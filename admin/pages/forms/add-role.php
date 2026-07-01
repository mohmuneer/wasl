<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-role.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$can_add = 0;
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $can_add = (int)($accStmt->fetchColumn() ?: 0);
}

$flash = ['type'=>'', 'msg'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_permission'])) {
    $role_name = trim($_POST['permission_name'] ?? '');
    $role_code = trim($_POST['permission_code'] ?? '');
    if (!$can_add) {
        $flash = ['type'=>'error', 'msg'=>'ليس لديك صلاحية الإضافة'];
    } elseif (empty($role_name) || empty($role_code)) {
        $flash = ['type'=>'error', 'msg'=>'الرجاء تعبئة جميع الحقول'];
    } else {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM sys_roles WHERE role_code=?");
        $exists->execute([$role_code]);
        if ($exists->fetchColumn() > 0) {
            $flash = ['type'=>'error', 'msg'=>'كود الصلاحية موجود مسبقاً'];
        } else {
            $pdo->prepare("INSERT INTO sys_roles (role_name, role_code) VALUES (?,?)")->execute([$role_name, $role_code]);
            echo "<script>sessionStorage.setItem('showSuccess','تم إضافة الصلاحية بنجاح');window.location.href='../tables/view-permissions.php';</script>";
            exit;
        }
    }
}

$allRoles = $pdo->query("SELECT * FROM sys_roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>إضافة صلاحية | النظام</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
:root{--p:#1e4b8a;--p-lt:#e8eef8}
body{direction:rtl;text-align:right;font-family:'Source Sans Pro',Arial,sans-serif;background:#f4f6f9}
.form-card,.list-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden}
.card-head{padding:13px 18px;border-bottom:2px solid var(--p);background:var(--p-lt);display:flex;align-items:center;justify-content:space-between}
.card-head h6{margin:0;color:var(--p);font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px}
.card-head .ch-icon{width:30px;height:30px;border-radius:7px;background:var(--p);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.form-body{padding:20px}
.f-label{font-weight:600;font-size:.84rem;color:#555;margin-bottom:5px;display:block}
.f-input{width:100%;border:1.5px solid #dde3f0;border-radius:9px;padding:10px 14px;font-size:.88rem;transition:border-color .2s}
.f-input:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(30,75,138,.1)}
.f-group{margin-bottom:14px}
.f-hint{font-size:.74rem;color:#888;margin-top:4px}
.btn-add{width:100%;background:var(--p);color:#fff;border:none;border-radius:9px;padding:10px;font-size:.88rem;font-weight:700;margin-top:4px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .18s}
.btn-add:hover{background:#163870}
.btn-add:disabled{background:#aaa;cursor:not-allowed}
#roleTable th{background:#f0f4ff;border-bottom:2px solid var(--p)!important;text-align:center}
#roleTable td{vertical-align:middle;text-align:center}
.code-badge{background:#f0f4ff;color:var(--p);border-radius:6px;padding:3px 10px;font-size:.78rem;font-family:monospace;font-weight:700}
.dataTables_filter input{border-radius:8px;border:1.5px solid #dde3f0;padding:5px 12px;font-size:.84rem}
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
            <h4><i class="fas fa-shield-alt ml-2"></i>إدارة الصلاحيات</h4>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                <li class="breadcrumb-item"><a href="#">صلاحيات المستخدمين</a></li>
                <li class="breadcrumb-item active">إضافة صلاحية</li>
            </ol>
        </div>
    </div>
</section>
<section class="content">
<div class="container-fluid">
<div class="row">
    <!-- نموذج الإضافة -->
    <div class="col-lg-4 col-md-5 mb-4">
        <div class="form-card">
            <div class="card-head">
                <h6><div class="ch-icon"><i class="fas fa-plus"></i></div>إضافة صلاحية جديدة</h6>
            </div>
            <div class="form-body">
                <form method="POST">
                    <div class="f-group">
                        <label class="f-label">اسم الصلاحية <span style="color:#dc3545">*</span></label>
                        <input type="text" name="permission_name" class="f-input" placeholder="مثال: مشرف عمليات" required autocomplete="off">
                    </div>
                    <div class="f-group">
                        <label class="f-label">رمز الصلاحية <span style="color:#dc3545">*</span></label>
                        <input type="text" name="permission_code" class="f-input" placeholder="مثال: Supervisor" required autocomplete="off">
                        <span class="f-hint">يُستخدم داخلياً — يجب أن يكون فريداً</span>
                    </div>
                    <?php if ($can_add): ?>
                    <button type="submit" name="add_permission" class="btn-add"><i class="fas fa-plus"></i>إضافة الصلاحية</button>
                    <?php else: ?>
                    <button type="button" class="btn-add" disabled><i class="fas fa-lock"></i>لا تملك صلاحية الإضافة</button>
                    <?php endif; ?>
                </form>
                <?php if ($flash['msg']): ?>
                <div class="alert alert-<?= $flash['type']==='success'?'success':'danger' ?> mt-3 py-2 small"><?= htmlspecialchars($flash['msg']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- بطاقة معلومات -->
        <div class="form-card mt-3">
            <div class="card-head"><h6><div class="ch-icon" style="background:#6f42c1"><i class="fas fa-info"></i></div>ملاحظات</h6></div>
            <div class="form-body" style="padding:14px">
                <ul class="list-unstyled mb-0 small text-muted" style="line-height:2">
                    <li><i class="fas fa-circle text-primary ml-2" style="font-size:.45rem"></i>رمز الصلاحية لا يقبل التكرار</li>
                    <li><i class="fas fa-circle text-primary ml-2" style="font-size:.45rem"></i>بعد الإضافة يمكن تعيين المستخدمين للصلاحية</li>
                    <li><i class="fas fa-circle text-primary ml-2" style="font-size:.45rem"></i>يُفضَّل كتابة الرمز بالإنجليزية</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- قائمة الصلاحيات -->
    <div class="col-lg-8 col-md-7 mb-4">
        <div class="list-card">
            <div class="card-head">
                <h6><div class="ch-icon" style="background:#6f42c1"><i class="fas fa-list"></i></div>الصلاحيات المسجلة<span class="badge badge-primary badge-pill mr-1" style="font-size:.7rem"><?= count($allRoles) ?></span></h6>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table id="roleTable" class="table table-hover table-bordered" style="width:100%">
                        <thead><tr><th style="width:55px">#</th><th>اسم الصلاحية</th><th>رمز الصلاحية</th></tr></thead>
                        <tbody>
                            <?php foreach ($allRoles as $i=>$r): ?>
                            <tr><td><?= $i+1 ?></td>
                                <td style="text-align:right;padding-right:16px;font-weight:600"><?= htmlspecialchars($r['role_name']) ?></td>
                                <td><span class="code-badge"><?= htmlspecialchars($r['role_code']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($allRoles)): ?><tr><td colspan="3" class="text-center text-muted py-3">لا توجد صلاحيات مسجلة</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</section>
</div>
</div>
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
    try { $('#roleTable').DataTable({language:{emptyTable:'لا توجد بيانات', info:'عرض _START_ إلى _END_ من أصل _TOTAL_ سجل', infoEmpty:'عرض 0 إلى 0 من أصل 0 سجل', infoFiltered:'(منتقاة من _MAX_ سجل إجمالي)', lengthMenu:'عرض _MENU_ سجل في الصفحة', loadingRecords:'جارٍ التحميل...', processing:'جارٍ المعالجة...', search:'بحث:', zeroRecords:'لم يعثر على أية سجلات', paginate:{ first:'الأول', last:'الأخير', next:'التالي', previous:'السابق' }, aria:{ sortAscending:': تفعيل لترتيب العمود تصاعدياً', sortDescending:': تفعيل لترتيب العمود تنازلياً' }},order:[[0,'asc']],pageLength:15}); } catch(e) { console.warn('DataTables:', e.message); }
    <?php if($flash['msg'] && $flash['type']==='success'): ?>
    Swal.fire({icon:'success',title:'تمت العملية',text:'<?= addslashes($flash['msg']) ?>',timer:2500,showConfirmButton:false,timerProgressBar:true});
    <?php endif; ?>
});
</script>
</body></html>
