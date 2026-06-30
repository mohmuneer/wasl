<?php
session_start();
require __DIR__ . "/../config/db.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$my_id  = $_SESSION['user_id'];
$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$peer_id = isset($_GET['peer_id']) ? intval($_GET['peer_id']) : 0;

if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit();
}

$params = [];
$sql = "
    SELECT m.id, m.sender_id, m.receiver_id, m.message_text, m.created_at,
           u.full_name as sender_name
    FROM messages m
    JOIN sys_users u ON m.sender_id = u.id
    WHERE m.deleted_at IS NULL
      AND m.message_text LIKE ?
      AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
    ORDER BY m.created_at DESC
    LIMIT 30
";

$params[] = '%' . $q . '%';
$params[] = $my_id;
$params[] = $peer_id;
$params[] = $peer_id;
$params[] = $my_id;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['results' => $results]);
