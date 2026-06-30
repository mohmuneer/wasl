<?php
// يضمن مساراً صالحاً وقابلاً للكتابة لتخزين الجلسات قبل أي session_start()
// ضروري على الاستضافة المشتركة (InfinityFree) حيث المسار الافتراضي قد لا يكون موثوقاً
if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0750, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
        $htaccess = $sessionPath . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
    }
}
