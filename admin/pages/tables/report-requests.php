<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

// 1. جلب بيانات المستخدم للجلسة (لإظهار اسم الطابع في التذييل)
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_name = "مستخدم غير معروف";
if ($current_user_id) {
    $userSql = "SELECT full_name FROM sys_users WHERE id = ?"; 
    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute([$current_user_id]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    $current_user_name = $userData['full_name'] ?? "مستخدم غير معروف";
}

// 2. جلب إعدادات النظام (اللوجو والاسم)
$settingsStmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$company_name = $settings['system_name'] ?? 'اسم الشركة';
$company_logo = $settings['system_logo'] ?? 'default-logo.png';
$company_address = $settings['address'] ?? 'العنوان المكتبي';

// 3. تجهيز الشعار base64 للطباعة
$logo_path_internal = __DIR__ . '/../../dist/img/' . $company_logo;
$logo_data_uri = '';
if (file_exists($logo_path_internal)) {
    $logo_data = file_get_contents($logo_path_internal);
    $logo_base64 = base64_encode($logo_data);
    $logo_data_uri = 'data:image/png;base64,' . $logo_base64;
}

// 4. استعلام البلاغات
$sql = "SELECT r.id, r.reporter_ref, r.location_name, r.details, r.priority, r.status, r.created_at,
               b.branch_name, 
               c.region_name, 
               g.category_name 
        FROM tickets r
        LEFT JOIN branches b ON r.branch_id = b.id
        LEFT JOIN regions c ON r.region_id = c.id
        LEFT JOIN issue_categories g ON r.category_id = g.id
        ORDER BY r.created_at DESC";

$requests = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>تقارير الطلبات</title>
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
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-ticket .card-body { padding: 20px; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-weight: 600; border-radius: 20px; font-size: .8rem; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        #requestsTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #requestsTable td { vertical-align: middle; text-align: center; }
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
                        <h4><i class="fas fa-print ml-2"></i> تقارير الطلبات</h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                            <li class="breadcrumb-item active">تقارير الطلبات</li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <div class="card card-ticket">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list ml-2"></i> جدول الطلبات المسجلة</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="requestsTable" class="table table-bordered table-hover text-center">
                                    <thead>
                                        <tr>
                                            <th>رقم الطلب</th>
                                            <th>مقدم البلاغ</th>
                                            <th>الفرع/المنطقة</th>
                                            <th>المكان</th>
                                            <th>نوع المشكلة</th>
                                            <th>الأولوية</th>
                                            <th>الحالة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requests as $req): 
                                            $priority_map = [
                                                'Urgent' => ['text' => 'طارئ', 'class' => 'badge-danger'],
                                                'High' => ['text' => 'عاجل', 'class' => 'badge-warning'],
                                                'Medium' => ['text' => 'متوسط', 'class' => 'badge-info'],
                                                'Low' => ['text' => 'عادي', 'class' => 'badge-secondary']
                                            ];
                                            $p_attr = $priority_map[$req['priority']] ?? $priority_map['Low'];
                                            $status_map = [
                                                'Pending' => ['text' => 'قيد الانتظار', 'class' => 'badge-secondary'],
                                                'In Progress' => ['text' => 'قيد التنفيذ', 'class' => 'badge-primary'],
                                                'Resolved' => ['text' => 'تم الإنجاز', 'class' => 'badge-success'],
                                                'Cancelled' => ['text' => 'ملغي', 'class' => 'badge-danger']
                                            ];
                                            $s_attr = $status_map[$req['status']] ?? $status_map['Pending'];
                                        ?>
                                        <tr>
                                            <td><strong><?= $req['id']; ?></strong></td>
                                            <td><?= htmlspecialchars($req['reporter_ref'] ?? $req['id']) ?></td>
                                            <td><?= htmlspecialchars($req['branch_name'] ?? '-') ?> / <?= htmlspecialchars($req['region_name'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($req['location_name'] ?? '-') ?></td>
                                            <td><span class="badge badge-dark badge-pill-custom"><?= htmlspecialchars($req['category_name'] ?? '-') ?></span></td>
                                            <td><span class="badge badge-pill-custom <?= $p_attr['class'] ?>"><?= $p_attr['text'] ?></span></td>
                                            <td><span class="badge badge-pill-custom <?= $s_attr['class'] ?>"><?= $s_attr['text'] ?></span></td>
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
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>

    <script>
    $(document).ready(function() {
        var reportTitle = "تقرير طلبات الصيانة والبلاغات";
        var formattedDate = new Date().toLocaleDateString('ar-EG');

        $('#requestsTable').DataTable({
            responsive: true,
            autoWidth: false,
            order: [[0, 'desc']],
            dom: "<'row mb-3'<'col-md-6'B><'col-md-6 text-left'f>>" +
                 "<'row'<'col-12'tr>>" +
                 "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
            buttons: [
                {
                    extend: 'print',
                    text: '<i class="fas fa-print ml-1"></i> طباعة',
                    className: 'btn btn-outline-primary btn-sm ml-1',
                    exportOptions: { columns: ':visible' },
                    customize: function(win) {
                        $(win.document.body)
                            .css('direction', 'rtl')
                            .css('text-align', 'right')
                            .css('font-family', 'Cairo, sans-serif');
                        $(win.document.body).find('table')
                            .addClass('table-bordered')
                            .css('width', '100%');
                        var header = '';
                        <?php if ($logo_data_uri): ?>
                        header += '<div style="text-align:center;margin-bottom:20px;">';
                        header += '<img src="<?= $logo_data_uri ?>" style="max-height:80px;" />';
                        <?php endif; ?>
                        header += '<h2 style="margin:10px 0 5px;color:#0d6efd;"><?= $company_name ?></h2>';
                        header += '<p style="color:#555;margin:0 0 20px;"><?= $company_address ?></p>';
                        header += '<hr style="border-top:2px solid #0d6efd;">';
                        header += '<h4 style="margin:15px 0;">' + reportTitle + '</h4>';
                        header += '</div>';
                        $(win.document.body).find('h1').remove();
                        $(win.document.body).prepend(header);
                    }
                },
                {
                    extend: 'excelHtml5',
                    text: '<i class="fas fa-file-excel ml-1"></i> Excel',
                    className: 'btn btn-outline-success btn-sm ml-1',
                    exportOptions: { columns: ':visible' }
                },
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-columns ml-1"></i> أعمدة',
                    className: 'btn btn-outline-secondary btn-sm',
                    postfixButtons: ['colvisRestore']
                }
            ],
            language: {
                search: 'بحث:',
                lengthMenu: 'عرض _MENU_ سجلات',
                info: '_START_ إلى _END_ من _TOTAL_',
                infoEmpty: '0 سجل',
                infoFiltered: '(من أصل _MAX_)',
                paginate: { next: 'التالي', previous: 'السابق' },
                emptyTable: 'لا توجد بلاغات',
                buttons: {
                    print: 'طباعة',
                    excel: 'Excel',
                    colvis: 'أعمدة'
                }
            }
        });

        if (sessionStorage.getItem('wasl_fullscreen') === 'true') {
            $('body').addClass('sidebar-collapse');
            $('body').addClass('wasl-fullscreen');
        }
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>