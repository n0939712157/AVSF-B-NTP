<?php
/**
 * taxonomy-templates/taxonomy-category.php — หน้า archive หมวดหมู่เดี่ยว (/category/{slug}).
 * ตั้ง $metaTitle, $metaDesc, $canonicalUrl, $pageSchema, $breadcrumbItems.
 */
global $conn, $canonicalBase, $siteDisplayName;
$extractCategories = function($value) { return splitTaxonomyNames($value); };
$loadCategoryIndex = function($sourcePosts = null) use ($extractCategories, $conn) {
    $counts = [];
    foreach (is_array($sourcePosts) ? $sourcePosts : publishedPosts() as $post) {
        foreach ($extractCategories($post['category'] ?? '') as $c) {
            if (!isset($counts[$c])) $counts[$c] = 0;
            $counts[$c]++;
        }
    }
    $index = [];
    $result = mysqli_query($conn, "SELECT * FROM categories ORDER BY name");
    while ($result && ($cat = mysqli_fetch_assoc($result))) {
        $index[$cat['slug']] = ['id'=>(int)$cat['id'], 'name'=>$cat['name'], 'count'=>(int)($counts[$cat['name']]??0), 'metaTitle'=>$cat['metaTitle'], 'metaDesc'=>$cat['metaDesc']];
    }
    return $index;
};
$categoryIndex = $loadCategoryIndex();
if (!isset($categoryIndex[$slug])) {
    http_response_code(404); $GLOBALS['metaTitle']=__('ไม่พบหมวดหมู่').' | '.setting('siteName',''); $GLOBALS['robotsContent']='noindex, follow'; $GLOBALS['canonicalUrl']='';
    echo '<div class="avsf-card avsf-archive-empty" style="text-align:center;padding:40px;margin-top:20px;"><h2 style="margin-bottom:10px;">'.__('ไม่พบหมวดหมู่นี้').'</h2><p style="color:var(--muted);margin-bottom:14px;">'.__('หมวดหมู่อาจถูกลบหรือยังไม่มีเรื่องที่เผยแพร่').'</p><a class="avsf-btn avsf-btn-ghost" href="'.esc(siteUrl('categories')).'">'.__('ดูหมวดหมู่ทั้งหมด').'</a></div>';
    return;
}
$category = $categoryIndex[$slug];
$pageSize = avsf_cards_per_page('posts');
$matchedPosts = [];
foreach (publishedPosts() as $post) {
    if (in_array($category['name'], $extractCategories($post['category'] ?? ''), true)) $matchedPosts[] = $post;
}
usort($matchedPosts, function($a,$b){ $d=strcmp((string)$b['date'],(string)$a['date']); return $d!==0?$d:((int)$b['id']-(int)$a['id']); });
$totalPosts = count($matchedPosts);
$totalPages = max(1, (int)ceil($totalPosts / $pageSize));
$currentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalPages);
$posts = array_slice($matchedPosts, ($currentPage - 1) * $pageSize, $pageSize);
$publicVars = ['cat'=>$category['name'], 'category'=>$category['name'], 'title'=>$category['name'], 'term_title'=>$category['name'], 'site_name'=>setting('siteName',''), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>number_format($totalPosts), 'item_count'=>number_format($totalPosts), 'page'=>$currentPage];
$visibleTitle = renderSeoSetting('seo_public_category_title', '%%cat%%', $publicVars);
$visibleDescription = renderSeoSetting('seo_public_category_description', __('เรื่องทั้งหมดในหมวด %%cat%% เรียงตามวันที่อัปเดตล่าสุด'), $publicVars);
$GLOBALS['metaTitle'] = taxonomySeoValue('category', 'title', $category['name'], setting('siteName',''), $category['metaTitle'], $totalPosts);
$GLOBALS['metaDesc'] = taxonomySeoValue('category', 'desc', $category['name'], setting('siteName',''), $category['metaDesc'], $totalPosts);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('category', $slug, $currentPage > 1 ? ['pg'=>$currentPage] : []);
$GLOBALS['pageSchema'] = ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$GLOBALS['metaTitle'],'description'=>$GLOBALS['metaDesc'],'url'=>$GLOBALS['canonicalUrl'],'isPartOf'=>['@type'=>'WebSite','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'],'inLanguage'=>'th'];
$GLOBALS['breadcrumbItems'][] = ['name'=>__('หมวดหมู่'), 'url'=>$canonicalBase.sitePath('categories')];
$GLOBALS['breadcrumbItems'][] = ['name'=>$category['name'], 'url'=>$canonicalBase.sitePath('category', $slug)];
?>
<nav class="avsf-archive-breadcrumb" aria-label="<?php echo esc(__('เส้นทางนำทาง')); ?>" style="padding:16px 0;font-size:12px;color:var(--muted);"><a href="<?php echo esc(siteUrl('home')); ?>" style="color:var(--accent);"><?php _e('หน้าหลัก'); ?></a> › <a href="<?php echo esc(siteUrl('categories')); ?>" style="color:var(--accent);"><?php _e('หมวดหมู่'); ?></a> › <span><?php echo esc($category['name']); ?></span></nav>
<section class="avsf-archive-hero" style="margin-bottom:18px;">
    <span class="avsf-archive-eyebrow" style="color:var(--accent);font-size:11px;font-weight:700;letter-spacing:.08em;"><?php _e('หมวดหมู่'); ?></span>
    <h1 style="font-size:clamp(20px,3vw,26px);font-weight:900;margin:4px 0;"><?php echo esc($visibleTitle); ?></h1>
    <?php if ($visibleDescription !== ''): ?><p style="color:var(--muted);font-size:13px;max-width:600px;"><?php echo nl2br(esc($visibleDescription)); ?></p><?php endif; ?>
    <div class="avsf-archive-count" style="margin-top:8px;font-size:13px;"><strong style="color:var(--accent);"><?php echo number_format($totalPosts); ?></strong> <?php _e('เรื่อง'); ?></div>
</section>
<div class="avsf-archive-heading" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h2 style="font-size:16px;font-weight:800;"><?php printf(__('เรื่องในหมวด %s'), esc($category['name'])); ?></h2><span style="color:var(--muted);font-size:12px;"><?php printf(__('หน้า %s / %s'), $currentPage, $totalPages); ?></span></div>
<?php if (count($posts)): ?>
    <div class="avsf-grid avsf-archive-post-grid" style="gap:12px;margin-bottom:24px;"><?php echo implode('', array_map(function($post, $index) {
            $eager = $index < 2;
            return postCardHtml($post, false, false, $eager ? 'eager' : 'lazy', $index === 0 ? 'high' : ($eager ? 'auto' : 'low'));
        }, $posts, array_keys($posts))); ?></div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'category', 'currentPage'=>$currentPage, 'totalPages'=>$totalPages, 'slug'=>$slug]); ?>
<?php else: ?>
    <div class="avsf-card avsf-archive-empty" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีเรื่องในหมวดนี้'); ?></div>
<?php endif; ?>
