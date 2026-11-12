<?php
/**
 * loop-templates/content-gallery.php
 * แกลเลอรี่ภาพ (carousel + lightbox). รับ: $gallery (array of urls), $p.
 */
$gallery = isset($gallery) && is_array($gallery) ? $gallery : [];
if (!$gallery) return;
$galleryAlts = json_decode(isset($p['galleryAlt']) ? (string)$p['galleryAlt'] : '[]', true) ?: [];
?>
<h3 style="font-size:16px;font-weight:700;margin:24px 0 12px;"><?php _e('แกลเลอรี่'); ?></h3>
<div class="avsf-gallery-carousel" data-gallery-carousel tabindex="0" aria-label="<?php echo esc(__('แกลเลอรี่ ใช้ปุ่มลูกศรซ้ายขวาเพื่อเลื่อนดูภาพ')); ?>">
    <button class="avsf-gallery-scroll-button avsf-is-prev" type="button" data-gallery-scroll="-1" aria-label="<?php echo esc(__('เลื่อนแกลเลอรี่ไปทางซ้าย')); ?>">‹</button>
    <div class="avsf-gallery-viewport"><div class="avsf-gallery-grid">
        <?php foreach ($gallery as $i => $src):
            $galleryAlt = trim((string)($galleryAlts[$i] ?? ''));
            $altText = $galleryAlt !== '' ? $galleryAlt : __('ภาพที่ %s จาก %s', [($i+1), $p['title']]);
        ?>
        <button class="avsf-gallery-item" type="button" data-gallery-image="<?php echo (int)$i; ?>" data-gallery-src="<?php echo esc($src); ?>" aria-label="<?php printf(esc(__('เปิดภาพที่ %s จาก %s')), (int)$i+1, count($gallery)); ?>">
            <img src="<?php echo esc($src); ?>" alt="<?php echo esc($altText); ?>" loading="lazy" decoding="async" onerror="this.remove();this.parentElement.classList.add('avsf-gallery-image-missing')"><span class="avsf-gallery-missing-label" aria-hidden="true"><?php _e('ไม่พบไฟล์ภาพ'); ?></span>
        </button>
        <?php endforeach; ?>
    </div></div>
    <button class="avsf-gallery-scroll-button avsf-is-next" type="button" data-gallery-scroll="1" aria-label="<?php echo esc(__('เลื่อนแกลเลอรี่ไปทางขวา')); ?>">›</button>
</div>
