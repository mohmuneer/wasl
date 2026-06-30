<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) { header("Location: ../../index.php"); exit; }

/* ── ترقية قاعدة البيانات (إضافة أعمدة الثيم الجديدة إن لم تكن موجودة) ── */
try {
    $existingCols = array_column(
        $pdo->query("DESCRIBE sys_theme")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    $newColumns = [
        'primary_color'    => "VARCHAR(25) DEFAULT '#1a5276'",
        'accent_color'     => "VARCHAR(25) DEFAULT '#2980b9'",
        'btn_radius'       => "VARCHAR(15) DEFAULT '8px'",
        'btn_style'        => "VARCHAR(15) DEFAULT 'gradient'",
        'table_style'      => "VARCHAR(20) DEFAULT 'default'",
        'card_radius'      => "VARCHAR(15) DEFAULT '14px'",
        'theme_preset'     => "VARCHAR(50) DEFAULT 'classic-blue'",
        'dark_mode'        => "TINYINT(1) DEFAULT 0",
        /* شريط عنوان الصفحة */
        'page_bar_style'   => "VARCHAR(20) DEFAULT 'gradient'",
        'page_bar_radius'  => "VARCHAR(10) DEFAULT '14px'",
        'topbar_visible'   => "TINYINT(1) DEFAULT 1",
        'topbar_shadow'    => "TINYINT(1) DEFAULT 1",
        /* أزرار التصدير */
        'btn_print_color'  => "VARCHAR(25) DEFAULT '#5a6268'",
        'btn_pdf_color'    => "VARCHAR(25) DEFAULT '#c82333'",
        'btn_excel_color'  => "VARCHAR(25) DEFAULT '#1e7e34'",
        'btn_colvis_color' => "VARCHAR(25) DEFAULT '#0062cc'",
        'btn_add_color'    => "VARCHAR(25) DEFAULT '#1e7e34'",
        /* الشريط السفلي */
        'footer_bg'        => "VARCHAR(25) DEFAULT '#1e272e'",
        'footer_text'      => "VARCHAR(25) DEFAULT '#adb5bd'",
    ];
    foreach ($newColumns as $col => $def) {
        if (!in_array($col, $existingCols)) {
            $pdo->exec("ALTER TABLE sys_theme ADD COLUMN `$col` $def");
        }
    }
} catch (Exception $e) { /* تجاهل أخطاء الترقية */ }

/* ── صلاحيات ── */
$page_path = "pages/forms/theme-settings.php";
$menuStmt  = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;
$can_add = 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add FROM user_menu_access WHERE user_id = ? AND menu_id = ?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $can_add = $accStmt->fetchColumn() ?? 0;
}

/* ── قراءة الثيم الحالي ── */
$theme = $pdo->query("SELECT * FROM sys_theme LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$theme) {
    $pdo->exec("INSERT INTO sys_theme (system_font, header_color, sidebar_color) VALUES ('Cairo','#ffffff','#2c3e50')");
    $theme = $pdo->query("SELECT * FROM sys_theme LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

/* ── قراءة معلومات النظام ── */
$sysInfo = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

/* ── حفظ معلومات النظام ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sysinfo'])) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$can_add) { echo json_encode(['success'=>false,'message'=>'لا تملك صلاحية التعديل']); exit; }
    $sn  = trim($_POST['system_name']    ?? '');
    $sne = trim($_POST['system_name_en'] ?? '');
    $st  = trim($_POST['site_tagline']   ?? '');
    $ft  = trim($_POST['footer_tagline'] ?? '');
    $ae  = trim($_POST['admin_email']    ?? '');
    $cn  = trim($_POST['contact_number'] ?? '');
    try {
        $pdo->prepare("UPDATE sys_settings SET system_name=?, system_name_en=?, site_tagline=?, footer_tagline=?, admin_email=?, contact_number=? WHERE id=?")
            ->execute([$sn, $sne, $st, $ft, $ae, $cn, $sysInfo['id'] ?? 1]);
        echo json_encode(['success'=>true,'message'=>'تم حفظ معلومات النظام بنجاح']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'خطأ: '.$e->getMessage()]);
    }
    exit;
}

/* ── ثيمات جاهزة ── */
$presets = [
    'classic-blue'   => ['label' => 'كلاسيكي أزرق',    'primary' => '#1a5276', 'accent' => '#2980b9', 'sidebar' => '#2c3e50', 'header' => '#ffffff'],
    'saudi-green'    => ['label' => 'الهوية السعودية', 'primary' => '#1a5c38', 'accent' => '#27ae60', 'sidebar' => '#145a32', 'header' => '#ffffff'],
    'royal-purple'   => ['label' => 'ملكي بنفسجي',     'primary' => '#4a235a', 'accent' => '#8e44ad', 'sidebar' => '#3b1449', 'header' => '#ffffff'],
    'golden-desert'  => ['label' => 'ذهبي فاخر',       'primary' => '#7d6608', 'accent' => '#d4ac0d', 'sidebar' => '#2d2416', 'header' => '#fffef5'],
    'ocean-teal'     => ['label' => 'فيروزي محيطي',    'primary' => '#0b6278', 'accent' => '#17a2b8', 'sidebar' => '#084c5e', 'header' => '#ffffff'],
    'crimson-red'    => ['label' => 'أحمر احترافي',    'primary' => '#78281f', 'accent' => '#c0392b', 'sidebar' => '#641e16', 'header' => '#ffffff'],
    'dark-midnight'  => ['label' => 'داكن عصري',       'primary' => '#3498db', 'accent' => '#5dade2', 'sidebar' => '#0d1117', 'header' => '#161b22'],
    'emerald-pro'    => ['label' => 'زمردي محترف',     'primary' => '#0b5345', 'accent' => '#17a589', 'sidebar' => '#083d33', 'header' => '#ffffff'],
];

/* ── حفظ الثيم ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_theme'])) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$can_add) { echo json_encode(['success'=>false,'message'=>'لا تملك صلاحية التعديل']); exit; }

    $cHex = fn($v,$d) => preg_match('/^#[0-9a-fA-F]{3,8}$/', $v ?? '') ? $v : $d;
    $cIn  = fn($v,$opts,$d) => in_array($v ?? '', $opts) ? $v : $d;

    $preset          = $_POST['theme_preset']   ?? 'classic-blue';
    $primary         = $cHex($_POST['primary_color']   ?? '', '#1a5276');
    $accent          = $cHex($_POST['accent_color']    ?? '', '#2980b9');
    $sidebar         = $cHex($_POST['sidebar_color']   ?? '', '#2c3e50');
    $header_bg       = $cHex($_POST['header_color']    ?? '', '#ffffff');
    $font            = $cIn($_POST['system_font']      ?? '', ['Cairo','Almarai','Tajawal'], 'Cairo');
    $btn_radius      = $cIn($_POST['btn_radius']       ?? '', ['4px','8px','12px','50px','0px'], '8px');
    $btn_style       = $cIn($_POST['btn_style']        ?? '', ['gradient','solid','outline','pill','square'], 'gradient');
    $table_style     = $cIn($_POST['table_style']      ?? '', ['default','striped','bordered','minimal','compact'], 'default');
    $card_radius     = $cIn($_POST['card_radius']      ?? '', ['4px','8px','12px','14px','20px','0px'], '14px');
    $dark_mode       = (($_POST['dark_mode'] ?? '0') === '1') ? 1 : 0;
    /* شريط عنوان الصفحة */
    $page_bar_style  = $cIn($_POST['page_bar_style']   ?? '', ['gradient','solid','flat','glass','dark','transparent'], 'gradient');
    $page_bar_radius = $cIn($_POST['page_bar_radius']  ?? '', ['0px','6px','10px','14px','20px'], '14px');
    /* أزرار التصدير */
    $btn_print_color  = $cHex($_POST['btn_print_color']  ?? '', '#5a6268');
    $btn_pdf_color    = $cHex($_POST['btn_pdf_color']    ?? '', '#c82333');
    $btn_excel_color  = $cHex($_POST['btn_excel_color']  ?? '', '#1e7e34');
    $btn_colvis_color = $cHex($_POST['btn_colvis_color'] ?? '', '#0062cc');
    $btn_add_color    = $cHex($_POST['btn_add_color']    ?? '', '#1e7e34');
    /* الشريط السفلي */
    $footer_bg        = $cHex($_POST['footer_bg']    ?? '', '#1e272e');
    $footer_text      = $cHex($_POST['footer_text']  ?? '', '#adb5bd');
    /* الشريط العلوي */
    $topbar_visible   = isset($_POST['topbar_visible'])  && $_POST['topbar_visible']  == '1' ? 1 : 0;
    $topbar_shadow    = isset($_POST['topbar_shadow'])   && $_POST['topbar_shadow']   == '1' ? 1 : 0;
    /* أزرار الإجراءات */
    $btn_view_color    = $cHex($_POST['btn_view_color']    ?? '', '#17a2b8');
    $btn_edit_color    = $cHex($_POST['btn_edit_color']    ?? '', '#e0a800');
    $btn_delete_color  = $cHex($_POST['btn_delete_color']  ?? '', '#dc3545');
    $btn_archive_color = $cHex($_POST['btn_archive_color'] ?? '', '#6c757d');

    try {
        $pdo->prepare("UPDATE sys_theme SET
            primary_color=?, accent_color=?, sidebar_color=?, header_color=?,
            system_font=?, btn_radius=?, btn_style=?, table_style=?,
            card_radius=?, theme_preset=?, dark_mode=?,
            page_bar_style=?, page_bar_radius=?,
            btn_print_color=?, btn_pdf_color=?, btn_excel_color=?, btn_colvis_color=?, btn_add_color=?,
            footer_bg=?, footer_text=?,
            topbar_visible=?, topbar_shadow=?,
            btn_view_color=?, btn_edit_color=?, btn_delete_color=?, btn_archive_color=?
            WHERE id=?")
            ->execute([
                $primary,$accent,$sidebar,$header_bg,$font,$btn_radius,$btn_style,$table_style,
                $card_radius,$preset,$dark_mode,
                $page_bar_style,$page_bar_radius,
                $btn_print_color,$btn_pdf_color,$btn_excel_color,$btn_colvis_color,$btn_add_color,
                $footer_bg,$footer_text,
                $topbar_visible,$topbar_shadow,
                $btn_view_color,$btn_edit_color,$btn_delete_color,$btn_archive_color,
                $theme['id']
            ]);
        echo json_encode(['success'=>true,'message'=>'تم حفظ الثيم بنجاح']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'خطأ: '.$e->getMessage()]);
    }
    exit;
}

/* ── قيم حالية للعرض ── */
$cur = [
    'primary'          => $theme['primary_color']   ?? '#1a5276',
    'accent'           => $theme['accent_color']    ?? '#2980b9',
    'sidebar'          => $theme['sidebar_color']   ?? '#2c3e50',
    'header'           => $theme['header_color']    ?? '#ffffff',
    'font'             => $theme['system_font']     ?? 'Cairo',
    'btn_radius'       => $theme['btn_radius']      ?? '8px',
    'btn_style'        => $theme['btn_style']       ?? 'gradient',
    'table_style'      => $theme['table_style']     ?? 'default',
    'card_radius'      => $theme['card_radius']     ?? '14px',
    'preset'           => $theme['theme_preset']    ?? 'classic-blue',
    'dark_mode'        => $theme['dark_mode']       ?? 0,
    /* شريط عنوان الصفحة */
    'page_bar_style'   => $theme['page_bar_style']  ?? 'gradient',
    'page_bar_radius'  => $theme['page_bar_radius'] ?? '14px',
    /* أزرار التصدير */
    'btn_print'        => $theme['btn_print_color']  ?? '#5a6268',
    'btn_pdf'          => $theme['btn_pdf_color']    ?? '#c82333',
    'btn_excel'        => $theme['btn_excel_color']  ?? '#1e7e34',
    'btn_colvis'       => $theme['btn_colvis_color'] ?? '#0062cc',
    'btn_add'          => $theme['btn_add_color']    ?? '#1e7e34',
    /* الشريط السفلي */
    'footer_bg'        => $theme['footer_bg']        ?? '#1e272e',
    'footer_text'      => $theme['footer_text']      ?? '#adb5bd',
    /* الشريط العلوي */
    'topbar_visible'   => $theme['topbar_visible']   ?? 1,
    'topbar_shadow'    => $theme['topbar_shadow']    ?? 1,
    /* أزرار الإجراءات */
    'btn_view'         => $theme['btn_view_color']    ?? '#17a2b8',
    'btn_edit'         => $theme['btn_edit_color']    ?? '#e0a800',
    'btn_delete'       => $theme['btn_delete_color']  ?? '#dc3545',
    'btn_archive'      => $theme['btn_archive_color'] ?? '#6c757d',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>مخصص الثيم – CRM</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.min.css">
<style>
/* ══ صفحة مخصص الثيم ══ */
.theme-page { padding: 0; }

/* بطاقة الثيم الجاهز */
.preset-card {
    border: 2.5px solid transparent;
    border-radius: 14px;
    cursor: pointer;
    transition: all 0.22s;
    overflow: hidden;
    position: relative;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}
.preset-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.14); }
.preset-card.active { border-color: var(--crm-primary) !important; box-shadow: 0 0 0 4px rgba(var(--crm-primary-rgb),0.15), 0 8px 24px rgba(0,0,0,0.12); }
.preset-card.active::after {
    content: "\f00c";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute; top: 8px; left: 10px;
    background: #fff; color: var(--crm-primary);
    width: 24px; height: 24px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}

.preset-preview {
    height: 52px;
    position: relative;
    overflow: hidden;
}
.preset-sidebar-strip {
    position: absolute; right: 0; top: 0; bottom: 0;
    width: 30px;
}
.preset-header-strip {
    position: absolute; left: 0; right: 30px; top: 0;
    height: 18px;
    border-bottom: 2px solid rgba(255,255,255,0.3);
}
.preset-content-area {
    position: absolute; left: 0; right: 30px; top: 18px; bottom: 0;
    background: #f0f2f5;
    display: flex; align-items: center; justify-content: center; gap: 4px; padding: 4px;
}
.preset-dot { width: 12px; height: 12px; border-radius: 3px; }
.preset-info { padding: 8px 10px; text-align: center; background: #fff; }
.preset-name { font-size: 0.78rem; font-weight: 700; color: #333; }

/* أداة اختيار اللون مع مسمى */
.color-picker-wrap {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 12px;
    border: 1.5px solid #e9ecef;
    border-radius: 12px;
    transition: border-color 0.2s;
    background: #fff;
}
.color-picker-wrap:hover { border-color: var(--crm-primary); }
.color-picker-wrap input[type="color"] {
    -webkit-appearance: none;
    width: 50px; height: 50px;
    border: none; border-radius: 10px;
    cursor: pointer; padding: 2px;
    background: transparent;
}
.color-picker-wrap .color-label {
    font-size: 0.75rem; font-weight: 600; color: #666; text-align: center;
}
.color-picker-wrap .color-hex {
    font-size: 0.72rem; color: #999; font-family: monospace;
    background: #f8f9fa; padding: 2px 8px; border-radius: 20px;
}

/* بطاقة اختيار النمط */
.style-option {
    border: 2.5px solid #e9ecef;
    border-radius: 10px;
    padding: 12px 10px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    background: #fff;
}
.style-option:hover { border-color: var(--crm-primary); background: rgba(var(--crm-primary-rgb),0.04); }
.style-option.active { border-color: var(--crm-primary); background: rgba(var(--crm-primary-rgb),0.08); }
.style-option.active .style-label { color: var(--crm-primary); }
.style-option .style-preview { margin-bottom: 8px; }
.style-option .style-label { font-size: 0.8rem; font-weight: 600; color: #555; }
.style-option input[type="radio"] { display: none; }

/* معاينة الأزرار */
.btn-preview {
    padding: 7px 16px; font-size: 0.82rem; font-weight: 600;
    display: inline-block; cursor: default; margin: 2px; color: #fff;
    transition: all 0.2s;
}
.btn-preview.btn-grad  { background: linear-gradient(135deg, #154360, #2980b9); border: none; border-radius: 8px; }
.btn-preview.btn-solid { background: #1a5276; border: 1px solid #154360; border-radius: 8px; }
.btn-preview.btn-out   { background: transparent; border: 2px solid #1a5276; color: #1a5276; border-radius: 8px; }
.btn-preview.btn-pill  { background: linear-gradient(135deg, #154360, #2980b9); border: none; border-radius: 50px; }
.btn-preview.btn-sqr   { background: linear-gradient(135deg, #154360, #2980b9); border: none; border-radius: 3px; }

/* معاينة الجداول */
.table-preview-wrap { border-radius: 8px; overflow: hidden; border: 1px solid #e9ecef; }
.table-prev { width: 100%; font-size: 0.72rem; margin: 0; }
.table-prev th { padding: 6px 8px; font-weight: 700; font-size: 0.68rem; }
.table-prev td { padding: 5px 8px; }

/* Live Preview Panel */
.live-preview {
    background: var(--crm-body-bg, #f0f2f5);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
    height: 100%;
    min-height: 340px;
}
.lp-header {
    height: 44px; display: flex; align-items: center;
    padding: 0 14px; gap: 10px;
    background: #fff;
    border-bottom: 1px solid rgba(0,0,0,0.08);
}
.lp-menu-icon { width: 20px; height: 14px; display: flex; flex-direction: column; gap: 4px; cursor: pointer; }
.lp-menu-icon span { display: block; height: 2px; background: #555; border-radius: 2px; }
.lp-logo { font-size: 0.8rem; font-weight: 800; }
.lp-body { display: flex; height: calc(100% - 44px); }
.lp-sidebar {
    width: 80px; padding: 10px 0;
    display: flex; flex-direction: column; gap: 2px;
    flex-shrink: 0;
    background: #2c3e50;
}
.lp-sidebar-item {
    height: 28px; margin: 0 6px; border-radius: 6px;
    display: flex; align-items: center; padding: 0 8px;
    font-size: 0.6rem; color: rgba(255,255,255,0.7); gap: 5px;
    cursor: pointer; transition: background 0.15s;
}
.lp-sidebar-item.active { background: rgba(255,255,255,0.15); color: #fff; font-weight: 700; }
.lp-sidebar-item:hover { background: rgba(255,255,255,0.08); }
.lp-content { flex: 1; padding: 10px; overflow: hidden; background: #f0f2f5; }
.lp-card {
    background: #fff; border-radius: 10px; padding: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.lp-card-title { font-size: 0.72rem; font-weight: 700; color: #333; margin-bottom: 8px; }
.lp-table-head { display: flex; gap: 4px; margin-bottom: 4px; }
.lp-th { flex: 1; height: 18px; border-radius: 3px; background: #f4f6f9; font-size: 0.55rem; display: flex; align-items: center; justify-content: center; color: #555; font-weight: 700; }
.lp-row { display: flex; gap: 4px; margin-bottom: 3px; }
.lp-td { flex: 1; height: 16px; border-radius: 3px; background: #f8f9fa; }
.lp-btns { display: flex; gap: 5px; margin-top: 8px; }
.lp-btn { padding: 4px 10px; border-radius: 5px; font-size: 0.6rem; font-weight: 600; color: #fff; }
.lp-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.58rem; font-weight: 700; }

/* Tabs */
.theme-tabs .nav-link {
    border-radius: 10px 10px 0 0 !important;
    font-weight: 600; font-size: 0.85rem;
    color: #6c757d;
    border: none !important;
    padding: 10px 18px;
}
.theme-tabs .nav-link.active {
    background: var(--crm-primary) !important;
    color: #fff !important;
}

/* Section title inside tabs */
.section-label {
    font-size: 0.8rem; font-weight: 700; color: #6c757d;
    text-transform: uppercase; letter-spacing: 0.8px;
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 8px;
}
.section-label::after {
    content: ''; flex: 1; height: 1px; background: #e9ecef;
}

/* Font Preview */
.font-card {
    border: 2.5px solid #e9ecef; border-radius: 12px;
    padding: 14px; cursor: pointer; transition: all 0.2s;
    background: #fff; text-align: center;
}
.font-card:hover { border-color: var(--crm-primary); background: rgba(var(--crm-primary-rgb),0.04); }
.font-card.active { border-color: var(--crm-primary); background: rgba(var(--crm-primary-rgb),0.06); }
.font-card .font-sample { font-size: 1.4rem; font-weight: 700; color: #333; margin-bottom: 4px; line-height: 1.2; }
.font-card .font-name { font-size: 0.75rem; color: #888; }
.font-card input { display: none; }

/* Floating Save */
.float-save-bar {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    z-index: 1050;
    background: #fff;
    border-radius: 50px;
    padding: 10px 20px;
    box-shadow: 0 6px 30px rgba(0,0,0,0.18);
    display: flex; align-items: center; gap: 14px;
    border: 1.5px solid rgba(var(--crm-primary-rgb),0.2);
    transition: all 0.3s;
}
.float-save-bar.hidden { opacity: 0; pointer-events: none; transform: translateX(-50%) translateY(20px); }
#btnSave {
    padding: 9px 28px; font-weight: 700; font-size: 0.9rem;
    border-radius: 50px !important;
}
.unsaved-dot { width: 8px; height: 8px; border-radius: 50%; background: #f39c12; animation: pulse-dot 1.5s infinite; }
@keyframes pulse-dot {
    0%,100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.8); }
}

.radius-preview {
    width: 60px; height: 30px; background: var(--crm-primary, #1a5276);
    display: inline-block; margin: 4px auto;
}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper theme-page">
    <!-- ترويسة الصفحة -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-7">
                    <h1>
                        <i class="fas fa-palette"></i>
                        مخصص الثيم والمظهر
                        <small class="text-muted" style="font-size:0.65em;font-weight:400;">تحكم كامل في شكل النظام</small>
                    </h1>
                </div>
                <div class="col-sm-5">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                        <li class="breadcrumb-item"><a href="system-settings.php">الإعدادات</a></li>
                        <li class="breadcrumb-item active">مخصص الثيم</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="row">

                <!-- ══ العمود الأيسر: المعاينة الحية ══ -->
                <div class="col-lg-4 mb-4">
                    <div class="card h-100" style="position:sticky; top:70px;">
                        <div class="card-header" style="background:linear-gradient(135deg,var(--crm-primary,#1a5276),var(--crm-primary-light,#2980b9)) !important; color:#fff !important;">
                            <h5 class="mb-0"><i class="fas fa-eye ml-2"></i> معاينة حية</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="live-preview" id="livePreview">
                                <!-- هيدر -->
                                <div class="lp-header" id="lpHeader">
                                    <div class="lp-menu-icon"><span></span><span></span><span></span></div>
                                    <div class="lp-logo" id="lpLogo" style="color:#333;">نظام CRM</div>
                                    <div class="ml-auto" style="display:flex;gap:8px;align-items:center;">
                                        <div style="width:22px;height:22px;border-radius:50%;background:#e9ecef;display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-bell" style="font-size:9px;color:#999;"></i>
                                        </div>
                                        <div style="width:22px;height:22px;border-radius:50%;background:#e9ecef;"></div>
                                    </div>
                                </div>
                                <!-- جسم -->
                                <div class="lp-body">
                                    <div class="lp-sidebar" id="lpSidebar">
                                        <div class="lp-sidebar-item active" id="lpSideActive">
                                            <i class="fas fa-home" style="font-size:8px;"></i>
                                            الرئيسية
                                        </div>
                                        <div class="lp-sidebar-item"><i class="fas fa-users" style="font-size:8px;"></i> المستخدمون</div>
                                        <div class="lp-sidebar-item"><i class="fas fa-file" style="font-size:8px;"></i> الوثائق</div>
                                        <div class="lp-sidebar-item"><i class="fas fa-cog" style="font-size:8px;"></i> الإعدادات</div>
                                    </div>
                                    <div class="lp-content">
                                        <!-- stat cards -->
                                        <div style="display:flex;gap:6px;margin-bottom:8px;">
                                            <div style="flex:1;border-radius:8px;height:38px;display:flex;align-items:center;padding:0 8px;gap:6px;" id="lpStatCard1">
                                                <div id="lpStatIcon" style="width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;">
                                                    <i class="fas fa-file" style="font-size:9px;color:#fff;"></i>
                                                </div>
                                                <div><div style="font-size:0.65rem;font-weight:800;color:#fff;">120</div><div style="font-size:0.5rem;color:rgba(255,255,255,0.8);">وثيقة</div></div>
                                            </div>
                                            <div style="flex:1;border-radius:8px;height:38px;background:linear-gradient(135deg,#1e8449,#27ae60);display:flex;align-items:center;padding:0 8px;gap:6px;">
                                                <div style="width:22px;height:22px;border-radius:6px;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">
                                                    <i class="fas fa-check" style="font-size:9px;color:#fff;"></i>
                                                </div>
                                                <div><div style="font-size:0.65rem;font-weight:800;color:#fff;">45</div><div style="font-size:0.5rem;color:rgba(255,255,255,0.8);">مكتمل</div></div>
                                            </div>
                                        </div>
                                        <!-- بطاقة الجدول -->
                                        <div class="lp-card" id="lpCard">
                                            <div class="lp-card-title" id="lpCardTitle">قائمة البيانات</div>
                                            <!-- أزرار التصدير -->
                                            <div style="display:flex;gap:3px;margin-bottom:6px;">
                                                <span style="padding:2px 7px;border-radius:4px;font-size:0.55rem;color:#fff;background:#5a6268;">طباعة</span>
                                                <span style="padding:2px 7px;border-radius:4px;font-size:0.55rem;color:#fff;background:#c82333;">PDF</span>
                                                <span style="padding:2px 7px;border-radius:4px;font-size:0.55rem;color:#fff;background:#1e7e34;">Excel</span>
                                                <span id="lpExportBtn" style="padding:2px 7px;border-radius:4px;font-size:0.55rem;color:#fff;">الأعمدة</span>
                                            </div>
                                            <!-- جدول مصغر -->
                                            <div class="lp-table-head">
                                                <div class="lp-th" id="lpTh">#</div>
                                                <div class="lp-th" id="lpTh2">الاسم</div>
                                                <div class="lp-th" id="lpTh3">الحالة</div>
                                                <div class="lp-th" id="lpTh4">إجراء</div>
                                            </div>
                                            <div class="lp-row"><div class="lp-td"></div><div class="lp-td"></div>
                                                <div class="lp-td" style="display:flex;align-items:center;padding:0 4px;">
                                                    <span class="lp-badge" id="lpBadge1" style="background:#d1e7dd;color:#0a3622;">مكتمل</span>
                                                </div>
                                                <div class="lp-td" style="display:flex;align-items:center;gap:2px;padding:0 2px;">
                                                    <span class="lp-btn" id="lpActionBtn1" style="padding:2px 5px;border-radius:3px;font-size:0.5rem;">تعديل</span>
                                                </div>
                                            </div>
                                            <div class="lp-row"><div class="lp-td"></div><div class="lp-td"></div>
                                                <div class="lp-td" style="display:flex;align-items:center;padding:0 4px;">
                                                    <span class="lp-badge" style="background:#fff3cd;color:#856404;">انتظار</span>
                                                </div>
                                                <div class="lp-td" style="display:flex;align-items:center;gap:2px;padding:0 2px;">
                                                    <span class="lp-btn" id="lpActionBtn2" style="padding:2px 5px;border-radius:3px;font-size:0.5rem;">عرض</span>
                                                </div>
                                            </div>
                                            <!-- أزرار -->
                                            <div class="lp-btns">
                                                <span class="lp-btn" id="lpBtn1" style="border-radius:5px;">إضافة جديد</span>
                                                <span class="lp-btn" id="lpBtn2" style="background:#1e8449;border-radius:5px;">حفظ</span>
                                                <span class="lp-btn" id="lpBtn3" style="background:#cb4335;border-radius:5px;">حذف</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- معلومات الثيم الحالي -->
                            <div class="mt-3 p-3" style="background:#f8f9fa;border-radius:10px;">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div id="curPrimaryDot" style="width:32px;height:32px;border-radius:50%;margin:0 auto 4px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.2);"></div>
                                        <div style="font-size:0.65rem;color:#888;">رئيسي</div>
                                    </div>
                                    <div class="col-3">
                                        <div id="curAccentDot" style="width:32px;height:32px;border-radius:50%;margin:0 auto 4px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.2);"></div>
                                        <div style="font-size:0.65rem;color:#888;">ثانوي</div>
                                    </div>
                                    <div class="col-3">
                                        <div id="curSidebarDot" style="width:32px;height:32px;border-radius:50%;margin:0 auto 4px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.2);"></div>
                                        <div style="font-size:0.65rem;color:#888;">شريط</div>
                                    </div>
                                    <div class="col-3">
                                        <div id="curHeaderDot" style="width:32px;height:32px;border-radius:50%;margin:0 auto 4px;border:2px solid #ddd;box-shadow:0 2px 6px rgba(0,0,0,0.2);"></div>
                                        <div style="font-size:0.65rem;color:#888;">هيدر</div>
                                    </div>
                                </div>
                                <div class="text-center mt-2" style="font-size:0.72rem;color:#888;">
                                    الخط: <strong id="curFontLabel"><?= htmlspecialchars($cur['font']) ?></strong> &nbsp;|&nbsp;
                                    النمط: <strong id="curStyleLabel"><?= htmlspecialchars($cur['btn_style']) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ العمود الأيمن: الإعدادات ══ -->
                <div class="col-lg-8">
                    <form id="themeForm">
                        <input type="hidden" name="save_theme" value="1">
                        <input type="hidden" name="theme_preset" id="fPreset" value="<?= htmlspecialchars($cur['preset']) ?>">
                        <input type="hidden" name="primary_color" id="fPrimary" value="<?= htmlspecialchars($cur['primary']) ?>">
                        <input type="hidden" name="accent_color" id="fAccent" value="<?= htmlspecialchars($cur['accent']) ?>">
                        <input type="hidden" name="sidebar_color" id="fSidebar" value="<?= htmlspecialchars($cur['sidebar']) ?>">
                        <input type="hidden" name="header_color" id="fHeader" value="<?= htmlspecialchars($cur['header']) ?>">
                        <input type="hidden" name="system_font" id="fFont" value="<?= htmlspecialchars($cur['font']) ?>">
                        <input type="hidden" name="btn_radius" id="fBtnRadius" value="<?= htmlspecialchars($cur['btn_radius']) ?>">
                        <input type="hidden" name="btn_style" id="fBtnStyle" value="<?= htmlspecialchars($cur['btn_style']) ?>">
                        <input type="hidden" name="table_style" id="fTableStyle" value="<?= htmlspecialchars($cur['table_style']) ?>">
                        <input type="hidden" name="card_radius" id="fCardRadius" value="<?= htmlspecialchars($cur['card_radius']) ?>">
                        <input type="hidden" name="page_bar_style" id="fPageBarStyle" value="<?= htmlspecialchars($cur['page_bar_style']) ?>">
                        <input type="hidden" name="page_bar_radius" id="fPageBarRadius" value="<?= htmlspecialchars($cur['page_bar_radius']) ?>">
                        <input type="hidden" name="btn_print_color" id="fBtnPrint" value="<?= htmlspecialchars($cur['btn_print']) ?>">
                        <input type="hidden" name="btn_pdf_color" id="fBtnPdf" value="<?= htmlspecialchars($cur['btn_pdf']) ?>">
                        <input type="hidden" name="btn_excel_color" id="fBtnExcel" value="<?= htmlspecialchars($cur['btn_excel']) ?>">
                        <input type="hidden" name="btn_colvis_color" id="fBtnColvis" value="<?= htmlspecialchars($cur['btn_colvis']) ?>">
                        <input type="hidden" name="btn_add_color" id="fBtnAdd" value="<?= htmlspecialchars($cur['btn_add']) ?>">
                        <input type="hidden" name="footer_bg" id="fFooterBg" value="<?= htmlspecialchars($cur['footer_bg']) ?>">
                        <input type="hidden" name="footer_text" id="fFooterText" value="<?= htmlspecialchars($cur['footer_text']) ?>">
                        <input type="hidden" name="topbar_visible" id="fTopbarVisible" value="<?= $cur['topbar_visible'] ? '1' : '0' ?>">
                        <input type="hidden" name="topbar_shadow" id="fTopbarShadow" value="<?= $cur['topbar_shadow'] ? '1' : '0' ?>">
                        <input type="hidden" name="btn_view_color"    id="fBtnView"    value="<?= htmlspecialchars($cur['btn_view']) ?>">
                        <input type="hidden" name="btn_edit_color"    id="fBtnEdit"    value="<?= htmlspecialchars($cur['btn_edit']) ?>">
                        <input type="hidden" name="btn_delete_color"  id="fBtnDelete"  value="<?= htmlspecialchars($cur['btn_delete']) ?>">
                        <input type="hidden" name="btn_archive_color" id="fBtnArchive" value="<?= htmlspecialchars($cur['btn_archive']) ?>">

                        <!-- Tabs Navigation -->
                        <ul class="nav nav-tabs theme-tabs mb-0" id="themeTab">
                            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-sysinfo"><i class="fas fa-building ml-1"></i> معلومات النظام</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-presets"><i class="fas fa-th-large ml-1"></i> الثيمات الجاهزة</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-colors"><i class="fas fa-fill-drip ml-1"></i> الألوان</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-fonts"><i class="fas fa-font ml-1"></i> الخطوط</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-shapes"><i class="fas fa-vector-square ml-1"></i> الأزرار</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-tables"><i class="fas fa-table ml-1"></i> الجداول</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-elements"><i class="fas fa-layer-group ml-1"></i> العناصر</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-advanced"><i class="fas fa-sliders-h ml-1"></i> متقدم</a></li>
                        </ul>

                        <div class="tab-content card" style="border-radius:0 0 14px 14px !important; padding:20px;">

                            <!-- ═══ تاب 0: معلومات النظام ═══ -->
                            <div class="tab-pane fade show active" id="tab-sysinfo">
                                <p class="text-muted mb-4" style="font-size:.85rem;">
                                    <i class="fas fa-info-circle ml-1 text-primary"></i>
                                    معلومات الشركة التي تظهر في الترويسة والتذييل وجميع صفحات النظام.
                                </p>

                                <div class="section-label"><i class="fas fa-building ml-2 text-primary"></i> هوية الشركة</div>
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <label style="font-weight:700;font-size:.85rem;color:#555;margin-bottom:6px;display:block">اسم النظام بالعربية <span style="color:#dc3545">*</span></label>
                                        <input type="text" id="si_name" class="form-control"
                                               value="<?= htmlspecialchars($sysInfo['system_name'] ?? '') ?>"
                                               placeholder="مثال: نظام وَصل للإدارة">
                                        <small class="text-muted">يظهر في الترويسة الرئيسية والتذييل</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label style="font-weight:700;font-size:.85rem;color:#555;margin-bottom:6px;display:block">اسم النظام بالإنجليزية</label>
                                        <input type="text" id="si_name_en" class="form-control"
                                               value="<?= htmlspecialchars($sysInfo['system_name_en'] ?? '') ?>"
                                               placeholder="e.g. Wasl Management System" dir="ltr">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label style="font-weight:700;font-size:.85rem;color:#555;margin-bottom:6px;display:block">شعار الموقع (Tagline)</label>
                                        <input type="text" id="si_tagline" class="form-control"
                                               value="<?= htmlspecialchars($sysInfo['site_tagline'] ?? '') ?>"
                                               placeholder="مثال: الحلول الذكية للإدارة">
                                        <small class="text-muted">يظهر أسفل اسم النظام في الترويسة</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label style="font-weight:700;font-size:.85rem;color:#555;margin-bottom:6px;display:block">نص التذييل (Footer)</label>
                                        <input type="text" id="si_footer" class="form-control"
                                               value="<?= htmlspecialchars($sysInfo['footer_tagline'] ?? '') ?>"
                                               placeholder="مثال: نظام توثيق المشاكل الداخلية">
                                        <small class="text-muted">يظهر في شريط التذييل السفلي</small>
                                    </div>
                                </div>

                                <div class="section-label"><i class="fas fa-envelope ml-2 text-info"></i> بيانات التواصل</div>
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <label style="font-weight:700;font-size:.85rem;color:#555;margin-bottom:6px;display:block">البريد الإلكتروني</label>
                                        <input type="email" id="si_email" class="form-control"
                                               value="<?= htmlspecialchars($sysInfo['admin_email'] ?? '') ?>"
                                               placeholder="admin@company.com" dir="ltr">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label style="font-weight:700;font-size:.85rem;color:#555;margin-bottom:6px;display:block">رقم التواصل</label>
                                        <input type="text" id="si_phone" class="form-control"
                                               value="<?= htmlspecialchars($sysInfo['contact_number'] ?? '') ?>"
                                               placeholder="+966xxxxxxxxx" dir="ltr">
                                    </div>
                                </div>

                                <!-- معاينة التذييل -->
                                <div class="section-label"><i class="fas fa-eye ml-2 text-success"></i> معاينة التذييل</div>
                                <div id="footerPreviewSysInfo" style="
                                    background:var(--crm-footer-bg,#1e272e);
                                    color:var(--crm-footer-text,#adb5bd);
                                    padding:12px 20px; border-radius:10px;
                                    display:flex; align-items:center; justify-content:space-between;
                                    font-size:.82rem; font-weight:500; flex-wrap:wrap; gap:8px;
                                ">
                                    <div>
                                        <i class="fas fa-copyright" style="margin-left:4px;opacity:.7;"></i>
                                        <strong><?= date('Y') ?></strong>
                                        <span id="fp_name" style="font-weight:700;"><?= htmlspecialchars($sysInfo['system_name'] ?? 'النظام') ?></span>
                                        &mdash; <span id="fp_footer"><?= htmlspecialchars($sysInfo['footer_tagline'] ?? '') ?></span>
                                    </div>
                                    <div style="opacity:.8;font-size:.78rem;">
                                        <i class="fas fa-code" style="margin-left:4px;"></i>
                                        <span id="fp_name_en"><?= htmlspecialchars($sysInfo['system_name_en'] ?? '') ?></span>
                                    </div>
                                </div>

                                <div class="text-center mt-4">
                                    <?php if ($can_add): ?>
                                    <button type="button" id="btnSaveSysInfo" class="btn btn-primary px-5" style="border-radius:50px;font-weight:700;">
                                        <i class="fas fa-save ml-2"></i>حفظ معلومات النظام
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- /معلومات النظام -->

                            <!-- ═══ تاب 1: الثيمات الجاهزة ═══ -->
                            <div class="tab-pane fade show active" id="tab-presets">
                                <p class="text-muted mb-3" style="font-size:0.85rem;">
                                    <i class="fas fa-info-circle ml-1 text-primary"></i>
                                    اختر ثيماً جاهزاً أو خصص الألوان يدوياً من تاب "الألوان".
                                </p>
                                <div class="row">
                                    <?php foreach ($presets as $key => $p): ?>
                                    <div class="col-md-3 col-sm-4 col-6 mb-3">
                                        <div class="preset-card <?= $cur['preset'] === $key ? 'active' : '' ?>"
                                             data-preset="<?= $key ?>"
                                             data-primary="<?= $p['primary'] ?>"
                                             data-accent="<?= $p['accent'] ?>"
                                             data-sidebar="<?= $p['sidebar'] ?>"
                                             data-header="<?= $p['header'] ?>">
                                            <div class="preset-preview">
                                                <div class="preset-sidebar-strip" style="background:<?= $p['sidebar'] ?>"></div>
                                                <div class="preset-header-strip" style="background:<?= $p['header'] === '#ffffff' ? '#ffffff' : $p['header'] ?>; border-bottom: 2px solid <?= $p['primary'] ?>33;"></div>
                                                <div class="preset-content-area">
                                                    <div class="preset-dot" style="background:<?= $p['primary'] ?>"></div>
                                                    <div class="preset-dot" style="background:<?= $p['accent'] ?>"></div>
                                                    <div class="preset-dot" style="background:#d1e7dd"></div>
                                                </div>
                                            </div>
                                            <div class="preset-info">
                                                <div class="preset-name"><?= $p['label'] ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ═══ تاب 2: الألوان ═══ -->
                            <div class="tab-pane fade" id="tab-colors">
                                <div class="section-label"><i class="fas fa-palette ml-2 text-primary"></i> اختيار الألوان يدوياً</div>
                                <div class="row justify-content-center g-3">
                                    <div class="col-md-3 col-6">
                                        <div class="color-picker-wrap">
                                            <input type="color" id="cpPrimary" value="<?= htmlspecialchars($cur['primary']) ?>">
                                            <div class="color-label">اللون الرئيسي</div>
                                            <div class="color-hex" id="cpPrimaryHex"><?= htmlspecialchars($cur['primary']) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="color-picker-wrap">
                                            <input type="color" id="cpAccent" value="<?= htmlspecialchars($cur['accent']) ?>">
                                            <div class="color-label">اللون الثانوي</div>
                                            <div class="color-hex" id="cpAccentHex"><?= htmlspecialchars($cur['accent']) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="color-picker-wrap">
                                            <input type="color" id="cpSidebar" value="<?= htmlspecialchars($cur['sidebar']) ?>">
                                            <div class="color-label">لون الشريط الجانبي</div>
                                            <div class="color-hex" id="cpSidebarHex"><?= htmlspecialchars($cur['sidebar']) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="color-picker-wrap">
                                            <input type="color" id="cpHeader" value="<?= htmlspecialchars($cur['header']) ?>">
                                            <div class="color-label">لون شريط العنوان</div>
                                            <div class="color-hex" id="cpHeaderHex"><?= htmlspecialchars($cur['header']) ?></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ألوان مقترحة -->
                                <div class="mt-4">
                                    <div class="section-label"><i class="fas fa-swatchbook ml-2 text-warning"></i> ألوان رئيسية مقترحة</div>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                        <?php
                                        $quickColors = ['#1a5276','#145a32','#512e5f','#7d6608','#0b6278','#78281f','#1b2631','#0b5345','#1a3a5c','#4a235a','#922b21','#784212'];
                                        foreach ($quickColors as $c): ?>
                                        <div class="quick-color" data-color="<?= $c ?>" data-target="primary"
                                             style="width:34px;height:34px;border-radius:8px;background:<?= $c ?>;cursor:pointer;transition:transform 0.2s;box-shadow:0 2px 6px rgba(0,0,0,0.15);"
                                             title="<?= $c ?>"></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ تاب 3: الخطوط ═══ -->
                            <div class="tab-pane fade" id="tab-fonts">
                                <div class="section-label"><i class="fas fa-font ml-2 text-primary"></i> خط النظام</div>
                                <div class="row">
                                    <?php
                                    $fonts = [
                                        'Cairo'   => ['sample'=>'أبجد هوز حطي','desc'=>'Cairo – خط عربي عصري سلس'],
                                        'Almarai' => ['sample'=>'أبجد هوز حطي','desc'=>'Almarai – خط احترافي واضح'],
                                        'Tajawal' => ['sample'=>'أبجد هوز حطي','desc'=>'Tajawal – خط ناعم وأنيق'],
                                    ];
                                    foreach ($fonts as $fname => $fdata): ?>
                                    <div class="col-md-4 col-sm-6 mb-3">
                                        <label class="font-card <?= $cur['font']==$fname ? 'active' : '' ?>" style="cursor:pointer;display:block;">
                                            <input type="radio" name="font_radio" value="<?= $fname ?>" <?= $cur['font']==$fname ? 'checked' : '' ?>>
                                            <div class="font-sample" style="font-family:'<?= $fname ?>',sans-serif;"><?= $fdata['sample'] ?></div>
                                            <div class="font-name"><?= $fdata['desc'] ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ═══ تاب 4: شكل الأزرار ═══ -->
                            <div class="tab-pane fade" id="tab-shapes">
                                <!-- نمط الزر -->
                                <div class="section-label"><i class="fas fa-paint-brush ml-2 text-primary"></i> نمط الأزرار</div>
                                <div class="row mb-4">
                                    <?php
                                    $btnStyles = [
                                        'gradient' => ['label'=>'تدرج لوني','preview'=>'btn-grad'],
                                        'solid'    => ['label'=>'لون ثابت', 'preview'=>'btn-solid'],
                                        'outline'  => ['label'=>'مخطط',     'preview'=>'btn-out'],
                                        'pill'     => ['label'=>'دائري',    'preview'=>'btn-pill'],
                                        'square'   => ['label'=>'مربع',     'preview'=>'btn-sqr'],
                                    ];
                                    foreach ($btnStyles as $sk => $sv): ?>
                                    <div class="col-md-2 col-4 mb-3">
                                        <label class="style-option <?= $cur['btn_style']==$sk ? 'active' : '' ?>" style="cursor:pointer;display:block;">
                                            <input type="radio" name="btn_style_radio" value="<?= $sk ?>" <?= $cur['btn_style']==$sk ? 'checked' : '' ?>>
                                            <div class="style-preview">
                                                <span class="btn-preview <?= $sv['preview'] ?>">زر</span>
                                            </div>
                                            <div class="style-label"><?= $sv['label'] ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- نصف قطر الزر -->
                                <div class="section-label"><i class="fas fa-circle-notch ml-2 text-info"></i> حواف الأزرار (Border Radius)</div>
                                <div class="row">
                                    <?php
                                    $radii = ['0px'=>'حاد','4px'=>'خفيف','8px'=>'متوسط (افتراضي)','12px'=>'منحني','50px'=>'دائري كامل'];
                                    foreach ($radii as $rv => $rl): ?>
                                    <div class="col-md-2 col-4 mb-3">
                                        <label class="style-option <?= $cur['btn_radius']==$rv ? 'active' : '' ?>" style="cursor:pointer;display:block;">
                                            <input type="radio" name="btn_radius_radio" value="<?= $rv ?>" <?= $cur['btn_radius']==$rv ? 'checked' : '' ?>>
                                            <div class="style-preview">
                                                <div class="radius-preview" style="border-radius:<?= $rv ?>;"></div>
                                            </div>
                                            <div class="style-label"><?= $rl ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- نصف قطر البطاقات -->
                                <div class="section-label mt-2"><i class="fas fa-id-card ml-2 text-success"></i> حواف البطاقات (Card Radius)</div>
                                <div class="row">
                                    <?php
                                    $cardRadii = ['0px'=>'حاد','4px'=>'خفيف','8px'=>'ناعم','14px'=>'متوسط (افتراضي)','20px'=>'كبير'];
                                    foreach ($cardRadii as $rv => $rl): ?>
                                    <div class="col-md-2 col-4 mb-3">
                                        <label class="style-option <?= $cur['card_radius']==$rv ? 'active' : '' ?>" style="cursor:pointer;display:block;">
                                            <input type="radio" name="card_radius_radio" value="<?= $rv ?>" <?= $cur['card_radius']==$rv ? 'checked' : '' ?>>
                                            <div class="style-preview">
                                                <div style="width:40px;height:26px;background:#e9ecef;border-radius:<?= $rv ?>;margin:0 auto;"></div>
                                            </div>
                                            <div class="style-label"><?= $rl ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ═══ تاب 5: الجداول ═══ -->
                            <div class="tab-pane fade" id="tab-tables">
                                <div class="section-label"><i class="fas fa-table ml-2 text-primary"></i> نمط عرض الجداول</div>
                                <div class="row">
                                    <?php
                                    $tableStyles = [
                                        'default' => 'افتراضي',
                                        'striped' => 'مخطط',
                                        'bordered'=> 'محاط بإطار',
                                        'minimal' => 'بسيط',
                                        'compact' => 'مضغوط',
                                    ];
                                    $tablePreviews = [
                                        'default' => ['#f4f6f9','#ffffff','#f8f9fa'],
                                        'striped' => ['#f4f6f9','#f0f4ff','#ffffff'],
                                        'bordered'=> ['#f4f6f9','#ffffff','#f8f9fa'],
                                        'minimal' => ['transparent','#ffffff','#f8f9fa'],
                                        'compact' => ['#f4f6f9','#ffffff','#f8f9fa'],
                                    ];
                                    foreach ($tableStyles as $ts => $tl): ?>
                                    <div class="col-md-4 col-sm-6 mb-4">
                                        <label class="style-option <?= $cur['table_style']==$ts ? 'active' : '' ?>" style="cursor:pointer;display:block;padding:14px;">
                                            <input type="radio" name="table_style_radio" value="<?= $ts ?>" <?= $cur['table_style']==$ts ? 'checked' : '' ?>>
                                            <!-- معاينة مصغرة للجدول -->
                                            <div class="table-preview-wrap mb-2" style="<?= $ts==='bordered' ? 'border:1.5px solid #dee2e6' : '' ?>">
                                                <table class="table-prev">
                                                    <thead>
                                                        <tr style="background:<?= $tablePreviews[$ts][0] ?>;">
                                                            <th style="border-bottom:2px solid var(--crm-primary,#1a5276);<?= $ts==='minimal'?'background:transparent;':'' ?>">#</th>
                                                            <th style="border-bottom:2px solid var(--crm-primary,#1a5276);">الاسم</th>
                                                            <th style="border-bottom:2px solid var(--crm-primary,#1a5276);">الحالة</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr style="background:<?= $tablePreviews[$ts][1] ?>;<?= $ts==='bordered'?'border:1px solid #dee2e6':'' ?>">
                                                            <td style="<?= $ts==='compact'?'padding:3px 6px':'' ?>">1</td>
                                                            <td style="<?= $ts==='compact'?'padding:3px 6px':'' ?>">أحمد محمد</td>
                                                            <td style="<?= $ts==='compact'?'padding:3px 6px':'' ?>"><span style="background:#d1e7dd;color:#0a3622;padding:1px 5px;border-radius:8px;font-size:0.6rem;">نشط</span></td>
                                                        </tr>
                                                        <tr style="background:<?= $tablePreviews[$ts][2] ?>;">
                                                            <td style="<?= $ts==='compact'?'padding:3px 6px':'' ?>">2</td>
                                                            <td style="<?= $ts==='compact'?'padding:3px 6px':'' ?>">فاطمة علي</td>
                                                            <td style="<?= $ts==='compact'?'padding:3px 6px':'' ?>"><span style="background:#fff3cd;color:#856404;padding:1px 5px;border-radius:8px;font-size:0.6rem;">انتظار</span></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="style-label"><?= $tl ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ═══ تاب 6: العناصر ═══ -->
                            <div class="tab-pane fade" id="tab-elements">

                                <!-- ── شريط عنوان الصفحة ── -->
                                <div class="section-label"><i class="fas fa-heading ml-2 text-primary"></i> شريط عنوان الصفحة (Page Header Bar)</div>
                                <p class="text-muted mb-3" style="font-size:0.82rem;"><i class="fas fa-info-circle ml-1"></i> هذا الشريط يظهر في رأس كل صفحة ويحتوي على عنوان الصفحة ومسار التنقل (Breadcrumb)</p>

                                <!-- أنماط الشريط -->
                                <div class="row mb-3">
                                    <?php
                                    $barStyles = [
                                        'gradient'    => ['label'=>'تدرج لوني',  'icon'=>'fas fa-layer-group'],
                                        'solid'       => ['label'=>'لون ثابت',   'icon'=>'fas fa-square'],
                                        'flat'        => ['label'=>'مسطح بلا حواف','icon'=>'fas fa-minus'],
                                        'glass'       => ['label'=>'زجاجي',      'icon'=>'fas fa-tint'],
                                        'dark'        => ['label'=>'داكن',        'icon'=>'fas fa-moon'],
                                        'transparent' => ['label'=>'شفاف',       'icon'=>'fas fa-eye-slash'],
                                    ];
                                    foreach ($barStyles as $bk => $bv): ?>
                                    <div class="col-md-2 col-4 mb-3">
                                        <label class="style-option <?= $cur['page_bar_style']===$bk ? 'active' : '' ?>" style="cursor:pointer;display:block;padding:10px 8px;" data-bar-style="<?= $bk ?>">
                                            <input type="radio" name="page_bar_style_radio" value="<?= $bk ?>" <?= $cur['page_bar_style']===$bk ? 'checked' : '' ?>>
                                            <!-- معاينة مصغرة -->
                                            <div class="style-preview mb-1">
                                                <?php if ($bk === 'gradient'): ?>
                                                <div style="height:28px;border-radius:6px;background:linear-gradient(135deg,#154360,#2980b9);display:flex;align-items:center;padding:0 8px;">
                                                    <span style="color:#fff;font-size:0.55rem;font-weight:700;">عنوان الصفحة</span>
                                                </div>
                                                <?php elseif ($bk === 'solid'): ?>
                                                <div style="height:28px;border-radius:6px;background:#1a5276;display:flex;align-items:center;padding:0 8px;">
                                                    <span style="color:#fff;font-size:0.55rem;font-weight:700;">عنوان الصفحة</span>
                                                </div>
                                                <?php elseif ($bk === 'flat'): ?>
                                                <div style="height:28px;border-radius:0;background:linear-gradient(135deg,#154360,#2980b9);display:flex;align-items:center;padding:0 8px;">
                                                    <span style="color:#fff;font-size:0.55rem;font-weight:700;">عنوان الصفحة</span>
                                                </div>
                                                <?php elseif ($bk === 'glass'): ?>
                                                <div style="height:28px;border-radius:6px;background:rgba(26,82,118,0.12);border:1px solid rgba(26,82,118,0.3);display:flex;align-items:center;padding:0 8px;">
                                                    <span style="color:#1a5276;font-size:0.55rem;font-weight:700;">عنوان الصفحة</span>
                                                </div>
                                                <?php elseif ($bk === 'dark'): ?>
                                                <div style="height:28px;border-radius:6px;background:linear-gradient(135deg,#0d1117,#1c2128);display:flex;align-items:center;padding:0 8px;">
                                                    <span style="color:#fff;font-size:0.55rem;font-weight:700;">عنوان الصفحة</span>
                                                </div>
                                                <?php else: ?>
                                                <div style="height:28px;border-radius:6px;background:transparent;border-bottom:2px solid #1a5276;display:flex;align-items:center;padding:0 8px;">
                                                    <span style="color:#1a5276;font-size:0.55rem;font-weight:700;">عنوان الصفحة</span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="style-label"><?= $bv['label'] ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- حواف الشريط -->
                                <div class="section-label"><i class="fas fa-circle-notch ml-2 text-info"></i> حواف شريط العنوان</div>
                                <div class="row mb-4">
                                    <?php
                                    $barRadii = ['0px'=>'حاد (مسطح)','6px'=>'خفيف','10px'=>'متوسط','14px'=>'كبير (افتراضي)','20px'=>'دائري'];
                                    foreach ($barRadii as $rv => $rl): ?>
                                    <div class="col-md-2 col-4 mb-2">
                                        <label class="style-option <?= $cur['page_bar_radius']===$rv ? 'active' : '' ?>" style="cursor:pointer;display:block;padding:8px;" data-bar-radius="<?= $rv ?>">
                                            <input type="radio" name="page_bar_radius_radio" value="<?= $rv ?>" <?= $cur['page_bar_radius']===$rv ? 'checked' : '' ?>>
                                            <div style="height:20px;background:#1a5276;border-radius:<?= $rv ?>;margin-bottom:4px;"></div>
                                            <div class="style-label" style="font-size:0.7rem;"><?= $rl ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <hr class="my-4">

                                <!-- ── أزرار التصدير ── -->
                                <div class="section-label"><i class="fas fa-download ml-2 text-success"></i> ألوان أزرار التصدير والإجراءات</div>
                                <div class="row">
                                    <?php
                                    $exportBtns = [
                                        ['id'=>'cpBtnPrint',  'fid'=>'fBtnPrint',  'val'=>$cur['btn_print'],  'label'=>'طباعة',   'icon'=>'fa-print'],
                                        ['id'=>'cpBtnPdf',    'fid'=>'fBtnPdf',    'val'=>$cur['btn_pdf'],    'label'=>'PDF',     'icon'=>'fa-file-pdf'],
                                        ['id'=>'cpBtnExcel',  'fid'=>'fBtnExcel',  'val'=>$cur['btn_excel'],  'label'=>'Excel',   'icon'=>'fa-file-excel'],
                                        ['id'=>'cpBtnColvis', 'fid'=>'fBtnColvis', 'val'=>$cur['btn_colvis'], 'label'=>'الأعمدة','icon'=>'fa-columns'],
                                        ['id'=>'cpBtnAdd',    'fid'=>'fBtnAdd',    'val'=>$cur['btn_add'],    'label'=>'إضافة',   'icon'=>'fa-plus'],
                                    ];
                                    foreach ($exportBtns as $eb): ?>
                                    <div class="col-md-2 col-4 mb-3">
                                        <div class="color-picker-wrap text-center">
                                            <!-- معاينة الزر -->
                                            <div class="export-btn-preview" id="prev_<?= $eb['id'] ?>"
                                                 style="background:<?= htmlspecialchars($eb['val']) ?>;padding:6px 14px;border-radius:7px;color:#fff;font-size:0.72rem;font-weight:700;margin-bottom:8px;display:inline-flex;align-items:center;gap:4px;cursor:default;">
                                                <i class="fas <?= $eb['icon'] ?>" style="font-size:0.65rem;"></i>
                                                <?= $eb['label'] ?>
                                            </div>
                                            <input type="color" id="<?= $eb['id'] ?>" value="<?= htmlspecialchars($eb['val']) ?>"
                                                   data-fid="<?= $eb['fid'] ?>" data-prev="prev_<?= $eb['id'] ?>"
                                                   class="export-btn-color-picker"
                                                   style="-webkit-appearance:none;width:36px;height:36px;border:none;border-radius:8px;cursor:pointer;padding:2px;background:transparent;">
                                            <div class="color-label" style="font-size:0.72rem;font-weight:700;"><?= $eb['label'] ?></div>
                                            <div class="color-hex" id="hex_<?= $eb['id'] ?>" style="font-size:0.65rem;"><?= htmlspecialchars($eb['val']) ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <hr class="my-4">

                                <!-- ── أزرار الإجراءات ── -->
                                <div class="section-label"><i class="fas fa-mouse-pointer ml-2 text-warning"></i> ألوان أزرار الإجراءات (أزرار الصفوف)</div>
                                <p class="text-muted mb-3" style="font-size:0.82rem;"><i class="fas fa-info-circle ml-1"></i> هذه الأزرار تظهر في كل صف من الجدول (عرض، تعديل، حذف، أرشفة)</p>

                                <!-- معاينة أزرار الإجراءات -->
                                <div class="mb-3 p-3 rounded" style="background:#f8f9fa;border:1px solid #e9ecef;">
                                    <div style="font-size:0.75rem;color:#888;margin-bottom:8px;font-weight:600;">معاينة صف الجدول:</div>
                                    <div style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                                        <div style="flex:1;font-size:0.82rem;color:#333;">محمد أحمد علي</div>
                                        <div style="font-size:0.82rem;color:#333;">2026-06-20</div>
                                        <div style="display:flex;gap:4px;">
                                            <span class="action-preview-btn" id="prevBtnView" style="width:30px;height:30px;border-radius:7px;background:<?= htmlspecialchars($cur['btn_view'] ?? '#17a2b8') ?>;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:0.75rem;cursor:default;" title="عرض"><i class="fas fa-eye"></i></span>
                                            <span class="action-preview-btn" id="prevBtnEdit" style="width:30px;height:30px;border-radius:7px;background:<?= htmlspecialchars($cur['btn_edit'] ?? '#e0a800') ?>;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:0.75rem;cursor:default;" title="تعديل"><i class="fas fa-edit"></i></span>
                                            <span class="action-preview-btn" id="prevBtnDelete" style="width:30px;height:30px;border-radius:7px;background:<?= htmlspecialchars($cur['btn_delete'] ?? '#dc3545') ?>;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:0.75rem;cursor:default;" title="حذف"><i class="fas fa-trash"></i></span>
                                            <span class="action-preview-btn" id="prevBtnArchive" style="width:30px;height:30px;border-radius:7px;background:<?= htmlspecialchars($cur['btn_archive'] ?? '#6c757d') ?>;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:0.75rem;cursor:default;" title="أرشفة"><i class="fas fa-archive"></i></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <?php
                                    $actionBtns = [
                                        ['id'=>'cpBtnView',    'fid'=>'fBtnView',    'val'=>$cur['btn_view']    ?? '#17a2b8', 'label'=>'عرض',    'icon'=>'fa-eye',     'prev'=>'prevBtnView'],
                                        ['id'=>'cpBtnEdit',    'fid'=>'fBtnEdit',    'val'=>$cur['btn_edit']    ?? '#e0a800', 'label'=>'تعديل',  'icon'=>'fa-edit',    'prev'=>'prevBtnEdit'],
                                        ['id'=>'cpBtnDelete',  'fid'=>'fBtnDelete',  'val'=>$cur['btn_delete']  ?? '#dc3545', 'label'=>'حذف',    'icon'=>'fa-trash',   'prev'=>'prevBtnDelete'],
                                        ['id'=>'cpBtnArchive', 'fid'=>'fBtnArchive', 'val'=>$cur['btn_archive'] ?? '#6c757d', 'label'=>'أرشفة',  'icon'=>'fa-archive', 'prev'=>'prevBtnArchive'],
                                    ];
                                    foreach ($actionBtns as $ab): ?>
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="color-picker-wrap text-center" style="cursor:pointer;" onclick="document.getElementById('<?= $ab['id'] ?>').click()">
                                            <!-- زر معاينة -->
                                            <div style="width:44px;height:44px;border-radius:10px;background:<?= htmlspecialchars($ab['val']) ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;box-shadow:0 3px 10px rgba(0,0,0,0.18);cursor:pointer;" id="prevAction_<?= $ab['id'] ?>">
                                                <i class="fas <?= $ab['icon'] ?>" style="color:#fff;font-size:1rem;"></i>
                                            </div>
                                            <input type="color"
                                                   id="<?= $ab['id'] ?>"
                                                   value="<?= htmlspecialchars($ab['val']) ?>"
                                                   data-fid="<?= $ab['fid'] ?>"
                                                   data-row-prev="<?= $ab['prev'] ?>"
                                                   data-icon-prev="prevAction_<?= $ab['id'] ?>"
                                                   class="action-btn-color-picker"
                                                   style="-webkit-appearance:none;width:40px;height:40px;border:none;border-radius:10px;cursor:pointer;padding:2px;background:transparent;">
                                            <div class="color-label" style="font-size:0.78rem;font-weight:700;margin-top:4px;"><?= $ab['label'] ?></div>
                                            <div class="color-hex" id="hexAction_<?= $ab['id'] ?>" style="font-size:0.7rem;color:#999;background:#f8f9fa;border-radius:20px;padding:2px 8px;margin-top:3px;"><?= htmlspecialchars($ab['val']) ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <hr class="my-4">

                                <!-- ── الشريط السفلي ── -->
                                <div class="section-label"><i class="fas fa-window-minimize ml-2 text-dark"></i> الشريط السفلي (Footer)</div>
                                <div class="row align-items-center">
                                    <!-- معاينة -->
                                    <div class="col-md-6 mb-3">
                                        <div id="footerPreview" style="padding:10px 18px;border-radius:8px;background:<?= htmlspecialchars($cur['footer_bg']) ?>;color:<?= htmlspecialchars($cur['footer_text']) ?>;font-size:0.78rem;font-weight:500;display:flex;align-items:center;gap:8px;">
                                            <i class="fas fa-copyright" style="opacity:0.7;"></i>
                                            <span>2025 UltimateSolutions CRM — جميع الحقوق محفوظة</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="color-picker-wrap">
                                            <input type="color" id="cpFooterBg" value="<?= htmlspecialchars($cur['footer_bg']) ?>">
                                            <div class="color-label">خلفية الشريط</div>
                                            <div class="color-hex" id="hexFooterBg"><?= htmlspecialchars($cur['footer_bg']) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="color-picker-wrap">
                                            <input type="color" id="cpFooterText" value="<?= htmlspecialchars($cur['footer_text']) ?>">
                                            <div class="color-label">لون النص</div>
                                            <div class="color-hex" id="hexFooterText"><?= htmlspecialchars($cur['footer_text']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- /تاب العناصر -->

                            <!-- ═══ تاب 7: متقدم ═══ -->
                            <div class="tab-pane fade" id="tab-advanced">

                                <!-- ══ الشريط العلوي ══ -->
                                <div class="section-label"><i class="fas fa-bars ml-2 text-primary"></i> الشريط العلوي (Top Navigation Bar)</div>
                                <div class="row mb-3">
                                    <!-- إخفاء/إظهار الشريط -->
                                    <div class="col-md-6 mb-3">
                                        <div class="card p-3 h-100" style="border:2px solid #e9ecef !important;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div>
                                                    <strong><i class="fas fa-eye ml-1"></i> إظهار الشريط العلوي</strong>
                                                    <div class="text-muted" style="font-size:0.8rem;margin-top:3px;">تحكم في ظهور شريط التنقل العلوي في جميع الصفحات</div>
                                                </div>
                                                <label style="position:relative;display:inline-block;width:52px;height:26px;flex-shrink:0;cursor:pointer;">
                                                    <input type="checkbox" id="topbarVisibleToggle" <?= $cur['topbar_visible'] ? 'checked' : '' ?>>
                                                    <span id="topbarVisibleSpan" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:<?= $cur['topbar_visible'] ? 'var(--crm-primary,#1a5276)' : '#ccc' ?>;border-radius:34px;transition:.3s;">
                                                        <span style="position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s;transform:<?= $cur['topbar_visible'] ? 'translateX(26px)' : 'translateX(0)' ?>;"></span>
                                                    </span>
                                                </label>
                                            </div>
                                            <!-- معاينة -->
                                            <div class="mt-3 p-2 rounded text-center" style="background:#f8f9fa;font-size:0.75rem;color:#888;">
                                                <div id="topbarPreviewBox" style="height:22px;background:<?= $cur['header'] ?? '#fff' ?>;border-radius:4px;margin-bottom:4px;display:flex;align-items:center;padding:0 8px;gap:6px;<?= !$cur['topbar_visible'] ? 'opacity:0.2;' : '' ?>">
                                                    <div style="width:16px;height:8px;background:#ddd;border-radius:2px;"></div>
                                                    <div style="font-size:0.55rem;color:#555;flex:1;">شريط التنقل العلوي</div>
                                                    <div style="width:14px;height:14px;border-radius:50%;background:#eee;"></div>
                                                </div>
                                                <span id="topbarVisibleLabel"><?= $cur['topbar_visible'] ? '🟢 ظاهر' : '🔴 مخفي' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- ظل الشريط -->
                                    <div class="col-md-6 mb-3">
                                        <div class="card p-3 h-100" style="border:2px solid #e9ecef !important;">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div>
                                                    <strong><i class="fas fa-layer-group ml-1"></i> ظل الشريط العلوي</strong>
                                                    <div class="text-muted" style="font-size:0.8rem;margin-top:3px;">إضافة أو إزالة الظل أسفل شريط التنقل</div>
                                                </div>
                                                <label style="position:relative;display:inline-block;width:52px;height:26px;flex-shrink:0;cursor:pointer;">
                                                    <input type="checkbox" id="topbarShadowToggle" <?= $cur['topbar_shadow'] ? 'checked' : '' ?>>
                                                    <span id="topbarShadowSpan" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:<?= $cur['topbar_shadow'] ? 'var(--crm-primary,#1a5276)' : '#ccc' ?>;border-radius:34px;transition:.3s;">
                                                        <span style="position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s;transform:<?= $cur['topbar_shadow'] ? 'translateX(26px)' : 'translateX(0)' ?>;"></span>
                                                    </span>
                                                </label>
                                            </div>
                                            <div class="mt-3 p-2 rounded text-center" style="background:#f8f9fa;font-size:0.75rem;color:#888;">
                                                <div style="height:22px;background:#fff;border-radius:4px;<?= $cur['topbar_shadow'] ? 'box-shadow:0 2px 8px rgba(0,0,0,0.15);' : '' ?>margin-bottom:4px;"></div>
                                                <span><?= $cur['topbar_shadow'] ? '🟢 ظل مفعّل' : '⚪ بدون ظل' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- تلميح لون الشريط العلوي -->
                                <div class="alert alert-info py-2 px-3 mb-4" style="border-radius:10px;font-size:0.82rem;">
                                    <i class="fas fa-info-circle ml-1"></i>
                                    لتغيير <strong>لون الشريط العلوي</strong> انتقل إلى تاب
                                    <a href="#tab-colors" data-toggle="tab" class="font-weight-bold">"الألوان"</a>
                                    ← <strong>"لون شريط العنوان"</strong>.
                                    &nbsp;|&nbsp;
                                    الشريط الداكن الحالي ناتج عن تفعيل <strong>الوضع الداكن</strong> أدناه.
                                </div>

                                <hr class="my-3">

                                <!-- الوضع الداكن -->
                                <div class="section-label"><i class="fas fa-moon ml-2 text-primary"></i> الوضع الداكن (Dark Mode)</div>
                                <div class="card p-3 mb-4" style="border:2px solid #e9ecef !important;">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <strong>تفعيل الوضع الداكن</strong>
                                            <div class="text-muted" style="font-size:0.82rem;">يحول جميع خلفيات الصفحات إلى ألوان داكنة</div>
                                        </div>
                                        <label class="switch" style="position:relative;display:inline-block;width:52px;height:26px;flex-shrink:0;">
                                            <input type="checkbox" id="darkModeToggle" name="dark_mode" value="1" <?= $cur['dark_mode'] ? 'checked' : '' ?>>
                                            <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:34px;transition:.3s;">
                                                <span style="position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s;"></span>
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <!-- معاينة الأيقونات -->
                                <div class="section-label"><i class="fas fa-icons ml-2 text-success"></i> أيقونات النظام</div>
                                <div class="row text-center mb-3">
                                    <?php
                                    $icons = ['fas fa-home','fas fa-users','fas fa-file-alt','fas fa-chart-bar','fas fa-cog','fas fa-bell','fas fa-envelope','fas fa-search','fas fa-edit','fas fa-trash','fas fa-plus','fas fa-download'];
                                    foreach ($icons as $ic): ?>
                                    <div class="col-2 mb-3">
                                        <div style="width:44px;height:44px;border-radius:10px;background:rgba(var(--crm-primary-rgb,26,82,118),0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 4px;color:var(--crm-primary,#1a5276);font-size:1.1rem;">
                                            <i class="<?= $ic ?>"></i>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- استعادة الافتراضي -->
                                <div class="section-label mt-2"><i class="fas fa-undo ml-2 text-danger"></i> إعادة تعيين</div>
                                <button type="button" class="btn btn-outline-danger" onclick="resetToDefault()">
                                    <i class="fas fa-undo ml-1"></i> إعادة تعيين لإعدادات المصنع
                                </button>
                            </div>

                        </div><!-- /tab-content -->
                    </form>
                </div><!-- /col-8 -->
            </div><!-- /row -->
        </div>
    </section>
</div><!-- /content-wrapper -->

<footer class="main-footer"><?php include(__DIR__ . '/../../main-footer.php'); ?></footer>
</div><!-- /wrapper -->

<!-- شريط الحفظ العائم -->
<div class="float-save-bar hidden" id="floatSave">
    <div class="unsaved-dot"></div>
    <span style="font-size:0.85rem;color:#666;">يوجد تغييرات غير محفوظة</span>
    <button type="button" class="btn btn-primary" id="btnSave">
        <i class="fas fa-save ml-1"></i> حفظ التغييرات
    </button>
    <button type="button" class="btn btn-light btn-sm" id="btnCancel">إلغاء</button>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.all.min.js"></script>
<script>
$(function(){
    var hasChanges = false;

    // ── القيم الابتدائية ──
    var state = {
        primary:    '<?= addslashes($cur['primary']) ?>',
        accent:     '<?= addslashes($cur['accent']) ?>',
        sidebar:    '<?= addslashes($cur['sidebar']) ?>',
        header:     '<?= addslashes($cur['header']) ?>',
        font:       '<?= addslashes($cur['font']) ?>',
        btnRadius:  '<?= addslashes($cur['btn_radius']) ?>',
        btnStyle:   '<?= addslashes($cur['btn_style']) ?>',
        tableStyle: '<?= addslashes($cur['table_style']) ?>',
        cardRadius: '<?= addslashes($cur['card_radius']) ?>',
        preset:     '<?= addslashes($cur['preset']) ?>',
        darkMode:   <?= $cur['dark_mode'] ? 'true' : 'false' ?>
    };

    // ── المعاينة الحية ──
    function applyPreview(s) {
        var p = s.primary, a = s.accent, sb = s.sidebar, hd = s.header;
        // هيدر
        $('#lpHeader').css('background', hd);
        var isDark = isColorDark(hd);
        $('#lpLogo').css('color', isDark ? '#fff' : '#333');
        // شريط جانبي
        $('#lpSidebar').css('background', sb);
        $('#lpSideActive').css({'background': 'rgba(255,255,255,0.15)', 'border-right': '3px solid rgba(255,255,255,0.8)'});
        // أزرار التصدير
        $('#lpExportBtn').css('background', a);
        // بطاقة
        $('#lpCard').css({'border-radius': s.cardRadius});
        // رؤوس الجدول
        $('#lpTh,#lpTh2,#lpTh3,#lpTh4').css('border-bottom', '2px solid '+p);
        // أزرار
        $('#lpBtn1').css('background', 'linear-gradient(135deg,'+darkenColor(p)+','+a+')');
        $('#lpActionBtn1,#lpActionBtn2').css('background', a);
        // بطاقة stat
        $('#lpStatCard1').css('background', 'linear-gradient(135deg,'+darkenColor(p)+','+p+')');
        $('#lpStatIcon').css('background', 'rgba(255,255,255,0.2)');
        // دوائر الألوان
        $('#curPrimaryDot').css('background', p);
        $('#curAccentDot').css('background', a);
        $('#curSidebarDot').css('background', sb);
        $('#curHeaderDot').css('background', hd);
        // تحديث CSS Variables على الصفحة
        document.documentElement.style.setProperty('--crm-primary', p);
        document.documentElement.style.setProperty('--crm-primary-light', a);
        document.documentElement.style.setProperty('--crm-sidebar-bg', sb);
        document.documentElement.style.setProperty('--crm-header-bg', hd);
        document.documentElement.style.setProperty('--crm-btn-radius', s.btnRadius);
        document.documentElement.style.setProperty('--crm-card-radius', s.cardRadius);
        // تحديث الوصف
        $('#curFontLabel').text(s.font);
        $('#curStyleLabel').text(s.btnStyle);
    }

    function isColorDark(hex) {
        hex = hex.replace('#','');
        if (hex.length < 6) return false;
        var r=parseInt(hex.substr(0,2),16), g=parseInt(hex.substr(2,2),16), b=parseInt(hex.substr(4,2),16);
        return ((r*299+g*587+b*114)/1000) < 128;
    }
    function darkenColor(hex) {
        hex = hex.replace('#','');
        var r=Math.max(0,parseInt(hex.substr(0,2),16)-40);
        var g=Math.max(0,parseInt(hex.substr(2,2),16)-40);
        var b=Math.max(0,parseInt(hex.substr(4,2),16)-40);
        return '#'+[r,g,b].map(x=>x.toString(16).padStart(2,'0')).join('');
    }

    function markChanged() {
        hasChanges = true;
        $('#floatSave').removeClass('hidden');
    }

    // ── الثيمات الجاهزة ──
    $(document).on('click', '.preset-card', function(){
        var $el = $(this);
        $('.preset-card').removeClass('active');
        $el.addClass('active');
        var p = $el.data();
        state.primary = p.primary;
        state.accent  = p.accent;
        state.sidebar = p.sidebar;
        state.header  = p.header;
        state.preset  = p.preset;
        // تحديث color pickers
        $('#cpPrimary').val(p.primary); $('#cpPrimaryHex').text(p.primary);
        $('#cpAccent').val(p.accent);   $('#cpAccentHex').text(p.accent);
        $('#cpSidebar').val(p.sidebar); $('#cpSidebarHex').text(p.sidebar);
        $('#cpHeader').val(p.header);   $('#cpHeaderHex').text(p.header);
        // تحديث الـ hidden fields
        $('#fPreset').val(p.preset); $('#fPrimary').val(p.primary);
        $('#fAccent').val(p.accent); $('#fSidebar').val(p.sidebar); $('#fHeader').val(p.header);
        applyPreview(state);
        markChanged();
    });

    // ── Color Pickers ──
    function bindColorPicker(inputId, hexId, stateKey, hiddenId) {
        $(inputId).on('input change', function(){
            var v = $(this).val();
            state[stateKey] = v;
            $(hexId).text(v);
            $(hiddenId).val(v);
            state.preset = 'custom';
            $('#fPreset').val('custom');
            $('.preset-card').removeClass('active');
            applyPreview(state);
            markChanged();
        });
    }
    bindColorPicker('#cpPrimary','#cpPrimaryHex','primary','#fPrimary');
    bindColorPicker('#cpAccent','#cpAccentHex','accent','#fAccent');
    bindColorPicker('#cpSidebar','#cpSidebarHex','sidebar','#fSidebar');
    bindColorPicker('#cpHeader','#cpHeaderHex','header','#fHeader');

    // ── ألوان سريعة ──
    $(document).on('click', '.quick-color', function(){
        var c = $(this).data('color');
        state.primary = c;
        $('#cpPrimary').val(c); $('#cpPrimaryHex').text(c); $('#fPrimary').val(c);
        // حساب اللون الفاتح تلقائياً
        var lighter = lightenColor(c, 40);
        state.accent = lighter;
        $('#cpAccent').val(lighter); $('#cpAccentHex').text(lighter); $('#fAccent').val(lighter);
        state.preset = 'custom'; $('#fPreset').val('custom');
        $('.preset-card').removeClass('active');
        applyPreview(state);
        markChanged();
    });
    function lightenColor(hex, amt) {
        hex = hex.replace('#','');
        var r=Math.min(255,parseInt(hex.substr(0,2),16)+amt);
        var g=Math.min(255,parseInt(hex.substr(2,2),16)+amt);
        var b=Math.min(255,parseInt(hex.substr(4,2),16)+amt);
        return '#'+[r,g,b].map(x=>x.toString(16).padStart(2,'0')).join('');
    }

    // ── الخطوط ──
    $('[name="font_radio"]').on('change', function(){
        state.font = $(this).val();
        $('#fFont').val(state.font);
        $('.font-card').removeClass('active');
        $(this).closest('.font-card').addClass('active');
        $('#curFontLabel').text(state.font);
        applyPreview(state);
        markChanged();
    });

    // ── نمط الأزرار ──
    $('[name="btn_style_radio"]').on('change', function(){
        state.btnStyle = $(this).val();
        $('#fBtnStyle').val(state.btnStyle);
        $('[name="btn_style_radio"]').each(function(){ $(this).closest('.style-option').removeClass('active'); });
        $(this).closest('.style-option').addClass('active');
        $('#curStyleLabel').text(state.btnStyle);
        markChanged();
    });

    // ── نصف قطر الأزرار ──
    $('[name="btn_radius_radio"]').on('change', function(){
        state.btnRadius = $(this).val();
        $('#fBtnRadius').val(state.btnRadius);
        $('[name="btn_radius_radio"]').each(function(){ $(this).closest('.style-option').removeClass('active'); });
        $(this).closest('.style-option').addClass('active');
        applyPreview(state);
        markChanged();
    });

    // ── نصف قطر البطاقات ──
    $('[name="card_radius_radio"]').on('change', function(){
        state.cardRadius = $(this).val();
        $('#fCardRadius').val(state.cardRadius);
        $('[name="card_radius_radio"]').each(function(){ $(this).closest('.style-option').removeClass('active'); });
        $(this).closest('.style-option').addClass('active');
        applyPreview(state);
        markChanged();
    });

    // ── نمط الجداول ──
    $('[name="table_style_radio"]').on('change', function(){
        state.tableStyle = $(this).val();
        $('#fTableStyle').val(state.tableStyle);
        $('[name="table_style_radio"]').each(function(){ $(this).closest('.style-option').removeClass('active'); });
        $(this).closest('.style-option').addClass('active');
        markChanged();
    });

    // ── الوضع الداكن ──
    $('#darkModeToggle').on('change', function(){
        state.darkMode = $(this).is(':checked');
        applyToggleStyle($(this).is(':checked'));
        markChanged();
    });

    // ── الشريط العلوي: إخفاء/إظهار ──
    $('#topbarVisibleToggle').on('change', function(){
        var v = $(this).is(':checked');
        $('#fTopbarVisible').val(v ? '1' : '0');
        // تحريك المفتاح
        var $span = $('#topbarVisibleSpan');
        $span.css('background', v ? 'var(--crm-primary,#1a5276)' : '#ccc');
        $span.find('span').css('transform', v ? 'translateX(26px)' : 'translateX(0)');
        // تحديث المعاينة
        $('#topbarPreviewBox').css('opacity', v ? '1' : '0.2');
        $('#topbarVisibleLabel').text(v ? '🟢 ظاهر' : '🔴 مخفي');
        // تطبيق مباشر على الصفحة للمعاينة
        if (v) {
            $('.main-header').show();
            $('body').removeClass('topbar-hidden');
        } else {
            $('body').addClass('topbar-hidden');
        }
        markChanged();
    });

    // ── الشريط العلوي: ظل ──
    $('#topbarShadowToggle').on('change', function(){
        var v = $(this).is(':checked');
        $('#fTopbarShadow').val(v ? '1' : '0');
        var $span = $('#topbarShadowSpan');
        $span.css('background', v ? 'var(--crm-primary,#1a5276)' : '#ccc');
        $span.find('span').css('transform', v ? 'translateX(26px)' : 'translateX(0)');
        if (v) $('body').removeClass('topbar-no-shadow');
        else   $('body').addClass('topbar-no-shadow');
        markChanged();
    });

    // ══════════════════════════════════════
    // ── العناصر: شريط عنوان الصفحة ──
    // ══════════════════════════════════════
    $('[name="page_bar_style_radio"]').on('change', function(){
        var v = $(this).val();
        $('#fPageBarStyle').val(v);
        $('[name="page_bar_style_radio"]').each(function(){ $(this).closest('.style-option').removeClass('active'); });
        $(this).closest('.style-option').addClass('active');
        // تحديث المعاينة الحية
        updatePageBarPreview(v, $('#fPageBarRadius').val());
        markChanged();
    });

    $('[name="page_bar_radius_radio"]').on('change', function(){
        var v = $(this).val();
        $('#fPageBarRadius').val(v);
        $('[name="page_bar_radius_radio"]').each(function(){ $(this).closest('.style-option').removeClass('active'); });
        $(this).closest('.style-option').addClass('active');
        updatePageBarPreview($('#fPageBarStyle').val(), v);
        markChanged();
    });

    function updatePageBarPreview(style, radius) {
        var p = state.primary, a = state.accent;
        var bg, color = '#fff', border = 'none';
        if (style === 'gradient')    { bg = 'linear-gradient(135deg,'+darkenColor(p)+','+a+')'; }
        else if (style === 'solid')  { bg = p; }
        else if (style === 'flat')   { bg = 'linear-gradient(135deg,'+darkenColor(p)+','+a+')'; radius = '0px'; }
        else if (style === 'glass')  { bg = 'rgba(26,82,118,0.12)'; color = p; border = '1px solid rgba(26,82,118,0.3)'; }
        else if (style === 'dark')   { bg = 'linear-gradient(135deg,#0d1117,#1c2128)'; }
        else { bg = 'transparent'; color = p; border = '2px solid rgba(26,82,118,0.2)'; }
        // معاينة مصغرة في panel
        document.documentElement.style.setProperty('--crm-page-bar-from', darkenColor(p));
        document.documentElement.style.setProperty('--crm-page-bar-to', a);
        document.documentElement.style.setProperty('--crm-page-bar-radius', radius);
    }

    // ── أزرار التصدير ──
    $('.export-btn-color-picker').on('input change', function(){
        var v = $(this).val();
        var fid = $(this).data('fid');
        var prev = $(this).data('prev');
        $('#'+fid).val(v);
        $('#'+prev).css('background', v);
        $('#hex_'+$(this).attr('id')).text(v);
        markChanged();
    });

    // ── أزرار الإجراءات ──
    $('.action-btn-color-picker').on('input change', function(){
        var v    = $(this).val();
        var fid  = $(this).data('fid');
        var rowP = $(this).data('row-prev');    // زر في معاينة الصف
        var icnP = $(this).data('icon-prev');   // مربع الأيقونة
        $('#'+fid).val(v);
        if (rowP) $('#'+rowP).css('background', v);
        if (icnP) $('#'+icnP).css('background', v);
        $('#hexAction_'+$(this).attr('id')).text(v);
        // تحديث CSS variable مباشرة للمعاينة الحية
        var varMap = {
            'cpBtnView':    '--crm-btn-view-bg',
            'cpBtnEdit':    '--crm-btn-edit-bg',
            'cpBtnDelete':  '--crm-btn-delete-bg',
            'cpBtnArchive': '--crm-btn-archive-bg'
        };
        var cssVar = varMap[$(this).attr('id')];
        if (cssVar) document.documentElement.style.setProperty(cssVar, v);
        markChanged();
    });

    // ── الشريط السفلي ──
    $('#cpFooterBg').on('input change', function(){
        var v = $(this).val();
        $('#fFooterBg').val(v);
        $('#hexFooterBg').text(v);
        $('#footerPreview').css('background', v);
        markChanged();
    });
    $('#cpFooterText').on('input change', function(){
        var v = $(this).val();
        $('#fFooterText').val(v);
        $('#hexFooterText').text(v);
        $('#footerPreview').css('color', v);
        markChanged();
    });

    // ── دالة تحريك مفتاح الوضع الداكن (منفصلة عن markChanged) ──
    function applyToggleStyle(checked) {
        var $span = $('#darkModeToggle').siblings('span');
        if (checked) {
            $span.css('background', '#1a5276');
            $span.find('span').css('transform', 'translateX(26px)');
        } else {
            $span.css('background', '#ccc');
            $span.find('span').css('transform', 'translateX(0)');
        }
    }
    // تطبيق الحالة الأولية بدون تشغيل الحدث
    applyToggleStyle($('#darkModeToggle').is(':checked'));

    // ── متغير للتنقل المقصود (يمنع beforeunload) ──
    var isLeavingIntentionally = false;

    // ── حفظ ──
    $('#btnSave').on('click', function(){
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin ml-1"></i> جارٍ الحفظ...');
        $.ajax({
            url: window.location.href,
            method: 'POST',
            dataType: 'json',
            data: $('#themeForm').serialize()
                  .replace(/&?dark_mode=[^&]*/g, '')          // إزالة أي dark_mode من serialize
                  + '&dark_mode=' + (state.darkMode ? '1' : '0')   // إضافة القيمة الصحيحة مرة واحدة
                  + '&topbar_visible=' + ($('#fTopbarVisible').val() === '1' ? '1' : '0')
                  + '&topbar_shadow='  + ($('#fTopbarShadow').val()  === '1' ? '1' : '0'),
            success: function(resp){
                btn.prop('disabled', false).html('<i class="fas fa-save ml-1"></i> حفظ التغييرات');
                if (resp.success) {
                    hasChanges = false;
                    isLeavingIntentionally = true;
                    $('#floatSave').addClass('hidden');
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ!',
                        text: resp.message,
                        timer: 2000,
                        showConfirmButton: false,
                        timerProgressBar: true
                    }).then(function(){ location.reload(); });
                } else {
                    Swal.fire({ icon:'error', title:'خطأ', text:resp.message });
                }
            },
            error: function(){
                btn.prop('disabled', false).html('<i class="fas fa-save ml-1"></i> حفظ التغييرات');
                Swal.fire({ icon:'error', title:'خطأ', text:'حدث خطأ في الاتصال. حاول مجدداً.' });
            }
        });
    });

    // ══ حفظ معلومات النظام ══
    // معاينة فورية للتذييل
    function updateFooterPreview() {
        $('#fp_name').text($('#si_name').val() || 'النظام');
        $('#fp_footer').text($('#si_footer').val());
        $('#fp_name_en').text($('#si_name_en').val());
    }
    $('#si_name, #si_name_en, #si_footer, #si_tagline').on('input', updateFooterPreview);

    $('#btnSaveSysInfo').on('click', function() {
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin ml-1"></i> جاري الحفظ...');
        $.ajax({
            url: window.location.href,
            method: 'POST',
            dataType: 'json',
            data: {
                save_sysinfo:    1,
                system_name:     $('#si_name').val(),
                system_name_en:  $('#si_name_en').val(),
                site_tagline:    $('#si_tagline').val(),
                footer_tagline:  $('#si_footer').val(),
                admin_email:     $('#si_email').val(),
                contact_number:  $('#si_phone').val()
            },
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-save ml-2"></i>حفظ معلومات النظام');
                if (resp.success) {
                    Swal.fire({ icon:'success', title:'تم الحفظ!', text:resp.message, timer:2000, showConfirmButton:false, timerProgressBar:true });
                } else {
                    Swal.fire({ icon:'error', title:'خطأ', text:resp.message });
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-save ml-2"></i>حفظ معلومات النظام');
                Swal.fire({ icon:'error', title:'خطأ', text:'حدث خطأ في الاتصال.' });
            }
        });
    });

    // ── إلغاء ──
    $('#btnCancel').on('click', function(){
        if (!hasChanges) {
            isLeavingIntentionally = true;
            location.reload();
            return;
        }
        Swal.fire({
            title: 'تجاهل التغييرات؟',
            text: 'لم يتم حفظ التغييرات. هل تريد تجاهلها والرجوع للقيم السابقة؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، تجاهل',
            cancelButtonText: 'رجوع للتعديل',
            confirmButtonColor: '#6c757d',
            cancelButtonColor: '#1a5276'
        }).then(function(r){
            if (r.isConfirmed) {
                isLeavingIntentionally = true;
                location.reload();
            }
        });
    });

    // ── إعادة تعيين ──
    window.resetToDefault = function(){
        Swal.fire({
            title: 'إعادة تعيين الثيم',
            text: 'سيتم استعادة الثيم الافتراضي (الأزرق الكلاسيكي). هل أنت متأكد؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، أعد التعيين',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        }).then(function(r){
            if (r.isConfirmed) {
                $('.preset-card[data-preset="classic-blue"]').trigger('click');
                setTimeout(function(){ $('#btnSave').trigger('click'); }, 300);
            }
        });
    };

    // ── تحذير عند مغادرة الصفحة مع تغييرات غير محفوظة ──
    window.addEventListener('beforeunload', function(e){
        if (hasChanges && !isLeavingIntentionally) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ── تهيئة أولية ──
    applyPreview(state);

    // quick-color hover effect
    $(document).on('mouseenter','.quick-color',function(){ $(this).css('transform','scale(1.2)'); });
    $(document).on('mouseleave','.quick-color',function(){ $(this).css('transform','scale(1)'); });
});
</script>
</body>
</html>
