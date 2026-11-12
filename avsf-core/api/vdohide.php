<?php
/**
 * api/vdohide.php — VDOHide resolve endpoint (AJAX/proxy).
 * ใช้: POST/GET api/vdohide.php?source=<url> → JSON {url, error}
 * Security: ต้องมี token ถึงจะ resolve ผ่าน remote API; ไม่มี token คืน error.
 *           ไม่เปิดเผย token ใน response. อนุญาตเฉพาะ source http(s).
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/api/vdohide-api.php';

$source = isset($_GET['source']) ? (string)$_GET['source'] : (isset($_POST['source']) ? (string)$_POST['source'] : '');
if ($source === '') {
    echo json_encode(['url' => '', 'error' => 'missing source'], JSON_UNESCAPED_UNICODE);
    exit;
}
$result = avsf_vdohide_resolve($source);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
