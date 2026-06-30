<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/archive-list.php";

if (!$current_user_id) {
    die(__('login_required'));
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function archiveFormatBadge($fmt) {
    $fmt = strtolower($fmt);
    $map = ['pdf'=>'primary','doc'=>'info','docx'=>'info','xls'=>'success','xlsx'=>'success','jpg'=>'warning','png'=>'warning','gif'=>'warning'];
    $cls = $map[$fmt] ?? 'secondary';
    return '<span class="badge badge-' . $cls . '">' . strtoupper($fmt) . '</span>';
}

function archiveStatusBadge($status) {
    $map = [
        'approved' => ['success', __('status_approved')],
        'archived' => ['info', __('status_archived')],
        'cancelled' => ['danger', __('status_cancelled')],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];

    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
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

    $can_view = 0; $can_add = 0; $can_edit = 0; $can_delete = 0; $can_view_archive = 0; $can_archive = 0;
    if ($current_page_id > 0) {
        $accessSql = "SELECT can_view, can_add, can_edit, can_delete, can_view_archive, can_archive FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
        $accessStmt = $pdo->prepare($accessSql);
        $accessStmt->execute([$current_user_id, $current_page_id]);
        $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
        $can_view = $permissions['can_view'] ?? 0;
        $can_add = $permissions['can_add'] ?? 0;
        $can_edit = $permissions['can_edit'] ?? 0;
        $can_delete = $permissions['can_delete'] ?? 0;
        $can_view_archive = $permissions['can_view_archive'] ?? 0;
        $can_archive = $permissions['can_archive'] ?? 0;
    }

    if (!$can_view) {
        $_SESSION['warning_message'] = __('no_access_page');
        header("Location: ../index.php");
        exit;
    }

    if (isset($_GET['delete_id'])) {
        $did = (int)$_GET['delete_id'];
        $pdo->prepare("DELETE FROM dms_documents WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'ظˆط«ظٹظ‚ط©', $did, [], ['id' => $did]);
        set_success(__('deleted_success'));
        header("Location: archive-list.php");
        exit;
    }

    if (isset($_GET['status_id']) && isset($_GET['new_status'])) {
        $sid = (int)$_GET['status_id'];
        $new_status = $_GET['new_status'];
        $allowed = ['approved', 'archived', 'cancelled'];
        if (in_array($new_status, $allowed)) {
            $oldStmt = $pdo->prepare("SELECT status FROM dms_documents WHERE id = ?");
            $oldStmt->execute([$sid]);
            $old_status = $oldStmt->fetchColumn();
            $pdo->prepare("UPDATE dms_documents SET status = ? WHERE id = ?")->execute([$new_status, $sid]);
            log_action($pdo, 'update', 'ظˆط«ظٹظ‚ط©', $sid, ['status' => $old_status], ['status' => $new_status]);
            if ($new_status === 'archived') {
                set_success(__('doc_archived_ok'));
            } elseif ($new_status === 'cancelled') {
                set_success(__('doc_cancelled_ok'));
            }
        }
        header("Location: archive-list.php");
        exit;
    }

    $docs = $pdo->query("
        SELECT d.*, t.name AS type_name, c.name AS category_name,
               u.full_name AS creator_name,
               (SELECT COUNT(*) FROM dms_signatures s WHERE s.document_id = d.id) AS sig_count,
               (SELECT COUNT(*) FROM dms_signatures s WHERE s.document_id = d.id AND s.status = 'signed') AS sig_signed_count
        FROM dms_documents d
        LEFT JOIN dms_document_types t ON d.type_id = t.id
        LEFT JOIN dms_categories c ON d.category_id = c.id
        LEFT JOIN sys_users u ON d.created_by = u.id
        WHERE d.status IN ('approved', 'archived')
        ORDER BY d.updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $docTypes = $pdo->query("SELECT id, name FROM dms_document_types WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $docCategories = $pdo->query("SELECT id, name FROM dms_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT DISTINCT department FROM dms_documents WHERE department IS NOT NULL AND department != '' ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);

    // GET filter params from archive-browser links
    $presetYear = $_GET['year'] ?? '';
    $presetDept = $_GET['department'] ?? '';
    $presetType = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
    $presetTypeName = '';
    if ($presetType) {
        $tStmt = $pdo->prepare("SELECT name FROM dms_document_types WHERE id = ?");
        $tStmt->execute([$presetType]);
        $presetTypeName = $tStmt->fetchColumn() ?: '';
    }

    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $company_name = $settings['system_name'] ?? 'اسم الشركة';
    $company_name_en = $settings['system_name_en'] ?? $company_name;
    $company_logo = $settings['system_logo'] ?? 'default-logo.png';
    $company_address = $settings['address'] ?? 'العنوان';
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
    <title><?= __('doc_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.min.css">

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
#archiveTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
#archiveTable td { vertical-align: middle; text-align: center; }
.dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
.dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
.btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
.btn-group-action { display: flex; gap: 4px; justify-content: center; flex-wrap: nowrap; }
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
                        <h4><i class="fas fa-archive ml-2"></i> <?= __('doc_title') ?> <?= langSwitcher() ?></h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i><?= __('home') ?></a></li>
                            <li class="breadcrumb-item active"><?= __('docs_management') ?></li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <div class="card card-ticket">
                        <div class="card-header">
                            <div class="row w-100">
                                <div class="col-md-3">
                                    <label><i class="fas fa-tag ml-1"></i> <?= __('doc_filter_type') ?></label>
                                    <select id="filter-type" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <?php foreach ($docTypes as $dt): ?>
                                            <option value="<?= htmlspecialchars($dt['name']) ?>"><?= htmlspecialchars($dt['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label><i class="fas fa-folder ml-1"></i> <?= __('doc_filter_category') ?></label>
                                    <select id="filter-category" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <?php foreach ($docCategories as $dc): ?>
                                            <option value="<?= htmlspecialchars($dc['name']) ?>"><?= htmlspecialchars($dc['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label><i class="fas fa-building ml-1"></i> <?= __('doc_filter_dept') ?></label>
                                    <select id="filter-department" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="w-100">
                                        <label>&nbsp;</label>
                                        <button id="resetFilters" class="btn btn-secondary btn-block"><i class="fas fa-undo"></i> <?= __('reset') ?></button>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <?php if ($can_add == 1): ?>
                                    <a href="../forms/add-document.php" class="btn btn-primary btn-sm"><i class="fas fa-plus ml-1"></i> <?= __('doc_add') ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="archiveTable" class="table table-hover table-bordered text-center">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>#</th>
                                            <th><?= __('doc_number') ?></th>
                                            <th><?= __('doc_title_field') ?></th>
                                            <th><?= __('doc_type') ?></th>
                                            <th><?= __('doc_category') ?></th>
                                            <th><?= __('doc_department') ?></th>
                                            <th><?= __('doc_format') ?></th>
                                            <th><?= __('doc_size') ?></th>
                                            <th><?= __('sig_title') ?></th>
                                            <th><?= __('doc_status') ?></th>
                                            <th><?= __('last_update') ?></th>
                                            <th><?= __('actions') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($docs as $d): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><?= htmlspecialchars($d['doc_number'] ?? '-') ?></strong></td>
                                            <td><?= mb_strlen($d['title'] ?? '') > 50 ? mb_substr(htmlspecialchars($d['title']), 0, 50) . '...' : htmlspecialchars($d['title'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($d['type_name'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($d['category_name'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($d['department'] ?? '-') ?></td>
                                            <td><?= $d['file_format'] ? archiveFormatBadge($d['file_format']) : '-' ?></td>
                                            <td><?= $d['file_size'] !== null ? formatSize((int)$d['file_size']) : '-' ?></td>
                                            <td>
                                                <?php
                                                $sig = ($d['sig_signed_count'] ?? 0) . '/' . ($d['sig_count'] ?? 0);
                                                $allSigned = ($d['sig_count'] ?? 0) > 0 && ($d['sig_count'] ?? 0) == ($d['sig_signed_count'] ?? 0);
                                                $badgeCls = $allSigned ? 'success' : 'warning';
                                                ?>
                                                <span class="badge badge-<?= $badgeCls ?>"><?= $sig ?></span>
                                            </td>
                                            <td><?= archiveStatusBadge($d['status'] ?? 'archived') ?></td>
                                            <td><?= $d['updated_at'] ? date('Y-m-d', strtotime($d['updated_at'])) : '-' ?></td>
                                            <td>
                                                <div class="btn-group-action">
                                                    <?php if ($can_view_archive == 1): ?>
                                                    <a href="../forms/view-document.php?id=<?= $d['id'] ?>" class="btn btn-info btn-sm" title="<?= __('doc_view') ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="../forms/archive-signatures.php?id=<?= $d['id'] ?>" class="btn btn-primary btn-sm" title="<?= __('archive_sig_title') ?>">
                                                        <i class="fas fa-signature"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <?php if ($can_archive == 1): ?>
                                                        <?php if ($d['status'] === 'approved'): ?>
                                                            <a href="?status_id=<?= $d['id'] ?>&new_status=archived" class="btn btn-info btn-sm btn-status" title="<?= __('yes_archive') ?>" data-action="archive" data-title="<?= htmlspecialchars($d['title'] ?? '') ?>">
                                                                <i class="fas fa-archive"></i>
                                                            </a>
                                                        <?php elseif ($d['status'] === 'archived'): ?>
                                                            <a href="?status_id=<?= $d['id'] ?>&new_status=cancelled" class="btn btn-danger btn-sm btn-status" title="<?= __('cancel') ?>" data-action="cancel" data-title="<?= htmlspecialchars($d['title'] ?? '') ?>">
                                                                <i class="fas fa-ban"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete == 1): ?>
                                                        <a href="#" class="btn btn-danger btn-sm btn-delete" title="<?= __('delete') ?>" data-id="<?= $d['id'] ?>" data-title="<?= htmlspecialchars($d['title'] ?? '') ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($docs)): ?>
                                        <tr><?php for ($_c=0; $_c<12; $_c++): ?><td></td><?php endfor; ?></tr>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.all.min.js"></script>

    <script>
    var systemName = "<?= $company_name ?>";
    var systemNameEn = "<?= addslashes($company_name_en) ?>";
    var systemAddress = "<?= $company_address ?>";
    var logoPath = "<?= $base_assets_url ?>/admin/dist/img/<?= rawurlencode($company_logo) ?>";
    var logoDataUri = "<?= $logo_data_uri ?>";
    var sessionUserName = "<?= $current_user_name ?>";
    var currentLang = "<?= getLang() ?>";
    var displayCompanyName = currentLang === 'en' ? systemNameEn : systemName;
    var presetDept = "<?= $presetDept ?>";
    var presetTypeName = "<?= $presetTypeName ?>";

    var btnPrint = "<?= __('print') ?>";
    var btnPdf = 'PDF';
    var btnExcel = "<?= __('excel') ?>";
    var btnColumns = "<?= __('columns_select') ?>";
    var reportTitle = "<?= __('docs_report_title') ?>";
    var confirmDeleteText = "<?= __('confirm_delete_doc') ?>";
    var yesDelete = "<?= __('yes_delete') ?>";
    var cancelBtn = "<?= __('cancel_btn') ?>";
    var successTitle = "<?= __('success') ?>";
    var errorTitle = "<?= __('error') ?>";
    var confirmArchiveText = "<?= __('confirm_archive') ?>";
    var confirmCancelText = "<?= __('confirm_cancel') ?>";
    var yesBtn = "<?= __('yes') ?>";

    $(document).ready(function() {
        const currentDate = new Date().toLocaleDateString(currentLang === 'ar' ? 'ar-EG' : 'en-US');
        const printDateLabel = currentLang === 'ar' ? "<?= __('report_print_date') ?>" : "<?= __('report_print_date_en') ?>";
        const reportDateLabel = currentLang === 'ar' ? "<?= __('report_date_prefix') ?>" : "<?= __('report_date_prefix_en') ?>";

        var table = $('#archiveTable').DataTable({
            "dom": "<'row mb-3'<'col-md-6'B><'col-md-6 text-<?= isRtl() ? 'left' : 'right' ?>'f>>" +
                   "<'row'<'col-12'tr>>" +
                   "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
            "buttons": [
                {
                    extend: 'print',
                    text: btnPrint,
                    className: 'btn btn-outline-primary btn-sm ml-1',
                    exportOptions: { columns: ':visible:not(:last-child)' },
                    customize: function(win) { waslPrintSetup(win, reportTitle); }
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
            "language": <?= json_encode([
                'search' => __('search') . ':',
                'lengthMenu' => __('show_entries'),
                'info' => __('showing_info'),
                'paginate' => ['first' => __('first'), 'last' => __('last'), 'next' => __('next'), 'previous' => __('previous')],
                'emptyTable' => __('empty_table')
            ]) ?>
        });

        $('#filter-type').on('change', function () { table.column(3).search(this.value).draw(); });
        $('#filter-category').on('change', function () { table.column(4).search(this.value).draw(); });
        $('#filter-department').on('change', function () { table.column(5).search(this.value).draw(); });
        $('#resetFilters').on('click', function () {
            $('#filter-type').val('');
            $('#filter-category').val('');
            $('#filter-department').val('');
            table.column(3).search('').draw();
            table.column(4).search('').draw();
            table.column(5).search('').draw();
        });

        // Apply preset filters from URL params
        if (presetDept) {
            $('#filter-department').val(presetDept);
            table.column(5).search(presetDept).draw();
        }
        if (presetTypeName) {
            $('#filter-type').val(presetTypeName);
            table.column(3).search(presetTypeName).draw();
        }

        $('.btn-status').on('click', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');
            var action = $(this).data('action');
            var title = $(this).data('title');
            var msg = action === 'archive' ? confirmArchiveText : confirmCancelText;
            Swal.fire({
                title: msg,
                text: title ? '"' + title + '"' : '',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'cancel' ? '#dc3545' : '#17a2b8',
                cancelButtonColor: '#6c757d',
                confirmButtonText: yesBtn,
                cancelButtonText: cancelBtn
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            });
        });

        $('.btn-delete').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var title = $(this).data('title');
            Swal.fire({
                title: '<?= __('confirm_delete') ?>',
                text: confirmDeleteText + ' ' + title + '?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: yesDelete,
                cancelButtonText: cancelBtn
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_id=' + id;
                }
            });
        });
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
