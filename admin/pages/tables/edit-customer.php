<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$customer_id = $_GET['id'] ?? null;

if (!$current_user_id || !$customer_id) {
    die("خطأ: وصول غير مصرح به");
}

// 1. التحقق من صلاحية التعديل
$page_path = "pages/tables/edit-customer.php"; 
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn();

if ($current_page_id) {
    $accessSql = "SELECT can_edit FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $can_edit = $accessStmt->fetchColumn();

    if (!$can_edit) {
        die("عذراً، لا تملك صلاحية التعديل على هذه البيانات.");
    }
}

// 2. جلب بيانات العميل الحالية
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("العميل غير موجود");
}

$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll(PDO::FETCH_ASSOC);
$regions = $pdo->query("SELECT id, region_name FROM regions")->fetchAll(PDO::FETCH_ASSOC);

// 3. معالجة تحديث البيانات
if (isset($_POST['update_customer'])) {
    $name = trim($_POST['customer_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $branch_id = $_POST['branch_id'];
    $lab_id = $_POST['lab_id'];
    $password = $_POST['password'];
    $status = $_POST['status']; // استقبال القيمة كنص (active/inactive)

    try {
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE clients SET client_name=?, phone=?, email=?, password=?, address=?, location_id=?, department_id=?, status=? WHERE id=?";
            $params = [$name, $phone, $email, $hashed_password, $address, $branch_id, $lab_id, $status, $customer_id];
        } else {
            $sql = "UPDATE clients SET client_name=?, phone=?, email=?, address=?, location_id=?, department_id=?, status=? WHERE id=?";
            $params = [$name, $phone, $email, $address, $branch_id, $lab_id, $status, $customer_id];
        }

        $pdo->prepare($sql)->execute($params);
        
        echo "<script>
                sessionStorage.setItem('showSuccess', 'تم تحديث بيانات العميل بنجاح');
                window.location.href = '../tables/show-cstmr.php';
              </script>";
        exit;
    } catch (Exception $e) {
        $error_msg = "خطأ أثناء التحديث: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تعديل بيانات الموظف</title>
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>
        body { direction: rtl; text-align: right; }
        /* محاذاة كافة الحقول والعناوين لليمين */
        .form-control, select.form-control, label {
            text-align: right !important;
        }
        .form-section { 
            background: #fff; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
            margin-bottom: 20px; 
            text-align: right;
        }
        .card-footer { text-align: right !important; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-blocked { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <section class="content-header text-right">
                <div class="container-fluid">
                    <h1>تعديل بيانات الموظف: <?= htmlspecialchars($customer['client_name']) ?></h1>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <?php if(isset($error_msg)): ?>
                        <div class="alert alert-danger text-right"><?= $error_msg ?></div>
                    <?php endif; ?>

                    <form action="" method="POST">
                        <div class="card card-warning card-outline">
                            <div class="card-body">
                                
                                <div class="form-section">
                                    <div class="row">
                                        <div class="col-md-5 form-group">
                                            <label>اسم الموظف</label>
                                            <input type="text" name="customer_name" class="form-control" value="<?= htmlspecialchars($customer['client_name']) ?>" required>
                                        </div>
                                        <div class="col-md-4 form-group">
                                            <label>رقم الهاتف</label>
                                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>" required>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>حالة الحساب</label>
                                            <select name="status" class="form-control" style="font-weight: bold;">
                                                <option value="active" class="status-active" <?= ($customer['status'] == 'active') ? 'selected' : '' ?>>● نشط</option>
                                                <option value="inactive" class="status-blocked" <?= ($customer['status'] == 'inactive') ? 'selected' : '' ?>>● موقوف</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>البريد الإلكتروني</label>
                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email']) ?>" dir="ltr">
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>كلمة المرور الجديدة (اتركها فارغة إذا لم ترد التغيير)</label>
                                            <input type="password" name="password" class="form-control" placeholder="******">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>الفرع</label>
                                            <select name="branch_id" class="form-control">
                                                <?php foreach($branches as $b): ?>
                                                    <option value="<?= $b['id'] ?>" <?= ($customer['location_id'] == $b['id']) ? 'selected' : '' ?>>
                                                        <?= $b['branch_name'] ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>القسم</label>
                                            <select name="lab_id" class="form-control">
                                                <?php $labs = $pdo->query("SELECT MIN(id) AS id, department_name FROM departments GROUP BY department_name")->fetchAll(PDO::FETCH_ASSOC); ?>
                                                <?php foreach($labs as $l): ?>
                                                    <option value="<?= $l['id'] ?>" <?= ($customer['department_id'] == $l['id']) ? 'selected' : '' ?>>
                                                        <?= $l['department_name'] ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-12 form-group">
                                            <label>العنوان</label>
                                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($customer['address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="card-footer">
                                <button type="submit" name="update_customer" class="btn btn-warning px-4 font-weight-bold">حفظ التغييرات</button>
                                <a href="show-cstmr.php" class="btn btn-secondary mr-2">إلغاء</a>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
    
    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.min.js"></script>
</body>
</html>