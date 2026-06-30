<?php
/**
 * print_header.php — ترويسة الطباعة الموحدة لجميع تقارير النظام
 * يُضمَّن في كل صفحة تقرير قبل وسم </body>
 * الاستخدام:  <?php include __DIR__ . '/../../print_header.php'; ?>
 *             ثم في زر الطباعة: customize: waslPrintSetup(win, 'عنوان التقرير')
 */

// جلب بيانات النظام إن لم تكن محملة مسبقاً
if (!isset($settings) || empty($settings)) {
    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
}

$_pName    = htmlspecialchars($settings['system_name']    ?? 'الشركة',      ENT_QUOTES);
$_pNameEn  = htmlspecialchars($settings['system_name_en'] ?? 'Company',     ENT_QUOTES);
$_pAddr    = htmlspecialchars($settings['address']        ?? '',             ENT_QUOTES);
$_pLogo    = $settings['system_logo'] ?? 'default-logo.png';

// تحويل الشعار إلى base64
$_pLogoUri = '';
$_logoFile = __DIR__ . '/dist/img/' . $_pLogo;
if (file_exists($_logoFile)) {
    $ext = strtolower(pathinfo($_pLogo, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/jpeg');
    $_pLogoUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($_logoFile));
}
?>
<script>
/* ══════════════════════════════════════════════════════════════
   waslPrintSetup — ترويسة طباعة موحدة لجميع تقارير النظام
   الاستخدام داخل DataTable:
       { extend:'print', customize: function(win){ waslPrintSetup(win,'عنوان التقرير'); } }
══════════════════════════════════════════════════════════════ */
var _WASL = {
    logo:    "<?= $_pLogoUri ?>",
    nameAr:  "<?= $_pName ?>",
    nameEn:  "<?= $_pNameEn ?>",
    addr:    "<?= $_pAddr ?>"
};

function waslPrintSetup(win, reportTitle, subtitle) {
    var doc = win.document;

    /* ── إعادة تنسيق الجسم ── */
    $(doc.body).css({
        'direction'   : 'rtl',
        'text-align'  : 'right',
        'font-family' : 'Cairo, Tahoma, sans-serif',
        'color'       : '#222',
        'font-size'   : '13px'
    });
    $(doc.body).find('h1, h2, h3').remove();
    $(doc.body).find('table').css({ 'width':'100%', 'border-collapse':'collapse' });
    $(doc.body).find('th').css({
        'background'    : '#0d4a1c',
        'color'         : '#fff',
        'padding'       : '7px 10px',
        'border'        : '1px solid #0d4a1c',
        'font-size'     : '12px'
    });
    $(doc.body).find('td').css({
        'padding'       : '6px 10px',
        'border'        : '1px solid #dde3f0',
        'font-size'     : '12px'
    });
    $(doc.body).find('tr:nth-child(even)').css('background', '#f5f7fa');

    /* ── بناء الترويسة ── */
    var d = new Date().toLocaleDateString('ar-SA', { year:'numeric', month:'long', day:'numeric' });

    var logoHtml = _WASL.logo
        ? '<img src="' + _WASL.logo + '" style="max-height:80px;margin-bottom:8px;">'
        : '';

    var subtitleHtml = subtitle
        ? '<p style="color:#555;font-size:12px;margin:4px 0 0;">' + subtitle + '</p>'
        : '';

    var header = [
        '<div style="text-align:center;margin-bottom:24px;padding-bottom:12px;">',
            logoHtml,
            '<br>',
            '<strong style="font-size:24px;color:#0d4a1c;display:block;line-height:1.4;">'
                + _WASL.nameAr + '</strong>',
            '<span style="font-size:13px;color:#0d6efd;display:block;margin-top:4px;">'
                + _WASL.addr + '</span>',
            subtitleHtml,
            '<hr style="border:none;border-top:2.5px solid #0d6efd;margin:14px 0;">',
            '<h3 style="font-size:18px;color:#1e4b8a;margin:8px 0 0;font-weight:700;">'
                + (reportTitle || '') + '</h3>',
            '<small style="color:#999;font-size:11px;display:block;margin-top:6px;">'
                + 'تاريخ الطباعة: ' + d + '</small>',
        '</div>'
    ].join('');

    $(doc.body).prepend(header);

    /* ── تذييل الصفحة ── */
    var footer = [
        '<div style="margin-top:30px;border-top:1px solid #dde;padding-top:8px;',
                    'display:flex;justify-content:space-between;font-size:11px;color:#999;">',
            '<span>' + _WASL.nameAr + ' — ' + _WASL.nameEn + '</span>',
            '<span>' + d + '</span>',
        '</div>'
    ].join('');

    $(doc.body).append(footer);
}
</script>
