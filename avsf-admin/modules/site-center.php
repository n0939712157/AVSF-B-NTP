<?php
/**
 * AVSF Admin — ศูนย์เว็บไซต์ (Site Center)
 * วางที่: avsf-admin/site-center.php
 * แบบการ์ดเหมือนภาพที่ส่งมา — มือถือก็ใช้สวย
 * ทุกปุ่มลิงก์ไปยังฟีเจอร์จริงที่ทำงานได้
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();
require_once AVSF_CORE_DIR . '/backup/backup_manager.php';
requireSiteCenterAccess();

$conn = $GLOBALS['conn'] ?? ($GLOBALS['mysqli'] ?? null);
if($conn)ensurePostsUpdatedAtColumn($conn);
$adminAccent = setting('admin_accent', '#267cf4');
if (!preg_match('/^#[0-9a-f]{6}$/i', $adminAccent)) $adminAccent = '#267cf4';
$siteCenterBrandName = avsfBrandName();
$siteCenterBrandLogo = trim((string)setting('siteLogo',''));
$siteCenterBrandLogoValid = $siteCenterBrandLogo !== '' && validImageReference($siteCenterBrandLogo);

function avsfSiteCenterChangePassword($conn, $username, $oldPassword, $newPassword) {
    if (!$conn || $username === '') return [false, __('ไม่พบข้อมูลผู้ดูแลระบบที่เข้าสู่ระบบ')];
    $stmt = $conn->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        error_log('AVSF password verification query failed: ' . $conn->error);
        return [false, __('ตรวจสอบรหัสผ่านไม่สำเร็จ')];
    }
    $stmt->bind_param('s', $username);
    if (!$stmt->execute()) {
        error_log('AVSF password verification query failed: ' . $stmt->error);
        $stmt->close();
        return [false, __('ตรวจสอบรหัสผ่านไม่สำเร็จ')];
    }
    $stmt->bind_result($userId, $storedPassword);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found || !(password_verify($oldPassword, $storedPassword) || hash_equals($storedPassword, md5($oldPassword)))) {
        return [false, __('รหัสผ่านเดิมไม่ถูกต้อง')];
    }
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    if (!$update) {
        error_log('AVSF password update query failed: ' . $conn->error);
        return [false, __('เปลี่ยนรหัสผ่านไม่สำเร็จ')];
    }
    $update->bind_param('si', $newHash, $userId);
    $saved = $update->execute();
    if (!$saved) error_log('AVSF password update failed: ' . $update->error);
    $update->close();
    return $saved ? [true, __('เปลี่ยนรหัสผ่านเรียบร้อย')] : [false, __('เปลี่ยนรหัสผ่านไม่สำเร็จ')];
}

function avsfSiteCenterDashboardQuery($label,$sql) {
    global $conn,$dashboardChecks;
    if (!$conn) {
        $dashboardChecks[]=['label'=>$label,'status'=>'error','detail'=>__('ไม่มีการเชื่อมต่อฐานข้อมูล')];
        return false;
    }
    $result=mysqli_query($conn,$sql);
    if (!$result) {
        error_log('AVSF Site Center dashboard query failed ('.$label.'): '.mysqli_error($conn));
        $dashboardChecks[]=['label'=>$label,'status'=>'error','detail'=>__('อ่านข้อมูลไม่สำเร็จ ตรวจ error log ของเซิร์ฟเวอร์')];
        return false;
    }
    $dashboardChecks[]=['label'=>$label,'status'=>'ok','detail'=>__('อ่านข้อมูลได้')];
    return $result;
}

$dashboardChecks=[];
$stats = ['posts'=>null,'published'=>null,'drafts'=>null,'pending_posts'=>null,'private_posts'=>null,'comments'=>null,'pending_comments'=>null,'views'=>null,'users'=>null,'pages'=>null,'published_pages'=>null];
$taxonomyCounts=[__('หมวดหมู่')=>null,__('ค่าย')=>null,__('นักแสดง')=>null,__('แท็ก')=>null];
$dashboardActivity=[];
$dashboard404=[];
$dashboardServerErrors=[];
$dashboardRedirectRows=[];
$dashboardRedirects=['301'=>null,'410'=>null,'hits_301'=>null];
$dashboardErrorSummary=['404_entries'=>null,'404_hits'=>null,'500_entries'=>null,'500_hits'=>null];
$dashboardSeo=['index'=>0,'noindex'=>0,'total'=>0];
$dashboardUsersByRole=[];

$postSummary=avsfSiteCenterDashboardQuery(__('สรุปโพสต์'),"SELECT COUNT(*) AS total,SUM(status='publish') AS published,SUM(status='draft') AS drafts,SUM(status='pending') AS pending_posts,SUM(status='private') AS private_posts,COALESCE(SUM(views),0) AS views FROM posts");
if ($postSummary) {
    $row=$postSummary->fetch_assoc();
    foreach(['posts'=>'total','published'=>'published','drafts'=>'drafts','pending_posts'=>'pending_posts','private_posts'=>'private_posts','views'=>'views'] as $key=>$column)$stats[$key]=(int)$row[$column];
    $postSummary->free();
}
$pageSummary=avsfSiteCenterDashboardQuery(__('สรุปหน้าเว็บ'),"SELECT COUNT(*) AS total,SUM(status='publish') AS published FROM pages");
if ($pageSummary) {$row=$pageSummary->fetch_assoc();$stats['pages']=(int)$row['total'];$stats['published_pages']=(int)$row['published'];$pageSummary->free();}
$commentSummary=avsfSiteCenterDashboardQuery(__('สรุปคอมเมนต์'),'SELECT COUNT(*) AS total,SUM(approved=0) AS pending FROM comments');
if ($commentSummary) {$row=$commentSummary->fetch_assoc();$stats['comments']=(int)$row['total'];$stats['pending_comments']=(int)$row['pending'];$commentSummary->free();}
$userSummary=avsfSiteCenterDashboardQuery(__('สรุปบัญชีผู้ใช้'),'SELECT role,COUNT(*) AS total FROM users GROUP BY role');
if ($userSummary) {while($row=$userSummary->fetch_assoc()){$dashboardUsersByRole[(string)$row['role']]=(int)$row['total'];$stats['users']=($stats['users']??0)+(int)$row['total'];}$userSummary->free();}
foreach([__('หมวดหมู่')=>'categories',__('ค่าย')=>'studios',__('นักแสดง')=>'actors',__('แท็ก')=>'tags'] as $label=>$table) {
    $result=avsfSiteCenterDashboardQuery(__('จำนวน').$label,'SELECT COUNT(*) AS total FROM `'.$table.'`');
    if($result){$taxonomyCounts[$label]=(int)$result->fetch_assoc()['total'];$result->free();}
}
$staticSeoTypes=['home','all','category_list','studio_list','actor_list','tag_list'];
foreach($staticSeoTypes as $type){$dashboardSeo['total']++;if(function_exists('seoIndexDefault')&&seoIndexDefault($type))$dashboardSeo['index']++;else $dashboardSeo['noindex']++;}
$seoEntities=['post'=>['posts',"WHERE entity.status='publish'"],'page'=>['pages',"WHERE entity.status='publish'"],'category'=>['categories',''],'studio'=>['studios',''],'actor'=>['actors',''],'tag'=>['tags','']];
foreach($seoEntities as $type=>$entity) {
    $default=function_exists('seoIndexDefault')&&seoIndexDefault($type)?1:0;
    $typeSql=$conn?mysqli_real_escape_string($conn,(string)$type):(string)$type;
    $result=avsfSiteCenterDashboardQuery('SEO index/noindex '.$type,"SELECT COALESCE(override.indexable,$default) AS indexable,COUNT(*) AS total FROM `{$entity[0]}` entity LEFT JOIN seo_index_overrides override ON override.entity_type='$typeSql' AND override.entity_id=entity.id {$entity[1]} GROUP BY indexable");
    if($result) {
        while($row=$result->fetch_assoc()){$count=(int)$row['total'];$dashboardSeo[(int)$row['indexable']?'index':'noindex']+=$count;$dashboardSeo['total']+=$count;}
        $result->free();
    }
}
$redirectSummary=avsfSiteCenterDashboardQuery(__('สรุป redirect'),'SELECT code,COUNT(*) AS total,COALESCE(SUM(hits),0) AS hits FROM redirects WHERE active=1 GROUP BY code');
if($redirectSummary){$dashboardRedirects=['301'=>0,'410'=>0,'hits_301'=>0];while($row=$redirectSummary->fetch_assoc()){if((int)$row['code']===301){$dashboardRedirects['301']=(int)$row['total'];$dashboardRedirects['hits_301']=(int)$row['hits'];}if((int)$row['code']===410)$dashboardRedirects['410']=(int)$row['total'];}$redirectSummary->free();}
$redirectRowsResult=avsfSiteCenterDashboardQuery(__('รายการ redirect ล่าสุด'),'SELECT old_url,new_url,code,hits FROM redirects WHERE active=1 ORDER BY id DESC LIMIT 5');
if($redirectRowsResult){while($row=$redirectRowsResult->fetch_assoc())$dashboardRedirectRows[]=$row;$redirectRowsResult->free();}
$notFoundSummary=avsfSiteCenterDashboardQuery(__('สรุป 404'),'SELECT COUNT(*) AS entries,COALESCE(SUM(hits),0) AS hits FROM `404_log`');
if($notFoundSummary){$row=$notFoundSummary->fetch_assoc();$dashboardErrorSummary['404_entries']=(int)$row['entries'];$dashboardErrorSummary['404_hits']=(int)$row['hits'];$notFoundSummary->free();}
$serverErrorSummary=avsfSiteCenterDashboardQuery(__('สรุปข้อผิดพลาด 5xx'),'SELECT COUNT(*) AS entries,COALESCE(SUM(hits),0) AS hits FROM server_error_log WHERE error_type LIKE \'HTTP_5%\' OR error_type LIKE \'PHP_FATAL_%\'');
if($serverErrorSummary){$row=$serverErrorSummary->fetch_assoc();$dashboardErrorSummary['500_entries']=(int)$row['entries'];$dashboardErrorSummary['500_hits']=(int)$row['hits'];$serverErrorSummary->free();}
$recent404Result=avsfSiteCenterDashboardQuery(__('รายการ 404 ล่าสุด'),'SELECT path,hits,last_hit FROM `404_log` ORDER BY last_hit DESC LIMIT 5');
if($recent404Result){while($row=$recent404Result->fetch_assoc())$dashboard404[]=$row;$recent404Result->free();}
$recentServerErrorsResult=avsfSiteCenterDashboardQuery(__('รายการข้อผิดพลาด 5xx ล่าสุด'),'SELECT path,error_type,hits,last_hit FROM server_error_log WHERE error_type LIKE \'HTTP_5%\' OR error_type LIKE \'PHP_FATAL_%\' ORDER BY last_hit DESC LIMIT 5');
if($recentServerErrorsResult){while($row=$recentServerErrorsResult->fetch_assoc())$dashboardServerErrors[]=$row;$recentServerErrorsResult->free();}
$activityResult=avsfSiteCenterDashboardQuery(__('กิจกรรมหลังบ้านล่าสุด'),'SELECT action,time FROM activity ORDER BY time DESC LIMIT 10');
if($activityResult){while($row=$activityResult->fetch_assoc())$dashboardActivity[]=$row;$activityResult->free();}
$root = AVSF_ROOT;
$stats['php_version'] = PHP_VERSION;
$stats['php_settings'] = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
];
$requestHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
$siteCanonicalHttps = stripos((string)avsfCanonicalBaseUrl(), 'https://') === 0;
$stats['https'] = $requestHttps ? 'ON' : ($siteCanonicalHttps ? 'VERIFY' : 'OFF');
$diskTotal = function_exists('disk_total_space') ? @disk_total_space($root) : false;
$diskFree = function_exists('disk_free_space') ? @disk_free_space($root) : false;
$stats['disk_percent'] = is_numeric($diskTotal) && $diskTotal > 0 && is_numeric($diskFree) ? round((1 - $diskFree / $diskTotal) * 100, 1) : null;
$stats['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
$dbNameResult = $conn ? $conn->query('SELECT DATABASE()') : false;
$stats['database_mb'] = null;
if (!$dbNameResult) error_log('AVSF Site Center could not read current database name'.($conn ? ': '.$conn->error : ': database connection unavailable'));
if ($dbNameResult) {
    $dbName = (string)($dbNameResult->fetch_row()[0] ?? '');
    if ($dbName !== '') {
        $dbStmt = $conn->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?');
        if ($dbStmt) {
            $dbStmt->bind_param('s', $dbName);
            if ($dbStmt->execute()) { $dbStmt->bind_result($dbBytes); $dbStmt->fetch(); $stats['database_mb'] = round((int)$dbBytes / 1048576, 1); }
            else error_log('AVSF Site Center database size query failed: '.$dbStmt->error);
            $dbStmt->close();
        } else error_log('AVSF Site Center database size prepare failed: '.$conn->error);
    }
}
$databaseConnectionAvailable=(bool)($conn&&mysqli_ping($conn));
$dashboardRuntimeChecks=[
    ['label'=>__('การเชื่อมต่อฐานข้อมูล'),'status'=>$databaseConnectionAvailable?'ok':'error','detail'=>$databaseConnectionAvailable?__('เชื่อมต่อได้'):__('เชื่อมต่อฐานข้อมูลไม่ได้')],
    ['label'=>__('HTTPS ของ request ปัจจุบัน'),'status'=>$stats['https']==='ON'?'ok':($stats['https']==='VERIFY'?'info':'warning'),'detail'=>$stats['https']==='ON'?__('PHP ยืนยัน HTTPS'):($stats['https']==='VERIFY'?__('URL หลักเป็น HTTPS แต่ PHP ไม่ยืนยัน scheme; อาจอยู่หลัง reverse proxy / SSL offload'):__('request นี้ไม่ยืนยัน HTTPS'))],
    ['label'=>__('พื้นที่ Disk filesystem'),'status'=>$stats['disk_percent']===null?'info':($stats['disk_percent']>=90?'warning':'ok'),'detail'=>$stats['disk_percent']===null?__('อ่านพื้นที่ filesystem ไม่ได้'):__('ใช้ไป ').$stats['disk_percent'].__('% ของ filesystem')],
    ['label'=>'PHP display_errors','status'=>in_array(strtolower(trim((string)ini_get('display_errors'))),['','0','off','false','no'],true)?'ok':'warning','detail'=>__('ค่า: ').(ini_get('display_errors')?:__('ค่าเริ่มต้น'))],
    ['label'=>__('โฟลเดอร์ uploads'),'status'=>is_dir(AVSF_UPLOAD_DIR)&&is_writable(AVSF_UPLOAD_DIR)?'ok':'warning','detail'=>is_dir(AVSF_UPLOAD_DIR)?(is_writable(AVSF_UPLOAD_DIR)?__('มีอยู่และเขียนได้'):__('มีอยู่แต่เขียนไม่ได้')):__('ไม่พบโฟลเดอร์')],
    ['label'=>'uploads/.htaccess','status'=>is_readable(AVSF_UPLOAD_DIR.'/.htaccess')?'ok':'warning','detail'=>is_readable(AVSF_UPLOAD_DIR.'/.htaccess')?__('พบไฟล์กฎป้องกันใน uploads'):__('ไม่พบหรืออ่านไฟล์ไม่ได้')],
    ['label'=>__('โฟลเดอร์ cache'),'status'=>is_dir(AVSF_CACHE_DIR)?(is_writable(AVSF_CACHE_DIR)?'ok':'warning'):'info','detail'=>is_dir(AVSF_CACHE_DIR)?(is_writable(AVSF_CACHE_DIR)?__('มีอยู่และเขียนได้'):__('มีอยู่แต่เขียนไม่ได้')):__('ยังไม่มีโฟลเดอร์แคช')],
    ['label'=>__('การตั้งค่า Cache'),'status'=>'info','detail'=>(setting('cache_enabled','0')==='1'?__('เปิด'):__('ปิด')).' · TTL '.(int)setting('cache_ttl','600').__(' วินาที')],
    ['label'=>__('ไฟล์กฎ .htaccess'),'status'=>is_readable($root.'/.htaccess')?'ok':'warning','detail'=>is_readable($root.'/.htaccess')?__('พบและอ่านได้'):__('ไม่พบหรืออ่านไฟล์ไม่ได้')],
    ['label'=>'robots / sitemap','status'=>is_readable($root.'/robots.php')&&is_readable($root.'/sitemap_index.php')&&is_readable($root.'/sitemap.php')?'ok':'error','detail'=>is_readable($root.'/robots.php')&&is_readable($root.'/sitemap_index.php')&&is_readable($root.'/sitemap.php')?__('ไฟล์ endpoint หลักอ่านได้'):__('ไฟล์ endpoint บางส่วนขาดหรืออ่านไม่ได้')],
    ['label'=>__('ค่า Robots เริ่มต้น'),'status'=>'info','detail'=>'robots meta: '.trim((string)setting('seo_robots','index, follow')).__(' · หน้าแรก: ').trim((string)setting('seo_index_home',__('ตามค่าเริ่มต้น')))],
    ['label'=>__('งานเบื้องหลัง / cron'),'status'=>'info','detail'=>__('แอปไม่มี heartbeat สำหรับ worker/cron จึงยืนยันสถานะงานเบื้องหลังแบบเรียลไทม์ไม่ได้')],
    ['label'=>__('ประวัติสแกนความปลอดภัย'),'status'=>'info','detail'=>__('การสแกนเริ่มจากผู้ดูแล และยังไม่มีตารางเก็บผล/เวลาสแกนล่าสุด')],
];
$quarantineFiles=glob(AVSF_QUARANTINE_DIR.'/*');
$quarantineCount=0;
if(is_array($quarantineFiles))foreach($quarantineFiles as $quarantineFile){$qName=basename($quarantineFile);if(is_file($quarantineFile)&&!is_link($quarantineFile)&&!in_array($qName,['index.html','.htaccess'],true)&&substr($qName,-5)!=='.meta'&&is_file($quarantineFile.'.meta'))$quarantineCount++;}
$dashboardRuntimeChecks[]=['label'=>'Quarantine','status'=>'info','detail'=>number_format($quarantineCount).__(' ไฟล์ที่มี metadata ใน quarantine; ตัวเลขนี้ไม่ใช่ผลสแกนมัลแวร์')];
$siteModuleFiles=[__('หน้าหลังบ้าน')=>'../index.php',__('ศูนย์เว็บไซต์')=>'site-center.php',__('SEO และ Robots')=>'seo-center.php',__('Redirect และ 404')=>'redirects.php','Cache'=>'cache.php','Security Center'=>'security.php','File Manager'=>'file-manager.php','Backup & Restore'=>'backup-center.php','Error Log'=>'error-log-center.php',__('นำเข้าข้อมูล')=>'import.php',__('จัดการสมาชิก')=>'users.php'];
foreach($siteModuleFiles as $label=>$file){$modulePath=($file==='../index.php')?AVSF_ADMIN_DIR.'/index.php':AVSF_ADMIN_DIR.'/modules/'.$file;$moduleExists=is_readable($modulePath);$dashboardRuntimeChecks[]=['label'=>__('โมดูล').$label,'status'=>$moduleExists?'ok':'error','detail'=>$moduleExists?__('พบไฟล์และอ่านได้'):__('ไม่พบไฟล์หรืออ่านไฟล์ไม่ได้')];}
$systemDependencies=['permissions.php'=>is_readable($root.'/avsf-core/auth/permissions.php'),'security_lib.php'=>is_readable($root.'/avsf-core/security/security_lib.php'),'backup_manager.php'=>is_readable($root.'/avsf-core/backup/backup_manager.php'),'deployment_manager.php'=>is_readable($root.'/avsf-core/updates/deployment_manager.php'),'malware_scanner.php'=>is_readable($root.'/avsf-core/security/malware_scanner.php')];
foreach($systemDependencies as $dependency=>$available)$dashboardRuntimeChecks[]=['label'=>'Dependency '.$dependency,'status'=>$available?'ok':'error','detail'=>$available?__('พร้อมใช้งาน'):__('ไฟล์ dependency หายหรืออ่านไม่ได้')];
$dashboardRuntimeChecks[]=['label'=>'PHP ZipArchive','status'=>class_exists('ZipArchive')?'ok':'warning','detail'=>class_exists('ZipArchive')?__('รองรับ ZIP Backup/Restore'):__('ไม่พบ Extension Zip — Backup/Restore ZIP ใช้งานไม่ได้')];
$dashboardRuntimeChecks[]=['label'=>'PHP mysqli','status'=>extension_loaded('mysqli')?'ok':'error','detail'=>extension_loaded('mysqli')?__('พร้อมเชื่อมต่อ MySQL'):__('ไม่พบ Extension mysqli — AVSF เชื่อมต่อ MySQL ไม่ได้')];
$dashboardRuntimeChecks[]=['label'=>'PHP json','status'=>extension_loaded('json')?'ok':'error','detail'=>extension_loaded('json')?__('พร้อมใช้งาน'):__('ไม่พบ Extension json')];
$dashboardRuntimeChecks[]=['label'=>'PHP mbstring','status'=>extension_loaded('mbstring')?'ok':'error','detail'=>extension_loaded('mbstring')?__('พร้อมใช้งานสำหรับจัดการข้อความ UTF-8'):__('ไม่พบ Extension mbstring; ฟังก์ชันจัดการข้อความและการนำเข้าหลายส่วนจะทำงานไม่ได้')];
try{if(class_exists('AVSFBackupManager')){$backupProbe=new AVSFBackupManager($root);$backupPath=realpath($backupProbe->getBackupDir());$rootPath=realpath($root);$backupOutside=$backupPath&&$rootPath&&strpos($backupPath,rtrim($rootPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)!==0&&$backupPath!==$rootPath;$dashboardRuntimeChecks[]=['label'=>__('พื้นที่เก็บ Backup'),'status'=>$backupOutside&&is_writable($backupPath)?'ok':'warning','detail'=>$backupOutside?(is_writable($backupPath)?__('อยู่นอกเว็บไซต์และเขียนได้'):__('อยู่นอกเว็บไซต์แต่เขียนไม่ได้')):__('ยังยืนยันพื้นที่เก็บ Backup ภายนอกเว็บไม่ได้')];}}catch(Throwable $backupCheckError){$dashboardRuntimeChecks[]=['label'=>__('พื้นที่เก็บ Backup'),'status'=>'warning','detail'=>__('ตรวจไม่ได้: ').$backupCheckError->getMessage()];}
if($stats['database_mb']===null)$dashboardRuntimeChecks[]=['label'=>__('ขนาดฐานข้อมูล'),'status'=>'warning','detail'=>__('PHP อ่านขนาดจาก information_schema ไม่ได้')];
else $dashboardRuntimeChecks[]=['label'=>__('ขนาดฐานข้อมูล'),'status'=>'ok','detail'=>number_format($stats['database_mb'],1).__(' MB ตาม data + index')];
$dashboardChecks=array_merge($dashboardChecks,$dashboardRuntimeChecks);
$dashboardCheckCounts=['ok'=>0,'warning'=>0,'error'=>0,'info'=>0];
foreach($dashboardChecks as $check)if(isset($dashboardCheckCounts[$check['status']]))$dashboardCheckCounts[$check['status']]++;
$dashboardOverallStatus=$dashboardCheckCounts['error']>0?'error':($dashboardCheckCounts['warning']>0?'warning':($dashboardCheckCounts['info']>0?'info':'ok'));

// ====== จัดการ action จากปุ่มต่างๆ ======
$msg = ''; $msgType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    $action = sec_post('action');

    if ($action === 'save_admin_accent_quick') {
        requirePermission('settings.edit');
        $quickAccent = sec_post('admin_accent');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $quickAccent)) {
            $msg = __('รูปแบบสีหลังบ้านไม่ถูกต้อง'); $msgType = 'error';
        } elseif (!saveSetting('admin_accent', strtolower($quickAccent))) {
            error_log('AVSF Site Center accent save failed');
            $msg = __('บันทึกสีหลังบ้านไม่สำเร็จ'); $msgType = 'error';
        } else {
            logActivity('เปลี่ยนสี Accent หลังบ้าน');
            header('Location: site-center.php');
            exit;
        }
    } elseif ($action === 'clear_cache') {
        requirePermission('cache.manage');
        require_once AVSF_CORE_DIR . '/cache/cache_lib.php';
        $n = AVSFCache::clearAll(AVSF_CACHE_DIR);
        $msg = "ล้างแคชเรียบร้อย — $n ไฟล์";
    }
    elseif ($action === 'change_password') {
        $oldPass = sec_post('old_pass');
        $newPass = sec_post('new_pass');
        if (strlen($newPass) < 8) {
            $msg = __('รหัสผ่านใหม่ต้องอย่างน้อย 8 ตัวอักษร'); $msgType = 'error';
        } else {
            [$passwordChanged, $passwordMessage] = avsfSiteCenterChangePassword(
                $conn,
                $_SESSION['avsf_admin_username'] ?? '',
                $oldPass,
                $newPass
            );
            $msg = $passwordMessage;
            $msgType = $passwordChanged ? 'success' : 'error';
            // ตัวอย่าง:
            // $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=? AND password=?");
            // $hash = password_hash($newPass, PASSWORD_DEFAULT);
            // $stmt->bind_param('sis', $hash, $adminId, $oldHash);
        }
    }
    elseif ($action === 'backup_db') {
        requirePermission('backup.download');
        // สร้างไฟล์ .sql แล้วดาวน์โหลด
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="avsf-backup-' . date('Y-m-d-His') . '.sql"');
        header('Cache-Control: no-store, private');
        if (!$conn) {
            error_log('AVSF database backup failed: database connection is unavailable');
            http_response_code(500);
            exit;
        }
        if ($conn) {
            $tables = ['settings','studios','actors','tags','posts','banners','pages','comments','users','activity','login_attempts','redirects','cache_stats','404_log'];
            foreach ($tables as $t) {
                $result = $conn->query("SHOW CREATE TABLE `$t`");
                if (!$result) {
                    error_log('AVSF database backup failed to read schema for ' . $t . ': ' . $conn->error);
                    http_response_code(500);
                    exit;
                }
                if ($result) {
                    $row = $result->fetch_row();
                    echo "\n-- " . $t . " --\n" . $row[1] . ";\n\n";
                    $data = $conn->query("SELECT * FROM `$t`");
                    if (!$data) {
                        error_log('AVSF database backup failed to read rows for ' . $t . ': ' . $conn->error);
                        http_response_code(500);
                        exit;
                    }
                    while ($d = $data->fetch_assoc()) {
                        $columns = array_map(function($column) { return '`' . str_replace('`', '``', $column) . '`'; }, array_keys($d));
                        $values = array_map(function($value) use ($conn) {
                            return $value === null ? 'NULL' : "'" . $conn->real_escape_string((string)$value) . "'";
                        }, array_values($d));
                        echo "INSERT INTO `$t` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n";
                    }
                    $data->free();
                }
                $result->free();
            }
        }
        exit;
    }
    elseif ($action === 'change_domain') {
        requirePermission('sitecenter.tools');
        $oldDomain = sec_post('old_domain');
        $newDomain = sec_post('new_domain');
        $oldParts = parse_url($oldDomain);
        $newParts = parse_url($newDomain);
        $validUrl = function($parts) {
            return is_array($parts) && isset($parts['scheme'], $parts['host'])
                && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
        };
        if ($validUrl($oldParts) && $validUrl($newParts) && $conn) {
            $conn->begin_transaction();
            $affected = 0;
            $failed = false;
            foreach (['content','cover','posterUrl','videoUrl','embedUrl'] as $column) {
                $stmt = $conn->prepare("UPDATE posts SET `$column` = REPLACE(`$column`, ?, ?), updatedAt=NOW() WHERE LOCATE(?,`$column`)>0");
                if (!$stmt) {
                    error_log('AVSF domain replacement prepare failed: ' . $conn->error);
                    $failed = true;
                    break;
                }
                $stmt->bind_param('sss', $oldDomain, $newDomain, $oldDomain);
                if (!$stmt->execute()) {
                    error_log('AVSF domain replacement failed: ' . $stmt->error);
                    $failed = true;
                    $stmt->close();
                    break;
                }
                $affected += $stmt->affected_rows;
                $stmt->close();
            }
            if (!$failed) {
                $stmt = $conn->prepare('UPDATE settings SET svalue = REPLACE(svalue, ?, ?)');
                if (!$stmt) {
                    error_log('AVSF settings domain replacement prepare failed: ' . $conn->error);
                    $failed = true;
                } else {
                    $stmt->bind_param('ss', $oldDomain, $newDomain);
                    if (!$stmt->execute()) {
                        error_log('AVSF settings domain replacement failed: ' . $stmt->error);
                        $failed = true;
                    } else {
                        $affected += $stmt->affected_rows;
                    }
                    $stmt->close();
                }
            }
            if ($failed || !$conn->commit()) {
                $conn->rollback();
                $msg = __('เปลี่ยนโดเมนไม่สำเร็จ ระบบยกเลิกการเปลี่ยนแปลงทั้งหมดแล้ว'); $msgType = 'error';
            } else {
                $msg = "เปลี่ยนโดเมนเรียบร้อย — แก้ไขข้อมูล $affected แถว";
            }
        } else {
            $msg = __('กรุณากรอก URL เก่าและใหม่ให้ถูกต้อง (http:// หรือ https://)'); $msgType = 'error';
        }
    }
    elseif ($action === 'search_replace') {
        requirePermission('sitecenter.tools');
        $search = sec_post('search_text');
        $replace = sec_post('replace_text');
        if ($search && $conn) {
            $affected = 0;
            foreach (['content','title'] as $column) {
                $stmt = $conn->prepare("UPDATE posts SET `$column` = REPLACE(`$column`, ?, ?), updatedAt=NOW() WHERE LOCATE(?,`$column`)>0");
                if (!$stmt) {
                    error_log('AVSF search/replace prepare failed: ' . $conn->error);
                    $msg = __('ค้นหาและแทนที่ไม่สำเร็จ'); $msgType = 'error'; break;
                }
                $stmt->bind_param('sss', $search, $replace, $search);
                if (!$stmt->execute()) {
                    error_log('AVSF search/replace failed: ' . $stmt->error);
                    $msg = __('ค้นหาและแทนที่ไม่สำเร็จ'); $msgType = 'error';
                    $stmt->close(); break;
                }
                $affected += $stmt->affected_rows;
                $stmt->close();
            }
            if ($msgType !== 'error') $msg = "สแกน/แทนที่เรียบร้อย — แก้ไขข้อมูล $affected แถว";
        } else {
            $msg = __('กรุณากรอกข้อความที่ต้องการค้นหา'); $msgType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🏠 ศูนย์เว็บไซต์ — AVSF Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,'Noto Sans Thai',sans-serif;background:#f5f6f8;color:#1a1a1a;padding-bottom:40px;min-height:100vh}
.avsf-topbar{background:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.avsf-topbar .avsf-menu-btn{font-size:22px;background:none;border:none;cursor:pointer;color:#333}
.avsf-topbar .avsf-domain{font-size:17px;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.avsf-user-meta{text-align:right;min-width:0}.avsf-user-meta strong,.avsf-user-meta span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.avsf-user-meta strong{font-size:13px}.avsf-user-meta span{font-size:11px;color:#69707c}
.avsf-container{max-width:600px;margin:0 auto;padding:16px}

/* สถิติบนสุด */
.avsf-stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}
.avsf-stat-card{background:#fff;border-radius:14px;padding:14px 10px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.avsf-stat-card .avsf-num{font-size:20px;font-weight:800;color:#1a1a1a}
.avsf-stat-card .avsf-label{font-size:11px;color:#8a8f98;margin-top:3px}

/* Section header */
.avsf-section-title{font-size:13px;font-weight:700;color:#8a8f98;text-transform:none;margin:22px 4px 10px;letter-spacing:.2px}

/* Card เมนู */
.avsf-menu-card{display:flex;align-items:center;gap:14px;background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:8px;box-shadow:0 1px 3px rgba(0,0,0,.05);text-decoration:none;color:inherit;transition:all .15s;cursor:pointer;border:none;width:100%;text-align:left;font-size:15px}
.avsf-menu-card:active{transform:scale(.98);background:#f0f1f3}
.avsf-menu-card .avsf-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.avsf-menu-card .avsf-info{flex:1;min-width:0}
.avsf-menu-card .avsf-title{font-size:15px;font-weight:600;color:#1a1a1a}
.avsf-menu-card .avsf-desc{font-size:12px;color:#8a8f98;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.avsf-menu-card .avsf-arrow{color:#c0c4cc;font-size:18px;flex-shrink:0}
.avsf-menu-card .avsf-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;margin-left:6px}
.avsf-badge-green{background:#dcfce7;color:#16a34a}
.avsf-badge-red{background:#fee2e2;color:#dc2626}
.avsf-badge-blue{background:#dbeafe;color:#2563eb}
.avsf-badge-gray{background:#f1f5f9;color:#64748b}

/* สี icon background ต่างๆ */
.avsf-icon-blue{background:#dbeafe;color:#2563eb}
.avsf-icon-green{background:#dcfce7;color:#16a34a}
.avsf-icon-orange{background:#ffedd5;color:#ea580c}
.avsf-icon-purple{background:#f3e8ff;color:#9333ea}
.avsf-icon-red{background:#fee2e2;color:#dc2626}
.avsf-icon-cyan{background:#cffafe;color:#0891b2}
.avsf-icon-AVSF{background:#fce7f3;color:#db2777}
.avsf-icon-yellow{background:#fef9c3;color:#ca8a04}
.avsf-icon-indigo{background:#e0e7ff;color:#4f46e5}
.avsf-icon-gray{background:#f1f5f9;color:#475569}

.avsf-msg{padding:12px 16px;border-radius:10px;margin-bottom:14px;font-size:14px}
.avsf-msg.avsf-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.avsf-msg.avsf-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}

/* Modal */
.avsf-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:flex-end;justify-content:center}
.avsf-modal-overlay.avsf-active{display:flex}
.avsf-modal{background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:600px;padding:24px 20px 32px;max-height:80vh;overflow-y:auto;animation:slideUp .25s ease}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.avsf-modal h3{font-size:18px;margin-bottom:16px}
.avsf-modal label{display:block;font-size:13px;color:#666;margin:12px 0 6px}
.avsf-modal input{width:100%;padding:11px 14px;border:1px solid #e0e0e0;border-radius:10px;font-size:14px;background:#f9fafb}
.avsf-modal .avsf-btn-row{display:flex;gap:10px;margin-top:20px}
.avsf-modal .avsf-btn{flex:1;padding:12px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer}
.avsf-btn-primary{background:#2563eb;color:#fff}
.avsf-btn-secondary{background:#f1f5f9;color:#334155}
.avsf-modal-close{position:absolute;top:16px;right:20px;font-size:24px;background:none;border:none;cursor:pointer;color:#999}

.avsf-back{display:inline-flex;align-items:center;gap:6px;color:#6b7280;text-decoration:none;font-size:14px;margin-bottom:12px}
.avsf-back:hover{color:#2563eb}

.avsf-notice{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:12px 14px;font-size:12px;color:#92400e;margin-bottom:16px;line-height:1.6}
.avsf-site-shell{min-height:100vh;display:grid;grid-template-columns:250px minmax(0,1fr);--site-accent:<?= e($adminAccent) ?>;background:#f5f7fb;color:#17233b}
.avsf-site-sidebar{position:sticky;top:0;height:100vh;overflow-y:auto;background:#fff;color:#23314c;padding:12px;scrollbar-width:thin;border-right:1px solid #e6eaf1}
.avsf-site-sidebar-head{position:sticky;top:-12px;z-index:5;margin:-12px -4px 8px;padding:14px 4px 10px;background:#fff;border-bottom:1px solid #e9edf4}
.avsf-site-brand{display:flex;align-items:center;gap:9px;min-height:48px;padding:0 8px 10px;color:#17233b}.avsf-site-brand img{display:block;max-width:190px;max-height:48px;width:auto;height:auto;object-fit:contain}.avsf-site-brand .avsf-site-brand-name{font-weight:850;font-size:15px;overflow-wrap:anywhere}.avsf-site-brand .avsf-site-brand-caption{margin-left:auto;color:#7a879b;font-size:10px;font-weight:600;white-space:nowrap}@media(max-width:520px){.avsf-site-brand img{max-width:145px;max-height:42px}.avsf-site-brand .avsf-site-brand-caption{font-size:9px}}
.avsf-site-user{display:flex;align-items:center;gap:9px;padding:10px;border:1px solid #e6ebf2;border-radius:10px;background:#f8faff;color:#27364f;min-width:0}
.avsf-site-user-dot{width:9px;height:9px;flex:0 0 9px;border-radius:50%;background:#20c77a}
.avsf-site-user-mark{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border-radius:10px;background:#edf2fa;color:#64748b}
.avsf-site-user-mark svg{width:30px;height:30px;display:block}
.avsf-site-user-role-owner{border-color:rgba(236,159,39,.85);background:linear-gradient(120deg,#fff9e9,#fff2d4);color:#74370e;box-shadow:0 0 0 1px rgba(236,159,39,.2),0 0 18px rgba(232,137,34,.18);position:relative}
.avsf-site-user-role-owner:after{content:"";position:absolute;inset:-3px;border:1px solid rgba(236,159,39,.6);border-radius:13px;pointer-events:none;animation:avsfSiteOwnerGlow 3s linear infinite}
.avsf-site-user-role-super_admin{border-color:rgba(157,108,237,.6)}.avsf-site-user-role-deputy_admin{border-color:rgba(67,151,242,.6)}.avsf-site-user-role-editor{border-color:rgba(34,190,202,.6)}.avsf-site-user-role-author{border-color:rgba(59,185,106,.6)}.avsf-site-user-role-moderator{border-color:rgba(29,180,157,.6)}.avsf-site-user-role-custom{border-color:rgba(140,110,220,.6)}.avsf-site-user-role-user{border-color:rgba(155,166,182,.6)}
@keyframes avsfSiteOwnerGlow{0%,100%{box-shadow:0 0 5px rgba(247,185,72,.35)}50%{box-shadow:0 0 15px rgba(239,134,29,.55)}}
.avsf-site-user strong,.avsf-site-user small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.avsf-site-user strong{font-size:12px}.avsf-site-user small{font-size:10px;color:#7a879b}
.avsf-site-quick-controls{display:flex;align-items:center;gap:7px;margin-top:8px}
.avsf-site-quick-controls button{flex:1;min-height:35px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#41516a;font:inherit;font-size:12px}
.avsf-site-color-control{display:flex;align-items:center;justify-content:center;gap:6px;min-height:35px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#41516a;font-size:11px}
.avsf-site-color-control input{width:24px;height:24px;padding:0;border:0;background:transparent;cursor:pointer}
.avsf-site-nav{display:grid;gap:3px;margin-top:8px}
.avsf-site-nav>a,.avsf-site-nav summary{display:flex;align-items:center;gap:9px;min-height:40px;padding:9px 11px;border-radius:8px;color:#58677f;text-decoration:none;font-size:12px;cursor:pointer;list-style:none;transition:background .15s,color .15s}
.avsf-site-nav>a .avsf-site-nav-icon,.avsf-site-nav summary .avsf-site-nav-icon,.avsf-site-nav .avsf-site-action-item .avsf-site-nav-icon{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;flex:0 0 18px;color:#7a8aa3}
.avsf-site-nav-icon svg{display:block;width:17px;height:17px}
.avsf-site-nav summary::-webkit-details-marker{display:none}
.avsf-site-nav summary:hover,.avsf-site-nav>a:hover,.avsf-site-nav>a.avsf-active{background:#f0f4ff;color:#3159a8}
.avsf-site-nav>a.avsf-active{box-shadow:inset 3px 0 var(--site-accent);font-weight:700}
.avsf-site-nav>a:hover .avsf-site-nav-icon,.avsf-site-nav>a.avsf-active .avsf-site-nav-icon,.avsf-site-nav summary:hover .avsf-site-nav-icon{color:var(--site-accent)}
.avsf-site-nav details{border-bottom:1px solid #edf0f5;padding-bottom:4px;margin-bottom:2px}
.avsf-site-nav summary:after{content:"";width:6px;height:6px;margin-left:auto;border-top:2px solid #8491a8;border-right:2px solid #8491a8;transform:rotate(45deg);transition:transform .15s}
.avsf-site-nav details[open]>summary:after{transform:rotate(135deg)}
.avsf-site-nav summary .avsf-menu-dot{width:5px;height:5px;margin-left:auto;border-radius:50%;background:#a6b0c1}
.avsf-site-nav summary:after{margin-left:0}
.avsf-site-nav details[open]>summary .avsf-menu-dot{display:none}
.avsf-site-nav .avsf-site-subnav{display:grid;gap:2px;padding:0 0 5px 13px}
.avsf-site-nav .avsf-site-subnav a{display:flex;align-items:center;gap:8px;padding:8px 10px;border-left:2px solid #e2e8f0;color:#65748b;text-decoration:none;font-size:11px}
.avsf-site-nav .avsf-site-subnav a:hover,.avsf-site-nav .avsf-site-subnav a.avsf-active{border-left-color:var(--site-accent);background:color-mix(in srgb,var(--site-accent) 8%,transparent);color:#243b69}
.avsf-site-nav .avsf-site-subnav a[data-center-panel],.avsf-site-nav .avsf-site-action-item{cursor:pointer}
.avsf-site-nav .avsf-site-action-item{display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:0;border-left:2px solid #e2e8f0;border-radius:0;background:transparent;color:#65748b;text-align:left;font:inherit;font-size:11px}
.avsf-site-nav .avsf-site-action-item:hover{border-left-color:var(--site-accent);background:color-mix(in srgb,var(--site-accent) 8%,transparent);color:#243b69}
.avsf-site-nav .avsf-site-nav-action-form{margin:0}
.avsf-site-nav-primary-action{min-height:38px!important;margin:3px 0;padding:9px 11px!important;border:1px solid #e6ebf2!important;border-radius:9px!important;background:#f8faff!important;color:#31415b!important;font-size:11px!important;font-weight:650!important}
.avsf-site-nav-primary-action:hover{border-color:color-mix(in srgb,var(--site-accent) 35%,#e6ebf2)!important;background:color-mix(in srgb,var(--site-accent) 8%,#fff)!important;color:#243b69!important}
.avsf-site-nav-primary-action .avsf-site-nav-icon{color:var(--site-accent)!important}
.avsf-site-shell[data-theme=dark] .avsf-site-nav-primary-action{background:#1a2030!important;border-color:#303748!important;color:#e5e7eb!important}
.avsf-site-shell[data-theme=dark] .avsf-site-nav-primary-action:hover{background:#202a3a!important;color:#fff!important}
.avsf-site-dashboard-panel>.avsf-section-title,.avsf-site-dashboard-panel>.avsf-section-title~*{display:none!important}
.avsf-site-dashboard-panel[hidden],.avsf-site-panel-frame[hidden],.avsf-site-inline-tool-panel[hidden]{display:none!important}
.avsf-site-inline-tool-panel{padding:24px 20px}
.avsf-site-inline-tool-panel .avsf-modal{position:relative;width:100%;max-width:680px;margin:0 auto;border-radius:14px;max-height:none;overflow:visible;animation:none}
.avsf-site-panel-frame{display:block;width:100%;height:72vh;min-height:640px;border:0;border-radius:12px;background:#fff}
.avsf-site-panel-status{margin:10px 14px;padding:10px 13px;border:1px solid #f0c987;border-radius:10px;background:#fff8e8;color:#805b16;font-size:13px;line-height:1.6}.avsf-site-panel-status[hidden]{display:none!important}.avsf-site-panel-fallback-link{display:inline-flex;margin-left:8px;font-weight:700;color:#2456a6;text-decoration:underline}
.avsf-site-main{min-width:0}
.avsf-site-topbar{position:sticky;top:0;z-index:10;min-height:58px;display:flex;align-items:center;gap:12px;padding:8px 20px;background:rgba(255,255,255,.96);border-bottom:1px solid #e5eaf2;backdrop-filter:blur(12px)}
.avsf-site-topbar button{min-height:36px;padding:6px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#202633;font:inherit;cursor:pointer}
.avsf-site-topbar-title{font-size:13px;font-weight:700;flex:1}
.avsf-site-topbar-user{font-size:11px;color:#586273}
.avsf-site-menu-toggle{display:none}
.avsf-site-shell .avsf-container{max-width:1180px;padding:20px}
.avsf-site-shell .avsf-stats-row{grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
.avsf-site-shell .avsf-stat-card{border:1px solid #e8edf5;border-radius:13px;box-shadow:0 3px 12px rgba(31,52,87,.045);transition:transform .15s,box-shadow .15s}
.avsf-site-shell .avsf-stat-card:hover{transform:translateY(-2px);box-shadow:0 7px 18px rgba(31,52,87,.08)}
.avsf-site-shell .avsf-stat-card .avsf-num{color:#1e3a65}
.avsf-site-shell .avsf-stat-card .avsf-label{color:#738199}
.avsf-site-shell .avsf-menu-card{max-width:100%}
.avsf-system-status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}
.avsf-system-status-card{display:flex;flex-direction:column;gap:6px;min-width:0;padding:13px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.avsf-system-status-card strong{display:flex;align-items:center;gap:8px;font-size:13px}.avsf-system-status-card span{font-size:12px;font-weight:700;color:#374151}.avsf-system-status-card .avsf-site-status-icon{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;border-radius:8px;background:#eff4ff;color:#3974db}.avsf-site-status-icon svg{width:15px;height:15px}.avsf-system-status-card small{font-size:10px;color:#7a8392;line-height:1.55}
.avsf-site-details-panel{margin-top:16px;padding:17px;border:1px solid #e8edf5;border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(31,52,87,.045)}
.avsf-site-details-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:13px}
.avsf-site-details-heading h2{font-size:15px;color:#263957}
.avsf-site-details-heading p{margin-top:3px;color:#8190a6;font-size:11px}
.avsf-site-runtime-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.avsf-site-runtime-item{min-width:0;padding:10px 12px;border:1px solid #edf0f5;border-radius:10px;background:#fbfcfe}
.avsf-site-runtime-item span,.avsf-site-runtime-item strong{display:block;overflow-wrap:anywhere}
.avsf-site-runtime-item span{color:#7a879b;font-size:10px}
.avsf-site-runtime-item strong{margin-top:4px;color:#263957;font-size:12px}
.avsf-site-details-note{margin-top:12px;color:#718096;font-size:10px;line-height:1.6}
.avsf-site-health-summary{display:flex;align-items:center;gap:8px;margin:-4px 0 16px;color:#718096;font-size:11px}
.avsf-site-health-dot{width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:#20b879}
.avsf-site-health-summary.avsf-is-warning .avsf-site-health-dot{background:#e5a11a}
.avsf-site-health-summary.avsf-is-error .avsf-site-health-dot{background:#e04456} .avsf-site-health-summary.avsf-is-info .avsf-site-health-dot{background:#4779cf}
.avsf-site-dashboard-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
.avsf-site-metric-card{min-width:0;padding:14px;border:1px solid #e8edf5;border-radius:13px;background:#fff;box-shadow:0 3px 12px rgba(31,52,87,.045)}
.avsf-site-metric-card span,.avsf-site-metric-card strong,.avsf-site-metric-card small{display:block}
.avsf-site-metric-card span{color:#738199;font-size:10px}
.avsf-site-metric-card strong{margin-top:5px;color:#1e3a65;font-size:21px;line-height:1.2}
.avsf-site-metric-card small{margin-top:4px;color:#8794a8;font-size:9px}
.avsf-site-dashboard-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:12px;margin:12px 0}
.avsf-site-dashboard-card{min-width:0;padding:16px;border:1px solid #e8edf5;border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(31,52,87,.045)}
.avsf-site-dashboard-card h2,.avsf-site-details-panel h2{color:#263957;font-size:14px;font-weight:750}
.avsf-site-dashboard-card>p{margin-top:4px;color:#8190a6;font-size:10px;line-height:1.6}
.avsf-site-index-chart{display:grid;grid-template-columns:170px minmax(0,1fr);align-items:center;gap:16px;margin-top:12px}
.avsf-site-donut{position:relative;display:grid;place-items:center;width:162px;aspect-ratio:1;margin:auto;border-radius:50%;background:conic-gradient(#2878ec 0deg var(--index-end),#f0aa38 var(--index-end) 360deg)}
.avsf-site-donut:before{content:"";position:absolute;inset:23%;border-radius:50%;background:#fff;box-shadow:inset 0 0 0 1px #edf1f7}
.avsf-site-donut-center{position:relative;z-index:1;text-align:center}
.avsf-site-donut-center strong,.avsf-site-donut-center span{display:block}
.avsf-site-donut-center strong{color:#21395e;font-size:22px;line-height:1.2}
.avsf-site-donut-center span{color:#7b899e;font-size:10px}
.avsf-site-chart-legend{display:grid;gap:9px}
.avsf-site-chart-legend-item{display:grid;grid-template-columns:9px minmax(0,1fr) auto;align-items:center;gap:8px;color:#53637c;font-size:11px}
.avsf-site-chart-legend-item strong{color:#273b5d;font-size:11px}
.avsf-site-chart-swatch{width:9px;height:9px;border-radius:50%}
.avsf-site-chart-swatch.avsf-is-index{background:#2878ec}.avsf-site-chart-swatch.avsf-is-noindex{background:#f0aa38}
.avsf-site-inventory-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
.avsf-site-inventory-item{padding:10px;border:1px solid #edf0f5;border-radius:10px;background:#fbfcfe}
.avsf-site-inventory-item span,.avsf-site-inventory-item strong,.avsf-site-inventory-item small{display:block}
.avsf-site-inventory-item span{color:#7a879b;font-size:10px}
.avsf-site-inventory-item strong{margin-top:4px;color:#263957;font-size:14px}
.avsf-site-inventory-item small{margin-top:3px;color:#8794a8;font-size:9px;line-height:1.5}
.avsf-site-report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:12px 0}
.avsf-site-report-card{min-width:0;padding:16px;border:1px solid #e8edf5;border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(31,52,87,.045)}
.avsf-site-report-heading{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
.avsf-site-report-heading h2{color:#263957;font-size:14px}
.avsf-site-report-heading span{color:#718096;font-size:10px}
.avsf-site-report-list{display:grid;gap:7px}
.avsf-site-report-row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #edf0f5;font-size:10px}
.avsf-site-report-row:last-child{border-bottom:0}
.avsf-site-report-row-main{min-width:0;overflow-wrap:anywhere}
.avsf-site-report-row-main strong,.avsf-site-report-row-main small{display:block}
.avsf-site-report-row-main strong{color:#34445f;font-size:10px;font-weight:650}
.avsf-site-report-row-main small{margin-top:2px;color:#8794a8;font-size:9px}
.avsf-site-report-count{flex:0 0 auto;color:#52637d;font-size:10px}
.avsf-site-empty-state{padding:14px 0;color:#7a879b;font-size:10px}
.avsf-site-check-summary{display:flex;flex-wrap:wrap;gap:7px;margin:10px 0}
.avsf-site-check-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;background:#ecfdf5;color:#18784d;font-size:10px}
.avsf-site-check-pill.avsf-is-warning{background:#fff7e6;color:#9a6408}
.avsf-site-check-pill.avsf-is-error{background:#fff0f0;color:#b42331}
.avsf-site-check-pill.avsf-is-info{background:#eef4ff;color:#3159a8}
.avsf-site-check-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}
.avsf-site-check-item{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:8px 9px;border:1px solid #edf0f5;border-radius:9px;background:#fbfcfe}
.avsf-site-check-item strong,.avsf-site-check-item small{display:block}
.avsf-site-check-item strong{color:#34445f;font-size:10px;font-weight:650}
.avsf-site-check-item small{margin-top:2px;color:#8794a8;font-size:9px;line-height:1.5}
.avsf-site-check-status{flex:0 0 auto;color:#168253;font-size:9px;font-weight:700}
.avsf-site-check-status.avsf-is-warning{color:#a46b05}.avsf-site-check-status.avsf-is-error{color:#c5303f}.avsf-site-check-status.avsf-is-info{color:#3159a8}
.avsf-site-report-note{margin-top:10px;color:#8190a6;font-size:9px;line-height:1.6}
.avsf-site-dashboard-metrics .avsf-site-metric-card.avsf-is-alert strong{color:#c5303f}
.avsf-site-shell[data-theme=dark] .avsf-site-dashboard-card,.avsf-site-shell[data-theme=dark] .avsf-site-metric-card,.avsf-site-shell[data-theme=dark] .avsf-site-report-card{background:#151c29;border-color:#283244;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-dashboard-card h2,.avsf-site-shell[data-theme=dark] .avsf-site-report-heading h2,.avsf-site-shell[data-theme=dark] .avsf-site-donut-center strong,.avsf-site-shell[data-theme=dark] .avsf-site-metric-card strong,.avsf-site-shell[data-theme=dark] .avsf-site-inventory-item strong,.avsf-site-shell[data-theme=dark] .avsf-site-report-row-main strong,.avsf-site-shell[data-theme=dark] .avsf-site-check-item strong{color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-dashboard-card>p,.avsf-site-shell[data-theme=dark] .avsf-site-metric-card span,.avsf-site-shell[data-theme=dark] .avsf-site-metric-card small,.avsf-site-shell[data-theme=dark] .avsf-site-chart-legend-item,.avsf-site-shell[data-theme=dark] .avsf-site-chart-legend-item strong,.avsf-site-shell[data-theme=dark] .avsf-site-report-row-main small,.avsf-site-shell[data-theme=dark] .avsf-site-check-item small,.avsf-site-shell[data-theme=dark] .avsf-site-report-count{color:#9aa8be}
.avsf-site-shell[data-theme=dark] .avsf-site-inventory-item,.avsf-site-shell[data-theme=dark] .avsf-site-check-item{background:#111827;border-color:#283244}
.avsf-site-shell[data-theme=dark] .avsf-site-donut:before{background:#151c29;box-shadow:inset 0 0 0 1px #283244}
.avsf-site-shell[data-theme=dark] .avsf-site-report-row{border-color:#283244}
.avsf-site-shell[data-theme=dark] .avsf-site-health-summary{color:#aab5c7}
.avsf-site-theme-settings:hover{filter:brightness(1.15)}
.avsf-site-shell[data-theme=dark]{background:#090b10;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-main{background:#090b10}
.avsf-site-shell[data-theme=dark] .avsf-site-topbar{background:rgba(17,24,39,.94);border-color:#283244;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-topbar button{background:#1a2030;border-color:#303748;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-sidebar,.avsf-site-shell[data-theme=dark] .avsf-site-sidebar-head{background:#111827;color:#e5e7eb;border-color:#283244}
.avsf-site-shell[data-theme=dark] .avsf-site-brand{color:#e5e7eb}.avsf-site-shell[data-theme=dark] .avsf-site-brand .avsf-site-brand-caption{color:#9aa8bd}
.avsf-site-shell[data-theme=dark] .avsf-site-user,.avsf-site-shell[data-theme=dark] .avsf-site-quick-controls button,.avsf-site-shell[data-theme=dark] .avsf-site-color-control{background:#1a2030;border-color:#303748;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-user small{color:#9ca3af}.avsf-site-shell[data-theme=dark] .avsf-site-user-role-owner{background:linear-gradient(120deg,#382a16,#2b2018);color:#ffe2a6;border-color:#b77727}.avsf-site-shell[data-theme=dark] .avsf-site-user-mark{background:#202a3a}
.avsf-site-shell[data-theme=dark] .avsf-site-nav details{border-color:#252b37}
.avsf-site-shell[data-theme=dark] .avsf-site-nav>a,.avsf-site-shell[data-theme=dark] .avsf-site-nav summary{color:#b8c2d4}
.avsf-site-shell[data-theme=dark] .avsf-site-nav summary:hover,.avsf-site-shell[data-theme=dark] .avsf-site-nav>a:hover,.avsf-site-shell[data-theme=dark] .avsf-site-nav>a.avsf-active{background:#202a3a;color:#fff}
.avsf-site-shell[data-theme=dark] .avsf-site-nav .avsf-site-subnav a,.avsf-site-shell[data-theme=dark] .avsf-site-nav .avsf-site-action-item{border-color:#303748;color:#aab5c7}
.avsf-site-shell[data-theme=dark] .avsf-site-nav .avsf-site-subnav a:hover,.avsf-site-shell[data-theme=dark] .avsf-site-nav .avsf-site-subnav a.avsf-active,.avsf-site-shell[data-theme=dark] .avsf-site-nav .avsf-site-action-item:hover{background:color-mix(in srgb,var(--site-accent) 12%,transparent);color:#fff}
.avsf-site-shell[data-theme=dark] .avsf-site-nav-icon{color:#9aa8be}
.avsf-site-shell[data-theme=dark] .avsf-site-status-icon{background:#1e2b43!important;color:#79a8ff!important}
.avsf-site-shell[data-theme=dark] .avsf-site-panel-status{background:#342b1c;border-color:#725a2e;color:#f4d69a}
.avsf-site-shell[data-theme=dark] .avsf-site-details-panel{background:#151c29;border-color:#283244}
.avsf-site-shell[data-theme=dark] .avsf-site-details-heading h2,.avsf-site-shell[data-theme=dark] .avsf-site-runtime-item strong{color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-runtime-item{background:#111827;border-color:#283244}
.avsf-site-shell[data-theme=dark] .avsf-site-runtime-item span,.avsf-site-shell[data-theme=dark] .avsf-site-details-heading p,.avsf-site-shell[data-theme=dark] .avsf-site-details-note{color:#9aa8be}
.avsf-site-shell[data-theme=dark] .avsf-stat-card,.avsf-site-shell[data-theme=dark] .avsf-menu-card,.avsf-site-shell[data-theme=dark] .avsf-modal{background:#151c29;color:#e5e7eb;border:1px solid #283244}
.avsf-site-shell[data-theme=dark] .avsf-menu-card .avsf-title,.avsf-site-shell[data-theme=dark] .avsf-stat-card .avsf-num{color:#e5e7eb}
@media(max-width:850px){.avsf-site-shell{grid-template-columns:1fr}.avsf-site-sidebar{position:fixed;left:0;top:0;z-index:150;width:min(290px,86vw);transform:translateX(-105%);transition:transform .2s;box-shadow:0 0 0 100vmax transparent;visibility:hidden}.avsf-site-sidebar.avsf-open{transform:none;visibility:visible;box-shadow:0 0 0 100vmax rgba(0,0,0,.4)}.avsf-site-menu-toggle{display:inline-flex!important}.avsf-site-shell .avsf-stats-row{grid-template-columns:repeat(3,minmax(0,1fr))}.avsf-system-status-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-site-shell .avsf-container{padding:14px}.avsf-site-topbar{padding:8px 12px}.avsf-site-topbar-user{display:none}.avsf-site-panel-frame{height:calc(100dvh - 96px);min-height:560px}.avsf-site-dashboard-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.avsf-site-dashboard-grid{grid-template-columns:1fr}.avsf-site-report-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.avsf-site-user-role-owner:after{animation:none}}
@media(max-width:560px){.avsf-site-shell .avsf-stats-row,.avsf-site-runtime-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-site-details-panel{padding:13px}.avsf-site-details-heading h2{font-size:14px}.avsf-site-dashboard-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-site-index-chart{grid-template-columns:1fr}.avsf-site-donut{width:148px}.avsf-site-inventory-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-site-check-list{grid-template-columns:1fr}}
@media(max-width:420px){.avsf-system-status-grid{grid-template-columns:1fr}.avsf-site-shell .avsf-menu-card{padding:12px}.avsf-site-shell .avsf-menu-card .avsf-icon{width:36px;height:36px}}

/* AVSF responsive viewport correction: Site Center and embedded tools */
html,body{width:100%;max-width:none;min-width:0;margin-left:0;margin-right:0;overflow-x:clip}
.avsf-site-shell{width:100%;max-width:none;min-width:0;grid-template-columns:clamp(220px,22vw,270px) minmax(0,1fr)}
.avsf-site-main{width:100%;max-width:none;min-width:0}
.avsf-site-shell .avsf-site-main>.avsf-container{width:100%;max-width:none;margin:0;padding:clamp(14px,2vw,24px)}
.avsf-site-panel-frame{width:100%;max-width:100%;min-width:0}
.avsf-site-dashboard-panel,.avsf-site-inline-tool-panel{width:100%;max-width:100%;min-width:0}
.avsf-site-dashboard-grid,.avsf-site-dashboard-card,.avsf-site-report-card,.avsf-site-details-panel{min-width:0;max-width:100%}
@media(max-width:1100px){.avsf-site-shell .avsf-stats-row{grid-template-columns:repeat(3,minmax(0,1fr))}.avsf-system-status-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-site-dashboard-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:850px){.avsf-site-shell{width:100%;grid-template-columns:minmax(0,1fr)}.avsf-site-shell .avsf-site-main>.avsf-container{padding:clamp(10px,2vw,16px)}}
@media(max-width:560px){.avsf-site-shell .avsf-site-main>.avsf-container{padding:10px}.avsf-site-shell .avsf-stats-row,.avsf-system-status-grid,.avsf-site-dashboard-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
/* Shared global admin dock replaces the previous Site Center floating rail. */
.avsf-site-mobile-dock{display:none!important}
/* v3.2.2: Site Center uses the same full-width content behavior as Admin.
   Floating controls overlay the page instead of reserving a blank right gutter. */
.avsf-site-shell{width:100%;max-width:100%;min-width:0;margin:0}
.avsf-site-main{display:block;width:100%;max-width:100%;min-width:0;min-height:100vh;min-height:100dvh;padding:0 0 40px!important;box-sizing:border-box;overflow-x:clip}
.avsf-site-main>.avsf-site-topbar{width:100%;max-width:none}
.avsf-site-main>.avsf-container{display:block;width:100%;max-width:none;min-width:0;margin:0;padding:clamp(12px,1.8vw,22px)}
.avsf-site-main>.avsf-site-panel-frame,.avsf-site-main>.avsf-site-inline-tool-panel{width:100%;max-width:100%;min-width:0}
.avsf-site-main>*{min-width:0;max-width:100%}
@media(max-width:850px){
  .avsf-site-shell{grid-template-columns:minmax(0,1fr)}
  .avsf-site-main{width:100%;max-width:100%;padding:0 0 28px!important}
  .avsf-site-main>.avsf-container{padding:12px}
  .avsf-site-topbar{width:100%;padding-right:12px}
}
@media(max-width:560px){
  .avsf-site-main>.avsf-container{padding:10px}
}
/* v3.2.4 responsive correction: keep the iframe at the full viewport width.
   The floating control rail is an overlay, so reserve its space inside content,
   not by shrinking the iframe/container itself. */
@media(max-width:850px){
  .avsf-site-main>.avsf-container{
    box-sizing:border-box!important;width:100%!important;max-width:100%!important;
    min-width:0!important;padding:12px!important;
  }
  .avsf-site-main>.avsf-site-panel-frame,
  .avsf-site-main>.avsf-site-inline-tool-panel{
    box-sizing:border-box!important;width:100%!important;max-width:100%!important;
    min-width:0!important;margin:0!important;
  }
  .avsf-site-panel-frame{display:block;min-width:0!important}
}
@media(max-width:560px){
  .avsf-site-main>.avsf-container{padding:10px!important}
}
@media(max-width:380px){
  .avsf-site-shell .avsf-stats-row,
  .avsf-system-status-grid,
  .avsf-site-dashboard-metrics,
  .avsf-site-inventory-grid{grid-template-columns:minmax(0,1fr)!important}
}

.avsf-site-dashboard-note{margin:18px 0 4px;padding:15px 17px;border:1px solid #e4eaf3;border-radius:14px;background:linear-gradient(135deg,#fff 0%,#f8faff 100%);color:#253653}
.avsf-site-dashboard-note strong{display:block;font-size:14px;font-weight:800;margin-bottom:5px}
.avsf-site-dashboard-note p{margin:0;font-size:12px;line-height:1.75;color:#718096}
.avsf-site-shell[data-theme=dark] .avsf-site-dashboard-note{background:#151c29;border-color:#30394a;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-site-dashboard-note p{color:#aab6ca}
@media(max-width:560px){.avsf-site-dashboard-note{margin:12px 0 4px;padding:13px;border-radius:12px}}

/* v3.2.4 Site Center isolation fix: hidden panels must never be forced visible by layout rules. */
.avsf-site-main > [hidden]{display:none!important}
</style>
<link rel="stylesheet" href="admin-ui.css?v=3.2.5-adminseo1">
<style id="avsf-site-center-panel-visibility-fix">
/* Keep hidden Site Center panels hidden even when admin-ui.css defines layout display rules later. */
.avsf-site-main > .avsf-site-dashboard-panel[hidden],
.avsf-site-main > .avsf-site-panel-status[hidden],
.avsf-site-main > .avsf-site-panel-frame[hidden],
.avsf-site-main > .avsf-site-inline-tool-panel[hidden] {
    display: none !important;
    visibility: hidden !important;
}

/* AVSF Live Observability Dashboard */

/* AVSF Live Observability Dashboard */
.avsf-observe-shell{margin:16px 0 22px;padding:18px;border:1px solid #e2eaf5;border-radius:18px;background:linear-gradient(155deg,#fff 0%,#f8fbff 100%);box-shadow:0 5px 20px rgba(31,52,87,.055);color:#243651}
.avsf-observe-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
.avsf-observe-eyebrow{font-size:9px;font-weight:800;letter-spacing:.14em;color:#4784dc;margin-bottom:4px}
.avsf-observe-heading h2{font-size:19px;line-height:1.3;color:#223653;font-weight:800}
.avsf-observe-heading p{margin:4px 0 8px;color:#71819a;font-size:11px;line-height:1.55}
.avsf-observe-meta{display:flex;flex-wrap:wrap;gap:8px 13px;color:#7d8ca3;font-size:9px}
.avsf-observe-connection{display:inline-flex;align-items:center;gap:5px;font-weight:750;color:#1a9766}
.avsf-observe-connection i{display:inline-block;width:7px;height:7px;border-radius:50%;background:#19b77a;box-shadow:0 0 0 3px rgba(25,183,122,.12)}
.avsf-observe-connection.is-loading{color:#9a741c}.avsf-observe-connection.is-loading i{background:#e1a72b;box-shadow:0 0 0 3px rgba(225,167,43,.12)}
.avsf-observe-connection.is-stale{color:#b27617}.avsf-observe-connection.is-stale i{background:#e1a72b}
.avsf-observe-connection.is-error{color:#c33848}.avsf-observe-connection.is-error i{background:#dc4254;box-shadow:0 0 0 3px rgba(220,66,84,.12)}
.avsf-observe-refresh{flex:0 0 auto;min-height:36px;padding:8px 12px;border:1px solid #dbe5f4;border-radius:10px;background:#fff;color:#31578e;font-family:inherit;font-size:10px;font-weight:700;cursor:pointer;box-shadow:0 2px 6px rgba(40,70,110,.05)}
.avsf-observe-refresh:disabled{opacity:.55;cursor:wait}
.avsf-observe-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin:12px 0}
.avsf-observe-metric{min-width:0;padding:12px;border:1px solid #e5ebf4;border-radius:12px;background:#fff}
.avsf-observe-metric-label{display:block;color:#74849d;font-size:10px}
.avsf-observe-metric-value{display:block;margin-top:4px;color:#243c62;font-size:19px;font-weight:850;line-height:1.2;overflow-wrap:anywhere}
.avsf-observe-metric-detail{display:block;margin-top:4px;color:#8795a9;font-size:9px;line-height:1.45;overflow-wrap:anywhere}
.avsf-observe-metric[data-status=warning] .avsf-observe-metric-value{color:#a36a12}
.avsf-observe-metric[data-status=error] .avsf-observe-metric-value{color:#bd3145}
.avsf-observe-metric[data-status=info] .avsf-observe-metric-value{color:#536b91}
.avsf-observe-groups{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:11px}
.avsf-observe-group{min-width:0;padding:13px;border:1px solid #e4eaf3;border-radius:13px;background:#fff}
.avsf-observe-group-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-bottom:9px;margin-bottom:5px;border-bottom:1px solid #edf1f6}
.avsf-observe-group-head h3{font-size:12px;font-weight:800;color:#2d405e}
.avsf-observe-group-badge,.avsf-observe-status{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:3px 7px;border-radius:999px;background:#eaf8f1;color:#168052;font-size:9px;font-weight:800;white-space:nowrap}
.avsf-observe-group-badge[data-status=warning],.avsf-observe-status[data-status=warning]{background:#fff5df;color:#98620d}
.avsf-observe-group-badge[data-status=error],.avsf-observe-status[data-status=error]{background:#fff0f1;color:#b62e40}
.avsf-observe-group-badge[data-status=info],.avsf-observe-status[data-status=info]{background:#edf3ff;color:#45659a}
.avsf-observe-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:8px;padding:8px 0;border-bottom:1px solid #f0f3f7}
.avsf-observe-row:last-child{border-bottom:0}
.avsf-observe-row-main{min-width:0}
.avsf-observe-row-label{display:block;color:#34455f;font-size:10px;font-weight:750;line-height:1.4;overflow-wrap:anywhere}
.avsf-observe-row-detail{display:block;margin-top:3px;color:#8693a7;font-size:9px;line-height:1.5;overflow-wrap:anywhere}
.avsf-observe-row-value{display:block;margin-top:3px;color:#425a7b;font-size:9px;font-weight:750;overflow-wrap:anywhere}
.avsf-observe-status{margin-top:1px}
.avsf-observe-issues{margin:12px 0;padding:12px 13px;border:1px solid #f2dfb7;border-radius:12px;background:#fffaf0}
.avsf-observe-issues[data-status=error]{border-color:#f2cbd0;background:#fff6f7}
.avsf-observe-issues h3{margin:0 0 7px;color:#8d5d12;font-size:11px;font-weight:800}
.avsf-observe-issues[data-status=error] h3{color:#ad3445}
.avsf-observe-issue{padding:6px 0;border-top:1px solid rgba(130,100,50,.12);font-size:9px;color:#6f5a3b;line-height:1.5}
.avsf-observe-issue strong{color:#684714}
.avsf-observe-footnote{margin-top:12px;padding:11px 13px;border:1px solid #e4ebf5;border-radius:11px;background:#f5f8fd}
.avsf-observe-footnote strong{font-size:10px;color:#405571}
.avsf-observe-footnote p{margin-top:3px;color:#7888a0;font-size:9px;line-height:1.65}
.avsf-observe-placeholder{grid-column:1/-1;padding:18px;text-align:center;border:1px dashed #d7e1ef;border-radius:12px;color:#8090a7;background:#fbfdff;font-size:10px}
.avsf-site-shell[data-theme=dark] .avsf-observe-shell{background:linear-gradient(155deg,#151c29,#111827);border-color:#2c374b;color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-observe-heading h2,.avsf-site-shell[data-theme=dark] .avsf-observe-group-head h3,.avsf-site-shell[data-theme=dark] .avsf-observe-row-label,.avsf-site-shell[data-theme=dark] .avsf-observe-footnote strong{color:#e5e7eb}
.avsf-site-shell[data-theme=dark] .avsf-observe-metric,.avsf-site-shell[data-theme=dark] .avsf-observe-group{background:#111827;border-color:#2a3547}
.avsf-site-shell[data-theme=dark] .avsf-observe-metric-value,.avsf-site-shell[data-theme=dark] .avsf-observe-row-value{color:#c9d7ed}
.avsf-site-shell[data-theme=dark] .avsf-observe-footnote,.avsf-site-shell[data-theme=dark] .avsf-observe-placeholder{background:#101827;border-color:#2a3547}
.avsf-site-shell[data-theme=dark] .avsf-observe-row{border-color:#263244}
.avsf-site-shell[data-theme=dark] .avsf-observe-issues{background:#2c2518;border-color:#5b4826}
.avsf-site-shell[data-theme=dark] .avsf-observe-issues[data-status=error]{background:#2d1d25;border-color:#673642}
.avsf-site-shell[data-theme=dark] .avsf-observe-issue,.avsf-site-shell[data-theme=dark] .avsf-observe-issue strong{color:#ead4a9}
@media(max-width:850px){.avsf-observe-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-observe-groups{grid-template-columns:1fr}}
@media(max-width:560px){.avsf-observe-shell{padding:12px;margin:12px 0 18px;border-radius:14px}.avsf-observe-heading{gap:8px}.avsf-observe-heading h2{font-size:16px}.avsf-observe-refresh{padding:7px 9px;font-size:9px}.avsf-observe-metrics{gap:7px}.avsf-observe-metric{padding:10px}.avsf-observe-metric-value{font-size:17px}.avsf-observe-group{padding:11px}.avsf-observe-row{gap:5px}.avsf-observe-status{font-size:8px;padding:3px 6px}}

</style>
</head>
<body data-admin-theme="light">

<?php
if (!function_exists('siteCenterNavIcon')) {
    function siteCenterNavIcon($name) {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'roles' => '<path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
            'password' => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4M12 15v2"/>',
            'seo' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M8 11h6M11 8v6"/>',
            'redirect' => '<path d="M4 7h12a4 4 0 1 1-4 4"/><path d="m8 3-4 4 4 4M20 17H8a4 4 0 1 1 4-4"/><path d="m16 13 4 4-4 4"/>',
            'cache' => '<path d="m13 2-3 8h7l-6 12 2-9H6l7-11Z"/>',
            'malware' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/>',
            'backup' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3 1.2 0 2.4-.1 3.4-.4"/><path d="m17 17 2 2 4-4"/>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'files' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
            'php' => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M7 10h3a2 2 0 0 1 0 4H7v-4Zm10 0h-3v6m0-3h3a2 2 0 0 0 0-4"/>',
            'domain' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
            'replace' => '<path d="M4 7h13l-3-3M20 17H7l3 3"/><path d="M17 7a5 5 0 0 1 0 10M7 17A5 5 0 0 1 7 7"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.8-.6a8 8 0 0 1-1.5.9l-.3 1.9h-2.8l-.3-1.9a8 8 0 0 1-1.5-.9l-1.8.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.8.6a8 8 0 0 1 1.5-.9l.3-1.9h2.8l.3 1.9a8 8 0 0 1 1.5.9l1.8-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z"/>',
            'import' => '<path d="M12 3v12m-5-5 5 5 5-5"/><path d="M5 17v3h14v-3"/>',
            'logs' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>'
        ];
        $path = isset($paths[$name]) ? $paths[$name] : $paths['settings'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$path.'</svg>';
    }
}
$siteRoleLabels = permissionRoleDefinitions();
$siteRoleCode = (string)currentUserRole();
$siteRoleClass = preg_replace('/[^a-z0-9_-]/i', '', $siteRoleCode);
$siteRoleLabel = $siteRoleLabels[$siteRoleCode] ?? __('ผู้ดูแลระบบ');
$siteRoleMark = $siteRoleCode === 'owner'
    ? '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3 11 5 6 4 4 10l2 5-1 6 6 7 5-2 5 2 6-7-1-6 2-5-2-6-5 1-5-2z" fill="#e9a12a" stroke="#8b4214"/><path d="M7 8 11 10 16 7l5 3 4-2-2 5 1 6-5 5-3-2-3 2-5-5 1-6z" fill="#f8d57a" stroke="#9b4a18"/><path d="m10 5 1-3 5 3 5-3 1 3M10 14l3 1m6-1 3-1M13 20h6m-3-3v3" fill="none" stroke="#5d2a12" stroke-width="1.5" stroke-linecap="round"/><circle cx="11" cy="15" r="1" fill="#2b2019"/><circle cx="21" cy="15" r="1" fill="#2b2019"/></svg>'
    : '<span class="avsf-site-user-dot"></span>';
$sitePanelAllowlist = ['dashboard','members','roles','seo','seo-indexing','seo-sitemap','seo-urls','seo-errors','redirects','cache','security','import','backup-center','file-manager','errorlog','settings'];
$sitePanelRequested = isset($_GET['panel']) && is_string($_GET['panel']) ? strtolower(trim($_GET['panel'])) : 'dashboard';
$sitePanelRequested = preg_replace('/[^a-z0-9_-]/', '', $sitePanelRequested);
$siteSection = in_array($sitePanelRequested, $sitePanelAllowlist, true) ? $sitePanelRequested : 'dashboard';
?>
<div class="avsf-site-shell" id="siteShell">
<aside class="avsf-site-sidebar" id="siteSidebar">
    <div class="avsf-site-sidebar-head">
        <div class="avsf-site-brand"><?php if($siteCenterBrandLogoValid): ?><img src="<?= e($siteCenterBrandLogo) ?>" alt="<?= e($siteCenterBrandName) ?>"><?php else: ?><span class="avsf-site-brand-name"><?= e($siteCenterBrandName) ?></span><?php endif; ?><span class="avsf-site-brand-caption"><?php _e('ศูนย์เว็บไซต์'); ?></span></div>
        <div class="avsf-site-user avsf-site-user-role-<?= e($siteRoleClass) ?>"><span class="avsf-site-user-mark"><?= $siteRoleMark ?></span><span><strong><?= e($_SESSION['avsf_admin_username'] ?? __('ผู้ดูแล')) ?></strong><small><?= e($siteRoleLabel) ?></small></span></div>
        <div class="avsf-site-quick-controls">
            <button type="button" data-site-theme-toggle aria-label="<?php echo __('สลับโหมดมืดและสว่าง'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" style="vertical-align:-2px;margin-right:4px"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg><?php _e('ธีม'); ?></button>
            <?php if (can('settings.edit')): ?><form method="post" class="avsf-site-color-control" title="<?php echo __('เปลี่ยนสี Accent หลังบ้าน'); ?>"><?= sec_csrf_field() ?><input type="hidden" name="action" value="save_admin_accent_quick"><label for="siteAccentColor"><?php _e('สี'); ?></label><input id="siteAccentColor" type="color" name="admin_accent" value="<?= e($adminAccent) ?>" aria-label="<?php echo __('เลือกสี Accent หลังบ้าน'); ?>" onchange="this.form.submit()"></form><?php else: ?><a class="avsf-site-theme-settings" href="index.php?page=settings" style="flex:1;text-align:center;padding:8px;border:1px solid var(--border,#dfe6f0);border-radius:8px;background:var(--card,#fff);color:var(--text,#19304f);text-decoration:none;font-size:12px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="vertical-align:-2px;margin-right:4px"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.8-.6a8 8 0 0 1-1.5.9l-.3 1.9h-2.8l-.3-1.9a8 8 0 0 1-1.5-.9l-1.8.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.8.6a8 8 0 0 1 1.5-.9l.3-1.9h2.8l.3 1.9a8 8 0 0 1 1.5.9l1.8-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z"/></svg><?php _e('สีหลังบ้าน'); ?></a><?php endif; ?>
        </div>
    </div>
    <nav class="avsf-site-nav" aria-label="<?php echo __('เมนูศูนย์เว็บไซต์'); ?>">
        <a class="<?= e(avsfClass($siteSection==='dashboard'?'avsf-active':'')) ?>" href="site-center.php" data-center-panel="dashboard"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('dashboard') ?></span><?php _e('ภาพรวมระบบ'); ?></a>
        <?php if (can('users.manage')): ?>
        <details <?= in_array($siteSection,['members','roles'],true)?'open':'' ?>>
            <summary><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('users') ?></span><?php _e('สมาชิกและสิทธิ์'); ?> <span class="avsf-menu-dot"></span></summary>
            <div class="avsf-site-subnav">
                <a class="<?= e(avsfClass($siteSection==='members'?'avsf-active':'')) ?>" href="users.php" data-center-panel="members"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('users') ?></span><?php _e('รายชื่อผู้ใช้งาน'); ?></a>
                <a class="<?= e(avsfClass($siteSection==='roles'?'avsf-active':'')) ?>" href="users.php?action=roles" data-center-panel="roles"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('roles') ?></span><?php _e('ตั้งค่าสิทธิ์แต่ละบทบาท'); ?></a>
                <button class="avsf-site-action-item" type="button" data-center-modal="passModal"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('password') ?></span><?php _e('เปลี่ยนรหัสผ่านแอดมิน'); ?></button>
            </div>
        </details>
        <?php endif; ?>
        <?php if (can('seo.manage') || can('redirects.manage')): ?>
        <details <?= in_array($siteSection,['seo','redirects'],true)?'open':'' ?>>
            <summary><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('seo') ?></span><?php _e('SEO และเส้นทาง URL'); ?> <span class="avsf-menu-dot"></span></summary>
            <div class="avsf-site-subnav">
                <?php if (can('seo.manage')): ?>
                <a class="<?= e(avsfClass($siteSection==='seo'?'avsf-active':'')) ?>" href="seo-center.php" data-center-panel="seo"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('seo') ?></span>SEO &amp; Robots Manager</a>
                <a href="seo-center.php?view=indexing" data-center-panel="seo-indexing"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('roles') ?></span>Index / Noindex</a>
                <a href="seo-center.php?view=sitemap" data-center-panel="seo-sitemap"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('files') ?></span>Sitemap &amp; Crawlability</a>
                <a href="seo-center.php?view=urls" data-center-panel="seo-urls"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('domain') ?></span><?php _e('รายการ URL'); ?></a>
                <a href="seo-center.php?view=errors" data-center-panel="seo-errors"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('logs') ?></span><?php _e('รายงาน 404 / 5xx'); ?></a>
                <?php endif; ?>
                <?php if (can('redirects.manage')): ?><a class="<?= e(avsfClass($siteSection==='redirects'?'avsf-active':'')) ?>" href="redirects.php" data-center-panel="redirects"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('redirect') ?></span>Redirect / 404 / 410</a><?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
        <?php if (can('cache.manage')): ?>
        <a href="cache.php" data-center-panel="cache"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('cache') ?></span><?php _e('แคช &amp; ประสิทธิภาพ'); ?></a>
        <form method="post" action="site-center.php" class="avsf-site-nav-action-form avsf-site-nav-primary-form"><?= sec_csrf_field() ?><input type="hidden" name="action" value="clear_cache"><button class="avsf-site-action-item avsf-site-nav-primary-action" type="submit" onclick="return true"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('cache') ?></span><?php _e('ล้างแคชทันที'); ?></button></form>
        <?php endif; ?>
        <?php if (can('security.view')): ?>
        <a href="security.php" data-center-panel="security"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('malware') ?></span><?php _e('สแกนมัลแวร์ / กู้คืนไฟล์'); ?></a>
        <?php endif; ?>
        <?php if (can('posts.import')): ?>
        <a href="import.php" data-center-panel="import"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('files') ?></span><?php _e('นำเข้าข้อมูล'); ?></a>
        <?php endif; ?>
        <?php if (can('backup.download')): ?>
        <a href="backup-center.php" data-center-panel="backup-center"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('backup') ?></span><?php _e('สำรองข้อมูลและกู้คืนระบบ'); ?></a>
        <?php endif; ?>
        <?php if (can('sitecenter.tools') && isSuperAdmin()): ?>
        <a href="file-manager.php" data-center-panel="file-manager"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('files') ?></span>File Manager</a>
        <?php endif; ?>
        <?php if (can('errorlog.view')): ?>
        <details>
            <summary><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('logs') ?></span><?php _e('บันทึกระบบ'); ?> <span class="avsf-menu-dot"></span></summary>
            <div class="avsf-site-subnav">
                <a href="error-log-center.php" data-center-panel="errorlog"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('logs') ?></span><?php _e('ดู Error Log'); ?></a>
            </div>
        </details>
        <?php endif; ?>
        <?php if (can('sitecenter.tools')): ?>
        <details>
            <summary><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('settings') ?></span><?php _e('เครื่องมือเพิ่มเติม'); ?> <span class="avsf-menu-dot"></span></summary>
            <div class="avsf-site-subnav">
                <button class="avsf-site-action-item" type="button" data-center-modal="phpModal"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('php') ?></span><?php _e('ตรวจค่า PHP'); ?></button>
                <button class="avsf-site-action-item" type="button" data-center-modal="domainModal"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('domain') ?></span><?php _e('เปลี่ยนโดเมน'); ?></button>
                <button class="avsf-site-action-item" type="button" data-center-modal="replaceModal"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('replace') ?></span><?php _e('ค้นหา/แทนที่ในฐานข้อมูล'); ?></button>
            </div>
        </details>
        <?php endif; ?>
        <?php if (can('settings.view')): ?><a href="index.php?page=settings" data-center-panel="settings"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('settings') ?></span><?php _e('ตั้งค่าเว็บ'); ?></a><?php endif; ?>
        <a href="index.php?page=dashboard"><span class="avsf-site-nav-icon"><?= siteCenterNavIcon('dashboard') ?></span><?php _e('กลับหลังบ้าน'); ?></a>
    </nav>
</aside>
<section class="avsf-site-main">
<header class="avsf-site-topbar"><button class="avsf-site-menu-toggle" id="siteMenuToggle" type="button" aria-controls="siteSidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:-3px;margin-right:4px"><path d="M4 6h16M4 12h16M4 18h16"/></svg><?php _e('เมนู'); ?></button><span class="avsf-site-topbar-title"><?= e(avsfCanonicalBaseUrl()) ?> · Site Center</span><span class="avsf-site-topbar-user"><?= e($_SESSION['avsf_admin_username'] ?? __('ผู้ดูแล')) ?> · <?= e($siteRoleLabel) ?></span><button type="button" data-site-theme-toggle aria-label="<?php echo __('สลับโหมดมืดและสว่าง'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg></button></header>
<div class="avsf-container avsf-site-dashboard-panel" id="siteDashboardPanel" aria-label="ภาพรวมเว็บไซต์"<?= $siteSection !== 'dashboard' ? ' hidden aria-hidden="true"' : '' ?>>
    <h1 style="font-size:24px;font-weight:800;margin-bottom:4px"><?php _e('ภาพรวมศูนย์เว็บไซต์'); ?></h1>
    <p style="font-size:13px;color:#738199;margin-bottom:10px"><?php _e('สรุปข้อมูลเว็บไซต์, SEO, URL, บันทึกข้อผิดพลาด และสภาพแวดล้อมเซิร์ฟเวอร์ ·'); ?> <?= e(avsfCanonicalBaseUrl()) ?> · <span id="avsfDashboardSummaryTime">ตรวจเมื่อ <?= date('d/m/Y H:i:s') ?></span></p>

    <?php if ($msg): ?><div class="avsf-msg <?= e(avsfClass($msgType)) ?>"><?= e($msg) ?></div><?php endif; ?>

    <div class="avsf-site-health-summary avsf-is-<?= e($dashboardOverallStatus) ?>" id="avsfLiveHealthSummary" aria-live="polite">
        <span class="avsf-site-health-dot" aria-hidden="true"></span>
        <strong id="avsfLiveHealthHeadline"><?= $dashboardOverallStatus==='ok'?__('กำลังเตรียมตรวจสอบระบบ'):($dashboardOverallStatus==='warning'?__('กำลังเตรียมตรวจสอบระบบ'):__('กำลังเตรียมตรวจสอบระบบ')) ?></strong>
        <span><span>ผ่าน <b id="avsfLiveCountOk"><?= (int)$dashboardCheckCounts['ok'] ?></b></span> · <span>เตือน <b id="avsfLiveCountWarning"><?= (int)$dashboardCheckCounts['warning'] ?></b></span> · <span>ผิดพลาด <b id="avsfLiveCountError"><?= (int)$dashboardCheckCounts['error'] ?></b></span> · <span>ข้อมูลประกอบ <b id="avsfLiveCountInfo"><?= (int)$dashboardCheckCounts['info'] ?></b></span></span>
    </div>

    <div class="avsf-site-dashboard-metrics" aria-label="<?php echo __('ตัวเลขสำคัญของเว็บไซต์'); ?>">
        <article class="avsf-site-metric-card" data-avsf-top-metric="posts"><span><?php _e('โพสต์ทั้งหมด'); ?></span><strong data-avsf-top-value><?= $stats['posts']===null?__('อ่านไม่ได้'):number_format($stats['posts']) ?></strong><small data-avsf-top-detail><?php _e('เผยแพร่'); ?> <?= $stats['published']===null?'—':number_format($stats['published']) ?> <?php _e('· ฉบับร่าง'); ?> <?= $stats['drafts']===null?'—':number_format($stats['drafts']) ?></small></article>
        <article class="avsf-site-metric-card" data-avsf-top-metric="pages"><span><?php _e('หน้าเว็บ'); ?></span><strong data-avsf-top-value><?= $stats['pages']===null?__('อ่านไม่ได้'):number_format($stats['pages']) ?></strong><small data-avsf-top-detail><?php _e('เผยแพร่'); ?> <?= $stats['published_pages']===null?'—':number_format($stats['published_pages']) ?></small></article>
        <article class="avsf-site-metric-card" data-avsf-top-metric="users"><span><?php _e('ผู้ใช้งาน'); ?></span><strong data-avsf-top-value><?= $stats['users']===null?__('อ่านไม่ได้'):number_format($stats['users']) ?></strong><small data-avsf-top-detail><?php _e('บัญชีทั้งหมดในระบบ'); ?></small></article>
        <article class="avsf-site-metric-card" data-avsf-top-metric="redirect301"><span>Redirect 301</span><strong data-avsf-top-value><?= $dashboardRedirects['301']===null?__('อ่านไม่ได้'):number_format($dashboardRedirects['301']) ?></strong><small data-avsf-top-detail>active · <?= $dashboardRedirects['hits_301']===null?'—':number_format($dashboardRedirects['hits_301']) ?> hits</small></article>
        <article class="avsf-site-metric-card <?= e(avsfClass(!empty($dashboardErrorSummary['404_entries'])?'avsf-is-alert':'')) ?>" data-avsf-top-metric="notfound"><span><?php _e('เส้นทาง 404'); ?></span><strong data-avsf-top-value><?= $dashboardErrorSummary['404_entries']===null?__('อ่านไม่ได้'):number_format($dashboardErrorSummary['404_entries']) ?></strong><small data-avsf-top-detail><?php _e('รายการ ·'); ?> <?= $dashboardErrorSummary['404_hits']===null?'—':number_format($dashboardErrorSummary['404_hits']) ?> <?php _e('hits ที่บันทึก'); ?></small></article>
        <article class="avsf-site-metric-card <?= e(avsfClass(!empty($dashboardErrorSummary['500_hits'])?'avsf-is-alert':'')) ?>" data-avsf-top-metric="errors"><span><?php _e('ข้อผิดพลาด 5xx'); ?></span><strong data-avsf-top-value><?= $dashboardErrorSummary['500_hits']===null?__('อ่านไม่ได้'):number_format($dashboardErrorSummary['500_hits']) ?></strong><small data-avsf-top-detail><?= $dashboardErrorSummary['500_entries']===null?'—':number_format($dashboardErrorSummary['500_entries']) ?> <?php _e('path/type ใน log'); ?></small></article>
        <article class="avsf-site-metric-card" data-avsf-top-metric="views"><span><?php _e('ยอดเข้าชม'); ?></span><strong data-avsf-top-value><?= $stats['views']===null?__('อ่านไม่ได้'):number_format($stats['views']) ?></strong><small data-avsf-top-detail><?php _e('ผลรวม views ที่บันทึกในโพสต์'); ?></small></article>
        <article class="avsf-site-metric-card" data-avsf-top-metric="disk"><span><?php _e('ดิสก์ filesystem'); ?></span><strong data-avsf-top-value><?= $stats['disk_percent']===null?__('อ่านไม่ได้'):e($stats['disk_percent']).'%' ?></strong><small data-avsf-top-detail><?php _e('การใช้งาน filesystem ของเว็บไซต์'); ?></small></article>
    </div>

    <?php $indexTotal=(int)$dashboardSeo['total'];$indexPercent=$indexTotal>0?round($dashboardSeo['index']/$indexTotal*100,1):0;$indexDegrees=$indexTotal>0?round($dashboardSeo['index']/$indexTotal*360,2):0; ?>
    <div class="avsf-site-dashboard-grid">
        <section class="avsf-site-dashboard-card" aria-labelledby="siteIndexChartHeading">
            <h2 id="siteIndexChartHeading"><?php _e('สรุปการจัดทำดัชนี SEO'); ?></h2>
            <p><?php _e('นับหน้าตามค่า Index/Noindex เริ่มต้นและ override จาก SEO Center รวมหน้ารวมแบบคงที่'); ?></p>
            <div class="avsf-site-index-chart">
                <div class="avsf-site-donut" role="img" aria-label="Index <?= number_format($dashboardSeo['index']) ?> <?php _e('URLs จาก'); ?> <?= number_format($indexTotal) ?> URLs" style="--index-end:<?= e($indexDegrees) ?>deg">
                    <div class="avsf-site-donut-center"><strong><?= e($indexPercent) ?>%</strong><span><?php _e('ตั้งค่า Index'); ?></span></div>
                </div>
                <div class="avsf-site-chart-legend">
                    <div class="avsf-site-chart-legend-item"><span class="avsf-site-chart-swatch avsf-is-index"></span><span>Index</span><strong><?= number_format($dashboardSeo['index']) ?></strong></div>
                    <div class="avsf-site-chart-legend-item"><span class="avsf-site-chart-swatch avsf-is-noindex"></span><span>Noindex</span><strong><?= number_format($dashboardSeo['noindex']) ?></strong></div>
                    <small class="avsf-site-report-note"><?php _e('รวม'); ?> <?= number_format($indexTotal) ?> <?php _e('รายการ · ไม่ใช่จำนวน URL ที่ Google จัดทำดัชนีจริง'); ?></small>
                </div>
            </div>
        </section>
        <section class="avsf-site-dashboard-card" aria-labelledby="siteInventoryHeading">
            <h2 id="siteInventoryHeading"><?php _e('คลังข้อมูลในระบบ'); ?></h2>
            <p><?php _e('ข้อมูลจากตารางหลักของเว็บไซต์ ขณะเปิดหน้านี้'); ?></p>
            <div class="avsf-site-inventory-grid">
                <?php foreach($taxonomyCounts as $label=>$count): ?><div class="avsf-site-inventory-item"><span><?= e($label) ?></span><strong><?= $count===null?__('อ่านไม่ได้'):number_format($count) ?></strong></div><?php endforeach; ?>
                <div class="avsf-site-inventory-item"><span><?php _e('คอมเมนต์ทั้งหมด'); ?></span><strong><?= $stats['comments']===null?__('อ่านไม่ได้'):number_format($stats['comments']) ?></strong></div>
                <div class="avsf-site-inventory-item"><span><?php _e('รออนุมัติ'); ?></span><strong><?= $stats['pending_comments']===null?__('อ่านไม่ได้'):number_format($stats['pending_comments']) ?></strong></div>
                <div class="avsf-site-inventory-item"><span>Redirect 410</span><strong><?= $dashboardRedirects['410']===null?__('อ่านไม่ได้'):number_format($dashboardRedirects['410']) ?></strong></div>
                <div class="avsf-site-inventory-item"><span>SEO inventory</span><strong><?= number_format($indexTotal) ?></strong></div>
                <?php $dashboardRoleLabels=['owner'=>'Owner','super_admin'=>'Super Admin','deputy_admin'=>__('รองแอดมิน'),'editor'=>__('บรรณาธิการ'),'author'=>__('ผู้เขียน'),'moderator'=>__('ผู้ตรวจ'),'custom'=>__('ทั่วไป'),'user'=>__('ผู้ใช้')];$dashboardRoleText=[];foreach($dashboardUsersByRole as $role=>$count)$dashboardRoleText[]=(isset($dashboardRoleLabels[$role])?$dashboardRoleLabels[$role]:$role).' '.number_format($count); ?>
                <div class="avsf-site-inventory-item"><span><?php _e('บัญชีแยกตามบทบาท'); ?></span><strong><?= count($dashboardUsersByRole) ?></strong><small><?= $dashboardRoleText?e(implode(' · ',$dashboardRoleText)):__('อ่านไม่ได้หรือไม่มีบัญชี') ?></small></div>
            </div>
        </section>
    </div>

    <div class="avsf-system-status-grid">
        <article class="avsf-system-status-card"><strong><span class="avsf-site-status-icon"><?= siteCenterNavIcon('domain') ?></span> HTTPS</strong><span class="avsf-badge <?= e(avsfClass($stats['https']==='ON'?'avsf-badge-green':($stats['https']==='VERIFY'?'avsf-badge-blue':'avsf-badge-red'))) ?>"><?= $stats['https']==='VERIFY'?__('ยืนยันไม่ได้'):$stats['https'] ?></span><small><?= $stats['https']==='ON'?__('PHP ยืนยันว่า request ปัจจุบันใช้ HTTPS'):($stats['https']==='VERIFY'?__('URL หลักเป็น HTTPS แต่ PHP อาจอยู่หลัง reverse proxy / SSL offload'):__('request ปัจจุบันไม่ยืนยัน HTTPS')) ?></small></article>
        <article class="avsf-system-status-card"><strong><span class="avsf-site-status-icon"><?= siteCenterNavIcon('php') ?></span> PHP <?= e($stats['php_version']) ?></strong><span>RAM worker <?= e($stats['memory_peak_mb']) ?> MB / <?= e($stats['php_settings']['memory_limit']) ?></span><small><?php _e('เป็น peak memory ของ PHP request นี้ ไม่ใช่ RAM ทั้งเครื่อง'); ?></small></article>
        <article class="avsf-system-status-card"><strong><span class="avsf-site-status-icon"><?= siteCenterNavIcon('files') ?></span> <?php _e('พื้นที่ดิสก์'); ?></strong><span><?= $stats['disk_percent']===null?__('อ่านไม่ได้'):e($stats['disk_percent']).'%' ?></span><small><?php _e('พื้นที่ของ filesystem ที่เก็บไฟล์เว็บไซต์ ไม่ใช่การนับ uploads ทีละไฟล์'); ?></small></article>
        <article class="avsf-system-status-card"><strong><span class="avsf-site-status-icon"><?= siteCenterNavIcon('database') ?></span> <?php _e('ฐานข้อมูล'); ?></strong><span><?= $stats['database_mb']===null?__('อ่านไม่ได้'):e($stats['database_mb']).' MB' ?></span><small><?php _e('รวมขนาด data + index จาก information_schema'); ?></small></article>
    </div>
    <section class="avsf-observe-shell" id="avsfLiveObservability" data-endpoint="site-center-health.php" data-refresh-ms="30000" aria-labelledby="avsfObserveHeading">
        <div class="avsf-observe-heading">
            <div>
                <div class="avsf-observe-eyebrow">AVSF · SITE OBSERVABILITY</div>
                <h2 id="avsfObserveHeading">เฝ้าระวังสถานะทุกระบบ</h2>
                <p>อ่านค่าจากระบบจริงแบบ Read-only · รีเฟรชอัตโนมัติทุก 30 วินาที</p>
                <div class="avsf-observe-meta">
                    <span id="avsfObserveConnection" class="avsf-observe-connection is-loading"><i></i> กำลังเชื่อมต่อ</span>
                    <span id="avsfObserveUpdated">ยังไม่มีข้อมูลสด</span>
                    <span id="avsfObserveCadence">รอบตรวจ 30 วินาที</span>
                </div>
            </div>
            <button type="button" class="avsf-observe-refresh" id="avsfObserveRefresh">↻ ตรวจสอบทันที</button>
        </div>
        <div class="avsf-observe-metrics" id="avsfObserveMetrics" aria-live="polite">
            <div class="avsf-observe-placeholder">กำลังอ่านสถานะฐานข้อมูล เซิร์ฟเวอร์ พื้นที่จัดเก็บ และโมดูล…</div>
        </div>
        <div class="avsf-observe-issues" id="avsfObserveIssues" hidden></div>
        <div class="avsf-observe-groups" id="avsfObserveGroups">
            <div class="avsf-observe-placeholder">กำลังรวบรวมผลตรวจจากทุกส่วนของ AVSF…</div>
        </div>
        <div class="avsf-observe-footnote">
            <strong>ขอบเขตการตรวจสอบ</strong>
            <p>Dashboard นี้ดึงข้อมูลจาก PHP runtime, MySQL, ตารางข้อมูล, โฟลเดอร์จริง, โมดูลและบันทึกของ AVSF โดยไม่แก้ไขข้อมูลหรือสั่งสแกน/สำรองข้อมูลเอง ค่า CPU, RAM ทั้งเครื่อง และ cron/worker จะแสดงเป็น “ยังวัดไม่ได้” เมื่อ Hosting ไม่เปิดข้อมูลหรือไม่มี heartbeat จึงไม่สร้างสถานะผ่านจากค่าที่ไม่มีแหล่งข้อมูล</p>
        </div>
    </section>

</div>
<div class="avsf-site-panel-status" id="sitePanelStatus" role="status" aria-live="polite" hidden></div>
<iframe class="avsf-site-panel-frame" id="sitePanelFrame" title="<?php echo __('เนื้อหาเมนูที่เลือกในศูนย์เว็บไซต์'); ?>" aria-label="เนื้อหาเมนูที่เลือก" hidden></iframe>
<div class="avsf-site-inline-tool-panel" id="siteInlineToolPanel" hidden></div>
</section>
</div>

<!-- ===== Modal: ค่า PHP จากเซิร์ฟเวอร์ ===== -->
<div class="avsf-modal-overlay" id="phpModal" onclick="if(event.target===this)closeModal('phpModal')">
    <div class="avsf-modal">
        <button class="avsf-modal-close" onclick="closeModal('phpModal')">×</button>
        <h3><?php _e('📊 ค่า PHP ที่เซิร์ฟเวอร์ใช้งานจริง'); ?></h3>
        <p style="font-size:13px;color:#666;line-height:1.7"><?php _e('หน้านี้อ่านค่าจาก PHP ที่กำลังรันอยู่ การแก้ค่าต้องทำผ่าน XAMPP/php.ini หรือแผงควบคุม Hosting แล้ว restart PHP/Apache ตามที่ระบบกำหนด'); ?></p>
        <div style="background:#f8fafc;padding:14px;border-radius:10px;font-size:13px;margin-top:10px;line-height:1.9">
            Upload max: <strong><?= e($stats['php_settings']['upload_max_filesize']) ?></strong><br>
            POST max: <strong><?= e($stats['php_settings']['post_max_size']) ?></strong><br>
            Memory limit: <strong><?= e($stats['php_settings']['memory_limit']) ?></strong><br>
            Max execution time: <strong><?= e($stats['php_settings']['max_execution_time']) ?> sec</strong><br>
            Max input time: <strong><?= e($stats['php_settings']['max_input_time']) ?> sec</strong>
        </div>
    </div>
</div>

<!-- ===== Modal: เปลี่ยนโดเมน ===== -->
<div class="avsf-modal-overlay" id="domainModal" onclick="if(event.target===this)closeModal('domainModal')">
    <div class="avsf-modal">
        <button class="avsf-modal-close" onclick="closeModal('domainModal')">×</button>
        <h3><?php _e('🌐 เปลี่ยนโดเมน'); ?></h3>
        <form method="post">
            <?= sec_csrf_field() ?><input type="hidden" name="action" value="change_domain">
            <label><?php _e('โดเมนเก่า (เช่น https://old.com)'); ?></label>
            <input type="text" name="old_domain" placeholder="https://old-domain.com" required>
            <label><?php _e('โดเมนใหม่ (เช่น https://new.com)'); ?></label>
            <input type="text" name="new_domain" placeholder="https://new-domain.com" required>
            <div class="avsf-btn-row">
                <button class="avsf-btn avsf-btn-secondary" type="button" onclick="closeModal('domainModal')"><?php _e('ยกเลิก'); ?></button>
                <button class="avsf-btn avsf-btn-primary" type="submit" onclick="return confirm(<?php echo avsf_js(__('แน่ใจ? จะแทนที่ URL ทั้งหมดในฐานข้อมูล')); ?>)">เปลี่ยนโดเมน</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== Modal: เปลี่ยนรหัสผ่าน ===== -->
<div class="avsf-modal-overlay" id="passModal" onclick="if(event.target===this)closeModal('passModal')">
    <div class="avsf-modal">
        <button class="avsf-modal-close" onclick="closeModal('passModal')">×</button>
        <h3><?php _e('🔑 เปลี่ยนรหัสผ่านแอดมิน'); ?></h3>
        <form method="post">
            <?= sec_csrf_field() ?><input type="hidden" name="action" value="change_password">
            <label><?php _e('รหัสผ่านเก่า'); ?></label>
            <input type="password" name="old_pass" required>
            <label><?php _e('รหัสผ่านใหม่ (อย่างน้อย 8 ตัว)'); ?></label>
            <input type="password" name="new_pass" minlength="8" required>
            <div class="avsf-btn-row">
                <button class="avsf-btn avsf-btn-secondary" type="button" onclick="closeModal('passModal')"><?php _e('ยกเลิก'); ?></button>
                <button class="avsf-btn avsf-btn-primary" type="submit"><?php _e('บันทึก'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ===== Modal: สแกน/แทนที่ ===== -->
<div class="avsf-modal-overlay" id="replaceModal" onclick="if(event.target===this)closeModal('replaceModal')">
    <div class="avsf-modal">
        <button class="avsf-modal-close" onclick="closeModal('replaceModal')">×</button>
        <h3><?php _e('🔗 สแกน/แทนที่ข้อความในฐานข้อมูล'); ?></h3>
        <form method="post">
            <?= sec_csrf_field() ?><input type="hidden" name="action" value="search_replace">
            <label><?php _e('ค้นหาข้อความ'); ?></label>
            <input type="text" name="search_text" placeholder="<?php echo __('เช่น https://old-domain.com'); ?>" required>
            <label><?php _e('แทนที่ด้วย'); ?></label>
            <input type="text" name="replace_text" placeholder="<?php echo __('เช่น https://new-domain.com'); ?>">
            <div class="avsf-btn-row">
                <button class="avsf-btn avsf-btn-secondary" type="button" onclick="closeModal('replaceModal')"><?php _e('ยกเลิก'); ?></button>
                <button class="avsf-btn avsf-btn-primary" type="submit" onclick="return confirm(<?php echo avsf_js(__('แน่ใจ? จะแก้ไขข้อมูลจริงในฐานข้อมูล')); ?>)">เริ่มแทนที่</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('avsf-active');document.body.style.overflow='hidden'}
function closeModal(id){
    if(typeof window.closeSiteCenterInlineModal==='function'&&window.closeSiteCenterInlineModal(id))return;
    document.getElementById(id).classList.remove('avsf-active');
    document.body.style.overflow='';
}
(function(){
    var dashboard=document.getElementById('siteDashboardPanel');
    var frame=document.getElementById('sitePanelFrame');
    var inlinePanel=document.getElementById('siteInlineToolPanel');
    var sidebar=document.getElementById('siteSidebar');
    var toggle=document.getElementById('siteMenuToggle');
    var panelLinks=Array.prototype.slice.call(document.querySelectorAll('[data-center-panel]'));
    var modalLinks=Array.prototype.slice.call(document.querySelectorAll('[data-center-modal]'));
    var framePath=location.pathname.replace(/[^/]*$/,'');
    var activePanel='dashboard';
    function restoreInlineModal(){
        var modal=inlinePanel.querySelector('[data-site-inline-modal]');
        if(!modal)return;
        var holder=document.getElementById(modal.getAttribute('data-site-inline-modal'));
        if(holder)holder.appendChild(modal);
        modal.removeAttribute('data-site-inline-modal');
        inlinePanel.hidden=true;
    }
    var panelStatus=document.getElementById('sitePanelStatus');
    var panelLoadTimer=null;
    var requestedPanelUrl='';
    function showPanelFallback(message,url){
        if(!panelStatus)return;
        panelStatus.replaceChildren();
        var text=document.createElement('span');
        text.textContent=message+' ';
        panelStatus.appendChild(text);
        if(url){
            var safeUrl;
            try{safeUrl=new URL(url,location.href);}catch(error){safeUrl=null;}
            if(safeUrl&&safeUrl.origin===location.origin){
                var link=document.createElement('a');
                link.href=safeUrl.href;
                link.textContent='เปิดหน้านี้โดยตรง';
                link.className='avsf-site-panel-fallback-link';
                panelStatus.appendChild(link);
            }
        }
        panelStatus.hidden=false;
    }
    function fallbackToDirect(message,url){
        showPanelFallback(message,url);
        if(!url)return;
        var safeUrl;
        try{safeUrl=new URL(url,location.href);}catch(error){return;}
        if(safeUrl.origin!==location.origin || safeUrl.href===location.href)return;
        var expected=safeUrl.href;
        window.setTimeout(function(){
            // Navigate only if the same panel is still active; avoid stale timers
            // redirecting after the user has selected another menu item.
            if(requestedPanelUrl===expected && !frame.hidden){
                window.location.assign(expected);
            }
        },750);
    }
    function findPanelLink(destination){
        return panelLinks.find(function(item){
            var itemUrl=new URL(item.href,location.href);
            return itemUrl.pathname===destination.pathname&&itemUrl.search===destination.search;
        }) || panelLinks.find(function(item){
            var itemUrl=new URL(item.href,location.href);
            return itemUrl.pathname===destination.pathname && (destination.search==='' || itemUrl.search==='');
        });
    }
    function setPanel(panel,url,updateAddress){
        restoreInlineModal();
        inlinePanel.hidden=true;
        if(panelLoadTimer){window.clearTimeout(panelLoadTimer);panelLoadTimer=null;}
        if(panelStatus){panelStatus.hidden=true;panelStatus.replaceChildren();}
        if(panel==='dashboard'){
            if(dashboard){
                dashboard.hidden=false;
                dashboard.setAttribute('aria-hidden','false');
            }
            frame.hidden=true;
            frame.setAttribute('aria-hidden','true');
            frame.removeAttribute('src');
            frame.removeAttribute('aria-busy');
            requestedPanelUrl='';
        }else{
            var targetUrl;
            try{targetUrl=new URL(url,location.href);}catch(error){
                showPanelFallback('ลิงก์เมนูไม่ถูกต้อง',url);
                return;
            }
            var adminBase=location.pathname.replace(/[^/]*$/,'');
            if(targetUrl.origin!==location.origin || targetUrl.pathname.indexOf(adminBase)!==0){
                showPanelFallback('ระบบป้องกันลิงก์นอกเว็บไซต์หรือเส้นทางหลังบ้านที่ไม่ถูกต้อง',url);
                return;
            }
            var alreadyLoadedSameTarget=!frame.hidden && requestedPanelUrl===targetUrl.href && frame.getAttribute('aria-busy')!=='true';
            if(dashboard){
                dashboard.hidden=true;
                dashboard.setAttribute('aria-hidden','true');
            }
            frame.hidden=false;
            frame.setAttribute('aria-hidden','false');
            frame.title=(panelLinks.find(function(item){return item.getAttribute('data-center-panel')===panel;})||{}).textContent||'เนื้อหาเมนูที่เลือก';
            requestedPanelUrl=targetUrl.href;
            frame.dataset.requestedUrl=requestedPanelUrl;
            if(alreadyLoadedSameTarget){
                // Re-selecting the active menu must not start a load timer when
                // the iframe URL is unchanged and the panel is already ready.
                frame.removeAttribute('aria-busy');
                if(panelStatus){panelStatus.hidden=true;panelStatus.replaceChildren();}
            }else{
                frame.setAttribute('aria-busy','true');
                if(panelStatus){panelStatus.textContent='กำลังโหลดหน้าเมนู…';panelStatus.hidden=false;}
                if(frame.src!==targetUrl.href)frame.src=targetUrl.href;
                panelLoadTimer=window.setTimeout(function(){
                    if(frame.getAttribute('aria-busy')==='true'){
                        frame.removeAttribute('aria-busy');
                        fallbackToDirect('หน้านี้ใช้เวลาโหลดนานเกินกำหนด ระบบกำลังเปิดหน้าโดยตรงแทน iframe',requestedPanelUrl);
                    }
                },8000);
            }
        }
        activePanel=panel;
        panelLinks.forEach(function(link){
            var active=link.getAttribute('data-center-panel')===panel;
            link.classList.toggle('avsf-active',active);
            if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
            if(active){var details=link.closest('details');if(details)details.open=true;}
        });
        if(updateAddress){
            var nextUrl=new URL(location.href);
            if(panel==='dashboard')nextUrl.searchParams.delete('panel');else nextUrl.searchParams.set('panel',panel);
            history.pushState({siteCenterPanel:panel},'',nextUrl.pathname+nextUrl.search+nextUrl.hash);
        }
        if(sidebar.classList.contains('avsf-open')&&toggle){sidebar.classList.remove('avsf-open');toggle.setAttribute('aria-expanded','false');}
    }
    window.closeSiteCenterInlineModal=function(id){
        var modal=inlinePanel.querySelector('[data-site-inline-modal]');
        if(!modal||modal.getAttribute('data-site-inline-modal')!==id)return false;
        setPanel('dashboard','',true);
        return true;
    };
    // Bind navigation to each real Site Center link. This keeps click handling
    // local to the activated anchor and avoids intercepting unrelated document events.
    function activatePanelLink(event,link,panelName){
        if(!link || link.hasAttribute('download') || link.target==='_blank')return;
        var destination;
        try{destination=new URL(link.href,location.href);}catch(error){return;}
        if(destination.origin!==location.origin)return;
        var adminBase=location.pathname.replace(/[^/]*$/,'');
        if(destination.pathname===adminBase+'site-center.php' || (destination.pathname===adminBase+'index.php' && ['', 'dashboard'].indexOf(destination.searchParams.get('page')||'')!==-1)){
            event.preventDefault();
            try{setPanel('dashboard','',true);}
            catch(error){
                console.error('Site Center dashboard navigation failed',error);
                window.location.assign(destination.href);
            }
            return;
        }
        var match=findPanelLink(destination);
        var panel=panelName || (match?match.getAttribute('data-center-panel'):'');
        if(!panel)return;
        event.preventDefault();
        try{setPanel(panel,destination.href,true);}
        catch(error){
            console.error('Site Center navigation failed',error);
            window.location.assign(destination.href);
        }
    }
    panelLinks.forEach(function(link){
        link.addEventListener('click',function(event){
            activatePanelLink(event,link,link.getAttribute('data-center-panel'));
        });
    });
    // Dashboard cards without a panel key inherit the matching sidebar route.
    document.querySelectorAll('a.avsf-menu-card[href]').forEach(function(card){
        if(card.hasAttribute('data-center-panel'))return;
        card.addEventListener('click',function(event){activatePanelLink(event,card,'');});
    });
    modalLinks.forEach(function(link){
        link.addEventListener('click',function(){
            var id=link.getAttribute('data-center-modal');
            var holder=document.getElementById(id);
            var modal=holder?holder.querySelector('.avsf-modal'):null;
            if(!modal)return;
            setPanel('dashboard','',true);
            if(dashboard){
                dashboard.hidden=true;
                dashboard.setAttribute('aria-hidden','true');
            }
            inlinePanel.replaceChildren(modal);
            inlinePanel.hidden=false;
            modal.setAttribute('data-site-inline-modal',id);
            if(sidebar.classList.contains('avsf-open')&&toggle){sidebar.classList.remove('avsf-open');toggle.setAttribute('aria-expanded','false');}
        });
    });
    frame.addEventListener('load',function(){
        // Removing src when returning to the dashboard also fires load for
        // about:blank. Ignore that event instead of treating it as a failed page.
        if(frame.hidden || !requestedPanelUrl)return;
        try{
            if(panelLoadTimer){window.clearTimeout(panelLoadTimer);panelLoadTimer=null;}
            frame.removeAttribute('aria-busy');
            var doc=frame.contentDocument;
            if(!doc){fallbackToDirect('เบราว์เซอร์อ่านหน้าใน iframe ไม่ได้ ระบบกำลังเปิดหน้าโดยตรง',requestedPanelUrl);return;}
            var loadedPath=doc.location.pathname;
            var expectedPath=requestedPanelUrl?new URL(requestedPanelUrl,location.href).pathname:'';
            var bodyText=(doc.body&&doc.body.innerText?doc.body.innerText:'').slice(0,12000);
            var pageTitle=(doc.title||'').toLowerCase();
            var looksLikeError=/Fatal error:|Parse error:|Uncaught (?:Error|Exception)|Warning:|Internal Server Error|Server Error|Not Found|Forbidden|Access Denied|ไม่มีสิทธิ์เข้าถึง|กรุณาเข้าสู่ระบบ/i.test(bodyText)
                || /403|404|500|error|forbidden|not found/.test(pageTitle);
            var redirectedToLogin=expectedPath && loadedPath!==expectedPath && (/index\.php$/.test(loadedPath)||/login/i.test(loadedPath));
            if(looksLikeError || redirectedToLogin){
                fallbackToDirect('หน้าเมนูตอบกลับด้วยข้อผิดพลาดหรือถูกเปลี่ยนเส้นทาง ระบบกำลังเปิด URL โดยตรงเพื่อให้เห็นข้อผิดพลาดจริง',requestedPanelUrl);
                return;
            }
            if(panelStatus){panelStatus.hidden=true;panelStatus.replaceChildren();}
            if(!doc.getElementById('site-center-embedded-style')){
                var style=doc.createElement('style');
                style.id='site-center-embedded-style';
                style.textContent=[
                    ':root[data-admin-theme="light"]{color-scheme:light;--sc-bg:#f5f7fb;--sc-surface:#fff;--sc-muted-surface:#f8fafc;--sc-text:#17233b;--sc-muted:#718096;--sc-border:#e5eaf2;--sc-accent:#3159a8}',
                    ':root[data-admin-theme="dark"]{color-scheme:dark;--sc-bg:#090b10;--sc-surface:#11151f;--sc-muted-surface:#161b26;--sc-text:#e5e7eb;--sc-muted:#9aa8be;--sc-border:#283244;--sc-accent:#8bb4ff}',
                    'html,body{width:100%!important;max-width:none!important;min-width:0!important;height:auto!important;min-height:0!important;overflow-x:clip!important;background:var(--sc-bg)!important;color:var(--sc-text)!important;color-scheme:inherit!important}',
                    'body{padding:0 0 16px!important;font-family:"Segoe UI",-apple-system,BlinkMacSystemFont,"Noto Sans Thai",sans-serif!important}',
                    '.avsf-shell{display:block!important;min-height:0!important;background:transparent!important}',
                    '.avsf-shell>.avsf-sidebar,.avsf-shell>div>.avsf-topbar,#centerSidebar,.avsf-back,a[href="site-center.php"],.avsf-admin-sidebar,.avsf-admin-mobile-bar,.avsf-admin-menu-backdrop{display:none!important}',
                    '.avsf-shell>div{width:100%!important;background:transparent!important}',
                    '.avsf-main,.avsf-admin-main,.avsf-container{box-sizing:border-box!important;max-width:none!important;width:100%!important;min-width:0!important;margin:0!important;padding:14px!important;background:transparent!important;color:var(--sc-text)!important}',
                    '.avsf-admin-layout{display:block!important;background:transparent!important}',
                    '.avsf-admin-topbar,.avsf-admin-header,.avsf-admin-nav,.avsf-admin-toolbar{display:none!important}',
                    '.avsf-admin-main,.avsf-admin-content,.avsf-admin-page{width:100%!important;max-width:none!important;min-width:0!important;margin:0!important;padding:14px!important;background:transparent!important;color:var(--sc-text)!important}',
                    '.avsf-admin-card,.avsf-card,.avsf-panel{background:var(--sc-surface)!important;border-color:var(--sc-border)!important;color:var(--sc-text)!important}',
                    '.avsf-admin-card h1,.avsf-admin-card h2,.avsf-admin-card h3,.avsf-card h1,.avsf-card h2,.avsf-card h3,.avsf-card p,.avsf-card label{color:var(--sc-text)!important}',
                    'h1,h2,h3,h4,legend,label,th,td,p,small,.avsf-subtitle,.avsf-section-title,.avsf-field-help,.avsf-help-text,.avsf-muted,.avsf-text-muted{color:var(--sc-text)!important}',
                    '.avsf-subtitle,.avsf-field-help,.avsf-help-text,.avsf-muted,.avsf-text-muted,small{color:var(--sc-muted)!important}',
                    '.avsf-card,.avsf-panel,.avsf-box,.avsf-stat,.avsf-stat-card,.avsf-metric-card,.avsf-settings-card,.avsf-role-card,.avsf-user-card,.avsf-section-card,.avsf-table-wrap,.avsf-table-container,.avsf-form-section{background:var(--sc-surface)!important;border-color:var(--sc-border)!important;color:var(--sc-text)!important;box-shadow:0 3px 12px rgba(31,52,87,.045)!important}',
                    '.avsf-card h1,.avsf-card h2,.avsf-card h3,.avsf-card h4,.avsf-card label,.avsf-card p,.avsf-card li,.avsf-card span:not(.avsf-badge):not(.avsf-status):not(.avsf-btn),.avsf-card td,.avsf-card th,.avsf-panel h1,.avsf-panel h2,.avsf-panel h3,.avsf-panel label,.avsf-panel p,.avsf-panel td,.avsf-panel th,.avsf-box h1,.avsf-box h2,.avsf-box h3,.avsf-box label,.avsf-box p,.avsf-box td,.avsf-box th{color:var(--sc-text)!important}',
                    '.avsf-card small,.avsf-card .avsf-muted,.avsf-card .avsf-text-muted,.avsf-card .avsf-help-text,.avsf-panel small,.avsf-panel .avsf-muted,.avsf-box small{color:var(--sc-muted)!important}',
                    'input:not([type="checkbox"]):not([type="radio"]):not([type="color"]),textarea,select{background:var(--sc-surface)!important;color:var(--sc-text)!important;border-color:var(--sc-border)!important}',
                    'table{color:var(--sc-text)!important;border-color:var(--sc-border)!important}thead,th{background:var(--sc-muted-surface)!important;color:var(--sc-text)!important}td,tr{border-color:var(--sc-border)!important}',
                    'details,summary{border-color:var(--sc-border)!important}details>summary{color:var(--sc-text)!important}',
                    'nav a,.avsf-tabs a,.avsf-tab-nav a,.avsf-subnav a,.avsf-nav-tabs a,.avsf-tab-button,.avsf-nav-link{color:var(--sc-muted)!important;background:transparent!important;border-color:var(--sc-border)!important}',
                    'nav a.avsf-active,.avsf-tabs a.avsf-active,.avsf-tab-nav a.avsf-active,.avsf-subnav a.avsf-active,.avsf-nav-tabs a.avsf-active,.avsf-tab-button.avsf-active,.avsf-nav-link.avsf-active,nav a[aria-current="page"],.avsf-tabs a[aria-current="page"],.avsf-nav-tabs a[aria-current="page"]{color:var(--sc-accent)!important;background:var(--sc-muted-surface)!important;border-color:var(--sc-border)!important}',
                    'a:not(.avsf-btn):not(.avsf-button){color:var(--sc-accent)}',
                    'input[type="checkbox"],input[type="radio"]{accent-color:var(--sc-accent)!important;background:transparent!important;color-scheme:inherit!important}',
                    '.avsf-btn-secondary,.avsf-btn-ghost,.avsf-btn-outline,.avsf-btn-default,button:not(.avsf-btn):not(.avsf-button):not([type="submit"]):not([type="reset"]){background:var(--sc-muted-surface)!important;border-color:var(--sc-border)!important;color:var(--sc-text)!important}',
                    '.avsf-badge,.avsf-status,.avsf-pill{color:inherit}',
                    '.avsf-msg.avsf-success,.avsf-alert-success{background:#e8f7ef!important;color:#18784d!important;border-color:#b8e4ca!important}',
                    '.avsf-msg.avsf-error,.avsf-alert-danger,.avsf-alert-error{background:#fff0f0!important;color:#b42331!important;border-color:#f3c4c7!important}',
                    '.avsf-msg.avsf-warning,.avsf-alert-warning{background:#fff7e6!important;color:#9a6408!important;border-color:#f0d99c!important}',
                    'img{max-width:100%}',
                    '@media(max-width:700px){html,body{box-sizing:border-box!important;max-width:100%!important;min-width:0!important;overflow-x:hidden!important}body{padding-right:10px!important;padding-bottom:calc(96px + env(safe-area-inset-bottom))!important}.avsf-main,.avsf-admin-main,.avsf-container{box-sizing:border-box!important;width:100%!important;max-width:100%!important;min-width:0!important;padding-left:10px!important;padding-right:10px!important}.avsf-card,.avsf-panel,.avsf-box,.avsf-stat,.avsf-stat-card,.avsf-metric-card,.avsf-settings-card,.avsf-role-card,.avsf-user-card,.avsf-section-card,.avsf-table-wrap,.avsf-table-container,.table-responsive,.avsf-form-section{box-sizing:border-box!important;max-width:100%!important;min-width:0!important}.avsf-table-wrap,.avsf-table-container,.table-responsive{overflow-x:auto!important;-webkit-overflow-scrolling:touch!important}.avsf-table-wrap table,.avsf-table-container table,.table-responsive table{min-width:620px!important;width:max-content!important;max-width:none!important}.avsf-main table:not(.avsf-table-wrap table):not(.avsf-table-container table):not(.table-responsive table),.avsf-admin-main table:not(.avsf-table-wrap table):not(.avsf-table-container table):not(.table-responsive table),.avsf-container table:not(.avsf-table-wrap table):not(.avsf-table-container table):not(.table-responsive table){width:100%!important;min-width:0!important;table-layout:auto!important}th,td{overflow-wrap:break-word!important;word-break:normal!important}.avsf-btn,.avsf-button,button{box-sizing:border-box!important;max-width:100%!important;white-space:normal!important;overflow-wrap:normal!important;word-break:normal!important}.avsf-card form[style*="display:inline"]{display:inline-flex!important;max-width:100%!important;vertical-align:top}}'
                ].join('');
                doc.head.appendChild(style);
            }
            doc.documentElement.setAttribute('data-admin-theme',document.getElementById('siteShell').dataset.theme==='dark'?'dark':'light');
            // Keep all internal navigation inside the Site Center right-hand panel.
            if(!doc.documentElement.hasAttribute('data-site-center-nav-bound')){
                doc.documentElement.setAttribute('data-site-center-nav-bound','1');
                doc.addEventListener('click',function(navEvent){
                    var anchor=navEvent.target&&navEvent.target.closest?navEvent.target.closest('a[href]'):null;
                    if(!anchor||anchor.hasAttribute('download')||anchor.target==='_blank')return;
                    var rawHref=(anchor.getAttribute('href')||'').trim();
                    if(rawHref==='' || rawHref.charAt(0)==='#' || /^javascript:/i.test(rawHref))return;
                    var destination;
                    try{destination=new URL(anchor.href,doc.location.href);}catch(_){return;}
                    if(destination.origin!==location.origin)return;
                    // Download endpoints must remain native downloads inside this workspace.
                    if(destination.searchParams.has('download'))return;
                    var adminBase=location.pathname.replace(/[^/]*$/,'');
                    if(destination.pathname===adminBase+'site-center.php' || (destination.pathname===adminBase+'index.php' && ['', 'dashboard'].indexOf(destination.searchParams.get('page')||'')!==-1)){
                        navEvent.preventDefault();
                        setPanel('dashboard','',true);
                        return;
                    }
                    var navMatch=panelLinks.find(function(item){
                        var itemUrl=new URL(item.href,location.href);
                        return itemUrl.pathname===destination.pathname&&itemUrl.search===destination.search;
                    });
                    // Only use a path-only fallback when the destination has no query string,
                    // or when it is a module route (e.g. file-manager.php?dir=...). Never map
                    // every index.php?page=... URL to the unrelated Settings menu item.
                    if(!navMatch && destination.pathname!==adminBase+'index.php'){
                        navMatch=panelLinks.find(function(item){
                            var itemUrl=new URL(item.href,location.href);
                            return itemUrl.pathname===destination.pathname && (destination.search==='' || itemUrl.search==='');
                        });
                    }
                    if(navMatch){
                        navEvent.preventDefault();
                        setPanel(navMatch.getAttribute('data-center-panel'),destination.href,true);
                    }
                },true);
            }
            var isFileManager=/\/file-manager\.php$/.test(doc.location.pathname);
            if(isFileManager){
                doc.body.classList.add('avsf-site-center-file-manager');
                if(!doc.getElementById('site-center-file-manager-style')){
                    var fileManagerStyle=doc.createElement('link');
                    fileManagerStyle.id='site-center-file-manager-style';
                    fileManagerStyle.rel='stylesheet';
                    fileManagerStyle.href=new URL('file-manager-center.css?v=3.2.5-adminseo1',doc.location.href).href;
                    doc.head.appendChild(fileManagerStyle);
                }
            }
            var resize=function(){
                if(isFileManager){
                    doc.documentElement.style.setProperty('height','100%','important');
                    doc.documentElement.style.setProperty('overflow-y','auto','important');
                    if(doc.body){
                        doc.body.style.setProperty('height','100%','important');
                        doc.body.style.setProperty('overflow-y','auto','important');
                    }
                    var frameTop=frame.getBoundingClientRect().top;
                    frame.style.height=Math.max(360,window.innerHeight-Math.max(0,frameTop)-16)+'px';
                    return;
                }
                var height=Math.max(640,doc.documentElement.scrollHeight,doc.body?doc.body.scrollHeight:0);
                frame.style.height=Math.min(height,12000)+'px';
            };
            if(isFileManager){
                var fileManagerStyleLoaded=doc.getElementById("site-center-file-manager-style");
                if(fileManagerStyleLoaded)fileManagerStyleLoaded.addEventListener("load",resize,{once:true});
            }
            resize();
            if(frame._resizeObserver)frame._resizeObserver.disconnect();
            if(doc.body&&window.ResizeObserver){
                frame._resizeObserver=new ResizeObserver(resize);
                frame._resizeObserver.observe(doc.body);
                frame._resizeObserver.observe(doc.documentElement);
            }
            if(doc.defaultView)doc.defaultView.addEventListener("resize",resize,{passive:true});
            if(frame._resizeWindowHandler)window.removeEventListener("resize",frame._resizeWindowHandler);
            frame._resizeWindowHandler=resize;
            window.addEventListener("resize",resize,{passive:true});
            if(frame._resizeContainerObserver)frame._resizeContainerObserver.disconnect();
            if(frame.parentElement&&window.ResizeObserver){
                frame._resizeContainerObserver=new ResizeObserver(resize);
                frame._resizeContainerObserver.observe(frame.parentElement);
            }
        }catch(error){
            console.error('Unable to prepare Site Center panel',error);
            fallbackToDirect('เตรียมหน้าเมนูใน iframe ไม่สำเร็จ ระบบกำลังเปิดหน้าโดยตรง',requestedPanelUrl);
        }
    });
    window.addEventListener('popstate',function(){
        var panel=new URL(location.href).searchParams.get('panel');
        if(!panel){setPanel('dashboard','',false);return;}
        var link=panelLinks.find(function(item){return item.getAttribute('data-center-panel')===panel;});
        if(link)setPanel(panel,link.href,false);
    });
    var initialPanel=new URL(location.href).searchParams.get('panel');
    var initialLink=panelLinks.find(function(link){return link.getAttribute('data-center-panel')===initialPanel;});
    if(initialLink)setPanel(initialPanel,initialLink.href,false);
})();
(function(){
    var shell=document.getElementById('siteShell');
    var sidebar=document.getElementById('siteSidebar');
    var toggle=document.getElementById('siteMenuToggle');
    var dockMenu=document.querySelector('[data-site-menu-dock]');
    if(dockMenu&&toggle){dockMenu.addEventListener('click',function(event){event.stopPropagation();toggle.click();});}
    window.syncSiteCenterFrameTheme=function(){
        var frame=document.getElementById('sitePanelFrame');
        try{if(frame&&frame.contentDocument)frame.contentDocument.documentElement.setAttribute('data-admin-theme',shell.dataset.theme==='dark'?'dark':'light');}
        catch(error){console.error('Unable to synchronize Site Center panel theme',error);}
    };
    var themeKey='avsf-admin-theme';
    var saved=localStorage.getItem(themeKey);
    shell.dataset.theme=saved==='dark'?'dark':'light';
    document.documentElement.setAttribute('data-admin-theme',shell.dataset.theme);
    document.querySelectorAll('[data-site-theme-toggle]').forEach(function(button){
        button.addEventListener('click',function(){
            var next=shell.dataset.theme==='dark'?'light':'dark';
            shell.dataset.theme=next;
            document.documentElement.setAttribute('data-admin-theme',next);
            localStorage.setItem(themeKey,next);
            window.syncSiteCenterFrameTheme();
        });
    });
    if(toggle){
        toggle.addEventListener('click',function(){
            var open=sidebar.classList.toggle('avsf-open');
            toggle.setAttribute('aria-expanded',open?'true':'false');
        });
        document.addEventListener('keydown',function(event){
            if(event.key==='Escape'){sidebar.classList.remove('avsf-open');toggle.setAttribute('aria-expanded','false');}
        });
        document.addEventListener('click',function(event){
            if(sidebar.classList.contains('avsf-open')&&!sidebar.contains(event.target)&&!toggle.contains(event.target)&&!(dockMenu&&dockMenu.contains(event.target))){
                sidebar.classList.remove('avsf-open');
                toggle.setAttribute('aria-expanded','false');
            }
        });
        sidebar.querySelectorAll('a').forEach(function(link){
            link.addEventListener('click',function(){sidebar.classList.remove('avsf-open');toggle.setAttribute('aria-expanded','false');});
        });
    }
})();

/* AVSF live observability polling */

/* AVSF live observability polling */
(function(){
    var root=document.getElementById('avsfLiveObservability');
    if(!root)return;
    var endpoint=root.getAttribute('data-endpoint')||'site-center-health.php';
    var interval=Math.max(15000,parseInt(root.getAttribute('data-refresh-ms')||'30000',10)||30000);
    var dashboard=document.getElementById('siteDashboardPanel');
    var refreshButton=document.getElementById('avsfObserveRefresh');
    var connection=document.getElementById('avsfObserveConnection');
    var updated=document.getElementById('avsfObserveUpdated');
    var metricsBox=document.getElementById('avsfObserveMetrics');
    var groupsBox=document.getElementById('avsfObserveGroups');
    var issuesBox=document.getElementById('avsfObserveIssues');
    var summary=document.getElementById('avsfLiveHealthSummary');
    var busy=false,hasSnapshot=false;
    var statusText={ok:'ผ่าน',warning:'เตือน',error:'ผิดพลาด',info:'ข้อมูลประกอบ'};
    var overallText={ok:'สถานะระบบปกติ',warning:'มีรายการควรตรวจสอบ',error:'พบข้อผิดพลาดที่ต้องแก้ไข',info:'มีบางสถานะที่ยังยืนยันไม่ได้'};
    function el(tag,cls,text){var n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;}
    function isDashboardVisible(){return !document.hidden&&(!dashboard||!dashboard.hidden);}
    function setConnection(kind,label){
        connection.className='avsf-observe-connection '+kind;
        connection.replaceChildren(el('i',''),document.createTextNode(label));
    }
    function updateSummary(data){
        if(!summary)return;
        summary.classList.remove('avsf-is-ok','avsf-is-warning','avsf-is-error','avsf-is-info');
        summary.classList.add('avsf-is-'+data.overall);
        var headline=document.getElementById('avsfLiveHealthHeadline');
        if(headline)headline.textContent=overallText[data.overall]||'ตรวจสอบสถานะแล้ว';
        ['ok','warning','error','info'].forEach(function(key){
            var target=document.getElementById('avsfLiveCount'+key.charAt(0).toUpperCase()+key.slice(1));
            if(target)target.textContent=String((data.counts||{})[key]||0);
        });
    }
    function renderTopMetrics(items){
        if(!items)return;
        document.querySelectorAll('[data-avsf-top-metric]').forEach(function(card){
            var key=card.getAttribute('data-avsf-top-metric');
            var item=items[key];if(!item)return;
            var value=card.querySelector('[data-avsf-top-value]');
            var detail=card.querySelector('[data-avsf-top-detail]');
            if(value)value.textContent=item.value||'—';
            if(detail)detail.textContent=item.detail||'';
            card.classList.toggle('avsf-is-alert',item.status==='warning'||item.status==='error');
        });
    }
    function renderMetrics(items){
        var frag=document.createDocumentFragment();
        (items||[]).forEach(function(item){
            var card=el('article','avsf-observe-metric');
            card.setAttribute('data-status',item.status||'info');
            card.appendChild(el('span','avsf-observe-metric-label',item.label||''));
            card.appendChild(el('strong','avsf-observe-metric-value',item.value||'—'));
            card.appendChild(el('small','avsf-observe-metric-detail',item.detail||''));
            frag.appendChild(card);
        });
        metricsBox.replaceChildren(frag);
    }
    function renderGroups(sections){
        var frag=document.createDocumentFragment();
        (sections||[]).forEach(function(section){
            var card=el('section','avsf-observe-group');
            var head=el('div','avsf-observe-group-head');
            head.appendChild(el('h3','',section.title||''));
            var counts=section.counts||{};
            var badge=el('span','avsf-observe-group-badge',statusText[section.status]||'ข้อมูลประกอบ');
            badge.setAttribute('data-status',section.status||'info');
            head.appendChild(badge);card.appendChild(head);
            (section.items||[]).forEach(function(item){
                var row=el('div','avsf-observe-row');
                var main=el('div','avsf-observe-row-main');
                main.appendChild(el('strong','avsf-observe-row-label',item.label||''));
                if(item.detail)main.appendChild(el('small','avsf-observe-row-detail',item.detail));
                if(item.value)main.appendChild(el('span','avsf-observe-row-value',item.value));
                var st=el('span','avsf-observe-status',statusText[item.status]||'ข้อมูลประกอบ');
                st.setAttribute('data-status',item.status||'info');
                row.append(main,st);card.appendChild(row);
            });
            frag.appendChild(card);
        });
        groupsBox.replaceChildren(frag);
    }
    function renderIssues(data){
        var issues=data.issues||[];
        if(!issues.length){issuesBox.hidden=true;issuesBox.replaceChildren();return;}
        issuesBox.hidden=false;
        issuesBox.setAttribute('data-status',data.overall==='error'?'error':'warning');
        var frag=document.createDocumentFragment();
        frag.appendChild(el('h3','', 'รายการที่ต้องสังเกต ('+issues.length+')'));
        issues.forEach(function(issue){
            var row=el('div','avsf-observe-issue');
            row.appendChild(el('strong','',(issue.group||'')+' · '+(issue.label||'')));
            row.appendChild(document.createTextNode(' — '+(issue.detail||'')));
            frag.appendChild(row);
        });
        issuesBox.replaceChildren(frag);
    }
    async function refresh(){
        if(busy||!isDashboardVisible())return;
        busy=true;if(refreshButton)refreshButton.disabled=true;
        if(!hasSnapshot)setConnection('is-loading','กำลังตรวจสอบระบบ');
        try{
            var url=new URL(endpoint,window.location.href);
            url.searchParams.set('_health',String(Date.now()));
            var response=await fetch(url.toString(),{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            if(!response.ok)throw new Error('HTTP '+response.status);
            var data=await response.json();
            if(!data||data.ok!==true||!Array.isArray(data.sections))throw new Error('Invalid health response');
            renderTopMetrics(data.top_metrics||{});renderMetrics(data.metrics||[]);renderGroups(data.sections);renderIssues(data);updateSummary(data);
            hasSnapshot=true;setConnection('','LIVE · เชื่อมต่ออยู่');
            var stamp=data.generated_at?new Date(data.generated_at):new Date();
            updated.textContent='ตรวจล่าสุด '+stamp.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
            var cadence=document.getElementById('avsfObserveCadence');
            if(cadence)cadence.textContent='รีเฟรชทุก '+(data.refresh_seconds||30)+' วินาที';
            var summaryTime=document.getElementById('avsfDashboardSummaryTime');
            if(summaryTime)summaryTime.textContent='ตรวจเมื่อ '+stamp.toLocaleString('th-TH');
        }catch(error){
            console.warn('AVSF live health snapshot unavailable',error);
            setConnection(hasSnapshot?'is-stale':'is-error',hasSnapshot?'ข้อมูลเดิม · เชื่อมต่อรอบใหม่ไม่สำเร็จ':'ยังเชื่อมต่อ Health Monitor ไม่ได้');
            if(updated)updated.textContent='ตรวจการเชื่อมต่อ endpoint / สิทธิ์ sitecenter.view';
            if(!hasSnapshot){
                metricsBox.replaceChildren(el('div','avsf-observe-placeholder','โหลดข้อมูลสดไม่สำเร็จ — ระบบไม่ได้แสดงผลจำลอง กรุณากดตรวจสอบอีกครั้ง'));
                groupsBox.replaceChildren(el('div','avsf-observe-placeholder','ไม่สามารถยืนยันสถานะระบบได้ในขณะนี้'));
            }
        }finally{busy=false;if(refreshButton)refreshButton.disabled=false;}
    }
    if(refreshButton)refreshButton.addEventListener('click',refresh);
    document.addEventListener('visibilitychange',function(){if(!document.hidden&&isDashboardVisible())refresh();});
    if(dashboard&&'MutationObserver'in window){
        new MutationObserver(function(){if(!dashboard.hidden)refresh();}).observe(dashboard,{attributes:true,attributeFilter:['hidden']});
    }
    refresh();
    window.setInterval(function(){if(isDashboardVisible())refresh();},interval);
})();

</script>
</body>
</html>
