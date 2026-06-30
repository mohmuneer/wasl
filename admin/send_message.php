<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../config/db.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit();
}

$sender_id   = $_SESSION['user_id'];
$receiver_id = isset($_POST['receiver_id']) ? intval($_POST['receiver_id']) : 0;
$message     = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($receiver_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'empty_fields']);
    exit();
}

$message_type = 'text';
$file_path    = null;
$file_name    = null;
$file_size    = null;

// ── ملصق (sticker): النص هو الإيموجي مباشرة ──
if (isset($_POST['message_type_override']) && $_POST['message_type_override'] === 'sticker' && !empty($message)) {
    $message_type = 'sticker';
}

// ── معالجة المرفقات ──
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . '/../uploads/chat/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $file          = $_FILES['attachment'];
    $original_name = basename($file['name']);
    $ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    $allowed_images = ['jpg','jpeg','png','gif','webp'];
    $allowed_audio  = ['webm','ogg','mp3','wav','m4a','aac'];
    $allowed_files  = ['pdf','doc','docx','xls','xlsx','txt','csv','ppt','pptx'];

    $all_allowed = array_merge($allowed_images, $allowed_audio, $allowed_files);

    // التحقق من نوع الملف الحقيقي
    $uploadCheck = Security::validateUpload($file, 'any', 20);

    if ($uploadCheck['ok'] && in_array($ext, $all_allowed)) {
        $unique_name = Security::safeFilename($original_name, 'chat');
        $dest        = $upload_dir . $unique_name;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            if (in_array($ext, $allowed_images)) {
                $message_type = 'image';
            } elseif (in_array($ext, $allowed_audio)) {
                $message_type = 'voice';
                if (empty($message)) $message = '';
            } else {
                $message_type = 'file';
            }
            $file_path = $unique_name;
            $file_name = $original_name;
            $file_size = $file['size'];
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'نوع الملف غير مسموح به']);
        exit();
    }
}

if ($message_type === 'text' && empty($message) && $file_path === null) {
    echo json_encode(['success' => false, 'error' => 'empty_fields']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, message_text, message_type, file_path, file_name, file_size, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $success = $stmt->execute([$sender_id, $receiver_id, $message, $message_type, $file_path, $file_name, $file_size]);

    if ($success) {
        echo json_encode(['success' => true, 'message_id' => (int)$pdo->lastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'error_database']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'error: ' . $e->getMessage()]);
}
