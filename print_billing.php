<?php
require_once 'db.php';

// Fetch hotel name from settings
$settings = [];
try {
    $sRows = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings")->fetchAll();
    foreach ($sRows as $sr) { $settings[$sr['setting_key']] = $sr['setting_value']; }
} catch (Exception $e) {}
$hotel_name = $settings['hotel_name'] ?? 'LuxeHotel';

$selected_date = $_GET['date'] ?? null;
$selected_month = $_GET['month'] ?? null;

$where_clauses = ["1=1"];
$params = [];
$report_title = "Billing & Invoices Report";
$report_subtitle = "All Records";

if ($selected_date) {
    $where_clauses[] = "date(p.paid_at) = ?";
    $params[] = $selected_date;
    $report_subtitle = "Daily Report: " . date('F d, Y', strtotime($selected_date));
} elseif ($selected_month) {
    $where_clauses[] = "strftime('%Y-%m', p.paid_at) = ?";
    $params[] = $selected_month;
    $report_subtitle = "Monthly Report: " . date('F Y', strtotime($selected_month . '-01'));
}

$where_sql = implode(" AND ", $where_clauses);

$query = "
    SELECT p.id as pay_id, p.room_charges, p.additional_services, p.total_amount, p.payment_status, p.paid_at,
           c.id as checkin_id, r.room_number, g.name as guest_name
    FROM hotel_payments p
    JOIN hotel_checkins c ON p.checkin_id = c.id
    JOIN hotel_bookings b ON c.booking_id = b.id
    JOIN hotel_guests g ON b.guest_id = g.id
    JOIN hotel_rooms r ON c.room_id = r.id
    WHERE $where_sql
    ORDER BY p.paid_at DESC, p.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bills = $stmt->fetchAll();

$total_collected = 0;
foreach($bills as $b) {
    if($b['payment_status'] == 'Paid') {
        $total_collected += $b['total_amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $report_title ?> | <?= htmlspecialchars($hotel_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; padding: 40px; }

        .report-wrapper { max-width: 1000px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e2e8f0; }
        
        .report-header { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; padding: 40px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .logo-area h1 { font-size: 2rem; font-weight: 800; letter-spacing: 2px; margin-bottom: 5px; }
        .logo-area p { opacity: 0.9; font-size: 1rem; font-weight: 500; }
        
        .report-info { text-align: right; }
        .report-info h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 5px; }
        .report-info p { opacity: 0.8; font-size: 0.875rem; }

        .report-body { padding: 40px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; }
        .stat-label { font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .stat-value { font-size: 1.5rem; font-weight: 800; color: #1e293b; }
        .stat-value.success { color: #059669; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; padding: 12px 15px; border-bottom: 2px solid #e2e8f0; color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; color: #334155; }
        tr:last-child td { border-bottom: none; }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fef2f2; color: #991b1b; }

        .footer { padding: 30px 40px; background: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 0.75rem; }

        .no-print-actions { max-width: 1000px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; }
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.875rem; transition: all 0.2s; border: none; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }

        @media print {
            body { background: #fff; padding: 0; }
            .report-wrapper { box-shadow: none; border-radius: 0; border: none; max-width: 100%; }
            .no-print-actions { display: none; }
            .stat-card { border: 1px solid #eee; }
        }
    </style>
</head>
<body>

    <div class="no-print-actions">
        <a href="billing.php" class="btn btn-secondary"><i class='bx bx-left-arrow-alt'></i> Back to Billing</a>
        <button onclick="window.print()" class="btn btn-primary"><i class='bx bx-printer'></i> Print Report</button>
    </div>

    <div class="report-wrapper">
        <div class="report-header">
            <div class="header-content">
                <div class="logo-area">
                    <h1><?= htmlspecialchars(strtoupper($hotel_name)) ?></h1>
                    <p>Billing & Invoices Summary</p>
                </div>
                <div class="report-info">
                    <h2><?= $report_subtitle ?></h2>
                    <p>Generated on: <?= date('F d, Y h:i A') ?></p>
                </div>
            </div>
        </div>

        <div class="report-body">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value"><?= count($bills) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Collected</div>
                    <div class="stat-value success">₱<?= number_format($total_collected, 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending (Unpaid)</div>
                    <div class="stat-value" style="color:#ef4444;"><?= count(array_filter($bills, fn($b) => $b['payment_status'] == 'Unpaid')) ?></div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Inv #</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Room Charges</th>
                        <th>Other Svcs</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;">No billing records found for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($bills as $row): 
                            $bclass = $row['payment_status'] == 'Paid' ? 'badge-success' : 'badge-danger';
                        ?>
                        <tr>
                            <td>INV-10<?= $row['pay_id'] ?></td>
                            <td><strong><?= htmlspecialchars($row['guest_name']) ?></strong></td>
                            <td>Room <?= $row['room_number'] ?></td>
                            <td>₱<?= number_format($row['room_charges'], 2) ?></td>
                            <td>₱<?= number_format($row['additional_services'], 2) ?></td>
                            <td style="font-weight: 700;">₱<?= number_format($row['total_amount'], 2) ?></td>
                            <td><span class="badge <?= $bclass ?>"><?= $row['payment_status'] ?></span></td>
                            <td><small><?= $row['paid_at'] ? date('M d, Y', strtotime($row['paid_at'])) : '---' ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <p><?= htmlspecialchars($hotel_name) ?> Management System - Official Billing Report</p>
            <p style="margin-top: 5px; opacity: 0.6;">This report is computer-generated for internal and guest records.</p>
        </div>
    </div>

    <script>
        window.addEventListener('load', function() {
            // Optional: Auto-trigger print
            // window.print();
        });
    </script>
</body>
</html>
