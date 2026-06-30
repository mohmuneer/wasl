<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

if (! isset($_GET['id'])) {
    header("Location: show-users.php");
    exit;
}

$user_id = $_GET['id'];

// جلب جميع الفروع المتاحة
$branches_stmt = $pdo->query("SELECT * FROM branches");
$all_branches  = $branches_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب بيانات المستخدم الأساسية
$stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب فروع المستخدم الحالية
$user_branches_stmt = $pdo->prepare("SELECT branch_id FROM user_branch_access WHERE user_id = ?");
$user_branches_stmt->execute([$user_id]);
$current_user_branches = $user_branches_stmt->fetchAll(PDO::FETCH_COLUMN);

if (! $user) {
    echo "المستخدم غير موجود!";
    exit;
}

if (isset($_POST['update_user'])) {
    $full_name = $_POST['full_name'];
    $email     = $_POST['email'];
    $status    = isset($_POST['status']) ? 'active' : 'inactive';
    $password  = $_POST['password']; // استقبال كلمة المرور الجديدة
    $file_path = $user['file_path'] ?? '';
    // 1. استقبال الفروع
    $selected_branches = $_POST['branch_id'] ?? [];

    // --- التعديل المطلوب هنا ---
    // إذا كانت المصفوفة فارغة (المستخدم لم يختر أي فرع)
    if (empty($selected_branches)) {
        $default_branch_id  = 2; // استبدل رقم 2 بالـ ID الخاص بالفرع الافتراضي في قاعدة بياناتك
        $selected_branches[] = $default_branch_id;
    }
    // -------------------------

    // معالجة رفع الصورة
    if (isset($_FILES['file_input']) && $_FILES['file_input']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . "/../../../uploads/";
        if (! empty($user['file_path']) && file_exists($upload_dir . $user['file_path'])) {
            unlink($upload_dir . $user['file_path']);
        }
        $filename = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "_", basename($_FILES['file_input']['name']));
        if (move_uploaded_file($_FILES['file_input']['tmp_name'], $upload_dir . $filename)) {
            $file_path = $filename;
        }
    }

 try {
        $pdo->beginTransaction();

        // 1. تحديث بيانات المستخدم الأساسية مع التحقق من كلمة المرور
        if (!empty($_POST['password'])) {
            // إذا أدخل المستخدم كلمة مرور جديدة
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $update_stmt = $pdo->prepare("UPDATE sys_users SET full_name=?, email=?, file_path=?, status=?, password=? WHERE id=?");
            $update_stmt->execute([$full_name, $email, $file_path, $status, $hashed_password, $user_id]);
        } else {
            // إذا ترك حقل كلمة المرور فارغاً (تحديث البيانات بدون كلمة المرور)
            $update_stmt = $pdo->prepare("UPDATE sys_users SET full_name=?, email=?, file_path=?, status=? WHERE id=?");
            $update_stmt->execute([$full_name, $email, $file_path, $status, $user_id]);
        }

        // 2. تحديث جدول فروع المستخدم (user_branch_access)
        // حذف الفروع القديمة أولاً
        $delete_perm_stmt = $pdo->prepare("DELETE FROM user_branch_access WHERE user_id = ?");
        $delete_perm_stmt->execute([$user_id]);

        // إضافة الفروع المختارة الجديدة
        $insert_perm_stmt = $pdo->prepare("INSERT INTO user_branch_access (user_id, branch_id) VALUES (?, ?)");
        foreach ($selected_branches as $branch_id) {
            $insert_perm_stmt->execute([$user_id, $branch_id]);
        }

        $pdo->commit();

        // 3. رسالة النجاح والتحويل
        echo "<script>
            sessionStorage.setItem('showSuccess', 'تم تحديث بيانات المستخدم بنجاح');
            window.location.href = 'show-users.php';
          </script>";
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "حدث خطأ أثناء التحديث: " . $e->getMessage();
    }
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
    html,
    body {
        overflow: auto;
        /* إظهار السكرول */
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


        <?php include __DIR__ . '/../../main-header.php'; ?>



        <?php include __DIR__ . '/../../main-sidebar.php'; ?>


        <div class="content-wrapper">
            <!-- Content Header -->
            <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1>تعديل بيانات المستخدم</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                                <li class="breadcrumb-item active">تعديل مستخدم</li>
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
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
    <!-- عرض الصورة الحالية -->
    <div class="form-group text-center">
        <label>الصورة الحالية</label><br>
        <?php if (!empty($user['file_path'])): ?>
            <img src="../../../uploads/<?php echo htmlspecialchars($user['file_path']) ?>"
                 class="img-thumbnail" width="120" style="border-radius: 50%;">
        <?php else: ?>
            <img src="../../dist/img/avatar5.png" class="img-thumbnail" width="120"
                 style="border-radius: 50%;">
        <?php endif; ?>
    </div>

    <!-- حالة الحساب -->
    <div class="form-group">
        <label>حالة الحساب</label>
        <div class="custom-control custom-switch">
            <input type="checkbox" name="status" class="custom-control-input"
                   id="statusSwitch" <?php echo ($user['status'] == 'active') ? 'checked' : '' ?>>
            <label class="custom-control-label" for="statusSwitch">
                <span id="statusLabel"
                      class="badge <?php echo ($user['status'] == 'active') ? 'badge-success' : 'badge-danger' ?>">
                    <?php echo ($user['status'] == 'active') ? 'نشط' : 'موقف' ?>
                </span>
            </label>
        </div>
        <small class="text-muted">قم بتبديل الزر لتفعيل أو تعطيل دخول المستخدم للنظام.</small>
    </div>

    <!-- اسم المستخدم -->
    <div class="form-group">
        <label>اسم المستخدم</label>
        <input type="text" name="full_name" class="form-control"
               value="<?php echo htmlspecialchars($user['full_name']) ?>" required>
    </div>

    <!-- البريد الإلكتروني -->
    <div class="form-group">
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" class="form-control"
               value="<?php echo htmlspecialchars($user['email']) ?>" required>
    </div>

    <!-- حقل كلمة المرور الجديدة -->
    <div class="form-group">
        <label>كلمة المرور الجديدة</label>
        <div class="input-group">
            <input type="password" name="password" id="passwordField" class="form-control" 
                   placeholder="اتركه فارغاً إذا كنت لا تريد التغيير">
            <div class="input-group-append">
                <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword()">
                    <i class="fas fa-eye" id="passwordIcon"></i>
                </span>
            </div>
        </div>
        <small class="text-muted">إذا تركت هذا الحقل فارغاً، فسيتم الاحتفاظ بكلمة المرور الحالية.</small>
    </div>

    <!-- تغيير الصورة -->
    <div class="form-group">
        <label>تغيير الصورة (اختياري)</label>
        <div class="custom-file">
            <input type="file" name="file_input" class="custom-file-input" id="customFile">
            <label class="custom-file-label" for="customFile" data-browse="استعراض">
                اختر صورة جديدة...
            </label>
        </div>
    </div>

    <!-- اختيار الفرع -->
    <div class="form-group">
        <label>الفرع المسؤول عنه</label>
        <select name="branch_id" class="form-control select2" required>
            <option value="">-- اختر الفرع --</option>
            <?php foreach ($all_branches as $branch): ?>
                <option value="<?php echo $branch['id'] ?>"
                    <?php echo (in_array($branch['id'], $current_user_branches)) ? 'selected' : '' ?>>
                    <?php echo htmlspecialchars($branch['branch_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- أزرار التحكم -->
    <div class="form-group">
        <button type="submit" name="update_user" class="btn btn-success">
            <i class="fas fa-save"></i> حفظ التعديلات
        </button>
        <a href="show-users.php" class="btn btn-secondary">إلغاء</a>
    </div>
</form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <footer class="main-footer">
            <?php include '../../main-footer.php' ?>
        </footer>
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
function togglePassword() {
    var passwordField = document.getElementById("passwordField");
    var passwordIcon = document.getElementById("passwordIcon");
    if (passwordField.type === "password") {
        passwordField.type = "text";
        passwordIcon.classList.remove("fa-eye");
        passwordIcon.classList.add("fa-eye-slash");
    } else {
        passwordField.type = "password";
        passwordIcon.classList.remove("fa-eye-slash");
        passwordIcon.classList.add("fa-eye");
    }
}
</script>
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
    <script>
    // لإظهار اسم الملف المختار في حقل الـ File Input
    $(".custom-file-input").on("change", function() {
        var fileName = $(this).val().split("\\").pop();
        $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
    });
    </script>
    <script>
    $('#statusSwitch').on('change', function() {
        if ($(this).is(':checked')) {
            $('#statusLabel').text('نشط').removeClass('badge-danger').addClass('badge-success');
        } else {
            $('#statusLabel').text('موقف').removeClass('badge-success').addClass('badge-danger');
        }
    });
    $(document).ready(function() {
        // التحقق من وجود رسالة نجاح في مخزن المتصفح
        if (sessionStorage.getItem('showSuccess')) {
            $('#successMessage').text(sessionStorage.getItem('showSuccess'));
            $('#successModal').modal('show');
            sessionStorage.removeItem('showSuccess'); // مسحها لكي لا تظهر مرة أخرى عند التحديث
        }
    });
    </script>
    <div class="modal fade" id="successModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
            <div class="modal-content text-center" style="border-radius: 15px;">
                <div class="modal-body">
                    <div class="text-success mb-3">
                        <i class="fas fa-check-circle fa-4x"></i>
                    </div>
                    <h5 id="successMessage" style="font-weight: bold;">تمت العملية بنجاح!</h5>
                    <p class="text-muted">تم حفظ التعديلات في قاعدة البيانات.</p>
                    <button type="button" class="btn btn-success btn-block mt-3" data-dismiss="modal">موافق</button>
                </div>
            </div>
        </div>
    </div>
</body>

</html>