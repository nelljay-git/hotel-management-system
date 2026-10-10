<?php
require_once 'db.php';

$selected_date = $_GET['date'] ?? null;
$selected_month = $_GET['month'] ?? null;

// If neither is set, default to today's date
if (!$selected_date && !$selected_month) {
    $selected_date = date('Y-m-d');
}

$where_clause = "";
$where_clause_created = "";
$where_clause_h_created = "";
$where_clause_p = "";
$params = [];
$period_label = "";

if ($selected_month) {
    $where_clause          = "strftime('%Y-%m', paid_at) = ?";
    $where_clause_created  = "strftime('%Y-%m', created_at) = ?";
    $where_clause_h_created = "strftime('%Y-%m', h.created_at) = ?";
    $where_clause_p        = "strftime('%Y-%m', p.paid_at) = ?";
    $params      = [$selected_month];
    $period_label = date('F Y', strtotime($selected_month . '-01'));
} else {
    $where_clause          = "date(paid_at) = ?";
    $where_clause_created  = "date(created_at) = ?";
    $where_clause_h_created = "date(h.created_at) = ?";
    $where_clause_p        = "date(p.paid_at) = ?";
    $params      = [$selected_date];
    $period_label = date('M d, Y', strtotime($selected_date));
}

// 1. Fetch Revenue Summary for the selected period
// We count revenue as anything 'Paid' within that specific period (paid_at)
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_revenue,
        SUM(room_charges) as total_room_charges,
        SUM(additional_services) as total_additional,
        COUNT(*) as total_transactions
    FROM hotel_payments 
    WHERE payment_status = 'Paid' AND $where_clause
");
$stmt->execute($params);
$daily = $stmt->fetch();

$total_revenue = $daily['total_revenue'] ?? 0;
$room_rev = $daily['total_room_charges'] ?? 0;
$extra_rev = $daily['total_additional'] ?? 0;
$trans_count = $daily['total_transactions'] ?? 0;

// 2. Fetch Pending Receivables (Unpaid) created during that period
$stmt = $pdo->prepare("SELECT SUM(total_amount) FROM hotel_payments WHERE payment_status = 'Unpaid' AND $where_clause_created");
$stmt->execute($params);
$pending_revenue = $stmt->fetchColumn() ?? 0;

include 'header.php';
?>

<div class="card-panel">
    <div class="card-panel-header">
        <h2>Financial Revenue Report</h2>
        <form method="GET" action="reports.php" class="form-row" style="margin: 0; align-items: center; gap: 10px;">
            <div style="display: flex; gap: 10px; align-items: center; border-right: 2px solid #e2e8f0; padding-right: 15px;">
                <label for="date" style="margin: 0; font-weight:600; color: #64748b;">Daily:</label>
                <input type="date" name="date" id="date" class="form-control" value="<?= $selected_date ?>" onchange="document.getElementById('month').value=''; this.form.submit()" style="width: auto;">
            </div>
            <div style="display: flex; gap: 10px; align-items: center; padding-left: 5px;">
                <label for="month" style="margin: 0; font-weight:600; color: #64748b;">Monthly:</label>
                <input type="month" name="month" id="month" class="form-control" value="<?= $selected_month ?>" onchange="document.getElementById('date').value=''; this.form.submit()" style="width: auto;">
            </div>
            <noscript><button type="submit" class="btn btn-primary btn-sm">Filter</button></noscript>
        </form>
    </div>
</div>

<div class="overview-boxes" style="margin-top: 20px;">
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Total Revenue (Paid)</div>
            <div class="number">₱<?= number_format($total_revenue, 2) ?></div>
            <div class="indicator" style="font-size: 12px; color: #666; font-weight: 600;">
                Period: <?= $period_label ?>
            </div>
        </div>
        <i class='bx bx-money-withdraw' style="background: #e0f2fe; color: #0369a1;"></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Unpaid Receivables</div>
            <div class="number">₱<?= number_format($pending_revenue, 2) ?></div>
            <div class="indicator" style="font-size: 12px; color: #ef4444; font-weight: 600;">
                From bills created this period
            </div>
        </div>
        <i class='bx bx-time-five' style="background: #fef2f2; color: #991b1b;"></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Transactions</div>
            <div class="number"><?= $trans_count ?></div>
            <div class="indicator" style="font-size: 12px; color: #666; font-weight: 600;">
                Successful payments
            </div>
        </div>
        <i class='bx bx-transfer-alt' style="background: #f0fdf4; color: #166534;"></i>
    </div>
</div>

<div class="card-panel" style="margin-top: 20px;">
    <div class="card-panel-header">
        <h2>Transaction Details <span style="font-size:1rem; color:#64748b; font-weight:500;">(<?= $period_label ?>)</span></h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Inv #</th>
                    <th>Type</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Original</th>
                    <th>Base Chrg</th>
                    <th>Extra Pers</th>
                    <th>Ext. Hours</th>
                    <th>Extra Svc</th>
                    <th>Total</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->prepare("
                    SELECT p.*, g.name as guest_name, r.room_number, 
                           b.booking_type, b.num_guests, b.extra_charges, b.extension_hours,
                           b.duration_type, b.base_price
                    FROM hotel_payments p
                    JOIN hotel_checkins c ON p.checkin_id = c.id
                    JOIN hotel_bookings b ON c.booking_id = b.id
                    JOIN hotel_guests g ON b.guest_id = g.id
                    JOIN hotel_rooms r ON c.room_id = r.id
                    WHERE p.payment_status = 'Paid' AND $where_clause_p
                    ORDER BY p.paid_at DESC
                ");
                $stmt->execute($params);
                $paid_rows = $stmt->fetchAll();
                
                foreach($paid_rows as $row):
                ?>
                <tr>
                    <td>INV-10<?= $row['id'] ?></td>
                    <td><span class="badge <?= $row['booking_type'] == 'Instant' ? 'badge-primary' : 'badge-warning' ?>" style="font-size: 10px;"><?= $row['booking_type'] ?></span></td>
                    <td><strong><?= htmlspecialchars($row['guest_name']) ?></strong></td>
                    <td>Room <?= $row['room_number'] ?></td>
                    <td><small><?= $row['duration_type'] ?></small></td>
                    <td style="text-align: right;">₱<?= number_format($row['base_price'], 2) ?></td>
                    <td style="text-align: right;">₱<?= number_format($row['extra_charges'], 2) ?></td>
                    <td style="text-align: center;"><?= $row['extension_hours'] ?></td>
                    <td style="text-align: right;">₱<?= number_format($row['additional_services'], 2) ?></td>
                    <td style="font-weight: 600; color: var(--primary); text-align: right;">₱<?= number_format($row['total_amount'], 2) ?></td>
                    <td><small><?= $selected_month ? date('M d, h:i A', strtotime($row['paid_at'])) : date('h:i A', strtotime($row['paid_at'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(count($paid_rows) == 0): ?>
                    <tr><td colspan="11" style="text-align:center; padding: 30px; color: #999;">No paid transactions found for this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-panel" style="margin-top: 20px;">
    <div class="card-panel-header">
        <h2>Revenue Source Breakdown</h2>
    </div>
    <div style="display: flex; gap: 20px; flex-wrap: wrap; padding: 10px;">
        <div style="flex: 1; min-width: 250px; background: #fff; border: 1px solid #e5e7eb; padding: 20px; border-radius: 12px; text-align: center;">
            <h4 style="color: #666; margin-bottom: 10px;">Room Sales</h4>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);">₱<?= number_format($room_rev, 2) ?></div>
            <div style="margin-top: 10px; height: 8px; background: #f3f4f6; border-radius: 4px; overflow: hidden;">
                <div style="width: <?= $total_revenue > 0 ? ($room_rev / $total_revenue * 100) : 0 ?>%; height: 100%; background: var(--primary);"></div>
            </div>
        </div>
        <div style="flex: 1; min-width: 250px; background: #fff; border: 1px solid #e5e7eb; padding: 20px; border-radius: 12px; text-align: center;">
            <h4 style="color: #666; margin-bottom: 10px;">Extra Services / Fees</h4>
            <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;">₱<?= number_format($extra_rev, 2) ?></div>
            <div style="margin-top: 10px; height: 8px; background: #f3f4f6; border-radius: 4px; overflow: hidden;">
                <div style="width: <?= $total_revenue > 0 ? ($extra_rev / $total_revenue * 100) : 0 ?>%; height: 100%; background: #10b981;"></div>
            </div>
        </div>
    </div>
</div>

<div class="card-panel" style="margin-top: 20px;">
    <div class="card-panel-header">
        <h2><i class='bx bx-cart' style="color:var(--primary);"></i> Mini-bar Sales Summary</h2>
        <span style="font-size:0.9rem; color:#64748b; font-weight:500;"><?= $period_label ?></span>
    </div>
    <?php
    $minibar_stmt = $pdo->prepare("
        SELECT i.item_name, 
               SUM(ABS(h.change_amount)) as qty_sold,
               SUM(ABS(h.change_amount) * h.historical_selling_price) as revenue,
               SUM(ABS(h.change_amount) * (h.historical_selling_price - h.historical_unit_price)) as profit
        FROM hotel_inventory_history h
        JOIN hotel_inventory i ON h.item_id = i.id
        WHERE h.change_type = 'Guest Sale' AND $where_clause_h_created
        GROUP BY i.item_name
        ORDER BY revenue DESC
    ");
    $minibar_stmt->execute($params);
    $minibar_sales = $minibar_stmt->fetchAll();
    $total_mb_rev = array_sum(array_column($minibar_sales, 'revenue'));
    $total_mb_profit = array_sum(array_column($minibar_sales, 'profit'));
    $total_mb_qty = array_sum(array_column($minibar_sales, 'qty_sold'));
    ?>

    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
        <div style="flex:1; min-width:150px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:15px; text-align:center;">
            <div style="font-size:0.8rem; color:#166534; font-weight:600;">Total Revenue</div>
            <div style="font-size:1.5rem; font-weight:800; color:#059669;">&#8369;<?= number_format($total_mb_rev, 2) ?></div>
        </div>
        <div style="flex:1; min-width:150px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:15px; text-align:center;">
            <div style="font-size:0.8rem; color:#92400e; font-weight:600;">Est. Profit</div>
            <div style="font-size:1.5rem; font-weight:800; color:#d97706;">&#8369;<?= number_format($total_mb_profit, 2) ?></div>
        </div>
        <div style="flex:1; min-width:150px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:15px; text-align:center;">
            <div style="font-size:0.8rem; color:#1e40af; font-weight:600;">Items Sold</div>
            <div style="font-size:1.5rem; font-weight:800; color:#2563eb;"><?= $total_mb_qty ?></div>
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th style="text-align:center;">Qty Sold</th>
                    <th style="text-align:right;">Revenue</th>
                    <th style="text-align:right;">Est. Profit</th>
                    <th style="text-align:right;">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($minibar_sales as $mb):
                    $margin = $mb['revenue'] > 0 ? ($mb['profit'] / $mb['revenue'] * 100) : 0;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($mb['item_name']) ?></strong></td>
                    <td style="text-align:center;"><span class="badge badge-primary"><?= $mb['qty_sold'] ?></span></td>
                    <td style="text-align:right; font-weight:600;">&#8369;<?= number_format($mb['revenue'], 2) ?></td>
                    <td style="text-align:right; font-weight:700; color:#059669;">&#8369;<?= number_format($mb['profit'], 2) ?></td>
                    <td style="text-align:right;">
                        <span style="font-size:0.8rem; font-weight:600; color:<?= $margin > 30 ? '#059669' : ($margin > 15 ? '#d97706' : '#ef4444') ?>;"><?= number_format($margin, 1) ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($minibar_sales) === 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">
                        <i class='bx bx-cart' style="font-size:2rem; display:block; margin-bottom:8px;"></i>
                        No mini-bar sales found for this period.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
<script>
    document.getElementById('page-title').innerText = 'Revenue Reports';
</script>

