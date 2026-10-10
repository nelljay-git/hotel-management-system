<?php
require_once 'db.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$stmt = $pdo->prepare("
    SELECT MIN(id) as id, name, MAX(contact) as contact 
    FROM hotel_guests 
    WHERE name LIKE ? OR contact LIKE ?
    GROUP BY name
    ORDER BY name ASC
    LIMIT 8
");
$stmt->execute(['%' . $q . '%', '%' . $q . '%']);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
