<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../config/db.php";

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    echo (int)$stmt->fetchColumn();
} else {
    echo 0;
}
