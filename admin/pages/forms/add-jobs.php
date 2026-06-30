<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-jobs.php";

if (!$current_user_id) { die(__('login_required')); }

$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);
$current_page_id = $menu_item['id'] ?? 0;

$can_add = 0; $can_edit = 0; $can_delete = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    $can_add = $permissions['can_add'] ?? 0;
    $can_edit = $permissions['can_edit'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
}

// جلب قائمة الفروع والمناطق
$allBranches = $pdo->query("SELECT * FROM " . TBL_BRANCHES . " ORDER BY branch_name")->fetchAll();
$allRegions = $pdo->query("SELECT * FROM " . TBL_REGIONS . " ORDER BY region_name")->fetchAll();
$regionsByBranch = [];
foreach ($allRegions as $r) { $regionsByBranch[$r['branch_id']][] = $r; }

// جلب الأقسام مع أسماء الفروع والمناطق
$departments = $pdo->query("
    SELECT d.*, r.region_name, b.branch_name
    FROM " . TBL_DEPARTMENTS . " d
    LEFT JOIN regions r ON d.region_id = r.id
    LEFT JOIN branches b ON r.branch_id = b.id
    ORDER BY d.id ASC
")->fetchAll();

// جلب الوظائف لكل قسم
$allJobs = $pdo->query("
    SELECT jp.*, d.department_name
    FROM " . TBL_JOB_POSITIONS . " jp
    LEFT JOIN departments d ON jp.department_id = d.id
    ORDER BY jp.id ASC
")->fetchAll();

$jobsByDept = [];
foreach ($allJobs as $j) { $jobsByDept[$j['department_id']][] = $j; }

// رسائل النجاح
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <title><?= __('jobs_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2-bootstrap4.min.css" rel="stylesheet" />
    <style>
        html, body { overflow-x: hidden !important; scrollbar-width: none !important; -ms-overflow-style: none !important; }
        ::-webkit-scrollbar { display: none !important; }
        body { direction: <?= isRtl() ? 'rtl' : 'ltr' ?>; text-align: <?= isRtl() ? 'right' : 'left' ?>; }
        .job-card { border-<?= isRtl() ? 'right' : 'left' ?>: 3px solid #28a745; padding: 10px 15px; margin-bottom: 8px; border-radius: 5px; background: #f8f9fa; transition: all 0.2s; }
        .job-card:hover { background: #e8f5e9; }
        .job-card .job-title { font-weight: bold; font-size: 1rem; }
        .job-card .job-desc { font-size: 0.85rem; color: #6c757d; }
        .section-divider { border-top: 2px dashed #dee2e6; margin: 20px 0; }
        .badge-job-count { background: #28a745; color: #fff; border-radius: 12px; padding: 2px 10px; font-size: 0.75rem; }
        .dept-row { transition: all 0.2s; }
        .dept-row:hover { background: #f0f4ff !important; }
        .job-modal-list { max-height: 400px; overflow-y: auto; }
        .detail-header { background: #f8f9fa; padding: 10px 15px; border-radius: 5px; margin-bottom: 15px; border-<?= isRtl() ? 'right' : 'left' ?>: 3px solid #ffc107; }
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
                        <h4><i class="fas fa-briefcase ml-2"></i><?= __('jobs_title') ?> <?= langSwitcher() ?></h4>
                        <small>إدارة الأقسام والمسميات الوظيفية</small>
                    </div>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                        <li class="breadcrumb-item active"><?= __('jobs_title') ?></li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <!-- Add Department Card -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title" style="float: <?= isRtl() ? 'right' : 'left' ?>;">
                            <i class="fas fa-plus-circle ml-1"></i> <?= __('jobs_add_dept') ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="edit-jobs.php">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('jobs_branch') ?> <span class="text-danger">*</span></label>
                                        <select name="branch_id" id="addBranch" class="form-control" required>
                                            <option value="">-- <?= __('jobs_select_branch_first') ?> --</option>
                                            <?php foreach ($allBranches as $b): ?>
                                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('jobs_region') ?> <span class="text-danger">*</span></label>
                                        <select name="region_id" id="addRegion" class="form-control" required>
                                            <option value="">-- <?= __('jobs_select_branch_first') ?> --</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label><?= __('jobs_dept_name') ?> <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" name="department_name" class="form-control" placeholder="<?= __('jobs_dept_name') ?>" required>
                                            <input type="hidden" name="action" value="add_department">
                                            <div class="input-group-append">
                                                <?php if ($can_add): ?>
                                                    <button type="submit" class="btn btn-primary px-4">
                                                        <i class="fas fa-save"></i> <?= __('save') ?>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-secondary disabled">
                                                        <i class="fas fa-save"></i> <?= __('save') ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Departments & Jobs List -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title" style="float: <?= isRtl() ? 'right' : 'left' ?>;">
                            <i class="fas fa-list ml-1"></i> <?= __('jobs_title') ?>
                        </h3>
                        <div class="card-tools">
                            <div class="input-group input-group-sm" style="width: 200px;">
                                <input type="text" id="tableSearch" class="form-control" placeholder="<?= __('search') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover text-center m-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:5%">#</th>
                                        <th><?= __('jobs_dept_name') ?></th>
                                        <th><?= __('jobs_region') ?></th>
                                        <th><?= __('jobs_branch') ?></th>
                                        <th style="width:10%"><?= __('jobs_jobs_count') ?></th>
                                        <th style="width:18%"><?= __('actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="deptTableBody">
                                    <?php $counter = 1; foreach ($departments as $dept):
                                        $deptJobs = $jobsByDept[$dept['id']] ?? [];
                                        $jobCount = count($deptJobs);
                                    ?>
                                    <tr class="dept-row" data-dept-name="<?= htmlspecialchars($dept['department_name']) ?>">
                                        <td><?= $counter++ ?></td>
                                        <td class="text-<?= isRtl() ? 'right' : 'left' ?>">
                                            <strong><?= htmlspecialchars($dept['department_name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($dept['region_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($dept['branch_name'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-job-count"><?= $jobCount ?></span>
                                        </td>
                                        <td class="py-2">
                                            <div class="btn-group">
                                                <!-- Details / Jobs -->
                                                <button class="btn btn-sm btn-success show-jobs-btn"
                                                        data-id="<?= $dept['id'] ?>"
                                                        data-name="<?= htmlspecialchars($dept['department_name']) ?>"
                                                        data-toggle="modal" data-target="#jobsModal"
                                                        title="<?= __('jobs_dept_jobs') ?>">
                                                    <i class="fas fa-briefcase"></i>
                                                </button>
                                                <!-- Edit Department -->
                                                <?php if ($can_edit): ?>
                                                <button class="btn btn-sm btn-warning edit-dept-btn"
                                                        data-id="<?= $dept['id'] ?>"
                                                        data-name="<?= htmlspecialchars($dept['department_name']) ?>"
                                                        data-region="<?= $dept['region_id'] ?>"
                                                        data-toggle="modal" data-target="#editDeptModal">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <!-- Delete Department -->
                                                <?php if ($can_delete): ?>
                                                <button class="btn btn-sm btn-danger delete-dept-btn"
                                                        data-id="<?= $dept['id'] ?>"
                                                        data-name="<?= htmlspecialchars($dept['department_name']) ?>"
                                                        data-toggle="modal" data-target="#deleteDeptModal">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($departments)): ?>
                                    <tr><td colspan="6" class="text-muted py-3"><?= __('jobs_no_depts') ?></td></tr>
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

<!-- Jobs Modal (Detail) -->
<div class="modal fade" id="jobsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-briefcase ml-1"></i>
                    <?= __('jobs_dept_jobs') ?>: <span id="jobsDeptName"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="jobsDeptId">

                <!-- Add Job Form -->
                <div class="detail-header">
                    <form method="POST" action="edit-jobs.php" class="row align-items-end">
                        <input type="hidden" name="department_id" id="addJobDeptId" value="">
                        <input type="hidden" name="action" value="add_job">
                        <div class="col-md-5">
                            <div class="form-group mb-0">
                                <label class="small"><?= __('jobs_job_title') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="job_title" class="form-control form-control-sm" required placeholder="<?= __('jobs_job_title') ?>">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group mb-0">
                                <label class="small"><?= __('jobs_job_desc') ?></label>
                                <input type="text" name="job_description" class="form-control form-control-sm" placeholder="<?= __('jobs_job_desc') ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <?php if ($can_add): ?>
                                <button type="submit" class="btn btn-success btn-sm btn-block">
                                    <i class="fas fa-plus"></i> <?= __('add') ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm btn-block disabled"><?= __('add') ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Jobs List -->
                <div id="jobsListContainer" class="job-modal-list">
                    <div class="text-muted text-center py-4">
                        <i class="fas fa-spinner fa-spin"></i> <?= __('jobs_dept_jobs') ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDeptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><?= __('jobs_dept_name') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="edit-jobs.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_department">
                    <input type="hidden" name="department_id" id="editDeptId">
                    <div class="form-group">
                        <label><?= __('jobs_dept_name') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="department_name" id="editDeptName" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><?= __('update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Department Modal -->
<div class="modal fade" id="deleteDeptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><?= __('delete') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="edit-jobs.php">
                <div class="modal-body">
                    <p><?= __('jobs_confirm_delete_dept') ?>: <strong id="delDeptName"></strong>?</p>
                    <p class="text-danger small"><?= __('jobs_confirm_delete_dept') ?></p>
                    <input type="hidden" name="department_id" id="delDeptId">
                    <input type="hidden" name="action" value="delete_department">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-danger"><?= __('yes_delete') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Job Modal -->
<div class="modal fade" id="editJobModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><?= __('jobs_edit_job') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="edit-jobs.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_job">
                    <input type="hidden" name="job_id" id="editJobId">
                    <div class="form-group">
                        <label><?= __('jobs_job_title') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="job_title" id="editJobTitle" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label><?= __('jobs_job_desc') ?></label>
                        <textarea name="job_description" id="editJobDesc" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><?= __('update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Job Modal -->
<div class="modal fade" id="deleteJobModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><?= __('jobs_delete_job') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="edit-jobs.php">
                <div class="modal-body">
                    <p><?= __('jobs_delete_job') ?>: <strong id="delJobTitle"></strong>?</p>
                    <input type="hidden" name="job_id" id="delJobId">
                    <input type="hidden" name="action" value="delete_job">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-danger"><?= __('yes_delete') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
var allJobs = <?= json_encode($allJobs) ?>;
var currentLang = "<?= getLang() ?>";

$(document).ready(function() {
    // Branch â†’ Region cascading
    var regionsData = <?= json_encode($regionsByBranch) ?>;

    $('#addBranch').on('change', function() {
        var branchId = $(this).val();
        var $region = $('#addRegion');
        $region.empty().append('<option value="">-- <?= __('jobs_region') ?> --</option>');
        if (branchId && regionsData[branchId]) {
            $.each(regionsData[branchId], function(i, r) {
                $region.append($('<option>', { value: r.id, text: r.region_name }));
            });
        }
    });

    // Table search
    $('#tableSearch').on('keyup', function() {
        var val = $(this).val().toLowerCase();
        $('#deptTableBody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
        });
    });

    // Show jobs modal
    $('.show-jobs-btn').on('click', function() {
        var deptId = $(this).data('id');
        var deptName = $(this).data('name');
        $('#jobsDeptId').val(deptId);
        $('#addJobDeptId').val(deptId);
        $('#jobsDeptName').text(deptName);

        var jobs = allJobs.filter(function(j) { return parseInt(j.department_id) === parseInt(deptId); });
        var $container = $('#jobsListContainer');

        if (jobs.length === 0) {
            $container.html('<div class="text-muted text-center py-4"><i class="fas fa-info-circle ml-1"></i> <?= __('jobs_no_jobs') ?></div>');
        } else {
            var html = '';
            $.each(jobs, function(i, job) {
                var desc = job.job_description || '-';
                var activeBadge = job.is_active == 1
                    ? '<span class="badge badge-success"><?= __('active') ?></span>'
                    : '<span class="badge badge-secondary"><?= __('inactive') ?></span>';
                html += '<div class="job-card">'
                    + '<div class="d-flex justify-content-between align-items-start">'
                    + '<div>'
                    + '<div class="job-title">' + $('<span>').text(job.job_title).html() + ' ' + activeBadge + '</div>'
                    + '<div class="job-desc">' + $('<span>').text(desc).html() + '</div>'
                    + '</div>'
                    + '<div class="btn-group">'
                    + '<button class="btn btn-xs btn-warning edit-job-btn" data-id="' + job.id + '" data-title="' + $('<span>').text(job.job_title).html() + '" data-desc="' + $('<span>').text(job.job_description || '').html() + '" data-toggle="modal" data-target="#editJobModal"><i class="fas fa-edit"></i></button>'
                    + '<button class="btn btn-xs btn-danger delete-job-btn" data-id="' + job.id + '" data-title="' + $('<span>').text(job.job_title).html() + '" data-toggle="modal" data-target="#deleteJobModal"><i class="fas fa-trash"></i></button>'
                    + '</div>'
                    + '</div>'
                    + '</div>';
            });
            $container.html(html);
        }
    });

    // Edit job button
    $(document).on('click', '.edit-job-btn', function() {
        $('#editJobId').val($(this).data('id'));
        $('#editJobTitle').val($(this).data('title'));
        $('#editJobDesc').val($(this).data('desc'));
    });

    // Delete job button
    $(document).on('click', '.delete-job-btn', function() {
        $('#delJobId').val($(this).data('id'));
        $('#delJobTitle').text($(this).data('title'));
    });

    // Edit department button
    $('.edit-dept-btn').on('click', function() {
        $('#editDeptId').val($(this).data('id'));
        $('#editDeptName').val($(this).data('name'));
    });

    // Delete department button
    $('.delete-dept-btn').on('click', function() {
        $('#delDeptId').val($(this).data('id'));
        $('#delDeptName').text($(this).data('name'));
    });

    // Success message from sessionStorage (redirect from edit-jobs.php)
    var swalIcon = sessionStorage.getItem('swal_icon');
    var swalTitle = sessionStorage.getItem('swal_title');
    var swalText = sessionStorage.getItem('swal_text');
    if (swalText) {
        Swal.fire({
            icon: swalIcon || 'success',
            title: swalTitle || '<?= __('success') ?>',
            text: swalText,
            confirmButtonText: 'موافق',
            timer: 3000,
            timerProgressBar: true
        });
        sessionStorage.removeItem('swal_icon');
        sessionStorage.removeItem('swal_title');
        sessionStorage.removeItem('swal_text');
    }

    <?php if ($success_message): ?>
    Swal.fire({
        icon: 'success',
        title: '<?= __('success') ?>',
        text: '<?= htmlspecialchars($success_message, ENT_QUOTES) ?>',
        confirmButtonText: 'موافق',
        timer: 3000,
        timerProgressBar: true
    });
    <?php endif; ?>
});
</script>
</body>
</html>
