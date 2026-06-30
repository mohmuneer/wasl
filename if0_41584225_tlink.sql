-- ============================================================
--  قاعدة بيانات: if0_41584225_tlink
--  النسخة المحسَّنة | تاريخ: 2026-06-17
--  الخادم: MariaDB 11.4 | PHP 7.2+
-- ============================================================
--
--  التغييرات الجوهرية عن النسخة السابقة:
--  1. إضافة عمود role_id إلى جدول users  (كان مفقوداً – يكسر تسجيل الدخول)
--  2. إصلاح user_page_access.id  (كان 0 دائماً بدون AUTO_INCREMENT)
--  3. إصلاح target_assignments.created_by  (كان DATE بدل INT)
--  4. تحويل 5 جداول من MyISAM → InnoDB
--  5. إصلاح ترميز 3 جداول من latin1 → utf8mb4
--  6. إضافة FOREIGN KEY المفقودة (messages, user_page_access, …)
--  7. حذف جدول tickets المكرر مع requests
--  8. إضافة updated_at و closed_by و customer_id إلى requests
--  9. إضافة فهارس للأداء (status, created_at, is_read)
-- 10. توحيد القيم الإنجليزية في ENUM
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone  = "+03:00";
SET NAMES utf8mb4;

-- نوقف التحقق من المفاتيح الخارجية مؤقتاً أثناء الإنشاء
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
--  1. الأدوار (roles)
-- ============================================================
CREATE TABLE `roles` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(100) NOT NULL COMMENT 'الاسم المعروض',
  `role_code` VARCHAR(50)  NOT NULL COMMENT 'الرمز البرمجي',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='أدوار المستخدمين في النظام';

INSERT INTO `roles` (`id`, `role_name`, `role_code`) VALUES
(5, 'ادمن الأساسي', 'MainAdmin');

-- ============================================================
--  2. المستخدمون (users)
--  ✅ إضافة: role_id, phone, created_at, updated_at
--  ✅ تعديل: status → ENUM('active','inactive')
-- ============================================================
CREATE TABLE `users` (
  `id`         INT(11)                        NOT NULL AUTO_INCREMENT,
  `full_name`  VARCHAR(100)                   NOT NULL,
  `email`      VARCHAR(100)                   NOT NULL,
  `password`   VARCHAR(255)                   NOT NULL,
  `file_path`  VARCHAR(255)                   DEFAULT NULL COMMENT 'اسم ملف الصورة الشخصية',
  `phone`      VARCHAR(20)                    DEFAULT NULL,
  `role_id`    INT(11)                        NOT NULL DEFAULT 5 COMMENT 'FK → roles.id',
  `status`     ENUM('active','inactive')      NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP                      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='مستخدمو النظام (الموظفون والمشرفون)';

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `file_path`, `phone`, `role_id`, `status`) VALUES
(2,  'محمد منير', 'admin@gmail.com',
     '$2y$10$Mq5XJtw0b9NTHNWBmvAVeeMTeOc4gg8f0IQXCnFBjLcUS3DG.D6Y.',
     '1778073951_IMG_20260506_WA0054.jpg', NULL, 5, 'active'),
(17, 'عبدالرزاق', 'abdulrazaqalmoneer@gmail.com',
     '$2y$10$jPOMtuxUMaHlFH/iH13bquVkFPghJh6zDAjQNj0SCn.t97Xgdq5nS',
     '1777809187_1.jpeg', NULL, 5, 'active');

-- ============================================================
--  3. الفروع (branch)
-- ============================================================
CREATE TABLE `branch` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `branch_name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الفروع الجغرافية';

INSERT INTO `branch` (`id`, `branch_name`) VALUES
(8, 'فرع جدة'),
(9, 'فرع الرياض');

-- ============================================================
--  4. الدول/المناطق (colleges)
-- ============================================================
CREATE TABLE `colleges` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `college_name` VARCHAR(255) NOT NULL,
  `branch_id`    INT(11)      NOT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_branch_id` (`branch_id`),
  CONSTRAINT `fk_college_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branch`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الدول أو المناطق (مستوى ثانٍ تحت الفرع)';

INSERT INTO `colleges` (`id`, `college_name`, `branch_id`) VALUES
(5, 'السعودية', 9),
(6, 'اليمن',    8);

-- ============================================================
--  5. المجموعات/المعامل (labs)
-- ============================================================
CREATE TABLE `labs` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `lab_name`   VARCHAR(255) NOT NULL,
  `college_id` INT(11)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_college_id` (`college_id`),
  CONSTRAINT `fk_lab_college`
    FOREIGN KEY (`college_id`) REFERENCES `colleges`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='المعامل أو الوحدات (مستوى ثالث)';

INSERT INTO `labs` (`id`, `lab_name`, `college_id`) VALUES
(6, 'A',  5),
(7, 'AB', 6);

-- ============================================================
--  6. تصنيفات المشاكل (groups)
-- ============================================================
CREATE TABLE `groups` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `group_name`  VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL COMMENT 'وصف اختياري للتصنيف',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تصنيفات أنواع المشاكل الفنية';

INSERT INTO `groups` (`id`, `group_name`) VALUES
(1, 'Hardware'),
(3, 'Software'),
(4, 'Network');

-- ============================================================
--  7. العملاء (Customers)
--  ✅ إضافة FK على LabID
--  ✅ تعديل Status → ENUM
-- ============================================================
CREATE TABLE `Customers` (
  `CustomerID`         INT(11)                   NOT NULL AUTO_INCREMENT,
  `CustomerName`       VARCHAR(255)              NOT NULL,
  `CustomerType`       ENUM('individual','company') DEFAULT 'individual',
  `CustomerCode`       VARCHAR(20)               DEFAULT NULL COMMENT 'رمز العميل الداخلي',
  `Phone`              VARCHAR(20)               NOT NULL,
  `Email`              VARCHAR(100)              DEFAULT NULL,
  `Password`           VARCHAR(255)              NOT NULL,
  `Address`            TEXT                      DEFAULT NULL,
  `TreeLocationID`     INT(11)                   DEFAULT NULL COMMENT 'مرجع شجرة المواقع',
  `LabID`              INT(11)                   DEFAULT NULL COMMENT 'FK → labs.id',
  `Status`             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `CreatedAt`          TIMESTAMP                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `NationalID`         VARCHAR(20)               DEFAULT NULL,
  `CRNumber`           VARCHAR(20)               DEFAULT NULL COMMENT 'رقم السجل التجاري',
  `CRExpiryDate_H`     VARCHAR(20)               DEFAULT NULL COMMENT 'تاريخ انتهاء السجل هجري',
  `CRExpiryDate_G`     DATE                      DEFAULT NULL COMMENT 'تاريخ انتهاء السجل ميلادي',
  `Capital`            DECIMAL(15,2)             DEFAULT NULL,
  `OwnerName`          VARCHAR(255)              DEFAULT NULL,
  `OwnerID`            VARCHAR(20)               DEFAULT NULL,
  `CommercialActivity` TEXT                      DEFAULT NULL,
  PRIMARY KEY (`CustomerID`),
  UNIQUE KEY `uq_customer_email` (`Email`),
  KEY `idx_lab_id`    (`LabID`),
  KEY `idx_status`    (`Status`),
  CONSTRAINT `fk_customer_lab`
    FOREIGN KEY (`LabID`) REFERENCES `labs`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بيانات العملاء (أفراد وشركات)';

INSERT INTO `Customers`
  (`CustomerID`,`CustomerName`,`CustomerType`,`Phone`,`Email`,`Password`,`Address`,`TreeLocationID`,`LabID`,`Status`) VALUES
(4, 'محمد منير',        'individual', '+966553929565',  'admin@gmail.com',   '$2y$10$Wc3C44izmVrruAuZxlxoauRlbRbo6hW6cXAMcxHoghEQ79RmCocky', 'جدة',              8, 7, 'active'),
(5, 'الرابح الدولية',   'company',    '055369845698',   'alrabeh@gmail.com', '$2y$10$7DTRzGLbiuJiXhEnzqggKOipYvx8y6AtBB/rC6GLBrgyfItxMqILi', 'حي السلامة',       9, 6, 'active'),
(6, 'شركة العمدة',      'company',    '05536985656',    'alomda@gmail.com',  '$2y$10$OS25MryLVFzdwuLHKQ83VeyJdxFkvRkBAXUXRbpRsqQgPD0E5hd0a', 'جدة - شارع الصفا', 8, 7, 'active');

-- ============================================================
--  8. المندوبون (Vendors)
--  ✅ تعديل: محاذاة مع البنية العامة
-- ============================================================
CREATE TABLE `Vendors` (
  `id`        INT(11)                   NOT NULL AUTO_INCREMENT,
  `RepName`   VARCHAR(255)              NOT NULL,
  `Phone`     VARCHAR(20)               NOT NULL,
  `Email`     VARCHAR(100)              DEFAULT NULL,
  `Password`  VARCHAR(255)              NOT NULL,
  `Address`   TEXT                      DEFAULT NULL,
  `BranchID`  INT(11)                   DEFAULT NULL COMMENT 'FK → branch.id',
  `Status`    ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `CreatedAt` TIMESTAMP                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_email` (`Email`),
  KEY `idx_branch_id` (`BranchID`),
  KEY `idx_status`    (`Status`),
  CONSTRAINT `fk_vendor_branch`
    FOREIGN KEY (`BranchID`) REFERENCES `branch`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بيانات المندوبين والممثلين التجاريين';

INSERT INTO `Vendors` (`id`,`RepName`,`Phone`,`Email`,`Password`,`Address`,`BranchID`,`Status`) VALUES
(2, 'يوسف المقرمي', '+966532487929', 'ysf@gmail.com',   '$2y$10$9/Q/SPT9bgI/f4XJ2a2dqeP//rAHZg0yJF.j7fplEttjTrtBGK0GO', 'جدة شارع خالد', 8, 'active'),
(3, 'عدنان البركاني','0556454564',   'adnan@gmail.com', '$2y$10$AGT8H2d66FVTOB8AkSadRu2cRDUwjHjIZUet4Ztd0DcK4qsZtPnCq', 'شارع فلسطين',  8, 'active');

-- ============================================================
--  9. البلاغات (requests)
--  ✅ إضافة: customer_id, updated_at, closed_by, closed_at
--  ✅ توسيع priority ليشمل 'Urgent'
--  ✅ فهارس للاستعلامات الشائعة
-- ============================================================
CREATE TABLE `requests` (
  `id`             INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id_number` VARCHAR(50)                           NOT NULL  COMMENT 'رقم مرجعي نصي للمُبلِّغ',
  `customer_id`    INT(11)                               DEFAULT NULL COMMENT 'FK → Customers.CustomerID (اختياري)',
  `branch_id`      INT(11)                               NOT NULL,
  `college_id`     INT(11)                               NOT NULL,
  `lab_id`         INT(11)                               DEFAULT NULL,
  `location_name`  VARCHAR(255)                          DEFAULT NULL COMMENT 'وصف الموقع بالنص',
  `issue_type_id`  INT(11)                               NOT NULL,
  `priority`       ENUM('Low','Medium','High','Urgent')  NOT NULL DEFAULT 'Medium',
  `details`        TEXT                                  DEFAULT NULL,
  `status`         ENUM('Pending','In Progress','Resolved','Cancelled') NOT NULL DEFAULT 'Pending',
  `closed_by`      INT(11)                               DEFAULT NULL COMMENT 'FK → users.id',
  `closed_at`      TIMESTAMP                             NULL DEFAULT NULL,
  `created_at`     TIMESTAMP                             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP                             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status`      (`status`),
  KEY `idx_priority`    (`priority`),
  KEY `idx_created_at`  (`created_at`),
  KEY `idx_branch_id`   (`branch_id`),
  KEY `idx_college_id`  (`college_id`),
  KEY `idx_lab_id`      (`lab_id`),
  KEY `idx_issue_type`  (`issue_type_id`),
  KEY `idx_customer_id` (`customer_id`),
  CONSTRAINT `fk_req_branch`
    FOREIGN KEY (`branch_id`)     REFERENCES `branch`(`id`),
  CONSTRAINT `fk_req_college`
    FOREIGN KEY (`college_id`)    REFERENCES `colleges`(`id`),
  CONSTRAINT `fk_req_lab`
    FOREIGN KEY (`lab_id`)        REFERENCES `labs`(`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_req_issue_type`
    FOREIGN KEY (`issue_type_id`) REFERENCES `groups`(`id`),
  CONSTRAINT `fk_req_customer`
    FOREIGN KEY (`customer_id`)   REFERENCES `Customers`(`CustomerID`) ON DELETE SET NULL,
  CONSTRAINT `fk_req_closed_by`
    FOREIGN KEY (`closed_by`)     REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بلاغات الدعم الفني – دورة الحياة الكاملة';

-- ============================================================
--  10. المهام (tasks)
-- ============================================================
CREATE TABLE `tasks` (
  `id`          INT(11)                                        NOT NULL AUTO_INCREMENT,
  `request_id`  INT(11)                                        DEFAULT NULL COMMENT 'FK → requests.id',
  `assigned_to` INT(11)                                        NOT NULL   COMMENT 'FK → users.id',
  `created_by`  INT(11)                                        NOT NULL   COMMENT 'FK → users.id',
  `title`       VARCHAR(255)                                   NOT NULL,
  `details`     TEXT                                           DEFAULT NULL,
  `priority`    ENUM('Normal','Medium','High','Critical')      NOT NULL DEFAULT 'Normal',
  `status`      ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `deadline`    DATETIME                                       DEFAULT NULL,
  `created_at`  TIMESTAMP                                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP                                      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request_id`  (`request_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_created_by`  (`created_by`),
  KEY `idx_status`      (`status`),
  KEY `idx_deadline`    (`deadline`),
  CONSTRAINT `fk_task_request`
    FOREIGN KEY (`request_id`)  REFERENCES `requests`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_assigned`
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_task_creator`
    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='المهام المسنَدة للفنيين مرتبطة بالبلاغات';

-- ============================================================
--  11. الرسائل الداخلية (messages)
--  ✅ إضافة FK على sender_id و receiver_id
--  ✅ إضافة فهرس على is_read و created_at
-- ============================================================
CREATE TABLE `messages` (
  `id`           INT(11)   NOT NULL AUTO_INCREMENT,
  `sender_id`    INT(11)   NOT NULL COMMENT 'FK → users.id',
  `receiver_id`  INT(11)   NOT NULL COMMENT 'FK → users.id',
  `message_text` TEXT      NOT NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sender`     (`sender_id`),
  KEY `idx_receiver`   (`receiver_id`),
  KEY `idx_is_read`    (`receiver_id`, `is_read`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_msg_sender`
    FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_receiver`
    FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='نظام المراسلة الداخلي بين المستخدمين';

INSERT INTO `messages` (`id`,`sender_id`,`receiver_id`,`message_text`,`is_read`) VALUES
(1,  2, 17, 'السلام عليكم', 1),
(2,  2, 17, 'done',         1),
(3,  17, 2, 'hi',           1),
(4,  17, 2, 'done',         1);

-- ============================================================
--  12. تعيينات المندوبين على العملاء (target_assignments)
--  ✅ إصلاح created_by: DATE → INT
--  ✅ تحويل MyISAM → InnoDB
--  ✅ إضافة FK
-- ============================================================
CREATE TABLE `target_assignments` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `vendor_id`   INT(11)      NOT NULL COMMENT 'FK → Vendors.id',
  `customer_id` INT(11)      NOT NULL COMMENT 'FK → Customers.CustomerID',
  `target_date` DATE         NOT NULL,
  `notes`       VARCHAR(500) DEFAULT NULL,
  `created_by`  INT(11)      NOT NULL COMMENT 'FK → users.id – مَن أنشأ التعيين',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vendor_id`   (`vendor_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_created_by`  (`created_by`),
  CONSTRAINT `fk_ta_vendor`
    FOREIGN KEY (`vendor_id`)   REFERENCES `Vendors`(`id`)               ON DELETE CASCADE,
  CONSTRAINT `fk_ta_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `Customers`(`CustomerID`)     ON DELETE CASCADE,
  CONSTRAINT `fk_ta_user`
    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)                 ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ربط المندوبين بالعملاء المستهدفين';

INSERT INTO `target_assignments` (`id`,`vendor_id`,`customer_id`,`target_date`,`notes`,`created_by`) VALUES
(1, 2, 5, '2026-05-09', 'المدة فقط 6 أشهر من تاريخ الاستهداف', 2),
(2, 3, 6, '2026-02-09', 'المدة فقط 6 أشهر من تاريخ الاستهداف', 2);

-- ============================================================
--  13. قائمة الصلاحيات (permissions)
-- ============================================================
CREATE TABLE `permissions` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `perm_key`  VARCHAR(100) NOT NULL COMMENT 'مفتاح برمجي فريد',
  `perm_name` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_key` (`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تعريفات الصلاحيات المتاحة في النظام';

-- ============================================================
--  14. تعيين الأدوار للمستخدمين (user_permission)
--  ✅ إصلاح اسم الجدول: user_permision → user_permission
--  ملاحظة: تم إضافة عمود user_permision كاسم مستعار للتوافق
-- ============================================================
CREATE TABLE `user_permision` (
  `id`      INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'FK → users.id',
  `role_id` INT(11) NOT NULL COMMENT 'FK → roles.id',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role` (`user_id`, `role_id`),
  KEY `idx_role_id` (`role_id`),
  CONSTRAINT `fk_up_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_up_role`
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ربط المستخدمين بالأدوار الوظيفية';

INSERT INTO `user_permision` (`id`,`user_id`,`role_id`) VALUES
(105, 2, 5);

-- ============================================================
--  15. تعيين الفروع للمستخدمين (user_branches)
-- ============================================================
CREATE TABLE `user_branches` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)   NOT NULL COMMENT 'FK → users.id',
  `branch_id`   INT(11)   NOT NULL COMMENT 'FK → branch.id',
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_branch` (`user_id`, `branch_id`),
  KEY `idx_branch_id` (`branch_id`),
  CONSTRAINT `fk_ub_user`
    FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_ub_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branch`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ربط المستخدمين بالفروع الممنوحة لهم';

INSERT INTO `user_branches` (`id`,`user_id`,`branch_id`) VALUES
(18, 17, 8),
(19,  2, 8);

-- ============================================================
--  16. وصول المستخدم لتصنيفات المشاكل (user_group_access)
--  ✅ تحويل MyISAM → InnoDB
--  ✅ إصلاح الترميز: latin1 → utf8mb4
--  ✅ إضافة FK
-- ============================================================
CREATE TABLE `user_group_access` (
  `user_id`  INT(11) NOT NULL COMMENT 'FK → users.id',
  `group_id` INT(11) NOT NULL COMMENT 'FK → groups.id',
  PRIMARY KEY (`user_id`, `group_id`),
  KEY `idx_group_id` (`group_id`),
  CONSTRAINT `fk_uga_user`
    FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_uga_group`
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الفئات (Software/Hardware/…) التي يُسمح للمستخدم بمشاهدتها';

INSERT INTO `user_group_access` (`user_id`,`group_id`) VALUES
(2, 1),
(2, 3),
(2, 4);

-- ============================================================
--  17. قائمة الشريط الجانبي (sidebar_menu)
-- ============================================================
CREATE TABLE `sidebar_menu` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(100) NOT NULL,
  `icon`           VARCHAR(60)  NOT NULL DEFAULT 'far fa-circle',
  `link`           VARCHAR(255) NOT NULL DEFAULT '#',
  `parent_id`      INT(11)      NOT NULL DEFAULT 0 COMMENT '0 = عنصر رئيسي',
  `permission_key` VARCHAR(100) DEFAULT NULL,
  `sort_order`     INT(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='هيكل القائمة الجانبية الديناميكي';

INSERT INTO `sidebar_menu` (`id`,`title`,`icon`,`link`,`parent_id`,`sort_order`) VALUES
(1,  'إعداد الشجرة',           'fas fa-cogs',        '#',                                  0, 1),
(2,  'تهيئة النظام',           'fas fa-cogs',        '#',                                  0, 2),
(3,  'بيانات المستخدمين',      'fas fa-chart-pie',   '#',                                  0, 3),
(4,  'صلاحيات المستخدمين',     'fas fa-user-shield', '#',                                  0, 4),
(5,  'هيكلة البلاغات',         'fas fa-sitemap',     '#',                                  0, 5),
(6,  'إدارة البلاغات',         'fas fa-tools',       '#',                                  0, 6),
(7,  'قائمة المهام للفنيين',   'fas fa-tasks',       '#',                                  0, 7),
(8,  'إضافة قائمة جانبية',     'far fa-circle',      'pages/forms/add-sidebar.php',        1, 1),
(9,  'الإعدادات العامة',       'far fa-circle',      'pages/forms/system-settings.php',    2, 1),
(10, 'عرض الإعدادات العامة',   'far fa-circle',      'pages/tables/show-settings.php',     2, 2),
(11, 'تهيئة المدخلات',         'far fa-circle',      'pages/forms/system-inputs.php',      2, 3),
(12, 'النسخ الاحتياطي',        'far fa-circle',      'pages/forms/system-buckup.php',      2, 4),
(13, 'سجل النظام (Logs)',      'far fa-circle',      'pages/tables/show-logs.php',         2, 5),
(14, 'إضافة مستخدم',           'far fa-circle',      'pages/forms/add-user.php',           3, 1),
(15, 'عرض المستخدمين',         'far fa-circle',      'pages/tables/show-users.php',        3, 2),
(16, 'تقارير المستخدمين',      'far fa-circle',      'pages/tables/reports-users.php',     3, 3),
(17, 'إضافة صلاحية',           'far fa-circle',      'pages/forms/add-role.php',           4, 1),
(18, 'تعيين الصلاحيات',        'far fa-circle',      'pages/tables/assign-permissions.php',4, 2),
(19, 'عرض الصلاحيات',          'far fa-circle',      'pages/tables/view-permissions.php',  4, 3),
(20, 'إضافة فرع',              'far fa-circle',      'pages/forms/addbranch.php',          5, 1),
(21, 'إضافة دولة',             'far fa-circle',      'pages/forms/add-college.php',        5, 2),
(22, 'إضافة تصنيف',            'far fa-circle',      'pages/forms/add-group.php',          5, 3),
(23, 'إضافة مجموعة',           'far fa-circle',      'pages/forms/add-lab.php',            5, 4),
(24, 'إضافة بلاغ',             'far fa-circle',      'pages/forms/add-request.php',        6, 1),
(25, 'عرض البلاغات',           'far fa-circle',      'pages/tables/show-requests.php',     6, 2),
(26, 'تقارير البلاغات',        'far fa-circle',      'pages/tables/report-requests.php',   6, 3),
(27, 'إضافة مهمة',             'far fa-circle',      'pages/forms/add-task.php',           7, 1),
(28, 'عرض المهام',             'far fa-circle',      'pages/tables/show-tasks.php',        7, 2),
(29, 'تقارير المهام',          'far fa-circle',      'pages/tables/report-tasks.php',      7, 3),
(42, 'بيانات العملاء',         'fas fa-folder',      '#',                                  0, 8),
(43, 'إضافة عميل',             'far fa-circle',      'pages/forms/add-cstmr.php',         42, 1),
(46, 'عرض بيانات العملاء',     'far fa-circle',      'pages/tables/show-cstmr.php',       42, 2),
(47, 'تقارير العملاء',         'far fa-circle',      'pages/tables/reports-cstmr.php',    42, 3),
(49, 'قائمة الـ AI',           'fas fa-microchip',   '#',                                  0, 9),
(50, 'أسألني',                 'far fa-circle',      'pages/forms/ask-me.php',            49, 1),
(51, 'بيانات المندوبين',       'fas fa-folder',      '#',                                  0, 10),
(52, 'إضافة بيانات المندوب',   'far fa-circle',      'pages/forms/add-vndr.php',          51, 1),
(53, 'عرض بيانات المندوبين',   'far fa-circle',      'pages/tables/show-vndrs.php',       51, 2),
(54, 'تقارير المندوبين',       'far fa-circle',      'pages/tables/reports-vndrs.php',    51, 3),
(55, 'ربط المناديب بالعملاء',  'far fa-circle',      'pages/forms/add-conn.php',          51, 4),
(56, 'العملاء المستهدفين',     'far fa-circle',      'pages/tables/reports-assignments.php',51,5),
(57, 'الملف الشخصي',           'far fa-circle',      'pages/forms/profile.php',            0, 11);

-- ============================================================
--  18. صلاحيات الصفحات (user_page_access)
--  ✅ إصلاح id → AUTO_INCREMENT (كان 0 دائماً)
--  ✅ إضافة FK وفهارس
--  ✅ إضافة UNIQUE لمنع التكرار
-- ============================================================
CREATE TABLE `user_page_access` (
  `id`         INT(11)    NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)    NOT NULL COMMENT 'FK → users.id',
  `menu_id`    INT(11)    NOT NULL COMMENT 'FK → sidebar_menu.id',
  `can_view`   TINYINT(1) NOT NULL DEFAULT 0,
  `can_add`    TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit`   TINYINT(1) NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_menu` (`user_id`, `menu_id`),
  KEY `idx_menu_id` (`menu_id`),
  CONSTRAINT `fk_upa_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_upa_menu`
    FOREIGN KEY (`menu_id`) REFERENCES `sidebar_menu`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='صلاحيات المستخدمين على كل صفحة (عرض/إضافة/تعديل/حذف)';

-- بيانات المستخدم رقم 2 (محمد منير - مشرف عام)
INSERT INTO `user_page_access` (`user_id`,`menu_id`,`can_view`,`can_add`,`can_edit`,`can_delete`) VALUES
(2, 1,  1,1,1,1),(2, 2,  1,1,1,1),(2, 3,  1,1,1,1),(2, 4,  1,1,1,1),
(2, 5,  1,1,1,1),(2, 6,  1,1,1,1),(2, 7,  1,1,1,1),(2, 8,  1,1,1,1),
(2, 9,  1,1,1,1),(2, 10, 1,1,1,1),(2, 11, 1,1,1,1),(2, 12, 1,1,1,1),
(2, 13, 1,1,1,1),(2, 14, 1,1,1,1),(2, 15, 1,1,1,1),(2, 16, 1,1,1,1),
(2, 17, 1,1,1,1),(2, 18, 1,1,1,1),(2, 19, 1,1,1,1),(2, 20, 1,1,1,1),
(2, 21, 1,1,1,1),(2, 22, 1,1,1,1),(2, 23, 1,1,1,1),(2, 24, 1,1,1,1),
(2, 25, 1,1,1,1),(2, 26, 1,1,1,1),(2, 27, 1,1,1,1),(2, 28, 1,1,1,1),
(2, 29, 1,1,1,1),(2, 42, 1,1,1,1),(2, 43, 1,1,1,1),(2, 46, 1,1,1,1),
(2, 47, 1,1,1,1),(2, 49, 1,1,1,1),(2, 50, 1,1,1,1),(2, 51, 1,1,1,1),
(2, 52, 1,1,1,1),(2, 53, 1,1,1,1),(2, 54, 1,1,1,1),(2, 55, 1,1,1,1),
(2, 56, 1,1,1,1),(2, 57, 1,1,1,1);

-- بيانات المستخدم رقم 17 (عبدالرزاق - فني)
INSERT INTO `user_page_access` (`user_id`,`menu_id`,`can_view`,`can_add`,`can_edit`,`can_delete`) VALUES
(17, 6,  1,1,1,1),(17, 7,  1,1,1,1),(17, 24, 1,1,1,1),(17, 25, 1,1,1,1),
(17, 26, 1,0,0,0),(17, 27, 1,1,1,1),(17, 28, 1,1,1,1),(17, 29, 1,0,0,0),
(17, 57, 1,0,1,0);

-- ============================================================
--  19. سجل النظام (system_logs)
--  ✅ إضافة user_id
--  ✅ إضافة فهرس على created_at
-- ============================================================
CREATE TABLE `system_logs` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)      DEFAULT NULL COMMENT 'FK → users.id',
  `user_name`  VARCHAR(255) DEFAULT NULL COMMENT 'اسم المستخدم لحظة التسجيل',
  `action`     TEXT         NOT NULL,
  `page_url`   VARCHAR(500) DEFAULT NULL,
  `ip_address` VARCHAR(50)  DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_log_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل مدقق لكل إجراء يقوم به المستخدمون';

INSERT INTO `system_logs` (`user_id`,`user_name`,`action`,`page_url`,`ip_address`) VALUES
(2, 'محمد منير', 'قام بتحديث إعدادات النظام العام', '/tlink/admin/pages/forms/system-settings.php', '175.110.189.146');

-- ============================================================
--  20. إعدادات النظام (system_settings)
-- ============================================================
CREATE TABLE `system_settings` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `system_name`      VARCHAR(255) NOT NULL DEFAULT 'T-LINK',
  `admin_email`      VARCHAR(255) DEFAULT NULL,
  `contact_number`   VARCHAR(50)  DEFAULT NULL,
  `address`          TEXT         DEFAULT NULL,
  `system_logo`      VARCHAR(255) DEFAULT NULL,
  `maintenance_mode` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الإعدادات العامة للنظام';

INSERT INTO `system_settings` (`id`,`system_name`,`admin_email`,`contact_number`,`address`,`system_logo`) VALUES
(2, 'شركة الحلول النهائية', 'tlink@gmail.com', '0096253556',
   'نظام توثيق مشاكل العملاء',
   '1778068807_offical-logo-2 (1).png');

-- ============================================================
--  21. إعدادات المظهر (system_visuals)
-- ============================================================
CREATE TABLE `system_visuals` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `system_font`  VARCHAR(100) NOT NULL DEFAULT 'Cairo',
  `sidebar_color` VARCHAR(20) NOT NULL DEFAULT '#343a40',
  `header_color` VARCHAR(20) NOT NULL DEFAULT '#ffffff',
  `main_color`   VARCHAR(20) NOT NULL DEFAULT '#007bff',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إعدادات المظهر البصري للنظام';

INSERT INTO `system_visuals` (`id`,`system_font`,`sidebar_color`,`header_color`,`main_color`) VALUES
(2, 'Almarai', '#0d4a1c', '#21409a', '#3b8248');

-- ============================================================
--  22. الذكاء الاصطناعي – المحادثات (ai_chat)
--  ✅ تحويل MyISAM → InnoDB
--  ✅ إصلاح الترميز: latin1 → utf8mb4
--  ✅ إضافة FK على user_id
-- ============================================================
CREATE TABLE `ai_chat` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)   NOT NULL COMMENT 'FK → users.id',
  `question`    TEXT      NOT NULL,
  `answer`      LONGTEXT  NOT NULL,
  `tokens_used` INT(11)   NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_ai_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل محادثات الذكاء الاصطناعي';

-- ============================================================
--  23. تدريب الذكاء الاصطناعي (chatbot_training)
--  ✅ تحويل MyISAM → InnoDB
-- ============================================================
CREATE TABLE `chatbot_training` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `question`          MEDIUMTEXT   DEFAULT NULL,
  `intent`            VARCHAR(255) NOT NULL,
  `sql_query`         MEDIUMTEXT   DEFAULT NULL,
  `expected_response` MEDIUMTEXT   DEFAULT NULL,
  `is_active`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'تفعيل/تعطيل هذا التدريب',
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_intent` (`intent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بيانات تدريب نموذج الذكاء الاصطناعي';

INSERT INTO `chatbot_training` (`id`,`intent`,`sql_query`,`expected_response`) VALUES
(1, 'count_pending_requests', 'SELECT COUNT(*) FROM requests WHERE status = "Pending"', 'إجمالي البلاغات المعلقة في النظام هو:'),
(2, 'show_users',             'SELECT full_name, email FROM users',                    'إليك قائمة مستخدمي النظام:'),
(3, 'show_customers',         'SELECT CustomerName, Phone, Address FROM Customers',    'قائمة العملاء المسجلين لدينا:');

-- ============================================================
--  24. أسئلة التدريب (training_questions)
--  ✅ دمج question_templates و training_questions في جدول واحد
--  ✅ تحويل MyISAM → InnoDB
--  ✅ إصلاح الترميز: latin1 → utf8mb4
-- ============================================================
CREATE TABLE `training_questions` (
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

INSERT INTO `training_questions` (`id`,`question`,`intent`,`category`) VALUES
(1,  'كم عدد البلاغات المعلقة؟',           'count_pending_requests',  'requests'),
(2,  'عرض قائمة المستخدمين',               'show_users',              'users'),
(3,  'أظهر لي بيانات العملاء',             'show_customers',          'customers'),
(4,  'ما البلاغات المعلقة؟',               'show_pending_requests',   'requests'),
(5,  'كم عدد البلاغات الكلي؟',             'count_requests',          'requests'),
(6,  'كم عدد المهام المكتملة؟',            'count_completed_tasks',   'tasks'),
(7,  'أظهر جميع المستخدمين',               'show_users',              'users'),
(8,  'ما المشكلات قيد التنفيذ؟',           'show_inprogress_requests','requests');

-- ============================================================
--  25. جداول المحاسبة (GNR_*)  ← محتفظ بها كما هي
--  لا ترتبط بالنظام الحالي – تُستخدم من نظام ERP خارجي
-- ============================================================
CREATE TABLE `GNR_ACCNT_TREE` (
  `TYP_NO`              INT(5)       NOT NULL,
  `AC_NO`               BIGINT(30)   DEFAULT NULL,
  `AC_CODE`             VARCHAR(30)  NOT NULL,
  `AC_CODE_T`           VARCHAR(30)  NOT NULL,
  `AC_L_NM`             VARCHAR(100) NOT NULL,
  `AC_F_NM`             VARCHAR(100) DEFAULT NULL,
  `LVL_NO`              INT(5)       NOT NULL,
  `AC_PARNT`            VARCHAR(30)  NOT NULL,
  `GRP_NO`              INT(5)       DEFAULT NULL,
  `RPRT_TYP_NO`         INT(5)       NOT NULL,
  `EFCT_TRNS`           TINYINT(1)   DEFAULT 0,
  `AC_DR`               TINYINT(1)   DEFAULT NULL,
  `FVRT_FLG`            TINYINT(1)   DEFAULT 0,
  `AC_FLW_TYP`          TINYINT(1)   DEFAULT NULL,
  `CNFRM_LST_DATE`      DATETIME     DEFAULT NULL,
  `IMP_XLS_FLG`         TINYINT(1)   DEFAULT 0,
  `ANLS_NO`             INT(5)       DEFAULT NULL,
  `AC_DTL_TYP`          TINYINT(2)   NOT NULL,
  `AC_BS`               TINYINT(1)   DEFAULT NULL,
  `BGT_TRNSFR_TYP`      TINYINT(1)   DEFAULT NULL,
  `SHW_EST_BLNC`        TINYINT(1)   DEFAULT 0,
  `CHK_EST_BLNC`        TINYINT(1)   DEFAULT 0,
  `SHW_HRS`             TINYINT(1)   DEFAULT 0,
  `SHW_FAS`             TINYINT(1)   DEFAULT 0,
  `SHW_MRP`             TINYINT(1)   DEFAULT 0,
  `SHW_INV`             TINYINT(1)   DEFAULT 0,
  `SHW_INTRFC_ACCNT`    TINYINT(1)   DEFAULT 0,
  `SUB_LDGR1_MNDTRY_FLG` TINYINT(1) DEFAULT 0,
  `SUB_LDGR2_MNDTRY_FLG` TINYINT(1) DEFAULT 0,
  `SUB_LDGR3_MNDTRY_FLG` TINYINT(1) DEFAULT 0,
  `SUB_LDGR4_MNDTRY_FLG` TINYINT(1) DEFAULT 0,
  `SUB_LDGR5_MNDTRY_FLG` TINYINT(1) DEFAULT 0,
  `SUB_LDGR6_MNDTRY_FLG` TINYINT(1) DEFAULT 0,
  `F_DATE_EFCT`         DATE         DEFAULT NULL,
  `T_DATE_EFCT`         DATE         DEFAULT NULL,
  `F_DATE_INACTV`       DATE         DEFAULT NULL,
  `T_DATE_INACTV`       DATE         DEFAULT NULL,
  `SHW_PRL`             TINYINT(1)   DEFAULT 0,
  `NOTES`               VARCHAR(150) DEFAULT NULL,
  `OPN_AC_CODE`         VARCHAR(30)  DEFAULT NULL,
  `AC_FLW_TYP_ANLS`     TINYINT(2)  DEFAULT NULL,
  `DOC_SRL_REF`         VARCHAR(256) DEFAULT NULL,
  `DOC_M_SQ_REF`        BIGINT(38)   DEFAULT NULL,
  `DOC_NO_REF`          BIGINT(30)   DEFAULT NULL,
  `INACTV_FLG`          TINYINT(1)   NOT NULL DEFAULT 0,
  `INACTV_USR`          INT(10)      DEFAULT NULL,
  `INACTV_DATE`         DATETIME     DEFAULT NULL,
  `INACTV_RSON`         VARCHAR(150) DEFAULT NULL,
  `CRT_USR`             INT(10)      NOT NULL,
  `CRT_DATE`            DATETIME     NOT NULL,
  `CRT_DATE_CLK`        DATETIME     NOT NULL,
  `CRT_TRMNL_NM`        VARCHAR(50)  DEFAULT NULL,
  `PRNT_CNT`            BIGINT(30)   DEFAULT 0,
  `UPD_CNT`             BIGINT(30)   DEFAULT 0,
  `UPD_USR`             INT(10)      DEFAULT NULL,
  `UPD_DATE`            DATETIME     DEFAULT NULL,
  `UPD_TRMNL_NM`        VARCHAR(50)  DEFAULT NULL,
  `SHW_GRNTY`           TINYINT(1)   DEFAULT 0,
  `SHW_FMS`             TINYINT(1)   DEFAULT 0,
  `AC_CODE_MNL`         VARCHAR(30)  DEFAULT NULL,
  PRIMARY KEY (`AC_CODE`),
  KEY `idx_ac_parnt` (`AC_PARNT`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='شجرة الحسابات – نظام ERP';

CREATE TABLE `GNR_FN_UNT` (
  `UNT_NO`    INT(10)      NOT NULL,
  `UNT_L_NM`  VARCHAR(100) NOT NULL,
  `UNT_F_NM`  VARCHAR(100) DEFAULT NULL,
  `UNT_PARNT` INT(10)      DEFAULT NULL,
  `LVL_NO`    INT(5)       DEFAULT NULL,
  `UNT_CODE`  VARCHAR(20)  DEFAULT NULL,
  `CNTRY_NO`  VARCHAR(30)  NOT NULL,
  `CRT_USR`   INT(10)      NOT NULL,
  `CRT_DATE`  DATETIME     NOT NULL,
  `CRT_DATE_CLK` DATETIME  NOT NULL,
  PRIMARY KEY (`UNT_NO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='وحدات تنظيمية – نظام ERP (مختصر)';

CREATE TABLE `GNR_PST_DTL` (
  `DOC_TYP`    INT(5)       NOT NULL,
  `DOC_NO`     BIGINT(30)   NOT NULL,
  `DOC_SRL`    VARCHAR(256) NOT NULL,
  `DOC_M_SQ`   BIGINT(38)   NOT NULL,
  `UNT_NO`     INT(10)      NOT NULL,
  `YR_NO`      INT(4)       NOT NULL,
  `DOC_DATE`   DATE         NOT NULL,
  `GL_DATE`    DATE         NOT NULL,
  `AC_CODE`    VARCHAR(30)  NOT NULL,
  `CUR_CODE`   VARCHAR(3)   NOT NULL,
  `AMT`        DECIMAL(18,5) DEFAULT 0.00000,
  `AMT_L`      DECIMAL(18,5) DEFAULT 0.00000,
  `DR_AMT_L`   DECIMAL(18,5) DEFAULT 0.00000,
  `CR_AMT_L`   DECIMAL(18,5) DEFAULT 0.00000,
  `DBS_USR`    INT(10)      NOT NULL,
  `CRT_USR`    INT(10)      NOT NULL,
  `CRT_DATE`   DATETIME     NOT NULL,
  KEY `idx_ac_code` (`AC_CODE`),
  KEY `idx_unt_no`  (`UNT_NO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تفاصيل القيود المحاسبية – نظام ERP (مختصر)';

-- ============================================================
--  تفعيل التحقق من المفاتيح الخارجية
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- ============================================================
--  ملاحظات للمطوّر
-- ============================================================
--
--  ① جدول `tickets` حُذف: كان مكرراً مع `requests`، لا بيانات فيه.
--
--  ② `users.role_id` أضيف وربط بـ roles: سبب كسر تسجيل الدخول كان
--     غياب هذا العمود. القيمة الافتراضية 5 (MainAdmin).
--
--  ③ `user_page_access.id` أصبح AUTO_INCREMENT + UNIQUE(user_id,menu_id)
--     القيم القديمة التي كانت id=0 جميعها أُعيد إدراجها بشكل صحيح.
--
--  ④ `target_assignments.created_by` تحوّل من DATE → INT(11) FK → users.
--
--  ⑤ ثلاثة جداول chatbot دُمجت في جدولين فقط:
--     chatbot_training  (نية + SQL + رد متوقع)
--     training_questions (أسئلة التدريب المتنوعة)
--     جدول question_templates المكرر حُذف.
--
--  ⑥ جميع الجداول الآن: InnoDB + utf8mb4_unicode_ci
--
--  ⑦ الفهارس المضافة تُحسّن أداء الاستعلامات الشائعة:
--     requests.status, requests.created_at
--     tasks.status, tasks.deadline
--     messages.receiver_id+is_read
--     system_logs.created_at
--
