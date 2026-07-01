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

// ظ†ط³طھط®ط¯ظ… طµظ„ط§ط­ظٹط© ط§ظ„طھط¹ط¯ظٹظ„ ظ…ظ† طµظپط­ط© show-documents.php ظ†ظپط³ظ‡ط§
$page_path = "pages/tables/show-documents.php";
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$current_page_id = (int)$menuStmt->fetchColumn();

$can_edit = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_edit FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $can_edit = (int)$accessStmt->fetchColumn();
}

// ط¬ظ„ط¨ ط§ظ„ط¨ظٹط§ظ†ط§طھ
$doc_id = (int)($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    header("Location: ../tables/show-documents.php");
    exit;
}

$docStmt = $pdo->prepare("SELECT d.*, t.name AS type_name, c.name AS category_name
    FROM " . TBL_DOCUMENTS . " d
    LEFT JOIN " . TBL_DOC_TYPES . " t ON d.type_id = t.id
    LEFT JOIN " . TBL_DOC_CATEGORIES . " c ON d.category_id = c.id
    WHERE d.id = ?");
$docStmt->execute([$doc_id]);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    header("Location: ../tables/show-documents.php");
    exit;
}

// ط¬ظ„ط¨ ط§ظ„ظ‚ظˆط§ط¦ظ…
$doc_types = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_DOC_TYPES . " WHERE is_active = 1 GROUP BY name ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$doc_categories = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_DOC_CATEGORIES . " WHERE is_active = 1 GROUP BY name ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT MIN(d.id) AS id, d.department_name, r.region_name FROM departments d LEFT JOIN regions r ON d.region_id = r.id GROUP BY d.department_name, r.region_name ORDER BY r.region_name, d.department_name")->fetchAll(PDO::FETCH_ASSOC);
$approval_workflows = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_APPROVAL_WORKFLOWS . " WHERE is_active = 1 GROUP BY name ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ظ…ط¹ط§ظ„ط¬ط© ط§ظ„طھط¹ط¯ظٹظ„
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_document'])) {
    $title       = trim($_POST['title']);
    $type_id     = (int)($_POST['type_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $department  = trim($_POST['department'] ?? '');
    $description = Security::sanitizeHtml($_POST['description'] ?? '');
    $status      = $_POST['status'] ?? 'draft';
    $workflow_id = (int)($_POST['workflow_id'] ?? 0);

    $errors = [];
    if (empty($title)) $errors[] = __('doc_title_field') . ' ' . __('field_required');
    if ($type_id <= 0) $errors[] = __('doc_type') . ' ' . __('field_required');

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $old_values = [
                'title'       => $doc['title'],
                'type_id'     => $doc['type_id'],
                'category_id' => $doc['category_id'],
                'department'  => $doc['department'],
                'description' => $doc['description'],
    'status'      => $doc['status'],
    'file_path'   => $doc['file_path'],
    'workflow_id' => $doc['workflow_id'],
];

// طھط­ط¯ظٹط« ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ط£ط³ط§ط³ظٹط©
$updStmt = $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET title=?, type_id=?, category_id=?, department=?, description=?, status=?, workflow_id=?, updated_at=NOW() WHERE id=?");
$updStmt->execute([$title, $type_id, $category_id, $department ?: null, $description ?: null, $status, $workflow_id ?: null, $doc_id]);

// إعادة إنشاء سجلات المراحل إذا تم تغيير سياسة الاعتماد
if ($workflow_id > 0 && $workflow_id !== (int)$doc['workflow_id']) {
    $pdo->prepare("DELETE FROM " . TBL_DOC_APPROVALS . " WHERE document_id = ?")->execute([$doc_id]);
    $stStmt = $pdo->prepare("SELECT id, employee_id FROM " . TBL_APPROVAL_STAGES . " WHERE workflow_id = ? AND is_active = 1 ORDER BY stage_order ASC");
    $stStmt->execute([$workflow_id]);
    $stRows = $stStmt->fetchAll(PDO::FETCH_ASSOC);
    $insApproval = $pdo->prepare("INSERT INTO " . TBL_DOC_APPROVALS . " (document_id, stage_id, workflow_id, employee_id, status) VALUES (?, ?, ?, ?, 'pending')");
    foreach ($stRows as $st) {
        $insApproval->execute([$doc_id, $st['id'], $workflow_id, $st['employee_id']]);
    }
    // ── تعبئة تلقائية للقسم من أول موظف في السياسة ──────────────────────
    if (empty($department) && !empty($stRows)) {
        $firstEmpId = $stRows[0]['employee_id'];
        $deptRow = $pdo->prepare("
            SELECT COALESCE(d.department_name, e.department) AS dept
            FROM " . TBL_EMPLOYEES . " e
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE e.id = ? LIMIT 1
        ");
        $deptRow->execute([$firstEmpId]);
        $autoDept = $deptRow->fetchColumn();
        if ($autoDept) {
            $department = $autoDept;
            // تحديث حقل القسم في الوثيقة
            $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET department = ? WHERE id = ?")->execute([$autoDept, $doc_id]);
        }
    }
}

            // ظ…ط¹ط§ظ„ط¬ط© ط±ظپط¹ ظ…ظ„ظپ ط¬ط¯ظٹط¯
            if (isset($_FILES['file_input']) && $_FILES['file_input']['error'] === UPLOAD_ERR_OK) {
                $uploadCheck = Security::validateUpload($_FILES['file_input'], 'document', 30);
                if (!$uploadCheck['ok']) { $errors[] = $uploadCheck['error']; goto end_edit; }
                $file      = $_FILES['file_input'];
                $orig_name = basename($file['name']);
                $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $file_size = $file['size'];
                $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._\-\x{0600}-\x{06FF}]/u', '_', $orig_name);

                $version = (int)$doc['version'];
                $new_version = $version + 1;

                $format_map = [
                    'pdf'  => 'PDF', 'doc'  => 'DOC', 'docx' => 'DOCX',
                    'xls'  => 'XLS', 'xlsx' => 'XLSX',
                    'jpg'  => 'JPG', 'jpeg' => 'JPEG', 'png'  => 'PNG',
                ];
                $file_format = $format_map[$file_ext] ?? strtoupper($file_ext);

                // ط¥ظ†ط´ط§ط، ظ…ط¬ظ„ط¯ ط§ظ„ظ†ط³ط®
                $doc_dir = dirname(__DIR__ . '/../../../' . $doc['file_path']);
                $versions_dir = $doc_dir . '/versions';
                if (!is_dir($versions_dir)) {
                    mkdir($versions_dir, 0777, true);
                }

                // ط£ط±ط´ظپط© ط§ظ„ظ…ظ„ظپ ط§ظ„ظ‚ط¯ظٹظ… ظپظٹ ظ…ط¬ظ„ط¯ versions
                $old_path = __DIR__ . '/../../../' . $doc['file_path'];
                $old_ext  = strtolower(pathinfo($old_path, PATHINFO_EXTENSION));
                $archived_name = 'v' . $version . '_' . $doc['file_name'];
                // ظ†ط³ط® ط§ظ„ظ…ظ„ظپ ط§ظ„ظ‚ط¯ظٹظ… ط¥ظ„ظ‰ ظ…ط¬ظ„ط¯ ط§ظ„ظ†ط³ط® (ط¨ط¯ظˆظ† ط§ظ…طھط¯ط§ط¯ ظ…ط¶ط§ط¹ظپ)
                $archived_path = $versions_dir . '/v' . $version . '_' . basename($doc['file_name'], '.' . $old_ext) . '.' . $old_ext;
                if (file_exists($old_path)) {
                    copy($old_path, $archived_path);
                }

                // ط±ظپط¹ ط§ظ„ظ…ظ„ظپ ط§ظ„ط¬ط¯ظٹط¯ ظ…ظƒط§ظ† ط§ظ„ظ‚ط¯ظٹظ…
                $new_file_path = $doc_dir . '/original.' . $file_ext;
                if (move_uploaded_file($file['tmp_name'], $new_file_path)) {
                    // ط­ط°ظپ ط§ظ„ظ…ظ„ظپ ط§ظ„ظ‚ط¯ظٹظ… ط¥ظ† ظƒط§ظ† ظ…ط®طھظ„ظپ ط§ظ„ط§ظ…طھط¯ط§ط¯
                    if ($doc['file_path'] && file_exists($old_path) && $old_path !== $new_file_path) {
                        unlink($old_path);
                    }

                    // طھط­ط¯ظٹط« ظ…ط³ط§ط± ط§ظ„ظ…ظ„ظپ ظˆط§ظ„ط¨ظٹط§ظ†ط§طھ
                    $rel_path = dirname($doc['file_path']) . '/original.' . $file_ext;
                    $upd2 = $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET file_path=?, file_name=?, file_format=?, file_size=?, version=? WHERE id=?");
                    $upd2->execute([$rel_path, $safe_name, $file_format, $file_size, $new_version, $doc_id]);

                    // طھط³ط¬ظٹظ„ ط§ظ„ظ†ط³ط®ط© ظپظٹ dms_versions
                    $vStmt = $pdo->prepare("INSERT INTO " . TBL_DOC_VERSIONS . " (document_id, version_number, file_path, file_name, file_size, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    $vStmt->execute([$doc_id, $new_version, $rel_path, $safe_name, $file_size, __('doc_file_updated'), $current_user_id]);
                }
            }

            $pdo->commit();

            $new_values = [
                'title'       => $title,
                'type_id'     => $type_id,
                'category_id' => $category_id,
                'department'  => $department,
                'description' => $description,
                'status'      => $status,
                'workflow_id' => $workflow_id,
            ];
            log_action($pdo, 'update', 'document', $doc_id, $old_values, $new_values);

            set_success(__('doc_updated_success'));
            echo "<script>window.location.href = '../tables/show-documents.php';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ icon: 'error', title: '" . __('error') . "',
                            text: '" . addslashes($e->getMessage()) . "',
                            confirmButtonText: '" . __('ok') . "' });
                    });
                  </script>";
        }
    } else {
        end_edit:
        $error_msg = implode('<br>', $errors);
        echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({ icon: 'error', title: '" . __('error') . "',
                        html: '" . addslashes($error_msg) . "',
                        confirmButtonText: '" . __('ok') . "' });
                });
              </script>";
    }
}
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= __('doc_edit') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tempusdominus-bootstrap-4@5.39.0/build/css/tempusdominus-bootstrap-4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/icheck-bootstrap/3.0.1/icheck-bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jqvmap@1.5.1/dist/jqvmap.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@1.13.3/css/OverlayScrollbars.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>
        html, body { overflow-x: hidden !important; scrollbar-width: none !important; -ms-overflow-style: none !important; }
        ::-webkit-scrollbar { display: none !important; width: 0px !important; background: transparent !important; }
        .wrapper { overflow-x: hidden !important; }
        body { direction: <?= isRtl() ? 'rtl' : 'ltr' ?>; text-align: <?= isRtl() ? 'right' : 'left' ?>; }
        .current-file { padding: 8px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6; }
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
                            <h1><?= __('doc_edit') ?> <?= langSwitcher() ?></h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-<?= isRtl() ? 'right' : 'left' ?>">
                                <li class="breadcrumb-item"><a href="../../index.php"><?= __('home') ?></a></li>
                                <li class="breadcrumb-item"><a href="../tables/show-documents.php"><?= __('docs_management') ?></a></li>
                                <li class="breadcrumb-item active"><?= __('doc_edit') ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="row">
                    <div class="col-12">
                        <div class="card card-warning">
                            <div class="card-header">
                                <h3 class="card-title" style="float: <?= isRtl() ? 'right' : 'left' ?>;">
                                    <i class="fas fa-edit ml-1"></i> <?= __('doc_edit') ?>: <?= htmlspecialchars($doc['doc_number']) ?>
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_number') ?></label>
                                                <input type="text" class="form-control" value="<?= htmlspecialchars($doc['doc_number']) ?>" readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_version') ?></label>
                                                <input type="text" class="form-control" value="<?= (int)$doc['version'] ?>" readonly>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label><?= __('doc_title_field') ?> <span class="text-danger">*</span></label>
                                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($doc['title']) ?>" required>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_type') ?> <span class="text-danger">*</span></label>
                                                <select name="type_id" class="form-control" required>
                                                    <option value="">-- <?= __('doc_type') ?> --</option>
                                                    <?php foreach ($doc_types as $t): ?>
                                                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $doc['type_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($t['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_category') ?> <span class="text-danger">*</span></label>
                                                <select name="category_id" class="form-control" required>
                                                    <option value="">-- <?= __('doc_category') ?> --</option>
                                                    <?php foreach ($doc_categories as $c): ?>
                                                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $doc['category_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($c['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_department') ?></label>
                                                <select name="department" class="form-control">
                                                    <option value="">-- <?= __('doc_department') ?> --</option>
                                                    <?php foreach ($departments as $d): ?>
                                                        <option value="<?= htmlspecialchars($d['department_name']) ?>" <?= $d['department_name'] == $doc['department'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($d['department_name']) ?>
                                                            <?php if (!empty($d['region_name'])): ?>
                                                                (<?= htmlspecialchars($d['region_name']) ?>)
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_status') ?></label>
                                                <select name="status" class="form-control">
                                                    <option value="draft" <?= $doc['status'] === 'draft' ? 'selected' : '' ?>><?= __('status_draft') ?></option>
                                                    <option value="approved" <?= $doc['status'] === 'approved' ? 'selected' : '' ?>><?= __('status_approved') ?></option>
                                                    <option value="archived" <?= $doc['status'] === 'archived' ? 'selected' : '' ?>><?= __('status_archived') ?></option>
                                                    <option value="cancelled" <?= $doc['status'] === 'cancelled' ? 'selected' : '' ?>><?= __('status_cancelled') ?></option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label><?= __('approval_workflows') ?></label>
                                        <select name="workflow_id" id="workflowSelect" class="form-control">
                                            <option value="">-- <?= __('approval_workflows') ?> --</option>
                                            <?php foreach ($approval_workflows as $wf): ?>
                                                <option value="<?= $wf['id'] ?>" <?= $wf['id'] == $doc['workflow_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($wf['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- ── مربع معلومات الاعتماد التلقائي ── -->
                                    <div id="wfInfoBox" class="card border-primary mb-3" style="display:none;">
                                        <div class="card-header bg-primary text-white py-2" style="font-size:.9rem;font-weight:600;">
                                            <i class="fas fa-info-circle ml-1"></i> <?= __('approval_auto_fill_info') ?? 'بيانات الاعتماد التلقائية' ?>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <div class="row" style="font-size:.88rem;">
                                                <div class="col-md-6">
                                                    <strong><?= __('doc_department') ?? 'القسم' ?>:</strong>
                                                    <span id="wfInfoDept" class="text-primary mr-1">—</span>
                                                </div>
                                                <div class="col-md-6">
                                                    <strong><?= __('approval_approver') ?? 'الموظف المعتمد' ?>:</strong>
                                                    <span id="wfInfoEmp" class="text-success mr-1">—</span>
                                                    <span id="wfInfoSig" class="badge badge-success" style="display:none;font-size:.72rem;">
                                                        <i class="fas fa-signature ml-1"></i><?= __('has_signature') ?? 'لديه توقيع' ?>
                                                    </span>
                                                    <span id="wfInfoNoSig" class="badge badge-warning" style="display:none;font-size:.72rem;">
                                                        <i class="fas fa-exclamation-triangle ml-1"></i><?= __('no_signature') ?? 'بدون توقيع' ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <!-- مراحل متعددة -->
                                            <div id="wfStagesWrap" class="mt-2" style="display:none;">
                                                <hr class="my-1">
                                                <small class="text-muted d-block mb-1"><?= __('approval_stages') ?? 'مراحل الاعتماد' ?>:</small>
                                                <div id="wfStagesList" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label><?= __('doc_description') ?></label>
                                        <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($doc['description'] ?? '') ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label><?= __('doc_current_file') ?></label>
                                        <div class="current-file">
                                            <i class="fas fa-file ml-1"></i>
                                            <?php if ($doc['file_path']): ?>
                                                <a href="../../<?= htmlspecialchars($doc['file_path']) ?>" target="_blank">
                                                    <?= htmlspecialchars($doc['file_name'] ?: basename($doc['file_path'])) ?>
                                                </a>
                                                <span class="badge badge-<?= strtolower($doc['file_format']) ?> ml-2"><?= htmlspecialchars($doc['file_format']) ?></span>
                                                <small class="text-muted mr-2">
                                                    <?= $doc['file_size'] ? '(' . number_format($doc['file_size'] / 1024, 1) . ' KB)' : '' ?>
                                                </small>
                                                <span class="badge badge-info"><?= __('doc_version') . ' ' . (int)$doc['version'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">--</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label><?= __('doc_upload_new') ?></label>
                                        <input type="file" name="file_input" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png">
                                        <small class="text-muted"><?= __('doc_upload_note') ?></small>
                                    </div>

                                    <div class="form-group">
                                        <a href="../tables/show-documents.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-<?= isRtl() ? 'right' : 'left' ?>"></i> <?= __('back') ?>
                                        </a>
                                        <?php if ($can_edit == 1): ?>
                                            <button type="submit" name="edit_document" class="btn btn-warning">
                                                <i class="fas fa-save"></i> <?= __('doc_save_changes') ?>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary disabled" style="cursor: not-allowed;">
                                                <?= __('doc_save_changes') ?> (<?= __('no_permission') ?>)
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <script src="../../plugins/jquery/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.13.2/jquery-ui.min.js"></script>
        <script>$.widget.bridge('uibutton', $.ui.button)</script>
        <script src="https://cdn.rtlcss.com/bootstrap/v4.2.1/js/bootstrap.min.js"></script>
        <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jquery-sparkline@2.1.2/jquery.sparkline.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jqvmap@1.5.1/dist/jquery.vmap.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jqvmap@1.5.1/dist/maps/jquery.vmap.world.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jquery-knob@1.2.13/dist/jquery.knob.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/min/moment.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/tempusdominus-bootstrap-4@5.39.0/build/js/tempusdominus-bootstrap-4.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@1.13.3/js/jquery.overlayScrollbars.min.js"></script>
        <script src="../../dist/js/adminlte.js"></script>
        <script src="../../dist/js/demo.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <script>
        (function () {
            var deptSelect = document.querySelector('select[name="department"]');
            var wfSelect   = document.getElementById('workflowSelect');
            if (!wfSelect) return;

            function loadWfInfo(wfId) {
                var box       = document.getElementById('wfInfoBox');
                var deptSpan  = document.getElementById('wfInfoDept');
                var empSpan   = document.getElementById('wfInfoEmp');
                var sigBadge  = document.getElementById('wfInfoSig');
                var noSigBadge= document.getElementById('wfInfoNoSig');
                var stagesWrap= document.getElementById('wfStagesWrap');
                var stagesList= document.getElementById('wfStagesList');

                if (!wfId) { box.style.display = 'none'; return; }

                fetch('get_workflow_info.php?workflow_id=' + wfId)
                    .then(function(r){ return r.json(); })
                    .then(function(data) {
                        if (data.error) { box.style.display='none'; return; }

                        box.style.display = 'block';

                        // القسم
                        var dept = data.department || '—';
                        deptSpan.textContent = dept;

                        // تعبئة حقل القسم تلقائياً إذا كان فارغاً
                        if (deptSelect && data.department) {
                            var found = false;
                            for (var i = 0; i < deptSelect.options.length; i++) {
                                if (deptSelect.options[i].value === data.department) {
                                    deptSelect.value = data.department;
                                    deptSelect.style.borderColor = '#28a745';
                                    found = true; break;
                                }
                            }
                            if (!found) {
                                // أضف خياراً مؤقتاً إذا لم يكن موجوداً
                                var opt = new Option(data.department, data.department, true, true);
                                deptSelect.appendChild(opt);
                                deptSelect.style.borderColor = '#28a745';
                            }
                        }

                        // الموظف المعتمد (أول مرحلة)
                        var empName = data.employee || '—';
                        var jobTitle= data.job_title ? ' — ' + data.job_title : '';
                        empSpan.textContent = empName + jobTitle;

                        // حالة التوقيع
                        if (data.has_signature) {
                            sigBadge.style.display   = 'inline-block';
                            noSigBadge.style.display = 'none';
                        } else {
                            sigBadge.style.display   = 'none';
                            noSigBadge.style.display = data.employee ? 'inline-block' : 'none';
                        }

                        // عرض المراحل إذا كانت أكثر من واحدة
                        var stages = data.stages || [];
                        if (stages.length > 1) {
                            stagesWrap.style.display = 'block';
                            stagesList.innerHTML = '';
                            stages.forEach(function(s, i) {
                                var color = s.has_sig ? '#28a745' : '#ffc107';
                                var chip = '<span style="background:#f0f0f0;border:1px solid #ddd;border-radius:20px;padding:3px 10px;font-size:.78rem;display:inline-flex;align-items:center;gap:4px;">'
                                         + '<span style="background:' + color + ';color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;">' + s.order + '</span>'
                                         + '<span>' + (s.employee || '—') + '</span>'
                                         + (s.dept ? '<span style="color:#888;font-size:.72rem;">(' + s.dept + ')</span>' : '')
                                         + '</span>';
                                stagesList.innerHTML += chip;
                            });
                        } else {
                            stagesWrap.style.display = 'none';
                        }
                    })
                    .catch(function() { box.style.display = 'none'; });
            }

            // تحميل معلومات السياسة الحالية عند فتح الصفحة
            if (wfSelect.value) loadWfInfo(wfSelect.value);

            // تحديث عند تغيير السياسة
            wfSelect.addEventListener('change', function () {
                // إزالة اللون الأخضر من حقل القسم عند التغيير
                if (deptSelect) deptSelect.style.borderColor = '';
                loadWfInfo(this.value);
            });
        }());
        </script>
    </div>
</body>
</html>
