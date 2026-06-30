<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . "/../config/db.php";
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$baseAssets = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . "/";
$my_id = $_SESSION['user_id'];

// Fetch all conversations (grouped by user)
$stmt = $pdo->prepare("
    SELECT 
        u.id as user_id,
        u.full_name,
        u.file_path,
        u.email,
        (SELECT m0.created_at FROM messages m0
         WHERE m0.deleted_at IS NULL AND (
            (m0.sender_id = ? AND m0.receiver_id = u.id) OR 
            (m0.sender_id = u.id AND m0.receiver_id = ?)
         )
         ORDER BY m0.created_at DESC LIMIT 1
        ) as last_msg_time,
        (SELECT message_text FROM messages m2 
         WHERE m2.deleted_at IS NULL AND (
            (m2.sender_id = ? AND m2.receiver_id = u.id) OR 
            (m2.sender_id = u.id AND m2.receiver_id = ?)
         ) 
         ORDER BY m2.created_at DESC LIMIT 1
        ) as last_message,
        (SELECT message_type FROM messages m3
         WHERE m3.deleted_at IS NULL AND (
            (m3.sender_id = ? AND m3.receiver_id = u.id) OR 
            (m3.sender_id = u.id AND m3.receiver_id = ?)
         )
         ORDER BY m3.created_at DESC LIMIT 1
        ) as last_msg_type,
        (SELECT m4.sender_id FROM messages m4
         WHERE m4.deleted_at IS NULL AND (
            (m4.sender_id = ? AND m4.receiver_id = u.id) OR 
            (m4.sender_id = u.id AND m4.receiver_id = ?)
         )
         ORDER BY m4.created_at DESC LIMIT 1
        ) as last_sender_id,
        (SELECT COUNT(*) FROM messages m5 
         WHERE m5.sender_id = u.id AND m5.receiver_id = ? AND m5.is_read = 0 AND m5.deleted_at IS NULL
        ) as unread_count
    FROM sys_users u
    WHERE u.id != ?
      AND EXISTS (
          SELECT 1 FROM messages m6
          WHERE m6.deleted_at IS NULL
            AND ((m6.sender_id = ? AND m6.receiver_id = u.id) OR (m6.sender_id = u.id AND m6.receiver_id = ?))
      )
    GROUP BY u.id, u.full_name, u.file_path, u.email
    ORDER BY last_msg_time DESC
");
$stmt->execute([$my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_unread = array_sum(array_column($conversations, 'unread_count'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>صندوق الوارد | الرسائل</title>
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css?family=Cairo:400,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="dist/css/custom.css?v=202606261542">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        body { background: #f0f2f5; }
        .content-wrapper { background: transparent; }

        .inbox-header {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .inbox-header h1 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
        }

        .inbox-header .badge-total {
            background: #007bff;
            color: #fff;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .conversation-list { background: transparent; }

        .conv-card {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            background: #fff;
            border-radius: 12px;
            margin-bottom: 8px;
            text-decoration: none !important;
            color: inherit;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            border-right: 4px solid transparent;
        }

        .conv-card:hover {
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
            transform: translateX(-3px);
        }

        .conv-card.unread {
            border-right-color: #007bff;
            background: #f8fbff;
        }

        .conv-avatar {
            width: 56px;
            height: 56px;
            min-width: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9edef;
            margin-left: 16px;
        }

        .conv-card.unread .conv-avatar {
            border-color: #007bff;
        }

        .conv-info { flex: 1; min-width: 0; }
        .conv-name {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 4px;
            color: #111;
        }

        .conv-preview {
            font-size: 0.88rem;
            color: #667781;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 350px;
        }

        .conv-preview .prefix {
            color: #007bff;
            font-weight: 600;
        }

        .conv-meta {
            text-align: left;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            margin-right: 12px;
            min-width: 80px;
        }

        .conv-time {
            font-size: 0.75rem;
            color: #667781;
            white-space: nowrap;
        }

        .conv-unread-badge {
            background: #007bff;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            min-width: 24px;
            height: 24px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 8px;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #667781;
        }

        .empty-state i {
            font-size: 64px;
            color: #d1d7db;
            margin-bottom: 16px;
        }

        .empty-state h4 { font-weight: 700; color: #3b4a54; }

        /* ── Tablet ≤ 768px ── */
        @media (max-width: 768px) {
            .inbox-header { padding: 14px 16px; }
            .inbox-header h1 { font-size: 1.15rem; }
            .conv-preview { max-width: 220px; }
            .conv-avatar { width: 50px; height: 50px; min-width: 50px; }
            .conv-card { padding: 13px 16px; }
        }

        /* ── Mobile ≤ 576px ── */
        @media (max-width: 576px) {
            .inbox-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                padding: 12px 14px;
            }
            .inbox-header > div:last-child {
                display: flex; flex-wrap: wrap; gap: 6px; width: 100%;
            }
            .inbox-header .badge-total { font-size: 0.8rem; padding: 5px 12px; }
            .inbox-header .btn { flex: 1; justify-content: center; }

            .conv-card { padding: 11px 12px; border-radius: 10px; }
            .conv-avatar { width: 44px; height: 44px; min-width: 44px; margin-left: 12px; }
            .conv-name { font-size: 0.92rem; }
            .conv-preview { max-width: calc(100vw - 160px); font-size: 0.82rem; }
            .conv-meta { min-width: 60px; margin-right: 8px; }
            .conv-time { font-size: 0.7rem; }
            .conv-unread-badge { min-width: 20px; height: 20px; font-size: 0.68rem; }

            .empty-state { padding: 50px 16px; }
            .empty-state i { font-size: 48px; }
        }

        /* ── اللمس: أهداف لمس أكبر ── */
        @media (hover: none) and (pointer: coarse) {
            .conv-card { min-height: 70px; }
            .conv-card:hover { transform: none !important; box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important; }
        }
    </style>
</head>
<body class="hold-transition layout-fixed">
<div class="wrapper">
    <?php include "main-header.php"; ?>
    <?php include "main-sidebar.php"; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1>صندوق الوارد</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-left">
                            <li class="breadcrumb-item"><a href="index.php">الرئيسية</a></li>
                            <li class="breadcrumb-item active">الرسائل</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="inbox-header">
                    <div>
                        <h1><i class="fas fa-inbox ml-2" style="color:#007bff;"></i>الرسائل الواردة</h1>
                    </div>
                    <div>
                        <span class="badge-total">
                            <i class="fas fa-envelope ml-1"></i>
                            <?php echo count($conversations); ?> محادثة
                        </span>
                        <?php if ($total_unread > 0): ?>
                        <span class="badge badge-danger mr-2" style="font-size:0.85rem; padding:4px 12px;">
                            <i class="fas fa-exclamation-circle ml-1"></i>
                            <?php echo $total_unread; ?> غير مقروءة
                        </span>
                        <?php endif; ?>
                        <a href="contact.php" class="btn btn-primary btn-sm mr-2">
                            <i class="fas fa-plus ml-1"></i>محادثة جديدة
                        </a>
                    </div>
                </div>

                <?php if (count($conversations) > 0): ?>
                <div class="conversation-list">
                    <?php foreach ($conversations as $conv):
                        $img = !empty($conv['file_path']) ? "../uploads/" . $conv['file_path'] : $baseAssets . "dist/img/avatar5.png";
                        $unread = (int)$conv['unread_count'];
                        $is_unread = $unread > 0;

                        $preview = '';
                        if ($conv['last_message']) {
                            $sender_is_me = ((int)$conv['last_sender_id'] === (int)$my_id);
                            $prefix = $sender_is_me ? 'أنت: ' : '';
                            $text = $conv['last_message'];
                            if ($conv['last_msg_type'] === 'image') $text = '?? صورة';
                            elseif ($conv['last_msg_type'] === 'file') $text = '?? ملف';
                            $preview = '<span class="prefix">' . $prefix . '</span>' . htmlspecialchars(mb_strimwidth($text, 0, 50, '...'));
                        }

                        $time = '';
                        if ($conv['last_msg_time']) {
                            $t = strtotime($conv['last_msg_time']);
                            if (date('Y-m-d') === date('Y-m-d', $t)) {
                                $time = date('h:i A', $t);
                            } elseif (date('Y-m-d', strtotime('-1 day')) === date('Y-m-d', $t)) {
                                $time = 'أمس';
                            } else {
                                $time = date('d M', $t);
                            }
                        }
                    ?>
                    <a href="contact.php?user_id=<?php echo $conv['user_id']; ?>" class="conv-card <?php echo $is_unread ? 'unread' : ''; ?>">
                        <img src="<?php echo $img; ?>" class="conv-avatar" alt="">
                        <div class="conv-info">
                            <div class="conv-name"><?php echo htmlspecialchars($conv['full_name']); ?></div>
                            <div class="conv-preview"><?php echo $preview; ?></div>
                        </div>
                        <div class="conv-meta">
                            <span class="conv-time"><?php echo $time; ?></span>
                            <?php if ($is_unread): ?>
                            <span class="conv-unread-badge"><?php echo $unread; ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="far fa-envelope-open"></i>
                    <h4>لا توجد رسائل واردة</h4>
                    <p class="text-muted">عندما تتلقى رسالة من أحد الزملاء، ستظهر هنا</p>
                    <a href="contact.php" class="btn btn-primary mt-3">
                        <i class="fas fa-comment ml-1"></i>ابدأ محادثة جديدة
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
</body>
</html>
