<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-signature.php";

if (!$current_user_id) {
    die(__('login_required'));
}

$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);

$current_page_id = $menu_item['id'] ?? 0;

$can_add = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_add FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_add = $permissions['can_add'] ?? 0;
}

$documents = $pdo->query("SELECT id, doc_number, title FROM " . TBL_DOCUMENTS . " ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$employees = $pdo->query("SELECT id, full_name, job_title, department FROM " . TBL_EMPLOYEES . " WHERE can_sign = 1 AND is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_signature'])) {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $pos_x       = (float)($_POST['pos_x'] ?? 10);
    $pos_y       = (float)($_POST['pos_y'] ?? 10);
    $page_number = (int)($_POST['page_number'] ?? 1);
    $width       = (float)($_POST['width'] ?? 150);
    $height      = (float)($_POST['height'] ?? 50);
    $sign_type   = $_POST['sign_type'] ?? 'auto';
    $status      = $_POST['status'] ?? 'pending';

    $errors = [];
    if ($document_id <= 0) $errors[] = __('doc_not_found');
    if ($employee_id <= 0) $errors[] = __('choose_employee');

    if (empty($errors)) {
        try {
            $docStmt = $pdo->prepare("SELECT d.*, t.name AS type_name FROM " . TBL_DOCUMENTS . " d LEFT JOIN " . TBL_DOC_TYPES . " t ON d.type_id = t.id WHERE d.id = ?");
            $docStmt->execute([$document_id]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception(__('doc_not_found'));

            $empStmt = $pdo->prepare("SELECT * FROM " . TBL_EMPLOYEES . " WHERE id = ? AND can_sign = 1");
            $empStmt->execute([$employee_id]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            if (!$emp) throw new Exception(__('emp_not_found'));

            // ── معالجة رفع صورة التوقيع ──
            $sig_image_value = $emp['signature_image'];
            if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($ext, $allowed)) {
                    $sig_dir = __DIR__ . '/../../../uploads/signatures/';
                    if (!is_dir($sig_dir)) {
                        mkdir($sig_dir, 0777, true);
                    }
                    $sig_name = 'sig_emp_' . $employee_id . '_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['signature_image']['tmp_name'], $sig_dir . $sig_name);
                    $sig_path = 'signatures/' . $sig_name;
                    // تحديث صورة التوقيع في سجل الموظف
                    $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET signature_image = ? WHERE id = ?")->execute([$sig_path, $employee_id]);
                    $sig_image_value = $sig_path;
                }
            }

            $pdo->beginTransaction();

            if ($status === 'signed') {
                if (empty($sig_image_value)) {
                    throw new Exception(__('sig_no_image'));
                }

                $sourceFile = __DIR__ . '/../../../' . $doc['file_path'];
                if (!file_exists($sourceFile)) throw new Exception(__('doc_file_missing'));

                $sigFile = __DIR__ . '/../../../uploads/' . $sig_image_value;
                if (!file_exists($sigFile)) throw new Exception(__('sig_file_missing'));

                require_once __DIR__ . '/../../../vendor/autoload.php';

                $docDir = dirname($sourceFile);
                $outputFile = $docDir . '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';

                copy($sourceFile, $outputFile);

                $pdf = new setasign\Fpdi\Fpdi();
                $pageCount = $pdf->setSourceFile($outputFile);

                for ($i = 1; $i <= $pageCount; $i++) {
                    $templateId = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($templateId);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);
                    if ($i == $page_number) {
                        $pdf->Image($sigFile, $pos_x, $pos_y, $width, $height);
                    }
                }

                $pdf->Output('F', $outputFile);

                $insStmt = $pdo->prepare("INSERT INTO " . TBL_SIGNATURES . " (document_id, employee_id, signature_image, pos_x, pos_y, page_number, width, height, sign_type, status, signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'signed', NOW())");
                $insStmt->execute([$document_id, $employee_id, $sig_image_value, $pos_x, $pos_y, $page_number, $width, $height, $sign_type]);

                $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET version = version + 1 WHERE id = ?")->execute([$document_id]);
            } else {
                $insStmt = $pdo->prepare("INSERT INTO " . TBL_SIGNATURES . " (document_id, employee_id, sign_type, pos_x, pos_y, page_number, width, height, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $insStmt->execute([$document_id, $employee_id, $sign_type, $pos_x, $pos_y, $page_number, $width, $height]);
            }

            $pdo->commit();

            log_action($pdo, 'create', 'توقيع', (int)$pdo->lastInsertId(), [], [
                'document_id' => $document_id, 'employee_id' => $employee_id,
                'sign_type' => $sign_type, 'status' => $status,
            ]);

            set_success(getLang() === 'ar' ? 'تم إضافة التوقيع بنجاح' : 'Signature added successfully');
            echo "<script>window.location.href = '../tables/show-signatures.php';</script>";
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
    <title><?= __('sig_add') ?></title>
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
                            <h1><?= __('sig_add') ?> <?= langSwitcher() ?></h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-<?= isRtl() ? 'right' : 'left' ?>">
                                <li class="breadcrumb-item"><a href="../../index.php"><?= __('home') ?></a></li>
                                <li class="breadcrumb-item"><a href="../tables/show-signatures.php"><?= __('sig_title') ?></a></li>
                                <li class="breadcrumb-item active"><?= __('sig_add') ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="row">
                    <div class="col-12">
                        <div class="card card-primary">
                            <div class="card-header">
                                <h3 class="card-title" style="float: <?= isRtl() ? 'right' : 'left' ?>;"><?= __('sig_add') ?></h3>
                            </div>

                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('doc_title_field') ?> <span class="text-danger">*</span></label>
                                                <select name="document_id" class="form-control" required>
                                                    <option value="">-- <?= __('doc_title_field') ?> --</option>
                                                    <?php foreach ($documents as $d): ?>
                                                        <option value="<?= $d['id'] ?>">
                                                            <?= htmlspecialchars($d['doc_number'] . ' - ' . $d['title']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('sig_employee') ?> <span class="text-danger">*</span></label>
                                                <select name="employee_id" class="form-control" required>
                                                    <option value="">-- <?= __('sig_employee') ?> --</option>
                                                    <?php foreach ($employees as $e): ?>
                                                        <option value="<?= $e['id'] ?>"
                                                                data-sig="<?= htmlspecialchars($e['signature_image'] ?? '') ?>">
                                                            <?= htmlspecialchars($e['full_name'] . ($e['job_title'] ? ' (' . $e['job_title'] . ')' : '')) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?= __('sig_upload_sign') ?> <small class="text-muted"><?= __('optional') ?></small></label>
                                                <div id="sig-preview" class="mb-2" style="display:none;">
                                                    <img src="" class="img-thumbnail" style="max-height:60px;">
                                                </div>
                                                <div class="custom-file">
                                                    <input type="file" name="signature_image" class="custom-file-input" id="sig-file" accept="image/*">
                                                    <label class="custom-file-label" for="sig-file"><?= __('choose_file') ?></label>
                                                </div>
                                                <small class="text-muted"><?= __('sig_upload_note') ?></small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label><?= __('sig_type') ?></label>
                                                <select name="sign_type" class="form-control">
                                                    <option value="auto"><?= __('sig_type_auto') ?></option>
                                                    <option value="manual"><?= __('sig_type_manual') ?></option>
                                                    <option value="digital"><?= __('sig_type_digital') ?></option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label><?= __('doc_status') ?></label>
                                                <select name="status" class="form-control">
                                                    <option value="pending"><?= __('sig_pending') ?></option>
                                                    <option value="signed"><?= __('sig_signed') ?></option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label><?= __('sig_page') ?> <span class="text-danger">*</span></label>
                                                <input type="number" name="page_number" class="form-control" value="1" min="1" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label><?= __('sig_x') ?> (mm)</label>
                                                <input type="number" name="pos_x" class="form-control" value="10" step="0.1">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label><?= __('sig_y') ?> (mm)</label>
                                                <input type="number" name="pos_y" class="form-control" value="10" step="0.1">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label><?= __('sig_width') ?> (mm)</label>
                                                <input type="number" name="width" class="form-control" value="150" step="0.1">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label><?= __('sig_height') ?> (mm)</label>
                                                <input type="number" name="height" class="form-control" value="50" step="0.1">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <a href="../tables/show-signatures.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-<?= isRtl() ? 'right' : 'left' ?>"></i> <?= __('back') ?>
                                        </a>
                                        <?php if ($can_add == 1): ?>
                                            <button type="submit" name="add_signature" class="btn btn-primary">
                                                <i class="fas fa-save"></i> <?= __('save') ?>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary disabled" style="cursor: not-allowed;">
                                                <?= __('save') ?> (<?= __('no_permission') ?>)
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
        <script>
            $.widget.bridge('uibutton', $.ui.button)
        </script>
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
            window.addEventListener("load", function() {
                const showSuccess = sessionStorage.getItem('showSuccess');
                if (showSuccess) {
                    Swal.fire({ icon: 'success', title: '<?= __('success') ?>', text: showSuccess,
                        confirmButtonText: '<?= __('ok') ?>', customClass: { confirmButton: 'btn btn-primary' },
                        buttonsStyling: false });
                    sessionStorage.removeItem('showSuccess');
                }
            });
        </script>

        <script>
            $(function() {
                // عرض صورة التوقيع الحالية عند اختيار الموظف
                var $empSelect = $('select[name="employee_id"]');
                var $preview = $('#sig-preview');
                var $previewImg = $preview.find('img');
                var baseUploadPath = '../../uploads/';

                function updateSigPreview() {
                    var $opt = $empSelect.find('option:selected');
                    var sig = $opt.data('sig') || '';
                    if (sig) {
                        $previewImg.attr('src', baseUploadPath + sig);
                        $preview.show();
                    } else {
                        $preview.hide();
                        $previewImg.attr('src', '');
                    }
                }

                $empSelect.on('change', updateSigPreview);
                updateSigPreview();

                // BS4 custom file input label
                $('#sig-file').on('change', function() {
                    var fileName = $(this).val().split('\\').pop();
                    $(this).next('.custom-file-label').html(fileName);
                    // معاينة الملف المختار
                    if (this.files && this.files[0]) {
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            $previewImg.attr('src', e.target.result);
                            $preview.show();
                        };
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            });
        </script>
    </div>
</body>

</html>
