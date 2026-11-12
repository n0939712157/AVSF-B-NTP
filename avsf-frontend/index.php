<?php
require_once dirname(__DIR__) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/cache/cache_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';

ensureCategoriesTable($conn);
ensurePrimarySlugs($conn);
ensureSplitTaxonomyNames($conn);
ensurePostsUpdatedAtColumn($conn);
ensurePagesSeoColumns($conn);
ensureSeoIndexOverridesTable($conn);
ensureServerErrorLogTable($conn);

// ====== Auto Redirect Check (301/302/307/308/410) — ทำงานอัตโนมัติทุกหน้า ======
$AVSFReqUri = $_SERVER['REQUEST_URI'] ?? '';
$avsfReqPath = parse_url($AVSFReqUri, PHP_URL_PATH) ?: $AVSFReqUri;
$AVSFFullUrl = avsfCanonicalOrigin() . $AVSFReqUri;
$AVSFRes = @mysqli_query($conn, "SELECT * FROM redirects WHERE active=1 AND (old_url='" . mysqli_real_escape_string($conn, $avsfReqPath) . "' OR old_url='" . mysqli_real_escape_string($conn, $AVSFFullUrl) . "') LIMIT 1");
if ($AVSFRes && ($AVSFRedir = mysqli_fetch_assoc($AVSFRes))) {
    @mysqli_query($conn, "UPDATE redirects SET hits=hits+1 WHERE id=" . (int)$AVSFRedir['id']);
    $code = (int)$AVSFRedir['code'];
    if ($code === 410) { http_response_code(410); exit('<h1>410 Gone</h1><p>'.__('หน้านี้ถูกลบถาวร').'</p>'); }
    if ($code === 451) { http_response_code(451); exit('<h1>451 Unavailable</h1>'); }
    header('Location: ' . $AVSFRedir['new_url'], true, $code);
    exit;
}

// ====== 404 Logger (บันทึกอัตโนมัติเมื่อเจอ 404) ======
function avsf_log_404($path) {
    global $conn;
    if (!$conn || !$path || $path === '/' || $path === '') return;
    $pE = mysqli_real_escape_string($conn, substr($path, 0, 500));
    @mysqli_query($conn, "INSERT INTO 404_log (path,hits,first_hit,last_hit) VALUES ('$pE',1,NOW(),NOW()) ON DUPLICATE KEY UPDATE hits=hits+1, last_hit=NOW()");
}
// กำหนดให้เรียกทุกครั้งที่ http_response_code(404) — ใช้ register_shutdown ดัก
$GLOBALS['avsf_404_logged'] = false;
function avsf_check_404_on_shutdown() {
    global $avsfReqPath;
    $status=function_exists('http_response_code')?http_response_code():200;
    if ($status === 404 && empty($GLOBALS['avsf_404_logged'])) {
        avsf_log_404($GLOBALS['avsfReqPath'] ?? $_SERVER['REQUEST_URI'] ?? '');
    }
    $lastError=error_get_last();
    $fatalTypes=[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR];
    if($status>=500||(is_array($lastError)&&in_array($lastError['type'],$fatalTypes,true))) {
        global $conn;
        $requestPath=parse_url($GLOBALS['avsfReqPath']??($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        $requestPath=is_string($requestPath)?substr($requestPath,0,500):'/';
        $errorType=is_array($lastError)&&in_array($lastError['type'],$fatalTypes,true)?'PHP_FATAL_'.$lastError['type']:'HTTP_'.$status;
        $stmt=mysqli_prepare($conn,'INSERT INTO server_error_log (path,error_type,hits,first_hit,last_hit) VALUES (?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE hits=hits+1,last_hit=NOW()');
        if($stmt) {
            mysqli_stmt_bind_param($stmt,'ss',$requestPath,$errorType);
            if(!mysqli_stmt_execute($stmt))error_log('AVSF server error report write failed: '.mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
        } else error_log('AVSF server error report prepare failed: '.mysqli_error($conn));
    }
}
register_shutdown_function('avsf_check_404_on_shutdown');

// ===== Maintenance Mode (ปิดเว็บชั่วคราว — เปลี่ยนเป็น true ตอนแก้ไข) =====
$maintenance = false;
if ($maintenance && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') === false && !isAdmin()) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.__('กำลังปรับปรุง').'</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0f172a;color:#e2e8f0;text-align:center}h1{font-size:28px;margin-bottom:10px}p{color:#94a3b8}</style></head><body><div><h1>'.__('🔧 กำลังปรับปรุงระบบ').'</h1><p>'.__('ขออภัยในความไม่สะดวก กรุณากลับมาใหม่ในเร็วๆ นี้').'</p></div></body></html>';
    exit;
}

// ===== ระบบแคช (เปิด/ปิดผ่าน setting 'cache_enabled') =====
if (setting('cache_enabled', '0') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_POST)) {
    $cacheTtl = (int)setting('cache_ttl', '600');
    $AVSFCache = new AVSFCache(AVSF_CACHE_DIR, $cacheTtl);
    if ($AVSFCache->start()) { exit; }
    register_shutdown_function(function() use ($AVSFCache) { $AVSFCache->end(); });
}

// ===== View Beacon (inject ผ่าน output buffer) =====
if (!defined('AVSF_VIEW_BEACON_INJECTED')) {
    define('AVSF_VIEW_BEACON_INJECTED', true);
    ob_start(function($buffer) {
        global $p;
        $scripts = '';
        // Some free hosts append ?i=1 after their browser-security check.
        // Hide only that temporary parameter in the address bar without a redirect/reload.
        $scripts .= '<script>(function(){try{var u=new URL(window.location.href);if(u.searchParams.get("i")==="1"){u.searchParams.delete("i");var q=u.searchParams.toString();window.history.replaceState(window.history.state,"",u.pathname+(q?"?"+q:"")+u.hash);}}catch(e){}})();</script>';
        if (!empty($p) && is_array($p) && !empty($p['id'])) {
            $viewEndpoint = rtrim(avsfCanonicalBasePath(), '/') . '/view.php?id=' . (int)$p['id'];
            $scripts .= '<script>(function(){fetch(' . json_encode($viewEndpoint, JSON_UNESCAPED_SLASHES) . ',{mode:"no-cors"});})();</script>';
        }
        $basePath = rtrim(avsfCanonicalBasePath(), '/');
        $pageViewEndpoint = $basePath . '/page-view.php';
        $scripts .= '<script>(function(){try{var data={path:location.pathname,title:document.title};fetch('.json_encode($pageViewEndpoint, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(data),keepalive:true,credentials:"same-origin"}).catch(function(){});}catch(e){}})();</script>';
        if (function_exists('isAdmin') && isAdmin() && strpos($buffer,'avsf-global-dock')===false) {
            $adminBase = rtrim(avsfCanonicalBasePath(), '/');
            $adminDockUrl = ($adminBase !== '' ? $adminBase : '') . '/admin-login/';
            global $pg;
            $currentPostId = (!empty($p) && is_array($p) && !empty($p['id'])) ? (int)$p['id'] : 0;
            $currentPageId = (!$currentPostId && !empty($pg['id'])) ? (int)$pg['id'] : 0;
            $scripts .= avsf_admin_quick_dock_assets() . avsf_admin_quick_dock_html($adminDockUrl, $currentPostId, false, $currentPageId);
        }
        if (strpos($buffer, '</body>') !== false) {
            $buffer = str_replace('</body>', $scripts.'</body>', $buffer);
        }
        return $buffer;
    });
}

$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
$routeBase=isset($_GET['route_base'])&&is_string($_GET['route_base'])?strtolower(trim($_GET['route_base'],'/')):'';
$routeSlug=isset($_GET['route_slug'])&&is_string($_GET['route_slug'])?$_GET['route_slug']:'';
$permalinkBases=avsfPermalinkBases();
$routeTypeMap=[];
foreach($permalinkBases as $type=>$baseName) {
    $routeTypeMap[$baseName]=$type;
    $legacyBase=['category'=>'category','tag'=>'tag','actor'=>'actor','studio'=>'studio'][$type];
    $routeTypeMap[$legacyBase]=$type;
}
if($routeBase!=='') {
    $slug=$routeSlug;
    if($routeBase==='post')$page='post';
    elseif(isset($routeTypeMap[$routeBase]))$page=$routeTypeMap[$routeBase];
    elseif($routeBase==='page')$page='page';
    else $page='not-found';
} elseif(isset($_GET['route_slug'])&&is_string($_GET['route_slug'])) {
    $slug=$_GET['route_slug'];
    $routeLists=['all'=>'all','categories'=>'categories'];
    foreach($routeTypeMap as $baseName=>$type) {
        if(!isset($routeLists[$baseName]) && in_array($type,['studio','actor','tag'],true))$routeLists[$baseName]='taxlist';
    }
    // Legacy list roots stay routable after a base URL is changed and are redirected canonically.
    $routeLists['studio']='taxlist';$routeLists['actor']='taxlist';$routeLists['tag']='taxlist';
    if(isset($routeLists[$slug])) {
        $page=$routeLists[$slug];
        if($page==='taxlist')$slug=$routeTypeMap[$slug]??$slug;
    } else $page='post';
}

// Resolve plain (?p=ID) and numeric (/archives/ID/) permalink requests to a published post.
$resolvedPermalinkPost=null;
$incomingPostId=0;
$hasIncomingPostId=isset($_GET['route_post_id'])||isset($_GET['p']);
if(isset($_GET['route_post_id'])&&is_string($_GET['route_post_id'])&&ctype_digit($_GET['route_post_id']))$incomingPostId=(int)$_GET['route_post_id'];
elseif(isset($_GET['p'])&&(is_string($_GET['p'])||is_numeric($_GET['p']))&&ctype_digit((string)$_GET['p']))$incomingPostId=(int)$_GET['p'];
if($hasIncomingPostId&&$incomingPostId>0) {
    $postByIdStmt=mysqli_prepare($conn,"SELECT id,slug,date FROM posts WHERE id=? AND status='publish' LIMIT 1");
    if($postByIdStmt) {
        mysqli_stmt_bind_param($postByIdStmt,'i',$incomingPostId);
        if(mysqli_stmt_execute($postByIdStmt)) {
            mysqli_stmt_bind_result($postByIdStmt,$foundPostId,$foundPostSlug,$foundPostDate);
            if(mysqli_stmt_fetch($postByIdStmt)) {
                $resolvedPermalinkPost=['id'=>(int)$foundPostId,'slug'=>(string)$foundPostSlug,'date'=>(string)$foundPostDate];
                $page='post';$slug=(string)$foundPostSlug;
            } else $page='not-found';
        } else $page='not-found';
        mysqli_stmt_close($postByIdStmt);
    } else $page='not-found';
} elseif($hasIncomingPostId) {
    $page='not-found';
} elseif(isset($_GET['route_date'])||isset($_GET['route_month'])) {
    $dateSlug=isset($_GET['route_slug'])&&is_string($_GET['route_slug'])?$_GET['route_slug']:$slug;
    if($dateSlug!=='') {
        $postBySlugStmt=mysqli_prepare($conn,"SELECT id,slug,date FROM posts WHERE slug=? AND status='publish' LIMIT 1");
        if($postBySlugStmt) {
            mysqli_stmt_bind_param($postBySlugStmt,'s',$dateSlug);
            if(mysqli_stmt_execute($postBySlugStmt)) {
                mysqli_stmt_bind_result($postBySlugStmt,$foundPostId,$foundPostSlug,$foundPostDate);
                if(mysqli_stmt_fetch($postBySlugStmt)) {
                    $resolvedPermalinkPost=['id'=>(int)$foundPostId,'slug'=>(string)$foundPostSlug,'date'=>(string)$foundPostDate];
                    $page='post';$slug=(string)$foundPostSlug;
                } else $page='not-found';
            } else $page='not-found';
            mysqli_stmt_close($postBySlugStmt);
        } else $page='not-found';
    } else $page='not-found';
}
if(isset($_GET['s'])&&is_string($_GET['s'])) {
    $page='search';$q=$_GET['s'];
} else $q=isset($_GET['q'])&&is_string($_GET['q'])?$_GET['q']:'';
if($slug!==''&&in_array($page,['post','page','studio','actor','tag','category'],true)) {
    $aliasStmt=mysqli_prepare($conn,"SELECT newSlug FROM slug_aliases WHERE entityType=? AND oldSlug=? LIMIT 1");
    if(!$aliasStmt)throw new RuntimeException('Could not prepare legacy slug lookup: '.mysqli_error($conn));
    $canonicalSlug=$slug;$visited=[];
    for($depth=0;$depth<10;$depth++) {
        if(isset($visited[$canonicalSlug]))break;
        $visited[$canonicalSlug]=true;
        mysqli_stmt_bind_param($aliasStmt,'ss',$page,$canonicalSlug);
        if(!mysqli_stmt_execute($aliasStmt)) {
            $error=mysqli_stmt_error($aliasStmt);mysqli_stmt_close($aliasStmt);
            throw new RuntimeException('Could not look up legacy slug: '.$error);
        }
        mysqli_stmt_bind_result($aliasStmt,$nextSlug);
        if(!mysqli_stmt_fetch($aliasStmt))break;
        mysqli_stmt_free_result($aliasStmt);
        if($nextSlug===$canonicalSlug)break;
        $canonicalSlug=$nextSlug;
    }
    mysqli_stmt_close($aliasStmt);
    if($canonicalSlug!==$slug) {
        header('Location: '.siteUrl($page,$canonicalSlug),true,301);
        exit;
    }
}
if($_SERVER['REQUEST_METHOD']==='GET'&&!isset($_GET['route_base'])&&!isset($_GET['route_slug'])&&isset($_GET['page'])) {
    $legacyRoute=null;$legacySlug=$slug;$legacyQuery=[];
    if($page==='post'&&$slug!=='')$legacyRoute='post';
    elseif(in_array($page,['studio','actor','tag','category','page'],true)&&$slug!=='')$legacyRoute=$page;
    elseif($page==='taxlist'&&in_array($slug,['studio','actor','tag'],true))$legacyRoute=$slug.'_list';
    elseif(in_array($page,['home','all','categories'],true))$legacyRoute=$page;
    elseif($page==='search'){$legacyRoute='search';$legacySlug=$q;}
    if($legacyRoute!==null) {
        if(isset($_GET['pg'])&&ctype_digit((string)$_GET['pg'])&&(int)$_GET['pg']>1)$legacyQuery['pg']=(int)$_GET['pg'];
        header('Location: '.siteUrl($legacyRoute,$legacySlug,$legacyQuery),true,301);
        exit;
    }
}
if($_SERVER['REQUEST_METHOD']==='GET'&&(isset($_GET['route_base'])||isset($_GET['route_slug'])||isset($_GET['route_post_id'])||isset($_GET['route_date'])||isset($_GET['route_month'])||isset($_GET['p']))) {
    $prettyRoute=$page;$prettySlug=$slug;$prettyQuery=[];
    if($page==='taxlist')$prettyRoute=$slug.'_list';
    if($page==='post'&&isset($_GET['pg']))$prettyQuery['pg']=$_GET['pg'];
    if($page==='not-found')$prettyRoute='';
    if($prettyRoute!=='') {
        $prettyUrl=siteUrl($prettyRoute,$prettySlug,$prettyQuery,$resolvedPermalinkPost);
        $prettyParts=parse_url($prettyUrl);
        $requestParts=parse_url($_SERVER['REQUEST_URI']??'/');
        $prettyPath=is_array($prettyParts)?(string)($prettyParts['path']??'/'):'';
        $requestPath=is_array($requestParts)?(string)($requestParts['path']??'/'):'';
        $prettyQueryValues=[];$requestQueryValues=[];
        if(is_array($prettyParts)&&isset($prettyParts['query']))parse_str((string)$prettyParts['query'],$prettyQueryValues);
        if(is_array($requestParts)&&isset($requestParts['query']))parse_str((string)$requestParts['query'],$requestQueryValues);
        unset($prettyQueryValues['i'],$requestQueryValues['i']);
        // Check only query parameters required by the selected permalink (for example p=123).
        // Preserve unrelated campaign/tracking parameters on otherwise canonical pretty URLs.
        $queryMismatch=false;
        foreach($prettyQueryValues as $requiredKey=>$requiredValue) {
            if(!array_key_exists($requiredKey,$requestQueryValues)||$requestQueryValues[$requiredKey]!=$requiredValue) {$queryMismatch=true;break;}
        }
        if($prettyPath!==''&&$requestPath!==''&&(rtrim($requestPath,'/')!==rtrim($prettyPath,'/')||$queryMismatch)) {
            header('Location: '.$prettyUrl,true,301);exit;
        }
    }
}
$accent = setting('theme_accent', '#ff2d78');
if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$accent)) $accent = '#ff2d78';
$themeCssDefaults = [
    'bg_color'=>'#0a0a0f','text_color'=>'#ffffff','muted_color'=>'#8b8b9a','accent2_color'=>'#a855f7',
    'link_color'=>'#ff79a8','button_bg'=>$accent,'button_text'=>'#ffffff',
    'card_bg'=>'#14141c','card2_bg'=>'#1a1a24','border_color'=>'#343443',
    'menu_bg'=>'#101018','menu_text'=>'#b5b5c5','menu_active_bg'=>'#321426','menu_active_text'=>'#ff70a4'
];
$themeCssColors = [];
foreach ($themeCssDefaults as $themeKey=>$themeDefault) {
    $themeValue = setting('theme_'.$themeKey, $themeDefault);
    $themeCssColors[$themeKey] = preg_match('/^#[0-9a-f]{6}$/i', (string)$themeValue) ? $themeValue : $themeDefault;
}
$themeCardRadius = (int)setting('theme_card_radius','14');
if ($themeCardRadius < 8 || $themeCardRadius > 24) $themeCardRadius = 14;
$themeCardShadowName = setting('theme_card_shadow','soft');
$themeCardShadow = $themeCardShadowName === 'none' ? 'none' : ($themeCardShadowName === 'deep' ? '0 14px 36px rgba(0,0,0,.38)' : '0 6px 22px rgba(0,0,0,.18)');
$navLabels = json_decode(setting('theme_navLabels', '["หน้าหลัก","ค่าย","นักแสดง","แท็ก"]'), true);
if (!is_array($navLabels)) $navLabels = ['หน้าหลัก','ค่าย','นักแสดง','แท็ก'];
$defaultSiteMenus=[
    'header'=>[
        ['label'=>'นักแสดง','type'=>'actor_list','value'=>''],
        ['label'=>'ค่าย','type'=>'studio_list','value'=>''],
        ['label'=>'รหัสเรื่อง','type'=>'all','value'=>''],
        ['label'=>'บทความ','type'=>'categories','value'=>'']
    ],
    'explore'=>[
        ['label'=>'เรื่องทั้งหมด','type'=>'all','value'=>''],
        ['label'=>'หมวดหมู่','type'=>'categories','value'=>'']
    ],
    'bottom'=>[
        ['label'=>isset($navLabels[0])?$navLabels[0]:'หน้าหลัก','type'=>'home','value'=>''],
        ['label'=>isset($navLabels[1])?$navLabels[1]:'ค่าย','type'=>'studio_list','value'=>''],
        ['label'=>isset($navLabels[2])?$navLabels[2]:'นักแสดง','type'=>'actor_list','value'=>''],
        ['label'=>isset($navLabels[3])?$navLabels[3]:'แท็ก','type'=>'tag_list','value'=>'']
    ]
];
$readSiteMenu=function($key,$defaults) {
    $stored=json_decode(setting($key,''),true);
    if(!is_array($stored))return $defaults;
    $allowed=['home','all','categories','studio_list','actor_list','tag_list','category','studio','actor','tag','page','custom'];
    $items=[];
    foreach($stored as $item) {
        if(!is_array($item))continue;
        $label=trim(isset($item['label'])?(string)$item['label']:'');
        $type=isset($item['type'])?(string)$item['type']:'';
        $value=isset($item['value'])?(string)$item['value']:'';
        if($label==='' || !in_array($type,$allowed,true))continue;
        if(in_array($type,['category','studio','actor','tag','page'],true) && !preg_match('/^[a-z0-9ก-๙-]+$/iu',$value))continue;
        if($type==='custom' && !(validAdDestinationUrl($value) || (preg_match('/^(?:index\.php\?|\/(?!\/))[^\s<>"]*$/u',$value))))continue;
        $items[]=['label'=>$label,'type'=>$type,'value'=>$value];
    }
    return $items;
};
$siteMenus=[
    'header'=>$readSiteMenu('site_menu_header',$defaultSiteMenus['header']),
    'explore'=>$readSiteMenu('site_menu_explore',$defaultSiteMenus['explore']),
    'bottom'=>$readSiteMenu('site_menu_bottom',$defaultSiteMenus['bottom'])
];
$siteMenuUrl=function($item) {
    $type=$item['type'];$value=$item['value'];
    if($type==='home')return siteUrl('home');
    if($type==='all')return siteUrl('all');
    if($type==='categories')return siteUrl('categories');
    if($type==='studio_list')return siteUrl('studio_list');
    if($type==='actor_list')return siteUrl('actor_list');
    if($type==='tag_list')return siteUrl('tag_list');
    if(in_array($type,['category','studio','actor','tag','page'],true))return siteUrl($type,$value);
    return $value;
};
$siteMenuIsActive=function($item) use ($page,$slug) {
    if($item['type']==='home')return $page==='home';
    if($item['type']==='all')return $page==='all';
    if($item['type']==='categories')return $page==='categories'||$page==='category';
    if($item['type']==='studio_list')return $page==='taxlist'&&$slug==='studio';
    if($item['type']==='actor_list')return $page==='taxlist'&&$slug==='actor';
    if($item['type']==='tag_list')return $page==='taxlist'&&$slug==='tag';
    if($item['type']==='category')return $page==='category'&&$slug===$item['value'];
    if($item['type']==='studio')return $page==='studio'&&$slug===$item['value'];
    if($item['type']==='actor')return $page==='actor'&&$slug===$item['value'];
    if($item['type']==='tag')return $page==='tag'&&$slug===$item['value'];
    if($item['type']==='page')return $page==='page'&&$slug===$item['value'];
    return false;
};
$publishedPages = [];
$res = mysqli_query($conn, "SELECT * FROM pages WHERE status='publish'");
while ($r = mysqli_fetch_assoc($res)) $publishedPages[] = $r;
$activeBanners = [];
$res = mysqli_query($conn, "SELECT * FROM banners WHERE active=1");
while ($r = mysqli_fetch_assoc($res)) {
    if (trim($r['link'])!=='') {
        $bannerQuery=parse_url($r['link'],PHP_URL_QUERY);
        $bannerParams=[];
        if ($bannerQuery) parse_str($bannerQuery,$bannerParams);
        $bannerPostSlug=isset($bannerParams['page'],$bannerParams['slug']) && $bannerParams['page']==='post' ? trim($bannerParams['slug']) : '';
        if ($bannerPostSlug!=='') {
            $bannerPostStmt=mysqli_prepare($conn,"SELECT title,code,content,cover,posterUrl,gallery,videoUrl,status FROM posts WHERE slug=? LIMIT 1");
            if ($bannerPostStmt) {
                mysqli_stmt_bind_param($bannerPostStmt,'s',$bannerPostSlug);
                mysqli_stmt_execute($bannerPostStmt);
                $bannerPostResult=mysqli_stmt_get_result($bannerPostStmt);
                $bannerPost=$bannerPostResult?mysqli_fetch_assoc($bannerPostResult):null;
                mysqli_stmt_close($bannerPostStmt);
                if ($bannerPost && $bannerPost['status']==='publish') {
                    $bannerVideo=parseVideo($bannerPost['videoUrl']);
                    $postImage=(function_exists('avsfFirstPostImage') ? avsfFirstPostImage($bannerPost, true) : (trim($bannerPost['posterUrl'])?:trim($bannerPost['cover']))) ?: $bannerVideo['thumb'];
                    if ($postImage!=='') $r['image']=$postImage;
                    $r['code']=trim($bannerPost['code']);
                    $r['title']=$bannerPost['title'];
                    $r['text']=mb_substr(stripHtml($bannerPost['content']),0,170,'UTF-8');
                }
            }
        }
    }
    $activeBanners[] = $r;
}
$siteDisplayName=avsfBrandName();
$seoSiteName=avsfSeoSiteName($siteDisplayName);
$metaHomeTitle=trim(setting('seo_meta_home_title',''));
if($metaHomeTitle==='')$metaHomeTitle=$seoSiteName.(trim(setting('siteDesc',''))!==''?' - '.trim(setting('siteDesc')):'');
$metaHomeDescription=trim(setting('seo_meta_home_desc',''));
if($metaHomeDescription==='')$metaHomeDescription=setting('siteDesc');
$publishedPostCountResult=mysqli_query($conn,"SELECT COUNT(*) AS total FROM posts WHERE status='publish'");
if(!$publishedPostCountResult)throw new RuntimeException('Could not count published posts for SEO templates: '.mysqli_error($conn));
$publishedPostCount=(int)mysqli_fetch_assoc($publishedPostCountResult)['total'];
$homeSeoVariables=['site_name'=>$seoSiteName,'site_desc'=>setting('siteDesc'),'primary_keyword'=>setting('seo_primary_keyword',''),'post_count'=>$publishedPostCount,'item_count'=>$publishedPostCount];
$metaTitle=renderSeoTemplate($metaHomeTitle,$homeSeoVariables);
$metaDesc=renderSeoTemplate($metaHomeDescription,$homeSeoVariables);
$metaKeywords = implode(', ', array_filter([
    setting('seo_primary_keyword', ''),
    setting('seo_secondary_keywords', ''),
    setting('seo_keywords', '')
]));
$canonicalBase = rtrim(avsfCanonicalBaseUrl(), '/');
$requestOrigin = avsfCanonicalOrigin();
$requestScheme = parse_url($requestOrigin, PHP_URL_SCHEME) ?: 'https';
$requestHost = parse_url($requestOrigin, PHP_URL_HOST) ?: 'localhost';
$requestPort = parse_url($requestOrigin, PHP_URL_PORT);
if ($requestPort !== null) $requestHost .= ':' . (int)$requestPort;
$canonicalUrl = $canonicalBase . '/';
$robotsContent=trim(setting('seo_robots','index, follow'))?:'index, follow';
$videoSchema=null;
$pageSchema=null;
$breadcrumbItems=[['name'=>$seoSiteName,'url'=>$canonicalBase.'/']];
$websiteSchema=[
    '@context'=>'https://schema.org',
    '@type'=>'WebSite',
    'name'=>$seoSiteName,
    'url'=>$canonicalBase.'/',
    'inLanguage'=>'th',
    'potentialAction'=>['@type'=>'SearchAction','target'=>['@type'=>'EntryPoint','urlTemplate'=>$canonicalBase.'/?s={search_term_string}'],'query-input'=>'required name=search_term_string']
];
$extractCategories=function($value) {
    return splitTaxonomyNames($value);
};
$categoriesForPost=function($post) use ($extractCategories) {
    return $extractCategories(isset($post['category'])?$post['category']:'');
};
$loadCategoryIndex=function($sourcePosts=null) use ($categoriesForPost,$conn) {
    $counts=[];
    foreach(is_array($sourcePosts)?$sourcePosts:publishedPosts() as $post) {
        foreach($categoriesForPost($post) as $category) {
            if(!isset($counts[$category]))$counts[$category]=0;
            $counts[$category]++;
        }
    }
    $index=[];$result=mysqli_query($conn,"SELECT * FROM categories ORDER BY name");
    while($result&&($category=mysqli_fetch_assoc($result)))$index[$category['slug']]=['id'=>(int)$category['id'],'name'=>$category['name'],'count'=>(int)($counts[$category['name']]??0),'metaTitle'=>$category['metaTitle'],'metaDesc'=>$category['metaDesc']];
    return $index;
};
$renderPagination=function($route,$currentPage,$totalPages,$extra=[]) {
    if($totalPages<=1)return '';
    $routeSlug=isset($extra['slug'])?$extra['slug']:'';
    unset($extra['slug']);
    $start=max(1,$currentPage-2);$end=min($totalPages,$currentPage+2);
    $html='<nav class="avsf-archive-pagination" aria-label="'.esc(__('หน้ารายการ')).'">';
    if($currentPage>1) {
        $html.='<a href="'.esc(siteUrl($route,$routeSlug,array_merge(['pg'=>$currentPage-1],$extra))).'" rel="prev">'.__('← ก่อนหน้า').'</a>';
    }
    for($pageNumber=$start;$pageNumber<=$end;$pageNumber++) {
        if($pageNumber===$currentPage)$html.='<span aria-current="page">'.$pageNumber.'</span>';
        else {
            $html.='<a href="'.esc(siteUrl($route,$routeSlug,array_merge(['pg'=>$pageNumber],$extra))).'">'.$pageNumber.'</a>';
        }
    }
    if($currentPage<$totalPages) {
        $html.='<a href="'.esc(siteUrl($route,$routeSlug,array_merge(['pg'=>$currentPage+1],$extra))).'" rel="next">'.__('ถัดไป →').'</a>';
    }
    return $html.'</nav>';
};

// ---- Route handling ----
$contentHtml = '';

// ====== Modular page dispatch (แยกหน้าเป็นไฟล์ของตัวเอง — หน้าไหนเสียจะได้เสียแค่จุดนั้น) ======
require_once AVSF_FRONTEND_DIR . '/runtime/template-loader.php';
require_once AVSF_CORE_DIR . '/api/vdohide-api.php';
require_once AVSF_FRONTEND_DIR.'/loop-templates/content-post-card.php';

$AVSFPageMap = [
    'post'       => 'loop-templates/single-video.php',
    'page'       => 'pages/page-static.php',
    'studio'     => 'taxonomy-templates/taxonomy-studio.php',
    'actor'      => 'taxonomy-templates/taxonomy-actor.php',
    'tag'        => 'taxonomy-templates/taxonomy-tag.php',
    'category'   => 'taxonomy-templates/taxonomy-category.php',
    'all'        => 'pages/page-all.php',
    'categories' => 'pages/page-categories.php',
    'search'     => 'pages/page-search.php',
    'taxlist'    => 'pages/page-taxlist.php',
    'home'       => 'pages/page-home.php',
    'not-found'  => 'pages/page-not-found.php',
];
$AVSFTemplateFile = isset($AVSFPageMap[$page]) ? $AVSFPageMap[$page] : 'pages/page-not-found.php';
$AVSFTemplatePath = AVSF_FRONTEND_DIR . '/' . $AVSFTemplateFile;
$contentHtml = '';
if (is_file($AVSFTemplatePath)) {
    try {
        ob_start();
        include $AVSFTemplatePath;
        $contentHtml = ob_get_clean();
    } catch (Throwable $AVSFErr) {
        while (ob_get_level()) ob_end_clean();
        error_log('[AVSF page] '.$AVSFTemplateFile.' :: '.$AVSFErr->getMessage().' @ '.$AVSFErr->getFile().':'.$AVSFErr->getLine());
        if (!headers_sent()) http_response_code(500);
        $contentHtml = '<div class="avsf-card" style="text-align:center;padding:40px;margin-top:20px;"><h2 style="margin-bottom:10px;">'.__('หน้านี้มีข้อผิดพลาดชั่วคราว').'</h2><p style="color:var(--muted);margin-bottom:14px;">'.__('ส่วนอื่นๆ ของเว็บยังทำงานปกติ — ขออภัยในความไม่สะดวก').'</p><a class="avsf-btn avsf-btn-ghost" href="'.esc(siteUrl('home')).'">'.__('กลับหน้าหลัก').'</a></div>';
    }
} else {
    error_log('[AVSF page] missing template file: '.$AVSFTemplateFile);
    $contentHtml = avsf_render_template('pages/page-not-found.php');
}
// Handle comment submit
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cmtText'])) {
    $postId = intval($_POST['postId']);
    $name = trim($_POST['cmtName']) ?: __('ผู้เยี่ยมชม');
    $text = trim($_POST['cmtText']);
    if ($text) {
        $nameE = mysqli_real_escape_string($conn, $name);
        $textE = mysqli_real_escape_string($conn, $text);
        // === CSRF + Rate Limit protection (Security Kit) ===
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403); die(__('CSRF token ไม่ถูกต้อง'));
}
sec_rate_limit('comment', 3);
// === end protection ===
mysqli_query($conn, "INSERT INTO comments (postId,name,text,date,approved) VALUES ($postId,'$nameE','$textE',CURDATE(),0)");
        logActivity('มีคอมเมนต์ใหม่จาก '.$name);
        echo '<script>alert('.avsf_js(__('ส่งคอมเมนต์แล้ว รอการอนุมัติ')).');</script>';
    }
}
if($page==='search'||$page==='not-found'||http_response_code()===404) {
    $robotsContent='noindex, follow';
} else {
    $robotsType='home';$robotsId=0;$emptyTaxonomyPage=false;
    if($page==='post'&&isset($p['id'])){$robotsType='post';$robotsId=(int)$p['id'];}
    elseif($page==='page'&&isset($pg['id'])){$robotsType='page';$robotsId=(int)$pg['id'];}
    elseif(in_array($page,['studio','actor','tag'],true)&&isset($item['id'])){$robotsType=$page;$robotsId=(int)$item['id'];$emptyTaxonomyPage=isset($totalPosts)&&(int)$totalPosts===0;}
    elseif($page==='category'&&isset($category['id'])){$robotsType='category';$robotsId=(int)$category['id'];$emptyTaxonomyPage=isset($totalPosts)&&(int)$totalPosts===0;}
    elseif($page==='all')$robotsType='all';
    elseif($page==='categories')$robotsType='category_list';
    elseif($page==='taxlist'&&in_array($slug,['studio','actor','tag'],true))$robotsType=$slug.'_list';
    $robotsContent=(!$emptyTaxonomyPage&&seoIndexable($robotsType,$robotsId))?'index, follow':'noindex, follow';
}
$breadcrumbSchema=['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[]];
foreach($breadcrumbItems as $index=>$breadcrumbItem) {
    $breadcrumbSchema['itemListElement'][]=['@type'=>'ListItem','position'=>$index+1,'name'=>$breadcrumbItem['name'],'item'=>$breadcrumbItem['url']];
}
?>
<?php include AVSF_FRONTEND_DIR.'/partials/head.php'; ?>
<?php include AVSF_FRONTEND_DIR.'/partials/header.php'; ?>
<?php echo $contentHtml; ?>
<?php include AVSF_FRONTEND_DIR.'/partials/footer.php'; ?>
