<?php
/**
 * pages/page-home.php — หน้าหลัก: banner hero slider + แนะนำ carousel + โพสต์ล่าสุด.
 * ใช้ $activeBanners, $siteMenus จาก bootstrap (global).
 */
global $activeBanners, $canonicalBase, $siteDisplayName;
$posts = publishedPosts();
$homePageSize = avsf_cards_per_page('posts');
$homeTotalPages = max(1, (int)ceil(count($posts) / $homePageSize));
$homeCurrentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $homeTotalPages);
$latestPosts = array_slice($posts, ($homeCurrentPage - 1) * $homePageSize, $homePageSize);
if ($homeCurrentPage > 1) $GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('home', '', ['pg'=>$homeCurrentPage]);
// Banner hero slider
$bannerHtml = '';
if (themeOn('showBanners') && $activeBanners) {
    $bannerLabel = trim(setting('site_banner_accessibility', __('บทความรหัสหนังใหม่'))) ?: __('บทความรหัสหนังใหม่');
    $bannerHtml = '<div id="bannerBox" class="avsf-banner-box"><section class="avsf-hero-panel avsf-hero-featured" id="bannerSlide" data-hero-slider data-count="'.count($activeBanners).'" aria-label="'.esc($bannerLabel).'" aria-live="polite"><div class="avsf-hero-arrows"><button type="button" data-hero-prev aria-label="'.esc(__('บทความก่อนหน้า')).'">←</button><button type="button" data-hero-next aria-label="'.esc(__('บทความถัดไป')).'">→</button></div></section><div class="avsf-banner-dots" id="bannerDots">'.implode('', array_map(function($i){return '<button type="button" aria-label="'.esc(__('แสดงบทความที่ %s', [$i+1])).'" '.($i===0?'class="avsf-active"':'').' aria-pressed="'.($i===0?'true':'false').'" data-banner-index="'.$i.'"></button>';}, array_keys($activeBanners))).'</div></div>';
}
// Featured carousel (แนะนำ)
$featuredCardsHtml = '';
$featuredLimit = (int)setting('site_home_featured_limit', '12');
if (!in_array($featuredLimit, [4,6,8,10,12,16,20,24], true)) $featuredLimit = 12;
foreach (array_slice($posts, 0, $featuredLimit) as $featuredIndex => $featuredPost) {
    $featuredVideo = parseVideo($featuredPost['videoUrl']);
    $featuredImage = (function_exists('avsfFirstPostImage') ? avsfFirstPostImage($featuredPost, true) : (trim((string)($featuredPost['posterUrl'] ?? '')) ?: trim((string)$featuredPost['cover']))) ?: $featuredVideo['thumb'];
    $featuredUrl = siteUrl('post', $featuredPost['slug'], [], $featuredPost);
    $featuredHasImage = trim((string)$featuredImage) !== '';
    $featuredGalleryImages = [];
    if ($featuredHasImage) $featuredGalleryImages[] = $featuredImage;
    if (function_exists('normalizeGalleryImages')) {
        foreach (normalizeGalleryImages(isset($featuredPost['gallery']) ? $featuredPost['gallery'] : '') as $galleryImageUrl) {
            if ($galleryImageUrl !== '' && !in_array($galleryImageUrl, $featuredGalleryImages, true)) {
                $featuredGalleryImages[] = $galleryImageUrl;
            }
        }
    }
    $featuredGalleryAttr = count($featuredGalleryImages) > 1
        ? ' data-gallery-preview="'.esc(json_encode($featuredGalleryImages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG)).'" data-gallery-preview-delay="750"'
        : '';
    $featuredCardsHtml .= '<a class="avsf-home-featured-card '.($featuredHasImage?'avsf-home-featured-card--has-image':'avsf-home-featured-card--no-image').'" href="'.esc($featuredUrl).'"'.$featuredGalleryAttr.'><span class="avsf-home-featured-placeholder" aria-hidden="true"></span>';
    if ($featuredImage !== '') {
        $featuredCardsHtml .= '<img src="'.esc($featuredImage).'" alt="'.esc($featuredPost['title']).'" loading="'.($featuredIndex===0?'eager':'lazy').'" decoding="async" fetchpriority="'.($featuredIndex===0?'high':'low').'" width="960" height="640" onerror="this.remove();var c=this.closest(\'.avsf-home-featured-card\');if(c){c.classList.remove(\'avsf-home-featured-card--has-image\');c.classList.add(\'avsf-home-featured-card--no-image\');var p=c.querySelector(\'.avsf-home-featured-placeholder\');if(p)p.removeAttribute(\'style\');}">';
    }
    $featuredCardsHtml .= '<span class="avsf-home-featured-card-title">'.esc($featuredPost['title']).'</span></a>';
}
$featuredTitle = trim(setting('site_home_featured_title', __('แนะนำ'))) ?: __('แนะนำ');
$featuredAllLabel = trim(setting('site_home_featured_all_label', __('ดูทั้งหมด'))) ?: __('ดูทั้งหมด');
$featuredHtml = $posts ? '<section class="avsf-home-recommendations" aria-labelledby="home-recommendations-title"><div class="avsf-home-recommendations-heading"><h2 id="home-recommendations-title">'.esc($featuredTitle).'</h2><div class="avsf-home-recommendations-actions"><a href="'.esc(siteUrl('all')).'" class="avsf-home-recommendations-all">'.esc($featuredAllLabel).' <span aria-hidden="true">→</span></a><button type="button" class="avsf-home-carousel-arrow" data-home-carousel-step="-1" aria-label="'.esc(__('เลื่อนการ์ดไปก่อนหน้า')).'">‹</button><button type="button" class="avsf-home-carousel-arrow" data-home-carousel-step="1" aria-label="'.esc(__('เลื่อนการ์ดไปถัดไป')).'">›</button></div></div><p class="avsf-home-carousel-status" aria-live="polite" aria-atomic="true"></p><div class="avsf-home-carousel-viewport" data-home-carousel tabindex="0" aria-label="'.esc($featuredTitle).'"><div class="avsf-home-featured-track">'.$featuredCardsHtml.'</div></div></section>' : '';
$latestTitle = trim(setting('site_home_latest_title', __('โพสต์ล่าสุด'))) ?: __('โพสต์ล่าสุด');
$homeVariables = ['site_name'=>avsfSeoSiteName($siteDisplayName), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>count($posts), 'item_count'=>count($posts)];
$homeTitle = renderSeoTemplate(setting('seo_public_home_title', ''), $homeVariables);
$homeDescription = renderSeoTemplate(setting('seo_public_home_description', ''), $homeVariables);
$homeIntro = ($homeTitle !== '' || $homeDescription !== '') ? '<section class="avsf-archive-hero avsf-public-seo-intro">'.($homeTitle!==''?'<h1>'.esc($homeTitle).'</h1>':'').($homeDescription!==''?'<p>'.nl2br(esc($homeDescription)).'</p>':'').'</section>' : '';
$homePosition = setting('seo_public_home_position', 'top');
// meta defaults (ถ้าไม่ได้ตั้งจาก bootstrap)
if (empty($GLOBALS['metaTitle'])) { $GLOBALS['metaTitle'] = renderSeoTemplate(setting('seo_meta_home_title',''), $homeVariables) ?: (avsfSeoSiteName($siteDisplayName).(setting('siteDesc','')!==''?' - '.setting('siteDesc',''):'')); }
if (empty($GLOBALS['metaDesc'])) { $GLOBALS['metaDesc'] = renderSeoTemplate(setting('seo_meta_home_desc',''), $homeVariables) ?: setting('siteDesc',''); }
echo ($homePosition === 'bottom' ? '' : $homeIntro) . $bannerHtml . $featuredHtml;
?>
<div class="avsf-archive-heading" style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;"><h2 style="font-size:18px;font-weight:800;"><?php echo esc($latestTitle); ?></h2><span style="font-size:12px;color:var(--muted);"><?php printf(__('หน้า %s / %s'), $homeCurrentPage, $homeTotalPages); ?></span></div>
<?php if (count($latestPosts)): ?>
    <div class="avsf-grid avsf-home-post-grid" style="margin-bottom:32px;">
        <?php echo implode('', array_map(function($post){ return postCardHtml($post, true); }, $latestPosts)); ?>
    </div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'home', 'currentPage'=>$homeCurrentPage, 'totalPages'=>$homeTotalPages]); ?>
<?php else: ?>
    <div class="avsf-card" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีโพสต์ —'); ?> <a href="admin-login/" style="color:var(--accent);"><?php _e('ไปเขียนโพสต์แรกในหลังบ้าน'); ?></a></div>
<?php endif; ?>
<?php if ($homePosition === 'bottom') echo $homeIntro; ?>
