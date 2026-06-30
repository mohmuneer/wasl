<?php

/**
 * cr_ocr_api.php  (نسخة متوافقة مع ويندوز/XAMPP و Linux)
 * =====================================================
 * API لاستقبال صورة أو PDF لشهادة السجل التجاري، استخراج البيانات،
 * وإرجاعها JSON لتعبئة الفورم.
 *
 * ★★ مهم لمستخدمي ويندوز/XAMPP ★★
 * عدّل المسارات في قسم "إعدادات المسارات" أدناه لتطابق أماكن تثبيت
 * Tesseract و Poppler على جهازك. على Linux اترك القيم الافتراضية.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/extract_logic.php';

// =====================================================================
// ★ إعدادات المسارات — عدّلها حسب نظامك ★
// =====================================================================
$IS_WINDOWS = stripos(PHP_OS, 'WIN') === 0;

if ($IS_WINDOWS) {
    // مسارات ويندوز الكاملة (عدّلها لتطابق جهازك)
    define('BIN_PDFTOTEXT', '"C:\\poppler\\Library\\bin\\pdftotext.exe"');
    define('BIN_PDFTOPPM',  '"C:\\poppler\\Library\\bin\\pdftoppm.exe"');
    define('BIN_TESSERACT', '"C:\\Program Files\\Tesseract-OCR\\tesseract.exe"');
} else {
    // Linux: الأدوات في PATH
    define('BIN_PDFTOTEXT', 'pdftotext');
    define('BIN_PDFTOPPM',  'pdftoppm');
    define('BIN_TESSERACT', 'tesseract');
}
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    respond(400, ['success' => false, 'message' => 'لم يتم رفع ملف. أرسل الحقل file عبر POST.']);
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    respond(400, ['success' => false, 'message' => 'فشل رفع الملف (رمز الخطأ: ' . $file['error'] . ')']);
}

if ($file['size'] > 10 * 1024 * 1024) {
    respond(400, ['success' => false, 'message' => 'حجم الملف يتجاوز 10 ميجابايت.']);
}

$tmpPath = $file['tmp_name'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
if (!in_array($ext, $allowed)) {
    respond(400, ['success' => false, 'message' => 'نوع ملف غير مدعوم. المسموح: PDF أو صورة.']);
}

// تجهيز متغيرات التشخيص والنصوص (تم تقديمها لتفادي أخطاء التعريف المسبق)
$text = '';
$source = '';
$debug = [];

// ★ نسخ الملف لمجلد عمل محلي (لتفادي مشاكل صلاحيات Apache مع الـ temp) ★
$workDir = __DIR__ . '/work';
if (!is_dir($workDir)) {
    @mkdir($workDir, 0777, true);
}
$workPath = $workDir . '/' . uniqid('ocr_') . '.' . $ext;
if (!@copy($tmpPath, $workPath)) {
    $workPath = $tmpPath; // fallback للنسخة الأصلية إن فشل النسخ
    $debug[] = 'تعذّر نسخ الملف لمجلد العمل، استخدام المسار الأصلي';
}

try {
    if ($ext === 'pdf') {
        $text = pdfToText($workPath, $debug);
        $source = 'pdf-text';
        if (mb_strlen(trim($text)) < 30) {
            $text = ocrPdf($workPath, $debug);
            $source = 'pdf-ocr';
        }
    } else {
        $text = ocrImage($workPath, $debug);
        $source = 'image-ocr';
    }
} catch (Throwable $e) {
    if ($workPath !== $tmpPath) @unlink($workPath);
    respond(500, ['success' => false, 'message' => 'خطأ أثناء المعالجة: ' . $e->getMessage()]);
}

// تنظيف الملف المؤقت
if ($workPath !== $tmpPath) {
    @unlink($workPath);
}

if (mb_strlen(trim($text)) < 10) {
    respond(422, [
        'success' => false,
        'message' => 'تعذّر استخراج نص من الملف. تأكد من تثبيت Tesseract/Poppler ووضوح الملف.',
        'source'  => $source,
        'debug'   => $debug,
        'raw_text' => $text, // النص الخام حتى لو قصير — للمساعدة في التشخيص
    ]);
}

$fields = extractCrFields($text);

respond(200, [
    'success'  => true,
    'message'  => 'تم استخراج البيانات بنجاح',
    'source'   => $source,
    'data'     => $fields,
    'raw_text' => mb_substr($text, 0, 3000),
]);


// =====================================================================
// دوال المعالجة
// =====================================================================

function pdfToText(string $path, array &$debug): string
{
    $out = [];
    $code = 0;
    $cmd = BIN_PDFTOTEXT . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>&1';
    @exec($cmd, $out, $code);
    if ($code !== 0) {
        $debug[] = "pdftotext فشل (code=$code): " . implode(' ', array_slice($out, 0, 3));
    } else {
        $debug[] = "pdftotext نجح مع " . count($out) . " سطراً";
    }
    return $code === 0 ? implode("\n", $out) : '';
}

function ocrPdf(string $path, array &$debug): string
{
    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crpdf_' . uniqid();
    $cmd = BIN_PDFTOPPM . ' -png -r 300 ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    if ($code !== 0) {
        $debug[] = "pdftoppm فشل (code=$code): " . implode(' ', array_slice($out, 0, 2));
    }

    $text = '';
    foreach (glob($prefix . '*.png') as $img) {
        $text .= ocrImage($img, $debug) . "\n";
        @unlink($img);
    }
    return $text;
}

function ocrImage(string $path, array &$debug): string
{
    $out = [];
    $code = 0;
    $cmd = BIN_TESSERACT . ' ' . escapeshellarg($path) . ' stdout -l ara+eng --psm 6 2>&1';
    @exec($cmd, $out, $code);
    if ($code !== 0) {
        $debug[] = "tesseract فشل (code=$code): " . implode(' ', array_slice($out, 0, 3));
        return '';
    }
    $debug[] = "tesseract نجح (code=$code, " . count($out) . " سطور). أول سطرين: " . implode(' | ', array_slice($out, 0, 2));
    return implode("\n", $out);
}

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
