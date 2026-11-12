<?php
/**
 * taxonomy-templates/taxonomy-actor.php — หน้า archive นักแสดงเดี่ยว (/actor/{slug}).
 * ตั้ง $metaTitle, $metaDesc, $canonicalUrl, $pageSchema, $breadcrumbItems.
 */
global $conn, $canonicalBase, $siteDisplayName;
$slugE = mysqli_real_escape_string($conn, $slug);
$item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM actors WHERE slug='$slugE'"));
if (!$item) {
    http_response_code(404); $GLOBALS['metaTitle']=__('ไม่พบนักแสดง').' | '.setting('siteName',''); $GLOBALS['robotsContent']='noindex, follow';
    echo '<div class="avsf-card" style="text-align:center;padding:40px;margin-top:20px;">'.__('ไม่พบนักแสดงนี้').' <a href="'.esc(siteUrl('actor_list')).'" style="color:var(--accent);">'.__('ดูนักแสดงทั้งหมด').'</a></div>'; return;
}
$pageSize = avsf_cards_per_page('posts');
$allPosts = [];
foreach (publishedPosts() as $pp) {
    if (in_array((int)$item['id'], json_decode($pp['actorIds'] ?? '[]', true) ?: [])) $allPosts[] = $pp;
}
usort($allPosts, function($a,$b){ $d=strcmp((string)$b['date'],(string)$a['date']); return $d!==0?$d:((int)$b['id']-(int)$a['id']); });
$totalPosts = count($allPosts);
$totalPages = max(1, (int)ceil($totalPosts / $pageSize));
$currentPage = min(max(1, (int)($_GET['pg'] ?? 1)), $totalPages);
$posts = array_slice($allPosts, ($currentPage - 1) * $pageSize, $pageSize);
$publicVars = ['actor'=>$item['name'], 'site_name'=>setting('siteName',''), 'site_desc'=>setting('siteDesc',''), 'primary_keyword'=>setting('seo_primary_keyword',''), 'post_count'=>number_format($totalPosts)];
$visibleTitle = renderSeoSetting('seo_public_actor_title', '%%actor%%', $publicVars);
$visibleDescription = trim((string)$item['bio']) !== '' ? $item['bio'] : renderSeoSetting('seo_public_actor_description', __('รวมผลงานของ %%actor%% ทั้งหมด %%post_count%% เรื่อง'), $publicVars);
$GLOBALS['metaTitle'] = taxonomySeoValue('actor', 'title', $item['name'], setting('siteName',''), $item['metaTitle'] ?? '', $totalPosts);
$GLOBALS['metaDesc'] = taxonomySeoValue('actor', 'desc', $item['name'], setting('siteName',''), $item['metaDesc'] ?? '', $totalPosts);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('actor', $item['slug'], $currentPage > 1 ? ['pg'=>$currentPage] : []);
$GLOBALS['pageSchema'] = ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$GLOBALS['metaTitle'],'description'=>$GLOBALS['metaDesc'],'url'=>$GLOBALS['canonicalUrl'],'isPartOf'=>['@type'=>'WebSite','name'=>avsfSeoSiteName($siteDisplayName),'url'=>$canonicalBase.'/'],'inLanguage'=>'th'];
$GLOBALS['breadcrumbItems'][] = ['name'=>__('นักแสดงทั้งหมด'), 'url'=>siteUrl('actor_list')];
$GLOBALS['breadcrumbItems'][] = ['name'=>$item['name'], 'url'=>$GLOBALS['canonicalUrl']];
$photo = trim((string)$item['photo']);
?>
<nav class="avsf-archive-breadcrumb" aria-label="<?php echo esc(__('เส้นทางนำทาง')); ?>" style="padding:16px 0;font-size:12px;color:var(--muted);"><a href="<?php echo esc(siteUrl('home')); ?>" style="color:var(--accent);"><?php _e('หน้าหลัก'); ?></a> › <a href="<?php echo esc(siteUrl('actor_list')); ?>" style="color:var(--accent);"><?php _e('นักแสดงทั้งหมด'); ?></a> › <span><?php echo esc($item['name']); ?></span></nav>
<section class="avsf-archive-hero avsf-taxonomy-hero avsf-taxonomy-actor-hero" style="margin-bottom:18px;">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        <?php if ($photo !== '' && validImageReference($photo)): ?>
            <img src="<?php echo esc($photo); ?>" alt="<?php echo esc($item['name']); ?>" style="width:90px;height:90px;object-fit:cover;border-radius:50%;border:2px solid var(--accent);">
        <?php endif; ?>
        <div style="flex:1 1 200px;min-width:0;">
            <span class="avsf-archive-eyebrow" style="color:var(--accent);font-size:11px;font-weight:700;letter-spacing:.08em;"><?php _e('นักแสดง'); ?></span>
            <h1 style="font-size:clamp(20px,3vw,26px);font-weight:900;margin:4px 0;"><?php echo esc($visibleTitle); ?></h1>
            <?php if ($visibleDescription !== ''): ?><p style="color:var(--muted);font-size:13px;max-width:600px;"><?php echo nl2br(esc($visibleDescription)); ?></p><?php endif; ?>
            <div class="avsf-archive-count" style="margin-top:8px;font-size:13px;"><strong style="color:var(--accent);"><?php echo number_format($totalPosts); ?></strong> <?php _e('เรื่อง'); ?></div>
        </div>
    </div>
</section>
<div class="avsf-archive-heading" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h2 style="font-size:16px;font-weight:800;"><?php printf(__('ผลงานของ %s'), esc($item['name'])); ?></h2><span style="color:var(--muted);font-size:12px;"><?php printf(__('หน้า %s / %s'), $currentPage, $totalPages); ?></span></div>
<?php if (count($posts)): ?>
    <div class="avsf-grid avsf-archive-post-grid" style="gap:12px;margin-bottom:24px;"><?php echo implode('', array_map(function($post, $index) {
            $eager = $index < 2;
            return postCardHtml($post, false, false, $eager ? 'eager' : 'lazy', $index === 0 ? 'high' : ($eager ? 'auto' : 'low'));
        }, $posts, array_keys($posts))); ?></div>
    <?php echo avsf_render_template('partials/pagination.php', ['route'=>'actor', 'currentPage'=>$currentPage, 'totalPages'=>$totalPages, 'slug'=>$item['slug']]); ?>
<?php else: ?>
    <div class="avsf-card avsf-archive-empty" style="text-align:center;color:var(--muted);padding:40px;"><?php _e('ยังไม่มีคอนเทนต์ของนักแสดงนี้'); ?></div>
<?php endif; ?>
