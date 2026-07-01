<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$can_add = 0; 
if ($current_user_id) {
    $page_path = "pages/tables/assign-permissions.php"; 
    $stmt_check = $pdo->prepare("SELECT a.can_add FROM user_menu_access a JOIN sys_menu m ON a.menu_id = m.id WHERE a.user_id = ? AND m.link = ?");
    $stmt_check->execute([$current_user_id, $page_path]);
    $can_add = $stmt_check->fetchColumn() ?: 0;
}

$users = $pdo->query("SELECT id, full_name FROM sys_users ORDER BY full_name ASC")->fetchAll();
$permissions_list = $pdo->query("SELECT id as role_id, role_name FROM sys_roles")->fetchAll(PDO::FETCH_ASSOC);
$all_pages = $pdo->query("SELECT id, title, link FROM sys_menu ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_groups = $pdo->query("SELECT id, category_name FROM issue_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$doc_page_ids = [63, 65, 66, 67, 68];
$task_page_id = 28; // show-tasks.php
$task_perms = ['view_group_tasks','view_own_tasks'];

$assigned_permissions = [];
$assigned_pages = []; 
$assigned_groups = [];
$selected_user_id = $_GET['user_id'] ?? "";
$is_admin = false;

if ($selected_user_id != "") {
    $stmt1 = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id=?");
    $stmt1->execute([$selected_user_id]);
    $assigned_permissions = $stmt1->fetchAll(PDO::FETCH_COLUMN);

    $stmt2 = $pdo->prepare("SELECT menu_id, can_view, can_add, can_edit, can_delete, can_approve, can_archive, can_view_archive, can_view_group_tasks, can_view_own_tasks FROM user_menu_access WHERE user_id=?");
    $stmt2->execute([$selected_user_id]);
    $temp_pages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($temp_pages as $tp) {
        $assigned_pages[$tp['menu_id']] = $tp;
    }

    $stmt_g = $pdo->prepare("SELECT category_id FROM user_category_access WHERE user_id = ?");
    $stmt_g->execute([$selected_user_id]);
    $assigned_groups = $stmt_g->fetchAll(PDO::FETCH_COLUMN);

    foreach ($permissions_list as $p) {
        if (in_array($p['role_id'], $assigned_permissions)) {
            $roleName = trim($p['role_name']);
            if (in_array($roleName, ['ادمن الأساسي', 'ادمن الفرعي', 'mainadmin', 'subadmin', 'مدير النظام', 'مشرف عمليات'])) {
                $is_admin = true;
                break;
            }
        }
    }
}

if (isset($_POST['save_all_settings'])) {
    if ($can_add == 0) {
        $_SESSION['error_msg'] = "عذراً، لا تملك صلاحية تعديل الصلاحيات.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?user_id=" . $_POST['user_id']);
        exit;
    }

    try {
        $user_id = $_POST['user_id'];
        $selected_roles = $_POST['roles'] ?? [];
        $page_perms = $_POST['page_perms'] ?? []; 
        $selected_groups = $_POST['problem_groups'] ?? [];

        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM user_category_access WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM user_menu_access WHERE user_id = ?")->execute([$user_id]);

        $stmt_r = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        foreach ($selected_roles as $r_id) { $stmt_r->execute([$user_id, $r_id]); }

        $stmt_g_ins = $pdo->prepare("INSERT INTO user_category_access (user_id, category_id) VALUES (?, ?)");
        foreach ($selected_groups as $g_id) { $stmt_g_ins->execute([$user_id, $g_id]); }

        $stmt_p = $pdo->prepare("INSERT INTO user_menu_access (user_id, menu_id, can_view, can_add, can_edit, can_delete, can_approve, can_archive, can_view_archive, can_view_group_tasks, can_view_own_tasks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $new_is_admin = false;
        $admin_roles = ['ادمن الأساسي', 'ادمن الفرعي', 'mainadmin', 'subadmin', 'مدير النظام', 'مشرف عمليات'];
        foreach ($permissions_list as $pl) {
            if (in_array($pl['role_id'], $selected_roles) && in_array(trim($pl['role_name']), $admin_roles)) {
                $new_is_admin = true; break;
            }
        }

        if ($new_is_admin) {
            foreach ($all_pages as $pg) { $stmt_p->execute([$user_id, $pg['id'], 1, 1, 1, 1, 1, 1, 1, 1, 1]); }
        } else {
            foreach ($page_perms as $m_id => $actions) {
                $v  = isset($actions['can_view']) ? 1 : 0;
                $a  = isset($actions['can_add']) ? 1 : 0;
                $e  = isset($actions['can_edit']) ? 1 : 0;
                $d  = isset($actions['can_delete']) ? 1 : 0;
                $ap = isset($actions['can_approve']) ? 1 : 0;
                $ar = isset($actions['can_archive']) ? 1 : 0;
                $va = isset($actions['can_view_archive']) ? 1 : 0;
                $vgt = isset($actions['can_view_group_tasks']) ? 1 : 0;
                $vot = isset($actions['can_view_own_tasks']) ? 1 : 0;
                if ($v || $a || $e || $d || $ap || $ar || $va || $vgt || $vot) { $stmt_p->execute([$user_id, $m_id, $v, $a, $e, $d, $ap, $ar, $va, $vgt, $vot]); }
            }
        }

        $pdo->commit();
        log_action($pdo, 'update', 'صلاحية', $user_id, [], [
            'role_ids' => $selected_roles,
            'group_ids' => $selected_groups,
            'page_permissions' => $page_perms
        ]);
        $_SESSION['show_success'] = true;
        header("Location: " . $_SERVER['PHP_SELF'] . "?user_id=" . $user_id);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "حدث خطأ أثناء الحفظ: " . $e->getMessage();
    }
}

// جلب إعدادات النظام للطباعة
$settingsStmt = $pdo->query("SELECT * FROM sys_settings LIMIT 1");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$company_name = $settings['system_name'] ?? 'إدارة النظام';
$company_logo = $settings['system_logo'] ?? 'logo.png';
$company_address = $settings['address'] ?? 'المملكة العربية السعودية';

$logo_path_internal = __DIR__ . '/../../dist/img/' . $company_logo;
$logo_data_uri = '';
if (file_exists($logo_path_internal)) {
    $logo_data = file_get_contents($logo_path_internal);
    $logo_base64 = base64_encode($logo_data);
    $logo_data_uri = 'data:image/png;base64,' . $logo_base64;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تعيين الصلاحيات | النظام</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
:root { --p:#1e4b8a; --p-lt:#e8eef8; }
body { direction:rtl; text-align:right; font-family:'Source Sans Pro',Arial,sans-serif; background:#f4f6f9; }

/* ── ترويسة ── */

/* ── خطوات ── */
.step-badge { width:28px; height:28px; border-radius:50%; background:var(--p); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; flex-shrink:0; }
.section-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); margin-bottom:18px; overflow:hidden; }
.sc-head { padding:13px 18px; border-bottom:2px solid var(--p); background:var(--p-lt); display:flex; align-items:center; gap:10px; justify-content:space-between; }
.sc-head h6 { margin:0; color:var(--p); font-weight:700; font-size:.95rem; display:flex; align-items:center; gap:8px; }
.sc-body { padding:18px; }

/* ── اختيار المستخدم ── */
.user-select-wrap { position:relative; }
.user-select-wrap select { width:100%; border:2px solid #dde3f0; border-radius:10px; padding:11px 44px 11px 14px; font-size:.92rem; appearance:none; background:#fff; cursor:pointer; transition:border-color .2s; }
.user-select-wrap select:focus { outline:none; border-color:var(--p); box-shadow:0 0 0 3px rgba(30,75,138,.1); }
.user-select-wrap .sel-arrow { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#aaa; pointer-events:none; }

/* ── user info card (بعد الاختيار) ── */
.user-info-card { display:flex; align-items:center; gap:14px; background:#f0f6ff; border:1px solid #c8d8f8; border-radius:10px; padding:12px 16px; margin-top:14px; }
.user-avatar { width:42px; height:42px; border-radius:50%; background:var(--p); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.user-info-card .ui-name { font-weight:700; color:#222; font-size:.92rem; }
.user-info-card .ui-id { font-size:.75rem; color:#888; }

/* ── أدوار (chips) ── */
.role-chip { display:inline-flex; align-items:center; gap:8px; border:2px solid #dde3f0; border-radius:10px; padding:8px 14px; cursor:pointer; transition:all .18s; user-select:none; margin:4px; background:#fff; }
.role-chip:hover { border-color:var(--p); background:var(--p-lt); }
.role-chip input[type="checkbox"] { display:none; }
.role-chip.selected { border-color:var(--p); background:var(--p); color:#fff; }
.role-chip.selected .rc-icon { color:#fff; }
.rc-icon { color:var(--p); font-size:.85rem; transition:color .18s; }
.rc-label { font-size:.84rem; font-weight:600; transition:color .18s; }

/* ── تصنيفات (toggle cards) ── */
.cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; }
.cat-chip { border:2px solid #dde3f0; border-radius:10px; padding:10px 14px; cursor:pointer; transition:all .18s; display:flex; align-items:center; gap:8px; background:#fff; user-select:none; }
.cat-chip:hover { border-color:#f39c12; background:#fff9f0; }
.cat-chip input[type="checkbox"] { display:none; }
.cat-chip.cat-active { border-color:#f39c12; background:#fff3cd; }
.cat-chip .cc-icon { color:#f39c12; font-size:.85rem; }
.cat-chip .cc-label { font-size:.82rem; font-weight:600; color:#444; }
.cat-chip.cat-active .cc-label { color:#856404; }

/* ── جدول الصفحات ── */
#pagesTable { width:100%; border-collapse:separate; border-spacing:0; }
#pagesTable thead tr th { padding:10px 8px; font-size:.75rem; font-weight:700; white-space:nowrap; vertical-align:middle; text-align:center; border-bottom:2px solid #eee; }
#pagesTable tbody tr { border-bottom:1px solid #f0f2f5; transition:background .12s; }
#pagesTable tbody tr:hover { background:#f8f9ff; }
#pagesTable td { padding:8px 8px; vertical-align:middle; text-align:center; }
.page-name-cell { text-align:right!important; padding-right:16px!important; font-weight:600; font-size:.85rem; }

/* رؤوس أعمدة ملونة */
.th-view   { color:#3498db; } .th-add    { color:#27ae60; } .th-edit   { color:#f39c12; }
.th-delete { color:#e74c3c; } .th-approve{ color:#9b59b6; } .th-archive{ color:#1abc9c; }
.th-varch  { color:#7f8c8d; } .th-vgt    { color:#e67e22; } .th-vot    { color:#2c3e50; }

/* switches ملونة */
.sw-view   .custom-control-input:checked ~ .custom-control-label::before { background:#3498db!important; border-color:#3498db!important; }
.sw-add    .custom-control-input:checked ~ .custom-control-label::before { background:#27ae60!important; border-color:#27ae60!important; }
.sw-edit   .custom-control-input:checked ~ .custom-control-label::before { background:#f39c12!important; border-color:#f39c12!important; }
.sw-delete .custom-control-input:checked ~ .custom-control-label::before { background:#e74c3c!important; border-color:#e74c3c!important; }
.sw-approve.custom-control-input:checked ~ .custom-control-label::before { background:#9b59b6!important; border-color:#9b59b6!important; }
.sw-archive.custom-control-input:checked ~ .custom-control-label::before { background:#1abc9c!important; border-color:#1abc9c!important; }
.sw-varch  .custom-control-input:checked ~ .custom-control-label::before { background:#7f8c8d!important; border-color:#7f8c8d!important; }
.sw-vgt    .custom-control-input:checked ~ .custom-control-label::before { background:#e67e22!important; border-color:#e67e22!important; }
.sw-vot    .custom-control-input:checked ~ .custom-control-label::before { background:#2c3e50!important; border-color:#2c3e50!important; }

/* toggle ALL row */
.th-all-row th { background:#f8f9fa; }
.toggle-col-btn { font-size:.7rem; padding:2px 8px; border-radius:6px; border:1px solid #dde; background:#fff; cursor:pointer; color:#666; transition:all .12s; display:block; margin:0 auto 4px; }
.toggle-col-btn:hover { background:var(--p); color:#fff; border-color:var(--p); }

/* search DataTable */
.dataTables_filter { display:flex; align-items:center; gap:8px; }
.dataTables_filter label { margin:0; font-weight:600; font-size:.84rem; color:#555; }
.dataTables_filter input { border:1.5px solid #dde3f0; border-radius:8px; padding:6px 12px; font-size:.84rem; }
.dataTables_filter input:focus { outline:none; border-color:var(--p); }

/* زر الحفظ */
.save-bar { background:linear-gradient(135deg,#1e4b8a,#0d2f5e); border-radius:14px; padding:16px 22px; display:flex; align-items:center; justify-content:space-between; }
.save-bar .sb-text { color:#fff; font-size:.88rem; opacity:.85; }
.btn-save-big { background:#27ae60; color:#fff; border:none; border-radius:10px; padding:11px 28px; font-size:.95rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px; transition:background .18s,transform .1s; }
.btn-save-big:hover { background:#1e8449; transform:translateY(-1px); }

/* admin shield badge */
.admin-shield { background:#ffc107; color:#856404; border-radius:8px; padding:5px 12px; font-size:.78rem; font-weight:700; display:inline-flex; align-items:center; gap:6px; }

/* حالة فارغة */
.empty-user { text-align:center; padding:40px 20px; color:#bbb; }
.empty-user i { font-size:3rem; display:block; margin-bottom:12px; }

/* ── غلاف جدول الصفحات مع scroll ── */
.table-perms-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    position: relative;
}
/* شارة scroll hint على الجوال */
.scroll-hint {
    display: none;
    font-size: .72rem;
    color: #888;
    padding: 6px 16px 2px;
    text-align: center;
}

/* ══════════════════════════════════════════
   Mobile — شاشات أقل من 768px
══════════════════════════════════════════ */
@media (max-width: 767px) {

    /* ─ ترويسة الصفحة ─ */

    /* ─ رأس الأقسام ─ */
    .sc-head { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px 14px; }
    .sc-head h6 { font-size: .88rem; }
    .sc-body { padding: 14px; }

    /* ─ اختيار المستخدم ─ */
    .user-select-wrap select { font-size: .85rem; padding: 10px 40px 10px 12px; }
    .user-info-card { padding: 10px 12px; gap: 10px; }
    .user-avatar { width: 36px; height: 36px; font-size: .95rem; }

    /* ─ Role chips: تملأ العرض ─ */
    .role-chip { width: 100%; margin: 3px 0; padding: 10px 12px; border-radius: 8px; }

    /* ─ تصنيفات: عمودان ─ */
    .cat-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .cat-chip { padding: 8px 10px; font-size: .78rem; }

    /* ─ أزرار "الكل": أصغر حجماً ─ */
    .toggle-col-btn { font-size: .62rem; padding: 1px 5px; }

    /* ─ شارة الـ scroll ─ */
    .scroll-hint { display: block; }

    /* ─ جدول الصلاحيات: sticky column + scroll أفقي ─ */
    .table-perms-wrap { margin: 0; border-radius: 0 0 14px 14px; }
    #pagesTable { min-width: 560px; }

    /* عمود اسم الصفحة ثابت على اليمين (RTL) */
    #pagesTable tbody td.page-name-cell {
        position: sticky;
        right: 0;
        background: #fff;
        z-index: 2;
        box-shadow: -3px 0 6px rgba(0,0,0,.08);
        min-width: 110px;
        max-width: 130px;
        font-size: .78rem;
        white-space: normal;
        word-break: break-word;
    }
    #pagesTable tbody tr:hover td.page-name-cell { background: #f8f9ff; }

    /* رأس عمود الاسم ثابت أيضاً */
    #pagesTable thead th:first-child,
    #pagesTable .th-all-row th:first-child {
        position: sticky;
        right: 0;
        background: #f8f9fa;
        z-index: 3;
    }

    /* تقليص أعمدة الـ switches */
    #pagesTable thead th:not(:first-child) { padding: 6px 4px; min-width: 52px; }
    #pagesTable tbody td:not(.page-name-cell) { padding: 6px 4px; min-width: 52px; }

    /* تصغير الـ switches */
    .custom-control.custom-switch { padding-left: 2.2rem; }
    .custom-switch .custom-control-label::before { width: 1.6rem; height: .88rem; border-radius: .44rem; }
    .custom-switch .custom-control-label::after { width: calc(.88rem - 4px); height: calc(.88rem - 4px); border-radius: calc(.44rem - 2px); }
    .custom-control-input:checked ~ .custom-control-label::after { transform: translateX(-0.7rem); }

    /* ─ شريط الحفظ: عمودي ─ */
    .save-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 14px;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .save-bar .sb-text { text-align: center; }
    .btn-save-big { width: 100%; justify-content: center; padding: 12px; font-size: .9rem; }

    /* ─ بحث DataTable ─ */
    .dataTables_filter { flex-direction: column; align-items: stretch; gap: 6px; padding: 10px 14px; }
    .dataTables_filter input { width: 100%; }

    /* ─ admin shield ─ */
    .admin-shield { font-size: .72rem; padding: 4px 10px; }
}

/* ── Tablet (768-1024) ── */
@media (min-width: 768px) and (max-width: 1024px) {
    .cat-grid { grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); }
    #pagesTable { min-width: 700px; }
    .table-perms-wrap { overflow-x: auto; }
    .save-bar { padding: 14px 18px; }
}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">
<section class="content-header">
    <div class="container-fluid">
        <div class="page-banner">
            <div>
                <h4><i class="fas fa-user-shield ml-2"></i>تعيين صلاحيات المستخدمين</h4>
                <small style="opacity:.75">اختر مستخدماً لتحديد أدواره وصلاحياته التفصيلية</small>
            </div>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                <li class="breadcrumb-item"><a href="#">صلاحيات المستخدمين</a></li>
                <li class="breadcrumb-item active">تعيين الصلاحيات</li>
            </ol>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid">
<form method="POST" id="permForm">

    <!-- ══ الخطوة 1: اختيار المستخدم والأدوار ══ -->
    <div class="section-card">
        <div class="sc-head">
            <h6>
                <span class="step-badge">1</span>
                <i class="fas fa-user"></i>اختيار المستخدم والأدوار
            </h6>
            <?php if ($selected_user_id && $is_admin): ?>
            <span class="admin-shield"><i class="fas fa-user-shield"></i>صلاحية كاملة (Admin)</span>
            <?php endif; ?>
        </div>
        <div class="sc-body">
            <div class="row">
                <div class="col-md-6">
                    <label style="font-weight:600;font-size:.84rem;color:#555;margin-bottom:6px;display:block">المستخدم <span style="color:#dc3545">*</span></label>
                    <div class="user-select-wrap">
                        <select name="user_id" onchange="changeUser(this.value)">
                            <option value="">— اختر مستخدماً —</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= ($selected_user_id==$user['id'])?'selected':'' ?>>
                                <?= htmlspecialchars($user['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sel-arrow"><i class="fas fa-chevron-down"></i></span>
                    </div>
                    <?php if ($selected_user_id): ?>
                    <div class="user-info-card">
                        <div class="user-avatar"><i class="fas fa-user"></i></div>
                        <div>
                            <div class="ui-name">
                                <?php foreach($users as $u) if($u['id']==$selected_user_id){ echo htmlspecialchars($u['full_name']); break; } ?>
                            </div>
                            <div class="ui-id">المعرف: #<?= $selected_user_id ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($selected_user_id): ?>
                <div class="col-md-6">
                    <label style="font-weight:600;font-size:.84rem;color:#555;margin-bottom:10px;display:block">
                        <i class="fas fa-shield-alt ml-1 text-primary"></i>الأدوار والصلاحيات العامة
                    </label>
                    <div>
                        <?php foreach ($permissions_list as $perm):
                            $isChecked = in_array($perm['role_id'], $assigned_permissions);
                        ?>
                        <label class="role-chip <?= $isChecked?'selected':'' ?>" for="role<?= $perm['role_id'] ?>">
                            <input type="checkbox" name="roles[]" value="<?= $perm['role_id'] ?>" id="role<?= $perm['role_id'] ?>" <?= $isChecked?'checked':'' ?>>
                            <i class="fas fa-user-tag rc-icon"></i>
                            <span class="rc-label"><?= htmlspecialchars($perm['role_name']) ?></span>
                            <?php if ($isChecked): ?><i class="fas fa-check-circle" style="font-size:.75rem;opacity:.7"></i><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="col-md-6 d-flex align-items-center">
                    <div class="empty-user w-100">
                        <i class="fas fa-user-circle"></i>
                        <p class="mb-0" style="font-size:.88rem">اختر مستخدماً من القائمة لتحديد صلاحياته</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($selected_user_id):
        $groupTaskStats = [];
        $userTaskCount  = 0;
        try {
            $gts = $pdo->query("
                SELECT g.id, g.category_name, g.color,
                       COUNT(DISTINCT r.id) AS ticket_count,
                       COUNT(DISTINCT w.id) AS task_count
                FROM issue_categories g
                LEFT JOIN tickets r ON r.category_id = g.id
                LEFT JOIN work_orders w ON w.ticket_id = r.id
                GROUP BY g.id, g.category_name, g.color
                ORDER BY g.category_name
            ");
            $groupTaskStats = $gts->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
        try {
            $uts = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE assigned_to = ?");
            $uts->execute([$selected_user_id]);
            $userTaskCount = (int)$uts->fetchColumn();
        } catch (PDOException $e) {}
    ?>

    <!-- ══ الخطوة 2: تصنيفات المشاكل ══ -->
    <div class="section-card">
        <div class="sc-head">
            <h6><span class="step-badge">2</span><i class="fas fa-tags"></i>تصنيفات المشاكل المتاحة</h6>
            <small style="color:#888;font-size:.78rem">اختر التصنيفات التي يمكن لهذا المستخدم العمل عليها</small>
        </div>
        <div class="sc-body">
            <?php if (empty($all_groups)): ?>
            <p class="text-muted text-center mb-0">لا توجد تصنيفات مسجلة</p>
            <?php else: ?>
            <div class="cat-grid">
                <?php foreach ($all_groups as $group):
                    $isActive = in_array($group['id'], $assigned_groups);
                    $stat = array_filter($groupTaskStats, fn($s) => (int)$s['id'] === (int)$group['id']);
                    $stat = $stat ? current($stat) : null;
                ?>
                <label class="cat-chip <?= $isActive?'cat-active':'' ?>" for="group<?= $group['id'] ?>">
                    <input type="checkbox" name="problem_groups[]" value="<?= $group['id'] ?>" id="group<?= $group['id'] ?>" <?= $isActive?'checked':'' ?>>
                    <i class="fas fa-tag cc-icon"></i>
                    <span class="cc-label"><?= htmlspecialchars($group['category_name']) ?></span>
                    <?php if ($stat): ?>
                    <span style="font-size:.62rem;color:#64748b;background:#f1f5f9;border-radius:6px;padding:1px 6px;margin-right:4px;white-space:nowrap">
                        <?= (int)$stat['ticket_count'] ?> تذاكر | <?= (int)$stat['task_count'] ?> مهام
                    </span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                <span style="font-size:.8rem;color:#1a3a5c;font-weight:600">
                    <i class="fas fa-tasks ml-1 text-primary"></i>إجمالي المهام المسندة لهذا المستخدم:
                    <strong style="font-size:1.1rem;color:#1a5276"><?= $userTaskCount ?></strong>
                </span>
                <?php if ($userTaskCount > 0): ?>
                <a href="show-tasks.php?user_id=<?= $selected_user_id ?>" target="_blank" style="font-size:.75rem;color:#2980b9">
                    <i class="fas fa-external-link-alt ml-1"></i>عرض المهام
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ الخطوة 3: صلاحيات الصفحات ══ -->
    <div class="section-card">
        <div class="sc-head">
            <h6><span class="step-badge">3</span><i class="fas fa-file-alt"></i>صلاحيات الصفحات التفصيلية</h6>
            <div style="display:flex;align-items:center;gap:10px">
                <div id="searchWrapper"></div>
                <?php if ($is_admin): ?>
                <span class="admin-shield"><i class="fas fa-lock"></i>مشفوع (Admin)</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="sc-body p-0">
            <!-- ── أزرار المنح/السحب الشامل ── -->
            <?php if (!$is_admin): ?>
            <div style="padding:10px 16px;background:#fafbfc;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span style="font-size:.78rem;font-weight:700;color:#64748b"><i class="fas fa-list-check ml-1"></i>جميع الصلاحيات:</span>
                <button type="button" id="grantAllBtn"
                    style="background:linear-gradient(135deg,#065f46,#059669);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
                    <i class="fas fa-check-double"></i>منح الكل
                </button>
                <button type="button" id="revokeAllBtn"
                    style="background:linear-gradient(135deg,#991b1b,#dc2626);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
                    <i class="fas fa-times-circle"></i>سحب الكل
                </button>
                <span style="font-size:.72rem;color:#94a3b8">يطبق على جميع الصفوف والأعمدة المتاحة</span>
            </div>
            <?php endif; ?>
            <p class="scroll-hint"><i class="fas fa-arrows-alt-h ml-1"></i>اسحب يساراً لرؤية باقي الصلاحيات</p>
            <div class="table-perms-wrap">
                <table id="pagesTable" class="table table-hover">
                    <thead>
                        <!-- صف تفعيل العمود بالكامل -->
                        <tr class="th-all-row">
                            <th style="min-width:180px"></th>
                            <?php
                            $cols = [
                                'view'=>['th-view','fa-eye','استعراض','sw-view'],
                                'add'=>['th-add','fa-plus','إضافة','sw-add'],
                                'edit'=>['th-edit','fa-edit','تعديل','sw-edit'],
                                'delete'=>['th-delete','fa-trash','حذف','sw-delete'],
                                'approve'=>['th-approve','fa-check','اعتماد','sw-approve'],
                                'archive'=>['th-archive','fa-archive','أرشفة','sw-archive'],
                                'view_archive'=>['th-varch','fa-folder-open','عرض الأرشيف','sw-varch'],
                                'view_group_tasks'=>['th-vgt','fa-layer-group','مهام المجموعة','sw-vgt'],
                                'view_own_tasks'=>['th-vot','fa-user-check','مهامي فقط','sw-vot'],
                            ];
                            $task_perm_keys = ['view_group_tasks','view_own_tasks'];
                            foreach ($cols as $act => [$cls,$icon,$label,$sw]): ?>
                            <th class="<?= $cls ?>">
                                <button type="button" class="toggle-col-btn" data-col="<?= $act ?>" title="تفعيل/تعطيل الكل">
                                    <i class="fas fa-toggle-on ml-1"></i>الكل
                                </button>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                        <!-- رأس الأعمدة الملون -->
                        <tr>
                            <th class="text-right" style="padding-right:16px">اسم الصفحة</th>
                            <?php foreach ($cols as $act => [$cls,$icon,$label,$sw]): ?>
                            <th class="<?= $cls ?>">
                                <i class="fas <?= $icon ?> mb-1" style="display:block;font-size:.9rem"></i>
                                <span style="font-size:.72rem"><?= $label ?></span>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_pages as $page):
                            $p_id = $page['id'];
                            $p = $assigned_pages[$p_id] ?? [];
                            $isDocPage = in_array($p_id, $doc_page_ids);
                        ?>
                        <tr>
                            <td class="page-name-cell" data-label="">
                                <?= htmlspecialchars($page['title']) ?>
                                <?php if ($page['parent_id'] ?? null): ?>
                                <small class="text-muted d-block" style="font-size:.7rem;font-weight:normal"><code><?= htmlspecialchars($page['link'] ?? '') ?></code></small>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($cols as $act => [$cls,$icon,$label,$sw]):
                                $field = "can_" . $act;
                                $isExtra = in_array($act, ['approve','archive','view_archive']);
                                $isTaskPerm = in_array($act, $task_perm_keys);
                                if (!$isDocPage && $isExtra) {
                                    $checked = ''; $disabled = 'disabled';
                                } elseif (!in_array($p_id, [$task_page_id]) && $isTaskPerm) {
                                    $checked = ''; $disabled = 'disabled';
                                } elseif ($is_admin) {
                                    $checked = 'checked'; $disabled = 'disabled';
                                } else {
                                    $checked = !empty($p[$field]) ? 'checked' : ''; $disabled = '';
                                }
                            ?>
                            <td data-label="<?= $label ?>" class="<?= ($disabled && !$is_admin)?'text-muted':'' ?>">
                                <?php if ($disabled && !$is_admin): ?>
                                <i class="fas fa-minus text-muted" style="opacity:.3"></i>
                                <?php else: ?>
                                <div class="custom-control custom-switch <?= $sw ?>">
                                    <input type="checkbox"
                                           name="page_perms[<?= $p_id ?>][<?= $field ?>]"
                                           class="custom-control-input page-switch col-toggle-<?= $act ?>"
                                           id="sw_<?= $act.$p_id ?>"
                                           <?= $checked ?> <?= $disabled ?>>
                                    <label class="custom-control-label" for="sw_<?= $act.$p_id ?>"></label>
                                </div>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- /table-perms-wrap -->
        </div>
    </div>

    <!-- ══ شريط الحفظ ══ -->
    <?php if ($can_add == 1): ?>
    <div class="save-bar mb-4">
        <div class="sb-text">
            <i class="fas fa-info-circle ml-1"></i>
            تأكد من مراجعة جميع الصلاحيات قبل الحفظ
        </div>
        <button type="submit" name="save_all_settings" class="btn-save-big">
            <i class="fas fa-save"></i>حفظ كافة الإعدادات
        </button>
    </div>
    <?php endif; ?>

    <?php endif; // end if selected_user ?>
</form>
</div>
</section>
</div>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function changeUser(val) {
    if (val !== "") {
        Swal.fire({ title:'جاري التحميل...', allowOutsideClick:false, didOpen:()=>{ Swal.showLoading(); } });
        window.location.href = '?user_id=' + val;
    }
}

$(document).ready(function() {

    // ── Role chips: تفعيل/تعطيل بصري ──────────────────────────────
    $('.role-chip').on('click', function() {
        var $input = $(this).find('input[type="checkbox"]');
        var checked = $input.prop('checked');
        $(this).toggleClass('selected', checked);
    });
    // تزامن الحالة الأولية
    $('.role-chip input:checked').closest('.role-chip').addClass('selected');

    // ── Category chips ──────────────────────────────────────────────
    $('.cat-chip').on('click', function() {
        var $input = $(this).find('input[type="checkbox"]');
        $(this).toggleClass('cat-active', $input.prop('checked'));
    });

    // ── تفعيل/تعطيل عمود كامل ──
    $(document).on('click', '.toggle-col-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var col = $(this).data('col');
        // البحث في كل الـ DOM بما في ذلك الصفوف المخفية
        var $switches = $('input[class*="col-toggle-' + col + '"]').not(':disabled');
        if ($switches.length === 0) return;
        var allChecked = ($switches.filter(':checked').length === $switches.length);
        // إذا كل الخانات محددة → اسحب الكل، وإلا → منح الكل
        $switches.prop('checked', !allChecked).trigger('change');
        // تغذية راجعة بصرية للزر
        if (!allChecked) {
            $(this).css({ background:'var(--p,#1a5276)', color:'#fff', borderColor:'var(--p,#1a5276)' });
        } else {
            $(this).css({ background:'', color:'', borderColor:'' });
        }
    });

    // ── منح الكل ──────────────────────────────────────────────────────
    $('#grantAllBtn').on('click', function() {
        var $all = $('.page-switch').not(':disabled');
        if ($all.length === 0) return;
        $all.prop('checked', true).trigger('change');
        // تحديث أزرار الأعمدة بصرياً
        $('.toggle-col-btn').css({ background:'var(--p,#1a5276)', color:'#fff', borderColor:'var(--p,#1a5276)' });
        // رسالة تأكيد
        var count = $all.length;
        $(this).html('<i class="fas fa-check"></i> تم منح ' + count + ' صلاحية');
        setTimeout(function() {
            $('#grantAllBtn').html('<i class="fas fa-check-double"></i>منح الكل');
        }, 2000);
    });

    // ── سحب الكل ──────────────────────────────────────────────────────
    $('#revokeAllBtn').on('click', function() {
        var $all = $('.page-switch').not(':disabled');
        if ($all.length === 0) return;
        $all.prop('checked', false).trigger('change');
        // تحديث أزرار الأعمدة بصرياً
        $('.toggle-col-btn').css({ background:'', color:'', borderColor:'' });
        // رسالة تأكيد
        $(this).html('<i class="fas fa-check"></i> تم سحب الكل');
        setTimeout(function() {
            $('#revokeAllBtn').html('<i class="fas fa-times-circle"></i>سحب الكل');
        }, 2000);
    });

    // تحديث حالة الأزرار عند تحميل الصفحة
    function syncColBtns() {
        $('.toggle-col-btn').each(function() {
            var col = $(this).data('col');
            var $sw = $('input.col-toggle-' + col).not(':disabled');
            if ($sw.length > 0 && $sw.filter(':checked').length === $sw.length) {
                $(this).css({ background:'var(--p)', color:'#fff', borderColor:'var(--p)' });
            }
        });
    }
    syncColBtns();

    // ── DataTable: بحث فقط ─────────────────────────────────────────
    if ($('#pagesTable').length) {
        $('#pagesTable').DataTable({
            paging:   false,
            ordering: false,
            info:     false,
            dom: "<'row mb-2'<'col-12'f>><'row'<'col-12'tr>>",
            language: { search:'', searchPlaceholder:'🔍  بحث في الصفحات...', emptyTable:'لا توجد صفحات' }
        });
        $('.dataTables_filter').appendTo('#searchWrapper');
    }

    // ── حفظ ────────────────────────────────────────────────────────
    $('#permForm').on('submit', function() {
        $('.page-switch:disabled').prop('disabled', false);
        Swal.fire({ title:'جاري حفظ الصلاحيات...', allowOutsideClick:false, didOpen:()=>{ Swal.showLoading(); } });
    });

    // ── رسائل ──────────────────────────────────────────────────────
    <?php if (isset($_SESSION['show_success'])): ?>
    Swal.fire({ icon:'success', title:'تم الحفظ!', text:'تم تحديث كافة الصلاحيات بنجاح', timer:2500, showConfirmButton:false, timerProgressBar:true });
    <?php unset($_SESSION['show_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
    Swal.fire({ icon:'error', title:'فشل الحفظ', text:'<?= addslashes($_SESSION['error_msg']) ?>' });
    <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>

    if (sessionStorage.getItem('wasl_fullscreen') === 'true') {
        $('body').addClass('sidebar-collapse wasl-fullscreen');
    }
});
</script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
