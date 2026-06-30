<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$path = $_GET['path'] ?? '';

if (empty($path)) {
    http_response_code(404);
    die('ملف غير موجود');
}

$projectRoot = realpath(__DIR__ . '/../');
$fullPath = realpath(__DIR__ . '/../' . $path);

if ($fullPath === false || strpos($fullPath, $projectRoot) !== 0) {
    http_response_code(403);
    die('مسار غير صالح');
}

if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    die('الملف غير موجود');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
];

$mimeType = $mimeTypes[$ext] ?? null;

if (!$mimeType) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fullPath);
    finfo_close($finfo);
}

$inlineExts = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
$disposition = in_array($ext, $inlineExts) ? 'inline' : 'attachment';

$fileName = basename($fullPath);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
header('Cache-Control: private, max-age=3600');
header('Pragma: public');

readfile($fullPath);
exit;
