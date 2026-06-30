<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
if(isset($_POST['id'])) {
    $stmt = $pdo->prepare("UPDATE clients SET status = ? WHERE id = ?");
    $result = $stmt->execute([$_POST['status'], $_POST['id']]);
    log_action($pdo, 'update', 'employee', (int)$_POST['id'], [], ['status' => $_POST['status']]);
    echo json_encode(['success' => $result]);
}