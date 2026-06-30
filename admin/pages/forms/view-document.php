<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    die(__('login_required'));
}

$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($doc_id <= 0) {
    die(__('invalid_id'));
}

// ط¬ظ„ط¨ ط§ظ„ظˆط«ظٹظ‚ط©
$sql = "SELECT d.*, t.name AS type_name, c.name AS category_name
        FROM " . TBL_DOCUMENTS . " d
        LEFT JOIN " . TBL_DOC_TYPES . " t ON d.type_id = t.id
        LEFT JOIN " . TBL_DOC_CATEGORIES . " c ON d.category_id = c.id
        WHERE d.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$doc_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die(__('doc_not_found'));
}

// ط¬ظ„ط¨ ط§ظ„طھظˆظ‚ظٹط¹ط§طھ
$sigSql = "SELECT s.*, e.full_name AS employee_name, e.job_title, e.signature_image AS emp_signature
           FROM " . TBL_SIGNATURES . " s
           JOIN " . TBL_EMPLOYEES . " e ON s.employee_id = e.id
           WHERE s.document_id = ?
           ORDER BY s.page_number, s.id";
$sigStmt = $pdo->prepare($sigSql);
$sigStmt->execute([$doc_id]);
$signatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);

// ط¥ط­طµط§ط¦ظٹط§طھ ط§ظ„طھظˆظ‚ظٹط¹ط§طھ
$total_signatures   = count($signatures);
$pending_signatures = 0;
$signed_signatures  = 0;
$rejected_signatures = 0;
foreach ($signatures as $sig) {
    if ($sig['status'] === 'signed')   $signed_signatures++;
    if ($sig['status'] === 'pending')  $pending_signatures++;
    if ($sig['status'] === 'rejected') $rejected_signatures++;
}

// ربط المستخدم الحالي بموظف
$current_employee_id  = null;
$current_employee_sig = null;
$has_signed           = false;
$can_sign_now         = false;

$linkSql = "SELECT e.id, e.full_name, e.signature_image, e.department, e.job_title
            FROM " . TBL_EMPLOYEES . " e
            JOIN sys_users u ON u.email = e.email
            WHERE u.id = ? AND e.can_sign = 1";
$linkStmt = $pdo->prepare($linkSql);
$linkStmt->execute([$current_user_id]);
$empRow = $linkStmt->fetch(PDO::FETCH_ASSOC);
if ($empRow) {
    $current_employee_id  = (int)$empRow['id'];
    $current_employee_sig = $empRow['signature_image'];
    foreach ($signatures as $sig) {
        if ((int)$sig['employee_id'] === $current_employee_id) {
            $has_signed = true;
            break;
        }
    }
}

// جلب سياسة الاعتماد المرتبطة بالوثيقة ومراحلها بالترتيب التسلسلي
$doc_workflow_id  = (int)($doc['workflow_id'] ?? 0);
$workflow_approvals = [];
$signers            = [];

if ($doc_workflow_id > 0) {
    $apprSql = "SELECT da.id AS approval_id, da.employee_id, da.status AS approval_status,
                       da.signed_at AS approval_signed_at,
                       ast.stage_order, ast.stage_name,
                       e.full_name AS employee_name, e.job_title, e.department, e.signature_image
                FROM " . TBL_DOC_APPROVALS . " da
                JOIN " . TBL_APPROVAL_STAGES . " ast ON da.stage_id = ast.id
                JOIN " . TBL_EMPLOYEES . " e ON da.employee_id = e.id
                WHERE da.document_id = ? AND da.workflow_id = ?
                ORDER BY ast.stage_order ASC";
    $apprStmt = $pdo->prepare($apprSql);
    $apprStmt->execute([$doc_id, $doc_workflow_id]);
    $workflow_approvals = $apprStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($workflow_approvals as $appr) {
        $signers[] = [
            'id'              => $appr['employee_id'],
            'full_name'       => $appr['employee_name'],
            'job_title'       => $appr['job_title'],
            'department'      => $appr['department'],
            'signature_image' => $appr['signature_image'],
        ];
    }

    // هل حان دور الموظف الحالي؟ (تحقق تسلسلي)
    if ($current_employee_id && !$has_signed) {
        foreach ($workflow_approvals as $appr) {
            if (
                (int)$appr['employee_id'] === $current_employee_id
                && $appr['approval_status'] === 'pending'
            ) {
                $myOrder  = (int)$appr['stage_order'];
                $prevDone = true;
                foreach ($workflow_approvals as $prev) {
                    if (
                        (int)$prev['stage_order'] < $myOrder
                        && $prev['approval_status'] !== 'approved'
                    ) {
                        $prevDone = false;
                        break;
                    }
                }
                $can_sign_now = $prevDone;
                break;
            }
        }
    }
} else {
    // لا توجد سياسة اعتماد – أي موظف مخوّل يمكنه التوقيع
    $empSql  = "SELECT id, full_name, job_title, department, signature_image
                FROM " . TBL_EMPLOYEES . "
                WHERE can_sign = 1 AND is_active = 1
                ORDER BY full_name";
    $signers = $pdo->query($empSql)->fetchAll(PDO::FETCH_ASSOC);
    if ($current_employee_id && !$has_signed) {
        $can_sign_now = true;
    }
}

// ط§ظ„طµظ„ط§ط­ظٹط§طھ
$page_path = "pages/forms/view-document.php";
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$can_view = 0;
$can_add = 0;
$can_edit = 0;
$can_delete = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_view, can_add, can_edit, can_delete FROM " . TBL_USER_MENU_ACCESS . " WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_view   = $permissions['can_view'] ?? 0;
    $can_add    = $permissions['can_add'] ?? 0;
    $can_edit   = $permissions['can_edit'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
}

function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function statusBadge($status)
{
    $map = [
        'draft'    => ['secondary', __('status_draft')],
        'approved' => ['success',   __('status_approved')],
        'archived' => ['info',      __('status_archived')],
        'cancelled' => ['danger',    __('status_cancelled')],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
}

function sigStatusBadge($status)
{
    $map = [
        'pending'  => ['warning', __('sig_pending')],
        'signed'   => ['success', __('sig_signed')],
        'rejected' => ['danger',  __('sig_rejected')],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
}

$file_path_abs = __DIR__ . '/../../../' . $doc['file_path'];
$file_exists   = file_exists($file_path_abs);

// ط±ظˆط§ط¨ط· ط§ظ„ظ…ظ„ظپ â€” pdf-viewer.php ظ„ظ„ظ€ PDFطŒ ظˆserve-file.php ظ„ط¨ظ‚ظٹط© ط§ظ„ط£ظ†ظˆط§ط¹
$dl_name = $doc['file_name'] ?: ('document.' . strtolower($doc['file_format'] ?? 'pdf'));
$is_pdf_fmt = strtolower($doc['file_format'] ?? '') === 'pdf';

if ($is_pdf_fmt) {
    $file_url          = 'pdf-viewer.php?id=' . $doc_id;
    $file_url_download = 'pdf-viewer.php?id=' . $doc_id . '&download=1';
} else {
    $file_url          = $doc['file_path'] ? 'serve-file.php?path=' . urlencode($doc['file_path']) : '';
    $file_url_download = $doc['file_path']
        ? 'serve-file.php?path=' . urlencode($doc['file_path']) . '&download=1&name=' . urlencode($dl_name)
        : '';
}

$is_pdf = strtolower($doc['file_format'] ?? '') === 'pdf';
$is_image = in_array(strtolower($doc['file_format'] ?? ''), ['jpg', 'jpeg', 'png', 'gif']);
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= __('view_document') ?> - <?= htmlspecialchars($doc['title'] ?? '') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.min.css">
    <style>
        html,
        body {
            overflow-x: hidden;
        }

        body {
            direction: <?= isRtl() ? 'rtl' : 'ltr' ?>;
            text-align: <?= isRtl() ? 'right' : 'left' ?>;
        }

        :root {
            --wasl-primary: #1e3a5f;
            --wasl-accent: #2d6da8;
        }

        .signature-zone {
            position: relative;
            border: 2px dashed #ccc;
            background: #fafafa;
            cursor: crosshair;
            min-height: 400px;
            overflow: hidden;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
        }

        .signature-zone:hover {
            border-color: var(--wasl-accent);
        }

        .signature-marker {
            position: absolute;
            width: 180px;
            height: 70px;
            border: 2px dashed #e74c3c;
            background: rgba(231, 76, 60, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 10;
            border-radius: 4px;
        }

        .signature-marker img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .signature-marker .badge-coord {
            position: absolute;
            bottom: -22px;
            font-size: 11px;
            background: #333;
            color: #fff;
            padding: 0 6px;
            border-radius: 3px;
            white-space: nowrap;
        }

        .sig-tooltip {
            position: absolute;
            pointer-events: none;
            z-index: 999;
            background: rgba(0, 0, 0, 0.75);
            color: #fff;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 13px;
            display: none;
            white-space: nowrap;
        }

        .sig-preview-follow {
            position: absolute;
            pointer-events: none;
            z-index: 998;
            opacity: 0.6;
            display: none;
        }

        .sig-preview-follow img {
            max-width: 150px;
            max-height: 50px;
        }

        .info-table tr td:first-child {
            font-weight: 600;
            width: 40%;
            background: #f8f9fa;
        }

        .info-table tr td:last-child {
            width: 60%;
        }

        .doc-iframe {
            width: 100%;
            height: 860px;
            border: none;
            display: block;
        }

        .doc-viewer-toolbar {
            background: #f1f3f5;
            border-bottom: 1px solid #dee2e6;
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .doc-preview-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 420px;
            background: #f8f9fa;
            padding: 40px 20px;
            text-align: center;
        }

        .doc-image-viewer {
            background: #2b2b2b;
            text-align: center;
            padding: 20px;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .doc-image-viewer img {
            max-width: 100%;
            max-height: 840px;
            object-fit: contain;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
            cursor: zoom-in;
        }

        .summary-stat {
            text-align: center;
            padding: 10px;
            border-radius: 6px;
        }

        .summary-stat .num {
            font-size: 24px;
            font-weight: 700;
        }

        .summary-stat .lbl {
            font-size: 13px;
            color: #6c757d;
        }

        @media (max-width: 768px) {
            .doc-iframe {
                height: 500px;
            }

            .signature-zone {
                min-height: 250px;
            }
        }

        .modal-xl .modal-lg {
            max-width: 90%;
        }
    </style>
</head>

<body class="hold-transition layout-fixed">
    <div class="wrapper">
        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1><i class="fas fa-file-alt ml-2"></i> <?= htmlspecialchars($doc['title'] ?? '') ?>
                                <?= langSwitcher() ?></h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-<?= isRtl() ? 'right' : 'left' ?>">
                                <li class="breadcrumb-item"><a href="../../index.php"><?= __('home') ?></a></li>
                                <li class="breadcrumb-item"><a
                                        href="../tables/show-documents.php"><?= __('docs_management') ?></a></li>
                                <li class="breadcrumb-item active"><?= __('view_document') ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <div class="row">
                        <!-- ط§ظ„ط¹ظ…ظˆط¯ ط§ظ„ط£ظٹظ…ظ†: ط¹ط§ط±ط¶ PDF -->
                        <div class="col-md-8">
                            <div class="card card-outline card-info shadow">
                                <div class="card-header">
                                    <h3 class="card-title" style="float:<?= isRtl() ? 'right' : 'left' ?>;">
                                        <?php
                                        $hdrFmt = strtolower($doc['file_format'] ?? '');
                                        $hdrIcon = 'fa-file-alt';
                                        if ($hdrFmt === 'pdf') $hdrIcon = 'fa-file-pdf';
                                        elseif (in_array($hdrFmt, ['doc', 'docx'])) $hdrIcon = 'fa-file-word';
                                        elseif (in_array($hdrFmt, ['xls', 'xlsx'])) $hdrIcon = 'fa-file-excel';
                                        elseif (in_array($hdrFmt, ['jpg', 'jpeg', 'png', 'gif'])) $hdrIcon = 'fa-file-image';
                                        ?>
                                        <i class="fas <?= $hdrIcon ?> ml-1"></i> <?= __('doc_view') ?>
                                    </h3>
                                </div>
                                <div class="card-body p-0">
                                    <?php if ($file_exists): ?>
                                        <!-- ط´ط±ظٹط· ط£ط¯ظˆط§طھ ط§ظ„ط¹ط§ط±ط¶ -->
                                        <div class="doc-viewer-toolbar">
                                            <div>
                                                <?php
                                                $fmt = strtolower($doc['file_format'] ?? '');
                                                $fmtColors = ['pdf' => 'danger', 'doc' => 'primary', 'docx' => 'primary', 'xls' => 'success', 'xlsx' => 'success', 'jpg' => 'warning', 'png' => 'warning', 'gif' => 'warning'];
                                                $fmtCls = $fmtColors[$fmt] ?? 'secondary';
                                                ?>
                                                <span
                                                    class="badge badge-<?= $fmtCls ?> mr-2"><?= strtoupper($doc['file_format'] ?? '') ?></span>
                                                <small
                                                    class="text-muted"><?= htmlspecialchars($doc['file_name'] ?? '') ?></small>
                                                <?php if ($doc['file_size']): ?>
                                                    <small
                                                        class="text-muted ml-2">(<?= formatFileSize((int)$doc['file_size']) ?>)</small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex gap-2" style="gap:6px;">
                                                <a href="<?= htmlspecialchars($file_url_download) ?>"
                                                    class="btn btn-success btn-sm">
                                                    <i class="fas fa-download <?= isRtl() ? 'ml-1' : 'mr-1' ?>"></i>
                                                    <?= __('doc_download') ?>
                                                </a>
                                                <?php if (!$is_pdf): ?>
                                                    <a href="<?= htmlspecialchars($file_url) ?>" target="_blank"
                                                        class="btn btn-info btn-sm">
                                                        <i
                                                            class="fas fa-external-link-alt <?= isRtl() ? 'ml-1' : 'mr-1' ?>"></i>
                                                        <?= __('doc_open_new_tab') ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- ظ…ظ†ط·ظ‚ط© ط§ظ„ط¹ط±ط¶ -->
                                        <?php if ($is_pdf): ?>
                                            <!-- ط¹ط§ط±ط¶ PDF: ظٹظڈظپطھط­ ظپظٹ طھط¨ظˆظٹط¨ ط¬ط¯ظٹط¯ ظ…ط¨ط§ط´ط±ط©ظ‹ -->
                                            <div class="doc-preview-placeholder" style="min-height:480px;">
                                                <div class="mb-4" style="position:relative;">
                                                    <i class="fas fa-file-pdf"
                                                        style="font-size:80px;color:#e74c3c;filter:drop-shadow(0 4px 8px rgba(231,76,60,0.3));"></i>
                                                </div>
                                                <h5 class="mb-2 text-dark"><?= htmlspecialchars($doc['title'] ?? '') ?></h5>
                                                <p class="text-muted mb-1">
                                                    <?php if ($doc['file_size']): ?>
                                                        <?= formatFileSize((int)$doc['file_size']) ?> &nbsp;|&nbsp;
                                                    <?php endif; ?>
                                                    <span class="badge badge-danger">PDF</span>
                                                </p>
                                                <p class="text-muted mb-4 small">
                                                    <?= htmlspecialchars($doc['file_name'] ?? '') ?></p>

                                                <!-- ط²ط± ط§ظ„ط§ط³طھط¹ط±ط§ط¶ ط§ظ„ط±ط¦ظٹط³ظٹ -->
                                                <a href="<?= htmlspecialchars($file_url) ?>" target="_blank"
                                                    class="btn btn-danger btn-lg px-5 mb-3 shadow"
                                                    style="font-size:1.1rem;border-radius:8px;">
                                                    <i class="fas fa-eye <?= isRtl() ? 'ml-2' : 'mr-2' ?>"></i>
                                                    <?= __('doc_open_new_tab') ?>
                                                </a>
                                                <br>
                                                <!-- ط²ط± ط§ظ„طھط­ظ…ظٹظ„ -->
                                                <a href="<?= htmlspecialchars($file_url_download) ?>"
                                                    class="btn btn-outline-success btn-sm">
                                                    <i class="fas fa-download <?= isRtl() ? 'ml-1' : 'mr-1' ?>"></i>
                                                    <?= __('doc_download') ?>
                                                </a>
                                            </div>

                                        <?php elseif ($is_image): ?>
                                            <div class="doc-image-viewer">
                                                <img src="<?= htmlspecialchars($file_url) ?>"
                                                    alt="<?= htmlspecialchars($doc['title']) ?>" id="docImage"
                                                    onclick="this.style.maxHeight = this.style.maxHeight === 'none' ? '840px' : 'none';">
                                            </div>

                                        <?php else: ?>
                                            <?php
                                            $fileIcon = 'fa-file';
                                            $iconColor = 'text-secondary';
                                            if (in_array($fmt, ['doc', 'docx'])) {
                                                $fileIcon = 'fa-file-word';
                                                $iconColor = 'text-primary';
                                            } elseif (in_array($fmt, ['xls', 'xlsx'])) {
                                                $fileIcon = 'fa-file-excel';
                                                $iconColor = 'text-success';
                                            } elseif (in_array($fmt, ['ppt', 'pptx'])) {
                                                $fileIcon = 'fa-file-powerpoint';
                                                $iconColor = 'text-danger';
                                            } elseif ($fmt === 'zip') {
                                                $fileIcon = 'fa-file-archive';
                                                $iconColor = 'text-warning';
                                            }
                                            ?>
                                            <div class="doc-preview-placeholder">
                                                <i class="fas <?= $fileIcon ?> fa-6x <?= $iconColor ?> mb-4"></i>
                                                <h5 class="mb-1"><?= htmlspecialchars($doc['title'] ?? '') ?></h5>
                                                <?php if ($doc['file_size']): ?>
                                                    <p class="text-muted mb-3"><?= formatFileSize((int)$doc['file_size']) ?></p>
                                                <?php endif; ?>
                                                <p class="text-muted mb-4"><?= __('doc_preview_not_available') ?></p>
                                                <a href="<?= htmlspecialchars($file_url_download) ?>"
                                                    class="btn btn-primary btn-lg">
                                                    <i class="fas fa-download <?= isRtl() ? 'ml-2' : 'mr-2' ?>"></i>
                                                    <?= __('doc_download') ?>
                                                </a>
                                                <?php if (in_array($fmt, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])): ?>
                                                    <p class="text-muted mt-3 small"><?= __('doc_open_with') ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <div class="alert alert-danger text-center m-3 mb-0">
                                            <i class="fas fa-exclamation-triangle fa-2x d-block mb-2"></i>
                                            <?= __('doc_file_missing') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ط§ظ„ط¹ظ…ظˆط¯ ط§ظ„ط£ظٹط³ط±: ط§ظ„ظ…ط¹ظ„ظˆظ…ط§طھ + ط§ظ„طھظˆظ‚ظٹط¹ط§طھ + ط§ظ„ط¥ط¬ط±ط§ط،ط§طھ -->
                        <div class="col-md-4">
                            <!-- ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ظˆط«ظٹظ‚ط© -->
                            <div class="card card-outline card-primary shadow">
                                <div class="card-header">
                                    <h3 class="card-title" style="float:<?= isRtl() ? 'right' : 'left' ?>;">
                                        <i class="fas fa-info-circle ml-1"></i> <?= __('doc_title_field') ?>
                                    </h3>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered table-striped info-table mb-0">
                                        <tr>
                                            <td><?= __('doc_number') ?></td>
                                            <td><strong><?= htmlspecialchars($doc['doc_number'] ?? '-') ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_title_field') ?></td>
                                            <td><?= htmlspecialchars($doc['title'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_type') ?></td>
                                            <td><?= htmlspecialchars($doc['type_name'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_category') ?></td>
                                            <td><?= htmlspecialchars($doc['category_name'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_department') ?></td>
                                            <td><?= htmlspecialchars($doc['department'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_status') ?></td>
                                            <td><?= statusBadge($doc['status'] ?? 'draft') ?></td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_size') ?></td>
                                            <td><?= $doc['file_size'] !== null ? formatFileSize((int)$doc['file_size']) : '-' ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_created_at') ?></td>
                                            <td><?= $doc['created_at'] ? date('Y-m-d H:i', strtotime($doc['created_at'])) : '-' ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?= __('doc_created_by') ?></td>
                                            <td><?= htmlspecialchars($doc['created_by'] ?? '-') ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                            <!-- ط§ظ„طھظˆظ‚ظٹط¹ط§طھ -->
                            <div class="card card-outline card-warning shadow">
                                <div class="card-header">
                                    <h3 class="card-title" style="float:<?= isRtl() ? 'right' : 'left' ?>;">
                                        <i class="fas fa-pen ml-1"></i> <?= __('sig_title') ?>
                                    </h3>
                                    <span class="badge badge-info" style="float:<?= isRtl() ? 'left' : 'right' ?>;">
                                        <?= $total_signatures ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center mb-3">
                                        <div class="col-4 summary-stat">
                                            <div class="num"><?= $total_signatures ?></div>
                                            <div class="lbl"><?= __('sig_total') ?></div>
                                        </div>
                                        <div class="col-4 summary-stat">
                                            <div class="num text-warning"><?= $pending_signatures ?></div>
                                            <div class="lbl"><?= __('sig_pending') ?></div>
                                        </div>
                                        <div class="col-4 summary-stat">
                                            <div class="num text-success"><?= $signed_signatures ?></div>
                                            <div class="lbl"><?= __('sig_signed') ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($signatures)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0">
                                                <thead>
                                                    <tr>
                                                        <th><?= __('sig_employee') ?></th>
                                                        <th><?= __('doc_status') ?></th>
                                                        <th><?= __('sig_date') ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($signatures as $sig): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?= htmlspecialchars($sig['employee_name']) ?></strong>
                                                                <br><small
                                                                    class="text-muted"><?= htmlspecialchars($sig['job_title'] ?? '') ?></small>
                                                            </td>
                                                            <td><?= sigStatusBadge($sig['status']) ?></td>
                                                            <td style="font-size:12px;">
                                                                <?= $sig['signed_at'] ? date('Y-m-d H:i', strtotime($sig['signed_at'])) : '-' ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted text-center mb-0"><?= __('doc_no_sig') ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- مراحل الاعتماد التسلسلية -->
                            <?php if (!empty($workflow_approvals)): ?>
                                <div class="card card-outline card-secondary shadow">
                                    <div class="card-header">
                                        <h3 class="card-title" style="float:<?= isRtl() ? 'right' : 'left' ?>;">
                                            <i class="fas fa-check-double ml-1"></i> <?= __('approval_stages') ?>
                                        </h3>
                                    </div>
                                    <div class="card-body p-2">
                                        <?php foreach ($workflow_approvals as $i => $appr):
                                            $apprCls = $appr['approval_status'] === 'approved' ? 'success' : 'warning';
                                        ?>
                                            <div class="d-flex align-items-start mb-1">
                                                <span class="badge badge-<?= $apprCls ?> mt-1 flex-shrink-0"
                                                    style="width:24px;height:24px;line-height:16px;border-radius:50%;font-size:.75rem;">
                                                    <?= (int)$appr['stage_order'] ?>
                                                </span>
                                                <div class="flex-grow-1 <?= isRtl() ? 'mr-2' : 'ml-2' ?>">
                                                    <strong
                                                        style="font-size:.85rem;"><?= htmlspecialchars($appr['employee_name']) ?></strong>
                                                    <?php if ($appr['stage_name']): ?>
                                                        <small class="text-muted"> ·
                                                            <?= htmlspecialchars($appr['stage_name']) ?></small>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= htmlspecialchars($appr['department'] ?? '') ?>
                                                        <?php if ($appr['job_title']): ?> —
                                                            <?= htmlspecialchars($appr['job_title']) ?><?php endif; ?>
                                                    </small>
                                                    <?php if ($appr['approval_status'] === 'approved' && $appr['approval_signed_at']): ?>
                                                        <br><small class="text-success"><i class="fas fa-check-circle"></i>
                                                            <?= date('Y-m-d H:i', strtotime($appr['approval_signed_at'])) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="badge badge-<?= $apprCls ?> ml-1 flex-shrink-0"
                                                    style="font-size:.7rem;">
                                                    <?= $appr['approval_status'] === 'approved' ? __('sig_signed') : __('sig_pending') ?>
                                                </span>
                                            </div>
                                            <?php if ($i < count($workflow_approvals) - 1): ?>
                                                <div class="text-center mb-1" style="color:#ccc;font-size:.7rem;"><i
                                                        class="fas fa-chevron-down"></i></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- الإجراءات -->
                            <div class="card card-outline card-success shadow">
                                <div class="card-header">
                                    <h3 class="card-title" style="float:<?= isRtl() ? 'right' : 'left' ?>;">
                                        <i class="fas fa-tools ml-1"></i> <?= __('actions') ?>
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <?php if ($current_employee_id && $can_sign_now && $file_exists): ?>
                                        <button type="button" class="btn btn-success btn-block mb-2" data-toggle="modal"
                                            data-target="#signatureModal" id="btnMySign">
                                            <i class="fas fa-pen-fancy ml-1"></i> <?= __('sig_doc') ?>
                                        </button>
                                    <?php elseif ($current_employee_id && !$has_signed && $doc_workflow_id > 0 && !$can_sign_now): ?>
                                        <div class="alert alert-info p-2 mb-2 small">
                                            <i class="fas fa-clock ml-1"></i> <?= __('sig_not_your_turn') ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($can_edit): ?>
                                        <button type="button" class="btn btn-info btn-block mb-2" data-toggle="modal"
                                            data-target="#addSigModal">
                                            <i class="fas fa-user-plus ml-1"></i> <?= __('sig_add') ?>
                                        </button>
                                        <a href="../forms/add-document.php?edit_id=<?= $doc_id ?>"
                                            class="btn btn-warning btn-block mb-2">
                                            <i class="fas fa-edit ml-1"></i> <?= __('edit') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                        <button type="button" class="btn btn-danger btn-block btn-delete-doc"
                                            data-id="<?= $doc_id ?>">
                                            <i class="fas fa-trash ml-1"></i> <?= __('doc_delete') ?>
                                        </button>
                                    <?php endif; ?>
                                    <a href="../tables/show-documents.php" class="btn btn-secondary btn-block">
                                        <i class="fas fa-arrow-<?= isRtl() ? 'right' : 'left' ?> ml-1"></i>
                                        <?= __('back') ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Modal: طھظˆظ‚ظٹط¹ ط§ظ„ظ…ط³طھط®ط¯ظ… ط§ظ„ط­ط§ظ„ظٹ -->
        <div class="modal fade" id="signatureModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <form id="sigForm" method="POST" action="process-signature.php">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-pen-fancy ml-1"></i> <?= __('sig_doc') ?></h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="document_id" value="<?= $doc_id ?>">
                            <input type="hidden" name="employee_id" id="sig_employee_id"
                                value="<?= $current_employee_id ?>">
                            <input type="hidden" name="pos_x" id="sig_pos_x" value="">
                            <input type="hidden" name="pos_y" id="sig_pos_y" value="">
                            <input type="hidden" name="page_number" id="sig_page_number" value="1">

                            <div class="form-group">
                                <label><?= __('sig_employee') ?>:</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($empRow['full_name'] ?? $_SESSION['full_name'] ?? '') ?>"
                                    readonly disabled>
                            </div>

                            <div class="form-group mb-0">
                                <label><?= __('sig_place') ?>:</label>
                                <p class="text-muted"><?= __('sig_click_place') ?></p>
                                <div id="sigZoneContainer">
                                    <?php if ($file_exists): ?>
                                        <div class="signature-zone" id="signatureZone"
                                            style="background-image: url('<?= htmlspecialchars($file_url) ?>'); background-size: 100% auto; <?= $is_image ? 'background-size:contain;background-repeat:no-repeat;' : '' ?> min-height: 500px;">
                                            <div class="sig-tooltip" id="sigTooltip">x: 0, y: 0</div>
                                            <div class="sig-preview-follow" id="sigPreviewFollow">
                                                <?php if ($current_employee_sig): ?>
                                                    <img src="../../uploads/<?= htmlspecialchars(ltrim($current_employee_sig, '/')) ?>"
                                                        alt="<?= __('sig_title') ?>">
                                                <?php else: ?>
                                                    <span
                                                        style="background:#fff;border:1px solid #999;padding:4px 8px;border-radius:4px;font-size:14px;"><?= __('sig_title') ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-danger mb-0"><?= __('file_not_found_sig') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                data-dismiss="modal"><?= __('cancel') ?></button>
                            <button type="submit" name="process_signature" class="btn btn-success" id="confirmSigBtn"
                                disabled>
                                <i class="fas fa-check ml-1"></i> <?= __('sig_confirm') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal: ط¥ط¶ط§ظپط© طھظˆظ‚ظٹط¹ ظ„ظ…ظˆط¸ظپ ط¢ط®ط± -->
        <div class="modal fade" id="addSigModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <form id="addSigForm" method="POST" action="process-signature.php">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-user-plus ml-1"></i> <?= __('sig_add') ?></h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="document_id" value="<?= $doc_id ?>">
                            <input type="hidden" name="employee_id" id="add_sig_employee_id" value="">
                            <input type="hidden" name="pos_x" id="add_sig_pos_x" value="">
                            <input type="hidden" name="pos_y" id="add_sig_pos_y" value="">
                            <input type="hidden" name="page_number" id="add_sig_page_number" value="1">

                            <div class="form-group">
                                <label><?= __('sig_choose_emp') ?> <span class="text-danger">*</span></label>
                                <select class="form-control" id="addSigEmployeeSelect" required>
                                    <option value="">-- <?= __('sig_choose_emp') ?> --</option>
                                    <?php foreach ($signers as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"
                                            data-sig="<?= htmlspecialchars($emp['signature_image'] ?? '') ?>">
                                            <?= htmlspecialchars($emp['full_name']) ?>
                                            (<?= htmlspecialchars($emp['job_title'] ?? '') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group mb-0">
                                <label><?= __('sig_place') ?>:</label>
                                <p class="text-muted"><?= __('sig_click_place') ?></p>
                                <div id="addSigZoneContainer">
                                    <?php if ($file_exists): ?>
                                        <div class="signature-zone" id="addSignatureZone"
                                            style="background-image: url('<?= htmlspecialchars($file_url) ?>'); background-size: 100% auto; <?= $is_image ? 'background-size:contain;background-repeat:no-repeat;' : '' ?> min-height: 500px;">
                                            <div class="sig-tooltip" id="addSigTooltip">x: 0, y: 0</div>
                                            <div class="sig-preview-follow" id="addSigPreviewFollow">
                                                <span
                                                    style="background:#fff;border:1px solid #999;padding:4px 8px;border-radius:4px;font-size:14px;"><?= __('sig_title') ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-danger mb-0"><?= __('file_not_found_sig') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                data-dismiss="modal"><?= __('cancel') ?></button>
                            <button type="submit" name="process_signature" class="btn btn-success" id="addConfirmSigBtn"
                                disabled>
                                <i class="fas fa-check ml-1"></i> <?= __('sig_confirm') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <footer class="main-footer">
            <?php include(__DIR__ . '/../../main-footer.php') ?>
        </footer>
    </div>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.all.min.js"></script>

    <script>
        $(function() {
            var currentLang = "<?= getLang() ?>";
            var errorTitle = "<?= __('error') ?>";
            var okText = "<?= __('ok') ?>";
            var sigConfirmDelete = "<?= __('confirm_delete_doc') ?>";
            var yesDelete = "<?= __('yes_delete') ?>";
            var cancelBtn = "<?= __('cancel_btn') ?>";

            function setupSignaturePlacement(zoneId, tooltipId, previewId, posXId, posYId, confirmBtnId, sigId,
                empSelectId) {
                var $zone = $('#' + zoneId);
                var $tooltip = $('#' + tooltipId);
                var $preview = $('#' + previewId);
                var $sigImage = null;
                var placed = false;
                var $marker = null;

                if (!empSelectId) {
                    $sigImage = $preview.find('img').attr('src');
                }

                function getSigSrc() {
                    if ($sigImage) return $sigImage;
                    if (empSelectId) {
                        var $sel = $('#' + empSelectId);
                        var img = $sel.find(':selected').data('sig');
                        return img || '';
                    }
                    return '';
                }

                function updatePreviewImg() {
                    var src = getSigSrc();
                    if (src) {
                        $preview.html('<img src="../../uploads/' + src.replace(/^\/+/, '') +
                            '" alt="<?= __('sig_title') ?>" style="max-width:150px;max-height:50px;">');
                    } else {
                        $preview.html(
                            '<span style="background:#fff;border:1px solid #999;padding:4px 8px;border-radius:4px;font-size:14px;"><?= __('sig_title') ?></span>'
                        );
                    }
                    $sigImage = src || null;
                }

                if (empSelectId) {
                    $('#' + empSelectId).on('change', function() {
                        updatePreviewImg();
                        if (placed) {
                            placed = false;
                            $('#' + confirmBtnId).prop('disabled', true);
                            if ($marker) {
                                $marker.remove();
                                $marker = null;
                            }
                        }
                    });
                }

                $zone.on('mousemove', function(e) {
                    var offset = $zone.offset();
                    var x = Math.round((e.pageX - offset.left) * ($zone[0].scrollWidth ? $zone.width() /
                        $zone[0].scrollWidth : 1));
                    var y = Math.round((e.pageY - offset.top) * ($zone[0].scrollHeight ? $zone.height() /
                        $zone[0].scrollHeight : 1));
                    $tooltip.css({
                            display: 'block',
                            left: (e.pageX - offset.left + 10) + 'px',
                            top: (e.pageY - offset.top - 10) + 'px'
                        })
                        .text('x: ' + x + ', y: ' + y);
                    $preview.css({
                        display: 'block',
                        left: (e.pageX - offset.left - 75) + 'px',
                        top: (e.pageY - offset.top - 35) + 'px'
                    });
                });

                $zone.on('mouseleave', function() {
                    $tooltip.hide();
                    $preview.hide();
                });

                $zone.on('click', function(e) {
                    var offset = $zone.offset();
                    var x = Math.round((e.pageX - offset.left) * ($zone[0].scrollWidth ? $zone.width() /
                        $zone[0].scrollWidth : 1));
                    var y = Math.round((e.pageY - offset.top) * ($zone[0].scrollHeight ? $zone.height() /
                        $zone[0].scrollHeight : 1));

                    $('#' + posXId).val(x);
                    $('#' + posYId).val(y);

                    if ($marker) $marker.remove();

                    var src = getSigSrc();
                    var markerHtml = '';
                    if (src) {
                        markerHtml = '<img src="../../uploads/' + src.replace(/^\/+/, '') +
                            '" alt="<?= __('sig_title') ?>">';
                    } else {
                        markerHtml =
                            '<span style="color:#e74c3c;font-weight:bold;"><?= __('sig_title') ?></span>';
                    }
                    markerHtml += '<div class="badge-coord">x:' + x + ' y:' + y + '</div>';

                    $marker = $('<div class="signature-marker">' + markerHtml + '</div>')
                        .css({
                            left: x + 'px',
                            top: y + 'px'
                        });
                    $zone.append($marker);

                    placed = true;
                    $('#' + confirmBtnId).prop('disabled', false);
                    $tooltip.hide();
                    $preview.hide();
                });
            }

            // التوقيع الشخصي
            setupSignaturePlacement(
                'signatureZone', 'sigTooltip', 'sigPreviewFollow',
                'sig_pos_x', 'sig_pos_y', 'confirmSigBtn',
                null, null
            );

            // إضافة توقيع لموظف آخر
            setupSignaturePlacement(
                'addSignatureZone', 'addSigTooltip', 'addSigPreviewFollow',
                'add_sig_pos_x', 'add_sig_pos_y', 'addConfirmSigBtn',
                null, 'addSigEmployeeSelect'
            );

            // عند فتح مودال إضافة توقيع – حدّث الصورة
            $('#addSigModal').on('shown.bs.modal', function() {
                $('#addSigEmployeeSelect').trigger('change');
            });

            // ── مساعد إرسال AJAX مشترك ──
            function submitSignatureForm($form, $btn) {
                $btn.prop('disabled', true)
                    .html('<i class=”fas fa-spinner fa-spin <?= isRtl() ? 'ml-1' : 'mr-1' ?>”></i>');

                $.ajax({
                    url: 'process-signature.php',
                    method: 'POST',
                    data: $form.serialize() + '&process_signature=1',
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '<?= __('success') ?>',
                                text: resp.message,
                                timer: 1800,
                                showConfirmButton: false
                            }).then(function() {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: errorTitle,
                                text: resp.message,
                                confirmButtonText: okText
                            });
                            $btn.prop('disabled', false)
                                .html(<?= json_encode('<i class=”fas fa-check ' . (isRtl() ? 'ml-1' : 'mr-1') . '”></i> ' . __('sig_confirm')) ?>);
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: errorTitle,
                            text: <?= json_encode(__('network_error')) ?>,
                            confirmButtonText: okText
                        });
                        $btn.prop('disabled', false)
                            .html(<?= json_encode('<i class=”fas fa-check ' . (isRtl() ? 'ml-1' : 'mr-1') . '”></i> ' . __('sig_confirm')) ?>);
                    }
                });
            }

            // توقيع المستخدم الحالي (AJAX)
            $('#sigForm').on('submit', function(e) {
                e.preventDefault();
                submitSignatureForm($(this), $('#confirmSigBtn'));
            });

            // إضافة توقيع لموظف آخر (AJAX)
            $('#addSigForm').on('submit', function(e) {
                e.preventDefault();
                var empId = $('#addSigEmployeeSelect').val();
                if (!empId) {
                    Swal.fire({
                        icon: 'error',
                        title: errorTitle,
                        text: “<?= __('choose_employee') ?>”,
                        confirmButtonText: okText
                    });
                    return;
                }
                $('#add_sig_employee_id').val(empId);
                submitSignatureForm($(this), $('#addConfirmSigBtn'));
            });

            // حذف الوثيقة
            $('.btn-delete-doc').on('click', function() {
                var docId = $(this).data('id');
                Swal.fire({
                    title: '<?= __('confirm_delete') ?>',
                    text: sigConfirmDelete,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonText: cancelBtn,
                    confirmButtonText: yesDelete
                }).then(function(result) {
                    if (result.isConfirmed) {
                        window.location.href = '../tables/show-documents.php?delete_id=' + docId;
                    }
                });
            });

            // رسالة النجاح من sessionStorage
            var showSuccess = sessionStorage.getItem('showSuccess');
            if (showSuccess) {
                Swal.fire({
                    icon: 'success',
                    title: '<?= __('success') ?>',
                    text: showSuccess,
                    confirmButtonText: okText
                });
                sessionStorage.removeItem('showSuccess');
            }
        });
    </script>
</body>

</html>