<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";

$uid = (int)($_SESSION['user_id'] ?? 0);
$page_path = "pages/tables/show-kb.php";
if (!$uid) die("خطأ: يجب تسجيل الدخول أولاً");

// صلاحية إضافة (مرتبطة بصفحة قاعدة المعرفة)
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?: 0;
$can_add = 0;
if ($current_page_id > 0) {
    $r = $pdo->prepare("SELECT can_add FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $r->execute([$uid, $current_page_id]);
    $can_add = $r->fetchColumn() ?: 0;
}

$categories = $pdo->query("SELECT id,name,icon,color FROM " . TBL_KB_CATEGORIES . " WHERE is_active=1 ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article']) && $can_add) {
    $title      = trim($_POST['title'] ?? '');
    $cat_id     = (int)($_POST['category_id'] ?? 0);
    $summary    = trim($_POST['summary'] ?? '');
    $content    = Security::sanitizeHtml($_POST['content'] ?? '');
    $tags       = trim($_POST['tags'] ?? '');
    $status     = in_array($_POST['status']??'draft', ['draft','published']) ? $_POST['status'] : 'draft';
    $featured   = isset($_POST['featured']) ? 1 : 0;

    if (empty($title))   $errors[] = 'عنوان المقالة مطلوب';
    if ($cat_id <= 0)    $errors[] = 'يجب اختيار تصنيف';
    if (empty($content) || $content === '<p><br></p>') $errors[] = 'محتوى المقالة مطلوب';

    if (empty($errors)) {
        // إنشاء slug فريد
        $latin = function_exists('transliterator_transliterate')
            ? transliterator_transliterate('Any-Latin; Latin-ASCII', $title)
            : $title;
        $base_slug = preg_replace('/[^a-z0-9\-]/i', '-', $latin ?: $title);
        $base_slug = strtolower(trim(preg_replace('/-+/', '-', $base_slug), '-'));
        if (empty($base_slug)) $base_slug = 'article';
        $slug = $base_slug;
        $counter = 1;
        while ($pdo->prepare("SELECT id FROM " . TBL_KB_ARTICLES . " WHERE slug=?")->execute([$slug]) && $pdo->query("SELECT COUNT(*) FROM " . TBL_KB_ARTICLES . " WHERE slug='$slug'")->fetchColumn() > 0) {
            $slug = $base_slug . '-' . $counter++;
        }

        $pdo->prepare("INSERT INTO " . TBL_KB_ARTICLES . "
            (category_id,title,slug,summary,content,tags,status,featured,created_by)
            VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$cat_id, $title, $slug, $summary, $content, $tags, $status, $featured, $uid]);
        $new_id = (int)$pdo->lastInsertId();
        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تمت الإضافة',text:'تم نشر المقالة بنجاح'}));window.location.href='../tables/show-kb.php';</script>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إضافة مقالة جديدة</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/icheck-bootstrap/3.0.1/icheck-bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}
.kb-form-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.07);border:1px solid #f0f2f7;overflow:hidden;margin-bottom:20px}
.kb-form-head{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;padding:16px 22px;display:flex;align-items:center;gap:10px}
.kb-form-body{padding:22px}
.field-label{font-size:.8rem;font-weight:700;color:#475569;margin-bottom:5px;display:block}
.form-control{border-radius:8px;border:1.5px solid #e2e8f0;font-size:.875rem}
.form-control:focus{border-color:var(--crm-primary,#1a5276);box-shadow:0 0 0 3px rgba(26,82,118,.08)}
.tag-hint{font-size:.72rem;color:#94a3b8;margin-top:4px}
.sidebar-card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);border:1px solid #f0f2f7;overflow:hidden;margin-bottom:16px}
.sidebar-head{background:#f8fafc;padding:10px 16px;font-size:.8rem;font-weight:700;color:#1a3a5c;border-bottom:1px solid #f0f2f7}
.sidebar-body{padding:14px 16px}
.cat-option{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:8px;cursor:pointer;transition:.15s}
.cat-option:hover{background:#f0f2f7}
.cat-option input[type=radio]{accent-color:var(--crm-primary,#1a5276)}
.cat-icon-sm{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.72rem;color:#fff;flex-shrink:0}
.note-editor.note-frame{border-radius:0 0 8px 8px!important}
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
                <h4><i class="fas fa-plus-circle ml-2"></i>إضافة مقالة جديدة</h4>
                <small>أضف مقالة إلى قاعدة المعرفة</small>
            </div>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                <li class="breadcrumb-item"><a href="../tables/show-kb.php">قاعدة المعرفة</a></li>
                <li class="breadcrumb-item active">إضافة مقالة</li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid" style="padding-bottom:20px">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="border-radius:10px">
    <i class="fas fa-exclamation-circle ml-2"></i>
    <?= implode(' — ', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<form method="POST" id="articleForm">
<div class="row">

    <!-- ── عمود المحتوى (يسار) ── -->
    <div class="col-lg-8">

        <!-- العنوان -->
        <div class="kb-form-card">
            <div class="kb-form-head"><i class="fas fa-heading fa-lg"></i><span style="font-size:1rem;font-weight:700">عنوان المقالة</span></div>
            <div class="kb-form-body">
                <input type="text" name="title" class="form-control" placeholder="اكتب عنواناً واضحاً ومختصراً..." value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
            </div>
        </div>

        <!-- الملخص -->
        <div class="kb-form-card">
            <div class="kb-form-head"><i class="fas fa-align-right fa-lg"></i><span style="font-size:1rem;font-weight:700">الملخص</span></div>
            <div class="kb-form-body">
                <textarea name="summary" class="form-control" rows="2" placeholder="ملخص مختصر يظهر في نتائج البحث (اختياري)"><?= htmlspecialchars($_POST['summary'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- المحتوى -->
        <div class="kb-form-card">
            <div class="kb-form-head"><i class="fas fa-file-alt fa-lg"></i><span style="font-size:1rem;font-weight:700">محتوى المقالة</span></div>
            <div class="kb-form-body" style="padding-bottom:10px">
                <textarea id="articleContent" name="content"><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
            </div>
        </div>

    </div><!-- /col-8 -->

    <!-- ── الشريط الجانبي ── -->
    <div class="col-lg-4">

        <!-- الإجراءات -->
        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-cog ml-2"></i>النشر والحالة</div>
            <div class="sidebar-body">
                <label class="field-label">الحالة</label>
                <select name="status" class="form-control mb-3">
                    <option value="draft"     <?= ($_POST['status']??'draft')==='draft'?'selected':'' ?>>مسودة</option>
                    <option value="published" <?= ($_POST['status']??'')==='published'?'selected':'' ?>>منشور</option>
                </select>
                <div class="icheck-primary d-inline mb-3">
                    <input type="checkbox" id="featured" name="featured" value="1" <?= isset($_POST['featured'])?'checked':'' ?>>
                    <label for="featured" style="font-size:.85rem;font-weight:600">تمييز المقالة <i class="fas fa-star" style="color:#f39c12;font-size:.75rem"></i></label>
                </div>
                <div class="d-flex gap-2" style="gap:8px;margin-top:6px">
                    <button type="submit" name="save_article" class="btn btn-primary flex-fill" style="border-radius:8px">
                        <i class="fas fa-save ml-1"></i> حفظ المقالة
                    </button>
                    <a href="../tables/show-kb.php" class="btn btn-secondary" style="border-radius:8px">
                        <i class="fas fa-times ml-1"></i> إلغاء
                    </a>
                </div>
            </div>
        </div>

        <!-- التصنيف -->
        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-folder ml-2"></i>التصنيف *</div>
            <div class="sidebar-body" style="max-height:260px;overflow-y:auto">
                <?php foreach ($categories as $cat): ?>
                <label class="cat-option">
                    <input type="radio" name="category_id" value="<?= $cat['id'] ?>"
                        <?= (($_POST['category_id']??0) == $cat['id']) ? 'checked' : '' ?>>
                    <div class="cat-icon-sm" style="background:<?= htmlspecialchars($cat['color']) ?>">
                        <i class="<?= htmlspecialchars($cat['icon']) ?>"></i>
                    </div>
                    <span style="font-size:.84rem;font-weight:600;color:#1a3a5c"><?= htmlspecialchars($cat['name']) ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <div style="font-size:.8rem;color:#94a3b8;text-align:center;padding:10px">
                    لا توجد تصنيفات — <a href="../tables/show-kb.php">أضف تصنيفاً أولاً</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- الوسوم -->
        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-tags ml-2"></i>الوسوم (Tags)</div>
            <div class="sidebar-body">
                <input type="text" name="tags" class="form-control" placeholder="كلمة1, كلمة2, كلمة3" value="<?= htmlspecialchars($_POST['tags'] ?? '') ?>">
                <div class="tag-hint">افصل الوسوم بفاصلة. تستخدم للبحث.</div>
            </div>
        </div>

    </div><!-- /col-4 -->
</div><!-- /row -->
</form>

</div></section>
</div><!-- /content-wrapper -->

<?php include __DIR__ . '/../../main-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
<script>
$(function(){
    $('#articleContent').summernote({
        height: 380,
        lang: 'ar-AR',
        direction: 'rtl',
        toolbar: [
            ['style',  ['style']],
            ['font',   ['bold','italic','underline','strikethrough','clear']],
            ['color',  ['color']],
            ['para',   ['ul','ol','paragraph']],
            ['table',  ['table']],
            ['insert', ['link','picture','hr']],
            ['view',   ['fullscreen','codeview']]
        ],
        placeholder: 'اكتب محتوى المقالة هنا...',
        styleTags: ['p','h3','h4','h5','blockquote','pre']
    });

    $('#articleForm').on('submit', function(){
        var content = $('#articleContent').summernote('code');
        if (!content || content === '<p><br></p>') {
            Swal.fire({icon:'warning',title:'المحتوى مطلوب',text:'يرجى كتابة محتوى المقالة'});
            return false;
        }
        var cat = $('input[name="category_id"]:checked').val();
        if (!cat) {
            Swal.fire({icon:'warning',title:'التصنيف مطلوب',text:'يرجى اختيار تصنيف للمقالة'});
            return false;
        }
        return true;
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
