<?php
/**
 * next_checkin_ajax.php
 *
 * Given a calendar date (YYYY-MM-DD), returns the latest checkout time
 * among all active/pending bookings that end on or near that date,
 * plus a configurable buffer, so the UI can suggest an accurate
 * next-available check-in time instead of a hard-coded 2:00 PM.
 *
 * GET params:
 *   date       – selected date (YYYY-MM-DD)
 *   room_id    – (optional) restrict to a specific room
 *   buffer_min – (optional) buffer in minutes after checkout (default: 30)
 *
 * Returns JSON:
 *   {
 *     suggested_checkin : "2026-06-09T10:05",   // ISO datetime-local string (local +08)
 *     latest_checkout   : "2026-06-09T10:00",   // latest checkout found (null if none)
 *     has_prior_booking : true/false,
 *     buffer_min        : 30
 *   }
 */

require_once 'db.php';
header('Content-Type: application/json');

$date       = $_GET['date']       ?? '';
$room_id    = (int)($_GET['room_id']    ?? 0);

// Fetch buffer_time from settings
$buffer_min = 30; // default
try {
    $stmt_buf = $pdo->query("SELECT setting_value FROM hotel_settings WHERE setting_key = 'booking_buffer_time'");
    if ($row_buf = $stmt_buf->fetch(PDO::FETCH_ASSOC)) {
        $buffer_min = max(0, (int)$row_buf['setting_value']);
    }
} catch (Exception $e) {}

// Still allow GET override if passed
$buffer_min = max(0, (int)($_GET['buffer_min'] ?? $buffer_min));

if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Invalid or missing date parameter.']);
    exit;
}

try {
    // Look at the selected date AND the day before (a booking that started
    // yesterday might not check out until today).
    $search_from = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') - 86400);
    $search_to   = date('Y-m-d H:i:s', strtotime($date . ' 23:59:59'));

    // Build room restriction
    $room_clause = $room_id > 0 ? ' AND b.room_id = :room_id ' : '';

    $sql = "
        SELECT MAX(b.check_out_time) AS latest_checkout
        FROM hotel_bookings b
        WHERE b.status != 'Cancelled'
          AND (
              b.status = 'Pending'
              OR (b.status = 'Actioned' AND EXISTS (
                  SELECT 1 FROM hotel_checkins c
                  WHERE c.booking_id = b.id AND c.status = 'Checked-in'
              ))
          )
          AND b.check_out_time > :search_from
          AND b.check_out_time <= :search_to
          $room_clause
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':search_from', $search_from);
    $stmt->bindValue(':search_to',   $search_to);
    if ($room_id > 0) {
        $stmt->bindValue(':room_id', $room_id);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $latest_checkout  = $row['latest_checkout'] ?? null;
    $has_prior        = !empty($latest_checkout);

    $current_time = time();
    $current_date = date('Y-m-d', $current_time);

    if ($has_prior) {
        // Add buffer on top of the latest checkout
        $suggested_ts = strtotime($latest_checkout) + ($buffer_min * 60);
    } else {
        // No bookings ending on this date — default to 10:00 AM (sensible hotel start)
        $suggested_ts = strtotime($date . ' 10:00:00');
    }

    // If the selected date is today, and the suggested time is in the past, suggest current time instead.
    if ($date === $current_date && $suggested_ts < $current_time) {
        $suggested_ts = $current_time;
    }

    // Format for <input type="datetime-local"> (YYYY-MM-DDTHH:MM)
    $suggested_checkin = date('Y-m-d\TH:i', $suggested_ts);
    $latest_checkout_fmt = $latest_checkout
        ? date('Y-m-d\TH:i', strtotime($latest_checkout))
        : null;

    echo json_encode([
        'suggested_checkin'  => $suggested_checkin,
        'latest_checkout'    => $latest_checkout_fmt,
        'has_prior_booking'  => $has_prior,
        'buffer_min'         => $buffer_min,
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
