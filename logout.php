<?php
require_once 'db.php';

if (isset($_SESSION['login_history_id'])) {
    $pdo->prepare("UPDATE hotel_login_history SET logout_time = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $_SESSION['login_history_id']]);
}

session_unset();
session_destroy();
header("Location: login.php");
exit;
?>