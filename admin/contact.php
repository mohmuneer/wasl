<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require __DIR__ . "/../config/db.php";

$receiver_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

$peer = null;
if ($receiver_id > 0) {
    $stmt_peer = $pdo->prepare("SELECT id, full_name, file_path FROM sys_users WHERE id = ?");
    $stmt_peer->execute([$receiver_id]);
    $peer = $stmt_peer->fetch(PDO::FETCH_ASSOC);
}

// ── جلب جميع مستخدمي النظام (قابلون للمراسلة) ──
$stmt_u = $pdo->prepare("
    SELECT u.id, u.full_name, u.file_path,
           'sys_user' AS person_type,
           COALESCE(r.role_name, '') AS role_name
    FROM sys_users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN sys_roles r ON ur.role_id = r.id
    WHERE u.id != ?
    GROUP BY u.id
    ORDER BY u.full_name ASC
");
$stmt_u->execute([$_SESSION['user_id']]);
$users = $stmt_u->fetchAll();

// معرّفات المستخدمين الحاليين (لتجنب التكرار مع الموظفين)
$existing_user_ids = array_column($users, 'id');

// ── جلب الموظفين (dms_employees) ──
// الموظفون المرتبطون بحساب نظام: قابلون للمراسلة
// الموظفون غير المرتبطون: يظهرون للعرض فقط
$stmt_e = $pdo->prepare("
    SELECT e.id AS emp_id,
           e.full_name,
           e.signature_image AS file_path,
           e.job_title,
           e.department,
           e.emp_code,
           e.user_id AS linked_user_id,
           u.id AS sys_user_id,
           u.file_path AS sys_file_path,
           'employee' AS person_type
    FROM dms_employees e
    LEFT JOIN sys_users u ON e.user_id = u.id
    WHERE e.full_name IS NOT NULL
      AND e.full_name != ''
      AND (e.user_id IS NULL OR e.user_id != ?)
    ORDER BY e.full_name ASC
");
$stmt_e->execute([$_SESSION['user_id']]);
$all_employees = $stmt_e->fetchAll();

// فصل الموظفين: المرتبطون (مع user_id مختلف عن المستخدمين الحاليين) والمستقلون
$employees_linked   = [];  // مرتبطون بـ sys_user غير مُدرَج في القائمة الرئيسية
$employees_unlinked = [];  // غير مرتبطين بأي sys_user
foreach ($all_employees as $emp) {
    if ($emp['sys_user_id'] && !in_array($emp['sys_user_id'], $existing_user_ids)) {
        $employees_linked[] = $emp;
        $existing_user_ids[] = $emp['sys_user_id'];
    } elseif (!$emp['sys_user_id']) {
        $employees_unlinked[] = $emp;
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>المحادثات | مركز المراسلة الذكي</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo:400,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="dist/css/custom.css?v=202606261542">

    <style>
        :root {
            --chat-primary: #007bff;
            --chat-bg: #f0f2f5;
            --chat-bubble-sent: #007bff;
            --chat-bubble-received: #ffffff;
            --chat-sidebar-bg: #ffffff;
            --chat-header-bg: #ffffff;
            --chat-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        * { font-family: 'Cairo', sans-serif; }
        html, body { overflow: hidden; height: 100%; background: var(--chat-bg); }

        .chat-wrapper {
            display: flex;
            height: calc(100vh - 110px);
            margin: 0 -15px;
        }

        /* ??? Users Sidebar ??? */
        .chat-users-panel {
            width: 340px;
            min-width: 340px;
            background: var(--chat-sidebar-bg);
            border-left: 1px solid #e9edef;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        [dir="rtl"] .chat-users-panel {
            border-left: none;
            border-right: 1px solid #e9edef;
        }

        .chat-users-header {
            padding: 16px 20px 12px;
            border-bottom: 1px solid #e9edef;
            background: var(--chat-header-bg);
        }

        .chat-users-header h5 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
        }

        .chat-search-box {
            padding: 10px 16px;
            background: #f6f6f6;
        }

        .chat-search-box .input-group {
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e9edef;
        }

        .chat-search-box .input-group:focus-within {
            border-color: var(--chat-primary);
            box-shadow: 0 0 0 2px rgba(0,123,255,0.15);
        }

        .chat-search-box .form-control {
            border: none;
            box-shadow: none;
            background: transparent;
            font-size: 0.9rem;
        }

        .chat-search-box .input-group-text {
            background: transparent;
            border: none;
            color: #888;
        }

        .chat-users-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        .chat-users-list::-webkit-scrollbar { width: 4px; }
        .chat-users-list::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

        .chat-user-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid #f0f2f5;
            text-decoration: none !important;
            color: inherit;
        }

        .chat-user-item:hover { background: #f0f2f5; }
        .chat-user-item.active { background: #e8f4fd; }

        .chat-user-avatar {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9edef;
            margin-left: 14px;
            position: relative;
        }

        [dir="rtl"] .chat-user-avatar {
            margin-left: 0;
            margin-right: 14px;
        }

        .chat-user-item.active .chat-user-avatar {
            border-color: var(--chat-primary);
        }

        .chat-user-info { flex: 1; min-width: 0; }
        .chat-user-name { font-weight: 600; font-size: 0.95rem; margin-bottom: 2px; color: #111; }
        .chat-user-preview { font-size: 0.82rem; color: #667781; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        .chat-user-meta { text-align: left; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        [dir="rtl"] .chat-user-meta { text-align: right; }
        .chat-user-time { font-size: 0.72rem; color: #667781; }
        .chat-user-badge {
            background: var(--chat-primary);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        /* ??? Main Chat Area ??? */
        .chat-main-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-width: 0;
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #667781;
            text-align: center;
            padding: 40px;
        }

        .empty-state i { font-size: 80px; color: #d1d7db; margin-bottom: 20px; }
        .empty-state h5 { font-weight: 700; color: #3b4a54; }
        .empty-state p { max-width: 350px; }

        /* Chat Header */
        .chat-header {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            background: var(--chat-header-bg);
            border-bottom: 1px solid #e9edef;
            min-height: 64px;
            position: relative;
        }

        .chat-header .peer-avatar {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 50%;
            object-fit: cover;
            margin-left: 14px;
            border: 2px solid #e9edef;
        }

        [dir="rtl"] .chat-header .peer-avatar {
            margin-left: 0;
            margin-right: 14px;
        }

        .chat-header .peer-info { flex: 1; min-width: 0; }
        .chat-header .peer-name { font-weight: 700; font-size: 1rem; color: #111; }
        .chat-header .peer-status { font-size: 0.78rem; color: #667781; }

        .chat-header-actions { display: flex; gap: 8px; }
        .chat-header-actions .btn {
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #54656f; background: transparent; border: none;
            transition: background 0.2s;
        }
        .chat-header-actions .btn:hover { background: #e9edef; }

        /* Messages Area */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px 40px;
            background: #efeae2;
            background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" opacity="0.04"><path d="M8 4h16v16H8z" fill="%23ddd"/><circle cx="16" cy="20" r="3" fill="%23ddd"/><circle cx="20" cy="8" r="2" fill="%23ddd"/><circle cx="8" cy="12" r="2" fill="%23ddd"/></svg>');
            background-repeat: repeat;
        }

        .chat-messages::-webkit-scrollbar { width: 4px; }
        .chat-messages::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

        .date-divider {
            text-align: center;
            margin: 16px 0;
        }

        .date-divider span {
            background: #e1f3fb;
            color: #54656f;
            font-size: 0.75rem;
            padding: 4px 14px;
            border-radius: 8px;
            box-shadow: 0 1px 1px rgba(0,0,0,0.05);
        }

        .msg-wrapper {
            display: flex;
            margin-bottom: 2px;
            position: relative;
            align-items: flex-end;
            gap: 6px;
            padding: 0 12px;
        }
        .msg-wrapper.sent     { justify-content: flex-end; }
        .msg-wrapper.received { justify-content: flex-start; }
        .msg-wrapper.sent + .msg-wrapper.received,
        .msg-wrapper.received + .msg-wrapper.sent { margin-top: 6px; }

        .msg-avatar {
            width: 30px; height: 30px; min-width: 30px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid #e9edef;
            flex-shrink: 0;
        }

        /* ════════════════════════════════════
           فقاعة الرسالة — WhatsApp Style
        ════════════════════════════════════ */
        .msg-bubble {
            /* تتكيف مع النص تلقائياً */
            display: table;            /* أقوى طريقة لـ fit-content داخل flex */
            max-width: 65%;
            min-width: 60px;
            table-layout: auto;
            padding: 7px 9px 22px 9px; /* bottom = مكان للوقت */
            border-radius: 10px;
            position: relative;
            box-shadow: 0 1px 2px rgba(0,0,0,0.10);
            overflow-wrap: break-word;
            word-break: break-word;
            white-space: pre-wrap;
            box-sizing: border-box;
        }

        .msg-wrapper.sent .msg-bubble {
            background: #d9fdd3;
            border-bottom-right-radius: 3px;
        }
        .msg-wrapper.received .msg-bubble {
            background: #ffffff;
            border-bottom-left-radius: 3px;
        }

        /* ── النص ── */
        .msg-text {
            font-size: 0.9rem;
            line-height: 1.5;
            color: #111;
            display: block;
        }
        .msg-text:empty { display: none; }

        /* ── الوقت وعلامة القراءة (absolute في أسفل الفقاعة) ── */

        .msg-attachment {
            margin: 6px 0 4px;
            border-radius: 6px;
            overflow: hidden;
            max-width: 280px;
        }

        .msg-attachment img {
            width: 100%;
            max-height: 220px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s;
            display: block;
        }

        .msg-attachment img:hover { transform: scale(1.02); }

        .msg-file {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(0,0,0,0.04);
            border-radius: 6px;
            margin: 4px 0;
            text-decoration: none !important;
            color: inherit;
            transition: background 0.2s;
        }

        .msg-file:hover { background: rgba(0,0,0,0.08); }

        .msg-file i { font-size: 28px; color: var(--chat-primary); }
        .msg-file .file-info { flex: 1; min-width: 0; }
        .msg-file .file-name { font-size: 0.85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .msg-file .file-size { font-size: 0.72rem; color: #667781; }
        .msg-file .download-link { color: var(--chat-primary); font-size: 1.1rem; }

        .msg-meta {
            position: absolute;
            bottom: 4px;
            left: 8px;          /* RTL: اليسار = نهاية النص */
            display: flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
            line-height: 1;
        }

        .msg-time { font-size: 0.65rem; color: #667781; }
        .msg-seen { font-size: 0.75rem; }
        .msg-seen.seen { color: #53bdeb; }
        .msg-seen.delivered { color: #667781; }

        .msg-actions {
            position: absolute;
            top: -10px;
            display: none;
            gap: 2px;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.15);
            padding: 2px;
        }

        .msg-wrapper.sent .msg-actions { left: -35px; }
        .msg-wrapper.received .msg-actions { right: -35px; }
        [dir="rtl"] .msg-wrapper.sent .msg-actions { left: auto; right: -35px; }
        [dir="rtl"] .msg-wrapper.received .msg-actions { right: auto; left: -35px; }

        .msg-wrapper:hover .msg-actions { display: flex; }

        .msg-actions .btn-msg-action {
            width: 28px; height: 28px; border-radius: 50%;
            border: none; background: transparent;
            color: #54656f; font-size: 0.72rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: background 0.15s;
        }

        .msg-actions .btn-msg-action:hover { background: #f0f2f5; }
        .msg-actions .btn-msg-action.danger:hover { color: #dc3545; }

        .msg-edited { font-size: 0.65rem; color: #667781; margin-left: 4px; }
        [dir="rtl"] .msg-edited { margin-left: 0; margin-right: 4px; }

        /* ??? Typing Indicator ??? */
        .typing-indicator {
            display: none;
            align-items: center;
            padding: 6px 40px;
            font-size: 0.8rem;
            color: #667781;
        }

        .typing-indicator.show { display: flex; }

        .typing-dots {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-left: 8px;
        }

        [dir="rtl"] .typing-dots {
            margin-left: 0;
            margin-right: 8px;
        }

        .typing-dots span {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #667781;
            animation: typing-bounce 1.4s infinite ease-in-out;
        }

        .typing-dots span:nth-child(1) { animation-delay: 0s; }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-4px); opacity: 1; }
        }

        /* ??? Input Area ??? */
        .chat-input-area {
            padding: 8px 16px;
            background: var(--chat-header-bg);
            border-top: 1px solid #e9edef;
        }

        .chat-input-wrapper {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .chat-input-wrapper .btn-emoji,
        .chat-input-wrapper .btn-attach {
            width: 40px; height: 40px; border-radius: 50%;
            border: none; background: transparent;
            color: #54656f; font-size: 1.2rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: background 0.2s;
            flex-shrink: 0;
        }

        .chat-input-wrapper .btn-emoji:hover,
        .chat-input-wrapper .btn-attach:hover { background: #e9edef; }

        .chat-input-wrapper .form-control {
            flex: 1;
            border-radius: 8px;
            border: 1px solid #e9edef;
            padding: 10px 14px;
            font-size: 0.92rem;
            resize: none;
            max-height: 120px;
            background: #fff;
            box-shadow: none;
        }

        .chat-input-wrapper .form-control:focus {
            border-color: var(--chat-primary);
            box-shadow: 0 0 0 2px rgba(0,123,255,0.15);
        }

        .chat-input-wrapper .btn-send {
            width: 42px; height: 42px; border-radius: 50%;
            border: none;
            background: var(--chat-primary);
            color: #fff;
            font-size: 1rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s;
            flex-shrink: 0;
        }

        .chat-input-wrapper .btn-send:hover {
            background: #0056b3;
            transform: scale(1.05);
        }

        .chat-input-wrapper .btn-send:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
        }

        /* ??? File Preview ??? */
        .file-preview {
            display: none;
            padding: 8px 12px;
            background: #f0f2f5;
            border-radius: 8px;
            margin-bottom: 8px;
            align-items: center;
            gap: 10px;
        }

        .file-preview.show { display: flex; }

        .file-preview .file-icon { font-size: 28px; color: var(--chat-primary); }
        .file-preview .file-info { flex: 1; min-width: 0; }
        .file-preview .file-name { font-size: 0.85rem; font-weight: 600; }
        .file-preview .file-size { font-size: 0.72rem; color: #667781; }
        .file-preview .btn-remove-file {
            width: 28px; height: 28px; border-radius: 50%;
            border: none; background: transparent; color: #dc3545;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
        }

        /* ══ Responsive Chat ══ */
        .back-to-users { display: none; }

        /* ── Tablet ≤ 991px ── */
        @media (max-width: 991.98px) {
            .chat-wrapper { height: calc(100vh - 106px); }
            .chat-users-panel { width: 300px; min-width: 300px; }
            .chat-messages { padding: 16px 24px; }
        }

        /* ── Mobile ≤ 768px ── */
        @media (max-width: 768px) {
            .chat-wrapper {
                height: calc(100vh - 57px);
                margin: 0;
                position: relative;
                overflow: hidden;
            }
            .chat-users-panel {
                width: 100%;
                min-width: 100%;
                position: absolute;
                inset: 0;
                z-index: 20;
                transition: transform 0.28s ease;
            }
            .chat-users-panel.hide {
                transform: translateX(100%);
                pointer-events: none;
            }
            [dir="ltr"] .chat-users-panel.hide { transform: translateX(-100%); }
            .chat-main-panel  { width: 100%; }
            .chat-messages    { padding: 12px 10px; }
            .msg-bubble       { max-width: 88%; }
            .msg-avatar       { display: none; }
            .msg-wrapper      { padding: 0 4px; }
            .chat-header      { padding: 8px 12px; min-height: 56px; }
            .back-to-users    { display: flex !important; }
            .chat-input-area  { padding: 6px 10px; }
            .chat-input-wrapper { gap: 5px; }
            .chat-input-wrapper .form-control {
                font-size: 16px !important;
                padding: 8px 10px !important;
            }
            .chat-input-wrapper .btn-emoji,
            .chat-input-wrapper .btn-attach { width: 36px; height: 36px; font-size: 1.05rem; }
            .chat-input-wrapper .btn-send   { width: 38px; height: 38px; font-size: 0.95rem; }
            .btn-mic  { width: 38px; height: 38px; font-size: 1rem; }
            .sticker-panel {
                width: calc(100vw - 20px);
                max-width: 340px;
                bottom: 62px;
                right: 4px;
                height: 300px;
            }
            [dir="rtl"] .sticker-panel { right: 4px; left: auto; }
            #notifSettingsPanel {
                position: fixed !important;
                top: auto !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                border-radius: 18px 18px 0 0 !important;
                max-height: 85vh;
                overflow-y: auto;
            }
            .msg-voice { min-width: 140px; }
            #inlineSearchResults { max-height: 150px; }
            .empty-state i  { font-size: 60px; }
            .empty-state h5 { font-size: 1rem; }
        }

        /* ── Small phones ≤ 480px ── */
        @media (max-width: 480px) {
            .chat-wrapper   { height: calc(100vh - 57px); }
            .chat-messages  { padding: 10px 8px; }
            .msg-bubble     { max-width: 92%; font-size: 0.88rem; }
            .chat-users-header { padding: 12px 14px 10px; }
            .chat-users-header h5 { font-size: 1rem; }
            .chat-user-item { padding: 10px 14px; }
            .chat-user-avatar { width: 42px; height: 42px; min-width: 42px; margin-left: 10px; }
            .sticker-panel  { height: 260px; }
            .sticker-grid   { grid-template-columns: repeat(6,1fr); }
        }

        /* ??? Search Messages Modal ??? */
        .search-results-item {
            padding: 10px 16px;
            border-bottom: 1px solid #f0f2f5;
            cursor: pointer;
            transition: background 0.15s;
        }
        .search-results-item:hover { background: #f0f2f5; }
        .search-results-item .search-sender { font-size: 0.82rem; color: var(--chat-primary); font-weight: 600; }
        .search-results-item .search-text { font-size: 0.9rem; color: #111; }
        .search-results-item .search-text mark { background: #fff3cd; padding: 0 2px; border-radius: 2px; }
        .search-results-item .search-time { font-size: 0.72rem; color: #667781; }

        /* ??? Toast / Notifications ??? */
        .chat-toast {
            position: fixed;
            bottom: 90px;
            right: 20px;
            background: #323232;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.85rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 9999;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s;
            pointer-events: none;
        }

        .chat-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* ??? Scrollbar for the page wrapper ??? */
        .content-wrapper { height: calc(100vh - 57px); overflow: hidden; }
        section.content { height: calc(100% - 50px); overflow: hidden; }
        .container-fluid, .row { height: 100%; }

        /* ══════════════════════════════════════════
           ملصقات وإيموجي (Sticker Panel)
        ══════════════════════════════════════════ */
        .sticker-panel {
            position: absolute;
            bottom: 70px; right: 4px;
            width: 340px; height: 340px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            z-index: 200;
            display: none;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid #e9edef;
        }
        [dir="rtl"] .sticker-panel { right: auto; left: 4px; }
        .sticker-panel.show { display: flex; }

        .sticker-tabs {
            display: flex; overflow-x: auto; padding: 6px 8px 0; border-bottom: 1px solid #f0f2f5;
            gap: 2px; scrollbar-width: none; background: #fafafa;
        }
        .sticker-tabs::-webkit-scrollbar { display: none; }

        .sticker-tab {
            min-width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 1.2rem; transition: all 0.15s;
            flex-shrink: 0; border: none; background: transparent;
        }
        .sticker-tab:hover { background: #e9edef; transform: scale(1.1); }
        .sticker-tab.active { background: #e8f4fd; }

        .sticker-search-box {
            padding: 6px 10px; border-bottom: 1px solid #f0f2f5;
        }
        .sticker-search-box input {
            width: 100%; border: 1.5px solid #e9edef; border-radius: 20px;
            padding: 5px 14px; font-size: 0.82rem; outline: none; background: #f0f2f5;
            font-family: 'Cairo', sans-serif; transition: all 0.2s;
        }
        .sticker-search-box input:focus { border-color: var(--chat-primary); background: #fff; }

        .sticker-category-label {
            padding: 4px 12px 2px; font-size: 0.7rem; color: #888; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .sticker-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            padding: 6px 8px;
            overflow-y: auto;
            flex: 1;
        }
        .sticker-grid::-webkit-scrollbar { width: 3px; }
        .sticker-grid::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }

        .sticker-item {
            width: 38px; height: 38px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; cursor: pointer; border-radius: 8px;
            transition: all 0.15s; user-select: none; border: none; background: transparent;
        }
        .sticker-item:hover { background: #f0f2f5; transform: scale(1.2); }
        .sticker-item:active { transform: scale(0.9); }

        /* ملصق في فقاعة الشات */
        .msg-sticker {
            font-size: 3.8rem; line-height: 1.1;
            background: transparent !important;
            box-shadow: none !important;
            padding: 4px 6px !important;
            border: none !important;
        }
        .msg-text.sticker-text { font-size: 3.8rem; line-height: 1.1; }

        /* ══════════════════════════════════════════
           تسجيل الصوت (Voice Recording)
        ══════════════════════════════════════════ */
        .btn-mic {
            width: 42px; height: 42px; border-radius: 50%;
            border: none; background: transparent; color: #54656f;
            font-size: 1.15rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s; flex-shrink: 0;
        }
        .btn-mic:hover { background: #e9edef; }
        .btn-mic.recording {
            background: #dc3545; color: #fff;
            animation: mic-pulse 1.2s infinite;
        }
        @keyframes mic-pulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(220,53,69,0.5); }
            50% { box-shadow: 0 0 0 10px rgba(220,53,69,0); }
        }

        .voice-recording-ui {
            display: none; align-items: center; gap: 10px;
            padding: 8px 12px; background: #fff3f3;
            border-radius: 10px; margin-bottom: 8px;
            border: 1.5px solid #ffc0c0;
        }
        .voice-recording-ui.show { display: flex; }

        .voice-waveform {
            flex: 1; display: flex; align-items: center;
            gap: 3px; height: 30px;
        }
        .voice-waveform-bar {
            width: 3px; border-radius: 3px;
            background: #dc3545; min-height: 4px;
            animation: voice-wave 0.8s ease-in-out infinite;
        }
        .voice-waveform-bar:nth-child(1) { animation-delay: 0.0s; }
        .voice-waveform-bar:nth-child(2) { animation-delay: 0.1s; }
        .voice-waveform-bar:nth-child(3) { animation-delay: 0.2s; }
        .voice-waveform-bar:nth-child(4) { animation-delay: 0.3s; }
        .voice-waveform-bar:nth-child(5) { animation-delay: 0.4s; }
        .voice-waveform-bar:nth-child(6) { animation-delay: 0.5s; }
        .voice-waveform-bar:nth-child(7) { animation-delay: 0.4s; }
        .voice-waveform-bar:nth-child(8) { animation-delay: 0.3s; }
        .voice-waveform-bar:nth-child(9) { animation-delay: 0.2s; }
        .voice-waveform-bar:nth-child(10){ animation-delay: 0.1s; }
        @keyframes voice-wave {
            0%,100% { height: 5px; } 50% { height: 22px; }
        }

        .voice-timer {
            font-size: 0.88rem; font-weight: 700; color: #dc3545;
            min-width: 42px; text-align: center; letter-spacing: 1px;
        }
        .btn-voice-cancel {
            width: 32px; height: 32px; border-radius: 50%;
            border: none; background: #f0f2f5; color: #dc3545;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            transition: background 0.2s;
        }
        .btn-voice-cancel:hover { background: #fce8ea; }
        .btn-voice-send {
            width: 36px; height: 36px; border-radius: 50%;
            border: none; background: #25d366; color: #fff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s;
        }
        .btn-voice-send:hover { background: #1da851; transform: scale(1.05); }

        /* ══ مشغل الرسائل الصوتية ══ */
        .msg-voice {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 180px;
            max-width: 280px;
            padding: 3px 0 2px;
        }
        .msg-voice .v-play-btn {
            width: 38px; height: 38px; border-radius: 50%;
            background: var(--chat-primary);
            border: none; color: #fff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 0.88rem; flex-shrink: 0;
            transition: all 0.2s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.18);
        }
        .msg-voice .v-play-btn:hover { transform: scale(1.08); }
        .msg-voice .v-play-btn:active { transform: scale(0.96); }
        .msg-wrapper.sent  .msg-voice .v-play-btn { background: #25d366; }
        .msg-wrapper.received .msg-voice .v-play-btn { background: var(--chat-primary); }

        .msg-voice .v-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 0;
        }
        .msg-voice .v-progress {
            -webkit-appearance: none; appearance: none;
            width: 100%; height: 3px; border-radius: 3px;
            background: rgba(0,0,0,0.18); cursor: pointer; outline: none;
            flex: 1;
        }
        .msg-voice .v-progress::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 13px; height: 13px; border-radius: 50%;
            background: var(--chat-primary); cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .msg-wrapper.sent .msg-voice .v-progress::-webkit-slider-thumb { background: #25d366; }
        .msg-voice .v-dur {
            font-size: 0.68rem; color: #667781;
            font-variant-numeric: tabular-nums;
        }

        /* ══ ردود الفعل (Reactions) ══ */
        .msg-reactions { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 3px; }
        .msg-reaction {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 7px; background: rgba(0,0,0,0.06); border-radius: 12px;
            font-size: 0.78rem; cursor: pointer; border: 1.5px solid transparent;
            transition: all 0.15s; user-select: none;
        }
        .msg-reaction:hover { background: rgba(0,0,0,0.12); }
        .msg-reaction.mine { border-color: var(--chat-primary); background: rgba(0,123,255,0.08); }
        .msg-reaction .r-emoji { font-size: 0.92rem; }
        .msg-reaction .r-count { font-size: 0.7rem; font-weight: 700; color: #54656f; }

        .reaction-picker-bar {
            position: absolute; bottom: calc(100% + 4px);
            background: #fff; border-radius: 24px; padding: 5px 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.16); display: none;
            gap: 3px; z-index: 120; border: 1px solid #e9edef;
        }
        .msg-bubble:hover .reaction-picker-bar { display: flex; }
        .msg-wrapper.sent  .reaction-picker-bar { left: 0; }
        .msg-wrapper.received .reaction-picker-bar { right: 0; }
        [dir="rtl"] .msg-wrapper.sent  .reaction-picker-bar { left: auto; right: 0; }
        [dir="rtl"] .msg-wrapper.received .reaction-picker-bar { right: auto; left: 0; }
        .r-pick-btn {
            font-size: 1.25rem; cursor: pointer; transition: transform 0.15s;
            padding: 2px; border: none; background: transparent; border-radius: 4px;
        }
        .r-pick-btn:hover { transform: scale(1.3); background: #f0f2f5; }
    </style>
</head>
<body class="hold-transition layout-fixed">
    <div class="wrapper">
        <?php include __DIR__ . '/main-header.php'; ?>
        <?php include __DIR__ . '/main-sidebar.php'; ?>

        <div class="content-wrapper">
            <section class="content-header"><!-- intentionally blank --></section>
            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12" style="height:100%;">
                            <div class="chat-wrapper">
                                <!-- ??? Users Panel ??? -->
                                <div class="chat-users-panel" id="chatUsersPanel">
                                    <div class="chat-users-header">
                                        <h5><i class="bi bi-chat-dots ml-2" style="color:var(--chat-primary);"></i>المحادثات</h5>
                                    </div>
                                    <div class="chat-search-box">
                                        <div class="input-group input-group-sm">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            </div>
                                            <input type="text" class="form-control" id="userSearchInput" placeholder="بحث عن زميل..." autocomplete="off">
                                        </div>
                                    </div>
                                    <div class="chat-users-list" id="chatUsersList">

                                        <?php
                                        // ── دالة مساعدة: آخر رسالة وعدد غير المقروءة ──
                                        function getLastMsg($pdo, $myId, $otherId) {
                                            $s = $pdo->prepare("
                                                SELECT m.message_text, m.created_at, m.message_type,
                                                       CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END as is_outgoing
                                                FROM messages m
                                                WHERE (m.sender_id=? AND m.receiver_id=?)
                                                   OR (m.sender_id=? AND m.receiver_id=?)
                                                ORDER BY m.created_at DESC LIMIT 1");
                                            $s->execute([$myId,$myId,$otherId,$otherId,$myId]);
                                            return $s->fetch();
                                        }
                                        function getUnread($pdo, $senderId, $receiverId) {
                                            $s = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id=? AND receiver_id=? AND is_read=0");
                                            $s->execute([$senderId,$receiverId]);
                                            return (int)$s->fetchColumn();
                                        }
                                        function renderChatItem($uid, $name, $img, $active, $preview, $time, $unread, $badge='', $subtitle='') {
                                            $activeClass = $active ? 'active' : '';
                                            echo '<a href="contact.php?user_id=' . $uid . '" class="chat-user-item ' . $activeClass . '" data-userid="' . $uid . '" data-name="' . htmlspecialchars(strtolower($name)) . '">';
                                            echo '<img src="' . $img . '" class="chat-user-avatar" alt="">';
                                            echo '<div class="chat-user-info">';
                                            echo '<div class="chat-user-name">' . htmlspecialchars($name);
                                            if ($badge) echo ' ' . $badge;
                                            echo '</div>';
                                            echo '<div class="chat-user-preview">' . ($subtitle ? '<span style="color:#888;font-size:.7rem">' . $subtitle . '</span>' : ($preview ?: 'لا توجد محادثات سابقة')) . '</div>';
                                            echo '</div>';
                                            echo '<div class="chat-user-meta">';
                                            if ($time) echo '<span class="chat-user-time">' . $time . '</span>';
                                            if ($unread > 0) echo '<span class="chat-user-badge">' . $unread . '</span>';
                                            echo '</div>';
                                            echo '</a>';
                                        }
                                        ?>

                                        <!-- ══ مستخدمو النظام ══ -->
                                        <?php if (!empty($users)): ?>
                                        <div class="chat-group-header" style="padding:8px 16px 4px;font-size:.7rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#fafbfc;border-bottom:1px solid #f0f2f5">
                                            <i class="fas fa-users ml-1" style="color:var(--chat-primary,#007bff)"></i>
                                            مستخدمو النظام
                                            <span style="background:#e2e8f0;color:#64748b;border-radius:20px;padding:1px 8px;font-size:.65rem;font-weight:700;margin-right:4px"><?= count($users) ?></span>
                                        </div>
                                        <?php foreach ($users as $u):
                                            $uid  = $u['id'];
                                            $img  = !empty($u['file_path']) ? "../uploads/" . $u['file_path'] : "dist/img/avatar5.png";
                                            $active = ($uid == $receiver_id);
                                            $lm   = getLastMsg($pdo, $_SESSION['user_id'], $uid);
                                            $unread = getUnread($pdo, $uid, $_SESSION['user_id']);
                                            $preview = ''; $time = '';
                                            if ($lm) {
                                                $prefix = $lm['is_outgoing'] ? 'أنت: ' : '';
                                                $txt = $lm['message_type']==='image' ? '🖼 صورة' : ($lm['message_type']==='file' ? '📎 ملف' : $lm['message_text']);
                                                // إشعارات النظام: اعرض أيقونة واضحة
                                                if (str_starts_with($txt, '🔧 مهمة جديدة')) $txt = '🔔 مهمة مُسنَدة';
                                                elseif (str_starts_with($txt, '📄 طلب اعتماد')) $txt = '🔔 طلب اعتماد وثيقة';
                                                $preview = $prefix . htmlspecialchars(mb_strimwidth($txt,0,35,'...'));
                                                $time = date('h:i A', strtotime($lm['created_at']));
                                            }
                                            $roleBadge = !empty($u['role_name']) ? '<span style="background:#dbeafe;color:#1d4ed8;font-size:.6rem;padding:1px 6px;border-radius:10px;font-weight:700">' . htmlspecialchars($u['role_name']) . '</span>' : '';
                                        ?>
                                        <a href="contact.php?user_id=<?= $uid ?>" class="chat-user-item <?= $active?'active':'' ?>" data-userid="<?= $uid ?>" data-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>">
                                            <img src="<?= $img ?>" class="chat-user-avatar" alt="">
                                            <div class="chat-user-info">
                                                <div class="chat-user-name">
                                                    <?= htmlspecialchars($u['full_name']) ?>
                                                    <?= $roleBadge ?>
                                                </div>
                                                <div class="chat-user-preview"><?= $preview ?: 'ابدأ محادثة جديدة' ?></div>
                                            </div>
                                            <div class="chat-user-meta">
                                                <?php if ($time): ?><span class="chat-user-time"><?= $time ?></span><?php endif; ?>
                                                <?php if ($unread>0): ?><span class="chat-user-badge"><?= $unread ?></span><?php endif; ?>
                                            </div>
                                        </a>
                                        <?php endforeach; ?>
                                        <?php endif; ?>

                                        <!-- ══ موظفون مرتبطون بحساب نظام ══ -->
                                        <?php if (!empty($employees_linked)): ?>
                                        <div class="chat-group-header" style="padding:8px 16px 4px;font-size:.7rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#fafbfc;border-bottom:1px solid #f0f2f5;margin-top:4px">
                                            <i class="fas fa-id-card ml-1" style="color:#059669"></i>
                                            الموظفون (حساب نشط)
                                            <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:1px 8px;font-size:.65rem;font-weight:700;margin-right:4px"><?= count($employees_linked) ?></span>
                                        </div>
                                        <?php foreach ($employees_linked as $emp):
                                            $uid    = $emp['sys_user_id'];
                                            $img    = !empty($emp['sys_file_path']) ? "../uploads/" . $emp['sys_file_path'] : "dist/img/avatar5.png";
                                            $active = ($uid == $receiver_id);
                                            $lm     = getLastMsg($pdo, $_SESSION['user_id'], $uid);
                                            $unread = getUnread($pdo, $uid, $_SESSION['user_id']);
                                            $preview = ''; $time = '';
                                            if ($lm) {
                                                $prefix = $lm['is_outgoing'] ? 'أنت: ' : '';
                                                $txt = $lm['message_type']==='image' ? '🖼 صورة' : ($lm['message_type']==='file' ? '📎 ملف' : $lm['message_text']);
                                                $preview = $prefix . htmlspecialchars(mb_strimwidth($txt,0,35,'...'));
                                                $time = date('h:i A', strtotime($lm['created_at']));
                                            }
                                            $sub = htmlspecialchars(trim(($emp['job_title']?:'') . ($emp['department'] ? ' · '.$emp['department'] : '')));
                                        ?>
                                        <a href="contact.php?user_id=<?= $uid ?>" class="chat-user-item <?= $active?'active':'' ?>" data-userid="<?= $uid ?>" data-name="<?= htmlspecialchars(strtolower($emp['full_name'])) ?>">
                                            <img src="<?= $img ?>" class="chat-user-avatar" alt="">
                                            <div class="chat-user-info">
                                                <div class="chat-user-name">
                                                    <?= htmlspecialchars($emp['full_name']) ?>
                                                    <span style="background:#d1fae5;color:#065f46;font-size:.6rem;padding:1px 6px;border-radius:10px;font-weight:700">موظف</span>
                                                </div>
                                                <div class="chat-user-preview"><?= $sub ? '<span style="color:#059669;font-size:.72rem">' . $sub . '</span>' : ($preview ?: 'ابدأ محادثة') ?></div>
                                            </div>
                                            <div class="chat-user-meta">
                                                <?php if ($time): ?><span class="chat-user-time"><?= $time ?></span><?php endif; ?>
                                                <?php if ($unread>0): ?><span class="chat-user-badge"><?= $unread ?></span><?php endif; ?>
                                            </div>
                                        </a>
                                        <?php endforeach; ?>
                                        <?php endif; ?>

                                        <!-- ══ موظفون غير مرتبطين (للعرض فقط) ══ -->
                                        <?php if (!empty($employees_unlinked)): ?>
                                        <div class="chat-group-header" style="padding:8px 16px 4px;font-size:.7rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#fafbfc;border-bottom:1px solid #f0f2f5;margin-top:4px">
                                            <i class="fas fa-user-clock ml-1" style="color:#d97706"></i>
                                            الموظفون (بدون حساب)
                                            <span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:1px 8px;font-size:.65rem;font-weight:700;margin-right:4px"><?= count($employees_unlinked) ?></span>
                                        </div>
                                        <?php foreach ($employees_unlinked as $emp): ?>
                                        <div class="chat-user-item" data-name="<?= htmlspecialchars(strtolower($emp['full_name'])) ?>"
                                            style="opacity:.7;cursor:default" title="هذا الموظف لا يملك حساباً في النظام — لا يمكن إرسال رسائل إليه">
                                            <div style="width:48px;height:48px;min-width:48px;border-radius:50%;background:linear-gradient(135deg,#fef3c7,#fde68a);display:flex;align-items:center;justify-content:center;margin-left:14px;font-size:1.1rem;font-weight:700;color:#92400e;border:2px solid #fde68a">
                                                <?= mb_substr($emp['full_name'],0,1) ?>
                                            </div>
                                            <div class="chat-user-info">
                                                <div class="chat-user-name">
                                                    <?= htmlspecialchars($emp['full_name']) ?>
                                                    <span style="background:#fef3c7;color:#92400e;font-size:.6rem;padding:1px 6px;border-radius:10px;font-weight:700">بدون حساب</span>
                                                </div>
                                                <div class="chat-user-preview" style="color:#d97706;font-size:.72rem">
                                                    <?= htmlspecialchars(trim(($emp['job_title']?:'موظف') . ($emp['department'] ? ' · '.$emp['department'] : ''))) ?>
                                                </div>
                                            </div>
                                            <div class="chat-user-meta">
                                                <i class="fas fa-lock" style="color:#d97706;font-size:.75rem" title="لا يمكن المراسلة"></i>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php endif; ?>

                                        <!-- رسالة إذا لم يوجد أحد -->
                                        <?php if (empty($users) && empty($employees_linked) && empty($employees_unlinked)): ?>
                                        <div class="text-center py-5 text-muted">
                                            <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                            لا يوجد مستخدمون أو موظفون
                                        </div>
                                        <?php endif; ?>

                                    </div>
                                </div>

                                <!-- ??? Main Chat ??? -->
                                <div class="chat-main-panel">
                                    <?php if ($peer): ?>
                                    <!-- Chat Header -->
                                    <div class="chat-header">
                                        <button class="btn back-to-users p-1" onclick="toggleUsersPanel()" style="font-size:1.2rem;margin-left:8px;" title="العودة للمحادثات">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                        <?php
                                        $peer_img = !empty($peer['file_path']) ? "../uploads/" . $peer['file_path'] : "dist/img/avatar5.png";
                                        ?>
                                        <img src="<?php echo $peer_img; ?>" class="peer-avatar" alt="">
                                        <div class="peer-info">
                                            <div class="peer-name"><?php echo htmlspecialchars($peer['full_name']); ?></div>
                                            <div class="peer-status" id="peerStatus">غير متصل</div>
                                        </div>
                                        <div class="chat-header-actions">
                                            <button class="btn" onclick="toggleSearch()" title="بحث في المحادثة">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <button class="btn" onclick="location.href='allmessages.php'" title="جميع الرسائل">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Inline Search -->
                                    <div id="inlineSearch" style="display:none; padding:8px 16px; background:#f6f6f6; border-bottom:1px solid #e9edef;">
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" id="inlineSearchInput" placeholder="ابحث في المحادثة..." autocomplete="off">
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-secondary" onclick="toggleSearch()" type="button"><i class="fas fa-times"></i></button>
                                            </div>
                                        </div>
                                        <div id="inlineSearchResults" style="max-height:200px; overflow-y:auto; margin-top:6px; background:#fff; border-radius:6px; border:1px solid #e9edef; display:none;"></div>
                                    </div>

                                    <!-- Messages -->
                                    <div class="chat-messages" id="chatMessages">
                                        <div class="text-center mt-5 text-muted">
                                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                                            <p class="mt-2">جاري تحميل الرسائل...</p>
                                        </div>
                                    </div>

                                    <!-- Typing Indicator -->
                                    <div class="typing-indicator" id="typingIndicator">
                                        <div class="typing-dots">
                                            <span></span><span></span><span></span>
                                        </div>
                                        <span id="typingName">جارٍ الكتابة...</span>
                                    </div>

                                    <!-- منطقة الإدخال الكاملة -->
                                    <div class="chat-input-area" style="position:relative;">

                                        <!-- لوحة الملصقات والإيموجي -->
                                        <div class="sticker-panel" id="stickerPanel">
                                            <div class="sticker-tabs" id="stickerTabs"></div>
                                            <div class="sticker-search-box">
                                                <input type="text" id="stickerSearch" placeholder="🔍 ابحث عن إيموجي...">
                                            </div>
                                            <div class="sticker-grid" id="stickerGrid"></div>
                                        </div>

                                        <!-- واجهة تسجيل الصوت -->
                                        <div class="voice-recording-ui" id="voiceRecordingUI">
                                            <button class="btn-voice-cancel" id="voiceCancelBtn" title="إلغاء التسجيل">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                            <div class="voice-waveform">
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                                <div class="voice-waveform-bar"></div>
                                            </div>
                                            <div class="voice-timer" id="voiceTimer">0:00</div>
                                            <button class="btn-voice-send" id="voiceSendBtn" title="إرسال الرسالة الصوتية">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        </div>

                                        <!-- معاينة الملف -->
                                        <div class="file-preview" id="filePreview">
                                            <i class="fas fa-file file-icon" id="filePreviewIcon"></i>
                                            <div class="file-info">
                                                <div class="file-name" id="filePreviewName"></div>
                                                <div class="file-size" id="filePreviewSize"></div>
                                            </div>
                                            <button class="btn-remove-file" onclick="removeFile()"><i class="fas fa-times"></i></button>
                                        </div>

                                        <!-- شريط الإدخال -->
                                        <div class="chat-input-wrapper" id="chatInputWrapper">
                                            <input type="hidden" id="receiver_id" value="<?php echo $receiver_id; ?>">
                                            <input type="hidden" id="currentPeerId" value="<?php echo $receiver_id; ?>">

                                            <!-- زر الملصقات/الإيموجي -->
                                            <button class="btn-emoji" id="stickerBtn" type="button" title="ملصقات وإيموجي">
                                                <i class="far fa-smile"></i>
                                            </button>

                                            <!-- زر إرفاق ملف -->
                                            <button class="btn-attach" onclick="document.getElementById('fileInput').click()" type="button" title="إرفاق ملف أو صورة">
                                                <i class="fas fa-paperclip"></i>
                                            </button>
                                            <input type="file" id="fileInput" style="display:none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt">

                                            <!-- حقل الرسالة -->
                                            <textarea class="form-control" id="messageInput" rows="1" placeholder="اكتب رسالتك هنا..." autocomplete="off"></textarea>

                                            <!-- زر المايكروفون -->
                                            <button class="btn-mic" id="micBtn" type="button" title="تسجيل رسالة صوتية">
                                                <i class="fas fa-microphone"></i>
                                            </button>

                                            <!-- زر الإرسال -->
                                            <button class="btn-send" id="sendBtn" type="button">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="empty-state">
                                        <i class="bi bi-chat-dots-fill"></i>
                                        <h5>مرحباً بك في مركز المراسلة الذكي</h5>
                                        <p class="text-muted">اختر أحد الزملاء من القائمة الجانبية لبدء محادثة فورية ومشاركة الملفات</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Toast -->
    <div class="chat-toast" id="chatToast"></div>

    <!-- Image Viewer Modal -->
    <div class="modal fade" id="imageViewerModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content bg-dark border-0">
                <div class="modal-body p-0 text-center">
                    <img src="" id="viewerImage" style="max-width:100%; max-height:80vh;" alt="">
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button class="btn btn-light" data-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <script src="plugins/jquery/jquery.min.js"></script>
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/adminlte.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/emojionearea/3.4.2/emojionearea.min.js"></script>
    <script>
    // ??? State ???
    const CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
    const MY_AVATAR = <?php echo json_encode(!empty($_SESSION['file_path']) ? "../uploads/" . $_SESSION['file_path'] : "dist/img/avatar5.png"); ?>;
    const PEER_AVATAR = <?php echo json_encode($peer ? (!empty($peer['file_path']) ? "../uploads/" . $peer['file_path'] : "dist/img/avatar5.png") : ''); ?>;
    let peerId = <?php echo $receiver_id ?: 'null'; ?>;
    let lastMessageId = 0;
    let shouldScroll = true;
    let typingTimeout = null;
    let isTyping = false;
    let selectedFile = null;

    // ??? Utility ???
    function showToast(msg) {
        const t = document.getElementById('chatToast');
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._hide);
        t._hide = setTimeout(() => t.classList.remove('show'), 3000);
    }

    function scrollToBottom() {
        const el = document.getElementById('chatMessages');
        if (el) { el.scrollTop = el.scrollHeight; }
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function formatTime(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr);
        const today = new Date();
        const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'اليوم';
        if (d.toDateString() === yesterday.toDateString()) return 'أمس';
        return d.toLocaleDateString('ar-SA', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ════════════════════════════════════════════
    //  نظام الرنات المتقدم
    // ════════════════════════════════════════════
    const NotificationSound = {
        ctx: null,
        enabled:  localStorage.getItem('chat_sound')   !== 'off',
        volume:   parseFloat(localStorage.getItem('chat_volume') || '0.5'),
        ringId:   localStorage.getItem('chat_ringtone') || 'classic',
        customDataUrl: localStorage.getItem('chat_custom_ring') || null,
        customAudio: null,

        init() {
            try { this.ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
        },

        /* ── الرنات المدمجة (Web Audio API) ── */
        rings: {
            /* كلاسيكي: ثلاث نغمات تصاعدية */
            classic(ctx, vol, now) {
                [[523,0],[659,.12],[784,.24]].forEach(([f,d]) => {
                    const o=ctx.createOscillator(), g=ctx.createGain();
                    o.type='sine'; o.frequency.value=f;
                    g.gain.setValueAtTime(vol,now+d);
                    g.gain.exponentialRampToValueAtTime(.01,now+d+.22);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now+d); o.stop(now+d+.25);
                });
            },
            /* واتساب: نغمتان صاعدتان */
            whatsapp(ctx, vol, now) {
                [[880,0,0],[1108,.18,0]].forEach(([f,d]) => {
                    const o=ctx.createOscillator(), g=ctx.createGain();
                    o.type='sine'; o.frequency.value=f;
                    g.gain.setValueAtTime(vol*.8,now+d);
                    g.gain.exponentialRampToValueAtTime(.01,now+d+.3);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now+d); o.stop(now+d+.35);
                });
            },
            /* جرس: نغمة جرس حقيقية (triangle + تلاشٍ) */
            bell(ctx, vol, now) {
                [[880,1],[1320,.5],[1760,.25]].forEach(([f,r]) => {
                    const o=ctx.createOscillator(), g=ctx.createGain();
                    o.type='triangle'; o.frequency.value=f;
                    g.gain.setValueAtTime(vol*r*.6,now);
                    g.gain.exponentialRampToValueAtTime(.001,now+.8);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now); o.stop(now+.85);
                });
            },
            /* نبض: ثلاث نبضات سريعة */
            pulse(ctx, vol, now) {
                [0,.18,.36].forEach(d => {
                    const o=ctx.createOscillator(), g=ctx.createGain();
                    o.type='sine'; o.frequency.value=660;
                    g.gain.setValueAtTime(vol*.7,now+d);
                    g.gain.exponentialRampToValueAtTime(.001,now+d+.12);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now+d); o.stop(now+d+.14);
                });
            },
            /* صعود: تصاعد موسيقي سريع */
            rise(ctx, vol, now) {
                const o=ctx.createOscillator(), g=ctx.createGain();
                o.type='sine';
                o.frequency.setValueAtTime(300,now);
                o.frequency.linearRampToValueAtTime(1200,now+.35);
                g.gain.setValueAtTime(vol*.7,now);
                g.gain.setValueAtTime(vol*.7,now+.25);
                g.gain.exponentialRampToValueAtTime(.001,now+.45);
                o.connect(g); g.connect(ctx.destination);
                o.start(now); o.stop(now+.48);
            },
            /* هادئ: نغمة ناعمة خافتة */
            soft(ctx, vol, now) {
                const o=ctx.createOscillator(), g=ctx.createGain();
                o.type='sine'; o.frequency.value=528;
                g.gain.setValueAtTime(0,now);
                g.gain.linearRampToValueAtTime(vol*.5,now+.08);
                g.gain.exponentialRampToValueAtTime(.001,now+.6);
                o.connect(g); g.connect(ctx.destination);
                o.start(now); o.stop(now+.65);
            },
            /* موسيقي: لحن قصير */
            melody(ctx, vol, now) {
                [[523,.0],[659,.12],[784,.24],[659,.36],[784,.48],[1047,.60]].forEach(([f,d]) => {
                    const o=ctx.createOscillator(), g=ctx.createGain();
                    o.type='sine'; o.frequency.value=f;
                    g.gain.setValueAtTime(vol*.65,now+d);
                    g.gain.exponentialRampToValueAtTime(.001,now+d+.14);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now+d); o.stop(now+d+.16);
                });
            },
            /* تريل: رعشة موسيقية سريعة */
            trill(ctx, vol, now) {
                for (let i=0;i<8;i++) {
                    const o=ctx.createOscillator(), g=ctx.createGain();
                    o.type='sine'; o.frequency.value = i%2===0 ? 880 : 1046;
                    g.gain.setValueAtTime(vol*.55,now+i*.065);
                    g.gain.exponentialRampToValueAtTime(.001,now+i*.065+.08);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now+i*.065); o.stop(now+i*.065+.09);
                }
            }
        },

        /* ── تشغيل الرنة الحالية ── */
        play() {
            if (!this.enabled) return;

            /* رنة مخصصة من ملف */
            if (this.ringId === 'custom' && this.customDataUrl) {
                if (!this.customAudio) {
                    this.customAudio = new Audio(this.customDataUrl);
                }
                this.customAudio.volume = this.volume;
                this.customAudio.currentTime = 0;
                this.customAudio.play().catch(()=>{});
                return;
            }

            /* رنة مدمجة */
            if (!this.ctx) return;
            if (this.ctx.state === 'suspended') this.ctx.resume();
            const fn = this.rings[this.ringId] || this.rings.classic;
            fn(this.ctx, this.volume, this.ctx.currentTime);
        },

        /* ── معاينة رنة محددة ── */
        preview(id) {
            if (!this.ctx) return;
            if (this.ctx.state === 'suspended') this.ctx.resume();
            if (id === 'custom' && this.customDataUrl) {
                const a = new Audio(this.customDataUrl);
                a.volume = this.volume; a.play().catch(()=>{});
                return;
            }
            const fn = this.rings[id] || this.rings.classic;
            fn(this.ctx, this.volume, this.ctx.currentTime);
        },

        /* ── حفظ الإعدادات ── */
        save() {
            localStorage.setItem('chat_sound',    this.enabled ? 'on' : 'off');
            localStorage.setItem('chat_volume',   this.volume.toString());
            localStorage.setItem('chat_ringtone', this.ringId);
            if (this.customDataUrl)
                localStorage.setItem('chat_custom_ring', this.customDataUrl);
        }
    };

    // ??? Desktop Notifications ???
    let notifEnabled = localStorage.getItem('chat_desktop_notif') !== 'off';
    let lastNotifTime = 0;

    function requestNotifPermission() {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    function sendDesktopNotification(title, body, icon) {
        if (!notifEnabled || !('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        // Prevent duplicate rapid notifications
        const now = Date.now();
        if (now - lastNotifTime < 3000) return;
        lastNotifTime = now;
        try {
            const n = new Notification(title, {
                body: body,
                icon: icon || '/UltimatesolutionsCrm/admin/dist/img/logo.png',
                tag: 'chat-message',
                silent: true
            });
            setTimeout(() => n.close(), 5000);
            n.onclick = () => { window.focus(); n.close(); };
        } catch(e) {}
    }

    // ??? Settings Toggle UI ???
    function addNotifSettingsToggle() {
        const header = document.querySelector('.chat-header-actions');
        if (!header) return;
        const btn = document.createElement('button');
        btn.className = 'btn';
        btn.id = 'notifSettingsBtn';
        btn.title = 'إعدادات الإشعارات';
        btn.innerHTML = '<i class="fas fa-bell"></i>';
        btn.onclick = toggleNotifPanel;
        header.appendChild(btn);
    }

    /* قائمة الرنات مع أوصافها */
    const RING_LIST = [
        { id:'classic',  label:'كلاسيكي',    icon:'fa-music',         desc:'ثلاث نغمات تصاعدية' },
        { id:'whatsapp', label:'واتساب',      icon:'fa-comment-dots',  desc:'نغمتان صاعدتان' },
        { id:'bell',     label:'جرس',         icon:'fa-bell',          desc:'رنة جرس طبيعية' },
        { id:'pulse',    label:'نبض',         icon:'fa-circle',        desc:'ثلاث نبضات سريعة' },
        { id:'rise',     label:'صعود',        icon:'fa-arrow-up',      desc:'تصاعد موسيقي' },
        { id:'soft',     label:'هادئ',        icon:'fa-volume-down',   desc:'نغمة ناعمة خافتة' },
        { id:'melody',   label:'لحن',         icon:'fa-headphones',    desc:'لحن موسيقي قصير' },
        { id:'trill',    label:'تريل',        icon:'fa-wave-square',   desc:'رعشة موسيقية سريعة' },
        { id:'custom',   label:'مخصص',        icon:'fa-upload',        desc:'رفع صوت خاص بك' },
    ];

    function toggleNotifPanel() {
        let panel = document.getElementById('notifSettingsPanel');
        if (panel) { panel.remove(); return; }

        const vol = Math.round(NotificationSound.volume * 100);
        const curRing = NotificationSound.ringId;

        panel = document.createElement('div');
        panel.id = 'notifSettingsPanel';
        panel.style.cssText = `
            position:absolute; top:64px; left:10px;
            background:#fff; border-radius:14px;
            box-shadow:0 8px 32px rgba(0,0,0,0.18);
            padding:0; z-index:300; width:300px;
            border:1px solid #e9edef; overflow:hidden;
            direction:rtl;
        `;

        panel.innerHTML = `
        <!-- رأس اللوحة -->
        <div style="background:linear-gradient(135deg,#1a5276,#2980b9);padding:14px 18px;color:#fff;">
            <div style="font-weight:700;font-size:0.95rem;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-bell"></i> إعدادات الإشعارات
            </div>
        </div>

        <!-- تبويبات -->
        <div style="display:flex;border-bottom:1px solid #e9edef;background:#f8f9fa;">
            <button id="tabGeneral" onclick="switchNotifTab('general')"
                style="flex:1;padding:10px 0;font-size:0.82rem;font-weight:700;border:none;background:#fff;color:var(--chat-primary,#007bff);border-bottom:2px solid var(--chat-primary,#007bff);cursor:pointer;font-family:Cairo,sans-serif;">
                <i class="fas fa-cog ml-1"></i>عام
            </button>
            <button id="tabRingtone" onclick="switchNotifTab('ringtone')"
                style="flex:1;padding:10px 0;font-size:0.82rem;font-weight:600;border:none;background:transparent;color:#667781;cursor:pointer;font-family:Cairo,sans-serif;">
                <i class="fas fa-music ml-1"></i>الرنة
            </button>
        </div>

        <!-- تاب: عام -->
        <div id="tabContentGeneral" style="padding:16px;">
            <label style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;cursor:pointer;">
                <span style="font-size:0.88rem;font-weight:600;color:#111;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-volume-up" style="color:#007bff;width:16px;"></i>صوت الإشعارات
                </span>
                <input type="checkbox" id="soundToggle" ${NotificationSound.enabled ? 'checked' : ''}
                       style="width:18px;height:18px;cursor:pointer;">
            </label>
            <label style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;cursor:pointer;">
                <span style="font-size:0.88rem;font-weight:600;color:#111;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-desktop" style="color:#007bff;width:16px;"></i>إشعارات سطح المكتب
                </span>
                <input type="checkbox" id="desktopNotifToggle" ${notifEnabled ? 'checked' : ''}
                       style="width:18px;height:18px;cursor:pointer;">
            </label>

            <!-- مستوى الصوت -->
            <div style="margin-bottom:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:0.85rem;font-weight:600;color:#111;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-sliders-h" style="color:#007bff;width:16px;"></i>مستوى الصوت
                    </span>
                    <span id="volLabel" style="font-size:0.8rem;font-weight:700;color:var(--chat-primary,#007bff);">${vol}%</span>
                </div>
                <input type="range" id="volumeSlider" min="0" max="100" value="${vol}"
                       style="width:100%;height:5px;border-radius:5px;outline:none;cursor:pointer;
                              accent-color:var(--chat-primary,#007bff);">
            </div>

            <!-- الرنة المختارة -->
            <div style="background:#f8f9fa;border-radius:8px;padding:10px 12px;font-size:0.82rem;color:#54656f;display:flex;align-items:center;justify-content:space-between;">
                <span><i class="fas fa-music ml-1" style="color:#007bff;"></i>الرنة الحالية: <strong id="curRingLabel">${RING_LIST.find(r=>r.id===curRing)?.label||'كلاسيكي'}</strong></span>
                <button onclick="switchNotifTab('ringtone')" style="font-size:0.78rem;background:none;border:none;color:var(--chat-primary,#007bff);cursor:pointer;font-family:Cairo,sans-serif;font-weight:600;">تغيير</button>
            </div>

            <div style="padding-top:12px;border-top:1px solid #e9edef;margin-top:14px;">
                <button onclick="testNotification()"
                    style="width:100%;padding:9px;border-radius:8px;background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;border:none;font-size:0.85rem;font-weight:700;cursor:pointer;font-family:Cairo,sans-serif;">
                    <i class="fas fa-play ml-1"></i>اختبار الرنة
                </button>
            </div>
        </div>

        <!-- تاب: الرنة -->
        <div id="tabContentRingtone" style="display:none;padding:12px 0;">
            <div style="padding:0 14px 8px;font-size:0.78rem;color:#667781;">اختر رنة الإشعارات</div>
            <div id="ringListContainer" style="max-height:280px;overflow-y:auto;">
            ${RING_LIST.map(r => `
                <div class="ring-option" data-ring-id="${r.id}"
                     onclick="selectRingtone('${r.id}')"
                     style="display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;
                            transition:background .15s;border-bottom:1px solid #f0f2f5;
                            ${r.id===curRing ? 'background:#e8f4fd;' : ''}">
                    <div style="width:36px;height:36px;border-radius:50%;
                                background:${r.id===curRing ? 'var(--chat-primary,#007bff)' : '#f0f2f5'};
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;
                                transition:all .2s;">
                        <i class="fas ${r.icon}" style="font-size:0.85rem;color:${r.id===curRing ? '#fff' : '#667781'};"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.88rem;font-weight:${r.id===curRing ? '700' : '600'};
                                    color:${r.id===curRing ? 'var(--chat-primary,#007bff)' : '#111'};">${r.label}</div>
                        <div style="font-size:0.72rem;color:#667781;">${r.desc}</div>
                    </div>
                    ${r.id==='custom' ? `
                        <label style="cursor:pointer;padding:4px 10px;border-radius:6px;background:#e8f4fd;color:var(--chat-primary,#007bff);font-size:0.75rem;font-weight:600;white-space:nowrap;">
                            <i class="fas fa-upload ml-1"></i>رفع
                            <input type="file" id="customRingInput" accept="audio/*" style="display:none;" onchange="uploadCustomRing(this)">
                        </label>
                    ` : `
                        <button onclick="event.stopPropagation();NotificationSound.preview('${r.id}')"
                                style="width:30px;height:30px;border-radius:50%;border:1.5px solid #e9edef;
                                       background:#fff;cursor:pointer;color:#667781;font-size:0.75rem;"
                                title="معاينة">
                            <i class="fas fa-play"></i>
                        </button>
                    `}
                </div>
            `).join('')}
            </div>
        </div>
        `;

        document.querySelector('.chat-header')?.appendChild(panel);

        /* ── منطق التبويبات ── */
        window.switchNotifTab = (tab) => {
            const isGen = tab === 'general';
            document.getElementById('tabContentGeneral').style.display  = isGen ? 'block' : 'none';
            document.getElementById('tabContentRingtone').style.display = isGen ? 'none'  : 'block';
            document.getElementById('tabGeneral').style.cssText   += isGen
                ? ';color:var(--chat-primary,#007bff);border-bottom:2px solid var(--chat-primary,#007bff);background:#fff;'
                : ';color:#667781;border-bottom:none;background:transparent;';
            document.getElementById('tabRingtone').style.cssText  += isGen
                ? ';color:#667781;border-bottom:none;background:transparent;'
                : ';color:var(--chat-primary,#007bff);border-bottom:2px solid var(--chat-primary,#007bff);background:#fff;';
        };

        /* ── تبديل صوت الإشعارات ── */
        document.getElementById('soundToggle')?.addEventListener('change', function() {
            NotificationSound.enabled = this.checked;
            NotificationSound.save();
        });

        /* ── إشعارات سطح المكتب ── */
        document.getElementById('desktopNotifToggle')?.addEventListener('change', function() {
            notifEnabled = this.checked;
            localStorage.setItem('chat_desktop_notif', this.checked ? 'on' : 'off');
            if (this.checked) requestNotifPermission();
        });

        /* ── مستوى الصوت ── */
        document.getElementById('volumeSlider')?.addEventListener('input', function() {
            NotificationSound.volume = parseInt(this.value) / 100;
            document.getElementById('volLabel').textContent = this.value + '%';
            NotificationSound.save();
        });

        /* ── إغلاق بالضغط خارج اللوحة ── */
        setTimeout(() => {
            document.addEventListener('click', function closePanel(e) {
                if (!e.target.closest('#notifSettingsPanel') && !e.target.closest('#notifSettingsBtn')) {
                    panel.remove();
                    document.removeEventListener('click', closePanel);
                }
            });
        }, 100);
    }

    /* اختيار رنة */
    window.selectRingtone = (id) => {
        NotificationSound.ringId = id;
        NotificationSound.save();

        /* تحديث الـ UI */
        document.querySelectorAll('.ring-option').forEach(el => {
            const rid = el.dataset.ringId;
            const isActive = rid === id;
            el.style.background = isActive ? '#e8f4fd' : '';
            const icon = el.querySelector('div > i');
            const iconWrap = el.querySelector('div');
            if (icon && iconWrap) {
                iconWrap.style.background = isActive ? 'var(--chat-primary,#007bff)' : '#f0f2f5';
                icon.style.color = isActive ? '#fff' : '#667781';
            }
            const label = el.querySelector('div:nth-child(2) > div:first-child');
            if (label) {
                label.style.fontWeight = isActive ? '700' : '600';
                label.style.color = isActive ? 'var(--chat-primary,#007bff)' : '#111';
            }
        });

        const ringLabel = RING_LIST.find(r => r.id === id)?.label || id;
        const curLabel = document.getElementById('curRingLabel');
        if (curLabel) curLabel.textContent = ringLabel;

        if (id !== 'custom') NotificationSound.preview(id);
    };

    /* رفع رنة مخصصة */
    window.uploadCustomRing = (input) => {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            showToast('⚠️ الحجم الأقصى للرنة 2 ميجابايت');
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            NotificationSound.customDataUrl = e.target.result;
            NotificationSound.customAudio = null;
            selectRingtone('custom');
            NotificationSound.save();
            showToast('✅ تم رفع الرنة المخصصة بنجاح');
        };
        reader.readAsDataURL(file);
    };

    function testNotification() {
        NotificationSound.play();
        sendDesktopNotification('🔔 اختبار الإشعارات', 'تم تفعيل الإشعارات بنجاح!');
    }

    // ??? Track last notified message ???
    let lastNotifiedMsgId = 0;

    function notifyNewMessage(msg) {
        if (parseInt(msg.sender_id) === CURRENT_USER_ID) return;
        if (parseInt(msg.id) <= lastNotifiedMsgId) return;
        lastNotifiedMsgId = parseInt(msg.id);

        NotificationSound.play();

        if (!document.hasFocus() || document.hidden) {
            let preview = msg.message_text;
            if (msg.message_type === 'image') preview = '?? أرسل صورة';
            else if (msg.message_type === 'file') preview = '?? أرسل ملفاً';
            sendDesktopNotification(
                'رسالة جديدة من ' + (msg.sender_name || 'زميل'),
                preview
            );
        }
    }

    // ??? User Search ???
    document.getElementById('userSearchInput')?.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        // البحث في عناصر القائمة (a وdiv كلاهما)
        document.querySelectorAll('.chat-user-item, .chat-users-list > div[data-name]').forEach(el => {
            const name = (el.dataset.name || el.querySelector('.chat-user-name')?.textContent || '').toLowerCase();
            el.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
        // إخفاء/إظهار رؤوس المجموعات إذا لم يبقَ عناصر مرئية
        document.querySelectorAll('.chat-group-header').forEach(header => {
            let next = header.nextElementSibling;
            let hasVisible = false;
            while (next && !next.classList.contains('chat-group-header')) {
                if (next.style.display !== 'none' && (next.classList.contains('chat-user-item') || next.dataset.name)) hasVisible = true;
                next = next.nextElementSibling;
            }
            header.style.display = hasVisible ? '' : 'none';
        });
    });

    // ── Toggle Users Panel (mobile) ──
    function toggleUsersPanel() {
        const panel = document.getElementById('chatUsersPanel');
        panel.classList.toggle('hide');
    }

    // على الجوال: إذا كانت محادثة مفتوحة → اخفِ لوحة المستخدمين تلقائياً
    (function initMobileLayout() {
        if (window.innerWidth <= 768 && peerId) {
            const panel = document.getElementById('chatUsersPanel');
            if (panel) panel.classList.add('hide');
        }
    })();

    // عند تغيير حجم الشاشة: إعادة إظهار اللوحة على الشاشات الكبيرة
    window.addEventListener('resize', function() {
        const panel = document.getElementById('chatUsersPanel');
        if (!panel) return;
        if (window.innerWidth > 768) {
            panel.classList.remove('hide');
            panel.style.transform = '';
        }
    });

    // ??? Fetch Messages ???
    function fetchMessages() {
        if (!peerId) return;
        $.get('fetch_messages.php', {
            user_id: peerId,
            last_id: lastMessageId
        }, function(data) {
            try {
                const res = typeof data === 'string' ? JSON.parse(data) : data;
                if (res.error) return;

                const container = document.getElementById('chatMessages');
                const isFirstLoad = lastMessageId === 0;

                // Clear on first load
                if (isFirstLoad) {
                    container.innerHTML = '';
                }

                if (res.messages && res.messages.length > 0) {
                    let lastDate = '';
                    let html = '';

                    res.messages.forEach(msg => {
                        const msgDate = new Date(msg.created_at).toDateString();
                        if (msgDate !== lastDate) {
                            lastDate = msgDate;
                            html += `<div class="date-divider"><span>${formatDate(msg.created_at)}</span></div>`;
                        }
                        html += renderMessage(msg);
                        if (parseInt(msg.id) > lastMessageId) {
                            lastMessageId = parseInt(msg.id);
                        }
                        // Trigger notifications for new incoming messages
                        if (!isFirstLoad) {
                            notifyNewMessage(msg);
                        }
                    });

                    if (isFirstLoad) {
                        container.innerHTML = html;
                    } else {
                        container.insertAdjacentHTML('beforeend', html);
                    }

                    if (shouldScroll || isFirstLoad) {
                        scrollToBottom();
                    }
                } else if (isFirstLoad) {
                    container.innerHTML = '<div class="text-center mt-5 text-muted"><p>لا توجد رسائل بعد. ابدأ المحادثة!</p></div>';
                }

                // Update seen status for older messages
                if (res.seen_at) {
                    document.querySelectorAll('.msg-seen[data-msg-id="' + res.seen_msg_id + '"]').forEach(el => {
                        el.className = 'msg-seen seen';
                        el.innerHTML = '<i class="fas fa-check-double"></i>';
                    });
                }
            } catch(e) {
                console.error('Fetch error:', e);
            }
        });
    }

    function renderMessage(msg) {
        const isMe = parseInt(msg.sender_id) === CURRENT_USER_ID;
        const sentClass = isMe ? 'sent' : 'received';
        const seenIcon = isMe ? (msg.is_read && msg.seen_at ? '<i class="fas fa-check-double"></i>' : '<i class="fas fa-check"></i>') : '';
        const seenClass = isMe ? (msg.is_read && msg.seen_at ? 'seen' : 'delivered') : '';

        const avatarSrc = isMe ? MY_AVATAR : (msg.sender_file_path ? `../uploads/${escapeHtml(msg.sender_file_path)}` : 'dist/img/avatar5.png');

        // ── ملصق (Sticker) ──
        if (msg.message_type === 'sticker') {
            return `<div class="msg-wrapper ${sentClass}" data-msg-id="${msg.id}">
                ${isMe ? '' : `<img src="${avatarSrc}" class="msg-avatar" alt="">`}
                <div class="msg-bubble msg-sticker" title="${formatTime(msg.created_at)}">
                    <span class="msg-text sticker-text">${escapeHtml(msg.message_text)}</span>
                    <div class="msg-meta" style="font-size:0.6rem;">
                        <span class="msg-time">${formatTime(msg.created_at)}</span>
                        ${isMe ? `<span class="msg-seen ${seenClass}">${seenIcon}</span>` : ''}
                    </div>
                </div>
                ${isMe ? `<img src="${avatarSrc}" class="msg-avatar" alt="">` : ''}
            </div>`;
        }

        // ── إشعار النظام (مهمة / وثيقة) ──
        const systemPrefixes = ['🔧 مهمة جديدة مُسنَدة إليك', '📄 طلب اعتماد وثيقة'];
        const isSystemNotif = systemPrefixes.some(p => msg.message_text && msg.message_text.startsWith(p));
        if (isSystemNotif) {
            const lines   = msg.message_text.split('\n');
            const title   = lines[0] || '';
            const isTask  = title.includes('مهمة');
            const icon    = isTask ? '🔧' : '📄';
            const color   = isTask ? '#1d4ed8' : '#5b21b6';
            const bgColor = isTask ? '#eff6ff' : '#f5f3ff';
            const border  = isTask ? '#bfdbfe' : '#ddd6fe';
            const bodyLines = lines.slice(1).filter(l => l.trim());
            const bodyHtml  = bodyLines.map(l => {
                const clean = l.replace(/^[🔧📄📍📋⚡📅➡️✅🗓]+\s*/, '');
                const label = l.match(/^([^:]+):/) ? `<strong>${l.match(/^([^:]+):/)[1]}:</strong>${l.replace(/^[^:]+:/, '')}` : l;
                return `<div style="font-size:.78rem;color:#334155;margin-bottom:2px">${escapeHtml(l)}</div>`;
            }).join('');

            return `<div class="msg-wrapper received" data-msg-id="${msg.id}" style="padding:0 12px;margin-bottom:6px">
                <div style="background:${bgColor};border:1.5px solid ${border};border-radius:12px;padding:12px 14px;max-width:85%;border-right:4px solid ${color}">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        <span style="font-size:1.1rem">${icon}</span>
                        <strong style="font-size:.85rem;color:${color}">${escapeHtml(title.replace(/^[🔧📄]\s*/,''))}</strong>
                    </div>
                    ${bodyHtml}
                    <div style="font-size:.68rem;color:#94a3b8;margin-top:6px;text-align:left">${formatTime(msg.created_at)}</div>
                </div>
            </div>`;
        }

        let attachmentHtml = '';
        let hideText = false;  // هل نخفي النص؟

        // ── صورة ──
        if (msg.message_type === 'image' && msg.file_path) {
            const src = `../uploads/chat/${escapeHtml(msg.file_path)}`;
            attachmentHtml = `<div class="msg-attachment">
                <img src="${src}" alt="صورة" onclick="viewImage('${src}')" loading="lazy">
            </div>`;
        }
        // ── ملف ──
        else if (msg.message_type === 'file' && msg.file_path) {
            const ext = msg.file_name ? msg.file_name.split('.').pop().toLowerCase() : '';
            const icons = { pdf:'fa-file-pdf text-danger', doc:'fa-file-word text-primary', docx:'fa-file-word text-primary',
                            xls:'fa-file-excel text-success', xlsx:'fa-file-excel text-success',
                            zip:'fa-file-archive text-warning', rar:'fa-file-archive text-warning',
                            txt:'fa-file-alt', csv:'fa-file-csv' };
            const iconClass = icons[ext] || 'fa-file-alt';
            attachmentHtml = `<a href="../uploads/chat/${escapeHtml(msg.file_path)}" class="msg-file"
                    download="${escapeHtml(msg.file_name || 'ملف')}" target="_blank">
                <i class="fas ${iconClass}" style="font-size:26px;"></i>
                <div class="file-info">
                    <div class="file-name">${escapeHtml(msg.file_name || 'ملف')}</div>
                    <div class="file-size">${msg.file_size ? formatFileSize(msg.file_size) : ''}</div>
                </div>
                <i class="fas fa-download download-link"></i>
            </a>`;
        }
        // ── رسالة صوتية (مع ملف) ──
        else if (msg.message_type === 'voice' && msg.file_path) {
            hideText = true;
            const aId  = 'va_' + msg.id;
            const aSrc = `../uploads/chat/${escapeHtml(msg.file_path)}`;
            attachmentHtml = `
            <div class="msg-voice" id="mv_${aId}">
                <!-- زر التشغيل -->
                <button class="v-play-btn" id="vbtn_${aId}" onclick="voiceToggle('${aId}',this)" type="button" title="تشغيل">
                    <i class="fas fa-play" id="vi_${aId}"></i>
                </button>
                <!-- شريط التقدم والمعلومات -->
                <div class="v-info">
                    <div style="display:flex;align-items:center;gap:5px;">
                        <i class="fas fa-microphone" style="font-size:0.75rem;color:#667781;opacity:0.8;"></i>
                        <input type="range" class="v-progress" id="vp_${aId}"
                               value="0" min="0" step="0.01"
                               oninput="voiceSeek('${aId}',this.value)"
                               style="flex:1;">
                    </div>
                    <span class="v-dur" id="vd_${aId}">0:00</span>
                </div>
                <!-- العنصر الصوتي المخفي -->
                <audio id="${aId}" preload="metadata"
                       onloadedmetadata="voiceLoaded('${aId}')"
                       ontimeupdate="voiceUpdate('${aId}')"
                       onended="voiceEnded('${aId}')"
                       onerror="voiceError('${aId}')">
                    <source src="${aSrc}" type="audio/webm;codecs=opus">
                    <source src="${aSrc}" type="audio/webm">
                    <source src="${aSrc}" type="audio/ogg">
                    <source src="${aSrc}" type="audio/mpeg">
                </audio>
            </div>`;
        }
        // ── رسالة صوتية بدون ملف (خطأ في الرفع) ──
        else if (msg.message_type === 'voice' && !msg.file_path) {
            hideText = true;
            attachmentHtml = `<div style="display:flex;align-items:center;gap:8px;padding:4px 0;opacity:0.6;">
                <i class="fas fa-microphone-slash" style="font-size:1.1rem;color:#667781;"></i>
                <span style="font-size:0.82rem;color:#667781;">رسالة صوتية • غير متاحة</span>
            </div>`;
        }

        const editedHtml = msg.edited_at ? '<span class="msg-edited">(معدّل)</span>' : '';
        const actionsHtml = isMe ? `
            <div class="msg-actions">
                <button class="btn-msg-action" onclick="editMessage(${msg.id})" title="تعديل"><i class="fas fa-pen"></i></button>
                <button class="btn-msg-action danger" onclick="deleteMessage(${msg.id})" title="حذف"><i class="fas fa-trash"></i></button>
            </div>` : `
            <div class="msg-actions">
                <button class="btn-msg-action danger" onclick="deleteMessage(${msg.id})" title="حذف"><i class="fas fa-trash"></i></button>
            </div>`;

        // أزرار ردود الفعل السريعة
        const quickReactions = ['👍','❤️','😂','😮','😢','🙏'];
        const reactionsBar = `<div class="reaction-picker-bar">
            ${quickReactions.map(e => `<button class="r-pick-btn" onclick="sendReaction(${msg.id},'${e}')" title="${e}">${e}</button>`).join('')}
        </div>`;

        // النص: يظهر فقط إذا لم يكن مخفياً ولم يكن النص 🎤 فارغاً
        const textContent = msg.message_text && msg.message_text !== '🎤' ? msg.message_text : '';
        const textHtml = (!hideText && textContent)
            ? `<div class="msg-text">${escapeHtml(textContent)}</div>`
            : '';

        return `<div class="msg-wrapper ${sentClass}" data-msg-id="${msg.id}">
            ${isMe ? '' : `<img src="${avatarSrc}" class="msg-avatar" alt="">`}
            <div class="msg-bubble">
                ${reactionsBar}
                ${attachmentHtml}
                ${textHtml}
                <div class="msg-meta">
                    ${editedHtml}
                    <span class="msg-time">${formatTime(msg.created_at)}</span>
                    ${isMe ? `<span class="msg-seen ${seenClass}">${seenIcon}</span>` : ''}
                </div>
                ${actionsHtml}
            </div>
            ${isMe ? `<img src="${avatarSrc}" class="msg-avatar" alt="">` : ''}
        </div>`;
    }

    // ??? Send Message ???
    function sendMessage() {
        const input = document.getElementById('messageInput');
        const text = input.value.trim();
        const fileInput = document.getElementById('fileInput');

        if (!text && !selectedFile) { return; }

        const formData = new FormData();
        formData.append('receiver_id', peerId);
        formData.append('message', text);

        if (selectedFile) {
            formData.append('attachment', selectedFile);
        }

        document.getElementById('sendBtn').disabled = true;

        $.ajax({
            url: 'send_message.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    input.value = '';
                    input.style.height = 'auto';
                    removeFile();
                    shouldScroll = true;
                    lastMessageId = 0;
                    fetchMessages();
                } else {
                    showToast(res.error || 'حدث خطأ أثناء الإرسال');
                }
            },
            error: function() {
                showToast('خطأ في الاتصال بالخادم');
            },
            complete: function() {
                document.getElementById('sendBtn').disabled = false;
            }
        });
    }

    // ??? File Handling ???
    document.getElementById('fileInput')?.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            selectedFile = this.files[0];
            const preview = document.getElementById('filePreview');
            document.getElementById('filePreviewName').textContent = selectedFile.name;
            document.getElementById('filePreviewSize').textContent = formatFileSize(selectedFile.size);

            const icon = document.getElementById('filePreviewIcon');
            if (selectedFile.type.startsWith('image/')) {
                icon.className = 'fas fa-image file-icon';
            } else {
                icon.className = 'fas fa-file-alt file-icon';
            }
            preview.classList.add('show');
        }
    });

    function removeFile() {
        selectedFile = null;
        document.getElementById('fileInput').value = '';
        document.getElementById('filePreview').classList.remove('show');
    }

    // ??? Image Viewer ???
    function viewImage(src) {
        document.getElementById('viewerImage').src = src;
        $('#imageViewerModal').modal('show');
    }

    // ??? Delete Message ???
    function deleteMessage(msgId) {
        if (!confirm('هل أنت متأكد من حذف هذه الرسالة؟')) return;
        $.post('delete_message.php', { message_id: msgId }, function(res) {
            try {
                const data = typeof res === 'string' ? JSON.parse(res) : res;
                if (data.success) {
                    const wrapper = document.querySelector(`.msg-wrapper[data-msg-id="${msgId}"]`);
                    if (wrapper) wrapper.remove();
                    showToast('تم حذف الرسالة');
                }
            } catch(e) {}
        });
    }

    // ??? Edit Message ???
    function editMessage(msgId) {
        const wrapper = document.querySelector(`.msg-wrapper[data-msg-id="${msgId}"]`);
        if (!wrapper) return;
        const bubble = wrapper.querySelector('.msg-text');
        const currentText = bubble.textContent;

        const newText = prompt('تعديل الرسالة:', currentText);
        if (newText && newText.trim() !== '' && newText.trim() !== currentText) {
            $.post('edit_message.php', {
                message_id: msgId,
                message: newText.trim()
            }, function(res) {
                try {
                    const data = typeof res === 'string' ? JSON.parse(res) : res;
                    if (data.success) {
                        bubble.textContent = newText.trim();
                        // Add edited indicator
                        const meta = wrapper.querySelector('.msg-meta');
                        if (!meta.querySelector('.msg-edited')) {
                            const edited = document.createElement('span');
                            edited.className = 'msg-edited';
                            edited.textContent = '(معدّل)';
                            meta.insertBefore(edited, meta.querySelector('.msg-time'));
                        }
                        showToast('تم تعديل الرسالة');
                    }
                } catch(e) {}
            });
        }
    }

    // ??? Typing Indicator ???
    document.getElementById('messageInput')?.addEventListener('input', function() {
        if (!isTyping) {
            isTyping = true;
            $.post('typing_status.php', { peer_id: peerId, is_typing: 1 });
        }
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(function() {
            isTyping = false;
            $.post('typing_status.php', { peer_id: peerId, is_typing: 0 });
        }, 1500);

        // Auto-resize textarea
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    function checkTyping() {
        if (!peerId) return;
        $.get('typing_status.php', { peer_id: peerId }, function(res) {
            try {
                const data = typeof res === 'string' ? JSON.parse(res) : res;
                const indicator = document.getElementById('typingIndicator');
                if (data.is_typing) {
                    document.getElementById('typingName').textContent = data.full_name + ' جارٍ الكتابة...';
                    indicator.classList.add('show');
                } else {
                    indicator.classList.remove('show');
                }
            } catch(e) {}
        });
    }

    // ??? Send on Enter ???
    document.getElementById('messageInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    document.getElementById('sendBtn')?.addEventListener('click', sendMessage);

    // (الإيموجي والملصقات يُدارا عبر stickerPanel أعلاه)

    // ??? Search Messages ???
    function toggleSearch() {
        const el = document.getElementById('inlineSearch');
        if (el.style.display === 'none' || !el.style.display) {
            el.style.display = 'block';
            document.getElementById('inlineSearchInput').focus();
        } else {
            el.style.display = 'none';
            document.getElementById('inlineSearchResults').style.display = 'none';
        }
    }

    let searchTimeout = null;
    document.getElementById('inlineSearchInput')?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('inlineSearchResults').style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(() => {
            $.get('search_messages.php', {
                q: q,
                peer_id: peerId
            }, function(data) {
                try {
                    const res = typeof data === 'string' ? JSON.parse(data) : data;
                    const container = document.getElementById('inlineSearchResults');
                    container.innerHTML = '';
                    if (res.results && res.results.length > 0) {
                        res.results.forEach(msg => {
                            const isMe = parseInt(msg.sender_id) === CURRENT_USER_ID;
                            const sender = isMe ? 'أنت' : msg.sender_name;
                            const highlighted = escapeHtml(msg.message_text).replace(
                                new RegExp(escapeHtml(q), 'gi'),
                                m => `<mark>${m}</mark>`
                            );
                            const div = document.createElement('div');
                            div.className = 'search-results-item';
                            div.innerHTML = `<div class="search-sender">${escapeHtml(sender)}</div>
                                             <div class="search-text">${highlighted}</div>
                                             <div class="search-time">${formatTime(msg.created_at)}</div>`;
                            div.onclick = function() {
                                const target = document.querySelector(`.msg-wrapper[data-msg-id="${msg.id}"]`);
                                if (target) {
                                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    target.style.background = '#fff3cd';
                                    setTimeout(() => target.style.background = '', 2000);
                                }
                            };
                            container.appendChild(div);
                        });
                        container.style.display = 'block';
                    } else {
                        container.innerHTML = '<div class="text-center p-2 text-muted">لا توجد نتائج</div>';
                        container.style.display = 'block';
                    }
                } catch(e) {}
            });
        }, 300);
    });

    // ??? Auto-scroll detection ???
    document.getElementById('chatMessages')?.addEventListener('scroll', function() {
        const threshold = 80;
        shouldScroll = (this.scrollHeight - this.scrollTop - this.clientHeight) < threshold;
    });

    // ══════════════════════════════════════════════
    // ملصقات وإيموجي (Sticker Panel)
    // ══════════════════════════════════════════════
    const STICKER_CATS = [
        { icon:'😀', name:'تعبيرات', items:['😀','😂','🤣','😊','🥰','😍','🤩','😎','😜','🤔','😴','🥺','😭','😤','😡','🤯','😱','🤑','😇','🤗','😒','😩','😫','🙄','😈','👻','💀','🤖','👾','🎭'] },
        { icon:'❤️', name:'مشاعر',  items:['❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💕','💞','💓','💗','💖','💘','💝','💟','❣️','🌹','🌷','💐','🌸','🌺','🌻','🌼','🎀','🎁','🎊','🎉','✨'] },
        { icon:'👍', name:'إيماءات', items:['👍','👎','👌','✌️','🤞','👊','✊','🙌','👏','🤝','🙏','💪','👋','🤙','🖐️','☝️','🤟','🤘','👈','👉','👆','👇','🫶','🫂','🤜','🤛','💅','🫰','🤲','✋'] },
        { icon:'🎉', name:'احتفال', items:['🎉','🎊','🎈','🎁','🎂','🍰','🥂','🍾','🎆','🎇','🌟','⭐','💫','🏆','🥇','🎖️','🏅','🎗️','🎪','🎭','🎨','🎬','🎤','🎸','🎮','🃏','🎲','🎯','🏹','🎀'] },
        { icon:'🌙', name:'طبيعة',  items:['🌙','☀️','🌈','⛅','🌊','🌺','🌸','🌹','🌻','🍀','🌿','🌱','🌲','🌳','🍁','🍂','🌾','🌵','🎋','🐶','🐱','🐭','🐰','🐻','🐼','🐨','🐯','🦁','🐸','🐧'] },
        { icon:'🍕', name:'طعام',   items:['🍕','🍔','🍟','🌮','🍜','🍝','🍛','🍚','🍣','🍤','🍗','🍦','🍧','🍩','🍪','🎂','🍫','🍬','🍭','☕','🍵','🧃','🥤','🍺','🥗','🥙','🥪','🧆','🧇','🥞'] },
        { icon:'⚽', name:'رياضة',  items:['⚽','🏀','🏈','⚾','🎾','🏐','🏉','🥏','🏓','🏸','🥊','🏋️','⛹️','🤸','🚴','🧘','🏊','🏄','🚣','🧗','⛷️','🏂','🏇','🤺','🤼','🤾','🎿','🛷','🥌','⛸️'] },
        { icon:'🚀', name:'تقنية',  items:['🚀','💻','📱','⌨️','🖥️','🖨️','🖱️','💾','💿','📷','📸','📹','🎥','📞','📟','📺','📻','🎙️','⏱️','🔋','🔌','💡','🔦','🔧','🔨','⚙️','🔩','🧲','🔭','🔬'] },
    ];

    let stickerPanelOpen = false;

    function initStickerPanel() {
        const tabs = document.getElementById('stickerTabs');
        if (!tabs || tabs.children.length > 0) return;

        STICKER_CATS.forEach((cat, i) => {
            const tab = document.createElement('button');
            tab.className = 'sticker-tab' + (i === 0 ? ' active' : '');
            tab.textContent = cat.icon;
            tab.title = cat.name;
            tab.onclick = () => {
                tabs.querySelectorAll('.sticker-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                renderStickerGrid(cat.items);
            };
            tabs.appendChild(tab);
        });

        renderStickerGrid(STICKER_CATS[0].items);

        document.getElementById('stickerSearch').addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            if (!q) {
                const activeIdx = [...tabs.querySelectorAll('.sticker-tab')].findIndex(t => t.classList.contains('active'));
                renderStickerGrid(STICKER_CATS[activeIdx >= 0 ? activeIdx : 0].items);
                return;
            }
            // بحث في كل الفئات
            const all = STICKER_CATS.flatMap(c => c.items);
            renderStickerGrid([...new Set(all)].slice(0, 56));
        });
    }

    function renderStickerGrid(items) {
        const grid = document.getElementById('stickerGrid');
        grid.innerHTML = '';
        items.forEach(e => {
            const btn = document.createElement('button');
            btn.className = 'sticker-item';
            btn.textContent = e;
            btn.type = 'button';
            btn.onclick = () => sendSticker(e);
            grid.appendChild(btn);
        });
    }

    function sendSticker(emoji) {
        document.getElementById('stickerPanel').classList.remove('show');
        stickerPanelOpen = false;

        const fd = new FormData();
        fd.append('receiver_id', peerId);
        fd.append('message', emoji);
        fd.append('message_type_override', 'sticker');

        fetch('send_message.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    lastMessageId = 0; shouldScroll = true; fetchMessages();
                } else { showToast('خطأ في الإرسال'); }
            })
            .catch(() => showToast('خطأ في الاتصال'));
    }

    // فتح/إغلاق لوحة الملصقات
    document.getElementById('stickerBtn')?.addEventListener('click', function(e) {
        e.stopPropagation();
        const panel = document.getElementById('stickerPanel');
        if (stickerPanelOpen) {
            panel.classList.remove('show');
            stickerPanelOpen = false;
        } else {
            initStickerPanel();
            panel.classList.add('show');
            stickerPanelOpen = true;
            document.getElementById('stickerSearch').value = '';
        }
    });

    document.addEventListener('click', function(e) {
        if (stickerPanelOpen && !e.target.closest('#stickerPanel') && !e.target.closest('#stickerBtn')) {
            document.getElementById('stickerPanel').classList.remove('show');
            stickerPanelOpen = false;
        }
    });

    // ══════════════════════════════════════════════
    // تسجيل الصوت (Voice Recording)
    // ══════════════════════════════════════════════
    const VoiceRec = {
        recorder: null,
        chunks: [],
        stream: null,
        timer: null,
        secs: 0,
        isRecording: false,

        async start() {
            if (!navigator.mediaDevices) {
                showToast('المتصفح لا يدعم تسجيل الصوت');
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                this.chunks = [];
                this.secs = 0;

                const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                    ? 'audio/webm;codecs=opus'
                    : MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg';

                this.recorder = new MediaRecorder(this.stream, { mimeType });
                this.recorder.ondataavailable = e => { if (e.data.size > 0) this.chunks.push(e.data); };
                this.recorder.start(100);
                this.isRecording = true;

                // إظهار واجهة التسجيل
                document.getElementById('voiceRecordingUI').classList.add('show');
                document.getElementById('chatInputWrapper').style.display = 'none';
                document.getElementById('micBtn').classList.add('recording');

                // مؤقت
                this.timer = setInterval(() => {
                    this.secs++;
                    const m = Math.floor(this.secs / 60);
                    const s = this.secs % 60;
                    document.getElementById('voiceTimer').textContent = m + ':' + String(s).padStart(2,'0');
                    // حد أقصى 5 دقائق
                    if (this.secs >= 300) this.stop(true);
                }, 1000);

            } catch(err) {
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    showToast('⚠️ يرجى السماح بالوصول إلى الميكروفون من إعدادات المتصفح');
                } else {
                    showToast('خطأ في تشغيل الميكروفون: ' + err.message);
                }
            }
        },

        stop(send = false) {
            if (!this.recorder || !this.isRecording) return;
            clearInterval(this.timer);
            this.isRecording = false;

            this.recorder.onstop = () => {
                if (send && this.chunks.length > 0) {
                    const blob = new Blob(this.chunks, { type: this.recorder.mimeType });
                    this.upload(blob);
                }
                this.reset();
            };

            this.recorder.stop();
            this.stream?.getTracks().forEach(t => t.stop());
        },

        async upload(blob) {
            const ext = blob.type.includes('webm') ? 'webm' : 'ogg';
            const file = new File([blob], 'voice_' + Date.now() + '.' + ext, { type: blob.type });
            const fd = new FormData();
            fd.append('receiver_id', peerId);
            fd.append('message', '🎤');
            fd.append('attachment', file);

            showToast('⏳ جارٍ إرسال الرسالة الصوتية...');

            try {
                const res  = await fetch('send_message.php', { method:'POST', body:fd });
                const data = await res.json();
                if (data.success) {
                    lastMessageId = 0; shouldScroll = true;
                    fetchMessages();
                    showToast('✅ تم إرسال الرسالة الصوتية');
                } else {
                    showToast('❌ ' + (data.error || 'خطأ في الإرسال'));
                }
            } catch(e) {
                showToast('❌ خطأ في الاتصال');
            }
        },

        reset() {
            document.getElementById('voiceRecordingUI').classList.remove('show');
            document.getElementById('chatInputWrapper').style.display = 'flex';
            document.getElementById('micBtn').classList.remove('recording');
            document.getElementById('voiceTimer').textContent = '0:00';
            this.recorder = null;
            this.chunks = [];
            this.secs = 0;
        }
    };

    // أزرار التسجيل
    document.getElementById('micBtn')?.addEventListener('click', function() {
        if (VoiceRec.isRecording) { VoiceRec.stop(true); }
        else { VoiceRec.start(); }
    });
    document.getElementById('voiceSendBtn')?.addEventListener('click', function() { VoiceRec.stop(true); });
    document.getElementById('voiceCancelBtn')?.addEventListener('click', function() { VoiceRec.stop(false); });

    // ══════════════════════════════════════════════
    // مشغل الصوت في الرسائل
    // ══════════════════════════════════════════════
    /* ════ مشغل الرسائل الصوتية ════ */
    function fmtDur(sec) {
        if (isNaN(sec) || !isFinite(sec)) return '0:00';
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return m + ':' + String(s).padStart(2, '0');
    }

    function voiceToggle(id) {
        const audio = document.getElementById(id);
        const icon  = document.getElementById('vi_' + id);
        if (!audio || !icon) return;

        // إيقاف أي صوت آخر يعمل
        document.querySelectorAll('audio[id^="va_"]').forEach(a => {
            if (a.id !== id && !a.paused) {
                a.pause();
                const i = document.getElementById('vi_' + a.id);
                if (i) i.className = 'fas fa-play';
            }
        });

        if (audio.paused) {
            audio.play().then(() => {
                icon.className = 'fas fa-pause';
            }).catch(err => {
                console.warn('Voice play error:', err);
                showToast('⚠️ لا يمكن تشغيل الصوت');
            });
        } else {
            audio.pause();
            icon.className = 'fas fa-play';
        }
    }

    function voiceLoaded(id) {
        const audio = document.getElementById(id);
        const prog  = document.getElementById('vp_' + id);
        const dur   = document.getElementById('vd_' + id);
        if (!audio || isNaN(audio.duration) || !isFinite(audio.duration)) return;
        if (prog) { prog.max = audio.duration; prog.value = 0; }
        if (dur)  dur.textContent = fmtDur(audio.duration);
    }

    function voiceUpdate(id) {
        const audio = document.getElementById(id);
        const prog  = document.getElementById('vp_' + id);
        const dur   = document.getElementById('vd_' + id);
        if (!audio) return;
        if (prog) prog.value = audio.currentTime;
        if (dur)  dur.textContent = fmtDur(audio.currentTime) + ' / ' + fmtDur(audio.duration);
    }

    function voiceSeek(id, val) {
        const audio = document.getElementById(id);
        if (audio && isFinite(parseFloat(val))) {
            audio.currentTime = parseFloat(val);
        }
    }

    function voiceEnded(id) {
        const audio = document.getElementById(id);
        const icon  = document.getElementById('vi_' + id);
        const prog  = document.getElementById('vp_' + id);
        if (icon) icon.className = 'fas fa-play';
        if (audio) { audio.currentTime = 0; voiceLoaded(id); }
        if (prog)  prog.value = 0;
    }

    function voiceError(id) {
        const wrap = document.getElementById('mv_' + id);
        if (wrap) {
            wrap.innerHTML = `<i class="fas fa-microphone-slash" style="color:#dc3545;opacity:0.7;"></i>
                <span style="font-size:0.8rem;color:#667781;margin-right:6px;">تعذّر تحميل الصوت</span>`;
        }
    }

    // ══════════════════════════════════════════════
    // ردود الفعل (Emoji Reactions) — placeholder
    // ══════════════════════════════════════════════
    function sendReaction(msgId, emoji) {
        showToast(emoji + ' ردّ فعلك على الرسالة');
        // يمكن توسيعه لاحقاً بجدول message_reactions
    }

    // ══════════════════════════════════════════════
    // تغيير أيقونة زر الإرسال حسب محتوى الرسالة
    // ══════════════════════════════════════════════
    function updateSendBtn() {
        const val = document.getElementById('messageInput')?.value.trim();
        const icon = document.getElementById('sendBtn')?.querySelector('i');
        if (!icon) return;
        if (!val && !selectedFile) {
            icon.className = 'fas fa-paper-plane';
        } else {
            icon.className = 'fas fa-paper-plane';
        }
    }

    document.getElementById('messageInput')?.addEventListener('input', updateSendBtn);

    // ??? Init ???
    $(document).ready(function() {
        // Initialize notification system
        NotificationSound.init();
        requestNotifPermission();
        if (peerId) {
            addNotifSettingsToggle();
            fetchMessages();
            // Poll new messages every 2 seconds
            setInterval(fetchMessages, 2000);
            // Check typing every 2 seconds
            setInterval(checkTyping, 2000);
        }

        // Reload user unread counts periodically
        setInterval(function() {
            $.getJSON('get_unread_count.php', function(data) {
                const totalUnread = data.total || 0;
                // Update header badge via sessionStorage for inter-page sync
                sessionStorage.setItem('chat_unread', totalUnread);
                // Play sound if new unread messages across page
                const prev = parseInt(sessionStorage.getItem('chat_unread_prev') || '0');
                if (totalUnread > prev && !document.hasFocus() && peerId === null) {
                    NotificationSound.play();
                }
                sessionStorage.setItem('chat_unread_prev', totalUnread);
            });
        }, 10000);
    });
    </script>
</body>
</html>
