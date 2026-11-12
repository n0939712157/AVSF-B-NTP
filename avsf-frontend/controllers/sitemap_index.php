<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
ensureSeoIndexOverridesTable($conn);
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex', true);

$base = rtrim(avsfCanonicalBaseUrl(), '/');
$origin = avsfCanonicalOrigin();

$countRes = mysqli_query($conn, "SELECT COUNT(*) FROM posts WHERE status='publish'");
$postCount=$countRes?(int)mysqli_fetch_row($countRes)[0]:0;
if(!$countRes) {
    error_log('AVSF could not count posts for sitemap index: '.mysqli_error($conn));
    http_response_code(500);
    exit('Sitemap is temporarily unavailable');
}
$perFile=20000;
$postDefault=seoIndexDefault('post')?1:0;
$postEligibleResult=mysqli_query($conn,"SELECT COUNT(*) FROM posts entity LEFT JOIN seo_index_overrides override ON override.entity_type='post' AND override.entity_id=entity.id WHERE entity.status='publish' AND COALESCE(override.indexable,$postDefault)=1");
if(!$postEligibleResult) {
    error_log('AVSF could not count indexable posts for sitemap index: '.mysqli_error($conn));
    http_response_code(500);
    exit('Sitemap is temporarily unavailable');
}
$postEligible=(int)mysqli_fetch_row($postEligibleResult)[0];
$postLimit=setting('seo_sitemap_max_posts','20000');
$postLimit=ctype_digit((string)$postLimit)?min(50000,(int)$postLimit):20000;
$postCount=min($postEligible,$postLimit);
$numFiles=(int)ceil($postCount/$perFile);

function sitemapIndexXml($value) {
    return htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8');
}
function sitemapIndexEntry($base,$file) {
    echo "  <sitemap><loc>".sitemapIndexXml($base.'/'.$file)."</loc></sitemap>\n";
}
function sitemapIndexHasEntities($type,$table,$where='') {
    global $conn;
    $default=seoIndexDefault($type)?1:0;
    $typeE=mysqli_real_escape_string($conn,$type);
    $sql="SELECT COUNT(*) FROM `$table` entity LEFT JOIN seo_index_overrides override ON override.entity_type='$typeE' AND override.entity_id=entity.id WHERE COALESCE(override.indexable,$default)=1";
    if($where!=='')$sql.=' AND '.$where;
    $result=mysqli_query($conn,$sql);
    if(!$result) {
        error_log('AVSF sitemap index count failed: '.mysqli_error($conn));
        http_response_code(500);
        exit('Sitemap is temporarily unavailable');
    }
    return (int)mysqli_fetch_row($result)[0];
}
function sitemapIndexHasTaxonomyEntities($type,$table) {
    global $conn;
    $default=seoIndexDefault($type)?1:0;
    $typeE=mysqli_real_escape_string($conn,$type);
    $result=mysqli_query($conn,"SELECT entity.id,entity.name,COALESCE(override.indexable,$default) AS indexable FROM `$table` entity LEFT JOIN seo_index_overrides override ON override.entity_type='$typeE' AND override.entity_id=entity.id");
    if(!$result) {
        error_log('AVSF taxonomy sitemap index query failed: '.mysqli_error($conn));
        http_response_code(500);
        exit('Sitemap is temporarily unavailable');
    }
    $counts=avsfTaxonomyPostCounts($conn);
    $eligible=0;
    while($row=mysqli_fetch_assoc($result)) {
        $key=$type==='category'?$row['name']:(int)$row['id'];
        $termCount=(int)($counts[$type][$key]??0);
        if((int)$row['indexable']===1||$termCount===0)$eligible++;
    }
    mysqli_free_result($result);
    return $eligible;
}
function sitemapIndexLimit($type,$default=20000) {
    $value=setting('seo_sitemap_max_'.$type,(string)$default);
    return ctype_digit((string)$value)?min(50000,(int)$value):$default;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?xml-stylesheet type="text/xsl" href="'.sitemapIndexXml($base.'/sitemap.xsl').'"?>' . "\n";
echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
for ($i = 1; $i <= $numFiles; $i++) {
    sitemapIndexEntry($base,'sitemap-posts-'.$i.'.xml');
}
$pageCount=min(sitemapIndexHasEntities('page','pages',"entity.status='publish'"),sitemapIndexLimit('pages'));
$hasStaticPages=seoIndexable('home')||seoIndexable('all');
if($pageCount>0||$hasStaticPages)sitemapIndexEntry($base,'sitemap-pages.xml');
$taxonomyParts=[
    'categories'=>['category','categories','category_list'],
    'studios'=>['studio','studios','studio_list'],
    'actors'=>['actor','actors','actor_list'],
    'tags'=>['tag','tags','tag_list']
];
foreach($taxonomyParts as $file=>$taxonomy) {
    list($type,$table,$listType)=$taxonomy;
    $eligible=min(sitemapIndexHasTaxonomyEntities($type,$table),sitemapIndexLimit($table));
    if($eligible>0||seoIndexable($listType))sitemapIndexEntry($base,'sitemap-'.$file.'.xml');
}
echo '</sitemapindex>';
