<?php
/**
 * AVSF one-time storage migration helper.
 * Copies legacy root storage folders into avsf-storage without overwriting files.
 * Run while signed in as the AVSF Owner; this script never deletes legacy originals.
 */
require_once dirname(__DIR__) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
requireSuperAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') sec_csrf_verify();
$map = [
    'uploads' => AVSF_UPLOAD_DIR,
    'cache' => AVSF_CACHE_DIR,
    'logs' => AVSF_LOG_DIR,
    'backups' => AVSF_BACKUP_DIR,
    'quarantine' => AVSF_QUARANTINE_DIR,
];
$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    foreach ($map as $legacyName => $destination) {
        $source = AVSF_ROOT . DIRECTORY_SEPARATOR . $legacyName;
        $stats = ['copied'=>0, 'skipped'=>0, 'errors'=>0];
        $sourceReal = realpath($source);
        if ($sourceReal === false || !is_dir($sourceReal) || $sourceReal === realpath($destination)) {
            $results[$legacyName] = $stats;
            continue;
        }
        if (!is_dir($destination) && !@mkdir($destination, 0755, true)) {
            $stats['errors']++;
            $results[$legacyName] = $stats;
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink()) { $stats['skipped']++; continue; }
            $relative = substr(str_replace('\\', '/', $path), strlen(rtrim(str_replace('\\', '/', $sourceReal), '/')) + 1);
            if ($relative === '' || strpos($relative, '../') !== false || $relative === '..') { $stats['skipped']++; continue; }
            $target = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true)) $stats['errors']++;
                continue;
            }
            if (!is_file($path) || file_exists($target)) { $stats['skipped']++; continue; }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) { $stats['errors']++; continue; }
            if (@copy($path, $target)) { @chmod($target, 0644); $stats['copied']++; }
            else $stats['errors']++;
        }
        $results[$legacyName] = $stats;
    }
}
?>
<!doctype html><html lang="<?php echo avsf_lang_attr(); ?>"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AVSF Storage Migration</title>
<style>body{font:15px/1.7 system-ui,sans-serif;max-width:880px;margin:32px auto;padding:0 16px;color:#172033}section{border:1px solid #dbe3ed;border-radius:12px;padding:18px;margin:12px 0}button{padding:11px 18px;border:0;border-radius:8px;background:#1769d2;color:white;font-weight:700;cursor:pointer}.avsf-warn{color:#9a3412}</style>
<h1><?php _e('AVSF — ย้ายข้อมูลไป AVSF Storage'); ?></h1><section><p><?php _e('เครื่องมือนี้คัดลอกข้อมูลจากโฟลเดอร์เดิมที่ Root ไปยัง'); ?> <code>avsf-storage/</code> <?php _e('เท่านั้น ไม่เขียนทับไฟล์ที่มีอยู่ และไม่ลบไฟล์ต้นฉบับ'); ?></p><p class="avsf-warn"><?php _e('ควรสำรองข้อมูลและตรวจพื้นที่ดิสก์ก่อนเริ่ม โดยเฉพาะ uploads และ backups'); ?></p><ul><?php foreach($map as $name=>$dest): ?><li><code><?= e($name) ?>/</code> → <code><?= e(str_replace(AVSF_ROOT . DIRECTORY_SEPARATOR, '', $dest)) ?>/</code></li><?php endforeach; ?></ul><form method="post"><?= sec_csrf_field() ?><button name="run_migration" value="1" type="submit" onclick='return confirm(<?php echo avsf_js(__('ยืนยันคัดลอกข้อมูลเดิม? ไฟล์เดิมจะไม่ถูกลบ')); ?>)'><?php _e('เริ่มคัดลอกข้อมูล'); ?></button></form></section>
<?php if($results): ?><section><h2><?php _e('ผลการคัดลอก'); ?></h2><table><tr><th><?php _e('โฟลเดอร์'); ?></th><th><?php _e('คัดลอก'); ?></th><th><?php _e('ข้าม'); ?></th><th><?php _e('ผิดพลาด'); ?></th></tr><?php foreach($results as $name=>$stat): ?><tr><td><?= e($name) ?></td><td><?= (int)$stat['copied'] ?></td><td><?= (int)$stat['skipped'] ?></td><td><?= (int)$stat['errors'] ?></td></tr><?php endforeach; ?></table><p><?php _e('เมื่อยืนยันข้อมูลครบแล้ว คุณสามารถเก็บโฟลเดอร์เดิมไว้อีกระยะหนึ่งก่อนลบด้วยตนเอง'); ?></p></section><?php endif; ?></html>
