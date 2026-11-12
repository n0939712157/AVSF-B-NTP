<?php
/**
 * AVSF Cache Library
 * ระบบแคชไฟล์ HTML — ช่วยเร็วขึ้น 5-20 เท่า ลดภาระ MySQL
 * วิธีใช้: require_once ที่ต้นไฟล์ index.php (หลัง config)
 *
 *   $cache = new AVSFCache(AVSF_CACHE_DIR, 600); // TTL 600 วิ = 10 นาที
 *   if ($cache->start()) { exit; } // ถ้ามีแคช จะ echo แล้วจบการทำงาน
 *   ... เนื้อหาหน้า ...
 *   $cache->end(); // บันทึกแคช
 */

class AVSFCache {
    private $dir;
    private $ttl;
    private $enabled;
    private $key;
    private $skip = false;

    public function __construct($cacheDir, $ttlSeconds = 600) {
        $this->dir = rtrim($cacheDir, '/');
        $this->ttl = max(60, (int)$ttlSeconds);
        $this->enabled = true; // caller (index.php) already checks setting('cache_enabled') before instantiating
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        // ไม่แคชถ้าเป็น POST / มี query พิเศษ / เป็นแอดมิน / มี session message
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->skip = (
            $_SERVER['REQUEST_METHOD'] !== 'GET' ||
            strpos($uri, '/admin') !== false ||
            strpos($uri, '?login') !== false ||
            strpos($uri, '&msg=') !== false ||
            strpos($uri, '?success=') !== false ||
            strpos($uri, '?error=') !== false ||
            (isset($_SESSION['flash']) && !empty($_SESSION['flash']))
        );
        $this->key = md5($uri . '|' . ($_SERVER['QUERY_STRING'] ?? ''));
    }

    /** คืน path ไฟล์แคช */
    private function file() {
        return $this->dir . '/' . $this->key . '.html';
    }

    /** ตรวจสอบว่ามีแคชที่ยังไม่หมดอายุหรือไม่ — ถ้ามี echo ทันที คืน true */
    public function start() {
        if (!$this->enabled || $this->skip) return false;
        $f = $this->file();
        if (file_exists($f) && (time() - filemtime($f)) < $this->ttl) {
            header('X-avsf-Cache: HIT');
            readfile($f);
            return true;
        }
        header('X-avsf-Cache: MISS');
        ob_start();
        return false;
    }

    /** จบการทำงาน — บันทึก output ลงแคช */
    public function end() {
        if (!$this->enabled || $this->skip) return;
        $content = ob_get_flush();
        if ($content !== false && strlen($content) > 100) {
            @file_put_contents($this->file(), $content, LOCK_EX);
        }
    }

    /** ล้างแคชทั้งหมด คืนจำนวนไฟล์ที่ลบ */
    public static function clearAll($cacheDir) {
        $cacheDir = rtrim($cacheDir, '/');
        $count = 0;
        $files = glob($cacheDir . '/*.html');
        foreach (is_array($files) ? $files : [] as $f) {
            if (@unlink($f)) $count++;
        }
        return $count;
    }

    /** ล้างเฉพาะไฟล์ที่หมดอายุ คืนจำนวนที่ลบ */
    public static function clearExpired($cacheDir, $ttl) {
        $cacheDir = rtrim($cacheDir, '/');
        $count = 0;
        $cutoff = time() - $ttl;
        foreach (glob($cacheDir . '/*.html') as $f) {
            if (filemtime($f) < $cutoff && @unlink($f)) $count++;
        }
        return $count;
    }

    /** สถิติแคช */
    public static function stats($cacheDir) {
        $cacheDir = rtrim($cacheDir, '/');
        $files = glob($cacheDir . '/*.html') ?: [];
        $totalSize = 0;
        foreach ($files as $f) $totalSize += filesize($f);
        return [
            'count' => count($files),
            'size_bytes' => $totalSize,
            'size_mb' => round($totalSize / 1048576, 2),
        ];
    }

    /** ล้างแคชเฉพาะหน้า (เช่น ตอนแก้ไขโพสต์) */
    public static function clearUrl($cacheDir, $urlPath) {
        $parts = parse_url($urlPath);
        $requestUri = ($parts['path'] ?? $urlPath) . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $key = md5($requestUri . '|' . ($parts['query'] ?? ''));
        $f = rtrim($cacheDir, '/') . '/' . $key . '.html';
        return is_file($f) && @unlink($f);
    }
}

// ห้ามเรียกโดยตรง
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'cache_lib.php') {
    http_response_code(403);
    exit;
}
