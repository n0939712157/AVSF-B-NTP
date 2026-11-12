<?php
/** AVSF Server Reconnect: connect a new DB, then restore a verified full AVSF backup.
 * Does not create or reset users. Requires a FULL files_and_database AVSF backup.
 */
$root = dirname(__DIR__);
if (!defined('AVSF_ROOT')) define('AVSF_ROOT', $root);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
// This entry point runs before config.local.php exists, so it cannot load config.php/bootstrap.
// Load the i18n layer directly so UI strings (including caught RuntimeException messages) are translatable.
if (!defined('AVSF_CORE_DIR')) define('AVSF_CORE_DIR', $root . DIRECTORY_SEPARATOR . 'avsf-core');
require_once AVSF_CORE_DIR . DIRECTORY_SEPARATOR . 'i18n.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
require_once $root . '/avsf-core/backup/backup_manager.php';
function avsfReconnectEscape($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (empty($_SESSION['avsf_reconnect_csrf'])) $_SESSION['avsf_reconnect_csrf'] = bin2hex(random_bytes(32));
$error = '';
$success = false;
$summary = [];
$configPath = $root . '/config.local.php';
if (is_file($configPath)) {
    http_response_code(409);
    $error = __('เว็บไซต์นี้มี config.local.php และเชื่อมต่อฐานข้อมูลแล้ว หากต้องการย้ายไปเซิร์ฟเวอร์ใหม่ ให้ติดตั้งแพ็กเกจ Server Reconnect ใน Document Root ใหม่ที่ยังไม่มี config.local.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    @set_time_limit(0);
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['avsf_reconnect_csrf'], $token)) {
        $error = __('คำขอหมดอายุ กรุณาโหลดหน้าใหม่');
    } elseif (!extension_loaded('mysqli')) {
        $error = __('Hosting ยังไม่ได้เปิด PHP Extension mysqli');
    } elseif (!class_exists('ZipArchive')) {
        $error = __('Hosting ยังไม่ได้เปิด PHP Extension ZipArchive');
    } elseif (!isset($_FILES['migration_backup']) || ($_FILES['migration_backup']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['migration_backup']['tmp_name'])) {
        $error = __('กรุณาอัปโหลดไฟล์ AVSF Full Migration Backup ZIP');
    } elseif ((int)$_FILES['migration_backup']['size'] < 100 || (int)$_FILES['migration_backup']['size'] > 1073741824) {
        $error = __('ไฟล์ Backup ต้องมีขนาดไม่เกิน 1 GB และไม่ว่างเปล่า');
    } else {
        $host = trim((string)($_POST['host'] ?? ''));
        $port = filter_var($_POST['port'] ?? '3306', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
        $dbName = trim((string)($_POST['database'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9.-]{1,255}$/', $host) || $port === false || !preg_match('/^[a-zA-Z0-9_$-]{1,64}$/', $dbName) || !preg_match('/^[a-zA-Z0-9_$.-]{1,64}$/', $dbUser)) {
            $error = __('ข้อมูล Host, Port, Database หรือ Username ไม่ถูกต้อง');
        } elseif (!isset($_POST['confirm_empty_db']) || $_POST['confirm_empty_db'] !== '1') {
            $error = __('กรุณายืนยันว่า Database ปลายทางเป็นฐานข้อมูลใหม่และไม่มีตารางเดิม');
        } else {
            mysqli_report(MYSQLI_REPORT_OFF);
            $conn = @mysqli_connect($host, $dbUser, $dbPass, $dbName, (int)$port);
            if (!$conn) {
                $error = __('เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบข้อมูลที่ Hosting ให้มา');
            } else {
                mysqli_set_charset($conn, 'utf8mb4');
                $tables = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                if (!$tables) $error = __('ตรวจสอบฐานข้อมูลปลายทางไม่สำเร็จ: %s', [mysqli_error($conn)]);
                elseif ($tables->num_rows > 0) $error = __('ฐานข้อมูลปลายทางมีตารางอยู่แล้ว กรุณาสร้าง Database ว่างใหม่เพื่อป้องกันข้อมูลถูกเขียนทับ');
                if ($tables) $tables->free();
                $tmp = $_FILES['migration_backup']['tmp_name'];
                if ($error === '') {
                    try {
                        $manager = new AVSFBackupManager($root);
                        $manifest = $manager->inspectArchive($tmp);
                        if (empty($manifest['database']['included']) || ($manifest['backup_type'] ?? '') !== 'files_and_database') throw new RuntimeException(__('ไฟล์นี้เป็น Backup แบบไม่มีฐานข้อมูล ต้องเลือก Full Migration Backup'));
                        $tableNames = $manifest['_database_tables'] ?? [];
                        if (!in_array('users', $tableNames, true) || !in_array('settings', $tableNames, true) || !in_array('posts', $tableNames, true)) throw new RuntimeException(__('Backup ไม่มีตารางหลัก users/settings/posts ที่จำเป็น'));
                        $sqlZip = new ZipArchive();
                        if ($sqlZip->open($tmp, ZipArchive::CHECKCONS) !== true) throw new RuntimeException(__('เปิดไฟล์ Backup ไม่สำเร็จ'));
                        $sql = $sqlZip->getFromName('database/database.sql');
                        $sqlZip->close();
                        if (!is_string($sql) || !preg_match('/CREATE\\s+TABLE\\s+`users`\\s*\\([\\s\\S]*?`twofa_secret`[\\s\\S]*?`twofa_enabled`/i', $sql)) throw new RuntimeException(__('Backup ไม่มีโครงสร้างบัญชีผู้ใช้และข้อมูล 2FA ที่รองรับ'));
                        unset($sql);
                        if (!is_writable($root)) throw new RuntimeException(__('โฟลเดอร์เว็บไซต์ไม่มีสิทธิ์เขียนไฟล์ กรุณาตรวจสอบ Permission'));
                        // Write DB connector only after all package checks have passed.
                        $installNonce = bin2hex(random_bytes(32));
                        $config = ['host'=>$host,'port'=>(int)$port,'name'=>$dbName,'user'=>$dbUser,'password'=>$dbPass,'install_nonce'=>$installNonce,'migration_restored_at'=>date('c')];
                        $configCode = "<?php\n// Generated by AVSF Server Reconnect. Keep private.\nreturn ".var_export($config, true).";\n";
                        $configTmp = $configPath.'.'.bin2hex(random_bytes(6)).'.tmp';
                        if (@file_put_contents($configTmp, $configCode, LOCK_EX) === false || !@rename($configTmp, $configPath)) { @unlink($configTmp); throw new RuntimeException(__('บันทึก config.local.php ไม่สำเร็จ กรุณาตรวจ Permission')); }
                        @chmod($configPath, 0600);
                        try {
                            $preserveCore = [
                                '.htaccess', 'index.php', 'db-setup.php', 'setup.php', 'forgot-password.php', 'server-reconnect.php', 'AVSF-METADATA.json',
                                'avsf-admin/index.php', 'avsf-admin/modules/backup-center.php', 'avsf-admin/modules/data-transfer.php',
                                'avsf-admin/modules/file-manager.php', 'avsf-admin/modules/users.php', 'avsf-admin/modules/site-center.php',
                                'avsf-admin/modules/seo-center.php', 'avsf-core/backup/backup_manager.php',
                                'avsf-core/bootstrap.php', 'avsf-core/database/auto-install.php', 'avsf-core/auth/totp.php',
                                'avsf-core/auth/permissions.php', 'avsf-core/security/security_lib.php',
                                'avsf-installer/db-setup.php', 'avsf-installer/setup.php', 'avsf-installer/server-reconnect.php'
                            ];
                            $restored = $manager->restoreBackup($tmp, $conn, true, true, $preserveCore);
                        } catch (Throwable $restoreError) {
                            @unlink($configPath);
                            throw $restoreError;
                        }
                        $lock = ['completed_at'=>date('c'),'backup_created_at'=>$manifest['created_at'] ?? null,'database_tables'=>(int)($manifest['database']['tables'] ?? 0),'website_files'=>(int)($manifest['website_files'] ?? 0),'backup_history_restored'=>(int)($restored['backup_history'] ?? 0),'settings_backup_history_restored'=>(int)($restored['settings_backup_history'] ?? 0)];
                        @file_put_contents($root.'/.avsf-reconnect-complete', json_encode($lock, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
                        @chmod($root.'/.avsf-reconnect-complete', 0600);
                        $success = true;
                        $summary = $lock;
                        $_SESSION = [];
                        if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']); }
                        session_destroy();
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                        error_log('AVSF Server Reconnect failed: '.$e->getMessage());
                    }
                }
                mysqli_close($conn);
            }
        }
    }
}
?><!doctype html><html lang="<?php echo avsf_lang_attr(); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>AVSF Server Reconnect</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#f3f6fb;color:#162238;font:15px/1.65 system-ui,-apple-system,"Noto Sans Thai",sans-serif;padding:22px;display:grid;place-items:center}.wrap{width:min(100%,760px)}.card{background:#fff;border:1px solid #dce4ef;border-radius:20px;padding:clamp(20px,4vw,34px);box-shadow:0 18px 55px #18294412}.brand{font-size:12px;color:#e83e83;font-weight:900;letter-spacing:.1em}.title{font-size:25px;margin:7px 0}.muted{color:#66758b;font-size:13px;margin:0 0 20px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{margin:12px 0}.field label{display:block;font-size:12px;font-weight:800;margin:0 0 6px}.field input{width:100%;padding:12px;border:1px solid #ccd7e5;border-radius:10px;background:#fff;color:#162238;font:inherit}.field input[type=file]{padding:9px}.span{grid-column:1/-1}.check{display:flex;gap:10px;align-items:flex-start;background:#fff8e7;border:1px solid #f3d48d;border-radius:10px;padding:12px;font-size:13px}.btn{width:100%;padding:14px;border:0;border-radius:11px;background:linear-gradient(110deg,#ed2d79,#974de4);color:#fff;font-weight:850;font-size:15px;cursor:pointer}.notice{background:#edf5ff;border:1px solid #c6ddfb;padding:12px;border-radius:11px;color:#385477;font-size:12px;margin:15px 0}.err{padding:12px;border-radius:10px;background:#fff1f2;border:1px solid #fecdd3;color:#be123c;margin:12px 0}.ok{padding:16px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;margin:12px 0}.ok a{display:inline-block;margin-top:12px;background:#047857;color:#fff;padding:11px 18px;border-radius:9px;text-decoration:none;font-weight:800}.small{font-size:11px;color:#64748b;margin-top:10px}@media(max-width:560px){body{padding:12px}.grid{grid-template-columns:1fr}.span{grid-column:auto}.title{font-size:22px}}</style></head><body><main class="wrap"><section class="card"><div class="brand">B-NTP · AVSF PORTABLE</div><h1 class="title"><?php _e('เชื่อมต่อเซิร์ฟเวอร์ใหม่และกู้คืนเว็บไซต์'); ?></h1><p class="muted"><?php _e('ระบบจะเชื่อมต่อ MySQL ใหม่ แล้วกู้คืนไฟล์ ฐานข้อมูล ผู้ใช้ สิทธิ์ 2FA และประวัติ Backup จาก Full Migration ZIP โดยไม่สร้าง Owner ใหม่'); ?></p>
<?php if ($error !== ''): ?><div class="err" role="alert"><?= avsfReconnectEscape($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="ok"><strong><?php _e('เชื่อมต่อและกู้คืนสำเร็จ'); ?></strong><br><?php printf(__('ตารางฐานข้อมูล %s ตาราง · ไฟล์เว็บไซต์ %s ไฟล์ · ประวัติ Backup ZIP กลับมา %s ไฟล์ · Settings Snapshots %s ไฟล์'), (int)$summary['database_tables'], (int)$summary['website_files'], (int)$summary['backup_history_restored'], (int)$summary['settings_backup_history_restored']); ?><br><?php _e('บัญชี Owner/Admin เดิมยังคงเดิม ไม่มีการสมัคร Owner ใหม่'); ?><br><a href="admin-login/index.php"><?php _e('เข้าสู่ระบบด้วยบัญชีเดิม'); ?></a></div><?php else: ?>
<form method="post" enctype="multipart/form-data" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= avsfReconnectEscape($_SESSION['avsf_reconnect_csrf']) ?>"><div class="grid"><div class="field"><label for="host">MySQL Host</label><input id="host" name="host" required placeholder="<?= avsfReconnectEscape(__('localhost หรือ Host จาก Hosting')) ?>"></div><div class="field"><label for="port">Port</label><input id="port" name="port" type="number" min="1" max="65535" value="3306" required></div><div class="field"><label for="database">Database Name</label><input id="database" name="database" required></div><div class="field"><label for="db_user">Database Username</label><input id="db_user" name="db_user" required></div><div class="field span"><label for="db_password">Database Password</label><input id="db_password" name="db_password" type="password" autocomplete="new-password" required></div><div class="field span"><label for="migration_backup">Full Migration Backup ZIP</label><input id="migration_backup" name="migration_backup" type="file" accept=".zip,application/zip" required><div class="small"><?php _e('เลือกไฟล์ที่สร้างจาก Backup Center → Full Migration ZIP (ต้องมี Files + Database)'); ?></div></div><label class="check span"><input type="checkbox" name="confirm_empty_db" value="1" required style="margin-top:4px"> <?php _e('ยืนยันว่า Database ปลายทางเป็นฐานข้อมูลใหม่และว่างเปล่า ไม่มีข้อมูลที่ต้องเก็บไว้'); ?></label><div class="span"><button class="btn" type="submit"><?php _e('ทดสอบการเชื่อมต่อและกู้คืนเว็บไซต์'); ?></button></div></div></form><div class="notice"><strong><?php _e('ข้อกำหนด:'); ?></strong> <?php _e('ใช้ฐานข้อมูลใหม่ที่ว่างเปล่าเท่านั้น การกู้คืนจะนำข้อมูลเดิมทั้งหมดกลับมา รวมถึงบัญชีผู้ใช้ Password Hash, Role, Permissions, 2FA Secret, Posts, Taxonomy, SEO, Uploads และ Backup History ไฟล์ config เชื่อมต่อฐานข้อมูลเดิมจะไม่ถูกย้าย'); ?></div><p class="small"><?php _e('ต้องเปิด PHP Extensions: mysqli, ZipArchive · ขนาด Upload สูงสุด 1 GB หรือตามข้อจำกัดของ Hosting'); ?></p><?php endif; ?></section></main></body></html>
