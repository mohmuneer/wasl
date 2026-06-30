<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";

// جلب معرف البلاغ من الرابط
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id <= 0) {
    die("<script>alert('معرف غير صحيح'); window.history.back();</script>");
}

// استعلام جلب البيانات مع ربط الجداول لجلب الأسماء
try {
    $sql = "SELECT r.*, b.branch_name, c.region_name, d.department_name, g.category_name 
            FROM tickets r
            LEFT JOIN branches b ON r.branch_id = b.id
            LEFT JOIN regions c ON r.region_id = c.id
            LEFT JOIN departments d ON r.department_id = d.id
            LEFT JOIN issue_categories g ON r.category_id = g.id
            WHERE r.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$request_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        die("<script>alert('البلاغ غير موجود'); window.history.back();</script>");
    }
} catch (PDOException $e) {
    die("خطأ: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>View Request #<?php echo $request_id; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <style>
        /* الحفاظ على نفس التنسيقات الخاصة بك */
        html,
        body {
            overflow-x: hidden !important;
            scrollbar-width: none !important;
        }

        ::-webkit-scrollbar {
            display: none !important;
        }


        .section-title {
            background: #f8f9fa;
            border-right: 5px solid var(--uni-primary, var(--crm-primary));
            padding: 8px;
            margin-bottom: 15px;
            font-weight: bold;
            color: var(--uni-primary, var(--crm-primary));
        }

        .card-ticket {
            border: 1px solid #ddd;
            border-top: 3px solid var(--uni-primary, var(--crm-primary));
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .data-display {
            background: #f1f1f1 !important;
            font-weight: bold;
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
                            <div class="main-header-uni">
                                <i class="fas fa-eye"></i> تفاصيل البلاغ رقم #<?php echo $request_id; ?>
                                <span class="badge badge-light float-left p-2"><?php echo $data['status']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="content mt-4">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-5">
                            <div class="card card-ticket">
                                <div class="card-body">
                                    <div class="section-title">بيانات الموقع والمبلغ</div>
                                    <div class="form-group">
                                        <label>الرقم الجامعي / الوظيفي</label>
                                        <input type="text" class="form-control data-display"
                                            value="<?php echo $data['reporter_ref'] ?? ''; ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>الفرع</label>
                                        <input type="text" class="form-control data-display"
                                            value="<?php echo $data['branch_name']; ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>الكلية</label>
                                        <input type="text" class="form-control data-display"
                                            value="<?php echo $data['region_name']; ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>المعمل</label>
                                        <input type="text" class="form-control data-display"
                                            value="<?php echo $data['department_name'] ?: 'لا يوجد'; ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>رقم القاعة / الموقع</label>
                                        <input type="text" class="form-control data-display"
                                            value="<?php echo $data['location_name']; ?>" readonly>
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
                                        <input type="text" class="form-control data-display text-primary"
                                            value="<?php echo $data['category_name']; ?>" readonly>
                                    </div>

                                    <div class="form-group">
                                        <label>درجة الأهمية</label>
                                        <input type="text"
                                            class="form-control data-display <?php echo ($data['priority'] == 'High') ? 'text-danger' : ''; ?>"
                                            value="<?php echo $data['priority']; ?>" readonly>
                                    </div>

                                    <div class="form-group">
                                        <label>وصف المشكلة بالتفصيل</label>
                                        <textarea class="form-control data-display" rows="5"
                                            readonly><?php echo $data['details']; ?></textarea>
                                    </div>

                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <button onclick="window.print()" class="btn btn-info">
                                            <i class="fas fa-print"></i> طباعة البلاغ
                                        </button>
                                        <a href="../tables/show-requests.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-right"></i> عودة للخلف
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
</body>

</html>