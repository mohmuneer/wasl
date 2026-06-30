<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/archive-browser.php";
if (!$current_user_id) die(__('login_required'));

try {
    $menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
    $menuStmt->execute([$page_path]);
    $current_page_id = $menuStmt->fetchColumn() ?? 0;

    $can_view = 0;
    if ($current_page_id > 0) {
        $accStmt = $pdo->prepare("SELECT can_view FROM user_menu_access WHERE user_id = ? AND menu_id = ?");
        $accStmt->execute([$current_user_id, $current_page_id]);
        $can_view = (int)($accStmt->fetchColumn() ?: 0);
    }
    if ($can_view != 1 && ($_SESSION['role_code'] ?? '') !== 'MainAdmin') exit(__('no_permission'));

    // ── إحصائيات ──────────────────────────────────────────────────────
    $totalDocs    = (int)$pdo->query("SELECT COUNT(*) FROM dms_documents")->fetchColumn();
    $totalSigned  = (int)$pdo->query("SELECT COUNT(DISTINCT document_id) FROM dms_signatures WHERE status='signed'")->fetchColumn();
    $totalPending = (int)$pdo->query("SELECT COUNT(DISTINCT document_id) FROM dms_signatures WHERE status='pending'")->fetchColumn();

    // ── جلب كل الوثائق مع بيانات النوع ───────────────────────────────
    $allDocs = $pdo->query("
        SELECT d.id, d.doc_number, d.title, d.status, d.file_format,
               d.department, d.created_at,
               t.name AS type_name, t.id AS type_id
        FROM dms_documents d
        LEFT JOIN dms_document_types t ON d.type_id = t.id
        ORDER BY d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── بناء هيكل البيانات: سنة → قسم → نوع → وثائق ──────────────────
    $tree = [];      // ['2025']['IT']['PDF'] = [docs...]
    $yearCounts = [];
    $deptCounts = [];
    $years = [];

    foreach ($allDocs as $doc) {
        $yr   = date('Y', strtotime($doc['created_at']));
        $dept = $doc['department'] ?: __('archive_no_dept');
        $type = $doc['type_name'] ?: 'أخرى';

        $tree[$yr][$dept][$type][] = $doc;
        $yearCounts[$yr] = ($yearCounts[$yr] ?? 0) + 1;
        $deptCounts[$dept] = ($deptCounts[$dept] ?? 0) + 1;
    }
    krsort($tree);
    $years = array_keys($tree);

    // ── أنواع الوثائق ──────────────────────────────────────────────────
    $docTypes = $pdo->query("SELECT id, name FROM dms_document_types WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // ── أحدث الوثائق ──────────────────────────────────────────────────
    $recentDocs = array_slice($allDocs, 0, 6);

} catch (PDOException $e) { die("خطأ: " . $e->getMessage()); }

// ── دوال مساعدة للواجهة ───────────────────────────────────────────
function fileIcon($fmt) {
    $map = ['PDF'=>['fa-file-pdf','#e74c3c'],'DOC'=>['fa-file-word','#2980b9'],'DOCX'=>['fa-file-word','#2980b9'],'XLS'=>['fa-file-excel','#27ae60'],'XLSX'=>['fa-file-excel','#27ae60'],'JPG'=>['fa-file-image','#8e44ad'],'JPEG'=>['fa-file-image','#8e44ad'],'PNG'=>['fa-file-image','#8e44ad']];
    return $map[strtoupper($fmt)] ?? ['fa-file-alt','#7f8c8d'];
}
function statusBadge($s) {
    $m=['draft'=>['#6c757d','مسودة'],'approved'=>['#27ae60','معتمدة'],'archived'=>['#1565c0','مؤرشفة'],'cancelled'=>['#c62828','ملغاة']];
    return $m[$s] ?? ['#aaa', $s];
}
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= __('archive_title') ?></title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
:root { --p:#1e4b8a; --p-lt:#e8eef8; --gold:#f39c12; }
body { direction:<?= isRtl()?'rtl':'ltr' ?>; text-align:<?= isRtl()?'right':'left' ?>; background:#f0f2f5; font-family:'Source Sans Pro',Arial,sans-serif; }

/* ── ترويسة ─── */

/* ── KPI ─── */
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
@media(max-width:900px){ .kpi-row { grid-template-columns:repeat(2,1fr); } }
.kpi { border-radius:12px; padding:16px 18px; color:#fff; display:flex; align-items:center; gap:14px; box-shadow:0 4px 14px rgba(0,0,0,.12); transition:transform .18s; cursor:default; }
.kpi:hover { transform:translateY(-2px); }
.kpi-icon { width:48px; height:48px; border-radius:10px; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.kpi-val { font-size:1.8rem; font-weight:800; line-height:1; }
.kpi-lbl { font-size:.78rem; opacity:.9; }

/* ── تخطيط رئيسي ─── */
.layout { display:grid; grid-template-columns:240px 1fr; gap:16px; align-items:start; }
@media(max-width:900px){ .layout { grid-template-columns:1fr; } }

/* ── شريط التنقل الجانبي ─── */
.sidebar { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.07); overflow:hidden; position:sticky; top:70px; }
.sb-sec { border-bottom:1px solid #eef0f5; }
.sb-sec:last-child { border:none; }
.sb-head { background:var(--p-lt); padding:10px 14px; font-weight:700; font-size:.8rem; color:var(--p); display:flex; align-items:center; gap:7px; }
.year-list { padding:10px; display:flex; flex-direction:column; gap:6px; }
.year-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:9px 12px; border-radius:9px; cursor:pointer;
    border:1.5px solid #dde3f0; background:#fff; transition:all .15s;
    font-weight:700; color:#444; user-select:none;
}
.year-item:hover { border-color:var(--p); background:var(--p-lt); color:var(--p); }
.year-item.active { border-color:var(--p); background:var(--p); color:#fff; }
.year-item .yi-count { font-size:.7rem; font-weight:600; background:rgba(255,255,255,.25); padding:2px 7px; border-radius:10px; }
.year-item:not(.active) .yi-count { background:#eef0f8; color:#666; }
.sb-link-list { list-style:none; margin:0; padding:0; }
.sb-link-list li a { display:flex; align-items:center; justify-content:space-between; padding:8px 14px; color:#444; font-size:.82rem; text-decoration:none; border-bottom:1px solid #f5f6f8; transition:background .12s; }
.sb-link-list li:last-child a { border:none; }
.sb-link-list li a:hover { background:#f5f7ff; color:var(--p); text-decoration:none; }

/* ── منطقة المحتوى ─── */
.content-area { display:flex; flex-direction:column; gap:16px; }

/* شريط المسار التنقل */
.breadpath {
    background:#fff; border-radius:10px; padding:10px 16px;
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    box-shadow:0 1px 6px rgba(0,0,0,.06); font-size:.84rem;
}
.breadpath .bp-item { color:#888; cursor:pointer; transition:color .12s; }
.breadpath .bp-item:hover { color:var(--p); text-decoration:underline; }
.breadpath .bp-item.active { color:#333; font-weight:600; cursor:default; text-decoration:none; }
.breadpath .bp-sep { color:#ccc; font-size:.65rem; }
.bp-home { color:var(--p); font-weight:700; cursor:pointer; }

/* بطاقات المجلدات */
.panel { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.07); overflow:hidden; }
.panel-head { background:var(--p-lt); padding:13px 18px; border-bottom:2px solid var(--p); display:flex; align-items:center; justify-content:space-between; }
.panel-head h6 { margin:0; color:var(--p); font-weight:700; font-size:.95rem; display:flex; align-items:center; gap:8px; }
.panel-head .ph-count { background:var(--p); color:#fff; font-size:.7rem; padding:2px 9px; border-radius:10px; }
.panel-body { padding:18px; }

.folder-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px; }
.folder-card {
    background:#fff; border:2px solid #eef0f8; border-radius:12px;
    padding:14px 10px; text-align:center; cursor:pointer;
    transition:all .18s; user-select:none;
}
.folder-card:hover { border-color:var(--gold); box-shadow:0 4px 14px rgba(243,156,18,.2); transform:translateY(-2px); }
.folder-card .fc-emoji { font-size:2.2rem; line-height:1; margin-bottom:8px; }
.folder-card .fc-name { font-size:.76rem; font-weight:600; color:#333; word-break:break-word; line-height:1.3; }
.folder-card .fc-cnt  { font-size:.68rem; color:#aaa; margin-top:4px; }

/* قائمة الوثائق */
.doc-list { display:flex; flex-direction:column; gap:10px; }
.doc-row {
    display:flex; align-items:center; gap:14px;
    background:#fff; border-radius:11px; padding:12px 16px;
    box-shadow:0 1px 6px rgba(0,0,0,.06);
    text-decoration:none; color:#333; transition:box-shadow .15s, transform .12s;
    border: 1.5px solid transparent;
}
.doc-row:hover { box-shadow:0 4px 14px rgba(0,0,0,.1); transform:translateY(-1px); text-decoration:none; color:#222; border-color:#dde3f0; }
.doc-row .dr-icon { width:42px; height:42px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1.15rem; color:#fff; flex-shrink:0; }
.doc-row .dr-body { flex:1; min-width:0; }
.doc-row .dr-num   { font-size:.7rem; color:#aaa; }
.doc-row .dr-title { font-size:.87rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.doc-row .dr-meta  { font-size:.72rem; color:#bbb; margin-top:2px; }
.doc-row .dr-status{ flex-shrink:0; font-size:.72rem; font-weight:600; padding:3px 10px; border-radius:20px; }

/* حالة فارغة */
.empty-state { text-align:center; padding:40px 20px; color:#bbb; }
.empty-state i { font-size:3rem; margin-bottom:12px; display:block; }
.empty-state p { font-size:.9rem; }

/* شريط البحث */
.search-bar { display:flex; gap:10px; margin-bottom:14px; }
.search-bar input { flex:1; border-radius:9px; border:1.5px solid #dde3f0; padding:8px 14px; font-size:.85rem; }
.search-bar input:focus { outline:none; border-color:var(--p); box-shadow:0 0 0 3px rgba(30,75,138,.1); }

/* شبكة الأقسام الكاملة (المنزل) */
.home-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:14px; }
.home-year-card {
    background:#fff; border:2px solid #e0e6f0; border-radius:14px;
    padding:20px 14px; text-align:center; cursor:pointer;
    transition:all .2s; box-shadow:0 2px 8px rgba(0,0,0,.06);
}
.home-year-card:hover { border-color:var(--p); box-shadow:0 6px 20px rgba(30,75,138,.15); transform:translateY(-3px); }
.home-year-card .hyc-year { font-size:2rem; font-weight:800; color:var(--p); }
.home-year-card .hyc-count { font-size:.78rem; color:#888; margin-top:4px; }
.home-year-card .hyc-bar { height:4px; border-radius:4px; background:linear-gradient(90deg,var(--p),#4facfe); margin-top:10px; }
</style>
</head>
<body class="hold-transition layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">
<section class="content-header">
    <div class="container-fluid">
        <div class="page-banner">
            <div>
                <h4><i class="fas fa-archive ml-2"></i><?= __('archive_title') ?> <?= langSwitcher() ?></h4>
                <small style="opacity:.75"><?= getLang()==='ar'?'انقر على السنة لعرض الوثائق':'Click a year to browse documents' ?></small>
            </div>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                <li class="breadcrumb-item active"><?= __('archive_title') ?></li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid">

<!-- KPI -->
<div class="kpi-row">
    <div class="kpi" style="background:linear-gradient(135deg,#667eea,#764ba2)">
        <div class="kpi-icon"><i class="fas fa-file-alt"></i></div>
        <div><div class="kpi-val"><?= $totalDocs ?></div><div class="kpi-lbl"><?= __('archive_stats_total') ?></div></div>
    </div>
    <div class="kpi" style="background:linear-gradient(135deg,#f093fb,#f5576c)">
        <div class="kpi-icon"><i class="fas fa-signature"></i></div>
        <div><div class="kpi-val"><?= $totalSigned ?></div><div class="kpi-lbl"><?= __('archive_stats_signed') ?></div></div>
    </div>
    <div class="kpi" style="background:linear-gradient(135deg,#43e97b,#38f9d7)">
        <div class="kpi-icon"><i class="fas fa-clock"></i></div>
        <div><div class="kpi-val"><?= $totalPending ?></div><div class="kpi-lbl"><?= __('archive_stats_pending') ?></div></div>
    </div>
    <div class="kpi" style="background:linear-gradient(135deg,#4facfe,#00f2fe)">
        <div class="kpi-icon"><i class="fas fa-calendar-alt"></i></div>
        <div><div class="kpi-val"><?= count($years) ?></div><div class="kpi-lbl"><?= __('archive_stats_years') ?></div></div>
    </div>
</div>

<!-- تخطيط رئيسي -->
<div class="layout">

    <!-- شريط جانبي -->
    <div class="sidebar">
        <div class="sb-sec">
            <div class="sb-head"><i class="fas fa-calendar-alt"></i><?= getLang()==='ar'?'السنوات':'Years' ?></div>
            <div class="year-list">
                <?php foreach ($tree as $yr => $depts): ?>
                <div class="year-item" id="yi-<?= $yr ?>" onclick="navYear('<?= $yr ?>')">
                    <span><i class="fas fa-folder ml-2" style="color:var(--gold)"></i><?= $yr ?></span>
                    <span class="yi-count"><?= $yearCounts[$yr] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sb-sec">
            <div class="sb-head"><i class="fas fa-building"></i><?= getLang()==='ar'?'الأقسام':'Departments' ?></div>
            <ul class="sb-link-list">
                <?php foreach (array_keys($deptCounts) as $d): ?>
                <li><a href="#" onclick="navDeptAll('<?= htmlspecialchars(addslashes($d)) ?>');return false">
                    <span><i class="fas fa-folder-open ml-2" style="color:var(--gold)"></i><?= htmlspecialchars($d) ?></span>
                    <span class="badge badge-primary badge-pill"><?= $deptCounts[$d] ?></span>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="sb-sec">
            <div class="sb-head"><i class="fas fa-tags"></i><?= getLang()==='ar'?'أنواع الوثائق':'Doc Types' ?></div>
            <ul class="sb-link-list">
                <?php foreach ($docTypes as $t): ?>
                <li><a href="#" onclick="navType('<?= htmlspecialchars(addslashes($t['name'])) ?>');return false">
                    <span><i class="fas fa-file-alt ml-2 text-info"></i><?= htmlspecialchars($t['name']) ?></span>
                    <i class="fas fa-chevron-<?= isRtl()?'left':'right' ?>" style="font-size:.65rem;color:#ccc"></i>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- منطقة المحتوى -->
    <div class="content-area">

        <!-- شريط المسار -->
        <div class="breadpath" id="breadpath">
            <span class="bp-home" onclick="navHome()"><i class="fas fa-home ml-1"></i><?= getLang()==='ar'?'الأرشيف':'Archive' ?></span>
            <span class="bp-sep" id="sep1" style="display:none"><i class="fas fa-chevron-<?= isRtl()?'left':'right' ?>"></i></span>
            <span class="bp-item" id="bp-year" style="display:none"></span>
            <span class="bp-sep" id="sep2" style="display:none"><i class="fas fa-chevron-<?= isRtl()?'left':'right' ?>"></i></span>
            <span class="bp-item" id="bp-dept" style="display:none"></span>
            <span class="bp-sep" id="sep3" style="display:none"><i class="fas fa-chevron-<?= isRtl()?'left':'right' ?>"></i></span>
            <span class="bp-item" id="bp-type" style="display:none"></span>
        </div>

        <!-- المنزل: شبكة السنوات -->
        <div id="view-home" class="panel">
            <div class="panel-head">
                <h6><i class="fas fa-archive"></i><?= getLang()==='ar'?'كل السنوات':'All Years' ?></h6>
                <span class="ph-count"><?= count($years) ?> <?= getLang()==='ar'?'سنة':'years' ?></span>
            </div>
            <div class="panel-body">
                <?php if (empty($years)): ?>
                <div class="empty-state"><i class="fas fa-folder-open"></i><p><?= getLang()==='ar'?'لا توجد وثائق مؤرشفة بعد':'No archived documents yet' ?></p></div>
                <?php else: ?>
                <div class="home-grid">
                    <?php foreach ($tree as $yr => $depts):
                        $cnt = $yearCounts[$yr];
                        $w = $totalDocs > 0 ? round(($cnt/$totalDocs)*100) : 0;
                    ?>
                    <div class="home-year-card" onclick="navYear('<?= $yr ?>')">
                        <div class="hyc-year"><?= $yr ?></div>
                        <div class="hyc-count"><?= $cnt ?> <?= getLang()==='ar'?'وثيقة':'docs' ?></div>
                        <div class="hyc-bar" style="width:<?= $w ?>%"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- عرض السنة: أقسام -->
        <div id="view-year" style="display:none" class="panel">
            <div class="panel-head">
                <h6 id="vy-title"><i class="fas fa-folder-open"></i></h6>
                <span class="ph-count" id="vy-count"></span>
            </div>
            <div class="panel-body">
                <div class="folder-grid" id="vy-grid"></div>
            </div>
        </div>

        <!-- عرض القسم: أنواع -->
        <div id="view-dept" style="display:none" class="panel">
            <div class="panel-head">
                <h6 id="vd-title"><i class="fas fa-folder-open" style="color:var(--gold)"></i></h6>
                <span class="ph-count" id="vd-count"></span>
            </div>
            <div class="panel-body">
                <div class="folder-grid" id="vd-grid"></div>
            </div>
        </div>

        <!-- عرض الوثائق: قائمة -->
        <div id="view-docs" style="display:none" class="panel">
            <div class="panel-head">
                <h6 id="vdoc-title"><i class="fas fa-file-alt"></i></h6>
                <span class="ph-count" id="vdoc-count"></span>
            </div>
            <div class="panel-body">
                <div class="search-bar">
                    <input type="text" id="docSearch" placeholder="<?= getLang()==='ar'?'بحث في الوثائق...':'Search documents...' ?>" oninput="filterDocs(this.value)">
                </div>
                <div class="doc-list" id="vdoc-list"></div>
            </div>
        </div>

        <!-- أحدث الوثائق (يظهر في المنزل فقط) -->
        <div id="view-recent" class="panel">
            <div class="panel-head">
                <h6><i class="fas fa-history"></i><?= __('archive_recent') ?></h6>
            </div>
            <div class="panel-body">
                <div class="doc-list">
                    <?php foreach ($recentDocs as $doc):
                        [$fi,$fc] = fileIcon($doc['file_format']??'');
                        [$sc,$sl] = statusBadge($doc['status']??'draft');
                    ?>
                    <a href="../forms/view-document.php?id=<?= $doc['id'] ?>" class="doc-row">
                        <div class="dr-icon" style="background:<?= $fc ?>"><i class="fas <?= $fi ?>"></i></div>
                        <div class="dr-body">
                            <div class="dr-num"><?= htmlspecialchars($doc['doc_number']??'') ?></div>
                            <div class="dr-title"><?= htmlspecialchars($doc['title']) ?></div>
                            <div class="dr-meta"><?= date('Y/m/d',strtotime($doc['created_at'])) ?> &bull; <?= htmlspecialchars($doc['type_name']??'') ?></div>
                        </div>
                        <span class="dr-status" style="background:<?= $sc ?>22;color:<?= $sc ?>"><?= $sl ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div><!-- /content-area -->
</div><!-- /layout -->
</div>
</section>
</div>

<!-- بيانات الأرشيف (JSON) -->
<script>
var AR = <?= json_encode($tree, JSON_UNESCAPED_UNICODE) ?>;
var isRtl = <?= isRtl()?'true':'false' ?>;
var LBL = {
    docs:   '<?= getLang()==="ar"?"وثيقة":"docs" ?>',
    folders:'<?= getLang()==="ar"?"أقسام":"depts" ?>',
    types:  '<?= getLang()==="ar"?"أنواع":"types" ?>',
    noDocs: '<?= getLang()==="ar"?"لا توجد وثائق":"No documents" ?>',
    draft:  '<?= __('status_draft') ?>',
    approved:'<?= __('status_approved') ?>',
    archived:'<?= __('status_archived') ?>',
    cancelled:'<?= getLang()==="ar"?"ملغاة":"Cancelled" ?>'
};

var currentYear = null, currentDept = null, currentType = null;
var currentDocs = [];

// ── أيقونات الملفات ──────────────────────────────────────────────
function fmtIcon(fmt) {
    var m={PDF:['fa-file-pdf','#e74c3c'],DOC:['fa-file-word','#2980b9'],DOCX:['fa-file-word','#2980b9'],XLS:['fa-file-excel','#27ae60'],XLSX:['fa-file-excel','#27ae60'],JPG:['fa-file-image','#8e44ad'],JPEG:['fa-file-image','#8e44ad'],PNG:['fa-file-image','#8e44ad']};
    return m[(fmt||'').toUpperCase()] || ['fa-file-alt','#7f8c8d'];
}
function statusInfo(s) {
    var m={draft:['#6c757d',LBL.draft],approved:['#27ae60',LBL.approved],archived:['#1565c0',LBL.archived],cancelled:['#c62828',LBL.cancelled]};
    return m[s]||['#aaa',s];
}

// ── تحديث شريط المسار ────────────────────────────────────────────
function updateBreadpath() {
    var sep1=document.getElementById('sep1'),sep2=document.getElementById('sep2'),sep3=document.getElementById('sep3');
    var bpY=document.getElementById('bp-year'),bpD=document.getElementById('bp-dept'),bpT=document.getElementById('bp-type');
    sep1.style.display=sep2.style.display=sep3.style.display='none';
    bpY.style.display=bpD.style.display=bpT.style.display='none';
    if (currentYear) {
        sep1.style.display=''; bpY.style.display='';
        bpY.textContent='📅 '+currentYear;
        bpY.className='bp-item'+(currentDept?'':' active');
        bpY.onclick=currentDept?function(){navYear(currentYear);}:null;
    }
    if (currentDept) {
        sep2.style.display=''; bpD.style.display='';
        bpD.textContent='📂 '+currentDept;
        bpD.className='bp-item'+(currentType?'':' active');
        bpD.onclick=currentType?function(){navDept(currentYear,currentDept);}:null;
    }
    if (currentType) {
        sep3.style.display=''; bpT.style.display='';
        bpT.textContent='📄 '+currentType;
        bpT.className='bp-item active';
    }
}

// ── تحديث الشريط الجانبي ─────────────────────────────────────────
function updateSidebar() {
    document.querySelectorAll('.year-item').forEach(function(el){ el.classList.remove('active'); });
    if (currentYear) {
        var yi=document.getElementById('yi-'+currentYear);
        if(yi) yi.classList.add('active');
    }
}

// ── عرض المشاهدات ────────────────────────────────────────────────
function showView(name) {
    ['home','year','dept','docs'].forEach(function(v){ document.getElementById('view-'+v).style.display='none'; });
    document.getElementById('view-recent').style.display = (name==='home')?'':'none';
    document.getElementById('view-'+name).style.display = '';
}

// ── المنزل ────────────────────────────────────────────────────────
function navHome() {
    currentYear=currentDept=currentType=null;
    updateBreadpath(); updateSidebar();
    showView('home');
}

// ── السنة ─────────────────────────────────────────────────────────
function navYear(yr) {
    currentYear=yr; currentDept=null; currentType=null;
    updateBreadpath(); updateSidebar();
    var depts=AR[yr]||{};
    var keys=Object.keys(depts);
    var grid=document.getElementById('vy-grid');
    document.getElementById('vy-title').innerHTML='<i class="fas fa-folder-open" style="color:var(--gold)"></i> '+yr;
    document.getElementById('vy-count').textContent=keys.length+' '+LBL.folders;
    grid.innerHTML='';
    if(!keys.length){
        grid.innerHTML='<div class="empty-state" style="grid-column:1/-1"><i class="fas fa-folder-open"></i><p>'+LBL.noDocs+'</p></div>';
    } else {
        keys.forEach(function(dept){
            var cnt=Object.values(depts[dept]).reduce(function(a,docs){return a+docs.length;},0);
            var card=document.createElement('div');
            card.className='folder-card';
            card.innerHTML='<div class="fc-emoji">📂</div><div class="fc-name">'+esc(dept)+'</div><div class="fc-cnt">'+cnt+' '+LBL.docs+'</div>';
            card.onclick=function(){navDept(yr,dept);};
            grid.appendChild(card);
        });
    }
    showView('year');
}

// ── القسم ─────────────────────────────────────────────────────────
function navDept(yr, dept) {
    currentYear=yr; currentDept=dept; currentType=null;
    updateBreadpath(); updateSidebar();
    var types=(AR[yr]||{})[dept]||{};
    var keys=Object.keys(types);
    var grid=document.getElementById('vd-grid');
    var typeEmoji={'PDF':'📕','DOC':'📘','DOCX':'📘','XLS':'📗','XLSX':'📗','JPG':'🖼️','PNG':'🖼️'};
    document.getElementById('vd-title').innerHTML='<i class="fas fa-folder-open" style="color:var(--gold)"></i> '+esc(dept);
    document.getElementById('vd-count').textContent=keys.length+' '+LBL.types;
    grid.innerHTML='';
    keys.forEach(function(type){
        var cnt=types[type].length;
        var emoji=typeEmoji[type.toUpperCase()]||'📄';
        var card=document.createElement('div');
        card.className='folder-card';
        card.innerHTML='<div class="fc-emoji">'+emoji+'</div><div class="fc-name">'+esc(type)+'</div><div class="fc-cnt">'+cnt+' '+LBL.docs+'</div>';
        card.onclick=function(){navType2(yr,dept,type);};
        grid.appendChild(card);
    });
    showView('dept');
}

// ── النوع ─────────────────────────────────────────────────────────
function navType2(yr, dept, type) {
    currentYear=yr; currentDept=dept; currentType=type;
    updateBreadpath(); updateSidebar();
    var docs=((AR[yr]||{})[dept]||{})[type]||[];
    currentDocs=docs;
    renderDocs(docs, esc(type)+' — '+esc(dept)+' ('+yr+')');
    showView('docs');
}

// ── تصفح حسب القسم (من الشريط الجانبي) ──────────────────────────
function navDeptAll(dept) {
    var collected=[];
    Object.keys(AR).forEach(function(yr){
        if(AR[yr][dept]){
            Object.values(AR[yr][dept]).forEach(function(docs){ collected=collected.concat(docs); });
        }
    });
    currentYear=null; currentDept=dept; currentType=null;
    updateBreadpath(); updateSidebar();
    currentDocs=collected;
    renderDocs(collected, '📂 '+esc(dept));
    showView('docs');
}

// ── تصفح حسب النوع (من الشريط الجانبي) ──────────────────────────
function navType(type) {
    var collected=[];
    Object.keys(AR).forEach(function(yr){
        Object.keys(AR[yr]).forEach(function(dept){
            if(AR[yr][dept][type]) collected=collected.concat(AR[yr][dept][type]);
        });
    });
    currentYear=null; currentDept=null; currentType=type;
    updateBreadpath(); updateSidebar();
    currentDocs=collected;
    renderDocs(collected,'📄 '+esc(type));
    showView('docs');
}

// ── رسم الوثائق ──────────────────────────────────────────────────
function renderDocs(docs, title) {
    var list=document.getElementById('vdoc-list');
    document.getElementById('vdoc-title').innerHTML=title;
    document.getElementById('vdoc-count').textContent=docs.length+' '+LBL.docs;
    list.innerHTML='';
    if(!docs.length){
        list.innerHTML='<div class="empty-state"><i class="fas fa-file-excel"></i><p>'+LBL.noDocs+'</p></div>';
        return;
    }
    docs.forEach(function(doc){
        var fi=fmtIcon(doc.file_format); var si=statusInfo(doc.status);
        var dt=doc.created_at?doc.created_at.substr(0,10):'';
        var row=document.createElement('a');
        row.href='../forms/view-document.php?id='+doc.id;
        row.className='doc-row';
        row.innerHTML=
            '<div class="dr-icon" style="background:'+fi[1]+'"><i class="fas '+fi[0]+'"></i></div>'+
            '<div class="dr-body">'+
              '<div class="dr-num">'+esc(doc.doc_number||'')+'</div>'+
              '<div class="dr-title">'+esc(doc.title)+'</div>'+
              '<div class="dr-meta">'+dt+' &bull; '+esc(doc.type_name||'')+'</div>'+
            '</div>'+
            '<span class="dr-status" style="background:'+si[0]+'22;color:'+si[0]+'">'+si[1]+'</span>';
        list.appendChild(row);
    });
    document.getElementById('docSearch').value='';
}

// ── بحث ──────────────────────────────────────────────────────────
function filterDocs(q) {
    q=q.toLowerCase();
    var filtered=q?currentDocs.filter(function(d){
        return (d.title||'').toLowerCase().includes(q)||(d.doc_number||'').toLowerCase().includes(q)||(d.department||'').toLowerCase().includes(q);
    }):currentDocs;
    renderDocs(filtered, document.getElementById('vdoc-title').innerHTML);
}

function esc(s){ var d=document.createElement('div');d.textContent=s;return d.innerHTML; }
</script>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
</body>
</html>
