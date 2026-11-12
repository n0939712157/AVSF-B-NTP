<?php
/**
 * loop-templates/single-video.php
 * หน้าวิดีโอเดี่ยว (single post) — title/meta/player/poster/taxonomy + gallery/related/comments.
 * โค้ดเขียนเอง ไม่คัดลอกธีม. แต่ละ section ย่อย include แยกไฟล์ใน loop-templates/ —
 * ถ้าส่วนใด error จะได้แค่ส่วนนั้น ไม่กระทบส่วนอื่นของหน้า.
 *
 * ตั้งตัวแปร global สำหรับ head.php: $metaTitle, $metaDesc, $metaKeywords,
 * $canonicalUrl, $videoSchema, $breadcrumbItems, $robotsContent.
 */
global $conn, $canonicalBase, $siteDisplayName, $requestScheme, $requestHost;
$slugE = mysqli_real_escape_string($conn, $slug);
$p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM posts WHERE slug='$slugE' AND status='publish'"));
if (!$p) {
    http_response_code(404);
    $GLOBALS['metaTitle'] = __('ไม่พบโพสต์') . ' | ' . setting('siteName', '');
    $GLOBALS['robotsContent'] = 'noindex, follow';
    echo '<div class="avsf-card" style="text-align:center;padding:40px;margin-top:20px;">'.__('ไม่พบโพสต์นี้').' <a href="' . esc(siteUrl('home')) . '" style="color:var(--accent);">'.__('กลับหน้าหลัก').'</a></div>';
    return;
}
// ===== Data prep =====
$categoryPosts = publishedPosts();
$loadCategoryIndex = function($sourcePosts = null) use ($conn) {
    $extractCategories = function($value) { return splitTaxonomyNames($value); };
    $counts = [];
    foreach (is_array($sourcePosts) ? $sourcePosts : publishedPosts() as $post) {
        foreach ($extractCategories(isset($post['category']) ? $post['category'] : '') as $c) {
            if (!isset($counts[$c])) $counts[$c] = 0;
            $counts[$c]++;
        }
    }
    $index = [];
    $result = mysqli_query($conn, "SELECT * FROM categories ORDER BY name");
    while ($result && ($category = mysqli_fetch_assoc($result))) {
        $index[$category['slug']] = ['id'=>(int)$category['id'], 'name'=>$category['name'], 'count'=>(int)($counts[$category['name']]??0), 'metaTitle'=>$category['metaTitle'], 'metaDesc'=>$category['metaDesc']];
    }
    return $index;
};
$categoryIndex = $loadCategoryIndex($categoryPosts);
@mysqli_query($conn, "UPDATE posts SET views=views+1 WHERE id=" . (int)$p['id']);
$am = autoMeta($p);
$metaTitle = trim($am['title']);
$seoSiteName=avsfSeoSiteName($siteDisplayName);
if ($seoSiteName !== '' && stripos($metaTitle, $seoSiteName) === false) $metaTitle .= ' | ' . $seoSiteName;
$metaDesc = $am['desc'];
$metaKeywords = implode(', ', array_filter([$p['focusKeyphrase'], $p['keywords'], setting('seo_primary_keyword',''), setting('seo_secondary_keywords',''), setting('seo_keywords','')]));
$playerInput = !empty($p['embedUrl']) ? $p['embedUrl'] : $p['videoUrl'];
$v = parseVideo($playerInput);
$sourceVideo = parseVideo($p['videoUrl']);
$canonicalUrl = $canonicalBase . sitePath('post', $p['slug'], [], $p);
$breadcrumbItems[] = ['name' => $p['title'], 'url' => $canonicalUrl];
$thumbnail = (function_exists('avsfFirstPostImage') ? avsfFirstPostImage($p, true) : ((isset($p['posterUrl']) ? $p['posterUrl'] : '') ?: ($p['cover'] ?: ''))) ?: $sourceVideo['thumb'];
// JSON-LD VideoObject เพียงชุดเดียวสำหรับ Google; ไม่มี Microdata ซ้ำในเนื้อหา HTML.
// หากข้อมูลที่ Google กำหนดเป็น Required ยังไม่ครบ จะไม่ส่ง VideoObject ที่ไม่สมบูรณ์ออกไป.
$videoSchema = null;
if ($p['id'] > 0) {
    $schemaThumbnail = '';
    $thumbCandidate = trim((string)$thumbnail);
    if ($thumbCandidate !== '') {
        if (preg_match('/^https?:\/\//i', $thumbCandidate)) $schemaThumbnail = $thumbCandidate;
        elseif (strpos($thumbCandidate, '//') === 0) $schemaThumbnail = $requestScheme.':'.$thumbCandidate;
        elseif ($thumbCandidate[0] === '/') $schemaThumbnail = $canonicalBase.$thumbCandidate;
        else $schemaThumbnail = $canonicalBase.'/'.ltrim($thumbCandidate,'/');
    }
    try { $dateTimezone = new DateTimeZone(setting('siteTimezone','Asia/Bangkok')); }
    catch (Throwable $e) { $dateTimezone = new DateTimeZone('Asia/Bangkok'); }
    $publishedAt = null;
    $uploadAt = null;
    $modifiedAt = null;
    try {
        if (!empty($p['date'])) $publishedAt = new DateTimeImmutable((string)$p['date'].' 00:00:00', $dateTimezone);
        if (!empty($p['createdAt'])) $uploadAt = new DateTimeImmutable((string)$p['createdAt'], $dateTimezone);
        if (!empty($p['updatedAt'])) $modifiedAt = new DateTimeImmutable((string)$p['updatedAt'], $dateTimezone);
    } catch (Throwable $e) {
        error_log('AVSF VideoObject date error: '.$e->getMessage());
    }
    if (!$publishedAt && $uploadAt) $publishedAt = $uploadAt;
    if (!$uploadAt && $publishedAt) $uploadAt = $publishedAt;
    if (!$modifiedAt && $uploadAt) $modifiedAt = $uploadAt;
    if ($modifiedAt && $publishedAt && $modifiedAt < $publishedAt) $modifiedAt = $publishedAt;
    $schemaName = trim(stripHtml((string)$p['title']));
    $schemaDescription = trim(stripHtml((string)($am['desc'] ?: $p['content'])));
    if ($schemaDescription === '') $schemaDescription = $schemaName;
    // Required by Google Video structured data: name, thumbnailUrl and uploadDate.
    if ($schemaName !== '' && $schemaThumbnail !== '' && validImageReference($schemaThumbnail) && $uploadAt) {
        $videoSchema = [
            '@context'=>'https://schema.org',
            '@type'=>'VideoObject',
            'name'=>$schemaName,
            'description'=>$schemaDescription,
            'thumbnailUrl'=>$schemaThumbnail,
            'uploadDate'=>$uploadAt->format(DateTime::ATOM),
            'url'=>$canonicalUrl,
            'inLanguage'=>'th'
        ];
        if ($publishedAt) $videoSchema['datePublished'] = $publishedAt->format(DateTime::ATOM);
        if ($modifiedAt) $videoSchema['dateModified'] = $modifiedAt->format(DateTime::ATOM);
        $durationIso = isoVideoDuration($p['videoDuration']);
        if ($durationIso !== '') $videoSchema['duration'] = $durationIso;
        if ($v['embedUrl'] !== '') $videoSchema['embedUrl'] = $v['embedUrl'];
        if ($v['contentUrl'] !== '') $videoSchema['contentUrl'] = $v['contentUrl'];
        $videoSchema['publisher'] = ['@type'=>'Organization','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'];
    }
}
// Taxonomy links
$studioIds = parseTaxonomyIds($p['studioId']);
$studios = array_map('getStudio', $studioIds);
$studio = $studios ? $studios[0] : getStudio(0);
$actorIds = parseTaxonomyIds($p['actorIds']);
$tagIds = parseTaxonomyIds($p['tagIds']);
$gallery = normalizeGalleryImages($p['gallery'] ?? '');
$actorsHtml = '';
foreach ($actorIds as $aid) {
    $a = getActor($aid);
    if ($a && $a['id']) {
        if ($actorsHtml !== '') $actorsHtml .= '<span class="avsf-post-detail-separator">, </span>';
        $actorsHtml .= '<a class="avsf-post-detail-link avsf-post-detail-link--actor" href="'.esc(siteUrl('actor',$a['slug'])).'">'.esc($a['name']).'</a>';
    }
}
$tagsHtml = '';
foreach ($tagIds as $tid) {
    $tag = getTag($tid);
    if ($tag && $tag['id']) {
        if ($tagsHtml !== '') $tagsHtml .= '<span class="avsf-post-detail-separator">, </span>';
        $tagsHtml .= '<a class="avsf-post-detail-link avsf-post-detail-link--tag" href="'.esc(siteUrl('tag',$tag['slug'])).'">'.esc($tag['name']).'</a>';
    }
}
$categoryHtml = '';
foreach (splitTaxonomyNames($p['category'] ?? '') as $postCategory) {
    foreach ($categoryIndex as $categorySlug => $categoryItem) {
        if ($categoryItem['name'] === $postCategory) {
            if ($categoryHtml !== '') $categoryHtml .= '<span class="avsf-post-detail-separator">, </span>';
            $categoryHtml .= '<a class="avsf-post-detail-link avsf-post-detail-link--category" href="'.esc(siteUrl('category',$categorySlug)).'">'.esc($postCategory).'</a>';
            break;
        }
    }
}
$studioHtml = '';
foreach ($studios as $postStudio) {
    if (!$postStudio['id']) continue;
    if ($studioHtml !== '') $studioHtml .= '<span class="avsf-post-detail-separator">, </span>';
    $studioHtml .= '<a class="avsf-post-detail-link avsf-post-detail-link--studio" href="'.esc(siteUrl('studio',$postStudio['slug'])).'">'.esc($postStudio['name']).'</a>';
}
if ($studioHtml === '') $studioHtml = '<span>'.__('ไม่ระบุ').'</span>';
$posterIsCustom = trim((string)($p['posterUrl'] ?? '')) !== '';
$postPoster = (function_exists('avsfFirstPostImage') ? avsfFirstPostImage($p, true) : (($posterIsCustom ? $p['posterUrl'] : '') ?: ($p['cover'] ?: ''))) ?: $sourceVideo['thumb'];
$posterAlt = trim((string)($p['posterAlt'] ?? '')) !== '' ? $p['posterAlt'] : __('โปสเตอร์ %s', [$p['title']]);
// Video ad slots
$videoAdSlots = json_decode(setting('video_ad_slots','{}'), true);
if (!is_array($videoAdSlots)) $videoAdSlots = [];
$renderVideoAds = function($position) use ($videoAdSlots) {
    $html = '';
    $adLabel = trim(setting('site_ad_label', __('โฆษณา'))) ?: __('โฆษณา');
    $slots = isset($videoAdSlots[$position]) && is_array($videoAdSlots[$position]) ? $videoAdSlots[$position] : [];
    foreach ($slots as $slot) {
        if (!is_array($slot)) continue;
        $destination = isset($slot['destination']) ? trim($slot['destination']) : '';
        $image = isset($slot['image']) ? trim($slot['image']) : '';
        if (substr($image,0,2) === '//') $image = 'https:'.$image;
        if (!validAdDestinationUrl($destination) || !validImageReference($image) || $image === '') continue;
        $html .= '<a class="avsf-post-video-ad" href="'.esc($destination).'" target="_blank" rel="nofollow sponsored noopener noreferrer"><img src="'.esc($image).'" alt="'.esc($adLabel).'" loading="lazy" decoding="async"></a>';
    }
    if ($position === 'inside') {
        return $html !== '' ? '<div class="avsf-post-video-ad-inside" aria-label="'.esc($adLabel).'">'.$html.'<button class="avsf-post-video-ad-close" type="button">'.esc(setting('site_ad_close_label', __('ปิดโฆษณา'))).'</button></div>' : '';
    }
    return $html !== '' ? '<div class="avsf-post-video-ads avsf-post-video-ads-'.esc($position).'" aria-label="'.esc($adLabel).'">'.$html.'</div>' : '';
};
$metaLine = '<span class="avsf-post-meta-date-group"><time class="avsf-post-meta-date" datetime="'.esc(date('c', strtotime($p['date']))).'">📅 '.__('เผยแพร่').' '.fmtDate($p['date']).'</time>'.(themeOn('showViews')?'<span class="avsf-post-meta-views">👁 '.number_format((int)$p['views']).' views</span>':'').'</span>'.($p['videoDuration']?'<span class="avsf-badge avsf-badge-gold">'.clockIcon().' '.esc(formatVideoDuration($p['videoDuration'])).'</span>':'');
// Export to global for head.php
$GLOBALS['metaTitle'] = $metaTitle;
$GLOBALS['metaDesc'] = $metaDesc;
$GLOBALS['metaKeywords'] = $metaKeywords;
$GLOBALS['canonicalUrl'] = $canonicalUrl;
$GLOBALS['videoSchema'] = $videoSchema;
$GLOBALS['breadcrumbItems'] = $breadcrumbItems;
?>
<article id="post-<?php echo (int)$p['id']; ?>" class="avsf-single-video-article">
    <div class="avsf-post-heading-layout">
        <nav class="avsf-single-breadcrumb" style="padding:16px 0;font-size:12px;color:var(--muted);" aria-label="<?php echo esc(__('เส้นทางนำทาง')); ?>">
            <a href="<?php echo esc(siteUrl('home')); ?>" style="color:var(--accent);"><?php _e('หน้าหลัก'); ?></a>
            <?php if ($studio && !empty($studio['id'])): ?> › <a href="<?php echo esc(siteUrl('studio',$studio['slug'])); ?>" style="color:var(--accent);"><?php echo esc($studio['name']); ?></a><?php endif; ?>
            › <span><?php echo esc($p['title']); ?></span>
        </nav>
        <h1 class="avsf-single-video-title" style="font-size:clamp(20px,3.5vw,28px);font-weight:900;line-height:1.3;margin-bottom:12px;"><?php echo esc($p['title']); ?></h1>
        <div class="avsf-post-meta-line"><?php echo $metaLine; ?></div>
    </div>
    <!-- ===== Player ===== -->
    <section class="avsf-single-video-player-section" aria-label="<?php echo esc(__('ตัวเล่นวิดีโอ')); ?>">
        <div class="avsf-container" style="max-width:1100px;margin:0 auto;">
            <div class="avsf-row" style="display:flex;gap:16px;flex-wrap:wrap;">
                <div class="avsf-col-player" style="flex:1 1 640px;min-width:0;">
                    <?php echo $renderVideoAds('top'); ?>
                    <div class="avsf-video-wrap avsf-glow-AVSF" style="margin-bottom:12px;">
                        <?php avsf_render_section('loop-templates/single-video-player.php', compact('p','v','thumbnail','canonicalUrl','canonicalBase','siteDisplayName')); ?>
                        <?php echo $renderVideoAds('inside'); ?>
                    </div>
                    <?php echo $renderVideoAds('bottom'); ?>
                </div>
                <?php $besideAdsHtml = $renderVideoAds('beside_player_1').$renderVideoAds('beside_player_2'); ?>
                <?php if ($besideAdsHtml !== ''): ?>
                    <div class="avsf-col-beside" style="flex:0 0 260px;display:flex;flex-direction:column;gap:12px;"><?php echo $besideAdsHtml; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php avsf_render_section('loop-templates/content-share-buttons.php', ['canonicalUrl'=>$canonicalUrl, 'title'=>$p['title']]); ?>
    <section class="avsf-post-detail-layout<?php echo $postPoster === '' ? ' avsf-post-detail-layout-no-poster' : ''; ?>" aria-label="<?php echo esc(__('ข้อมูลโพสต์')); ?>">
        <?php if ($postPoster !== ''): ?>
            <button class="avsf-post-poster avsf-poster-lightbox" type="button" data-poster-src="<?php echo esc($postPoster); ?>" aria-label="<?php printf(esc(__('ขยายโปสเตอร์ %s')), esc($p['title'])); ?>">
                <img src="<?php echo esc($postPoster); ?>" alt="<?php echo esc($posterAlt); ?>" loading="eager" fetchpriority="high" decoding="async" onload="this.classList.add('avsf-image-ready')" onerror="var layout=this.closest('.avsf-post-detail-layout');this.parentElement.remove();if(layout)layout.classList.add('avsf-post-detail-layout-no-poster')">
            </button>
        <?php endif; ?>
        <div class="avsf-post-detail-panel avsf-post-detail-panel-premium">
            <h2><?php _e('ข้อมูลเรื่อง'); ?></h2>
            <div class="avsf-post-detail-row"><strong><span class="avsf-detail-label-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 6.5A2.5 2.5 0 0 1 5.5 4H10l2 2h6.5A2.5 2.5 0 0 1 21 8.5v8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 16.5z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="M3.5 9h17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="avsf-detail-label-text"><?php _e('หมวดหมู่'); ?></span></strong><div><?php echo $categoryHtml !== '' ? $categoryHtml : '<span style="color:var(--muted);">'.__('ไม่ระบุ').'</span>'; ?></div></div>
            <div class="avsf-post-detail-row"><strong><span class="avsf-detail-label-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="6.5" width="13.5" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="m16.5 10 4.5-2.5v9L16.5 14z" fill="currentColor" stroke="currentColor" stroke-linejoin="round"/></svg></span><span class="avsf-detail-label-text"><?php _e('ค่าย'); ?></span></strong><div><?php echo $studioHtml; ?></div></div>
            <div class="avsf-post-detail-row"><strong><span class="avsf-detail-label-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 2.8 2.75 5.57 6.15.9-4.45 4.34 1.05 6.13L12 16.85l-5.5 2.89 1.05-6.13L3.1 9.27l6.15-.9z" fill="currentColor" stroke="currentColor" stroke-linejoin="round"/></svg></span><span class="avsf-detail-label-text"><?php _e('นักแสดง'); ?></span></strong><div><?php echo $actorsHtml !== '' ? $actorsHtml : '<span style="color:var(--muted);">'.__('ไม่ระบุ').'</span>'; ?></div></div>
            <div class="avsf-post-detail-row"><strong><span class="avsf-detail-label-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12.3V5.5A2.5 2.5 0 0 1 5.5 3h6.8L21 11.7 11.7 21z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><circle cx="8" cy="8" r="1.45" fill="currentColor"/></svg></span><span class="avsf-detail-label-text"><?php _e('แท็ก'); ?></span></strong><div><?php echo $tagsHtml !== '' ? $tagsHtml : '<span style="color:var(--muted);">'.__('ไม่ระบุ').'</span>'; ?></div></div>
        </div>
    </section>
    <?php if (trim(stripHtml((string)$p['content'])) !== ''): ?>
        <div class="avsf-card avsf-post-content-card" style="margin-bottom:20px;">
            <div style="line-height:1.8;font-size:14px;"><?php echo $p['content']; ?></div>
        </div>
    <?php endif; ?>
    <!-- ===== Section 3: Gallery ===== -->
    <?php avsf_render_section('loop-templates/content-gallery.php', ['gallery'=>$gallery, 'p'=>$p]); ?>
    <!-- ===== Section 4: Related posts ===== -->
    <?php avsf_render_section('loop-templates/content-related.php', ['p'=>$p, 'tagIds'=>$tagIds, 'studioIds'=>$studioIds, 'actorIds'=>$actorIds, 'categoryPosts'=>$categoryPosts]); ?>
    <!-- ===== Section 5: Comments ===== -->
    <?php avsf_render_section('loop-templates/content-comments.php', ['p'=>$p]); ?>
</article>
<div style="text-align:center;margin:24px 0;">
    <a class="avsf-btn avsf-btn-ghost" href="<?php echo esc(siteUrl('home')); ?>"><?php _e('← กลับหน้าหลัก'); ?></a>
</div>
