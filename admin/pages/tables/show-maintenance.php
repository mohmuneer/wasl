<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require_once __DIR__ . "/../../../core/Notify.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$asset_id_filter = (int)($_GET['asset_id'] ?? 0);

// ── حذف جدول ─────────────────────────────────────────────────────
if (isset($_POST['delete_schedule']) && !empty($_POST['id'])) {
    $pdo->prepare("DELETE FROM ".TBL_MAINTENANCE_SCHEDULES." WHERE id=?")
        ->execute([(int)$_POST['id']]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── إرسال إشعارات الصيانة المستحقة (يُستدعى عند كل تحميل) ────────
try {
    $dueSchedules = $pdo->query("
        SELECT ms.*, a.name AS asset_name, a.asset_code
        FROM ".TBL_MAINTENANCE_SCHEDULES." ms
        JOIN ".TBL_ASSETS." a ON ms.asset_id=a.id
        WHERE ms.status='active'
          AND ms.assigned_to IS NOT NULL
          AND ms.next_due_date <= DATE_ADD(CURDATE(), INTERVAL ms.notify_days_before DAY)
          AND (ms.last_done_date IS NULL OR ms.last_done_date < ms.next_due_date)
          AND NOT EXISTS (
              SELECT 1 FROM ".TBL_MAINTENANCE_LOGS." ml
              WHERE ml.asset_id=ms.asset_id
                AND ml.maintenance_date >= DATE_SUB(ms.next_due_date, INTERVAL 1 DAY)
          )
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dueSchedules as $due) {
        $daysLeft = (strtotime($due['next_due_date']) - time()) / 86400;
        $urgency  = $daysLeft < 0 ? '🔴 متأخرة '.(int)abs($daysLeft).' يوم!' : ($daysLeft<=3 ? '🟠 تستحق خلال '.(int)$daysLeft.' أيام' : '🟡 موعدها '.$due['next_due_date']);

        // تحقق من عدم إرسال إشعار مسبق اليوم (تجنب التكرار)
        $alreadySent = $pdo->prepare("
            SELECT COUNT(*) FROM messages
            WHERE receiver_id=? AND message_text LIKE ? AND created_at >= CURDATE()
        ");
        $alreadySent->execute([$due['assigned_to'], '%'.$due['asset_code'].'%']);
        if (!$alreadySent->fetchColumn()) {
            Notify::internalMessage(
                $pdo,
                $current_user_id,
                (int)$due['assigned_to'],
                "🔧 تذكير صيانة دورية\n"
                . "الجهاز: " . $due['asset_name'] . " (" . $due['asset_code'] . ")\n"
                . "المهمة: " . $due['title'] . "\n"
                . "الحالة: " . $urgency . "\n"
                . "➡️ راجع جدول الصيانة لتسجيل الصيانة"
            );
        }
    }
} catch (Exception $e) {}

// ── حساب تاريخ الصيانة القادمة ───────────────────────────────────
function calcNextDate($lastDate, $freqType, $freqVal) {
    $base = $lastDate ? strtotime($lastDate) : time();
    $n    = max(1, (int)$freqVal);
    switch ($freqType) {
        case 'daily':     return date('Y-m-d', strtotime("+$n days", $base));
        case 'weekly':    return date('Y-m-d', strtotime("+".($n*7)." days", $base));
        case 'monthly':   return date('Y-m-d', strtotime("+$n months", $base));
        case 'quarterly': return date('Y-m-d', strtotime("+".($n*3)." months", $base));
        case 'yearly':    return date('Y-m-d', strtotime("+$n years", $base));
        default:          return date('Y-m-d', strtotime("+1 month", $base));
    }
}

// ── معالجة إضافة/تعديل جدول صيانة ───────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_schedule'])) {
    $asset_id = (int)$_POST['asset_id'];
    $freq     = $_POST['frequency_type']  ?? 'monthly';
    $freqVal  = max(1,(int)($_POST['frequency_value']??1));
    $startDate= $_POST['start_date'] ?? date('Y-m-d');
    $nextDue  = calcNextDate($startDate, $freq, $freqVal);

    if (!empty($_POST['schedule_id'])) {
        // تعديل
        $pdo->prepare("UPDATE ".TBL_MAINTENANCE_SCHEDULES." SET title=?,description=?,frequency_type=?,frequency_value=?,next_due_date=?,assigned_to=?,notify_days_before=?,status=? WHERE id=?")
        ->execute([$_POST['title'],$_POST['description']??null,$freq,$freqVal,$nextDue,$_POST['assigned_to']??null,(int)($_POST['notify_days']??3),$_POST['status']??'active',(int)$_POST['schedule_id']]);
        $msg = 'تم تحديث جدول الصيانة';
    } else {
        // إضافة
        $pdo->prepare("INSERT INTO ".TBL_MAINTENANCE_SCHEDULES."
            (asset_id,title,description,frequency_type,frequency_value,next_due_date,assigned_to,notify_days_before,created_by)
            VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$asset_id,$_POST['title'],$_POST['description']??null,$freq,$freqVal,$nextDue,$_POST['assigned_to']??null,(int)($_POST['notify_days']??3),$current_user_id]);
        $msg = 'تم إضافة جدول الصيانة';
    }
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم',text:'".addslashes($msg)."'}));window.location.href='show-maintenance.php".($asset_id_filter?"?asset_id=$asset_id_filter":"")."';</script>";
    exit;
}

// ── تسجيل صيانة منفَّذة ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['log_maintenance'])) {
    $schedule_id = !empty($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : null;
    $asset_id    = (int)$_POST['asset_id'];
    $date        = $_POST['maintenance_date'] ?? date('Y-m-d');

    // حفظ السجل
    $pdo->prepare("INSERT INTO ".TBL_MAINTENANCE_LOGS."
        (asset_id,schedule_id,maintenance_date,performed_by,work_done,parts_replaced,cost,duration_hours,status,notes,created_by)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$asset_id,$schedule_id,$date,$_POST['performed_by']??$current_user_id,$_POST['work_done'],$_POST['parts_replaced']??null,$_POST['cost']??null,$_POST['duration_hours']??null,$_POST['log_status']??'completed',$_POST['notes']??null,$current_user_id]);

    // تحديث تاريخ آخر صيانة والموعد القادم
    if ($schedule_id) {
        $sch = $pdo->prepare("SELECT * FROM ".TBL_MAINTENANCE_SCHEDULES." WHERE id=?");
        $sch->execute([$schedule_id]);
        $s = $sch->fetch(PDO::FETCH_ASSOC);
        if ($s && $s['frequency_type']!=='once') {
            $nextDue = calcNextDate($date, $s['frequency_type'], $s['frequency_value']);
            $pdo->prepare("UPDATE ".TBL_MAINTENANCE_SCHEDULES." SET last_done_date=?,next_due_date=? WHERE id=?")
            ->execute([$date, $nextDue, $schedule_id]);
        } else {
            $pdo->prepare("UPDATE ".TBL_MAINTENANCE_SCHEDULES." SET last_done_date=?,status='completed' WHERE id=?")
            ->execute([$date, $schedule_id]);
        }
    }

    // إشعار للمسؤول
    if (!empty($_POST['performed_by']) && (int)$_POST['performed_by'] !== $current_user_id) {
        Notify::internalMessage($pdo, $current_user_id, (int)$_POST['performed_by'],
            "✅ تم تسجيل صيانة\nتم تسجيل صيانة للجهاز بنجاح بتاريخ " . $date
        );
    }

    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم',text:'تم تسجيل الصيانة بنجاح'}));window.location.href='show-maintenance.php".($asset_id_filter?"?asset_id=$asset_id_filter":"")."';</script>";
    exit;
}

// ── جلب بيانات المساعدة ───────────────────────────────────────────
$users_list = $pdo->query("SELECT id, full_name FROM sys_users WHERE status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// ── جلب جداول الصيانة ────────────────────────────────────────────
$schedulesSql = "SELECT ms.*,
       a.name AS asset_name, a.asset_code, a.branch_id,
       ac.name AS cat_name, ac.color AS cat_color, ac.icon AS cat_icon,
       b.branch_name, d.department_name,
       u.full_name AS assigned_name
FROM ".TBL_MAINTENANCE_SCHEDULES." ms
JOIN ".TBL_ASSETS." a ON ms.asset_id=a.id
LEFT JOIN ".TBL_ASSET_CATEGORIES." ac ON a.category_id=ac.id
LEFT JOIN branches b ON a.branch_id=b.id
LEFT JOIN departments d ON a.department_id=d.id
LEFT JOIN sys_users u ON ms.assigned_to=u.id
WHERE ms.status != 'completed' " . ($asset_id_filter ? "AND ms.asset_id=$asset_id_filter" : "") . "
ORDER BY ms.next_due_date ASC";
$schedules = $pdo->query($schedulesSql)->fetchAll(PDO::FETCH_ASSOC);

// ── جلب سجل الصيانات الأخيرة ─────────────────────────────────────
$logsSql = "SELECT ml.*, a.name AS asset_name, a.asset_code,
       u.full_name AS tech_name
FROM ".TBL_MAINTENANCE_LOGS." ml
JOIN ".TBL_ASSETS." a ON ml.asset_id=a.id
LEFT JOIN sys_users u ON ml.performed_by=u.id
" . ($asset_id_filter ? "WHERE ml.asset_id=$asset_id_filter" : "") . "
ORDER BY ml.maintenance_date DESC LIMIT 50";
$logs = $pdo->query($logsSql)->fetchAll(PDO::FETCH_ASSOC);

// ── قائمة الأصول للقائمة المنسدلة ────────────────────────────────
$assetsList = $pdo->query("SELECT id,asset_code,name FROM ".TBL_ASSETS." WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ── أصل مُختار للعنوان ───────────────────────────────────────────
$selectedAsset = null;
if ($asset_id_filter) {
    $r = $pdo->prepare("SELECT a.*,ac.name AS cat_name FROM ".TBL_ASSETS." a LEFT JOIN ".TBL_ASSET_CATEGORIES." ac ON a.category_id=ac.id WHERE a.id=?");
    $r->execute([$asset_id_filter]);
    $selectedAsset = $r->fetch(PDO::FETCH_ASSOC);
}

// ── إحصاءات ──────────────────────────────────────────────────────
$today7 = date('Y-m-d', strtotime('+7 days'));
$stats = [
    'total'    => count($schedules),
    'overdue'  => count(array_filter($schedules, fn($s)=>$s['next_due_date']<date('Y-m-d'))),
    'soon'     => count(array_filter($schedules, fn($s)=>$s['next_due_date']>=date('Y-m-d')&&$s['next_due_date']<=$today7)),
    'logs_total'=> count($logs),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>جدول الصيانة الدورية</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}
.mnt-stat{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:14px 18px;display:flex;align-items:center;gap:12px;border:1px solid #f0f2f7}
.mnt-stat .si{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;flex-shrink:0}
.mnt-stat .sv{font-size:1.5rem;font-weight:800;line-height:1}
.mnt-stat .sl{font-size:.72rem;color:#888;margin-top:2px}
.mnt-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;border:1px solid #f0f2f7;margin-bottom:20px}
.mnt-card-head{padding:13px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))}
.mnt-card-head h5{margin:0;color:#fff;font-weight:700;font-size:.9rem}
/* الجدول */
.mnt-table thead th{background:#1a5276!important;color:#fff!important;border:none!important;font-size:.74rem;font-weight:700;padding:9px 8px;white-space:nowrap;text-align:center}
.mnt-table tbody td{font-size:.78rem;padding:9px 8px;vertical-align:middle;text-align:center;border-top:1px solid #f0f4f8!important;border-left:none!important;border-right:none!important;border-bottom:none!important}
.mnt-table tbody tr:hover{background:#f8fafc}
/* timeline للسجل */
.log-item{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f0f2f5;align-items:flex-start}
.log-item:last-child{border-bottom:none}
.log-dot{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;color:#fff;flex-shrink:0;margin-top:2px}
.log-completed{background:linear-gradient(135deg,#065f46,#059669)}
.log-partial{background:linear-gradient(135deg,#d97706,#f59e0b)}
.log-failed{background:linear-gradient(135deg,#dc2626,#ef4444)}
/* شارات */
.badge-overdue{background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700}
.badge-soon{background:#fef3c7;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700}
.badge-ok{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700}
.freq-badge{background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:6px;font-size:.68rem;font-weight:700}
.btn-act{width:28px;height:28px;padding:0;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;border:none;cursor:pointer}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>
<div class="content-wrapper">

    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-calendar-check ml-2"></i>
                        <?= $selectedAsset ? 'صيانة: '.htmlspecialchars($selectedAsset['name']) : 'جدول الصيانة الدورية' ?>
                    </h4>
                    <small><?= $selectedAsset ? $selectedAsset['asset_code'].' — '.$selectedAsset['cat_name'] : 'تتبع وجدولة صيانة جميع الأصول' ?></small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item"><a href="show-assets.php">الأصول</a></li>
                    <li class="breadcrumb-item active">الصيانة</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- إحصاءات -->
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3">
                <div class="mnt-stat"><div class="si" style="background:linear-gradient(135deg,#1a5276,#2980b9)"><i class="fas fa-calendar-alt"></i></div><div><div class="sv"><?= $stats['total'] ?></div><div class="sl">جداول نشطة</div></div></div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="mnt-stat" <?= $stats['overdue']>0?'style="border-right:3px solid #dc2626"':'' ?>><div class="si" style="background:linear-gradient(135deg,#dc2626,#ef4444)"><i class="fas fa-exclamation-triangle"></i></div><div><div class="sv" style="<?= $stats['overdue']>0?'color:#dc2626':'' ?>"><?= $stats['overdue'] ?></div><div class="sl">متأخرة</div></div></div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="mnt-stat"><div class="si" style="background:linear-gradient(135deg,#d97706,#f59e0b)"><i class="fas fa-clock"></i></div><div><div class="sv"><?= $stats['soon'] ?></div><div class="sl">خلال 7 أيام</div></div></div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="mnt-stat"><div class="si" style="background:linear-gradient(135deg,#065f46,#059669)"><i class="fas fa-check-double"></i></div><div><div class="sv"><?= $stats['logs_total'] ?></div><div class="sl">صيانة مُنجَزة</div></div></div>
            </div>
        </div>

        <div class="row">
            <!-- ═ جداول الصيانة ═ -->
            <div class="col-lg-8">
                <div class="mnt-card">
                    <div class="mnt-card-head">
                        <h5><i class="fas fa-list-alt ml-2"></i>جداول الصيانة</h5>
                        <button type="button" class="btn btn-sm" data-toggle="modal" data-target="#addScheduleModal"
                            style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:7px;font-size:.78rem">
                            <i class="fas fa-plus ml-1"></i>جدول جديد
                        </button>
                    </div>
                    <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="mnt-table table mb-0">
                        <thead><tr>
                            <th>الجهاز</th><th>المهمة</th><th>التكرار</th>
                            <th>الموعد القادم</th><th>الفني</th><th>إجراء</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($schedules as $s):
                            $daysLeft = (strtotime($s['next_due_date']) - time()) / 86400;
                            $badgeCls = $daysLeft<0 ? 'badge-overdue' : ($daysLeft<=7 ? 'badge-soon' : 'badge-ok');
                            $badgeLbl = $daysLeft<0 ? 'متأخرة '.abs((int)$daysLeft).' يوم' : ($daysLeft<=7 ? 'بعد '.(int)$daysLeft.' أيام' : date('Y/m/d',strtotime($s['next_due_date'])));
                            $freqMap  = ['once'=>'مرة واحدة','daily'=>'يومياً','weekly'=>'أسبوعياً','monthly'=>'شهرياً','quarterly'=>'كل 3 أشهر','yearly'=>'سنوياً'];
                        ?>
                        <tr>
                            <td style="text-align:right">
                                <div style="font-weight:700;font-size:.78rem;color:#1e293b"><?= htmlspecialchars($s['asset_name']) ?></div>
                                <div style="font-family:monospace;font-size:.66rem;color:#94a3b8"><?= htmlspecialchars($s['asset_code']) ?></div>
                            </td>
                            <td style="text-align:right">
                                <div style="font-size:.78rem;color:#334155;font-weight:600"><?= htmlspecialchars($s['title']) ?></div>
                                <?php if ($s['branch_name']): ?><div style="font-size:.68rem;color:#94a3b8"><?= htmlspecialchars($s['branch_name']) ?></div><?php endif; ?>
                            </td>
                            <td><span class="freq-badge"><?= $freqMap[$s['frequency_type']]??$s['frequency_type'] ?></span></td>
                            <td><span class="<?= $badgeCls ?>"><?= $badgeLbl ?></span></td>
                            <td style="font-size:.74rem"><?= htmlspecialchars($s['assigned_name']??'—') ?></td>
                            <td>
                                <div class="d-flex justify-content-center" style="gap:4px">
                                    <button type="button" class="btn-act log-btn"
                                        style="background:#d1fae5;color:#065f46" title="تسجيل صيانة"
                                        data-schedule="<?= $s['id'] ?>" data-asset="<?= $s['asset_id'] ?>"
                                        data-assetname="<?= htmlspecialchars($s['asset_name']) ?>">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn-act delete-sch-btn"
                                        style="background:#fee2e2;color:#dc2626" title="حذف الجدول"
                                        data-id="<?= $s['id'] ?>" data-title="<?= htmlspecialchars($s['title']) ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($schedules)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">
                            <i class="fas fa-calendar-times fa-lg mb-1 d-block"></i>لا توجد جداول صيانة نشطة
                        </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                </div>
            </div>

            <!-- ═ سجل الصيانات ═ -->
            <div class="col-lg-4">
                <div class="mnt-card">
                    <div class="mnt-card-head">
                        <h5><i class="fas fa-history ml-2"></i>آخر الصيانات المنفَّذة</h5>
                    </div>
                    <div class="card-body" style="max-height:520px;overflow-y:auto">
                        <?php if (empty($logs)): ?>
                        <p class="text-center text-muted py-3" style="font-size:.82rem">لم تُسجَّل أي صيانات بعد</p>
                        <?php endif; ?>
                        <?php foreach ($logs as $l): ?>
                        <div class="log-item">
                            <div class="log-dot log-<?= $l['status'] ?>">
                                <i class="fas fa-<?= $l['status']==='completed'?'check':($l['status']==='partial'?'minus':'times') ?>"></i>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:.8rem;color:#1e293b"><?= htmlspecialchars($l['asset_name']) ?></div>
                                <div style="font-size:.74rem;color:#475569;margin-bottom:2px"><?= htmlspecialchars(mb_substr($l['work_done'],0,60)) ?><?= mb_strlen($l['work_done'])>60?'…':'' ?></div>
                                <div style="display:flex;gap:8px;font-size:.68rem;color:#94a3b8;flex-wrap:wrap">
                                    <span><i class="fas fa-user ml-1"></i><?= htmlspecialchars($l['tech_name']??'—') ?></span>
                                    <span><i class="fas fa-calendar ml-1"></i><?= date('Y/m/d',strtotime($l['maintenance_date'])) ?></span>
                                    <?php if ($l['cost']): ?><span><i class="fas fa-coins ml-1"></i><?= number_format($l['cost'],0) ?> ر</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
    </section>
</div>

<!-- مودال إضافة جدول -->
<div class="modal fade" id="addScheduleModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header" style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff">
        <h5 class="modal-title"><i class="fas fa-calendar-plus ml-2"></i>إضافة جدول صيانة دوري</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="save_schedule" value="1">
        <input type="hidden" name="schedule_id" value="">
        <div class="modal-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:700;color:#475569">الجهاز/الأصل <span class="text-danger">*</span></label>
                        <select name="asset_id" class="form-control" required>
                            <option value="">— اختر الجهاز —</option>
                            <?php foreach ($assetsList as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= $a['id']==$asset_id_filter?'selected':'' ?>><?= htmlspecialchars($a['asset_code']) ?> — <?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:700;color:#475569">عنوان مهمة الصيانة <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="مثال: صيانة دورية شهرية">
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:700;color:#475569">وصف العمل المطلوب</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="تنظيف، فحص، استبدال قطع..."></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label style="font-size:.82rem;font-weight:700;color:#475569">تكرار الصيانة</label>
                                <select name="frequency_type" class="form-control">
                                    <option value="monthly">شهري</option>
                                    <option value="weekly">أسبوعي</option>
                                    <option value="quarterly">كل 3 أشهر</option>
                                    <option value="yearly">سنوي</option>
                                    <option value="daily">يومي</option>
                                    <option value="once">مرة واحدة</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label style="font-size:.82rem;font-weight:700;color:#475569">كل كم مرة</label>
                                <input type="number" name="frequency_value" class="form-control" value="1" min="1">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:700;color:#475569">تاريخ البداية</label>
                        <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:700;color:#475569">الفني المسؤول</label>
                        <select name="assigned_to" class="form-control">
                            <option value="">— غير محدد —</option>
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:.82rem;font-weight:700;color:#475569">إشعار قبل الموعد بـ (أيام)</label>
                        <input type="number" name="notify_days" class="form-control" value="3" min="0">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="background:#fafbfc">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;border-radius:8px"><i class="fas fa-save ml-1"></i>حفظ الجدول</button>
        </div>
    </form>
</div></div></div>

<!-- مودال تسجيل صيانة -->
<div class="modal fade" id="logMaintenanceModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header" style="background:linear-gradient(135deg,#065f46,#059669);color:#fff">
        <h5 class="modal-title"><i class="fas fa-check-circle ml-2"></i>تسجيل صيانة منفَّذة</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="log_maintenance" value="1">
        <input type="hidden" name="schedule_id" id="log_schedule_id">
        <input type="hidden" name="asset_id"    id="log_asset_id">
        <div class="modal-body">
            <div id="log_asset_name" style="background:#f0fdf4;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-weight:700;color:#065f46;font-size:.88rem"></div>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">تاريخ الصيانة <span class="text-danger">*</span></label><input type="date" name="maintenance_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">الفني المنفِّذ</label>
                        <select name="performed_by" class="form-control">
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id']==$current_user_id?'selected':'' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">حالة الصيانة</label>
                        <select name="log_status" class="form-control">
                            <option value="completed">مكتملة ✓</option>
                            <option value="partial">جزئية</option>
                            <option value="failed">فشلت</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">وصف العمل المنجز <span class="text-danger">*</span></label><textarea name="work_done" class="form-control" rows="4" required placeholder="ما تم فعله..."></textarea></div>
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">القطع المستبدلة</label><textarea name="parts_replaced" class="form-control" rows="2" placeholder="إن وُجدت..."></textarea></div>
                </div>
                <div class="col-md-4">
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">مدة العمل (ساعات)</label><input type="number" name="duration_hours" class="form-control" step="0.5" placeholder="2.5"></div>
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">التكلفة (ريال)</label><input type="number" name="cost" class="form-control" step="0.01"></div>
                    <div class="form-group"><label style="font-size:.82rem;font-weight:700;color:#475569">ملاحظات</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="background:#fafbfc">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#065f46,#059669);color:#fff;border-radius:8px"><i class="fas fa-save ml-1"></i>تسجيل الصيانة</button>
        </div>
    </form>
</div></div></div>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    // فتح مودال تسجيل الصيانة
    $(document).on('click','.log-btn',function(){
        var schedId = $(this).data('schedule');
        var assetId = $(this).data('asset');
        var assetName = $(this).data('assetname');
        $('#log_schedule_id').val(schedId);
        $('#log_asset_id').val(assetId);
        $('#log_asset_name').html('<i class="fas fa-tools ml-2"></i>تسجيل صيانة لـ: <strong>'+assetName+'</strong>');
        $('#logMaintenanceModal').modal('show');
    });

    // حذف جدول
    $(document).on('click','.delete-sch-btn',function(){
        var id=$(this).data('id'), title=$(this).data('title');
        Swal.fire({title:'حذف جدول "'+title+'"?',icon:'warning',showCancelButton:true,
            confirmButtonColor:'#dc2626',confirmButtonText:'نعم',cancelButtonText:'إلغاء'})
        .then(r=>{
            if(r.isConfirmed){
                var f=$('<form method="post">');
                f.append('<input type="hidden" name="save_schedule" value="1">');
                f.append('<input type="hidden" name="schedule_id" value="'+id+'">');
                f.append('<input type="hidden" name="status" value="completed">');
                f.append('<input type="hidden" name="asset_id" value="0">');
                f.append('<input type="hidden" name="title" value="deleted">');
                f.append('<input type="hidden" name="frequency_type" value="once">');
                f.append('<input type="hidden" name="start_date" value="<?= date('Y-m-d') ?>">');
                $('body').append(f);
                // بدلاً من ذلك، استخدم DELETE مباشرة
                $.post('',{delete_schedule:1,id:id},function(){location.reload();});
            }
        });
    });
});
</script>
</body>
</html>
