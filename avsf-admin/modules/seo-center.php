<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();

requireSiteCenterAccess();
requirePermission('seo.manage');
ensureSeoIndexOverridesTable($conn);
ensureServerErrorLogTable($conn);
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, private', true);

$types=[
    'home'=>['label'=>__('หน้าแรก'),'setting'=>'seo_index_home','table'=>''],
    'all'=>['label'=>__('หน้ารวมโพสต์'),'setting'=>'seo_index_all','table'=>''],
    'category_list'=>['label'=>__('หน้ารวมหมวดหมู่'),'setting'=>'seo_index_category_list','table'=>''],
    'studio_list'=>['label'=>__('หน้ารวมค่าย'),'setting'=>'seo_index_studio_list','table'=>''],
    'actor_list'=>['label'=>__('หน้ารวมนักแสดง'),'setting'=>'seo_index_actor_list','table'=>''],
    'tag_list'=>['label'=>__('หน้ารวมแท็ก'),'setting'=>'seo_index_tag_list','table'=>''],
    'post'=>['label'=>__('โพสต์'),'setting'=>'seo_index_posts','table'=>'posts'],
    'page'=>['label'=>__('หน้าเว็บ'),'setting'=>'seo_index_pages','table'=>'pages'],
    'category'=>['label'=>__('หมวดหมู่'),'setting'=>'seo_index_categories','table'=>'categories'],
    'studio'=>['label'=>__('ค่าย'),'setting'=>'seo_index_studios','table'=>'studios'],
    'actor'=>['label'=>__('นักแสดง'),'setting'=>'seo_index_actors','table'=>'actors'],
    'tag'=>['label'=>__('แท็ก'),'setting'=>'seo_index_tags','table'=>'tags']
];
$limits=[
    'post'=>['label'=>__('โพสต์'),'setting'=>'seo_sitemap_max_posts'],
    'page'=>['label'=>__('หน้าเว็บ'),'setting'=>'seo_sitemap_max_pages'],
    'category'=>['label'=>__('หมวดหมู่'),'setting'=>'seo_sitemap_max_categories'],
    'studio'=>['label'=>__('ค่าย'),'setting'=>'seo_sitemap_max_studios'],
    'actor'=>['label'=>__('นักแสดง'),'setting'=>'seo_sitemap_max_actors'],
    'tag'=>['label'=>__('แท็ก'),'setting'=>'seo_sitemap_max_tags']
];
$notice='';
$noticeType='success';
$csrf=esc($_SESSION['csrf_token']??'');
$view=isset($_GET['view'])&&is_string($_GET['view'])?$_GET['view']:'dashboard';
$validViews=['dashboard','indexing','urls','sitemap','redirects','errors'];
if(!in_array($view,$validViews,true))$view='dashboard';

if($_SERVER['REQUEST_METHOD']==='POST') {
    sec_csrf_verify();
    $action=isset($_POST['action'])&&is_string($_POST['action'])?$_POST['action']:'';
    if($action==='save_post_seo_templates') {
        $metaTitleTemplate=trim(isset($_POST['seo_post_meta_title_template'])?(string)$_POST['seo_post_meta_title_template']:'');
        $metaDescTemplate=trim(isset($_POST['seo_post_meta_desc_template'])?(string)$_POST['seo_post_meta_desc_template']:'');
        if(mb_strlen($metaTitleTemplate,'UTF-8')>200||mb_strlen($metaDescTemplate,'UTF-8')>300) {
            $notice=__('แม่แบบ SEO ยาวเกินกำหนด กรุณาลดความยาวแล้วลองใหม่');$noticeType='error';
        } elseif(saveSetting('seo_post_meta_title_template',$metaTitleTemplate)&&saveSetting('seo_post_meta_desc_template',$metaDescTemplate)) {
            logActivity('บันทึกแม่แบบ SEO โพสต์โดย '.($_SESSION['avsf_admin_username']??'admin'));
            $notice=__('บันทึกแม่แบบ SEO โพสต์แล้ว');
        } else {
            $notice=__('บันทึกแม่แบบ SEO ไม่สำเร็จ');$noticeType='error';
        }
        $view='dashboard';
    } elseif($action==='save_index_defaults') {
        $postedDefaults=isset($_POST['defaults'])&&is_array($_POST['defaults'])?$_POST['defaults']:[];
        $validated=[];
        foreach($types as $key=>$type) {
            $value=isset($postedDefaults[$key])&&is_string($postedDefaults[$key])?$postedDefaults[$key]:'';
            if(!in_array($value,['index','noindex'],true)) {
                $notice=__('ค่าดัชนีไม่ถูกต้อง กรุณาตรวจสอบและลองใหม่');$noticeType='error';break;
            }
            $validated[$type['setting']]=$value;
        }
        if($noticeType!=='error') {
            if(!mysqli_begin_transaction($conn)) {
                error_log('AVSF could not start Index defaults transaction: '.mysqli_error($conn));
                $notice=__('เริ่มบันทึกนโยบาย Index ไม่สำเร็จ');$noticeType='error';
            } else {
                $oldValues=[];
                foreach($validated as $key=>$value)$oldValues[$key]=setting($key,'');
                foreach($validated as $key=>$value) {
                    if(!saveSetting($key,$value)) {
                        $notice=__('บันทึกค่า ').$key.__(' ไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');$noticeType='error';break;
                    }
                }
                if($noticeType==='error')mysqli_rollback($conn);
                elseif(!mysqli_commit($conn)) {
                    error_log('AVSF Index defaults commit failed: '.mysqli_error($conn));
                    mysqli_rollback($conn);
                    $notice=__('บันทึกนโยบาย Index ไม่สำเร็จ');$noticeType='error';
                } else {
                    foreach($validated as $key=>$value)if($oldValues[$key]!==$value)logActivity('SEO setting '.$key.': '.($oldValues[$key]!==''?$oldValues[$key]:'default').' → '.$value.' by '.($_SESSION['avsf_admin_username']??'admin'));
                    $notice=__('บันทึกนโยบาย Index แล้ว');
                }
            }
        }
        $view='indexing';
    } elseif($action==='save_sitemap_settings') {
        $postedLimits=isset($_POST['limits'])&&is_array($_POST['limits'])?$_POST['limits']:[];
        $validated=[];
        foreach($limits as $key=>$limit) {
            $value=isset($postedLimits[$key])&&is_string($postedLimits[$key])?trim($postedLimits[$key]):'';
            if($value===''||!ctype_digit($value)||(int)$value<0||(int)$value>50000) {
                $notice=__('จำนวน URL ใน Sitemap ต้องอยู่ระหว่าง 0 ถึง 50,000');$noticeType='error';break;
            }
            $validated[$limit['setting']]=(string)(int)$value;
        }
        $robotsRules=isset($_POST['robots_rules'])&&is_string($_POST['robots_rules'])?$_POST['robots_rules']:'';
        $robotsRules=str_replace("\0",'',preg_replace("/\r\n?/", "\n", $robotsRules));
        if(strlen($robotsRules)>12000) {
            $notice=__('กฎ robots.txt ยาวเกิน 12,000 ไบต์ กรุณาลดข้อความก่อนบันทึก');$noticeType='error';
        }
        $validated['seo_robots_txt']=$robotsRules;
        if($noticeType!=='error') {
            if(!mysqli_begin_transaction($conn)) {
                error_log('AVSF could not start Sitemap settings transaction: '.mysqli_error($conn));
                $notice=__('เริ่มบันทึกการตั้งค่า Sitemap ไม่สำเร็จ');$noticeType='error';
            } else {
                $oldValues=[];
                foreach($validated as $key=>$value)$oldValues[$key]=setting($key,'');
                foreach($validated as $key=>$value) {
                    if(!saveSetting($key,$value)) {
                        $notice=__('บันทึกค่า ').$key.__(' ไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');$noticeType='error';break;
                    }
                }
                if($noticeType==='error')mysqli_rollback($conn);
                elseif(!mysqli_commit($conn)) {
                    error_log('AVSF Sitemap settings commit failed: '.mysqli_error($conn));
                    mysqli_rollback($conn);
                    $notice=__('บันทึกการตั้งค่า Sitemap ไม่สำเร็จ');$noticeType='error';
                } else {
                    foreach($validated as $key=>$value)if($oldValues[$key]!==$value)logActivity('SEO setting '.$key.' updated by '.($_SESSION['avsf_admin_username']??'admin'));
                    $notice=__('บันทึกการตั้งค่า Sitemap และ Crawlability แล้ว');
                }
            }
        }
        $view='sitemap';
    } elseif($action==='save_index_override') {
        $entityType=isset($_POST['entity_type'])&&is_string($_POST['entity_type'])?$_POST['entity_type']:'';
        $entityId=isset($_POST['entity_id'])?(int)$_POST['entity_id']:0;
        $indexable=isset($_POST['indexable'])&&$_POST['indexable']==='1';
        if(!isset($types[$entityType]['table'])||$types[$entityType]['table']===''||$entityId<1) {
            $notice=__('รายการ URL ที่เลือกไม่ถูกต้อง');$noticeType='error';
        } else {
            try {
                $previous=seoIndexable($entityType,$entityId,$conn);
                saveSeoIndexOverride($entityType,$entityId,$indexable,$conn);
                logActivity('SEO override '.$entityType.' #'.$entityId.': '.($previous?'Index':'Noindex').' → '.($indexable?'Index':'Noindex').' by '.($_SESSION['avsf_admin_username']??'admin'));
                $notice=__('ปรับสถานะการจัดทำดัชนีแล้ว');
            } catch(Throwable $error) {
                error_log('AVSF index override action failed: '.$error->getMessage());
                $notice=__('บันทึกสถานะไม่สำเร็จ กรุณาลองใหม่');$noticeType='error';
            }
        }
        if(isset($_POST['ajax'])&&$_POST['ajax']==='1'){
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>$noticeType!=='error','indexable'=>$indexable,'notice'=>$notice]);
            exit;
        }
        $view='indexing';
    }
}

$statusCounts=['index'=>0,'noindex'=>0,'total'=>0];
$inventoryCounts=[];
$countQueries=[
    'post'=>"SELECT COUNT(*) FROM posts WHERE status='publish'",
    'page'=>"SELECT COUNT(*) FROM pages WHERE status='publish'",
    'category'=>"SELECT COUNT(*) FROM categories",
    'studio'=>"SELECT COUNT(*) FROM studios",
    'actor'=>"SELECT COUNT(*) FROM actors",
    'tag'=>"SELECT COUNT(*) FROM tags"
];
foreach($countQueries as $type=>$sql) {
    $result=mysqli_query($conn,$sql);
    if(!$result) {
        error_log('AVSF SEO center count failed: '.mysqli_error($conn));
        http_response_code(500);
        exit(__('ไม่สามารถโหลดสรุปข้อมูล SEO ได้'));
    }
    $inventoryCounts[$type]=(int)mysqli_fetch_row($result)[0];
    mysqli_free_result($result);
}
$staticTypes=['home','all','category_list','studio_list','actor_list','tag_list'];
foreach($staticTypes as $type) {
    $statusCounts['total']++;
    if(seoIndexable($type))$statusCounts['index']++;else $statusCounts['noindex']++;
}
$entityTables=['post'=>'posts','page'=>'pages','category'=>'categories','studio'=>'studios','actor'=>'actors','tag'=>'tags'];
foreach($entityTables as $type=>$table) {
    $statusColumn=in_array($type,['post','page'],true)?" WHERE entity.status='publish'":'';
    $default=seoIndexDefault($type)?1:0;
    $sql="SELECT COALESCE(override.indexable,$default) AS indexable,COUNT(*) AS total FROM `$table` entity LEFT JOIN seo_index_overrides override ON override.entity_type='".mysqli_real_escape_string($conn,$type)."' AND override.entity_id=entity.id".$statusColumn." GROUP BY indexable";
    $result=mysqli_query($conn,$sql);
    if(!$result) {
        error_log('AVSF SEO center indexability count failed: '.mysqli_error($conn));
        http_response_code(500);
        exit(__('ไม่สามารถโหลดสถานะ Index ได้'));
    }
    while($row=mysqli_fetch_assoc($result)) {
        $count=(int)$row['total'];
        $statusCounts[(int)$row['indexable']?'index':'noindex']+=$count;
        $statusCounts['total']+=$count;
    }
    mysqli_free_result($result);
}
$redirectResult=mysqli_query($conn,"SELECT COUNT(*) AS total,COALESCE(SUM(hits),0) AS hits FROM redirects WHERE active=1 AND code=301");
if(!$redirectResult){error_log('AVSF redirect summary failed: '.mysqli_error($conn));http_response_code(500);exit(__('ไม่สามารถโหลดสรุป Redirect ได้'));}
$redirectStats=mysqli_fetch_assoc($redirectResult);
$notFoundResult=mysqli_query($conn,"SELECT COUNT(*) AS total,COALESCE(SUM(hits),0) AS hits FROM `404_log`");
if(!$notFoundResult){error_log('AVSF 404 summary failed: '.mysqli_error($conn));http_response_code(500);exit(__('ไม่สามารถโหลดสรุป 404 ได้'));}
$notFoundStats=mysqli_fetch_assoc($notFoundResult);
$goneResult=mysqli_query($conn,"SELECT COUNT(*) AS total FROM redirects WHERE active=1 AND code=410");
if(!$goneResult){error_log('AVSF 410 summary failed: '.mysqli_error($conn));http_response_code(500);exit(__('ไม่สามารถโหลดสรุป 410 ได้'));}
$goneCount=(int)mysqli_fetch_row($goneResult)[0];
$serverErrorResult=mysqli_query($conn,"SELECT COALESCE(SUM(hits),0) FROM server_error_log");
if(!$serverErrorResult){error_log('AVSF 500 summary failed: '.mysqli_error($conn));http_response_code(500);exit(__('ไม่สามารถโหลดสรุป 500 ได้'));}
$serverErrors=(int)mysqli_fetch_row($serverErrorResult)[0];
$serverErrorListResult=mysqli_query($conn,"SELECT COUNT(*) AS entries,COALESCE(SUM(hits),0) AS hits FROM server_error_log WHERE error_type LIKE 'HTTP_5%' OR error_type LIKE 'PHP_FATAL_%'");
if(!$serverErrorListResult){error_log('AVSF 5xx summary failed: '.mysqli_error($conn));http_response_code(500);exit(__('ไม่สามารถโหลดสรุป 5xx ได้'));}
$serverErrorStats=mysqli_fetch_assoc($serverErrorListResult);
$serverErrorCount=(int)$serverErrorStats['hits'];
$urlCount=max(1,$statusCounts['total']+(int)$redirectStats['total']+(int)$notFoundStats['total']+$goneCount);
$indexPercent=round($statusCounts['index']/$urlCount*100,1);
$noindexPercent=round($statusCounts['noindex']/$urlCount*100,1);
$redirectPercent=round((int)$redirectStats['total']/$urlCount*100,1);
$diskPath=realpath(AVSF_ROOT);
$diskTotal=$diskPath&&function_exists('disk_total_space')?@disk_total_space($diskPath):false;
$diskFree=$diskPath&&function_exists('disk_free_space')?@disk_free_space($diskPath):false;
$diskPercent=is_numeric($diskTotal)&&$diskTotal>0&&is_numeric($diskFree)?round((1-$diskFree/$diskTotal)*100):null;
$memoryLimit=ini_get('memory_limit');
$memoryBytes=preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?$/i',trim((string)$memoryLimit),$memoryMatch)?(float)$memoryMatch[1]:0;
if(isset($memoryMatch[2]))$memoryBytes*=['K'=>1024,'M'=>1048576,'G'=>1073741824][strtoupper($memoryMatch[2])];
$phpMemoryPercent=$memoryBytes>0?min(100,round(memory_get_peak_usage(true)/$memoryBytes*100)):null;
$databaseBytes=0;
$databaseNameResult=mysqli_query($conn,'SELECT DATABASE()');
if(!$databaseNameResult){error_log('AVSF database name lookup failed: '.mysqli_error($conn));http_response_code(500);exit(__('ไม่สามารถโหลดข้อมูลฐานข้อมูลได้'));}
$databaseName=mysqli_fetch_row($databaseNameResult)[0]??'';
if($databaseName!=='') {
    $dbStmt=mysqli_prepare($conn,'SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?');
    if($dbStmt) {
        mysqli_stmt_bind_param($dbStmt,'s',$databaseName);
        if(mysqli_stmt_execute($dbStmt)) {
            mysqli_stmt_bind_result($dbStmt,$databaseBytes);
            mysqli_stmt_fetch($dbStmt);
        }
        mysqli_stmt_close($dbStmt);
    }
}
$formatBytes=function($bytes) {
    $bytes=max(0,(float)$bytes);
    $units=['B','KB','MB','GB','TB'];$index=0;
    while($bytes>=1024&&$index<count($units)-1){$bytes/=1024;$index++;}
    return number_format($bytes,$index===0?0:1).' '.$units[$index];
};
$memoryLabel=$memoryBytes>0?$formatBytes(memory_get_peak_usage(true)).' / '.$formatBytes($memoryBytes):$formatBytes(memory_get_peak_usage(true)).' peak';
$baseType=isset($_GET['type'])&&isset($types[$_GET['type']])?$_GET['type']:'all';
$indexFilter=isset($_GET['index'])&&in_array($_GET['index'],['index','noindex'],true)?$_GET['index']:'all';
$search=isset($_GET['q'])&&is_string($_GET['q'])?trim($_GET['q']):'';
$currentPage=max(1,(int)(isset($_GET['p'])?$_GET['p']:1));
$pageSize=25;
$rows=[];
$loadRows=function($type,$searchValue)use($conn,$types) {
    $table=$types[$type]['table'];
    if($table==='')return [];
    $typeColumn=$type==='category'||$type==='studio'||$type==='actor'||$type==='tag'?'name':'title';
    $where=in_array($type,['post','page'],true)?"entity.status='publish'":"1=1";
    $searchValue=trim($searchValue);
    if($searchValue!=='') {
        $searchEsc=mysqli_real_escape_string($conn,$searchValue);
        $where.=" AND (entity.`$typeColumn` LIKE '%$searchEsc%' OR entity.slug LIKE '%$searchEsc%')";
    }
    $default=seoIndexDefault($type)?1:0;
    $sql="SELECT entity.id,entity.`$typeColumn` AS title,entity.slug,COALESCE(override.indexable,$default) AS indexable FROM `$table` entity LEFT JOIN seo_index_overrides override ON override.entity_type='".mysqli_real_escape_string($conn,$type)."' AND override.entity_id=entity.id WHERE $where ORDER BY entity.id DESC LIMIT 1000";
    $result=mysqli_query($conn,$sql);
    if(!$result) {
        error_log('AVSF SEO center URL query failed: '.mysqli_error($conn));
        return [];
    }
    $items=[];
    while($row=mysqli_fetch_assoc($result)) {
        $row['entity_type']=$type;
        $row['url']=seoCenterPublicUrl($type,$row['slug']);
        $items[]=$row;
    }
    mysqli_free_result($result);
    return $items;
};
function seoCenterPublicUrl($type,$slug='') {
    $routeMap=['post'=>'post','page'=>'page','category'=>'category','studio'=>'studio','actor'=>'actor','tag'=>'tag'];
    return rtrim(avsfCanonicalBaseUrl(), '/') . sitePath($routeMap[$type]??'home',$slug);
}

$totalRows=0;
$totalPages=1;
$visibleRows=[];
// Sitemap settings do not need the URL override inventory; avoid coupling this
// page to the heavier Index listing query and keep both views independently loadable.
if($view!=='sitemap') {
    $filterTypes=$baseType==='all'?array_keys($entityTables):[$baseType];
    foreach($filterTypes as $type) {
        if(!isset($entityTables[$type]))continue;
        foreach($loadRows($type,$search) as $row) {
            if($indexFilter!=='all'&&(int)$row['indexable']!==($indexFilter==='index'?1:0))continue;
            $rows[]=$row;
        }
    }
    usort($rows,function($a,$b){return strcmp($b['entity_type'],$a['entity_type'])?:((int)$b['id']-(int)$a['id']);});
    $totalRows=count($rows);
    $totalPages=max(1,(int)ceil($totalRows/$pageSize));
    $currentPage=min($currentPage,$totalPages);
    $visibleRows=array_slice($rows,($currentPage-1)*$pageSize,$pageSize);
}
$allRedirects=mysqli_query($conn,"SELECT old_url,new_url,code,hits,created_at AS last_hit FROM redirects ORDER BY id DESC LIMIT 100");
$redirectListAvailable=$allRedirects!==false;
$redirectRows=[];
if($allRedirects)while($row=mysqli_fetch_assoc($allRedirects))$redirectRows[]=$row;
$notFoundRows=mysqli_query($conn,"SELECT path,hits,last_hit FROM `404_log` ORDER BY last_hit DESC LIMIT 100");
$errorRows=[];
if($notFoundRows)while($row=mysqli_fetch_assoc($notFoundRows))$errorRows[]=$row;
$serverErrorRows=[];
$errorList=mysqli_query($conn,'SELECT path,error_type,hits,last_hit FROM server_error_log ORDER BY last_hit DESC LIMIT 100');
if($errorList)while($row=mysqli_fetch_assoc($errorList))$serverErrorRows[]=$row;
$indexRows='';
foreach($visibleRows as $row) {
    $status=(int)$row['indexable']?'Index':'Noindex';
    $badge=(int)$row['indexable']?'avsf-index':'avsf-noindex';
    $indexRows.='<tr><td><div class="avsf-url-inventory-label"><strong>'.esc($types[$row['entity_type']]['label']).' · '.esc($row['title']).'</strong><a href="'.esc($row['url']).'" target="_blank" rel="noopener">'.esc(sitePath($row['entity_type'],$row['slug'])).'</a></div></td><td>'.esc($types[$row['entity_type']]['label']).'</td><td>'.esc($row['title']).'</td><td><span class="avsf-pill '.$badge.'" data-seo-badge>'.$status.'</span></td><td><span class="avsf-pill avsf-unknown">ยังไม่ Live scan</span></td><td>ยังไม่ตรวจ</td><td><form method="post" class="avsf-inline-form avsf-seo-toggle"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="save_index_override"><input type="hidden" name="ajax" value="1"><input type="hidden" name="view" value="'.esc($view).'"><input type="hidden" name="entity_type" value="'.esc($row['entity_type']).'"><input type="hidden" name="entity_id" value="'.(int)$row['id'].'"><input type="hidden" name="indexable" value="'.((int)$row['indexable']?'0':'1').'"><button class="avsf-btn avsf-small" type="submit" data-seo-toggle-btn>'.((int)$row['indexable']?'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="9" y1="9" x2="15" y2="15"/></svg> ':'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ').((int)$row['indexable']?__('ปิด Index'):__('เปิด Index')).'</button></form></td></tr>';
}
$defaultsHtml='';
foreach($types as $key=>$type) {
    $settingValue=setting($type['setting'],'');
    if(!in_array($settingValue,['index','noindex'],true))$settingValue=seoIndexDefault($key)?'index':'noindex';
    if(!in_array($settingValue,['index','noindex'],true))$settingValue='index';
    $defaultsHtml.='<label class="avsf-setting-row"><span><strong>'.esc($type['label']).'</strong><small>ค่าเริ่มต้นสำหรับรายการใหม่และรายการที่ยังไม่มีการกำหนดรายตัว</small></span><select name="defaults['.esc($key).']"><option value="index"'.($settingValue==='index'?' selected':'').'>Index, follow</option><option value="noindex"'.($settingValue==='noindex'?' selected':'').'>Noindex, follow</option></select></label>';
}
$limitsHtml='';
foreach($limits as $key=>$limit) {
    $limitValue=setting($limit['setting'],'20000');
    if(!ctype_digit((string)$limitValue)||(int)$limitValue>50000)$limitValue='20000';
    $limitsHtml.='<label class="avsf-setting-row"><span><strong>'.esc($limit['label']).'</strong><small>จำนวนสูงสุดที่ส่งเข้า sitemap; 0 คือไม่ส่งหมวดนี้เข้า sitemap</small></span><input type="number" min="0" max="50000" step="1" name="limits['.esc($key).']" value="'.esc($limitValue).'"></label>';
}
$content='';
$seoRoleLabels=permissionRoleDefinitions();
$seoRoleLabel=$seoRoleLabels[currentUserRole()]??__('ผู้ดูแลระบบ');
if($view==='dashboard') {
    $postMetaTitleTemplate=setting('seo_post_meta_title_template','');
    $postMetaDescTemplate=setting('seo_post_meta_desc_template','');
    $postMetaTitleTemplate=$postMetaTitleTemplate!==''?$postMetaTitleTemplate:'{title} | {site_name}';
    $postMetaDescTemplate=$postMetaDescTemplate!==''?$postMetaDescTemplate:'{code} - {title}';
    $seoTemplatePanel='<section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>แม่แบบ SEO ของโพสต์</h2><p>ตัวแปรของโพสต์เท่านั้น: {code}, {title}, {site_name}</p></div></div><form method="post" id="avsf-post-seo-template-form"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="save_post_seo_templates"><label class="avsf-setting-row"><span><strong>Meta Title เริ่มต้น</strong><small>ใช้เมื่อช่อง Meta Title ของโพสต์ยังว่าง · สูงสุด 200 ตัวอักษร</small></span><input id="avsfPostSeoTitle" type="text" name="seo_post_meta_title_template" value="'.esc($postMetaTitleTemplate).'" maxlength="200"></label><div class="avsf-token-picker"><span>แทรกตัวแปรในช่องที่เลือก:</span><button type="button" class="avsf-token-chip" data-post-seo-token="{code}">{code}</button><button type="button" class="avsf-token-chip" data-post-seo-token="{title}">{title}</button><button type="button" class="avsf-token-chip" data-post-seo-token="{site_name}">{site_name}</button></div><small class="avsf-char-count" id="avsfPostSeoTitleCount"></small><label class="avsf-setting-row"><span><strong>Meta Description เริ่มต้น</strong><small>ใช้เมื่อช่อง Meta Description ของโพสต์ยังว่าง · สูงสุด 300 ตัวอักษร</small></span><textarea id="avsfPostSeoDesc" name="seo_post_meta_desc_template" rows="3" maxlength="300">'.esc($postMetaDescTemplate).'</textarea></label><div class="avsf-token-picker"><span>ตัวแปรที่ใช้ได้:</span><button type="button" class="avsf-token-chip" data-post-seo-token="{code}">{code}</button><button type="button" class="avsf-token-chip" data-post-seo-token="{title}">{title}</button><button type="button" class="avsf-token-chip" data-post-seo-token="{site_name}">{site_name}</button></div><small class="avsf-char-count" id="avsfPostSeoDescCount"></small><div class="avsf-google-preview" style="margin-top:12px"><div class="avsf-google-preview-label">ตัวอย่างผลการค้นหา · โพสต์</div><div class="avsf-google-preview-title" id="avsfPostSeoPreviewTitle"></div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/miab-679/').'</div><div class="avsf-google-preview-desc" id="avsfPostSeoPreviewDesc"></div><div class="avsf-google-preview-status" id="avsfPostSeoPreviewStatus">ตัวอย่างจาก Code MIAB-679 และชื่อเรื่องสมมติ · Google อาจเปลี่ยนข้อความและความยาวตามอุปกรณ์และคำค้นหา</div></div><button class="avsf-btn" type="submit" style="margin-top:12px"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> บันทึกแม่แบบ SEO โพสต์</button></form></section>';
    $content='<div class="avsf-page-heading"><div><p class="avsf-eyebrow">SEO &amp; Robots Manager</p><h1>ศูนย์ควบคุม SEO และ URL</h1><p>ภาพรวมจากเนื้อหาเว็บไซต์, กฎ redirect และ log ที่ระบบเก็บได้ · อัปเดต '.date('d/m/Y H:i:s').'</p></div><a class="avsf-btn" href="../sitemap.xml" target="_blank" rel="noopener">เปิด Sitemap</a></div>'.$seoTemplatePanel.
    '<section class="avsf-kpi-grid"><article class="avsf-kpi"><span>URL ที่รู้จักในเว็บไซต์</span><strong>'.number_format($statusCounts['total']).'</strong><small>ไม่รวม URL ภายนอกหรือ route ที่ยังไม่เคยบันทึก</small></article><article class="avsf-kpi avsf-green"><span>Index</span><strong>'.number_format($statusCounts['index']).'</strong><small>'.number_format($statusCounts['total']?$statusCounts['index']/$statusCounts['total']*100:0,1).'% ของ URL ที่ตั้งค่าได้</small></article><article class="avsf-kpi avsf-orange"><span>Noindex</span><strong>'.number_format($statusCounts['noindex']).'</strong><small>ใช้ meta robots; ไม่ block robots.txt</small></article><article class="avsf-kpi avsf-blue"><span>301 ที่เปิดใช้</span><strong>'.number_format((int)$redirectStats['total']).'</strong><small>'.number_format((int)$redirectStats['hits']).' hits ที่เคยบันทึก</small></article><article class="avsf-kpi avsf-red"><span>404 ใน log</span><strong>'.number_format((int)$notFoundStats['hits']).'</strong><small>'.number_format((int)$notFoundStats['total']).' path · มีเฉพาะ route ที่ PHP จับได้</small></article><article class="avsf-kpi avsf-orange"><span>410 ที่ตั้งไว้</span><strong>'.number_format($goneCount).'</strong><small>กฎ Gone ใน redirect manager</small></article><article class="avsf-kpi avsf-red"><span>5xx ใน application log</span><strong>'.number_format($serverErrorCount).'</strong><small>PHP fatal/HTTP 5xx ที่ application จับได้เท่านั้น</small></article><article class="avsf-kpi avsf-blue"><span>HTTP live scan</span><strong>ยังไม่มีข้อมูล</strong><small>หน้านี้ไม่ได้ส่ง request ยิงตรวจทุก URL</small></article></section>'.
    '<section class="avsf-overview-grid"><article class="avsf-panel"><div class="avsf-panel-heading"><div><h2>สรุปการจัดทำดัชนี</h2><p>เฉพาะ URL ของเนื้อหาในฐานข้อมูลและหน้ารวม</p></div></div><div class="avsf-chart-row"><div class="avsf-donut" style="--index:'.($statusCounts['total']?round($statusCounts['index']/$statusCounts['total']*100,1):0).'"><span>'.number_format($statusCounts['total']).'<small>URL</small></span></div><div class="avsf-legend"><div><i class="avsf-green-dot"></i><b>Index</b><strong>'.number_format($statusCounts['index']).'</strong><small>'.number_format($statusCounts['total']?$statusCounts['index']/$statusCounts['total']*100:0,1).'%</small></div><div><i class="avsf-orange-dot"></i><b>Noindex</b><strong>'.number_format($statusCounts['noindex']).'</strong><small>'.number_format($statusCounts['total']?$statusCounts['noindex']/$statusCounts['total']*100:0,1).'%</small></div><div><i class="avsf-blue-dot"></i><b>301 redirect</b><strong>'.number_format((int)$redirectStats['total']).'</strong><small>'.number_format($redirectPercent,1).'%</small></div></div></div><p class="avsf-safe-note">Noindex ใช้ meta robots และไม่ใส่ Disallow ใน robots.txt เพื่อให้ Googlebot อ่านคำสั่ง noindex ได้</p></article>'.
    '<article class="avsf-panel"><div class="avsf-panel-heading"><div><h2>สถานะเซิร์ฟเวอร์และทรัพยากร</h2><p>ค่าที่ PHP ตรวจวัดได้จากเครื่องแม่ข่ายนี้</p></div><span class="avsf-health">● PHP ทำงาน</span></div>'.
    '<div class="avsf-resource-row"><span class="avsf-resource-icon">◉</span><div class="avsf-resource-main"><div><strong>CPU</strong><small>ไม่รองรับการวัด CPU ทั้งเครื่องจาก PHP runtime นี้</small></div><span>—</span></div></div>'.
    seoCenterMeter(__('RAM ของ PHP worker'),$phpMemoryPercent,$memoryLabel).
    seoCenterMeter(__('พื้นที่ดิสก์ของเว็บไซต์'),$diskPercent,$diskPercent===null?__('ไม่สามารถอ่านพื้นที่ดิสก์'):number_format($diskPercent,1).__('% ใช้งาน')).
    '<div class="avsf-resource-row"><span class="avsf-resource-icon">▤</span><div class="avsf-resource-main"><div><strong>ฐานข้อมูล</strong><small>'.$formatBytes($databaseBytes).' · ขนาดรวม data + index</small></div></div></div>'.
    '<div class="avsf-resource-row"><span class="avsf-resource-icon">▣</span><div class="avsf-resource-main"><div><strong>uploads</strong><small>ยังไม่คำนวณ · ไม่สแกนทุกไฟล์ระหว่างโหลดแดชบอร์ด</small></div></div></div>'.
    '<div class="avsf-safe-note">CPU ทั้งเครื่องไม่สามารถวัดได้อย่างเชื่อถือได้จาก PHP shared hosting จึงไม่แสดงเปอร์เซ็นต์จำลอง</div></article></section>'.
    '<section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>สถานะ HTTP ที่มีหลักฐานจากระบบ</h2><p>แยกจาก Index/Noindex; ยังไม่มีการ live scan ของ URL</p></div></div><div class="avsf-health-grid"><div><strong>2xx</strong><span>ยังไม่ตรวจ</span></div><div><strong>3xx</strong><span>'.number_format((int)$redirectStats['total']).' กฎ redirect</span></div><div><strong>404</strong><span>'.number_format((int)$notFoundStats['hits']).' hits ที่บันทึก</span></div><div><strong>410</strong><span>'.number_format($goneCount).' กฎ Gone</span></div><div><strong>5xx</strong><span>'.number_format($serverErrorCount).' application log</span></div></div><p class="avsf-safe-note">กฎ 301/410 ไม่ใช่การตรวจ response live; 5xx ไม่รวม error log ของ Apache ที่ AVSF อ่านไม่ได้</p></section>'.
    '<section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>รายการ URL ในเว็บไซต์</h2><p>สร้างจากเนื้อหาที่เผยแพร่และ route ที่รู้จัก · HTTP status ยังไม่ผ่าน live scan</p></div><a class="avsf-text-link" href="?view=urls">ดูรายการทั้งหมด →</a></div>'.seoCenterFilterForm($baseType,$indexFilter,$search,$types).'<div class="avsf-table-wrap"><table><thead><tr><th>URL</th><th>ประเภท</th><th>ชื่อเรื่อง</th><th>Indexing</th><th>HTTP</th><th>Canonical</th><th>การทำงาน</th></tr></thead><tbody>'.$indexRows.'</tbody></table></div></section>'.
    '<section class="avsf-quick-grid"><a href="?view=indexing"><strong>ตั้งค่า Index / Noindex</strong><small>ตั้งค่าแยกตามประเภท และ override ราย URL</small></a><a href="?view=sitemap"><strong>จัดการ Sitemap</strong><small>ตั้งขีดจำกัด URL และเปิด sitemap XML</small></a><a href="redirects.php"><strong>301 / 404 / 410</strong><small>ตรวจและจัดการ redirect กับ URL ที่หายไป</small></a><a href="?view=errors"><strong>รายงาน Error</strong><small>ตรวจ 404, 410 และ PHP fatal ที่ระบบบันทึก</small></a></section>';
} elseif($view==='indexing') {
    $content='<div class="avsf-page-heading"><div><p class="avsf-eyebrow">ควบคุมการค้นหา</p><h1>Index / Noindex</h1><p>กำหนดนโยบายการจัดทำดัชนีของหน้าแต่ละประเภท และปรับสถานะเป็นราย URL ได้ โดยไม่ปะปนกับการตั้งค่า Sitemap</p></div></div>'.
    '<form method="post"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="save_index_defaults"><section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>นโยบาย Index แยกตามชนิดเนื้อหา</h2><p>ค่าเริ่มต้นสำหรับรายการใหม่และรายการที่ยังไม่มีการกำหนดรายตัว</p></div></div>'.$defaultsHtml.'<button class="avsf-btn" type="submit">บันทึกนโยบาย Index</button></section></form>'.
    '<section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>สถานะ Index ราย URL</h2><p>ค้นหาโพสต์ หน้า หมวดหมู่ ค่าย นักแสดง หรือแท็ก แล้วเปิด/ปิด Index แยกเป็นรายรายการ</p></div></div>'.seoCenterFilterForm($baseType,$indexFilter,$search,$types).'<div class="avsf-table-wrap"><table><thead><tr><th>URL</th><th>ประเภท</th><th>ชื่อ</th><th>Indexing</th><th>HTTP</th><th>การทำงาน</th></tr></thead><tbody>'.$indexRows.'</tbody></table></div>'.seoCenterPagination($totalPages,$currentPage,$view).'<p class="avsf-safe-note">หมายเหตุ: สถานะ Index ในหน้านี้เป็นค่าที่ AVSF กำหนด ไม่ใช่การยืนยันว่า Google จัดทำดัชนี URL แล้ว</p></section>';
} elseif($view==='sitemap') {
    $robotsRulesValue=(string)setting('seo_robots_txt','');
    $publicBase=rtrim(avsfCanonicalBaseUrl(),'/');
    $sitemapUrl=$publicBase.'/sitemap_index.xml';
    $robotsUrl=$publicBase.'/robots.txt';
    $sitemapSummary='<div class="avsf-quick-grid"><a href="'.esc($sitemapUrl).'" target="_blank" rel="noopener noreferrer"><strong>Sitemap Index XML</strong><small>'.esc($sitemapUrl).'</small></a><a href="'.esc($robotsUrl).'" target="_blank" rel="noopener noreferrer"><strong>robots.txt</strong><small>'.esc($robotsUrl).'</small></a><a href="?view=indexing"><strong>Index / Noindex</strong><small>จัดการนโยบาย Index แยกตามประเภทและราย URL</small></a></div>';
    $sitemapCountsHtml='<div class="avsf-health-grid"><div><strong>'.number_format($inventoryCounts['post']??0).'</strong><span>โพสต์ที่เผยแพร่</span></div><div><strong>'.number_format($inventoryCounts['page']??0).'</strong><span>หน้าเว็บที่เผยแพร่</span></div><div><strong>'.number_format($inventoryCounts['category']??0).'</strong><span>หมวดหมู่</span></div><div><strong>'.number_format($inventoryCounts['studio']??0).'</strong><span>ค่าย</span></div><div><strong>'.number_format($inventoryCounts['actor']??0).'</strong><span>นักแสดง</span></div></div>';
    $content='<div class="avsf-page-heading"><div><p class="avsf-eyebrow">Sitemap &amp; Crawlability</p><h1>Sitemap &amp; Crawlability</h1><p>จัดการขีดจำกัด URL ที่ส่งออกใน Sitemap และกฎ robots.txt แยกจากการตั้งค่า Index / Noindex</p></div></div>'.
    '<section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>ทางเข้าของไฟล์สำหรับ Search Engine</h2><p>เปิดลิงก์เพื่อตรวจผลลัพธ์ที่ระบบสร้างจริง</p></div></div>'.$sitemapSummary.$sitemapCountsHtml.'<p class="avsf-safe-note">การนำ URL ออกจาก Sitemap ไม่ได้เปลี่ยนค่า Index/Noindex ของ URL นั้น และไม่รับประกันการลบ URL ออกจากผลการค้นหาทันที</p></section>'.
    '<form method="post"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="save_sitemap_settings"><section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>ขีดจำกัด URL ใน Sitemap</h2><p>กำหนดจำนวนสูงสุดแยกตามชนิด ค่า 0 คือไม่ส่งชนิดนั้นเข้า Sitemap</p></div></div>'.$limitsHtml.'<div class="avsf-safe-note">ค่าเหล่านี้มีผลกับไฟล์ Sitemap XML ที่ AVSF สร้างเท่านั้น ไม่ได้สั่งเปลี่ยน meta robots ของเนื้อหา</div></section><section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>กฎเพิ่มเติมของ robots.txt</h2><p>ระบบจะเพิ่มกฎนี้ต่อท้ายกฎพื้นฐานและ Sitemap URL อัตโนมัติ</p></div></div><label class="avsf-setting-row"><span><strong>Custom robots.txt rules</strong><small>ใส่เฉพาะบรรทัด Allow / Disallow หรือกลุ่ม User-agent เพิ่มเติม · สูงสุด 12,000 ไบต์</small></span><textarea name="robots_rules" rows="8" maxlength="12000" spellcheck="false" style="width:100%;max-width:100%;min-height:150px;font:12px/1.6 ui-monospace,Consolas,monospace">'.esc($robotsRulesValue).'</textarea></label><div class="avsf-safe-note">อย่าใส่คำสั่ง Disallow: / หากยังต้องการให้ Search Engine เข้าถึงเว็บไซต์ และตรวจ robots.txt หลังบันทึกทุกครั้ง</div><button class="avsf-btn" type="submit">บันทึก Sitemap &amp; Crawlability</button></section></form>';
} elseif($view==='urls') {
    $content='<div class="avsf-page-heading"><div><p class="avsf-eyebrow">URL inventory</p><h1>รายการ URL ทั้งหมด</h1><p>รายการจากโพสต์ หน้า และ taxonomy ที่เผยแพร่ในเว็บไซต์; HTTP ยังไม่ผ่าน live scan</p></div><a class="avsf-btn avsf-secondary" href="../sitemap.xml" target="_blank" rel="noopener">ดู XML Sitemap</a></div><section class="avsf-panel">'.seoCenterFilterForm($baseType,$indexFilter,$search,$types).'<div class="avsf-table-wrap"><table><thead><tr><th>URL</th><th>ประเภท</th><th>ชื่อเรื่อง</th><th>Indexing</th><th>HTTP</th><th>Canonical</th><th>การทำงาน</th></tr></thead><tbody>'.$indexRows.'</tbody></table></div>'.seoCenterPagination($totalPages,$currentPage,$view).'<p class="avsf-safe-note">แหล่งข้อมูล: ตารางเว็บไซต์ และกฎ Index ที่บันทึกไว้; ไม่ใช่การตรวจ URL ภายนอกหรือ HTTP live scan</p></section>';
} elseif($view==='redirects') {
    header('Location: redirects.php',true,302);exit;
} else {
    $redirectHtml='';
    foreach($redirectRows as $row)$redirectHtml.='<tr><td>'.esc($row['old_url']).'</td><td>'.esc($row['new_url']?:'—').'</td><td>'.(int)$row['code'].'</td><td>'.number_format((int)$row['hits']).'</td><td>'.esc($row['last_hit']??'').'</td></tr>';
    $notFoundHtml='';
    foreach($errorRows as $row)$notFoundHtml.='<tr><td>'.esc($row['path']).'</td><td>404</td><td>'.number_format((int)$row['hits']).'</td><td>'.esc($row['last_hit']).'</td></tr>';
    foreach($serverErrorRows as $row)$notFoundHtml.='<tr><td>'.esc($row['path']).'<small class="avsf-muted">'.esc($row['error_type']).'</small></td><td>500</td><td>'.number_format((int)$row['hits']).'</td><td>'.esc($row['last_hit']).'</td></tr>';
    $content='<div class="avsf-page-heading"><div><p class="avsf-eyebrow">การตอบสนอง URL</p><h1>รายงาน Redirect และข้อผิดพลาด</h1><p>รายการ 301/410 ที่ตั้งไว้ และ 404/PHP fatal ที่ระบบบันทึกได้</p></div><a class="avsf-btn" href="redirects.php">จัดการ Redirect</a></div><section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>301 / 410 Redirect rules</h2><p>301/410 เป็นค่าที่ตั้งไว้ในระบบ; response จริงจะเกิดเมื่อมีผู้เรียก URL</p></div></div><div class="avsf-table-wrap"><table><thead><tr><th>URL เดิม</th><th>URL ปลายทาง</th><th>สถานะ</th><th>Hits</th><th>สร้างเมื่อ</th></tr></thead><tbody>'.$redirectHtml.'</tbody></table></div></section><section class="avsf-panel"><div class="avsf-panel-heading"><div><h2>404 และ 5xx ที่ระบบบันทึก</h2><p>404 จาก application route; 5xx เป็น HTTP/PHP fatal ที่ shutdown handler จับได้ ไม่รวม Apache error log</p></div></div><div class="avsf-table-wrap"><table><thead><tr><th>Path</th><th>HTTP / Error</th><th>Hits</th><th>ล่าสุด</th></tr></thead><tbody>'.$notFoundHtml.'</tbody></table></div><a class="avsf-btn avsf-secondary" href="redirects.php?tab=404">เปิดหน้าแก้ 404 / สร้าง redirect</a></section>';
}
function seoCenterMeter($label,$percent,$description) {
    if($percent===null)return '<div class="avsf-resource-row"><span class="avsf-resource-icon">◌</span><div class="avsf-resource-main"><div><strong>'.esc($label).'</strong><small>'.esc($description).'</small></div><span>—</span></div></div>';
    return '<div class="avsf-resource-row"><span class="avsf-resource-icon">◌</span><div class="avsf-resource-main"><div><strong>'.esc($label).'</strong><small>'.esc($description).'</small></div><span>'.number_format($percent,1).'%</span><div class="avsf-meter"><i style="width:'.min(100,max(0,$percent)).'%"></i></div></div></div>';
}
function seoCenterFilterForm($type,$index,$search,$types) {
    $html='<form class="avsf-filters" method="get"><input type="hidden" name="view" value="'.esc($_GET['view']??'dashboard').'"><label class="avsf-sr-only" for="urlSearch">ค้นหา URL</label><input id="urlSearch" name="q" value="'.esc($search).'" placeholder="ค้นหา URL, slug หรือชื่อเรื่อง"><label class="avsf-sr-only" for="typeFilter">ประเภท</label><select id="typeFilter" name="type"><option value="all">ทุกประเภท</option>';
    foreach($types as $key=>$item)$html.='<option value="'.esc($key).'"'.($type===$key?' selected':'').'>'.esc($item['label']).'</option>';
    $html.='</select><label class="avsf-sr-only" for="indexFilter">สถานะ Index</label><select id="indexFilter" name="index"><option value="all"'.($index==='all'?' selected':'').'>ทุกสถานะ</option><option value="index"'.($index==='index'?' selected':'').'>Index</option><option value="noindex"'.($index==='noindex'?' selected':'').'>Noindex</option></select><button class="avsf-btn avsf-secondary" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg> ค้นหา / กรอง</button></form>';
    return $html;
}
function seoCenterPagination($totalPages,$currentPage,$view) {
    if($totalPages<=1)return '';
    $html='<nav class="avsf-pagination" aria-label="หน้ารายการ URL">';
    for($page=1;$page<=$totalPages;$page++) {
        if($page===$currentPage)$html.='<span aria-current="page">'.$page.'</span>';
        else $html.='<a href="?view='.rawurlencode($view).'&amp;type='.rawurlencode($_GET['type']??'all').'&amp;index='.rawurlencode($_GET['index']??'all').'&amp;q='.rawurlencode($_GET['q']??'').'&amp;p='.$page.'">'.$page.'</a>';
    }
    return $html.'</nav>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>SEO &amp; Robots Manager - AVSF</title>
<style>
:root{color-scheme:light;--bg:#f3f6fb;--panel:#fff;--text:#19305b;--muted:#7183a3;--line:#e4eaf3;--blue:#2783f5;--green:#17a975;--orange:#f5a623;--red:#ed5263;--purple:#8c62e9;--shadow:0 6px 24px rgba(31,60,106,.055)}
*{box-sizing:border-box}html,body{width:100%;max-width:none;min-width:0;margin:0!important;overflow-x:clip}body{min-height:100vh;background:var(--bg);color:var(--text);font:13px/1.5 "Segoe UI",Tahoma,sans-serif}.avsf-shell{min-height:100vh;display:grid;grid-template-columns:230px minmax(0,1fr)}.avsf-sidebar{position:sticky;top:0;height:100vh;overflow:auto;background:#fff;border-right:1px solid var(--line);padding:17px 11px}.avsf-brand{display:flex;align-items:center;gap:10px;padding:4px 8px 18px;font-weight:800;font-size:15px}.avsf-brand-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:11px;background:#e8f2ff;color:var(--blue);font-size:20px}.avsf-nav-label{padding:10px 8px 5px;color:#8190aa;font-size:10px;font-weight:800;letter-spacing:.04em}.avsf-nav a{display:flex;gap:9px;align-items:center;padding:8px 10px;margin:2px 0;border-radius:7px;color:#536987;font-size:12px}.avsf-nav a:hover,.avsf-nav a.avsf-active{background:#e8f2ff;color:#176bd0}.avsf-nav a.avsf-active{font-weight:700;box-shadow:inset 3px 0 var(--blue)}.avsf-topbar{position:sticky;top:0;z-index:5;height:54px;padding:0 22px;display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.92);border-bottom:1px solid var(--line);backdrop-filter:blur(12px)}.avsf-topbar a{color:#4a668d}.avsf-main{max-width:1600px;width:100%;margin:auto;padding:21px 22px 40px}.avsf-page-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.avsf-page-heading h1{font-size:22px;margin:1px 0 2px}.avsf-page-heading p{margin:0;color:var(--muted);font-size:11px}.avsf-eyebrow{color:var(--blue)!important;font-size:10px!important;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.avsf-btn{display:inline-flex;justify-content:center;align-items:center;gap:6px;min-height:34px;padding:7px 12px;border:0;border-radius:7px;background:var(--blue);color:#fff;text-decoration:none;font:600 11px inherit;cursor:pointer;white-space:nowrap}.avsf-btn.avsf-secondary{border:1px solid var(--line);background:#fff;color:#4f6788}.avsf-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 12px}.avsf-kpi,.avsf-panel{background:var(--panel);border:1px solid var(--line);border-radius:8px;box-shadow:var(--shadow)}.avsf-kpi{padding:13px 15px;min-height:95px}.avsf-kpi span,.avsf-kpi small{display:block;color:var(--muted);font-size:10px}.avsf-kpi strong{display:block;margin:4px 0;color:var(--text);font-size:22px;line-height:1.2}.avsf-kpi.avsf-green strong{color:var(--green)}.avsf-kpi.avsf-orange strong{color:var(--orange)}.avsf-kpi.avsf-blue strong{color:var(--blue)}.avsf-kpi.avsf-red strong{color:var(--red);font-size:18px}.avsf-overview-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:12px;margin-bottom:12px}.avsf-panel{padding:14px;margin-bottom:12px;min-width:0}.avsf-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.avsf-panel-heading h2{margin:0;font-size:13px}.avsf-panel-heading p{margin:3px 0 0;color:var(--muted);font-size:10px}.avsf-chart-row{display:flex;align-items:center;justify-content:center;gap:30px;min-height:185px}.avsf-donut{width:158px;height:158px;display:grid;place-items:center;border-radius:50%;background:conic-gradient(var(--blue) calc(var(--index)*1%),var(--orange) 0)}.avsf-donut:before{content:"";position:absolute;width:112px;height:112px;border-radius:50%;background:white}.avsf-donut span{z-index:1;text-align:center;font-size:23px;font-weight:800}.avsf-donut small{display:block;color:var(--muted);font-size:10px;font-weight:500}.avsf-legend{min-width:180px;flex:1}.avsf-legend>div{display:grid;grid-template-columns:10px 1fr auto 38px;align-items:center;gap:7px;padding:8px 0;border-bottom:1px solid var(--line);font-size:10px}.avsf-legend>div:last-child{border:0}.avsf-legend strong{text-align:right}.avsf-legend small{color:var(--muted);text-align:right}.avsf-green-dot,.avsf-orange-dot,.avsf-blue-dot{width:8px;height:8px;border-radius:50%;background:var(--green)}.avsf-orange-dot{background:var(--orange)}.avsf-blue-dot{background:var(--blue)}.avsf-resource-row{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line)}.avsf-resource-icon{display:grid;place-items:center;flex:0 0 30px;height:30px;border-radius:50%;background:#eef4fc;color:#496b9c}.avsf-resource-main{flex:1;min-width:0;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 10px;align-items:center}.avsf-resource-main strong,.avsf-resource-main small{display:block}.avsf-resource-main strong{font-size:10px}.avsf-resource-main small{color:var(--muted);font-size:9px;overflow-wrap:anywhere}.avsf-resource-main>span{font-size:10px;font-weight:700}.avsf-meter{grid-column:1/-1;height:6px;border-radius:9px;background:#edf1f7;overflow:hidden}.avsf-meter i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#2c81ef,#55a0ff)}.avsf-health{padding:4px 8px;border-radius:20px;background:#e8f8f1;color:var(--green);font-size:9px}.avsf-safe-note{margin-top:10px;color:var(--muted);font-size:10px;line-height:1.6}.avsf-filters{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}.avsf-filters input{flex:1;min-width:180px}.avsf-filters select{min-width:135px}.avsf-filters input,.avsf-filters select,.avsf-setting-row input,.avsf-setting-row select{min-height:34px;padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--text);font:11px inherit}.avsf-table-wrap{overflow:auto}table{width:100%;min-width:720px;border-collapse:collapse;font-size:10px}th,td{padding:9px 8px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}th{background:#f8faff;color:#7183a3;font-size:9px}td a{color:#267ce2;overflow-wrap:anywhere}.avsf-pill{display:inline-block;padding:3px 8px;border-radius:20px;font-size:9px;font-weight:700}.avsf-pill.avsf-index{background:#e1f6ee;color:#168d61}.avsf-pill.avsf-noindex{background:#fff1dd;color:#c47a10}.avsf-inline-form{display:inline}.avsf-small{min-height:27px;padding:5px 8px;font-size:9px}.avsf-setting-row{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:12px 0;border-bottom:1px solid var(--line)}.avsf-setting-row strong,.avsf-setting-row small{display:block}.avsf-setting-row strong{font-size:11px}.avsf-setting-row small{margin-top:3px;color:var(--muted);font-size:9px}.avsf-setting-row select,.avsf-setting-row input{width:min(230px,42%)}.avsf-quick-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.avsf-quick-grid a{padding:13px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--text);box-shadow:var(--shadow)}.avsf-quick-grid strong,.avsf-quick-grid small{display:block}.avsf-quick-grid strong{font-size:10px}.avsf-quick-grid small{margin-top:4px;color:var(--muted);font-size:9px}.avsf-pagination{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;margin-top:12px}.avsf-pagination a,.avsf-pagination span{padding:5px 8px;border:1px solid var(--line);border-radius:5px;color:#47678d;font-size:10px}.avsf-pagination span{background:var(--blue);color:#fff}.avsf-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.avsf-muted{display:block;color:var(--muted);font-size:9px}.avsf-flash{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#e7f8f0;color:#168d61}.avsf-flash.avsf-error{background:#fff0f0;color:#c63e4e}
[data-admin-theme="dark"]{color-scheme:dark;--bg:#101522;--panel:#182131;--text:#e7eefb;--muted:#a3b1c8;--line:#2a374b;--shadow:0 6px 24px rgba(0,0,0,.15)}[data-admin-theme="dark"] .avsf-sidebar,[data-admin-theme="dark"] .avsf-topbar,[data-admin-theme="dark"] .avsf-quick-grid a{background:#151e2c}[data-admin-theme="dark"] .avsf-donut:before{background:#182131}[data-admin-theme="dark"] .avsf-filters input,[data-admin-theme="dark"] .avsf-filters select,[data-admin-theme="dark"] .avsf-setting-row input,[data-admin-theme="dark"] .avsf-setting-row select{background:#101522;color:var(--text);border-color:var(--line)}[data-admin-theme="dark"] th{background:#202b3d}[data-admin-theme="dark"] .avsf-meter{background:#2a374b}[data-admin-theme="dark"] .avsf-btn.avsf-secondary{background:#1a2333;color:#cdd9ec;border-color:var(--line)}[data-admin-theme="dark"] .avsf-quick-grid a{color:var(--text)}[data-admin-theme="dark"] .avsf-nav a{color:#9fb0c8}[data-admin-theme="dark"] .avsf-nav a:hover,[data-admin-theme="dark"] .avsf-nav a.avsf-active{background:#1e2a3d;color:#7db4ff}[data-admin-theme="dark"] .avsf-pill.avsf-unknown{background:#223044;color:#9fb0c8}[data-admin-theme="dark"] .avsf-health-grid div{background:#151e2c}
@media(max-width:1100px){.avsf-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.avsf-quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:900px){.avsf-shell{grid-template-columns:minmax(0,1fr)}.avsf-sidebar{position:fixed;left:0;top:0;z-index:20;width:min(280px,86vw);transform:translateX(-105%);transition:transform .2s;box-shadow:0 0 0 100vmax rgba(10,20,40,0);visibility:hidden}.avsf-sidebar.avsf-open{transform:none;visibility:visible;box-shadow:0 0 0 100vmax rgba(10,20,40,.35)}.avsf-topbar{height:50px;padding:0 12px}.avsf-main{padding:14px 12px 30px}.avsf-overview-grid{grid-template-columns:1fr}.avsf-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.avsf-kpi{padding:11px;min-height:84px}.avsf-kpi strong{font-size:19px}.avsf-chart-row{gap:16px;min-height:150px}.avsf-donut{width:128px;height:128px}.avsf-donut:before{width:90px;height:90px}.avsf-page-heading{align-items:flex-start}.avsf-page-heading h1{font-size:18px}.avsf-page-heading>.avsf-btn{font-size:9px}.avsf-setting-row{align-items:flex-start;flex-direction:column;gap:8px}.avsf-setting-row select,.avsf-setting-row input{width:100%}.avsf-filters>*{width:100%;min-width:0!important}.avsf-filters input{min-width:0}.avsf-quick-grid{gap:7px}.avsf-panel{padding:11px}.avsf-user-label{display:none}}
.avsf-kpi-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
.avsf-health-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.avsf-health-grid div{padding:10px;border:1px solid var(--line);border-radius:7px;background:var(--bg)}
.avsf-health-grid strong,.avsf-health-grid span{display:block}
.avsf-health-grid strong{font-size:16px}
.avsf-health-grid span{margin-top:3px;color:var(--muted);font-size:9px}
.avsf-pill.avsf-unknown{background:#edf1f7;color:#62718a}
.avsf-btn{font:inherit;font-weight:600;font-size:11px}
.avsf-filters input,.avsf-filters select,.avsf-setting-row input,.avsf-setting-row select{font:inherit;font-size:11px}
@media(max-width:1100px){.avsf-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.avsf-shell>div{min-width:0}
@media(max-width:760px){.avsf-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-health-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-page-heading{flex-direction:column}.avsf-filters>.avsf-sr-only{width:1px;height:1px}}
.avsf-url-inventory-label{display:grid;gap:4px;min-width:180px}.avsf-url-inventory-label strong{font-size:12px;color:var(--text);font-weight:800;line-height:1.45;overflow-wrap:anywhere}.avsf-url-inventory-label a{font-size:11px;color:#267cf4;text-decoration:underline;text-underline-offset:2px;overflow-wrap:anywhere}

.avsf-token-picker{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:8px 0}.avsf-token-chip{border:1px solid rgba(38,124,244,.3);border-radius:999px;background:rgba(38,124,244,.07);color:#2468b8;padding:5px 8px;font:600 10px/1.3 ui-monospace,monospace;cursor:pointer}.avsf-token-chip:hover{background:#267cf4;color:#fff}.avsf-char-count{display:block;margin:5px 0 10px;color:var(--muted);font-size:11px}.avsf-google-preview{border:1px solid var(--border);border-radius:12px;background:#fff;color:#202124;padding:13px;overflow:hidden}.avsf-google-preview-title{font:600 20px/1.3 Arial,sans-serif;color:#1a0dab;overflow-wrap:anywhere}.avsf-google-preview-url{font:12px/1.5 Arial,sans-serif;color:#188038;margin:3px 0 5px;overflow-wrap:anywhere}.avsf-google-preview-desc{font:14px/1.48 Arial,sans-serif;color:#4d5156;overflow-wrap:anywhere}.avsf-google-preview-status{font:11px/1.5 system-ui,sans-serif;color:#697586;margin-top:8px;padding-top:7px;border-top:1px solid #e5e7eb}
</style>
<link rel="stylesheet" href="admin-ui.css">
</head>
<body data-admin-theme="light">
<?php if($notice!==''): ?><div class="avsf-flash<?php echo $noticeType==='error'?' avsf-error':''; ?>" role="status"><?php echo esc($notice); ?></div><?php endif; ?>
<div class="avsf-shell">
<aside class="avsf-sidebar" id="centerSidebar">
<div class="avsf-brand"><span class="avsf-brand-icon">◈</span><span>Site Center<small class="avsf-muted"><?php _e('จัดการเว็บไซต์และดัชนี'); ?></small></span></div>
<nav class="avsf-nav" aria-label="<?php echo __('เมนูศูนย์เว็บไซต์'); ?>">
<div class="avsf-nav-label"><?php _e('ศูนย์เว็บไซต์'); ?></div>
<a href="site-center.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> <?php _e('ภาพรวมศูนย์เว็บไซต์'); ?></a>
<div class="avsf-nav-label"><?php _e('SEO &amp; การค้นหา'); ?></div>
<a class="<?php echo e(avsfClass($view==='dashboard'?'avsf-active':'')); ?>" href="seo-center.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> SEO &amp; Robots Manager</a>
<a class="<?php echo e(avsfClass($view==='indexing'?'avsf-active':'')); ?>" href="?view=indexing"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></svg> Index / Noindex</a>
<a class="<?php echo e(avsfClass($view==='sitemap'?'avsf-active':'')); ?>" href="?view=sitemap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="9" y="15" width="6" height="6" rx="1"/><path d="M6 9v3a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V9"/></svg> Sitemap &amp; Crawlability</a>
<a class="<?php echo e(avsfClass($view==='urls'?'avsf-active':'')); ?>" href="?view=urls"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg> URL Scanner</a>
<a href="redirects.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg> Redirect 301</a>
<a class="<?php echo e(avsfClass($view==='errors'?'avsf-active':'')); ?>" href="?view=errors"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><line x1="12" y1="10" x2="12" y2="14"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> 404 / 410 / 500</a>
<div class="avsf-nav-label"><?php _e('ระบบเว็บไซต์'); ?></div>
<a href="index.php?page=dashboard"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg> <?php _e('แดชบอร์ด AVSF'); ?></a>
<a href="index.php?page=reports"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> <?php _e('รายงานเนื้อหา'); ?></a>
<a href="index.php?page=settings"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.4 1z"/></svg> <?php _e('ตั้งค่า'); ?></a>
<a href="../sitemap.xml" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> <?php _e('เปิด XML Sitemap'); ?></a>
</nav></aside>
<div>
<header class="avsf-topbar"><button class="avsf-btn avsf-secondary" type="button" id="menuToggle" aria-controls="centerSidebar" aria-expanded="false"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg> <?php _e('เมนู'); ?></button><span>SEO &amp; Robots Manager</span><span class="avsf-user-label"><?php echo esc($_SESSION['avsf_admin_username']??__('ผู้ดูแลระบบ')); ?> · <?php echo esc($seoRoleLabel); ?></span><button class="avsf-btn avsf-secondary" type="button" id="themeToggle" aria-label="<?php echo __('สลับโหมดมืดและสว่าง'); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></button></header>
<main class="avsf-main">
<?php echo $content; ?>
</main>
</div></div>
<script>
(function(){
  var root=document.documentElement,body=document.body;
  var saved=localStorage.getItem('avsf-admin-theme');
  if(saved==='dark'||saved==='light'){root.setAttribute('data-admin-theme',saved);body.setAttribute('data-admin-theme',saved);}
  var themeBtn=document.getElementById('themeToggle');
  if(themeBtn)themeBtn.addEventListener('click',function(){
    var cur=root.getAttribute('data-admin-theme')||body.getAttribute('data-admin-theme')||'light';
    var next=cur==='dark'?'light':'dark';
    root.setAttribute('data-admin-theme',next);body.setAttribute('data-admin-theme',next);
    localStorage.setItem('avsf-admin-theme',next);
  });
  var menu=document.getElementById('centerSidebar');
  var toggle=document.getElementById('menuToggle');
  if(toggle&&menu)toggle.addEventListener('click',function(){var open=menu.classList.toggle('avsf-open');toggle.setAttribute('aria-expanded',open?'true':'false');});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&menu){menu.classList.remove('avsf-open');if(toggle)toggle.setAttribute('aria-expanded','false');}});
  // SEO Index/Noindex toggle — on success update BOTH status badge and button label consistently
  document.querySelectorAll('form.avsf-seo-toggle').forEach(function(form){
    form.addEventListener('submit',function(e){
      e.preventDefault();
      var btn=form.querySelector('[data-seo-toggle-btn]');
      var row=form.closest('tr');
      var badge=row?row.querySelector('[data-seo-badge]'):null;
      var idxInput=form.querySelector('input[name="indexable"]');
      if(btn)btn.disabled=true;
      fetch(location.href.split('#')[0],{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json();})
        .then(function(data){
          if(data&&data.ok){
            var on=!!data.indexable;
            if(badge){badge.textContent=on?'Index':'Noindex';badge.classList.toggle('avsf-index',on);badge.classList.toggle('avsf-noindex',!on);}
            if(idxInput)idxInput.value=on?'0':'1';
            if(btn){
              btn.innerHTML=(on?'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="9" y1="9" x2="15" y2="15"/></svg> ':'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ')+(on?'ปิด Index':'เปิด Index');
              btn.disabled=false;
            }
          } else { form.submit(); }
        })
        .catch(function(){form.submit();});
    });
  });
})();
</script>

<script>
(function(){
 var title=document.getElementById('avsfPostSeoTitle'),desc=document.getElementById('avsfPostSeoDesc');
 if(!title||!desc)return;
 var sample={code:'MIAB-679',title:'ตัวอย่างเรื่อง AV ญี่ปุ่นซับไทย',site_name:document.querySelector('.avsf-user-label')?.textContent?.split('·')[0]?.trim()||'AVSUBFIN'};
 var titleOut=document.getElementById('avsfPostSeoPreviewTitle'),descOut=document.getElementById('avsfPostSeoPreviewDesc'),status=document.getElementById('avsfPostSeoPreviewStatus');
 var titleCount=document.getElementById('avsfPostSeoTitleCount'),descCount=document.getElementById('avsfPostSeoDescCount');
 function count(s){try{return Array.from(new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(s)).length;}catch(e){return Array.from(s).length;}}
 function resolve(s){return String(s||'').replace(/\{(code|title|site_name)\}/g,function(all,key){return sample[key]||all;});}
 function update(){var t=resolve(title.value),d=resolve(desc.value);titleOut.textContent=t;descOut.textContent=d;var tc=count(t),dc=count(d);titleCount.textContent='ตัวแปรตัวอย่างแล้ว '+tc+' ตัวอักษร · แม่แบบต้นฉบับ '+count(title.value)+' ตัวอักษร';descCount.textContent='ตัวแปรตัวอย่างแล้ว '+dc+' ตัวอักษร · แม่แบบต้นฉบับ '+count(desc.value)+' ตัวอักษร';var c=document.createElement('canvas').getContext('2d');c.font='600 20px Arial';var tw=c.measureText(t).width;c.font='14px Arial';var dw=c.measureText(d).width;status.textContent=(tw>580?'Title อาจถูกตัด · ':'')+(dw>920?'Description อาจถูกตัด · ':'')+'นี่เป็นการประมาณการเท่านั้น Google อาจเปลี่ยนข้อความที่แสดงตามอุปกรณ์และคำค้นหา';}
 [title,desc].forEach(function(el){el.addEventListener('input',update);el.addEventListener('focus',function(){document.querySelectorAll('[data-post-seo-token]').forEach(function(b){b.dataset.target=el.id;});});});
 document.querySelectorAll('[data-post-seo-token]').forEach(function(button){button.addEventListener('click',function(){var target=document.getElementById(button.dataset.target||title.id);if(!target)return;var start=target.selectionStart==null?target.value.length:target.selectionStart,end=target.selectionEnd==null?start:target.selectionEnd,token=button.getAttribute('data-post-seo-token');target.value=target.value.slice(0,start)+token+target.value.slice(end);target.focus();target.setSelectionRange(start+token.length,start+token.length);target.dispatchEvent(new Event('input',{bubbles:true}));});});
 update();
})();
</script>
</body>
</html>
