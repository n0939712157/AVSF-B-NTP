<?php
/**
 * AVSF Site Center — read-only live observability snapshot.
 * This endpoint reports only values the current PHP/web application can read.
 * It does not run scans, backups, imports, writes, or external network probes.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
requireSiteCenterAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$conn = $GLOBALS['conn'] ?? ($GLOBALS['mysqli'] ?? null);
$root = defined('AVSF_ROOT') ? AVSF_ROOT : dirname(__DIR__, 2);
$groups = [];
$metrics = [];
$recent = [];

function avsfObsAdd(&$groups, $groupId, $groupTitle, $label, $status, $detail, $value = '') {
    $allowed = ['ok', 'warning', 'error', 'info'];
    if (!in_array($status, $allowed, true)) $status = 'info';
    if (!isset($groups[$groupId])) {
        $groups[$groupId] = ['id' => $groupId, 'title' => $groupTitle, 'items' => []];
    }
    $groups[$groupId]['items'][] = [
        'label' => (string)$label,
        'status' => $status,
        'detail' => (string)$detail,
        'value' => (string)$value,
    ];
}
function avsfObsGroupStatus($items) {
    $hasOk = false;
    $hasWarning = false;
    foreach ((array)$items as $item) {
        $itemStatus = (string)($item['status'] ?? 'info');
        if ($itemStatus === 'error') return 'error';
        if ($itemStatus === 'warning') $hasWarning = true;
        if ($itemStatus === 'ok') $hasOk = true;
    }
    if ($hasWarning) return 'warning';
    // Do not show a group as passed when every check is unknown/unavailable.
    return $hasOk ? 'ok' : 'info';
}
function avsfObsBytes($bytes) {
    if (!is_numeric($bytes)) return 'อ่านไม่ได้';
    $bytes = max(0, (float)$bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    while ($bytes >= 1024 && $unit < count($units) - 1) { $bytes /= 1024; $unit++; }
    return number_format($bytes, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}
function avsfObsIniBytes($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '-1') return PHP_INT_MAX;
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMG]?)B?$/i', $value, $m)) return null;
    $n = (float)$m[1];
    $unit = strtoupper($m[2] ?? '');
    if ($unit === 'K') $n *= 1024;
    elseif ($unit === 'M') $n *= 1048576;
    elseif ($unit === 'G') $n *= 1073741824;
    return $n > PHP_INT_MAX ? PHP_INT_MAX : (int)$n;
}
function avsfObsQueryRow($conn, $sql) {
    if (!$conn || !is_object($conn) || !method_exists($conn, 'query')) return null;
    $result = @$conn->query($sql);
    if (!$result) return null;
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : [];
}
function avsfObsQueryValue($conn, $sql, $key = 'value') {
    $row = avsfObsQueryRow($conn, $sql);
    return is_array($row) && array_key_exists($key, $row) ? $row[$key] : null;
}
function avsfObsTableList($conn) {
    $names = [];
    if (!$conn || !is_object($conn) || !method_exists($conn, 'query')) return $names;
    $res = @$conn->query('SHOW FULL TABLES');
    if (!$res) return $names;
    while ($row = $res->fetch_row()) {
        if (isset($row[0])) $names[(string)$row[0]] = true;
    }
    $res->free();
    return $names;
}
function avsfObsDir($path) {
    return is_string($path) && $path !== '' && is_dir($path) && !is_link($path);
}

// --- Application / PHP runtime ---
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
$canonical = function_exists('avsfCanonicalBaseUrl') ? avsfCanonicalBaseUrl() : '';
$canonicalHttps = stripos((string)$canonical, 'https://') === 0;
// If the canonical site is HTTPS but PHP does not expose the original scheme,
// mark transport as unverifiable (reverse proxy / SSL offload may be in use).
// Do not trust arbitrary X-Forwarded-Proto headers because they are client-spoofable.
$httpsStatus = $https ? 'ok' : ($canonicalHttps ? 'info' : 'warning');
avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'HTTPS ของ request ปัจจุบัน', $httpsStatus,
    $https ? 'PHP ยืนยัน request นี้ผ่าน HTTPS' : ($canonicalHttps ? 'URL หลักกำหนดเป็น HTTPS แต่ PHP ไม่เห็น HTTPS flag/port 443; อาจมี reverse proxy หรือ SSL offload จึงยังยืนยัน request นี้ไม่ได้' : 'request นี้ไม่ยืนยัน HTTPS และ URL หลักไม่ได้กำหนดเป็น HTTPS'),
    $https ? 'ON' : ($canonicalHttps ? 'ยืนยันไม่ได้' : 'OFF'));

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'PHP ' . PHP_VERSION, $phpOk ? 'ok' : 'error',
    $phpOk ? 'ผ่านเกณฑ์ขั้นต่ำที่ Security Center ใช้ตรวจ (PHP 8.0+)' : 'ต่ำกว่า PHP 8.0 ซึ่ง AVSF ตั้งเป็นเกณฑ์ขั้นต่ำ', PHP_VERSION);

$memoryPeak = memory_get_peak_usage(true);
$memoryLimit = (string)ini_get('memory_limit');
$memoryLimitBytes = avsfObsIniBytes($memoryLimit);
$memoryValue = avsfObsBytes($memoryPeak) . ' / ' . ($memoryLimit !== '' ? $memoryLimit : 'ไม่จำกัด');
$memoryStatus = 'info';
$memoryDetail = 'Peak memory ของ PHP request นี้เท่านั้น ไม่ใช่ RAM ทั้งเครื่อง';
if ($memoryLimitBytes && $memoryLimitBytes !== PHP_INT_MAX && $memoryLimitBytes > 0) {
    $memoryPct = ($memoryPeak / $memoryLimitBytes) * 100;
    if ($memoryPct >= 90) $memoryStatus = 'warning';
    $memoryDetail .= ' · ใช้ประมาณ ' . number_format($memoryPct, 1) . '% ของ memory_limit';
}
avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'หน่วยความจำ PHP request', $memoryStatus, $memoryDetail, $memoryValue);

$phpLimits = [
    ['upload_max_filesize', 'ขนาดอัปโหลดสูงสุด', (string)ini_get('upload_max_filesize')],
    ['post_max_size', 'ขนาด POST สูงสุด', (string)ini_get('post_max_size')],
    ['max_execution_time', 'เวลา PHP ต่อ request', (string)ini_get('max_execution_time') . ' วินาที'],
    ['max_input_time', 'เวลารับ input', (string)ini_get('max_input_time') . ' วินาที'],
    ['max_file_uploads', 'จำนวนไฟล์สูงสุดต่อ request', (string)ini_get('max_file_uploads') . ' ไฟล์'],
];
foreach ($phpLimits as $limit) {
    avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', $limit[1], 'info', 'ค่าจาก PHP runtime ปัจจุบัน', $limit[2] !== ' วินาที' ? $limit[2] : 'ไม่ระบุ');
}
$uploadBytes = avsfObsIniBytes(ini_get('upload_max_filesize'));
$postBytes = avsfObsIniBytes(ini_get('post_max_size'));
if (is_int($uploadBytes) && is_int($postBytes) && $postBytes < $uploadBytes) {
    avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'ความสัมพันธ์ Upload / POST', 'warning',
        'post_max_size ต่ำกว่า upload_max_filesize ทำให้ไฟล์ขนาดใหญ่บางรายการถูกปฏิเสธก่อนเข้าระบบ');
} else {
    avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'ความสัมพันธ์ Upload / POST', 'ok',
        'ตรวจค่าขีดจำกัด Upload และ POST แล้ว');
}

$requiredExtensions = ['mysqli', 'json', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'PHP Extension ' . $ext, $loaded ? 'ok' : 'error',
        $loaded ? 'Extension พร้อมใช้งาน' : 'ไม่พบ Extension ที่ระบบหลักต้องใช้', $loaded ? 'ON' : 'OFF');
}
foreach (['zip', 'simplexml'] as $ext) {
    $loaded = extension_loaded($ext);
    $status = $loaded ? 'ok' : 'warning';
    $feature = $ext === 'zip' ? 'ZIP Backup/Restore, ZIP Import และ XLSX' : 'การอ่านไฟล์ XLSX';
    avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'PHP Extension ' . $ext, $status,
        $loaded ? 'พร้อมใช้งานสำหรับ ' . $feature : 'ไม่พบ Extension นี้; ความสามารถ ' . $feature . ' จะใช้งานไม่ได้');
}
foreach (['openssl', 'fileinfo', 'curl', 'gd'] as $ext) {
    $loaded = extension_loaded($ext);
    avsfObsAdd($groups, 'application', 'แอปพลิเคชันและ PHP', 'PHP Extension ' . $ext, $loaded ? 'ok' : 'info',
        $loaded ? 'พร้อมใช้งาน' : 'ไม่พบ Extension นี้ใน runtime; เฉพาะความสามารถที่เกี่ยวข้องอาจมีข้อจำกัด', $loaded ? 'ON' : 'OFF');
}

// --- Database: live ping, latency, schema, size ---
$dbStart = microtime(true);
$dbReady = false;
if ($conn && is_object($conn) && method_exists($conn, 'query')) {
    $ping = false;
    try { $ping = @$conn->ping(); } catch (Throwable $e) { $ping = false; }
    $probe = $ping ? avsfObsQueryValue($conn, 'SELECT 1 AS value') : null;
    $dbReady = $ping && (string)$probe === '1';
}
$dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 1);
avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'การเชื่อมต่อ MySQL', $dbReady ? 'ok' : 'error',
    $dbReady ? 'PING และ SELECT 1 สำเร็จ' : 'เชื่อมต่อหรือทดสอบ query ไม่สำเร็จ', $dbReady ? 'ONLINE' : 'OFFLINE');
if ($dbReady) {
    $latStatus = $dbLatencyMs >= 1000 ? 'warning' : 'ok';
    avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'เวลาตอบสนอง DB', $latStatus,
        'เวลาที่ใช้กับการเชื่อมต่อและ query ทดสอบใน request นี้', $dbLatencyMs . ' ms');
}

$tableNames = avsfObsTableList($dbReady ? $conn : null);
$requiredTables = ['settings','posts','pages','users','categories','studios','actors','tags','comments','redirects','seo_index_overrides'];
$missingCoreTables = [];
foreach ($requiredTables as $table) if (!isset($tableNames[$table])) $missingCoreTables[] = $table;
if (!$dbReady) {
    avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'ตารางหลักของ AVSF', 'info', 'ยังตรวจโครงสร้างไม่ได้เพราะฐานข้อมูลไม่พร้อม');
} elseif ($missingCoreTables) {
    avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'ตารางหลักของ AVSF', 'error',
        'ไม่พบตารางหลัก: ' . implode(', ', $missingCoreTables));
} else {
    avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'ตารางหลักของ AVSF', 'ok',
        'พบตารางหลักครบ ' . count($requiredTables) . ' ตาราง', count($tableNames) . ' tables');
}
$optionalTables = ['login_attempts','404_log','server_error_log','cache_stats','recent_page_views','activity','import_history','security_scan_history'];
$missingOptionalTables = [];
foreach ($optionalTables as $table) if ($dbReady && !isset($tableNames[$table])) $missingOptionalTables[] = $table;
if ($dbReady) {
    avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'ตาราง Log / Monitoring', $missingOptionalTables ? 'warning' : 'ok',
        $missingOptionalTables ? 'ไม่พบตารางเสริม: ' . implode(', ', $missingOptionalTables) : 'พบตารางสำหรับ Log และสถิติครบ');
}
$dbSizeMb = null;
if ($dbReady) {
    $dbName = (string)(avsfObsQueryValue($conn, 'SELECT DATABASE() AS value') ?? '');
    if ($dbName !== '') {
        $stmt = @$conn->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?');
        if ($stmt) {
            $stmt->bind_param('s', $dbName);
            if (@$stmt->execute()) {
                $stmt->bind_result($dbBytes);
                if ($stmt->fetch()) $dbSizeMb = round((int)$dbBytes / 1048576, 2);
            }
            $stmt->close();
        }
    }
}
avsfObsAdd($groups, 'database', 'ฐานข้อมูลและโครงสร้าง', 'ขนาดฐานข้อมูล', $dbSizeMb === null ? 'info' : 'ok',
    $dbSizeMb === null ? 'information_schema ไม่อนุญาตหรืออ่านขนาดไม่ได้' : 'รวม data_length + index_length จาก information_schema',
    $dbSizeMb === null ? 'อ่านไม่ได้' : number_format($dbSizeMb, 2) . ' MB');

// --- Content and user inventory ---
$contentMetrics = ['posts'=>null,'published'=>null,'drafts'=>null,'views'=>null,'pages'=>null,'users'=>null,'comments'=>null,'pending_comments'=>null];
if ($dbReady && isset($tableNames['posts'])) {
    $r = avsfObsQueryRow($conn, "SELECT COUNT(*) total, SUM(status='publish') published, SUM(status='draft') drafts, SUM(status='pending') pending, COALESCE(SUM(views),0) views FROM posts");
    if ($r) {
        $contentMetrics['posts'] = (int)$r['total'];
        $contentMetrics['published'] = (int)$r['published'];
        $contentMetrics['drafts'] = (int)$r['drafts'];
        $contentMetrics['views'] = (int)$r['views'];
    }
}
if ($dbReady && isset($tableNames['pages'])) $contentMetrics['pages'] = (int)(avsfObsQueryValue($conn, 'SELECT COUNT(*) AS value FROM pages') ?? 0);
if ($dbReady && isset($tableNames['users'])) $contentMetrics['users'] = (int)(avsfObsQueryValue($conn, 'SELECT COUNT(*) AS value FROM users') ?? 0);
if ($dbReady && isset($tableNames['comments'])) {
    $r = avsfObsQueryRow($conn, 'SELECT COUNT(*) total, SUM(approved=0) pending FROM comments');
    if ($r) { $contentMetrics['comments'] = (int)$r['total']; $contentMetrics['pending_comments'] = (int)$r['pending']; }
}
$taxonomy = [];
foreach (['categories'=>'หมวดหมู่','studios'=>'ค่าย','actors'=>'นักแสดง','tags'=>'แท็ก'] as $table=>$label) {
    $taxonomy[$table] = ($dbReady && isset($tableNames[$table])) ? (int)(avsfObsQueryValue($conn, 'SELECT COUNT(*) AS value FROM `' . $table . '`') ?? 0) : null;
}
$contentDetail = 'โพสต์ ' . ($contentMetrics['posts'] === null ? 'อ่านไม่ได้' : number_format($contentMetrics['posts']))
    . ' · เผยแพร่ ' . ($contentMetrics['published'] === null ? '—' : number_format($contentMetrics['published']))
    . ' · ฉบับร่าง ' . ($contentMetrics['drafts'] === null ? '—' : number_format($contentMetrics['drafts']));
avsfObsAdd($groups, 'content', 'เนื้อหาและบัญชี', 'คลังโพสต์', $contentMetrics['posts'] === null ? 'warning' : 'ok', $contentDetail);
avsfObsAdd($groups, 'content', 'เนื้อหาและบัญชี', 'หน้าเว็บ', $contentMetrics['pages'] === null ? 'info' : 'ok',
    $contentMetrics['pages'] === null ? 'อ่านจำนวนหน้าเว็บไม่ได้' : 'จำนวนหน้าเว็บทั้งหมด', $contentMetrics['pages'] === null ? '—' : number_format($contentMetrics['pages']));
foreach (['categories'=>'หมวดหมู่','studios'=>'ค่าย','actors'=>'นักแสดง','tags'=>'แท็ก'] as $table=>$label) {
    avsfObsAdd($groups, 'content', 'เนื้อหาและบัญชี', 'รายการ' . $label, $taxonomy[$table] === null ? 'info' : 'ok',
        $taxonomy[$table] === null ? 'อ่านจำนวนไม่ได้' : 'จำนวนรายการในตารางหลัก', $taxonomy[$table] === null ? '—' : number_format($taxonomy[$table]));
}
avsfObsAdd($groups, 'content', 'เนื้อหาและบัญชี', 'คอมเมนต์รออนุมัติ', $contentMetrics['pending_comments'] === null ? 'info' : ($contentMetrics['pending_comments'] > 100 ? 'warning' : 'ok'),
    $contentMetrics['pending_comments'] === null ? 'อ่านจำนวนไม่ได้' : 'ตรวจจาก comments.approved = 0', $contentMetrics['pending_comments'] === null ? '—' : number_format($contentMetrics['pending_comments']));

// --- Security posture ---
$displayErrors = strtolower(trim((string)ini_get('display_errors')));
$displayErrorsOff = in_array($displayErrors, ['', '0', 'off', 'false', 'no'], true);
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'PHP display_errors', $displayErrorsOff ? 'ok' : 'warning',
    $displayErrorsOff ? 'ปิดการแสดง error ออกหน้าเว็บ' : 'เปิด display_errors อาจเปิดเผยรายละเอียดภายในระบบ', $displayErrorsOff ? 'OFF' : 'ON');
$rootHtaccess = @file_get_contents($root . '/.htaccess');
$rootConfigProtected = is_string($rootHtaccess) && strpos($rootHtaccess, 'Require all denied') !== false && preg_match('/config/i', $rootHtaccess);
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'กฎป้องกันไฟล์ตั้งค่า Root', $rootConfigProtected ? 'ok' : 'warning',
    $rootConfigProtected ? 'พบกฎป้องกันไฟล์ config ใน .htaccess' : 'ตรวจไม่พบกฎป้องกัน config อย่างชัดเจน');
$uploadsHt = defined('AVSF_UPLOAD_DIR') ? AVSF_UPLOAD_DIR . DIRECTORY_SEPARATOR . '.htaccess' : '';
$cacheHt = defined('AVSF_CACHE_DIR') ? AVSF_CACHE_DIR . DIRECTORY_SEPARATOR . '.htaccess' : '';
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'กฎป้องกัน uploads', is_file($uploadsHt) && is_readable($uploadsHt) ? 'ok' : 'warning',
    is_file($uploadsHt) && is_readable($uploadsHt) ? 'พบ uploads/.htaccess' : 'ไม่พบหรืออ่าน uploads/.htaccess ไม่ได้');
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'กฎป้องกัน cache', is_file($cacheHt) && is_readable($cacheHt) ? 'ok' : 'warning',
    is_file($cacheHt) && is_readable($cacheHt) ? 'พบ cache/.htaccess' : 'ไม่พบหรืออ่าน cache/.htaccess ไม่ได้');
$cookieParams = session_get_cookie_params();
$cookieHttpOnly = !empty($cookieParams['httponly']);
$cookieSecure = !empty($cookieParams['secure']);
$cookieSameSite = (string)($cookieParams['samesite'] ?? '');
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'Session Cookie HttpOnly', $cookieHttpOnly ? 'ok' : 'warning',
    $cookieHttpOnly ? 'เปิด HttpOnly ลดการอ่าน session cookie ผ่าน JavaScript' : 'Session cookie ยังไม่ได้เปิด HttpOnly');
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'Session Cookie Secure / SameSite', ($https && !$cookieSecure) ? 'warning' : 'info',
    'Secure ' . ($cookieSecure ? 'ON' : 'OFF') . ' · SameSite ' . ($cookieSameSite !== '' ? $cookieSameSite : 'ไม่ระบุ') . ' · ตรวจจาก cookie parameters ของ PHP session');

$currentAdminId = (int)($_SESSION['avsf_admin_id'] ?? 0);
$currentVerified = $currentAdminId > 0
    && (int)($_SESSION['avsf_2fa_verified_user_id'] ?? 0) === $currentAdminId
    && (int)($_SESSION['avsf_2fa_verified_at'] ?? 0) > 0
    && (string)($_SESSION['avsf_2fa_auth_method'] ?? '') === 'totp';
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', '2FA ของเซสชันผู้ดูแลปัจจุบัน', $currentVerified ? 'ok' : 'error',
    $currentVerified ? 'เซสชันนี้ผ่านการยืนยัน TOTP แล้ว' : 'ไม่พบหลักฐาน TOTP ที่ผูกกับบัญชีผู้ดูแลปัจจุบัน');
$admin2fa = null;
if ($dbReady && isset($tableNames['users'])) {
    $r = avsfObsQueryRow($conn, "SELECT COUNT(*) total, SUM(twofa_enabled=1 AND twofa_secret<>'') enabled FROM users WHERE role IN ('owner','super_admin','deputy_admin','editor','moderator','author','custom')");
    if ($r) $admin2fa = ['total'=>(int)$r['total'], 'enabled'=>(int)$r['enabled']];
}
if ($admin2fa !== null) {
    $coverage = $admin2fa['total'] > 0 ? round(($admin2fa['enabled'] / $admin2fa['total']) * 100, 1) : 100;
    $twofaStatus = $admin2fa['enabled'] < $admin2fa['total'] ? 'warning' : 'ok';
    avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'การลงทะเบียน 2FA ของบัญชีหลังบ้าน', $twofaStatus,
        'ตรวจจาก twofa_enabled และ twofa_secret ของบัญชีแอดมิน', $admin2fa['enabled'] . '/' . $admin2fa['total'] . ' (' . $coverage . '%)');
}
$failed24 = null;
if ($dbReady && isset($tableNames['login_attempts'])) {
    $failed24 = (int)(avsfObsQueryValue($conn, 'SELECT COUNT(*) AS value FROM login_attempts WHERE success=0 AND attempted_at >= NOW() - INTERVAL 24 HOUR') ?? 0);
    $failedStatus = $failed24 >= 50 ? 'error' : ($failed24 >= 10 ? 'warning' : 'ok');
    avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'การเข้าสู่ระบบไม่สำเร็จใน 24 ชั่วโมง', $failedStatus,
        'นับรายการจาก login_attempts.success = 0; เป็นสัญญาณให้ตรวจสอบ ไม่ใช่การยืนยันว่าเป็นการโจมตี', number_format($failed24) . ' ครั้ง');
} else {
    avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'บันทึกการเข้าสู่ระบบ', 'info', 'ไม่พบตาราง login_attempts หรืออ่านไม่ได้');
}

// --- Storage / filesystem / cache ---
$diskTotal = function_exists('disk_total_space') ? @disk_total_space($root) : false;
$diskFree = function_exists('disk_free_space') ? @disk_free_space($root) : false;
$diskUsedPercent = null;
if (is_numeric($diskTotal) && $diskTotal > 0 && is_numeric($diskFree) && $diskFree >= 0) {
    $diskUsedPercent = round((1 - ($diskFree / $diskTotal)) * 100, 1);
    if ($diskUsedPercent < 0) $diskUsedPercent = 0;
}
$diskStatus = $diskUsedPercent === null ? 'info' : ($diskUsedPercent >= 97 ? 'error' : ($diskUsedPercent >= 85 ? 'warning' : 'ok'));
$diskValue = $diskUsedPercent === null ? 'อ่านไม่ได้' : $diskUsedPercent . '% ใช้ไป';
$diskDetail = $diskUsedPercent === null ? 'Hosting/PHP ไม่อนุญาตให้อ่านพื้นที่ filesystem' : 'ใช้ไป ' . avsfObsBytes($diskTotal - $diskFree) . ' จาก ' . avsfObsBytes($diskTotal) . ' · เหลือ ' . avsfObsBytes($diskFree);
avsfObsAdd($groups, 'storage', 'พื้นที่จัดเก็บและแคช', 'Disk filesystem', $diskStatus, $diskDetail, $diskValue);

$uploadDir = defined('AVSF_UPLOAD_DIR') ? AVSF_UPLOAD_DIR : '';
$uploadExists = avsfObsDir($uploadDir);
$uploadWritable = $uploadExists && is_writable($uploadDir);
avsfObsAdd($groups, 'storage', 'พื้นที่จัดเก็บและแคช', 'พื้นที่ uploads', !$uploadExists ? 'error' : ($uploadWritable ? 'ok' : 'error'),
    !$uploadExists ? 'ไม่พบโฟลเดอร์ uploads ที่กำหนดใน AVSF' : ($uploadWritable ? 'โฟลเดอร์มีอยู่และ PHP เขียนได้' : 'โฟลเดอร์มีอยู่แต่ PHP เขียนไม่ได้'),
    $uploadWritable ? 'พร้อม' : 'ไม่พร้อม');

$cacheDir = defined('AVSF_CACHE_DIR') ? AVSF_CACHE_DIR : '';
$cacheExists = avsfObsDir($cacheDir);
$cacheEnabled = (string)setting('cache_enabled', '0') === '1';
$cacheWritable = $cacheExists && is_writable($cacheDir);
$cacheStatus = !$cacheEnabled ? 'info' : (!$cacheExists || !$cacheWritable ? 'warning' : 'ok');
avsfObsAdd($groups, 'storage', 'พื้นที่จัดเก็บและแคช', 'ระบบ Cache', $cacheStatus,
    'ตั้งค่า ' . ($cacheEnabled ? 'เปิด' : 'ปิด') . ' · TTL ' . (int)setting('cache_ttl', '600') . ' วินาที' . ($cacheExists ? ($cacheWritable ? ' · โฟลเดอร์เขียนได้' : ' · โฟลเดอร์เขียนไม่ได้') : ' · ยังไม่มีโฟลเดอร์'),
    $cacheEnabled ? 'ON' : 'OFF');

$backupDir = '';
$rootReal = realpath($root);
$docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
$parent = $docRoot ? realpath(dirname($docRoot)) : false;
if ($parent === false || !is_dir($parent) || !is_writable($parent)) $parent = realpath(sys_get_temp_dir());
if ($parent && $rootReal) {
    $docPrefix = $docRoot ? rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
    if ($docRoot && ($parent === $docRoot || strpos($parent . DIRECTORY_SEPARATOR, $docPrefix) === 0)) $parent = realpath(sys_get_temp_dir());
    if ($parent) $backupDir = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.avsf-private-backups-' . substr(hash('sha256', $rootReal), 0, 12);
}
$backupDirExists = $backupDir !== '' && is_dir($backupDir) && !is_link($backupDir);
$backupWritable = $backupDirExists && is_writable($backupDir);
$backupFiles = $backupDirExists ? (glob($backupDir . DIRECTORY_SEPARATOR . 'avsf-backup-*.zip') ?: []) : [];
$backupCount = 0; $latestBackup = null; $backupTotalBytes = 0;
foreach ($backupFiles as $file) {
    if (is_link($file) || !is_file($file)) continue;
    $backupCount++; $backupTotalBytes += (int)@filesize($file);
    $mtime = (int)@filemtime($file);
    if ($latestBackup === null || $mtime > $latestBackup['mtime']) $latestBackup = ['mtime'=>$mtime,'size'=>(int)@filesize($file)];
}
$backupStatus = !$backupDirExists ? 'info' : ($backupWritable ? 'ok' : 'warning');
$backupDetail = !$backupDirExists ? 'ยังไม่พบพื้นที่ Backup ส่วนตัวภายนอกเว็บ; ระบบจะสร้างเมื่อเริ่มงาน Backup' :
    ($backupWritable ? 'พื้นที่ Backup อยู่ภายนอกเว็บและเขียนได้' : 'พบพื้นที่ Backup แต่เขียนไม่ได้');
if ($latestBackup) $backupDetail .= ' · ล่าสุด ' . date('d/m/Y H:i', $latestBackup['mtime']) . ' · ' . avsfObsBytes($latestBackup['size']) . ' · ตรวจชื่อ/เวลา/ขนาดเท่านั้น; ตรวจ Manifest/Hash เต็มใน Backup Center';
else $backupDetail .= ' · ยังไม่มีไฟล์ Backup ที่บันทึกไว้';
avsfObsAdd($groups, 'storage', 'พื้นที่จัดเก็บและแคช', 'พื้นที่ Backup ส่วนตัว', $backupStatus, $backupDetail, $backupCount . ' ไฟล์');

$quarantineCount = 0;
$qDir = defined('AVSF_QUARANTINE_DIR') ? AVSF_QUARANTINE_DIR : '';
if (avsfObsDir($qDir)) {
    foreach (glob(rtrim($qDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [] as $qFile) {
        $name = basename($qFile);
        if (is_file($qFile) && !is_link($qFile) && !in_array($name, ['index.html','.htaccess'], true) && substr($name, -5) !== '.meta' && is_file($qFile . '.meta') && !is_link($qFile . '.meta')) $quarantineCount++;
    }
}
avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'ไฟล์ใน Quarantine', $quarantineCount > 0 ? 'warning' : 'info',
    $quarantineCount > 0 ? 'มีไฟล์ที่ถูกกักกันและมี metadata รอตรวจทาน; dashboard ไม่ได้สแกนมัลแวร์อัตโนมัติ' : 'ไม่พบรายการ Quarantine ที่มี metadata', number_format($quarantineCount) . ' ไฟล์');
$latestScan = null;
if ($dbReady && isset($tableNames['security_scan_history'])) {
    $latestScan = avsfObsQueryRow($conn, 'SELECT scan_type,scanned_files,infected_files,error_count,limit_reached,result_status,finished_at,summary_text FROM security_scan_history ORDER BY id DESC LIMIT 1');
}
if ($latestScan) {
    $scanStatus = in_array((string)$latestScan['result_status'], ['ok','warning','error'], true) ? (string)$latestScan['result_status'] : 'info';
    $scanDetail = (string)$latestScan['summary_text'];
    if ($scanDetail === '') $scanDetail = 'สแกน ' . (string)$latestScan['scan_type'];
    $scanDetail .= ' · ล่าสุด ' . date('d/m/Y H:i', strtotime((string)$latestScan['finished_at']));
    if ((int)$latestScan['error_count'] > 0 || !empty($latestScan['limit_reached'])) $scanDetail .= ' · พบไฟล์อ่านไม่สำเร็จหรือชนขีดจำกัด';
    avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'ประวัติ Malware Scan', $scanStatus, $scanDetail,
        ($latestScan['scanned_files'] === null ? 'ไม่ระบุจำนวน' : number_format((int)$latestScan['scanned_files']) . ' ไฟล์') . ' · ต้องสงสัย ' . number_format((int)$latestScan['infected_files']));
} else {
    avsfObsAdd($groups, 'security', 'ความปลอดภัยและการเข้าถึง', 'ประวัติ Malware Scan', 'info',
        isset($tableNames['security_scan_history']) ? 'ยังไม่มีประวัติการสแกนที่เสร็จสมบูรณ์' : 'ไม่พบตารางเก็บประวัติ; ตรวจว่า auto-install สร้าง schema migration แล้วหรือยัง');
}

// --- SEO, redirects and request/error logs ---
$redirectMetrics = ['301'=>null,'410'=>null,'hits'=>null];
if ($dbReady && isset($tableNames['redirects'])) {
    $redirectMetrics = ['301'=>0,'410'=>0,'hits'=>0];
    $res = @$conn->query('SELECT code, COUNT(*) total, COALESCE(SUM(hits),0) hits FROM redirects WHERE active=1 GROUP BY code');
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if ((int)$r['code'] === 301) { $redirectMetrics['301'] = (int)$r['total']; $redirectMetrics['hits'] = (int)$r['hits']; }
            if ((int)$r['code'] === 410) $redirectMetrics['410'] = (int)$r['total'];
        }
        $res->free();
    }
    avsfObsAdd($groups, 'seo', 'SEO, URL และการตอบกลับ', 'Redirect 301 / 410', 'ok',
        'นับเฉพาะรายการ active; hits คือจำนวนที่บันทึกใน redirects', '301: ' . $redirectMetrics['301'] . ' · 410: ' . $redirectMetrics['410'] . ' · Hits: ' . $redirectMetrics['hits']);
}
$notFound24 = null; $notFoundEntries = null;
if ($dbReady && isset($tableNames['404_log'])) {
    $r = avsfObsQueryRow($conn, 'SELECT COUNT(*) entries, COALESCE(SUM(hits),0) hits FROM `404_log` WHERE last_hit >= NOW() - INTERVAL 24 HOUR');
    if ($r) { $notFoundEntries = (int)$r['entries']; $notFound24 = (int)$r['hits']; }
    avsfObsAdd($groups, 'seo', 'SEO, URL และการตอบกลับ', '404 ใน 24 ชั่วโมง', $notFound24 !== null && $notFound24 >= 300 ? 'warning' : 'info',
        $notFound24 === null ? 'อ่านตาราง 404_log ไม่ได้' : 'เป็นรายการ URL ที่ไม่พบ; อาจเกิดจากลิงก์เก่าหรือ bot ไม่ได้ยืนยันว่าเป็นข้อผิดพลาดของเนื้อหา', $notFound24 === null ? '—' : number_format($notFound24) . ' hits');
}
$errors5xx24 = null; $error5xxRows = [];
if ($dbReady && isset($tableNames['server_error_log'])) {
    $r = avsfObsQueryRow($conn, "SELECT COUNT(*) entries, COALESCE(SUM(hits),0) hits FROM server_error_log WHERE (error_type LIKE 'HTTP_5%' OR error_type LIKE 'PHP_FATAL_%') AND last_hit >= NOW() - INTERVAL 24 HOUR");
    if ($r) $errors5xx24 = (int)$r['hits'];
    $res = @$conn->query("SELECT path,error_type,hits,last_hit FROM server_error_log WHERE error_type LIKE 'HTTP_5%' OR error_type LIKE 'PHP_FATAL_%' ORDER BY last_hit DESC LIMIT 5");
    if ($res) { while ($row = $res->fetch_assoc()) $error5xxRows[] = ['path'=>(string)$row['path'],'type'=>(string)$row['error_type'],'hits'=>(int)$row['hits'],'last_hit'=>(string)$row['last_hit']]; $res->free(); }
    $errStatus = $errors5xx24 === null ? 'info' : ($errors5xx24 >= 50 ? 'error' : ($errors5xx24 > 0 ? 'warning' : 'ok'));
    avsfObsAdd($groups, 'seo', 'SEO, URL และการตอบกลับ', 'HTTP 5xx / PHP Fatal ใน 24 ชั่วโมง', $errStatus,
        $errors5xx24 === null ? 'อ่านบันทึก server_error_log ไม่ได้' : ($errors5xx24 > 0 ? 'มีรายการผิดพลาด ควรตรวจ Error Log Center' : 'ไม่พบข้อผิดพลาด 5xx/Fatal ในช่วง 24 ชั่วโมง'),
        $errors5xx24 === null ? '—' : number_format($errors5xx24) . ' hits');
}

$sitemapFiles = ['robots.php','sitemap_index.php','sitemap.php'];
$missingSitemapFiles = [];
foreach ($sitemapFiles as $file) if (!is_readable(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file)) $missingSitemapFiles[] = $file;
avsfObsAdd($groups, 'seo', 'SEO, URL และการตอบกลับ', 'ไฟล์ Robots / Sitemap', $missingSitemapFiles ? 'error' : 'ok',
    $missingSitemapFiles ? 'ไม่พบหรืออ่านไม่ได้: ' . implode(', ', $missingSitemapFiles) : 'พบ robots.php, sitemap_index.php และ sitemap.php');
$seoDefaults = trim((string)setting('seo_robots', 'index, follow'));
avsfObsAdd($groups, 'seo', 'SEO, URL และการตอบกลับ', 'Robots เริ่มต้น', 'info',
    'เป็นค่าเริ่มต้นของ meta robots ไม่ใช่ผลการ index ของ Google', $seoDefaults);

$seoIndexable = 0; $seoNoindex = 0; $seoCountKnown = false;
if ($dbReady) {
    $seoEntities = [
        'post'=>['posts', "WHERE entity.status='publish'"],
        'page'=>['pages', "WHERE entity.status='publish'"],
        'category'=>['categories',''], 'studio'=>['studios',''], 'actor'=>['actors',''], 'tag'=>['tags','']
    ];
    $seoCountKnown = true;
    foreach ($seoEntities as $type=>$entity) {
        if (!isset($tableNames[$entity[0]])) { $seoCountKnown = false; continue; }
        $default = function_exists('seoIndexDefault') && seoIndexDefault($type) ? 1 : 0;
        $typeSafe = $conn->real_escape_string($type);
        $res = @$conn->query("SELECT COALESCE(override.indexable, {$default}) indexable, COUNT(*) total FROM `{$entity[0]}` entity LEFT JOIN seo_index_overrides override ON override.entity_type='{$typeSafe}' AND override.entity_id=entity.id {$entity[1]} GROUP BY indexable");
        if (!$res) { $seoCountKnown = false; continue; }
        while ($row = $res->fetch_assoc()) {
            if ((int)$row['indexable'] === 1) $seoIndexable += (int)$row['total']; else $seoNoindex += (int)$row['total'];
        }
        $res->free();
    }
}
avsfObsAdd($groups, 'seo', 'SEO, URL และการตอบกลับ', 'SEO Index / Noindex inventory', $seoCountKnown ? 'ok' : 'info',
    $seoCountKnown ? 'รวมค่าตั้งต้นและ override ในฐานข้อมูล ไม่ใช่จำนวนที่ Google index จริง' : 'อ่าน SEO inventory ได้ไม่ครบ',
    $seoCountKnown ? 'Index ' . number_format($seoIndexable) . ' · Noindex ' . number_format($seoNoindex) : '—');

// --- Module / dependency inventory ---
$moduleFiles = [
    'SEO Center' => 'seo-center.php',
    'Redirect & 404' => 'redirects.php',
    'Cache' => 'cache.php',
    'Security Center' => 'security.php',
    'File Manager' => 'file-manager.php',
    'Backup & Restore' => 'backup-center.php',
    'Error Log Center' => 'error-log-center.php',
    'Bulk Import' => 'import.php',
    'User & Role Manager' => 'users.php',
    'Data Transfer' => 'data-transfer.php',
];
$missingModules = [];
foreach ($moduleFiles as $label=>$file) {
    $path = AVSF_ADMIN_DIR . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $file;
    $exists = is_file($path) && is_readable($path);
    if (!$exists) $missingModules[] = $label;
    avsfObsAdd($groups, 'modules', 'โมดูลและ Core Dependencies', 'โมดูล ' . $label, $exists ? 'ok' : 'error',
        $exists ? 'พบไฟล์โมดูลและ PHP อ่านได้ (ยังไม่ใช่การทดสอบ flow ทุกปุ่ม)' : 'ไม่พบหรืออ่านไฟล์โมดูลไม่ได้');
}
$coreFiles = [
    'Core Functions'=>'functions.php',
    'Database Auto Install'=>'database/auto-install.php',
    'Permission / RBAC'=>'auth/permissions.php',
    'TOTP / 2FA'=>'auth/totp.php',
    'Security Library'=>'security/security_lib.php',
    'Malware Scanner'=>'security/malware_scanner.php',
    'Backup Manager'=>'backup/backup_manager.php',
    'Cache Library'=>'cache/cache_lib.php',
    'Import Parser'=>'import/post_import_parser.php',
    'Image Matcher'=>'import/avsf-image-matcher.php',
    'Deployment Manager'=>'updates/deployment_manager.php',
];
foreach ($coreFiles as $label=>$relative) {
    $path = AVSF_CORE_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $exists = is_file($path) && is_readable($path);
    avsfObsAdd($groups, 'modules', 'โมดูลและ Core Dependencies', 'Core ' . $label, $exists ? 'ok' : 'error',
        $exists ? 'พบไฟล์และอ่านได้' : 'ไม่พบหรืออ่านไฟล์ Core ไม่ได้');
}
$licenseCandidates = [
    AVSF_CORE_DIR . '/license/license_client.php',
    AVSF_CORE_DIR . '/license/license_manager.php',
    AVSF_ADMIN_DIR . '/modules/license.php',
    AVSF_ADMIN_DIR . '/modules/license-center.php',
];
$licenseFound = false;
foreach ($licenseCandidates as $candidate) if (is_file($candidate) && is_readable($candidate)) $licenseFound = true;
avsfObsAdd($groups, 'modules', 'โมดูลและ Core Dependencies', 'B-NTP License Integration', $licenseFound ? 'info' : 'warning',
    $licenseFound ? 'พบไฟล์ License Integration; ยังต้องทดสอบการเชื่อมต่อและตรวจลายเซ็นกับ License Server จริง' : 'ยังไม่พบ License Client/Manager/Generator/Server ในแพ็กเกจนี้; ระบบ License ของ B-NTP ยังเป็นงานค้างและยังใช้ตรวจสิทธิ์ผลิตภัณฑ์จริงไม่ได้');

// --- Import readiness ---
$parserReady = is_readable(AVSF_CORE_DIR . '/import/post_import_parser.php');
$imageMatcherReady = is_readable(AVSF_CORE_DIR . '/import/avsf-image-matcher.php');
$importReady = is_readable(AVSF_ADMIN_DIR . '/modules/import.php') && $parserReady && $imageMatcherReady;
avsfObsAdd($groups, 'import', 'ระบบนำเข้าข้อมูลและสื่อ', 'โมดูลนำเข้า + Parser + Image Matcher', $importReady ? 'ok' : 'error',
    $importReady ? 'พบไฟล์นำเข้าข้อมูลและตัวจับคู่ภาพตาม Code ครบ' : 'ไฟล์ระบบนำเข้าหรือส่วนจับคู่ภาพขาด');
$uploadPostDetail = 'upload_max_filesize ' . (string)ini_get('upload_max_filesize') . ' · post_max_size ' . (string)ini_get('post_max_size') . ' · max_execution_time ' . (string)ini_get('max_execution_time') . 's';
avsfObsAdd($groups, 'import', 'ระบบนำเข้าข้อมูลและสื่อ', 'ขีดจำกัด PHP สำหรับนำเข้า', 'info',
    'ค่าจาก PHP runtime; ยังขึ้นกับข้อจำกัด Hosting / Proxy / Web server ด้วย', $uploadPostDetail);
avsfObsAdd($groups, 'import', 'ระบบนำเข้าข้อมูลและสื่อ', 'ขนาด/จำนวนไฟล์ที่ระบบกำหนด', 'info',
    'รูปสูงสุด 25 MB ต่อไฟล์ · สูงสุด 20,000 รูปต่องานนำเข้า · โพสต์บันทึกครั้งละ 25 เรื่อง · ไฟล์ข้อมูล staged สูงสุด 80 MB; ยังต้องไม่เกิน PHP/Hosting limits',
    '25 MB/รูป · 20,000 รูป/งาน · 25 เรื่อง/ชุด · 80 MB/ข้อมูล');
$latestImport = null;
if ($dbReady && isset($tableNames['import_history'])) {
    $latestImport = avsfObsQueryRow($conn, 'SELECT mode,total_rows,inserted_rows,updated_rows,skipped_rows,failed_rows,saved_images,linked_images,finished_at,summary_text FROM import_history ORDER BY id DESC LIMIT 1');
}
if ($latestImport) {
    $importStatus = (int)$latestImport['failed_rows'] > 0 ? 'warning' : 'ok';
    $importDetail = 'รวม ' . number_format((int)$latestImport['total_rows']) . ' รายการ · เพิ่ม ' . number_format((int)$latestImport['inserted_rows'])
        . ' · อัปเดต ' . number_format((int)$latestImport['updated_rows']) . ' · ข้าม ' . number_format((int)$latestImport['skipped_rows'])
        . ' · ผิดพลาด ' . number_format((int)$latestImport['failed_rows']);
    $importDetail .= ' · ล่าสุด ' . date('d/m/Y H:i', strtotime((string)$latestImport['finished_at']));
    if ((int)$latestImport['saved_images'] > 0 || (int)$latestImport['linked_images'] > 0) {
        $importDetail .= ' · บันทึกรูป ' . number_format((int)$latestImport['saved_images']) . ' · เชื่อมรูป ' . number_format((int)$latestImport['linked_images']);
    }
    avsfObsAdd($groups, 'import', 'ระบบนำเข้าข้อมูลและสื่อ', 'ประวัติการนำเข้าล่าสุด', $importStatus, $importDetail, (string)$latestImport['mode']);
} else {
    avsfObsAdd($groups, 'import', 'ระบบนำเข้าข้อมูลและสื่อ', 'ประวัติการนำเข้า', 'info',
        isset($tableNames['import_history']) ? 'ยังไม่มีงานนำเข้าที่บันทึกเสร็จสมบูรณ์' : 'ไม่พบตาราง import_history; ตรวจว่า auto-install สร้าง schema migration แล้วหรือยัง');
}

// --- Background process / host-level metrics (show unknown rather than invent values) ---
if (function_exists('sys_getloadavg')) {
    $load = @sys_getloadavg();
    if (is_array($load) && count($load) >= 3) {
        $loadText = number_format((float)$load[0], 2) . ' / ' . number_format((float)$load[1], 2) . ' / ' . number_format((float)$load[2], 2);
        avsfObsAdd($groups, 'host', 'เครื่องเซิร์ฟเวอร์และงานเบื้องหลัง', 'System load average (1/5/15 นาที)', 'info',
            'ค่าที่ PHP process อ่านได้; shared hosting อาจเป็นค่าของ host/container ร่วม ไม่ใช่ CPU ของเว็บนี้โดยตรง', $loadText);
    }
} else {
    avsfObsAdd($groups, 'host', 'เครื่องเซิร์ฟเวอร์และงานเบื้องหลัง', 'System load average', 'info', 'Hosting ไม่อนุญาตให้ PHP อ่านค่านี้');
}
$hostMemory = null;
if (is_readable('/proc/meminfo')) {
    $memRaw = @file_get_contents('/proc/meminfo');
    if (is_string($memRaw) && preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memRaw, $mt) && preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memRaw, $ma)) {
        $hostMemory = ['total'=>(int)$mt[1] * 1024, 'available'=>(int)$ma[1] * 1024];
    }
}
if ($hostMemory) {
    $used = max(0, $hostMemory['total'] - $hostMemory['available']);
    $pct = $hostMemory['total'] > 0 ? round(($used / $hostMemory['total']) * 100, 1) : null;
    avsfObsAdd($groups, 'host', 'เครื่องเซิร์ฟเวอร์และงานเบื้องหลัง', 'RAM ระดับ OS ที่ PHP อ่านได้', 'info',
        'อาจเป็น RAM ของ host/container ร่วมบน shared hosting ไม่ใช่ RAM ที่รับประกันเฉพาะบัญชีนี้', avsfObsBytes($used) . ' / ' . avsfObsBytes($hostMemory['total']) . ($pct !== null ? ' (' . $pct . '%)' : ''));
} else {
    avsfObsAdd($groups, 'host', 'เครื่องเซิร์ฟเวอร์และงานเบื้องหลัง', 'RAM ทั้งเครื่อง', 'info',
        'อ่านไม่ได้ใน PHP environment; ค่า memory_peak ด้านบนเป็นเฉพาะ request ของ PHP เท่านั้น');
}
avsfObsAdd($groups, 'host', 'เครื่องเซิร์ฟเวอร์และงานเบื้องหลัง', 'CPU utilization', 'info',
    'PHP shared hosting ไม่มีข้อมูล CPU usage แบบ process/บัญชีที่เชื่อถือได้ จึงไม่แสดงตัวเลขคาดเดา');
avsfObsAdd($groups, 'host', 'เครื่องเซิร์ฟเวอร์และงานเบื้องหลัง', 'Cron / Worker heartbeat', 'info',
    'ระบบชุดนี้ยังไม่มี heartbeat registry สำหรับยืนยันว่าทุก cron/worker ทำงานอยู่; แสดงเป็นยังไม่สามารถยืนยัน ไม่ใช่สถานะผ่าน');

// Summary and compact KPI tiles.
foreach ($groups as $id=>$group) {
    $groups[$id]['status'] = avsfObsGroupStatus($group['items']);
    $counts = ['ok'=>0,'warning'=>0,'error'=>0,'info'=>0];
    foreach ($group['items'] as $item) $counts[$item['status']]++;
    $groups[$id]['counts'] = $counts;
}
$allChecks = [];
foreach ($groups as $g) foreach ($g['items'] as $item) $allChecks[] = $item;
$summaryCounts = ['ok'=>0,'warning'=>0,'error'=>0,'info'=>0];
foreach ($allChecks as $item) $summaryCounts[$item['status']]++;
$overall = $summaryCounts['error'] > 0 ? 'error'
    : ($summaryCounts['warning'] > 0 ? 'warning'
    : ($summaryCounts['info'] > 0 ? 'info' : 'ok'));
$issueRows = [];
foreach ($groups as $group) foreach ($group['items'] as $item) {
    if (in_array($item['status'], ['error','warning'], true)) {
        $issueRows[] = ['group'=>$group['title'],'label'=>$item['label'],'status'=>$item['status'],'detail'=>$item['detail']];
    }
}

$metrics = [
    ['id'=>'db','label'=>'ฐานข้อมูล','value'=>$dbReady?'ONLINE':'OFFLINE','detail'=>$dbReady?($dbLatencyMs.' ms response'):'เชื่อมต่อไม่ได้','status'=>$dbReady?'ok':'error'],
    ['id'=>'disk','label'=>'Disk filesystem','value'=>$diskUsedPercent===null?'อ่านไม่ได้':($diskUsedPercent.'%'),'detail'=>$diskUsedPercent===null?'Hosting ไม่อนุญาตให้ตรวจ':('เหลือ '.avsfObsBytes($diskFree)),'status'=>$diskStatus],
    ['id'=>'memory','label'=>'PHP request peak','value'=>avsfObsBytes($memoryPeak),'detail'=>'memory_limit '.$memoryLimit,'status'=>$memoryStatus],
    ['id'=>'posts','label'=>'โพสต์ทั้งหมด','value'=>$contentMetrics['posts']===null?'—':number_format($contentMetrics['posts']),'detail'=>'เผยแพร่ '.($contentMetrics['published']===null?'—':number_format($contentMetrics['published'])),'status'=>$contentMetrics['posts']===null?'info':'ok'],
    ['id'=>'errors','label'=>'5xx / Fatal 24 ชม.','value'=>$errors5xx24===null?'—':number_format($errors5xx24),'detail'=>'จำนวน hits จาก server_error_log','status'=>$errors5xx24===null?'info':($errors5xx24>0?'warning':'ok')],
    ['id'=>'notfound','label'=>'404 ใน 24 ชม.','value'=>$notFound24===null?'—':number_format($notFound24),'detail'=>'จำนวน hits จาก 404_log','status'=>'info'],
    ['id'=>'twofa','label'=>'บัญชีแอดมิน 2FA','value'=>$admin2fa===null?'—':($admin2fa['enabled'].'/'.$admin2fa['total']),'detail'=>'บัญชีที่ลงทะเบียน 2FA','status'=>$admin2fa===null?'info':($admin2fa['enabled']<$admin2fa['total']?'warning':'ok')],
    ['id'=>'seo','label'=>'SEO inventory','value'=>$seoCountKnown?number_format($seoIndexable+$seoNoindex):'—','detail'=>$seoCountKnown?('Index '.$seoIndexable.' · Noindex '.$seoNoindex):'อ่านไม่ครบ','status'=>$seoCountKnown?'ok':'info'],
];
$topMetrics = [
    'posts'=>['value'=>$contentMetrics['posts']===null?'อ่านไม่ได้':number_format($contentMetrics['posts']),'detail'=>'เผยแพร่ '.($contentMetrics['published']===null?'—':number_format($contentMetrics['published'])).' · ฉบับร่าง '.($contentMetrics['drafts']===null?'—':number_format($contentMetrics['drafts'])),'status'=>$contentMetrics['posts']===null?'info':'ok'],
    'pages'=>['value'=>$contentMetrics['pages']===null?'อ่านไม่ได้':number_format($contentMetrics['pages']),'detail'=>'จำนวนหน้าเว็บทั้งหมด','status'=>$contentMetrics['pages']===null?'info':'ok'],
    'users'=>['value'=>$contentMetrics['users']===null?'อ่านไม่ได้':number_format($contentMetrics['users']),'detail'=>'บัญชีทั้งหมดในระบบ','status'=>$contentMetrics['users']===null?'info':'ok'],
    'redirect301'=>['value'=>$redirectMetrics['301']===null?'อ่านไม่ได้':number_format($redirectMetrics['301']),'detail'=>'active · '.($redirectMetrics['hits']===null?'—':number_format($redirectMetrics['hits'])).' hits','status'=>$redirectMetrics['301']===null?'info':'ok'],
    'notfound'=>['value'=>$notFoundEntries===null?'อ่านไม่ได้':number_format($notFoundEntries),'detail'=>'รายการ · '.($notFound24===null?'—':number_format($notFound24)).' hits ใน 24 ชม.','status'=>$notFound24===null?'info':($notFound24>=300?'warning':'ok')],
    'errors'=>['value'=>$errors5xx24===null?'อ่านไม่ได้':number_format($errors5xx24),'detail'=>'5xx / Fatal hits ใน 24 ชม.','status'=>$errors5xx24===null?'info':($errors5xx24>0?'warning':'ok')],
    'views'=>['value'=>$contentMetrics['views']===null?'อ่านไม่ได้':number_format($contentMetrics['views']),'detail'=>'ผลรวม views ที่บันทึกในโพสต์','status'=>$contentMetrics['views']===null?'info':'ok'],
    'disk'=>['value'=>$diskUsedPercent===null?'อ่านไม่ได้':($diskUsedPercent.'%'),'detail'=>$diskUsedPercent===null?'พื้นที่ filesystem ของเว็บไซต์':('เหลือ '.avsfObsBytes($diskFree)),'status'=>$diskStatus],
];

if ($errors5xx24 !== null && $errors5xx24 > 0) {
    foreach ($error5xxRows as $errorRow) {
        $issueRows[] = ['group'=>'ข้อผิดพลาดล่าสุด','label'=>$errorRow['type'].' · '.$errorRow['path'],'status'=>'warning','detail'=>'hits '.$errorRow['hits'].' · ล่าสุด '.$errorRow['last_hit']];
    }
}

$payload = [
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'refresh_seconds' => 30,
    'overall' => $overall,
    'counts' => $summaryCounts,
    'total_checks' => count($allChecks),
    'issue_count' => min(12, count($issueRows)),
    'metrics' => $metrics,
    'top_metrics' => $topMetrics,
    'sections' => array_values($groups),
    'issues' => array_slice($issueRows, 0, 12),
    'recent_errors' => $error5xxRows,
    'limits' => [
        'system_cpu' => 'unavailable',
        'cron_worker' => 'no_heartbeat',
        'import_history' => 'not_recorded',
    ],
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
