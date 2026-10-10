<?php
require_once 'db.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(255) NOT NULL,
            category ENUM('Toiletries', 'Mini-bar', 'Cleaning', 'Linens', 'Other') DEFAULT 'Other',
            quantity INT NOT NULL DEFAULT 0,
            unit VARCHAR(50) DEFAULT 'pc',
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            min_stock_level INT NOT NULL DEFAULT 5,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "Successfully created hotel_inventory table.\n";
} catch (PDOException $e) {
    die("Database migration failed: " . $e->getMessage());
}
?>
