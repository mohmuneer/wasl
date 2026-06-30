<?php
/**
 * test_http.php
 * افتح: http://localhost:8080/UltimatesolutionsCrm/admin/api/test_http.php
 * يختبر OCR بإنشاء صورة (لو GD متاح) أو بنص مباشر، ويعرض النتيجة
 */
header('Content-Type: text/html; charset=utf-8');
echo "<html dir='rtl'><body style='font-family:sans-serif;padding:20px;line-height:2'>";
echo "<h2>اختبار OCR عبر Apache</h2><hr>";

// 1) Check exec
$disabled = explode(',', ini_get('disable_functions'));
$execOk = function_exists('exec') && !in_array(trim('exec'), array_map('trim', $disabled));
echo "<b>exec():</b> " . ($execOk ? "✅" : "❌") . "<br>";

// 2) Create a test image using command-line tools (not GD)
$testDir = __DIR__ . '/work';
if (!is_dir($testDir)) @mkdir($testDir, 0777, true);
$testText = $testDir . '/test_text.png';

// Use ImageMagick if available, otherwise try a simple approach
$imOk = false;
if ($execOk) {
    // Try to use PowerShell to create a simple test image
    $psScript = @'
Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap(800, 150)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.Clear([System.Drawing.Color]::White)
$font = New-Object System.Drawing.Font("Consolas", 16)
$brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::Black)
$g.DrawString("CR Number: 1012345678", $font, $brush, 50, 30)
$g.DrawString("National ID: 7012345678", $font, $brush, 50, 60)
$g.DrawString("Company: Test Est", $font, $brush, 50, 90)
$bmp.Save('###PATH###', [System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose()
$bmp.Dispose()
'@
    $psScript = $psScript.Replace('###PATH###', $testText.Replace('\', '\\'))
    $psScriptFile = $testDir . '/make_image.ps1'
    file_put_contents($psScriptFile, $psScript);
    
    $cmd = 'powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($psScriptFile) . ' 2>&1';
    exec($cmd, $psOut, $psCode);
    @unlink($psScriptFile);
    
    if (file_exists($testText) && filesize($testText) > 100) {
        $imOk = true;
        echo "<b>صورة الاختبار:</b> ✅ تم إنشاؤها (" . filesize($testText) . " bytes)<br>";
    }
}

if (!$imOk) {
    // Fallback: just test Tesseract with a simple text file
    echo "<b>صورة الاختبار:</b> لم يتم إنشاؤها - سأختبر Tesseract فقط<br>";
}

// 3) Test Tesseract directly
$tessExe = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
if (!file_exists($tessExe)) {
    echo "<span style='color:red'>❌ Tesseract غير موجود: $tessExe</span>";
    echo "</body></html>";
    exit;
}
echo "<b>Tesseract:</b> ✅ موجود<br>";

// Test without image - check if we can at least get version
$cmd = '"' . $tessExe . '" --version 2>&1';
exec($cmd, $out, $code);
echo "<b>Tesseract version (exit code $code):</b> " . ($code === 0 ? '✅' : '❌') . "<br>";

// Test languages
$cmd = '"' . $tessExe . '" --list-langs 2>&1';
exec($cmd, $out2, $code2);
$hasAra = false;
foreach ($out2 as $line) {
    if (trim($line) === 'ara') $hasAra = true;
}
echo "<b>اللغة العربية:</b> " . ($hasAra ? '✅' : '❌') . "<br>";

// If we have a test image, run OCR on it
if ($imOk) {
    echo "<hr><h3>اختبار OCR على الصورة:</h3>";
    
    $cmd = '"' . $tessExe . '" ' . escapeshellarg($testText) . ' stdout --psm 6 -l ara+eng 2>&1';
    exec($cmd, $ocrOut, $ocrCode);
    echo "<b>رمز الخروج:</b> $ocrCode<br>";
    echo "<b>النص المستخرج:</b><br><pre style='background:#f5f5f5;padding:10px;border:1px solid #ddd;'>";
    echo htmlspecialchars(implode("\n", $ocrOut));
    echo "</pre>";
    
    if ($ocrCode === 0 && strlen(trim(implode('', $ocrOut))) > 5) {
        echo "<span style='color:green;font-size:1.2em'>✅ OCR يعمل تحت Apache!</span><br>";
    } else {
        echo "<span style='color:red'>❌ OCR فشل تحت Apache</span><br>";
    }
    
    @unlink($testText);
}

// 4) Test the actual API
echo "<hr><h3>ملخص:</h3>";
echo "<b>PHP SAPI:</b> " . php_sapi_name() . "<br>";
echo "<b>PHP Version:</b> " . PHP_VERSION . "<br>";
echo "<b>GD enabled:</b> " . (function_exists('imagecreatetruecolor') ? '✅' : '❌') . "<br>";

echo "</body></html>";
