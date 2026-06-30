<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_name = "مستخدم غير معروف";
if ($current_user_id) {
    $userSql = "SELECT full_name FROM sys_users WHERE id = ?"; 
    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute([$current_user_id]);
    $current_user_name = $userStmt->fetchColumn() ?: "مستخدم غير معروف";
}

$settingsStmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$company_name = $settings['system_name'] ?? 'إدارة النظام';
$company_logo = $settings['system_logo'] ?? 'logo.png';
$company_address = $settings['address'] ?? 'المملكة العربية السعودية';

$sql = "
    SELECT 
        u.id, 
        u.full_name, 
        u.email, 
        u.status, 
        GROUP_CONCAT(r.role_name SEPARATOR ' | ') AS all_roles
    FROM sys_users u
    LEFT JOIN user_roles up ON u.id = up.user_id
    LEFT JOIN sys_roles r ON up.role_id = r.id
    GROUP BY u.id
    ORDER BY u.id ASC
";

try {
    $users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>تقرير المستخدمين</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
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
        #usersReportTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #usersReportTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-size: 11px; }
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
                    <h4><i class="fas fa-print ml-2"></i> تقارير المستخدمين</h4>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                        <li class="breadcrumb-item active">تقارير المستخدمين</li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="card card-ticket">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list ml-2"></i> قائمة مستخدمي النظام وصلاحياتهم</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="usersReportTable" class="table table-hover table-bordered" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الاسم الكامل</th>
                                        <th>البريد الإلكتروني</th>
                                        <th>الأدوار / الصلاحيات</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= $i++; ?></td>
                                        <td><strong class="text-dark"><?= htmlspecialchars($user['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><span class="text-muted"><?= htmlspecialchars($user['all_roles'] ?: 'غير محدد') ?></span></td>
                                        <td>
                                            <?php if ($user['status'] == 'active'): ?>
                                                <span class="badge badge-success badge-pill-custom"><i class="fas fa-check ml-1"></i> نشط</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger badge-pill-custom"><i class="fas fa-times ml-1"></i> موقف</span>
                                            <?php endif; ?>
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
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>

<script>
$(document).ready(function() {
    var systemName = "<?= htmlspecialchars($company_name); ?>";
    var systemAddress = "<?= htmlspecialchars($company_address); ?>";
    var logoPath = "../../dist/img/<?= $company_logo; ?>"; 
    var sessionUserName = "<?= htmlspecialchars($current_user_name); ?>";
    var reportTitle = "تقرير مستخدمي النظام والصلاحيات";
    var formattedDate = new Date().toLocaleDateString('ar-EG');

    $('#usersReportTable').DataTable({
        dom: "<'row mb-3'<'col-md-6'B><'col-md-6 text-left'f>>" +
             "<'row'<'col-12'tr>>" +
             "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
        buttons: [
            {
                extend: 'colvis',
                text: '<i class="fas fa-columns ml-1"></i> تخصيص الأعمدة',
                className: 'btn btn-outline-secondary btn-sm'
            },
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel ml-1"></i> إكسل',
                className: 'btn btn-outline-success btn-sm ml-1',
                title: reportTitle,
                exportOptions: { columns: ':visible' }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print ml-1"></i> طباعة التقرير',
                className: 'btn btn-outline-primary btn-sm ml-1',
                title: '',
                exportOptions: { columns: ':visible' },
                customize: function (win) {
                    $(win.document.body).css({'direction': 'rtl', 'text-align': 'right', 'background-color': '#fff'});
                    $(win.document.body).find('table').addClass('compact').css('font-size', '12pt');

                    $(win.document.body).prepend(`
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:20px;">
                            <div style="text-align:right; width:33%;">
                                <h5 style="margin:0;">${systemName}</h5>
                                <p style="margin:0; font-size:12px;">${systemAddress}</p>
                            </div>
                            <div style="text-align:center; width:33%;">
                                <img src="${logoPath}" style="height:90px;">
                            </div>
                            <div style="text-align:left; width:33%;">
                                <p style="margin:0;">التاريخ: ${formattedDate}</p>
                                <p style="margin:0;">المستخدم: ${sessionUserName}</p>
                            </div>
                        </div>
                        <h3 style="text-align:center; background-color:#f4f4f4; padding:10px; border-radius:5px;">${reportTitle}</h3>
                    `);

                    $(win.document.body).append(`
                        <div style="position:fixed; bottom:0; left:0; width:100%; text-align:center; font-size:10px; border-top:1px solid #ccc; padding-top:5px;">
                            تقرير مستخرج من النظام الإلكتروني - ${systemName}
                        </div>
                    `);
                }
            }
        ],
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
});
</script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
