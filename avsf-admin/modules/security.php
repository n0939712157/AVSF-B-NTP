<?php
/**
 * AVSF Admin — Security Center & Malware Scanner
 * วางที่: avsf-admin/security.php
 * ฟีเจอร์: สแกนมัลแวร์, quarantine, กู้คืน, ตรวจไฟล์แก้ไขล่าสุด,
 *          สแกน uploads หา PHP shell, เช็คสถานะความปลอดภัย
 * รันในหลังบ้านเท่านั้น — ไม่กระทบผู้ใช้หน้าเว็บ ไม่ลบข้อมูลจริง
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();

require_once AVSF_CORE_DIR . '/security/malware_scanner.php';

function avsfSecurityRecordScanHistory($conn, $scanType, $scannedFiles, $infectedFiles, $errorCount, $limitReached, $resultStatus, $startedAt, $durationMs, $summary) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) return false;
    $allowedTypes = ['scan_all','scan_uploads'];
    if (!in_array($scanType, $allowedTypes, true)) return false;
    $allowedStatuses = ['ok','warning','error','info'];
    if (!in_array($resultStatus, $allowedStatuses, true)) $resultStatus = 'info';
    $actorId = (int)($_SESSION['avsf_admin_id'] ?? 0);
    $startedAtSql = date('Y-m-d H:i:s', max(0, (int)$startedAt));
    $safeSummary = function_exists('mb_substr') ? mb_substr((string)$summary, 0, 240, 'UTF-8') : substr((string)$summary, 0, 240);
    $stmt = @$conn->prepare('INSERT INTO security_scan_history (scan_type,scanned_files,infected_files,error_count,limit_reached,result_status,started_at,finished_at,duration_ms,created_by,summary_text) VALUES (?,?,?,?,?,?,?,NOW(),?,?,?)');
    if (!$stmt) {
        error_log('AVSF security scan history prepare failed: ' . (isset($conn->error) ? $conn->error : 'database unavailable'));
        return false;
    }
    $scannedValue = $scannedFiles === null ? null : (int)$scannedFiles;
    $infectedFiles=(int)$infectedFiles; $errorCount=(int)$errorCount;
    $limitReached=(int)(bool)$limitReached; $durationMs=max(0,(int)$durationMs);
    $stmt->bind_param('siiiissiiis', $scanType,$scannedValue,$infectedFiles,$errorCount,$limitReached,$resultStatus,$startedAtSql,$durationMs,$actorId,$safeSummary);
    $ok=$stmt->execute();
    if (!$ok) error_log('AVSF security scan history insert failed: ' . $stmt->error);
    $stmt->close();
    return $ok;
}

requireSiteCenterAccess();
requirePermission('security.view');

$root = AVSF_ROOT; // AVSF site root
$scanner = null;
$scannerInitError = '';
try { $scanner = new AVSFMalwareScanner(AVSF_ROOT); }
catch (Throwable $e) { $scannerInitError = __('เริ่มระบบ Malware Scanner ไม่สำเร็จ: ').$e->getMessage(); error_log('AVSF Malware Scanner init failed: '.$e->getMessage()); }
$msg = $scannerInitError;
$msgType = $scannerInitError !== '' ? 'error' : 'success';
$scanResult = null;
$recentFiles = null;
$uploadsResult = null;
$quarantineList = null;

$action = '';
$securityScanStartedAt = 0.0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    $action = sec_post('action');
    if (in_array($action, ['scan_all','scan_uploads'], true)) $securityScanStartedAt = microtime(true);
    if (in_array($action, ['scan_all','scan_uploads','recent_files'], true)) requirePermission('security.scan');
    elseif (in_array($action, ['quarantine','restore'], true)) requireSuperAdmin();
    elseif ($action !== 'list_quarantine') { http_response_code(400); exit(__('คำสั่งไม่ถูกต้อง')); }

    if (!$scanner) {
        $msg = $scannerInitError !== '' ? $scannerInitError : __('Malware Scanner ยังไม่พร้อมใช้งาน');
        $msgType = 'error';
    } else {
        try {
            if ($action === 'scan_all') {
                $scanResult = $scanner->scanAll();
                $msg = __('ตรวจสอบ ') . $scanResult['scanned'] . __(' ไฟล์ พบต้องสงสัย ') . count($scanResult['infected']) . __(' ไฟล์');
                if (!empty($scanResult['limit_reached'])) { $msg .= __(' · ถึงขีดจำกัดการสแกน ยังไม่ครบทั้งเว็บไซต์'); $msgType = 'error'; }
                elseif (!empty($scanResult['errors'])) { $msg .= __(' · มีไฟล์อ่านไม่สำเร็จ ') . count($scanResult['errors']) . __(' รายการ'); $msgType = 'error'; }
                else { $msg = __('สแกนเรียบร้อย — ') . $msg; }
            } elseif ($action === 'scan_uploads') {
                $uploadsResult = $scanner->scanUploads();
                if (is_array($uploadsResult) && isset($uploadsResult['error'])) {
                    $msg = $uploadsResult['error']; $msgType = 'error';
                } else {
                    $msg = __('สแกน uploads เรียบร้อย — พบ ') . count($uploadsResult) . __(' รายการต้องสงสัย');
                    if (count($uploadsResult) > 0) $msgType = 'error';
                }
            } elseif ($action === 'recent_files') {
                $recentFiles = $scanner->recentlyModified(24);
                $msg = __('พบไฟล์ที่ถูกแก้ไขใน 24 ชั่วโมงล่าสุด: ') . count($recentFiles) . __(' ไฟล์');
            } elseif ($action === 'quarantine') {
                $file = sec_post('file');
                if ($scanner->quarantine($file)) $msg = __('ย้ายไฟล์เข้า quarantine เรียบร้อย (ยังไม่ลบ กู้คืนได้)');
                else { $msg = __('ไม่สามารถย้ายไฟล์ที่เลือกได้ กรุณาตรวจ path, symlink และสิทธิ์โฟลเดอร์'); $msgType = 'error'; }
            } elseif ($action === 'restore') {
                $qFile = sec_post('qfile');
                if ($scanner->restore($qFile)) $msg = __('กู้คืนไฟล์เรียบร้อย');
                else { $msg = __('กู้คืนไม่สำเร็จ ไฟล์ปลายทางอาจมีอยู่แล้วหรือ metadata ไม่ถูกต้อง'); $msgType = 'error'; }
            } elseif ($action === 'list_quarantine') {
                $quarantineList = glob(AVSF_QUARANTINE_DIR . '/*');
                $quarantineList = $quarantineList ? array_values(array_filter($quarantineList, function($f) { $name = basename($f); return is_file($f) && !is_link($f) && !in_array($name, ['index.html','.htaccess'], true) && !str_ends_with($name, '.meta') && is_file($f . '.meta') && !is_link($f.'.meta'); })) : [];
            }
        } catch (Throwable $actionError) {
            $msg = __('ดำเนินการไม่สำเร็จ: ').$actionError->getMessage();
            $msgType = 'error';
            error_log('AVSF Security Center action '.$action.' failed: '.$actionError->getMessage());
        }
    }
}

if (in_array($action, ['scan_all','scan_uploads'], true) && $securityScanStartedAt > 0) {
    $durationMs = (int)round((microtime(true) - $securityScanStartedAt) * 1000);
    $scannedFiles = null; $infectedFiles = 0; $errorCount = 0; $limitReached = false;
    if ($action === 'scan_all' && is_array($scanResult)) {
        $scannedFiles = (int)($scanResult['scanned'] ?? 0);
        $infectedFiles = count((array)($scanResult['infected'] ?? []));
        $errorCount = count((array)($scanResult['errors'] ?? []));
        $limitReached = !empty($scanResult['limit_reached']);
    } elseif ($action === 'scan_uploads' && is_array($uploadsResult) && !isset($uploadsResult['error'])) {
        $infectedFiles = count($uploadsResult);
    } elseif ($msgType === 'error') {
        $errorCount = 1;
    }
    $resultStatus = $msgType === 'error' ? 'error' : (($infectedFiles > 0 || $errorCount > 0 || $limitReached) ? 'warning' : 'ok');
    avsfSecurityRecordScanHistory($conn ?? null, $action, $scannedFiles, $infectedFiles, $errorCount, $limitReached, $resultStatus, (int)$securityScanStartedAt, $durationMs, $msg);
}

// เช็คสถานะความปลอดภัยเบื้องต้น
$displayErrors = strtolower(trim((string)ini_get('display_errors')));
$loginAttemptsResult = null;
if (isset($conn) && is_object($conn) && method_exists($conn, 'query')) {
    $loginAttemptsResult = @$conn->query("SHOW TABLES LIKE 'login_attempts'");
}
$rootHtaccess = @file_get_contents($root . '/.htaccess');
$securityChecks = [
    __('uploads/.htaccess มีอยู่') => file_exists(AVSF_UPLOAD_DIR . '/.htaccess'),
    __('cache/.htaccess มีอยู่') => file_exists(AVSF_CACHE_DIR . '/.htaccess'),
    __('display_errors ปิดอยู่') => in_array($displayErrors, ['', '0', 'off', 'false', 'no'], true),
    __('PHP เวอร์ชัน 8.0+') => version_compare(PHP_VERSION, '8.0.0', '>='),
    __('ตาราง login_attempts มีอยู่') => is_object($loginAttemptsResult) && isset($loginAttemptsResult->num_rows) && $loginAttemptsResult->num_rows > 0,
    __('ไม่มีไฟล์ install.php') => !file_exists($root . '/install.php') && !file_exists($root . '/../install.php'),
    __('ไม่มีไฟล์ phpinfo.php') => !file_exists($root . '/phpinfo.php') && !file_exists($root . '/../phpinfo.php'),
    __('config.php ถูกบล็อกด้วย .htaccess') => is_string($rootHtaccess)
        && strpos($rootHtaccess, '<FilesMatch "^(config(\\.example)?') !== false
        && strpos($rootHtaccess, 'Require all denied') !== false,
];
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🛡️ Security Center — <?= e(avsfBrandName()) ?> Admin</title>
<style>
:root{color-scheme:light;--sec-bg:#f5f7fb;--sec-surface:#fff;--sec-surface2:#f8fafc;--sec-text:#172033;--sec-muted:#64748b;--sec-border:#e2e8f0}
[data-admin-theme="dark"]{color-scheme:dark;--sec-bg:#090b10;--sec-surface:#121722;--sec-surface2:#192131;--sec-text:#e8edf7;--sec-muted:#9ba8bb;--sec-border:#2a3547}
*{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:clip}body{font-family:'Segoe UI',Tahoma,sans-serif;background:var(--sec-bg);color:var(--sec-text);padding:20px}
.avsf-container{width:100%;max-width:1440px;margin:0 auto}
h1{font-size:24px;margin-bottom:8px;color:#f87171}
.avsf-subtitle{color:var(--sec-muted);margin-bottom:24px;font-size:14px}
.avsf-card{background:var(--sec-surface);border:1px solid var(--sec-border);border-radius:12px;padding:20px;margin-bottom:16px;color:var(--sec-text)}
.avsf-card h2{font-size:16px;margin-bottom:14px;color:var(--sec-text)}
.avsf-card p,.avsf-card li{color:var(--sec-muted)}
@media(max-width:700px){body{padding:10px}.avsf-card{padding:14px}}
.avsf-msg{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px}
.avsf-msg.avsf-success{background:#e7f8f0;border:1px solid #10b981;color:#15803d}
.avsf-msg.avsf-error{background:#fde8e8;border:1px solid #ef4444;color:#b42318}
.avsf-btn{display:inline-block;padding:10px 18px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin:4px 4px 4px 0;transition:all .2s}
.avsf-btn:hover{transform:translateY(-1px)}
.avsf-btn-primary{background:#0ea5e9;color:#fff}
.avsf-btn-danger{background:#ef4444;color:#fff}
.avsf-btn-warning{background:#f59e0b;color:#000}
.avsf-btn-success{background:#10b981;color:#fff}
.avsf-btn-sm{padding:5px 10px;font-size:12px}
.avsf-check-list{list-style:none}
.avsf-check-list li{padding:8px 0;border-bottom:1px solid var(--sec-border);font-size:14px;display:flex;align-items:center;gap:10px}
.avsf-check-ok{color:#10b981}
.avsf-check-fail{color:#ef4444}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--sec-border)}
th{color:var(--sec-muted);font-weight:600;font-size:12px;text-transform:uppercase}
.avsf-severity{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700}
.avsf-sev-critical{background:#7f1d1d;color:#fca5a5}
.avsf-sev-high{background:#7c2d12;color:#fdba74}
.avsf-sev-medium{background:#713f12;color:#fde047}
.avsf-sev-info{background:#1e3a5f;color:#bfdbfe}
code{background:var(--sec-surface2,#f8fafc);padding:2px 6px;border-radius:4px;font-size:12px;color:var(--accent,#267cf4);word-break:break-all}
.avsf-back{color:#64748b;text-decoration:none;font-size:13px;display:inline-block;margin-bottom:16px}
.avsf-back:hover{color:#38bdf8}
.avsf-file-path{font-family:monospace;font-size:12px;color:#fbbf24;word-break:break-all}

/* Keep security actions and long scan tables usable on touch screens. */
.avsf-card h2{line-height:1.45;min-width:0}
.avsf-card form[style*="display:inline"]{display:inline-flex!important;max-width:100%;vertical-align:top;margin:4px 8px 6px 0}
.avsf-card form[style*="display:inline"] .avsf-btn{max-width:100%;white-space:normal;overflow-wrap:normal;word-break:normal;line-height:1.45}
.avsf-check-list li{min-width:0;flex-wrap:wrap}
.avsf-file-path{overflow-wrap:anywhere}
@media(max-width:700px){
  body{padding:12px}
  .avsf-container{min-width:0}
  .avsf-card{padding:14px;min-width:0}
  .avsf-card form[style*="display:inline"]{display:flex!important;width:100%;max-width:100%;margin:0 0 8px}
  .avsf-card form[style*="display:inline"] .avsf-btn{width:100%;min-height:42px}
  .avsf-card>div[style*="overflow-x:auto"]{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
  .avsf-card table{min-width:620px}
  .avsf-btn{min-width:0;white-space:normal;overflow-wrap:normal;word-break:normal}
}
</style>
<link rel="stylesheet" href="admin-ui.css">
</head>
<body data-admin-theme="light">
<div class="avsf-container">
    <a class="avsf-back" href="index.php">← กลับแดชบอร์ด</a>
    <h1>🛡️ Security Center &amp; Malware Scanner</h1>
    <p class="avsf-subtitle">สแกนหามัลแวร์/แบ็คดอร์/PHP shell — รันในหลังบ้าน ไม่กระทบผู้ใช้ ไฟล์ที่ต้องสงสัยจะถูกย้ายเข้า quarantine (ไม่ลบทันที กู้คืนได้)</p>

    <?php if ($msg): ?><div class="avsf-msg <?= e(avsfClass($msgType)) ?>"><?= e($msg) ?></div><?php endif; ?>

    <!-- เช็คสถานะความปลอดภัย -->
    <div class="avsf-card">
        <h2><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> <?php _e('เช็คสถานะความปลอดภัยเบื้องต้น'); ?></h2>
        <ul class="avsf-check-list">
            <?php foreach ($securityChecks as $label => $ok): ?>
            <li>
                <span class="<?= e(avsfClass($ok ? 'avsf-check-ok' : 'avsf-check-fail')) ?>"><?= $ok ? '✓' : '✗' ?></span>
                <span><?= e($label) ?></span>
                <?php if (!$ok): ?><span style="color:#fca5a5;font-size:12px"><?php _e('— ต้องแก้ไข'); ?></span><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- ปุ่มสแกน -->
    <div class="avsf-card">
        <h2><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg> <?php _e('เริ่มการสแกน'); ?></h2>
        <?php if (can('security.scan')): ?>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="scan_all"><button class="avsf-btn avsf-btn-primary" type="submit" <?= !$scanner?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg> <?php _e('สแกนทั้งเว็บ (PHP / Script files)'); ?></button></form>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="scan_uploads"><button class="avsf-btn avsf-btn-warning" type="submit" <?= !$scanner?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg> <?php _e('สแกนโฟลเดอร์ uploads'); ?></button></form>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="recent_files"><button class="avsf-btn avsf-btn-secondary" type="submit" <?= !$scanner?'disabled':'' ?> style="background:#475569;color:#fff"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg> <?php _e('ดูไฟล์ที่แก้ไขล่าสุด 24 ชม.'); ?></button></form>
        <?php endif; ?>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="list_quarantine"><button class="avsf-btn avsf-btn-danger" type="submit" <?= !$scanner?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg> <?php _e('ดูไฟล์ใน quarantine'); ?></button></form>
    </div>

    <!-- ผลสแกนทั้งเว็บ -->
    <?php if ($scanResult): ?>
    <div class="avsf-card">
        <h2><?php _e('📋 ผลการสแกนทั้งเว็บ —'); ?> <?= $scanResult['scanned'] ?> <?php _e('ไฟล์'); ?></h2>
        <?php if (!empty($scanResult['limit_reached'])): ?><div class="avsf-msg avsf-error"><?php _e('การสแกนหยุดที่ขีดจำกัด'); ?> <?= (int)($scanResult['limit'] ?? 0) ?> <?php _e('ไฟล์ ผลนี้ยังไม่ใช่การยืนยันว่าทั้งเว็บไซต์ปลอดภัย'); ?></div><?php endif; ?>
        <?php if (!empty($scanResult['errors'])): ?><div class="avsf-msg avsf-error"><?php _e('มี'); ?> <?= count($scanResult['errors']) ?> <?php _e('รายการที่อ่าน/สแกนไม่สำเร็จ กรุณาตรวจข้อผิดพลาดก่อนสรุปผล'); ?></div><ul><?php foreach(array_slice($scanResult['errors'],0,20) as $scanError): ?><li><?= e($scanError['file'] ?? __('ไฟล์ไม่ทราบตำแหน่ง')) ?> — <?= e($scanError['message'] ?? __('ไม่ทราบสาเหตุ')) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (empty($scanResult['infected'])): ?>
            <p style="color:#10b981;padding:10px 0"><?php _e('✓ ไม่พบ signature ที่ต้องสงสัยในไฟล์ที่สแกนสำเร็จ'); ?> <?= (!empty($scanResult['errors']) || !empty($scanResult['limit_reached'])) ? __(' — ยังไม่ถือว่าสแกนครบ') : '' ?></p>
        <?php else: ?>
            <div style="overflow-x:auto"><table>
                <tr><th><?php _e('ไฟล์'); ?></th><th><?php _e('ขนาด'); ?></th><th><?php _e('แก้ไขล่าสุด'); ?></th><th><?php _e('การตรวจพบ'); ?></th><th><?php _e('จัดการ'); ?></th></tr>
                <?php foreach ($scanResult['infected'] as $inf): ?>
                <tr>
                    <td class="avsf-file-path"><?= e($inf['file']) ?></td>
                    <td><?= round($inf['size']/1024,1) ?> KB</td>
                    <td><?= e($inf['mtime']) ?></td>
                    <td>
                        <?php foreach ($inf['findings'] as $f): ?>
                            <div style="margin-bottom:4px">
                                <span class="avsf-severity avsf-sev-<?= $f['severity'] ?>"><?= strtoupper($f['severity']) ?></span>
                                <code><?= e($f['type']) ?></code>
                                <?php if (!empty($f['line'])): ?><?php _e('(บรรทัด'); ?> <?= $f['line'] ?>)<?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if (isSuperAdmin()): ?>
                        <form method="post" style="display:inline">
                            <?= sec_csrf_field() ?>
                            <input type="hidden" name="action" value="quarantine">
                            <input type="hidden" name="file" value="<?= e($inf['file']) ?>">
                            <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit" onclick="return confirm(<?php echo avsf_js(__('ย้ายไฟล์นี้เข้า quarantine? (ไม่ลบ กู้คืนได้)')); ?>)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg> <?php _e('ย้าย quarantine'); ?></button>
                        </form>
                        <?php else: ?><span class="avsf-muted"><?php _e('ต้องมีสิทธิ์สแกน/จัดการ'); ?></span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ผลสแกน uploads -->
    <?php if ($uploadsResult && !isset($uploadsResult['error'])): ?>
    <div class="avsf-card">
        <h2><?php _e('📁 ผลสแกนโฟลเดอร์ uploads'); ?></h2>
        <?php if (empty($uploadsResult)): ?>
            <p style="color:#10b981"><?php _e('✓ ปลอดภัย — ไม่พบ PHP shell ใน uploads'); ?></p>
        <?php else: ?>
            <div style="overflow-x:auto"><table>
                <tr><th><?php _e('ไฟล์'); ?></th><th><?php _e('สาเหตุ'); ?></th><th><?php _e('ระดับ'); ?></th><th><?php _e('จัดการ'); ?></th></tr>
                <?php foreach ($uploadsResult as $u): ?>
                <tr>
                    <td class="avsf-file-path"><?= e($u['file']) ?></td>
                    <td><?= e($u['reason']) ?></td>
                    <td><span class="avsf-severity avsf-sev-<?= e($u['severity'] ?? 'medium') ?>"><?= e(strtoupper($u['severity'] ?? 'medium')) ?></span></td>
                    <td>
                        <?php if (($u['severity'] ?? '') === 'info'): ?><span class="avsf-muted"><?php _e('ข้อมูลสถานะ'); ?></span>
                        <?php elseif (isSuperAdmin()): ?>
                        <form method="post" style="display:inline">
                            <?= sec_csrf_field() ?>
                            <input type="hidden" name="action" value="quarantine">
                            <input type="hidden" name="file" value="<?= e($u['file']) ?>">
                            <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit" onclick="return confirm(<?php echo avsf_js(__('ย้ายไฟล์นี้เข้า quarantine? ไฟล์จะถูกย้าย ไม่ถูกลบ')); ?>)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg> <?php _e('ย้าย quarantine'); ?></button>
                        </form>
                        <?php else: ?><span class="avsf-muted"><?php _e('ต้องมีสิทธิ์สแกน/จัดการ'); ?></span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ไฟล์แก้ไขล่าสุด -->
    <?php if ($recentFiles): ?>
    <div class="avsf-card">
        <h2><?php _e('🕒 ไฟล์ที่ถูกแก้ไขใน 24 ชั่วโมงล่าสุด ('); ?><?= count($recentFiles) ?> <?php _e('ไฟล์)'); ?></h2>
        <p style="font-size:12px;color:#94a3b8;margin-bottom:10px"><?php _e('มัลแวร์มักถูกอัปโหลด/แก้ไขล่าสุด — ถ้าเห็นไฟล์ที่ไม่รู้จักให้สงสัยทันที'); ?></p>
        <div style="overflow-x:auto"><table>
            <tr><th><?php _e('เวลา'); ?></th><th><?php _e('ไฟล์'); ?></th><th><?php _e('ขนาด'); ?></th></tr>
            <?php foreach (array_slice($recentFiles, 0, 50) as $r): ?>
            <tr><?php if (!empty($r['scan_notice'])): ?><td colspan="3" style="color:#fbbf24"><?= e($r['file']) ?><?= !empty($r['error'])?' — '.e($r['error']):'' ?></td><?php else: ?><td><?= e($r['mtime']) ?></td><td class="avsf-file-path"><?= e($r['file']) ?></td><td><?= round($r['size']/1024,1) ?> KB</td><?php endif; ?></tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <?php endif; ?>

    <!-- รายการ quarantine -->
    <?php if ($quarantineList !== null): ?>
    <div class="avsf-card">
        <h2><?php _e('📦 ไฟล์ใน quarantine ('); ?><?= count($quarantineList) ?> <?php _e('ไฟล์)'); ?></h2>
        <?php if (empty($quarantineList)): ?>
            <p style="color:#94a3b8"><?php _e('ว่างเปล่า'); ?></p>
        <?php else: ?>
            <div style="overflow-x:auto"><table>
                <tr><th><?php _e('ไฟล์ (ใน quarantine)'); ?></th><th><?php _e('จัดการ'); ?></th></tr>
                <?php foreach ($quarantineList as $q): ?>
                <tr>
                    <td class="avsf-file-path"><?= e(basename($q)) ?></td>
                    <td>
                        <?php if (isSuperAdmin()): ?>
                        <form method="post" style="display:inline">
                            <?= sec_csrf_field() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="qfile" value="<?= e(basename($q)) ?>">
                            <button class="avsf-btn avsf-btn-success avsf-btn-sm" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 2.1-9.4L1 10"/></svg> <?php _e('กู้คืน'); ?></button>
                        </form>
                        <?php else: ?><span class="avsf-muted"><?php _e('ไม่มีสิทธิ์กู้คืน'); ?></span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="avsf-card" style="border-color:#f59e0b">
        <h2 style="color:#fbbf24"><?php _e('⚠️ คำแนะนำความปลอดภัยเพิ่มเติม'); ?></h2>
        <ul style="font-size:13px;color:var(--sec-muted);line-height:2;padding-left:20px">
            <li><strong><?php _e('เปลี่ยนชื่อโฟลเดอร์ admin/'); ?></strong> <?php _e('เป็นชื่อที่เดายาก เช่น'); ?> <code>my-panel-xyz/</code> <?php _e('แล้วแก้ link ในโค้ด'); ?></li>
            <li><strong><?php _e('เปลี่ยนรหัสผ่าน admin'); ?></strong> เป็นรหัสยาว >12 ตัว ผสมตัวอักษร ตัวเลข สัญลักษณ์</li>
            <li><strong><?php _e('ติดตั้ง Cloudflare ฟรี'); ?></strong> <?php _e('— ได้ CDN + WAF + DDoS protection + SSL ฟรี'); ?></li>
            <li><strong><?php _e('เพิ่ม CAPTCHA'); ?></strong> <?php _e('ที่หน้า login และฟอร์มคอมเมนต์ (hCaptcha หรือ Cloudflare Turnstile ฟรี)'); ?></li>
            <li><strong><?php _e('สำรองข้อมูลทุกวัน'); ?></strong> <?php _e('— export .sql + ดาวน์โหลดโฟลเดอร์ uploads/'); ?></li>
            <li><strong><?php _e('ติดตั้ง SSL'); ?></strong> <?php _e('แล้วเอา # หน้า HTTPS redirect ออกใน .htaccess'); ?></li>
            <li><strong><?php _e('ลบไฟล์ install.php / setup.php / phpinfo.php'); ?></strong> <?php _e('ทิ้งทันทีหลังติดตั้ง'); ?></li>
        </ul>
    </div>
</body>
</html>
