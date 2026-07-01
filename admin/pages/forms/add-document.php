<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";
require __DIR__ . '/../../lang/init.php';
require_once __DIR__ . "/../../../core/Notify.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-document.php";

if (!$current_user_id) {
    die(__('login_required'));
}

// 1. ط¬ظ„ط¨ id ط§ظ„طµظپط­ط© ط¯ظٹظ†ط§ظ…ظٹظƒظٹط§ظ‹ ظ…ظ† ط¬ط¯ظˆظ„ sys_menu
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);

$current_page_id = $menu_item['id'] ?? 0;

// 2. ط¬ظ„ط¨ طµظ„ط§ط­ظٹط© ط§ظ„ط¥ط¶ط§ظپط© ظ…ظ† ط¬ط¯ظˆظ„ user_menu_access
$can_add = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_add FROM user_menu_access
                  WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);

    $can_add = $permissions['can_add'] ?? 0;
}

// جلب أنواع الوثائق (مع GROUP BY لمنع التكرار)
$types_query = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_DOC_TYPES . " WHERE is_active = 1 GROUP BY name ORDER BY name");
$doc_types = $types_query->fetchAll(PDO::FETCH_ASSOC);

// جلب التصنيفات (مع GROUP BY لمنع التكرار)
$categories_query = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_DOC_CATEGORIES . " WHERE is_active = 1 GROUP BY name ORDER BY name");
$categories = $categories_query->fetchAll(PDO::FETCH_ASSOC);

// جلب سياسات الاعتماد (مع GROUP BY لمنع التكرار)
$workflows = $pdo->query("SELECT MIN(id) AS id, name FROM " . TBL_APPROVAL_WORKFLOWS . " WHERE is_active = 1 GROUP BY name ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// جلب مراحل كل سياسة مع بيانات الموظف
$workflowEmployees = [];
$stagesStmt = $pdo->query("
    SELECT aps.workflow_id, aps.stage_order, aps.stage_name,
           e.id AS emp_id, e.full_name, e.job_title, e.department, e.emp_code
    FROM " . TBL_APPROVAL_STAGES . " aps
    JOIN " . TBL_EMPLOYEES . " e ON e.id = aps.employee_id
    WHERE aps.is_active = 1
    ORDER BY aps.workflow_id, aps.stage_order ASC
");
foreach ($stagesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $workflowEmployees[$row['workflow_id']][] = $row;
}

// جلب الأقسام (مع GROUP BY لمنع التكرار)
$departments_list = $pdo->query("SELECT MIN(id) AS id, department_name FROM departments GROUP BY department_name ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_POST['add_document'])) {
    $doc_number   = trim($_POST['doc_number'] ?? '');
    $title        = trim($_POST['title']);
    $type_id      = (int) ($_POST['type_id'] ?? 0);
    $category_id  = (int) ($_POST['category_id'] ?? 0);
    $department   = trim($_POST['department'] ?? '');
    $description  = Security::sanitizeHtml($_POST['description'] ?? '');
    $status       = $_POST['status'] ?? 'draft';
    $workflow_id  = (int) ($_POST['workflow_id'] ?? 0);

    // ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط­ظ‚ظˆظ„ ط§ظ„ظ…ط·ظ„ظˆط¨ط©
    $errors = [];
    if (empty($title)) {
        $errors[] = __('doc_title_field') . ' ' . __('field_required');
    }
    if ($type_id <= 0) {
        $errors[] = __('doc_type') . ' ' . __('field_required');
    }
    if ($category_id <= 0) {
        $errors[] = __('doc_category') . ' ' . __('field_required');
    }
    if (!isset($_FILES['file_input']) || $_FILES['file_input']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = __('doc_upload') . ' ' . __('field_required');
    } else {
        $uploadCheck = Security::validateUpload($_FILES['file_input'], 'document', 30);
        if (!$uploadCheck['ok']) $errors[] = $uploadCheck['error'];
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // طھظˆظ„ظٹط¯ ط±ظ‚ظ… ط§ظ„ظˆط«ظٹظ‚ط© ط¥ظ† ظ„ظ… ظٹطھظ… ط¥ط¯ط®ط§ظ„ظ‡
            $year = date('Y');
            if (empty($doc_number)) {
                $prefix = "DOC-" . $year . "-";
                $seqSql = "SELECT MAX(doc_number) FROM " . TBL_DOCUMENTS . " WHERE doc_number LIKE ?";
                $seqStmt = $pdo->prepare($seqSql);
                $seqStmt->execute([$prefix . '%']);
                $lastDoc = $seqStmt->fetchColumn();
                if ($lastDoc) {
                    $parts = explode('-', $lastDoc);
                    $lastNum = (int) end($parts);
                    $nextNum = $lastNum + 1;
                } else {
                    $nextNum = 1;
                }
                $doc_number = $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
            }

            // ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ظ…ظ„ظپ
            $file      = $_FILES['file_input'];
            $orig_name = basename($file['name']);
            $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $file_size = $file['size'];
            $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._\-\x{0600}-\x{06FF}]/u', '_', $orig_name);

            // MIME type
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            // file_format ظ…ظ† ط§ظ„ط§ظ…طھط¯ط§ط¯
            $format_map = [
                'pdf'  => 'PDF',
                'doc'  => 'DOC',
                'docx' => 'DOCX',
                'xls'  => 'XLS',
                'xlsx' => 'XLSX',
                'jpg'  => 'JPG',
                'jpeg' => 'JPEG',
                'png'  => 'PNG',
            ];
            $file_format = $format_map[$file_ext] ?? strtoupper($file_ext);

            // 1. INSERT ط£ظˆظ„ظٹ ط¨ط¯ظˆظ† file_path
            $sql = "INSERT INTO " . TBL_DOCUMENTS . "
                        (doc_number, title, type_id, category_id, description,
                         file_path, file_name, file_format, file_size,
                         department, status, workflow_id, version, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $doc_number,
                $title,
                $type_id,
                $category_id,
                $description,
                '',          // placeholder
                $safe_name,
                $file_format,
                $file_size,
                $department,
                $status,
                $workflow_id ?: null,
                $current_user_id,
            ]);

            $document_id = (int) $pdo->lastInsertId();

            // إنشاء سجلات مراحل الاعتماد
            if ($workflow_id > 0) {
                $stages = $pdo->prepare("SELECT id, employee_id FROM " . TBL_APPROVAL_STAGES . " WHERE workflow_id = ? AND is_active = 1 ORDER BY stage_order ASC");
                $stages->execute([$workflow_id]);
                $insApproval = $pdo->prepare("INSERT INTO " . TBL_DOC_APPROVALS . " (document_id, stage_id, workflow_id, employee_id, status) VALUES (?, ?, ?, ?, 'pending')");
                foreach ($stages as $st) {
                    $insApproval->execute([$document_id, $st['id'], $workflow_id, $st['employee_id']]);
                }
            }

            // طھط­ط¶ظٹط± ط£ط³ظ…ط§ط، ط§ظ„ظ…ط¬ظ„ط¯ط§طھ
            $dept_slug = !empty($department)
                ? preg_replace('/[^\x{0600}-\x{06FF}\w\-]/u', '_', $department)
                : 'general';
            $dept_slug = trim(preg_replace('/_+/', '_', $dept_slug), '_');

            // ط§ظ„ط­طµظˆظ„ ط¹ظ„ظ‰ ط§ط³ظ… ط§ظ„ظ†ظˆط¹
            $typeSql = "SELECT name FROM " . TBL_DOC_TYPES . " WHERE id = ?";
            $typeStmt = $pdo->prepare($typeSql);
            $typeStmt->execute([$type_id]);
            $type_row = $typeStmt->fetch(PDO::FETCH_ASSOC);
            $type_slug = $type_row
                ? preg_replace('/[^\x{0600}-\x{06FF}\w\-]/u', '_', $type_row['name'])
                : 'unknown';
            $type_slug = trim(preg_replace('/_+/', '_', $type_slug), '_');

            // ط¨ظ†ط§ط، ظ…ط³ط§ط± ط§ظ„ط­ظپط¸
            $rel_dir  = "archive/{$year}/{$dept_slug}/{$type_slug}/{$document_id}";
            $abs_dir  = __DIR__ . "/../../../{$rel_dir}";
            $versions_dir = $abs_dir . '/versions';

            if (!is_dir($versions_dir)) {
                mkdir($versions_dir, 0777, true);
            }

            $rel_file_path = $rel_dir . '/original.' . $file_ext;
            $abs_file_path = $abs_dir . '/original.' . $file_ext;

            // 2. ظ†ظ‚ظ„ ط§ظ„ظ…ظ„ظپ
            if (move_uploaded_file($file['tmp_name'], $abs_file_path)) {
                // 3. UPDATE ط¨ظ…ط³ط§ط± ط§ظ„ظ…ظ„ظپ ط§ظ„طµط­ظٹط­
                $updSql = "UPDATE " . TBL_DOCUMENTS . " SET file_path = ? WHERE id = ?";
                $updStmt = $pdo->prepare($updSql);
                $updStmt->execute([$rel_file_path, $document_id]);

                $pdo->commit();

                // ── إشعار داخلي للمعتمِدين عبر الدردشة ──────────────────
                if ($workflow_id > 0) {
                    // جلب اسم النوع لإثراء الإشعار
                    $typeNameRow = $pdo->prepare("SELECT name FROM " . TBL_DOC_TYPES . " WHERE id=? LIMIT 1");
                    $typeNameRow->execute([$type_id]);
                    $typeName = $typeNameRow->fetchColumn() ?: '';

                    Notify::onDocumentApprovalRequired($pdo, (int)$current_user_id, (int)$workflow_id, [
                        'title'      => $title,
                        'doc_number' => $doc_number,
                        'type_name'  => $typeName,
                        'department' => $department,
                    ]);
                }
                // ────────────────────────────────────────────────────────

                log_action($pdo, 'create', 'document', $document_id, [], [
                    'doc_number'  => $doc_number,
                    'title'       => $title,
                    'type_id'     => $type_id,
                    'category_id' => $category_id,
                    'file_path'   => $rel_file_path,
                    'file_format' => $file_format,
                    'file_size'   => $file_size,
                    'department'  => $department,
                    'status'      => $status,
                ]);

                // طباعة كتل الموظفين (pending) على الـ PDF فور الرفع
                if ($workflow_id > 0 && strtolower($file_ext) === 'pdf') {
                    stamp_approval_template($pdo, $document_id);
                }

            set_success(__('doc_added_success'));
            echo "<script>window.location.href = '../tables/show-documents.php';</script>";
            exit;
            } else {
                throw new Exception(getLang() === 'ar' ? 'فشل نقل الملف المرفوع' : 'Failed to upload file');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'error',
                            title: '" . __('error') . "',
                            text: '" . addslashes(__('error') . ": " . $e->getMessage()) . "',
                            confirmButtonText: '" . __('ok') . "'
                        });
                    });
                  </script>";
        }
    } else {
        $error_msg = implode('<br>', $errors);
        echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'error',
                        title: '" . __('error') . "',
                        html: '" . addslashes($error_msg) . "',
                        confirmButtonText: '" . __('ok') . "'
                    });
                });
              </script>";
    }
}
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= __('doc_add') ?></title>
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>
        :root {
            --primary:   var(--crm-primary, #1e4b8a);
            --primary-lt:rgba(var(--crm-primary-rgb, 30,75,138), 0.1);
            --success:   #28a745;
            --warning:   #ffc107;
            --border:    #dee2e6;
        }
        body { direction:<?= isRtl()?'rtl':'ltr' ?>; text-align:<?= isRtl()?'right':'left' ?>; background:#f4f6f9; font-family:'Source Sans Pro',Arial,sans-serif; }

        /* ── شريط الترويسة ── */

        /* ── بطاقة القسم ── */
        .form-section {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .form-section .sec-header {
            background: var(--primary-lt);
            border-bottom: 2px solid var(--primary);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-section .sec-header .sec-icon {
            width: 34px; height: 34px;
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }
        .form-section .sec-header h6 {
            margin: 0;
            font-weight: 700;
            color: var(--primary);
            font-size: .95rem;
        }
        .form-section .sec-body { padding: 20px; }

        /* ── حقول الإدخال ── */
        .form-control {
            border-radius: 8px;
            border: 1.5px solid var(--border);
            padding: 9px 14px;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(30,75,138,.12);
        }
        label { font-weight: 600; color: #444; font-size: .875rem; margin-bottom: 5px; }
        .req { color: #dc3545; }

        /* ── منطقة رفع الملف ── */
        .upload-zone {
            border: 2.5px dashed #a0b4d0;
            border-radius: 12px;
            background: #f7f9fc;
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
        }
        .upload-zone:hover, .upload-zone.drag-over {
            border-color: var(--primary);
            background: var(--primary-lt);
        }
        .upload-zone input[type="file"] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .upload-zone .uz-icon { font-size: 2.8rem; color: #a0b4d0; margin-bottom: 10px; }
        .upload-zone .uz-icon.has-file { color: var(--success); }
        .upload-zone .uz-title { font-weight: 600; color: #555; margin-bottom: 4px; }
        .upload-zone .uz-sub { font-size: .8rem; color: #888; }
        .upload-zone .uz-filename {
            display: none;
            margin-top: 10px;
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: .85rem;
            color: #2e7d32;
            font-weight: 600;
        }
        .format-badges { margin-top: 10px; }
        .format-badges .badge {
            margin: 2px;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: .72rem;
            font-weight: 600;
        }

        /* ── بطاقة الموافقين ── */
        .approver-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f0f4ff;
            border: 1px solid #c8d8f8;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 8px;
        }
        .approver-chip .chip-order {
            width: 30px; height: 30px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .8rem;
            flex-shrink: 0;
        }
        .approver-chip .chip-name { font-weight: 600; font-size: .875rem; color: #222; }
        .approver-chip .chip-meta { font-size: .78rem; color: #666; }
        .approver-chip .chip-arrow {
            margin-inline: 6px;
            color: #aaa;
            font-size: .9rem;
        }

        /* ── حالة الوثيقة ── */
        .status-options { display: flex; gap: 10px; flex-wrap: wrap; }
        .status-btn {
            flex: 1; min-width: 100px;
            border: 2px solid var(--border);
            border-radius: 10px;
            padding: 12px 10px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            background: #fff;
        }
        .status-btn:hover { border-color: var(--primary); background: var(--primary-lt); }
        .status-btn.active-status { border-color: var(--primary); background: var(--primary-lt); }
        .status-btn input { display: none; }
        .status-btn .sb-icon { font-size: 1.5rem; margin-bottom: 4px; }
        .status-btn .sb-label { font-size: .8rem; font-weight: 600; }

        /* ── أزرار الإجراءات ── */
        .action-bar {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-submit {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-weight: 700;
            font-size: .95rem;
            transition: background .2s, transform .1s;
        }
        .btn-submit:hover { background: #163870; color:#fff; transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }
        .btn-back {
            color: #666;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 9px 20px;
            background: #fff;
            transition: all .2s;
        }
        .btn-back:hover { background: #f4f6f9; color: #333; }
    </style>
</head>

<body class="hold-transition layout-fixed">
<div class="wrapper">
    <?php include(__DIR__ . '/../../main-header.php'); ?>
    <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="page-banner">
                    <div>
                        <h4><i class="fas fa-file-alt ml-2"></i><?= __('doc_add_new') ?></h4>
                        <small style="opacity:.8"><?= __('docs_management') ?> &mdash; <?= __('doc_add') ?></small>
                    </div>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                        <li class="breadcrumb-item"><a href="../tables/show-documents.php"><?= __('docs_management') ?></a></li>
                        <li class="breadcrumb-item active"><?= __('doc_add') ?></li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <form method="POST" enctype="multipart/form-data" id="docForm">

                    <div class="row">
                        <!-- ══ العمود الرئيسي ══ -->
                        <div class="col-lg-8">

                            <!-- ① البيانات الأساسية -->
                            <div class="form-section">
                                <div class="sec-header">
                                    <div class="sec-icon"><i class="fas fa-info"></i></div>
                                    <h6><?= getLang()==='ar' ? 'البيانات الأساسية' : 'Basic Information' ?></h6>
                                </div>
                                <div class="sec-body">
                                    <div class="row">
                                        <div class="col-md-4 form-group mb-3">
                                            <label><?= __('doc_number') ?> <small class="text-muted font-weight-normal">(<?= __('optional') ?>)</small></label>
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text" style="border-radius:8px 0 0 8px;border:1.5px solid var(--border);background:#f0f4ff;color:var(--primary)"><i class="fas fa-hashtag"></i></span>
                                                </div>
                                                <input type="text" name="doc_number" class="form-control" style="border-radius:0 8px 8px 0" placeholder="DOC-<?= date('Y') ?>-0001">
                                            </div>
                                            <small class="text-muted"><?= getLang()==='ar'?'يُولَّد تلقائياً إن تُرك فارغاً':'Auto-generated if left empty' ?></small>
                                        </div>
                                        <div class="col-md-8 form-group mb-3">
                                            <label><?= __('doc_title_field') ?> <span class="req">*</span></label>
                                            <input type="text" name="title" class="form-control" placeholder="<?= __('doc_title_field') ?>" required>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label><?= __('doc_description') ?></label>
                                        <textarea name="description" class="form-control" rows="3" placeholder="<?= getLang()==='ar'?'وصف مختصر للوثيقة...':'Brief description...' ?>"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- ② التصنيف -->
                            <div class="form-section">
                                <div class="sec-header">
                                    <div class="sec-icon"><i class="fas fa-tags"></i></div>
                                    <h6><?= getLang()==='ar'?'التصنيف والتنظيم':'Classification' ?></h6>
                                </div>
                                <div class="sec-body">
                                    <div class="row">
                                        <div class="col-md-4 form-group mb-3">
                                            <label><?= __('doc_type') ?> <span class="req">*</span></label>
                                            <select name="type_id" class="form-control" required>
                                                <option value="">-- <?= __('doc_type') ?> --</option>
                                                <?php foreach ($doc_types as $type): ?>
                                                    <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 form-group mb-3">
                                            <label><?= __('doc_category') ?> <span class="req">*</span></label>
                                            <select name="category_id" class="form-control" required>
                                                <option value="">-- <?= __('doc_category') ?> --</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 form-group mb-0">
                                            <label><?= __('doc_department') ?></label>
                                            <select name="department" class="form-control">
                                                <option value="">-- <?= __('doc_department') ?> --</option>
                                                <?php foreach ($departments_list as $dept): ?>
                                                    <option value="<?= htmlspecialchars($dept['department_name']) ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ③ سياسة الاعتماد -->
                            <div class="form-section">
                                <div class="sec-header">
                                    <div class="sec-icon" style="background:#6f42c1"><i class="fas fa-stamp"></i></div>
                                    <h6 style="color:#6f42c1"><?= getLang()==='ar'?'سياسة الاعتماد والتوقيع':'Approval Workflow' ?></h6>
                                </div>
                                <div class="sec-body">
                                    <div class="form-group mb-3">
                                        <label><?= __('approval_workflows') ?></label>
                                        <select name="workflow_id" class="form-control" id="workflowSelect">
                                            <option value=""><?= getLang()==='ar'?'— بدون سياسة اعتماد —':'— No Approval Policy —' ?></option>
                                            <?php foreach ($workflows as $wf): ?>
                                                <option value="<?= $wf['id'] ?>"><?= htmlspecialchars($wf['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="workflowEmployeesBox" style="display:none">
                                        <div class="d-flex align-items-center mb-2">
                                            <span style="font-size:.82rem;font-weight:600;color:#6f42c1"><i class="fas fa-route ml-1"></i><?= getLang()==='ar'?'مسار الاعتماد':'Approval Chain' ?></span>
                                        </div>
                                        <div id="workflowChain"></div>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /col-lg-8 -->

                        <!-- ══ الشريط الجانبي ══ -->
                        <div class="col-lg-4">

                            <!-- رفع الملف -->
                            <div class="form-section">
                                <div class="sec-header">
                                    <div class="sec-icon" style="background:#17a2b8"><i class="fas fa-cloud-upload-alt"></i></div>
                                    <h6 style="color:#17a2b8"><?= __('doc_upload') ?> <span class="req">*</span></h6>
                                </div>
                                <div class="sec-body">
                                    <div class="upload-zone" id="uploadZone">
                                        <input type="file" name="file_input" id="fileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                                        <div class="uz-icon" id="uzIcon"><i class="fas fa-file-upload"></i></div>
                                        <div class="uz-title" id="uzTitle"><?= getLang()==='ar'?'اسحب الملف هنا أو انقر للاختيار':'Drag file here or click to browse' ?></div>
                                        <div class="uz-sub" id="uzSub"><?= getLang()==='ar'?'الحد الأقصى: 20MB':'Max size: 20MB' ?></div>
                                        <div class="uz-filename" id="uzFilename"></div>
                                    </div>
                                    <div class="format-badges mt-2 text-center">
                                        <span class="badge badge-danger">PDF</span>
                                        <span class="badge badge-primary">DOC</span>
                                        <span class="badge badge-success">XLS</span>
                                        <span class="badge badge-warning">JPG</span>
                                        <span class="badge badge-secondary">PNG</span>
                                    </div>
                                </div>
                            </div>

                            <!-- الحالة -->
                            <div class="form-section">
                                <div class="sec-header">
                                    <div class="sec-icon" style="background:#fd7e14"><i class="fas fa-toggle-on"></i></div>
                                    <h6 style="color:#fd7e14"><?= __('doc_status') ?></h6>
                                </div>
                                <div class="sec-body">
                                    <div class="status-options">
                                        <label class="status-btn active-status" id="sb-draft">
                                            <input type="radio" name="status" value="draft" checked onchange="updateStatusUI(this)">
                                            <div class="sb-icon" style="color:#6c757d"><i class="fas fa-file-alt"></i></div>
                                            <div class="sb-label"><?= __('status_draft') ?></div>
                                        </label>
                                        <label class="status-btn" id="sb-approved">
                                            <input type="radio" name="status" value="approved" onchange="updateStatusUI(this)">
                                            <div class="sb-icon" style="color:#28a745"><i class="fas fa-check-circle"></i></div>
                                            <div class="sb-label"><?= __('status_approved') ?></div>
                                        </label>
                                        <label class="status-btn" id="sb-archived">
                                            <input type="radio" name="status" value="archived" onchange="updateStatusUI(this)">
                                            <div class="sb-icon" style="color:#6f42c1"><i class="fas fa-archive"></i></div>
                                            <div class="sb-label"><?= __('status_archived') ?></div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- ملخص سريع -->
                            <div class="form-section">
                                <div class="sec-header">
                                    <div class="sec-icon" style="background:#20c997"><i class="fas fa-clipboard-check"></i></div>
                                    <h6 style="color:#20c997"><?= getLang()==='ar'?'ملخص الوثيقة':'Summary' ?></h6>
                                </div>
                                <div class="sec-body p-3">
                                    <small class="d-block mb-2" id="sumTitle" style="color:#888"><?= getLang()==='ar'?'العنوان: —':'Title: —' ?></small>
                                    <small class="d-block mb-2" id="sumType"  style="color:#888"><?= getLang()==='ar'?'النوع: —':'Type: —' ?></small>
                                    <small class="d-block mb-2" id="sumCat"   style="color:#888"><?= getLang()==='ar'?'التصنيف: —':'Category: —' ?></small>
                                    <small class="d-block"      id="sumFile"  style="color:#888"><?= getLang()==='ar'?'الملف: —':'File: —' ?></small>
                                </div>
                            </div>

                        </div><!-- /col-lg-4 -->
                    </div><!-- /row -->

                    <!-- شريط الإجراءات -->
                    <div class="action-bar mb-4">
                        <a href="../tables/show-documents.php" class="btn btn-back">
                            <i class="fas fa-arrow-<?= isRtl()?'right':'left' ?> ml-1"></i> <?= __('back') ?>
                        </a>
                        <?php if ($can_add == 1): ?>
                        <button type="submit" name="add_document" class="btn btn-submit">
                            <i class="fas fa-save ml-2"></i> <?= __('doc_add') ?>
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-submit" disabled style="opacity:.55;cursor:not-allowed">
                            <i class="fas fa-ban ml-2"></i> <?= __('no_permission') ?>
                        </button>
                        <?php endif; ?>
                    </div>

                </form>
            </div>
        </section>
    </div><!-- /content-wrapper -->
</div><!-- /wrapper -->

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var workflowData = <?= json_encode($workflowEmployees, JSON_UNESCAPED_UNICODE) ?>;
var isRtl = <?= isRtl() ? 'true' : 'false' ?>;

// ── منطقة رفع الملف ──────────────────────────────────────────────
var zone  = document.getElementById('uploadZone');
var input = document.getElementById('fileInput');

input.addEventListener('change', function() { showFile(this.files[0]); });

zone.addEventListener('dragover',  function(e){ e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', function(){ zone.classList.remove('drag-over'); });
zone.addEventListener('drop', function(e){
    e.preventDefault(); zone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFile(e.dataTransfer.files[0]); }
});

function showFile(file) {
    if (!file) return;
    var icon = document.getElementById('uzIcon');
    var title = document.getElementById('uzTitle');
    var fn = document.getElementById('uzFilename');
    var iconMap = { 'pdf':'fa-file-pdf','doc':'fa-file-word','docx':'fa-file-word','xls':'fa-file-excel','xlsx':'fa-file-excel','jpg':'fa-file-image','jpeg':'fa-file-image','png':'fa-file-image' };
    var ext = file.name.split('.').pop().toLowerCase();
    icon.innerHTML = '<i class="fas ' + (iconMap[ext] || 'fa-file') + '"></i>';
    icon.classList.add('has-file');
    title.textContent = isRtl ? 'تم اختيار الملف بنجاح' : 'File selected';
    fn.textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
    fn.style.display = 'block';
    document.getElementById('sumFile').textContent = (isRtl ? 'الملف: ' : 'File: ') + file.name;
}

// ── ملخص ديناميكي ────────────────────────────────────────────────
document.querySelector('[name="title"]').addEventListener('input', function(){
    document.getElementById('sumTitle').textContent = (isRtl?'العنوان: ':'Title: ') + (this.value||'—');
});
document.querySelector('[name="type_id"]').addEventListener('change', function(){
    var t = this.options[this.selectedIndex].text;
    document.getElementById('sumType').textContent = (isRtl?'النوع: ':'Type: ') + (this.value?t:'—');
});
document.querySelector('[name="category_id"]').addEventListener('change', function(){
    var t = this.options[this.selectedIndex].text;
    document.getElementById('sumCat').textContent = (isRtl?'التصنيف: ':'Category: ') + (this.value?t:'—');
});

// ── حالة الوثيقة ─────────────────────────────────────────────────
function updateStatusUI(radio) {
    document.querySelectorAll('.status-btn').forEach(function(b){ b.classList.remove('active-status'); });
    radio.closest('.status-btn').classList.add('active-status');
}

// ── مسار الاعتماد ────────────────────────────────────────────────
document.getElementById('workflowSelect').addEventListener('change', function() {
    var wfId  = this.value;
    var box   = document.getElementById('workflowEmployeesBox');
    var chain = document.getElementById('workflowChain');
    chain.innerHTML = '';
    if (!wfId || !workflowData[wfId] || !workflowData[wfId].length) { box.style.display='none'; return; }

    workflowData[wfId].forEach(function(emp, idx) {
        var chip = document.createElement('div');
        chip.className = 'approver-chip';
        chip.innerHTML =
            '<div class="chip-order">' + emp.stage_order + '</div>' +
            '<div class="flex-grow-1">' +
                '<div class="chip-name">' + (emp.full_name||'—') + ' <small class="text-muted">(' + (emp.emp_code||'') + ')</small></div>' +
                '<div class="chip-meta"><i class="fas fa-building ml-1"></i>' + (emp.department||'—') + ' &nbsp;|&nbsp; <i class="fas fa-briefcase ml-1"></i>' + (emp.job_title||'—') + '</div>' +
            '</div>' +
            '<div><span class="badge badge-pill badge-light border">' + (emp.stage_name||'') + '</span></div>';
        chain.appendChild(chip);

        if (idx < workflowData[wfId].length - 1) {
            var arr = document.createElement('div');
            arr.style.cssText = 'text-align:center;color:#aaa;margin:-2px 0;font-size:1rem';
            arr.innerHTML = '<i class="fas fa-chevron-' + (isRtl?'down':'down') + '"></i>';
            chain.appendChild(arr);
        }
    });
    box.style.display = 'block';
});
</script>
</body>
</html>
