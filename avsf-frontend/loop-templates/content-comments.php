<?php
/**
 * loop-templates/content-comments.php
 * คอมเมนต์ + ฟอร์มส่งคอมเมนต์ (มี CSRF). รับ: $p.
 */
global $conn;
$cmts = [];
$res = mysqli_query($conn, "SELECT * FROM comments WHERE postId=".(int)$p['id']." AND approved=1");
while ($r = mysqli_fetch_assoc($res)) $cmts[] = $r;
$csrf = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
?>
<h2 style="font-size:16px;font-weight:700;margin:28px 0 12px;"><?php printf(__('คอมเมนต์ (%s)'), count($cmts)); ?></h2>
<?php foreach ($cmts as $c): ?>
<div class="avsf-comment-item" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:8px;">
    <span class="avsf-c-name" style="font-weight:700;font-size:13px;"><?php echo esc($c['name']); ?></span>
    <span class="avsf-c-date" style="color:var(--muted);font-size:11px;margin-left:8px;"><?php echo fmtDate($c['date']); ?></span>
    <div class="avsf-c-text" style="margin-top:6px;font-size:13px;line-height:1.6;"><?php echo esc($c['text']); ?></div>
</div>
<?php endforeach; ?>
<div class="avsf-card avsf-mt" style="margin-top:14px;">
    <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;"><?php _e('แสดงความคิดเห็น'); ?></h4>
    <form method="post" action="<?php echo esc(siteUrl('post', $p['slug'], [], $p)); ?>">
        <input type="hidden" name="postId" value="<?php echo (int)$p['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo esc($csrf); ?>">
        <input name="cmtName" placeholder="<?php echo esc(__('ชื่อของคุณ')); ?>" style="margin-bottom:8px;">
        <textarea name="cmtText" rows="2" placeholder="<?php echo esc(__('เขียนคอมเมนต์...')); ?>" style="margin-bottom:8px;"></textarea>
        <button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit"><?php _e('ส่งคอมเมนต์'); ?></button>
    </form>
</div>
