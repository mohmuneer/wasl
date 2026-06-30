<?php
session_start();
require __DIR__ . "/../config/db.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id    = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
$new_text   = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($message_id <= 0 || empty($new_text)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

// التحقق من الملكية
$stmt = $pdo->prepare("SELECT sender_id, message_type FROM messages WHERE id = ?");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();

if (!$msg || $msg['sender_id'] != $user_id) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح بتعديل هذه الرسالة']);
    exit();
}

if ($msg['message_type'] !== 'text') {
    echo json_encode(['success' => false, 'error' => 'يمكن تعديل الرسائل النصية فقط']);
    exit();
}

$stmt = $pdo->prepare("UPDATE messages SET message_text = ?, edited_at = NOW() WHERE id = ? AND sender_id = ?");
$stmt->execute([$new_text, $message_id, $user_id]);

echo json_encode(['success' => true]);
