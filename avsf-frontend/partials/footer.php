</main>
<footer style="background:var(--card);border-top:1px solid var(--border);padding:24px 16px;margin-top:16px;">
<div style="max-width:1100px;margin:0 auto;text-align:center;">
<div style="font-size:18px;font-weight:900;margin-bottom:10px;"><?php if($siteLogoUrl!==''&&validImageReference($siteLogoUrl)): ?><img src="<?php echo esc($siteLogoUrl); ?>" alt="<?php echo esc($siteDisplayName); ?>" style="max-width:180px;max-height:48px;object-fit:contain;"><?php else: $brandParts=preg_split('/\s+/u',trim(avsfBrandName())); $brandLast=array_pop($brandParts); echo ($brandParts?esc(implode(' ',$brandParts)):'').'<span style="color:var(--accent);">'.esc($brandLast).'</span>'; endif; ?></div>
<div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-bottom:12px;font-size:12px;color:var(--muted);">
<?php foreach ($publishedPages as $pg): ?><a href="<?php echo esc(siteUrl('page',$pg['slug'])); ?>"><?php echo esc($pg['title']); ?></a><?php endforeach; ?>
</div>
<p style="font-size:11px;color:var(--muted);"><?php echo esc(avsfCopyrightNotice()); ?></p>
</div>
</footer>
<?php if (themeOn('showBottomNav')): ?>
<nav class="avsf-bottom-nav">
<?php
$bottomMenuIcons=[
    'home'=>'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
    'all'=>'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
    'studio_list'=>'<span class="avsf-bottom-emoji" aria-hidden="true">🎬</span>',
    'actor_list'=>'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>',
    'tag_list'=>'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>'
];
foreach($siteMenus['bottom'] as $menuItem):
    $menuHref=$siteMenuUrl($menuItem);$externalMenu=$menuItem['type']==='custom'&&validAdDestinationUrl($menuHref);
?>
<a class="avsf-bottom-btn" href="<?php echo esc($menuHref); ?>"<?php echo $siteMenuIsActive($menuItem)?' aria-current="page"':''; ?><?php echo $externalMenu?' target="_blank" rel="noopener noreferrer"':''; ?>><?php echo isset($bottomMenuIcons[$menuItem['type']])?$bottomMenuIcons[$menuItem['type']]:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>'; ?><span><?php echo esc(__($menuItem['label'])); ?></span></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<div id="lightbox" class="avsf-lightbox" role="dialog" aria-modal="true" aria-label="<?php echo esc(__('ดูภาพแกลเลอรี่')); ?>" onclick="if(event.target===this)closeLightbox()"><button class="avsf-lightbox-close" type="button" aria-label="<?php echo esc(__('ปิดภาพ')); ?>" onclick="closeLightbox()">×</button><button class="avsf-lightbox-nav avsf-is-prev" type="button" data-lightbox-step="-1" aria-label="<?php echo esc(__('ภาพก่อนหน้า')); ?>">‹</button><img id="lightboxImg" src="" alt=""><button class="avsf-lightbox-nav avsf-is-next" type="button" data-lightbox-step="1" aria-label="<?php echo esc(__('ภาพถัดไป')); ?>">›</button></div>
<script>
var banners = <?php echo json_encode($activeBanners, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var bannerIntervalSeconds = <?php $bannerInterval=(int)setting('site_banner_interval_seconds','3'); echo $bannerInterval>=3&&$bannerInterval<=30?$bannerInterval:3; ?>;
var featuredIntervalSeconds = <?php $featuredInterval=(int)setting('site_home_featured_interval_seconds','6'); echo $featuredInterval>=3&&$featuredInterval<=30?$featuredInterval:6; ?>;
document.addEventListener('click',function(event){
  var closeButton=event.target.closest('.avsf-post-video-ad-close');
  if(closeButton){var ad=closeButton.closest('.avsf-post-video-ad-inside');if(ad)ad.remove();}
});
var bannerIdx = 0;
var bannerTimer = null;
function showBanner(i){
  if(!banners.length||i<0||i>=banners.length)return;
  bannerIdx=i;
  var b=banners[i],el=document.getElementById('bannerSlide');
  if(!el)return;
  el.querySelectorAll('[data-hero-slide]').forEach(function(slide,idx){
    var active=idx===i;
    slide.classList.toggle('avsf-is-active',active);
    slide.setAttribute('aria-hidden',active?'false':'true');
  });
  document.querySelectorAll('#bannerDots button').forEach(function(button,idx){button.classList.toggle('avsf-active',idx===i);button.setAttribute('aria-pressed',idx===i?'true':'false');});
}
function buildBannerSlides(){
  var slider=document.getElementById('bannerSlide');
  if(!slider||!banners.length)return;
  banners.forEach(function(b,i){
    var target;
    try{target=new URL(b.link||'',location.href);}catch(e){target=null;}
    var safeLink=target&&(target.protocol==='http:'||target.protocol==='https:')?target.href:'';
    var article=document.createElement('article');article.className='avsf-hero-slide';article.setAttribute('data-hero-slide','');article.setAttribute('aria-hidden','true');
    var copy=document.createElement('div');copy.className='avsf-hero-copy';
    var eyebrow=document.createElement('span');eyebrow.className='avsf-hero-eyebrow';eyebrow.textContent=<?php echo avsf_js(__('แนะนำบทความรหัสหนังใหม่')); ?>;copy.appendChild(eyebrow);
    var titleText=(b.title||'').trim(),codeMatch=titleText.match(/^\[?([A-Z0-9][A-Z0-9-]{2,})\]?\s*(?:[-:|]\s*)?/i),codeText=(b.code||'').trim(),headingText=titleText;
    if(!codeText&&codeMatch)codeText=codeMatch[1];
    if(codeText){var code=document.createElement('strong');code.className='avsf-hero-code';code.textContent=codeText;copy.appendChild(code);}
    if(codeMatch&&codeMatch[1].toLowerCase()===codeText.toLowerCase())headingText=titleText.slice(codeMatch[0].length).trim()||titleText;
    var heading=document.createElement('h2');heading.textContent=headingText||<?php echo avsf_js(__('บทความแนะนำ')); ?>;copy.appendChild(heading);
    if(b.text){var excerpt=document.createElement('p');excerpt.textContent=b.text;copy.appendChild(excerpt);}
    if(safeLink){var readLink=document.createElement('a');readLink.className='avsf-hero-read-link';readLink.href=safeLink;readLink.textContent=<?php echo avsf_js(__('อ่านบทความ　→')); ?>;copy.appendChild(readLink);}
    article.appendChild(copy);
    var cover=document.createElement(safeLink?'a':'div');cover.className='avsf-hero-cover';if(safeLink){cover.href=safeLink;cover.setAttribute('aria-label',<?php echo avsf_js(__('อ่าน')); ?>+' '+titleText);}
    if(b.image){
      try{
        var imageUrl=new URL(b.image,location.href);
        if(imageUrl.protocol==='http:'||imageUrl.protocol==='https:'){
          var image=document.createElement('img');image.src=imageUrl.href;image.alt=titleText;image.width=800;image.height=500;image.loading=i===0?'eager':'lazy';image.decoding='async';if(i===0)image.fetchPriority='high';cover.appendChild(image);
        }
      }catch(e){}
    }
    if(!cover.firstChild){var placeholder=document.createElement('span');placeholder.className='avsf-hero-cover-placeholder';placeholder.setAttribute('aria-hidden','true');placeholder.innerHTML='<span class="avsf-play-mark"><svg class="avsf-play-svg" width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.25 5.94c0-1.17 1.28-1.88 2.26-1.26l9.18 5.81a1.78 1.78 0 0 1 0 3.02l-9.18 5.81a1.5 1.5 0 0 1-2.26-1.26V5.94Z" fill="currentColor"/></svg></span>';cover.appendChild(placeholder);}
    article.appendChild(cover);slider.appendChild(article);
  });
  showBanner(0);
}
document.querySelectorAll('#bannerDots button[data-banner-index]').forEach(function(button){button.addEventListener('click',function(){showBanner(parseInt(button.getAttribute('data-banner-index'),10));startBannerTimer();});});
var bannerPrevious=document.querySelector('[data-hero-prev]');
var bannerNext=document.querySelector('[data-hero-next]');
function startBannerTimer(){
  if(bannerTimer)clearInterval(bannerTimer);
  bannerTimer=null;
  if(banners.length>1&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
    bannerTimer=setInterval(function(){showBanner((bannerIdx+1)%banners.length);},bannerIntervalSeconds*1000);
  }
}
if(bannerPrevious)bannerPrevious.addEventListener('click',function(){showBanner((bannerIdx-1+banners.length)%banners.length);startBannerTimer();});
if(bannerNext)bannerNext.addEventListener('click',function(){showBanner((bannerIdx+1)%banners.length);startBannerTimer();});
var bannerPanel=document.getElementById('bannerSlide');
if(bannerPanel){
  bannerPanel.addEventListener('mouseenter',function(){if(bannerTimer){clearInterval(bannerTimer);bannerTimer=null;}});
  bannerPanel.addEventListener('mouseleave',startBannerTimer);
  var bannerPointerStart=null;
  bannerPanel.addEventListener('pointerdown',function(event){bannerPointerStart=event.clientX;});
  bannerPanel.addEventListener('pointerup',function(event){
    if(bannerPointerStart===null)return;
    var swipeDistance=event.clientX-bannerPointerStart;bannerPointerStart=null;
    if(Math.abs(swipeDistance)>55){showBanner((bannerIdx+(swipeDistance<0?1:-1)+banners.length)%banners.length);startBannerTimer();}
  });
  bannerPanel.addEventListener('pointercancel',function(){bannerPointerStart=null;});
}
document.addEventListener('visibilitychange',function(){if(document.hidden){if(bannerTimer){clearInterval(bannerTimer);bannerTimer=null;}}else startBannerTimer();});
if(banners.length){buildBannerSlides();startBannerTimer();}
var recommendationViewport=document.querySelector('[data-home-carousel]');
if(recommendationViewport){
  var recommendationTrack=recommendationViewport.querySelector('.avsf-home-featured-track');
  var recommendationCards=recommendationTrack?Array.prototype.slice.call(recommendationTrack.querySelectorAll('.avsf-home-featured-card')):[];
  var recommendationButtons=Array.prototype.slice.call(document.querySelectorAll('[data-home-carousel-step]'));
  var recommendationStatus=document.querySelector('.avsf-home-carousel-status');
  var recommendationTimer=null,recommendationPaused=false;
  function recommendationStepSize(){
    if(!recommendationCards.length)return recommendationViewport.clientWidth;
    var gap=parseFloat(getComputedStyle(recommendationTrack).columnGap)||0;
    return recommendationCards[0].getBoundingClientRect().width+gap;
  }
  function updateRecommendationControls(){
    var canScroll=recommendationViewport.scrollWidth-recommendationViewport.clientWidth>2;
    recommendationButtons.forEach(function(button){button.hidden=!canScroll;});
    return canScroll;
  }
  function stopRecommendationTimer(){
    if(recommendationTimer){clearInterval(recommendationTimer);recommendationTimer=null;}
  }
  function moveRecommendationCards(direction,announce){
    var maxScroll=recommendationViewport.scrollWidth-recommendationViewport.clientWidth;
    if(maxScroll<=2)return;
    var current=recommendationViewport.scrollLeft;
    if(direction>0&&current>=maxScroll-3)recommendationViewport.scrollTo({left:0,behavior:'smooth'});
    else if(direction<0&&current<=3)recommendationViewport.scrollTo({left:maxScroll,behavior:'smooth'});
    else recommendationViewport.scrollBy({left:direction*recommendationStepSize(),behavior:'smooth'});
    if(announce&&recommendationStatus){
      var nextIndex=Math.max(0,Math.min(recommendationCards.length-1,Math.round((current+direction*recommendationStepSize())/recommendationStepSize())));
      recommendationStatus.textContent=(<?php echo avsf_js(__('รายการแนะนำที่ %s จาก %s')); ?>).replace('%s',String(nextIndex+1)).replace('%s',String(recommendationCards.length));
    }
  }
  function startRecommendationTimer(){
    stopRecommendationTimer();
    if(!document.hidden&&!recommendationPaused&&recommendationCards.length>1&&updateRecommendationControls()&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
      recommendationTimer=setInterval(function(){moveRecommendationCards(1,false);},featuredIntervalSeconds*1000);
    }
  }
  recommendationButtons.forEach(function(button){button.addEventListener('click',function(){moveRecommendationCards(parseInt(button.getAttribute('data-home-carousel-step'),10)||1,true);startRecommendationTimer();});});
  recommendationViewport.addEventListener('mouseenter',function(){recommendationPaused=true;stopRecommendationTimer();});
  recommendationViewport.addEventListener('mouseleave',function(){recommendationPaused=false;startRecommendationTimer();});
  recommendationViewport.addEventListener('focusin',function(){recommendationPaused=true;stopRecommendationTimer();});
  recommendationViewport.addEventListener('focusout',function(event){if(!recommendationViewport.contains(event.relatedTarget)){recommendationPaused=false;startRecommendationTimer();}});
  recommendationViewport.addEventListener('scroll',updateRecommendationControls,{passive:true});
  window.addEventListener('resize',startRecommendationTimer);
  document.addEventListener('visibilitychange',function(){if(document.hidden)stopRecommendationTimer();else startRecommendationTimer();});
  updateRecommendationControls();
  startRecommendationTimer();
}
var lightboxImages=[],lightboxIndex=0,lastGalleryFocus=null;
function renderLightboxImage(){
  if(!lightboxImages.length)return;
  var image=document.getElementById('lightboxImg');
  image.src=lightboxImages[lightboxIndex].src;
  image.alt=lightboxImages[lightboxIndex].alt;
  var navigation=document.querySelectorAll('[data-lightbox-step]');
  navigation.forEach(function(button){button.hidden=lightboxImages.length<2;});
}
function openLightbox(src,images,index){
  lightboxImages=images&&images.length?images:[{src:src,alt:''}];
  lightboxIndex=Math.max(0,Math.min(index||0,lightboxImages.length-1));
  lastGalleryFocus=document.activeElement;
  renderLightboxImage();
  var lightbox=document.getElementById('lightbox');lightbox.classList.add('avsf-active');document.body.style.overflow='hidden';
  lightbox.querySelector('.avsf-lightbox-close').focus();
}
function closeLightbox(){
  var lightbox=document.getElementById('lightbox');lightbox.classList.remove('avsf-active');document.body.style.overflow='';
  document.getElementById('lightboxImg').removeAttribute('src');lightboxImages=[];
  if(lastGalleryFocus&&typeof lastGalleryFocus.focus==='function')lastGalleryFocus.focus();
}
function stepLightbox(direction){
  if(lightboxImages.length<2)return;
  lightboxIndex=(lightboxIndex+direction+lightboxImages.length)%lightboxImages.length;renderLightboxImage();
}
document.querySelectorAll('[data-gallery-carousel]').forEach(function(carousel){
  var viewport=carousel.querySelector('.avsf-gallery-viewport'),items=Array.from(carousel.querySelectorAll('.avsf-gallery-item'));
  var images=items.map(function(item){var image=item.querySelector('img');return {src:item.getAttribute('data-gallery-src'),alt:image?image.alt:''};});
  var updateButtons=function(){
    var maxScroll=viewport.scrollWidth-viewport.clientWidth;
    carousel.querySelector('[data-gallery-scroll="-1"]').disabled=viewport.scrollLeft<=1;
    carousel.querySelector('[data-gallery-scroll="1"]').disabled=viewport.scrollLeft>=maxScroll-1;
  };
  var scrollGallery=function(direction){
    viewport.scrollTo({left:viewport.scrollLeft+direction*viewport.clientWidth*.85,behavior:'auto'});
  };
  carousel.querySelectorAll('[data-gallery-scroll]').forEach(function(button){
    button.addEventListener('click',function(){scrollGallery(Number(button.getAttribute('data-gallery-scroll')));});
  });
  viewport.addEventListener('scroll',updateButtons,{passive:true});
  window.addEventListener('resize',updateButtons);updateButtons();
  carousel.addEventListener('keydown',function(event){
    if(event.key==='ArrowLeft'||event.key==='ArrowRight'){
      event.preventDefault();scrollGallery(event.key==='ArrowRight'?1:-1);
    }
  });
  items.forEach(function(item,index){item.addEventListener('click',function(){openLightbox(images[index].src,images,index);});});
});
document.querySelectorAll('[data-lightbox-step]').forEach(function(button){
  button.addEventListener('click',function(event){event.stopPropagation();stepLightbox(Number(button.getAttribute('data-lightbox-step')));});
});
document.addEventListener('keydown',function(event){
  var lightbox=document.getElementById('lightbox');
  if(!lightbox.classList.contains('avsf-active'))return;
  if(event.key==='Escape'){event.preventDefault();closeLightbox();}
  else if(event.key==='ArrowLeft'){event.preventDefault();stepLightbox(-1);}
  else if(event.key==='ArrowRight'){event.preventDefault();stepLightbox(1);}
});
function showActionToast(message){
  var oldToast=document.getElementById('actionToast');
  if(oldToast)oldToast.remove();
  var toast=document.createElement('div');toast.id='actionToast';toast.className='avsf-toast';toast.setAttribute('role','status');toast.textContent=message;
  document.body.appendChild(toast);window.setTimeout(function(){toast.remove();},2600);
}
async function copyPostUrl(url){
  try{
    if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(url);}
    else{
      var field=document.createElement('textarea');field.value=url;field.setAttribute('readonly','');field.style.position='fixed';field.style.opacity='0';document.body.appendChild(field);field.select();
      var copied=document.execCommand('copy');field.remove();if(!copied)throw new Error('Clipboard copy was not available');
    }
    showActionToast('คัดลอกลิงก์แล้ว');
  }catch(error){window.prompt('คัดลอกลิงก์นี้',url);}
}
document.querySelectorAll('[data-copy-url]').forEach(function(button){button.addEventListener('click',function(){copyPostUrl(button.getAttribute('data-copy-url'));});});
document.querySelectorAll('[data-native-share]').forEach(function(button){button.addEventListener('click',async function(){
  var url=button.getAttribute('data-share-url'),title=button.getAttribute('data-share-title');
  if(!navigator.share){await copyPostUrl(url);return;}
  try{await navigator.share({title:title,url:url});}
  catch(error){if(error.name!=='AbortError')await copyPostUrl(url);}
});});
document.querySelectorAll('.avsf-post-detail-layout .avsf-post-poster img').forEach(function(image){
  var layout=image.closest('.avsf-post-detail-layout');
  var poster=image.closest('.avsf-post-poster');
  if(!layout||!poster)return;

  var updateOrientation=function(){
    if(!image.naturalWidth||!image.naturalHeight)return;
    var isLandscape=image.naturalWidth>image.naturalHeight;
    layout.classList.toggle('avsf-post-detail-layout-landscape-poster',isLandscape);
    layout.classList.toggle('avsf-post-detail-layout-portrait-poster',!isLandscape);

    // Use the same original poster as a soft full-bleed background behind the
    // untouched foreground image. This removes blank bars without stretching
    // or cropping the source poster itself.
    var source=image.currentSrc||image.src;
    if(source){
      var safeSource=String(source).replace(/["\\\n\r]/g,'\\$&');
      poster.style.setProperty('--avsf-poster-image','url("'+safeSource+'")');
    }
  };

  if(image.complete&&image.naturalWidth>0)updateOrientation();
  else image.addEventListener('load',updateOrientation);

  image.addEventListener('error',function(){
    layout.classList.remove('avsf-post-detail-layout-landscape-poster');
    layout.classList.remove('avsf-post-detail-layout-portrait-poster');
    poster.style.removeProperty('--avsf-poster-image');
  });
});
document.querySelectorAll('.avsf-poster-lightbox').forEach(function(button){button.addEventListener('click',function(){openLightbox(button.getAttribute('data-poster-src'),null,0);});});
</script>

<script>
(function(){
    var toggle=document.querySelector('.avsf-site-mobile-menu-toggle');
    var actions=document.querySelector('.avsf-site-header-actions');
    var nav=document.getElementById('site-primary-nav');
    if(!toggle||!actions||!nav)return;
    function closeMenu(){actions.classList.remove('avsf-menu-open');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','เปิดเมนู');}
    toggle.addEventListener('click',function(){
        var open=!actions.classList.contains('avsf-menu-open');
        actions.classList.toggle('avsf-menu-open',open);
        toggle.setAttribute('aria-expanded',open?'true':'false');
        toggle.setAttribute('aria-label',open?'ปิดเมนู':'เปิดเมนู');
    });
    document.addEventListener('click',function(event){if(!actions.contains(event.target))closeMenu();});
    document.addEventListener('keydown',function(event){if(event.key==='Escape')closeMenu();});
    nav.addEventListener('click',function(event){if(event.target.closest('a'))closeMenu();});
    window.addEventListener('resize',function(){if(window.innerWidth>820||(window.matchMedia&&window.matchMedia('(orientation: landscape)').matches))closeMenu();});
})();
</script>

<script>
/* ===== AVSF modular supplement: poster lightbox / share toggle / like-save / copy ===== */
(function(){
  // Poster lightbox (button[data-poster-lightbox-src])
  document.addEventListener('click', function(e){
    var posterBtn = e.target.closest('[data-poster-lightbox-src]');
    if (posterBtn) {
      var src = posterBtn.getAttribute('data-poster-lightbox-src');
      if (src && typeof openLightbox === 'function') { openLightbox(src, [src], 0); return; }
      // fallback: simple overlay
      var ov = document.createElement('div');
      ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;padding:20px;';
      ov.innerHTML='<img src="'+src+'" style="max-width:100%;max-height:100%;border-radius:8px;">';
      ov.addEventListener('click', function(){ ov.remove(); });
      document.body.appendChild(ov);
    }
    // Share box toggle
    var shareBtn = e.target.closest('#show-sharing-buttons');
    if (shareBtn) {
      var box = document.getElementById('video-share-box');
      if (box) {
        var hidden = box.hasAttribute('hidden');
        if (hidden) { box.removeAttribute('hidden'); shareBtn.setAttribute('aria-expanded','true'); }
        else { box.setAttribute('hidden',''); shareBtn.setAttribute('aria-expanded','false'); }
      }
    }
    // Like / Save (localStorage)
    var likeBtn = e.target.closest('.avsf-capsule-btn-like');
    if (likeBtn) {
      var id = likeBtn.getAttribute('data-post_id');
      var key = 'AVSF_like_' + id, active = likeBtn.classList.toggle('avsf-is-active');
      likeBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
      try { active ? localStorage.setItem(key,'1') : localStorage.removeItem(key); } catch(err){}
      if (typeof showActionToast === 'function') showActionToast(active ? 'ถูกใจแล้ว' : 'ยกเลิกถูกใจ');
    }
    var saveBtn = e.target.closest('.avsf-capsule-btn-save');
    if (saveBtn) {
      var sid = saveBtn.getAttribute('data-save-post');
      var skey = 'AVSF_save_' + sid, sactive = saveBtn.classList.toggle('avsf-is-active');
      saveBtn.setAttribute('aria-pressed', sactive ? 'true' : 'false');
      try { sactive ? localStorage.setItem(skey,'1') : localStorage.removeItem(skey); } catch(err){}
      if (typeof showActionToast === 'function') showActionToast(sactive ? 'บันทึกแล้ว' : 'ลบออกจากรายการที่บันทึก');
    }
  });
  // Restore like/save state on load
  document.querySelectorAll('.avsf-capsule-btn-like').forEach(function(b){ try{ if(localStorage.getItem('AVSF_like_'+b.getAttribute('data-post_id'))){ b.classList.add('avsf-is-active'); b.setAttribute('aria-pressed','true'); } }catch(e){} });
  document.querySelectorAll('.avsf-capsule-btn-save').forEach(function(b){ try{ if(localStorage.getItem('AVSF_save_'+b.getAttribute('data-save-post'))){ b.classList.add('avsf-is-active'); b.setAttribute('aria-pressed','true'); } }catch(e){} });
})();
</script>


<script id="avsf-gallery-card-preview">

(function(){
  'use strict';

  var selector='.avsf-post-card[data-gallery-preview], .avsf-home-featured-card[data-gallery-preview]';
  var states=new WeakMap(),preloadCache=new Map(),selectedCard=null;
  var reducedMotion=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var observer=null;

  function safeUrl(value){
    try{
      var u=new URL(String(value||''),document.baseURI);
      return (u.protocol==='http:'||u.protocol==='https:')?u.href:'';
    }catch(e){return '';}
  }
  function cssUrl(value){return 'url("'+String(value).replace(/["\\\n\r]/g,'\\$&')+'")';}
  function delay(card){return Math.max(500,Math.min(2000,Number(card.getAttribute('data-gallery-preview-delay'))||750));}
  function applyOrientation(img){
    if(!img||!img.naturalWidth||!img.naturalHeight)return;
    var featured=img.closest('.avsf-home-featured-card');
    var frame=featured||img.closest('.avsf-post-thumb');
    if(frame)frame.classList.toggle('avsf-cover-portrait',img.naturalHeight>img.naturalWidth*1.08);

    // Apply the blurred fill only after the real <img> has loaded.
    // This prevents CSS background URLs from eagerly downloading every
    // below-the-fold poster and bypassing native loading="lazy".
    if(img.complete&&img.naturalWidth>0){
      var backdrop=featured
        ? featured.querySelector('.avsf-home-featured-placeholder')
        : (frame?frame.querySelector('.avsf-post-thumb-backdrop'):null);
      if(backdrop){
        var property=featured?'--avsf-featured-cover':'--avsf-card-cover';
        backdrop.style.setProperty(property,cssUrl(img.currentSrc||img.src));
      }
    }
  }

  document.querySelectorAll('.avsf-post-card .avsf-post-thumb > img, .avsf-home-featured-card > img').forEach(function(img){
    if(img.complete)applyOrientation(img);
    img.addEventListener('load',function(){applyOrientation(img);});
  });

  function preload(url){
    if(preloadCache.has(url))return preloadCache.get(url);
    var promise=new Promise(function(resolve){
      var img=new Image(),done=false;
      function finish(ok){if(done)return;done=true;resolve(!!ok);}
      img.onload=function(){finish(img.naturalWidth>0);};
      img.onerror=function(){finish(false);};
      img.decoding='async';img.src=url;
      if(img.complete)finish(img.naturalWidth>0);
    });
    preloadCache.set(url,promise);
    return promise;
  }

  function stateFor(card){
    var old=states.get(card);if(old)return old;
    var urls=[];
    try{
      var raw=JSON.parse(card.getAttribute('data-gallery-preview')||'[]');
      if(Array.isArray(raw))raw.forEach(function(v){
        var u=safeUrl(v);if(u&&urls.indexOf(u)<0)urls.push(u);
      });
    }catch(e){}
    var featured=card.matches('.avsf-home-featured-card');
    var img=featured?card.querySelector(':scope > img'):card.querySelector('.avsf-post-thumb > img');
    if(!img||urls.length<2)return null;
    var original=safeUrl(img.currentSrc||img.src);if(!original)return null;
    if(urls.indexOf(original)<0)urls.unshift(original);
    var backdrop=featured?card.querySelector('.avsf-home-featured-placeholder'):card.querySelector('.avsf-post-thumb-backdrop');
    if(!backdrop)return null;

    var state={
      card:card,image:img,backdrop:backdrop,urls:urls,index:Math.max(0,urls.indexOf(original)),
      original:original,originalAlt:img.alt||'',
      originalBackdrop:backdrop.style.getPropertyValue(featured?'--avsf-featured-cover':'--avsf-card-cover'),
      timer:null,active:false,advancing:false,failed:new Set(),
      touchSelected:false,lastInputType:''
    };
    states.set(card,state);
    applyOrientation(img);
    return state;
  }

  function stopTimer(s){
    if(s.timer){clearTimeout(s.timer);s.timer=null;}
  }

  function showImage(s,url){
    s.image.src=url;
    s.image.addEventListener('load',function(){applyOrientation(s.image);},{once:true});
    s.backdrop.style.setProperty(
      s.card.matches('.avsf-home-featured-card')?'--avsf-featured-cover':'--avsf-card-cover',
      cssUrl(url)
    );
    s.card.classList.add('avsf-gallery-preview-active');
    s.card.setAttribute('data-gallery-previewing','true');
  }

  function reset(s,clearSelection){
    stopTimer(s);
    s.active=false;
    s.advancing=false;
    s.index=Math.max(0,s.urls.indexOf(s.original));
    s.image.src=s.original;
    s.image.alt=s.originalAlt;
    applyOrientation(s.image);

    var prop=s.card.matches('.avsf-home-featured-card')?'--avsf-featured-cover':'--avsf-card-cover';
    if(s.originalBackdrop)s.backdrop.style.setProperty(prop,s.originalBackdrop);
    else s.backdrop.style.removeProperty(prop);

    s.card.classList.remove('avsf-gallery-preview-active');
    s.card.removeAttribute('data-gallery-previewing');

    if(clearSelection){
      s.touchSelected=false;
      s.card.classList.remove('avsf-gallery-preview-selected');
      if(selectedCard===s.card)selectedCard=null;
    }
  }

  // Advance immediately to the first saved Gallery image, then rotate at 750ms.
  // Preloading is interaction-triggered only and never blocks the initial page load.
  async function advance(s){
    if(!s.active||document.hidden||s.advancing)return;
    s.advancing=true;
    var attempts=0,next=s.index;

    while(attempts<s.urls.length-1&&s.active){
      next=(next+1)%s.urls.length;
      var url=s.urls[next];
      attempts++;
      if(s.failed.has(url))continue;

      var loaded=await preload(url);
      if(!s.active)break;
      if(loaded){
        s.index=next;
        showImage(s,url);
        break;
      }
      s.failed.add(url);
    }

    s.advancing=false;
    if(s.active)s.timer=setTimeout(function(){advance(s);},delay(s.card));
  }

  function start(card){
    if(reducedMotion||document.hidden)return;
    var s=stateFor(card);
    if(!s||s.active)return;
    s.active=true;
    s.failed.clear();
    // No initial 750ms wait: show the first valid Gallery frame as soon as ready.
    advance(s);
  }

  function findCard(target){
    return target&&target.closest?target.closest(selector):null;
  }

  function selectCard(card,inputType){
    var s=stateFor(card);
    if(!s)return false;

    if(selectedCard&&selectedCard!==card){
      var previous=states.get(selectedCard);
      if(previous)reset(previous,true);
    }

    selectedCard=card;
    s.touchSelected=(inputType==='touch'||inputType==='pen');
    s.lastInputType=inputType||'';
    card.classList.add('avsf-gallery-preview-selected');
    start(card);
    return true;
  }

  // Any source that applies the visible selection outline also starts preview.
  // This covers tap selection, keyboard focus, and other UI-driven selections.
  if('MutationObserver'in window){
    observer=new MutationObserver(function(records){
      records.forEach(function(record){
        if(record.type!=='attributes'||record.attributeName!=='class')return;
        var card=record.target;
        if(!card.matches||!card.matches(selector))return;
        if(card.classList.contains('avsf-gallery-preview-selected')){
          if(selectedCard&&selectedCard!==card){
            var previous=states.get(selectedCard);
            if(previous)reset(previous,true);
          }
          selectedCard=card;
          start(card);
        }
      });
    });
    document.querySelectorAll(selector).forEach(function(card){
      observer.observe(card,{attributes:true,attributeFilter:['class']});
    });
  }

  // Desktop mouse/trackpad: hover means selected, so preview starts immediately.
  document.querySelectorAll(selector).forEach(function(card){
    if(!card.hasAttribute('tabindex'))card.setAttribute('tabindex','0');
    card.addEventListener('pointerenter',function(e){
      if(e.pointerType==='mouse'){
        card.classList.add('avsf-gallery-preview-selected');
        selectCard(card,'mouse');
      }
    });
    card.addEventListener('pointerleave',function(e){
      if(e.pointerType==='mouse'){
        var s=states.get(card);
        if(s&&!s.touchSelected){
          reset(s,true);
        }
      }
    });
  });

  // Remember the actual input type before focus/click events occur.
  document.addEventListener('pointerdown',function(e){
    var card=findCard(e.target);
    if(!card)return;
    var s=stateFor(card);
    if(s)s.lastInputType=e.pointerType||'';
  },true);

  // Touch: first tap selects and previews; second tap on that same selected card
  // opens it. Tapping a different card transfers the highlight and preview.
  document.addEventListener('click',function(e){
    var card=findCard(e.target);
    if(!card)return;
    var s=stateFor(card);
    if(!s)return;

    var inputType=s.lastInputType||'';
    s.lastInputType='';
    var touchLike=inputType==='touch'||inputType==='pen'||
      (e.detail>0&&window.matchMedia&&window.matchMedia('(hover: none) and (pointer: coarse)').matches);

    if(touchLike){
      if(s.touchSelected&&selectedCard===card){
        reset(s,true);
        return; // allow the original post link/onclick
      }

      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation)e.stopImmediatePropagation();
      selectCard(card,inputType||'touch');
      return;
    }
  },true);

  // Keyboard focus has the same visible outline and instant preview.
  document.addEventListener('focusin',function(e){
    var card=findCard(e.target);
    if(!card)return;
    var s=stateFor(card);
    if(!s)return;
    if(s.lastInputType==='touch'||s.lastInputType==='pen')return;
    selectCard(card,'keyboard');
  });

  document.addEventListener('focusout',function(e){
    var card=findCard(e.target);
    if(!card)return;
    if(e.relatedTarget&&card.contains(e.relatedTarget))return;
    var s=states.get(card);
    if(s&&!s.touchSelected)reset(s,true);
  });

  // Off-screen cards and background tabs stop preview to conserve mobile data.
  if('IntersectionObserver'in window){
    var io=new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting)return;
        var s=states.get(entry.target);
        if(s&&(s.active||s.touchSelected))reset(s,true);
      });
    },{root:null,rootMargin:'160px 0px',threshold:0});
    document.querySelectorAll(selector).forEach(function(card){io.observe(card);});
  }

  document.addEventListener('visibilitychange',function(){
    if(document.hidden){
      document.querySelectorAll(selector).forEach(function(card){
        var s=states.get(card);
        if(s&&(s.active||s.touchSelected))reset(s,true);
      });
    }
  });
})();

</script>



<script>
/* AVSF v3.2.8: synchronize breadcrumb/title/meta block to the actual video frame.
   This reads the rendered player box, so optional side ads and responsive widths
   are included; the heading remains in normal flow and never changes player layout. */
(function(){
  function initPlayerHeadingFit(){
    var article=document.querySelector('.avsf-single-video-article');
    if(!article)return;
    var heading=article.querySelector('.avsf-post-heading-layout');
    var player=article.querySelector('.avsf-single-video-player-section .avsf-video-wrap');
    if(!heading||!player)return;
    var raf=0;
    function sync(){
      raf=0;
      var playerRect=player.getBoundingClientRect();
      if(!playerRect.width || playerRect.width<1)return;
      // Remove the previous translation before measuring the centered flow box.
      heading.style.setProperty('transform','none','important');
      heading.style.setProperty('width',playerRect.width+'px','important');
      heading.style.setProperty('max-width','none','important');
      heading.style.setProperty('box-sizing','border-box','important');
      heading.style.setProperty('margin-left','auto','important');
      heading.style.setProperty('margin-right','auto','important');
      var headingRect=heading.getBoundingClientRect();
      var delta=(playerRect.left+playerRect.width/2)-(headingRect.left+headingRect.width/2);
      heading.style.setProperty('transform','translateX('+delta+'px)','important');
    }
    function schedule(){
      if(raf)return;
      raf=window.requestAnimationFrame(sync);
    }
    schedule();
    window.addEventListener('resize',schedule,{passive:true});
    window.addEventListener('orientationchange',schedule,{passive:true});
    if('ResizeObserver' in window){
      var observer=new ResizeObserver(schedule);
      observer.observe(player);
      observer.observe(article);
    }
    if(document.fonts && document.fonts.ready){document.fonts.ready.then(schedule).catch(function(){});}
    window.addEventListener('load',schedule,{once:true});
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',initPlayerHeadingFit,{once:true});}
  else{initPlayerHeadingFit();}
})();
</script>\n</body>
</html>
