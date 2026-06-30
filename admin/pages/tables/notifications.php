<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

// ── إنشاء جدول إعدادات الإشعارات إن لم يكن موجوداً ──
$pdo->exec("
    CREATE TABLE IF NOT EXISTS notification_settings (
        id           INT(11)      NOT NULL AUTO_INCREMENT,
        person_type  ENUM('sys_user','employee') NOT NULL DEFAULT 'sys_user',
        person_id    INT(11)      NOT NULL,
        is_active    TINYINT(1)   NOT NULL DEFAULT 1,
        notify_email TINYINT(1)   NOT NULL DEFAULT 1,
        notify_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
        updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_person (person_type, person_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── معالجة حفظ الإعداد عبر AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $type       = in_array($_POST['person_type'] ?? '', ['sys_user','employee']) ? $_POST['person_type'] : 'sys_user';
    $pid        = (int)($_POST['person_id'] ?? 0);
    $active     = isset($_POST['is_active'])    ? 1 : 0;
    $email      = isset($_POST['notify_email']) ? 1 : 0;
    $whatsapp   = isset($_POST['notify_whatsapp']) ? 1 : 0;

    if ($pid <= 0) { echo json_encode(['ok'=>false,'msg'=>'معرّف غير صالح']); exit; }

    try {
        $pdo->prepare("
            INSERT INTO notification_settings (person_type, person_id, is_active, notify_email, notify_whatsapp)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), notify_email=VALUES(notify_email),
                                    notify_whatsapp=VALUES(notify_whatsapp)
        ")->execute([$type, $pid, $active, $email, $whatsapp]);
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── حفظ الكل دفعة واحدة ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    $rows = $_POST['rows'] ?? [];
    foreach ($rows as $row) {
        $type     = in_array($row['person_type'] ?? '', ['sys_user','employee']) ? $row['person_type'] : 'sys_user';
        $pid      = (int)($row['person_id'] ?? 0);
        $active   = ($row['is_active']       ?? '0') === '1' ? 1 : 0;
        $email    = ($row['notify_email']    ?? '0') === '1' ? 1 : 0;
        $whatsapp = ($row['notify_whatsapp'] ?? '0') === '1' ? 1 : 0;
        if ($pid <= 0) continue;
        $pdo->prepare("
            INSERT INTO notification_settings (person_type,person_id,is_active,notify_email,notify_whatsapp)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),notify_email=VALUES(notify_email),notify_whatsapp=VALUES(notify_whatsapp)
        ")->execute([$type,$pid,$active,$email,$whatsapp]);
    }
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الحفظ',text:'تم حفظ إعدادات الإشعارات بنجاح'}));window.location.href='notifications.php';</script>";
    exit;
}

// ── جلب إعدادات الإشعارات الحالية ──
$settingsRows = $pdo->query("SELECT person_type, person_id, is_active, notify_email, notify_whatsapp FROM notification_settings")->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settingsRows as $s) {
    $settings[$s['person_type'].'_'.$s['person_id']] = $s;
}
function getSetting($settings, $type, $id, $field, $default=1) {
    $key = $type.'_'.$id;
    return isset($settings[$key]) ? (int)$settings[$key][$field] : $default;
}

// ── جلب مستخدمي النظام ──
$sysUsers = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.phone, u.job_title, u.status, u.file_path,
           COALESCE(r.role_name,'') AS role_name
    FROM sys_users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN sys_roles r ON ur.role_id = r.id
    WHERE u.id != " . (int)$current_user_id . "
    GROUP BY u.id
    ORDER BY u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── جلب الموظفين (dms_employees) غير المرتبطين بحساب نظام ──
$dmsEmployees = $pdo->query("
    SELECT e.id, e.full_name, e.email, e.phone, e.job_title, e.department, e.emp_code,
           e.is_active AS emp_active
    FROM dms_employees e
    WHERE (e.user_id IS NULL OR e.user_id NOT IN (SELECT id FROM sys_users))
      AND e.full_name IS NOT NULL AND e.full_name != ''
    ORDER BY e.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── إحصاءات ──
$totalPeople      = count($sysUsers) + count($dmsEmployees);
$activeNotif      = count(array_filter(array_merge($sysUsers,$dmsEmployees), fn($u) => getSetting($settings, isset($u['emp_code'])?'employee':'sys_user', $u['id'], 'is_active', 1) == 1));
$withEmail        = count(array_filter(array_merge($sysUsers,$dmsEmployees), fn($u) => !empty($u['email'])));
$withPhone        = count(array_filter(array_merge($sysUsers,$dmsEmployees), fn($u) => !empty($u['phone'])));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إعدادات الإشعارات</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ══ بطاقات الإحصاء ══ */
.notif-stat {
    background:#fff;border-radius:14px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    padding:18px 20px;border:1px solid #f0f2f7;
    display:flex;align-items:center;gap:14px;
}
.notif-stat .ns-icon {
    width:50px;height:50px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.25rem;color:#fff;flex-shrink:0;
}
.notif-stat .ns-val {font-size:1.7rem;font-weight:800;line-height:1;}
.notif-stat .ns-lbl {font-size:.75rem;color:#888;margin-top:2px;}

/* ══ شريط الأدوات ══ */
.notif-toolbar {
    background:#fff;border-radius:12px;padding:12px 18px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:10px;margin-bottom:18px;
    border:1px solid #f0f2f7;
}
.notif-search {
    display:flex;align-items:center;gap:8px;
    border:1.5px solid #e2e8f0;border-radius:8px;
    padding:6px 12px;background:#fff;flex:1;max-width:300px;
}
.notif-search input {border:none;outline:none;font-size:.85rem;flex:1;background:transparent;color:#334155;}
.notif-search i {color:#94a3b8;font-size:.85rem;}

/* ══ بطاقة القسم ══ */
.notif-section {
    background:#fff;border-radius:16px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    margin-bottom:24px;overflow:hidden;
    border:1px solid #f0f2f7;
}
.notif-section-head {
    padding:14px 20px;
    /* الأولوية: page-bar-from (ذكي) → sidebar (دائماً داكن) → fallback ثابت */
    background:linear-gradient(135deg,
        var(--crm-page-bar-from, var(--crm-sidebar-bg, #1a5276)),
        var(--crm-page-bar-to,   var(--crm-sidebar-bg, #2980b9)));
    display:flex;align-items:center;gap:12px;
    /* ضمان أن الخلفية دائماً داكنة كافية لقراءة النص الأبيض */
    color:#fff;
}
.notif-section-head .s-icon {
    width:34px;height:34px;background:rgba(255,255,255,.2);
    border-radius:8px;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:14px;flex-shrink:0;
}
.notif-section-head h5  {margin:0;color:#fff !important;font-weight:700;font-size:.9rem;}
.notif-section-head small {color:rgba(255,255,255,.85) !important;font-size:.75rem;}
.notif-section-head .sec-badge {
    background:rgba(255,255,255,.2);color:#fff !important;
    font-size:.7rem;font-weight:700;padding:2px 10px;border-radius:20px;margin-right:auto;
}

/* ══ بطاقة الموظف ══ */
.person-row {
    display:grid;
    grid-template-columns: 52px 1fr 160px 160px 140px;
    align-items:center;
    padding:14px 20px;
    border-bottom:1px solid #f8fafc;
    gap:12px;
    transition:.15s;
}
.person-row:last-child{border-bottom:none}
.person-row:hover{background:#fafbfc;}

/* أفاتار */
.person-avatar {
    width:46px;height:46px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:1rem;font-weight:800;color:#fff;
    flex-shrink:0;border:2px solid rgba(255,255,255,.5);
}
.person-info .p-name {font-size:.88rem;font-weight:700;color:#1e293b;margin-bottom:2px;}
.person-info .p-meta {font-size:.75rem;color:#64748b;display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.person-info .p-meta i {font-size:.7rem;margin-left:3px;}
.person-info .p-meta a {color:#2563eb;text-decoration:none;}
.person-info .p-meta a:hover{text-decoration:underline;}

/* بيانات التواصل */
.contact-cell {font-size:.78rem;color:#475569;}
.contact-cell .contact-link {
    display:flex;align-items:center;gap:6px;
    padding:4px 8px;border-radius:7px;transition:.15s;
    text-decoration:none;color:inherit;
    border:1px solid #e2e8f0;background:#f8fafc;
    margin-bottom:4px;
}
.contact-link:hover{background:#eff6ff;border-color:#bfdbfe;}
.contact-link i {font-size:.75rem;flex-shrink:0;}
.contact-link.email-lnk i {color:#2563eb;}
.contact-link.whatsapp-lnk i {color:#25d366;}
.no-contact {color:#cbd5e1;font-size:.74rem;display:flex;align-items:center;gap:4px;}

/* ── تبديل الإشعارات ── */
.toggle-wrap {display:flex;align-items:center;justify-content:center;}
.notif-toggle {
    position:relative;width:46px;height:24px;
    display:inline-block;flex-shrink:0;
}
.notif-toggle input {opacity:0;width:0;height:0;}
.notif-slider {
    position:absolute;cursor:pointer;
    inset:0;border-radius:34px;
    background:#e2e8f0;transition:.25s;
}
.notif-slider::before {
    position:absolute;content:'';
    height:18px;width:18px;left:3px;bottom:3px;
    border-radius:50%;background:#fff;transition:.25s;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.notif-toggle input:checked + .notif-slider { background:var(--crm-page-bar-from,#1a5276); }
.notif-toggle input:checked + .notif-slider::before { transform:translateX(22px); }

/* ── خانات اختيار طريقة الإشعار ── */
.method-cell {display:flex;flex-direction:column;gap:6px;align-items:flex-start;}
.method-check {
    display:flex;align-items:center;gap:7px;
    font-size:.78rem;cursor:pointer;user-select:none;
}
.method-check input[type=checkbox] {
    width:15px;height:15px;accent-color:var(--crm-primary,#1a5276);cursor:pointer;
}
.method-check .mc-email   { color:#2563eb; }
.method-check .mc-whatsapp { color:#25d366; }

/* ── شارة الحالة ── */
.status-dot {
    display:inline-block;width:7px;height:7px;
    border-radius:50%;margin-left:4px;vertical-align:middle;
}
.status-active   {background:#22c55e;}
.status-inactive {background:#e2e8f0;}

/* ── أزرار ── */
.btn-save-all {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    color:#fff;border:none;border-radius:10px;
    padding:10px 26px;font-weight:700;font-size:.88rem;
    display:inline-flex;align-items:center;gap:8px;
    transition:.2s;cursor:pointer;
    box-shadow:0 4px 14px rgba(26,82,118,.3);
}
.btn-save-all:hover{opacity:.9;transform:translateY(-1px);}
.btn-enable-all, .btn-disable-all {
    border:none;border-radius:8px;padding:7px 14px;font-size:.78rem;font-weight:700;
    cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:6px;
}
.btn-enable-all  {background:#d1fae5;color:#065f46;}
.btn-disable-all {background:#f1f5f9;color:#475569;}
.btn-enable-all:hover  {background:#a7f3d0;}
.btn-disable-all:hover {background:#e2e8f0;}

/* ── رسالة حفظ تلقائي ── */
.autosave-indicator {
    position:fixed;bottom:20px;left:20px;
    background:#1e293b;color:#fff;
    border-radius:8px;padding:8px 16px;
    font-size:.78rem;display:none;z-index:9999;
    animation:fadeIn .2s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}

/* ── إعادة ضبط - مسؤولية ── */
@media(max-width:900px){
    .person-row{grid-template-columns:46px 1fr;grid-template-rows:auto auto;row-gap:8px;}
    .person-row > .contact-cell {grid-column:2;}
    .person-row > .toggle-wrap  {grid-column:1/3;justify-content:flex-start;}
    .person-row > .method-cell  {grid-column:1/3;flex-direction:row;}
}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

    <!-- ── الترويسة ── -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-bell ml-2"></i>إعدادات الإشعارات</h4>
                    <small>تفعيل وربط الإشعارات مع المستخدمين والموظفين — تحديد طريقة الإشعار</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">إعدادات الإشعارات</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- ══ إحصاءات ══ -->
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3">
                <div class="notif-stat">
                    <div class="ns-icon" style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9))">
                        <i class="fas fa-users"></i>
                    </div>
                    <div><div class="ns-val"><?= $totalPeople ?></div><div class="ns-lbl">إجمالي الأشخاص</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="notif-stat">
                    <div class="ns-icon" style="background:linear-gradient(135deg,#065f46,#059669)">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div><div class="ns-val"><?= $activeNotif ?></div><div class="ns-lbl">إشعارات مُفعَّلة</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="notif-stat">
                    <div class="ns-icon" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div><div class="ns-val"><?= $withEmail ?></div><div class="ns-lbl">لديهم بريد إلكتروني</div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3 mb-3">
                <div class="notif-stat">
                    <div class="ns-icon" style="background:linear-gradient(135deg,#065f46,#25d366)">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <div><div class="ns-val"><?= $withPhone ?></div><div class="ns-lbl">لديهم رقم جوال</div></div>
                </div>
            </div>
        </div>

        <!-- ══ شريط الأدوات ══ -->
        <div class="notif-toolbar">
            <div class="notif-search">
                <i class="fas fa-search"></i>
                <input type="text" id="personSearch" placeholder="ابحث باسم الموظف أو البريد...">
            </div>
            <div class="d-flex gap-2" style="gap:8px;align-items:center">
                <button type="button" class="btn-enable-all" onclick="setAllActive(true)">
                    <i class="fas fa-toggle-on"></i>تفعيل الكل
                </button>
                <button type="button" class="btn-disable-all" onclick="setAllActive(false)">
                    <i class="fas fa-toggle-off"></i>إيقاف الكل
                </button>
            </div>
        </div>

        <form id="notifForm" method="POST">
            <input type="hidden" name="save_all" value="1">

            <?php
            // تهيئة خارج الحلقتين لتجنب "undefined variable" عند غياب أحد القسمين
            $avatarColors = ['#1a5276','#065f46','#7c3aed','#9a3412','#1d4ed8','#0369a1','#059669'];
            $ci = 0;
            ?>

            <!-- ══ مستخدمو النظام ══ -->
            <?php if (!empty($sysUsers)): ?>
            <div class="notif-section">
                <div class="notif-section-head">
                    <div class="s-icon"><i class="fas fa-user-shield"></i></div>
                    <div>
                        <h5>مستخدمو النظام</h5>
                        <small>حسابات تسجيل الدخول — إشعارات البلاغات والمهام</small>
                    </div>
                    <span class="sec-badge"><?= count($sysUsers) ?> مستخدم</span>
                </div>

                <!-- رأس الأعمدة -->
                <div style="display:grid;grid-template-columns:52px 1fr 160px 160px 140px;gap:12px;padding:10px 20px 6px;background:#fafbfc;border-bottom:2px solid #f0f2f7;">
                    <div></div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase">بيانات الموظف</div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase">البريد الإلكتروني</div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase">رقم الجوال (واتساب)</div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase;text-align:center">تفعيل · الطريقة</div>
                </div>

                <?php foreach ($sysUsers as $u):
                    $key   = 'sys_user_'.$u['id'];
                    $isAct = getSetting($settings, 'sys_user', $u['id'], 'is_active', 1);
                    $nEmail= getSetting($settings, 'sys_user', $u['id'], 'notify_email', 1);
                    $nWa   = getSetting($settings, 'sys_user', $u['id'], 'notify_whatsapp', 0);
                    $initials = mb_substr($u['full_name'],0,1,'UTF-8') . (str_word_count($u['full_name'])>1 ? mb_substr(explode(' ',$u['full_name'])[1],0,1,'UTF-8') : '');
                    $clr = $avatarColors[$ci++ % count($avatarColors)];
                    $phone = trim($u['phone'] ?? '');
                    $email = trim($u['email'] ?? '');
                    $rowIdx = "sys_{$u['id']}";
                ?>
                <div class="person-row notif-row" data-search="<?= strtolower(htmlspecialchars($u['full_name'].' '.$email.' '.$phone)) ?>">
                    <!-- أفاتار -->
                    <div class="person-avatar" style="background:<?= $clr ?>"><?= htmlspecialchars(mb_strtoupper($initials,'UTF-8')) ?></div>

                    <!-- معلومات -->
                    <div class="person-info">
                        <div class="p-name">
                            <span class="status-dot <?= $u['status']==='active'?'status-active':'status-inactive' ?>"></span>
                            <?= htmlspecialchars($u['full_name']) ?>
                        </div>
                        <div class="p-meta">
                            <?php if ($u['role_name']): ?>
                            <span style="background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:10px;font-size:.68rem;font-weight:700"><?= htmlspecialchars($u['role_name']) ?></span>
                            <?php endif; ?>
                            <?php if ($u['job_title']): ?>
                            <span><i class="fas fa-briefcase"></i><?= htmlspecialchars($u['job_title']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- البريد -->
                    <div class="contact-cell">
                        <?php if ($email): ?>
                        <a href="mailto:<?= htmlspecialchars($email) ?>" class="contact-link email-lnk" dir="ltr">
                            <i class="fas fa-envelope"></i>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px"><?= htmlspecialchars($email) ?></span>
                        </a>
                        <?php else: ?>
                        <span class="no-contact"><i class="fas fa-minus-circle"></i>لا يوجد بريد</span>
                        <?php endif; ?>
                    </div>

                    <!-- الجوال -->
                    <div class="contact-cell">
                        <?php if ($phone): ?>
                        <a href="https://wa.me/<?= preg_replace('/\D/','',$phone) ?>" target="_blank" class="contact-link whatsapp-lnk" dir="ltr">
                            <i class="fab fa-whatsapp"></i>
                            <span><?= htmlspecialchars($phone) ?></span>
                        </a>
                        <a href="tel:<?= htmlspecialchars($phone) ?>" class="contact-link" dir="ltr" style="margin-top:2px">
                            <i class="fas fa-phone" style="color:#64748b"></i>
                            <span style="color:#64748b"><?= htmlspecialchars($phone) ?></span>
                        </a>
                        <?php else: ?>
                        <span class="no-contact"><i class="fas fa-minus-circle"></i>لا يوجد جوال</span>
                        <?php endif; ?>
                    </div>

                    <!-- التفعيل والطريقة -->
                    <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
                        <!-- تبديل التفعيل -->
                        <div class="toggle-wrap">
                            <label class="notif-toggle" title="تفعيل/إيقاف الإشعارات">
                                <input type="checkbox" name="rows[<?= $rowIdx ?>][is_active]" value="1"
                                    <?= $isAct?'checked':'' ?>
                                    data-row="<?= $rowIdx ?>" class="toggle-input"
                                    onchange="autoSave('sys_user',<?= $u['id'] ?>,this)">
                                <span class="notif-slider"></span>
                            </label>
                            <input type="hidden" name="rows[<?= $rowIdx ?>][person_type]" value="sys_user">
                            <input type="hidden" name="rows[<?= $rowIdx ?>][person_id]"   value="<?= $u['id'] ?>">
                        </div>
                        <!-- طريقة الإشعار -->
                        <div class="method-cell">
                            <label class="method-check">
                                <input type="checkbox" name="rows[<?= $rowIdx ?>][notify_email]" value="1"
                                    <?= $nEmail?'checked':'' ?> onchange="autoSave('sys_user',<?= $u['id'] ?>,this)">
                                <i class="fas fa-envelope mc-email"></i><span>إيميل</span>
                            </label>
                            <label class="method-check">
                                <input type="checkbox" name="rows[<?= $rowIdx ?>][notify_whatsapp]" value="1"
                                    <?= $nWa?'checked':'' ?> onchange="autoSave('sys_user',<?= $u['id'] ?>,this)">
                                <i class="fab fa-whatsapp mc-whatsapp"></i><span>واتساب</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ══ الموظفون (dms_employees) ══ -->
            <?php if (!empty($dmsEmployees)): ?>
            <div class="notif-section">
                <div class="notif-section-head" style="background:linear-gradient(135deg,#065f46,#059669)">
                    <div class="s-icon"><i class="fas fa-id-card"></i></div>
                    <div>
                        <h5>الموظفون (بدون حساب نظام)</h5>
                        <small>موظفو نظام إدارة الوثائق</small>
                    </div>
                    <span class="sec-badge"><?= count($dmsEmployees) ?> موظف</span>
                </div>

                <!-- رأس الأعمدة -->
                <div style="display:grid;grid-template-columns:52px 1fr 160px 160px 140px;gap:12px;padding:10px 20px 6px;background:#fafbfc;border-bottom:2px solid #f0f2f7;">
                    <div></div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase">بيانات الموظف</div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase">البريد الإلكتروني</div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase">رقم الجوال (واتساب)</div>
                    <div style="font-size:.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase;text-align:center">تفعيل · الطريقة</div>
                </div>

                <?php
                foreach ($dmsEmployees as $e):
                    $isAct = getSetting($settings, 'employee', $e['id'], 'is_active', 1);
                    $nEmail= getSetting($settings, 'employee', $e['id'], 'notify_email', 1);
                    $nWa   = getSetting($settings, 'employee', $e['id'], 'notify_whatsapp', 0);
                    $initials = mb_substr($e['full_name'],0,1,'UTF-8') . (str_word_count($e['full_name'])>1 ? mb_substr(explode(' ',$e['full_name'])[1],0,1,'UTF-8') : '');
                    $clr = $avatarColors[$ci++ % count($avatarColors)];
                    $phone = trim($e['phone'] ?? '');
                    $email = trim($e['email'] ?? '');
                    $rowIdx = "emp_{$e['id']}";
                ?>
                <div class="person-row notif-row" data-search="<?= strtolower(htmlspecialchars($e['full_name'].' '.$email.' '.$phone)) ?>">
                    <div class="person-avatar" style="background:<?= $clr ?>"><?= htmlspecialchars(mb_strtoupper($initials,'UTF-8')) ?></div>

                    <div class="person-info">
                        <div class="p-name">
                            <span class="status-dot <?= ($e['emp_active']??1)?'status-active':'status-inactive' ?>"></span>
                            <?= htmlspecialchars($e['full_name']) ?>
                        </div>
                        <div class="p-meta">
                            <?php if (!empty($e['emp_code'])): ?>
                            <span style="background:#f1f5f9;color:#475569;padding:1px 7px;border-radius:10px;font-size:.68rem;font-weight:700;font-family:monospace"><?= htmlspecialchars($e['emp_code']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($e['job_title'])): ?>
                            <span><i class="fas fa-briefcase"></i><?= htmlspecialchars($e['job_title']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($e['department'])): ?>
                            <span><i class="fas fa-building"></i><?= htmlspecialchars($e['department']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="contact-cell">
                        <?php if ($email): ?>
                        <a href="mailto:<?= htmlspecialchars($email) ?>" class="contact-link email-lnk" dir="ltr">
                            <i class="fas fa-envelope"></i>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px"><?= htmlspecialchars($email) ?></span>
                        </a>
                        <?php else: ?>
                        <span class="no-contact"><i class="fas fa-minus-circle"></i>لا يوجد بريد</span>
                        <?php endif; ?>
                    </div>

                    <div class="contact-cell">
                        <?php if ($phone): ?>
                        <a href="https://wa.me/<?= preg_replace('/\D/','',$phone) ?>" target="_blank" class="contact-link whatsapp-lnk" dir="ltr">
                            <i class="fab fa-whatsapp"></i>
                            <span><?= htmlspecialchars($phone) ?></span>
                        </a>
                        <a href="tel:<?= htmlspecialchars($phone) ?>" class="contact-link" dir="ltr" style="margin-top:2px">
                            <i class="fas fa-phone" style="color:#64748b"></i>
                            <span style="color:#64748b"><?= htmlspecialchars($phone) ?></span>
                        </a>
                        <?php else: ?>
                        <span class="no-contact"><i class="fas fa-minus-circle"></i>لا يوجد جوال</span>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
                        <div class="toggle-wrap">
                            <label class="notif-toggle" title="تفعيل/إيقاف الإشعارات">
                                <input type="checkbox" name="rows[<?= $rowIdx ?>][is_active]" value="1"
                                    <?= $isAct?'checked':'' ?> class="toggle-input"
                                    onchange="autoSave('employee',<?= $e['id'] ?>,this)">
                                <span class="notif-slider"></span>
                            </label>
                            <input type="hidden" name="rows[<?= $rowIdx ?>][person_type]" value="employee">
                            <input type="hidden" name="rows[<?= $rowIdx ?>][person_id]"   value="<?= $e['id'] ?>">
                        </div>
                        <div class="method-cell">
                            <label class="method-check">
                                <input type="checkbox" name="rows[<?= $rowIdx ?>][notify_email]" value="1"
                                    <?= $nEmail?'checked':'' ?> onchange="autoSave('employee',<?= $e['id'] ?>,this)">
                                <i class="fas fa-envelope mc-email"></i><span>إيميل</span>
                            </label>
                            <label class="method-check">
                                <input type="checkbox" name="rows[<?= $rowIdx ?>][notify_whatsapp]" value="1"
                                    <?= $nWa?'checked':'' ?> onchange="autoSave('employee',<?= $e['id'] ?>,this)">
                                <i class="fab fa-whatsapp mc-whatsapp"></i><span>واتساب</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($sysUsers) && empty($dmsEmployees)): ?>
            <div style="text-align:center;padding:60px;background:#fff;border-radius:14px;color:#94a3b8">
                <i class="fas fa-users fa-3x mb-3 d-block"></i>
                <h5>لا يوجد مستخدمون أو موظفون</h5>
            </div>
            <?php endif; ?>

            <!-- ── زر الحفظ الكلي ── -->
            <?php if (!empty($sysUsers) || !empty($dmsEmployees)): ?>
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn-save-all">
                    <i class="fas fa-save"></i>حفظ جميع الإعدادات
                </button>
            </div>
            <?php endif; ?>
        </form>

        <!-- ══ قنوات الإشعارات التلقائية ══ -->
        <div style="background:#fff;border-radius:16px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden;margin-top:24px;border:1px solid #f0f2f7">
            <div style="padding:14px 20px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));display:flex;align-items:center;gap:12px">
                <div style="width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px"><i class="fas fa-bolt"></i></div>
                <div>
                    <h5 style="margin:0;color:#fff;font-weight:700;font-size:.9rem">الإشعارات التلقائية — قنوات الإرسال</h5>
                    <small style="color:rgba(255,255,255,.8)">يتم الإرسال تلقائياً عند الأحداث التالية</small>
                </div>
            </div>
            <div style="padding:22px">
                <div class="row">
                    <!-- حدث 1: إسناد مهمة -->
                    <div class="col-md-6 mb-4">
                        <div style="border:1.5px solid #e2e8f0;border-radius:12px;padding:16px">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                                <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#1d4ed8,#3b82f6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem"><i class="fas fa-tasks"></i></div>
                                <div>
                                    <div style="font-weight:700;color:#1e293b;font-size:.88rem">إسناد مهمة للفني</div>
                                    <div style="font-size:.72rem;color:#64748b">عند إسناد مهمة جديدة عبر صفحة إسناد المهام</div>
                                </div>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                <span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700"><i class="fas fa-comments ml-1"></i>دردشة داخلية ✓ دائماً</span>
                                <span style="background:#f1f5f9;color:#94a3b8;padding:3px 10px;border-radius:20px;font-size:.72rem"><i class="fab fa-whatsapp ml-1"></i>واتساب (API مطلوب)</span>
                                <span style="background:#f1f5f9;color:#94a3b8;padding:3px 10px;border-radius:20px;font-size:.72rem"><i class="fas fa-envelope ml-1"></i>إيميل (API مطلوب)</span>
                            </div>
                        </div>
                    </div>
                    <!-- حدث 2: طلب اعتماد وثيقة -->
                    <div class="col-md-6 mb-4">
                        <div style="border:1.5px solid #e2e8f0;border-radius:12px;padding:16px">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                                <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#5b21b6,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem"><i class="fas fa-file-signature"></i></div>
                                <div>
                                    <div style="font-weight:700;color:#1e293b;font-size:.88rem">طلب اعتماد وثيقة</div>
                                    <div style="font-size:.72rem;color:#64748b">عند رفع وثيقة مرتبطة بسياسة اعتماد</div>
                                </div>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                <span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700"><i class="fas fa-comments ml-1"></i>دردشة داخلية ✓ دائماً</span>
                                <span style="background:#f1f5f9;color:#94a3b8;padding:3px 10px;border-radius:20px;font-size:.72rem"><i class="fab fa-whatsapp ml-1"></i>واتساب (API مطلوب)</span>
                                <span style="background:#f1f5f9;color:#94a3b8;padding:3px 10px;border-radius:20px;font-size:.72rem"><i class="fas fa-envelope ml-1"></i>إيميل (API مطلوب)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:12px 16px;font-size:.82rem;color:#1d4ed8">
                    <i class="fas fa-info-circle ml-1"></i>
                    <strong>الدردشة الداخلية مُفعَّلة تلقائياً</strong> — الإشعارات تظهر فوراً في <a href="../../contact.php" style="color:#1d4ed8;font-weight:700;text-decoration:underline">صفحة المحادثات</a> للمستخدم المعني.
                    لتفعيل الواتساب والإيميل الخارجيَّين، تحتاج إلى ربط API في إعدادات النظام.
                </div>
            </div>
        </div>

    </div>
    </section>
</div>

<!-- مؤشر الحفظ التلقائي -->
<div id="autosaveIndicator" class="autosave-indicator">
    <i class="fas fa-spinner fa-spin ml-1"></i> جاري الحفظ...
</div>

<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── بحث لحظي ──
document.getElementById('personSearch').addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('.notif-row').forEach(function(row) {
        var search = row.getAttribute('data-search') || '';
        row.style.display = (!q || search.includes(q)) ? '' : 'none';
    });
});

// ── تفعيل/إيقاف الكل ──
function setAllActive(state) {
    document.querySelectorAll('.toggle-input').forEach(function(chk) {
        chk.checked = state;
    });
    showSaved('تم ' + (state ? 'تفعيل' : 'إيقاف') + ' جميع الإشعارات — اضغط "حفظ جميع الإعدادات" للتطبيق', false);
}

// ── حفظ تلقائي فردي ──
var saveTimer = null;
function autoSave(personType, personId, el) {
    // جمع بيانات الصف الحالي
    var row = el.closest('.person-row');
    var toggleInput = row.querySelector('.toggle-input');
    var emailInput  = row.querySelector('input[name*="notify_email"]');
    var waInput     = row.querySelector('input[name*="notify_whatsapp"]');

    var data = new FormData();
    data.append('ajax_save', '1');
    data.append('person_type', personType);
    data.append('person_id', personId);
    if (toggleInput && toggleInput.checked) data.append('is_active', '1');
    if (emailInput  && emailInput.checked)  data.append('notify_email', '1');
    if (waInput     && waInput.checked)     data.append('notify_whatsapp', '1');

    // مؤشر بصري
    var ind = document.getElementById('autosaveIndicator');
    ind.style.display = 'block';
    clearTimeout(saveTimer);

    fetch('notifications.php', { method:'POST', body: data })
        .then(r => r.json())
        .then(res => {
            if (res.ok) showSaved('تم حفظ إعداد ' + (personType === 'sys_user' ? 'المستخدم' : 'الموظف') + ' بنجاح');
            else showSaved('فشل الحفظ: ' + (res.msg || ''), true);
        })
        .catch(() => {})
        .finally(() => {
            saveTimer = setTimeout(() => { ind.style.display = 'none'; }, 1500);
        });
}

function showSaved(msg, isError) {
    var ind = document.getElementById('autosaveIndicator');
    ind.innerHTML = '<i class="fas fa-' + (isError ? 'times text-danger' : 'check text-success') + ' ml-1"></i> ' + msg;
    ind.style.display = 'block';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { ind.style.display = 'none'; }, 2500);
}
</script>
</body>
</html>
