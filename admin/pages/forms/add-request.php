<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";
$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-request.php"; // المسار المعتمد في جدول sys_menu

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// 1. جلب id الصفحة ديناميكياً من جدول sys_menu
$menuSql = "SELECT id FROM sys_menu WHERE link = ?";
$menuStmt = $pdo->prepare($menuSql);
$menuStmt->execute([$page_path]);
$menu_item = $menuStmt->fetch(PDO::FETCH_ASSOC);

$current_page_id = $menu_item['id'] ?? 0;

// 2. جلب صلاحية الإضافة من جدول user_menu_access باستخدام الـ id المستخرج
$can_add = 0;
$can_edit = 0;
$can_delete = 0;
if ($current_page_id > 0) {
    $accessSql = "SELECT can_add,can_edit,can_delete FROM user_menu_access 
                  WHERE user_id = ? AND menu_id = ?";
    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute([$current_user_id, $current_page_id]);
    $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
    
    $can_add = $permissions['can_add'] ?? 0;
    $can_edit = $permissions['can_edit'] ?? 0;
    $can_delete = $permissions['can_delete'] ?? 0;
}


// التحقق من إرسال نموذج البلاغ
if (isset($_POST['add_ticket'])) {
    $reporter_ref   = trim($_POST['user_id_number']);
    $branch_id      = $_POST['branch_id'];
    $region_id      = $_POST['college_id'];
    $department_id  = !empty($_POST['lab_id']) ? $_POST['lab_id'] : null;
    $room_number    = trim($_POST['room_number']);
    $priority       = $_POST['priority'];
    $details        = trim($_POST['details']);

    // التأكد من اختيار تصنيف المشكلة وتعيينه في متغير واحد فقط
    $category_id = isset($_POST['issue_type_id']) ? $_POST['issue_type_id'] : null;

    if ($category_id === null) {
        die("<script>alert('الرجاء اختيار تصنيف المشكلة'); window.history.back();</script>");
    }

    try {
        $sql = "INSERT INTO tickets (reporter_ref, branch_id, region_id, department_id, location_name, category_id, priority, details, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $reporter_ref,
            $branch_id,
            $region_id,
            $department_id,
            $room_number,
            $category_id,
            $priority,
            $details
        ]);

        echo "<script>
                    sessionStorage.setItem('swal_title', 'تمت العملية!');
                    sessionStorage.setItem('swal_text', 'تم إرسال البلاغ بنجاح');
                    sessionStorage.setItem('swal_icon', 'success');
                    window.location.href = '../tables/show-requests.php'; 
                </script>";
    } catch (PDOException $e) {
        die("خطأ في قاعدة البيانات: " . $e->getMessage());
    }
}

// ---------------------------------------------------------
// الأكواد الخاصة بجلب البيانات للقوائم المنسدلة (كما هي في كودك)
// ---------------------------------------------------------

// جلب جميع الفروع
$branchesSql = "SELECT id, branch_name FROM branches ORDER BY branch_name ASC";
$allBranches = $pdo->query($branchesSql)->fetchAll();

// جلب تصنيفات المشاكل من جدول التصنيفات
$categoriesSql = "SELECT id, category_name FROM issue_categories ORDER BY id ASC";
$allCategories = $pdo->query($categoriesSql)->fetchAll();
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>توثيق المشاكل</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>

        .section-title {
            background: #f8f9fa;
            border-right: 5px solid var(--uni-primary);
            padding: 10px 14px;
            margin-bottom: 20px;
            font-weight: 700;
            color: var(--uni-primary);
            border-radius: 0 6px 6px 0;
            font-size: 1rem;
        }
        [dir="ltr"] .section-title {
            border-right: none;
            border-left: 5px solid var(--uni-primary);
            border-radius: 6px 0 0 6px;
        }

        .card-ticket {
            border: none;
            border-top: 4px solid var(--uni-primary);
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            border-radius: 10px;
            height: 100%;
        }
        .card-ticket .card-body { padding: 22px; }

        .radio-box {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            background: #fff;
            padding: 14px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .radio-box label {
            margin: 0;
            padding: 6px 14px;
            border-radius: 20px;
            cursor: pointer;
            transition: all .15s;
            background: #f1f3f5;
            font-weight: 500;
        }
        .radio-box label:hover { background: #e2e6ea; }
        .radio-box input[type="radio"] { margin-left: 6px; }

        .btn-submit {
            padding: 12px 40px;
            font-size: 1.05rem;
            border-radius: 8px;
            font-weight: 600;
        }

        .form-control:focus {
            border-color: var(--uni-primary);
            box-shadow: 0 0 0 .2rem rgba(13,110,253,.15);
        }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">

    <div class="wrapper">


        <?php include(__DIR__ . '/../../main-header.php'); ?>




        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>



        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">

                        <div class="col-sm-12">
                            <div class="uni-header">
                                <h4><i class="fas fa-headset ml-2"></i> بوابة الدعم الفني - توثيق المشاكل</h4>
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                                    <li class="breadcrumb-item active">بلاغ فني</li>
                                </ol>
                            </div>
                        </div>

                    </div>
                </div>
            </section>



            <section class="content mt-4">
                <div class="container-fluid">
                    <form method="POST">
                        <div class="row">

                            <div class="col-md-5">
                                <div class="card card-ticket">
                                    <div class="card-body">
                                        <div class="section-title">بيانات الموقع والمبلغ</div>
                                        <div class="form-group">
                                            <label>الرقم الجامعي / الوظيفي</label>
                                            <input type="text" name="user_id_number" class="form-control"
                                                placeholder="مثال: 20241010" required>
                                        </div>
                                        <div class="form-group">
                                            <label>اختيار الفرع</label>
                                            <select id="branch_select" name="branch_id" class="form-control" required>
                                                <option value="">-- اختر الفرع --</option>
                                                <?php if (!empty($allBranches)): ?>
                                                    <?php foreach ($allBranches as $branch): ?>
                                                        <option value="<?= $branch['id'] ?>">
                                                            <?= htmlspecialchars($branch['branch_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                        <label class="d-block">اختيار المنطقة</label>
                                            <select id="college_select" name="college_id" class="form-control" required
                                                disabled>
                                                <option value="">-- اختر المنطقة --</option>
                                            </select>
                                        </div>
                                        <div class="form-group" id="lab_select_container">
                                            <label>اختر القسم</label>
                                            <select name="lab_id" id="lab_select" class="form-control">
                                                <option value="">-- اختر المنطقة أولاً --</option>
                                            </select>
                                        </div>


                                        <div class="form-group">
                                            <label>رقم القاعة / المعمل (أو اسم المكان)</label>
                                            <input type="text" name="room_number" class="form-control"
                                                placeholder="مثال: Lab 04 أو قاعة 201">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-7">
                                <div class="card card-ticket">
                                    <div class="card-body">
                                        <div class="section-title">تفاصيل البلاغ الفني</div>

                                        <div class="form-group">
                                            <label>تصنيف المشكلة</label>
                                            <div class="radio-box">
                                                <?php if (!empty($allCategories)): ?>
                                                    <?php foreach ($allCategories as $group): ?>
                                                        <label>
                                                            <input type="radio" name="issue_type_id" value="<?= $group['id'] ?>"
                                                                required>
                                                            <?= htmlspecialchars($group['category_name']) ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label>درجة الأولوية</label>
                                            <div class="radio-box" style="border-color: #ffc107;">
                                                <label class="text-info"><input type="radio" name="priority"
                                                        value="Low">
                                                    عادي</label>
                                                <label class="text-success"><input type="radio" name="priority"
                                                        value="Medium">
                                                    متوسط</label>
                                                <label class="text-warning"><input type="radio" name="priority"
                                                        value="High">
                                                    عاجل</label>
                                                <label class="text-danger"><input type="radio" name="priority"
                                                        value="Urgent">
                                                    طارئ</label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label>وصف المشكلة بالتفصيل</label>
                                            <textarea name="details" class="form-control" rows="4"
                                                placeholder="يرجى كتابة ما حدث، مثلاً: جهاز رقم 12 في المعمل لا يقلع.."></textarea>
                                        </div>

                                        <hr>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <p class="small text-muted">سيتم إخطار فنيي الدور فور إرسال البلاغ.</p>
                                            <div class="form-group">
    <?php if ($can_add == 1): ?>
        <button type="submit" name="add_ticket" class="btn btn-primary btn-submit">
            <i class="fas fa-paper-plane ml-1"></i> إرسال البلاغ
        </button>
    <?php else: ?>
        <button type="button" class="btn btn-secondary btn-submit disabled" style="cursor: not-allowed;" title="ليس لديك صلاحية لإرسال بلاغات">
            <i class="fas fa-ban ml-1"></i> إرسال البلاغ (غير مسموح)
        </button>
    <?php endif; ?>
</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </form>
                </div>
            </section>

        </div>

        <footer class="main-footer">
            <?php include('../../main-footer.php') ?>
        </footer>

    </div>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script>
    $(document).ready(function() {
        // تحميل المناطق عند تغيير الفرع
        $('#branch_select').on('change', function() {
            var branchId = $(this).val();
            var collegeSelect = $('#college_select');
            var labSelect = $('#lab_select');

            labSelect.prop('disabled', true).html('<option value="">-- اختر المنطقة أولاً --</option>');

            if (!branchId) {
                collegeSelect.prop('disabled', true).html('<option value="">-- اختر الفرع أولاً --</option>');
                return;
            }

            collegeSelect.prop('disabled', false).html('<option value="">جاري التحميل...</option>');

            $.ajax({
                url: 'get_colleges.php',
                type: 'GET',
                data: { branch_id: branchId },
                dataType: 'json',
                success: function(data) {
                    var opts = '<option value="">-- اختر المنطقة --</option>';
                    $.each(data, function(i, v) {
                        opts += '<option value="' + v.id + '">' + v.region_name + '</option>';
                    });
                    collegeSelect.html(opts);
                },
                error: function() {
                    collegeSelect.html('<option value="">خطأ في تحميل البيانات</option>');
                }
            });
        });

        // تحميل الأقسام عند تغيير المنطقة
        $('#college_select').on('change', function() {
            var collegeId = $(this).val();
            var labSelect = $('#lab_select');

            if (!collegeId) {
                labSelect.prop('disabled', true).html('<option value="">-- اختر المنطقة أولاً --</option>');
                return;
            }

            labSelect.prop('disabled', false).html('<option value="">جاري التحميل...</option>');

            $.ajax({
                url: 'get_labs.php',
                type: 'GET',
                data: { college_id: collegeId },
                dataType: 'json',
                success: function(data) {
                    var opts = '<option value="">-- اختر القسم --</option>';
                    if (data.length > 0) {
                        $.each(data, function(i, v) {
                            opts += '<option value="' + v.id + '">' + v.department_name + '</option>';
                        });
                    } else {
                        opts += '<option value="">لا توجد أقسام لهذه المنطقة</option>';
                    }
                    labSelect.html(opts);
                },
                error: function() {
                    labSelect.html('<option value="">خطأ في تحميل البيانات</option>');
                }
            });
        });
    });
    </script>

</body>

</html>