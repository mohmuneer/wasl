<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$user_id = $_SESSION['user_id'] ?? 0;
$page_path = "pages/forms/add-sidebar.php"; // الصفحة التي نتحكم بصلاحياتها

// 1. جلب معرف الصفحة
$stmt_page = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$stmt_page->execute([$page_path]);
$page = $stmt_page->fetch();
$menu_id = $page['id'] ?? 0;

// 2. التحقق من صلاحية الحذف للمستخدم على هذه الصفحة
$can_delete = 0;
if ($menu_id > 0) {
    $stmt_priv = $pdo->prepare("SELECT can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?");
    $stmt_priv->execute([$user_id, $menu_id]);
    $res = $stmt_priv->fetch();
    $can_delete = $res['can_delete'] ?? 0;
}

// 3. منع الوصول إذا لم يكن يملك الصلاحية (ديناميكياً)
if ($can_delete == 0) {
    header("Location: add-sidebar.php?error=" . urlencode("عذراً، لا تملك صلاحية الحذف وفقاً لإعدادات حسابك."));
    exit;
}

// 4. تنفيذ الحذف
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $pdo->beginTransaction();
        
        // حذف التوابع
        $pdo->prepare("DELETE FROM sys_menu WHERE parent_id = ?")->execute([$id]);
        // حذف العنصر
        $pdo->prepare("DELETE FROM sys_menu WHERE id = ?")->execute([$id]);

        $pdo->commit();
        header("Location: add-sidebar.php?success=" . urlencode("تم الحذف بنجاح"));
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("خطأ: " . $e->getMessage());
    }
}