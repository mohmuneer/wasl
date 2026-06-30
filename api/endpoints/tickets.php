<?php
/**
 * /api?endpoint=tickets
 *
 * @var string $method
 * @var string $action
 * @var array  $body
 * @var PDO    $pdo
 *
 * GET               → قائمة التذاكر (مع ترقيم الصفحات)
 * GET  &action=show&id=N → تذكرة محددة
 * POST              → إنشاء تذكرة جديدة
 * PUT  &id=N        → تحديث التذكرة
 * POST &action=close&id=N → إغلاق التذكرة
 */

// ─── المتغيرات مُحقَنة من api/index.php ───────────────────────
$method ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action ??= '';
$body   ??= [];
$pdo    ??= Database::getInstance()->getPdo();

$me = Middleware::auth($pdo);
$db = Database::getInstance();

// ─── قائمة التذاكر ─────────────────────────────────────────
if ($method === 'GET' && ($action === '' || $action === 'list')) {

    $where  = ['1=1'];
    $params = [];

    // فلترة
    if (!empty($_GET['status'])) {
        $where[]  = 'status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['priority'])) {
        $where[]  = 'priority = ?';
        $params[] = $_GET['priority'];
    }
    if (!empty($_GET['branch_id'])) {
        $where[]  = 'branch_id = ?';
        $params[] = (int)$_GET['branch_id'];
    }
    if (!empty($_GET['sla_breached'])) {
        $where[]  = 'sla_breached = 1';
    }
    if (!empty($_GET['search'])) {
        $where[]  = 'MATCH(details) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $_GET['search'] . '*';
    }

    $whereStr = implode(' AND ', $where);
    $sql = "SELECT t.id, t.ticket_number, t.reporter_ref, t.priority, t.status,
                   t.sla_breached, t.created_at,
                   b.branch_name, ic.category_name, cl.client_name
            FROM tickets t
            LEFT JOIN branches          b  ON b.id  = t.branch_id
            LEFT JOIN issue_categories  ic ON ic.id = t.category_id
            LEFT JOIN clients           cl ON cl.id = t.client_id
            WHERE {$whereStr}
            ORDER BY t.created_at DESC";

    $page  = max(1, (int)($_GET['page']  ?? 1));
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 25)));

    Response::paginated($db->paginate($sql, $params, $page, $limit));
}

// ─── تذكرة محددة ───────────────────────────────────────────
if ($method === 'GET' && $action === 'show') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) Response::error('معرّف التذكرة مطلوب', 422);

    $sql = "SELECT t.*, b.branch_name, r.region_name, d.department_name,
                   ic.category_name, cl.client_name, cl.phone AS client_phone,
                   sr.rule_name AS sla_rule, u.full_name AS closed_by_name
            FROM tickets t
            LEFT JOIN branches         b  ON b.id  = t.branch_id
            LEFT JOIN regions          r  ON r.id  = t.region_id
            LEFT JOIN departments      d  ON d.id  = t.department_id
            LEFT JOIN issue_categories ic ON ic.id = t.category_id
            LEFT JOIN clients          cl ON cl.id = t.client_id
            LEFT JOIN sla_rules        sr ON sr.id = t.sla_rule_id
            LEFT JOIN sys_users        u  ON u.id  = t.closed_by
            WHERE t.id = ?";

    $ticket = $db->fetchOne($sql, [$id]);
    if (!$ticket) Response::error('التذكرة غير موجودة', 404);

    // جلب التعليقات
    $comments = $db->fetchAll(
        "SELECT tc.*, u.full_name AS author
         FROM ticket_comments tc
         JOIN sys_users u ON u.id = tc.user_id
         WHERE tc.ticket_id = ?
           AND (tc.is_internal = 0 OR ? IN (SELECT role_code FROM sys_roles WHERE id IN (SELECT role_id FROM sys_users WHERE id = ?)))
         ORDER BY tc.created_at",
        [$id, $me['role_code'], $me['user_id']]
    );

    $ticket['comments'] = $comments;

    Response::success($ticket);
}

// ─── إنشاء تذكرة جديدة ─────────────────────────────────────
if ($method === 'POST' && ($action === '' || $action === 'create')) {

    $required = ['branch_id', 'region_id', 'category_id', 'details'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            Response::error("الحقل '{$field}' مطلوب", 422);
        }
    }

    $priority = in_array($body['priority'] ?? '', ['Low','Medium','High','Urgent'])
              ? $body['priority'] : 'Medium';

    // جلب قاعدة SLA المناسبة
    $slaRule = $pdo->prepare("SELECT * FROM sla_rules WHERE priority = ? AND is_active = 1 LIMIT 1");
    $slaRule->execute([$priority]);
    $sla = $slaRule->fetch();

    $slaDeadline     = null;
    $slaRuleId       = null;
    $slaResponseDdl  = null;

    if ($sla) {
        $slaRuleId      = $sla['id'];
        $now            = new DateTime();
        $slaResponseDdl = SlaCalculator::deadline($now, (float)$sla['response_hours'],  $pdo)->format('Y-m-d H:i:s');
        $slaDeadline    = SlaCalculator::deadline($now, (float)$sla['resolution_hours'], $pdo)->format('Y-m-d H:i:s');
    }

    $sql = "INSERT INTO tickets
              (reporter_ref, client_id, branch_id, region_id, department_id,
               location_name, category_id, priority, details,
               sla_rule_id, sla_breach_at, sla_response_deadline, sla_resolve_deadline)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";

    $db->execute($sql, [
        $body['reporter_ref']   ?? ('API-' . $me['user_id']),
        $body['client_id']      ?? null,
        (int)$body['branch_id'],
        (int)$body['region_id'],
        $body['department_id']  ?? null,
        $body['location_name']  ?? null,
        (int)$body['category_id'],
        $priority,
        $body['details'],
        $slaRuleId,
        $slaDeadline,
        $slaResponseDdl,
        $slaDeadline,
    ]);

    $newId = (int)$db->lastInsertId();

    // إشعارات
    $ticket = $db->fetchOne("SELECT t.*, cl.phone AS client_phone, cl.email AS client_email, cl.client_name
                              FROM tickets t LEFT JOIN clients cl ON cl.id = t.client_id
                              WHERE t.id = ?", [$newId]);
    if ($ticket) {
        Notify::onTicketCreated($ticket, $pdo);
    }

    Response::success(['ticket_id' => $newId], 'تم إنشاء التذكرة بنجاح', 201);
}

// ─── تحديث حالة التذكرة ─────────────────────────────────────
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) Response::error('معرّف التذكرة مطلوب', 422);

    $allowed = ['status', 'priority', 'details', 'location_name'];
    $updates = [];
    $params  = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $updates[] = "{$field} = ?";
            $params[]  = $body[$field];
        }
    }

    if (empty($updates)) Response::error('لا توجد حقول للتحديث', 422);

    $params[] = $id;
    $db->execute('UPDATE tickets SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);

    Response::success(['ticket_id' => $id], 'تم التحديث بنجاح');
}

// ─── إغلاق التذكرة ─────────────────────────────────────────
if ($method === 'POST' && $action === 'close') {
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) Response::error('معرّف التذكرة مطلوب', 422);

    $successParam = null;
    $pdo->prepare("CALL sp_close_ticket(?, ?, @success)")->execute([$id, $me['user_id']]);
    $ok = (int)$pdo->query("SELECT @success")->fetchColumn();

    if (!$ok) Response::error('التذكرة غير موجودة أو مغلقة مسبقاً', 404);

    // إشعار الإغلاق
    $ticket = $db->fetchOne(
        "SELECT t.*, cl.phone AS client_phone, cl.email AS client_email, cl.client_name
         FROM tickets t LEFT JOIN clients cl ON cl.id = t.client_id WHERE t.id = ?", [$id]
    );
    if ($ticket) Notify::onTicketClosed($ticket, $pdo);

    // إضافة تعليق الإغلاق
    if (!empty($body['comment'])) {
        $pdo->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal) VALUES (?,?,?,0)")
            ->execute([$id, $me['user_id'], $body['comment']]);
    }

    Response::success(['ticket_id' => $id], 'تم إغلاق التذكرة بنجاح');
}

Response::error('الإجراء غير معروف أو الطريقة غير مدعومة', 404);
