<?php
$host = 'localhost';
$dbname = 'hotel_management_db';
$username = 'root';
$password = '';

try {
    // Connect with DB
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Create hotel_settings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // 2. Insert Default Costs if they don't exist
    $stmt = $pdo->prepare("INSERT IGNORE INTO hotel_settings (setting_key, setting_value) VALUES (?, ?), (?, ?)");
    $stmt->execute([
        'extra_person_cost', '100',
        'extension_hourly_cost', '100'
    ]);

    echo "<h1>Migration Successful!</h1>";
    echo "<p>Table <strong>hotel_settings</strong> created and seeded with default values.</p>";
    echo "<a href='index.php'>Go to Dashboard</a>";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}
?>
