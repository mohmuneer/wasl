<?php
/**
 * Notify — طبقة الإشعارات الموحّدة (WhatsApp + SMS + Email)
 *
 * الاستخدام:
 *  Notify::onTicketCreated($ticket, $pdo);
 *  Notify::sms('+966501234567', 'تم استلام بلاغك رقم TK-000042');
 */
class Notify
{
    // ─────────────────────────────────────────────
    //  SMS عبر Msegat
    // ─────────────────────────────────────────────
    public static function sms(string $phone, string $message): bool
    {
        if (!defined('NOTIFY_SMS_ENABLED') || !NOTIFY_SMS_ENABLED) return false;

        $phone = self::normalizePhone($phone);

        $payload = http_build_query([
            'userSender' => MSEGAT_USERNAME,
            'apiKey'     => MSEGAT_API_KEY,
            'numbers'    => $phone,
            'msg'        => $message,
            'sender'     => MSEGAT_SENDER,
            'msgEncoding'=> 'UTF8',
        ]);

        $response = self::httpPost(MSEGAT_API_URL, $payload, 'application/x-www-form-urlencoded');
        $success  = isset($response['code']) && $response['code'] === '1';

        if (!$success) {
            error_log('[WASL-Notify] SMS failed to ' . $phone . ': ' . json_encode($response));
        }

        return $success;
    }

    // ─────────────────────────────────────────────
    //  WhatsApp عبر Unifonic
    // ─────────────────────────────────────────────
    public static function whatsapp(string $phone, string $message): bool
    {
        if (!defined('NOTIFY_WHATSAPP_ENABLED') || !NOTIFY_WHATSAPP_ENABLED) return false;

        $phone = self::normalizePhone($phone);

        $payload = json_encode([
            'AppSid'    => UNIFONIC_APP_SID,
            'SenderID'  => UNIFONIC_SENDER_ID,
            'Body'      => $message,
            'Recipient' => $phone,
        ]);

        $response = self::httpPost(
            UNIFONIC_API_URL,
            $payload,
            'application/json',
            ['Authorization: Bearer ' . UNIFONIC_APP_SID]
        );

        $success = !empty($response['success']);

        if (!$success) {
            error_log('[WASL-Notify] WhatsApp failed to ' . $phone . ': ' . json_encode($response));
        }

        return $success;
    }

    // ─────────────────────────────────────────────
    //  البريد الإلكتروني — يُضاف لقائمة الانتظار
    // ─────────────────────────────────────────────
    public static function queueEmail(
        PDO    $pdo,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyHtml
    ): void {
        if (!defined('NOTIFY_EMAIL_ENABLED') || !NOTIFY_EMAIL_ENABLED) return;

        $sql = 'INSERT INTO email_queue (to_email, to_name, subject, body_html) VALUES (?,?,?,?)';
        try {
            $pdo->prepare($sql)->execute([$toEmail, $toName, $subject, $bodyHtml]);
        } catch (PDOException $e) {
            error_log('[WASL-Notify] Queue email failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    //  معالجة قائمة انتظار البريد (تُشغَّل بـ cron)
    //  يُعيد عدد الرسائل المرسلة
    // ─────────────────────────────────────────────
    public static function processEmailQueue(PDO $pdo, int $limit = 20): int
    {
        if (!defined('NOTIFY_EMAIL_ENABLED') || !NOTIFY_EMAIL_ENABLED) return 0;

        $rows = $pdo->query(
            "SELECT * FROM email_queue WHERE status='pending' AND attempts < 3
             ORDER BY created_at LIMIT {$limit}"
        )->fetchAll();

        $sent = 0;

        foreach ($rows as $row) {
            $pdo->prepare("UPDATE email_queue SET attempts = attempts + 1 WHERE id = ?")
                ->execute([$row['id']]);

            $ok = self::sendSmtp(
                $row['to_email'],
                $row['subject'],
                $row['body_html']
            );

            if ($ok) {
                $pdo->prepare("UPDATE email_queue SET status='sent', sent_at=NOW() WHERE id=?")
                    ->execute([$row['id']]);
                $sent++;
            } else {
                $status = ($row['attempts'] + 1) >= 3 ? 'failed' : 'pending';
                $pdo->prepare("UPDATE email_queue SET status=? WHERE id=?")
                    ->execute([$status, $row['id']]);
            }
        }

        return $sent;
    }

    // ─────────────────────────────────────────────
    //  أحداث تلقائية — تُستدعى من الكود عند الأحداث
    // ─────────────────────────────────────────────

    // عند فتح تذكرة جديدة: أبلغ العميل والمشرف
    public static function onTicketCreated(array $ticket, PDO $pdo): void
    {
        $ticketNum = $ticket['ticket_number'] ?? 'TK-' . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT);
        $priority  = $ticket['priority'] ?? 'Medium';

        $msg = "🔔 نظام وَصْل\nتم فتح التذكرة: {$ticketNum}\nالأولوية: {$priority}\nسنتواصل معك قريباً.";

        // إبلاغ العميل
        if (!empty($ticket['client_phone'])) {
            self::whatsapp($ticket['client_phone'], $msg);
            self::sms($ticket['client_phone'], $msg);
        }

        // إبلاغ المشرفين عبر الإشعارات الداخلية
        self::notifyAdmins($pdo, "تذكرة جديدة: {$ticketNum} ({$priority})", 'ticket', $ticket['id'] ?? 0);

        // بريد إلكتروني للعميل
        if (!empty($ticket['client_email'])) {
            self::queueEmail(
                $pdo,
                $ticket['client_email'],
                $ticket['client_name'] ?? '',
                "تم استلام بلاغك — {$ticketNum}",
                self::emailTemplate("تم استلام بلاغك", "رقم التذكرة: <strong>{$ticketNum}</strong><br>الأولوية: {$priority}<br>سنبدأ العمل على بلاغك فوراً.")
            );
        }
    }

    // عند خرق SLA: تنبيه عاجل للمشرفين
    public static function onSlaBreached(array $ticket, PDO $pdo): void
    {
        $ticketNum = $ticket['ticket_number'] ?? 'TK-' . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT);

        $msg = "⚠️ تنبيه SLA!\nالتذكرة {$ticketNum} تجاوزت مستوى الخدمة المتفق عليه.\nيُرجى التصعيد فوراً.";

        self::notifyAdmins($pdo, "⚠️ خرق SLA: {$ticketNum}", 'sla_breach', $ticket['id'] ?? 0);

        // SMS للمشرف المناوب (افتراضي — خصّص حسب بنية شركتك)
        if (defined('SUPERVISOR_PHONE') && SUPERVISOR_PHONE) {
            self::sms(SUPERVISOR_PHONE, $msg);
            self::whatsapp(SUPERVISOR_PHONE, $msg);
        }
    }

    // عند إغلاق التذكرة: أبلغ العميل
    public static function onTicketClosed(array $ticket, PDO $pdo): void
    {
        $ticketNum = $ticket['ticket_number'] ?? '';
        $msg = "✅ نظام وَصْل\nتم إغلاق التذكرة {$ticketNum}.\nشكراً لثقتكم، نرجو تقييم تجربتكم.";

        if (!empty($ticket['client_phone'])) {
            self::whatsapp($ticket['client_phone'], $msg);
        }

        if (!empty($ticket['client_email'])) {
            self::queueEmail(
                $pdo,
                $ticket['client_email'],
                $ticket['client_name'] ?? '',
                "تم إغلاق بلاغك — {$ticketNum}",
                self::emailTemplate("تم إغلاق بلاغك", "رقم التذكرة: <strong>{$ticketNum}</strong> تم إغلاقه بنجاح.")
            );
        }
    }

    // ─────────────────────────────────────────────
    //  دوال داخلية مساعدة
    // ─────────────────────────────────────────────
    private static function notifyAdmins(PDO $pdo, string $title, string $type, int $refId): void
    {
        try {
            // جلب جميع المديرين والمشرفين
            $admins = $pdo->query(
                "SELECT id FROM sys_users WHERE role_id IN (
                    SELECT id FROM sys_roles WHERE role_code IN ('MainAdmin','Supervisor','BranchManager')
                 ) AND status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (empty($admins)) return;

            $sql = 'INSERT INTO notifications (user_id, title, type, reference_id) VALUES (?,?,?,?)';
            $stmt = $pdo->prepare($sql);

            foreach ($admins as $uid) {
                $stmt->execute([$uid, $title, $type, $refId ?: null]);
            }
        } catch (PDOException) {}
    }

    private static function sendSmtp(string $to, string $subject, string $html): bool
    {
        // إرسال بسيط عبر mail() — استبدل بـ PHPMailer أو Symfony Mailer في الإنتاج
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . (SMTP_FROM_NAME ?? 'وَصْل') . " <" . (SMTP_FROM_EMAIL ?? '') . ">\r\n";

        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
    }

    private static function emailTemplate(string $title, string $body): string
    {
        return <<<HTML
        <!DOCTYPE html><html dir="rtl" lang="ar">
        <head><meta charset="UTF-8">
        <style>body{font-family:Cairo,Arial,sans-serif;background:#f4f6f9;margin:0;padding:20px}
        .card{background:#fff;border-radius:12px;padding:30px;max-width:500px;margin:auto}
        h2{color:#0d4a1c} p{color:#444;line-height:1.8}
        .footer{text-align:center;color:#999;font-size:12px;margin-top:20px}</style>
        </head><body>
        <div class="card">
          <h2>{$title}</h2>
          <p>{$body}</p>
          <div class="footer">نظام وَصْل &copy; 2026</div>
        </div></body></html>
        HTML;
    }

    private static function httpPost(string $url, string $data, string $contentType, array $extraHeaders = []): array
    {
        $headers = array_merge(["Content-Type: {$contentType}"], $extraHeaders);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers),
                'content' => $data,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true],
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) return ['error' => 'connection_failed'];

        $json = json_decode($raw, true);
        return $json ?? ['raw' => $raw];
    }

    private static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (str_starts_with($phone, '05')) {
            $phone = '966' . substr($phone, 1);
        } elseif (str_starts_with($phone, '+')) {
            $phone = ltrim($phone, '+');
        }
        return $phone;
    }

    // ══════════════════════════════════════════════════════════════
    //  الدردشة الداخلية — إرسال رسالة من النظام إلى مستخدم
    // ══════════════════════════════════════════════════════════════

    /**
     * أرسل رسالة نصية في الدردشة الداخلية من مستخدم إلى آخر.
     * تظهر في contact.php كرسالة عادية.
     */
    /**
     * أرسل رسالة في الدردشة الداخلية من مستخدم إلى آخر (أو إلى نفسه كتنبيه ذاتي).
     * يعمل حتى لو كان المرسل = المستقبِل (إسناد ذاتي / تنبيه شخصي).
     */
    public static function internalMessage(PDO $pdo, int $fromUserId, int $toUserId, string $text): bool
    {
        if ($toUserId <= 0 || trim($text) === '') return false;

        // إذا لم يكن للمرسل ID صالح، استخدم المستقبِل كمرسل (تنبيه ذاتي)
        if ($fromUserId <= 0) $fromUserId = $toUserId;

        // التحقق أن كلا المستخدمين موجودان
        try {
            $ids = $pdo->prepare(
                "SELECT COUNT(DISTINCT id) FROM sys_users WHERE id IN (?,?) AND status = 'active'"
            );
            $ids->execute([$fromUserId, $toUserId]);
            $found = (int)$ids->fetchColumn();
            // إذا كان نفس المستخدم يكفي أن يوجد واحد، وإلا يجب أن يوجدا معاً
            $required = ($fromUserId === $toUserId) ? 1 : 2;
            if ($found < $required) return false;
        } catch (\Throwable $e) {
            error_log('[Notify] internalMessage user-check failed: ' . $e->getMessage());
            return false;
        }

        // إرسال الرسالة — مع fallback بدون message_type إن لم يوجد العمود
        try {
            $pdo->prepare(
                "INSERT INTO messages (sender_id, receiver_id, message_text, message_type, created_at)
                 VALUES (?, ?, ?, 'text', NOW())"
            )->execute([$fromUserId, $toUserId, $text]);
            return true;
        } catch (\Throwable $e) {
            try {
                $pdo->prepare(
                    "INSERT INTO messages (sender_id, receiver_id, message_text, is_read, created_at)
                     VALUES (?, ?, ?, 0, NOW())"
                )->execute([$fromUserId, $toUserId, $text]);
                return true;
            } catch (\Throwable $e2) {
                error_log('[Notify] internalMessage failed: ' . $e2->getMessage());
                return false;
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  حدث: إسناد مهمة — يُرسَل للفني عبر الدردشة الداخلية
    // ──────────────────────────────────────────────────────────────
    public static function onTaskAssigned(
        PDO $pdo,
        int $createdByUserId,
        int $assignedToUserId,
        array $taskData = []
    ): void {
        if ($assignedToUserId <= 0) return;

        $priorityMap = ['Low'=>'عادي','Medium'=>'متوسط','High'=>'عالي (مستعجل)','Urgent'=>'طارئ (توقف عمل)'];
        $priority    = $priorityMap[$taskData['priority'] ?? 'Medium'] ?? ($taskData['priority'] ?? '');
        $deadline    = !empty($taskData['deadline']) ? date('Y/m/d', strtotime($taskData['deadline'])) : 'غير محدد';
        $details     = mb_substr($taskData['details'] ?? '', 0, 120);
        $branch      = $taskData['branch_name'] ?? '';
        $category    = $taskData['category_name'] ?? '';

        $lines   = [];
        $lines[] = "🔧 مهمة جديدة مُسنَدة إليك";
        if ($branch || $category)   $lines[] = "📍 " . implode(' — ', array_filter([$branch, $category]));
        if ($details)               $lines[] = "📋 " . $details . (mb_strlen($taskData['details'] ?? '') > 120 ? '…' : '');
        $lines[] = "⚡ الأولوية: {$priority}";
        $lines[] = "📅 الموعد النهائي: {$deadline}";
        $lines[] = "➡️ راجع قائمة مهامك لمزيد من التفاصيل";

        self::internalMessage($pdo, $createdByUserId, $assignedToUserId, implode("\n", $lines));
    }

    // ──────────────────────────────────────────────────────────────
    //  حدث: إنشاء وثيقة تحتاج اعتماداً — يُرسَل لكل معتمِد
    // ──────────────────────────────────────────────────────────────
    public static function onDocumentApprovalRequired(
        PDO $pdo,
        int $createdByUserId,
        int $workflowId,
        array $docData = []
    ): void {
        if ($workflowId <= 0) return;

        try {
            // جلب user_id لكل موظف معتمِد في سياسة الاعتماد
            $stmt = $pdo->prepare("
                SELECT DISTINCT e.user_id
                FROM   approval_stages aps
                JOIN   dms_employees   e  ON aps.employee_id = e.id
                WHERE  aps.workflow_id = ?
                  AND  aps.is_active   = 1
                  AND  e.user_id       IS NOT NULL
            ");
            $stmt->execute([$workflowId]);
            $approverUids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($approverUids)) return;

            $title      = $docData['title']       ?? 'وثيقة جديدة';
            $docNumber  = $docData['doc_number']  ?? '';
            $docType    = $docData['type_name']   ?? '';
            $department = $docData['department']  ?? '';

            $lines   = [];
            $lines[] = "📄 طلب اعتماد وثيقة";
            $lines[] = "الوثيقة: {$title}" . ($docNumber ? " ({$docNumber})" : '');
            if ($docType)    $lines[] = "النوع: {$docType}";
            if ($department) $lines[] = "القسم: {$department}";
            $lines[] = "✅ يُرجى الدخول إلى نظام الوثائق لمراجعة الوثيقة واعتمادها";

            $message = implode("\n", $lines);

            foreach ($approverUids as $uid) {
                $uid = (int)$uid;
                if ($uid > 0 && $uid !== $createdByUserId) {
                    self::internalMessage($pdo, $createdByUserId, $uid, $message);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Notify] onDocumentApprovalRequired failed: ' . $e->getMessage());
        }
    }
}
