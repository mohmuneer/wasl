<?php
session_start();
require __DIR__ . "/../../../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = filter_var($_POST['branch_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        if ($action === 'update') {
            $name = trim($_POST['branch_name']);

            // التأكد من أن الاسم الجديد ليس موجوداً لفرع آخر
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE branch_name = ? AND id != ?");
            $stmt->execute([$name, $id]);

            if ($stmt->fetchColumn() > 0) {
                $_SESSION['swal_type'] = 'error';
                $_SESSION['swal_title'] = 'خطأ!';
                $_SESSION['swal_text'] = 'اسم الفرع هذا مستخدم بالفعل.';
            } else {
                $stmt = $pdo->prepare("UPDATE branches SET branch_name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                $_SESSION['swal_type'] = 'success';
                $_SESSION['swal_title'] = 'تم التحديث!';
                $_SESSION['swal_text'] = 'تم تعديل بيانات الفرع بنجاح.';
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['swal_type'] = 'success';
            $_SESSION['swal_title'] = 'تم الحذف!';
            $_SESSION['swal_text'] = 'تم حذف الفرع نهائياً.';
        }
    } catch (PDOException $e) {
        $_SESSION['swal_type'] = 'error';
        $_SESSION['swal_title'] = 'فشل الإجراء';
        $_SESSION['swal_text'] = 'لا يمكن تنفيذ العملية لوجود بيانات مرتبطة بهذا الفرع.';
    }

    // نقل بيانات السشن إلى sessionStorage عبر الجافا سكريبت في الصفحة التالية
    echo "<script>
        sessionStorage.setItem('swal_icon', '{$_SESSION['swal_type']}');
        sessionStorage.setItem('swal_title', '{$_SESSION['swal_title']}');
        sessionStorage.setItem('swal_text', '{$_SESSION['swal_text']}');
        window.location.href = '../forms/add-branch.php'; 
    </script>";
    exit;
}
