<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-group.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

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

$flash = ['type'=>'', 'msg'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_group'])) {
    $cat_name = trim($_POST['group_name'] ?? '');
    if (empty($cat_name)) {
        $flash = ['type'=>'error','msg'=>'الرجاء إدخال اسم التصنيف'];
    } elseif (!$can_add) {
        $flash = ['type'=>'error','msg'=>'ليس لديك صلاحية الإضافة'];
    } else {
        $ex = $pdo->prepare("SELECT COUNT(*) FROM issue_categories WHERE category_name=?");
        $ex->execute([$cat_name]);
        if ($ex->fetchColumn() > 0) {
            $flash = ['type'=>'error','msg'=>'التصنيف موجود مسبقاً'];
        } else {
            $pdo->prepare("INSERT INTO issue_categories (category_name) VALUES (?)")->execute([$cat_name]);
            $flash = ['type'=>'success','msg'=>'تم إضافة التصنيف بنجاح'];
        }
    }
}

$allGroups = $pdo->query("SELECT * FROM issue_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$total = count($allGroups);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة تصنيفات المشاكل | النظام</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
:root{--p:#1e4b8a;--p-lt:#e8eef8;--orange:#e67e22}
body{direction:rtl;text-align:right;font-family:'Source Sans Pro',Arial,sans-serif;background:#f4f6f9}
.form-card,.list-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden}
.card-head{padding:13px 18px;border-bottom:2px solid var(--p);background:var(--p-lt);display:flex;align-items:center;justify-content:space-between}
.card-head h6{margin:0;color:var(--p);font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px}
.card-head .ch-icon{width:30px;height:30px;border-radius:7px;background:var(--p);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.form-body{padding:18px}
.f-label{font-weight:600;font-size:.84rem;color:#555;margin-bottom:5px;display:block}
.f-input{width:100%;border:1.5px solid #dde3f0;border-radius:9px;padding:10px 14px;font-size:.88rem;transition:border-color .2s}
.f-input:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(30,75,138,.1)}
.f-group{margin-bottom:14px}
.btn-add{width:100%;background:var(--p);color:#fff;border:none;border-radius:9px;padding:10px;font-size:.88rem;font-weight:700;margin-top:4px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .18s}
.btn-add:hover{background:#163870}
.btn-add:disabled{background:#aaa;cursor:not-allowed}
.stat-box{background:linear-gradient(135deg,var(--orange),#f39c12);border-radius:10px;padding:14px 16px;color:#fff;margin-top:14px;display:flex;align-items:center;gap:12px}
.stat-box .sb-num{font-size:1.8rem;font-weight:800;line-height:1}
.stat-box .sb-lbl{font-size:.78rem;opacity:.9}
#catTable th{background:#f0f4ff;border-bottom:2px solid var(--p)!important;text-align:center}
#catTable td{vertical-align:middle;text-align:center}
.cat-name-cell{font-weight:600;text-align:right!important;padding-right:16px!important}
.btn-act{width:32px;height:32px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;transition:opacity .15s}
.btn-act:hover{opacity:.82}
.btn-edit{background:#ffc107;color:#212529}
.btn-del{background:#dc3545;color:#fff}
.btn-dis{background:#e9ecef;color:#aaa;cursor:not-allowed}
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
            <h4><i class="fas fa-tags ml-2"></i>إدارة تصنيفات المشاكل</h4>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                <li class="breadcrumb-item"><a href="#">هيكلة البلاغات</a></li>
                <li class="breadcrumb-item active">التصنيفات</li>
            </ol>
        </div>
    </div>
</section>
<section class="content">
<div class="container-fluid">
<div class="row">
    <!-- نموذج -->
    <div class="col-lg-3 col-md-4 mb-4">
        <div class="form-card">
            <div class="card-head"><h6><div class="ch-icon"><i class="fas fa-plus"></i></div>إضافة تصنيف جديد</h6></div>
            <div class="form-body">
                <form method="POST">
                    <div class="f-group">
                        <label class="f-label">اسم التصنيف <span style="color:#dc3545">*</span></label>
                        <input type="text" name="group_name" class="f-input" placeholder="مثال: أعطال الشبكة" required autocomplete="off">
                    </div>
                    <?php if ($can_add): ?>
                    <button type="submit" name="add_group" class="btn-add"><i class="fas fa-plus"></i>إضافة التصنيف</button>
                    <?php else: ?>
                    <button type="button" class="btn-add" disabled><i class="fas fa-lock"></i>لا تملك صلاحية الإضافة</button>
                    <?php endif; ?>
                </form>
                <?php if ($flash['msg']): ?>
                <div class="alert alert-<?= $flash['type']==='success'?'success':'danger' ?> mt-3 py-2 small"><?= htmlspecialchars($flash['msg']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-box">
            <i class="fas fa-tags" style="font-size:1.8rem;opacity:.8"></i>
            <div><div class="sb-num"><?= $total ?></div><div class="sb-lbl">تصنيف مسجل</div></div>
        </div>
    </div>
    <!-- قائمة -->
    <div class="col-lg-9 col-md-8 mb-4">
        <div class="list-card">
            <div class="card-head">
                <h6><div class="ch-icon" style="background:var(--orange)"><i class="fas fa-list"></i></div>قائمة التصنيفات<span class="badge badge-primary badge-pill mr-1" style="font-size:.7rem"><?= $total ?></span></h6>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table id="catTable" class="table table-hover table-bordered" style="width:100%">
                        <thead><tr><th style="width:55px">#</th><th>اسم التصنيف</th><th style="width:110px">الإجراءات</th></tr></thead>
                        <tbody>
                            <?php foreach ($allGroups as $i=>$g): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td class="cat-name-cell"><i class="fas fa-tag text-warning ml-2" style="font-size:.75rem"></i><?= htmlspecialchars($g['category_name']) ?></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center">
                                        <?php if ($can_edit): ?>
                                        <button class="btn-act btn-edit edit-btn" data-id="<?= $g['id'] ?>" data-name="<?= htmlspecialchars($g['category_name'],ENT_QUOTES) ?>" title="تعديل"><i class="fas fa-edit" style="font-size:.78rem"></i></button>
                                        <?php else: ?><span class="btn-act btn-dis"><i class="fas fa-edit" style="font-size:.78rem"></i></span><?php endif; ?>
                                        <?php if ($can_delete): ?>
                                        <button class="btn-act btn-del del-btn" data-id="<?= $g['id'] ?>" data-name="<?= htmlspecialchars($g['category_name'],ENT_QUOTES) ?>" title="حذف"><i class="fas fa-trash" style="font-size:.78rem"></i></button>
                                        <?php else: ?><span class="btn-act btn-dis"><i class="fas fa-trash" style="font-size:.78rem"></i></span><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($allGroups)): ?><tr><td colspan="3" class="text-center text-muted py-4"><i class="fas fa-tags fa-2x mb-2 d-block" style="opacity:.25"></i>لا توجد تصنيفات</td></tr><?php endif; ?>
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

<!-- مودال التعديل -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content">
    <div class="modal-header" style="background:linear-gradient(135deg,#ffc107,#e0a800)">
        <h5 class="modal-title font-weight-bold"><i class="fas fa-edit ml-2"></i>تعديل التصنيف</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
    </div>
    <form action="edit-group.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="group_id" id="editId">
            <label class="f-label">اسم التصنيف <span style="color:#dc3545">*</span></label>
            <input type="text" name="group_name" id="editName" class="f-input" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-warning font-weight-bold"><i class="fas fa-save ml-1"></i>حفظ</button></div>
    </form>
</div></div></div>

<!-- مودال الحذف -->
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content">
    <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-exclamation-triangle ml-2"></i>تأكيد الحذف</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
    <form action="edit-group.php" method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="group_id" id="delId">
        <div class="modal-body text-center"><p class="mb-1">هل أنت متأكد من حذف تصنيف</p><strong class="text-danger" id="delName"></strong></div>
        <div class="modal-footer justify-content-center"><button type="button" class="btn btn-light btn-sm" data-dismiss="modal">إلغاء</button><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash ml-1"></i>حذف</button></div>
    </form>
</div></div></div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
    $('#catTable').DataTable({language:{url:'//cdn.datatables.net/plug-ins/1.13.6/i18n/ar.json'},order:[[0,'asc']],columnDefs:[{orderable:false,targets:[2]}],pageLength:15});
    $(document).on('click','.edit-btn',function(){$('#editId').val($(this).data('id'));$('#editName').val($(this).data('name'));$('#editModal').modal('show');});
    $(document).on('click','.del-btn',function(){$('#delId').val($(this).data('id'));$('#delName').text($(this).data('name'));$('#delModal').modal('show');});
    <?php if($flash['msg'] && $flash['type']==='success'): ?>
    Swal.fire({icon:'success',title:'تمت العملية',text:'<?= addslashes($flash['msg']) ?>',timer:2500,showConfirmButton:false,timerProgressBar:true});
    <?php endif; ?>
    var st=sessionStorage.getItem('swal_title');
    if(st){Swal.fire({title:st,text:sessionStorage.getItem('swal_text'),icon:sessionStorage.getItem('swal_icon'),timer:2500,showConfirmButton:false,timerProgressBar:true});sessionStorage.removeItem('swal_title');sessionStorage.removeItem('swal_text');sessionStorage.removeItem('swal_icon');}
});
</script>
</body></html>
