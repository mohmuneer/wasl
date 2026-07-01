<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: ../../../auth/login.php");
    exit;
}

// تهيئة قائمة الملف الشامل في sys_menu تلقائياً
$menuLink = 'pages/forms/user-profile.php';
$menuExists = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuExists->execute([$menuLink]);
if (!$menuExists->fetch()) {
    $pdo->prepare("INSERT INTO sys_menu (title, icon, link, parent_id, sort_order) VALUES (?, ?, ?, 0, 140)")
        ->execute(['ملفي الشامل', 'fas fa-id-card', $menuLink]);
}

// جلب بيانات المستخدم
$stmt = $pdo->prepare("
    SELECT u.*, r.role_name, b.branch_name
    FROM sys_users u
    LEFT JOIN sys_roles r ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) die("المستخدم غير موجود");

$isRtl = true;

// ── إحصائيات ──
$ticketsCount = (int)$pdo->prepare("SELECT COUNT(*) FROM tickets WHERE created_by = ?")->execute([$user_id])
    ? $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_by = $user_id")->fetchColumn() : 0;
$assignedTicketsCount = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE assigned_to = $user_id")->fetchColumn();
$tasksCount = (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_to = $user_id")->fetchColumn();
$tasksDone = (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_to = $user_id AND status IN ('Resolved','مكتمل','completed')")->fetchColumn();
$docsCount = (int)$pdo->query("SELECT COUNT(*) FROM dms_documents WHERE created_by = $user_id")->fetchColumn();
$msgsCount = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE sender_id = $user_id OR receiver_id = $user_id")->fetchColumn();
$openTickets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE (created_by = $user_id OR assigned_to = $user_id) AND status NOT IN ('closed','مغلقة','Resolved')")->fetchColumn();

// ── آخر التذاكر ──
$recentTickets = $pdo->prepare("SELECT id, title, status, priority, created_at FROM tickets WHERE created_by = ? OR assigned_to = ? ORDER BY created_at DESC LIMIT 10");
$recentTickets->execute([$user_id, $user_id]);
$recentTickets = $recentTickets->fetchAll(PDO::FETCH_ASSOC);

// ─ـ آخر المهام ──
$recentTasks = $pdo->prepare("SELECT id, title, status, priority, deadline, created_at FROM work_orders WHERE assigned_to = ? ORDER BY created_at DESC LIMIT 10");
$recentTasks->execute([$user_id]);
$recentTasks = $recentTasks->fetchAll(PDO::FETCH_ASSOC);

// ── آخر الوثائق ──
$recentDocs = $pdo->prepare("SELECT id, doc_number, title, status, created_at FROM dms_documents WHERE created_by = ? ORDER BY created_at DESC LIMIT 10");
$recentDocs->execute([$user_id]);
$recentDocs = $recentDocs->fetchAll(PDO::FETCH_ASSOC);

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 4), '/');
$uploadsDir = __DIR__ . '/../../../uploads/';
$avatarPath = (!empty($user['file_path']) && file_exists($uploadsDir . $user['file_path']))
    ? $baseUrl . '/uploads/' . $user['file_path']
    : $baseUrl . '/admin/dist/img/avatar5.png';

$statusBadge = function($status) {
    $map = [
        'active' => ['badge-success', 'نشط'],
        'inactive' => ['badge-secondary', 'غير نشط'],
        'open' => ['badge-warning', 'مفتوحة'],
        'in_progress' => ['badge-info', 'قيد التنفيذ'],
        'resolved' => ['badge-success', 'تم الحل'],
        'closed' => ['badge-secondary', 'مغلقة'],
        'pending' => ['badge-warning', 'معلقة'],
        'completed' => ['badge-success', 'مكتملة'],
        'cancelled' => ['badge-danger', 'ملغاة'],
        'draft' => ['badge-secondary', 'مسودة'],
        'approved' => ['badge-success', 'معتمدة'],
        'archived' => ['badge-info', 'مؤرشفة'],
        'high' => ['badge-danger', 'عالية'],
        'medium' => ['badge-warning', 'متوسطة'],
        'low' => ['badge-success', 'منخفضة'],
        'urgent' => ['badge-danger', 'عاجلة'],
    ];
    $s = strtolower($status);
    $m = $map[$s] ?? ['badge-secondary', $status];
    return "<span class=\"badge {$m[0]}\" style=\"border-radius:8px;padding:4px 10px\">{$m[1]}</span>";
};
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ملفي الشامل</title>
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root { --primary: #1e4b8a; --primary-lt: rgba(30,75,138,0.08); }
        body { background:#f4f6f9; font-family:'Source Sans Pro',Arial,sans-serif; }
        .hero-card {
            background: linear-gradient(135deg, #1e4b8a, #2962b0, #1a73e8);
            border-radius: 16px; padding: 30px; color: #fff; position: relative; overflow: hidden;
        }
        .hero-card::before {
            content: ''; position: absolute; width: 220px; height: 220px; border-radius: 50%;
            background: rgba(255,255,255,0.06); top: -70px; left: -70px;
        }
        .hero-card::after {
            content: ''; position: absolute; width: 160px; height: 160px; border-radius: 50%;
            background: rgba(255,255,255,0.06); bottom: -50px; right: -50px;
        }
        .hero-avatar {
            width: 90px; height: 90px; border-radius: 50%; object-fit: cover;
            border: 4px solid rgba(255,255,255,0.5); box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        }
        .stat-tile {
            background: rgba(255,255,255,0.15); border-radius: 12px; padding: 14px 10px;
            text-align: center; backdrop-filter: blur(4px); min-width: 100px;
        }
        .stat-tile strong { display: block; font-size: 1.5rem; font-weight: 800; }
        .stat-tile small { font-size: 0.7rem; opacity: 0.85; }
        .section-card {
            border-radius: 14px !important; border: none !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06) !important; overflow: hidden;
        }
        .section-card .card-header {
            background: #fff; border-bottom: 2px solid #eef1f5; padding: 14px 20px;
        }
        .section-card .card-header h6 { margin: 0; font-weight: 700; color: var(--primary); }
        .section-card .card-body { padding: 18px 20px; }
        .info-label { color: #777; font-size: 0.82rem; font-weight: 500; }
        .info-value { font-weight: 600; color: #222; font-size: 0.92rem; }
        .table th { border-top: none; font-weight: 600; color: #555; font-size: 0.82rem; }
        .table td { font-size: 0.88rem; vertical-align: middle; }
        .empty-state { padding: 30px; text-align: center; color: #aaa; }
        .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 8px; }
        .nav-tabs .nav-link { font-weight: 600; color: #555; border: none; padding: 10px 18px; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 3px solid var(--primary); background: transparent; }
        .badge { font-weight: 500; }
    </style>
</head>
<body class="hold-transition layout-fixed">
<div class="wrapper">
    <?php include(__DIR__ . '/../../main-header.php'); ?>
    <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

    <div class="content-wrapper">
        <section class="content-header" style="padding:20px 20px 0">
            <div class="container-fluid">
                <h4 class="font-weight-bold" style="color:#1a1a2e">
                    <i class="fas fa-id-card text-primary ml-2"></i>ملفي الشامل
                </h4>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                        <li class="breadcrumb-item active">ملفي الشامل</li>
                    </ol>
                </nav>
            </div>
        </section>

        <section class="content" style="padding:16px 20px">
            <div class="container-fluid">

                <!-- ── الهيرو / البطاقة الشخصية ── -->
                <div class="hero-card mb-4">
                    <div class="d-flex align-items-center flex-wrap" style="gap:20px">
                        <img src="<?= $avatarPath ?>" class="hero-avatar" alt="">
                        <div style="flex:1;min-width:180px">
                            <h4 class="font-weight-bold mb-1"><?= htmlspecialchars($user['full_name']) ?></h4>
                            <p style="opacity:0.8;margin-bottom:6px;font-size:0.9rem">
                                <i class="fas fa-briefcase ml-1"></i><?= htmlspecialchars($user['role_name'] ?? 'مستخدم') ?>
                                <?php if (!empty($user['branch_name'])): ?>
                                &nbsp;|&nbsp; <i class="fas fa-building ml-1"></i><?= htmlspecialchars($user['branch_name']) ?>
                                <?php endif; ?>
                            </p>
                            <?= $statusBadge($user['status'] ?? 'active') ?>
                        </div>
                        <div class="d-flex flex-wrap" style="gap:10px">
                            <div class="stat-tile"><strong><?= $ticketsCount ?></strong><small>تذاكري</small></div>
                            <div class="stat-tile"><strong><?= $tasksCount ?></strong><small>مهامي</small></div>
                            <div class="stat-tile"><strong><?= $docsCount ?></strong><small>وثائقي</small></div>
                            <div class="stat-tile"><strong><?= $msgsCount ?></strong><small>رسائلي</small></div>
                        </div>
                    </div>
                </div>

                <div class="row">

                    <!-- ── العمود الأيمن: معلومات المستخدم ── -->
                    <div class="col-lg-4 mb-4">
                        <div class="card section-card">
                            <div class="card-header"><h6><i class="fas fa-info-circle ml-2"></i>معلومات الحساب</h6></div>
                            <div class="card-body">
                                <table class="table table-borderless mb-0">
                                    <tr><td class="info-label">الاسم الكامل</td><td class="info-value"><?= htmlspecialchars($user['full_name'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">البريد الإلكتروني</td><td class="info-value"><?= htmlspecialchars($user['email'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">رقم الجوال</td><td class="info-value"><?= htmlspecialchars($user['phone'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">رقم الهوية</td><td class="info-value"><?= htmlspecialchars($user['national_id'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">رقم الموظف</td><td class="info-value"><?= htmlspecialchars($user['employee_id'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">المسمى الوظيفي</td><td class="info-value"><?= htmlspecialchars($user['job_title'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">الدور</td><td class="info-value"><?= htmlspecialchars($user['role_name'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">الفرع</td><td class="info-value"><?= htmlspecialchars($user['branch_name'] ?? '—') ?></td></tr>
                                    <tr><td class="info-label">الحالة</td><td class="info-value"><?= $statusBadge($user['status'] ?? 'inactive') ?></td></tr>
                                    <tr><td class="info-label">آخر دخول</td><td class="info-value"><?= !empty($user['last_login']) ? date('Y/m/d H:i', strtotime($user['last_login'])) : '—' ?></td></tr>
                                    <tr><td class="info-label">تاريخ الإنشاء</td><td class="info-value"><?= !empty($user['created_at']) ? date('Y/m/d', strtotime($user['created_at'])) : '—' ?></td></tr>
                                </table>
                            </div>
                        </div>

                        <!-- ملخص سريع -->
                        <div class="card section-card mt-3">
                            <div class="card-header"><h6><i class="fas fa-chart-pie ml-2"></i>ملخص الأداء</h6></div>
                            <div class="card-body text-center">
                                <div class="row">
                                    <div class="col-4">
                                        <div style="font-size:1.8rem;font-weight:800;color:var(--primary)"><?= $ticketsCount ?></div>
                                        <small class="text-muted">تذاكري</small>
                                    </div>
                                    <div class="col-4">
                                        <div style="font-size:1.8rem;font-weight:800;color:#198754"><?= $tasksDone ?>/<?= $tasksCount ?></div>
                                        <small class="text-muted">مهام مكتملة</small>
                                    </div>
                                    <div class="col-4">
                                        <div style="font-size:1.8rem;font-weight:800;color:#0d6efd"><?= $docsCount ?></div>
                                        <small class="text-muted">وثائق مرفوعة</small>
                                    </div>
                                </div>
                                <?php if ($tasksCount > 0): ?>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted">نسبة إنجاز المهام</small>
                                        <small class="font-weight-bold"><?= round($tasksDone / $tasksCount * 100) ?>%</small>
                                    </div>
                                    <div class="progress" style="height:7px;border-radius:4px">
                                        <div class="progress-bar bg-success" style="width:<?= round($tasksDone / $tasksCount * 100) ?>%;border-radius:4px"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ── العمود الأيسر: التبويبات ── -->
                    <div class="col-lg-8 mb-4">

                        <ul class="nav nav-tabs mb-3" id="profileTabs" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-tickets"><i class="fas fa-ticket-alt ml-1"></i>التذاكر (<?= $ticketsCount ?>)</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-tasks"><i class="fas fa-tasks ml-1"></i>المهام (<?= $tasksCount ?>)</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-docs"><i class="fas fa-file-alt ml-1"></i>الوثائق (<?= $docsCount ?>)</a></li>
                        </ul>

                        <div class="tab-content">

                            <!-- ═══ التذاكر ═══ -->
                            <div class="tab-pane fade show active" id="tab-tickets">
                                <div class="card section-card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6><i class="fas fa-ticket-alt ml-2" style="color:#fd7e14"></i>آخر التذاكر</h6>
                                        <a href="../tables/show-requests.php" class="btn btn-sm btn-outline-primary">عرض الكل</a>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (empty($recentTickets)): ?>
                                            <div class="empty-state"><i class="fas fa-inbox"></i>لا توجد تذاكر حتى الآن</div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead><tr><th>#</th><th>العنوان</th><th>الأولوية</th><th>الحالة</th><th>التاريخ</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($recentTickets as $t): ?>
                                                    <tr>
                                                        <td><?= $t['id'] ?></td>
                                                        <td><a href="../tables/show-requests.php?id=<?= $t['id'] ?>"><?= htmlspecialchars(mb_substr($t['title'], 0, 50)) ?></a></td>
                                                        <td><?= $statusBadge($t['priority']) ?></td>
                                                        <td><?= $statusBadge($t['status']) ?></td>
                                                        <td style="font-size:0.8rem;color:#888"><?= date('Y/m/d', strtotime($t['created_at'])) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ المهام ═══ -->
                            <div class="tab-pane fade" id="tab-tasks">
                                <div class="card section-card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6><i class="fas fa-tasks ml-2" style="color:#28a745"></i>آخر المهام</h6>
                                        <a href="../tables/show-tasks.php" class="btn btn-sm btn-outline-primary">عرض الكل</a>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (empty($recentTasks)): ?>
                                            <div class="empty-state"><i class="fas fa-clipboard-list"></i>لا توجد مهام حتى الآن</div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead><tr><th>#</th><th>العنوان</th><th>الأولوية</th><th>الحالة</th><th>الموعد</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($recentTasks as $t): ?>
                                                    <tr>
                                                        <td><?= $t['id'] ?></td>
                                                        <td><a href="../tables/show-tasks.php?id=<?= $t['id'] ?>"><?= htmlspecialchars(mb_substr($t['title'], 0, 50)) ?></a></td>
                                                        <td><?= $statusBadge($t['priority'] ?? 'medium') ?></td>
                                                        <td><?= $statusBadge($t['status']) ?></td>
                                                        <td style="font-size:0.8rem;color:#888"><?= !empty($t['deadline']) ? date('Y/m/d', strtotime($t['deadline'])) : '—' ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ الوثائق ═══ -->
                            <div class="tab-pane fade" id="tab-docs">
                                <div class="card section-card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6><i class="fas fa-file-alt ml-2" style="color:#6f42c1"></i>آخر الوثائق</h6>
                                        <a href="../tables/show-documents.php" class="btn btn-sm btn-outline-primary">عرض الكل</a>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (empty($recentDocs)): ?>
                                            <div class="empty-state"><i class="fas fa-folder-open"></i>لا توجد وثائق حتى الآن</div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead><tr><th>رقم الوثيقة</th><th>العنوان</th><th>الحالة</th><th>التاريخ</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($recentDocs as $d): ?>
                                                    <tr>
                                                        <td style="font-size:0.8rem;color:var(--primary)"><?= htmlspecialchars($d['doc_number']) ?></td>
                                                        <td><a href="../tables/show-documents.php?id=<?= $d['id'] ?>"><?= htmlspecialchars(mb_substr($d['title'], 0, 50)) ?></a></td>
                                                        <td><?= $statusBadge($d['status']) ?></td>
                                                        <td style="font-size:0.8rem;color:#888"><?= date('Y/m/d', strtotime($d['created_at'])) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /.tab-content -->
                    </div><!-- /.col-lg-8 -->
                </div><!-- /.row -->
            </div>
        </section>
    </div>

    <footer class="main-footer">
        <?php include('../../main-footer.php') ?>
    </footer>
    <aside class="control-sidebar control-sidebar-dark"></aside>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
