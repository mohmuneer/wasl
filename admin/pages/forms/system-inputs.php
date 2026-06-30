<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/system-inputs.php";
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

$stmt = $pdo->query("SELECT * FROM sys_theme LIMIT 1");
$visuals = $stmt->fetch();

if (isset($_POST['def_visuals'])) {
    $pdo->prepare("UPDATE sys_theme SET system_font='Cairo',sidebar_color='#343a40',header_color='#ffffff',main_color='#007bff',primary_color='#1a5276',accent_color='#2980b9' WHERE id=?")
        ->execute([$visuals['id']]);
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'info',title:'تمت الاستعادة',text:'تم العودة إلى الإعدادات الأصلية'}));window.location.href='system-inputs.php';</script>";
    exit;
}
if (isset($_POST['update_visuals'])) {
    // دالة بسيطة للتحقق من صحة الكود اللوني
    $validHex = function($v, $def) {
        $v = trim($v ?? '');
        // إضافة # إذا كانت مفقودة
        if ($v && $v[0] !== '#') $v = '#' . $v;
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $def;
    };

    $font        = $_POST['system_font']   ?? 'Cairo';
    $sidebarClr  = $validHex($_POST['sidebar_color'], '#2c3e50');
    $headerClr   = $validHex($_POST['header_color'],  '#ffffff');
    $mainClr     = $validHex($_POST['main_color'],     '#007bff');

    try {
        // حفظ main_color وأيضاً primary_color وaccent_color
        // لأن main-header.php يقرأ primary_color لألوان الترويسات
        $existingCols = array_column(
            $pdo->query("DESCRIBE sys_theme")->fetchAll(PDO::FETCH_ASSOC), 'Field'
        );

        $setParts = ["system_font=?","sidebar_color=?","header_color=?","main_color=?"];
        $params   = [$font, $sidebarClr, $headerClr, $mainClr];

        // إذا كان عمود primary_color موجوداً → حدّثه أيضاً لأنه يتحكم في ترويسات الصفحات
        if (in_array('primary_color', $existingCols)) {
            $setParts[] = "primary_color=?";
            $params[]   = $mainClr;
        }
        if (in_array('accent_color', $existingCols)) {
            // لون التمييز = نسخة أفتح من اللون الرئيسي
            $setParts[] = "accent_color=?";
            $params[]   = $mainClr; // يمكن استبداله بحساب لون أفتح لاحقاً
        }

        $params[] = $visuals['id'];
        $pdo->prepare("UPDATE sys_theme SET " . implode(',', $setParts) . " WHERE id=?")
            ->execute($params);

        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الحفظ',text:'تم تحديث إعدادات المظهر بنجاح'}));window.location.href='system-inputs.php';</script>";
        exit;
    } catch (Exception $e) {
        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'error',title:'خطأ في الحفظ',text:" . json_encode($e->getMessage()) . "}));window.location.href='system-inputs.php';</script>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إعدادات المظهر</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
::-webkit-scrollbar { display:none; }
body { overflow-x:hidden; scrollbar-width:none; direction:rtl; }

.appearance-section {
    background:#fff;
    border-radius:14px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    margin-bottom:22px;
    overflow:hidden;
}
.appearance-section .sec-head {
    display:flex;align-items:center;gap:12px;
    padding:14px 20px;
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    border-radius:14px 14px 0 0;
}
.appearance-section .sec-head .sec-icon {
    width:36px;height:36px;
    background:rgba(255,255,255,.2);border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:15px;flex-shrink:0;
}
.appearance-section .sec-head h5 { margin:0;color:#fff;font-weight:700;font-size:.95rem; }
.appearance-section .sec-body { padding:24px; }

/* ── color picker card ── */
.color-item {
    background:#f8f9fa;
    border:1.5px solid #e8ecf0;
    border-radius:12px;
    padding:16px;
    display:flex;flex-direction:column;align-items:center;
    gap:10px;
    transition:.2s;
    cursor:pointer;
}
.color-item:hover { border-color:var(--crm-primary,#1a5276);box-shadow:0 4px 12px rgba(0,0,0,.08); }
.color-item label { font-size:.8rem;font-weight:600;color:#555;margin:0;text-align:center;cursor:pointer; }
.color-item label i { display:block;font-size:1.3rem;margin-bottom:4px;color:var(--crm-primary,#1a5276); }
.color-swatch {
    width:56px;height:56px;
    border-radius:50%;
    border:3px solid #fff;
    box-shadow:0 2px 10px rgba(0,0,0,.2);
    cursor:pointer;
    transition:.2s;
    position:relative;
    overflow:hidden;
}
.color-swatch input[type=color] {
    position:absolute;inset:0;
    width:100%;height:100%;
    opacity:0;cursor:pointer;border:none;
}

/* ── font preview ── */
.font-card {
    border:2px solid #e8ecf0;
    border-radius:12px;
    padding:16px 20px;
    cursor:pointer;
    transition:.2s;
    text-align:center;
}
.font-card:hover { border-color:var(--crm-primary,#1a5276); }
.font-card input[type=radio] { display:none; }
.font-card.selected { border-color:var(--crm-primary,#1a5276);background:#f0f6ff; }
.font-card .font-name { font-size:1.1rem;font-weight:700;color:#333; }
.font-card .font-sample { font-size:.8rem;color:#888;margin-top:4px; }

/* ── live preview ── */
.preview-box {
    border-radius:14px;
    overflow:hidden;
    border:1px solid #e0e4ea;
    box-shadow:0 4px 20px rgba(0,0,0,.08);
}
.preview-header {
    height:46px;
    display:flex;align-items:center;padding:0 14px;gap:8px;
}
.preview-sidebar {
    width:80px;
    min-height:120px;
    float:right;
    display:flex;flex-direction:column;align-items:center;
    padding:10px 0;gap:6px;
}
.preview-sidebar span {
    width:50px;height:6px;border-radius:3px;background:rgba(255,255,255,.3);
}
.preview-content {
    overflow:hidden;
    background:#f4f6f9;
    min-height:120px;
    padding:10px;
}
.preview-bar {
    height:28px;border-radius:6px;margin-bottom:8px;
}
.preview-cards { display:flex;gap:6px; }
.preview-card { flex:1;height:50px;background:#fff;border-radius:8px;border:1px solid #e0e4ea; }

/* ── btns ── */
.btn-save-app {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    color:#fff;border:none;border-radius:10px;
    padding:10px 28px;font-weight:700;
    transition:.2s;
}
.btn-save-app:hover { opacity:.9;transform:translateY(-1px);color:#fff; }
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-palette ml-2"></i> إعدادات المظهر والواجهة</h4>
                    <small>الخطوط والألوان والهوية البصرية</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">المظهر</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <form action="" method="POST" id="appearanceForm">

                <div class="row">
                    <!-- ══ العمود الرئيسي ══ -->
                    <div class="col-lg-8">

                        <!-- الألوان -->
                        <div class="appearance-section">
                            <div class="sec-head">
                                <div class="sec-icon"><i class="fas fa-fill-drip"></i></div>
                                <h5>ألوان النظام</h5>
                            </div>
                            <div class="sec-body">
                                <div class="row">
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="color-item">
                                            <label for="sidebar_color_inp">
                                                <i class="fas fa-columns"></i>القائمة الجانبية
                                            </label>
                                            <div class="color-swatch" id="swatch_sidebar" style="background:<?= htmlspecialchars($visuals['sidebar_color'] ?? '#343a40') ?>">
                                                <input type="color" name="sidebar_color" id="sidebar_color_inp"
                                                    value="<?= htmlspecialchars($visuals['sidebar_color'] ?? '#343a40') ?>"
                                                    onchange="updateSwatch('swatch_sidebar',this.value)">
                                            </div>
                                            <small id="sidebar_color_val" style="font-size:.7rem;color:#888"><?= htmlspecialchars($visuals['sidebar_color'] ?? '#343a40') ?></small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="color-item">
                                            <label for="header_color_inp">
                                                <i class="fas fa-bars"></i>شريط التنقل العلوي
                                                <span style="font-size:.6rem;color:#94a3b8;display:block;font-weight:400">(الشريط الأعلى مع الأيقونات)</span>
                                            </label>
                                            <div class="color-swatch" id="swatch_header" style="background:<?= htmlspecialchars($visuals['header_color'] ?? '#ffffff') ?>;border:2px solid #ddd">
                                                <input type="color" name="header_color" id="header_color_inp"
                                                    value="<?= htmlspecialchars($visuals['header_color'] ?? '#ffffff') ?>"
                                                    onchange="updateSwatch('swatch_header',this.value)">
                                            </div>
                                            <small id="header_color_val" style="font-size:.7rem;color:#888"><?= htmlspecialchars($visuals['header_color'] ?? '#ffffff') ?></small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="color-item">
                                            <label for="main_color_inp">
                                                <i class="fas fa-paint-brush"></i>اللون الرئيسي
                                                <span style="font-size:.6rem;color:#94a3b8;display:block;font-weight:400">(ترويسات الصفحات والأزرار)</span>
                                            </label>
                                            <div class="color-swatch" id="swatch_main" style="background:<?= htmlspecialchars($visuals['main_color'] ?? '#007bff') ?>">
                                                <input type="color" name="main_color" id="main_color_inp"
                                                    value="<?= htmlspecialchars($visuals['main_color'] ?? '#007bff') ?>"
                                                    onchange="updateSwatch('swatch_main',this.value)">
                                            </div>
                                            <small id="main_color_val" style="font-size:.7rem;color:#888"><?= htmlspecialchars($visuals['main_color'] ?? '#007bff') ?></small>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="color-item" style="background:#fff8f0;">
                                            <label><i class="fas fa-undo"></i>استعادة الافتراضي</label>
                                            <button type="submit" name="def_visuals"
                                                class="btn btn-sm btn-outline-warning" style="border-radius:8px;margin-top:4px;"
                                                onclick="return confirm('هل تريد إعادة ضبط الألوان للافتراضي؟')">
                                                <i class="fas fa-sync-alt ml-1"></i>إعادة ضبط
                                            </button>
                                            <small style="font-size:.7rem;color:#aaa">#343a40 · #fff · #007bff</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- الخط -->
                        <div class="appearance-section">
                            <div class="sec-head">
                                <div class="sec-icon"><i class="fas fa-font"></i></div>
                                <h5>خط النظام</h5>
                            </div>
                            <div class="sec-body">
                                <div class="row">
                                    <?php foreach (['Cairo'=>'القاهرة — Cairo','Tajawal'=>'تجوال — Tajawal','Almarai'=>'المراعي — Almarai'] as $val=>$label): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="w-100" style="cursor:pointer">
                                            <input type="radio" name="system_font" value="<?= $val ?>"
                                                <?= ($visuals['system_font'] ?? 'Cairo') === $val ? 'checked' : '' ?>
                                                onchange="updateFontCards(this)">
                                            <div class="font-card <?= ($visuals['system_font'] ?? 'Cairo') === $val ? 'selected' : '' ?>" style="font-family:'<?= $val ?>',sans-serif">
                                                <div class="font-name"><?= $val ?></div>
                                                <div class="font-sample">أبجد هوز حطي كلمن سعفص</div>
                                                <small class="text-muted"><?= $label ?></small>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- ══ معاينة حية ══ -->
                    <div class="col-lg-4">
                        <div class="appearance-section" style="position:sticky;top:70px;">
                            <div class="sec-head">
                                <div class="sec-icon"><i class="fas fa-eye"></i></div>
                                <h5>معاينة فورية</h5>
                            </div>
                            <div class="sec-body" style="padding:16px;">
                                <div class="preview-box">
                                    <!-- رأس -->
                                    <div class="preview-header" id="prev_header"
                                        style="background:<?= htmlspecialchars($visuals['header_color'] ?? '#fff') ?>;border-bottom:1px solid #eee">
                                        <div style="width:18px;height:18px;border-radius:4px;background:#e0e4ea;"></div>
                                        <div style="flex:1;height:8px;background:#e8ecf0;border-radius:4px;max-width:80px;"></div>
                                        <div style="width:22px;height:22px;border-radius:50%;background:#ddd;margin-right:auto;"></div>
                                    </div>
                                    <div style="display:flex;">
                                        <!-- شريط جانبي -->
                                        <div class="preview-sidebar" id="prev_sidebar"
                                            style="background:<?= htmlspecialchars($visuals['sidebar_color'] ?? '#343a40') ?>">
                                            <span></span><span></span><span></span>
                                        </div>
                                        <!-- محتوى -->
                                        <div class="preview-content" style="flex:1;">
                                            <div class="preview-bar" id="prev_bar"
                                                style="background:<?= htmlspecialchars($visuals['main_color'] ?? '#007bff') ?>;opacity:.8"></div>
                                            <div class="preview-cards">
                                                <div class="preview-card"></div>
                                                <div class="preview-card"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-muted text-center mt-3 mb-0" style="font-size:.78rem;">تعكس المعاينة الألوان المختارة فقط</p>
                            </div>
                        </div>

                        <!-- زر الحفظ -->
                        <div class="mt-3 d-flex flex-column" style="gap:10px;">
                            <?php if ($can_add): ?>
                            <button type="submit" name="update_visuals" class="btn-save-app btn-block">
                                <i class="fas fa-save ml-1"></i>حفظ إعدادات المظهر
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-block" disabled style="border-radius:10px;">
                                <i class="fas fa-lock ml-1"></i>غير مسموح
                            </button>
                            <?php endif; ?>
                            <a href="theme-settings.php" class="btn btn-outline-secondary btn-block" style="border-radius:10px;">
                                <i class="fas fa-sliders-h ml-1"></i>إعدادات المظهر المتقدمة
                            </a>
                        </div>
                    </div>
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
function updateSwatch(id, color) {
    document.getElementById(id).style.background = color;
    const valEl = document.getElementById(id.replace('swatch_','') + '_color_val');
    if (valEl) valEl.textContent = color;
    // تحديث المعاينة
    if (id === 'swatch_sidebar')  document.getElementById('prev_sidebar').style.background = color;
    if (id === 'swatch_header')   document.getElementById('prev_header').style.background  = color;
    if (id === 'swatch_main')     document.getElementById('prev_bar').style.background     = color;
}
function updateFontCards(radio) {
    document.querySelectorAll('.font-card').forEach(c => c.classList.remove('selected'));
    radio.closest('label').querySelector('.font-card').classList.add('selected');
}
</script>
</body>
</html>
