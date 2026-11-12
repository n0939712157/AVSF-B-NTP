<?php
/**
 * AVSF Backup Manager
 * Creates private ZIP archives (website files, optionally all DB tables) and
 * restores only validated AVSF archives. Backups are stored outside web root.
 */
if (!class_exists('AVSFBackupManager')) {
class AVSFBackupManager {
    private $root;
    private $backupDir;
    private $excludedDirs = ['.git','node_modules','avsf-updates'];
    private $excludedFiles = ['config.php','config.local.php','.env','.user.ini'];

    public function __construct($rootDir) {
        $root = realpath($rootDir);
        if ($root === false || !is_dir($root)) throw new RuntimeException('ไม่พบโฟลเดอร์เว็บไซต์');
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $docRootCandidate = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $docRoot = $docRootCandidate !== '' ? realpath($docRootCandidate) : false;
        if ($docRoot === false) $docRoot = $this->root;
        $parent = realpath(dirname($docRoot));
        if ($parent === false || $parent === $docRoot) $parent = realpath(sys_get_temp_dir());
        if ($parent === false || !is_dir($parent) || !is_writable($parent)) {
            $parent = realpath(sys_get_temp_dir());
        }
        if ($parent === false || !is_dir($parent) || !is_writable($parent)) throw new RuntimeException('ไม่พบพื้นที่เก็บ Backup ที่อยู่นอกเว็บและเขียนได้');
        $docPrefix = rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($parent === $docRoot || strpos($parent . DIRECTORY_SEPARATOR, $docPrefix) === 0) {
            throw new RuntimeException('พื้นที่เก็บ Backup ยังอยู่ภายใน Document Root จึงหยุดเพื่อความปลอดภัย');
        }
        $slug = substr(hash('sha256', $this->root), 0, 12);
        $this->backupDir = $parent . DIRECTORY_SEPARATOR . '.avsf-private-backups-' . $slug;
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0700, true)) throw new RuntimeException('สร้างพื้นที่เก็บ Backup ภายนอกเว็บไม่สำเร็จ');
        @chmod($this->backupDir, 0700);
        // Defense in depth; the directory is outside document root already.
        if (!is_file($this->backupDir . '/index.php')) @file_put_contents($this->backupDir . '/index.php', "<?php http_response_code(404); exit;\n", LOCK_EX);
        if (!is_file($this->backupDir . '/.htaccess')) @file_put_contents($this->backupDir . '/.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
    }

    public function getBackupDir() { return $this->backupDir; }

    private function quoteIdentifier($name) {
        return '`' . str_replace('`', '``', (string)$name) . '`';
    }

    private function writeDatabaseDump($conn, $outputPath) {
        if (!is_object($conn) || !method_exists($conn, 'query')) throw new RuntimeException('ไม่มีการเชื่อมต่อฐานข้อมูลสำหรับ Backup');
        $out = @fopen($outputPath, 'wb');
        if (!$out) throw new RuntimeException('สร้างไฟล์ SQL ชั่วคราวไม่สำเร็จ');
        $write = function($value) use ($out) {
            if (fwrite($out, $value) === false) throw new RuntimeException('เขียนไฟล์ SQL สำรองไม่สำเร็จ');
        };
        try {
            $write("-- AVSF_DATABASE_BACKUP v1\n-- Generated: " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            $tables = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            if (!$tables) throw new RuntimeException('อ่านรายชื่อตารางฐานข้อมูลไม่สำเร็จ: ' . $conn->error);
            $tableCount = 0;
            while ($tableRow = $tables->fetch_row()) {
                $tableName = (string)$tableRow[0];
                $tableId = $this->quoteIdentifier($tableName);
                $create = $conn->query('SHOW CREATE TABLE ' . $tableId);
                if (!$create) throw new RuntimeException('อ่านโครงสร้างตาราง ' . $tableName . ' ไม่สำเร็จ: ' . $conn->error);
                $createRow = $create->fetch_row();
                $create->free();
                if (!$createRow || empty($createRow[1])) throw new RuntimeException('ไม่พบโครงสร้างตาราง ' . $tableName);
                $write("\nDROP TABLE IF EXISTS $tableId;\n" . $createRow[1] . ";\n");
                $rows = $conn->query('SELECT * FROM ' . $tableId, defined('MYSQLI_USE_RESULT') ? MYSQLI_USE_RESULT : 0);
                if (!$rows) throw new RuntimeException('อ่านข้อมูลตาราง ' . $tableName . ' ไม่สำเร็จ: ' . $conn->error);
                while ($row = $rows->fetch_assoc()) {
                    $columns = [];
                    $values = [];
                    foreach ($row as $column => $value) {
                        $columns[] = $this->quoteIdentifier($column);
                        $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string)$value) . "'";
                    }
                    $write('INSERT INTO ' . $tableId . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
                }
                $rows->free();
                $tableCount++;
            }
            $tables->free();
            if ($tableCount === 0) throw new RuntimeException('ไม่พบตารางฐานข้อมูล จึงไม่สร้าง Backup ฐานข้อมูลเปล่า');
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($out);
            return ['tables' => $tableCount, 'sha256' => hash_file('sha256', $outputPath), 'bytes' => filesize($outputPath)];
        } catch (Throwable $e) {
            if (is_resource($out)) fclose($out);
            @unlink($outputPath);
            throw $e;
        }
    }

    private function isExcludedPath($relative) {
        $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
        $lower = strtolower($relative);
        $parts = explode('/', $lower);
        foreach ($parts as $part) {
            if (in_array($part, $this->excludedDirs, true)) return true;
        }
        // Runtime folders are excluded only at the document root or under avsf-storage.
        // Do not exclude source code such as avsf-core/cache/cache_lib.php.
        if (preg_match('#^(?:cache|quarantine|tmp|temp|logs|backups)(?:/|$)#i', $lower)) return true;
        if (preg_match('#^avsf-storage/(?:cache|quarantine|tmp|temp|logs|backups)(?:/|$)#i', $lower)) return true;
        // The live database dump is written separately to database/database.sql.
        // Never bundle stale SQL snapshots from the document root.
        if (preg_match('/\.sql$/i', $relative) && $relative !== 'avsf-installer/database-schema.sql') return true;
        return in_array(strtolower(basename($relative)), $this->excludedFiles, true);
    }

    private function addWebsiteFiles($zip) {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($this->root))), '/');
            if ($relative === '' || $this->isExcludedPath($relative)) continue;
            if (is_link($absolute)) continue;
            if ($item->isDir()) continue;
            if (!$item->isFile() || !is_readable($absolute)) continue;
            if (!$zip->addFile($absolute, 'website/' . $relative)) throw new RuntimeException('เพิ่มไฟล์ลง Backup ไม่สำเร็จ: ' . $relative);
            $count++;
        }
        return $count;
    }


    /** Add existing private backup archives to a one-time migration export. */
    private function addStoredBackupHistory($zip) {
        $items = [];
        $totalBytes = 0;
        $seen = [];
        $sources = [
            $this->backupDir,
            $this->root . DIRECTORY_SEPARATOR . 'avsf-storage' . DIRECTORY_SEPARATOR . 'backups',
            $this->root . DIRECTORY_SEPARATOR . 'backups',
        ];
        foreach (array_unique($sources) as $sourceDir) {
            if (!is_dir($sourceDir) || is_link($sourceDir)) continue;
            foreach (glob(rtrim($sourceDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $path) {
                if (is_link($path) || !is_file($path) || !is_readable($path)) continue;
                $size = (int)filesize($path);
                if ($size < 100) continue;
                $sourceName = basename($path);
                if (!preg_match('/^[A-Za-z0-9_.-]+\.zip$/', $sourceName)) continue;
                // Only archives carrying an AVSF manifest are eligible for restore.
                try { $this->inspectArchive($path); } catch (Throwable $e) { continue; }
                $hash = hash_file('sha256', $path);
                if (!$hash || isset($seen[$hash])) continue;
                $name = preg_match('/^avsf-backup-/', $sourceName) ? $sourceName : 'avsf-backup-imported-' . substr($hash, 0, 12) . '.zip';
                if (isset($seen['name:' . $name])) $name = preg_replace('/\.zip$/', '-' . substr($hash, 0, 8) . '.zip', $name);
                if (count($items) >= 2000 || $totalBytes + $size > 1073741824) {
                    throw new RuntimeException('ประวัติ Backup เกินขีดจำกัด 2,000 ไฟล์ หรือ 1 GB กรุณาแยกส่งออกเป็นชุด');
                }
                if (!$zip->addFile($path, 'backup_history/' . $name)) {
                    throw new RuntimeException('เพิ่มประวัติ Backup ลง Migration ZIP ไม่สำเร็จ: ' . $sourceName);
                }
                $items[] = ['name'=>$name, 'size'=>$size, 'sha256'=>$hash];
                $seen[$hash] = true;
                $seen['name:' . $name] = true;
                $totalBytes += $size;
            }
        }
        return ['included'=>!empty($items), 'count'=>count($items), 'bytes'=>$totalBytes, 'files'=>$items];
    }

    /** Add settings pre-import snapshots from the protected backup area. */
    private function addSettingsBackupHistory($zip) {
        $dir = $this->root . DIRECTORY_SEPARATOR . 'avsf-storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'settings-migration';
        $items = []; $totalBytes = 0;
        if (!is_dir($dir) || is_link($dir)) return ['included'=>false,'count'=>0,'bytes'=>0,'files'=>[]];
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'settings-before-import-*.json') ?: [] as $path) {
            if (is_link($path) || !is_file($path) || !is_readable($path)) continue;
            $name = basename($path); $size = (int)filesize($path);
            if (!preg_match('/^settings-before-import-[A-Za-z0-9_.-]+\.json$/', $name) || $size < 2 || $size > 10485760) continue;
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'AVSF_SETTINGS_PREIMPORT_BACKUP') continue;
            if (count($items) >= 500 || $totalBytes + $size > 104857600) throw new RuntimeException('Settings Backup History เกินขีดจำกัด 500 ไฟล์ หรือ 100 MB');
            $entry = 'settings_backup_history/' . $name;
            if (!$zip->addFile($path, $entry)) throw new RuntimeException('เพิ่ม Settings Backup History ไม่สำเร็จ: ' . $name);
            $items[] = ['name'=>$name,'size'=>$size,'sha256'=>hash('sha256',$raw)];
            $totalBytes += $size;
        }
        return ['included'=>!empty($items),'count'=>count($items),'bytes'=>$totalBytes,'files'=>$items];
    }

    /** Restore settings snapshots in protected AVSF storage, never in the public document root. */
    private function restoreSettingsBackupHistory($zip) {
        $dir = $this->root . DIRECTORY_SEPARATOR . 'avsf-storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'settings-migration';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('สร้างพื้นที่ Settings Backup History ไม่สำเร็จ');
        @chmod($dir, 0700);
        $restored = [];
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); if (!$stat) continue;
            $entry = (string)$stat['name'];
            if (strpos($entry, 'settings_backup_history/') !== 0 || substr($entry, -1) === '/') continue;
            $name = substr($entry, strlen('settings_backup_history/'));
            if (!preg_match('/^settings-before-import-[A-Za-z0-9_.-]+\.json$/', $name)) continue;
            $raw = $zip->getFromIndex($i);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'AVSF_SETTINGS_PREIMPORT_BACKUP') throw new RuntimeException('Settings Backup History ไม่ใช่รูปแบบที่รองรับ: '.$name);
            $target = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($target)) $target = $dir . DIRECTORY_SEPARATOR . preg_replace('/\.json$/', '-migrated-' . substr(hash('sha256',$raw),0,10) . '.json', $name);
            if (file_exists($target) || file_put_contents($target, $raw, LOCK_EX) === false) throw new RuntimeException('บันทึก Settings Backup History ไม่สำเร็จ: '.$name);
            @chmod($target, 0600); $restored[] = basename($target);
        }
        return $restored;
    }

    /** Restore previously retained private backup archives without exposing them under the web root. */
    private function restoreBackupHistory($zip) {
        $restored = [];
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $entry = (string)$stat['name'];
            if (strpos($entry, 'backup_history/') !== 0 || substr($entry, -1) === '/') continue;
            $name = substr($entry, strlen('backup_history/'));
            if (!preg_match('/^avsf-backup-[A-Za-z0-9_.-]+\.zip$/', $name)) continue;
            $input = $zip->getStream($entry);
            if (!$input) throw new RuntimeException('อ่านประวัติ Backup ไม่สำเร็จ: ' . $name);
            $temp = @tempnam($this->backupDir, '.avsf-history-');
            if ($temp === false) { fclose($input); throw new RuntimeException('สร้างไฟล์ชั่วคราวสำหรับประวัติ Backup ไม่สำเร็จ'); }
            $output = @fopen($temp, 'wb');
            if (!$output) { fclose($input); @unlink($temp); throw new RuntimeException('เปิดไฟล์ปลายทางประวัติ Backup ไม่สำเร็จ'); }
            $copied = stream_copy_to_stream($input, $output);
            fclose($input); fclose($output);
            if ($copied === false || $copied !== (int)$stat['size']) { @unlink($temp); throw new RuntimeException('คัดลอกประวัติ Backup ไม่ครบ: ' . $name); }
            try { $this->inspectArchive($temp); }
            catch (Throwable $e) { @unlink($temp); throw new RuntimeException('ประวัติ Backup ภายในไม่ผ่านการตรวจสอบ: ' . $name); }
            $targetName = $name;
            $target = $this->backupDir . DIRECTORY_SEPARATOR . $targetName;
            if (file_exists($target)) {
                $targetName = preg_replace('/\.zip$/', '-migrated-' . substr(hash_file('sha256', $temp), 0, 10) . '.zip', $name);
                $target = $this->backupDir . DIRECTORY_SEPARATOR . $targetName;
            }
            if (file_exists($target) || !@rename($temp, $target)) { @unlink($temp); throw new RuntimeException('บันทึกประวัติ Backup ไม่สำเร็จ: ' . $name); }
            @chmod($target, 0600);
            $restored[] = $targetName;
        }
        return $restored;
    }

    /**
     * Decide whether a file belongs in a clean install package.
     * This is intentionally stricter than ordinary backups: all live content/media,
     * secrets and runtime artifacts are omitted, while source templates and help text remain.
     */
    private function isCleanPackageExcludedPath($relative) {
        $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
        $lower = strtolower($relative);
        $parts = array_values(array_filter(explode('/', $lower), 'strlen'));
        $blockedDirs = ['.git','.ssh','node_modules','avsf-updates','sessions','session','private','secrets','credentials','certificates','ssl','.well-known','.tmb','.trash','wp-admin','wp-content','wp-includes'];
        foreach ($parts as $part) if (in_array($part, $blockedDirs, true)) return true;
        // Exclude only runtime cache/log/backup folders, never the AVSF Core cache library.
        if (preg_match('#^(?:cache|logs|backups|quarantine|tmp|temp)(?:/|$)#i', $lower)) return true;
        if (preg_match('#^avsf-storage/(?:cache|logs|backups|quarantine|tmp|temp)(?:/|$)#i', $lower)) return true;
        if (preg_match('/(?:^|\/)avsf-installer\/clean-package(?:\/|$)/i', $relative)) return true;

        // Keep only guard files inside managed uploads folders; never ship live media.
        foreach (['avsf-storage/uploads/','uploads/'] as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                $tail = substr($lower, strlen($prefix));
                if ($tail !== '.htaccess' && $tail !== 'index.html') return true;
            }
        }
        // Legacy root runtime folders are never part of a new-site package.
        if (preg_match('#^(?:uploads|cache|logs|backups|quarantine|tmp|temp)/#i', $relative)) return true;

        $base = strtolower(basename($relative));
        if (preg_match('/^\.env(?:\..*)?$/i', $base) || preg_match('/^config\.local\.php(?:\..*)?$/i', $base)) return true;
        if (in_array($base, ['.user.ini','.htpasswd','error_log','php_errorlog','.ds_store','thumbs.db','full-replace-clean-manifest.json','readme-full-replace-clean-th.txt','robots.txt','sitemap.xml','sitemap_index.xml','bing-site-auth.xml'], true)) return true;
        if (preg_match('/^(?:google[0-9a-f]+|yandex_[a-z0-9]+)\.html$/i', $base)) return true;
        if (preg_match('/(?:^|[._-])(secret|secrets|credential|credentials|private-key|private_key|access-token|access_token|api-key|api_key)(?:[._-]|$)/i', $base)) return true;
        if (preg_match('/\.(?:pem|key|p12|pfx|log|bak|old|swp|tmp|zip|tar|tgz|gz|sqlite|sqlite3|db|dump)$/i', $base)) return true;
        // The AVSF installer schema is required; all other SQL files are treated as database dumps.
        if (preg_match('/\.sql$/i', $base) && $relative !== 'avsf-installer/database-schema.sql') return true;
        return false;
    }

    /** Create a fresh-install ZIP without changing the currently running site or database. */
    public function createCleanSitePackage($version = '3.2.5') {
        @set_time_limit(0);
        if (!class_exists('ZipArchive')) throw new RuntimeException('เซิร์ฟเวอร์ไม่มี PHP ZipArchive จึงสร้าง Clean Site ZIP ไม่ได้');
        if (!function_exists('avsf_default_settings')) {
            $defaultsFile = $this->root . DIRECTORY_SEPARATOR . 'avsf-core' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'auto-install.php';
            if (is_file($defaultsFile)) require_once $defaultsFile;
        }
        if (!function_exists('avsf_default_settings')) throw new RuntimeException('ไม่พบชุดค่าเริ่มต้นกลางของ AVSF');
        $defaults = avsf_default_settings();
        if (!is_array($defaults) || empty($defaults)) throw new RuntimeException('ชุดค่าเริ่มต้นของ AVSF ว่างเปล่า');

        $stamp = date('Ymd-His');
        $fileName = 'AVSF-CLEAN-SITE-v' . preg_replace('/[^0-9.]/', '', (string)$version) . '-' . $stamp . '.zip';
        $archivePath = $this->backupDir . DIRECTORY_SEPARATOR . $fileName;
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('เริ่มสร้าง Clean Site ZIP ไม่สำเร็จ');
        $fileHashes = [];
        $fileBytes = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $absolute = $item->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($this->root))), '/');
                if ($relative === '' || $this->isCleanPackageExcludedPath($relative)) continue;
                if (is_link($absolute) || $item->isDir() || !$item->isFile() || !is_readable($absolute)) continue;
                $size = (int)filesize($absolute);
                if ($size < 0 || $size > 536870912 || $fileBytes + $size > 2147483648) throw new RuntimeException('ขนาด Source Package เกินขีดจำกัด 2 GB');
                if (!$zip->addFile($absolute, $relative)) throw new RuntimeException('เพิ่มไฟล์ลง Clean Site ZIP ไม่สำเร็จ: ' . $relative);
                $sourceHash = hash_file('sha256', $absolute);
                if (!is_string($sourceHash) || strlen($sourceHash) !== 64) throw new RuntimeException('คำนวณ SHA-256 ของไฟล์ไม่สำเร็จ: ' . $relative);
                $fileHashes[$relative] = $sourceHash;
                $fileBytes += $size;
            }
            $requiredFiles = ['index.php','config.php','.htaccess','avsf-installer/database-schema.sql','avsf-installer/db-setup.php','avsf-installer/setup.php','avsf-installer/server-reconnect.php','avsf-core/database/auto-install.php','avsf-core/cache/cache_lib.php'];
            foreach ($requiredFiles as $requiredFile) if (!isset($fileHashes[$requiredFile])) throw new RuntimeException('Clean Site Source ขาดไฟล์จำเป็น: ' . $requiredFile);
            $rewriteConfig = @file_get_contents($this->root . DIRECTORY_SEPARATOR . '.htaccess');
            foreach ([
                'RewriteRule ^db-setup\\.php$ avsf-installer/db-setup.php',
                'RewriteRule ^setup\\.php$ avsf-installer/setup.php',
                'RewriteRule ^server-reconnect\\.php$ avsf-installer/server-reconnect.php'
            ] as $requiredRoute) {
                if (!is_string($rewriteConfig) || strpos($rewriteConfig, $requiredRoute) === false) throw new RuntimeException('Root .htaccess ไม่มี Installer Route ที่จำเป็น: ' . $requiredRoute);
            }

            // Defaults are exported from the same canonical source used by auto-install.
            // Actual setting hints, descriptions, labels and placeholders remain in PHP/i18n source files.
            $defaultsPayload = [
                'format' => 'AVSF_CLEAN_DEFAULT_SETTINGS',
                'version' => 1,
                'product' => 'AVSF',
                'settings_count' => count($defaults),
                'settings' => $defaults,
                'notes' => [
                    'No live site settings, domain, database credentials, user accounts or API secrets are included.',
                    'Field labels, instructions, help text and placeholders remain in the source templates and language dictionaries.',
                    'The first-run installer applies these defaults automatically when the new database is initialized.'
                ]
            ];
            $defaultsJson = json_encode($defaultsPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($defaultsJson) || !$zip->addFromString('avsf-installer/clean-package/default-settings.json', $defaultsJson . "\n")) throw new RuntimeException('เพิ่ม Default Settings ลง ZIP ไม่สำเร็จ');
            $fileHashes['avsf-installer/clean-package/default-settings.json'] = hash('sha256', $defaultsJson . "\n");
            if (!$zip->addFromString('avsf-installer/clean-package/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n")) throw new RuntimeException('เพิ่มไฟล์ป้องกัน Clean Package ไม่สำเร็จ');
            $fileHashes['avsf-installer/clean-package/.htaccess'] = hash('sha256', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
            if (!$zip->addFromString('avsf-installer/clean-package/index.php', "<?php http_response_code(404); exit;\n")) throw new RuntimeException('เพิ่มไฟล์ป้องกัน Index Clean Package ไม่สำเร็จ');
            $fileHashes['avsf-installer/clean-package/index.php'] = hash('sha256', "<?php http_response_code(404); exit;\n");

            $readme = "AVSF B-NTP — CLEAN SITE PACKAGE\n"
                . "=================================\n\n"
                . "วัตถุประสงค์\n"
                . "แพ็กเกจนี้เป็นซอร์ส AVSF สำหรับติดตั้งเว็บไซต์ใหม่แบบสะอาด โดยไม่แก้ไขเว็บไซต์ต้นทางที่ใช้สร้าง ZIP\n\n"
                . "สิ่งที่รวมอยู่\n"
                . "- ซอร์ส AVSF ทั้งหมด รวม Admin, Site Center, Frontend, Installer, CSS, JavaScript และไฟล์ช่วยเหลือ\n"
                . "- config.php แบบทั่วไปและ .htaccess สำหรับ route/security ของ AVSF\n"
                . "- database-schema.sql และค่าเริ่มต้นที่สร้างจาก auto-install.php รุ่นเดียวกัน\n"
                . "- ข้อความช่วยเหลือ ป้ายชื่อ คำแนะนำ และ Placeholder ในหน้า Settings ยังคงอยู่ เพราะเป็นส่วนหนึ่งของซอร์สและไฟล์ภาษา\n\n"
                . "สิ่งที่ไม่รวม\n"
                . "- config.local.php, .env, รหัสผ่านฐานข้อมูล และข้อมูลเชื่อมต่อเฉพาะเว็บ\n"
                . "- SQL dump ของเว็บไซต์ต้นทาง, ผู้ใช้, รหัสผ่าน Hash, 2FA Secret และข้อมูล Activity\n"
                . "- โพสต์ หน้าเพจ นักแสดง ค่าย หมวดหมู่ แท็ก Redirect/SEO Override และเนื้อหาเว็บไซต์เดิม\n"
                . "- ไฟล์สื่อใน uploads, cache, logs, backups, quarantine และไฟล์ runtime\n"
                . "- API Key, Token, Proxy, SMTP Credential และ secret ที่ผู้ดูแลกรอกในเว็บต้นทาง\n\n"
                . "วิธีติดตั้งบน Hosting ใหม่\n"
                . "1. แตก ZIP ทั้งชุดลงใน Document Root ของโดเมนใหม่ (ให้ไฟล์ index.php และ config.php อยู่ที่ Root)\n"
                . "2. สร้าง MySQL Database และ User ใหม่ในแผงควบคุม Hosting พร้อมกำหนดสิทธิ์ใช้งาน\n"
                . "3. เปิด https://โดเมนใหม่/db-setup.php แล้วกรอก MySQL Host, Port, Database Name, Username และ Password\n"
                . "4. ระบบสร้าง config.local.php และเชื่อมต่อฐานข้อมูล จากนั้นสร้างตาราง/ค่าเริ่มต้นอัตโนมัติ\n"
                . "5. เปิด /setup.php เพื่อสร้างบัญชี Owner คนแรก ตั้งรหัสผ่าน และเปิดใช้งาน 2FA\n"
                . "6. เข้าสู่หลังบ้าน ตรวจ Site URL, SEO, Permalink, Upload Permission และการส่งอีเมลก่อนเปิดเว็บจริง\n\n"
                . "สำคัญ\n"
                . "- อย่า Import SQL dump จากเว็บต้นทางลงฐานข้อมูลใหม่ เพราะแพ็กเกจนี้ตั้งใจเริ่มด้วยฐานข้อมูลใหม่\n"
                . "- อย่านำ config.local.php หรือ .env จากเว็บเก่ามาใช้กับโดเมนใหม่ เว้นแต่ตั้งใจใช้ฐานข้อมูลเดียวกันและตรวจสอบแล้ว\n"
                . "- หลังสร้าง Owner แล้ว ให้ปิด/จำกัดการเข้าถึงไฟล์ติดตั้งตามนโยบาย Hosting และเก็บ ZIP ต้นฉบับไว้ในพื้นที่ส่วนตัว\n"
                . "- การสร้าง Clean Package ไม่ลบข้อมูลใด ๆ จากเว็บไซต์ต้นทาง\n";
            if (!$zip->addFromString('avsf-installer/clean-package/README-TH.txt', $readme)) throw new RuntimeException('เพิ่มคู่มือติดตั้ง Clean Package ไม่สำเร็จ');
            $fileHashes['avsf-installer/clean-package/README-TH.txt'] = hash('sha256', $readme);

            $englishReadme = "AVSF B-NTP — CLEAN SITE PACKAGE\n"
                . "=================================\n\n"
                . "PURPOSE\nThis archive is a clean AVSF source package for deploying a new website. Creating it does not modify the source website or its database.\n\n"
                . "INCLUDED\n- AVSF source: Admin, Site Center, Frontend, Installer, CSS, JavaScript and language dictionaries.\n"
                . "- Generic config.php, site routing/security .htaccess and the canonical database-schema.sql.\n"
                . "- Canonical defaults from the same avsf_default_settings() function used by the first-run installer.\n"
                . "- Labels, help text, descriptions, examples, placeholders and Thai/English dictionaries.\n\n"
                . "EXCLUDED\n- config.local.php, .env variants, database credentials and live SQL dumps.\n"
                . "- Existing users, password hashes, roles, permissions, 2FA secrets and activity history.\n"
                . "- Posts, pages, actors, studios, taxonomies, redirects, SEO overrides and site-specific settings.\n"
                . "- Uploaded media and runtime files including cache, logs, backups and quarantine.\n"
                . "- API keys, tokens, SMTP credentials, proxies, private keys and site verification files.\n\n"
                . "INSTALL ON NEW HOSTING\n"
                . "1. Create a new empty MySQL database and database user in your hosting control panel.\n"
                . "2. Extract this ZIP into the new domain's Document Root. Ensure index.php and config.php are at the root.\n"
                . "3. Open https://your-new-domain/db-setup.php and enter the MySQL host, port, database name, username and password.\n"
                . "4. AVSF creates config.local.php and initializes the schema and canonical default settings. Do not import an old database dump.\n"
                . "5. Open /setup.php in the same browser to create the first Owner account and complete the mandatory 2FA setup at first login.\n"
                . "6. Configure the site name, canonical URL, SEO, permalinks, mail and external integrations before launch.\n\n"
                . "SECURITY\n- Never publish config.local.php or this ZIP. Keep the first-run endpoints restricted after setup according to your hosting policy.\n"
                . "- Use a dedicated empty database for the new installation.\n";
            if (!$zip->addFromString('avsf-installer/clean-package/README-EN.txt', $englishReadme)) throw new RuntimeException('Could not add the English Clean Package guide.');
            $fileHashes['avsf-installer/clean-package/README-EN.txt'] = hash('sha256', $englishReadme);

            ksort($fileHashes, SORT_STRING);
            $manifest = [
                'format' => 'AVSF_CLEAN_SITE_PACKAGE',
                'format_version' => 1,
                'product' => 'AVSF',
                'publisher' => 'B-NTP',
                'version' => (string)$version,
                'created_at' => date('c'),
                'created_at_utc' => gmdate('c'),
                'deployment_mode' => 'fresh_install',
                'source_is_modified' => false,
                'live_database_dump_included' => false,
                'default_settings_count' => count($defaults),
                'source_files' => count($fileHashes),
                'source_uncompressed_bytes' => $fileBytes,
                'hash_algorithm' => 'SHA-256',
                'files' => $fileHashes,
                'preserved_in_source_ui' => ['field labels','help text','descriptions','placeholders','Thai/English dictionaries','SEO field guidance'],
                'deployment_guides' => ['avsf-installer/clean-package/README-TH.txt','avsf-installer/clean-package/README-EN.txt'],
                'excluded' => ['config.local.php and variants','.env and variants','SQL/database dumps except avsf-installer/database-schema.sql','database/archive files','users and 2FA secrets','site content','uploaded media','cache','logs','backup history','quarantine','private keys and credential-named files','site verification files','site-specific API/SMTP credentials']
            ];
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($manifestJson) || !$zip->addFromString('avsf-installer/clean-package/MANIFEST.json', $manifestJson . "\n")) throw new RuntimeException('เพิ่ม Clean Package Manifest ไม่สำเร็จ');
            if (!$zip->close()) throw new RuntimeException('ปิด Clean Site ZIP ไม่สำเร็จ');
            @chmod($archivePath, 0600);
            // Re-open the ZIP and verify every manifest hash before returning it.
            $check = new ZipArchive();
            if ($check->open($archivePath, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Clean Site ZIP เปิดตรวจสอบหลังสร้างไม่สำเร็จ');
            try {
                $manifestRaw = $check->getFromName('avsf-installer/clean-package/MANIFEST.json');
                $decoded = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
                if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'AVSF_CLEAN_SITE_PACKAGE') throw new RuntimeException('Clean Site Manifest ไม่ถูกต้อง');
                if ((int)($decoded['default_settings_count'] ?? 0) !== count($defaults)) throw new RuntimeException('จำนวน Default Settings ใน Manifest ไม่ตรงกับ Auto Installer');
                foreach ($requiredFiles as $requiredFile) if ($check->locateName($requiredFile) === false) throw new RuntimeException('Clean Site ZIP ขาดไฟล์จำเป็น: ' . $requiredFile);
                foreach (($decoded['files'] ?? []) as $path => $expectedHash) {
                    $raw = $check->getFromName($path);
                    if (!is_string($raw) || !hash_equals((string)$expectedHash, hash('sha256', $raw))) throw new RuntimeException('Hash ของไฟล์ Clean Package ไม่ถูกต้อง: ' . $path);
                }
                $seenEntries = [];
                for ($entryIndex = 0; $entryIndex < $check->numFiles; $entryIndex++) {
                    $entryStat = $check->statIndex($entryIndex);
                    if (!$entryStat) continue;
                    $entryName = ltrim(str_replace('\\', '/', (string)$entryStat['name']), '/');
                    if (isset($seenEntries[$entryName])) throw new RuntimeException('Clean Package มี Path ซ้ำ: ' . $entryName);
                    $seenEntries[$entryName] = true;
                    $entryBase = strtolower(basename($entryName));
                    $entryLower = strtolower($entryName);
                    if (preg_match('/^\.env(?:\..*)?$/i', $entryBase) || preg_match('/^config\.local\.php(?:\..*)?$/i', $entryBase)) throw new RuntimeException('Clean Package มีไฟล์กำหนดค่าเฉพาะเว็บไซต์ที่ไม่ควรรวม: ' . $entryName);
                    if (preg_match('/\.(?:pem|key|p12|pfx|log|bak|old|swp|tmp|zip|tar|tgz|gz|sqlite|sqlite3|db|dump)$/i', $entryBase)) throw new RuntimeException('Clean Package มีไฟล์ลับ/ไฟล์สำรองที่ไม่ควรรวม: ' . $entryName);
                    if (preg_match('/\.sql$/i', $entryBase) && $entryName !== 'avsf-installer/database-schema.sql') throw new RuntimeException('Clean Package มี SQL Dump ที่ไม่ควรรวม: ' . $entryName);
                    if (strpos($entryLower, 'avsf-storage/uploads/') === 0) {
                        $uploadTail = substr($entryLower, strlen('avsf-storage/uploads/'));
                        if ($uploadTail !== '.htaccess' && $uploadTail !== 'index.html') throw new RuntimeException('Clean Package มีไฟล์สื่อเดิมที่ไม่ควรรวม: ' . $entryName);
                    }
                    if (strpos($entryLower, 'uploads/') === 0) throw new RuntimeException('Clean Package มี Root Uploads ที่ไม่ควรรวม: ' . $entryName);
                }
            } finally { $check->close(); }
            return ['name'=>$fileName,'path'=>$archivePath,'size'=>(int)filesize($archivePath),'files'=>count($fileHashes),'defaults'=>count($defaults),'manifest'=>$manifest];
        } catch (Throwable $e) {
            try { $zip->close(); } catch (Throwable $ignored) {}
            @unlink($archivePath);
            throw $e;
        }
    }

    /**
     * Clear only AVSF-owned, explicitly allow-listed website data.
     * A complete private Files + Database safety backup is mandatory before any reset.
     */
    public function resetSiteData($conn, $clearUploads = true) {
        @set_time_limit(0);
        if (!is_object($conn) || !method_exists($conn, 'query')) throw new RuntimeException('ไม่พบการเชื่อมต่อฐานข้อมูล');
        if (!function_exists('avsf_default_settings')) {
            $defaultsFile = $this->root . DIRECTORY_SEPARATOR . 'avsf-core' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'auto-install.php';
            if (is_file($defaultsFile)) require_once $defaultsFile;
        }
        if (!function_exists('avsf_default_settings')) throw new RuntimeException('ไม่พบค่าเริ่มต้นมาตรฐาน จึงยกเลิกการล้างข้อมูล');
        $defaults = avsf_default_settings();

        $tableResult = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if (!$tableResult) throw new RuntimeException('ตรวจสอบตารางฐานข้อมูลก่อน Reset ไม่สำเร็จ: ' . $conn->error);
        $existing = [];
        while ($row = $tableResult->fetch_row()) $existing[] = (string)$row[0];
        $tableResult->free();
        $allowed = ['comments','seo_index_overrides','slug_aliases','posts','banners','pages','studios','actors','tags','categories','redirects','activity','login_attempts','cache_stats','404_log','server_error_log','recent_page_views','users','settings'];
        foreach (['settings','users','posts'] as $required) if (!in_array($required, $existing, true)) throw new RuntimeException('ไม่พบตารางหลัก ' . $required . ' — กรุณาตรวจสอบโครงสร้าง AVSF ก่อน Reset');
        $unknown = array_values(array_diff($existing, $allowed));

        // DELETE must be rollback-safe. Refuse a reset if any AVSF-owned table is non-transactional.
        $statusResult = $conn->query('SHOW TABLE STATUS');
        if (!$statusResult) throw new RuntimeException('ตรวจสอบ Storage Engine ของตารางก่อน Reset ไม่สำเร็จ: ' . $conn->error);
        $engines = [];
        while ($statusRow = $statusResult->fetch_assoc()) {
            $engines[(string)$statusRow['Name']] = strtoupper((string)($statusRow['Engine'] ?? ''));
        }
        $statusResult->free();
        $nonTransactional = [];
        foreach ($allowed as $table) {
            if (in_array($table, $existing, true) && ($engines[$table] ?? '') !== 'INNODB') $nonTransactional[] = $table . ':' . ($engines[$table] ?? 'UNKNOWN');
        }
        if ($nonTransactional) throw new RuntimeException('ไม่สามารถ Reset อย่างปลอดภัยได้ เพราะตารางต่อไปนี้ไม่ใช่ InnoDB (Rollback ไม่รับประกัน): ' . implode(', ', $nonTransactional));

        // Capture all DB tables and website files (including uploaded media) first.
        // Do not touch live data until the resulting Safety Backup has been reopened,
        // structurally validated, and its database dump/hash has passed inspection.
        $safety = $this->createBackup($conn, true, true, false);
        try {
            $verifiedSafetyPath = $this->findStoredBackup($safety['name'] ?? '');
            if (!$verifiedSafetyPath || realpath($verifiedSafetyPath) !== realpath((string)($safety['path'] ?? ''))) {
                throw new RuntimeException('Safety Backup ไม่อยู่ในพื้นที่จัดเก็บส่วนตัวที่คาดหมาย');
            }
            $safetyManifest = $this->inspectArchive($verifiedSafetyPath);
            if (empty($safetyManifest['database']['included']) || (int)($safetyManifest['database']['tables'] ?? 0) < count($existing) || (int)($safetyManifest['_archive_files'] ?? 0) < 1) {
                throw new RuntimeException('Safety Backup ไม่ครอบคลุมฐานข้อมูลและไฟล์เว็บไซต์ครบถ้วน');
            }
        } catch (Throwable $backupValidationError) {
            throw new RuntimeException('หยุด Reset เพื่อความปลอดภัย เพราะ Safety Backup ยังตรวจสอบไม่ผ่าน · ไฟล์ที่สร้างไว้: ' . (string)($safety['name'] ?? 'unknown') . ' · ' . $backupValidationError->getMessage(), 0, $backupValidationError);
        }
        $cleared = [];
        if (!$conn->query('SET FOREIGN_KEY_CHECKS=0')) throw new RuntimeException('ปิดการตรวจ Foreign Key ชั่วคราวไม่สำเร็จ · Safety Backup: ' . $safety['name']);
        try {
            if (!$conn->begin_transaction()) throw new RuntimeException('เริ่ม Transaction สำหรับ Reset ไม่สำเร็จ: ' . $conn->error);
            foreach ($allowed as $table) {
                if ($table === 'settings' || !in_array($table, $existing, true)) continue;
                if (!$conn->query('DELETE FROM ' . $this->quoteIdentifier($table))) throw new RuntimeException('ล้างตาราง ' . $table . ' ไม่สำเร็จ: ' . $conn->error);
                $cleared[$table] = (int)$conn->affected_rows;
            }
            if (!$conn->query('DELETE FROM `settings`')) throw new RuntimeException('ล้างตาราง settings ไม่สำเร็จ: ' . $conn->error);
            $stmt = $conn->prepare('INSERT INTO `settings` (`skey`,`svalue`) VALUES (?,?)');
            if (!$stmt) throw new RuntimeException('เตรียมบันทึกค่าเริ่มต้นไม่สำเร็จ: ' . $conn->error);
            foreach ($defaults as $key => $value) {
                $keyString = (string)$key; $valueString = (string)$value;
                $stmt->bind_param('ss', $keyString, $valueString);
                if (!$stmt->execute()) { $dbError = $stmt->error; $stmt->close(); throw new RuntimeException('บันทึกค่าเริ่มต้น ' . $keyString . ' ไม่สำเร็จ: ' . $dbError); }
            }
            $stmt->close();
            $cleared['settings'] = 0;
            $cleared['settings_defaults_restored'] = count($defaults);
            if (!$conn->commit()) throw new RuntimeException('บันทึกการ Reset ไม่สำเร็จ: ' . $conn->error);
        } catch (Throwable $e) {
            @$conn->rollback();
            throw new RuntimeException('Reset ฐานข้อมูลไม่สำเร็จ จึงยกเลิก Transaction แล้ว · Safety Backup: ' . $safety['name'] . ' · ' . $e->getMessage(), 0, $e);
        } finally {
            if (!$conn->query('SET FOREIGN_KEY_CHECKS=1')) error_log('AVSF Reset could not restore FOREIGN_KEY_CHECKS=1: ' . $conn->error);
        }

        $mediaRemoved = 0;
        $cleanupWarnings = [];
        if ($clearUploads) {
            $mediaDirs = [
                $this->root . DIRECTORY_SEPARATOR . 'avsf-storage' . DIRECTORY_SEPARATOR . 'uploads',
                $this->root . DIRECTORY_SEPARATOR . 'uploads'
            ];
            foreach ($mediaDirs as $dir) {
                try { $mediaRemoved += $this->clearDirectoryContents($dir, ['.htaccess','index.html']); }
                catch (Throwable $cleanupError) { $cleanupWarnings[] = $cleanupError->getMessage(); }
            }
        }
        // Runtime data can be regenerated. Keep backup directories and application code untouched.
        foreach (['avsf-storage/cache','avsf-storage/logs','avsf-storage/quarantine','cache','logs','quarantine'] as $relativeDir) {
            $dir = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
            if (is_dir($dir) && !is_link($dir)) {
                try { $this->clearDirectoryContents($dir, ['.htaccess','index.php','index.html']); }
                catch (Throwable $cleanupError) { $cleanupWarnings[] = $cleanupError->getMessage(); }
            }
        }
        return [
            'safety_backup'=>$safety['name'],
            'tables_cleared'=>$cleared,
            'unknown_tables_preserved'=>$unknown,
            'media_files_removed'=>$mediaRemoved,
            'defaults_restored'=>count($defaults),
            'uploads_cleared'=>(bool)$clearUploads,
            'cleanup_warnings'=>$cleanupWarnings
        ];
    }

    /** Remove contents of a known AVSF data directory without following symlinks or deleting its guard files. */
    private function clearDirectoryContents($directory, $preserveTopLevel = []) {
        if (!is_dir($directory) || is_link($directory)) return 0;
        $base = realpath($directory);
        $rootReal = realpath($this->root);
        if ($base === false || $rootReal === false || ($base !== $rootReal && strpos($base, $rootReal . DIRECTORY_SEPARATOR) !== 0)) throw new RuntimeException('พื้นที่ล้างข้อมูลอยู่นอก AVSF Root');
        $removed = 0;
        $items = scandir($base);
        if (!is_array($items)) throw new RuntimeException('อ่านโฟลเดอร์ข้อมูลไม่สำเร็จ: ' . basename($base));
        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $preserveTopLevel, true)) continue;
            $path = $base . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || is_file($path)) {
                if (!@unlink($path)) throw new RuntimeException('ลบไฟล์ข้อมูลไม่สำเร็จ: ' . basename($path));
                $removed++;
                continue;
            }
            if (is_dir($path)) $removed += $this->removeDirectoryTree($path, $base);
        }
        return $removed;
    }

    private function removeDirectoryTree($directory, $allowedParent) {
        $parentReal = realpath($allowedParent); $dirReal = realpath($directory);
        if ($parentReal === false || $dirReal === false || strpos($dirReal, $parentReal . DIRECTORY_SEPARATOR) !== 0 || is_link($directory)) throw new RuntimeException('พบ path ไม่ปลอดภัยระหว่างล้างข้อมูล');
        $removed = 0;
        foreach (scandir($dirReal) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dirReal . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || is_file($path)) { if (!@unlink($path)) throw new RuntimeException('ลบไฟล์ไม่สำเร็จระหว่าง Reset'); $removed++; }
            elseif (is_dir($path)) $removed += $this->removeDirectoryTree($path, $dirReal);
        }
        if (!@rmdir($dirReal)) throw new RuntimeException('ลบโฟลเดอร์ข้อมูลไม่สำเร็จ: ' . basename($dirReal));
        return $removed;
    }

    public function createBackup($conn, $includeDatabase, $store = true, $includeBackupHistory = false) {
        @set_time_limit(0);
        if (!class_exists('ZipArchive')) throw new RuntimeException('เซิร์ฟเวอร์ไม่มี PHP ZipArchive จึงสร้าง ZIP Backup ไม่ได้');
        $tempZip = tempnam(sys_get_temp_dir(), 'avsf-backup-');
        $tempSql = tempnam(sys_get_temp_dir(), 'avsf-sql-');
        if ($tempZip === false || $tempSql === false) throw new RuntimeException('สร้างไฟล์ชั่วคราวไม่สำเร็จ');
        @unlink($tempZip);
        $zip = new ZipArchive();
        $openResult = $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) { @unlink($tempZip); @unlink($tempSql); throw new RuntimeException('เริ่มสร้าง ZIP Backup ไม่สำเร็จ'); }
        try {
            $dbInfo = null;
            if ($includeDatabase) {
                $dbInfo = $this->writeDatabaseDump($conn, $tempSql);
                if (!$zip->addFile($tempSql, 'database/database.sql')) throw new RuntimeException('เพิ่มไฟล์ฐานข้อมูลลง ZIP ไม่สำเร็จ');
            }
            $fileCount = $this->addWebsiteFiles($zip);
            $historyInfo = $includeBackupHistory ? $this->addStoredBackupHistory($zip) : ['included'=>false,'count'=>0,'bytes'=>0,'files'=>[]];
            $settingsHistoryInfo = $includeBackupHistory ? $this->addSettingsBackupHistory($zip) : ['included'=>false,'count'=>0,'bytes'=>0,'files'=>[]];
            $manifest = [
                'format' => 'AVSF_BACKUP', 'format_version' => 1,
                'created_at' => date('c'), 'created_at_utc' => gmdate('c'),
                'backup_type' => $includeDatabase ? 'files_and_database' : 'files_only',
                'website_files' => $fileCount,
                'database_object_scope' => $includeDatabase ? 'base_tables_only' : 'not_included',
                'database' => $dbInfo ? ['included'=>true,'tables'=>$dbInfo['tables'],'bytes'=>$dbInfo['bytes'],'sha256'=>$dbInfo['sha256']] : ['included'=>false],
                'config_files_excluded' => ['config.php','config.local.php','.env','.user.ini'],
                'backup_history' => $historyInfo,
                'settings_backup_history' => $settingsHistoryInfo,
            ];
            if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) throw new RuntimeException('เขียน Manifest ลง ZIP ไม่สำเร็จ');
            if (!$zip->close()) throw new RuntimeException('ปิด ZIP Backup ไม่สำเร็จ');
            @unlink($tempSql);
            if (!is_file($tempZip) || filesize($tempZip) < 100) throw new RuntimeException('ไฟล์ Backup ที่สร้างมีขนาดผิดปกติ');
            $name = 'avsf-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
            $destination = $store ? $this->backupDir . DIRECTORY_SEPARATOR . $name : $tempZip;
            if ($store) {
                if (!@rename($tempZip, $destination)) {
                    if (!@copy($tempZip, $destination)) throw new RuntimeException('บันทึก Backup ลงพื้นที่ส่วนตัวไม่สำเร็จ');
                    @unlink($tempZip);
                }
                @chmod($destination, 0600);
            }
            return ['path'=>$destination,'name'=>$name,'size'=>filesize($destination),'manifest'=>$manifest];
        } catch (Throwable $e) {
            $zip->close();
            @unlink($tempZip); @unlink($tempSql);
            throw $e;
        }
    }

    public function findStoredBackup($name) {
        $name = basename((string)$name);
        if ($name === '' || !preg_match('/^avsf-backup-[A-Za-z0-9_.-]+\.zip$/', $name)) return false;
        $path = $this->backupDir . DIRECTORY_SEPARATOR . $name;
        $real = realpath($path);
        $base = realpath($this->backupDir);
        if ($real === false || $base === false || !is_file($real) || is_link($path) || strpos($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) return false;
        return $real;
    }

    public function listBackups() {
        $items = [];
        foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . 'avsf-backup-*.zip') ?: [] as $path) {
            if (is_link($path) || !is_file($path) || !is_readable($path)) continue;
            $manifest = null;
            try { $manifest = $this->inspectArchive($path); } catch (Throwable $e) { $manifest = null; }
            $items[] = ['name'=>basename($path),'size'=>filesize($path),'modified'=>date('Y-m-d H:i:s',filemtime($path)),'valid'=>is_array($manifest),'manifest'=>$manifest];
        }
        usort($items, function($a,$b){ return strcmp($b['modified'],$a['modified']); });
        return $items;
    }

    private function validateRelativePath($path) {
        if (!is_string($path) || $path === '' || strpos($path, "\0") !== false || strpos($path, ':') !== false) return false;
        $path = str_replace('\\', '/', $path);
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path) || strpos($path, '//') !== false) return false;
        $parts = explode('/', rtrim($path, '/'));
        foreach ($parts as $part) if ($part === '' || $part === '..' || $part === '.') return false;
        return isset($parts[0]) && $parts[0] === 'website' && count($parts) >= 2;
    }

    public function inspectArchive($path) {
        @set_time_limit(0);
        if (!class_exists('ZipArchive')) throw new RuntimeException('เซิร์ฟเวอร์ไม่มี PHP ZipArchive');
        if (!is_file($path) || is_link($path)) throw new RuntimeException('ไม่พบไฟล์ Backup');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('ไฟล์ ZIP เสียหายหรือเปิดไม่ได้');
        try {
            $manifestRaw = $zip->getFromName('manifest.json');
            $manifest = $manifestRaw !== false ? json_decode($manifestRaw, true) : null;
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'AVSF_BACKUP' || (int)($manifest['format_version'] ?? 0) !== 1) throw new RuntimeException('ไม่ใช่ไฟล์สำรองมาตรฐาน AVSF หรือ Manifest ไม่ถูกต้อง');
            $hasDb = false; $fileCount = 0; $historyCount = 0; $settingsHistoryCount = 0; $totalUncompressed = 0;
            if ($zip->numFiles > 50000) throw new RuntimeException('ZIP มีจำนวนรายการเกิน 50,000 รายการ');
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) throw new RuntimeException('อ่านรายการไฟล์ภายใน ZIP ไม่ได้');
                $name = (string)$stat['name'];
                $entrySize = (int)($stat['size'] ?? 0);
                $totalUncompressed += $entrySize;
                if ($entrySize < 0 || $entrySize > 536870912 || $totalUncompressed > 2147483648) throw new RuntimeException('ขนาดข้อมูลภายใน ZIP เกินขีดจำกัดที่ปลอดภัย');
                if (strpos($name, 'database/') === 0) {
                    if ($name === 'database/database.sql') {
                        $hasDb = true;
                        if ($entrySize > 268435456) throw new RuntimeException('ไฟล์ SQL ใหญ่เกิน 256 MB สำหรับ Restore ผ่าน PHP; กรุณาใช้เครื่องมือฐานข้อมูลของ Hosting');
                    }
                    elseif (strpos($name, 'database/') === 0 && substr($name, -1) !== '/') throw new RuntimeException('พบไฟล์นอกโครงสร้างฐานข้อมูลที่อนุญาต');
                    continue;
                }
                if ($name === 'manifest.json') continue;
                if ($name === 'backup_history/') continue;
                if ($name === 'settings_backup_history/') continue;
                if (strpos($name, 'settings_backup_history/') === 0) {
                    $settingsName = substr($name, strlen('settings_backup_history/'));
                    if (!preg_match('/^settings-before-import-[A-Za-z0-9_.-]+\.json$/', $settingsName)) throw new RuntimeException('ชื่อไฟล์ Settings Backup History ไม่ปลอดภัย');
                    if ($entrySize > 10485760) throw new RuntimeException('Settings Backup History ใหญ่เกิน 10 MB ต่อไฟล์');
                    $settingsHistoryCount++;
                    continue;
                }
                if (strpos($name, 'backup_history/') === 0) {
                    $historyName = substr($name, strlen('backup_history/'));
                    if (!preg_match('/^avsf-backup-[A-Za-z0-9_.-]+\.zip$/', $historyName)) throw new RuntimeException('ชื่อไฟล์ในประวัติ Backup ไม่ปลอดภัย');
                    if ($entrySize > 536870912) throw new RuntimeException('ไฟล์ Backup เก่าใหญ่เกิน 512 MB ต่อไฟล์');
                    $historyCount++;
                    continue;
                }
                if (strpos($name, 'website/') === 0) {
                    if (!$this->validateRelativePath($name)) throw new RuntimeException('พบ path ที่ไม่ปลอดภัยใน ZIP');
                    if (substr($name, -1) !== '/') $fileCount++;
                    continue;
                }
                if (substr($name, -1) !== '/') throw new RuntimeException('พบไฟล์นอกโครงสร้าง AVSF Backup: ' . basename($name));
            }
            $manifestHasDb = !empty($manifest['database']['included']);
            if ($manifestHasDb !== $hasDb) throw new RuntimeException('ข้อมูล Manifest กับไฟล์ฐานข้อมูลใน ZIP ไม่ตรงกัน');
            if ($manifestHasDb) {
                $expectedHash = (string)($manifest['database']['sha256'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/i', $expectedHash)) throw new RuntimeException('Manifest ไม่มี SHA-256 ของฐานข้อมูลที่ถูกต้อง');
                if ((int)($manifest['database']['tables'] ?? 0) < 1) throw new RuntimeException('Manifest ระบุจำนวนตารางฐานข้อมูลไม่ถูกต้อง');
                $sql = $zip->getFromName('database/database.sql');
                if ($sql === false || !hash_equals(strtolower($expectedHash), hash('sha256',$sql))) throw new RuntimeException('ตรวจสอบความถูกต้องของไฟล์ SQL ไม่ผ่าน');
                $validated = $this->validateSqlDump($sql, $manifest);
            }
            if (isset($manifest['website_files']) && (int)$manifest['website_files'] !== $fileCount) throw new RuntimeException('จำนวนไฟล์ใน Manifest ไม่ตรงกับรายการใน ZIP');
            $historyMeta = $manifest['backup_history'] ?? ['included'=>false,'count'=>0,'files'=>[]];
            if ((int)($historyMeta['count'] ?? 0) !== $historyCount || (!empty($historyMeta['included']) !== ($historyCount > 0))) throw new RuntimeException('จำนวน Backup History ใน Manifest ไม่ตรงกับ ZIP');
            $historyExpected = [];
            foreach (($historyMeta['files'] ?? []) as $historyItem) {
                if (!is_array($historyItem) || !preg_match('/^avsf-backup-[A-Za-z0-9_.-]+\.zip$/', (string)($historyItem['name'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/i', (string)($historyItem['sha256'] ?? ''))) throw new RuntimeException('Manifest ของ Backup History ไม่ถูกต้อง');
                $historyExpected[(string)$historyItem['name']] = strtolower((string)$historyItem['sha256']);
            }
            if (count($historyExpected) !== $historyCount) throw new RuntimeException('รายการ Backup History ใน Manifest ไม่ครบ');
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); if (!$stat) continue;
                $entry = (string)$stat['name'];
                if (strpos($entry, 'backup_history/') !== 0 || substr($entry, -1) === '/') continue;
                $historyName = substr($entry, strlen('backup_history/'));
                $rawHistory = $zip->getFromIndex($i);
                if ($rawHistory === false || !isset($historyExpected[$historyName]) || !hash_equals($historyExpected[$historyName], hash('sha256', $rawHistory))) throw new RuntimeException('Hash ของ Backup History ไม่ถูกต้อง: ' . $historyName);
                $nestedTemp = tempnam(sys_get_temp_dir(), 'avsf-nested-');
                if ($nestedTemp === false || file_put_contents($nestedTemp, $rawHistory, LOCK_EX) === false) { if ($nestedTemp) @unlink($nestedTemp); throw new RuntimeException('ตรวจสอบ ZIP ประวัติ Backup ไม่สำเร็จ'); }
                $nestedZip = new ZipArchive();
                $nestedOk = $nestedZip->open($nestedTemp, ZipArchive::CHECKCONS) === true;
                $nestedManifestRaw = $nestedOk ? $nestedZip->getFromName('manifest.json') : false;
                if ($nestedOk) $nestedZip->close();
                @unlink($nestedTemp);
                $nestedManifest = $nestedManifestRaw !== false ? json_decode($nestedManifestRaw, true) : null;
                if (!is_array($nestedManifest) || ($nestedManifest['format'] ?? '') !== 'AVSF_BACKUP') throw new RuntimeException('พบไฟล์ประวัติที่ไม่ใช่ AVSF Backup: ' . $historyName);
            }
            $settingsMeta = $manifest['settings_backup_history'] ?? ['included'=>false,'count'=>0,'files'=>[]];
            if ((int)($settingsMeta['count'] ?? 0) !== $settingsHistoryCount || (!empty($settingsMeta['included']) !== ($settingsHistoryCount > 0))) throw new RuntimeException('จำนวน Settings Backup History ใน Manifest ไม่ตรงกับ ZIP');
            $settingsExpected = [];
            foreach (($settingsMeta['files'] ?? []) as $item) {
                if (!is_array($item) || !preg_match('/^settings-before-import-[A-Za-z0-9_.-]+\.json$/', (string)($item['name'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/i', (string)($item['sha256'] ?? ''))) throw new RuntimeException('Manifest ของ Settings Backup History ไม่ถูกต้อง');
                $settingsExpected[(string)$item['name']] = strtolower((string)$item['sha256']);
            }
            if (count($settingsExpected) !== $settingsHistoryCount) throw new RuntimeException('รายการ Settings Backup History ใน Manifest ไม่ครบ');
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat=$zip->statIndex($i); if (!$stat) continue;
                $entry=(string)$stat['name'];
                if (strpos($entry,'settings_backup_history/')!==0 || substr($entry,-1)==='/') continue;
                $settingsName=substr($entry,strlen('settings_backup_history/'));
                $rawSettings=$zip->getFromIndex($i);
                $settingsJson=is_string($rawSettings)?json_decode($rawSettings,true):null;
                if ($rawSettings===false || !isset($settingsExpected[$settingsName]) || !hash_equals($settingsExpected[$settingsName],hash('sha256',$rawSettings)) || !is_array($settingsJson) || ($settingsJson['format']??'')!=='AVSF_SETTINGS_PREIMPORT_BACKUP') throw new RuntimeException('Settings Backup History ไม่ผ่านการตรวจสอบ: '.$settingsName);
            }
            $manifest['_archive_files'] = $fileCount;
            $manifest['_backup_history_files'] = $historyCount;
            $manifest['_settings_backup_history_files'] = $settingsHistoryCount;
            $manifest['_database_tables'] = isset($validated) && is_array($validated) ? ($validated['table_names'] ?? []) : [];
            return $manifest;
        } finally { $zip->close(); }
    }

    /** Stream SQL statements on semicolons outside quoted strings/backticks/comments. */
    private function iterateSqlStatements($sql) {
        $buffer = ''; $quote = ''; $escaped = false; $lineComment = false; $blockComment = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i]; $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($lineComment) { if ($ch === "\n") { $lineComment = false; $buffer .= "\n"; } continue; }
            if ($blockComment) { if ($ch === '*' && $next === '/') { $blockComment = false; $i++; } continue; }
            if ($quote !== '') {
                $buffer .= $ch;
                if ($escaped) { $escaped = false; continue; }
                if ($ch === "\\") { $escaped = true; continue; }
                if ($ch === $quote) {
                    if ($next === $quote && $quote !== '`') { $buffer .= $next; $i++; continue; }
                    $quote = '';
                }
                continue;
            }
            if ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) { $lineComment = true; $i++; continue; }
            if ($ch === '#') { $lineComment = true; continue; }
            if ($ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buffer .= $ch; continue; }
            if ($ch === ';') { $trimmed = trim($buffer); if ($trimmed !== '') yield $trimmed; $buffer = ''; continue; }
            $buffer .= $ch;
        }
        if ($quote !== '' || $blockComment) throw new RuntimeException('ไฟล์ SQL มี quote/comment ที่ปิดไม่ครบ');
        $trimmed = trim($buffer); if ($trimmed !== '') yield $trimmed;
    }

    /** Reject arbitrary SQL before multi_query; only avsf-generated table dump statements are accepted. */
    private function validateSqlDump($sql, $manifest) {
        if (strpos($sql, "-- AVSF_DATABASE_BACKUP v1") !== 0) throw new RuntimeException('ไฟล์ SQL ไม่ใช่รูปแบบสำรองของ AVSF');
        $statementCount = 0;
        $created = []; $dropped = []; $inserts = 0;
        foreach ($this->iterateSqlStatements($sql) as $statement) {
            $statementCount++;
            if ($statementCount > 200000) throw new RuntimeException('จำนวนคำสั่ง SQL ใน Backup เกิน 200,000 คำสั่ง');
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^--/',$statement)) continue;
            if (preg_match('/^SET\s+(?:FOREIGN_KEY_CHECKS\s*=\s*[01]|SQL_MODE\s*=\s*\'(?:[^\']|\\\\.)*\'|NAMES\s+utf8mb4)$/i', $statement)) continue;
            if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`((?:``|[^`])+)`$/i', $statement, $m)) { $dropped[str_replace('``','`',$m[1])] = true; continue; }
            if (preg_match('/^CREATE\s+TABLE\s+`((?:``|[^`])+)`\s*\(/is', $statement, $m)) {
                $table = str_replace('``','`',$m[1]);
                if (isset($created[$table])) throw new RuntimeException('พบ CREATE TABLE ซ้ำในไฟล์ SQL');
                if (preg_match('/\b(?:DATA|INDEX)\s+DIRECTORY\b|\bTABLESPACE\b/i', $statement)) throw new RuntimeException('Backup มีตัวเลือกพื้นที่จัดเก็บตารางที่ไม่อนุญาต');
                $created[$table] = true;
                continue;
            }
            if (preg_match('/^INSERT\s+INTO\s+`((?:``|[^`])+)`\s*\(/is', $statement, $m)) {
                $table = str_replace('``','`',$m[1]);
                if (!isset($created[$table])) throw new RuntimeException('พบ INSERT ก่อน CREATE TABLE ในไฟล์ SQL');
                // Writer emits one row per statement and quotes every non-NULL value.
                if (!preg_match('/^INSERT\s+INTO\s+`(?:``|[^`])+`\s*\((?:`(?:``|[^`])+`\s*,?\s*)+\)\s*VALUES\s*\((?:NULL|\'(?:\\\\.|[^\'])*\')(?:\s*,\s*(?:NULL|\'(?:\\\\.|[^\'])*\'))*\)$/is', $statement)) {
                    throw new RuntimeException('พบรูปแบบ INSERT ที่ไม่ปลอดภัยในไฟล์ SQL');
                }
                $inserts++;
                continue;
            }
            throw new RuntimeException('พบคำสั่ง SQL ที่ไม่อนุญาตใน Backup: ' . strtoupper(strtok($statement, " \t\r\n")));
        }
        if ($statementCount < 4 || count($created) < 1 || count($created) !== (int)($manifest['database']['tables'] ?? 0)) throw new RuntimeException('จำนวนคำสั่งหรือตารางในไฟล์ SQL ไม่ตรงกับ Manifest');
        if (array_diff_key($dropped, $created) || array_diff_key($created, $dropped)) throw new RuntimeException('รายการ DROP/CREATE TABLE ใน Backup ไม่ตรงกัน');
        return ['tables'=>count($created),'inserts'=>$inserts,'table_names'=>array_keys($created)];
    }

    private function restoreDatabase($zip, $conn, $manifest) {
        if (!is_object($conn) || !method_exists($conn, 'query')) throw new RuntimeException('ไม่มีการเชื่อมต่อฐานข้อมูลสำหรับ Restore');
        $sql = $zip->getFromName('database/database.sql');
        if ($sql === false || $sql === '') throw new RuntimeException('ไม่พบไฟล์ SQL ใน Backup');
        if (empty($manifest['database']['sha256']) || !hash_equals(strtolower((string)$manifest['database']['sha256']), hash('sha256',$sql))) throw new RuntimeException('Hash ของฐานข้อมูลไม่ถูกต้อง');
        $validated = $this->validateSqlDump($sql, $manifest);
        $targetTables = array_fill_keys($validated['table_names'], true);
        $foreignKeysDisabled = false;
        try {
            // Restore means returning the DB to the snapshot. Remove base tables
            // created after that snapshot, but only within the currently selected DB.
            $currentTables = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            if (!$currentTables) throw new RuntimeException('อ่านรายชื่อตารางปัจจุบันก่อน Restore ไม่สำเร็จ: '.($conn->error ?? 'ไม่ทราบข้อผิดพลาด'));
            $extraTables = [];
            while ($tableRow = $currentTables->fetch_row()) {
                $currentName = (string)($tableRow[0] ?? '');
                if ($currentName !== '' && !isset($targetTables[$currentName])) $extraTables[] = $currentName;
            }
            $currentTables->free();
            if ($extraTables) {
                if ($conn->query('SET FOREIGN_KEY_CHECKS=0') === false) throw new RuntimeException('ปิด Foreign Key ชั่วคราวไม่สำเร็จ');
                $foreignKeysDisabled = true;
                foreach ($extraTables as $extraTable) {
                    $drop = 'DROP TABLE IF EXISTS '.$this->quoteIdentifier($extraTable);
                    if ($conn->query($drop) === false) throw new RuntimeException('ลบตารางส่วนเกิน '. $extraTable .' ไม่สำเร็จ: '.($conn->error ?? 'ไม่ทราบข้อผิดพลาด'));
                }
            }
            $index = 0;
            foreach ($this->iterateSqlStatements($sql) as $statement) {
                $index++;
                if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*0$/i', trim($statement))) $foreignKeysDisabled = true;
                $result = $conn->query($statement);
                if ($result === false) throw new RuntimeException('Restore ฐานข้อมูลผิดพลาดที่คำสั่งลำดับ '.($index + 1).': '.($conn->error ?? 'ไม่ทราบข้อผิดพลาด'));
                if (is_object($result) && method_exists($result, 'free')) $result->free();
                if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*1$/i', trim($statement))) $foreignKeysDisabled = false;
            }
        } finally {
            if ($foreignKeysDisabled) @$conn->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function restoreWebsiteFiles($zip, $preserveFiles = []) {
        $rootReal = realpath($this->root);
        if (!$rootReal) throw new RuntimeException('ไม่พบ root เว็บไซต์');
        $restored = 0;
        $preserveMap = array_fill_keys(array_map(function($path) { return str_replace('\\', '/', ltrim((string)$path, '/')); }, (array)$preserveFiles), true);
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $entry = (string)$stat['name'];
            if (strpos($entry, 'website/') !== 0 || substr($entry, -1) === '/') continue;
            if (!$this->validateRelativePath($entry)) throw new RuntimeException('Path ภายใน ZIP ไม่ปลอดภัย');
            $relative = substr($entry, 8);
            if ($this->isExcludedPath($relative) || isset($preserveMap[$relative])) continue;
            $mode = 0;
            $opsys = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $mode) && $opsys === ZipArchive::OPSYS_UNIX) {
                $fileType = ($mode >> 16) & 0170000;
                if ($fileType === 0120000) throw new RuntimeException('ไม่อนุญาตให้ Restore symbolic link');
            }
            $destination = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($destination);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) throw new RuntimeException('สร้างโฟลเดอร์ปลายทางไม่สำเร็จ: ' . dirname($relative));
            $parentReal = realpath($parent);
            if ($parentReal === false || ($parentReal !== $rootReal && strpos($parentReal, $rootReal . DIRECTORY_SEPARATOR) !== 0)) throw new RuntimeException('ปลายทาง Restore อยู่นอกเว็บไซต์');
            if (is_link($destination) || (file_exists($destination) && !is_file($destination))) throw new RuntimeException('ปลายทางไม่ใช่ไฟล์ปกติ: ' . $relative);
            $input = $zip->getStream($entry);
            if (!$input) throw new RuntimeException('อ่านไฟล์จาก ZIP ไม่สำเร็จ: ' . $relative);
            $tempDestination = @tempnam($parent, '.avsf-restore-');
            if ($tempDestination === false) { fclose($input); throw new RuntimeException('สร้างไฟล์ชั่วคราวสำหรับ Restore ไม่สำเร็จ: ' . $relative); }
            $output = @fopen($tempDestination, 'wb');
            if (!$output) { fclose($input); @unlink($tempDestination); throw new RuntimeException('เขียนไฟล์ชั่วคราวไม่สำเร็จ: ' . $relative); }
            $copied = stream_copy_to_stream($input, $output);
            fclose($input); fclose($output);
            if ($copied === false || $copied !== (int)$stat['size']) { @unlink($tempDestination); throw new RuntimeException('เขียนไฟล์ไม่ครบ: ' . $relative); }
            @chmod($tempDestination, 0644);
            if (!@rename($tempDestination, $destination)) { @unlink($tempDestination); throw new RuntimeException('แทนที่ไฟล์ปลายทางไม่สำเร็จ: ' . $relative); }
            $restored++;
        }
        return $restored;
    }

    private function removeFilesAddedByFailedRestore($targetZip, $safetyZip) {
        $safetyNames = [];
        for ($i = 0; $i < $safetyZip->numFiles; $i++) {
            $stat = $safetyZip->statIndex($i);
            if (!$stat) continue;
            $name = (string)$stat['name'];
            if (strpos($name, 'website/') === 0 && substr($name, -1) !== '/') $safetyNames[$name] = true;
        }
        $rootReal = realpath($this->root);
        if (!$rootReal) throw new RuntimeException('ไม่พบ root เว็บไซต์ระหว่าง Rollback');
        $removed = 0;
        for ($i = 0; $i < $targetZip->numFiles; $i++) {
            $stat = $targetZip->statIndex($i);
            if (!$stat) continue;
            $entry = (string)$stat['name'];
            if (strpos($entry, 'website/') !== 0 || substr($entry, -1) === '/' || isset($safetyNames[$entry])) continue;
            if (!$this->validateRelativePath($entry)) continue;
            $relative = substr($entry, 8);
            if ($this->isExcludedPath($relative)) continue;
            $destination = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!file_exists($destination) || is_link($destination) || !is_file($destination)) continue;
            $parentReal = realpath(dirname($destination));
            if ($parentReal === false || ($parentReal !== $rootReal && strpos($parentReal, $rootReal . DIRECTORY_SEPARATOR) !== 0)) continue;
            if (@unlink($destination)) $removed++;
        }
        return $removed;
    }

    public function restoreBackup($path, $conn, $restoreFiles, $restoreDatabase, $preserveFiles = []) {
        @set_time_limit(0);
        if (!$restoreFiles && !$restoreDatabase) throw new RuntimeException('กรุณาเลือกส่วนที่ต้องการ Restore');
        $manifest = $this->inspectArchive($path);
        if ($restoreDatabase && empty($manifest['database']['included'])) throw new RuntimeException('Backup นี้ไม่มีฐานข้อมูล จึง Restore ฐานข้อมูลไม่ได้');
        if ($restoreDatabase && (!is_object($conn) || !method_exists($conn, 'query'))) throw new RuntimeException('ไม่พบการเชื่อมต่อฐานข้อมูล');
        // Create a matching safety point before changing live data. File-only restores do not require DB export.
        $safetyWithDb = false;
        if ($restoreDatabase) {
            $tableProbe = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            if (!$tableProbe) throw new RuntimeException('ตรวจสอบตารางฐานข้อมูลปลายทางก่อน Restore ไม่สำเร็จ');
            $safetyWithDb = $tableProbe->num_rows > 0;
            $tableProbe->free();
        }
        $safety = $this->createBackup($conn, $safetyWithDb, true);
        $targetZip = new ZipArchive();
        if ($targetZip->open($path, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('เปิด ZIP สำหรับ Restore ไม่สำเร็จ · Safety Backup ก่อนดำเนินการ: '.$safety['name']);
        $attemptedDb = false; $attemptedFiles = false;
        try {
            if ($restoreDatabase) { $attemptedDb = true; $this->restoreDatabase($targetZip, $conn, $manifest); }
            $restoredFiles = 0;
            if ($restoreFiles) { $attemptedFiles = true; $restoredFiles = $this->restoreWebsiteFiles($targetZip, $preserveFiles); }
            $restoredHistory = $this->restoreBackupHistory($targetZip);
            $restoredSettingsHistory = $this->restoreSettingsBackupHistory($targetZip);
            $targetZip->close();
            return ['files'=>$restoredFiles,'database'=>$restoreDatabase,'backup_history'=>count($restoredHistory),'settings_backup_history'=>count($restoredSettingsHistory),'safety_backup'=>$safety['name']];
        } catch (Throwable $restoreError) {
            $rollbackNotes = [];
            try {
                $safetyPath = $this->findStoredBackup($safety['name']);
                if ($safetyPath) {
                    $safetyManifest = $this->inspectArchive($safetyPath);
                    $safetyZip = new ZipArchive();
                    if ($safetyZip->open($safetyPath, ZipArchive::CHECKCONS) === true) {
                        try {
                            if ($attemptedFiles) {
                                $removed = $this->removeFilesAddedByFailedRestore($targetZip, $safetyZip);
                                $restored = $this->restoreWebsiteFiles($safetyZip);
                                $rollbackNotes[] = 'ย้อนคืนไฟล์เดิมแล้ว '.$restored.' ไฟล์ (ลบไฟล์ใหม่ที่เพิ่งถูกเพิ่ม '.$removed.' ไฟล์)';
                            }
                            if ($attemptedDb) {
                                if (!empty($safetyManifest['database']['included'])) {
                                    $this->restoreDatabase($safetyZip, $conn, $safetyManifest);
                                    $rollbackNotes[] = 'ย้อนคืนฐานข้อมูลจาก Safety Backup แล้ว';
                                } else {
                                    $current = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                                    if (!$current) throw new RuntimeException('ตรวจสอบตารางหลัง Restore ผิดพลาดไม่สำเร็จ');
                                    $dropNames = [];
                                    while ($row = $current->fetch_row()) $dropNames[] = (string)$row[0];
                                    $current->free();
                                    $conn->query('SET FOREIGN_KEY_CHECKS=0');
                                    foreach ($dropNames as $dropName) $conn->query('DROP TABLE IF EXISTS '.$this->quoteIdentifier($dropName));
                                    $conn->query('SET FOREIGN_KEY_CHECKS=1');
                                    $rollbackNotes[] = 'ล้างตารางที่ Restore ค้างไว้ เนื่องจากฐานข้อมูลก่อนเริ่มว่างเปล่า';
                                }
                            }
                        } catch (Throwable $rollbackError) {
                            $rollbackNotes[] = 'การย้อนคืนอัตโนมัติไม่สมบูรณ์: '.$rollbackError->getMessage();
                        } finally { $safetyZip->close(); }
                    } else { $rollbackNotes[] = 'เปิด Safety Backup เพื่อย้อนคืนไม่สำเร็จ'; }
                } else { $rollbackNotes[] = 'ไม่พบ Safety Backup'; }
            } catch (Throwable $safetyError) {
                $rollbackNotes[] = 'ไม่สามารถทำการย้อนคืนอัตโนมัติ: '.$safetyError->getMessage();
            } finally { $targetZip->close(); }
            throw new RuntimeException('Restore ไม่สำเร็จ: '.$restoreError->getMessage().' · Safety Backup: '.$safety['name'].' · '.implode(' · ',$rollbackNotes), 0, $restoreError);
        }
    }
}
}
