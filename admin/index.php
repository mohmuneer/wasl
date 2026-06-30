<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

require __DIR__ . "/../config/db.php";
require __DIR__ . '/lang/init.php';

$uid = (int)$_SESSION['user_id'];

// ══════════════════════════════════════════════════════════════════
//  بيانات KPI — البلاغات
// ══════════════════════════════════════════════════════════════════
$kpi = $pdo->query("
    SELECT
        COUNT(*)                                                       AS total_all,
        SUM(DATE(created_at) = CURDATE())                             AS today,
        SUM(DATE(created_at) = CURDATE() - INTERVAL 1 DAY)           AS yesterday,
        SUM(DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS week,
        SUM(status = 'Pending')                                        AS pending,
        SUM(status = 'In Progress')                                    AS in_progress,
        SUM(status IN ('Resolved','Closed'))                          AS resolved,
        SUM(status = 'Cancelled')                                      AS cancelled,
        SUM(sla_breached = 1)                                          AS sla_breached,
        SUM(priority = 'Urgent')                                       AS urgent
    FROM tickets
")->fetch(PDO::FETCH_ASSOC);

// حساب معدل الإنجاز
$total_active = ($kpi['total_all'] ?? 0);
$rate = $total_active > 0 ? round(($kpi['resolved'] / $total_active) * 100, 1) : 0;
$today_change = ($kpi['yesterday'] > 0)
    ? round((($kpi['today'] - $kpi['yesterday']) / $kpi['yesterday']) * 100, 1)
    : ($kpi['today'] > 0 ? 100 : 0);

// ══════════════════════════════════════════════════════════════════
//  المهام
// ══════════════════════════════════════════════════════════════════
$taskKpi = $pdo->query("
    SELECT
        COUNT(*)                            AS total,
        SUM(status = 'Pending')             AS pending,
        SUM(status = 'In Progress')         AS in_progress,
        SUM(status IN ('Resolved','Closed'))AS done,
        SUM(deadline < NOW() AND status NOT IN ('Resolved','Closed','Cancelled')) AS overdue
    FROM work_orders
")->fetch(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════════
//  الأصول والصيانة (إن وُجدت)
// ══════════════════════════════════════════════════════════════════
$assetKpi = ['total'=>0,'active'=>0,'due_soon'=>0];
try {
    $assetKpi = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(status='active') AS active,
            (SELECT COUNT(*) FROM maintenance_schedules
             WHERE status='active' AND next_due_date <= DATE_ADD(CURDATE(),INTERVAL 7 DAY)) AS due_soon
        FROM assets
    ")->fetch(PDO::FETCH_ASSOC) ?: $assetKpi;
} catch(PDOException $e){}

// ══════════════════════════════════════════════════════════════════
//  مخطط خطي — بلاغات آخر 30 يوماً
// ══════════════════════════════════════════════════════════════════
$days30 = [];
for ($i=29; $i>=0; $i--) $days30[] = date('Y-m-d', strtotime("-$i days"));

$stmt30 = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(created_at)=?");
$stmt30r = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(created_at)=? AND status IN('Resolved','Closed')");
$chartLine = ['labels'=>[],'opened'=>[],'resolved'=>[]];
foreach ($days30 as $d) {
    $chartLine['labels'][] = date('d/m', strtotime($d));
    $stmt30->execute([$d]);  $chartLine['opened'][]   = (int)$stmt30->fetchColumn();
    $stmt30r->execute([$d]); $chartLine['resolved'][] = (int)$stmt30r->fetchColumn();
}

// ══════════════════════════════════════════════════════════════════
//  توزيع الأولوية والحالة
// ══════════════════════════════════════════════════════════════════
$priorityMap = ['Urgent'=>['طارئ','#dc2626'],'High'=>['عالي','#d97706'],'Medium'=>['متوسط','#2563eb'],'Low'=>['عادي','#6b7280']];
$priority = $pdo->query("SELECT priority, COUNT(*) c FROM tickets WHERE status NOT IN('Resolved','Closed','Cancelled') GROUP BY priority")->fetchAll(PDO::FETCH_ASSOC);
$chartPriority = ['labels'=>[],'data'=>[],'colors'=>[]];
foreach ($priority as $r) {
    $m = $priorityMap[$r['priority']] ?? [$r['priority'],'#94a3b8'];
    $chartPriority['labels'][] = $m[0];
    $chartPriority['data'][]   = (int)$r['c'];
    $chartPriority['colors'][] = $m[1];
}

// ══════════════════════════════════════════════════════════════════
//  أداء الفنيين — أفضل 5
// ══════════════════════════════════════════════════════════════════
$techPerf = $pdo->query("
    SELECT u.full_name,
           COUNT(wo.id)                                            AS total,
           SUM(wo.status IN('Resolved','Closed'))                 AS done,
           ROUND(AVG(CASE WHEN wo.status IN('Resolved','Closed')
               THEN TIMESTAMPDIFF(HOUR, wo.created_at, wo.updated_at) END), 1) AS avg_hours
    FROM work_orders wo
    JOIN sys_users u ON wo.assigned_to = u.id
    WHERE wo.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY wo.assigned_to
    ORDER BY done DESC, avg_hours ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════════
//  أداء الفروع — أعلى بلاغات
// ══════════════════════════════════════════════════════════════════
$branchPerf = $pdo->query("
    SELECT b.branch_name,
           COUNT(t.id) AS total,
           SUM(t.status IN('Resolved','Closed')) AS done,
           SUM(t.sla_breached=1) AS sla_breach,
           ROUND(SUM(t.status IN('Resolved','Closed'))/COUNT(t.id)*100,0) AS rate
    FROM tickets t
    JOIN branches b ON t.branch_id = b.id
    WHERE t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY t.branch_id
    ORDER BY total DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════════
//  تنبيهات مهمة
// ══════════════════════════════════════════════════════════════════
// SLA خُرِق ولم يُغلَق
$slaAlerts = $pdo->query("
    SELECT t.id, t.priority, t.details, t.created_at,
           b.branch_name, u.full_name AS tech_name, DATEDIFF(NOW(),t.created_at) AS age_days
    FROM tickets t
    LEFT JOIN branches b ON t.branch_id = b.id
    LEFT JOIN work_orders wo ON t.id = wo.ticket_id
    LEFT JOIN sys_users u ON wo.assigned_to = u.id
    WHERE t.sla_breached=1 AND t.status NOT IN('Resolved','Closed','Cancelled')
    ORDER BY t.created_at ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// صيانة متأخرة أو قريبة
$maintAlerts = [];
try {
    $maintAlerts = $pdo->query("
        SELECT ms.title, ms.next_due_date, a.name AS asset_name, a.asset_code,
               u.full_name AS tech_name,
               DATEDIFF(ms.next_due_date, CURDATE()) AS days_left
        FROM maintenance_schedules ms
        JOIN assets a ON ms.asset_id = a.id
        LEFT JOIN sys_users u ON ms.assigned_to = u.id
        WHERE ms.status='active' AND ms.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY ms.next_due_date ASC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){}

// ══════════════════════════════════════════════════════════════════
//  آخر نشاط (timeline)
// ══════════════════════════════════════════════════════════════════
$activity = $pdo->query("
    SELECT 'ticket' AS type, id, CONCAT('بلاغ جديد #', id) AS title,
           details AS body, created_at, priority AS badge, status
    FROM tickets ORDER BY created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
$activityTasks = $pdo->query("
    SELECT 'task' AS type, wo.id, CONCAT('مهمة مُسنَدة للفني: ', u.full_name) AS title,
           wo.details AS body, wo.created_at, wo.priority AS badge, wo.status
    FROM work_orders wo JOIN sys_users u ON wo.assigned_to=u.id
    ORDER BY wo.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
$merged = array_merge($activity, $activityTasks);
usort($merged, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$allActivity = array_slice($merged, 0, 8);

// ══════════════════════════════════════════════════════════════════
//  نظام معلومات المستخدم الحالي
// ══════════════════════════════════════════════════════════════════
$meRow = $pdo->prepare("SELECT full_name, file_path, job_title FROM sys_users WHERE id=?");
$meRow->execute([$uid]);
$me = $meRow->fetch(PDO::FETCH_ASSOC);

// مهامي الشخصية
$myTasks = $pdo->prepare("
    SELECT wo.status, wo.priority, wo.deadline, t.id AS ticket_id,
           b.branch_name, cat.category_name
    FROM work_orders wo
    LEFT JOIN tickets t ON wo.ticket_id = t.id
    LEFT JOIN branches b ON t.branch_id = b.id
    LEFT JOIN issue_categories cat ON t.category_id = cat.id
    WHERE wo.assigned_to=? AND wo.status NOT IN('Resolved','Closed','Cancelled')
    ORDER BY wo.deadline ASC LIMIT 5
");
$myTasks->execute([$uid]);
$myTasksList = $myTasks->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════════
//  إحصائيات سريعة إضافية
// ══════════════════════════════════════════════════════════════════
$quickStats = [
    'users'   => (int)$pdo->query("SELECT COUNT(*) FROM sys_users WHERE status='active'")->fetchColumn(),
    'docs'    => (int)$pdo->query("SELECT COUNT(*) FROM dms_documents")->fetchColumn(),
    'pending_docs' => (int)$pdo->query("SELECT COUNT(*) FROM dms_document_approvals WHERE status='pending'")->fetchColumn(),
];

// JSON للرسوم البيانية
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
<title>لوحة التحكم التحليلية</title>
<link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/custom.css?v=202606261542">
<!-- jQuery مبكراً -->
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ══ KPI Cards ══ */
.kpi-card{border-radius:16px;padding:20px 22px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 20px rgba(0,0,0,.08);transition:.25s;cursor:default;position:relative;overflow:hidden;border:none}
.kpi-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,.14)}
.kpi-card::after{content:'';position:absolute;left:-20px;top:-20px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.1)}
.kpi-val{font-size:2rem;font-weight:900;line-height:1;color:#fff}
.kpi-lbl{font-size:.75rem;color:rgba(255,255,255,.82);margin-top:4px;font-weight:600}
.kpi-sub{font-size:.72rem;color:rgba(255,255,255,.7);margin-top:6px}
.kpi-icon{font-size:2.2rem;color:rgba(255,255,255,.25)}
.kpi-trend-up{color:#86efac;font-size:.72rem;font-weight:700}
.kpi-trend-dn{color:#fca5a5;font-size:.72rem;font-weight:700}
.kpi-trend-eq{color:rgba(255,255,255,.6);font-size:.72rem}

/* ══ قسم الرسوم البيانية ══ */
.dash-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;border:1px solid #f0f2f7}
.dash-card-head{padding:14px 20px;border-bottom:1px solid #f0f2f7;display:flex;align-items:center;justify-content:space-between}
.dash-card-head h5{margin:0;font-size:.92rem;font-weight:800;color:#1e293b}
.dash-card-body{padding:18px}

/* ══ تنبيهات ══ */
.alert-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #f8fafc;align-items:flex-start}
.alert-item:last-child{border-bottom:none}
.alert-dot{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;color:#fff;flex-shrink:0}

/* ══ Timeline ══ */
.timeline-item{display:flex;gap:12px;padding:8px 0;border-bottom:1px dashed #f0f2f5;align-items:center}
.timeline-item:last-child{border-bottom:none}
.tl-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;color:#fff;flex-shrink:0}

/* ══ جدول الفروع ══ */
.branch-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f8fafc}
.branch-row:last-child{border-bottom:none}
.branch-bar-bg{flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden}
.branch-bar-fill{height:100%;border-radius:3px;transition:width .6s ease}

/* ══ أفضل الفنيين ══ */
.tech-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f8fafc}
.tech-row:last-child{border-bottom:none}
.tech-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:800;color:#fff;flex-shrink:0}
.tech-bar-wrap{flex:1}
.tech-bar-bg{height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden;margin-top:3px}
.tech-bar-fill{height:100%;border-radius:3px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))}

/* ══ مهامي ══ */
.my-task{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f8fafc}
.my-task:last-child{border-bottom:none}

/* ══ شرائط الأولوية ══ */
.pri-urgent{background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.pri-high{background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.pri-medium{background:#dbeafe;color:#2563eb;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.pri-low{background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}

/* ══ الترحيب ══ */
.welcome-bar{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276) 0%,var(--crm-page-bar-to,#2980b9) 100%);border-radius:16px;padding:22px 28px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;color:#fff;flex-wrap:wrap;gap:16px}
.welcome-bar .wb-left h4{margin:0;font-size:1.15rem;font-weight:800}
.welcome-bar .wb-left p{margin:4px 0 0;font-size:.82rem;opacity:.85}
.welcome-bar .wb-right{display:flex;gap:16px;flex-wrap:wrap}
.wb-stat{text-align:center;background:rgba(255,255,255,.15);border-radius:10px;padding:10px 18px}
.wb-stat .ws-val{font-size:1.4rem;font-weight:900;line-height:1}
.wb-stat .ws-lbl{font-size:.68rem;opacity:.8;margin-top:2px}

/* ══ gauge ══ */
.gauge-wrap{position:relative;display:inline-block}
.gauge-num{position:absolute;top:55%;left:50%;transform:translate(-50%,-50%);font-size:1.5rem;font-weight:900;color:#1e293b}
.gauge-lbl{position:absolute;bottom:8%;left:50%;transform:translateX(-50%);font-size:.68rem;color:#94a3b8;white-space:nowrap}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include __DIR__ . '/main-header.php'; ?>
<?php include __DIR__ . '/main-sidebar.php'; ?>

<div class="content-wrapper" style="padding-bottom:30px">
<section class="content" style="padding:20px 20px 0">
<div class="container-fluid">

<!-- ══ شريط الترحيب ══ -->
<div class="welcome-bar">
    <div class="wb-left">
        <h4>مرحباً، <?= htmlspecialchars($me['full_name'] ?? 'مستخدم') ?> 👋</h4>
        <p><?= $me['job_title'] ? htmlspecialchars($me['job_title']).' · ' : '' ?><?= date('l، d F Y') ?></p>
    </div>
    <div class="wb-right">
        <div class="wb-stat">
            <div class="ws-val"><?= $kpi['today'] ?></div>
            <div class="ws-lbl">بلاغ اليوم</div>
        </div>
        <div class="wb-stat">
            <div class="ws-val"><?= $kpi['pending'] ?></div>
            <div class="ws-lbl">معلق</div>
        </div>
        <div class="wb-stat">
            <div class="ws-val"><?= count($myTasksList) ?></div>
            <div class="ws-lbl">مهامي</div>
        </div>
        <?php if ($slaAlerts): ?>
        <div class="wb-stat" style="background:rgba(220,38,38,.3)">
            <div class="ws-val"><?= count($slaAlerts) ?></div>
            <div class="ws-lbl">⚠️ خرق SLA</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ بطاقات KPI ══ -->
<div class="row mb-4">
    <?php
    $kpiCards = [
        ['البلاغات اليوم',     $kpi['today'],           $today_change, 'fas fa-ticket-alt', 'linear-gradient(135deg,#1a5276,#2980b9)', 'vs الأمس: '.$kpi['yesterday']],
        ['قيد التنفيذ',        $kpi['in_progress'],     null,          'fas fa-spinner',     'linear-gradient(135deg,#d97706,#f59e0b)', 'من '.$kpi['total_all'].' إجمالي'],
        ['معدل الإنجاز',       $rate.'%',               null,          'fas fa-chart-line',  'linear-gradient(135deg,#065f46,#059669)', $kpi['resolved'].' بلاغ مُنجَز'],
        ['خرق SLA',            $kpi['sla_breached'],    null,          'fas fa-exclamation-triangle','linear-gradient(135deg,#991b1b,#dc2626)', 'بلاغ تجاوز الوقت'],
        ['مهام متأخرة',        $taskKpi['overdue'],     null,          'fas fa-clock',       'linear-gradient(135deg,#5b21b6,#7c3aed)', 'تجاوزت الموعد النهائي'],
        ['صيانة قادمة',        $assetKpi['due_soon'],   null,          'fas fa-tools',       'linear-gradient(135deg,#0369a1,#0ea5e9)', 'خلال 7 أيام'],
    ];
    foreach ($kpiCards as $i => $c):
    ?>
    <div class="col-6 col-lg-2 mb-3">
        <div class="kpi-card h-100" style="background:<?= $c[4] ?>">
            <div>
                <div class="kpi-val"><?= $c[1] ?></div>
                <div class="kpi-lbl"><?= $c[0] ?></div>
                <div class="kpi-sub">
                    <?php if ($c[2] !== null): ?>
                        <?php if ($c[2] > 0): ?>
                        <span class="kpi-trend-up">↑ <?= abs($c[2]) ?>%</span>
                        <?php elseif ($c[2] < 0): ?>
                        <span class="kpi-trend-dn">↓ <?= abs($c[2]) ?>%</span>
                        <?php else: ?>
                        <span class="kpi-trend-eq">← ثابت</span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span><?= $c[5] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <i class="<?= $c[3] ?> kpi-icon"></i>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ══ الصف الثاني: مخطط خطي + دائري ══ -->
<div class="row mb-4">
    <!-- مخطط الخط — 30 يوم -->
    <div class="col-lg-8 mb-3">
        <div class="dash-card h-100">
            <div class="dash-card-head">
                <h5><i class="fas fa-chart-line ml-2" style="color:var(--crm-primary,#1a5276)"></i>البلاغات — آخر 30 يوماً</h5>
                <div style="display:flex;gap:12px;font-size:.72rem">
                    <span style="color:#2563eb"><span style="display:inline-block;width:12px;height:3px;background:#2563eb;border-radius:2px;vertical-align:middle;margin-left:4px"></span>مفتوح</span>
                    <span style="color:#059669"><span style="display:inline-block;width:12px;height:3px;background:#059669;border-radius:2px;vertical-align:middle;margin-left:4px"></span>مُنجَز</span>
                </div>
            </div>
            <div class="dash-card-body" style="padding-top:10px">
                <canvas id="lineChart" height="90"></canvas>
            </div>
        </div>
    </div>

    <!-- مخطط دائري -->
    <div class="col-lg-4 mb-3">
        <div class="dash-card h-100">
            <div class="dash-card-head">
                <h5><i class="fas fa-chart-pie ml-2" style="color:var(--crm-primary,#1a5276)"></i>توزيع الأولوية</h5>
            </div>
            <div class="dash-card-body text-center">
                <canvas id="donutChart" height="160" style="max-height:160px"></canvas>
                <div class="mt-3" style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px">
                    <?php foreach ($chartPriority['labels'] as $i=>$lbl): ?>
                    <span style="font-size:.72rem;color:#475569;display:flex;align-items:center;gap:4px">
                        <span style="width:10px;height:10px;border-radius:50%;background:<?= $chartPriority['colors'][$i] ?>;display:inline-block"></span>
                        <?= $lbl ?>: <strong><?= $chartPriority['data'][$i] ?></strong>
                    </span>
                    <?php endforeach; ?>
                </div>
                <!-- معدل الإنجاز gauge -->
                <div class="mt-3 p-3 rounded" style="background:#f8fafc">
                    <div style="font-size:.72rem;color:#94a3b8;margin-bottom:6px">معدل الإنجاز الكلي</div>
                    <div style="font-size:2rem;font-weight:900;color:<?= $rate>=70?'#059669':($rate>=40?'#d97706':'#dc2626') ?>"><?= $rate ?>%</div>
                    <div style="height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;margin-top:6px">
                        <div style="width:<?= $rate ?>%;height:100%;background:<?= $rate>=70?'linear-gradient(135deg,#059669,#10b981)':($rate>=40?'linear-gradient(135deg,#d97706,#f59e0b)':'linear-gradient(135deg,#dc2626,#ef4444)') ?>;border-radius:4px;transition:width 1s ease"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:.65rem;color:#94a3b8;margin-top:4px"><span>0%</span><span>100%</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ الصف الثالث: أداء الفنيين + الفروع + الإحصاءات السريعة ══ -->
<div class="row mb-4">

    <!-- أفضل الفنيين -->
    <div class="col-lg-4 mb-3">
        <div class="dash-card h-100">
            <div class="dash-card-head">
                <h5><i class="fas fa-medal ml-2" style="color:#d97706"></i>أفضل الفنيين — 30 يوم</h5>
            </div>
            <div class="dash-card-body">
                <?php if (empty($techPerf)): ?>
                <p class="text-muted text-center py-3" style="font-size:.82rem">لا توجد بيانات</p>
                <?php endif; ?>
                <?php
                $techColors = ['#1a5276','#065f46','#7c3aed','#d97706','#0369a1'];
                $maxDone = max(1, max(array_column($techPerf,'done')));
                foreach ($techPerf as $ti => $t):
                    $initials = mb_substr($t['full_name'],0,1,'UTF-8');
                    $pct = round($t['done']/$maxDone*100);
                ?>
                <div class="tech-row">
                    <div style="font-size:.7rem;font-weight:800;color:#94a3b8;width:16px"><?= $ti+1 ?></div>
                    <div class="tech-avatar" style="background:<?= $techColors[$ti%5] ?>"><?= $initials ?></div>
                    <div class="tech-bar-wrap" style="flex:1;min-width:0">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <span style="font-size:.76rem;font-weight:700;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($t['full_name']) ?></span>
                            <span style="font-size:.68rem;color:#94a3b8;flex-shrink:0;margin-right:6px"><?= $t['done'] ?>/<?= $t['total'] ?></span>
                        </div>
                        <div class="tech-bar-bg"><div class="tech-bar-fill" style="width:<?= $pct ?>%"></div></div>
                        <?php if ($t['avg_hours']): ?>
                        <div style="font-size:.62rem;color:#94a3b8;margin-top:1px">⏱ متوسط <?= $t['avg_hours'] ?> ساعة</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- أداء الفروع -->
    <div class="col-lg-4 mb-3">
        <div class="dash-card h-100">
            <div class="dash-card-head">
                <h5><i class="fas fa-map-marker-alt ml-2" style="color:#dc2626"></i>أداء الفروع — 30 يوم</h5>
            </div>
            <div class="dash-card-body">
                <?php if (empty($branchPerf)): ?>
                <p class="text-muted text-center py-3" style="font-size:.82rem">لا توجد بيانات</p>
                <?php endif; ?>
                <?php foreach ($branchPerf as $b): ?>
                <div class="branch-row">
                    <div style="font-size:.74rem;font-weight:700;color:#1e293b;min-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($b['branch_name']) ?></div>
                    <div class="branch-bar-bg">
                        <div class="branch-bar-fill" style="width:<?= $b['rate'] ?>%;background:<?= $b['rate']>=70?'#059669':($b['rate']>=40?'#d97706':'#dc2626') ?>"></div>
                    </div>
                    <div style="font-size:.68rem;color:#475569;flex-shrink:0;margin-right:6px;text-align:left;min-width:36px"><?= $b['rate'] ?>%</div>
                    <?php if ($b['sla_breach'] > 0): ?>
                    <span style="background:#fee2e2;color:#dc2626;font-size:.6rem;padding:1px 6px;border-radius:10px;flex-shrink:0">SLA <?= $b['sla_breach'] ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- إحصاءات سريعة + مهامي -->
    <div class="col-lg-4 mb-3">
        <!-- الإحصاءات السريعة -->
        <div class="dash-card mb-3">
            <div class="dash-card-head">
                <h5><i class="fas fa-tachometer-alt ml-2" style="color:var(--crm-primary,#1a5276)"></i>نظرة عامة</h5>
            </div>
            <div class="dash-card-body" style="padding:14px 18px">
                <?php
                $quickItems = [
                    ['fas fa-users','#1a5276',    'المستخدمون النشطون', $quickStats['users']],
                    ['fas fa-file-alt','#7c3aed',  'إجمالي الوثائق',     $quickStats['docs']],
                    ['fas fa-clock','#d97706',     'اعتمادات معلقة',     $quickStats['pending_docs']],
                    ['fas fa-cubes','#065f46',     'الأصول النشطة',      $assetKpi['active']],
                    ['fas fa-ticket-alt','#dc2626','بلاغات هذا الأسبوع', $kpi['week']],
                ];
                foreach ($quickItems as $qi):
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f8fafc">
                    <div style="width:28px;height:28px;border-radius:7px;background:<?= $qi[1] ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="<?= $qi[0] ?>" style="color:<?= $qi[1] ?>;font-size:.76rem"></i>
                    </div>
                    <div style="flex:1;font-size:.78rem;color:#475569"><?= $qi[2] ?></div>
                    <div style="font-size:.88rem;font-weight:800;color:#1e293b"><?= number_format($qi[3]) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ الصف الرابع: التنبيهات + مهامي + النشاط ══ -->
<div class="row mb-4">

    <!-- تنبيهات SLA -->
    <div class="col-lg-4 mb-3">
        <div class="dash-card h-100" style="border-right:3px solid #dc2626">
            <div class="dash-card-head" style="background:#fff5f5">
                <h5 style="color:#dc2626"><i class="fas fa-exclamation-triangle ml-2"></i>تنبيهات خرق SLA</h5>
                <span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700"><?= count($slaAlerts) ?></span>
            </div>
            <div class="dash-card-body">
                <?php if (empty($slaAlerts)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
                    <span style="font-size:.82rem;color:#64748b">لا توجد خروقات SLA 🎉</span>
                </div>
                <?php endif; ?>
                <?php foreach ($slaAlerts as $a): ?>
                <div class="alert-item">
                    <div class="alert-dot" style="background:<?= $a['priority']==='Urgent'?'#dc2626':($a['priority']==='High'?'#d97706':'#2563eb') ?>">
                        <i class="fas fa-exclamation"></i>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.76rem;font-weight:700;color:#1e293b">بلاغ #<?= $a['id'] ?></div>
                        <div style="font-size:.68rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($a['details']??'',0,50)) ?></div>
                        <div style="font-size:.64rem;color:#94a3b8;margin-top:2px">
                            <i class="fas fa-map-marker-alt ml-1"></i><?= htmlspecialchars($a['branch_name']??'') ?>
                            <span class="mx-1">·</span>
                            <i class="fas fa-clock ml-1"></i><?= $a['age_days'] ?> يوم
                        </div>
                    </div>
                    <a href="pages/forms/view-request.php?id=<?= $a['id'] ?>" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border-radius:7px;padding:4px 8px;font-size:.68rem;white-space:nowrap;flex-shrink:0">
                        عرض
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- مهامي الشخصية -->
    <div class="col-lg-4 mb-3">
        <div class="dash-card h-100" style="border-right:3px solid #1a5276">
            <div class="dash-card-head">
                <h5><i class="fas fa-tasks ml-2" style="color:#1a5276"></i>مهامي الحالية</h5>
                <a href="pages/tables/show-tasks.php" style="font-size:.72rem;color:var(--crm-primary,#1a5276)">عرض الكل →</a>
            </div>
            <div class="dash-card-body">
                <?php if (empty($myTasksList)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-check-double fa-2x text-success mb-2 d-block"></i>
                    <span style="font-size:.82rem;color:#64748b">لا توجد مهام معلقة ✅</span>
                </div>
                <?php endif; ?>
                <?php
                $priMap = ['Urgent'=>['pri-urgent','طارئ'],'High'=>['pri-high','عالي'],'Medium'=>['pri-medium','متوسط'],'Low'=>['pri-low','عادي']];
                foreach ($myTasksList as $mt):
                    $pr = $priMap[$mt['priority']] ?? ['pri-low',$mt['priority']];
                    $dl = !empty($mt['deadline']) && $mt['deadline']!=='0000-00-00 00:00:00';
                    $overdue = $dl && strtotime($mt['deadline']) < time();
                ?>
                <div class="my-task">
                    <div style="width:6px;height:36px;border-radius:3px;background:<?= $mt['priority']==='Urgent'?'#dc2626':($mt['priority']==='High'?'#d97706':'#2563eb') ?>;flex-shrink:0"></div>
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
                            <span class="<?= $pr[0] ?>"><?= $pr[1] ?></span>
                            <?php if ($mt['ticket_id']): ?><span style="font-size:.64rem;color:#94a3b8">#<?= $mt['ticket_id'] ?></span><?php endif; ?>
                        </div>
                        <div style="font-size:.74rem;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($mt['category_name']??'مهمة') ?> — <?= htmlspecialchars($mt['branch_name']??'') ?></div>
                        <?php if ($dl): ?>
                        <div style="font-size:.64rem;color:<?= $overdue?'#dc2626':'#94a3b8' ?>;margin-top:2px">
                            <?= $overdue?'⚠️ متأخرة:':'' ?> <?= date('Y/m/d', strtotime($mt['deadline'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- صيانة قادمة + آخر نشاط -->
    <div class="col-lg-4 mb-3">
        <!-- صيانة قادمة -->
        <?php if (!empty($maintAlerts)): ?>
        <div class="dash-card mb-3" style="border-right:3px solid #d97706">
            <div class="dash-card-head" style="background:#fff8f0">
                <h5 style="color:#d97706"><i class="fas fa-tools ml-2"></i>صيانة قادمة</h5>
            </div>
            <div class="dash-card-body" style="padding:10px 16px">
                <?php foreach ($maintAlerts as $ma): ?>
                <div class="alert-item">
                    <div class="alert-dot" style="background:<?= $ma['days_left']<0?'#dc2626':($ma['days_left']<=3?'#d97706':'#2563eb') ?>">
                        <i class="fas fa-wrench" style="font-size:.65rem"></i>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.74rem;font-weight:700;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($ma['asset_name']) ?></div>
                        <div style="font-size:.68rem;color:#64748b"><?= htmlspecialchars($ma['title']) ?></div>
                        <div style="font-size:.62rem;color:<?= $ma['days_left']<0?'#dc2626':'#94a3b8' ?>">
                            <?= $ma['days_left']<0 ? '⚠️ متأخرة '.abs($ma['days_left']).' يوم' : 'بعد '.$ma['days_left'].' أيام' ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- آخر نشاط -->
        <div class="dash-card">
            <div class="dash-card-head">
                <h5><i class="fas fa-history ml-2" style="color:#7c3aed"></i>آخر النشاطات</h5>
            </div>
            <div class="dash-card-body" style="padding:10px 16px">
                <?php foreach (array_slice($allActivity,0,5) as $act):
                    $isTicket = $act['type']==='ticket';
                    $prClr = ['Urgent'=>'#dc2626','High'=>'#d97706','Medium'=>'#2563eb','Low'=>'#6b7280'][$act['badge']]??'#94a3b8';
                ?>
                <div class="timeline-item">
                    <div class="tl-icon" style="background:<?= $isTicket?'#eff6ff':'#f0fdf4' ?>">
                        <i class="fas fa-<?= $isTicket?'ticket-alt':'tasks' ?>" style="color:<?= $isTicket?'#2563eb':'#059669' ?>;font-size:.68rem"></i>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.73rem;font-weight:700;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($act['title']) ?></div>
                        <div style="font-size:.65rem;color:#94a3b8;margin-top:1px"><?= date('H:i · d/m', strtotime($act['created_at'])) ?></div>
                    </div>
                    <div class="pri-<?= strtolower($act['badge']) ?>" style="flex-shrink:0"><?= $act['badge'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

</div>
</section>
</div>

<footer class="main-footer"><?php include 'main-footer.php' ?></footer>
</div>

<!-- Chart.js -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
Chart.defaults.font.family = "'Cairo', 'sans-serif'";
Chart.defaults.color = '#64748b';

// ── مخطط الخط — 30 يوم ──────────────────────────────────────────
(function() {
    var ctx = document.getElementById('lineChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= $j($chartLine['labels']) ?>,
            datasets: [
                {
                    label: 'مفتوح',
                    data: <?= $j($chartLine['opened']) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,.08)',
                    fill: true,
                    tension: .4,
                    pointRadius: 2,
                    borderWidth: 2
                },
                {
                    label: 'مُنجَز',
                    data: <?= $j($chartLine['resolved']) ?>,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,.07)',
                    fill: true,
                    tension: .4,
                    pointRadius: 2,
                    borderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false, rtl: true }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 10 } } },
                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1, font: { size: 10 } } }
            }
        }
    });
})();

// ── مخطط دائري ──────────────────────────────────────────────────
(function() {
    var ctx = document.getElementById('donutChart');
    if (!ctx) return;
    var labels = <?= $j($chartPriority['labels']) ?>;
    if (labels.length === 0) { ctx.closest('.dash-card-body').querySelector('canvas').style.display='none'; return; }
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: <?= $j($chartPriority['data']) ?>,
                backgroundColor: <?= $j($chartPriority['colors']) ?>,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: { rtl: true, bodyFont: { family: 'Cairo' } }
            }
        }
    });
})();
</script>
<?php include __DIR__ . '/print_header.php'; ?>
</body>
</html>
