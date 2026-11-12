<?php
/**
 * Dynamic robots.txt endpoint.
 * The public URL is rewritten from /robots.txt by .htaccess.
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$base = rtrim(avsfCanonicalBaseUrl(), '/');
$basePath = rtrim(avsfCanonicalBasePath(), '/');

// Robots rules are relative to the domain root, so prefix private routes
// with the installation directory when AVSF is installed in a subfolder.
$disallowedPaths = [
    '/admin-login/',
    '/api/',
    '/avsf-core/',
    '/avsf-admin/',
    '/avsf-frontend/',
    '/avsf-installer/',
    '/avsf-storage/',
    '/avsf-updates/',
    '/cache/',
    '/includes/',
    '/quarantine/',
    '/setup.php',
    '/db-setup.php',
    '/register.php',
    '/forgot-password.php',
    '/config.php',
    '/config.local.php',
    '/database-schema.sql',
];

echo "User-agent: *\n";
echo "Allow: /\n";
foreach ($disallowedPaths as $path) {
    echo 'Disallow: ' . $basePath . $path . "\n";
}
echo 'Disallow: ' . $basePath . "/*?page=login\n";
echo "\n";
echo "# ====== บอท scraper ที่ไม่อนุญาต ======\n";
foreach (['AhrefsBot','SemrushBot','MJ12bot','DotBot','PetalBot','Bytespider','GPTBot','CCBot','Omgilibot'] as $bot) {
    echo "User-agent: {$bot}\nDisallow: /\n\n";
}
echo "# Googlebot, Bingbot และบอทค้นหาทั่วไปใช้กฎ User-agent: *\n";

$customRules = trim((string)setting('seo_robots_txt', ''));
if ($customRules !== '') {
    $customRules = preg_replace("/\r\n?/", "\n", $customRules);
    // A bare Allow/Disallow block needs its own wildcard group; otherwise it
    // would accidentally attach to the last named crawler group above.
    if (!preg_match('/^\s*User-agent\s*:/mi', $customRules)) {
        echo "\nUser-agent: *\n" . $customRules . "\n";
    } else {
        echo "\n" . $customRules . "\n";
    }
}
echo "\nSitemap: " . $base . "/sitemap_index.xml\n";
