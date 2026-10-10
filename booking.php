<?php
ob_start();
require_once 'db.php';

$message = "";

// 0. Fetch Global Costs (safe — table may not exist yet)
try {
    $settingsQuery = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings");
    $gs = [];
    while($row = $settingsQuery->fetch()) $gs[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {
    $gs = [];
}
$extra_person_cost = (float)($gs['extra_person_cost'] ?? 100.00);
$extension_hourly_cost = (float)($gs['extension_hourly_cost'] ?? 100.00);

// Handle Delete Booking
if(isset($_GET['delete'])) {
    if ($_SESSION['role'] === 'Admin') {
        $stmt = $pdo->prepare("DELETE FROM hotel_bookings WHERE id = ?");
        if($stmt->execute([$_GET['delete']])) {
            $message = "<div class='alert alert-success'>Booking deleted successfully.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can delete bookings.</div>";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['book_now']) || isset($_POST['book_and_checkin']))) {
    $is_instant_checkin = isset($_POST['book_and_checkin']);
    $name = trim($_POST['guest_name']);
    $contact = trim($_POST['contact']);
    $check_in_time = $_POST['check_in_time'];
    $duration_label = $_POST['duration_type'];
    $duration_type = match ($duration_label) {
        '22 Hours' => '3 Hours',
        '1 Day' => '6 Hours',
        '2 Days' => '12 Hours',
        default => $duration_label,
    };
    $room_id = (int)$_POST['room_id'];
    $num_guests = (int)$_POST['num_guests'];
    $is_paid = isset($_POST['is_paid']) ? 1 : 0;

    try {
        $pdo->beginTransaction();

        // Retrieve room type, capacity, and rates from hotel_rooms
        $roomStmt = $pdo->prepare("SELECT room_type, capacity, price_3h, price_6h, price_12h, price_24h FROM hotel_rooms WHERE id = ?");
        $roomStmt->execute([$room_id]);
        $room = $roomStmt->fetch();
        if (!$room) {
            throw new Exception("Selected room not found.");
        }
        $room_type = $room['room_type'];
        $capacity = (int)$room['capacity'];

        // Validate that the selected duration is supported by the room
        if ($duration_label == '22 Hours' && $room['price_3h'] === null) {
            throw new Exception("22 Hours rate is not configured for this room.");
        }
        if ($duration_label == '1 Day' && $room['price_6h'] === null) {
            throw new Exception("1 Day rate is not configured for this room.");
        }
        if ($duration_label == '2 Days' && $room['price_12h'] === null) {
            throw new Exception("2 Days rate is not configured for this room.");
        }

        // 1. Manage Guest
        $guest_id = null;
        if (!empty($contact)) {
            $stmt = $pdo->prepare("SELECT id FROM hotel_guests WHERE contact = ? LIMIT 1");
            $stmt->execute([$contact]);
            $guest = $stmt->fetch();

            if ($guest) {
                $guest_id = $guest['id'];
                $pdo->prepare("UPDATE hotel_guests SET name = ? WHERE id = ?")->execute([$name, $guest_id]);
            }
        }

        if (!$guest_id) {
            if (empty($name)) {
                $seq_stmt = $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'hotel_guests'");
                $seq = $seq_stmt ? (int)$seq_stmt->fetchColumn() : 0;
                $name = 'Guest #' . ($seq + 1);
            }
            $stmt = $pdo->prepare("INSERT INTO hotel_guests (name, contact) VALUES (?, ?)");
            $stmt->execute([$name, empty($contact) ? 'Not Provided' : $contact]);
            $guest_id = $pdo->lastInsertId();
        }

        // 2. Calculate Check Out Time based on Duration
        $inDate = new DateTime($check_in_time);
        $original_check_in_time = $inDate->format('Y-m-d H:i:s');
        
        if ($duration_label == '22 Hours') {
            $inDate->modify('+22 hours');
            $check_out_time = $inDate->format('Y-m-d H:i:s');
        } elseif ($duration_label == '1 Day') {
            $inDate->modify('+24 hours');
            $check_out_time = $inDate->format('Y-m-d H:i:s');
        } elseif ($duration_label == '2 Days') {
            $inDate->modify('+48 hours');
            $check_out_time = $inDate->format('Y-m-d H:i:s');
        } elseif ($duration_label == 'Custom') {
            if (empty($_POST['check_out_time'])) {
                throw new Exception("Custom check-out date & time is required.");
            }
            $outDate = new DateTime($_POST['check_out_time']);
            if ($outDate <= $inDate) {
                throw new Exception("Check-out time must be after check-in time.");
            }
            $check_out_time = $outDate->format('Y-m-d H:i:s');
        } else {
            throw new Exception("Invalid duration type.");
        }
        $check_in_time = $original_check_in_time;

        // Conflict check
        $conflictStmt = $pdo->prepare("
            SELECT COUNT(*) FROM hotel_bookings b
            WHERE b.room_id = ?
              AND b.status != 'Cancelled'
              AND (
                  b.status = 'Pending'
                  OR (b.status = 'Actioned' AND EXISTS (
                      SELECT 1 FROM hotel_checkins c
                      WHERE c.booking_id = b.id AND c.status = 'Checked-in'
                  ))
              )
              AND b.check_in_time < ?
              AND b.check_out_time > ?
        ");
        $conflictStmt->execute([$room_id, $check_out_time, $check_in_time]);
        $hasConflict = $conflictStmt->fetchColumn() > 0;
        
        if ($hasConflict) {
            throw new Exception("The selected room is already booked or occupied during this period.");
        }

        // 3. Calculate Extra Person Charges
        $extra_charges = 0;
        if ($num_guests > $capacity) {
            $extra_charges = ($num_guests - $capacity) * $extra_person_cost;
        }

        $room_charges = 0;
        if ($duration_label == '22 Hours') $room_charges = $room['price_3h'];
        elseif ($duration_label == '1 Day') $room_charges = $room['price_6h'];
        elseif ($duration_label == '2 Days') $room_charges = $room['price_12h'];
        elseif ($duration_label == 'Custom') {
            $diff = strtotime($check_out_time) - strtotime($check_in_time);
            $total_hours = ceil($diff / 3600);
            if ($total_hours <= 22 && $room['price_3h'] !== null) {
                $room_charges = $room['price_3h'];
            } elseif ($total_hours <= 24 && $room['price_6h'] !== null) {
                $room_charges = $room['price_6h'];
            } elseif ($total_hours <= 48 && $room['price_12h'] !== null) {
                $room_charges = $room['price_12h'];
            } else {
                $nights = floor($total_hours / 24);
                $extra_hours = $total_hours % 24;
                if ($nights == 0) {
                    $room_charges = $room['price_24h'];
                } else {
                    $room_charges = ($nights * $room['price_24h']) + ($extra_hours * $extension_hourly_cost);
                }
            }
        }

        // 4. Insert Booking
        $status = $is_instant_checkin ? 'Actioned' : 'Pending';
        $booking_type = $is_instant_checkin ? 'Instant' : 'Reservation';
        
        $stmt = $pdo->prepare("
            INSERT INTO hotel_bookings (guest_id, room_id, check_in_time, check_out_time, room_type, num_guests, duration_type, base_price, booking_type, status, is_paid, extra_charges) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$guest_id, $room_id, $check_in_time, $check_out_time, $room_type, $num_guests, $duration_type, $room_charges, $booking_type, $status, $is_paid, $extra_charges]);
        $booking_id = $pdo->lastInsertId();

        if ($is_instant_checkin) {
            // When instant check-in is used, we treat 'NOW()' as the absolute start for both records
            $pdo->prepare("INSERT INTO hotel_checkins (booking_id, room_id, check_in_time, status) VALUES (?, ?, ?, 'Checked-in')")->execute([$booking_id, $room_id, date('Y-m-d H:i:s')]);
            $checkin_id = $pdo->lastInsertId();
            
            // Re-calculate the check-out time based on actual server NOW() to be exactly 100% accurate
            $stmt = $pdo->prepare("
                UPDATE hotel_bookings 
                SET check_in_time = (SELECT check_in_time FROM hotel_checkins WHERE id = ?),
                    check_out_time = CASE 
                        WHEN duration_type = '3 Hours'  THEN datetime((SELECT check_in_time FROM hotel_checkins WHERE id = ?), '+22 hours')
                        WHEN duration_type = '6 Hours'  THEN datetime((SELECT check_in_time FROM hotel_checkins WHERE id = ?), '+24 hours')
                        WHEN duration_type = '12 Hours' THEN datetime((SELECT check_in_time FROM hotel_checkins WHERE id = ?), '+48 hours')
                        ELSE check_out_time 
                    END
                WHERE id = ?
            ");
            $stmt->execute([$checkin_id, $checkin_id, $checkin_id, $checkin_id, $booking_id]);

            $pdo->prepare("UPDATE hotel_rooms SET status = 'Occupied' WHERE id = ?")->execute([$room_id]);
            


            $total_amount = $room_charges + $extra_charges;
            $payment_status = $is_paid ? 'Paid' : 'Unpaid';
            $paid_timestamp = $is_paid ? date('Y-m-d H:i:s') : null;

            $pdo->prepare("INSERT INTO hotel_payments (checkin_id, room_charges, additional_services, total_amount, payment_status, paid_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$checkin_id, $room_charges, $extra_charges, $total_amount, $payment_status, $paid_timestamp]);
            
            $message = "<div class='alert alert-success'>Booking & <strong>Instant Check-In</strong> successful for <strong>$name</strong>!</div>";
        } else {
            $message = "<div class='alert alert-success'>Booking (Pending) successful for <strong>$name</strong>! Duration: $duration_type.</div>";
        }
        
        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Error Failed: " . $e->getMessage() . "</div>";
    }
}

// Get next guest number for Name preview
$seq_stmt = $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'hotel_guests'");
$seq = ($seq_stmt && ($s = $seq_stmt->fetchColumn()) !== false) ? (int)$s : 0;
$auto_name = 'Guest #' . ($seq + 1);

include 'header.php';
?>

<?= $message ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2 style="margin: 0; font-size: 1.8rem; font-weight: 700; color: var(--dark);">Bookings</h2>
    <button type="button" class="btn btn-primary" onclick="openCalendarModal()"><i class='bx bx-calendar'></i> Booking Calendar</button>
</div>

<div class="card-panel">
    <div class="card-panel-header">
        <h2>Make a Reservation</h2>
    </div>
    
    <form method="POST" action="booking.php" id="booking_form">
        <div class="form-row">
            <div class="form-group">
                <label>Guest Full Name</label>
                <input type="text" name="guest_name" class="form-control" value="<?= $auto_name ?>" placeholder="Optional (Auto-generated)">
            </div>
            <div class="form-group">
                <label>Contact Number (or Email)</label>
                <input type="text" name="contact" class="form-control" placeholder="Optional">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Check-in Date & Time</label>
                <input type="datetime-local" name="check_in_time" id="check_in_time" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Duration of Stay</label>
                <select name="duration_type" id="duration_type" class="form-select" required onchange="onDurationChange()">
                    <option value="" disabled selected>-- Select Duration --</option>
                    <option value="22 Hours">22 Hours</option>
                    <option value="1 Day">1 Day Rate</option>
                    <option value="2 Days">2 Days Rate</option>
                    <option value="Custom">Custom Booking Date</option>
                </select>
            </div>
            <div class="form-group" id="custom_checkout_group" style="display:none;">
                <label>Check-out Date & Time</label>
                <input type="datetime-local" name="check_out_time" id="check_out_time" class="form-control" onchange="updateAvailableRooms()">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Assign Available Room</label>
                <small id="room_hint" style="color: #e67e22; font-weight: 500; display: block; margin-bottom: 4px;"><i class='bx bx-info-circle'></i> Please select a Duration of Stay first.</small>
                <div id="room_picker_row" style="display:flex; gap:10px; align-items:stretch;">
                    <div id="selected_room_display" style="flex:1; padding:10px 14px; border:2px dashed #cbd5e1; border-radius:8px; background:#f8fafc; font-weight:600; color:#64748b; display:flex; align-items:center;">
                        No room selected
                    </div>
                    <button type="button" id="choose_room_btn" class="btn btn-secondary" onclick="openRoomPicker()" disabled style="opacity:0.5; cursor:not-allowed; white-space:nowrap;"><i class='bx bx-bed'></i> Choose Room</button>
                </div>
                <select name="room_id" id="room_id" style="display:none;" onchange="calculateCheckout(true)" disabled>
                    <option value="" disabled selected>-- Select an Available Room --</option>
                    <?php
                    $rooms = $pdo->query("SELECT * FROM hotel_rooms WHERE status = 'Available' ORDER BY room_number ASC")->fetchAll();
                    foreach($rooms as $r):
                    ?>
                        <option value="<?= $r['id'] ?>"
                                data-number="<?= htmlspecialchars($r['room_number']) ?>"
                                data-type="<?= $r['room_type'] ?>"
                                data-capacity="<?= $r['capacity'] ?>"
                                data-p3="<?= $r['price_3h'] ?>"
                                data-p6="<?= $r['price_6h'] ?>"
                                data-p12="<?= $r['price_12h'] ?>"
                                data-p24="<?= $r['price_24h'] ?>">
                            Room <?= htmlspecialchars($r['room_number']) ?> (<?= $r['room_type'] ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php if(count($rooms) == 0): ?>
                        <option value="" disabled>No rooms currently available</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Number of Guests</label>
                <input type="number" id="num_guests" name="num_guests" min="1" max="10" class="form-control" value="1" required onchange="calculateCheckout()" disabled style="opacity:0.5;">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Payment Status</label>
                <div>
                    <input type="checkbox" name="is_paid" id="is_paid" value="1" style="transform: scale(1.5); margin: 10px;" checked>
                    <label for="is_paid" style="display:inline-block; vertical-align:middle; cursor:pointer;">Mark as Paid on Booking</label>
                </div>
            </div>
        </div>
        
        <div id="stay_preview" style="display:none; margin: 15px 0; padding: 15px; background: rgba(var(--primary-rgb), 0.05); border-radius: 8px; border-left: 5px solid var(--primary);">
            <div style="margin-bottom: 5px;"><strong>Check-Out:</strong> <span id="checkout_out"></span></div>
            <div style="font-size: 1.2rem; color: var(--primary);"><strong>Total Price:</strong> ₱<span id="total_price_display">0.00</span></div>
            <div id="extra_charge_note" style="font-size: 0.85rem; color: #666; display:none;"></div>
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" name="book_now" class="btn btn-primary"><i class='bx bx-calendar-check'></i> Book Only (Pending)</button>
            <button type="submit" name="book_and_checkin" class="btn btn-success"><i class='bx bx-check-double'></i> Instant Check-In</button>
        </div>
    </form>
</div>

<div class="card-panel">
    <div class="card-panel-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">All Active Bookings</h2>
        <?php $filter_status = $_GET['filter_status'] ?? 'All'; ?>
        <form method="GET" action="booking.php" style="margin: 0;">
            <select name="filter_status" class="form-select" onchange="this.form.submit()" style="width: auto; display: inline-block; padding: 5px 10px;">
                <option value="All" <?= $filter_status === 'All' ? 'selected' : '' ?>>All Statuses</option>
                <option value="Pending" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Actioned" <?= $filter_status === 'Actioned' ? 'selected' : '' ?>>Actioned</option>
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Guest</th>
                    <th>Room #</th>
                    <th>Room Type</th>
                    <th>Check In Time</th>
                    <th>Duration / Type</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $status_sql = "";
                if ($filter_status === 'Pending') {
                    $status_sql = " AND b.status = 'Pending'";
                } elseif ($filter_status === 'Actioned') {
                    $status_sql = " AND b.status = 'Actioned'";
                }
                
                $stmt = $pdo->query("
                    SELECT b.*, g.name, r.room_number 
                    FROM hotel_bookings b 
                    JOIN hotel_guests g ON b.guest_id = g.id 
                    LEFT JOIN hotel_rooms r ON b.room_id = r.id 
                    WHERE b.status != 'Cancelled'
                      $status_sql
                      AND NOT EXISTS (
                          SELECT 1 FROM hotel_checkins c 
                          WHERE c.booking_id = b.id 
                            AND c.status = 'Checked-out'
                      )
                    ORDER BY b.check_in_time DESC
                ");
                $all_bookings = $stmt->fetchAll();
                foreach($all_bookings as $row):
                    $sc = 'badge-warning';
                    if($row['status'] == 'Actioned') $sc = 'badge-success';
                    if($row['status'] == 'Cancelled') $sc = 'badge-danger';
                ?>
                <tr>
                    <td>#<?= $row['id'] ?></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= $row['room_number'] ? htmlspecialchars($row['room_number']) : '<span style="color:#999;">Pending</span>' ?></td>
                    <td><span class="badge badge-info" style="background-color: #0dcaf0; color: #fff;"><?= htmlspecialchars($row['room_type']) ?></span></td>
                    <td><small><?= date('M d, Y h:i A', strtotime($row['check_in_time'])) ?></small></td>
                    <td>
                        <strong><?= $row['duration_type'] ?></strong><br>
                        <span class="badge <?= $row['booking_type'] == 'Instant' ? 'badge-primary' : 'badge-warning' ?>" style="font-size: 10px;"><?= $row['booking_type'] ?></span>
                        <br><small><?= date('M d h:i A', strtotime($row['check_out_time'])) ?> (Out)</small>
                    </td>
                    <td>
                        <span class="badge <?= $row['is_paid'] ? 'badge-success' : 'badge-danger' ?>"><?= $row['is_paid'] ? 'Paid' : 'Unpaid' ?></span>
                        <?php if($row['extra_charges'] > 0): ?>
                            <br><small style="color:red;">+₱<?= $row['extra_charges'] ?> (Extra)</small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $sc ?>"><?= $row['status'] ?></span></td>
                    <td class="action-links">
                        <?php if($row['status'] == 'Pending'): ?>
                            <a href="checkin.php?booking_id=<?= $row['id'] ?>" class="btn btn-sm btn-primary" style="color:#fff;text-decoration:none;"><i class='bx bx-log-in'></i> Check In</a>
                        <?php endif; ?>
                        <?php if($_SESSION['role'] === 'Admin'): ?>
                            <a href="booking.php?delete=<?= $row['id'] ?>" class="delete-link" onclick="return confirm('Delete this booking?');"><i class='bx bx-trash'></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($all_bookings) == 0): ?>
                    <tr><td colspan="9" style="text-align:center; padding: 25px;">No bookings found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // 0. Auto-set current time on page load
    window.addEventListener('load', () => {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        document.getElementById('check_in_time').value = now.toISOString().slice(0, 16);
        onDurationChange();
        syncRoomPicker();
        updateInstantCheckinButton();
    });

    function updateInstantCheckinButton() {
        const checkInInput = document.getElementById('check_in_time');
        const instantBtn = document.querySelector('button[name="book_and_checkin"]');
        if (!checkInInput || !instantBtn || !checkInInput.value) return;
        
        const checkInDate = new Date(checkInInput.value);
        const today = new Date();
        
        if (checkInDate.getFullYear() === today.getFullYear() &&
            checkInDate.getMonth() === today.getMonth() &&
            checkInDate.getDate() === today.getDate()) {
            instantBtn.style.display = 'inline-block';
        } else {
            instantBtn.style.display = 'none';
        }
    }

    // ── Visual Room Picker Modal ──────────────────────────────────────
    // The hidden #room_id select remains the single source of truth so all
    // existing pricing/validation logic keeps working unchanged.
    function readRoomsFromSelect() {
        const roomSelect = document.getElementById('room_id');
        const rooms = [];
        Array.from(roomSelect.options).forEach(opt => {
            if (!opt.value) return;
            rooms.push({
                id: opt.value,
                room_number: opt.getAttribute('data-number') || '',
                room_type: opt.getAttribute('data-type') || '',
                capacity: opt.getAttribute('data-capacity') || '',
                price_3h: opt.getAttribute('data-p3'),
                price_6h: opt.getAttribute('data-p6'),
                price_12h: opt.getAttribute('data-p12'),
                price_24h: opt.getAttribute('data-p24')
            });
        });
        return rooms;
    }

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function roomPriceForDuration(r, duration) {
        if (duration === '22 Hours') return { value: r.price_3h, label: '22 hrs' };
        if (duration === '1 Day') return { value: r.price_6h, label: '24 hrs' };
        if (duration === '2 Days') return { value: r.price_12h, label: '48 hrs' };
        return { value: r.price_24h, label: '/ night' };
    }

    function updateSelectedRoomDisplay() {
        const roomSelect = document.getElementById('room_id');
        const display = document.getElementById('selected_room_display');
        if (!display) return;
        if (roomSelect.value) {
            const opt = roomSelect.options[roomSelect.selectedIndex];
            const num = opt ? (opt.getAttribute('data-number') || '') : '';
            const type = opt ? (opt.getAttribute('data-type') || '') : '';
            display.innerHTML = '<i class=\'bx bx-bed\' style="color:var(--primary); margin-right:6px;"></i> Room ' + escapeHtml(num) + ' (' + escapeHtml(type) + ')';
            display.style.borderStyle = 'solid';
            display.style.borderColor = 'var(--primary)';
            display.style.color = 'var(--dark)';
            display.style.background = '#fff';
        } else {
            display.textContent = 'No room selected';
            display.style.borderStyle = 'dashed';
            display.style.borderColor = '#cbd5e1';
            display.style.color = '#64748b';
            display.style.background = '#f8fafc';
        }
    }

    // Called whenever the available-room list changes: drops stale
    // selections, refreshes the display, and re-renders the open modal.
    function syncRoomPicker() {
        const roomSelect = document.getElementById('room_id');
        const availableIds = readRoomsFromSelect().map(r => String(r.id));
        if (roomSelect.value && !availableIds.includes(String(roomSelect.value))) {
            roomSelect.value = '';
        }
        if (!roomSelect.value && roomSelect.options.length) {
            roomSelect.selectedIndex = 0;
        }
        updateSelectedRoomDisplay();
        const modal = document.getElementById('roomPickerModal');
        if (modal && modal.style.display === 'block') {
            renderRoomGrid();
        }
    }

    function openRoomPicker() {
        if (!document.getElementById('duration_type').value) {
            alert('Please select a Duration of Stay first.');
            return;
        }
        renderRoomGrid();
        document.getElementById('roomPickerModal').style.display = 'block';
    }

    function closeRoomPicker() {
        document.getElementById('roomPickerModal').style.display = 'none';
    }

    function renderRoomGrid() {
        const grid = document.getElementById('room_grid');
        const summary = document.getElementById('room_picker_summary');
        const roomSelect = document.getElementById('room_id');
        const duration = document.getElementById('duration_type').value;
        const rooms = readRoomsFromSelect();

        if (summary) {
            summary.textContent = rooms.length
                ? rooms.length + ' room' + (rooms.length > 1 ? 's' : '') + ' available · ' + duration
                : 'No rooms available for the selected dates.';
        }
        grid.innerHTML = '';
        rooms.forEach(r => {
            const price = roomPriceForDuration(r, duration);
            const priceText = (price.value === null || price.value === '' || price.value === undefined)
                ? 'Price on request'
                : '₱' + Number(price.value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + price.label;
            const box = document.createElement('div');
            box.className = 'room-box' + (String(r.id) === String(roomSelect.value) ? ' selected' : '');
            box.innerHTML =
                '<div class="room-box-number">Room ' + escapeHtml(r.room_number) + '</div>' +
                '<div class="room-box-type">' + escapeHtml(r.room_type) + '</div>' +
                '<div class="room-box-meta">' + escapeHtml(priceText) + '<br>' + escapeHtml(r.capacity) + ' guest' + (String(r.capacity) === '1' ? '' : 's') + '</div>';
            box.onclick = (function(id) { return function() { selectRoom(id); }; })(r.id);
            grid.appendChild(box);
        });
    }

    function selectRoom(id) {
        const roomSelect = document.getElementById('room_id');
        roomSelect.value = id;
        updateSelectedRoomDisplay();
        closeRoomPicker();
        calculateCheckout(true);
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeRoomPicker();
    });

    let isFetchingRooms = false;
    function updateAvailableRooms() {
        const check_in = document.getElementById('check_in_time').value;
        const duration = document.getElementById('duration_type').value;
        const customCheckoutInput = document.getElementById('check_out_time');
        
        let check_out = '';
        if (duration === 'Custom') {
            check_out = customCheckoutInput.value;
        } else if (check_in && duration) {
            let date = new Date(check_in);
            if (duration === '22 Hours') date.setHours(date.getHours() + 22);
            else if (duration === '1 Day') date.setHours(date.getHours() + 24);
            else if (duration === '2 Days') date.setHours(date.getHours() + 48);
            
            const offset = date.getTimezoneOffset();
            const localDate = new Date(date.getTime() - (offset*60*1000));
            check_out = localDate.toISOString().slice(0, 16);
        }
        
        if (!check_in || !check_out) {
            return;
        }
        
        isFetchingRooms = true;
        const roomSelect = document.getElementById('room_id');
        const currentSelectedRoom = roomSelect.value;
        
        fetch(`check_rooms_ajax.php?check_in_time=${encodeURIComponent(check_in)}&check_out_time=${encodeURIComponent(check_out)}&duration_type=${encodeURIComponent(duration)}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    console.error(data.error);
                    return;
                }
                
                // Clear and rebuild options
                roomSelect.innerHTML = '<option value="" disabled selected>-- Select an Available Room --</option>';
                if (data.rooms && data.rooms.length > 0) {
                    data.rooms.forEach(r => {
                        const opt = document.createElement('option');
                        opt.value = r.id;
                        opt.setAttribute('data-number', r.room_number);
                        opt.setAttribute('data-type', r.room_type);
                        opt.setAttribute('data-capacity', r.capacity);
                        opt.setAttribute('data-p3', r.price_3h !== null ? r.price_3h : '');
                        opt.setAttribute('data-p6', r.price_6h !== null ? r.price_6h : '');
                        opt.setAttribute('data-p12', r.price_12h !== null ? r.price_12h : '');
                        opt.setAttribute('data-p24', r.price_24h);
                        opt.innerText = `Room ${r.room_number} (${r.room_type})`;
                        
                        if (r.id == currentSelectedRoom) {
                            opt.selected = true;
                        }
                        roomSelect.appendChild(opt);
                    });
                } else {
                    const opt = document.createElement('option');
                    opt.value = "";
                    opt.disabled = true;
                    opt.innerText = "No rooms available for selected dates";
                    roomSelect.appendChild(opt);
                }
                
                isFetchingRooms = false;
                syncRoomPicker();
                calculateCheckout(false);
            })
            .catch(err => {
                console.error(err);
                isFetchingRooms = false;
            });
    }

    function onDurationChange() {
        const duration = document.getElementById('duration_type').value;
        const customGroup = document.getElementById('custom_checkout_group');
        const customCheckoutInput = document.getElementById('check_out_time');
        const roomSelect = document.getElementById('room_id');
        const roomHint = document.getElementById('room_hint');
        const numGuestsInput = document.getElementById('num_guests');
        
        if (duration === 'Custom') {
            customGroup.style.display = 'block';
            customCheckoutInput.required = true;
            if (!customCheckoutInput.value) {
                const check_in = document.getElementById('check_in_time').value;
                if (check_in) {
                    let date = new Date(check_in);
                    date.setHours(date.getHours() + 24);
                    const offset = date.getTimezoneOffset();
                    const localDate = new Date(date.getTime() - (offset*60*1000));
                    customCheckoutInput.value = localDate.toISOString().slice(0, 16);
                }
            }
        } else {
            customGroup.style.display = 'none';
            customCheckoutInput.required = false;
        }

        if (duration) {
            // Enable room picker now that a duration is chosen
            roomSelect.disabled = false;
            const chooseBtn = document.getElementById('choose_room_btn');
            if (chooseBtn) {
                chooseBtn.disabled = false;
                chooseBtn.style.opacity = '1';
                chooseBtn.style.cursor = 'pointer';
            }
            numGuestsInput.disabled = false;
            numGuestsInput.style.opacity = '1';
            if (roomHint) roomHint.style.display = 'none';
        } else {
            roomSelect.disabled = true;
            const chooseBtn = document.getElementById('choose_room_btn');
            if (chooseBtn) {
                chooseBtn.disabled = true;
                chooseBtn.style.opacity = '0.5';
                chooseBtn.style.cursor = 'not-allowed';
            }
            numGuestsInput.disabled = true;
            numGuestsInput.style.opacity = '0.5';
            if (roomHint) roomHint.style.display = 'block';
        }
        
        updateAvailableRooms();
    }

    function updateDurationOptions() {
        const roomSelect = document.getElementById('room_id');
        const durationSelect = document.getElementById('duration_type');
        if (!roomSelect.value) return;
        
        const selectedOption = roomSelect.options[roomSelect.selectedIndex];
        const p3 = selectedOption.getAttribute('data-p3');
        const p6 = selectedOption.getAttribute('data-p6');
        const p12 = selectedOption.getAttribute('data-p12');
        
        const currentDuration = durationSelect.value;
        let selectedDurationValid = true;

        Array.from(durationSelect.options).forEach(opt => {
            if (opt.value === '22 Hours') {
                const disabled = (!p3 || p3 === '' || parseFloat(p3) <= 0);
                opt.disabled = disabled;
                if (disabled && currentDuration === '22 Hours') selectedDurationValid = false;
            } else if (opt.value === '1 Day') {
                const disabled = (!p6 || p6 === '' || parseFloat(p6) <= 0);
                opt.disabled = disabled;
                if (disabled && currentDuration === '1 Day') selectedDurationValid = false;
            } else if (opt.value === '2 Days') {
                const disabled = (!p12 || p12 === '' || parseFloat(p12) <= 0);
                opt.disabled = disabled;
                if (disabled && currentDuration === '2 Days') selectedDurationValid = false;
            }
        });

        if (!selectedDurationValid) {
            durationSelect.value = '2 Days';
            onDurationChange();
        }
    }

    function calculateCheckout(isRoomChange = false) {
        const check_in = document.getElementById('check_in_time').value;
        const duration = document.getElementById('duration_type').value;
        const roomSelect = document.getElementById('room_id');
        const numGuestsInput = document.getElementById('num_guests');
        const extraPersonCost = <?= $extra_person_cost ?>;
        
        let numGuests = parseInt(numGuestsInput.value) || 1;
        
        const display = document.getElementById('stay_preview');
        const checkoutOut = document.getElementById('checkout_out');
        const priceDisplay = document.getElementById('total_price_display');
        const extraNote = document.getElementById('extra_charge_note');

        if(roomSelect.value && isRoomChange) {
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            const capacity = parseInt(selectedOption.getAttribute('data-capacity')) || 2;
            numGuestsInput.value = capacity;
            numGuests = capacity;
            updateDurationOptions();
        }

        if(check_in && duration && roomSelect.value) {
            // 1. Calculate Checkout Time
            let date;
            if (duration === 'Custom') {
                const customOut = document.getElementById('check_out_time').value;
                if (!customOut) return;
                date = new Date(customOut);
            } else {
                date = new Date(check_in);
                if (duration === '22 Hours') date.setHours(date.getHours() + 22);
                else if (duration === '1 Day') date.setHours(date.getHours() + 24);
                else if (duration === '2 Days') date.setHours(date.getHours() + 48);
            }

            // 2. Calculate Pricing
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            const capacity = parseInt(selectedOption.getAttribute('data-capacity')) || 2;
            let basePrice = 0;
            
            if (duration === '22 Hours') basePrice = parseFloat(selectedOption.getAttribute('data-p3')) || 0;
            else if (duration === '1 Day') basePrice = parseFloat(selectedOption.getAttribute('data-p6')) || 0;
            else if (duration === '2 Days') basePrice = parseFloat(selectedOption.getAttribute('data-p12')) || 0;
            else if (duration === 'Custom') {
                const checkInDate = new Date(check_in);
                const diffMs = date - checkInDate;
                if (diffMs > 0) {
                    const totalHours = Math.ceil(diffMs / (1000 * 60 * 60));
                    const p3 = parseFloat(selectedOption.getAttribute('data-p3'));
                    const p6 = parseFloat(selectedOption.getAttribute('data-p6'));
                    const p12 = parseFloat(selectedOption.getAttribute('data-p12'));
                    const p24 = parseFloat(selectedOption.getAttribute('data-p24'));
                    const extCost = <?= $extension_hourly_cost ?>;

                    if (totalHours <= 22 && !isNaN(p3) && p3 > 0) basePrice = p3;
                    else if (totalHours <= 24 && !isNaN(p6) && p6 > 0) basePrice = p6;
                    else if (totalHours <= 48 && !isNaN(p12) && p12 > 0) basePrice = p12;
                    else {
                        const nights = Math.floor(totalHours / 24);
                        const extraHours = totalHours % 24;
                        if (nights === 0) {
                            basePrice = p24;
                        } else {
                            basePrice = (nights * p24) + (extraHours * extCost);
                        }
                    }
                }
            }

            // 3. Calculate Extra Guest Charges
            let extraCharges = 0;

            if (numGuests > capacity) {
                extraCharges = (numGuests - capacity) * extraPersonCost;
                extraNote.innerText = `Includes ₱${extraCharges.toFixed(2)} extra person charge (${numGuests - capacity} extra guest/s)`;
                extraNote.style.display = 'block';
            } else {
                extraNote.style.display = 'none';
            }

            const total = basePrice + extraCharges;

            // Updates
            display.style.display = 'block';
            checkoutOut.innerText = date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
            priceDisplay.innerText = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            display.style.display = 'none';
        }
    }
    document.getElementById('check_in_time').addEventListener('change', () => {
        if (document.getElementById('duration_type').value === 'Custom') {
            // Adjust custom checkout time relative to new check-in time
            const check_in = document.getElementById('check_in_time').value;
            const customCheckoutInput = document.getElementById('check_out_time');
            if (check_in && customCheckoutInput.value) {
                let date = new Date(check_in);
                date.setHours(date.getHours() + 24);
                const offset = date.getTimezoneOffset();
                const localDate = new Date(date.getTime() - (offset*60*1000));
                customCheckoutInput.value = localDate.toISOString().slice(0, 16);
            }
        }
        updateAvailableRooms();
        updateInstantCheckinButton();
    });
    document.getElementById('duration_type').addEventListener('change', () => onDurationChange());
    document.getElementById('room_id').addEventListener('change', () => calculateCheckout(true));
    document.getElementById('roomPickerModal').addEventListener('click', function(e) {
        if (e.target === this) closeRoomPicker();
    });
    document.getElementById('booking_form').addEventListener('submit', function(e) {
        if (!document.getElementById('room_id').value) {
            e.preventDefault();
            openRoomPicker();
        }
    });
    document.getElementById('num_guests').addEventListener('change', () => calculateCheckout(false));
</script>

<!-- Room Picker Modal -->
<style>
    .room-box {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 10px;
        text-align: center;
        cursor: pointer;
        background: #f8fafc;
        transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease;
    }
    .room-box:hover {
        border-color: var(--primary);
        transform: translateY(-2px);
        box-shadow: var(--shadow-sm);
    }
    .room-box.selected {
        border-color: var(--primary);
        background: rgba(var(--primary-rgb), 0.07);
        box-shadow: var(--shadow-sm);
    }
    .room-box-number { font-size: 1.15rem; font-weight: 700; color: var(--dark); }
    .room-box.selected .room-box-number { color: var(--primary); }
    .room-box-type {
        display: inline-block;
        font-size: 0.72rem;
        font-weight: 600;
        color: #fff;
        background: #0dcaf0;
        border-radius: 20px;
        padding: 2px 10px;
        margin: 6px 0;
    }
    .room-box-meta { font-size: 0.78rem; color: #64748b; line-height: 1.5; }
</style>
<div id="roomPickerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
    <div style="background:#fff; width:620px; max-width:92%; margin:6% auto; border-radius:10px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
            <h3 style="margin:0; font-size:1.2rem;"><i class='bx bx-bed' style="color:var(--primary);"></i> Choose a Room</h3>
            <button type="button" onclick="closeRoomPicker()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:20px;">
            <p id="room_picker_summary" style="margin:0 0 12px 0; font-size:0.85rem; color:#64748b;"></p>
            <div id="room_grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:12px;"></div>
        </div>
        <div style="padding:12px 20px; border-top:1px solid #e2e8f0; text-align:right; background:#f8fafc;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeRoomPicker()">Cancel</button>
        </div>
    </div>
</div>

<?php include 'calendar_modal.php'; ?>

<script>
    // Auto-populate check-in date from calendar or URL ?date= param
    (function() {
        const params = new URLSearchParams(window.location.search);
        const dateParam = params.get('date');
        if (dateParam) {
            const checkInInput = document.getElementById('check_in_time');
            if (checkInInput) {
                // Fetch smart check-in time from server
                fetch('next_checkin_ajax.php?date=' + encodeURIComponent(dateParam))
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            checkInInput.value = dateParam + 'T10:00';
                        } else {
                            checkInInput.value = data.suggested_checkin;
                            if (data.has_prior_booking && typeof showCalendarNotice === 'function') {
                                const msg = `⏰ Previous booking checks out at ${data.latest_checkout.replace('T',' ')}. Check-in suggested at ${data.suggested_checkin.replace('T',' ')} (${data.buffer_min}-min buffer).`;
                                showCalendarNotice(msg, 'info');
                            }
                        }
                        checkInInput.dispatchEvent(new Event('change'));
                        document.getElementById('duration_type').focus();
                    })
                    .catch(() => {
                        checkInInput.value = dateParam + 'T10:00';
                        checkInInput.dispatchEvent(new Event('change'));
                    });
            }
            // Clean up URL without reloading
            const url = new URL(window.location);
            url.searchParams.delete('date');
            window.history.replaceState({}, '', url);
        }
    })();
</script>

<?php include 'footer.php'; ?>
