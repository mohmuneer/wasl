<?php
/**
 * Database – مجرّد وصول للقاعدة بنمط Singleton
 *
 * الميزات:
 *  - اتصال واحد مشترك لكل طلب PHP (يوفر الاتصالات)
 *  - مساعد paginate() جاهز لأي استعلام
 *  - عدّاد الاستعلامات + تسجيل الاستعلامات البطيئة (> 1 ثانية)
 *  - معاملات (transactions) آمنة مع دعم التداخل
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private int   $queryCount    = 0;
    private float $totalTime     = 0.0;
    private array $slowQueries   = [];
    private int   $txDepth       = 0;

    private const SLOW_THRESHOLD = 1.0; // بالثواني

    private function __construct()
    {
        // تحميل إعدادات الاتصال من config/db.php إذا لم تكن محددة
        $host    = defined('DB_HOST')    ? DB_HOST    : 'localhost';
        $dbname  = defined('DB_NAME')    ? DB_NAME    : 'wasl';
        $user    = defined('DB_USER')    ? DB_USER    : 'root';
        $pass    = defined('DB_PASS')    ? DB_PASS    : '';
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // لا نستخدم ATTR_PERSISTENT مع InfinityFree لأن البيئة المشتركة
            // تعيد استخدام الاتصالات تلقائياً. فعّله فقط على VPS/Dedicated.
            // PDO::ATTR_PERSISTENT => true,
        ];

        $this->pdo = new PDO($dsn, $user, $pass, $options);

        // إعدادات MariaDB لتحسين الأداء
        $this->pdo->exec("SET time_zone = '+03:00'");
        // query_cache أُزيل من MariaDB 11.4+ — لا حاجة لتفعيله يدوياً
        // $this->pdo->exec("SET SESSION query_cache_type = ON");
    }

    // ─────────────────────────────────────────────
    //  الحصول على الكائن الوحيد
    // ─────────────────────────────────────────────
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ─────────────────────────────────────────────
    //  تنفيذ استعلام مع إحصاء الوقت
    // ─────────────────────────────────────────────
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);
        $stmt  = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $elapsed = microtime(true) - $start;

        $this->queryCount++;
        $this->totalTime += $elapsed;

        if ($elapsed >= self::SLOW_THRESHOLD) {
            $this->slowQueries[] = [
                'sql'     => $sql,
                'params'  => $params,
                'seconds' => round($elapsed, 4),
            ];
        }

        return $stmt;
    }

    // ─────────────────────────────────────────────
    //  جلب كل الصفوف
    // ─────────────────────────────────────────────
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    // ─────────────────────────────────────────────
    //  جلب صف واحد
    // ─────────────────────────────────────────────
    public function fetchOne(string $sql, array $params = []): array|false
    {
        return $this->execute($sql, $params)->fetch();
    }

    // ─────────────────────────────────────────────
    //  جلب قيمة عمود واحد (COUNT, SUM, MAX …)
    // ─────────────────────────────────────────────
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        return $this->execute($sql, $params)->fetchColumn();
    }

    // ─────────────────────────────────────────────
    //  ترقيم الصفحات
    //
    //  $sql   → استعلام SELECT (بدون LIMIT)
    //  $page  → رقم الصفحة الحالية (يبدأ من 1)
    //  $limit → عدد الصفوف في الصفحة
    //
    //  إرجاع: ['data'=>[], 'total'=>int, 'pages'=>int, 'page'=>int, 'limit'=>int]
    // ─────────────────────────────────────────────
    public function paginate(
        string $sql,
        array  $params  = [],
        int    $page    = 1,
        int    $limit   = ITEMS_PER_PAGE
    ): array {
        $page  = max(1, $page);
        $limit = max(1, $limit);

        // عد الإجمالي بنفس الشروط
        $countSql = preg_replace('/^\s*SELECT\s+.+?\s+FROM\s+/is', 'SELECT COUNT(*) FROM ', $sql, 1);
        // نزيل ORDER BY من استعلام العد
        $countSql = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $countSql);
        $total    = (int) $this->fetchScalar($countSql, $params);

        $pages  = (int) ceil($total / $limit);
        $offset = ($page - 1) * $limit;

        $data = $this->fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

        return [
            'data'  => $data,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    // ─────────────────────────────────────────────
    //  معاملات آمنة (تدعم التداخل عبر Savepoints)
    // ─────────────────────────────────────────────
    public function beginTransaction(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT sp_{$this->txDepth}");
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec("RELEASE SAVEPOINT sp_{$this->txDepth}");
        }
    }

    public function rollback(): void
    {
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT sp_{$this->txDepth}");
        }
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // ─────────────────────────────────────────────
    //  إحصاءات الأداء (للوحة الأداء أو التصحيح)
    // ─────────────────────────────────────────────
    public function getStats(): array
    {
        return [
            'query_count'  => $this->queryCount,
            'total_time'   => round($this->totalTime, 4),
            'slow_queries' => $this->slowQueries,
        ];
    }

    // لا نسمح بالنسخ أو التسلسل
    private function __clone() {}
    public function __wakeup() { throw new \Exception('Database singleton cannot be unserialized'); }
}
