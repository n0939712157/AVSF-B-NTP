<?php
/**
 * AVSF — TOTP (Google Authenticator / Authy) Library
 * RFC 6238 — ไม่ต้องติดตั้ง library เพิ่ม
 * ใช้: require_once AVSF_CORE_DIR . '/auth/totp.php';
 */
if (!defined('TOTP_LIB')) {
define('TOTP_LIB', true);

class AVSFTOTP {
    // สร้าง secret key ใหม่ (16 ตัวอักษร base32)
    public static function generateSecret($length = 16) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32 alphabet
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    // สร้าง otpauth URI มาตรฐานสำหรับสร้าง QR ภายในเบราว์เซอร์
    // ห้ามส่ง Secret ไปยังบริการสร้าง QR ภายนอก
    public static function getOTPAuthUri($secret, $username, $issuer = 'AVSF') {
        $label = rawurlencode($issuer . ':' . $username);
        $params = http_build_query([
            'secret' => (string)$secret,
            'issuer' => (string)$issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $params;
    }

    /**
     * Backward-compatible alias. Returns an otpauth URI for local QR rendering;
     * it never calls a remote QR service or transmits the Secret.
     * @deprecated Use getOTPAuthUri() directly.
     */
    public static function getQRCodeUrl($secret, $username, $issuer = 'AVSF') {
        return self::getOTPAuthUri($secret, $username, $issuer);
    }

    // ตรวจสอบโค้ด 6 หลัก (window = 1 = ยอมรับ +-30 วินาที)
    public static function verifyCode($secret, $code, $window = 1) {
        $code = preg_replace('/[^0-9]/', '', (string)$code);
        if (strlen($code) !== 6) return false;
        $secret = self::base32Decode($secret);
        if ($secret === false) return false;
        $timeSlice = floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::generateTOTP($secret, $timeSlice + $i);
            if (hash_equals($expected, $code)) return true;
        }
        return false;
    }

    // สร้างโค้ด TOTP ที่ time slice หนึ่ง
    private static function generateTOTP($secret, $timeSlice) {
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);
        $otp = $binary % 1000000;
        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    // decode base32 (ไม่ต้องมี extension)
    private static function base32Decode($secret) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        if ($secret === '') return false;
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}

} // TOTP_LIB

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'totp.php') {
    http_response_code(403); exit;
}
