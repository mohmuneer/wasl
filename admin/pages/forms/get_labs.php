<?php
require __DIR__ . "/../../../config/db.php";

if (isset($_GET['college_id'])) {
    $region_id = (int)$_GET['college_id'];

    // استعلام لجلب الأقسام التابعة للمنطقة المختارة
    $stmt = $pdo->prepare("SELECT MIN(id) AS id, department_name FROM departments WHERE region_id = ? GROUP BY department_name ORDER BY department_name ASC");
    $stmt->execute([$region_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // إرسال البيانات بتنسيق JSON
    header('Content-Type: application/json');
    echo json_encode($departments);
    exit;
}
