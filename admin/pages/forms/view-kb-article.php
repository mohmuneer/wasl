<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) die("يجب تسجيل الدخول");

$art_id = (int)($_GET['id'] ?? 0);
if ($art_id <= 0) { header("Location: ../tables/show-kb.php"); exit; }

// ── زيادة عداد المشاهدات ─────────────────────────────────────────
$pdo->prepare("UPDATE " . TBL_KB_ARTICLES . " SET views = views + 1 WHERE id=?")->execute([$art_id]);

// ── جلب المقالة ──────────────────────────────────────────────────
$art = $pdo->prepare("
    SELECT a.*, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color,
           u.full_name AS author_name, u.file_path AS author_img
    FROM " . TBL_KB_ARTICLES . " a
    LEFT JOIN " . TBL_KB_CATEGORIES . " c ON a.category_id = c.id
    LEFT JOIN sys_users u ON a.created_by = u.id
    WHERE a.id = ?
");
$art->execute([$art_id]);
$art = $art->fetch(PDO::FETCH_ASSOC);
if (!$art) { header("Location: ../tables/show-kb.php"); exit; }

// ── تسجيل تقييم المستخدم (AJAX) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vote'])) {
    header('Content-Type: application/json');
    $vote = $_POST['vote'] === '1' ? 1 : 0;
    try {
        // REPLACE INTO يُحدّث التصويت القديم إن وُجد
        $pdo->prepare("INSERT INTO " . TBL_KB_FEEDBACK . " (article_id,user_id,is_helpful) VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE is_helpful=VALUES(is_helpful)")->execute([$art_id,$uid,$vote]);
        // إعادة حساب المجاميع
        $counts = $pdo->prepare("SELECT
            SUM(is_helpful=1) AS yes_count,
            SUM(is_helpful=0) AS no_count
            FROM " . TBL_KB_FEEDBACK . " WHERE article_id=?");
        $counts->execute([$art_id]);
        $c = $counts->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE " . TBL_KB_ARTICLES . " SET helpful_yes=?,helpful_no=? WHERE id=?")
            ->execute([(int)$c['yes_count'],(int)$c['no_count'],$art_id]);
        echo json_encode(['ok'=>true,'yes'=>(int)$c['yes_count'],'no'=>(int)$c['no_count']]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── تقييم المستخدم الحالي ─────────────────────────────────────────
$myVote = $pdo->prepare("SELECT is_helpful FROM " . TBL_KB_FEEDBACK . " WHERE article_id=? AND user_id=?");
$myVote->execute([$art_id, $uid]);
$myVote = $myVote->fetchColumn();

// ── مقالات ذات صلة (نفس التصنيف) ─────────────────────────────────
$related = $pdo->prepare("
    SELECT id, title, views, helpful_yes, helpful_no, created_at
    FROM " . TBL_KB_ARTICLES . "
    WHERE category_id=? AND id!=? AND status='published'
    ORDER BY views DESC LIMIT 5
");
$related->execute([$art['category_id'], $art_id]);
$related = $related->fetchAll(PDO::FETCH_ASSOC);

// ── صلاحيات التعديل ───────────────────────────────────────────────
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link='pages/tables/show-kb.php'");
$menuStmt->execute();
$mid = $menuStmt->fetchColumn() ?: 0;
$can_edit = $can_delete = 0;
if ($mid > 0) {
    $r = $pdo->prepare("SELECT can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $r->execute([$uid,$mid]);
    $r = $r->fetch(PDO::FETCH_ASSOC) ?: [];
    $can_edit   = $r['can_edit']   ?? 0;
    $can_delete = $r['can_delete'] ?? 0;
}

$total_votes = $art['helpful_yes'] + $art['helpful_no'];
$helpful_pct = $total_votes > 0 ? round($art['helpful_yes'] / $total_votes * 100) : null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($art['title']) ?> — قاعدة المعرفة</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ── تنسيق محتوى المقالة ── */
.kb-article-body{font-size:.95rem;line-height:1.85;color:#2d3748}
.kb-article-body h3,.kb-article-body h4{color:#1a3a5c;font-weight:700;margin-top:1.5rem;margin-bottom:.75rem}
.kb-article-body ul,.kb-article-body ol{padding-right:1.5rem}
.kb-article-body li{margin-bottom:.4rem}
.kb-article-body blockquote{border-right:4px solid var(--crm-primary,#2980b9);padding:12px 18px;background:#f0f7ff;border-radius:0 8px 8px 0;margin:1rem 0;color:#1a5276}
.kb-article-body pre{background:#1e293b;color:#e2e8f0;padding:16px;border-radius:10px;overflow-x:auto;font-size:.83rem;direction:ltr;text-align:left}
.kb-article-body img{max-width:100%;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);margin:8px 0}
.kb-article-body table{width:100%;border-collapse:collapse;margin:1rem 0;font-size:.85rem}
.kb-article-body table th{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;padding:8px 12px;text-align:right}
.kb-article-body table td{padding:8px 12px;border:1px solid #e2e8f0}
.kb-article-body table tr:nth-child(even) td{background:#f8fafc}
.kb-article-body a{color:var(--crm-primary,#2980b9);text-decoration:underline}

/* ── بطاقة المقالة ── */
.art-card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.07);border:1px solid #f0f2f7;overflow:hidden}
.art-header{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));padding:28px 32px;color:#fff}
.art-body{padding:28px 32px}
.art-meta{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;font-size:.78rem;color:#64748b}
.art-meta span{display:flex;align-items:center;gap:5px}

/* ── تقييم ── */
.vote-box{background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1px solid #bbf7d0;border-radius:14px;padding:22px;text-align:center;margin-top:28px}
.vote-btn{border:2px solid;border-radius:40px;padding:9px 24px;font-size:.9rem;font-weight:700;cursor:pointer;transition:.2s;background:#fff;display:inline-flex;align-items:center;gap:8px}
.vote-btn.yes{border-color:#27ae60;color:#27ae60}
.vote-btn.yes:hover,.vote-btn.yes.active{background:#27ae60;color:#fff}
.vote-btn.no{border-color:#e74c3c;color:#e74c3c}
.vote-btn.no:hover,.vote-btn.no.active{background:#e74c3c;color:#fff}
.vote-bar{height:8px;border-radius:20px;background:#e2e8f0;overflow:hidden;margin:10px auto;max-width:240px}
.vote-fill{height:100%;background:linear-gradient(90deg,#27ae60,#2ecc71);border-radius:20px;transition:width .4s ease}

/* ── الشريط الجانبي ── */
.sidebar-widget{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);border:1px solid #f0f2f7;overflow:hidden;margin-bottom:16px}
.widget-head{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));padding:10px 16px;color:#fff;font-size:.82rem;font-weight:700}
.widget-body{padding:14px}
.related-item{display:block;padding:8px 10px;border-radius:8px;color:#1a3a5c;font-size:.82rem;font-weight:600;transition:.15s;border:1px solid transparent;text-decoration:none}
.related-item:hover{background:#f0f7ff;border-color:#bce0fd;color:#1a5276;text-decoration:none}
.cat-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;color:#fff}
.breadcrumb-kb{font-size:.8rem;color:#64748b;display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.breadcrumb-kb a{color:var(--crm-primary,#2980b9);text-decoration:none}
.breadcrumb-kb a:hover{text-decoration:underline}
.tag-pill{background:#e8f4fd;color:#2980b9;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;display:inline-block;margin:2px}
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
                <small><?= htmlspecialchars($art['title']) ?></small>
            </div>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                <li class="breadcrumb-item"><a href="../tables/show-kb.php">قاعدة المعرفة</a></li>
                <li class="breadcrumb-item active">عرض مقالة</li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid" style="padding-bottom:20px">

<!-- Breadcrumb -->
<nav class="breadcrumb-kb">
    <a href="../../index.php"><i class="fas fa-home"></i></a>
    <span><i class="fas fa-chevron-left" style="font-size:.65rem"></i></span>
    <a href="../tables/show-kb.php">قاعدة المعرفة</a>
    <span><i class="fas fa-chevron-left" style="font-size:.65rem"></i></span>
    <span class="cat-badge" style="background:<?= htmlspecialchars($art['cat_color']) ?>">
        <i class="<?= htmlspecialchars($art['cat_icon']) ?>"></i>
        <?= htmlspecialchars($art['cat_name'] ?? '—') ?>
    </span>
    <span><i class="fas fa-chevron-left" style="font-size:.65rem"></i></span>
    <span style="color:#1a3a5c;font-weight:600"><?= htmlspecialchars($art['title']) ?></span>
</nav>

<div class="row">

    <!-- ── المحتوى الرئيسي ── -->
    <div class="col-lg-8">
        <div class="art-card">
            <!-- رأس المقالة -->
            <div class="art-header">
                <div style="margin-bottom:10px">
                    <span class="cat-badge" style="background:rgba(255,255,255,.2)">
                        <i class="<?= htmlspecialchars($art['cat_icon']) ?>"></i>
                        <?= htmlspecialchars($art['cat_name'] ?? '—') ?>
                    </span>
                    <?php if ($art['featured']): ?>
                    <span style="background:rgba(255,255,255,.2);color:#fef3c7;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;margin-right:5px">
                        <i class="fas fa-star"></i> مميز
                    </span>
                    <?php endif; ?>
                </div>
                <h2 style="font-size:1.4rem;font-weight:800;margin:0 0 12px"><?= htmlspecialchars($art['title']) ?></h2>
                <?php if (!empty($art['summary'])): ?>
                <p style="font-size:.9rem;opacity:.85;margin:0"><?= htmlspecialchars($art['summary']) ?></p>
                <?php endif; ?>
            </div>

            <div class="art-body">
                <!-- معلومات المقالة -->
                <div class="art-meta">
                    <span><i class="fas fa-user" style="color:#2980b9"></i><?= htmlspecialchars($art['author_name'] ?? 'غير محدد') ?></span>
                    <span><i class="fas fa-calendar" style="color:#8e44ad"></i><?= date('d/m/Y', strtotime($art['created_at'])) ?></span>
                    <span><i class="fas fa-eye" style="color:#27ae60"></i><?= number_format($art['views']) ?> مشاهدة</span>
                    <?php if ($helpful_pct !== null): ?>
                    <span><i class="fas fa-heart" style="color:#e74c3c"></i><?= $helpful_pct ?>% مفيد</span>
                    <?php endif; ?>
                </div>

                <!-- الوسوم -->
                <?php if (!empty($art['tags'])): ?>
                <div style="margin-bottom:20px">
                    <?php foreach (array_filter(array_map('trim', explode(',', $art['tags']))) as $tag): ?>
                    <span class="tag-pill"><i class="fas fa-tag" style="font-size:.65rem"></i> <?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <hr style="border-color:#f0f2f7;margin:0 0 24px">

                <!-- محتوى المقالة -->
                <div class="kb-article-body">
                    <?= $art['content'] ?>
                </div>

                <!-- ── صندوق التقييم ── -->
                <div class="vote-box" id="voteBox">
                    <div style="font-size:1rem;font-weight:700;color:#1a3a5c;margin-bottom:6px">
                        <i class="fas fa-question-circle" style="color:var(--crm-primary,#2980b9)"></i>
                        هل كانت هذه المقالة مفيدة؟
                    </div>
                    <div style="font-size:.8rem;color:#64748b;margin-bottom:14px">ساعدنا في تحسين محتوى قاعدة المعرفة</div>

                    <div style="display:flex;justify-content:center;gap:16px;margin-bottom:14px">
                        <button class="vote-btn yes <?= $myVote===1?'active':'' ?>" id="btnYes" data-v="1">
                            <i class="fas fa-thumbs-up"></i> <span>نعم، مفيدة</span>
                        </button>
                        <button class="vote-btn no <?= $myVote===0&&$myVote!==false?'active':'' ?>" id="btnNo" data-v="0">
                            <i class="fas fa-thumbs-down"></i> <span>لا</span>
                        </button>
                    </div>

                    <?php if ($total_votes > 0): ?>
                    <div id="voteStats">
                        <div class="vote-bar">
                            <div class="vote-fill" id="voteFill" style="width:<?= $helpful_pct ?>%"></div>
                        </div>
                        <div style="font-size:.75rem;color:#64748b">
                            <span class="h-yes" style="color:#27ae60;font-weight:700"><i class="fas fa-thumbs-up"></i> <span id="cntYes"><?= $art['helpful_yes'] ?></span></span>
                            &nbsp;/&nbsp;
                            <span style="color:#e74c3c;font-weight:700"><i class="fas fa-thumbs-down"></i> <span id="cntNo"><?= $art['helpful_no'] ?></span></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div id="voteStats" style="display:none">
                        <div class="vote-bar"><div class="vote-fill" id="voteFill" style="width:0%"></div></div>
                        <div style="font-size:.75rem;color:#64748b">
                            <span style="color:#27ae60;font-weight:700"><i class="fas fa-thumbs-up"></i> <span id="cntYes">0</span></span>
                            &nbsp;/&nbsp;
                            <span style="color:#e74c3c;font-weight:700"><i class="fas fa-thumbs-down"></i> <span id="cntNo">0</span></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div id="voteMsg" style="display:none;font-size:.78rem;margin-top:8px;color:#065f46;background:#d1fae5;padding:5px 12px;border-radius:20px;display:inline-block"></div>
                </div>

                <!-- أزرار الإدارة -->
                <?php if ($can_edit || $can_delete): ?>
                <div class="d-flex gap-2 mt-4" style="gap:10px">
                    <?php if ($can_edit): ?>
                    <a href="edit-kb-article.php?id=<?= $art_id ?>" class="btn btn-warning btn-sm" style="border-radius:8px">
                        <i class="fas fa-edit ml-1"></i> تعديل المقالة
                    </a>
                    <?php endif; ?>
                    <a href="../tables/show-kb.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px">
                        <i class="fas fa-arrow-right ml-1"></i> العودة للقائمة
                    </a>
                </div>
                <?php endif; ?>
            </div><!-- /art-body -->
        </div><!-- /art-card -->
    </div><!-- /col-8 -->

    <!-- ── الشريط الجانبي ── -->
    <div class="col-lg-4 mt-3 mt-lg-0">

        <!-- معلومات المقالة -->
        <div class="sidebar-widget">
            <div class="widget-head"><i class="fas fa-info-circle ml-2"></i>معلومات المقالة</div>
            <div class="widget-body">
                <div style="font-size:.8rem;display:flex;flex-direction:column;gap:8px">
                    <div class="d-flex justify-content-between">
                        <span style="color:#64748b"><i class="fas fa-eye ml-1"></i>المشاهدات</span>
                        <strong><?= number_format($art['views']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span style="color:#64748b"><i class="fas fa-calendar ml-1"></i>النشر</span>
                        <strong><?= date('d/m/Y', strtotime($art['created_at'])) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span style="color:#64748b"><i class="fas fa-sync ml-1"></i>آخر تحديث</span>
                        <strong><?= date('d/m/Y', strtotime($art['updated_at'])) ?></strong>
                    </div>
                    <?php if ($helpful_pct !== null): ?>
                    <div class="d-flex justify-content-between">
                        <span style="color:#64748b"><i class="fas fa-heart ml-1"></i>الرضا</span>
                        <strong style="color:#27ae60"><?= $helpful_pct ?>%</strong>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between">
                        <span style="color:#64748b"><i class="fas fa-circle ml-1" style="color:<?= $art['status']==='published'?'#27ae60':'#f39c12' ?>"></i>الحالة</span>
                        <strong><?= $art['status']==='published'?'منشور':'مسودة' ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- مقالات ذات صلة -->
        <?php if (!empty($related)): ?>
        <div class="sidebar-widget">
            <div class="widget-head"><i class="fas fa-link ml-2"></i>مقالات ذات صلة</div>
            <div class="widget-body" style="padding:8px">
                <?php foreach ($related as $r): ?>
                <a href="view-kb-article.php?id=<?= $r['id'] ?>" class="related-item">
                    <div><?= htmlspecialchars($r['title']) ?></div>
                    <div style="font-size:.7rem;color:#94a3b8;margin-top:2px">
                        <i class="fas fa-eye ml-1"></i><?= number_format($r['views']) ?> مشاهدة
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- روابط سريعة -->
        <div class="sidebar-widget">
            <div class="widget-head"><i class="fas fa-link ml-2"></i>روابط سريعة</div>
            <div class="widget-body" style="display:flex;flex-direction:column;gap:8px">
                <a href="../tables/show-kb.php" class="btn btn-outline-primary btn-sm" style="border-radius:8px;text-align:right">
                    <i class="fas fa-book-open ml-2"></i> قاعدة المعرفة
                </a>
                <?php if ($can_edit): ?>
                <a href="edit-kb-article.php?id=<?= $art_id ?>" class="btn btn-outline-warning btn-sm" style="border-radius:8px;text-align:right">
                    <i class="fas fa-edit ml-2"></i> تعديل هذه المقالة
                </a>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /col-4 -->
</div><!-- /row -->
</div></section><!-- /container-fluid/content -->
</div><!-- /content-wrapper -->

<?php include __DIR__ . '/../../main-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
    var artId = <?= $art_id ?>;

    $('#btnYes, #btnNo').on('click', function(){
        var v = $(this).data('v');
        var btn = $(this);
        btn.prop('disabled', true);

        $.post('view-kb-article.php?id='+artId, {vote: v}, function(res){
            if (res.ok) {
                // تحديث الأزرار
                $('#btnYes').toggleClass('active', v == 1);
                $('#btnNo').toggleClass('active',  v == 0);
                // تحديث الأرقام
                $('#cntYes').text(res.yes);
                $('#cntNo').text(res.no);
                var total = res.yes + res.no;
                var pct   = total > 0 ? Math.round(res.yes / total * 100) : 0;
                $('#voteFill').css('width', pct+'%');
                $('#voteStats').show();
                // رسالة شكر
                var msg = v == 1 ? '🙏 شكراً! يسعدنا أنها كانت مفيدة' : 'شكراً على رأيك، سنعمل على تحسينها';
                $('#voteMsg').text(msg).show();
            }
        }, 'json').always(function(){ btn.prop('disabled', false); });
    });
});
</script>
</body>
</html>
