<?php
/**
 * AVSF — Redirect Manager (ระบบจัดการลิงก์เปลี่ยนทาง 301/302/307/308/410)
 * เหมือนปลั๊กอิน Redirection ของ WordPress — จัดการได้ทั้งหมดในหลังบ้าน
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();

requireSiteCenterAccess();
requirePermission('redirects.manage');

$msg = '';
$msgType = 'success';
$tab = $_GET['tab'] ?? 'redirects';

// ====== POST actions ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();

    // เพิ่ม redirect ใหม่
    if (isset($_POST['add_redirect'])) {
        $old = trim($_POST['old_url'] ?? '');
        $new = trim($_POST['new_url'] ?? '');
        $code = (int)($_POST['code'] ?? 301);
        $note = trim($_POST['note'] ?? '');
        $validCodes = [301,302,307,308,410,451];
        if ($old === '' || !in_array($code, $validCodes)) {
            $msg = __('กรุณากรอก URL เดิม และเลือกประเภท redirect'); $msgType = 'error';
        } elseif ($code !== 410 && $code !== 451 && $new === '') {
            $msg = __('กรุณากรอก URL ปลายทาง'); $msgType = 'error';
        } else {
            $oE = mysqli_real_escape_string($conn, $old);
            $nE = mysqli_real_escape_string($conn, $new);
            $noteE = mysqli_real_escape_string($conn, $note);
            mysqli_query($conn, "INSERT INTO redirects (old_url,new_url,code,note) VALUES ('$oE','$nE',$code,'$noteE') ON DUPLICATE KEY UPDATE new_url='$nE', code=$code, note='$noteE'");
            $msg = __('✅ เพิ่ม redirect เรียบร้อย');
            logActivity("เพิ่ม redirect: $old → $new ($code)");
        }
    }

    // ลบ redirect
    if (isset($_POST['delete_redirect'])) {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM redirects WHERE id=$id");
        $msg = __('ลบ redirect เรียบร้อย');
    }

    // เปิด/ปิด redirect
    if (isset($_POST['toggle_redirect'])) {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "UPDATE redirects SET active = 1 - active WHERE id=$id");
        $msg = __('อัปเดตสถานะเรียบร้อย');
    }

    // ล้าง log 404
    if (isset($_POST['clear_404'])) {
        mysqli_query($conn, "TRUNCATE TABLE 404_log");
        $msg = __('ล้าง log 404 เรียบร้อย');
    }

    // สร้าง redirect จาก 404 log
    if (isset($_POST['redirect_from_404'])) {
        $old = trim($_POST['old_url'] ?? '');
        $new = trim($_POST['new_url'] ?? '');
        if ($old && $new) {
            $oE = mysqli_real_escape_string($conn, $old);
            $nE = mysqli_real_escape_string($conn, $new);
            mysqli_query($conn, "INSERT INTO redirects (old_url,new_url,code) VALUES ('$oE','$nE',301) ON DUPLICATE KEY UPDATE new_url='$nE'");
            mysqli_query($conn, "DELETE FROM 404_log WHERE path='$oE'");
            $msg = __('✅ สร้าง redirect จาก 404 เรียบร้อย');
        }
    }

    // Export CSV
    if (isset($_POST['export_csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="avsf-redirects.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['old_url','new_url','code','hits','note']);
        $res = mysqli_query($conn, "SELECT old_url,new_url,code,hits,note FROM redirects ORDER BY id");
        while ($r = mysqli_fetch_assoc($res)) fputcsv($out, $r);
        fclose($out); exit;
    }
}

// ====== ดึงข้อมูล ======
$redirects = [];
$res = mysqli_query($conn, "SELECT * FROM redirects ORDER BY id DESC");
while ($r = mysqli_fetch_assoc($res)) $redirects[] = $r;

$log404 = [];
$res = mysqli_query($conn, "SELECT * FROM 404_log ORDER BY last_hit DESC LIMIT 200");
while ($r = mysqli_fetch_assoc($res)) $log404[] = $r;

$totalHits = array_sum(array_column($redirects, 'hits'));
$csrf = esc($_SESSION['csrf_token']);
$codeLabels = [301=>__('301 Permanent (ย้ายถาวร — แนะนำสำหรับ SEO)'),302=>__('302 Temporary (ย้ายชั่วคราว)'),307=>__('307 Temporary (รักษา POST)'),308=>__('308 Permanent (รักษา POST)'),410=>__('410 Gone (ลบถาวร ไม่มีทางกลับ)'),451=>__('451 Unavailable (กฎหมาย/เซ็นเซอร์)')];
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🔀 Redirect Manager — AVSF</title>
<style>
:root{color-scheme:light;--bg:#f3f6fb;--card:#fff;--panel:#fff;--surface2:#f8fafc;--text:#19304f;--muted:#667790;--border:#dfe6f0;--accent:#267cf4;--shadow:0 4px 14px rgba(23,48,79,.06)}
[data-admin-theme="dark"]{color-scheme:dark;--bg:#090b10;--card:#11151f;--panel:#161b26;--surface2:#192131;--text:#e9edf6;--muted:#a5afc1;--border:#293142;--accent:#4c8df6;--shadow:0 4px 14px rgba(0,0,0,.35)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:clip}body{font-family:Kanit,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;width:100%;max-width:none;margin:0;padding:clamp(12px,2vw,24px)}
h1{font-size:20px;margin-bottom:4px}
.avsf-sub{color:var(--muted);font-size:12px;margin-bottom:16px}
.avsf-tabs{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap}
.avsf-tab{padding:8px 16px;border-radius:10px 10px 0 0;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--muted);background:var(--card);border:1px solid var(--border);border-bottom:none}
.avsf-tab.avsf-active{background:var(--card);color:var(--text);border-color:#ff2d78}
.avsf-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;color:var(--text)}
.avsf-msg{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px}
.avsf-msg.avsf-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.avsf-msg.avsf-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}
.avsf-btn{display:inline-block;padding:8px 16px;border-radius:9px;border:none;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;font-family:inherit}
.avsf-btn-primary{background:linear-gradient(135deg,#ff2d78,#a855f7);color:#fff}
.avsf-btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
.avsf-btn-danger{background:#dc2626;color:#fff}
.avsf-btn-sm{padding:5px 10px;font-size:11px}
label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin:10px 0 4px}
input,select,textarea{width:100%;padding:9px 11px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:inherit}
input:focus,select:focus{outline:none;border-color:#ff2d78}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{padding:9px 7px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:10px;text-transform:uppercase}
.avsf-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
.avsf-badge.avsf-c301{background:rgba(34,197,94,.2);color:#4ade80}
.avsf-badge.avsf-c302{background:rgba(59,130,246,.2);color:#60a5fa}
.avsf-badge.avsf-c307{background:rgba(59,130,246,.2);color:#60a5fa}
.avsf-badge.avsf-c308{background:rgba(34,197,94,.2);color:#4ade80}
.avsf-badge.avsf-c410{background:rgba(239,68,68,.2);color:#f87171}
.avsf-badge.avsf-c451{background:rgba(245,158,11,.2);color:#fbbf24}
.avsf-badge.avsf-off{background:#374151;color:#9ca3af}
.avsf-row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.avsf-row>div{flex:1;min-width:150px}
.avsf-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.avsf-stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.avsf-stat .avsf-num{font-size:24px;font-weight:800;background:linear-gradient(135deg,#ff2d78,#a855f7);-webkit-background-clip:text;background-clip:text;color:transparent}
.avsf-stat .avsf-lbl{font-size:11px;color:var(--muted);margin-top:2px}
.avsf-url-cell{font-family:monospace;font-size:11px;word-break:break-all;max-width:250px}
.avsf-back{color:var(--accent);text-decoration:none;font-size:13px}
.avsf-hint{font-size:11px;color:var(--muted);margin-top:6px}
</style>
<link rel="stylesheet" href="admin-ui.css">
</head>
<body data-admin-theme="light">
<a href="index.php" class="avsf-back"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> กลับแดชบอร์ด</a>
<h1 style="margin-top:12px">🔀 Redirect Manager</h1>
<p class="avsf-sub">จัดการลิงก์เปลี่ยนทาง 301/302/307/308/410 · นับ hit อัตโนมัติ · บันทึก 404 ให้สร้าง redirect ได้ · ปลอดภัยต่อ SEO</p>

<?php if ($msg): ?><div class="avsf-msg <?php echo e(avsfClass($msgType)); ?>"><?php echo esc($msg); ?></div><?php endif; ?>

<div class="avsf-stats">
    <div class="avsf-stat"><div class="avsf-num"><?php echo count($redirects); ?></div><div class="avsf-lbl"><?php _e('Redirect ทั้งหมด'); ?></div></div>
    <div class="avsf-stat"><div class="avsf-num"><?php echo number_format($totalHits); ?></div><div class="avsf-lbl"><?php _e('Hit รวม'); ?></div></div>
    <div class="avsf-stat"><div class="avsf-num"><?php echo count($log404); ?></div><div class="avsf-lbl"><?php _e('404 ที่บันทึก (200 ล่าสุด)'); ?></div></div>
</div>

<div class="avsf-tabs">
    <a class="avsf-tab <?php echo e(avsfClass($tab==='redirects'?'avsf-active':'')); ?>" href="?tab=redirects"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> <?php _e('Redirect ทั้งหมด'); ?>
    <a class="avsf-tab <?php echo e(avsfClass($tab==='add'?'avsf-active':'')); ?>" href="?tab=add"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> <?php _e('เพิ่ม Redirect'); ?>
    <a class="avsf-tab <?php echo e(avsfClass($tab==='404'?'avsf-active':'')); ?>" href="?tab=404"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><line x1="12" y1="10" x2="12" y2="14"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?php _e('บันทึก 404'); ?>
    <a class="avsf-tab <?php echo e(avsfClass($tab==='help'?'avsf-active':'')); ?>" href="?tab=help"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?php _e('คู่มือ'); ?>
</div>

<?php if ($tab === 'add'): ?>
<div class="avsf-card">
    <h3 style="margin-bottom:12px"><?php _e('➕ เพิ่ม Redirect ใหม่'); ?></h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="add_redirect" value="1">
        <div class="avsf-row">
            <div><label><?php _e('🔗 URL เดิม (ที่ต้องการเปลี่ยนทาง)'); ?></label>
            <input name="old_url" required placeholder="<?php echo __('/old-post-slug หรือ https://.../old'); ?>" value="<?php echo esc($_POST['old_url'] ?? ''); ?>"></div>
            <div><label><?php _e('➡️ URL ปลายทาง (เว้นว่างถ้าเลือก 410/451)'); ?></label>
            <input name="new_url" placeholder="<?php echo __('/new-post-slug หรือ https://...'); ?>" value="<?php echo esc($_POST['new_url'] ?? ''); ?>"></div>
            <div style="min-width:0;flex:1"><label><?php _e('🔢 ประเภท Redirect'); ?></label>
            <select name="code">
                <?php foreach ($codeLabels as $code=>$label): ?>
                <option value="<?php echo $code; ?>" <?php echo $code==301?'selected':''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select></div>
        </div>
        <label><?php _e('📝 หมายเหตุ (ไม่แสดงต่อสาธารณะ)'); ?></label>
        <input name="note" placeholder="<?php echo __('เช่น ย้ายจาก slug เก่า ตั้งแต่ 25 ต.ค. 2569'); ?>">
        <div class="avsf-row" style="margin-top:14px">
            <button class="avsf-btn avsf-btn-primary" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <?php _e('บันทึก Redirect'); ?>
            <a class="avsf-btn avsf-btn-ghost" href="?tab=redirects"><?php _e('ดูรายการทั้งหมด'); ?></a>
        </div>
        <p class="avsf-hint"><?php _e('💡 เคล็ดลับ: ใส่แค่ path เช่น'); ?> <code>/old-slug</code> <?php _e('ก็ได้ (ระบบจะจับคู่อัตโนมัติ) หรือใส่ URL เต็มก็ได้ · 301 เป็นค่าเริ่มต้นที่ดีที่สุดสำหรับ SEO'); ?></p>
    </form>
</div>

<?php elseif ($tab === '404'): ?>
<div class="avsf-card">
    <div class="avsf-row" style="justify-content:space-between;margin-bottom:14px">
        <strong><?php _e('🚨 ลิงก์ที่ผู้ใช้เข้าแล้วเจอ 404 (สร้าง redirect ได้จากนี้เลย)'); ?></strong>
        <form method="post" onsubmit="return confirm(<?php echo avsf_js(__('ล้าง log 404 ทั้งหมด?')); ?>)" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="clear_404" value="1">
            <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> <?php _e('ล้าง log'); ?>
        </form>
    </div>
    <?php if (empty($log404)): ?>
        <p style="color:#4ade80;font-size:13px"><?php _e('✅ ยังไม่มี 404 — เว็บของคุณสมบูรณ์แบบ!'); ?></p>
    <?php else: ?>
    <div style="position:relative;margin-bottom:10px"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><input type="search" placeholder="<?php echo __('ค้นหา URL 404...'); ?>" style="width:100%;padding-left:34px" oninput="var q=this.value.toLowerCase();document.querySelectorAll('tr[data-404-row]').forEach(function(r){r.style.display=(r.textContent.toLowerCase().indexOf(q)>=0)?'':'none';});"></div><div style="overflow-x:auto">
    <table>
        <tr><th><?php _e('URL ที่เจอ 404'); ?></th><th><?php _e('ครั้ง'); ?></th><th><?php _e('ครั้งล่าสุด'); ?></th><th><?php _e('สร้าง Redirect'); ?></th></tr>
        <?php foreach ($log404 as $l): ?>
        <tr data-404-row>
            <td class="avsf-url-cell"><?php echo esc($l['path']); ?></td>
            <td><strong><?php echo $l['hits']; ?></strong></td>
            <td style="font-size:11px;color:#9ca3af"><?php echo esc($l['last_hit']); ?></td>
            <td>
                <form method="post" class="avsf-row" style="gap:4px">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="redirect_from_404" value="1">
                    <input type="hidden" name="old_url" value="<?php echo esc($l['path']); ?>">
                    <input name="new_url" placeholder="/new-url" style="min-width:120px;padding:6px;font-size:11px" required>
                    <button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit">→ 301</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'help'): ?>
<div class="avsf-card" style="font-size:13px;line-height:1.8">
    <h3 style="margin-bottom:12px"><?php _e('❓ คู่มือการใช้งาน Redirect'); ?></h3>
    <p><strong>301 Permanent</strong> <?php _e('— ย้ายถาวร ใช้เมื่อเปลี่ยน slug/ลบหน้าแล้วมีหน้าใหม่ทดแทน ✅'); ?> <strong><?php _e('แนะนำสำหรับ SEO'); ?></strong> <?php _e('Google จะโอนค่า SEO ไปยังหน้าใหม่'); ?></p>
    <p><strong>302/307 Temporary</strong> <?php _e('— ย้ายชั่วคราว (เช่น โปรโมชั่นชั่วคราว) Google จะไม่โอนค่า SEO'); ?></p>
    <p><strong>308 Permanent</strong> <?php _e('— เหมือน 301 แต่รักษา method POST (ใช้กับฟอร์ม)'); ?></p>
    <p><strong>410 Gone</strong> <?php _e('— ลบถาวร ไม่มีทางกลับ ไม่มีหน้าทดแทน บอก Google ให้ลบออกจาก index ทันที'); ?></p>
    <p><strong>451</strong> <?php _e('— ไม่พร้อมใช้งานเนื่องจากกฎหมาย/เซ็นเซอร์ (หายาก)'); ?></p>
    <hr style="border-color:var(--border);margin:14px 0">
    <p><strong><?php _e('💡 เมื่อไหร่ต้องสร้าง Redirect?'); ?></strong></p>
    <p><?php _e('• เปลี่ยน slug ของโพสต์ (เช่น จาก'); ?> <code>/old-name</code> <?php _e('เป็น'); ?> <code>/new-name</code><?php _e(') → สร้าง 301 จาก old → new'); ?></p>
    <p><?php _e('• ลบโพสต์แล้วไม่มีทดแทน → ใช้ 410 Gone'); ?></p>
    <p><?php _e('• ตรวจแท็บ "บันทึก 404" เป็นประจำ ถ้ามี URL ไหนคนเข้าบ่อยๆ ให้สร้าง redirect ทันที'); ?></p>
    <p><?php _e('• ระบบจะนับ hit อัตโนมัติทุกครั้งที่มีคนเข้าผ่าน redirect'); ?></p>
</div>

<?php else: ?>
<div class="avsf-card">
    <div class="avsf-row" style="justify-content:space-between;margin-bottom:14px">
        <strong><?php _e('📋 Redirect ทั้งหมด ('); ?><?php echo count($redirects); ?>)</strong>
        <div class="avsf-row" style="gap:6px">
            <a class="avsf-btn avsf-btn-primary avsf-btn-sm" href="?tab=add"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> <?php _e('เพิ่มใหม่'); ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="export_csv" value="1">
                <button class="avsf-btn avsf-btn-ghost avsf-btn-sm" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export CSV
            </form>
        </div>
    </div>
    <?php if (empty($redirects)): ?>
        <p style="color:#9ca3af;font-size:13px"><?php _e('ยังไม่มี redirect —'); ?> <a href="?tab=add" style="color:#60a5fa"><?php _e('เพิ่มตัวแรก'); ?></a> <?php _e('หรือดูจากแท็บ "บันทึก 404"'); ?></p>
    <?php else: ?>
    <div style="position:relative;margin-bottom:10px"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><input type="search" id="redirectFilter" placeholder="<?php echo __('ค้นหา URL...'); ?>" style="width:100%;padding-left:34px" oninput="var q=this.value.toLowerCase();document.querySelectorAll('tr[data-redirect-row]').forEach(function(r){r.style.display=(r.textContent.toLowerCase().indexOf(q)>=0)?'':'none';});"></div><div style="overflow-x:auto">
    <table>
        <tr><th><?php _e('URL เดิม'); ?></th><th>→</th><th><?php _e('ปลายทาง'); ?></th><th><?php _e('ประเภท'); ?></th><th>Hit</th><th><?php _e('หมายเหตุ'); ?></th><th><?php _e('จัดการ'); ?></th></tr>
        <?php foreach ($redirects as $r): ?>
        <tr style="<?php echo $r['active']?'':'opacity:.5'; ?>">
            <td class="avsf-url-cell"><?php echo esc($r['old_url']); ?></td>
            <td>→</td>
            <td class="avsf-url-cell"><?php echo $r['code']==410?'<span style="color:#f87171">410 Gone (ลบ)</span>':esc($r['new_url']); ?></td>
            <td><span class="avsf-badge avsf-c<?php echo $r['code']; ?>"><?php echo $r['code']; ?></span></td>
            <td><strong><?php echo number_format($r['hits']); ?></strong></td>
            <td style="font-size:11px;color:#9ca3af;max-width:120px"><?php echo esc($r['note']); ?></td>
            <td style="white-space:nowrap">
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="toggle_redirect" value="1">
                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                    <button class="avsf-btn avsf-btn-ghost avsf-btn-sm" type="submit"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg> <?php echo $r['active']?__('⏸️ ปิด'):__('▶️ เปิด'); ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm(<?php echo avsf_js(__('ลบ redirect นี้?')); ?>)">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="delete_redirect" value="1">
                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                    <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
