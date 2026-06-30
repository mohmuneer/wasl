<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['total' => 0]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if a specific user_id is requested (for the contact page)
$specific_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

header('Content-Type: application/json; charset=utf-8');

try {
    if ($specific_user > 0) {
        // Count unread from a specific user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0 AND deleted_at IS NULL");
        $stmt->execute([$specific_user, $user_id]);
        echo json_encode(['count' => (int) $stmt->fetchColumn()]);
    } else {
        // Total unread
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0 AND deleted_at IS NULL");
        $stmt->execute([$user_id]);
        echo json_encode(['total' => (int) $stmt->fetchColumn()]);
    }
} catch (PDOException $e) {
    echo json_encode(['total' => 0, 'count' => 0]);
}
