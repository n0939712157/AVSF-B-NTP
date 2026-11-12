<?php
/**
 * Post card (video card) loop template — ใช้ในทุกหน้า list. แยกไฟล์ของตัวเอง.
 */
if (!function_exists('postCardHtml')) {
function postCardHtml($p, $homeCard = false, $hideTags = false, $imageLoading = 'lazy', $imagePriority = 'low') {
    $v = parseVideo($p['videoUrl']);
    $thumb = function_exists('avsfFirstPostImage') ? avsfFirstPostImage($p, false) : ($p['cover'] ?: '');
    if ($thumb === '') $thumb = $v['thumb'];
    // Gallery Preview: keep the real cover first, then append distinct normalized gallery images.
    $galleryPreviewImages = [];
    if ($thumb !== '') $galleryPreviewImages[] = $thumb;
    if (function_exists('normalizeGalleryImages')) {
        foreach (normalizeGalleryImages(isset($p['gallery']) ? $p['gallery'] : '') as $galleryImageUrl) {
            if ($galleryImageUrl !== '' && !in_array($galleryImageUrl, $galleryPreviewImages, true)) {
                $galleryPreviewImages[] = $galleryImageUrl;
            }
        }
    }
    $galleryPreviewAttr = count($galleryPreviewImages) > 1
        ? ' data-gallery-preview="'.esc(json_encode($galleryPreviewImages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG)).'" data-gallery-preview-delay="750"'
        : '';
    $imageLoading = $imageLoading === 'eager' ? 'eager' : 'lazy';
    $imagePriority = in_array($imagePriority, ['high','low','auto'], true) ? $imagePriority : 'low';
    $dur = (themeOn('showDuration') && $p['videoDuration']) ? '<span class="avsf-dur-badge">'.(themeOn('showViews')?'<span class="avsf-post-card-thumb-views">'.number_format((int)$p['views']).' views</span>':'').clockIcon().esc(formatVideoDuration($p['videoDuration'])).'</span>' : '';
    $categoryNames=preg_split('/[,،;|，；\r\n]+/u',isset($p['category'])?$p['category']:'');
    $categoryNames=array_values(array_unique(array_filter(array_map('trim',$categoryNames?:[]),function($name){return $name!=='';})));
    $fallbackCategory = isset($categoryNames[0]) ? strtolower($categoryNames[0]) : '';
    $fallbackTheme = 'default';
    if (strpos($fallbackCategory, 'ซับไทย') !== false) $fallbackTheme = 'subthai';
    elseif (preg_match('/\\bhd\\b/i', $fallbackCategory)) $fallbackTheme = 'hd';
    elseif (strpos($fallbackCategory, 'ใหม่') !== false) $fallbackTheme = 'new';
    elseif (strpos($fallbackCategory, 'ยอดนิยม') !== false || strpos($fallbackCategory, 'popular') !== false) $fallbackTheme = 'popular';
    $categoryHtml='';
    foreach(array_slice($categoryNames,0,1) as $categoryName)$categoryHtml.='<span class="avsf-badge avsf-badge-AVSF">'.esc($categoryName).'</span> ';
    $tagsHtml = (!$homeCard && !$hideTags && themeOn('showTags')) ? implode('', array_map(function($tid){return '<span class="avsf-badge avsf-badge-gray">#'.esc(getTag($tid)['name']).'</span>';}, array_slice(parseTaxonomyIds($p['tagIds']),0,4))) : '';
    $cardDate='<span class="avsf-post-card-date">📅 '.fmtDate($p['date']).'</span>';
    return '<article class="avsf-post-card'.($homeCard?' avsf-post-card-home':'').(($homeCard || $hideTags)?' avsf-post-card-no-tags':'').'"'.$galleryPreviewAttr.' onclick="location.href=\''.esc(siteUrl('post',$p['slug'],[],$p)).'\'">'.
        '<div class="avsf-post-thumb '.($thumb!==''?'avsf-post-thumb--has-image':'avsf-post-thumb--no-image').'">'.
        ($thumb?'<span class="avsf-post-thumb-backdrop" aria-hidden="true"></span>':'').
        '<span class="avsf-post-thumb-fallback avsf-post-thumb-fallback--'.esc($fallbackTheme).'" aria-hidden="true"></span>'.
        ($thumb?'<img src="'.esc($thumb).'" alt="'.esc(trim((isset($p['coverAlt'])?(string)$p['coverAlt']:''))!==''?$p['coverAlt']:$p['title']).'" width="640" height="400" loading="'.esc($imageLoading).'" fetchpriority="'.esc($imagePriority).'" decoding="async" onerror="this.remove();var t=this.closest(\'.avsf-post-thumb\');if(t){t.classList.remove(\'avsf-post-thumb--has-image\');t.classList.add(\'avsf-post-thumb--no-image\');var b=t.querySelector(\'.avsf-post-thumb-backdrop\');if(b)b.remove();}">':'').$dur.
        (!empty($p['code'])?'<span class="avsf-code-badge">'.esc($p['code']).'</span>':'').'</div>'.
        '<div class="avsf-post-card-body"><h3 class="avsf-post-card-title">'.esc($p['title']).'</h3>'.
        '<div class="avsf-post-card-taxonomy-row">'.($categoryHtml!==''?'<div class="avsf-post-category-list">'.$categoryHtml.'</div>':'').$cardDate.'</div>'.
        ($tagsHtml!==''?'<div class="avsf-post-card-footer"><div class="avsf-post-card-footer-tags">'.$tagsHtml.'</div></div>':'').'</div></article>';
}
}
