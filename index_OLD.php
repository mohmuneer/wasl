<?php
session_start();
require_once __DIR__ . "/config/db.php";

// --- كود التحقق من وجود مستخدمين ---
try {
    $checkUsers = $pdo->query("SELECT COUNT(*) FROM sys_users");
    $userCount = $checkUsers->fetchColumn();

    if ($userCount == 0) {
        // إذا لم يوجد مستخدمين، توجه إلى صفحة إنشاء الأدمن
        header("Location: /ustcrmproject/auth/setup-admin.php"); // تأكد من اسم الملف ومساره
        exit;
    }
} catch (PDOException $e) {
    // في حال وجود مشكلة في الجدول أصلاً
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}
// ----------------------------------


$systemStmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$systemData = $systemStmt->fetch(PDO::FETCH_ASSOC);

$message = "";
$alertType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $message = "الرجاء إدخال البريد الإلكتروني وكلمة المرور";
        $alertType = "warning";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT u.*, r.role_name 
                FROM sys_users u 
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN sys_roles r ON r.id = ur.role_id
                WHERE u.email = ?
            ");
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($u && password_verify($password, $u['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $u['id'];
                $_SESSION['full_name'] = $u['full_name'];
                $_SESSION['file_path'] = $u['file_path'];

                header("Location:/Ultimate-Solutions/admin/index.php");
                exit;
            } else {
                $message = "البيانات المدخلة غير صحيحة";
                $alertType = "danger";
            }
        } catch (PDOException $e) {
            $message = "خطأ في الاتصال بقاعدة البيانات";
            $alertType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | UST</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary:rgb(20, 102, 41);
            --dark-blue: #025a87;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #025a87 0%, rgb(20, 102, 41) 100%);
            background-attachment: fixed;
            padding: 15px;
            overflow: hidden; /* لمنع ظهور شريط التمرير بسبب الأشكال العائمة */
            position: relative;
        }

        /* --- كود الأشكال العائمة في الخلفية --- */
        .background-shapes {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }

        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            border-radius: 20px;
            animation: float 20s linear infinite;
        }

        .shape:nth-child(1) { width: 100px; height: 100px; top: 10%; left: 10%; animation-duration: 15s; }
        .shape:nth-child(2) { width: 150px; height: 150px; top: 70%; left: 80%; border-radius: 50%; animation-duration: 25s; }
        .shape:nth-child(3) { width: 80px; height: 80px; top: 40%; left: 85%; animation-duration: 18s; }
        .shape:nth-child(4) { width: 120px; height: 120px; top: 80%; left: 15%; border-radius: 50%; animation-duration: 22s; }
        .shape:nth-child(5) { width: 60px; height: 60px; top: 20%; left: 70%; animation-duration: 12s; }

        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); opacity: 0; }
            50% { opacity: 0.5; }
            100% { transform: translateY(-100vh) rotate(360deg); opacity: 0; }
        }
        /* -------------------------------------- */

        .login-container {
            position: relative;
            z-index: 10; /* فوق الأشكال */
            width: 100%;
            max-width: 900px;
            display: flex;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        /* التصميم المشترك للدائرة */
        .circle-logo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            border: 2px solid #f0f0f0;
            margin-bottom: 20px;
        }

        .circle-logo::before {
            content: '';
            position: absolute;
            width: 112%;
            height: 112%;
            border-radius: 50%;
            border: 2px dashed var(--primary);
            animation: rotateLogo 12s linear infinite;
        }

        @keyframes rotateLogo {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .circle-logo img {
            width: 80%;
            height: 80%;
            object-fit: contain;
            z-index: 5;
        }

        .brand-section {
            flex: 1;
            background: rgba(250, 250, 250, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            border-left: 1px solid #f0f0f0;
        }

        .form-section {
            flex: 1.2;
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #fff;
        }

        .mobile-logo-box {
            display: none;
            justify-content: center;
            margin-bottom: 10px;
        }

        h1 { font-size: 24px; color: var(--dark-blue); text-align: center; margin-bottom: 5px; }
        h3 { font-size: 14px; color: #777; text-align: center; margin-bottom: 30px; font-weight: 400; }

        .input-group {
            position: relative;
            margin-bottom: 18px;
        }

        .input-group i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            font-size: 20px;
        }

        .input-group input {
            width: 100%;
            padding: 14px 45px 14px 15px;
            border: 1.5px solid #eee;
            border-radius: 12px;
            outline: none;
            transition: 0.3s;
            background: #fdfdfd;
        }

        button {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
        }

        button:hover { background: var(--dark-blue); transform: translateY(-2px); }

        .footer-text { margin-top: 30px; text-align: center; font-size: 11px; color: #bbb; }

        @media (max-width: 768px) {
            body { overflow-y: auto; } /* السماح بالتمرير في الجوال */
            .login-container { flex-direction: column; max-width: 400px; margin: 20px auto; }
            .brand-section { display: none; }
            .mobile-logo-box { display: flex; }
            .circle-logo { width: 120px; height: 120px; }
        }
    </style>
</head>
<body>

    <div class="background-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <div class="login-container">
        <div class="brand-section">
            <div class="circle-logo">
                <img src="/Ultimate-Solutions/admin/dist/img/<?php echo $systemData['system_logo'] ?? 'default-login.jpg'; ?>" alt="Logo">
            </div>
            <h2 style="color: #999;">
    <?php echo htmlspecialchars($systemData['system_name'] ?? 'جامعة العلوم والتكنولوجيا'); ?></h2>
           
        </div>

        <div class="form-section">
            <div class="mobile-logo-box">
                <div class="circle-logo">
                    <img src="/Ultimate-Solutions/admin/dist/img/<?php echo $systemData['system_logo'] ?? 'default-login.jpg'; ?>" alt="Logo">
                </div>
            </div>

            <h1>تسجيل الدخول</h1>
            <h3><?php echo $systemData['system_name'] ?? 'نظام إدارة المشاكل التقنية'; ?></h3>

            <?php if (!empty($message)) : ?>
                <div class="alert" style="background:#ffeeee; color:#d32f2f; padding:10px; border-radius:10px; margin-bottom:20px; text-align:center; border:1px solid #ffcccc; font-size:13px;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="input-group">
                    <i class='bx bx-user'></i>
                    <input type="email" name="email" placeholder="البريد الإلكتروني" value="<?= htmlspecialchars($email ?? '') ?>" required autocomplete="off">
                </div>
                
                <div class="input-group">
                    <i class='bx bx-lock-alt'></i>
                    <input type="password" name="password" placeholder="كلمة المرور" required>
                </div>

                <button type="submit">دخول</button>
            </form>

            <div class="footer-text">
                جميع الحقوق محفوظة &copy; فريق الدعم الفني 2026
            </div>
        </div>
    </div>

</body>
</html>