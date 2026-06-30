<?php
/**
 * /api?endpoint=cron — مهام الخلفية
 *
 * أضف هذه السطور لـ cron job على الخادم:
 * * * * *  curl -s "https://your-domain.com/api/index.php?endpoint=cron&action=email&cron_key=SECRET"
 * *\/15 * * * *  curl -s "https://your-domain.com/api/index.php?endpoint=cron&action=sla&cron_key=SECRET"
 */

// ─── المتغيرات مُحقَنة من api/index.php ───────────────────────
$action ??= '';
$pdo    ??= Database::getInstance()->getPdo();

// التحقق من مفتاح الـ cron (لا يتطلب رمز API)
$cronKey = $_GET['cron_key'] ?? '';
$validKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'CHANGE_ME_IN_CONFIG';

if (!hash_equals($validKey, $cronKey)) {
    Response::error('مفتاح cron غير صالح', 401);
}

$db = Database::getInstance();

// ─── إرسال قائمة انتظار البريد ─────────────────────────────
if ($action === 'email') {
    require_once BASE_PATH . '/core/Notify.php';
    $sent = Notify::processEmailQueue($pdo);
    Response::success(['emails_sent' => $sent], "تم إرسال {$sent} رسالة");
}

// ─── فحص خرق SLA ──────────────────────────────────────────
if ($action === 'sla') {
    require_once BASE_PATH . '/core/Notify.php';

    // تحديث الخروقات
    $pdo->query("CALL sp_update_sla_breaches()");

    // إرسال تنبيهات للتذاكر المخترقة التي لم يُبلَّغ عنها بعد
    $breached = $db->fetchAll(
        "SELECT t.*, cl.phone AS client_phone, cl.email AS client_email, cl.client_name
         FROM tickets t
         LEFT JOIN clients cl ON cl.id = t.client_id
         WHERE t.sla_breached = 1
           AND t.status NOT IN ('Resolved','Cancelled')
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.type = 'sla_breach'
                 AND n.reference_id = t.id
                 AND n.created_at > NOW() - INTERVAL 1 HOUR
           )
         LIMIT 50"
    );

    foreach ($breached as $ticket) {
        Notify::onSlaBreached($ticket, $pdo);
    }

    Response::success([
        'sla_breaches_checked' => count($breached)
    ], 'تم فحص SLA');
}

Response::error('action غير معروف', 404);
