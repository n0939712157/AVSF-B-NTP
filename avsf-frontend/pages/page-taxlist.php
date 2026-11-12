<?php
/**
 * pages/page-taxlist.php — ดัชนี taxonomy: ค่ายทั้งหมด / นักแสดงทั้งหมด / แท็กทั้งหมด
 * เรียกผ่าน ?page=taxlist&slug=studio|actor|tag (หรือ pretty URL /studios, /actors, /tags).
 */
global $conn, $canonicalBase, $siteDisplayName;
$taxType = in_array($slug, ['studio','actor','tag'], true) ? $slug : 'studio';
$table = $taxType === 'studio' ? 'studios' : ($taxType === 'actor' ? 'actors' : 'tags');
$label = $taxType === 'studio' ? __('ค่ายทั้งหมด') : ($taxType === 'actor' ? __('นักแสดงทั้งหมด') : __('แท็กทั้งหมด'));
$items = [];
$res = mysqli_query($conn, "SELECT * FROM $table");
while ($res && ($r = mysqli_fetch_assoc($res))) $items[] = $r;
$allPub = publishedPosts();
// Count once, then order every public taxonomy directory by published-post usage.
$usageCounts = [];
foreach ($allPub as $post) {
    if ($taxType === 'studio') {
        $termId = (int)($post['studioId'] ?? 0);
        if ($termId > 0) $usageCounts[$termId] = ($usageCounts[$termId] ?? 0) + 1;
    } elseif ($taxType === 'actor') {
        $ids = json_decode((string)($post['actorIds'] ?? '[]'), true);
        foreach (array_unique(array_map('intval', is_array($ids) ? $ids : [])) as $termId) {
            if ($termId > 0) $usageCounts[$termId] = ($usageCounts[$termId] ?? 0) + 1;
        }
    } else {
        $ids = json_decode((string)($post['tagIds'] ?? '[]'), true);
        foreach (array_unique(array_map('intval', is_array($ids) ? $ids : [])) as $termId) {
            if ($termId > 0) $usageCounts[$termId] = ($usageCounts[$termId] ?? 0) + 1;
        }
    }
}
foreach ($items as &$item) $item['_post_count'] = (int)($usageCounts[(int)$item['id']] ?? 0);
unset($item);
usort($items, function($a, $b) {
    $byCount = (int)$b['_post_count'] <=> (int)$a['_post_count'];
    return $byCount !== 0 ? $byCount : strnatcasecmp((string)$a['name'], (string)$b['name']);
});
$templateType = $taxType === 'studio' ? 'studio_list' : ($taxType === 'actor' ? 'actor_list' : 'tag_list');
$taxGroup = $taxType === 'studio' ? 'studios' : ($taxType === 'actor' ? 'actors' : 'tags');
$pageSize = avsf_cards_per_page($taxGroup);
$totalItems = count($items);
$totalPages = max(1, (int)ceil($totalItems / $pageSize));
$currentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalPages);
$items = array_slice($items, ($currentPage - 1) * $pageSize, $pageSize);
$directoryRoute = $templateType;
$publicVars = ['site_name'=>setting('siteName',''), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>count($allPub), 'item_count'=>$totalItems];
$visibleTitle = renderSeoSetting('seo_public_'.$templateType.'_title', $label.' | %%site_name%%', $publicVars);
$visibleDescription = renderSeoSetting('seo_public_'.$templateType.'_description', __('รวมรายการทั้งหมดบน %%site_name%%'), $publicVars);
$GLOBALS['metaTitle'] = renderSeoSetting('seo_meta_'.$templateType.'_title', $visibleTitle, $publicVars);
$GLOBALS['metaDesc'] = renderSeoSetting('seo_meta_'.$templateType.'_desc', $visibleDescription, $publicVars);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath($templateType, '', $currentPage > 1 ? ['pg'=>$currentPage] : []);
$GLOBALS['breadcrumbItems'][] = ['name' => $visibleTitle, 'url' => $GLOBALS['canonicalUrl']];
$GLOBALS['pageSchema'] = ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$GLOBALS['metaTitle'],'description'=>$GLOBALS['metaDesc'],'url'=>$GLOBALS['canonicalUrl'],'isPartOf'=>['@type'=>'WebSite','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'],'inLanguage'=>'th'];
$kindLabel = $taxType === 'studio' ? 'STUDIOS' : ($taxType === 'actor' ? 'ACTORS' : 'TAGS');
$neonIcon = static function ($type, $uid = 0) {
    $key = 'avsf-tax-' . preg_replace('/[^a-z0-9_-]/i', '', (string)$type) . '-' . (int)$uid;
    $pink = $key . '-pink'; $violet = $key . '-violet'; $cyan = $key . '-cyan'; $shade = $key . '-shade';
    if ($type === 'actor') {
        return '<svg class="avsf-neon-icon avsf-neon-icon--actor" viewBox="0 0 180 190" role="img" aria-label="Actor artwork" xmlns="http://www.w3.org/2000/svg">'
            . '<defs><linearGradient id="'.$pink.'" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#ff9be6"/><stop offset=".52" stop-color="#c45cff"/><stop offset="1" stop-color="#637dff"/></linearGradient>'
            . '<linearGradient id="'.$violet.'" x1="0" y1="0" x2=".8" y2="1"><stop stop-color="#f9c8ff"/><stop offset=".5" stop-color="#8a55e9"/><stop offset="1" stop-color="#29265d"/></linearGradient>'
            . '<radialGradient id="'.$shade.'"><stop stop-color="#ff7cdb" stop-opacity=".5"/><stop offset="1" stop-color="#a653ff" stop-opacity="0"/></radialGradient>'
            . '<filter id="'.$cyan.'" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="6"/></filter></defs>'
            . '<ellipse cx="92" cy="154" rx="62" ry="22" fill="url(#'.$shade.')" filter="url(#'.$cyan.')"/>'
            . '<path d="M25 174c5-30 23-49 51-57l15-5 15 5c28 8 46 27 51 57 1 6-3 10-9 10H34c-6 0-10-4-9-10Z" fill="url(#'.$violet.')"/>'
            . '<path d="M62 107c8 9 17 13 29 13s21-4 29-13l8 12c-6 18-19 29-37 29s-31-11-37-29l8-12Z" fill="#f2b5f2" opacity=".72"/>'
            . '<path d="M52 60c0-28 16-46 40-46 25 0 42 19 42 47 0 14-3 26-10 37-8 13-20 22-33 22-13 0-25-9-33-22-5-10-6-22-6-38Z" fill="url(#'.$pink.')"/>'
            . '<path d="M51 65c-6-29 8-54 32-59 22-5 43 7 51 29-9-6-16-8-25-8-8 0-14 4-21 8-10 6-21 9-34 9l-3 21Z" fill="#201532" opacity=".94"/>'
            . '<path d="M49 68c-2 17 3 31 12 43-17-10-23-28-21-48 1-22 12-42 33-52-16 15-21 33-24 57Zm91-2c4 17 0 33-10 47 18-10 24-28 22-49-2-20-13-38-31-49 13 14 17 31 19 51Z" fill="#3b245f" opacity=".9"/>'
            . '<path d="M66 84c7 5 14 5 21 0m10 0c7 5 14 5 21 0" fill="none" stroke="#5b347a" stroke-width="3" stroke-linecap="round" opacity=".68"/>'
            . '<path d="M79 101c7 6 16 6 23 0" fill="none" stroke="#fff0ff" stroke-width="3" stroke-linecap="round" opacity=".8"/>'
            . '<path d="M25 174c6-30 24-49 51-57m53 0c27 8 45 27 51 57" fill="none" stroke="#ffb4f0" stroke-width="2" opacity=".7"/>'
            . '<path d="M38 163c17 8 36 12 55 12s39-4 56-12" fill="none" stroke="#a9ddff" stroke-width="2" opacity=".42"/></svg>';
    }
    if ($type === 'tag') {
        return '<svg class="avsf-neon-icon avsf-neon-icon--tag" viewBox="0 0 180 190" role="img" aria-label="Tag artwork" xmlns="http://www.w3.org/2000/svg">'
            . '<defs><linearGradient id="'.$pink.'" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#ff8ce2"/><stop offset=".48" stop-color="#9a5cff"/><stop offset="1" stop-color="#31cfff"/></linearGradient>'
            . '<linearGradient id="'.$violet.'" x1="0" y1="0" x2="0.9" y2="1"><stop stop-color="#fff" stop-opacity=".74"/><stop offset=".35" stop-color="#fff" stop-opacity=".12"/><stop offset="1" stop-color="#5b3aab" stop-opacity=".05"/></linearGradient>'
            . '<filter id="'.$cyan.'" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="7"/></filter></defs>'
            . '<path d="M41 35 116 24l39 42-12 77-78 20-39-42 15-86Z" fill="#d34fff" opacity=".45" filter="url(#'.$cyan.')"/>'
            . '<path d="m38 35 78-12 40 42-14 79-78 18-40-43 14-84Z" fill="url(#'.$pink.')" stroke="#b5eaff" stroke-width="2.5" stroke-linejoin="round"/>'
            . '<path d="m47 42 67-10 33 35-12 69-67 15-33-36 12-73Z" fill="url(#'.$violet.')" opacity=".7"/>'
            . '<circle cx="116" cy="48" r="10" fill="#171229" stroke="#fff" stroke-width="4"/>'
            . '<circle cx="116" cy="48" r="4" fill="#8ae9ff"/>'
            . '<path d="m54 95 48-8m-43 24 37-6" stroke="#fff" stroke-width="5" stroke-linecap="round" opacity=".7"/>'
            . '<path d="m43 37 73-11 39 41" fill="none" stroke="#fff" stroke-width="2" opacity=".68"/>'
            . '<path d="m36 129 30 32 76-18" fill="none" stroke="#8ef0ff" stroke-width="2" opacity=".68"/></svg>';
    }
    return '<svg class="avsf-neon-icon avsf-neon-icon--studio" viewBox="0 0 180 190" role="img" aria-label="Studio artwork" xmlns="http://www.w3.org/2000/svg">'
        . '<defs><linearGradient id="'.$pink.'" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff0ff"/><stop offset=".45" stop-color="#db70f4"/><stop offset="1" stop-color="#647eff"/></linearGradient>'
        . '<linearGradient id="'.$violet.'" x1="0" y1="0" x2="0.9" y2="1"><stop stop-color="#a7f1ff"/><stop offset=".46" stop-color="#a76cff"/><stop offset="1" stop-color="#49317c"/></linearGradient>'
        . '<filter id="'.$cyan.'" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="7"/></filter></defs>'
        . '<path d="m30 61 18-30 104-6-18 32-104 4Z" fill="#e85be4" opacity=".55" filter="url(#'.$cyan.')"/>'
        . '<path d="m28 57 18-31 108-7-18 31-108 7Z" fill="url(#'.$pink.')" stroke="#f7d7ff" stroke-width="2.5" stroke-linejoin="round"/>'
        . '<path d="m48 27 11 27m18-29 11 27m18-28 11 27m18-29 10 27" stroke="#3c2b6c" stroke-width="8" opacity=".82"/>'
        . '<rect x="32" y="58" width="105" height="91" rx="8" fill="url(#'.$violet.')" stroke="#c6b7ff" stroke-width="2.5"/>'
        . '<path d="M42 72h85v64H42z" fill="#211838" opacity=".58" stroke="#f1caff" stroke-width="2"/>'
        . '<path d="M53 87h63m-63 16h48m-48 16h57" stroke="#c6efff" stroke-width="4" stroke-linecap="round" opacity=".72"/>'
        . '<path d="M30 156h110" stroke="#ff90e6" stroke-width="4" stroke-linecap="round" opacity=".8"/>'
        . '<circle cx="147" cy="142" r="19" fill="#31205d" stroke="#7adfff" stroke-width="3"/><circle cx="147" cy="142" r="7" fill="#d4b6ff"/><path d="M147 126v32m-16-16h32m-27-11 22 22m0-22-22 22" stroke="#7adfff" stroke-width="2" opacity=".65"/></svg>';
};
?>
<section class="avsf-tax-stage avsf-tax-stage--<?php echo esc($taxType); ?>">
    <div class="avsf-tax-stage-bg" aria-hidden="true"></div>
    <nav class="avsf-archive-breadcrumb" aria-label="<?php echo esc(__('เส้นทางนำทาง')); ?>"><a href="<?php echo esc(siteUrl('home')); ?>"><?php _e('หน้าหลัก'); ?></a> › <span><?php echo esc($label); ?></span></nav>
    <header class="avsf-tax-stage-head">
        <p class="avsf-tax-stage-kicker"><?php echo esc($kindLabel); ?></p>
        <h1><?php echo esc($visibleTitle); ?></h1>
        <p><?php echo nl2br(esc($visibleDescription)); ?></p>
    </header>
    <?php if ($items): ?>
    <div class="avsf-neon-grid">
        <?php foreach ($items as $it):
            $count = (int)($it['_post_count'] ?? 0);
            $thumb = $taxType === 'studio' ? trim((string)($it['logo'] ?? '')) : ($taxType === 'actor' ? trim((string)($it['photo'] ?? '')) : '');
            $showThumb = $thumb !== '' && validImageReference($thumb);
            $displayName = $taxType === 'tag' ? '#'.$it['name'] : $it['name'];
        ?>
        <a class="avsf-neon-card avsf-neon-card--<?php echo esc($taxType); ?><?php echo $showThumb ? ' avsf-neon-card--has-image' : ' avsf-neon-card--no-image'; ?>" href="<?php echo esc(siteUrl($taxType, $it['slug'])); ?>">
            <span class="avsf-neon-card-copy">
                <span class="avsf-neon-card-name"><?php echo esc($displayName); ?></span>
                <span class="avsf-neon-card-count"><?php echo $count; ?> <?php _e('รายการ'); ?></span>
                <span class="avsf-neon-card-arrow" aria-hidden="true">→</span>
            </span>
            <span class="avsf-neon-card-art">
                <span class="avsf-neon-glass-panel" aria-hidden="true"></span>
                <span class="avsf-neon-art-glow" aria-hidden="true"></span>
                <span class="avsf-neon-art-fallback" aria-hidden="true"><?php echo $neonIcon($taxType, (int)$it['id']); ?></span>
                <?php if ($showThumb): ?>
                    <img src="<?php echo esc($thumb); ?>" alt="<?php echo esc($it['name']); ?>" loading="lazy" decoding="async"
                         onload="var c=this.closest('.avsf-neon-card');if(c)c.classList.add('avsf-neon-card--image-loaded');"
                         onerror="var c=this.closest('.avsf-neon-card');this.remove();if(c){c.classList.remove('avsf-neon-card--has-image','avsf-neon-card--image-loaded');c.classList.add('avsf-neon-card--image-error');}">
                <?php endif; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="avsf-card" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีรายการ'); ?></div>
    <?php endif; ?>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>$directoryRoute, 'currentPage'=>$currentPage, 'totalPages'=>$totalPages]); ?>
</section>
