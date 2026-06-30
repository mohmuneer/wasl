-- ============================================================
-- Migration: تحويل نموذج المبيعات إلى نموذج الاستخدام الداخلي
-- التاريخ: 2026-06-24
-- التأثير: حذف جداول agents/agent_assignments، تعديل جدول clients
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─── الخطوة 1: حذف جدول تعيينات المندوبين (FK إلى agents + clients) ─────
DROP TABLE IF EXISTS `agent_assignments`;

-- ─── الخطوة 2: حذف جدول المندوبين ──────────────────────────────────────
DROP TABLE IF EXISTS `agents`;

-- ─── الخطوة 3: حذف الحقول التجارية من جدول clients ─────────────────────
ALTER TABLE `clients`
    DROP COLUMN IF EXISTS `cr_number`,
    DROP COLUMN IF EXISTS `cr_expiry_hijri`,
    DROP COLUMN IF EXISTS `cr_expiry_date`,
    DROP COLUMN IF EXISTS `vat_number`,
    DROP COLUMN IF EXISTS `capital`,
    DROP COLUMN IF EXISTS `owner_name`,
    DROP COLUMN IF EXISTS `owner_id`,
    DROP COLUMN IF EXISTS `commercial_activity`,
    DROP COLUMN IF EXISTS `country`,
    DROP COLUMN IF EXISTS `district`,
    DROP COLUMN IF EXISTS `postal_code`;

-- ─── الخطوة 4: تحويل client_type إلى VARCHAR مؤقتاً لتجنب تعارض ENUM ──
ALTER TABLE `clients` MODIFY COLUMN `client_type` VARCHAR(50) NOT NULL DEFAULT 'technical';

-- ─── الخطوة 5: تحديث القيم الموجودة لتتوافق مع التصنيفات الداخلية ──────
UPDATE `clients` SET `client_type` = 'technical'       WHERE `client_type` = 'individual';
UPDATE `clients` SET `client_type` = 'operational'     WHERE `client_type` = 'company';
UPDATE `clients` SET `client_type` = 'administrative'  WHERE `client_type` = 'government';
UPDATE `clients` SET `client_type` = 'administrative'  WHERE `client_type` = 'semi_government';
UPDATE `clients` SET `client_type` = 'technical'       WHERE `client_type` NOT IN ('technical','administrative','operational','management');

-- ─── الخطوة 6: تغيير client_type إلى ENUM الداخلي الجديد ───────────────
ALTER TABLE `clients` MODIFY COLUMN `client_type`
    ENUM('technical','administrative','operational','management')
    NOT NULL DEFAULT 'technical'
    COMMENT 'نوع الموظف: تقني / إداري / تشغيلي / إدارة';

-- ─── الخطوة 7: إضافة حقول الموظف الداخلي ───────────────────────────────
ALTER TABLE `clients`
    ADD COLUMN IF NOT EXISTS `employee_number` VARCHAR(20) DEFAULT NULL COMMENT 'رقم الموظف' AFTER `client_code`,
    ADD COLUMN IF NOT EXISTS `job_title`       VARCHAR(100) DEFAULT NULL COMMENT 'المسمى الوظيفي' AFTER `employee_number`,
    ADD COLUMN IF NOT EXISTS `hire_date`       DATE DEFAULT NULL COMMENT 'تاريخ التوظيف' AFTER `job_title`;

-- ─── الخطوة 8: تحديث تعليق الجدول ──────────────────────────────────────
ALTER TABLE `clients`
    COMMENT = 'بيانات الموظفين الداخليين مقدمي الطلبات';

-- ─── الخطوة 9: تحديث قيود الفهارس المحذوفة ─────────────────────────────
-- (الفهارس idx_cr_number تم حذفها تلقائياً مع العمود)

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════════════════════
-- تحديث الشجرة الجانبية (sys_menu)
-- ════════════════════════════════════════════════════════════

-- ─── تحديث قسم "بيانات العملاء" → "بيانات الموظفين الداخليين" ──────────
UPDATE `sys_menu` SET
    `title`      = 'بيانات الموظفين الداخليين',
    `icon`       = 'fas fa-users'
WHERE `id` = 42;

UPDATE `sys_menu` SET `title` = 'إضافة موظف داخلي'    WHERE `id` = 43;
UPDATE `sys_menu` SET `title` = 'عرض بيانات الموظفين' WHERE `id` = 46;
UPDATE `sys_menu` SET `title` = 'تقارير الموظفين'     WHERE `id` = 47;

-- ─── حذف قسم المندوبين بالكامل (51-56) ────────────────────────────────
DELETE FROM `user_menu_access` WHERE `menu_id` IN (51, 52, 53, 54, 55, 56);
DELETE FROM `sys_menu`         WHERE `id`      IN (51, 52, 53, 54, 55, 56);

-- ─── تحديث دور "مندوب مبيعات" → "موظف داخلي" ──────────────────────────
UPDATE `sys_roles` SET
    `role_name` = 'موظف داخلي',
    `role_code` = 'InternalStaff',
    `description` = 'موظف داخلي يستخدم النظام لتقديم البلاغات'
WHERE `role_code` = 'SalesAgent';

-- ─── تحديث وصف النظام في إعدادات sys_settings ──────────────────────────
UPDATE `sys_settings` SET
    `system_description` = 'نظام توثيق المشاكل الداخلية وإدارة البلاغات'
WHERE 1 LIMIT 1;

-- ─── تحديث بيانات تدريب الذكاء الاصطناعي ───────────────────────────────
UPDATE `ai_training` SET
    `sql_query`        = 'SELECT client_name, phone, address FROM clients',
    `expected_response` = 'قائمة الموظفين الداخليين:'
WHERE `intent` = 'show_clients';

UPDATE `ai_questions` SET
    `question` = 'أظهر لي بيانات الموظفين الداخليين'
WHERE `intent` = 'show_clients';

-- ─── ملاحظة ─────────────────────────────────────────────────────────────
-- جدول clients يبقى باسمه الأصلي لتجنب تعارض FK في tickets/work_orders
-- التغيير الدلالي يظهر في واجهة المستخدم فقط (Labels/Titles)
-- ─────────────────────────────────────────────────────────────────────────
