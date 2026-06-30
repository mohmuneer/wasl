<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/report-tasks.php"; 

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// 1. جلب بيانات النظام والمستخدم الحالي للطباعة
$current_user_name = "";
$userStmt = $pdo->prepare("SELECT full_name FROM sys_users WHERE id = ?");
$userStmt->execute([$current_user_id]);
$current_user_name = $userStmt->fetchColumn() ?: "مستخدم النظام";

$settingsStmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$company_name = $settings['system_name'] ?? 'إدارة الصيانة والتشغيل';
$company_address = $settings['address'] ?? 'المملكة العربية السعودية';
$company_logo = $settings['system_logo'] ?? 'logo.png';

// 2. جلب صلاحيات المستخدم
$can_add = 0; $can_edit = 0; $can_delete = 0;
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?: 0;

if ($current_page_id > 0) {
    $accessSql = "SELECT can_add, can_edit, can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_add = $permissions['can_add'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
}

// 3. استعلام جلب المهام
$sql = "SELECT t.*, 
               r.reporter_ref, r.location_name, r.details as req_details,
               b.branch_name, 
               c.region_name, 
               g.category_name,
               u.full_name as technician_name
        FROM work_orders t
        LEFT JOIN tickets r ON t.ticket_id = r.id
        LEFT JOIN branches b ON r.branch_id = b.id
        LEFT JOIN regions c ON r.region_id = c.id
        LEFT JOIN issue_categories g ON r.category_id = g.id
        LEFT JOIN sys_users u ON t.assigned_to = u.id
        ORDER BY t.created_at DESC";

$tasks = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>تقرير المهام</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">

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
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-size: 11px; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        #tasksReportTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #tasksReportTable td { vertical-align: middle; text-align: center; }
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
                        <h4><i class="fas fa-print ml-2"></i> تقارير المهام</h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                            <li class="breadcrumb-item active">تقارير المهام</li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="card card-ticket">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list ml-2"></i> جدول المهام المسندة</h5>
                        <?php if($can_add): ?>
                        <a href="add-task.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> إضافة مهمة جديدة
                        </a>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                        <table id="tasksReportTable" class="table table-bordered table-hover text-center w-100">
                            <thead class="bg-light">
                                <tr>
                                    <th>رقم المهمة</th>
                                    <th>المهندس المسند له</th>
                                    <th>الفرع/الكلية</th>
                                    <th>نوع المشكلة</th>
                                    <th>الأولوية</th>
                                    <th>الحالة</th>
                                    <th>تاريخ التسليم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): 
                                    $p_map = [
                                        'Urgent' => ['t' => 'حرج جداً', 'c' => 'badge-danger'],
                                        'High'     => ['t' => 'عالي', 'c' => 'badge-warning'],
                                        'Medium'   => ['t' => 'متوسط', 'c' => 'badge-info'],
                                        'Low'   => ['t' => 'عادي', 'c' => 'badge-secondary']
                                    ];
                                    $s_map = [
                                        'Pending'     => ['t' => 'قيد الانتظار', 'c' => 'badge-secondary'],
                                        'In Progress' => ['t' => 'قيد التنفيذ', 'c' => 'badge-primary'],
                                        'Resolved'   => ['t' => 'تم الإنجاز', 'c' => 'badge-success'],
                                        'Cancelled'   => ['t' => 'ملغي', 'c' => 'badge-danger']
                                    ];
                                    $p = $p_map[$task['priority']] ?? $p_map['Low'];
                                    $s = $s_map[$task['status']] ?? $s_map['Pending'];
                                ?>
                                <tr>
                                    <td><?= $task['id']; ?></td>
                                    <td><b class="text-primary"><?= htmlspecialchars($task['technician_name'] ?? 'غير محدد') ?></b></td>
                                    <td>
                                        <small>
                                            <?= htmlspecialchars($task['branch_name'] ?? '---') ?> <br>
                                            <?= htmlspecialchars($task['region_name'] ?? '---') ?>
                                        </small>
                                    </td>
                                    <td><span class="badge badge-dark"><?= htmlspecialchars($task['category_name'] ?? 'عام') ?></span></td>
                                    <td><span class="badge <?= $p['c'] ?>"><?= $p['t'] ?></span></td>
                                    <td><span class="badge <?= $s['c'] ?>"><?= $s['t'] ?></span></td>
                                    <td><small><?= !empty($task['deadline']) && $task['deadline'] !== '0000-00-00 00:00:00' ? date('Y-m-d', strtotime($task['deadline'])) : '—' ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>

    <script>
    $(document).ready(function() {
        var systemName = "<?= htmlspecialchars($company_name); ?>";
        var systemAddress = "<?= htmlspecialchars($company_address); ?>";
        var logoPath = "../../dist/img/<?= $company_logo; ?>"; 
        var sessionUserName = "<?= htmlspecialchars($current_user_name); ?>";
        var reportTitle = "تقرير المهام المسندة لموظفي الصيانة";
        const formattedDate = new Date().toLocaleDateString('ar-EG');

        $('#tasksReportTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Arabic.json" },
            "responsive": true,
            "autoWidth": false,
            "dom": "<'row mb-3'<'col-md-6'B><'col-md-6 text-left'f>>" +
                   "<'row'<'col-12'tr>>" +
                   "<'row'<'col-md-5'i><'col-md-7'p>>",
            "buttons": [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-columns"></i> إظهار/إخفاء الأعمدة',
                    className: 'btn btn-outline-secondary btn-sm'
                },
                {
                    extend: 'excelHtml5',
                    text: '<i class="fas fa-file-excel"></i> إكسل',
                    className: 'btn btn-outline-success btn-sm ml-1',
                    title: reportTitle
                },
                {
                    extend: 'print',
                    text: '<i class="fas fa-print"></i> طباعة التقرير',
                    className: 'btn btn-outline-primary btn-sm ml-1',
                    title: '',
                    exportOptions: { columns: ':visible' },
                    customize: function (win) {
                        $(win.document.body).css({'direction': 'rtl', 'text-align': 'right', 'background-color': '#fff'});
                        $(win.document.body).find('table').addClass('compact').css('font-size', '12pt');

                        // الهيدر الاحترافي (نفس صفحة البلاغات)
                        $(win.document.body).prepend(`
                            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:20px;">
                                <div style="text-align:right;">
                                    <h5 style="margin:0;">${systemName}</h5>
                                    <p style="margin:0; font-size:12px;">${systemAddress}</p>
                                </div>
                                <div><img src="${logoPath}" style="height:90px;"></div>
                                <div style="text-align:left;">
                                    <p style="margin:0;">التاريخ: ${formattedDate}</p>
                                    <p style="margin:0;">المستخدم: ${sessionUserName}</p>
                                </div>
                            </div>
                            <h3 style="text-align:center; background-color:#f4f4f4; padding:10px; border-radius:5px;">${reportTitle}</h3>
                        `);

                        // الفوتر
                        $(win.document.body).append(`
                            <div style="position:fixed; bottom:0; left:0; width:100%; text-align:center; font-size:10px; border-top:1px solid #ccc; padding-top:5px;">
                                صفحة (1) - صدر بواسطة ${systemName}
                            </div>
                        `);
                    }
                }
            ]
        });
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>