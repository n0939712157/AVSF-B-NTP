<?php
/**
 * AVSF single-video player presentation only.
 * Structured data is emitted once as JSON-LD from partials/head.php.
 */
if (!isset($p) || !is_array($p)) { echo '<!-- player: no post data -->'; return; }
$playerInput = !empty($p['embedUrl']) ? $p['embedUrl'] : (isset($p['videoUrl']) ? $p['videoUrl'] : '');
$v = isset($v) && is_array($v) ? $v : parseVideo($playerInput);
$embedUrl = isset($v['embedUrl']) ? trim((string)$v['embedUrl']) : '';
if ($embedUrl === '' && $playerInput !== '' && function_exists('avsf_vdohide_resolve')) {
    $remote = avsf_vdohide_resolve($playerInput);
    if (isset($remote['error'],$remote['url']) && $remote['error'] === '' && $remote['url'] !== '') $embedUrl = $remote['url'];
}
$selfHostedUrl = '';
if ($embedUrl === '' && !empty($v['contentUrl'])) $selfHostedUrl = (string)$v['contentUrl'];
$thumb = isset($thumbnail) ? trim((string)$thumbnail) : '';
?>
<div class="avsf-responsive-player avsf-video-player">
    <?php if ($embedUrl !== ''): ?>
        <?php echo avsf_player_iframe($embedUrl, (int)$p['id'], 'avsf-single-iframe'); ?>
    <?php elseif ($selfHostedUrl !== ''): ?>
        <video class="avsf-self-hosted-video" controls preload="metadata" playsinline
            <?php if ($thumb !== ''): ?>poster="<?php echo esc($thumb); ?>"<?php endif; ?>
            style="width:100%;height:100%;background:#000;">
            <source src="<?php echo esc($selfHostedUrl); ?>" type="video/mp4">
        </video>
    <?php else: ?>
        <div class="avsf-wpst-empty-video-player avsf-player-unavailable<?php echo $thumb !== '' ? ' avsf-player-has-still' : ''; ?>" role="group" aria-label="<?php echo esc(__('วิดีโอยังไม่พร้อม')); ?>">
            <?php if ($thumb !== ''): ?>
                <img class="avsf-player-still" src="<?php echo esc($thumb); ?>" alt="<?php echo esc($p['title'] ?? ''); ?>" decoding="async">
            <?php endif; ?>
            <button type="button" class="avsf-empty-play" disabled aria-disabled="true" tabindex="-1" aria-label="<?php echo esc(__('วิดีโอยังไม่สามารถเล่นได้')); ?>"><?php echo avsfPlaySvg(26); ?></button>
            <p style="color:var(--muted);font-size:12px;margin-top:8px;"><?php _e('วิดีโอกำลังถูกเตรียมการ หรือยังไม่ได้ตั้งค่า player'); ?></p>
        </div>
    <?php endif; ?>
</div>
