<?php
/**
 * pages/page-categories.php — ดัชนีหมวดหมู่ทั้งหมด /categories.
 */
global $conn, $canonicalBase, $siteDisplayName;
$extractCategories = function($value) { return splitTaxonomyNames($value); };
$counts = [];
foreach (publishedPosts() as $post) {
    foreach (array_unique($extractCategories($post['category'] ?? '')) as $c) {
        if (!isset($counts[$c])) $counts[$c] = 0;
        $counts[$c]++;
    }
}
$categoryIndex = [];
$result = mysqli_query($conn, "SELECT * FROM categories ORDER BY name");
while ($result && ($cat = mysqli_fetch_assoc($result))) {
    $categoryIndex[$cat['slug']] = ['id'=>(int)$cat['id'], 'name'=>$cat['name'], 'count'=>(int)($counts[$cat['name']]??0), 'metaTitle'=>$cat['metaTitle'], 'metaDesc'=>$cat['metaDesc']];
}
uasort($categoryIndex, function($a, $b) {
    $byCount = (int)$b['count'] <=> (int)$a['count'];
    return $byCount !== 0 ? $byCount : strnatcasecmp((string)$a['name'], (string)$b['name']);
});
$categoryTotal = array_sum(array_column($categoryIndex, 'count'));
$totalCategoryItems = count($categoryIndex);
$categoryPageSize = avsf_cards_per_page('categories');
$totalCategoryPages = max(1, (int)ceil($totalCategoryItems / $categoryPageSize));
$currentCategoryPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalCategoryPages);
$categoryIndex = array_slice($categoryIndex, ($currentCategoryPage - 1) * $categoryPageSize, $categoryPageSize, true);
$publicVars = ['site_name'=>setting('siteName',''), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>number_format($categoryTotal), 'item_count'=>$totalCategoryItems, 'page'=>1];
$visibleTitle = renderSeoSetting('seo_public_categories_title', __('หมวดหมู่ทั้งหมด | %%site_name%%'), $publicVars);
$visibleDescription = renderSeoSetting('seo_public_categories_description', __('เลือกดูเรื่องที่เผยแพร่ตามหมวดหมู่และแท็ก รวม %%post_count%% หัวข้อ'), $publicVars);
$GLOBALS['metaTitle'] = renderSeoSetting('seo_meta_categories_title', $visibleTitle, $publicVars);
$GLOBALS['metaDesc'] = renderSeoSetting('seo_meta_categories_desc', $visibleDescription, $publicVars);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('categories', '', $currentCategoryPage > 1 ? ['pg'=>$currentCategoryPage] : []);
$GLOBALS['breadcrumbItems'][] = ['name' => $visibleTitle, 'url' => $GLOBALS['canonicalUrl']];
$GLOBALS['pageSchema'] = ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$GLOBALS['metaTitle'],'description'=>$GLOBALS['metaDesc'],'url'=>$GLOBALS['canonicalUrl'],'isPartOf'=>['@type'=>'WebSite','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'],'inLanguage'=>'th'];
?>
<nav class="avsf-archive-breadcrumb" aria-label="<?php echo esc(__('เส้นทางนำทาง')); ?>" style="padding:16px 0;font-size:12px;color:var(--muted);"><a href="<?php echo esc(siteUrl('home')); ?>" style="color:var(--accent);"><?php _e('หน้าหลัก'); ?></a> › <span><?php _e('หมวดหมู่'); ?></span></nav>
<section class="avsf-archive-hero" style="margin-bottom:18px;">
    <span class="avsf-archive-eyebrow" style="color:var(--accent);font-size:11px;font-weight:700;letter-spacing:.08em;"><?php _e('สำรวจตามหมวด'); ?></span>
    <h1 style="font-size:clamp(22px,3.5vw,30px);font-weight:900;margin:4px 0;"><?php echo esc($visibleTitle); ?></h1>
    <p style="color:var(--muted);font-size:13px;max-width:600px;"><?php echo esc($visibleDescription); ?></p>
    <div class="avsf-archive-count" style="margin-top:8px;font-size:13px;"><strong style="color:var(--accent);"><?php echo number_format($totalCategoryItems); ?></strong> <?php _e('หัวข้อ'); ?> · <strong style="color:var(--accent);"><?php echo number_format($categoryTotal); ?></strong> <?php _e('รายการ'); ?></div>
</section>
<?php if (count($categoryIndex)): ?>
    <div class="avsf-category-grid" style="display:grid;gap:10px;margin-bottom:24px;">
        <?php foreach ($categoryIndex as $categorySlug => $category):
            $categoryNameForTheme = strtolower((string)$category['name']);
            $categoryTheme = 'default';
            if (strpos($categoryNameForTheme, 'ซับไทย') !== false) $categoryTheme = 'subthai';
            elseif (preg_match('/\\bhd\\b/i', $categoryNameForTheme)) $categoryTheme = 'hd';
            elseif (strpos($categoryNameForTheme, 'ใหม่') !== false) $categoryTheme = 'new';
            elseif (strpos($categoryNameForTheme, 'ยอดนิยม') !== false || strpos($categoryNameForTheme, 'popular') !== false) $categoryTheme = 'popular';
        ?>
            <a class="avsf-category-card avsf-category-card--directory avsf-category-card-theme--<?php echo esc($categoryTheme); ?>" href="<?php echo esc(siteUrl('category', $categorySlug)); ?>" style="display:flex;align-items:center;gap:10px;padding:14px 16px;background:var(--card);border:1px solid var(--border);border-radius:12px;transition:all .2s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
                <span class="avsf-category-card-icon" aria-hidden="true">✦</span>
                <span class="avsf-category-card-name"><?php echo esc($category['name']); ?></span>
                <span class="avsf-category-card-count"><?php echo number_format($category['count']); ?> <?php _e('เรื่อง'); ?></span>
                <span class="avsf-category-card-arrow" aria-hidden="true">→</span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'categories', 'currentPage'=>$currentCategoryPage, 'totalPages'=>$totalCategoryPages]); ?>
<?php else: ?>
    <div class="avsf-card avsf-archive-empty" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีหมวดหมู่ — เพิ่มหมวดหมู่ในข้อมูลโพสต์ที่เผยแพร่แล้ว'); ?></div>
<?php endif; ?>
