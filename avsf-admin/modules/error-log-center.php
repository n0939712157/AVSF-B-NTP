<?php
/** AVSF Error Log Viewer — Site Center right panel only. */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();
requireSiteCenterAccess();
requirePermission('errorlog.view');

$root = realpath(AVSF_ROOT);
$parent = $root ? realpath(dirname($root)) : false;
$candidates = [];
$configured = trim((string)ini_get('error_log'));
if ($configured !== '' && !in_array(strtolower($configured), ['syslog','stderr','stdout'], true)) {
    $candidates[] = ($configured[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\\/]/', $configured)) ? $configured : (($root ?: AVSF_ROOT) . DIRECTORY_SEPARATOR . $configured);
}
if ($root) {
    foreach ([AVSF_LOG_DIR.'/error_log',AVSF_LOG_DIR.'/error.log',AVSF_LOG_DIR.'/php-error.log',$root.'/error_log',$root.'/error.log',AVSF_ADMIN_DIR.'/error_log'] as $candidate) $candidates[] = $candidate;
}
if ($parent) foreach ([$parent.'/logs/error_log',$parent.'/logs/error.log',$parent.'/logs/php-error.log'] as $candidate) $candidates[] = $candidate;
$logPath = false;
$allowedRoots = array_values(array_filter([AVSF_LOG_DIR, $root, $parent ? $parent.'/logs' : false]));
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if ($real === false || !is_file($real) || is_link($candidate) || !is_readable($real)) continue;
    $allowed = false;
    foreach ($allowedRoots as $allowedRoot) {
        $base = realpath($allowedRoot);
        if ($base && ($real === $base || strpos($real, rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) === 0)) { $allowed=true; break; }
    }
    if ($allowed) { $logPath=$real; break; }
}
$logLines=[]; $logBytes=0; $logModified=''; $logName=''; $logError='';
$conn = $GLOBALS['conn'] ?? ($GLOBALS['mysqli'] ?? null);
$appErrors = []; $notFoundRows = []; $appErrorCount = null; $notFoundCount = null; $dbLogStatus = '';
if (is_object($conn) && method_exists($conn, 'query')) {
    $errorResult = @$conn->query("SELECT path,error_type,hits,first_hit,last_hit FROM server_error_log ORDER BY last_hit DESC LIMIT 100");
    if ($errorResult) {
        while ($row = $errorResult->fetch_assoc()) $appErrors[] = $row;
        $errorResult->free();
        $countResult = @$conn->query('SELECT COUNT(*) AS total FROM server_error_log');
        if ($countResult) { $appErrorCount = (int)$countResult->fetch_assoc()['total']; $countResult->free(); }
        $dbLogStatus = __('อ่านตาราง server_error_log ได้');
    } else { $dbLogStatus = __('ยังไม่มีตาราง server_error_log หรือไม่มีสิทธิ์อ่าน'); }
    $notFoundResult = @$conn->query("SELECT path,hits,first_hit,last_hit FROM `404_log` ORDER BY last_hit DESC LIMIT 100");
    if ($notFoundResult) {
        while ($row = $notFoundResult->fetch_assoc()) $notFoundRows[] = $row;
        $notFoundResult->free();
        $notFoundCountResult = @$conn->query('SELECT COUNT(*) AS total FROM `404_log`');
        if ($notFoundCountResult) { $notFoundCount = (int)$notFoundCountResult->fetch_assoc()['total']; $notFoundCountResult->free(); }
    }
}
if ($logPath) {
    $logBytes=(int)filesize($logPath);
    $logModified=date('Y-m-d H:i:s',filemtime($logPath));
    $logName=basename($logPath);
    $handle=@fopen($logPath,'rb');
    if (!$handle) $logError=__('เซิร์ฟเวอร์ไม่อนุญาตให้อ่านไฟล์ Log นี้');
    else {
        $maxBytes=1024*1024;
        if ($logBytes>$maxBytes) fseek($handle,-$maxBytes,SEEK_END);
        $raw=stream_get_contents($handle,$maxBytes);
        fclose($handle);
        $lines=preg_split('/\r\n|\n|\r/',(string)$raw) ?: [];
        $logLines=array_slice($lines,-250);
    }
} else {
    $logError=__('ไม่พบไฟล์ PHP Error Log ที่ระบบมีสิทธิ์อ่านได้ หาก Hosting เก็บ Log ไว้ใน cPanel ให้เปิดดูผ่านเมนู Errors ของ Hosting หรือกำหนด error_log ให้ชี้ไปยังโฟลเดอร์ logs ของเว็บไซต์');
}
function avsfLogSize($n){$n=max(0,(int)$n);if($n>=1048576)return number_format($n/1048576,2).' MB';if($n>=1024)return number_format($n/1024,1).' KB';return $n.' B';}
?>
<!doctype html>
<html lang="<?php echo avsf_lang_attr(); ?>"><head><script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Error Log</title>
<style>
:root{color-scheme:light;--bg:#f4f6fa;--surface:#fff;--surface2:#f8fafc;--text:#172033;--muted:#64748b;--border:#e2e8f0;--accent:#ef5a24;--good:#087443;--bad:#b42318}
[data-admin-theme="dark"]{color-scheme:dark;--bg:#090b10;--surface:#121722;--surface2:#192131;--text:#e8edf7;--muted:#9ba8bb;--border:#2a3547;--accent:#ff713c;--good:#63d7a2;--bad:#ff8d8d}
*{box-sizing:border-box}html,body{margin:0;max-width:100%;overflow-x:clip;min-height:100%;background:var(--bg);color:var(--text);font:14px/1.65 system-ui,-apple-system,"Noto Sans Thai",sans-serif}body{padding:16px}.avsf-wrap{max-width:1400px;margin:0 auto}.avsf-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:15px}.avsf-head h1{font-size:clamp(20px,3vw,27px);margin:0 0 4px}.avsf-muted{color:var(--muted);font-size:12px}.avsf-panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:14px}.avsf-stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.avsf-stat{border:1px solid var(--border);background:var(--surface2);border-radius:9px;padding:7px 10px;font-size:12px;color:var(--muted)}.avsf-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:9px;padding:9px 13px;background:var(--surface2);color:var(--text);text-decoration:none;font-weight:700;cursor:pointer}.avsf-btn.avsf-primary{background:var(--accent);color:#fff;border-color:var(--accent)}.avsf-log{margin:0;max-height:70vh;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#080d15;color:#d5e3f5;border:1px solid #27364a;border-radius:10px;padding:14px;font:12px/1.65 ui-monospace,SFMono-Regular,Consolas,monospace}.avsf-empty{border:1px solid var(--border);border-left:4px solid #d99a30;padding:14px;border-radius:10px;background:var(--surface2);color:var(--muted)}@media(max-width:600px){body{padding:10px}.avsf-panel{padding:12px}}
</style></head><body data-admin-theme="light"><main class="avsf-wrap">
<header class="avsf-head"><div><div class="avsf-muted">SITE CENTER · SYSTEM MONITOR</div><h1>บันทึกข้อผิดพลาด (Error Log)</h1><div class="avsf-muted">อ่านอย่างเดียว · แสดงสูงสุด 250 บรรทัดล่าสุด ไม่แก้ไขหรือลบไฟล์ Log</div></div><div class="avsf-row"><a class="avsf-btn" href="site-center.php">← กลับภาพรวม</a><button class="avsf-btn avsf-primary" type="button" onclick="location.reload()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-2.1-9.4L23 10"/></svg> รีเฟรช</button></div></header>
<section class="avsf-panel"><strong>แหล่งข้อมูล Log</strong><div class="avsf-stats"><span class="avsf-stat">ไฟล์: <?= e($logName ?: __('ไม่พบไฟล์')) ?></span><span class="avsf-stat"><?php _e('ขนาด:'); ?> <?= e(avsfLogSize($logBytes)) ?></span><span class="avsf-stat"><?php _e('แก้ไขล่าสุด:'); ?> <?= e($logModified ?: '-') ?></span><span class="avsf-stat"><?php _e('แสดง:'); ?> <?= count($logLines) ?> <?php _e('บรรทัดล่าสุด'); ?></span></div></section>
<section class="avsf-panel"><h2 style="margin:0 0 10px"><?php _e('เนื้อหา Error Log'); ?></h2><?php if($logError): ?><div class="avsf-empty"><?= e($logError) ?></div><?php elseif(!$logLines): ?><div class="avsf-empty"><?php _e('ไฟล์ Log ยังไม่มีรายการข้อผิดพลาด'); ?></div><?php else: ?><pre class="avsf-log"><?= e(implode("\n",$logLines)) ?></pre><?php endif; ?></section>
<section class="avsf-panel"><h2 style="margin:0 0 10px"><?php _e('ข้อผิดพลาดจากAVSF (HTTP 5xx / PHP Fatal)'); ?></h2><div class="avsf-stats"><span class="avsf-stat"><?php _e('รายการทั้งหมด:'); ?> <?= $appErrorCount===null?__('ไม่พร้อมใช้งาน'):number_format($appErrorCount) ?></span><span class="avsf-stat"><?= e($dbLogStatus ?: __('ยังไม่ได้เชื่อมต่อฐานข้อมูล')) ?></span></div><?php if(!$appErrors): ?><div class="avsf-empty" style="margin-top:10px"><?php _e('ไม่มีรายการ หรือยังไม่มีตาราง server_error_log'); ?></div><?php else: ?><div style="position:relative;margin:10px 0"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><input type="search" id="errFilter1" placeholder="<?php echo __('ค้นหา Path / ประเภท...'); ?>" style="width:100%;padding-left:34px" oninput="var q=this.value.toLowerCase();document.querySelectorAll('tr[data-err500-row]').forEach(function(r){r.style.display=(r.textContent.toLowerCase().indexOf(q)>=0)?'':'none';});"></div><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:650px;margin-top:10px"><thead><tr><th>Path</th><th><?php _e('ประเภท'); ?></th><th>Hits</th><th><?php _e('พบล่าสุด'); ?></th></tr></thead><tbody><?php foreach($appErrors as $row): ?><tr data-err500-row><td><?= e($row['path']) ?></td><td><?= e($row['error_type']) ?></td><td><?= number_format((int)$row['hits']) ?></td><td><?= e($row['last_hit']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="avsf-panel"><h2 style="margin:0 0 10px"><?php _e('รายการ URL ที่พบ 404'); ?></h2><div class="avsf-stats"><span class="avsf-stat"><?php _e('รายการทั้งหมด:'); ?> <?= $notFoundCount===null?__('ไม่พร้อมใช้งาน'):number_format($notFoundCount) ?></span></div><?php if(!$notFoundRows): ?><div class="avsf-empty" style="margin-top:10px"><?php _e('ไม่มีรายการ 404 หรือยังไม่มีตาราง 404_log'); ?></div><?php else: ?><div style="position:relative;margin:10px 0"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><input type="search" placeholder="<?php echo __('ค้นหา Path 404...'); ?>" style="width:100%;padding-left:34px" oninput="var q=this.value.toLowerCase();document.querySelectorAll('tr[data-err404-row]').forEach(function(r){r.style.display=(r.textContent.toLowerCase().indexOf(q)>=0)?'':'none';});"></div><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:650px;margin-top:10px"><thead><tr><th>Path</th><th>Hits</th><th><?php _e('พบครั้งแรก'); ?></th><th><?php _e('พบล่าสุด'); ?></th></tr></thead><tbody><?php foreach($notFoundRows as $row): ?><tr data-err404-row><td><?= e($row['path']) ?></td><td><?= number_format((int)$row['hits']) ?></td><td><?= e($row['first_hit']) ?></td><td><?= e($row['last_hit']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main></body></html>
