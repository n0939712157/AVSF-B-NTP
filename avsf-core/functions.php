<?php
// =============================================
// AVSF - functions.php (ฟังก์ชันร่วม)
// =============================================
function slugify($s) {
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$s), 'UTF-8') : strtolower(trim((string)$s));
    $s = preg_replace('/[^\p{L}\p{M}\p{N}\s-]/u', '', $s);
    $s = preg_replace('/\s+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s,'-') ?: 'item';
}
function stripHtml($s) {
    $s = strip_tags($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}
function fmtDate($d) {
    if (!$d) return '';
    $date=siteDateTime($d);
    return $date?$date->format(siteDateFormat()):'';
}
function fmtTime($t) {
    if(!$t)return '';
    $timeFormat=setting('timeFormat','H:i');
    if(!in_array($timeFormat,['H:i','g:i A'],true)) {
        error_log('AVSF received an unsupported time format setting; using the default.');
        $timeFormat='H:i';
    }
    $date=siteDateTime($t);
    return $date?$date->format(siteDateFormat().' '.$timeFormat):'';
}
function siteDateFormat() {
    $formats=['d M Y','d/m/Y','Y-m-d','F j, Y'];
    $format=setting('dateFormat','d M Y');
    if(!in_array($format,$formats,true)) {
        error_log('AVSF received an unsupported date format setting; using the default.');
        return 'd M Y';
    }
    return $format;
}
function siteDateTime($value) {
    $timezones=['Asia/Bangkok','UTC','Asia/Tokyo','Asia/Singapore','Asia/Kuala_Lumpur','Australia/Sydney','Europe/London','America/New_York','America/Los_Angeles'];
    $timezoneName=setting('siteTimezone','Asia/Bangkok');
    if(!in_array($timezoneName,$timezones,true)) {
        error_log('AVSF received an unsupported site timezone setting; using Asia/Bangkok.');
        $timezoneName='Asia/Bangkok';
    }
    try {
        return new DateTimeImmutable((string)$value,new DateTimeZone($timezoneName));
    } catch (Exception $error) {
        return null;
    }
}
function fmtThaiDate($value) {
    if (!$value) return '';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Bangkok'));
    } catch (Exception $error) {
        return '';
    }
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return $date->format('j').' '.$months[(int)$date->format('n')-1].' '.((int)$date->format('Y')+543);
}
function fmtThaiDateTime($value) {
    if (!$value) return '';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Bangkok'));
    } catch (Exception $error) {
        return '';
    }
    return fmtThaiDate($value).' เวลา '.$date->format('H:i').' น.';
}
function esc($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function avsfOriginalImageUploadFilename($originalName, $mime) {
    // Keep the exact client filename (including Thai characters, spaces and extension case).
    // Strip only the browser-supplied path portion; never create a random/hashed filename.
    $filename = basename(str_replace('\\', '/', (string)$originalName));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return ['filename'=>'', 'error'=>__('ชื่อไฟล์ภาพไม่ถูกต้อง')];
    }
    if (preg_match('~[\\x00-\\x1F\\x7F:/\\\\]~', $filename)) {
        return ['filename'=>'', 'error'=>__('ชื่อไฟล์มีอักขระที่ไม่อนุญาต')];
    }
    if (strlen($filename) > 240) {
        return ['filename'=>'', 'error'=>__('ชื่อไฟล์ยาวเกินไป กรุณาใช้ชื่อที่สั้นลง')];
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedByMime = [
        'image/jpeg'=>['jpg','jpeg'],
        'image/png'=>['png'],
        'image/gif'=>['gif'],
        'image/webp'=>['webp'],
    ];
    if (!isset($allowedByMime[$mime]) || !in_array($extension, $allowedByMime[$mime], true)) {
        return ['filename'=>'', 'error'=>__('นามสกุลไฟล์ไม่ตรงกับชนิดรูปภาพ กรุณาใช้ไฟล์ JPG, JPEG, PNG, GIF หรือ WebP ที่ถูกต้อง')];
    }
    return ['filename'=>$filename, 'error'=>''];
}
function storeImageUpload($field) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return ['path'=>'','error'=>''];
    $file = $_FILES[$field];
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['path'=>'','error'=>''];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? __('รูปมีขนาดเกินกำหนด')
            : __('อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่');
        return ['path'=>'','error'=>$message];
    }
    if (!isset($file['tmp_name'],$file['size'],$file['name']) || !is_uploaded_file($file['tmp_name'])) return ['path'=>'','error'=>__('ไฟล์อัปโหลดไม่ถูกต้อง')];
    if ((int)$file['size'] < 1 || (int)$file['size'] > 5 * 1024 * 1024) return ['path'=>'','error'=>__('รองรับรูปภาพขนาดไม่เกิน 5 MB')];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) return ['path'=>'','error'=>__('ตรวจสอบชนิดรูปภาพไม่ได้')];
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $image = @getimagesize($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true) || !$image || !isset($image['mime']) || $image['mime'] !== $mime) {
        return ['path'=>'','error'=>__('รองรับเฉพาะรูป JPG, JPEG, PNG, GIF และ WebP')];
    }
    $nameResult = avsfOriginalImageUploadFilename($file['name'], $mime);
    if ($nameResult['error'] !== '') return ['path'=>'','error'=>$nameResult['error']];
    $filename = $nameResult['filename'];

    $directory = AVSF_UPLOAD_DIR;
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return ['path'=>'','error'=>__('สร้างโฟลเดอร์เก็บรูปไม่ได้ กรุณาตรวจสอบสิทธิ์เขียนไฟล์ของเซิร์ฟเวอร์')];
    }
    if (!is_writable($directory)) {
        return ['path'=>'','error'=>__('โฟลเดอร์ uploads ไม่มีสิทธิ์เขียนไฟล์ กรุณาปรับ Permission ของโฟลเดอร์')];
    }
    $dest = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (is_link($dest) || is_dir($dest)) {
        return ['path'=>'','error'=>__('ปลายทางชื่อไฟล์นี้ไม่ปลอดภัยหรือเป็นโฟลเดอร์')];
    }
    if (is_file($dest)) {
        $existingHash = @hash_file('sha256', $dest);
        $incomingHash = @hash_file('sha256', $file['tmp_name']);
        if (is_string($existingHash) && is_string($incomingHash) && hash_equals($existingHash, $incomingHash)) {
            $basePath = function_exists('avsfCanonicalBasePath') ? avsfCanonicalBasePath() : '';
            return ['path'=>rtrim($basePath,'/').'/uploads/'.rawurlencode($filename),'error'=>'','filename'=>$filename,'duplicate'=>true];
        }
        return ['path'=>'','error'=>__('มีไฟล์ชื่อเดียวกันอยู่แล้ว ระบบจะไม่เปลี่ยนชื่อหรือเขียนทับให้อัตโนมัติ กรุณาจัดการไฟล์เดิมหรือใช้ชื่อไฟล์ที่ไม่ซ้ำ')];
    }
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        error_log('AVSF image upload failed: destination is not writable or move_uploaded_file failed for ' . $filename);
        return ['path'=>'','error'=>__('บันทึกรูปลงเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบสิทธิ์โฟลเดอร์ uploads และพื้นที่จัดเก็บ')];
    }
    @chmod($dest, 0644);
    $basePath = function_exists('avsfCanonicalBasePath') ? avsfCanonicalBasePath() : '';
    return ['path'=>rtrim($basePath,'/').'/uploads/'.rawurlencode($filename),'error'=>'','filename'=>$filename,'duplicate'=>false];
}
function storeMultipleImageUploads($field) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || !isset($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) {
        return ['paths'=>[],'error'=>''];
    }
    $files=$_FILES[$field];$paths=[];
    foreach($files['name'] as $index=>$name) {
        if (!isset($files['error'][$index]) || $files['error'][$index]===UPLOAD_ERR_NO_FILE) continue;
        $single=[];
        foreach(['name','type','tmp_name','error','size'] as $key) $single[$key]=isset($files[$key][$index])?$files[$key][$index]:null;
        $original=isset($_FILES[$field])?$_FILES[$field]:null;
        $_FILES[$field]=$single;
        $upload=storeImageUpload($field);
        if ($original!==null) $_FILES[$field]=$original;
        if ($upload['error']!=='') return ['paths'=>$paths,'error'=>$upload['error']];
        if ($upload['path']!=='') $paths[]=$upload['path'];
    }
    return ['paths'=>$paths,'error'=>''];
}
function validImageReference($value) {
    $value = trim($value);
    if ($value === '') return true;
    if (preg_match('/[\x00-\x20<>"]/',$value)) return false;
    if (preg_match('/^https?:\/\/[^\s]+$/i',$value)) return true;
    return isset($value[0]) && $value[0] === '/' && substr($value,0,2) !== '//';
}
/**
 * Normalize media references saved by older importers/editors.
 * Keeps absolute URLs and root-relative URLs intact, while fixing common
 * relative uploads/ paths so they resolve from the site root, not the post URL.
 */
function normalizePublicImageReference($value) {
    $value = trim(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if ($value === '') return '';
    if (strpos($value, '//') === 0) {
        $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https:' : 'http:';
        return $scheme . $value;
    }
    if (preg_match('/^https?:\\/\\//i', $value)) return $value;
    $value = str_replace('\\', '/', $value);
    while (strpos($value, './') === 0) $value = substr($value, 2);
    if (strpos($value, '/') === 0) return $value;
    if (preg_match('/^(uploads|media)\\//i', $value)) {
        $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $basePath = rtrim(dirname($scriptPath), '/');
        if ($basePath === '.' || $basePath === '/') $basePath = '';
        // Admin pages execute from /admin-login/index.php; strip that route segment.
        if (preg_match('~/admin-login$~i', $basePath)) $basePath = rtrim(dirname($basePath), '/');
        if ($basePath === '.') $basePath = ''; 
        return $basePath . '/' . ltrim($value, '/');
    }
    return $value;
}

/** Accept JSON arrays, legacy newline lists, or arrays passed directly. */
function normalizeGalleryImages($raw) {
    if (is_string($raw)) {
        $source = trim($raw);
        if ($source === '') return [];
        $decoded = json_decode($source, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded) && isset($decoded['images']) && is_array($decoded['images'])) {
                $raw = $decoded['images'];
            } elseif (is_array($decoded)) {
                $raw = $decoded;
            } elseif (is_string($decoded) && $decoded !== $source) {
                $raw = [$decoded];
            } else {
                $raw = [$source];
            }
        } else {
            $raw = preg_split('/\\r\\n|\\r|\\n/', $source) ?: [$source];
            if (count($raw) === 1 && preg_match('/,\\s*(?:https?:\\/\\/|\\/?uploads\\/)/i', $source)) {
                $raw = preg_split('/,\\s*(?=https?:\\/\\/|\\/?uploads\\/)/i', $source) ?: [$source];
            }
        }
    }
    if (!is_array($raw)) $raw = [];
    $images = [];
    $seen = [];
    foreach ($raw as $item) {
        if (is_array($item)) $item = $item['url'] ?? ($item['src'] ?? ($item['path'] ?? ''));
        $url = normalizePublicImageReference($item);
        if ($url === '' || !validImageReference($url)) continue;
        $key = strtolower($url);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $images[] = $url;
    }
    return $images;
}

/** Cover, otherwise poster, otherwise the first gallery image. Cards and the single post use this so gallery-only imports still show a picture. */
function avsfFirstPostImage($post, $preferPoster = false) {
    if (!is_array($post)) return '';
    $poster = trim((string)($post['posterUrl'] ?? ''));
    $cover = trim((string)($post['cover'] ?? ''));
    $gallery = function_exists('normalizeGalleryImages') ? normalizeGalleryImages($post['gallery'] ?? '') : [];
    $firstGallery = '';
    foreach ($gallery as $imageUrl) {
        $imageUrl = trim((string)$imageUrl);
        if ($imageUrl !== '') { $firstGallery = $imageUrl; break; }
    }
    if ($preferPoster && $poster !== '') return $poster;
    if ($cover !== '') return $cover;
    if ($poster !== '') return $poster;
    return $firstGallery;
}

function validAdDestinationUrl($value) {
    $value = trim((string)$value);
    if ($value === '' || preg_match('/[\x00-\x20<>"]/',$value)) return false;
    $parts = parse_url($value);
    return is_array($parts) && isset($parts['scheme'],$parts['host']) &&
        in_array(strtolower($parts['scheme']),['http','https'],true) &&
        !isset($parts['user']) && !isset($parts['pass']);
}
function ensureSeoIndexOverridesTable($connection=null) {
    static $ready=false;
    global $conn;
    if($ready)return true;
    $connection=$connection?:$conn;
    if(!$connection)throw new RuntimeException('Database connection is unavailable for SEO index rules.');
    $result=mysqli_query($connection,'SELECT 1 FROM seo_index_overrides LIMIT 0');
    if(!$result) {
        error_log('AVSF SEO index rules migration is required: '.mysqli_error($connection));
        throw new RuntimeException('Apply database-upgrade.sql before enabling SEO index controls.');
    }
    mysqli_free_result($result);
    $ready=true;
    return true;
}
function ensureServerErrorLogTable($connection=null) {
    static $ready=false;
    global $conn;
    if($ready)return true;
    $connection=$connection?:$conn;
    if(!$connection)throw new RuntimeException('Database connection is unavailable for server error logging.');
    $result=mysqli_query($connection,'SELECT 1 FROM server_error_log LIMIT 0');
    if(!$result) {
        error_log('AVSF server error log migration is required: '.mysqli_error($connection));
        throw new RuntimeException('Apply database-upgrade.sql before enabling server error reporting.');
    }
    mysqli_free_result($result);
    $ready=true;
    return true;
}
function seoIndexDefault($entityType) {
    $settingKeys=[
        'home'=>'seo_index_home',
        'all'=>'seo_index_all',
        'category_list'=>'seo_index_category_list',
        'studio_list'=>'seo_index_studio_list',
        'actor_list'=>'seo_index_actor_list',
        'tag_list'=>'seo_index_tag_list',
        'post'=>'seo_index_posts',
        'page'=>'seo_index_pages',
        'category'=>'seo_index_categories',
        'studio'=>'seo_index_studios',
        'actor'=>'seo_index_actors',
        'tag'=>'seo_index_tags'
    ];
    if(!isset($settingKeys[$entityType]))return false;
    $value=setting($settingKeys[$entityType],'');
    if($value==='index')return true;
    if($value==='noindex')return false;
    return strpos(trim(setting('seo_robots','index, follow')),'noindex')!==0;
}
function seoIndexable($entityType,$entityId=0,$connection=null) {
    global $conn;
    $connection=$connection?:$conn;
    $default=seoIndexDefault($entityType);
    if(!$default)return false;
    if((int)$entityId<1)return true;
    ensureSeoIndexOverridesTable($connection);
    $stmt=mysqli_prepare($connection,'SELECT indexable FROM seo_index_overrides WHERE entity_type=? AND entity_id=? LIMIT 1');
    if(!$stmt) {
        error_log('AVSF could not prepare SEO index override lookup: '.mysqli_error($connection));
        throw new RuntimeException('Could not read SEO index rules.');
    }
    $entityId=(int)$entityId;
    mysqli_stmt_bind_param($stmt,'si',$entityType,$entityId);
    if(!mysqli_stmt_execute($stmt)) {
        $error=mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        error_log('AVSF could not execute SEO index override lookup: '.$error);
        throw new RuntimeException('Could not read SEO index rules.');
    }
    mysqli_stmt_bind_result($stmt,$indexable);
    $found=mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $found?(bool)$indexable:$default;
}
function saveSeoIndexOverride($entityType,$entityId,$indexable,$connection=null) {
    global $conn;
    $connection=$connection?:$conn;
    $allowedTypes=['post','page','category','studio','actor','tag'];
    $entityId=(int)$entityId;
    if(!in_array($entityType,$allowedTypes,true)||$entityId<1) {
        throw new InvalidArgumentException('Invalid SEO index override target.');
    }
    ensureSeoIndexOverridesTable($connection);
    $tableMap=['post'=>'posts','page'=>'pages','category'=>'categories','studio'=>'studios','actor'=>'actors','tag'=>'tags'];
    $table=$tableMap[$entityType];
    $exists=mysqli_prepare($connection,"SELECT id FROM `$table` WHERE id=? LIMIT 1");
    if(!$exists) {
        error_log('AVSF could not prepare SEO override target validation: '.mysqli_error($connection));
        throw new RuntimeException('Could not validate SEO override target.');
    }
    mysqli_stmt_bind_param($exists,'i',$entityId);
    if(!mysqli_stmt_execute($exists)) {
        $error=mysqli_stmt_error($exists);
        mysqli_stmt_close($exists);
        error_log('AVSF SEO override target validation failed: '.$error);
        throw new RuntimeException('Could not validate SEO override target.');
    }
    mysqli_stmt_store_result($exists);
    $targetExists=mysqli_stmt_num_rows($exists)>0;
    mysqli_stmt_close($exists);
    if(!$targetExists)throw new InvalidArgumentException('SEO override target does not exist.');
    $stmt=mysqli_prepare($connection,'INSERT INTO seo_index_overrides (entity_type,entity_id,indexable) VALUES (?,?,?) ON DUPLICATE KEY UPDATE indexable=VALUES(indexable)');
    if(!$stmt) {
        error_log('AVSF could not prepare SEO index override save: '.mysqli_error($connection));
        throw new RuntimeException('Could not save SEO index rules.');
    }
    $value=$indexable?1:0;
    mysqli_stmt_bind_param($stmt,'sii',$entityType,$entityId,$value);
    $saved=mysqli_stmt_execute($stmt);
    if(!$saved) {
        error_log('AVSF could not save SEO index override: '.mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
    if(!$saved)throw new RuntimeException('Could not save SEO index rules.');
    return true;
}
function ensurePostsUpdatedAtColumn($connection=null) {
    global $conn;
    $connection=$connection?:$conn;
    static $checked=false;
    if($checked)return true;
    // Keep MySQL NOW() aligned with the timezone configured in AVSF. This
    // makes updatedAt values comparable with createdAt/date in VideoObject.
    $allowedTimezones=['Asia/Bangkok','UTC','Asia/Tokyo','Asia/Singapore','Asia/Kuala_Lumpur','Australia/Sydney','Europe/London','America/New_York','America/Los_Angeles'];
    $timezoneName=(string)setting('siteTimezone','Asia/Bangkok');
    if(!in_array($timezoneName,$allowedTimezones,true))$timezoneName='Asia/Bangkok';
    try {
        $timezoneOffset=(new DateTimeImmutable('now',new DateTimeZone($timezoneName)))->format('P');
        if(!preg_match('/^[+-][0-9]{2}:[0-9]{2}$/',$timezoneOffset)||!mysqli_query($connection,"SET time_zone='".$timezoneOffset."'")) {
            error_log('AVSF could not align the database session timezone: '.mysqli_error($connection));
        }
    } catch(Throwable $timezoneError) {
        error_log('AVSF timezone setup failed: '.$timezoneError->getMessage());
    }
    $checked=true;
    $column=mysqli_query($connection,"SHOW COLUMNS FROM posts LIKE 'updatedAt'");
    if(!$column) {
        error_log('AVSF updatedAt column check failed: '.mysqli_error($connection));
        return false;
    }
    $columnExists=mysqli_num_rows($column)>0;
    mysqli_free_result($column);
    if(!$columnExists) {
        if(!mysqli_query($connection,'ALTER TABLE posts ADD COLUMN updatedAt DATETIME DEFAULT NULL AFTER createdAt')) {
            error_log('AVSF updatedAt migration failed: '.mysqli_error($connection));
            return false;
        }
        // Backfill only once, immediately after a new installation/migration.
        // Do not run this UPDATE on every frontend request.
        if(!mysqli_query($connection,"UPDATE posts SET updatedAt=COALESCE(createdAt,CONCAT(date,' 00:00:00')) WHERE updatedAt IS NULL AND (createdAt IS NOT NULL OR date IS NOT NULL)")) {
            error_log('AVSF updatedAt backfill failed: '.mysqli_error($connection));
            return false;
        }
    }
    return true;
}

function avsfPermalinkBase($settingKey,$default) {
    $value=trim((string)setting($settingKey,$default));
    $value=trim($value," /\\\t\n\r\0\x0B");
    $value=strtolower($value);
    $value=preg_replace('/[^a-z0-9-]+/','-',$value);
    $value=trim(preg_replace('/-+/','-',$value),'-');
    return $value!==''?$value:$default;
}
function avsfPermalinkBases() {
    return [
        'category'=>avsfPermalinkBase('categoryBase','category'),
        'tag'=>avsfPermalinkBase('tagBase','tag'),
        'actor'=>avsfPermalinkBase('actorBase','actor'),
        'studio'=>avsfPermalinkBase('studioBase','studio')
    ];
}
function avsfPermalinkStructure() {
    $value=trim((string)setting('permalinkStructure','postname'));
    $allowed=['plain','dayname','monthname','numeric','postname'];
    return in_array($value,$allowed,true)?$value:'postname';
}
function avsfPostPermalinkInfo($slug,$postMeta=null) {
    if(is_array($postMeta)&&isset($postMeta['id'])) {
        return ['id'=>(int)$postMeta['id'],'date'=>(string)($postMeta['date']??'')];
    }
    static $cache=[];
    $slug=(string)$slug;
    if(array_key_exists($slug,$cache))return $cache[$slug];
    $cache[$slug]=['id'=>0,'date'=>''];
    global $conn;
    if($slug===''||!isset($conn)||!($conn instanceof mysqli))return $cache[$slug];
    $stmt=@mysqli_prepare($conn,'SELECT id,date FROM posts WHERE slug=? LIMIT 1');
    if(!$stmt)return $cache[$slug];
    mysqli_stmt_bind_param($stmt,'s',$slug);
    if(mysqli_stmt_execute($stmt)) {
        mysqli_stmt_bind_result($stmt,$id,$date);
        if(mysqli_stmt_fetch($stmt))$cache[$slug]=['id'=>(int)$id,'date'=>(string)$date];
    }
    mysqli_stmt_close($stmt);
    return $cache[$slug];
}
function sitePath($route='home',$slug='',$query=[],$postMeta=null) {
    $bases=avsfPermalinkBases();
    $reservedSlugs=array_values(array_unique(array_merge(['studio','actor','tag','category','page','post','all','categories','search','sitemap','sitemap-index','rss','robots','archives'],array_values($bases))));
    $postSlug=rawurlencode((string)$slug);
    $postPath=$postSlug;
    if($route==='post') {
        $structure=avsfPermalinkStructure();
        if($structure==='plain'||$structure==='numeric'||$structure==='dayname'||$structure==='monthname') {
            $info=avsfPostPermalinkInfo($slug,$postMeta);
            $postId=(int)$info['id'];
            if($structure==='plain'&&$postId>0) {
                $postPath='';
                $query=['p'=>$postId]+(is_array($query)?$query:[]);
            } elseif($structure==='numeric'&&$postId>0) {
                $postPath='archives/'.$postId;
            } elseif(in_array($structure,['dayname','monthname'],true)&&$postId>0) {
                $dateRaw=trim((string)$info['date']);
                $date=(preg_match('/^\d{4}-\d{2}-\d{2}/',$dateRaw)?siteDateTime($dateRaw):null);
                if($date) {
                    $datePrefix=$date->format($structure==='dayname'?'Y/m/d':'Y/m');
                    $postPath=$datePrefix.'/'.$postSlug;
                }
            }
        }
        if($structure==='postname'&&in_array(strtolower((string)$slug),$reservedSlugs,true))$postPath='post/'.$postSlug;
    }
    $paths=[
        'home'=>'','post'=>$postPath,'search'=>'','all'=>'all','categories'=>'categories',
        'studio_list'=>$bases['studio'],'actor_list'=>$bases['actor'],'tag_list'=>$bases['tag'],
        'studio'=>$bases['studio'].'/'.rawurlencode((string)$slug),
        'actor'=>$bases['actor'].'/'.rawurlencode((string)$slug),
        'tag'=>$bases['tag'].'/'.rawurlencode((string)$slug),
        'category'=>$bases['category'].'/'.rawurlencode((string)$slug),
        'page'=>'page/'.rawurlencode((string)$slug)
    ];
    if(!array_key_exists($route,$paths))return '';
    $relative=trim($paths[$route],'/');
    $path='/'.($relative!==''?$relative.'/':'');
    if($route==='search')$query=['s'=>(string)$slug]+$query;
    $queryString=$query?http_build_query($query,'','&',PHP_QUERY_RFC3986):'';
    return $path.($queryString!==''?'?'.$queryString:'');
}
function siteUrl($route='home',$slug='',$query=[],$postMeta=null) {
    // Keep internal links on the current host, but take their installation path
    // from the same configured canonical base used by Sitemap/robots/RSS.
    if(function_exists('avsfCanonicalBasePath')) {
        $basePath=rtrim((string)avsfCanonicalBasePath(),'/');
    } else {
        $scriptPath=str_replace('\\','/',isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/index.php');
        $basePath=rtrim(dirname($scriptPath),'/');
        if($basePath==='.'||$basePath==='\\')$basePath='';
        $internalDirectories=['admin-login','api','avsf-admin','avsf-core','avsf-frontend','avsf-installer','avsf-storage','avsf-updates','pages','includes','partials','loop-templates','taxonomy-templates'];
        if($basePath!==''&&in_array(basename($basePath),$internalDirectories,true))$basePath=rtrim(dirname($basePath),'/');
        if($basePath==='.'||$basePath==='\\')$basePath='';
    }
    return $basePath.sitePath($route,$slug,$query,$postMeta);
}
function getStudio($id) {
    global $conn;
    $id = intval($id);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM studios WHERE id=$id"));
    return $r ? $r : ['id'=>0,'name'=>__('ไม่ระบุ'),'slug'=>'','desc'=>''];
}
function parseTaxonomyIds($value) {
    $ids = is_array($value) ? $value : json_decode((string)$value, true);
    if (!is_array($ids)) $ids = preg_split('/[,،;|，；\r\n]+/u', (string)$value) ?: [];
    return array_values(array_unique(array_filter(array_map('intval', $ids), function($id) {
        return $id > 0;
    })));
}
function getActor($id) {
    global $conn;
    $id = intval($id);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM actors WHERE id=$id"));
    return $r ? $r : ['id'=>0,'name'=>__('ไม่ระบุ'),'slug'=>'','bio'=>''];
}
function getTag($id) {
    global $conn;
    $id = intval($id);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tags WHERE id=$id"));
    return $r ? $r : ['id'=>0,'name'=>__('ไม่ระบุ'),'slug'=>''];
}
function publishedPosts() {
    global $conn;
    $res = mysqli_query($conn, "SELECT * FROM posts WHERE status='publish' ORDER BY date DESC");
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    return $out;
}
function normalizeVideoInput($input) {
    $input = trim((string)$input);
    if ($input === '') return ['url'=>'','error'=>''];
    if (stripos($input, '<iframe') !== false) {
        if (!preg_match('/^\s*<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>\s*(?:<\/iframe\s*>)?\s*$/is', $input, $match)) {
            return ['url'=>'','error'=>__('วาง iframe เต็มรูปแบบที่มี src URL เดียวเท่านั้น')];
        }
        $input = trim(html_entity_decode($match[2], ENT_QUOTES|ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('/^[A-Za-z0-9_-]{5,120}$/', $input)) {
        $input = rtrim((string)(function_exists('setting')?setting('vdohideIframeDomain',''):''),'/').'/embed/'.$input.'/';
    }
    if (substr($input,0,2)==='//') $input='https:'.$input;
    if (preg_match('/[\x00-\x20<>"]/',$input)) return ['url'=>'','error'=>__('URL วิดีโอมีรูปแบบไม่ถูกต้อง')];
    $parts = parse_url($input);
    if (!$parts || empty($parts['scheme']) || !in_array(strtolower($parts['scheme']),['http','https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return ['url'=>'','error'=>__('ใช้ลิงก์วิดีโอ http(s) หรือ iframe ที่มีลิงก์ http(s) เท่านั้น')];
    }
    return ['url'=>$input,'error'=>''];
}
function videoHost($url) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./','',$host);
}

function avsf_supported_player_domains() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $list = [];
    $raw = function_exists('setting') ? trim((string)setting('vdohideSupportedDomains', '')) : '';
    if ($raw !== '') {
        foreach (preg_split('/[,\n\r]+/', $raw) as $d) {
            $d = strtolower(trim($d, " /"));
            if ($d !== '' && strpos($d, '.') !== false) $list[] = $d;
        }
    }
    $json = function_exists('setting') ? trim((string)setting('videoKnownPlayers', '')) : '';
    if ($json !== '') { $dec = json_decode($json, true); if (is_array($dec)) { foreach ($dec as $d) { $d = strtolower(trim((string)$d)); if ($d !== '') $list[] = $d; } } }
    $cached = array_values(array_unique($list));
    return $cached;
}

function isSupportedVideoPlayer($url) {
    $host = videoHost($url);
    if (preg_match('/(^|\.)fembed\.(co|com|net)$/',$host)===1) return true;
    foreach (avsf_supported_player_domains() as $d) {
        if ($host === $d || substr($host, -strlen($d)-1) === '.'.$d) return true;
    }
    return false;
}
function videoApiRemote($source) {
    $token = getenv('AVSF_VDOHIDE_TOKEN');
    if ($token===false || trim($token)==='') {
        $token = function_exists('setting') ? setting('vdohideApiToken', '') : '';
    }
    if (trim((string)$token)==='') return ['url'=>'','error'=>''];
    $sourceInfo = normalizeVideoInput($source);
    if ($sourceInfo['error']!=='' || $sourceInfo['url']==='') return ['url'=>'','error'=>$sourceInfo['error']];
    if (isSupportedVideoPlayer($sourceInfo['url'])) return ['url'=>$sourceInfo['url'],'error'=>''];
    if (!function_exists('curl_init')) return ['url'=>'','error'=>__('เซิร์ฟเวอร์ยังไม่ได้เปิด PHP cURL สำหรับ Remote API')];
    $payload = json_encode(['source'=>$sourceInfo['url'],'token'=>trim($token)]);
    if ($payload===false) return ['url'=>'','error'=>__('เตรียมข้อมูลสำหรับ Remote API ไม่สำเร็จ')];
    $curl = curl_init('https://vdohide.com/api/remote');
    if ($curl===false) return ['url'=>'','error'=>__('เริ่มการเชื่อมต่อ Remote API ไม่สำเร็จ')];
    $optionsSet=curl_setopt_array($curl,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2
    ]);
    if (!$optionsSet) {
        curl_close($curl);
        return ['url'=>'','error'=>__('ตั้งค่าการเชื่อมต่อ Remote API ไม่สำเร็จ')];
    }
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body===false || $status<200 || $status>=300) {
        error_log('AVSF remote video API failed; HTTP '.$status.($error!==''?' '.$error:''));
        return ['url'=>'','error'=>__('Remote API ไม่ตอบกลับสำเร็จ (HTTP %s) บันทึกลิงก์ต้นฉบับไว้แล้ว', [$status])];
    }
    $data = json_decode($body,true);
    $slug = is_array($data) && isset($data['slug'])?(string)$data['slug']:'';
    if (!preg_match('/^[A-Za-z0-9_-]{3,160}$/',$slug)) {
        error_log('AVSF remote video API returned an invalid response');
        return ['url'=>'','error'=>__('Remote API ไม่ส่ง player ID ที่ถูกต้อง บันทึกลิงก์ต้นฉบับไว้แล้ว')];
    }
    $playerBase = function_exists('setting') ? trim((string)setting('vdohideIframeDomain', '')) : '';
    $playerBase = rtrim($playerBase, '/');
    if (!preg_match('#^https://#i', $playerBase)) $playerBase = '';
    return ['url'=>$playerBase.'/embed/'.$slug.'/','error'=>''];
}
function videoEmbedUrl($url) {
    $parsed = parseVideo($url);
    return isset($parsed['embedUrl'])?$parsed['embedUrl']:'';
}
function parseVideo($input) {
    $normalized = normalizeVideoInput($input);
    if ($normalized['error']!=='' || $normalized['url']==='') return ['embed'=>'','thumb'=>'','embedUrl'=>'','contentUrl'=>'','error'=>$normalized['error']];
    $url = $normalized['url'];
    $host = videoHost($url);
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i',$url,$match)) {
        $id = $match[1];
        $embedUrl='https://www.youtube.com/embed/'.$id;
        return ['embed'=>'<iframe src="'.esc($embedUrl).'" title="Video player" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>','thumb'=>'https://img.youtube.com/vi/'.$id.'/hqdefault.jpg','embedUrl'=>$embedUrl,'contentUrl'=>'','error'=>''];
    }
    if ($host==='drive.google.com') {
        $driveUrl=preg_replace('/\/view(?:\?.*)?$/','/preview',$url);
        $driveUrl=preg_replace('/([?&])usp=[^&]+/','',$driveUrl);
        return ['embed'=>'<iframe src="'.esc($driveUrl).'" title="Video player" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; fullscreen"></iframe>','thumb'=>'','embedUrl'=>$driveUrl,'contentUrl'=>'','error'=>''];
    }
    if (preg_match('/(^|\.)fembed\.(co|com|net)$/',$host) && preg_match('~/((?:embed|v|f)/)?([A-Za-z0-9_-]{5,160})/?(?:\?.*)?$~i',parse_url($url,PHP_URL_PATH),$match)) {
        $embedUrl=rtrim((string)setting('vdohideIframeDomain',''),'/').'/embed/'.$match[2].'/';
        return ['embed'=>'<iframe src="'.esc($embedUrl).'" title="Video player" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; fullscreen; encrypted-media; picture-in-picture"></iframe>','thumb'=>'','embedUrl'=>$embedUrl,'contentUrl'=>'','error'=>''];
    }

    foreach (avsf_supported_player_domains() as $sd) {
        $sdHost = strtolower((string)parse_url((strpos($sd,'://')===false?'https://'.$sd:$sd), PHP_URL_HOST));
        if ($host === $sdHost || substr($host, -strlen($sdHost)-1) === '.'.$sdHost) {
            if (preg_match('~/(?:embed/)?([A-Za-z0-9_-]{5,160})(?:/|$)~i', parse_url($url,PHP_URL_PATH), $m2)) {
                $embedBase = rtrim((string)setting('vdohideIframeDomain',''), '/');
                if ($embedBase === '') $embedBase = 'https://'.$sdHost;
                $embedUrl = $embedBase.'/embed/'.$m2[1];
                return ['embed'=>'<iframe src="'.esc($embedUrl).'" title="Video player" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; fullscreen; encrypted-media; picture-in-picture"></iframe>','thumb'=>'','embedUrl'=>$embedUrl,'contentUrl'=>'','error'=>''];
            }
        }
    }
    $cfgDomain = trim((string)setting('vdohideIframeDomain',''));
    if ($cfgDomain!=='') { $cfgHost = strtolower((string)parse_url($cfgDomain, PHP_URL_HOST)); if ($host===$cfgHost && preg_match('~/(?:embed/)?([A-Za-z0-9_-]{5,160})(?:/|$)~',parse_url($url,PHP_URL_PATH),$match)) { $embedUrl=rtrim($cfgDomain,'/').'/embed/'.$match[1]; return ['embed'=>'<iframe src="'.esc($embedUrl).'" title="Video player" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; fullscreen; encrypted-media; picture-in-picture"></iframe>','thumb'=>'','embedUrl'=>$embedUrl,'contentUrl'=>'','error'=>'']; } }
    if (preg_match('/\.(mp4|webm|ogg)(?:$|\?)/i',$url)) {
        return ['embed'=>'<video src="'.esc($url).'" controls playsinline preload="metadata"></video>','thumb'=>'','embedUrl'=>'','contentUrl'=>$url,'error'=>''];
    }
    $embedUrl=$url;
    return ['embed'=>'<iframe src="'.esc($embedUrl).'" title="Video player" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; fullscreen; encrypted-media; picture-in-picture"></iframe>','thumb'=>'','embedUrl'=>$embedUrl,'contentUrl'=>'','error'=>''];
}
function isoVideoDuration($duration) {
    $parts=videoDurationParts($duration);
    if ($parts===null) return '';
    list($hours,$minutes,$seconds)=$parts;
    return 'PT'.($hours?$hours.'H':'').($minutes?$minutes.'M':'').($seconds?$seconds.'S':'0S');
}
function videoDurationParts($duration) {
    $duration=trim((string)$duration);
    if ($duration==='') return [0,0,0];
    if (preg_match('/^(\d{1,9}):([0-5]\d):([0-5]\d)$/',$duration,$match)) {
        return [(int)$match[1],(int)$match[2],(int)$match[3]];
    }
    if (preg_match('/^\d{1,9}$/',$duration)) {
        $total=(int)$duration;
        return [(int)floor($total/3600),(int)floor(($total%3600)/60),$total%60];
    }
    if (preg_match('/^(\d{1,9}):([0-5]?\d)$/',$duration,$match)) {
        $total=(int)$match[1]*60+(int)$match[2];
        return [(int)floor($total/3600),(int)floor(($total%3600)/60),$total%60];
    }
    if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i',$duration,$match) &&
        count(array_filter(array_slice($match,1),static function($part){return $part!=='';}))>0) {
        $total=((int)($match[1]??0)*86400)+((int)($match[2]??0)*3600)+((int)($match[3]??0)*60)+(int)($match[4]??0);
        return [(int)floor($total/3600),(int)floor(($total%3600)/60),$total%60];
    }
    if (preg_match('/^(?:(\d+)\s*(?:d|days?|วัน)\s*)?(?:(\d+)\s*(?:h|hours?|ชั่วโมง)\s*)?(?:(\d+)\s*(?:m|mins?|minutes?|นาที)\s*)?(?:(\d+)\s*(?:s|secs?|seconds?|วินาที)\s*)?$/iu',$duration,$match) &&
        trim(preg_replace('/\s+/u','',$duration))!=='' &&
        count(array_filter(array_slice($match,1),static function($part){return $part!=='';}))>0) {
        $total=((int)($match[1]??0)*86400)+((int)($match[2]??0)*3600)+((int)($match[3]??0)*60)+(int)($match[4]??0);
        return [(int)floor($total/3600),(int)floor(($total%3600)/60),$total%60];
    }
    return null;
}
function formatVideoDuration($duration) {
    $parts=videoDurationParts($duration);
    if ($parts===null || trim((string)$duration)==='') return '';
    list($hours,$minutes,$seconds)=$parts;
    return sprintf('%02d:%02d:%02d',$hours,$minutes,$seconds);
}
function autoMeta($p) {
    $mt = !empty($p['metaTitle']) ? $p['metaTitle'] : $p['title'];
    $md = !empty($p['metaDesc']) ? $p['metaDesc'] : mb_substr(stripHtml($p['content']), 0, 140, 'UTF-8');
    return ['title'=>$mt, 'desc'=>$md];
}
function clockIcon() {
    return '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
}
function avsfPlaySvg($size=24) {
    $size=(int)$size;
    if($size<14||$size>48)$size=24;
    return '<svg class="avsf-play-svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M8.25 5.94c0-1.17 1.28-1.88 2.26-1.26l9.18 5.81a1.78 1.78 0 0 1 0 3.02l-9.18 5.81a1.5 1.5 0 0 1-2.26-1.26V5.94Z" fill="currentColor"/></svg>';
}
function avsfSeoSiteName($fallback='') {
    $configured=trim((string)setting('seo_site_name',''));
    if($configured!=='')return $configured;
    $fallback=trim((string)$fallback);
    if($fallback!=='')return $fallback;
    $siteName=trim((string)setting('siteName',''));
    if($siteName!=='')return $siteName;
    return trim((string)setting('logoText','AVSF'))?:'AVSF';
}
function avsfBrandName() {
    $siteName = trim((string)setting('siteName', ''));
    if ($siteName !== '') return $siteName;
    $logoText = trim((string)setting('logoText', ''));
    return $logoText !== '' ? $logoText : 'AVSF';
}
function themeOn($key) { return setting('theme_'.$key, '1') === '1'; }
function toast($msg) { echo '<script>window.addEventListener("load",function(){var t=document.createElement("div");t.className="avsf-toast";t.textContent="'.esc($msg).'";document.body.appendChild(t);setTimeout(function(){t.remove();},2500);});</script>'; }
function splitTaxonomyNames($value) {
    $names=preg_split('/[,،;|，；\r\n]+/u',(string)$value);
    $names=array_map('trim',$names?:[]);
    return array_values(array_unique(array_filter($names,function($name){return $name!=='';})));
}
function avsfTaxonomyPostCounts($connection=null) {
    global $conn;
    $connection=$connection?:$conn;
    static $cachedConnection=null;
    static $cachedCounts=null;
    if($cachedConnection===$connection&&is_array($cachedCounts))return $cachedCounts;
    $counts=['category'=>[],'studio'=>[],'actor'=>[],'tag'=>[]];
    $result=mysqli_query($connection,"SELECT category,studioId,actorIds,tagIds FROM posts WHERE status='publish'");
    if(!$result) {
        error_log('AVSF taxonomy post-count query failed: '.mysqli_error($connection));
        throw new RuntimeException('Could not count taxonomy posts.');
    }
    while($row=mysqli_fetch_assoc($result)) {
        foreach(splitTaxonomyNames($row['category']??'') as $name) {
            $counts['category'][$name]=($counts['category'][$name]??0)+1;
        }
        foreach(parseTaxonomyIds($row['studioId']??0) as $id) {
            $counts['studio'][$id]=($counts['studio'][$id]??0)+1;
        }
        foreach(parseTaxonomyIds($row['actorIds']??'[]') as $id) {
            $counts['actor'][$id]=($counts['actor'][$id]??0)+1;
        }
        foreach(parseTaxonomyIds($row['tagIds']??'[]') as $id) {
            $counts['tag'][$id]=($counts['tag'][$id]??0)+1;
        }
    }
    mysqli_free_result($result);
    $cachedConnection=$connection;
    $cachedCounts=$counts;
    return $counts;
}

function taxonomySeoValue($type,$field,$name,$siteName,$override='',$postCount=0) {
    $siteName=avsfSeoSiteName($siteName);
    $override=trim((string)$override);
    $key='seo_taxonomy_'.$type.'_'.$field;
    $tokens=['category'=>'cat','studio'=>'studio','actor'=>'actor','tag'=>'tag'];
    $defaultTitle=['category'=>'%%cat%% | %%sitename%%','studio'=>__('%%studio%% | ผลงานและเรื่องใหม่ | %%sitename%%'),'actor'=>__('%%actor%% | ผลงานทั้งหมด | %%sitename%%'),'tag'=>'%%tag%% | %%sitename%%'];
    $defaultDesc=['category'=>__('รวมเรื่องในหมวด %%cat%% อัปเดตล่าสุดบน %%sitename%%'),'studio'=>__('รวมผลงานจากค่าย %%studio%% อัปเดตล่าสุดบน %%sitename%%'),'actor'=>__('รวมผลงานของ %%actor%% อัปเดตล่าสุดบน %%sitename%%'),'tag'=>__('รวมเรื่องที่ติดแท็ก %%tag%% อัปเดตล่าสุดบน %%sitename%%')];
    $template=$override!==''?$override:trim(setting($key,$field==='title'?$defaultTitle[$type]:$defaultDesc[$type]));
    if($template==='')$template=$field==='title'?$defaultTitle[$type]:$defaultDesc[$type];
    $template=strtr($template,['{name}'=>'%%'.$tokens[$type].'%%','{site}'=>'%%sitename%%','{type}'=>$type]);
    $rendered=renderSeoTemplate($template,[
        'name'=>$name,'title'=>$name,'term_title'=>$name,
        'site_name'=>$siteName,'site_desc'=>setting('siteDesc'),'primary_keyword'=>setting('seo_primary_keyword',''),
        'site'=>$siteName,$tokens[$type]=>$name,'post_count'=>$postCount,'item_count'=>$postCount
    ]);
    return trim($rendered)!==''?$rendered:($field==='title'?$name:$siteName);
}
function renderSeoTemplate($template,$variables=[]) {
    $siteName=avsfSeoSiteName(isset($variables['site_name'])?(string)$variables['site_name']:(isset($variables['sitename'])?(string)$variables['sitename']:''));
    $siteDescription=isset($variables['site_desc'])?(string)$variables['site_desc']:(isset($variables['sitedesc'])?(string)$variables['sitedesc']:(string)setting('siteDesc',''));
    $primaryKeyword=isset($variables['primary_keyword'])?(string)$variables['primary_keyword']:(isset($variables['focus_keyphrase'])?(string)$variables['focus_keyphrase']:(string)setting('seo_primary_keyword',''));
    $termName=isset($variables['term_title'])?(string)$variables['term_title']:(isset($variables['name'])?(string)$variables['name']:(isset($variables['cat'])?(string)$variables['cat']:(isset($variables['category'])?(string)$variables['category']:(isset($variables['studio'])?(string)$variables['studio']:(isset($variables['actor'])?(string)$variables['actor']:(isset($variables['tag'])?(string)$variables['tag']:$siteName))))));
    $values=[
        'site'=>$siteName,'site_name'=>$siteName,'sitename'=>$siteName,
        'site_desc'=>$siteDescription,'sitedesc'=>$siteDescription,
        'primary_keyword'=>$primaryKeyword,'focus_keyphrase'=>$primaryKeyword,
        'title'=>isset($variables['title'])?(string)$variables['title']:$termName,
        'term_title'=>$termName,'name'=>isset($variables['name'])?(string)$variables['name']:$termName,
        'category'=>isset($variables['category'])?(string)$variables['category']:(isset($variables['cat'])?(string)$variables['cat']:''),
        'cat'=>isset($variables['cat'])?(string)$variables['cat']:(isset($variables['category'])?(string)$variables['category']:''),
        'excerpt'=>isset($variables['excerpt'])?(string)$variables['excerpt']:$siteDescription,
        'post_count'=>'','item_count'=>'','page'=>'1','sep'=>setting('seo_title_separator','|'),
        'currentyear'=>date('Y'),'year'=>date('Y')
    ];
    $values=array_merge($values,$variables);
    // SEO Site Name is authoritative for title templates even when a page passes the public brand name.
    $resolvedSiteName=avsfSeoSiteName(trim((string)($values['site_name']??''))?:trim((string)($values['sitename']??''))?:$siteName);
    $values['site']=$resolvedSiteName;
    $values['site_name']=$resolvedSiteName;
    $values['sitename']=$resolvedSiteName;
    $replacements=[];
    foreach($values as $key=>$value)$replacements['%%'.$key.'%%']=(string)$value;
    $replacements['%%primary_category%%']=$values['category'];
    return trim(strtr((string)$template,$replacements));
}
function renderSeoSetting($key,$default,$variables=[]) {
    $template=trim((string)setting($key,''));
    return renderSeoTemplate($template!==''?$template:$default,$variables);
}

/** Ensure SEO override fields exist for static pages; safe to run repeatedly. */
function ensurePagesSeoColumns($connection=null) {
    global $conn;
    $connection=$connection?:$conn;
    if(!$connection)throw new RuntimeException('Database connection is unavailable for page SEO migration.');
    $columns=[
        'metaTitle'=>"VARCHAR(200) NOT NULL DEFAULT ''",
        'metaDesc'=>"VARCHAR(300) NOT NULL DEFAULT ''"
    ];
    foreach($columns as $column=>$definition) {
        $columnE=mysqli_real_escape_string($connection,$column);
        $check=mysqli_query($connection,"SHOW COLUMNS FROM pages LIKE '$columnE'");
        if(!$check)throw new RuntimeException('Could not inspect pages SEO columns: '.mysqli_error($connection));
        $exists=mysqli_num_rows($check)>0;
        mysqli_free_result($check);
        if(!$exists&&!mysqli_query($connection,"ALTER TABLE pages ADD COLUMN `$column` $definition"))
            throw new RuntimeException('Could not add pages SEO column '.$column.': '.mysqli_error($connection));
    }
    return true;
}

/** Remove a per-record index override so the global SEO policy applies again. */
function resetSeoIndexOverride($entityType,$entityId,$connection=null) {
    global $conn;
    $connection=$connection?:$conn;
    $allowedTypes=['post','page','category','studio','actor','tag'];
    $entityId=(int)$entityId;
    if(!$connection||!in_array($entityType,$allowedTypes,true)||$entityId<1)
        throw new InvalidArgumentException('Invalid SEO index override reset target.');
    ensureSeoIndexOverridesTable($connection);
    $stmt=mysqli_prepare($connection,'DELETE FROM seo_index_overrides WHERE entity_type=? AND entity_id=?');
    if(!$stmt)throw new RuntimeException('Could not prepare SEO override reset: '.mysqli_error($connection));
    mysqli_stmt_bind_param($stmt,'si',$entityType,$entityId);
    $ok=mysqli_stmt_execute($stmt);
    if(!$ok)error_log('AVSF SEO override reset failed: '.mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    if(!$ok)throw new RuntimeException('Could not reset SEO index override.');
    return true;
}

function ensureCategoriesTable($conn) {
    $sql="CREATE TABLE IF NOT EXISTS categories (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(150) NOT NULL,
        metaTitle VARCHAR(200) DEFAULT '',
        metaDesc VARCHAR(300) DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if(!mysqli_query($conn,$sql))throw new RuntimeException('Could not create categories table: '.mysqli_error($conn));
    $column=mysqli_query($conn,"SELECT CHARACTER_MAXIMUM_LENGTH AS maxLength FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categories' AND COLUMN_NAME='name' LIMIT 1");
    if(!$column)throw new RuntimeException('Could not inspect category name column: '.mysqli_error($conn));
    $columnInfo=mysqli_fetch_assoc($column);
    if($columnInfo&&(int)$columnInfo['maxLength']<150&&!mysqli_query($conn,"ALTER TABLE categories MODIFY name VARCHAR(150) NOT NULL"))throw new RuntimeException('Could not expand category names: '.mysqli_error($conn));
    $marker=mysqli_fetch_assoc(mysqli_query($conn,"SELECT svalue FROM settings WHERE skey='taxonomy_categories_seeded' LIMIT 1"));
    if($marker)return;
    $posts=mysqli_query($conn,"SELECT category FROM posts WHERE category IS NOT NULL AND category<>''");
    if(!$posts)throw new RuntimeException('Could not read post categories: '.mysqli_error($conn));
    while($post=mysqli_fetch_assoc($posts)) {
        foreach(splitTaxonomyNames($post['category']) as $name) {
            $find=mysqli_prepare($conn,"SELECT id FROM categories WHERE name=? LIMIT 1");
            if(!$find)throw new RuntimeException('Could not prepare category lookup: '.mysqli_error($conn));
            mysqli_stmt_bind_param($find,'s',$name);
            mysqli_stmt_execute($find);mysqli_stmt_store_result($find);
            $exists=mysqli_stmt_num_rows($find)>0;mysqli_stmt_close($find);
            if($exists)continue;
            $slug=uniqueSlugForName($conn,'categories',$name);
            $stmt=mysqli_prepare($conn,"INSERT INTO categories (name,slug) VALUES (?,?)");
            if(!$stmt)throw new RuntimeException('Could not prepare category migration: '.mysqli_error($conn));
            mysqli_stmt_bind_param($stmt,'ss',$name,$slug);
            if(!mysqli_stmt_execute($stmt)) {
                $error=mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);
                throw new RuntimeException('Could not migrate post category: '.$error);
            }
            mysqli_stmt_close($stmt);
        }
    }
    if(!mysqli_query($conn,"INSERT INTO settings (skey,svalue) VALUES ('taxonomy_categories_seeded','1')")) {
        throw new RuntimeException('Could not save category migration state: '.mysqli_error($conn));
    }
}
function uniqueSlugForName($conn,$table,$name,$excludeId=0) {
    $limits=['studios'=>150,'actors'=>150,'tags'=>150,'categories'=>150,'posts'=>200,'pages'=>150];
    if(!isset($limits[$table]))throw new InvalidArgumentException('Unsupported slug table');
    $maxLength=$limits[$table];
    $base=trim(mb_substr(slugify($name),0,$maxLength,'UTF-8'),'-')?:'item';
    $slug=$base;$suffix=2;
    while(true) {
        $stmt=mysqli_prepare($conn,"SELECT id FROM `$table` WHERE slug=? AND id<>? LIMIT 1");
        if(!$stmt)throw new RuntimeException('Could not prepare slug lookup: '.mysqli_error($conn));
        $excluded=(int)$excludeId;
        mysqli_stmt_bind_param($stmt,'si',$slug,$excluded);
        if(!mysqli_stmt_execute($stmt)) {
            $error=mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);
            throw new RuntimeException('Could not check slug: '.$error);
        }
        mysqli_stmt_store_result($stmt);$used=mysqli_stmt_num_rows($stmt)>0;mysqli_stmt_close($stmt);
        if(!$used)return $slug;
        $ending='-'.$suffix++;
        $slug=trim(mb_substr($base,0,$maxLength-mb_strlen($ending,'UTF-8'),'UTF-8'),'-').$ending;
    }
}
function saveSlugAlias($conn,$entityType,$oldSlug,$newSlug) {
    if($oldSlug===''||$newSlug===''||$oldSlug===$newSlug)return;
    if(!in_array($entityType,['post','page','studio','actor','tag','category'],true))throw new InvalidArgumentException('Unsupported slug alias type');
    $chain=mysqli_prepare($conn,"UPDATE slug_aliases SET newSlug=? WHERE entityType=? AND newSlug=?");
    if(!$chain)throw new RuntimeException('Could not prepare legacy slug chain update: '.mysqli_error($conn));
    mysqli_stmt_bind_param($chain,'sss',$newSlug,$entityType,$oldSlug);
    if(!mysqli_stmt_execute($chain)) {
        $error=mysqli_stmt_error($chain);mysqli_stmt_close($chain);
        throw new RuntimeException('Could not update legacy slug chain: '.$error);
    }
    mysqli_stmt_close($chain);
    $stmt=mysqli_prepare($conn,"INSERT INTO slug_aliases (entityType,oldSlug,newSlug) VALUES (?,?,?) ON DUPLICATE KEY UPDATE newSlug=VALUES(newSlug)");
    if(!$stmt)throw new RuntimeException('Could not prepare slug alias: '.mysqli_error($conn));
    mysqli_stmt_bind_param($stmt,'sss',$entityType,$oldSlug,$newSlug);
    if(!mysqli_stmt_execute($stmt)) {
        $error=mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);
        throw new RuntimeException('Could not save legacy slug: '.$error);
    }
    mysqli_stmt_close($stmt);
}
function ensurePrimarySlugs($conn) {
    $create="CREATE TABLE IF NOT EXISTS slug_aliases (
        id INT NOT NULL AUTO_INCREMENT,
        entityType VARCHAR(20) NOT NULL,
        oldSlug VARCHAR(200) NOT NULL,
        newSlug VARCHAR(200) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY entity_old_slug (entityType,oldSlug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if(!mysqli_query($conn,$create))throw new RuntimeException('Could not create slug alias table: '.mysqli_error($conn));
    $marker=mysqli_fetch_assoc(mysqli_query($conn,"SELECT svalue FROM settings WHERE skey='primary_slugs_v2' LIMIT 1"));
    if($marker)return;
    if(!mysqli_begin_transaction($conn))throw new RuntimeException('Could not start slug migration: '.mysqli_error($conn));
    try {
        $entities=[
            'studios'=>['name','studio'],'actors'=>['name','actor'],'tags'=>['name','tag'],
            'categories'=>['name','category'],'posts'=>['title','post'],'pages'=>['title','page']
        ];
        foreach($entities as $table=>$definition) {
            list($nameField,$entityType)=$definition;
            $rows=mysqli_query($conn,"SELECT id,`$nameField` AS entityName,slug FROM `$table` ORDER BY id");
            if(!$rows)throw new RuntimeException('Could not read slugs from '.$table.': '.mysqli_error($conn));
            $items=[];
            while($row=mysqli_fetch_assoc($rows))$items[]=$row;
            foreach($items as $item) {
                $id=(int)$item['id'];$oldSlug=(string)$item['slug'];
                $newSlug=uniqueSlugForName($conn,$table,$item['entityName'],$id);
                if($newSlug===$oldSlug)continue;
                $stmt=mysqli_prepare($conn,"UPDATE `$table` SET slug=? WHERE id=?");
                if(!$stmt)throw new RuntimeException('Could not prepare slug update: '.mysqli_error($conn));
                mysqli_stmt_bind_param($stmt,'si',$newSlug,$id);
                if(!mysqli_stmt_execute($stmt)) {
                    $error=mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);
                    throw new RuntimeException('Could not update slug in '.$table.': '.$error);
                }
                mysqli_stmt_close($stmt);
                if($oldSlug!=='') {
                    saveSlugAlias($conn,$entityType,$oldSlug,$newSlug);
                }
            }
        }
        if(!mysqli_query($conn,"INSERT INTO settings (skey,svalue) VALUES ('primary_slugs_v2','1')"))throw new RuntimeException('Could not save slug migration state: '.mysqli_error($conn));
        if(!mysqli_commit($conn))throw new RuntimeException('Could not commit slug migration: '.mysqli_error($conn));
    } catch(Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}
function ensureSplitTaxonomyNames($conn) {
    $marker=mysqli_fetch_assoc(mysqli_query($conn,"SELECT svalue FROM settings WHERE skey='taxonomy_names_split_v1' LIMIT 1"));
    if($marker)return;
    if(!mysqli_begin_transaction($conn))throw new RuntimeException('Could not start taxonomy migration: '.mysqli_error($conn));
    try {
        foreach(['actors','tags'] as $table) {
            $result=mysqli_query($conn,"SELECT * FROM `$table` ORDER BY id");
            if(!$result)throw new RuntimeException('Could not read '.$table.' for migration: '.mysqli_error($conn));
            $items=[];
            while($item=mysqli_fetch_assoc($result))$items[]=$item;
            $idMap=[];
            foreach($items as $item) {
                $names=splitTaxonomyNames($item['name']);
                if(count($names)<2)continue;
                $id=(int)$item['id'];$ids=[];
                foreach($names as $index=>$name) {
                    $lookup=mysqli_prepare($conn,"SELECT id FROM `$table` WHERE name=? AND id<>? LIMIT 1");
                    if(!$lookup)throw new RuntimeException('Could not prepare '.$table.' lookup: '.mysqli_error($conn));
                    mysqli_stmt_bind_param($lookup,'si',$name,$id);mysqli_stmt_execute($lookup);mysqli_stmt_bind_result($lookup,$existingId);
                    $existing=mysqli_stmt_fetch($lookup)?['id'=>$existingId]:null;mysqli_stmt_close($lookup);
                    if($index===0 && !$existing) {
                        $newSlug=uniqueSlugForName($conn,$table,$name,$id);
                        $update=mysqli_prepare($conn,"UPDATE `$table` SET name=?,slug=? WHERE id=?");
                        if(!$update)throw new RuntimeException('Could not prepare '.$table.' update: '.mysqli_error($conn));
                        mysqli_stmt_bind_param($update,'ssi',$name,$newSlug,$id);
                        if(!mysqli_stmt_execute($update))throw new RuntimeException('Could not update '.$table.': '.mysqli_stmt_error($update));
                        mysqli_stmt_close($update);$termId=$id;
                    } elseif($existing) {
                        $termId=(int)$existing['id'];
                        $columns=$table==='actors'?['photo','bio','metaTitle','metaDesc']:['metaTitle','metaDesc'];
                        foreach($columns as $column) {
                            $read=mysqli_prepare($conn,"SELECT `$column` FROM `$table` WHERE id=?");
                            if(!$read)throw new RuntimeException('Could not prepare '.$table.' metadata read: '.mysqli_error($conn));
                            mysqli_stmt_bind_param($read,'i',$id);mysqli_stmt_execute($read);mysqli_stmt_bind_result($read,$sourceValue);
                            $hasSource=mysqli_stmt_fetch($read);mysqli_stmt_close($read);
                            if(!$hasSource||$sourceValue===null||$sourceValue==='')continue;
                            $merge=mysqli_prepare($conn,"UPDATE `$table` SET `$column`=? WHERE id=? AND (`$column` IS NULL OR `$column`='')");
                            if(!$merge)throw new RuntimeException('Could not prepare '.$table.' metadata merge: '.mysqli_error($conn));
                            mysqli_stmt_bind_param($merge,'si',$sourceValue,$termId);
                            if(!mysqli_stmt_execute($merge))throw new RuntimeException('Could not preserve '.$table.' metadata: '.mysqli_stmt_error($merge));
                            mysqli_stmt_close($merge);
                        }
                    } else {
                        $newSlug=uniqueSlugForName($conn,$table,$name);
                        if($table==='actors')$insert=mysqli_prepare($conn,"INSERT INTO actors (name,slug) VALUES (?,?)");
                        else $insert=mysqli_prepare($conn,"INSERT INTO tags (name,slug) VALUES (?,?)");
                        if(!$insert)throw new RuntimeException('Could not prepare '.$table.' insert: '.mysqli_error($conn));
                        mysqli_stmt_bind_param($insert,'ss',$name,$newSlug);
                        if(!mysqli_stmt_execute($insert))throw new RuntimeException('Could not insert '.$table.': '.mysqli_stmt_error($insert));
                        $termId=(int)mysqli_insert_id($conn);mysqli_stmt_close($insert);
                    }
                    $ids[]=$termId;
                }
                $idMap[$id]=$ids;
                if(isset($ids[0])) {
                    $oldSlug=(string)$item['slug'];$newSlug=(string)mysqli_fetch_assoc(mysqli_query($conn,"SELECT slug FROM `$table` WHERE id=".(int)$ids[0]))['slug'];
                    if($oldSlug!==''&&$oldSlug!==$newSlug) {
                        $entityType=$table==='actors'?'actor':'tag';
                        saveSlugAlias($conn,$entityType,$oldSlug,$newSlug);
                    }
                }
                if(isset($ids[0])&&$ids[0]!==$id) {
                    $delete=mysqli_prepare($conn,"DELETE FROM `$table` WHERE id=?");
                    if(!$delete)throw new RuntimeException('Could not prepare duplicate '.$table.' cleanup: '.mysqli_error($conn));
                    mysqli_stmt_bind_param($delete,'i',$id);
                    if(!mysqli_stmt_execute($delete))throw new RuntimeException('Could not remove combined '.$table.' entry: '.mysqli_stmt_error($delete));
                    mysqli_stmt_close($delete);
                }
            }
            if(!$idMap)continue;
            $posts=mysqli_query($conn,"SELECT id,`".($table==='actors'?'actorIds':'tagIds')."` AS taxonomyIds FROM posts");
            if(!$posts)throw new RuntimeException('Could not read post references: '.mysqli_error($conn));
            $updates=[];
            while($post=mysqli_fetch_assoc($posts)) {
                $oldIds=parseTaxonomyIds($post['taxonomyIds']);$newIds=[];
                foreach($oldIds as $oldId)foreach($idMap[$oldId]??[$oldId] as $newId)$newIds[]=(int)$newId;
                $newIds=array_values(array_unique($newIds));
                if($newIds!==$oldIds)$updates[]=[(int)$post['id'],json_encode($newIds)];
            }
            $column=$table==='actors'?'actorIds':'tagIds';
            $updatePost=mysqli_prepare($conn,"UPDATE posts SET `$column`=? WHERE id=?");
            if(!$updatePost)throw new RuntimeException('Could not prepare post taxonomy update: '.mysqli_error($conn));
            foreach($updates as $update) {
                mysqli_stmt_bind_param($updatePost,'si',$update[1],$update[0]);
                if(!mysqli_stmt_execute($updatePost))throw new RuntimeException('Could not update post taxonomy: '.mysqli_stmt_error($updatePost));
            }
            mysqli_stmt_close($updatePost);
        }
        if(!mysqli_query($conn,"INSERT INTO settings (skey,svalue) VALUES ('taxonomy_names_split_v1','1')"))throw new RuntimeException('Could not save taxonomy migration state: '.mysqli_error($conn));
        if(!mysqli_commit($conn))throw new RuntimeException('Could not commit taxonomy migration: '.mysqli_error($conn));
    } catch(Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}
function ensureCategoryName($conn,$name) {
    $name=trim((string)$name);
    if($name==='')return;
    $find=mysqli_prepare($conn,"SELECT id FROM categories WHERE name=? LIMIT 1");
    if(!$find)throw new RuntimeException('Could not prepare category lookup: '.mysqli_error($conn));
    mysqli_stmt_bind_param($find,'s',$name);mysqli_stmt_execute($find);mysqli_stmt_store_result($find);
    $exists=mysqli_stmt_num_rows($find)>0;mysqli_stmt_close($find);
    if($exists)return;
    $slug=uniqueSlugForName($conn,'categories',$name);
    $insert=mysqli_prepare($conn,"INSERT INTO categories (name,slug) VALUES (?,?)");
    if(!$insert)throw new RuntimeException('Could not prepare category insert: '.mysqli_error($conn));
    mysqli_stmt_bind_param($insert,'ss',$name,$slug);
    if(!mysqli_stmt_execute($insert)) {
        $error=mysqli_stmt_error($insert);mysqli_stmt_close($insert);
        throw new RuntimeException('Could not save category: '.$error);
    }
    mysqli_stmt_close($insert);
}



/** Build the mandatory footer attribution from the configured canonical Site URL. */
function avsfCopyrightNotice() {
    $base = function_exists('avsfCanonicalBaseUrl') ? avsfCanonicalBaseUrl() : '';
    $host = $base !== '' ? (string)parse_url($base, PHP_URL_HOST) : '';
    if ($host === '') $host = 'localhost';
    $host = strtolower(trim($host));
    $host = preg_replace('/[^a-z0-9.:-\[\]]/i', '', $host);
    if ($host === '') $host = 'localhost';
    $timezoneName = function_exists('setting') ? (string)setting('siteTimezone', 'Asia/Bangkok') : 'Asia/Bangkok';
    try {
        $allowed = DateTimeZone::listIdentifiers();
        if (!in_array($timezoneName, $allowed, true)) $timezoneName = 'Asia/Bangkok';
        $year = (new DateTimeImmutable('now', new DateTimeZone($timezoneName)))->format('Y');
    } catch (Throwable $e) {
        $year = date('Y');
    }
    return '© ' . $year . ' - ' . $host . '. All rights reserved. Powered by B-NTP';
}
