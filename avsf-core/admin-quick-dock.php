<?php
/**
 * AVSF Global Admin Quick Dock
 * Shared, permission-aware floating controls for admin pages and public pages
 * viewed by an authenticated administrator.
 */
if (!function_exists('avsf_admin_quick_dock_html')) {
    function avsf_admin_quick_dock_html($adminUrl = '', $postId = 0, $showMenu = false, $pageId = 0) {
        if (!function_exists('isAdmin') || !isAdmin()) return '';
        $base = function_exists('avsfCanonicalBasePath') ? rtrim((string)avsfCanonicalBasePath(), '/') : '';
        $frontUrl = ($base !== '' ? $base : '') . '/';
        if ($adminUrl === '') $adminUrl = ($base !== '' ? $base : '') . '/admin-login/';
        $adminUrl = (string)$adminUrl;
        // Preserve script endpoints such as index.php; directory aliases get a trailing slash.
        if (!preg_match('~\.php(?:$|[?])~i', $adminUrl)) $adminUrl = rtrim($adminUrl, '/') . '/';
        $adminQuery = strpos($adminUrl, '?') === false ? '?' : '&';
        $dashboardUrl = $adminUrl;
        $newPostUrl = $adminUrl . $adminQuery . 'page=post-new';
        $editUrl = (int)$postId > 0 ? $adminUrl . $adminQuery . 'page=post-edit&id=' . (int)$postId : ((int)$pageId > 0 ? $adminUrl . $adminQuery . 'page=pages&edit=' . (int)$pageId : '');
        $editLabel = (int)$postId > 0 ? 'แก้ไขโพสต์นี้' : 'แก้ไขหน้านี้';
        $logoutUrl = $adminUrl . $adminQuery . 'logout=1';
        $username = (string)($_SESSION['avsf_admin_username'] ?? 'Admin');
        $role = function_exists('currentUserRole') ? (string)currentUserRole() : 'user';
        $rankMap = [
            'owner'=>['rank'=>10,'label'=>'Owner','icon'=>'lion','class'=>'owner'],
            'super_admin'=>['rank'=>9,'label'=>'Super Admin','icon'=>'crown','class'=>'super'],
            'deputy_admin'=>['rank'=>8,'label'=>'Deputy Admin','icon'=>'shield','class'=>'deputy'],
            'editor'=>['rank'=>7,'label'=>'Editor','icon'=>'pen','class'=>'editor'],
            'author'=>['rank'=>6,'label'=>'Author','icon'=>'book','class'=>'author'],
            'moderator'=>['rank'=>5,'label'=>'Moderator','icon'=>'check','class'=>'moderator'],
            'custom'=>['rank'=>4,'label'=>'Custom Admin','icon'=>'diamond','class'=>'custom'],
            'contributor'=>['rank'=>3,'label'=>'Contributor','icon'=>'feather','class'=>'contributor'],
            'subscriber'=>['rank'=>2,'label'=>'Subscriber','icon'=>'user-check','class'=>'subscriber'],
            'user'=>['rank'=>1,'label'=>'User','icon'=>'user','class'=>'user']
        ];
        $rank=$rankMap[$role]??$rankMap['user'];
        $csrf=(string)($_SESSION['csrf_token']??'');
        $canCreate=function_exists('can')?can('posts.create'):false;
        $canEdit=function_exists('can')?(can('posts.edit')||can('posts.edit_all')):false;
        $canCache=function_exists('can')?can('cache.manage'):false;
        $canDashboard=function_exists('can')?(can('posts.view')||can('settings.view')||can('sitecenter.view')):true;
        $icon=function($name) {
            $paths=[
                'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
                'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
                'dashboard'=>'<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
                'write'=>'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
                'edit'=>'<path d="M12 20h9"/><path d="M16 4a2.1 2.1 0 0 1 3 3L8 18l-5 1 1-5Z"/>',
                'theme'=>'<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
                'logout'=>'<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>',
                'lion'=>'<path d="M12 3 8.8 5 5 5.7 4 10l1.7 3.4-.5 4.2L9 21l3-1.2 3 1.2 3.8-3.4-.5-4.2L20 10l-1-4.3L15.2 5z"/><path d="m7 9 2 1m8-1-2 1M9 14h6m-3-3v2m-2 2 2 1 2-1"/><path d="m8 5 1-3 3 2 3-2 1 3"/>',
                'crown'=>'<path d="m3 8 5 4 4-8 4 8 5-4-2 12H5z"/>',
                'shield'=>'<path d="M12 3 20 6v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/><path d="m8 12 2.5 2.5L16 9"/>',
                'pen'=>'<path d="m4 20 4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16z"/><path d="m14.5 6.5 3 3"/>',
                'book'=>'<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 0 4 22z"/><path d="M4 5.5v14A2.5 2.5 0 0 1 6.5 17H20"/>',
                'check'=>'<path d="M12 3 20 6v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/><path d="m8 12 2.5 2.5L16 9"/>',
                'diamond'=>'<path d="m12 3 8 9-8 9-8-9z"/><path d="m4 12 16 0M12 3l-3 9 3 9 3-9z"/>',
                'feather'=>'<path d="M20 4c-8-2-14 4-14 11 0 3 2 5 5 5 7 0 13-6 11-14-1-1-1-1-2-2Z"/><path d="M4 21 15 10M9 16l-1-4m6 0 4 1"/>',
                'user-check'=>'<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7 2 0 3.5.5 5 2"/><path d="m16 19 2 2 4-5"/>',
                'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"/>'
            ];
            if($name==='rocket')return '<text x="12" y="18" text-anchor="middle" font-size="20">🚀</text>';
            return $paths[$name]??$paths['user'];
        };
        $buttons='';
        if($showMenu) $buttons.='<button type="button" class="avsf-gdock-btn" data-avsf-dock-menu title="เมนูหลังบ้าน" aria-label="เมนูหลังบ้าน"><svg viewBox="0 0 24 24">'.$icon('menu').'</svg><span>เมนู</span></button>';
        $buttons.='<a class="avsf-gdock-btn" href="'.htmlspecialchars($frontUrl,ENT_QUOTES,'UTF-8').'" target="_blank" rel="noopener" title="เปิดหน้าบ้าน" aria-label="เปิดหน้าบ้าน"><svg viewBox="0 0 24 24">'.$icon('home').'</svg><span>หน้าบ้าน</span></a>';
        if($canDashboard)$buttons.='<a class="avsf-gdock-btn" href="'.htmlspecialchars($dashboardUrl,ENT_QUOTES,'UTF-8').'" title="เปิดหลังบ้าน" aria-label="เปิดหลังบ้าน"><svg viewBox="0 0 24 24">'.$icon('dashboard').'</svg><span>หลังบ้าน</span></a>';
        if($canCreate)$buttons.='<a class="avsf-gdock-btn" href="'.htmlspecialchars($newPostUrl,ENT_QUOTES,'UTF-8').'" title="เขียนโพสต์ใหม่" aria-label="เขียนโพสต์ใหม่"><svg viewBox="0 0 24 24">'.$icon('write').'</svg><span>เขียนโพสต์</span></a>';
        if(($canEdit&&$editUrl!=='' ) || ((int)$pageId>0&&function_exists('can')&&can('pages.manage')))$buttons.='<a class="avsf-gdock-btn" href="'.htmlspecialchars($editUrl,ENT_QUOTES,'UTF-8').'" title="'.htmlspecialchars($editLabel,ENT_QUOTES,'UTF-8').'" aria-label="'.htmlspecialchars($editLabel,ENT_QUOTES,'UTF-8').'"><svg viewBox="0 0 24 24">'.$icon('edit').'</svg><span>'.htmlspecialchars($editLabel,ENT_QUOTES,'UTF-8').'</span></a>';
        if($canCache)$buttons.='<button type="button" class="avsf-gdock-btn avsf-gdock-cache" data-avsf-clear-cache data-admin-url="'.htmlspecialchars($adminUrl,ENT_QUOTES,'UTF-8').'" data-csrf="'.htmlspecialchars($csrf,ENT_QUOTES,'UTF-8').'" title="ล้างแคชเว็บไซต์" aria-label="ล้างแคชเว็บไซต์"><svg viewBox="0 0 24 24">'.$icon('rocket').'</svg><span>ล้างแคช</span></button>';
        $buttons.='<button type="button" class="avsf-gdock-btn" data-avsf-theme-toggle title="สลับธีม" aria-label="สลับธีม"><svg viewBox="0 0 24 24">'.$icon('theme').'</svg><span>ธีม</span></button>';
        $buttons.='<a class="avsf-gdock-btn avsf-gdock-logout" href="'.htmlspecialchars($logoutUrl,ENT_QUOTES,'UTF-8').'" onclick="return confirm(\'ออกจากระบบผู้ดูแลหรือไม่?\')" title="ออกจากระบบ" aria-label="ออกจากระบบ"><svg viewBox="0 0 24 24">'.$icon('logout').'</svg><span>ออกจากระบบ</span></a>';
        $lang=function_exists('avsf_lang_switcher_html')?avsf_lang_switcher_html():'';
        $lion=$icon('lion');
        return '<aside class="avsf-global-dock avsf-rank-'.$rank['class'].'" aria-label="เมนูด่วนผู้ดูแล"><div class="avsf-gdock-user" title="'.htmlspecialchars($rank['label'].' · '.$username,ENT_QUOTES,'UTF-8').'"><span class="avsf-gdock-rank-icon"><svg viewBox="0 0 24 24">'.$lion.'</svg></span><span class="avsf-gdock-rank-num">'.$rank['rank'].'</span></div><div class="avsf-gdock-user-name">'.htmlspecialchars($username,ENT_QUOTES,'UTF-8').'<small>'.htmlspecialchars($rank['label'],ENT_QUOTES,'UTF-8').'</small></div>'.$buttons.'<div class="avsf-gdock-language">'.$lang.'</div></aside>';
    }

    function avsf_admin_quick_dock_assets() {
        static $done=false;
        if($done)return '';$done=true;
        return <<<'HTML'
<style>
.avsf-global-dock{position:fixed!important;z-index:2147483000;box-sizing:border-box;max-width:calc(100vw - 8px);margin:0!important;right:max(7px,env(safe-area-inset-right));top:50%;transform:translateY(-50%);display:flex;flex-direction:column;align-items:center;gap:5px;width:54px;max-height:calc(100dvh - 20px);overflow-y:auto;overflow-x:visible;padding:7px 6px;border:1px solid rgba(150,165,190,.48);border-radius:20px;background:color-mix(in srgb,var(--card,#fff) 92%,transparent);box-shadow:0 12px 34px rgba(20,32,56,.2);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);scrollbar-width:none}
.avsf-global-dock::-webkit-scrollbar{display:none}.avsf-gdock-user{position:relative;display:grid;place-items:center;width:39px;height:39px;border-radius:13px;background:linear-gradient(145deg,#fff7d3,#e9a12a 55%,#9b3a16);color:#61280c;box-shadow:0 0 16px rgba(232,153,34,.38);border:1px solid rgba(255,220,130,.9)}.avsf-gdock-rank-icon svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-width:1.3;stroke-linecap:round;stroke-linejoin:round}.avsf-gdock-rank-num{position:absolute;right:-5px;top:-5px;width:17px;height:17px;display:grid;place-items:center;border-radius:50%;background:#17243d;color:#fff;font:700 10px/1 system-ui;border:1px solid #fff}.avsf-gdock-user-name{max-width:48px;text-align:center;font:700 9px/1.25 system-ui;color:var(--text,#1e293b);overflow-wrap:anywhere;padding:4px 3px;border:1px solid var(--border,#dbe2ed);border-radius:9px;background:color-mix(in srgb,var(--card,#fff) 90%,transparent)}.avsf-gdock-user-name small{display:block;font-size:8px;font-weight:500;color:var(--muted,#64748b)}
.avsf-gdock-btn{position:relative;display:flex;flex:0 0 37px;align-items:center;justify-content:center;width:39px;height:37px;padding:0;border:1px solid var(--border,#dbe2ed);border-radius:12px;background:var(--card,#fff);color:var(--text,#1e293b);text-decoration:none;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,background .18s ease,border-color .18s ease;box-shadow:0 2px 7px rgba(20,32,56,.06)}.avsf-gdock-btn svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.avsf-gdock-btn span{position:absolute;right:46px;top:50%;transform:translate(5px,-50%);opacity:0;pointer-events:none;white-space:nowrap;padding:7px 10px;border-radius:9px;background:#17243d;color:#fff;font:600 11px/1.2 system-ui;box-shadow:0 5px 18px #0002;transition:opacity .16s ease,transform .16s ease}.avsf-gdock-btn:hover,.avsf-gdock-btn:focus-visible{transform:translateX(-2px);box-shadow:0 5px 16px rgba(32,91,170,.2);border-color:#4989ec;background:color-mix(in srgb,#4989ec 10%,var(--card,#fff));outline:none}.avsf-gdock-btn:hover span,.avsf-gdock-btn:focus-visible span{opacity:1;transform:translate(0,-50%)}.avsf-gdock-cache{color:#ea6c20}.avsf-gdock-logout{color:#dc4545}.avsf-gdock-language{width:100%;display:flex;justify-content:center}.avsf-gdock-language .avsf-lang-switcher{display:flex!important;flex-direction:column!important;align-items:center!important;gap:4px!important}.avsf-gdock-language .avsf-lang-switcher a{display:flex;justify-content:center;align-items:center;min-width:38px;min-height:29px;padding:5px 6px!important;border-radius:9px!important;font:700 10px/1 system-ui!important}
.avsf-rank-owner .avsf-gdock-user{background:linear-gradient(145deg,#fff9d7,#ffc44f 40%,#de6a19 78%,#7b2f12);color:#6f2c0c;border-color:#ffdb8a;box-shadow:0 0 0 2px rgba(240,167,48,.2),0 0 22px rgba(236,139,35,.55)}.avsf-rank-super .avsf-gdock-user{background:linear-gradient(145deg,#f1e8ff,#9a69ed);color:#43217e}.avsf-rank-deputy .avsf-gdock-user{background:linear-gradient(145deg,#e5f7ff,#4e9df3);color:#123d7d}.avsf-rank-editor .avsf-gdock-user{background:linear-gradient(145deg,#e2fbff,#27bac7);color:#07535c}.avsf-rank-author .avsf-gdock-user{background:linear-gradient(145deg,#e8ffed,#46bd72);color:#14552d}.avsf-rank-moderator .avsf-gdock-user{background:linear-gradient(145deg,#e1fffa,#23b89a);color:#075447}.avsf-rank-custom .avsf-gdock-user{background:linear-gradient(145deg,#f1eaff,#8b70db);color:#34236e}.avsf-rank-contributor .avsf-gdock-user{background:linear-gradient(145deg,#ffe9f4,#e77bb0);color:#7a1949}.avsf-rank-subscriber .avsf-gdock-user{background:linear-gradient(145deg,#f0f4ff,#9baed1);color:#35496e}.avsf-rank-user .avsf-gdock-user{background:linear-gradient(145deg,#f4f6fa,#aeb8c8);color:#344054}
.avsf-global-dock.avsf-rank-owner{border-color:rgba(238,156,37,.8);box-shadow:0 12px 34px rgba(20,32,56,.2),0 0 24px rgba(236,139,35,.18)}.avsf-rank-owner .avsf-gdock-user-name{border-color:rgba(238,156,37,.8);background:linear-gradient(145deg,rgba(255,245,204,.96),rgba(238,164,54,.22));color:#8a3a0e;box-shadow:0 0 14px rgba(236,139,35,.2)}.avsf-rank-super .avsf-gdock-user-name{border-color:rgba(157,108,237,.5)}.avsf-rank-deputy .avsf-gdock-user-name{border-color:rgba(67,151,242,.5)}.avsf-rank-editor .avsf-gdock-user-name{border-color:rgba(34,190,202,.5)}.avsf-rank-author .avsf-gdock-user-name{border-color:rgba(59,185,106,.5)}.avsf-rank-moderator .avsf-gdock-user-name{border-color:rgba(29,180,157,.5)}.avsf-rank-custom .avsf-gdock-user-name{border-color:rgba(140,110,220,.5)}.avsf-rank-contributor .avsf-gdock-user-name{border-color:rgba(225,116,171,.5)}.avsf-rank-subscriber .avsf-gdock-user-name{border-color:rgba(134,157,200,.5)}.avsf-rank-user .avsf-gdock-user-name{border-color:rgba(155,166,182,.5)}.avsf-rank-owner .avsf-gdock-user:after{content:"";position:absolute;inset:-4px;border:1px solid rgba(238,156,37,.75);border-radius:16px;animation:avsfOwnerGlow 3s linear infinite}.avsf-rank-super .avsf-gdock-user:after,.avsf-rank-deputy .avsf-gdock-user:after,.avsf-rank-editor .avsf-gdock-user:after,.avsf-rank-author .avsf-gdock-user:after,.avsf-rank-moderator .avsf-gdock-user:after,.avsf-rank-custom .avsf-gdock-user:after,.avsf-rank-contributor .avsf-gdock-user:after,.avsf-rank-subscriber .avsf-gdock-user:after,.avsf-rank-user .avsf-gdock-user:after{content:"";position:absolute;inset:-3px;border:1px solid currentColor;border-radius:15px;opacity:.28}@keyframes avsfOwnerGlow{0%,100%{box-shadow:0 0 5px #f9bd51;transform:scale(1)}50%{box-shadow:0 0 15px #f38a2c;transform:scale(1.06)}}
@media(max-width:700px){.avsf-global-dock{right:max(4px,env(safe-area-inset-right));width:46px;padding:5px 3px;gap:4px;border-radius:16px}.avsf-gdock-user{width:34px;height:34px;border-radius:11px}.avsf-gdock-rank-icon svg{width:23px;height:23px}.avsf-gdock-btn{width:35px;height:35px;flex-basis:35px;border-radius:11px}.avsf-gdock-btn svg{width:17px;height:17px}.avsf-gdock-btn span{right:42px}.avsf-gdock-user-name{font-size:8px;max-width:40px}.avsf-gdock-user-name small{font-size:7px}.avsf-gdock-language .avsf-lang-switcher a{min-width:34px;min-height:27px;font-size:9px!important}}
@media(prefers-reduced-motion:reduce){.avsf-gdock-btn,.avsf-gdock-btn span{transition:none}.avsf-rank-owner .avsf-gdock-user:after{animation:none}}
</style>
<script>
(function(){
  // Dock is a fixed overlay and intentionally does not reserve document layout space.
  if(window.__avsfGlobalDockBound)return;window.__avsfGlobalDockBound=true;
  document.addEventListener('click',function(ev){
    var menu=ev.target.closest('[data-avsf-dock-menu]');if(menu){var t=document.querySelector('.avsf-admin-menu-toggle');if(t)t.click();return;}
    var theme=ev.target.closest('[data-avsf-theme-toggle]');if(theme){var current=document.body.getAttribute('data-admin-theme')||document.documentElement.getAttribute('data-admin-theme')||'light';var next=current==='dark'?'light':'dark';document.body.setAttribute('data-admin-theme',next);document.documentElement.setAttribute('data-admin-theme',next);document.documentElement.classList.toggle('avsf-theme-dark',next==='dark');document.documentElement.classList.toggle('avsf-theme-light',next==='light');try{localStorage.setItem('avsf-admin-theme',next);}catch(e){}return;}
    var clear=ev.target.closest('[data-avsf-clear-cache]');if(clear){if(clear.dataset.busy==='1')return;if(!window.confirm('ล้างแคช HTML ของเว็บไซต์ทั้งหมดหรือไม่?'))return;clear.dataset.busy='1';clear.style.opacity='.55';var body=new URLSearchParams({action:'quick_clear_cache',ajax:'1',csrf_token:clear.getAttribute('data-csrf')||''});fetch(clear.getAttribute('data-admin-url')||'admin-login/',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','Accept':'application/json'},body:body.toString(),cache:'no-store'}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(function(data){if(!data||!data.ok)throw new Error('clear failed');window.alert(data.message||('ล้างแคชสำเร็จ '+(data.cleared||0)+' ไฟล์'));}).catch(function(){window.alert('ล้างแคชไม่สำเร็จ กรุณาตรวจสอบสิทธิ์ cache.manage และลองใหม่');}).finally(function(){clear.dataset.busy='0';clear.style.opacity='';});}
  });
})();
</script>
HTML;
    }

    function avsf_admin_quick_dock_start_buffer() {
        if (!function_exists('isAdmin') || !isAdmin() || headers_sent()) return;
        // The parent Admin shell already owns the persistent dock; do not duplicate it inside Site Center iframes.
        if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) === 'iframe') return;
        ob_start(function($html) {
            $headers=function_exists('headers_list')?headers_list():[];
            foreach($headers as $header){if(stripos($header,'Content-Disposition:')===0 || preg_match('~^Content-Type:\s*(application/zip|application/octet-stream|image/|video/)~i',$header))return $html;}
            if (stripos($html,'</body>')===false || strpos($html,'avsf-global-dock')!==false) return $html;
            $base=function_exists('avsfCanonicalBasePath')?rtrim((string)avsfCanonicalBasePath(),'/'):'';
            $adminUrl=($base!==''?$base:'').'/admin-login/';
            $postId=0;$pageId=0;
            $uri=(string)($_SERVER['REQUEST_URI']??'');
            global $p,$pg;
            if(!empty($p['id']))$postId=(int)$p['id'];
            elseif(!empty($pg['id']))$pageId=(int)$pg['id'];
            $dock=avsf_admin_quick_dock_assets().avsf_admin_quick_dock_html($adminUrl,$postId,false,$pageId);
            return str_ireplace('</body>',$dock.'</body>',$html);
        });
    }
}
