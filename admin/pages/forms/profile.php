<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: ../../../auth/login.php");
    exit;
}

// جلب بيانات المستخدم
$stmt = $pdo->prepare("
    SELECT u.*, r.role_name
    FROM sys_users u
    LEFT JOIN sys_roles r ON r.id = u.role_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("المستخدم غير موجود");
}

$success = $error = '';

// ── معالجة تغيير كلمة المرور ─────────────────────────────────────────────────
if (isset($_POST['change_password'])) {
    $current  = $_POST['current_password'] ?? '';
    $newPass  = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($newPass) || empty($confirm)) {
        $error = 'يرجى ملء جميع الحقول.';
    } elseif (!password_verify($current, $user['password'])) {
        $error = 'كلمة المرور الحالية غير صحيحة.';
    } elseif (strlen($newPass) < 6) {
        $error = 'يجب أن تكون كلمة المرور الجديدة 6 أحرف على الأقل.';
    } elseif ($newPass !== $confirm) {
        $error = 'كلمة المرور الجديدة وتأكيدها غير متطابقين.';
    } else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE sys_users SET password = ? WHERE id = ?")->execute([$hash, $user_id]);
        log_action($pdo, 'update', 'مستخدم', $user_id, ['password' => '***'], ['password' => '***']);
        $success = 'تم تغيير كلمة المرور بنجاح.';
    }
}

// ── معالجة تغيير الصورة ───────────────────────────────────────────────────────
if (isset($_POST['change_avatar']) && isset($_FILES['avatar'])) {
    $file    = $_FILES['avatar'];
    $uploadCheck = Security::validateUpload($file, 'image', 2);

    if (!$uploadCheck['ok']) {
        $error = $uploadCheck['error'];
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'حدث خطأ أثناء رفع الملف.';
    } else {
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newName  = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
        $destDir  = __DIR__ . '/../../../uploads/';
        $destPath = $destDir . $newName;

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $pdo->prepare("UPDATE sys_users SET file_path = ? WHERE id = ?")->execute([$newName, $user_id]);
            log_action($pdo, 'update', 'مستخدم', $user_id, [], ['file_path' => $newName]);
            $_SESSION['file_path'] = $newName;
            $user['file_path']     = $newName;
            $success = 'تم تحديث الصورة الشخصية بنجاح.';
        } else {
            $error = 'فشل في رفع الصورة. تحقق من صلاحيات المجلد.';
        }
    }
}

// مسار صورة المستخدم
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 4), '/');
$uploadsDir = __DIR__ . '/../../../uploads/';
$avatarPath = (!empty($user['file_path']) && file_exists($uploadsDir . $user['file_path']))
    ? $baseUrl . '/uploads/' . $user['file_path']
    : $baseUrl . '/admin/dist/img/avatar5.png';

// إحصائيات المستخدم
$myTasksCount = (int)$pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE assigned_to = ?")
    ->execute([$user_id]) ? $pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_to = $user_id")->fetchColumn() : 0;
$myDoneCount  = (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_to = $user_id AND status IN ('Resolved','مكتمل')")->fetchColumn();
$myMsgsCount  = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE sender_id = $user_id OR receiver_id = $user_id")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>الملف الشخصي</title>

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">

    <style>
    html,
    body {
        overflow-x: hidden !important;
        scrollbar-width: none !important;
    }

    ::-webkit-scrollbar {
        display: none !important;
    }

    .profile-hero {
        background: linear-gradient(135deg, #0d4a1c 0%, #1a5f2e 50%, #21409a 100%);
        border-radius: 16px;
        padding: 40px 30px;
        color: white;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .profile-hero::before {
        content: '';
        position: absolute;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.05);
        top: -60px;
        left: -60px;
    }

    .profile-hero::after {
        content: '';
        position: absolute;
        width: 150px;
        height: 150px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.05);
        bottom: -40px;
        right: -40px;
    }

    .avatar-wrap {
        position: relative;
        display: inline-block;
        margin-bottom: 16px;
    }

    .avatar-wrap img {
        width: 110px;
        height: 110px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid rgba(255, 255, 255, 0.5);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
    }

    .avatar-upload-btn {
        position: absolute;
        bottom: 4px;
        left: 4px;
        background: #fff;
        color: #0d4a1c;
        border: none;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        font-size: 0.8rem;
        transition: transform 0.2s;
    }

    .avatar-upload-btn:hover {
        transform: scale(1.1);
    }

    .stat-pill {
        background: rgba(255, 255, 255, 0.15);
        border-radius: 20px;
        padding: 10px 20px;
        display: inline-block;
        margin: 4px;
        text-align: center;
        backdrop-filter: blur(5px);
    }

    .stat-pill strong {
        display: block;
        font-size: 1.4rem;
        font-weight: 800;
    }

    .stat-pill small {
        font-size: 0.75rem;
        opacity: 0.8;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f0f0f0;
        font-size: 0.9rem;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-row .label {
        color: #888;
        font-weight: 500;
    }

    .info-row .value {
        font-weight: 600;
        color: #333;
    }

    .section-card {
        border-radius: 14px !important;
        border: none !important;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.07) !important;
    }

    .section-card .card-header {
        background: white !important;
        border-bottom: 2px solid #f0f0f0 !important;
        padding: 16px 20px !important;
        border-radius: 14px 14px 0 0 !important;
    }

    .section-card .card-header h6 {
        margin: 0;
        font-weight: 700;
        color: #333;
    }

    .strength-bar {
        height: 6px;
        border-radius: 3px;
        background: #e9ecef;
        overflow: hidden;
        margin-top: 6px;
    }

    .strength-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.3s, background 0.3s;
    }
    </style>
</head>

<body class="hold-transition layout-fixed">
    <div class="wrapper">

        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <section class="content-header" style="padding: 20px 20px 0;">
                <div class="container-fluid">
                    <h4 class="mb-0 font-weight-bold" style="color:#1a1a2e;">
                        <i class="fas fa-user-circle text-success mr-2"></i>
                        الملف الشخصي
                    </h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                            <li class="breadcrumb-item active">الملف الشخصي</li>
                        </ol>
                    </nav>
                </div>
            </section>

            <section class="content" style="padding: 16px 20px;">
                <div class="container-fluid">

                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle ml-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle ml-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                    <?php endif; ?>

                    <div class="row">

                        <!-- العمود الأيمن: البطاقة الشخصية -->
                        <div class="col-lg-4 mb-4">
                            <div class="profile-hero mb-3">
                                <!-- رفع الصورة -->
                                <form method="POST" enctype="multipart/form-data" id="avatarForm">
                                    <div class="avatar-wrap">
                                        <img src="<?= $avatarPath ?>" id="avatarPreview" alt="صورة المستخدم">
                                        <label class="avatar-upload-btn" title="تغيير الصورة">
                                            <i class="fas fa-camera"></i>
                                            <input type="file" name="avatar" id="avatarInput" accept="image/*"
                                                style="display:none;">
                                        </label>
                                    </div>
                                    <button type="submit" name="change_avatar" id="saveAvatarBtn"
                                        class="btn btn-sm btn-light d-none mb-2">
                                        <i class="fas fa-save ml-1"></i> حفظ الصورة
                                    </button>
                                </form>

                                <h5 class="font-weight-bold mb-1"><?= htmlspecialchars($user['full_name']) ?></h5>
                                <p style="opacity:0.7; font-size:0.85rem; margin-bottom:16px;">
                                    <?= htmlspecialchars($user['role_name'] ?? 'مستخدم') ?>
                                </p>

                                <div>
                                    <div class="stat-pill">
                                        <strong><?= $myTasksCount ?></strong>
                                        <small>مهامي</small>
                                    </div>
                                    <div class="stat-pill">
                                        <strong><?= $myDoneCount ?></strong>
                                        <small>مكتملة</small>
                                    </div>
                                    <div class="stat-pill">
                                        <strong><?= $myMsgsCount ?></strong>
                                        <small>رسائل</small>
                                    </div>
                                </div>
                            </div>

                            <!-- معلومات الحساب -->
                            <div class="card section-card">
                                <div class="card-header">
                                    <h6><i class="fas fa-info-circle text-primary ml-2"></i>معلومات الحساب</h6>
                                </div>
                                <div class="card-body px-4 py-2">
                                    <div class="info-row">
                                        <span class="label"><i class="fas fa-envelope ml-1"></i>البريد الإلكتروني</span>
                                        <span class="value"><?= htmlspecialchars($user['email']) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label"><i class="fas fa-shield-alt ml-1"></i>الدور الوظيفي</span>
                                        <span class="value"><?= htmlspecialchars($user['role_name'] ?? '—') ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label"><i class="fas fa-toggle-on ml-1"></i>الحالة</span>
                                        <span class="value">
                                            <?php if (($user['status'] ?? '') === 'active'): ?>
                                            <span class="badge badge-success"
                                                style="border-radius:10px; padding:4px 12px;">نشط</span>
                                            <?php else: ?>
                                            <span class="badge badge-secondary"
                                                style="border-radius:10px; padding:4px 12px;">غير نشط</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label"><i class="fas fa-calendar ml-1"></i>تاريخ الانضمام</span>
                                        <span
                                            class="value"><?= isset($user['created_at']) ? date('Y/m/d', strtotime($user['created_at'])) : '—' ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- العمود الأيسر: تغيير كلمة المرور -->
                        <div class="col-lg-8 mb-4">
                            <div class="card section-card">
                                <div class="card-header">
                                    <h6><i class="fas fa-lock text-danger ml-2"></i>تغيير كلمة المرور</h6>
                                </div>
                                <div class="card-body p-4">
                                    <form method="POST" autocomplete="off">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">كلمة المرور الحالية</label>
                                                <div class="input-group">
                                                    <input type="password" name="current_password" id="cur_pass"
                                                        class="form-control" placeholder="••••••••" required>
                                                    <div class="input-group-append">
                                                        <button type="button"
                                                            class="btn btn-outline-secondary toggle-eye"
                                                            data-target="cur_pass">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">كلمة المرور الجديدة</label>
                                                <div class="input-group">
                                                    <input type="password" name="new_password" id="new_pass"
                                                        class="form-control" placeholder="6 أحرف على الأقل"
                                                        oninput="checkStrength(this.value)">
                                                    <div class="input-group-append">
                                                        <button type="button"
                                                            class="btn btn-outline-secondary toggle-eye"
                                                            data-target="new_pass">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="strength-bar mt-2">
                                                    <div class="strength-fill" id="strengthFill" style="width:0%"></div>
                                                </div>
                                                <small id="strengthLabel" class="text-muted"></small>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">تأكيد كلمة المرور الجديدة</label>
                                                <div class="input-group">
                                                    <input type="password" name="confirm_password" id="conf_pass"
                                                        class="form-control" placeholder="أعد كتابة كلمة المرور">
                                                    <div class="input-group-append">
                                                        <button type="button"
                                                            class="btn btn-outline-secondary toggle-eye"
                                                            data-target="conf_pass">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="alert alert-light border"
                                            style="border-radius:10px; font-size:0.85rem;">
                                            <i class="fas fa-lightbulb text-warning ml-2"></i>
                                            <strong>نصيحة أمنية:</strong> استخدم مزيجاً من الأحرف الكبيرة والصغيرة
                                            والأرقام والرموز لكلمة مرور أقوى.
                                        </div>

                                        <button type="submit" name="change_password" class="btn btn-primary px-5">
                                            <i class="fas fa-save ml-2"></i>حفظ كلمة المرور
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- بطاقة إحصائيات سريعة -->
                            <div class="card section-card mt-3">
                                <div class="card-header">
                                    <h6><i class="fas fa-chart-bar text-info ml-2"></i>إحصائياتي السريعة</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div style="font-size:2rem; font-weight:800; color:#0d4a1c;">
                                                <?= $myTasksCount ?></div>
                                            <div class="text-muted" style="font-size:0.82rem;">إجمالي مهامي</div>
                                        </div>
                                        <div class="col-4">
                                            <div style="font-size:2rem; font-weight:800; color:#198754;">
                                                <?= $myDoneCount ?></div>
                                            <div class="text-muted" style="font-size:0.82rem;">مهام مكتملة</div>
                                        </div>
                                        <div class="col-4">
                                            <div style="font-size:2rem; font-weight:800; color:#0d6efd;">
                                                <?= $myMsgsCount ?></div>
                                            <div class="text-muted" style="font-size:0.82rem;">رسائلي</div>
                                        </div>
                                    </div>
                                    <?php if ($myTasksCount > 0): ?>
                                    <div class="mt-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small class="text-muted">نسبة إنجاز مهامي</small>
                                            <small
                                                class="font-weight-bold"><?= round($myDoneCount / $myTasksCount * 100) ?>%</small>
                                        </div>
                                        <div class="progress" style="height:8px; border-radius:4px;">
                                            <div class="progress-bar bg-success"
                                                style="width:<?= round($myDoneCount / $myTasksCount * 100) ?>%; border-radius:4px;">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.row -->
                </div>
            </section>
        </div><!-- /.content-wrapper -->

        <footer class="main-footer">
            <?php include('../../main-footer.php') ?>
        </footer>
        <aside class="control-sidebar control-sidebar-dark"></aside>
    </div><!-- ./wrapper -->

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    // ── معاينة الصورة قبل الرفع ──
    document.getElementById('avatarInput').addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').src = e.target.result;
            document.getElementById('saveAvatarBtn').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    });

    // ── إظهار/إخفاء كلمة المرور ──
    document.querySelectorAll('.toggle-eye').forEach(btn => {
        btn.addEventListener('click', function() {
            const input = document.getElementById(this.dataset.target);
            const icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });

    // ── مؤشر قوة كلمة المرور ──
    function checkStrength(val) {
        const fill = document.getElementById('strengthFill');
        const label = document.getElementById('strengthLabel');
        let score = 0;
        if (val.length >= 6) score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const levels = [{
                w: '0%',
                bg: '#e9ecef',
                txt: ''
            },
            {
                w: '25%',
                bg: '#dc3545',
                txt: 'ضعيفة جداً'
            },
            {
                w: '45%',
                bg: '#fd7e14',
                txt: 'ضعيفة'
            },
            {
                w: '65%',
                bg: '#ffc107',
                txt: 'متوسطة'
            },
            {
                w: '85%',
                bg: '#198754',
                txt: 'قوية'
            },
            {
                w: '100%',
                bg: '#0d4a1c',
                txt: 'قوية جداً'
            },
        ];
        const lv = levels[Math.min(score, 5)];
        fill.style.width = lv.w;
        fill.style.background = lv.bg;
        label.textContent = lv.txt;
        label.style.color = lv.bg;
    }
    </script>
</body>

</html>