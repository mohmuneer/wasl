<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-document-types.php";

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
        $cur = $pdo->prepare("SELECT is_active FROM " . TBL_DOC_TYPES . " WHERE id = ?");
        $cur->execute([$tid]);
        $val = $cur->fetchColumn();
        $new = $val ? 0 : 1;
        $pdo->prepare("UPDATE " . TBL_DOC_TYPES . " SET is_active = ? WHERE id = ?")->execute([$new, $tid]);
        log_action($pdo, 'update', 'نوع مستند', $tid, ['is_active' => $val], ['is_active' => $new]);
        header("Location: show-document-types.php");
        exit;
    }

    // Handle delete
    if (isset($_GET['delete_id'])) {
        $did = (int)$_GET['delete_id'];
        $pdo->prepare("DELETE FROM " . TBL_DOC_TYPES . " WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'نوع مستند', $did, [], ['id' => $did]);
        header("Location: show-document-types.php");
        exit;
    }

    // Handle add
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_type'])) {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $retention_period = $_POST['retention_period'] !== '' ? (int)$_POST['retention_period'] : null;

        $stmt = $pdo->prepare("INSERT INTO " . TBL_DOC_TYPES . " (name, description, retention_period) VALUES (?, ?, ?)");
        $stmt->execute([$name, $description ?: null, $retention_period]);
        $new_id = $pdo->lastInsertId();
        log_action($pdo, 'create', 'نوع مستند', $new_id, [], [
            'name' => $name, 'description' => $description, 'retention_period' => $retention_period
        ]);
        header("Location: show-document-types.php");
        exit;
    }

    // Handle edit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_type'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $retention_period = $_POST['retention_period'] !== '' ? (int)$_POST['retention_period'] : null;

        $stmt = $pdo->prepare("UPDATE " . TBL_DOC_TYPES . " SET name=?, description=?, retention_period=? WHERE id=?");
        $stmt->execute([$name, $description ?: null, $retention_period, $id]);
        log_action($pdo, 'update', 'نوع مستند', $id, [], [
            'name' => $name, 'description' => $description, 'retention_period' => $retention_period
        ]);
        header("Location: show-document-types.php");
        exit;
    }

    $types = $pdo->query("SELECT * FROM " . TBL_DOC_TYPES . " ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

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
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <title><?= __('type_title') ?></title>
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
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-ticket .card-body { padding: 20px; }
        #typesTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #typesTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-weight: 600; border-radius: 20px; font-size: .8rem; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        .filter-inline select { border-radius: 8px; border: 1px solid #ced4da; padding: 4px 10px; font-size: .85rem; }
        .filter-inline select:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
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
                        <h4><i class="fas fa-file-alt ml-2"></i> <?= __('type_title') ?> <?= langSwitcher() ?></h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i><?= __('home') ?></a></li>
                            <li class="breadcrumb-item active"><?= __('dms_doc_types') ?></li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <div class="card card-ticket">
                        <div class="card-header">
                            <div>
                                <?php if ($can_add == 1): ?>
                                    <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                        <i class="fas fa-plus ml-1"></i> <?= __('type_add') ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="filter-inline d-flex align-items-center gap-2 flex-wrap">
                                <label class="mb-0 ml-1 small font-weight-bold"><?= __('doc_filter_status') ?>:</label>
                                <select id="filter-active" class="form-control-sm">
                                    <option value=""><?= __('all') ?></option>
                                    <option value="<?= __('active') ?>"><?= __('active') ?></option>
                                    <option value="<?= __('inactive') ?>"><?= __('inactive') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="typesTable" class="table table-hover table-bordered text-center">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th><?= __('type_name') ?></th>
                                            <th><?= __('type_desc') ?></th>
                                            <th><?= __('type_retention') ?></th>
                                            <th><?= __('type_status') ?></th>
                                            <th><?= __('actions') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($types as $t): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($t['description'] ?? '-') ?></td>
                                            <td><?= $t['retention_period'] !== null ? $t['retention_period'] . ' ' . __('type_retention_suffix') : '-' ?></td>
                                            <td>
                                                <a href="?toggle_id=<?= $t['id'] ?>" class="badge badge-pill-custom <?= $t['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                                                    <?= $t['is_active'] ? __('active') : __('inactive') ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-center" style="gap:4px;">
                                                    <?php if ($can_edit == 1): ?>
                                                        <a href="#" class="btn btn-warning btn-action" title="<?= __('edit') ?>" data-toggle="modal" data-target="#editModal<?= $t['id'] ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete == 1): ?>
                                                        <a href="#" class="btn btn-danger btn-action delete-btn" data-id="<?= $t['id'] ?>" data-name="<?= htmlspecialchars($t['name']) ?>" title="<?= __('delete') ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
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

        <!-- Add Modal -->
        <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content" style="border-radius:10px;border:none;">
                    <div class="modal-header" style="background:linear-gradient(135deg,var(--uni-primary),var(--uni-accent));color:#fff;border-radius:10px 10px 0 0;">
                        <h5 class="modal-title"><i class="fas fa-plus ml-1"></i> <?= __('type_add') ?></h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="add_type" value="1">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?= __('type_name') ?></label>
                                        <input type="text" name="name" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?= __('type_retention') ?></label>
                                        <input type="number" name="retention_period" class="form-control" placeholder="<?= __('optional') ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><?= __('type_desc') ?></label>
                                <textarea name="description" class="form-control" rows="3" placeholder="<?= __('type_desc') ?> (<?= __('optional') ?>)"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('cancel') ?></button>
                            <button type="submit" class="btn btn-primary"><?= __('save') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Modals -->
        <?php foreach ($types as $t): ?>
        <div class="modal fade" id="editModal<?= $t['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content" style="border-radius:10px;border:none;">
                    <div class="modal-header" style="background:linear-gradient(135deg,var(--uni-primary),var(--uni-accent));color:#fff;border-radius:10px 10px 0 0;">
                        <h5 class="modal-title"><i class="fas fa-edit ml-1"></i> <?= __('type_edit') ?></h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="edit_type" value="1">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?= __('type_name') ?></label>
                                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($t['name']) ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><?= __('type_retention') ?></label>
                                        <input type="number" name="retention_period" class="form-control" value="<?= $t['retention_period'] !== null ? $t['retention_period'] : '' ?>" placeholder="<?= __('optional') ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><?= __('type_desc') ?></label>
                                <textarea name="description" class="form-control" rows="3" placeholder="<?= __('type_desc') ?> (<?= __('optional') ?>)"><?= htmlspecialchars($t['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('cancel') ?></button>
                            <button type="submit" class="btn btn-primary"><?= __('update') ?></button>
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
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <script>
        $(document).ready(function() {
            var table = $('#typesTable').DataTable({
                order: [[0, 'asc']],
                dom: "<'row mb-3'<'col-md-6'B><'col-md-6 text-<?= isRtl() ? 'left' : 'right' ?>'f>>" +
                     "<'row'<'col-12'tr>>" +
                     "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
                buttons: [
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print ml-1"></i> <?= __('print') ?>',
                        className: 'btn btn-outline-primary btn-sm ml-1',
                        exportOptions: { columns: ':visible' },
                        customize: function(win) {
                            $(win.document.body)
                                .css('direction', '<?= isRtl() ? 'rtl' : 'ltr' ?>')
                                .css('text-align', '<?= isRtl() ? 'right' : 'left' ?>')
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
                            header += '<h4 style="margin:15px 0;"><?= __('types_report_title') ?></h4>';
                            header += '</div>';
                            $(win.document.body).find('h1').remove();
                            $(win.document.body).prepend(header);
                        }
                    },
                    {
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel ml-1"></i> <?= __('excel') ?>',
                        className: 'btn btn-outline-success btn-sm ml-1',
                        exportOptions: { columns: ':visible' }
                    },
                    {
                        extend: 'colvis',
                        text: '<i class="fas fa-columns ml-1"></i> <?= __('columns_select') ?>',
                        className: 'btn btn-outline-secondary btn-sm',
                        postfixButtons: ['colvisRestore']
                    }
                ],
                language: {
                    search: '<?= getLang() === 'ar' ? 'بحث:' : 'Search:' ?>',
                    lengthMenu: '<?= getLang() === 'ar' ? 'عرض _MENU_ سجل' : 'Show _MENU_ entries' ?>',
                    info: '<?= getLang() === 'ar' ? 'عرض _START_ إلى _END_ من _TOTAL_ سجل' : 'Showing _START_ to _END_ of _TOTAL_ entries' ?>',
                    infoEmpty: '<?= getLang() === 'ar' ? '0 سجل' : '0 entries' ?>',
                    infoFiltered: '<?= getLang() === 'ar' ? '(من أصل _MAX_)' : '(filtered from _MAX_ total entries)' ?>',
                    paginate: {
                        next: '<?= getLang() === 'ar' ? 'التالي' : 'Next' ?>',
                        previous: '<?= getLang() === 'ar' ? 'السابق' : 'Previous' ?>'
                    },
                    emptyTable: '<?= getLang() === 'ar' ? 'لا توجد بيانات' : 'No data available' ?>',
                    buttons: {
                        print: '<?= __('print') ?>',
                        excel: '<?= __('excel') ?>',
                        colvis: '<?= __('columns_select') ?>'
                    }
                }
            });

            $('#filter-active').on('change', function() {
                table.column(4).search(this.value).draw();
            });

            $(document).on('click', '.delete-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                var name = $(this).data('name');
                Swal.fire({
                    title: '<?= __('confirm') ?>',
                    text: "<?= __('confirm_delete_item') ?> '" + name + "'?",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: '<?= getLang() === 'ar' ? 'نعم، احذف' : 'Yes, delete' ?>',
                    cancelButtonText: '<?= getLang() === 'ar' ? 'إلغاء' : 'Cancel' ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = '?delete_id=' + id;
                    }
                });
            });

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
