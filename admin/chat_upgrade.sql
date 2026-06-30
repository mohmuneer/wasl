-- ============================================
-- WASL CRM - Chat System Upgrade Migration
-- Adds new columns to the messages table
-- ============================================

ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS `message_type` ENUM('text','image','file') NOT NULL DEFAULT 'text' AFTER `message_text`,
  ADD COLUMN IF NOT EXISTS `file_path`     VARCHAR(500) DEFAULT NULL AFTER `message_type`,
  ADD COLUMN IF NOT EXISTS `file_name`     VARCHAR(255) DEFAULT NULL AFTER `file_path`,
  ADD COLUMN IF NOT EXISTS `file_size`     INT(11) DEFAULT NULL AFTER `file_name`,
  ADD COLUMN IF NOT EXISTS `edited_at`     DATETIME DEFAULT NULL AFTER `file_size`,
  ADD COLUMN IF NOT EXISTS `seen_at`       DATETIME DEFAULT NULL AFTER `edited_at`,
  ADD COLUMN IF NOT EXISTS `deleted_at`    DATETIME DEFAULT NULL AFTER `seen_at`,
  ADD INDEX `idx_message_type` (`message_type`),
  ADD INDEX `idx_deleted_at` (`deleted_at`);

-- Table for typing indicators
CREATE TABLE IF NOT EXISTS `typing_status` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11) NOT NULL,
  `peer_id`     INT(11) NOT NULL,
  `is_typing`   TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_peer` (`user_id`,`peer_id`),
  KEY `idx_peer` (`peer_id`),
  CONSTRAINT `fk_typing_user` FOREIGN KEY (`user_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_typing_peer` FOREIGN KEY (`peer_id`) REFERENCES `sys_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حالة الكتابة للمستخدمين';
