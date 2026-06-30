# نظام وَصْل (WASL CRM) — دليل المطوّر

> **الإصدار:** 2.0 | **التاريخ:** 2026-06-17 | **المنصة:** PHP 7.4+ / MariaDB 11.4

---

## 1. نظرة عامة

**وَصْل (WASL)** نظام إدارة علاقات عملاء (CRM) متخصص لشركات الدعم الفني في المملكة العربية السعودية. يتيح:

- توثيق البلاغات الفنية ومتابعتها بدورة حياة كاملة
- إدارة SLA بساعات العمل الفعلية (مع استثناء العطل الرسمية)
- تعيين المندوبين والفنيين على العملاء
- إشعارات فورية عبر WhatsApp / SMS / بريد إلكتروني
- API كاملة للتطبيقات الجوالة
- لوحة تحكم بصرية مع إحصاءات ومؤشرات أداء

---

## 2. متطلبات النظام

| المتطلب | الحد الأدنى | الموصى به |
|---|---|---|
| PHP | 7.4 | 8.2 |
| MariaDB / MySQL | 10.4 | 11.4 |
| مساحة القرص | 500 MB | 2 GB |
| ذاكرة PHP | 128 MB | 256 MB |
| إضافات PHP | `pdo_mysql`, `mbstring`, `json` | + `apcu`, `intl` |

---

## 3. بنية المجلدات

```
UltimatesolutionsCrm/
│
├── auth/                       ← تسجيل الدخول والخروج
│   └── login.php
│
├── admin/                      ← لوحة التحكم (AdminLTE 3)
│   ├── index.php               ← الصفحة الرئيسية (Dashboard)
│   ├── main-header.php         ← الهيدر المشترك
│   ├── main-sidebar.php        ← القائمة الجانبية الديناميكية
│   ├── main-footer.php         ← الفوتر المشترك
│   ├── pages/
│   │   ├── forms/              ← صفحات الإدخال (Add/Edit)
│   │   │   ├── functions.php   ← دوال مشتركة (check_permission, log_action…)
│   │   │   └── *.php
│   │   └── tables/             ← صفحات العرض والتقارير
│   └── dist/
│       ├── css/custom.css      ← تخصيصات CSS + RTL
│       └── js/
│
├── api/                        ← API JSON للتطبيق الجوال
│   ├── index.php               ← الراوتر الرئيسي
│   ├── .htaccess
│   ├── Core/
│   │   ├── Response.php        ← استجابة JSON موحّدة
│   │   └── Middleware.php      ← التحقق من رمز API + Rate Limiting
│   └── endpoints/
│       ├── auth.php            ← تسجيل الدخول/الخروج
│       ├── tickets.php         ← إدارة التذاكر
│       ├── stats.php           ← إحصاءات لوحة التحكم
│       ├── notifications.php   ← الإشعارات
│       └── cron.php            ← مهام الخلفية
│
├── config/
│   ├── db.php                  ← إعدادات قاعدة البيانات (Bootstrap)
│   ├── tables.php              ← ثوابت أسماء الجداول
│   └── notify.php              ← مفاتيح Msegat / Unifonic / SMTP
│
├── core/                       ← الفئات الأساسية
│   ├── Database.php            ← Singleton + paginate + slow query log
│   ├── Cache.php               ← APCu / ملفات
│   ├── Auth.php                ← CSRF + Brute Force + Session Guard
│   ├── Notify.php              ← WhatsApp / SMS / Email
│   └── SlaCalculator.php       ← حساب SLA بساعات العمل
│
├── storage/
│   ├── cache/                  ← ملفات الكاش (محمية بـ .htaccess)
│   └── uploads/                ← ملفات المرفوعة (يُنشأ يدوياً)
│
├── docs/                       ← ← أنت هنا
│
├── wasl_database.sql           ← قاعدة البيانات الكاملة (v2.0)
└── wasl_migration_v2.sql       ← هجرة الإضافات (أمان + SLA + API)
```

---

## 4. تدفق الطلب (Request Lifecycle)

```
المتصفح / التطبيق الجوال
        │
        ▼
  auth/login.php          ← مصادقة + CSRF + Brute Force
        │
        ▼
  config/db.php           ← تحميل: PDO + ثوابت + Cache + Database
        │
        ▼
  admin/pages/*.php       ← (ويب) فحص الصلاحيات عبر check_permission()
     أو
  api/index.php           ← (API) فحص Bearer Token عبر Middleware::auth()
        │
        ▼
  core/Database.php       ← تنفيذ الاستعلام (مع تسجيل البطيئة)
  core/Cache.php          ← تخزين النتيجة مؤقتاً
        │
        ▼
  core/Notify.php         ← إشعار (WhatsApp/SMS/Email) عند الحاجة
        │
        ▼
   HTML / JSON
```

---

## 5. تدفق الصلاحيات

```
تسجيل دخول
    │
    ├─► role_code === 'MainAdmin'  →  تخطّي كل الصلاحيات (وصول كامل)
    │
    └─► غيره  →  user_menu_access (can_view / can_add / can_edit / can_delete)
                    │
                    ▼
              check_permission($user_id, $page_link, $pdo)
              يُعيد: ['can_view'=>0/1, 'can_add'=>0/1, ...]
```

---

## 6. المكتبات المستخدمة

| المكتبة | الإصدار | الغرض |
|---|---|---|
| AdminLTE | 3.x | قالب لوحة التحكم |
| Bootstrap | 4.x RTL | واجهة مستخدم |
| jQuery | 3.7.1 | DOM + AJAX |
| DataTables | 1.x | جداول تفاعلية |
| Chart.js | 3.x | الرسوم البيانية |
| Font Awesome | 6.x | الأيقونات |
| SweetAlert2 | 11.x | نوافذ التأكيد |
| Cairo / Almarai | — | خطوط عربية |

---

## 7. نقاط الدخول المهمة

| الملف | الوظيفة |
|---|---|
| `auth/login.php` | صفحة تسجيل الدخول (مع حماية CSRF + Brute Force) |
| `admin/index.php` | الصفحة الرئيسية لوحة التحكم |
| `admin/main-sidebar.php` | القائمة الجانبية (تُحمَّل من `sys_menu` + `user_menu_access`) |
| `admin/pages/forms/functions.php` | دوال مشتركة (مطلوبة في كل صفحة) |
| `api/index.php` | راوتر API الجوال |
| `config/db.php` | Bootstrap الكامل (يُضمَّن في كل صفحة) |
