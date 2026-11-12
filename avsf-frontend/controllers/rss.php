<?php
/**
 * rss.xml — ฟีดข่าวสารสำหรับผู้ติดตามและ Google News
 * เรียกได้ทั้ง /rss.php และ /rss.xml (ผ่าน .htaccess rewrite)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';

header('Content-Type: application/rss+xml; charset=utf-8');
header('X-Robots-Tag: noindex', true);

$base = rtrim(avsfCanonicalBaseUrl(), '/');
$origin = avsfCanonicalOrigin();
function rssMediaUrl($path,$base,$origin) {
    $path=trim((string)$path);
    if($path==='')return '';
    if(preg_match('/^https?:\/\//i',$path))return $path;
    if(strpos($path,'//')===0)return 'https:'.$path;
    if($path[0]==='/')return $origin.$path;
    return $base.'/'.ltrim($path,'/');
}

$siteName = setting('siteName', 'AVSF');
$siteDesc = setting('siteDesc', __('อัปเดตล่าสุด'));

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
echo "<channel>\n";
echo "  <title>" . htmlspecialchars($siteName, ENT_XML1) . "</title>\n";
echo "  <link>$base/</link>\n";
echo "  <description>" . htmlspecialchars($siteDesc, ENT_XML1) . "</description>\n";
echo "  <language>" . (avsf_lang_attr()==='en'?'en-us':'th-th') . "</language>\n";
echo "  <lastBuildDate>" . date('r') . "</lastBuildDate>\n";
echo "  <atom:link href=\"$base/rss.xml\" rel=\"self\" type=\"application/rss+xml\" />\n";

// โพสต์ล่าสุด 50 เรื่อง (status = 'publish')
$res = mysqli_query($conn, "SELECT id, title, slug, content, cover, posterUrl, gallery, date, createdAt FROM posts WHERE status='publish' ORDER BY date DESC, id DESC LIMIT 50");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $link = $base . sitePath('post',$row['slug'],[],$row);
        $title = htmlspecialchars($row['title'], ENT_XML1);
        $desc = mb_substr(stripHtml($row['content']), 0, 300, 'UTF-8');
        $dateRaw = !empty($row['createdAt']) ? $row['createdAt'] : ($row['date'] . ' 12:00:00');
        $pubDate = date('r', strtotime($dateRaw));
        echo "  <item>\n";
        echo "    <title>$title</title>\n";
        echo "    <link>$link</link>\n";
        echo "    <guid isPermaLink=\"true\">$link</guid>\n";
        echo "    <pubDate>$pubDate</pubDate>\n";
        $descCdata = str_replace(']]>', ']]]]><![CDATA[>', $desc);
        echo "    <description><![CDATA[$descCdata]]></description>\n";
        $rssImage = function_exists('avsfFirstPostImage') ? avsfFirstPostImage($row, false) : (string)($row['cover'] ?? '');
        if ($rssImage !== '') {
            $mediaUrl=rssMediaUrl($rssImage,$base,$origin);
            echo "    <media:thumbnail url=\"" . htmlspecialchars($mediaUrl, ENT_XML1) . "\" />\n";
            echo "    <enclosure url=\"" . htmlspecialchars($mediaUrl, ENT_XML1) . "\" type=\"image/jpeg\" />\n";
        }
        echo "  </item>\n";
    }
    mysqli_free_result($res);
}

echo "</channel>\n</rss>";
