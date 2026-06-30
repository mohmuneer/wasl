<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/system-settings.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$can_add = 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add FROM user_menu_access WHERE user_id = ? AND menu_id = ?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $can_add = $accStmt->fetchColumn() ?? 0;
}

$stmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$settings = $stmt->fetch();
if (!$settings) {
    $pdo->exec("INSERT INTO sys_settings (system_name) VALUES ('نظام CRM الذكي')");
    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch();
}

if (isset($_POST['update_settings'])) {
    if (!$can_add) {
        echo "<script>alert('ليس لديك صلاحية');window.location.href='system-settings.php';</script>";
        exit;
    }
    $logo_name = $settings['system_logo'];
    if (isset($_FILES['system_logo']) && $_FILES['system_logo']['error'] === UPLOAD_ERR_OK) {
        $uploadCheck = Security::validateUpload($_FILES['system_logo'], 'image', 2);
        if ($uploadCheck['ok']) {
            $newName = Security::safeFilename($_FILES['system_logo']['name'], 'logo');
            if (move_uploaded_file($_FILES['system_logo']['tmp_name'], '../../dist/img/' . $newName))
                $logo_name = $newName;
        }
    }
    $pdo->prepare("UPDATE sys_settings SET system_name=?,admin_email=?,contact_number=?,address=?,maintenance_mode=?,system_logo=? WHERE id=?")
        ->execute([$_POST['system_name'],$_POST['admin_email'],$_POST['contact_number'],$_POST['address'],$_POST['maintenance_mode'],$logo_name,$settings['id']]);
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الحفظ',text:'تم تحديث إعدادات النظام بنجاح'}));window.location.href='system-settings.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إعدادات النظام العامة</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
::-webkit-scrollbar { display:none; }
body { overflow-x:hidden; scrollbar-width:none; direction:rtl; }

/* ── بطاقة القسم ── */
.settings-section {
    background:#fff;
    border-radius:14px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    margin-bottom:22px;
    overflow:hidden;
}
.settings-section .sec-head {
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px 20px;
    border-bottom:1px solid #f0f0f0;
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276) 0%,var(--crm-page-bar-to,#2980b9) 100%);
    border-radius:14px 14px 0 0;
}
.settings-section .sec-head .sec-icon {
    width:36px;height:36px;
    background:rgba(255,255,255,.2);
    border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:15px;
    flex-shrink:0;
}
.settings-section .sec-head h5 {
    margin:0;color:#fff;font-weight:700;font-size:.95rem;
}
.settings-section .sec-body { padding:22px; }

/* ── حقول ── */
.form-control {
    border-radius:8px;
    border:1.5px solid #e0e4ea;
    padding:9px 14px;
    font-size:.9rem;
    transition:border-color .2s,box-shadow .2s;
}
.form-control:focus {
    border-color:var(--crm-primary,#1a5276);
    box-shadow:0 0 0 3px rgba(26,82,118,.1);
}
.field-label {
    font-weight:600;color:#444;font-size:.85rem;
    margin-bottom:6px;display:block;
}
.field-label i { color:var(--crm-primary,#1a5276);margin-left:5px; }

/* ── منطقة رفع الشعار ── */
.logo-upload-area {
    border:2px dashed #d0d7e0;
    border-radius:12px;
    padding:22px;
    text-align:center;
    cursor:pointer;
    transition:.2s;
    background:#fafbfc;
    position:relative;
}
.logo-upload-area:hover { border-color:var(--crm-primary,#1a5276);background:#f0f4f8; }
.logo-upload-area input[type=file] {
    position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;
}
.logo-upload-area .upload-icon { font-size:2rem;color:#b0b8c4;margin-bottom:8px; }
.logo-upload-area p { margin:0;color:#888;font-size:.85rem; }

/* ── حالة النظام badge ── */
.status-active  { background:#d4edda;color:#155724;border-radius:20px;padding:4px 14px;font-size:.82rem;font-weight:600; }
.status-maint   { background:#fff3cd;color:#856404;border-radius:20px;padding:4px 14px;font-size:.82rem;font-weight:600; }

/* ── زر الحفظ ── */
.btn-save {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    color:#fff;border:none;border-radius:10px;
    padding:10px 28px;font-weight:700;font-size:.9rem;
    transition:.2s;
}
.btn-save:hover { opacity:.9;transform:translateY(-1px);color:#fff; }
.btn-save i { margin-left:7px; }
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">
    <!-- ── الترويسة ── -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-cogs ml-2"></i> إعدادات النظام العامة</h4>
                    <small>الهوية والاتصال والتكوين</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">إعدادات النظام</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <form action="" method="POST" enctype="multipart/form-data">

                <!-- ── القسم 1: الهوية ── -->
                <div class="settings-section">
                    <div class="sec-head">
                        <div class="sec-icon"><i class="fas fa-building"></i></div>
                        <h5>هوية المؤسسة</h5>
                    </div>
                    <div class="sec-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="field-label"><i class="fas fa-signature"></i>اسم النظام / المؤسسة</label>
                                    <input type="text" name="system_name" class="form-control"
                                        placeholder="مثلاً: نظام إدارة المعامل بجامعة..."
                                        value="<?= htmlspecialchars($settings['system_name'] ?? 'نظام CRM الذكي') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="field-label"><i class="fas fa-map-marker-alt"></i>العنوان الفيزيائي</label>
                                    <input type="text" name="address" class="form-control"
                                        placeholder="المبنى الرئيسي - الطابق الثاني"
                                        value="<?= htmlspecialchars($settings['address'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ── منطقة الشعار ── -->
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="field-label"><i class="fas fa-image"></i>شعار النظام (Logo)</label>
                                    <div class="logo-upload-area" id="logoDropArea">
                                        <input type="file" name="system_logo" accept=".png,.jpg,.jpeg,.svg" id="logoInput">
                                        <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                        <p id="logoLabel">اضغط أو اسحب الملف هنا<br><small>PNG، JPG، SVG — يفضل خلفية شفافة</small></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <?php if (!empty($settings['system_logo'])): ?>
                                <div style="padding:12px;background:#f8f9fa;border-radius:10px;border:1px solid #e0e4ea;">
                                    <img src="../../dist/img/<?= htmlspecialchars($settings['system_logo']) ?>"
                                        alt="الشعار الحالي" style="max-height:80px;max-width:100%;object-fit:contain;">
                                    <p class="text-muted mt-2 mb-0" style="font-size:.75rem;">الشعار الحالي</p>
                                </div>
                                <?php else: ?>
                                <div style="padding:22px;background:#f8f9fa;border-radius:10px;border:1px dashed #d0d7e0;color:#aaa;text-align:center;">
                                    <i class="fas fa-image fa-2x mb-2"></i><br><small>لا يوجد شعار</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── القسم 2: التواصل ── -->
                <div class="settings-section">
                    <div class="sec-head">
                        <div class="sec-icon"><i class="fas fa-phone-alt"></i></div>
                        <h5>بيانات التواصل</h5>
                    </div>
                    <div class="sec-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="field-label"><i class="fas fa-envelope"></i>البريد الإلكتروني الرسمي</label>
                                    <input type="email" name="admin_email" class="form-control"
                                        placeholder="admin@example.com"
                                        value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="field-label"><i class="fas fa-mobile-alt"></i>رقم الهاتف الرسمي</label>
                                    <input type="text" name="contact_number" class="form-control"
                                        placeholder="00966XXXXXXX"
                                        value="<?= htmlspecialchars($settings['contact_number'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── القسم 3: حالة النظام ── -->
                <div class="settings-section">
                    <div class="sec-head">
                        <div class="sec-icon"><i class="fas fa-toggle-on"></i></div>
                        <h5>حالة النظام</h5>
                    </div>
                    <div class="sec-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <div class="form-group mb-0">
                                    <label class="field-label"><i class="fas fa-server"></i>وضع التشغيل</label>
                                    <select class="form-control" name="maintenance_mode">
                                        <option value="0" <?= (($settings['maintenance_mode'] ?? 0) == 0) ? 'selected' : '' ?>>✅ يعمل بشكل طبيعي (نشط)</option>
                                        <option value="1" <?= (($settings['maintenance_mode'] ?? 0) == 1) ? 'selected' : '' ?>>🔧 وضع الصيانة (مغلق للمستخدمين)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end pb-1">
                                <div class="p-3 rounded" style="background:#f8f9fa;border:1px solid #e0e4ea;width:100%;">
                                    <small class="text-muted d-block mb-1">الحالة الحالية:</small>
                                    <?php if (($settings['maintenance_mode'] ?? 0) == 0): ?>
                                    <span class="status-active"><i class="fas fa-check-circle ml-1"></i>النظام يعمل</span>
                                    <?php else: ?>
                                    <span class="status-maint"><i class="fas fa-tools ml-1"></i>وضع الصيانة</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── أزرار الحفظ ── -->
                <div class="d-flex justify-content-end gap-2" style="gap:10px;">
                    <a href="../../index.php" class="btn btn-outline-secondary" style="border-radius:10px;padding:10px 22px;">
                        <i class="fas fa-times ml-1"></i>إلغاء
                    </a>
                    <?php if ($can_add): ?>
                    <button type="submit" name="update_settings" class="btn-save">
                        <i class="fas fa-save"></i>حفظ التغييرات
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" disabled style="border-radius:10px;padding:10px 22px;">
                        <i class="fas fa-lock ml-1"></i>غير مسموح
                    </button>
                    <?php endif; ?>
                </div>

            </form>
        </div>
    </section>
</div>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.getElementById('logoInput').addEventListener('change', function() {
    const name = this.files[0]?.name || 'اختر ملفاً';
    document.getElementById('logoLabel').innerHTML = '<strong>' + name + '</strong><br><small>اضغط حفظ لتطبيق الشعار الجديد</small>';
});
</script>
</body>
</html>
