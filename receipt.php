<?php
require_once 'db.php';

$pay_id = (int)($_GET['id'] ?? 0);
if (!$pay_id) { header("Location: billing.php"); exit; }

// Fetch bill + booking details including extension_hours
$stmt = $pdo->prepare("
    SELECT p.*, g.name as guest_name, g.contact,
           r.room_number, r.room_type, r.capacity,
           b.check_in_time, b.check_out_time, b.duration_type, b.num_guests,
           b.extra_charges, b.extension_hours, b.base_price,
           c.id as checkin_id
    FROM hotel_payments p
    JOIN hotel_checkins c ON p.checkin_id = c.id
    JOIN hotel_bookings b ON c.booking_id = b.id
    JOIN hotel_guests g ON b.guest_id = g.id
    JOIN hotel_rooms r ON c.room_id = r.id
    WHERE p.id = ?
");
$stmt->execute([$pay_id]);
$bill = $stmt->fetch();
if (!$bill) { header("Location: billing.php"); exit; }

// Fetch settings for cost rates
$settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings")->fetchAll();
    foreach ($rows as $row) { $settings[$row['setting_key']] = $row['setting_value']; }
} catch (Exception $e) {}
$extra_person_rate = $settings['extra_person_cost'] ?? 100;
$extension_rate    = $settings['extension_hourly_cost'] ?? 100;

// Derive extra person cost vs extension cost from combined extra_charges
$capacity = (int)($bill['capacity'] ?? 2);

$extra_person_count = max(0, $bill['num_guests'] - $capacity);
$extra_person_charge = $extra_person_count * $extra_person_rate;
$extension_charge = $bill['extension_hours'] > 0 ? ($bill['extension_hours'] * $extension_rate) : 0;

// Fetch mini-bar items linked to this checkin
$minibar_items = [];
if ($bill['checkin_id']) {
    $mb_stmt = $pdo->prepare("
        SELECT h.change_amount, h.historical_selling_price, h.notes, i.item_name
        FROM hotel_inventory_history h
        JOIN hotel_inventory i ON h.item_id = i.id
        WHERE h.change_type = 'Guest Sale' AND h.notes LIKE ?
        ORDER BY h.created_at ASC
    ");
    $mb_stmt->execute(['%Sold to Check-in #' . $bill['checkin_id'] . '%']);
    $minibar_items = $mb_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt - INV-10<?= $bill['id'] ?> | <?= htmlspecialchars($settings['hotel_name'] ?? 'Pinobretel Suites') ?></title>
    <link href="css/fonts.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; padding: 20px; }

        .receipt-wrapper { max-width: 540px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .receipt-header { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; padding: 30px; text-align: center; }
        .receipt-header h1 { font-size: 1.8rem; font-weight: 800; letter-spacing: 2px; }
        .receipt-header p { opacity: 0.85; font-size: 0.875rem; margin-top: 4px; }
        .inv-badge { display: inline-block; background: rgba(255,255,255,0.2); border-radius: 8px; padding: 6px 16px; font-size: 0.8rem; font-weight: 700; margin-top: 12px; letter-spacing: 1px; }

        .receipt-body { padding: 24px 28px; }
        .section-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; font-weight: 700; margin-bottom: 8px; margin-top: 4px; }
        .info-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 7px 0; border-bottom: 1px dashed #f1f5f9; font-size: 0.85rem; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; }
        .info-value { font-weight: 600; text-align: right; max-width: 60%; }
        .divider { border: none; border-top: 2px dashed #e2e8f0; margin: 18px 0; }

        /* Charges table */
        .charge-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f8fafc; font-size: 0.875rem; }
        .charge-row:last-child { border-bottom: none; }
        .charge-label { color: #475569; display: flex; align-items: center; gap: 7px; }
        .charge-label .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .charge-amount { font-weight: 600; }

        /* Mini-bar sub-items */
        .minibar-sub { background: #f8fafc; border-radius: 8px; padding: 8px 12px; margin: 4px 0 8px 0; }
        .minibar-sub-row { display: flex; justify-content: space-between; font-size: 0.8rem; padding: 4px 0; color: #475569; }

        .grand-total { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-radius: 12px; padding: 18px; margin-top: 14px; display: flex; justify-content: space-between; align-items: center; }
        .grand-total .label { font-weight: 700; color: #166534; font-size: 1rem; }
        .grand-total .amount { font-size: 1.7rem; font-weight: 800; color: #059669; }
        .status-paid { display: inline-flex; align-items: center; gap: 5px; background: #dcfce7; color: #166534; padding: 5px 14px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; }

        .receipt-footer { background: #f8fafc; padding: 18px 28px; text-align: center; border-top: 1px solid #e2e8f0; }
        .receipt-footer p { font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px; }

        /* Screen-only action buttons */
        .print-actions { max-width: 540px; margin: 20px auto; display: flex; gap: 10px; justify-content: center; }
        .btn-print { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; border: none; padding: 12px 30px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; display: inline-flex; align-items: center; gap: 8px; }
        .btn-back { background: #e2e8f0; color: #334155; border: none; padding: 12px 24px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; font-family: 'Inter', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }

        @media print {
            body { background: white; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .receipt-wrapper { box-shadow: none; border-radius: 0; max-width: 100%; }
            .print-actions { display: none !important; }
        }
    </style>
</head>
<body>

<div class="print-actions">
    <a href="billing.php" class="btn-back">&#8592; Back</a>
    <button class="btn-print" onclick="window.print()">&#128438; Print Receipt</button>
</div>

<div class="receipt-wrapper">
    <div class="receipt-header">
        <h1>&#127970; <?= htmlspecialchars(strtoupper($settings['hotel_name'] ?? 'Pinobretel Suites')) ?></h1>
        <p>Official Payment Receipt</p>
        <div class="inv-badge">INV-10<?= $bill['id'] ?></div>
    </div>

    <div class="receipt-body">

        <!-- Guest Info -->
        <div class="section-label">Guest Information</div>
        <div class="info-row">
            <span class="info-label">Name</span>
            <span class="info-value"><?= htmlspecialchars($bill['guest_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Contact</span>
            <span class="info-value"><?= htmlspecialchars($bill['contact']) ?></span>
        </div>

        <hr class="divider">

        <!-- Stay Details -->
        <div class="section-label">Stay Details</div>
        <div class="info-row">
            <span class="info-label">Room</span>
            <span class="info-value">Room <?= htmlspecialchars($bill['room_number']) ?> &ndash; <?= htmlspecialchars($bill['room_type']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Duration Plan</span>
            <span class="info-value"><?= htmlspecialchars($bill['duration_type']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Check-In</span>
            <span class="info-value"><?= date('M d, Y h:i A', strtotime($bill['check_in_time'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Check-Out</span>
            <span class="info-value"><?= date('M d, Y h:i A', strtotime($bill['check_out_time'])) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">No. of Guests</span>
            <span class="info-value"><?= $bill['num_guests'] ?></span>
        </div>

        <hr class="divider">

        <!-- Charges Breakdown -->
        <div class="section-label">Charges Breakdown</div>

        <!-- Base Room -->
        <div class="charge-row">
            <span class="charge-label"><span class="dot" style="background:#4f46e5;"></span> Room Charge (<?= htmlspecialchars($bill['duration_type']) ?>)</span>
            <span class="charge-amount">&#8369;<?= number_format($bill['base_price'], 2) ?></span>
        </div>

        <!-- Extra Persons -->
        <?php if($extra_person_count > 0): ?>
        <div class="charge-row">
            <span class="charge-label"><span class="dot" style="background:#f59e0b;"></span> Extra Person &times;<?= $extra_person_count ?> (&#8369;<?= number_format($extra_person_rate, 2) ?> each)</span>
            <span class="charge-amount" style="color:#d97706;">+&#8369;<?= number_format($extra_person_charge, 2) ?></span>
        </div>
        <?php endif; ?>

        <!-- Extension Hours -->
        <?php if($bill['extension_hours'] > 0): ?>
        <div class="charge-row">
            <span class="charge-label"><span class="dot" style="background:#10b981;"></span> Extended Stay &times;<?= $bill['extension_hours'] ?> hr<?= $bill['extension_hours'] > 1 ? 's' : '' ?> (&#8369;<?= number_format($extension_rate, 2) ?>/hr)</span>
            <span class="charge-amount" style="color:#059669;">+&#8369;<?= number_format($extension_charge, 2) ?></span>
        </div>
        <?php endif; ?>

        <!-- Mini-bar Items -->
        <?php if(!empty($minibar_items)): ?>
        <div class="charge-row" style="flex-direction:column; align-items:flex-start; gap:0;">
            <div style="display:flex; justify-content:space-between; width:100%; align-items:center; padding-bottom:6px;">
                <span class="charge-label"><span class="dot" style="background:#7c3aed;"></span> Mini-bar / Room Services</span>
                <span class="charge-amount" style="color:#7c3aed;">
                    +&#8369;<?= number_format(array_sum(array_map(fn($m) => abs($m['change_amount']) * $m['historical_selling_price'], $minibar_items)), 2) ?>
                </span>
            </div>
            <div class="minibar-sub" style="width:100%;">
                <?php foreach($minibar_items as $mb):
                    $qty = abs($mb['change_amount']);
                    $item_total = $qty * $mb['historical_selling_price'];
                ?>
                <div class="minibar-sub-row">
                    <span><?= htmlspecialchars($mb['item_name']) ?> &times;<?= $qty ?></span>
                    <span style="font-weight:600;">&#8369;<?= number_format($item_total, 2) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Grand Total -->
        <div class="grand-total">
            <div class="label">&#10003; TOTAL PAID</div>
            <div class="amount">&#8369;<?= number_format($bill['total_amount'], 2) ?></div>
        </div>

        <div style="margin-top:14px; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:0.8rem; color:#64748b;">Paid on: <strong><?= date('M d, Y h:i A', strtotime($bill['paid_at'])) ?></strong></div>
            <div class="status-paid">&#10003; PAID IN FULL</div>
        </div>
    </div>

    <div class="receipt-footer">
        <p>Thank you for staying with us at <strong><?= htmlspecialchars($settings['hotel_name'] ?? 'Pinobretel Suites') ?></strong>!</p>
        <p>We hope to see you again soon. &#128149;</p>
        <p style="margin-top:8px; font-size:0.7rem; color:#cbd5e1;">This is a computer-generated receipt and requires no signature.</p>
    </div>
</div>

<script>
    // Auto-print immediately when page loads
    window.addEventListener('load', function() {
        window.print();
    });
</script>

</body>
</html>
