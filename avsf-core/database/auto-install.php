<?php
/**
 * AVSF Auto Installer.
 * Creates any missing schema tables and seeds portable website defaults once.
 * Portable SEO recommendations are seeded; site-specific identity, domains, keywords and credentials remain blank.
 */
/** Canonical, portable first-run defaults. UI labels, descriptions and placeholders live in source templates and language dictionaries. */
if (!function_exists('avsf_default_settings')) {
    function avsf_default_settings() {
    return [
            // General site identity: intentionally blank so each new site supplies its own identity.
            'siteName' => 'เว็บไซต์ใหม่', 'siteDesc' => '', 'siteUrl' => '', 'site_url' => '',
            'adminEmail' => '', 'site_admin_email' => '', 'site_admin_phone' => '',
            'logoText' => '', 'siteLogo' => '', 'siteIcon' => '',
            'siteLanguage' => 'th', 'siteTimezone' => 'Asia/Bangkok', 'dateFormat' => 'd M Y', 'timeFormat' => 'H:i',
            'announcement' => '',

            // Basic frontend theme defaults.
            'theme_accent' => '#ff2d78', 'admin_accent' => '#267cf4', 'admin_theme' => 'dark',
            'theme_bg_color' => '#0a0a0f', 'theme_text_color' => '#ffffff', 'theme_muted_color' => '#8b8b9a',
            'theme_accent2_color' => '#a855f7', 'theme_link_color' => '#ff79a8',
            'theme_button_bg' => '#ff2d78', 'theme_button_text' => '#ffffff',
            'theme_card_bg' => '#14141c', 'theme_card2_bg' => '#1a1a24', 'theme_border_color' => '#343443',
            'theme_menu_bg' => '#101018', 'theme_menu_text' => '#b5b5c5',
            'theme_menu_active_bg' => '#321426', 'theme_menu_active_text' => '#ff70a4',
            'theme_card_radius' => '14', 'theme_card_shadow' => 'soft',
            'theme_navLabels' => '["หน้าหลัก","ค่าย","นักแสดง","แท็ก"]',

            // Normal frontend features remain enabled; no site content is preloaded.
            'showViews' => 'on', 'showStudio' => 'on', 'showTags' => 'on', 'showActors' => 'on',
            'showDuration' => 'on', 'showBottomNav' => 'on', 'showSearch' => 'on', 'showRelated' => 'on',
            'showBanners' => 'on', 'showAnnouncement' => 'off',
            'theme_showViews' => '1', 'theme_showStudio' => '1', 'theme_showTags' => '1', 'theme_showActors' => '1',
            'theme_showDuration' => '1', 'theme_showBottomNav' => '1', 'theme_showSearch' => '1',
            'theme_showRelated' => '1', 'theme_showBanners' => '1', 'theme_showAnnouncement' => '0',

            // Frontend copy and carousel settings.
            'site_home_featured_title' => 'แนะนำ', 'site_home_featured_all_label' => 'ดูทั้งหมด',
            'site_home_latest_title' => 'โพสต์ล่าสุด', 'site_banner_accessibility' => 'แบนเนอร์หน้าแรก',
            'site_ad_label' => 'โฆษณา', 'site_ad_close_label' => 'ปิดโฆษณา',
            'site_search_placeholder' => 'ค้นหา...', 'site_search_button' => 'ค้นหา',
            'site_home_featured_limit' => '12', 'site_banner_interval_seconds' => '3',
            'site_home_featured_interval_seconds' => '6', 'video_ad_slots' => '{}',

            // Permalinks and route bases: clean, portable, independent of any old domain.
            'permalinkStructure' => 'postname', 'categoryBase' => 'category', 'tagBase' => 'tag',
            'actorBase' => 'actor', 'studioBase' => 'studio',

            // Optional integrations are disabled/unconfigured until the site owner connects them.
            'cache_enabled' => '0', 'cache_ttl' => '600',
            'vdohideApiToken' => '', 'vdohideSupportedDomains' => '', 'vdohideIframeDomain' => '',
            'videoKnownPlayers' => '',
            'redisEnabled' => '0', 'redisHost' => '', 'redisPort' => '6379', 'redisDatabase' => '0',
            'socialFacebook' => '', 'socialX' => '', 'socialInstagram' => '', 'socialTelegram' => '',
            'mailServer' => '', 'mailPort' => '', 'mailLogin' => '', 'mailCategory' => '', 'updateServices' => '',

            // Standard content / reading / discussion defaults.
            'defaultCategory' => '', 'defaultPostFormat' => 'standard',
            'homeDisplay' => 'posts', 'homePageId' => '', 'postsPerPage' => '12',
            'feedItems' => '10', 'feedContent' => 'summary',
            'commentsEnabled' => '0', 'commentsRequireName' => '1', 'commentsRequireApproval' => '1', 'commentsNotifyAdmin' => '0',
            'mediaMaxWidth' => '1920', 'mediaMaxHeight' => '1920', 'mediaQuality' => '82', 'mediaLazyLoad' => '1',
            'privacyPageId' => '', 'privacyContact' => '',

            // Portable SEO defaults: safe general recommendations without importing site identity or keywords.
            'seo_robots' => 'index, follow', 'seo_title_separator' => '|',
            'seo_social_twitter_card' => 'summary_large_image',
            'seo_post_meta_title_template' => '{title} | {site_name}',
            'seo_post_meta_desc_template' => '{code} - {title}',
            'seo_public_home_position' => 'top',
            'seo_sitemap_max_posts' => '20000', 'seo_sitemap_max_pages' => '20000',
            'seo_sitemap_max_categories' => '20000', 'seo_sitemap_max_studios' => '20000',
            'seo_sitemap_max_actors' => '20000', 'seo_sitemap_max_tags' => '20000',

            // Security and user defaults: no imported keys, accounts, proxies, or external secrets.
            'default_register_permissions' => '["posts.view","posts.create","posts.edit"]',
            'registration_secret_key' => '', 'security_trusted_proxy_ips' => '',
            'media_metadata_v1' => '{}', 'setup_complete' => '0',
            'avsf_defaults_version' => '1',
        ];
    }
}

function avsf_auto_install($conn) {
    $schemaFile = AVSF_INSTALLER_DIR . '/database-schema.sql';
    if (!is_file($schemaFile)) {
        http_response_code(500);
        die('<div style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:28px;border:1px solid #f5c6c6;background:#fff5f5;border-radius:14px;">'
            . '<h2 style="margin-top:0;color:#c0392b;">'.__('ไม่พบไฟล์ database-schema.sql').'</h2>'
            . '<p>'.__('ตรวจสอบว่าได้อัปโหลดไฟล์ฐานข้อมูลของระบบขึ้นมาครบถ้วนแล้ว').'</p></div>');
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        http_response_code(500);
        die(__('อ่านไฟล์โครงสร้างฐานข้อมูลไม่สำเร็จ'));
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/^\/\*.*?\*\//s', '', $sql);

    // Apply the idempotent schema only when one or more required tables are missing.
    $existingTables = [];
    $tableResult = @mysqli_query($conn, 'SHOW TABLES');
    if (!$tableResult) {
        error_log('AVSF could not inspect database tables: ' . mysqli_error($conn));
        http_response_code(500);
        die(__('ตรวจสอบตารางฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบสิทธิ์ของผู้ใช้ฐานข้อมูล'));
    }
    while ($tableRow = mysqli_fetch_row($tableResult)) {
        $existingTables[strtolower((string)$tableRow[0])] = true;
    }
    mysqli_free_result($tableResult);

    preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $tableMatches);
    $requiredTables = array_unique(array_map('strtolower', $tableMatches[1] ?? []));
    $missingTables = array_values(array_filter($requiredTables, function ($table) use ($existingTables) {
        return !isset($existingTables[$table]);
    }));

    if ($missingTables) {
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=0');
        $errors = [];
        foreach ($statements as $stmt) {
            if ($stmt === '' || stripos($stmt, 'SET ') === 0) continue;
            if (!@mysqli_query($conn, $stmt)) $errors[] = mysqli_error($conn);
        }
        mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=1');
        if ($errors) {
            error_log('AVSF auto-install errors: ' . implode(' | ', array_slice($errors, 0, 5)));
            http_response_code(500);
            die(__('สร้างตารางฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบสิทธิ์ CREATE TABLE ของฐานข้อมูล'));
        }
    }

    // Avoid running the default seed on every request. Existing values are preserved.
    $seedCheck = @mysqli_query($conn, "SELECT 1 FROM settings WHERE skey='avsf_defaults_version' LIMIT 1");
    if (!$seedCheck) {
        error_log('AVSF could not inspect default seed marker: ' . mysqli_error($conn));
        http_response_code(500);
        die(__('ตรวจสอบค่าตั้งต้นของเว็บไซต์ไม่สำเร็จ'));
    }
    $defaultsAlreadySeeded = mysqli_num_rows($seedCheck) > 0;
    mysqli_free_result($seedCheck);

    if (!$defaultsAlreadySeeded) {
        $defaults = avsf_default_settings();

        $insert = mysqli_prepare($conn, 'INSERT INTO settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=svalue');
        if (!$insert) {
            error_log('AVSF could not prepare defaults insert: ' . mysqli_error($conn));
            http_response_code(500);
            die(__('ไม่สามารถเตรียมค่าตั้งต้นของเว็บไซต์ได้'));
        }
        foreach ($defaults as $key => $value) {
            mysqli_stmt_bind_param($insert, 'ss', $key, $value);
            if (!mysqli_stmt_execute($insert)) {
                error_log('AVSF default setting insert failed for ' . $key . ': ' . mysqli_stmt_error($insert));
                mysqli_stmt_close($insert);
                http_response_code(500);
                die(__('บันทึกค่าตั้งต้นของเว็บไซต์ไม่สำเร็จ'));
            }
        }
        mysqli_stmt_close($insert);
    }

    global $SETTINGS;
    $SETTINGS = [];
    $res = @mysqli_query($conn, 'SELECT skey,svalue FROM settings');
    if ($res) while ($row = mysqli_fetch_assoc($res)) $SETTINGS[$row['skey']] = $row['svalue'];
}
