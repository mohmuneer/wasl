<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';
require __DIR__ . '/../../lang/init.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-employees.php";
if (!$current_user_id) die(__('login_required'));

try {
    // ── صلاحيات ────────────────────────────────────────────────────────
    $menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
    $menuStmt->execute([$page_path]);
    $current_page_id = $menuStmt->fetchColumn() ?? 0;

    $can_add = $can_edit = $can_delete = 0;
    if ($current_page_id > 0) {
        $accStmt = $pdo->prepare("SELECT can_add, can_edit, can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?");
        $accStmt->execute([$current_user_id, $current_page_id]);
        $p = $accStmt->fetch(PDO::FETCH_ASSOC);
        $can_add    = $p['can_add']    ?? 0;
        $can_edit   = $p['can_edit']   ?? 0;
        $can_delete = $p['can_delete'] ?? 0;
    }

    // ══════════════════════════════════════════════════════════════════
    // تاب 1 – موظفو DMS (dms_employees)
    // ══════════════════════════════════════════════════════════════════
    if (isset($_GET['toggle_id'])) {
        $tid = (int)$_GET['toggle_id'];
        $val = $pdo->prepare("SELECT is_active FROM " . TBL_EMPLOYEES . " WHERE id=?")->execute([$tid]);
        $val = $pdo->prepare("SELECT is_active FROM " . TBL_EMPLOYEES . " WHERE id=?")->execute([$tid]) ? $pdo->query("SELECT is_active FROM " . TBL_EMPLOYEES . " WHERE id=$tid")->fetchColumn() : 0;
        $new = $val ? 0 : 1;
        $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET is_active=? WHERE id=?")->execute([$new, $tid]);
        log_action($pdo, 'update', 'employee', $tid, ['is_active' => $val], ['is_active' => $new]);
        $_SESSION['success_message'] = $new ? __('emp_activated') : __('emp_deactivated');
        header("Location: show-employees.php#dms"); exit;
    }
    if (isset($_GET['toggle_sign_id'])) {
        $tid = (int)$_GET['toggle_sign_id'];
        $val = $pdo->query("SELECT can_sign FROM " . TBL_EMPLOYEES . " WHERE id=$tid")->fetchColumn();
        $new = $val ? 0 : 1;
        $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET can_sign=? WHERE id=?")->execute([$new, $tid]);
        log_action($pdo, 'update', 'employee', $tid, ['can_sign'=>$val], ['can_sign'=>$new]);
        $_SESSION['success_message'] = $new ? __('emp_sign_granted') : __('emp_sign_revoked');
        header("Location: show-employees.php#dms"); exit;
    }
    if (isset($_GET['delete_dms'])) {
        $did = (int)$_GET['delete_dms'];
        $pdo->prepare("DELETE FROM " . TBL_EMPLOYEES . " WHERE id=?")->execute([$did]);
        log_action($pdo, 'delete', 'employee', $did, [], []);
        $_SESSION['success_message'] = __('emp_deleted_success');
        header("Location: show-employees.php#dms"); exit;
    }

    // إضافة موظف DMS
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dms'])) {
        $emp_code = trim($_POST['emp_code']);
        $full_name = trim($_POST['full_name']);
        $job_title = trim($_POST['job_title'] ?? '');
        $dept_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $dept_name = $dept_id ? ($pdo->prepare("SELECT department_name FROM departments WHERE id=?") && ($s = $pdo->prepare("SELECT department_name FROM departments WHERE id=?")) && $s->execute([$dept_id]) ? $s->fetchColumn() : '') : '';
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $can_sign_val = isset($_POST['can_sign']) ? 1 : 0;
        $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO " . TBL_EMPLOYEES . " (emp_code, user_id, full_name, job_title, department, department_id, email, phone, can_sign) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$emp_code, $user_id, $full_name, $job_title ?: null, $dept_name ?: null, $dept_id, $email ?: null, $phone ?: null, $can_sign_val]);
        $new_id = (int)$pdo->lastInsertId();

        if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
            $uploadCheck = Security::validateUpload($_FILES['signature_image'], 'signature', 3);
            if ($uploadCheck['ok']) {
                $sig_dir = __DIR__ . '/../../uploads/signatures/';
                if (!is_dir($sig_dir)) mkdir($sig_dir, 0775, true);
                $sig_name = Security::safeFilename($_FILES['signature_image']['name'], 'emp_'.$new_id);
                move_uploaded_file($_FILES['signature_image']['tmp_name'], $sig_dir . $sig_name);
                $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET signature_image=? WHERE id=?")->execute(['signatures/' . $sig_name, $new_id]);
            }
        }
        log_action($pdo, 'create', 'employee', $new_id, [], ['emp_code'=>$emp_code,'full_name'=>$full_name]);
        $_SESSION['success_message'] = __('emp_added_success');
        header("Location: show-employees.php#dms"); exit;
    }

    // تعديل موظف DMS
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_dms'])) {
        $id = (int)$_POST['id'];
        $emp_code  = trim($_POST['emp_code']);
        $full_name = trim($_POST['full_name']);
        $job_title = trim($_POST['job_title'] ?? '');
        $dept_id   = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $s = $pdo->prepare("SELECT department_name FROM departments WHERE id=?"); $s->execute([$dept_id]); $dept_name = $s->fetchColumn() ?: '';
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $can_sign_val = isset($_POST['can_sign']) ? 1 : 0;
        $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

        $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET emp_code=?,user_id=?,full_name=?,job_title=?,department=?,department_id=?,email=?,phone=?,can_sign=? WHERE id=?")
            ->execute([$emp_code,$user_id,$full_name,$job_title?:null,$dept_name?:null,$dept_id,$email?:null,$phone?:null,$can_sign_val,$id]);

        if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
            $uploadCheck = Security::validateUpload($_FILES['signature_image'], 'signature', 3);
            if ($uploadCheck['ok']) {
                $sig_dir = __DIR__ . '/../../uploads/signatures/';
                if (!is_dir($sig_dir)) mkdir($sig_dir, 0775, true);
                $sig_name = Security::safeFilename($_FILES['signature_image']['name'], 'emp_'.$id);
                move_uploaded_file($_FILES['signature_image']['tmp_name'], $sig_dir . $sig_name);
                $pdo->prepare("UPDATE " . TBL_EMPLOYEES . " SET signature_image=? WHERE id=?")->execute(['signatures/'.$sig_name, $id]);
            }
        }
        log_action($pdo, 'update', 'employee', $id, [], ['emp_code'=>$emp_code,'full_name'=>$full_name]);
        $_SESSION['success_message'] = __('emp_updated_success');
        header("Location: show-employees.php#dms"); exit;
    }

    // ══════════════════════════════════════════════════════════════════
    // تاب 2 – مقدمو الطلبات (clients)
    // ══════════════════════════════════════════════════════════════════
    if (isset($_GET['delete_req'])) {
        $did = (int)$_GET['delete_req'];
        $pdo->prepare("DELETE FROM clients WHERE id=?")->execute([$did]);
        log_action($pdo, 'delete', 'requester', $did, [], []);
        $_SESSION['success_message'] = 'تم حذف الموظف بنجاح';
        header("Location: show-employees.php#req"); exit;
    }
    if (isset($_GET['toggle_req'])) {
        $tid = (int)$_GET['toggle_req'];
        $val = $pdo->query("SELECT status FROM clients WHERE id=$tid")->fetchColumn();
        $new = ($val === 'active') ? 'inactive' : 'active';
        $pdo->prepare("UPDATE clients SET status=? WHERE id=?")->execute([$new, $tid]);
        log_action($pdo, 'update', 'requester', $tid, ['status'=>$val], ['status'=>$new]);
        $_SESSION['success_message'] = ($new === 'active') ? 'تم تفعيل الموظف' : 'تم تعطيل الموظف';
        header("Location: show-employees.php#req"); exit;
    }

    // إضافة مقدم طلب
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_req'])) {
        $client_name     = trim($_POST['client_name']);
        $employee_number = trim($_POST['employee_number'] ?? '');
        $job_title_r     = trim($_POST['job_title'] ?? '');
        $client_type     = $_POST['client_type'] ?? 'technical';
        $dept_id         = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $phone           = trim($_POST['phone']);
        $email           = trim($_POST['email'] ?? '');
        $hire_date       = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $address         = trim($_POST['address'] ?? '');
        $hashed_pw       = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : password_hash(uniqid(), PASSWORD_DEFAULT);

        $pdo->prepare("INSERT INTO clients (client_name, employee_number, job_title, client_type, department_id, phone, email, hire_date, address, password, status) VALUES (?,?,?,?,?,?,?,?,?,?,'active')")
            ->execute([$client_name, $employee_number ?: null, $job_title_r ?: null, $client_type, $dept_id, $phone, $email ?: null, $hire_date, $address ?: null, $hashed_pw]);
        $new_id = (int)$pdo->lastInsertId();
        log_action($pdo, 'create', 'requester', $new_id, [], ['client_name'=>$client_name]);
        $_SESSION['success_message'] = 'تم إضافة الموظف بنجاح';
        header("Location: show-employees.php#req"); exit;
    }

    // تعديل مقدم طلب
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_req'])) {
        $id              = (int)$_POST['id'];
        $client_name     = trim($_POST['client_name']);
        $employee_number = trim($_POST['employee_number'] ?? '');
        $job_title_r     = trim($_POST['job_title'] ?? '');
        $client_type     = $_POST['client_type'] ?? 'technical';
        $dept_id         = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $phone           = trim($_POST['phone']);
        $email           = trim($_POST['email'] ?? '');
        $hire_date       = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $address         = trim($_POST['address'] ?? '');
        $status          = $_POST['status'] ?? 'active';

        if (!empty($_POST['password'])) {
            $pdo->prepare("UPDATE clients SET client_name=?,employee_number=?,job_title=?,client_type=?,department_id=?,phone=?,email=?,hire_date=?,address=?,status=?,password=? WHERE id=?")
                ->execute([$client_name,$employee_number?:null,$job_title_r?:null,$client_type,$dept_id,$phone,$email?:null,$hire_date,$address?:null,$status,password_hash($_POST['password'],PASSWORD_DEFAULT),$id]);
        } else {
            $pdo->prepare("UPDATE clients SET client_name=?,employee_number=?,job_title=?,client_type=?,department_id=?,phone=?,email=?,hire_date=?,address=?,status=? WHERE id=?")
                ->execute([$client_name,$employee_number?:null,$job_title_r?:null,$client_type,$dept_id,$phone,$email?:null,$hire_date,$address?:null,$status,$id]);
        }
        log_action($pdo, 'update', 'requester', $id, [], ['client_name'=>$client_name]);
        $_SESSION['success_message'] = 'تم تحديث بيانات الموظف بنجاح';
        header("Location: show-employees.php#req"); exit;
    }

    // ── جلب البيانات المشتركة ─────────────────────────────────────────
    $orgDepts = $pdo->query("SELECT MIN(id) AS id, department_name FROM departments GROUP BY department_name ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
    $allPositions = $pdo->query("SELECT department_id, job_title FROM " . TBL_JOB_POSITIONS . " WHERE is_active=1 AND job_title!='' ORDER BY job_title")->fetchAll(PDO::FETCH_ASSOC);
    $positionsByDept = [];
    foreach ($allPositions as $p) {
        $positionsByDept[(int)($p['department_id'] ?? 0)][] = $p['job_title'];
    }
    $posJson = json_encode($positionsByDept, JSON_UNESCAPED_UNICODE);
    $users_list = $pdo->query("SELECT id, full_name, email FROM sys_users WHERE status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    // ── DMS employees ─────────────────────────────────────────────────
    $employees = $pdo->query("SELECT e.*, u.full_name AS linked_user_name FROM " . TBL_EMPLOYEES . " e LEFT JOIN sys_users u ON e.user_id=u.id ORDER BY e.id DESC")->fetchAll(PDO::FETCH_ASSOC);

    // ── Requesters (clients) ──────────────────────────────────────────
    $requesters = $pdo->query("SELECT c.*, d.department_name FROM clients c LEFT JOIN departments d ON c.department_id=d.id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);

    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $company_name = $settings['system_name'] ?? 'الشركة';

} catch (PDOException $e) {
    die("خطأ: " . $e->getMessage());
}

$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (strpos($_SERVER['HTTP_REFERER'] ?? '', '#req') !== false ? 'req' : 'dms');

$typeLabels = ['technical'=>'تقني','administrative'=>'إداري','operational'=>'تشغيلي','management'=>'إدارة عليا'];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إدارة الموظفين</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2-bootstrap4.min.css" rel="stylesheet">
<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;text-align:right;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}
.emp-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;border:1px solid #f0f2f7;margin-bottom:22px}
.emp-card-head{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:1px solid #f0f2f7}
.emp-card-head h5{margin:0;font-weight:700;font-size:.95rem;color:#334155}
#empTable thead th{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))!important;color:#fff!important;border:none!important;white-space:nowrap;vertical-align:middle;font-size:.76rem;font-weight:700;padding:10px 8px;text-align:center}
#empTable tbody td{vertical-align:middle;text-align:center;font-size:.8rem;padding:9px 7px;border-top:1px solid #f0f4f8!important;border-left:none!important;border-right:none!important;border-bottom:none!important}
#empTable tbody tr:hover{background:#f8fafc}
#empTable tbody tr:first-child td{border-top:none!important}
.emp-stat{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:14px 16px;display:flex;align-items:center;gap:12px;border:1px solid #f0f2f7}
.emp-stat .si{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff;flex-shrink:0}
.emp-stat .sv{font-size:1.5rem;font-weight:800;line-height:1}
.emp-stat .sl{font-size:.72rem;color:#888;margin-top:2px}
.badge-pill-custom{padding:4px 12px;border-radius:20px;font-size:.7rem;font-weight:700}
.s-active{background:#d1fae5;color:#065f46}
.s-inactive{background:#f1f5f9;color:#64748b}
.s-sign-yes{background:#dbeafe;color:#1d4ed8}
.s-sign-no{background:#f1f5f9;color:#94a3b8}
.s-linked{background:#ede9fe;color:#5b21b6}
.btn-act{width:30px;height:30px;padding:0;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;transition:.15s;border:none;cursor:pointer}
.btn-act:hover{transform:scale(1.08)}
.modal-header-primary{background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff}
.modal-header-edit{background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff}
.f-label{font-size:.82rem;font-weight:700;color:#475569;margin-bottom:5px;display:block}
.f-req{color:#ef4444}
.emp-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 20px;background:#fafbfc;border-bottom:1px solid #f0f2f7}
.emp-filters select{border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 10px;font-size:.78rem;color:#475569;background:#fff}
.emp-filters select:focus{border-color:var(--crm-primary,#1a5276);outline:none}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-users ml-2"></i>إدارة الموظفين</h4>
                    <small>الكادر الوظيفي وربط الحسابات</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">الموظفون</li>
                </ol>
            </div>
        </div>
    </section>
    <section class="content">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3">
                <div class="emp-stat">
                    <div class="si" style="background:linear-gradient(135deg,#1a5276,#2980b9)"><i class="fas fa-users"></i></div>
                    <div><div class="sv"><?php echo count($employees); ?></div><div class="sl">إجمالي الموظفين</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="emp-stat">
                    <div class="si" style="background:linear-gradient(135deg,#065f46,#059669)"><i class="fas fa-user-check"></i></div>
                    <div><div class="sv"><?php echo count(array_filter($employees, fn($e)=>$e['is_active'])); ?></div><div class="sl">موظف نشط</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="emp-stat">
                    <div class="si" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)"><i class="fas fa-signature"></i></div>
                    <div><div class="sv"><?php echo count(array_filter($employees, fn($e)=>$e['can_sign'])); ?></div><div class="sl">يملك توقيع</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="emp-stat">
                    <div class="si" style="background:linear-gradient(135deg,#5b21b6,#7c3aed)"><i class="fas fa-link"></i></div>
                    <div><div class="sv"><?php echo count(array_filter($employees, fn($e)=>!empty($e['user_id']))); ?></div><div class="sl">مرتبط بحساب</div></div>
                </div>
            </div>
        </div>

        <div class="emp-card">
            <div class="emp-card-head">
                <h5><i class="fas fa-id-card ml-2 text-muted"></i>الكادر الوظيفي</h5>
                <?php if ($can_add): ?>
                <button type="button" class="btn btn-sm" data-toggle="modal" data-target="#addDmsModal"
                    style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:8px;padding:6px 14px;font-weight:700">
                    <i class="fas fa-plus ml-1"></i>إضافة موظف
                </button>
                <?php endif; ?>
            </div>
            <div class="emp-filters">
                <label style="font-size:.78rem;font-weight:700;color:#64748b;margin:0"><i class="fas fa-filter ml-1"></i>فلترة:</label>
                <select id="fDept">
                    <option value="">كل الأقسام</option>
                    <?php foreach ($orgDepts as $d): ?>
                    <option value="<?= htmlspecialchars($d['department_name']) ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fSign">
                    <option value="">كل الصلاحيات</option>
                    <option value="نعم">يملك توقيع</option>
                    <option value="لا">بدون توقيع</option>
                </select>
                <select id="fStatus">
                    <option value="">كل الحالات</option>
                    <option value="نشط">نشط</option>
                    <option value="موقف">موقف</option>
                </select>
                <select id="fLinked">
                    <option value="">كل الموظفين</option>
                    <option value="مرتبط">مرتبط بحساب</option>
                    <option value="غير مرتبط">غير مرتبط</option>
                </select>
                <button id="resetFilters" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.75rem">
                    <i class="fas fa-undo ml-1"></i>إعادة
                </button>
            </div>
            <div class="card-body p-0">
            <div class="table-responsive">
            <table id="empTable" class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>الكود</th><th>الاسم والمعلومات</th>
                        <th>القسم / المسمى</th><th>الهاتف</th>
                        <th>الحساب المرتبط</th><th>التوقيع</th><th>الحالة</th><th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i=1; foreach ($employees as $e):
                    $isLinked = !empty($e['user_id']);
                ?>
                <tr data-dept="<?= htmlspecialchars($e['department'] ?? '') ?>"
                    data-sign="<?= $e['can_sign'] ? 'نعم' : 'لا' ?>"
                    data-status="<?= $e['is_active'] ? 'نشط' : 'موقف' ?>"
                    data-linked="<?= $isLinked ? 'مرتبط' : 'غير مرتبط' ?>">
                    <td><strong class="text-muted"><?= $i++ ?></strong></td>
                    <td><span style="font-family:monospace;font-size:.75rem;background:#f1f5f9;padding:2px 7px;border-radius:5px;color:#334155"><?= htmlspecialchars($e['emp_code']) ?></span></td>
                    <td style="text-align:right">
                        <div style="font-weight:700;color:#1e293b;font-size:.82rem"><?= htmlspecialchars($e['full_name']) ?></div>
                        <?php if (!empty($e['email'])): ?><div style="font-size:.7rem;color:#94a3b8" dir="ltr"><?= htmlspecialchars($e['email']) ?></div><?php endif; ?>
                        <?php if (!empty($e['signature_image'])): ?>
                        <img src="../../uploads/<?= htmlspecialchars($e['signature_image']) ?>" alt="توقيع" style="max-height:28px;max-width:70px;border:1px solid #e2e8f0;border-radius:4px;margin-top:3px">
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:.78rem;font-weight:600;color:#334155"><?= htmlspecialchars($e['department'] ?? '—') ?></div>
                        <div style="font-size:.7rem;color:#94a3b8"><?= htmlspecialchars($e['job_title'] ?? '—') ?></div>
                    </td>
                    <td dir="ltr" style="font-size:.78rem"><?= htmlspecialchars($e['phone'] ?? '—') ?></td>
                    <td>
                        <?php if ($isLinked): ?>
                        <span class="badge-pill-custom s-linked"><i class="fas fa-link" style="font-size:.6rem;margin-left:4px"></i><?= htmlspecialchars($e['linked_user_name'] ?? 'مرتبط') ?></span>
                        <?php else: ?>
                        <span style="font-size:.72rem;color:#94a3b8"><i class="fas fa-unlink" style="font-size:.65rem"></i> غير مرتبط</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?toggle_sign_id=<?= $e['id'] ?>" class="badge-pill-custom <?= $e['can_sign'] ? 's-sign-yes' : 's-sign-no' ?>" style="text-decoration:none;cursor:pointer">
                            <?= $e['can_sign'] ? '✓ نعم' : '✗ لا' ?>
                        </a>
                    </td>
                    <td>
                        <a href="?toggle_id=<?= $e['id'] ?>" class="badge-pill-custom <?= $e['is_active'] ? 's-active' : 's-inactive' ?>" style="text-decoration:none;cursor:pointer">
                            <?= $e['is_active'] ? '● نشط' : '○ موقف' ?>
                        </a>
                    </td>
                    <td>
                        <div class="d-flex justify-content-center" style="gap:4px">
                            <?php if ($can_edit): ?>
                            <button type="button" class="btn-act" style="background:#fef3c7;color:#d97706" data-toggle="modal" data-target="#editDms<?= $e['id'] ?>" title="تعديل"><i class="fas fa-edit"></i></button>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <button type="button" class="btn-act" style="background:#fee2e2;color:#dc2626" onclick="confirmDeleteDms(<?= $e['id'] ?>, '<?= htmlspecialchars(addslashes($e['full_name'])) ?>')" title="حذف"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php /* DataTables يعرض رسالة الجدول الفارغ تلقائياً عبر language.emptyTable */ ?>
                </tbody>
            </table>
            </div>
            </div>
        </div>
    </div>
    </section>
</div>

<!-- مودال الإضافة -->
<div class="modal fade" id="addDmsModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header modal-header-primary">
        <h5 class="modal-title"><i class="fas fa-id-card ml-2"></i>إضافة موظف جديد</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= Security::field() ?>
        <input type="hidden" name="add_dms" value="1">
        <div class="modal-body">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="f-label">الكود <span class="f-req">*</span></label>
                    <input type="text" name="emp_code" class="form-control" placeholder="EMP-001" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">الاسم الكامل <span class="f-req">*</span></label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">القسم</label>
                    <select name="department_id" class="form-control dms-dept-add">
                        <option value="">-- اختر --</option>
                        <?php foreach ($orgDepts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="f-label">المسمى الوظيفي</label>
                    <select name="job_title" class="form-control dms-job-add"><option value="">-- اختر القسم أولاً --</option></select>
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">الهاتف</label>
                    <input type="text" name="phone" class="form-control">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="f-label"><i class="fas fa-link" style="color:#5b21b6"></i> ربط بمستخدم النظام</label>
                    <select name="user_id" class="form-control select2-dms-add">
                        <option value="">-- لا يوجد ربط --</option>
                        <?php foreach ($users_list as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> — <?= htmlspecialchars($u['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">الربط يمنح الموظف صلاحية تسجيل الدخول والتوقيع</small>
                </div>
                <div class="col-md-3 form-group">
                    <label class="f-label">صورة التوقيع</label>
                    <input type="file" name="signature_image" class="form-control-file" accept="image/*">
                </div>
                <div class="col-md-3 form-group d-flex flex-column justify-content-end">
                    <label class="f-label">صلاحية التوقيع</label>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="add_dms_sign" name="can_sign" value="1" checked>
                        <label class="custom-control-label" for="add_dms_sign">مفعّل</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="background:#fafbfc">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;border-radius:8px"><i class="fas fa-save ml-1"></i>حفظ الموظف</button>
        </div>
    </form>
</div></div></div>

<!-- مودالات التعديل -->
<?php foreach ($employees as $e): ?>
<div class="modal fade" id="editDms<?= $e['id'] ?>" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="border-radius:14px;overflow:hidden">
    <div class="modal-header modal-header-edit">
        <h5 class="modal-title"><i class="fas fa-edit ml-2"></i>تعديل: <?= htmlspecialchars($e['full_name']) ?></h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= Security::field() ?>
        <input type="hidden" name="edit_dms" value="1">
        <input type="hidden" name="id" value="<?= $e['id'] ?>">
        <div class="modal-body">
            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="f-label">الكود <span class="f-req">*</span></label>
                    <input type="text" name="emp_code" class="form-control" value="<?= htmlspecialchars($e['emp_code']) ?>" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">الاسم الكامل <span class="f-req">*</span></label>
                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($e['full_name']) ?>" required>
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">القسم</label>
                    <select name="department_id" class="form-control dms-dept-edit-<?= $e['id'] ?>">
                        <option value="">-- اختر --</option>
                        <?php foreach ($orgDepts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($e['department_id']??'') == $d['id'] ? 'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="f-label">المسمى الوظيفي</label>
                    <select name="job_title" class="form-control dms-job-edit-<?= $e['id'] ?>">
                        <option value="<?= htmlspecialchars($e['job_title']??'') ?>"><?= htmlspecialchars($e['job_title']??'-- اختر --') ?></option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($e['email']??'') ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label class="f-label">الهاتف</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($e['phone']??'') ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="f-label"><i class="fas fa-link" style="color:#5b21b6"></i> ربط بمستخدم النظام</label>
                    <select name="user_id" class="form-control select2-dms-edit-<?= $e['id'] ?>">
                        <option value="">-- لا يوجد ربط --</option>
                        <?php foreach ($users_list as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($e['user_id']??'') == $u['id'] ? 'selected':'' ?>><?= htmlspecialchars($u['full_name']) ?> — <?= htmlspecialchars($u['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 form-group">
                    <label class="f-label">صورة التوقيع</label>
                    <input type="file" name="signature_image" class="form-control-file" accept="image/*">
                    <?php if (!empty($e['signature_image'])): ?>
                    <img src="../../uploads/<?= htmlspecialchars($e['signature_image']) ?>" style="max-height:36px;margin-top:5px;border:1px solid #e2e8f0;border-radius:4px">
                    <?php endif; ?>
                </div>
                <div class="col-md-3 form-group d-flex flex-column justify-content-end">
                    <label class="f-label">صلاحية التوقيع</label>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="edit_sign_<?= $e['id'] ?>" name="can_sign" value="1" <?= $e['can_sign']?'checked':'' ?>>
                        <label class="custom-control-label" for="edit_sign_<?= $e['id'] ?>"><?= $e['can_sign']?'مفعّل':'موقف' ?></label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="background:#fafbfc">
            <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
            <button type="submit" class="btn" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border-radius:8px"><i class="fas fa-save ml-1"></i>حفظ التعديلات</button>
        </div>
    </form>
</div></div></div>
<?php endforeach; ?>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../dist/js/adminlte.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
var positionsByDept = <?php echo $posJson; ?>;
function fillJobs($jobSel, deptId, selectedVal) {
    $jobSel.empty().append('<option value="">-- اختر --</option>');
    (positionsByDept[deptId] || []).forEach(function(j) {
        $jobSel.append('<option value="' + j + '"' + (j === selectedVal ? ' selected' : '') + '>' + j + '</option>');
    });
    if ($jobSel.hasClass('select2-hidden-accessible')) $jobSel.trigger('change.select2');
}
$(document).ready(function() {
    /* ── DataTable ── */
    var table = $('#empTable').DataTable({
        responsive:false, scrollX:true, autoWidth:false, order:[[0,'asc']],
        dom:"<'row mb-2'<'col-md-6'B><'col-md-6 text-left'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
        buttons:[
            {extend:'excelHtml5',text:'<i class="fas fa-file-excel ml-1"></i>إكسل',className:'btn btn-sm btn-outline-success',exportOptions:{columns:[0,1,2,3,4,5,6,7]}},
            {extend:'print',text:'<i class="fas fa-print ml-1"></i>طباعة',className:'btn btn-sm btn-outline-primary',exportOptions:{columns:[0,1,2,3,4,5,6,7]},customize:function(win){$(win.document.body).css({direction:'rtl','text-align':'right','font-family':'Cairo,sans-serif'})}},
            {extend:'colvis',text:'<i class="fas fa-columns ml-1"></i>الأعمدة',className:'btn btn-sm btn-outline-secondary'}
        ],
        language:{search:'بحث شامل:',lengthMenu:'عرض _MENU_',info:'_START_–_END_ من _TOTAL_',paginate:{next:'التالي',previous:'السابق'},emptyTable:'لا يوجد موظفون'},
        columnDefs:[{orderable:false,targets:[7,8]}]
    });
    /* ── فلاتر ── */
    $.fn.dataTable.ext.search.push(function(settings,data,dataIndex){
        if(settings.nTable.id!=='empTable')return true;
        var row=settings.aoData[dataIndex].nTr; if(!row)return true;
        var fD=$('#fDept').val(),fS=$('#fSign').val(),fSt=$('#fStatus').val(),fL=$('#fLinked').val();
        if(fD&&$(row).data('dept')!==fD)return false;
        if(fS&&$(row).data('sign')!==fS)return false;
        if(fSt&&$(row).data('status')!==fSt)return false;
        if(fL&&$(row).data('linked')!==fL)return false;
        return true;
    });
    $('#fDept,#fSign,#fStatus,#fLinked').on('change',function(){table.draw();});
    $('#resetFilters').on('click',function(){$('#fDept,#fSign,#fStatus,#fLinked').val('');table.draw();});
    /* ── Select2 مودال الإضافة ── */
    $('#addDmsModal').on('shown.bs.modal',function(){
        var $s=$(this).find('.select2-dms-add');
        if(!$s.hasClass('select2-hidden-accessible'))$s.select2({theme:'bootstrap4',dropdownParent:$(this),dir:'rtl',allowClear:true,placeholder:'-- لا يوجد ربط --'});
        $(this).find('.dms-dept-add').off('change.cascade').on('change.cascade',function(){fillJobs($('#addDmsModal .dms-job-add'),$(this).val(),'');});
    }).on('hide.bs.modal',function(){
        var $s=$(this).find('.select2-dms-add');
        if($s.hasClass('select2-hidden-accessible'))$s.select2('destroy');
    });
    /* ── Select2 مودالات التعديل ── */
    <?php foreach ($employees as $e): ?>
    $('#editDms<?= $e['id'] ?>').on('shown.bs.modal',function(){
        var $s=$(this).find('.select2-dms-edit-<?= $e['id'] ?>');
        if(!$s.hasClass('select2-hidden-accessible'))$s.select2({theme:'bootstrap4',dropdownParent:$(this),dir:'rtl',allowClear:true,placeholder:'-- لا يوجد ربط --'});
        var $d=$(this).find('.dms-dept-edit-<?= $e['id'] ?>');
        if($d.val())fillJobs($(this).find('.dms-job-edit-<?= $e['id'] ?>'),$d.val(),'<?= addslashes($e['job_title']??'') ?>');
        $d.off('change.cascade').on('change.cascade',function(){fillJobs($('#editDms<?= $e['id'] ?> .dms-job-edit-<?= $e['id'] ?>'),$d.val(),'');});
    }).on('hide.bs.modal',function(){
        var $s=$(this).find('.select2-dms-edit-<?= $e['id'] ?>');
        if($s.hasClass('select2-hidden-accessible'))$s.select2('destroy');
    });
    <?php endforeach; ?>
    /* ── open via URL ── */
    if(new URLSearchParams(window.location.search).get('open_dms')==='1')setTimeout(function(){$('#addDmsModal').modal('show');},400);
    /* ── رسالة نجاح ── */
    <?php if ($success_message): ?>
    Swal.fire({icon:'success',title:'تمت العملية',text:<?= json_encode($success_message) ?>,timer:2500,showConfirmButton:false,timerProgressBar:true});
    <?php endif; ?>
});
function confirmDeleteDms(id,name){
    Swal.fire({title:'تأكيد الحذف',text:'حذف الموظف "'+name+'"؟',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'نعم، احذف',cancelButtonText:'إلغاء'})
    .then(r=>{if(r.isConfirmed)window.location.href='?delete_dms='+id;});
}
</script>
<?php include __DIR__ . '/../../print_header.php'; ?>
</body>
</html>
