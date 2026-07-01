<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/../../lang/init.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path       = "pages/tables/approval-workflows.php";

if (!$current_user_id) { die(__('login_required')); }

/* ── صلاحيات ── */
$can_add = 1; $can_edit = 1; $can_delete = 1;   // افتراضي: مفتوح
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$menu_item      = $menuStmt->fetch(PDO::FETCH_ASSOC);
$current_page_id = $menu_item['id'] ?? 0;

if ($current_page_id > 0) {
    $accessStmt = $pdo->prepare("SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $perm = $accessStmt->fetch(PDO::FETCH_ASSOC);
    if ($perm) {
        $can_add    = (int)$perm['can_add'];
        $can_edit   = (int)$perm['can_edit'];
        $can_delete = (int)$perm['can_delete'];
    }
}

/* ── معالجة POST: حذف ── */
$flash_ok  = '';
$flash_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_workflow'])) {
    if (!$can_delete) {
        $flash_err = __('no_permission');
    } else {
        $wf_id = (int)$_POST['workflow_id'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM " . TBL_APPROVAL_STAGES    . " WHERE workflow_id=?")->execute([$wf_id]);
            $pdo->prepare("DELETE FROM " . TBL_APPROVAL_WORKFLOWS . " WHERE id=?")->execute([$wf_id]);
            $pdo->commit();
            $flash_ok = __('approval_deleted');
        } catch (Exception $e) {
            $pdo->rollBack();
            $flash_err = $e->getMessage();
        }
    }
}

/* ── معالجة POST: حفظ (إضافة / تعديل) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_workflow'])) {
    $wf_id  = (int)($_POST['workflow_id'] ?? 0);
    $is_new = ($wf_id === 0);

    if (($is_new && !$can_add) || (!$is_new && !$can_edit)) {
        $flash_err = __('no_permission');
    } else {
        $name        = trim($_POST['name']        ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $flash_err = __('approval_name') . ' ' . __('field_required');
        } else {
            try {
                $pdo->beginTransaction();

                if ($is_new) {
                    $pdo->prepare("INSERT INTO " . TBL_APPROVAL_WORKFLOWS . " (name,description) VALUES (?,?)")
                        ->execute([$name, $description]);
                    $wf_id = (int)$pdo->lastInsertId();
                } else {
                    $pdo->prepare("UPDATE " . TBL_APPROVAL_WORKFLOWS . " SET name=?,description=? WHERE id=?")
                        ->execute([$name, $description, $wf_id]);
                    $pdo->prepare("DELETE FROM " . TBL_APPROVAL_STAGES . " WHERE workflow_id=?")->execute([$wf_id]);
                }

                $orders    = $_POST['stage_order']    ?? [];
                $names     = $_POST['stage_name']     ?? [];
                $employees = $_POST['stage_employee'] ?? [];
                $notes     = $_POST['stage_notes']    ?? [];

                $insStmt = $pdo->prepare("INSERT INTO " . TBL_APPROVAL_STAGES .
                    " (workflow_id,stage_order,stage_name,employee_id,notes) VALUES (?,?,?,?,?)");

                foreach ($orders as $i => $ord) {
                    $emp_id = !empty($employees[$i]) ? (int)$employees[$i] : null;
                    if ($emp_id) {
                        $insStmt->execute([
                            $wf_id,
                            (int)$ord,
                            !empty($names[$i])  ? trim($names[$i])  : null,
                            $emp_id,
                            !empty($notes[$i])  ? trim($notes[$i])  : null,
                        ]);
                    }
                }

                $pdo->commit();
                $flash_ok = __('approval_saved');
            } catch (Exception $e) {
                $pdo->rollBack();
                $flash_err = $e->getMessage();
            }
        }
    }
}

/* ── جلب البيانات ── */
$workflows = $pdo->query("SELECT * FROM " . TBL_APPROVAL_WORKFLOWS . " ORDER BY created_at DESC")
                  ->fetchAll(PDO::FETCH_ASSOC);

$stagesByWf = [];
if ($workflows) {
    $ids          = array_column($workflows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stStmt = $pdo->prepare(
        "SELECT s.*, e.full_name AS employee_name
           FROM " . TBL_APPROVAL_STAGES . " s
           LEFT JOIN " . TBL_EMPLOYEES  . " e ON e.id = s.employee_id
          WHERE s.workflow_id IN ($placeholders)
          ORDER BY s.stage_order ASC"
    );
    $stStmt->execute($ids);
    foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
        $stagesByWf[$sr['workflow_id']][] = $sr;
    }
}

$employees = $pdo->query(
    "SELECT id, full_name, emp_code, job_title FROM " . TBL_EMPLOYEES .
    " WHERE is_active=1 AND can_sign=1 ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$rtl      = isRtl();
$dir      = $rtl ? 'rtl'   : 'ltr';
$talign   = $rtl ? 'right' : 'left';
$ml1      = $rtl ? 'ml-1'  : 'mr-1';
$ml2      = $rtl ? 'ml-2'  : 'mr-2';
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title><?= __('approval_title') ?></title>

<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2-bootstrap4.min.css" rel="stylesheet">

<style>
body { direction:<?= $dir ?>; text-align:<?= $talign ?>; }

/* ── Header ── */

/* ── Card ── */
.card-ticket {
    border:none; border-top:4px solid var(--uni-primary);
    box-shadow:0 2px 12px rgba(0,0,0,.08); border-radius:10px;
}
.card-ticket .card-header {
    background:transparent; border-bottom:1px solid #eee;
    padding:14px 20px;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;
}
.card-ticket .card-body { padding:20px; }

/* ── Table ── */
#wfTable th {
    white-space:nowrap; vertical-align:middle; text-align:center;
    background:#f8f9fa; border-bottom:2px solid var(--uni-primary) !important;
}
#wfTable td { vertical-align:middle; text-align:center; }
.dataTables_filter input { border-radius:20px; border:1px solid #ced4da; padding:5px 15px; }

/* ── Action buttons ── */
.btn-action {
    width:34px; height:34px; padding:0;
    display:inline-flex; align-items:center; justify-content:center;
    border-radius:8px;
}
.btn-group-action { display:flex; gap:4px; justify-content:center; flex-wrap:nowrap; }

/* ── Stages badge ── */
.badge-stages {
    background:#e7f1ff; color:var(--uni-primary);
    border-radius:20px; padding:4px 14px;
    font-size:.8rem; font-weight:600;
}

/* ── Stage form rows (inside modal) ── */
.stage-row {
    display:flex; gap:8px; align-items:center; flex-wrap:wrap;
    background:#f8f9fa; border:1px solid #e9ecef;
    border-radius:8px; padding:10px 12px; margin-bottom:8px;
}
.stage-row:hover { border-color:#cfe2ff; background:#f0f6ff; }
.btn-rm { color:#dc3545; cursor:pointer; font-size:1.2rem; padding:0 4px; background:none; border:none; }
.btn-rm:hover { color:#a71d2a; }

/* ── Timeline (in table expand row) ── */
.tl-mini { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px; }
.tl-mini li {
    display:flex; align-items:center; gap:8px;
    font-size:.82rem; color:#444;
}
.tl-mini li .dot {
    width:24px; height:24px; border-radius:50%;
    background:var(--uni-primary); color:#fff;
    font-size:.75rem; font-weight:700;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}

/* ── Child row ── */
tr.detail-row td { background:#f0f6ff !important; padding:12px 20px !important; }

/* ── شريط البحث والفلترة ── */
.filter-bar {
    background:#f8f9fa; border:1px solid #e9ecef; border-radius:10px;
    padding:13px 16px; margin-bottom:14px;
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.filter-bar .fi-wrap { position:relative; flex:1; min-width:180px; }
.filter-bar .fi-wrap .fi-icon { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:#aaa; pointer-events:none; }
.filter-bar .fi-wrap input {
    width:100%; border:1.5px solid #dde3f0; border-radius:8px;
    padding:7px 34px 7px 12px; font-size:.86rem; transition:border-color .2s;
}
.filter-bar .fi-wrap input:focus { outline:none; border-color:var(--uni-primary); box-shadow:0 0 0 3px rgba(13,110,253,.09); }
.filter-bar select.fi-select {
    border:1.5px solid #dde3f0; border-radius:8px; padding:7px 12px;
    font-size:.85rem; background:#fff; min-width:150px; cursor:pointer;
}
.filter-bar select.fi-select:focus { outline:none; border-color:var(--uni-primary); }
.btn-reset-f {
    border:1.5px solid #dde3f0; border-radius:8px; padding:7px 14px;
    font-size:.82rem; background:#fff; color:#666; cursor:pointer; white-space:nowrap;
    transition:all .15s;
}
.btn-reset-f:hover { background:#f0f4ff; color:var(--uni-primary); border-color:var(--uni-primary); }
.f-count { font-size:.78rem; color:#888; white-space:nowrap; }
.f-count strong { color:var(--uni-primary); }

/* ── Select2 ── */
.select2-container--bootstrap4 .select2-selection--single { height:calc(2.25rem + 2px); }
.select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { line-height:2.25rem; }

@media (max-width:575px) {
    .stage-row { flex-direction:column; align-items:stretch; }
    .stage-row input, .stage-row select { max-width:100% !important; }
}
</style>
</head>
<body class="hold-transition layout-fixed sidebar-mini">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

<!-- ═══ Flash messages ═══ -->
<?php if ($flash_ok): ?>
<div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
    <i class="fas fa-check-circle ml-1"></i> <?= htmlspecialchars($flash_ok) ?>
    <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="alert alert-danger alert-dismissible fade show mx-3 mt-3" role="alert">
    <i class="fas fa-exclamation-circle ml-1"></i> <?= htmlspecialchars($flash_err) ?>
    <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>

<section class="content-header">
    <div class="container-fluid">
        <div class="uni-header">
            <h4><i class="fas fa-check-double <?= $ml2 ?>"></i><?= __('approval_title') ?></h4>
            <?php if ($can_add): ?>
            <button type="button" class="btn btn-light btn-sm font-weight-bold"
                    onclick="openModal()">
                <i class="fas fa-plus <?= $ml1 ?>"></i><?= __('approval_add') ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="content">
<div class="container-fluid">

    <div class="card card-ticket">
        <div class="card-header">
            <span class="font-weight-bold text-primary">
                <i class="fas fa-list <?= $ml1 ?>"></i>
                <?= __('approval_title') ?>
                <span class="badge badge-primary badge-pill mr-1" id="wfTotalBadge"><?= count($workflows) ?></span>
            </span>
            <?php if ($can_add): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                <i class="fas fa-plus <?= $ml1 ?>"></i><?= __('approval_add') ?>
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body pb-0">

            <!-- ── شريط الفلترة ── -->
            <div class="filter-bar">
                <div class="fi-wrap">
                    <span class="fi-icon"><i class="fas fa-search"></i></span>
                    <input type="text" id="wfSearch" placeholder="ابحث باسم السياسة أو الوصف...">
                </div>
                <select class="fi-select" id="wfFilterStages">
                    <option value="">كل السياسات</option>
                    <option value="has">لديها مراحل</option>
                    <option value="none">بدون مراحل</option>
                    <option value="1">مرحلة واحدة</option>
                    <option value="2plus">مرحلتان أو أكثر</option>
                </select>
                <button class="btn-reset-f" id="wfResetFilters">
                    <i class="fas fa-undo ml-1"></i> إعادة تعيين
                </button>
                <span class="f-count" id="wfMatchCount"></span>
            </div>

        </div>
        <div class="card-body p-0 pt-1">
            <div class="table-responsive">
                <table id="wfTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                        <tr>
                            <th style="width:40px"></th>
                            <th>#</th>
                            <th><?= __('approval_name') ?></th>
                            <th><?= __('approval_description') ?></th>
                            <th><?= __('approval_stages') ?></th>
                            <th>تاريخ الإنشاء</th>
                            <th>العمليات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $n = 0; foreach ($workflows as $wf):
                        $wf_stages = $stagesByWf[$wf['id']] ?? [];
                        $sc        = count($wf_stages);
                        $n++;
                    ?>
                        <tr data-wf-id="<?= $wf['id'] ?>">
                            <!-- expand toggle -->
                            <td>
                                <?php if ($sc > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-expand"
                                        style="width:28px;height:28px;padding:0;border-radius:6px;"
                                        title="عرض المراحل">
                                    <i class="fas fa-chevron-down" style="font-size:.7rem"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                            <td><?= $n ?></td>
                            <td class="text-<?= $talign ?>">
                                <strong><?= htmlspecialchars($wf['name']) ?></strong>
                            </td>
                            <td class="text-<?= $talign ?> text-muted" style="font-size:.85rem;max-width:220px;">
                                <?= $wf['description'] ? htmlspecialchars(mb_substr($wf['description'],0,80)) . (mb_strlen($wf['description'])>80?'…':'') : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <span class="badge-stages">
                                    <i class="fas fa-list-ol <?= $ml1 ?>"></i><?= $sc ?>
                                </span>
                            </td>
                            <td style="font-size:.83rem;white-space:nowrap;">
                                <?= date('Y-m-d', strtotime($wf['created_at'])) ?>
                            </td>
                            <td>
                                <div class="btn-group-action">
                                    <?php if ($can_edit): ?>
                                    <button type="button"
                                            class="btn btn-warning btn-sm btn-action"
                                            onclick="openModal(<?= $wf['id'] ?>)"
                                            title="<?= __('edit') ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <button type="button"
                                            class="btn btn-danger btn-sm btn-action"
                                            onclick="deleteWorkflow(<?= $wf['id'] ?>,'<?= addslashes(htmlspecialchars($wf['name'])) ?>')"
                                            title="<?= __('delete') ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!$can_edit && !$can_delete): ?>
                                    <span class="text-muted" style="font-size:.8rem;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- table-responsive -->

            <?php if (empty($workflows)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-check-double fa-3x mb-3 d-block" style="opacity:.3"></i>
                <p class="mb-0"><?= __('approval_no_workflows') ?></p>
                <?php if ($can_add): ?>
                <button type="button" class="btn btn-primary btn-sm mt-3" onclick="openModal()">
                    <i class="fas fa-plus <?= $ml1 ?>"></i><?= __('approval_add') ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</section>
</div><!-- content-wrapper -->

<!-- ════════════════════════════════════════
     Modal: إضافة / تعديل سياسة اعتماد
════════════════════════════════════════ -->
<div class="modal fade" id="wfModal" tabindex="-1" role="dialog" aria-labelledby="wfModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" id="wfForm" novalidate>
                <input type="hidden" name="save_workflow" value="1">
                <input type="hidden" name="workflow_id"  id="modalWfId" value="0">

                <div class="modal-header" style="background:linear-gradient(135deg,#0d6efd,#0b5ed7);color:#fff;">
                    <h5 class="modal-title" id="wfModalTitle">
                        <i class="fas fa-plus-circle <?= $ml2 ?>"></i>
                        <span id="modalTitleText"><?= __('approval_add') ?></span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">

                    <!-- ── بيانات السياسة ── -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="wfName" class="font-weight-bold">
                                    <?= __('approval_name') ?> <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="wfName" name="name"
                                       class="form-control" required
                                       placeholder="<?= __('approval_name') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="wfDesc" class="font-weight-bold">
                                    <?= __('approval_description') ?>
                                </label>
                                <textarea id="wfDesc" name="description"
                                          class="form-control" rows="2"
                                          placeholder="<?= __('approval_description') ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <!-- ── مراحل الاعتماد ── -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="m-0 text-primary font-weight-bold">
                            <i class="fas fa-list-ol <?= $ml1 ?>"></i><?= __('approval_stages') ?>
                        </h6>
                        <button type="button" class="btn btn-outline-primary btn-sm"
                                onclick="addStageRow()">
                            <i class="fas fa-plus <?= $ml1 ?>"></i><?= __('approval_stage_add') ?>
                        </button>
                    </div>

                    <div id="stagesWrap"></div>

                    <p id="noStageHint" class="text-muted text-center small py-2 d-none">
                        اضغط "<?= __('approval_stage_add') ?>" لإضافة مراحل اعتماد.
                    </p>

                </div><!-- modal-body -->

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times <?= $ml1 ?>"></i><?= __('cancel') ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="modalSaveBtn">
                        <i class="fas fa-save <?= $ml1 ?>"></i><?= __('save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════
     Modal: تأكيد الحذف
════════════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
        <div class="modal-content">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="delete_workflow" value="1">
                <input type="hidden" name="workflow_id" id="deleteWfId">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle <?= $ml2 ?>"></i><?= __('delete') ?>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body text-center">
                    <p class="mb-1 font-weight-bold"><?= __('approval_confirm_delete') ?></p>
                    <p class="text-danger small mb-0" id="deleteWfName"></p>
                    <p class="text-muted small mt-2"><?= __('approval_confirm_delete_warn') ?></p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">
                        <i class="fas fa-times <?= $ml1 ?>"></i><?= __('cancel') ?>
                    </button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash <?= $ml1 ?>"></i><?= __('yes_delete') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════
     JSON بيانات
════════════════════════════════════════ -->
<script id="dataWorkflows"  type="application/json"><?= json_encode($workflows,     JSON_UNESCAPED_UNICODE) ?></script>
<script id="dataStages"     type="application/json"><?= json_encode($stagesByWf,   JSON_UNESCAPED_UNICODE) ?></script>
<script id="dataEmployees"  type="application/json"><?= json_encode($employees,    JSON_UNESCAPED_UNICODE) ?></script>

<!-- ════════════════════════════════════════
     Scripts
════════════════════════════════════════ -->
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
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
/* ════ بيانات PHP ════ */
var _wfWorkflows  = JSON.parse(document.getElementById('dataWorkflows').textContent  || '[]');
var _wfStagesByWf = JSON.parse(document.getElementById('dataStages').textContent     || '{}');
var _wfEmployees  = JSON.parse(document.getElementById('dataEmployees').textContent  || '[]');
var _wfStageIdx   = 0;
var _wfTable      = null;   /* مرجع DataTable */

/* ════ مساعدات ════ */
function _wfEmpOptions(selected) {
    var html = '<option value="">-- <?= addslashes(__('approval_select_employee')) ?> --</option>';
    _wfEmployees.forEach(function (e) {
        var s = (e.id == selected) ? ' selected' : '';
        var label = e.full_name;
        if (e.job_title) label += ' (' + e.job_title + ')';
        if (e.emp_code)  label += ' [' + e.emp_code  + ']';
        html += '<option value="' + e.id + '"' + s + '>' + label + '</option>';
    });
    return html;
}

function _wfAutoOrder() {
    document.querySelectorAll('#stagesWrap .stage-row').forEach(function (row, i) {
        var inp = row.querySelector('input[name="stage_order[]"]');
        if (inp) inp.value = i + 1;
    });
}

/* بناء HTML المراحل لـ DataTables child row */
function _wfBuildStagesHtml(wfId) {
    var stages = _wfStagesByWf[wfId] || [];
    if (!stages.length) {
        return '<div class="p-3 text-muted" style="font-size:.85rem;">لا توجد مراحل مضافة.</div>';
    }
    var html = '<div class="p-3"><p class="text-primary font-weight-bold mb-2" style="font-size:.88rem;">'
             + '<i class="fas fa-list-ol <?= $ml1 ?>"></i> <?= addslashes(__('approval_stages')) ?></p>'
             + '<ul class="tl-mini">';
    stages.forEach(function (s) {
        html += '<li><span class="dot">' + s.stage_order + '</span><span>';
        html += '<strong>' + (s.employee_name || '—') + '</strong>';
        if (s.stage_name) html += ' <span class="text-muted"> · ' + s.stage_name + '</span>';
        if (s.notes)      html += ' <span class="text-muted"> · ' + s.notes + '</span>';
        html += '</span></li>';
    });
    html += '</ul></div>';
    return html;
}

/* ════ دوال global (onclick) ════ */
function openModal(wfId) {
    wfId = wfId || 0;
    document.getElementById('stagesWrap').innerHTML = '';
    document.getElementById('noStageHint').classList.remove('d-none');
    document.getElementById('modalWfId').value = wfId;
    document.getElementById('wfName').value    = '';
    document.getElementById('wfDesc').value    = '';
    document.getElementById('wfName').classList.remove('is-invalid');

    var icon = document.getElementById('wfModalTitle').querySelector('i');

    if (wfId > 0) {
        document.getElementById('modalTitleText').textContent = '<?= addslashes(__('approval_edit')) ?>';
        icon.className = 'fas fa-edit <?= $ml2 ?>';
        var wf = null;
        for (var i = 0; i < _wfWorkflows.length; i++) {
            if (_wfWorkflows[i].id == wfId) { wf = _wfWorkflows[i]; break; }
        }
        if (wf) {
            document.getElementById('wfName').value = wf.name        || '';
            document.getElementById('wfDesc').value = wf.description || '';
        }
        var stages = _wfStagesByWf[wfId] || [];
        if (!stages.length) {
            addStageRow(1, '', '', '');
        } else {
            stages.forEach(function (s) {
                addStageRow(s.stage_order, s.stage_name, s.employee_id, s.notes);
            });
        }
    } else {
        document.getElementById('modalTitleText').textContent = '<?= addslashes(__('approval_add')) ?>';
        icon.className = 'fas fa-plus-circle <?= $ml2 ?>';
        addStageRow(1, '', '', '');
    }
    jQuery('#wfModal').modal('show');
}

function deleteWorkflow(wfId, wfName) {
    document.getElementById('deleteWfId').value         = wfId;
    document.getElementById('deleteWfName').textContent  = wfName;
    jQuery('#deleteModal').modal('show');
}

function addStageRow(order, name, empId, note) {
    _wfStageIdx++;
    var idx = _wfStageIdx;
    var html =
        '<div class="stage-row" id="sr_' + idx + '">' +
          '<input type="number" name="stage_order[]" min="1"' +
                 ' class="form-control form-control-sm" style="max-width:64px;text-align:center;"' +
                 ' placeholder="1" value="' + (order || '') + '" required>' +
          '<input type="text" name="stage_name[]"' +
                 ' class="form-control form-control-sm" style="max-width:140px;"' +
                 ' placeholder="<?= addslashes(__('approval_stage_name')) ?>"' +
                 ' value="' + (name || '') + '">' +
          '<select name="stage_employee[]"' +
                  ' class="form-control form-control-sm s2emp" style="min-width:190px;" required>' +
              _wfEmpOptions(empId) +
          '</select>' +
          '<input type="text" name="stage_notes[]"' +
                 ' class="form-control form-control-sm" style="max-width:130px;"' +
                 ' placeholder="<?= addslashes(__('approval_stage_notes')) ?>"' +
                 ' value="' + (note || '') + '">' +
          '<button type="button" class="btn-rm" onclick="removeStage(\'sr_' + idx + '\')">'+
              '<i class="fas fa-times-circle"></i>'+
          '</button>' +
        '</div>';

    document.getElementById('stagesWrap').insertAdjacentHTML('beforeend', html);
    jQuery('#sr_' + idx + ' .s2emp').select2({
        theme: 'bootstrap4',
        width: 'resolve',
        placeholder: '-- <?= addslashes(__('approval_select_employee')) ?> --',
        allowClear: true
    });
    document.getElementById('noStageHint').classList.add('d-none');
    _wfAutoOrder();
}

function removeStage(id) {
    var el = document.getElementById(id);
    if (el) el.remove();
    _wfAutoOrder();
    if (!document.querySelector('#stagesWrap .stage-row')) {
        document.getElementById('noStageHint').classList.remove('d-none');
    }
}

/* ════ jQuery ready ════ */
jQuery(function ($) {

    /* ── DataTable ── */
    if ($.fn.DataTable && $('#wfTable tbody tr').length) {
        try {
        _wfTable = $('#wfTable').DataTable({
            language: { emptyTable: 'لا توجد بيانات', info: 'عرض _START_ إلى _END_ من أصل _TOTAL_ سجل', infoEmpty: 'عرض 0 إلى 0 من أصل 0 سجل', infoFiltered: '(منتقاة من _MAX_ سجل إجمالي)', lengthMenu: 'عرض _MENU_ سجل في الصفحة', loadingRecords: 'جارٍ التحميل...', processing: 'جارٍ المعالجة...', search: 'بحث:', zeroRecords: 'لم يعثر على أية سجلات', paginate: { first: 'الأول', last: 'الأخير', next: 'التالي', previous: 'السابق' }, aria: { sortAscending: ': تفعيل لترتيب العمود تصاعدياً', sortDescending: ': تفعيل لترتيب العمود تنازلياً' } },
            pageLength: 10,
            order: [],
            columnDefs: [
                { orderable: false, targets: [0, 6] },
                { visible: false, targets: [0] }   // عمود التوسيع مخفي من الطباعة
            ],
            dom: "<'row mb-2'<'col-md-6'B><'col-md-6 text-left'f>>" +
                 "<'row'<'col-12'tr>>" +
                 "<'row mt-2'<'col-md-5'i><'col-md-7'p>>",
            buttons: [
                {
                    extend: 'print',
                    text: '<i class="fas fa-print ml-1"></i> طباعة',
                    className: 'btn btn-outline-primary btn-sm ml-1',
                    exportOptions: { columns: ':visible:not(:last-child)' },
                    customize: function(win) {
                        waslPrintSetup(win, 'سياسات الاعتماد والتوقيع');
                    }
                },
                {
                    extend: 'excelHtml5',
                    text: '<i class="fas fa-file-excel ml-1"></i> Excel',
                    className: 'btn btn-outline-success btn-sm ml-1',
                    exportOptions: { columns: ':visible:not(:last-child)' },
                    title: 'سياسات الاعتماد'
                },
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-columns ml-1"></i> أعمدة',
                    className: 'btn btn-outline-secondary btn-sm',
                    columns: ':not(:first-child):not(:last-child)'
                }
            ]
        });
        } catch(e) { _wfTable = null; console.warn('DataTables:', e.message); }
    }

    /* ── فلترة مخصصة ── */
    var _srchTimer;
    var TOTAL_WF = <?= count($workflows) ?>;

    function _updateWfCount() {
        var info = _wfTable ? _wfTable.page.info() : null;
        var el = document.getElementById('wfMatchCount');
        if (!info) { el.textContent = ''; return; }
        if (info.recordsDisplay < info.recordsTotal) {
            el.innerHTML = 'نتائج: <strong>' + info.recordsDisplay + '</strong> من ' + TOTAL_WF;
        } else {
            el.innerHTML = '<strong>' + TOTAL_WF + '</strong> سياسة';
        }
    }

    function _applyWfFilters() {
        if (!_wfTable) return;
        var q      = document.getElementById('wfSearch').value.toLowerCase().trim();
        var stages = document.getElementById('wfFilterStages').value;

        $.fn.dataTable.ext.search.splice(0, $.fn.dataTable.ext.search.length);

        $.fn.dataTable.ext.search.push(function(settings, data) {
            if (settings.nTable.id !== 'wfTable') return true;

            // بحث نصي (الاسم + الوصف)
            var name = (data[2] || '').toLowerCase();
            var desc = (data[3] || '').toLowerCase();
            if (q && !name.includes(q) && !desc.includes(q)) return false;

            // فلتر المراحل — col[4] يحتوي span بالعدد
            if (stages) {
                var stageText = (data[4] || '').replace(/<[^>]*>/g, '').trim();
                var stageNum  = parseInt(stageText) || 0;
                if (stages === 'has'   && stageNum < 1) return false;
                if (stages === 'none'  && stageNum > 0) return false;
                if (stages === '1'     && stageNum !== 1) return false;
                if (stages === '2plus' && stageNum < 2)  return false;
            }
            return true;
        });

        _wfTable.draw();
        _updateWfCount();
    }

    $('#wfSearch').on('input', function() {
        clearTimeout(_srchTimer);
        _srchTimer = setTimeout(_applyWfFilters, 250);
    });
    $('#wfFilterStages').on('change', _applyWfFilters);
    $('#wfResetFilters').on('click', function() {
        document.getElementById('wfSearch').value = '';
        document.getElementById('wfFilterStages').value = '';
        _applyWfFilters();
    });

    $('#wfTable').on('draw.dt', _updateWfCount);
    _updateWfCount();

    /* ── expand: DataTables child row ── */
    $('#wfTable tbody').on('click', '.btn-expand', function () {
        var $btn = $(this);
        var $tr  = $btn.closest('tr');
        var wfId = $tr.data('wf-id');
        var $icon = $btn.find('i');

        if (_wfTable) {
            var row = _wfTable.row($tr);
            if (row.child.isShown()) {
                row.child.hide();
                $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                row.child($(_wfBuildStagesHtml(wfId))).show();
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        }
    });

    /* ── تحقق النموذج ── */
    document.getElementById('wfForm').addEventListener('submit', function (e) {
        var name = document.getElementById('wfName').value.trim();
        if (!name) {
            e.preventDefault();
            document.getElementById('wfName').focus();
            document.getElementById('wfName').classList.add('is-invalid');
            return;
        }
        var btn = document.getElementById('modalSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin <?= $ml1 ?>"></i> <?= addslashes(__('save')) ?>...';
    });
    document.getElementById('wfName').addEventListener('input', function () {
        this.classList.remove('is-invalid');
    });

    /* إعادة تفعيل زر الحفظ إذا أُغلق المودال دون إرسال */
    $('#wfModal').on('hidden.bs.modal', function () {
        var btn = document.getElementById('modalSaveBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save <?= $ml1 ?>"></i> <?= addslashes(__('save')) ?>';
    });

    <?php if ($flash_ok): ?>
    setTimeout(function () { $('.alert-success').alert('close'); }, 4000);
    <?php endif; ?>
});
</script>

</div><!-- /.wrapper -->
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
