<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-cstmr.php"; 

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// 1. جلب اسم المستخدم من قاعدة البيانات (حسب الجلسة)
$userSql = "SELECT full_name FROM sys_users WHERE id = ?"; 
$userStmt = $pdo->prepare($userSql);
$userStmt->execute([$current_user_id]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);
$current_user_name = $userData['full_name'] ?? "مستخدم غير معروف";

// 2. جلب id الصفحة وصلاحيات المستخدم
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);
$current_page_id = $menu_item['id'] ?? 0;

$can_add = 0; $can_edit = 0; $can_delete = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_add, can_edit, can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    
    $can_add = $permissions['can_add'] ?? 0;
    $can_edit = $permissions['can_edit'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
}

// 3. جلب بيانات العملاء
$sql = "SELECT c.*, b.branch_name, r.region_name 
        FROM clients c
        LEFT JOIN regions r ON c.location_id = r.id
        LEFT JOIN branches b ON r.branch_id = b.id
        LEFT JOIN departments d ON c.department_id = d.id
        ORDER BY c.id DESC";
$customers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll(PDO::FETCH_ASSOC);
$regions = $pdo->query("SELECT id, region_name FROM regions")->fetchAll(PDO::FETCH_ASSOC);
$settingsStmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$company_name = $settings['system_name'] ?? 'اسم الشركة الافتراضي';
$company_logo = $settings['system_logo'] ?? 'default-logo.png';
$company_address = $settings['address'] ?? 'default-logo.png';
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>تقارير الموظفين الداخليين</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- DataTables Buttons CSS -->
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
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-size: 11px; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        #customerTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #customerTable td { vertical-align: middle; text-align: center; }
        .filter-box { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-top: 3px solid var(--uni-primary); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <!-- تحسين الترويسة بناءً على image_56f1bd.png -->
           <section class="content-header">
                <div class="container-fluid">
                    <div class="uni-header">
                        <h4><i class="fas fa-print ml-2"></i> تقارير بيانات الموظفين الداخليين</h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                            <li class="breadcrumb-item active">بيانات الموظفين</li>
                        </ol>
                    </div>
                </div>
            </section>
            <section class="content">
                <div class="container-fluid">
                    
                    <!-- فلاتر البحث -->
                    <div class="filter-box p-3 border rounded bg-light">
    <div class="row align-items-end">

        <!-- الدولة -->
        <div class="col-md-4">
            <label class="form-label">
                <i class="fas fa-globe ml-1"></i> الفلترة حسب الدولة
            </label>
            <select id="filter-college" class="form-control select2">
                <option value="">الكل</option>
                <?php foreach($regions as $col): ?>
                    <option value="<?= htmlspecialchars($col['region_name']) ?>">
                        <?= htmlspecialchars($col['region_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- الفرع -->
        <div class="col-md-4">
            <label class="form-label">
                <i class="fas fa-map-marker-alt ml-1"></i> الفلترة حسب الفرع
            </label>
            <select id="filter-branch" class="form-control select2">
                <option value="">الكل</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?= htmlspecialchars($b['branch_name']) ?>">
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- زر إعادة التعيين -->
        <div class="col-md-4 text-md-end text-center">
            <label class="form-label d-block opacity-0">.</label>
            <button id="resetFilters" class="btn btn-secondary btn-sm w-100">
                <i class="fas fa-undo ml-1"></i> إعادة تعيين الفلترة
            </button>
        </div>

    </div>
</div>

                    <div class="card card-ticket">
                       <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><i class="fas fa-list ml-2"></i> قائمة الموظفين الداخليين المسجلين</h5>

    <?php if ($can_add == 1): ?>
        <a href="../forms/add-cstmr.php" class="btn btn-success btn-sm shadow-sm">
            <i class="fas fa-plus"></i> إضافة موظف جديد
        </a>
    <?php endif; ?>
</div>

                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="customerTable" class="table table-hover table-bordered text-center">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>الرقم</th>
                                            <th>اسم الموظف</th>
                                            <th>الدولة</th>
                                            <th>الفرع</th>
                                            <th>الهاتف</th>
                                            <th>الحالة</th>
                                            <th>العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($customers as $c): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><?= htmlspecialchars($c['client_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($c['region_name'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($c['branch_name'] ?? '-') ?></td>
                                            <td style="direction: ltr; text-align: center;">
    <?= htmlspecialchars($c['phone']) ?>
</td>
                                            <td>
                                                <?php 
                                                    $val = $c['status'] ?? 'active';
                                                    $is_active = ($val === 'active');
                                                ?>
                                                <button onclick="toggleStatus(<?= $c['id'] ?>, '<?= $val ?>')" 
                                                        class="badge badge-pill-custom border-0 <?= $is_active ? 'badge-success' : 'badge-danger' ?>"
                                                        id="status-btn-<?= $c['id'] ?>">
                                                    <i class="fas <?= $is_active ? 'fa-check-circle' : 'fa-times-circle' ?> ml-1"></i>
                                                    <span class="status-text"><?= $is_active ? 'نشط' : 'موقف' ?></span>
                                                </button>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <?php if ($can_edit == 1): ?>
                                                        <a href="edit-customer.php?id=<?= $c['id'] ?>" class="btn btn-warning btn-sm btn-action">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($can_delete == 1): ?>
                                                        <button class="btn btn-danger btn-sm deleteBtn btn-action" data-id="<?= $c['id'] ?>" data-toggle="modal" data-target="#deleteModal">
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
    </div>

    <!-- Modal الحذف -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle ml-2"></i> تأكيد الحذف</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">هل أنت متأكد من حذف هذا الموظف؟ لا يمكن التراجع عن هذا الإجراء.</div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
                    <a href="#" id="confirmDelete" class="btn btn-danger">تأكيد الحذف</a>
                </div>
            </div>
        </div>
    </div>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- مكتبات التصدير والطباعة -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script>

    var systemName = "<?php echo htmlspecialchars($company_name ?? 'الشركة'); ?>";
    var systemAddress = "<?php echo htmlspecialchars($company_address ?? 'العنوان'); ?>";

    var logoPath = "/tlink/admin/dist/img/<?php echo $company_logo ?? 'default-logo.png'; ?>"; 

    var sessionUserName = "<?php echo htmlspecialchars($current_user_name); ?>";

    var title = "جدول بيانات الموظفين الداخليين";

$(document).ready(function() {
    const currentDate = new Date();
    const formattedDate = currentDate.toLocaleDateString('ar-EG');

    var table = $('#customerTable').DataTable({
        "language": { "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Arabic.json" },
        "responsive": true,
        "dom": 'Bfrtip',
        "buttons": [
            {
                extend: 'colvis',
                text: 'تحديد الأعمدة',
                className: 'btn btn-outline-secondary btn-sm'
            },
            {
                extend: 'excelHtml5',
                text: 'إكسل',
                className: 'btn btn-outline-success btn-sm ml-1',
                title: 'تقرير بيانات العملاء',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5] }
            },
            {
                extend: 'print',
                text: 'طباعة التقرير',
                className: 'btn btn-outline-primary btn-sm ml-1',
                title: '',
                exportOptions: { 
                    columns: function(idx) {
                        return idx !== 6;
                    }
                },
                customize: function (win) {

    $(win.document.body).css({
        'direction': 'rtl',
        'text-align': 'center',
        'padding': '15px'
    });

    // ✅ CSS ثابت
    var style = `
    <style>
    @media print {

        @page {
            margin: 20mm;
        }

        body {
            margin-bottom: 80px;
        }

        .print-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            padding: 10px 20px;
            border-top: 2px solid #000;
        }
    }

    .print-footer {
        margin-top: 40px;
        display: flex;
        justify-content: space-between;
        width: 100%;
    }
    </style>
    `;
    $(win.document.head).append(style);

    // ✅ الهيدر
    $(win.document.body).prepend(`
        <div style='display:flex; justify-content:space-between; align-items:center; height:150px;'>
            <div style='width:30%;'>
                <h5>العنوان: ${systemAddress}</h5>
            </div>
            <div style='width:40%; text-align:center;'>
                <img src='${logoPath}' style='height:200px;'>
            </div>
            <div style='width:30%;'>
                <h5><b>الشركة:${systemName}</b></h5>
            </div>
        </div>
        <hr>
        <div style='font-size:22px; font-weight:bold; margin:15px 0;'>${title}</div>
    `);

    // ✅ التذييل
    $(win.document.body).append(`
        <div class="print-footer">
            <div style="width:33%; text-align:right;">
                طُبع بواسطة: ${formattedDate}
            </div>
            <div style="width:33%; text-align:center;">
                <span class="page-number"></span>
            </div>
            <div style="width:33%; text-align:left;">
                طُبع بواسطة: ${sessionUserName}
            </div>
        </div>
    `);

    // ✅ تنسيق الجدول
    $(win.document.body).find('table').css({
        'font-size': '12pt',
        'width': '99%',
        'border': '1px solid #000'
    });

    // ✅ الحل الحقيقي لترقيم الصفحات
    setTimeout(function () {

        var rowsPerPage = 20; // 👈 عدل حسب طول الصفحة
        var rows = $(win.document.body).find('tbody tr');
        var totalPages = Math.ceil(rows.length / rowsPerPage);

        // 👇 عرض رقم الصفحة (صفحة 1 من X)
        $(win.document.body).find('.page-number')
            .text('صفحة 1 من ' + totalPages);

    }, 300);
}
            }
        ]
    });
    
    
    // =======================
// ✅ الفلترة
$('#filter-college').on('change', function () {
    table.column(2).search(this.value).draw();
});

$('#filter-branch').on('change', function () {
    table.column(3).search(this.value).draw();
});

// ✅ إعادة تعيين الفلاتر
$('#resetFilters').on('click', function () {
    $('#filter-college').val('');
    $('#filter-branch').val('');
    
    table.column(2).search('');
    table.column(3).search('');
    
    table.draw();
});
    
    
    
});

// حذف
$(document).on('click', '.deleteBtn', function() {
    var id = $(this).data('id');
    $('#confirmDelete').attr('href', 'delete-customer.php?id=' + id);
});
</script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>