<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-categories.php";

if (!$current_user_id) {
    die(__('login_required'));
}

try {
    $userSql = "SELECT full_name FROM sys_users WHERE id = ?";
    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute([$current_user_id]);
    $current_user_name = $userStmt->fetchColumn() ?? __('unknown_user');

    $menuSql = "SELECT id FROM sys_menu WHERE link = ?";
    $menuStmt = $pdo->prepare($menuSql);
    $menuStmt->execute([$page_path]);
    $current_page_id = $menuStmt->fetchColumn() ?? 0;

    $can_add = 0; $can_edit = 0; $can_delete = 0;
    if ($current_page_id > 0) {
        $accessSql = "SELECT can_add, can_edit, can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
        $accessStmt = $pdo->prepare($accessSql);
        $accessStmt->execute([$current_user_id, $current_page_id]);
        $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
        $can_add = $permissions['can_add'] ?? 0;
        $can_edit = $permissions['can_edit'] ?? 0;
        $can_delete = $permissions['can_delete'] ?? 0;
    }

    // Handle toggle active
    if (isset($_GET['toggle_id'])) {
        $tid = (int)$_GET['toggle_id'];
        $cur = $pdo->prepare("SELECT is_active FROM " . TBL_DOC_CATEGORIES . " WHERE id = ?");
        $cur->execute([$tid]);
        $val = $cur->fetchColumn();
        $new = $val ? 0 : 1;
        $pdo->prepare("UPDATE " . TBL_DOC_CATEGORIES . " SET is_active = ? WHERE id = ?")->execute([$new, $tid]);
        log_action($pdo, 'update', 'تصنيف وثائق', $tid, ['is_active' => $val], ['is_active' => $new]);
        header("Location: show-categories.php");
        exit;
    }

    // Handle delete
    if (isset($_GET['delete_id'])) {
        $did = (int)$_GET['delete_id'];
        // Check if category has children
        $childCheck = $pdo->prepare("SELECT COUNT(*) FROM " . TBL_DOC_CATEGORIES . " WHERE parent_id = ?");
        $childCheck->execute([$did]);
        if ($childCheck->fetchColumn() > 0) {
            die(__('child_delete_denied'));
        }
        $pdo->prepare("DELETE FROM " . TBL_DOC_CATEGORIES . " WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'تصنيف وثائق', $did, [], ['id' => $did]);
        header("Location: show-categories.php");
        exit;
    }

    // Handle add
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
        $name = trim($_POST['name']);
        $parent_id = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        $stmt = $pdo->prepare("INSERT INTO " . TBL_DOC_CATEGORIES . " (name, parent_id, description, sort_order) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $parent_id, $description ?: null, $sort_order]);
        $new_id = $pdo->lastInsertId();
        log_action($pdo, 'create', 'تصنيف وثائق', $new_id, [], [
            'name' => $name, 'parent_id' => $parent_id, 'description' => $description, 'sort_order' => $sort_order
        ]);
        header("Location: show-categories.php");
        exit;
    }

    // Handle edit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $parent_id = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        // Prevent setting self as parent
        if ($parent_id === $id) {
            die(__('self_parent'));
        }

        $stmt = $pdo->prepare("UPDATE " . TBL_DOC_CATEGORIES . " SET name=?, parent_id=?, description=?, sort_order=? WHERE id=?");
        $stmt->execute([$name, $parent_id, $description ?: null, $sort_order, $id]);
        log_action($pdo, 'update', 'تصنيف وثائق', $id, [], [
            'name' => $name, 'parent_id' => $parent_id, 'description' => $description, 'sort_order' => $sort_order
        ]);
        header("Location: show-categories.php");
        exit;
    }

    $categories = $pdo->query("SELECT * FROM " . TBL_DOC_CATEGORIES . " ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $company_name = $settings['system_name'] ?? (getLang() === 'ar' ? 'اسم الشركة' : 'Company Name');
    $company_name_en = $settings['system_name_en'] ?? $company_name;
    $company_logo = $settings['system_logo'] ?? 'default-logo.png';
    $company_address = $settings['address'] ?? (getLang() === 'ar' ? 'العنوان' : 'Address');
    $base_assets_url = rtrim(dirname($_SERVER['SCRIPT_NAME'], 4), '/');

    $logo_path_internal = __DIR__ . '/../../dist/img/' . $company_logo;
    $logo_data_uri = '';
    if (file_exists($logo_path_internal)) {
        $logo_data = file_get_contents($logo_path_internal);
        $logo_base64 = base64_encode($logo_data);
        $logo_data_uri = 'data:image/png;base64,' . $logo_base64;
    }

} catch (PDOException $e) {
    die(__('db_error') . ": " . $e->getMessage());
}

// Fetch a single category for edit modal
$edit_category = null;
if (isset($_GET['edit_id'])) {
    $s = $pdo->prepare("SELECT * FROM " . TBL_DOC_CATEGORIES . " WHERE id = ?");
    $s->execute([(int)$_GET['edit_id']]);
    $edit_category = $s->fetch(PDO::FETCH_ASSOC);
}

// Helper: get category name by id
function getCategoryName($pdo, $id) {
    static $cache = [];
    if (!isset($cache[$id])) {
        $s = $pdo->prepare("SELECT name FROM " . TBL_DOC_CATEGORIES . " WHERE id = ?");
        $s->execute([$id]);
        $cache[$id] = $s->fetchColumn();
    }
    return $cache[$id];
}
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <title><?= __('cat_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">

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
            justify-content: flex-start;
        }
        .card-ticket .card-body { padding: 20px; }
        #categoriesTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #categoriesTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        .badge-pill-custom { padding: 6px 14px; font-size: 11px; }
    </style>
</head>

<body class="hold-transition layout-fixed">
    <div class="wrapper">
        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <div class="uni-header">
                        <h4><i class="fas fa-folder ml-2"></i> <?= __('cat_title') ?> <?= langSwitcher() ?></h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i><?= __('home') ?></a></li>
                            <li class="breadcrumb-item active"><?= __('dms_categories') ?></li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <div class="card card-ticket">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list ml-2"></i> <?= __('categories_list') ?></h5>
                            <?php if ($can_add == 1): ?>
                                <button class="btn btn-primary btn-sm mr-auto" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus ml-1"></i> <?= __('cat_add') ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="categoriesTable" class="table table-hover table-bordered text-center">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>#</th>
                                            <th><?= __('cat_name') ?></th>
                                            <th><?= __('cat_parent') ?></th>
                                            <th><?= __('cat_desc') ?></th>
                                            <th><?= __('cat_order') ?></th>
                                            <th><?= __('cat_status') ?></th>
                                            <th><?= __('actions') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($categories as $c): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                            <td>
                                                <?php if ($c['parent_id']): ?>
                                                    <?= htmlspecialchars(getCategoryName($pdo, $c['parent_id']) ?? '-') ?>
                                                <?php else: ?>
                                                    <span class="text-muted"><?= __('cat_root') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($c['description'] ?? '-') ?></td>
                                            <td><?= (int)$c['sort_order'] ?></td>
                                            <td>
                                                <a href="?toggle_id=<?= $c['id'] ?>" class="badge badge-pill-custom <?= $c['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                                                    <?= $c['is_active'] ? __('active') : __('inactive') ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <?php if ($can_edit == 1): ?>
                                                        <a href="?edit_id=<?= $c['id'] ?>" class="btn btn-warning btn-sm btn-action" title="<?= __('edit') ?>" data-toggle="modal" data-target="#editModal<?= $c['id'] ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete == 1): ?>
                                                        <a href="?delete_id=<?= $c['id'] ?>" class="btn btn-danger btn-sm btn-action" title="<?= __('delete') ?>" onclick="return confirm('<?= __('confirm_delete_item') ?>?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($categories)): ?>
                                        <tr><?php for ($_c=0; $_c<7; $_c++): ?><td></td><?php endfor; ?></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><?= __('cat_add') ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="add_category" value="1">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= __('cat_name') ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= __('cat_parent') ?></label>
                                    <select name="parent_id" class="form-control">
                                        <option value="">-- <?= __('cat_root') ?> --</option>
                                        <?php foreach ($categories as $pc): ?>
                                            <option value="<?= $pc['id'] ?>"><?= htmlspecialchars($pc['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?= __('cat_desc') ?></label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label><?= __('cat_order') ?></label>
                            <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                        <button type="submit" class="btn btn-info"><?= __('save') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modals -->
    <?php foreach ($categories as $c): ?>
    <div class="modal fade" id="editModal<?= $c['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><?= __('cat_edit') ?></h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="edit_category" value="1">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= __('cat_name') ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= __('cat_parent') ?></label>
                                    <select name="parent_id" class="form-control">
                                        <option value="">-- <?= __('cat_root') ?> --</option>
                                        <?php foreach ($categories as $pc): ?>
                                            <?php if ($pc['id'] != $c['id']): ?>
                                                <option value="<?= $pc['id'] ?>" <?= $c['parent_id'] == $pc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pc['name']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><?= __('cat_desc') ?></label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($c['description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label><?= __('cat_order') ?></label>
                            <input type="number" name="sort_order" class="form-control" value="<?= (int)$c['sort_order'] ?>" min="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                        <button type="submit" class="btn btn-warning"><?= __('update') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="../../dist/js/pdfmake-arabic.js"></script>

    <script>
    var systemName = "<?= $company_name ?>";
    var systemNameEn = "<?= addslashes($company_name_en) ?>";
    var systemAddress = "<?= $company_address ?>";
    var logoPath = "<?= $base_assets_url ?>/admin/dist/img/<?= rawurlencode($company_logo) ?>";
    var logoDataUri = "<?= $logo_data_uri ?>";
    var sessionUserName = "<?= $current_user_name ?>";
    var currentLang = "<?= getLang() ?>";
    var displayCompanyName = currentLang === 'en' ? systemNameEn : systemName;

    var btnPrint = "<?= __('print') ?>";
    var btnPdf = 'PDF';
    var btnExcel = "<?= __('excel') ?>";
    var btnColumns = "<?= __('columns_select') ?>";
    var reportTitle = "<?= __('categories_report_title') ?>";

    $(document).ready(function() {
        const currentDate = new Date().toLocaleDateString(currentLang === 'ar' ? 'ar-EG' : 'en-US');
        const printDateLabel = currentLang === 'ar' ? "<?= __('report_print_date') ?>" : "<?= __('report_print_date_en') ?>";
        const reportDateLabel = currentLang === 'ar' ? "<?= __('report_date_prefix') ?>" : "<?= __('report_date_prefix_en') ?>";

        var table = $('#categoriesTable').DataTable({
            "dom": "<'row mb-3'<'col-md-6'B><'col-md-6 text-<?= isRtl() ? 'left' : 'right' ?>'f>>" +
                   "<'row'<'col-12'tr>>" +
                   "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
            "buttons": [
                {
                    extend: 'print',
                    text: btnPrint,
                    className: 'btn btn-outline-primary btn-sm ml-1',
                    exportOptions: { columns: ':visible' },
                    customize: function (win) {
                        $(win.document.body).css({'direction': '<?= isRtl() ? 'rtl' : 'ltr' ?>', 'text-align': '<?= isRtl() ? 'right' : 'left' ?>'});
                        $(win.document.body).prepend(`
                            <div style="display: table; width: 100%; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px;">
                                <div style="display: table-cell; width: 33.33%; vertical-align: middle;">
                                    <h4 style="margin:0;">${displayCompanyName}</h4>
                                    <p>${systemAddress}</p>
                                </div>
                                <div style="display: table-cell; width: 33.33%; text-align: center; vertical-align: middle;">
                                    <img src="${logoPath}" style="height: 80px;">
                                </div>
                                <div style="display: table-cell; width: 33.33%; text-align: <?= isRtl() ? 'left' : 'right' ?>; vertical-align: middle;">
                                    <h3 style="margin:0;">${reportTitle}</h3>
                                    <p>${printDateLabel}: ${currentDate}</p>
                                </div>
                            </div>
                        `);
                    }
                },
                {
                    extend: 'pdf',
                    text: btnPdf,
                    className: 'btn btn-outline-danger btn-sm ml-1',
                    exportOptions: { columns: ':visible' },
                    customize: function(doc) {
                        doc.defaultStyle.font = 'tahoma';
                        var d = new Date().toLocaleDateString(currentLang === 'ar' ? 'ar-EG' : 'en-US');
                        doc.content.unshift({
                            stack: [
                                { image: logoDataUri, width: 70, alignment: 'center', margin: [0, 0, 0, 8] },
                                { text: displayCompanyName, alignment: 'center', fontSize: 20, bold: true, color: '#0d4a1c', margin: [0, 0, 0, 4] },
                                { text: reportTitle, alignment: 'center', fontSize: 14, color: '#555', margin: [0, 0, 0, 4] },
                                { text: reportDateLabel + ': ' + d, alignment: 'center', fontSize: 10, color: '#999', margin: [0, 0, 0, 12] },
                                { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 515, y2: 0, lineWidth: 2.5, color: '#0d4a1c' }] }
                            ],
                            margin: [0, 0, 0, 15]
                        });
                        doc.styles.tableHeader = {
                            bold: true, fontSize: 10, color: 'white',
                            fillColor: '#0d4a1c', alignment: 'center'
                        };
                        doc.styles.tableBodyOdd = { fillColor: '#f5f7fa' };
                        doc.content[0].layout = {
                            hLineWidth: function(i, node) {
                                if (i === 0 || i === node.table.body.length) return 2;
                                return 0.5;
                            },
                            vLineWidth: function() { return 0; },
                            hLineColor: function(i) {
                                if (i === 0 || i === 1) return '#0d4a1c';
                                return '#e0e4ea';
                            },
                            paddingTop: function() { return 5; },
                            paddingBottom: function() { return 5; },
                            paddingLeft: function() { return 8; },
                            paddingRight: function() { return 8; }
                        };
                    }
                },
                { extend: 'excelHtml5', text: btnExcel, className: 'btn btn-outline-success btn-sm ml-1', exportOptions: { columns: ':visible' } },
                { extend: 'colvis', text: btnColumns, className: 'btn btn-outline-secondary btn-sm' }
            ],
            "language": <?= getLang() === 'ar' ? json_encode([
                'search' => 'بحث:',
                'lengthMenu' => 'عرض _MENU_ سجل',
                'info' => 'عرض _START_ إلى _END_ من _TOTAL_ سجل',
                'paginate' => ['first' => 'الأول', 'last' => 'الأخير', 'next' => 'التالي', 'previous' => 'السابق'],
                'emptyTable' => 'لا توجد بيانات'
            ]) : json_encode([
                'search' => 'Search:',
                'lengthMenu' => 'Show _MENU_ entries',
                'info' => 'Showing _START_ to _END_ of _TOTAL_ entries',
                'paginate' => ['first' => 'First', 'last' => 'Last', 'next' => 'Next', 'previous' => 'Previous'],
                'emptyTable' => 'No data available'
            ]) ?>
        });

        <?php if (isset($_GET['edit_id'])): ?>
        $('#editModal<?= (int)$_GET['edit_id'] ?>').modal('show');
        <?php endif; ?>
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
