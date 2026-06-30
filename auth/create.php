<?php
session_start();
require "../config/db.php";

$message = "";
$alertType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim inputs
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role_id = 2; // Default role, e.g., 'user'. Adjust as needed.

    if (empty($email) || empty($password)) {
        $message = "الرجاء إدخال البريد الإلكتروني وكلمة المرور";
        $alertType = "warning";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "البريد الإلكتروني غير صالح";
        $alertType = "warning";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM sys_users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $message = "هذا البريد الإلكتروني مسجل بالفعل";
                $alertType = "danger";
            } else {
                // Hash the password securely
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Insert new user
                $insert = $pdo->prepare("
                    INSERT INTO sys_users (email, password, role_id) 
                    VALUES (?, ?, ?)
                ");
                $insert->execute([$email, $hashedPassword, $role_id]);

                $message = "تم إنشاء الحساب بنجاح";
                $alertType = "success";

                // Optionally, auto-login the new user
                $user_id = $pdo->lastInsertId();
                $_SESSION['user_id'] = $user_id;

                // Fetch role name
                $roleStmt = $pdo->prepare("SELECT role_name FROM sys_roles WHERE id = ?");
                $roleStmt->execute([$role_id]);
                $_SESSION['role'] = $roleStmt->fetchColumn();

                // Redirect to dashboard
                header("Location: ../dashboard/index.php");
                exit;
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
    <title>إنشاء حساب جديد</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex justify-content-center align-items-center vh-100">

    <form method="post" class="card p-4 shadow" style="width:320px">
        <h4 class="mb-3 text-center">إنشاء حساب جديد</h4>

        <!-- رسائل التنبيه -->
        <?php if (!empty($message)) : ?>
            <div class="alert alert-<?= htmlspecialchars($alertType) ?> text-center">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <input name="email" class="form-control mb-2" placeholder="البريد الإلكتروني"
            value="<?= htmlspecialchars($email ?? '') ?>">

        <input type="password" name="password" class="form-control mb-3" placeholder="كلمة المرور">

        <button class="btn btn-success w-100">إنشاء الحساب</button>
    </form>

</body>

</html>