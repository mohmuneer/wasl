<?php
// ─── إعدادات قاعدة البيانات ──────────────────────────────────
!defined('DB_HOST')    && define('DB_HOST',    'localhost');
!defined('DB_NAME')    && define('DB_NAME',    'wasl');
!defined('DB_USER')    && define('DB_USER',    'root');
!defined('DB_PASS')    && define('DB_PASS',    '');
!defined('DB_CHARSET') && define('DB_CHARSET', 'utf8mb4');

// ─── مسار مجلد الكاش ─────────────────────────────────────────
!defined('CACHE_DIR')    && define('CACHE_DIR',    dirname(__DIR__) . '/storage/cache/');
!defined('CACHE_PREFIX') && define('CACHE_PREFIX', 'wasl_');

// ─── تحميل الثوابت والفئات ───────────────────────────────────
require_once __DIR__ . '/tables.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Cache.php';
require_once dirname(__DIR__) . '/core/Security.php';

// ─── حماية CSRF التلقائية لجميع طلبات POST ──────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
Security::validatePost();

// ─── إنشاء مجلد التخزين المؤقت إذا لم يوجد ──────────────────
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0750, true);
    // حماية المجلد من الوصول المباشر
    file_put_contents(CACHE_DIR . '.htaccess', "Deny from all\n");
}

// ─── تهيئة كائن PDO المباشر للتوافق مع الكود القديم ──────────
// الكود الجديد يستخدم: Database::getInstance()
// الكود القديم يستخدم: $pdo مباشرة (متوافق)
try {
    $pdo = Database::getInstance()->getPdo();
} catch (PDOException $e) {
    // لا تكشف تفاصيل الاتصال للمستخدم في بيئة الإنتاج
    error_log('[WASL] DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('<div style="font-family:Cairo,sans-serif;text-align:center;margin-top:100px;color:#c0392b">
            <h2>⚠️ النظام غير متاح مؤقتاً</h2>
            <p>يرجى المحاولة بعد قليل أو التواصل مع الدعم التقني</p>
         </div>');
}
