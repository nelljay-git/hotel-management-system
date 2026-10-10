<?php
require_once 'db.php';

$message = "";

// Delete Guest
if(isset($_GET['delete'])) {
    if ($_SESSION['role'] === 'Admin') {
        $stmt = $pdo->prepare("DELETE FROM hotel_guests WHERE id = ?");
        if($stmt->execute([$_GET['delete']])) {
            $message = "<div class='alert alert-success'>Guest deleted successfully.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can delete guests.</div>";
    }
}

$search = trim($_GET['search'] ?? '');
$filter_active = isset($_GET['active_only']) && $_GET['active_only'] == '1';

include 'header.php';
?>

<?= $message ?>

<div class="card-panel">
    <div class="card-panel-header" style="flex-wrap: wrap; gap: 10px;">
        <h2>Guests Directory</h2>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <form method="GET" action="guests.php" style="display: flex; gap: 8px; align-items: center;">
                <div style="display: flex; align-items: center; background: #f1f5f9; border: 2px solid #e2e8f0; border-radius: 8px; padding: 5px 12px; gap: 6px;">
                    <i class='bx bx-search' style="color: #94a3b8;"></i>
                    <input type="text" name="search" id="guest-search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or contact..." autocomplete="off"
                        style="border: none; background: transparent; outline: none; font-size: 0.875rem; width: 200px; color: #1e293b; font-family: inherit;">
                </div>
                <label style="display:flex; align-items:center; gap:5px; font-size:0.875rem; color:#64748b; cursor:pointer; white-space:nowrap;">
                    <input type="checkbox" name="active_only" value="1" <?= $filter_active ? 'checked' : '' ?> onchange="this.form.submit()">
                    Active Stays Only
                </label>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if($search || $filter_active): ?>
                    <a href="guests.php" class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </form>
            <a href="booking.php" class="btn btn-primary btn-sm">Add Reservation</a>
        </div>
    </div>
    
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>G-ID</th>
                    <th>Name</th>
                    <th>Contact Info</th>
                    <th>Joined</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $query = "
                    SELECT MIN(g.id) as id, g.name, MAX(g.contact) as contact, MIN(g.created_at) as created_at,
                           MAX(CASE WHEN c.status = 'Checked-in' THEN 1 ELSE 0 END) as is_active
                    FROM hotel_guests g
                    LEFT JOIN hotel_bookings b ON b.guest_id = g.id
                    LEFT JOIN hotel_checkins c ON c.booking_id = b.id AND c.status = 'Checked-in'
                    WHERE 1=1
                ";
                $params = [];
                if ($search) {
                    $query .= " AND (g.name LIKE ? OR g.contact LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                $query .= " GROUP BY g.name";
                if ($filter_active) {
                    $query .= " HAVING is_active = 1";
                }
                $query .= " ORDER BY g.name ASC";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $all_guests = $stmt->fetchAll();
                
                foreach($all_guests as $row):
                ?>
                <tr>
                    <td><strong><?= $row['id'] ?></strong></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['contact']) ?></td>
                    <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                    <td>
                        <?php if($row['is_active']): ?>
                            <span class="badge badge-success"><i class='bx bx-check-circle'></i> In-House</span>
                        <?php else: ?>
                            <span class="badge" style="background:#e2e8f0;color:#64748b;">Past Guest</span>
                        <?php endif; ?>
                    </td>
                    <td class="action-links">
                        <a href="guest_details.php?id=<?= $row['id'] ?>"><i class='bx bx-show'></i> View Details</a>
                        <?php if($_SESSION['role'] === 'Admin'): ?>
                            <a href="guests.php?delete=<?= $row['id'] ?>" class="delete-link" onclick="return confirm('Deleting guest will also wipe bookings. Continue?')"><i class='bx bx-trash'></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(count($all_guests) == 0): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 30px; color:#94a3b8;">
                        <i class='bx bx-user-x' style="font-size:2.5rem; display:block; margin-bottom:10px;"></i>
                        <?= $search ? 'No guests match your search.' : 'No guests found.' ?>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
