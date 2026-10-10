<?php
// Calculate Sidebar Indicators
$near_checkout_count = 0;
$cleaning_rooms_count = 0;
if (isset($pdo)) {
    // Rooms currently being cleaned
    $cleaning_rooms_count = $pdo->query("SELECT COUNT(*) FROM hotel_rooms WHERE status = 'Cleaning'")->fetchColumn();
    
    // Guests near check-out (within 30 mins) or overdue
    $near_checkout_count = $pdo->query("
        SELECT COUNT(*) FROM hotel_checkins c
        JOIN hotel_bookings b ON c.booking_id = b.id
        WHERE c.status = 'Checked-in' AND b.check_out_time <= datetime('now','+8 hours','+30 minutes')
    ")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_settings['hotel_name'] ?? 'LuxeHotel') ?> Management System</title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Boxicons for Modern Vector Icons -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: <?= $site_settings['primary_color'] ?? '#4f46e5' ?>;
            --secondary: <?= $site_settings['secondary_color'] ?? '#64748b' ?>;
        }
        /* Dynamic Primary Hover (Approximation) */
        .btn-primary:hover {
            filter: brightness(0.9);
        }
        .sidebar .logo i, .overview-boxes i, .profile-details i {
            color: var(--primary);
        }
    </style>
</head>
<body>

<!-- Sidebar Navigation -->
<div class="sidebar">
    <div class="logo">
        <?php if(!empty($site_settings['hotel_logo'])): ?>
            <img src="<?= htmlspecialchars($site_settings['hotel_logo']) ?>" style="max-height: 35px; border-radius: 4px; margin-right: 10px;">
        <?php else: ?>
            <i class='bx bx-building-house'></i>
        <?php endif; ?>
        <span><?= htmlspecialchars($site_settings['hotel_name'] ?? 'LuxeHotel') ?></span>
    </div>
    <ul class="nav-links">
        <li>
            <a href="index.php" id="nav-dashboard">
                <i class='bx bx-grid-alt'></i>
                <span class="links_name">Dashboard</span>
            </a>
        </li>
        <li>
            <a href="booking.php" id="nav-booking">
                <i class='bx bx-calendar-event'></i>
                <span class="links_name">Bookings</span>
            </a>
        </li>
        <li>
            <a href="guests.php" id="nav-guests">
                <i class='bx bx-user'></i>
                <span class="links_name">Guests</span>
            </a>
        </li>
        <li>
            <a href="rooms.php" id="nav-rooms">
                <i class='bx bx-bed'></i>
                <span class="links_name">Rooms</span>
            </a>
        </li>
        <li>
            <a href="checkin.php" id="nav-checkin" class="<?= $near_checkout_count > 0 ? 'notification-active' : '' ?>">
                <i class='bx bx-key'></i>
                <span class="links_name">Check-in / Out</span>
                <?php if($near_checkout_count > 0): ?>
                    <span style="background:#ef4444; color:#fff; border-radius:12px; padding: 2px 7px; font-size:0.75rem; font-weight:700; position:absolute; right:15px; box-shadow: 0 0 5px rgba(239,68,68,0.5);" title="Checkouts due within 30mins!"><?= $near_checkout_count ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="billing.php" id="nav-billing">
                <i class='bx bx-receipt'></i>
                <span class="links_name">Billing</span>
            </a>
        </li>
        <li>
            <a href="shop.php" id="nav-shop">
                <i class='bx bx-shopping-bag'></i>
                <span class="links_name">Mini-bar Shop</span>
            </a>
        </li>
        <li>
            <a href="housekeeping.php" id="nav-housekeeping" class="<?= $cleaning_rooms_count > 0 ? 'notification-active' : '' ?>">
                <i class='bx bx-brush'></i>
                <span class="links_name">Housekeeping</span>
                <?php if($cleaning_rooms_count > 0): ?>
                    <span style="background:#f59e0b; color:#fff; border-radius:12px; padding: 2px 7px; font-size:0.75rem; font-weight:700; position:absolute; right:15px; box-shadow: 0 0 5px rgba(245,158,11,0.5);" title="Rooms currently being cleaned"><?= $cleaning_rooms_count ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="reports.php" id="nav-reports">
                <i class='bx bx-pie-chart-alt-2'></i>
                <span class="links_name">Revenue Reports</span>
            </a>
        </li>
        <li>
            <a href="inventory.php" id="nav-inventory">
                <i class='bx bx-package'></i>
                <span class="links_name">Inventory</span>
            </a>
        </li>
        <li>
            <a href="settings.php" id="nav-settings">
                <i class='bx bx-cog'></i>
                <span class="links_name">Settings</span>
            </a>
        </li>
    </ul>
</div>

<!-- Main Content Area -->
<section class="main-content">
    <nav class="top-nav">
        <div class="sidebar-button">
            <i class='bx bx-menu sidebarBtn'></i>
            <span class="dashboard" id="page-title">Dashboard</span>
        </div>

        <!-- Global Guest Search -->
        <div id="global-search-wrap" style="flex: 1; max-width: 380px; margin: 0 20px; position: relative;">
            <div style="display: flex; align-items: center; background: #f1f5f9; border: 2px solid #e2e8f0; border-radius: 10px; padding: 6px 14px; gap: 8px; transition: border-color 0.2s;" id="search-box-container">
                <i class='bx bx-search' style="color: #94a3b8; font-size: 1.2rem; flex-shrink: 0;"></i>
                <input type="text" id="global-guest-search" placeholder="Search guest by name..." autocomplete="off"
                    style="border: none; background: transparent; outline: none; font-size: 0.9rem; width: 100%; color: #1e293b; font-family: inherit;">
                <span id="search-spinner" style="display:none;"><i class='bx bx-loader-alt bx-spin' style="color:#94a3b8;"></i></span>
            </div>
            <div id="search-results-dropdown" style="display:none; position:absolute; top:calc(100% + 6px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.12); z-index:9999; overflow:hidden; max-height: 300px; overflow-y: auto;"></div>
        </div>
        
        <div class="profile-details">
            <i class='bx bx-user' style="font-size: 24px; color: var(--primary);"></i>
            <span class="admin_name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Staff') ?></span>
            <span style="font-size: 10px; color: #64748b; margin-left: 5px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">
                <?= htmlspecialchars($_SESSION['role'] ?? 'User') ?>
            </span>
            <a href="logout.php" style="margin-left: 15px; color: #ef4444; font-size: 20px; transition: 0.3s;" title="Log Out">
                <i class='bx bx-log-out'></i>
            </a>
        </div>
    </nav>

    <script>
    (function() {
        const input = document.getElementById('global-guest-search');
        const dropdown = document.getElementById('search-results-dropdown');
        const spinner = document.getElementById('search-spinner');
        const container = document.getElementById('search-box-container');
        let debounce;

        input.addEventListener('input', function() {
            clearTimeout(debounce);
            const q = this.value.trim();
            if (q.length < 2) { dropdown.style.display = 'none'; return; }
            spinner.style.display = 'block';
            debounce = setTimeout(() => {
                fetch('guest_search_ajax.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        spinner.style.display = 'none';
                        if (data.length === 0) {
                            dropdown.innerHTML = '<div style="padding:16px;color:#94a3b8;text-align:center;font-size:0.875rem;">No guests found</div>';
                        } else {
                            dropdown.innerHTML = data.map(g => `
                                <a href="guest_details.php?id=${g.id}" style="display:flex;align-items:center;gap:12px;padding:12px 16px;text-decoration:none;color:#1e293b;border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                    <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#818cf8);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.9rem;flex-shrink:0;">${g.name.charAt(0).toUpperCase()}</div>
                                    <div>
                                        <div style="font-weight:600;font-size:0.9rem;">${g.name}</div>
                                        <div style="font-size:0.75rem;color:#64748b;">${g.contact}</div>
                                    </div>
                                </a>`).join('');
                        }
                        dropdown.style.display = 'block';
                    }).catch(() => { spinner.style.display = 'none'; });
            }, 300);
        });

        input.addEventListener('focus', () => { container.style.borderColor = 'var(--primary)'; });
        input.addEventListener('blur', () => {
            container.style.borderColor = '#e2e8f0';
            setTimeout(() => { dropdown.style.display = 'none'; }, 200);
        });
    })();
    </script>

    <script>
    // Auto-record logout time when staff closes the browser/tab without clicking Logout
    (function() {
        let beaconSent = false;
        function sendAutoLogout() {
            if (beaconSent) return;
            beaconSent = true;
            navigator.sendBeacon('auto_logout.php');
        }
        window.addEventListener('beforeunload', sendAutoLogout);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') sendAutoLogout();
        });
    })();
    </script>

    <div class="home-content">
