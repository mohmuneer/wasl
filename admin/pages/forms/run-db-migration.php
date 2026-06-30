<?php
/**
 * تطبيق مهاجرة الفهارس — نافذة إدارة قاعدة البيانات
 * الوصول: صلاحية MainAdmin فقط
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) { header("Location: ../../index.php"); exit; }

// التحقق من صلاحية MainAdmin فقط
$isAdmin = $pdo->prepare(
    "SELECT COUNT(*) FROM user_roles ur
     JOIN sys_roles r ON ur.role_id = r.id
     WHERE ur.user_id = ? AND LOWER(r.role_code) = 'mainadmin'"
);
$isAdmin->execute([$current_user_id]);
if (!$isAdmin->fetchColumn()) {
    die('<div style="font-family:Cairo,sans-serif;text-align:center;margin-top:100px;color:#dc2626">
        <h2>🔒 غير مصرح</h2><p>هذه الصفحة مخصصة لمدير النظام الرئيسي فقط</p>
    </div>');
}

$results   = [];
$totalOk   = 0;
$totalSkip = 0;
$totalFail = 0;

// ── تشغيل الهجرة ────────────────────────────────────────────────
if (isset($_POST['run_migration'])) {
    $sqlFile = __DIR__ . "/../../../../wasl_indexes_migration.sql";
    if (!file_exists($sqlFile)) {
        $results[] = ['type'=>'error', 'msg'=>'ملف الهجرة غير موجود: wasl_indexes_migration.sql'];
    } else {
        $sql = file_get_contents($sqlFile);

        // تقسيم الأوامر (إزالة التعليقات وتقسيم بـ ;)
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => strlen(trim($s)) > 5
        );

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt)) continue;

            try {
                $pdo->exec($stmt);
                $totalOk++;
                // فقط اعرض الفهارس المُضافة (ليس SET/ANALYZE)
                if (stripos($stmt, 'ADD INDEX') !== false || stripos($stmt, 'ADD FULLTEXT') !== false) {
                    preg_match('/INDEX\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $stmt, $m);
                    preg_match('/ALTER TABLE `?(\w+)`?/i', $stmt, $t);
                    $results[] = [
                        'type' => 'ok',
                        'msg'  => "✅ تم إضافة " . ($m[1] ?? '') . " على جدول " . ($t[1] ?? '')
                    ];
                }
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                // فهرس موجود مسبقاً — ليس خطأ حقيقياً
                if (str_contains($msg, 'Duplicate') || str_contains($msg, 'already exists')) {
                    $totalSkip++;
                    preg_match('/INDEX\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $stmt, $m);
                    if (!empty($m[1])) {
                        $results[] = ['type'=>'skip', 'msg' => "⏭ موجود مسبقاً: " . $m[1]];
                    }
                } elseif (str_contains($msg, "doesn't exist") && str_contains($stmt, 'ALTER TABLE')) {
                    // جدول غير موجود — تخطي
                    preg_match('/ALTER TABLE `?(\w+)`?/i', $stmt, $t);
                    $results[] = ['type'=>'warn', 'msg' => "⚠️ جدول غير موجود: " . ($t[1]??$stmt)];
                    $totalSkip++;
                } else {
                    $totalFail++;
                    $results[] = ['type'=>'error', 'msg' => "❌ خطأ: " . $msg . "\n» " . mb_substr($stmt,0,80)];
                }
            }
        }
    }
}

// ── جلب فهارس الجداول الحالية ────────────────────────────────────
$indexStats = [];
$importantTables = ['tickets','work_orders','messages','dms_documents','dms_employees','sys_users','audit_logs','clients','notifications'];
foreach ($importantTables as $tbl) {
    try {
        $rows = $pdo->query("SHOW INDEX FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
        $indexStats[$tbl] = count(array_unique(array_column($rows, 'Key_name')));
    } catch (PDOException $e) {
        $indexStats[$tbl] = null;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>مهاجرة فهارس قاعدة البيانات</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<script src="../../plugins/jquery/jquery.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;background:#f0f2f7;overflow-x:hidden;scrollbar-width:none}
.mig-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;border:1px solid #f0f2f7}
.mig-head{padding:14px 20px;display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))}
.mig-head h5{margin:0;color:#fff;font-weight:700;font-size:.95rem}
.mig-body{padding:20px}
.stat-pill{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:14px 18px;display:flex;align-items:center;gap:12px;border:1px solid #f0f2f7;text-align:center}
.result-log{background:#0f1117;border-radius:10px;padding:16px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:.78rem;color:#e2e8f0;line-height:1.8}
.result-log .ok{color:#4ade80}
.result-log .skip{color:#94a3b8}
.result-log .warn{color:#fbbf24}
.result-log .err{color:#f87171}
.idx-badge{background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700}
.btn-run{background:linear-gradient(135deg,#065f46,#059669);color:#fff;border:none;border-radius:10px;padding:12px 32px;font-weight:700;font-size:.95rem;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:10px}
.btn-run:hover{opacity:.9;transform:translateY(-1px);color:#fff}
.warning-box{background:#fff8f0;border:1.5px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:16px}
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
                    <h4><i class="fas fa-database ml-2"></i>تحسين أداء قاعدة البيانات</h4>
                    <small>إضافة فهارس (Indexes) لتسريع الاستعلامات 5x-50x</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">تحسين قاعدة البيانات</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- ── حالة الفهارس الحالية ── -->
        <div class="mig-card">
            <div class="mig-head">
                <div style="width:30px;height:30px;background:rgba(255,255,255,.2);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff"><i class="fas fa-chart-bar"></i></div>
                <h5>الفهارس الحالية لكل جدول</h5>
            </div>
            <div class="mig-body">
                <div class="row">
                    <?php
                    $colors = ['tickets'=>'#dc2626','work_orders'=>'#d97706','messages'=>'#2563eb','dms_documents'=>'#7c3aed','dms_employees'=>'#065f46','sys_users'=>'#1a5276','audit_logs'=>'#475569','clients'=>'#9a3412','notifications'=>'#0369a1'];
                    foreach ($indexStats as $tbl => $count):
                    ?>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="stat-pill" style="border-right:3px solid <?= $colors[$tbl]??'#1a5276' ?>">
                            <div style="flex:1">
                                <div style="font-size:.72rem;color:#94a3b8;margin-bottom:3px"><?= $tbl ?></div>
                                <?php if ($count !== null): ?>
                                <span class="idx-badge"><?= $count ?> فهارس</span>
                                <?php else: ?>
                                <span style="font-size:.7rem;color:#ef4444">الجدول غير موجود</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── تشغيل الهجرة ── -->
        <div class="mig-card">
            <div class="mig-head">
                <div style="width:30px;height:30px;background:rgba(255,255,255,.2);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff"><i class="fas fa-rocket"></i></div>
                <h5>تطبيق الفهارس الجديدة</h5>
            </div>
            <div class="mig-body">
                <div class="warning-box">
                    <i class="fas fa-exclamation-triangle" style="color:#d97706;margin-left:8px"></i>
                    <strong style="color:#92400e">قبل التشغيل:</strong>
                    <ul style="margin:8px 0 0 0;padding-right:20px;font-size:.82rem;color:#78350f">
                        <li>تأكد من وجود نسخة احتياطية حديثة لقاعدة البيانات</li>
                        <li>قد تستغرق العملية 30-120 ثانية حسب حجم البيانات</li>
                        <li>النظام سيظل يعمل أثناء إضافة الفهارس (لا توقف)</li>
                        <li>الفهارس الموجودة مسبقاً ستُتخطى تلقائياً</li>
                    </ul>
                </div>

                <?php if (!empty($results)): ?>
                <div class="mb-4">
                    <!-- إحصاءات التشغيل -->
                    <div class="row mb-3">
                        <div class="col-4">
                            <div style="background:#d1fae5;border-radius:10px;padding:12px;text-align:center">
                                <div style="font-size:1.5rem;font-weight:800;color:#065f46"><?= $totalOk ?></div>
                                <div style="font-size:.75rem;color:#064e3b">تم تطبيقه</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div style="background:#f1f5f9;border-radius:10px;padding:12px;text-align:center">
                                <div style="font-size:1.5rem;font-weight:800;color:#475569"><?= $totalSkip ?></div>
                                <div style="font-size:.75rem;color:#64748b">موجود مسبقاً</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div style="background:<?= $totalFail>0?'#fee2e2':'#f0fdf4' ?>;border-radius:10px;padding:12px;text-align:center">
                                <div style="font-size:1.5rem;font-weight:800;color:<?= $totalFail>0?'#dc2626':'#22c55e' ?>"><?= $totalFail ?></div>
                                <div style="font-size:.75rem;color:<?= $totalFail>0?'#991b1b':'#166534' ?>">أخطاء</div>
                            </div>
                        </div>
                    </div>

                    <!-- سجل التشغيل -->
                    <div class="result-log">
                        <?php foreach ($results as $r): ?>
                        <div class="<?= $r['type']==='ok'?'ok':($r['type']==='skip'?'skip':($r['type']==='warn'?'warn':'err')) ?>">
                            <?= htmlspecialchars($r['msg']) ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($results)): ?>
                        <div style="color:#64748b">لا توجد عمليات مُسجَّلة</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" onsubmit="this.querySelector('#runBtn').disabled=true;this.querySelector('#runBtn').innerHTML='<i class=\'fas fa-spinner fa-spin ml-1\'></i> جاري التطبيق...'">
                    <button type="submit" name="run_migration" id="runBtn" class="btn-run">
                        <i class="fas fa-play-circle"></i>
                        <?= !empty($results) ? 'إعادة تشغيل الهجرة' : 'تطبيق الفهارس الآن' ?>
                    </button>
                    <a href="../../index.php" class="btn btn-outline-secondary mr-3" style="border-radius:9px;padding:11px 20px">
                        العودة للرئيسية
                    </a>
                </form>
            </div>
        </div>

        <!-- ── شرح الفهارس ── -->
        <div class="mig-card">
            <div class="mig-head">
                <div style="width:30px;height:30px;background:rgba(255,255,255,.2);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff"><i class="fas fa-info-circle"></i></div>
                <h5>ما الفائدة من هذه الفهارس؟</h5>
            </div>
            <div class="mig-body">
                <div class="row">
                    <div class="col-md-4">
                        <div style="background:#eff6ff;border-radius:10px;padding:14px;margin-bottom:12px">
                            <h6 style="color:#1d4ed8"><i class="fas fa-search ml-1"></i>البحث والفلترة</h6>
                            <p style="font-size:.8rem;color:#334155;margin:0">بدون فهرس: MySQL تقرأ <strong>كل الصفوف</strong> لإيجاد نتيجة واحدة.<br>مع فهرس: تقفز مباشرة للنتيجة — تسريع <strong>100x+</strong></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:#f0fdf4;border-radius:10px;padding:14px;margin-bottom:12px">
                            <h6 style="color:#065f46"><i class="fas fa-sort ml-1"></i>الترتيب والتصفح</h6>
                            <p style="font-size:.8rem;color:#334155;margin:0">فرز 50,000 وثيقة بدون فهرس يستغرق ثوانٍ.<br>مع فهرس على created_at: <strong>أجزاء من الثانية</strong></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background:#fef3c7;border-radius:10px;padding:14px;margin-bottom:12px">
                            <h6 style="color:#92400e"><i class="fas fa-link ml-1"></i>JOIN بين الجداول</h6>
                            <p style="font-size:.8rem;color:#334155;margin:0">ربط tickets مع branches مع users بدون فهرس يتضاعف التعقيد.<br>الفهارس المركّبة تحل هذا <strong>بكفاءة عالية</strong></p>
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
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>
</body>
</html>
