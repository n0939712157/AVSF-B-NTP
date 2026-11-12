<?php
/**
 * loop-templates/content-related.php
 * เรื่องที่เกี่ยวข้อง — จับคู่ตาม category / tag / studio / actor.
 * รับ: $p, $tagIds, $studioIds, $actorIds, $categoryPosts.
 */
if (!function_exists('themeOn') || !themeOn('showRelated')) return;
$tagIds = isset($tagIds) ? $tagIds : [];
$studioIds = isset($studioIds) ? $studioIds : [];
$actorIds = isset($actorIds) ? $actorIds : [];
$categoryPosts = isset($categoryPosts) ? $categoryPosts : publishedPosts();
$postCategories = splitTaxonomyNames($p['category'] ?? '');
$rel = [];
foreach ($categoryPosts as $r) {
    if ((int)$r['id'] === (int)$p['id']) continue;
    $relatedCategories = splitTaxonomyNames($r['category'] ?? '');
    $relatedTagIds = parseTaxonomyIds($r['tagIds'] ?? '');
    $relatedStudioIds = parseTaxonomyIds($r['studioId'] ?? '');
    $relatedActorIds = parseTaxonomyIds($r['actorIds'] ?? '');
    if (array_intersect($postCategories, $relatedCategories) ||
        array_intersect($tagIds, $relatedTagIds) ||
        array_intersect($studioIds, $relatedStudioIds) ||
        array_intersect($actorIds, $relatedActorIds)) {
        $rel[] = $r;
    }
}
shuffle($rel);
$rel = array_slice($rel, 0, avsf_cards_per_page('posts'));
if (!$rel) return;
?>
<section class="avsf-related-posts avsf-related-posts-no-tags" aria-labelledby="related-posts-heading">
    <h2 id="related-posts-heading" style="font-size:18px;font-weight:800;margin-bottom:14px;"><?php _e('เรื่องที่เกี่ยวข้อง'); ?></h2>
    <div class="avsf-grid avsf-related-posts-grid">
        <?php echo implode('', array_map(function ($post) { return postCardHtml($post, false, true); }, $rel)); ?>
    </div>
</section>
