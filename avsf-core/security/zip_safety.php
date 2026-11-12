<?php
/**
 * Safe ZIP extraction for AVSF.
 * Rejects traversal paths, symlinks, oversized archives and file collisions.
 */
if (!function_exists('avsfSafeExtractZip')) {
    function avsfSafeExtractZip($zipPath, $destination, $limits = []) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('เซิร์ฟเวอร์ยังไม่มี ZipArchive extension');
        }
        $maxEntries = isset($limits['max_entries']) ? (int)$limits['max_entries'] : 3000;
        $maxFileBytes = isset($limits['max_file_bytes']) ? (int)$limits['max_file_bytes'] : 64 * 1024 * 1024;
        $maxTotalBytes = isset($limits['max_total_bytes']) ? (int)$limits['max_total_bytes'] : 512 * 1024 * 1024;
        $maxRatio = isset($limits['max_ratio']) ? (float)$limits['max_ratio'] : 200.0;
        $zipPath = (string)$zipPath;
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException('ไม่พบไฟล์ ZIP หรืออ่านไฟล์ไม่ได้');
        }
        if (file_exists($destination)) {
            throw new RuntimeException('โฟลเดอร์ปลายทางมีอยู่แล้ว กรุณาเปลี่ยนชื่อหรือเลือกตำแหน่งอื่น');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false || !is_writable($parent)) {
            throw new RuntimeException('โฟลเดอร์ปลายทางไม่สามารถเขียนได้');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('เปิดไฟล์ ZIP ไม่สำเร็จ หรือไฟล์เสียหาย');
        }
        $count = (int)$zip->numFiles;
        if ($count < 1 || $count > $maxEntries) {
            $zip->close();
            throw new RuntimeException('จำนวนรายการใน ZIP ไม่ถูกต้องหรือเกินกำหนด (' . $maxEntries . ' รายการ)');
        }
        $validated = [];
        $totalBytes = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name'])) {
                $zip->close();
                throw new RuntimeException('อ่านรายการภายใน ZIP ไม่สำเร็จ');
            }
            $raw = (string)$stat['name'];
            if ($raw === '' || strpos($raw, "\0") !== false || preg_match('/^[A-Za-z]:/', $raw) || substr($raw, 0, 1) === '/' || strpos($raw, '\\') !== false || strpos($raw, ':') !== false) {
                $zip->close();
                throw new RuntimeException('พบชื่อไฟล์ที่ไม่ปลอดภัยใน ZIP');
            }
            $isDir = substr($raw, -1) === '/';
            $parts = explode('/', rtrim($raw, '/'));
            foreach ($parts as $part) {
                if ($part === '' || $part === '.' || $part === '..' || preg_match('/[\x00-\x1f]/', $part)) {
                    $zip->close();
                    throw new RuntimeException('ZIP มีเส้นทางไฟล์ที่พยายามออกนอกโฟลเดอร์');
                }
            }
            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0; $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $unixMode = ($attr >> 16) & 0170000;
                    if ($unixMode === 0120000) {
                        $zip->close();
                        throw new RuntimeException('ไม่อนุญาตไฟล์ Symbolic Link ภายใน ZIP');
                    }
                }
            }
            $size = $isDir ? 0 : (int)($stat['size'] ?? 0);
            $compressed = max(0, (int)($stat['comp_size'] ?? 0));
            if ($size < 0 || $size > $maxFileBytes) {
                $zip->close();
                throw new RuntimeException('ไฟล์ภายใน ZIP มีขนาดเกินกำหนด');
            }
            $totalBytes += $size;
            if ($totalBytes > $maxTotalBytes) {
                $zip->close();
                throw new RuntimeException('ขนาดรวมเมื่อแตก ZIP เกินกำหนด');
            }
            if ($size > 1024 * 1024 && $compressed > 0 && ($size / $compressed) > $maxRatio) {
                $zip->close();
                throw new RuntimeException('พบอัตราการบีบอัด ZIP ที่ผิดปกติ');
            }
            $relative = implode('/', $parts);
            $validated[] = ['index'=>$i, 'relative'=>$relative, 'directory'=>$isDir, 'size'=>$size];
        }
        if (!@mkdir($destination, 0755, true)) {
            $zip->close();
            throw new RuntimeException('สร้างโฟลเดอร์สำหรับแตก ZIP ไม่สำเร็จ');
        }
        $destinationReal = realpath($destination);
        if ($destinationReal === false) {
            $zip->close();
            @rmdir($destination);
            throw new RuntimeException('ตรวจสอบโฟลเดอร์สำหรับแตก ZIP ไม่สำเร็จ');
        }
        $written = 0;
        try {
            foreach ($validated as $entry) {
                $target = $destinationReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['relative']);
                if ($entry['directory']) {
                    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                        throw new RuntimeException('สร้างโฟลเดอร์ย่อยใน ZIP ไม่สำเร็จ');
                    }
                    continue;
                }
                $targetParent = dirname($target);
                if (!is_dir($targetParent) && !@mkdir($targetParent, 0755, true) && !is_dir($targetParent)) {
                    throw new RuntimeException('สร้างโฟลเดอร์ปลายทางของไฟล์ไม่สำเร็จ');
                }
                $parentReal = realpath($targetParent);
                if ($parentReal === false || ($parentReal !== $destinationReal && strpos($parentReal, $destinationReal . DIRECTORY_SEPARATOR) !== 0)) {
                    throw new RuntimeException('เส้นทางปลายทางของ ZIP ไม่ปลอดภัย');
                }
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('ชื่อไฟล์ซ้ำกันภายใน ZIP');
                }
                $in = $zip->getStream((string)$zip->getNameIndex($entry['index']));
                if (!$in) throw new RuntimeException('อ่านข้อมูลไฟล์ใน ZIP ไม่สำเร็จ');
                $out = @fopen($target, 'xb');
                if (!$out) { fclose($in); throw new RuntimeException('สร้างไฟล์ปลายทางไม่สำเร็จ'); }
                $copied = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 1024 * 1024);
                    if ($chunk === false) { fclose($in); fclose($out); throw new RuntimeException('อ่านข้อมูล ZIP ไม่ครบ'); }
                    if ($chunk === '') continue;
                    $copied += strlen($chunk);
                    if ($copied > $entry['size'] || $copied > $maxFileBytes) { fclose($in); fclose($out); throw new RuntimeException('ขนาดไฟล์แตกออกมาไม่ตรงกับข้อมูล ZIP'); }
                    $offset = 0; $length = strlen($chunk);
                    while ($offset < $length) {
                        $n = fwrite($out, substr($chunk, $offset));
                        if ($n === false || $n === 0) { fclose($in); fclose($out); throw new RuntimeException('เขียนไฟล์ที่แตกจาก ZIP ไม่สำเร็จ'); }
                        $offset += $n;
                    }
                }
                fclose($in);
                fclose($out);
                if ($copied !== $entry['size']) throw new RuntimeException('ขนาดไฟล์หลังแตก ZIP ไม่ตรงกับข้อมูลต้นฉบับ');
                @chmod($target, 0644);
                $written++;
            }
            $zip->close();
        } catch (Throwable $error) {
            $zip->close();
            avsfSafeRemoveTree($destination);
            throw $error;
        }
        return ['files'=>$written, 'bytes'=>$totalBytes, 'entries'=>$count];
    }
}
if (!function_exists('avsfSafeRemoveTree')) {
    function avsfSafeRemoveTree($path) {
        if (!is_dir($path) || is_link($path)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) @unlink($item->getPathname());
            elseif ($item->isDir()) @rmdir($item->getPathname());
        }
        @rmdir($path);
    }
}
