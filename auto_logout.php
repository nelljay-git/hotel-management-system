<?php
// Auto-logout endpoint: records logout_time when the browser tab/window is closed.
// Called via navigator.sendBeacon() — does NOT destroy the session, just timestamps it.
session_start();
require_once 'db.php';

if (isset($_SESSION['login_history_id'])) {
    $pdo->prepare("UPDATE hotel_login_history SET logout_time = ? WHERE id = ? AND logout_time IS NULL")
        ->execute([date('Y-m-d H:i:s'), $_SESSION['login_history_id']]);
}

http_response_code(200);
?>
