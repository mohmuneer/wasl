<?php
/**
 * Auth — الحارس الأمني لنظام وَصْل
 *
 * يوفر:
 *  - فحص الجلسة وإعادة التوجيه
 *  - حماية CSRF على جميع نماذج POST
 *  - منع Brute Force (قفل تلقائي بعد 5 محاولات خلال 15 دقيقة)
 *  - إدارة دورة حياة الجلسة بأمان
 */
class Auth
{
    private const MAX_ATTEMPTS     = 5;
    private const LOCKOUT_MINUTES  = 15;
    private const SESSION_LIFETIME = 7200;   // ثانيتان = ساعتان

    // ─────────────────────────────────────────────
    //  فحص وتهيئة الجلسة
    // ─────────────────────────────────────────────
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Strict');
            session_start();
        }

        // تجديد معرف الجلسة دورياً لمنع Session Fixation
        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
        }

        // انتهاء الجلسة التلقائي
        if (isset($_SESSION['_last_activity'])) {
            if (time() - $_SESSION['_last_activity'] > self::SESSION_LIFETIME) {
                self::logout();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();
    }

    // ─────────────────────────────────────────────
    //  هل المستخدم مسجّل الدخول؟
    // ─────────────────────────────────────────────
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['email']);
    }

    // ─────────────────────────────────────────────
    //  إلزامية المصادقة — يُستخدَم في أعلى كل صفحة محمية
    //
    //  Auth::requireAuth();   ← يعيد التوجيه للوجين إن لم تكن مسجلاً
    //  Auth::requireAuth('MainAdmin');  ← يتطلب دور معين
    // ─────────────────────────────────────────────
    public static function requireAuth(?string $requiredRole = null): void
    {
        self::startSecureSession();

        if (!self::isLoggedIn()) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . self::loginUrl() . ($redirect ? '?redirect=' . $redirect : ''));
            exit;
        }

        if ($requiredRole && ($_SESSION['role_code'] ?? '') !== $requiredRole) {
            http_response_code(403);
            die('<div style="font-family:Cairo,sans-serif;text-align:center;margin-top:80px">
                    <h2 style="color:#c0392b">⛔ غير مصرح</h2>
                    <p>ليس لديك صلاحية للوصول لهذه الصفحة</p>
                    <a href="' . self::baseUrl() . '/admin/index.php">العودة للوحة التحكم</a>
                 </div>');
        }
    }

    // ─────────────────────────────────────────────
    //  قراءة بيانات المستخدم الحالي
    // ─────────────────────────────────────────────
    public static function user(): array
    {
        return [
            'id'        => $_SESSION['user_id']   ?? 0,
            'name'      => $_SESSION['full_name']  ?? '',
            'email'     => $_SESSION['email']      ?? '',
            'role_code' => $_SESSION['role_code']  ?? '',
            'role_name' => $_SESSION['role_name']  ?? '',
            'file_path' => $_SESSION['file_path']  ?? '',
        ];
    }

    public static function id(): int     { return (int)($_SESSION['user_id']   ?? 0); }
    public static function role(): string{ return $_SESSION['role_code'] ?? ''; }
    public static function isAdmin(): bool { return self::role() === 'MainAdmin'; }

    // ─────────────────────────────────────────────
    //  تسجيل الدخول (حفظ الجلسة)
    //  $userRow = صف من قاعدة البيانات
    // ─────────────────────────────────────────────
    public static function login(array $userRow): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id']   = $userRow['id'];
        $_SESSION['full_name'] = $userRow['full_name'];
        $_SESSION['email']     = $userRow['email'];
        $_SESSION['role_code'] = $userRow['role_code'];
        $_SESSION['role_name'] = $userRow['role_name'];
        $_SESSION['file_path'] = $userRow['file_path'] ?? '';
        $_SESSION['_initiated']     = true;
        $_SESSION['_last_activity'] = time();
    }

    // ─────────────────────────────────────────────
    //  تسجيل الخروج
    // ─────────────────────────────────────────────
    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        header('Location: ' . self::loginUrl());
        exit;
    }

    // ─────────────────────────────────────────────
    //  حماية CSRF
    // ─────────────────────────────────────────────
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // أرجع حقل HTML مخفي جاهز للإدراج في النموذج
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::csrfToken() . '">';
    }

    // تحقق من التوكن — يرمي استثناء إن كان غير صالح
    public static function validateCsrf(?string $token): void
    {
        if (!$token || !hash_equals(self::csrfToken(), $token)) {
            http_response_code(419);
            die('<div style="font-family:Cairo,sans-serif;text-align:center;margin-top:80px">
                    <h2 style="color:#c0392b">⚠️ طلب غير صالح</h2>
                    <p>انتهت صلاحية الجلسة. يرجى الرجوع وإعادة المحاولة.</p>
                    <a href="javascript:history.back()">رجوع</a>
                 </div>');
        }
        // تجديد التوكن بعد التحقق (Single-use)
        unset($_SESSION['csrf_token']);
    }

    // ─────────────────────────────────────────────
    //  Brute Force Protection
    // ─────────────────────────────────────────────

    // سجّل محاولة فاشلة
    public static function recordFailedAttempt(string $identifier, string $ip, PDO $pdo): void
    {
        $sql = 'INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)';
        $pdo->prepare($sql)->execute([strtolower($identifier), $ip]);
    }

    // هل هذا المعرّف / العنوان محظور؟
    public static function isBlocked(string $identifier, string $ip, PDO $pdo): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
        $sql   = 'SELECT COUNT(*) FROM login_attempts
                  WHERE (identifier = ? OR ip_address = ?)
                    AND attempted_at >= ?';
        $count = (int)$pdo->prepare($sql)->execute([strtolower($identifier), $ip, $since])
                 ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn()
                 : 0;

        // استخدام الطريقة الصحيحة
        $stmt = $pdo->prepare($sql);
        $stmt->execute([strtolower($identifier), $ip, $since]);
        return (int)$stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    // المدة المتبقية للحظر بالدقائق
    public static function lockoutMinutesLeft(string $identifier, string $ip, PDO $pdo): int
    {
        $sql  = 'SELECT MIN(attempted_at) as oldest FROM login_attempts
                 WHERE (identifier = ? OR ip_address = ?)
                   AND attempted_at >= ?';
        $since = date('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
        $stmt  = $pdo->prepare($sql);
        $stmt->execute([strtolower($identifier), $ip, $since]);
        $oldest = $stmt->fetchColumn();

        if (!$oldest) return 0;

        $unblockAt = strtotime($oldest) + self::LOCKOUT_MINUTES * 60;
        return max(0, (int)ceil(($unblockAt - time()) / 60));
    }

    // امسح المحاولات بعد نجاح الدخول
    public static function clearAttempts(string $identifier, string $ip, PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? OR ip_address = ?')
            ->execute([strtolower($identifier), $ip]);
    }

    // ─────────────────────────────────────────────
    //  مساعدات
    // ─────────────────────────────────────────────
    public static function loginUrl(): string
    {
        $base = rtrim(self::baseUrl(), '/');
        return $base . '/auth/login.php';
    }

    public static function baseUrl(): string
    {
        if (defined('BASE_URL')) return BASE_URL;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        return rtrim($scheme . '://' . $host . $dir, '/');
    }

    // الحصول على IP العميل الحقيقي (خلف Proxy)
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
