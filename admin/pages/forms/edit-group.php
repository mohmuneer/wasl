<?php
session_start();
require __DIR__ . "/../../../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = filter_var($_POST['group_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        if ($action === 'update') {
            $name = trim($_POST['group_name']);

            // 1. التأكد من أن اسم المجموعة الجديد ليس مكرراً (باستثناء السجل الحالي)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM issue_categories WHERE category_name = ? AND id != ?");
            $stmt->execute([$name, $id]);

            if ($stmt->fetchColumn() > 0) {
                $_SESSION['swal_type'] = 'error';
                $_SESSION['swal_title'] = 'خطأ!';
                $_SESSION['swal_text'] = 'اسم المجموعة هذا مستخدم بالفعل.';
            } else {
                // تحديث جدول issue_categories
                $stmt = $pdo->prepare("UPDATE issue_categories SET category_name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                $_SESSION['swal_type'] = 'success';
                $_SESSION['swal_title'] = 'تم التحديث!';
                $_SESSION['swal_text'] = 'تم تعديل بيانات المجموعة بنجاح.';
            }
        } elseif ($action === 'delete') {
            // الحذف من جدول groups
            $stmt = $pdo->prepare("DELETE FROM issue_categories WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['swal_type'] = 'success';
            $_SESSION['swal_title'] = 'تم الحذف!';
            $_SESSION['swal_text'] = 'تم حذف المجموعة نهائياً.';
        }
    } catch (PDOException $e) {
        $_SESSION['swal_type'] = 'error';
        $_SESSION['swal_title'] = 'فشل الإجراء';
        $_SESSION['swal_text'] = 'لا يمكن تنفيذ العملية لوجود بيانات مرتبطة بهذه المجموعة (مثل طلاب أو جداول).';
    }

    // إرسال البيانات إلى sessionStorage والعودة لصفحة المجموعات
    echo "<script>
        sessionStorage.setItem('swal_icon', '" . ($_SESSION['swal_type'] ?? 'info') . "');
        sessionStorage.setItem('swal_title', '" . ($_SESSION['swal_title'] ?? '') . "');
        sessionStorage.setItem('swal_text', '" . ($_SESSION['swal_text'] ?? '') . "');
        window.location.href = '../forms/add-group.php'; 
    </script>";
    exit;
}
