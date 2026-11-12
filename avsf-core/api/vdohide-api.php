<?php
/**
 * AVSF VDOHide Remote Player API — clean wrapper (โค้ดเขียนเอง, ไม่คัดลอกธีม).
 *
 * รับผิดชอบ:
 *  - ตรวจสอบ source URL ว่าเป็น known player หรือไม่ (ไม่ต้องผ่าน remote API)
 *  - ส่ง source ไป https://vdohide.com/api/remote เพื่อขอ player slug
 *  - สร้าง embed URL จากโดเมนที่ตั้งค่า (vdohideIframeDomain)
 *  - คืนผลในรูปแบบเดียวกับ videoApiRemote() เดิม เพื่อความเข้ากันได้
 *
 * Security:
 *  - token อ่านจาก env AVSF_VDOHIDE_TOKEN ก่อน (ลำดับสูง) ถึงจะ fallback ที่ DB
 *  - ตรวจสอบ response slug ด้วย regex เท่านั้น (ป้องกัน injection เข้า src)
 *  - ตรวจสอบ player domain ว่าเป็น https เท่านั้น
 *  - cURL: SSL verify เปิด, timeout จำกัด, ไม่ follow redirect
 */
if (!defined('AVSF_VDOHIDE_ENDPOINT')) {
    define('AVSF_VDOHIDE_ENDPOINT', 'https://vdohide.com/api/remote');
}
if (!defined('AVSF_VDOHIDE_DEFAULT_DOMAIN')) {
    define('AVSF_VDOHIDE_DEFAULT_DOMAIN', ''); // ตั้งค่าในหลังบ้าน Settings → VDOHide API หรือ env AVSF_VDOHIDE_IFRAME_DOMAIN
}

function avsf_vdohide_token() {
    $env = getenv('AVSF_VDOHIDE_TOKEN');
    if ($env !== false && trim((string)$env) !== '') return trim((string)$env);
    if (function_exists('setting')) {
        $db = trim((string)setting('vdohideApiToken', ''));
        if ($db !== '') return $db;
    }
    return '';
}

function avsf_vdohide_player_domain() {
    $base = AVSF_VDOHIDE_DEFAULT_DOMAIN;
    if (function_exists('setting')) {
        $cfg = trim((string)setting('vdohideIframeDomain', ''));
        if ($cfg !== '') $base = $cfg;
    }
    $base = rtrim($base, '/');
    if (!preg_match('#^https://#i', $base)) $base = AVSF_VDOHIDE_DEFAULT_DOMAIN;
    return $base;
}

/**
 * @param string $source ลิงก์วิดีโอต้นฉบับ หรือ iframe เต็ม หรือ player id
 * @return array {url:string, error:string}
 */
function avsf_vdohide_resolve($source) {
    if (!function_exists('normalizeVideoInput') || !function_exists('isSupportedVideoPlayer')) {
        return ['url' => '', 'error' => 'video helpers not loaded'];
    }
    $token = avsf_vdohide_token();
    $info = normalizeVideoInput($source);
    if ($info['error'] !== '' || $info['url'] === '') {
        return ['url' => '', 'error' => $info['error']];
    }
    // Known player — ใช้ URL ตรงได้เลย ไม่ต้องเสียเวลาเรียก remote
    if (isSupportedVideoPlayer($info['url'])) {
        return ['url' => $info['url'], 'error' => ''];
    }
    // ไม่มี token — คืนว่างเปล่า (ปล่อยให้ fallback ไป direct embed)
    if ($token === '') {
        return ['url' => '', 'error' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['url' => '', 'error' => __('เซิร์ฟเวอร์ยังไม่ได้เปิด PHP cURL สำหรับ Remote API')];
    }
    $payload = json_encode(['source' => $info['url'], 'token' => $token]);
    if ($payload === false) return ['url' => '', 'error' => __('เตรียมข้อมูล Remote API ไม่สำเร็จ')];

    $curl = curl_init(AVSF_VDOHIDE_ENDPOINT);
    if ($curl === false) return ['url' => '', 'error' => __('เริ่มการเชื่อมต่อ Remote API ไม่สำเร็จ')];
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log('[AVSF vdohide] remote API failed; HTTP ' . $status . ($error !== '' ? ' ' . $error : ''));
        return ['url' => '', 'error' => __('Remote API ไม่ตอบกลับสำเร็จ (HTTP %s)', [$status])];
    }
    $data = json_decode($body, true);
    $slug = is_array($data) && isset($data['slug']) ? (string)$data['slug'] : '';
    if (!preg_match('/^[A-Za-z0-9_-]{3,160}$/', $slug)) {
        error_log('[AVSF vdohide] invalid response slug');
        return ['url' => '', 'error' => __('Remote API ไม่ส่ง player ID ที่ถูกต้อง')];
    }
    return ['url' => avsf_vdohide_player_domain() . '/embed/' . $slug . '/', 'error' => ''];
}

/**
 * สร้าง <iframe> player ที่ปลอดภัย (sanitize src, attributes คงที่).
 * ใช้ attribute ตาม pattern ที่ต้องการ: allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
 */
function avsf_player_iframe($embedUrl, $postId = 0, $extraClass = '') {
    $url = trim((string)$embedUrl);
    if ($url === '' || !preg_match('#^https://#i', $url)) return '';
    $id = $postId > 0 ? 'avsf-player-' . (int)$postId : '';
    $cls = trim('avsf-video-iframe ' . (string)$extraClass);
    $idAttribute = $id !== '' ? ' id="' . esc($id) . '"' : '';
    return '<iframe' . $idAttribute . ' class="' . esc($cls) . '" src="' . esc($url) . '" '
        . 'frameborder="0" width="560" height="340" scrolling="no" '
        . 'allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>';
}
