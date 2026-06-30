<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

// 1. التحقق من وجود معرف الصلاحية في الرابط
if (!isset($_GET['id'])) {
    header("Location: ../tables/show-users.php");
    exit();
}

$id = $_GET['id'];

// 2. جلب بيانات الصلاحية الحالية لعرضها في النموذج
$sql_fetch = "SELECT * FROM sys_roles WHERE id = ?";
$stmt_fetch = $pdo->prepare($sql_fetch);
$stmt_fetch->execute([$id]);
$role = $stmt_fetch->fetch();

if (!$role) {
    echo "الصلاحية غير موجودة!";
    exit();
}

// 3. معالجة عملية التحديث عند إرسال النموذج
if (isset($_POST['update_permission'])) {
    $permission_name = trim($_POST['permission_name']);
    $permission_code = trim($_POST['permission_code']);

    if (!empty($permission_name) && !empty($permission_code)) {

        // التحقق من أن الكود الجديد لا يستخدمه سجل آخر (غير السجل الحالي)
        $checkSql = "SELECT COUNT(*) FROM sys_roles WHERE role_code = ? AND id != ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$permission_code, $id]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            echo "<script>alert('خطأ: كود الصلاحية هذا مستخدم بالفعل في صلاحية أخرى');</script>";
        } else {
            // تنفيذ التحديث
            $sql = "UPDATE sys_roles SET role_name = ?, role_code = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$permission_name, $permission_code, $id])) {
                echo "<script>
                        sessionStorage.setItem('showSuccess', 'تم تحديث الصلاحية بنجاح');
                        window.location.href = '../tables/view-permissions.php';
                      </script>";
            }
        }
    } else {
        echo "<script>alert('الرجاء تعبئة جميع الحقول');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

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
    <link rel="stylesheet" href="../../plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
    <!-- iCheck -->
    <link rel="stylesheet" href="../../plugins/icheck-bootstrap/icheck-bootstrap.min.css">
    <!-- JQVMap -->
    <link rel="stylesheet" href="../../plugins/jqvmap/jqvmap.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <!-- overlayScrollbars -->
    <link rel="stylesheet" href="../../plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
    <!-- Daterange picker -->
    <link rel="stylesheet" href="../../plugins/daterangepicker/daterangepicker.css">
    <!-- summernote -->
    <link rel="stylesheet" href="../../plugins/summernote/summernote-bs4.css">
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
            overflow-x: hidden !important;
            scrollbar-width: none !important;
        }

        ::-webkit-scrollbar {
            display: none !important;
        }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">


    <?php include(__DIR__ . '/../../main-header.php'); ?>



    <?php include(__DIR__ . '/../../main-sidebar.php'); ?>


    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1>تعديل صلاحية</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                            <li class="breadcrumb-item active">تعديل صلاحية</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary">
                        <div class="card-header breadcrumb float-sm-right">
                            <h3 class="card-title">تعديل بيانات الصلاحية:
                                <?php echo htmlspecialchars($role['role_name']); ?></h3>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label>اسم الصلاحية</label>
                                    <input type="text" name="permission_name" class="form-control"
                                        value="<?php echo htmlspecialchars($role['role_name']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label>رمز الصلاحية</label>
                                    <input type="text" name="permission_code" class="form-control"
                                        value="<?php echo htmlspecialchars($role['role_code']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <button type="submit" name="update_permission" class="btn btn-primary">
                                        حفظ التغييرات
                                    </button>
                                    <a href="../tables/show-users.php" class="btn btn-default">إلغاء</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <footer class="main-footer">
        <?php include('../../main-footer.php') ?>
    </footer>
    </div>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
</body>

</html>