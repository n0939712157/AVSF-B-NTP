<?php
/**
 * AVSF — สมัครสมาชิกสาธารณะ (เปิด/ปิดโดย Super Admin)
 * ถ้า setting 'registration_enabled' = '1' → เปิดให้สมัครได้
 * สมาชิกที่สมัครเองจะได้บทบาท 'user' (ธรรมดา) มีสิทธิ์ตาม default ที่ Super Admin ตั้งไว้
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
// ====== ลิงก์สมัครลับ (Private Invite Link) — ต้องมี key ที่ถูกต้องเท่านั้น ======
// ถ้าไม่มี key หรือ key ไม่ถูก → แสดง 404 เหมือนหน้าไม่มีอยู่จริง (คนนอกจะไม่รู้ว่ามีหน้าสมัคร)
$secretKey = setting('registration_secret_key', '');
$providedKey = $_GET['key'] ?? '';
if ($secretKey === '' || !hash_equals($secretKey, $providedKey)) {
    http_response_code(404);
    die('<!DOCTYPE html><html lang="'.avsf_lang_attr().'"><head><meta charset="UTF-8"><title>404 Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:80px;"><h1>404</h1><p>'.__('หน้านี้ไม่มีอยู่จริง').'</p><a href="/" style="color:#2563eb">'.__('← กลับหน้าแรก').'</a></body></html>');
}
// ถ้ามี key ถูกต้อง → แสดงฟอร์มสมัคร (key จะติดอยู่ใน URL ตลอด)
$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    sec_rate_limit('register', 3); // จำกัด 3 ครั้ง/นาที
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $email = trim($_POST['email'] ?? '');
    if (strlen($username) < 3) $error = __('ชื่อผู้ใช้อย่างน้อย 3 ตัว');
    elseif (strlen($password) < 8) $error = __('รหัสผ่านอย่างน้อย 8 ตัว');
    elseif ($password !== $password2) $error = __('รหัสผ่านไม่ตรงกัน');
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = __('อีเมลไม่ถูกต้อง');
    else {
        $uE = mysqli_real_escape_string($conn, $username);
        $eE = mysqli_real_escape_string($conn, $email);
        $exist = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM users WHERE username='$uE' OR email='$eE' LIMIT 1"));
        if ($exist) {
            $error = __('ชื่อผู้ใช้หรืออีเมลนี้มีอยู่แล้ว');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $defaultPerms = setting('default_register_permissions', '["posts.view","posts.create","posts.edit"]');
            mysqli_query($conn, "INSERT INTO users (username,password,email,role,permissions) VALUES ('$uE','$hash','$eE','user','$defaultPerms')");
            logActivity("สมัครสมาชิกใหม่: $username");
            $success = true;
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
<title><?php _e('📝 สมัครสมาชิก — AVSF'); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Kanit,sans-serif;background:#0a0a12;color:#e5e7eb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.avsf-card{background:#13131f;border:1px solid #27273a;border-radius:16px;padding:32px;max-width:400px;width:100%}
h1{font-size:20px;text-align:center;margin-bottom:6px}
.avsf-sub{text-align:center;color:#9ca3af;font-size:12px;margin-bottom:20px}
.avsf-lang-switch{text-align:center;margin-bottom:14px;font-size:12px}
label{display:block;font-size:12px;font-weight:600;color:#9ca3af;margin:12px 0 5px}
input{width:100%;padding:11px 13px;background:#0c0c15;border:1px solid #27273a;border-radius:9px;color:#fff;font-size:13px;font-family:inherit}
input:focus{outline:none;border-color:#ff2d78}
button{width:100%;padding:13px;border:none;border-radius:10px;background:linear-gradient(135deg,#ff2d78,#a855f7);color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:18px;font-family:inherit}
.avsf-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}
.avsf-ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80;padding:16px;border-radius:12px;font-size:13px;text-align:center}
a{color:#60a5fa;text-decoration:none;font-size:13px}
@media (prefers-color-scheme: light){
  body{background:#f3f6fb;color:#19304f}
  .avsf-card{background:#fff;border-color:#dfe6f0;box-shadow:0 4px 14px rgba(23,48,79,.06)}
  .avsf-sub,label{color:#667790}
  input{background:#fff;border-color:#dfe6f0;color:#19304f}
  input:focus{border-color:#267cf4}
  a{color:#267cf4}
  .avsf-ok small{color:#667790}
}
</style>
</head>
<body>
<div class="avsf-card">
  <div class="avsf-lang-switch"><?php echo avsf_lang_switcher_html(); ?></div>
  <h1><?php _e('📝 สมัครสมาชิก'); ?></h1>
  <p class="avsf-sub"><?php _e('สมัครเสร็จจะได้สิทธิ์ขั้นพื้นฐานตามที่ผู้ดูแลระบบกำหนด'); ?></p>
  <?php if ($success): ?>
    <div class="avsf-ok"><?php _e('✅ สมัครสำเร็จ! ตอนนี้สามารถ'); ?> <a href="admin-login/index.php"><?php _e('เข้าสู่ระบบ'); ?></a> <?php _e('ได้เลย'); ?><br><small style="color:#9ca3af"><?php _e('หมายเหตุ: ทุกแอดมินจะต้องตั้งค่า 2FA (Google Authenticator) ก่อนใช้งาน'); ?></small></div>
  <?php else: ?>
    <?php if ($error): ?><div class="avsf-err">⚠️ <?php echo esc($error); ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <input type="hidden" name="reg_key" value="<?php echo esc($_GET['key'] ?? ''); ?>">
      <label><?php _e('👤 ชื่อผู้ใช้'); ?></label>
      <input name="username" required value="<?php echo esc($_POST['username'] ?? ''); ?>">
      <label><?php _e('📧 อีเมล (สำหรับกู้รหัสผ่าน)'); ?></label>
      <input name="email" type="email" required value="<?php echo esc($_POST['email'] ?? ''); ?>">
      <label><?php _e('🔑 รหัสผ่าน (อย่างน้อย 8 ตัว)'); ?></label>
      <input name="password" type="password" required>
      <label><?php _e('🔑 ยืนยันรหัสผ่าน'); ?></label>
      <input name="password2" type="password" required>
      <button type="submit"><?php _e('✅ สมัครสมาชิก'); ?></button>
    </form>
    <p style="text-align:center;margin-top:16px"><?php _e('มีบัญชีอยู่แล้ว?'); ?> <a href="admin-login/index.php"><?php _e('เข้าสู่ระบบ'); ?></a></p>
  <?php endif; ?>
</div>
</body>
</html>
