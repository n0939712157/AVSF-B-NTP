<?php
/**
 * AVSF Security Library
 * ระบบรักษาความปลอดภัยรวม — include ที่ทุกหน้า
 * วิธีใช้: require_once AVSF_CORE_DIR . '/security/security_lib.php';
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ====== ค่าคอนฟิกความปลอดภัย (แก้ไขได้) ======
define('SEC_LOGIN_MAX_ATTEMPTS', 5);       // ทายผิดได้สูงสุดกี่ครั้ง
define('SEC_LOCKOUT_DURATION', 900);       // ระยะเวลาล็อก (วินาที) = 15 นาที
define('SEC_CSRF_TOKEN_NAME', 'csrf_token');
define('SEC_UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('SEC_ALLOWED_IMAGE_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('SEC_ALLOWED_IMAGE_MIME', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ====== 1. XSS Escape — ใช้ทุกครั้งที่ echo ข้อมูลจากผู้ใช้ ======
if (!function_exists('e')) {
    function e($str, $flags = ENT_QUOTES, $encoding = 'UTF-8') {
        return htmlspecialchars((string)$str, $flags, $encoding);
    }
}

// ====== 2. CSRF Token ======
if (!function_exists('sec_csrf_token')) {
    function sec_csrf_token() {
        if (empty($_SESSION[SEC_CSRF_TOKEN_NAME])) {
            $_SESSION[SEC_CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[SEC_CSRF_TOKEN_NAME];
    }

    function sec_csrf_field() {
        return '<input type="hidden" name="' . SEC_CSRF_TOKEN_NAME . '" value="' . e(sec_csrf_token()) . '">';
    }

    function sec_csrf_verify() {
        $token = $_POST[SEC_CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($token) || !hash_equals($_SESSION[SEC_CSRF_TOKEN_NAME] ?? '', $token)) {
            http_response_code(403);
            die(__('CSRF token ไม่ถูกต้อง — กรุณารีเฟรชหน้าแล้วลองใหม่'));
        }
        // เก็บ token เดิมไว้ตลอดหน้าสถานะเดียวกัน เพื่อไม่ให้ฟอร์มหลายปุ่มในหน้าเดียวกัน
        // ทำให้ปุ่มถัดไปผิด CSRF หลังล้างแคชหรือบันทึกปุ่มแรกแล้วกลับมาหน้าเดิม
        return true;
    }
}

// ====== 3. Input Sanitization ======
if (!function_exists('sec_get')) {
    function sec_get($key, $default = '') {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }
    function sec_post($key, $default = '') {
        return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
    }
    function sec_int($key, $default = 0) {
        $val = $_REQUEST[$key] ?? $default;
        return filter_var($val, FILTER_VALIDATE_INT) !== false ? (int)$val : (int)$default;
    }
    function sec_slug($str) {
        $str = strtolower(trim($str));
        $str = preg_replace('/[^a-z0-9\-]/', '-', $str);
        $str = preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }
}

// ====== 4. Brute Force Protection (ต้องมีตาราง login_attempts) ======
if (!function_exists('sec_is_ip_locked')) {
    function sec_db() {
        // คืน mysqli connection — config.php สร้าง $conn ไว้แล้ว
        global $conn;
        return $conn;
    }

    function sec_client_ip() {
        // Forwarded headers are client-controlled unless a trusted-proxy allowlist is configured.
        $trustedProxies = [];
        if (function_exists('setting')) {
            $raw = trim((string)setting('security_trusted_proxy_ips', ''));
            if ($raw !== '') $trustedProxies = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($trustedProxies && in_array($remote, $trustedProxies, true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (empty($_SERVER[$header])) continue;
                $ip = trim(explode(',', (string)$_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    function sec_is_ip_locked($conn = null) {
        $conn = $conn ?: sec_db();
        if (!$conn) return false;
        $ip = sec_client_ip();
        $cutoff = time() - SEC_LOCKOUT_DURATION;
        try {
            $ipE = mysqli_real_escape_string($conn, $ip);
            $result = @mysqli_query($conn, "SELECT COUNT(*) FROM login_attempts WHERE ip = '$ipE' AND success = 0 AND attempted_at > FROM_UNIXTIME($cutoff)");
            if (!$result) return false;
            $fails = (int)mysqli_fetch_row($result)[0];
            return $fails >= SEC_LOGIN_MAX_ATTEMPTS;
        } catch (Throwable $e) {
            return false; // ถ้าตารางยังไม่มี หรือ error ใดๆ อย่าล็อก
        }
    }

    function sec_log_login_attempt($username, $success, $conn = null) {
        $conn = $conn ?: sec_db();
        if (!$conn) return;
        try {
            $ipE = mysqli_real_escape_string($conn, sec_client_ip());
            $userE = mysqli_real_escape_string($conn, substr($username, 0, 100));
            $s = $success ? 1 : 0;
            @mysqli_query($conn, "INSERT INTO login_attempts (ip, username, success, attempted_at) VALUES ('$ipE', '$userE', $s, NOW())");
            @mysqli_query($conn, "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        } catch (Throwable $e) {
            // ข้ามถ้าตารางยังไม่มี
        }
    }
}

// ====== 5. File Upload Security ======
if (!function_exists('sec_handle_image_upload')) {
    function sec_handle_image_upload($fileInputName, $uploadDir) {
        if (!isset($_FILES[$fileInputName]) || !is_array($_FILES[$fileInputName]) || (int)($_FILES[$fileInputName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => __('ไม่มีไฟล์หรืออัปโหลดไม่สำเร็จ')];
        }
        $file = $_FILES[$fileInputName];
        if (!isset($file['tmp_name'],$file['size'],$file['name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => __('ไฟล์อัปโหลดไม่ถูกต้อง')];
        }
        if ((int)$file['size'] < 1 || (int)$file['size'] > SEC_UPLOAD_MAX_SIZE) {
            return ['ok' => false, 'error' => __('ไฟล์ใหญ่เกิน 5MB')];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) return ['ok'=>false, 'error'=>__('ตรวจสอบชนิดรูปภาพไม่ได้')];
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $imgInfo = @getimagesize($file['tmp_name']);
        if (!in_array($realMime, SEC_ALLOWED_IMAGE_MIME, true) || $imgInfo === false || ($imgInfo['mime'] ?? '') !== $realMime) {
            return ['ok' => false, 'error' => __('ไฟล์ไม่ใช่รูปภาพที่อนุญาต (jpg/png/gif/webp เท่านั้น)')];
        }

        // Preserve the original basename and extension; never replace it with an MD5/random name.
        $originalName = basename(str_replace('\\', '/', (string)$file['name']));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeExtensions = [
            'image/jpeg'=>['jpg','jpeg'], 'image/png'=>['png'],
            'image/gif'=>['gif'], 'image/webp'=>['webp'],
        ];
        if ($originalName === '' || $originalName === '.' || $originalName === '..'
            || preg_match('~[\\x00-\\x1F\\x7F:/\\\\]~', $originalName)
            || strlen($originalName) > 240
            || !isset($mimeExtensions[$realMime]) || !in_array($extension, $mimeExtensions[$realMime], true)) {
            return ['ok'=>false, 'error'=>__('ชื่อไฟล์ภาพไม่ถูกต้องหรือสกุลไฟล์ไม่ตรงกับชนิดภาพ')];
        }

        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['ok'=>false, 'error'=>__('สร้างโฟลเดอร์เก็บรูปไม่ได้')];
        }
        if (!is_writable($uploadDir)) return ['ok'=>false, 'error'=>__('โฟลเดอร์ uploads ไม่มีสิทธิ์เขียนไฟล์')];
        $dest = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $originalName;
        if (is_link($dest) || is_dir($dest)) return ['ok'=>false, 'error'=>__('ปลายทางชื่อไฟล์นี้ไม่ปลอดภัย')];
        if (is_file($dest)) {
            $oldHash = @hash_file('sha256', $dest);
            $newHash = @hash_file('sha256', $file['tmp_name']);
            if (is_string($oldHash) && is_string($newHash) && hash_equals($oldHash, $newHash)) {
                return ['ok'=>true, 'filename'=>$originalName, 'path'=>$dest, 'mime'=>$realMime, 'duplicate'=>true];
            }
            return ['ok'=>false, 'error'=>__('มีไฟล์ชื่อเดียวกันอยู่แล้ว ระบบจะไม่เปลี่ยนชื่อหรือเขียนทับให้อัตโนมัติ')];
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => __('ไม่สามารถบันทึกไฟล์ได้ กรุณาตรวจสอบสิทธิ์โฟลเดอร์ uploads')];
        }
        @chmod($dest, 0644);
        return ['ok' => true, 'filename' => $originalName, 'path' => $dest, 'mime' => $realMime, 'duplicate'=>false];
    }
}

// ====== 6. Rate Limit (ป้องกัน spam คอมเมนต์ / submit ฟอร์มบ่อยเกินไป) ======
if (!function_exists('sec_rate_limit')) {
    function sec_rate_limit($action, $maxPerMinute = 5) {
        $key = 'rate_' . $action . '_' . sec_client_ip();
        $now = time();
        $history = $_SESSION[$key] ?? [];
        $history = array_filter($history, fn($t) => ($now - $t) < 60);
        if (count($history) >= $maxPerMinute) {
            http_response_code(429);
            die(__('กรุณารอสักครู่แล้วลองใหม่ (ส่งบ่อยเกินไป)'));
        }
        $history[] = $now;
        $_SESSION[$key] = $history;
        return true;
    }
}

// ====== 7. Security Headers (ส่งทุกหน้า) ======
if (!function_exists('sec_send_headers')) {
    function sec_send_headers() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // ห้าม browser cache หน้าที่มี session (admin)
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== false) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
    }
}
sec_send_headers();

// ====== 8. ป้องกัน Clickjacking + HSTS (ถ้าใช้ HTTPS ให้เอา comment ออก) ======
// if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
//     header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
// }

// ====== 9. ห้ามเรียกไฟล์นี้โดยตรง ======
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'security_lib.php') {
    http_response_code(403);
    exit;
}
