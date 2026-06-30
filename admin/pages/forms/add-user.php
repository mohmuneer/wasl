<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-user.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$can_add = 0;
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $can_add = (int)($accStmt->fetchColumn() ?: 0);
}

$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$roles    = $pdo->query("SELECT id, role_name, role_code FROM sys_roles ORDER BY role_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$flash = ['type'=>'', 'msg'=>''];

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_user'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $role_id   = (int)($_POST['role_id'] ?? 0);

    if (!$can_add) {
        $flash = ['type'=>'error','msg'=>'ليس لديك صلاحية الإضافة'];
    } elseif (empty($full_name) || empty($email) || empty($password)) {
        $flash = ['type'=>'error','msg'=>'الرجاء تعبئة جميع الحقول المطلوبة'];
    } else {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM sys_users WHERE email=?");
        $exists->execute([$email]);
        if ($exists->fetchColumn() > 0) {
            $flash = ['type'=>'error','msg'=>'البريد الإلكتروني مسجل مسبقاً'];
        } else {
            $file_path = null;
            if (isset($_FILES['file_input']) && $_FILES['file_input']['error'] === UPLOAD_ERR_OK) {
                $uploadCheck = Security::validateUpload($_FILES['file_input'], 'image', 5);
                if (!$uploadCheck['ok']) {
                    $errors[] = $uploadCheck['error'];
                } else {
                    $upload_dir = __DIR__ . "/../../../uploads/";
                    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                    $filename = Security::safeFilename($_FILES['file_input']['name'], 'usr');
                    if (move_uploaded_file($_FILES['file_input']['tmp_name'], $upload_dir . $filename))
                        $file_path = $filename;
                }
            }
            try {
                $pdo->beginTransaction();
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO sys_users (full_name, email, password, file_path) VALUES (?,?,?,?)")
                    ->execute([$full_name, $email, $hashed, $file_path]);
                $uid = (int)$pdo->lastInsertId();
                if ($branch_id) $pdo->prepare("INSERT INTO user_branch_access (user_id, branch_id) VALUES (?,?)")->execute([$uid, $branch_id]);
                if ($role_id)   $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$uid, $role_id]);
                $pdo->commit();
                echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم بنجاح',text:'تم إضافة المستخدم بنجاح'}));window.location.href='../tables/show-users.php';</script>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $flash = ['type'=>'error','msg'=>'خطأ: '.$e->getMessage()];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إضافة مستخدم جديد</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;text-align:right;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ══ متغيرات محلية تُؤخذ من الثيم ══ */
:root{
    --au-primary: var(--crm-page-bar-from, #1a5276);
    --au-accent:  var(--crm-page-bar-to,   #2980b9);
    --au-light:   rgba(var(--crm-primary-rgb, 26,82,118), 0.08);
    --au-border:  #e2e8f0;
    --au-radius:  12px;
    --au-shadow:  0 4px 24px rgba(0,0,0,.07);
}

/* ══ بطاقة القسم ══ */
.au-section {
    background: #fff;
    border-radius: var(--au-radius);
    box-shadow: var(--au-shadow);
    margin-bottom: 20px;
    overflow: hidden;
    border: 1px solid #f0f2f7;
}
.au-section-head {
    background: linear-gradient(135deg, var(--au-primary), var(--au-accent));
    padding: 13px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.au-section-head .s-icon {
    width: 34px; height: 34px;
    background: rgba(255,255,255,.2);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 14px; flex-shrink: 0;
}
.au-section-head h6 {
    margin: 0; color: #fff; font-weight: 700; font-size: .9rem;
}
.au-section-head small { color: rgba(255,255,255,.75); font-size: .75rem; }
.au-section-body { padding: 22px; }

/* ══ حقول الإدخال ══ */
.au-group { margin-bottom: 18px; position: relative; }
.au-label {
    display: flex; align-items: center; gap: 6px;
    font-size: .83rem; font-weight: 700; color: #475569;
    margin-bottom: 7px;
}
.au-label i { color: var(--au-primary); font-size: .78rem; }
.au-label .req { color: #ef4444; font-size: .9rem; }
.au-input {
    width: 100%;
    border: 1.5px solid var(--au-border);
    border-radius: var(--au-radius);
    padding: 10px 14px;
    font-size: .88rem;
    color: #334155;
    background: #fff;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.au-input:focus {
    outline: none;
    border-color: var(--au-primary);
    box-shadow: 0 0 0 3px rgba(var(--crm-primary-rgb,26,82,118),.1);
    background: #fafcff;
}
.au-input.is-valid   { border-color: #22c55e; }
.au-input.is-invalid { border-color: #ef4444; }
.au-input-icon { position: relative; }
.au-input-icon .au-input { padding-right: 38px; }
.au-input-icon .field-icon {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .85rem; pointer-events: none;
}
.au-hint { font-size: .75rem; color: #94a3b8; margin-top: 5px; }
.au-error { font-size: .75rem; color: #ef4444; margin-top: 5px; display:none; }

/* ══ مؤشر قوة كلمة المرور ══ */
.strength-bar {
    height: 5px; border-radius: 10px;
    background: #e2e8f0; margin-top: 8px;
    overflow: hidden;
}
.strength-fill {
    height: 100%; border-radius: 10px;
    width: 0%; transition: width .3s, background .3s;
}
.strength-label { font-size: .74rem; color: #94a3b8; margin-top: 4px; }

/* ══ زر إظهار/إخفاء كلمة المرور ══ */
.pass-wrapper { position: relative; }
.pass-wrapper .au-input { padding-left: 40px; }
.pass-toggle-btn {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: #94a3b8; cursor: pointer;
    font-size: .9rem; padding: 0; transition: color .2s;
}
.pass-toggle-btn:hover { color: var(--au-primary); }

/* ══ زر توليد كلمة مرور ══ */
.btn-gen {
    background: linear-gradient(135deg, #7c3aed, #a855f7);
    color: #fff; border: none; border-radius: 8px;
    padding: 8px 14px; font-size: .78rem; font-weight: 600;
    cursor: pointer; transition: .2s; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 5px;
}
.btn-gen:hover { opacity: .9; transform: translateY(-1px); }

/* ══ منطقة رفع الصورة ══ */
.avatar-upload-zone {
    border: 2px dashed var(--au-border);
    border-radius: 50%;
    width: 110px; height: 110px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    cursor: pointer; transition: .2s;
    position: relative; overflow: hidden;
    margin: 0 auto 12px;
    background: #f8fafc;
}
.avatar-upload-zone:hover { border-color: var(--au-primary); background: var(--au-light); }
.avatar-upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%;
}
.avatar-upload-zone img {
    width: 100%; height: 100%; object-fit: cover; border-radius: 50%;
}
.avatar-upload-zone .az-icon { font-size: 1.6rem; color: #cbd5e1; }
.avatar-upload-zone .az-text { font-size: .65rem; color: #94a3b8; text-align: center; margin-top: 4px; }
.avatar-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.45);
    display: none; align-items: center; justify-content: center;
    border-radius: 50%; color: #fff; font-size: .75rem;
}
.avatar-upload-zone:hover .avatar-overlay { display: flex; }

/* ══ بطاقة المعاينة (العمود الأيسر) ══ */
.preview-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: var(--au-shadow);
    overflow: hidden;
    border: 1px solid #f0f2f7;
    position: sticky; top: 70px;
}
.preview-card-header {
    background: linear-gradient(135deg, var(--au-primary), var(--au-accent));
    padding: 24px 20px 50px;
    text-align: center;
    position: relative;
}
.preview-avatar-wrap {
    width: 90px; height: 90px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,.9);
    box-shadow: 0 4px 20px rgba(0,0,0,.2);
    overflow: hidden;
    margin: 0 auto;
    position: absolute;
    bottom: -45px; left: 50%; transform: translateX(-50%);
    background: #e2e8f0;
    display: flex; align-items: center; justify-content: center;
}
.preview-avatar-wrap img {
    width: 100%; height: 100%; object-fit: cover;
}
.preview-avatar-placeholder { font-size: 2.2rem; color: #94a3b8; }
.preview-card-body { padding: 54px 20px 20px; text-align: center; }
.preview-name {
    font-size: 1.05rem; font-weight: 800; color: #1e293b;
    margin-bottom: 4px; min-height: 1.5em;
}
.preview-email {
    font-size: .78rem; color: #64748b;
    margin-bottom: 14px; direction: ltr; min-height: 1.2em;
}
.preview-badges { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; margin-bottom: 14px; }
.preview-badge {
    padding: 4px 12px; border-radius: 20px; font-size: .72rem; font-weight: 600;
}
.preview-badge-role   { background: #dbeafe; color: #1d4ed8; }
.preview-badge-branch { background: #dcfce7; color: #166534; }
.preview-badge-none   { background: #f1f5f9; color: #94a3b8; }
.preview-divider { border: none; border-top: 1px solid #f1f5f9; margin: 14px 0; }
.preview-meta { font-size: .75rem; color: #94a3b8; text-align: center; }
.preview-meta i { color: var(--au-primary); margin-left: 4px; }

/* ══ أزرار الإجراء ══ */
.btn-submit-au {
    background: linear-gradient(135deg, var(--au-primary), var(--au-accent));
    color: #fff; border: none; border-radius: 10px;
    padding: 12px 32px; font-size: .9rem; font-weight: 700;
    cursor: pointer; transition: .2s;
    display: inline-flex; align-items: center; gap: 9px;
    box-shadow: 0 4px 16px rgba(var(--crm-primary-rgb,26,82,118),.35);
}
.btn-submit-au:hover { opacity: .92; transform: translateY(-2px); color: #fff; }
.btn-submit-au:disabled { background: #94a3b8; box-shadow: none; transform: none; cursor: not-allowed; }
.btn-back-au {
    background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0;
    border-radius: 10px; padding: 11px 22px; font-size: .88rem; font-weight: 600;
    display: inline-flex; align-items: center; gap: 7px;
    transition: .2s; text-decoration: none;
}
.btn-back-au:hover { background: #e2e8f0; color: #334155; text-decoration: none; }

/* ══ شريط الخطوات ══ */
.steps-bar {
    display: flex; gap: 0; margin-bottom: 24px;
    background: #fff; border-radius: 12px;
    box-shadow: var(--au-shadow); overflow: hidden;
    border: 1px solid #f0f2f7;
}
.step-item {
    flex: 1; padding: 12px 16px;
    display: flex; align-items: center; gap: 10px;
    border-left: 1px solid #f0f2f7; cursor: default;
}
.step-item:last-child { border-left: none; }
.step-num {
    width: 28px; height: 28px; border-radius: 50%;
    background: #e2e8f0; color: #94a3b8;
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; font-weight: 700; flex-shrink: 0;
    transition: .2s;
}
.step-item.active .step-num {
    background: linear-gradient(135deg, var(--au-primary), var(--au-accent));
    color: #fff;
}
.step-item.done .step-num { background: #22c55e; color: #fff; }
.step-label { font-size: .78rem; color: #94a3b8; font-weight: 600; }
.step-item.active .step-label { color: var(--au-primary); }
.step-item.done .step-label { color: #22c55e; }

/* ══ رسالة الخطأ ══ */
.flash-alert {
    border-radius: 10px; padding: 12px 16px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 10px;
    font-size: .88rem;
}
.flash-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.flash-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

/* ══ حقل الاختيار المُزيَّن ══ */
.au-select-wrap { position: relative; }
.au-select-wrap .au-input { appearance: none; -webkit-appearance: none; padding-right: 38px; }
.au-select-wrap .sel-icon {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .78rem; pointer-events: none;
}
.au-select-wrap .sel-arrow {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .75rem; pointer-events: none;
}

/* ══ tips ══ */
.tips-card {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1px solid #fde68a; border-radius: 12px;
    padding: 16px 18px; margin-top: 16px;
}
.tips-card .tips-title { font-size: .82rem; font-weight: 700; color: #92400e; margin-bottom: 8px; }
.tips-card ul { margin: 0; padding-right: 18px; }
.tips-card ul li { font-size: .78rem; color: #78350f; margin-bottom: 5px; line-height: 1.5; }
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

    <!-- ══ الترويسة ══ -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-user-plus ml-2"></i>إضافة مستخدم جديد</h4>
                    <small>إنشاء حساب وتعيين الصلاحيات والفرع</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item"><a href="../tables/show-users.php">المستخدمون</a></li>
                    <li class="breadcrumb-item active">إضافة مستخدم</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <?php if ($flash['msg']): ?>
        <div class="flash-alert <?= $flash['type']==='error' ? 'flash-error' : 'flash-success' ?>">
            <i class="fas fa-<?= $flash['type']==='error' ? 'times-circle' : 'check-circle' ?> fa-lg"></i>
            <span><?= htmlspecialchars($flash['msg']) ?></span>
        </div>
        <?php endif; ?>

        <!-- ══ شريط الخطوات ══ -->
        <div class="steps-bar">
            <div class="step-item active" id="step1-indicator">
                <div class="step-num">1</div>
                <div class="step-label">المعلومات الأساسية</div>
            </div>
            <div class="step-item" id="step2-indicator">
                <div class="step-num">2</div>
                <div class="step-label">الأمان والصلاحيات</div>
            </div>
            <div class="step-item" id="step3-indicator">
                <div class="step-num">3</div>
                <div class="step-label">مراجعة وإنشاء</div>
            </div>
        </div>

        <div class="row">

            <!-- ══ العمود الرئيسي ══ -->
            <div class="col-lg-8">
                <form method="POST" enctype="multipart/form-data" id="addUserForm" novalidate>

                    <!-- ── القسم 1: المعلومات الشخصية ── -->
                    <div class="au-section">
                        <div class="au-section-head">
                            <div class="s-icon"><i class="fas fa-id-card"></i></div>
                            <div>
                                <h6>المعلومات الشخصية</h6>
                                <small>الاسم والبريد الإلكتروني والصورة الشخصية</small>
                            </div>
                        </div>
                        <div class="au-section-body">
                            <div class="row">
                                <!-- الاسم الكامل -->
                                <div class="col-md-6">
                                    <div class="au-group">
                                        <label class="au-label">
                                            <i class="fas fa-user"></i>
                                            الاسم الكامل
                                            <span class="req">*</span>
                                        </label>
                                        <div class="au-input-icon">
                                            <span class="field-icon"><i class="fas fa-user-circle"></i></span>
                                            <input type="text" name="full_name" id="inp_name" class="au-input"
                                                placeholder="أدخل الاسم الكامل" required autocomplete="off"
                                                oninput="updatePreviewName(this.value)">
                                        </div>
                                        <span class="au-error" id="err_name"><i class="fas fa-exclamation-circle ml-1"></i>الاسم مطلوب</span>
                                    </div>
                                </div>

                                <!-- البريد الإلكتروني -->
                                <div class="col-md-6">
                                    <div class="au-group">
                                        <label class="au-label">
                                            <i class="fas fa-envelope"></i>
                                            البريد الإلكتروني
                                            <span class="req">*</span>
                                        </label>
                                        <div class="au-input-icon">
                                            <span class="field-icon"><i class="fas fa-at"></i></span>
                                            <input type="email" name="email" id="inp_email" class="au-input"
                                                placeholder="example@domain.com" required dir="ltr" autocomplete="off"
                                                oninput="updatePreviewEmail(this.value)">
                                        </div>
                                        <span class="au-error" id="err_email"><i class="fas fa-exclamation-circle ml-1"></i>بريد إلكتروني غير صالح</span>
                                    </div>
                                </div>
                            </div>

                            <!-- الصورة الشخصية -->
                            <div class="au-group mb-0">
                                <label class="au-label"><i class="fas fa-camera"></i>الصورة الشخصية <small class="text-muted font-weight-normal mr-1">(اختياري)</small></label>
                                <div class="d-flex align-items-center gap-3" style="gap:16px">
                                    <div class="avatar-upload-zone" id="avatarZone">
                                        <input type="file" name="file_input" id="avatarInput" accept="image/*" onchange="previewAvatar(this)">
                                        <div id="avatarDefault">
                                            <div class="az-icon"><i class="fas fa-user-circle"></i></div>
                                            <div class="az-text">اضغط<br>للرفع</div>
                                        </div>
                                        <img id="avatarPreviewInCircle" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%">
                                        <div class="avatar-overlay"><i class="fas fa-camera"></i></div>
                                    </div>
                                    <div>
                                        <p style="font-size:.82rem;color:#475569;margin-bottom:6px;font-weight:600">رفع صورة الملف الشخصي</p>
                                        <p style="font-size:.76rem;color:#94a3b8;margin-bottom:8px">PNG، JPG، JPEG — الحجم الأقصى 2 ميجابايت</p>
                                        <button type="button" onclick="document.getElementById('avatarInput').click()"
                                            class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem">
                                            <i class="fas fa-upload ml-1"></i>اختيار صورة
                                        </button>
                                        <button type="button" onclick="clearAvatar()" id="clearAvatarBtn"
                                            class="btn btn-sm btn-outline-danger ml-1" style="border-radius:8px;font-size:.78rem;display:none">
                                            <i class="fas fa-trash ml-1"></i>حذف
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── القسم 2: الأمان ── -->
                    <div class="au-section">
                        <div class="au-section-head">
                            <div class="s-icon"><i class="fas fa-lock"></i></div>
                            <div>
                                <h6>كلمة المرور والأمان</h6>
                                <small>تعيين كلمة مرور قوية للحساب</small>
                            </div>
                        </div>
                        <div class="au-section-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="au-group">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="au-label mb-0">
                                                <i class="fas fa-key"></i>كلمة المرور <span class="req">*</span>
                                            </label>
                                            <button type="button" class="btn-gen" onclick="generatePassword()">
                                                <i class="fas fa-magic"></i>توليد تلقائي
                                            </button>
                                        </div>
                                        <div class="pass-wrapper">
                                            <input type="password" name="password" id="passInput" class="au-input"
                                                placeholder="••••••••" required autocomplete="new-password"
                                                oninput="checkStrength(this.value)">
                                            <button type="button" class="pass-toggle-btn" onclick="togglePass('passInput','eyeIcon1')">
                                                <i class="fas fa-eye" id="eyeIcon1"></i>
                                            </button>
                                        </div>
                                        <!-- مؤشر القوة -->
                                        <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                                        <div class="strength-label" id="strengthLabel">أدخل كلمة المرور</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="au-group">
                                        <label class="au-label"><i class="fas fa-check-double"></i>تأكيد كلمة المرور <span class="req">*</span></label>
                                        <div class="pass-wrapper">
                                            <input type="password" id="passConfirm" class="au-input"
                                                placeholder="••••••••" oninput="checkConfirm()">
                                            <button type="button" class="pass-toggle-btn" onclick="togglePass('passConfirm','eyeIcon2')">
                                                <i class="fas fa-eye" id="eyeIcon2"></i>
                                            </button>
                                        </div>
                                        <span class="au-error" id="err_confirm">كلمتا المرور غير متطابقتين</span>
                                    </div>
                                </div>
                            </div>

                            <!-- نصائح كلمة المرور -->
                            <div style="background:#f8fafc;border-radius:10px;padding:12px 16px;border:1px solid #e2e8f0;">
                                <p style="font-size:.78rem;font-weight:700;color:#475569;margin-bottom:8px"><i class="fas fa-shield-alt ml-1" style="color:var(--au-primary)"></i>متطلبات كلمة المرور القوية:</p>
                                <div class="row" style="row-gap:4px">
                                    <div class="col-6"><span class="req-item" id="req_len"><i class="fas fa-circle ml-1" style="font-size:.5rem"></i>8 أحرف على الأقل</span></div>
                                    <div class="col-6"><span class="req-item" id="req_upper"><i class="fas fa-circle ml-1" style="font-size:.5rem"></i>حرف كبير (A-Z)</span></div>
                                    <div class="col-6"><span class="req-item" id="req_num"><i class="fas fa-circle ml-1" style="font-size:.5rem"></i>رقم واحد على الأقل</span></div>
                                    <div class="col-6"><span class="req-item" id="req_sym"><i class="fas fa-circle ml-1" style="font-size:.5rem"></i>رمز خاص (!@#$...)</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── القسم 3: الصلاحيات والفرع ── -->
                    <div class="au-section">
                        <div class="au-section-head">
                            <div class="s-icon"><i class="fas fa-user-tag"></i></div>
                            <div>
                                <h6>الدور الوظيفي والفرع</h6>
                                <small>تعيين الصلاحيات والموقع التنظيمي</small>
                            </div>
                        </div>
                        <div class="au-section-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="au-group">
                                        <label class="au-label"><i class="fas fa-user-shield"></i>الدور الوظيفي</label>
                                        <div class="au-select-wrap">
                                            <span class="sel-icon"><i class="fas fa-briefcase"></i></span>
                                            <select name="role_id" id="inp_role" class="au-input"
                                                onchange="updatePreviewRole(this.options[this.selectedIndex].text, this.value)">
                                                <option value="">— بدون دور محدد —</option>
                                                <?php foreach ($roles as $r): ?>
                                                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?> (<?= htmlspecialchars($r['role_code']) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="sel-arrow"><i class="fas fa-chevron-down"></i></span>
                                        </div>
                                        <span class="au-hint"><i class="fas fa-info-circle ml-1"></i>يمكن تعيين صلاحيات تفصيلية لاحقاً</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="au-group">
                                        <label class="au-label"><i class="fas fa-code-branch"></i>الفرع</label>
                                        <div class="au-select-wrap">
                                            <span class="sel-icon"><i class="fas fa-map-marker-alt"></i></span>
                                            <select name="branch_id" id="inp_branch" class="au-input"
                                                onchange="updatePreviewBranch(this.options[this.selectedIndex].text, this.value)">
                                                <option value="">— بدون فرع محدد —</option>
                                                <?php foreach ($branches as $b): ?>
                                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="sel-arrow"><i class="fas fa-chevron-down"></i></span>
                                        </div>
                                        <span class="au-hint"><i class="fas fa-info-circle ml-1"></i>يحدد الفرع الذي ينتمي إليه المستخدم</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── أزرار الإجراء ── -->
                    <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:12px">
                        <a href="../tables/show-users.php" class="btn-back-au">
                            <i class="fas fa-arrow-right"></i>العودة للقائمة
                        </a>
                        <?php if ($can_add): ?>
                        <button type="submit" name="add_user" id="submitBtn" class="btn-submit-au">
                            <i class="fas fa-user-plus"></i>إنشاء الحساب
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn-submit-au" disabled>
                            <i class="fas fa-lock"></i>لا تملك صلاحية الإضافة
                        </button>
                        <?php endif; ?>
                    </div>

                </form>
            </div>

            <!-- ══ عمود المعاينة ══ -->
            <div class="col-lg-4">
                <div class="preview-card">
                    <div class="preview-card-header">
                        <p style="color:rgba(255,255,255,.75);font-size:.78rem;margin:0 0 4px">معاينة الحساب</p>
                        <div class="preview-avatar-wrap" id="previewAvatarWrap">
                            <img id="previewAvatarImg" src="" alt="" style="display:none">
                            <div class="preview-avatar-placeholder" id="previewAvatarPlaceholder">
                                <i class="fas fa-user-circle"></i>
                            </div>
                        </div>
                    </div>
                    <div class="preview-card-body">
                        <div class="preview-name" id="previewName">الاسم الكامل</div>
                        <div class="preview-email" id="previewEmail">البريد الإلكتروني</div>
                        <div class="preview-badges" id="previewBadges">
                            <span class="preview-badge preview-badge-none">بدون دور</span>
                            <span class="preview-badge preview-badge-none">بدون فرع</span>
                        </div>
                        <hr class="preview-divider">
                        <div class="preview-meta">
                            <i class="fas fa-calendar-plus"></i>
                            تاريخ الإنشاء: <?= date('Y/m/d') ?>
                        </div>
                        <div class="preview-meta mt-1">
                            <i class="fas fa-circle" style="font-size:.5rem;color:#22c55e"></i>
                            الحالة: نشط
                        </div>
                    </div>
                </div>

                <!-- نصائح -->
                <div class="tips-card mt-4">
                    <div class="tips-title"><i class="fas fa-lightbulb ml-1"></i>ملاحظات مهمة</div>
                    <ul>
                        <li>البريد الإلكتروني يُستخدم لتسجيل الدخول ويجب أن يكون فريداً</li>
                        <li>بعد الإنشاء يمكن تعيين صلاحيات تفصيلية من <a href="../tables/assign-permissions.php" style="color:#92400e;text-decoration:underline">إدارة الصلاحيات</a></li>
                        <li>كلمة المرور مشفرة ولا تُعرض مجدداً بعد الحفظ</li>
                    </ul>
                </div>

                <!-- مؤشر اكتمال النموذج -->
                <div class="au-section mt-4">
                    <div class="au-section-head">
                        <div class="s-icon"><i class="fas fa-tasks"></i></div>
                        <h6>اكتمال النموذج</h6>
                    </div>
                    <div class="au-section-body pb-3">
                        <div id="completionItems"></div>
                        <div class="strength-bar mt-3"><div class="strength-fill" id="completionBar"></div></div>
                        <p class="strength-label text-center mt-2" id="completionLabel">0% مكتمل</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
    </section>
</div>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.req-item { font-size: .76rem; color: #94a3b8; display: flex; align-items: center; gap: 4px; }
.req-item.met { color: #22c55e; }
.completion-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: .78rem; color: #475569;
}
.completion-item:last-child { border-bottom: none; }
.completion-item .ci-status { font-size: .7rem; font-weight: 700; }
.ci-done { color: #22c55e; }
.ci-miss { color: #ef4444; }
</style>
<script>
/* ══ معاينة الصورة ══ */
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        // في الحلقة الرئيسية
        const circle = document.getElementById('avatarPreviewInCircle');
        const def    = document.getElementById('avatarDefault');
        circle.src   = e.target.result; circle.style.display = 'block';
        def.style.display = 'none';
        document.getElementById('clearAvatarBtn').style.display = '';
        // في المعاينة
        const pi = document.getElementById('previewAvatarImg');
        const pp = document.getElementById('previewAvatarPlaceholder');
        pi.src = e.target.result; pi.style.display = 'block';
        pp.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
    updateCompletion();
}

function clearAvatar() {
    document.getElementById('avatarInput').value = '';
    document.getElementById('avatarPreviewInCircle').style.display = 'none';
    document.getElementById('avatarDefault').style.display = '';
    document.getElementById('clearAvatarBtn').style.display = 'none';
    document.getElementById('previewAvatarImg').style.display = 'none';
    document.getElementById('previewAvatarPlaceholder').style.display = '';
}

/* ══ تحديث المعاينة ══ */
function updatePreviewName(v) {
    document.getElementById('previewName').textContent = v || 'الاسم الكامل';
    updateCompletion();
}
function updatePreviewEmail(v) {
    document.getElementById('previewEmail').textContent = v || 'البريد الإلكتروني';
    updateCompletion();
}
function updatePreviewRole(text, val) {
    updatePreviewBadges();
    updateCompletion();
}
function updatePreviewBranch(text, val) {
    updatePreviewBadges();
    updateCompletion();
}
function updatePreviewBadges() {
    const roleEl   = document.getElementById('inp_role');
    const branchEl = document.getElementById('inp_branch');
    const role   = roleEl   ? roleEl.options[roleEl.selectedIndex].text   : '';
    const branch = branchEl ? branchEl.options[branchEl.selectedIndex].text : '';
    const rVal = roleEl   ? roleEl.value   : '';
    const bVal = branchEl ? branchEl.value : '';
    const container = document.getElementById('previewBadges');
    let html = '';
    html += rVal
        ? `<span class="preview-badge preview-badge-role"><i class="fas fa-user-shield ml-1"></i>${role.split('(')[0].trim()}</span>`
        : `<span class="preview-badge preview-badge-none">بدون دور</span>`;
    html += bVal
        ? `<span class="preview-badge preview-badge-branch"><i class="fas fa-map-marker-alt ml-1"></i>${branch}</span>`
        : `<span class="preview-badge preview-badge-none">بدون فرع</span>`;
    container.innerHTML = html;
}

/* ══ مؤشر قوة كلمة المرور ══ */
function checkStrength(v) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    const checks = { len: v.length >= 8, upper: /[A-Z]/.test(v), num: /[0-9]/.test(v), sym: /[^A-Za-z0-9]/.test(v) };
    Object.keys(checks).forEach(k => {
        const el = document.getElementById('req_' + k);
        if (el) el.classList.toggle('met', checks[k]);
        if (checks[k]) score++;
    });
    const levels = [
        {pct:0,  bg:'#e2e8f0', txt:'أدخل كلمة المرور'},
        {pct:25, bg:'#ef4444', txt:'ضعيفة جداً'},
        {pct:50, bg:'#f59e0b', txt:'مقبولة — يُنصح بتعزيزها'},
        {pct:75, bg:'#3b82f6', txt:'جيدة'},
        {pct:100,bg:'#22c55e', txt:'قوية جداً ✓'},
    ];
    const l = v.length === 0 ? levels[0] : levels[score];
    fill.style.width = l.pct + '%'; fill.style.background = l.bg;
    label.textContent = l.txt; label.style.color = l.bg;
    updateCompletion();
    checkConfirm();
}

/* ══ تطابق كلمة المرور ══ */
function checkConfirm() {
    const p = document.getElementById('passInput').value;
    const c = document.getElementById('passConfirm').value;
    const err = document.getElementById('err_confirm');
    const inp = document.getElementById('passConfirm');
    if (c.length === 0) { err.style.display='none'; inp.classList.remove('is-valid','is-invalid'); return; }
    if (p === c) { err.style.display='none'; inp.classList.add('is-valid'); inp.classList.remove('is-invalid'); }
    else         { err.style.display='block'; inp.classList.add('is-invalid'); inp.classList.remove('is-valid'); }
    updateCompletion();
}

/* ══ إظهار/إخفاء كلمة المرور ══ */
function togglePass(id, iconId) {
    const inp  = document.getElementById(id);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type='text';     icon.classList.replace('fa-eye','fa-eye-slash'); }
    else                         { inp.type='password'; icon.classList.replace('fa-eye-slash','fa-eye'); }
}

/* ══ توليد كلمة مرور تلقائية ══ */
function generatePassword() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
    let pw = '';
    for (let i = 0; i < 12; i++) pw += chars[Math.floor(Math.random() * chars.length)];
    const inp = document.getElementById('passInput');
    inp.type = 'text';
    inp.value = pw;
    document.getElementById('eyeIcon1').classList.replace('fa-eye','fa-eye-slash');
    checkStrength(pw);
    Swal.fire({
        title: 'كلمة المرور المولَّدة',
        html: `<div dir="ltr" style="font-size:1.2rem;font-weight:bold;letter-spacing:2px;padding:12px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">${pw}</div><p style="font-size:.82rem;color:#888;margin-top:8px">سجّل هذه الكلمة — لن تُعرض مجدداً بعد الحفظ</p>`,
        icon: 'info',
        confirmButtonText: 'تم التسجيل',
        confirmButtonColor: 'var(--au-primary, #1a5276)'
    });
}

/* ══ مؤشر اكتمال النموذج ══ */
function updateCompletion() {
    const fields = [
        { id: 'inp_name',    label: 'الاسم الكامل',        check: () => document.getElementById('inp_name')?.value.trim().length > 0 },
        { id: 'inp_email',   label: 'البريد الإلكتروني',   check: () => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(document.getElementById('inp_email')?.value||'') },
        { id: 'passInput',   label: 'كلمة المرور',          check: () => document.getElementById('passInput')?.value.length >= 8 },
        { id: 'passConfirm', label: 'تأكيد كلمة المرور',   check: () => document.getElementById('passInput')?.value === document.getElementById('passConfirm')?.value && document.getElementById('passConfirm')?.value.length > 0 },
        { id: 'inp_role',    label: 'الدور الوظيفي',        check: () => document.getElementById('inp_role')?.value !== '' },
        { id: 'inp_branch',  label: 'الفرع',                check: () => document.getElementById('inp_branch')?.value !== '' },
    ];
    let done = 0;
    let html = '';
    fields.forEach(f => {
        const met = f.check();
        if (met) done++;
        html += `<div class="completion-item"><span>${f.label}</span><span class="ci-status ${met?'ci-done':'ci-miss'">${met?'<i class="fas fa-check-circle"></i> مكتمل':'<i class="fas fa-times-circle"></i> مطلوب'}</span></div>`;
    });
    const pct = Math.round((done/fields.length)*100);
    document.getElementById('completionItems').innerHTML = html;
    const bar = document.getElementById('completionBar');
    const lbl = document.getElementById('completionLabel');
    bar.style.width = pct + '%';
    bar.style.background = pct < 40 ? '#ef4444' : pct < 70 ? '#f59e0b' : pct < 100 ? '#3b82f6' : '#22c55e';
    lbl.textContent = pct + '% مكتمل';
    lbl.style.color = bar.style.background;

    // تحديث شريط الخطوات
    const s1 = document.getElementById('inp_name')?.value.trim() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(document.getElementById('inp_email')?.value||'');
    const s2 = document.getElementById('passInput')?.value.length >= 8 && document.getElementById('passInput')?.value === document.getElementById('passConfirm')?.value;
    document.getElementById('step1-indicator').className = 'step-item' + (s1 ? ' done' : ' active');
    document.getElementById('step1-indicator').querySelector('.step-num').innerHTML = s1 ? '<i class="fas fa-check"></i>' : '1';
    document.getElementById('step2-indicator').className = 'step-item' + (s1 ? (s2 ? ' done' : ' active') : '');
    document.getElementById('step2-indicator').querySelector('.step-num').innerHTML = s2 ? '<i class="fas fa-check"></i>' : '2';
    document.getElementById('step3-indicator').className = 'step-item' + (pct === 100 ? ' done' : (s1 && s2 ? ' active' : ''));
    document.getElementById('step3-indicator').querySelector('.step-num').innerHTML = pct === 100 ? '<i class="fas fa-check"></i>' : '3';
}

/* ══ التحقق عند الإرسال ══ */
document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
    let ok = true;
    const name  = document.getElementById('inp_name');
    const email = document.getElementById('inp_email');
    const pass  = document.getElementById('passInput');
    const conf  = document.getElementById('passConfirm');

    if (!name.value.trim()) { document.getElementById('err_name').style.display='block'; name.classList.add('is-invalid'); ok=false; }
    else { document.getElementById('err_name').style.display='none'; name.classList.remove('is-invalid'); }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) { document.getElementById('err_email').style.display='block'; email.classList.add('is-invalid'); ok=false; }
    else { document.getElementById('err_email').style.display='none'; email.classList.remove('is-invalid'); }

    if (pass.value !== conf.value || conf.value.length === 0) { document.getElementById('err_confirm').style.display='block'; ok=false; }

    if (!ok) e.preventDefault();
});

// تهيئة
updateCompletion();
</script>
</body>
</html>
