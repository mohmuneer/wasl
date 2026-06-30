<?php
session_start();
include '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_GET['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$my_id = $_SESSION['user_id'];
$peer_id = intval($_GET['user_id']);
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

// جلب المحادثة - فقط الرسائل غير المحذوفة
$sql = "
    SELECT m.id, m.sender_id, m.receiver_id, m.message_text, m.message_type,
           m.file_path, m.file_name, m.file_size, m.is_read, m.created_at,
           m.edited_at, m.seen_at,
           u.full_name as sender_name, u.file_path as sender_file_path
    FROM messages m
    JOIN sys_users u ON m.sender_id = u.id
    WHERE m.deleted_at IS NULL
      AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
";

$params = [$my_id, $peer_id, $peer_id, $my_id];

if ($last_id > 0) {
    $sql .= " AND m.id > ?";
    $params[] = $last_id;
}

$sql .= " ORDER BY m.created_at ASC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تحديث الرسائل كمقروءة
$update = $pdo->prepare("
    UPDATE messages SET is_read = 1, seen_at = COALESCE(seen_at, NOW())
    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
");
$update->execute([$peer_id, $my_id]);

// تجهيز البيانات للإرجاع
$result = [];
foreach ($messages as $msg) {
    $result[] = [
        'id'           => (int)$msg['id'],
        'sender_id'    => (int)$msg['sender_id'],
        'receiver_id'  => (int)$msg['receiver_id'],
        'message_text' => $msg['message_text'],
        'message_type' => $msg['message_type'] ?? 'text',
        'file_path'    => $msg['file_path'],
        'file_name'    => $msg['file_name'],
        'file_size'    => $msg['file_size'] ? (int)$msg['file_size'] : null,
        'is_read'      => (int)$msg['is_read'],
        'created_at'   => $msg['created_at'],
        'edited_at'    => $msg['edited_at'],
        'seen_at'      => $msg['seen_at'],
        'sender_name'      => $msg['sender_name'],
        'sender_file_path'  => $msg['sender_file_path']
    ];
}

echo json_encode([
    'messages' => $result,
    'count'    => count($result)
]);
