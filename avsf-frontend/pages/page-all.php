<?php
/**
 * pages/page-all.php — รายการทุกเรื่อง (archive ทั้งหมด) /all.
 */
global $conn, $canonicalBase, $siteDisplayName;
$pageSize = avsf_cards_per_page('posts');
$totalResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM posts WHERE status='publish'");
$totalPosts = (int)mysqli_fetch_assoc($totalResult)['total'];
$totalPages = max(1, (int)ceil($totalPosts / $pageSize));
$currentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalPages);
$offset = ($currentPage - 1) * $pageSize;
$posts = [];
$result = mysqli_query($conn, "SELECT * FROM posts WHERE status='publish' ORDER BY date DESC,id DESC LIMIT $offset,$pageSize");
while ($row = mysqli_fetch_assoc($result)) $posts[] = $row;
$publicVars = ['site_name'=>setting('siteName',''), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>number_format($totalPosts), 'item_count'=>number_format($totalPosts), 'page'=>$currentPage];
$visibleTitle = renderSeoSetting('seo_public_all_title', __('เรื่องทั้งหมด | %%site_name%%'), $publicVars);
$visibleDescription = renderSeoSetting('seo_public_all_description', __('รวมเรื่องที่เผยแพร่ทั้งหมด %%post_count%% เรื่อง เลือกอ่านตามวันที่อัปเดตล่าสุด'), $publicVars);
$GLOBALS['metaTitle'] = renderSeoSetting('seo_meta_all_title', $visibleTitle, $publicVars);
$GLOBALS['metaDesc'] = renderSeoSetting('seo_meta_all_desc', $visibleDescription, $publicVars);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('all', '', $currentPage > 1 ? ['pg'=>$currentPage] : []);
$GLOBALS['breadcrumbItems'][] = ['name' => $visibleTitle, 'url' => $canonicalBase . sitePath('all')];
$GLOBALS['pageSchema'] = ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$GLOBALS['metaTitle'],'description'=>$GLOBALS['metaDesc'],'url'=>$GLOBALS['canonicalUrl'],'isPartOf'=>['@type'=>'WebSite','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'],'inLanguage'=>'th'];
?>
<section class="avsf-archive-hero" style="margin-bottom:18px;">
    <span class="avsf-archive-eyebrow" style="color:var(--accent);font-size:11px;font-weight:700;letter-spacing:.08em;"><?php _e('คลังเรื่อง'); ?></span>
    <h1 style="font-size:clamp(22px,3.5vw,30px);font-weight:900;margin:4px 0;"><?php echo esc($visibleTitle); ?></h1>
    <p style="color:var(--muted);font-size:13px;max-width:600px;"><?php echo esc($visibleDescription); ?></p>
    <div class="avsf-archive-count" style="margin-top:8px;font-size:13px;"><strong style="color:var(--accent);"><?php echo number_format($totalPosts); ?></strong> <?php _e('เรื่อง'); ?></div>
</section>
<div class="avsf-archive-heading" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h2 style="font-size:16px;font-weight:800;"><?php _e('รายการล่าสุด'); ?></h2><span style="color:var(--muted);font-size:12px;"><?php printf(__('หน้า %s / %s'), $currentPage, $totalPages); ?></span></div>
<?php if (count($posts)): ?>
    <div class="avsf-grid avsf-archive-post-grid" style="gap:12px;margin-bottom:24px;"><?php echo implode('', array_map(function($post, $index) {
            $eager = $index < 2;
            return postCardHtml($post, false, false, $eager ? 'eager' : 'lazy', $index === 0 ? 'high' : ($eager ? 'auto' : 'low'));
        }, $posts, array_keys($posts))); ?></div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'all', 'currentPage'=>$currentPage, 'totalPages'=>$totalPages]); ?>
<?php else: ?>
    <div class="avsf-card avsf-archive-empty" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีเรื่องที่เผยแพร่'); ?></div>
<?php endif; ?>
