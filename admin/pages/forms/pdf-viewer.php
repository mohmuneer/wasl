<?php
/**
 * pdf-viewer.php
 * يقدّم ملف PDF مباشرة في المتصفح بناءً على id الوثيقة
 * يُفتح في تبويب جديد: pdf-viewer.php?id=6
 */

// تجميع كل output مسبق من أي include
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

// تجاهل أي output من db.php والملفات المضمّنة
ob_end_clean();

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    http_response_code(403);
    exit('Unauthorized');
}

$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($doc_id <= 0) {
    http_response_code(400);
    exit('Invalid document ID');
}

// جلب مسار الملف من قاعدة البيانات
try {
    $stmt = $pdo->prepare("SELECT file_path, file_name, file_format FROM " . TBL_DOCUMENTS . " WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit('DB Error');
}

if (!$doc || empty($doc['file_path'])) {
    http_response_code(404);
    exit('Document not found');
}

$ext = strtolower($doc['file_format'] ?? pathinfo($doc['file_path'], PATHINFO_EXTENSION));

// هذه الصفحة مخصصة للـ PDF فقط
if ($ext !== 'pdf') {
    http_response_code(400);
    exit('Not a PDF document');
}

// بناء المسار الفعلي
$relPath = str_replace('\\', '/', $doc['file_path']);

// منع directory traversal
if (strpos($relPath, '..') !== false) {
    http_response_code(403);
    exit('Invalid path');
}

$filePath = realpath(__DIR__ . '/../../../' . $relPath);

// إذا فشل realpath بسبب أسماء عربية، استخدم المسار المباشر
if ($filePath === false) {
    $filePath = __DIR__ . '/../../../' . $relPath;
}

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found');
}

$fileSize = filesize($filePath);
$fileName = $doc['file_name'] ?: basename($filePath);
$isDownload = isset($_GET['download']);

// إرسال الملف
header('Content-Type: application/pdf');
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');

if ($isDownload) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
} else {
    header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
}

readfile($filePath);
exit;
