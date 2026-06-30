<?php
// 1. بدء الجلسة للوصول إلى البيانات الحالية
session_start();

// 2. إفراغ كافة متغيرات الجلسة
$_SESSION = array();

// 3. إذا كنت تستخدم ملفات تعريف الارتباط (Cookies) للجلسة، فقم بحذفها
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. تدمير الجلسة نهائياً من السيرفر
session_destroy();

// 5. توجيه المستخدم إلى صفحة تسجيل الدخول أو الصفحة الرئيسية
header("Location:../index.php");
exit;