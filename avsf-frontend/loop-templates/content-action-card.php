<?php
/**
 * loop-templates/content-action-card.php
 * Action card แถวขวา: Like / Save / Share (KolorTube pattern, โค้ดเอง).
 * Like/Save ใช้ data attributes + JS (localStorage) — ไม่ต้อง backend.
 * รับ: $postId, $canonicalUrl, $title
 */
$postId = (int)($postId ?? 0);
?>
<div class="avsf-video-action-card avsf-card" style="padding:14px;">
    <div class="avsf-video-buttons-row" role="group" aria-label="<?php echo esc(__('จัดการวิดีโอ')); ?>" style="display:flex;gap:8px;margin-bottom:10px;">
        <button type="button" class="avsf-capsule-btn avsf-capsule-btn-like" data-post_id="<?php echo $postId; ?>" data-post_like="like" aria-pressed="false" style="flex:1;padding:9px;border-radius:999px;border:1px solid var(--border);background:var(--card2);color:var(--text);font-weight:600;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>
            <span><?php _e('ถูกใจ'); ?></span>
        </button>
        <button type="button" class="avsf-capsule-btn avsf-capsule-btn-save" data-save-post="<?php echo $postId; ?>" aria-pressed="false" style="flex:1;padding:9px;border-radius:999px;border:1px solid var(--border);background:var(--card2);color:var(--text);font-weight:600;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/></svg>
            <span><?php _e('บันทึก'); ?></span>
        </button>
        <button type="button" id="show-sharing-buttons" class="avsf-capsule-btn avsf-capsule-btn-share" aria-expanded="false" aria-controls="video-share-box" style="flex:1;padding:9px;border-radius:999px;border:1px solid var(--border);background:var(--card2);color:var(--text);font-weight:600;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>
            <span><?php _e('แชร์'); ?></span>
        </button>
    </div>
    <div class="avsf-video-share-box" id="video-share-box" hidden style="border-top:1px solid var(--border);padding-top:10px;">
        <span class="avsf-title" style="font-size:12px;font-weight:700;color:var(--muted);display:block;margin-bottom:6px;"><?php _e('คัดลอกลิงก์'); ?></span>
        <div style="display:flex;gap:6px;">
            <input type="text" readonly value="<?php echo esc($canonicalUrl); ?>" id="share-link-input" style="flex:1;font-size:11px;padding:7px 9px;" onclick="this.select()">
            <button type="button" class="avsf-btn avsf-btn-primary avsf-btn-sm" data-copy-url="<?php echo esc($canonicalUrl); ?>" style="padding:7px 12px;font-size:11px;"><?php _e('คัดลอก'); ?></button>
        </div>
    </div>
</div>
