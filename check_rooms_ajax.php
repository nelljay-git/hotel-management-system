<?php
require_once 'db.php';
header('Content-Type: application/json');

$check_in = $_GET['check_in_time'] ?? '';
$check_out = $_GET['check_out_time'] ?? '';
$exclude_booking_id = (int)($_GET['exclude_booking_id'] ?? 0);
$duration_type = $_GET['duration_type'] ?? '';

if (empty($check_in) || empty($check_out)) {
    echo json_encode(['error' => 'Missing check-in or check-out time.']);
    exit;
}

try {
    $in_date = new DateTime($check_in);
    $out_date = new DateTime($check_out);
    
    if ($out_date <= $in_date) {
        echo json_encode(['error' => 'Check-out time must be after check-in time.']);
        exit;
    }

    $in_str = $in_date->format('Y-m-d H:i:s');
    $out_str = $out_date->format('Y-m-d H:i:s');

    $rate_filter = "";
    if ($duration_type === '22 Hours') {
        $rate_filter = " AND r.price_3h IS NOT NULL ";
    } elseif ($duration_type === '1 Day') {
        $rate_filter = " AND r.price_6h IS NOT NULL ";
    } elseif ($duration_type === '2 Days') {
        $rate_filter = " AND r.price_12h IS NOT NULL ";
    }

    // Query for rooms that are explicitly Available and don't overlap with existing active/pending bookings
    $stmt = $pdo->prepare("
        SELECT r.* FROM hotel_rooms r
        WHERE r.status = 'Available'
          $rate_filter
          AND NOT EXISTS (
              SELECT 1 FROM hotel_bookings b
              WHERE b.room_id = r.id
                AND b.id != ?
                AND b.status != 'Cancelled'
                AND (
                    b.status = 'Pending'
                    OR (b.status = 'Actioned' AND EXISTS (
                        SELECT 1 FROM hotel_checkins c
                        WHERE c.booking_id = b.id AND c.status = 'Checked-in'
                    ))
                )
                AND b.check_in_time < ?
                AND b.check_out_time > ?
          )
        ORDER BY r.room_number ASC
    ");
    
    $stmt->execute([$exclude_booking_id, $out_str, $in_str]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rooms' => $rooms]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
