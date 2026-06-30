-- ================================================================
-- WASL CRM — نظام تتبع الأصول وجدولة الصيانة الدورية
-- الإصدار: 1.1  |  متوافق مع MySQL 5.7+ وMariaDB 10.1+
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─── 1. تصنيفات الأصول ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `asset_categories` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL COMMENT 'اسم التصنيف',
  `icon`        VARCHAR(50)  NOT NULL DEFAULT 'fas fa-cube',
  `color`       VARCHAR(20)  NOT NULL DEFAULT '#1a5276',
  `description` TEXT         DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بيانات افتراضية
INSERT IGNORE INTO `asset_categories` (`id`,`name`,`icon`,`color`) VALUES
(1,'أجهزة حاسوب','fas fa-desktop','#1d4ed8'),
(2,'طابعات وماسحات','fas fa-print','#7c3aed'),
(3,'شبكات واتصالات','fas fa-network-wired','#0369a1'),
(4,'أجهزة عرض','fas fa-tv','#065f46'),
(5,'سيارات ومركبات','fas fa-car','#b45309'),
(6,'معدات مكتبية','fas fa-chair','#6b7280'),
(7,'أنظمة أمن ومراقبة','fas fa-video','#dc2626'),
(8,'معدات كهربائية','fas fa-bolt','#d97706'),
(9,'أجهزة تكييف','fas fa-snowflake','#0891b2'),
(10,'أخرى','fas fa-box','#94a3b8');

-- ─── 2. الأصول والأجهزة ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `assets` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `asset_code`       VARCHAR(30)   NOT NULL,
  `name`             VARCHAR(200)  NOT NULL,
  `category_id`      INT(11)       NOT NULL DEFAULT 1,
  `serial_number`    VARCHAR(100)  DEFAULT NULL,
  `model`            VARCHAR(100)  DEFAULT NULL,
  `manufacturer`     VARCHAR(100)  DEFAULT NULL,
  `branch_id`        INT(11)       DEFAULT NULL,
  `region_id`        INT(11)       DEFAULT NULL,
  `department_id`    INT(11)       DEFAULT NULL,
  `room_number`      VARCHAR(50)   DEFAULT NULL,
  `status`           ENUM('active','under_maintenance','retired','lost') NOT NULL DEFAULT 'active',
  `purchase_date`    DATE          DEFAULT NULL,
  `purchase_price`   DECIMAL(12,2) DEFAULT NULL,
  `warranty_expiry`  DATE          DEFAULT NULL,
  `assigned_to`      INT(11)       DEFAULT NULL,
  `photo_path`       VARCHAR(255)  DEFAULT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `created_by`       INT(11)       NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_code` (`asset_code`),
  KEY `idx_ast_category`  (`category_id`),
  KEY `idx_ast_status`    (`status`),
  KEY `idx_ast_branch`    (`branch_id`),
  KEY `idx_ast_dept`      (`department_id`),
  KEY `idx_ast_assigned`  (`assigned_to`),
  KEY `idx_ast_serial`    (`serial_number`),
  KEY `idx_ast_warranty`  (`warranty_expiry`),
  FULLTEXT KEY `ft_ast_search` (`name`,`serial_number`,`model`),
  CONSTRAINT `fk_ast_category` FOREIGN KEY (`category_id`)   REFERENCES `asset_categories`(`id`),
  CONSTRAINT `fk_ast_branch`   FOREIGN KEY (`branch_id`)     REFERENCES `branches`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ast_dept`     FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ast_assigned` FOREIGN KEY (`assigned_to`)   REFERENCES `sys_users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ast_creator`  FOREIGN KEY (`created_by`)    REFERENCES `sys_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3. جداول الصيانة الدورية ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `maintenance_schedules` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `asset_id`            INT(11)     NOT NULL,
  `title`               VARCHAR(200) NOT NULL,
  `description`         TEXT        DEFAULT NULL,
  `frequency_type`      ENUM('once','daily','weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  `frequency_value`     INT(11)     NOT NULL DEFAULT 1,
  `next_due_date`       DATE        NOT NULL,
  `last_done_date`      DATE        DEFAULT NULL,
  `assigned_to`         INT(11)     DEFAULT NULL,
  `notify_days_before`  INT(11)     NOT NULL DEFAULT 3,
  `status`              ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
  `created_by`          INT(11)     NOT NULL,
  `created_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ms_asset`    (`asset_id`),
  KEY `idx_ms_next_due` (`next_due_date`,`status`),
  KEY `idx_ms_assigned` (`assigned_to`),
  CONSTRAINT `fk_ms_asset`    FOREIGN KEY (`asset_id`)    REFERENCES `assets`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ms_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `sys_users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_creator`  FOREIGN KEY (`created_by`)  REFERENCES `sys_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4. سجل الصيانة المنفَّذة ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `maintenance_logs` (
  `id`               INT(11)       NOT NULL AUTO_INCREMENT,
  `asset_id`         INT(11)       NOT NULL,
  `schedule_id`      INT(11)       DEFAULT NULL,
  `ticket_id`        INT(11)       DEFAULT NULL,
  `maintenance_date` DATE          NOT NULL,
  `performed_by`     INT(11)       NOT NULL,
  `work_done`        TEXT          NOT NULL,
  `parts_replaced`   TEXT          DEFAULT NULL,
  `cost`             DECIMAL(10,2) DEFAULT NULL,
  `duration_hours`   DECIMAL(5,2)  DEFAULT NULL,
  `status`           ENUM('completed','partial','failed') NOT NULL DEFAULT 'completed',
  `notes`            TEXT          DEFAULT NULL,
  `photo_path`       VARCHAR(255)  DEFAULT NULL,
  `next_scheduled`   DATE          DEFAULT NULL,
  `created_by`       INT(11)       NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ml_asset_date`   (`asset_id`,`maintenance_date`),
  KEY `idx_ml_schedule`     (`schedule_id`),
  KEY `idx_ml_performed_by` (`performed_by`),
  KEY `idx_ml_ticket`       (`ticket_id`),
  CONSTRAINT `fk_ml_asset`    FOREIGN KEY (`asset_id`)     REFERENCES `assets`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ml_schedule` FOREIGN KEY (`schedule_id`)  REFERENCES `maintenance_schedules`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ml_ticket`   FOREIGN KEY (`ticket_id`)    REFERENCES `tickets`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ml_tech`     FOREIGN KEY (`performed_by`) REFERENCES `sys_users`(`id`),
  CONSTRAINT `fk_ml_creator`  FOREIGN KEY (`created_by`)   REFERENCES `sys_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5. ربط البلاغات بالأصول ────────────────────────────────────
-- نستخدم IGNORE لتجنب الخطأ إن وُجد العمود مسبقاً
ALTER TABLE `tickets` ADD COLUMN `asset_id` INT(11) DEFAULT NULL COMMENT 'الجهاز المتعطل';
ALTER TABLE `tickets` ADD INDEX `idx_tk_asset` (`asset_id`);
ALTER TABLE `tickets` ADD CONSTRAINT `fk_tk_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

ANALYZE TABLE `assets`, `maintenance_schedules`, `maintenance_logs`;
