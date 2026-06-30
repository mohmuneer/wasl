<?php
/**
 * serve-file.php - عرض الملفات بأمان عبر PHP (يدعم الأسماء العربية على Windows)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    http_response_code(403);
    die('Unauthorized');
}

$path = $_GET['path'] ?? '';
if (empty($path)) {
    http_response_code(400);
    die('No path specified');
}

// منع directory traversal — بدون استخدام realpath() لأنه يفشل مع الأسماء العربية على Windows
$path = str_replace('\\', '/', $path); // توحيد الفواصل
if (strpos($path, '..') !== false) {
    http_response_code(403);
    die('Invalid path');
}

// يجب أن يبدأ المسار بـ archive/
if (strpos($path, 'archive/') !== 0) {
    http_response_code(403);
    die('Invalid path');
}

$filePath = __DIR__ . '/../../../' . $path;

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found: ' . htmlspecialchars($path));
}

// تحديد نوع المحتوى
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'txt'  => 'text/plain',
    'zip'  => 'application/zip',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

$isPdf    = ($ext === 'pdf');
$isImage  = in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
$isViewable = $isPdf || $isImage;

$forceDownload = isset($_GET['download']);
$fileSize = filesize($filePath);
// اسم الملف: استخدم الاسم الأصلي من DB إذا تم تمريره، وإلا اسم الملف الفعلي
$fileName = isset($_GET['name']) && !empty($_GET['name'])
    ? basename($_GET['name'])
    : basename($filePath);

// إزالة أي output سابق
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=0, must-revalidate');

if ($forceDownload || !$isViewable) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
} else {
    header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
    if ($isPdf) {
        header('Accept-Ranges: bytes');
    }
}

readfile($filePath);
exit;
