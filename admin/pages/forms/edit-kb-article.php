<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";

$uid = (int)($_SESSION['user_id'] ?? 0);
$page_path = "pages/tables/show-kb.php";
if (!$uid) die("خطأ: يجب تسجيل الدخول أولاً");

$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?: 0;
$can_edit = 0;
if ($current_page_id > 0) {
    $r = $pdo->prepare("SELECT can_edit FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $r->execute([$uid, $current_page_id]);
    $can_edit = $r->fetchColumn() ?: 0;
}
if (!$can_edit) die("لا صلاحية للتعديل");

$art_id = (int)($_GET['id'] ?? 0);
if ($art_id <= 0) { header("Location: ../tables/show-kb.php"); exit; }

$art = $pdo->prepare("SELECT * FROM " . TBL_KB_ARTICLES . " WHERE id=?");
$art->execute([$art_id]);
$art = $art->fetch(PDO::FETCH_ASSOC);
if (!$art) { header("Location: ../tables/show-kb.php"); exit; }

$categories = $pdo->query("SELECT id,name,icon,color FROM " . TBL_KB_CATEGORIES . " WHERE is_active=1 ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    $title    = trim($_POST['title'] ?? '');
    $cat_id   = (int)($_POST['category_id'] ?? 0);
    $summary  = trim($_POST['summary'] ?? '');
    $content  = Security::sanitizeHtml($_POST['content'] ?? '');
    $tags     = trim($_POST['tags'] ?? '');
    $status   = in_array($_POST['status']??'draft', ['draft','published']) ? $_POST['status'] : 'draft';
    $featured = isset($_POST['featured']) ? 1 : 0;

    if (empty($title))  $errors[] = 'عنوان المقالة مطلوب';
    if ($cat_id <= 0)   $errors[] = 'يجب اختيار تصنيف';
    if (empty($content) || $content === '<p><br></p>') $errors[] = 'محتوى المقالة مطلوب';

    if (empty($errors)) {
        $pdo->prepare("UPDATE " . TBL_KB_ARTICLES . " SET
            category_id=?,title=?,summary=?,content=?,tags=?,status=?,featured=?,updated_by=?
            WHERE id=?")
        ->execute([$cat_id,$title,$summary,$content,$tags,$status,$featured,$uid,$art_id]);
        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم التحديث',text:'تم تحديث المقالة بنجاح'}));window.location.href='../tables/show-kb.php';</script>";
        exit;
    }
    // استعادة القيم المدخلة عند الخطأ
    $art = array_merge($art, ['title'=>$title,'category_id'=>$cat_id,'summary'=>$summary,'content'=>$content,'tags'=>$tags,'status'=>$status,'featured'=>$featured]);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تعديل مقالة: <?= htmlspecialchars($art['title']) ?></title>
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
.sidebar-card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);border:1px solid #f0f2f7;overflow:hidden;margin-bottom:16px}
.sidebar-head{background:#f8fafc;padding:10px 16px;font-size:.8rem;font-weight:700;color:#1a3a5c;border-bottom:1px solid #f0f2f7}
.sidebar-body{padding:14px 16px}
.cat-option{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:8px;cursor:pointer;transition:.15s}
.cat-option:hover{background:#f0f2f7}
.cat-icon-sm{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.72rem;color:#fff;flex-shrink:0}
.meta-box{background:#f8fafc;border-radius:8px;padding:10px 14px;font-size:.76rem;color:#64748b;border:1px solid #e2e8f0}
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
                <h4><i class="fas fa-edit ml-2"></i>تعديل مقالة</h4>
                <small><?= htmlspecialchars($art['title']) ?></small>
            </div>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                <li class="breadcrumb-item"><a href="../tables/show-kb.php">قاعدة المعرفة</a></li>
                <li class="breadcrumb-item active">تعديل</li>
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

    <div class="col-lg-8">
        <div class="kb-form-card">
            <div class="kb-form-head"><i class="fas fa-heading fa-lg"></i><span style="font-size:1rem;font-weight:700">عنوان المقالة</span></div>
            <div class="kb-form-body">
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($art['title']) ?>" required>
            </div>
        </div>

        <div class="kb-form-card">
            <div class="kb-form-head"><i class="fas fa-align-right fa-lg"></i><span style="font-size:1rem;font-weight:700">الملخص</span></div>
            <div class="kb-form-body">
                <textarea name="summary" class="form-control" rows="2"><?= htmlspecialchars($art['summary'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="kb-form-card">
            <div class="kb-form-head"><i class="fas fa-file-alt fa-lg"></i><span style="font-size:1rem;font-weight:700">محتوى المقالة</span></div>
            <div class="kb-form-body" style="padding-bottom:10px">
                <textarea id="articleContent" name="content"><?= htmlspecialchars($art['content'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-cog ml-2"></i>النشر والحالة</div>
            <div class="sidebar-body">
                <label class="field-label">الحالة</label>
                <select name="status" class="form-control mb-3">
                    <option value="draft"     <?= $art['status']==='draft'?'selected':'' ?>>مسودة</option>
                    <option value="published" <?= $art['status']==='published'?'selected':'' ?>>منشور</option>
                </select>
                <div class="icheck-primary d-inline mb-3">
                    <input type="checkbox" id="featured" name="featured" value="1" <?= $art['featured']?'checked':'' ?>>
                    <label for="featured" style="font-size:.85rem;font-weight:600">مقالة مميزة <i class="fas fa-star" style="color:#f39c12;font-size:.75rem"></i></label>
                </div>
                <div class="d-flex gap-2" style="gap:8px;margin-top:6px">
                    <button type="submit" name="save_article" class="btn btn-primary flex-fill" style="border-radius:8px">
                        <i class="fas fa-save ml-1"></i> تحديث
                    </button>
                    <a href="../tables/show-kb.php" class="btn btn-secondary" style="border-radius:8px">
                        <i class="fas fa-times ml-1"></i> إلغاء
                    </a>
                </div>
            </div>
        </div>

        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-folder ml-2"></i>التصنيف *</div>
            <div class="sidebar-body" style="max-height:260px;overflow-y:auto">
                <?php foreach ($categories as $cat): ?>
                <label class="cat-option">
                    <input type="radio" name="category_id" value="<?= $cat['id'] ?>"
                        <?= $art['category_id'] == $cat['id'] ? 'checked' : '' ?>>
                    <div class="cat-icon-sm" style="background:<?= htmlspecialchars($cat['color']) ?>">
                        <i class="<?= htmlspecialchars($cat['icon']) ?>"></i>
                    </div>
                    <span style="font-size:.84rem;font-weight:600;color:#1a3a5c"><?= htmlspecialchars($cat['name']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-tags ml-2"></i>الوسوم</div>
            <div class="sidebar-body">
                <input type="text" name="tags" class="form-control" placeholder="كلمة1, كلمة2" value="<?= htmlspecialchars($art['tags'] ?? '') ?>">
                <div style="font-size:.72rem;color:#94a3b8;margin-top:4px">افصل الوسوم بفاصلة</div>
            </div>
        </div>

        <!-- معلومات المقالة -->
        <div class="sidebar-card">
            <div class="sidebar-head"><i class="fas fa-info-circle ml-2"></i>معلومات</div>
            <div class="sidebar-body">
                <div class="meta-box">
                    <div><i class="fas fa-eye ml-1" style="color:#8e44ad"></i>المشاهدات: <strong><?= number_format($art['views']) ?></strong></div>
                    <div class="mt-1"><i class="fas fa-thumbs-up ml-1" style="color:#27ae60"></i>مفيد: <strong><?= $art['helpful_yes'] ?></strong></div>
                    <div class="mt-1"><i class="fas fa-thumbs-down ml-1" style="color:#e74c3c"></i>غير مفيد: <strong><?= $art['helpful_no'] ?></strong></div>
                    <div class="mt-1"><i class="fas fa-calendar ml-1" style="color:#2980b9"></i>تاريخ النشر: <strong><?= date('Y-m-d', strtotime($art['created_at'])) ?></strong></div>
                </div>
                <a href="view-kb-article.php?id=<?= $art_id ?>" class="btn btn-sm btn-outline-info w-100 mt-2" style="border-radius:8px">
                    <i class="fas fa-eye ml-1"></i> معاينة المقالة
                </a>
            </div>
        </div>
    </div>

</div>
</form>

</div></section>
</div><!-- /content-wrapper -->
<?php include __DIR__ . '/../../main-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        placeholder: 'اكتب محتوى المقالة هنا...'
    });

    $('#articleForm').on('submit', function(){
        var content = $('#articleContent').summernote('code');
        if (!content || content === '<p><br></p>') {
            Swal.fire({icon:'warning',title:'المحتوى مطلوب',text:'يرجى كتابة محتوى المقالة'});
            return false;
        }
        return true;
    });
});
</script>
</body>
</html>
