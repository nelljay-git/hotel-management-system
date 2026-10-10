<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$room_id = (int)($_POST['room_id'] ?? 0);
$new_status = trim($_POST['status'] ?? '');

if (!$room_id || empty($new_status)) {
    echo json_encode(['error' => 'Missing room_id or status']);
    exit;
}

// Non-admin users can only set Available or Maintenance
$allowed_statuses = ['Available', 'Maintenance'];
if ($_SESSION['role'] !== 'Admin') {
    if (!in_array($new_status, $allowed_statuses)) {
        echo json_encode(['error' => 'Unauthorized: You can only set Available or Maintenance.']);
        exit;
    }
}

// Don't allow setting a room to Available if it's currently Occupied (guest is checked in)
$currentStmt = $pdo->prepare("SELECT status FROM hotel_rooms WHERE id = ?");
$currentStmt->execute([$room_id]);
$currentStatus = $currentStmt->fetchColumn();

if ($currentStatus === false) {
    echo json_encode(['error' => 'Room not found']);
    exit;
}

if ($currentStatus === 'Occupied' && $new_status === 'Available') {
    // Check if there's still an active check-in
    $activeCheckin = $pdo->prepare("
        SELECT COUNT(*) FROM hotel_checkins c
        JOIN hotel_bookings b ON c.booking_id = b.id
        WHERE c.room_id = ? AND c.status = 'Checked-in'
    ");
    $activeCheckin->execute([$room_id]);
    if ($activeCheckin->fetchColumn() > 0) {
        echo json_encode(['error' => 'Cannot change status: Room has an active guest checked in.']);
        exit;
    }
}

$stmt = $pdo->prepare("UPDATE hotel_rooms SET status = ? WHERE id = ?");
$stmt->execute([$new_status, $room_id]);

echo json_encode([
    'success' => true,
    'room_id' => $room_id,
    'status' => $new_status
]);
