<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-users.php"; // المسار المعتمد في جدول sys_menu

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// 1. جلب id الصفحة ديناميكياً من جدول sys_menu
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);

$current_page_id = $menu_item['id'] ?? 0;

// 2. جلب صلاحية الإضافة من جدول user_menu_access باستخدام الـ id المستخرج
$can_add = 0;
$can_edit = 0;
$can_delete = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_add,can_edit,can_delete FROM user_menu_access 
                  WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    
    $can_add = $permissions['can_add'] ?? 0;
    $can_edit = $permissions['can_edit'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
}
// استعلام مطور يجلب البيانات مع تجميع الأدوار في حال تعددها (اختياري)
$sql = "SELECT u.*, r.branch_name, up.branch_id 
        FROM sys_users u
        LEFT JOIN user_branch_access up ON u.id = up.user_id
        LEFT JOIN branches r ON up.branch_id = r.id";
$users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// معالجة حذف مستخدم
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];

    $stmt_img = $pdo->prepare("SELECT file_path FROM sys_users WHERE id=?");
    $stmt_img->execute([$delete_id]);
    $img = $stmt_img->fetchColumn();

    if ($img && file_exists("../../../uploads/" . $img)) {
        unlink("../../../uploads/" . $img);
    }

    $stmt = $pdo->prepare("DELETE FROM sys_users WHERE id=?");
    $stmt->execute([$delete_id]);

    // تخزين رسالة نجاح في السشن لعرضها بعد التحويل
    $_SESSION['success_msg'] = "تم حذف المستخدم بنجاح";
    header("Location: ../tables/show-users.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>عرض المستخدمين</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        .card-ticket {
            border: none;
            border-top: 4px solid var(--uni-primary);
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            border-radius: 10px;
        }
        .card-ticket .card-header {
            background: transparent;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        .card-ticket .card-body { padding: 20px; }
        #usersTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #usersTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <?php include(__DIR__ . '/../../main-header.php'); ?>
    <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="uni-header">
                    <h4><i class="fas fa-users ml-2"></i> عرض بيانات المستخدمين</h4>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                        <li class="breadcrumb-item active">المستخدمين</li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="card card-ticket">
                    <div class="card-header">
                        <?php if ($can_add == 1): ?>
                            <a href="../forms/add-user.php" class="btn btn-primary btn-sm"><i class="fas fa-plus ml-1"></i> إضافة مستخدم</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="usersTable" class="table table-hover table-bordered" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>اسم المستخدم</th>
                                        <th>البريد الإلكتروني</th>
                                        <th>الصورة</th>
                                        <th>الفرع</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><?= htmlspecialchars($user['full_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td>
                                                <?php
                                                $upload_path = "../../../uploads/";
                                                $user_image = $user['file_path'];
                                                if (!empty($user_image) && file_exists($upload_path . $user_image)): ?>
                                                    <img src="<?= $upload_path . htmlspecialchars($user_image) ?>" class="img-circle elevation-2" width="40" height="40" style="object-fit:cover;" alt="User Image">
                                                <?php else: ?>
                                                    <img src="../../dist/img/avatar5.png" class="img-circle elevation-2" width="40" height="40" alt="Default Avatar">
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($user['branch_name'] ?? 'بدون فرع') ?></td>
                                            <td>
                                                <?php if ($user['status'] == 'active'): ?>
                                                    <span class="badge badge-success badge-pill" style="padding:6px 14px;">نشط</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary badge-pill" style="padding:6px 14px;">موقف</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-center" style="gap:4px;">
                                                    <?php if ($can_edit == 1): ?>
                                                        <a class="btn btn-warning btn-sm btn-action" href="edit-user.php?id=<?= $user['id'] ?>" title="تعديل"><i class="fas fa-edit"></i></a>
                                                    <?php else: ?>
                                                        <button class="btn btn-secondary btn-sm btn-action disabled"><i class="fas fa-edit"></i></button>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete == 1): ?>
                                                        <a href="#" class="btn btn-danger btn-sm btn-action delete-btn" data-id="<?= $user['id'] ?>" title="حذف"><i class="fas fa-trash"></i></a>
                                                    <?php else: ?>
                                                        <button class="btn btn-secondary btn-sm btn-action disabled"><i class="fas fa-trash"></i></button>
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
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    var table = $('#usersTable').DataTable({
        dom: "<'row mb-3'<'col-md-12'f>>" +
             "<'row'<'col-12'tr>>" +
             "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
        order: [[0, 'asc']],
        language: {
            search: 'بحث:',
            lengthMenu: 'عرض _MENU_ سجلات',
            info: '_START_ إلى _END_ من _TOTAL_',
            infoEmpty: '0 سجل',
            infoFiltered: '(من أصل _MAX_)',
            paginate: { next: 'التالي', previous: 'السابق' },
            emptyTable: 'لا توجد بيانات'
        }
    });

    // رسائل الخطأ من sessionStorage
    var errorMsg = sessionStorage.getItem('showError');
    if (errorMsg) {
        Swal.fire({ icon: 'error', title: 'تنبيه!', text: errorMsg, confirmButtonText: 'موافق' });
        sessionStorage.removeItem('showError');
    }

    var successMsg = sessionStorage.getItem('showSuccess');
    if (successMsg) {
        Swal.fire({ icon: 'success', title: 'تمت العملية', text: successMsg, confirmButtonText: 'ممتاز' });
        sessionStorage.removeItem('showSuccess');
    }

    // تأكيد الحذف
    $('.delete-btn').on('click', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        Swal.fire({
            title: 'هل أنت متأكد؟',
            text: 'لن تتمكن من التراجع عن حذف هذا المستخدم!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'إلغاء'
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = 'delete-user.php?id=' + userId;
            }
        });
    });
});
</script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>