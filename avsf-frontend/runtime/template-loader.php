<?php
/**
 * AVSF Template Loader — modular isolation layer.
 *
 * หลักการ: ทุก template ถูก include ภายใน try/catch ของตัวเอง
 * ถ้าไฟล์ใดไฟล์หนึ่ง error (fatal/syntax) จะได้แค่บล็อกนั้นเท่านั้น
 * ไม่กระทบส่วนอื่นของหน้า หรือหน้าอื่น — ตรงตาม requirement "เวลาหน้าไหนเสียจะได้เสียแค่จุดของมัน"
 *
 * ใช้:
 *   $contentHtml = avsf_render_template('loop-templates/single-video.php', ['p'=>$p, ...]);
 *   echo avsf_render_section('loop-templates/single-video-player.php', ['p'=>$p,'v'=>$v]);
 */
if (!defined('AVSF_TEMPLATE_DIR')) {
    define('AVSF_TEMPLATE_DIR', AVSF_FRONTEND_DIR);
}
if (!defined('AVSF_TEMPLATE_DEBUG')) {
    define('AVSF_TEMPLATE_DEBUG', false); // เปิด true ตอน dev เท่านั้น
}

function avsf_template_path($relative) {
    $relative = ltrim((string)$relative, '/\\');
    $path = AVSF_TEMPLATE_DIR . '/' . $relative;
    return is_file($path) ? $path : false;
}

/**
 * Render a template file in isolation. Returns HTML string. Never throws.
 * On failure: logs error + returns graceful fallback block (never blank/white-screen).
 */
function avsf_render_template($relative, $vars = []) {
    $path = avsf_template_path($relative);
    if (!$path) {
        error_log('[AVSF template] missing template: ' . $relative);
        return '<div class="avsf-card" style="text-align:center;padding:24px;color:var(--muted);">Template not found: ' . esc($relative) . '</div>';
    }
    if (!is_array($vars)) $vars = [];
    // นำเข้าตัวแปรที่ template ต้องการ (รักษาชื่อเดิม: $p, $v, $studio, $metaLine, $detailsHtml, ...)
    extract($vars, EXTR_SKIP);
    ob_start();
    try {
        include $path;
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('[AVSF template] ' . $relative . ' :: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (AVSF_TEMPLATE_DEBUG) {
            return '<div class="avsf-card" style="border-color:var(--danger);padding:16px;"><strong style="color:var(--danger);">Template error (debug):</strong> ' . esc($e->getMessage()) . '<br><small style="color:var(--muted);">' . esc($relative) . ' : ' . esc($e->getLine()) . '</small></div>';
        }
        return '<div class="avsf-card" style="text-align:center;padding:24px;color:var(--muted);">'.__('ส่วนนี้ไม่สามารถแสดงผลได้ชั่วคราว — ส่วนอื่นของหน้ายังทำงานปกติ').'</div>';
    }
    return (string)ob_get_clean();
}

/**
 * Render a section inside an existing page. Same isolation; failure only blanks that section.
 * Echoes directly — ใช้ใน template ที่ต้องการ include section ย่อย (player, gallery, comments, ...)
 */
function avsf_render_section($relative, $vars = []) {
    echo avsf_render_template($relative, $vars);
}

/**
 * Dispatch หน้าตาม $page. แต่ละหน้าเป็นไฟล์ของตัวเองใน pages/ หรือ loop-templates/ หรือ taxonomy-templates/.
 * คืน $contentHtml. ถ้า template หน้านั้นพัง จะได้แค่หน้านั้น — หน้าอื่นไม่กระทบ.
 */
function avsf_dispatch_page($page, $slug, $extra = []) {
    $map = [
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
    $file = isset($map[$page]) ? $map[$page] : 'pages/page-not-found.php';
    return avsf_render_template($file, array_merge(['page' => $page, 'slug' => $slug], $extra));
}
