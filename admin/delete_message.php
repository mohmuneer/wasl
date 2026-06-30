<?php
session_start();
require __DIR__ . "/../config/db.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id   = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;

if ($message_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid message']);
    exit();
}

// التحقق من أن المستخدم هو مالك الرسالة
$stmt = $pdo->prepare("SELECT sender_id, file_path FROM messages WHERE id = ?");
$stmt->execute([$message_id]);
$msg = $stmt->fetch();

if (!$msg || $msg['sender_id'] != $user_id) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح بحذف هذه الرسالة']);
    exit();
}

// حذف الملف المرفق إن وجد
if ($msg['file_path']) {
    $file_path = __DIR__ . '/../uploads/chat/' . $msg['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}

// Soft delete - تعيين deleted_at
$stmt = $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE id = ? AND sender_id = ?");
$stmt->execute([$message_id, $user_id]);

echo json_encode(['success' => true]);
