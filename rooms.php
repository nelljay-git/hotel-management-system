<?php
require_once 'db.php';

$message = "";

// Handle Delete
if (isset($_GET['delete'])) {
    if ($_SESSION['role'] === 'Admin') {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM hotel_rooms WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = "<div class='alert alert-success'>Room deleted successfully.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can delete rooms.</div>";
    }
}

// Handle Add/Edit Room
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_room'])) {
    if ($_SESSION['role'] === 'Admin') {
        $room_number = $_POST['room_number'];
        $room_type = $_POST['room_type'];
        if ($room_type === 'Custom') {
            $room_type = trim($_POST['custom_room_type'] ?? '');
        }
        
        if ($_POST['price_3h'] === '' || $_POST['price_6h'] === '' || $_POST['price_12h'] === '') {
            $message = "<div class='alert alert-danger'>Please fill in all required room rates.</div>";
        } else {
        $price_3h = (float)$_POST['price_3h'];
        $price_6h = (float)$_POST['price_6h'];
        $price_12h = (float)$_POST['price_12h'];
        $price_24h = ($_POST['price_24h'] ?? '') !== '' ? (float)$_POST['price_24h'] : $price_12h;
        $capacity = (int)$_POST['capacity'];
        $status = $_POST['status'];
        $room_id = $_POST['room_id'] ?? null;

        if (empty($room_type)) {
            $message = "<div class='alert alert-danger'>Room type cannot be empty.</div>";
        } else {
            if ($room_id) {
                $stmt = $pdo->prepare("UPDATE hotel_rooms SET room_number=?, room_type=?, price_3h=?, price_6h=?, price_12h=?, price_24h=?, status=?, capacity=? WHERE id=?");
                if ($stmt->execute([$room_number, $room_type, $price_3h, $price_6h, $price_12h, $price_24h, $status, $capacity, $room_id])) {
                    $message = "<div class='alert alert-success'>Room updated successfully.</div>";
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO hotel_rooms (room_number, room_type, price_3h, price_6h, price_12h, price_24h, status, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$room_number, $room_type, $price_3h, $price_6h, $price_12h, $price_24h, $status, $capacity])) {
                    $message = "<div class='alert alert-success'>Room added successfully.</div>";
                }
            }
        }
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can add or edit rooms.</div>";
    }
}

// Check if Edit mode
$edit_room = null;
if (isset($_GET['edit'])) {
    if ($_SESSION['role'] === 'Admin') {
        $stmt = $pdo->prepare("SELECT * FROM hotel_rooms WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit_room = $stmt->fetch();
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can edit rooms.</div>";
    }
}

// Fetch existing room types dynamically
$room_types = [];
try {
    $rtypes_stmt = $pdo->query("SELECT DISTINCT room_type FROM hotel_rooms ORDER BY room_type ASC");
    $room_types = $rtypes_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
$room_types = array_unique($room_types);

$val_room_num = $edit_room['room_number'] ?? ''; 
$val_room_type = $edit_room['room_type'] ?? ($_POST['room_type'] ?? 'Single');
$val_status = $edit_room['status'] ?? ($_POST['status'] ?? 'Available');
$val_3h = $edit_room['price_3h'] ?? ($_POST['price_3h'] ?? '');
$val_6h = $edit_room['price_6h'] ?? ($_POST['price_6h'] ?? '');
$val_12h = $edit_room['price_12h'] ?? ($_POST['price_12h'] ?? '');
$val_24h = $edit_room['price_24h'] ?? ($_POST['price_24h'] ?? '');
$default_24h = $val_24h !== '' ? $val_24h : $val_12h;
$val_capacity = $edit_room['capacity'] ?? ($_POST['capacity'] ?? '2');

include 'header.php';
?>

<?= $message ?>

<?php if ($_SESSION['role'] === 'Admin'): ?>
<div class="card-panel">
    <div class="card-panel-header">
        <h2><?= $edit_room ? 'Edit Room' : 'Add New Room' ?></h2>
        <?php if($edit_room): ?>
            <a href="rooms.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
        <?php endif; ?>
    </div>
    
    <form method="POST" action="rooms.php">
        <?php if($edit_room): ?>
            <input type="hidden" name="room_id" value="<?= $edit_room['id'] ?>">
        <?php endif; ?>
        
        <div class="form-row">
            <div class="form-group">
                <label>Room Number</label>
                <input type="text" name="room_number" class="form-control" placeholder="e.g. 101" required value="<?= htmlspecialchars($val_room_num) ?>">
            </div>
            <div class="form-group">
                <label>Room Type</label>
                <select name="room_type" id="room_type" class="form-select" required onchange="toggleCustomType()">
                    <?php foreach ($room_types as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $val_room_type == $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                    <option value="Custom">Custom (Enter new type...)</option>
                </select>
            </div>
            <div class="form-group" id="custom_type_group" style="display: none;">
                <label>Custom Room Type</label>
                <input type="text" name="custom_room_type" id="custom_room_type" class="form-control" placeholder="e.g. Suite">
            </div>
            <div class="form-group">
                <label>Room Capacity</label>
                <input type="number" name="capacity" class="form-control" placeholder="e.g. 2" required min="1" max="20" value="<?= htmlspecialchars($val_capacity) ?>">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-select" required>
                    <option <?= $val_status == 'Available' ? 'selected' : '' ?>>Available</option>
                    <option <?= $val_status == 'Occupied' ? 'selected' : '' ?>>Occupied</option>
                    <option <?= $val_status == 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>22 Hours Rate (₱) <span style="color:red;">*</span></label>
                <input type="number" step="0.01" name="price_3h" class="form-control" required value="<?= htmlspecialchars($val_3h) ?>">
            </div>
            <div class="form-group">
                <label>1 Day Rate (₱) <span style="color:red;">*</span></label>
                <input type="number" step="0.01" name="price_6h" class="form-control" required value="<?= htmlspecialchars($val_6h) ?>">
            </div>
             <div class="form-group">
                <label>2 Days Rate (₱) <span style="color:red;">*</span></label>
                <input type="number" step="0.01" name="price_12h" class="form-control" required value="<?= htmlspecialchars($val_12h) ?>">
            </div>
        </div>
        <input type="hidden" name="price_24h" value="<?= htmlspecialchars($default_24h) ?>">
        <button type="submit" name="save_room" class="btn btn-primary"><i class='bx bx-save'></i> Save Room</button>

        <script>
        function toggleCustomType() {
            const typeSelect = document.getElementById('room_type');
            const customGroup = document.getElementById('custom_type_group');
            const customInput = document.getElementById('custom_room_type');
            if (typeSelect.value === 'Custom') {
                customGroup.style.display = 'block';
                customInput.required = true;
            } else {
                customGroup.style.display = 'none';
                customInput.required = false;
                customInput.value = '';
            }
        }
        document.addEventListener('DOMContentLoaded', toggleCustomType);
        </script>
    </form>
</div>
<?php endif; ?>

<div class="card-panel">
    <div class="card-panel-header">
        <h2>Manage Rooms</h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Room #</th>
                    <th>Type</th>
                    <th>Rates (22h/1d/2d/24h)</th>
                    <th>Status</th>
                    <?php if($_SESSION['role'] === 'Admin'): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM hotel_rooms ORDER BY room_number ASC");
                $all_rooms = $stmt->fetchAll();
                
                foreach($all_rooms as $row):
                    $sc = 'badge-success';
                    if($row['status'] == 'Occupied') $sc = 'badge-primary';
                    if($row['status'] == 'Maintenance') $sc = 'badge-warning';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['room_number']) ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($row['room_type']) ?></strong><br>
                        <small style="color:#64748b;">Max Capacity: <?= htmlspecialchars($row['capacity']) ?> <?= $row['capacity'] > 1 ? 'guests' : 'guest' ?></small>
                    </td>
                    <td>
                        <small style="font-weight: 500;">
                            <?= $row['price_3h'] !== null ? '₱'.number_format($row['price_3h'], 0) : '-' ?> / 
                            <?= $row['price_6h'] !== null ? '₱'.number_format($row['price_6h'], 0) : '-' ?> / 
                            <?= $row['price_12h'] !== null ? '₱'.number_format($row['price_12h'], 0) : '-' ?> / 
                            ₱<?= number_format($row['price_24h'], 0) ?>
                        </small>
                    </td>
                    <td>
                        <?php if ($_SESSION['role'] !== 'Admin'): ?>
                            <select class="room-status-dropdown status-<?= strtolower($row['status']) ?>"
                                    data-room-id="<?= $row['id'] ?>"
                                    onchange="updateRoomStatus(this)">
                                <option value="Available" <?= $row['status'] == 'Available' ? 'selected' : '' ?>>✅ Available</option>
                                <option value="Maintenance" <?= $row['status'] == 'Maintenance' ? 'selected' : '' ?>>🔧 Maintenance</option>
                                <?php if ($row['status'] == 'Occupied'): ?>
                                    <option value="Occupied" selected disabled>🔴 Occupied</option>
                                <?php endif; ?>
                            </select>
                        <?php else: ?>
                            <span class="badge <?= $sc ?>"><?= $row['status'] ?></span>
                        <?php endif; ?>
                    </td>
                    <?php if($_SESSION['role'] === 'Admin'): ?>
                    <td class="action-links">
                        <a href="rooms.php?edit=<?= $row['id'] ?>"><i class='bx bx-edit'></i> Edit</a>
                        <a href="rooms.php?delete=<?= $row['id'] ?>" class="delete-link" onclick="return confirm('Are you sure?')"><i class='bx bx-trash'></i> Delete</a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if(count($all_rooms) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 25px;">No rooms found. Add one above.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .room-status-dropdown {
        padding: 6px 12px;
        border-radius: 20px;
        border: 2px solid #ddd;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        outline: none;
        transition: all 0.3s ease;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        background-repeat: no-repeat;
        background-position: right 8px center;
        background-size: 12px;
        padding-right: 28px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23666'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
    }
    .room-status-dropdown.status-available {
        background-color: #e8f5e9;
        border-color: #4caf50;
        color: #2e7d32;
    }
    .room-status-dropdown.status-maintenance {
        background-color: #fff3e0;
        border-color: #ff9800;
        color: #e65100;
    }
    .room-status-dropdown.status-occupied {
        background-color: #e3f2fd;
        border-color: #1976d2;
        color: #1565c0;
        pointer-events: none;
        opacity: 0.8;
    }
    .room-status-dropdown:hover:not(.status-occupied) {
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .room-status-dropdown:focus:not(.status-occupied) {
        box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 63,81,181), 0.25);
    }
    .status-toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 24px;
        border-radius: 8px;
        color: #fff;
        font-weight: 600;
        font-size: 0.9rem;
        z-index: 10000;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    .status-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    .status-toast.success { background: #43a047; }
    .status-toast.error { background: #e53935; }
</style>

<script>
function updateRoomStatus(selectEl) {
    const roomId = selectEl.getAttribute('data-room-id');
    const newStatus = selectEl.value;

    // Optimistic UI update — change classes immediately
    selectEl.className = 'room-status-dropdown status-' + newStatus.toLowerCase();

    const formData = new FormData();
    formData.append('room_id', roomId);
    formData.append('status', newStatus);

    fetch('update_room_status_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            showStatusToast(data.error, 'error');
            // Revert — reload to get real state
            location.reload();
        } else {
            showStatusToast(`Room status updated to ${data.status}`, 'success');
        }
    })
    .catch(err => {
        showStatusToast('Network error. Please try again.', 'error');
        location.reload();
    });
}

function showStatusToast(message, type) {
    // Remove any existing toast
    const existing = document.querySelector('.status-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'status-toast ' + type;
    toast.textContent = message;
    document.body.appendChild(toast);

    // Trigger animation
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
</script>

<?php include 'footer.php'; ?>
