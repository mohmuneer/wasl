<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require_once __DIR__ . "/../../../core/Notify.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-assets.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

// ── تطبيق الهجرة تلقائياً عند أول زيارة ──────────────────────────
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'assets'")->fetchColumn();
    if (!$tableExists) {
        $sqlFile = __DIR__ . "/../../../../wasl_assets_migration.sql";
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
            $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
            $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s)=>strlen(trim($s))>5);
            foreach ($statements as $stmt) {
                try { $pdo->exec($stmt); } catch(PDOException $e) {}
            }
        }
    }
} catch (PDOException $e) {}

// ── صلاحيات ──────────────────────────────────────────────────────
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;
$can_add = $can_edit = $can_delete = 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $p = $accStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $can_add    = $p['can_add']    ?? 0;
    $can_edit   = $p['can_edit']   ?? 0;
    $can_delete = $p['can_delete'] ?? 0;
}

// ── جلب البيانات المساعدة ─────────────────────────────────────────
$categories  = $pdo->query("SELECT * FROM " . TBL_ASSET_CATEGORIES . " WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$branches    = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT MIN(id) AS id, department_name FROM departments GROUP BY department_name ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
$users_list  = $pdo->query("SELECT id, full_name, email FROM sys_users WHERE status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// ── معالجة الحذف ──────────────────────────────────────────────────
if (isset($_GET['delete_id']) && $can_delete) {
    $pdo->prepare("DELETE FROM " . TBL_ASSETS . " WHERE id=?")->execute([(int)$_GET['delete_id']]);
    log_action($pdo,'delete','asset',(int)$_GET['delete_id'],[],[]);
    header("Location: show-assets.php?deleted=1"); exit;
}

// ── معالجة الإضافة ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_asset']) && $can_add) {
    $code = strtoupper(trim($_POST['asset_code']??''));
    if (empty($code)) {
        // توليد كود تلقائي
        $last = $pdo->query("SELECT MAX(id) FROM ".TBL_ASSETS)->fetchColumn();
        $code = 'AST-' . str_pad(($last+1), 5, '0', STR_PAD_LEFT);
    }
    $photo = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK) {
        $uploadCheck = Security::validateUpload($_FILES['photo'], 'image', 5);
        if ($uploadCheck['ok']) {
            $upDir = __DIR__ . '/../../../uploads/assets/';
            if (!is_dir($upDir)) mkdir($upDir,0777,true);
            $file = Security::safeFilename($_FILES['photo']['name'], 'ast');
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upDir.$file)) $photo = 'assets/'.$file;
        }
    }
    $pdo->prepare("INSERT INTO ".TBL_ASSETS."
        (asset_code,name,category_id,serial_number,model,manufacturer,
         branch_id,department_id,room_number,status,
         purchase_date,purchase_price,warranty_expiry,
         assigned_to,photo_path,notes,created_by)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $code, $_POST['name'], $_POST['category_id']??1,
        $_POST['serial_number']??null, $_POST['model']??null, $_POST['manufacturer']??null,
        $_POST['branch_id']??null, $_POST['department_id']??null, $_POST['room_number']??null,
        $_POST['status']??'active',
        $_POST['purchase_date']??null, $_POST['purchase_price']??null, $_POST['warranty_expiry']??null,
        $_POST['assigned_to']??null, $photo, $_POST['notes']??null, $current_user_id
    ]);
    $new_id = (int)$pdo->lastInsertId();
    log_action($pdo,'create','asset',$new_id,[],['code'=>$code,'name'=>$_POST['name']]);
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الإضافة',text:'تم إضافة الأصل #".$code." بنجاح'}));window.location.href='show-assets.php';</script>";
    exit;
}

// ── معالجة التعديل ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_asset']) && $can_edit) {
    $id = (int)$_POST['id'];
    $photo = $_POST['existing_photo']??null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK) {
        $uploadCheck = Security::validateUpload($_FILES['photo'], 'image', 5);
        if ($uploadCheck['ok']) {
            $upDir = __DIR__ . '/../../../uploads/assets/';
            if (!is_dir($upDir)) mkdir($upDir,0777,true);
            $file = Security::safeFilename($_FILES['photo']['name'], 'ast');
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upDir.$file)) $photo = 'assets/'.$file;
        }
    }
    $pdo->prepare("UPDATE ".TBL_ASSETS." SET
        name=?,category_id=?,serial_number=?,model=?,manufacturer=?,
        branch_id=?,department_id=?,room_number=?,status=?,
        purchase_date=?,purchase_price=?,warranty_expiry=?,
        assigned_to=?,photo_path=?,notes=?
        WHERE id=?")
    ->execute([
        $_POST['name'],$_POST['category_id']??1,
        $_POST['serial_number']??null,$_POST['model']??null,$_POST['manufacturer']??null,
        $_POST['branch_id']??null,$_POST['department_id']??null,$_POST['room_number']??null,
        $_POST['status']??'active',
        $_POST['purchase_date']??null,$_POST['purchase_price']??null,$_POST['warranty_expiry']??null,
        $_POST['assigned_to']??null,$photo,$_POST['notes']??null, $id
    ]);
    log_action($pdo,'update','asset',$id,[],['name'=>$_POST['name']]);
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم التعديل',text:'تم تحديث بيانات الأصل بنجاح'}));window.location.href='show-assets.php';</script>";
    exit;
}

// ── جلب الأصول ───────────────────────────────────────────────────
$assets = $pdo->query("
    SELECT a.*,
           ac.name AS category_name, ac.icon AS cat_icon, ac.color AS cat_color,
           b.branch_name, d.department_name,
           u.full_name AS assigned_name,
           (SELECT COUNT(*) FROM ".TBL_MAINTENANCE_LOGS." ml WHERE ml.asset_id=a.id) AS maint_count,
           (SELECT ml2.maintenance_date FROM ".TBL_MAINTENANCE_LOGS." ml2 WHERE ml2.asset_id=a.id ORDER BY ml2.maintenance_date DESC LIMIT 1) AS last_maint,
           (SELECT ms.next_due_date FROM ".TBL_MAINTENANCE_SCHEDULES." ms WHERE ms.asset_id=a.id AND ms.status='active' ORDER BY ms.next_due_date ASC LIMIT 1) AS next_due
    FROM ".TBL_ASSETS." a
    LEFT JOIN ".TBL_ASSET_CATEGORIES." ac ON a.category_id=ac.id
    LEFT JOIN branches b ON a.branch_id=b.id
    LEFT JOIN departments d ON a.department_id=d.id
    LEFT JOIN sys_users u ON a.assigned_to=u.id
    ORDER BY a.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── إحصاءات ──────────────────────────────────────────────────────
$stats = [
    'total'       => count($assets),
    'active'      => count(array_filter($assets, fn($a)=>$a['status']==='active')),
    'maintenance' => count(array_filter($assets, fn($a)=>$a['status']==='under_maintenance')),
    'due_soon'    => count(array_filter($assets, fn($a)=>$a['next_due'] && strtotime($a['next_due'])<=strtotime('+7 days'))),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة الأصول والأجهزة</title>
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

/* ── إحصاءات ── */
.ast-stat{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:14px 18px;display:flex;align-items:center;gap:12px;border:1px solid #f0f2f7}
.ast-stat .si{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;flex-shrink:0}
.ast-stat .sv{font-size:1.5rem;font-weight:800;line-height:1}
.ast-stat .sl{font-size:.72rem;color:#888;margin-top:2px}

/* ── بطاقة الجدول ── */
.ast-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;border:1px solid #f0f2f7}
.ast-card-head{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:1px solid #f0f2f7}

/* ── جدول ── */
#assetsTable thead th{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))!important;color:#fff!important;border:none!important;white-space:nowrap;vertical-align:middle;font-size:.76rem;font-weight:700;padding:10px 8px;text-align:center}
#assetsTable tbody td{vertical-align:middle;text-align:center;font-size:.78rem;padding:9px 7px;border-top:1px solid #f0f4f8!important;border-left:none!important;border-right:none!important;border-bottom:none!important}
#assetsTable tbody tr:hover{background:#f8fafc}

/* ── شارات ── */
.status-active{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.status-maintenance{background:#fef3c7;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.status-retired{background:#f1f5f9;color:#64748b;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.status-lost{background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.due-badge{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}
.overdue-badge{background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700}

/* ── أزرار ── */
.btn-act{width:28px;height:28px;padding:0;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;transition:.15s;border:none;cursor:pointer}

/* ── مودال ── */
.ast-modal-head{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff}
.ast-label{font-size:.8rem;font-weight:700;color:#475569;margin-bottom:4px;display:block}
.form-control{border-radius:8px;border:1.5px solid #e2e8f0;font-size:.85rem}
.form-control:focus{border-color:var(--crm-primary,#1a5276);box-shadow:0 0 0 3px rgba(26,82,118,.08)}

/* ── كارت الأصل (عرض سريع) ── */
.cat-icon-wrap{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:#fff;flex-shrink:0}

/* ── QR ── */
#qrModal .qr-box{padding:20px;text-align:center;background:#fff;border-radius:12px}
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
                    <h4><i class="fas fa-cubes ml-2"></i>إدارة الأصول والأجهزة</h4>
                    <small>تسجيل وتتبع جميع الأصول مع ربطها بالبلاغات والصيانة</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">الأصول</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- ── إحصاءات ── -->
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3">
                <div class="ast-stat">
                    <div class="si" style="background:linear-gradient(135deg,#1a5276,#2980b9)"><i class="fas fa-cubes"></i></div>
                    <div><div class="sv"><?= $stats['total'] ?></div><div class="sl">إجمالي الأصول</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="ast-stat">
                    <div class="si" style="background:linear-gradient(135deg,#065f46,#059669)"><i class="fas fa-check-circle"></i></div>
                    <div><div class="sv"><?= $stats['active'] ?></div><div class="sl">نشط</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="ast-stat">
                    <div class="si" style="background:linear-gradient(135deg,#d97706,#f59e0b)"><i class="fas fa-tools"></i></div>
                    <div><div class="sv"><?= $stats['maintenance'] ?></div><div class="sl">تحت الصيانة</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="ast-stat" <?= $stats['due_soon']>0?'style="border-right:3px solid #dc2626"':'' ?>>
                    <div class="si" style="background:linear-gradient(135deg,#dc2626,#ef4444)"><i class="fas fa-exclamation-triangle"></i></div>
                    <div><div class="sv" style="<?= $stats['due_soon']>0?'color:#dc2626':'' ?>"><?= $stats['due_soon'] ?></div><div class="sl">صيانة خلال 7 أيام</div></div>
                </div>
            </div>
        </div>

        <!-- ── الجدول ── -->
        <div class="ast-card">
            <div class="ast-card-head">
                <h5 style="margin:0;font-weight:700;font-size:.95rem;color:#334155">
                    <i class="fas fa-list ml-2 text-muted"></i>قائمة الأصول
                </h5>
                <div class="d-flex" style="gap:8px">
                    <a href="show-maintenance.php" class="btn btn-sm btn-outline-warning" style="border-radius:8px;font-weight:600">
                        <i class="fas fa-calendar-alt ml-1"></i>جدول الصيانة
                    </a>
                    <?php if ($can_add): ?>
                    <button type="button" class="btn btn-sm" data-toggle="modal" data-target="#addAssetModal"
                        style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:8px;font-weight:700;padding:6px 14px">
                        <i class="fas fa-plus ml-1"></i>إضافة أصل
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
            <div class="table-responsive">
            <table id="assetsTable" class="table mb-0">
                <thead>
                    <tr>
                        <th>الكود</th><th>الأصل</th><th>التصنيف</th><th>الفرع / القسم</th>
                        <th>المسؤول</th><th>الحالة</th><th>آخر صيانة</th><th>الصيانة القادمة</th><th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assets as $a):
                    $today = date('Y-m-d');
                    $dueStatus = '';
                    if ($a['next_due']) {
                        $daysLeft = (strtotime($a['next_due']) - time()) / 86400;
                        if ($daysLeft < 0)         $dueStatus = 'overdue';
                        elseif ($daysLeft <= 7)    $dueStatus = 'soon';
                    }
                ?>
                <tr data-id="<?= $a['id'] ?>">
                    <td>
                        <span style="font-family:monospace;font-size:.72rem;background:#f1f5f9;padding:2px 7px;border-radius:5px;color:#334155"><?= htmlspecialchars($a['asset_code']) ?></span>
                    </td>
                    <td style="text-align:right">
                        <div style="display:flex;align-items:center;gap:8px">
                            <?php if (!empty($a['photo_path'])): ?>
                            <img src="../../../uploads/<?= htmlspecialchars($a['photo_path']) ?>" style="width:32px;height:32px;border-radius:7px;object-fit:cover;flex-shrink:0" alt="">
                            <?php else: ?>
                            <div class="cat-icon-wrap" style="background:<?= htmlspecialchars($a['cat_color']??'#1a5276') ?>">
                                <i class="<?= htmlspecialchars($a['cat_icon']??'fas fa-cube') ?>"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight:700;color:#1e293b;font-size:.82rem"><?= htmlspecialchars($a['name']) ?></div>
                                <?php if ($a['model']): ?>
                                <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($a['model']) ?><?= $a['manufacturer']?' · '.htmlspecialchars($a['manufacturer']):'' ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="background:<?= htmlspecialchars($a['cat_color']??'#1a5276') ?>22;color:<?= htmlspecialchars($a['cat_color']??'#1a5276') ?>;padding:2px 9px;border-radius:20px;font-size:.68rem;font-weight:700">
                            <?= htmlspecialchars($a['category_name']??'—') ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-size:.76rem;color:#334155"><?= htmlspecialchars($a['branch_name']??'—') ?></div>
                        <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($a['department_name']??'') ?><?= $a['room_number']?' / '.$a['room_number']:'' ?></div>
                    </td>
                    <td>
                        <?php if ($a['assigned_name']): ?>
                        <div style="display:flex;align-items:center;justify-content:center;gap:5px">
                            <div style="width:24px;height:24px;border-radius:50%;background:#1a5276;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;flex-shrink:0">
                                <?= mb_substr($a['assigned_name'],0,1,'UTF-8') ?>
                            </div>
                            <span style="font-size:.76rem;color:#334155"><?= htmlspecialchars($a['assigned_name']) ?></span>
                        </div>
                        <?php else: echo '<span style="color:#cbd5e1;font-size:.74rem">—</span>'; endif; ?>
                    </td>
                    <td>
                        <?php
                        $statusMap = ['active'=>'نشط','under_maintenance'=>'صيانة','retired'=>'متقاعد','lost'=>'مفقود'];
                        $statusCls = ['active'=>'status-active','under_maintenance'=>'status-maintenance','retired'=>'status-retired','lost'=>'status-lost'];
                        ?>
                        <span class="<?= $statusCls[$a['status']]??'status-retired' ?>"><?= $statusMap[$a['status']]??$a['status'] ?></span>
                    </td>
                    <td>
                        <?php if ($a['last_maint']): ?>
                        <small style="font-size:.74rem;color:#64748b"><?= date('Y/m/d',strtotime($a['last_maint'])) ?></small>
                        <div style="font-size:.65rem;color:#94a3b8"><?= $a['maint_count'] ?> مرة</div>
                        <?php else: echo '<span style="color:#cbd5e1;font-size:.72rem">لم تُنفَّذ</span>'; endif; ?>
                    </td>
                    <td>
                        <?php if ($a['next_due']): ?>
                        <?php if ($dueStatus==='overdue'): ?>
                        <span class="overdue-badge"><i class="fas fa-exclamation-circle ml-1"></i>متأخرة</span>
                        <?php elseif ($dueStatus==='soon'): ?>
                        <span class="due-badge"><i class="fas fa-clock ml-1"></i><?= date('Y/m/d',strtotime($a['next_due'])) ?></span>
                        <?php else: ?>
                        <small style="font-size:.74rem;color:#64748b"><?= date('Y/m/d',strtotime($a['next_due'])) ?></small>
                        <?php endif; ?>
                        <?php else: echo '<span style="color:#cbd5e1;font-size:.72rem">غير مجدولة</span>'; endif; ?>
                    </td>
                    <td>
                        <div class="d-flex justify-content-center" style="gap:4px">
                            <button type="button" class="btn-act" style="background:#eff6ff;color:#2563eb" title="QR كود"
                                onclick="showQR('<?= htmlspecialchars($a['asset_code']) ?>','<?= htmlspecialchars(addslashes($a['name'])) ?>')">
                                <i class="fas fa-qrcode"></i>
                            </button>
                            <a href="show-maintenance.php?asset_id=<?= $a['id'] ?>" class="btn-act" style="background:#fef3c7;color:#d97706" title="جدول الصيانة">
                                <i class="fas fa-tools"></i>
                            </a>
                            <?php if ($can_edit): ?>
                            <button type="button" class="btn-act edit-btn" style="background:#f0fdf4;color:#059669" title="تعديل"
                                data-id="<?= $a['id'] ?>"
                                data-code="<?= htmlspecialchars($a['asset_code']) ?>"
                                data-name="<?= htmlspecialchars($a['name']) ?>"
                                data-cat="<?= $a['category_id'] ?>"
                                data-serial="<?= htmlspecialchars($a['serial_number']??'') ?>"
                                data-model="<?= htmlspecialchars($a['model']??'') ?>"
                                data-mfr="<?= htmlspecialchars($a['manufacturer']??'') ?>"
                                data-branch="<?= $a['branch_id'] ?>"
                                data-dept="<?= $a['department_id'] ?>"
                                data-room="<?= htmlspecialchars($a['room_number']??'') ?>"
                                data-status="<?= $a['status'] ?>"
                                data-purchase="<?= $a['purchase_date']??'' ?>"
                                data-price="<?= $a['purchase_price']??'' ?>"
                                data-warranty="<?= $a['warranty_expiry']??'' ?>"
                                data-assigned="<?= $a['assigned_to']??'' ?>"
                                data-notes="<?= htmlspecialchars($a['notes']??'') ?>"
                                data-photo="<?= htmlspecialchars($a['photo_path']??'') ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <button type="button" class="btn-act" style="background:#fee2e2;color:#dc2626" title="حذف"
                                onclick="confirmDelete(<?= $a['id'] ?>,'<?= htmlspecialchars(addslashes($a['name'])) ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            </div>
        </div>

    </div>
    </section>
</div>

<!-- ══ مودال الإضافة ══ -->
<div class="modal fade" id="addAssetModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header ast-modal-head">
        <h5 class="modal-title"><i class="fas fa-plus-circle ml-2"></i>إضافة أصل/جهاز جديد</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="add_asset" value="1">
        <div class="modal-body">
            <div class="row">
                <!-- العمود الأول -->
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">كود الأصل <small class="text-muted">(تلقائي إذا فارغ)</small></label>
                        <input type="text" name="asset_code" class="form-control" placeholder="AST-00001" style="font-family:monospace">
                    </div>
                    <div class="form-group">
                        <label class="ast-label">اسم الجهاز/الأصل <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="مثال: حاسوب مكتبي Dell">
                    </div>
                    <div class="form-group">
                        <label class="ast-label">التصنيف</label>
                        <select name="category_id" class="form-control">
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="ast-label">الحالة</label>
                        <select name="status" class="form-control">
                            <option value="active">نشط</option>
                            <option value="under_maintenance">تحت الصيانة</option>
                            <option value="retired">متقاعد</option>
                            <option value="lost">مفقود</option>
                        </select>
                    </div>
                </div>
                <!-- العمود الثاني -->
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">الرقم التسلسلي</label>
                        <input type="text" name="serial_number" class="form-control" placeholder="SN-XXXXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label class="ast-label">الموديل</label>
                        <input type="text" name="model" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="ast-label">الشركة المصنعة</label>
                        <input type="text" name="manufacturer" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="ast-label">المسؤول عنه</label>
                        <select name="assigned_to" class="form-control">
                            <option value="">— غير محدد —</option>
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- العمود الثالث -->
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">الفرع</label>
                        <select name="branch_id" class="form-control">
                            <option value="">— اختر —</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="ast-label">القسم</label>
                        <select name="department_id" class="form-control">
                            <option value="">— اختر —</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="ast-label">رقم الغرفة/المكتب</label>
                        <input type="text" name="room_number" class="form-control" placeholder="مثال: A-101">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="ast-label">تاريخ الشراء</label>
                                <input type="date" name="purchase_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="ast-label">انتهاء الضمان</label>
                                <input type="date" name="warranty_expiry" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">صورة الجهاز</label>
                        <input type="file" name="photo" class="form-control-file" accept="image/*">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">سعر الشراء</label>
                        <div class="input-group">
                            <input type="number" name="purchase_price" class="form-control" step="0.01">
                            <div class="input-group-append"><span class="input-group-text">ريال</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="background:#fafbfc">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;border-radius:8px">
                <i class="fas fa-save ml-1"></i>حفظ الأصل
            </button>
        </div>
    </form>
</div></div></div>

<!-- ══ مودال التعديل ══ -->
<div class="modal fade" id="editAssetModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff">
        <h5 class="modal-title"><i class="fas fa-edit ml-2"></i>تعديل بيانات الأصل</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data" id="editAssetForm">
        <input type="hidden" name="edit_asset" value="1">
        <input type="hidden" name="id" id="edit_id">
        <input type="hidden" name="existing_photo" id="edit_existing_photo">
        <div class="modal-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="ast-label">الكود</label>
                        <input type="text" id="edit_code" class="form-control" readonly style="background:#f8fafc;font-family:monospace">
                    </div>
                    <div class="form-group">
                        <label class="ast-label">الاسم <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="ast-label">التصنيف</label>
                        <select name="category_id" id="edit_cat" class="form-control">
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="ast-label">الحالة</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">نشط</option>
                            <option value="under_maintenance">تحت الصيانة</option>
                            <option value="retired">متقاعد</option>
                            <option value="lost">مفقود</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group"><label class="ast-label">الرقم التسلسلي</label><input type="text" name="serial_number" id="edit_serial" class="form-control"></div>
                    <div class="form-group"><label class="ast-label">الموديل</label><input type="text" name="model" id="edit_model" class="form-control"></div>
                    <div class="form-group"><label class="ast-label">الشركة المصنعة</label><input type="text" name="manufacturer" id="edit_mfr" class="form-control"></div>
                    <div class="form-group">
                        <label class="ast-label">المسؤول</label>
                        <select name="assigned_to" id="edit_assigned" class="form-control">
                            <option value="">— غير محدد —</option>
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group"><label class="ast-label">الفرع</label>
                        <select name="branch_id" id="edit_branch" class="form-control"><option value="">— اختر —</option>
                            <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="ast-label">القسم</label>
                        <select name="department_id" id="edit_dept" class="form-control"><option value="">— اختر —</option>
                            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="ast-label">رقم الغرفة</label><input type="text" name="room_number" id="edit_room" class="form-control"></div>
                    <div class="row">
                        <div class="col-6"><div class="form-group"><label class="ast-label">تاريخ الشراء</label><input type="date" name="purchase_date" id="edit_purchase" class="form-control"></div></div>
                        <div class="col-6"><div class="form-group"><label class="ast-label">انتهاء الضمان</label><input type="date" name="warranty_expiry" id="edit_warranty" class="form-control"></div></div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4"><div class="form-group"><label class="ast-label">صورة جديدة (اختياري)</label><input type="file" name="photo" class="form-control-file" accept="image/*"></div></div>
                <div class="col-md-4"><div class="form-group"><label class="ast-label">سعر الشراء</label><div class="input-group"><input type="number" name="purchase_price" id="edit_price" class="form-control" step="0.01"><div class="input-group-append"><span class="input-group-text">ريال</span></div></div></div></div>
                <div class="col-md-4"><div class="form-group"><label class="ast-label">ملاحظات</label><textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea></div></div>
            </div>
        </div>
        <div class="modal-footer" style="background:#fafbfc">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border-radius:8px"><i class="fas fa-save ml-1"></i>حفظ التعديلات</button>
        </div>
    </form>
</div></div></div>

<!-- ══ مودال QR ══ -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered" style="max-width:360px">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header ast-modal-head">
        <h5 class="modal-title"><i class="fas fa-qrcode ml-2"></i>رمز QR للأصل</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <div class="modal-body text-center py-4">
        <div id="qrContainer" style="display:inline-block;padding:16px;background:#fff;border:2px solid #e2e8f0;border-radius:12px"></div>
        <div id="qrAssetName" style="font-weight:700;color:#334155;margin-top:12px;font-size:.9rem"></div>
        <div id="qrAssetCode" style="font-family:monospace;color:#64748b;font-size:.8rem"></div>
        <button onclick="printQR()" class="btn btn-sm mt-3" style="background:#1a5276;color:#fff;border-radius:8px">
            <i class="fas fa-print ml-1"></i>طباعة QR
        </button>
    </div>
</div></div></div>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- مولّد QR كود خفيف الوزن -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
$(document).ready(function() {
    /* DataTable */
    $('#assetsTable').DataTable({
        responsive:false,
        scrollX:false,
        autoWidth:false,
        order:[[0,'asc']],
        dom:"<'row mb-2'<'col-md-6'B><'col-md-6 text-left'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        columnDefs:[
            { orderable:false, targets:[1,4,6,7,8] }  /* لا ترتيب على: الصورة، المسؤول، الصيانة، الإجراءات */
        ],
        buttons:[
            {
                extend:'excelHtml5',
                text:'<i class="fas fa-file-excel ml-1"></i>إكسل',
                className:'btn btn-sm btn-outline-success',
                exportOptions:{ columns:[0,1,2,3,4,5,6,7] }   /* استثناء عمود الإجراءات */
            },
            {
                extend:'print',
                text:'<i class="fas fa-print ml-1"></i>طباعة',
                className:'btn btn-sm btn-outline-primary',
                exportOptions:{ columns:[0,1,2,3,4,5,6,7] },
                customize:function(w){$(w.document.body).css({direction:'rtl','text-align':'right','font-family':'Cairo,sans-serif'});}
            },
            {
                extend:'colvis',
                text:'<i class="fas fa-columns ml-1"></i>الأعمدة',
                className:'btn btn-sm btn-outline-secondary',
                columns:':not(:last-child)'  /* لا تُخفِ عمود الإجراءات من القائمة */
            }
        ],
        language:{
            search:'بحث شامل:',
            lengthMenu:'عرض _MENU_',
            info:'_START_–_END_ من _TOTAL_',
            paginate:{next:'التالي',previous:'السابق'},
            emptyTable:'<div class="text-center py-4"><i class="fas fa-cubes fa-2x text-muted mb-2 d-block"></i><span class="text-muted">لا توجد أصول مسجلة — ابدأ بإضافة أول جهاز</span></div>',
            zeroRecords:'لا توجد نتائج مطابقة للبحث'
        }
    });

    /* تعديل — ملء البيانات */
    $(document).on('click','.edit-btn',function(){
        var d=$(this).data();
        $('#edit_id').val(d.id);
        $('#edit_code').val(d.code);
        $('#edit_name').val(d.name);
        $('#edit_cat').val(d.cat);
        $('#edit_serial').val(d.serial);
        $('#edit_model').val(d.model);
        $('#edit_mfr').val(d.mfr);
        $('#edit_branch').val(d.branch||'');
        $('#edit_dept').val(d.dept||'');
        $('#edit_room').val(d.room);
        $('#edit_status').val(d.status);
        $('#edit_purchase').val(d.purchase);
        $('#edit_price').val(d.price);
        $('#edit_warranty').val(d.warranty);
        $('#edit_assigned').val(d.assigned||'');
        $('#edit_notes').val(d.notes);
        $('#edit_existing_photo').val(d.photo);
        $('#editAssetModal').modal('show');
    });
});

/* حذف */
function confirmDelete(id, name) {
    Swal.fire({title:'حذف "'+name+'"?',text:'لا يمكن التراجع',icon:'warning',showCancelButton:true,
        confirmButtonColor:'#dc2626',confirmButtonText:'نعم، احذف',cancelButtonText:'إلغاء'})
    .then(r=>{if(r.isConfirmed)window.location.href='?delete_id='+id;});
}

/* QR كود */
function showQR(code, name) {
    $('#qrContainer').empty();
    new QRCode(document.getElementById('qrContainer'), {
        text: window.location.origin + '/UltimatesolutionsCrm/admin/pages/tables/show-assets.php?search=' + code,
        width: 200, height: 200,
        colorDark: '#1a5276', colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });
    $('#qrAssetName').text(name);
    $('#qrAssetCode').text(code);
    $('#qrModal').modal('show');
}

function printQR() {
    var w = window.open('','_blank');
    w.document.write('<html dir="rtl"><head><title>QR - '+$('#qrAssetCode').text()+'</title></head><body style="text-align:center;font-family:Cairo,sans-serif;padding:40px">');
    w.document.write('<h3 style="color:#1a5276">'+$('#qrAssetName').text()+'</h3>');
    w.document.write($('#qrContainer').html());
    w.document.write('<p style="font-family:monospace;font-size:14px;margin-top:12px">'+$('#qrAssetCode').text()+'</p>');
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}
</script>
<?php include __DIR__ . '/../../print_header.php'; ?>
</body>
</html>
