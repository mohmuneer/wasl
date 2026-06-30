<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../../../config/db.php";
require_once __DIR__ . "/../../../core/Notify.php";
$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/forms/add-task.php"; // المسار المعتمد في جدول sys_menu

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

try {
    // أولاً: جلب الفنيين بدور SupTech
    $sql = "SELECT
    u.id, u.full_name, u.email, u.file_path, u.status,
    u.employee_id, u.job_title, u.phone,
    GROUP_CONCAT(DISTINCT r.role_name SEPARATOR ', ') AS all_roles,
    GROUP_CONCAT(DISTINCT b.branch_name SEPARATOR ' · ') AS all_branches
FROM sys_users u
INNER JOIN user_roles up ON u.id = up.user_id
INNER JOIN sys_roles r ON up.role_id = r.id
LEFT JOIN user_branch_access ub ON u.id = ub.user_id
LEFT JOIN branches b ON ub.branch_id = b.id
WHERE LOWER(r.role_code) = LOWER('SupTech')
  AND u.status = 'active'
GROUP BY u.id
ORDER BY u.full_name ASC";

    $allUsers = $pdo->query($sql)->fetchAll();

    // إذا لم يوجد دور SupTech في النظام — اجلب جميع المستخدمين النشطين كبديل
    if (empty($allUsers)) {
        $sql2 = "SELECT
            u.id, u.full_name, u.email, u.file_path, u.status,
            u.employee_id, u.job_title, u.phone,
            GROUP_CONCAT(DISTINCT r.role_name SEPARATOR ', ') AS all_roles,
            GROUP_CONCAT(DISTINCT b.branch_name SEPARATOR ' · ') AS all_branches
        FROM sys_users u
        LEFT JOIN user_roles up ON u.id = up.user_id
        LEFT JOIN sys_roles r ON up.role_id = r.id
        LEFT JOIN user_branch_access ub ON u.id = ub.user_id
        LEFT JOIN branches b ON ub.branch_id = b.id
        WHERE u.status = 'active'
        GROUP BY u.id
        ORDER BY u.full_name ASC";
        $allUsers = $pdo->query($sql2)->fetchAll();
    }

    // البلاغات المعلقة — لا نُحمِّلها هنا بعد الآن (يتم البحث عبر AJAX في search_tickets.php)
    $requests = [];
} catch (PDOException $e) {
    $allUsers = [];
    $requests = [];
    // يمكنك تفعيل هذا السطر للتصحيح: echo $e->getMessage();
}

// 2. معالجة الإسناد
if (isset($_POST['assign_task'])) {
    $request_id   = !empty($_POST['task_title']) ? (int)$_POST['task_title'] : null;
    $assigned_to  = (int)($_POST['assigned_to'] ?? 0);
    $priority     = $_POST['priority']  ?? 'Medium';
    $deadline     = $_POST['deadline']  ?? '';
    $details      = trim($_POST['details'] ?? '');
    $created_by   = $_SESSION['user_id'] ?? 1;

    // ── التحقق من المدخلات قبل الإدراج ──────────────────────────────
    $validationError = null;
    if ($assigned_to <= 0) {
        $validationError = 'يرجى اختيار الفني المسؤول عن المهمة من القائمة.';
    } elseif (!$request_id) {
        $validationError = 'يرجى اختيار البلاغ المُراد إسناده.';
    } else {
        // التحقق أن المستخدم موجود فعلاً في قاعدة البيانات
        $checkUser = $pdo->prepare("SELECT COUNT(*) FROM sys_users WHERE id = ? AND status = 'active'");
        $checkUser->execute([$assigned_to]);
        if (!$checkUser->fetchColumn()) {
            $validationError = 'الفني المحدد غير موجود أو حسابه غير نشط. يرجى اختيار فني آخر.';
        }
    }

    if ($validationError) {
        echo "<script>
            sessionStorage.setItem('app_message', JSON.stringify({
                icon: 'warning',
                title: 'تنبيه',
                text: " . json_encode($validationError) . "
            }));
            history.back();
        </script>";
        exit;
    }
    // ────────────────────────────────────────────────────────────────

    try {
        $pdo->beginTransaction();

        // 1. إدخال المهمة الجديدة في جدول tasks
        $task_title = !empty($_POST['task_title']) ? 'مهمة جديدة' : 'مهمة عامة';
        $insertSql = "INSERT INTO work_orders (ticket_id, assigned_to, priority, deadline, details, status, created_by, created_at, title) 
                      VALUES (?, ?, ?, ?, ?, 'Pending', ?, NOW(), ?)";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([$request_id, $assigned_to, $priority, $deadline, $details, $created_by, $task_title]);

        // 2. تحديث حالة البلاغ الأصلي في جدول requests ليصبح "قيد التنفيذ"
        if ($request_id) {
            $updateReqSql = "UPDATE tickets SET status = 'In Progress' WHERE id = ?";
            $updateStmt = $pdo->prepare($updateReqSql);
            $updateStmt->execute([$request_id]);
        }

        $pdo->commit(); // اعتماد التغييرات

        // ── إشعار داخلي للفني عبر الدردشة ──────────────────────────────
        // جلب تفاصيل البلاغ المُحال لإثراء نص الإشعار
        $taskData = [
            'priority' => $priority,
            'deadline' => $deadline,
            'details'  => $details,
        ];
        if ($request_id) {
            $ticketRow = $pdo->prepare(
                "SELECT b.branch_name, c.category_name
                 FROM tickets t
                 LEFT JOIN branches b ON t.branch_id = b.id
                 LEFT JOIN issue_categories c ON t.category_id = c.id
                 WHERE t.id = ? LIMIT 1"
            );
            $ticketRow->execute([$request_id]);
            $ticketInfo = $ticketRow->fetch(PDO::FETCH_ASSOC);
            if ($ticketInfo) {
                $taskData['branch_name']   = $ticketInfo['branch_name']   ?? '';
                $taskData['category_name'] = $ticketInfo['category_name'] ?? '';
            }
        }
        Notify::onTaskAssigned($pdo, (int)$created_by, (int)$assigned_to, $taskData);
        // ────────────────────────────────────────────────────────────────

        // جلب اسم الفني للرسالة
        $techRow = $pdo->prepare("SELECT full_name FROM sys_users WHERE id = ? LIMIT 1");
        $techRow->execute([$assigned_to]);
        $techName = $techRow->fetchColumn() ?: 'الفني';

        header("Location: ../tables/show-tasks.php?task_saved=1&tech=" . urlencode($techName) . "&req=" . (int)$request_id);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "<script>
            sessionStorage.setItem('app_message', JSON.stringify({
                icon: 'error',
                title: 'خطأ في الحفظ',
                text: " . json_encode('حدث خطأ أثناء حفظ المهمة: ' . $e->getMessage()) . "
            }));
            history.back();
        </script>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>إسناد مهمة جديدة</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <!-- Select2 محذوف — تم الاستغناء عنه بالمكتبات المخصصة -->
    <!-- jQuery مبكراً — main-header.php يستدعيه فوراً -->
    <script src="../../plugins/jquery/jquery.min.js"></script>
    <style>
    /* إخفاء شريط التمرير الأفقي والعمودي نهائياً */
    html,
    body {
        overflow-x: hidden !important;
        /* يمنع التمرير العرضي الذي يظهر في الصورة */
        scrollbar-width: none !important;
        /* Firefox */
        -ms-overflow-style: none !important;
        /* IE/Edge */
    }

    /* لمتصفحات Chrome و Safari */
    ::-webkit-scrollbar {
        display: none !important;
        width: 0px !important;
        background: transparent !important;
    }

    /* إخفاء أشرطة مكتبة OverlayScrollbars الخاصة بالقالب */
    .os-scrollbar,
    .os-scrollbar-horizontal,
    .os-scrollbar-vertical {
        display: none !important;
        visibility: hidden !important;
    }

    /* منع ظهور الفراغ الأبيض في أسفل الصفحة */
    .wrapper {
        overflow-x: hidden !important;
    }



    .section-title {
        border-right: 5px solid;
        background: var(--uni-primary, var(--crm-primary));
        padding: 0px;
        margin-bottom: 0px;
        font-weight: bold;

    }

    .card-ticket {
        border: 1px solid #ddd;
        border-top: 3px solid;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        background: var(--uni-primary, var(--crm-primary));
    }



    .priority-urgent {
        color: #d9534f;
        font-weight: bold;
    }

    /* تعديل مكان زر المسح (Clear button) */
    .select2-container--default .select2-selection--single .select2-selection__clear {
        float: left !important;
        margin-left: 10px !important;
        margin-right: 0 !important;
    }

    /* التأكد من محاذاة النص لليمين داخل حاوية Select2 */
    .select2-container {
        direction: rtl;
        text-align: right;
    }

    /* إصلاح تموضع علامة الـ x عند استخدام ثيم بوتستراب 4 مع RTL */
    .select2-container--bootstrap4 .select2-selection--single .select2-selection__clear {
        float: left !important;
        /* نقلها لليسار */
        margin-right: 0 !important;
        margin-left: 0.5rem !important;
        /* إعطاؤها مساحة بسيطة من الحافة */
        position: relative;
        z-index: 1;
    }

    /* التأكد من أن السهم الصغير يظل في مكانه الصحيح (أقصى اليسار عادة في RTL) */
    .select2-container--bootstrap4[dir="rtl"] .select2-selection--single .select2-selection__arrow {
        left: 3px !important;
        right: auto !important;
    }

    /* إعطاء مساحة للنص حتى لا يغطي على علامة الـ x */
    .select2-container--bootstrap4[dir="rtl"] .select2-selection--single .select2-selection__rendered {
        padding-left: 40px !important;
        padding-right: 8px !important;
    }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">

    <div class="wrapper">


        <?php include(__DIR__ . '/../../main-header.php'); ?>




        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>



        <div class="content-wrapper">
            <section class="content mt-4">

                <div class="container-fluid">

                    <form method="POST">
                        <div class="row mb-3">
                            <div class="col-sm-12">
                                <div class="main-header-uni">
                                    <div class="header-title">
                                        <h5 class="mb-0">
                                            <i class="fas fa-university ml-2"></i>
                                            بوابة الدعم الفني والصيانة
                                        </h5>
                                    </div>
                                    <nav aria-label="breadcrumb">
                                        <ol class="breadcrumb mb-0">
                                            <li class="breadcrumb-item">
                                                <a href="../../index.php">الرئيسية</a>
                                            </li>
                                            <li class="breadcrumb-item active">إسناد المهام</li>
                                        </ol>
                                    </nav>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="card card-custom p-3">

                                    <!-- ══ بحث الموظفين (بالاسم أو رقم الموظف) ══ -->
                                    <div class="form-group mt-3" id="employeePickerWrap">
                                        <label style="font-weight:700;margin-bottom:8px;display:block">
                                            <i class="fas fa-user-hard-hat ml-1" style="color:var(--crm-primary,#1a5276)"></i>
                                            اختر الفني المسؤول
                                            <span style="background:#e2e8f0;color:#64748b;font-size:.68rem;padding:2px 8px;border-radius:20px;font-weight:700;margin-right:6px"><?= count($allUsers) ?> موظف</span>
                                        </label>

                                        <!-- الموظف المحدد -->
                                        <div id="selectedEmpInfo" style="display:none;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;padding:10px 14px;margin-bottom:8px;align-items:center;justify-content:space-between;gap:10px">
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <div id="empAvatar" style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#065f46,#059669);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:800;flex-shrink:0"></div>
                                                <div>
                                                    <div style="font-size:.72rem;color:#64748b;margin-bottom:1px">الفني المحدد</div>
                                                    <div style="font-size:.9rem;font-weight:800;color:#065f46" id="selectedEmpName"></div>
                                                    <div style="font-size:.72rem;color:#64748b" id="selectedEmpMeta"></div>
                                                </div>
                                            </div>
                                            <button type="button" onclick="clearEmpPicker()"
                                                style="background:#fee2e2;border:none;border-radius:7px;padding:5px 10px;font-size:.72rem;font-weight:700;color:#dc2626;cursor:pointer;white-space:nowrap">
                                                <i class="fas fa-times ml-1"></i>تغيير
                                            </button>
                                        </div>

                                        <!-- حقل البحث -->
                                        <div id="empSearchBox">
                                            <div style="position:relative;margin-bottom:6px">
                                                <i class="fas fa-search" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;pointer-events:none"></i>
                                                <input type="text" id="empSearchInput"
                                                    placeholder="ابحث بـ: رقم الموظف · الاسم · الفرع · المسمى الوظيفي..."
                                                    autocomplete="off"
                                                    style="width:100%;border:2px solid #e2e8f0;border-radius:10px;padding:10px 40px 10px 14px;font-size:.88rem;color:#334155;outline:none;transition:.2s"
                                                    onfocus="this.style.borderColor='var(--crm-primary,#1a5276)';renderEmpResults('')"
                                                    onblur="setTimeout(function(){ if(document.activeElement && document.activeElement.closest('#employeePickerWrap,#empResultsBox,.emp-select-btn')) return; document.getElementById('empResultsBox').style.display='none'; },200)">
                                            </div>

                                            <!-- نتائج البحث -->
                                            <div id="empResultsBox" style="display:none;border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden">
                                                <!-- رأس -->
                                                <div style="display:grid;grid-template-columns:50px 1fr 120px 100px 70px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));padding:8px 12px;gap:6px">
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">#</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">الاسم والمسمى</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">الفرع</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">رقم الموظف</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800;text-align:center">تحديد</div>
                                                </div>
                                                <div id="empRowsContainer" style="max-height:260px;overflow-y:auto"></div>
                                                <div id="empNoResults" style="display:none;text-align:center;padding:20px;color:#94a3b8;font-size:.82rem">
                                                    <i class="fas fa-user-slash fa-lg mb-2 d-block"></i>لا توجد نتائج مطابقة
                                                </div>
                                            </div>
                                        </div>

                                        <!-- الحقل الخفي -->
                                        <input type="hidden" name="assigned_to" id="selected_emp_id" required>
                                    </div>

                                    <!-- ══ بحث البلاغات — AJAX (يعمل مع أي عدد) ══ -->
                                    <div class="form-group mt-3" id="ticketPickerWrap">
                                        <label style="font-weight:700;margin-bottom:8px;display:block">
                                            <i class="fas fa-tasks ml-1" style="color:var(--crm-primary,#1a5276)"></i>
                                            اختر البلاغ المُراد إسناده
                                        </label>

                                        <!-- مربع البلاغ المحدد — نفس تصميم الموظف -->
                                        <div id="selectedTaskInfo" style="display:none;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;padding:10px 14px;margin-bottom:8px;align-items:center;justify-content:space-between;gap:10px">
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <div id="taskBadgeCircle" style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.75rem;font-weight:800;font-family:monospace"></div>
                                                <div>
                                                    <div style="font-size:.72rem;color:#64748b;margin-bottom:1px">البلاغ المحدد</div>
                                                    <div style="font-size:.9rem;font-weight:800;color:#065f46" id="selectedTaskLabel"></div>
                                                    <div style="font-size:.72rem;color:#64748b" id="selectedTaskMeta"></div>
                                                </div>
                                            </div>
                                            <button type="button" onclick="clearTicketPicker()"
                                                style="background:#fee2e2;border:none;border-radius:7px;padding:5px 10px;font-size:.72rem;font-weight:700;color:#dc2626;cursor:pointer;white-space:nowrap">
                                                <i class="fas fa-times ml-1"></i>تغيير
                                            </button>
                                        </div>

                                        <!-- حقل البحث -->
                                        <div id="ticketSearchBox">
                                            <div style="position:relative;margin-bottom:6px">
                                                <i class="fas fa-search" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;pointer-events:none"></i>
                                                <input type="text" id="ticketSearchInput"
                                                    placeholder="اكتب: رقم البلاغ · اسم المُبلِّغ · الفرع · التفاصيل..."
                                                    autocomplete="off"
                                                    style="width:100%;border:2px solid #e2e8f0;border-radius:10px;padding:10px 40px 10px 14px;font-size:.88rem;color:#334155;outline:none;transition:.2s"
                                                    onfocus="this.style.borderColor='var(--crm-primary,#1a5276)'"
                                                    onblur="this.style.borderColor='#e2e8f0'">
                                                <div id="ticketSpinner" style="display:none;position:absolute;left:13px;top:50%;transform:translateY(-50%)">
                                                    <i class="fas fa-circle-notch fa-spin" style="color:#94a3b8;font-size:.8rem"></i>
                                                </div>
                                            </div>
                                            <div style="font-size:.74rem;color:#94a3b8;margin-bottom:6px">
                                                <i class="fas fa-info-circle ml-1"></i>
                                                ابدأ الكتابة للبحث — أو اضغط على حقل البحث لعرض آخر البلاغات المعلقة
                                            </div>

                                            <!-- منطقة النتائج — نفس هيكل قائمة الموظفين -->
                                            <div id="ticketResults" style="border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;display:none">
                                                <!-- رأس الجدول — نفس ألوان وهيكل قائمة الموظفين -->
                                                <div style="display:grid;grid-template-columns:50px 1fr 120px 100px 70px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));padding:8px 12px;gap:6px">
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">#</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">المُبلِّغ والتفاصيل</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">الفرع</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800">التصنيف والأولوية</div>
                                                    <div style="color:rgba(255,255,255,.85);font-size:.65rem;font-weight:800;text-align:center">تحديد</div>
                                                </div>
                                                <!-- الصفوف -->
                                                <div id="ticketRowsContainer" style="max-height:260px;overflow-y:auto"></div>
                                                <div id="ticketResultsFooter" style="padding:6px 12px;background:#fafbfc;border-top:1px solid #f0f2f5;font-size:.72rem;color:#94a3b8;display:none"></div>
                                            </div>

                                            <!-- رسالة لا نتائج -->
                                            <div id="ticketNoResults" style="display:none;text-align:center;padding:24px;background:#fafbfc;border:1.5px dashed #e2e8f0;border-radius:10px;color:#94a3b8">
                                                <i class="fas fa-search fa-lg mb-2 d-block"></i>
                                                لا توجد نتائج مطابقة لـ "<span id="ticketNoResultsQuery" style="color:#475569;font-weight:700"></span>"
                                                <br><small>جرب بحثاً مختلفاً أو تحقق من رقم البلاغ</small>
                                            </div>
                                        </div>

                                        <!-- الحقل الخفي يُرسَل مع النموذج -->
                                        <input type="hidden" name="task_title" id="selected_task_id" required>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>الأولوية</label>
                                                <select name="priority" class="form-control">
                                                    <option value="Low">عادي</option>
                                                    <option value="Medium" selected>متوسط</option>
                                                    <option value="High">عالي (مستعجل)</option>
                                                    <option value="Urgent">حرج (توقف عمل)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>تاريخ ووقت التسليم النهائي</label>
                                                <input type="datetime-local" name="deadline" class="form-control"
                                                    required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group mt-3">
                                        <label>ملاحظات إضافية للفني</label>
                                        <textarea name="details" class="form-control" rows="3"
                                            placeholder="أدخل أي تعليمات إضافية هنا..."></textarea>
                                    </div>

                                    <div class="mt-4 pt-3 border-top text-right">
    <?php if ($can_add == 1): ?>
        <!-- الزر فعال للمصرح لهم بإسناد المهام -->
        <button type="submit" name="assign_task" class="btn btn-primary">
            <i class="fas fa-paper-plane ml-1"></i> اعتماد وإرسال المهمة
        </button>
    <?php else: ?>
        <!-- الزر معطل لمن لا يملك الصلاحية -->
        <button type="button" class="btn btn-secondary disabled" style="cursor: not-allowed;" title="ليس لديك صلاحية لاعتماد المهام">
            <i class="fas fa-user-slash ml-1"></i> اعتماد وإرسال المهمة (مقيد)
        </button>
    <?php endif; ?>
</div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php /* القائمة الثابتة محذوفة — يتم البحث عبر AJAX */ ?>
                </div>
            </section>

        </div>

        <footer class="main-footer">
            <?php include('../../main-footer.php') ?>
        </footer>

    </div>

    <!-- jQuery في الرأس — موجود بالفعل -->
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <!-- Select2 وDataTable محذوفان — تم الاستغناء عنهما بالمكتبات المخصصة -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- ══ بيانات الموظفين مُدمَجة (حجمها صغير) ══ -->
    <script>
    var EMP_DATA = <?php
        $empJson = [];
        foreach ($allUsers as $u) {
            $empJson[] = [
                'id'         => $u['id'],
                'name'       => $u['full_name'],
                'emp_id'     => $u['employee_id'] ?? '',
                'job'        => $u['job_title']   ?? '',
                'branches'   => $u['all_branches'] ?? '',
                'roles'      => $u['all_roles']    ?? '',
                'email'      => $u['email']        ?? '',
                'phone'      => $u['phone']        ?? '',
                // نص البحث يشمل: رقم الموظف + الاسم + الفرع + المسمى
                'search'     => strtolower(
                    ($u['employee_id'] ?? '') . ' ' .
                    $u['full_name'] . ' ' .
                    ($u['all_branches'] ?? '') . ' ' .
                    ($u['job_title'] ?? '') . ' ' .
                    ($u['all_roles'] ?? '')
                ),
            ];
        }
        echo json_encode($empJson, JSON_UNESCAPED_UNICODE);
    ?>;
    </script>

    <!-- ══ بحث الموظفين — فلترة لحظية على بيانات مُدمَجة ══ -->
    <script>
    (function() {
        var searchInput = document.getElementById('empSearchInput');
        var resultsBox  = document.getElementById('empResultsBox');
        var rowsCont    = document.getElementById('empRowsContainer');
        var noResults   = document.getElementById('empNoResults');
        var empBox      = document.getElementById('empSearchBox');
        var infoBox     = document.getElementById('selectedEmpInfo');
        var hiddenInput = document.getElementById('selected_emp_id');

        if (!searchInput) return;

        /* ── بناء صف HTML ── */
        function buildEmpRow(emp, isLast) {
            var initials = emp.name.split(' ').slice(0,2).map(function(w){ return w[0]||''; }).join('').toUpperCase();
            var colors   = ['#1a5276','#065f46','#7c3aed','#9a3412','#1d4ed8','#0369a1'];
            var clr      = colors[emp.id % colors.length];
            var border   = isLast ? '' : 'border-bottom:1px solid #f0f2f5;';
            var empIdBadge = emp.emp_id
                ? '<span style="background:#eff6ff;color:#1d4ed8;font-size:.65rem;font-weight:800;padding:2px 7px;border-radius:5px;font-family:monospace">' + esc(emp.emp_id) + '</span>'
                : '<span style="color:#cbd5e1;font-size:.68rem">—</span>';

            return '<div class="emp-result-row" data-id="' + emp.id + '"'
                + ' data-name="' + esc(emp.name) + '"'
                + ' data-meta="' + esc((emp.emp_id ? 'رقم: ' + emp.emp_id + ' · ' : '') + (emp.job||'') + (emp.branches ? ' · ' + emp.branches : '')) + '"'
                + ' data-initials="' + esc(initials) + '"'
                + ' data-color="' + clr + '"'
                + ' style="display:grid;grid-template-columns:50px 1fr 120px 100px 70px;gap:6px;padding:9px 12px;' + border + 'align-items:center;cursor:default;transition:.12s"'
                + ' onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'

                + '<div style="text-align:center"><div style="width:34px;height:34px;border-radius:50%;background:' + clr + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;margin:0 auto">' + esc(initials) + '</div></div>'

                + '<div style="overflow:hidden">'
                + '<div style="font-size:.78rem;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(emp.name) + '</div>'
                + '<div style="font-size:.66rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(emp.job||emp.roles||'—') + '</div>'
                + '</div>'

                + '<div style="font-size:.7rem;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(emp.branches||'—') + '</div>'

                + '<div>' + empIdBadge + '</div>'

                + '<div style="text-align:center">'
                + '<button type="button" class="emp-select-btn" data-id="' + emp.id + '"'
                + ' style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border:none;border-radius:7px;padding:5px 10px;font-size:.7rem;font-weight:700;cursor:pointer;white-space:nowrap">'
                + '<i class="fas fa-check"></i> اختيار</button>'
                + '</div>'
                + '</div>';
        }

        function esc(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        /* ── فلترة وعرض النتائج ── */
        function renderEmpResults(q) {
            var filtered = q
                ? EMP_DATA.filter(function(e){ return e.search.includes(q.toLowerCase()); })
                : EMP_DATA.slice(0, 15); // أول 15 عند الفتح بدون بحث

            rowsCont.innerHTML = '';
            noResults.style.display = 'none';

            if (filtered.length === 0) {
                noResults.style.display = 'block';
                resultsBox.style.display = 'block';
                return;
            }

            var html = '';
            filtered.slice(0, 20).forEach(function(emp, i) {
                html += buildEmpRow(emp, i === Math.min(filtered.length,20) - 1);
            });
            rowsCont.innerHTML = html;

            if (filtered.length > 20) {
                rowsCont.innerHTML += '<div style="padding:6px 12px;font-size:.72rem;color:#94a3b8;background:#fafbfc;border-top:1px solid #f0f2f5">'
                    + 'يعرض 20 من ' + filtered.length + ' — ضيّق البحث للحصول على نتائج أدق</div>';
            }

            // ربط أزرار الاختيار
            rowsCont.querySelectorAll('.emp-select-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = parseInt(this.getAttribute('data-id'));
                    var emp = EMP_DATA.find(function(e){ return e.id == id; });
                    if (!emp) return;
                    selectEmployee(emp);
                });
            });

            resultsBox.style.display = 'block';
        }

        /* ── اختيار موظف ── */
        function selectEmployee(emp) {
            // ضبط الحقل الخفي
            hiddenInput.value = emp.id;

            // تحديث مربع "الموظف المحدد"
            document.getElementById('empAvatar').textContent =
                emp.name.split(' ').slice(0,2).map(function(w){ return w[0]||''; }).join('').toUpperCase();

            var colors = ['#1a5276','#065f46','#7c3aed','#9a3412','#1d4ed8','#0369a1'];
            document.getElementById('empAvatar').style.background =
                'linear-gradient(135deg,' + colors[emp.id % colors.length] + ',#2980b9)';

            document.getElementById('selectedEmpName').textContent = emp.name;
            var meta = [];
            if (emp.emp_id) meta.push('رقم: ' + emp.emp_id);
            if (emp.job)    meta.push(emp.job);
            if (emp.branches) meta.push(emp.branches);
            document.getElementById('selectedEmpMeta').textContent = meta.join(' · ');

            infoBox.style.display = 'flex';
            empBox.style.display  = 'none';
            resultsBox.style.display = 'none';

            // Toast
            if (window.Swal) {
                Swal.fire({
                    toast:true, position:'top-end', icon:'success',
                    title:'تم اختيار الفني: ' + emp.name,
                    showConfirmButton:false, timer:1600, timerProgressBar:true
                });
            }
        }

        /* ── تغيير الموظف ── */
        window.clearEmpPicker = function() {
            hiddenInput.value = '';
            infoBox.style.display = 'none';
            empBox.style.display  = 'block';
            resultsBox.style.display = 'none';
            searchInput.value = '';
            searchInput.focus();
        };

        /* ── عرض عند الفتح ── */
        window.renderEmpResults = function(q) { renderEmpResults(q||''); };

        /* ── بحث أثناء الكتابة ── */
        searchInput.addEventListener('input', function() {
            renderEmpResults(this.value.trim());
        });

        /* ── Enter: حدد إذا نتيجة واحدة ── */
        searchInput.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var btns = rowsCont.querySelectorAll('.emp-select-btn');
            if (btns.length === 1) btns[0].click();
        });

        /* ── Ctrl+E → تركيز على البحث ── */
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                if (empBox.style.display !== 'none') {
                    searchInput.focus(); searchInput.select();
                }
            }
        });
    })();
    </script>

    <!-- ══ بحث AJAX للبلاغات — لا يُحمَّل أي بيانات حتى يكتب المستخدم ══ -->
    <script>
    (function() {
        var searchInput   = document.getElementById('ticketSearchInput');
        var spinner       = document.getElementById('ticketSpinner');
        var resultsBox    = document.getElementById('ticketResults');
        var rowsContainer = document.getElementById('ticketRowsContainer');
        var footer        = document.getElementById('ticketResultsFooter');
        var noResults     = document.getElementById('ticketNoResults');
        var noResultsQ    = document.getElementById('ticketNoResultsQuery');
        var searchBox     = document.getElementById('ticketSearchBox');
        var infoBox       = document.getElementById('selectedTaskInfo');
        var infoLabel     = document.getElementById('selectedTaskLabel');
        var hiddenInput   = document.getElementById('selected_task_id');

        var debounceTimer = null;
        var lastQuery     = null;

        if (!searchInput) return;

        /* ── بناء صف HTML — نفس هيكل قائمة الموظفين تماماً ── */
        function buildRow(item, isLast) {
            var border = isLast ? '' : 'border-bottom:1px solid #f0f2f5;';
            // شارة رقم البلاغ (مثل avatar circle للموظف)
            var numBadge = '<div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:800;font-family:monospace;margin:0 auto">#' + item.id + '</div>';

            // شارة الأولوية (مثل employee_id badge)
            var prBadge = '<span style="background:' + item.p_bg + ';color:' + item.p_color + ';padding:2px 8px;border-radius:20px;font-size:.64rem;font-weight:700;display:inline-block">' + item.priority + '</span>';
            // شارة التصنيف
            var catBadge = '<span style="background:#f1f5f9;color:#334155;padding:2px 6px;border-radius:5px;font-size:.62rem;font-weight:700;display:inline-block;margin-top:2px">' + escHtml(item.category) + '</span>';

            return '<div class="t-result-row"'
                + ' data-id="'    + item.id              + '"'
                + ' data-label="' + escHtml(item.label)  + '"'
                + ' data-branch="' + escHtml(item.branch) + '"'
                + ' data-date="'  + item.date            + '"'
                + ' style="display:grid;grid-template-columns:50px 1fr 120px 100px 70px;gap:6px;padding:9px 12px;' + border + 'align-items:center;cursor:default;transition:.12s"'
                + ' onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'

                // عمود 1: رقم البلاغ (مثل avatar)
                + '<div style="text-align:center">' + numBadge + '</div>'

                // عمود 2: المُبلِّغ + تفاصيل (مثل الاسم + المسمى)
                + '<div style="overflow:hidden">'
                +   '<div style="font-size:.78rem;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escHtml(item.reporter) + '</div>'
                +   '<div style="font-size:.66rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + escHtml(item.details) + '">' + escHtml(item.details||'—') + '</div>'
                +   '<div style="font-size:.62rem;color:#cbd5e1">' + item.date + '</div>'
                + '</div>'

                // عمود 3: الفرع (مثل الفرع للموظف)
                + '<div style="font-size:.7rem;color:#475569;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                +   escHtml(item.branch) + (item.region ? '<br><span style="color:#94a3b8;font-size:.62rem">' + escHtml(item.region) + '</span>' : '')
                + '</div>'

                // عمود 4: التصنيف + الأولوية (مثل رقم الموظف)
                + '<div>' + catBadge + '<br>' + prBadge + '</div>'

                // عمود 5: زر التحديد — نفس الأزرار تماماً
                + '<div style="text-align:center">'
                +   '<button type="button" class="t-select-btn"'
                +   ' data-id="' + item.id + '"'
                +   ' data-label="' + escHtml(item.label) + '"'
                +   ' data-branch="' + escHtml(item.branch) + '"'
                +   ' data-date="' + item.date + '"'
                +   ' style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border:none;border-radius:7px;padding:5px 10px;font-size:.7rem;font-weight:700;cursor:pointer;white-space:nowrap">'
                +   '<i class="fas fa-check"></i> اختيار</button>'
                + '</div>'
                + '</div>';
        }

        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        /* ── عرض النتائج ── */
        function renderResults(data, query) {
            spinner.style.display = 'none';

            if (!data || !data.items || data.items.length === 0) {
                resultsBox.style.display = 'none';
                noResults.style.display = 'block';
                if (noResultsQ) noResultsQ.textContent = query || '';
                return;
            }

            noResults.style.display = 'none';

            var html = '';
            data.items.forEach(function(item, i) {
                html += buildRow(item, i === data.items.length - 1);
            });
            rowsContainer.innerHTML = html;

            if (data.items.length >= 10) {
                footer.textContent = 'يعرض أول ' + data.items.length + ' نتيجة — ضيّق البحث للحصول على نتائج أدق';
                footer.style.display = 'block';
            } else {
                footer.textContent = data.items.length + ' نتيجة';
                footer.style.display = 'block';
            }

            resultsBox.style.display = 'block';

            // ربط أزرار الاختيار
            rowsContainer.querySelectorAll('.t-select-btn').forEach(function(btn) {
                btn.addEventListener('click', selectTicket);
            });
        }

        /* ── استدعاء AJAX ── */
        function loadTickets() {
            var q = searchInput.value.trim();
            if (q === lastQuery) return;
            lastQuery = q;

            clearTimeout(debounceTimer);

            if (q.length === 0) {
                // عرض آخر 10 بلاغات فوراً
                doFetch('');
                return;
            }

            if (q.length < 2) {
                resultsBox.style.display = 'none';
                noResults.style.display = 'none';
                return;
            }

            spinner.style.display = 'block';
            debounceTimer = setTimeout(function() { doFetch(q); }, 300);
        }

        function doFetch(q) {
            spinner.style.display = 'block';
            fetch('search_tickets.php?q=' + encodeURIComponent(q) + '&limit=15')
                .then(function(r) { return r.json(); })
                .then(function(data) { renderResults(data, q); })
                .catch(function() { spinner.style.display = 'none'; });
        }

        /* ── اختيار بلاغ — نفس منطق اختيار الموظف ── */
        function selectTicket() {
            var tid    = this.getAttribute('data-id');
            var label  = this.getAttribute('data-label');
            var branch = this.getAttribute('data-branch') || '';
            var date   = this.getAttribute('data-date')   || '';

            // ضبط الحقل الخفي
            hiddenInput.value = tid;

            // تحديث الشارة الدائرية برقم البلاغ
            var badge = document.getElementById('taskBadgeCircle');
            if (badge) badge.textContent = '#' + tid;

            // إظهار مربع "البلاغ المحدد" بنفس أسلوب الموظف
            document.getElementById('selectedTaskLabel').textContent = label;
            var metaEl = document.getElementById('selectedTaskMeta');
            var meta = [];
            if (branch) meta.push(branch);
            if (date)   meta.push(date);
            if (metaEl) metaEl.textContent = meta.join(' · ');

            infoBox.style.display = 'flex';
            searchBox.style.display = 'none';
            resultsBox.style.display = 'none';

            // Toast
            if (window.Swal) {
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: 'تم اختيار البلاغ #' + tid,
                    showConfirmButton: false, timer: 1800, timerProgressBar: true
                });
            }
        }

        /* ── تغيير البلاغ — نفس clearEmpPicker ── */
        window.clearTicketPicker = function() {
            hiddenInput.value = '';
            infoBox.style.display = 'none';
            searchBox.style.display = 'block';
            searchInput.value = '';
            resultsBox.style.display = 'none';
            noResults.style.display = 'none';
            lastQuery = null;
            var badge = document.getElementById('taskBadgeCircle');
            if (badge) badge.textContent = '';
            searchInput.focus();
        };

        /* ── ربط الأحداث ── */
        searchInput.addEventListener('input', loadTickets);

        // Enter → حدد إذا نتيجة واحدة
        searchInput.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var btns = rowsContainer.querySelectorAll('.t-select-btn');
            if (btns.length === 1) btns[0].click();
        });

        // إغلاق النتائج عند النقر خارجاً — مع استثناء زر تبديل الشريط الجانبي
        document.addEventListener('click', function(e) {
            // تجاهل النقر على زر تبديل الشريط الجانبي أو الترويسة الرئيسية
            var isNavBtn = e.target.closest('[data-widget="pushmenu"], .main-header, .main-sidebar');
            if (isNavBtn) return;

            var picker = document.getElementById('ticketPickerWrap');
            if (picker && !picker.contains(e.target)) {
                resultsBox.style.display = 'none';
            }
        });
        // ── عند التركيز: حمّل البلاغات فوراً من أول نقرة ──
        searchInput.addEventListener('focus', function() {
            if (!rowsContainer.innerHTML) {
                // أول مرة → جلب جميع البلاغات
                lastQuery = null;
                doFetch('');
            } else {
                // زيارة لاحقة → أظهر النتائج المحفوظة مباشرة
                resultsBox.style.display = 'block';
            }
        });

        // Ctrl+F → تركيز على البحث
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                searchInput.focus(); searchInput.select();
            }
        });
    })();
    </script>
</body>

</html>