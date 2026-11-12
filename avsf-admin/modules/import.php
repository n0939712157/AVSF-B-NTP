<?php
/**
 * AVSF Admin — Bulk Import (นำเข้าจำนวนมาก)
 * วางที่: avsf-admin/import.php
 * รองรับ: CSV, JSON, TXT (รูปแบบ My Boss Universal Import)
 * สูงสุดไม่จำกัดจำนวนเรื่องต่อการนำเข้าหนึ่งรอบ ระบบแบ่งอัปโหลดและบันทึกต่อเนื่องเอง
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();
requireSiteCenterAccess();
require_once AVSF_CORE_DIR . '/security/zip_safety.php';
require_once AVSF_CORE_DIR . '/import/avsf-image-matcher.php';
requirePermission('posts.import');
ensureCategoriesTable($conn);
ensurePrimarySlugs($conn);
ensureSplitTaxonomyNames($conn);
ensurePostsUpdatedAtColumn($conn);
foreach(['coverAlt'=>'VARCHAR(255) DEFAULT NULL','posterAlt'=>'VARCHAR(255) DEFAULT NULL','galleryAlt'=>'TEXT DEFAULT NULL'] as $column=>$definition){
    $columnExists=mysqli_query($conn,"SHOW COLUMNS FROM posts LIKE '$column'");
    if($columnExists && mysqli_num_rows($columnExists)==0) mysqli_query($conn,"ALTER TABLE posts ADD COLUMN `$column` $definition");
}

function importXlsxRows($path) {
    if (!class_exists('ZipArchive')) throw new RuntimeException('เซิร์ฟเวอร์ไม่มีส่วนขยาย ZipArchive สำหรับอ่าน XLSX');
    $zip = new ZipArchive(); if ($zip->open($path) !== true) throw new RuntimeException('เปิดไฟล์ XLSX ไม่ได้');
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $simple = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NOCDATA|LIBXML_NONET);
        if ($simple) foreach ($simple->si as $si) { $text=[]; if(isset($si->t)) $text[]=(string)$si->t; foreach($si->r as $run) $text[]=(string)$run->t; $shared[]=implode('', $text); }
    }
    $xlsxColumn = function($letters) { $n=0; foreach(str_split(strtoupper($letters)) as $c) $n=$n*26+ord($c)-64; return $n-1; };
    $rows=[]; $sheetNo=0;
    while (($xml=$zip->getFromName('xl/worksheets/sheet'.(++$sheetNo).'.xml')) !== false) {
        $sheet=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET); if(!$sheet) continue;
        foreach($sheet->xpath('//*[local-name()="row"]') as $row) {
            $values=[];
            foreach($row->xpath('./*[local-name()="c"]') as $cell) {
                $ref=(string)$cell['r']; preg_match('/^([A-Z]+)/i',$ref,$m); $col=$xlsxColumn($m[1]??'A');
                while(count($values)<=$col) $values[]='';
                $type=(string)$cell['t']; $vNodes=$cell->xpath('./*[local-name()="v"]'); $value=$vNodes?(string)$vNodes[0]:'';
                if($type==='s') $value=$shared[(int)$value]??'';
                elseif($type==='inlineStr'){ $tNodes=$cell->xpath('.//*[local-name()="t"]'); $value=$tNodes?(string)$tNodes[0]:''; }
                $values[$col]=$value;
            }
            while(count($values)&&end($values)==='') array_pop($values);
            $rows[]=$values;
        }
    }
    $zip->close();
    if(count($rows)<2) return [];
    $headers=[]; foreach($rows[0] as $header) $headers[]=trim(preg_replace('/\s+/u',' ',(string)$header));
    $out=[];
    for($i=1;$i<count($rows);$i++){ if(!array_filter($rows[$i],fn($v)=>trim((string)$v)!=='')) continue; $data=[]; foreach($headers as $j=>$header) if($header!=='' && array_key_exists($j,$rows[$i])) $data[$header]=$rows[$i][$j]; $out[]=$data; }
    return $out;
}

function importMediaFile($source, $code = '', $webPrefix = 'uploads/import-media', $originalName = '') {
    global $importMediaStats;
    $saved = avsfStoreImportImage($source, $code, $originalName);
    if ($saved !== '' && is_array($importMediaStats)) $importMediaStats['saved']++;
    return $saved;
}


/**
 * Persist a completed import job summary for Site Center observability.
 * Only counts, mode, a token hash and a short summary are stored; no filenames,
 * image URLs, source file paths, credentials or raw job tokens are persisted.
 */
function importRecordHistory($conn, $jobToken, $mode, $totalRows, $inserted, $updated, $skipped, $failed, $savedImages, $linkedImages, $summary = '') {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) return false;
    $jobToken = (string)$jobToken;
    if ($jobToken === '') $jobToken = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $jobToken);
    $allowedMode = in_array($mode, ['posts','image_only','disk_relink'], true) ? $mode : 'posts';
    $startedAt = isset($_SESSION['avsf_import_jobs'][$jobToken]) ? (int)$_SESSION['avsf_import_jobs'][$jobToken] : time();
    $startedAtSql = date('Y-m-d H:i:s', max(0, $startedAt));
    $actorId = (int)($_SESSION['avsf_admin_id'] ?? 0);
    $safeSummary = function_exists('mb_substr') ? mb_substr((string)$summary, 0, 240, 'UTF-8') : substr((string)$summary, 0, 240);
    $stmt = @$conn->prepare('INSERT INTO import_history (job_token_hash,mode,total_rows,inserted_rows,updated_rows,skipped_rows,failed_rows,saved_images,linked_images,started_at,finished_at,created_by,summary_text) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,?) ON DUPLICATE KEY UPDATE total_rows=VALUES(total_rows),inserted_rows=VALUES(inserted_rows),updated_rows=VALUES(updated_rows),skipped_rows=VALUES(skipped_rows),failed_rows=VALUES(failed_rows),saved_images=VALUES(saved_images),linked_images=VALUES(linked_images),finished_at=NOW(),summary_text=VALUES(summary_text)');
    if (!$stmt) {
        error_log('AVSF import history prepare failed: ' . (isset($conn->error) ? $conn->error : 'database unavailable'));
        return false;
    }
    $totalRows=(int)$totalRows; $inserted=(int)$inserted; $updated=(int)$updated;
    $skipped=(int)$skipped; $failed=(int)$failed; $savedImages=(int)$savedImages; $linkedImages=(int)$linkedImages;
    $stmt->bind_param('ssiiiiiiisis', $tokenHash,$allowedMode,$totalRows,$inserted,$updated,$skipped,$failed,$savedImages,$linkedImages,$startedAtSql,$actorId,$safeSummary);
    $ok=$stmt->execute();
    if (!$ok) error_log('AVSF import history insert failed: ' . $stmt->error);
    $stmt->close();
    return $ok;
}


/**
 * Normalize the main multi-file picker for both AJAX staging and ordinary
 * PHP form fallback. Accept one data file plus multiple Code-named images.
 * Do not silently replace one data file with the last selected data file.
 */
function importNormalizeMainUploadFiles($upload, $maxImages = 3000, $maxImageBytes = 26214400) {
    $result = ['data'=>null, 'images'=>[], 'errors'=>[]];
    if (!is_array($upload) || !array_key_exists('name', $upload)) return $result;
    $names = is_array($upload['name']) ? $upload['name'] : [$upload['name']];
    $temps = is_array($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : [($upload['tmp_name'] ?? '')];
    $errors = is_array($upload['error'] ?? null) ? $upload['error'] : [($upload['error'] ?? UPLOAD_ERR_NO_FILE)];
    $sizes = is_array($upload['size'] ?? null) ? $upload['size'] : [($upload['size'] ?? 0)];
    $dataExtensions = ['csv','xlsx','json','txt','zip'];
    $imageExtensions = ['jpg','jpeg','png','webp','gif','avif'];
    $dataConflict = false;

    foreach ($names as $index => $rawName) {
        $name = basename(str_replace('\\', '/', (string)$rawName));
        $tmp = (string)($temps[$index] ?? '');
        $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($sizes[$index] ?? 0);
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') continue;
        if ($error !== UPLOAD_ERR_OK) {
            $result['errors'][] = 'อัปโหลดไฟล์ ' . $name . ' ไม่สำเร็จ (รหัส ' . $error . ')';
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $imageExtensions, true)) {
            if ($size < 1 || $size > $maxImageBytes || $tmp === '' || !is_file($tmp) || !@getimagesize($tmp)) {
                $result['errors'][] = 'รูป ' . $name . ' ไม่ใช่รูปภาพที่รองรับหรือใหญ่เกิน ' . round($maxImageBytes / 1048576) . ' MB';
                continue;
            }
            if (count($result['images']) >= $maxImages) {
                $result['errors'][] = 'รูปจากช่องไฟล์หลักเกิน ' . number_format($maxImages) . ' ไฟล์';
                break;
            }
            $result['images'][] = ['path'=>$tmp, 'name'=>$name];
            continue;
        }
        if (in_array($ext, $dataExtensions, true)) {
            if ($result['data'] !== null || $dataConflict) {
                $result['data'] = null;
                $dataConflict = true;
                $result['errors'][] = 'เลือกไฟล์ข้อมูลได้ครั้งละ 1 ไฟล์; ไม่ได้เริ่มนำเข้าเพราะพบไฟล์ข้อมูลมากกว่า 1 ไฟล์';
                continue;
            }
            $result['data'] = [
                'name'=>$name,
                'type'=>(string)($upload['type'][$index] ?? ''),
                'tmp_name'=>$tmp,
                'error'=>$error,
                'size'=>$size
            ];
            continue;
        }
        $result['errors'][] = 'ชนิดไฟล์ไม่รองรับ: ' . $name;
    }
    return $result;
}

function importFindMatchingImages($images, $code, $coverName = '', $posterName = '') {
    return avsfFindImportImagesByCode($images, $code, $coverName, $posterName);
}

function importResolveImageValue($value, $images, $code, $role = 'cover') {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('#^(?:https?://|/)#i', $value) || preg_match('#^(?:uploads|assets|media)/#i', $value)) return $value;
    $wanted = strtolower(basename($value));
    $classified = importFindMatchingImages($images, $code);
    $classifiedByPath = [];
    foreach ($classified as $item) $classifiedByPath[$item['_real'] ?? realpath($item['path'])] = (string)($item['role'] ?? '');
    foreach ($images as $image) {
        if (strtolower(basename((string)($image['name'] ?? ''))) === $wanted && !empty($image['path']) && is_file($image['path'])) {
            $real = realpath($image['path']);
            $detectedRole = $real !== false ? (string)($classifiedByPath[$real] ?? '') : '';
            if ($detectedRole !== '' && $detectedRole !== $role) return '';
            return importMediaFile($image['path'], $code, 'uploads/import-media', $image['name']);
        }
    }
    foreach (importFindMatchingImages($images, $code, $role === 'cover' ? $value : '', $role === 'poster' ? $value : '') as $image) {
        if (($image['role'] ?? '') === $role) return importMediaFile($image['path'], $code, 'uploads/import-media', $image['name']);
    }
    return '';
}

function importProgressPath($token) {
    $identity = session_id() . '|' . (string)$token;
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avsf-import-progress-' . hash('sha256', $identity) . '.json';
}
function importProgressUpdate($token, $percent, $message, $done = 0, $total = 0) {
    if (!is_string($token) || $token === '') return false;
    $data = ['percent'=>max(0,min(100,(int)$percent)), 'message'=>(string)$message, 'done'=>max(0,(int)$done), 'total'=>max(0,(int)$total), 'updated_at'=>time()];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $path = importProgressPath($token);
    $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temp, $json, LOCK_EX) === false) return false;
    @chmod($temp, 0600);
    if (!@rename($temp, $path)) { @unlink($temp); return false; }
    return true;
}


/** Private per-session image-only job storage. */
function importImageJobDirectory($token) {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avsf-image-job-' . hash('sha256', session_id() . '|' . (string)$token);
}

/** Match uploaded filenames to existing posts by exact Code, never by loose prefix. */
function importBuildExistingPostImageGroups($conn, $images) {
    $map = [];
    $result = $conn->query("SELECT id, code FROM posts WHERE code IS NOT NULL AND code <> ''");
    if (!$result) throw new RuntimeException('อ่านรายการ Code จาก posts ไม่สำเร็จ: ' . $conn->error);
    while ($row = $result->fetch_assoc()) {
        $key = avsfNormalizeImageKey((string)$row['code']);
        if ($key === '') continue;
        if (!isset($map[$key])) $map[$key] = [];
        $map[$key][] = ['id'=>(int)$row['id'], 'code'=>(string)$row['code']];
    }
    $result->free();

    $groups = []; $unmatched = 0; $ambiguous = 0; $seenFiles = [];
    foreach ((array)$images as $image) {
        if (!is_array($image) || empty($image['path']) || !is_file($image['path'])) { $unmatched++; continue; }
        $real = realpath($image['path']);
        if ($real === false || isset($seenFiles[$real])) continue;
        $seenFiles[$real] = true;
        $stem = avsfNormalizeImageKey((string)($image['name'] ?? basename($image['path'])));
        if ($stem === '') { $unmatched++; continue; }

        $matched = null; $isAmbiguous = false; $bestLength = -1;
        foreach ($map as $candidate => $postsForCode) {
            if ($candidate === '' || !is_array($postsForCode)) continue;
            if ($stem !== $candidate && strpos($stem, $candidate . '-') !== 0) continue;
            $length = strlen($candidate);
            if ($length < $bestLength) continue;
            $bestLength = $length;
            if (count($postsForCode) !== 1) { $matched = null; $isAmbiguous = true; continue; }
            $matched = $postsForCode[0];
            $isAmbiguous = false;
        }
        if ($isAmbiguous) { $ambiguous++; continue; }
        if (!$matched) { $unmatched++; continue; }
        $postId = (int)$matched['id'];
        if (!isset($groups[$postId])) $groups[$postId] = ['post_id'=>$postId, 'code'=>$matched['code'], 'images'=>[]];
        $groups[$postId]['images'][] = ['path'=>$image['path'], 'name'=>basename((string)($image['name'] ?? ''))];
    }
    uasort($groups, function($a, $b) { return strnatcasecmp((string)$a['code'], (string)$b['code']); });
    return ['groups'=>array_values($groups), 'unmatched'=>$unmatched, 'ambiguous'=>$ambiguous];
}

/** Copy uploaded images into a private job directory so each bounded request can process a small batch. */
function importStageImageOnlyJob($token, $groupData) {
    $dir = importImageJobDirectory($token);
    if (is_dir($dir)) avsfSafeRemoveTree($dir);
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('สร้างพื้นที่พักไฟล์นำเข้ารูปภาพไม่สำเร็จ');
    @chmod($dir, 0700);
    $groups = []; $sequence = 0;
    foreach ((array)($groupData['groups'] ?? []) as $group) {
        $staged = [];
        foreach ((array)($group['images'] ?? []) as $image) {
            $source = (string)($image['path'] ?? '');
            if ($source === '' || !is_file($source) || !is_readable($source)) continue;
            $sequence++;
            $target = $dir . DIRECTORY_SEPARATOR . sprintf('asset-%06d.bin', $sequence);
            // ZIP extraction and PHP upload temp files usually live on the same filesystem;
            // rename is instant, with copy as a safe fallback for cross-volume temp paths.
            if (!@rename($source, $target) && !@copy($source, $target)) continue;
            @chmod($target, 0600);
            $staged[] = ['path'=>$target, 'name'=>basename((string)($image['name'] ?? ''))];
        }
        if ($staged) $groups[] = ['post_id'=>(int)$group['post_id'], 'code'=>(string)$group['code'], 'images'=>$staged];
    }
    if (!$groups) { avsfSafeRemoveTree($dir); return 0; }
    $job = [
        'version'=>1, 'created_at'=>time(), 'next'=>0, 'total'=>count($groups),
        'groups'=>$groups,
        'stats'=>['updated_posts'=>0,'linked_images'=>0,'skipped_posts'=>0,'errors'=>0,'unmatched'=>(int)($groupData['unmatched'] ?? 0),'ambiguous'=>(int)($groupData['ambiguous'] ?? 0)],
        'results'=>[]
    ];
    $json = json_encode($job, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($dir . DIRECTORY_SEPARATOR . 'job.json', $json, LOCK_EX) === false) {
        avsfSafeRemoveTree($dir);
        throw new RuntimeException('บันทึกคิวงานรูปภาพไม่สำเร็จ');
    }
    @chmod($dir . DIRECTORY_SEPARATOR . 'job.json', 0600);
    return count($groups);
}

/** Update only the image columns for an existing post; all story, SEO and taxonomy data remains untouched. */
function importApplyImageOnlyGroup($conn, $group) {
    $postId = (int)($group['post_id'] ?? 0);
    $code = (string)($group['code'] ?? '');
    $lookup = $conn->prepare('SELECT code,actorEN,cover,coverAlt,posterUrl,posterAlt,gallery,galleryAlt FROM posts WHERE id=? LIMIT 1');
    if (!$lookup) throw new RuntimeException('เตรียมอ่านเรื่องเดิมไม่สำเร็จ: ' . $conn->error);
    $lookup->bind_param('i', $postId);
    if (!$lookup->execute()) { $error=$lookup->error; $lookup->close(); throw new RuntimeException('อ่านเรื่องเดิมไม่สำเร็จ: ' . $error); }
    $post = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$post || strcasecmp((string)$post['code'], $code) !== 0) return ['status'=>'skip','images'=>0,'title'=>$code,'reason'=>'ไม่พบ Code ที่ตรงกันแบบ exact'];

    $assets = avsfFindImportImagesByCode((array)($group['images'] ?? []), $code);
    if (!$assets) return ['status'=>'skip','images'=>0,'title'=>$code,'reason'=>'ชื่อไฟล์ไม่ตรงรูปแบบ Code / Cover / Poster / Gallery'];

    $cover = (string)($post['cover'] ?? '');
    $coverAlt = (string)($post['coverAlt'] ?? '');
    $poster = (string)($post['posterUrl'] ?? '');
    $posterAlt = (string)($post['posterAlt'] ?? '');
    $gallery = json_decode((string)($post['gallery'] ?? '[]'), true);
    if (!is_array($gallery)) $gallery = [];
    $galleryAlt = json_decode((string)($post['galleryAlt'] ?? '[]'), true);
    if (!is_array($galleryAlt)) $galleryAlt = [];
    while (count($galleryAlt) < count($gallery)) $galleryAlt[] = trim($code . ' ' . (string)($post['actorEN'] ?? ''));
    $altDefault = trim($code . ' ' . (string)($post['actorEN'] ?? ''));
    $linked = 0; $newCover = false; $newPoster = false;

    foreach ($assets as $asset) {
        $role = (string)($asset['role'] ?? '');
        if (!in_array($role, ['cover','poster','gallery'], true)) continue;
        if ($role === 'cover' && $newCover) continue;
        if ($role === 'poster' && $newPoster) continue;
        $url = importReuseOrStoreImage((string)$asset['path'], $code, (string)$asset['name']);
        if ($url === '') continue;
        $linked++;
        if ($role === 'cover') {
            $cover = $url;
            if ($coverAlt === '') $coverAlt = $altDefault;
            $newCover = true;
        } elseif ($role === 'poster') {
            $poster = $url;
            if ($posterAlt === '') $posterAlt = $altDefault;
            $newPoster = true;
        } else {
            if (!in_array($url, $gallery, true)) {
                $gallery[] = $url;
                $galleryAlt[] = $altDefault;
            }
        }
    }
    if ($cover === '' && $gallery) {
        $cover = (string)$gallery[0];
        if ($coverAlt === '') $coverAlt = $altDefault;
    }
    if ($linked < 1 && $cover === (string)($post['cover'] ?? '')) return ['status'=>'skip','images'=>0,'title'=>$code,'reason'=>'บันทึกไฟล์ภาพไม่สำเร็จหรือไม่มีไฟล์ที่ตรงรูปแบบ'];
    if (mb_strlen($cover, 'UTF-8') > 255 || mb_strlen($poster, 'UTF-8') > 1000 || mb_strlen($coverAlt, 'UTF-8') > 255 || mb_strlen($posterAlt, 'UTF-8') > 255) {
        throw new RuntimeException('URL หรือ ALT รูปภาพของ ' . $code . ' ยาวเกินขนาดฐานข้อมูลรองรับ');
    }

    $gallery = array_values($gallery);
    $galleryAlt = array_slice(array_values($galleryAlt), 0, count($gallery));
    $galleryJson = json_encode($gallery, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $galleryAltJson = json_encode($galleryAlt, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($galleryJson === false || $galleryAltJson === false || strlen($galleryJson) > 65535 || strlen($galleryAltJson) > 65535) {
        throw new RuntimeException('Gallery ของ ' . $code . ' มีขนาดเกินฐานข้อมูลรองรับ');
    }
    $update = $conn->prepare('UPDATE posts SET cover=?,coverAlt=?,posterUrl=?,posterAlt=?,gallery=?,galleryAlt=?,updatedAt=NOW() WHERE id=?');
    if (!$update) throw new RuntimeException('เตรียมอัปเดตรูปภาพไม่สำเร็จ: ' . $conn->error);
    $update->bind_param('ssssssi', $cover, $coverAlt, $poster, $posterAlt, $galleryJson, $galleryAltJson, $postId);
    $ok = $update->execute(); $error = $update->error; $update->close();
    if (!$ok) throw new RuntimeException('บันทึกรูปภาพของ ' . $code . ' ไม่สำเร็จ: ' . $error);
    return ['status'=>'updated','images'=>$linked,'title'=>$code,'reason'=>'จับคู่รูปและอัปเดตเฉพาะช่องรูปภาพแล้ว'];
}

function importIniBytes($value) {
    $value = trim((string)$value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    if ($unit === 'g') return (int)round($number * 1073741824);
    if ($unit === 'm') return (int)round($number * 1048576);
    if ($unit === 'k') return (int)round($number * 1024);
    return (int)round($number);
}

function importStageDirectory($token) {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'avsf-import-stage-' . hash('sha256', session_id() . '|' . (string)$token);
}

function importReadJsonFile($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function importWriteJsonFile($path, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temp, $json, LOCK_EX) === false) return false;
    @chmod($temp, 0600);
    if (!@rename($temp, $path)) { @unlink($temp); return false; }
    @chmod($path, 0600);
    return true;
}

function importAjaxRateLimit($maxPerMinute = 240) {
    $key = 'rate_import_ajax_' . (function_exists('sec_client_ip') ? sec_client_ip() : 'local');
    $now = time();
    $history = $_SESSION[$key] ?? [];
    if (!is_array($history)) $history = [];
    $history = array_values(array_filter($history, function($stamp) use ($now) { return ($now - (int)$stamp) < 60; }));
    if (count($history) >= $maxPerMinute) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'rate_limit','message'=>'ส่งคำขอถี่เกินไป ระบบจะพักสั้นๆ แล้วไปต่อได้'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    $history[] = $now;
    $_SESSION[$key] = $history;
}

function importRequireJobToken($allowSeconds = 21600) {
    $token = function_exists('sec_post') ? sec_post('job_token') : (string)($_POST['job_token'] ?? '');
    $jobs = $_SESSION['avsf_import_jobs'] ?? [];
    $issuedAt = is_array($jobs) ? (int)($jobs[$token] ?? 0) : 0;
    if (!preg_match('/^[a-f0-9]{48}$/', $token) || $issuedAt < time() - $allowSeconds) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'invalid_token','message'=>'คำขอนำเข้าหมดอายุ กรุณาเปิดหน้านี้ใหม่แล้วเริ่มอีกครั้ง'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    return $token;
}

function importPublicUrlForStoredFile($path) {
    if (!defined('AVSF_UPLOAD_DIR')) return '';
    $root = realpath(AVSF_UPLOAD_DIR);
    $real = realpath((string)$path);
    if ($root === false || $real === false || !is_file($real)) return '';
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $realNorm = str_replace('\\', '/', $real);
    if (strpos($realNorm, $root . '/') !== 0 && $realNorm !== $root) return '';
    $relative = ltrim(substr($realNorm, strlen($root)), '/');
    if ($relative === '' || strpos($relative, '..') !== false) return '';
    $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
    return rtrim(avsfCurrentBasePath(), '/') . '/uploads/' . $encoded;
}

function importReuseOrStoreImage($path, $code, $name) {
    $existing = importPublicUrlForStoredFile($path);
    if ($existing !== '') return $existing;
    return avsfStoreImportImage($path, $code, $name);
}

function importCollectLibraryImages() {
    if (!defined('AVSF_UPLOAD_DIR') || !is_dir(AVSF_UPLOAD_DIR)) return [];
    $images = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AVSF_UPLOAD_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif','avif'], true)) continue;
        $images[] = ['path'=>$file->getPathname(), 'name'=>$file->getFilename()];
    }
    return $images;
}

function importPromoteEmptyCovers($conn) {
    $promoted = 0;
    $result = $conn->query("SELECT id,code,actorEN,cover,coverAlt,gallery FROM posts WHERE (cover IS NULL OR cover='') AND gallery IS NOT NULL AND gallery NOT IN ('','[]')");
    if (!$result) return 0;
    while ($row = $result->fetch_assoc()) {
        $gallery = json_decode((string)($row['gallery'] ?? '[]'), true);
        if (!is_array($gallery)) continue;
        $cover = '';
        foreach ($gallery as $url) { $url = trim((string)$url); if ($url !== '') { $cover = $url; break; } }
        if ($cover === '' || mb_strlen($cover, 'UTF-8') > 255) continue;
        $alt = trim((string)($row['coverAlt'] ?? ''));
        if ($alt === '') $alt = trim((string)($row['code'] ?? '') . ' ' . (string)($row['actorEN'] ?? ''));
        if (mb_strlen($alt, 'UTF-8') > 255) $alt = mb_substr($alt, 0, 255, 'UTF-8');
        $postId = (int)$row['id'];
        $update = $conn->prepare('UPDATE posts SET cover=?,coverAlt=?,updatedAt=NOW() WHERE id=? AND (cover IS NULL OR cover="")');
        if (!$update) continue;
        $update->bind_param('ssi', $cover, $alt, $postId);
        if ($update->execute() && $update->affected_rows > 0) $promoted++;
        $update->close();
    }
    $result->free();
    return $promoted;
}

function importParseDataFile($path, $originalName, &$extraImages) {
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    $rows = [];
    $error = '';
    $extract = '';
    if ($ext === 'csv') {
        $handle = fopen($path, 'r');
        if ($handle === false) $error = 'เปิดไฟล์ CSV ไม่สำเร็จ';
        else {
            $headers = fgetcsv($handle);
            if (is_array($headers) && isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
            if (!is_array($headers) || !$headers) $error = 'ไฟล์ CSV ไม่มีแถวหัวคอลัมน์';
            else while (($data = fgetcsv($handle)) !== false) if (count($data) === count($headers)) $rows[] = array_combine($headers, $data);
            fclose($handle);
        }
    } elseif ($ext === 'xlsx') {
        try { $rows = importXlsxRows($path); }
        catch (Throwable $exception) { $error = $exception->getMessage(); }
    } elseif ($ext === 'json') {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) $rows = $decoded; else $error = 'ไฟล์ JSON มีรูปแบบไม่ถูกต้อง';
    } elseif ($ext === 'txt') {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode('|', $line);
            $rows[] = ['code'=>trim($parts[0] ?? ''),'duration'=>trim($parts[1] ?? ''),'video_url'=>trim($parts[2] ?? ''),'title'=>trim($parts[3] ?? '')];
        }
    } elseif ($ext === 'zip') {
        $extract = dirname($path) . DIRECTORY_SEPARATOR . 'zip-extract';
        if (is_dir($extract)) avsfSafeRemoveTree($extract);
        try {
            avsfSafeExtractZip($path, $extract, ['max_entries'=>8000, 'max_file_bytes'=>50*1024*1024, 'max_total_bytes'=>700*1024*1024, 'max_ratio'=>150]);
            $dataFiles = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extract, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $fileExt = strtolower($file->getExtension());
                if (in_array($fileExt, ['xlsx','csv','json','txt'], true)) $dataFiles[] = $file->getPathname();
                elseif (in_array($fileExt, ['jpg','jpeg','png','webp','gif','avif'], true)) $extraImages[] = ['path'=>$file->getPathname(), 'name'=>$file->getFilename()];
            }
            sort($dataFiles, SORT_NATURAL | SORT_FLAG_CASE);
            if (!$dataFiles && !$extraImages) $error = 'ZIP ไม่มีไฟล์ข้อมูลหรือรูปภาพ';
            elseif ($dataFiles) {
                $inner = importParseDataFile($dataFiles[0], basename($dataFiles[0]), $extraImages);
                $rows = $inner['rows'];
                if ($inner['error'] !== '') $error = $inner['error'];
                if ($inner['extract'] !== '' && $inner['extract'] !== $extract) $extract = $inner['extract'];
            }
        } catch (Throwable $zipError) { $error = 'อ่าน ZIP ไม่สำเร็จ: ' . $zipError->getMessage(); }
    } else $error = 'รองรับเฉพาะไฟล์ CSV, XLSX, JSON, TXT และ ZIP';
    return ['rows'=>$rows, 'error'=>$error, 'extract'=>$extract];
}

function importSaveStagedUpload($directory, $tmp, $originalName, $isImage) {
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    $safeExt = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
    $target = $directory . DIRECTORY_SEPARATOR . ($isImage ? 'img-' : 'data-') . bin2hex(random_bytes(8)) . '.' . $safeExt;
    if (!@move_uploaded_file($tmp, $target) && !@copy($tmp, $target)) return '';
    @chmod($target, 0600);
    return $target;
}

$msg = '';
$results = [];
$importMediaStats = ['saved'=>0,'reused'=>0];
$importJobToken = '';
$activeImportJobToken = '';
$importExtractRoot = '';
$usingStage = false;
$importBatchOffset = 0;
$importBatchLimit = 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['avsf_image_only_flash']) && is_array($_SESSION['avsf_image_only_flash'])) {
    $imageFlash = $_SESSION['avsf_image_only_flash'];
    $msg = (string)($imageFlash['message'] ?? '');
    $results = is_array($imageFlash['results'] ?? null) ? $imageFlash['results'] : [];
    unset($_SESSION['avsf_image_only_flash']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['avsf_import_flash']) && is_array($_SESSION['avsf_import_flash'])) {
    $importFlash = $_SESSION['avsf_import_flash'];
    $msg = (string)($importFlash['message'] ?? '');
    $results = is_array($importFlash['results'] ?? null) ? $importFlash['results'] : [];
    unset($_SESSION['avsf_import_flash']);
}

if (isset($_GET['progress'])) {
    $progressToken = is_string($_GET['progress']) ? $_GET['progress'] : '';
    $jobs = $_SESSION['avsf_import_jobs'] ?? [];
    $issuedAt = is_array($jobs) ? (int)($jobs[$progressToken] ?? 0) : 0;
    if ($progressToken === '' || $issuedAt < time() - 1800 || !preg_match('/^[a-f0-9]{48}$/', $progressToken)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'invalid_token']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $progressRaw = @file_get_contents(importProgressPath($progressToken));
    $progressData = $progressRaw !== false ? json_decode($progressRaw, true) : null;
    echo json_encode(is_array($progressData) ? $progressData : ['percent'=>0,'message'=>__('กำลังเตรียมประมวลผล…'),'done'=>0,'total'=>0], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

// Each image-only batch is a short independent request. This avoids one long PHP request timing out.
if (isset($_GET['image_job_step']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);
    sec_csrf_verify();
    $token = sec_post('image_job_token');
    $jobs = $_SESSION['avsf_import_jobs'] ?? [];
    $issuedAt = is_array($jobs) ? (int)($jobs[$token] ?? 0) : 0;
    if (!preg_match('/^[a-f0-9]{48}$/', $token) || $issuedAt < time()-43200) {
        http_response_code(403); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'invalid_token']); exit;
    }
    $jobDir = importImageJobDirectory($token);
    $jobPath = $jobDir . DIRECTORY_SEPARATOR . 'job.json';
    $lockPath = $jobDir . DIRECTORY_SEPARATOR . 'job.lock';
    $lock = @fopen($lockPath, 'c');
    if (is_resource($lock)) @chmod($lockPath, 0600);
    if (!$lock || !@flock($lock, LOCK_EX|LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        http_response_code(409); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'job_busy','message'=>'กำลังประมวลผลชุดก่อนหน้า']); exit;
    }
    $rawJob = @file_get_contents($jobPath);
    $job = $rawJob !== false ? json_decode($rawJob, true) : null;
    if (!is_array($job) || (int)($job['version'] ?? 0) !== 1 || time()-(int)($job['created_at'] ?? 0) > 43200) {
        @flock($lock, LOCK_UN); fclose($lock);
        http_response_code(410); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'job_expired']); exit;
    }
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn) {
        @flock($lock, LOCK_UN); fclose($lock);
        http_response_code(500); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'database_unavailable']); exit;
    }
    $groups = (array)($job['groups'] ?? []);
    $total = count($groups);
    $start = max(0, (int)($job['next'] ?? 0));
    $batchSize = 5; // Keep each web request short on shared hosting, even for large galleries.
    $batch = array_slice($groups, $start, $batchSize);
    foreach ($batch as $group) {
        try {
            $result = importApplyImageOnlyGroup($conn, $group);
            if (($result['status'] ?? '') === 'updated') {
                $job['stats']['updated_posts']++;
                $job['stats']['linked_images'] += (int)($result['images'] ?? 0);
            } else {
                $job['stats']['skipped_posts']++;
            }
        } catch (Throwable $error) {
            $job['stats']['errors']++;
            $result = ['status'=>'error','images'=>0,'title'=>(string)($group['code'] ?? ''),'reason'=>$error->getMessage()];
        }
        if (count($job['results']) < 120) $job['results'][] = $result;
    }
    $job['next'] = min($total, $start + count($batch));
    $done = $job['next'];
    $isDone = ($done >= $total);
    $percent = $total > 0 ? (int)floor(($done / $total) * 100) : 100;
    $stats = (array)$job['stats'];
    $message = 'จับคู่รูปตาม Code แล้ว ' . $done . '/' . $total . ' เรื่อง';
    importProgressUpdate($token, $percent, $message, $done, $total);

    if ($isDone) {
        $summary = 'นำเข้ารูปภาพเสร็จ — อัปเดต ' . (int)$stats['updated_posts'] . ' เรื่อง, เชื่อมรูป ' . (int)$stats['linked_images'] . ' ไฟล์, ข้าม ' . (int)$stats['skipped_posts'] . ' เรื่อง, ผิดพลาด ' . (int)$stats['errors'] . ' เรื่อง, ไม่พบ Code ' . (int)$stats['unmatched'] . ' ไฟล์, Code ซ้ำ ' . (int)$stats['ambiguous'] . ' ไฟล์';
        importRecordHistory($conn, $token, 'image_only', $total, 0, (int)$stats['updated_posts'], (int)$stats['skipped_posts'], (int)$stats['errors'], 0, (int)$stats['linked_images'], $summary);
        $_SESSION['avsf_image_only_flash'] = ['message'=>$summary, 'results'=>$job['results']];
        importProgressUpdate($token, 100, $summary, $total, $total);
    } else {
        $json = json_encode($job, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $tempJob = $jobPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($json === false || @file_put_contents($tempJob, $json, LOCK_EX) === false || !@rename($tempJob, $jobPath)) {
            @unlink($tempJob);
            $job['stats']['errors']++;
            $isDone = true;
            $_SESSION['avsf_image_only_flash'] = ['message'=>'บันทึกสถานะงานไม่สำเร็จ โปรดเริ่มนำเข้ารูปชุดนี้ใหม่', 'results'=>$job['results']];
        }
    }
    @flock($lock, LOCK_UN); fclose($lock);
    if ($isDone) avsfSafeRemoveTree($jobDir);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['done'=>$isDone,'processed'=>$done,'total'=>$total,'percent'=>$isDone?100:$percent,'message'=>$message], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['stage_chunk']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    importAjaxRateLimit(240);
    $token = importRequireJobToken();
    $dir = importStageDirectory($token);
    $filesDir = $dir . DIRECTORY_SEPARATOR . 'files';
    if (!is_dir($filesDir) && !@mkdir($filesDir, 0700, true) && !is_dir($filesDir)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'stage_failed','message'=>'สร้างพื้นที่พักไฟล์ไม่สำเร็จ']);
        exit;
    }
    @chmod($dir, 0700);
    $manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = importReadJsonFile($manifestPath);
    if (!is_array($manifest)) $manifest = ['images'=>[], 'data'=>null];
    if (!isset($manifest['images']) || !is_array($manifest['images'])) $manifest['images'] = [];
    $accepted = 0; $errors = [];
    if (!empty($_FILES['chunk_data']['tmp_name']) && (int)($_FILES['chunk_data']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $dataName = basename((string)($_FILES['chunk_data']['name'] ?? 'data.bin'));
        $dataExt = strtolower(pathinfo($dataName, PATHINFO_EXTENSION));
        $dataSize = (int)($_FILES['chunk_data']['size'] ?? 0);
        if (!in_array($dataExt, ['csv','xlsx','json','txt','zip'], true)) $errors[] = 'ไฟล์ข้อมูลต้องเป็น CSV, XLSX, JSON, TXT หรือ ZIP';
        elseif ($dataSize < 1 || $dataSize > 80 * 1024 * 1024) $errors[] = 'ไฟล์ข้อมูลใหญ่เกินที่เซิร์ฟเวอร์รับต่อครั้ง';
        else {
            $stored = importSaveStagedUpload($filesDir, (string)$_FILES['chunk_data']['tmp_name'], $dataName, false);
            if ($stored === '') $errors[] = 'บันทึกไฟล์ข้อมูลไม่สำเร็จ';
            else $manifest['data'] = ['path'=>$stored, 'name'=>$dataName];
        }
    }
    $imageFiles = $_FILES['chunk_images'] ?? null;
    if (is_array($imageFiles) && isset($imageFiles['name']) && is_array($imageFiles['name'])) {
        foreach ($imageFiles['name'] as $index => $imageName) {
            $err = (int)($imageFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) { $errors[] = 'อัปโหลด ' . basename((string)$imageName) . ' ไม่สำเร็จ'; continue; }
            $size = (int)($imageFiles['size'][$index] ?? 0);
            $tmp = (string)($imageFiles['tmp_name'][$index] ?? '');
            $ext = strtolower(pathinfo((string)$imageName, PATHINFO_EXTENSION));
            if ($size < 1 || $size > 25 * 1024 * 1024 || !in_array($ext, ['jpg','jpeg','png','webp','gif','avif'], true) || !is_file($tmp) || !@getimagesize($tmp)) {
                $errors[] = 'ไฟล์ ' . basename((string)$imageName) . ' ไม่ใช่รูปที่รองรับหรือใหญ่เกิน 25 MB';
                continue;
            }
            if (count($manifest['images']) >= 20000) { $errors[] = 'จำนวนรูปในงานนี้เกิน 20,000 ไฟล์'; break; }
            $stored = importSaveStagedUpload($filesDir, $tmp, (string)$imageName, true);
            if ($stored === '') { $errors[] = 'บันทึกรูป ' . basename((string)$imageName) . ' ไม่สำเร็จ'; continue; }
            $manifest['images'][] = ['path'=>$stored, 'name'=>basename((string)$imageName)];
            $accepted++;
        }
    }
    if (!importWriteJsonFile($manifestPath, $manifest)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'stage_failed','message'=>'บันทึกรายการไฟล์ไม่สำเร็จ']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['ok'=>true,'accepted'=>$accepted,'staged_images'=>count($manifest['images']),'has_data'=>!empty($manifest['data']),'errors'=>$errors], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['library_chunk']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    importAjaxRateLimit(240);
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'database_unavailable']); exit; }
    $upload = avsfCollectUploadedImageFiles(['library_images'], 12, 25 * 1024 * 1024);
    $groupData = importBuildExistingPostImageGroups($conn, $upload['images']);
    $grouped = [];
    foreach ($groupData['groups'] as $group) {
        foreach ((array)($group['images'] ?? []) as $image) {
            $real = !empty($image['path']) ? realpath($image['path']) : false;
            $grouped[$real !== false ? $real : (string)($image['path'] ?? '')] = true;
        }
    }
    $updated = 0; $linked = 0; $saved = 0;
    foreach ($groupData['groups'] as $group) {
        try {
            $result = importApplyImageOnlyGroup($conn, $group);
            if (($result['status'] ?? '') === 'updated') { $updated++; $linked += (int)($result['images'] ?? 0); }
        } catch (Throwable $error) { $upload['errors'][] = $error->getMessage(); }
    }
    foreach ($upload['images'] as $image) {
        $real = realpath((string)$image['path']);
        $key = $real !== false ? $real : (string)$image['path'];
        if (isset($grouped[$key])) continue;
        if (avsfStoreImportImage($image['path'], '', $image['name']) !== '') $saved++;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'saved'=>$saved + $linked,'updated_posts'=>$updated,'linked_images'=>$linked,'unmatched'=>(int)$groupData['unmatched'],'errors'=>$upload['errors']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['relink_disk']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    importAjaxRateLimit(120);
    $token = importRequireJobToken();
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'database_unavailable']); exit; }
    $dir = importStageDirectory($token);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        http_response_code(500); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'stage_failed','message'=>'สร้างคิวเชื่อมรูปไม่สำเร็จ']); exit;
    }
    $jobPath = $dir . DIRECTORY_SEPARATOR . 'relink-job.json';
    $job = importReadJsonFile($jobPath);
    if (!is_array($job) || !isset($job['groups'])) {
        $images = importCollectLibraryImages();
        $groupData = importBuildExistingPostImageGroups($conn, $images);
        $job = ['next'=>0,'groups'=>$groupData['groups'],'unmatched'=>(int)$groupData['unmatched'],'ambiguous'=>(int)$groupData['ambiguous'],'updated'=>0,'linked'=>0,'skipped'=>0,'errors'=>0,'messages'=>[]];
    }
    $groups = (array)$job['groups'];
    $total = count($groups);
    $start = max(0, (int)($job['next'] ?? 0));
    foreach (array_slice($groups, $start, 8) as $group) {
        try {
            $result = importApplyImageOnlyGroup($conn, $group);
            if (($result['status'] ?? '') === 'updated') { $job['updated']++; $job['linked'] += (int)($result['images'] ?? 0); }
            else $job['skipped']++;
        } catch (Throwable $error) {
            $job['errors']++;
            if (count($job['messages']) < 30) $job['messages'][] = $error->getMessage();
        }
    }
    $job['next'] = min($total, $start + 8);
    $done = $job['next'] >= $total;
    $promoted = 0;
    if ($done) $promoted = importPromoteEmptyCovers($conn);
    if (!$done && !importWriteJsonFile($jobPath, $job)) {
        http_response_code(500); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'stage_failed','message'=>'บันทึกคิวเชื่อมรูปไม่สำเร็จ']); exit;
    }
    if ($done) avsfSafeRemoveTree($dir);
    $message = $done
        ? ('เชื่อมรูปที่มีอยู่แล้วเสร็จ — อัปเดต ' . (int)$job['updated'] . ' เรื่อง, เชื่อม ' . (int)$job['linked'] . ' ไฟล์, เติมรูปปกจาก Gallery ' . $promoted . ' เรื่อง, ไม่พบ Code ' . (int)$job['unmatched'] . ' ไฟล์')
        : ('กำลังเชื่อมรูปตาม Code ' . (int)$job['next'] . '/' . $total . ' เรื่อง');
    if ($done) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $_SESSION['avsf_import_flash'] = ['message'=>$message, 'results'=>[]];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'done'=>$done,'processed'=>(int)$job['next'],'total'=>$total,'message'=>$message,'errors'=>$job['messages']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $importJobToken = bin2hex(random_bytes(24));
    if (!isset($_SESSION['avsf_import_jobs']) || !is_array($_SESSION['avsf_import_jobs'])) $_SESSION['avsf_import_jobs'] = [];
    $_SESSION['avsf_import_jobs'][$importJobToken] = time();
    foreach ($_SESSION['avsf_import_jobs'] as $tokenKey=>$issuedAt) if ((int)$issuedAt < time()-43200) unset($_SESSION['avsf_import_jobs'][$tokenKey]);
    if (count($_SESSION['avsf_import_jobs']) > 8) $_SESSION['avsf_import_jobs'] = array_slice($_SESSION['avsf_import_jobs'], -8, null, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    $usingStage = sec_post('use_stage') === '1';
    if ($usingStage) importAjaxRateLimit(240);
    else sec_rate_limit('import', 30);
    $activeImportJobToken = sec_post('job_token');
    $jobs = $_SESSION['avsf_import_jobs'] ?? [];
    $issuedAt = is_array($jobs) ? (int)($jobs[$activeImportJobToken] ?? 0) : 0;
    if ($activeImportJobToken === '' || !preg_match('/^[a-f0-9]{48}$/', $activeImportJobToken) || $issuedAt < time()-21600) {
        if ($usingStage) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>'invalid_token','message'=>'คำขอนำเข้าหมดอายุ กรุณาเปิดหน้านี้ใหม่แล้วเริ่มอีกครั้ง'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(403); exit(__('คำขอนำเข้าไม่ถูกต้องหรือหมดอายุ'));
    }
    importProgressUpdate($activeImportJobToken, 2, __('รับคำขอแล้ว กำลังอ่านข้อมูลและตรวจไฟล์…'));
    // ปลด session lock เพื่อให้หน้าจออื่นใน session เดียวกันอ่านสถานะความคืบหน้าได้
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);
    $imageOnlyMode = sec_post('image_only') === '1' || sec_post('image_only_submit') === '1';
    $publishNow = sec_post('publish_now') === '1';
    $status = $publishNow ? 'publish' : 'draft';
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn) { die(__('ไม่สามารถเชื่อมต่อฐานข้อมูลได้')); }

    // รับข้อมูลจากไฟล์อัปโหลด หรือวาง JSON
    $rows = [];
    $importImagePath = '';
    $importImagePaths = [];
    $importExtractRoot = '';

    if ($usingStage) {
        $stageDir = importStageDirectory($activeImportJobToken);
        if (!is_dir($stageDir) && !@mkdir($stageDir, 0700, true) && !is_dir($stageDir)) $msg = 'สร้างพื้นที่พักไฟล์นำเข้าไม่สำเร็จ';
        $manifest = importReadJsonFile($stageDir . DIRECTORY_SEPARATOR . 'manifest.json');
        $rowsFile = $stageDir . DIRECTORY_SEPARATOR . 'rows.json';
        $batchState = importReadJsonFile($stageDir . DIRECTORY_SEPARATOR . 'batch-state.json');
        $importBatchOffset = is_array($batchState) ? (int)($batchState['next'] ?? 0) : 0;
        $importBatchLimit = 25;
        if ($importBatchOffset === 0 && !is_file($rowsFile)) {
            $extraImages = [];
            if (is_array($manifest) && !empty($manifest['data']['path']) && is_file((string)$manifest['data']['path'])) {
                $parsed = importParseDataFile((string)$manifest['data']['path'], (string)($manifest['data']['name'] ?? ''), $extraImages);
                $rows = is_array($parsed['rows']) ? $parsed['rows'] : [];
                if ($parsed['error'] !== '' && !$rows && !$imageOnlyMode) $msg = $parsed['error'];
            } elseif (!$imageOnlyMode && trim((string)($_POST['json_data'] ?? '')) !== '') {
                $decoded = json_decode((string)$_POST['json_data'], true);
                if (is_array($decoded)) $rows = $decoded;
                else $msg = __('JSON ไม่ถูกต้อง');
            }
            if ($msg === '' && !$imageOnlyMode && !importWriteJsonFile($rowsFile, $rows)) $msg = 'บันทึกชุดข้อมูลระหว่างนำเข้าไม่สำเร็จ';
            if ($extraImages) importWriteJsonFile($stageDir . DIRECTORY_SEPARATOR . 'extra-images.json', $extraImages);
        } elseif (is_file($rowsFile)) {
            $cachedRows = json_decode((string)file_get_contents($rowsFile), true);
            if (is_array($cachedRows)) $rows = $cachedRows;
        }
        if (is_array($manifest)) {
            foreach ((array)($manifest['images'] ?? []) as $image) {
                if (!empty($image['path']) && is_file($image['path'])) $importImagePaths[] = ['path'=>$image['path'], 'name'=>(string)($image['name'] ?? basename((string)$image['path']))];
            }
        }
        $extraCached = importReadJsonFile($stageDir . DIRECTORY_SEPARATOR . 'extra-images.json');
        if (is_array($extraCached)) {
            foreach ($extraCached as $image) {
                if (!empty($image['path']) && is_file($image['path'])) $importImagePaths[] = ['path'=>$image['path'], 'name'=>(string)($image['name'] ?? basename((string)$image['path']))];
            }
        }
    } else {

    // รองรับทั้งรูปภาพหลายไฟล์และโหมดเลือกโฟลเดอร์พร้อมกันกับไฟล์ข้อมูล
    foreach(['import_files','import_images','import_folder'] as $imageField) {
        if(empty($_FILES[$imageField]['tmp_name']))continue;
        $fileNames=is_array($_FILES[$imageField]['name'])?$_FILES[$imageField]['name']:[$_FILES[$imageField]['name']];
        $tmpNames=is_array($_FILES[$imageField]['tmp_name'])?$_FILES[$imageField]['tmp_name']:[$_FILES[$imageField]['tmp_name']];
        $errors=is_array($_FILES[$imageField]['error'])?$_FILES[$imageField]['error']:[$_FILES[$imageField]['error']];
        $sizes=is_array($_FILES[$imageField]['size'])?$_FILES[$imageField]['size']:[$_FILES[$imageField]['size']];
        foreach($fileNames as $imageIndex=>$imageName){
            if((int)($errors[$imageIndex]??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)continue;
            if((int)($sizes[$imageIndex]??0)>25*1024*1024)continue;
            if(count($importImagePaths)>=3000)break;
            $imageExt=strtolower(pathinfo((string)$imageName,PATHINFO_EXTENSION));
            if(in_array($imageExt,['jpg','jpeg','png','webp','gif','avif'],true))$importImagePaths[]=['path'=>$tmpNames[$imageIndex],'name'=>$imageName];
        }
    }
    // The main picker is `multiple`; normalize it before the legacy scalar
    // parser below so no-JS form submission also handles data + many images.
    if (!empty($_FILES['import_file']) && is_array($_FILES['import_file'])) {
        $mainUpload = importNormalizeMainUploadFiles($_FILES['import_file'], 3000, 25 * 1024 * 1024);
        foreach ($mainUpload['images'] as $mainImage) $importImagePaths[] = $mainImage;
        foreach ($mainUpload['errors'] as $uploadError) {
            $msg = trim($msg . ($msg !== '' ? ' · ' : '') . $uploadError);
        }
        if (is_array($mainUpload['data'])) $_FILES['import_file'] = $mainUpload['data'];
        else unset($_FILES['import_file']);
    }
    if (!empty($_FILES['import_file']['tmp_name']) && is_string($_FILES['import_file']['tmp_name'])) {
        $tmp = $_FILES['import_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        $maxImportFileBytes = ($ext === 'zip') ? (700 * 1024 * 1024) : (50 * 1024 * 1024);
        if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK || (int)$_FILES['import_file']['size'] > $maxImportFileBytes) {
            $msg = ($ext === 'zip') ? __('อัปโหลด ZIP ไม่สำเร็จหรือไฟล์มีขนาดเกิน 700 MB (ยังขึ้นกับ upload_max_filesize และ post_max_size ของเซิร์ฟเวอร์)') : __('อัปโหลดไม่สำเร็จหรือไฟล์มีขนาดเกิน 50 MB');
        } elseif ($ext === 'csv') {
            $handle = fopen($tmp, 'r');
            if ($handle === false) {
                $msg = __('เปิดไฟล์ CSV ไม่สำเร็จ');
            } else {
                $headers = fgetcsv($handle);
                if (is_array($headers) && isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
                if (!is_array($headers) || !$headers) {
                    $msg = __('ไฟล์ CSV ไม่มีแถวหัวคอลัมน์');
                } else {
                    while (($data = fgetcsv($handle)) !== false) {
                        if (count($data) === count($headers)) {
                            $rows[] = array_combine($headers, $data);
                        }
                    }
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            try { $rows = importXlsxRows($tmp); }
            catch (Throwable $e) { $msg = $e->getMessage(); }
        } elseif ($ext === 'zip') {
            $extract = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avsf-import-' . bin2hex(random_bytes(6));
            $importExtractRoot = $extract;
            try {
                avsfSafeExtractZip($tmp, $extract, ['max_entries'=>4000, 'max_file_bytes'=>50*1024*1024, 'max_total_bytes'=>700*1024*1024, 'max_ratio'=>150]);
                $dataFiles = [];
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extract, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->isLink()) continue;
                    $fileExt = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                    if (in_array($fileExt, ['xlsx','csv','json','txt'], true)) $dataFiles[] = $file->getPathname();
                    elseif (in_array($fileExt, ['jpg','jpeg','png','webp','gif','avif'], true)) $importImagePaths[] = ['path'=>$file->getPathname(), 'name'=>$file->getFilename()];
                }
                sort($dataFiles, SORT_NATURAL | SORT_FLAG_CASE);
                $dataFile = $dataFiles[0] ?? '';
                if ($dataFile === '') { if (!$imageOnlyMode) $msg = __('ZIP ต้องมีไฟล์ XLSX, CSV, JSON หรือ TXT'); }
                elseif (strtolower(pathinfo($dataFile, PATHINFO_EXTENSION)) === 'xlsx') { try { $rows = importXlsxRows($dataFile); } catch (Throwable $e) { $msg = $e->getMessage(); } }
                elseif (strtolower(pathinfo($dataFile, PATHINFO_EXTENSION)) === 'csv') {
                    if (($h = fopen($dataFile, 'r'))) { $headers = fgetcsv($h); if (is_array($headers) && isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]); while (($d = fgetcsv($h)) !== false) if (is_array($headers) && count($d) === count($headers)) $rows[] = array_combine($headers, $d); fclose($h); }
                } elseif (strtolower(pathinfo($dataFile, PATHINFO_EXTENSION)) === 'json') {
                    $decoded = json_decode((string)file_get_contents($dataFile), true);
                    if (is_array($decoded)) $rows = $decoded; else $msg = __('ไฟล์ JSON ใน ZIP มีรูปแบบไม่ถูกต้อง');
                } elseif (strtolower(pathinfo($dataFile, PATHINFO_EXTENSION)) === 'txt') {
                    foreach (file($dataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) { $parts = explode('|', $line); $rows[] = ['code'=>trim($parts[0]??''),'duration'=>trim($parts[1]??''),'video_url'=>trim($parts[2]??''),'title'=>trim($parts[3]??'')]; }
                }
            } catch (Throwable $zipError) { $msg = __('อ่าน ZIP ไม่สำเร็จ: ') . $zipError->getMessage(); }
        } elseif (in_array($ext,['jpg','jpeg','png','webp','gif','avif'],true)) {
            $importImagePath=$tmp;
            $importImagePaths[]=['path'=>$tmp,'name'=>$_FILES['import_file']['name']];
            $msg=__('รูปภาพพร้อมใช้งานร่วมกับไฟล์ข้อมูลในคำขอเดียวกัน');
        } elseif ($ext === 'json') {
            $rows = json_decode(file_get_contents($tmp), true);
        } elseif ($ext === 'txt') {
            foreach (file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $parts = explode('|', $line);
                // format: CODE|duration|video_url|title
                $rows[] = [
                    'code' => trim($parts[0] ?? ''),
                    'duration' => trim($parts[1] ?? ''),
                    'video_url' => trim($parts[2] ?? ''),
                    'title' => trim($parts[3] ?? ''),
                ];
            }
        } else {
            $msg = __('รองรับเฉพาะไฟล์ CSV, JSON และ TXT');
        }
    } elseif (!empty($_POST['json_data'])) {
        $rows = json_decode(sec_post('json_data'), true);
    } elseif (!$imageOnlyMode && $importImagePaths) {
        $msg = trim($msg . ($msg !== '' ? ' · ' : '') . __('หากต้องการผูกรูปเข้ากับเรื่องเดิม ให้เลือกปุ่ม “นำเข้ารูปอย่างเดียว จับคู่เรื่องเดิมด้วย Code”'));
    }
    }

    if ($imageOnlyMode) {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);
        try {
            if (!$importImagePaths) throw new RuntimeException('ไม่พบรูปภาพ กรุณาเลือกหลายภาพหรือ ZIP ที่มีรูปภาพ');
            importProgressUpdate($activeImportJobToken, 8, 'ตรวจสอบ Code ของเรื่องเดิมและเตรียมไฟล์รูปภาพ…', 0, 0);
            $groupData = importBuildExistingPostImageGroups($conn, $importImagePaths);
            $groupCount = importStageImageOnlyJob($activeImportJobToken, $groupData);
            if ($groupCount < 1) {
                $unmatched = (int)($groupData['unmatched'] ?? 0);
                $ambiguous = (int)($groupData['ambiguous'] ?? 0);
                if ($importExtractRoot !== '' && is_dir($importExtractRoot)) avsfSafeRemoveTree($importExtractRoot);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error'=>'no_matching_code','message'=>'ไม่พบภาพที่จับคู่กับ Code ของเรื่องเดิมได้ (ไม่พบ ' . $unmatched . ' ไฟล์, Code ซ้ำ ' . $ambiguous . ' ไฟล์)'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                exit;
            }
            if ($importExtractRoot !== '' && is_dir($importExtractRoot)) avsfSafeRemoveTree($importExtractRoot);
            if ($usingStage) {
                $stageToClean = importStageDirectory($activeImportJobToken);
                if (is_dir($stageToClean)) avsfSafeRemoveTree($stageToClean);
            }
            importProgressUpdate($activeImportJobToken, 10, 'เตรียมคิวจับคู่รูปภาพแล้ว เริ่มประมวลผลแบบชุดย่อย…', 0, $groupCount);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo json_encode(['image_job'=>true,'token'=>$activeImportJobToken,'total'=>$groupCount,'message'=>'เตรียมไฟล์รูปภาพแล้ว กำลังจับคู่กับเรื่องเดิม'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $imageError) {
            if ($importExtractRoot !== '' && is_dir($importExtractRoot)) avsfSafeRemoveTree($importExtractRoot);
            importProgressUpdate($activeImportJobToken, 100, $imageError->getMessage(), 0, 0);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>'image_job_prepare_failed','message'=>$imageError->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    if (empty($rows) || !is_array($rows)) {
        if ($msg === '') $msg = __('ไม่พบข้อมูลที่จะนำเข้า');
        importProgressUpdate($activeImportJobToken, 95, $msg, 0, 0);
    } else {
        $totalImportRows = count($rows);
        importProgressUpdate($activeImportJobToken, 10, __('อ่านข้อมูลสำเร็จ กำลังจับคู่ภาพตาม Code…'), $importBatchOffset, $totalImportRows);
        $ok = 0; $fail = 0; $updated = 0; $skipped = 0;
        $normalizeNames = function($value) {
            $values = is_array($value) ? $value : [$value];
            $names = [];
            $seen = [];
            foreach ($values as $entry) {
                foreach (splitTaxonomyNames((string)$entry) as $name) {
                    $key = mb_strtolower($name, 'UTF-8');
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $names[] = $name;
                }
            }
            return $names;
        };
        $findOrCreateTerm = function($table, $name) use ($conn) {
            if (!in_array($table, ['studios', 'actors', 'tags', 'categories'], true)) {
                throw new InvalidArgumentException('Unsupported taxonomy table');
            }
            $maxLength = $table === 'categories' ? 150 : 100;
            if ($name === '' || mb_strlen($name, 'UTF-8') > $maxLength) {
                throw new RuntimeException('ชื่อรายการใน '.$table.__(' ว่างหรือยาวเกินกำหนด'));
            }
            $lookup = $conn->prepare("SELECT id FROM `$table` WHERE name=? LIMIT 1");
            if (!$lookup) throw new RuntimeException('ค้นหารายการใน '.$table.__(' ไม่สำเร็จ: ').$conn->error);
            $lookup->bind_param('s', $name);
            if (!$lookup->execute()) {
                $error = $lookup->error;
                $lookup->close();
                throw new RuntimeException('ค้นหารายการใน '.$table.__(' ไม่สำเร็จ: ').$error);
            }
            $lookup->bind_result($termId);
            $found = $lookup->fetch();
            $lookup->close();
            if ($found) return (int)$termId;
            $slug = uniqueSlugForName($conn, $table, $name);
            $insert = $conn->prepare("INSERT INTO `$table` (name,slug) VALUES (?,?)");
            if (!$insert) throw new RuntimeException('เตรียมบันทึกรายการใน '.$table.__(' ไม่สำเร็จ: ').$conn->error);
            $insert->bind_param('ss', $name, $slug);
            if (!$insert->execute()) {
                $error = $insert->error;
                $insert->close();
                throw new RuntimeException('บันทึกรายการใน '.$table.__(' ไม่สำเร็จ: ').$error);
            }
            $id = (int)$insert->insert_id;
            $insert->close();
            return $id;
        };

        foreach ($rows as $i => $row) {
            if ($importBatchLimit > 0 && $i < $importBatchOffset) continue;
            if ($importBatchLimit > 0 && $i >= $importBatchOffset + $importBatchLimit) break;
            $rowNumber = $i + 1;
            $rowProgress = 12 + (int)floor((($i) / max(1,$totalImportRows)) * 82);
            $rowCodeForStatus = is_array($row) ? (string)($row['Code'] ?? $row['code'] ?? $row['movie_code'] ?? '') : '';
            importProgressUpdate($activeImportJobToken, $rowProgress, __('กำลังตรวจสอบและจับคู่รูป: ') . ($rowCodeForStatus !== '' ? $rowCodeForStatus : __('รายการ ') . $rowNumber), $rowNumber-1, $totalImportRows);
            if (!is_array($row)) {
                $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => __('รูปแบบข้อมูลในแถวไม่ถูกต้อง')];
                $fail++; continue;
            }
            $sourceRow=$row;
            foreach ($sourceRow as $key => $value) {
                $mappedField=function_exists('advancedDraftFieldKey')?advancedDraftFieldKey($key):'';
                $normalizedKey=strtolower(trim((string)$key));
                $normalizedKey=preg_replace('/[^a-z0-9]+/i','_',trim($normalizedKey));
                if($mappedField==='tags')$normalizedKey='tags';
                elseif($mappedField==='categories')$normalizedKey='categories';
                elseif($mappedField==='actor')$normalizedKey='actor';
                elseif($mappedField==='actor_en')$normalizedKey='actor_en';
                if($normalizedKey==='tag'||$normalizedKey==='post_tag'||$normalizedKey==='post_tags'||$normalizedKey==='tag_name'||$normalizedKey==='tag_names')$normalizedKey='tags';
                if($normalizedKey==='tags' && isset($row['tags']) && trim((string)$row['tags'])!=='' && trim((string)$value)!=='')$row['tags'].="\n".(string)$value;
                else $row[$normalizedKey]=$value;
            }
            $row['code']=$row['code']??$row['movie_code']??$row['video_code']??'';
            $row['title']=$row['title']??$row['name']??'';
            $row['video_url']=$row['video_url']??$row['videourl']??$row['url']??'';
            $row['embed_url']=$row['embed_url']??$row['embedurl']??$row['embed']??'';
            // map fields (รองรับหัวตารางตัวอย่าง: index, Code, Focus Keyphrase, Actor, Tags, Categories ...)
            $title = $row['title'] ?? $row['name'] ?? '';
            $code = $row['code'] ?? '';
            $videoUrl = $row['video_url'] ?? $row['videoUrl'] ?? $row['url'] ?? '';
            $embedUrl = trim((string)($row['embed_url'] ?? $row['embedurl'] ?? $row['embed'] ?? ''));
            $coverInput = trim((string)($row['cover'] ?? $row['cover_url'] ?? $row['cover_image'] ?? $row['image'] ?? $row['thumbnail'] ?? ''));
            $posterInput = trim((string)($row['poster_url'] ?? $row['poster'] ?? ''));
            $galleryInput = $row['gallery'] ?? $row['gallery_urls'] ?? [];
            $matchedAssets = importFindMatchingImages($importImagePaths, $code, $coverInput, $posterInput);
            $cover = importResolveImageValue($coverInput, $importImagePaths, $code, 'cover');
            $posterUrl = importResolveImageValue($posterInput, $importImagePaths, $code, 'poster');
            $usedImagePaths = [];
            foreach ($matchedAssets as $asset) {
                $assetRole = (string)($asset['role'] ?? 'gallery');
                $assetKey = $asset['_real'] ?? (realpath($asset['path']) ?: $asset['path']);
                if ($assetRole === 'cover') {
                    if ($cover === '') $cover = importMediaFile($asset['path'], $code, 'uploads/import-media', $asset['name']);
                    if ($cover !== '') $usedImagePaths[$assetKey] = true;
                } elseif ($assetRole === 'poster') {
                    if ($posterUrl === '') $posterUrl = importMediaFile($asset['path'], $code, 'uploads/import-media', $asset['name']);
                    if ($posterUrl !== '') $usedImagePaths[$assetKey] = true;
                }
            }
            // Do not assign arbitrary unmatched files as cover: image roles are determined only by Code/filename or explicit fields.
            if ($posterUrl === '' && $matchedAssets) {
                foreach ($matchedAssets as $asset) {
                    if (($asset['role'] ?? '') === 'poster') {
                        $posterUrl = importMediaFile($asset['path'], $code, 'uploads/import-media', $asset['name']);
                        if ($posterUrl !== '') $usedImagePaths[$asset['_real'] ?? realpath($asset['path'])] = true;
                        break;
                    }
                }
            }
            $galleryUrls = [];
            if (is_string($galleryInput) && trim($galleryInput) !== '') {
                $decodedGallery = json_decode($galleryInput, true);
                $galleryValues = is_array($decodedGallery) ? $decodedGallery : preg_split('/\r\n|\r|\n|[,;]+/', $galleryInput);
            } elseif (is_array($galleryInput)) $galleryValues = $galleryInput;
            else $galleryValues = [];
            foreach ($galleryValues as $galleryValue) {
                $galleryValue = trim((string)$galleryValue);
                if ($galleryValue === '') continue;
                $resolvedGallery = importResolveImageValue($galleryValue, $importImagePaths, $code, 'gallery');
                if ($resolvedGallery !== '') $galleryUrls[] = $resolvedGallery;
            }
            foreach ($matchedAssets as $asset) {
                $realAsset = $asset['_real'] ?? realpath($asset['path']);
                if (isset($usedImagePaths[$realAsset])) continue;
                $assetUrl = importMediaFile($asset['path'], $code, 'uploads/import-media', $asset['name']);
                if ($assetUrl !== '') { $galleryUrls[] = $assetUrl; $usedImagePaths[$realAsset] = true; }
            }
            $galleryUrls = array_values(array_unique($galleryUrls));
            if ($cover === '' && $galleryUrls) $cover = (string)$galleryUrls[0];
            $contentValue = $row['content'] ?? $row['desc'] ?? $row['description'] ?? '';
            $content = is_string($contentValue) || is_numeric($contentValue) ? (string)$contentValue : ''; // retain original paragraph breaks and spacing
            $studioNames = $normalizeNames($row['studio'] ?? $row['studio_name'] ?? $row['studioname'] ?? '');
            $actorSource = $row['actor'] ?? $row['actors'] ?? $row['actor_name'] ?? '';
            $actorEnSource = $row['actor_en'] ?? $row['actoren'] ?? $row['actor_en_name'] ?? '';
            if (is_array($actorEnSource)) $actorEN = implode(', ', $normalizeNames($actorEnSource));
            else $actorEN = trim((string)$actorEnSource);
            $actorNames = $normalizeNames($actorSource);
            $category = $row['category'] ?? $row['categories'] ?? '';
            $tags = $row['tags'] ?? $row['tag'] ?? $row['post_tag'] ?? $row['post_tags'] ?? $row['tag_name'] ?? $row['tag_names'] ?? $row['แท็ก'] ?? '';
            $categoryNames = $normalizeNames($category);
            $tagNames = function_exists('advancedDraftTaxonomyNames') ? advancedDraftTaxonomyNames($tags) : $normalizeNames($tags);
            if (trim(is_array($tags)?json_encode($tags,JSON_UNESCAPED_UNICODE):(string)$tags)!=='' && !$tagNames) { $results[]=['row'=>$i+1,'status'=>'error','reason'=>__('อ่านคอลัมน์ Tag/Tags ไม่สำเร็จ กรุณาตรวจรูปแบบข้อมูล')]; $fail++; continue; }
            $duration = $row['duration'] ?? '';
            $focus = $row['focus'] ?? $row['focuskeyphrase'] ?? $row['focus_keyphrase'] ?? '';
            $seoTitle = $row['seo_title'] ?? $row['meta_title'] ?? $row['seotitle'] ?? '';
            $metaDesc = $row['meta_description'] ?? $row['metadescription'] ?? $row['meta_desc'] ?? '';
            $defaultAlt = trim((string)$code.(trim((string)$actorEN)!==''?' '.trim((string)$actorEN):''));
            $coverAlt = trim((string)($row['cover_alt'] ?? $row['coveralt'] ?? $row['alt'] ?? $defaultAlt));
            $posterAlt = trim((string)($row['poster_alt'] ?? $row['posteralt'] ?? $defaultAlt));
            $galleryAltInput = $row['gallery_alt'] ?? $row['galleryalt'] ?? [];
            if (is_string($galleryAltInput)) {
                $decodedAlt = json_decode($galleryAltInput, true);
                $galleryAltValues = is_array($decodedAlt) ? $decodedAlt : preg_split('/\r\n|\r|\n/', $galleryAltInput);
            } elseif (is_array($galleryAltInput)) $galleryAltValues = $galleryAltInput;
            else $galleryAltValues = [];
            $galleryAltValues = array_values(array_map(function($value) { return trim((string)$value); }, $galleryAltValues));
            while (count($galleryAltValues) < count($galleryUrls)) $galleryAltValues[] = $defaultAlt;
            $galleryAltValues = array_slice($galleryAltValues, 0, count($galleryUrls));
            $galleryAlt = json_encode($galleryAltValues, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $dateValue = $row['post_date'] ?? $row['date'] ?? date('Y-m-d');
            $dateTimestamp = strtotime((string)$dateValue);

            if (empty($title) && empty($videoUrl)) {
                $results[] = ['row' => $i+1, 'status' => 'skip', 'reason' => __('ไม่มี title หรือ video_url')];
                $fail++; continue;
            }
            if ($dateTimestamp === false) {
                $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => __('วันที่ไม่ถูกต้อง')];
                $fail++; continue;
            }
            $date = date('Y-m-d', $dateTimestamp);

            if (count($studioNames) > 1) {
                $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => __('โพสต์รองรับหนึ่งค่ายต่อเรื่อง กรุณาแยกค่ายก่อนนำเข้า')];
                $fail++; continue;
            }

            $rowTransactionStarted = false;
            importProgressUpdate($activeImportJobToken, min(96,$rowProgress+3), __('กำลังบันทึกโพสต์และ Taxonomy: ') . (trim((string)$code) !== '' ? $code : __('รายการ ') . $rowNumber), $rowNumber-1, $totalImportRows);
            try {
                if (!$conn->begin_transaction()) throw new RuntimeException('เริ่ม Transaction สำหรับแถวนี้ไม่สำเร็จ');
                $rowTransactionStarted = true;
                $hasExplicitSlug = trim((string)($row['slug'] ?? '')) !== '';
                $slugSource = $hasExplicitSlug ? trim((string)$row['slug']) : (trim((string)$code) !== '' ? $code : $title);
                $slugBase = trim(mb_substr(slugify($slugSource),0,200,'UTF-8'),'-') ?: 'item';
                $existingId = 0;
                $existingSlug = '';
                if (trim((string)$code) !== '') {
                    $codeLookup = $conn->prepare("SELECT id,slug FROM posts WHERE code=? AND code<>'' ORDER BY id ASC LIMIT 1");
                    if (!$codeLookup) throw new RuntimeException('ค้นหา Code เดิมไม่สำเร็จ: '.$conn->error);
                    $codeLookup->bind_param('s', $code);
                    if (!$codeLookup->execute()) { $error=$codeLookup->error; $codeLookup->close(); throw new RuntimeException('ค้นหา Code เดิมไม่สำเร็จ: '.$error); }
                    $codeLookup->bind_result($foundId, $foundSlug);
                    if ($codeLookup->fetch()) { $existingId=(int)$foundId; $existingSlug=(string)$foundSlug; }
                    $codeLookup->close();
                }
                if (!$existingId) {
                    $slugLookup = $conn->prepare('SELECT id,slug FROM posts WHERE slug=? LIMIT 1');
                    if (!$slugLookup) throw new RuntimeException('ค้นหา Slug เดิมไม่สำเร็จ: '.$conn->error);
                    $slugLookup->bind_param('s',$slugBase);
                    if (!$slugLookup->execute()) { $error=$slugLookup->error; $slugLookup->close(); throw new RuntimeException('ค้นหา Slug เดิมไม่สำเร็จ: '.$error); }
                    $slugLookup->bind_result($foundId, $foundSlug);
                    if ($slugLookup->fetch()) { $existingId=(int)$foundId; $existingSlug=(string)$foundSlug; }
                    $slugLookup->close();
                }
                if ($existingId && !$hasExplicitSlug && $existingSlug !== '') $slug = $existingSlug;
                elseif ($existingId) {
                    $collision = $conn->prepare('SELECT id FROM posts WHERE slug=? AND id<>? LIMIT 1');
                    if (!$collision) throw new RuntimeException('ตรวจสอบ Slug ซ้ำไม่สำเร็จ: '.$conn->error);
                    $collision->bind_param('si', $slugBase, $existingId);
                    if (!$collision->execute()) { $error=$collision->error; $collision->close(); throw new RuntimeException('ตรวจสอบ Slug ซ้ำไม่สำเร็จ: '.$error); }
                    $collision->store_result(); $hasCollision = $collision->num_rows > 0; $collision->close();
                    $slug = $hasCollision ? uniqueSlugForName($conn,'posts',$slugSource) : $slugBase;
                } else $slug = uniqueSlugForName($conn,'posts',$slugSource);
                $studioId = $studioNames ? $findOrCreateTerm('studios', $studioNames[0]) : 0;
                $actorIds = [];
                foreach ($actorNames as $actorName) $actorIds[] = $findOrCreateTerm('actors', $actorName);
                $tagIds = [];
                foreach ($tagNames as $tagName) $tagIds[] = $findOrCreateTerm('tags', $tagName);
                foreach ($categoryNames as $categoryName) $findOrCreateTerm('categories', $categoryName);
            } catch (Throwable $error) {
                if ($rowTransactionStarted) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
                $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => $error->getMessage()];
                $fail++; continue;
            }
            $category = implode(', ', $categoryNames);
            $tags = implode(', ', $tagNames);
            $actorIdsJson = json_encode($actorIds);
            $tagIdsJson = json_encode($tagIds);
            $keywords = trim((string)($row['keywords'] ?? $tags));
            $metaTitle = $seoTitle;

            if ($existingId) {
                $lookup = $conn->prepare('SELECT code,title,slug,content,cover,coverAlt,posterUrl,posterAlt,gallery,galleryAlt,videoUrl,embedUrl,videoDuration,studioId,actorEN,category,focusKeyphrase,metaTitle,metaDesc,keywords,status,date,actorIds,tagIds FROM posts WHERE id=? LIMIT 1');
                if (!$lookup) { $conn->rollback(); $results[]=['row'=>$i+1,'status'=>'error','reason'=>__('อ่านโพสต์เดิมไม่สำเร็จ: ').$conn->error]; $fail++; continue; }
                $lookup->bind_param('i',$existingId);
                if (!$lookup->execute()) { $error=$lookup->error; $lookup->close(); $conn->rollback(); $results[]=['row'=>$i+1,'status'=>'error','reason'=>__('อ่านโพสต์เดิมไม่สำเร็จ: ').$error]; $fail++; continue; }
                $old = $lookup->get_result()->fetch_assoc(); $lookup->close();
                if ($old) {
                    if (!$tagNames) {
                        $tagIdsJson=json_encode(parseTaxonomyIds($old['tagIds']??'[]'),JSON_UNESCAPED_UNICODE);
                    }
                    if ($cover === '') $cover = (string)($old['cover'] ?? '');
                    if ($posterUrl === '') $posterUrl = (string)($old['posterUrl'] ?? '');
                    if ($embedUrl === '') $embedUrl = (string)($old['embedUrl'] ?? '');
                    if (!$galleryUrls && empty($galleryInput)) {
                        $oldGallery = json_decode((string)($old['gallery'] ?? '[]'), true);
                        $galleryUrls = is_array($oldGallery) ? $oldGallery : [];
                        $oldGalleryAlt = json_decode((string)($old['galleryAlt'] ?? '[]'), true);
                        if (is_array($oldGalleryAlt)) $galleryAlt = json_encode($oldGalleryAlt, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                        $galleryJson = json_encode($galleryUrls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    }
                }
                $galleryJson = json_encode($galleryUrls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $incoming = [$code,$title,$slug,$content,$cover,$coverAlt,$posterUrl,$posterAlt,$videoUrl,$embedUrl,(string)$duration,$galleryJson,$galleryAlt,(int)$studioId,$actorEN,$category,$focus,$metaTitle,$metaDesc,$keywords,$status,$date,$actorIdsJson,$tagIdsJson];
                $keys = ['code','title','slug','content','cover','coverAlt','posterUrl','posterAlt','videoUrl','embedUrl','videoDuration','gallery','galleryAlt','studioId','actorEN','category','focusKeyphrase','metaTitle','metaDesc','keywords','status','date','actorIds','tagIds'];
                $changed = !$old;
                if ($old) foreach ($keys as $keyIndex=>$key) {
                    if ($key === 'gallery' || $key === 'galleryAlt') {
                        $oldValue = json_decode((string)($old[$key] ?? '[]'), true);
                        $newValue = json_decode((string)$incoming[$keyIndex], true);
                        $isDifferent = (is_array($oldValue) ? $oldValue : []) !== (is_array($newValue) ? $newValue : []);
                    } else $isDifferent = (string)($old[$key] ?? '') !== (string)$incoming[$keyIndex];
                    if ($isDifferent) { $changed=true; break; }
                }
                if (!$changed) {
                    if (!$conn->commit()) { $conn->rollback(); $fail++; $results[]=['row'=>$i+1,'status'=>'error','reason'=>__('ยืนยัน Transaction ไม่สำเร็จ')]; }
                    else { $skipped++; $results[]=['row'=>$i+1,'status'=>'skip','title'=>$title,'reason'=>__('ข้อมูลตรงกันทั้งหมด — ข้ามโพสต์นี้')]; }
                    continue;
                }
                $update = $conn->prepare('UPDATE posts SET code=?,title=?,slug=?,content=?,cover=?,coverAlt=?,posterUrl=?,posterAlt=?,videoUrl=?,embedUrl=?,videoDuration=?,gallery=?,galleryAlt=?,studioId=?,actorEN=?,category=?,focusKeyphrase=?,metaTitle=?,metaDesc=?,keywords=?,status=?,date=?,actorIds=?,tagIds=?,updatedAt=NOW() WHERE id=?');
                if (!$update) { $conn->rollback(); $fail++; $results[]=['row'=>$i+1,'status'=>'error','reason'=>__('เตรียมอัปเดตโพสต์ไม่สำเร็จ: ').$conn->error]; continue; }
                $updateTypes = str_repeat('s',13).'i'.str_repeat('s',10).'i';
                $update->bind_param($updateTypes,$code,$title,$slug,$content,$cover,$coverAlt,$posterUrl,$posterAlt,$videoUrl,$embedUrl,$duration,$galleryJson,$galleryAlt,$studioId,$actorEN,$category,$focus,$metaTitle,$metaDesc,$keywords,$status,$date,$actorIdsJson,$tagIdsJson,$existingId);
                $updatedOk = $update->execute(); $updateError = $update->error; $update->close();
                if ($updatedOk && $conn->commit()) { $updated++; $results[]=['row'=>$i+1,'status'=>'updated','title'=>$title,'reason'=>__('อัปเดตข้อมูลที่มีการเปลี่ยนแปลงแล้ว') . ' · แท็ก ' . count(parseTaxonomyIds($tagIdsJson)) . ' รายการ']; }
                else { $conn->rollback(); $fail++; $results[]=['row'=>$i+1,'status'=>'error','reason'=>$updatedOk?__('ยืนยันการอัปเดตไม่สำเร็จ'):$updateError]; }
                continue;
            }

            // insert
            $galleryJson = json_encode($galleryUrls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $importPlaceholders = implode(',', array_fill(0,24,'?'));
            $stmt = $conn->prepare("INSERT INTO posts (code,title,slug,content,cover,coverAlt,posterUrl,posterAlt,videoUrl,embedUrl,videoDuration,gallery,galleryAlt,studioId,actorEN,category,focusKeyphrase,metaTitle,metaDesc,keywords,status,date,actorIds,tagIds,views,createdAt,updatedAt) VALUES ($importPlaceholders,0,NOW(),NOW())");
            if (!$stmt) {
                $conn->rollback();
                $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => 'prepare fail: ' . $conn->error];
                $fail++; continue;
            }
            $stmt->bind_param(str_repeat('s',13).'i'.str_repeat('s',10),
                $code, $title, $slug, $content, $cover, $coverAlt, $posterUrl, $posterAlt,
                $videoUrl, $embedUrl, $duration, $galleryJson, $galleryAlt, $studioId, $actorEN, $category, $focus,
                $metaTitle, $metaDesc, $keywords, $status, $date, $actorIdsJson, $tagIdsJson
            );
            if ($stmt->execute()) {
                $stmt->close();
                if ($conn->commit()) {
                    $ok++;
                    $results[] = ['row' => $i+1, 'status' => 'ok', 'title' => mb_substr($title,0,50), 'reason' => __('บันทึกข้อมูลและภาพประกอบ ') . (int)(($cover !== '') + ($posterUrl !== '') + count($galleryUrls)) . __(' รายการ') . ' · แท็ก ' . count($tagIds) . ' รายการ'];
                } else {
                    $conn->rollback(); $fail++;
                    $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => __('ยืนยันการบันทึกโพสต์ไม่สำเร็จ')];
                }
            } else {
                $insertError = $stmt->error; $stmt->close(); $conn->rollback();
                $fail++;
                $results[] = ['row' => $i+1, 'status' => 'error', 'reason' => $insertError];
            }
        }
        $msg = __('นำเข้าเสร็จ — เพิ่มใหม่ ') . $ok . __(' เรื่อง, อัปเดต ') . $updated . __(' เรื่อง, ข้าม ') . $skipped . __(' เรื่อง, ล้มเหลว ') . $fail . __(' เรื่อง · บันทึกรูปโดยคงชื่อไฟล์เดิม ') . (int)$importMediaStats['saved'] . __(' รูป');
    }
    if ($usingStage) {
        $stageDir = importStageDirectory($activeImportJobToken);
        $statePath = $stageDir . DIRECTORY_SEPARATOR . 'batch-state.json';
        $prev = importReadJsonFile($statePath);
        if (!is_array($prev)) $prev = [];
        $totalRows = isset($totalImportRows) ? (int)$totalImportRows : (is_array($rows) ? count($rows) : 0);
        $next = ($importBatchLimit > 0 && $totalRows > 0) ? min($totalRows, $importBatchOffset + $importBatchLimit) : $totalRows;
        $totOk = (int)($prev['ok'] ?? 0) + (isset($ok) ? (int)$ok : 0);
        $totUpdated = (int)($prev['updated'] ?? 0) + (isset($updated) ? (int)$updated : 0);
        $totSkipped = (int)($prev['skipped'] ?? 0) + (isset($skipped) ? (int)$skipped : 0);
        $totFail = (int)($prev['fail'] ?? 0) + (isset($fail) ? (int)$fail : 0);
        $totSaved = (int)($prev['saved'] ?? 0) + (int)$importMediaStats['saved'];
        $allResults = array_merge(is_array($prev['results'] ?? null) ? $prev['results'] : [], $results);
        if (count($allResults) > 200) $allResults = array_slice($allResults, -200);
        $done = ($totalRows === 0) || ($next >= $totalRows);
        $finalMsg = ($totalRows === 0 && $msg !== '')
            ? $msg
            : ('นำเข้าเสร็จ — เพิ่มใหม่ ' . $totOk . ' เรื่อง, อัปเดต ' . $totUpdated . ' เรื่อง, ข้าม ' . $totSkipped . ' เรื่อง, ล้มเหลว ' . $totFail . ' เรื่อง · บันทึกรูป ' . $totSaved . ' รูป');
        if (!$done) $finalMsg = 'กำลังนำเข้าต่อเนื่อง ' . $next . '/' . $totalRows . ' เรื่อง';
        importWriteJsonFile($statePath, ['ok'=>$totOk,'updated'=>$totUpdated,'skipped'=>$totSkipped,'fail'=>$totFail,'saved'=>$totSaved,'results'=>$allResults,'next'=>$next]);
        if ($done) {
            $promoted = importPromoteEmptyCovers($conn);
            if ($promoted > 0) $finalMsg .= ' · เติมรูปปกจาก Gallery ' . $promoted . ' เรื่อง';
            importRecordHistory($conn, $activeImportJobToken, 'posts', $totalRows, $totOk, $totUpdated, $totSkipped, $totFail, $totSaved, 0, $finalMsg);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        if ($done) {
            $_SESSION['avsf_import_flash'] = ['message'=>$finalMsg, 'results'=>$allResults];
            if (is_dir($stageDir)) avsfSafeRemoveTree($stageDir);
            if ($importExtractRoot !== '' && is_dir($importExtractRoot)) avsfSafeRemoveTree($importExtractRoot);
            $importExtractRoot = '';
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        $percent = $totalRows > 0 ? (int)floor(($next / $totalRows) * 100) : ($done ? 100 : 0);
        echo json_encode(['batch'=>true,'done'=>$done,'processed'=>$next,'total'=>$totalRows,'percent'=>$percent,'message'=>$finalMsg], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!$usingStage && isset($totalImportRows)) {
        importRecordHistory($conn, $activeImportJobToken, 'posts', (int)$totalImportRows, (int)($ok ?? 0), (int)($updated ?? 0), (int)($skipped ?? 0), (int)($fail ?? 0), (int)($importMediaStats['saved'] ?? 0), 0, $msg);
    }
    importProgressUpdate($activeImportJobToken, 100, $msg !== '' ? $msg : __('นำเข้าจบการทำงานแล้ว'), isset($totalImportRows) ? $totalImportRows : 0, isset($totalImportRows) ? $totalImportRows : 0);
    // เปิด session กลับเพื่อสร้าง token ใหม่ให้ฟอร์มหลังแสดงผลลัพธ์
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $importJobToken = bin2hex(random_bytes(24));
    if (!isset($_SESSION['avsf_import_jobs']) || !is_array($_SESSION['avsf_import_jobs'])) $_SESSION['avsf_import_jobs'] = [];
    $_SESSION['avsf_import_jobs'][$importJobToken] = time();
    foreach ($_SESSION['avsf_import_jobs'] as $tokenKey=>$issuedAt) if ((int)$issuedAt < time()-43200) unset($_SESSION['avsf_import_jobs'][$tokenKey]);
    if (count($_SESSION['avsf_import_jobs']) > 8) $_SESSION['avsf_import_jobs'] = array_slice($_SESSION['avsf_import_jobs'], -8, null, true);
}
if ($importExtractRoot !== '' && is_dir($importExtractRoot)) avsfSafeRemoveTree($importExtractRoot);
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📥 นำเข้าจำนวนมาก — AVSF Admin</title>
<style>
:root{color-scheme:light;--bg:#f3f6fb;--card:#fff;--panel:#fff;--surface2:#f8fafc;--text:#19304f;--muted:#667790;--border:#dfe6f0;--accent:#267cf4;--shadow:0 4px 14px rgba(23,48,79,.06)}
[data-admin-theme="dark"]{color-scheme:dark;--bg:#090b10;--card:#11151f;--panel:#161b26;--surface2:#192131;--text:#e9edf6;--muted:#a5afc1;--border:#293142;--accent:#4c8df6;--shadow:0 4px 14px rgba(0,0,0,.35)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:clip}body{font-family:'Segoe UI','Noto Sans Thai',Tahoma,sans-serif;background:var(--bg);color:var(--text);padding:20px}
.avsf-container{width:100%;max-width:1440px;margin:0 auto}
.avsf-card{position:relative;background:var(--card)!important;border:1px solid var(--border)!important;border-radius:16px!important;padding:22px!important;box-shadow:var(--shadow);color:var(--text)}
.avsf-card:first-of-type{background:var(--card)!important;border-top:3px solid var(--accent)!important}
input[type=file]{min-height:46px;cursor:pointer;background:var(--surface2)!important;border-style:dashed!important;color:var(--text)}
h1{font-size:22px;margin-bottom:8px;color:var(--accent)}
.avsf-subtitle{color:var(--muted);margin-bottom:20px;font-size:14px}
.avsf-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:16px;color:var(--text)}
.avsf-card h2{font-size:15px;margin-bottom:12px;color:var(--text)}
.avsf-msg{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;background:#e7f8f0;border:1px solid #10b981;color:#15803d}
.avsf-btn{padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:#10b981;color:#fff;margin-top:10px}
.avsf-btn:hover{background:#059669}
input[type=file],textarea{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:10px;border-radius:6px;font-size:13px;margin:8px 0;font-family:monospace}
textarea{min-height:120px}
label{font-size:13px;color:var(--muted);display:block;margin-top:12px}
.avsf-checkbox-row{display:flex;align-items:center;gap:8px;margin-top:12px}
.avsf-result-ok{color:#10b981}
.avsf-result-err{color:#fca5a5}
.avsf-result-skip{color:#fbbf24}
table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
th,td{padding:6px 8px;border-bottom:1px solid var(--border);text-align:left}
code{background:var(--surface2);padding:2px 6px;border-radius:4px;font-size:11px;color:var(--accent)}
.avsf-import-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.avsf-btn-secondary{background:#0f766e!important}.avsf-result-updated{color:#60a5fa}.avsf-import-progress{height:8px;background:var(--border);border-radius:999px;overflow:hidden;margin:12px 0 16px}.avsf-import-progress span{display:block;height:100%;width:100%;background:linear-gradient(90deg,#10b981,#3b82f6)}.avsf-result-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.avsf-result-summary div{border:1px solid var(--border);background:var(--surface2);border-radius:10px;padding:12px;color:var(--text)}.avsf-result-summary b{display:block;font-size:20px}.avsf-result-summary span{font-size:12px;color:var(--muted)}@media(max-width:760px){.avsf-result-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
.avsf-back{color:var(--muted);text-decoration:none;font-size:13px;display:inline-block;margin-bottom:16px}
.avsf-back:hover{color:var(--accent)}
.avsf-import-progress-modal{position:fixed;inset:0;z-index:9999;display:none;place-items:center;padding:18px;background:rgba(0,0,0,.78);backdrop-filter:blur(8px)}.avsf-import-progress-modal.avsf-active{display:grid}.avsf-import-progress-dialog{width:min(440px,100%);padding:22px;border:1px solid #344055;border-radius:18px;background:#141a25;box-shadow:0 25px 80px rgba(0,0,0,.5)}.avsf-import-progress-title{font-size:17px;font-weight:850;color:#fff}.avsf-import-progress-track{height:10px;margin:16px 0 9px;overflow:hidden;border-radius:999px;background:#242d3b}.avsf-import-progress-track span{display:block;width:0;height:100%;border-radius:999px;background:linear-gradient(90deg,#ff2d78,#8b5cf6);transition:width .15s}.avsf-import-progress-dialog #importProgressLabel{font-size:12px;color:#cbd5e1}.avsf-import-progress-steps{display:grid;gap:7px;margin-top:18px;padding-top:14px;border-top:1px solid #293344;color:#aeb9ca;font-size:12px}.avsf-import-progress-help{margin-top:15px;color:#7f8da3;font-size:11px}.avsf-import-spinner{display:inline-block;width:13px;height:13px;margin-right:5px;border:2px solid #596579;border-top-color:#ff2d78;border-radius:50%;animation:importSpin .8s linear infinite;vertical-align:middle}@keyframes importSpin{to{transform:rotate(360deg)}}

.avsf-import-steps{display:flex;gap:8px;margin-bottom:16px;background:var(--surface2);border:1px solid var(--border);border-radius:16px;padding:6px;flex-wrap:wrap}.avsf-import-steps>div{flex:1;display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;color:var(--muted);min-width:0}.avsf-import-steps>div.avsf-active{background:var(--card);color:var(--text)}.avsf-import-steps b{width:32px;height:32px;border-radius:50%;display:grid;place-items:center;background:var(--border);color:var(--muted);flex:none}.avsf-import-steps .avsf-active b{background:var(--accent);color:#fff}.avsf-import-steps span{min-width:0}.avsf-import-steps strong,.avsf-import-steps small{display:block}.avsf-import-steps small{color:var(--muted);font-size:11px}.avsf-preview-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.avsf-preview-grid div{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px;color:var(--text)}.avsf-preview-grid span{display:block;font-size:12px;color:var(--muted)}.avsf-preview-grid strong{font-size:18px;color:#60a5fa}@media(max-width:760px){body{padding:12px}.avsf-container{width:100%}.avsf-import-steps{overflow-x:auto}.avsf-import-steps>div{min-width:190px}.avsf-card{padding:16px!important}table{display:block;overflow:auto}.avsf-checkbox-row{align-items:flex-start}}
</style>
<link rel="stylesheet" href="admin-ui.css">
</head>
<body data-admin-theme="light">
<div class="avsf-container">
    <a class="avsf-back" href="index.php">← กลับแดชบอร์ด</a>
    <h1>📥 นำเข้าเรื่องจำนวนมาก</h1>
    <p class="avsf-subtitle">นำเข้าจาก XLSX / CSV / JSON / TXT / ZIP พร้อมรูปภาพหลายรายการ ระบบแบ่งอัปโหลดและบันทึกต่อเนื่องเป็นชุดย่อย · รองรับสูงสุด 20,000 รูปต่องาน (ยังขึ้นกับขีดจำกัด Hosting และพื้นที่จัดเก็บ)</p>

    <?php if ($msg): ?><div class="avsf-msg"><?= e($msg) ?></div><?php endif; ?>
    <div class="avsf-import-steps"><div class="avsf-active"><b>1</b><span><strong><?php _e('เลือกไฟล์'); ?></strong><small>XLSX, CSV, JSON, TXT, ZIP</small></span></div><div><b>2</b><span><strong><?php _e('ตรวจสอบข้อมูล'); ?></strong><small><?php _e('จับคอลัมน์อัตโนมัติ'); ?></small></span></div><div><b>3</b><span><strong><?php _e('นำเข้าโพสต์'); ?></strong><small><?php _e('เรื่อง + รูปภาพ + Taxonomy'); ?></small></span></div></div>

    <div class="avsf-card">
        <h2><?php _e('อัปโหลดไฟล์ หรือวาง JSON'); ?></h2>
        <form id="AVSFImportForm" method="post" enctype="multipart/form-data">
            <?= sec_csrf_field() ?>
            <input type="hidden" name="job_token" value="<?=e($importJobToken)?>">
            <input type="hidden" name="image_only" id="avsfImageOnlyMode" value="0">
            <label><?php _e('ไฟล์หลัก: เลือก CSV/XLSX/JSON/TXT/ZIP และรูปของหลายเรื่องพร้อมกันได้'); ?></label>
            <input type="file" id="avsfMainFiles" name="import_file" accept=".xlsx,.csv,.json,.txt,.zip,.jpg,.jpeg,.png,.webp,.gif,.avif" multiple>
            <div id="avsfMainFileCount" class="avsf-field-help"></div>
            <small class="avsf-field-help"><?php _e('เลือกไฟล์ข้อมูล 1 ไฟล์พร้อมรูปหลายรายการได้ในช่องนี้ หรือแยกรูปไว้ช่องถัดไป ระบบแบ่งส่งเป็นชุดเล็ก จึงไม่ต้องส่งทุกรูปใน request เดียว'); ?> <span><?php _e('เพดานเดิมของเซิร์ฟเวอร์:'); ?> <?=e((string)ini_get('max_file_uploads'))?> <?php _e('ไฟล์/ครั้ง'); ?> · <?=e((string)ini_get('upload_max_filesize'))?> / <?=e((string)ini_get('post_max_size'))?></span></small>
            <label style="margin-top:10px"><?php _e('รูปภาพหลายเรื่อง (เลือกได้ครั้งละมากๆ):'); ?></label><small class="avsf-field-help"><?php _e('จับคู่กับ Code: CODE-123.jpg หรือ CODE-123-cover.jpg = รูปปก · CODE-123-poster.jpg = โปสเตอร์ · CODE-123-gallery-01.jpg หรือ CODE-123-01.jpg = Gallery ถ้าไม่มีรูปปก ระบบใช้รูปแรกของเรื่องนั้นเป็นปกให้การ์ดและหน้าโพสต์'); ?></small>
            <input type="file" id="avsfImageFiles" name="import_images[]" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple>
            <div id="avsfImageFileCount" class="avsf-field-help"></div>
            <label style="margin-top:10px"><?php _e('หรือเลือกโฟลเดอร์รูปภาพทั้งชุด (รองรับ Chromium/Edge):'); ?></label>
            <input type="file" id="avsfFolderFiles" name="import_folder[]" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple webkitdirectory directory>
            <div id="avsfFolderFileCount" class="avsf-field-help"></div>
            <label><?php _e('หรือวาง JSON array ตรงๆ:'); ?></label>
            <textarea name="json_data" placeholder='[{"code":"ABC-123","title":"ชื่อเรื่อง","video_url":"https://..."}]'></textarea>
            <div class="avsf-checkbox-row">
                <input type="checkbox" name="publish_now" value="1" id="pub" checked>
                <label for="pub" style="margin:0"><?php _e('🚀 เผยแพร่ทันที (ไม่ติ๊ก = บันทึกเป็น draft ทั้งหมด)'); ?></label>
            </div>
            <div class="avsf-import-actions">
                <button class="avsf-btn" id="startImportBtn" type="submit" name="image_only_submit" value="0" onclick="document.getElementById('avsfImageOnlyMode').value='0'"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> <?php _e('นำเข้าข้อมูลเรื่อง + รูปภาพ'); ?></button>
                <button class="avsf-btn avsf-btn-secondary" id="startImageOnlyBtn" type="submit" name="image_only_submit" value="1" onclick="document.getElementById('avsfImageOnlyMode').value='1'"><?php _e('🖼️ นำเข้ารูปอย่างเดียว จับคู่เรื่องเดิมด้วย Code'); ?></button>
                <button class="avsf-btn avsf-btn-secondary" id="relinkDiskBtn" type="button"><?php _e('🔗 เชื่อมรูปที่มีอยู่แล้วเข้ากับโพสต์'); ?></button>
            </div>
            <small class="avsf-field-help"><?php _e('ระบบบันทึกโพสต์ครั้งละ 25 เรื่องต่อ request และจับคู่รูปเป็นชุดย่อย รองรับสูงสุด 20,000 รูปต่องาน ไม่ใช่ไม่จำกัดจริงเพราะยังขึ้นกับ Hosting / พื้นที่เก็บไฟล์ · รูปที่เข้าคลังแล้วแต่ยังไม่ขึ้นในโพสต์ ให้กด “เชื่อมรูปที่มีอยู่แล้ว”'); ?></small>
        </form>
        <div id="importProgressModal" class="avsf-import-progress-modal" aria-hidden="true"><div class="avsf-import-progress-dialog"><div class="avsf-import-progress-title"><?php _e('กำลังนำเข้าข้อมูลและรูปภาพ'); ?></div><div class="avsf-import-progress-track"><span id="importProgressFill"></span></div><div id="importProgressLabel"><?php _e('กำลังเตรียมไฟล์…'); ?></div><div class="avsf-import-progress-steps"><div><?php _e('1. อัปโหลดข้อมูลและรูปภาพ'); ?></div><div><?php _e('2. จับคู่รูปตาม Code แบบตรงตัว'); ?></div><div><?php _e('3. คงชื่อไฟล์และนามสกุลต้นฉบับ'); ?></div><div><?php _e('4. อัปเดตเฉพาะช่องรูปภาพของเรื่องเดิม'); ?></div></div><div class="avsf-import-progress-help"><?php _e('ระบบแบ่งประมวลผลเป็นชุดย่อย กรุณาอย่าปิดหน้านี้จนกว่าจะแสดงผลสำเร็จ'); ?></div></div></div>
        <script>
        (function(){
            var form=document.getElementById('AVSFImportForm');if(!form)return;
            var CHUNK_FILES=<?php echo (int)max(1, min(8, ((int)ini_get('max_file_uploads') ?: 20) - 1)); ?>;
            var uploadMax=<?php echo (int)importIniBytes((string)ini_get('upload_max_filesize')); ?>;
            var postMax=<?php echo (int)importIniBytes((string)ini_get('post_max_size')); ?>;
            var CHUNK_BYTES=8*1024*1024;
            if(uploadMax>0)CHUNK_BYTES=Math.min(CHUNK_BYTES, Math.floor(uploadMax*0.65));
            if(postMax>0)CHUNK_BYTES=Math.min(CHUNK_BYTES, Math.floor(postMax*0.65));
            CHUNK_BYTES=Math.max(32*1024,CHUNK_BYTES);
            var imageMax=Math.min(uploadMax||25*1024*1024, postMax||25*1024*1024, 25*1024*1024);
            var dataMax=Math.min(uploadMax||80*1024*1024, postMax||80*1024*1024, 80*1024*1024);
            function fileCount(id,inputId){var input=document.getElementById(inputId),note=document.getElementById(id);if(!input||!note)return;input.addEventListener('change',function(){var n=input.files?input.files.length:0;note.textContent=n?('เลือกแล้ว '+n+' ไฟล์ — ระบบจะแบ่งอัปโหลดต่อเนื่อง'):'';});}
            fileCount('avsfMainFileCount','avsfMainFiles');
            fileCount('avsfImageFileCount','avsfImageFiles');
            fileCount('avsfFolderFileCount','avsfFolderFiles');
            function listFiles(selector){var input=form.querySelector(selector);return input&&input.files?Array.prototype.slice.call(input.files):[];}
            function baseName(file){return String(file.name||'image').split(/[\\/]/).pop();}
            function postForm(url,data,onProgress){return new Promise(function(resolve,reject){var xhr=new XMLHttpRequest();xhr.open('POST',url,true);if(onProgress)xhr.upload.onprogress=onProgress;xhr.onload=function(){var body=null;try{body=JSON.parse(xhr.responseText||'{}');}catch(ignore){body=null;}if(xhr.status<200||xhr.status>=300||!body){reject(new Error((body&&body.message)||'นำเข้าไม่สำเร็จ'));return;}if(body.error){reject(new Error(body.message||'นำเข้าไม่สำเร็จ'));return;}resolve(body);};xhr.onerror=function(){reject(new Error('การเชื่อมต่อขัดข้อง'));};xhr.send(data);});}
            form.addEventListener('submit',function(event){
                if(!window.XMLHttpRequest||!window.FormData||!window.Promise)return;
                event.preventDefault();
                var modal=document.getElementById('importProgressModal'),fill=document.getElementById('importProgressFill'),label=document.getElementById('importProgressLabel');
                var jobToken=form.querySelector('[name=job_token]').value;
                var csrf=form.querySelector('[name=csrf_token]');
                var submitButtons=form.querySelectorAll('button');
                var originalLabels=[];submitButtons.forEach(function(b){originalLabels.push(b.textContent);b.disabled=true;});
                modal.classList.add('avsf-active');modal.setAttribute('aria-hidden','false');
                function restoreButtons(){submitButtons.forEach(function(b,i){b.disabled=false;b.textContent=originalLabels[i]||b.textContent;});}
                function fail(message){modal.classList.remove('avsf-active');restoreButtons();alert(message||'นำเข้าไม่สำเร็จ');}
                function setProgress(percent,message){fill.style.width=Math.max(0,Math.min(100,percent))+'%';label.textContent=message;}
                var dataFiles=[],images=[],warnings=[];
                listFiles('[name=import_file]').forEach(function(file){
                    if(/\.(jpe?g|png|gif|webp|avif)$/i.test(file.name))images.push(file);
                    else dataFiles.push(file);
                });
                if(dataFiles.length>1){fail('เลือกไฟล์ข้อมูลได้ครั้งละ 1 ไฟล์ ส่วนรูปภาพเลือกได้หลายรายการ');return;}
                var dataFile=dataFiles.length?dataFiles[0]:null;
                images=images.concat(listFiles('[name="import_images[]"]'),listFiles('[name="import_folder[]"]'));
                // Do not deduplicate by filename/size/mtime: different images can
                // legitimately share those values. Exact paths/URLs are deduped server-side.
                images=images.filter(function(file){if(file.size>imageMax){warnings.push(baseName(file));return false;}return true;});
                var jsonText=(form.querySelector('[name=json_data]').value||'').trim();
                var imageOnly=document.getElementById('avsfImageOnlyMode').value==='1';
                if(!dataFile&&!images.length&&!jsonText){fail('ยังไม่ได้เลือกไฟล์ข้อมูลหรือรูปภาพ');return;}
                if(imageOnly&&!images.length&&!(dataFile&&/\.zip$/i.test(dataFile.name))){fail('โหมดรูปอย่างเดียวต้องเลือกภาพหรือ ZIP ที่มีรูป');return;}
                if(dataFile&&dataFile.size>dataMax){fail('ไฟล์ข้อมูลเกินขีดจำกัดของคำขอนี้ (สูงสุด 80 MB และยังขึ้นกับ upload_max_filesize / post_max_size ของ Hosting)');return;}
                var chunks=[],current=[],bytes=0;
                images.forEach(function(file){if(current.length>=CHUNK_FILES||(current.length&&bytes+file.size>CHUNK_BYTES)){chunks.push(current);current=[];bytes=0;}current.push(file);bytes+=file.size;});
                if(current.length)chunks.push(current);
                var steps=[];
                if(dataFile)steps.push({data:dataFile,images:[]});
                chunks.forEach(function(chunk){steps.push({images:chunk});});
                var stagedWarnings=[];
                function stageStep(index){
                    if(index>=steps.length){runProcess();return;}
                    var step=steps[index],data=new FormData();
                    if(csrf)data.append('csrf_token',csrf.value);
                    data.append('job_token',jobToken);
                    if(step.data)data.append('chunk_data',step.data,baseName(step.data));
                    (step.images||[]).forEach(function(file){data.append('chunk_images[]',file,baseName(file));});
                    setProgress(Math.round((index/Math.max(1,steps.length))*45),'กำลังอัปโหลดชุดที่ '+(index+1)+'/'+steps.length+' ('+images.length+' รูป)');
                    postForm('import.php?stage_chunk=1',data,function(e){if(!e.lengthComputable)return;var pct=((index+(e.loaded/e.total))/Math.max(1,steps.length))*45;setProgress(pct,'กำลังอัปโหลดชุดที่ '+(index+1)+'/'+steps.length);}).then(function(body){if(body.errors&&body.errors.length)stagedWarnings=stagedWarnings.concat(body.errors);if((!body.accepted)&&step.images&&step.images.length&&body.errors&&body.errors.length){fail(body.errors[0]);return;}window.setTimeout(function(){stageStep(index+1);},350);}).catch(fail);
                }
                function runImageBatch(token){
                    var data=new FormData();
                    if(csrf)data.append('csrf_token',csrf.value);
                    data.append('job_token',token);data.append('image_job_token',token);
                    postForm('import.php?image_job_step=1',data).then(function(body){
                        var pct=Math.max(0,Math.min(100,Number(body.percent)||0));
                        setProgress(pct,(body.message||'กำลังจับคู่รูป…')+' ('+(Number(body.processed)||0)+'/'+(Number(body.total)||0)+' เรื่อง)');
                        if(body.done){setProgress(100,'เสร็จแล้ว กำลังแสดงผล…');if(stagedWarnings.length)alert('มีบางไฟล์ที่ข้าม:\n'+stagedWarnings.slice(0,8).join('\n'));window.setTimeout(function(){window.location.href='import.php';},350);}
                        else window.setTimeout(function(){runImageBatch(token);},120);
                    }).catch(fail);
                }
                function runProcess(){
                    var data=new FormData();
                    if(csrf)data.append('csrf_token',csrf.value);
                    data.append('job_token',jobToken);
                    data.append('use_stage','1');
                    data.append('image_only',imageOnly?'1':'0');
                    var publish=form.querySelector('[name=publish_now]');
                    if(publish&&publish.checked)data.append('publish_now','1');
                    if(jsonText)data.append('json_data',jsonText);
                    setProgress(48,'อัปโหลดครบแล้ว กำลังบันทึกโพสต์และผูกภาพ…');
                    postForm('import.php',data).then(function(body){
                        if(body.image_job){setProgress(10,body.message||'เริ่มจับคู่รูปกับเรื่องเดิม…');runImageBatch(body.token||jobToken);return;}
                        if(body.batch){
                            var pct=Math.max(48,Math.min(100,Number(body.percent)||0));
                            setProgress(pct,(body.message||'กำลังนำเข้า…')+(body.total?(' ('+body.processed+'/'+body.total+' เรื่อง)'):''));
                            if(!body.done){window.setTimeout(runProcess,350);return;}
                            setProgress(100,'เสร็จแล้ว กำลังแสดงผล…');
                            if(stagedWarnings.length)alert('มีบางไฟล์ที่ข้าม:\n'+stagedWarnings.slice(0,8).join('\n'));
                            window.setTimeout(function(){window.location.href='import.php';},350);
                            return;
                        }
                        fail('เซิร์ฟเวอร์ไม่ได้ตอบผลการนำเข้า');
                    }).catch(fail);
                }
                if(warnings.length)setProgress(1,'ข้ามไฟล์ที่ใหญ่เกิน: '+warnings.slice(0,3).join(', '));
                if(!steps.length)runProcess(); else stageStep(0);
            });
            var relink=document.getElementById('relinkDiskBtn');
            if(relink)relink.addEventListener('click',function(){
                if(!window.confirm('เชื่อมรูปที่มีอยู่ในเซิร์ฟเวอร์เข้ากับโพสต์ตามชื่อไฟล์ Code และเติมรูปปกที่ยังว่างอยู่หรือไม่'))return;
                var modal=document.getElementById('importProgressModal'),fill=document.getElementById('importProgressFill'),label=document.getElementById('importProgressLabel');
                var jobToken=form.querySelector('[name=job_token]').value,csrf=form.querySelector('[name=csrf_token]');
                modal.classList.add('avsf-active');
                function step(){var data=new FormData();if(csrf)data.append('csrf_token',csrf.value);data.append('job_token',jobToken);postForm('import.php?relink_disk=1',data).then(function(body){var pct=body.total?Math.round((Number(body.processed)||0)/body.total*100):100;fill.style.width=pct+'%';label.textContent=body.message||'กำลังเชื่อมรูป…';if(body.done)window.setTimeout(function(){window.location.href='import.php';},400);else window.setTimeout(step,650);}).catch(function(error){modal.classList.remove('avsf-active');alert(error.message||'เชื่อมรูปไม่สำเร็จ');});}
                step();
            });
        })();
        </script>
    </div>

    <div class="avsf-card">
        <h2>📋 รูปแบบคอลัมน์ (CSV header)</h2>
        <p style="font-size:12px;color:#94a3b8;line-height:1.8">
            <code>Code, Focus Keyphrase, Actor, Actor EN, Tags, Studio, Categories, Title, Content, Meta Title, Meta Description, Duration, Video URL, Embed URL, Cover, Poster, Gallery, Cover Alt, Poster Alt, Gallery Alt, Slug, Date</code><br>
            ถ้าไม่ระบุ ALT ระบบจะสร้างเป็น <code>Code + Actor EN</code> และใช้ <code>Code</code> เป็น Slug เริ่มต้น<br>รองรับ XLSX, CSV, JSON, TXT และ ZIP ที่มีไฟล์ข้อมูลพร้อมรูปภาพ หรือ ZIP ที่มีเฉพาะรูปภาพสำหรับนำเข้าภายหลัง<br>
            ชื่อไฟล์รูปภาพจับคู่กับ Code: <code>MIAB-679.jpg</code> (ปก), <code>MIAB-679-poster.jpg</code> (โปสเตอร์), <code>MIAB-679-gallery-01.jpg</code>, <code>MIAB-679-01.jpg</code> (Gallery) · รูป Gallery จะเรียงตามชื่อ/ลำดับเลข<br>
            ใส่แค่ <code>title</code> + <code>video_url</code> ก็พอ — คอลัมน์อื่นๆ ใส่ไม่ได้ก็ไม่เป็นไร ระบบจะ map ให้อัตโนมัติ<br>
            นักแสดง, แท็ก และหมวดหมู่รองรับหลายชื่อคั่นด้วยจุลภาค โดยแต่ละชื่อจะเป็นรายการและ slug แยกกัน · หนึ่งโพสต์ระบุค่ายได้หนึ่งค่าย
        </p>
    </div>

    <?php if (!empty($results)): ?>
    <?php
    $resultCounts=['ok'=>0,'updated'=>0,'skip'=>0,'error'=>0];
    foreach($results as $resultRow){$resultStatus=$resultRow['status']??'error';$resultCounts[$resultStatus]=($resultCounts[$resultStatus]??0)+1;}
    ?>
    <div class="avsf-card">
        <h2><?php _e('✅ ผลการนำเข้าและรายละเอียด ('); ?><?= count($results) ?> <?php _e('รายการ)'); ?></h2>
        <div class="avsf-import-progress" aria-label="<?php echo __('นำเข้าเสร็จสมบูรณ์'); ?>"><span></span></div>
        <div class="avsf-result-summary">
            <div><b class="avsf-result-ok"><?= $resultCounts['ok'] ?></b><span><?php _e('เพิ่มใหม่'); ?></span></div>
            <div><b class="avsf-result-updated"><?= $resultCounts['updated'] ?></b><span><?php _e('อัปเดต'); ?></span></div>
            <div><b class="avsf-result-skip"><?= $resultCounts['skip'] ?></b><span><?php _e('ข้ามข้อมูลซ้ำ'); ?></span></div>
            <div><b class="avsf-result-err"><?= $resultCounts['error'] ?></b><span><?php _e('ผิดพลาด'); ?></span></div>
        </div>
        <table>
            <tr><th>#</th><th><?php _e('สถานะ'); ?></th><th><?php _e('เรื่อง / รายละเอียด'); ?></th></tr>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><?= $r['row'] ?></td>
                <td class="avsf-result-<?= $r['status']==='ok'?'avsf-ok':($r['status']==='updated'?'updated':($r['status']==='skip'?'avsf-skip':'avsf-err')) ?>">
                    <?= $r['status']==='ok'?__('✓ เพิ่มใหม่'):($r['status']==='updated'?__('↻ อัปเดต'):($r['status']==='skip'?__('↷ ข้าม'):__('✗ ผิดพลาด'))) ?>
                </td>
                <td><?= e($r['title'] ?? '') ?><?php if(!empty($r['reason'])): ?><br><small><?= e($r['reason']) ?></small><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
