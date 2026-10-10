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

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
    <div>
        <h2 style="margin:0; font-size:1.8rem; font-weight:700; color:var(--dark);">Settings</h2>
        <p style="margin:4px 0 0 0; color:#64748b; font-size:0.9rem;">Manage pricing, branding, staff accounts and activity.</p>
    </div>
    <?php if($_SESSION['role'] === 'Admin'): ?>
        <span class="badge badge-primary"><i class='bx bx-shield-quarter'></i> Administrator</span>
    <?php else: ?>
        <span class="badge badge-secondary"><i class='bx bx-user'></i> Receptionist · view only</span>
    <?php endif; ?>
</div>

<style>
    .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
    @media (max-width: 900px) { .settings-grid { grid-template-columns:1fr; } }
    .settings-grid .card-panel-header h2 i { color:var(--primary); margin-right:6px; vertical-align:-2px; }
    .input-prefix { display:flex; align-items:stretch; border:2px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#fff; transition:border-color .2s; }
    .input-prefix:focus-within { border-color:var(--primary); }
    .input-prefix > span { display:flex; align-items:center; padding:0 14px; font-weight:700; color:var(--primary); background:#f8fafc; border-right:2px solid #e2e8f0; }
    .input-prefix input { flex:1; border:none; outline:none; padding:10px 12px; font-size:1rem; font-family:inherit; background:transparent; min-width:0; }
    .input-prefix input[readonly] { background:#f1f5f9; color:#64748b; }
    .pwd-eye { background:none; border:none; cursor:pointer; padding:0 14px; color:#94a3b8; font-size:1.15rem; }
    .pwd-eye:hover { color:var(--primary); }
    .color-field { display:flex; align-items:center; gap:10px; border:2px solid #e2e8f0; border-radius:8px; padding:6px 12px; background:#fff; }
    .color-field input[type=color] { width:46px; height:34px; border:none; padding:0; background:none; cursor:pointer; }
    .color-field span { font-family:monospace; font-weight:700; color:#475569; text-transform:uppercase; font-size:0.9rem; }
    .logo-drop { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; border:2px dashed #cbd5e1; border-radius:10px; background:#f8fafc; padding:22px 14px; cursor:pointer; text-align:center; transition:border-color .2s, background .2s; color:#64748b; }
    .logo-drop:hover { border-color:var(--primary); background:#fff; }
    .logo-drop i { font-size:2rem; color:var(--primary); }
    .logo-drop img { max-height:64px; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,0.12); }
    .staff-avatar { width:36px; height:36px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem; margin-right:10px; flex-shrink:0; }
    .status-pill { display:inline-flex; align-items:center; gap:6px; font-size:0.72rem; font-weight:600; padding:3px 11px; border-radius:20px; }
    .status-pill.online { background:#dcfce7; color:#15803d; }
    .status-pill.offline { background:#f1f5f9; color:#64748b; }
    .status-pill .dot { width:8px; height:8px; border-radius:50%; background:currentColor; }
    .status-pill.online .dot { animation:pulse-dot 1.5s infinite; }
    @keyframes pulse-dot { 0%,100% { opacity:1; } 50% { opacity:.3; } }
</style>

<div class="settings-grid">
    <!-- Cost Settings -->
    <div class="card-panel" style="margin:0;">
        <div class="card-panel-header">
            <h2><i class='bx bx-wallet'></i>Global Cost Settings</h2>
        </div>
        <form method="POST" action="settings.php" style="margin-top: 15px;">
            <div class="form-group">
                <label>Extra Person Cost</label>
                <div class="input-prefix"><span>₱</span><input type="number" step="0.01" name="extra_person_cost"
                       value="<?= htmlspecialchars($settings['extra_person_cost'] ?? '100') ?>" required <?= $_SESSION['role'] !== 'Admin' ? 'readonly' : '' ?>></div>
                <small style="color: #888;">Charged per person exceeding base capacity.</small>
            </div>
            <div class="form-group">
                <label>Hourly Extension Cost</label>
                <div class="input-prefix"><span>₱/hr</span><input type="number" step="0.01" name="extension_hourly_cost"
                       value="<?= htmlspecialchars($settings['extension_hourly_cost'] ?? '100') ?>" required <?= $_SESSION['role'] !== 'Admin' ? 'readonly' : '' ?>></div>
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
    <div class="card-panel" style="margin:0;">
        <div class="card-panel-header">
            <h2><i class='bx bx-palette'></i>Site Customization</h2>
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
                <label for="hotel_logo" class="logo-drop" id="logo_drop">
                    <?php if(!empty($settings['hotel_logo'])): ?>
                        <img src="<?= htmlspecialchars($settings['hotel_logo']) ?>" id="logo_preview_img" alt="Hotel logo">
                        <span style="font-size:0.8rem; font-weight:600;">Click to replace logo</span>
                    <?php else: ?>
                        <i class='bx bx-cloud-upload' id="logo_drop_icon"></i>
                        <span style="font-size:0.85rem; font-weight:600;" id="logo_drop_text">Click to upload logo</span>
                        <small>JPG, PNG, GIF or WEBP</small>
                    <?php endif; ?>
                </label>
                <input type="file" id="hotel_logo" name="hotel_logo" accept="image/*" style="display:none;" onchange="previewLogo(this)">
                <?php if(!empty($settings['hotel_logo'])): ?>
                    <div style="margin-top:10px; text-align:right;">
                        <button type="submit" name="remove_logo" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to remove the logo?');"><i class='bx bx-trash'></i> Remove Logo</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="form-row" style="gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>Primary Color</label>
                    <div class="color-field">
                        <input type="color" id="primary_color" name="primary_color"
                               value="<?= htmlspecialchars($settings['primary_color'] ?? '#4f46e5') ?>">
                        <span id="primary_hex"><?= htmlspecialchars($settings['primary_color'] ?? '#4f46e5') ?></span>
                    </div>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Secondary Color</label>
                    <div class="color-field">
                        <input type="color" id="secondary_color" name="secondary_color"
                               value="<?= htmlspecialchars($settings['secondary_color'] ?? '#64748b') ?>">
                        <span id="secondary_hex"><?= htmlspecialchars($settings['secondary_color'] ?? '#64748b') ?></span>
                    </div>
                </div>
            </div>
            <div style="padding:14px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:15px;">
                <small style="color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; font-size:0.68rem;">Live theme preview</small>
                <div style="display:flex; gap:8px; margin-top:10px; align-items:center; flex-wrap:wrap;">
                    <span id="pv_btn" class="btn btn-sm" style="color:#fff;">Book Now</span>
                    <span id="pv_badge" class="badge" style="color:#fff;">Deluxe</span>
                    <span id="pv_text" style="font-weight:700;">Pinobretel Suites</span>
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
<div class="settings-grid">
    <!-- Create Account Card -->
    <div class="card-panel" style="margin:0;">
        <div class="card-panel-header">
            <h2><i class='bx bx-user-plus'></i>Create New Staff Account</h2>
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
                <div class="input-prefix"><input type="password" id="new_password" name="password" required placeholder="••••••••" style="font-family:inherit;"><button type="button" class="pwd-eye" onclick="togglePwd()" title="Show / hide password"><i class='bx bx-hide' id="pwd_eye_icon"></i></button></div>
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
    <div class="card-panel" style="margin:0;">
        <div class="card-panel-header">
            <h2><i class='bx bx-group'></i>Current Staff Members</h2>
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
                    foreach ($users as $user):
                        $parts = preg_split('/\s+/', trim($user['full_name']));
                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr(end($parts), 0, 1) : ''));
                        $hue = abs(crc32($user['full_name'])) % 360;
                        $is_self = ($user['id'] == $_SESSION['user_id']);
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center;">
                                <span class="staff-avatar" style="background:hsl(<?= $hue ?>, 60%, 90%); color:hsl(<?= $hue ?>, 60%, 30%);"><?= htmlspecialchars($initials) ?></span>
                                <span><strong><?= htmlspecialchars($user['full_name']) ?></strong>
                                <?php if ($is_self): ?> <span class="badge badge-success" style="font-size:10px;">You</span><?php endif; ?></span>
                            </div>
                        </td>
                        <td><span class="badge <?= $user['role'] == 'Admin' ? 'badge-primary' : 'badge-secondary' ?>"><?= $user['role'] ?></span></td>
                        <td>
                            <?php if (!$is_self): ?>
                                <a href="settings.php?delete_user=<?= $user['id'] ?>"
                                   style="color: var(--danger); font-size: 18px;"
                                   onclick="return confirm('Delete this staff member?')">
                                    <i class='bx bx-trash'></i>
                                </a>
                            <?php else: ?>
                                <small style="color:#94a3b8;"><em>Current</em></small>
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

<div class="card-panel" style="margin-top: 10px;">
    <div class="card-panel-header">
        <h2><i class='bx bx-history'></i>Staff Login History</h2>
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
                            <span class="status-pill online"><span class="dot"></span>Active Session</span>
                        <?php else: ?>
                            <span class="status-pill offline"><span class="dot"></span>Logged Out</span>
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

<script>
    // Live theme preview + hex labels
    function syncThemePreview() {
        const p = document.getElementById('primary_color');
        const s = document.getElementById('secondary_color');
        if (p) {
            document.getElementById('primary_hex').textContent = p.value.toUpperCase();
            document.getElementById('pv_btn').style.background = p.value;
            document.getElementById('pv_text').style.color = p.value;
        }
        if (s) {
            document.getElementById('secondary_hex').textContent = s.value.toUpperCase();
            document.getElementById('pv_badge').style.background = s.value;
        }
    }
    ['primary_color', 'secondary_color'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', syncThemePreview);
    });
    syncThemePreview();

    // Show / hide new-account password
    function togglePwd() {
        const input = document.getElementById('new_password');
        const icon = document.getElementById('pwd_eye_icon');
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        if (icon) icon.className = show ? 'bx bx-show' : 'bx bx-hide';
    }

    // Instant logo preview inside the dropzone
    function previewLogo(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const drop = document.getElementById('logo_drop');
            drop.innerHTML = '<img src="' + e.target.result + '" alt="Logo preview">'
                + '<span style="font-size:0.8rem; font-weight:600;">Click to choose a different file</span>';
        };
        reader.readAsDataURL(input.files[0]);
    }
</script>

<?php include 'footer.php'; ?>
