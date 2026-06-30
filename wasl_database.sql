-- ============================================================
--  قاعدة بيانات نظام وَصْل  (WASL CRM)
--  الإصدار 2.0 — مُهيَّأ للشركات الكبرى في المملكة العربية السعودية
--  تاريخ: 2026-06-17 | MariaDB 11.4 | PHP 7.4+
-- ============================================================
--
--  التغييرات الجوهرية عن الإصدار 1.0:
--  ① إعادة تسمية 24 جدولاً باتفاقية snake_case المهنية
--  ② إضافة حقول سعودية: الرقم الضريبي، السجل التجاري، الهوية الوطنية
--  ③ جداول جديدة: sla_rules, ticket_comments, ticket_attachments, notifications, client_contacts
--  ④ فهارس مركّبة لأنماط الاستعلام الشائعة في التقارير
--  ⑤ مشاهد (VIEWs) جاهزة للوحة التحكم والتقارير
--  ⑥ إجراءات مخزّنة لعمليات SLA وإغلاق التذاكر
--  ⑦ دعم الترقيم الهجري عبر خانات منفصلة
-- ============================================================

SET SQL_MODE    = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT  = 0;
START TRANSACTION;
SET time_zone   = "+03:00";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════════════════════════
--  ① جداول النظام الأساسية
-- ════════════════════════════════════════════════════════════

-- 1. الأدوار الوظيفية
CREATE TABLE `sys_roles` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(100) NOT NULL COMMENT 'الاسم المعروض بالعربية',
  `role_code` VARCHAR(50)  NOT NULL COMMENT 'الرمز البرمجي بالإنجليزية',
  `description` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='أدوار المستخدمين في النظام';

INSERT INTO `sys_roles` (`id`,`role_name`,`role_code`,`description`) VALUES
(1, 'مدير النظام',     'MainAdmin',   'صلاحيات كاملة غير مقيدة'),
(2, 'مشرف عمليات',    'Supervisor',  'إدارة التذاكر والمهام والموظفين'),
(3, 'فني دعم',         'Technician',  'تنفيذ المهام وتحديث التذاكر'),
(4, 'موظف داخلي',     'InternalStaff','تقديم البلاغات ومتابعة الطلبات الداخلية'),
(5, 'مدير فرع',        'BranchManager','إدارة عمليات الفرع');

-- 2. المستخدمون
CREATE TABLE `sys_users` (
  `id`            INT(11)                        NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(100)                   NOT NULL COMMENT 'الاسم الكامل',
  `email`         VARCHAR(100)                   NOT NULL,
  `password`      VARCHAR(255)                   NOT NULL,
  `phone`         VARCHAR(20)                    DEFAULT NULL,
  `national_id`   VARCHAR(10)                    DEFAULT NULL COMMENT 'رقم الهوية الوطنية أو الإقامة',
  `employee_id`   VARCHAR(20)                    DEFAULT NULL COMMENT 'رقم الموظف الداخلي',
  `job_title`     VARCHAR(100)                   DEFAULT NULL COMMENT 'المسمى الوظيفي',
  `file_path`     VARCHAR(255)                   DEFAULT NULL COMMENT 'مسار الصورة الشخصية',
  `role_id`       INT(11)                        NOT NULL DEFAULT 3,
  `branch_id`     INT(11)                        DEFAULT NULL COMMENT 'الفرع الرئيسي للمستخدم',
  `status`        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `last_login`    TIMESTAMP                      NULL DEFAULT NULL,
  `created_at`    TIMESTAMP                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP                      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email`       (`email`),
  UNIQUE KEY `uq_national_id` (`national_id`),
  KEY `idx_role_id`   (`role_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_status`    (`status`),
  CONSTRAINT `fk_user_role`
    FOREIGN KEY (`role_id`) REFERENCES `sys_roles`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='مستخدمو النظام (الموظفون والمشرفون)';

INSERT INTO `sys_users`
  (`id`,`full_name`,`email`,`password`,`phone`,`role_id`,`status`) VALUES
(2,  'محمد منير',  'admin@gmail.com',
     '$2y$10$Mq5XJtw0b9NTHNWBmvAVeeMTeOc4gg8f0IQXCnFBjLcUS3DG.D6Y.',
     NULL, 1, 'active'),
(17, 'عبدالرزاق', 'abdulrazaqalmoneer@gmail.com',
     '$2y$10$jPOMtuxUMaHlFH/iH13bquVkFPghJh6zDAjQNj0SCn.t97Xgdq5nS',
     NULL, 3, 'active');

-- 3. الإعدادات العامة للنظام
CREATE TABLE `sys_settings` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `system_name`      VARCHAR(255) NOT NULL DEFAULT 'وَصْل',
  `system_name_en`   VARCHAR(255) NOT NULL DEFAULT 'WASL',
  `admin_email`      VARCHAR(255) DEFAULT NULL,
  `contact_number`   VARCHAR(50)  DEFAULT NULL,
  `address`          TEXT         DEFAULT NULL,
  `system_logo`      VARCHAR(255) DEFAULT NULL,
  `cr_number`        VARCHAR(20)  DEFAULT NULL COMMENT 'السجل التجاري للشركة',
  `vat_number`       VARCHAR(15)  DEFAULT NULL COMMENT 'الرقم الضريبي للشركة',
  `maintenance_mode` TINYINT(1)   NOT NULL DEFAULT 0,
  `timezone`         VARCHAR(50)  NOT NULL DEFAULT 'Asia/Riyadh',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الإعدادات العامة للنظام';

INSERT INTO `sys_settings`
  (`id`,`system_name`,`system_name_en`,`admin_email`,`contact_number`,`address`,`system_logo`) VALUES
(1, 'شركة الحلول النهائية', 'Ultimate Solutions',
   'tlink@gmail.com', '0096253556',
   'نظام توثيق المشاكل الداخلية وإدارة البلاغات',
   '1778068807_offical-logo-2 (1).png');

-- 4. إعدادات المظهر
CREATE TABLE `sys_theme` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `system_font`   VARCHAR(100) NOT NULL DEFAULT 'Cairo',
  `sidebar_color` VARCHAR(20)  NOT NULL DEFAULT '#0d4a1c',
  `header_color`  VARCHAR(20)  NOT NULL DEFAULT '#21409a',
  `main_color`    VARCHAR(20)  NOT NULL DEFAULT '#3b8248',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إعدادات المظهر البصري';

INSERT INTO `sys_theme` VALUES (1, 'Almarai', '#0d4a1c', '#21409a', '#3b8248');

-- 5. قائمة الشريط الجانبي
CREATE TABLE `sys_menu` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(100) NOT NULL,
  `icon`           VARCHAR(60)  NOT NULL DEFAULT 'far fa-circle',
  `link`           VARCHAR(255) NOT NULL DEFAULT '#',
  `parent_id`      INT(11)      NOT NULL DEFAULT 0,
  `permission_key` VARCHAR(100) DEFAULT NULL,
  `sort_order`     INT(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='هيكل القائمة الجانبية الديناميكي';

INSERT INTO `sys_menu` (`id`,`title`,`icon`,`link`,`parent_id`,`sort_order`) VALUES
(1,  'إعداد الشجرة',           'fas fa-cogs',        '#',                                   0, 1),
(2,  'تهيئة النظام',           'fas fa-cogs',        '#',                                   0, 2),
(3,  'بيانات المستخدمين',      'fas fa-chart-pie',   '#',                                   0, 3),
(4,  'صلاحيات المستخدمين',     'fas fa-user-shield', '#',                                   0, 4),
(5,  'هيكلة البلاغات',         'fas fa-sitemap',     '#',                                   0, 5),
(6,  'إدارة البلاغات',         'fas fa-tools',       '#',                                   0, 6),
(7,  'قائمة المهام للفنيين',   'fas fa-tasks',       '#',                                   0, 7),
(8,  'إضافة قائمة جانبية',     'far fa-circle',      'pages/forms/add-sidebar.php',         1, 1),
(9,  'الإعدادات العامة',       'far fa-circle',      'pages/forms/system-settings.php',     2, 1),
(10, 'عرض الإعدادات العامة',   'far fa-circle',      'pages/tables/show-settings.php',      2, 2),
(11, 'تهيئة المدخلات',         'far fa-circle',      'pages/forms/system-inputs.php',       2, 3),
(12, 'النسخ الاحتياطي',        'far fa-circle',      'pages/forms/system-buckup.php',       2, 4),
(13, 'سجل النظام (Logs)',      'far fa-circle',      'pages/tables/show-logs.php',          2, 5),
(14, 'إضافة مستخدم',           'far fa-circle',      'pages/forms/add-user.php',            3, 1),
(15, 'عرض المستخدمين',         'far fa-circle',      'pages/tables/show-users.php',         3, 2),
(16, 'تقارير المستخدمين',      'far fa-circle',      'pages/tables/reports-users.php',      3, 3),
(17, 'إضافة صلاحية',           'far fa-circle',      'pages/forms/add-role.php',            4, 1),
(18, 'تعيين الصلاحيات',        'far fa-circle',      'pages/tables/assign-permissions.php', 4, 2),
(19, 'عرض الصلاحيات',          'far fa-circle',      'pages/tables/view-permissions.php',   4, 3),
(20, 'إضافة فرع',              'far fa-circle',      'pages/forms/addbranch.php',           5, 1),
(21, 'إضافة منطقة',            'far fa-circle',      'pages/forms/add-college.php',         5, 2),
(22, 'إضافة تصنيف مشكلة',     'far fa-circle',      'pages/forms/add-group.php',           5, 3),
(23, 'إضافة قسم',              'far fa-circle',      'pages/forms/add-lab.php',             5, 4),
(24, 'إضافة بلاغ',             'far fa-circle',      'pages/forms/add-request.php',         6, 1),
(25, 'عرض البلاغات',           'far fa-circle',      'pages/tables/show-requests.php',      6, 2),
(26, 'تقارير البلاغات',        'far fa-circle',      'pages/tables/report-requests.php',    6, 3),
(27, 'إضافة مهمة',             'far fa-circle',      'pages/forms/add-task.php',            7, 1),
(28, 'عرض المهام',             'far fa-circle',      'pages/tables/show-tasks.php',         7, 2),
(29, 'تقارير المهام',          'far fa-circle',      'pages/tables/report-tasks.php',       7, 3),
(42, 'بيانات الموظفين الداخليين', 'fas fa-users',     '#',                                   0, 8),
(43, 'إضافة موظف داخلي',        'far fa-circle',    'pages/forms/add-cstmr.php',          42, 1),
(46, 'عرض بيانات الموظفين',     'far fa-circle',    'pages/tables/show-cstmr.php',        42, 2),
(47, 'تقارير الموظفين',          'far fa-circle',    'pages/tables/reports-cstmr.php',     42, 3),
(49, 'قائمة الـ AI',             'fas fa-microchip', '#',                                   0, 9),
(50, 'أسألني',                   'far fa-circle',    'pages/forms/ask-me.php',             49, 1),
(57, 'الملف الشخصي',             'far fa-user-circle','pages/forms/profile.php',            0,10),
(58, 'قواعد SLA',                'far fa-clock',     'pages/tables/show-sla.php',           2, 6),
(59, 'الإشعارات',                'far fa-bell',      'pages/tables/notifications.php',      0,11);

-- 6. صلاحيات النظام (نوع الإجراء)
CREATE TABLE `sys_permissions` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `perm_key`  VARCHAR(100) NOT NULL,
  `perm_name` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_key` (`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تعريفات الصلاحيات المتاحة';

-- ════════════════════════════════════════════════════════════
--  ② جداول التحكم بالوصول
-- ════════════════════════════════════════════════════════════

-- 7. ربط المستخدمين بالأدوار
CREATE TABLE `user_roles` (
  `id`      INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `role_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role` (`user_id`,`role_id`),
  KEY `idx_role_id` (`role_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `sys_roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ربط المستخدمين بالأدوار الوظيفية';

INSERT INTO `user_roles` (`id`,`user_id`,`role_id`) VALUES (105, 2, 1);

-- 8. صلاحيات صفحات القائمة
CREATE TABLE `user_menu_access` (
  `id`         INT(11)    NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)    NOT NULL,
  `menu_id`    INT(11)    NOT NULL,
  `can_view`   TINYINT(1) NOT NULL DEFAULT 0,
  `can_add`    TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit`   TINYINT(1) NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_menu` (`user_id`,`menu_id`),
  KEY `idx_menu_id` (`menu_id`),
  CONSTRAINT `fk_uma_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uma_menu` FOREIGN KEY (`menu_id`) REFERENCES `sys_menu`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='صلاحيات المستخدمين على كل صفحة';

INSERT INTO `user_menu_access` (`user_id`,`menu_id`,`can_view`,`can_add`,`can_edit`,`can_delete`) VALUES
(2, 1,1,1,1,1),(2, 2,1,1,1,1),(2, 3,1,1,1,1),(2, 4,1,1,1,1),
(2, 5,1,1,1,1),(2, 6,1,1,1,1),(2, 7,1,1,1,1),(2, 8,1,1,1,1),
(2, 9,1,1,1,1),(2,10,1,1,1,1),(2,11,1,1,1,1),(2,12,1,1,1,1),
(2,13,1,1,1,1),(2,14,1,1,1,1),(2,15,1,1,1,1),(2,16,1,1,1,1),
(2,17,1,1,1,1),(2,18,1,1,1,1),(2,19,1,1,1,1),(2,20,1,1,1,1),
(2,21,1,1,1,1),(2,22,1,1,1,1),(2,23,1,1,1,1),(2,24,1,1,1,1),
(2,25,1,1,1,1),(2,26,1,1,1,1),(2,27,1,1,1,1),(2,28,1,1,1,1),
(2,29,1,1,1,1),(2,42,1,1,1,1),(2,43,1,1,1,1),(2,46,1,1,1,1),
(2,47,1,1,1,1),(2,49,1,1,1,1),(2,50,1,1,1,1),(2,51,1,1,1,1),
(2,52,1,1,1,1),(2,53,1,1,1,1),(2,54,1,1,1,1),(2,55,1,1,1,1),
(2,56,1,1,1,1),(2,57,1,1,1,1),(2,58,1,1,1,1),(2,59,1,1,1,1),
(17, 6,1,1,1,1),(17, 7,1,1,1,1),(17,24,1,1,1,1),(17,25,1,1,1,1),
(17,26,1,0,0,0),(17,27,1,1,1,1),(17,28,1,1,1,1),(17,29,1,0,0,0),
(17,57,1,0,1,0);

-- ════════════════════════════════════════════════════════════
--  ③ الهيكل التنظيمي
-- ════════════════════════════════════════════════════════════

-- 9. الفروع الجغرافية
CREATE TABLE `branches` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `branch_name` VARCHAR(100) NOT NULL,
  `city`        VARCHAR(100) DEFAULT NULL COMMENT 'المدينة',
  `region`      ENUM(
    'الرياض','مكة المكرمة','المدينة المنورة','القصيم',
    'المنطقة الشرقية','عسير','تبوك','حائل',
    'الحدود الشمالية','جازان','نجران','الباحة','الجوف','أخرى'
  ) DEFAULT 'أخرى' COMMENT 'المنطقة الإدارية',
  `address`     TEXT         DEFAULT NULL,
  `phone`       VARCHAR(20)  DEFAULT NULL,
  `manager_id`  INT(11)      DEFAULT NULL COMMENT 'مدير الفرع FK→sys_users',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_manager` (`manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الفروع الجغرافية للشركة';

INSERT INTO `branches` (`id`,`branch_name`,`city`,`region`) VALUES
(8, 'فرع جدة',   'جدة',   'مكة المكرمة'),
(9, 'فرع الرياض','الرياض','الرياض');

-- 10. المناطق (مستوى ثانٍ تحت الفرع — كانت colleges)
CREATE TABLE `regions` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `region_name` VARCHAR(255) NOT NULL,
  `branch_id`   INT(11)      NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_branch_id` (`branch_id`),
  CONSTRAINT `fk_region_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='مناطق التشغيل (مستوى ثانٍ تحت الفرع)';

INSERT INTO `regions` (`id`,`region_name`,`branch_id`) VALUES
(5, 'السعودية', 9),
(6, 'اليمن',    8);

-- 11. الأقسام (مستوى ثالث — كانت labs)
CREATE TABLE `departments` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `department_name`VARCHAR(255) NOT NULL,
  `region_id`      INT(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_region_id` (`region_id`),
  CONSTRAINT `fk_dept_region`
    FOREIGN KEY (`region_id`) REFERENCES `regions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الأقسام والوحدات التنظيمية (مستوى ثالث)';

INSERT INTO `departments` (`id`,`department_name`,`region_id`) VALUES
(6, 'A',  5),
(7, 'AB', 6);

-- 12. تصنيفات المشاكل الفنية (كانت groups)
CREATE TABLE `issue_categories` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(100) NOT NULL,
  `description`   VARCHAR(500) DEFAULT NULL,
  `color`         VARCHAR(7)   DEFAULT '#6c757d' COMMENT 'لون الشارة (#hex)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تصنيفات أنواع المشاكل الفنية';

INSERT INTO `issue_categories` (`id`,`category_name`,`color`) VALUES
(1, 'Hardware', '#dc3545'),
(3, 'Software', '#0d6efd'),
(4, 'Network',  '#198754');

-- ════════════════════════════════════════════════════════════
--  ④ العملاء والمندوبون
-- ════════════════════════════════════════════════════════════

-- 13. العملاء (كانت Customers) — بحقول سعودية موسّعة
CREATE TABLE `clients` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `client_name`        VARCHAR(255) NOT NULL COMMENT 'اسم العميل أو الشركة',
  `client_type`        ENUM('individual','company','government','semi_government') NOT NULL DEFAULT 'company',
  `client_code`        VARCHAR(20)  DEFAULT NULL COMMENT 'الرمز الداخلي',
  `phone`              VARCHAR(20)  NOT NULL,
  `email`              VARCHAR(100) DEFAULT NULL,
  `password`           VARCHAR(255) NOT NULL,
  `address`            TEXT         DEFAULT NULL,
  `city`               VARCHAR(100) DEFAULT NULL,
  `district`           VARCHAR(100) DEFAULT NULL COMMENT 'الحي',
  `postal_code`        VARCHAR(10)  DEFAULT NULL COMMENT 'الرمز البريدي',
  `country`            VARCHAR(3)   NOT NULL DEFAULT 'SA' COMMENT 'ISO 3166-1',
  `department_id`      INT(11)      DEFAULT NULL COMMENT 'FK→departments.id',
  `location_id`        INT(11)      DEFAULT NULL COMMENT 'مرجع شجرة المواقع',
  `national_id`        VARCHAR(10)  DEFAULT NULL COMMENT 'الهوية/الإقامة',
  `cr_number`          VARCHAR(20)  DEFAULT NULL COMMENT 'رقم السجل التجاري',
  `cr_expiry_hijri`    VARCHAR(20)  DEFAULT NULL COMMENT 'تاريخ انتهاء السجل هجري',
  `cr_expiry_date`     DATE         DEFAULT NULL COMMENT 'تاريخ انتهاء السجل ميلادي',
  `vat_number`         VARCHAR(15)  DEFAULT NULL COMMENT 'الرقم الضريبي (15 رقماً)',
  `capital`            DECIMAL(15,2)DEFAULT NULL COMMENT 'رأس المال',
  `owner_name`         VARCHAR(255) DEFAULT NULL COMMENT 'اسم صاحب العمل',
  `owner_id`           VARCHAR(10)  DEFAULT NULL COMMENT 'هوية صاحب العمل',
  `commercial_activity`TEXT         DEFAULT NULL COMMENT 'النشاط التجاري',
  `status`             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_email` (`email`),
  KEY `idx_dept_id`   (`department_id`),
  KEY `idx_status`    (`status`),
  KEY `idx_type`      (`client_type`),
  KEY `idx_cr_number` (`cr_number`),
  CONSTRAINT `fk_client_dept`
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بيانات العملاء (أفراد وشركات وجهات حكومية)';

INSERT INTO `clients`
  (`id`,`client_name`,`client_type`,`phone`,`email`,`password`,`address`,`department_id`,`status`) VALUES
(4, 'محمد منير',       'individual','966553929565','admin@gmail.com',
   '$2y$10$Wc3C44izmVrruAuZxlxoauRlbRbo6hW6cXAMcxHoghEQ79RmCocky','جدة',   7,'active'),
(5, 'الرابح الدولية',  'company',   '055369845698','alrabeh@gmail.com',
   '$2y$10$7DTRzGLbiuJiXhEnzqggKOipYvx8y6AtBB/rC6GLBrgyfItxMqILi','حي السلامة',6,'active'),
(6, 'شركة العمدة',     'company',   '05536985656', 'alomda@gmail.com',
   '$2y$10$OS25MryLVFzdwuLHKQ83VeyJdxFkvRkBAXUXRbpRsqQgPD0E5hd0a','جدة - شارع الصفا',7,'active');

-- 14. جهات اتصال العملاء (جديد)
CREATE TABLE `client_contacts` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `client_id`    INT(11)      NOT NULL,
  `contact_name` VARCHAR(255) NOT NULL COMMENT 'اسم جهة الاتصال',
  `job_title`    VARCHAR(100) DEFAULT NULL,
  `phone`        VARCHAR(20)  DEFAULT NULL,
  `email`        VARCHAR(100) DEFAULT NULL,
  `is_primary`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'جهة الاتصال الرئيسية',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client_id` (`client_id`),
  CONSTRAINT `fk_cc_client`
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='جهات اتصال العملاء (أشخاص متعددون لكل شركة)';

-- 15. المندوبون (كانت Vendors)
CREATE TABLE `agents` (
  `id`         INT(11)                   NOT NULL AUTO_INCREMENT,
  `agent_name` VARCHAR(255)              NOT NULL COMMENT 'اسم المندوب',
  `phone`      VARCHAR(20)               NOT NULL,
  `email`      VARCHAR(100)              DEFAULT NULL,
  `password`   VARCHAR(255)              NOT NULL,
  `national_id`VARCHAR(10)               DEFAULT NULL,
  `address`    TEXT                      DEFAULT NULL,
  `branch_id`  INT(11)                   DEFAULT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_email` (`email`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_status`    (`status`),
  CONSTRAINT `fk_agent_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بيانات مندوبي المبيعات والممثلين التجاريين';

INSERT INTO `agents` (`id`,`agent_name`,`phone`,`email`,`password`,`address`,`branch_id`,`status`) VALUES
(2, 'يوسف المقرمي',  '966532487929', 'ysf@gmail.com',   '$2y$10$9/Q/SPT9bgI/f4XJ2a2dqeP//rAHZg0yJF.j7fplEttjTrtBGK0GO','جدة شارع خالد',8,'active'),
(3, 'عدنان البركاني','0556454564',   'adnan@gmail.com', '$2y$10$AGT8H2d66FVTOB8AkSadRu2cRDUwjHjIZUet4Ztd0DcK4qsZtPnCq','شارع فلسطين', 8,'active');

-- 16. تعيينات المندوبين على العملاء (كانت target_assignments)
CREATE TABLE `agent_assignments` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `agent_id`    INT(11)      NOT NULL COMMENT 'FK→agents.id',
  `client_id`   INT(11)      NOT NULL COMMENT 'FK→clients.id',
  `target_date` DATE         NOT NULL,
  `notes`       VARCHAR(500) DEFAULT NULL,
  `created_by`  INT(11)      NOT NULL COMMENT 'FK→sys_users.id',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_id`  (`agent_id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_created_by`(`created_by`),
  CONSTRAINT `fk_aa_agent`
    FOREIGN KEY (`agent_id`)   REFERENCES `agents`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_aa_client`
    FOREIGN KEY (`client_id`)  REFERENCES `clients`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_aa_user`
    FOREIGN KEY (`created_by`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ربط المندوبين بالعملاء المستهدفين';

INSERT INTO `agent_assignments` (`id`,`agent_id`,`client_id`,`target_date`,`notes`,`created_by`) VALUES
(1, 2, 5, '2026-05-09', 'المدة 6 أشهر من تاريخ الاستهداف', 2),
(2, 3, 6, '2026-02-09', 'المدة 6 أشهر من تاريخ الاستهداف', 2);

-- ════════════════════════════════════════════════════════════
--  ⑤ إدارة مستويات الخدمة (SLA)
-- ════════════════════════════════════════════════════════════

-- 17. قواعد مستوى الخدمة (جديد)
CREATE TABLE `sla_rules` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `rule_name`        VARCHAR(100) NOT NULL COMMENT 'مثال: عاجل – 2 ساعة',
  `priority`         ENUM('Urgent','High','Medium','Low') NOT NULL,
  `response_hours`   DECIMAL(5,2) NOT NULL DEFAULT 2.00  COMMENT 'ساعات أول رد',
  `resolution_hours` DECIMAL(5,2) NOT NULL DEFAULT 8.00  COMMENT 'ساعات إغلاق كامل',
  `applies_to_type`  VARCHAR(50)  DEFAULT NULL COMMENT 'نوع العميل المستهدف (اختياري)',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تعريفات مستويات الخدمة (SLA) حسب الأولوية';

INSERT INTO `sla_rules` (`rule_name`,`priority`,`response_hours`,`resolution_hours`) VALUES
('عاجل جداً – SLA حرج',    'Urgent',  1.00,  4.00),
('عالي – SLA مرتفع',       'High',    2.00,  8.00),
('متوسط – SLA قياسي',      'Medium',  4.00, 24.00),
('منخفض – SLA عادي',       'Low',     8.00, 72.00);

-- ════════════════════════════════════════════════════════════
--  ⑥ التذاكر والمهام
-- ════════════════════════════════════════════════════════════

-- 18. التذاكر / البلاغات (كانت requests)
CREATE TABLE `tickets` (
  `id`                  INT(11)   NOT NULL AUTO_INCREMENT,
  `ticket_number`       VARCHAR(20) DEFAULT NULL
                          COMMENT 'رقم التذكرة المُولَّد تلقائياً',
  `reporter_ref`        VARCHAR(50) NOT NULL  COMMENT 'رقم مرجعي للمُبلِّغ',
  `client_id`           INT(11)     DEFAULT NULL,
  `branch_id`           INT(11)     NOT NULL,
  `region_id`           INT(11)     NOT NULL,
  `department_id`       INT(11)     DEFAULT NULL,
  `location_name`       VARCHAR(255)DEFAULT NULL,
  `category_id`         INT(11)     NOT NULL COMMENT 'FK→issue_categories',
  `priority`            ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
  `details`             TEXT        DEFAULT NULL,
  `status`              ENUM('Pending','In Progress','Resolved','Cancelled') NOT NULL DEFAULT 'Pending',
  `sla_rule_id`         INT(11)     DEFAULT NULL,
  `sla_breach_at`       DATETIME    DEFAULT NULL COMMENT 'موعد خرق SLA',
  `sla_breached`        TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = تم خرق SLA',
  `escalated`           TINYINT(1)  NOT NULL DEFAULT 0,
  `escalated_at`        DATETIME    DEFAULT NULL,
  `first_response_at`   DATETIME    DEFAULT NULL COMMENT 'وقت أول رد فعلي',
  `closed_by`           INT(11)     DEFAULT NULL,
  `closed_at`           TIMESTAMP   NULL DEFAULT NULL,
  `resolution_time_hrs` DECIMAL(10,2) DEFAULT NULL COMMENT 'وقت الحل الفعلي بالساعات',
  `created_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status`       (`status`),
  KEY `idx_priority`     (`priority`),
  KEY `idx_created_at`   (`created_at`),
  KEY `idx_branch_id`    (`branch_id`),
  KEY `idx_region_id`    (`region_id`),
  KEY `idx_dept_id`      (`department_id`),
  KEY `idx_category_id`  (`category_id`),
  KEY `idx_client_id`    (`client_id`),
  KEY `idx_sla_breach`   (`sla_breached`,`status`),
  KEY `idx_closed_at`    (`closed_at`),
  CONSTRAINT `fk_tk_branch`    FOREIGN KEY (`branch_id`)     REFERENCES `branches`(`id`),
  CONSTRAINT `fk_tk_region`    FOREIGN KEY (`region_id`)     REFERENCES `regions`(`id`),
  CONSTRAINT `fk_tk_dept`      FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_tk_category`  FOREIGN KEY (`category_id`)   REFERENCES `issue_categories`(`id`),
  CONSTRAINT `fk_tk_client`    FOREIGN KEY (`client_id`)     REFERENCES `clients`(`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_tk_closed_by` FOREIGN KEY (`closed_by`)     REFERENCES `sys_users`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_tk_sla`       FOREIGN KEY (`sla_rule_id`)   REFERENCES `sla_rules`(`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تذاكر الدعم الفني – دورة الحياة الكاملة مع SLA'
  ROW_FORMAT=DYNAMIC;

CREATE TRIGGER `trg_tickets_before_insert` BEFORE INSERT ON `tickets`
FOR EACH ROW
SET NEW.ticket_number = CONCAT('TK-', LPAD(
  (SELECT AUTO_INCREMENT FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'), 6, '0'
));

-- 19. تعليقات التذاكر (جديد)
CREATE TABLE `ticket_comments` (
  `id`          INT(11)    NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT(11)    NOT NULL,
  `user_id`     INT(11)    NOT NULL,
  `comment`     TEXT       NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = داخلي للموظفين فقط',
  `created_at`  TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_user_id`   (`user_id`),
  CONSTRAINT `fk_tcmt_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_tcmt_user`   FOREIGN KEY (`user_id`)   REFERENCES `sys_users`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تعليقات ومتابعة التذاكر';

-- 20. مرفقات التذاكر (جديد)
CREATE TABLE `ticket_attachments` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT(11)      NOT NULL,
  `uploaded_by` INT(11)      NOT NULL,
  `file_name`   VARCHAR(255) NOT NULL,
  `file_path`   VARCHAR(500) NOT NULL,
  `file_type`   VARCHAR(100) DEFAULT NULL,
  `file_size`   INT(11)      NOT NULL DEFAULT 0 COMMENT 'الحجم بالبايت',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  CONSTRAINT `fk_ta_ticket`   FOREIGN KEY (`ticket_id`)   REFERENCES `tickets`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_ta_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `sys_users`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ملفات مرفقة بالتذاكر (صور، مستندات)';

-- 21. أوامر العمل / المهام (كانت tasks)
CREATE TABLE `work_orders` (
  `id`          INT(11)                                              NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT(11)                                             DEFAULT NULL,
  `assigned_to` INT(11)                                             NOT NULL,
  `created_by`  INT(11)                                             NOT NULL,
  `title`       VARCHAR(255)                                        NOT NULL,
  `details`     TEXT                                                DEFAULT NULL,
  `priority`    ENUM('Normal','Medium','High','Critical')           NOT NULL DEFAULT 'Normal',
  `status`      ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `deadline`    DATETIME                                            DEFAULT NULL,
  `completed_at`DATETIME                                            DEFAULT NULL,
  `created_at`  TIMESTAMP                                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP                                           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id`  (`ticket_id`),
  KEY `idx_assigned_to`(`assigned_to`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status`     (`status`),
  KEY `idx_deadline`   (`deadline`),
  KEY `idx_composite`  (`assigned_to`,`status`,`deadline`),
  CONSTRAINT `fk_wo_ticket`   FOREIGN KEY (`ticket_id`)   REFERENCES `tickets`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_wo_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `sys_users`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_wo_creator`  FOREIGN KEY (`created_by`)  REFERENCES `sys_users`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='أوامر العمل المسندة للفنيين';

-- ════════════════════════════════════════════════════════════
--  ⑦ التواصل والإشعارات
-- ════════════════════════════════════════════════════════════

-- 22. الرسائل الداخلية
CREATE TABLE `messages` (
  `id`           INT(11)    NOT NULL AUTO_INCREMENT,
  `sender_id`    INT(11)    NOT NULL,
  `receiver_id`  INT(11)    NOT NULL,
  `message_text` TEXT       NOT NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sender`     (`sender_id`),
  KEY `idx_receiver`   (`receiver_id`),
  KEY `idx_inbox`      (`receiver_id`,`is_read`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_msg_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `sys_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='نظام المراسلة الداخلي';

INSERT INTO `messages` (`id`,`sender_id`,`receiver_id`,`message_text`,`is_read`) VALUES
(1, 2,17,'السلام عليكم',1),(2, 2,17,'done',1),
(3,17, 2,'hi',          1),(4,17, 2,'done',1);

-- 23. إشعارات النظام (جديد)
CREATE TABLE `notifications` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)      NOT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `body`         TEXT         DEFAULT NULL,
  `type`         ENUM('ticket','task','message','system','sla_breach') NOT NULL DEFAULT 'system',
  `reference_id` INT(11)      DEFAULT NULL COMMENT 'معرّف الكيان المرتبط',
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_unread` (`user_id`,`is_read`),
  KEY `idx_created_at`  (`created_at`),
  KEY `idx_type`        (`type`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إشعارات النظام للمستخدمين (تذاكر، مهام، SLA)';

-- ════════════════════════════════════════════════════════════
--  ⑧ صلاحيات الوصول الإضافية
-- ════════════════════════════════════════════════════════════

-- 24. ربط المستخدمين بالفروع
CREATE TABLE `user_branch_access` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)   NOT NULL,
  `branch_id`   INT(11)   NOT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_branch` (`user_id`,`branch_id`),
  KEY `idx_branch_id` (`branch_id`),
  CONSTRAINT `fk_uba_user`   FOREIGN KEY (`user_id`)   REFERENCES `sys_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uba_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ربط المستخدمين بالفروع المسموح لهم بالوصول إليها';

INSERT INTO `user_branch_access` (`id`,`user_id`,`branch_id`) VALUES
(18,17,8),(19,2,8);

-- 25. وصول المستخدمين لتصنيفات المشاكل
CREATE TABLE `user_category_access` (
  `user_id`     INT(11) NOT NULL,
  `category_id` INT(11) NOT NULL,
  PRIMARY KEY (`user_id`,`category_id`),
  KEY `idx_category_id` (`category_id`),
  CONSTRAINT `fk_uca_user`     FOREIGN KEY (`user_id`)     REFERENCES `sys_users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_uca_category` FOREIGN KEY (`category_id`) REFERENCES `issue_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='التصنيفات التي يُسمح للمستخدم بالاطلاع عليها';

INSERT INTO `user_category_access` (`user_id`,`category_id`) VALUES
(2,1),(2,3),(2,4);

-- ════════════════════════════════════════════════════════════
--  ⑨ سجل التدقيق
-- ════════════════════════════════════════════════════════════

-- 26. سجل التدقيق الموحَّد
CREATE TABLE `audit_logs` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      DEFAULT NULL,
  `user_name`  VARCHAR(255) DEFAULT NULL,
  `action`     TEXT         NOT NULL,
  `entity`     VARCHAR(100) DEFAULT NULL COMMENT 'اسم الجدول أو الكيان',
  `entity_id`  INT(11)      DEFAULT NULL COMMENT 'معرّف السجل المتأثر',
  `old_values` JSON         DEFAULT NULL COMMENT 'القيم قبل التعديل',
  `new_values` JSON         DEFAULT NULL COMMENT 'القيم بعد التعديل',
  `page_url`   VARCHAR(500) DEFAULT NULL,
  `ip_address` VARCHAR(50)  DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_entity`     (`entity`,`entity_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_log_user`
    FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل تدقيق كامل لكل إجراء في النظام'
  ROW_FORMAT=COMPRESSED;

INSERT INTO `audit_logs` (`user_id`,`user_name`,`action`,`entity`,`page_url`,`ip_address`) VALUES
(2,'محمد منير','تحديث الإعدادات العامة','sys_settings',
 '/tlink/admin/pages/forms/system-settings.php','175.110.189.146');

-- ════════════════════════════════════════════════════════════
--  ⑩ الذكاء الاصطناعي
-- ════════════════════════════════════════════════════════════

-- 27. جلسات الذكاء الاصطناعي (كانت ai_chat)
CREATE TABLE `ai_sessions` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)   NOT NULL,
  `question`    TEXT      NOT NULL,
  `answer`      LONGTEXT  NOT NULL,
  `tokens_used` INT(11)   NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_ai_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل محادثات الذكاء الاصطناعي';

-- 28. بيانات تدريب النموذج (كانت chatbot_training)
CREATE TABLE `ai_training` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `question`          MEDIUMTEXT   DEFAULT NULL,
  `intent`            VARCHAR(255) NOT NULL,
  `sql_query`         MEDIUMTEXT   DEFAULT NULL,
  `expected_response` MEDIUMTEXT   DEFAULT NULL,
  `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_intent` (`intent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بيانات تدريب نموذج الذكاء الاصطناعي';

INSERT INTO `ai_training` (`id`,`intent`,`sql_query`,`expected_response`) VALUES
(1,'count_pending_tickets',  'SELECT COUNT(*) FROM tickets WHERE status = "Pending"',                    'إجمالي التذاكر المعلقة:'),
(2,'show_users',             'SELECT full_name, email FROM sys_users',                                   'قائمة مستخدمي النظام:'),
(3,'show_clients',           'SELECT client_name, phone, address FROM clients',                          'قائمة الموظفين الداخليين:'),
(4,'count_sla_breached',     'SELECT COUNT(*) FROM tickets WHERE sla_breached = 1 AND status != "Resolved"','التذاكر التي تجاوزت SLA:');

-- 29. أسئلة التدريب (كانت training_questions)
CREATE TABLE `ai_questions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `question`   MEDIUMTEXT   NOT NULL,
  `intent`     VARCHAR(255) NOT NULL,
  `category`   VARCHAR(255) NOT NULL DEFAULT 'general',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_intent`   (`intent`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='قائمة أسئلة تدريب الذكاء الاصطناعي';

INSERT INTO `ai_questions` (`id`,`question`,`intent`,`category`) VALUES
(1,'كم عدد البلاغات المعلقة؟',        'count_pending_tickets',   'tickets'),
(2,'عرض قائمة المستخدمين',            'show_users',              'users'),
(3,'أظهر لي بيانات الموظفين الداخليين','show_clients',            'clients'),
(4,'ما التذاكر المعلقة؟',             'show_pending_tickets',    'tickets'),
(5,'كم عدد التذاكر الكلي؟',           'count_tickets',           'tickets'),
(6,'كم عدد المهام المكتملة؟',         'count_completed_tasks',   'tasks'),
(7,'أظهر جميع المستخدمين',            'show_users',              'users'),
(8,'ما المشكلات قيد التنفيذ؟',        'show_inprogress_tickets', 'tickets'),
(9,'كم تذكرة تجاوزت مستوى الخدمة؟',  'count_sla_breached',      'sla');

-- ════════════════════════════════════════════════════════════
--  ⑪ جداول ERP (محتفظ بها كما هي — نظام خارجي)
-- ════════════════════════════════════════════════════════════

CREATE TABLE `GNR_ACCNT_TREE` (
  `TYP_NO`               INT(5)        NOT NULL,
  `AC_NO`                BIGINT(30)    DEFAULT NULL,
  `AC_CODE`              VARCHAR(30)   NOT NULL,
  `AC_CODE_T`            VARCHAR(30)   NOT NULL,
  `AC_L_NM`              VARCHAR(100)  NOT NULL,
  `AC_F_NM`              VARCHAR(100)  DEFAULT NULL,
  `LVL_NO`               INT(5)        NOT NULL,
  `AC_PARNT`             VARCHAR(30)   NOT NULL,
  `GRP_NO`               INT(5)        DEFAULT NULL,
  `RPRT_TYP_NO`          INT(5)        NOT NULL,
  `EFCT_TRNS`            TINYINT(1)    DEFAULT 0,
  `AC_DR`                TINYINT(1)    DEFAULT NULL,
  `FVRT_FLG`             TINYINT(1)    DEFAULT 0,
  `AC_FLW_TYP`           TINYINT(1)    DEFAULT NULL,
  `CNFRM_LST_DATE`       DATETIME      DEFAULT NULL,
  `IMP_XLS_FLG`          TINYINT(1)    DEFAULT 0,
  `ANLS_NO`              INT(5)        DEFAULT NULL,
  `AC_DTL_TYP`           TINYINT(2)    NOT NULL,
  `AC_BS`                TINYINT(1)    DEFAULT NULL,
  `BGT_TRNSFR_TYP`       TINYINT(1)    DEFAULT NULL,
  `SHW_EST_BLNC`         TINYINT(1)    DEFAULT 0,
  `CHK_EST_BLNC`         TINYINT(1)    DEFAULT 0,
  `SHW_HRS`              TINYINT(1)    DEFAULT 0,
  `SHW_FAS`              TINYINT(1)    DEFAULT 0,
  `SHW_MRP`              TINYINT(1)    DEFAULT 0,
  `SHW_INV`              TINYINT(1)    DEFAULT 0,
  `SHW_INTRFC_ACCNT`     TINYINT(1)    DEFAULT 0,
  `SUB_LDGR1_MNDTRY_FLG` TINYINT(1)   DEFAULT 0,
  `SUB_LDGR2_MNDTRY_FLG` TINYINT(1)   DEFAULT 0,
  `SUB_LDGR3_MNDTRY_FLG` TINYINT(1)   DEFAULT 0,
  `SUB_LDGR4_MNDTRY_FLG` TINYINT(1)   DEFAULT 0,
  `SUB_LDGR5_MNDTRY_FLG` TINYINT(1)   DEFAULT 0,
  `SUB_LDGR6_MNDTRY_FLG` TINYINT(1)   DEFAULT 0,
  `F_DATE_EFCT`          DATE          DEFAULT NULL,
  `T_DATE_EFCT`          DATE          DEFAULT NULL,
  `F_DATE_INACTV`        DATE          DEFAULT NULL,
  `T_DATE_INACTV`        DATE          DEFAULT NULL,
  `SHW_PRL`              TINYINT(1)    DEFAULT 0,
  `NOTES`                VARCHAR(150)  DEFAULT NULL,
  `OPN_AC_CODE`          VARCHAR(30)   DEFAULT NULL,
  `AC_FLW_TYP_ANLS`     TINYINT(2)    DEFAULT NULL,
  `DOC_SRL_REF`          VARCHAR(256)  DEFAULT NULL,
  `DOC_M_SQ_REF`         BIGINT(38)    DEFAULT NULL,
  `DOC_NO_REF`           BIGINT(30)    DEFAULT NULL,
  `INACTV_FLG`           TINYINT(1)    NOT NULL DEFAULT 0,
  `INACTV_USR`           INT(10)       DEFAULT NULL,
  `INACTV_DATE`          DATETIME      DEFAULT NULL,
  `INACTV_RSON`          VARCHAR(150)  DEFAULT NULL,
  `CRT_USR`              INT(10)       NOT NULL,
  `CRT_DATE`             DATETIME      NOT NULL,
  `CRT_DATE_CLK`         DATETIME      NOT NULL,
  `CRT_TRMNL_NM`         VARCHAR(50)   DEFAULT NULL,
  `PRNT_CNT`             BIGINT(30)    DEFAULT 0,
  `UPD_CNT`              BIGINT(30)    DEFAULT 0,
  `UPD_USR`              INT(10)       DEFAULT NULL,
  `UPD_DATE`             DATETIME      DEFAULT NULL,
  `UPD_TRMNL_NM`         VARCHAR(50)   DEFAULT NULL,
  `SHW_GRNTY`            TINYINT(1)    DEFAULT 0,
  `SHW_FMS`              TINYINT(1)    DEFAULT 0,
  `AC_CODE_MNL`          VARCHAR(30)   DEFAULT NULL,
  PRIMARY KEY (`AC_CODE`),
  KEY `idx_ac_parnt` (`AC_PARNT`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='شجرة الحسابات – نظام ERP';

CREATE TABLE `GNR_FN_UNT` (
  `UNT_NO`       INT(10)       NOT NULL,
  `UNT_L_NM`     VARCHAR(100)  NOT NULL,
  `UNT_F_NM`     VARCHAR(100)  DEFAULT NULL,
  `UNT_PARNT`    INT(10)       DEFAULT NULL,
  `LVL_NO`       INT(5)        DEFAULT NULL,
  `UNT_CODE`     VARCHAR(20)   DEFAULT NULL,
  `CNTRY_NO`     VARCHAR(30)   NOT NULL,
  `CRT_USR`      INT(10)       NOT NULL,
  `CRT_DATE`     DATETIME      NOT NULL,
  `CRT_DATE_CLK` DATETIME      NOT NULL,
  PRIMARY KEY (`UNT_NO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الوحدات التنظيمية – نظام ERP';

CREATE TABLE `GNR_PST_DTL` (
  `DOC_TYP`  INT(5)         NOT NULL,
  `DOC_NO`   BIGINT(30)     NOT NULL,
  `DOC_SRL`  VARCHAR(256)   NOT NULL,
  `DOC_M_SQ` BIGINT(38)     NOT NULL,
  `UNT_NO`   INT(10)        NOT NULL,
  `YR_NO`    INT(4)         NOT NULL,
  `DOC_DATE` DATE           NOT NULL,
  `GL_DATE`  DATE           NOT NULL,
  `AC_CODE`  VARCHAR(30)    NOT NULL,
  `CUR_CODE` VARCHAR(3)     NOT NULL,
  `AMT`      DECIMAL(18,5)  DEFAULT 0.00000,
  `AMT_L`    DECIMAL(18,5)  DEFAULT 0.00000,
  `DR_AMT_L` DECIMAL(18,5)  DEFAULT 0.00000,
  `CR_AMT_L` DECIMAL(18,5)  DEFAULT 0.00000,
  `DBS_USR`  INT(10)        NOT NULL,
  `CRT_USR`  INT(10)        NOT NULL,
  `CRT_DATE` DATETIME       NOT NULL,
  KEY `idx_ac_code` (`AC_CODE`),
  KEY `idx_unt_no`  (`UNT_NO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='القيود المحاسبية – نظام ERP';

-- ════════════════════════════════════════════════════════════
--  ⑫ مشاهد (VIEWS) لتسريع الاستعلامات الشائعة
-- ════════════════════════════════════════════════════════════

-- ملخص التذاكر (لوحة التحكم)
CREATE OR REPLACE VIEW `v_ticket_summary` AS
SELECT
  COUNT(*)                                                AS total,
  SUM(status = 'Pending')                                AS pending,
  SUM(status = 'In Progress')                            AS in_progress,
  SUM(status = 'Resolved')                               AS resolved,
  SUM(status = 'Cancelled')                              AS cancelled,
  SUM(sla_breached = 1 AND status NOT IN ('Resolved','Cancelled')) AS sla_breaches,
  SUM(DATE(created_at) = CURDATE())                      AS today_count
FROM `tickets`;

-- آخر 10 تذاكر مع أسماء الكيانات
CREATE OR REPLACE VIEW `v_tickets_full` AS
SELECT
  t.id,
  t.ticket_number,
  t.reporter_ref,
  t.priority,
  t.status,
  t.sla_breached,
  t.created_at,
  t.updated_at,
  b.branch_name,
  r.region_name,
  d.department_name,
  ic.category_name,
  cl.client_name,
  sr.rule_name AS sla_rule_name,
  CONCAT(u.full_name) AS closed_by_name
FROM `tickets`        t
LEFT JOIN `branches`          b  ON b.id  = t.branch_id
LEFT JOIN `regions`           r  ON r.id  = t.region_id
LEFT JOIN `departments`       d  ON d.id  = t.department_id
LEFT JOIN `issue_categories`  ic ON ic.id = t.category_id
LEFT JOIN `clients`           cl ON cl.id = t.client_id
LEFT JOIN `sla_rules`         sr ON sr.id = t.sla_rule_id
LEFT JOIN `sys_users`         u  ON u.id  = t.closed_by;

-- أوامر العمل مع أسماء المستخدمين
CREATE OR REPLACE VIEW `v_work_orders_full` AS
SELECT
  wo.*,
  u1.full_name AS assigned_to_name,
  u2.full_name AS created_by_name,
  t.ticket_number
FROM `work_orders` wo
LEFT JOIN `sys_users` u1 ON u1.id = wo.assigned_to
LEFT JOIN `sys_users` u2 ON u2.id = wo.created_by
LEFT JOIN `tickets`   t  ON t.id  = wo.ticket_id;

-- إحصاءات أداء الفنيين
CREATE OR REPLACE VIEW `v_technician_stats` AS
SELECT
  u.id,
  u.full_name,
  COUNT(wo.id)                       AS total_tasks,
  SUM(wo.status = 'Completed')       AS completed,
  SUM(wo.status = 'Pending')         AS pending,
  SUM(wo.status = 'In Progress')     AS in_progress,
  ROUND(
    SUM(wo.status = 'Completed') * 100.0 / NULLIF(COUNT(wo.id), 0), 1
  )                                  AS completion_rate
FROM `sys_users` u
LEFT JOIN `work_orders` wo ON wo.assigned_to = u.id
GROUP BY u.id, u.full_name;

-- ════════════════════════════════════════════════════════════
--  ⑬ إجراءات مخزّنة (Stored Procedures) لعمليات متكررة
-- ════════════════════════════════════════════════════════════
DELIMITER $$

-- إغلاق تذكرة مع حساب وقت الحل ووضع علامة خرق SLA
CREATE PROCEDURE `sp_close_ticket`(
  IN  p_ticket_id  INT,
  IN  p_closed_by  INT,
  OUT p_success    TINYINT
)
BEGIN
  DECLARE v_created_at DATETIME;
  DECLARE v_res_hours  DECIMAL(10,2);

  SELECT created_at INTO v_created_at
  FROM tickets WHERE id = p_ticket_id;

  SET v_res_hours = TIMESTAMPDIFF(MINUTE, v_created_at, NOW()) / 60.0;

  UPDATE tickets SET
    status               = 'Resolved',
    closed_by            = p_closed_by,
    closed_at            = NOW(),
    resolution_time_hrs  = v_res_hours,
    sla_breached         = IF(sla_breach_at IS NOT NULL AND NOW() > sla_breach_at, 1, sla_breached)
  WHERE id = p_ticket_id AND status NOT IN ('Resolved','Cancelled');

  SET p_success = IF(ROW_COUNT() > 0, 1, 0);
END$$

-- حساب وتحديث خرق SLA للتذاكر المفتوحة (تُشغَّل دورياً)
CREATE PROCEDURE `sp_update_sla_breaches`()
BEGIN
  UPDATE tickets t
  JOIN  sla_rules sr ON sr.id = t.sla_rule_id
  SET   t.sla_breached = 1
  WHERE t.status NOT IN ('Resolved','Cancelled')
    AND t.sla_breached = 0
    AND t.sla_breach_at IS NOT NULL
    AND NOW() > t.sla_breach_at;
END$$

DELIMITER ;

-- ════════════════════════════════════════════════════════════
--  إعادة تفعيل التحقق من المفاتيح الخارجية
-- ════════════════════════════════════════════════════════════
SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- ════════════════════════════════════════════════════════════
--  ملاحظات المطوّر – دليل التحديث
-- ════════════════════════════════════════════════════════════
--
--  ┌─────────────────────────────────────────────────────────┐
--  │          جدول تحويل الأسماء القديمة ← الجديدة          │
--  ├──────────────────────┬──────────────────────────────────┤
--  │  الاسم القديم        │  الاسم الجديد                   │
--  ├──────────────────────┼──────────────────────────────────┤
--  │  roles               │  sys_roles                      │
--  │  users               │  sys_users                      │
--  │  system_settings     │  sys_settings                   │
--  │  system_visuals      │  sys_theme                      │
--  │  sidebar_menu        │  sys_menu                       │
--  │  permissions         │  sys_permissions                │
--  │  user_permision      │  user_roles                     │
--  │  user_page_access    │  user_menu_access               │
--  │  branch              │  branches                       │
--  │  colleges            │  regions                        │
--  │  labs                │  departments                    │
--  │  groups              │  issue_categories               │
--  │  Customers           │  clients                        │
--  │  Vendors             │  agents                         │
--  │  target_assignments  │  agent_assignments              │
--  │  requests            │  tickets                        │
--  │  tasks               │  work_orders                    │
--  │  user_branches       │  user_branch_access             │
--  │  user_group_access   │  user_category_access           │
--  │  system_logs         │  audit_logs                     │
--  │  ai_chat             │  ai_sessions                    │
--  │  chatbot_training    │  ai_training                    │
--  │  training_questions  │  ai_questions                   │
--  └──────────────────────┴──────────────────────────────────┘
--
--  لتحديث ملفات PHP بسرعة:
--  استخدم ثوابت config/tables.php بدلاً من الأسماء المباشرة
--  مثال: "SELECT * FROM " . TBL_TICKETS
--
--  أدوات الأداء المضافة:
--  ① core/Database.php  — singleton + paginate() + slow query log
--  ② core/Cache.php     — APCu/file cache بـ remember()
--  ③ config/tables.php  — ثوابت أسماء الجداول
--  ④ Views (v_*)        — مشاهد جاهزة لتجنب JOINs معقدة
--  ⑤ sp_close_ticket    — إغلاق التذاكر مع حساب SLA
--  ⑥ sp_update_sla_breaches — تحديث خرق SLA دورياً
-- ════════════════════════════════════════════════════════════
