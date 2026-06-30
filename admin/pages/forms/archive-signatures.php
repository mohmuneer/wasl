<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/archive-signatures.php";

if (!$current_user_id) {
    die(__('login_required'));
}

$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($doc_id <= 0) {
    header("Location: ../tables/archive-list.php");
    exit;
}

// ── صلاحيات الصفحة ──
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$can_view = 0;
$can_add = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_view, can_add FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_view = $permissions['can_view'] ?? 0;
    $can_add  = $permissions['can_add'] ?? 0;
}

if (!$can_view) {
    $_SESSION['warning_message'] = __('no_access_page');
    header("Location: ../../index.php");
    exit;
}

// ── تحميل الوثيقة ──
$docSql = "SELECT d.*, t.name AS type_name, c.name AS category_name, u.full_name AS creator_name
           FROM " . TBL_DOCUMENTS . " d
           LEFT JOIN " . TBL_DOC_TYPES . " t ON d.type_id = t.id
           LEFT JOIN " . TBL_DOC_CATEGORIES . " c ON d.category_id = c.id
           LEFT JOIN sys_users u ON d.created_by = u.id
           WHERE d.id = ?";
$docStmt = $pdo->prepare($docSql);
$docStmt->execute([$doc_id]);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die(__('doc_not_found'));
}

// ── التوقيعات الحالية للوثيقة ──
$sigSql = "SELECT s.*, e.full_name AS employee_name, e.job_title, e.signature_image AS emp_signature
           FROM " . TBL_SIGNATURES . " s
           JOIN " . TBL_EMPLOYEES . " e ON s.employee_id = e.id
           WHERE s.document_id = ?
           ORDER BY s.signed_at ASC";
$sigStmt = $pdo->prepare($sigSql);
$sigStmt->execute([$doc_id]);
$signatures = $sigStmt->fetchAll(PDO::FETCH_ASSOC);

// ── الوظائف حسب الهيكل الإداري ──
$posStmt = $pdo->query("
    SELECT jp.*, d.department_name
    FROM " . TBL_JOB_POSITIONS . " jp
    LEFT JOIN departments d ON jp.department_id = d.id
    WHERE jp.is_active = 1
    ORDER BY d.department_name, jp.job_title
");
$positions = $posStmt->fetchAll(PDO::FETCH_ASSOC);

// ── لكل وظيفة: الموظفون المتاحون ──
$positionEmployees = [];
foreach ($positions as $pos) {
    $eStmt = $pdo->prepare("
        SELECT id, full_name, job_title, department_id, signature_image
        FROM " . TBL_EMPLOYEES . "
        WHERE job_title = ? AND can_sign = 1 AND is_active = 1
        ORDER BY full_name
    ");
    $eStmt->execute([$pos['job_title']]);
    $positionEmployees[$pos['id']] = $eStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── معالجة POST لإضافة التوقيع ──
$response = ['success' => false, 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_POST['action'] === 'sign_position' && $can_add) {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $position_id = (int)($_POST['position_id'] ?? 0);

        if (!$employee_id || !$position_id) {
            $response = ['success' => false, 'message' => __('sig_invalid_data')];
        } else {
            try {
                $empStmt = $pdo->prepare("SELECT * FROM " . TBL_EMPLOYEES . " WHERE id = ? AND can_sign = 1 AND is_active = 1");
                $empStmt->execute([$employee_id]);
                $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

                if (!$emp) {
                    $response = ['success' => false, 'message' => __('emp_not_found')];
                } elseif (empty($emp['signature_image'])) {
                    $response = ['success' => false, 'message' => __('sig_no_image')];
                } else {
                    $chkStmt = $pdo->prepare("SELECT id FROM " . TBL_SIGNATURES . " WHERE document_id = ? AND employee_id = ? AND status = 'signed'");
                    $chkStmt->execute([$doc_id, $employee_id]);
                    $existingSig = $chkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingSig) {
                        $response = ['success' => false, 'message' => __('archive_sig_already_signed')];
                    } else {
                        $insStmt = $pdo->prepare("INSERT INTO " . TBL_SIGNATURES . "
                            (document_id, employee_id, signature_image, sign_type, status, signed_at)
                            VALUES (?, ?, ?, 'manual', 'signed', NOW())");
                        $insStmt->execute([$doc_id, $employee_id, $emp['signature_image']]);

                        log_action($pdo, 'sign', 'وثيقة', $doc_id, [], [
                            'signed_by'  => $employee_id,
                            'position'   => $position_id,
                            'page'       => 'archive-signatures',
                        ]);

                        $response = [
                            'success' => true,
                            'message' => __('sig_success'),
                            'data' => [
                                'employee_name' => $emp['full_name'],
                                'job_title'     => $emp['job_title'],
                                'signature_image' => $emp['signature_image'],
                                'signed_at'     => date('Y-m-d H:i'),
                            ],
                        ];
                    }
                }
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => __('sig_error') . ': ' . $e->getMessage()];
            }
        }
    } elseif ($_POST['action'] === 'revoke_signature' && $can_add) {
        $sig_id = (int)($_POST['signature_id'] ?? 0);
        $doc_id_revoke = (int)($_POST['document_id'] ?? 0);

        if ($sig_id && $doc_id_revoke) {
            try {
                $delStmt = $pdo->prepare("DELETE FROM " . TBL_SIGNATURES . " WHERE id = ? AND document_id = ?");
                $delStmt->execute([$sig_id, $doc_id_revoke]);

                if ($delStmt->rowCount() > 0) {
                    log_action($pdo, 'delete', 'توقيع', $sig_id, [], ['document_id' => $doc_id_revoke, 'page' => 'archive-signatures']);
                    $response = ['success' => true, 'message' => __('archive_sig_revoked')];
                } else {
                    $response = ['success' => false, 'message' => __('sig_not_found')];
                }
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => __('sig_error') . ': ' . $e->getMessage()];
            }
        } else {
            $response = ['success' => false, 'message' => __('sig_invalid_data')];
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── دوال مساعدة ──
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

$is_pdf   = strtolower($doc['file_format'] ?? '') === 'pdf';
$is_image = in_array(strtolower($doc['file_format'] ?? ''), ['jpg', 'jpeg', 'png', 'gif']);
$file_url = $doc['file_path'] ? 'serve-file.php?path=' . urlencode($doc['file_path']) : '';
$file_path_abs = __DIR__ . '/../../../' . $doc['file_path'];
$file_exists   = file_exists($file_path_abs);
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= __('archive_sig_title') ?> - <?= htmlspecialchars($doc['title'] ?? '') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.min.css">
    <style>
        body {
            direction: <?= isRtl() ? 'rtl' : 'ltr' ?>;
            text-align: <?= isRtl() ? 'right' : 'left' ?>;
        }

        .sig-block {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: box-shadow 0.2s;
            width: 210px;
            min-height: 220px;
            display: flex;
            flex-direction: column;
        }

        .sig-block:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .sig-block .sig-header {
            background: #f8f9fa;
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            font-weight: 700;
            font-size: 14px;
            text-align: center;
            border-radius: 8px 8px 0 0;
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sig-block .sig-body {
            padding: 14px 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .sig-block .sig-body .emp-name {
            font-weight: 600;
            font-size: 13px;
            color: #333;
            margin-bottom: 6px;
        }

        .sig-block .sig-body .emp-position {
            font-size: 11px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .sig-block .sig-body .emp-sig-img {
            max-width: 140px;
            max-height: 40px;
            margin-bottom: 8px;
            object-fit: contain;
        }

        .sig-block .sig-body .sig-date {
            font-size: 11px;
            color: #999;
        }

        .sig-block .sig-body .sig-status {
            margin-bottom: 8px;
        }

        .sig-block .sig-body select,
        .sig-block .sig-body .btn-sign {
            width: 100%;
        }

        .sig-block .sig-body select {
            margin-bottom: 8px;
        }

        .sig-block .sig-footer {
            padding: 8px 12px;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
            text-align: center;
            border-radius: 0 0 8px 8px;
        }

        .sig-block .sig-footer .btn-sign {
            font-size: 12px;
            padding: 4px 12px;
        }

        .sig-block .sig-body .sig-placeholder {
            color: #adb5bd;
            font-size: 13px;
        }

        .sig-container {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: center;
            padding: 10px 0;
        }

        .doc-info-bar {
            background: #fff;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            border-right: 4px solid #17a2b8;
        }

        <?php if (isRtl()): ?>.doc-info-bar {
            border-right: none;
            border-left: 4px solid #17a2b8;
        }

        <?php endif;

        ?>.doc-info-bar .info-item {
            display: inline-block;
            margin-<?= isRtl() ? 'left' : 'right' ?>: 28px;
            font-size: 13px;
        }

        .doc-info-bar .info-item .label {
            color: #6c757d;
        }

        .doc-info-bar .info-item .value {
            font-weight: 600;
            color: #333;
        }

        .section-title {
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            color: #495057;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #17a2b8;
        }

        .section-title i {
            margin-<?= isRtl() ? 'left' : 'right' ?>: 8px;
        }

        .no-positions-msg {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .no-positions-msg i {
            font-size: 48px;
            display: block;
            margin-bottom: 16px;
            color: #adb5bd;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.show {
            display: flex;
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
                            <h1><i class="fas fa-signature ml-2"></i> <?= __('archive_sig_title') ?>
                                <?= langSwitcher() ?></h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-<?= isRtl() ? 'right' : 'left' ?>">
                                <li class="breadcrumb-item"><a href="../../index.php"><?= __('home') ?></a></li>
                                <li class="breadcrumb-item"><a
                                        href="../tables/archive-list.php"><?= __('archive_title') ?></a></li>
                                <li class="breadcrumb-item active"><?= __('archive_sig_title') ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <!-- شريط معلومات الوثيقة -->
                    <div class="doc-info-bar">
                        <div class="info-item">
                            <span class="label"><?= __('doc_number') ?>:</span>
                            <span class="value"><?= htmlspecialchars($doc['doc_number'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label"><?= __('doc_title_field') ?>:</span>
                            <span class="value"><?= htmlspecialchars($doc['title'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label"><?= __('doc_department') ?>:</span>
                            <span class="value"><?= htmlspecialchars($doc['department'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label"><?= __('doc_type') ?>:</span>
                            <span class="value"><?= htmlspecialchars($doc['type_name'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label"><?= __('doc_status') ?>:</span>
                            <span class="value"><?= statusBadge($doc['status'] ?? 'draft') ?></span>
                        </div>
                        <div class="float-sm-<?= isRtl() ? 'left' : 'right' ?>">
                            <a href="view-document.php?id=<?= $doc_id ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-eye"></i> <?= __('doc_view') ?>
                            </a>
                            <a href="../tables/archive-list.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-<?= isRtl() ? 'right' : 'left' ?>"></i> <?= __('back') ?>
                            </a>
                        </div>
                    </div>

                    <!-- التوقيعات الأفقية حسب الهيكل الإداري -->
                    <div class="card card-outline card-info shadow">
                        <div class="card-header">
                            <h3 class="card-title" style="float:<?= isRtl() ? 'right' : 'left' ?>;">
                                <i class="fas fa-sitemap ml-1"></i> <?= __('archive_sig_org') ?>
                            </h3>
                            <span class="badge badge-info" style="float:<?= isRtl() ? 'left' : 'right' ?>;">
                                <?= count($positions) . ' ' . __('archive_sig_positions') ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($positions)): ?>
                                <div class="no-positions-msg">
                                    <i class="fas fa-draw-polygon"></i>
                                    <p><?= __('archive_sig_no_positions') ?></p>
                                    <a href="add-jobs.php" class="btn btn-info btn-sm">
                                        <i class="fas fa-plus"></i> <?= __('jobs_add_job') ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="sig-container" id="sigContainer">
                                    <?php foreach ($positions as $pos):
                                        $posId   = $pos['id'];
                                        $posTitle = htmlspecialchars($pos['job_title']);
                                        $deptName = htmlspecialchars($pos['department_name'] ?? '');

                                        // البحث عن توقيعات لهذه الوظيفة (نفس job_title)
                                        $posSigs = array_filter($signatures, function ($s) use ($pos) {
                                            return $s['job_title'] === $pos['job_title'];
                                        });
                                        $signedSig = !empty($posSigs) ? reset($posSigs) : null;

                                        // الموظفين المتاحين لهذه الوظيفة
                                        $availableEmps = $positionEmployees[$posId] ?? [];
                                    ?>
                                        <div class="sig-block" id="sigBlock_<?= $posId ?>" data-pos-id="<?= $posId ?>">
                                            <div class="sig-header">
                                                <?php if ($deptName): ?>
                                                    <span title="<?= $deptName ?>"><?= $posTitle ?></span>
                                                <?php else: ?>
                                                    <?= $posTitle ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sig-body" id="sigBody_<?= $posId ?>">
                                                <?php if ($signedSig): ?>
                                                    <!-- موقّع -->
                                                    <div class="sig-status"><?= sigStatusBadge('signed') ?></div>
                                                    <div class="emp-name"><?= htmlspecialchars($signedSig['employee_name']) ?></div>
                                                    <?php if ($signedSig['emp_signature']): ?>
                                                        <img src="../../../<?= ltrim($signedSig['emp_signature'], '/') ?>"
                                                            alt="<?= __('sig_title') ?>" class="emp-sig-img"
                                                            onerror="this.style.display='none'">
                                                    <?php endif; ?>
                                                    <div class="sig-date">
                                                        <i class="far fa-calendar-alt ml-1"></i>
                                                        <?= $signedSig['signed_at'] ? date('Y-m-d H:i', strtotime($signedSig['signed_at'])) : '-' ?>
                                                    </div>
                                                <?php elseif (!empty($availableEmps)): ?>
                                                    <!-- غير موقّع: اختيار موظف -->
                                                    <div class="sig-status"><?= sigStatusBadge('pending') ?></div>
                                                    <select class="form-control form-control-sm emp-select"
                                                        id="empSelect_<?= $posId ?>">
                                                        <option value="">-- <?= __('archive_sig_select_emp') ?> --</option>
                                                        <?php foreach ($availableEmps as $emp):
                                                            $hasSigImage = !empty($emp['signature_image']);
                                                        ?>
                                                            <option value="<?= $emp['id'] ?>"
                                                                data-has-sig="<?= $hasSigImage ? '1' : '0' ?>"
                                                                data-sig="<?= htmlspecialchars($emp['signature_image'] ?? '') ?>">
                                                                <?= htmlspecialchars($emp['full_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="button" class="btn btn-info btn-sm btn-sign"
                                                        id="btnSign_<?= $posId ?>" data-pos-id="<?= $posId ?>" disabled>
                                                        <i class="fas fa-pen"></i> <?= __('sig_doc') ?>
                                                    </button>

                                                <?php else: ?>
                                                    <!-- لا يوجد موظفون لهذه الوظيفة -->
                                                    <div class="sig-placeholder">
                                                        <i class="fas fa-user-slash d-block mb-2" style="font-size:24px;"></i>
                                                        <span><?= __('archive_sig_no_employees') ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($signedSig && $can_add): ?>
                                                <div class="sig-footer">
                                                    <button type="button" class="btn btn-outline-info btn-sm btn-revoke"
                                                        data-pos-id="<?= $posId ?>" data-sig-id="<?= $signedSig['id'] ?>">
                                                        <i class="fas fa-undo"></i> <?= __('archive_sig_revoke') ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- إحصائيات التوقيعات -->
                    <?php
                    $totalPos = count($positions);
                    $signedPos = 0;
                    foreach ($positions as $pos) {
                        $matched = array_filter($signatures, function ($s) use ($pos) {
                            return $s['job_title'] === $pos['job_title'] && $s['status'] === 'signed';
                        });
                        if (!empty($matched)) $signedPos++;
                    }
                    $pendingPos = $totalPos - $signedPos;
                    ?>
                    <div class="row text-center mb-3">
                        <div class="col-md-4 col-sm-4">
                            <div class="small-box bg-light border rounded p-3">
                                <div class="inner">
                                    <h3><?= $totalPos ?></h3>
                                    <p><?= __('archive_sig_total_positions') ?></p>
                                </div>
                                <div class="icon"><i class="fas fa-sitemap"></i></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-4">
                            <div class="small-box bg-light border rounded p-3">
                                <div class="inner">
                                    <h3 class="text-success"><?= $signedPos ?></h3>
                                    <p><?= __('archive_sig_signed_positions') ?></p>
                                </div>
                                <div class="icon"><i class="fas fa-check-circle text-success"></i></div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-4">
                            <div class="small-box bg-light border rounded p-3">
                                <div class="inner">
                                    <h3 class="text-warning"><?= $pendingPos ?></h3>
                                    <p><?= __('archive_sig_pending_positions') ?></p>
                                </div>
                                <div class="icon"><i class="fas fa-clock text-warning"></i></div>
                            </div>
                        </div>
                    </div>

                </div>
            </section>
        </div>

        <footer class="main-footer">
            <?php include(__DIR__ . '/../../main-footer.php') ?>
        </footer>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="text-center">
            <div class="spinner-border text-info" style="width:3rem;height:3rem;" role="status">
                <span class="sr-only"><?= __('loading') ?></span>
            </div>
            <p class="mt-2 text-muted"><?= __('loading') ?></p>
        </div>
    </div>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.all.min.js"></script>

    <script>
        $(function() {
            var currentLang = "<?= getLang() ?>";
            var docId = <?= $doc_id ?>;
            var canAdd = <?= $can_add ? 1 : 0 ?>;
            var successTitle = "<?= __('success') ?>";
            var errorTitle = "<?= __('error') ?>";
            var okText = "<?= __('ok') ?>";
            var loadingText = "<?= __('loading') ?>";

            // تفعيل/تعطيل زر التوقيع عند اختيار موظف
            $('.emp-select').on('change', function() {
                var block = $(this).closest('.sig-block');
                var btn = block.find('.btn-sign');
                var selected = $(this).val();
                var hasSig = $(this).find(':selected').data('has-sig');

                if (selected && hasSig == 1) {
                    btn.prop('disabled', false);
                } else if (selected && hasSig == 0) {
                    btn.prop('disabled', true);
                    Swal.fire({
                        icon: 'warning',
                        title: '<?= __('warning') ?>',
                        text: '<?= __('sig_no_image') ?>',
                        confirmButtonText: okText
                    });
                } else {
                    btn.prop('disabled', true);
                }
            });

            // التوقيع
            $('.btn-sign').on('click', function() {
                if (!canAdd) {
                    Swal.fire({
                        icon: 'error',
                        title: errorTitle,
                        text: '<?= __('no_permission') ?>',
                        confirmButtonText: okText
                    });
                    return;
                }

                var btn = $(this);
                var posId = btn.data('pos-id');
                var block = $('#sigBlock_' + posId);
                var empSelect = $('#empSelect_' + posId);
                var empId = empSelect.val();

                if (!empId) {
                    Swal.fire({
                        icon: 'warning',
                        title: '<?= __('warning') ?>',
                        text: '<?= __('choose_employee') ?>',
                        confirmButtonText: okText
                    });
                    return;
                }

                // تحقق من صورة التوقيع
                var empOption = empSelect.find(':selected');
                if (empOption.data('has-sig') != 1) {
                    Swal.fire({
                        icon: 'warning',
                        title: '<?= __('warning') ?>',
                        text: '<?= __('sig_no_image') ?>',
                        confirmButtonText: okText
                    });
                    return;
                }

                $('#loadingOverlay').addClass('show');

                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'sign_position',
                        employee_id: empId,
                        position_id: posId,
                        document_id: docId
                    },
                    success: function(resp) {
                        $('#loadingOverlay').removeClass('show');
                        if (resp.success) {
                            // تحديث البطاقة
                            var newBody =
                                '<div class="sig-status"><?= sigStatusBadge('signed') ?></div>' +
                                '<div class="emp-name">' + (resp.data.employee_name || '') +
                                '</div>' +
                                (resp.data.signature_image ?
                                    '<img src="../../../' + resp.data.signature_image.replace(
                                        /^\/+/, '') +
                                    '" alt="<?= __('sig_title') ?>" class="emp-sig-img" onerror="this.style.display=\'none\'">' :
                                    '') +
                                '<div class="sig-date"><i class="far fa-calendar-alt ml-1"></i> ' +
                                (resp.data.signed_at || '') + '</div>';

                            $('#sigBody_' + posId).html(newBody);

                            // إضافة footer للإلغاء
                            if (canAdd) {
                                var footer =
                                    '<button type="button" class="btn btn-outline-info btn-sm btn-revoke" data-pos-id="' +
                                    posId +
                                    '" data-sig-id="0"><i class="fas fa-undo"></i> <?= __('archive_sig_revoke') ?></button>';
                                block.append('<div class="sig-footer">' + footer + '</div>');
                            }

                            Swal.fire({
                                icon: 'success',
                                title: successTitle,
                                text: resp.message,
                                confirmButtonText: okText
                            });
                            location.reload();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: errorTitle,
                                text: resp.message,
                                confirmButtonText: okText
                            });
                        }
                    },
                    error: function() {
                        $('#loadingOverlay').removeClass('show');
                        Swal.fire({
                            icon: 'error',
                            title: errorTitle,
                            text: '<?= __('sig_error') ?>',
                            confirmButtonText: okText
                        });
                    }
                });
            });

            // إلغاء التوقيع
            $(document).on('click', '.btn-revoke', function() {
                if (!canAdd) {
                    Swal.fire({
                        icon: 'error',
                        title: errorTitle,
                        text: '<?= __('no_permission') ?>',
                        confirmButtonText: okText
                    });
                    return;
                }

                var posId = $(this).data('pos-id');
                var sigId = $(this).data('sig-id');

                Swal.fire({
                    title: '<?= __('confirm') ?>',
                    text: '<?= __('archive_sig_revoke_confirm') ?>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonText: '<?= __('cancel_btn') ?>',
                    confirmButtonText: '<?= __('yes') ?>'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        // حذف التوقيع
                        $.ajax({
                            url: window.location.href,
                            method: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'revoke_signature',
                                signature_id: sigId,
                                document_id: docId
                            },
                            success: function(resp) {
                                if (resp.success) {
                                    location.reload();
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: errorTitle,
                                        text: resp.message,
                                        confirmButtonText: okText
                                    });
                                }
                            },
                            error: function() {
                                Swal.fire({
                                    icon: 'error',
                                    title: errorTitle,
                                    text: '<?= __('sig_error') ?>',
                                    confirmButtonText: okText
                                });
                            }
                        });
                    }
                });
            });

            // رسالة النجاح من sessionStorage
            var showSuccess = sessionStorage.getItem('showSuccess');
            if (showSuccess) {
                Swal.fire({
                    icon: 'success',
                    title: successTitle,
                    text: showSuccess,
                    confirmButtonText: okText
                });
                sessionStorage.removeItem('showSuccess');
            }
        });
    </script>
</body>

</html>