<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-cstmr.php"; 

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

// 1. التحقق من الصلاحيات وجلب البيانات الأساسية
try {
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

    $branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $deptsSql = "SELECT d.id, d.department_name, b.branch_name 
                FROM departments d
                LEFT JOIN regions r ON d.region_id = r.id
                LEFT JOIN branches b ON r.branch_id = b.id
                ORDER BY b.branch_name, d.department_name";
    $departments = $pdo->query($deptsSql)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

// 2. معالجة الفورم عند الحفظ
if (isset($_POST['add_customer'])) {
    if ($can_add != 1) {
        $error_msg = "عذراً، لا تملك صلاحية إضافة عملاء جدد.";
    } else {
        $customer_name   = $_POST['customer_name'];
        $customer_type   = $_POST['customer_type'];
        $phone           = $_POST['phone'];
        $email           = $_POST['email']; 
        $address         = $_POST['address'];
        $national_id     = $_POST['national_id'];
        $cr_number       = $_POST['cr_number'];
        $cr_expiry_g     = $_POST['cr_expiry_g'];
        $owner_name      = $_POST['owner_name'];
        $owner_id        = $_POST['owner_id'];
        $capital         = $_POST['capital'];
        $activity        = $_POST['activity'] ?? '';
        $branch_id       = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        $lab_id          = !empty($_POST['lab_id']) ? $_POST['lab_id'] : null;

        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        try {
            $sql = "INSERT INTO clients (client_name, client_type, phone, email, password, address, 
                    national_id, cr_number, cr_expiry_date, owner_name, owner_id, capital, commercial_activity, location_id, department_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $customer_name, $customer_type, $phone, $email, $hashed_password, $address,
                $national_id, $cr_number, $cr_expiry_g, $owner_name, $owner_id, $capital, $activity, $branch_id, $lab_id
            ]);

            echo "<script>
                    sessionStorage.setItem('showSuccess', 'تم إضافة العميل بنجاح');
                    window.location.href = '../tables/show-cstmr.php';
                  </script>";
            exit;
        } catch (Exception $e) {
            $error_msg = "خطأ في الإدخال: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إضافة عميل | نظام الإدارة</title>
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    
    <style>
        :root { --primary-blue: #1e4b8a; }
        body { direction: rtl; text-align: right; font-family: 'Source Sans Pro', sans-serif; }
        .form-section { border: 1px solid #eee; padding: 20px; border-radius: 10px; margin-bottom: 25px; background: #fdfdfd; }
        .section-title { font-size: 1rem; font-weight: bold; color: var(--primary-blue); margin-bottom: 20px; border-right: 4px solid var(--primary-blue); padding-right: 10px; }
        .upload-zone { border: 2px dashed #17a2b8; padding: 20px; border-radius: 10px; cursor: pointer; background: #f8f9fa; transition: 0.3s; }
        .upload-zone:hover { background: #e9ecef; }
        
        /* تنسيق منطقة القص للجوال */
        .img-container { 
            max-height: 70vh; 
            width: 100%; 
            background-color: #333; 
            overflow: hidden; 
            display: flex; 
            justify-content: center; 
            align-items: center;
        }
        #image-to-crop { max-width: 100%; }
        
        /* إخفاء الصورة المكررة خارج المودال */
        .preview-container { display: none; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <?php include(__DIR__ . '/../../main-header.php'); ?>
    <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

    <div class="content-wrapper">
        <section class="content">
            <div class="container-fluid pt-3">
                <div class="card card-outline card-primary shadow-sm">
                    <div class="card-body">
                        <div class="form-section shadow-sm border-info bg-light">
                            <div class="section-title text-info"><i class="fas fa-magic ml-2"></i>تحليل السجل الذكي (OCR)</div>
                            <div class="row justify-content-center text-center">
                                <div class="col-md-8">
                                    <div class="upload-zone" onclick="document.getElementById('cr_file').click()">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-info mb-3"></i>
                                        <h5>اضغط لرفع صورة أو ملف PDF أو Ctrl+V للصق</h5>
                                        <input type="file" id="cr_file" accept="image/*,application/pdf" style="display:none" onchange="handleFileSelect(this)">
                                    </div>
                                    <div id="upload-status" class="mt-3 text-muted small">جاهز لاستقبال الملف...</div>
                                </div>
                            </div>
                        </div>

                        <form action="" method="POST" id="customerForm">
                            <div class="form-section">
                                <div class="section-title">بيانات السجل التجاري والمنشأة</div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>اسم المنشأة <span class="text-danger">*</span></label>
                                        <input type="text" name="customer_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>نوع المنشأة</label>
                                        <input type="text" name="customer_type" id="customer_type" class="form-control" placeholder="مؤسسة / شركة">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>حالة السجل</label>
                                        <input type="text" name="cr_status" id="cr_status" class="form-control" placeholder="نشط">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>الرقم الوطني الموحد</label>
                                        <input type="text" name="national_id" class="form-control">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>رقم السجل التجاري</label>
                                        <input type="text" name="cr_number" id="cr_number" class="form-control">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>تاريخ القيد</label>
                                        <input type="text" name="cr_date" id="cr_date" class="form-control">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>تاريخ الانتهاء</label>
                                        <input type="text" name="cr_expiry_g" class="form-control">
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>رأس المال الكلي</label>
                                        <input type="text" name="capital" class="form-control">
                                    </div>
                                    <div class="col-md-8 form-group">
                                        <label>العنوان المعتمد</label>
                                        <input type="text" name="address" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="section-title">بيانات المالك والاتصال</div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>اسم المالك</label>
                                        <input type="text" name="owner_name" class="form-control">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label>رقم الهوية</label>
                                        <input type="text" name="owner_id" class="form-control">
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>رقم الجوال <span class="text-danger">*</span></label>
                                        <input type="tel" name="phone" class="form-control text-left" dir="ltr" required>
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>البريد الإلكتروني</label>
                                        <input type="email" name="email" class="form-control text-left" dir="ltr">
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>كلمة المرور <span class="text-danger">*</span></label>
                                        <input type="password" name="password" class="form-control" required>
                                    </div>
                                </div>
                            </div>

                            <div class="text-center pb-4">
                                <button type="submit" name="add_customer" class="btn btn-primary btn-lg px-5">حفظ البيانات</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="modal fade" id="cropModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">تعديل وقص الصورة لزيادة دقة التحليل</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-0">
                <div class="img-container">
                    <img id="image-to-crop" src="">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="crop-button"><i class="fas fa-crop-alt ml-1"></i> قص وبدء التحليل</button>
            </div>
        </div>
    </div>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>

<script>
let cropper;
const imageElement = document.getElementById('image-to-crop');

// رابط الـ API (صفحتك في admin/pages/forms/ والـ API في admin/api/)
const OCR_API_URL = '../../api/cr_ocr_api.php';

// ---------- اختيار الملف ----------
function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        // PDF يُرسل مباشرة للخادم (لا يحتاج قص)
        if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
            sendToApi(file);
        } else {
            startCropping(file); // الصور تمرّ بواجهة القص
        }
    }
}

// ---------- اللصق (Ctrl+V) ----------
document.addEventListener('paste', e => {
    const item = Array.from(e.clipboardData.items).find(x => x.kind === 'file');
    if (item) startCropping(item.getAsFile());
});

// ---------- واجهة القص ----------
function startCropping(file) {
    if (cropper) { cropper.destroy(); cropper = null; }
    imageElement.src = '';
    const reader = new FileReader();
    reader.onload = (e) => {
        imageElement.src = e.target.result;
        $('#cropModal').modal('show');
    };
    reader.readAsDataURL(file);
}

$('#cropModal').on('shown.bs.modal', function () {
    cropper = new Cropper(imageElement, {
        viewMode: 2, dragMode: 'move', autoCropArea: 0.9,
        checkOrientation: true, responsive: true
    });
}).on('hidden.bs.modal', function () {
    if (cropper) { cropper.destroy(); cropper = null; }
    imageElement.src = '';
});

// ---------- القص ثم الإرسال للـ API ----------
document.getElementById('crop-button').addEventListener('click', function () {
    if (!cropper) return;
    const canvas = cropper.getCroppedCanvas({
        width: 1500, imageSmoothingQuality: 'high'
    });
    $('#cropModal').modal('hide');
    canvas.toBlob((blob) => {
        if (blob) sendToApi(blob, 'cropped.jpg');
    }, 'image/jpeg', 0.95);
});

// ---------- إرسال الملف للـ API الخادمي ----------
async function sendToApi(fileOrBlob, filename) {
    const status = document.getElementById('upload-status');
    status.innerHTML = '<i class="fas fa-sync fa-spin text-primary"></i> جاري التحليل في الخادم...';

    const formData = new FormData();
    formData.append('file', fileOrBlob, filename || fileOrBlob.name || 'upload');

    try {
        const res = await fetch(OCR_API_URL, { method: 'POST', body: formData });
        const json = await res.json();

        if (!json.success) {
            status.innerHTML = '<span class="text-danger">' + (json.message || 'فشل التحليل') + '</span>';
            return;
        }

        fillForm(json.data);

        const src = json.source === 'pdf-text' ? 'PDF أصلي (دقة عالية)'
                  : json.source === 'pdf-ocr'  ? 'PDF ممسوح (OCR)'
                  : 'صورة (OCR)';
        status.innerHTML = '<span class="text-success font-weight-bold">اكتمل الاستخراج بنجاح ('
                         + src + ')! راجع البيانات قبل الحفظ.</span>';
    } catch (err) {
        status.innerHTML = '<span class="text-danger">تعذّر الاتصال بالخادم. تأكد من مسار الـ API.</span>';
        console.error(err);
    }
}

// ---------- تعبئة الفورم من بيانات الـ API ----------
function fillForm(data) {
    const setByName = (name, val) => {
        if (val == null || val === '') return;
        const el = document.getElementsByName(name)[0];
        if (el) el.value = val;
    };
    const setById = (id, val) => {
        if (val == null || val === '') return;
        const el = document.getElementById(id);
        if (el) el.value = val;
    };

    setByName('customer_name', data.customer_name);
    setById('customer_type',   data.customer_type);
    setById('cr_status',       data.cr_status);
    setByName('national_id',   data.national_id);
    setById('cr_number',       data.cr_number);
    setById('cr_date',         data.cr_date);
    setByName('cr_expiry_g',   data.cr_expiry_g);
    setByName('capital',       data.capital);
    setByName('address',       data.address);
    setByName('owner_name',    data.owner_name);
    setByName('owner_id',      data.owner_id);
    setByName('phone',         data.phone);
    setByName('email',         data.email);
}
</script>
</body>
</html>
