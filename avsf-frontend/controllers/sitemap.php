<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
ensureSeoIndexOverridesTable($conn);

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex', true);

$base = rtrim(avsfCanonicalBaseUrl(), '/');
$origin = avsfCanonicalOrigin();
$part=isset($_GET['part'])&&is_string($_GET['part'])?strtolower($_GET['part']):'pages';
$pageNum=max(1,(int)(isset($_GET['p'])?$_GET['p']:1));
$perPage=20000;
$offset=($pageNum-1)*$perPage;
$allowedParts=['pages','posts','categories','studios','actors','tags','taxonomy','all'];
if(!in_array($part,$allowedParts,true)) {
    http_response_code(404);
    exit('Unknown sitemap part');
}

function sitemapXml($value) {
    return htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8');
}
function sitemapQuery($sql) {
    global $conn;
    $result=mysqli_query($conn,$sql);
    if(!$result) {
        error_log('AVSF sitemap query failed: '.mysqli_error($conn));
        http_response_code(500);
        exit('Sitemap is temporarily unavailable');
    }
    return $result;
}
function sitemapMediaUrl($path,$base,$origin) {
    $path=trim((string)$path);
    if($path==='')return '';
    if(preg_match('/^https?:\\/\\//i',$path))return $path;
    if(strpos($path,'//')===0)return 'https:'.$path;
    if($path[0]==='/')return $origin.$path;
    return $base.'/'.ltrim($path,'/');
}
function sitemapUrlEntry($url,$lastmod='',$image='',$title='') {
    echo "  <url><loc>".sitemapXml($url)."</loc>";
    if($lastmod!=='')echo '<lastmod>'.sitemapXml($lastmod).'</lastmod>';
    if($image!=='')echo '<image:image><image:loc>'.sitemapXml($image).'</image:loc>';
    if($image!==''&&$title!=='')echo '<image:title>'.sitemapXml($title).'</image:title>';
    if($image!=='')echo '</image:image>';
    echo "</url>\n";
}
function sitemapLimit($entityType,$default=20000) {
    $keys=['post'=>'posts','page'=>'pages','category'=>'categories','studio'=>'studios','actor'=>'actors','tag'=>'tags'];
    if(!isset($keys[$entityType]))return $default;
    $value=setting('seo_sitemap_max_'.$keys[$entityType],(string)$default);
    return ctype_digit((string)$value)?min(50000,(int)$value):$default;
}

echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
echo '<?xml-stylesheet type="text/xsl" href="'.sitemapXml($base.'/sitemap.xsl').'"?>'."\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

if($part==='all'||$part==='pages') {
    if(seoIndexable('home'))sitemapUrlEntry($base.'/');
    if(seoIndexable('all'))sitemapUrlEntry($base.sitePath('all'));
    $pageLimit=sitemapLimit('page');
    $pageDefault=seoIndexDefault('page')?1:0;
    $pages=sitemapQuery("SELECT page.slug,page.date FROM pages page LEFT JOIN seo_index_overrides override ON override.entity_type='page' AND override.entity_id=page.id WHERE page.status='publish' AND COALESCE(override.indexable,$pageDefault)=1 ORDER BY page.date DESC LIMIT $pageLimit");
    while($row=mysqli_fetch_assoc($pages)) {
        $lastmod=!empty($row['date'])?date('Y-m-d',strtotime($row['date'])):'';
        sitemapUrlEntry($base.sitePath('page',$row['slug']),$lastmod);
    }
    mysqli_free_result($pages);
}

if($part==='all'||$part==='posts') {
    $postLimit=sitemapLimit('post');
    $postDefault=seoIndexDefault('post')?1:0;
    $sql="SELECT post.id,post.slug,post.date,post.title,post.cover,post.posterUrl,post.gallery FROM posts post LEFT JOIN seo_index_overrides override ON override.entity_type='post' AND override.entity_id=post.id WHERE post.status='publish' AND COALESCE(override.indexable,$postDefault)=1 ORDER BY post.date DESC,post.id DESC LIMIT ";
    $sql.=min($offset,$postLimit).','.min($perPage,max(0,$postLimit-$offset));
    $posts=sitemapQuery($sql);
    while($row=mysqli_fetch_assoc($posts)) {
        $lastmod=!empty($row['date'])?date('Y-m-d',strtotime($row['date'])):'';
        $image = function_exists('avsfFirstPostImage') ? avsfFirstPostImage($row, true) : ((isset($row['posterUrl']) && $row['posterUrl'] !== '') ? $row['posterUrl'] : $row['cover']);
        sitemapUrlEntry($base.sitePath('post',$row['slug'],[],$row),$lastmod,sitemapMediaUrl($image,$base,$origin),$row['title']);
    }
    mysqli_free_result($posts);
}

$taxonomyTables=[
    'categories'=>['categories','category'],
    'studios'=>['studios','studio'],
    'actors'=>['actors','actor'],
    'tags'=>['tags','tag']
];
$taxonomyPostCounts=in_array($part,['all','taxonomy','categories','studios','actors','tags'],true)?avsfTaxonomyPostCounts($conn):[];
foreach($taxonomyTables as $key=>$definition) {
    if($part!=='all'&&$part!=='taxonomy'&&$part!==$key)continue;
    list($table,$route)=$definition;
    $entityType=$route;
    $entityLimit=sitemapLimit($entityType);
    $entityDefault=seoIndexDefault($entityType)?1:0;
    $items=sitemapQuery("SELECT entity.id,entity.slug,entity.name,COALESCE(override.indexable,$entityDefault) AS indexable FROM `$table` entity LEFT JOIN seo_index_overrides override ON override.entity_type='".sitemapXml($entityType)."' AND override.entity_id=entity.id ORDER BY entity.name LIMIT $entityLimit");
    while($row=mysqli_fetch_assoc($items)) {
        $countKey=$route==='category'?$row['name']:(int)$row['id'];
        $termCount=(int)($taxonomyPostCounts[$route][$countKey]??0);
        if((int)$row['indexable']===1||$termCount===0)sitemapUrlEntry($base.sitePath($route,$row['slug']));
    }
    mysqli_free_result($items);
    $listRoute=$table==='categories'?'categories':$route.'_list';
    $listType=$table==='categories'?'category_list':$route.'_list';
    if(($part==='all'||$part==='taxonomy'||$part===$key)&&seoIndexable($listType))sitemapUrlEntry($base.sitePath($listRoute));
}

echo '</urlset>';
