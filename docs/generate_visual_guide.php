<?php
/**
 * الدليل البصري التوضيحي — نظام وَصْل CRM
 * http://localhost/UltimatesolutionsCrm/docs/generate_visual_guide.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$mpdf = new \Mpdf\Mpdf([
    'mode'              => 'utf-8',
    'format'            => 'A4',
    'default_font_size' => 10,
    'default_font'      => 'dejavusans',
    'margin_left'       => 12,
    'margin_right'      => 12,
    'margin_top'        => 22,
    'margin_bottom'     => 18,
    'margin_header'     => 8,
    'margin_footer'     => 8,
    'tempDir'           => dirname(__DIR__) . '/storage/purifier_cache',
]);
$mpdf->SetDirectionality('rtl');
$mpdf->autoScriptToLang = true;
$mpdf->autoLangToFont   = true;

// ── Header / Footer ──────────────────────────────────────────────────
$mpdf->SetHeader('<table width="100%" style="border-bottom:2px solid #1a5276;padding-bottom:3px">
  <tr>
    <td style="text-align:right;font-size:8pt;color:#1a5276;font-weight:bold">الدليل البصري التوضيحي — نظام وَصْل CRM</td>
    <td style="text-align:left;font-size:8pt;color:#999">Visual User Guide v2.0</td>
  </tr></table>');
$mpdf->SetFooter('<table width="100%" style="border-top:1px solid #e2e8f0;padding-top:3px">
  <tr>
    <td style="text-align:right;font-size:7pt;color:#aaa">نظام وَصْل — سري وخاص</td>
    <td style="text-align:center;font-size:8pt;color:#555">{PAGENO} / {nbpg}</td>
    <td style="text-align:left;font-size:7pt;color:#aaa">2026</td>
  </tr></table>');

// ════════════════════════════════════════════════════
// دوال مساعدة
// ════════════════════════════════════════════════════

/** رسم شاشة وهمية مُشروحة */
function mockScreen(string $title, string $url, array $zones): string {
    $html = "
    <div style='border:2px solid #1a5276;border-radius:10px;overflow:hidden;
                font-size:8pt;margin:8px 0 14px;direction:ltr'>
      <!-- شريط المتصفح -->
      <div style='background:#2d3748;padding:5px 10px;display:flex;align-items:center;gap:6px'>
        <span style='width:9px;height:9px;border-radius:50%;background:#ff5f57;display:inline-block'></span>
        <span style='width:9px;height:9px;border-radius:50%;background:#ffbd2e;display:inline-block'></span>
        <span style='width:9px;height:9px;border-radius:50%;background:#28c940;display:inline-block'></span>
        <span style='background:#3d4a5c;border-radius:4px;padding:2px 8px;color:#aaa;
                     font-size:7pt;flex:1;text-align:left'>{$url}</span>
      </div>
      <!-- شريط الرأس -->
      <div style='background:#1a5276;padding:6px 12px;display:flex;
                  align-items:center;justify-content:space-between'>
        <div style='color:#fff;font-size:8pt;font-weight:bold;direction:rtl'>{$title}</div>
        <div style='color:rgba(255,255,255,.6);font-size:7pt'>مدير النظام &nbsp;|&nbsp; ⚙ 🔔 💬</div>
      </div>";

    // رسم المناطق
    $html .= "<div style='background:#f0f2f7;padding:8px;direction:rtl'>";
    foreach ($zones as $z) {
        $html .= $z;
    }
    $html .= "</div></div>";
    return $html;
}

/** بطاقة KPI صغيرة */
function kpiCard(string $label, string $value, string $color, string $icon): string {
    return "
    <div style='background:{$color};border-radius:8px;padding:8px 12px;color:#fff;
                display:inline-block;margin:3px;min-width:80px;text-align:center'>
        <div style='font-size:16pt;font-weight:900'>{$value}</div>
        <div style='font-size:7pt;opacity:.85'>{$icon} {$label}</div>
    </div>";
}

/** مستطيل منطقة مُعلَّمة */
function zone(string $label, string $bg, string $content, string $border = '#cbd5e1'): string {
    return "
    <div style='background:{$bg};border:1.5px solid {$border};border-radius:7px;
                padding:7px 10px;margin-bottom:6px;direction:rtl'>
        <div style='font-size:7pt;color:#64748b;font-weight:bold;margin-bottom:4px'>{$label}</div>
        {$content}
    </div>";
}

/** خطوة مرقّمة مع سهم */
function steps(array $items, string $color = '#1a5276'): string {
    $html = "<div style='direction:rtl'>";
    foreach ($items as $i => $item) {
        $n = $i + 1;
        $arrow = $n < count($items) ? "
            <div style='text-align:center;color:{$color};font-size:14pt;margin:1px 0'>↓</div>" : '';
        $html .= "
        <div style='display:flex;align-items:flex-start;gap:8px;margin-bottom:3px'>
            <div style='background:{$color};color:#fff;border-radius:50%;
                        width:20px;height:20px;min-width:20px;display:flex;
                        align-items:center;justify-content:center;
                        font-size:8pt;font-weight:bold'>{$n}</div>
            <div style='background:#fff;border:1px solid #e2e8f0;border-radius:6px;
                        padding:6px 10px;flex:1;font-size:8.5pt;color:#374151'>
                {$item}
            </div>
        </div>{$arrow}";
    }
    return $html . "</div>";
}

/** callout مُشير */
function callout(string $text, string $color = '#e74c3c'): string {
    return "
    <span style='background:{$color};color:#fff;border-radius:4px;
                 padding:1px 6px;font-size:7.5pt;font-weight:bold'>{$text}</span>";
}

/** قسم رئيسي */
function sectionHeader(string $n, string $title, string $icon, string $color = '#1a5276'): string {
    return "
    <div style='background:linear-gradient(135deg,{$color},#2980b9);color:#fff;
                padding:12px 18px;border-radius:8px;margin:16px 0 10px;
                font-size:13pt;font-weight:bold;direction:rtl'>
        {$icon} {$n}. {$title}
    </div>";
}

/** مربع معلومة / تحذير */
function tip(string $text, string $type='info'): string {
    $map=['info'=>['#eaf4fb','#2980b9','ℹ'],'warn'=>['#fefce8','#f59e0b','⚠'],'ok'=>['#f0fdf4','#27ae60','✓']];
    [$bg,$border,$ico]=$map[$type]??$map['info'];
    return "
    <div style='background:{$bg};border-right:3px solid {$border};padding:7px 12px;
                border-radius:0 6px 6px 0;margin:6px 0;font-size:8.5pt;color:#374151;direction:rtl'>
        <strong style='color:{$border}'>{$ico}</strong> {$text}
    </div>";
}

/** جدول حقول صغير */
function fieldRow(string $name, string $type, string $note, bool $req=false): string {
    $star = $req ? "<span style='color:#e74c3c'>*</span>" : '';
    return "
    <tr>
        <td style='padding:4px 8px;border:1px solid #e2e8f0;font-weight:bold;
                   color:#1a3a5c;white-space:nowrap'>{$name}{$star}</td>
        <td style='padding:4px 8px;border:1px solid #e2e8f0;text-align:center'>
            <span style='background:#e8f4fd;color:#2980b9;padding:1px 6px;
                         border-radius:8px;font-size:7.5pt'>{$type}</span></td>
        <td style='padding:4px 8px;border:1px solid #e2e8f0;color:#555'>{$note}</td>
    </tr>";
}

function fieldsTable(array $rows): string {
    $html = "<table width='100%' style='border-collapse:collapse;font-size:8.5pt;direction:rtl;margin:6px 0'>
        <tr style='background:#1a5276;color:#fff'>
            <th style='padding:5px 8px;border:1px solid #154360;width:22%'>الحقل</th>
            <th style='padding:5px 8px;border:1px solid #154360;width:12%'>النوع</th>
            <th style='padding:5px 8px;border:1px solid #154360'>التوضيح</th>
        </tr>";
    foreach ($rows as $r) {
        $html .= fieldRow($r[0],$r[1],$r[2],$r[3]??false);
    }
    return $html . "</table>";
}

/** سهم مؤشر مع نص */
function arrow(string $text, string $direction='←'): string {
    return "<span style='color:#e74c3c;font-weight:bold'>{$direction}</span>
            <span style='background:#fff3cd;border:1px solid #ffc107;padding:1px 7px;
                         border-radius:10px;font-size:8pt;color:#856404'>{$text}</span>";
}

// ════════════════════════════════════════════════════
// غلاف
// ════════════════════════════════════════════════════
$html = '<html><body style="direction:rtl;font-family:dejavusans;color:#1a1a2e">';

$html .= '
<div style="text-align:center;padding:50px 10px">
  <div style="background:linear-gradient(135deg,#0d2b4e,#1a5276,#2980b9);
              border-radius:16px;padding:50px 30px;color:#fff">
    <div style="font-size:10pt;opacity:.75;letter-spacing:2px">VISUAL USER GUIDE</div>
    <div style="font-size:34pt;font-weight:900;margin:8px 0">وَصـل</div>
    <div style="width:50px;height:3px;background:#fff;margin:12px auto;opacity:.5"></div>
    <div style="font-size:18pt;font-weight:700">الدليل البصري التوضيحي</div>
    <div style="font-size:11pt;opacity:.8;margin-top:8px">
        رسومات تفصيلية ومؤشرات توضيحية لكل شاشة في النظام
    </div>
  </div>
  <div style="margin-top:20px;background:#fff;border-radius:10px;
              border:1px solid #e2e8f0;padding:14px 20px;text-align:right">
    <table width="100%" style="font-size:9pt">
      <tr>
        <td><strong>النظام:</strong> وَصْل CRM v2.0</td>
        <td><strong>التاريخ:</strong> يونيو 2026</td>
        <td><strong>النوع:</strong> دليل بصري تفاعلي</td>
      </tr>
    </table>
  </div>
</div>';

$mpdf->WriteHTML($html);

// ════════════════════════════════════════════════════
// الصفحة 1 — تسجيل الدخول
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('1','شاشة تسجيل الدخول','🔐');

// محاكاة الشاشة
$p .= mockScreen('نظام وَصْل CRM', 'localhost:8080/UltimatesolutionsCrm/auth/login.php', [
    "<div style='display:flex;gap:8px'>
      <!-- يسار: مميزات -->
      <div style='flex:1;background:linear-gradient(135deg,#1a5276,#2980b9);
                  border-radius:8px;padding:12px;color:#fff;font-size:7.5pt'>
          <div style='font-weight:bold;font-size:9pt;margin-bottom:8px'>نظام وَصْل</div>
          <div>✔ إدارة البلاغات الفنية</div>
          <div>✔ تتبع المهام والموظفين</div>
          <div>✔ إدارة الوثائق الرسمية</div>
          <div>✔ حماية متعددة الطبقات</div>
      </div>
      <!-- يمين: نموذج -->
      <div style='flex:1;background:#fff;border-radius:8px;padding:12px;
                  border:2px solid #e2e8f0;position:relative'>
          <div style='font-weight:bold;font-size:9pt;color:#1a5276;
                      text-align:center;margin-bottom:10px'>تسجيل الدخول</div>
          <!-- حقل البريد -->
          <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:6px;
                      padding:5px 8px;margin-bottom:6px;font-size:7.5pt;color:#64748b'>
              البريد الإلكتروني
          </div>
          <!-- حقل كلمة المرور -->
          <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:6px;
                      padding:5px 8px;margin-bottom:8px;font-size:7.5pt;color:#64748b'>
              كلمة المرور  ••••••••
          </div>
          <!-- زر -->
          <div style='background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;
                      border-radius:6px;padding:6px;text-align:center;font-size:7.5pt;
                      font-weight:bold'>
              تسجيل الدخول
          </div>
      </div>
    </div>"
]);

// مؤشرات توضيحية
$p .= "<table width='100%' style='border-collapse:collapse;font-size:8.5pt;direction:rtl;margin-top:8px'>
<tr>
<td style='padding:4px;width:50%;vertical-align:top'>";

$p .= "<div style='font-weight:bold;color:#1a5276;margin-bottom:6px'>① حقل البريد الإلكتروني</div>
<div style='border:2px solid #e74c3c;border-radius:6px;padding:6px 10px;font-size:8pt;margin-bottom:8px'>
    أدخل البريد المسجّل في النظام<br>
    <span style='color:#e74c3c'>مثال: admin@company.com</span>
</div>
<div style='font-weight:bold;color:#1a5276;margin-bottom:6px'>② حقل كلمة المرور</div>
<div style='border:2px solid #f39c12;border-radius:6px;padding:6px 10px;font-size:8pt;margin-bottom:8px'>
    8 أحرف على الأقل<br>
    <span style='color:#f39c12'>⚠ كلمة المرور حساسة لحالة الأحرف</span>
</div>";

$p .= "</td><td style='padding:4px 4px 4px 12px;vertical-align:top'>";

$p .= "<div style='font-weight:bold;color:#1a5276;margin-bottom:6px'>③ زر تسجيل الدخول</div>
<div style='border:2px solid #27ae60;border-radius:6px;padding:6px 10px;font-size:8pt;margin-bottom:8px'>
    بعد الضغط ← توجيه تلقائي للوحة التحكم<br>
    <span style='color:#27ae60'>✓ الجلسة تستمر ساعتين</span>
</div>
<div style='background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:6px 10px;font-size:8pt'>
    <strong>④ حماية تلقائية:</strong><br>
    5 محاولات خاطئة → قفل 15 دقيقة
</div>";

$p .= "</td></tr></table>";

$p .= tip('بعد تسجيل الدخول بنجاح، يُوجَّهك النظام تلقائياً للوحة التحكم التحليلية.','ok');
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 2 — لوحة التحكم
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('2','لوحة التحكم التحليلية','📊');

$p .= mockScreen('لوحة التحكم', 'localhost:8080/UltimatesolutionsCrm/admin/index.php', [
    // شريط ترحيب
    zone('① شريط الترحيب وإحصاءات سريعة','#1a5276',
        "<div style='color:#fff;display:flex;gap:8px;flex-wrap:wrap'>
          <div style='background:rgba(255,255,255,.15);border-radius:6px;padding:4px 10px;text-align:center'>
              <div style='font-size:12pt;font-weight:900;color:#fff'>0</div>
              <div style='font-size:7pt;color:rgba(255,255,255,.8)'>مهامي</div>
          </div>
          <div style='background:rgba(255,255,255,.15);border-radius:6px;padding:4px 10px;text-align:center'>
              <div style='font-size:12pt;font-weight:900;color:#fbbf24'>2</div>
              <div style='font-size:7pt;color:rgba(255,255,255,.8)'>معلق</div>
          </div>
          <div style='background:rgba(255,255,255,.15);border-radius:6px;padding:4px 10px;text-align:center'>
              <div style='font-size:12pt;font-weight:900;color:#fff'>0</div>
              <div style='font-size:7pt;color:rgba(255,255,255,.8)'>بلاغ اليوم</div>
          </div>
          <div style='flex:1;text-align:left;color:rgba(255,255,255,.8);font-size:8pt;padding-top:8px'>
              رحباً، مدير النظام 👋
          </div>
        </div>",'#154360'),

    // بطاقات KPI
    zone('② بطاقات مؤشرات الأداء (KPIs)','#fff',
        "<div style='display:flex;gap:6px;flex-wrap:wrap'>
          <div style='flex:1;min-width:80px;background:#eaf4fb;border-radius:8px;
                      padding:8px;text-align:center;border-right:3px solid #2980b9'>
              <div style='font-size:14pt;font-weight:900;color:#1a5276'>0</div>
              <div style='font-size:6.5pt;color:#64748b'>صيانة قادمة<br>خلال 7 أيام</div>
          </div>
          <div style='flex:1;min-width:80px;background:#fdf4ff;border-radius:8px;
                      padding:8px;text-align:center;border-right:3px solid #8e44ad'>
              <div style='font-size:14pt;font-weight:900;color:#8e44ad'>1</div>
              <div style='font-size:6.5pt;color:#64748b'>مهام متأخرة<br>تجاوزت الموعد</div>
          </div>
          <div style='flex:1;min-width:80px;background:#fff5f5;border-radius:8px;
                      padding:8px;text-align:center;border-right:3px solid #e74c3c'>
              <div style='font-size:14pt;font-weight:900;color:#e74c3c'>0</div>
              <div style='font-size:6.5pt;color:#64748b'>خرق SLA<br>بلاغ تجاوز الوقت</div>
          </div>
          <div style='flex:1;min-width:80px;background:#f0fdf4;border-radius:8px;
                      padding:8px;text-align:center;border-right:3px solid #27ae60'>
              <div style='font-size:14pt;font-weight:900;color:#27ae60'>14.3%</div>
              <div style='font-size:6.5pt;color:#64748b'>معدل الإنجاز<br>1 بلاغ فُنجز</div>
          </div>
          <div style='flex:1;min-width:80px;background:#fffbeb;border-radius:8px;
                      padding:8px;text-align:center;border-right:3px solid #f59e0b'>
              <div style='font-size:14pt;font-weight:900;color:#f59e0b'>4</div>
              <div style='font-size:6.5pt;color:#64748b'>قيد التنفيذ<br>من 7 إجمالي</div>
          </div>
        </div>"),

    // رسوم بيانية
    zone('③ الرسوم البيانية','#fff',
        "<div style='display:flex;gap:6px'>
          <div style='flex:2;background:#f8fafc;border-radius:8px;padding:8px;text-align:center'>
              <div style='font-size:7.5pt;font-weight:bold;color:#1a5276;margin-bottom:4px'>
                  📈 البلاغات — آخر 30 يوماً
              </div>
              <!-- خط بياني وهمي -->
              <div style='height:50px;background:linear-gradient(to bottom,#eaf4fb,#fff);
                          border-radius:4px;position:relative;overflow:hidden'>
                  <div style='position:absolute;bottom:5px;left:5px;right:5px;
                               height:2px;background:#e2e8f0'></div>
                  <div style='position:absolute;bottom:8px;left:10px;
                               border-left:2px solid #2980b9;border-bottom:2px solid #2980b9;
                               width:80%;height:30px;border-radius:0 0 0 4px'></div>
                  <div style='position:absolute;bottom:12px;left:15px;
                               border-left:2px dashed #27ae60;border-bottom:2px dashed #27ae60;
                               width:70%;height:20px;border-radius:0 0 0 4px'></div>
              </div>
              <div style='font-size:6.5pt;color:#94a3b8;margin-top:2px'>
                  — مفتوح &nbsp;&nbsp; - - فُنجز
              </div>
          </div>
          <div style='flex:1;background:#f8fafc;border-radius:8px;padding:8px;text-align:center'>
              <div style='font-size:7.5pt;font-weight:bold;color:#1a5276;margin-bottom:4px'>
                  🍩 توزيع الأولويات
              </div>
              <!-- دائرة وهمية -->
              <div style='width:50px;height:50px;border-radius:50%;margin:0 auto;
                           background:conic-gradient(#e74c3c 0deg 90deg,#f39c12 90deg 180deg,
                           #3498db 180deg 270deg,#2ecc71 270deg 360deg)'></div>
              <div style='font-size:6pt;margin-top:3px;color:#555'>
                  🔴عاجل 🟠عالي 🔵متوسط 🟢منخفض
              </div>
          </div>
        </div>"),
]);

// شرح المؤشرات
$p .= "<table width='100%' style='font-size:8.5pt;direction:rtl;border-collapse:collapse;margin-top:4px'>
<tr>
<td style='vertical-align:top;padding-left:8px;width:50%'>
<div style='font-weight:bold;color:#1a5276;border-bottom:2px solid #e2e8f0;
            padding-bottom:4px;margin-bottom:8px'>شرح البطاقات ①②</div>";

$cards=[
    ['#2980b9','صيانة قادمة','أصول موعدها خلال 7 أيام'],
    ['#8e44ad','مهام متأخرة','تجاوزت الموعد ولم تُنجز'],
    ['#e74c3c','خرق SLA','بلاغات تجاوزت وقت الحل'],
    ['#27ae60','معدل الإنجاز','نسبة البلاغات المُغلقة'],
    ['#f59e0b','قيد التنفيذ','مهام تحت المعالجة حالياً'],
];
foreach($cards as $c){
    $p .= "<div style='display:flex;align-items:center;gap:6px;margin-bottom:5px'>
        <div style='width:12px;height:12px;border-radius:3px;background:{$c[0]};flex-shrink:0'></div>
        <div><strong>{$c[1]}:</strong> {$c[2]}</div>
    </div>";
}

$p .= "</td><td style='vertical-align:top;width:50%'>
<div style='font-weight:bold;color:#1a5276;border-bottom:2px solid #e2e8f0;
            padding-bottom:4px;margin-bottom:8px'>شرح الرسوم البيانية ③</div>
<div style='background:#eaf4fb;border-radius:6px;padding:8px;font-size:8pt;margin-bottom:6px'>
    <strong>📈 منحنى البلاغات</strong><br>
    الخط الصلب = بلاغات مفتوحة<br>
    الخط المنقط = بلاغات مُنجزة<br>
    <span style='color:#64748b'>المحور الأفقي = آخر 30 يوم</span>
</div>
<div style='background:#f0fdf4;border-radius:6px;padding:8px;font-size:8pt'>
    <strong>🍩 توزيع الأولويات</strong><br>
    كل لون = نسبة أولوية<br>
    انقر على القطعة لرؤية التفاصيل
</div>
</td></tr></table>";

$p .= tip('لوحة التحكم تتحدث تلقائياً عند كل تحميل — لا تحتاج لإعادة تحميل يدوي.','ok');
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 3 — إضافة بلاغ
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('3','إضافة بلاغ جديد','🎫','#c0392b');

$p .= mockScreen('إضافة بلاغ', 'admin/pages/forms/add-request.php', [
    zone('نموذج البلاغ — اتبع الأرقام بالترتيب','#fff',
        "<div style='display:flex;gap:8px'>
          <div style='flex:1'>
            <!-- الموقع -->
            <div style='margin-bottom:5px'>
                <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
                    ① الموقع <span style='color:#e74c3c'>*</span>
                </div>
                <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;
                            padding:4px 8px;font-size:7.5pt;color:#94a3b8'>
                    مثال: مستودع B2 - الطابق الثاني
                </div>
            </div>
            <!-- التصنيف -->
            <div style='margin-bottom:5px'>
                <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
                    ② التصنيف <span style='color:#e74c3c'>*</span>
                </div>
                <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;
                            padding:4px 8px;font-size:7.5pt;color:#94a3b8'>
                    اختر: أعطال كهربائية ▾
                </div>
            </div>
            <!-- الفرع -->
            <div style='margin-bottom:5px'>
                <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
                    ③ الفرع <span style='color:#e74c3c'>*</span>
                </div>
                <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;
                            padding:4px 8px;font-size:7.5pt;color:#94a3b8'>
                    اختر: الفرع الرئيسي - الرياض ▾
                </div>
            </div>
          </div>
          <div style='flex:1'>
            <!-- الأولوية -->
            <div style='margin-bottom:5px'>
                <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
                    ④ الأولوية <span style='color:#e74c3c'>*</span>
                </div>
                <div style='display:flex;gap:3px'>
                    <span style='background:#e74c3c;color:#fff;border-radius:4px;padding:3px 6px;font-size:7pt'>عاجل</span>
                    <span style='background:#f0f2f7;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:7pt'>عالية</span>
                    <span style='background:#f0f2f7;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:7pt'>متوسطة</span>
                    <span style='background:#f0f2f7;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:7pt'>منخفضة</span>
                </div>
            </div>
            <!-- التفاصيل -->
            <div style='margin-bottom:5px'>
                <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
                    ⑤ التفاصيل <span style='color:#e74c3c'>*</span>
                </div>
                <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;
                            padding:4px 8px;font-size:7.5pt;color:#94a3b8;height:36px'>
                    صف المشكلة بدقة وذكر وقت حدوثها...
                </div>
            </div>
            <!-- المرفقات -->
            <div style='margin-bottom:5px'>
                <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
                    ⑥ مرفقات (اختياري)
                </div>
                <div style='background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:5px;
                            padding:4px 8px;font-size:7.5pt;color:#94a3b8;text-align:center'>
                    📎 اختر ملفاً أو اسحبه هنا
                </div>
            </div>
          </div>
        </div>
        <!-- زر الحفظ -->
        <div style='text-align:center;margin-top:6px'>
            <span style='background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;
                          border-radius:6px;padding:6px 20px;font-size:8pt;font-weight:bold'>
                ⑦ حفظ البلاغ
            </span>
        </div>"),
]);

// خطوات
$p .= "<div style='display:flex;gap:10px;margin-top:6px'>
<div style='flex:1'>";
$p .= "<div style='font-weight:bold;color:#1a5276;margin-bottom:6px'>🔢 خطوات الإدخال بالترتيب</div>";
$p .= steps([
    '<strong>① الموقع</strong> — أدخل المكان الدقيق',
    '<strong>② التصنيف</strong> — اختر نوع المشكلة',
    '<strong>③ الفرع</strong> — حدد الموقع التنظيمي',
    '<strong>④ الأولوية</strong> — عاجل / عالية / متوسطة / منخفضة',
    '<strong>⑤ التفاصيل</strong> — صف المشكلة مع التوقيت',
    '<strong>⑥ المرفقات</strong> — صور أو مستندات (اختياري)',
    '<strong>⑦ حفظ</strong> — يُولَّد رقم تلقائي للبلاغ',
],'#c0392b');
$p .= "</div><div style='flex:1'>";

// جدول الأولويات
$p .= "<div style='font-weight:bold;color:#1a5276;margin-bottom:6px'>⏱ جدول SLA الأولويات</div>
<table width='100%' style='border-collapse:collapse;font-size:8pt;direction:rtl'>
<tr style='background:#1a5276;color:#fff'>
<th style='padding:5px 8px;border:1px solid #154360'>الأولوية</th>
<th style='padding:5px 8px;border:1px solid #154360'>استجابة</th>
<th style='padding:5px 8px;border:1px solid #154360'>حل</th>
</tr>
<tr style='background:#fff5f5'>
<td style='padding:5px 8px;border:1px solid #fecaca;color:#dc2626;font-weight:bold'>🔴 عاجل</td>
<td style='padding:5px 8px;border:1px solid #fecaca'>1 ساعة</td>
<td style='padding:5px 8px;border:1px solid #fecaca'>4 ساعات</td>
</tr>
<tr>
<td style='padding:5px 8px;border:1px solid #e2e8f0;color:#d97706;font-weight:bold'>🟠 عالية</td>
<td style='padding:5px 8px;border:1px solid #e2e8f0'>2 ساعة</td>
<td style='padding:5px 8px;border:1px solid #e2e8f0'>8 ساعات</td>
</tr>
<tr style='background:#f8fbff'>
<td style='padding:5px 8px;border:1px solid #e2e8f0;color:#2563eb;font-weight:bold'>🟡 متوسطة</td>
<td style='padding:5px 8px;border:1px solid #e2e8f0'>4 ساعات</td>
<td style='padding:5px 8px;border:1px solid #e2e8f0'>24 ساعة</td>
</tr>
<tr>
<td style='padding:5px 8px;border:1px solid #e2e8f0;color:#64748b;font-weight:bold'>🔵 منخفضة</td>
<td style='padding:5px 8px;border:1px solid #e2e8f0'>8 ساعات</td>
<td style='padding:5px 8px;border:1px solid #e2e8f0'>72 ساعة</td>
</tr>
</table>";
$p .= "</div></div>";
$p .= tip('اختيار أولوية خاطئة يؤثر على SLA — تأكد من الأولوية قبل الحفظ.','warn');
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 4 — عرض البلاغات + سير العمل
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('4','عرض البلاغات وسير العمل','📋','#2980b9');

// مخطط سير العمل
$p .= "<div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;
                    padding:12px;margin-bottom:10px'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:10px;font-size:10pt'>
    🔄 مخطط سير عمل البلاغ (Workflow)
</div>
<div style='display:flex;align-items:center;justify-content:space-between;
            flex-wrap:wrap;gap:4px;text-align:center'>

  <div style='background:#3498db;color:#fff;border-radius:8px;padding:8px 12px;font-size:8pt;font-weight:bold;min-width:80px'>
      📥 بلاغ جديد<br><span style='font-size:7pt;opacity:.8'>مفتوح</span>
  </div>
  <div style='font-size:14pt;color:#94a3b8'>→</div>
  <div style='background:#f39c12;color:#fff;border-radius:8px;padding:8px 12px;font-size:8pt;font-weight:bold;min-width:80px'>
      👷 تعيين فني<br><span style='font-size:7pt;opacity:.8'>add-task.php</span>
  </div>
  <div style='font-size:14pt;color:#94a3b8'>→</div>
  <div style='background:#8e44ad;color:#fff;border-radius:8px;padding:8px 12px;font-size:8pt;font-weight:bold;min-width:80px'>
      ⚙ قيد التنفيذ<br><span style='font-size:7pt;opacity:.8'>الفني يعمل</span>
  </div>
  <div style='font-size:14pt;color:#94a3b8'>→</div>
  <div style='background:#27ae60;color:#fff;border-radius:8px;padding:8px 12px;font-size:8pt;font-weight:bold;min-width:80px'>
      ✅ مُغلق<br><span style='font-size:7pt;opacity:.8'>تم الحل</span>
  </div>
  <div style='font-size:14pt;color:#94a3b8'>↘</div>
  <div style='background:#e74c3c;color:#fff;border-radius:8px;padding:8px 12px;font-size:8pt;font-weight:bold;min-width:80px;margin-top:4px'>
      ❌ ملغي<br><span style='font-size:7pt;opacity:.8'>تم الإلغاء</span>
  </div>

</div>

<!-- SLA Timeline -->
<div style='margin-top:10px;background:#fff;border-radius:6px;padding:8px;border:1px solid #e2e8f0'>
    <div style='font-size:7.5pt;font-weight:bold;color:#374151;margin-bottom:4px'>⏰ مؤشر SLA التلقائي</div>
    <div style='display:flex;gap:0;border-radius:4px;overflow:hidden;height:14px'>
        <div style='flex:2;background:#27ae60'></div>
        <div style='flex:1;background:#f39c12'></div>
        <div style='flex:1;background:#e74c3c'></div>
    </div>
    <div style='display:flex;justify-content:space-between;font-size:6.5pt;color:#64748b;margin-top:2px'>
        <span>✓ ضمن الوقت</span><span>⚠ تحذير</span><span>✕ خرق SLA</span>
    </div>
</div>
</div>";

// محاكاة جدول البلاغات
$p .= mockScreen('عرض البلاغات','admin/pages/tables/show-requests.php',[
    zone('① شريط الفلاتر','#f8fafc',
        "<div style='display:flex;gap:5px;align-items:center'>
            <div style='background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:3px 8px;font-size:7pt;color:#64748b'>🔍 بحث...</div>
            <div style='background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:3px 8px;font-size:7pt;color:#64748b'>التصنيف ▾</div>
            <div style='background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:3px 8px;font-size:7pt;color:#64748b'>الأولوية ▾</div>
            <div style='background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:3px 8px;font-size:7pt;color:#64748b'>الحالة ▾</div>
            <div style='background:#f1f5f9;border:1px solid #cbd5e1;border-radius:5px;padding:3px 8px;font-size:7pt;color:#64748b'>↺ إعادة</div>
        </div>"),
    zone('② جدول البيانات','#fff',
        "<table width='100%' style='border-collapse:collapse;font-size:7pt'>
          <tr style='background:#1a5276;color:#fff'>
            <th style='padding:4px 6px;border:1px solid #154360'>#</th>
            <th style='padding:4px 6px;border:1px solid #154360'>رقم البلاغ</th>
            <th style='padding:4px 6px;border:1px solid #154360'>الموقع</th>
            <th style='padding:4px 6px;border:1px solid #154360'>الأولوية</th>
            <th style='padding:4px 6px;border:1px solid #154360'>الحالة</th>
            <th style='padding:4px 6px;border:1px solid #154360'>الفني</th>
            <th style='padding:4px 6px;border:1px solid #154360'>الموعد</th>
            <th style='padding:4px 6px;border:1px solid #154360'>إجراءات</th>
          </tr>
          <tr>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'>1</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;font-family:monospace;color:#1a5276'>TK-2026-007</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8'>مستودع B2</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'><span style='background:#fee2e2;color:#dc2626;border-radius:8px;padding:1px 5px'>عاجل</span></td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'><span style='background:#fef3c7;color:#d97706;border-radius:8px;padding:1px 5px'>قيد المعالجة</span></td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8'>أحمد محمد</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;color:#dc2626;font-weight:bold'>2026-06-27 ⚠</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'>
                <span style='background:#0891b2;color:#fff;border-radius:4px;padding:1px 5px'>👁</span>
                <span style='background:#d97706;color:#fff;border-radius:4px;padding:1px 5px'>✏</span>
            </td>
          </tr>
          <tr style='background:#f8fafc'>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'>2</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;font-family:monospace;color:#1a5276'>TK-2026-006</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8'>مكتب 101</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'><span style='background:#fef3c7;color:#d97706;border-radius:8px;padding:1px 5px'>عالية</span></td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'><span style='background:#d1fae5;color:#065f46;border-radius:8px;padding:1px 5px'>مُغلق</span></td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8'>سلمى العمري</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;color:#27ae60'>2026-06-25 ✓</td>
            <td style='padding:4px 6px;border:1px solid #f0f4f8;text-align:center'>
                <span style='background:#0891b2;color:#fff;border-radius:4px;padding:1px 5px'>👁</span>
            </td>
          </tr>
        </table>"),
]);

$p .= "<table width='100%' style='font-size:8.5pt;direction:rtl;margin-top:6px'>
<tr>
<td style='vertical-align:top;width:48%;padding-left:8px'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:5px'>شرح أعمدة الجدول ②</div>";
$cols=[['TK-2026-XXX','رقم فريد لكل بلاغ'],['الموقع','مكان المشكلة'],
       ['الأولوية','لون أحمر=عاجل، برتقالي=عالية'],
       ['الموعد ⚠','أحمر = تجاوز SLA'],['إجراءات','👁عرض / ✏تعديل / 🗑حذف']];
foreach($cols as $c){
    $p .= "<div style='display:flex;gap:6px;margin-bottom:3px;align-items:flex-start'>
        <span style='background:#1a5276;color:#fff;border-radius:3px;padding:0 5px;
                     font-size:7pt;white-space:nowrap'>{$c[0]}</span>
        <span>{$c[1]}</span>
    </div>";
}
$p .= "</td><td style='vertical-align:top'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:5px'>استخدام الفلاتر ①</div>
<div style='background:#eaf4fb;border-radius:6px;padding:8px;font-size:8pt'>
    <div style='margin-bottom:4px'>🔍 <strong>بحث نصي:</strong> في الرقم، الموقع، التفاصيل</div>
    <div style='margin-bottom:4px'>📂 <strong>التصنيف:</strong> فلتر بنوع المشكلة</div>
    <div style='margin-bottom:4px'>🎯 <strong>الأولوية:</strong> عاجل → منخفضة</div>
    <div>📊 <strong>الحالة:</strong> مفتوح / قيد المعالجة / مُغلق</div>
</div>
</td></tr></table>";
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 5 — إضافة مهمة
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('5','إضافة مهمة وتعيين فني','✅','#27ae60');

$p .= mockScreen('إضافة مهمة','admin/pages/forms/add-task.php',[
    "<div style='display:flex;gap:8px'>
     <div style='flex:1'>
      <!-- بحث البلاغ -->
      <div style='margin-bottom:5px'>
        <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
            ① ابحث عن البلاغ <span style='color:#e74c3c'>*</span>
        </div>
        <div style='background:#f8fafc;border:1.5px solid #3498db;border-radius:5px;
                    padding:4px 8px;font-size:7.5pt;color:#3498db'>
            🔍 اكتب رقم أو عنوان البلاغ...
        </div>
        <!-- نتائج بحث -->
        <div style='background:#fff;border:1px solid #e2e8f0;border-radius:5px;
                    padding:4px;margin-top:2px'>
            <div style='padding:3px 6px;border-radius:3px;background:#eaf4fb;
                        font-size:7pt;color:#1a5276'>
                ✓ TK-2026-007 — عطل كهربائي مستودع B2
            </div>
        </div>
      </div>
      <!-- العنوان -->
      <div style='margin-bottom:5px'>
        <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
            ② عنوان المهمة <span style='color:#e74c3c'>*</span>
        </div>
        <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;
                    padding:4px 8px;font-size:7.5pt;color:#94a3b8'>
            إصلاح دائرة الكهرباء — مستودع B2
        </div>
      </div>
      <!-- الموعد -->
      <div>
        <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
            ④ الموعد النهائي <span style='color:#e74c3c'>*</span>
        </div>
        <div style='background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:5px;
                    padding:4px 8px;font-size:7.5pt;color:#94a3b8'>
            📅 2026-06-27
        </div>
      </div>
     </div>
     <div style='flex:1'>
      <!-- بحث الموظف -->
      <div style='margin-bottom:5px'>
        <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>
            ③ ابحث عن الموظف <span style='color:#e74c3c'>*</span>
        </div>
        <div style='background:#f8fafc;border:1.5px solid #27ae60;border-radius:5px;
                    padding:4px 8px;font-size:7.5pt;color:#27ae60'>
            🔍 ابحث بالاسم أو الكود...
        </div>
        <!-- بطاقات الفنيين -->
        <div style='display:flex;gap:3px;margin-top:3px;flex-wrap:wrap'>
          <div style='border:2px solid #27ae60;border-radius:6px;padding:4px 6px;
                      font-size:7pt;background:#f0fdf4;flex:1;text-align:center'>
              <div>👤 أحمد محمد</div>
              <div style='color:#64748b;font-size:6.5pt'>F-001</div>
              <div style='color:#27ae60;font-size:6pt'>✓ محدد</div>
          </div>
          <div style='border:1px solid #e2e8f0;border-radius:6px;padding:4px 6px;
                      font-size:7pt;flex:1;text-align:center;color:#64748b'>
              <div>👤 خالد الزهراني</div>
              <div style='font-size:6.5pt'>F-002</div>
          </div>
        </div>
      </div>
      <!-- زر الحفظ -->
      <div style='text-align:center;margin-top:10px'>
        <div style='background:linear-gradient(135deg,#27ae60,#2ecc71);color:#fff;
                    border-radius:6px;padding:6px 16px;font-size:8pt;font-weight:bold'>
            ⑤ حفظ المهمة ← إشعار تلقائي
        </div>
      </div>
     </div>
    </div>"
]);

// مخطط الإشعار التلقائي
$p .= "<div style='background:#f0fdf4;border:2px solid #bbf7d0;border-radius:10px;
                    padding:10px;margin:8px 0'>
<div style='font-weight:bold;color:#065f46;margin-bottom:8px;font-size:9pt'>
    🔔 مخطط الإشعار التلقائي عند حفظ المهمة
</div>
<div style='display:flex;align-items:center;gap:6px;flex-wrap:wrap'>
  <div style='background:#1a5276;color:#fff;border-radius:6px;padding:6px 10px;font-size:8pt'>
      💾 حفظ المهمة
  </div>
  <div style='font-size:12pt;color:#27ae60'>→</div>
  <div style='background:#0891b2;color:#fff;border-radius:6px;padding:6px 10px;font-size:8pt'>
      🔍 جلب user_id الفني
  </div>
  <div style='font-size:12pt;color:#27ae60'>→</div>
  <div style='background:#8e44ad;color:#fff;border-radius:6px;padding:6px 10px;font-size:8pt'>
      💬 Notify::onTaskAssigned()
  </div>
  <div style='font-size:12pt;color:#27ae60'>→</div>
  <div style='background:#27ae60;color:#fff;border-radius:6px;padding:6px 10px;font-size:8pt;text-align:center'>
      📱 رسالة في الشات<br><span style='font-size:7pt;opacity:.8'>فورية</span>
  </div>
</div>
<div style='margin-top:6px;background:#fff;border-radius:6px;padding:6px 10px;
            border:1px solid #bbf7d0;font-size:8pt;color:#374151'>
    <strong>نص الإشعار:</strong>
    📋 تم تعيينك في مهمة: <em>إصلاح دائرة الكهرباء</em> | الموعد: 2026-06-27
</div>
</div>";

$p .= tip('إن لم يكن للموظف حساب مستخدم مرتبط، لن يصله الإشعار — تأكد من الربط في صفحة الموظفين.','warn');
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 6 — الوثائق DMS
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('6','نظام إدارة الوثائق (DMS)','📄','#8e44ad');

// مخطط سير الاعتماد
$p .= "<div style='background:#fdf4ff;border:2px solid #e9d5ff;border-radius:10px;padding:10px;margin-bottom:10px'>
<div style='font-weight:bold;color:#6b21a8;margin-bottom:8px;font-size:9pt'>
    📋 مخطط سير اعتماد الوثيقة
</div>
<div style='display:flex;align-items:center;gap:4px;flex-wrap:wrap;justify-content:center'>
  <div style='text-align:center'>
    <div style='background:#64748b;color:#fff;border-radius:6px;padding:6px 10px;font-size:7.5pt;font-weight:bold'>
        📝 مسودة<br><span style='font-size:6.5pt;opacity:.8'>رفع الوثيقة</span>
    </div>
  </div>
  <div style='font-size:12pt;color:#8e44ad'>→</div>
  <div style='text-align:center'>
    <div style='background:#f59e0b;color:#fff;border-radius:6px;padding:6px 10px;font-size:7.5pt;font-weight:bold'>
        🔔 إشعار المعتمِدين<br><span style='font-size:6.5pt;opacity:.8'>تلقائي</span>
    </div>
  </div>
  <div style='font-size:12pt;color:#8e44ad'>→</div>
  <div style='text-align:center'>
    <div style='background:#8e44ad;color:#fff;border-radius:6px;padding:6px 10px;font-size:7.5pt;font-weight:bold'>
        ✍ توقيع المعتمِد<br><span style='font-size:6.5pt;opacity:.8'>تلقائي على PDF</span>
    </div>
  </div>
  <div style='font-size:12pt;color:#8e44ad'>→</div>
  <div style='text-align:center'>
    <div style='background:#27ae60;color:#fff;border-radius:6px;padding:6px 10px;font-size:7.5pt;font-weight:bold'>
        ✅ معتمدة<br><span style='font-size:6.5pt;opacity:.8'>نهائية</span>
    </div>
  </div>
  <div style='font-size:12pt;color:#8e44ad'>→</div>
  <div style='text-align:center'>
    <div style='background:#0891b2;color:#fff;border-radius:6px;padding:6px 10px;font-size:7.5pt;font-weight:bold'>
        📦 مؤرشفة<br><span style='font-size:6.5pt;opacity:.8'>للحفظ</span>
    </div>
  </div>
</div>
</div>";

// شاشة رفع الوثيقة
$p .= mockScreen('رفع وثيقة جديدة','admin/pages/forms/add-document.php',[
    "<div style='display:flex;gap:8px'>
     <div style='flex:2'>
       <div style='display:flex;gap:5px;margin-bottom:5px'>
         <div style='flex:1'>
           <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>① رقم الوثيقة</div>
           <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:7pt;color:#94a3b8'>DOC-2026-XXXX (تلقائي)</div>
         </div>
         <div style='flex:1'>
           <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>② نوع الوثيقة <span style='color:#e74c3c'>*</span></div>
           <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:7pt;color:#94a3b8'>سياسة ▾</div>
         </div>
       </div>
       <div style='margin-bottom:5px'>
         <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>③ عنوان الوثيقة <span style='color:#e74c3c'>*</span></div>
         <div style='background:#f8fafc;border:1.5px solid #8e44ad;border-radius:4px;padding:3px 6px;font-size:7pt;color:#94a3b8'>سياسة الإجازات السنوية 2026</div>
       </div>
       <div style='margin-bottom:5px'>
         <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>④ سياسة الاعتماد</div>
         <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:3px 6px;font-size:7pt;color:#94a3b8'>اعتماد ثنائي — مدير HR ▾</div>
       </div>
     </div>
     <div style='flex:1'>
       <div style='margin-bottom:5px'>
         <div style='font-size:7pt;font-weight:bold;color:#374151;margin-bottom:2px'>⑤ رفع الملف <span style='color:#e74c3c'>*</span></div>
         <div style='border:2px dashed #8e44ad;border-radius:6px;padding:10px;text-align:center;background:#fdf4ff'>
             <div style='font-size:10pt;color:#8e44ad'>📎</div>
             <div style='font-size:7pt;color:#94a3b8'>PDF/Word/Excel<br>حتى 30MB</div>
         </div>
       </div>
       <div style='text-align:center;margin-top:6px'>
         <div style='background:linear-gradient(135deg,#8e44ad,#9b59b6);color:#fff;
                     border-radius:5px;padding:5px;font-size:7.5pt;font-weight:bold'>
             ⑥ رفع الوثيقة
         </div>
       </div>
     </div>
    </div>"
]);

$p .= "<table width='100%' style='font-size:8.5pt;border-collapse:collapse;direction:rtl;margin-top:6px'>
<tr>
<td style='vertical-align:top;width:50%;padding-left:8px'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:5px'>الأنواع المقبولة للملفات</div>
<table width='100%' style='border-collapse:collapse;font-size:8pt'>
<tr style='background:#1a5276;color:#fff'>
<th style='padding:4px;border:1px solid #154360'>الامتداد</th>
<th style='padding:4px;border:1px solid #154360'>اللون في الجدول</th>
</tr>
<tr><td style='padding:4px;border:1px solid #e2e8f0'>.pdf</td><td style='padding:4px;border:1px solid #e2e8f0'><span style='background:#dc2626;color:#fff;padding:1px 8px;border-radius:8px'>PDF</span></td></tr>
<tr style='background:#f8fbff'><td style='padding:4px;border:1px solid #e2e8f0'>.doc/.docx</td><td style='padding:4px;border:1px solid #e2e8f0'><span style='background:#2563eb;color:#fff;padding:1px 8px;border-radius:8px'>DOC</span></td></tr>
<tr><td style='padding:4px;border:1px solid #e2e8f0'>.xls/.xlsx</td><td style='padding:4px;border:1px solid #e2e8f0'><span style='background:#16a34a;color:#fff;padding:1px 8px;border-radius:8px'>XLS</span></td></tr>
</table>
</td>
<td style='vertical-align:top'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:5px'>إجراءات الوثيقة حسب الحالة</div>
<table width='100%' style='border-collapse:collapse;font-size:8pt'>
<tr style='background:#1a5276;color:#fff'>
<th style='padding:4px;border:1px solid #154360'>الحالة</th>
<th style='padding:4px;border:1px solid #154360'>الإجراءات المتاحة</th>
</tr>
<tr><td style='padding:4px;border:1px solid #e2e8f0'>مسودة</td><td style='padding:4px;border:1px solid #e2e8f0'>عرض ✏تعديل ✅اعتماد 🗑حذف</td></tr>
<tr style='background:#f8fbff'><td style='padding:4px;border:1px solid #e2e8f0'>معتمدة</td><td style='padding:4px;border:1px solid #e2e8f0'>عرض 📥تحميل 📦أرشفة</td></tr>
<tr><td style='padding:4px;border:1px solid #e2e8f0'>مؤرشفة</td><td style='padding:4px;border:1px solid #e2e8f0'>عرض 📥تحميل فقط</td></tr>
</table>
</td>
</tr></table>";
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 7 — الأصول والصيانة
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('7','إدارة الأصول والصيانة الدورية','🔧','#f39c12');

// مخطط دورة حياة الأصل
$p .= "<div style='background:#fffbeb;border:2px solid #fde68a;border-radius:10px;padding:10px;margin-bottom:10px'>
<div style='font-weight:bold;color:#92400e;margin-bottom:8px;font-size:9pt'>🔄 دورة حياة الأصل</div>
<div style='display:flex;align-items:center;gap:4px;flex-wrap:wrap'>
  <div style='background:#27ae60;color:#fff;border-radius:6px;padding:5px 8px;font-size:7.5pt;font-weight:bold;text-align:center'>
      🆕 إضافة الأصل<br><span style='font-size:6.5pt;opacity:.8'>show-assets.php</span>
  </div><div style='font-size:12pt;color:#f39c12'>→</div>
  <div style='background:#2980b9;color:#fff;border-radius:6px;padding:5px 8px;font-size:7.5pt;font-weight:bold;text-align:center'>
      📅 جدولة صيانة<br><span style='font-size:6.5pt;opacity:.8'>show-maintenance.php</span>
  </div><div style='font-size:12pt;color:#f39c12'>→</div>
  <div style='background:#f39c12;color:#fff;border-radius:6px;padding:5px 8px;font-size:7.5pt;font-weight:bold;text-align:center'>
      ⚙ تحت الصيانة<br><span style='font-size:6.5pt;opacity:.8'>حالة مؤقتة</span>
  </div><div style='font-size:12pt;color:#f39c12'>→</div>
  <div style='background:#27ae60;color:#fff;border-radius:6px;padding:5px 8px;font-size:7.5pt;font-weight:bold;text-align:center'>
      ✅ نشط<br><span style='font-size:6.5pt;opacity:.8'>يعود للعمل</span>
  </div><div style='font-size:12pt;color:#e74c3c'>↘</div>
  <div style='background:#64748b;color:#fff;border-radius:6px;padding:5px 8px;font-size:7.5pt;font-weight:bold;text-align:center'>
      🗄 متقاعد<br><span style='font-size:6.5pt;opacity:.8'>خارج الخدمة</span>
  </div>
</div></div>";

$p .= mockScreen('إدارة الأصول','admin/pages/tables/show-assets.php',[
    zone('① إحصاءات الأصول','#fff',
        "<div style='display:flex;gap:5px'>
          <div style='flex:1;background:#f0fdf4;border-radius:6px;padding:6px;text-align:center;border-right:3px solid #27ae60'>
              <div style='font-size:13pt;font-weight:900;color:#065f46'>1</div>
              <div style='font-size:6.5pt;color:#64748b'>إجمالي الأصول</div>
          </div>
          <div style='flex:1;background:#eaf4fb;border-radius:6px;padding:6px;text-align:center;border-right:3px solid #2980b9'>
              <div style='font-size:13pt;font-weight:900;color:#1a5276'>1</div>
              <div style='font-size:6.5pt;color:#64748b'>نشط</div>
          </div>
          <div style='flex:1;background:#fefce8;border-radius:6px;padding:6px;text-align:center;border-right:3px solid #f59e0b'>
              <div style='font-size:13pt;font-weight:900;color:#92400e'>0</div>
              <div style='font-size:6.5pt;color:#64748b'>تحت الصيانة</div>
          </div>
          <div style='flex:1;background:#fff5f5;border-radius:6px;padding:6px;text-align:center;border-right:3px solid #e74c3c'>
              <div style='font-size:13pt;font-weight:900;color:#dc2626'>0</div>
              <div style='font-size:6.5pt;color:#64748b'>موعد قريب</div>
          </div>
        </div>"),
    zone('② جدول الأصول مع QR','#fff',
        "<table width='100%' style='border-collapse:collapse;font-size:7pt'>
          <tr style='background:#1a5276;color:#fff'>
            <th style='padding:4px;border:1px solid #154360'>#</th>
            <th style='padding:4px;border:1px solid #154360'>الكود</th>
            <th style='padding:4px;border:1px solid #154360'>الاسم</th>
            <th style='padding:4px;border:1px solid #154360'>التصنيف</th>
            <th style='padding:4px;border:1px solid #154360'>الحالة</th>
            <th style='padding:4px;border:1px solid #154360'>الصيانة القادمة</th>
            <th style='padding:4px;border:1px solid #154360'>إجراءات</th>
          </tr>
          <tr>
            <td style='padding:4px;border:1px solid #f0f4f8;text-align:center'>1</td>
            <td style='padding:4px;border:1px solid #f0f4f8;font-family:monospace;font-size:6.5pt'>AST-00001</td>
            <td style='padding:4px;border:1px solid #f0f4f8'>طابعة HP LaserJet</td>
            <td style='padding:4px;border:1px solid #f0f4f8'><span style='background:#eaf4fb;color:#1a5276;border-radius:8px;padding:1px 5px'>🖨 طابعات</span></td>
            <td style='padding:4px;border:1px solid #f0f4f8'><span style='background:#d1fae5;color:#065f46;border-radius:8px;padding:1px 5px'>نشط</span></td>
            <td style='padding:4px;border:1px solid #f0f4f8;color:#27ae60'>2026-07-15 ✓</td>
            <td style='padding:4px;border:1px solid #f0f4f8;text-align:center'>
                <span style='background:#0891b2;color:#fff;border-radius:3px;padding:1px 4px'>👁</span>
                <span style='background:#d97706;color:#fff;border-radius:3px;padding:1px 4px'>✏</span>
                <span style='background:#374151;color:#fff;border-radius:3px;padding:1px 4px'>QR</span>
            </td>
          </tr>
        </table>"),
]);

// صيانة
$p .= "<div style='margin-top:8px'>";
$p .= "<div style='font-weight:bold;color:#f39c12;margin-bottom:6px;font-size:10pt'>📅 جدولة الصيانة الدورية</div>";
$p .= "<div style='display:flex;gap:8px'>
<div style='flex:1'>";
$p .= fieldsTable([
    ['الأصل','قائمة','اختر الجهاز أو المعدة',true],
    ['نوع التكرار','قائمة','شهري / ربعي / سنوي ...',true],
    ['التاريخ القادم','تاريخ','موعد الصيانة الأولى',true],
    ['الفني المكلّف','قائمة','يصله إشعار تلقائي',false],
    ['التكلفة المتوقعة','رقم','بالريال السعودي',false],
]);
$p .= "</div><div style='flex:1'>
<div style='background:#fff3cd;border:1px solid #fbbf24;border-radius:8px;padding:10px;font-size:8pt'>
    <div style='font-weight:bold;color:#92400e;margin-bottom:6px'>⏰ الحساب التلقائي للتاريخ القادم</div>
    <div style='display:flex;flex-direction:column;gap:4px'>
        <div>📅 شهري → +30 يوم</div>
        <div>📅 ربع سنوي → +90 يوم</div>
        <div>📅 نصف سنوي → +180 يوم</div>
        <div>📅 سنوي → +365 يوم</div>
    </div>
    <div style='margin-top:8px;padding-top:6px;border-top:1px dashed #fbbf24;font-size:7.5pt;color:#64748b'>
        بعد تسجيل الصيانة المُنجزة، يُحسب التاريخ القادم تلقائياً ويُرسل إشعار للفني.
    </div>
</div>
</div></div></div>";
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 8 — قاعدة المعرفة + الشات
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('8','قاعدة المعرفة والشات الداخلي','📚💬','#0891b2');

$p .= "<div style='display:flex;gap:10px'>
<div style='flex:1'>";

$p .= "<div style='font-weight:bold;color:#0891b2;margin-bottom:6px;font-size:10pt'>📚 قاعدة المعرفة</div>";

$p .= mockScreen('قاعدة المعرفة','admin/pages/tables/show-kb.php',[
    zone('إحصاءات','#fff',
        "<div style='display:flex;gap:4px'>
          <div style='flex:1;background:#eaf4fb;border-radius:5px;padding:5px;text-align:center'>
              <div style='font-size:11pt;font-weight:900;color:#1a5276'>0</div>
              <div style='font-size:6.5pt;color:#64748b'>مقالة</div>
          </div>
          <div style='flex:1;background:#f0fdf4;border-radius:5px;padding:5px;text-align:center'>
              <div style='font-size:11pt;font-weight:900;color:#27ae60'>0</div>
              <div style='font-size:6.5pt;color:#64748b'>منشور</div>
          </div>
          <div style='flex:1;background:#fffbeb;border-radius:5px;padding:5px;text-align:center'>
              <div style='font-size:11pt;font-weight:900;color:#f59e0b'>5</div>
              <div style='font-size:6.5pt;color:#64748b'>تصنيف</div>
          </div>
        </div>"),
    zone('قائمة المقالات','#fff',
        "<table width='100%' style='border-collapse:collapse;font-size:6.5pt'>
          <tr style='background:#0891b2;color:#fff'>
            <th style='padding:3px 5px;border:1px solid #0e7490'>العنوان</th>
            <th style='padding:3px 5px;border:1px solid #0e7490'>التصنيف</th>
            <th style='padding:3px 5px;border:1px solid #0e7490'>الحالة</th>
            <th style='padding:3px 5px;border:1px solid #0e7490'>مشاهدات</th>
          </tr>
          <tr>
            <td style='padding:3px 5px;border:1px solid #f0f4f8'>لا توجد مقالات بعد</td>
            <td style='padding:3px 5px;border:1px solid #f0f4f8'>—</td>
            <td style='padding:3px 5px;border:1px solid #f0f4f8'>—</td>
            <td style='padding:3px 5px;border:1px solid #f0f4f8'>—</td>
          </tr>
        </table>"),
]);

$p .= "<div style='font-size:8.5pt;direction:rtl;margin-top:4px'>
<div style='font-weight:bold;color:#0891b2;margin-bottom:5px'>خطوات إضافة مقالة</div>";
$p .= steps([
    'افتح <strong>قاعدة المعرفة</strong> → <strong>إضافة مقالة</strong>',
    'أدخل <strong>العنوان</strong> الواضح',
    'اختر <strong>التصنيف</strong> من اليمين',
    'اكتب <strong>المحتوى</strong> بمحرر Summernote',
    'أضف <strong>وسوماً</strong> للبحث (كلمة1, كلمة2)',
    'غيّر الحالة إلى <strong>منشور</strong> وحفظ',
],'#0891b2');
$p .= "</div></div>";

$p .= "<div style='flex:1'>";
$p .= "<div style='font-weight:bold;color:#1a5276;margin-bottom:6px;font-size:10pt'>💬 الشات الداخلي</div>";

$p .= mockScreen('الشات','admin/contact.php',[
    "<div style='display:flex;gap:4px;height:130px'>
     <!-- قائمة المستخدمين -->
     <div style='width:35%;background:#1a2a4a;border-radius:5px;padding:5px'>
         <div style='color:#94a3b8;font-size:6pt;margin-bottom:4px'>المستخدمون</div>
         <div style='background:#1e3a5f;border-radius:4px;padding:3px 5px;margin-bottom:3px'>
             <div style='color:#fff;font-size:6.5pt;display:flex;align-items:center;gap:3px'>
                 <div style='width:7px;height:7px;background:#27ae60;border-radius:50%'></div>
                 أحمد محمد
             </div>
         </div>
         <div style='border-radius:4px;padding:3px 5px;margin-bottom:3px'>
             <div style='color:#94a3b8;font-size:6.5pt;display:flex;align-items:center;gap:3px'>
                 <div style='width:7px;height:7px;background:#64748b;border-radius:50%'></div>
                 سلمى العمري
             </div>
         </div>
     </div>
     <!-- منطقة الرسائل -->
     <div style='flex:1;display:flex;flex-direction:column;justify-content:space-between'>
         <div style='flex:1;padding:4px'>
             <!-- رسالة مهمة -->
             <div style='background:#eef2ff;border-right:3px solid #4f46e5;
                         border-radius:0 6px 6px 0;padding:4px 6px;margin-bottom:4px;font-size:6.5pt'>
                 <div style='font-weight:bold;color:#4338ca;font-size:6pt'>📋 مهمة جديدة</div>
                 تم تعيينك في: إصلاح كهرباء مستودع B2
                 <div style='color:#94a3b8;font-size:5.5pt'>الموعد: 2026-06-27</div>
             </div>
             <!-- رسالة عادية -->
             <div style='background:#f0fdf4;border-right:3px solid #27ae60;
                         border-radius:0 6px 6px 0;padding:4px 6px;font-size:6.5pt'>
                 تم الانتهاء من الصيانة ✓
             </div>
         </div>
         <!-- حقل الإرسال -->
         <div style='display:flex;gap:3px;padding:4px;background:#f8fafc;border-radius:0 0 5px 5px'>
             <div style='flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:4px;
                         padding:3px 6px;font-size:6.5pt;color:#94a3b8'>اكتب رسالة...</div>
             <div style='background:#1a5276;color:#fff;border-radius:4px;padding:3px 6px;font-size:7pt'>إرسال</div>
         </div>
     </div>
    </div>"
]);

$p .= "<div style='margin-top:4px;background:#f0fdf4;border-radius:6px;padding:8px;font-size:8pt'>
<div style='font-weight:bold;color:#065f46;margin-bottom:4px'>أنواع رسائل الشات</div>
<table width='100%' style='border-collapse:collapse;font-size:7.5pt'>
<tr><td style='padding:3px;border-bottom:1px solid #bbf7d0'><span style='color:#4f46e5'>■</span> حدود زرقاء</td><td style='padding:3px;border-bottom:1px solid #bbf7d0;color:#374151'>إشعار مهمة جديدة</td></tr>
<tr><td style='padding:3px;border-bottom:1px solid #bbf7d0'><span style='color:#8e44ad'>■</span> حدود بنفسجية</td><td style='padding:3px;border-bottom:1px solid #bbf7d0;color:#374151'>طلب اعتماد وثيقة</td></tr>
<tr><td style='padding:3px'><span style='color:#27ae60'>■</span> حدود خضراء</td><td style='padding:3px;color:#374151'>رسالة نصية عادية</td></tr>
</table>
</div>";
$p .= "</div></div>";
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 9 — الصلاحيات + المستخدمون
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('9','إدارة المستخدمين والصلاحيات','🔒👥','#374151');

$p .= "<div style='display:flex;gap:10px'>
<div style='flex:1'>";

$p .= "<div style='font-weight:bold;color:#1a5276;margin-bottom:6px;font-size:10pt'>👤 إضافة مستخدم جديد</div>";
$p .= mockScreen('إضافة مستخدم','admin/pages/tables/show-users.php',[
    zone('نموذج المستخدم','#fff',
        "<div style='font-size:7.5pt'>
          <div style='display:flex;gap:5px;margin-bottom:4px'>
            <div style='flex:1'>
              <div style='font-size:6.5pt;font-weight:bold;color:#374151;margin-bottom:1px'>① الاسم الكامل *</div>
              <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:2px 6px;color:#94a3b8'>محمد علي الغامدي</div>
            </div>
            <div style='flex:1'>
              <div style='font-size:6.5pt;font-weight:bold;color:#374151;margin-bottom:1px'>② البريد الإلكتروني *</div>
              <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:2px 6px;color:#94a3b8'>m.alghamdi@co.com</div>
            </div>
          </div>
          <div style='display:flex;gap:5px;margin-bottom:4px'>
            <div style='flex:1'>
              <div style='font-size:6.5pt;font-weight:bold;color:#374151;margin-bottom:1px'>③ كلمة المرور *</div>
              <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:2px 6px;color:#94a3b8'>••••••••</div>
            </div>
            <div style='flex:1'>
              <div style='font-size:6.5pt;font-weight:bold;color:#374151;margin-bottom:1px'>④ الدور *</div>
              <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:2px 6px;color:#94a3b8'>فني صيانة ▾</div>
            </div>
          </div>
          <div style='text-align:center'>
            <span style='background:#1a5276;color:#fff;border-radius:5px;padding:4px 12px;font-size:7pt'>⑤ إضافة المستخدم</span>
          </div>
        </div>"),
]);

$p .= "<div style='margin-top:6px;font-size:8.5pt'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:5px'>الأدوار المتاحة</div>
<table width='100%' style='border-collapse:collapse;font-size:8pt'>
<tr style='background:#1a5276;color:#fff'>
<th style='padding:4px;border:1px solid #154360'>الدور</th>
<th style='padding:4px;border:1px solid #154360'>مستوى الصلاحية</th>
</tr>
<tr style='background:#fff5f5'><td style='padding:4px;border:1px solid #e2e8f0;font-weight:bold'>MainAdmin</td><td style='padding:4px;border:1px solid #e2e8f0'>كامل — يشمل إدارة النظام</td></tr>
<tr><td style='padding:4px;border:1px solid #e2e8f0;font-weight:bold'>Admin</td><td style='padding:4px;border:1px solid #e2e8f0'>إدارة البلاغات والمهام والوثائق</td></tr>
<tr style='background:#f8fbff'><td style='padding:4px;border:1px solid #e2e8f0;font-weight:bold'>Technician</td><td style='padding:4px;border:1px solid #e2e8f0'>تنفيذ المهام المعيّنة فقط</td></tr>
<tr><td style='padding:4px;border:1px solid #e2e8f0;font-weight:bold'>Viewer</td><td style='padding:4px;border:1px solid #e2e8f0'>عرض فقط — بدون تعديل</td></tr>
</table>
</div>";

$p .= "</div><div style='flex:1'>";
$p .= "<div style='font-weight:bold;color:#e74c3c;margin-bottom:6px;font-size:10pt'>🔒 تعيين الصلاحيات</div>";

$p .= mockScreen('الصلاحيات','admin/pages/tables/assign-permissions.php',[
    zone('اختيار المستخدم','#fff',
        "<div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;
                     padding:3px 8px;font-size:7.5pt;color:#94a3b8'>
             اختر المستخدم: محمد علي الغامدي ▾
         </div>"),
    zone('جدول الصلاحيات','#fff',
        "<table width='100%' style='border-collapse:collapse;font-size:6.5pt'>
          <tr style='background:#374151;color:#fff'>
            <th style='padding:3px;border:1px solid #4b5563'>الصفحة</th>
            <th style='padding:3px;border:1px solid #4b5563'>عرض</th>
            <th style='padding:3px;border:1px solid #4b5563'>إضافة</th>
            <th style='padding:3px;border:1px solid #4b5563'>تعديل</th>
            <th style='padding:3px;border:1px solid #4b5563'>حذف</th>
          </tr>
          <tr>
            <td style='padding:3px;border:1px solid #e2e8f0'>البلاغات</td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#27ae60'>✓</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#27ae60'>✓</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#e74c3c'>✗</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#e74c3c'>✗</span></td>
          </tr>
          <tr style='background:#f8fbff'>
            <td style='padding:3px;border:1px solid #e2e8f0'>المهام</td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#27ae60'>✓</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#27ae60'>✓</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#27ae60'>✓</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#e74c3c'>✗</span></td>
          </tr>
          <tr>
            <td style='padding:3px;border:1px solid #e2e8f0'>الوثائق</td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#27ae60'>✓</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#e74c3c'>✗</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#e74c3c'>✗</span></td>
            <td style='padding:3px;border:1px solid #e2e8f0;text-align:center'><span style='color:#e74c3c'>✗</span></td>
          </tr>
        </table>
        <div style='display:flex;gap:4px;margin-top:4px'>
            <span style='background:#27ae60;color:#fff;border-radius:4px;padding:2px 6px;font-size:6.5pt'>منح الكل</span>
            <span style='background:#e74c3c;color:#fff;border-radius:4px;padding:2px 6px;font-size:6.5pt'>سحب الكل</span>
            <span style='background:#1a5276;color:#fff;border-radius:4px;padding:2px 6px;font-size:6.5pt'>حفظ الصلاحيات</span>
        </div>"),
]);
$p .= tip('التغييرات تسري فوراً — لا تحتاج لإعادة تشغيل أو خروج المستخدم.','ok');
$p .= "</div></div>";
$mpdf->WriteHTML($p);

// ════════════════════════════════════════════════════
// الصفحة 10 — ملخص المسارات
// ════════════════════════════════════════════════════
$mpdf->AddPage();
$p = sectionHeader('10','خريطة التنقل الكاملة بين الصفحات','🗺️','#0d2b4e');

$p .= "<div style='background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;padding:14px'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:12px;font-size:10pt;text-align:center'>
    🔑 خريطة سير العمل الكاملة — نظام وَصْل CRM
</div>

<!-- الصف الأول: دخول -->
<div style='text-align:center;margin-bottom:10px'>
    <div style='display:inline-block;background:linear-gradient(135deg,#1a5276,#2980b9);
                color:#fff;border-radius:8px;padding:8px 20px;font-size:9pt;font-weight:bold'>
        🔐 تسجيل الدخول (auth/login.php)
    </div>
</div>
<div style='text-align:center;font-size:14pt;color:#94a3b8;margin-bottom:6px'>↓</div>

<!-- الصف الثاني: لوحة التحكم -->
<div style='text-align:center;margin-bottom:10px'>
    <div style='display:inline-block;background:linear-gradient(135deg,#0d2b4e,#1a5276);
                color:#fff;border-radius:8px;padding:8px 20px;font-size:9pt;font-weight:bold'>
        📊 لوحة التحكم التحليلية (admin/index.php)
    </div>
</div>
<div style='text-align:center;font-size:14pt;color:#94a3b8;margin-bottom:8px'>↓</div>

<!-- الوحدات الرئيسية -->
<div style='display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-bottom:8px'>

  <div style='background:#fff;border:2px solid #e74c3c;border-radius:8px;padding:8px;
              text-align:center;min-width:95px;flex:1'>
      <div style='color:#e74c3c;font-size:11pt'>🎫</div>
      <div style='font-size:8pt;font-weight:bold;color:#1a3a5c;margin:3px 0'>البلاغات</div>
      <div style='font-size:6.5pt;color:#64748b'>show-requests.php</div>
      <div style='font-size:6.5pt;color:#64748b'>add-request.php</div>
      <div style='margin-top:4px;font-size:7pt;color:#e74c3c'>→ إنشاء مهمة</div>
  </div>

  <div style='background:#fff;border:2px solid #27ae60;border-radius:8px;padding:8px;
              text-align:center;min-width:95px;flex:1'>
      <div style='color:#27ae60;font-size:11pt'>✅</div>
      <div style='font-size:8pt;font-weight:bold;color:#1a3a5c;margin:3px 0'>المهام</div>
      <div style='font-size:6.5pt;color:#64748b'>show-tasks.php</div>
      <div style='font-size:6.5pt;color:#64748b'>add-task.php</div>
      <div style='margin-top:4px;font-size:7pt;color:#27ae60'>→ إشعار فوري</div>
  </div>

  <div style='background:#fff;border:2px solid #8e44ad;border-radius:8px;padding:8px;
              text-align:center;min-width:95px;flex:1'>
      <div style='color:#8e44ad;font-size:11pt'>📄</div>
      <div style='font-size:8pt;font-weight:bold;color:#1a3a5c;margin:3px 0'>الوثائق</div>
      <div style='font-size:6.5pt;color:#64748b'>show-documents.php</div>
      <div style='font-size:6.5pt;color:#64748b'>add-document.php</div>
      <div style='margin-top:4px;font-size:7pt;color:#8e44ad'>→ سير اعتماد</div>
  </div>

  <div style='background:#fff;border:2px solid #f39c12;border-radius:8px;padding:8px;
              text-align:center;min-width:95px;flex:1'>
      <div style='color:#f39c12;font-size:11pt'>🔧</div>
      <div style='font-size:8pt;font-weight:bold;color:#1a3a5c;margin:3px 0'>الأصول</div>
      <div style='font-size:6.5pt;color:#64748b'>show-assets.php</div>
      <div style='font-size:6.5pt;color:#64748b'>show-maintenance.php</div>
      <div style='margin-top:4px;font-size:7pt;color:#f39c12'>→ QR + صيانة</div>
  </div>

  <div style='background:#fff;border:2px solid #0891b2;border-radius:8px;padding:8px;
              text-align:center;min-width:95px;flex:1'>
      <div style='color:#0891b2;font-size:11pt'>📚</div>
      <div style='font-size:8pt;font-weight:bold;color:#1a3a5c;margin:3px 0'>المعرفة</div>
      <div style='font-size:6.5pt;color:#64748b'>show-kb.php</div>
      <div style='font-size:6.5pt;color:#64748b'>add-kb-article.php</div>
      <div style='margin-top:4px;font-size:7pt;color:#0891b2'>→ تقييم المقال</div>
  </div>

</div>

<div style='display:flex;gap:6px;flex-wrap:wrap;justify-content:center'>

  <div style='background:#fff;border:2px solid #1a5276;border-radius:8px;padding:6px;
              text-align:center;min-width:90px;flex:1'>
      <div style='font-size:9pt'>👥</div>
      <div style='font-size:7.5pt;font-weight:bold;color:#1a3a5c;margin:2px 0'>الموظفون</div>
      <div style='font-size:6.5pt;color:#64748b'>show-employees.php</div>
  </div>

  <div style='background:#fff;border:2px solid #374151;border-radius:8px;padding:6px;
              text-align:center;min-width:90px;flex:1'>
      <div style='font-size:9pt'>🔒</div>
      <div style='font-size:7.5pt;font-weight:bold;color:#1a3a5c;margin:2px 0'>الصلاحيات</div>
      <div style='font-size:6.5pt;color:#64748b'>assign-permissions.php</div>
  </div>

  <div style='background:#fff;border:2px solid #4f46e5;border-radius:8px;padding:6px;
              text-align:center;min-width:90px;flex:1'>
      <div style='font-size:9pt'>💬</div>
      <div style='font-size:7.5pt;font-weight:bold;color:#1a3a5c;margin:2px 0'>الشات</div>
      <div style='font-size:6.5pt;color:#64748b'>contact.php</div>
  </div>

  <div style='background:#fff;border:2px solid #64748b;border-radius:8px;padding:6px;
              text-align:center;min-width:90px;flex:1'>
      <div style='font-size:9pt'>📈</div>
      <div style='font-size:7.5pt;font-weight:bold;color:#1a3a5c;margin:2px 0'>التقارير</div>
      <div style='font-size:6.5pt;color:#64748b'>report-*.php</div>
  </div>

  <div style='background:#fff;border:2px solid #94a3b8;border-radius:8px;padding:6px;
              text-align:center;min-width:90px;flex:1'>
      <div style='font-size:9pt'>⚙</div>
      <div style='font-size:7.5pt;font-weight:bold;color:#1a3a5c;margin:2px 0'>الإعدادات</div>
      <div style='font-size:6.5pt;color:#64748b'>system-settings.php</div>
  </div>

</div>
</div>

<!-- مفتاح الألوان -->
<div style='margin-top:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px'>
<div style='font-weight:bold;color:#1a5276;margin-bottom:6px;font-size:9pt'>🎨 مفتاح ألوان الشارات والحالات</div>
<div style='display:flex;flex-wrap:wrap;gap:6px;font-size:8pt'>
    <div style='display:flex;align-items:center;gap:4px'><span style='background:#dc2626;color:#fff;padding:1px 8px;border-radius:10px'>أحمر</span> عاجل / خطأ / حذف</div>
    <div style='display:flex;align-items:center;gap:4px'><span style='background:#d97706;color:#fff;padding:1px 8px;border-radius:10px'>برتقالي</span> عالي / تحذير / مسودة</div>
    <div style='display:flex;align-items:center;gap:4px'><span style='background:#16a34a;color:#fff;padding:1px 8px;border-radius:10px'>أخضر</span> نجاح / معتمد / مكتمل</div>
    <div style='display:flex;align-items:center;gap:4px'><span style='background:#2563eb;color:#fff;padding:1px 8px;border-radius:10px'>أزرق</span> معلومة / مفتوح</div>
    <div style='display:flex;align-items:center;gap:4px'><span style='background:#7c3aed;color:#fff;padding:1px 8px;border-radius:10px'>بنفسجي</span> وثائق / اعتماد</div>
    <div style='display:flex;align-items:center;gap:4px'><span style='background:#64748b;color:#fff;padding:1px 8px;border-radius:10px'>رمادي</span> غير نشط / مؤرشف</div>
</div>
</div>";

$p .= "<div style='margin-top:16px;background:linear-gradient(135deg,#0d2b4e,#1a5276);
                    border-radius:10px;padding:14px;text-align:center;color:#fff'>
    <div style='font-size:12pt;font-weight:bold;margin-bottom:5px'>✅ نهاية الدليل البصري</div>
    <div style='font-size:8.5pt;opacity:.85'>
        نظام وَصْل CRM — الإصدار 2.0 — 2026<br>
        للدعم التقني: تواصل مع مسؤول النظام أو أرسل بلاغاً عبر النظام
    </div>
</div>";
$mpdf->WriteHTML($p);

// ── حفظ PDF ─────────────────────────────────────────────────────────
$path = dirname(__DIR__) . '/docs/wasl_visual_guide.pdf';
$mpdf->Output($path, \Mpdf\Output\Destination::FILE);

echo '<html><head><meta charset="utf-8">
<style>
body{font-family:Arial,sans-serif;background:#f0f2f7;display:flex;
     align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border-radius:16px;padding:40px;text-align:center;
     box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:500px}
h2{color:#27ae60}
a{background:#1a5276;color:#fff;padding:12px 28px;border-radius:8px;
  text-decoration:none;display:inline-block;margin:6px;font-size:14px}
a.blue{background:#2980b9}
</style></head><body>
<div class="box">
    <h2>✅ تم توليد الدليل البصري</h2>
    <p>الدليل البصري التوضيحي — نظام وَصْل CRM</p>
    <p style="font-size:12px;color:#888">10 صفحات | رسومات توضيحية لكل شاشة</p>
    <a href="wasl_visual_guide.pdf" download>⬇ تحميل PDF</a>
    <a href="wasl_visual_guide.pdf" target="_blank" class="blue">🔍 فتح في المتصفح</a>
</div>
</body></html>';
