<?php
/** Shared AVSF image matching for Dashboard Advanced Draft and Bulk Import. */
function avsfNormalizeImageKey($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\.(?:jpe?g|png|webp|gif|avif|bmp)$/i', '', basename(str_replace('\\', '/', $value)));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    return trim((string)$value, '-');
}

function avsfFindImportImagesByCode($images, $code, $coverName = '', $posterName = '') {
    $codeKey = avsfNormalizeImageKey($code);
    if ($codeKey === '' || !is_array($images)) return [];
    $coverKey = avsfNormalizeImageKey($coverName);
    $posterKey = avsfNormalizeImageKey($posterName);
    $matches = []; $seen = [];
    foreach ($images as $image) {
        if (!is_array($image) || empty($image['path']) || !is_file($image['path'])) continue;
        $real = realpath($image['path']);
        if ($real === false || isset($seen[$real])) continue;
        $name = (string)($image['name'] ?? basename((string)$image['path']));
        $stem = avsfNormalizeImageKey($name);
        if ($stem === '') continue;
        $role = ''; $score = 0; $intrinsicRole = '';
        if ($stem === $codeKey) $intrinsicRole = 'cover';
        elseif (strpos($stem, $codeKey . '-') === 0) {
            $suffix = substr($stem, strlen($codeKey) + 1);
            // Explicit Gallery marker always wins; never promote a Gallery file to cover.
            if (preg_match('/(?:^|-)gallery(?:-|$)/', $suffix)) $intrinsicRole = 'gallery';
            elseif (preg_match('/^cover(?:-|$)/', $suffix)) $intrinsicRole = 'cover';
            elseif (preg_match('/^poster(?:-|$)/', $suffix)) $intrinsicRole = 'poster';
            // Legacy/import convention: CODE-01.jpg, CODE-02.jpg are Gallery images.
            elseif (preg_match('/^\d{1,4}$/', $suffix)) $intrinsicRole = 'gallery';
            // Older imports renamed files to CODE-<hash>.webp. Keep them attached as gallery.
            elseif (preg_match('/^[a-f0-9]{8,40}$/', $suffix)) $intrinsicRole = 'gallery';
        }
        // Explicit row references may identify a non-standard filename, but cannot override a Gallery/Poster marker.
        if ($coverKey !== '' && $stem === $coverKey && $intrinsicRole !== 'gallery' && $intrinsicRole !== 'poster') { $role = 'cover'; $score = 1000; }
        elseif ($posterKey !== '' && $stem === $posterKey && $intrinsicRole !== 'gallery' && $intrinsicRole !== 'cover') { $role = 'poster'; $score = 990; }
        elseif ($intrinsicRole === 'cover') { $role = 'cover'; $score = ($stem === $codeKey ? 500 : 450); }
        elseif ($intrinsicRole === 'poster') { $role = 'poster'; $score = 300; }
        elseif ($intrinsicRole === 'gallery') { $role = 'gallery'; $score = 200; }
        if ($role !== '') {
            $image['role'] = $role;
            $image['score'] = $score;
            $image['_real'] = $real;
            $matches[] = $image;
            $seen[$real] = true;
        }
    }
    usort($matches, function($a, $b) {
        $score = (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
        return $score ?: strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return $matches;
}

function avsfConvertImageToWebp($source, $destination, $quality = 82) {
    $quality = max(60, min(92, (int)$quality));
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick($source);
            if (method_exists($image, 'setIteratorIndex')) $image->setIteratorIndex(0);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);
            $image->stripImage();
            $ok = $image->writeImage($destination);
            $image->clear(); $image->destroy();
            return $ok && is_file($destination) && filesize($destination) > 0;
        } catch (Throwable $error) { @unlink($destination); }
    }
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $binary = @file_get_contents($source);
        $image = $binary !== false ? @imagecreatefromstring($binary) : false;
        if ($image !== false) {
            if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($image);
            if (function_exists('imagealphablending')) @imagealphablending($image, false);
            if (function_exists('imagesavealpha')) @imagesavealpha($image, true);
            $ok = @imagewebp($image, $destination, $quality);
            if (function_exists('imagedestroy')) imagedestroy($image);
            return $ok && is_file($destination) && filesize($destination) > 0;
        }
    }
    return false;
}

function avsfCurrentBasePath() {
    if (function_exists('avsfCanonicalBasePath')) return avsfCanonicalBasePath();
    return '';
}

function avsfStoreImportImage($source, $code = '', $originalName = '') {
    if (!is_file($source) || !is_readable($source)) return '';
    $original = (string)($originalName !== '' ? $originalName : basename($source));
    $original = str_replace('\\', '/', $original);
    $name = basename($original);
    // Keep the uploaded filename. Only remove path/control characters that could escape the upload directory.
    $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $name);
    $name = trim((string)$name);
    if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 240) return '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif','avif'], true)) return '';
    $imageInfo = @getimagesize($source);
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/avif'];
    if (!$imageInfo || empty($imageInfo['mime']) || !in_array(strtolower($imageInfo['mime']), $allowed, true)) return '';
    $pixels = (int)($imageInfo[0] ?? 0) * (int)($imageInfo[1] ?? 0);
    if ($pixels < 1 || $pixels > 30000000) return '';

    $root = AVSF_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'import-media';
    if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) return '';
    $safeCode = avsfNormalizeImageKey($code);
    if ($safeCode === '') $safeCode = 'uncoded';
    $codePrefix = $safeCode . '-';
    $stem = avsfNormalizeImageKey(pathinfo($name, PATHINFO_FILENAME));

    // Keep standard CODE-prefixed names directly in import-media to preserve existing URL conventions.
    // For custom names or filename collisions across codes, isolate by Code while retaining the basename.
    $destinationDir = $root;
    $isCodeNamed = ($stem === $safeCode || strpos($stem, $codePrefix) === 0);
    $destination = $destinationDir . DIRECTORY_SEPARATOR . $name;
    if (!$isCodeNamed) {
        $destinationDir = $root . DIRECTORY_SEPARATOR . $safeCode;
        $destination = $destinationDir . DIRECTORY_SEPARATOR . $name;
    } elseif (is_file($destination)) {
        $sourceHash = @hash_file('sha256', $source);
        $existingHash = @hash_file('sha256', $destination);
        if ($sourceHash !== false && $existingHash !== false && hash_equals($existingHash, $sourceHash)) {
            return avsfCurrentBasePath() . '/uploads/import-media/' . rawurlencode($name);
        }
        // The same CODE filename can be refreshed in place; a different CODE is isolated in its own folder.
        $existingStem = avsfNormalizeImageKey(pathinfo($name, PATHINFO_FILENAME));
        if (strpos($existingStem, $safeCode) !== 0) {
            $destinationDir = $root . DIRECTORY_SEPARATOR . $safeCode;
            $destination = $destinationDir . DIRECTORY_SEPARATOR . $name;
        }
    }
    if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) return '';
    if (!is_writable($destinationDir)) return '';

    $temp = $destinationDir . DIRECTORY_SEPARATOR . '.avsf-' . bin2hex(random_bytes(8)) . '.tmp';
    if (!@copy($source, $temp)) { @unlink($temp); return ''; }
    @chmod($temp, 0644);
    if (is_file($destination)) {
        $backup = $destination . '.avsf-old-' . bin2hex(random_bytes(4));
        if (!@rename($destination, $backup)) { @unlink($temp); return ''; }
        if (!@rename($temp, $destination)) {
            @rename($backup, $destination);
            @unlink($temp);
            return '';
        }
        @unlink($backup);
    } elseif (!@rename($temp, $destination)) {
        @unlink($temp);
        return '';
    }

    $relative = 'uploads/import-media/';
    if ($destinationDir !== $root) $relative .= rawurlencode($safeCode) . '/';
    $relative .= rawurlencode($name);
    return avsfCurrentBasePath() . '/' . $relative;
}

function avsfCollectUploadedImageFiles($fieldNames, $maxFiles = 3000, $maxSize = 26214400) {
    $images = []; $errors = [];
    foreach ((array)$fieldNames as $field) {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) continue;
        $files = $_FILES[$field];
        $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
        $temps = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
        $errs = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
        foreach ($names as $i => $name) {
            $err = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) { $errors[] = __('อัปโหลดรูป %s ไม่สำเร็จ', [basename((string)$name)]); continue; }
            if (count($images) >= $maxFiles) { $errors[] = __('เลือกภาพเกินจำนวนสูงสุด %s ไฟล์', [$maxFiles]); break 2; }
            $path = (string)($temps[$i] ?? '');
            $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            if ((int)($sizes[$i] ?? 0) < 1 || (int)($sizes[$i] ?? 0) > $maxSize) { $errors[] = __('รูป %s ต้องมีขนาดไม่เกิน %s MB', [basename((string)$name), round($maxSize / 1048576)]); continue; }
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif','avif'], true) || !is_file($path) || !@getimagesize($path)) { $errors[] = __('ไฟล์ %s ไม่ใช่รูปภาพที่รองรับ', [basename((string)$name)]); continue; }
            if (function_exists('is_uploaded_file') && !is_uploaded_file($path)) { $errors[] = __('ไฟล์อัปโหลด %s ไม่ผ่านการตรวจสอบ', [basename((string)$name)]); continue; }
            $images[] = ['path'=>$path, 'name'=>basename((string)$name)];
        }
    }
    return ['images'=>$images, 'errors'=>$errors];
}

function avsfResolveImportImageValue($value, $images, $code, $role = 'cover') {
    $value = trim((string)$value);
    if ($value === '') return '';
    $normalized = function_exists('normalizePublicImageReference') ? normalizePublicImageReference($value) : $value;
    if (preg_match('#^(?:https?://|/)#i', $normalized)) return $normalized;
    $wanted = strtolower(basename(str_replace('\\', '/', $value)));
    $classified = avsfFindImportImagesByCode($images, $code);
    $classifiedByPath = [];
    foreach ($classified as $item) $classifiedByPath[$item['_real'] ?? realpath($item['path'])] = (string)($item['role'] ?? '');
    foreach ((array)$images as $image) {
        if (strtolower(basename((string)($image['name'] ?? ''))) === $wanted && !empty($image['path']) && is_file($image['path'])) {
            $real = realpath($image['path']);
            $detectedRole = $real !== false ? (string)($classifiedByPath[$real] ?? '') : '';
            if ($detectedRole !== '' && $detectedRole !== $role) return '';
            return avsfStoreImportImage($image['path'], $code, $image['name']);
        }
    }
    return '';
}
