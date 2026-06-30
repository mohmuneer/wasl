<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-requests.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$can_add = $can_edit = $can_delete = 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $p = $accStmt->fetch(PDO::FETCH_ASSOC);
    $can_add    = $p['can_add']    ?? 0;
    $can_edit   = $p['can_edit']   ?? 0;
    $can_delete = $p['can_delete'] ?? 0;
}

// جلب اسم المستخدم وإعدادات النظام (للطباعة)
$current_user_name = $pdo->prepare("SELECT full_name FROM sys_users WHERE id=?");
$current_user_name->execute([$current_user_id]);
$current_user_name = $current_user_name->fetchColumn() ?: 'مستخدم';

$settings      = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$company_name  = $settings['system_name'] ?? 'النظام';
$company_logo  = $settings['system_logo'] ?? 'logo.png';

$logo_data_uri = '';
$logo_path     = __DIR__ . '/../../dist/img/' . $company_logo;
if (file_exists($logo_path)) {
    $logo_data_uri = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

// ── جلب البيانات ──
$sql = "SELECT r.id AS request_id, r.reporter_ref, r.location_name, r.details,
               r.status, r.priority, r.created_at,
               t.id AS task_id, t.deadline,
               b.branch_name, c.region_name, g.category_name,
               u.full_name AS technician_name
        FROM tickets r
        LEFT JOIN work_orders t       ON r.id = t.ticket_id
        LEFT JOIN issue_categories g  ON r.category_id = g.id
        LEFT JOIN branches b          ON r.branch_id = b.id
        LEFT JOIN regions c           ON r.region_id  = c.id
        LEFT JOIN sys_users u         ON t.assigned_to = u.id
        WHERE r.category_id IN (SELECT category_id FROM user_category_access WHERE user_id = ?)
        ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$current_user_id]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── جلب التصنيفات للفلتر ──
$categories = $pdo->prepare("SELECT id,category_name FROM issue_categories WHERE id IN (SELECT category_id FROM user_category_access WHERE user_id=?) ORDER BY category_name");
$categories->execute([$current_user_id]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

// ── معالجة الحذف ──
if (isset($_GET['delete_id']) && $can_delete) {
    $did = (int)$_GET['delete_id'];
    $hasTask = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE ticket_id=?");
    $hasTask->execute([$did]);
    if ($hasTask->fetchColumn() > 0) {
        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'error',title:'فشل الحذف',text:'لا يمكن حذف بلاغ مرتبط بمهمة نشطة'}));window.location.href='show-requests.php';</script>";
    } else {
        $pdo->prepare("DELETE FROM tickets WHERE id=?")->execute([$did]);
        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الحذف',text:'تم حذف البلاغ بنجاح'}));window.location.href='show-requests.php';</script>";
    }
    exit;
}

// ── إحصاءات ──
$total    = count($tasks);
$pending  = count(array_filter($tasks, fn($t) => $t['status'] === 'Pending'));
$progress = count(array_filter($tasks, fn($t) => $t['status'] === 'In Progress'));
$resolved = count(array_filter($tasks, fn($t) => in_array($t['status'], ['Resolved','Closed'])));

// ── خرائط التحويل ──
$pmap = [
    'Urgent' => ['طارئ',    'p-urgent'],
    'High'   => ['عاجل',    'p-high'],
    'Medium' => ['متوسط',   'p-medium'],
    'Low'    => ['عادي',    'p-low'],
];
$smap = [
    'Pending'     => ['قيد الانتظار', 's-pending'],
    'In Progress' => ['قيد التنفيذ',  's-progress'],
    'Resolved'    => ['تم الإنجاز',   's-resolved'],
    'Closed'      => ['مغلق',         's-resolved'],
    'Cancelled'   => ['ملغي',         's-cancel'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة البلاغات</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<!-- jQuery في الرأس ضروري لأن main-header.php يستدعي $ فوراً -->
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ══ إحصائيات ══ */
.task-stat {
    background:#fff;border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    padding:16px 18px;border:1px solid #f0f2f7;
    display:flex;align-items:center;gap:12px;
}
.task-stat .ts-icon {
    width:44px;height:44px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.1rem;color:#fff;flex-shrink:0;
}
.task-stat .ts-val {font-size:1.5rem;font-weight:800;line-height:1;}
.task-stat .ts-lbl {font-size:.74rem;color:#888;margin-top:2px;}

/* ══ بطاقة الجدول ══ */
.tasks-card {
    background:#fff;border-radius:14px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    overflow:hidden;border:1px solid #f0f2f7;
}
.tasks-card-head {
    padding:14px 20px;
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:10px;border-bottom:1px solid #f0f2f7;
}
.tasks-card-head h5 {margin:0;font-weight:700;font-size:.95rem;color:#334155;}

/* ══ فلاتر ══ */
.task-filters {
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
    padding:12px 20px;background:#fafbfc;border-bottom:1px solid #f0f2f7;
}
.task-filters select {
    border:1.5px solid #e2e8f0;border-radius:8px;
    padding:5px 10px;font-size:.8rem;color:#475569;
    background:#fff;cursor:pointer;transition:.2s;
}
.task-filters select:focus {border-color:var(--crm-primary,#1a5276);outline:none;}
.task-filters select.active-filter {
    border-color:var(--crm-primary,#1a5276);
    background:#eff6ff;color:#1d4ed8;font-weight:700;
}
.task-filters label {font-size:.78rem;font-weight:700;color:#64748b;margin-bottom:0;}
.filter-count {
    background:#1a5276;color:#fff;
    border-radius:20px;font-size:.68rem;font-weight:700;
    padding:1px 8px;margin-right:4px;display:none;
}
.filter-count.show {display:inline;}

/* ══ جدول ══ */
#requestsTable {border-collapse:separate;border-spacing:0;}
#requestsTable thead th {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9)) !important;
    color:#fff !important;border:none !important;
    white-space:nowrap;vertical-align:middle;
    font-size:.78rem;font-weight:700;padding:11px 10px;text-align:center;
}
#requestsTable tbody td {
    vertical-align:middle;text-align:center;
    font-size:.8rem;padding:10px 8px;
    border-top:1px solid #f0f4f8 !important;
    border-left:none !important;border-right:none !important;border-bottom:none !important;
}
#requestsTable tbody tr:hover {background:#f8fafc;}
#requestsTable tbody tr:first-child td {border-top:none !important;}

/* ══ شارات ══ */
.badge-pill-custom {padding:4px 12px;border-radius:20px;font-size:.72rem;font-weight:700;}
.p-urgent  {background:#fee2e2;color:#dc2626;}
.p-high    {background:#fef3c7;color:#d97706;}
.p-medium  {background:#dbeafe;color:#2563eb;}
.p-low     {background:#f1f5f9;color:#64748b;}
.s-pending  {background:#f1f5f9;color:#475569;}
.s-progress {background:#dbeafe;color:#1d4ed8;}
.s-resolved {background:#d1fae5;color:#065f46;}
.s-cancel   {background:#fee2e2;color:#dc2626;}

/* ══ أزرار ══ */
.btn-act {
    width:30px;height:30px;padding:0;border-radius:7px;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:.75rem;transition:.15s;border:none;cursor:pointer;
}
.btn-act:hover{transform:scale(1.05);}

/* ══ حقل التفاصيل ══ */
.details-cell {text-align:right !important;}
.details-cell .ref-chip {
    display:inline-flex;align-items:center;gap:4px;
    font-size:.72rem;color:#64748b;margin-bottom:3px;
}
.details-cell .details-text {
    font-size:.78rem;color:#475569;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;
    overflow:hidden;max-width:220px;
}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

    <!-- ── الترويسة ── -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-clipboard-list ml-2"></i>إدارة البلاغات الفنية</h4>
                    <small>متابعة وتتبع بلاغات الصيانة والدعم الفني</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">البلاغات</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- ── إحصاءات ── -->
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3">
                <div class="task-stat">
                    <div class="ts-icon" style="background:linear-gradient(135deg,#1a5276,#2980b9)"><i class="fas fa-clipboard-list"></i></div>
                    <div><div class="ts-val"><?= $total ?></div><div class="ts-lbl">إجمالي البلاغات</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="task-stat">
                    <div class="ts-icon" style="background:linear-gradient(135deg,#475569,#64748b)"><i class="fas fa-clock"></i></div>
                    <div><div class="ts-val"><?= $pending ?></div><div class="ts-lbl">قيد الانتظار</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="task-stat">
                    <div class="ts-icon" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)"><i class="fas fa-spinner"></i></div>
                    <div><div class="ts-val"><?= $progress ?></div><div class="ts-lbl">قيد التنفيذ</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="task-stat">
                    <div class="ts-icon" style="background:linear-gradient(135deg,#065f46,#059669)"><i class="fas fa-check-circle"></i></div>
                    <div><div class="ts-val"><?= $resolved ?></div><div class="ts-lbl">مُنجَزة</div></div>
                </div>
            </div>
        </div>

        <!-- ── الجدول ── -->
        <div class="tasks-card">
            <div class="tasks-card-head">
                <h5>
                    <i class="fas fa-table ml-2 text-muted"></i>قائمة البلاغات
                    <span id="filteredCount" class="filter-count"></span>
                </h5>
                <div class="d-flex" style="gap:8px">
                    <?php if ($can_add): ?>
                    <a href="../forms/add-request.php" class="btn btn-sm"
                        style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:8px;font-weight:700;padding:6px 14px">
                        <i class="fas fa-plus ml-1"></i>بلاغ جديد
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── فلاتر ── -->
            <div class="task-filters">
                <label><i class="fas fa-filter ml-1"></i>فلترة:</label>

                <select id="fCategory">
                    <option value="">كل التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
                        <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <select id="fPriority">
                    <option value="">كل الأولويات</option>
                    <option value="طارئ">🔴 طارئ</option>
                    <option value="عاجل">🟠 عاجل</option>
                    <option value="متوسط">🔵 متوسط</option>
                    <option value="عادي">⚪ عادي</option>
                </select>

                <select id="fStatus">
                    <option value="">كل الحالات</option>
                    <option value="قيد الانتظار">⏳ قيد الانتظار</option>
                    <option value="قيد التنفيذ">🔄 قيد التنفيذ</option>
                    <option value="تم الإنجاز">✅ تم الإنجاز</option>
                    <option value="مغلق">🔒 مغلق</option>
                    <option value="ملغي">❌ ملغي</option>
                </select>

                <button id="resetFiltersBtn" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem" title="إعادة الضبط">
                    <i class="fas fa-undo ml-1"></i>إعادة
                </button>
            </div>

            <!-- ── الجدول الرئيسي ── -->
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="requestsTable" class="table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التفاصيل</th>
                                <th>الفرع / المنطقة</th>
                                <th>التصنيف</th>
                                <th>الأولوية</th>
                                <th>الحالة</th>
                                <th>الفني المسؤول</th>
                                <th>تاريخ التسليم</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tasks as $task):
                            $p  = $pmap[$task['priority']] ?? ['عادي','p-low'];
                            $s  = $smap[$task['status']]   ?? ['قيد الانتظار','s-pending'];
                            $dl = !empty($task['deadline']) && $task['deadline'] !== '0000-00-00 00:00:00'
                                ? date('Y-m-d', strtotime($task['deadline'])) : '—';
                            $cat = htmlspecialchars($task['category_name'] ?? 'عام');
                        ?>
                        <tr data-cat="<?= $cat ?>"
                            data-prior="<?= htmlspecialchars($p[0]) ?>"
                            data-status="<?= htmlspecialchars($s[0]) ?>">
                            <td><strong class="text-muted"><?= $task['request_id'] ?></strong></td>
                            <td class="details-cell">
                                <div class="ref-chip">
                                    <i class="fas fa-user" style="color:#94a3b8"></i>
                                    <?= htmlspecialchars($task['reporter_ref'] ?? '—') ?>
                                </div>
                                <div class="details-text" title="<?= htmlspecialchars($task['details'] ?? '') ?>">
                                    <?= htmlspecialchars(mb_substr($task['details'] ?? '', 0, 90)) ?><?= mb_strlen($task['details']??'')>90?'…':'' ?>
                                </div>
                            </td>
                            <td>
                                <small style="color:#475569"><?= htmlspecialchars($task['branch_name'] ?? '—') ?></small>
                                <?php if (!empty($task['region_name'])): ?>
                                <br><small style="color:#94a3b8;font-size:.7rem"><?= htmlspecialchars($task['region_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="background:#f1f5f9;color:#334155;padding:3px 10px;border-radius:6px;font-size:.72rem;font-weight:700">
                                    <?= $cat ?>
                                </span>
                            </td>
                            <td><span class="badge-pill-custom <?= $p[1] ?>"><?= htmlspecialchars($p[0]) ?></span></td>
                            <td><span class="badge-pill-custom <?= $s[1] ?>"><?= htmlspecialchars($s[0]) ?></span></td>
                            <td>
                                <?php if (!empty($task['technician_name'])): ?>
                                <div class="d-flex align-items-center justify-content-center gap-1" style="gap:5px">
                                    <div style="width:26px;height:26px;border-radius:50%;background:var(--crm-primary,#1a5276);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;flex-shrink:0">
                                        <?= mb_substr($task['technician_name'],0,1,'UTF-8') ?>
                                    </div>
                                    <span style="font-size:.78rem;color:#334155;font-weight:600"><?= htmlspecialchars($task['technician_name']) ?></span>
                                </div>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:.75rem">غير مُسنَد</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($dl !== '—'): ?>
                                <small style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px;font-size:.74rem">
                                    <i class="fas fa-calendar ml-1 text-muted" style="font-size:.65rem"></i><?= $dl ?>
                                </small>
                                <?php else: echo '<span class="text-muted">—</span>'; endif; ?>
                            </td>
                            <td>
                                <div class="d-flex justify-content-center" style="gap:4px">
                                    <a href="../forms/view-request.php?id=<?= $task['request_id'] ?>"
                                        class="btn-act" style="background:#dbeafe;color:#2563eb" title="عرض">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($can_edit): ?>
                                    <a href="edit-task.php?id=<?= $task['request_id'] ?>"
                                        class="btn-act" style="background:#fef3c7;color:#d97706" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <button type="button" class="btn-act delete-btn"
                                        style="background:#fee2e2;color:#dc2626" title="حذف"
                                        data-id="<?= $task['request_id'] ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
    </section>
</div>

<!-- مودال تأكيد الحذف -->
<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle ml-2"></i>تأكيد حذف البلاغ</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <div class="modal-body" style="padding:20px">
        <p>هل أنت متأكد من حذف هذا البلاغ نهائياً؟</p>
        <small class="text-danger"><i class="fas fa-info-circle ml-1"></i>لا يمكن حذف البلاغات المرتبطة بمهام نشطة.</small>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
        <a href="#" id="confirmDeleteBtn" class="btn btn-danger" style="border-radius:8px">
            <i class="fas fa-trash ml-1"></i>حذف نهائي
        </a>
    </div>
</div></div></div>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<!-- jQuery وBootstrap محمَّلان في الرأس — هنا نكمل باقي المكتبات -->
<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function() {
    /* ══════════════════════════════════════════════════════════
       فلتر مخصص — يقرأ data-* من كل صف مباشرةً
       أموثق هذا النهج لأنه الأكثر موثوقية مع scrollX وغيرها
    ══════════════════════════════════════════════════════════ */
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== 'requestsTable') return true;

        var row = settings.aoData[dataIndex].nTr;
        if (!row) return true;

        var rowCat    = $(row).data('cat')    || '';
        var rowPrior  = $(row).data('prior')  || '';
        var rowStatus = $(row).data('status') || '';

        var fCat    = $('#fCategory').val();
        var fPrior  = $('#fPriority').val();
        var fStatus = $('#fStatus').val();

        // إزالة الإيموجي من قيم الفلتر للمقارنة
        function clean(v) { return v.replace(/[\u{1F300}-\u{1FFFF}⏳🔄✅🔒❌🔴🟠🔵⚪]/gu,'').trim(); }

        if (fCat    && rowCat    !== fCat)           return false;
        if (fPrior  && rowPrior  !== clean(fPrior))  return false;
        if (fStatus && rowStatus !== clean(fStatus)) return false;
        return true;
    });

    /* ══ تهيئة DataTable ══ */
    var table = $('#requestsTable').DataTable({
        responsive: false,
        scrollX: false,
        autoWidth: false,
        order: [[0, 'desc']],
        dom: "<'row mb-2'<'col-md-6'B><'col-md-6 text-left'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel ml-1"></i>إكسل',
                className: 'btn btn-sm btn-outline-success',
                title: 'تقرير البلاغات',
                exportOptions: { columns: [0,1,2,3,4,5,6,7] }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print ml-1"></i>طباعة',
                className: 'btn btn-sm btn-outline-primary',
                exportOptions: { columns: [0,1,2,3,4,5,6,7] },
                customize: function(win) {
                    $(win.document.body).css({ direction:'rtl','text-align':'right','font-family':'Cairo,sans-serif' });
                    var hdr = '<div style="text-align:center;margin-bottom:20px;border-bottom:2px solid #1a5276;padding-bottom:10px">';
                    <?php if ($logo_data_uri): ?>
                    hdr += '<img src="<?= $logo_data_uri ?>" style="max-height:70px;margin-bottom:8px"><br>';
                    <?php endif; ?>
                    hdr += '<strong style="font-size:18px;color:#1a5276"><?= htmlspecialchars($company_name) ?></strong><br>';
                    hdr += '<h4 style="margin:8px 0 0;color:#334155">تقرير البلاغات الفنية</h4>';
                    hdr += '<small style="color:#888">تاريخ الطباعة: ' + new Date().toLocaleDateString('ar-SA') + '</small></div>';
                    $(win.document.body).find('h1').remove();
                    $(win.document.body).prepend(hdr);
                }
            },
            {
                extend: 'colvis',
                text: '<i class="fas fa-columns ml-1"></i>الأعمدة',
                className: 'btn btn-sm btn-outline-secondary'
            }
        ],
        language: {
            search: 'بحث شامل:', lengthMenu: 'عرض _MENU_',
            info: '_START_–_END_ من _TOTAL_', infoEmpty: 'لا نتائج',
            paginate: { next:'التالي', previous:'السابق' },
            emptyTable: '<div class="text-center py-4"><i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i><span class="text-muted">لا توجد بلاغات</span></div>',
            zeroRecords: 'لا توجد نتائج مطابقة للبحث'
        },
        drawCallback: function() {
            // تحديث عداد الصفوف المُصفَّاة
            var info = this.api().page.info();
            var countEl = document.getElementById('filteredCount');
            var hasFilter = $('#fCategory').val() || $('#fPriority').val() || $('#fStatus').val();
            if (hasFilter && info.recordsDisplay < info.recordsTotal) {
                countEl.textContent = info.recordsDisplay + ' نتيجة';
                countEl.classList.add('show');
            } else {
                countEl.classList.remove('show');
            }
            if (window.crmStyleButtons) window.crmStyleButtons();
        }
    });

    /* ══ ربط الفلاتر ══ */
    $('#fCategory, #fPriority, #fStatus').on('change', function() {
        // تمييز الفلتر النشط
        $(this).toggleClass('active-filter', !!$(this).val());
        table.draw();
    });

    $('#resetFiltersBtn').on('click', function() {
        $('#fCategory, #fPriority, #fStatus').val('').removeClass('active-filter');
        table.draw();
    });

    /* ══ حذف ══ */
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        $('#confirmDeleteBtn').attr('href', '?delete_id=' + $(this).data('id'));
        $('#deleteModal').modal('show');
    });
});
</script>
<?php include __DIR__ . '/../../print_header.php'; ?>
</body>
</html>
