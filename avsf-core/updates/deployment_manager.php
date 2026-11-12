<?php
/**
 * AVSF in-admin deployment manager.
 * File-only deployments with archive validation, staged preview, private backups and rollback.
 * Database tables, runtime configuration, uploads and rewrite rules are never modified.
 */
if (!class_exists('AVSFDeploymentManager')) {
class AVSFDeploymentManager {
    private $root;
    private $privateRoot;
    private $maxEntries = 12000;
    private $maxFileBytes = 104857600; // 100 MB
    private $maxTotalBytes = 629145600; // 600 MB

    public function __construct($siteRoot) {
        $root = realpath($siteRoot);
        if ($root === false || !is_dir($root)) throw new RuntimeException('ไม่พบโฟลเดอร์เว็บไซต์');
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);

        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $candidates = [];
        if ($docRoot !== false) $candidates[] = dirname($docRoot);
        $candidates[] = sys_get_temp_dir();
        $candidates[] = dirname($this->root);
        $privateBase = false;
        foreach ($candidates as $candidate) {
            $candidate = realpath($candidate);
            if ($candidate === false || !is_dir($candidate) || !is_writable($candidate)) continue;
            if ($docRoot !== false && $this->isWithin($candidate, $docRoot)) continue;
            if ($this->isWithin($candidate, $this->root)) continue;
            $privateBase = $candidate;
            break;
        }
        if ($privateBase === false) throw new RuntimeException('ไม่พบพื้นที่จัดเก็บ Update/Backup ที่เขียนได้และอยู่นอกเว็บรูท');

        $slug = substr(hash('sha256', $this->root), 0, 14);
        $this->privateRoot = $privateBase . DIRECTORY_SEPARATOR . '.avsf-deployments-' . $slug;
        if (is_link($this->privateRoot)) throw new RuntimeException('พื้นที่ Update ส่วนตัวชี้ผ่าน Symbolic Link จึงปิดการใช้งานเพื่อความปลอดภัย');
        if (!is_dir($this->privateRoot) && !@mkdir($this->privateRoot, 0700, true)) throw new RuntimeException('สร้างพื้นที่ Update ส่วนตัวไม่สำเร็จ');
        @chmod($this->privateRoot, 0700);
        foreach (['staging','deployments'] as $folder) {
            $path = $this->privateRoot . DIRECTORY_SEPARATOR . $folder;
            if (is_link($path)) throw new RuntimeException('โฟลเดอร์ระบบ Update มี Symbolic Link ที่ไม่อนุญาต');
            if (!is_dir($path) && !@mkdir($path, 0700, true)) throw new RuntimeException('สร้างโฟลเดอร์ระบบ Update ไม่สำเร็จ');
            @chmod($path, 0700);
        }
        foreach (['index.php'=>"<?php http_response_code(404); exit;\n",'.htaccess'=>"Require all denied\nDeny from all\n"] as $guardName=>$guardContent) {
            $guardPath = $this->privateRoot . DIRECTORY_SEPARATOR . $guardName;
            if (is_link($guardPath)) throw new RuntimeException('พบไฟล์ Symbolic Link ในพื้นที่ Update ส่วนตัว');
            if (!is_file($guardPath)) @file_put_contents($guardPath, $guardContent, LOCK_EX);
        }
        $this->cleanupExpiredStages();
    }

    private function isWithin($path, $base) {
        $path = rtrim(str_replace('\\', '/', (string)$path), '/');
        $base = rtrim(str_replace('\\', '/', (string)$base), '/');
        return $path === $base || strpos($path . '/', $base . '/') === 0;
    }

    private function currentVersion() {
        $readme = $this->root . DIRECTORY_SEPARATOR . 'README.md';
        if (is_file($readme) && is_readable($readme)) {
            $content = (string)file_get_contents($readme);
            if (preg_match('/^\*\*Version:\*\*\s*([0-9]+(?:\.[0-9]+){1,3})/mi', $content, $match)) return $match[1];
        }
        return '0.0.0';
    }

    private function randomId() { return date('Ymd-His') . '-' . bin2hex(random_bytes(6)); }

    private function safeId($id) {
        return is_string($id) && preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $id);
    }

    private function stagePath($id) {
        if (!$this->safeId($id)) throw new RuntimeException('รหัสงานอัปเดตไม่ถูกต้อง');
        return $this->privateRoot . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $id;
    }

    private function deploymentPath($id) {
        if (!$this->safeId($id)) throw new RuntimeException('รหัส Deployment ไม่ถูกต้อง');
        return $this->privateRoot . DIRECTORY_SEPARATOR . 'deployments' . DIRECTORY_SEPARATOR . $id;
    }

    private function saveJson($path, $data) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('บันทึกข้อมูลสถานะ Update ไม่สำเร็จ');
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('เขียนไฟล์สถานะ Update ไม่สำเร็จ');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('สลับไฟล์สถานะ Update ไม่สำเร็จ'); }
    }

    private function loadJson($path) {
        if (!is_file($path) || is_link($path) || !is_readable($path)) return false;
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : false;
    }

    private function protectedReason($relative) {
        $relative = str_replace('\\', '/', trim((string)$relative, '/'));
        $parts = explode('/', $relative);
        $first = strtolower($parts[0] ?? '');
        $blockedDirs = ['uploads','cache','quarantine','backups','backup','logs','log','tmp','temp','sessions','session','storage','data','avsf-storage','avsf-updates','.git','node_modules'];
        if (in_array($first, $blockedDirs, true)) return 'โฟลเดอร์ข้อมูลเว็บไซต์ — ไม่แทนที่';
        $base = strtolower(basename($relative));
        $blockedFiles = ['config.php','config.local.php','.env','.env.local','.user.ini','.htaccess','web.config','wp-config.php','auth.json','credentials.json','avsf-update-activate.php','robots.txt'];
        if (in_array($base, $blockedFiles, true)) return 'ไฟล์ตั้งค่า/กฎเซิร์ฟเวอร์ — เก็บค่าเดิม';
        if (preg_match('/\.(?:pem|key|p12|pfx|log)$/i', $base)) return 'ไฟล์ลับหรือ Log — ไม่แทนที่';
        if (preg_match('/\.(?:exe|dll|so|bat|cmd|sh|ps1)$/i', $base)) return 'ไม่อนุญาตไฟล์ executable ชนิดนี้';
        return '';
    }

    private function isPathLocked($absolutePath) {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avsf-file-locks-' . substr(hash('sha256', $this->root), 0, 16) . '.json';
        if (!is_file($lockFile)) return false;
        $locks = json_decode((string)@file_get_contents($lockFile), true);
        if (!is_array($locks)) return false;
        $target = rtrim(str_replace('\\', '/', $absolutePath), '/');
        foreach ($locks as $locked => $enabled) {
            if (!$enabled) continue;
            $locked = rtrim(str_replace('\\', '/', (string)$locked), '/');
            if ($target === $locked || strpos($target, $locked . '/') === 0) return true;
            if (strpos($locked, $target . '/') === 0) return true;
        }
        return false;
    }

    private function validateArchiveName($name) {
        if (!is_string($name) || $name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false || strpos($name, ':') !== false || preg_match('/[\x00-\x1f]/', $name)) return false;
        if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) return false;
        $trimmed = rtrim($name, '/');
        if ($trimmed === '') return false;
        foreach (explode('/', $trimmed) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
        return true;
    }

    private function isBootstrapArtifact($path) {
        return in_array(strtolower((string)$path), ['avsf-update-activate.php', '.htaccess', 'avsf-update-manifest.json'], true);
    }

    private function determinePrefix($names, $hasManifest) {
        $names = array_values(array_filter($names, function($name) { return !$this->isBootstrapArtifact($name); }));
        if (!$names) throw new RuntimeException('ZIP ไม่มีไฟล์ระบบที่ใช้ติดตั้ง');
        $allWebsite = true;
        foreach ($names as $name) if (strpos($name, 'website/') !== 0) { $allWebsite = false; break; }
        if ($allWebsite) return 'website/';

        $top = [];
        foreach ($names as $name) {
            $parts = explode('/', $name);
            $top[$parts[0]] = true;
        }
        if (count($top) === 1) {
            $only = array_key_first($top);
            $nestedWebsite = true;
            foreach ($names as $name) if (strpos($name, $only . '/website/') !== 0) { $nestedWebsite = false; break; }
            if ($nestedWebsite) return $only . '/website/';
            $nestedRoot = true;
            foreach (['index.php','config.php','avsf-core/bootstrap.php','avsf-admin/index.php','avsf-frontend/index.php'] as $required) if (!in_array($only . '/' . $required, $names, true)) $nestedRoot = false;
            if ($nestedRoot) return $only . '/';
        }
        return '';
    }

    private function safeMakeDir($path, $mode = 0755) {
        if (is_dir($path)) return true;
        if (file_exists($path) || is_link($path)) return false;
        return @mkdir($path, $mode, true) || is_dir($path);
    }

    private function extractValidatedArchive($zip, $entries, $payloadRoot) {
        if (!$this->safeMakeDir($payloadRoot, 0700)) throw new RuntimeException('สร้างพื้นที่แตก ZIP ไม่สำเร็จ');
        $payloadReal = realpath($payloadRoot);
        if ($payloadReal === false) throw new RuntimeException('ตรวจสอบพื้นที่แตก ZIP ไม่สำเร็จ');
        $total = 0; $files = 0;
        foreach ($entries as $entry) {
            if ($entry['directory'] || $entry['relative'] === '') continue;
            $total += (int)$entry['size'];
            if ($total > $this->maxTotalBytes) throw new RuntimeException('ขนาด ZIP หลังแตกเกิน 600 MB');
            $target = $payloadReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['relative']);
            $parent = dirname($target);
            if (!$this->safeMakeDir($parent, 0755)) throw new RuntimeException('สร้างโฟลเดอร์ staging ไม่สำเร็จ');
            $parentReal = realpath($parent);
            if ($parentReal === false || !$this->isWithin($parentReal, $payloadReal)) throw new RuntimeException('เส้นทางไฟล์ใน ZIP ออกนอกพื้นที่ staging');
            if (file_exists($target) || is_link($target)) throw new RuntimeException('พบไฟล์ชื่อซ้ำใน ZIP: ' . $entry['relative']);
            $stream = $zip->getStream($entry['archive_name']);
            if (!$stream) throw new RuntimeException('อ่านไฟล์ ZIP ไม่สำเร็จ: ' . $entry['relative']);
            $out = @fopen($target, 'xb');
            if (!$out) { fclose($stream); throw new RuntimeException('สร้างไฟล์ staging ไม่สำเร็จ: ' . $entry['relative']); }
            $written = 0; $hash = hash_init('sha256');
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 1048576);
                    if ($chunk === false) throw new RuntimeException('อ่านข้อมูล ZIP ไม่ครบ');
                    if ($chunk === '') continue;
                    $written += strlen($chunk);
                    if ($written > (int)$entry['size'] || $written > $this->maxFileBytes) throw new RuntimeException('ขนาดไฟล์แตกจาก ZIP ผิดปกติ');
                    hash_update($hash, $chunk);
                    $offset = 0; $length = strlen($chunk);
                    while ($offset < $length) {
                        $n = fwrite($out, substr($chunk, $offset));
                        if ($n === false || $n === 0) throw new RuntimeException('เขียนไฟล์ staging ไม่ครบ');
                        $offset += $n;
                    }
                }
                if ($written !== (int)$entry['size']) throw new RuntimeException('ขนาดไฟล์หลังแตกไม่ตรงกับ ZIP');
                if (!fflush($out)) throw new RuntimeException('บันทึกไฟล์ staging ไม่สมบูรณ์');
            } finally { fclose($stream); fclose($out); }
            @chmod($target, 0644);
            $entry['sha256'] = hash_final($hash);
            $entry['stage_path'] = $target;
            $files++;
            $entries[$entry['slot']] = $entry;
        }
        return ['files'=>$files,'bytes'=>$total,'entries'=>$entries];
    }

    public function previewUpload($tmpPath, $originalName, $uploadSize = null) {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Hosting ยังไม่มี PHP ZipArchive');
        if (!is_file($tmpPath) || !is_readable($tmpPath)) throw new RuntimeException('ไม่พบไฟล์ ZIP ที่อัปโหลด');
        $size = $uploadSize !== null ? (int)$uploadSize : (int)filesize($tmpPath);
        if ($size < 22 || $size > 128 * 1024 * 1024) throw new RuntimeException('ZIP ต้องมีขนาดไม่เกิน 128 MB ต่อไฟล์');
        if (strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('กรุณาเลือกไฟล์ .zip เท่านั้น');

        $id = $this->randomId();
        $stageDir = $this->stagePath($id);
        if (!@mkdir($stageDir, 0700, true)) throw new RuntimeException('สร้างพื้นที่เตรียม Update ไม่สำเร็จ');
        @chmod($stageDir, 0700);
        $archivePath = $stageDir . DIRECTORY_SEPARATOR . 'package.zip';
        if (!@copy($tmpPath, $archivePath)) { avsfSafeRemoveTree($stageDir); throw new RuntimeException('คัดลอก ZIP เข้าพื้นที่ส่วนตัวไม่สำเร็จ'); }
        @chmod($archivePath, 0600);

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) { avsfSafeRemoveTree($stageDir); throw new RuntimeException('ZIP เสียหายหรือเปิดไม่ได้'); }
        try {
            $count = (int)$zip->numFiles;
            if ($count < 1 || $count > $this->maxEntries) throw new RuntimeException('จำนวนรายการใน ZIP ต้องไม่เกิน ' . $this->maxEntries);
            $rawEntries = []; $names = []; $total = 0; $manifestRaw = false;
            for ($i=0; $i<$count; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !isset($stat['name'])) throw new RuntimeException('อ่านรายการ ZIP ไม่สำเร็จ');
                $raw = (string)$stat['name'];
                if (!$this->validateArchiveName($raw)) throw new RuntimeException('พบ Path ที่ไม่ปลอดภัยใน ZIP');
                $directory = substr($raw, -1) === '/';
                $relative = rtrim($raw, '/');
                if (strpos($relative, '__MACOSX/') === 0 || basename($relative) === '.DS_Store' || strpos($relative, '/._') !== false || strpos($relative, '._') === 0) continue;
                if ($relative === 'avsf-UPDATE-MANIFEST.json') {
                    if ($directory) throw new RuntimeException('Manifest มีชนิดไฟล์ไม่ถูกต้อง');
                    $manifestRaw = $zip->getFromIndex($i);
                    continue;
                }
                $lower = strtolower($relative);
                if (isset($names[$lower])) throw new RuntimeException('พบชื่อไฟล์ซ้ำหรือชนกันเมื่อไม่แยกตัวพิมพ์เล็ก/ใหญ่: ' . $relative);
                $names[$lower] = true;
                $sizeEntry = $directory ? 0 : (int)($stat['size'] ?? 0);
                $compressed = max(0, (int)($stat['comp_size'] ?? 0));
                if ($sizeEntry < 0 || $sizeEntry > $this->maxFileBytes) throw new RuntimeException('ไฟล์ใน ZIP ใหญ่เกิน 100 MB: ' . basename($relative));
                $total += $sizeEntry;
                if ($total > $this->maxTotalBytes) throw new RuntimeException('ขนาดรวมหลังแตก ZIP เกิน 600 MB');
                if ($sizeEntry > 1024 * 1024 && $compressed > 0 && ($sizeEntry / $compressed) > 200) throw new RuntimeException('พบ ZIP compression ratio ผิดปกติ');
                if (method_exists($zip, 'getExternalAttributesIndex')) {
                    $opsys = 0; $attr = 0;
                    if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && defined('ZipArchive::OPSYS_UNIX') && $opsys === ZipArchive::OPSYS_UNIX) {
                        $unixMode = ($attr >> 16) & 0170000;
                        $allowedMode = $directory ? 0040000 : 0100000;
                        if ($unixMode !== 0 && $unixMode !== $allowedMode) {
                            throw new RuntimeException('ไม่อนุญาตไฟล์ชนิดพิเศษหรือ Symbolic Link ภายใน Update ZIP: ' . basename($relative));
                        }
                    }
                }
                $rawEntries[] = ['index'=>$i,'archive_name'=>$raw,'raw_relative'=>$relative,'size'=>$sizeEntry,'directory'=>$directory];
            }

            $fileNames = [];
            foreach ($rawEntries as $entry) if (!$entry['directory']) $fileNames[] = $entry['raw_relative'];
            $prefix = $this->determinePrefix($fileNames, is_string($manifestRaw));
            $mapped = []; $mappedNames = [];
            foreach ($rawEntries as $entry) {
                $raw = $entry['raw_relative'];
                if ($this->isBootstrapArtifact($raw)) continue;
                if ($prefix !== '') {
                    if (strpos($raw, $prefix) !== 0) {
                        if (!$entry['directory']) throw new RuntimeException('พบไฟล์อยู่นอกโฟลเดอร์เว็บไซต์ใน ZIP: ' . basename($raw));
                        continue;
                    }
                    $relative = substr($raw, strlen($prefix));
                } else $relative = $raw;
                $relative = trim(str_replace('\\', '/', $relative), '/');
                if ($relative === '') continue;
                if (!$this->validateArchiveName($relative)) throw new RuntimeException('Path หลังปรับโครงสร้าง ZIP ไม่ถูกต้อง');
                if ($entry['directory']) continue;
                $lower = strtolower($relative);
                if (isset($mappedNames[$lower])) throw new RuntimeException('ชื่อไฟล์ซ้ำหลังตัดโฟลเดอร์แพ็กเกจ: ' . $relative);
                $mappedNames[$lower] = true;
                $mapped[] = ['slot'=>count($mapped),'archive_name'=>$entry['archive_name'],'relative'=>$relative,'size'=>$entry['size'],'directory'=>false];
            }

            $manifest = null;
            if (is_string($manifestRaw) && $manifestRaw !== '') {
                $manifest = json_decode($manifestRaw, true);
                if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'AVSF_UPDATE' || (int)($manifest['format_version'] ?? 0) !== 1) throw new RuntimeException('AVSF Update Manifest ไม่ถูกต้อง');
                if (empty($manifest['version']) || !is_string($manifest['version']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,40}$/', $manifest['version'])) throw new RuntimeException('เวอร์ชันใน Update Manifest ไม่ถูกต้อง');
                $currentVersion = $this->currentVersion();
                if (!empty($manifest['min_version']) && version_compare($currentVersion, (string)$manifest['min_version'], '<')) throw new RuntimeException('แพ็กเกจนี้ต้องใช้ AVSF อย่างน้อยเวอร์ชัน ' . $manifest['min_version'] . ' (เว็บปัจจุบัน ' . $currentVersion . ')');
                if (version_compare((string)$manifest['version'], $currentVersion, '<')) throw new RuntimeException('แพ็กเกจเวอร์ชันต่ำกว่าเว็บไซต์ปัจจุบัน กรุณาใช้ Rollback จากประวัติ Update แทน');
                $manifestFiles = $manifest['files'] ?? null;
                if (!is_array($manifestFiles)) throw new RuntimeException('Manifest ไม่มีรายการ SHA-256');
                $actual = [];
                foreach ($mapped as $entry) $actual[$entry['relative']] = true;
                if (count($actual) !== count($manifestFiles)) throw new RuntimeException('จำนวนไฟล์ใน Manifest ไม่ตรงกับ ZIP');
                foreach ($manifestFiles as $path=>$hash) {
                    if (!isset($actual[$path]) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) throw new RuntimeException('รายการ Hash ใน Manifest ไม่ถูกต้อง');
                }
            } else {
                $legacyMarkers = ['config.php','index.php','avsf-core/bootstrap.php','avsf-admin/index.php','avsf-admin/modules/file-manager.php'];
                foreach ($legacyMarkers as $marker) if (!isset($mappedNames[strtolower($marker)])) throw new RuntimeException('ZIP นี้ไม่ใช่แพ็กเกจ AVSF ที่รู้จัก หรือไม่มี Update Manifest');
            }

            $payloadRoot = $stageDir . DIRECTORY_SEPARATOR . 'payload';
            $extracted = $this->extractValidatedArchive($zip, $mapped, $payloadRoot);
            $mapped = $extracted['entries'];
            $verified = 0;
            foreach ($mapped as $entry) {
                if (strtolower(pathinfo($entry['relative'], PATHINFO_EXTENSION)) === 'php') {
                    $source = file_get_contents($entry['stage_path']);
                    if ($source === false) throw new RuntimeException('อ่านไฟล์ PHP ใน Update ไม่สำเร็จ');
                    try { token_get_all($source, TOKEN_PARSE); } catch (ParseError $parseError) { throw new RuntimeException('PHP Syntax ไม่ผ่าน: ' . $entry['relative'] . ' — ' . $parseError->getMessage()); }
                }
                if ($manifest !== null) {
                    $expected = strtolower((string)$manifest['files'][$entry['relative']]);
                    if (!hash_equals($expected, strtolower($entry['sha256']))) throw new RuntimeException('SHA-256 ไม่ตรงกับ Manifest: ' . $entry['relative']);
                    $verified++;
                }
            }
            if ($manifest !== null && $verified !== count($mapped)) throw new RuntimeException('ตรวจสอบ Hash ของ Update ไม่ครบ');
            $hasSystemMarkers = isset($mappedNames['config.php']) && isset($mappedNames['index.php']) && isset($mappedNames['avsf-core/bootstrap.php']) && isset($mappedNames['avsf-admin/index.php']) && isset($mappedNames['avsf-admin/modules/file-manager.php']);
            if (!$hasSystemMarkers && $manifest === null) throw new RuntimeException('แพ็กเกจไม่พบไฟล์หลักของ AVSF');

            $fileRows = []; $counts = ['new'=>0,'replace'=>0,'skip'=>0,'locked'=>0]; $previewBytes = 0;
            foreach ($mapped as $entry) {
                $relative = $entry['relative'];
                $reason = $this->protectedReason($relative);
                $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($reason === '' && $this->isPathLocked($target)) $reason = 'ไฟล์ถูกล็อกใน File Manager';
                $currentHash = null; $exists = file_exists($target) || is_link($target);
                if ($reason === '' && $exists) {
                    if (is_link($target) || !is_file($target)) throw new RuntimeException('ปลายทางเป็น Symbolic Link หรือไม่ใช่ไฟล์ปกติ: ' . $relative);
                    $currentHash = hash_file('sha256', $target);
                }
                if ($reason !== '') {
                    $counts['skip']++;
                    if (strpos($reason, 'ล็อก') !== false) $counts['locked']++;
                    $status = 'skip';
                } elseif ($exists) { $counts['replace']++; $status = 'replace'; $previewBytes += (int)$entry['size']; }
                else { $counts['new']++; $status = 'new'; $previewBytes += (int)$entry['size']; }
                $fileRows[] = ['path'=>$relative,'size'=>(int)$entry['size'],'sha256'=>$entry['sha256'],'status'=>$status,'reason'=>$reason,'current_hash'=>$currentHash];
            }
            if ($counts['new'] + $counts['replace'] < 1) throw new RuntimeException('ไม่มีไฟล์ที่สามารถอัปเดตได้ (ไฟล์ทั้งหมดถูกป้องกันหรือถูกล็อก)');

            $stageMeta = [
                'id'=>$id,'created_at'=>date('c'),'original_name'=>basename((string)$originalName),
                'archive_size'=>$size,'package_version'=>$manifest['version'] ?? 'ไม่ระบุ (Legacy ZIP)',
                'current_version'=>$this->currentVersion(),'min_version'=>$manifest['min_version'] ?? null,'manifest_verified'=>$manifest !== null,
                'manifest_files_verified'=>$verified,'prefix'=>$prefix,'counts'=>$counts,
                'files'=>$fileRows,'manifest'=>$manifest,'payload_dir'=>$payloadRoot,'status'=>'ready'
            ];
            $this->saveJson($stageDir . DIRECTORY_SEPARATOR . 'stage.json', $stageMeta);
            if (!$zip->close()) throw new RuntimeException('ปิดไฟล์ ZIP หลังตรวจสอบไม่สำเร็จ');
            return $stageMeta;
        } catch (Throwable $error) {
            $zip->close();
            avsfSafeRemoveTree($stageDir);
            throw $error;
        }
        $zip->close();
    }

    public function getPreview($id) {
        $stage = $this->stagePath($id);
        $meta = $this->loadJson($stage . DIRECTORY_SEPARATOR . 'stage.json');
        if (!$meta || ($meta['status'] ?? '') !== 'ready') return false;
        return $meta;
    }

    public function discardPreview($id) {
        $stage = $this->stagePath($id);
        if (!is_dir($stage) || is_link($stage)) return false;
        avsfSafeRemoveTree($stage);
        return !file_exists($stage);
    }

    private function ensureTargetParent($relative, &$createdDirs) {
        $parts = explode('/', $relative);
        array_pop($parts);
        $cursor = $this->root;
        foreach ($parts as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) throw new RuntimeException('พบ Symbolic Link ในโฟลเดอร์ปลายทาง: ' . $part);
            if (file_exists($cursor) && !is_dir($cursor)) throw new RuntimeException('มีไฟล์ขวางเส้นทางโฟลเดอร์: ' . $part);
            if (!is_dir($cursor)) {
                if (!@mkdir($cursor, 0755)) throw new RuntimeException('สร้างโฟลเดอร์สำหรับติดตั้งไม่สำเร็จ: ' . $part);
                $createdDirs[] = $cursor;
            }
            $real = realpath($cursor);
            if ($real === false || !$this->isWithin($real, $this->root)) throw new RuntimeException('โฟลเดอร์ปลายทางออกนอกเว็บรูท');
        }
        return $cursor;
    }

    private function atomicReplace($source, $target, $mode) {
        $temp = dirname($target) . DIRECTORY_SEPARATOR . '.avsf-deploy-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!@copy($source, $temp)) throw new RuntimeException('คัดลอกไฟล์ Update ไปยังพื้นที่ติดตั้งไม่สำเร็จ: ' . basename($target));
        @chmod($temp, $mode & 0755 ? ($mode & 0755) : 0644);
        if (DIRECTORY_SEPARATOR === '\\' && file_exists($target)) {
            $oldSwap = $target . '.avsf-old-' . bin2hex(random_bytes(4));
            if (!@rename($target, $oldSwap)) { @unlink($temp); throw new RuntimeException('เตรียมแทนที่ไฟล์เดิมไม่สำเร็จ: ' . basename($target)); }
            if (!@rename($temp, $target)) { @rename($oldSwap, $target); @unlink($temp); throw new RuntimeException('แทนที่ไฟล์ไม่สำเร็จ: ' . basename($target)); }
            @unlink($oldSwap);
        } else {
            if (!@rename($temp, $target)) { @unlink($temp); throw new RuntimeException('สลับไฟล์ Update ไม่สำเร็จ: ' . basename($target)); }
        }
    }

    private function writeDeploymentMeta($dir, &$meta) {
        $this->saveJson($dir . DIRECTORY_SEPARATOR . 'deployment.json', $meta);
    }

    private function restoreEntries($deployDir, &$meta, $force = false) {
        $errors = []; $restored = 0;
        $files = $meta['files'] ?? [];
        for ($i=count($files)-1; $i>=0; $i--) {
            $record = $files[$i];
            if (($record['rollback_state'] ?? '') === 'restored') continue;
            $relative = (string)($record['path'] ?? '');
            if (!$this->validateArchiveName($relative)) { $errors[] = 'Path ไม่ถูกต้อง: ' . $relative; continue; }
            $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_link($target)) { $errors[] = 'พบ Symbolic Link จึงไม่แตะไฟล์: ' . $relative; continue; }
            $currentExists = is_file($target);
            $currentHash = $currentExists ? hash_file('sha256', $target) : null;
            if (!$force && $currentExists && !empty($record['new_hash']) && !hash_equals((string)$record['new_hash'], (string)$currentHash)) {
                $errors[] = 'ไฟล์ถูกแก้หลังอัปเดต จึงข้ามเพื่อไม่ทับการแก้ไขใหม่: ' . $relative;
                continue;
            }
            if (!empty($record['existed'])) {
                $backup = $deployDir . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_file($backup) || is_link($backup)) { $errors[] = 'ไม่พบไฟล์สำรอง: ' . $relative; continue; }
                try { $this->ensureTargetParent($relative, $meta['created_dirs']); $this->atomicReplace($backup, $target, (int)($record['mode'] ?? 0644)); }
                catch (Throwable $error) { $errors[] = $relative . ': ' . $error->getMessage(); continue; }
                if (!empty($record['mtime'])) @touch($target, (int)$record['mtime']);
                $meta['files'][$i]['rollback_state'] = 'restored';
                $restored++;
            } else {
                if ($currentExists) {
                    if (!empty($record['new_hash']) && hash_equals((string)$record['new_hash'], (string)$currentHash)) {
                        if (@unlink($target)) { $meta['files'][$i]['rollback_state'] = 'restored'; $restored++; } else $errors[] = 'ลบไฟล์ที่เพิ่มใหม่ไม่สำเร็จ: ' . $relative;
                    } elseif ($force) {
                        if (@unlink($target)) { $meta['files'][$i]['rollback_state'] = 'restored'; $restored++; } else $errors[] = 'ลบไฟล์ที่เพิ่มใหม่ไม่สำเร็จ: ' . $relative;
                    } else $errors[] = 'ไฟล์ใหม่ถูกแก้ภายหลัง จึงไม่ลบ: ' . $relative;
                } else {
                    $meta['files'][$i]['rollback_state'] = 'restored';
                }
            }
        }
        $dirs = $meta['created_dirs'] ?? [];
        for ($i=count($dirs)-1; $i>=0; $i--) {
            $dir = $dirs[$i];
            if (!is_string($dir) || is_link($dir) || !$this->isWithin($dir, $this->root) || !is_dir($dir)) continue;
            $items = @scandir($dir);
            if (is_array($items) && count($items) === 2) @rmdir($dir);
        }
        return ['restored'=>$restored,'errors'=>$errors];
    }

    public function apply($id) {
        $preview = $this->getPreview($id);
        if (!$preview) throw new RuntimeException('ไม่พบรายการ Preview หรือหมดอายุแล้ว กรุณาอัปโหลด ZIP ใหม่');
        $lockPath = $this->privateRoot . DIRECTORY_SEPARATOR . 'deployment.lock';
        if (is_link($lockPath)) throw new RuntimeException('ไฟล์ล็อก Update ไม่ปลอดภัย');
        $lock = @fopen($lockPath, 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); throw new RuntimeException('มีงาน Update อื่นกำลังทำงาน กรุณาลองอีกครั้ง'); }
        $deployId = $this->randomId();
        $deployDir = $this->deploymentPath($deployId);
        $originalDir = $deployDir . DIRECTORY_SEPARATOR . 'original';
        if (!@mkdir($deployDir, 0700, true) || !@mkdir($originalDir, 0700, true)) { flock($lock, LOCK_UN); fclose($lock); throw new RuntimeException('สร้างพื้นที่สำรองก่อนอัปเดตไม่สำเร็จ'); }
        @chmod($deployDir, 0700); @chmod($originalDir, 0700);
        $meta = [
            'id'=>$deployId,'source_stage_id'=>$id,'created_at'=>date('c'),'package_name'=>$preview['original_name'],
            'package_version'=>$preview['package_version'],'status'=>'applying','files'=>[],'created_dirs'=>[],
            'counts'=>$preview['counts'],'manifest_verified'=>$preview['manifest_verified']
        ];
        $metaPath = $deployDir . DIRECTORY_SEPARATOR . 'deployment.json';
        $this->writeDeploymentMeta($deployDir, $meta);
        try {
            $stageDir = $this->stagePath($id);
            $payload = $stageDir . DIRECTORY_SEPARATOR . 'payload';
            $backupBytes = 0;
            foreach ($preview['files'] as $row) if ($row['status'] === 'replace') {
                $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['path']);
                if (is_file($target)) $backupBytes += (int)filesize($target);
            }
            $free = @disk_free_space($this->privateRoot);
            if (is_numeric($free) && $free < $backupBytes + 8 * 1024 * 1024) throw new RuntimeException('พื้นที่ดิสก์สำหรับเก็บไฟล์เดิมไม่เพียงพอ จึงหยุดก่อนเขียนทับ');

            foreach ($preview['files'] as $row) {
                if ($row['status'] === 'skip') continue;
                $relative = $row['path'];
                $reason = $this->protectedReason($relative);
                if ($reason !== '') throw new RuntimeException('ไฟล์ถูกป้องกันหลัง Preview: ' . $relative);
                $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($this->isPathLocked($target)) throw new RuntimeException('ไฟล์ถูกล็อกหลัง Preview: ' . $relative);
                $exists = file_exists($target) || is_link($target);
                if ($exists && (is_link($target) || !is_file($target))) throw new RuntimeException('ปลายทางไม่ใช่ไฟล์ปกติ: ' . $relative);
                $nowHash = $exists ? hash_file('sha256', $target) : null;
                if ($exists !== ($row['status'] === 'replace') || ($exists && !hash_equals((string)$row['current_hash'], (string)$nowHash))) throw new RuntimeException('ไฟล์เปลี่ยนไปหลัง Preview จึงยกเลิกเพื่อป้องกันการทับข้อมูล: ' . $relative);
                $stageFile = $payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_file($stageFile) || is_link($stageFile) || !hash_equals((string)$row['sha256'], (string)hash_file('sha256', $stageFile))) throw new RuntimeException('ไฟล์ staging ไม่ตรงกับข้อมูล Preview: ' . $relative);
                $this->ensureTargetParent($relative, $meta['created_dirs']);
                $this->writeDeploymentMeta($deployDir, $meta);
                $backupRelative = 'original' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $record = ['path'=>$relative,'existed'=>$exists,'backup_relative'=>$exists ? $backupRelative : null,'mode'=>$exists ? (fileperms($target) & 0777) : 0644,'mtime'=>$exists ? filemtime($target) : null,'new_hash'=>$row['sha256'],'state'=>'pending'];
                if ($exists) {
                    $backupPath = $deployDir . DIRECTORY_SEPARATOR . $backupRelative;
                    if (!$this->safeMakeDir(dirname($backupPath), 0700) || !@copy($target, $backupPath)) throw new RuntimeException('สำรองไฟล์เดิมไม่สำเร็จ: ' . $relative);
                    @chmod($backupPath, 0600);
                }
                $meta['files'][] = $record;
                $this->writeDeploymentMeta($deployDir, $meta);
                $this->atomicReplace($stageFile, $target, $record['mode']);
                if (!hash_equals((string)$row['sha256'], (string)hash_file('sha256', $target))) throw new RuntimeException('ตรวจสอบไฟล์หลังติดตั้งไม่ผ่าน: ' . $relative);
                $meta['files'][count($meta['files'])-1]['state'] = 'applied';
                $this->writeDeploymentMeta($deployDir, $meta);
                if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php' && function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
            }
            $meta['status'] = 'applied';
            $meta['finished_at'] = date('c');
            $this->writeDeploymentMeta($deployDir, $meta);
            $this->discardPreview($id);
            if (function_exists('opcache_reset')) @opcache_reset();
            return ['id'=>$deployId,'version'=>$meta['package_version'],'files'=>count($meta['files']),'counts'=>$meta['counts'],'backup_dir'=>$deployDir];
        } catch (Throwable $error) {
            $restore = $this->restoreEntries($deployDir, $meta, true);
            $meta['status'] = empty($restore['errors']) ? 'failed_rolled_back' : 'failed_partial_rollback';
            $meta['error'] = $error->getMessage();
            $meta['rollback_errors'] = $restore['errors'];
            $meta['finished_at'] = date('c');
            try { $this->writeDeploymentMeta($deployDir, $meta); } catch (Throwable $ignored) {}
            if (function_exists('opcache_reset')) @opcache_reset();
            throw new RuntimeException($error->getMessage() . (empty($restore['errors']) ? ' · ระบบย้อนคืนไฟล์ที่เริ่มเปลี่ยนแล้วให้โดยอัตโนมัติ' : ' · มีไฟล์บางรายการที่ย้อนคืนไม่สำเร็จ กรุณาตรวจ Deployment History'));
        } finally {
            @flock($lock, LOCK_UN); @fclose($lock);
        }
    }

    public function rollback($id) {
        $deployDir = $this->deploymentPath($id);
        $metaPath = $deployDir . DIRECTORY_SEPARATOR . 'deployment.json';
        $meta = $this->loadJson($metaPath);
        if (!$meta || !in_array(($meta['status'] ?? ''), ['applied','rollback_partial','failed_partial_rollback'], true)) throw new RuntimeException('รายการนี้ไม่อยู่ในสถานะที่ย้อนกลับได้');
        $lockPath = $this->privateRoot . DIRECTORY_SEPARATOR . 'deployment.lock';
        if (is_link($lockPath)) throw new RuntimeException('ไฟล์ล็อก Update ไม่ปลอดภัย');
        $lock = @fopen($lockPath, 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); throw new RuntimeException('มีงาน Update/Restore อื่นกำลังทำงาน'); }
        try {
            $result = $this->restoreEntries($deployDir, $meta, false);
            $meta['status'] = empty($result['errors']) ? 'rolled_back' : 'rollback_partial';
            $meta['rolled_back_at'] = date('c');
            $meta['rollback_errors'] = $result['errors'];
            $meta['rollback_restored'] = $result['restored'];
            $this->writeDeploymentMeta($deployDir, $meta);
            if (function_exists('opcache_reset')) @opcache_reset();
            if ($result['errors']) throw new RuntimeException('ย้อนกลับได้ ' . $result['restored'] . ' ไฟล์ แต่มี ' . count($result['errors']) . ' รายการที่ถูกข้ามเพื่อป้องกันการทับข้อมูล: ' . implode(' | ', array_slice($result['errors'], 0, 4)));
            return ['id'=>$id,'restored'=>$result['restored']];
        } finally { @flock($lock, LOCK_UN); @fclose($lock); }
    }

    public function listDeployments($limit = 8) {
        $rows = [];
        foreach (glob($this->privateRoot . DIRECTORY_SEPARATOR . 'deployments' . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir)) continue;
            $meta = $this->loadJson($dir . DIRECTORY_SEPARATOR . 'deployment.json');
            if (!$meta || empty($meta['id']) || !$this->safeId($meta['id'])) continue;
            $rows[] = $meta;
        }
        usort($rows, function($a,$b){ return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });
        return array_slice($rows, 0, max(1, (int)$limit));
    }

    private function cleanupExpiredStages() {
        $base = $this->privateRoot . DIRECTORY_SEPARATOR . 'staging';
        foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir) || (time() - (int)@filemtime($dir)) < 48 * 3600) continue;
            avsfSafeRemoveTree($dir);
        }
    }
}
}
