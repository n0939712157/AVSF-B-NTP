<?php
/**
 * AVSF Admin — Cache & Performance (ระดับสูง)
 * วางที่: avsf-admin/cache.php
 * ฟีเจอร์: เปิด/ปิดแคช, ตั้ง TTL, ล้างทั้งหมด, ล้างเฉพาะหมดอายุ,
 *          ล้างตามหมวดหมู่, ล้างตามโพสต์, ล้างหน้าแรก, Pre-warm, สถิติรายละเอียด
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();
require_once AVSF_CORE_DIR . '/cache/cache_lib.php';

requireSiteCenterAccess();
requirePermission('cache.manage');

$cacheDir = AVSF_CACHE_DIR;
$siteBasePath = rtrim(avsfCanonicalBasePath(), '/');
$msg = '';
$msgType = 'success';

// ====== จัดการ action ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    $action = sec_post('action');

    if ($action === 'toggle_cache') {
        $enabled = sec_post('cache_enabled') === '1';
        $ttl = filter_var(sec_post('cache_ttl', '600'), FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 60 || $ttl > 86400) {
            $msg = __('TTL ต้องเป็นตัวเลขตั้งแต่ 60 ถึง 86400 วินาที');
            $msgType = 'error';
        } elseif (!saveSetting('cache_enabled', $enabled ? '1' : '0') || !saveSetting('cache_ttl', (string)$ttl)) {
            $msg = __('บันทึกการตั้งค่าแคชไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');
            $msgType = 'error';
        } else {
            $msg = $enabled ? __('เปิดแคชเรียบร้อย') : __('ปิดแคชเรียบร้อย');
        }
    }
    elseif ($action === 'clear_all') {
        $n = AVSFCache::clearAll($cacheDir);
        $msg = "ล้างแคชทั้งหมดเรียบร้อย — $n ไฟล์";
    }
    elseif ($action === 'clear_expired') {
        $ttl = (int)setting('cache_ttl', '600');
        $n = AVSFCache::clearExpired($cacheDir, $ttl);
        $msg = "ล้างแคชที่หมดอายุเรียบร้อย — $n ไฟล์";
    }
    elseif ($action === 'clear_home') {
        $cleared = (int)AVSFCache::clearUrl($cacheDir, $siteBasePath . '/') + (int)AVSFCache::clearUrl($cacheDir, $siteBasePath . '/index.php');
        $msg = "ล้างแคชหน้าแรกเรียบร้อย — $cleared รายการ";
    }
    elseif ($action === 'clear_category') {
        $cat = sec_post('category_slug');
        if ($cat === '') {
            $msg = __('กรุณาระบุ slug หมวดหมู่');
            $msgType = 'error';
        } else {
            $cleared = AVSFCache::clearUrl($cacheDir, $siteBasePath . '/index.php?page=category&slug=' . rawurlencode($cat));
            $msg = $cleared ? "ล้างแคชหมวดหมู่ '$cat' เรียบร้อย" : "ไม่พบแคชของหมวดหมู่ '$cat'";
        }
    }
    elseif ($action === 'clear_post') {
        $slug = sec_post('post_slug');
        $cleared = AVSFCache::clearUrl($cacheDir, $siteBasePath . '/index.php?page=post&slug=' . rawurlencode($slug));
        $msg = $cleared ? "ล้างแคชโพสต์ '$slug' เรียบร้อย" : "ไม่พบแคชของโพสต์ '$slug'";
    }
    elseif ($action === 'prewarm') {
        if (setting('cache_enabled', '0') !== '1') {
            $msg = __('Pre-warm ไม่ได้เพราะระบบแคชปิดอยู่ กรุณาเปิดแคชก่อน');
            $msgType = 'error';
        } else {
            $urls = [
                $siteBasePath . '/',
                $siteBasePath . '/index.php?page=all',
                $siteBasePath . '/index.php?page=categories',
            ];
            $warmed = 0;
            $failed = 0;
            foreach ($urls as $u) {
            $origin = avsfCanonicalOrigin();
            $full = rtrim($origin, '/') . '/' . ltrim((string)$u, '/');
                $http_response_header = [];
                $response = @file_get_contents($full, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
                $status = $http_response_header[0] ?? '';
                if ($response === false || !preg_match('/\s2\d{2}\s/', $status)) {
                    error_log('AVSF cache pre-warm failed for ' . $full . ($status !== '' ? ' (' . $status . ')' : ''));
                    $failed++;
                } else {
                    $warmed++;
                }
            }
            $msg = "Pre-warm สำเร็จ $warmed/" . count($urls) . __(' หน้า');
            if ($failed > 0) $msgType = 'error';
        }
    }
}

$stats = AVSFCache::stats($cacheDir);
$cacheEnabled = setting('cache_enabled', '0');
$cacheTtl = setting('cache_ttl', '600');
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⚡ แคช &amp; ประสิทธิภาพ — AVSF Admin</title>
<style>
:root{color-scheme:light;--bg:#f3f6fb;--card:#fff;--panel:#fff;--surface2:#f8fafc;--text:#19304f;--muted:#667790;--border:#dfe6f0;--accent:#267cf4;--shadow:0 4px 14px rgba(23,48,79,.06)}
[data-admin-theme="dark"]{color-scheme:dark;--bg:#090b10;--card:#11151f;--panel:#161b26;--surface2:#192131;--text:#e9edf6;--muted:#a5afc1;--border:#293142;--accent:#4c8df6;--shadow:0 4px 14px rgba(0,0,0,.35)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:clip}body{font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:var(--text);padding:20px;min-height:100vh}
.avsf-container{width:100%;max-width:1280px;margin:0 auto}
h1{font-size:24px;margin-bottom:8px;color:var(--accent)}
.avsf-subtitle{color:var(--muted);margin-bottom:24px;font-size:14px}
.avsf-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:16px;color:var(--text)}
.avsf-card h2{font-size:16px;margin-bottom:14px;color:var(--text);display:flex;align-items:center;gap:8px}
.avsf-msg{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px}
.avsf-msg.avsf-success{background:#064e3b;border:1px solid #10b981;color:#6ee7b7}
.avsf-msg.avsf-error{background:#7f1d1d;border:1px solid #ef4444;color:#fca5a5}
.avsf-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:8px}
.avsf-stat{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center}
.avsf-stat .avsf-num{font-size:28px;font-weight:700;color:#38bdf8}
.avsf-stat .avsf-label{font-size:12px;color:var(--muted);margin-top:4px}
.avsf-btn{display:inline-block;padding:10px 18px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;margin:4px 4px 4px 0;text-decoration:none}
.avsf-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.3)}
.avsf-btn-primary{background:#0ea5e9;color:#fff}
.avsf-btn-danger{background:#ef4444;color:#fff}
.avsf-btn-warning{background:#f59e0b;color:#000}
.avsf-btn-success{background:#10b981;color:#fff}
.avsf-btn-secondary{background:var(--muted);color:#fff}
.avsf-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;min-width:0}
input[type=text],input[type=number],select{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:6px;font-size:14px;min-width:0;max-width:100%}
label{font-size:13px;color:var(--muted)}
.avsf-section-title{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin:16px 0 10px;border-bottom:1px solid var(--border);padding-bottom:6px}
.avsf-toggle{position:relative;display:inline-block;width:48px;height:26px}
.avsf-toggle input{opacity:0;width:0;height:0}
.avsf-slider{position:absolute;cursor:pointer;inset:0;background:#475569;border-radius:26px;transition:.3s}
.avsf-slider:before{content:"";position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
input:checked + .avsf-slider{background:#10b981}
input:checked + .avsf-slider:before{transform:translateX(22px)}
.avsf-back{color:var(--muted);text-decoration:none;font-size:13px;display:inline-block;margin-bottom:16px}
.avsf-back:hover{color:var(--accent)}

/* Responsive layout safeguards for compact screens. */
.avsf-card h2{min-width:0;line-height:1.45;flex-wrap:wrap}
.avsf-card form[style*="display:inline"]{display:inline-flex!important;max-width:100%;vertical-align:top;margin:4px 8px 6px 0}
.avsf-card form[style*="display:inline"] .avsf-btn{max-width:100%;white-space:normal;overflow-wrap:normal;word-break:normal;line-height:1.45}
.avsf-row>input[type=text]{flex:1 1 190px;width:min(100%,360px)}
@media(max-width:700px){
  body{padding:12px}
  .avsf-container{min-width:0}
  .avsf-card{padding:14px;min-width:0}
  .avsf-stats-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,125px),1fr))}
  .avsf-row{align-items:stretch}
  .avsf-row>label{flex:0 0 100%}
  .avsf-row>input[type=text],.avsf-row>input[type=number],.avsf-row>select{width:100%;max-width:100%;flex:1 1 100%}
  .avsf-card form[style*="display:inline"]{display:flex!important;width:100%;max-width:100%;margin:0 0 8px}
  .avsf-card form[style*="display:inline"] .avsf-btn{width:100%;min-height:42px}
  .avsf-btn{min-width:0}
}
</style>
<link rel="stylesheet" href="admin-ui.css">
</head>
<body data-admin-theme="light">
<div class="avsf-container">
    <a class="avsf-back" href="index.php">← กลับแดชบอร์ด</a>
    <h1>⚡ ระบบแคช &amp; ประสิทธิภาพ</h1>
    <p class="avsf-subtitle">จัดการแคชในตัว — ลดภาระ MySQL เร็วขึ้น 5–20 เท่า</p>

    <?php if ($msg): ?><div class="avsf-msg <?= e(avsfClass($msgType)) ?>"><?= e($msg) ?></div><?php endif; ?>

    <!-- สถิติ -->
    <div class="avsf-card">
        <h2><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> <?php _e('สถิติแคชปัจจุบัน'); ?></h2>
        <div class="avsf-stats-grid">
            <div class="avsf-stat"><div class="avsf-num"><?= $stats['count'] ?></div><div class="avsf-label"><?php _e('ไฟล์แคช'); ?></div></div>
            <div class="avsf-stat"><div class="avsf-num"><?= $stats['size_mb'] ?> MB</div><div class="avsf-label"><?php _e('ขนาดรวม'); ?></div></div>
            <div class="avsf-stat"><div class="avsf-num"><?= (int)$cacheTtl ?> <?php _e('วิ'); ?></div><div class="avsf-label"><?php _e('TTL (อายุแคช)'); ?></div></div>
            <div class="avsf-stat"><div class="avsf-num" style="color:<?= $cacheEnabled==='1'?'#10b981':'#ef4444' ?>"><?= $cacheEnabled==='1'?__('เปิด'):__('ปิด') ?></div><div class="avsf-label"><?php _e('สถานะ'); ?></div></div>
        </div>
    </div>

    <!-- ตั้งค่า -->
    <div class="avsf-card">
        <h2><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.4 1z"/></svg> <?php _e('ตั้งค่าแคช'); ?></h2>
        <form method="post">
            <?= sec_csrf_field() ?>
            <input type="hidden" name="action" value="toggle_cache">
            <div class="avsf-row">
                <label><?php _e('เปิดใช้แคช:'); ?></label>
                <label class="avsf-toggle"><input type="checkbox" name="cache_enabled" value="1" <?= $cacheEnabled==='1'?'checked':'' ?>><span class="avsf-slider"></span></label>
            </div>
            <div class="avsf-row">
                <label><?php _e('TTL (วินาที):'); ?></label>
                <input type="number" name="cache_ttl" value="<?= e($cacheTtl) ?>" min="60" max="86400">
                <span style="font-size:12px;color:var(--muted)"><?php _e('แนะนำ 600 (10 นาที)'); ?></span>
            </div>
            <button class="avsf-btn avsf-btn-primary" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <?php _e('บันทึกการตั้งค่า'); ?></button>
        </form>
    </div>

    <!-- ล้างแคชระดับสูง -->
    <div class="avsf-card">
        <h2><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> <?php _e('ล้างแคช (ระดับสูง)'); ?></h2>

        <div class="avsf-section-title"><?php _e('ล้างแบบรวดเร็ว'); ?></div>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="clear_all"><button class="avsf-btn avsf-btn-danger" type="submit" onclick="return confirm(<?php echo avsf_js(__('ล้างแคชทั้งหมดแน่ใจ?')); ?>)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> <?php _e('ล้างทั้งหมด'); ?></button></form>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="clear_expired"><button class="avsf-btn avsf-btn-warning" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-2.1-9.4L23 10"/></svg> <?php _e('ล้างเฉพาะที่หมดอายุ'); ?></button></form>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="clear_home"><button class="avsf-btn avsf-btn-secondary" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> <?php _e('ล้างแคชหน้าแรก'); ?></button></form>

        <div class="avsf-section-title"><?php _e('ล้างตามหมวดหมู่'); ?></div>
        <form method="post" class="avsf-row">
            <?= sec_csrf_field() ?>
            <input type="hidden" name="action" value="clear_category">
            <input type="text" name="category_slug" placeholder="<?php echo __('slug หมวดหมู่ เช่น av-subthai'); ?>" required>
            <button class="avsf-btn avsf-btn-warning" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> <?php _e('ล้างแคชหมวดนี้'); ?></button>
        </form>

        <div class="avsf-section-title"><?php _e('ล้างตามโพสต์ (เมื่อแก้ไขโพสต์)'); ?></div>
        <form method="post" class="avsf-row">
            <?= sec_csrf_field() ?>
            <input type="hidden" name="action" value="clear_post">
            <input type="text" name="post_slug" placeholder="<?php echo __('slug ของโพสต์'); ?>" required>
            <button class="avsf-btn avsf-btn-warning" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> <?php _e('ล้างแคชโพสต์นี้'); ?></button>
        </form>

        <div class="avsf-section-title"><?php _e('Pre-warm (สร้างแคชล่วงหน้า)'); ?></div>
        <form method="post" style="display:inline"><?= sec_csrf_field() ?><input type="hidden" name="action" value="prewarm"><button class="avsf-btn avsf-btn-success" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> <?php _e('Pre-warm หน้าหลัก'); ?></button></form>
        <span style="font-size:12px;color:var(--muted);margin-left:8px"><?php _e('เรียกหน้าแรก + หมวดหมู่ให้สร้างแคชก่อน คนเข้าคนแรกจะได้เร็ว'); ?></span>
    </div>

    <div class="avsf-card" style="border-color:#f59e0b">
        <h2 style="color:#fbbf24"><?php _e('⚠️ ข้อควรรู้'); ?></h2>
        <ul style="font-size:13px;color:var(--muted);line-height:1.8;padding-left:20px">
            <li><?php _e('เมื่อแคชเปิด ตัวนับ views จะนับเฉพาะครั้งแรก — ใช้'); ?> <code>view.php</code> <?php _e('beacon หรือ Google Analytics เพื่อความแม่นยำ'); ?></li>
            <li><?php _e('หลังแก้ไขโพสต์/ธีม/เมนู ควรกดล้างแคชเพื่อเห็นผลทันที'); ?></li>
            <li><?php _e('แนะนำใช้คู่กับ'); ?> <strong><?php _e('Cloudflare ฟรี'); ?></strong> <?php _e('เพื่อ CDN + cache สถิต + WAF'); ?></li>
            <li><?php _e('ห้ามเปิดแคชตอนกำลังพัฒนา/แก้ไขหน้าเว็บ (จะไม่เห็นผลแก้ไข)'); ?></li>
        </ul>
    </div>
</div>
</body>
</html>
