<?php
/**
 * API Router — نظام وَصْل
 *
 * الاستخدام من التطبيق الجوال:
 *  POST /api/index.php?endpoint=auth&action=login
 *  GET  /api/index.php?endpoint=tickets
 *  POST /api/index.php?endpoint=tickets
 *  GET  /api/index.php?endpoint=stats
 *  GET  /api/index.php?endpoint=notifications
 *
 * يمكن تفعيل الروابط الجميلة عبر .htaccess:
 *  /api/auth/login → index.php?endpoint=auth&action=login
 */

// ─── Bootstrap ───────────────────────────────────────────────
define('API_MODE', true);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/db.php';        // $pdo + constants
require_once BASE_PATH . '/config/notify.php';

require_once BASE_PATH . '/api/Core/Response.php';
require_once BASE_PATH . '/api/Core/Middleware.php';
require_once BASE_PATH . '/core/SlaCalculator.php';
require_once BASE_PATH . '/core/Notify.php';

// ─── CORS ──────────────────────────────────────────────────
Middleware::cors();

// ─── Rate Limiting (60 طلب/دقيقة) ─────────────────────────
Middleware::rateLimit(60);

// ─── تحليل الطلب ────────────────────────────────────────────
$endpoint = strtolower(trim($_GET['endpoint'] ?? ''));
$action   = strtolower(trim($_GET['action']   ?? ''));
$method   = $_SERVER['REQUEST_METHOD'];

// قراءة JSON body
$rawBody  = file_get_contents('php://input');
$body     = json_decode($rawBody, true) ?? [];

// ─── التوجيه ─────────────────────────────────────────────────
match ($endpoint) {
    'auth'          => require __DIR__ . '/endpoints/auth.php',
    'tickets'       => require __DIR__ . '/endpoints/tickets.php',
    'work-orders'   => require __DIR__ . '/endpoints/work_orders.php',
    'stats'         => require __DIR__ . '/endpoints/stats.php',
    'notifications' => require __DIR__ . '/endpoints/notifications.php',
    'cron'          => require __DIR__ . '/endpoints/cron.php',
    default         => Response::error("endpoint غير معروف: {$endpoint}", 404),
};
