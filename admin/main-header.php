<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. جلب البيانات من الجلسة
$userName = $_SESSION['full_name'] ?? 'مستخدم';
$userImg  = $_SESSION['file_path'] ?? '';

// 2. إعداد المسارات بشكل مرن
// استخدم مسارات نسبية داخل الموقع لضمان عمل الروابط
$web_base = (isset($_SERVER['SERVER_NAME']) && str_ends_with($_SERVER['SERVER_NAME'], '.wuaze.com'))
    ? "/admin/"
    : "/UltimatesolutionsCrm/admin/";
$webUploadsDir = "/uploads/";

// 3. التحقق من الصورة باستخدام المسار الفيزيائي للسيرفر
// $_SERVER['DOCUMENT_ROOT'] هو الحل الأفضل في الاستضافات الحية
$serverUploadsDir = $_SERVER['DOCUMENT_ROOT'] . $webUploadsDir;

if (!empty($userImg) && file_exists($serverUploadsDir . $userImg)) {
    $fullImagePath = $webUploadsDir . $userImg;
} else {
    // تأكد أن هذا المسار صحيح في مدير الملفات لديك
    $fullImagePath = $web_base . "dist/img/avatar5.png";
}

// 4. جلب إعدادات المظهر الكاملة
try {
    $stmt_v = $pdo->query("SELECT * FROM sys_theme LIMIT 1");
    $visuals = $stmt_v->fetch();
} catch (Exception $e) {
    $visuals = false;
}

if (!$visuals) {
    $visuals = [
        'system_font'   => 'Cairo',
        'header_color'  => '#ffffff',
        'sidebar_color' => '#2c3e50',
        'primary_color' => '#1a5276',
        'accent_color'  => '#2980b9',
        'btn_radius'    => '8px',
        'btn_style'     => 'gradient',
        'table_style'   => 'default',
        'card_radius'   => '14px',
        'dark_mode'     => 0,
    ];
}

$header_bg_color = $visuals['header_color'] ?? '#ffffff';
$system_font     = $visuals['system_font']  ?? 'Cairo';

// حساب قيم CSS Variables المشتقة
$crm_primary      = $visuals['primary_color']  ?? '#1a5276';
$crm_accent       = $visuals['accent_color']   ?? '#2980b9';
$crm_sidebar      = $visuals['sidebar_color']  ?? '#2c3e50';
$crm_btn_radius   = $visuals['btn_radius']     ?? '8px';
$crm_btn_style    = $visuals['btn_style']      ?? 'gradient';
$crm_table_style  = $visuals['table_style']    ?? 'default';
$crm_card_radius  = $visuals['card_radius']    ?? '14px';
$crm_dark_mode    = !empty($visuals['dark_mode']);
/* شريط عنوان الصفحة */
$crm_page_bar_style   = $visuals['page_bar_style']  ?? 'gradient';
$crm_page_bar_radius  = $visuals['page_bar_radius'] ?? '14px';
/* أزرار التصدير */
$crm_btn_print    = $visuals['btn_print_color']  ?? '#5a6268';
$crm_btn_pdf      = $visuals['btn_pdf_color']    ?? '#c82333';
$crm_btn_excel    = $visuals['btn_excel_color']  ?? '#1e7e34';
$crm_btn_colvis   = $visuals['btn_colvis_color'] ?? '#0062cc';
$crm_btn_add      = $visuals['btn_add_color']    ?? '#1e7e34';
/* الشريط السفلي */
$crm_footer_bg    = $visuals['footer_bg']        ?? '#1e272e';
$crm_footer_text  = $visuals['footer_text']      ?? '#adb5bd';
/* أزرار الإجراءات */
$crm_btn_view     = $visuals['btn_view_color']   ?? '#17a2b8';
$crm_btn_edit     = $visuals['btn_edit_color']   ?? '#e0a800';
$crm_btn_delete   = $visuals['btn_delete_color'] ?? '#dc3545';
$crm_btn_archive  = $visuals['btn_archive_color'] ?? '#6c757d';
/* الشريط العلوي */
$crm_topbar_visible = !isset($visuals['topbar_visible']) || $visuals['topbar_visible'] != 0;
$crm_topbar_shadow  = !isset($visuals['topbar_shadow'])  || $visuals['topbar_shadow']  != 0;

// استخراج RGB للون الرئيسي (لاستخدامه في rgba())
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) < 6) return '26, 82, 118';
        return implode(', ', [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))]);
    }
}
if (!function_exists('darkenHex')) {
    function darkenHex($hex, $amount = 30) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) < 6) return '#154360';
        $r = max(0, hexdec(substr($hex,0,2)) - $amount);
        $g = max(0, hexdec(substr($hex,2,2)) - $amount);
        $b = max(0, hexdec(substr($hex,4,2)) - $amount);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
$crm_primary_rgb  = hexToRgb($crm_primary);
$crm_primary_dark = darkenHex($crm_primary, 25);

// احتساب سطوع اللون — إذا كان فاتحاً جداً نستخدم لون الشريط الجانبي كبديل لترويسة الصفحات
if (!function_exists('colorLuminance')) {
    function colorLuminance($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) < 6) return 0;
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
        return ($r * 299 + $g * 587 + $b * 114) / 1000;
    }
}
// إذا كان اللون الرئيسي فاتحاً (>160) نستخدم لون الشريط الجانبي لترويسة الصفحات
$_page_bar_base = (colorLuminance($crm_primary) > 160) ? $crm_sidebar : $crm_primary;
$_page_bar_dark = darkenHex($_page_bar_base, 20);
$_page_bar_to   = (colorLuminance($crm_accent) > 160)  ? $crm_sidebar : $crm_accent;

// دالة بسيطة لتحديد لون النص (أبيض أو أسود) بناءً على خلفية الهيدر
function getContrastColor($hexColor)
{
    $hexColor = str_replace('#', '', $hexColor);
    if (strlen($hexColor) < 6) return 'navbar-light';
    $r = hexdec(substr($hexColor, 0, 2));
    $g = hexdec(substr($hexColor, 2, 2));
    $b = hexdec(substr($hexColor, 4, 2));
    // معادلة السطوع
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return ($yiq >= 128) ? 'navbar-light' : 'navbar-dark';
}

$header_text_class = getContrastColor($header_bg_color);

// / جلب عدد البلاغات غير المكتملة (أو الجديدة)
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE status = 'pending'");
$stmt_count->execute();
$total_requests = $stmt_count->fetchColumn();

// جلب آخر 5 بلاغات لعرضها في القائمة المنسدلة
$stmt_list = $pdo->prepare("SELECT id, created_at,priority FROM tickets ORDER BY created_at DESC LIMIT 5");
$stmt_list->execute();
$recent_requests = $stmt_list->fetchAll();


$my_id = $_SESSION['user_id'] ?? 0;

// 1. جلب إجمالي عدد الرسائل غير المقروءة (للرقم الذي يظهر فوق الأيقونة)
$stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt_unread->execute([$my_id]);
$unread_total_count = $stmt_unread->fetchColumn();

// 2. جلب آخر رسالة من كل مرسل (منع التكرار) مع حساب عدد رسائل كل واحد
$stmt_msg = $pdo->prepare("
    SELECT m.*, u.full_name, 
           COUNT(m.id) OVER(PARTITION BY m.sender_id) as total_sent_by_user
    FROM messages m
    JOIN sys_users u ON m.sender_id = u.id
    WHERE m.id IN (
        SELECT MAX(id) 
        FROM messages 
        WHERE receiver_id = ? 
        GROUP BY sender_id
    )
    ORDER BY m.created_at DESC 
    LIMIT 5
");
$stmt_msg->execute([$my_id]);
$latest_messages = $stmt_msg->fetchAll();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&family=Cairo:wght@400;600;700;800&family=Tajawal:wght@400;500;700&display=swap');

/* ══ CSS Variables من قاعدة البيانات (يُطبَّق بعد inline styles في <head>) ══ */
:root {
    /* ── نظام CRM الأساسي ── */
    --crm-primary:          <?php echo htmlspecialchars($crm_primary); ?>;
    --crm-primary-rgb:      <?php echo htmlspecialchars($crm_primary_rgb); ?>;
    --crm-primary-light:    <?php echo htmlspecialchars($crm_accent); ?>;
    --crm-primary-dark:     <?php echo htmlspecialchars($crm_primary_dark); ?>;
    --crm-accent:           <?php echo htmlspecialchars($crm_accent); ?>;
    --crm-sidebar-bg:       <?php echo htmlspecialchars($crm_sidebar); ?>;
    --crm-header-bg:        <?php echo htmlspecialchars($header_bg_color); ?>;
    --crm-btn-radius:       <?php echo htmlspecialchars($crm_btn_radius); ?>;
    --crm-card-radius:      <?php echo htmlspecialchars($crm_card_radius); ?>;
    --crm-input-radius:     <?php echo htmlspecialchars($crm_btn_radius); ?>;
    --crm-badge-radius:     20px;
    --crm-font:             '<?php echo htmlspecialchars($system_font); ?>';
    --crm-table-hover:      rgba(<?php echo htmlspecialchars($crm_primary_rgb); ?>, 0.045);
    /* شريط عنوان الصفحة */
    --crm-page-bar-from:    <?php echo htmlspecialchars($_page_bar_dark); ?>;
    --crm-page-bar-to:      <?php echo htmlspecialchars($_page_bar_to); ?>;
    --crm-page-bar-radius:  <?php echo htmlspecialchars($crm_page_bar_radius); ?>;
    --crm-page-bar-shadow:  0 4px 20px rgba(<?php echo htmlspecialchars($crm_primary_rgb); ?>, 0.25);
    /* أزرار التصدير */
    --crm-btn-print-bg:     <?php echo htmlspecialchars($crm_btn_print); ?>;
    --crm-btn-pdf-bg:       <?php echo htmlspecialchars($crm_btn_pdf); ?>;
    --crm-btn-excel-bg:     <?php echo htmlspecialchars($crm_btn_excel); ?>;
    --crm-btn-colvis-bg:    <?php echo htmlspecialchars($crm_btn_colvis); ?>;
    --crm-btn-add-bg:       <?php echo htmlspecialchars($crm_btn_add); ?>;
    /* أزرار الإجراءات */
    --crm-btn-view-bg:      <?php echo htmlspecialchars($crm_btn_view); ?>;
    --crm-btn-edit-bg:      <?php echo htmlspecialchars($crm_btn_edit); ?>;
    --crm-btn-delete-bg:    <?php echo htmlspecialchars($crm_btn_delete); ?>;
    --crm-btn-archive-bg:   <?php echo htmlspecialchars($crm_btn_archive); ?>;
    /* الشريط السفلي */
    --crm-footer-bg:        <?php echo htmlspecialchars($crm_footer_bg); ?>;
    --crm-footer-text:      <?php echo htmlspecialchars($crm_footer_text); ?>;

    /* ══ توحيد متغيرات الصفحات (تتجاوز القيم الثابتة في كل صفحة) ══ */
    /* متغيرات uni-* المستخدمة في جميع الصفحات */
    --uni-primary:          <?php echo htmlspecialchars($crm_primary); ?>;
    --uni-accent:           <?php echo htmlspecialchars($crm_accent); ?>;
    --uni-primary-dark:     <?php echo htmlspecialchars($crm_primary_dark); ?>;
    --uni-primary-rgb:      <?php echo htmlspecialchars($crm_primary_rgb); ?>;
    /* متغيرات bs-* لـ Bootstrap */
    --bs-primary:           <?php echo htmlspecialchars($crm_primary); ?>;
    --bs-primary-rgb:       <?php echo htmlspecialchars($crm_primary_rgb); ?>;
}

body {
    font-family: '<?php echo htmlspecialchars($system_font); ?>', sans-serif !important;
    overflow-x: hidden !important;
    scrollbar-width: none;
    <?php if ($crm_dark_mode): ?>background-color: #0f1117 !important;<?php endif; ?>
}

/* ── ساعة حية ── */
#live-clock {
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 4px 12px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.12);
    display: flex;
    align-items: center;
    gap: 5px;
    min-width: 90px;
    text-align: center;
    cursor: default;
}

#live-clock i {
    font-size: 0.75rem;
}

/* ── الساعة تتكيف مع لون الهيدر ── */
.navbar-dark #live-clock {
    color: rgba(255, 255, 255, 0.9);
}

.navbar-light #live-clock {
    color: #444;
    background: rgba(0, 0, 0, 0.06);
}

/* ── نبض أيقونة الجرس ── */
.navbar-badge.badge-warning {
    animation: pulse-warning 2s infinite;
}

@keyframes pulse-warning {
    0% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7);
    }

    70% {
        transform: scale(1.1);
        box-shadow: 0 0 0 5px rgba(255, 193, 7, 0);
    }

    100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
    }
}

/* ── زر ملء الشاشة ── */
#fullscreen-btn:hover {
    color: #3498db !important;
    transform: scale(1.1);
    transition: all 0.2s ease;
}

/* ── ملء الشاشة بالكامل ── */
.wasl-fullscreen .wrapper,
.wasl-fullscreen .content-wrapper,
.wasl-fullscreen .main-footer {
    margin-left: 0 !important;
    margin-right: 0 !important;
}

.wasl-fullscreen .main-sidebar {
    display: block !important;
}

.wasl-fullscreen .content-wrapper {
    min-height: calc(100vh - 50px) !important;
}

.wasl-fullscreen .content-wrapper>.content {
    padding: 0 15px !important;
}

.wasl-fullscreen>.wrapper {
    overflow-y: auto;
}

/* ── قائمة منسدلة ── */
.dropdown-menu-lg .dropdown-header {
    background: #f8f9fa;
    color: #333;
    font-weight: bold;
    text-align: center;
}

/* ── اسم المستخدم في الهيدر ── */
.header-user-name {
    font-size: 0.82rem;
    font-weight: 600;
    max-width: 130px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: default;
}

.navbar-dark .header-user-name {
    color: rgba(255, 255, 255, 0.85);
}

.navbar-light .header-user-name {
    color: #444;
}
</style>

<!-- ══ ألوان الأزرار المباشرة (CSS + JS) ══ -->
<style>
/* ── أزرار التصدير: قيم مباشرة من PHP ── */
.dt-buttons .buttons-print,
.dt-buttons .btn-outline-primary { background: <?= htmlspecialchars($crm_btn_print) ?> !important; border:none!important; color:#fff!important; }
.dt-buttons .buttons-pdf,
.dt-buttons .btn-outline-danger  { background: <?= htmlspecialchars($crm_btn_pdf) ?>   !important; border:none!important; color:#fff!important; }
.dt-buttons .buttons-excel,
.dt-buttons .btn-outline-success { background: <?= htmlspecialchars($crm_btn_excel) ?> !important; border:none!important; color:#fff!important; }
.dt-buttons .buttons-colvis,
.dt-buttons .btn-outline-info    { background: <?= htmlspecialchars($crm_btn_colvis) ?>!important; border:none!important; color:#fff!important; }
.dt-buttons .buttons-copy,
.dt-buttons .btn-outline-secondary { background: <?= htmlspecialchars($crm_btn_print) ?> !important; border:none!important; color:#fff!important; }
/* ── أزرار الإجراءات: قيم مباشرة من PHP ── */
.btn-action.btn-info,     .btn-group-action .btn-info,
td .btn.btn-info,         .table .btn.btn-info       { background: <?= htmlspecialchars($crm_btn_view) ?>    !important; border:none!important; color:#fff!important; }
.btn-action.btn-warning,  .btn-group-action .btn-warning,
td .btn.btn-warning,      .table .btn.btn-warning    { background: <?= htmlspecialchars($crm_btn_edit) ?>    !important; border:none!important; color:#fff!important; }
.btn-action.btn-danger,   .btn-group-action .btn-danger,
td .btn.btn-danger,       .table .btn.btn-danger     { background: <?= htmlspecialchars($crm_btn_delete) ?>  !important; border:none!important; color:#fff!important; }
.btn-action.btn-secondary,.btn-group-action .btn-secondary,
td .btn.btn-secondary,    .table .btn.btn-secondary  { background: <?= htmlspecialchars($crm_btn_archive) ?> !important; border:none!important; color:#fff!important; }
/* ── btn-add من الإعدادات ── */
td .btn.btn-primary, .btn-group-action .btn-primary  { background: <?= htmlspecialchars($crm_btn_add) ?>    !important; border:none!important; color:#fff!important; }
</style>

<script>
/* ══ تطبيق ألوان الأزرار بعد بناء DataTables ══ */
(function(){
    var COLORS = {
        print:   '<?= addslashes($crm_btn_print) ?>',
        pdf:     '<?= addslashes($crm_btn_pdf) ?>',
        excel:   '<?= addslashes($crm_btn_excel) ?>',
        colvis:  '<?= addslashes($crm_btn_colvis) ?>',
        copy:    '<?= addslashes($crm_btn_print) ?>',
        view:    '<?= addslashes($crm_btn_view) ?>',
        edit:    '<?= addslashes($crm_btn_edit) ?>',
        del:     '<?= addslashes($crm_btn_delete) ?>',
        archive: '<?= addslashes($crm_btn_archive) ?>',
        add:     '<?= addslashes($crm_btn_add) ?>'
    };
    var RADIUS = '<?= addslashes($crm_btn_radius) ?>';

    function applyColor(el, color) {
        el.style.setProperty('background',    color, 'important');
        el.style.setProperty('border',        'none','important');
        el.style.setProperty('color',         '#fff','important');
        el.style.setProperty('border-radius', RADIUS,'important');
    }

    function styleAllButtons() {
        /* أزرار التصدير */
        var map = [
            ['buttons-print',         COLORS.print],
            ['buttons-pdf',           COLORS.pdf],
            ['buttons-excel',         COLORS.excel],
            ['buttons-colvis',        COLORS.colvis],
            ['buttons-copy',          COLORS.copy],
            ['buttons-csv',           COLORS.excel],
            ['btn-outline-primary',   COLORS.print],
            ['btn-outline-danger',    COLORS.pdf],
            ['btn-outline-success',   COLORS.excel],
            ['btn-outline-info',      COLORS.colvis],
            ['btn-outline-secondary', COLORS.copy],
        ];
        document.querySelectorAll('.dt-buttons .dt-button, .dt-buttons .btn').forEach(function(el){
            for (var i=0; i<map.length; i++) {
                if (el.classList.contains(map[i][0])) {
                    applyColor(el, map[i][1]); break;
                }
            }
        });

        /* أزرار الإجراءات في صفوف الجداول */
        document.querySelectorAll('td .btn, .btn-group-action .btn, .btn-action').forEach(function(el){
            if (el.classList.contains('btn-info'))      { applyColor(el, COLORS.view);    return; }
            if (el.classList.contains('btn-warning'))   { applyColor(el, COLORS.edit);    return; }
            if (el.classList.contains('btn-danger'))    { applyColor(el, COLORS.del);     return; }
            if (el.classList.contains('btn-secondary')) { applyColor(el, COLORS.archive); return; }
            if (el.classList.contains('btn-primary'))   { applyColor(el, COLORS.add);     return; }
        });
    }

    /* تطبيق عند تحميل الصفحة */
    document.addEventListener('DOMContentLoaded', styleAllButtons);

    /* تطبيق عند إضافة عناصر جديدة (DataTables يبني الأزرار بعد DOMContentLoaded) */
    var obs = new MutationObserver(function(muts){
        var needed = false;
        muts.forEach(function(m){ if (m.addedNodes.length) needed = true; });
        if (needed) styleAllButtons();
    });
    document.addEventListener('DOMContentLoaded', function(){
        obs.observe(document.body, { childList:true, subtree:true });
    });

    window.crmStyleButtons = styleAllButtons;
})();
</script>

<!-- ══ كلاسات الثيم الديناميكية على الـ body ══ -->
<script>
(function(){
    var b = document.body;
    <?php if ($crm_dark_mode): ?>b.classList.add('dark-mode');<?php endif; ?>
    <?php if ($crm_btn_style && $crm_btn_style !== 'gradient'): ?>
    b.classList.add('btn-style-<?php echo htmlspecialchars($crm_btn_style); ?>');
    <?php endif; ?>
    <?php if ($crm_table_style && $crm_table_style !== 'default'): ?>
    b.classList.add('table-style-<?php echo htmlspecialchars($crm_table_style); ?>');
    <?php endif; ?>
    <?php if ($crm_page_bar_style && $crm_page_bar_style !== 'gradient'): ?>
    b.classList.add('page-bar-<?php echo htmlspecialchars($crm_page_bar_style); ?>');
    <?php endif; ?>
    <?php if (!$crm_topbar_shadow):  ?>b.classList.add('topbar-no-shadow');<?php endif; ?>
})();
</script>

<!-- Left navbar links -->
<nav class="main-header navbar navbar-expand <?php echo $header_text_class; ?>"
     style="background-color:<?php echo htmlspecialchars($header_bg_color); ?> !important;
            border-bottom:1px solid rgba(0,0,0,0.08);
            font-family:'<?php echo htmlspecialchars($system_font); ?>',sans-serif !important;
            box-shadow:0 2px 10px rgba(0,0,0,0.07)!important;">

    <ul class="navbar-nav align-items-center">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="<?= $web_base ?>index.php" class="nav-link">الرئيسية</a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="<?= $web_base ?>contact.php" class="nav-link">المحادثة</a>
        </li>
        <li class="nav-item d-none d-md-inline-block">
            <a class="nav-link" data-widget="fullscreen" href="#" role="button" id="fullscreen-btn">
                <i class="fas fa-expand-arrows-alt"></i>
            </a>
        </li>
        <!-- ساعة حية -->
        <li class="nav-item d-none d-md-inline-block">
            <span id="live-clock" class="nav-link px-2">
                <i class="far fa-clock"></i>
                <span id="clock-time">--:--:--</span>
            </span>
        </li>
    </ul>



    <!-- Right navbar links -->
    <ul class="navbar-nav mr-auto-navbav">
        <!-- Messages Dropdown Menu -->
        <!-- Messages Dropdown Menu -->
        <!-- Messages Dropdown Menu -->
        <li class="nav-item dropdown">
            <a class="nav-link" data-toggle="dropdown" href="#">
                <i class="far fa-comments"></i>
                <?php if ($unread_total_count > 0): ?>
                <span class="badge badge-danger navbar-badge"><?php echo $unread_total_count; ?></span>
                <?php endif; ?>
            </a>

            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                <span class="dropdown-item dropdown-header">المحادثات الأخيرة</span>
                <div class="dropdown-divider"></div>

                <?php if (count($latest_messages) > 0): ?>
                <?php foreach ($latest_messages as $msg): ?>
                <a href="<?= $web_base ?>contact.php?user_id=<?php echo $msg['sender_id']; ?>"
                    class="dropdown-item">
                    <div class="media align-items-center">
                        <!-- عرض إجمالي رسائل هذا المستخدم في دائرة ملونة -->
                        <div class="img-circle mr-3 d-flex align-items-center justify-content-center bg-primary text-white"
                            style="width: 40px; height: 40px; min-width: 40px; font-weight: bold; font-size: 0.9rem;">
                            <?php echo $msg['total_sent_by_user']; ?>
                        </div>

                        <div class="media-body text-right">
                            <h3 class="dropdown-item-title" style="font-size: 0.9rem; font-weight: bold;">
                                <?php echo htmlspecialchars($msg['full_name']); ?>
                                <span
                                    class="float-left text-sm <?php echo ($msg['is_read'] == 0) ? 'text-danger' : 'text-muted'; ?>">
                                    <i class="fas fa-star"></i>
                                </span>
                            </h3>
                            <!-- عرض نص آخر رسالة فقط -->
                            <p class="text-sm text-muted">
                                <?php echo htmlspecialchars(mb_strimwidth($msg['message_text'], 0, 30, "...")); ?>
                            </p>
                            <p class="text-xs text-muted">
                                <i class="far fa-clock mr-1"></i>
                                <?php echo date('h:i A', strtotime($msg['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                </a>
                <div class="dropdown-divider"></div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-center p-3 mb-0 text-muted">لا توجد محادثات</p>
                <?php endif; ?>

                <a href="<?= $web_base ?>contact.php" class="dropdown-item dropdown-footer">عرض جميع
                    الرسائل</a>
            </div>
        </li>
        <!-- Notifications Dropdown Menu -->
        <li class="nav-item dropdown">
            <a class="nav-link" data-toggle="dropdown" href="#">
                <i class="far fa-bell"></i>
                <?php if ($total_requests > 0): ?>
                <span class="badge badge-warning navbar-badge"><?php echo $total_requests; ?></span>
                <?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                <span class="dropdown-item dropdown-header"><?php echo $total_requests; ?> بلاغات جديدة</span>

                <div class="dropdown-divider"></div>

                <?php if (count($recent_requests) > 0): ?>
                <?php
                    // ... داخل حلقة foreach الخاصة بالبلاغات ...
                    foreach ($recent_requests as $req):
                        // تحديد لون الأيقونة أو النص بناءً على الأولوية
                        $priority_color = 'text-info'; // الافتراضي
                        $priority_text = $req['priority'] ?? 'عادي';

                        switch ($req['priority']) {
                            case 'Urgent':
                            case 'High':
                            case 'عاجل':
                                $priority_color = 'text-danger';
                                break;
                            case 'Medium':
                            case 'متوسط':
                                $priority_color = 'text-warning';
                                break;
                            case 'Low':
                            case 'منخفض':
                                $priority_color = 'text-success';
                                break;
                        }
                    ?>
                <a href="<?= $web_base ?>pages/forms/view-request.php?id=<?php echo $req['id']; ?>"
                    class="dropdown-item">
                    <div class="media align-items-center">
                        <i class="fas fa-exclamation-circle mr-2 <?php echo $priority_color; ?>"
                            style="font-size: 1.2rem;"></i>
                        <div class="media-body">
                            <p class="text-sm mb-0">بلاغ جديد رقم <strong>#<?php echo $req['id']; ?></strong></p>
                            <div class="d-flex justify-content-between">
                                <small class="text-muted"><i class="far fa-clock"></i>
                                    <?php echo date('H:i', strtotime($req['created_at'])); ?></small>
                                <small
                                    class="badge badge-light <?php echo $priority_color; ?> border"><?php echo $priority_text; ?></small>
                            </div>
                        </div>
                    </div>
                </a>
                <div class="dropdown-divider"></div>
                <?php endforeach; ?>
                <?php else: ?>
                <a href="#" class="dropdown-item text-center">لا توجد بلاغات حالياً</a>
                <?php endif; ?>

                <a href="<?= $web_base ?>pages/tables/show-requests.php"
                    class="dropdown-item dropdown-footer">عرض كافة
                    البلاغات</a>
            </div>
        </li>

        <!-- اسم المستخدم -->
        <li class="nav-item d-none d-md-inline-block">
            <a href="<?= $web_base ?>pages/forms/profile.php" class="nav-link" title="الملف الشخصي"
                style="display:flex; align-items:center; gap:7px;">
                <img src="<?= $fullImagePath ?>" alt="avatar"
                    style="width:28px; height:28px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.4);">
                <span class="header-user-name"><?= htmlspecialchars($userName) ?></span>
            </a>
        </li>
        <!-- زر تسجيل الخروج -->
        <li class="nav-item">
            <a class="nav-link text-danger" href="<?= $web_base ?>logout.php" id="logout-link"
                title="تسجيل الخروج">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </li>
    </ul>
</nav>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── رسالة موحدة لجميع الصفحات ──
(function() {
    var msgJson = sessionStorage.getItem('app_message');
    if (msgJson) {
        try {
            var msg = JSON.parse(msgJson);
            if (msg && msg.text) {
                var icons = {
                    success: '<div style="font-size:60px;color:#28a745;"><i class="fas fa-check-circle"></i></div>',
                    error: '<div style="font-size:60px;color:#dc3545;"><i class="fas fa-times-circle"></i></div>',
                    warning: '<div style="font-size:60px;color:#ffc107;"><i class="fas fa-exclamation-triangle"></i></div>',
                    info: '<div style="font-size:60px;color:#17a2b8;"><i class="fas fa-info-circle"></i></div>'
                };
                Swal.fire({
                    title: msg.title || '',
                    html: (icons[msg.icon] || '') + '<div style="font-size:18px;margin-top:12px;">' + msg
                        .text + '</div>',
                    icon: msg.icon || 'info',
                    confirmButtonText: 'موافق',
                    confirmButtonColor: '#3085d6',
                    customClass: {
                        confirmButton: 'btn btn-primary px-4',
                        title: 'swal2-title-custom'
                    },
                    buttonsStyling: false,
                    timer: msg.icon === 'success' ? 3000 : 0,
                    timerProgressBar: true
                });
            }
        } catch (e) {}
        sessionStorage.removeItem('app_message');
    }
    // توافق مع الإصدارات السابقة (قديم)
    (function() {
        var oldKeys = ['showSuccess', 'showError', 'swal_title'];
        for (var i = 0; i < oldKeys.length; i++) {
            var val = sessionStorage.getItem(oldKeys[i]);
            if (val) {
                sessionStorage.removeItem(oldKeys[i]);
            }
        }
        var oldTitle = sessionStorage.getItem('swal_title');
        if (oldTitle) {
            sessionStorage.removeItem('swal_title');
            sessionStorage.removeItem('swal_text');
            sessionStorage.removeItem('swal_icon');
        }
    })();
})();

// رسالة تحذير من SESSION (لإعادة التوجيه عند منع الوصول)
<?php
    $warnMsg = $_SESSION['warning_message'] ?? null;
    unset($_SESSION['warning_message']);
    if ($warnMsg): ?>
Swal.fire({
    icon: 'warning',
    title: '<?= __('warning') ?>',
    text: '<?= htmlspecialchars($warnMsg, ENT_QUOTES) ?>',
    confirmButtonText: 'موافق'
});
<?php endif; ?>

document.getElementById('logout-link').addEventListener('click', function(e) {
    e.preventDefault();

    Swal.fire({
        title: 'تسجيل الخروج',
        text: "هل أنت متأكد من رغبتك في إنهاء الجلسة؟",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'نعم، اخرج الآن',
        cancelButtonText: ' إلغاء',
        reverseButtons: true // لجعل الأزرار مناسبة للغة العربية
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = "<?= $web_base ?>logout.php";
        }
    });
});

function updateUnreadCount() {
    // مسار مطلق لتجنب 404 من الصفحات المتداخلة
    $.get("<?= $web_base ?>get_unread_count.php", function(data) {
        // الملف يُعيد JSON: {"total": X} أو {"count": X}
        var count = 0;
        if (typeof data === 'object') {
            count = data.total || data.count || 0;
        } else {
            count = parseInt(data) || 0;
        }
        if (count > 0) {
            $('.navbar-badge').first().text(count).show();
        } else {
            $('.navbar-badge').first().hide();
        }
    }).fail(function() {
        // تجاهل أخطاء الشبكة بصمت
    });
}

// تحديث العدد كل 30 ثوانٍ (بعد تحميل jQuery)
if (typeof jQuery !== 'undefined') {
    setInterval(updateUnreadCount, 30000);
    updateUnreadCount();
} else {
    document.addEventListener('DOMContentLoaded', function() {
        setInterval(updateUnreadCount, 30000);
        updateUnreadCount();
    });
}
// وظيفة إرسال الرسالة
function sendMessage(receiverId, text) {
    $.post("send_message.php", {
        receiver_id: receiverId,
        message: text
    }, function(data) {
        $('#chat-input').val(''); // مسح الحقل بعد الإرسال
        loadMessages(receiverId); // تحديث الشات
    });
}

var currentChatId = null;

// وظيفة تحديث الرسائل تلقائياً كل 3 ثوانٍ
setInterval(function() {
    if (currentChatId) {
        loadMessages(currentChatId);
    }
}, 3000);

// ── استعادة حالة ملء الشاشة عند التنقل بين الصفحات ──
(function() {
    const body = document.body;
    if (sessionStorage.getItem('wasl_fullscreen') === 'true') {
        body.classList.add('wasl-fullscreen');
        body.classList.add('sidebar-collapse');
        const icon = document.querySelector('#fullscreen-btn i');
        if (icon) {
            icon.classList.remove('fa-expand-arrows-alt');
            icon.classList.add('fa-compress-arrows-alt');
        }
    }
})();

// ── ملء الشاشة مع بقائها عند التنقل ──
document.getElementById('fullscreen-btn').addEventListener('click', function(e) {
    e.preventDefault();
    const icon = this.querySelector('i');
    const body = document.body;
    const isFullscreen = body.classList.contains('wasl-fullscreen');

    if (!isFullscreen) {
        body.classList.add('wasl-fullscreen');
        body.classList.add('sidebar-collapse');
        icon.classList.remove('fa-expand-arrows-alt');
        icon.classList.add('fa-compress-arrows-alt');
        sessionStorage.setItem('wasl_fullscreen', 'true');
    } else {
        body.classList.remove('wasl-fullscreen');
        body.classList.remove('sidebar-collapse');
        icon.classList.remove('fa-compress-arrows-alt');
        icon.classList.add('fa-expand-arrows-alt');
        sessionStorage.setItem('wasl_fullscreen', 'false');
    }
});

// ── خروج من وضع ملء الشاشة عند الضغط على Esc ──
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const body = document.body;
        if (body.classList.contains('wasl-fullscreen')) {
            body.classList.remove('wasl-fullscreen');
            body.classList.remove('sidebar-collapse');
            const icon = document.querySelector('#fullscreen-btn i');
            if (icon) {
                icon.classList.remove('fa-compress-arrows-alt');
                icon.classList.add('fa-expand-arrows-alt');
            }
            sessionStorage.setItem('wasl_fullscreen', 'false');
        }
    }
});

// ── ساعة حية ──
(function tickClock() {
    const el = document.getElementById('clock-time');
    if (el) {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        el.textContent = h + ':' + m + ':' + s;
    }
    setTimeout(tickClock, 1000);
})();

// ── CSRF Auto-Injection ───────────────────────────────────────────
// يُضيف توكن CSRF تلقائياً لكل نموذج POST في الصفحة
// ويُضيفه لجميع طلبات $.ajax و$.post
(function(){
    var token = '<?= Security::token() ?>';

    function injectCsrf() {
        // 1. إضافة hidden field لكل form[method=post] لا يحتوي على التوكن
        document.querySelectorAll('form').forEach(function(form){
            var m = (form.getAttribute('method') || '').toUpperCase();
            if (m !== 'POST' && m !== '') return;
            if (form.querySelector('[name="csrf_token"]')) return;
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'csrf_token';
            inp.value = token;
            form.appendChild(inp);
        });
    }

    // الانتظار حتى اكتمال DOM لأن الفورم تُنشأ بعد include main-header.php
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectCsrf);
    } else {
        injectCsrf();
    }

    // إعادة الحقن عند ظهور مودال بوتستراب (للمودالات الديناميكية)
    if (window.jQuery) {
        $(document).on('shown.bs.modal ajaxComplete', injectCsrf);
    }

    // 2. إضافة التوكن لجميع طلبات jQuery AJAX
    if (window.jQuery) {
        $.ajaxSetup({
            beforeSend: function(xhr, settings) {
                if (settings.type === 'POST' || settings.method === 'POST') {
                    xhr.setRequestHeader('X-CSRF-TOKEN', token);
                    if (typeof settings.data === 'string' && settings.data.indexOf('csrf_token') === -1) {
                        settings.data += (settings.data ? '&' : '') + 'csrf_token=' + token;
                    } else if (settings.data instanceof FormData && !settings.data.has('csrf_token')) {
                        settings.data.append('csrf_token', token);
                    }
                }
            }
        });
    }

    // 3. منع إرسال النموذج أكثر من مرة (تعطيل زر الإرسال بعد أول نقرة)
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (form.getAttribute('data-submitted') === '1') {
            e.preventDefault();
            return;
        }
        form.setAttribute('data-submitted', '1');
        var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        btns.forEach(function(btn) {
            btn.disabled = true;
            var orig = btn.getAttribute('data-orig-html');
            if (!orig) {
                btn.setAttribute('data-orig-html', btn.innerHTML);
                btn.setAttribute('data-orig-value', btn.value);
            }
            if (btn.tagName === 'BUTTON') {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i> جاري الحفظ...';
            } else {
                btn.value = 'جاري الحفظ...';
            }
        });
    }, true);
})();
</script>