<?php
/**
 * diagnose.php
 * ============
 * ضع هذا الملف في admin/api/ وافتحه في المتصفح:
 *   http://localhost:8080/UltimatesolutionsCrm/admin/api/diagnose.php
 *
 * يكشف ما إذا كانت أدوات OCR/PDF مثبّتة ويعمل exec().
 * احذفه بعد التشخيص.
 */
header('Content-Type: text/html; charset=utf-8');
echo "<html dir='rtl'><body style='font-family:sans-serif;padding:20px;line-height:2'>";
echo "<h2>تشخيص أدوات استخراج السجل التجاري</h2><hr>";

// 1) هل exec() مفعّلة؟
$disabled = explode(',', ini_get('disable_functions'));
$disabled = array_map('trim', $disabled);
$execOk = function_exists('exec') && !in_array('exec', $disabled);
echo "<b>1) دالة exec():</b> " . ($execOk
    ? "<span style='color:green'>مفعّلة ✓</span>"
    : "<span style='color:red'>معطّلة ✗ — يجب تفعيلها في php.ini</span>") . "<br>";

if (!$execOk) {
    echo "<p style='color:red'>توقف: بدون exec() لا تعمل الأدوات. فعّلها أولاً.</p></body></html>";
    exit;
}

// 2) فحص كل أداة (في PATH)
$tools = [
    'pdftotext' => 'استخراج نص PDF (poppler-utils)',
    'pdftoppm'  => 'تحويل PDF لصور (poppler-utils)',
    'tesseract' => 'محرك OCR',
];

echo "<hr><b>2) الأدوات (في PATH):</b><br>";
$os = stripos(PHP_OS, 'WIN') === 0 ? 'windows' : 'unix';
echo "نظام التشغيل: <b>$os</b><br><br>";

foreach ($tools as $tool => $desc) {
    $cmd = ($os === 'windows' ? "where $tool" : "command -v $tool") . " 2>&1";
    $out = [];
    @exec($cmd, $out, $code);
    $found = ($code === 0 && !empty($out));
    $path = $found ? htmlspecialchars(trim(implode(' ', $out))) : '';
    echo "<b>$tool</b> ($desc): " . ($found
        ? "<span style='color:green'>موجود ✓</span> <small style='color:#888'>$path</small>"
        : "<span style='color:orange'>غير موجود في PATH ✗ (قد يعمل بالمسار المباشر)</span>") . "<br>";
}

// 2ب) فحص المسارات المباشرة (التي يستخدمها API فعلياً)
echo "<hr><b>2ب) المسارات المباشرة (المستخدمة في API):</b><br>";
$directTools = [
    'C:\poppler\Library\bin\pdftotext.exe' => 'pdftotext (poppler)',
    'C:\poppler\Library\bin\pdftoppm.exe'  => 'pdftoppm (poppler)',
    'C:\Program Files\Tesseract-OCR\tesseract.exe' => 'tesseract (OCR)',
];
foreach ($directTools as $exePath => $label) {
    $exists = file_exists($exePath);
    echo "<b>$label</b>: " . ($exists
        ? "<span style='color:green'>موجود ✓</span> <small style='color:#888'>" . htmlspecialchars($exePath) . "</small>"
        : "<span style='color:red'>غير موجود ✗</span> <small>" . htmlspecialchars($exePath) . "</small>") . "<br>";
}

// 3) لغة tesseract العربية
echo "<hr><b>3) حزمة اللغة العربية في Tesseract:</b><br>";
$tessExe = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
if (file_exists($tessExe)) {
    $out = [];
    @exec('"' . $tessExe . '" --list-langs 2>&1', $out);
    $langs = implode(' ', $out);
    $hasAra = stripos($langs, 'ara') !== false;
    echo $hasAra
        ? "<span style='color:green'>العربية (ara) مثبّتة ✓</span>"
        : "<span style='color:red'>العربية (ara) غير مثبّتة ✗</span>";
    echo "<br><small style='color:#888'>اللغات المتاحة: " . htmlspecialchars($langs) . "</small>";
} else {
    echo "<span style='color:red'>Tesseract غير مثبّت</span>";
}

echo "<hr><b>الخلاصة:</b><br>";
echo "الـ API يستخدم المسارات المباشرة في القسم 2ب — إذا كانت كلها خضراء ✓، فالأدوات جاهزة.";
echo "</body></html>";
