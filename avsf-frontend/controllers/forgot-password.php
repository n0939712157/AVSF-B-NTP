<?php
/**
 * AVSF — ลืมรหัสผ่าน (กู้ผ่านอีเมล)
 * ใช้เมล์ของเซิร์ฟเวอร์ (mail()) — ถ้าโฮสติ้งรองรับ
 * ถ้าไม่รองรับ ให้ Super Admin เปลี่ยนรหัสให้ที่ admin/users.php แทน
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
$step = $_GET['step'] ?? 'request';
$msg = '';
$msgType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    sec_rate_limit('forgot', 3);
    if ($step === 'request') {
        $email = trim($_POST['email'] ?? '');
        $eE = mysqli_real_escape_string($conn, $email);
        $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id,username FROM users WHERE email='$eE' LIMIT 1"));
        if ($u) {
            // สร้าง reset token ใช้ได้ 1 ชั่วโมง
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $resetStmt = mysqli_prepare($conn, 'UPDATE users SET reset_token=?, reset_expires=? WHERE id=?');
            mysqli_stmt_bind_param($resetStmt, 'ssi', $token, $expires, $u['id']);
            mysqli_stmt_execute($resetStmt);
            mysqli_stmt_close($resetStmt);
            $canonicalBase = rtrim(avsfCanonicalBaseUrl(), '/');
            $resetLink = $canonicalBase . '/forgot-password.php?step=reset&token=' . rawurlencode($token);
            $subject = __('กู้รหัสผ่าน AVSF');
            $body = __('สวัสดี %s,

คุณได้ขอกู้รหัสผ่าน คลิกลิงก์นี้เพื่อตั้งรหัสใหม่ (ใช้ได้ 1 ชั่วโมง):
%s

ถ้าไม่ใช่คุณที่ขอ ให้เพิกเฉยอีเมลนี้', [$u['username'], $resetLink]);
            $mailHost = preg_replace('/[^a-z0-9.-]/i', '', parse_url($canonicalBase, PHP_URL_HOST) ?: 'localhost');
            $headers = 'From: noreply@' . $mailHost . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
            if (@mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers)) {
                $msg = __('✅ ส่งลิงก์กู้รหัสผ่านไปยังอีเมลแล้ว กรุณาตรวจสอบ (รวมถึงกล่อง Spam)');
                $msgType = 'success';
            } else {
                $msg = __('⚠️ เซิร์ฟเวอร์ไม่สามารถส่งอีเมลได้ กรุณาติดต่อ Super Admin เพื่อขอรีเซ็ตรหัสผ่าน (ที่หน้า จัดการผู้ใช้)');
                $msgType = 'error';
            }
        } else {
            $msg = __('⚠️ ไม่พบอีเมลนี้ในระบบ');
            $msgType = 'error';
        }
    } elseif ($step === 'reset' && !empty($_GET['token'])) {
        $token = $_GET['token'];
        $tE = mysqli_real_escape_string($conn, $token);
        $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id,username FROM users WHERE reset_token='$tE' AND reset_expires > NOW() LIMIT 1"));
        if (!$u) {
            $msg = __('⚠️ ลิงก์ไม่ถูกต้องหรือหมดอายุ กรุณาขอกู้รหัสผ่านใหม่');
            $msgType = 'error';
            $step = 'request';
        } else {
            $newPass = $_POST['new_password'] ?? '';
            if (strlen($newPass) < 8) {
                $msg = __('⚠️ รหัสผ่านอย่างน้อย 8 ตัว');
                $msgType = 'error';
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $resetPasswordStmt = mysqli_prepare($conn, 'UPDATE users SET password=?, reset_token=\'\', reset_expires=NULL WHERE id=?');
                mysqli_stmt_bind_param($resetPasswordStmt, 'si', $hash, $u['id']);
                mysqli_stmt_execute($resetPasswordStmt);
                mysqli_stmt_close($resetPasswordStmt);
                $msg = __('✅ เปลี่ยนรหัสผ่านเรียบร้อย ตอนนี้สามารถ') . ' <a href="admin-login/index.php">' . __('เข้าสู่ระบบ') . '</a> ' . __('ได้');
                $msgType = 'success';
                $step = 'done';
            }
        }
    }
}
$csrf = esc($_SESSION['csrf_token']);
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php _e('🔑 ลืมรหัสผ่าน — AVSF'); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Kanit,sans-serif;background:#0a0a12;color:#e5e7eb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.avsf-card{background:#13131f;border:1px solid #27273a;border-radius:16px;padding:32px;max-width:400px;width:100%}
h1{font-size:20px;text-align:center;margin-bottom:6px}
.avsf-sub{text-align:center;color:#9ca3af;font-size:12px;margin-bottom:20px}
.avsf-lang-switch{text-align:center;margin-bottom:14px;font-size:12px}
label{display:block;font-size:12px;font-weight:600;color:#9ca3af;margin:12px 0 5px}
input{width:100%;padding:11px 13px;background:#0c0c15;border:1px solid #27273a;border-radius:9px;color:#fff;font-size:13px;font-family:inherit}
button{width:100%;padding:13px;border:none;border-radius:10px;background:linear-gradient(135deg,#ff2d78,#a855f7);color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:18px;font-family:inherit}
.avsf-msg{padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}
.avsf-msg.avsf-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.avsf-msg.avsf-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}
.avsf-msg.avsf-info{background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);color:#60a5fa}
a{color:#60a5fa;text-decoration:none;font-size:13px}
@media (prefers-color-scheme: light){
  body{background:#f3f6fb;color:#19304f}
  .avsf-card{background:#fff;border-color:#dfe6f0;box-shadow:0 4px 14px rgba(23,48,79,.06)}
  .avsf-sub,label{color:#667790}
  input{background:#fff;border-color:#dfe6f0;color:#19304f}
  input:focus{border-color:#267cf4}
  a{color:#267cf4}
}
</style>
</head>
<body>
<div class="avsf-card">
  <div class="avsf-lang-switch"><?php echo avsf_lang_switcher_html(); ?></div>
  <h1><?php _e('🔑 ลืมรหัสผ่าน'); ?></h1>
  <p class="avsf-sub"><?php _e('กรอกอีเมลที่ลงทะเบียนไว้ ระบบจะส่งลิงก์ตั้งรหัสใหม่ให้'); ?></p>
  <?php if ($msg): ?><div class="avsf-msg <?php echo e(avsfClass($msgType)); ?>"><?php echo $msg; ?></div><?php endif; ?>
  <?php if ($step === 'request'): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <label><?php _e('📧 อีเมลที่ลงทะเบียน'); ?></label>
      <input name="email" type="email" required placeholder="you@example.com">
      <button type="submit"><?php _e('📨 ส่งลิงก์กู้รหัสผ่าน'); ?></button>
    </form>
  <?php elseif ($step === 'reset'): ?>
    <form method="post" action="forgot-password.php?step=reset&token=<?php echo urlencode($_GET['token'] ?? ''); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <label><?php _e('🔑 รหัสผ่านใหม่ (อย่างน้อย 8 ตัว)'); ?></label>
      <input name="new_password" type="password" required>
      <button type="submit"><?php _e('💾 ตั้งรหัสผ่านใหม่'); ?></button>
    </form>
  <?php endif; ?>
  <p style="text-align:center;margin-top:16px"><a href="admin-login/index.php"><?php _e('← กลับหน้าเข้าสู่ระบบ'); ?></a></p>
</div>
</body>
</html>
