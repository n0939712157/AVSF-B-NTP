<header class="avsf-site-header">
<div class="avsf-site-header-inner">
    <a class="avsf-site-brand" href="<?php echo esc(siteUrl('home')); ?>" aria-label="<?php echo esc($siteDisplayName); ?>">
        <?php $siteLogoUrl=trim(setting('siteLogo','')); if($siteLogoUrl!==''&&validImageReference($siteLogoUrl)): ?>
            <img src="<?php echo esc($siteLogoUrl); ?>" alt="<?php echo esc($siteDisplayName); ?>" loading="eager" fetchpriority="low" decoding="async">
        <?php else: $brandParts=preg_split('/\s+/u',trim(avsfBrandName())); $brandLast=array_pop($brandParts); echo ($brandParts?esc(implode(' ',$brandParts)):'').'<span>'.esc($brandLast).'</span>'; endif; ?>
    </a>
    <div class="avsf-site-header-actions">
        <?php if (themeOn('showSearch')): ?>
        <div class="avsf-site-search">
            <form method="get" action="<?php echo esc(siteUrl('home')); ?>">
                <input type="search" name="s" placeholder="<?php echo esc(setting('site_search_placeholder', __('ค้นหา...'))); ?>" value="<?php echo esc($q); ?>" aria-label="<?php echo esc(__('ค้นหา')); ?>">
                <button type="submit"><?php echo esc(setting('site_search_button', __('ค้นหา'))); ?></button>
            </form>
        </div>
        <?php endif; ?>
        <button class="avsf-site-mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="site-primary-nav" aria-label="<?php echo esc(__('เปิดเมนู')); ?>">
            <span class="avsf-site-mobile-menu-icon" aria-hidden="true">☰</span><span><?php _e('เมนู'); ?></span>
        </button>
        <nav id="site-primary-nav" class="avsf-site-primary-nav" aria-label="<?php echo esc(__('เมนูหลัก')); ?>">
            <?php
            $headerMenuIcons=[
                'actor_list'=>'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>',
                'studio_list'=>'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 9h.01M15 9h.01"/></svg>',
                'all'=>'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h7M7 16h5"/></svg>',
                'categories'=>'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="7" cy="5" r="1"/><circle cx="7" cy="12" r="1"/><circle cx="7" cy="19" r="1"/></svg>'
            ];
            foreach($siteMenus['header'] as $menuItem):
                $menuHref=$siteMenuUrl($menuItem);$externalMenu=$menuItem['type']==='custom'&&validAdDestinationUrl($menuHref);
            ?>
            <a href="<?php echo esc($menuHref); ?>"<?php echo $siteMenuIsActive($menuItem)?' aria-current="page"':''; ?><?php echo $externalMenu?' target="_blank" rel="noopener noreferrer"':''; ?>><?php echo isset($headerMenuIcons[$menuItem['type']])?$headerMenuIcons[$menuItem['type']]:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/></svg>'; ?><span><?php echo esc(__($menuItem['label'])); ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="avsf-header-language" role="group" aria-label="<?php echo esc(__('เปลี่ยนภาษา')); ?>"><?php echo avsf_lang_switcher_html(); ?></div>
    </div>
</div>
</header>
<main style="width:100%;max-width:none;min-width:0;margin:0;padding:0 clamp(12px,2vw,28px) 20px;">
<nav class="avsf-archive-nav" aria-label="<?php echo esc(__('สำรวจเนื้อหา')); ?>">
<?php foreach($siteMenus['explore'] as $menuItem): $menuHref=$siteMenuUrl($menuItem);$externalMenu=$menuItem['type']==='custom'&&validAdDestinationUrl($menuHref); ?>
<a href="<?php echo esc($menuHref); ?>"<?php echo $siteMenuIsActive($menuItem)?' aria-current="page"':''; ?><?php echo $externalMenu?' target="_blank" rel="noopener noreferrer"':''; ?>><?php echo esc(__($menuItem['label'])); ?></a>
<?php endforeach; ?>
</nav>
