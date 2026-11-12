<?php
/**
 * pages/page-static.php — หน้าเนื้อหาแบบคงที่ (About, DMCA, Contact ฯลฯ) /page/{slug}.
 */
global $conn, $canonicalBase;
$slugE = mysqli_real_escape_string($conn, $slug);
$pg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pages WHERE slug='$slugE' AND status='publish'"));
if (!$pg) {
    http_response_code(404); $GLOBALS['metaTitle']=__('ไม่พบหน้า').' | '.setting('siteName',''); $GLOBALS['robotsContent']='noindex, follow';
    echo '<div class="avsf-card" style="text-align:center;padding:40px;margin-top:20px;">'.__('ไม่พบหน้านี้').' <a href="'.esc(siteUrl('home')).'" style="color:var(--accent);">'.__('กลับหน้าหลัก').'</a></div>'; return;
}
$pageExcerpt = mb_substr(stripHtml($pg['content']), 0, 180, 'UTF-8');
$pageSeoVars = [
    'title'=>$pg['title'], 'name'=>$pg['title'], 'term_title'=>$pg['title'],
    'site_name'=>avsfSeoSiteName(setting('seo_site_name', setting('siteName',''))),
    'site_desc'=>setting('siteDesc',''), 'excerpt'=>$pageExcerpt,
    'primary_keyword'=>setting('seo_primary_keyword',''), 'sep'=>'|',
    'page'=>1, 'currentyear'=>date('Y')
];
$pageTitleTemplate = trim((string)setting('seo_meta_page_title','%%title%% %%sep%% %%sitename%%')) ?: '%%title%% %%sep%% %%sitename%%';
$pageDescTemplate = trim((string)setting('seo_meta_page_desc','%%excerpt%%')) ?: '%%excerpt%%';
$pageTitleSource = trim((string)($pg['metaTitle'] ?? '')) !== '' ? (string)$pg['metaTitle'] : $pageTitleTemplate;
$pageDescSource = trim((string)($pg['metaDesc'] ?? '')) !== '' ? (string)$pg['metaDesc'] : $pageDescTemplate;
// Resolve variables for both inherited templates and per-page overrides.
$GLOBALS['metaTitle'] = renderSeoTemplate($pageTitleSource, $pageSeoVars);
$GLOBALS['metaDesc'] = renderSeoTemplate($pageDescSource, $pageSeoVars);
$GLOBALS['canonicalUrl'] = $canonicalBase . sitePath('page', $pg['slug']);
$GLOBALS['breadcrumbItems'][] = ['name' => $pg['title'], 'url' => $GLOBALS['canonicalUrl']];
?>
<nav style="padding:16px 0;font-size:12px;color:var(--muted);"><a href="<?php echo esc(siteUrl('home')); ?>" style="color:var(--accent);"><?php _e('หน้าหลัก'); ?></a> › <?php echo esc($pg['title']); ?></nav>
<h1 style="font-size:26px;font-weight:900;margin-bottom:16px;"><?php echo esc($pg['title']); ?></h1>
<div class="avsf-card" style="margin-bottom:24px;line-height:1.8;"><?php echo $pg['content']; ?></div>
