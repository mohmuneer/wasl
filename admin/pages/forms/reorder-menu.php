<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$order = json_decode($_POST['order'] ?? '[]', true);
if (empty($order) || !is_array($order)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order data']);
    exit;
}

try {
    $pdo->beginTransaction();

    $grouped = [];
    foreach ($order as $item) {
        $pid = (int)($item['parent_id'] ?? 0);
        $grouped[$pid][] = (int)($item['id'] ?? 0);
    }

    foreach ($grouped as $parent_id => $ids) {
        $sort = 1;
        $stmt = $pdo->prepare("UPDATE sys_menu SET sort_order = ? WHERE id = ? AND parent_id = ?");
        foreach ($ids as $mid) {
            if ($mid > 0) {
                $stmt->execute([$sort, $mid, $parent_id]);
                $sort++;
            }
        }
    }

    $pdo->commit();

    log_action($pdo, 'update', 'قائمة', 0, [], ['action' => 'reorder_menu', 'order' => $order]);

    echo json_encode(['success' => true, 'message' => 'تم إعادة الترتيب بنجاح']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
