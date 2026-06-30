<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-signatures.php";

if (!$current_user_id) {
    die(__('login_required'));
}

function sigStatusBadge($status) {
    $map = [
        'pending' => ['warning', __('sig_pending'), 'Pending'],
        'signed' => ['success', __('sig_signed'), 'Signed'],
        'rejected' => ['danger', __('sig_rejected'), 'Rejected'],
    ];
    [$cls, $ar, $en] = $map[$status] ?? ['secondary', $status, $status];
    $label = getLang() === 'ar' ? $ar : $en;
    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
}

function sigTypeBadge($type) {
    $map = [
        'auto' => ['info', __('sig_type_auto'), 'Auto'],
        'manual' => ['warning', __('sig_type_manual'), 'Manual'],
        'digital' => ['success', __('sig_type_digital'), 'Digital'],
    ];
    [$cls, $ar, $en] = $map[$type] ?? ['secondary', $type, $type];
    $label = getLang() === 'ar' ? $ar : $en;
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

    // Handle status change (pending -> signed -> rejected)
    if (isset($_GET['status_id']) && isset($_GET['new_status'])) {
        $sid = (int)$_GET['status_id'];
        $new_status = $_GET['new_status'];
        $allowed = ['signed', 'rejected'];
        if (in_array($new_status, $allowed)) {
            $oldStmt = $pdo->prepare("SELECT status FROM dms_signatures WHERE id = ?");
            $oldStmt->execute([$sid]);
            $old_status = $oldStmt->fetchColumn();
            $pdo->prepare("UPDATE dms_signatures SET status = ? WHERE id = ?")->execute([$new_status, $sid]);
            log_action($pdo, 'update', 'signature', $sid, ['status' => $old_status], ['status' => $new_status]);
        }
        header("Location: show-signatures.php");
        exit;
    }

    // Handle delete
    if (isset($_GET['delete_id'])) {
        $did = (int)$_GET['delete_id'];
        $pdo->prepare("DELETE FROM dms_signatures WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'signature', $did, [], ['id' => $did]);
        header("Location: show-signatures.php");
        exit;
    }

    // Main query with joins
    $signatures = $pdo->query("
        SELECT s.*, d.doc_number, d.title AS doc_title, d.status AS doc_status,
               e.full_name AS employee_name, e.job_title, e.department AS emp_department
        FROM dms_signatures s
        JOIN dms_documents d ON s.document_id = d.id
        JOIN dms_employees e ON s.employee_id = e.id
        ORDER BY s.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Populate filter dropdowns
    $employees = $pdo->query("SELECT DISTINCT id, full_name FROM dms_employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $documents = $pdo->query("SELECT DISTINCT id, doc_number FROM dms_documents ORDER BY doc_number ASC")->fetchAll(PDO::FETCH_ASSOC);

    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $company_name = $settings['system_name'] ?? 'Company Name';
    $company_name_en = $settings['system_name_en'] ?? $company_name;
    $company_logo = $settings['system_logo'] ?? 'default-logo.png';
    $company_address = $settings['address'] ?? 'Address';
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
    <title><?= __('signatures_title') ?></title>
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
        #signaturesTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #signaturesTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        .badge-pill-custom { padding: 6px 14px; font-size: 11px; }
        body { direction: <?= isRtl() ? 'rtl' : 'ltr' ?>; text-align: <?= isRtl() ? 'right' : 'left' ?>; font-family: 'Source Sans Pro', sans-serif; }
        <?php if (isRtl()): ?>.dataTables_filter { text-align: left !important; }<?php else: ?>.dataTables_filter { text-align: right !important; }<?php endif; ?>
        .btn-group-action { display: flex; gap: 4px; justify-content: center; flex-wrap: nowrap; }
        .status-link { font-size: 0.75rem; padding: 2px 6px; border-radius: 3px; text-decoration: none; white-space: nowrap; }
        .status-link:hover { text-decoration: none; opacity: 0.8; }
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
                        <h4><i class="fas fa-signature ml-2"></i> <?= __('signatures_title') ?> <?= langSwitcher() ?></h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i><?= __('home') ?></a></li>
                            <li class="breadcrumb-item active"><?= __('signatures') ?></li>
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
                                    <label><i class="fas fa-file ml-1"></i> <?= __('doc_title_field') ?></label>
                                    <select id="filter-document" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <?php foreach ($documents as $doc): ?>
                                            <option value="<?= htmlspecialchars($doc['doc_number']) ?>"><?= htmlspecialchars($doc['doc_number']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label><i class="fas fa-user ml-1"></i> <?= __('sig_employee') ?></label>
                                    <select id="filter-employee" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?= htmlspecialchars($emp['full_name']) ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label><i class="fas fa-tag ml-1"></i> <?= __('sig_type') ?></label>
                                    <select id="filter-sign-type" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <option value="<?= __('sig_type_auto') ?>"><?= __('sig_type_auto') ?></option>
                                        <option value="<?= __('sig_type_manual') ?>"><?= __('sig_type_manual') ?></option>
                                        <option value="<?= __('sig_type_digital') ?>"><?= __('sig_type_digital') ?></option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label><i class="fas fa-info-circle ml-1"></i> <?= __('sig_status') ?></label>
                                    <select id="filter-status" class="form-control">
                                        <option value=""><?= __('all') ?></option>
                                        <option value="<?= __('sig_pending') ?>"><?= __('sig_pending') ?></option>
                                        <option value="<?= __('sig_signed') ?>"><?= __('sig_signed') ?></option>
                                        <option value="<?= __('sig_rejected') ?>"><?= __('sig_rejected') ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <button id="resetFilters" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> <?= __('reset') ?></button>
                                    <?php if ($can_add == 1): ?>
                                        <a href="../tables/show-documents.php" class="btn btn-primary btn-sm mr-2"><i class="fas fa-file-signature ml-1"></i> <?= __('doc_view') ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table id="signaturesTable" class="table table-hover table-bordered text-center">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>#</th>
                                            <th><?= __('doc_number') ?></th>
                                            <th><?= __('doc_title') ?></th>
                                            <th><?= __('sig_employee') ?></th>
                                            <th><?= __('sig_position') ?></th>
                                            <th><?= __('sig_type') ?></th>
                                            <th><?= __('sig_status') ?></th>
                                            <th><?= __('sig_date') ?></th>
                                            <th><?= __('created_at') ?></th>
                                            <th><?= __('actions') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($signatures as $s): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><a href="../forms/view-document.php?id=<?= $s['document_id'] ?>"><?= htmlspecialchars($s['doc_number'] ?? '-') ?></a></strong></td>
                                            <td><?= htmlspecialchars(mb_substr($s['doc_title'] ?? '-', 0, 50)) . (mb_strlen($s['doc_title'] ?? '') > 50 ? '...' : '') ?></td>
                                            <td><?= htmlspecialchars($s['employee_name'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($s['job_title'] ?? '-') ?></td>
                                            <td><?= sigTypeBadge($s['sign_type'] ?? 'manual') ?></td>
                                            <td><?= sigStatusBadge($s['status'] ?? 'pending') ?></td>
                                            <td><?= $s['signed_at'] ? date('Y-m-d H:i', strtotime($s['signed_at'])) : '-' ?></td>
                                            <td><?= $s['created_at'] ? date('Y-m-d', strtotime($s['created_at'])) : '-' ?></td>
                                            <td>
                                                <div class="btn-group-action">
                                                    <?php if ($can_edit == 1): ?>
                                                        <?php if ($s['status'] === 'pending'): ?>
                                                            <a href="?status_id=<?= $s['id'] ?>&new_status=signed" class="btn btn-success btn-action" title="<?= __('sig_title') ?>" onclick="return confirm('<?= __('sig_confirm_sign') ?>')">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                        <?php elseif ($s['status'] === 'signed'): ?>
                                                            <a href="?status_id=<?= $s['id'] ?>&new_status=rejected" class="btn btn-danger btn-action" title="<?= __('sig_rejected') ?>" onclick="return confirm('<?= __('sig_confirm_reject') ?>')">
                                                                <i class="fas fa-times"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete == 1): ?>
                                                            <a href="#" class="btn btn-danger btn-action btn-delete" title="<?= __('delete') ?>" data-id="<?= $s['id'] ?>" data-doc="<?= htmlspecialchars($s['doc_number'] ?? '') ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($signatures)): ?>
                                        <tr><?php for ($_c=0; $_c<10; $_c++): ?><td></td><?php endfor; ?></tr>
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

    $(document).ready(function() {
        const currentDate = new Date().toLocaleDateString(currentLang === 'ar' ? 'ar-EG' : 'en-US');

        var table = $('#signaturesTable').DataTable({
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
                                    <h3 style="margin:0;"><?= __('signatures_title') ?></h3>
                                    <p><?= __('report_print_date') ?>: ${currentDate}</p>
                                </div>
                            </div>
                        `);
                    }
                },
                {
                    extend: 'pdf',
                    text: 'PDF',
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
                'search' => __('search') . ':',
                'lengthMenu' => __('show_entries'),
                'info' => __('showing_info'),
                'paginate' => ['first' => __('first'), 'last' => __('last'), 'next' => __('next'), 'previous' => __('previous')],
                'emptyTable' => __('empty_table')
            ]) : json_encode([
                'search' => 'Search:',
                'lengthMenu' => 'Show _MENU_ entries',
                'info' => 'Showing _START_ to _END_ of _TOTAL_ entries',
                'paginate' => ['first' => 'First', 'last' => 'Last', 'next' => 'Next', 'previous' => 'Previous'],
                'emptyTable' => 'No data available'
            ]) ?>
        });

        // Column filters
        $('#filter-document').on('change', function () { table.column(1).search(this.value).draw(); });
        $('#filter-employee').on('change', function () { table.column(3).search(this.value).draw(); });
        $('#filter-sign-type').on('change', function () { table.column(5).search(this.value).draw(); });
        $('#filter-status').on('change', function () { table.column(6).search(this.value).draw(); });
        $('#resetFilters').on('click', function () {
            $('#filter-document').val('');
            $('#filter-employee').val('');
            $('#filter-sign-type').val('');
            $('#filter-status').val('');
            table.column(1).search('').draw();
            table.column(3).search('').draw();
            table.column(5).search('').draw();
            table.column(6).search('').draw();
        });

        // SweetAlert2 delete confirmation
        $('.btn-delete').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var doc = $(this).data('doc');
            Swal.fire({
                title: '<?= __('confirm_delete') ?>',
                text: '<?= __('confirm_delete') ?> ' + doc,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<?= __('yes_delete') ?>',
                cancelButtonText: '<?= __('cancel_btn') ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_id=' + id;
                }
            });
        });

        <?php
        $success_message = $_SESSION['success_message'] ?? null;
        unset($_SESSION['success_message']);
        ?>
        <?php if ($success_message): ?>
        Swal.fire({
            icon: 'success',
            title: '<?= __('success') ?>',
            text: '<?= htmlspecialchars($success_message, ENT_QUOTES) ?>',
            timer: 3000,
            showConfirmButton: true
        });
        <?php endif; ?>
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
