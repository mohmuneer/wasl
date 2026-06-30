<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require __DIR__ . "/../../../config/db.php";

// تعريف الدالة بشكل صحيح
function getUserPermissions($pdo, $page_link, $user_id) {
    $permissions = [
        'can_view'   => 0,
        'can_add'    => 0,
        'can_edit'   => 0,
        'can_delete' => 0
    ];

    if (!$user_id) return $permissions;

    try {
        // قمنا بتعديل الاستعلام ليطابق أسماء الأعمدة في صورتك
        $sql = "SELECT a.can_add, a.can_edit, a.can_delete, a.can_view 
                FROM sys_menu m 
                INNER JOIN user_menu_access a ON m.id = a.menu_id 
                WHERE m.link = ? AND a.user_id = ? 
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$page_link, $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $permissions = $result; // سيأخذ القيم 0 أو 1 مباشرة من الجدول
        }
    } catch (PDOException $e) {
        // في حال حدوث خطأ في القاعدة
    }
    return $permissions;
}

// استدعاء الدالة وتجهيز المتغيرات
$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-users.php";

$user_privilege = getUserPermissions($pdo, $page_path, $current_user_id);

// تحديد الصلاحيات في متغيرات بسيطة
$can_view   = $user_privilege['can_view'] ?? 0;
$can_add    = $user_privilege['can_add'] ?? 0;
$can_edit   = $user_privilege['can_edit'] ?? 0;
$can_delete = $user_privilege['can_delete'] ?? 0;

// فحص صلاحية الدخول للمستخدم الحالي
if ($can_view == 0) {
    echo "<script>alert('ليس لديك صلاحية لدخول هذه الصفحة'); window.location.href='../../index.php';</script>";
    exit;
}
?>