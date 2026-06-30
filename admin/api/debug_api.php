<?php
/**
 * debug_api.php
 * افتح في المتصفح: http://localhost:8080/UltimatesolutionsCrm/admin/api/debug_api.php
 * يختبر OCR مباشرة ويعرض النتيجة بدون رفع ملف.
 */
header('Content-Type: text/html; charset=utf-8');

$testDir = __DIR__ . '/work';
if (!is_dir($testDir)) @mkdir($testDir, 0777, true);
$testPng = $testDir . '/debug_test.png';
$testJpg = $testDir . '/debug_test.jpg';

// Create test images
$im = imagecreatetruecolor(800, 150);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefill($im, 0, 0, $white);
imagestring($im, 5, 50, 30, 'CR Number: 1012345678', $black);
imagestring($im, 5, 50, 60, 'National ID: 7012345678', $black);
imagestring($im, 5, 50, 90, 'Company: Test Est', $black);
imagepng($im, $testPng);
imagejpeg($im, $testJpg, 90);
imagedestroy($im);

$tesseract = '"C:\Program Files\Tesseract-OCR\tesseract.exe"';
$results = [];

// Test 1: PNG with eng only
$cmd = "$tesseract " . escapeshellarg($testPng) . " stdout --psm 6 -l eng 2>&1";
exec($cmd, $out, $code);
$results['PNG + eng'] = ['code' => $code, 'lines' => count($out), 'text' => implode("\n", $out)];

// Test 2: JPG with ara+eng  
$cmd = "$tesseract " . escapeshellarg($testJpg) . " stdout --psm 6 -l ara+eng 2>&1";
exec($cmd, $out, $code);
$results['JPG + ara+eng'] = ['code' => $code, 'lines' => count($out), 'text' => implode("\n", $out)];

// Test 3: Test with cropped-like JPEG (low quality)
$testLow = $testDir . '/debug_low.jpg';
imagejpeg($im, $testLow, 60);
$cmd = "$tesseract " . escapeshellarg($testLow) . " stdout --psm 6 -l ara+eng 2>&1";
exec($cmd, $out, $code);
$results['Low quality JPG'] = ['code' => $code, 'lines' => count($out), 'text' => implode("\n", $out)];

// Test 4: Test with the actual exec command format from the API
$cmd = "$tesseract " . escapeshellarg($testPng) . " stdout -l ara+eng --psm 6 2>&1";
exec($cmd, $out, $code);
$results['API format PNG'] = ['code' => $code, 'lines' => count($out), 'text' => implode("\n", $out)];

// Display results
echo "<html dir='rtl'><body style='font-family:sans-serif;padding:20px;line-height:2'>";
echo "<h2>تشخيص OCR عبر Apache</h2><hr>";
echo "<b>PHP SAPI:</b> " . php_sapi_name() . "<br>";
echo "<b>PHP Version:</b> " . PHP_VERSION . "<br>";
echo "<b>exec enabled:</b> " . (function_exists('exec') ? 'نعم' : 'لا') . "<br><br>";

echo "<table border='1' cellpadding='8' style='border-collapse:collapse;width:100%'>";
echo "<tr style='background:#eee'><th>الاختبار</th><th>رمز الخروج</th><th>عدد الأسطر</th><th>النص المستخرج</th></tr>";

foreach ($results as $name => $r) {
    $color = ($r['code'] === 0 && strlen(trim($r['text'])) > 5) ? 'green' : 'red';
    echo "<tr>";
    echo "<td>$name</td>";
    echo "<td style='color:$color;font-weight:bold'>{$r['code']}</td>";
    echo "<td>{$r['lines']}</td>";
    echo "<td><pre style='margin:0'>" . htmlspecialchars($r['text']) . "</pre></td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr><b>الخلاصة:</b><br>";
$allOk = true;
foreach ($results as $name => $r) {
    if ($r['code'] !== 0 || strlen(trim($r['text'])) < 5) {
        echo "<span style='color:red'>✗ $name فشل</span><br>";
        $allOk = false;
    }
}
if ($allOk) {
    echo "<span style='color:green;font-size:1.2em'>✓ جميع الاختبارات نجحت — OCR يعمل تحت Apache</span><br>";
    echo "مشكلة المستخدم قد تكون في جودة الصورة المرفوعة أو في واجهة القص (cropper)";
}

// Cleanup
@unlink($testPng); @unlink($testJpg); @unlink($testLow);
echo "</body></html>";
