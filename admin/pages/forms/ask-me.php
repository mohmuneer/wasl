<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";
$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/ask-me.php";

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// التحقق من الصلاحيات (نفس منطق صفحاتك السابقة)
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);
$current_page_id = $menu_item['id'] ?? 0;

// يمكن إضافة منطق إضافي هنا للتحقق مما إذا كان للمستخدم حق عرض هذه الصفحة
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>AI Assistant - WASL CRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">
    <style>
    .chat-container {
        height: 65vh;
        overflow-y: auto;
        background: #f4f6f9;
        padding: 20px;
        border-radius: 10px;
        border: 1px solid #dee2e6;
    }

    .direct-chat-msg {
        margin-bottom: 20px;
        display: flex;
        align-items: flex-start;
    }

    .direct-chat-msg.right {
        flex-direction: row-reverse;
    }

    .direct-chat-msg.left {
        flex-direction: row;
    }

    .direct-chat-text {
        background: #fff;
        border: 1px solid #d2d6de;
        border-radius: 12px;
        padding: 12px 16px;
        position: relative;
        max-width: 80%;
        word-wrap: break-word;
        white-space: pre-wrap;
        line-height: 1.6;
    }

    .right .direct-chat-text {
        background: #007bff;
        color: #fff;
        border-color: #007bff;
        margin-left: 10px;
    }

    .left .direct-chat-text {
        background: #fff;
        color: #333;
        margin-right: 10px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    }

    .chat-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .chat-img i {
        font-size: 20px;
    }

    .bot-img {
        background: #e9ecef;
    }

    .user-img {
        background: #007bff;
    }

    .user-img i {
        color: #fff;
    }

    .typing-indicator {
        display: none;
        padding: 12px 16px;
        background: #e9ecef;
        border-radius: 12px;
        margin-right: 10px;
        max-width: 80px;
    }

    .typing-indicator span {
        display: inline-block;
        width: 8px;
        height: 8px;
        background: #888;
        border-radius: 50%;
        margin: 0 2px;
        animation: typing 1.4s infinite;
    }

    .typing-indicator span:nth-child(2) {
        animation-delay: .2s;
    }

    .typing-indicator span:nth-child(3) {
        animation-delay: .4s;
    }

    @keyframes typing {

        0%,
        60%,
        100% {
            opacity: .3;
            transform: translateY(0);
        }

        30% {
            opacity: 1;
            transform: translateY(-4px);
        }
    }

    .suggestion-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .suggestion-chips .btn {
        font-size: 13px;
        border-radius: 20px;
    }
    </style>
</head>

<body class="hold-transition">
    <div class="wrapper">
        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <h1>مساعد الذكاء الاصطناعي (AI Bot)</h1>
                </div>
            </section>

            <section class="content">
                <div class="card card-primary card-outline direct-chat direct-chat-primary">
                    <div class="card-header">
                        <h3 class="card-title text-right">تحدث مع النظام</h3>
                    </div>
                    <div class="card-body">
                        <div id="chat-box" class="chat-container direct-chat-messages">
                            <div class="direct-chat-msg left">
                                <div class="chat-img bot-img"><i class="fas fa-robot text-primary"></i></div>
                                <div class="direct-chat-text">
                                    👋 مرحباً بك! أنا <b>مساعد WASL الذكي</b>.<br>
                                    يمكنني الإجابة عن أسئلتك حول بيانات النظام، مثل:<br>
                                    • كم عدد العملاء؟<br>
                                    • عرض البلاغات المعلقة<br>
                                    • أظهر المهام المكتملة<br>
                                    • من هو العميل X؟<br>
                                    • أحدث البلاغات<br>
                                    وأي شيء آخر عن بيانات النظام!
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="input-group">
                            <input type="text" id="user-input" placeholder="اكتب سؤالك هنا..." class="form-control"
                                autofocus>
                            <span class="input-group-append">
                                <button type="button" id="send-btn" class="btn btn-primary"><i
                                        class="fas fa-paper-plane"></i></button>
                            </span>
                        </div>
                        <div class="suggestion-chips mt-2">
                            <button class="btn btn-outline-primary btn-sm" onclick="quickAsk('ملخص النظام')">📊 ملخص النظام</button>
                            <button class="btn btn-outline-danger btn-sm" onclick="quickAsk('البلاغات المعلقة')">🔴 البلاغات المعلقة</button>
                            <button class="btn btn-outline-warning btn-sm" onclick="quickAsk('البلاغات العاجلة')">⚡ البلاغات العاجلة</button>
                            <button class="btn btn-outline-info btn-sm" onclick="quickAsk('بلاغات اليوم')">📅 بلاغات اليوم</button>
                            <button class="btn btn-outline-success btn-sm" onclick="quickAsk('مهامي')">✅ مهامي</button>
                            <button class="btn btn-outline-danger btn-sm" onclick="quickAsk('المهام المتأخرة')">⏰ المهام المتأخرة</button>
                            <button class="btn btn-outline-info btn-sm" onclick="quickAsk('إحصاءات SLA')">📈 إحصاءات SLA</button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="quickAsk('عدد العملاء النشطين')">👥 العملاء النشطون</button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="quickAsk('عرض المندوبين')">🚗 المندوبون</button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="quickAsk('توزيع البلاغات حسب الأولوية')">📋 توزيع الأولويات</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <footer class="main-footer text-center">
            <strong>جميع الحقوق محفوظة &copy; 2026</strong>
        </footer>
    </div>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.min.js"></script>

    <script>
    function appendMessage(text, side) {
        var iconHtml = side === 'right' ?
            '<div class="chat-img user-img"><i class="fas fa-user text-white"></i></div>' :
            '<div class="chat-img bot-img"><i class="fas fa-robot text-primary"></i></div>';
        var html = '<div class="direct-chat-msg ' + side + '">' +
            iconHtml +
            '<div class="direct-chat-text">' + text + '</div>' +
            '</div>';
        var box = $('#chat-box');
        box.append(html);
        box.scrollTop(box[0].scrollHeight);
    }

    function showTyping() {
        var html = '<div class="direct-chat-msg left" id="typing-indicator">' +
            '<div class="chat-img bot-img"><i class="fas fa-robot text-primary"></i></div>' +
            '<div class="typing-indicator" style="display:flex;">' +
            '<span></span><span></span><span></span>' +
            '</div></div>';
        $('#chat-box').append(html);
        $('#chat-box').scrollTop($('#chat-box')[0].scrollHeight);
    }

    function hideTyping() {
        $('#typing-indicator').remove();
    }

    function sendMessage() {
        var input = $('#user-input');
        var message = input.val().trim();
        if (message === "") return;

        appendMessage(escapeHtml(message), 'right');
        input.val("");
        $('#send-btn').prop('disabled', true);
        showTyping();

        $.ajax({
            url: 'process-ai.php',
            method: 'POST',
            data: {
                query: message
            },
            success: function(response) {
                hideTyping();
                appendMessage(response, 'left');
            },
            error: function() {
                hideTyping();
                appendMessage("عذراً، حدث خطأ في الاتصال بالنظام. تأكد من اتصالك بالخادم.", 'left');
            },
            complete: function() {
                $('#send-btn').prop('disabled', false);
                input.focus();
            }
        });
    }

    function quickAsk(text) {
        $('#user-input').val(text);
        sendMessage();
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    $('#user-input').keypress(function(e) {
        if (e.which == 13) sendMessage();
    });

    $('#send-btn').click(function() {
        sendMessage();
    });

    // Auto-focus input
    $('#user-input').focus();
    </script>
</body>

</html>