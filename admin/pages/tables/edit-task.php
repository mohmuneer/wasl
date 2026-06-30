<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../core/Notify.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) { header("Location: ../../index.php"); exit; }

$request_id_url = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$request_id_url) { header("Location: show-tasks.php"); exit; }

// ── جلب بيانات البلاغ والمهمة ──────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.id AS task_id, t.assigned_to, t.priority AS task_priority,
           t.status AS task_status, t.deadline, t.details AS task_details,
           r.id AS req_id, r.ticket_number, r.details AS req_details,
           r.reporter_ref, r.created_at AS req_created,
           b.branch_name, reg.region_name, g.category_name,
           u.full_name AS current_tech_name
    FROM tickets r
    LEFT JOIN work_orders t  ON r.id = t.ticket_id
    LEFT JOIN branches b     ON r.branch_id = b.id
    LEFT JOIN regions reg    ON r.region_id = reg.id
    LEFT JOIN issue_categories g ON r.category_id = g.id
    LEFT JOIN sys_users u    ON t.assigned_to = u.id
    WHERE r.id = ?
");
$stmt->execute([$request_id_url]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) { header("Location: show-tasks.php"); exit; }

$request_id       = $task['req_id'];
$current_assigned = $task['assigned_to'] ?? 0;
$current_priority = $task['task_priority'] ?? 'Medium';
$current_status   = $task['task_status']   ?? 'Pending';
$current_details  = $task['task_details']  ?? '';

// ── جلب الفروع ──────────────────────────────────────────────────
$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

// ── جلب الأدوار ─────────────────────────────────────────────────
$roles = $pdo->query("SELECT id, role_name, role_code FROM sys_roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);

// ── جلب الفنيين مع بياناتهم وفروعهم ───────────────────────────
$techsQuery = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.job_title, u.file_path,
           GROUP_CONCAT(DISTINCT b.id   ORDER BY b.id   SEPARATOR ',') AS branch_ids,
           GROUP_CONCAT(DISTINCT b.branch_name ORDER BY b.branch_name SEPARATOR '|') AS branch_names,
           GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ',') AS role_codes,
           GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS role_names
    FROM sys_users u
    LEFT JOIN user_branch_access uba ON u.id = uba.user_id
    LEFT JOIN branches b ON uba.branch_id = b.id
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN sys_roles r ON ur.role_id = r.id
    WHERE u.status = 'active'
    GROUP BY u.id
    ORDER BY u.full_name
");
$technicians = $techsQuery->fetchAll(PDO::FETCH_ASSOC);

// ── معالجة الحفظ ─────────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assigned_to = (int)($_POST['assigned_to'] ?? 0);
    $priority    = $_POST['priority']  ?? 'Medium';
    $status      = $_POST['status']    ?? 'Pending';
    $deadline    = $_POST['deadline']  ?? '';
    $details     = trim($_POST['details'] ?? '');
    $old_assigned = (int)$current_assigned;

    // ── التحقق من صحة الفني المحدد ──────────────────────────────
    if ($assigned_to <= 0) {
        $flash = 'يرجى اختيار الفني المسؤول عن المهمة.';
    } else {
        $checkUser = $pdo->prepare("SELECT COUNT(*) FROM sys_users WHERE id = ? AND status = 'active'");
        $checkUser->execute([$assigned_to]);
        if (!$checkUser->fetchColumn()) {
            $flash = 'الفني المحدد غير موجود أو حسابه غير نشط.';
            $assigned_to = 0;
        }
    }

    if ($flash) goto show_page;
    // ────────────────────────────────────────────────────────────

    try {
        if (!empty($task['task_id'])) {
            $pdo->prepare("UPDATE work_orders SET assigned_to=?,priority=?,status=?,deadline=?,details=?,updated_at=NOW() WHERE ticket_id=?")
                ->execute([$assigned_to,$priority,$status,$deadline,$details,$request_id]);
        } else {
            $pdo->prepare("INSERT INTO work_orders (assigned_to,priority,status,deadline,details,ticket_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
                ->execute([$assigned_to,$priority,$status,$deadline,$details,$request_id,$current_user_id]);
        }

        // إشعار داخلي للفني إذا تغير أو كان جديداً
        if ($assigned_to > 0 && $assigned_to !== $old_assigned) {
            $priorityAr = ['Low'=>'عادي','Medium'=>'متوسط','High'=>'عالي','Urgent'=>'طارئ'][$priority] ?? $priority;
            $dlText = $deadline ? date('Y/m/d', strtotime($deadline)) : 'غير محدد';
            Notify::onTaskAssigned($pdo, $current_user_id, $assigned_to, [
                'priority'     => $priority,
                'deadline'     => $deadline,
                'details'      => $details ?: ($task['req_details'] ?? ''),
                'branch_name'  => $task['branch_name']   ?? '',
                'category_name'=> $task['category_name'] ?? '',
            ]);
        }

        // جلب اسم الفني للرسالة
        $techNameRow = $pdo->prepare("SELECT full_name FROM sys_users WHERE id = ? LIMIT 1");
        $techNameRow->execute([$assigned_to]);
        $techName = $techNameRow->fetchColumn() ?: 'الفني';

        header("Location: show-tasks.php?task_saved=1&tech=" . urlencode($techName) . "&req=" . (int)$request_id);
        exit;
    } catch (PDOException $e) {
        $flash = 'خطأ في الحفظ: ' . $e->getMessage();
    }
}

show_page:
$priorityMap = ['Low'=>['عادي','p-low'],'Medium'=>['متوسط','p-medium'],'High'=>['عالي','p-high'],'Urgent'=>['طارئ','p-urgent']];
$statusMap   = ['Pending'=>['قيد الانتظار','s-pending'],'In Progress'=>['قيد التنفيذ','s-progress'],'Resolved'=>['تم الإنجاز','s-resolved'],'Cancelled'=>['ملغي','s-cancel']];
$techsJson   = json_encode($technicians, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تعديل مهمة #<?= $request_id ?></title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<!-- jQuery مبكراً — main-header يستدعيه -->
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ── بطاقة معلومات البلاغ ── */
.ticket-info-card {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    border-radius:14px;padding:20px 24px;margin-bottom:22px;color:#fff;
}
.ticket-info-card .tik-num{font-size:1rem;opacity:.75;margin-bottom:4px}
.ticket-info-card .tik-details{font-size:.9rem;opacity:.88;line-height:1.6;margin-top:10px;background:rgba(0,0,0,.15);border-radius:8px;padding:10px 14px}
.tik-meta-item{display:flex;align-items:center;gap:7px;font-size:.78rem;opacity:.9;margin-bottom:4px}
.tik-meta-item i{width:16px;text-align:center;opacity:.7}

/* ── بطاقة القسم ── */
.et-section{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;margin-bottom:20px;border:1px solid #f0f2f7}
.et-section-head{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));padding:12px 20px;display:flex;align-items:center;gap:10px}
.et-section-head .s-ico{width:30px;height:30px;background:rgba(255,255,255,.2);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;flex-shrink:0}
.et-section-head h6{margin:0;color:#fff;font-weight:700;font-size:.88rem}
.et-section-body{padding:20px}

/* ── فلاتر الفنيين ── */
.tech-filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.tech-filter-bar select{border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 12px;font-size:.82rem;color:#475569;background:#fff;flex:1;min-width:140px;transition:.2s}
.tech-filter-bar select:focus{border-color:var(--crm-primary,#1a5276);outline:none}
.tech-filter-bar select.active-filter{border-color:var(--crm-primary,#1a5276);background:#eff6ff;color:#1d4ed8;font-weight:700}

/* ── بطاقات الفنيين ── */
.techs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;max-height:340px;overflow-y:auto;padding:4px}
.tech-card{border:2px solid #e2e8f0;border-radius:12px;padding:14px;cursor:pointer;transition:.18s;position:relative;background:#fff}
.tech-card:hover{border-color:var(--crm-primary,#1a5276);box-shadow:0 4px 14px rgba(0,0,0,.1)}
.tech-card.selected{border-color:var(--crm-primary,#1a5276);background:#eff6ff}
.tech-card.selected::after{content:'✓';position:absolute;top:8px;left:8px;background:var(--crm-primary,#1a5276);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800}
.tech-card .tc-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;color:#fff;margin:0 auto 8px;flex-shrink:0}
.tech-card .tc-name{font-size:.8rem;font-weight:700;color:#1e293b;text-align:center;margin-bottom:3px}
.tech-card .tc-role{font-size:.68rem;color:#94a3b8;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tech-card .tc-branch{font-size:.67rem;color:#64748b;text-align:center;margin-top:4px;background:#f1f5f9;border-radius:20px;padding:1px 7px;display:inline-block}
.tech-no-results{text-align:center;padding:24px;color:#94a3b8;font-size:.82rem;grid-column:1/-1}

/* ── مربع الفني المحدد ── */
.selected-tech-box{background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.selected-tech-box.empty{background:#fafbfc;border-color:#e2e8f0}
.selected-tech-name{font-weight:700;color:#065f46;font-size:.9rem}
.selected-tech-meta{font-size:.74rem;color:#64748b}

/* ── حقول النموذج ── */
.et-label{font-size:.82rem;font-weight:700;color:#475569;margin-bottom:5px;display:block}
.et-input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 13px;font-size:.88rem;color:#334155;transition:.2s}
.et-input:focus{outline:none;border-color:var(--crm-primary,#1a5276);box-shadow:0 0 0 3px rgba(26,82,118,.08)}

/* ── شارات ── */
.badge-pill-custom{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.p-urgent{background:#fee2e2;color:#dc2626}.p-high{background:#fef3c7;color:#d97706}
.p-medium{background:#dbeafe;color:#2563eb}.p-low{background:#f1f5f9;color:#64748b}
.s-pending{background:#f1f5f9;color:#475569}.s-progress{background:#dbeafe;color:#1d4ed8}
.s-resolved{background:#d1fae5;color:#065f46}.s-cancel{background:#fee2e2;color:#dc2626}

/* ── أزرار ── */
.btn-save-et{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border:none;border-radius:10px;padding:11px 30px;font-weight:700;font-size:.9rem;transition:.2s;display:inline-flex;align-items:center;gap:8px}
.btn-save-et:hover{opacity:.9;transform:translateY(-1px);color:#fff}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

    <!-- ── الترويسة ── -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-edit ml-2"></i>تعديل مهمة #<?= $request_id ?></h4>
                    <small>تحديث بيانات الإسناد والمتابعة</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item"><a href="show-tasks.php">المهام</a></li>
                    <li class="breadcrumb-item active">تعديل #<?= $request_id ?></li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <?php if ($flash): ?>
        <div class="alert d-flex align-items-center gap-2 mb-3" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px 16px;gap:10px">
            <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($flash) ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- ═══ العمود الرئيسي ═══ -->
            <div class="col-lg-8">
                <form method="POST" id="editTaskForm">

                    <!-- ── بطاقة معلومات البلاغ ── -->
                    <div class="ticket-info-card">
                        <div class="tik-num"><i class="fas fa-ticket-alt ml-1"></i>رقم البلاغ #<?= $task['req_id'] ?><?= $task['ticket_number'] ? ' (' . htmlspecialchars($task['ticket_number']) . ')' : '' ?></div>
                        <div class="row" style="margin-top:8px">
                            <?php if ($task['branch_name']): ?>
                            <div class="col-auto"><div class="tik-meta-item"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($task['branch_name']) ?></div></div>
                            <?php endif; ?>
                            <?php if ($task['region_name']): ?>
                            <div class="col-auto"><div class="tik-meta-item"><i class="fas fa-map"></i><?= htmlspecialchars($task['region_name']) ?></div></div>
                            <?php endif; ?>
                            <?php if ($task['category_name']): ?>
                            <div class="col-auto"><div class="tik-meta-item"><i class="fas fa-tag"></i><?= htmlspecialchars($task['category_name']) ?></div></div>
                            <?php endif; ?>
                            <?php if ($task['reporter_ref']): ?>
                            <div class="col-auto"><div class="tik-meta-item"><i class="fas fa-user"></i><?= htmlspecialchars($task['reporter_ref']) ?></div></div>
                            <?php endif; ?>
                            <?php if ($task['req_created']): ?>
                            <div class="col-auto"><div class="tik-meta-item"><i class="fas fa-calendar"></i><?= date('Y/m/d', strtotime($task['req_created'])) ?></div></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($task['req_details'])): ?>
                        <div class="tik-details"><?= nl2br(htmlspecialchars(mb_substr($task['req_details'], 0, 300))) ?><?= mb_strlen($task['req_details'])>300?'…':'' ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- ── اختيار الفني ── -->
                    <div class="et-section">
                        <div class="et-section-head">
                            <div class="s-ico"><i class="fas fa-user-cog"></i></div>
                            <h6>تحديد الفني المسؤول</h6>
                        </div>
                        <div class="et-section-body">

                            <!-- الفني المحدد حالياً -->
                            <div id="selectedTechBox" class="selected-tech-box <?= $current_assigned ? '' : 'empty' ?>">
                                <div>
                                    <div class="selected-tech-name" id="selectedTechName">
                                        <?= $current_assigned && $task['current_tech_name'] ? htmlspecialchars($task['current_tech_name']) : 'لم يتم تحديد فني' ?>
                                    </div>
                                    <div class="selected-tech-meta" id="selectedTechMeta">
                                        <?= $current_assigned ? 'الفني الحالي — اضغط على بطاقة للتغيير' : 'اختر فنياً من القائمة أدناه' ?>
                                    </div>
                                </div>
                                <i class="fas fa-<?= $current_assigned ? 'check-circle text-success' : 'exclamation-circle text-warning' ?>"></i>
                            </div>
                            <input type="hidden" name="assigned_to" id="assignedToInput" value="<?= (int)$current_assigned ?>">

                            <!-- فلاتر الاختيار -->
                            <div class="tech-filter-bar">
                                <select id="filterBranch" onchange="filterTechs()">
                                    <option value="">كل الفروع</option>
                                    <?php foreach ($branches as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filterRole" onchange="filterTechs()">
                                    <option value="">كل الأدوار</option>
                                    <?php foreach ($roles as $r): ?>
                                    <option value="<?= htmlspecialchars($r['role_code']) ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="position:relative;flex:1;min-width:150px">
                                    <i class="fas fa-search" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.8rem;pointer-events:none"></i>
                                    <input type="text" id="filterSearch" placeholder="ابحث باسم الفني..." oninput="filterTechs()"
                                        style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 32px 6px 10px;font-size:.82rem;color:#475569;background:#fff">
                                </div>
                                <button type="button" onclick="resetFilters()" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;white-space:nowrap">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </div>

                            <!-- شبكة الفنيين -->
                            <div class="techs-grid" id="techsGrid">
                                <?php
                                $avatarColors = ['#1a5276','#065f46','#7c3aed','#9a3412','#1d4ed8','#0369a1','#059669'];
                                $ci = 0;
                                foreach ($technicians as $tech):
                                    $initials = mb_substr($tech['full_name'],0,1,'UTF-8') . (str_word_count($tech['full_name'])>1 ? mb_substr(explode(' ',$tech['full_name'])[1],0,1,'UTF-8') : '');
                                    $clr = $avatarColors[$ci++ % count($avatarColors)];
                                    $isSelected = ($tech['id'] == $current_assigned);
                                    $branchIds = $tech['branch_ids'] ? explode(',', $tech['branch_ids']) : [];
                                    $branchNamesShort = $tech['branch_names'] ? mb_substr(str_replace('|',', ',$tech['branch_names']),0,25) : '—';
                                ?>
                                <div class="tech-card <?= $isSelected?'selected':'' ?>"
                                    data-id="<?= $tech['id'] ?>"
                                    data-name="<?= htmlspecialchars($tech['full_name']) ?>"
                                    data-role="<?= htmlspecialchars($tech['role_codes']??'') ?>"
                                    data-branch-ids="<?= htmlspecialchars($tech['branch_ids']??'') ?>"
                                    data-branches="<?= htmlspecialchars($tech['branch_names']??'') ?>"
                                    data-search="<?= strtolower(htmlspecialchars($tech['full_name'].' '.($tech['job_title']??'').' '.($tech['branch_names']??'').' '.($tech['role_names']??''))) ?>"
                                    onclick="selectTech(this)">
                                    <div class="tc-avatar" style="background:<?= $clr ?>"><?= mb_strtoupper($initials,'UTF-8') ?></div>
                                    <div class="tc-name"><?= htmlspecialchars($tech['full_name']) ?></div>
                                    <div class="tc-role"><?= htmlspecialchars($tech['role_names'] ?? '—') ?></div>
                                    <?php if ($tech['job_title']): ?>
                                    <div class="tc-role" style="color:#64748b"><?= htmlspecialchars($tech['job_title']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($tech['branch_names']): ?>
                                    <div style="text-align:center;margin-top:5px"><span class="tc-branch"><?= htmlspecialchars($branchNamesShort) ?></span></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <div id="noTechResults" class="tech-no-results" style="display:none">
                                    <i class="fas fa-search fa-lg mb-2 d-block"></i>لا توجد نتائج مطابقة
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── إعدادات المهمة ── -->
                    <div class="et-section">
                        <div class="et-section-head">
                            <div class="s-ico"><i class="fas fa-sliders-h"></i></div>
                            <h6>إعدادات المهمة</h6>
                        </div>
                        <div class="et-section-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="et-label"><i class="fas fa-exclamation-circle ml-1" style="color:var(--crm-primary,#1a5276)"></i>الأولوية</label>
                                        <select name="priority" class="et-input">
                                            <option value="Low"      <?= $current_priority=='Low'     ?'selected':'' ?>>⚪ عادي</option>
                                            <option value="Medium"   <?= $current_priority=='Medium'  ?'selected':'' ?>>🔵 متوسط</option>
                                            <option value="High"     <?= $current_priority=='High'    ?'selected':'' ?>>🟠 عالي (مستعجل)</option>
                                            <option value="Urgent"   <?= $current_priority=='Urgent'  ?'selected':'' ?>>🔴 طارئ (توقف عمل)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="et-label"><i class="fas fa-toggle-on ml-1" style="color:var(--crm-primary,#1a5276)"></i>الحالة</label>
                                        <select name="status" class="et-input">
                                            <option value="Pending"     <?= $current_status=='Pending'    ?'selected':'' ?>>⏳ قيد الانتظار</option>
                                            <option value="In Progress" <?= $current_status=='In Progress'?'selected':'' ?>>🔄 قيد التنفيذ</option>
                                            <option value="Resolved"    <?= $current_status=='Resolved'   ?'selected':'' ?>>✅ تم الإنجاز</option>
                                            <option value="Cancelled"   <?= $current_status=='Cancelled'  ?'selected':'' ?>>❌ ملغي</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="et-label"><i class="fas fa-calendar-alt ml-1" style="color:var(--crm-primary,#1a5276)"></i>الموعد النهائي</label>
                                        <input type="datetime-local" name="deadline" class="et-input"
                                            value="<?= (!empty($task['deadline']) && $task['deadline'] !== '0000-00-00 00:00:00') ? date('Y-m-d\TH:i', strtotime($task['deadline'])) : '' ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="et-label"><i class="fas fa-sticky-note ml-1" style="color:var(--crm-primary,#1a5276)"></i>ملاحظات إضافية للفني</label>
                                <textarea name="details" class="et-input" rows="3" placeholder="أضف تعليمات أو ملاحظات إضافية..."><?= htmlspecialchars($current_details) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ── أزرار الحفظ ── -->
                    <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:10px">
                        <a href="show-tasks.php" class="btn btn-outline-secondary" style="border-radius:9px;padding:9px 20px">
                            <i class="fas fa-arrow-right ml-1"></i>رجوع للمهام
                        </a>
                        <button type="submit" class="btn-save-et">
                            <i class="fas fa-save"></i>حفظ وإرسال الإشعار
                        </button>
                    </div>

                </form>
            </div>

            <!-- ═══ العمود الجانبي ═══ -->
            <div class="col-lg-4">

                <!-- ملخص الحالة الحالية -->
                <div class="et-section" style="position:sticky;top:70px">
                    <div class="et-section-head">
                        <div class="s-ico"><i class="fas fa-info-circle"></i></div>
                        <h6>ملخص المهمة الحالية</h6>
                    </div>
                    <div class="et-section-body">
                        <?php
                        $cp = $priorityMap[$current_priority] ?? ['غير محدد','p-low'];
                        $cs = $statusMap[$current_status]     ?? ['غير محدد','s-pending'];
                        $dl = (!empty($task['deadline']) && $task['deadline']!=='0000-00-00 00:00:00') ? date('Y/m/d',strtotime($task['deadline'])) : '—';
                        ?>
                        <div style="display:flex;flex-direction:column;gap:12px">
                            <div>
                                <div class="et-label mb-1">الفني الحالي</div>
                                <div style="font-weight:700;color:#1e293b;font-size:.88rem">
                                    <?= $task['current_tech_name'] ? htmlspecialchars($task['current_tech_name']) : '<span class="text-muted">غير مُسنَد</span>' ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <div>
                                    <div class="et-label mb-1">الأولوية</div>
                                    <span class="badge-pill-custom <?= $cp[1] ?>"><?= $cp[0] ?></span>
                                </div>
                                <div>
                                    <div class="et-label mb-1">الحالة</div>
                                    <span class="badge-pill-custom <?= $cs[1] ?>"><?= $cs[0] ?></span>
                                </div>
                            </div>
                            <div>
                                <div class="et-label mb-1">الموعد النهائي</div>
                                <div style="font-size:.82rem;color:#475569"><?= $dl ?></div>
                            </div>
                            <?php if ($current_details): ?>
                            <div>
                                <div class="et-label mb-1">الملاحظات</div>
                                <div style="font-size:.78rem;color:#64748b;background:#f8fafc;border-radius:8px;padding:8px 10px"><?= htmlspecialchars(mb_substr($current_details,0,150)) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <hr style="border-color:#f0f2f7;margin:16px 0">

                        <!-- معلومات الإشعار -->
                        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 12px;font-size:.76rem;color:#065f46">
                            <i class="fas fa-comments ml-1"></i>
                            <strong>إشعار تلقائي</strong><br>
                            عند تغيير الفني المُسنَد، سيصله إشعار فوري في <strong>صفحة المحادثات</strong> تلقائياً.
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
    </section>
</div>
<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
/* ══ بيانات الفنيين للفلترة ══ */
var TECHS = <?= $techsJson ?>;
var selectedId = <?= (int)$current_assigned ?>;
var avatarColors = ['#1a5276','#065f46','#7c3aed','#9a3412','#1d4ed8','#0369a1','#059669'];

/* ══ تحديد فني ══ */
function selectTech(card) {
    // إزالة التحديد من الجميع
    document.querySelectorAll('.tech-card').forEach(function(c){ c.classList.remove('selected'); });
    card.classList.add('selected');
    selectedId = parseInt(card.dataset.id);
    document.getElementById('assignedToInput').value = selectedId;

    // تحديث مربع "الفني المحدد"
    var box  = document.getElementById('selectedTechBox');
    var name = document.getElementById('selectedTechName');
    var meta = document.getElementById('selectedTechMeta');
    box.classList.remove('empty');
    name.textContent = card.dataset.name;
    meta.textContent = (card.dataset.branches ? card.dataset.branches.replace(/\|/g,', ') : '') || 'تم التحديد';
    box.querySelector('i').className = 'fas fa-check-circle text-success';
}

/* ══ فلترة الفنيين ══ */
function filterTechs() {
    var fBranch = document.getElementById('filterBranch').value;
    var fRole   = document.getElementById('filterRole').value.toLowerCase();
    var fSearch = document.getElementById('filterSearch').value.trim().toLowerCase();

    // تمييز الفلاتر النشطة
    document.getElementById('filterBranch').classList.toggle('active-filter', !!fBranch);
    document.getElementById('filterRole').classList.toggle('active-filter', !!fRole);

    var cards   = document.querySelectorAll('.tech-card');
    var visible = 0;

    cards.forEach(function(card) {
        var branchIds = (card.dataset.branchIds || '').split(',');
        var roleCodes = (card.dataset.role || '').toLowerCase();
        var search    = card.dataset.search || '';

        var matchBranch = !fBranch || branchIds.includes(fBranch);
        var matchRole   = !fRole   || roleCodes.includes(fRole);
        var matchSearch = !fSearch || search.includes(fSearch);

        var show = matchBranch && matchRole && matchSearch;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('noTechResults').style.display = visible === 0 ? 'block' : 'none';
}

function resetFilters() {
    document.getElementById('filterBranch').value = '';
    document.getElementById('filterRole').value   = '';
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterBranch').classList.remove('active-filter');
    document.getElementById('filterRole').classList.remove('active-filter');
    filterTechs();
}

/* ══ التحقق قبل الإرسال ══ */
document.getElementById('editTaskForm').addEventListener('submit', function(e) {
    if (!selectedId || selectedId === 0) {
        e.preventDefault();
        Swal.fire({ icon:'warning', title:'تنبيه', text:'الرجاء اختيار الفني المسؤول أولاً', confirmButtonText:'حسناً' });
    }
});
</script>
</body>
</html>
