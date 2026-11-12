<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>
(function(){
  function family(){
    var width=window.innerWidth||document.documentElement.clientWidth||1280;
    var height=window.innerHeight||document.documentElement.clientHeight||800;
    var touch=(window.matchMedia&&window.matchMedia('(pointer: coarse)').matches)||(navigator.maxTouchPoints>1);
    var shortSide=Math.min(width,height);
    var longSide=Math.max(width,height);
    // Phone screens remain in the mobile profile even when rotated horizontally.
    // Tablets are detected by their shorter side and then split by orientation.
    if(touch&&shortSide>=600&&longSide<=1600)return width>height?'tablet_landscape':'tablet_portrait';
    if(shortSide<600||width<768)return 'mobile';
    return 'desktop';
  }
  var timer=null;
  function syncFamily(){
    var next=family();
    document.documentElement.setAttribute('data-avsf-viewport',next);
    var match=document.cookie.match(/(?:^|; )avsf_card_viewport=([^;]+)/);
    var current=match?decodeURIComponent(match[1]):'';
    if(current!==next){
      document.cookie='avsf_card_viewport='+encodeURIComponent(next)+'; path=/; max-age=31536000; SameSite=Lax';
      window.location.reload();
    }
  }
  syncFamily();
  window.addEventListener('resize',function(){clearTimeout(timer);timer=setTimeout(syncFamily,300);},{passive:true});
  window.addEventListener('orientationchange',function(){clearTimeout(timer);timer=setTimeout(syncFamily,350);},{passive:true});
})();
</script>
<title><?php echo esc($metaTitle); ?></title>
<meta name="description" content="<?php echo esc($metaDesc); ?>">
<meta name="keywords" content="<?php echo esc($metaKeywords); ?>">
<meta name="robots" content="<?php echo esc($robotsContent); ?>">
<?php $siteIconUrl=trim(setting('siteIcon','')); if($siteIconUrl!==''&&validImageReference($siteIconUrl)): ?>
<link rel="icon" href="<?php echo esc($siteIconUrl); ?>">
<link rel="apple-touch-icon" href="<?php echo esc($siteIconUrl); ?>">
<?php endif; ?>
<?php if($canonicalUrl!==''): ?><link rel="canonical" href="<?php echo esc($canonicalUrl); ?>"><?php endif; ?>
<?php
$socialTitle=($page==='home'&&trim(setting('seo_social_title',''))!=='')?trim(setting('seo_social_title')):$metaTitle;
$socialDescription=($page==='home'&&trim(setting('seo_social_desc',''))!=='')?trim(setting('seo_social_desc')):$metaDesc;
$socialImage='';
if($videoSchema&&!empty($videoSchema['thumbnailUrl']))$socialImage=$videoSchema['thumbnailUrl'];
elseif(isset($p) && function_exists('avsfFirstPostImage') && ($pickedPostImage=avsfFirstPostImage($p))!=='')$socialImage=$pickedPostImage;
elseif(isset($p['cover'])&&trim((string)$p['cover'])!=='')$socialImage=trim($p['cover']);
else $socialImage=trim(setting('seo_social_image',''));
if($socialImage!==''&&validImageReference($socialImage)) {
    if(substr($socialImage,0,2)==='//')$socialImage=$requestScheme.':'.$socialImage;
    elseif(isset($socialImage[0])&&$socialImage[0]==='/')$socialImage=$requestScheme.'://'.$requestHost.$socialImage;
}
$twitterCard=setting('seo_social_twitter_card','summary_large_image');
if(!in_array($twitterCard,['summary','summary_large_image'],true))$twitterCard='summary_large_image';
?>
<meta property="og:type" content="<?php echo $videoSchema?'video.other':'website'; ?>">
<meta property="og:site_name" content="<?php echo esc(avsfSeoSiteName($siteDisplayName)); ?>">
<meta property="og:title" content="<?php echo esc($socialTitle); ?>">
<meta property="og:description" content="<?php echo esc($socialDescription); ?>">
<?php if($canonicalUrl!==''): ?><meta property="og:url" content="<?php echo esc($canonicalUrl); ?>"><?php endif; ?>
<?php if($socialImage!==''): ?><meta property="og:image" content="<?php echo esc($socialImage); ?>"><meta property="og:image:alt" content="<?php echo esc($socialTitle); ?>"><?php endif; ?>
<meta name="twitter:card" content="<?php echo esc($twitterCard); ?>">
<meta name="twitter:title" content="<?php echo esc($socialTitle); ?>">
<meta name="twitter:description" content="<?php echo esc($socialDescription); ?>">
<?php if($socialImage!==''): ?><meta name="twitter:image" content="<?php echo esc($socialImage); ?>"><?php endif; ?>
<?php if($page==='post'): ?>
    <?php if($videoSchema): ?><script type="application/ld+json"><?php echo json_encode($videoSchema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?></script><?php endif; ?>
<?php else: ?>
    <?php if($pageSchema): ?><script type="application/ld+json"><?php echo json_encode($pageSchema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?></script><?php endif; ?>
    <script type="application/ld+json"><?php echo json_encode($websiteSchema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
    <script type="application/ld+json"><?php echo json_encode($breadcrumbSchema,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--bg:<?php echo $themeCssColors['bg_color']; ?>;--card:<?php echo $themeCssColors['card_bg']; ?>;--card2:<?php echo $themeCssColors['card2_bg']; ?>;--text:<?php echo $themeCssColors['text_color']; ?>;--muted:<?php echo $themeCssColors['muted_color']; ?>;--accent:<?php echo $accent; ?>;--accent-soft:<?php echo $accent; ?>26;--accent2:<?php echo $themeCssColors['accent2_color']; ?>;--accent3:#22d3ee;--gold:#f5c542;--border:<?php echo $themeCssColors['border_color']; ?>;--danger:#ef4444;--success:#22c55e;--link-color:<?php echo $themeCssColors['link_color']; ?>;--button-bg:<?php echo $themeCssColors['button_bg']; ?>;--button-text:<?php echo $themeCssColors['button_text']; ?>;--menu-bg:<?php echo $themeCssColors['menu_bg']; ?>;--menu-text:<?php echo $themeCssColors['menu_text']; ?>;--menu-active-bg:<?php echo $themeCssColors['menu_active_bg']; ?>;--menu-active-text:<?php echo $themeCssColors['menu_active_text']; ?>;--card-radius:<?php echo $themeCardRadius; ?>px;--card-shadow:<?php echo $themeCardShadow; ?>}
:root{
 --avsf-columns-mobile:<?php echo avsf_card_columns('mobile'); ?>;
 --avsf-columns-tablet-portrait:<?php echo avsf_card_columns('tablet_portrait'); ?>;
 --avsf-columns-tablet-landscape:<?php echo avsf_card_columns('tablet_landscape'); ?>;
 --avsf-columns-desktop:<?php echo avsf_card_columns('desktop'); ?>;
}
<?php
$avsfTaxonomyBasePath = function_exists('avsfCanonicalBasePath') ? rtrim((string)avsfCanonicalBasePath(), '/') : '';
$avsfTaxonomyArtBase = $avsfTaxonomyBasePath . '/uploads/avsf-ui/taxonomy/';
?>
:root{
  --avsf-actor-art:url("<?php echo esc($avsfTaxonomyArtBase . 'actor.webp'); ?>");
  --avsf-studio-art:url("<?php echo esc($avsfTaxonomyArtBase . 'studio.webp'); ?>");
  --avsf-tag-art:url("<?php echo esc($avsfTaxonomyArtBase . 'tag.webp'); ?>");
  --avsf-category-art:url("<?php echo esc($avsfTaxonomyArtBase . 'category.webp'); ?>");
  --avsf-post-empty-art:url("<?php echo esc($avsfTaxonomyArtBase . 'post-no-cover.webp'); ?>");
}
</style>
<?php $avsfPublicBasePath = function_exists('avsfCanonicalBasePath') ? rtrim((string)avsfCanonicalBasePath(), '/') : ''; ?>
<link rel="stylesheet" href="<?php echo esc($avsfPublicBasePath . '/avsf-frontend.css?v=3.2.8-player-fit1'); ?>">


</head>
<body>
<?php if (themeOn('showAnnouncement') && setting('announcement')): ?>
<div class="avsf-announce-bar"><span><?php echo esc(setting('announcement')); ?></span></div>
<?php endif; ?>

