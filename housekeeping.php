<?php
ob_start();
require_once 'db.php';

$message = "";

// Create table if not exists (safe for SQLite)
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_housekeeping_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id    INTEGER NOT NULL,
    note       TEXT NOT NULL,
    staff_id   INTEGER,
    created_at TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours')),
    FOREIGN KEY (room_id)  REFERENCES hotel_rooms(id) ON DELETE CASCADE
)");

// Handle Add Note
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_note'])) {
    $room_id = (int)$_POST['room_id'];
    $note = trim($_POST['note']);
    if ($room_id && $note) {
        $pdo->prepare("INSERT INTO hotel_housekeeping_logs (room_id, note, staff_id) VALUES (?, ?, ?)")
            ->execute([$room_id, $note, $_SESSION['user_id']]);
        $message = "<div class='alert alert-success'>Note saved for room.</div>";
    }
}

// Handle status update to Available
if (isset($_GET['ready_id'])) {
    $room_id = (int)$_GET['ready_id'];
    try {
        $stmt = $pdo->prepare("UPDATE hotel_rooms SET status = 'Available' WHERE id = ? AND status = 'Cleaning'");
        if ($stmt->execute([$room_id])) {
            $message = "<div class='alert alert-success'>Room marked as Available and ready for next guest!</div>";
        }
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}

include 'header.php';
?>

<?= $message ?>

<div class="card-panel">
    <div class="card-panel-header">
        <h2>Housekeeping Management</h2>
        <p>Rooms awaiting cleaning after check-out</p>
    </div>
    
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Room #</th>
                    <th>Room Type</th>
                    <th>Current Status</th>
                    <th>Add Note</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM hotel_rooms WHERE status = 'Cleaning' ORDER BY room_number ASC");
                $cleaning_rooms = $stmt->fetchAll();
                
                foreach($cleaning_rooms as $row):
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['room_number']) ?></strong></td>
                    <td><?= htmlspecialchars($row['room_type']) ?></td>
                    <td><span class="badge badge-warning">Cleaning In-Progress</span></td>
                    <td>
                        <form method="POST" action="housekeeping.php" style="display:flex; gap:6px; align-items:center;">
                            <input type="hidden" name="room_id" value="<?= $row['id'] ?>">
                            <input type="text" name="note" class="form-control" placeholder="e.g. Needs extra towels..." style="font-size:0.8rem; padding:4px 8px; min-width:200px;" required>
                            <button type="submit" name="add_note" class="btn btn-secondary btn-sm" title="Save Note"><i class='bx bx-note'></i></button>
                        </form>
                    </td>
                    <td>
                        <a href="housekeeping.php?ready_id=<?= $row['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Confirm room is clean and ready?')">
                            <i class='bx bx-check'></i> Mark as Available
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(count($cleaning_rooms) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 40px; color: #666;">
                        <i class='bx bx-check-shield' style='font-size: 3rem; display: block; margin-bottom: 10px;'></i>
                        No rooms currently require cleaning.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Housekeeping Notes Log -->
<div class="card-panel" style="margin-top: 20px;">
    <div class="card-panel-header">
        <h2>Recent Housekeeping Notes</h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Room #</th>
                    <th>Note</th>
                    <th>Staff</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $logs = $pdo->query("
                    SELECT l.*, r.room_number, r.room_type, u.full_name as staff_name
                    FROM hotel_housekeeping_logs l
                    JOIN hotel_rooms r ON l.room_id = r.id
                    LEFT JOIN hotel_users u ON l.staff_id = u.id
                    ORDER BY l.created_at DESC
                    LIMIT 30
                ")->fetchAll();
                foreach($logs as $log):
                ?>
                <tr>
                    <td><small><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></small></td>
                    <td><strong>Room <?= htmlspecialchars($log['room_number']) ?></strong><br><small style="color:#64748b;"><?= htmlspecialchars($log['room_type']) ?></small></td>
                    <td><?= htmlspecialchars($log['note']) ?></td>
                    <td><small><?= htmlspecialchars($log['staff_name'] ?? 'System') ?></small></td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($logs) === 0): ?>
                    <tr><td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">No housekeeping notes recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-panel" style="margin-top: 20px;">
    <div class="card-panel-header">
        <h2>Quick Room Status Overview</h2>
    </div>
    <div class="overview-boxes" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-top: 0;">
        <?php
        $stats = $pdo->query("SELECT status, COUNT(*) as count FROM hotel_rooms GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $statuses = ['Available' => 'bx-check-circle', 'Occupied' => 'bx-bed', 'Cleaning' => 'bx-brush', 'Maintenance' => 'bx-wrench'];
        foreach($statuses as $label => $icon):
            $count = $stats[$label] ?? 0;
        ?>
        <div class="box" style="padding: 15px;">
            <div class="right-side">
                <div class="box-topic" style="font-size: 14px;"><?= $label ?></div>
                <div class="number" style="font-size: 24px;"><?= $count ?></div>
            </div>
            <i class='bx <?= $icon ?>' style="font-size: 32px;"></i>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
<script>
    document.getElementById('page-title').innerText = 'Housekeeping';
</script>
