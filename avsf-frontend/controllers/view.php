<?php
/**
 * view.php — View counter beacon
 * เรียกผ่าน JS ที่หน้าโพสต์ เพื่อนับ views แม่นยำแม้แคชเปิด
 * วิธีเรียก: <script>fetch('view.php?id=123');</script>
 */
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($postId > 0) {
    require_once dirname(__DIR__, 2) . '/config.php';
    global $conn;
    if ($conn) {
        // ป้องกันนับซ้ำจาก IP เดียวกันใน 1 ชั่วโมง (ใช้ cookie)
        $cookieName = 'viewed_' . $postId;
        if (empty($_COOKIE[$cookieName])) {
            $stmt = $conn->prepare("UPDATE posts SET views = views + 1 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $postId);
                $stmt->execute();
                $stmt->close();
            }
            setcookie($cookieName, '1', time() + 3600, '/');
        }
    }
}
// ส่งกลับ pixel 1x1 gif
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
