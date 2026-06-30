<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $role_id = $_GET['id'];

    try {
        // 1. التحقق: هل هذه الصلاحية مرتبطة بمستخدمين في جدول user_roles؟
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
        $check_stmt->execute([$role_id]);
        $usage_count = $check_stmt->fetchColumn();

        if ($usage_count > 0) {
            // رسالة الخطأ: الصلاحية مرتبطة بمستخدم
            echo "<script>
                    sessionStorage.setItem('showError', 'عذراً، هذه الصلاحية مرتبطة بمستخدم حالياً ولا يمكن حذفها.');
                    window.location.href = 'view-permissions.php';
                  </script>";
            exit;
        }

        // 2. إذا لم تكن مرتبطة، نبدأ عملية الحذف
        $pdo->beginTransaction();

        // تأكد من أن اسم الجدول هو ROLES كما في استفسارك السابق
        $delete_role = $pdo->prepare("DELETE FROM sys_roles WHERE id = ?");
        $delete_role->execute([$role_id]);

        $pdo->commit();

        echo "<script>
                sessionStorage.setItem('showSuccess', 'تم حذف الصلاحية بنجاح');
                window.location.href = 'view-permissions.php';
              </script>";
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // عرض الخطأ التقني في حال حدوث فشل في الاستعلام
        echo "<script>
                sessionStorage.setItem('showError', 'حدث خطأ في النظام: " . addslashes($e->getMessage()) . "');
                window.location.href = 'view-permissions.php';
              </script>";
        exit;
    }
} else {
    header("Location: view-permissions.php");
    exit;
}