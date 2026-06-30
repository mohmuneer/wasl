-- ============================================================
--  وَصْل WASL – MySQL Event Scheduler (خوادم VPS فقط)
--
--  ⚠️  لا تُنفِّذ هذا الملف على Shared Hosting (InfinityFree وغيره)
--      يتطلب: صلاحية EVENT + تفعيل event_scheduler على الخادم
--
--  تفعيل event_scheduler (مرة واحدة على الخادم):
--      SET GLOBAL event_scheduler = ON;
--      أو أضف في my.cnf:  event_scheduler = ON
-- ============================================================

SET time_zone = '+03:00';

-- حدث يفحص خرق SLA كل 15 دقيقة
CREATE EVENT IF NOT EXISTS `evt_check_sla`
ON SCHEDULE EVERY 15 MINUTE
STARTS CURRENT_TIMESTAMP
DO CALL sp_update_sla_breaches();

-- حدث يُنظِّف رموز API المنتهية أسبوعياً
CREATE EVENT IF NOT EXISTS `evt_cleanup_tokens`
ON SCHEDULE EVERY 1 WEEK
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM api_tokens
  WHERE is_active = 0
    AND expires_at < NOW() - INTERVAL 7 DAY;

-- حدث يُنظِّف محاولات تسجيل الدخول القديمة يومياً
CREATE EVENT IF NOT EXISTS `evt_cleanup_login_attempts`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
  DELETE FROM login_attempts
  WHERE attempted_at < NOW() - INTERVAL 24 HOUR;

-- ============================================================
--  للتحقق من الأحداث المُنشأة:
--      SHOW EVENTS FROM `اسم_قاعدة_البيانات`;
-- ============================================================
