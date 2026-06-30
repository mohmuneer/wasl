<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
$page_path = "pages/tables/show-kb.php";
if (!$uid) die("خطأ: يجب تسجيل الدخول أولاً");

// ── تطبيق الهجرة تلقائياً ────────────────────────────────────────
try {
    if (!$pdo->query("SHOW TABLES LIKE 'kb_articles'")->fetchColumn()) {
        $sql = file_get_contents(__DIR__ . '/../../../../wasl_kb_migration.sql');
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql)), fn($s) => strlen($s) > 5) as $q) {
            try { $pdo->exec($q); } catch (PDOException $e) {}
        }
    }
} catch (PDOException $e) {}

// ── صلاحيات ──────────────────────────────────────────────────────
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?: 0;
$can_add = $can_edit = $can_delete = 0;
if ($current_page_id > 0) {
    $p = $pdo->prepare("SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $p->execute([$uid, $current_page_id]);
    $p = $p->fetch(PDO::FETCH_ASSOC) ?: [];
    $can_add    = $p['can_add']    ?? 0;
    $can_edit   = $p['can_edit']   ?? 0;
    $can_delete = $p['can_delete'] ?? 0;
}

// ── حذف تصنيف ────────────────────────────────────────────────────
if (isset($_GET['del_cat']) && $can_delete) {
    $cid = (int)$_GET['del_cat'];
    $used = (int)$pdo->prepare("SELECT COUNT(*) FROM " . TBL_KB_ARTICLES . " WHERE category_id=?")->execute([$cid])
            ? $pdo->query("SELECT COUNT(*) FROM " . TBL_KB_ARTICLES . " WHERE category_id=$cid")->fetchColumn() : 0;
    if ($used == 0) {
        $pdo->prepare("DELETE FROM " . TBL_KB_CATEGORIES . " WHERE id=?")->execute([$cid]);
        header("Location: show-kb.php?cat_deleted=1"); exit;
    }
    header("Location: show-kb.php?cat_error=has_articles"); exit;
}

// ── حذف مقالة ────────────────────────────────────────────────────
if (isset($_GET['del_art']) && $can_delete) {
    $aid = (int)$_GET['del_art'];
    $pdo->prepare("DELETE FROM " . TBL_KB_FEEDBACK . " WHERE article_id=?")->execute([$aid]);
    $pdo->prepare("DELETE FROM " . TBL_KB_ARTICLES  . " WHERE id=?")->execute([$aid]);
    header("Location: show-kb.php?deleted=1"); exit;
}

// ── حفظ / تعديل تصنيف (AJAX) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cat'])) {
    header('Content-Type: application/json');
    if (!$can_add && !$can_edit) { echo json_encode(['ok'=>false,'msg'=>'لا صلاحية']); exit; }
    $cid   = (int)($_POST['cat_id'] ?? 0);
    $name  = trim($_POST['cat_name'] ?? '');
    $desc  = trim($_POST['cat_desc'] ?? '');
    $icon  = trim($_POST['cat_icon'] ?? 'fas fa-folder');
    $color = trim($_POST['cat_color'] ?? '#1a5276');
    $sort  = (int)($_POST['cat_sort'] ?? 0);
    if (empty($name)) { echo json_encode(['ok'=>false,'msg'=>'الاسم مطلوب']); exit; }
    if ($cid > 0) {
        $pdo->prepare("UPDATE " . TBL_KB_CATEGORIES . " SET name=?,description=?,icon=?,color=?,sort_order=? WHERE id=?")
            ->execute([$name,$desc,$icon,$color,$sort,$cid]);
    } else {
        $pdo->prepare("INSERT INTO " . TBL_KB_CATEGORIES . " (name,description,icon,color,sort_order) VALUES(?,?,?,?,?)")
            ->execute([$name,$desc,$icon,$color,$sort]);
        $cid = (int)$pdo->lastInsertId();
    }
    echo json_encode(['ok'=>true,'id'=>$cid]);
    exit;
}

// ── البيانات ──────────────────────────────────────────────────────
$categories = $pdo->query("SELECT * FROM " . TBL_KB_CATEGORIES . " WHERE is_active=1 ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);

$articles = $pdo->query("
    SELECT a.id, a.title, a.status, a.featured, a.views, a.helpful_yes, a.helpful_no,
           a.tags, a.created_at, a.updated_at,
           c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS author_name
    FROM " . TBL_KB_ARTICLES . " a
    LEFT JOIN " . TBL_KB_CATEGORIES . " c ON a.category_id = c.id
    LEFT JOIN sys_users u ON a.created_by = u.id
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total'     => count($articles),
    'published' => count(array_filter($articles, fn($a) => $a['status'] === 'published')),
    'draft'     => count(array_filter($articles, fn($a) => $a['status'] === 'draft')),
    'views'     => array_sum(array_column($articles, 'views')),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>قاعدة المعرفة</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

.kb-stat{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:14px 18px;display:flex;align-items:center;gap:12px;border:1px solid #f0f2f7}
.kb-stat .si{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;flex-shrink:0}
.kb-stat .sv{font-size:1.5rem;font-weight:800;line-height:1}
.kb-stat .sl{font-size:.72rem;color:#888;margin-top:2px}

.kb-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;border:1px solid #f0f2f7}
.kb-card-head{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:1px solid #f0f2f7}

#kbTable thead th{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))!important;color:#fff!important;border:none!important;white-space:nowrap;vertical-align:middle;font-size:.76rem;font-weight:700;padding:10px 8px;text-align:center}
#kbTable tbody td{vertical-align:middle;text-align:center;font-size:.78rem;padding:9px 7px;border-top:1px solid #f0f4f8!important;border-left:none!important;border-right:none!important;border-bottom:none!important}
#kbTable tbody tr:hover{background:#f8fafc}

.badge-pub{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.badge-dft{background:#f1f5f9;color:#64748b;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.badge-feat{background:#fef3c7;color:#d97706;padding:3px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.cat-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:600;color:#fff}
.helpful-bar{display:flex;align-items:center;gap:4px;justify-content:center;font-size:.72rem}
.h-yes{color:#27ae60;font-weight:700}
.h-no{color:#e74c3c;font-weight:700}

.btn-act{width:28px;height:28px;padding:0;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;transition:.15s;border:none;cursor:pointer}

.cat-list-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;margin-bottom:8px}
.cat-ic{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;color:#fff;flex-shrink:0}

.modal-content{border-radius:14px;border:none}
.modal-head-grad{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:14px 14px 0 0;padding:16px 20px}
.form-control{border-radius:8px;border:1.5px solid #e2e8f0;font-size:.85rem}
.form-control:focus{border-color:var(--crm-primary,#1a5276);box-shadow:0 0 0 3px rgba(26,82,118,.08)}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include __DIR__ . '/../../main-header.php'; ?>
<?php include __DIR__ . '/../../main-sidebar.php'; ?>

<div class="content-wrapper">

<section class="content-header">
    <div class="container-fluid">
        <div class="uni-header">
            <div>
                <h4><i class="fas fa-book-open ml-2"></i>قاعدة المعرفة</h4>
                <small>إدارة المقالات والأدلة والأسئلة الشائعة</small>
            </div>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                <li class="breadcrumb-item active">قاعدة المعرفة</li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid" style="padding-bottom:20px">

<!-- ── إحصاءات ── -->
<div class="row mb-3" style="gap:0">
    <div class="col-6 col-md-3 mb-3">
        <div class="kb-stat"><div class="si" style="background:#2980b9"><i class="fas fa-book"></i></div><div><div class="sv"><?= $stats['total'] ?></div><div class="sl">إجمالي المقالات</div></div></div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="kb-stat"><div class="si" style="background:#27ae60"><i class="fas fa-check-circle"></i></div><div><div class="sv"><?= $stats['published'] ?></div><div class="sl">منشور</div></div></div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="kb-stat"><div class="si" style="background:#f39c12"><i class="fas fa-edit"></i></div><div><div class="sv"><?= $stats['draft'] ?></div><div class="sl">مسودة</div></div></div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="kb-stat"><div class="si" style="background:#8e44ad"><i class="fas fa-eye"></i></div><div><div class="sv"><?= number_format($stats['views']) ?></div><div class="sl">إجمالي المشاهدات</div></div></div>
    </div>
</div>

<!-- ── الجدول ── -->
<div class="kb-card">
    <div class="kb-card-head">
        <div style="font-size:1rem;font-weight:700;color:#1a3a5c"><i class="fas fa-book-open ml-2" style="color:var(--crm-primary,#2980b9)"></i> قاعدة المعرفة</div>
        <div class="d-flex gap-2" style="gap:8px">
            <button class="btn btn-sm btn-outline-secondary" id="btnManageCats"><i class="fas fa-folder ml-1"></i> إدارة التصنيفات</button>
            <?php if ($can_add): ?>
            <a href="../forms/add-kb-article.php" class="btn btn-sm" style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:8px">
                <i class="fas fa-plus ml-1"></i> مقالة جديدة
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- فلاتر -->
    <div style="padding:12px 20px;border-bottom:1px solid #f0f2f7;display:flex;flex-wrap:wrap;gap:8px">
        <select id="fCat" class="form-control form-control-sm" style="width:160px">
            <option value="">كل التصنيفات</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="fStatus" class="form-control form-control-sm" style="width:130px">
            <option value="">كل الحالات</option>
            <option value="منشور">منشور</option>
            <option value="مسودة">مسودة</option>
        </select>
        <button id="fReset" class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo ml-1"></i> إعادة</button>
    </div>

    <div style="padding:16px">
        <table id="kbTable" class="table table-bordered table-hover w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان</th>
                    <th>التصنيف</th>
                    <th>الحالة</th>
                    <th>المشاهدات</th>
                    <th>التقييم</th>
                    <th>الكاتب</th>
                    <th>التاريخ</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($articles as $i => $a):
                $catColor = $a['cat_color'] ?? '#888';
                $catName  = $a['cat_name'] ?? '—';
                $catIcon  = $a['cat_icon'] ?? 'fas fa-folder';
                $total    = $a['helpful_yes'] + $a['helpful_no'];
                $pct      = $total > 0 ? round($a['helpful_yes'] / $total * 100) : null;
            ?>
            <tr data-cat="<?= htmlspecialchars($catName) ?>" data-status="<?= $a['status']==='published'?'منشور':'مسودة' ?>">
                <td><?= $i+1 ?></td>
                <td style="text-align:right;max-width:220px">
                    <a href="../forms/view-kb-article.php?id=<?= $a['id'] ?>" style="color:#1a3a5c;font-weight:600;font-size:.8rem">
                        <?= htmlspecialchars($a['title']) ?>
                    </a>
                    <?php if ($a['featured']): ?><span class="badge-feat ml-1"><i class="fas fa-star"></i> مميز</span><?php endif; ?>
                    <?php if (!empty($a['tags'])): ?>
                    <div style="margin-top:3px">
                        <?php foreach (array_slice(explode(',', $a['tags']), 0, 3) as $tag): ?>
                        <span style="background:#e8f4fd;color:#2980b9;padding:1px 6px;border-radius:10px;font-size:.65rem"><?= htmlspecialchars(trim($tag)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="cat-chip" style="background:<?= htmlspecialchars($catColor) ?>">
                        <i class="<?= htmlspecialchars($catIcon) ?>"></i>
                        <?= htmlspecialchars($catName) ?>
                    </span>
                </td>
                <td>
                    <?php if ($a['status'] === 'published'): ?>
                    <span class="badge-pub"><i class="fas fa-check-circle ml-1"></i>منشور</span>
                    <?php else: ?>
                    <span class="badge-dft"><i class="fas fa-pencil-alt ml-1"></i>مسودة</span>
                    <?php endif; ?>
                </td>
                <td><i class="fas fa-eye" style="color:#8e44ad;font-size:.75rem;margin-left:3px"></i><?= number_format($a['views']) ?></td>
                <td>
                    <?php if ($pct !== null): ?>
                    <div class="helpful-bar">
                        <span class="h-yes"><i class="fas fa-thumbs-up"></i> <?= $a['helpful_yes'] ?></span>
                        <span style="color:#ccc">/</span>
                        <span class="h-no"><i class="fas fa-thumbs-down"></i> <?= $a['helpful_no'] ?></span>
                        <span style="color:#64748b;font-size:.68rem">(<?= $pct ?>%)</span>
                    </div>
                    <?php else: ?><span style="color:#ccc;font-size:.75rem">—</span><?php endif; ?>
                </td>
                <td style="font-size:.75rem"><?= htmlspecialchars($a['author_name'] ?? '—') ?></td>
                <td><small style="color:#64748b"><?= date('Y-m-d', strtotime($a['created_at'])) ?></small></td>
                <td>
                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:nowrap">
                        <a href="../forms/view-kb-article.php?id=<?= $a['id'] ?>" class="btn-act btn btn-info" title="عرض"><i class="fas fa-eye"></i></a>
                        <?php if ($can_edit): ?>
                        <a href="../forms/edit-kb-article.php?id=<?= $a['id'] ?>" class="btn-act btn btn-warning" title="تعديل"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                        <?php if ($can_delete): ?>
                        <button class="btn-act btn btn-danger del-art-btn" data-id="<?= $a['id'] ?>" data-title="<?= htmlspecialchars($a['title'], ENT_QUOTES) ?>" title="حذف"><i class="fas fa-trash"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div></section><!-- /container-fluid/content -->
</div><!-- /content-wrapper -->

<!-- ── مودال إدارة التصنيفات ── -->
<div class="modal fade" id="catsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-head-grad d-flex align-items-center justify-content-between">
                <span style="font-size:1.05rem;font-weight:700"><i class="fas fa-folder ml-2"></i>إدارة تصنيفات المعرفة</span>
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- قائمة التصنيفات -->
                    <div class="col-md-6">
                        <h6 style="font-weight:700;color:#1a3a5c;margin-bottom:12px">التصنيفات الحالية</h6>
                        <div id="catsList">
                        <?php foreach ($categories as $c): ?>
                        <div class="cat-list-item" id="cat-row-<?= $c['id'] ?>">
                            <div class="cat-ic" style="background:<?= htmlspecialchars($c['color']) ?>">
                                <i class="<?= htmlspecialchars($c['icon']) ?>"></i>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:.85rem;color:#1a3a5c"><?= htmlspecialchars($c['name']) ?></div>
                                <?php if (!empty($c['description'])): ?>
                                <div style="font-size:.72rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:4px">
                                <button class="btn-act btn btn-sm btn-warning edit-cat-btn"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= htmlspecialchars($c['name'],ENT_QUOTES) ?>"
                                    data-desc="<?= htmlspecialchars($c['description']??'',ENT_QUOTES) ?>"
                                    data-icon="<?= htmlspecialchars($c['icon'],ENT_QUOTES) ?>"
                                    data-color="<?= htmlspecialchars($c['color'],ENT_QUOTES) ?>"
                                    data-sort="<?= $c['sort_order'] ?>"
                                    title="تعديل"><i class="fas fa-edit"></i></button>
                                <a href="show-kb.php?del_cat=<?= $c['id'] ?>" class="btn-act btn btn-sm btn-danger" onclick="return confirm('حذف التصنيف؟')" title="حذف"><i class="fas fa-trash"></i></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- نموذج إضافة/تعديل -->
                    <div class="col-md-6">
                        <h6 style="font-weight:700;color:#1a3a5c;margin-bottom:12px" id="catFormTitle">إضافة تصنيف جديد</h6>
                        <input type="hidden" id="catId" value="0">
                        <div class="form-group mb-2">
                            <label class="ast-label" style="font-size:.8rem;font-weight:700;color:#475569">اسم التصنيف *</label>
                            <input type="text" id="catName" class="form-control" placeholder="مثال: الأسئلة الشائعة">
                        </div>
                        <div class="form-group mb-2">
                            <label class="ast-label" style="font-size:.8rem;font-weight:700;color:#475569">الوصف</label>
                            <input type="text" id="catDesc" class="form-control" placeholder="وصف مختصر للتصنيف">
                        </div>
                        <div class="row mb-2">
                            <div class="col-6">
                                <label class="ast-label" style="font-size:.8rem;font-weight:700;color:#475569">أيقونة FontAwesome</label>
                                <input type="text" id="catIcon" class="form-control" value="fas fa-folder" placeholder="fas fa-folder">
                            </div>
                            <div class="col-6">
                                <label class="ast-label" style="font-size:.8rem;font-weight:700;color:#475569">اللون</label>
                                <input type="color" id="catColor" class="form-control" value="#1a5276" style="height:38px;padding:3px">
                            </div>
                        </div>
                        <div class="form-group mb-3">
                            <label class="ast-label" style="font-size:.8rem;font-weight:700;color:#475569">الترتيب</label>
                            <input type="number" id="catSort" class="form-control" value="0" min="0">
                        </div>
                        <div class="d-flex gap-2" style="gap:8px">
                            <button id="saveCatBtn" class="btn btn-sm btn-primary flex-fill">
                                <i class="fas fa-save ml-1"></i> حفظ
                            </button>
                            <button id="resetCatBtn" class="btn btn-sm btn-secondary">
                                <i class="fas fa-times ml-1"></i> إلغاء
                            </button>
                        </div>
                        <div id="catMsg" class="mt-2" style="display:none;font-size:.8rem;padding:6px 10px;border-radius:6px"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../main-footer.php'; ?>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){

    // ── DataTable ────────────────────────────────────────────────
    var table = $('#kbTable').DataTable({
        scrollX: false,
        pageLength: 25,
        order: [[7,'desc']],
        language: {
            search: 'بحث:',
            lengthMenu: 'عرض _MENU_ مقالة',
            info: 'عرض _START_ إلى _END_ من أصل _TOTAL_',
            paginate:{first:'الأول',last:'الأخير',next:'التالي',previous:'السابق'},
            emptyTable: '<i class="fas fa-book-open fa-2x" style="color:#e2e8f0"></i><br>لا توجد مقالات بعد'
        },
        dom: '<"d-flex justify-content-between align-items-center mb-2"lBf>rtip',
        buttons: [
            { extend:'excelHtml5', text:'<i class="fas fa-file-excel ml-1"></i>Excel', className:'btn btn-sm btn-success' },
            { extend:'colvis', text:'<i class="fas fa-columns ml-1"></i>أعمدة', className:'btn btn-sm btn-outline-secondary' }
        ],
        columnDefs: [
            { orderable:false, targets:[5,8] },
            { searchable:false, targets:[0,4,5,8] }
        ]
    });

    // ── فلتر مخصص ───────────────────────────────────────────────
    $.fn.dataTable.ext.search.push(function(settings, data, idx){
        var cat    = $('#fCat').val();
        var status = $('#fStatus').val();
        var row    = table.row(idx).node();
        if (cat    && $(row).data('cat')    !== cat)    return false;
        if (status && $(row).data('status') !== status) return false;
        return true;
    });
    $('#fCat,#fStatus').on('change', function(){ table.draw(); });
    $('#fReset').on('click', function(){
        $('#fCat,#fStatus').val('');
        table.search('').draw();
    });

    // ── حذف مقالة ───────────────────────────────────────────────
    $(document).on('click', '.del-art-btn', function(){
        var id = $(this).data('id'), title = $(this).data('title');
        Swal.fire({
            title: 'حذف المقالة؟',
            html: 'سيتم حذف <strong>"'+title+'"</strong> نهائياً مع تقييماتها.',
            icon:'warning', showCancelButton:true,
            confirmButtonColor:'#d33', cancelButtonColor:'#6c757d',
            confirmButtonText:'نعم، احذف', cancelButtonText:'إلغاء'
        }).then(r=>{ if(r.isConfirmed) window.location.href='show-kb.php?del_art='+id; });
    });

    // ── فتح مودال التصنيفات ──────────────────────────────────────
    $('#btnManageCats').on('click', function(){ $('#catsModal').modal('show'); });

    // ── تحرير تصنيف ─────────────────────────────────────────────
    $(document).on('click', '.edit-cat-btn', function(){
        var d = $(this).data();
        $('#catId').val(d.id);
        $('#catName').val(d.name);
        $('#catDesc').val(d.desc);
        $('#catIcon').val(d.icon);
        $('#catColor').val(d.color);
        $('#catSort').val(d.sort);
        $('#catFormTitle').text('تعديل التصنيف');
    });

    // ── إعادة تعيين نموذج التصنيف ────────────────────────────────
    $('#resetCatBtn').on('click', function(){
        $('#catId').val('0');
        $('#catName,#catDesc').val('');
        $('#catIcon').val('fas fa-folder');
        $('#catColor').val('#1a5276');
        $('#catSort').val('0');
        $('#catFormTitle').text('إضافة تصنيف جديد');
        $('#catMsg').hide();
    });

    // ── حفظ التصنيف ─────────────────────────────────────────────
    $('#saveCatBtn').on('click', function(){
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin ml-1"></i> جاري الحفظ...');
        $.post('show-kb.php', {
            save_cat:1,
            cat_id:   $('#catId').val(),
            cat_name: $('#catName').val(),
            cat_desc: $('#catDesc').val(),
            cat_icon: $('#catIcon').val(),
            cat_color:$('#catColor').val(),
            cat_sort: $('#catSort').val()
        }, function(res){
            if (res.ok) {
                $('#catMsg').css({background:'#d1fae5',color:'#065f46',display:'block'}).text('تم الحفظ بنجاح');
                setTimeout(()=>location.reload(), 800);
            } else {
                $('#catMsg').css({background:'#fee2e2',color:'#dc2626',display:'block'}).text(res.msg);
            }
        }, 'json').always(()=> btn.prop('disabled',false).html('<i class="fas fa-save ml-1"></i> حفظ'));
    });

    // ── رسائل الجلسة ────────────────────────────────────────────
    <?php if (isset($_GET['deleted'])): ?>
    Swal.fire({icon:'success',title:'تم الحذف',timer:2000,showConfirmButton:false});
    <?php elseif (isset($_GET['cat_deleted'])): ?>
    Swal.fire({icon:'success',title:'تم حذف التصنيف',timer:2000,showConfirmButton:false});
    <?php elseif (isset($_GET['cat_error'])): ?>
    Swal.fire({icon:'warning',title:'لا يمكن الحذف',text:'التصنيف يحتوي على مقالات، احذف المقالات أولاً'});
    <?php endif; ?>
    var m = sessionStorage.getItem('app_message');
    if(m){var d=JSON.parse(m);Swal.fire({icon:d.icon,title:d.title,text:d.text,timer:3000,showConfirmButton:false});sessionStorage.removeItem('app_message');}
});
</script>
</body>
</html>
