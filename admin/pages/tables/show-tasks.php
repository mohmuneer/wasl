<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-tasks.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$can_add = $can_edit = $can_delete = 0;
$can_view_group_tasks = 0;
$can_view_own_tasks   = 0;
if ($current_page_id > 0) {
    try {
        $accStmt = $pdo->prepare("SELECT can_add, can_edit, can_delete, can_view_group_tasks, can_view_own_tasks FROM user_menu_access WHERE user_id=? AND menu_id=?");
        $accStmt->execute([$current_user_id, $current_page_id]);
        $p = $accStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // الأعمدة الجديدة غير مضافة بعد
        $accStmt = $pdo->prepare("SELECT can_add, can_edit, can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
        $accStmt->execute([$current_user_id, $current_page_id]);
        $p = $accStmt->fetch(PDO::FETCH_ASSOC);
        $p['can_view_group_tasks'] = 0;
        $p['can_view_own_tasks']   = 0;
    }
    $can_add             = $p['can_add']             ?? 0;
    $can_edit            = $p['can_edit']            ?? 0;
    $can_delete          = $p['can_delete']          ?? 0;
    $can_view_group_tasks = $p['can_view_group_tasks'] ?? 0;
    $can_view_own_tasks   = $p['can_view_own_tasks']   ?? 0;
}

// بناء شرط WHERE ديناميكي حسب صلاحيات عرض المهام
$whereClauses = [];
$params = [];
if ($can_view_group_tasks) {
    $whereClauses[] = "r.category_id IN (SELECT category_id FROM user_category_access WHERE user_id = ?)";
    $params[] = $current_user_id;
}
if ($can_view_own_tasks) {
    $whereClauses[] = "t.assigned_to = ?";
    $params[] = $current_user_id;
}
// إذا لم تكن أي من الصلاحيتين مفعلة، لا يُعرض شيء
if (empty($whereClauses)) {
    $whereClauses[] = "1 = 0";
}

$whereSQL = implode(" AND ", $whereClauses);
$sql = "SELECT t.*, r.details AS req_details,
               b.branch_name, g.category_name, u.full_name AS technician_name
        FROM work_orders t
        LEFT JOIN tickets r  ON t.ticket_id = r.id
        LEFT JOIN branches b ON r.branch_id  = b.id
        LEFT JOIN issue_categories g ON r.category_id = g.id
        LEFT JOIN sys_users u ON t.assigned_to = u.id
        WHERE $whereSQL
        ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حذف مهمة
if (isset($_GET['delete_id']) && $can_delete) {
    $pdo->prepare("DELETE FROM work_orders WHERE id = ?")->execute([(int)$_GET['delete_id']]);
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الحذف',text:'تم حذف المهمة بنجاح'}));window.location.href='show-tasks.php';</script>";
    exit;
}

// إحصائيات سريعة
$total     = count($tasks);
$pending   = count(array_filter($tasks, fn($t) => $t['status'] === 'Pending'));
$progress  = count(array_filter($tasks, fn($t) => $t['status'] === 'In Progress'));
$resolved  = count(array_filter($tasks, fn($t) => $t['status'] === 'Resolved'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة المهام</title>
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

/* ── إحصائيات ── */
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

/* ── بطاقة الجدول ── */
.tasks-card {
    background:#fff;border-radius:14px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    overflow:hidden;border:1px solid #f0f2f7;
}
.tasks-card-head {
    padding:14px 20px;
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:10px;
    border-bottom:1px solid #f0f2f7;
}
.tasks-card-head h5 {margin:0;font-weight:700;font-size:.95rem;color:#334155;}

/* ── فلاتر ── */
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
.task-filters select.active-filter {border-color:var(--crm-primary,#1a5276);background:#eff6ff;color:#1d4ed8;font-weight:700;}
.task-filters label {font-size:.78rem;font-weight:700;color:#64748b;margin-bottom:0;}

/* ── جدول ── */
#tasksTable {border-collapse:separate;border-spacing:0;}
#tasksTable thead th {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9)) !important;
    color:#fff !important;border:none !important;
    white-space:nowrap;vertical-align:middle;
    font-size:.78rem;font-weight:700;padding:11px 10px;text-align:center;
}
#tasksTable tbody td {
    vertical-align:middle;text-align:center;
    font-size:.8rem;padding:10px 8px;
    border-top:1px solid #f0f4f8 !important;border-left:none !important;border-right:none !important;border-bottom:none !important;
}
#tasksTable tbody tr:hover {background:#f8fafc;}
#tasksTable tbody tr:first-child td {border-top:none !important;}

.badge-pill-custom {padding:4px 12px;border-radius:20px;font-size:.72rem;font-weight:700;}
.btn-act {
    width:30px;height:30px;padding:0;border-radius:7px;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:.75rem;transition:.15s;border:none;cursor:pointer;
}
.btn-act:hover{transform:scale(1.05);}

/* ── شارة الأولوية ── */
.p-urgent  {background:#fee2e2;color:#dc2626;}
.p-high    {background:#fef3c7;color:#d97706;}
.p-medium  {background:#dbeafe;color:#2563eb;}
.p-low     {background:#f1f5f9;color:#64748b;}
/* ── شارة الحالة ── */
.s-pending  {background:#f1f5f9;color:#475569;}
.s-progress {background:#dbeafe;color:#1d4ed8;}
.s-resolved {background:#d1fae5;color:#065f46;}
.s-cancel   {background:#fee2e2;color:#dc2626;}
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
                    <h4><i class="fas fa-tasks ml-2"></i>إدارة المهام المُسنَدة</h4>
                    <small>عرض وتتبع المهام المُوزَّعة على الفنيين</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">المهام</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- ── إحصائيات ── -->
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3">
                <div class="task-stat">
                    <div class="ts-icon" style="background:linear-gradient(135deg,#1a5276,#2980b9)"><i class="fas fa-list"></i></div>
                    <div><div class="ts-val"><?= $total ?></div><div class="ts-lbl">إجمالي المهام</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="task-stat">
                    <div class="ts-icon" style="background:linear-gradient(135deg,#92400e,#d97706)"><i class="fas fa-clock"></i></div>
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

        <!-- ── الجدول الرئيسي ── -->
        <div class="tasks-card">
            <div class="tasks-card-head">
                    <h5><i class="fas fa-table ml-2 text-muted"></i>قائمة المهام</h5>
                <div class="d-flex gap-2" style="gap:8px">
                    <?php if ($can_add): ?>
                    <a href="../forms/add-task.php" class="btn btn-sm"
                        style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:8px;font-weight:700;padding:6px 14px">
                        <i class="fas fa-plus ml-1"></i>مهمة جديدة
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- فلاتر -->
            <div class="task-filters">
                <label><i class="fas fa-filter ml-1"></i>فلترة:</label>
                <select id="fCategory">
                    <option value="">كل الأنواع</option>
                    <?php $cats = array_unique(array_filter(array_column($tasks,'category_name'))); sort($cats);
                    foreach ($cats as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fPriority">
                    <option value="">كل الأولويات</option>
                    <option value="حرج جداً">🔴 حرج جداً</option>
                    <option value="عالي">🟠 عالي</option>
                    <option value="متوسط">🔵 متوسط</option>
                    <option value="عادي">⚪ عادي</option>
                </select>
                <select id="fStatus">
                    <option value="">كل الحالات</option>
                    <option value="قيد الانتظار">⏳ قيد الانتظار</option>
                    <option value="قيد التنفيذ">🔄 قيد التنفيذ</option>
                    <option value="تم الإنجاز">✅ تم الإنجاز</option>
                    <option value="ملغي">❌ ملغي</option>
                </select>
                <button id="resetFilters" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem">
                    <i class="fas fa-undo ml-1"></i>إعادة
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="tasksTable" class="table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الفني المسؤول</th>
                                <th>الفرع</th>
                                <th>نوع المشكلة</th>
                                <th>الأولوية</th>
                                <th>الحالة</th>
                                <th>تاريخ التسليم</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $pmap = [
                            'Urgent' => ['حرج جداً','p-urgent'],
                            'High'   => ['عالي',    'p-high'],
                            'Medium' => ['متوسط',   'p-medium'],
                            'Low'    => ['عادي',    'p-low'],
                        ];
                        $smap = [
                            'Pending'     => ['قيد الانتظار','s-pending'],
                            'In Progress' => ['قيد التنفيذ', 's-progress'],
                            'Resolved'    => ['تم الإنجاز',  's-resolved'],
                            'Cancelled'   => ['ملغي',        's-cancel'],
                        ];
                        foreach ($tasks as $task):
                            $p = $pmap[$task['priority']] ?? ['عادي','p-low'];
                            $s = $smap[$task['status']]   ?? ['قيد الانتظار','s-pending'];
                            $dl = !empty($task['deadline']) && $task['deadline'] !== '0000-00-00 00:00:00'
                                ? date('Y-m-d', strtotime($task['deadline'])) : '—';
                        ?>
                        <tr data-cat="<?= htmlspecialchars($task['category_name'] ?? 'عام') ?>"
                            data-prior="<?= htmlspecialchars($p[0]) ?>"
                            data-status="<?= htmlspecialchars($s[0]) ?>">
                            <td><strong class="text-muted"><?= $task['id'] ?></strong></td>
                            <td>
                                <div class="d-flex align-items-center justify-content-center gap-1" style="gap:6px">
                                    <div style="width:28px;height:28px;border-radius:50%;background:var(--crm-primary,#1a5276);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex-shrink:0">
                                        <?= mb_substr($task['technician_name'] ?? 'غ', 0, 1) ?>
                                    </div>
                                    <span style="font-size:.8rem;font-weight:600;color:#334155"><?= htmlspecialchars($task['technician_name'] ?? 'غير محدد') ?></span>
                                </div>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars($task['branch_name'] ?? '—') ?></small></td>
                            <td>
                                <span style="background:#f1f5f9;color:#334155;padding:3px 10px;border-radius:6px;font-size:.72rem;font-weight:700">
                                    <?= htmlspecialchars($task['category_name'] ?? 'عام') ?>
                                </span>
                            </td>
                            <td><span class="badge-pill-custom <?= $p[1] ?>"><?= $p[0] ?></span></td>
                            <td><span class="badge-pill-custom <?= $s[1] ?>"><?= $s[0] ?></span></td>
                            <td>
                                <?php if ($dl !== '—'): ?>
                                <small style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px;font-size:.75rem">
                                    <i class="fas fa-calendar ml-1 text-muted" style="font-size:.65rem"></i><?= $dl ?>
                                </small>
                                <?php else: echo '<span class="text-muted">—</span>'; endif; ?>
                            </td>
                            <td>
                                <div class="d-flex justify-content-center" style="gap:5px">
                                    <?php if ($can_edit): ?>
                                    <a href="edit-task.php?id=<?= $task['ticket_id'] ?? $task['id'] ?>"
                                        class="btn-act" style="background:#fef3c7;color:#d97706" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <button type="button" class="btn-act delete-btn"
                                        style="background:#fee2e2;color:#dc2626" title="حذف"
                                        data-id="<?= $task['id'] ?>">
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
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle ml-2"></i>تأكيد حذف المهمة</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <div class="modal-body" style="padding:20px">
        <p>هل أنت متأكد من حذف هذه المهمة نهائياً؟</p>
        <small class="text-danger"><i class="fas fa-info-circle ml-1"></i>لا يمكن التراجع عن هذا الإجراء.</small>
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

<!-- jQuery وBootstrap محمَّلان في الرأس -->
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
$(document).ready(function() {
    var table = $('#tasksTable').DataTable({
        responsive: false,
        scrollX: false,
        autoWidth: false,
        order: [[0,'desc']],
        dom: "<'row mb-2'<'col-md-6'B><'col-md-6 text-left'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
            { extend:'excelHtml5', text:'<i class="fas fa-file-excel ml-1"></i>إكسل', className:'btn btn-sm btn-outline-success', exportOptions:{columns:':not(:last-child)'} },
            { extend:'print',      text:'<i class="fas fa-print ml-1"></i>طباعة',     className:'btn btn-sm btn-outline-primary',  exportOptions:{columns:':not(:last-child)'},
              customize: function(win){ $(win.document.body).css({direction:'rtl','text-align':'right','font-family':'Cairo,sans-serif'}); }
            },
            { extend:'colvis', text:'<i class="fas fa-columns ml-1"></i>الأعمدة', className:'btn btn-sm btn-outline-secondary' }
        ],
        language: {
            search:'بحث:', lengthMenu:'عرض _MENU_', info:'_START_-_END_ من _TOTAL_',
            infoEmpty:'لا توجد نتائج', paginate:{next:'التالي',previous:'السابق'},
            emptyTable:'<div class="text-center py-4"><i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i><span class="text-muted">لا توجد مهام مُسنَدة لك</span></div>',
            zeroRecords:'لا توجد نتائج مطابقة للبحث'
        }
    });

    // ── فلتر موثوق باستخدام data-* attributes ──
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== 'tasksTable') return true;
        var row = settings.aoData[dataIndex].nTr;
        if (!row) return true;

        var rowCat    = $(row).data('cat')    || '';
        var rowPrior  = $(row).data('prior')  || '';
        var rowStatus = $(row).data('status') || '';

        var fCat    = $('#fCategory').val();
        var fPrior  = $('#fPriority').val();
        var fStatus = $('#fStatus').val();

        // إزالة الإيموجي من قيم الفلتر
        function clean(v) { return v.replace(/[\u{1F300}-\u{1FFFF}⏳🔄✅🔒❌🔴🟠🔵⚪]/gu,'').trim(); }

        if (fCat    && rowCat    !== fCat)           return false;
        if (fPrior  && rowPrior  !== clean(fPrior))  return false;
        if (fStatus && rowStatus !== clean(fStatus)) return false;
        return true;
    });

    $('#fCategory, #fPriority, #fStatus').on('change', function() {
        $(this).toggleClass('active-filter', !!$(this).val());
        table.draw();
    });
    $('#resetFilters').on('click', function() {
        $('#fCategory, #fPriority, #fStatus').val('').removeClass('active-filter');
        table.draw();
    });

    // ── حذف ──
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        $('#confirmDeleteBtn').attr('href', '?delete_id=' + $(this).data('id'));
        $('#deleteModal').modal('show');
    });

    // ── رسالة إسناد المهمة ──────────────────────────────────────
    (function() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('task_saved') === '1') {
            var tech = params.get('tech') || 'الفني';
            var req  = params.get('req')  || '';

            Swal.fire({
                icon: 'success',
                title: '✅ تم إسناد المهمة بنجاح',
                html:
                    '<div style="font-size:1rem;color:#334155;line-height:1.8">' +
                    '<div>📋 تم توزيع المهمة على <strong style="color:#1a5276">' + $('<span>').text(tech).html() + '</strong></div>' +
                    (req ? '<div style="font-size:.85rem;color:#64748b">رقم البلاغ: #' + parseInt(req) + '</div>' : '') +
                    '<div style="font-size:.82rem;color:#059669;margin-top:6px"><i class="fas fa-comments"></i> تم إرسال إشعار للفني عبر الدردشة الداخلية</div>' +
                    '</div>',
                confirmButtonText: 'حسناً',
                confirmButtonColor: '#1a5276',
                timer: 5000,
                timerProgressBar: true,
                showClass: { popup: 'animate__animated animate__fadeInDown' },
                didOpen: function() {
                    // إزالة المعاملات من الرابط بعد العرض
                    var cleanUrl = window.location.pathname;
                    window.history.replaceState({}, document.title, cleanUrl);
                }
            });
        }
    })();
    // ────────────────────────────────────────────────────────────
});
</script>
<?php include __DIR__ . '/../../print_header.php'; ?>
</body>
</html>
