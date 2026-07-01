<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path       = "pages/forms/addbranch.php";

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

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_branch'])) {
    $branch_name = trim($_POST['branch_name'] ?? '');
    if (empty($branch_name)) {
        $flash = ['type'=>'error', 'msg'=>'اسم الفرع مطلوب'];
    } elseif (!$can_add) {
        $flash = ['type'=>'error', 'msg'=>'ليس لديك صلاحية الإضافة'];
    } else {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE branch_name=?");
        $exists->execute([$branch_name]);
        if ($exists->fetchColumn() > 0) {
            $flash = ['type'=>'error', 'msg'=>'اسم الفرع موجود مسبقاً'];
        } else {
            $pdo->prepare("INSERT INTO branches (branch_name) VALUES (?)")->execute([$branch_name]);
            $flash = ['type'=>'success', 'msg'=>'تم إضافة الفرع بنجاح'];
        }
    }
}

// ── جلب جميع الفروع ─────────────────────────────────────────────
$allBranches = $pdo->query("SELECT * FROM branches ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalBranches = count($allBranches);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إدارة الفروع | النظام</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
:root { --p:#1e4b8a; --p-lt:#e8eef8; --gold:#f39c12; }
body { direction:rtl; text-align:right; font-family:'Source Sans Pro',Arial,sans-serif; background:#f4f6f9; }

/* ── ترويسة ── */

/* ── بطاقات ── */
.form-card, .list-card {
    background:#fff; border-radius:14px;
    box-shadow:0 2px 12px rgba(0,0,0,.07); overflow:hidden;
}
.card-head {
    padding:14px 20px; border-bottom:2px solid var(--p);
    background:var(--p-lt); display:flex; align-items:center; justify-content:space-between;
}
.card-head h6 { margin:0; color:var(--p); font-weight:700; font-size:.95rem; display:flex; align-items:center; gap:8px; }
.card-head .ch-icon {
    width:32px; height:32px; border-radius:8px; background:var(--p);
    color:#fff; display:flex; align-items:center; justify-content:center; font-size:.9rem;
}

/* ── نموذج الإضافة ── */
.form-body { padding:20px; }
.add-input {
    width:100%; border:1.5px solid #dde3f0; border-radius:10px;
    padding:11px 16px; font-size:.9rem; transition:border-color .2s,box-shadow .2s;
}
.add-input:focus { outline:none; border-color:var(--p); box-shadow:0 0 0 3px rgba(30,75,138,.1); }
.btn-add {
    width:100%; background:var(--p); color:#fff; border:none; border-radius:10px;
    padding:11px; font-size:.9rem; font-weight:700; margin-top:10px;
    transition:background .2s,transform .1s; cursor:pointer;
}
.btn-add:hover { background:#163870; transform:translateY(-1px); }
.btn-add:disabled { background:#aaa; cursor:not-allowed; transform:none; }

/* ── إحصاء ── */
.stat-box {
    background:linear-gradient(135deg,#1e4b8a,#4facfe);
    border-radius:10px; padding:14px 16px; color:#fff; margin-top:14px;
    display:flex; align-items:center; gap:12px;
}
.stat-box .sb-num { font-size:2rem; font-weight:800; line-height:1; }
.stat-box .sb-lbl { font-size:.82rem; opacity:.9; }

/* ── الجدول ── */
#branchTable th { background:#f0f4ff; border-bottom:2px solid var(--p)!important; white-space:nowrap; text-align:center; }
#branchTable td { vertical-align:middle; text-align:center; }
.branch-name-cell { font-weight:600; color:#222; text-align:right!important; padding-right:18px!important; }

/* ── أزرار الإجراءات ── */
.btn-act { width:34px; height:34px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; border:none; cursor:pointer; transition:opacity .15s; }
.btn-act:hover { opacity:.85; }
.btn-edit  { background:#ffc107; color:#212529; }
.btn-del   { background:#dc3545; color:#fff; }
.btn-dis   { background:#e9ecef; color:#aaa; cursor:not-allowed; }

/* ── بحث DataTable ── */
.dataTables_filter input { border-radius:8px; border:1.5px solid #dde3f0; padding:6px 14px; font-size:.85rem; }
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
            <h4><i class="fas fa-code-branch ml-2"></i>إدارة الفروع</h4>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                <li class="breadcrumb-item"><a href="#">الهيكل الإداري</a></li>
                <li class="breadcrumb-item active">الفروع</li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid">
<div class="row">

    <!-- ══ يمين: نموذج الإضافة + إحصاء ══ -->
    <div class="col-lg-3 col-md-4 mb-4">

        <div class="form-card">
            <div class="card-head">
                <h6><div class="ch-icon"><i class="fas fa-plus"></i></div>إضافة فرع جديد</h6>
            </div>
            <div class="form-body">
                <form method="POST" id="addForm">
                    <?= Security::field() ?>
                    <label style="font-weight:600;font-size:.85rem;color:#555;margin-bottom:6px;display:block">
                        اسم الفرع <span style="color:#dc3545">*</span>
                    </label>
                    <input type="text" name="branch_name" id="branchInput"
                           class="add-input" placeholder="مثال: فرع جدة" required
                           autocomplete="off">
                    <small class="text-muted" style="font-size:.75rem;display:block;margin-top:4px">
                        أدخل اسم الفرع الجديد ثم اضغط إضافة
                    </small>

                    <?php if ($can_add): ?>
                    <button type="submit" name="add_branch" class="btn-add">
                        <i class="fas fa-plus ml-2"></i>إضافة الفرع
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn-add" disabled>
                        <i class="fas fa-lock ml-2"></i>لا تملك صلاحية الإضافة
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- إحصاء -->
        <div class="stat-box">
            <i class="fas fa-code-branch" style="font-size:1.8rem;opacity:.8"></i>
            <div>
                <div class="sb-num"><?= $totalBranches ?></div>
                <div class="sb-lbl">إجمالي الفروع المسجلة</div>
            </div>
        </div>

    </div>

    <!-- ══ يسار: قائمة الفروع ══ -->
    <div class="col-lg-9 col-md-8 mb-4">
        <div class="list-card">
            <div class="card-head">
                <h6>
                    <div class="ch-icon" style="background:#27ae60"><i class="fas fa-list"></i></div>
                    قائمة الفروع المسجلة
                    <span class="badge badge-primary badge-pill" style="font-size:.72rem"><?= $totalBranches ?></span>
                </h6>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table id="branchTable" class="table table-hover table-bordered" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width:60px">#</th>
                                <th>اسم الفرع</th>
                                <th style="width:120px">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allBranches as $i => $row): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td class="branch-name-cell">
                                    <i class="fas fa-map-marker-alt text-primary ml-2" style="font-size:.8rem"></i>
                                    <?= htmlspecialchars($row['branch_name']) ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center">
                                        <?php if ($can_edit): ?>
                                        <button class="btn-act btn-edit edit-btn"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['branch_name'], ENT_QUOTES) ?>"
                                                title="تعديل">
                                            <i class="fas fa-edit" style="font-size:.8rem"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-act btn-dis" title="لا تملك صلاحية التعديل">
                                            <i class="fas fa-edit" style="font-size:.8rem"></i>
                                        </span>
                                        <?php endif; ?>

                                        <?php if ($can_delete): ?>
                                        <button class="btn-act btn-del del-btn"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['branch_name'], ENT_QUOTES) ?>"
                                                title="حذف">
                                            <i class="fas fa-trash" style="font-size:.8rem"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-act btn-dis" title="لا تملك صلاحية الحذف">
                                            <i class="fas fa-trash" style="font-size:.8rem"></i>
                                        </span>
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

</div><!-- /row -->
</div>
</section>
</div><!-- /content-wrapper -->
</div><!-- /wrapper -->

<!-- ══ مودال تعديل الفرع ══ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header" style="background:linear-gradient(135deg,#ffc107,#e0a800);">
        <h5 class="modal-title font-weight-bold"><i class="fas fa-edit ml-2"></i>تعديل بيانات الفرع</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
    </div>
    <form action="edit-branch.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="branch_id" id="editId">
            <label class="font-weight-bold">اسم الفرع <span class="text-danger">*</span></label>
            <input type="text" name="branch_name" id="editName" class="form-control" required>
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

<!-- ══ مودال حذف الفرع ══ -->
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content">
    <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle ml-2"></i>تأكيد الحذف</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form action="edit-branch.php" method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="branch_id" id="delId">
        <div class="modal-body text-center">
            <p class="mb-1">هل أنت متأكد من حذف فرع</p>
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
    $('#branchTable').DataTable({
        language: {
            emptyTable: 'لا توجد فروع مسجلة حالياً',
            info: 'عرض _START_ إلى _END_ من أصل _TOTAL_ سجل',
            infoEmpty: 'عرض 0 إلى 0 من أصل 0 سجل',
            infoFiltered: '(منتقاة من _MAX_ سجل إجمالي)',
            lengthMenu: 'عرض _MENU_ سجل في الصفحة',
            loadingRecords: 'جارٍ التحميل...',
            processing: 'جارٍ المعالجة...',
            search: 'بحث:',
            zeroRecords: 'لم يعثر على أية سجلات',
            paginate: { first: 'الأول', last: 'الأخير', next: 'التالي', previous: 'السابق' },
            aria: { sortAscending: ': تفعيل لترتيب العمود تصاعدياً', sortDescending: ': تفعيل لترتيب العمود تنازلياً' }
        },
        order: [[0,'asc']],
        columnDefs: [{ orderable:false, targets:[2] }],
        pageLength: 15
    });

    // زر التعديل
    $(document).on('click', '.edit-btn', function() {
        $('#editId').val($(this).data('id'));
        $('#editName').val($(this).data('name'));
        $('#editModal').modal('show');
    });

    // زر الحذف
    $(document).on('click', '.del-btn', function() {
        $('#delId').val($(this).data('id'));
        $('#delName').text($(this).data('name'));
        $('#delModal').modal('show');
    });

    // رسائل Flash
    <?php if ($flash['msg']): ?>
    Swal.fire({
        icon:  '<?= $flash['type'] ?>',
        title: '<?= $flash['type']==='success' ? 'تمت العملية' : 'تنبيه' ?>',
        text:  '<?= addslashes($flash['msg']) ?>',
        timer: 2800,
        showConfirmButton: false,
        timerProgressBar: true
    });
    <?php endif; ?>

    // رسائل SweetAlert من sessionStorage (بعد redirect)
    var st = sessionStorage.getItem('swal_title');
    if (st) {
        Swal.fire({ title:st, text:sessionStorage.getItem('swal_text'), icon:sessionStorage.getItem('swal_icon'), timer:2800, showConfirmButton:false, timerProgressBar:true });
        sessionStorage.removeItem('swal_title');
        sessionStorage.removeItem('swal_text');
        sessionStorage.removeItem('swal_icon');
    }
});
</script>
</body>
</html>
