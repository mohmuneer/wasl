<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path       = "pages/forms/add-college.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

// ── صلاحيات ─────────────────────────────────────────────────────
$can_add = $can_edit = $can_delete = 0;
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $p = $accStmt->fetch(PDO::FETCH_ASSOC);
    if ($p) { $can_add=(int)$p['can_add']; $can_edit=(int)$p['can_edit']; $can_delete=(int)$p['can_delete']; }
}

// ── معالجة الإضافة ───────────────────────────────────────────────
$flash = ['type'=>'', 'msg'=>''];

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_college'])) {
    $region_name = trim($_POST['college_name'] ?? '');
    $branch_id   = (int)($_POST['branch_id'] ?? 0);

    if (empty($region_name) || !$branch_id) {
        $flash = ['type'=>'error', 'msg'=>'الرجاء تعبئة جميع الحقول المطلوبة'];
    } elseif (!$can_add) {
        $flash = ['type'=>'error', 'msg'=>'ليس لديك صلاحية الإضافة'];
    } else {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM regions WHERE region_name=? AND branch_id=?");
        $exists->execute([$region_name, $branch_id]);
        if ($exists->fetchColumn() > 0) {
            $flash = ['type'=>'error', 'msg'=>'هذه المنطقة موجودة مسبقاً في هذا الفرع'];
        } else {
            $pdo->prepare("INSERT INTO regions (region_name, branch_id) VALUES (?,?)")->execute([$region_name, $branch_id]);
            $flash = ['type'=>'success', 'msg'=>'تم إضافة المنطقة بنجاح'];
        }
    }
}

// ── جلب البيانات ─────────────────────────────────────────────────
$allBranches = $pdo->query("SELECT * FROM branches ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allRegions  = $pdo->query("SELECT r.*, b.branch_name FROM regions r JOIN branches b ON r.branch_id=b.id ORDER BY b.branch_name, r.region_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalRegions  = count($allRegions);
$totalBranches = count($allBranches);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إدارة المناطق | النظام</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
:root { --p:#1e4b8a; --p-lt:#e8eef8; --teal:#17a2b8; }
body { direction:rtl; text-align:right; font-family:'Source Sans Pro',Arial,sans-serif; background:#f4f6f9; }


.form-card, .list-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); overflow:hidden; }

.card-head { padding:13px 18px; border-bottom:2px solid var(--p); background:var(--p-lt); display:flex; align-items:center; justify-content:space-between; }
.card-head h6 { margin:0; color:var(--p); font-weight:700; font-size:.92rem; display:flex; align-items:center; gap:8px; }
.card-head .ch-icon { width:30px; height:30px; border-radius:7px; background:var(--p); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.form-body { padding:18px; }

/* حقول النموذج */
.f-label { font-weight:600; font-size:.84rem; color:#555; margin-bottom:5px; display:block; }
.f-input { width:100%; border:1.5px solid #dde3f0; border-radius:9px; padding:10px 14px; font-size:.88rem; transition:border-color .2s,box-shadow .2s; }
.f-input:focus { outline:none; border-color:var(--p); box-shadow:0 0 0 3px rgba(30,75,138,.1); }
.f-group { margin-bottom:14px; }

/* زر الإضافة */
.btn-add { width:100%; background:var(--p); color:#fff; border:none; border-radius:9px; padding:10px; font-size:.88rem; font-weight:700; margin-top:6px; cursor:pointer; transition:background .18s,transform .1s; display:flex; align-items:center; justify-content:center; gap:7px; }
.btn-add:hover { background:#163870; transform:translateY(-1px); }
.btn-add:disabled { background:#aaa; cursor:not-allowed; transform:none; }

/* إحصاء */
.stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:14px; }
.stat-mini { border-radius:10px; padding:12px 14px; color:#fff; display:flex; align-items:center; gap:10px; }
.stat-mini .sm-num { font-size:1.6rem; font-weight:800; line-height:1; }
.stat-mini .sm-lbl { font-size:.72rem; opacity:.9; }

/* جدول */
#regionTable th { background:#f0f4ff; border-bottom:2px solid var(--p)!important; text-align:center; white-space:nowrap; }
#regionTable td { vertical-align:middle; text-align:center; }
.region-name-cell { font-weight:600; text-align:right!important; padding-right:16px!important; }
.branch-badge { background:#e8eef8; color:var(--p); border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; }

/* أزرار الإجراءات */
.btn-act { width:32px; height:32px; border-radius:7px; display:inline-flex; align-items:center; justify-content:center; border:none; cursor:pointer; transition:opacity .15s; }
.btn-act:hover { opacity:.82; }
.btn-edit { background:#ffc107; color:#212529; }
.btn-del  { background:#dc3545; color:#fff; }
.btn-dis  { background:#e9ecef; color:#aaa; cursor:not-allowed; }

/* فلتر */
.filter-row { display:flex; gap:10px; margin-bottom:12px; align-items:center; flex-wrap:wrap; }
.filter-row select { border:1.5px solid #dde3f0; border-radius:8px; padding:6px 12px; font-size:.84rem; min-width:160px; }
.filter-row select:focus { outline:none; border-color:var(--p); }
.filter-row .f-count { font-size:.78rem; color:#888; }
.filter-row .f-count strong { color:var(--p); }
.btn-reset-f { border:1.5px solid #dde3f0; border-radius:8px; padding:6px 12px; font-size:.8rem; background:#fff; color:#666; cursor:pointer; }
.btn-reset-f:hover { background:#f0f4ff; color:var(--p); border-color:var(--p); }

/* DataTable search */
.dataTables_filter input { border-radius:8px; border:1.5px solid #dde3f0; padding:5px 12px; font-size:.84rem; }
.dataTables_filter input:focus { border-color:var(--p); outline:none; }
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">
<section class="content-header">
    <div class="container-fluid">
        <div class="page-banner">
            <h4><i class="fas fa-map-marker-alt ml-2"></i>إدارة المناطق</h4>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                <li class="breadcrumb-item"><a href="#">الهيكل الإداري</a></li>
                <li class="breadcrumb-item active">المناطق</li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid">
<div class="row">

    <!-- ══ يمين: نموذج + إحصاء ══ -->
    <div class="col-lg-3 col-md-4 mb-4">
        <div class="form-card">
            <div class="card-head">
                <h6><div class="ch-icon"><i class="fas fa-plus"></i></div>إضافة منطقة جديدة</h6>
            </div>
            <div class="form-body">
                <form method="POST">
                    <div class="f-group">
                        <label class="f-label">الفرع <span style="color:#dc3545">*</span></label>
                        <select name="branch_id" class="f-input" required>
                            <option value="" disabled selected>— اختر الفرع —</option>
                            <?php foreach ($allBranches as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f-group">
                        <label class="f-label">اسم المنطقة <span style="color:#dc3545">*</span></label>
                        <input type="text" name="college_name" class="f-input"
                               placeholder="مثال: منطقة الشمال" required autocomplete="off">
                    </div>

                    <?php if ($can_add): ?>
                    <button type="submit" name="add_college" class="btn-add">
                        <i class="fas fa-plus"></i>إضافة المنطقة
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn-add" disabled>
                        <i class="fas fa-lock"></i>لا تملك صلاحية الإضافة
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- إحصائيات -->
        <div class="stats-grid">
            <div class="stat-mini" style="background:linear-gradient(135deg,#1e4b8a,#4facfe)">
                <i class="fas fa-map-marker-alt" style="font-size:1.5rem;opacity:.8"></i>
                <div><div class="sm-num"><?= $totalRegions ?></div><div class="sm-lbl">منطقة</div></div>
            </div>
            <div class="stat-mini" style="background:linear-gradient(135deg,#27ae60,#43e97b)">
                <i class="fas fa-code-branch" style="font-size:1.5rem;opacity:.8"></i>
                <div><div class="sm-num"><?= $totalBranches ?></div><div class="sm-lbl">فرع</div></div>
            </div>
        </div>

    </div>

    <!-- ══ يسار: قائمة المناطق ══ -->
    <div class="col-lg-9 col-md-8 mb-4">
        <div class="list-card">
            <div class="card-head">
                <h6>
                    <div class="ch-icon" style="background:#17a2b8"><i class="fas fa-list"></i></div>
                    قائمة المناطق المسجلة
                    <span class="badge badge-primary badge-pill" style="font-size:.7rem"><?= $totalRegions ?></span>
                </h6>
            </div>
            <div class="card-body p-3">

                <!-- فلتر الفروع -->
                <div class="filter-row">
                    <select id="filterBranch">
                        <option value="">كل الفروع</option>
                        <?php foreach ($allBranches as $b): ?>
                        <option value="<?= htmlspecialchars($b['branch_name']) ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-reset-f" id="resetFilter"><i class="fas fa-undo ml-1"></i>إعادة</button>
                    <span class="f-count" id="fCount"></span>
                </div>

                <div class="table-responsive">
                    <table id="regionTable" class="table table-hover table-bordered" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width:55px">#</th>
                                <th>اسم المنطقة</th>
                                <th>الفرع</th>
                                <th style="width:110px">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allRegions as $i => $row): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td class="region-name-cell">
                                    <i class="fas fa-map-pin text-info ml-2" style="font-size:.75rem"></i>
                                    <?= htmlspecialchars($row['region_name']) ?>
                                </td>
                                <td>
                                    <span class="branch-badge">
                                        <i class="fas fa-code-branch ml-1" style="font-size:.7rem"></i>
                                        <?= htmlspecialchars($row['branch_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center">
                                        <?php if ($can_edit): ?>
                                        <button class="btn-act btn-edit edit-btn"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['region_name'], ENT_QUOTES) ?>"
                                                data-branch="<?= $row['branch_id'] ?>"
                                                title="تعديل">
                                            <i class="fas fa-edit" style="font-size:.78rem"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-act btn-dis"><i class="fas fa-edit" style="font-size:.78rem"></i></span>
                                        <?php endif; ?>

                                        <?php if ($can_delete): ?>
                                        <button class="btn-act btn-del del-btn"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['region_name'], ENT_QUOTES) ?>"
                                                title="حذف">
                                            <i class="fas fa-trash" style="font-size:.78rem"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-act btn-dis"><i class="fas fa-trash" style="font-size:.78rem"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($allRegions)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">
                                <i class="fas fa-map-marker-alt fa-2x mb-2 d-block" style="opacity:.25"></i>
                                لا توجد مناطق مسجلة
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div><!-- /row -->
</div>
</section>
</div>

<!-- ══ مودال التعديل ══ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header" style="background:linear-gradient(135deg,#ffc107,#e0a800)">
        <h5 class="modal-title font-weight-bold"><i class="fas fa-edit ml-2"></i>تعديل بيانات المنطقة</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
    </div>
    <form action="edit-college.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="college_id" id="editId">
            <div class="f-group">
                <label class="f-label">اسم المنطقة <span style="color:#dc3545">*</span></label>
                <input type="text" name="college_name" id="editName" class="f-input" required>
            </div>
            <div class="f-group">
                <label class="f-label">الفرع</label>
                <select name="branch_id" id="editBranch" class="f-input">
                    <?php foreach ($allBranches as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn btn-warning font-weight-bold">
                <i class="fas fa-save ml-1"></i>حفظ التغييرات
            </button>
        </div>
    </form>
</div>
</div>
</div>

<!-- ══ مودال الحذف ══ -->
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content">
    <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle ml-2"></i>تأكيد الحذف</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form action="edit-college.php" method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="college_id" id="delId">
        <div class="modal-body text-center">
            <p class="mb-1">هل أنت متأكد من حذف منطقة</p>
            <strong class="text-danger" id="delName"></strong>
            <p class="text-muted small mt-2 mb-0">لا يمكن التراجع عن هذا الإجراء</p>
        </div>
        <div class="modal-footer justify-content-center">
            <button type="button" class="btn btn-light btn-sm" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn btn-danger btn-sm">
                <i class="fas fa-trash ml-1"></i>حذف نهائي
            </button>
        </div>
    </form>
</div>
</div>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {

    // DataTable
    var dt = $('#regionTable').DataTable({
        language: { url:'//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json' },
        order: [[2,'asc'],[1,'asc']],
        columnDefs: [{ orderable:false, targets:[3] }],
        pageLength: 15
    });

    // فلتر الفروع
    var TOTAL = <?= $totalRegions ?>;
    function updateCount() {
        var info = dt.page.info();
        var el = document.getElementById('fCount');
        if (info.recordsDisplay < info.recordsTotal)
            el.innerHTML = 'نتائج: <strong>' + info.recordsDisplay + '</strong> من ' + TOTAL;
        else
            el.innerHTML = '<strong>' + TOTAL + '</strong> منطقة';
    }
    $('#filterBranch').on('change', function() { dt.column(2).search(this.value).draw(); });
    $('#resetFilter').on('click', function() { $('#filterBranch').val(''); dt.column(2).search('').draw(); });
    $('#regionTable').on('draw.dt', updateCount);
    updateCount();

    // مودال التعديل
    $(document).on('click', '.edit-btn', function() {
        $('#editId').val($(this).data('id'));
        $('#editName').val($(this).data('name'));
        $('#editBranch').val($(this).data('branch'));
        $('#editModal').modal('show');
    });

    // مودال الحذف
    $(document).on('click', '.del-btn', function() {
        $('#delId').val($(this).data('id'));
        $('#delName').text($(this).data('name'));
        $('#delModal').modal('show');
    });

    // رسائل Flash
    <?php if ($flash['msg']): ?>
    Swal.fire({
        icon:'<?= $flash['type'] ?>',
        title:'<?= $flash['type']==='success'?'تمت العملية':'تنبيه' ?>',
        text:'<?= addslashes($flash['msg']) ?>',
        timer:2800, showConfirmButton:false, timerProgressBar:true
    });
    <?php endif; ?>

    // رسائل sessionStorage
    var st = sessionStorage.getItem('swal_title');
    if (st) {
        Swal.fire({ title:st, text:sessionStorage.getItem('swal_text'), icon:sessionStorage.getItem('swal_icon'), timer:2800, showConfirmButton:false, timerProgressBar:true });
        sessionStorage.removeItem('swal_title'); sessionStorage.removeItem('swal_text'); sessionStorage.removeItem('swal_icon');
    }
});
</script>
</body>
</html>
