<?php
// منع تخزين هذه الصفحة مؤقتاً في المتصفح (تحتوي على توكن CSRF يتغير بكل طلب)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/db.php';
require_once '../core/Auth.php';

Auth::startSecureSession();

// إعادة التوجيه إن كان مسجلاً بالفعل
if (Auth::isLoggedIn()) {
    header('Location: ../admin/index.php');
    exit;
}

$message   = '';
$alertType = '';
$email_val = '';
$locked    = false;
$lockMins  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // التحقق من CSRF
    Auth::validateCsrf($_POST['csrf_token'] ?? '');

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $email_val = htmlspecialchars($email);
    $ip        = Auth::clientIp();

    if (empty($email) || empty($password)) {
        $message   = 'الرجاء إدخال البريد الإلكتروني وكلمة المرور';
        $alertType = 'warning';

    } elseif (Auth::isBlocked($email, $ip, $pdo)) {
        $locked   = true;
        $lockMins = Auth::lockoutMinutesLeft($email, $ip, $pdo);
        $message  = "تم إيقاف تسجيل الدخول مؤقتاً بسبب محاولات متعددة. انتظر {$lockMins} دقيقة.";
        $alertType= 'danger';

    } else {
        $stmt = $pdo->prepare(
            "SELECT u.*, r.role_name, r.role_code
             FROM " . TBL_USERS . " u
             JOIN " . TBL_ROLES . " r ON r.id = u.role_id
             WHERE u.email = ? AND u.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u && password_verify($password, $u['password'])) {

            Auth::clearAttempts($email, $ip, $pdo);
            Auth::login($u);

            // تحديث آخر دخول
            $pdo->prepare("UPDATE " . TBL_USERS . " SET last_login = NOW() WHERE id = ?")
                ->execute([$u['id']]);

            // إعادة تجزئة كلمة المرور إن احتاجت
            if (password_needs_rehash($u['password'], PASSWORD_BCRYPT)) {
                $pdo->prepare("UPDATE " . TBL_USERS . " SET password = ? WHERE id = ?")
                    ->execute([password_hash($password, PASSWORD_BCRYPT), $u['id']]);
            }

            $redirect = $_GET['redirect'] ?? '../admin/index.php';
            header('Location: ' . $redirect);
            exit;

        } else {
            Auth::recordFailedAttempt($email, $ip, $pdo);
            $message   = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
            $alertType = 'danger';
        }
    }
}

// جلب اسم النظام والشعار
try {
    $sys = $pdo->query(
        "SELECT system_name, system_logo FROM " . TBL_SETTINGS . " LIMIT 1"
    )->fetch();
} catch (Exception) { $sys = []; }

$systemName = $sys['system_name'] ?? 'وَصْل';
$systemLogo = $sys['system_logo'] ?? 'logo_1777800792.png';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول – <?= htmlspecialchars($systemName) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Cairo',sans-serif;min-height:100vh;display:flex;align-items:center;
             justify-content:center;background:linear-gradient(135deg,#0d4a1c 0%,#1a6b30 40%,#21409a 100%);
             position:relative;overflow:hidden}
        body::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;
            background:rgba(255,255,255,.04);top:-150px;left:-150px;animation:float 8s ease-in-out infinite}
        body::after{content:'';position:absolute;width:350px;height:350px;border-radius:50%;
            background:rgba(255,255,255,.06);bottom:-80px;right:-80px;animation:float 10s ease-in-out infinite reverse}
        @keyframes float{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-20px) scale(1.05)}}
        .login-wrapper{display:flex;width:900px;max-width:96vw;border-radius:24px;overflow:hidden;
            box-shadow:0 30px 80px rgba(0,0,0,.4);position:relative;z-index:1;animation:slideUp .6s ease}
        @keyframes slideUp{from{opacity:0;transform:translateY(40px)}to{opacity:1;transform:translateY(0)}}
        .login-brand{flex:1;background:rgba(255,255,255,.08);backdrop-filter:blur(20px);display:flex;
            flex-direction:column;align-items:center;justify-content:center;padding:50px 30px;
            color:#fff;text-align:center;border-left:1px solid rgba(255,255,255,.1)}
        .login-brand img{width:100px;height:100px;object-fit:contain;background:#fff;border-radius:20px;
            padding:10px;margin-bottom:20px;box-shadow:0 10px 30px rgba(0,0,0,.2)}
        .login-brand h1{font-size:2rem;font-weight:800;margin-bottom:8px}
        .login-brand p{font-size:.9rem;opacity:.75;line-height:1.8;max-width:250px}
        .brand-features{margin-top:28px;display:flex;flex-direction:column;gap:10px;width:100%;max-width:230px}
        .brand-feature{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.1);
            border-radius:10px;padding:10px 15px;font-size:.83rem}
        .brand-feature i{font-size:1rem;color:#7fffb0}
        .login-form-side{width:400px;background:#fff;padding:50px 40px;display:flex;flex-direction:column;justify-content:center}
        .form-header{margin-bottom:28px;text-align:center}
        .form-header h2{font-size:1.6rem;font-weight:800;color:#1a1a2e;margin-bottom:6px}
        .form-header p{font-size:.88rem;color:#888}
        .form-group{margin-bottom:18px}
        .form-label{display:block;font-size:.86rem;font-weight:600;color:#444;margin-bottom:6px}
        .input-wrap{position:relative}
        .input-wrap>i.icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);
            color:#aaa;font-size:.92rem;pointer-events:none}
        .toggle-pass{position:absolute;left:14px;top:50%;transform:translateY(-50%);
            color:#aaa;cursor:pointer;font-size:.92rem}
        .form-control{width:100%;padding:12px 42px 12px 14px;border:1.5px solid #e0e0e0;
            border-radius:10px;font-family:'Cairo',sans-serif;font-size:.95rem;color:#333;
            transition:border-color .25s,box-shadow .25s;outline:none;direction:rtl}
        .form-control:focus{border-color:#0d4a1c;box-shadow:0 0 0 3px rgba(13,74,28,.12)}
        input[type=password].form-control{padding-left:42px}
        .alert{border-radius:10px;padding:12px 16px;font-size:.87rem;margin-bottom:18px;
            display:flex;align-items:flex-start;gap:8px}
        .alert-danger {background:#fff1f0;color:#c0392b;border:1px solid #ffc9c9}
        .alert-warning{background:#fffbe6;color:#7d6608;border:1px solid #ffe58f}
        .lock-timer{font-weight:700;font-size:1.1em}
        .btn-login{width:100%;padding:14px;background:linear-gradient(135deg,#0d4a1c,#21409a);
            color:#fff;border:none;border-radius:10px;font-family:'Cairo',sans-serif;font-size:1rem;
            font-weight:700;cursor:pointer;transition:transform .2s,box-shadow .2s}
        .btn-login:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(13,74,28,.35)}
        .btn-login:disabled{opacity:.5;cursor:not-allowed;transform:none}
        .form-footer{text-align:center;margin-top:22px;font-size:.8rem;color:#bbb}
        @media(max-width:700px){.login-brand{display:none}.login-form-side{width:100%;padding:40px 22px}}
    </style>
</head>
<body>
<div class="login-wrapper">

    <!-- يسار: علامة تجارية -->
    <div class="login-brand">
        <img src="../admin/dist/img/<?= htmlspecialchars($systemLogo) ?>"
             alt="شعار النظام"
             onerror="this.src='../admin/dist/img/avatar.png'">
        <h1><?= htmlspecialchars($systemName) ?></h1>
        <p>نظام متكامل لإدارة بلاغات الدعم الفني والصيانة</p>
        <div class="brand-features">
            <div class="brand-feature"><i class="fas fa-ticket-alt"></i> إدارة البلاغات الفنية</div>
            <div class="brand-feature"><i class="fas fa-users-cog"></i> تحكم كامل بالمستخدمين</div>
            <div class="brand-feature"><i class="fas fa-clock"></i> إدارة SLA بساعات العمل</div>
            <div class="brand-feature"><i class="fas fa-shield-alt"></i> حماية متعددة الطبقات</div>
        </div>
    </div>

    <!-- يمين: نموذج الدخول -->
    <div class="login-form-side">
        <div class="form-header">
            <h2>مرحباً بك</h2>
            <p>قم بتسجيل الدخول للمتابعة</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $alertType ?>">
            <i class="fas fa-<?= $alertType === 'danger' ? 'exclamation-circle' : 'exclamation-triangle' ?>" style="margin-top:2px"></i>
            <span>
                <?= htmlspecialchars($message) ?>
                <?php if ($locked): ?>
                <br><span class="lock-timer" id="lockTimer"><?= $lockMins ?>:00</span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= Auth::csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="emailInput">البريد الإلكتروني</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" id="emailInput" name="email" class="form-control"
                           placeholder="example@domain.com"
                           value="<?= $email_val ?>"
                           <?= $locked ? 'disabled' : '' ?> required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="passwordInput">كلمة المرور</label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" id="passwordInput" name="password" class="form-control"
                           placeholder="••••••••"
                           <?= $locked ? 'disabled' : '' ?> required>
                    <i class="fas fa-eye toggle-pass" id="togglePass"></i>
                </div>
            </div>

            <button type="submit" class="btn-login" <?= $locked ? 'disabled' : '' ?>>
                <i class="fas fa-sign-in-alt"></i> تسجيل الدخول
            </button>
        </form>

        <div class="form-footer">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($systemName) ?> – جميع الحقوق محفوظة
        </div>
    </div>
</div>

<script>
// إظهار / إخفاء كلمة المرور
document.getElementById('togglePass').addEventListener('click', function () {
    const inp = document.getElementById('passwordInput');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    this.className = 'fas fa-' + (show ? 'eye-slash' : 'eye') + ' toggle-pass';
});

<?php if ($locked && $lockMins > 0): ?>
// عداد تنازلي لوقت الحظر
(function () {
    let secs = <?= $lockMins ?> * 60;
    const el = document.getElementById('lockTimer');
    const btn = document.querySelector('.btn-login');
    const iv = setInterval(function () {
        secs--;
        if (secs <= 0) { clearInterval(iv); location.reload(); return; }
        const m = String(Math.floor(secs / 60)).padStart(2, '0');
        const s = String(secs % 60).padStart(2, '0');
        if (el) el.textContent = m + ':' + s;
    }, 1000);
})();
<?php endif; ?>
</script>
</body>
</html>
