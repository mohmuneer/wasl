-- ============================================================
--  إضافة صلاحيتي عرض المهام (حسب المجموعة وحسب المستخدم)
--  التاريخ: 2026-07-01
-- ============================================================

ALTER TABLE `user_menu_access`
  ADD COLUMN IF NOT EXISTS `can_view_group_tasks` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'عرض المهام حسب المجموعات المسندة' AFTER `can_view_archive`,
  ADD COLUMN IF NOT EXISTS `can_view_own_tasks`   TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'عرض المهام الخاصة بالمستخدم فقط' AFTER `can_view_group_tasks`;

-- تحديث صلاحيات المستخدم admin (id=2) لتفعيل الخيارين الجديدين لصفحة المهام (menu_id=28)
UPDATE `user_menu_access`
SET `can_view_group_tasks` = 1,
    `can_view_own_tasks`   = 1
WHERE `menu_id` = 28
  AND `user_id` IN (
    SELECT `user_id` FROM `user_roles` ur
    JOIN `sys_roles` sr ON ur.`role_id` = sr.`id`
    WHERE LOWER(TRIM(sr.`role_name`)) IN ('ادمن الأساسي','ادمن الفرعي','mainadmin','subadmin','مدير النظام','مشرف عمليات')
  );
