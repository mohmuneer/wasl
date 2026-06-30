<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/archive-structure.php";

if (!$current_user_id) { die(__('login_required')); }

try {
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

    // Handle delete
    if (isset($_GET['delete_id'])) {
        $did = (int)$_GET['delete_id'];
        $pdo->prepare("DELETE FROM " . TBL_EMPLOYEES . " WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'موظف', $did, [], ['id' => $did]);
        $_SESSION['success_message'] = getLang() === 'ar' ? 'تم حذف الموظف بنجاح' : 'Employee deleted successfully';
        header("Location: archive-structure.php");
        exit;
    }

    // Handle add
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
        $emp_code = trim($_POST['emp_code']);
        $full_name = trim($_POST['full_name']);
        $job_title = trim($_POST['job_title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $can_sign = isset($_POST['can_sign']) ? 1 : 0;
        $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO " . TBL_EMPLOYEES . " (emp_code, user_id, full_name, job_title, department, department_id, email, phone, can_sign) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$emp_code, $user_id, $full_name, $job_title ?: null, $department ?: null, $department_id, $email ?: null, $phone ?: null, $can_sign]);
        $new_id = $pdo->lastInsertId();
        log_action($pdo, 'create', 'موظف', $new_id, [], [
            'emp_code' => $emp_code, 'full_name' => $full_name, 'job_title' => $job_title,
            'department' => $department, 'department_id' => $department_id, 'email' => $email, 'phone' => $phone, 'can_sign' => $can_sign
        ]);
        $_SESSION['success_message'] = getLang() === 'ar' ? 'تم إضافة الموظف بنجاح' : 'Employee added successfully';
        header("Location: archive-structure.php");
        exit;
    }

    // Handle edit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_employee'])) {
        $id = (int)$_POST['id'];
        $emp_code = trim($_POST['emp_code']);
        $full_name = trim($_POST['full_name']);
        $job_title = trim($_POST['job_title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $can_sign = isset($_POST['can_sign']) ? 1 : 0;
        $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

        $stmt = $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET emp_code=?, user_id=?, full_name=?, job_title=?, department=?, department_id=?, email=?, phone=?, can_sign=? WHERE id=?");
        $stmt->execute([$emp_code, $user_id, $full_name, $job_title ?: null, $department ?: null, $department_id, $email ?: null, $phone ?: null, $can_sign, $id]);

        log_action($pdo, 'update', 'موظف', $id, [], [
            'emp_code' => $emp_code, 'full_name' => $full_name, 'job_title' => $job_title,
            'department' => $department, 'department_id' => $department_id, 'email' => $email, 'phone' => $phone, 'can_sign' => $can_sign
        ]);
        $_SESSION['success_message'] = getLang() === 'ar' ? 'تم تحديث بيانات الموظف بنجاح' : 'Employee updated successfully';
        header("Location: archive-structure.php");
        exit;
    }

    // Fetch hierarchy
    $branches = $pdo->query("SELECT * FROM " . TBL_BRANCHES . " ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);
    $regions = $pdo->query("SELECT * FROM " . TBL_REGIONS . " ORDER BY region_name")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT * FROM " . TBL_DEPARTMENTS . " ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

    // Build tree structure
    $regions_by_branch = [];
    foreach ($regions as $r) {
        $regions_by_branch[$r['branch_id']][] = $r;
    }
    $depts_by_region = [];
    foreach ($departments as $d) {
        $depts_by_region[$d['region_id']][] = $d;
    }

    // Fetch employees with department info
    $employees = $pdo->query("
        SELECT e.*, d.department_name, r.region_name, b.branch_name
        FROM " . TBL_EMPLOYEES . " e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN regions r ON d.region_id = r.id
        LEFT JOIN branches b ON r.branch_id = b.id
        ORDER BY e.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get distinct departments for filter
    $dept_list = $pdo->query("SELECT id, department_name FROM " . TBL_DEPARTMENTS . " ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

    $users_list = $pdo->query("SELECT id, full_name, email FROM sys_users WHERE status = 'active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $company_name = $settings['system_name'] ?? (getLang() === 'ar' ? 'اسم الشركة' : 'Company Name');
    $company_logo = $settings['system_logo'] ?? 'default-logo.png';
    $company_address = $settings['address'] ?? (getLang() === 'ar' ? 'العنوان' : 'Address');
    $base_assets_url = rtrim(dirname($_SERVER['SCRIPT_NAME'], 4), '/');

} catch (PDOException $e) {
    die(__('db_error') . ": " . $e->getMessage());
}

$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);
?>
<!DOCTYPE html>
<html lang="<?= getLang() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <title><?= __('archive_title') ?> - <?= __('dms_employees') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2-bootstrap4.min.css" rel="stylesheet" />
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
        .tree-container { background: #fff; border-radius: 8px; padding: 15px; border: 1px solid #dee2e6; max-height: calc(100vh - 250px); overflow-y: auto; }
        .tree-container::-webkit-scrollbar { width: 4px; }
        .tree-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 2px; }
        .tree-item { cursor: pointer; padding: 6px 10px; border-radius: 5px; margin-bottom: 2px; transition: all 0.15s; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
        .tree-item:hover { background: #f0f4ff; }
        .tree-item.active { background: var(--uni-primary); color: #fff; }
        .tree-item .icon { width: 20px; text-align: center; font-size: 0.85rem; }
        .tree-item .count { margin-<?= isRtl() ? 'left' : 'right' ?>: auto; background: #e9ecef; color: #495057; border-radius: 10px; padding: 0 8px; font-size: 0.75rem; line-height: 1.6; }
        .tree-item.active .count { background: rgba(255,255,255,0.25); color: #fff; }
        .tree-children { padding-<?= isRtl() ? 'right' : 'left' ?>: 25px; display: none; }
        .tree-children.open { display: block; }
        .tree-toggle { cursor: pointer; width: 16px; text-align: center; font-size: 0.7rem; color: #888; transition: transform 0.15s; }
        .tree-toggle.open { transform: rotate(90deg); }
        .emp-card { border-<?= isRtl() ? 'right' : 'left' ?>: 3px solid var(--uni-primary); padding: 10px 15px; margin-bottom: 8px; border-radius: 5px; background: #f8f9fa; }
        .emp-card .emp-name { font-weight: bold; }
        .emp-card .emp-detail { font-size: 0.85rem; color: #6c757d; }
        .structure-header { border-bottom: 2px solid #dee2e6; padding-bottom: 10px; margin-bottom: 15px; }
        #employeeList { min-height: 200px; }
        .badge-structure { font-size: 0.75rem; padding: 3px 8px; border-radius: 12px; }
        .filter-box { background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-top: 3px solid var(--uni-primary); }
        .node-path { color: #6c757d; font-size: 0.8rem; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        #employeesTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #employeesTable td { vertical-align: middle; text-align: center; }
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
                        <h4><i class="fas fa-sitemap ml-2"></i><?= __('org_structure') ?> <?= langSwitcher() ?></h4>
                        <small>الهيكل الإداري وموظفو التوقيع</small>
                    </div>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home"></i></a></li>
                        <li class="breadcrumb-item active"><?= __('archive_structure') ?></li>
                    </ol>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <!-- Tree Panel -->
                    <div class="col-md-4">
                        <div class="card card-ticket">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-tree ml-1"></i> <?= __('org_structure') ?></h5>
                                <div class="mr-auto">
                                    <button class="btn btn-sm btn-outline-secondary" title="<?= __('org_expand_all') ?>" onclick="$('.tree-children').addClass('open'); $('.tree-toggle').addClass('open');">
                                        <i class="fas fa-expand"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" title="<?= __('org_collapse_all') ?>" onclick="$('.tree-children').removeClass('open'); $('.tree-toggle').removeClass('open');">
                                        <i class="fas fa-compress"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <div class="tree-container">
                                    <?php if (empty($branches)): ?>
                                        <div class="text-muted text-center py-3"><?= __('org_no_branches') ?></div>
                                    <?php else: ?>
                                        <div id="orgTree">
                                            <?php foreach ($branches as $branch):
                                                $branchRegions = $regions_by_branch[$branch['id']] ?? [];
                                                $branchEmpCount = 0;
                                                foreach ($branchRegions as $br) {
                                                    $regionDepts = $depts_by_region[$br['id']] ?? [];
                                                    foreach ($regionDepts as $rd) {
                                                        foreach ($employees as $emp) {
                                                            if ($emp['department_id'] == $rd['id']) $branchEmpCount++;
                                                        }
                                                    }
                                                }
                                            ?>
                                            <div class="tree-item branch-item" data-type="branch" data-id="<?= $branch['id'] ?>">
                                                <?php if (!empty($branchRegions)): ?>
                                                    <span class="tree-toggle" onclick="event.stopPropagation(); toggleTree(this);"><i class="fas fa-chevron-right"></i></span>
                                                <?php else: ?>
                                                    <span class="tree-toggle" style="visibility:hidden;"><i class="fas fa-chevron-right"></i></span>
                                                <?php endif; ?>
                                                <span class="icon"><i class="fas fa-building" style="color:#e74c3c;"></i></span>
                                                <span><?= htmlspecialchars($branch['branch_name']) ?></span>
                                                <span class="count"><?= $branchEmpCount ?></span>
                                            </div>
                                            <div class="tree-children">
                                                <?php foreach ($branchRegions as $region):
                                                    $regionDepts = $depts_by_region[$region['id']] ?? [];
                                                    $regionEmpCount = 0;
                                                    foreach ($regionDepts as $rd) {
                                                        foreach ($employees as $emp) {
                                                            if ($emp['department_id'] == $rd['id']) $regionEmpCount++;
                                                        }
                                                    }
                                                ?>
                                                <div class="tree-item region-item" data-type="region" data-id="<?= $region['id'] ?>">
                                                    <?php if (!empty($regionDepts)): ?>
                                                        <span class="tree-toggle" onclick="event.stopPropagation(); toggleTree(this);"><i class="fas fa-chevron-right"></i></span>
                                                    <?php else: ?>
                                                        <span class="tree-toggle" style="visibility:hidden;"><i class="fas fa-chevron-right"></i></span>
                                                    <?php endif; ?>
                                                    <span class="icon"><i class="fas fa-map-marker-alt" style="color:#f39c12;"></i></span>
                                                    <span><?= htmlspecialchars($region['region_name']) ?></span>
                                                    <span class="count"><?= $regionEmpCount ?></span>
                                                </div>
                                                <div class="tree-children">
                                                    <?php foreach ($regionDepts as $dept):
                                                        $deptEmpCount = 0;
                                                        foreach ($employees as $emp) {
                                                            if ($emp['department_id'] == $dept['id']) $deptEmpCount++;
                                                        }
                                                    ?>
                                                    <div class="tree-item dept-item" data-type="dept" data-id="<?= $dept['id'] ?>" data-name="<?= htmlspecialchars($dept['department_name']) ?>" data-path="<?= htmlspecialchars($branch['branch_name'] . ' → ' . $region['region_name'] . ' → ' . $dept['department_name']) ?>">
                                                        <span class="tree-toggle" style="visibility:hidden;"><i class="fas fa-chevron-right"></i></span>
                                                        <span class="icon"><i class="fas fa-folder" style="color:#3498db;"></i></span>
                                                        <span><?= htmlspecialchars($dept['department_name']) ?></span>
                                                        <span class="count"><?= $deptEmpCount ?></span>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Employee List Panel -->
                    <div class="col-md-8">
                        <div class="card card-ticket">
                            <div class="card-header">
                                <h5 class="mb-0" id="panelTitle"><i class="fas fa-users ml-1"></i> <?= __('org_all_employees') ?></h5>
                                <div class="mr-auto">
                                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal" <?= $can_add ? '' : 'disabled' ?>>
                                        <i class="fas fa-plus ml-1"></i> <?= __('emp_add') ?>
                                    </button>
                                    <button class="btn btn-secondary btn-sm" onclick="$('#employeeSearch').val('').trigger('keyup');">
                                        <i class="fas fa-sync"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="filter-box">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-0">
                                                <input type="text" id="employeeSearch" class="form-control" placeholder="<?= __('org_search_emp') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <select id="filterDept" class="form-control">
                                                <option value=""><?= __('org_all_depts') ?></option>
                                                <?php foreach ($dept_list as $d): ?>
                                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <button id="resetFilterBtn" class="btn btn-secondary btn-block"><?= __('reset') ?></button>
                                        </div>
                                    </div>
                                    <div id="selectedPath" class="node-path mt-2" style="display:none;">
                                        <i class="fas fa-map-signs ml-1"></i> <span id="pathText"></span>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table id="employeesTable" class="table table-hover table-bordered text-center">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>#</th>
                                                <th><?= __('emp_code') ?></th>
                                                <th><?= __('emp_name') ?></th>
                                                <th><?= __('emp_job') ?></th>
                                                <th><?= __('emp_department') ?></th>
                                                <th><?= __('emp_email') ?></th>
                                                <th><?= __('emp_phone') ?></th>
                                                <th><?= __('actions') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="employeeTableBody">
                                            <?php $i = 1; foreach ($employees as $emp): ?>
                                            <tr data-dept-id="<?= $emp['department_id'] ?? '' ?>"
                                                data-emp-name="<?= htmlspecialchars($emp['full_name']) ?>"
                                                data-emp-code="<?= htmlspecialchars($emp['emp_code']) ?>">
                                                <td><?= $i++ ?></td>
                                                <td><strong><?= htmlspecialchars($emp['emp_code']) ?></strong></td>
                                                <td><?= htmlspecialchars($emp['full_name']) ?></td>
                                                <td><?= htmlspecialchars($emp['job_title'] ?? '-') ?></td>
                                                <td>
                                                    <?php if ($emp['department_name']): ?>
                                                        <span class="badge badge-info badge-structure"><?= htmlspecialchars($emp['department_name']) ?></span>
                                                        <small class="d-block node-path"><?= htmlspecialchars($emp['branch_name'] ?? '') ?> → <?= htmlspecialchars($emp['region_name'] ?? '') ?></small>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($emp['department'] ?? '-') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($emp['email'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($emp['phone'] ?? '-') ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <?php if ($can_edit): ?>
                                                            <button class="btn btn-warning btn-sm btn-action edit-btn"
                                                                    data-id="<?= $emp['id'] ?>"
                                                                    data-emp-code="<?= htmlspecialchars($emp['emp_code']) ?>"
                                                                    data-full-name="<?= htmlspecialchars($emp['full_name']) ?>"
                                                                    data-job-title="<?= htmlspecialchars($emp['job_title'] ?? '') ?>"
                                                                    data-department="<?= htmlspecialchars($emp['department'] ?? '') ?>"
                                                                    data-department-id="<?= $emp['department_id'] ?? '' ?>"
                                                                    data-email="<?= htmlspecialchars($emp['email'] ?? '') ?>"
                                                                    data-phone="<?= htmlspecialchars($emp['phone'] ?? '') ?>"
                                                                    data-can-sign="<?= $emp['can_sign'] ?>"
                                                                    data-user-id="<?= $emp['user_id'] ?? '' ?>"
                                                                    title="<?= __('edit') ?>"
                                                                    data-toggle="modal" data-target="#editModal">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($can_delete): ?>
                                                            <button class="btn btn-danger btn-sm btn-action" title="<?= __('delete') ?>" onclick="confirmDelete(<?= $emp['id'] ?>, '<?= htmlspecialchars(addslashes($emp['full_name']), ENT_QUOTES) ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><?= __('emp_add') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="add_employee" value="1">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_code') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="emp_code" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_name') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_job') ?></label>
                                <input type="text" name="job_title" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('org_structure_location') ?></label>
                                <select name="department_id" class="form-control select2-structure" id="addDeptSelect">
                                    <option value="">-- <?= __('org_select_path') ?> --</option>
                                    <?php foreach ($branches as $branch):
                                        $branchRegions = $regions_by_branch[$branch['id']] ?? [];
                                        foreach ($branchRegions as $region):
                                            $regionDepts = $depts_by_region[$region['id']] ?? [];
                                            foreach ($regionDepts as $dept): ?>
                                                <option value="<?= $dept['id'] ?>" data-path="<?= htmlspecialchars($branch['branch_name'] . ' → ' . $region['region_name'] . ' → ' . $dept['department_name']) ?>">
                                                    <?= htmlspecialchars($branch['branch_name'] . ' → ' . $region['region_name'] . ' → ' . $dept['department_name']) ?>
                                                </option>
                                            <?php endforeach;
                                        endforeach;
                                    endforeach; ?>
                                </select>
                                <input type="text" name="department" class="form-control mt-2" placeholder="<?= __('org_or_type_dept') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_email') ?></label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_phone') ?></label>
                                <input type="text" name="phone" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('linked_user') ?></label>
                                <select name="user_id" class="form-control select2-add">
                                    <option value="">-- <?= __('select_user') ?> --</option>
                                    <?php foreach ($users_list as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="add_can_sign" name="can_sign" value="1" checked>
                                    <label class="custom-control-label" for="add_can_sign"><?= __('signature_permission') ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-info"><?= __('save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><?= __('emp_edit') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="edit_employee" value="1">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_code') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="emp_code" id="edit_emp_code" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_name') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_job') ?></label>
                                <input type="text" name="job_title" id="edit_job_title" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('org_structure_location') ?></label>
                                <select name="department_id" id="edit_department_id" class="form-control select2-structure">
                                    <option value="">-- <?= __('org_select_path') ?> --</option>
                                    <?php foreach ($branches as $branch):
                                        $branchRegions = $regions_by_branch[$branch['id']] ?? [];
                                        foreach ($branchRegions as $region):
                                            $regionDepts = $depts_by_region[$region['id']] ?? [];
                                            foreach ($regionDepts as $dept): ?>
                                                <option value="<?= $dept['id'] ?>">
                                                    <?= htmlspecialchars($branch['branch_name'] . ' → ' . $region['region_name'] . ' → ' . $dept['department_name']) ?>
                                                </option>
                                            <?php endforeach;
                                        endforeach;
                                    endforeach; ?>
                                </select>
                                <input type="text" name="department" id="edit_department" class="form-control mt-2" placeholder="<?= __('org_or_type_dept') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_email') ?></label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('emp_phone') ?></label>
                                <input type="text" name="phone" id="edit_phone" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('linked_user') ?></label>
                                <select name="user_id" id="edit_user_id" class="form-control select2-edit">
                                    <option value="">-- <?= __('select_user') ?> --</option>
                                    <?php foreach ($users_list as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="edit_can_sign" name="can_sign" value="1">
                                    <label class="custom-control-label" for="edit_can_sign"><?= __('signature_permission') ?></label>
                                </div>
                            </div>
                        </div>
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

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
var currentLang = "<?= getLang() ?>";
var confirmDeleteItem = "<?= __('confirm_delete_item') ?>";
var yesDelete = "<?= __('yes_delete') ?>";
var cancelBtn = "<?= __('cancel_btn') ?>";

function toggleTree(el) {
    $(el).toggleClass('open');
    $(el).closest('.tree-item').next('.tree-children').toggleClass('open');
}

function confirmDelete(id, name) {
    Swal.fire({
        title: '<?= __('confirm_delete') ?>',
        text: confirmDeleteItem + ' "' + name + '"?',
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
}

$(document).ready(function() {
    // Initialize Select2
    $('.select2-structure').select2({
        theme: 'bootstrap4',
        placeholder: '-- <?= __('org_select_path') ?> --',
        allowClear: true,
        dropdownParent: $('#addModal'),
        dir: '<?= isRtl() ? "rtl" : "ltr" ?>'
    });
    $('.select2-add').select2({
        theme: 'bootstrap4',
        placeholder: '-- <?= __('select_user') ?> --',
        allowClear: true,
        dropdownParent: $('#addModal'),
        dir: '<?= isRtl() ? "rtl" : "ltr" ?>'
    });
    $('.select2-edit').select2({
        theme: 'bootstrap4',
        placeholder: '-- <?= __('select_user') ?> --',
        allowClear: true,
        dropdownParent: $('#editModal'),
        dir: '<?= isRtl() ? "rtl" : "ltr" ?>'
    });

    <?php if ($success_message): ?>
    Swal.fire({
        icon: 'success',
        title: '<span style="font-size:24px;"><?= __('success') ?></span>',
        html: '<div style="font-size:48px;color:#28a745;margin-bottom:8px;"><i class="fas fa-check-circle"></i></div><div style="font-size:18px;"><?= htmlspecialchars($success_message, ENT_QUOTES) ?></div>',
        confirmButtonText: 'موافق',
        confirmButtonColor: '#28a745',
        customClass: { confirmButton: 'btn btn-success px-4' },
        buttonsStyling: false,
        timer: 3000,
        timerProgressBar: true
    });
    <?php endif; ?>

    // Tree item click - filter employees
    $('.tree-item').on('click', function(e) {
        if ($(e.target).closest('.tree-toggle').length) return;
        $('.tree-item').removeClass('active');
        $(this).addClass('active');

        var type = $(this).data('type');
        var id = $(this).data('id');
        var name = $(this).data('name') || $(this).text().trim();
        var path = $(this).data('path') || '';

        // Update panel title
        $('#panelTitle').html('<i class="fas fa-users ml-1"></i> ' + name);

        // Update path display
        if (path) {
            $('#selectedPath').show();
            $('#pathText').text(path);
        } else {
            $('#selectedPath').hide();
        }

        // Filter rows
        $('#employeeTableBody tr').each(function() {
            var deptId = $(this).data('dept-id');
            var show = false;

            if (type === 'dept') {
                show = (deptId == id);
            } else if (type === 'region') {
                // Show employees in departments under this region
                <?php foreach ($depts_by_region as $regId => $depts): ?>
                if (<?= $regId ?> == id) {
                    if ($.inArray(parseInt(deptId), [<?= implode(',', array_column($depts, 'id')) ?>]) !== -1) show = true;
                }
                <?php endforeach; ?>
            } else if (type === 'branch') {
                <?php foreach ($regions_by_branch as $brId => $brRegions):
                    $regIds = array_column($brRegions, 'id');
                    $allDeptIds = [];
                    foreach ($regIds as $rid) {
                        if (isset($depts_by_region[$rid])) {
                            $allDeptIds = array_merge($allDeptIds, array_column($depts_by_region[$rid], 'id'));
                        }
                    }
                ?>
                if (<?= $brId ?> == id) {
                    if ($.inArray(parseInt(deptId), [<?= implode(',', $allDeptIds) ?>]) !== -1) show = true;
                }
                <?php endforeach; ?>
            }

            $(this).toggle(show);
        });

        // Clear any text search
        $('#employeeSearch').val('');
    });

    // Search employees
    $('#employeeSearch').on('keyup', function() {
        var val = $(this).val().toLowerCase();
        $('#employeeTableBody tr').each(function() {
            var name = $(this).data('emp-name').toLowerCase();
            var code = $(this).data('emp-code').toLowerCase();
            $(this).toggle(name.indexOf(val) > -1 || code.indexOf(val) > -1);
        });
    });

    // Filter by department dropdown
    $('#filterDept').on('change', function() {
        var val = $(this).val();
        if (val) {
            $('.tree-item').removeClass('active');
            $('#selectedPath').hide();
            $('#panelTitle').html('<i class="fas fa-users ml-1"></i> <?= __('org_all_employees') ?>');
        }
        $('#employeeTableBody tr').each(function() {
            var deptId = $(this).data('dept-id');
            if (!val) {
                $(this).show();
            } else {
                $(this).toggle(deptId == val);
            }
        });
        $('#employeeSearch').val('');
    });

    // Reset filter
    $('#resetFilterBtn').on('click', function() {
        $('#filterDept').val('');
        $('#employeeSearch').val('');
        $('#selectedPath').hide();
        $('.tree-item').removeClass('active');
        $('#panelTitle').html('<i class="fas fa-users ml-1"></i> <?= __('org_all_employees') ?>');
        $('#employeeTableBody tr').show();
    });

    // Edit button - populate modal
    $('.edit-btn').on('click', function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_emp_code').val($(this).data('emp-code'));
        $('#edit_full_name').val($(this).data('full-name'));
        $('#edit_job_title').val($(this).data('job-title'));
        $('#edit_department').val($(this).data('department'));
        $('#edit_department_id').val($(this).data('department-id')).trigger('change');
        $('#edit_email').val($(this).data('email'));
        $('#edit_phone').val($(this).data('phone'));
        $('#edit_user_id').val($(this).data('user-id')).trigger('change');
        $('#edit_can_sign').prop('checked', $(this).data('can-sign') == 1);
    });

    // Modal hidden - reset select2
    $('#addModal').on('hidden.bs.modal', function() {
        $(this).find('form')[0].reset();
        $(this).find('select').val('').trigger('change');
    });
});
</script>
</body>
</html>
