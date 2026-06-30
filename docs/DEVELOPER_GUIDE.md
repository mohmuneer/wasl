# دليل المطوّر — نظام وَصْل CRM
**الإصدار:** 2.0 | **آخر تحديث:** 2026-06-26

---

## 1. نظرة عامة على المشروع

نظام **وَصْل** هو نظام CRM/Helpdesk داخلي مبني بـ PHP 8 + MySQL + AdminLTE 3، يتضمن:

| الوحدة | الوصف |
|--------|--------|
| Helpdesk (Tickets) | استقبال البلاغات وتعيينها للفنيين |
| Task Management | إدارة المهام وسير العمل |
| DMS | نظام إدارة الوثائق مع سير الاعتماد |
| Asset Management | تتبع الأصول والصيانة الدورية |
| Knowledge Base | قاعدة المعرفة والمقالات الداخلية |
| Analytics Dashboard | لوحة تحليلية بـ Chart.js |
| Internal Chat | نظام مراسلة داخلية مع إشعارات |
| AI Assistant | مساعد ذكاء اصطناعي يجيب على أسئلة البيانات |

---

## 2. متطلبات البيئة

```
PHP     >= 8.1
MySQL   >= 5.7  أو  MariaDB >= 10.4
Extensions: pdo_mysql, mbstring, fileinfo, intl (اختياري)
Composer >= 2.x
```

### المكتبات الخارجية (PHP)
```json
"setasign/fpdi-fpdf": "2.3"   // توليد PDF
"ezyang/htmlpurifier": "^4.19" // تطهير HTML من Summernote
```

### المكتبات الخارجية (Frontend - CDN)
```
AdminLTE 3.2       // إطار واجهة المستخدم
Bootstrap 4 RTL    // cdn.rtlcss.com
jQuery 3.x         // داخلي في plugins/
DataTables 1.13.6  // جداول البيانات
Summernote         // محرر النصوص (plugins/summernote/)
SweetAlert2 v11    // نوافذ التأكيد
Chart.js v4        // الرسوم البيانية
Select2            // قوائم البحث
QRCode.js          // توليد QR
```

---

## 3. هيكل المجلدات

```
UltimatesolutionsCrm/
│
├── admin/                      # واجهة الإدارة الرئيسية
│   ├── index.php               # لوحة التحكم التحليلية
│   ├── main-header.php         # Header موحّد (CSS vars + CSRF injection)
│   ├── main-sidebar.php        # القائمة الجانبية الديناميكية
│   ├── main-footer.php         # Footer + Scripts
│   │
│   ├── pages/
│   │   ├── tables/             # صفحات العرض والإدارة (DataTables)
│   │   └── forms/              # نماذج الإضافة والتعديل
│   │
│   ├── api/                    # نقاط API داخلية (JSON)
│   │   └── documents_dt.php    # Server-Side DataTables للوثائق
│   │
│   ├── lang/                   # ملفات الترجمة
│   │   ├── ar.php
│   │   ├── en.php
│   │   └── init.php            # تحديد اللغة النشطة
│   │
│   ├── dist/                   # AdminLTE assets (CSS/JS/fonts)
│   ├── plugins/                # مكتبات JS محلية
│   └── uploads/                # ملفات مرفوعة (خارج git)
│
├── auth/
│   └── login.php               # صفحة تسجيل الدخول
│
├── config/
│   ├── db.php                  # اتصال DB + تهيئة Security
│   ├── tables.php              # ثوابت أسماء الجداول (TBL_*)
│   └── notify.php              # بيانات مزودي الإشعارات (SMS/WA/Email)
│
├── core/
│   ├── Auth.php                # CSRF + Brute Force + Session
│   ├── Database.php            # PDO Singleton + paginate() + transactions
│   ├── Cache.php               # APCu/File cache + remember()
│   ├── Notify.php              # SMS + WhatsApp + Email + internalMessage()
│   ├── Security.php            # CSRF auto-validate + HTML sanitize + upload validate
│   └── SlaCalculator.php       # حساب SLA مع أيام العمل والعطل
│
├── vendor/                     # Composer packages
├── storage/
│   ├── cache/                  # ملفات الكاش
│   └── purifier_cache/         # HTMLPurifier cache
│
└── docs/                       # هذا الملف + ملف العمليات
```

---

## 4. طبقة قاعدة البيانات

### الاتصال
كل الصفحات تبدأ بـ:
```php
require __DIR__ . '/../../../config/db.php';
// $pdo متاح تلقائياً كـ PDO object
```

### استخدام Database class (للكود الجديد)
```php
$db = Database::getInstance();

// جلب كل الصفوف
$rows = $db->fetchAll("SELECT * FROM tickets WHERE status=?", ['open']);

// جلب صف واحد
$ticket = $db->fetchOne("SELECT * FROM tickets WHERE id=?", [$id]);

// ترقيم الصفحات
$result = $db->paginate("SELECT * FROM tickets ORDER BY id DESC", [], $page, 25);
// $result['data'], $result['total'], $result['pages']

// معاملة آمنة
$db->beginTransaction();
try {
    $db->execute("INSERT INTO ...", [...]);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

### ثوابت الجداول (config/tables.php)
```php
// استخدم دائماً الثوابت بدل الأسماء المباشرة
TBL_TICKETS              // 'tickets'
TBL_WORK_ORDERS          // 'work_orders'
TBL_DOCUMENTS            // 'dms_documents'
TBL_EMPLOYEES            // 'dms_employees'
TBL_ASSETS               // 'assets'
TBL_KB_ARTICLES          // 'kb_articles'
TBL_KB_CATEGORIES        // 'kb_categories'
TBL_KB_FEEDBACK          // 'kb_feedback'
TBL_MESSAGES             // 'messages'
TBL_USERS                // 'sys_users'
TBL_MENU                 // 'sys_menu'
TBL_USER_MENU_ACCESS     // 'user_menu_access'
// ... انظر config/tables.php للقائمة الكاملة
```

---

## 5. نظام الأمان (core/Security.php)

### CSRF — تلقائي بالكامل
```
config/db.php ← يستدعي Security::validatePost() لكل POST
main-header.php ← JS يحقن csrf_token في كل <form> و $.ajax
```

**قائمة المسارات المُعفاة من CSRF** (AJAX داخلية):
```php
send_message.php, typing_status.php, get_unread_count.php,
process-ai.php, view-kb-article.php, show-kb.php, documents_dt.php
```

**إضافة token يدوياً** في النماذج التي لا تمر بـ main-header:
```php
echo Security::field(); // <input type="hidden" name="csrf_token" value="...">
```

### تطهير HTML (Summernote / Rich Text)
```php
// دائماً طهّر HTML قبل الحفظ في DB
$content = Security::sanitizeHtml($_POST['content'] ?? '');
```
يُبقي: `p, strong, em, h3-h6, ul, ol, table, a, img, blockquote, pre`
يحذف: `<script>`, `on*` handlers, `javascript:`, inline event handlers

### التحقق من الملفات المرفوعة
```php
$check = Security::validateUpload($_FILES['file'], 'document', 30); // 30MB max
if (!$check['ok']) { $errors[] = $check['error']; }

// أنواع متاحة: 'document' | 'image' | 'signature' | 'any'
// يفحص: كود رفع PHP + الحجم + الامتداد + MIME الحقيقي من bytes الملف
```

**توليد اسم ملف آمن:**
```php
$filename = Security::safeFilename($_FILES['photo']['name'], 'usr');
// مثال: usr_1750953600_a3f4b2c1.jpg
```

---

## 6. نظام الصلاحيات (RBAC)

كل صفحة تتحقق عبر جدول `user_menu_access`:

```php
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]); // مثال: "pages/tables/show-documents.php"
$page_id = $menuStmt->fetchColumn();

$accStmt = $pdo->prepare("
    SELECT can_view, can_add, can_edit, can_delete, can_approve, can_archive
    FROM user_menu_access WHERE user_id=? AND menu_id=?
");
$accStmt->execute([$user_id, $page_id]);
$perm = $accStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$can_add    = $perm['can_add']    ?? 0;
$can_edit   = $perm['can_edit']   ?? 0;
$can_delete = $perm['can_delete'] ?? 0;
```

**إضافة صفحة جديدة لشجرة النظام:**
```sql
-- 1. إضافة للقائمة الرئيسية
INSERT INTO sys_menu (title, icon, link, parent_id, sort_order)
VALUES ('اسم الصفحة', 'fas fa-icon', 'pages/tables/my-page.php', 0, 90);

-- 2. منح صلاحيات للمستخدمين
INSERT INTO user_menu_access (user_id, menu_id, can_view, can_add, can_edit, can_delete)
SELECT u.id, m.id, 1, 1, 1, 1
FROM sys_users u, sys_menu m
WHERE m.link = 'pages/tables/my-page.php';
```

---

## 7. نظام الإشعارات

### رسالة داخلية (Chat)
```php
require_once __DIR__ . '/core/Notify.php';

// إرسال رسالة داخلية بين مستخدمين
Notify::internalMessage($pdo, $fromUserId, $toUserId, "نص الرسالة");

// إشعار تعيين مهمة
Notify::onTaskAssigned($pdo, $createdByUserId, $assignedToUserId, [
    'id'      => $taskId,
    'title'   => $taskTitle,
    'details' => $taskDetails,
]);

// إشعار اعتماد وثيقة
Notify::onDocumentApprovalRequired($pdo, $createdByUserId, $workflowId, [
    'id'    => $docId,
    'title' => $docTitle,
]);
```

### إشعارات خارجية (تُفعَّل من config/notify.php)
```php
Notify::sms('+966501234567', 'نص الرسالة');
Notify::whatsapp('+966501234567', 'نص الرسالة');
```

---

## 8. Cache Layer (core/Cache.php)

```php
$cache = Cache::getInstance();

// remember() — جلب من الكاش أو تنفيذ الـ callback وتخزين النتيجة
$data = $cache->remember('dashboard_stats', 120, function() use ($pdo) {
    return $pdo->query("SELECT COUNT(*) ... ")->fetchColumn();
});

// حذف مفتاح محدد
$cache->forget('dashboard_stats');

// حذف الكاش بالكامل
$cache->flush();

// ثوابت المدة (config/tables.php)
CACHE_TTL_SHORT   = 120   // ثانيتان
CACHE_TTL_MEDIUM  = 600   // 10 دقائق
CACHE_TTL_LONG    = 3600  // ساعة
```

---

## 9. Server-Side DataTable (النمط الكامل)

### PHP Page (show-*.php)
```php
// 1. أفرغ tbody
// 2. هيّئ DataTable بـ serverSide: true
```

```javascript
function buildAjaxData(d) {
    d.filter_status = $('#fStatus').val() || '';
    d.filter_cat    = $('#fCat').val()    || '';
}

var table = $('#myTable').DataTable({
    serverSide: true,
    processing: true,
    ajax: {
        url:  '../../api/my_dt.php',
        type: 'POST',
        data: buildAjaxData,
    },
    columnDefs: [{ orderable: false, targets: [-1] }],
});

// الفلاتر تُعيد الطلب للـ API
$('#fStatus, #fCat').on('change', function() { table.draw(); });
$('#resetBtn').on('click', function() {
    $('#fStatus, #fCat').val('');
    table.search('').draw();
});

// event delegation للأزرار الديناميكية
$(document).on('click', '.delete-btn', function() {
    var id = $(this).data('id');
    // ...
});
```

### API Endpoint (admin/api/my_dt.php)
```php
// معاملات DataTables
$draw    = (int)($_REQUEST['draw'] ?? 1);
$start   = (int)($_REQUEST['start'] ?? 0);
$length  = max(1, min(200, (int)($_REQUEST['length'] ?? 25)));
$search  = trim($_REQUEST['search']['value'] ?? '');

// بناء WHERE وتنفيذ استعلامين (العدد الكلي + الصفحة)
// إرجاع JSON
echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE);
```

---

## 10. نمط صفحة جديدة (Template)

```php
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../../../config/db.php';  // يتضمن Security + tables + PDO
require __DIR__ . '/../forms/functions.php';  // log_action() و isRtl() وغيرها

$uid       = (int)($_SESSION['user_id'] ?? 0);
$page_path = 'pages/tables/my-page.php';      // يطابق السجل في sys_menu
if (!$uid) die("يجب تسجيل الدخول");

// صلاحيات
$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$page_id = $menuStmt->fetchColumn() ?: 0;
$can_add = $can_edit = $can_delete = 0;
if ($page_id > 0) {
    $p = $pdo->prepare("SELECT can_add,can_edit,can_delete FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $p->execute([$uid, $page_id]);
    $p = $p->fetch(PDO::FETCH_ASSOC) ?: [];
    $can_add    = $p['can_add']    ?? 0;
    $can_edit   = $p['can_edit']   ?? 0;
    $can_delete = $p['can_delete'] ?? 0;
}

// معالجة POST — CSRF مُتحقَّق منه تلقائياً بواسطة config/db.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && $can_add) {
    // ...
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606250922">
    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include __DIR__ . '/../../main-header.php'; ?>  <!-- يحقن CSRF تلقائياً -->
<?php include __DIR__ . '/../../main-sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div><h4><i class="fas fa-icon ml-2"></i>عنوان الصفحة</h4></div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php">الرئيسية</a></li>
                    <li class="breadcrumb-item active">اسم الصفحة</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">
        <!-- المحتوى هنا -->
    </div>
    </section>
</div>

<?php include __DIR__ . '/../../main-footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
```

---

## 11. CSS Variables (التخصيص البصري)

المتغيرات تُعيَّن في `main-header.php` من `sys_theme`:

```css
--crm-primary          /* اللون الأساسي */
--crm-page-bar-from    /* بداية gradient لشريط العنوان */
--crm-page-bar-to      /* نهاية gradient لشريط العنوان */
--crm-sidebar-bg       /* لون الشريط الجانبي */
```

**استخدامها في CSS:**
```css
.my-header {
    background: linear-gradient(135deg,
        var(--crm-page-bar-from, #1a5276),
        var(--crm-page-bar-to,   #2980b9)
    );
}
```

**ملاحظة:** دالة `colorLuminance()` في `main-header.php` تكشف اللون الفاتح جداً وتستبدله بلون الشريط الجانبي لضمان قراءة النص.

---

## 12. نظام الترجمة (i18n)

```php
// في أي ملف PHP بعد تحميل init.php
require __DIR__ . '/../../lang/init.php';

echo __('emp_title');          // 'إدارة الموظفين' أو 'Employees Management'
echo isRtl();                  // true/false
```

**إضافة مفتاح جديد:**
```php
// في admin/lang/ar.php
'my_new_key' => 'النص العربي',

// في admin/lang/en.php
'my_new_key' => 'English Text',
```

**اللغة تُحدَّد من:** `$_SESSION['lang']` أو `sys_settings.default_lang`

---

## 13. هيكل قاعدة البيانات (الجداول الرئيسية)

### مجموعة النظام
| الجدول | الوصف |
|--------|--------|
| `sys_users` | المستخدمون + كلمات المرور (bcrypt) |
| `sys_roles` | الأدوار (MainAdmin, Admin, Tech, ...) |
| `sys_menu` | شجرة القائمة الديناميكية |
| `user_menu_access` | صلاحيات المستخدم لكل صفحة |
| `sys_settings` | إعدادات النظام (اسم، شعار، ...) |
| `sys_theme` | ألوان الواجهة |
| `audit_logs` | سجل كل العمليات |

### مجموعة التذاكر
| الجدول | الوصف |
|--------|--------|
| `tickets` | البلاغات الواردة |
| `work_orders` | تعيينات الفنيين |
| `ticket_comments` | تعليقات التذاكر |
| `sla_rules` | قواعد SLA بحسب الأولوية |

### مجموعة الوثائق (DMS)
| الجدول | الوصف |
|--------|--------|
| `dms_documents` | الوثائق الرئيسية |
| `dms_document_types` | أنواع الوثائق |
| `dms_categories` | تصنيفات الوثائق |
| `dms_employees` | موظفو DMS (المعتمِدون) |
| `dms_document_approvals` | مراحل الاعتماد |
| `approval_workflows` | سياسات سير الاعتماد |

### مجموعة الأصول
| الجدول | الوصف |
|--------|--------|
| `assets` | الأصول والأجهزة |
| `asset_categories` | تصنيفات الأصول |
| `maintenance_schedules` | جداول الصيانة الدورية |
| `maintenance_logs` | سجل الصيانة المنجزة |

### مجموعة قاعدة المعرفة
| الجدول | الوصف |
|--------|--------|
| `kb_articles` | المقالات (FULLTEXT index على title+summary+tags) |
| `kb_categories` | تصنيفات المقالات |
| `kb_feedback` | تقييم المستخدمين (UNIQUE per user per article) |

### مجموعة التواصل
| الجدول | الوصف |
|--------|--------|
| `messages` | رسائل الشات الداخلي |
| `notifications` | الإشعارات |
| `notification_settings` | تفضيلات الإشعار لكل موظف |

---

## 14. ملفات SQL للترحيل

| الملف | الغرض |
|-------|--------|
| `wasl_database.sql` | قاعدة البيانات الأساسية (الإنشاء الكامل) |
| `wasl_migration_v2.sql` | ترحيلات الإصدار 2 |
| `wasl_migration_internal.sql` | جداول الشات والإشعارات |
| `wasl_assets_migration.sql` | وحدة الأصول والصيانة |
| `wasl_indexes_migration.sql` | ~59 index لتحسين الأداء |
| `wasl_kb_migration.sql` | وحدة قاعدة المعرفة |
| `wasl_event_scheduler.sql` | MySQL Event Scheduler (SLA alerts) |

**ترتيب التنفيذ:**
```
1. wasl_database.sql
2. wasl_migration_v2.sql
3. wasl_migration_internal.sql
4. wasl_assets_migration.sql
5. wasl_kb_migration.sql
6. wasl_indexes_migration.sql
7. wasl_event_scheduler.sql   (اختياري)
```

---

## 15. نقاط API الداخلية

| المسار | النوع | الوظيفة |
|--------|--------|---------|
| `admin/api/documents_dt.php` | POST | Server-Side DataTables للوثائق |
| `admin/send_message.php` | POST | إرسال رسالة الشات |
| `admin/fetch_messages.php` | GET | جلب محادثة |
| `admin/get_unread_count.php` | GET | عدد الرسائل غير المقروءة |
| `admin/typing_status.php` | POST | مؤشر الكتابة |
| `admin/pages/forms/search_tickets.php` | GET | بحث AJAX عن التذاكر |
| `admin/pages/forms/process-ai.php` | POST | معالجة سؤال AI |

---

## 16. نقاط يجب الانتباه

### عند إضافة صفحة جديدة
1. أضفها لـ `sys_menu` (INSERT)
2. امنح الصلاحيات لـ `user_menu_access`
3. استخدم نفس `$page_path` المتطابق مع `sys_menu.link`
4. لا تضع `csrf_token` يدوياً — JS يفعل ذلك تلقائياً

### عند حفظ HTML من محرر نصوص
```php
// ✅ صح
$content = Security::sanitizeHtml($_POST['content'] ?? '');

// ❌ خطأ
$content = $_POST['content'] ?? '';
```

### عند رفع ملف
```php
// ✅ صح
$check = Security::validateUpload($_FILES['file'], 'image', 5);
if (!$check['ok']) { /* رفض */ }
$name = Security::safeFilename($_FILES['file']['name'], 'prefix');

// ❌ خطأ
$name = $_FILES['file']['name']; // directory traversal خطر
```

### عند استعلام ديناميكي
```php
// ✅ صح — Prepared Statements دائماً
$stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
$stmt->execute([$id]);

// ❌ خطأ — SQL Injection
$pdo->query("SELECT * FROM tickets WHERE id = $id");
```
