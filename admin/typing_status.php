<?php
session_start();
require __DIR__ . "/../config/db.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$peer_id = isset($_REQUEST['peer_id']) ? intval($_REQUEST['peer_id']) : 0;

if ($peer_id <= 0) {
    echo json_encode(['error' => 'Invalid peer']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // تحديث حالة الكتابة
    $is_typing = isset($_POST['is_typing']) ? intval($_POST['is_typing']) : 0;

    $stmt = $pdo->prepare("
        INSERT INTO typing_status (user_id, peer_id, is_typing, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE is_typing = VALUES(is_typing), updated_at = NOW()
    ");
    $stmt->execute([$user_id, $peer_id, $is_typing]);

    echo json_encode(['success' => true]);
} else {
    // جلب حالة الكتابة للطرف الآخر
    $stmt = $pdo->prepare("
        SELECT ts.is_typing, u.full_name
        FROM typing_status ts
        JOIN sys_users u ON ts.user_id = u.id
        WHERE ts.user_id = ? AND ts.peer_id = ?
          AND ts.updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
    ");
    $stmt->execute([$peer_id, $user_id]);
    $result = $stmt->fetch();

    if ($result) {
        echo json_encode([
            'is_typing' => (int)$result['is_typing'],
            'full_name' => $result['full_name']
        ]);
    } else {
        echo json_encode(['is_typing' => 0, 'full_name' => '']);
    }
}
