<?php
// ── نقطة AJAX للبحث عن البلاغات المعلقة ──
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

require __DIR__ . "/../../../config/db.php";

header('Content-Type: application/json; charset=UTF-8');

$q     = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 15), 50); // حد أقصى 50

// لا بحث إذا كان الاستعلام فارغاً
if ($q === '') {
    // إرجاع آخر 10 بلاغات فقط عند الفتح
    $stmt = $pdo->prepare("
        SELECT r.id, r.reporter_ref, r.details, r.priority, r.created_at,
               b.branch_name, reg.region_name, cat.category_name
        FROM tickets r
        LEFT JOIN branches b ON r.branch_id = b.id
        LEFT JOIN regions reg ON r.region_id = reg.id
        LEFT JOIN issue_categories cat ON r.category_id = cat.id
        WHERE r.status = 'Pending'
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
} else {
    // بحث بالرقم أو الاسم أو الفرع أو التفاصيل
    $like = '%' . $q . '%';
    $isNum = is_numeric($q);

    $stmt = $pdo->prepare("
        SELECT r.id, r.reporter_ref, r.details, r.priority, r.created_at,
               b.branch_name, reg.region_name, cat.category_name
        FROM tickets r
        LEFT JOIN branches b ON r.branch_id = b.id
        LEFT JOIN regions reg ON r.region_id = reg.id
        LEFT JOIN issue_categories cat ON r.category_id = cat.id
        WHERE r.status = 'Pending'
          AND (
              " . ($isNum ? "r.id = :id OR" : "") . "
              r.reporter_ref LIKE :like
              OR b.branch_name    LIKE :like2
              OR reg.region_name  LIKE :like3
              OR cat.category_name LIKE :like4
              OR r.details        LIKE :like5
          )
        ORDER BY r.created_at DESC
        LIMIT :lim
    ");

    if ($isNum) $stmt->bindValue(':id', (int)$q, PDO::PARAM_INT);
    $stmt->bindValue(':like',  $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':like4', $like);
    $stmt->bindValue(':like5', $like);
    $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
    $stmt->execute();
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pmap = [
    'Urgent' => ['طارئ',  'fee2e2','dc2626'],
    'High'   => ['عاجل',  'fef3c7','d97706'],
    'Medium' => ['متوسط', 'dbeafe','2563eb'],
    'Low'    => ['عادي',  'f1f5f9','64748b'],
];

$result = [];
foreach ($rows as $r) {
    $pb = $pmap[$r['priority']] ?? ['عادي','f1f5f9','64748b'];
    $result[] = [
        'id'          => $r['id'],
        'reporter'    => $r['reporter_ref'] ?? '—',
        'branch'      => $r['branch_name']  ?? '—',
        'region'      => $r['region_name']  ?? '',
        'category'    => $r['category_name'] ?? 'عام',
        'priority'    => $pb[0],
        'p_bg'        => '#'.$pb[1],
        'p_color'     => '#'.$pb[2],
        'details'     => mb_substr($r['details'] ?? '', 0, 80),
        'date'        => date('Y/m/d', strtotime($r['created_at'])),
        'label'       => '#'.$r['id'].' — '.($r['branch_name']??'').' — '.mb_strimwidth($r['details']??'',0,40,'...'),
    ];
}

echo json_encode(['total' => count($result), 'items' => $result]);
