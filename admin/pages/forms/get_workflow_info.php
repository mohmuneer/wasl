<?php
/**
 * AJAX: إرجاع معلومات سياسة الاعتماد (القسم + الموظف) للتعبئة التلقائية
 * GET ?workflow_id=N
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require __DIR__ . '/../../../config/db.php';

$wf_id = (int)($_GET['workflow_id'] ?? 0);
if ($wf_id <= 0) {
    echo json_encode(['stages' => [], 'department' => '', 'employee' => '']);
    exit;
}

// جلب جميع مراحل السياسة مع بيانات الموظف والقسم
$stmt = $pdo->prepare("
    SELECT
        s.stage_order,
        s.stage_name,
        e.id          AS emp_id,
        e.full_name   AS emp_name,
        e.job_title   AS emp_title,
        e.department  AS emp_dept,
        e.signature_image AS emp_sig,
        d.department_name AS dept_name
    FROM " . TBL_APPROVAL_STAGES . " s
    LEFT JOIN " . TBL_EMPLOYEES . " e ON e.id = s.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE s.workflow_id = ? AND s.is_active = 1
    ORDER BY s.stage_order ASC
");
$stmt->execute([$wf_id]);
$stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// أول مرحلة تحدد القسم الافتراضي
$first   = $stages[0] ?? [];
$dept    = $first['dept_name'] ?: ($first['emp_dept'] ?? '');
$empName = $first['emp_name'] ?? '';
$empTitle= $first['emp_title'] ?? '';
$hasSig  = !empty($first['emp_sig']);

echo json_encode([
    'department'   => $dept,
    'employee'     => $empName,
    'job_title'    => $empTitle,
    'has_signature'=> $hasSig,
    'stages'       => array_map(fn($s) => [
        'order'     => $s['stage_order'],
        'name'      => $s['stage_name']  ?? '',
        'employee'  => $s['emp_name']    ?? '',
        'job_title' => $s['emp_title']   ?? '',
        'dept'      => $s['dept_name']   ?: ($s['emp_dept'] ?? ''),
        'has_sig'   => !empty($s['emp_sig']),
    ], $stages),
], JSON_UNESCAPED_UNICODE);
