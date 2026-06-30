<?php
/**
 * مولّد دليل المستخدم التنفيذي — نظام وَصْل CRM
 * الوصول: http://localhost/UltimatesolutionsCrm/docs/generate_user_manual.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// ── إعداد mPDF ──────────────────────────────────────────────────────
$mpdf = new \Mpdf\Mpdf([
    'mode'              => 'utf-8',
    'format'            => 'A4',
    'default_font_size' => 11,
    'default_font'      => 'dejavusans',
    'margin_left'       => 15,
    'margin_right'      => 15,
    'margin_top'        => 20,
    'margin_bottom'     => 20,
    'margin_header'     => 10,
    'margin_footer'     => 10,
    'tempDir'           => dirname(__DIR__) . '/storage/purifier_cache',
]);

$mpdf->SetDirectionality('rtl');
$mpdf->autoScriptToLang   = true;
$mpdf->autoLangToFont      = true;
$mpdf->useKerning          = true;

// ── رأس وتذييل الصفحات ───────────────────────────────────────────────
$mpdf->SetHeader('
<table width="100%" style="border-bottom:2px solid #1a5276;padding-bottom:4px">
  <tr>
    <td style="text-align:right;font-size:9pt;color:#1a5276;font-weight:bold">دليل المستخدم التنفيذي — نظام وَصْل CRM</td>
    <td style="text-align:left;font-size:9pt;color:#888">الإصدار 2.0 | 2026</td>
  </tr>
</table>');

$mpdf->SetFooter('
<table width="100%" style="border-top:1px solid #e2e8f0;padding-top:4px">
  <tr>
    <td style="text-align:right;font-size:8pt;color:#888">جميع الحقوق محفوظة © 2026</td>
    <td style="text-align:center;font-size:8pt;color:#888">{PAGENO} / {nbpg}</td>
    <td style="text-align:left;font-size:8pt;color:#888">سري وخاص بالمنظمة</td>
  </tr>
</table>');

// ════════════════════════════════════════════════════════════════════
// دوال مساعدة
// ════════════════════════════════════════════════════════════════════

function sectionTitle(string $num, string $title, string $icon = '●'): string {
    return "
    <div style='background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;
                padding:12px 18px;border-radius:8px;margin:18px 0 10px;
                font-size:14pt;font-weight:bold;direction:rtl;text-align:right'>
        {$icon} {$num}. {$title}
    </div>";
}

function subTitle(string $title): string {
    return "
    <div style='background:#eaf4fb;border-right:4px solid #2980b9;
                padding:8px 14px;margin:12px 0 6px;border-radius:0 6px 6px 0;
                font-size:11pt;font-weight:bold;color:#1a5276;direction:rtl;text-align:right'>
        ◆ {$title}
    </div>";
}

function fieldTable(array $fields): string {
    $rows = '';
    $alt  = false;
    foreach ($fields as $f) {
        $bg  = $alt ? '#f8fbff' : '#ffffff';
        $req = !empty($f['req']) ? '<span style="color:#e74c3c;font-weight:bold"> *</span>' : '';
        $type = $f['type'] ?? '';
        $typeHtml = $type ? "<span style='background:#e8f4fd;color:#2980b9;padding:1px 7px;
                             border-radius:10px;font-size:8pt'>{$type}</span>" : '';
        $rows .= "
        <tr style='background:{$bg}'>
            <td style='padding:7px 10px;border:1px solid #e2e8f0;font-weight:bold;
                       color:#1a3a5c;width:22%'>{$f['name']}{$req}</td>
            <td style='padding:7px 10px;border:1px solid #e2e8f0;width:10%;text-align:center'>{$typeHtml}</td>
            <td style='padding:7px 10px;border:1px solid #e2e8f0;color:#374151'>{$f['desc']}</td>
            <td style='padding:7px 10px;border:1px solid #e2e8f0;color:#27ae60;width:20%'>" . ($f['example'] ?? '') . "</td>
        </tr>";
        $alt = !$alt;
    }
    return "
    <table width='100%' style='border-collapse:collapse;font-size:9.5pt;direction:rtl;margin-bottom:10px'>
        <thead>
            <tr style='background:#1a5276;color:#fff'>
                <th style='padding:8px 10px;border:1px solid #154360;text-align:right'>الحقل</th>
                <th style='padding:8px 10px;border:1px solid #154360;text-align:center'>النوع</th>
                <th style='padding:8px 10px;border:1px solid #154360;text-align:right'>الشرح</th>
                <th style='padding:8px 10px;border:1px solid #154360;text-align:right'>مثال</th>
            </tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>";
}

function stepBox(array $steps): string {
    $html = '<ol style="direction:rtl;text-align:right;margin:8px 0;padding-right:20px">';
    foreach ($steps as $s) {
        $html .= "<li style='margin-bottom:5px;color:#2d3748;line-height:1.6'>{$s}</li>";
    }
    return $html . '</ol>';
}

function infoBox(string $text, string $type = 'info'): string {
    $colors = [
        'info'    => ['bg'=>'#eaf4fb','border'=>'#2980b9','icon'=>'ℹ'],
        'warning' => ['bg'=>'#fefce8','border'=>'#f59e0b','icon'=>'⚠'],
        'success' => ['bg'=>'#f0fdf4','border'=>'#27ae60','icon'=>'✓'],
        'danger'  => ['bg'=>'#fff5f5','border'=>'#e74c3c','icon'=>'✕'],
    ];
    $c = $colors[$type] ?? $colors['info'];
    return "
    <div style='background:{$c['bg']};border-right:4px solid {$c['border']};
                padding:10px 14px;border-radius:0 8px 8px 0;margin:10px 0;
                font-size:9.5pt;color:#374151;direction:rtl;text-align:right'>
        <strong style='color:{$c['border']}'>{$c['icon']}</strong> {$text}
    </div>";
}

function screenshotPlaceholder(string $screenName): string {
    return "
    <div style='border:2px dashed #cbd5e1;border-radius:8px;padding:20px;
                text-align:center;margin:10px 0;background:#f8fafc'>
        <div style='font-size:9pt;color:#94a3b8'>[ شاشة: {$screenName} ]</div>
        <div style='font-size:8pt;color:#cbd5e1;margin-top:4px'>
            http://localhost/UltimatesolutionsCrm/admin/
        </div>
    </div>";
}

// ════════════════════════════════════════════════════════════════════
// بداية محتوى PDF
// ════════════════════════════════════════════════════════════════════

$html = '
<html><body style="direction:rtl;text-align:right;font-family:dejavusans;color:#1a1a2e">';

// ── صفحة الغلاف ──────────────────────────────────────────────────────
$html .= '
<div style="text-align:center;padding:60px 20px;direction:rtl">
    <div style="background:linear-gradient(135deg,#0d2b4e,#1a5276,#2980b9);
                border-radius:16px;padding:60px 40px;color:#fff;margin:0 20px">
        <div style="font-size:11pt;letter-spacing:2px;opacity:.8;margin-bottom:10px">
            نظام إدارة علاقات العملاء
        </div>
        <div style="font-size:36pt;font-weight:900;margin:10px 0;letter-spacing:1px">
            وَصـــل
        </div>
        <div style="width:60px;height:4px;background:#fff;margin:16px auto;border-radius:2px;opacity:.6"></div>
        <div style="font-size:20pt;font-weight:700;margin:16px 0">
            دليل المستخدم التنفيذي
        </div>
        <div style="font-size:13pt;opacity:.85;margin-top:8px">
            شرح تفصيلي لكل شاشة مع خطوات التنفيذ وإدخال البيانات
        </div>
        <div style="margin-top:40px;font-size:10pt;opacity:.7">
            الإصدار 2.0 &nbsp;|&nbsp; 2026 &nbsp;|&nbsp; سري وخاص
        </div>
    </div>

    <div style="margin-top:30px;padding:20px;background:#f8fafc;
                border-radius:12px;border:1px solid #e2e8f0;text-align:right">
        <table width="100%" style="font-size:10pt">
            <tr>
                <td width="50%"><strong>اسم النظام:</strong> نظام وَصْل CRM</td>
                <td width="50%"><strong>تاريخ الإصدار:</strong> يونيو 2026</td>
            </tr>
            <tr>
                <td><strong>النوع:</strong> دليل تنفيذي وتشغيلي</td>
                <td><strong>المستخدم المستهدف:</strong> جميع المستخدمين</td>
            </tr>
        </table>
    </div>
</div>';

$mpdf->AddPage();

// ── فهرس المحتويات ────────────────────────────────────────────────────
$html .= '
<div style="background:#1a5276;color:#fff;padding:14px 18px;border-radius:8px;
            font-size:14pt;font-weight:bold;margin-bottom:16px">
    📋 فهرس المحتويات
</div>

<table width="100%" style="border-collapse:collapse;font-size:10pt;direction:rtl">
    <tr style="background:#eaf4fb">
        <td style="padding:7px 12px;border:1px solid #e2e8f0;font-weight:bold;color:#2980b9">الرقم</td>
        <td style="padding:7px 12px;border:1px solid #e2e8f0;font-weight:bold;color:#2980b9">الموضوع</td>
    </tr>';

$toc = [
    ['1',  'تسجيل الدخول إلى النظام'],
    ['2',  'لوحة التحكم التحليلية (Dashboard)'],
    ['3',  'إدارة البلاغات — إضافة بلاغ'],
    ['4',  'إدارة البلاغات — عرض وتتبع البلاغات'],
    ['5',  'إدارة المهام — إضافة مهمة وتعيينها'],
    ['6',  'إدارة المهام — عرض وتعديل المهام'],
    ['7',  'إدارة الوثائق — رفع وثيقة جديدة'],
    ['8',  'إدارة الوثائق — عرض وبحث الوثائق'],
    ['9',  'إدارة الأصول — إضافة أصل'],
    ['10', 'الصيانة الدورية — جدولة وتسجيل الصيانة'],
    ['11', 'قاعدة المعرفة — إضافة وتصفح المقالات'],
    ['12', 'إدارة الموظفين'],
    ['13', 'إدارة المستخدمين والأدوار'],
    ['14', 'نظام الصلاحيات'],
    ['15', 'الشات والإشعارات الداخلية'],
    ['16', 'التقارير والسجلات'],
    ['17', 'إعدادات النظام والمظهر'],
    ['18', 'ملحق — الرموز ومعاني الحالات'],
];

$alt = false;
foreach ($toc as $item) {
    $bg = $alt ? '#f8fbff' : '#ffffff';
    $html .= "<tr style='background:{$bg}'>
        <td style='padding:7px 12px;border:1px solid #e2e8f0;color:#1a5276;font-weight:bold;width:12%'>
            {$item[0]}
        </td>
        <td style='padding:7px 12px;border:1px solid #e2e8f0;color:#374151'>{$item[1]}</td>
    </tr>";
    $alt = !$alt;
}
$html .= '</table>';

$mpdf->WriteHTML($html);

// ════════════════════════════════════════════════════════════════════
// القسم 1 — تسجيل الدخول
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('1', 'تسجيل الدخول إلى النظام', '🔐');
$s .= screenshotPlaceholder('شاشة تسجيل الدخول — auth/login.php');
$s .= subTitle('الرابط');
$s .= '<p style="font-size:10pt;direction:ltr;text-align:left;background:#1e293b;color:#e2e8f0;
              padding:10px 16px;border-radius:6px;font-family:monospace">
    http://localhost/UltimatesolutionsCrm/auth/login.php
</p>';

$s .= subTitle('حقول تسجيل الدخول');
$s .= fieldTable([
    ['name'=>'البريد الإلكتروني','type'=>'Email','req'=>true,'desc'=>'البريد المسجّل في النظام للمستخدم','example'=>'admin@company.com'],
    ['name'=>'كلمة المرور','type'=>'Password','req'=>true,'desc'=>'كلمة المرور السرية (حساسة لحالة الأحرف)','example'=>'••••••••'],
]);

$s .= subTitle('خطوات الدخول');
$s .= stepBox([
    'افتح المتصفح وانتقل للرابط أعلاه',
    'أدخل البريد الإلكتروني الخاص بك',
    'أدخل كلمة المرور',
    'انقر زر <strong>"تسجيل الدخول"</strong>',
    'سيُوجَّهك النظام تلقائياً للوحة التحكم',
]);

$s .= infoBox('<strong>الحماية من Brute Force:</strong> يُقفَل الحساب تلقائياً بعد 5 محاولات فاشلة لمدة 15 دقيقة.', 'warning');
$s .= infoBox('<strong>انتهاء الجلسة:</strong> تنتهي الجلسة تلقائياً بعد ساعتين من عدم النشاط.', 'info');

$s .= subTitle('رسائل الخطأ الشائعة');
$s .= fieldTable([
    ['name'=>'بيانات غير صحيحة','type'=>'خطأ','desc'=>'البريد أو كلمة المرور غير مطابقة','example'=>'راجع البيانات وأعد المحاولة'],
    ['name'=>'حساب محظور','type'=>'تحذير','desc'=>'تجاوز الحد الأقصى لمحاولات الدخول','example'=>'انتظر 15 دقيقة'],
    ['name'=>'الحساب موقف','type'=>'خطأ','desc'=>'المسؤول أوقف الحساب','desc'=>'تواصل مع مسؤول النظام','example'=>'تواصل مع الإدارة'],
]);
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 2 — لوحة التحكم
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('2', 'لوحة التحكم التحليلية', '📊');
$s .= screenshotPlaceholder('لوحة التحكم — admin/index.php');

$s .= subTitle('مؤشرات الأداء (KPIs) — الصف العلوي');
$s .= fieldTable([
    ['name'=>'بلاغات اليوم','type'=>'عداد','desc'=>'عدد البلاغات المفتوحة اليوم مقارنةً بالأمس','example'=>'12 بلاغ (+3)'],
    ['name'=>'خروقات SLA','type'=>'تحذير','desc'=>'البلاغات التي تجاوزت وقت الاستجابة المتفق عليه','example'=>'3 خروقات'],
    ['name'=>'مهام متأخرة','type'=>'تحذير','desc'=>'المهام التي تجاوز موعدها ولم تُنجز','example'=>'5 مهام'],
    ['name'=>'أصول تستحق صيانة','type'=>'عداد','desc'=>'الأصول التي موعد صيانتها خلال 7 أيام','example'=>'2 أصل'],
]);

$s .= subTitle('الرسوم البيانية');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl;margin-bottom:10px">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الرسم</th>
        <th style="padding:8px;border:1px solid #154360">الوصف</th>
        <th style="padding:8px;border:1px solid #154360">الفترة</th>
    </tr>
    <tr style="background:#f8fbff">
        <td style="padding:7px;border:1px solid #e2e8f0">📈 منحنى البلاغات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">مقارنة البلاغات المفتوحة والمُغلقة يومياً</td>
        <td style="padding:7px;border:1px solid #e2e8f0">آخر 30 يوماً</td>
    </tr>
    <tr>
        <td style="padding:7px;border:1px solid #e2e8f0">🍩 توزيع الأولويات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">نسبة البلاغات حسب الأولوية (عاجل/عالي/متوسط/منخفض)</td>
        <td style="padding:7px;border:1px solid #e2e8f0">الشهر الحالي</td>
    </tr>
    <tr style="background:#f8fbff">
        <td style="padding:7px;border:1px solid #e2e8f0">👥 أداء الفنيين</td>
        <td style="padding:7px;border:1px solid #e2e8f0">أفضل 5 فنيين بعدد المهام المُنجزة</td>
        <td style="padding:7px;border:1px solid #e2e8f0">الشهر الحالي</td>
    </tr>
    <tr>
        <td style="padding:7px;border:1px solid #e2e8f0">🏢 أداء الفروع</td>
        <td style="padding:7px;border:1px solid #e2e8f0">نسبة الحل وعدد البلاغات لكل فرع</td>
        <td style="padding:7px;border:1px solid #e2e8f0">الشهر الحالي</td>
    </tr>
</table>';

$s .= subTitle('الجدول الزمني للنشاط');
$s .= '<p style="font-size:9.5pt;color:#374151">يعرض آخر 8 أحداث في النظام (بلاغات + مهام + وثائق) مرتبة زمنياً. يمكن النقر على أي حدث للانتقال إليه.</p>';

$s .= infoBox('لوحة التحكم تُحدَّث عند كل تحميل للصفحة. لا تحتاج إلى إعادة تحميل يدوي.', 'success');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 3 — إضافة بلاغ
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('3', 'إدارة البلاغات — إضافة بلاغ جديد', '🎫');
$s .= screenshotPlaceholder('نموذج إضافة بلاغ — pages/forms/add-request.php');

$s .= subTitle('حقول نموذج البلاغ');
$s .= fieldTable([
    ['name'=>'رقم المرجع','type'=>'نص','req'=>false,'desc'=>'الرقم المرجعي الخارجي (اختياري — يُولَّد تلقائياً)','example'=>'REQ-2026-001'],
    ['name'=>'الموقع','type'=>'نص','req'=>true,'desc'=>'اسم الموقع أو الغرفة أو المبنى مصدر البلاغ','example'=>'مستودع B2 - الطابق الثاني'],
    ['name'=>'التصنيف','type'=>'قائمة','req'=>true,'desc'=>'تصنيف البلاغ من قائمة التصنيفات المعرّفة مسبقاً (يتحكم فيها الصلاحيات)','example'=>'أعطال كهربائية'],
    ['name'=>'الفرع','type'=>'قائمة','req'=>true,'desc'=>'الفرع أو الموقع التنظيمي المصدر للبلاغ','example'=>'الفرع الرئيسي - الرياض'],
    ['name'=>'المنطقة','type'=>'قائمة','req'=>false,'desc'=>'المنطقة الجغرافية (تُملأ تلقائياً عند اختيار الفرع)','example'=>'منطقة الرياض'],
    ['name'=>'الأولوية','type'=>'قائمة','req'=>true,'desc'=>'مستوى الأولوية — يؤثر على حسابات SLA','example'=>'عالية'],
    ['name'=>'تفاصيل المشكلة','type'=>'نص طويل','req'=>true,'desc'=>'وصف تفصيلي للمشكلة أو الطلب','example'=>'انقطاع الكهرباء في الغرفة 204 منذ الساعة 9 صباحاً'],
    ['name'=>'المرفقات','type'=>'ملف','req'=>false,'desc'=>'صور أو مستندات داعمة (PDF/JPG/PNG - حتى 20MB)','example'=>'صورة.jpg'],
]);

$s .= subTitle('خطوات إضافة بلاغ');
$s .= stepBox([
    'انتقل إلى القائمة الجانبية → <strong>البلاغات</strong> → <strong>بلاغ جديد</strong>',
    'أدخل <strong>الموقع</strong> بدقة لمساعدة الفني في الوصول',
    'اختر <strong>التصنيف</strong> المناسب (الأولويات تحسب تلقائياً حسب SLA)',
    'اختر <strong>الفرع</strong> من القائمة',
    'حدد <strong>الأولوية</strong>: عاجل — عالية — متوسطة — منخفضة',
    'اكتب <strong>التفاصيل</strong> بوضوح مع ذكر وقت حدوث المشكلة',
    'أرفق صوراً إن وُجدت',
    'انقر <strong>"حفظ البلاغ"</strong>',
    'سيُوجَّهك النظام لصفحة البلاغات مع رسالة تأكيد',
]);

$s .= subTitle('مستويات الأولوية وأوقات SLA');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl;margin-bottom:10px">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الأولوية</th>
        <th style="padding:8px;border:1px solid #154360">وقت الاستجابة</th>
        <th style="padding:8px;border:1px solid #154360">وقت الحل</th>
        <th style="padding:8px;border:1px solid #154360">الاستخدام</th>
    </tr>
    <tr style="background:#fff5f5">
        <td style="padding:7px;border:1px solid #e2e8f0;color:#dc2626;font-weight:bold">🔴 عاجل</td>
        <td style="padding:7px;border:1px solid #e2e8f0">1 ساعة</td>
        <td style="padding:7px;border:1px solid #e2e8f0">4 ساعات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">توقف كامل، خسائر فورية</td>
    </tr>
    <tr style="background:#fefce8">
        <td style="padding:7px;border:1px solid #e2e8f0;color:#d97706;font-weight:bold">🟠 عالية</td>
        <td style="padding:7px;border:1px solid #e2e8f0">2 ساعات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">8 ساعات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">تأثير على الإنتاجية</td>
    </tr>
    <tr style="background:#f0fdf4">
        <td style="padding:7px;border:1px solid #e2e8f0;color:#16a34a;font-weight:bold">🟡 متوسطة</td>
        <td style="padding:7px;border:1px solid #e2e8f0">4 ساعات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">24 ساعة</td>
        <td style="padding:7px;border:1px solid #e2e8f0">عطل جزئي، بديل موجود</td>
    </tr>
    <tr style="background:#f8fbff">
        <td style="padding:7px;border:1px solid #e2e8f0;color:#2563eb;font-weight:bold">🔵 منخفضة</td>
        <td style="padding:7px;border:1px solid #e2e8f0">8 ساعات</td>
        <td style="padding:7px;border:1px solid #e2e8f0">72 ساعة</td>
        <td style="padding:7px;border:1px solid #e2e8f0">طلب غير عاجل</td>
    </tr>
</table>';

$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 4 — عرض البلاغات
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('4', 'إدارة البلاغات — عرض وتتبع', '📋');
$s .= screenshotPlaceholder('قائمة البلاغات — pages/tables/show-requests.php');

$s .= subTitle('أدوات الفلترة والبحث');
$s .= fieldTable([
    ['name'=>'بحث نصي','type'=>'بحث','desc'=>'البحث في رقم البلاغ، الموقع، التفاصيل، اسم الفني','example'=>'طاقة كهربائية'],
    ['name'=>'التصنيف','type'=>'قائمة','desc'=>'تصفية البلاغات حسب تصنيف المشكلة','example'=>'أعطال كهربائية'],
    ['name'=>'الأولوية','type'=>'قائمة','desc'=>'عاجل / عالية / متوسطة / منخفضة','example'=>'عاجل'],
    ['name'=>'الحالة','type'=>'قائمة','desc'=>'مفتوح / قيد المعالجة / مُغلق / ملغي','example'=>'مفتوح'],
    ['name'=>'إعادة','type'=>'زر','desc'=>'مسح جميع الفلاتر والعودة للعرض الكامل','example'=>'—'],
]);

$s .= subTitle('أعمدة الجدول');
$s .= fieldTable([
    ['name'=>'#','type'=>'رقم','desc'=>'الرقم التسلسلي للبلاغ في القائمة المعروضة','example'=>'1'],
    ['name'=>'رقم البلاغ','type'=>'نص','desc'=>'المعرّف الفريد للبلاغ في النظام','example'=>'TK-2026-0042'],
    ['name'=>'الموقع','type'=>'نص','desc'=>'مكان حدوث المشكلة','example'=>'مستودع B2'],
    ['name'=>'التصنيف','type'=>'شارة','desc'=>'تصنيف البلاغ بلون مميز','example'=>'كهرباء'],
    ['name'=>'الأولوية','type'=>'شارة','desc'=>'مستوى الأولوية بلون (أحمر/برتقالي/أصفر/أزرق)','example'=>'عاجل'],
    ['name'=>'الحالة','type'=>'شارة','desc'=>'الحالة الحالية للبلاغ','example'=>'قيد المعالجة'],
    ['name'=>'الفني المعيَّن','type'=>'نص','desc'=>'اسم الفني المسؤول عن المعالجة','example'=>'أحمد محمد'],
    ['name'=>'الموعد النهائي','type'=>'تاريخ','desc'=>'تاريخ SLA — يتحول لأحمر عند الاقتراب','example'=>'2026-06-27'],
    ['name'=>'الإجراءات','type'=>'أزرار','desc'=>'عرض التفاصيل / تعديل / حذف (حسب الصلاحية)','example'=>'—'],
]);

$s .= subTitle('حالات البلاغ ودلالاتها');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الحالة</th>
        <th style="padding:8px;border:1px solid #154360">المعنى</th>
        <th style="padding:8px;border:1px solid #154360">الإجراء التالي</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;color:#2563eb;font-weight:bold">مفتوح</td><td style="padding:7px;border:1px solid #e2e8f0">تم استلام البلاغ ولم يُعيَّن</td><td style="padding:7px;border:1px solid #e2e8f0">تعيين فني وإنشاء مهمة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;color:#d97706;font-weight:bold">قيد المعالجة</td><td style="padding:7px;border:1px solid #e2e8f0">الفني يعمل على الحل</td><td style="padding:7px;border:1px solid #e2e8f0">انتظار الفني</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;color:#16a34a;font-weight:bold">مُغلق</td><td style="padding:7px;border:1px solid #e2e8f0">تم حل المشكلة وإغلاق البلاغ</td><td style="padding:7px;border:1px solid #e2e8f0">—</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;color:#dc2626;font-weight:bold">ملغي</td><td style="padding:7px;border:1px solid #e2e8f0">تم إلغاء البلاغ</td><td style="padding:7px;border:1px solid #e2e8f0">—</td></tr>
</table>';

$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 5 — إضافة مهمة
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('5', 'إدارة المهام — إضافة مهمة وتعيينها', '✅');
$s .= screenshotPlaceholder('نموذج إضافة مهمة — pages/forms/add-task.php');

$s .= subTitle('حقول المهمة');
$s .= fieldTable([
    ['name'=>'البلاغ المرتبط','type'=>'بحث','req'=>true,'desc'=>'ابحث بالرقم أو العنوان لربط المهمة ببلاغ موجود','example'=>'TK-2026-0042'],
    ['name'=>'عنوان المهمة','type'=>'نص','req'=>true,'desc'=>'عنوان واضح يصف ما يجب إنجازه','example'=>'إصلاح دائرة الكهرباء في مستودع B2'],
    ['name'=>'التفاصيل','type'=>'نص طويل','req'=>false,'desc'=>'وصف تفصيلي للمهمة والخطوات المطلوبة','example'=>'فحص اللوحة الكهربائية وتبديل القاطع المحروق'],
    ['name'=>'الموظف المعيَّن','type'=>'بحث','req'=>true,'desc'=>'ابحث باسم الموظف أو كوده لتعيين المهمة','example'=>'أحمد محمد (F-001)'],
    ['name'=>'الموعد النهائي','type'=>'تاريخ','req'=>true,'desc'=>'آخر موعد لإنجاز المهمة (يحسب من SLA تلقائياً)','example'=>'2026-06-27'],
    ['name'=>'الأولوية','type'=>'قائمة','req'=>true,'desc'=>'تتوارث من البلاغ المرتبط ويمكن تعديلها','example'=>'عالية'],
    ['name'=>'ملاحظات','type'=>'نص','req'=>false,'desc'=>'أي ملاحظات إضافية للفني','example'=>'تنسيق مع مشرف المستودع قبل البدء'],
]);

$s .= subTitle('خطوات إضافة مهمة');
$s .= stepBox([
    'انتقل إلى <strong>المهام</strong> → <strong>إضافة مهمة</strong>',
    'في حقل <strong>البلاغ المرتبط</strong>: اكتب جزء من رقم البلاغ أو عنوانه — ستظهر النتائج فوراً',
    'انقر على البلاغ المطلوب لاختياره (سيُملأ الحقل تلقائياً)',
    'اكتب <strong>عنوان المهمة</strong> بوضوح',
    'في حقل <strong>الموظف المعيَّن</strong>: ابحث باسم الموظف أو رقمه',
    'حدد <strong>الموعد النهائي</strong>',
    'انقر <strong>"حفظ المهمة"</strong>',
    'سيتلقى الموظف المعيَّن إشعاراً تلقائياً عبر الشات الداخلي',
]);

$s .= infoBox('<strong>إشعار تلقائي:</strong> عند حفظ المهمة، يصل إشعار فوري للموظف المعيَّن في الشات الداخلي يتضمن اسم المهمة ورقمها وموعدها النهائي.', 'success');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 6 — عرض وتعديل المهام
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('6', 'إدارة المهام — عرض وتعديل', '📝');
$s .= screenshotPlaceholder('قائمة المهام — pages/tables/show-tasks.php');

$s .= subTitle('فلاتر قائمة المهام');
$s .= fieldTable([
    ['name'=>'الفرع','type'=>'قائمة','desc'=>'تصفية المهام حسب الفرع','example'=>'الفرع الرئيسي'],
    ['name'=>'القسم','type'=>'قائمة','desc'=>'تصفية حسب القسم الوظيفي','example'=>'الصيانة'],
    ['name'=>'الأولوية','type'=>'قائمة','desc'=>'عاجل/عالي/متوسط/منخفض','example'=>'عاجل'],
    ['name'=>'الحالة','type'=>'قائمة','desc'=>'مفتوح/قيد التنفيذ/مُغلق','example'=>'مفتوح'],
]);

$s .= subTitle('شاشة تعديل المهمة وإعادة التعيين');
$s .= screenshotPlaceholder('تعديل مهمة — pages/tables/edit-task.php');

$s .= subTitle('خطوات إعادة تعيين مهمة لفني آخر');
$s .= stepBox([
    'في قائمة المهام، انقر أيقونة <strong>التعديل ✏</strong> بجانب المهمة',
    'ستظهر شاشة التعديل مع كارت البلاغ في الأعلى',
    'استخدم <strong>فلتر الفرع</strong> لتضييق نطاق البحث',
    'استخدم <strong>فلتر الدور</strong> لعرض فنيين بتخصص معين',
    'انقر على <strong>بطاقة الفني</strong> المراد تعيينه (تتحول للأزرق)',
    'انقر <strong>"حفظ التعديلات"</strong>',
    'سيصل إشعار للفني الجديد والفني السابق تلقائياً',
]);

$s .= infoBox('يمكن تغيير حالة المهمة من صفحة العرض مباشرةً دون الدخول لصفحة التعديل.', 'info');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 7 — رفع وثيقة
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('7', 'إدارة الوثائق — رفع وثيقة جديدة', '📄');
$s .= screenshotPlaceholder('إضافة وثيقة — pages/forms/add-document.php');

$s .= subTitle('حقول نموذج الوثيقة');
$s .= fieldTable([
    ['name'=>'رقم الوثيقة','type'=>'نص','req'=>false,'desc'=>'يُوَلَّد تلقائياً بصيغة DOC-YYYY-XXXX إن تُرك فارغاً','example'=>'DOC-2026-0015'],
    ['name'=>'عنوان الوثيقة','type'=>'نص','req'=>true,'desc'=>'اسم الوثيقة الواضح والمميز','example'=>'سياسة الإجازات السنوية 2026'],
    ['name'=>'نوع الوثيقة','type'=>'قائمة','req'=>true,'desc'=>'تصنيف الوثيقة (سياسة / لائحة / عقد / تقرير ...)','example'=>'سياسة'],
    ['name'=>'التصنيف','type'=>'قائمة','req'=>true,'desc'=>'التصنيف الإداري للوثيقة','example'=>'شؤون الموارد البشرية'],
    ['name'=>'القسم المسؤول','type'=>'قائمة','req'=>false,'desc'=>'القسم صاحب الوثيقة','example'=>'الموارد البشرية'],
    ['name'=>'الوصف','type'=>'محرر','req'=>false,'desc'=>'وصف تفصيلي بمحرر النصوص (يدعم تنسيق نص كامل)','example'=>'تحدد هذه السياسة شروط الإجازة...'],
    ['name'=>'سياسة الاعتماد','type'=>'قائمة','req'=>false,'desc'=>'تحديد سير عمل الاعتماد (يُرسل إشعارات للمعتمِدين)','example'=>'اعتماد ثنائي - إدارة و HR'],
    ['name'=>'الحالة','type'=>'قائمة','req'=>true,'desc'=>'مسودة (للتحرير) / منشور (للعرض العام)','example'=>'مسودة'],
    ['name'=>'الملف','type'=>'رفع','req'=>true,'desc'=>'الملف الرسمي (PDF/Word/Excel — حتى 30MB)','example'=>'سياسة_الاجازات.pdf'],
]);

$s .= subTitle('أنواع الملفات المقبولة');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:7px;border:1px solid #154360">الامتداد</th>
        <th style="padding:7px;border:1px solid #154360">النوع</th>
        <th style="padding:7px;border:1px solid #154360">الحجم الأقصى</th>
    </tr>
    <tr><td style="padding:6px;border:1px solid #e2e8f0">.pdf</td><td style="padding:6px;border:1px solid #e2e8f0">مستند PDF</td><td style="padding:6px;border:1px solid #e2e8f0">30 MB</td></tr>
    <tr style="background:#f8fbff"><td style="padding:6px;border:1px solid #e2e8f0">.doc / .docx</td><td style="padding:6px;border:1px solid #e2e8f0">Microsoft Word</td><td style="padding:6px;border:1px solid #e2e8f0">30 MB</td></tr>
    <tr><td style="padding:6px;border:1px solid #e2e8f0">.xls / .xlsx</td><td style="padding:6px;border:1px solid #e2e8f0">Microsoft Excel</td><td style="padding:6px;border:1px solid #e2e8f0">30 MB</td></tr>
    <tr style="background:#f8fbff"><td style="padding:6px;border:1px solid #e2e8f0">.ppt / .pptx</td><td style="padding:6px;border:1px solid #e2e8f0">Microsoft PowerPoint</td><td style="padding:6px;border:1px solid #e2e8f0">30 MB</td></tr>
    <tr><td style="padding:6px;border:1px solid #e2e8f0">.txt / .csv</td><td style="padding:6px;border:1px solid #e2e8f0">نص / بيانات</td><td style="padding:6px;border:1px solid #e2e8f0">30 MB</td></tr>
</table>';

$s .= infoBox('<strong>الأمان:</strong> يتحقق النظام من نوع الملف الحقيقي (وليس فقط الامتداد) لمنع رفع الملفات الضارة.', 'warning');

$s .= subTitle('سير الاعتماد');
$s .= stepBox([
    'عند اختيار سياسة اعتماد، تُرسَل إشعارات تلقائية لجميع المعتمِدين',
    'المعتمِد الأول يعتمد الوثيقة من صفحة عرض الوثائق',
    'عند اعتماد آخر مرحلة، تتغير الحالة تلقائياً إلى <strong>"معتمدة"</strong>',
    'الوثيقة المعتمدة لا يمكن تعديلها إلا بعد إعادتها لحالة المسودة',
]);
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 8 — عرض الوثائق (Server-Side)
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('8', 'إدارة الوثائق — عرض وبحث (50,000 وثيقة)', '🔍');
$s .= screenshotPlaceholder('قائمة الوثائق — pages/tables/show-documents.php');

$s .= subTitle('أدوات الفلترة');
$s .= fieldTable([
    ['name'=>'بحث نصي','type'=>'بحث','desc'=>'يبحث في رقم الوثيقة، العنوان، النوع، التصنيف، القسم، اسم المعتمِد','example'=>'سياسة إجازات'],
    ['name'=>'النوع','type'=>'قائمة','desc'=>'تصفية حسب نوع الوثيقة','example'=>'عقد'],
    ['name'=>'التصنيف','type'=>'قائمة','desc'=>'تصفية حسب التصنيف الإداري','example'=>'HR'],
    ['name'=>'القسم','type'=>'قائمة','desc'=>'تصفية حسب القسم المسؤول','example'=>'الموارد البشرية'],
    ['name'=>'الحالة','type'=>'قائمة','desc'=>'مسودة / معتمدة / مؤرشفة / ملغاة','example'=>'معتمدة'],
]);

$s .= infoBox('<strong>الأداء:</strong> قائمة الوثائق تعمل بنظام Server-Side DataTables — تحمّل 25 وثيقة فقط في كل مرة بدلاً من تحميل 50,000 وثيقة دفعة واحدة، مما يجعلها فائقة السرعة.', 'success');

$s .= subTitle('إجراءات الوثيقة (حسب الصلاحية)');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الإجراء</th>
        <th style="padding:8px;border:1px solid #154360">الشرط</th>
        <th style="padding:8px;border:1px solid #154360">التأثير</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">👁 عرض</td><td style="padding:7px;border:1px solid #e2e8f0">can_view</td><td style="padding:7px;border:1px solid #e2e8f0">عرض تفاصيل الوثيقة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">📥 تحميل PDF</td><td style="padding:7px;border:1px solid #e2e8f0">can_view + ملف PDF</td><td style="padding:7px;border:1px solid #e2e8f0">تحميل الملف الأصلي</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">✏ تعديل</td><td style="padding:7px;border:1px solid #e2e8f0">can_edit + حالة مسودة</td><td style="padding:7px;border:1px solid #e2e8f0">تعديل بيانات الوثيقة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">✅ اعتماد</td><td style="padding:7px;border:1px solid #e2e8f0">can_approve + حالة مسودة</td><td style="padding:7px;border:1px solid #e2e8f0">تحويل لحالة "معتمدة"</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">📦 أرشفة</td><td style="padding:7px;border:1px solid #e2e8f0">can_archive + حالة معتمدة</td><td style="padding:7px;border:1px solid #e2e8f0">تحويل للأرشيف</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">🗑 حذف</td><td style="padding:7px;border:1px solid #e2e8f0">can_delete</td><td style="padding:7px;border:1px solid #e2e8f0">حذف نهائي</td></tr>
</table>';
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 9 — الأصول
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('9', 'إدارة الأصول — إضافة وتتبع', '🖥️');
$s .= screenshotPlaceholder('إدارة الأصول — pages/tables/show-assets.php');

$s .= subTitle('حقول إضافة أصل');
$s .= fieldTable([
    ['name'=>'كود الأصل','type'=>'نص','req'=>false,'desc'=>'يُوَلَّد تلقائياً بصيغة AST-XXXXX إن تُرك فارغاً','example'=>'AST-00042'],
    ['name'=>'اسم الأصل','type'=>'نص','req'=>true,'desc'=>'الاسم الكامل للجهاز أو المعدة','example'=>'طابعة HP LaserJet 1020'],
    ['name'=>'التصنيف','type'=>'قائمة','req'=>true,'desc'=>'تصنيف الأصل: أجهزة حاسب / طابعات / أثاث / سيارات ...','example'=>'طابعات'],
    ['name'=>'الرقم التسلسلي','type'=>'نص','req'=>false,'desc'=>'الرقم التسلسلي من على الجهاز','example'=>'CN12AB34567'],
    ['name'=>'الموديل','type'=>'نص','req'=>false,'desc'=>'موديل الجهاز','example'=>'LaserJet Pro M404n'],
    ['name'=>'الشركة المصنّعة','type'=>'نص','req'=>false,'desc'=>'اسم الشركة المصنّعة','example'=>'HP'],
    ['name'=>'الفرع','type'=>'قائمة','req'=>false,'desc'=>'الفرع الذي يوجد فيه الأصل','example'=>'الفرع الرئيسي'],
    ['name'=>'القسم','type'=>'قائمة','req'=>false,'desc'=>'القسم المستخدم للأصل','example'=>'قسم المحاسبة'],
    ['name'=>'الغرفة/الموقع','type'=>'نص','req'=>false,'desc'=>'الموقع الدقيق داخل الفرع','example'=>'مكتب 204 - الطابق الثاني'],
    ['name'=>'الحالة','type'=>'قائمة','req'=>true,'desc'=>'نشط / تحت الصيانة / متقاعد / مفقود','example'=>'نشط'],
    ['name'=>'تاريخ الشراء','type'=>'تاريخ','req'=>false,'desc'=>'تاريخ استلام الأصل','example'=>'2024-03-15'],
    ['name'=>'سعر الشراء','type'=>'رقم','req'=>false,'desc'=>'قيمة الأصل بالريال السعودي','example'=>'1,250.00'],
    ['name'=>'انتهاء الضمان','type'=>'تاريخ','req'=>false,'desc'=>'تاريخ انتهاء ضمان الشركة المصنّعة','example'=>'2026-03-15'],
    ['name'=>'مسؤول عنه','type'=>'قائمة','req'=>false,'desc'=>'المستخدم المسؤول عن الأصل','example'=>'سلمى العمري'],
    ['name'=>'صورة','type'=>'صورة','req'=>false,'desc'=>'صورة الأصل (JPG/PNG - حتى 5MB)','example'=>'—'],
]);

$s .= subTitle('ميزة QR Code');
$s .= '<p style="font-size:9.5pt;color:#374151">يُولَّد QR Code تلقائياً لكل أصل يحتوي على جميع بياناته. يمكن طباعته ولصقه على الجهاز لسهولة التعرف عليه.</p>';

$s .= infoBox('عند النقر على أيقونة QR بجانب أي أصل، تظهر نافذة تحتوي على الكود قابل للتحميل والطباعة.', 'info');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 10 — الصيانة
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('10', 'الصيانة الدورية — جدولة وتسجيل', '🔧');
$s .= screenshotPlaceholder('جدول الصيانة — pages/tables/show-maintenance.php');

$s .= subTitle('إضافة جدول صيانة دورية');
$s .= fieldTable([
    ['name'=>'الأصل','type'=>'قائمة','req'=>true,'desc'=>'اختيار الجهاز أو المعدة من قائمة الأصول','example'=>'AST-00042 - طابعة HP'],
    ['name'=>'عنوان الصيانة','type'=>'نص','req'=>true,'desc'=>'وصف نوع الصيانة المطلوبة','example'=>'صيانة دورية شهرية - تنظيف وفحص'],
    ['name'=>'نوع التكرار','type'=>'قائمة','req'=>true,'desc'=>'يومي / أسبوعي / شهري / ربع سنوي / سنوي / مرة واحدة','example'=>'شهري'],
    ['name'=>'التاريخ القادم','type'=>'تاريخ','req'=>true,'desc'=>'تاريخ الصيانة القادمة — يُحسب تلقائياً بعد تسجيل الإنجاز','example'=>'2026-07-15'],
    ['name'=>'الفني المكلّف','type'=>'قائمة','req'=>false,'desc'=>'الفني المسؤول عن التنفيذ (يصله إشعار)','example'=>'خالد الزهراني'],
    ['name'=>'التكلفة المتوقعة','type'=>'رقم','req'=>false,'desc'=>'التكلفة التقديرية بالريال','example'=>'200.00'],
    ['name'=>'التعليمات','type'=>'نص طويل','req'=>false,'desc'=>'خطوات تفصيلية للصيانة','example'=>'1- إيقاف الطابعة 2- تنظيف اسطوانة الحبر...'],
]);

$s .= subTitle('تسجيل صيانة مُنجزة');
$s .= fieldTable([
    ['name'=>'تاريخ التنفيذ','type'=>'تاريخ','req'=>true,'desc'=>'تاريخ إتمام الصيانة الفعلي','example'=>'2026-06-20'],
    ['name'=>'التكلفة الفعلية','type'=>'رقم','req'=>false,'desc'=>'التكلفة الفعلية بعد التنفيذ','example'=>'180.00'],
    ['name'=>'مدة التنفيذ','type'=>'رقم','req'=>false,'desc'=>'الوقت المستغرق بالدقائق','example'=>'45'],
    ['name'=>'ملاحظات','type'=>'نص','req'=>false,'desc'=>'ما تم فعله وأي ملاحظات فنية','example'=>'تم تبديل فلتر الهواء وتنظيف الدرج'],
]);

$s .= infoBox('عند تسجيل الإنجاز، يُحسب تاريخ الصيانة القادمة تلقائياً بحسب نوع التكرار المحدد.', 'success');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 11 — قاعدة المعرفة
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('11', 'قاعدة المعرفة — إضافة وتصفح', '📚');
$s .= screenshotPlaceholder('قاعدة المعرفة — pages/tables/show-kb.php');

$s .= subTitle('إضافة مقالة جديدة');
$s .= screenshotPlaceholder('إضافة مقالة — pages/forms/add-kb-article.php');

$s .= fieldTable([
    ['name'=>'العنوان','type'=>'نص','req'=>true,'desc'=>'عنوان المقالة واضح ومحدد','example'=>'كيفية إعادة تشغيل الطابعة عند توقفها'],
    ['name'=>'التصنيف','type'=>'قائمة','req'=>true,'desc'=>'اختر التصنيف المناسب من القائمة الجانبية','example'=>'استكشاف الأخطاء'],
    ['name'=>'الملخص','type'=>'نص','req'=>false,'desc'=>'وصف مختصر يظهر في نتائج البحث','example'=>'خطوات سريعة لإعادة تشغيل أي طابعة...'],
    ['name'=>'المحتوى','type'=>'محرر','req'=>true,'desc'=>'المحتوى الكامل بمحرر Summernote (يدعم صور + جداول + كود)','example'=>'—'],
    ['name'=>'الوسوم','type'=>'نص','req'=>false,'desc'=>'كلمات بحثية مفصولة بفاصلة لتحسين البحث','example'=>'طابعة, صيانة, إعادة تشغيل, ورقة عالقة'],
    ['name'=>'الحالة','type'=>'قائمة','req'=>true,'desc'=>'مسودة (غير مرئية) / منشور (مرئية للجميع)','example'=>'منشور'],
    ['name'=>'مقالة مميزة','type'=>'مربع','req'=>false,'desc'=>'تظهر بأولوية في القائمة مع نجمة ذهبية','example'=>'—'],
]);

$s .= subTitle('التصنيفات الافتراضية');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">التصنيف</th>
        <th style="padding:8px;border:1px solid #154360">الغرض</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">❓ الأسئلة الشائعة</td><td style="padding:7px;border:1px solid #e2e8f0">الأسئلة المتكررة وإجاباتها</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">📖 دليل المستخدم</td><td style="padding:7px;border:1px solid #e2e8f0">تعليمات استخدام الأنظمة والأجهزة</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">🔧 استكشاف الأخطاء</td><td style="padding:7px;border:1px solid #e2e8f0">حل المشكلات الشائعة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">📋 السياسات والإجراءات</td><td style="padding:7px;border:1px solid #e2e8f0">اللوائح والسياسات الرسمية</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">📢 التحديثات والأخبار</td><td style="padding:7px;border:1px solid #e2e8f0">آخر الإعلانات والتحديثات</td></tr>
</table>';

$s .= subTitle('تقييم المقالات');
$s .= '<p style="font-size:9.5pt;color:#374151">يمكن لكل مستخدم تقييم المقالة مرة واحدة بـ <strong>👍 مفيد</strong> أو <strong>👎 غير مفيد</strong>. يُعرض مجموع التقييمات ونسبة الرضا لمساعدة المحررين في تحسين المحتوى.</p>';
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 12 — الموظفون
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('12', 'إدارة الموظفين', '👥');
$s .= screenshotPlaceholder('إدارة الموظفين — pages/tables/show-employees.php');
$s .= infoBox('الموظفون في هذه الشاشة هم موظفو نظام إدارة الوثائق (DMS) — المعتمِدون وأصحاب التوقيعات الرسمية. يختلفون عن مستخدمي النظام.', 'warning');

$s .= subTitle('حقول إضافة موظف');
$s .= fieldTable([
    ['name'=>'كود الموظف','type'=>'نص','req'=>true,'desc'=>'المعرّف الفريد للموظف في المنظمة','example'=>'EMP-2024-001'],
    ['name'=>'الاسم الكامل','type'=>'نص','req'=>true,'desc'=>'الاسم الرباعي للموظف','example'=>'سلمى أحمد العمري'],
    ['name'=>'المسمى الوظيفي','type'=>'نص','req'=>false,'desc'=>'الوظيفة الرسمية','example'=>'مدير الموارد البشرية'],
    ['name'=>'القسم','type'=>'قائمة','req'=>false,'desc'=>'القسم الوظيفي','example'=>'الموارد البشرية'],
    ['name'=>'البريد الإلكتروني','type'=>'Email','req'=>false,'desc'=>'البريد الرسمي للموظف','example'=>'salma@company.com'],
    ['name'=>'الهاتف','type'=>'نص','req'=>false,'desc'=>'رقم الجوال للتواصل','example'=>'+966501234567'],
    ['name'=>'صلاحية التوقيع','type'=>'مربع','req'=>false,'desc'=>'تفعيل صلاحية التوقيع الإلكتروني على الوثائق','example'=>'✓'],
    ['name'=>'ربط بحساب مستخدم','type'=>'قائمة','req'=>false,'desc'=>'ربط الموظف بحساب نظام موجود لتفعيل الإشعارات','example'=>'salma@company.com'],
    ['name'=>'صورة التوقيع','type'=>'صورة','req'=>false,'desc'=>'رفع صورة التوقيع الشخصي (PNG - خلفية شفافة مفضّلة)','example'=>'—'],
]);

$s .= infoBox('<strong>ربط الموظف بالحساب:</strong> لاستقبال إشعارات اعتماد الوثائق عبر الشات الداخلي، يجب ربط الموظف بحساب مستخدم في النظام.', 'info');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 13 — المستخدمون
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('13', 'إدارة المستخدمين والأدوار', '👤');
$s .= screenshotPlaceholder('المستخدمون — pages/tables/show-users.php');

$s .= subTitle('حقول إضافة مستخدم');
$s .= fieldTable([
    ['name'=>'الاسم الكامل','type'=>'نص','req'=>true,'desc'=>'اسم المستخدم كما سيظهر في النظام','example'=>'محمد علي الغامدي'],
    ['name'=>'البريد الإلكتروني','type'=>'Email','req'=>true,'desc'=>'يُستخدم لتسجيل الدخول — يجب أن يكون فريداً','example'=>'m.alghamdi@company.com'],
    ['name'=>'كلمة المرور','type'=>'Password','req'=>true,'desc'=>'8 أحرف على الأقل تشمل أرقام وحروف','example'=>'Secure@2026'],
    ['name'=>'رقم الجوال','type'=>'نص','req'=>false,'desc'=>'رقم للتواصل وإشعارات SMS','example'=>'+966501234567'],
    ['name'=>'الدور','type'=>'قائمة','req'=>true,'desc'=>'دور المستخدم في النظام','example'=>'فني صيانة'],
    ['name'=>'الصورة الشخصية','type'=>'صورة','req'=>false,'desc'=>'صورة بروفايل المستخدم (JPG/PNG - حتى 5MB)','example'=>'—'],
]);

$s .= subTitle('الأدوار المتاحة');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الدور</th>
        <th style="padding:8px;border:1px solid #154360">الصلاحيات الافتراضية</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">MainAdmin</td><td style="padding:7px;border:1px solid #e2e8f0">كامل الصلاحيات + إدارة النظام</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">Admin</td><td style="padding:7px;border:1px solid #e2e8f0">إدارة البلاغات والمهام والوثائق</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">Technician</td><td style="padding:7px;border:1px solid #e2e8f0">عرض وتنفيذ المهام المعيّنة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">Viewer</td><td style="padding:7px;border:1px solid #e2e8f0">عرض فقط بدون تعديل</td></tr>
</table>';

$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 14 — الصلاحيات
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('14', 'نظام الصلاحيات', '🔒');
$s .= screenshotPlaceholder('تعيين الصلاحيات — pages/tables/assign-permissions.php');

$s .= subTitle('أنواع الصلاحيات لكل صفحة');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الصلاحية</th>
        <th style="padding:8px;border:1px solid #154360">المعنى</th>
        <th style="padding:8px;border:1px solid #154360">تُطبَّق على</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#2563eb">عرض</td><td style="padding:7px;border:1px solid #e2e8f0">رؤية الصفحة ومحتوياتها</td><td style="padding:7px;border:1px solid #e2e8f0">كل الصفحات</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#16a34a">إضافة</td><td style="padding:7px;border:1px solid #e2e8f0">إنشاء سجلات جديدة</td><td style="padding:7px;border:1px solid #e2e8f0">صفحات البيانات</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#d97706">تعديل</td><td style="padding:7px;border:1px solid #e2e8f0">تعديل السجلات الموجودة</td><td style="padding:7px;border:1px solid #e2e8f0">صفحات البيانات</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#dc2626">حذف</td><td style="padding:7px;border:1px solid #e2e8f0">حذف السجلات نهائياً</td><td style="padding:7px;border:1px solid #e2e8f0">صفحات البيانات</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#7c3aed">اعتماد</td><td style="padding:7px;border:1px solid #e2e8f0">اعتماد الوثائق وتغيير حالتها</td><td style="padding:7px;border:1px solid #e2e8f0">إدارة الوثائق</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#0891b2">أرشفة</td><td style="padding:7px;border:1px solid #e2e8f0">نقل الوثيقة للأرشيف</td><td style="padding:7px;border:1px solid #e2e8f0">إدارة الوثائق</td></tr>
</table>';

$s .= subTitle('خطوات تعيين صلاحيات مستخدم');
$s .= stepBox([
    'انتقل إلى <strong>الإعدادات</strong> → <strong>تعيين الصلاحيات</strong>',
    'اختر المستخدم من القائمة المنسدلة',
    'ستظهر قائمة بجميع صفحات النظام',
    'لكل صفحة: ضع علامة ✓ على الصلاحيات المطلوبة',
    'استخدم <strong>"منح الكل"</strong> لمنح كامل الصلاحيات لصفحة معينة',
    'استخدم <strong>"سحب الكل"</strong> لإزالة كامل الصلاحيات',
    'انقر <strong>"حفظ الصلاحيات"</strong>',
]);

$s .= infoBox('<strong>ملاحظة:</strong> التغييرات تسري فوراً دون الحاجة لتسجيل خروج المستخدم وإعادة دخوله.', 'info');
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 15 — الشات والإشعارات
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('15', 'الشات والإشعارات الداخلية', '💬');
$s .= screenshotPlaceholder('الشات الداخلي — admin/contact.php');

$s .= subTitle('الوصول للشات');
$s .= '<p style="font-size:9.5pt;color:#374151">انقر على <strong>أيقونة الرسائل</strong> في شريط الرأس العلوي، أو انتقل مباشرة للرابط: <code>admin/contact.php</code></p>';

$s .= subTitle('مجموعات الشات في الشريط الجانبي');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">المجموعة</th>
        <th style="padding:8px;border:1px solid #154360">الوصف</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">مستخدمو النظام</td><td style="padding:7px;border:1px solid #e2e8f0">جميع المستخدمين النشطين في النظام</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">الموظفون المرتبطون</td><td style="padding:7px;border:1px solid #e2e8f0">موظفو DMS المرتبطون بحساب مستخدم</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold;color:#94a3b8">الموظفون غير المرتبطين</td><td style="padding:7px;border:1px solid #e2e8f0">موظفو DMS بدون حساب (عرض فقط)</td></tr>
</table>';

$s .= subTitle('أنواع الرسائل');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">نوع الرسالة</th>
        <th style="padding:8px;border:1px solid #154360">الوصف</th>
        <th style="padding:8px;border:1px solid #154360">المصدر</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">💬 رسالة عادية</td><td style="padding:7px;border:1px solid #e2e8f0">تواصل مباشر بين المستخدمين</td><td style="padding:7px;border:1px solid #e2e8f0">يدوي من المستخدم</td></tr>
    <tr style="background:#eef2ff"><td style="padding:7px;border:1px solid #e2e8f0">📋 إشعار مهمة</td><td style="padding:7px;border:1px solid #e2e8f0">إشعار تلقائي عند تعيين مهمة للمستخدم</td><td style="padding:7px;border:1px solid #e2e8f0">تلقائي عند إضافة مهمة</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">📄 إشعار وثيقة</td><td style="padding:7px;border:1px solid #e2e8f0">إشعار عند الحاجة لاعتماد وثيقة</td><td style="padding:7px;border:1px solid #e2e8f0">تلقائي عند رفع وثيقة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">🖼 صورة</td><td style="padding:7px;border:1px solid #e2e8f0">إرسال صور</td><td style="padding:7px;border:1px solid #e2e8f0">مرفق من المستخدم</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">🎤 رسالة صوتية</td><td style="padding:7px;border:1px solid #e2e8f0">تسجيل وإرسال رسالة صوتية</td><td style="padding:7px;border:1px solid #e2e8f0">تسجيل مباشر</td></tr>
</table>';

$s .= subTitle('إعدادات الإشعارات للموظفين');
$s .= '<p style="font-size:9.5pt;color:#374151">في صفحة <strong>الإشعارات</strong> (pages/tables/notifications.php) يمكن تفعيل أو تعطيل قنوات الإشعار لكل موظف:</p>';
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">القناة</th><th style="padding:8px;border:1px solid #154360">الحالة</th><th style="padding:8px;border:1px solid #154360">الشرط</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">💬 شات داخلي</td><td style="padding:7px;border:1px solid #e2e8f0">يعمل دائماً</td><td style="padding:7px;border:1px solid #e2e8f0">—</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0">📱 SMS</td><td style="padding:7px;border:1px solid #e2e8f0">قابل للتفعيل</td><td style="padding:7px;border:1px solid #e2e8f0">إعداد Msegat في config/notify.php</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0">🟢 WhatsApp</td><td style="padding:7px;border:1px solid #e2e8f0">قابل للتفعيل</td><td style="padding:7px;border:1px solid #e2e8f0">إعداد Unifonic في config/notify.php</td></tr>
</table>';
$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 16 — التقارير والسجلات
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('16', 'التقارير والسجلات', '📈');

$s .= subTitle('التقارير المتاحة');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">التقرير</th>
        <th style="padding:8px;border:1px solid #154360">الصفحة</th>
        <th style="padding:8px;border:1px solid #154360">المحتوى</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">تقرير البلاغات</td><td style="padding:7px;border:1px solid #e2e8f0;font-size:8pt">report-requests.php</td><td style="padding:7px;border:1px solid #e2e8f0">تقرير مفصّل بكل البلاغات مع فلترة متقدمة وطباعة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">تقرير المهام</td><td style="padding:7px;border:1px solid #e2e8f0;font-size:8pt">report-tasks.php</td><td style="padding:7px;border:1px solid #e2e8f0">تقرير المهام مع إحصاءات الإنجاز والتأخير</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">تقرير تعيينات الفنيين</td><td style="padding:7px;border:1px solid #e2e8f0;font-size:8pt">reports-assignments.php</td><td style="padding:7px;border:1px solid #e2e8f0">عدد المهام لكل فني مع معدل الإنجاز</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-weight:bold">سجل الأعمال</td><td style="padding:7px;border:1px solid #e2e8f0;font-size:8pt">show-logs.php</td><td style="padding:7px;border:1px solid #e2e8f0">سجل كامل لكل إجراء قام به كل مستخدم</td></tr>
</table>';

$s .= subTitle('سجل الأعمال (Audit Log)');
$s .= screenshotPlaceholder('سجل الأعمال — pages/tables/show-logs.php');
$s .= fieldTable([
    ['name'=>'المستخدم','type'=>'نص','desc'=>'من قام بالإجراء','example'=>'محمد علي'],
    ['name'=>'الإجراء','type'=>'شارة','desc'=>'إنشاء / تعديل / حذف / اعتماد / أرشفة','example'=>'إنشاء'],
    ['name'=>'النوع','type'=>'نص','desc'=>'نوع السجل الذي تم التعامل معه','example'=>'ticket'],
    ['name'=>'المعرّف','type'=>'رقم','desc'=>'رقم السجل المتأثر','example'=>'42'],
    ['name'=>'التاريخ والوقت','type'=>'تاريخ','desc'=>'توقيت الإجراء بالثانية','example'=>'2026-06-26 10:30:45'],
    ['name'=>'عنوان IP','type'=>'نص','desc'=>'عنوان الجهاز الذي نفّذ منه الإجراء','example'=>'192.168.1.100'],
]);

$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 17 — الإعدادات
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('17', 'إعدادات النظام والمظهر', '⚙️');

$s .= subTitle('17.1 الإعدادات العامة (system-settings.php)');
$s .= fieldTable([
    ['name'=>'اسم النظام','type'=>'نص','req'=>true,'desc'=>'يظهر في الرأس والتقارير وعنوان المتصفح','example'=>'نظام وَصْل - شركة المستقبل'],
    ['name'=>'البريد الإداري','type'=>'Email','req'=>false,'desc'=>'بريد مسؤول النظام للإشعارات الحرجة','example'=>'admin@company.com'],
    ['name'=>'رقم التواصل','type'=>'نص','req'=>false,'desc'=>'هاتف الدعم التقني','example'=>'+966112345678'],
    ['name'=>'العنوان','type'=>'نص','req'=>false,'desc'=>'عنوان الشركة يظهر في التقارير','example'=>'الرياض، المملكة العربية السعودية'],
    ['name'=>'شعار النظام','type'=>'صورة','req'=>false,'desc'=>'الشعار الرسمي (PNG/JPG - حتى 2MB)','example'=>'logo.png'],
    ['name'=>'وضع الصيانة','type'=>'مربع','req'=>false,'desc'=>'إيقاف النظام مؤقتاً مع رسالة توضيحية','example'=>'—'],
]);

$s .= subTitle('17.2 المظهر والألوان (theme-settings.php)');
$s .= fieldTable([
    ['name'=>'لون الشريط الجانبي','type'=>'لون','desc'=>'لون خلفية القائمة الجانبية اليسرى','example'=>'#1a2a4a'],
    ['name'=>'اللون الأساسي','type'=>'لون','desc'=>'اللون الرئيسي لشريط عنوان الصفحات','example'=>'#1a5276'],
    ['name'=>'اللون الثانوي','type'=>'لون','desc'=>'لون gradient شريط العنوان','example'=>'#2980b9'],
    ['name'=>'خط النظام','type'=>'قائمة','desc'=>'خط الواجهة (Cairo / Tajawal / Almarai)','example'=>'Cairo'],
]);

$s .= infoBox('<strong>تطبيق فوري:</strong> جميع تغييرات الألوان تُطبَّق فوراً على كل صفحات النظام دون الحاجة لإعادة تشغيل.', 'success');

$s .= subTitle('17.3 إعدادات SLA (show-sla.php)');
$s .= fieldTable([
    ['name'=>'الأولوية','type'=>'نص','req'=>true,'desc'=>'مستوى الأولوية (عاجل/عالية/متوسطة/منخفضة)','example'=>'عاجل'],
    ['name'=>'وقت الاستجابة (ساعة)','type'=>'رقم','req'=>true,'desc'=>'الحد الأقصى للاستجابة الأولية بالساعات','example'=>'1'],
    ['name'=>'وقت الحل (ساعة)','type'=>'رقم','req'=>true,'desc'=>'الحد الأقصى للحل الكامل بالساعات','example'=>'4'],
]);

$s .= subTitle('17.4 النسخ الاحتياطية (system-buckup.php)');
$s .= stepBox([
    'انتقل إلى <strong>الإعدادات</strong> → <strong>النسخ الاحتياطية</strong>',
    'انقر <strong>"تحميل نسخة احتياطية"</strong>',
    'سيُنزَّل ملف SQL يحتوي على كاملة قاعدة البيانات',
    'احفظ الملف في مكان آمن خارج السيرفر',
    '<strong>التوصية:</strong> خذ نسخة يومياً وأسبوعياً وشهرياً',
]);

$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// القسم 18 — الملحق
// ════════════════════════════════════════════════════════════════════
$mpdf->AddPage();
$s = sectionTitle('18', 'ملحق — الرموز ومعاني الحالات', '📎');

$s .= subTitle('أيقونات الإجراءات');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360;width:15%">الأيقونة</th>
        <th style="padding:8px;border:1px solid #154360;width:20%">الاسم</th>
        <th style="padding:8px;border:1px solid #154360">الوظيفة</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;text-align:center;color:#0891b2">👁</td><td style="padding:7px;border:1px solid #e2e8f0">عرض</td><td style="padding:7px;border:1px solid #e2e8f0">عرض تفاصيل السجل</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;text-align:center;color:#d97706">✏</td><td style="padding:7px;border:1px solid #e2e8f0">تعديل</td><td style="padding:7px;border:1px solid #e2e8f0">تعديل بيانات السجل</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;text-align:center;color:#dc2626">🗑</td><td style="padding:7px;border:1px solid #e2e8f0">حذف</td><td style="padding:7px;border:1px solid #e2e8f0">حذف السجل نهائياً (يطلب تأكيد)</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;text-align:center;color:#16a34a">✅</td><td style="padding:7px;border:1px solid #e2e8f0">اعتماد</td><td style="padding:7px;border:1px solid #e2e8f0">اعتماد الوثيقة</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;text-align:center;color:#0891b2">📦</td><td style="padding:7px;border:1px solid #e2e8f0">أرشفة</td><td style="padding:7px;border:1px solid #e2e8f0">نقل للأرشيف</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;text-align:center">📥</td><td style="padding:7px;border:1px solid #e2e8f0">تحميل</td><td style="padding:7px;border:1px solid #e2e8f0">تحميل الملف</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;text-align:center">🖨</td><td style="padding:7px;border:1px solid #e2e8f0">طباعة</td><td style="padding:7px;border:1px solid #e2e8f0">طباعة السجل أو التقرير</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;text-align:center">🔲</td><td style="padding:7px;border:1px solid #e2e8f0">QR Code</td><td style="padding:7px;border:1px solid #e2e8f0">عرض وتحميل QR Code للأصل</td></tr>
</table>';

$s .= subTitle('اختصارات لوحة المفاتيح');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">الاختصار</th>
        <th style="padding:8px;border:1px solid #154360">الوظيفة</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0;font-family:monospace;font-weight:bold">Esc</td><td style="padding:7px;border:1px solid #e2e8f0">إغلاق النوافذ المنبثقة / الخروج من وضع ملء الشاشة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0;font-family:monospace;font-weight:bold">Ctrl + P</td><td style="padding:7px;border:1px solid #e2e8f0">طباعة الصفحة الحالية</td></tr>
</table>';

$s .= subTitle('معاني ألوان الشارات (Badges)');
$s .= '
<table width="100%" style="border-collapse:collapse;font-size:9.5pt;direction:rtl">
    <tr style="background:#1a5276;color:#fff">
        <th style="padding:8px;border:1px solid #154360">اللون</th>
        <th style="padding:8px;border:1px solid #154360">الدلالة</th>
        <th style="padding:8px;border:1px solid #154360">مثال</th>
    </tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0"><span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:10px">أحمر</span></td><td style="padding:7px;border:1px solid #e2e8f0">عاجل / حذف / خطأ</td><td style="padding:7px;border:1px solid #e2e8f0">أولوية عاجلة، تحذير حرج</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0"><span style="background:#d97706;color:#fff;padding:2px 8px;border-radius:10px">برتقالي</span></td><td style="padding:7px;border:1px solid #e2e8f0">تحذير / أولوية عالية</td><td style="padding:7px;border:1px solid #e2e8f0">مسودة، قيد المعالجة</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0"><span style="background:#16a34a;color:#fff;padding:2px 8px;border-radius:10px">أخضر</span></td><td style="padding:7px;border:1px solid #e2e8f0">نجاح / معتمد / مكتمل</td><td style="padding:7px;border:1px solid #e2e8f0">وثيقة معتمدة، مهمة مُنجزة</td></tr>
    <tr style="background:#f8fbff"><td style="padding:7px;border:1px solid #e2e8f0"><span style="background:#2563eb;color:#fff;padding:2px 8px;border-radius:10px">أزرق</span></td><td style="padding:7px;border:1px solid #e2e8f0">معلومات / مفتوح</td><td style="padding:7px;border:1px solid #e2e8f0">بلاغ مفتوح، معلومة</td></tr>
    <tr><td style="padding:7px;border:1px solid #e2e8f0"><span style="background:#64748b;color:#fff;padding:2px 8px;border-radius:10px">رمادي</span></td><td style="padding:7px;border:1px solid #e2e8f0">غير نشط / مؤرشف</td><td style="padding:7px;border:1px solid #e2e8f0">وثيقة مؤرشفة، مسودة</td></tr>
</table>';

$s .= '
<div style="margin-top:30px;background:#f0fdf4;border:2px solid #bbf7d0;
            border-radius:12px;padding:20px;text-align:center">
    <div style="font-size:14pt;font-weight:bold;color:#065f46;margin-bottom:8px">
        ✅ نهاية الدليل التنفيذي
    </div>
    <div style="font-size:9.5pt;color:#374151">
        لأي استفسار أو دعم تقني، يرجى التواصل مع مسؤول النظام<br>
        أو إرسال بلاغ من خلال نظام وَصْل
    </div>
    <div style="margin-top:12px;font-size:8.5pt;color:#94a3b8">
        نظام وَصْل CRM — الإصدار 2.0 — 2026<br>
        هذا الملف سري وخاص بالمنظمة
    </div>
</div>';

$mpdf->WriteHTML($s);

// ════════════════════════════════════════════════════════════════════
// حفظ PDF
// ════════════════════════════════════════════════════════════════════
$outputPath = dirname(__DIR__) . '/docs/wasl_user_manual.pdf';
$mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);

echo '<html><head><meta charset="utf-8">
<style>
body{font-family:Arial,sans-serif;background:#f0f2f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border-radius:16px;padding:40px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:480px}
h2{color:#27ae60}p{color:#555}a{background:#1a5276;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;margin-top:10px;font-size:14px}
</style></head><body>
<div class="box">
    <h2>✅ تم توليد الدليل بنجاح</h2>
    <p>دليل المستخدم التنفيذي — نظام وَصْل</p>
    <p style="font-size:12px;color:#888">تم الحفظ في: docs/wasl_user_manual.pdf</p>
    <a href="wasl_user_manual.pdf" download>⬇ تحميل PDF</a>
    <br><br>
    <a href="wasl_user_manual.pdf" target="_blank" style="background:#2980b9">🔍 فتح في المتصفح</a>
</div>
</body></html>';
