<?php
require_once 'db.php';

if (!isset($_GET['id'])) {
    header("Location: guests.php");
    exit;
}

$guest_id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM hotel_guests WHERE id = ?");
$stmt->execute([$guest_id]);
$guest = $stmt->fetch();

if (!$guest) {
    header("Location: guests.php");
    exit;
}

$filter_month = $_GET['filter_month'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';
$where_clause = "WHERE g.name = ?";
$params = [$guest['name']];

if ($filter_date) {
    $where_clause .= " AND date(b.check_in_time) = ?";
    $params[] = $filter_date;
} elseif ($filter_month) {
    $where_clause .= " AND strftime('%Y-%m', b.check_in_time) = ?";
    $params[] = $filter_month;
}

// Fetch all histories for this guest (matching by exact name to aggregate duplicates)
$stmt = $pdo->prepare("
    SELECT b.id as booking_id, b.room_type, b.duration_type, b.base_price, b.extra_charges, b.check_in_time, b.check_out_time, 
           r.room_number,
           p.additional_services, p.total_amount as bill_total, p.payment_status, p.paid_at,
           c.id as checkin_id
    FROM hotel_bookings b
    LEFT JOIN hotel_checkins c ON b.id = c.booking_id
    LEFT JOIN hotel_payments p ON c.id = p.checkin_id
    LEFT JOIN hotel_rooms r ON c.room_id = r.id
    JOIN hotel_guests g ON b.guest_id = g.id
    $where_clause
    ORDER BY b.check_in_time DESC
");
$stmt->execute($params);
$history = $stmt->fetchAll();

// Metrics
$total_spent = 0;
foreach($history as $h) {
    if ($h['payment_status'] == 'Paid') $total_spent += $h['bill_total'];
}
$visits = count($history);

// Fetch Minibar History
$checkin_ids = array_filter(array_column($history, 'checkin_id'));
$minibar_history = [];
if (!empty($checkin_ids)) {
    $like_conditions = array_map(function($id) { return "h.notes LIKE '%Sold to Check-in #$id%'"; }, $checkin_ids);
    $like_clause = implode(' OR ', $like_conditions);
    
    $minibar_where = "h.change_type = 'Guest Sale' AND ($like_clause)";
    $minibar_params = [];
    
    if ($filter_date) {
        $minibar_where .= " AND date(h.created_at) = ?";
        $minibar_params[] = $filter_date;
    } elseif ($filter_month) {
        $minibar_where .= " AND strftime('%Y-%m', h.created_at) = ?";
        $minibar_params[] = $filter_month;
    }
    
    $stmt = $pdo->prepare("
        SELECT h.*, i.item_name 
        FROM hotel_inventory_history h 
        JOIN hotel_inventory i ON h.item_id = i.id 
        WHERE $minibar_where
        ORDER BY h.created_at DESC
    ");
    $stmt->execute($minibar_params);
    $minibar_history = $stmt->fetchAll();
}

include 'header.php';
?>

<div class="overview-boxes" style="margin-bottom: 25px;">
    <div class="box" style="width: 32%;">
        <div class="right-side">
            <div class="box-topic">Total Stays</div>
            <div class="number"><?= $visits ?></div>
            <div class="indicator" style="font-size: 12px; color: #666;">Times visited <?= htmlspecialchars($site_settings['hotel_name'] ?? 'LuxeHotel') ?></div>
        </div>
        <i class='bx bx-hotel' style="background: #fdf2f8; color: #db2777;"></i>
    </div>
    <div class="box" style="width: 33%;">
        <div class="right-side">
            <div class="box-topic">Life-time Spending</div>
            <div class="number">₱<?= number_format($total_spent, 2) ?></div>
            <div class="indicator" style="font-size: 12px; color: #10b981;">Total revenue contributed</div>
        </div>
        <i class='bx bx-wallet' style="background: #f0fdf4; color: #16a34a;"></i>
    </div>
    <div class="box" style="width: 32%;">
        <div class="right-side">
            <div class="box-topic">Contact Info</div>
            <div class="number" style="font-size: 1rem;"><?= htmlspecialchars($guest['contact']) ?></div>
            <div class="indicator" style="font-size: 12px; color: #666;">Joined: <?= date('M Y', strtotime($guest['created_at'])) ?></div>
        </div>
        <i class='bx bx-id-card' style="background: #eff6ff; color: #2563eb;"></i>
    </div>
</div>

<div class="card-panel">
    <div class="card-panel-header" style="justify-content: space-between; flex-wrap: wrap;">
        <h2 style="font-size: 1.5rem; text-transform: uppercase; letter-spacing: 1px;">Guest: <strong><?= htmlspecialchars($guest['name']) ?></strong></h2>
        
        <div style="display: flex; align-items: center; justify-content: flex-end; flex-grow: 1; flex-wrap: wrap;">
            <form method="GET" action="guest_details.php" style="display: flex; gap: 10px; align-items: center; margin-right: 15px;">
                <input type="hidden" name="id" value="<?= $guest_id ?>">
                
                <label style="font-weight: 600; color: #64748b; margin: 0; white-space: nowrap;">Month:</label>
                <input type="month" name="filter_month" class="form-control" style="width: auto; padding: 5px 10px; height: 35px;" value="<?= htmlspecialchars($filter_month) ?>">
                
                <label style="font-weight: 600; color: #64748b; margin: 0; white-space: nowrap; margin-left: 10px;">Day:</label>
                <input type="date" name="filter_date" class="form-control" style="width: auto; padding: 5px 10px; height: 35px;" value="<?= htmlspecialchars($filter_date) ?>">
                
                <button type="submit" class="btn btn-primary btn-sm" style="height: 35px;">Filter</button>
                <?php if($filter_month || $filter_date): ?>
                    <a href="guest_details.php?id=<?= $guest_id ?>" class="btn btn-secondary btn-sm" style="height: 35px; background:#e2e8f0; color:#333;">Clear</a>
                <?php endif; ?>
            </form>
            <a href="guests.php" class="btn btn-secondary btn-sm" style="height: 35px;"><i class='bx bx-arrow-back'></i> Back to Directory</a>
        </div>
    </div>
    
    <div class="table-responsive" style="margin-top: 20px;">
        <table>
            <thead>
                <tr>
                    <th>Stay Date</th>
                    <th>Room</th>
                    <th>Original Plan</th>
                    <th>Base Price</th>
                    <th>Extra Charges</th>
                    <th>Services / Shop</th>
                    <th>Stay Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($history as $h): 
                    $room_display = $h['room_number'] ? 'Room ' . $h['room_number'] : $h['room_type'] . ' (Awaiting)';
                ?>
                <tr>
                    <td>
                        <small><strong>In:</strong> <?= date('M d, Y h:i A', strtotime($h['check_in_time'])) ?></small><br>
                        <small><strong>Out:</strong> <?= date('M d, Y h:i A', strtotime($h['check_out_time'])) ?></small>
                    </td>
                    <td><strong><?= $room_display ?></strong></td>
                    <td><small><?= $h['duration_type'] ?></small></td>
                    <td>₱<?= number_format($h['base_price'], 2) ?></td>
                    <td>
                        <?php if($h['extra_charges'] > 0): ?>
                            <span style="color: #ef4444; font-weight: 500;">+₱<?= number_format($h['extra_charges'], 2) ?></span>
                        <?php else: ?>
                            ₱0.00
                        <?php endif; ?>
                    </td>
                    <td>₱<?= number_format($h['additional_services'] ?? 0, 2) ?></td>
                    <td style="font-weight: 700; color: var(--primary);">
                        <?php 
                            $projected_total = $h['base_price'] + $h['extra_charges'] + ($h['additional_services'] ?? 0);
                            $final_total = !empty($h['bill_total']) ? $h['bill_total'] : $projected_total;
                        ?>
                        ₱<?= number_format($final_total, 2) ?>
                    </td>
                    <td>
                        <?php if($h['payment_status'] == 'Paid'): ?>
                            <span class="badge badge-success"><i class='bx bx-check-double'></i> Paid</span><br>
                            <small style="color: #64748b; font-size: 0.7rem;"><?= date('M d, h:i A', strtotime($h['paid_at'])) ?></small>
                        <?php else: ?>
                            <span class="badge badge-warning">Unpaid</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(count($history) == 0): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 40px; color: #94a3b8;">
                        <i class='bx bx-history' style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                        No stay history found for this guest yet.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-panel" style="margin-top: 25px;">
    <div class="card-panel-header">
        <h2 style="font-size: 1.5rem; text-transform: uppercase; letter-spacing: 1px;">Mini-bar Purchases</h2>
    </div>
    
    <div class="table-responsive" style="margin-top: 20px;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item Name</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total Price</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($minibar_history as $mb): 
                    $qty = abs($mb['change_amount']);
                    $total = $qty * $mb['historical_selling_price'];
                ?>
                <tr>
                    <td><?= date('M d, Y h:i A', strtotime($mb['created_at'])) ?></td>
                    <td><strong><?= htmlspecialchars($mb['item_name']) ?></strong></td>
                    <td><?= $qty ?></td>
                    <td>₱<?= number_format($mb['historical_selling_price'], 2) ?></td>
                    <td style="font-weight: 700; color: var(--primary);">₱<?= number_format($total, 2) ?></td>
                    <td><small style="color: #64748b;"><?= htmlspecialchars($mb['notes']) ?></small></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(count($minibar_history) == 0): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 40px; color: #94a3b8;">
                        <i class='bx bx-cart' style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                        No mini-bar purchases found for this guest.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.getElementById('page-title').innerText = 'Guest Activity / Records';
    document.getElementById('nav-guests').classList.add('active');
</script>

<?php include 'footer.php'; ?>
