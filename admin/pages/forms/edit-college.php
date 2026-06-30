<?php
session_start();
require __DIR__ . "/../../../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    // جلب معرف الكلية
    $id = filter_var($_POST['college_id'], FILTER_SANITIZE_NUMBER_INT);

    try {
        if ($action === 'update') {
            $college_name = trim($_POST['college_name']);
            $branch_id    = filter_var($_POST['branch_id'], FILTER_SANITIZE_NUMBER_INT);

            // 1. التأكد من أن الكلية ليست مكررة في نفس الفرع (باستثناء الكلية الحالية)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM regions WHERE region_name = ? AND branch_id = ? AND id != ?");
            $stmt->execute([$college_name, $branch_id, $id]);

            if ($stmt->fetchColumn() > 0) {
                $_SESSION['swal_type']  = 'error';
                $_SESSION['swal_title'] = 'خطأ!';
                $_SESSION['swal_text']  = 'اسم المنطقة هذا موجود بالفعل في هذا الفرع.';
            } else {
                // 2. تحديث بيانات الكلية (الاسم والفرع المرتبط بها)
                $stmt = $pdo->prepare("UPDATE regions SET region_name = ?, branch_id = ? WHERE id = ?");
                $stmt->execute([$college_name, $branch_id, $id]);

                $_SESSION['swal_type']  = 'success';
                $_SESSION['swal_title'] = 'تم التحديث!';
                $_SESSION['swal_text']  = 'تم تعديل بيانات المنطقة بنجاح.';
            }
        } elseif ($action === 'delete') {
            // 3. حذف الكلية
            $stmt = $pdo->prepare("DELETE FROM regions WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION['swal_type']  = 'success';
            $_SESSION['swal_title'] = 'تم الحذف!';
            $_SESSION['swal_text']  = 'تم حذف المنطقة نهائياً.';
        }
    } catch (PDOException $e) {
        $_SESSION['swal_type']  = 'error';
        $_SESSION['swal_title'] = 'فشل الإجراء';
        $_SESSION['swal_text']  = 'لا يمكن تنفيذ العملية لوجود بيانات مرتبطة بهذه المنطقة (مثل أقسام أو طلاب).';
    }

    // إرسال البيانات للـ sessionStorage والتحويل لصفحة الإضافة
    echo "<script>
        sessionStorage.setItem('swal_icon', '{$_SESSION['swal_type']}');
        sessionStorage.setItem('swal_title', '{$_SESSION['swal_title']}');
        sessionStorage.setItem('swal_text', '{$_SESSION['swal_text']}');
        window.location.href = '../forms/add-college.php'; 
    </script>";
    exit;
}
