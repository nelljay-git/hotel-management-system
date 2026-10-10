<?php
try {
    $db_path = __DIR__ . '/hotel.db';
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->beginTransaction();

    // Disable Foreign Key checks during the schema modification
    $pdo->exec('PRAGMA foreign_keys = OFF');

    // Check if capacity column already exists
    $stmt = $pdo->query("PRAGMA table_info(hotel_rooms)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    
    if (in_array('capacity', $cols)) {
        echo "Database already has capacity column. Skipping schema migration.\n";
    } else {
        // Create hotel_rooms_new
        $pdo->exec("
            CREATE TABLE hotel_rooms_new (
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

        // Copy and map existing data
        $pdo->exec("
            INSERT INTO hotel_rooms_new (id, room_number, room_type, price_3h, price_6h, price_12h, price_24h, status, capacity)
            SELECT id, room_number, room_type,
                   CASE WHEN price_3h <= 0 THEN NULL ELSE price_3h END,
                   CASE WHEN price_6h <= 0 THEN NULL ELSE price_6h END,
                   CASE WHEN price_12h <= 0 THEN NULL ELSE price_12h END,
                   price_24h, status,
                   CASE 
                       WHEN room_type = 'Single' THEN 1
                       WHEN room_type = 'Double' THEN 2
                       WHEN room_type = 'Deluxe' THEN 4
                       ELSE 2
                   END
            FROM hotel_rooms
        ");

        // Create hotel_bookings_new
        $pdo->exec("
            CREATE TABLE hotel_bookings_new (
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

        // Copy existing bookings
        $pdo->exec("
            INSERT INTO hotel_bookings_new (id, guest_id, room_id, check_in_time, check_out_time, room_type, num_guests, duration_type, base_price, booking_type, status, is_paid, extra_charges, extension_hours)
            SELECT id, guest_id, room_id, check_in_time, check_out_time, room_type, num_guests, duration_type, base_price, booking_type, status, is_paid, extra_charges, extension_hours
            FROM hotel_bookings
        ");

        // Drop old tables
        $pdo->exec("DROP TABLE hotel_rooms");
        $pdo->exec("DROP TABLE hotel_bookings");

        // Rename new tables
        $pdo->exec("ALTER TABLE hotel_rooms_new RENAME TO hotel_rooms");
        $pdo->exec("ALTER TABLE hotel_bookings_new RENAME TO hotel_bookings");

        echo "Migration logic executed successfully.\n";
    }

    // Re-enable Foreign Key constraints
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->commit();
    echo "SUCCESS: Database migrated successfully.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: Migration failed: " . $e->getMessage() . "\n";
}
