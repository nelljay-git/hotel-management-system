<?php
ob_start();
require_once 'db.php';
$message = "";

// Ensure hotel_settings table exists with defaults
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotel_settings (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key   TEXT NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        updated_at    TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours'))
    )");
    $pdo->exec("INSERT OR IGNORE INTO hotel_settings (setting_key, setting_value) VALUES
        ('extra_person_cost', '100'),
        ('extension_hourly_cost', '100'),
        ('booking_buffer_time', '30'),
        ('hotel_name', 'Pinobretel Suites'),
        ('hotel_logo', ''),
        ('primary_color', '#4f46e5'),
        ('secondary_color', '#64748b')");
} catch (Exception $e) { /* silently ignore */ }

// Handle Remove Logo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_logo'])) {
    if ($_SESSION['role'] === 'Admin') {
        $pdo->prepare("UPDATE hotel_settings SET setting_value = '' WHERE setting_key = 'hotel_logo'")->execute();
        header("Location: settings.php?msg=logo_removed");
        exit;
    }
}

// Handle Update Settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    $extra_person = (float)$_POST['extra_person_cost'];
    $extension_hourly = (float)$_POST['extension_hourly_cost'];
    
    // Customization Settings (Admins Only)
    $update_customization = ($_SESSION['role'] === 'Admin' && isset($_POST['hotel_name']));

    try {
        $pdo->beginTransaction();
        
        if ($_SESSION['role'] === 'Admin') {
            $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'extra_person_cost'")->execute([$extra_person]);
            $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'extension_hourly_cost'")->execute([$extension_hourly]);
            
            if (isset($_POST['booking_buffer_time'])) {
                $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'booking_buffer_time'")->execute([$_POST['booking_buffer_time']]);
            }
        }
        
        if ($update_customization) {
            $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'hotel_name'")->execute([trim($_POST['hotel_name'])]);
            $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'primary_color'")->execute([$_POST['primary_color']]);
            $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'secondary_color'")->execute([$_POST['secondary_color']]);
            
            // Handle logo upload
            if (isset($_FILES['hotel_logo']) && $_FILES['hotel_logo']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $filename = $_FILES['hotel_logo']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    $upload_dir = 'uploads/logo/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $new_filename = 'logo_' . time() . '.' . $ext;
                    $target_path = $upload_dir . $new_filename;
                    if (move_uploaded_file($_FILES['hotel_logo']['tmp_name'], $target_path)) {
                        $pdo->prepare("UPDATE hotel_settings SET setting_value = ? WHERE setting_key = 'hotel_logo'")->execute([$target_path]);
                    }
                }
            }
        }
        
        $pdo->commit();
        header("Location: settings.php?msg=updated");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Update Failed: " . $e->getMessage() . "</div>";
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'updated') {
        $message = "<div class='alert alert-success'>Settings successfully updated.</div>";
    } elseif ($_GET['msg'] == 'logo_removed') {
        $message = "<div class='alert alert-success'>Hotel logo successfully removed.</div>";
    }
}

// Handle Create Account
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_account'])) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $role = $_POST['role'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO hotel_users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $password, $full_name, $role]);
        $message = "<div class='alert alert-success'>Account for $full_name ($role) created successfully.</div>";
    } catch (PDOException $e) {
        $message = "<div class='alert alert-danger'>Failed to create account. Username might already exist.</div>";
    }
}

// Handle Delete Account
if (isset($_GET['delete_user'])) {
    if ($_SESSION['role'] === 'Admin') {
        $user_id = (int)$_GET['delete_user'];
        if ($user_id != $_SESSION['user_id']) { // Prevent self-deletion
            $pdo->prepare("DELETE FROM hotel_users WHERE id = ?")->execute([$user_id]);
            header("Location: settings.php?msg=deleted");
            exit;
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can delete staff accounts.</div>";
    }
}

// Fetch Current Settings
$settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings")->fetchAll();
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) { /* silently ignore */ }

include 'header.php';
?>

<?= $message ?>

<div class="form-row">
    <!-- Cost Settings -->
    <div class="card-panel" style="flex: 1;">
        <div class="card-panel-header">
            <h2>Global Cost Settings</h2>
        </div>
        <form method="POST" action="settings.php" style="margin-top: 15px;">
            <div class="form-group">
                <label>Extra Person Cost (₱)</label>
                <input type="number" step="0.01" name="extra_person_cost" class="form-control" 
                       value="<?= htmlspecialchars($settings['extra_person_cost'] ?? '100') ?>" required <?= $_SESSION['role'] !== 'Admin' ? 'readonly' : '' ?>>
                <small style="color: #888;">Charged per person exceeding base capacity.</small>
            </div>
            <div class="form-group">
                <label>Hourly Extension Cost (₱ / hr)</label>
                <input type="number" step="0.01" name="extension_hourly_cost" class="form-control" 
                       value="<?= htmlspecialchars($settings['extension_hourly_cost'] ?? '100') ?>" required <?= $_SESSION['role'] !== 'Admin' ? 'readonly' : '' ?>>
                <small style="color: #888;">Charged per additional hour extended.</small>
            </div>
            <div class="form-group">
                <label>Booking Buffer Time</label>
                <select name="booking_buffer_time" class="form-select" <?= $_SESSION['role'] !== 'Admin' ? 'disabled' : '' ?>>
                    <option value="10" <?= ($settings['booking_buffer_time'] ?? '30') == '10' ? 'selected' : '' ?>>10 mins</option>
                    <option value="15" <?= ($settings['booking_buffer_time'] ?? '30') == '15' ? 'selected' : '' ?>>15 mins</option>
                    <option value="20" <?= ($settings['booking_buffer_time'] ?? '30') == '20' ? 'selected' : '' ?>>20 mins</option>
                    <option value="30" <?= ($settings['booking_buffer_time'] ?? '30') == '30' ? 'selected' : '' ?>>30 mins</option>
                    <option value="60" <?= ($settings['booking_buffer_time'] ?? '30') == '60' ? 'selected' : '' ?>>1 hour</option>
                </select>
                <small style="color: #888;">Buffer time added after checkout for next available check-in.</small>
            </div>
            <?php if($_SESSION['role'] === 'Admin'): ?>
            <button type="submit" name="save_settings" class="btn btn-primary" style="width: 100%;">
                <i class='bx bx-save'></i> Save Costs
            </button>
            <?php endif; ?>
        </form>
    </div>

    <?php if($_SESSION['role'] == 'Admin'): ?>
    <!-- Site Customization -->
    <div class="card-panel" style="flex: 1;">
        <div class="card-panel-header">
            <h2>Site Customization</h2>
        </div>
        <form method="POST" action="settings.php" enctype="multipart/form-data" style="margin-top: 15px;">
            <input type="hidden" name="extra_person_cost" value="<?= $settings['extra_person_cost'] ?>">
            <input type="hidden" name="extension_hourly_cost" value="<?= $settings['extension_hourly_cost'] ?>">
            
            <div class="form-group">
                <label>Hotel Name / Web App Name</label>
                <input type="text" name="hotel_name" class="form-control" 
                       value="<?= htmlspecialchars($settings['hotel_name'] ?? 'Pinobretel Suites') ?>" required>
            </div>
            
            <div class="form-group">
                <label>Hotel Logo</label>
                <?php if(!empty($settings['hotel_logo'])): ?>
                    <div style="margin-bottom: 10px; display: flex; align-items: flex-end; gap: 10px;">
                        <img src="<?= htmlspecialchars($settings['hotel_logo']) ?>" style="max-height: 50px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <button type="submit" name="remove_logo" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to remove the logo?');" style="height: 35px; padding: 0 10px;"><i class='bx bx-trash'></i> Remove Logo</button>
                    </div>
                <?php endif; ?>
                <input type="file" name="hotel_logo" class="form-control" accept="image/*">
                <small style="color: #888;">Upload a new logo to replace the current one. Leave empty to keep.</small>
            </div>
            <div class="form-row" style="gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>Primary Color</label>
                    <input type="color" name="primary_color" class="form-control" style="height: 45px; padding: 5px;"
                           value="<?= htmlspecialchars($settings['primary_color'] ?? '#4f46e5') ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Secondary Color</label>
                    <input type="color" name="secondary_color" class="form-control" style="height: 45px; padding: 5px;"
                           value="<?= htmlspecialchars($settings['secondary_color'] ?? '#64748b') ?>">
                </div>
            </div>
            <button type="submit" name="save_settings" class="btn btn-dark" style="width: 100%; background: var(--dark); color: #fff;">
                <i class='bx bx-palette'></i> Apply Customization
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if($_SESSION['role'] == 'Admin'): ?>
<div class="form-row">
    <!-- Create Account Card -->
    <div class="card-panel" style="flex: 1;">
        <div class="card-panel-header">
            <h2>Create New Staff Account</h2>
        </div>
        <form method="POST" action="settings.php" style="margin-top: 15px;">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" class="form-control" required placeholder="Juan Dela Cruz">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required placeholder="User-101">
            </div>
            <div class="form-group">
                <label>Initial Password</label>
                <input type="password" name="password" class="form-control" required placeholder="••••••••">
            </div>
            <div class="form-group">
                <label>System Role</label>
                <select name="role" class="form-select">
                    <option value="Receptionist">Receptionist</option>
                    <option value="Admin">Admin</option>
                </select>
            </div>
            <button type="submit" name="create_account" class="btn btn-success" style="width: 100%;">
                <i class='bx bx-user-plus'></i> Register New Account
            </button>
        </form>
    </div>

    <!-- Staff List Card -->
    <div class="card-panel" style="flex: 1;">
        <div class="card-panel-header">
            <h2>Current Staff Members</h2>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $users = $pdo->query("SELECT id, full_name, role FROM hotel_users ORDER BY full_name ASC")->fetchAll();
                    foreach ($users as $user): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($user['full_name']) ?></strong></td>
                        <td><span class="badge <?= $user['role'] == 'Admin' ? 'badge-primary' : 'badge-secondary' ?>"><?= $user['role'] ?></span></td>
                        <td>
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <a href="settings.php?delete_user=<?= $user['id'] ?>" 
                                   style="color: var(--danger); font-size: 18px;" 
                                   onclick="return confirm('Delete this staff member?')">
                                    <i class='bx bx-trash'></i>
                                </a>
                            <?php else: ?>
                                <small><em>Current</em></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card-panel" style="margin-top: 30px;">
    <div class="card-panel-header">
        <h2>Staff Login History</h2>
        <p style="color: #666; font-size: 0.9rem;">Track the last 20 login and logout events for all accounts.</p>
    </div>
    
    <div class="table-responsive" style="margin-top: 15px;">
        <table>
            <thead>
                <tr>
                    <th>Staff Name</th>
                    <th>Username</th>
                    <th>Login Time</th>
                    <th>Logout Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $login_stmt = $pdo->prepare("
                    SELECT h.*, u.full_name, u.username 
                    FROM hotel_login_history h 
                    JOIN hotel_users u ON h.user_id = u.id 
                    ORDER BY h.login_time DESC 
                    LIMIT 20
                ");
                $login_stmt->execute();
                $histories = $login_stmt->fetchAll();
                
                foreach($histories as $log):
                    $is_online = is_null($log['logout_time']);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($log['full_name']) ?></strong></td>
                    <td><small><?= htmlspecialchars($log['username']) ?></small></td>
                    <td><?= date('M d, Y h:i A', strtotime($log['login_time'])) ?></td>
                    <td><?= $log['logout_time'] ? date('M d, Y h:i A', strtotime($log['logout_time'])) : '---' ?></td>
                    <td>
                        <?php if($is_online): ?>
                            <span class="badge badge-success" style="font-size: 10px;">Active Session</span>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size: 10px;">Logged Out</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($histories) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 20px;">No login history recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
