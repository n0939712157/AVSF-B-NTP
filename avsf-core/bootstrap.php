<?php
/**
 * avsf-ready AVSF database bootstrap.
 * Credentials are loaded from config.local.php or AVSF_DB_* environment variables.
 * Never commit or distribute config.local.php.
 */

// Storage layout. These paths are independent from the name/location of the project folder.
foreach ([AVSF_UPLOAD_DIR, AVSF_CACHE_DIR, AVSF_LOG_DIR, AVSF_BACKUP_DIR, AVSF_QUARANTINE_DIR,
          AVSF_UPDATE_DIR . '/staging', AVSF_UPDATE_DIR . '/history', AVSF_UPDATE_DIR . '/rollback'] as $avsfDir) {
    if (!is_dir($avsfDir) && !is_link($avsfDir)) @mkdir($avsfDir, 0755, true);
}

$configPath = AVSF_ROOT . DIRECTORY_SEPARATOR . 'config.local.php';
$dbConfig = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    if (is_array($loadedConfig)) $dbConfig = $loadedConfig;
}

$DB_HOST = (string)($dbConfig['host'] ?? getenv('AVSF_DB_HOST') ?: '');
$DB_USER = (string)($dbConfig['user'] ?? getenv('AVSF_DB_USER') ?: '');
$DB_PASS = (string)($dbConfig['password'] ?? getenv('AVSF_DB_PASSWORD') ?: '');
$DB_NAME = (string)($dbConfig['name'] ?? getenv('AVSF_DB_NAME') ?: '');
$DB_PORT = (int)($dbConfig['port'] ?? getenv('AVSF_DB_PORT') ?: 3306);

if ($DB_HOST === '' || $DB_USER === '' || $DB_NAME === '') {
    $requestPath = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($requestPath !== 'db-setup.php') {
        // Build a root-relative setup URL even when the request entered through
        // /admin-login/ or /api/. A relative Location header would point to a
        // nonexistent nested db-setup.php in those cases.
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $installRoot = realpath(AVSF_ROOT);
        $publicBasePath = '';
        if ($documentRoot !== false && $installRoot !== false) {
            $doc = rtrim(str_replace('\\', '/', $documentRoot), '/');
            $app = rtrim(str_replace('\\', '/', $installRoot), '/');
            if (strcasecmp($doc, $app) !== 0 && stripos($app, $doc . '/') === 0) {
                $publicBasePath = '/' . trim(substr($app, strlen($doc) + 1), '/');
            }
        }
        $setupUrl = rtrim($publicBasePath, '/') . '/db-setup.php';
        header('Location: ' . $setupUrl, true, 302);
        exit;
    }
    http_response_code(503);
    exit('กรุณาตั้งค่าฐานข้อมูลผ่าน db-setup.php');
}

if (!extension_loaded('mysqli')) {
    http_response_code(500);
    exit('เซิร์ฟเวอร์ยังไม่ได้เปิดใช้งาน PHP extension: mysqli');
}
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT > 0 ? $DB_PORT : 3306);
if (!$conn) {
    error_log('AVSF database connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบข้อมูลใน config.local.php หรือเปิด db-setup.php เพื่อตั้งค่าใหม่');
}
mysqli_set_charset($conn, 'utf8mb4');
require_once AVSF_CORE_DIR . '/database/auto-install.php';
avsf_auto_install($conn);

$SETTINGS = [];
$res = mysqli_query($conn, 'SELECT skey, svalue FROM settings');
if (!$res) {
    error_log('AVSF settings query failed: ' . mysqli_error($conn));
    http_response_code(500);
    die('ไม่สามารถโหลดค่าตั้งค่าของเว็บไซต์ได้');
}
while ($r = mysqli_fetch_assoc($res)) $SETTINGS[$r['skey']] = $r['svalue'];

function setting($key, $default = '') {
    global $SETTINGS;
    return isset($SETTINGS[$key]) ? $SETTINGS[$key] : $default;
}


/**
 * Shared responsive card layout settings. The selected viewport family is
 * supplied by the front-end viewport cookie; CSS and pagination use the same
 * breakpoint families so the card count stays aligned with the visible grid.
 */
function avsf_card_viewport_family() {
    $allowed = ['mobile', 'tablet_portrait', 'tablet_landscape', 'desktop'];
    $family = isset($_COOKIE['avsf_card_viewport']) ? (string)$_COOKIE['avsf_card_viewport'] : '';
    return in_array($family, $allowed, true) ? $family : 'desktop';
}

function avsf_card_columns($family = null) {
    $family = $family ?: avsf_card_viewport_family();
    $defaults = [
        'mobile' => 2,
        'tablet_portrait' => 3,
        'tablet_landscape' => 4,
        'desktop' => 4,
    ];
    if (!isset($defaults[$family])) $family = 'desktop';
    // Mobile layout is intentionally fixed at two columns across the public
    // post and taxonomy directories. Keep pagination calculations in sync
    // with the CSS grid so actor/studio/category/tag pages cannot drift to
    // one or three columns because of an older saved setting.
    if ($family === 'mobile') return 2;

    $key = 'card_columns_' . $family;
    $value = (int)setting($key, (string)$defaults[$family]);
    $max = 6;
    return max(1, min($max, $value));
}

function avsf_cards_per_page($group = 'posts', $family = null) {
    $groups = ['posts', 'studios', 'actors', 'categories', 'tags'];
    if (!in_array($group, $groups, true)) $group = 'posts';
    $family = $family ?: avsf_card_viewport_family();
    $families = ['mobile', 'tablet_portrait', 'tablet_landscape', 'desktop'];
    if (!in_array($family, $families, true)) $family = 'desktop';
    $legacyPostCount = (int)setting('postsPerPage', '12');
    $legacyPostCount = max(1, min(120, $legacyPostCount > 0 ? $legacyPostCount : 12));
    $postRowDefaults = [];
    foreach ($families as $layoutFamily) {
        $postRowDefaults[$layoutFamily] = (int)ceil($legacyPostCount / avsf_card_columns($layoutFamily));
    }
    $rowDefaults = [
        'posts' => $postRowDefaults,
        'studios' => ['mobile'=>5, 'tablet_portrait'=>4, 'tablet_landscape'=>3, 'desktop'=>3],
        'actors' => ['mobile'=>5, 'tablet_portrait'=>4, 'tablet_landscape'=>3, 'desktop'=>3],
        'categories' => ['mobile'=>5, 'tablet_portrait'=>4, 'tablet_landscape'=>3, 'desktop'=>3],
        'tags' => ['mobile'=>5, 'tablet_portrait'=>4, 'tablet_landscape'=>3, 'desktop'=>3],
    ];
    $rows = (int)setting('card_rows_' . $group . '_' . $family, (string)$rowDefaults[$group][$family]);
    $rows = max(1, min(20, $rows));
    return max(1, min(120, $rows * avsf_card_columns($family)));
}

/**
 * Return the canonical public URL of this installation (without a trailing slash).
 * The explicitly configured siteUrl is authoritative. When it is blank, fall
 * back to the server name and the current script directory; never trust the
 * request Host header when generating canonical URLs or XML feeds.
 */
function avsfCanonicalBaseUrl($scriptName = null) {
    $configured = trim((string)getenv('AVSF_SITE_URL'));
    if ($configured === '') $configured = trim((string)setting('siteUrl', ''));
    if ($configured === '') $configured = trim((string)setting('site_url', ''));
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (is_array($parts) && isset($parts['scheme'], $parts['host']) &&
            in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true) &&
            !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) &&
            !preg_match('/[\x00-\x20<>\\\\]/', $configured) &&
            !preg_match('~(?:^|/)\.\.(?:/|$)~', (string)($parts['path'] ?? ''))) {
            $host = (string)$parts['host'];
            $hostValid = filter_var($host, FILTER_VALIDATE_IP) !== false ||
                (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\.?$/i', $host);
            $port = isset($parts['port']) ? (int)$parts['port'] : null;
            if ($hostValid && ($port === null || ($port >= 1 && $port <= 65535))) {
                $authority = (strpos($host, ':') !== false && $host[0] !== '[') ? '[' . $host . ']' : $host;
                if ($port !== null) $authority .= ':' . $port;
                $path = rtrim((string)($parts['path'] ?? ''), '/');
                return strtolower((string)$parts['scheme']) . '://' . $authority . $path;
            }
        }
    }

    $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '' || (filter_var($host, FILTER_VALIDATE_IP) === false &&
        !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\.?$/i', $host))) {
        $host = 'localhost';
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $host = '[' . $host . ']';
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $isHttps ? 'https' : 'http';
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $portSuffix = ($port > 0 && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) ? ':' . $port : '';
    // Resolve public subdirectory from the actual install path, not the current controller path.
    $basePath = '';
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $installRoot = realpath(AVSF_ROOT);
    if ($documentRoot !== false && $installRoot !== false) {
        $doc = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $app = rtrim(str_replace('\\', '/', $installRoot), '/');
        $prefix = $doc === '' ? '/' : $doc . '/';
        if (strcasecmp($app, $doc) === 0) {
            $basePath = '';
        } elseif (stripos($app, $prefix) === 0) {
            $relative = substr($app, strlen($prefix));
            $basePath = $relative !== '' ? '/' . trim($relative, '/') : '';
        }
    }
    // Fallback for unusual Hosting layouts where DocumentRoot is unavailable.
    if ($documentRoot === false || $installRoot === false) {
        $scriptName = $scriptName !== null ? (string)$scriptName : (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $scriptName = str_replace('\\', '/', $scriptName);
        $scriptName = preg_replace('~/(?:avsf-admin|avsf-core|avsf-frontend|avsf-installer)(?:/.*)?$~i', '', $scriptName);
        $basePath = '/' . trim(dirname($scriptName), '/.');
        if ($basePath === '/.' || $basePath === '/') $basePath = '';
    }
    return $scheme . '://' . $host . $portSuffix . $basePath;
}

function avsfCanonicalOrigin() {
    $parts = parse_url(avsfCanonicalBaseUrl());
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return 'http://localhost';
    $host = (string)$parts['host'];
    if (strpos($host, ':') !== false && $host[0] !== '[') $host = '[' . $host . ']';
    return strtolower((string)$parts['scheme']) . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
}

function avsfCanonicalBasePath() {
    $parts = parse_url(avsfCanonicalBaseUrl());
    return is_array($parts) ? rtrim((string)($parts['path'] ?? ''), '/') : '';
}
function saveSetting($key, $value) {
    global $conn, $SETTINGS;
    $k = mysqli_real_escape_string($conn, (string)$key);
    $v = mysqli_real_escape_string($conn, (string)$value);
    if (!mysqli_query($conn, "INSERT INTO settings (skey,svalue) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE svalue='$v'")) return false;
    $SETTINGS[$key] = $value;
    return true;
}
function logActivity($action) {
    global $conn;
    $a = mysqli_real_escape_string($conn, (string)$action);
    @mysqli_query($conn, "INSERT INTO activity (action,time) VALUES ('$a',NOW())");
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function isAdmin() {
    if (empty($_SESSION['avsf_admin'])) return false;

    $adminUserId = (int)($_SESSION['avsf_admin_id'] ?? 0);
    $verifiedUserId = (int)($_SESSION['avsf_2fa_verified_user_id'] ?? 0);
    $verifiedAt = (int)($_SESSION['avsf_2fa_verified_at'] ?? 0);
    $method = (string)($_SESSION['avsf_2fa_auth_method'] ?? '');

    // Require a fresh, account-bound TOTP proof for every backend session.
    // This also invalidates pre-patch sessions that never completed 2FA.
    if ($adminUserId > 0 && $verifiedUserId === $adminUserId && $verifiedAt > 0 && $method === 'totp') {
        return true;
    }

    unset(
        $_SESSION['avsf_admin'], $_SESSION['avsf_admin_id'], $_SESSION['avsf_admin_username'],
        $_SESSION['avsf_2fa_verified_user_id'], $_SESSION['avsf_2fa_verified_at'],
        $_SESSION['avsf_2fa_auth_method'], $_SESSION['user_role'], $_SESSION['user_permissions']
    );
    return false;
}
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'setup.php') {
    $userCountRes = @mysqli_query($conn, 'SELECT COUNT(*) FROM users');
    if ($userCountRes && (int)mysqli_fetch_row($userCountRes)[0] === 0) {
        header('Location: setup.php');
        exit;
    }
}

function avsfClass($value) {
    if (!is_string($value) && !is_numeric($value)) return '';
    $tokens = preg_split('/\\s+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
    $safe = [];
    foreach ($tokens as $token) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $token)) continue;
        $safe[] = strpos($token, 'avsf-') === 0 ? $token : 'avsf-' . $token;
    }
    return implode(' ', $safe);
}

// i18n layer (v3.1.0): TH/EN translation helpers. Loaded after session_start()
// so locale can be read from cookie/session. Safe to require on every entry point.
require_once AVSF_CORE_DIR . DIRECTORY_SEPARATOR . 'i18n.php';
