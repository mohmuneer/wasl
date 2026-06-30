# الإعداد والنشر — نظام وَصْل

---

## 1. إعداد بيئة التطوير المحلية (XAMPP)

### الخطوات

```bash
# 1. انسخ المشروع داخل مجلد htdocs
cp -r UltimatesolutionsCrm/ C:/xampp/htdocs/

# 2. شغّل Apache + MySQL من لوحة تحكم XAMPP

# 3. أنشئ قاعدة بيانات جديدة
# افتح http://localhost/phpmyadmin
# أنشئ قاعدة: wasl_local
# ترميز: utf8mb4_unicode_ci
```

### استيراد قاعدة البيانات

```sql
-- في phpMyAdmin أو MySQL CLI:
-- الخطوة 1: قاعدة البيانات الأساسية
SOURCE C:/xampp/htdocs/UltimatesolutionsCrm/wasl_database.sql;

-- الخطوة 2: الإضافات (أمان + SLA + API)
SOURCE C:/xampp/htdocs/UltimatesolutionsCrm/wasl_migration_v2.sql;
```

### إعداد config/db.php للبيئة المحلية

```php
// config/db.php — غيّر هذه القيم للبيئة المحلية
define('DB_HOST',    'localhost');
define('DB_NAME',    'wasl_local');
define('DB_USER',    'root');
define('DB_PASS',    '');           // كلمة مرور XAMPP الافتراضية فارغة
define('DB_CHARSET', 'utf8mb4');
```

### إعداد config/notify.php

```php
// أبقِها معطّلة في بيئة التطوير
define('NOTIFY_SMS_ENABLED',      false);
define('NOTIFY_WHATSAPP_ENABLED', false);
define('NOTIFY_EMAIL_ENABLED',    false);
```

### الوصول للنظام

```
http://localhost/UltimatesolutionsCrm/auth/login.php

البريد الإلكتروني: admin@gmail.com
كلمة المرور:       123456   (أو كلمة مرور bcrypt المُعيَّنة في SQL)
```

---

## 2. النشر على InfinityFree (الاستضافة الحالية)

### معلومات الاتصال

| الإعداد | القيمة |
|---|---|
| DB Host | `sql207.infinityfree.com` |
| DB Name | `if0_41584225_tlink` |
| DB User | `if0_41584225` |
| DB Pass | `G8oZe6AhYU0M` |
| Panel URL | `https://infinityfree.net` |

### خطوات الرفع

```
1. ارفع الملفات عبر FileZilla أو مدير الملفات في الاستضافة
   المسار على الخادم: /htdocs/ أو /public_html/

2. استورد SQL عبر phpMyAdmin الخاص بـ InfinityFree

3. تأكد أن config/db.php يحتوي بيانات الإنتاج الصحيحة

4. اضبط صلاحيات مجلد storage/cache/:
   chmod 755 storage/cache/
```

### إعداد Cron Jobs على InfinityFree

> InfinityFree لا يدعم Cron Jobs الداخلية مجاناً.  
> استخدم خدمة خارجية مثل **cron-job.org** (مجاني).

| المهمة | الرابط | التكرار |
|---|---|---|
| إرسال البريد | `https://your-domain.com/api/index.php?endpoint=cron&action=email&cron_key=SECRET` | كل دقيقة |
| فحص SLA | `https://your-domain.com/api/index.php?endpoint=cron&action=sla&cron_key=SECRET` | كل 15 دقيقة |

```php
// أضف هذا في config/db.php لتعريف مفتاح الـ cron
define('CRON_SECRET_KEY', 'ضع_مفتاحًا_عشوائيًا_طويلًا_هنا');
```

---

## 3. الإعداد على VPS / خادم مخصص (للإنتاج الأمثل)

### متطلبات الخادم

```bash
# Ubuntu 22.04 LTS
apt install php8.2 php8.2-mysql php8.2-mbstring php8.2-intl \
            php8.2-apcu mariadb-server nginx

# تفعيل APCu (تسريع الكاش ×10 مقارنة بالملفات)
echo "extension=apcu.so" >> /etc/php/8.2/fpm/php.ini
echo "apc.enabled=1" >> /etc/php/8.2/fpm/php.ini
echo "apc.shm_size=64M" >> /etc/php/8.2/fpm/php.ini
```

### إعداد OPcache (ضروري للأداء)

```ini
; /etc/php/8.2/fpm/conf.d/10-opcache.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

### إعداد MySQL للأداء العالي

```ini
; /etc/mysql/mariadb.conf.d/50-server.cnf
[mysqld]
innodb_buffer_pool_size    = 1G          # 50-70% من RAM
innodb_log_file_size       = 256M
query_cache_type           = 1
query_cache_size           = 64M
query_cache_limit          = 2M
max_connections            = 200
slow_query_log             = 1
slow_query_log_file        = /var/log/mysql/slow.log
long_query_time            = 1           # تسجيل الاستعلامات > 1 ثانية
```

### إعداد cron jobs (Linux)

```cron
* * * * *       www-data  curl -s "http://localhost/api/index.php?endpoint=cron&action=email&cron_key=SECRET" > /dev/null
*/15 * * * *    www-data  curl -s "http://localhost/api/index.php?endpoint=cron&action=sla&cron_key=SECRET" > /dev/null
```

### MySQL Event Scheduler (بديل cron للـ SLA)

```sql
-- شغّل مرة واحدة على الخادم
SET GLOBAL event_scheduler = ON;

-- الحدث التلقائي موجود بالفعل في wasl_migration_v2.sql:
-- evt_check_sla → يعمل كل 15 دقيقة تلقائياً
```

---

## 4. المتغيرات البيئية (Environment Variables)

يُنصح في الإنتاج بنقل الأسرار من `config/db.php` إلى متغيرات البيئة:

```php
// config/db.php — نسخة VPS
define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'wasl_prod');
define('DB_USER',    $_ENV['DB_USER']    ?? 'wasl_user');
define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
```

```bash
# /etc/environment أو .env (استخدم vlucas/phpdotenv)
DB_HOST=localhost
DB_NAME=wasl_prod
DB_USER=wasl_user
DB_PASS=your_strong_password
CRON_SECRET_KEY=random_64_char_string
```

---

## 5. قائمة تدقيق ما قبل الإطلاق

- [ ] `config/db.php` — بيانات قاعدة بيانات الإنتاج
- [ ] `config/notify.php` — مفاتيح Msegat/Unifonic/SMTP الحقيقية
- [ ] `NOTIFY_*_ENABLED` = `true` في config/notify.php
- [ ] `CRON_SECRET_KEY` — مفتاح عشوائي معقد
- [ ] Cron jobs مُعدَّة على cron-job.org أو الخادم
- [ ] `storage/cache/` — صلاحيات الكتابة (chmod 755)
- [ ] `storage/uploads/` — موجود وله صلاحيات الكتابة
- [ ] OPcache مُفعَّل على الخادم
- [ ] SSL شهادة HTTPS مُفعَّلة
- [ ] `error_reporting(0)` في الإنتاج
- [ ] جدران الحماية: قاعدة البيانات تسمح فقط بالاتصال المحلي
