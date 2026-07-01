<?php
/**
 * Security — مركز الحماية الثلاثية لنظام وَصْل
 *
 * 1. CSRF  — حماية جميع نماذج POST
 * 2. HTML  — تطهير مخرجات Summernote بـ HTMLPurifier
 * 3. Upload— التحقق الصارم من الملفات المرفوعة
 */
class Security
{
    // ─── CSRF ────────────────────────────────────────────────────────

    /** أرجع (أو أنشئ) توكن الجلسة */
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** حقل HTML مخفي جاهز للإدراج في النموذج */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::token() . '">';
    }

    /**
     * تحقق من التوكن — يُرسل 419 ويوقف التنفيذ إن فشل
     * يُستدعى تلقائياً من config/db.php لكل POST
     */
    public static function validatePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        // قائمة المسارات المُعفاة (AJAX داخلية تتحقق من الجلسة بطريقتها)
        $exempt = [
            'index.php',               // صفحة الدخول الرئيسية، تتحقق بنفسها عبر Auth::validateCsrf()
            'login.php',              // يتحقق بنفسه عبر Auth::validateCsrf()
            'send_message.php',
            'typing_status.php',
            'get_unread_count.php',
            'process-ai.php',
            'process-signature.php',
            'documents_dt.php',       // DataTables API
        ];

        $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        foreach ($exempt as $ex) {
            if ($script === $ex) return;
        }

        // تحقق مزدوج: POST body + referer
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$token || !hash_equals(self::token(), $token)) {
            http_response_code(419);
            // AJAX requests — أرجع JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'CSRF token invalid', 'code' => 419]);
                exit;
            }
            // طلبات عادية — صفحة خطأ
            die('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
                <title>خطأ أمني</title>
                <style>body{font-family:Cairo,sans-serif;background:#f8f9fa;display:flex;align-items:center;
                justify-content:center;min-height:100vh;margin:0}
                .box{background:#fff;border-radius:12px;padding:40px;text-align:center;
                box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:400px}
                h2{color:#c0392b}p{color:#555}</style></head><body>
                <div class="box"><h2>⚠️ انتهت صلاحية النموذج</h2>
                <p>يرجى الرجوع وإعادة إرسال النموذج.</p>
                <a href="javascript:history.back()" style="background:#2980b9;color:#fff;
                padding:10px 24px;border-radius:8px;text-decoration:none">رجوع</a></div></body></html>');
        }
        // تجديد التوكن (Single-use)
        unset($_SESSION['csrf_token']);
    }

    // ─── HTML Sanitizer (HTMLPurifier) ───────────────────────────────

    private static ?object $purifier = null;

    /**
     * تطهير HTML من Summernote/CKEditor — يحذف JS ويُبقي التنسيق الآمن
     */
    public static function sanitizeHtml(string $html): string
    {
        if (empty(trim($html))) return '';

        // تحميل HTMLPurifier عند الحاجة
        if (self::$purifier === null) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!file_exists($autoload)) {
                // Fallback بسيط إن لم يكن Composer موجوداً
                return self::fallbackSanitize($html);
            }
            require_once $autoload;

            $config = \HTMLPurifier_Config::createDefault();

            // العناصر المسموح بها
            $config->set('HTML.Allowed',
                'p,br,strong,em,u,s,strike,h3,h4,h5,h6,' .
                'ul,ol,li,blockquote,pre,code,hr,' .
                'table,thead,tbody,tr,th,td,' .
                'a[href|title|target],img[src|alt|width|height|style],' .
                'span[style],div[style],b,i'
            );

            // CSS مسموح به (من Summernote)
            $config->set('CSS.AllowedProperties',
                'font-weight,font-style,text-decoration,color,background-color,' .
                'text-align,font-size,font-family,margin,padding,border'
            );

            // السماح بروابط http/https فقط
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);

            // إزالة خصائص ID غير المرغوبة
            $config->set('Attr.EnableID', false);

            // مجلد cache
            $cacheDir = dirname(__DIR__) . '/storage/purifier_cache';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            $config->set('Cache.SerializerPath', $cacheDir);

            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier->purify($html);
    }

    /** تطهير احتياطي بسيط إن لم يتوفر HTMLPurifier */
    private static function fallbackSanitize(string $html): string
    {
        // حذف script/style/on* handlers
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        return $html;
    }

    // ─── File Upload Validator ────────────────────────────────────────

    /**
     * التحقق الصارم من الملف المرفوع
     *
     * @param array  $file         عنصر من $_FILES
     * @param string $type         نوع الملف: 'document' | 'image' | 'signature' | 'any'
     * @param int    $maxMb        الحد الأقصى بـ MB (افتراضي 20)
     * @return array ['ok'=>bool, 'error'=>string, 'ext'=>string, 'mime'=>string]
     */
    public static function validateUpload(array $file, string $type = 'any', int $maxMb = 20): array
    {
        // خريطة الأنواع المسموحة
        $allowedMap = [
            'document'  => [
                'extensions' => ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv'],
                'mimes'      => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'text/plain',
                    'text/csv',
                ],
            ],
            'image'     => [
                'extensions' => ['jpg','jpeg','png','gif','webp'],
                'mimes'      => ['image/jpeg','image/png','image/gif','image/webp'],
            ],
            'signature' => [
                'extensions' => ['png','jpg','jpeg'],
                'mimes'      => ['image/png','image/jpeg'],
            ],
            'any'       => [
                'extensions' => ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','txt','csv'],
                'mimes'      => [
                    'application/pdf','application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'image/jpeg','image/png','image/gif','text/plain','text/csv',
                ],
            ],
        ];

        $allowed = $allowedMap[$type] ?? $allowedMap['any'];

        // 1. خطأ رفع PHP
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحد المسموح به في إعدادات السيرفر',
                UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحد المسموح به في النموذج',
                UPLOAD_ERR_PARTIAL    => 'الملف لم يُرفع بالكامل',
                UPLOAD_ERR_NO_FILE    => 'لم يتم اختيار ملف',
                UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة مفقود',
                UPLOAD_ERR_CANT_WRITE => 'فشل كتابة الملف',
            ];
            return ['ok' => false, 'error' => $errors[$file['error']] ?? 'خطأ غير معروف في الرفع'];
        }

        // 2. حجم الملف
        $maxBytes = $maxMb * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => "حجم الملف يتجاوز الحد المسموح به ({$maxMb}MB)"];
        }
        if ($file['size'] === 0) {
            return ['ok' => false, 'error' => 'الملف فارغ'];
        }

        // 3. امتداد الملف (من الاسم)
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed['extensions'], true)) {
            return [
                'ok' => false,
                'error' => 'نوع الملف غير مسموح به. الأنواع المقبولة: ' . implode(', ', $allowed['extensions']),
            ];
        }

        // 4. MIME الحقيقي من محتوى الملف (ليس من المتصفح)
        $realMime = self::detectMime($file['tmp_name']);
        if (!in_array($realMime, $allowed['mimes'], true)) {
            return [
                'ok' => false,
                'error' => 'محتوى الملف لا يتطابق مع امتداده. يُحتمل أنه ملف ضار.',
            ];
        }

        // 5. منع الملفات القابلة للتنفيذ حتى لو مرّت الفحوصات السابقة
        $dangerousExt = ['php','php3','php4','php5','phtml','phar','asp','aspx','jsp','cgi','sh','exe','bat','cmd'];
        if (in_array($ext, $dangerousExt, true)) {
            return ['ok' => false, 'error' => 'هذا النوع من الملفات محظور'];
        }

        return ['ok' => true, 'error' => '', 'ext' => $ext, 'mime' => $realMime];
    }

    /** قراءة MIME الحقيقي من bytes الملف */
    private static function detectMime(string $tmpPath): string
    {
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            return $mime ?: 'application/octet-stream';
        }
        if (function_exists('mime_content_type')) {
            return mime_content_type($tmpPath) ?: 'application/octet-stream';
        }
        // Fallback: قراءة magic bytes
        $handle = fopen($tmpPath, 'rb');
        $bytes  = fread($handle, 8);
        fclose($handle);
        // PDF
        if (str_starts_with($bytes, '%PDF'))     return 'application/pdf';
        // JPEG
        if (substr($bytes, 0, 2) === "\xFF\xD8") return 'image/jpeg';
        // PNG
        if (str_starts_with($bytes, "\x89PNG"))  return 'image/png';
        // GIF
        if (str_starts_with($bytes, 'GIF8'))     return 'image/gif';
        // ZIP-based (docx, xlsx, pptx)
        if (substr($bytes, 0, 2) === 'PK')       return 'application/zip';

        return 'application/octet-stream';
    }

    /**
     * توليد اسم ملف آمن ومميز
     * يمنع directory traversal وأسماء الملفات الخطرة
     */
    public static function safeFilename(string $originalName, string $prefix = ''): string
    {
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext  = preg_replace('/[^a-z0-9]/', '', $ext);
        $name = ($prefix ?: 'file') . '_' . time() . '_' . bin2hex(random_bytes(4));
        return $name . ($ext ? '.' . $ext : '');
    }
}
