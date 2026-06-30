<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. إعدادات الأخطاء والاتصال
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require __DIR__ . "/../../../config/db.php";

$id = $_GET['id'] ?? null;
if (!$id) { die("خطأ: معرف العنصر غير موجود"); }

// 2. التحقق من الصلاحيات الديناميكية
$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-sidebar.php"; 

if (!$current_user_id) { die("خطأ: يجب تسجيل الدخول أولاً"); }

// جلب بيانات العنصر الحالي
$stmt = $pdo->prepare("SELECT * FROM sys_menu WHERE id = ?");
$stmt->execute([$id]);
$menu_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$menu_data) { die("خطأ: العنصر غير موجود"); }

// جلب ID الصفحة الحالية للتحقق من صلاحية التعديل
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?: 0;

$can_edit = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_edit FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $can_edit = $accessStmt->fetchColumn() ?: 0;
}

if ($can_edit == 0) { die("عذراً، لا تملك صلاحية التعديل على هذا القسم!"); }

$error_msg = "";
$success_msg = "";

// 3. معالجة التحديث (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_menu'])) {
    try {
        $title = trim($_POST['title']);
        $icon = trim($_POST['icon']);
        $link = trim($_POST['link']);
        $parent_id = (int)$_POST['parent_id'];
        $permission_key = !empty($_POST['permission_key']) ? $_POST['permission_key'] : NULL;
        $sort_order = (int)$_POST['sort_order'];

        if (empty($title) || empty($link)) { throw new Exception("اسم العنصر والرابط مطلوبان!"); }

        $sql = "UPDATE sys_menu SET title=?, icon=?, link=?, parent_id=?, permission_key=?, sort_order=? WHERE id=?";
        $pdo->prepare($sql)->execute([$title, $icon, $link, $parent_id, $permission_key, $sort_order, $id]);
        
        // التوجيه للصفحة الرئيسية مع رسالة نجاح
        header("Location: add-sidebar.php?success=" . urlencode("تم تحديث بيانات '" . $title . "' بنجاح"));
        exit;
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 4. جلب البيانات للقوائم المنسدلة
$perms = $pdo->query("SELECT perm_key, perm_name FROM sys_permissions")->fetchAll();
$parents = $pdo->query("SELECT id, title FROM sys_menu WHERE parent_id = 0 AND id != $id ORDER BY sort_order ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تعديل عنصر القائمة</title>
    <!-- CSS الأساسي -->
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>
        html, body { overflow-x: hidden !important; scrollbar-width: none !important; }
        ::-webkit-scrollbar { display: none !important; }
        input.no-spinners::-webkit-outer-spin-button,
        input.no-spinners::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">

        <!-- 1. Header -->
        <?php include(__DIR__ . '/../../main-header.php'); ?>

        <!-- 2. Sidebar -->
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <!-- 3. Content Wrapper -->
        <div class="content-wrapper">
            <!-- Header الخاص بالمحتوى -->
             <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">

                        <div class="col-sm-6">
                            <h1>تعديل القائمة الجانبية</h1>
                        </div>

                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                                <li class="breadcrumb-item active">الشجرة</li>
                            </ol>
                        </div>

                    </div>
                </div>
            </section>

            <!-- المحتوى الفعلي -->
            <section class="content">
                <div class="container-fluid">
                    
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?= $error_msg ?>
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    <?php endif; ?>

                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title">تعديل بيانات: <?= htmlspecialchars($menu_data['title']) ?></h3>
                        </div>
                        
                        <form method="POST">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <label>اسم العنصر</label>
                                        <input type="text" name="title" class="form-control" 
                                               value="<?= htmlspecialchars($menu_data['title']) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label>الأيقونة (FontAwesome)</label>
                                        <input type="text" name="icon" class="form-control" 
                                               value="<?= htmlspecialchars($menu_data['icon']) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label>الرابط (Link)</label>
                                        <input type="text" name="link" class="form-control" 
                                               value="<?= htmlspecialchars($menu_data['link']) ?>" required>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <label>يتبع لقسم</label>
                                        <select name="parent_id" class="form-control">
                                            <option value="0" <?= $menu_data['parent_id'] == 0 ? 'selected' : '' ?>>قسم رئيسي</option>
                                            <?php foreach ($parents as $p): ?>
                                                <option value="<?= $p['id'] ?>" <?= $menu_data['parent_id'] == $p['id'] ? 'selected' : '' ?>>
                                                    <?= $p['title'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label>الصلاحية المطلوبة</label>
                                        <select name="permission_key" class="form-control">
                                            <option value="">متاح للجميع</option>
                                            <?php foreach ($perms as $perm): ?>
                                                <option value="<?= $perm['perm_key'] ?>" <?= $menu_data['permission_key'] == $perm['perm_key'] ? 'selected' : '' ?>>
                                                    <?= $perm['perm_name'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label>الترتيب (Sort Order)</label>
                                        <input type="number" name="sort_order" class="form-control no-spinners" 
                                               value="<?= $menu_data['sort_order'] ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer">
                                <button type="submit" name="update_menu" class="btn btn-info">حفظ التغييرات</button>
                                <a href="add-sidebar.php" class="btn btn-secondary float-left">عودة للخلف</a>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </div>

        <!-- الروابط السفلية للجافا سكريبت -->
        <script src="../../plugins/jquery/jquery.min.js"></script>
        <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="../../dist/js/adminlte.js"></script>
    </div>
</body>
</html>