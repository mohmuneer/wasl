-- ============================================================
--  وَصْل WASL – هجرة v2.0
--  الإضافات: أمان + SLA دقيق + API + إشعارات
--  تاريخ: 2026-06-17
--  نفَّذ هذا الملف بعد wasl_database.sql
--
--  ملاحظات التنفيذ:
--  ① الملف آمن للتنفيذ أكثر من مرة (INSERT IGNORE + IF NOT EXISTS)
--  ② CREATE EVENT معطَّل تلقائياً في Shared Hosting — استخدم cron بدلاً عنه
--  ③ يتطلب MariaDB 10.5.3+ أو MySQL 8.0+ لـ ADD INDEX IF NOT EXISTS
-- ============================================================

SET NAMES utf8mb4;
SET time_zone        = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════════════════════════
--  أ. الأمان — محاولات تسجيل الدخول الفاشلة
-- ════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `identifier`  VARCHAR(100) NOT NULL COMMENT 'البريد الإلكتروني',
  `ip_address`  VARCHAR(50)  NOT NULL,
  `attempted_at`TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_identifier` (`identifier`),
  KEY `idx_ip`         (`ip_address`),
  KEY `idx_time`       (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تتبع محاولات تسجيل الدخول الفاشلة لمنع Brute Force';

-- ════════════════════════════════════════════════════════════
--  ب. SLA دقيق — ساعات العمل والإجازات
-- ════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `business_hours` (
  `id`         INT(11)    NOT NULL AUTO_INCREMENT,
  `day_of_week`TINYINT(1) NOT NULL COMMENT '0=الأحد, 1=الاثنين … 6=السبت',
  `day_name`   VARCHAR(20)NOT NULL COMMENT 'اسم اليوم بالعربية',
  `is_working` TINYINT(1) NOT NULL DEFAULT 1,
  `open_time`  TIME       DEFAULT '08:00:00',
  `close_time` TIME       DEFAULT '17:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_day` (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ساعات العمل الرسمية لكل يوم من أيام الأسبوع';

-- INSERT IGNORE: آمن للتنفيذ المتكرر — يتجاهل السطور المكررة
INSERT IGNORE INTO `business_hours` (`day_of_week`,`day_name`,`is_working`,`open_time`,`close_time`) VALUES
(0, 'الأحد',      1, '08:00:00', '17:00:00'),
(1, 'الاثنين',   1, '08:00:00', '17:00:00'),
(2, 'الثلاثاء',  1, '08:00:00', '17:00:00'),
(3, 'الأربعاء',  1, '08:00:00', '17:00:00'),
(4, 'الخميس',    1, '08:00:00', '17:00:00'),
(5, 'الجمعة',    0, NULL,        NULL),
(6, 'السبت',     0, NULL,        NULL);

CREATE TABLE IF NOT EXISTS `holiday_calendar` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `holiday_date` DATE         NOT NULL,
  `holiday_name` VARCHAR(255) NOT NULL,
  `is_recurring` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = تتكرر كل عام بنفس اليوم/الشهر',
  `notes`        VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_date` (`holiday_date`),
  KEY `idx_holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الإجازات الرسمية في المملكة العربية السعودية';

-- INSERT IGNORE: يتجاهل التواريخ الموجودة مسبقاً
INSERT IGNORE INTO `holiday_calendar` (`holiday_date`,`holiday_name`,`is_recurring`) VALUES
('2026-02-22', 'يوم التأسيس',                1),
('2026-09-23', 'اليوم الوطني',               1),
('2026-03-29', 'عيد الفطر – اليوم الأول',    0),
('2026-03-30', 'عيد الفطر – اليوم الثاني',   0),
('2026-03-31', 'عيد الفطر – اليوم الثالث',   0),
('2026-06-05', 'وقفة عرفات',                 0),
('2026-06-06', 'عيد الأضحى – اليوم الأول',   0),
('2026-06-07', 'عيد الأضحى – اليوم الثاني',  0),
('2026-06-08', 'عيد الأضحى – اليوم الثالث',  0);

-- ════════════════════════════════════════════════════════════
--  ج. قائمة انتظار البريد الإلكتروني (Async)
-- ════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `to_email`    VARCHAR(255) NOT NULL,
  `to_name`     VARCHAR(255) DEFAULT NULL,
  `subject`     VARCHAR(500) NOT NULL,
  `body_html`   LONGTEXT     NOT NULL,
  `status`      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`    TINYINT      NOT NULL DEFAULT 0,
  `sent_at`     TIMESTAMP    NULL DEFAULT NULL,
  `error_msg`   TEXT         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status`     (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='قائمة انتظار البريد الإلكتروني للإرسال غير المتزامن';

-- ════════════════════════════════════════════════════════════
--  د. رموز API للتطبيق الجوال
-- ════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `token`       VARCHAR(64)  NOT NULL COMMENT 'SHA-256 hex token',
  `device_name` VARCHAR(100) DEFAULT NULL COMMENT 'اسم الجهاز',
  `device_type` ENUM('ios','android','web','other') DEFAULT 'other',
  `last_used`   TIMESTAMP    NULL DEFAULT NULL,
  `expires_at`  DATETIME     NOT NULL COMMENT 'يُحدَّد بـ PHP: NOW() + 30 يوم',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user_id`   (`user_id`),
  KEY `idx_expires`   (`expires_at`,`is_active`),
  CONSTRAINT `fk_at_user`
    FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='رموز مصادقة API للتطبيق الجوال';

-- ════════════════════════════════════════════════════════════
--  هـ. تحسينات على جدول tickets
-- ════════════════════════════════════════════════════════════

-- إضافة حقول SLA القائمة على ساعات العمل
-- IF NOT EXISTS: آمن إذا كانت الأعمدة موجودة مسبقاً (MariaDB 10.0.2+)
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `sla_response_deadline` DATETIME DEFAULT NULL
      COMMENT 'موعد أول رد مُحسَب بساعات العمل'
      AFTER `sla_breach_at`,
  ADD COLUMN IF NOT EXISTS `sla_resolve_deadline`  DATETIME DEFAULT NULL
      COMMENT 'موعد الإغلاق مُحسَب بساعات العمل'
      AFTER `sla_response_deadline`;

-- فهرس Full-Text على تفاصيل التذاكر للبحث السريع
-- IF NOT EXISTS: آمن للتنفيذ المتكرر (MariaDB 10.5.3+)
ALTER TABLE `tickets`
  ADD FULLTEXT KEY IF NOT EXISTS `ft_details` (`details`);

-- ════════════════════════════════════════════════════════════
--  و. حدث MySQL التلقائي (اختياري — للخوادم المخصصة فقط)
-- ════════════════════════════════════════════════════════════
--
--  ⚠️  CREATE EVENT يتطلب صلاحية EVENT على قاعدة البيانات.
--      هذه الصلاحية غير متاحة في InfinityFree وأغلب Shared Hosting.
--
--  البديل الموصى به:
--      أضف cron job يستدعي نقطة API كل 15 دقيقة:
--      */15 * * * *  curl -s "https://your-domain.com/api/index.php?endpoint=cron&action=sla&cron_key=SECRET"
--
--  إذا كان لديك خادم مخصص (VPS) وأردت تفعيل الحدث، نفِّذ الملف التالي يدوياً:
--      wasl_event_scheduler.sql
--
-- ════════════════════════════════════════════════════════════

-- ════════════════════════════════════════════════════════════
--  ز. إعداد اسم النظام في الإعدادات
-- ════════════════════════════════════════════════════════════
INSERT INTO `sys_settings` (`id`,`system_name`,`system_name_en`,`admin_email`) VALUES
(1, 'وَصْل', 'WASL', 'admin@example.com')
ON DUPLICATE KEY UPDATE
  `system_name`    = VALUES(`system_name`),
  `system_name_en` = VALUES(`system_name_en`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  ملاحظات ما بعد التنفيذ
--  ① business_hours: حدِّث open_time/close_time حسب توقيت شركتك
--  ② holiday_calendar: أضف الإجازات الهجرية سنوياً (عيد الفطر، عيد الأضحى)
--  ③ email_queue: أنشئ cron job كل دقيقة يستدعي /api/index.php?endpoint=cron&action=email
--  ④ api_tokens: رموز 30 يوم — غيّره لـ 7 أيام في config/notify.php لأمان أعلى
--  ⑤ لتفعيل MySQL Event على VPS: نفِّذ wasl_event_scheduler.sql
-- ============================================================
