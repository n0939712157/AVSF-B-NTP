<?php
/**
 * partials/pagination.php — แบ่งหน้า (prev / numbers / next).
 * รับ: $route, $currentPage, $totalPages, $slug (optional), $extra (optional query).
 */
$currentPage = (int)($currentPage ?? 1);
$totalPages = (int)($totalPages ?? 1);
if ($totalPages <= 1) return;
$routeSlug = $slug ?? '';
$extra = $extra ?? [];
$start = max(1, $currentPage - 2);
$end = min($totalPages, $currentPage + 2);
?>
<nav class="avsf-archive-pagination" aria-label="<?php echo esc(__('หน้ารายการ')); ?>" style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin:20px 0;">
    <?php if ($currentPage > 1): ?>
        <a href="<?php echo esc(siteUrl($route, $routeSlug, array_merge(['pg'=>$currentPage-1], $extra))); ?>" rel="prev" style="padding:7px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-size:12px;"><?php _e('← ก่อนหน้า'); ?></a>
    <?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $currentPage): ?>
            <span aria-current="page" style="padding:7px 12px;border-radius:8px;background:var(--accent);color:#fff;font-size:12px;font-weight:700;"><?php echo $i; ?></span>
        <?php else: ?>
            <a href="<?php echo esc(siteUrl($route, $routeSlug, array_merge(['pg'=>$i], $extra))); ?>" style="padding:7px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-size:12px;"><?php echo $i; ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($currentPage < $totalPages): ?>
        <a href="<?php echo esc(siteUrl($route, $routeSlug, array_merge(['pg'=>$currentPage+1], $extra))); ?>" rel="next" style="padding:7px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-size:12px;"><?php _e('ถัดไป →'); ?></a>
    <?php endif; ?>
</nav>
