<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-documents.php";

if (!$current_user_id) {
    die(__('login_required'));
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function statusBadge($status) {
    $map = [
        'draft' => ['secondary', __('status_draft')],
        'approved' => ['success', __('status_approved')],
        'archived' => ['info', __('status_archived')],
        'cancelled' => ['danger', __('status_cancelled')],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
}

function formatBadge($fmt) {
    $fmt = strtolower($fmt);
    $map = ['pdf'=>'primary','doc'=>'info','docx'=>'info','xls'=>'success','xlsx'=>'success','jpg'=>'warning','png'=>'warning','gif'=>'warning'];
    $cls = $map[$fmt] ?? 'secondary';
    return '<span class="badge badge-' . $cls . '">' . strtoupper($fmt) . '</span>';
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

    $can_view = 0; $can_add = 0; $can_edit = 0; $can_delete = 0;
    $can_approve = 0; $can_archive = 0; $can_view_archive = 0;
    if ($current_page_id > 0) {
        $accessSql = "SELECT can_view, can_add, can_edit, can_delete,
                             can_approve, can_archive, can_view_archive
                      FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
        $accessStmt = $pdo->prepare($accessSql);
        $accessStmt->execute([$current_user_id, $current_page_id]);
        $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
        $can_view         = $permissions['can_view']         ?? 0;
        $can_add          = $permissions['can_add']          ?? 0;
        $can_edit         = $permissions['can_edit']         ?? 0;
        $can_delete       = $permissions['can_delete']       ?? 0;
        $can_approve      = $permissions['can_approve']      ?? 0;
        $can_archive      = $permissions['can_archive']      ?? 0;
        $can_view_archive = $permissions['can_view_archive'] ?? 0;
    }

    if (!$can_view) {
        $_SESSION['warning_message'] = __('no_access_page');
        header("Location: ../index.php");
        exit;
    }

    // Handle status change (draft -> approved -> archived)
    if (isset($_GET['status_id']) && isset($_GET['new_status'])) {
        $sid = (int)$_GET['status_id'];
        $new_status = $_GET['new_status'];
        $allowed = ['approved', 'archived', 'cancelled'];

        // ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„طµظ„ط§ط­ظٹط© ظ‚ط¨ظ„ طھظ†ظپظٹط° ط£ظٹ طھط؛ظٹظٹط±
        $permOk = false;
        if ($new_status === 'approved'  && $can_approve == 1) $permOk = true;
        if ($new_status === 'archived'  && $can_archive == 1) $permOk = true;
        if ($new_status === 'cancelled' && $can_edit    == 1) $permOk = true;

        if (!$permOk) {
            $_SESSION['error_message'] = __('no_permission');
            header("Location: show-documents.php");
            exit;
        }

        if (in_array($new_status, $allowed)) {
            $oldStmt = $pdo->prepare("SELECT status FROM " . TBL_DOCUMENTS . " WHERE id = ?");
            $oldStmt->execute([$sid]);
            $old_status = $oldStmt->fetchColumn();

            // ط¹ظ†ط¯ ط§ظ„ط§ط¹طھظ…ط§ط¯: طھط¶ظ…ظٹظ† طھظˆظ‚ظٹط¹ ط§ظ„ظ…ظˆط¸ظپ ط§ظ„ظ…ط¹طھظ…ط¯ طھظ„ظ‚ط§ط¦ظٹط§ظ‹ ظپظٹ ط¢ط®ط± طµظپط­ط© ظ…ظ† ط§ظ„ظ€ PDF
                if ($new_status === 'approved') {
                    $docStmt = $pdo->prepare("SELECT file_format, file_path, workflow_id FROM " . TBL_DOCUMENTS . " WHERE id = ?");
                    $docStmt->execute([$sid]);
                    $docInfo = $docStmt->fetch(PDO::FETCH_ASSOC);

                    // 1. ابحث عن الموظف المرتبط بالمستخدم الحالي
                    $empStmt = $pdo->prepare("SELECT id, full_name, signature_image FROM " . TBL_EMPLOYEES . " WHERE user_id = ? AND is_active = 1 LIMIT 1");
                    $empStmt->execute([$current_user_id]);
                    $approver_emp = $empStmt->fetch(PDO::FETCH_ASSOC);

                    $canApprove    = false;
                    $targetEmpId   = $approver_emp['id'] ?? 0;

                    if (!empty($docInfo['workflow_id'])) {
                        // 2. تحقق أن هذا الموظف لديه مرحلة معلقة في سجلات الاعتماد
                        $apprStmt = $pdo->prepare(
                            "SELECT id FROM " . TBL_DOC_APPROVALS .
                            " WHERE document_id = ? AND employee_id = ? AND status = 'pending' LIMIT 1"
                        );
                        $apprStmt->execute([$sid, $targetEmpId]);
                        $pendingApproval = $apprStmt->fetch(PDO::FETCH_ASSOC);

                        if ($pendingApproval) {
                            $canApprove = true;
                        } else {
                            // 3. إذا لم يُوجد في قائمة الاعتماد — ابحث عن المرحلة الأولى المعلقة
                            //    وتحقق إذا كان المستخدم الحالي يملك أذونات المدير العام
                            $firstPendingStmt = $pdo->prepare(
                                "SELECT da.employee_id, e.full_name, e.signature_image
                                 FROM " . TBL_DOC_APPROVALS . " da
                                 JOIN " . TBL_EMPLOYEES . " e ON e.id = da.employee_id
                                 WHERE da.document_id = ? AND da.status = 'pending'
                                 ORDER BY da.id ASC LIMIT 1"
                            );
                            $firstPendingStmt->execute([$sid]);
                            $firstPending = $firstPendingStmt->fetch(PDO::FETCH_ASSOC);

                            if ($firstPending) {
                                // الموظف المنتظر هو من يجب أن يعتمد
                                $_SESSION['warning_message'] = sprintf(
                                    __('no_pending_approval') . ' — %s',
                                    $firstPending['full_name']
                                );
                            } else {
                                $_SESSION['warning_message'] = __('no_pending_approval');
                            }
                        }
                    } else {
                        // لا توجد سياسة — أي مستخدم مخوّل يمكنه الاعتماد
                        $canApprove = true;
                    }

                    if ($canApprove) {
                        $isPdfDoc = $docInfo
                            && strtolower($docInfo['file_format'] ?? '') === 'pdf'
                            && !empty($docInfo['file_path']);

                        if ($isPdfDoc && $approver_emp) {
                            if (!empty($approver_emp['signature_image'])) {
                                // 4. أضف التوقيع تلقائياً مع تسجيل تاريخ الاعتماد
                                $signResult = auto_sign_document($pdo, $sid, $approver_emp['id'], '');
                                if ($signResult['success']) {
                                    if (!empty($signResult['signed'])) {
                                        $_SESSION['success_message'] = __('auto_sign_success');
                                    } else {
                                        $_SESSION['success_message'] = __('doc_approved_ok');
                                        $_SESSION['warning_message'] = __('auto_sign_no_sig');
                                    }
                                } else {
                                    $_SESSION['success_message'] = __('doc_approved_ok');
                                    $_SESSION['warning_message'] = __('auto_sign_pdf_fail');
                                }
                            } else {
                                $_SESSION['success_message'] = __('doc_approved_ok');
                                $_SESSION['warning_message'] = __('auto_sign_no_sig');
                            }
                        } elseif ($isPdfDoc && !$approver_emp) {
                            $_SESSION['success_message'] = __('doc_approved_ok');
                            $_SESSION['warning_message'] = __('auto_sign_no_emp');
                        } else {
                            $_SESSION['success_message'] = __('doc_approved_ok');
                        }

                        // 5. تحديث حالة الوثيقة
                        if (!empty($docInfo['workflow_id'])) {
                            $remainStmt = $pdo->prepare(
                                "SELECT COUNT(*) FROM " . TBL_DOC_APPROVALS .
                                " WHERE document_id = ? AND status = 'pending'"
                            );
                            $remainStmt->execute([$sid]);
                            $remaining = (int)$remainStmt->fetchColumn();
                            if ($remaining === 0) {
                                $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET status='approved', updated_at=NOW() WHERE id=?")->execute([$sid]);
                            }
                        } else {
                            $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET status=?, updated_at=NOW() WHERE id=?")->execute([$new_status, $sid]);
                        }
                        log_action($pdo, 'update', 'document', $sid, ['status' => $old_status], ['status' => $new_status]);
                    }
                }
            }
        header("Location: show-documents.php");
        exit;
    }

    // Handle delete
    if (isset($_GET['delete_id'])) {
        if ($can_delete != 1) {
            $_SESSION['error_message'] = __('no_permission');
            header("Location: show-documents.php");
            exit;
        }
        $did = (int)$_GET['delete_id'];
        $pdo->prepare("DELETE FROM " . TBL_DOCUMENTS . " WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'document', $did, [], ['id' => $did]);
        header("Location: show-documents.php");
        exit;
    }

    // ── Server-Side DataTable: لا نُحمِّل البيانات هنا — API يتولى ذلك ──
    // $docs محذوفة → تحميل 50,000 وثيقة كان يُسبب بطءاً شديداً
    // البيانات تُجلب الآن في admin/api/documents_dt.php حسب الطلب

    // Populate filter dropdowns
    $docTypes = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_DOC_TYPES . " WHERE is_active = 1 GROUP BY name ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $docCategories = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_DOC_CATEGORIES . " WHERE is_active = 1 GROUP BY name ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT DISTINCT department FROM " . TBL_DOCUMENTS . " WHERE department IS NOT NULL AND department != '' ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);

    // ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ظ…ظˆط¸ظپ ط§ظ„ظ…ط±طھط¨ط· ط¨ط§ظ„ظ…ط³طھط®ط¯ظ… ط§ظ„ط­ط§ظ„ظٹ (ظ„ظ„ط§ط¹طھظ…ط§ط¯)
    $approver_info = $pdo->prepare("SELECT full_name, signature_image FROM " . TBL_EMPLOYEES . " WHERE user_id = ? AND is_active = 1");
    $approver_info->execute([$current_user_id]);
    $approver_data = $approver_info->fetch(PDO::FETCH_ASSOC);

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
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-ticket .card-body { padding: 20px; }
        .filter-inline select { border-radius: 8px; border: 1px solid #ced4da; padding: 4px 10px; font-size: .85rem; }
        .filter-inline select:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        #documentsTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #documentsTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-weight: 600; border-radius: 20px; font-size: .8rem; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        .btn-group-action { display: flex; gap: 4px; justify-content: center; flex-wrap: nowrap; }
        .status-link { font-size: 0.75rem; padding: 2px 6px; border-radius: 3px; text-decoration: none; white-space: nowrap; }
        .status-link:hover { text-decoration: none; opacity: 0.8; }
        <?php if (isRtl()): ?>.dataTables_filter { text-align: left !important; }<?php else: ?>.dataTables_filter { text-align: right !important; }<?php endif; ?>
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
                        <h4><i class="fas fa-file-alt ml-2"></i> <?= __('doc_title') ?> <?= langSwitcher() ?></h4>
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
                            <div class="d-flex align-items-center" style="gap:8px;flex-wrap:wrap;">
                                <select id="filter-type" class="form-control-sm filter-inline">
                                    <option value=""><?= __('all') ?> — <?= __('doc_filter_type') ?></option>
                                    <?php foreach ($docTypes as $dt): ?>
                                        <option value="<?= htmlspecialchars($dt['name']) ?>"><?= htmlspecialchars($dt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filter-category" class="form-control-sm filter-inline">
                                    <option value=""><?= __('all') ?> — <?= __('doc_filter_category') ?></option>
                                    <?php foreach ($docCategories as $dc): ?>
                                        <option value="<?= htmlspecialchars($dc['name']) ?>"><?= htmlspecialchars($dc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filter-department" class="form-control-sm filter-inline">
                                    <option value=""><?= __('all') ?> — <?= __('doc_filter_dept') ?></option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filter-status" class="form-control-sm filter-inline">
                                    <option value=""><?= __('all') ?> — <?= __('doc_filter_status') ?></option>
                                    <option value="<?= __('status_draft') ?>"><?= __('status_draft') ?></option>
                                    <option value="<?= __('status_approved') ?>"><?= __('status_approved') ?></option>
                                    <option value="<?= __('status_archived') ?>"><?= __('status_archived') ?></option>
                                    <option value="<?= __('status_cancelled') ?>"><?= __('status_cancelled') ?></option>
                                </select>
                                <button id="resetFilters" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> <?= __('reset') ?></button>
                            </div>
                            <div style="display:flex;gap:6px;">
                                <?php if ($can_view_archive == 1): ?>
                                    <a href="archive-browser.php" class="btn btn-secondary btn-sm"><i class="fas fa-archive"></i> <?= __('archive_browse') ?></a>
                                <?php endif; ?>
                                <?php if ($can_add == 1): ?>
                                    <a href="../forms/add-document.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?= __('doc_add') ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="documentsTable" class="table table-hover table-bordered">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th><?= __('doc_number') ?></th>
                                            <th><?= __('doc_title_field') ?></th>
                                            <th><?= __('doc_type') ?></th>
                                            <th><?= __('doc_category') ?></th>
                                            <th><?= __('doc_department') ?></th>
                                            <th><?= getLang() === 'ar' ? 'القسم' : 'Dept. (Approver)' ?></th>
                                            <th><?= getLang() === 'ar' ? 'اسم الموظف المعتمد' : 'Approver Name' ?></th>
                                            <th><?= __('doc_format') ?></th>
                                            <th><?= __('doc_size') ?></th>
                                            <th><?= __('doc_status') ?></th>
                                            <th><?= __('doc_created_at') ?></th>
                                            <th><?= __('actions') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- DataTable Server-Side يملأ هذا القسم تلقائياً -->
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
    var systemName    = <?= json_encode($company_name) ?>;
    var systemNameEn  = <?= json_encode($company_name_en) ?>;
    var systemAddress = <?= json_encode($company_address) ?>;
    var logoPath      = <?= json_encode($base_assets_url . '/admin/dist/img/' . rawurlencode($company_logo)) ?>;
    var logoDataUri   = <?= json_encode($logo_data_uri) ?>;
    var sessionUserName = <?= json_encode($current_user_name) ?>;
    var currentLang = "<?= getLang() ?>";
    var displayCompanyName = currentLang === 'en' ? systemNameEn : systemName;

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
    var confirmApprove = "<?= __('confirm_approve') ?>";
    var confirmArchive = "<?= __('confirm_archive') ?>";
    var approveTitle = "<?= __('status_approved') ?>";
    var archiveTitle = "<?= __('status_archived') ?>";
    var yesApprove = "<?= __('yes_approve') ?>";
    var yesArchive = "<?= __('yes_archive') ?>";
    var linkedEmpName = "<?= htmlspecialchars($approver_data['full_name'] ?? '', ENT_QUOTES) ?>";
    var linkedEmpSig = "<?= htmlspecialchars($approver_data['signature_image'] ?? '', ENT_QUOTES) ?>";
    var linkedEmpSigUrl = linkedEmpSig ? '../../uploads/' + (linkedEmpSig.indexOf('signatures/') === 0 ? '' : 'signatures/') + linkedEmpSig : '';
    var autoSignLabel = "<?= __('auto_sign_with') ?>";
    var autoSignNoLabel = "<?= __('auto_sign_no_emp') ?>";

    $(document).ready(function() {
        const currentDate = new Date().toLocaleDateString(currentLang === 'ar' ? 'ar-EG' : 'en-US');
        const printDateLabel = currentLang === 'ar' ? "<?= __('report_print_date') ?>" : "<?= __('report_print_date_en') ?>";
        const reportDateLabel = currentLang === 'ar' ? "<?= __('report_date_prefix') ?>" : "<?= __('report_date_prefix_en') ?>";

        // ══════════════════════════════════════════════════════
        // DataTable — Server-Side Processing
        // البيانات تُجلب من API بدلاً من تحميل 50,000 صف
        // ══════════════════════════════════════════════════════
        var activeFilters = {};  // تتبع الفلاتر النشطة

        // دالة مساعدة لإرسال الفلاتر مع كل طلب
        function buildAjaxData(d) {
            d.type_id     = $('#filter-type').val()     || '';
            d.category_id = $('#filter-category').val() || '';
            d.department  = $('#filter-department').val()|| '';
            d.status      = $('#filter-status').val()   || '';
        }

        var table = $('#documentsTable').DataTable({
            // ── Server-Side Processing ─────────────────────────
            "processing":  true,
            "serverSide":  true,
            "ajax": {
                "url":  "../../api/documents_dt.php",
                "type": "POST",
                "data": buildAjaxData,
                "error": function(xhr, error, thrown) {
                    console.error('DataTable AJAX error:', error, thrown);
                    Swal.fire({icon:'error',title:'خطأ',text:'تعذر تحميل البيانات. تحقق من الاتصال.',timer:3000});
                }
            },
            // ── إعدادات أعمدة التحكم ───────────────────────────
            "columnDefs": [
                { "orderable": false, "targets": [0, 6, 7, 12] },   // # + معتمِد + إجراءات
                { "searchable": false, "targets": [0, 8, 9, 12] },  // # + حجم + إجراءات
            ],
            // ── التخزين المؤقت الذكي ───────────────────────────
            "deferRender": true,
            "stateSave":   true,   // حفظ حالة الصفحة والبحث
            "pageLength":  25,
            // ── DOM والأزرار ────────────────────────────────────
            "dom": "<'row mb-3'<'col-md-6'B><'col-md-6 text-<?= isRtl() ? 'left' : 'right' ?>'f>>" +
                   "<'row'<'col-12'<'dt-processing-bar'>>>" +
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

        // ── فلاتر Server-Side ─────────────────────────────────────
        // لا نستخدم column().search() لأن البيانات تأتي من السيرفر
        $('#filter-type, #filter-category, #filter-department, #filter-status').on('change', function() {
            table.draw();  // يُرسل الفلاتر عبر buildAjaxData دفعةً واحدة
        });

        $('#resetFilters').on('click', function() {
            $('#filter-type, #filter-category, #filter-department, #filter-status').val('');
            table.search('').draw();
        });

        // ── أحداث مُفوَّضة (Event Delegation) ─────────────────────
        // ضروري مع Server-Side لأن الصفوف تُعاد رسمها مع كل صفحة

        // حذف
        $(document).on('click', '.delete-doc-btn', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            Swal.fire({
                title: '<?= __('confirm_delete') ?>',
                text: confirmDeleteText + '?',
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

        // SweetAlert2 approve confirmation
        $(document).on('click', '.approve-btn', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var title = $(this).data('title');

            var empHtml = '';
            if (linkedEmpName) {
                var sigImg = linkedEmpSigUrl ? '<img src="' + linkedEmpSigUrl + '" style="max-height:50px;border:1px solid #ddd;border-radius:4px;padding:2px;display:inline-block;vertical-align:middle;margin-<?= isRtl() ? 'left' : 'right' ?>:8px;">' : '';
                empHtml = '<div style="background:#f0f9ff;border:1px solid #b8daff;border-radius:8px;padding:12px;margin-top:12px;text-align:<?= isRtl() ? 'right' : 'left' ?>;">' +
                    '<div style="font-size:14px;color:#004085;"><?= __('auto_sign_with') ?>:</div>' +
                    '<div style="display:flex;align-items:center;margin-top:6px;gap:8px;<?= isRtl() ? 'flex-direction:row-reverse;' : '' ?>">' +
                    sigImg +
                    '<strong style="font-size:16px;">' + linkedEmpName + '</strong>' +
                    '</div></div>';
            } else {
                empHtml = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px;margin-top:12px;text-align:center;font-size:14px;color:#856404;">' +
                    '<?= __('auto_sign_no_emp') ?>' +
                    '</div>';
            }

            Swal.fire({
                title: '<span style="font-size:22px;">' + approveTitle + '</span>',
                html: '<div style="font-size:16px;margin-bottom:8px;">' + confirmApprove + ' <strong>"' + title + '"</strong></div>' + empHtml,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<?= __('yes_approve') ?>',
                cancelButtonText: cancelBtn,
                reverseButtons: <?= isRtl() ? 'true' : 'false' ?>
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?status_id=' + id + '&new_status=approved';
                }
            });
        });

        // أرشفة — مُفوَّض للصفوف الديناميكية
        $(document).on('click', '.archive-btn', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var title = $(this).data('title') || '';
            Swal.fire({
                title: '<span style="font-size:22px;">' + archiveTitle + '</span>',
                html: '<div style="font-size:16px;">' + confirmArchive + ' <strong>"' + title + '"</strong></div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#17a2b8',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<?= __('yes_archive') ?>',
                cancelButtonText: cancelBtn,
                reverseButtons: <?= isRtl() ? 'true' : 'false' ?>
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?status_id=' + id + '&new_status=archived';
                }
            });
        });

        <?php
        $success_message = $_SESSION['success_message'] ?? null;
        $warning_message = $_SESSION['warning_message'] ?? null;
        $error_message   = $_SESSION['error_message']   ?? null;
        unset($_SESSION['success_message'], $_SESSION['warning_message'], $_SESSION['error_message']);
        ?>
        <?php if ($success_message): ?>
        Swal.fire({
            icon: 'success',
            title: '<?= __('success') ?>',
            text: '<?= htmlspecialchars($success_message ?? '', ENT_QUOTES) ?>',
            confirmButtonText: '<?= __('ok') ?>',
            confirmButtonColor: '#28a745',
            timer: 3000,
            timerProgressBar: true
        });
        <?php endif; ?>
        <?php if ($warning_message): ?>
        Swal.fire({
            icon: 'warning',
            title: '<?= __('warning') ?>',
            text: '<?= htmlspecialchars($warning_message ?? '', ENT_QUOTES) ?>',
            confirmButtonText: '<?= __('ok') ?>',
            confirmButtonColor: '#ffc107'
        });
        <?php endif; ?>
        <?php if ($error_message): ?>
        Swal.fire({
            icon: 'error',
            title: '<?= __('error') ?>',
            text: '<?= htmlspecialchars($error_message ?? '', ENT_QUOTES) ?>',
            confirmButtonText: '<?= __('ok') ?>',
            confirmButtonColor: '#dc3545'
        });
        <?php endif; ?>
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
