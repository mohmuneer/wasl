<?php
/**
 * نظام التدويل (i18n) للوحات إدارة الوثائق
 * 
 * الاستخدام:
 *   require __DIR__ . '/../../lang/init.php';
 *   echo __('doc_title');
 *   echo __n('doc', 2); // صيغة الجمع إن لزم
 */

// تحديد اللغة
$availableLangs = ['ar', 'en'];
$defaultLang = 'ar';

// محاولة قراءة اللغة الافتراضية من الإعدادات
if (!isset($_SESSION['dms_lang'])) {
    try {
        $pdoLocal = $GLOBALS['pdo'] ?? null;
        if ($pdoLocal) {
            $stmt = $pdoLocal->query("SELECT dms_default_lang FROM sys_settings LIMIT 1");
            $dbLang = $stmt->fetchColumn();
            if ($dbLang && in_array($dbLang, $availableLangs)) {
                $defaultLang = $dbLang;
            }
        }
    } catch (Exception $e) {}
}

// قراءة اللغة من الجلسة أولاً، ثم من الإعدادات
$langCode = $_SESSION['dms_lang'] ?? $defaultLang;

// إذا أرسل المستخدم تغيير اللغة عبر GET
if (isset($_GET['lang']) && in_array($_GET['lang'], $availableLangs)) {
    $langCode = $_GET['lang'];
    $_SESSION['dms_lang'] = $langCode;
    // إعادة التوجيه لنفس الصفحة بدون param lang
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $redirectUrl");
    exit;
}

// تحميل ملف اللغة
$langFile = __DIR__ . "/$langCode.php";
if (!file_exists($langFile)) {
    $langCode = $defaultLang;
    $langFile = __DIR__ . "/$defaultLang.php";
}

$translations = require $langFile;
$currentLang = $langCode;

/**
 * دالة الترجمة
 * @param string $key مفتاح الترجمة
 * @param array $replace قيم الاستبدال (مثل ['name' => 'أحمد'])
 * @return string
 */
function __($key, $replace = []) {
    global $translations;
    $text = $translations[$key] ?? $key;
    if (!empty($replace)) {
        foreach ($replace as $k => $v) {
            $text = str_replace(":$k", $v, $text);
        }
    }
    return $text;
}

/**
 * الحصول على كود اللغة الحالي
 */
function getLang() {
    global $currentLang, $defaultLang;
    return $currentLang ?? $defaultLang;
}

/**
 * هل اللغة الحالية هي العربية؟
 */
function isRtl() {
    return getLang() === 'ar';
}

/**
 * إضافة رابط تبديل اللغة للصفحة
 */
function langSwitcher() {
    $current = getLang();
    $target = $current === 'ar' ? 'en' : 'ar';
    $label = $target === 'ar' ? 'العربية' : 'English';
    $icon = $target === 'ar' ? 'sa' : 'us';
    return '<a href="?lang=' . $target . '" class="btn btn-sm btn-outline-info ml-2" title="' . $label . '">
        <i class="fas fa-language"></i> ' . $label . '
    </a>';
}
