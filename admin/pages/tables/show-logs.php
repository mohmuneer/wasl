<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

// جلب السجلات من الأحدث إلى الأقدم
try {
    $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
    $error = "خطأ في قاعدة البيانات: " . $e->getMessage();
}
// جلب معلومات الشركة للطباعة
$settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$company_name = $settings['system_name'] ?? 'Company Name';
$company_name_en = $settings['system_name_en'] ?? $company_name;
$company_logo = $settings['system_logo'] ?? 'default-logo.png';
$company_address = $settings['address'] ?? 'Address';

$logo_path_internal = __DIR__ . '/../../dist/img/' . $company_logo;
$logo_data_uri = '';
if (file_exists($logo_path_internal)) {
    $logo_base64 = base64_encode(file_get_contents($logo_path_internal));
    $logo_data_uri = 'data:image/png;base64,' . $logo_base64;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>سجل النشاطات</title>
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
        .card-ticket .card-body { padding: 20px; }
        #logsTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #logsTable td { vertical-align: middle; text-align: center; font-size: .9rem; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-weight: 600; border-radius: 20px; font-size: .8rem; }
        .log-page { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; direction: ltr; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <?php include(__DIR__ . '/../../main-header.php'); ?>
    <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="uni-header" style="background:linear-gradient(135deg,#1e4b8a,#0d2f5e);border-radius:14px;box-shadow:0 6px 20px rgba(30,75,138,.3)">
                    <h4><i class="fas fa-history ml-2"></i> سجل نشاطات النظام</h4>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                        <li class="breadcrumb-item active">سجل النشاطات</li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="card card-ticket">
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table id="logsTable" class="table table-hover table-bordered" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>المستخدم</th>
                                        <th>العملية</th>
                                        <th>الصفحة</th>
                                        <th>IP</th>
                                        <th>التاريخ والوقت</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($logs)): ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?= $log['id'] ?></td>
                                                <td><span class="badge badge-info badge-pill-custom"><?= htmlspecialchars($log['user_name'] ?? 'غير معروف') ?></span></td>
                                                <td><span class="badge badge-secondary badge-pill-custom"><?= htmlspecialchars($log['action']) ?></span></td>
                                                <td><span class="log-page" title="<?= htmlspecialchars($log['page_url']) ?>"><?= htmlspecialchars($log['page_url']) ?></span></td>
                                                <td><code><?= $log['ip_address'] ?></code></td>
                                                <td><small><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted">لا توجد سجلات حالياً</td></tr>
                                    <?php endif; ?>
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
var systemName = "<?= $company_name ?>";
var systemNameEn = "<?= addslashes($company_name_en) ?>";
var systemAddress = "<?= $company_address ?>";
var logoDataUri = "<?= $logo_data_uri ?>";

$(document).ready(function() {
    var currentDate = new Date().toLocaleDateString('ar-EG');

    $('#logsTable').DataTable({
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
                    $(win.document.body).css({direction: 'rtl', 'text-align': 'right'});
                    $(win.document.body).prepend(
                        '<div style="display:table;width:100%;margin-bottom:20px;border-bottom:2px solid #333;padding-bottom:10px;">' +
                        '<div style="display:table-cell;width:33.33%;vertical-align:middle;">' +
                        '<h4 style="margin:0;">' + systemName + '</h4><p>' + systemAddress + '</p></div>' +
                        '<div style="display:table-cell;width:33.33%;text-align:center;vertical-align:middle;">' +
                        '<img src="' + logoDataUri + '" style="height:80px;"></div>' +
                        '<div style="display:table-cell;width:33.33%;text-align:left;vertical-align:middle;">' +
                        '<h3 style="margin:0;">سجل النشاطات</h3>' +
                        '<p>تاريخ الطباعة: ' + currentDate + '</p></div></div>'
                    );
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
                className: 'btn btn-outline-secondary btn-sm'
            }
        ],
        order: [[0, 'desc']],
        language: {
            search: 'بحث:',
            lengthMenu: 'عرض _MENU_ سجلات',
            info: '_START_ إلى _END_ من _TOTAL_',
            infoEmpty: '0 سجل',
            infoFiltered: '(من أصل _MAX_)',
            paginate: { next: 'التالي', previous: 'السابق' },
            emptyTable: 'لا توجد سجلات',
            buttons: { print: 'طباعة', colvis: 'الأعمدة' }
        }
    });
});
</script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>