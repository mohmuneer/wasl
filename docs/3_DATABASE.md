# قاعدة البيانات — نظام وَصْل

> **المحرك:** InnoDB | **الترميز:** utf8mb4_unicode_ci | **التوقيت:** Asia/Riyadh (+03:00)

---

## 1. خريطة تحويل الأسماء (القديم → الجديد)

| الاسم القديم | الاسم الجديد | الثابت في PHP |
|---|---|---|
| `roles` | `sys_roles` | `TBL_ROLES` |
| `users` | `sys_users` | `TBL_USERS` |
| `system_settings` | `sys_settings` | `TBL_SETTINGS` |
| `system_visuals` | `sys_theme` | `TBL_THEME` |
| `sidebar_menu` | `sys_menu` | `TBL_MENU` |
| `permissions` | `sys_permissions` | `TBL_PERMISSIONS` |
| `user_permision` | `user_roles` | `TBL_USER_ROLES` |
| `user_page_access` | `user_menu_access` | `TBL_USER_MENU_ACCESS` |
| `branch` | `branches` | `TBL_BRANCHES` |
| `colleges` | `regions` | `TBL_REGIONS` |
| `labs` | `departments` | `TBL_DEPARTMENTS` |
| `groups` | `issue_categories` | `TBL_ISSUE_CATEGORIES` |
| `Customers` | `clients` | `TBL_CLIENTS` |
| `Vendors` | `agents` | `TBL_AGENTS` |
| `target_assignments` | `agent_assignments` | `TBL_AGENT_ASSIGNMENTS` |
| `requests` | `tickets` | `TBL_TICKETS` |
| `tasks` | `work_orders` | `TBL_WORK_ORDERS` |
| `user_branches` | `user_branch_access` | `TBL_USER_BRANCH_ACCESS` |
| `user_group_access` | `user_category_access` | `TBL_USER_CATEGORY_ACCESS` |
| `system_logs` | `audit_logs` | `TBL_AUDIT_LOGS` |
| `ai_chat` | `ai_sessions` | `TBL_AI_SESSIONS` |
| `chatbot_training` | `ai_training` | `TBL_AI_TRAINING` |
| `training_questions` | `ai_questions` | `TBL_AI_QUESTIONS` |

**جداول جديدة تماماً:**

| الجدول | الثابت | الغرض |
|---|---|---|
| `sla_rules` | `TBL_SLA_RULES` | قواعد مستوى الخدمة |
| `ticket_comments` | `TBL_TICKET_COMMENTS` | تعليقات التذاكر |
| `ticket_attachments` | `TBL_TICKET_ATTACHMENTS` | مرفقات التذاكر |
| `notifications` | `TBL_NOTIFICATIONS` | إشعارات النظام |
| `client_contacts` | `TBL_CLIENT_CONTACTS` | جهات اتصال العملاء |
| `login_attempts` | — | تتبع محاولات الدخول |
| `business_hours` | — | ساعات العمل |
| `holiday_calendar` | — | الإجازات الرسمية |
| `email_queue` | — | قائمة انتظار البريد |
| `api_tokens` | — | رموز API الجوال |

---

## 2. مخطط العلاقات (ERD)

```
sys_roles (1) ──────────────────── (N) sys_users
                                           │
              ┌────────────────────────────┤
              │                            │
             (N)                          (N)
        user_roles                  user_menu_access
              │                            │
             (1)                          (1)
          sys_roles                     sys_menu


branches (1) ─── (N) regions (1) ─── (N) departments (1) ─── (N) clients
    │                                          │                      │
   (N)                                        (N)                    (N)
user_branch_access                           tickets ──────────────  client_contacts
                                              │    │
                               ┌──────────────┘    └─────────────────┐
                               │                                      │
                            work_orders                          ticket_comments
                               │                                ticket_attachments
                            sys_users


issue_categories (1) ─── (N) tickets
       │
      (N)
user_category_access


agents (1) ─── (N) agent_assignments ─── (1) clients


sla_rules (1) ─── (N) tickets


sys_users (1) ─── (N) messages ─── (1) sys_users
sys_users (1) ─── (N) notifications
sys_users (1) ─── (N) audit_logs
sys_users (1) ─── (N) ai_sessions
sys_users (1) ─── (N) api_tokens
```

---

## 3. توثيق الجداول

### sys_users — المستخدمون

| العمود | النوع | الوصف |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | المعرّف |
| `full_name` | VARCHAR(100) | الاسم الكامل |
| `email` | VARCHAR(100) UNIQUE | البريد الإلكتروني |
| `password` | VARCHAR(255) | bcrypt hash |
| `phone` | VARCHAR(20) | رقم الجوال |
| `national_id` | VARCHAR(10) UNIQUE | رقم الهوية / الإقامة |
| `employee_id` | VARCHAR(20) | رقم الموظف الداخلي |
| `job_title` | VARCHAR(100) | المسمى الوظيفي |
| `file_path` | VARCHAR(255) | اسم ملف الصورة |
| `role_id` | INT FK→sys_roles | الدور الوظيفي |
| `branch_id` | INT FK→branches | الفرع الرئيسي |
| `status` | ENUM(active,inactive,suspended) | الحالة |
| `last_login` | TIMESTAMP | آخر دخول |
| `created_at` | TIMESTAMP | تاريخ الإنشاء |

---

### tickets — التذاكر / البلاغات

| العمود | النوع | الوصف |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | المعرّف |
| `ticket_number` | VARCHAR(20) GENERATED | رقم التذكرة (TK-000001) |
| `reporter_ref` | VARCHAR(50) | رقم المُبلِّغ المرجعي |
| `client_id` | INT FK→clients | العميل (اختياري) |
| `branch_id` | INT FK→branches | الفرع |
| `region_id` | INT FK→regions | المنطقة |
| `department_id` | INT FK→departments | القسم |
| `category_id` | INT FK→issue_categories | تصنيف المشكلة |
| `priority` | ENUM(Low,Medium,High,Urgent) | الأولوية |
| `status` | ENUM(Pending,In Progress,Resolved,Cancelled) | الحالة |
| `sla_rule_id` | INT FK→sla_rules | قاعدة SLA المطبّقة |
| `sla_breach_at` | DATETIME | موعد خرق SLA |
| `sla_response_deadline` | DATETIME | موعد أول رد (ساعات عمل) |
| `sla_resolve_deadline` | DATETIME | موعد الإغلاق (ساعات عمل) |
| `sla_breached` | TINYINT(1) | 1 = تم خرق SLA |
| `escalated` | TINYINT(1) | 1 = تم التصعيد |
| `first_response_at` | DATETIME | وقت أول رد فعلي |
| `closed_by` | INT FK→sys_users | من أغلق التذكرة |
| `closed_at` | TIMESTAMP | وقت الإغلاق |
| `resolution_time_hrs` | DECIMAL(10,2) | وقت الحل الفعلي بالساعات |

---

### sla_rules — قواعد مستوى الخدمة

| الأولوية | ساعات أول رد | ساعات الإغلاق |
|---|---|---|
| Urgent | 1.00 | 4.00 |
| High | 2.00 | 8.00 |
| Medium | 4.00 | 24.00 |
| Low | 8.00 | 72.00 |

> **ملاحظة:** هذه الساعات بساعات عمل فعلية (يستثني الإجازات وساعات خارج العمل).

---

### clients — العملاء (حقول سعودية موسّعة)

| العمود | الوصف | ملاحظة |
|---|---|---|
| `client_type` | individual / company / government / semi_government | نوع الكيان |
| `vat_number` | الرقم الضريبي (15 رقماً) | لمتطلبات زاتكا |
| `cr_number` | رقم السجل التجاري | |
| `cr_expiry_hijri` | تاريخ انتهاء السجل هجري | نص (مثل: 1447/05/15) |
| `cr_expiry_date` | تاريخ انتهاء السجل ميلادي | DATE |
| `national_id` | رقم الهوية الوطنية / الإقامة | لعملاء الأفراد |
| `district` | الحي | |
| `postal_code` | الرمز البريدي السعودي | |
| `country` | رمز الدولة ISO 3166-1 | افتراضي: SA |

---

## 4. الفهارس الرئيسية

```sql
-- فهارس الأداء على tickets (الجدول الأكثر استخداماً)
KEY idx_status      (status)
KEY idx_priority    (priority)
KEY idx_created_at  (created_at)
KEY idx_branch_id   (branch_id)
KEY idx_client_id   (client_id)
KEY idx_sla_breach  (sla_breached, status)    ← لاستعلامات SLA
KEY idx_closed_at   (closed_at)

-- البحث النصي الكامل
FULLTEXT KEY ft_details (details)

-- فهرس مركّب على work_orders لاستعلامات الفنيين
KEY idx_composite (assigned_to, status, deadline)

-- فهرس الإشعارات غير المقروءة
KEY idx_user_unread (user_id, is_read)
```

---

## 5. المشاهد (Views) الجاهزة

```sql
-- ملخص إحصاءات التذاكر (للداشبورد)
SELECT * FROM v_ticket_summary;
-- الأعمدة: total, pending, in_progress, resolved, cancelled, sla_breaches, today_count

-- التذاكر مع أسماء كل الكيانات
SELECT * FROM v_tickets_full WHERE status = 'Pending';

-- أوامر العمل مع أسماء المستخدمين
SELECT * FROM v_work_orders_full WHERE assigned_to = 5;

-- إحصاءات أداء الفنيين
SELECT * FROM v_technician_stats ORDER BY completion_rate DESC;
```

---

## 6. الإجراءات المخزّنة (Stored Procedures)

### sp_close_ticket
```sql
-- إغلاق تذكرة مع حساب وقت الحل وتسجيل خرق SLA
CALL sp_close_ticket(p_ticket_id, p_closed_by, @success);
SELECT @success; -- 1 = نجح، 0 = فشل
```

### sp_update_sla_breaches
```sql
-- تحديث الحالة sla_breached=1 للتذاكر المتجاوزة
-- تُشغَّل تلقائياً كل 15 دقيقة عبر حدث MySQL أو cron
CALL sp_update_sla_breaches();
```

---

## 7. الأنماط الاستعلامية الشائعة

### جلب التذاكر مع الكاش
```php
$cache = Cache::getInstance();
$tickets = $cache->remember('tickets_pending_' . $branchId, 120, function () use ($db, $branchId) {
    return $db->fetchAll(
        "SELECT * FROM v_tickets_full WHERE status = 'Pending' AND branch_id = ?",
        [$branchId]
    );
});
```

### ترقيم الصفحات
```php
$db   = Database::getInstance();
$page = max(1, (int)($_GET['page'] ?? 1));

$result = $db->paginate(
    "SELECT * FROM " . TBL_TICKETS . " WHERE status = ? ORDER BY created_at DESC",
    ['Pending'],
    $page,
    25
);

// $result['data']  → الصفوف
// $result['total'] → إجمالي الصفوف
// $result['pages'] → عدد الصفحات
```

### البحث النصي الكامل
```php
$keyword = $_GET['q'] ?? '';
$tickets = $db->fetchAll(
    "SELECT * FROM tickets WHERE MATCH(details) AGAINST(? IN BOOLEAN MODE) LIMIT 20",
    [$keyword . '*']
);
```

### حساب موعد SLA
```php
require_once __DIR__ . '/core/SlaCalculator.php';

$slaRule = $pdo->query("SELECT * FROM sla_rules WHERE priority = 'High'")->fetch();
$now     = new DateTime();

$responseDeadline  = SlaCalculator::deadline($now, $slaRule['response_hours'],  $pdo);
$resolveDeadline   = SlaCalculator::deadline($now, $slaRule['resolution_hours'], $pdo);

echo $responseDeadline->format('Y-m-d H:i:s');  // موعد أول رد
```

---

## 8. نسخ احتياطية

```bash
# نسخة احتياطية يومية (أضفها لـ cron)
mysqldump -u if0_41584225 -p'G8oZe6AhYU0M' \
          --single-transaction \
          --routines \
          --triggers \
          if0_41584225_tlink \
  | gzip > /backups/wasl_$(date +%Y%m%d).sql.gz

# الاحتفاظ بآخر 30 نسخة فقط
find /backups/ -name "wasl_*.sql.gz" -mtime +30 -delete
```
