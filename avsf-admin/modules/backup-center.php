<?php
/** AVSF Backup Center — dedicated Site Center workspace. */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();
require_once AVSF_CORE_DIR . '/backup/backup_manager.php';

requireSiteCenterAccess();
requirePermission('backup.download');
$conn = $GLOBALS['conn'] ?? ($GLOBALS['mysqli'] ?? null);
$manager = null;
$msg = '';
$msgType = 'success';
$resultInfo = null;
$resetCompleted = false;
try { $manager = new AVSFBackupManager(AVSF_ROOT); }
catch (Throwable $e) { $msg = $e->getMessage(); $msgType = 'error'; error_log('AVSF Backup Center init failed: '.$e->getMessage()); }

function avsfBackupHumanSize($bytes) {
    $bytes = max(0, (int)$bytes);
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
function avsfBackupStream($path, $name, $deleteAfterSend = false) {
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit(__('ไม่พบไฟล์สำรอง')); }
    while (ob_get_level() > 0) ob_end_clean();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.basename($name).'"');
    header('Content-Length: '.filesize($path));
    header('Cache-Control: no-store, private');
    readfile($path);
    if ($deleteAfterSend) @unlink($path);
    exit;
}

if ($manager && isset($_GET['download'])) {
    $path = $manager->findStoredBackup($_GET['download']);
    if (!$path) { http_response_code(404); exit(__('ไม่พบไฟล์สำรองหรือชื่อไฟล์ไม่ถูกต้อง')); }
    avsfBackupStream($path, basename($path));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $manager) {
    sec_csrf_verify();
    $action = sec_post('action');
    try {
        if ($action === 'create_store' || $action === 'create_export' || $action === 'create_migration_export') {
            $type = sec_post('backup_type', 'files_only');
            if (!in_array($type, ['files_only','files_and_database'], true)) throw new RuntimeException('เลือกชนิด Backup ไม่ถูกต้อง');
            $withDb = $type === 'files_and_database' || $action === 'create_migration_export';
            if ($withDb || $action === 'create_migration_export') requireSuperAdmin();
            $includeHistory = $action === 'create_migration_export';
            $created = $manager->createBackup($conn, $withDb, $action === 'create_store', $includeHistory);
            if (function_exists('logActivity')) logActivity(($includeHistory ? __('ส่งออก Migration Backup พร้อมประวัติ Backup') : ($withDb ? __('สร้าง Backup ไฟล์และฐานข้อมูล') : __('สร้าง Backup ไฟล์เว็บไซต์'))) . ': ' . $created['name']);
            if ($action === 'create_export' || $action === 'create_migration_export') avsfBackupStream($created['path'], $created['name'], true);
            $resultInfo = $created;
            $msg = __('สร้างไฟล์สำรองไว้ในพื้นที่ส่วนตัวเรียบร้อยแล้ว');
        } elseif ($action === 'create_clean_site_export') {
            requireSuperAdmin();
            $created = $manager->createCleanSitePackage(defined('AVSF_VERSION') ? AVSF_VERSION : '3.2.0');
            // Clean export is intentionally non-mutating: do not write site activity/settings.
            avsfBackupStream($created['path'], $created['name'], true);
        } elseif ($action === 'reset_current_site') {
            requireSuperAdmin();
            if (sec_post('confirm_reset') !== 'RESET AVSF SITE' || sec_post('confirm_reset_scope') !== '1') {
                throw new RuntimeException('กรุณายืนยันขอบเขตและพิมพ์ RESET AVSF SITE ให้ตรงก่อนล้างข้อมูล');
            }
            $resetResult = $manager->resetSiteData($conn, sec_post('clear_uploads') === '1');
            // Clear all backend identity/session data after all accounts are removed.
            // Preserve only the install nonce so the first-Owner wizard can open in this browser.
            $localInstall = [];
            $localPath = AVSF_ROOT . DIRECTORY_SEPARATOR . 'config.local.php';
            if (is_file($localPath) && !is_link($localPath)) {
                $candidate = require $localPath;
                if (is_array($candidate)) $localInstall = $candidate;
            }
            $installNonce = (string)($localInstall['install_nonce'] ?? '');
            $_SESSION = [];
            if ($installNonce !== '') $_SESSION['avsf_install_nonce'] = $installNonce;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
            $resultInfo = $resetResult;
            $msg = __('ล้างข้อมูลเว็บไซต์สำเร็จแล้ว ระบบเก็บ Safety Backup ไว้ก่อนล้าง และคืนค่าตั้งต้นมาตรฐาน %s รายการ กรุณาสร้าง Owner คนใหม่', [(int)$resetResult['defaults_restored']]);
            if (!empty($resetResult['unknown_tables_preserved'])) $msg .= ' · ' . __('มีตารางที่ไม่ใช่ตารางมาตรฐาน AVSF ถูกเก็บไว้: ') . implode(', ', $resetResult['unknown_tables_preserved']);
            if (!empty($resetResult['cleanup_warnings'])) $msg .= ' · ' . __('ฐานข้อมูล Reset แล้ว แต่มีไฟล์ Runtime บางรายการลบไม่สำเร็จ: ') . implode(' | ', $resetResult['cleanup_warnings']);
            $resetCompleted = true;
        } elseif ($action === 'upload_backup') {
            requireSuperAdmin();
            if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) throw new RuntimeException('กรุณาเลือกไฟล์ ZIP สำหรับ Restore');
            $file = $_FILES['backup_file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลด ZIP ไม่สำเร็จ (รหัส '.(int)$file['error'].')');
            if ((int)$file['size'] < 100 || (int)$file['size'] > 512 * 1024 * 1024) throw new RuntimeException('ขนาด ZIP ต้องมากกว่า 100 ไบต์และไม่เกิน 512 MB');
            if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'zip' || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('รับเฉพาะไฟล์ ZIP ที่อัปโหลดผ่านฟอร์มเท่านั้น');
            $name = 'avsf-backup-imported-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
            $destination = $manager->getBackupDir() . DIRECTORY_SEPARATOR . $name;
            if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('ย้าย ZIP เข้า private storage ไม่สำเร็จ');
            @chmod($destination, 0600);
            try { $manifest = $manager->inspectArchive($destination); }
            catch (Throwable $e) { @unlink($destination); throw $e; }
            if (function_exists('logActivity')) logActivity('อัปโหลดไฟล์ Backup เพื่อ Restore: '.$name);
            $resultInfo = ['name'=>$name,'manifest'=>$manifest,'size'=>filesize($destination)];
            $msg = __('ตรวจสอบไฟล์ ZIP ผ่านและเพิ่มในรายการ Backup แล้ว');
        } elseif ($action === 'restore_backup') {
            requireSuperAdmin();
            $name = sec_post('backup_name');
            $confirm = sec_post('confirm_restore');
            if ($confirm !== 'RESTORE') throw new RuntimeException('กรุณาพิมพ์ RESTORE เพื่อยืนยันการกู้คืน');
            $restoreFiles = sec_post('restore_files') === '1';
            $restoreDb = sec_post('restore_database') === '1';
            $path = $manager->findStoredBackup($name);
            if (!$path) throw new RuntimeException('ไม่พบไฟล์ Backup ที่เลือก');
            $restored = $manager->restoreBackup($path, $conn, $restoreFiles, $restoreDb);
            if (function_exists('logActivity')) logActivity('Restore Backup '.$name.'; files='.$restored['files'].'; database='.($restored['database']?'yes':'no'));
            $resultInfo = $restored;
            $msg = __('Restore สำเร็จ — ไฟล์ ').$restored['files'].__(' รายการ').($restored['database']?__(' และฐานข้อมูลเรียบร้อย'):''). __(' · ประวัติ Backup ที่นำกลับ ').(int)($restored['backup_history']??0).__(' รายการ · Safety Backup: ').$restored['safety_backup'];
        } elseif ($action === 'delete_backup') {
            requireSuperAdmin();
            $path = $manager->findStoredBackup(sec_post('backup_name'));
            if (!$path) throw new RuntimeException('ไม่พบไฟล์ Backup ที่เลือก');
            if (!@unlink($path)) throw new RuntimeException('ลบไฟล์ Backup ไม่สำเร็จ');
            $msg = __('ลบไฟล์ Backup ที่เลือกแล้ว');
        } else {
            throw new RuntimeException('ไม่รู้จักคำสั่งที่ส่งมา');
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage(); $msgType = 'error';
        error_log('AVSF Backup Center action failed: '.$e->getMessage());
    }
}

$backups = [];
$zipAvailable = class_exists('ZipArchive');
$backupReady = $zipAvailable && $manager instanceof AVSFBackupManager;
if ($manager) {
    try { $backups = $manager->listBackups(); }
    catch (Throwable $e) { $msg = $msg ?: __('อ่านรายการ Backup ไม่สำเร็จ'); $msgType='error'; error_log('AVSF Backup list failed: '.$e->getMessage()); }
}
$maxUpload = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');
?>
<!doctype html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backup &amp; Restore Center</title>
<style>
:root{color-scheme:light;--bg:#f4f6fa;--surface:#fff;--surface2:#f8fafc;--text:#172033;--muted:#64748b;--border:#e2e8f0;--accent:#ef5a24;--good:#087443;--bad:#b42318;--warn:#9a6200}
[data-admin-theme="dark"]{color-scheme:dark;--bg:#090b10;--surface:#121722;--surface2:#192131;--text:#e8edf7;--muted:#9ba8bb;--border:#2a3547;--accent:#ff713c;--good:#63d7a2;--bad:#ff8d8d;--warn:#ffd078}
*{box-sizing:border-box}html,body{margin:0;width:100%;max-width:100%;overflow-x:clip;min-height:100%;background:var(--bg);color:var(--text);font:14px/1.65 system-ui,-apple-system,"Noto Sans Thai",sans-serif}body{padding:clamp(12px,2vw,24px)}a{color:inherit}.avsf-wrap{max-width:1200px;margin:0 auto}.avsf-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}.avsf-eyebrow{font-size:11px;font-weight:800;letter-spacing:.08em;color:var(--accent);text-transform:uppercase}.avsf-heading h1{font-size:clamp(21px,3vw,28px);line-height:1.25;margin:3px 0 6px}.avsf-heading p,.avsf-muted{margin:0;color:var(--muted);font-size:13px}.avsf-panel{background:var(--surface);border:1px solid var(--border);border-radius:15px;padding:clamp(14px,2vw,21px);margin-bottom:15px;box-shadow:0 5px 20px rgba(15,23,42,.04)}.avsf-panel h2{font-size:16px;margin:0 0 5px}.avsf-panel>.avsf-muted{margin-bottom:14px}.avsf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.avsf-choice{border:1px solid var(--border);background:var(--surface2);border-radius:12px;padding:14px;min-width:0}.avsf-choice label{display:flex;gap:10px;align-items:flex-start;font-weight:750;cursor:pointer}.avsf-choice input{margin-top:5px;accent-color:var(--accent)}.avsf-choice p{color:var(--muted);font-size:12px;margin:6px 0 0 25px}.avsf-row{display:flex;flex-wrap:wrap;gap:9px;align-items:center}.avsf-btn{appearance:none;border:1px solid var(--border);background:var(--surface2);color:var(--text);border-radius:9px;padding:10px 14px;min-height:40px;font:700 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.avsf-btn.avsf-primary{background:var(--accent);border-color:var(--accent);color:#fff}.avsf-btn.avsf-good{background:#087443;border-color:#087443;color:#fff}.avsf-btn.avsf-danger{color:var(--bad);border-color:color-mix(in srgb,var(--bad) 40%,var(--border))}.avsf-btn:disabled{opacity:.45;cursor:not-allowed}.avsf-notice{border:1px solid var(--border);border-left:4px solid var(--warn);padding:12px 14px;border-radius:10px;background:var(--surface2);color:var(--muted);font-size:12px;margin-top:12px}.avsf-notice strong{color:var(--text)}.avsf-msg{padding:12px 14px;border-radius:10px;margin-bottom:14px;border:1px solid var(--border);background:var(--surface2)}.avsf-msg.avsf-success{color:var(--good);border-color:color-mix(in srgb,var(--good) 35%,var(--border))}.avsf-msg.avsf-error{color:var(--bad);border-color:color-mix(in srgb,var(--bad) 35%,var(--border))}.avsf-table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:620px}th,td{padding:11px 10px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}th{font-size:11px;color:var(--muted);font-weight:800}td{font-size:12px}.avsf-tag{display:inline-block;padding:3px 8px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);font-size:11px;font-weight:700}.avsf-tag.avsf-good{color:var(--good)}.avsf-tag.avsf-bad{color:var(--bad)}.avsf-inline-form{display:inline}.avsf-file-input{display:block;max-width:100%;width:100%;padding:11px;border:1px dashed var(--border);border-radius:10px;background:var(--surface2);color:var(--text)}.avsf-restore-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(240px,.7fr);gap:16px}.avsf-field{display:grid;gap:6px;margin:10px 0}.avsf-field label{font-weight:700;font-size:12px}.avsf-field input[type=text]{width:100%;max-width:100%;padding:10px;border-radius:9px;border:1px solid var(--border);background:var(--surface2);color:var(--text)}.avsf-field-help{color:var(--muted);font-size:11px}.avsf-empty{padding:22px;text-align:center;color:var(--muted);border:1px dashed var(--border);border-radius:10px}.avsf-stat{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}.avsf-stat span{font-size:11px;color:var(--muted);background:var(--surface2);padding:5px 9px;border:1px solid var(--border);border-radius:20px}.avsf-danger-zone{border-color:color-mix(in srgb,var(--bad) 40%,var(--border))}@media(max-width:700px){.avsf-grid,.avsf-restore-grid{grid-template-columns:1fr}.avsf-btn{width:100%}.avsf-row .avsf-btn{width:auto}}
</style>
</head>
<body data-admin-theme="light"><main class="avsf-wrap">
<header class="avsf-heading"><div><div class="avsf-eyebrow">AVSF SYSTEM TOOLS</div><h1>สำรองข้อมูลและกู้คืนระบบ</h1><p>Backup Center · สร้างไฟล์สำรองแยกเก็บนอกโฟลเดอร์เว็บไซต์ พร้อมตรวจสอบก่อน Restore</p></div><span class="avsf-tag <?= $backupReady?'avsf-good':'avsf-bad' ?>"><?= $backupReady?__('พร้อมสร้างและตรวจ ZIP Backup'):(!$zipAvailable?__('ไม่พบ ZipArchive'):__('พื้นที่ Backup ยังไม่พร้อม')) ?></span></header>
<?php if($msg): ?><div class="avsf-msg <?= $msgType==='error'?'avsf-error':'avsf-success' ?>" role="status"><?= e($msg) ?></div><?php endif; ?>
<?php if(!$zipAvailable): ?><div class="avsf-msg avsf-error"><?php _e('Hosting นี้ยังไม่มี PHP ZipArchive จึงยังสร้าง/อ่าน ZIP ไม่ได้ กรุณาเปิด PHP Extension Zip ใน cPanel ก่อนใช้งาน Backup Center'); ?></div><?php endif; ?>
<section class="avsf-panel"><h2><?php _e('1. สร้าง Backup'); ?></h2><p class="avsf-muted"><?php _e('เลือกขอบเขตข้อมูลก่อนสร้างไฟล์สำรอง ระบบจะเก็บ Backup แบบบันทึกไว้บนเซิร์ฟเวอร์ หรือส่งออกให้ดาวน์โหลดลงเครื่องได้'); ?></p>
<form method="post" class="avsf-grid"><?= sec_csrf_field() ?><div class="avsf-choice"><label><input type="radio" name="backup_type" value="files_only" checked><span><?php _e('สำรองเฉพาะไฟล์เว็บไซต์'); ?></span></label><p><?php _e('รวม PHP, CSS, JavaScript, ธีม, การตั้งค่าที่เก็บเป็นไฟล์ และ uploads ไม่รวมฐานข้อมูลและไฟล์ config เชื่อมต่อฐานข้อมูล'); ?></p></div><div class="avsf-choice"><label><input type="radio" name="backup_type" value="files_and_database"><span><?php _e('สำรองไฟล์เว็บไซต์ + ฐานข้อมูล'); ?></span></label><p><?php _e('รวมไฟล์ทั้งหมดตามขอบเขตข้างต้นและตารางหลัก (BASE TABLE) ในฐานข้อมูลปัจจุบัน'); ?></p></div><div class="avsf-row" style="grid-column:1/-1"><button class="avsf-btn avsf-good" name="action" value="create_store" type="submit" <?= !$backupReady?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <?php _e('Backup เก็บไว้บนเซิร์ฟเวอร์'); ?></button><button class="avsf-btn avsf-primary" name="action" value="create_export" type="submit" <?= !$backupReady?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> <?php _e('Backup และส่งออกลงเครื่อง'); ?></button></div></form>
<div class="avsf-notice"><strong><?php _e('ความปลอดภัย:'); ?></strong> <?php _e('ไฟล์ Backup ที่เก็บไว้จะอยู่ในโฟลเดอร์ส่วนตัวนอก Document Root ไม่เปิดให้เข้าผ่าน URL โดยตรง ไฟล์ config เชื่อมต่อฐานข้อมูลจะไม่ถูกรวมใน ZIP เพื่อป้องกันข้อมูลลับ · Database dump ครอบคลุมตารางหลัก (BASE TABLE) ทั้งฐานข้อมูล ไม่รวม View/Trigger/Stored Procedure'); ?></div></section>
<section class="avsf-panel"><h2><?php _e('1.1 ส่งออกเพื่อย้ายเซิร์ฟเวอร์ (รวมประวัติ Backup)'); ?></h2><p class="avsf-muted"><?php _e('สร้าง ZIP ชุดย้ายระบบที่รวมไฟล์เว็บไซต์ ฐานข้อมูลทั้งหมด (รวมบัญชีผู้ใช้, Password Hash, Role, Permissions และข้อมูล 2FA) พร้อมไฟล์ Backup เก่าที่เก็บไว้ในพื้นที่ส่วนตัวนอกเว็บ'); ?></p><form method="post" class="avsf-row"><?= sec_csrf_field() ?><input type="hidden" name="backup_type" value="files_and_database"><button class="avsf-btn avsf-primary" name="action" value="create_migration_export" type="submit" <?= !$backupReady?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.7-4 3-9 3s-9-1.3-9-3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/></svg> <?php _e('ย้ายเซิร์ฟเวอร์: ดาวน์โหลด Full Migration ZIP'); ?></button></form><div class="avsf-notice"><strong><?php _e('ข้อควรทราบ:'); ?></strong> <?php _e('ZIP นี้ไม่รวม config.php/config.local.php และค่าเชื่อมต่อฐานข้อมูลเดิม แต่รวมข้อมูลสำคัญของผู้ใช้และ 2FA จึงต้องเก็บอย่างปลอดภัย ใช้กับฐานข้อมูลปลายทางใหม่ที่ยังไม่มีตาราง AVSF เท่านั้น'); ?></div></section>
<section class="avsf-panel">
<h2><?php _e('2. ส่งออก Clean Site ZIP — เว็บใหม่แบบสะอาด (ไม่กระทบเว็บนี้)'); ?></h2>
<p class="avsf-muted"><?php _e('สร้างไฟล์ ZIP สำหรับติดตั้ง AVSF เป็นเว็บไซต์ใหม่ โดยไม่เปลี่ยนแปลงเว็บไซต์ต้นทางและไม่ล้างฐานข้อมูลปัจจุบัน'); ?></p>
<div class="avsf-grid">
 <div class="avsf-choice"><label><input type="checkbox" checked disabled><span><?php _e('เก็บซอร์สและหน้าตั้งค่าทั้งหมด'); ?></span></label><p><?php _e('Admin, Site Center, Frontend, CSS, JavaScript, ภาษาไทย/อังกฤษ, คำแนะนำใต้ช่อง, Placeholder และค่าเริ่มต้นกลางของระบบ'); ?></p></div>
 <div class="avsf-choice"><label><input type="checkbox" checked disabled><span><?php _e('ตัดข้อมูลเฉพาะเว็บไซต์เดิม'); ?></span></label><p><?php _e('ไม่รวมฐานข้อมูลจริง ผู้ใช้/2FA โพสต์ หน้าเพจ SEO เฉพาะเว็บ รูปภาพ uploads และข้อมูล runtime'); ?></p></div>
</div>
<div class="avsf-notice"><strong><?php _e('ข้อมูลที่ระบบคงไว้:'); ?></strong> <?php _e('ชื่อหัวข้อ ช่องกรอก คำอธิบาย ตัวอย่าง Placeholder แนวทาง SEO และค่าตั้งต้นของระบบยังอยู่ครบ เพราะอยู่ในซอร์สและ Auto Installer ส่วนชื่อโดเมน รหัสผ่าน/API Key/SMTP/Token และข้อมูลลูกค้าเดิมจะไม่ถูกส่งออก'); ?></div>
<div class="avsf-notice"><strong><?php _e('วิธีนำไปใช้:'); ?></strong> <?php _e('แตก ZIP ลง Document Root ของเว็บใหม่ → สร้างฐานข้อมูล MySQL ว่าง → เปิด /db-setup.php → กรอกข้อมูลฐานข้อมูล → เปิด /setup.php เพื่อสร้าง Owner และตั้ง 2FA'); ?></div>
<form method="post" class="avsf-row" style="margin-top:12px"><?= sec_csrf_field() ?><button class="avsf-btn avsf-primary" name="action" value="create_clean_site_export" type="submit" <?= !$backupReady || !isSuperAdmin()?'disabled':'' ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 17v3h16v-3"/></svg> <?php _e('สร้างและดาวน์โหลด Clean Site ZIP'); ?></button></form>
</section>
<section class="avsf-panel avsf-danger-zone">
<h2><?php _e('3. ล้างข้อมูลเว็บไซต์นี้ (ไม่ย้อนกลับด้วยปุ่ม Undo)'); ?></h2>
<p class="avsf-muted"><?php _e('ใช้เมื่อจะเริ่มเว็บไซต์นี้ใหม่จริง ๆ ระบบจะสร้าง Safety Backup ไฟล์และฐานข้อมูลไว้ในพื้นที่ส่วนตัวก่อน จากนั้นล้างเฉพาะตารางมาตรฐาน AVSF และคืนค่า Default Settings'); ?></p>
<div class="avsf-notice"><strong><?php _e('ข้อมูลที่จะถูกล้าง:'); ?></strong> <?php _e('โพสต์, ค่าย, นักแสดง, แท็ก, หมวดหมู่, หน้าเพจ, แบนเนอร์, คอมเมนต์, Redirect, SEO Index Overrides, บัญชีผู้ใช้ทุก Role, Activity/Logs และค่าตั้งค่าเฉพาะเว็บไซต์'); ?></div>
<div class="avsf-notice"><strong><?php _e('ข้อมูลที่จะเก็บไว้:'); ?></strong> <?php _e('ซอร์สโค้ด, config.php, config.local.php, .env, .htaccess, คำแนะนำ/Placeholder/ภาษา และค่าเริ่มต้นมาตรฐานของระบบ ตารางที่ไม่ได้อยู่ในรายการ AVSF มาตรฐานจะไม่ถูกลบ'); ?></div>
<?php if (!$resetCompleted): ?>
<form method="post" class="avsf-panel" style="margin-top:12px" onsubmit="return confirm('คำเตือน: การดำเนินการนี้จะลบบัญชีผู้ใช้ทั้งหมด รวมถึง Owner และข้อมูลเว็บไซต์ที่ระบุไว้ ต้องการดำเนินการจริงหรือไม่?')">
<?= sec_csrf_field() ?>
<div class="avsf-field"><label><input type="checkbox" name="clear_uploads" value="1" checked> <?php _e('ลบไฟล์สื่อใน uploads และไฟล์ Cache/Logs/Quarantine หลังสร้าง Safety Backup'); ?></label><small class="avsf-field-help"><?php _e('ถ้าไม่เลือก รูปภาพ/วิดีโอเดิมจะยังอยู่ในโฟลเดอร์ uploads แต่ไม่ผูกกับโพสต์ที่ถูกล้าง'); ?></small></div>
<div class="avsf-field"><label><input type="checkbox" name="confirm_reset_scope" value="1" required> <?php _e('ฉันเข้าใจว่าจะลบบัญชีผู้ดูแลทั้งหมด และต้องสร้าง Owner ใหม่หลังจากนี้'); ?></label></div>
<div class="avsf-field"><label for="confirmResetText"><?php _e('พิมพ์ RESET AVSF SITE เพื่อยืนยัน'); ?></label><input id="confirmResetText" type="text" name="confirm_reset" autocomplete="off" placeholder="RESET AVSF SITE" required pattern="RESET AVSF SITE"></div>
<button class="avsf-btn avsf-danger" name="action" value="reset_current_site" type="submit" <?= !$backupReady || !isSuperAdmin()?'disabled':'' ?>><?php _e('สร้าง Safety Backup และล้างข้อมูลเว็บไซต์นี้'); ?></button>
</form>
<?php else: ?>
<div class="avsf-notice"><strong><?php _e('ล้างข้อมูลเสร็จแล้ว'); ?></strong><p><?php _e('Safety Backup:'); ?> <code><?= e($resultInfo['safety_backup'] ?? '') ?></code></p><p><?php _e('สร้าง Owner ใหม่จากหน้าติดตั้งครั้งแรก'); ?></p><a class="avsf-btn avsf-primary" href="<?= e(rtrim(avsfCanonicalBaseUrl(), '/') . '/setup.php') ?>" target="_top" rel="noopener"><?php _e('ไปสร้างบัญชี Owner ใหม่'); ?></a></div>
<?php endif; ?>
</section>
<section class="avsf-panel"><h2><?php _e('4. Restore / กู้คืนจากไฟล์ ZIP'); ?></h2><p class="avsf-muted"><?php _e('อัปโหลดไฟล์ AVSF Backup จากเครื่อง หรือเลือกไฟล์ที่บันทึกไว้บนเซิร์ฟเวอร์ ระบบจะตรวจ Manifest, path และ hash ของ SQL ก่อนให้กู้คืน'); ?></p>
<form method="post" enctype="multipart/form-data" class="avsf-row"><?= sec_csrf_field() ?><input class="avsf-file-input" style="flex:1 1 280px" type="file" name="backup_file" accept=".zip,application/zip" required><button class="avsf-btn avsf-primary" name="action" value="upload_backup" type="submit" <?= !$backupReady?'disabled':'' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> <?php _e('ตรวจสอบและเพิ่มไฟล์ Restore'); ?></button></form>
<div class="avsf-notice"><strong><?php _e('ก่อน Restore:'); ?></strong> <?php _e('ระบบจะสร้าง Safety Backup ของไฟล์และฐานข้อมูลปัจจุบันไว้อีกชุดก่อนเสมอ การ Restore ฐานข้อมูลจะคืนรายชื่อตารางและข้อมูลตาม Snapshot รวมถึงลบ BASE TABLE ที่ถูกสร้างเพิ่มภายหลังในฐานข้อมูลที่เชื่อมต่อ ดังนั้นควรใช้ฐานข้อมูลที่แยกสำหรับ AVSF เท่านั้น; View/Trigger/Stored Procedure ไม่รวมอยู่ในชุดนี้ และการ Restore ไฟล์จะเขียนทับไฟล์ที่มีชื่อเดียวกัน แต่ไม่ลบไฟล์ใหม่ที่ไม่มีใน Backup'); ?></div></section>
<section class="avsf-panel"><h2><?php _e('5. ไฟล์ Backup ที่เก็บไว้'); ?></h2><p class="avsf-muted"><?php _e('ไฟล์ในรายการนี้จัดเก็บนอกเว็บ และเข้าถึงได้เฉพาะผู้ดูแลที่มีสิทธิ์ Backup'); ?></p>
<?php if(!$backups): ?><div class="avsf-empty"><?php _e('ยังไม่มีไฟล์ Backup ที่เก็บไว้'); ?></div><?php else: ?><div class="avsf-table-wrap"><div style="position:relative;margin-bottom:10px"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><input type="search" id="backupFilter" placeholder="<?php echo __('ค้นหาไฟล์ Backup...'); ?>" style="width:100%;padding-left:34px" oninput="var q=this.value.toLowerCase();document.querySelectorAll('tr[data-backup-row]').forEach(function(r){r.style.display=(r.textContent.toLowerCase().indexOf(q)>=0)?'':'none';});"></div><table><thead><tr><th><?php _e('ไฟล์'); ?></th><th><?php _e('ขอบเขต'); ?></th><th><?php _e('ขนาด'); ?></th><th><?php _e('วันที่'); ?></th><th><?php _e('สถานะ'); ?></th><th><?php _e('จัดการ'); ?></th></tr></thead><tbody><?php foreach($backups as $b): ?><tr data-backup-row><td><strong><?= e($b['name']) ?></strong><br><span class="avsf-field-help"><?= e($b['manifest']['created_at']??'') ?></span></td><td><?= !empty($b['manifest']['database']['included'])?__('ไฟล์ + Database (ตารางหลัก)'):__('ไฟล์อย่างเดียว') ?><br><span class="avsf-field-help"><?= (int)($b['manifest']['website_files']??0) ?> <?php _e('ไฟล์'); ?></span></td><td><?= e(avsfBackupHumanSize($b['size'])) ?></td><td><?= e($b['modified']) ?></td><td><span class="avsf-tag <?= $b['valid']?'avsf-good':'avsf-bad' ?>"><?= $b['valid']?__('ตรวจสอบผ่าน'):__('ZIP ไม่สมบูรณ์') ?></span></td><td><div class="avsf-row"><a class="avsf-btn" href="?download=<?= rawurlencode($b['name']) ?>" download="<?= e($b['name']) ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> <?php _e('ดาวน์โหลด'); ?></a><?php if($b['valid'] && isSuperAdmin()): ?><button class="avsf-btn avsf-primary" type="button" onclick="document.getElementById('restore-<?= e(substr(hash('sha256',$b['name']),0,12)) ?>').hidden=false"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 2.1-9.4L1 10"/></svg> Restore</button><?php endif; ?><?php if(isSuperAdmin()): ?><form method="post" class="avsf-inline-form" onsubmit="return confirm(<?php echo avsf_js(__('ลบไฟล์ Backup นี้ถาวร?')); ?>)"><?= sec_csrf_field() ?><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="backup_name" value="<?= e($b['name']) ?>"><button class="avsf-btn avsf-danger" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> <?php _e('ลบ'); ?></button></form><?php endif; ?></div>
<?php if($b['valid'] && isSuperAdmin()): $rid='restore-'.substr(hash('sha256',$b['name']),0,12); ?><form method="post" id="<?= e($rid) ?>" class="avsf-panel avsf-danger-zone" style="margin-top:12px" hidden><?= sec_csrf_field() ?><input type="hidden" name="action" value="restore_backup"><input type="hidden" name="backup_name" value="<?= e($b['name']) ?>"><strong><?php _e('ยืนยันการ Restore:'); ?> <?= e($b['name']) ?></strong><div class="avsf-field"><label><input type="checkbox" name="restore_files" value="1" checked> <?php _e('กู้คืนไฟล์เว็บไซต์'); ?></label></div><div class="avsf-field"><label><input type="checkbox" name="restore_database" value="1" <?= !empty($b['manifest']['database']['included'])?'':'disabled' ?>> กู้คืนตารางหลักในฐานข้อมูล <?= empty($b['manifest']['database']['included'])?__('(Backup นี้ไม่มี Database)'):'' ?></label></div><div class="avsf-field"><label><?php _e('พิมพ์ RESTORE เพื่อยืนยัน'); ?></label><input type="text" name="confirm_restore" autocomplete="off" placeholder="RESTORE" required pattern="RESTORE"></div><button class="avsf-btn avsf-danger" type="submit" onclick="return confirm(<?php echo avsf_js(__('ดำเนินการ Restore จริงหรือไม่? ระบบจะสร้าง Safety Backup ก่อน')); ?>)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 2.1-9.4L1 10"/></svg> <?php _e('เริ่มกู้คืน'); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<section class="avsf-panel"><h2><?php _e('6. ข้อจำกัดและข้อมูลระบบ'); ?></h2><div class="avsf-stat"><span>PHP <?= e(PHP_VERSION) ?></span><span>Upload max <?= e($maxUpload) ?></span><span>POST max <?= e($postMax) ?></span><span><?php _e('เก็บ Backup นอกเว็บ'); ?></span></div><p class="avsf-field-help" style="margin-top:10px"><?php _e('Backup ไฟล์อย่างเดียวจะไม่คืนค่าฐานข้อมูล ส่วน Restore ไฟล์จะเขียนทับไฟล์ที่มีชื่อเดียวกัน แต่ไม่ลบไฟล์ใหม่ที่ไม่มีอยู่ใน Backup และไฟล์เชื่อมต่อฐานข้อมูล (config.php / config.local.php) จะคงค่าเดิมไว้'); ?></p></section>
</main></body></html>
