<?php
session_start();
require __DIR__ . "/../../../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = filter_var($_POST['lab_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        if ($action === 'update') {
            $name = trim($_POST['lab_name']);
            $region_id = filter_var($_POST['college_id'], FILTER_SANITIZE_NUMBER_INT);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_name = ? AND region_id = ? AND id != ?");
            $stmt->execute([$name, $region_id, $id]);

            if ($stmt->fetchColumn() > 0) {
                $_SESSION['swal_type'] = 'error';
                $_SESSION['swal_title'] = 'خطأ!';
                $_SESSION['swal_text'] = 'اسم القسم هذا موجود بالفعل في المنطقة المختارة.';
            } else {
                $stmt = $pdo->prepare("UPDATE departments SET department_name = ?, region_id = ? WHERE id = ?");
                $stmt->execute([$name, $region_id, $id]);

                $_SESSION['swal_type'] = 'success';
                $_SESSION['swal_title'] = 'تم التحديث!';
                $_SESSION['swal_text'] = 'تم تعديل بيانات القسم والمنطقة بنجاح.';
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION['swal_type'] = 'success';
            $_SESSION['swal_title'] = 'تم الحذف!';
            $_SESSION['swal_text'] = 'تم حذف القسم نهائياً.';
        }
    } catch (PDOException $e) {
        $_SESSION['swal_type'] = 'error';
        $_SESSION['swal_title'] = 'فشل الإجراء';
        $_SESSION['swal_text'] = 'لا يمكن تنفيذ العملية لوجود بيانات مرتبطة بهذا القسم (مثل أجهزة أو جداول محاضرات).';
    }

    // إرسال البيانات إلى sessionStorage والعودة لصفحة الإضافة
    echo "<script>
        sessionStorage.setItem('swal_icon', '" . ($_SESSION['swal_type'] ?? 'info') . "');
        sessionStorage.setItem('swal_title', '" . ($_SESSION['swal_title'] ?? '') . "');
        sessionStorage.setItem('swal_text', '" . ($_SESSION['swal_text'] ?? '') . "');
        window.location.href = '../forms/add-lab.php'; 
    </script>";
    exit;
}
