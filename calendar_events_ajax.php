<?php
require_once "db.php";
header("Content-Type: application/json");

$start = $_GET["start"] ?? null;
$end = $_GET["end"] ?? null;

$query = "
    SELECT b.*, 
           g.name as guest_name, 
           g.contact as guest_contact,
           r.room_number,
           r.status as room_status
    FROM hotel_bookings b
    JOIN hotel_guests g ON b.guest_id = g.id
    LEFT JOIN hotel_rooms r ON b.room_id = r.id
    WHERE b.status != 'Cancelled'
";
$params = [];

if ($start && $end) {
    $query .= " AND b.check_in_time < ? AND b.check_out_time > ?";
    $params[] = $end;
    $params[] = $start;
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();

    $events = [];
    foreach ($bookings as $b) {
        $chkStmt = $pdo->prepare("SELECT id, status FROM hotel_checkins WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        $chkStmt->execute([$b["id"]]);
        $chk = $chkStmt->fetch();
        
        $checkin_status = $chk ? $chk["status"] : "None";
        $checkin_id = $chk ? $chk["id"] : null;

        $color = "#f59e0b"; // Pending
        if ($b["status"] == "Actioned") {
            if ($checkin_status == "Checked-in") {
                $color = "#10b981"; // Success / Green
            } else if ($checkin_status == "Checked-out") {
                $color = "#94a3b8"; // Gray
            } else {
                $color = "#3b82f6"; // Blue
            }
        }
        
        $title = ($b["room_number"] ? "Rm " . $b["room_number"] : $b["room_type"]) . " - " . $b["guest_name"];
        
        $events[] = [
            "id" => $b["id"],
            "title" => $title,
            "start" => $b["check_in_time"],
            "end" => $b["check_out_time"],
            "color" => $color,
            "extendedProps" => [
                "booking_id" => $b["id"],
                "guest_name" => $b["guest_name"],
                "room_number" => $b["room_number"] ? "Room " . $b["room_number"] : "Unassigned",
                "room_type" => $b["room_type"],
                "check_in_time" => date("M d, Y h:i A", strtotime($b["check_in_time"])),
                "check_out_time" => date("M d, Y h:i A", strtotime($b["check_out_time"])),
                "num_guests" => $b["num_guests"],
                "status" => $b["status"],
                "checkin_status" => $checkin_status,
                "checkin_id" => $checkin_id,
                "room_status" => $b["room_status"] ?? "Available"
            ]
        ];
    }
    
    echo json_encode($events);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>
