<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

$error_msg = "";
$success_msg = "";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-sidebar.php"; 

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);
$current_page_id = $menu_item['id'] ?? 0;

$can_add = 0;
$can_delete = 0;
$can_edit = 0;

if ($current_page_id > 0) {
    $accessSql = "SELECT can_add, can_delete, can_edit FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_add = $permissions['can_add'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
    $can_edit = $permissions['can_edit'] ?? 0;
}

// معالجة الإرسال
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_menu'])) {
    try {
        if ($can_add == 0) {
            throw new Exception("عذراً، لا تملك صلاحية إضافة عناصر جديدة!");
        }

        $title       = trim($_POST['title']);
        $parent_id   = (int)$_POST['parent_id'];
        $permission  = !empty($_POST['permission_key']) ? $_POST['permission_key'] : NULL;
        $sort_order  = (int)$_POST['sort_order'];
        $link        = trim($_POST['link']);

        if (empty($link) && $parent_id == 0) { $link = '#'; }
        $icon = !empty(trim($_POST['icon'])) ? trim($_POST['icon']) : ($parent_id == 0 ? 'fas fa-folder' : 'far fa-circle');

        if (empty($title) || empty($link)) {
            throw new Exception("اسم العنصر والرابط مطلوبان!");
        }

        $sql = "INSERT INTO sys_menu (title, icon, link, parent_id, permission_key, sort_order) VALUES (?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$title, $icon, $link, $parent_id, $permission, $sort_order]);

        $success_msg = "تم إضافة العنصر بنجاح!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// جلب البيانات
$perms = $pdo->query("SELECT perm_key, perm_name FROM sys_permissions")->fetchAll();
$parents = $pdo->query("SELECT id, title FROM sys_menu WHERE parent_id = 0 ORDER BY sort_order ASC")->fetchAll();
$all_menus = $pdo->query("SELECT m.*, p.title as parent_name 
                          FROM sys_menu m 
                          LEFT JOIN sys_menu p ON m.parent_id = p.id 
                          ORDER BY CASE WHEN m.parent_id = 0 THEN m.id ELSE m.parent_id END, m.sort_order ASC")->fetchAll();

$stmt_role = $pdo->prepare("SELECT r.role_code FROM user_roles u JOIN sys_roles r ON u.role_id = r.id WHERE u.user_id = ?");
$stmt_role->execute([$current_user_id]);
$is_main_admin = in_array('mainadmin', array_map('strtolower', $stmt_role->fetchAll(PDO::FETCH_COLUMN)));

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
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>إعداد القائمة الجانبية</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>

        .card-ticket {
            border: none;
            border-top: 4px solid var(--uni-primary);
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            border-radius: 10px;
        }
        .card-ticket .card-header {
            background: transparent;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-ticket .card-body { padding: 20px; }
        .card-ticket .card-footer {
            background: transparent;
            border-top: 1px solid #eee;
            padding: 15px 20px;
        }

        #menuTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #menuTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-weight: 600; border-radius: 20px; font-size: .8rem; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }

        .table-responsive-custom {
            display: block;
            width: 100%;
            overflow-x: auto;
        }

        /* ── شريط البحث المخصص ─────────────────────────────── */
        .search-bar {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-input-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
        }
        .search-input-wrap .si-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            pointer-events: none;
        }
        .search-input-wrap input {
            width: 100%;
            border: 1.5px solid #dde3f0;
            border-radius: 8px;
            padding: 8px 36px 8px 12px;
            font-size: .88rem;
            transition: border-color .2s, box-shadow .2s;
        }
        .search-input-wrap input:focus {
            outline: none;
            border-color: var(--uni-primary);
            box-shadow: 0 0 0 3px rgba(13,110,253,.1);
        }
        .search-input-wrap .si-clear {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #bbb;
            font-size: .8rem;
            display: none;
        }
        .search-input-wrap input:not(:placeholder-shown) ~ .si-clear { display: block; }
        .filter-select {
            border: 1.5px solid #dde3f0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: .85rem;
            min-width: 140px;
            background: #fff;
            cursor: pointer;
            transition: border-color .2s;
        }
        .filter-select:focus { outline: none; border-color: var(--uni-primary); }
        .btn-reset-search {
            border: 1.5px solid #dde3f0;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: .82rem;
            background: #fff;
            color: #666;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }
        .btn-reset-search:hover { background: #f0f4ff; border-color: var(--uni-primary); color: var(--uni-primary); }
        .match-count {
            font-size: .78rem;
            color: #888;
            white-space: nowrap;
        }
        .match-count strong { color: var(--uni-primary); }

        /* تمييز نتائج البحث */
        .hl { background: #fff3cd; border-radius: 2px; padding: 0 2px; }
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
                        <h4><i class="fas fa-list ml-2"></i> إعداد القائمة الجانبية</h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                            <li class="breadcrumb-item active">القائمة الجانبية</li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <?php if ($success_msg): ?>
                        <div class="alert alert-success alert-dismissible fade show"><?= $success_msg ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
                    <?php endif; ?>
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger alert-dismissible fade show"><?= $error_msg ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
                    <?php endif; ?>

                    <div class="card card-ticket">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-plus ml-2"></i> إضافة عنصر جديد</h5>
                        </div>
                        <form method="POST">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <label>اسم العنصر</label>
                                        <input type="text" name="title" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label>الأيقونة (مثلاً: fas fa-cog)</label>
                                        <input type="text" name="icon" class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label>الرابط</label>
                                        <input type="text" name="link" class="form-control" placeholder="page.php">
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-4">
                                        <label>يتبع لقسم</label>
                                        <select name="parent_id" class="form-control">
                                            <option value="0">قسم رئيسي</option>
                                            <?php foreach ($parents as $p): ?>
                                                <option value="<?= $p['id'] ?>"><?= $p['title'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label>الصلاحية المطلوبة</label>
                                        <select name="permission_key" class="form-control">
                                            <option value="">متاح للجميع</option>
                                            <?php foreach ($perms as $perm): ?>
                                                <option value="<?= $perm['perm_key'] ?>"><?= $perm['perm_name'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label>الترتيب (Sort)</label>
                                        <input type="number" name="sort_order" class="form-control" value="0">
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-left">
                                <?php if ($can_add == 1): ?>
                                    <button type="submit" name="add_menu" class="btn btn-primary"><i class="fas fa-save ml-1"></i> حفظ العنصر</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary" disabled><i class="fas fa-lock ml-1"></i> لا تملك صلاحية الإضافة</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="card card-ticket mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list ml-2"></i> قائمة العناصر الحالية</h5>
                            <span class="badge badge-primary badge-pill-custom" id="totalCount"><?= count($all_menus) ?> عنصر</span>
                        </div>
                        <div class="card-body pb-0">

                            <!-- ── شريط البحث ── -->
                            <div class="search-bar">
                                <!-- بحث نصي -->
                                <div class="search-input-wrap">
                                    <span class="si-icon"><i class="fas fa-search"></i></span>
                                    <input type="text" id="globalSearch" placeholder="ابحث بالعنوان أو الرابط أو الأيقونة...">
                                    <span class="si-clear" id="clearSearch" onclick="clearSearch()"><i class="fas fa-times"></i></span>
                                </div>

                                <!-- فلتر: نوع العنصر -->
                                <select class="filter-select" id="filterType">
                                    <option value="">كل الأنواع</option>
                                    <option value="parent">رئيسي فقط</option>
                                    <option value="child">فرعي فقط</option>
                                </select>

                                <!-- فلتر: القسم الأب -->
                                <select class="filter-select" id="filterParent">
                                    <option value="">كل الأقسام</option>
                                    <option value="رئيسي">رئيسي (بدون أب)</option>
                                    <?php foreach ($parents as $p): ?>
                                    <option value="<?= htmlspecialchars($p['title']) ?>"><?= htmlspecialchars($p['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- زر إعادة تعيين -->
                                <button class="btn-reset-search" onclick="resetFilters()">
                                    <i class="fas fa-undo ml-1"></i> إعادة تعيين
                                </button>

                                <!-- عداد النتائج -->
                                <span class="match-count" id="matchCount"></span>
                            </div>

                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive-custom">
                                <table id="menuTable" class="table table-bordered table-hover text-center" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>العنوان</th>
                                            <th>الأيقونة</th>
                                            <th>الرابط</th>
                                            <th>القسم الأب</th>
                                            <th>الصلاحية</th>
                                            <th>الترتيب</th>
                                            <th>عمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_menus as $menu): ?>
                                            <tr <?= $menu['parent_id'] == 0 ? 'style="background-color:#f8f9fa;font-weight:bold;"' : '' ?>>
                                                <td><?= $menu['id'] ?></td>
                                                <td><?= $menu['title'] ?></td>
                                                <td><i class="<?= $menu['icon'] ?>"></i></td>
                                                <td><code><?= $menu['link'] ?></code></td>
                                                <td><?= $menu['parent_name'] ?? '<span class="badge badge-primary badge-pill-custom">رئيسي</span>' ?></td>
                                                <td><span class="badge badge-warning badge-pill-custom"><?= $menu['permission_key'] ?></span></td>
                                                <td><?= $menu['sort_order'] ?></td>
                                                <td>
                                                    <?php if ($can_edit == 0 && $can_delete == 0): ?>
                                                        <span class="badge badge-light text-muted"><i class="fas fa-lock"></i> مقفل</span>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-center" style="gap:4px;">
                                                            <?php if ($can_edit == 1): ?>
                                                                <a href="edit-menu.php?id=<?= $menu['id'] ?>" class="btn btn-sm btn-warning btn-action" title="تعديل"><i class="fas fa-edit"></i></a>
                                                            <?php endif; ?>
                                                            <?php if ($can_delete == 1): ?>
                                                                <button type="button" class="btn btn-sm btn-danger btn-action delete-btn" data-id="<?= $menu['id'] ?>" data-title="<?= htmlspecialchars($menu['title']) ?>" title="حذف"><i class="fas fa-trash"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
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

        <script src="../../plugins/jquery/jquery.min.js"></script>
        <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="../../dist/js/adminlte.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <script>
        // ── متغيرات عامة ──────────────────────────────────────────────────
        var dtTable   = null;
        var _srchTimer;
        var TOTAL_ROWS = <?= count($all_menus) ?>;

        // يزيل وسوم HTML ويُعيد النص الصافي
        function _strip(html) {
            return (html || '').replace(/<[^>]*>/g, '').trim();
        }

        // ── دالة الفلترة الرئيسية ────────────────────────────────────────
        function applyFilters() {
            if (!dtTable) return;

            var q        = document.getElementById('globalSearch').value.toLowerCase().trim();
            var type     = document.getElementById('filterType').value;
            var parent   = document.getElementById('filterParent').value.trim();
            var parentLc = parent.toLowerCase();

            // ⚠️ مهم: نمسح المصفوفة في مكانها (splice) وليس بإنشاء مصفوفة جديدة (= [])
            // لأن DataTable يحتفظ بمرجع للمصفوفة الأصلية
            $.fn.dataTable.ext.search.splice(0, $.fn.dataTable.ext.search.length);

            // نضيف فلتراً واحداً يعالج كل شروط البحث
            $.fn.dataTable.ext.search.push(function(settings, data) {
                var title      = _strip(data[1]).toLowerCase();
                var iconHtml   = (data[2] || '').toLowerCase();
                var link       = _strip(data[3]).toLowerCase();
                var parentText = _strip(data[4]).toLowerCase();
                var isParentRow = (parentText === 'رئيسي' || parentText === '');

                // بحث نصي
                if (q && !title.includes(q) && !link.includes(q) && !iconHtml.includes(q)) return false;

                // فلتر النوع
                if (type === 'parent' && !isParentRow) return false;
                if (type === 'child'  &&  isParentRow) return false;

                // فلتر القسم الأب
                if (parentLc) {
                    if (parentLc === 'رئيسي' && !isParentRow) return false;
                    if (parentLc !== 'رئيسي' && !parentText.includes(parentLc)) return false;
                }

                return true;
            });

            dtTable.draw();
        }

        function updateMatchCount() {
            if (!dtTable) return;
            var info = dtTable.page.info();
            var el   = document.getElementById('matchCount');
            if (info.recordsDisplay < info.recordsTotal) {
                el.innerHTML = 'نتائج: <strong>' + info.recordsDisplay + '</strong> من ' + TOTAL_ROWS;
            } else {
                el.innerHTML = '<strong>' + TOTAL_ROWS + '</strong> عنصر';
            }
        }

        function clearSearch() {
            document.getElementById('globalSearch').value = '';
            applyFilters();
        }

        function resetFilters() {
            document.getElementById('globalSearch').value  = '';
            document.getElementById('filterType').value    = '';
            document.getElementById('filterParent').value  = '';
            applyFilters();
        }

        $(document).ready(function() {
            // معالجة رسائل النجاح من الرابط
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success')) {
                Swal.fire({
                    icon: 'success',
                    title: 'عملية ناجحة',
                    text: urlParams.get('success'),
                    confirmButtonText: 'موافق',
                    timer: 3000,
                    timerProgressBar: true,
                    direction: 'rtl'
                });
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            dtTable = $('#menuTable').DataTable({
                responsive: false,
                scrollX: true,
                autoWidth: false,
                order: [[0, 'asc']],
                dom: "<'row mb-2'<'col-md-6'B><'col-md-6 text-left'>>" +
                     "<'row'<'col-12'tr>>" +
                     "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
                buttons: [
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print ml-1"></i> طباعة',
                        className: 'btn btn-outline-primary btn-sm ml-1',
                        exportOptions: { columns: ':visible' },
                        customize: function(win) {
                            $(win.document.body)
                                .css('direction', 'rtl')
                                .css('text-align', 'right')
                                .css('font-family', 'Cairo, sans-serif');
                            $(win.document.body).find('table')
                                .addClass('table-bordered')
                                .css('width', '100%');
                            var header = '';
                            <?php if ($logo_data_uri): ?>
                            header += '<div style="text-align:center;margin-bottom:20px;">';
                            header += '<img src="<?= $logo_data_uri ?>" style="max-height:80px;" />';
                            <?php endif; ?>
                            header += '<h2 style="margin:10px 0 5px;color:#0d6efd;"><?= $company_name ?></h2>';
                            header += '<p style="color:#555;margin:0 0 20px;"><?= $company_address ?></p>';
                            header += '<hr style="border-top:2px solid #0d6efd;">';
                            header += '<h4 style="margin:15px 0;">القائمة الجانبية</h4>';
                            header += '</div>';
                            $(win.document.body).find('h1').remove();
                            $(win.document.body).prepend(header);
                        }
                    },
                    {
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel ml-1"></i> Excel',
                        className: 'btn btn-outline-success btn-sm ml-1',
                        exportOptions: { columns: ':visible' }
                    },
                    {
                        extend: 'colvis',
                        text: '<i class="fas fa-columns ml-1"></i> أعمدة',
                        className: 'btn btn-outline-secondary btn-sm',
                        postfixButtons: ['colvisRestore']
                    }
                ],
                language: {
                    search: 'بحث:',
                    lengthMenu: 'عرض _MENU_ سجلات',
                    info: '_START_ إلى _END_ من _TOTAL_',
                    infoEmpty: '0 سجل',
                    infoFiltered: '(من أصل _MAX_)',
                    paginate: { next: 'التالي', previous: 'السابق' },
                    emptyTable: 'لا توجد عناصر',
                    buttons: { print: 'طباعة', excel: 'Excel', colvis: 'أعمدة' }
                },
                columns: [
                    { title: '#' },
                    { title: 'العنوان' },
                    { title: 'الأيقونة' },
                    { title: 'الرابط' },
                    { title: 'القسم الأب' },
                    { title: 'الصلاحية' },
                    { title: 'الترتيب' },
                    { title: 'عمليات', orderable: false, searchable: false }
                ]
            });

            // ── ربط أحداث البحث ──────────────────────────────────────────
            // 1. البحث النصي: تلقائي مع تأخير 250ms
            $('#globalSearch').on('input', function() {
                clearTimeout(_srchTimer);
                _srchTimer = setTimeout(applyFilters, 250);
            });

            // 2. القوائم المنسدلة: تطبيق فوري عند التغيير
            $('#filterType, #filterParent').on('change', function() {
                applyFilters();
            });

            // 3. اختصار لوحة المفاتيح: Ctrl+F للتركيز
            $(document).on('keydown', function(e) {
                if (e.ctrlKey && e.key === 'f') {
                    e.preventDefault();
                    $('#globalSearch').focus().select();
                }
            });

            // 4. Escape لمسح البحث
            $('#globalSearch').on('keydown', function(e) {
                if (e.key === 'Escape') clearSearch();
            });

            // 5. تحديث العداد عند كل إعادة رسم
            $('#menuTable').on('draw.dt', updateMatchCount);
            updateMatchCount();

            // حذف
            $(document).on('click', '.delete-btn', function() {
                const id = $(this).data('id');
                const title = $(this).data('title');
                Swal.fire({
                    title: 'هل أنت متأكد؟',
                    text: "سيتم حذف '" + title + "' مع كافة العناصر التابعة له!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'نعم، احذف الآن',
                    cancelButtonText: 'إلغاء'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'delete_menu.php?id=' + id;
                    }
                });
            });

            // استرجاع fullscreen
            if (sessionStorage.getItem('wasl_fullscreen') === 'true') {
                $('body').addClass('sidebar-collapse');
                $('body').addClass('wasl-fullscreen');
            }
        });
        </script>
    </div>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
