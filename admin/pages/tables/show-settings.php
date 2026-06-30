<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
// ملاحظة: تأكد أن هذا المسار مطابق تماماً لما هو مخزن في قاعدة البيانات
$page_path = "pages/tables/show-settings.php"; 

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// 1. جلب id الصفحة ديناميكياً
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);
$current_page_id = $menu_item['id'] ?? 0;

// 2. فحص صلاحية التعديل
$can_edit = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_edit FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_edit = $permissions['can_edit'] ?? 0;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// دالة تسجيل العمليات
function logAction($pdo, $userName, $action) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, action, page_url, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userName, $action, $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR']]);
    } catch (Exception $e) {}
}

$current_user = $_SESSION['full_name'] ?? 'Admin';

// 3. معالجة تحديث البيانات
if (isset($_POST['update_settings'])) {
    
    // حماية إضافية: منع التعديل برمجياً إذا لم يكن يملك الصلاحية
    if ($can_edit == 0) {
        die("عذراً، لا تملك صلاحية تنفيذ هذا الإجراء.");
    }

    $name = $_POST['system_name'];
    $email = $_POST['admin_email'];
    $phone = $_POST['contact_number'];
    $addr = $_POST['address'];
    $mode = $_POST['maintenance_mode'];

    $stmt = $pdo->query("SELECT id, system_logo FROM sys_settings LIMIT 1");
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current) {
        $id = $current['id'];
        $logo_name = $current['system_logo'] ?? '';

        if (!empty($_FILES['system_logo']['name']) && ($_FILES['system_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploadCheck = Security::validateUpload($_FILES['system_logo'], 'image', 2);
            if ($uploadCheck['ok']) {
                $logo_name   = Security::safeFilename($_FILES['system_logo']['name'], 'logo');
                $upload_path = "../../dist/img/" . $logo_name;
                if (!move_uploaded_file($_FILES['system_logo']['tmp_name'], $upload_path)) {
                    $logo_name = $current['system_logo'];
                }
            }
        }

        $update = $pdo->prepare("UPDATE sys_settings SET system_name = ?, admin_email = ?, contact_number = ?, address = ?, maintenance_mode = ?, system_logo = ? WHERE id = ?");

        if ($update->execute([$name, $email, $phone, $addr, $mode, $logo_name, $id])) {
            logAction($pdo, $current_user, "قام بتحديث إعدادات النظام العام");
            echo "<script>sessionStorage.setItem('swal_title', 'تم التحديث بنجاح'); sessionStorage.setItem('swal_icon', 'success'); window.location.href='" . $_SERVER['PHP_SELF'] . "';</script>";
            exit;
        }
    }
}

// 4. جلب البيانات للعرض
$users = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($users)) {
    $users = [['system_name'=>'','admin_email'=>'','contact_number'=>'','address'=>'','maintenance_mode'=>0,'system_logo'=>'']];
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Add Information</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>
    /* @import url('https://fonts.googleapis.com/css2?family=Almarai&family=Cairo&family=Tajawal&display=swap');

    body {
        font-family: '<?php echo $visuals['system_font']; ?>', sans-serif !important;
        overflow-x: hidden !important;
        scrollbar-width: none;
    } */

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
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">


        <?php include(__DIR__ . '/../../main-header.php'); ?>



        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>


        <div class="content-wrapper">
            <!-- Content Header -->
            <section class="content-header">
                <div class="container-fluid">
                    <div class="page-banner">
                        <h4><i class="fas fa-sliders-h ml-2"></i>عرض إعدادات النظام</h4>
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                            <li class="breadcrumb-item active">الإعدادات</li>
                        </ol>
                    </div>
                </div>
            </section>

            <!-- Main content -->
            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title float-right">هوية المؤسسة والاتصال</h3>
                                    <form action="" method="POST" class="float-left">
                                        <button type="submit" name="def_settings"
                                            class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-undo"></i> استعادة الافتراضي
                                        </button>
                                    </form>
                                </div>
                                <?php foreach ($users as $setting): ?>
                                <form action="" method="POST" enctype="multipart/form-data">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>اسم النظام / المؤسسة</label>
                                                    <input type="text" name="system_name" class="form-control"
                                                        value="<?php echo htmlspecialchars($setting['system_name'] ?? ''); ?>"
                                                        required>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>البريد الإلكتروني الرسمي</label>
                                                    <input type="email" name="admin_email" class="form-control"
                                                        value="<?php echo htmlspecialchars($setting['admin_email'] ?? ''); ?>"
                                                        required>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>رقم الهاتف</label>
                                                    <input type="text" name="contact_number" class="form-control"
                                                        value="<?php echo htmlspecialchars($setting['contact_number'] ?? ''); ?>">
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>العنوان</label>
                                                    <input type="text" name="address" class="form-control"
                                                        value="<?php echo htmlspecialchars($setting['address'] ?? ''); ?>">
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>حالة النظام</label>
                                                    <select class="form-control" name="maintenance_mode">
                                                        <option value="0"
                                                            <?php echo (isset($setting['maintenance_mode']) && $setting['maintenance_mode'] == 0) ? 'selected' : ''; ?>>
                                                            نشط (يعمل)
                                                        </option>
                                                        <option value="1"
                                                            <?php echo (isset($setting['maintenance_mode']) && $setting['maintenance_mode'] == 1) ? 'selected' : ''; ?>>
                                                            وضع الصيانة
                                                        </option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>شعار النظام الحالي</label><br>
                                                    <?php if (!empty($setting['system_logo'])): ?>
                                                    <img src="../../dist/img/<?php echo $setting['system_logo']; ?>"
                                                        class="logo-preview" style="max-width: 150px;">
                                                    <?php else: ?>
                                                    <div class="p-3 bg-light text-muted border">لا يوجد شعار حالياً
                                                    </div>
                                                    <?php endif; ?>

                                                    <div class="custom-file mt-2">
                                                        <input type="file" name="system_logo" class="custom-file-input"
                                                            id="logoInput">
                                                        <label class="custom-file-label" for="logoInput">تغيير
                                                            الشعار...</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer text-left">
    <?php if ($can_edit == 1): ?>
        <button type="submit" name="update_settings" class="btn btn-primary">
            <i class="fas fa-save mr-1"></i> حفظ التغييرات
        </button>
    <?php else: ?>
        <button type="button" class="btn btn-secondary" disabled>
            <i class="fas fa-lock mr-1"></i> حفظ (غير مسموح لك)
        </button>
        <small class="text-danger d-block mt-2">ليس لديك صلاحية لتعديل إعدادات النظام.</small>
    <?php endif; ?>
</div>
                                </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <!-- ./wrapper -->

        <script src="../../plugins/jquery/jquery.min.js"></script>
        <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="../../dist/js/adminlte.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
        // إظهار اسم الملف المختار في حقل الـ File Input
        $(".custom-file-input").on("change", function() {
            var fileName = $(this).val().split("\\").pop();
            $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
        });

        // رسائل SweetAlert
        const title = sessionStorage.getItem('swal_title');
        if (title) {
            Swal.fire({
                title: title,
                text: sessionStorage.getItem('swal_text'),
                icon: sessionStorage.getItem('swal_icon'),
                confirmButtonText: 'موافق'
            });
            sessionStorage.clear();
        }
        </script>

</body>

</html>