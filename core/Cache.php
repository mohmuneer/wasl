<?php
/**
 * Cache – طبقة تخزين مؤقت ذكية
 *
 * الأولوية:
 *  1. APCu  (في الذاكرة، إذا كانت مثبتة على الخادم)
 *  2. ملفات (احتياطية دائماً تعمل)
 *
 * الاستخدام:
 *  $cache = Cache::getInstance();
 *  $data  = $cache->remember('tickets_summary', 120, fn() => $db->fetchAll($sql));
 */
class Cache
{
    private static ?Cache $instance = null;
    private bool   $apcu;
    private string $cacheDir;
    private string $prefix;

    private int $hits   = 0;
    private int $misses = 0;

    private function __construct()
    {
        $this->apcu     = function_exists('apcu_fetch') && ini_get('apc.enabled');
        $this->cacheDir = defined('CACHE_DIR')
            ? CACHE_DIR
            : dirname(__DIR__) . '/storage/cache/';
        $this->prefix   = defined('CACHE_PREFIX') ? CACHE_PREFIX : 'wasl_';

        if (!$this->apcu && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }
    }

    public static function getInstance(): Cache
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─────────────────────────────────────────────
    //  قراءة من الكاش
    // ─────────────────────────────────────────────
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;

        if ($this->apcu) {
            $success = false;
            $value   = apcu_fetch($fullKey, $success);
            if ($success) {
                $this->hits++;
                return $value;
            }
        } else {
            $file = $this->filePath($fullKey);
            if (file_exists($file)) {
                $raw  = unserialize(file_get_contents($file));
                if ($raw['expires'] > time()) {
                    $this->hits++;
                    return $raw['value'];
                }
                @unlink($file);
            }
        }

        $this->misses++;
        return null;
    }

    // ─────────────────────────────────────────────
    //  حفظ في الكاش
    // ─────────────────────────────────────────────
    public function set(string $key, mixed $value, int $ttl = CACHE_TTL_SHORT): bool
    {
        $fullKey = $this->prefix . $key;

        if ($this->apcu) {
            return apcu_store($fullKey, $value, $ttl);
        }

        $data = serialize(['expires' => time() + $ttl, 'value' => $value]);
        return file_put_contents($this->filePath($fullKey), $data, LOCK_EX) !== false;
    }

    // ─────────────────────────────────────────────
    //  حذف مفتاح واحد
    // ─────────────────────────────────────────────
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;

        if ($this->apcu) {
            return apcu_delete($fullKey);
        }

        $file = $this->filePath($fullKey);
        return file_exists($file) ? unlink($file) : true;
    }

    // ─────────────────────────────────────────────
    //  مسح الكاش (كل أو بادئة محددة)
    // ─────────────────────────────────────────────
    public function flush(string $pattern = ''): void
    {
        if ($this->apcu) {
            if ($pattern === '') {
                apcu_clear_cache();
            } else {
                $info = apcu_cache_info();
                foreach ($info['cache_list'] ?? [] as $item) {
                    if (str_starts_with($item['info'], $this->prefix . $pattern)) {
                        apcu_delete($item['info']);
                    }
                }
            }
            return;
        }

        $search = $this->prefix . $pattern;
        foreach (glob($this->cacheDir . '*.cache') as $file) {
            $base = basename($file, '.cache');
            if ($pattern === '' || str_starts_with($base, md5($search))) {
                @unlink($file);
            }
        }
    }

    // ─────────────────────────────────────────────
    //  الاسترجاع أو الحساب (النمط الأكثر استخداماً)
    //
    //  $data = $cache->remember('branch_list', 600, function() use ($db) {
    //      return $db->fetchAll("SELECT * FROM " . TBL_BRANCHES);
    //  });
    // ─────────────────────────────────────────────
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    // ─────────────────────────────────────────────
    //  إبطال الكاش عند حفظ/حذف بيانات معينة
    //  يستخدم بادئة الجدول لمسح كل كاش مرتبط به
    // ─────────────────────────────────────────────
    public function invalidateTable(string $tableName): void
    {
        $this->flush($tableName . '_');
    }

    // ─────────────────────────────────────────────
    //  إحصاءات
    // ─────────────────────────────────────────────
    public function getStats(): array
    {
        return [
            'driver' => $this->apcu ? 'APCu' : 'File',
            'hits'   => $this->hits,
            'misses' => $this->misses,
            'ratio'  => $this->hits + $this->misses > 0
                ? round($this->hits / ($this->hits + $this->misses) * 100, 1) . '%'
                : 'N/A',
        ];
    }

    private function filePath(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }

    private function __clone() {}
    public function __wakeup() { throw new \Exception('Cache singleton cannot be unserialized'); }
}
