<?php
/**
 * SlaCalculator — حساب مواعيد SLA بساعات العمل الفعلية
 *
 * يستثني أيام الإجازة الأسبوعية (جدول business_hours)
 * ويستثني العطل الرسمية (جدول holiday_calendar)
 *
 * مثال:
 *  $deadline = SlaCalculator::deadline(new DateTime(), 4.0, $pdo);
 *  // يُعيد تاريخ بعد 4 ساعات عمل فعلية
 */
class SlaCalculator
{
    private static array $hoursCache    = [];
    private static array $holidayCache  = [];

    // ─────────────────────────────────────────────
    //  الدالة الرئيسية: احسب موعد انتهاء SLA
    //
    //  $start  : وقت بدء حساب الـ SLA
    //  $hours  : عدد ساعات العمل المطلوبة (مثلاً 4.5)
    //  $pdo    : اتصال قاعدة البيانات
    // ─────────────────────────────────────────────
    public static function deadline(DateTime $start, float $hours, PDO $pdo): DateTime
    {
        self::loadHours($pdo);

        $current   = clone $start;
        $remaining = $hours;

        $maxDays = 365; // حماية من حلقة لانهائية
        $day     = 0;

        while ($remaining > 0.0001 && $day++ < $maxDays) {
            // تخطّ يوم إجازة كامل
            if (self::isHoliday($current, $pdo) || !self::isWorkDay((int)$current->format('w'))) {
                $current = self::nextDayStart($current);
                continue;
            }

            [$openTime, $closeTime] = self::workWindow($current);

            // إذا الوقت قبل بداية العمل، انتقل لبداية العمل
            if ($current < $openTime) {
                $current = $openTime;
            }

            // إذا الوقت بعد نهاية العمل، انتقل لليوم التالي
            if ($current >= $closeTime) {
                $current = self::nextDayStart($current);
                continue;
            }

            // ساعات العمل المتبقية في هذا اليوم
            $availableHours = ($closeTime->getTimestamp() - $current->getTimestamp()) / 3600.0;

            if ($remaining <= $availableHours) {
                $current->modify('+' . round($remaining * 3600) . ' seconds');
                $remaining = 0;
            } else {
                $remaining -= $availableHours;
                $current    = self::nextDayStart($current);
            }
        }

        return $current;
    }

    // ─────────────────────────────────────────────
    //  احسب ساعات العمل الفعلية بين تاريخين
    // ─────────────────────────────────────────────
    public static function elapsedHours(DateTime $start, DateTime $end, PDO $pdo): float
    {
        if ($end <= $start) return 0.0;

        self::loadHours($pdo);

        $current = clone $start;
        $total   = 0.0;
        $maxDays = 365;
        $day     = 0;

        while ($current < $end && $day++ < $maxDays) {
            if (self::isHoliday($current, $pdo) || !self::isWorkDay((int)$current->format('w'))) {
                $current = self::nextDayStart($current);
                continue;
            }

            [$openTime, $closeTime] = self::workWindow($current);

            $periodStart = max($current,  $openTime);
            $periodEnd   = min($end,      $closeTime);

            if ($periodStart < $periodEnd) {
                $total += ($periodEnd->getTimestamp() - $periodStart->getTimestamp()) / 3600.0;
            }

            $current = self::nextDayStart($current);
        }

        return round($total, 2);
    }

    // ─────────────────────────────────────────────
    //  التحقق من خرق SLA الآن
    // ─────────────────────────────────────────────
    public static function isBreached(?string $slaDeadline): bool
    {
        if (!$slaDeadline) return false;
        return new DateTime() > new DateTime($slaDeadline);
    }

    // ─────────────────────────────────────────────
    //  عمليات مساعدة داخلية
    // ─────────────────────────────────────────────
    private static function loadHours(PDO $pdo): void
    {
        if (!empty(self::$hoursCache)) return;

        $rows = $pdo->query(
            'SELECT day_of_week, is_working, open_time, close_time FROM business_hours ORDER BY day_of_week'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            self::$hoursCache[(int)$row['day_of_week']] = $row;
        }
    }

    private static function isWorkDay(int $dayOfWeek): bool
    {
        return !empty(self::$hoursCache[$dayOfWeek]['is_working']);
    }

    private static function isHoliday(DateTime $dt, PDO $pdo): bool
    {
        $dateStr = $dt->format('Y-m-d');

        if (!array_key_exists($dateStr, self::$holidayCache)) {
            $stmt = $pdo->prepare('SELECT 1 FROM holiday_calendar WHERE holiday_date = ? LIMIT 1');
            $stmt->execute([$dateStr]);
            self::$holidayCache[$dateStr] = (bool)$stmt->fetchColumn();
        }

        return self::$holidayCache[$dateStr];
    }

    // يُعيد [وقت_البداية, وقت_النهاية] كـ DateTime لنفس يوم $dt
    private static function workWindow(DateTime $dt): array
    {
        $dow = (int)$dt->format('w');
        $h   = self::$hoursCache[$dow] ?? null;

        $base = $dt->format('Y-m-d');

        $open  = new DateTime($base . ' ' . ($h['open_time']  ?? '08:00:00'));
        $close = new DateTime($base . ' ' . ($h['close_time'] ?? '17:00:00'));

        return [$open, $close];
    }

    // بداية يوم العمل التالي (الساعة 00:00 ثم الدالة ستُعدِّل)
    private static function nextDayStart(DateTime $dt): DateTime
    {
        $next = clone $dt;
        $next->modify('+1 day');
        $next->setTime(0, 0, 0);
        return $next;
    }
}
