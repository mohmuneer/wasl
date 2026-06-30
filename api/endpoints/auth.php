<?php
/**
 * /api?endpoint=auth
 *
 * POST &action=login    → تسجيل الدخول وإعادة رمز API
 * POST &action=logout   → إلغاء الرمز الحالي
 * GET  &action=me       → بيانات المستخدم الحالي
 *
 * المتغيرات التالية مُحقَنة من api/index.php عبر require:
 * @var string $method   طريقة HTTP (GET / POST / PUT / DELETE)
 * @var string $action   الإجراء المطلوب من query string
 * @var array  $body     جسم الطلب JSON مُحوَّلاً إلى مصفوفة
 * @var PDO    $pdo      اتصال قاعدة البيانات
 */

require_once BASE_PATH . '/core/Auth.php';

// ─── المتغيرات مُحقَنة من api/index.php (قيم احتياطية للتحليل الساكن) ───
$method ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action ??= '';
$body   ??= [];
$pdo    ??= Database::getInstance()->getPdo();

$db = Database::getInstance();

// ─── تسجيل الدخول ─────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {

    $email    = trim($body['email']    ?? '');
    $password = trim($body['password'] ?? '');
    $device   = trim($body['device']   ?? 'unknown');
    $devType  = in_array($body['device_type'] ?? '', ['ios','android','web','other'])
                ? $body['device_type'] : 'other';
    $ip       = Auth::clientIp();

    if (!$email || !$password) {
        Response::error('البريد الإلكتروني وكلمة المرور مطلوبان', 422);
    }

    // Brute Force فحص
    if (Auth::isBlocked($email, $ip, $pdo)) {
        $mins = Auth::lockoutMinutesLeft($email, $ip, $pdo);
        Response::error("الحساب مُقفَل مؤقتاً. حاول بعد {$mins} دقيقة.", 429);
    }

    // جلب المستخدم
    $sql  = "SELECT u.*, r.role_name, r.role_code
             FROM sys_users u
             JOIN sys_roles r ON r.id = u.role_id
             WHERE u.email = ? AND u.status = 'active'
             LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        Auth::recordFailedAttempt($email, $ip, $pdo);
        Response::error('البريد الإلكتروني أو كلمة المرور غير صحيحة', 401);
    }

    Auth::clearAttempts($email, $ip, $pdo);

    // إنشاء رمز API
    $token = bin2hex(random_bytes(32));

    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $pdo->prepare(
        "INSERT INTO api_tokens (user_id, token, device_name, device_type, expires_at)
         VALUES (?,?,?,?,?)"
    )->execute([$user['id'], $token, $device, $devType, $expiresAt]);

    // تحديث آخر دخول
    $pdo->prepare("UPDATE sys_users SET last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);

    Response::success([
        'token'      => $token,
        'expires_in' => 30 * 24 * 3600,   // 30 يوماً بالثواني
        'user' => [
            'id'        => $user['id'],
            'name'      => $user['full_name'],
            'email'     => $user['email'],
            'role'      => $user['role_code'],
            'role_name' => $user['role_name'],
            'avatar'    => $user['file_path'] ?? null,
        ],
    ], 'تم تسجيل الدخول بنجاح');
}

// ─── تسجيل الخروج ─────────────────────────────────────────
if ($method === 'POST' && $action === 'logout') {
    $me = Middleware::auth($pdo);

    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = trim(str_replace('Bearer', '', $token));

    $pdo->prepare("UPDATE api_tokens SET is_active = 0 WHERE token = ?")
        ->execute([$token]);

    Response::success(null, 'تم تسجيل الخروج');
}

// ─── بيانات المستخدم الحالي ───────────────────────────────
if ($method === 'GET' && ($action === 'me' || $action === '')) {
    $me = Middleware::auth($pdo);

    Response::success([
        'id'        => $me['user_id'],
        'name'      => $me['full_name'],
        'email'     => $me['email'],
        'role'      => $me['role_code'],
        'role_name' => $me['role_name'],
    ]);
}

Response::error('الإجراء غير معروف', 404);
