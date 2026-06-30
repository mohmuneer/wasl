<?php
/**
 * /api?endpoint=stats
 *
 * GET               → ملخص لوحة التحكم
 * GET &action=sla   → إحصاءات SLA
 * GET &action=techs → أداء الفنيين
 */

// ─── المتغيرات مُحقَنة من api/index.php ───────────────────────
$method ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action ??= '';
$pdo    ??= Database::getInstance()->getPdo();

$me    = Middleware::auth($pdo);
$db    = Database::getInstance();
$cache = Cache::getInstance();

// ─── ملخص لوحة التحكم ─────────────────────────────────────
if ($method === 'GET' && ($action === '' || $action === 'summary')) {

    $data = $cache->remember('api_stats_summary_' . $me['user_id'], CACHE_TTL_SHORT, function () use ($db) {
        $summary = $db->fetchOne('SELECT * FROM v_ticket_summary');
        $today   = $db->fetchAll(
            "SELECT DATE(created_at) as day, COUNT(*) as total
             FROM tickets
             WHERE created_at >= CURDATE() - INTERVAL 6 DAY
             GROUP BY DATE(created_at)
             ORDER BY day"
        );
        $byPriority = $db->fetchAll(
            "SELECT priority, COUNT(*) as total FROM tickets
             WHERE status NOT IN ('Resolved','Cancelled')
             GROUP BY priority"
        );
        $recentTickets = $db->fetchAll(
            "SELECT t.id, t.ticket_number, t.priority, t.status, t.sla_breached,
                    t.created_at, b.branch_name, ic.category_name
             FROM tickets t
             LEFT JOIN branches         b  ON b.id  = t.branch_id
             LEFT JOIN issue_categories ic ON ic.id = t.category_id
             ORDER BY t.created_at DESC LIMIT 8"
        );

        return [
            'summary'        => $summary,
            'last_7_days'    => $today,
            'by_priority'    => $byPriority,
            'recent_tickets' => $recentTickets,
        ];
    });

    Response::success($data);
}

// ─── إحصاءات SLA ──────────────────────────────────────────
if ($method === 'GET' && $action === 'sla') {

    $data = $cache->remember('api_stats_sla', CACHE_TTL_SHORT, function () use ($db) {
        $overview = $db->fetchOne(
            "SELECT
               COUNT(*)                                        AS total_open,
               SUM(sla_breached = 1)                          AS breached,
               SUM(sla_breached = 0)                          AS on_time,
               ROUND(AVG(resolution_time_hrs), 1)             AS avg_resolution_hrs,
               ROUND(SUM(sla_breached=1)*100.0/COUNT(*), 1)   AS breach_rate
             FROM tickets
             WHERE status NOT IN ('Cancelled')"
        );

        $byCategory = $db->fetchAll(
            "SELECT ic.category_name, COUNT(*) AS total, SUM(t.sla_breached=1) AS breached
             FROM tickets t
             JOIN issue_categories ic ON ic.id = t.category_id
             WHERE t.status NOT IN ('Cancelled')
             GROUP BY ic.id, ic.category_name
             ORDER BY breached DESC"
        );

        return ['overview' => $overview, 'by_category' => $byCategory];
    });

    Response::success($data);
}

// ─── أداء الفنيين ──────────────────────────────────────────
if ($method === 'GET' && $action === 'techs') {

    Middleware::requireRole('MainAdmin', 'Supervisor', 'BranchManager');

    $data = $cache->remember('api_stats_techs', 300, function () use ($db) {
        return $db->fetchAll('SELECT * FROM v_technician_stats ORDER BY completion_rate DESC');
    });

    Response::success($data);
}

Response::error('الإجراء غير معروف', 404);
