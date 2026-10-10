<?php
// Initialize Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set Timezone to Philippines (UTC+8)
date_default_timezone_set('Asia/Manila');

// SQLite database file path (project root)
$db_path = __DIR__ . '/hotel.db';

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable foreign key enforcement and WAL mode for performance
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

} catch (PDOException $e) {
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'setup.php') === false) {
        die("Database connection failed. Please run <a href='setup.php'>setup.php</a> first.");
    }
}

// Global Authentication Check
$current_page = basename($_SERVER['PHP_SELF']);
$public_pages = [
    'login.php',
    'setup.php',
    'migrate_users.php',
    'migrate_inventory.php',
    'migrate_booking_type.php',
    'migrate_extensions.php'
];

if (!isset($_SESSION['user_id']) && !in_array($current_page, $public_pages)) {
    header("Location: login.php");
    exit;
}

// Global Site Settings (defaults)
$site_settings = [
    'hotel_name'            => 'Unknown',
    'primary_color'         => '#4f46e5',
    'secondary_color'       => '#64748b',
    'extra_person_cost'     => '100',
    'extension_hourly_cost' => '100'
];

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings");
    while ($row = $stmt->fetch()) {
        $site_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) { /* Table might not exist yet */ }
?>
