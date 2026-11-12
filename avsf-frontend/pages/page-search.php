<?php
/**
 * pages/page-search.php — ผลการค้นหา /?s=...
 */
global $conn;
$q = isset($q) ? $q : (isset($_GET['s']) ? (string)$_GET['s'] : '');
$qE = mysqli_real_escape_string($conn, $q);
$pageSize = avsf_cards_per_page('posts');
$totalResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM posts WHERE status='publish' AND (title LIKE '%$qE%' OR content LIKE '%$qE%' OR code LIKE '%$qE%')");
$totalPosts = $totalResult ? (int)(mysqli_fetch_assoc($totalResult)['total'] ?? 0) : 0;
$totalPages = max(1, (int)ceil($totalPosts / $pageSize));
$currentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalPages);
$offset = ($currentPage - 1) * $pageSize;
$posts = [];
$res = mysqli_query($conn, "SELECT * FROM posts WHERE status='publish' AND (title LIKE '%$qE%' OR content LIKE '%$qE%' OR code LIKE '%$qE%') ORDER BY date DESC,id DESC LIMIT $offset,$pageSize");
while ($r = mysqli_fetch_assoc($res)) $posts[] = $r;
$GLOBALS['metaTitle'] = __('ค้นหา: %s', [$q]);
$GLOBALS['robotsContent'] = 'noindex, follow';
$GLOBALS['canonicalUrl'] = '';
?>
<h1 style="font-size:22px;font-weight:900;margin:16px 0;"><?php printf(__('ผลการค้นหา: "%s"'), esc($q)); ?></h1>
<p style="color:var(--muted);font-size:13px;margin-bottom:20px;"><?php printf(__('พบ %s รายการ · หน้า %s / %s'), number_format($totalPosts), $currentPage, $totalPages); ?></p>
<?php if (count($posts)): ?>
    <div class="avsf-grid avsf-search-grid" style="margin-bottom:24px;"><?php echo implode('', array_map(function($post, $index) {
            $eager = $index < 2;
            return postCardHtml($post, false, false, $eager ? 'eager' : 'lazy', $index === 0 ? 'high' : ($eager ? 'auto' : 'low'));
        }, $posts, array_keys($posts))); ?></div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'home', 'currentPage'=>$currentPage, 'totalPages'=>$totalPages, 'extra'=>['s'=>$q]]); ?>
<?php else: ?>
    <div class="avsf-card" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ไม่พบผลการค้นหา — ลองค้นหาด้วยคำอื่น'); ?></div>
<?php endif; ?>
