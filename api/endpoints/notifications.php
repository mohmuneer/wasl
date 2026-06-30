<?php
/**
 * /api?endpoint=notifications
 *
 * GET               → إشعارات المستخدم الحالي
 * POST &action=read&id=N  → تعليم إشعار كمقروء
 * POST &action=read_all   → تعليم الكل مقروء
 */

// ─── المتغيرات مُحقَنة من api/index.php ───────────────────────
$method ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action ??= '';
$body   ??= [];
$pdo    ??= Database::getInstance()->getPdo();

$me = Middleware::auth($pdo);
$db = Database::getInstance();
$uid = (int)$me['user_id'];

// ─── جلب الإشعارات ─────────────────────────────────────────
if ($method === 'GET') {

    $onlyUnread = isset($_GET['unread']) && $_GET['unread'] === '1';

    $sql = "SELECT id, title, body, type, reference_id, is_read, created_at
            FROM notifications
            WHERE user_id = ?" . ($onlyUnread ? " AND is_read = 0" : "") . "
            ORDER BY created_at DESC";

    $page = max(1, (int)($_GET['page'] ?? 1));
    $data = $db->paginate($sql, [$uid], $page, 20);

    $unreadCount = (int)$db->fetchScalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
        [$uid]
    );

    $data['unread_count'] = $unreadCount;
    Response::paginated($data);
}

// ─── تعليم إشعار مقروء ─────────────────────────────────────
if ($method === 'POST' && $action === 'read') {
    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) Response::error('معرّف الإشعار مطلوب', 422);

    $db->execute(
        'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
        [$id, $uid]
    );
    Response::success(null, 'تم تعليمه كمقروء');
}

// ─── تعليم الكل مقروء ─────────────────────────────────────
if ($method === 'POST' && $action === 'read_all') {
    $db->execute(
        'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
        [$uid]
    );
    Response::success(null, 'تم تعليم جميع الإشعارات كمقروءة');
}

Response::error('الإجراء غير معروف', 404);
