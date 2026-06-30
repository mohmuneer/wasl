<?php
session_start();
require __DIR__ . "/../../../config/db.php";

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
        if ($stmt->execute([$id])) {
            echo "<script>
                    sessionStorage.setItem('showSuccess', 'تم حذف العميل بنجاح');
                    window.location.href = '../tables/show-cstmr.php';
                  </script>";
            exit;
        }
    } catch (PDOException $e) {
        die("خطأ قاعدة البيانات: " . $e->getMessage());
    }
}