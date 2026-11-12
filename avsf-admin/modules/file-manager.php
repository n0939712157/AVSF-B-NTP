<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();
require_once AVSF_CORE_DIR . '/cache/cache_lib.php';
require_once AVSF_CORE_DIR . '/security/zip_safety.php';
require_once AVSF_CORE_DIR . '/updates/deployment_manager.php';
requireSuperAdmin();
requireSiteCenterAccess();
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, private', true);

$siteRoot = realpath(AVSF_ROOT);
if (!$siteRoot) { http_response_code(500); exit(__('ไม่พบโฟลเดอร์เว็บไซต์')); }
$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avsf-code-backups-' . substr(hash('sha256', $siteRoot), 0, 16);
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avsf-file-locks-' . substr(hash('sha256', $siteRoot), 0, 16) . '.json';
$editable = ['php','css','js','html','htm','md','txt','json','xml','yml','yaml','sql','ini','conf','htaccess','svg'];
$msg = '';
$msgType = 'success';

function fmSafeName($name) {
    $name = trim((string)$name);
    if ($name === '' || $name === '.' || $name === '..' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 180 || strpos($name, "\0") !== false || strpos($name, ':') !== false || preg_match('~[\\/\x00-\x1f]~', $name)) return false;
    if (preg_match('/^[A-Za-z]:/', $name)) return false;
    return $name;
}
function fmResolve($root, $relative) {
    $relative = str_replace('\\', '/', trim((string)$relative));
    if ($relative === '' || $relative === '.') return [$root, ''];
    if ($relative[0] === '/' || preg_match('/^[A-Za-z]:/', $relative) || strpos($relative, "\0") !== false) return false;
    $parts = explode('/', $relative);
    $cursor = $root;
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
        $cursor .= DIRECTORY_SEPARATOR . $part;
        if (is_link($cursor)) return false;
    }
    $path = realpath($cursor);
    if ($path === false) return false;
    if ($path !== $root && strpos($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) return false;
    return [$path, trim(str_replace('\\', '/', substr($path, strlen($root))), '/')];
}
function fmIsSensitiveFile($path) {
    $base = strtolower(basename((string)$path));
    return in_array($base, ['config.php', '.env', 'wp-config.php', 'auth.json', 'credentials.json', 'id_rsa', 'id_ed25519'], true);
}
function fmIsProtectedSystemFile($path) {
    $base = strtolower(basename((string)$path));
    return fmIsSensitiveFile($path) || in_array($base, ['database-schema.sql'], true);
}
function fmHumanSize($size) {
    $size = max(0, (int)$size);
    if ($size < 1024) return $size . ' B';
    $units = ['KB','MB','GB','TB']; $value = $size / 1024; $i = 0;
    while ($value >= 1024 && $i < count($units)-1) { $value /= 1024; $i++; }
    return number_format($value, 1) . ' ' . $units[$i];
}
function fmLockData($lockFile) {
    if (!is_file($lockFile)) return [];
    $data = json_decode((string)@file_get_contents($lockFile), true);
    return is_array($data) ? $data : [];
}
function fmDirectPathLocked($path, $lockFile) {
    $key = rtrim(str_replace('\\','/', (string)$path), '/');
    $data = fmLockData($lockFile);
    return !empty($data[$key]);
}
function fmPathLocked($path, $lockFile) {
    $key = rtrim(str_replace('\\','/', (string)$path), '/');
    $data = fmLockData($lockFile);
    foreach ($data as $lockedPath=>$locked) {
        if (!$locked) continue;
        $lockedKey = rtrim(str_replace('\\','/', (string)$lockedPath), '/');
        if ($key === $lockedKey || strpos($key, $lockedKey . '/') === 0) return true;
    }
    return false;
}
function fmHasLockedDescendant($path, $lockFile) {
    $key = rtrim(str_replace('\\','/', (string)$path), '/') . '/';
    foreach (fmLockData($lockFile) as $lockedPath=>$locked) {
        if (!$locked) continue;
        $lockedKey = rtrim(str_replace('\\','/', (string)$lockedPath), '/') . '/';
        if (strpos($lockedKey, $key) === 0) return true;
    }
    return false;
}
function fmSetLocked($path, $lockFile, $locked) {
    $data = fmLockData($lockFile);
    $key = str_replace('\\','/', (string)$path);
    if ($locked) $data[$key] = true; else unset($data[$key]);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return file_put_contents($lockFile, $json, LOCK_EX) !== false;
}
function fmCopyTree($source, $destination) {
    if (is_link($source) || file_exists($destination)) return false;
    if (is_file($source)) return @copy($source, $destination);
    if (!is_dir($source) || !@mkdir($destination, 0755, true)) return false;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $relative = ltrim(str_replace('\\','/', substr($item->getPathname(), strlen($source))), '/');
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isLink()) { avsfSafeRemoveTree($destination); return false; }
        if ($item->isDir()) { if (!is_dir($target) && !@mkdir($target, 0755, true)) { avsfSafeRemoveTree($destination); return false; } }
        elseif ($item->isFile()) { if (!@copy($item->getPathname(), $target)) { avsfSafeRemoveTree($destination); return false; } }
    }
    return true;
}
function fmZipFolder($folder, $baseName) {
    if (!class_exists('ZipArchive')) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'avsf-folder-');
    if ($tmp === false) return false;
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return false; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        if ($item->isLink()) continue;
        $absolute = $item->getPathname();
        $name = ltrim(str_replace('\\','/', substr($absolute, strlen($folder))), '/');
        if ($item->isDir()) $zip->addEmptyDir($baseName . '/' . $name);
        elseif ($item->isFile()) $zip->addFile($absolute, $baseName . '/' . $name);
    }
    $zip->close();
    return $tmp;
}
function fmClearSiteCache() {
    if (class_exists('AVSFCache') && method_exists('AVSFCache', 'clearAll')) {
        try { AVSFCache::clearAll(AVSF_CACHE_DIR); } catch (Throwable $ignored) {}
    }
}

$currentRaw = isset($_GET['dir']) && is_string($_GET['dir']) ? str_replace('\\','/',$_GET['dir']) : '';
$current = fmResolve($siteRoot, $currentRaw);
if ($current === false || !is_dir($current[0])) $current = [$siteRoot, ''];
$currentPath = $current[0];
$currentRel = $current[1];

$deploymentManager = null;
$deploymentManagerError = '';
$zipExtensionAvailable = class_exists('ZipArchive');
try { $deploymentManager = new AVSFDeploymentManager($siteRoot); }
catch (Throwable $deploymentInitError) { $deploymentManagerError = $deploymentInitError->getMessage(); }

if (isset($_GET['download']) && is_string($_GET['download'])) {
    $download = fmResolve($siteRoot, $_GET['download']);
    if ($download && is_file($download[0]) && !fmIsSensitiveFile($download[0])) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($download[0])) . '"');
        header('Content-Length: ' . filesize($download[0]));
        readfile($download[0]); exit;
    }
    http_response_code(404); exit(__('ไม่พบไฟล์'));
}
if (isset($_GET['download_dir']) && is_string($_GET['download_dir'])) {
    $directory = fmResolve($siteRoot, $_GET['download_dir']);
    if ($directory && is_dir($directory[0])) {
        $zipFile = fmZipFolder($directory[0], basename($directory[0]) ?: 'website');
        if ($zipFile) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . rawurlencode((basename($directory[0]) ?: 'website') . '.zip') . '"');
            header('Content-Length: ' . filesize($zipFile)); readfile($zipFile); @unlink($zipFile); exit;
        }
    }
    http_response_code(500); exit(__('ไม่สามารถสร้าง ZIP ได้ หรือ Hosting ไม่มี ZipArchive'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
    if (in_array($action, ['update_preview','update_apply','update_cancel','update_rollback'], true)) {
        try {
            if (!$deploymentManager) throw new RuntimeException($deploymentManagerError !== '' ? $deploymentManagerError : __('ระบบ Update ยังเริ่มทำงานไม่ได้'));
            if (in_array($action, ['update_apply','update_rollback'], true)) { @set_time_limit(600); @ignore_user_abort(true); }
            if ($action === 'update_preview') {
                $upload = $_FILES['update_zip'] ?? null;
                if (!$upload || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('กรุณาเลือกไฟล์ ZIP ที่อัปโหลดสำเร็จ');
                $previousId = (string)($_SESSION['avsf_update_preview_id'] ?? '');
                if ($previousId !== '') { try { $deploymentManager->discardPreview($previousId); } catch (Throwable $ignored) {} }
                $preview = $deploymentManager->previewUpload($upload['tmp_name'], (string)$upload['name'], (int)$upload['size']);
                $_SESSION['avsf_update_preview_id'] = $preview['id'];
                $msg = __('ตรวจสอบแพ็กเกจผ่านแล้ว · เพิ่มใหม่ ') . (int)$preview['counts']['new'] . __(' · แทนที่ ') . (int)$preview['counts']['replace'] . __(' · ข้าม ') . (int)$preview['counts']['skip'] . __(' ไฟล์');
            } elseif ($action === 'update_apply') {
                $activeId = (string)($_SESSION['avsf_update_preview_id'] ?? '');
                $postedId = (string)($_POST['update_id'] ?? '');
                if ($activeId === '' || $postedId === '' || !hash_equals($activeId, $postedId)) throw new RuntimeException('รายการ Preview ไม่ตรงกับ Session กรุณาอัปโหลด ZIP และตรวจสอบใหม่');
                if (($_POST['confirm_update'] ?? '') !== '1') throw new RuntimeException('กรุณายืนยันการสำรองและติดตั้ง Update');
                $result = $deploymentManager->apply($postedId);
                fmClearSiteCache();
                unset($_SESSION['avsf_update_preview_id']);
                $msg = __('อัปเดตระบบเป็นเวอร์ชัน ') . $result['version'] . __(' สำเร็จ · เปลี่ยนไฟล์ ') . (int)$result['files'] . __(' ไฟล์ · เก็บไฟล์เดิมไว้สำหรับย้อนกลับแล้ว');
                if (function_exists('logActivity')) logActivity('AVSF Deployment: applied ' . $result['version'] . ' (' . (int)$result['files'] . ' files)');
            } elseif ($action === 'update_cancel') {
                $activeId = (string)($_SESSION['avsf_update_preview_id'] ?? '');
                $postedId = (string)($_POST['update_id'] ?? '');
                if ($activeId !== '' && $postedId !== '' && hash_equals($activeId, $postedId)) $deploymentManager->discardPreview($activeId);
                unset($_SESSION['avsf_update_preview_id']);
                $msg = __('ยกเลิก Preview แล้ว ไฟล์เว็บไซต์ยังไม่ถูกเปลี่ยนแปลง');
            } elseif ($action === 'update_rollback') {
                if (($_POST['confirm_rollback'] ?? '') !== '1') throw new RuntimeException('กรุณายืนยันการย้อนกลับ');
                $result = $deploymentManager->rollback((string)($_POST['deployment_id'] ?? ''));
                fmClearSiteCache();
                $msg = __('ย้อนกลับ Deployment สำเร็จ · คืนไฟล์เดิม ') . (int)$result['restored'] . __(' ไฟล์');
                if (function_exists('logActivity')) logActivity('AVSF Deployment: rollback ' . $result['id']);
            }
            $msgType = 'success';
        } catch (Throwable $updateError) {
            $msg = $updateError->getMessage();
            $msgType = 'error';
        }
        $_SESSION['fm_flash'] = ['message'=>$msg, 'type'=>$msgType];
        $redirectDir = isset($_POST['dir']) && is_string($_POST['dir']) ? str_replace('\\','/',$_POST['dir']) : $currentRel;
        header('Location: file-manager.php?dir=' . rawurlencode($redirectDir) . '&update=1', true, 303);
        exit;
    }
    $postedDir = isset($_POST['dir']) && is_string($_POST['dir']) ? str_replace('\\','/',$_POST['dir']) : $currentRel;
    $dirResolved = fmResolve($siteRoot, $postedDir);
    if ($dirResolved === false || !is_dir($dirResolved[0])) {
        $msg = __('โฟลเดอร์ปลายทางไม่ถูกต้อง'); $msgType = 'error';
    } else {
        $targetDir = $dirResolved[0];
        try {
            if (fmPathLocked($targetDir, $lockFile) && !in_array($action, ['unlock','lock'], true)) throw new RuntimeException('โฟลเดอร์ปลายทางถูกล็อกอยู่ กรุณาปลดล็อกก่อน');
            if ($action === 'mkdir' || $action === 'newfile') {
                $name = fmSafeName($_POST['name'] ?? '');
                if (!$name) throw new RuntimeException('ชื่อรายการไม่ถูกต้อง');
                $path = $targetDir . DIRECTORY_SEPARATOR . $name;
                if (file_exists($path) || is_link($path)) throw new RuntimeException('มีชื่อไฟล์หรือโฟลเดอร์นี้อยู่แล้ว');
                if ($action === 'mkdir') {
                    if (!mkdir($path, 0755)) throw new RuntimeException('สร้างโฟลเดอร์ไม่สำเร็จ');
                    $msg = __('สร้างโฟลเดอร์แล้ว');
                } else {
                    if (file_put_contents($path, '', LOCK_EX) === false) throw new RuntimeException('สร้างไฟล์ไม่สำเร็จ');
                    $msg = __('สร้างไฟล์แล้ว');
                }
            } elseif ($action === 'upload') {
                $files = $_FILES['files'] ?? null;
                if (!$files || !isset($files['name']) || !is_array($files['name'])) throw new RuntimeException('กรุณาเลือกไฟล์ที่จะอัปโหลด');
                $saved = 0; $extracted = 0; $errors = [];
                $autoExtract = isset($_POST['extract_zip']) && $_POST['extract_zip'] === '1';
                foreach ($files['name'] as $i => $rawName) {
                    $name = fmSafeName(basename((string)$rawName));
                    $tmp = $files['tmp_name'][$i] ?? '';
                    $errorCode = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                    if (!$name || $errorCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $errors[] = (string)$rawName . __(': อัปโหลดไม่สำเร็จ'); continue; }
                    if ((int)($files['size'][$i] ?? 0) > 128 * 1024 * 1024) { $errors[] = $name . __(': ไฟล์เกิน 128 MB'); continue; }
                    $dest = $targetDir . DIRECTORY_SEPARATOR . $name;
                    if (file_exists($dest) || is_link($dest)) { $errors[] = $name . __(': มีไฟล์ชื่อนี้อยู่แล้ว'); continue; }
                    if (!move_uploaded_file($tmp, $dest)) { $errors[] = $name . __(': บันทึกไฟล์ไม่สำเร็จ'); continue; }
                    $saved++;
                    if ($autoExtract && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip') {
                        $folderName = fmSafeName(pathinfo($name, PATHINFO_FILENAME));
                        $extractTo = $targetDir . DIRECTORY_SEPARATOR . $folderName;
                        try {
                            if (!$folderName) throw new RuntimeException('ชื่อโฟลเดอร์แตกไฟล์ไม่ถูกต้อง');
                            $result = avsfSafeExtractZip($dest, $extractTo);
                            $extracted += (int)$result['files'];
                        } catch (Throwable $zipError) { $errors[] = $name . ': ' . $zipError->getMessage(); }
                    }
                }
                $msg = __('อัปโหลดสำเร็จ ') . $saved . __(' ไฟล์') . ($autoExtract ? __(' · แตก ZIP ได้ ') . $extracted . __(' ไฟล์') : '');
                if ($errors) { $msg .= __(' · มีรายการต้องตรวจ ') . count($errors) . __(' รายการ: ') . implode(' | ', array_slice($errors, 0, 4)); $msgType = $saved ? 'warning' : 'error'; }
                if (!$saved) $msgType = 'error';
            } elseif (in_array($action, ['rename','copy','move'], true)) {
                $source = fmResolve($siteRoot, $_POST['source'] ?? '');
                $newName = fmSafeName($_POST['name'] ?? '');
                $destinationRel = isset($_POST['target_dir']) && is_string($_POST['target_dir']) ? trim(str_replace('\\','/',$_POST['target_dir'])) : $currentRel;
                $destinationResolved = fmResolve($siteRoot, $destinationRel);
                if (!$source || !$newName || !$destinationResolved || !is_dir($destinationResolved[0])) throw new RuntimeException('ค่าต้นทางหรือปลายทางไม่ถูกต้อง');
                if ($source[0] === $siteRoot && $action !== 'copy') throw new RuntimeException('ไม่อนุญาตให้เปลี่ยนชื่อหรือย้ายโฟลเดอร์รากเว็บไซต์');
                if ($action !== 'copy' && fmIsProtectedSystemFile($source[0])) throw new RuntimeException('ไม่อนุญาตให้เปลี่ยนชื่อหรือย้ายไฟล์ระบบสำคัญ');
                if ($action === 'copy' && fmIsSensitiveFile($source[0])) throw new RuntimeException('ไม่อนุญาตให้คัดลอกไฟล์ที่มีข้อมูลลับ');
                if ((fmPathLocked($source[0], $lockFile) && $action !== 'copy') || (is_dir($source[0]) && fmHasLockedDescendant($source[0], $lockFile) && in_array($action, ['rename','move'], true))) throw new RuntimeException('รายการนี้หรือไฟล์ด้านในถูกล็อกอยู่ กรุณาปลดล็อกก่อน');
                $destination = $destinationResolved[0] . DIRECTORY_SEPARATOR . $newName;
                if (file_exists($destination) || is_link($destination)) throw new RuntimeException('ปลายทางมีชื่อซ้ำอยู่แล้ว');
                if (is_dir($source[0]) && ($destination === $source[0] || strpos($destination, rtrim($source[0], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0)) throw new RuntimeException('ไม่อนุญาตให้คัดลอกหรือย้ายโฟลเดอร์เข้าไปในตัวเอง');
                if ($action === 'rename') {
                    if (dirname($source[0]) !== $destinationResolved[0] && $destinationRel === '') throw new RuntimeException('ตำแหน่งเปลี่ยนชื่อไม่ถูกต้อง');
                    if (!@rename($source[0], $destination)) throw new RuntimeException('เปลี่ยนชื่อหรือย้ายตำแหน่งไม่สำเร็จ');
                    $msg = __('เปลี่ยนชื่อ/ตำแหน่งเรียบร้อย');
                } elseif ($action === 'copy') {
                    if (!fmCopyTree($source[0], $destination)) throw new RuntimeException('คัดลอกไม่สำเร็จ หรือพบรายการ Symbolic Link');
                    $msg = __('คัดลอกเรียบร้อย');
                } else {
                    if ($source[0] === $siteRoot || strpos($destination, $source[0] . DIRECTORY_SEPARATOR) === 0) throw new RuntimeException('ไม่อนุญาตให้ย้ายโฟลเดอร์เข้าไปในตัวเอง');
                    if (!@rename($source[0], $destination)) throw new RuntimeException('ย้ายไม่สำเร็จ');
                    $msg = __('ย้ายเรียบร้อย');
                }
            } elseif ($action === 'delete') {
                $items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
                if (!$items && isset($_POST['source']) && is_string($_POST['source'])) $items = [$_POST['source']];
                $done = 0; $skipped = 0;
                foreach ($items as $relative) {
                    $source = fmResolve($siteRoot, $relative);
                    if (!$source || $source[0] === $siteRoot || fmPathLocked($source[0], $lockFile) || (is_dir($source[0]) && fmHasLockedDescendant($source[0], $lockFile))) { $skipped++; continue; }
                    $base = basename($source[0]);
                    if (fmIsProtectedSystemFile($source[0])) { $skipped++; continue; }
                    if (is_dir($source[0])) { avsfSafeRemoveTree($source[0]); if (!file_exists($source[0])) $done++; else $skipped++; }
                    elseif (is_file($source[0]) && @unlink($source[0])) $done++;
                    else $skipped++;
                }
                $msg = __('ลบแล้ว ') . $done . __(' รายการ') . ($skipped ? __(' · ข้าม ') . $skipped . __(' รายการ (ถูกล็อกหรือไม่อนุญาต)') : '');
                if (!$done) $msgType = 'error';
            } elseif ($action === 'chmod') {
                $source = fmResolve($siteRoot, $_POST['source'] ?? '');
                $modeRaw = isset($_POST['mode']) ? preg_replace('/[^0-7]/', '', (string)$_POST['mode']) : '';
                $mode = $modeRaw !== '' ? octdec($modeRaw) : -1;
                if (!$source || fmPathLocked($source[0], $lockFile) || $mode < 0 || $mode > 0755 || ($mode & 0002)) throw new RuntimeException('โหมดสิทธิ์ไม่ถูกต้อง หรือรายการถูกล็อกอยู่ (ไม่อนุญาต 0777 หรือ world-writable)');
                if (!chmod($source[0], $mode)) throw new RuntimeException('ตั้งสิทธิ์ไม่สำเร็จ');
                $msg = __('ตั้งสิทธิ์เป็น ') . substr(sprintf('%o', fileperms($source[0])), -4) . __(' แล้ว');
            } elseif ($action === 'lock' || $action === 'unlock') {
                $items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
                if (!$items && isset($_POST['source']) && is_string($_POST['source'])) $items = [$_POST['source']];
                $done = 0;
                foreach ($items as $relative) { $source = fmResolve($siteRoot, $relative); if ($source && fmSetLocked($source[0], $lockFile, $action === 'lock')) $done++; }
                $msg = ($action === 'lock' ? __('ล็อก') : __('ปลดล็อก')) . __(' แล้ว ') . $done . __(' รายการ');
                if (!$done) $msgType = 'error';
            } elseif ($action === 'extract') {
                $source = fmResolve($siteRoot, $_POST['source'] ?? '');
                if (!$source || !is_file($source[0]) || strtolower(pathinfo($source[0], PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('กรุณาเลือกไฟล์ ZIP ที่ถูกต้อง');
                if (fmPathLocked($source[0], $lockFile)) throw new RuntimeException('ไฟล์ ZIP ถูกล็อกอยู่');
                $folderName = fmSafeName($_POST['name'] ?? pathinfo($source[0], PATHINFO_FILENAME));
                if (!$folderName) throw new RuntimeException('ชื่อโฟลเดอร์ไม่ถูกต้อง');
                $destination = $targetDir . DIRECTORY_SEPARATOR . $folderName;
                $result = avsfSafeExtractZip($source[0], $destination);
                $msg = __('แตก ZIP สำเร็จ ') . (int)$result['files'] . __(' ไฟล์ · ') . fmHumanSize($result['bytes']);
            } elseif ($action === 'save') {
                $relative = isset($_POST['file']) && is_string($_POST['file']) ? str_replace('\\','/',$_POST['file']) : '';
                $source = fmResolve($siteRoot, $relative);
                $content = $_POST['content'] ?? null;
                if (!$source || !is_file($source[0])) throw new RuntimeException('ไม่พบไฟล์');
                if (fmIsSensitiveFile($source[0])) throw new RuntimeException('ไฟล์นี้มีข้อมูลระบบสำคัญ จึงไม่เปิดให้แก้ไขผ่าน File Manager');
                if (fmPathLocked($source[0], $lockFile)) throw new RuntimeException('ไฟล์ถูกล็อก จึงบันทึกไม่ได้');
                if (!is_string($content) || strlen($content) > 10 * 1024 * 1024) throw new RuntimeException('เนื้อหาไม่ถูกต้องหรือเกิน 10 MB');
                $extension = strtolower(pathinfo($source[0], PATHINFO_EXTENSION));
                if ($extension === 'php') { try { token_get_all($content, TOKEN_PARSE); } catch (ParseError $error) { throw new RuntimeException('บันทึกไม่ได้: PHP syntax ไม่ถูกต้อง — ' . $error->getMessage()); } }
                if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) throw new RuntimeException('สร้างโฟลเดอร์สำรองไฟล์เดิมไม่สำเร็จ');
                $old = file_get_contents($source[0]);
                if ($old === false) throw new RuntimeException('อ่านไฟล์เดิมไม่สำเร็จ');
                $backupName = hash('sha256', $relative . '.' . $old) . '.' . date('YmdHis') . '.' . bin2hex(random_bytes(3)) . '.bak';
                if (file_put_contents($backupRoot . DIRECTORY_SEPARATOR . $backupName, $old, LOCK_EX) === false) throw new RuntimeException('สำรองไฟล์เดิมไม่สำเร็จ จึงยกเลิกการบันทึก');
                $temporaryPath = dirname($source[0]) . DIRECTORY_SEPARATOR . '.avsf-edit-' . bin2hex(random_bytes(8)) . '.tmp';
                $handle = @fopen($temporaryPath, 'xb');
                if (!$handle) throw new RuntimeException('สร้างไฟล์ชั่วคราวสำหรับบันทึกไม่สำเร็จ');
                $offset = 0; $contentLength = strlen($content); $writeOk = true;
                while ($offset < $contentLength) {
                    $written = fwrite($handle, substr($content, $offset, 1024 * 1024));
                    if ($written === false || $written === 0) { $writeOk = false; break; }
                    $offset += $written;
                }
                if ($writeOk && function_exists('fflush')) $writeOk = fflush($handle);
                fclose($handle);
                if (!$writeOk || $offset !== $contentLength) { @unlink($temporaryPath); throw new RuntimeException('เขียนไฟล์ชั่วคราวไม่ครบ ไฟล์เดิมยังไม่ถูกเปลี่ยน'); }
                $oldMode = @fileperms($source[0]);
                if ($oldMode !== false) @chmod($temporaryPath, $oldMode & 0777);
                if (!@rename($temporaryPath, $source[0])) { @unlink($temporaryPath); throw new RuntimeException('สลับไฟล์ใหม่ไม่สำเร็จ ไฟล์เดิมยังคงอยู่และมีไฟล์สำรองแล้ว'); }
                if ($extension === 'php' && function_exists('opcache_invalidate')) @opcache_invalidate($source[0], true);
                $msg = __('บันทึกไฟล์เรียบร้อย พร้อมสำรองไฟล์เดิมก่อนแก้ไข');
                fmClearSiteCache();
            } else {
                throw new RuntimeException('ไม่รู้จักคำสั่งนี้');
            }
        } catch (Throwable $error) {
            $msg = $error->getMessage(); $msgType = 'error';
        }
    }
    if (in_array($action, ['mkdir','newfile','upload','rename','copy','move','delete','chmod','extract','save'], true) && in_array($msgType, ['success','warning'], true)) {
        fmClearSiteCache();
        if (function_exists('logActivity')) logActivity('File Manager: ' . (function_exists('mb_substr') ? mb_substr($msg, 0, 180, 'UTF-8') : substr($msg, 0, 180)));
    } elseif (in_array($action, ['lock','unlock'], true) && $msgType === 'success' && function_exists('logActivity')) {
        logActivity('File Manager: ' . $msg);
    }
    $_SESSION['fm_flash'] = ['message'=>$msg, 'type'=>$msgType];
    $redirectDir = isset($_POST['dir']) && is_string($_POST['dir']) ? str_replace('\\','/',$_POST['dir']) : $currentRel;
    $redirectFile = ($action === 'save' && isset($_POST['file'])) ? '&file=' . rawurlencode((string)$_POST['file']) : '';
    header('Location: file-manager.php?dir=' . rawurlencode($redirectDir) . $redirectFile, true, 303);
    exit;
}

if (isset($_SESSION['fm_flash'])) {
    $msg = (string)($_SESSION['fm_flash']['message'] ?? '');
    $msgType = (string)($_SESSION['fm_flash']['type'] ?? 'success');
    unset($_SESSION['fm_flash']);
}
$updatePreview = false;
$updatePreviewId = (string)($_SESSION['avsf_update_preview_id'] ?? '');
$updateHistory = [];
if ($deploymentManager) {
    if ($updatePreviewId !== '') {
        try { $updatePreview = $deploymentManager->getPreview($updatePreviewId); } catch (Throwable $ignored) { $updatePreview = false; }
        if (!$updatePreview) unset($_SESSION['avsf_update_preview_id']);
    }
    try { $updateHistory = $deploymentManager->listDeployments(8); } catch (Throwable $ignored) { $updateHistory = []; }
}
$selectedRel = isset($_GET['file']) && is_string($_GET['file']) ? str_replace('\\','/',$_GET['file']) : '';
$selected = $selectedRel !== '' ? fmResolve($siteRoot, $selectedRel) : false;
$selectedPath = $selected && is_file($selected[0]) ? $selected[0] : false;
$editableFile = $selectedPath && !fmIsSensitiveFile($selectedPath) && in_array(strtolower(pathinfo($selectedPath, PATHINFO_EXTENSION)), array_merge($editable, ['']), true);
$fileContent = $editableFile ? (string)file_get_contents($selectedPath) : '';
$folders = []; $items = [];
foreach (scandir($currentPath) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..' || $entry === 'cache') continue;
    $full = $currentPath . DIRECTORY_SEPARATOR . $entry;
    if (is_link($full)) continue;
    $relative = $currentRel === '' ? $entry : $currentRel . '/' . $entry;
    $common = ['name'=>$entry, 'rel'=>$relative, 'mtime'=>(int)filemtime($full), 'perms'=>substr(sprintf('%o', fileperms($full)), -4), 'locked'=>fmPathLocked($full, $lockFile), 'directLocked'=>fmDirectPathLocked($full, $lockFile)];
    if (is_dir($full)) $folders[] = $common + ['size'=>0, 'ext'=>'', 'type'=>'folder'];
    elseif (is_file($full)) $items[] = $common + ['size'=>(int)filesize($full), 'ext'=>strtolower(pathinfo($entry, PATHINFO_EXTENSION)), 'type'=>'file', 'editable'=>!fmIsSensitiveFile($full) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), array_merge($editable, ['']), true)];
}
usort($folders, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
usort($items, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
$crumb = []; $walking = '';
foreach (array_filter(explode('/', $currentRel), 'strlen') as $part) { $walking = ($walking ? $walking . '/' : '') . $part; $crumb[] = ['name'=>$part, 'rel'=>$walking]; }
?>
<!doctype html>
<html lang="<?php echo avsf_lang_attr(); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#f3f6fb"><title>File Manager — AVSF</title>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<style>
:root{color-scheme:light;--fm-bg:#f3f6fb;--fm-surface:#fff;--fm-surface2:#f8fafc;--fm-text:#19304f;--fm-muted:#667790;--fm-border:#dfe6f0;--fm-accent:#267cf4;--fm-shadow:0 4px 14px rgba(23,48,79,.06);--fm-topbar-bg:rgba(255,255,255,.92);--fm-editor-bg:#fff;--fm-editor-text:#19304f;--fm-msg-ok-bg:#e8f7ef;--fm-msg-ok-text:#18784d;--fm-msg-ok-border:#b8e4ca;--fm-msg-err-bg:#fff0f0;--fm-msg-err-text:#b42331;--fm-msg-err-border:#f3c4c7;--fm-msg-warn-bg:#fff7e6;--fm-msg-warn-text:#9a6408;--fm-msg-warn-border:#f0d99c;--fm-update-bg:linear-gradient(145deg,#ffffff,#f6f9ff);--fm-update-border:#dfe6f0;--fm-update-tag-border:#c8d3e4;--fm-update-tag-text:#4a5a75;--fm-upload-border:#b8c4d8;--fm-row-hover:rgba(38,124,244,.04);--fm-link:#267cf4;--fm-folder:#b8860b;}
:root[data-admin-theme="dark"]{color-scheme:dark;--fm-bg:#090b10;--fm-surface:#11151f;--fm-surface2:#161b26;--fm-text:#e9edf6;--fm-muted:#a5afc1;--fm-border:#293142;--fm-accent:#4c8df6;--fm-shadow:0 4px 14px rgba(0,0,0,.35);--fm-topbar-bg:rgba(14,18,27,.96);--fm-editor-bg:#080b12;--fm-editor-text:#e6edf7;--fm-msg-ok-bg:#103828;--fm-msg-ok-text:#9df0c5;--fm-msg-ok-border:#225c48;--fm-msg-err-bg:#421b27;--fm-msg-err-text:#ffc1d0;--fm-msg-err-border:#803347;--fm-msg-warn-bg:#3a2c15;--fm-msg-warn-text:#ffe0a0;--fm-msg-warn-border:#806229;--fm-update-bg:linear-gradient(145deg,#151d2b,#101620);--fm-update-border:#34445e;--fm-update-tag-border:#4b5c7a;--fm-update-tag-text:#c9d8f1;--fm-upload-border:#53617a;--fm-row-hover:rgba(255,255,255,.025);--fm-link:#8bb4ff;--fm-folder:#ffc96b;}
*{box-sizing:border-box}html,body{width:100%;max-width:none;min-width:0;margin:0;min-height:100%;overflow-x:clip;background:var(--fm-bg);color:var(--fm-text);font:14px/1.5 system-ui,-apple-system,"Segoe UI","Noto Sans Thai",sans-serif}body{padding-bottom:env(safe-area-inset-bottom)}a{color:inherit}.avsf-fm-topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:12px;min-height:62px;padding:10px clamp(12px,2vw,22px);border-bottom:1px solid var(--fm-border);background:var(--fm-topbar-bg);backdrop-filter:blur(14px)}.avsf-fm-topbar h1{margin:0;font-size:clamp(15px,2vw,19px);font-weight:850}.avsf-fm-tools{display:flex;flex-wrap:wrap;gap:7px;margin-left:auto}.avsf-fm-btn{display:inline-flex;min-height:40px;align-items:center;justify-content:center;gap:7px;padding:9px 13px;border:1px solid transparent;border-radius:10px;background:var(--fm-accent);color:#fff;font:inherit;font-size:12px;font-weight:700;line-height:1.2;text-decoration:none;cursor:pointer;white-space:nowrap}.avsf-fm-btn.avsf-secondary{border-color:var(--fm-border);background:var(--fm-surface2);color:var(--fm-text)}.avsf-fm-btn.avsf-danger{background:#7f1d32;color:#ffe3e8}.avsf-fm-btn:disabled{opacity:.45;cursor:not-allowed}.avsf-fm-toolbar{display:flex;flex-wrap:wrap;gap:9px;padding:12px clamp(12px,2vw,22px);border-bottom:1px solid var(--fm-border);background:var(--fm-surface)}.avsf-fm-toolbar form{display:flex;flex:1 1 220px;min-width:0;gap:7px;align-items:center}.avsf-fm-toolbar input:not([type=file]){width:100%;min-width:0;min-height:40px;padding:8px 10px;border:1px solid var(--fm-border);border-radius:9px;background:var(--fm-bg);color:var(--fm-text);font:inherit}.avsf-fm-upload{display:flex;flex:1 1 340px;flex-wrap:wrap;gap:8px;align-items:center;min-width:0;padding:8px 10px;border:1px dashed var(--fm-upload-border);border-radius:12px;background:var(--fm-surface2)}.avsf-fm-upload input[type=file]{max-width:100%;min-width:0;flex:1 1 150px;color:var(--fm-muted);font:inherit;font-size:12px}.avsf-fm-checkline{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--fm-muted);white-space:nowrap}.avsf-fm-layout{display:grid;grid-template-columns:minmax(190px,235px) minmax(0,1fr);min-height:calc(100vh - 145px)}.avsf-fm-sidebar{min-width:0;padding:14px 10px;border-right:1px solid var(--fm-border);background:var(--fm-surface)}.avsf-fm-sidebar strong{display:block;margin:0 8px 10px;color:var(--fm-muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}.avsf-fm-tree a{display:block;overflow:hidden;padding:9px 10px;border-radius:9px;color:var(--fm-muted);font-size:12px;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.avsf-fm-tree a.avsf-active,.avsf-fm-tree a:hover{background:rgba(255,45,120,.11);color:var(--fm-link)}.avsf-fm-main{min-width:0;padding:clamp(10px,2vw,18px);overflow:auto}.avsf-fm-message{margin:12px clamp(12px,2vw,22px) 0;padding:11px 14px;border:1px solid var(--fm-msg-ok-border);border-radius:11px;background:var(--fm-msg-ok-bg);color:var(--fm-msg-ok-text)}.avsf-fm-message.avsf-error{border-color:var(--fm-msg-err-border);background:var(--fm-msg-err-bg);color:var(--fm-msg-err-text)}.avsf-fm-message.avsf-warning{border-color:var(--fm-msg-warn-border);background:var(--fm-msg-warn-bg);color:var(--fm-msg-warn-text)}.avsf-fm-breadcrumb{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:12px;color:var(--fm-muted);font-size:12px;overflow-wrap:anywhere}.avsf-fm-breadcrumb a{color:var(--fm-link);text-decoration:none}.avsf-fm-actions{display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin-bottom:12px;padding:10px;border:1px solid var(--fm-border);border-radius:12px;background:var(--fm-surface)}.avsf-fm-actions form{display:flex;gap:6px;flex:1 1 230px;min-width:0}.avsf-fm-actions input{min-width:0;width:100%;min-height:38px;padding:7px 9px;border:1px solid var(--fm-border);border-radius:8px;background:var(--fm-bg);color:var(--fm-text);font:inherit}.avsf-fm-table-wrap{overflow:hidden;border:1px solid var(--fm-border);border-radius:13px;background:var(--fm-surface);box-shadow:var(--fm-shadow)}.avsf-fm-table{width:100%;border-collapse:collapse;table-layout:auto}.avsf-fm-table th,.avsf-fm-table td{padding:11px 10px;border-bottom:1px solid var(--fm-border);text-align:left;font-size:12px}.avsf-fm-table th{background:var(--fm-surface2);color:var(--fm-muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.avsf-fm-table tr:last-child td{border-bottom:0}.avsf-fm-table tr:hover td{background:var(--fm-row-hover)}.avsf-fm-row{touch-action:pan-y;-webkit-touch-callout:none}.avsf-fm-item-name{display:inline-flex;align-items:center;gap:8px;min-width:0;-webkit-user-select:none;user-select:none;color:var(--fm-text);font-weight:650;text-decoration:none;overflow-wrap:anywhere}.avsf-fm-item-name.avsf-folder{color:var(--fm-folder)}.avsf-fm-item-icon{display:grid;width:32px;height:32px;flex:none;place-items:center;border:1px solid var(--fm-border);border-radius:9px;background:var(--fm-surface2);font-size:15px}.avsf-fm-item-sub{display:block;color:var(--fm-muted);font-size:10px;font-weight:500}.avsf-fm-row-actions{display:flex;gap:5px;align-items:center;white-space:nowrap}.avsf-fm-row-actions .avsf-fm-btn{min-height:33px;padding:6px 9px;font-size:11px}.avsf-fm-check{width:18px;height:18px;accent-color:var(--fm-accent)}.avsf-fm-empty{padding:40px 16px;text-align:center;color:var(--fm-muted)}.avsf-fm-editor{margin-top:14px;padding:13px;border:1px solid var(--fm-border);border-radius:13px;background:var(--fm-surface)}.avsf-fm-editor-head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}.avsf-fm-editor textarea{display:block;width:100%;min-height:55vh;resize:vertical;padding:14px;border:1px solid var(--fm-border);border-radius:10px;background:var(--fm-editor-bg);color:var(--fm-editor-text);font:12px/1.65 ui-monospace,Consolas,monospace}.avsf-fm-empty-note{color:var(--fm-muted);font-size:12px}.avsf-fm-modal-backdrop{position:fixed;inset:0;z-index:100;display:none;align-items:flex-end;justify-content:center;padding:14px;background:rgba(0,0,0,.62);backdrop-filter:blur(4px)}.avsf-fm-modal-backdrop.avsf-active{display:flex}.avsf-fm-action-sheet{width:min(560px,100%);max-height:min(82vh,760px);overflow:auto;padding:18px;border:1px solid var(--fm-border);border-radius:20px 20px 14px 14px;background:var(--fm-surface);box-shadow:0 25px 80px rgba(0,0,0,.5)}.avsf-fm-sheet-head{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:14px}.avsf-fm-sheet-head strong{overflow-wrap:anywhere}.avsf-fm-sheet-close{width:40px;height:40px;border:1px solid var(--fm-border);border-radius:12px;background:var(--fm-surface2);color:var(--fm-text);font-size:20px}.avsf-fm-sheet-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:14px}.avsf-fm-sheet-grid [hidden]{display:none!important}.avsf-fm-sheet-grid a,.avsf-fm-sheet-grid button{display:flex;min-height:64px;flex-direction:column;align-items:center;justify-content:center;gap:5px;border:1px solid var(--fm-border);border-radius:12px;background:var(--fm-surface2);color:var(--fm-text);font:inherit;font-size:11px;font-weight:700;text-decoration:none;cursor:pointer}.avsf-fm-sheet-grid span{font-size:20px}.avsf-fm-sheet-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.avsf-fm-sheet-form label{grid-column:1/-1;color:var(--fm-muted);font-size:11px}.avsf-fm-sheet-form input{width:100%;min-width:0;min-height:42px;padding:9px 10px;border:1px solid var(--fm-border);border-radius:9px;background:var(--fm-bg);color:var(--fm-text);font:inherit}.avsf-fm-progress{position:fixed;inset:0;z-index:200;display:none;place-items:center;padding:18px;background:rgba(0,0,0,.72);backdrop-filter:blur(6px)}.avsf-fm-progress.avsf-active{display:grid}.avsf-fm-progress-card{width:min(420px,100%);padding:22px;border:1px solid var(--fm-border);border-radius:18px;background:var(--fm-surface);box-shadow:var(--fm-shadow)}.avsf-fm-progress-title{font-size:16px;font-weight:850}.avsf-fm-progress-track{height:10px;margin:14px 0 8px;overflow:hidden;border-radius:99px;background:var(--fm-surface2)}.avsf-fm-progress-fill{width:0;height:100%;border-radius:99px;background:linear-gradient(90deg,#ff2d78,#9b5cff);transition:width .15s}.avsf-fm-progress-text{color:var(--fm-muted);font-size:12px}.avsf-fm-spinner{display:inline-block;width:15px;height:15px;border:2px solid rgba(255,255,255,.24);border-top-color:var(--fm-accent);border-radius:50%;animation:fmspin .8s linear infinite;vertical-align:middle}@keyframes fmspin{to{transform:rotate(360deg)}}
@media(max-width:900px){.avsf-fm-layout{grid-template-columns:1fr}.avsf-fm-sidebar{max-height:130px;overflow:auto;border-right:0;border-bottom:1px solid var(--fm-border)}.avsf-fm-tree{display:flex;gap:6px;overflow:auto}.avsf-fm-tree a{flex:none;max-width:180px}.avsf-fm-table th:nth-child(4),.avsf-fm-table td:nth-child(4){display:none}}
@media(max-width:900px){.avsf-fm-table th:nth-child(5),.avsf-fm-table td:nth-child(5){display:none}.avsf-fm-row-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px;min-width:0;white-space:normal}.avsf-fm-row-actions>*{min-width:0;max-width:100%}.avsf-fm-row-actions .avsf-fm-btn{width:100%;min-width:0;max-width:100%;white-space:normal;overflow-wrap:anywhere;padding:6px;font-size:10px}}
@media(max-width:620px){.avsf-fm-topbar{align-items:flex-start;flex-wrap:wrap}.avsf-fm-tools{width:100%;margin-left:0}.avsf-fm-tools .avsf-fm-btn{flex:1}.avsf-fm-toolbar{display:grid;grid-template-columns:1fr}.avsf-fm-toolbar form{width:100%;flex:none}.avsf-fm-upload{display:grid;grid-template-columns:minmax(0,1fr);width:100%}.avsf-fm-upload .avsf-fm-btn{width:100%}.avsf-fm-main{padding:10px}.avsf-fm-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-fm-actions form{grid-column:1/-1}.avsf-fm-table-wrap{overflow:visible;border:0;background:transparent;box-shadow:none}.avsf-fm-table,.avsf-fm-table tbody{display:block;width:100%}.avsf-fm-table thead{display:none}.avsf-fm-table tr{display:grid;grid-template-columns:28px minmax(0,1fr);gap:0 8px;margin-bottom:9px;padding:10px;border:1px solid var(--fm-border);border-radius:13px;background:var(--fm-surface)}.avsf-fm-table td{display:block;min-width:0;padding:3px 0;border:0}.avsf-fm-table td:first-child{grid-row:1/4;grid-column:1;align-self:start;padding-top:7px}.avsf-fm-table td:nth-child(2){grid-column:2;grid-row:1}.avsf-fm-table td:nth-child(3),.avsf-fm-table td:nth-child(4),.avsf-fm-table td:nth-child(5){display:inline-block;grid-row:2;color:var(--fm-muted);font-size:10px;margin-right:9px}.avsf-fm-table td:last-child{grid-column:2;grid-row:3;margin-top:5px}.avsf-fm-row-actions .avsf-fm-btn{min-height:38px;flex:1}.avsf-fm-item-name{font-size:12px}.avsf-fm-sheet-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.avsf-fm-sheet-form{grid-template-columns:1fr}.avsf-fm-sheet-form label{grid-column:1}.avsf-fm-editor textarea{min-height:45vh}.avsf-fm-modal-backdrop{padding:0;align-items:flex-end}.avsf-fm-action-sheet{padding-bottom:calc(18px + env(safe-area-inset-bottom));border-radius:20px 20px 0 0}}
@media(min-width:621px) and (max-width:1024px) and (orientation:landscape){.avsf-fm-layout{grid-template-columns:180px minmax(0,1fr)}.avsf-fm-sidebar{max-height:calc(100vh - 145px)}.avsf-fm-table th,.avsf-fm-table td{padding:8px 7px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;transition-duration:.01ms!important}}

.avsf-fm-update-panel{margin:12px clamp(12px,2vw,22px);padding:16px;border:1px solid var(--fm-update-border);border-radius:15px;background:var(--fm-update-bg);box-shadow:var(--fm-shadow)}.avsf-fm-update-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:13px}.avsf-fm-update-head strong{display:block;font-size:15px;font-weight:850}.avsf-fm-update-head span:not(.avsf-fm-update-tag){display:block;margin-top:3px;color:var(--fm-muted);font-size:11px}.avsf-fm-update-tag,.avsf-fm-update-version{flex:none;padding:5px 9px;border:1px solid var(--fm-update-tag-border);border-radius:999px;color:var(--fm-update-tag-text);font-size:10px}.avsf-fm-update-upload{display:flex;flex-wrap:wrap;gap:9px;align-items:center}.avsf-fm-update-upload input[type=file]{flex:1 1 220px;min-width:0;color:var(--fm-muted);font:inherit;font-size:12px}.avsf-fm-update-note,.avsf-fm-update-limits{margin-top:9px;color:var(--fm-muted);font-size:11px;line-height:1.6}.avsf-fm-update-warning{margin-top:12px;padding:10px 12px;border:1px solid var(--fm-msg-warn-border);border-radius:10px;background:var(--fm-msg-warn-bg);color:var(--fm-msg-warn-text);font-size:11px;line-height:1.6}.avsf-fm-update-preview{margin-top:15px;padding-top:14px;border-top:1px solid var(--fm-border)}.avsf-fm-update-preview-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}.avsf-fm-update-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}.avsf-fm-update-stats>div{padding:10px;border:1px solid var(--fm-border);border-radius:11px;background:var(--fm-surface2)}.avsf-fm-update-stats b{display:block;font-size:20px}.avsf-fm-update-stats span{display:block;color:var(--fm-muted);font-size:10px}.avsf-fm-update-verify{margin:8px 0;color:#9ce6be;font-size:11px}.avsf-fm-update-file-list{margin-top:12px}.avsf-fm-update-file-list>strong,.avsf-fm-update-history>strong{display:block;margin-bottom:8px;font-size:12px}.avsf-fm-update-file-list ul{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 14px;max-height:220px;overflow:auto;margin:0;padding:0;list-style:none}.avsf-fm-update-file-list li{display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-width:0;padding:5px 0;border-bottom:1px solid var(--fm-border)}.avsf-fm-update-file-list code{min-width:0;overflow-wrap:anywhere;color:var(--fm-text);font-size:10px}.avsf-fm-update-file-list small{width:100%;color:#e6c98d;font-size:9px}.avsf-fm-file-status{padding:2px 6px;border-radius:999px;font-size:9px;font-weight:800}.avsf-fm-file-status.avsf-new{background:#173b2b;color:#a5f3c5}.avsf-fm-file-status.avsf-replace{background:#233b67;color:#bdd6ff}.avsf-fm-file-status.avsf-skip{background:#4a351a;color:#ffdfa0}.avsf-fm-update-actions{display:flex;flex-wrap:wrap;gap:9px;align-items:center;margin-top:13px}.avsf-fm-update-actions form{display:flex;flex-wrap:wrap;gap:9px;align-items:center}.avsf-fm-update-actions label{display:flex;gap:7px;align-items:center;color:var(--fm-muted);font-size:11px}.avsf-fm-update-history{margin-top:15px;padding-top:13px;border-top:1px solid var(--fm-border)}.avsf-fm-update-history-row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--fm-border)}.avsf-fm-update-history-row b,.avsf-fm-update-history-row span{display:block}.avsf-fm-update-history-row span{margin-top:3px;color:var(--fm-muted);font-size:10px}.avsf-fm-update-history-row .avsf-fm-btn{min-height:34px;padding:6px 10px;font-size:10px}

/* Full-screen responsive source editor. Uses dynamic viewport units for mobile browser chrome. */
.avsf-fm-editor-backdrop{position:fixed;inset:0;z-index:320;display:none;width:100%;height:100vh;height:100dvh;min-height:0;padding:0;margin:0;overflow:hidden;background:var(--fm-bg);}
.avsf-fm-editor-backdrop.avsf-active{display:flex;align-items:stretch;justify-content:stretch;}
.avsf-fm-editor-dialog{display:flex;width:100%;height:100%;min-width:0;min-height:0;max-width:none;max-height:none;overflow:hidden;border:0;border-radius:0;background:var(--fm-bg);box-shadow:none;}
.avsf-fm-editor-form{display:flex;flex:1 1 auto;flex-direction:column;width:100%;height:100%;min-width:0;min-height:0;overflow:hidden;}
.avsf-fm-editor-header{position:relative;z-index:2;display:flex;flex:0 0 auto;align-items:center;justify-content:space-between;gap:12px;min-height:68px;padding:calc(10px + env(safe-area-inset-top)) max(14px,env(safe-area-inset-right)) 10px max(14px,env(safe-area-inset-left));border-bottom:1px solid var(--fm-border);background:var(--fm-surface);}
.avsf-fm-editor-heading{display:flex;flex:1 1 auto;flex-direction:column;gap:2px;min-width:0;}
.avsf-fm-editor-toolbar{display:flex;flex:0 0 auto;flex-wrap:wrap;gap:6px;align-items:center;padding:8px 12px;border-bottom:1px solid var(--fm-border);background:var(--fm-surface);}
.avsf-fm-editor-tool{display:inline-flex;flex:0 0 auto;min-width:76px;min-height:34px;align-items:center;justify-content:center;gap:5px;padding:6px 9px;border:1px solid var(--fm-border);border-radius:8px;background:var(--fm-surface2);color:var(--fm-text);font:inherit;font-size:11px;font-weight:650;line-height:1.2;cursor:pointer;transition:background .15s,border-color .15s,transform .15s;}
.avsf-fm-editor-tool:hover,.avsf-fm-editor-tool:focus-visible{border-color:var(--fm-accent);background:color-mix(in srgb,var(--fm-accent) 9%,var(--fm-surface));outline:2px solid color-mix(in srgb,var(--fm-accent) 24%,transparent);outline-offset:1px;}
.avsf-fm-editor-tool:active{transform:translateY(1px);}
.avsf-fm-editor-tool svg{width:15px;height:15px;flex:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
.avsf-fm-editor-form textarea.avsf-fm-editor-wrapped{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;}
.avsf-fm-editor-heading strong{font-size:15px;font-weight:850;line-height:1.3;}
.avsf-fm-editor-heading span{overflow:hidden;color:var(--fm-muted);font-size:11px;text-overflow:ellipsis;white-space:nowrap;}
.avsf-fm-editor-buttons{display:flex;flex:0 0 auto;gap:8px;align-items:center;}
.avsf-fm-editor-buttons .avsf-fm-btn{min-height:42px;padding:9px 13px;font-size:12px;}
.avsf-fm-editor-form textarea{display:block;flex:1 1 auto;width:100%;height:0;min-height:0;max-height:none;resize:none;overflow:auto;margin:0;padding:16px max(14px,env(safe-area-inset-right)) 20px max(14px,env(safe-area-inset-left));border:0;border-radius:0;outline:none;background:var(--fm-editor-bg);color:var(--fm-editor-text);font:13px/1.7 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;tab-size:4;white-space:pre;overflow-wrap:normal;-webkit-overflow-scrolling:touch;}
.avsf-fm-editor-form textarea:focus{box-shadow:inset 0 0 0 1px rgba(255,45,120,.16);}
.avsf-fm-editor-footer{display:flex;flex:0 0 auto;align-items:center;justify-content:space-between;gap:12px;min-height:34px;padding:6px max(14px,env(safe-area-inset-right)) calc(6px + env(safe-area-inset-bottom)) max(14px,env(safe-area-inset-left));border-top:1px solid var(--fm-border);background:var(--fm-surface);color:var(--fm-muted);font-size:10px;}
.avsf-fm-editor-notice{flex:0 0 auto;margin:8px 12px 0;padding:8px 11px;border:1px solid var(--fm-msg-ok-border);border-radius:9px;background:var(--fm-msg-ok-bg);color:var(--fm-msg-ok-text);font-size:12px;}
.avsf-fm-editor-notice.is-error{border-color:var(--fm-msg-err-border);background:var(--fm-msg-err-bg);color:var(--fm-msg-err-text);}
.avsf-fm-editor-notice.is-warning{border-color:var(--fm-msg-warn-border);background:var(--fm-msg-warn-bg);color:var(--fm-msg-warn-text);}
@media(max-width:700px){.avsf-fm-editor-toolbar{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;padding:7px 9px}.avsf-fm-editor-tool{min-width:0;width:100%;padding:6px 4px;font-size:10px}.avsf-fm-editor-tool svg{width:14px;height:14px}.avsf-fm-editor-header{align-items:flex-start;flex-direction:column;gap:9px;padding-top:calc(10px + env(safe-area-inset-top));}.avsf-fm-editor-heading{width:100%;}.avsf-fm-editor-buttons{display:grid;width:100%;grid-template-columns:minmax(0,1fr) minmax(76px,auto);}.avsf-fm-editor-buttons .avsf-fm-btn{width:100%;min-height:44px;}.avsf-fm-editor-form textarea{padding-top:12px;font-size:12px;line-height:1.65;}.avsf-fm-editor-footer{font-size:9px;}}
@media(orientation:landscape) and (max-height:520px){.avsf-fm-editor-header{min-height:54px;flex-direction:row;align-items:center;padding-top:calc(5px + env(safe-area-inset-top));padding-bottom:5px;}.avsf-fm-editor-buttons .avsf-fm-btn{min-height:38px;}.avsf-fm-editor-form textarea{padding-top:8px;}}
@media(max-width:620px){.avsf-fm-main,.avsf-fm-main *{box-sizing:border-box}.avsf-fm-table tr{min-width:0;max-width:100%;overflow:hidden}.avsf-fm-table td:last-child{width:100%;max-width:100%;min-width:0;overflow:hidden}.avsf-fm-row-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%;max-width:100%;min-width:0;gap:6px;white-space:normal;overflow:hidden}.avsf-fm-row-actions>*{min-width:0;max-width:100%;box-sizing:border-box}.avsf-fm-item-name{max-width:100%;overflow-wrap:anywhere;word-break:break-word}.avsf-fm-row-actions .avsf-fm-btn{display:inline-flex;width:100%;min-width:0;max-width:100%;min-height:38px;flex:none;white-space:normal;overflow-wrap:anywhere;word-break:break-word;padding:6px 5px;font-size:10px;gap:4px}.avsf-fm-row-actions .avsf-fm-btn svg{width:13px;height:13px;flex:none}.avsf-fm-language-control{float:none!important;display:flex;align-items:center}.avsf-fm-tools{align-items:center;justify-content:flex-start}.avsf-fm-tools>.avsf-fm-language-control{margin-left:auto}}

</style></head>
<body data-admin-theme="light">
<header class="avsf-fm-topbar"><h1><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="vertical-align:-3px;margin-right:6px"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>File Manager · AVSF</h1><div class="avsf-fm-tools"><a class="avsf-fm-btn avsf-secondary" href="site-center.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="m12 19-7-7 7-7M19 12H5"/></svg>ศูนย์เว็บ</a><a class="avsf-fm-btn avsf-secondary" href="?download_dir=<?=rawurlencode($currentRel)?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>ZIP</a></div></header>
<?php if ($msg !== ''): ?><div class="avsf-fm-message <?= e(avsfClass(in_array($msgType,['error','warning'],true)?e($msgType):'')) ?>"><?=e($msg)?></div><?php endif; ?>
<section class="avsf-fm-update-panel" id="avsfUpdatePanel">
  <div class="avsf-fm-update-head"><div><strong><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="vertical-align:-3px;margin-right:5px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></svg><?php _e('อัปเดต AVSF ผ่าน ZIP'); ?></strong><span><?php _e('อัปโหลดแพ็กเกจ ตรวจสอบรายการไฟล์ สำรองไฟล์เดิม และติดตั้งจากหลังบ้านได้โดยไม่ต้องใช้ FTP/SSH'); ?></span></div><span class="avsf-fm-update-tag"><?php _e('ระบบอัปเดตไฟล์'); ?></span></div>
  <?php if ($deploymentManagerError !== ''): ?><div class="avsf-fm-update-warning"><?php _e('ระบบ Deploy ยังไม่พร้อม:'); ?> <?=e($deploymentManagerError)?></div><?php else: ?>
  <?php if (!$zipExtensionAvailable): ?><div class="avsf-fm-update-warning"><?php _e('Hosting ยังไม่มี PHP ZipArchive จึงไม่สามารถตรวจ/ติดตั้ง ZIP ได้ กรุณาเปิด Extension นี้ใน PHP Selector ของ Hosting'); ?></div><?php endif; ?>
  <form class="avsf-fm-update-upload" id="fmUpdateUploadForm" method="post" enctype="multipart/form-data" action="file-manager.php?dir=<?=rawurlencode($currentRel)?>&update=1">
    <input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="update_preview"><input type="hidden" name="dir" value="<?=e($currentRel)?>">
    <input type="file" name="update_zip" accept=".zip,application/zip" required aria-label="<?php echo __('เลือก ZIP สำหรับอัปเดตระบบ'); ?>" <?=!$zipExtensionAvailable?'disabled':''?>>
    <button class="avsf-fm-btn" type="submit" <?=!$zipExtensionAvailable?'disabled':''?>>ตรวจสอบ ZIP และแสดงตัวอย่าง</button>
  </form>
  <div class="avsf-fm-update-note"><?php _e('ระบบจะเก็บ config.php, .htaccess, uploads, cache, quarantine, logs และข้อมูลใช้งานจริงไว้ ไม่ลบไฟล์ที่ไม่ได้อยู่ในแพ็กเกจ และไม่รัน SQL/แก้ฐานข้อมูลอัตโนมัติ ต้องมี PHP ZipArchive และสิทธิ์เขียนไฟล์บน Hosting'); ?></div>
  <div class="avsf-fm-update-limits"><?php _e('ขนาดอัปโหลดที่ PHP อนุญาต:'); ?> <?=e((string)ini_get('upload_max_filesize'))?> <?php _e('· ขนาด POST:'); ?> <?=e((string)ini_get('post_max_size'))?> <?php _e('· เวลา PHP:'); ?> <?=e((string)ini_get('max_execution_time'))?> <?php _e('วินาที'); ?></div>
  <?php if ($updatePreview): ?>
    <div class="avsf-fm-update-preview"><div class="avsf-fm-update-preview-head"><strong><?php _e('ผลตรวจแพ็กเกจ:'); ?> <?=e($updatePreview['original_name'])?></strong><span class="avsf-fm-update-version"><?php _e('เวอร์ชัน'); ?> <?=e($updatePreview['package_version'])?></span></div>
      <div class="avsf-fm-update-stats"><div><b><?=number_format((int)$updatePreview['counts']['new'])?></b><span><?php _e('ไฟล์เพิ่มใหม่'); ?></span></div><div><b><?=number_format((int)$updatePreview['counts']['replace'])?></b><span><?php _e('ไฟล์แทนที่'); ?></span></div><div><b><?=number_format((int)$updatePreview['counts']['skip'])?></b><span><?php _e('ไฟล์ที่เก็บเดิม'); ?></span></div></div>
      <div class="avsf-fm-update-verify"><?=!empty($updatePreview['manifest_verified'])?__('✓ ตรวจ SHA-256 ตาม Manifest ครบ ').(int)$updatePreview['manifest_files_verified'].__(' ไฟล์'):__('⚠ ZIP รุ่นเก่าไม่มี Update Manifest — ตรวจโครงสร้างและ PHP Syntax แล้ว แต่ไม่มี Hash Manifest สำหรับตรวจเทียบ')?></div>
      <div class="avsf-fm-update-file-list"><strong><?php _e('ตัวอย่างรายการไฟล์ (แสดงสูงสุด 18 รายการ)'); ?></strong><ul><?php foreach (array_slice($updatePreview['files'],0,18) as $row): ?><li><span class="avsf-fm-file-status avsf-<?=e($row['status'])?>"><?= $row['status']==='new'?__('เพิ่ม'):($row['status']==='replace'?__('แทนที่'):__('เก็บเดิม')) ?></span><code><?=e($row['path'])?></code><?php if ($row['reason']!==''): ?><small><?=e($row['reason'])?></small><?php endif; ?></li><?php endforeach; ?></ul><?php if (count($updatePreview['files'])>18): ?><small><?php _e('และอีก'); ?> <?=number_format(count($updatePreview['files'])-18)?> <?php _e('ไฟล์'); ?></small><?php endif; ?></div>
      <div class="avsf-fm-update-warning"><?php _e('ก่อนติดตั้ง: ระบบจะสำรองไฟล์เดิมทั้งหมดที่กำลังแทนที่ไว้ในพื้นที่ส่วนตัวนอกเว็บรูท หากติดตั้งล้มเหลวจะพยายามย้อนคืนให้อัตโนมัติ ส่วนการย้อนกลับภายหลังจะไม่เขียนทับไฟล์ที่ถูกแก้ไขอีกครั้งหลังอัปเดต'); ?></div>
      <div class="avsf-fm-update-actions"><form class="avsf-fm-update-action" method="post"><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="update_apply"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input type="hidden" name="update_id" value="<?=e($updatePreview['id'])?>"><label><input type="checkbox" name="confirm_update" value="1" required> <?php _e('ฉันตรวจรายการแล้ว และยืนยันให้สำรองไฟล์เดิมก่อนติดตั้ง'); ?></label><button class="avsf-fm-btn" type="submit"><?php _e('สำรองและติดตั้งอัปเดต'); ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="update_cancel"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input type="hidden" name="update_id" value="<?=e($updatePreview['id'])?>"><button class="avsf-fm-btn avsf-secondary" type="submit"><?php _e('ยกเลิก Preview'); ?></button></form></div>
    </div>
  <?php endif; ?>
  <?php if ($updateHistory): ?><div class="avsf-fm-update-history"><strong><?php _e('ประวัติอัปเดตและย้อนกลับ'); ?></strong><?php foreach ($updateHistory as $history): ?><div class="avsf-fm-update-history-row"><div><b><?=e((string)($history['package_version'] ?? __('ไม่ระบุเวอร์ชัน')))?></b><span><?=e((string)($history['created_at'] ?? ''))?> · <?=number_format(count($history['files'] ?? []))?> <?php _e('ไฟล์ ·'); ?> <?=e((string)($history['status'] ?? ''))?></span></div><?php if (in_array(($history['status'] ?? ''), ['applied','rollback_partial','failed_partial_rollback'], true)): ?><form class="avsf-fm-update-action" method="post" onsubmit="return confirm(<?php echo avsf_js(__('ยืนยันย้อนกลับไปยังไฟล์ก่อนการอัปเดตนี้? ไฟล์ที่ถูกแก้ไขภายหลังจะถูกข้าม')); ?>);"><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="update_rollback"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input type="hidden" name="deployment_id" value="<?=e((string)$history['id'])?>"><label><input type="checkbox" name="confirm_rollback" value="1" required> <?php _e('ยืนยัน'); ?></label><button class="avsf-fm-btn avsf-danger" type="submit"><?php _e('ย้อนกลับ'); ?></button></form><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php endif; ?>
</section>
<div class="avsf-fm-toolbar">
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="mkdir"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input name="name" placeholder="<?php echo __('ชื่อโฟลเดอร์ใหม่'); ?>" aria-label="<?php echo __('ชื่อโฟลเดอร์ใหม่'); ?>" required><button class="avsf-fm-btn avsf-secondary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><path d="M12 11v6M9 14h6"/></svg><?php _e('โฟลเดอร์'); ?></button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="newfile"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input name="name" placeholder="<?php echo __('ชื่อไฟล์ใหม่'); ?>" aria-label="<?php echo __('ชื่อไฟล์ใหม่'); ?>" required><button class="avsf-fm-btn avsf-secondary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M12 12v6M9 15h6"/></svg><?php _e('ไฟล์'); ?></button></form>
<form class="avsf-fm-upload" id="fmUploadForm" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input type="file" name="files[]" multiple required aria-label="<?php echo __('เลือกไฟล์อัปโหลด'); ?>"><label class="avsf-fm-checkline"><input type="checkbox" name="extract_zip" value="1" checked> <?php _e('แตก ZIP อัตโนมัติ'); ?></label><button class="avsf-fm-btn" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></svg><?php _e('อัปโหลด'); ?></button></form>
</div>
<div class="avsf-fm-layout">
<aside class="avsf-fm-sidebar"><strong><?php _e('ตำแหน่งโฟลเดอร์'); ?></strong><nav class="avsf-fm-tree"><a class="<?= e(avsfClass($currentRel===''?'avsf-active':'')) ?>" href="?dir="><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="vertical-align:-2px;margin-right:4px"><path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/></svg>Root</a><?php foreach ($folders as $folder): ?><a class="<?= e(avsfClass(strpos($currentRel.'/', $folder['rel'].'/')===0?'avsf-active':'')) ?>" href="?dir=<?=rawurlencode($folder['rel'])?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="vertical-align:-2px;margin-right:4px"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg><?=e($folder['name'])?></a><?php endforeach; ?></nav></aside>
<main class="avsf-fm-main">
<nav class="avsf-fm-breadcrumb"><a href="?dir=">Root</a><?php foreach ($crumb as $i=>$part): ?> / <?php if ($i < count($crumb)-1): ?><a href="?dir=<?=rawurlencode($part['rel'])?>"><?=e($part['name'])?></a><?php else: ?><strong><?=e($part['name'])?></strong><?php endif; endforeach; ?></nav>
<div class="avsf-fm-search-bar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px"><div style="position:relative;flex:1 1 200px;min-width:0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--fm-muted);pointer-events:none"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" id="fmSearchInput" placeholder="<?php echo __('ค้นหาในโฟลเดอร์นี้…'); ?>" aria-label="<?php echo __('ค้นหาไฟล์และโฟลเดอร์'); ?>" style="width:100%;min-width:0;min-height:40px;padding:8px 10px 8px 34px;border:1px solid var(--fm-border);border-radius:9px;background:var(--fm-surface);color:var(--fm-text);font:inherit;font-size:14px"></div><span id="fmSearchCount" style="font-size:11px;color:var(--fm-muted);white-space:nowrap"></span></div>
<div class="avsf-fm-actions"><label class="avsf-fm-checkline"><input class="avsf-fm-check" type="checkbox" id="fmSelectAll"> <?php _e('เลือกทั้งหมด'); ?></label><button class="avsf-fm-btn avsf-secondary" type="button" onclick="fmBulk('lock')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg><?php _e('ล็อก'); ?></button><button class="avsf-fm-btn avsf-secondary" type="button" onclick="fmBulk('unlock')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg><?php _e('ปลดล็อก'); ?></button><button class="avsf-fm-btn avsf-danger" type="button" onclick="fmBulk('delete')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg><?php _e('ลบที่เลือก'); ?></button></div>
<div class="avsf-fm-table-wrap"><table class="avsf-fm-table"><thead><tr><th><input class="avsf-fm-check" type="checkbox" id="fmSelectAllHead" aria-label="<?php echo __('เลือกทั้งหมด'); ?>"></th><th><?php _e('ไฟล์ / โฟลเดอร์'); ?></th><th><?php _e('ประเภท / วันที่แก้ไข'); ?></th><th><?php _e('ขนาด'); ?></th><th><?php _e('สิทธิ์'); ?></th><th><?php _e('จัดการ'); ?></th></tr></thead><tbody>
<?php foreach (array_merge($folders,$items) as $item): $isFolder=$item['type']==='folder'; $icon=$isFolder?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>':(strtolower($item['ext'])==='zip'?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>':(in_array($item['ext'],['jpg','jpeg','png','webp','gif','avif'],true)?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>':(in_array($item['ext'],['php','js','css','html','htm'],true)?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'))); $isZip=!$isFolder && strtolower($item['ext'])==='zip'; ?>
<tr class="avsf-fm-row" data-path="<?=e($item['rel'])?>" data-name="<?=e($item['name'])?>" data-kind="<?=$isFolder?'folder':'file'?>" data-ext="<?=e($item['ext'])?>" data-editable="<?=!empty($item['editable'])?'1':'0'?>" data-locked="<?=$item['locked']?'1':'0'?>" data-direct-locked="<?=$item['directLocked']?'1':'0'?>">
<td><input class="avsf-fm-check avsf-fm-row-check" type="checkbox" value="<?=e($item['rel'])?><?php _e('" aria-label="เลือก'); ?> <?=e($item['name'])?>"></td>
<td><a class="avsf-fm-item-name <?= e(avsfClass($isFolder?'avsf-folder':'')) ?>" href="<?=$isFolder?'?dir='.rawurlencode($item['rel']):'?dir='.rawurlencode($currentRel).'&file='.rawurlencode($item['rel'])?>"><span class="avsf-fm-item-icon"><?=$icon?></span><span><?=e($item['locked']?'🔒 ':'')?><?=e($item['name'])?><span class="avsf-fm-item-sub"><?=$isFolder?__('โฟลเดอร์'):strtoupper($item['ext']?:'FILE')?></span></span></a></td>
<td><?=e($isFolder?__('โฟลเดอร์'):strtoupper($item['ext']?:'FILE'))?><span class="avsf-fm-item-sub"><?=date('d/m/Y H:i',$item['mtime'])?></span></td>
<td><?=$isFolder?'—':e(fmHumanSize($item['size']))?></td><td><?=e($item['perms'])?></td>
<td><div class="avsf-fm-row-actions"><button type="button" class="avsf-fm-btn avsf-secondary avsf-fm-open-actions" aria-label="จัดการ <?=e($item['name'])?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg><?php _e('จัดการ'); ?></button><?php if ($isFolder): ?><a class="avsf-fm-btn avsf-secondary" href="?download_dir=<?=rawurlencode($item['rel'])?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>ZIP</a><?php elseif (!fmIsSensitiveFile($currentPath . DIRECTORY_SEPARATOR . $item['name'])): ?><a class="avsf-fm-btn avsf-secondary" href="?download=<?=rawurlencode($item['rel'])?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></svg><?php _e('ดาวน์'); ?></a><?php endif; ?></div></td>
</tr>
<?php endforeach; ?>
</tbody></table><?php if (!$folders && !$items): ?><div class="avsf-fm-empty"><?php _e('โฟลเดอร์นี้ยังไม่มีไฟล์'); ?></div><?php endif; ?></div>
<?php if ($selectedPath && $editableFile): ?>
<div class="avsf-fm-editor-backdrop avsf-active" id="fmEditorBackdrop" aria-hidden="false">
  <section class="avsf-fm-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="fmEditorTitle">
    <form id="fmEditorForm" class="avsf-fm-editor-form" method="post">
      <input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>">
      <input type="hidden" name="dir" value="<?=e($currentRel)?>">
      <input type="hidden" name="file" value="<?=e($selectedRel)?>">
      <input type="hidden" name="action" value="save">
      <header class="avsf-fm-editor-header">
        <div class="avsf-fm-editor-heading">
          <strong id="fmEditorTitle"><?php _e('แก้ไขไฟล์'); ?><?=fmPathLocked($selectedPath,$lockFile)?' 🔒':''?></strong>
          <span title="<?=e($selectedRel)?>"><?=e($selectedRel)?></span>
        </div>
        <div class="avsf-fm-editor-buttons">
          <button class="avsf-fm-btn" type="submit" id="fmEditorSave" <?=fmPathLocked($selectedPath,$lockFile)?'disabled':''?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg><?php _e('บันทึก'); ?></button>
          <button class="avsf-fm-btn avsf-secondary" type="button" id="fmEditorClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M18 6 6 18M6 6l12 12"/></svg><?php _e('ปิด'); ?></button>
        </div>
      </header>
      <?php if ($msg !== ''): ?><div class="avsf-fm-editor-notice <?=e($msgType==='error'?'is-error':($msgType==='warning'?'is-warning':'is-success'))?>" role="status"><?=e($msg)?></div><?php endif; ?>
      <div class="avsf-fm-editor-toolbar" role="toolbar" aria-label="<?php echo __('เครื่องมือแก้ไขโค้ด'); ?>">
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="search" title="<?php echo __('ค้นหาในไฟล์'); ?>" aria-label="<?php echo __('ค้นหาในไฟล์'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg><span><?php _e('ค้นหา'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="indent" title="<?php echo __('ย่อหน้าเข้า'); ?>" aria-label="<?php echo __('ย่อหน้าเข้า'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 19h16M4 12h6M10 8l4 4-4 4"/></svg><span><?php _e('ย่อเข้า'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="outdent" title="<?php echo __('ย่อหน้าออก'); ?>" aria-label="<?php echo __('ย่อหน้าออก'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 19h16M4 12h6M10 8l-4 4 4 4"/></svg><span><?php _e('ย่อออก'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="comment" title="<?php echo __('แสดงความคิดเห็นโค้ด'); ?>" aria-label="<?php echo __('คอมเมนต์โค้ด'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-5.2A7.5 7.5 0 1 1 20 11.5Z"/><path d="M8 11h8M8 14h5"/></svg><span><?php _e('คอมเมนต์'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="match" title="<?php echo __('จับคู่วงเล็บ'); ?>" aria-label="<?php echo __('จับคู่วงเล็บ'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4C6 4 6 7 6 12s0 8 3 8M15 4c3 0 3 3 3 8s0 8-3 8"/><path d="M10 8h4M10 16h4"/></svg><span><?php _e('วงเล็บ'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="goto" title="<?php echo __('ไปยังบรรทัด'); ?>" aria-label="<?php echo __('ไปยังบรรทัด'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h10M4 19h16"/><path d="m17 9 3 3-3 3"/></svg><span><?php _e('ไปบรรทัด'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="select-all" title="<?php echo __('เลือกทั้งหมด'); ?>" aria-label="<?php echo __('เลือกทั้งหมด'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 12h8M8 15h5"/></svg><span><?php _e('เลือกทั้งหมด'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="copy" title="<?php echo __('คัดลอกข้อความที่เลือก'); ?>" aria-label="<?php echo __('คัดลอกข้อความที่เลือก'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span><?php _e('คัดลอก'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="download" title="<?php echo __('ดาวน์โหลดไฟล์ที่กำลังแก้ไข'); ?>" aria-label="<?php echo __('ดาวน์โหลดไฟล์ที่กำลังแก้ไข'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/></svg><span><?php _e('ดาวน์โหลด'); ?></span></button>
        <button type="button" class="avsf-fm-editor-tool" data-fm-editor-action="wrap" aria-pressed="false" title="<?php echo __('ตัดบรรทัดอัตโนมัติ'); ?>" aria-label="<?php echo __('ตัดบรรทัดอัตโนมัติ'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 11h12a3 3 0 1 1 0 6h-3M4 17h5M6 14l-3 3 3 3"/></svg><span><?php _e('ตัดบรรทัด'); ?></span></button>
      </div>
      <textarea id="fmEditorContent" name="content" data-extension="<?=e(strtolower(pathinfo($selectedPath, PATHINFO_EXTENSION)))?>" data-filename="<?=e(basename($selectedPath))?>" wrap="off" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" aria-label="<?php echo __('เนื้อหาไฟล์'); ?>"><?=e($fileContent)?></textarea>
      <footer class="avsf-fm-editor-footer"><span><?=fmPathLocked($selectedPath,$lockFile)?__('ไฟล์นี้ถูกล็อก จึงเปิดอ่านได้อย่างเดียว'):__('บันทึกแล้วระบบจะสำรองไฟล์เดิมให้อัตโนมัติ')?></span><span id="fmEditorStats"></span></footer>
    </form>
  </section>
</div>
<?php elseif ($selectedPath): ?><section class="avsf-fm-editor"><p class="avsf-fm-empty-note"><?php _e('ไฟล์นี้เป็น Binary หรือไม่ใช่ไฟล์ข้อความ สามารถดาวน์โหลดหรือจัดการจากเมนู จัดการ ได้'); ?></p><a class="avsf-fm-btn" href="?download=<?=rawurlencode($selectedRel)?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></svg><?php _e('ดาวน์โหลดไฟล์'); ?></a></section><?php endif; ?>
</main></div>
<form id="fmActionForm" method="post" hidden><input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input type="hidden" name="action" value=""><input type="hidden" name="source" value=""><input type="hidden" name="name" value=""><input type="hidden" name="target_dir" value="<?=e($currentRel)?>"></form>
<div class="avsf-fm-modal-backdrop" id="fmActionBackdrop" aria-hidden="true"><section class="avsf-fm-action-sheet" role="dialog" aria-modal="true" aria-labelledby="fmSheetTitle"><div class="avsf-fm-sheet-head"><strong id="fmSheetTitle"><?php _e('จัดการรายการ'); ?></strong><button type="button" class="avsf-fm-sheet-close" id="fmSheetClose" aria-label="<?php echo __('ปิด'); ?>">×</button></div><div class="avsf-fm-sheet-grid"><button type="button" id="fmEdit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg><?php _e('แก้ไข'); ?></button><a id="fmDownload" href="javascript:void(0)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></svg><?php _e('ดาวน์โหลด'); ?></a><button type="button" id="fmRename"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg><?php _e('เปลี่ยนชื่อ'); ?></button><button type="button" id="fmCopy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><?php _e('คัดลอก'); ?></button><button type="button" id="fmMove"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/></svg><?php _e('ย้าย'); ?></button><button type="button" id="fmLock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg><span id="fmLockLabel"><?php _e('ล็อก'); ?></span></button><button type="button" id="fmExtract"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg><?php _e('แตก ZIP'); ?></button><button type="button" id="fmChmod"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.8-.6a8 8 0 0 1-1.5.9l-.3 1.9h-2.8l-.3-1.9a8 8 0 0 1-1.5-.9l-1.8.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.8.6a8 8 0 0 1 1.5-.9l.3-1.9h2.8l.3 1.9a8 8 0 0 1 1.5.9l1.8-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z"/></svg><?php _e('สิทธิ์ไฟล์'); ?></button><button type="button" class="avsf-danger" id="fmDelete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg><?php _e('ลบ'); ?></button></div><div class="avsf-fm-sheet-form"><label for="fmNewName"><?php _e('ชื่อใหม่ / ชื่อโฟลเดอร์ (เปลี่ยนชื่อ คัดลอก ย้าย แตก ZIP)'); ?></label><input id="fmNewName" placeholder="<?php echo __('ชื่อรายการใหม่'); ?>"><label for="fmTargetDir"><?php _e('โฟลเดอร์ปลายทาง สำหรับคัดลอกหรือย้าย เช่น uploads/2026'); ?></label><input id="fmTargetDir" value="<?=e($currentRel)?>" placeholder="<?php echo __('โฟลเดอร์ปลายทาง เช่น uploads/2026'); ?>"><label for="fmMode"><?php _e('สิทธิ์ไฟล์ (chmod) เมื่อเลือกเมนูตั้งสิทธิ์'); ?></label><input id="fmMode" value="0644" placeholder="0644"><button class="avsf-fm-btn" type="button" id="fmApply"><?php _e('ยืนยันคำสั่ง'); ?></button><button class="avsf-fm-btn avsf-secondary" type="button" id="fmSheetCancel"><?php _e('ยกเลิก'); ?></button></div></section></div>
<div class="avsf-fm-progress" id="fmProgress" aria-live="polite"><div class="avsf-fm-progress-card"><div class="avsf-fm-progress-title"><span class="avsf-fm-spinner"></span> <?php _e('กำลังทำงานกับไฟล์'); ?></div><div class="avsf-fm-progress-track"><div class="avsf-fm-progress-fill" id="fmProgressFill"></div></div><div class="avsf-fm-progress-text" id="fmProgressText"><?php _e('กำลังเตรียมอัปโหลด…'); ?></div></div></div>
<script>
(function(){
'use strict';
var selected=null, pendingAction='';
var backdrop=document.getElementById('fmActionBackdrop'), form=document.getElementById('fmActionForm');

var editorBackdrop=document.getElementById('fmEditorBackdrop');
if(editorBackdrop){
  var editor=document.getElementById('fmEditorContent');
  var editorStats=document.getElementById('fmEditorStats');
  var editorForm=document.getElementById('fmEditorForm');
  var editorClose=document.getElementById('fmEditorClose');
  function updateEditorStats(){if(!editorStats||!editor)return;var value=editor.value||'',caret=editor.selectionStart||0,before=value.slice(0,caret),line=before.split('\n').length,column=before.length-(before.lastIndexOf('\n')+1)+1;editorStats.textContent='บรรทัด '+value.split('\n').length.toLocaleString()+' · Ln '+line.toLocaleString()+', Col '+column.toLocaleString()+' · '+value.length.toLocaleString()+' ตัวอักษร · '+new Blob([value]).size.toLocaleString()+' ไบต์';}
  function closeEditor(){var url=new URL(window.location.href);url.searchParams.delete('file');window.history.replaceState({},'',url.pathname+(url.search?url.search:'')+url.hash);editorBackdrop.classList.remove('avsf-active');editorBackdrop.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  document.body.style.overflow='hidden';
  var editorToolbar=editorBackdrop.querySelector('.avsf-fm-editor-toolbar');
  var editorExt=editor?String(editor.getAttribute('data-extension')||'').toLowerCase():'';
  var editorFileName=editor?String(editor.getAttribute('data-filename')||'avsf-code.txt'):'avsf-code.txt';
  function editorFocus(){if(editor)editor.focus();}
  function editorInsert(text,start,end){if(!editor)return;editor.setRangeText(text,start,end,'select');editorFocus();updateEditorStats();}
  function editorLineRange(){
    var value=editor.value,start=editor.selectionStart,end=editor.selectionEnd;
    var lineStart=value.lastIndexOf('\n',Math.max(0,start-1))+1;
    var lastSelected=end>start?end-1:start;
    var lineEnd=value.indexOf('\n',lastSelected);
    if(lineEnd<0)lineEnd=value.length;else lineEnd++;
    return {start:lineStart,end:lineEnd,text:value.slice(lineStart,lineEnd)};
  }
  function editorIndent(remove){
    if(!editor)return;
    var value=editor.value,start=editor.selectionStart,end=editor.selectionEnd;
    if(start===end){
      var lineStart=value.lastIndexOf('\n',Math.max(0,start-1))+1;
      if(remove){var leading=value.slice(lineStart,start).match(/(?: {1,4}|\t)$/);if(leading){editor.setRangeText('',start-leading[0].length,start,'end');}}
      else editor.setRangeText('    ',start,end,'end');
      editorFocus();updateEditorStats();return;
    }
    var range=editorLineRange();
    var lines=range.text.split('\n');
    var transformed=lines.map(function(line){
      if(remove){if(line.startsWith('\t'))return line.slice(1);return line.replace(/^ {1,4}/,'');}
      return line.trim()===''?line:'    '+line;
    }).join('\n');
    editor.setRangeText(transformed,range.start,range.end,'select');
    editorFocus();updateEditorStats();
  }
  function editorComment(){
    if(!editor)return;
    var range=editorLineRange(),block=range.text,open='',close='',prefix='';
    if(['php','js','htm','html','css','json','sql','ini','conf','yml','yaml','xml','svg','md'].indexOf(editorExt)<0){alert(<?php echo avsf_js(__('ชนิดไฟล์นี้ยังไม่รองรับการคอมเมนต์อัตโนมัติ')); ?>);return;}
    if(['html','htm','xml','svg','md'].indexOf(editorExt)>=0){open='<!--';close='-->';}
    else if(editorExt==='css'){open='/*';close='*/';}
    else if(editorExt==='sql')prefix='-- ';
    else if(['ini','conf','yml','yaml'].indexOf(editorExt)>=0)prefix='# ';
    else if(editorExt==='json'){alert(<?php echo avsf_js(__('JSON ไม่รองรับคอมเมนต์ตามมาตรฐาน จึงไม่แก้ไขไฟล์')); ?>);return;}
    else prefix='// ';
    if(open){
      var trimmed=block.trim();
      if(trimmed.startsWith(open)&&trimmed.endsWith(close)){
        var left=block.indexOf(open),right=block.lastIndexOf(close);
        block=block.slice(0,left)+block.slice(left+open.length,right)+block.slice(right+close.length);
      }else{block=open+'\n'+block.replace(/\n$/,'')+'\n'+close+(block.endsWith('\n')?'\n':'');}
    }else{
      var lines=block.split('\n');
      var contentLines=lines.filter(function(line){return line.trim()!=='';});
      var allCommented=contentLines.length>0&&contentLines.every(function(line){return line.trimStart().startsWith(prefix.trim());});
      block=lines.map(function(line){
        if(line.trim()==='')return line;
        if(allCommented){var pos=line.search(/\S/);var rest=line.slice(pos+prefix.trim().length);if(rest.startsWith(' '))rest=rest.slice(1);return line.slice(0,pos)+rest;}
        var pos=line.search(/\S/);return line.slice(0,pos)+prefix+line.slice(pos);
      }).join('\n');
    }
    editorInsert(block,range.start,range.end);
  }
  function editorSearch(){
    if(!editor)return;
    var query=window.prompt(<?php echo avsf_js(__('ค้นหาข้อความในไฟล์')); ?>);if(!query)return;
    var value=editor.value,needle=query.toLocaleLowerCase(),hay=value.toLocaleLowerCase();
    var at=hay.indexOf(needle,editor.selectionEnd);
    if(at<0)at=hay.indexOf(needle,0);
    if(at<0){alert(<?php echo avsf_js(__('ไม่พบข้อความที่ค้นหา')); ?>);return;}
    editor.focus();editor.setSelectionRange(at,at+query.length);updateEditorStats();
  }
  function editorGoto(){
    if(!editor)return;
    var line=window.prompt(<?php echo avsf_js(__('ไปยังบรรทัดที่')); ?>);if(line===null)return;
    var target=parseInt(line,10);if(!Number.isFinite(target)||target<1){alert(<?php echo avsf_js(__('กรุณาระบุหมายเลขบรรทัดที่ถูกต้อง')); ?>);return;}
    var value=editor.value,offset=0,current=1;
    while(current<target&&offset<value.length){var next=value.indexOf('\n',offset);if(next<0){offset=value.length;break;}offset=next+1;current++;}
    editor.focus();editor.setSelectionRange(offset,offset);updateEditorStats();
  }
  function editorSelectAll(){if(!editor)return;editor.focus();editor.select();updateEditorStats();}
  function editorCopySelection(){if(!editor)return;var text=editor.value.slice(editor.selectionStart,editor.selectionEnd);if(!text){alert(<?php echo avsf_js(__('กรุณาเลือกข้อความที่ต้องการคัดลอกก่อน')); ?>);return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(function(){alert(<?php echo avsf_js(__('คัดลอกข้อความแล้ว')); ?>);}).catch(function(){editorCopyFallback(text);});}else{editorCopyFallback(text);}}
  function editorCopyFallback(text){var helper=document.createElement('textarea');helper.value=text;helper.style.position='fixed';helper.style.opacity='0';document.body.appendChild(helper);helper.select();try{var ok=document.execCommand('copy');alert(ok?<?php echo avsf_js(__('คัดลอกข้อความแล้ว')); ?>:<?php echo avsf_js(__('ไม่สามารถคัดลอกอัตโนมัติได้ กรุณาคัดลอกด้วยตนเอง')); ?>);}catch(e){alert(<?php echo avsf_js(__('ไม่สามารถคัดลอกอัตโนมัติได้ กรุณาคัดลอกด้วยตนเอง')); ?>);}helper.remove();editorFocus();}
  function editorDownload(){if(!editor)return;var blob=new Blob([editor.value],{type:'text/plain;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=editorFileName||'avsf-code.txt';document.body.appendChild(a);a.click();a.remove();window.setTimeout(function(){URL.revokeObjectURL(url);},1200);}
  function editorMatch(){
    if(!editor)return;
    var value=editor.value,pos=editor.selectionStart,pairs={'(':')','[':']','{':'}'},reverse={')':'(',']':'[','}':'{'};
    var index=pos<value.length&&'([{)]}'.indexOf(value.charAt(pos))>=0?pos:pos-1;
    var char=value.charAt(index),open=pairs[char],close=reverse[char],match=-1,depth=0,i;
    if(open){for(i=index;i<value.length;i++){if(value[i]===open)depth++;else if(value[i]===close){depth--;if(depth===0){match=i;break;}}}}
    else if(close){for(i=index;i>=0;i--){if(value[i]===close)depth++;else if(value[i]===open){depth--;if(depth===0){match=i;break;}}}}
    if(match<0){alert(<?php echo avsf_js(__('ไม่พบวงเล็บคู่ที่ตรงกัน')); ?>);return;}
    editor.focus();editor.setSelectionRange(Math.min(index,match),Math.min(index,match)+1);updateEditorStats();
  }
  if(editor){
    editor.addEventListener('input',updateEditorStats);
    editor.addEventListener('click',updateEditorStats);
    editor.addEventListener('keyup',updateEditorStats);
    editor.addEventListener('select',updateEditorStats);
    editor.addEventListener('keydown',function(event){
      if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();if(editorForm){if(typeof editorForm.requestSubmit==='function')editorForm.requestSubmit();else editorForm.submit();}return;}
      if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='f'){event.preventDefault();editorSearch();return;}
      if((event.ctrlKey||event.metaKey)&&event.key==='/'){event.preventDefault();editorComment();return;}
      if(event.key==='Tab'){event.preventDefault();editorIndent(event.shiftKey);}
    });
    updateEditorStats();
  }
  if(editorToolbar)editorToolbar.querySelectorAll('[data-fm-editor-action]').forEach(function(button){
    button.addEventListener('click',function(){
      var action=button.getAttribute('data-fm-editor-action');
      if(action==='search')editorSearch();
      else if(action==='indent')editorIndent(false);
      else if(action==='outdent')editorIndent(true);
      else if(action==='comment')editorComment();
      else if(action==='match')editorMatch();
      else if(action==='goto')editorGoto();
      else if(action==='select-all')editorSelectAll();
      else if(action==='copy')editorCopySelection();
      else if(action==='download')editorDownload();
      else if(action==='wrap'){
        var wrapped=editor.classList.toggle('avsf-fm-editor-wrapped');
        button.setAttribute('aria-pressed',wrapped?'true':'false');
        editor.setAttribute('wrap',wrapped?'soft':'off');
      }
    });
  });
  if(editorClose)editorClose.addEventListener('click',closeEditor);
  document.addEventListener('keydown',function(event){if(event.key==='Escape'&&editorBackdrop.classList.contains('avsf-active')){event.preventDefault();closeEditor();}});
  if(editorForm){editorForm.addEventListener('submit',function(event){var save=document.getElementById('fmEditorSave');if(save){save.disabled=true;save.textContent=<?php echo avsf_js(__('กำลังบันทึก…')); ?>;} });}
}

function rowData(row){return {path:row.dataset.path||'',name:row.dataset.name||'',kind:row.dataset.kind||'file',ext:row.dataset.ext||'',editable:row.dataset.editable==='1',locked:row.dataset.locked==='1',directLocked:row.dataset.directLocked==='1'};}
function openSheet(row){selected=rowData(row);pendingAction='';document.getElementById('fmSheetTitle').textContent=selected.name+(selected.locked?' 🔒':'');document.getElementById('fmNewName').value=selected.name;document.getElementById('fmTargetDir').value='<?=e($currentRel)?>';document.getElementById('fmDownload').href=selected.kind==='folder'?'?download_dir='+encodeURIComponent(selected.path):'?download='+encodeURIComponent(selected.path);document.getElementById('fmEdit').hidden=!(selected.kind==='file'&&selected.editable);document.getElementById('fmDownload').hidden=selected.kind==='file'&&['config.php','.env','wp-config.php','auth.json','credentials.json','id_rsa','id_ed25519'].indexOf(selected.name.toLowerCase())>=0;document.getElementById('fmExtract').hidden=!(selected.kind==='file'&&selected.ext==='zip');document.getElementById('fmLockLabel').textContent=selected.directLocked?'ปลดล็อก':(selected.locked?'ล็อกโดยโฟลเดอร์แม่':'ล็อก');document.getElementById('fmLock').disabled=selected.locked&&!selected.directLocked;backdrop.classList.add('avsf-active');backdrop.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';}
function closeSheet(){backdrop.classList.remove('avsf-active');backdrop.setAttribute('aria-hidden','true');document.body.style.overflow='';selected=null;pendingAction='';}
function submitAction(action,name,targetDir){if(!selected)return; if(selected.locked&&['rename','copy','move','extract','delete','chmod'].indexOf(action)>=0){if(action!=='copy'){alert(<?php echo avsf_js(__('รายการนี้ถูกล็อก กรุณาปลดล็อกก่อน')); ?>);return;}} if(action==='delete'&&!confirm('ยืนยันลบ '+selected.name+' ?'))return; if(action==='extract'&&!confirm('แตกไฟล์ ZIP ไปยังโฟลเดอร์ '+(name||selected.name.replace(/\.zip$/i,''))+' ใช่หรือไม่?'))return; form.querySelector('[name=action]').value=action;form.querySelector('[name=source]').value=selected.path;form.querySelector('[name=name]').value=name||selected.name;form.querySelector('[name=target_dir]').value=targetDir===undefined?'<?=e($currentRel)?>':targetDir;form.submit();}
document.querySelectorAll('.avsf-fm-open-actions').forEach(function(button){button.addEventListener('click',function(){openSheet(button.closest('.avsf-fm-row'));});});
document.querySelectorAll('.avsf-fm-row').forEach(function(row){var timer=null,held=false,startX=0,startY=0;row.addEventListener('contextmenu',function(event){event.preventDefault();openSheet(row);});row.addEventListener('pointerdown',function(event){if(event.pointerType!=='touch')return;held=false;startX=event.clientX;startY=event.clientY;timer=setTimeout(function(){held=true;openSheet(row);},620);});row.addEventListener('pointermove',function(event){if(timer&&Math.hypot(event.clientX-startX,event.clientY-startY)>14){clearTimeout(timer);timer=null;}});['pointerup','pointerleave','pointercancel'].forEach(function(type){row.addEventListener(type,function(){if(timer){clearTimeout(timer);timer=null;}});});row.addEventListener('click',function(event){if(held){event.preventDefault();event.stopPropagation();held=false;}});});
document.getElementById('fmSheetClose').addEventListener('click',closeSheet);document.getElementById('fmSheetCancel').addEventListener('click',closeSheet);backdrop.addEventListener('click',function(event){if(event.target===backdrop)closeSheet();});document.addEventListener('keydown',function(event){if(event.key==='Escape')closeSheet();});
document.getElementById('fmEdit').addEventListener('click',function(){if(selected&&selected.editable&&selected.kind==='file'){location.href='?dir=<?=rawurlencode($currentRel)?>&file='+encodeURIComponent(selected.path);}});
document.getElementById('fmRename').addEventListener('click',function(){pendingAction='rename';document.getElementById('fmNewName').value=selected.name;document.getElementById('fmNewName').focus();});
document.getElementById('fmCopy').addEventListener('click',function(){pendingAction='copy';document.getElementById('fmNewName').value=selected.name;document.getElementById('fmTargetDir').focus();});
document.getElementById('fmMove').addEventListener('click',function(){pendingAction='move';document.getElementById('fmNewName').value=selected.name;document.getElementById('fmTargetDir').focus();});
document.getElementById('fmLock').addEventListener('click',function(){if(selected.locked&&!selected.directLocked){alert(<?php echo avsf_js(__('รายการนี้ถูกล็อกโดยโฟลเดอร์แม่ กรุณาเปิดโฟลเดอร์แม่แล้วปลดล็อกจากรายการนั้น')); ?>);return;}submitAction(selected.directLocked?'unlock':'lock',selected.name);});
document.getElementById('fmExtract').addEventListener('click',function(){pendingAction='extract';document.getElementById('fmNewName').value=selected.name.replace(/\.zip$/i,'');});
document.getElementById('fmChmod').addEventListener('click',function(){pendingAction='chmod';document.getElementById('fmMode').value='0644';document.getElementById('fmMode').focus();});
document.getElementById('fmDelete').addEventListener('click',function(){submitAction('delete',selected.name);});
document.getElementById('fmApply').addEventListener('click',function(){var name=document.getElementById('fmNewName').value.trim(),target=document.getElementById('fmTargetDir').value.trim();if(pendingAction==='rename'||pendingAction==='copy'||pendingAction==='move'||pendingAction==='extract'){if(!name){alert(<?php echo avsf_js(__('กรุณาระบุชื่อหรือปลายทาง')); ?>);return;}if(pendingAction==='rename')target=selected.path.split('/').slice(0,-1).join('/');submitAction(pendingAction,name,target);}else if(pendingAction==='chmod'){form.querySelector('[name=action]').value='chmod';form.querySelector('[name=source]').value=selected.path;form.querySelector('[name=name]').value='';form.querySelector('[name=mode]')?.remove();var mode=document.createElement('input');mode.type='hidden';mode.name='mode';mode.value=document.getElementById('fmMode').value.trim();form.appendChild(mode);form.submit();}});
function syncSelectAll(){var boxes=[...document.querySelectorAll('.avsf-fm-row-check')];var all=boxes.length>0&&boxes.every(function(box){return box.checked;});document.getElementById('fmSelectAll').checked=all;document.getElementById('fmSelectAllHead').checked=all;}
document.querySelectorAll('#fmSelectAll,#fmSelectAllHead').forEach(function(all){all.addEventListener('change',function(){document.querySelectorAll('.avsf-fm-row-check').forEach(function(box){box.checked=all.checked;});syncSelectAll();});});document.querySelectorAll('.avsf-fm-row-check').forEach(function(box){box.addEventListener('change',syncSelectAll);});
window.fmBulk=function(action){var selectedItems=[...document.querySelectorAll('.avsf-fm-row-check:checked')].map(function(box){return box.value;});if(!selectedItems.length){alert(<?php echo avsf_js(__('เลือกไฟล์หรือโฟลเดอร์ก่อน')); ?>);return;}if(action==='delete'&&!confirm('ยืนยันลบ '+selectedItems.length+' รายการ?'))return;var f=document.createElement('form');f.method='post';f.action='file-manager.php?dir=<?=rawurlencode($currentRel)?>';f.innerHTML='<input type="hidden" name="csrf_token" value="<?=e(sec_csrf_token())?>"><input type="hidden" name="dir" value="<?=e($currentRel)?>"><input type="hidden" name="action" value="'+action+'">';selectedItems.forEach(function(path){var input=document.createElement('input');input.type='hidden';input.name='items[]';input.value=path;f.appendChild(input);});document.body.appendChild(f);f.submit();};
var upload=document.getElementById('fmUploadForm');upload.addEventListener('submit',function(event){event.preventDefault();var files=upload.querySelector('input[type=file]').files;if(!files.length)return;var overlay=document.getElementById('fmProgress'),fill=document.getElementById('fmProgressFill'),text=document.getElementById('fmProgressText');overlay.classList.add('avsf-active');text.textContent='กำลังอัปโหลด '+files.length+' ไฟล์…';var xhr=new XMLHttpRequest();xhr.open('POST',upload.action||location.href,true);xhr.upload.onprogress=function(e){if(e.lengthComputable){var p=Math.min(99,Math.round(e.loaded/e.total*100));fill.style.width=p+'%';text.textContent='กำลังอัปโหลดไฟล์ '+p+'%';}};xhr.upload.onload=function(){fill.style.width='100%';text.textContent='อัปโหลดเสร็จ กำลังตรวจสอบและแตก ZIP อย่างปลอดภัย…';};xhr.onload=function(){if(xhr.status>=200&&xhr.status<400&&xhr.responseText){document.open();document.write(xhr.responseText);document.close();}else{overlay.classList.remove('avsf-active');alert(<?php echo avsf_js(__('เซิร์ฟเวอร์ตอบกลับไม่สำเร็จ กรุณาตรวจสอบไฟล์และลองอีกครั้ง')); ?>);}};xhr.onerror=function(){overlay.classList.remove('avsf-active');alert(<?php echo avsf_js(__('การอัปโหลดขัดข้อง กรุณาลองใหม่อีกครั้ง')); ?>);};xhr.send(new FormData(upload));});
var updateUpload=document.getElementById('fmUpdateUploadForm');if(updateUpload){updateUpload.addEventListener('submit',function(event){event.preventDefault();var file=updateUpload.querySelector('input[type=file]').files;if(!file.length)return;var overlay=document.getElementById('fmProgress'),fill=document.getElementById('fmProgressFill'),text=document.getElementById('fmProgressText');overlay.classList.add('avsf-active');fill.style.width='0%';text.textContent='กำลังอัปโหลดแพ็กเกจ Update…';var xhr=new XMLHttpRequest();xhr.open('POST',updateUpload.action||location.href,true);xhr.upload.onprogress=function(e){if(e.lengthComputable){var p=Math.min(99,Math.round(e.loaded/e.total*100));fill.style.width=p+'%';text.textContent='อัปโหลดแพ็กเกจ '+p+'%';}};xhr.upload.onload=function(){fill.style.width='100%';text.textContent='อัปโหลดเสร็จ กำลังตรวจ ZIP, SHA-256 และ PHP Syntax…';};xhr.onload=function(){if(xhr.status>=200&&xhr.status<400&&xhr.responseText){document.open();document.write(xhr.responseText);document.close();}else{overlay.classList.remove('avsf-active');alert(<?php echo avsf_js(__('ตรวจแพ็กเกจไม่สำเร็จ กรุณาดู Error Log และข้อจำกัด Hosting')); ?>);}};xhr.onerror=function(){overlay.classList.remove('avsf-active');alert(<?php echo avsf_js(__('การอัปโหลดขัดข้อง กรุณาลองอีกครั้ง')); ?>);};xhr.send(new FormData(updateUpload));});}document.querySelectorAll('.avsf-fm-update-action').forEach(function(form){form.addEventListener('submit',function(){var overlay=document.getElementById('fmProgress'),fill=document.getElementById('fmProgressFill'),text=document.getElementById('fmProgressText');if(overlay){overlay.classList.add('avsf-active');fill.style.width='40%';text.textContent='กำลังสำรองและติดตั้งไฟล์ระบบ… อย่าปิดหน้านี้';}});});
var fmSearchInput=document.getElementById('fmSearchInput'),fmSearchCount=document.getElementById('fmSearchCount'),fmRows=Array.prototype.slice.call(document.querySelectorAll('.avsf-fm-row'));
if(fmSearchInput){fmSearchInput.addEventListener('input',function(){var q=(fmSearchInput.value||'').trim().toLowerCase();var shown=0;fmRows.forEach(function(row){var name=(row.dataset.name||'').toLowerCase();var match=q===''||name.indexOf(q)!==-1;row.style.display=match?'':'none';if(match)shown++;});if(fmSearchCount)fmSearchCount.textContent=(q===''?'':shown+' / '+fmRows.length+' รายการ');});}
})();
</script>
</body></html>
