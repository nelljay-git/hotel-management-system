<?php
ob_start();
require_once 'db.php';
$message = "";

// 0. Fetch Global Costs
try {
    $settingsQuery = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings");
    $gs = [];
    while($row = $settingsQuery->fetch()) $gs[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {
    $gs = [];
}
$extension_hourly_cost = (float)($gs['extension_hourly_cost'] ?? 100.00);

function display_duration_label($duration) {
    return match ($duration) {
        '3 Hours' => '22 Hours',
        '6 Hours' => '1 Day',
        '12 Hours' => '2 Days',
        default => $duration,
    };
}

// Handle Extend Stay
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['extend_stay'])) {
    $checkin_id = (int)$_POST['checkin_id'];
    $ext_hours = (int)$_POST['extension_hours'];

    if ($ext_hours <= 0) {
        $message = "<div class='alert alert-danger'>Please enter a valid number of hours to extend.</div>";
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE hotel_bookings SET check_out_time = datetime(check_out_time, '+' || ? || ' hours'), extension_hours = extension_hours + ? WHERE id = (SELECT booking_id FROM hotel_checkins WHERE id = ?)")
                ->execute([$ext_hours, $ext_hours, $checkin_id]);
            
            $fee = $ext_hours * $extension_hourly_cost;
            $stmt = $pdo->prepare("UPDATE hotel_payments SET additional_services = additional_services + ?, total_amount = total_amount + ? WHERE checkin_id = ?");
            $stmt->execute([$fee, $fee, $checkin_id]);
            $pdo->commit();
            $message = "<div class='alert alert-success'>Stay extended by <strong>$ext_hours hour(s)</strong>. &#8369;" . number_format($fee, 2) . " added to bill.</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Extension Failed: " . $e->getMessage() . "</div>";
        }
    }
}

// Handle Check-In
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['do_checkin'])) {
    $booking_id = (int)$_POST['booking_id'];
    $room_id = (int)$_POST['room_id'];

    if (empty($room_id)) {
        $message = "<div class='alert alert-danger'>Please select a room.</div>";
    } else {
        try {
            $pdo->beginTransaction();

            // Retrieve booking duration
            $bStmt = $pdo->prepare("SELECT duration_type, check_in_time, check_out_time FROM hotel_bookings WHERE id = ?");
            $bStmt->execute([$booking_id]);
            $bRow = $bStmt->fetch();
            if (!$bRow) {
                throw new Exception("Booking not found.");
            }

            // Calculate checkout time from NOW
            $nowStr = date('Y-m-d H:i:s');
            $inDate = new DateTime($nowStr);
            if ($bRow['duration_type'] == '3 Hours') {
                $inDate->modify('+22 hours');
                $target_out = $inDate->format('Y-m-d H:i:s');
            } elseif ($bRow['duration_type'] == '6 Hours') {
                $inDate->modify('+24 hours');
                $target_out = $inDate->format('Y-m-d H:i:s');
            } elseif ($bRow['duration_type'] == '12 Hours') {
                $inDate->modify('+48 hours');
                $target_out = $inDate->format('Y-m-d H:i:s');
            } elseif ($bRow['duration_type'] == 'Custom') {
                $target_out = $bRow['check_out_time'];
            }

            // Check conflict
            $conflictStmt = $pdo->prepare("
                SELECT COUNT(*) FROM hotel_bookings b
                WHERE b.room_id = ?
                  AND b.id != ?
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
            $conflictStmt->execute([$room_id, $booking_id, $target_out, $nowStr]);
            $hasConflict = $conflictStmt->fetchColumn() > 0;
            if ($hasConflict) {
                throw new Exception("The selected room is already booked or occupied during this period.");
            }

            $pdo->prepare("INSERT INTO hotel_checkins (booking_id, room_id, check_in_time, status) VALUES (?, ?, ?, 'Checked-in')")->execute([$booking_id, $room_id, $nowStr]);
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

            $pdo->prepare("UPDATE hotel_bookings SET status = 'Actioned' WHERE id = ?")->execute([$booking_id]);
            $pdo->prepare("UPDATE hotel_rooms SET status = 'Occupied' WHERE id = ?")->execute([$room_id]);

            $stmt = $pdo->prepare("SELECT duration_type, is_paid, extra_charges FROM hotel_bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch();
            $duration = $booking['duration_type'];

            $stmt = $pdo->prepare("SELECT price_3h, price_6h, price_12h, price_24h FROM hotel_rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            $prices = $stmt->fetch();

            $room_charges = 0;
            if ($duration == '3 Hours') $room_charges = $prices['price_3h'];
            elseif ($duration == '6 Hours') $room_charges = $prices['price_6h'];
            elseif ($duration == '12 Hours') $room_charges = $prices['price_12h'];

            $extra_charges = $booking['extra_charges'];
            $total_amount = $room_charges + $extra_charges;
            $payment_status = $booking['is_paid'] ? 'Paid' : 'Unpaid';
            $paid_timestamp = $booking['is_paid'] ? date('Y-m-d H:i:s') : null;

            $pdo->prepare("INSERT INTO hotel_payments (checkin_id, room_charges, additional_services, total_amount, payment_status, paid_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$checkin_id, $room_charges, $extra_charges, $total_amount, $payment_status, $paid_timestamp]);

            $pdo->commit();
            $message = "<div class='alert alert-success'>Guest successfully checked in based on <strong>$duration</strong> rate!</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    }
}

// Handle Check-Out via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout_checkin_id'])) {
    $checkin_id = (int)$_POST['checkout_checkin_id'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT room_id FROM hotel_checkins WHERE id = ? AND status = 'Checked-in'");
        $stmt->execute([$checkin_id]);
        $checkin = $stmt->fetch();

        if ($checkin) {
            $pdo->prepare("UPDATE hotel_checkins SET status = 'Checked-out' WHERE id = ?")->execute([$checkin_id]);
            $pdo->prepare("UPDATE hotel_rooms SET status = 'Cleaning' WHERE id = ?")->execute([$checkin['room_id']]);
            $pdo->commit();
            // Clear all output buffers to ensure a clean header redirect
            while (ob_get_level()) ob_end_clean();
            header("Location: billing.php?checkin_id=" . $checkin_id);
            exit;
        } else {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Check-Out Error: Record not found or already checked out.</div>";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Checkout Error: " . $e->getMessage() . "</div>";
    }
}

// Handle Direct Booking param
$filter_booking = $_GET['booking_id'] ?? null;

include 'header.php';
?>

<?= $message ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2 style="margin: 0; font-size: 1.8rem; font-weight: 700; color: var(--dark);">Check-In / Out</h2>
    <button type="button" class="btn btn-primary" onclick="openCalendarModal()"><i class='bx bx-calendar'></i> View Booking Calendar</button>
</div>

<div class="card-panel">
    <div class="card-panel-header">
        <h2>Ready for Check-In (Pending Bookings)</h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Booking Time</th>
                    <th>Requested Type</th>
                    <th>Assign Room</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if($filter_booking) {
                    $stmt = $pdo->prepare("SELECT b.*, g.name as guest_name FROM hotel_bookings b JOIN hotel_guests g ON b.guest_id = g.id WHERE b.status = 'Pending' AND b.id = ? ORDER BY b.check_in_time ASC");
                    $stmt->execute([$filter_booking]);
                } else {
                    $stmt = $pdo->query("SELECT b.*, g.name as guest_name FROM hotel_bookings b JOIN hotel_guests g ON b.guest_id = g.id WHERE b.status = 'Pending' ORDER BY b.check_in_time ASC");
                }
                
                $pendingRows = $stmt->fetchAll();
                if (count($pendingRows) === 0):
                ?>
                    <tr><td colspan="5" style="text-align:center;">No pending bookings found.</td></tr>
                <?php else: foreach($pendingRows as $row):
                    $roomsQuery = $pdo->prepare("
                        SELECT r.* FROM hotel_rooms r
                        WHERE r.status = 'Available'
                          AND r.room_type = ?
                          AND NOT EXISTS (
                              SELECT 1 FROM hotel_bookings b
                              WHERE b.room_id = r.id
                                AND b.id != ?
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
                          )
                    ");
                    $roomsQuery->execute([$row['room_type'], $row['id'], $row['check_out_time'], $row['check_in_time']]);
                    $rooms = $roomsQuery->fetchAll();
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['guest_name']) ?></strong></td>
                    <td>
                        <small>
                            <?= date('M d, h:i A', strtotime($row['check_in_time'])) ?> <br>
                            to <?= date('M d, h:i A', strtotime($row['check_out_time'])) ?>
                        </small>
                    </td>
                    <td>
                        <span class="badge badge-primary"><?= $row['room_type'] ?></span><br>
                        <small>(<?= htmlspecialchars(display_duration_label($row['duration_type'])) ?>)</small><br>
                        <span class="badge <?= $row['booking_type'] == 'Instant' ? 'badge-primary' : 'badge-warning' ?>" style="font-size: 9px; opacity: 0.8;"><?= $row['booking_type'] ?></span>
                    </td>
                    <td>
                        <?php if (!empty($row['room_id']) || count($rooms) > 0): ?>
                            <form method="POST" action="checkin.php" id="checkin-form-<?= $row['id'] ?>">
                                <input type="hidden" name="booking_id" value="<?= $row['id'] ?>">
                                <?php if (!empty($row['room_id'])): ?>
                                    <input type="hidden" name="room_id" value="<?= $row['room_id'] ?>">
                                    <?php
                                        $assigned_room_no = 'Unknown';
                                        foreach($rooms as $r) {
                                            if ($r['id'] == $row['room_id']) {
                                                $assigned_room_no = $r['room_number'];
                                                break;
                                            }
                                        }
                                        if ($assigned_room_no === 'Unknown') {
                                            $rmStmt = $pdo->prepare("SELECT room_number FROM hotel_rooms WHERE id = ?");
                                            $rmStmt->execute([$row['room_id']]);
                                            $rmData = $rmStmt->fetch();
                                            if ($rmData) {
                                                $assigned_room_no = $rmData['room_number'];
                                            }
                                        }
                                    ?>
                                    <span class="badge badge-success" style="font-size: 14px; padding: 8px;">Room <?= htmlspecialchars($assigned_room_no) ?></span>
                                <?php else: ?>
                                    <select name="room_id" class="form-select" style="min-width:150px;" required>
                                        <option value="">-- Select Room --</option>
                                        <?php foreach($rooms as $r):
                                            $display_price = 0;
                                            if($row['duration_type'] == '3 Hours') $display_price = $r['price_3h'];
                                            if($row['duration_type'] == '6 Hours') $display_price = $r['price_6h'];
                                            if($row['duration_type'] == '12 Hours') $display_price = $r['price_12h'];
                                        ?>
                                            <option value="<?= $r['id'] ?>">Room <?= $r['room_number'] ?> (&#8369;<?= number_format($display_price, 2) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-danger">No Available Rooms</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['room_id']) || count($rooms) > 0): ?>
                                <button type="submit" name="do_checkin" class="btn btn-success btn-sm"><i class='bx bx-check'></i> Check-In</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-panel">
    <div class="card-panel-header">
        <h2>Currently Occupied (Active Check-Ins)</h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Room #</th>
                    <th>Room Type</th>
                    <th>Guest</th>
                    <th>Check-in Time</th>
                    <th>Check-out Time</th>
                    <th>Duration Type</th>
                    <th>Status</th>
                    <th>Extend Stay</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("
                    SELECT c.*, r.room_number, r.room_type, g.name as guest_name, b.duration_type, b.booking_type, b.check_out_time, b.extension_hours, datetime('now','+8 hours') as current_db_time
                    FROM hotel_checkins c
                    JOIN hotel_bookings b ON c.booking_id = b.id
                    JOIN hotel_guests g ON b.guest_id = g.id
                    JOIN hotel_rooms r ON c.room_id = r.id
                    WHERE c.status = 'Checked-in'
                    ORDER BY c.check_in_time ASC
                ");
                $activeRows = $stmt->fetchAll();
                if (count($activeRows) === 0):
                ?>
                    <tr><td colspan="9" style="text-align:center;">No occupied rooms currently.</td></tr>
                <?php else: foreach($activeRows as $row):
                    $checkout_time = strtotime($row['check_out_time']);
                    $current_time  = strtotime($row['current_db_time']);
                    $time_left     = $checkout_time - $current_time;

                    $row_style = ($time_left <= 1800) ? "style='background-color: rgba(255, 0, 0, 0.15);'" : "";

                    if ($time_left <= 0) {
                        $status_badge = '<span class="badge badge-danger">Overdue</span>';
                    } elseif ($time_left <= 1800) {
                        $status_badge = '<span class="badge badge-warning">Due Soon</span>';
                    } else {
                        $status_badge = '<span class="badge badge-success">Occupied</span>';
                    }
                ?>
                <tr <?= $row_style ?>>
                    <td><strong><?= htmlspecialchars($row['room_number']) ?></strong></td>
                    <td><span class="badge badge-info" style="background-color: #0dcaf0; color: #fff;"><?= htmlspecialchars($row['room_type']) ?></span></td>
                    <td><?= htmlspecialchars($row['guest_name']) ?></td>
                    <td><?= date('M d, h:i A', strtotime($row['check_in_time'])) ?></td>
                    <td><?= date('M d, h:i A', $checkout_time) ?></td>
                    <td>
        <?= htmlspecialchars($row['duration_type']) ?>
        <br><span class="badge <?= $row['booking_type'] == 'Instant' ? 'badge-primary' : 'badge-warning' ?>" style="font-size: 9px; opacity: 0.8;"><?= $row['booking_type'] ?></span>
        <?php if($row['extension_hours'] > 0): ?>
            <br><small style="color: #f59e0b; font-weight: 600;">(Time extended: <?= $row['extension_hours'] ?> hrs)</small>
        <?php endif; ?>
    </td>
                    <td><?= $status_badge ?></td>
                    <td>
                        <form method="POST" action="checkin.php" style="display:flex; gap:5px; align-items:center;">
                            <input type="hidden" name="checkin_id" value="<?= $row['id'] ?>">
                            <input type="number" name="extension_hours" value="1" min="1" max="24" class="form-control" style="width:60px; padding:2px 5px; height:30px;">
                            <button type="submit" name="extend_stay" class="btn btn-warning btn-sm" style="padding:2px 8px; font-size:12px;" title="Extend stay by hours">
                                <i class='bx bx-time-five'></i> Extend
                            </button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" action="checkin.php" style="display:inline;" onsubmit="return confirm('Proceed to Check-Out and go to Billing?')">
                            <input type="hidden" name="checkout_checkin_id" value="<?= $row['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="color:#fff;">
                                <i class='bx bx-log-out'></i> Check-Out
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'calendar_modal.php'; ?>

<?php include 'footer.php'; ?>
