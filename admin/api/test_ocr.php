<?php
/**
 * test_ocr.php
 * ============
 * اختصار: افتح في المتصفح لاختبار OCR مباشرة بدون رفع ملف.
 * http://localhost:8080/UltimatesolutionsCrm/admin/api/test_ocr.php
 */

header('Content-Type: text/html; charset=utf-8');
echo "<html dir='rtl'><body style='font-family:sans-serif;padding:20px;line-height:2'>";

// 1. Create a test image with Arabic text
echo "<h2>اختبار OCR مباشر</h2><hr>";

$testDir = sys_get_temp_dir();
$testPng = $testDir . '/test_cr_ocr.png';

echo "<b>إنشاء صورة اختبار...</b><br>";

if (!function_exists('imagecreatetruecolor')) {
    echo "<span style='color:red'>GD library غير مثبّتة — لا يمكن إنشاء صورة اختبار</span><br>";
    echo "يمكنك تخطي هذا الاختبار ورفع صورة حقيقية من صفحة add-cstmr.php<br>";
    echo "</body></html>";
    exit;
}

// Create a test image with Arabic-like text
$im = imagecreatetruecolor(800, 200);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
$red = imagecolorallocate($im, 200, 0, 0);
imagefill($im, 0, 0, $white);

// Add text - use Latin since Arabic needs special font
imagestring($im, 5, 50, 30, "CR Number: 1012345678", $black);
imagestring($im, 5, 50, 60, "National ID: 7012345678", $black);
imagestring($im, 5, 50, 90, "Status: Active", $black);
imagestring($im, 5, 50, 120, "Capital: 500000.00", $black);
imagestring($im, 5, 50, 150, "Est: Test Company", $black);
imagepng($im, $testPng);
imagedestroy($im);

echo "✓ صورة الاختبار: $testPng (" . number_format(filesize($testPng)) . " bytes)<br>";

// 2. Test Tesseract directly
echo "<hr><b>اختبار Tesseract على الصورة...</b><br>";

$tesseractExe = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
if (!file_exists($tesseractExe)) {
    echo "<span style='color:red'>✗ Tesseract غير موجود: $tesseractExe</span><br>";
    echo "</body></html>";
    exit;
}

echo "Tesseract: " . $tesseractExe . "<br>";

// Test with English only
$cmd = '"' . $tesseractExe . '" ' . escapeshellarg($testPng) . ' stdout --psm 6 -l eng 2>&1';
echo "<b>الأمر:</b> <code style='background:#eee;padding:2px 5px;'>" . htmlspecialchars($cmd) . "</code><br><br>";

$out = []; $code = -1;
@exec($cmd, $out, $code);
echo "رمز الخروج: $code<br>";
echo "عدد الأسطر: " . count($out) . "<br>";
echo "النص المستخرج: <pre style='background:#f5f5f5;padding:10px;border:1px solid #ddd;'>" . htmlspecialchars(implode("\n", $out)) . "</pre>";

// Test with ara+eng
echo "<hr><b>اختبار Tesseract مع ara+eng...</b><br>";
$cmd2 = '"' . $tesseractExe . '" ' . escapeshellarg($testPng) . ' stdout --psm 6 -l ara+eng 2>&1';
echo "<b>الأمر:</b> <code style='background:#eee;padding:2px 5px;'>" . htmlspecialchars($cmd2) . "</code><br><br>";

$out2 = []; $code2 = -1;
@exec($cmd2, $out2, $code2);
echo "رمز الخروج: $code2<br>";
echo "عدد الأسطر: " . count($out2) . "<br>";
echo "النص المستخرج: <pre style='background:#f5f5f5;padding:10px;border:1px solid #ddd;'>" . htmlspecialchars(implode("\n", $out2)) . "</pre>";

// 3. Clean up
@unlink($testPng);

echo "<hr><b>هل النص يُقرأ؟</b><br>";
if (strlen(trim(implode("\n", $out))) > 10) {
    echo "<span style='color:green'>✓ Tesseract يعمل بشكل صحيح</span><br>";
} else {
    echo "<span style='color:red'>✗ Tesseract لم يستخرج نصاً — قد تكون المشكلة في الأمر أو الصورة</span><br>";
}

echo "<hr><b>نظام:</b> " . PHP_OS . "<br>";
echo "<b>PHP:</b> " . PHP_VERSION . "<br>";
echo "<b>exec():</b> " . (function_exists('exec') ? 'مفعلة' : 'معطلة') . "<br>";

echo "</body></html>";
