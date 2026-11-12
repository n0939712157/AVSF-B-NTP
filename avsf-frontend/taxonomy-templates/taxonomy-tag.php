<?php
/**
 * taxonomy-templates/taxonomy-tag.php — หน้า archive แท็กเดี่ยว (/tag/{slug}).
 * ตั้ง $metaTitle, $metaDesc, $canonicalUrl, $pageSchema, $breadcrumbItems.
 */
global $conn, $canonicalBase, $siteDisplayName;
$slugE = mysqli_real_escape_string($conn, $slug);
$item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tags WHERE slug='$slugE'"));
if (!$item) {
    http_response_code(404); $GLOBALS['metaTitle']=__('ไม่พบแท็ก').' | '.setting('siteName',''); $GLOBALS['robotsContent']='noindex, follow';
    echo '<div class="avsf-card" style="text-align:center;padding:40px;margin-top:20px;">'.__('ไม่พบแท็กนี้').' <a href="'.esc(siteUrl('tag_list')).'" style="color:var(--accent);">'.__('ดูแท็กทั้งหมด').'</a></div>'; return;
}
$pageSize = avsf_cards_per_page('posts');
$allPosts = [];
foreach (publishedPosts() as $pp) {
    if (in_array((int)$item['id'], json_decode($pp['tagIds'] ?? '[]', true) ?: [])) $allPosts[] = $pp;
}
usort($allPosts, function($a,$b){ $d=strcmp((string)$b['date'],(string)$a['date']); return $d!==0?$d:((int)$b['id']-(int)$a['id']); });
$totalPosts = count($allPosts);
$totalPages = max(1, (int)ceil($totalPosts / $pageSize));
$currentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalPages);
$posts = array_slice($allPosts, ($currentPage - 1) * $pageSize, $pageSize);
$tagDisplayName = '#' . $item['name'];
$publicVars = ['tag'=>$item['name'], 'site_name'=>setting('siteName',''), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>number_format($totalPosts)];
$visibleTitle = renderSeoSetting('seo_public_tag_title', '#%%tag%%', $publicVars);
$visibleDescription = renderSeoSetting('seo_public_tag_description', __('เรื่องที่มีแท็ก %%tag%% ทั้งหมด %%post_count%% เรื่อง'), $publicVars);
$GLOBALS['metaTitle'] = taxonomySeoValue('tag', 'title', $item['name'], setting('siteName',''), $item['metaTitle'] ?? '', $totalPosts);
$GLOBALS['metaDesc'] = taxonomySeoValue('tag', 'desc', $item['name'], setting('siteName',''), $item['metaDesc'] ?? '', $totalPosts);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('tag', $item['slug'], $currentPage > 1 ? ['pg'=>$currentPage] : []);
$GLOBALS['pageSchema'] = ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$GLOBALS['metaTitle'],'description'=>$GLOBALS['metaDesc'],'url'=>$GLOBALS['canonicalUrl'],'isPartOf'=>['@type'=>'WebSite','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'],'inLanguage'=>'th'];
$GLOBALS['breadcrumbItems'][] = ['name'=>__('แท็กทั้งหมด'), 'url'=>siteUrl('tag_list')];
$GLOBALS['breadcrumbItems'][] = ['name'=>$tagDisplayName, 'url'=>$GLOBALS['canonicalUrl']];
?>
<nav class="avsf-archive-breadcrumb" aria-label="<?php echo esc(__('เส้นทางนำทาง')); ?>" style="padding:16px 0;font-size:12px;color:var(--muted);"><a href="<?php echo esc(siteUrl('home')); ?>" style="color:var(--accent);"><?php _e('หน้าหลัก'); ?></a> › <a href="<?php echo esc(siteUrl('tag_list')); ?>" style="color:var(--accent);"><?php _e('แท็กทั้งหมด'); ?></a> › <span><?php echo esc($tagDisplayName); ?></span></nav>
<section class="avsf-archive-hero avsf-taxonomy-hero avsf-taxonomy-tag-hero" style="margin-bottom:18px;">
    <span class="avsf-archive-eyebrow" style="color:var(--accent);font-size:11px;font-weight:700;letter-spacing:.08em;"><?php _e('แท็ก'); ?></span>
    <h1 style="font-size:clamp(20px,3vw,26px);font-weight:900;margin:4px 0;"><span class="avsf-badge avsf-badge-AVSF" style="font-size:16px;padding:6px 14px;"><?php echo esc($tagDisplayName); ?></span></h1>
    <?php if ($visibleDescription !== ''): ?><p style="color:var(--muted);font-size:13px;max-width:600px;"><?php echo nl2br(esc($visibleDescription)); ?></p><?php endif; ?>
    <div class="avsf-archive-count" style="margin-top:8px;font-size:13px;"><strong style="color:var(--accent);"><?php echo number_format($totalPosts); ?></strong> <?php _e('เรื่อง'); ?></div>
</section>
<div class="avsf-archive-heading" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h2 style="font-size:16px;font-weight:800;"><?php printf(__('เรื่องที่มีแท็ก %s'), esc($tagDisplayName)); ?></h2><span style="color:var(--muted);font-size:12px;"><?php printf(__('หน้า %s / %s'), $currentPage, $totalPages); ?></span></div>
<?php if (count($posts)): ?>
    <div class="avsf-grid avsf-archive-post-grid" style="gap:12px;margin-bottom:24px;"><?php echo implode('', array_map(function($post, $index) {
            $eager = $index < 2;
            return postCardHtml($post, false, false, $eager ? 'eager' : 'lazy', $index === 0 ? 'high' : ($eager ? 'auto' : 'low'));
        }, $posts, array_keys($posts))); ?></div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'tag', 'currentPage'=>$currentPage, 'totalPages'=>$totalPages, 'slug'=>$item['slug']]); ?>
<?php else: ?>
    <div class="avsf-card avsf-archive-empty" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีเรื่องที่มีแท็กนี้'); ?></div>
<?php endif; ?>
