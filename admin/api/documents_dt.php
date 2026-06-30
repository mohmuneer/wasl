<?php
/**
 * documents_dt.php — Server-Side DataTables API لوثائق DMS
 * يُعيد JSON متوافقاً مع بروتوكول DataTables serverSide
 * يدعم: بحث · ترتيب · تصفية حسب النوع/التصنيف/القسم/الحالة · صلاحيات
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ── أمان ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . "/../../config/db.php";

$uid = (int)$_SESSION['user_id'];

// ── الصلاحيات ─────────────────────────────────────────────────────
$permStmt = $pdo->prepare("
    SELECT uma.can_view, uma.can_add, uma.can_edit, uma.can_delete,
           uma.can_approve, uma.can_archive, uma.can_view_archive
    FROM user_menu_access uma
    JOIN sys_menu m ON uma.menu_id = m.id
    WHERE uma.user_id = ? AND m.link = 'pages/tables/show-documents.php'
    LIMIT 1
");
$permStmt->execute([$uid]);
$perm = $permStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($perm['can_view'])) {
    echo json_encode(['draw'=>0,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>'No permission']);
    exit;
}

// ── معاملات DataTables ────────────────────────────────────────────
$draw    = (int)($_REQUEST['draw']           ?? 1);
$start   = (int)($_REQUEST['start']          ?? 0);
$length  = max(1, min(200, (int)($_REQUEST['length'] ?? 25)));
$search  = trim($_REQUEST['search']['value'] ?? '');
$orderCol= (int)($_REQUEST['order'][0]['column'] ?? 0);
$orderDir= strtolower($_REQUEST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// ── فلاتر مخصصة ──────────────────────────────────────────────────
$fType   = (int)($_REQUEST['type_id']    ?? 0);
$fCat    = (int)($_REQUEST['category_id']?? 0);
$fDept   = trim($_REQUEST['department']  ?? '');
$fStatus = trim($_REQUEST['status']      ?? '');

// ── أعمدة الترتيب ─────────────────────────────────────────────────
// تطابق تسلسل <th> في HTML:
// 0:#  1:doc_number  2:title  3:type  4:category  5:department
// 6:approver_dept  7:approver_name  8:format  9:file_size  10:status  11:created_at  12:actions
$orderColMap = [
    0 => 'd.id',
    1 => 'd.doc_number',
    2 => 'd.title',
    3 => 't.name',
    4 => 'c.name',
    5 => 'd.department',
    8 => 'd.file_format',
    9 => 'd.file_size',
    10=> 'd.status',
    11=> 'd.created_at',
];
$orderSql = $orderColMap[$orderCol] ?? 'd.id';

// ── البنية الأساسية للاستعلام ─────────────────────────────────────
$baseSql = "
    FROM " . TBL_DOCUMENTS . " d
    LEFT JOIN " . TBL_DOC_TYPES . " t       ON d.type_id = t.id
    LEFT JOIN " . TBL_DOC_CATEGORIES . " c  ON d.category_id = c.id
    LEFT JOIN " . TBL_DOC_APPROVALS . " da  ON da.document_id = d.id
    LEFT JOIN " . TBL_EMPLOYEES . " e        ON e.id = da.employee_id
";

// ── شروط WHERE ────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($fType) {
    $where[]  = 'd.type_id = ?';
    $params[] = $fType;
}
if ($fCat) {
    $where[]  = 'd.category_id = ?';
    $params[] = $fCat;
}
if ($fDept !== '') {
    $where[]  = 'd.department = ?';
    $params[] = $fDept;
}
if ($fStatus !== '') {
    $where[]  = 'd.status = ?';
    $params[] = $fStatus;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(d.doc_number LIKE ? OR d.title LIKE ? OR t.name LIKE ? OR c.name LIKE ? OR d.department LIKE ? OR e.full_name LIKE ?)';
    $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── عدد الكل ──────────────────────────────────────────────────────
$totalStmt = $pdo->query("SELECT COUNT(DISTINCT d.id) $baseSql");
$recordsTotal = (int)$totalStmt->fetchColumn();

// ── عدد بعد الفلترة ───────────────────────────────────────────────
$filteredSql = "SELECT COUNT(DISTINCT d.id) $baseSql $whereSql";
$filteredStmt = $pdo->prepare($filteredSql);
$filteredStmt->execute($params);
$recordsFiltered = (int)$filteredStmt->fetchColumn();

// ── الاستعلام الرئيسي ─────────────────────────────────────────────
$dataSql = "
    SELECT d.id, d.doc_number, d.title, d.file_format, d.file_size,
           d.status, d.department, d.created_at, d.file_path, d.workflow_id,
           t.name AS type_name,
           c.name AS category_name,
           GROUP_CONCAT(DISTINCT e.full_name   ORDER BY da.id SEPARATOR '، ') AS approver_names,
           GROUP_CONCAT(DISTINCT e.department  ORDER BY da.id SEPARATOR '، ') AS approver_depts
    $baseSql
    $whereSql
    GROUP BY d.id
    ORDER BY $orderSql $orderDir
    LIMIT ? OFFSET ?
";
$dataStmt = $pdo->prepare($dataSql);
$dataStmt->execute(array_merge($params, [$length, $start]));
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// ── دوال مساعدة ──────────────────────────────────────────────────
function dtStatusBadge(string $status): string {
    $map = [
        'draft'     => ['secondary', 'مسودة'],
        'approved'  => ['success',   'معتمدة'],
        'archived'  => ['info',      'مؤرشفة'],
        'cancelled' => ['danger',    'ملغاة'],
    ];
    [$cls,$lbl] = $map[$status] ?? ['secondary',$status];
    return "<span class=\"badge badge-$cls\">$lbl</span>";
}

function dtFormatBadge(string $fmt): string {
    $fmt = strtolower($fmt);
    $map = ['pdf'=>'danger','doc'=>'primary','docx'=>'primary','xls'=>'success','xlsx'=>'success','jpg'=>'warning','png'=>'warning'];
    $cls = $map[$fmt] ?? 'secondary';
    return "<span class=\"badge badge-$cls\">" . strtoupper($fmt) . "</span>";
}

function dtFileSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824,1).' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576,1).' MB';
    if ($bytes >= 1024)       return round($bytes/1024,1).' KB';
    return $bytes.' B';
}

// ── بناء مصفوفة البيانات ──────────────────────────────────────────
$data = [];
$i = $start + 1;

foreach ($rows as $d) {
    // أزرار الإجراءات (تُولَّد حسب الصلاحيات)
    $actions = '<div class="d-flex justify-content-center gap-1" style="gap:4px">';

    // عرض
    $actions .= '<a href="../forms/view-document.php?id=' . $d['id'] . '" class="btn btn-sm btn-info" title="عرض" style="border-radius:6px"><i class="fas fa-eye"></i></a>';

    // تحميل (إن وُجد)
    if (!empty($d['file_path'])) {
        $ext = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $actions .= '<a href="../../../' . htmlspecialchars($d['file_path']) . '" target="_blank" class="btn btn-sm btn-danger" title="PDF" style="border-radius:6px"><i class="fas fa-file-pdf"></i></a>';
        }
    }

    // تعديل
    if (!empty($perm['can_edit']) && $d['status'] === 'draft') {
        $actions .= '<a href="../forms/edit-document.php?id=' . $d['id'] . '" class="btn btn-sm btn-warning" title="تعديل" style="border-radius:6px"><i class="fas fa-edit"></i></a>';
    }

    // اعتماد
    if (!empty($perm['can_approve']) && $d['status'] === 'draft') {
        $actions .= '<a href="show-documents.php?status_id=' . $d['id'] . '&new_status=approved" class="btn btn-sm btn-success approve-btn" data-id="' . $d['id'] . '" title="اعتماد" style="border-radius:6px"><i class="fas fa-check"></i></a>';
    }

    // أرشفة
    if (!empty($perm['can_archive']) && $d['status'] === 'approved') {
        $actions .= '<button class="btn btn-sm btn-secondary archive-btn" data-id="' . $d['id'] . '" data-title="' . htmlspecialchars($d['title'], ENT_QUOTES) . '" title="أرشفة" style="border-radius:6px"><i class="fas fa-archive"></i></button>';
    }

    // حذف
    if (!empty($perm['can_delete'])) {
        $actions .= '<button class="btn btn-sm btn-danger delete-doc-btn" data-id="' . $d['id'] . '" title="حذف" style="border-radius:6px"><i class="fas fa-trash"></i></button>';
    }

    $actions .= '</div>';

    $data[] = [
        $i++,
        '<span style="font-family:monospace;font-size:.75rem">' . htmlspecialchars($d['doc_number'] ?? '—') . '</span>',
        '<div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:right" title="' . htmlspecialchars($d['title']) . '">' . htmlspecialchars($d['title']) . '</div>',
        htmlspecialchars($d['type_name']     ?? '—'),
        htmlspecialchars($d['category_name'] ?? '—'),
        htmlspecialchars($d['department']    ?? '—'),
        htmlspecialchars($d['approver_depts']?? '—'),
        htmlspecialchars($d['approver_names']?? '—'),
        dtFormatBadge($d['file_format'] ?? ''),
        dtFileSize((int)($d['file_size'] ?? 0)),
        dtStatusBadge($d['status']),
        '<small>' . date('Y-m-d', strtotime($d['created_at'])) . '</small>',
        $actions,
    ];
}

// ── الاستجابة ─────────────────────────────────────────────────────
echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE);
