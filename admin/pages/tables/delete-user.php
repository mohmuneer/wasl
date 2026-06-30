<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

// معرف المستخدم المسجل حالياً
$current_logged_in_id = $_SESSION['user_id'] ?? null;

if (isset($_GET['id'])) {
    $user_id_to_delete = $_GET['id'];

    try {
        // 1. منع المستخدم من حذف نفسه
        if ($current_logged_in_id == $user_id_to_delete) {
            echo "<script>
                    sessionStorage.setItem('showError', 'عذراً، لا يمكنك حذف حسابك الشخصي أثناء تسجيل الدخول.');
                    window.location.href = 'show-users.php';
                  </script>";
            exit;
        }

        // 2. التحقق من رتبة mainAdmin من خلال جدول user_roles
        $stmt_check_role = $pdo->prepare("
            SELECT r.role_code 
            FROM user_roles u 
            JOIN sys_roles r ON u.role_id = r.id 
            WHERE u.user_id = ?
        ");
        $stmt_check_role->execute([$user_id_to_delete]);
        
        // جلب جميع أدوار المستخدم والتأكد إذا كان أحدها mainAdmin
        $roles = $stmt_check_role->fetchAll(PDO::FETCH_COLUMN);
        
        $is_main_admin = false;
        foreach ($roles as $role) {
            if (strtolower($role) === 'mainadmin') {
                $is_main_admin = true;
                break;
            }
        }

        if ($is_main_admin) {
            echo "<script>
                    sessionStorage.setItem('showError', 'لا يمكن حذف المستخدم صاحب صلاحية المدير العام (mainAdmin).');
                    window.location.href = 'show-users.php';
                  </script>";
            exit;
        }

        // --- بدء عملية الحذف ---
        $pdo->beginTransaction();

        // حذف الصورة الفيزيائية
        $stmt_img = $pdo->prepare("SELECT file_path FROM sys_users WHERE id = ?");
        $stmt_img->execute([$user_id_to_delete]);
        $file_path = $stmt_img->fetchColumn();

        if ($file_path) {
            $full_path = __DIR__ . "/../../../uploads/" . $file_path;
            if (file_exists($full_path)) {
                unlink($full_path);
            }
        }

        // حذف الصلاحيات المرتبطة
        $delete_perms = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $delete_perms->execute([$user_id_to_delete]);

        // حذف المستخدم
        $delete_user = $pdo->prepare("DELETE FROM sys_users WHERE id = ?");
        $delete_user->execute([$user_id_to_delete]);

        $pdo->commit();

        echo "<script>
                sessionStorage.setItem('showSuccess', 'تم حذف المستخدم وجميع بياناته بنجاح');
                window.location.href = 'show-users.php';
              </script>";
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = addslashes($e->getMessage());
        echo "<script>
                sessionStorage.setItem('showError', 'حدث خطأ أثناء الحذف: " . $error_msg . "');
                window.location.href = 'show-users.php';
              </script>";
        exit;
    }
} else {
    header("Location: show-users.php");
    exit;
}