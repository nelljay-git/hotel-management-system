<?php
require_once 'db.php';

try {
    $pdo->exec("PRAGMA foreign_keys = OFF");
    $pdo->beginTransaction();

    // Check current schema
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='hotel_bookings'")->fetchColumn();
    if (strpos($sql, "'Custom'") !== false) {
        echo "Migration already applied. hotel_bookings schema supports 'Custom'.\n";
        $pdo->rollBack();
        exit;
    }

    // Rename old table
    $pdo->exec("ALTER TABLE hotel_bookings RENAME TO hotel_bookings_old");

    // Create new table with updated CHECK constraint for duration_type
    $pdo->exec("
        CREATE TABLE hotel_bookings (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            guest_id       INTEGER NOT NULL,
            room_id        INTEGER,
            check_in_time  TEXT NOT NULL,
            check_out_time TEXT NOT NULL,
            room_type      TEXT NOT NULL CHECK(room_type IN ('Single','Double','Deluxe')),
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

    // Copy data from old table to new table
    $pdo->exec("
        INSERT INTO hotel_bookings (
            id, guest_id, room_id, check_in_time, check_out_time, room_type, 
            num_guests, duration_type, base_price, booking_type, status, 
            is_paid, extra_charges, extension_hours
        )
        SELECT 
            id, guest_id, room_id, check_in_time, check_out_time, room_type, 
            num_guests, duration_type, base_price, booking_type, status, 
            is_paid, extra_charges, extension_hours
        FROM hotel_bookings_old
    ");

    // Drop old table
    $pdo->exec("DROP TABLE hotel_bookings_old");

    $pdo->commit();
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo "Successfully migrated hotel_bookings table. Added 'Custom' to duration_type CHECK constraint.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("PRAGMA foreign_keys = ON");
    die("Database migration failed: " . $e->getMessage() . "\n");
}
?>
