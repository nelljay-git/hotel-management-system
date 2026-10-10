<?php
require_once 'db.php';

// Prepare Dashboard metrics
$today = date('Y-m-d'); // PHP Manila timezone
$totalGuests = $pdo->query("SELECT COUNT(*) FROM hotel_guests WHERE date(created_at) = '$today'")->fetchColumn();
$occupiedRooms = $pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Occupied'")->fetchColumn();
$availableRooms = $pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Available'")->fetchColumn();
$totalRevenue = $pdo->query("SELECT SUM(total_amount) FROM hotel_payments WHERE payment_status = 'Paid' AND date(paid_at) = '$today'")->fetchColumn();
$totalRevenue = $totalRevenue ? $totalRevenue : 0;

// 7-day revenue trend
$sevenDaysAgo = date('Y-m-d', strtotime('-6 days'));
$revenue7 = $pdo->query("
    SELECT date(paid_at) as day, SUM(total_amount) as total
    FROM hotel_payments
    WHERE payment_status = 'Paid' AND paid_at >= '$sevenDaysAgo'
    GROUP BY date(paid_at)
    ORDER BY day ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$days7 = [];
$maxRev = 0;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $val = $revenue7[$d] ?? 0;
    $days7[] = ['date' => $d, 'label' => date('D', strtotime($d)), 'total' => $val];
    if ($val > $maxRev) $maxRev = $val;
}

// Room type occupancy
$room_types = $pdo->query("
    SELECT room_type, 
           COUNT(*) as total,
           SUM(CASE WHEN status='Occupied' THEN 1 ELSE 0 END) as occupied,
           SUM(CASE WHEN status='Available' THEN 1 ELSE 0 END) as available
    FROM hotel_rooms
    GROUP BY room_type
    ORDER BY room_type ASC
")->fetchAll();

include 'header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
    <div>
        <h2 style="font-size: 1.8rem; font-weight: 700; color: var(--dark);">Dashboard Summary</h2>
    </div>
    <div style="background: #fff; padding: 10px 20px; border-radius: 8px; box-shadow: var(--shadow-sm); border: 1px solid #e2e8f0;">
        <i class='bx bx-time' style="color: var(--primary); font-size: 1.2rem; vertical-align: middle; margin-right: 5px;"></i>
        <span id="live-clock" style="font-size: 1.1rem; font-weight: 600; color: #334155; vertical-align: middle;">Loading live time...</span>
    </div>
</div>

<div class="overview-boxes">
    <div class="box">
        <div class="right-side">
            <div class="box-topic">New Guests Today</div>
            <div class="number"><?= $totalGuests ?></div>
        </div>
        <i class='bx bx-user'></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Occupied Rooms</div>
            <div class="number"><?= $occupiedRooms ?></div>
        </div>
        <i class='bx bx-bed'></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Available Rooms</div>
            <div class="number"><?= $availableRooms ?></div>
        </div>
        <i class='bx bx-door-open'></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Revenue Today</div>
            <div class="number">&#8369;<?= number_format($totalRevenue, 2) ?></div>
        </div>
        <i class='bx bx-line-chart'></i>
    </div>
</div>

<!-- Charts Row -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 25px;">
    <!-- 7-Day Revenue Trend -->
    <div class="card-panel" style="margin: 0;">
        <div class="card-panel-header">
            <h2 style="font-size: 1.1rem;"><i class='bx bx-bar-chart-alt-2' style="color:var(--primary);"></i> 7-Day Revenue Trend</h2>
            <a href="reports.php" class="btn btn-secondary btn-sm">Full Report</a>
        </div>
        <div style="padding: 10px 0 0 0;">
            <div style="display: flex; align-items: flex-end; gap: 10px; height: 140px; padding: 0 10px;">
                <?php foreach($days7 as $d):
                    $pct = $maxRev > 0 ? ($d['total'] / $maxRev * 100) : 5;
                    $pct = max($pct, 3);
                    $isToday = $d['date'] === date('Y-m-d');
                ?>
                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; height: 100%; justify-content: flex-end;">
                    <div style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-align:center;"><?= $d['total'] > 0 ? '&#8369;'.number_format($d['total']/1000,1).'k' : '' ?></div>
                    <div style="width: 100%; background: <?= $isToday ? 'var(--primary)' : '#bfdbfe' ?>; border-radius: 6px 6px 0 0; height: <?= $pct ?>%; min-height: 4px; transition: height 0.5s ease; cursor: default;" title="<?= $d['label'] ?>: &#8369;<?= number_format($d['total'], 2) ?>"></div>
                    <div style="font-size: 0.7rem; font-weight: <?= $isToday ? '700' : '500' ?>; color: <?= $isToday ? 'var(--primary)' : '#94a3b8' ?>;"><?= $d['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="text-align: center; margin-top: 8px; font-size: 0.75rem; color: #94a3b8;">
                <span style="display:inline-block; width:10px; height:10px; background:var(--primary); border-radius:2px; margin-right:4px; vertical-align:middle;"></span>Today
                <span style="display:inline-block; width:10px; height:10px; background:#bfdbfe; border-radius:2px; margin-left:12px; margin-right:4px; vertical-align:middle;"></span>Previous days
            </div>
        </div>
    </div>

    <!-- Room Type Occupancy -->
    <div class="card-panel" style="margin: 0;">
        <div class="card-panel-header">
            <h2 style="font-size: 1.1rem;"><i class='bx bx-building' style="color:var(--primary);"></i> Room Occupancy</h2>
        </div>
        <div style="padding: 5px 0;">
            <?php foreach($room_types as $rt):
                $pct_occ = $rt['total'] > 0 ? ($rt['occupied'] / $rt['total'] * 100) : 0;
            ?>
            <div style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.85rem; font-weight: 600; color: #334155;"><?= htmlspecialchars($rt['room_type']) ?></span>
                    <span style="font-size: 0.8rem; color: #64748b;"><?= $rt['occupied'] ?>/<?= $rt['total'] ?> occupied</span>
                </div>
                <div style="height: 8px; background: #e2e8f0; border-radius: 4px; overflow:hidden;">
                    <div style="height:100%; width:<?= number_format($pct_occ, 1) ?>%; background: <?= $pct_occ > 70 ? '#ef4444' : ($pct_occ > 40 ? '#f59e0b' : 'var(--primary)') ?>; border-radius:4px; transition: width 0.5s ease;"></div>
                </div>
                <div style="font-size: 0.72rem; color: #94a3b8; margin-top: 3px; text-align:right;"><?= number_format($pct_occ, 0) ?>%</div>
            </div>
            <?php endforeach; ?>
            <?php if(count($room_types) === 0): ?>
                <p style="color:#94a3b8; text-align:center; padding:20px;">No rooms configured yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card-panel" style="margin-top: 20px;">
    <div class="card-panel-header">
        <h2>Today's Bookings &amp; Check-ins</h2>
        <a href="booking.php" class="btn btn-primary btn-sm">View All</a>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room Type</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT b.*, g.name as guest_name FROM hotel_bookings b JOIN hotel_guests g ON b.guest_id = g.id WHERE date(b.check_in_time) = '$today' ORDER BY b.id DESC LIMIT 10");
                while($row = $stmt->fetch()):
                    $status = $row['status'];
                    $badgeClass = ($status == 'Actioned') ? 'badge-success' : (($status == 'Cancelled') ? 'badge-danger' : 'badge-warning');
                    if($status == 'Actioned') $status = "Confirmed";
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['guest_name']) ?></td>
                    <td><?= htmlspecialchars($row['room_type']) ?></td>
                    <td><?= date('M d, H:i', strtotime($row['check_in_time'])) ?></td>
                    <td><?= date('M d, H:i', strtotime($row['check_out_time'])) ?></td>
                    <td>
                        <span class="badge <?= $badgeClass ?>"><?= $status ?></span><br>
                        <small style="color: #64748b; font-weight: 600;"><?= $row['booking_type'] ?></small>
                    </td>
                </tr>
                <?php endwhile; ?>
                
                <?php if($stmt->rowCount() == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 25px;">No bookings found for today.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function updateLiveClock() {
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        document.getElementById('live-clock').innerText = now.toLocaleString('en-PH', options);
    }
    updateLiveClock();
    setInterval(updateLiveClock, 1000);
</script>

<?php include 'footer.php'; ?>
