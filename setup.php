<?php
date_default_timezone_set('Asia/Manila');

$db_path = __DIR__ . '/hotel.db';

try {
    // Delete existing database file to start fresh
    if (file_exists($db_path)) {
        unlink($db_path);
    }

    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    // ── hotel_users ──────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_users (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            username    TEXT NOT NULL UNIQUE,
            password    TEXT NOT NULL,
            full_name   TEXT NOT NULL,
            role        TEXT NOT NULL DEFAULT 'Receptionist'
                            CHECK(role IN ('Admin','Receptionist')),
            created_at  TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours'))
        )
    ");

    // ── hotel_guests ─────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_guests (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            contact    TEXT NOT NULL,
            created_at TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours'))
        )
    ");

    // ── hotel_rooms ──────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_rooms (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            room_number TEXT NOT NULL UNIQUE,
            room_type   TEXT NOT NULL,
            price_3h    REAL DEFAULT NULL,
            price_6h    REAL DEFAULT NULL,
            price_12h   REAL DEFAULT NULL,
            price_24h   REAL NOT NULL,
            status      TEXT NOT NULL DEFAULT 'Available'
                            CHECK(status IN ('Available','Occupied','Maintenance','Cleaning')),
            capacity    INTEGER NOT NULL DEFAULT 2
        )
    ");

    // ── hotel_bookings ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_bookings (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            guest_id       INTEGER NOT NULL,
            room_id        INTEGER,
            check_in_time  TEXT NOT NULL,
            check_out_time TEXT NOT NULL,
            room_type      TEXT NOT NULL,
            num_guests     INTEGER NOT NULL,
            duration_type  TEXT NOT NULL
                               CHECK(duration_type IN ('3 Hours','6 Hours','12 Hours','Per Night','Custom')),
            base_price     REAL NOT NULL DEFAULT 0.00,
            booking_type   TEXT NOT NULL DEFAULT 'Reservation'
                               CHECK(booking_type IN ('Reservation','Instant')),
            status         TEXT NOT NULL DEFAULT 'Pending'
                               CHECK(status IN ('Pending','Actioned','Cancelled')),
            is_paid        INTEGER NOT NULL DEFAULT 0,
            extra_charges  REAL NOT NULL DEFAULT 0.00,
            extension_hours INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (guest_id) REFERENCES hotel_guests(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id)  REFERENCES hotel_rooms(id)  ON DELETE SET NULL
        )
    ");

    // ── hotel_checkins ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_checkins (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id    INTEGER NOT NULL,
            room_id       INTEGER NOT NULL,
            check_in_time TEXT NOT NULL,
            status        TEXT NOT NULL DEFAULT 'Checked-in'
                              CHECK(status IN ('Checked-in','Checked-out')),
            FOREIGN KEY (booking_id) REFERENCES hotel_bookings(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id)    REFERENCES hotel_rooms(id)    ON DELETE CASCADE
        )
    ");

    // ── hotel_payments ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_payments (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            checkin_id          INTEGER NOT NULL,
            room_charges        REAL NOT NULL DEFAULT 0.00,
            additional_services REAL NOT NULL DEFAULT 0.00,
            total_amount        REAL NOT NULL DEFAULT 0.00,
            payment_status      TEXT NOT NULL DEFAULT 'Unpaid'
                                    CHECK(payment_status IN ('Unpaid','Paid')),
            created_at          TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours')),
            paid_at             TEXT DEFAULT NULL,
            FOREIGN KEY (checkin_id) REFERENCES hotel_checkins(id) ON DELETE CASCADE
        )
    ");

    // ── hotel_inventory ──────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_inventory (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name      TEXT NOT NULL,
            category       TEXT NOT NULL DEFAULT 'Other'
                               CHECK(category IN ('Toiletries','Mini-bar','Cleaning','Linens','Other')),
            quantity       INTEGER NOT NULL DEFAULT 0,
            unit           TEXT DEFAULT 'pc',
            unit_price     REAL NOT NULL DEFAULT 0.00,
            selling_price  REAL NOT NULL DEFAULT 0.00,
            min_stock_level INTEGER NOT NULL DEFAULT 5,
            created_at     TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours'))
        )
    ");

    // ── hotel_inventory_history ──────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_inventory_history (
            id                       INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id                  INTEGER NOT NULL,
            change_amount            INTEGER NOT NULL,
            change_type              TEXT NOT NULL
                                         CHECK(change_type IN ('Stock-In','Stock-Out','Guest Sale','Adjustment')),
            historical_unit_price    REAL DEFAULT 0.00,
            historical_selling_price REAL DEFAULT 0.00,
            user_id                  INTEGER,
            notes                    TEXT,
            created_at               TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours')),
            FOREIGN KEY (item_id)  REFERENCES hotel_inventory(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id)  REFERENCES hotel_users(id)     ON DELETE SET NULL
        )
    ");

    // ── hotel_login_history ──────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_login_history (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            login_time  TEXT NOT NULL,
            logout_time TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES hotel_users(id) ON DELETE CASCADE
        )
    ");

    // ── hotel_settings ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE hotel_settings (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key   TEXT NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at    TEXT DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','+8 hours'))
        )
    ");

    // ── Seed: Default Admin ───────────────────────────────────────────────────
    $hash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO hotel_users (username, password, full_name, role) VALUES ('admin', ?, 'Administrator', 'Admin')")
        ->execute([$hash]);

    // ── Seed: Default Settings ────────────────────────────────────────────────
    $pdo->exec("
        INSERT INTO hotel_settings (setting_key, setting_value) VALUES
            ('extra_person_cost',     '100'),
            ('extension_hourly_cost', '100'),
            ('hotel_name',            'Pinobretel Suites'),
            ('primary_color',         '#4f46e5'),
            ('secondary_color',       '#64748b')
    ");

    // ── Seed: Default Rooms ───────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO hotel_rooms (room_number, room_type, price_3h, price_6h, price_12h, price_24h, status, capacity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(['101', 'Single',  350,  600, 1000, 1500, 'Available', 1]);
    $stmt->execute(['102', 'Double',  500,  900, 1500, 2500, 'Available', 2]);
    $stmt->execute(['301', 'Deluxe',  800, 1500, 2500, 4500, 'Available', 4]);

    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
    <title>Setup Complete</title>
    <style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;background:#f8fafc;}
    h1{color:#10b981;} ul li{margin:6px 0;} .btn{display:inline-block;padding:10px 20px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:8px;margin-top:20px;}
    .db-path{background:#f1f5f9;padding:10px;border-radius:6px;font-family:monospace;font-size:13px;word-break:break-all;}
    </style></head><body>";
    echo "<h1>✅ SQLite Database Created!</h1>";
    echo "<p>The <strong>hotel.db</strong> file has been created at:</p>";
    echo "<div class='db-path'>" . htmlspecialchars($db_path) . "</div>";
    echo "<p>Tables created:</p><ul>
            <li>hotel_users (admin / password123)</li>
            <li>hotel_guests</li>
            <li>hotel_rooms (3 seeded)</li>
            <li>hotel_bookings</li>
            <li>hotel_checkins</li>
            <li>hotel_payments</li>
            <li>hotel_inventory</li>
            <li>hotel_inventory_history</li>
            <li>hotel_login_history</li>
            <li>hotel_settings (seeded with defaults)</li>
          </ul>";
    echo "<p><strong>No MySQL/XAMPP MySQL service required.</strong> All data is stored in <code>hotel.db</code>.</p>";
    echo "<a href='index.php' class='btn'>Go to Dashboard &rarr;</a>";
    echo "</body></html>";

} catch (PDOException $e) {
    die("<b>Setup failed:</b> " . htmlspecialchars($e->getMessage()));
}
?>