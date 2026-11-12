<?php
/**
 * pages/page-not-found.php — 404 fallback.
 */
http_response_code(404);
$GLOBALS['metaTitle'] = __('ไม่พบหน้า') . ' | ' . setting('siteName', '');
$GLOBALS['robotsContent'] = 'noindex, follow';
$GLOBALS['canonicalUrl'] = '';
?>
<div class="avsf-card" style="text-align:center;padding:48px 24px;margin-top:24px;">
    <div style="font-size:48px;font-weight:900;color:var(--accent);margin-bottom:8px;">404</div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:10px;"><?php _e('ไม่พบหน้าที่คุณต้องการ'); ?></h1>
    <p style="color:var(--muted);font-size:13px;margin-bottom:18px;"><?php _e('ลิงก์อาจไม่ถูกต้อง หรือหน้านี้ถูกลบไปแล้ว'); ?></p>
    <a class="avsf-btn avsf-btn-primary" href="<?php echo esc(siteUrl('home')); ?>"><?php _e('กลับหน้าหลัก'); ?></a>
    <a class="avsf-btn avsf-btn-ghost" href="<?php echo esc(siteUrl('all')); ?>" style="margin-left:8px;"><?php _e('ดูเรื่องทั้งหมด'); ?></a>
</div>
