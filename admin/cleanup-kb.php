<?php
session_start();
require __DIR__ . '/../config/db.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) die("يجب تسجيل الدخول");

try {
    $pdo->exec("DELETE FROM user_menu_access WHERE menu_id = (SELECT id FROM sys_menu WHERE link = 'pages/tables/show-kb.php')");
    $pdo->exec("DELETE FROM sys_menu WHERE link = 'pages/tables/show-kb.php'");
    $pdo->exec("DROP TABLE IF EXISTS kb_feedback");
    $pdo->exec("DROP TABLE IF EXISTS kb_articles");
    $pdo->exec("DROP TABLE IF EXISTS kb_categories");
    echo "تم حذف قاعدة المعرفة بالكامل (جداول + قائمة). يمكنك الآن حذف ملف cleanup-kb.php";
} catch (PDOException $e) {
    echo "خطأ: " . $e->getMessage();
}
