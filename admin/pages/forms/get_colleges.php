<?php
require __DIR__ . "/../../../config/db.php";

if (isset($_GET['branch_id'])) {
    $branch_id = filter_var($_GET['branch_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        $stmt = $pdo->prepare("SELECT id, region_name FROM regions WHERE branch_id = ? ORDER BY region_name ASC");
        $stmt->execute([$branch_id]);
        $colleges = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($colleges);
    } catch (PDOException $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
