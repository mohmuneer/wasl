<?php
/**
 * Middleware — التحقق من رمز API قبل كل طلب
 *
 * يبحث عن الرمز في:
 *  1. Header:  Authorization: Bearer {token}
 *  2. Query:   ?api_token={token}
 */
class Middleware
{
    private static ?array $currentUser = null;

    // ─────────────────────────────────────────────
    //  تطبيق حماية API — يُوقف الطلب إن لم يكن الرمز صالحاً
    // ─────────────────────────────────────────────
    public static function auth(PDO $pdo): array
    {
        $token = self::extractToken();

        if (!$token) {
            Response::error('مطلوب رمز المصادقة (Authorization: Bearer {token})', 401);
        }

        $sql = "SELECT t.*, u.full_name, u.email, u.status as user_status,
                       r.role_code, r.role_name
                FROM api_tokens t
                JOIN sys_users u ON u.id = t.user_id
                JOIN sys_roles r ON r.id = u.role_id
                WHERE t.token = ?
                  AND t.is_active = 1
                  AND t.expires_at > NOW()
                  AND u.status = 'active'
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$token]);
        $row  = $stmt->fetch();

        if (!$row) {
            Response::error('رمز المصادقة غير صالح أو منتهي الصلاحية', 401);
        }

        // تحديث آخر استخدام للرمز
        $pdo->prepare('UPDATE api_tokens SET last_used = NOW() WHERE token = ?')
            ->execute([$token]);

        self::$currentUser = $row;
        return $row;
    }

    // ─────────────────────────────────────────────
    //  التحقق من الدور
    // ─────────────────────────────────────────────
    public static function requireRole(string ...$roles): void
    {
        $userRole = self::$currentUser['role_code'] ?? '';
        if (!in_array($userRole, $roles, true)) {
            Response::error('ليس لديك صلاحية لهذه العملية', 403);
        }
    }

    public static function user(): ?array { return self::$currentUser; }

    // ─────────────────────────────────────────────
    //  Rate Limiting بسيط (مبني على APCu إن وُجد)
    // ─────────────────────────────────────────────
    public static function rateLimit(int $maxPerMinute = 60): void
    {
        if (!function_exists('apcu_fetch')) return;

        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'rate_' . $ip . '_' . date('Hi'); // دقيقة محددة

        $count = (int)(apcu_fetch($key) ?: 0);
        if ($count >= $maxPerMinute) {
            header('Retry-After: 60');
            Response::error('تجاوزت الحد المسموح به من الطلبات. انتظر دقيقة.', 429);
        }

        apcu_store($key, $count + 1, 60);
    }

    // ─────────────────────────────────────────────
    //  CORS Headers للتطبيق الجوال
    // ─────────────────────────────────────────────
    public static function cors(): void
    {
        $allowed = defined('API_ALLOWED_ORIGINS') ? API_ALLOWED_ORIGINS : '*';

        header('Access-Control-Allow-Origin: '  . $allowed);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Max-Age: 3600');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    // ─────────────────────────────────────────────
    private static function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return $_GET['api_token'] ?? null;
    }
}
