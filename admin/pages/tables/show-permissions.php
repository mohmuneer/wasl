<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

// استعلام مطور يجلب البيانات مع تجميع الأدوار في حال تعددها (اختياري)
$sql = "SELECT u.*, r.role_name, up.role_id 
        FROM sys_users u
        LEFT JOIN user_roles up ON u.id = up.user_id
        LEFT JOIN sys_roles r ON up.role_id = r.id";
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
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Show-Users</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <!-- Tempusdominus Bbootstrap 4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tempusdominus-bootstrap-4@5.39.0/build/css/tempusdominus-bootstrap-4.min.css">
    <!-- iCheck -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/icheck-bootstrap/3.0.1/icheck-bootstrap.min.css">
    <!-- JQVMap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jqvmap@1.5.1/dist/jqvmap.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <!-- overlayScrollbars -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@1.13.3/css/OverlayScrollbars.min.css">
    <!-- Daterange picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css">
    <!-- summernote -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css">
    <!-- Google Font: Source Sans Pro -->
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
    <!-- Bootstrap 4 RTL -->
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <!-- Custom style for RTL -->
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
    <style>
        /* إخفاء شريط التمرير الأفقي والعمودي نهائياً */
        html,
        body {
            overflow-x: hidden !important;
            /* يمنع التمرير العرضي الذي يظهر في الصورة */
            scrollbar-width: none !important;
            /* Firefox */
            -ms-overflow-style: none !important;
            /* IE/Edge */
        }

        /* لمتصفحات Chrome و Safari */
        ::-webkit-scrollbar {
            display: none !important;
            width: 0px !important;
            background: transparent !important;
        }

        /* إخفاء أشرطة مكتبة OverlayScrollbars الخاصة بالقالب */
        .os-scrollbar,
        .os-scrollbar-horizontal,
        .os-scrollbar-vertical {
            display: none !important;
            visibility: hidden !important;
        }

        /* منع ظهور الفراغ الأبيض في أسفل الصفحة */
        .wrapper {
            overflow-x: hidden !important;
        }

        .dataTables_filter {
            text-align: right !important;
        }

        .dataTables_filter input {
            width: 30%;
            /* غيّر الرقم كما تريد */
            border-radius: 20px;
            padding: 5px 15px;
        }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">


        <?php include(__DIR__ . '/../../main-header.php'); ?>

        <!-- Main Sidebar Container -->

        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>


        <div class="content-wrapper">
            <!-- Content Header -->
            <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1>عرض بيانات المستخدمين</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                                <li class="breadcrumb-item active">المستخدمين</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Main content -->
            <section class="content">
                <div class="row">
                    <div class="col-12">
                        <div class="card card-primary">
                            <div class="card-header breadcrumb float-sm-right">
                                <a href="../forms/add-user.php" class="btn btn-primary btn-sm"
                                    style="font-weight: bold;">
                                    <i class="fas fa-plus"></i> إضافة مستخدم
                                </a>
                            </div>

                            <div class="card-body">
                                <table id="example1" class="table table-bordered table-striped text-center">
                                    <thead>
                                        <tr>
                                            <th>الرقم</th>
                                            <th>اسم الصلاحية</th>
                                            <th> كود الصلاحية</th>
                                            <th>الحالة</th>
                                            <th>تعديل</th>
                                            <th>حذف</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1;
                                        foreach ($users as $user): ?>
                                            <tr>
                                                <td><?= $i++; ?></td>
                                                <td><?= htmlspecialchars($user['role_name']) ?></td>
                                                <td><?= htmlspecialchars($user['email']) ?></td>
                                                <td>
                                                    <?php
                                                    $upload_path = "../../../uploads/";
                                                    $user_image = $user['file_path'];
                                                    if (!empty($user_image) && file_exists($upload_path . $user_image)): ?>
                                                        <img src="<?= $upload_path . htmlspecialchars($user_image) ?>"
                                                            class="img-circle elevation-2" width="40" height="40"
                                                            style="object-fit: cover;" alt="User Image">
                                                    <?php else: ?>
                                                        <img src="../../dist/img/avatar5.png" class="img-circle elevation-2"
                                                            width="40" height="40" alt="Default Avatar">
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span
                                                        class="badge <?= ($user['role_id'] == 1) ? 'badge-danger' : 'badge-info' ?>">
                                                        <?= htmlspecialchars($user['role_name'] ?? 'بدون دور') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($user['status'] == 'active'): ?>
                                                        <span class="badge badge-success">نشط</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">موقف</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a class="btn btn-sm" style="background-color:#ffc107; color:black"
                                                        href="edit-user.php?id=<?= $user['id'] ?>">
                                                        <i class="fas fa-edit"><strong
                                                                style="margin: 0px 4px">تعديل</strong></i>
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="#" class="btn btn-danger btn-sm delete-btn"
                                                        data-id="<?= $user['id'] ?>">
                                                        <i class="fas fa-trash"></i> حذف
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th>الرقم</th>
                                            <th>اسم الصلاحية</th>
                                            <th> كود الصلاحية</th>
                                            <th>الحالة</th>
                                            <th>تعديل</th>
                                            <th>حذف</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <!-- ./wrapper -->

        <!-- jQuery -->
        <script src="../../plugins/jquery/jquery.min.js"></script>
        <!-- jQuery UI 1.11.4 -->
        <script src="https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.13.2/jquery-ui.min.js"></script>
        <!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
        <script>
            $.widget.bridge('uibutton', $.ui.button)
        </script>
        <!-- Bootstrap 4 rtl -->
        <script src="https://cdn.rtlcss.com/bootstrap/v4.2.1/js/bootstrap.min.js"></script>
        <!-- Bootstrap 4 -->
        <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <!-- ChartJS -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js"></script>
        <!-- Sparkline -->
        <script src="https://cdn.jsdelivr.net/npm/jquery-sparkline@2.1.2/jquery.sparkline.min.js"></script>
        <!-- JQVMap -->
        <script src="https://cdn.jsdelivr.net/npm/jqvmap@1.5.1/dist/jquery.vmap.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jqvmap@1.5.1/dist/maps/jquery.vmap.world.js"></script>
        <!-- jQuery Knob Chart -->
        <script src="https://cdn.jsdelivr.net/npm/jquery-knob@1.2.13/dist/jquery.knob.min.js"></script>
        <!-- daterangepicker -->
        <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/min/moment.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.js"></script>
        <!-- Tempusdominus Bootstrap 4 -->
        <script src="https://cdn.jsdelivr.net/npm/tempusdominus-bootstrap-4@5.39.0/build/js/tempusdominus-bootstrap-4.min.js"></script>
        <!-- Summernote -->
        <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
        <!-- overlayScrollbars -->
        <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@1.13.3/js/jquery.overlayScrollbars.min.js"></script>
        <!-- AdminLTE App -->
        <script src="../../dist/js/adminlte.js"></script>
        <!-- AdminLTE dashboard demo (This is only for demo purposes) -->
        <script src="../../dist/js/pages/dashboard.js"></script>
        <!-- AdminLTE for demo purposes -->
        <script src="../../dist/js/demo.js"></script>
        <!-- page script -->
        <!-- DataTables -->
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

        <!-- Buttons Extension -->
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>

        <!-- Export -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
        <script>
            $("#example1").DataTable({
                responsive: false,
                lengthChange: false,
                autoWidth: false,
                searching: true,
                dom: '<"row"<"col-md-12"l><"col-md-12 text-right"f>>rtip',

                language: {
                    search: "بحث:",
                    lengthMenu: "عرض _MENU_ سجل",
                    info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                    paginate: {
                        first: "الأول",
                        last: "الأخير",
                        next: "التالي",
                        previous: "السابق"
                    }
                }
            });
        </script>
        <script>
            window.addEventListener("load", function() {
                if (!sessionStorage.getItem("reloaded")) {
                    sessionStorage.setItem("reloaded", "true");
                    location.reload();
                } else {
                    document.body.style.visibility = "visible";
                }
            });
        </script>

        <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel"
            aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteModalLabel text-right">تأكيد الحذف</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="modal-body text-right">
                        هل أنت متأكد من رغبتك في حذف هذا المستخدم؟ لا يمكن التراجع عن هذا الإجراء.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <a href="#" id="confirmDeleteBtn" class="btn btn-danger">حذف نهائي</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="successModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-sm" role="document">
                <div class="modal-content text-center">
                    <div class="modal-body">
                        <div class="text-success mb-3">
                            <i class="fas fa-check-circle fa-4x"></i>
                        </div>
                        <h5 id="successMessage">تمت العملية بنجاح!</h5>
                        <button type="button" class="btn btn-success mt-3" data-dismiss="modal">موافق</button>
                    </div>
                </div>
            </div>
        </div>

        // add user

        <div class="modal fade" id="successModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
                <div class="modal-content text-center"
                    style="border-radius: 15px; border: none; shadow: 0 5px 15px rgba(0,0,0,0.2);">
                    <div class="modal-body">
                        <div class="text-primary mb-3">
                            <i class="fas fa-user-check fa-4x"></i>
                        </div>
                        <h5 id="successMessage" style="font-weight: bold;">تمت العملية!</h5>
                        <p class="text-muted" id="dynamicMsg">تمت إضافة البيانات بنجاح.</p>
                        <button type="button" class="btn btn-primary btn-block mt-3" data-dismiss="modal"
                            style="border-radius: 10px;">ممتاز</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            $(document).ready(function() {
                // 1. تشغيل رسالة النجاح بعد إعادة التوجيه
                if (sessionStorage.getItem('showSuccess')) {
                    $('#successMessage').text(sessionStorage.getItem('showSuccess'));
                    $('#successModal').modal('show');
                    sessionStorage.removeItem('showSuccess'); // مسح الرسالة بعد العرض
                }

                // 2. تفعيل مودل الحذف وتمرير الـ ID
                $('.delete-btn').on('click', function(e) {
                    e.preventDefault();
                    var userId = $(this).data('id'); // تأكد من إضافة data-id="123" لزر الحذف
                    var deleteUrl = 'delete-user.php?id=' + userId;
                    $('#confirmDeleteBtn').attr('href', deleteUrl);
                    $('#deleteModal').modal('show');
                });

                $(document).ready(function() {
                    // التحقق من وجود رسالة في sessionStorage
                    const msg = sessionStorage.getItem('showSuccess');
                    if (msg) {
                        $('#dynamicMsg').text(msg); // وضع النص القادم من PHP
                        $('#successModal').modal('show'); // إظهار الموديل
                        sessionStorage.removeItem(
                            'showSuccess'); // حذف الرسالة حتى لا تظهر عند تحديث الصفحة
                    }
                });
            });
        </script>

<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>

</html>