# دليل التشغيل والتهيئة — نظام وَصْل CRM
**الإصدار:** 2.0 | **آخر تحديث:** 2026-06-26

---

## المرحلة 1 — تجهيز البيئة

### 1.1 المتطلبات الأساسية

| المكوّن | الإصدار المطلوب | رابط التحميل |
|---------|----------------|--------------|
| XAMPP   | 8.1+           | apachefriends.org |
| PHP     | 8.1 أو أعلى   | يأتي مع XAMPP |
| MySQL   | 5.7+ أو MariaDB 10.4+ | يأتي مع XAMPP |
| Composer | 2.x            | getcomposer.org |

### 1.2 التحقق من إصدار PHP

```bash
php -v
# يجب أن يظهر: PHP 8.1.x أو أعلى
```

### 1.3 التحقق من PHP Extensions المطلوبة

```bash
php -m | grep -E "pdo_mysql|mbstring|fileinfo|intl|json|gd"
```

يجب ظهور جميع هذه الامتدادات. إن لم تظهر:

**في XAMPP:**
- افتح `C:\xampp\php\php.ini`
- أزل الـ `;` من أمام السطور:
```ini
extension=pdo_mysql
extension=mbstring
extension=fileinfo
extension=intl
extension=gd
```
- أعد تشغيل Apache من لوحة XAMPP

---

## المرحلة 2 — تنزيل وتثبيت المشروع

### 2.1 نسخ ملفات المشروع

```bash
# ضع المشروع داخل htdocs
C:\xampp\htdocs\UltimatesolutionsCrm\
```

### 2.2 تثبيت مكتبات Composer

```bash
cd C:\xampp\htdocs\UltimatesolutionsCrm
composer install
```

**يجب أن ينتهي بـ:**
```
Generating autoload files
```

**المكتبات التي ستُثبَّت:**
- `setasign/fpdi-fpdf` — توليد PDF
- `ezyang/htmlpurifier` — تطهير HTML

---

## المرحلة 3 — إعداد قاعدة البيانات

### 3.1 إنشاء قاعدة البيانات

1. افتح **phpMyAdmin**: `http://localhost/phpmyadmin`
2. انقر **New** أو **جديد**
3. اسم القاعدة: `wasl`
4. Collation: `utf8mb4_unicode_ci`
5. انقر **Create**

### 3.2 تنفيذ ملفات SQL بالترتيب

افتح **phpMyAdmin** → اختر قاعدة `wasl` → انقر تبويب **Import** وتنفيذ الملفات بهذا الترتيب:

```
الترتيب    الملف                              الوصف
─────────────────────────────────────────────────────────────
1          wasl_database.sql               البنية الأساسية + البيانات الأولية
2          wasl_migration_v2.sql           ترحيلات الإصدار 2
3          wasl_migration_internal.sql     جداول الشات والإشعارات الداخلية
4          wasl_assets_migration.sql       وحدة الأصول والصيانة
5          wasl_kb_migration.sql           وحدة قاعدة المعرفة
6          wasl_indexes_migration.sql      تحسينات الأداء (59 index)
7          wasl_event_scheduler.sql        [اختياري] تنبيهات SLA التلقائية
```

**أو عبر Command Line (أسرع):**
```bash
cd C:\xampp\htdocs\UltimatesolutionsCrm

C:\xampp\mysql\bin\mysql.exe -u root wasl < wasl_database.sql
C:\xampp\mysql\bin\mysql.exe -u root wasl < wasl_migration_v2.sql
C:\xampp\mysql\bin\mysql.exe -u root wasl < wasl_migration_internal.sql
C:\xampp\mysql\bin\mysql.exe -u root wasl < wasl_assets_migration.sql
C:\xampp\mysql\bin\mysql.exe -u root wasl < wasl_kb_migration.sql
C:\xampp\mysql\bin\mysql.exe -u root wasl < wasl_indexes_migration.sql
```

### 3.3 التحقق من البيانات الأولية

```sql
-- في phpMyAdmin → SQL
SELECT COUNT(*) FROM sys_users;        -- يجب أن يكون 1+ (المدير الافتراضي)
SELECT COUNT(*) FROM sys_menu;         -- يجب أن يكون 57+ سجل
SELECT COUNT(*) FROM kb_categories;    -- يجب أن يكون 5 سجلات
SELECT COUNT(*) FROM asset_categories; -- يجب أن يكون 10 سجلات
```

---

## المرحلة 4 — إعداد ملفات التهيئة

### 4.1 إعدادات قاعدة البيانات (config/db.php)

```php
// تعديل إذا كان الاسم مختلفاً
define('DB_HOST',    'localhost');
define('DB_NAME',    'wasl');        // ← اسم قاعدتك
define('DB_USER',    'root');        // ← مستخدم MySQL
define('DB_PASS',    '');            // ← كلمة مرور MySQL (فارغة في XAMPP)
define('DB_CHARSET', 'utf8mb4');
```

**⚠️ في الإنتاج:** لا تستخدم `root`. أنشئ مستخدماً مخصصاً:
```sql
CREATE USER 'wasl_user'@'localhost' IDENTIFIED BY 'كلمة_مرور_قوية';
GRANT SELECT, INSERT, UPDATE, DELETE ON wasl.* TO 'wasl_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4.2 إعدادات الإشعارات (config/notify.php)

**SMS عبر Msegat:**
```php
define('MSEGAT_USERNAME', 'اسم_المستخدم_من_msegat');
define('MSEGAT_API_KEY',  'مفتاح_API_من_msegat');
define('MSEGAT_SENDER',   'WASL');          // اسم المُرسِل المعتمد
define('NOTIFY_SMS_ENABLED', true);         // تفعيل
```

**WhatsApp عبر Unifonic:**
```php
define('UNIFONIC_APP_SID',   'معرف_التطبيق');
define('UNIFONIC_SENDER_ID', '+966XXXXXXXXX');  // رقم WhatsApp Business
define('NOTIFY_WHATSAPP_ENABLED', true);
```

**البريد الإلكتروني:**
```php
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_USERNAME',   'your@gmail.com');
define('SMTP_PASSWORD',   'app-password');  // App Password من Google
define('NOTIFY_EMAIL_ENABLED', true);
```

---

## المرحلة 5 — إعداد المجلدات والصلاحيات

### 5.1 إنشاء مجلدات التخزين

```bash
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\uploads
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\uploads\assets
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\uploads\signatures
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\uploads\chat
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\storage
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\storage\cache
mkdir C:\xampp\htdocs\UltimatesolutionsCrm\storage\purifier_cache
```

**على Linux/macOS:**
```bash
chmod -R 755 uploads/ storage/
chown -R www-data:www-data uploads/ storage/
```

### 5.2 حماية مجلدات الرفع (على الإنتاج)

أضف ملف `.htaccess` داخل `uploads/`:
```apache
# منع تنفيذ PHP في مجلد الرفع
<FilesMatch "\.(php|php3|php4|php5|phtml|phar)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
Options -Indexes
```

---

## المرحلة 6 — تسجيل الدخول الأول

### 6.1 بيانات الدخول الافتراضية

```
الرابط:   http://localhost/UltimatesolutionsCrm/auth/login.php
البريد:   admin@system.com
كلمة المرور: (انظر قاعدة البيانات أو seed.php)
```

**إنشاء مدير جديد يدوياً:**
```bash
# تشغيل ملف seed.php إن وُجد
php C:\xampp\htdocs\UltimatesolutionsCrm\seed.php
```

**أو مباشرة عبر SQL:**
```sql
INSERT INTO sys_users (full_name, email, password, phone)
VALUES (
    'مدير النظام',
    'admin@company.com',
    '$2y$10$HASH_HERE',   -- password_hash('كلمة_المرور', PASSWORD_DEFAULT)
    '+9665XXXXXXXX'
);
-- احصل على الـ hash بـ: php -r "echo password_hash('كلمة_المرور', PASSWORD_DEFAULT);"
```

### 6.2 تعيين دور المدير

```sql
-- احصل على معرّف دور MainAdmin
SELECT id FROM sys_roles WHERE role_code = 'MainAdmin';  -- عادةً 1

-- عيّن الدور للمستخدم
INSERT INTO user_roles (user_id, role_id) VALUES (1, 1);
```

---

## المرحلة 7 — تهيئة إعدادات النظام

### 7.1 من داخل النظام

بعد تسجيل الدخول:
1. **الإعدادات العامة** → `admin/pages/forms/system-settings.php`
   - اسم النظام، الشعار، بريد المدير، رقم الجوال
2. **الألوان والمظهر** → `admin/pages/forms/theme-settings.php`
   - لون القائمة، اللون الأساسي، تخصيص ألوان شريط العنوان
3. **إعدادات المدخلات** → `admin/pages/forms/system-inputs.php`
   - ضبط الألوان الأساسية والثانوية

### 7.2 إضافة البيانات الأساسية (بالترتيب)

```
الخطوة    الصفحة                            الوصف
──────────────────────────────────────────────────────────
1       pages/tables/show-settings.php    إعدادات النظام العامة
2       pages/tables/show-permissions.php إضافة الأدوار والصلاحيات
3       pages/forms/addbranch.php         إضافة الفروع
4       pages/forms/add-group.php         إضافة المناطق
5       pages/tables/show-categories.php  تصنيفات البلاغات
6       pages/tables/show-sla.php         قواعد SLA
7       pages/forms/add-jobs.php          الهيكل الوظيفي
8       pages/tables/show-users.php       إضافة المستخدمين
9       pages/tables/show-employees.php   ربط الموظفين بالمستخدمين
10      pages/tables/assign-permissions.php توزيع الصلاحيات
```

---

## المرحلة 8 — إضافة المستخدمين والصلاحيات

### 8.1 إضافة مستخدم جديد

1. انتقل إلى `admin/pages/tables/show-users.php`
2. انقر **إضافة مستخدم**
3. أدخل: الاسم، البريد، كلمة المرور، رقم الجوال
4. اختر الدور المناسب

### 8.2 تعيين صلاحيات المستخدم

1. انتقل إلى `admin/pages/tables/assign-permissions.php`
2. اختر المستخدم
3. لكل صفحة: فعّل `عرض / إضافة / تعديل / حذف / اعتماد / أرشفة`
4. أو استخدم **منح الكل / سحب الكل** للتطبيق السريع

### 8.3 منح صلاحيات برمجياً (مفيد عند إضافة وحدة جديدة)

```sql
-- منح صلاحية عرض صفحة معينة لجميع المستخدمين
INSERT IGNORE INTO user_menu_access (user_id, menu_id, can_view, can_add, can_edit, can_delete)
SELECT u.id, m.id, 1, 0, 0, 0
FROM sys_users u
CROSS JOIN sys_menu m
WHERE m.link = 'pages/tables/show-kb.php';
```

---

## المرحلة 9 — تشغيل الإنتاج (VPS/Server)

### 9.1 متطلبات الخادم

```
Ubuntu 20.04+ أو CentOS 8+
PHP 8.1-FPM
Nginx أو Apache 2.4
MySQL 8.0 أو MariaDB 10.6
SSL/TLS (Let's Encrypt مجاناً)
```

### 9.2 إعداد Nginx

```nginx
server {
    listen 443 ssl;
    server_name crm.yourcompany.com;
    root /var/www/UltimatesolutionsCrm;
    index index.php;

    # منع الوصول لمجلدات حساسة
    location ~* ^/(config|core|vendor|storage)/ {
        deny all;
    }

    # منع تنفيذ PHP في uploads
    location /uploads/ {
        location ~* \.php$ { deny all; }
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 9.3 إعدادات PHP للإنتاج (php.ini)

```ini
# تعطيل عرض الأخطاء للمستخدم
display_errors = Off
log_errors = On
error_log = /var/log/php-errors.log

# حدود الرفع
upload_max_filesize = 30M
post_max_size = 32M
max_execution_time = 60

# الذاكرة
memory_limit = 256M
```

### 9.4 متغيرات البيئة (بديل config.php)

أضف لـ `.env` أو `config/db.php`:
```php
// استخدام متغيرات بيئة المنتج
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'wasl');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
```

---

## المرحلة 10 — النسخ الاحتياطية

### 10.1 نسخ احتياطية يدوية

**قاعدة البيانات:**
```bash
# نسخ احتياطي كامل
C:\xampp\mysql\bin\mysqldump.exe -u root wasl > backup_wasl_2026-06-26.sql

# استعادة
C:\xampp\mysql\bin\mysql.exe -u root wasl < backup_wasl_2026-06-26.sql
```

**ملفات الرفع:**
```bash
# نسخ مجلد uploads بالكامل
xcopy C:\xampp\htdocs\UltimatesolutionsCrm\uploads D:\Backups\uploads /E /I /Y
```

### 10.2 أتمتة النسخ الاحتياطية (Windows Task Scheduler)

أنشئ ملف `backup.bat`:
```batch
@echo off
SET DATE=%DATE:~-4%-%DATE:~3,2%-%DATE:~0,2%
SET BACKUP_DIR=D:\Backups
C:\xampp\mysql\bin\mysqldump.exe -u root wasl > "%BACKUP_DIR%\wasl_%DATE%.sql"
xcopy C:\xampp\htdocs\UltimatesolutionsCrm\uploads "%BACKUP_DIR%\uploads_%DATE%" /E /I /Q
echo Backup completed: %DATE%
```

جدولة عبر **Task Scheduler** للتنفيذ يومياً الساعة 2:00 صباحاً.

### 10.3 النسخ الاحتياطي من داخل النظام

انتقل إلى: `admin/pages/forms/system-buckup.php`
- تحميل نسخة قاعدة البيانات مباشرة بصيغة `.sql`

---

## المرحلة 11 — العمليات اليومية

### 11.1 فحص سجلات الأخطاء

```bash
# XAMPP
C:\xampp\apache\logs\error.log
C:\xampp\php\logs\php_error_log

# Linux
/var/log/apache2/error.log
/var/log/nginx/error.log
```

### 11.2 مراقبة الأداء

**سجل العمليات في النظام:**
```
admin/pages/tables/show-logs.php  ← سجل أعمال المستخدمين
```

**استعلامات بطيئة:**
```sql
-- تفعيل Slow Query Log في MySQL
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;   -- استعلامات أبطأ من ثانية
SET GLOBAL slow_query_log_file = '/tmp/mysql-slow.log';
```

### 11.3 تنظيف دوري

**تنظيف كاش النظام:**
```bash
# حذف ملفات الكاش القديمة (أكثر من 7 أيام)
find C:\xampp\htdocs\UltimatesolutionsCrm\storage\cache -mtime +7 -delete
```

**تنظيف محاولات تسجيل الدخول الفاشلة:**
```sql
DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

**تنظيف الإشعارات القديمة:**
```sql
DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

---

## المرحلة 12 — استكشاف الأخطاء

### 12.1 مشاكل شائعة وحلولها

| المشكلة | السبب المحتمل | الحل |
|---------|---------------|------|
| صفحة بيضاء | خطأ PHP مخفي | افحص `php_error_log` |
| CSRF error 419 | انتهت صلاحية الجلسة | أعد تحميل الصفحة |
| DataTables warning | عدم تطابق أعمدة | تحقق من عدد `<th>` مقابل البيانات |
| $ is not defined | jQuery لم يُحمَّل | أضف jQuery في `<head>` |
| FK constraint error | القيمة المرجعية غير موجودة | تحقق من ID قبل INSERT |
| صور لا تظهر | مسار خاطئ | تحقق من مجلد `uploads/` |
| رفع ملف يفشل | حجم `post_max_size` | عدّل `php.ini` |
| ترميز عربي مشوّه | Collation غير صحيح | تأكد من `utf8mb4_unicode_ci` |

### 12.2 اختبار الاتصال بقاعدة البيانات

```bash
php -r "
try {
    \$pdo = new PDO('mysql:host=localhost;dbname=wasl;charset=utf8mb4','root','');
    echo 'Connected OK. Tables: ' . \$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"wasl\"')->fetchColumn();
} catch(Exception \$e) {
    echo 'FAILED: ' . \$e->getMessage();
}
"
```

### 12.3 إعادة تعيين كلمة مرور مستخدم

```sql
-- PHP: echo password_hash('كلمة_المرور_الجديدة', PASSWORD_DEFAULT);
UPDATE sys_users
SET password = '$2y$10$HASH_HERE'
WHERE email = 'user@company.com';
```

---

## ملحق أ — الجداول الأساسية بالترتيب (للرجوع إليها)

```sql
-- بيانات نظام
sys_roles → sys_users → user_roles
sys_menu → user_menu_access

-- هيكل المنظمة  
regions → branches → departments

-- بيانات العمل
clients → tickets → work_orders
dms_employees → approval_workflows → dms_documents → dms_document_approvals

-- أصول
asset_categories → assets → maintenance_schedules → maintenance_logs

-- معرفة
kb_categories → kb_articles → kb_feedback

-- تواصل
sys_users → messages
sys_users → notifications
```

---

## ملحق ب — روابط الصفحات الرئيسية

```
تسجيل الدخول     /auth/login.php
لوحة التحكم      /admin/index.php
البلاغات          /admin/pages/tables/show-requests.php
المهام            /admin/pages/tables/show-tasks.php
الوثائق           /admin/pages/tables/show-documents.php
الأصول            /admin/pages/tables/show-assets.php
قاعدة المعرفة     /admin/pages/tables/show-kb.php
الشات الداخلي     /admin/contact.php
سجل الأعمال       /admin/pages/tables/show-logs.php
إعدادات النظام    /admin/pages/forms/system-settings.php
المستخدمون        /admin/pages/tables/show-users.php
الصلاحيات         /admin/pages/tables/assign-permissions.php
```

---

## ملحق ج — قائمة تحقق قبل الإطلاق

```
[ ] تشغيل جميع ملفات SQL بالترتيب
[ ] تعديل DB_USER و DB_PASS في config/db.php
[ ] إعداد بيانات الإشعارات في config/notify.php
[ ] إنشاء مجلدات uploads/ و storage/
[ ] تشغيل composer install
[ ] تسجيل دخول ناجح بحساب المدير
[ ] اختبار رفع ملف (وثيقة، صورة)
[ ] اختبار إرسال رسالة داخلية
[ ] اختبار إضافة بلاغ وتعيينه
[ ] التحقق من ظهور الإشعارات
[ ] تفعيل HTTPS على الإنتاج
[ ] إضافة .htaccess لحماية مجلد uploads/
[ ] جدولة نسخة احتياطية يومية
[ ] اختبار استعادة نسخة احتياطية
```
