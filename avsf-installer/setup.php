<?php
/**
 * AVSF — First-run Owner setup.
 * The page is available only while the users table is empty.
 * A cryptographically-random password is generated on first display; the user may replace it.
 */
require_once dirname(__DIR__) . '/config.php';
require_once AVSF_CORE_DIR . '/functions.php';
require_once AVSF_CORE_DIR . '/security/security_lib.php';
function avsf_setup_generate_password(int $length = 22): string {
    $sets = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
        '!@#$%^&*()-_=+[]{}:?,.',
    ];
    $pool = implode('', $sets);
    $chars = [];
    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    while (count($chars) < max(16, $length)) {
        $chars[] = $pool[random_int(0, strlen($pool) - 1)];
    }
    // Fisher-Yates shuffle with a cryptographically secure random source.
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}
$userCountResult = mysqli_query($conn, 'SELECT COUNT(*) FROM users');
if (!$userCountResult) {
    error_log('AVSF setup could not count users: ' . mysqli_error($conn));
    http_response_code(500);
    die(__('ฐานข้อมูลยังไม่พร้อม กรุณาตรวจสอบการสร้างตาราง users และสิทธิ์ฐานข้อมูล'));
}
$userCount = (int) mysqli_fetch_row($userCountResult)[0];
mysqli_free_result($userCountResult);
if ($userCount > 0) {
    header('Location: admin-login/index.php', true, 303);
    exit;
}
// The first-Owner wizard must be opened in the same browser session used to configure DB credentials.
// This prevents another visitor from claiming the first Owner account after the database is configured.
$localInstallConfig = is_file(AVSF_ROOT . '/config.local.php') ? require AVSF_ROOT . '/config.local.php' : [];
$expectedInstallNonce = is_array($localInstallConfig) ? (string)($localInstallConfig['install_nonce'] ?? '') : '';
$sessionInstallNonce = (string)($_SESSION['avsf_install_nonce'] ?? '');
if ($expectedInstallNonce !== '' && ($sessionInstallNonce === '' || !hash_equals($expectedInstallNonce, $sessionInstallNonce))) {
    http_response_code(403);
    die(__('หน้านี้ผูกกับเบราว์เซอร์ที่ตั้งค่าฐานข้อมูลไว้ กรุณาเปิดหน้าติดตั้งต่อจากเบราว์เซอร์เดิม หากเปลี่ยนเบราว์เซอร์หรือเซสชันหมดอายุ ให้ลบ config.local.php ผ่าน Hosting File Manager แล้วเริ่ม db-setup.php ใหม่'));
}
$error = '';
$success = false;
$createdUsername = '';
$initialPassword = $_SERVER['REQUEST_METHOD'] === 'GET' ? avsf_setup_generate_password() : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    if (strlen($username) < 3 || strlen($username) > 50) {
        $error = __('ชื่อผู้ใช้ต้องมีความยาว 3–50 ตัวอักษร');
    } elseif (!preg_match('/^[\p{L}\p{N}_.@-]+$/u', $username)) {
        $error = __('ชื่อผู้ใช้ใช้ได้เฉพาะตัวอักษร ตัวเลข จุด ขีดล่าง ขีดกลาง และ @');
    } elseif (strlen($password) < 12) {
        $error = __('รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร แนะนำให้ใช้รหัสที่ระบบสุ่มให้');
    } elseif ($password !== $password2) {
        $error = __('รหัสผ่านทั้งสองช่องไม่ตรงกัน');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = __('อีเมลไม่ถูกต้อง');
    } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s]{6,30}$/', $phone)) {
        $error = __('เบอร์โทรไม่ถูกต้อง');
    } else {
        // Re-check immediately before insert to prevent two simultaneous first-run requests.
        $check = mysqli_query($conn, 'SELECT COUNT(*) FROM users');
        if (!$check) {
            error_log('AVSF setup could not recheck users: ' . mysqli_error($conn));
            $error = __('ตรวจสอบฐานข้อมูลไม่สำเร็จ กรุณาลองใหม่');
        } elseif ((int) mysqli_fetch_row($check)[0] !== 0) {
            mysqli_free_result($check);
            header('Location: admin-login/index.php', true, 303);
            exit;
        } else {
            mysqli_free_result($check);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $emailValue = $email === '' ? null : $email;
            $permissions = '["*"]';
            $stmt = mysqli_prepare($conn, "INSERT INTO users (username,password,role,email,phone,permissions,lastLogin) VALUES (?,?,'owner',?,?,?,NOW())");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sssss', $username, $hash, $emailValue, $phone, $permissions);
                $ok = mysqli_stmt_execute($stmt);
                $dbError = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $ok = false;
                $dbError = mysqli_error($conn);
            }
            if ($ok) {
                saveSetting('setup_complete', '1');
                saveSetting('site_admin_email', $email);
                saveSetting('site_admin_phone', $phone);
                logActivity('ติดตั้งครั้งแรก — สร้าง Owner: ' . $username);
                $createdUsername = $username;
                $success = true;
            } else {
                error_log('AVSF setup could not create the initial Owner: ' . $dbError);
                $error = __('สร้างบัญชีไม่สำเร็จ กรุณาตรวจสอบว่าชื่อผู้ใช้นี้ยังไม่ถูกใช้');
            }
        }
    }
}
$csrf = esc($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?php echo avsf_lang_attr(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php _e('ติดตั้งครั้งแรก — AVSF'); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:radial-gradient(ellipse at 15% 5%,#3b246c 0,transparent 42%),linear-gradient(135deg,#0f0c29,#24243e);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#fff}
.avsf-wrap{max-width:520px;width:100%}.avsf-card{background:rgba(255,255,255,.07);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.13);border-radius:20px;padding:clamp(22px,5vw,32px);box-shadow:0 24px 80px #0005}
.avsf-logo{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#ff2d78,#a855f7);display:grid;place-items:center;font-size:19px;font-weight:900;letter-spacing:.5px;margin:0 auto 16px}
h1{text-align:center;font-size:22px;margin-bottom:7px}.avsf-sub{text-align:center;color:#b7b9d4;font-size:13px;margin-bottom:23px;line-height:1.6}
.avsf-steps{display:flex;gap:7px;justify-content:center;align-items:center;flex-wrap:wrap;margin-bottom:18px}.avsf-step{padding:4px 10px;border-radius:20px;background:#ffffff12;color:#d9d8ed;font-size:11px}.avsf-step.avsf-active{background:#ff2d7830;color:#ffc1dc;border:1px solid #ff79ad66}
.avsf-field{margin:14px 0 0}.avsf-field label{display:block;font-size:12px;font-weight:700;color:#d9d9ef;margin-bottom:7px}.avsf-field input{width:100%;min-width:0;padding:12px 13px;background:#090d1caa;border:1px solid #ffffff2c;border-radius:10px;color:#fff;font-size:14px;font-family:inherit}.avsf-field input:focus{outline:none;border-color:#ff6aa9;box-shadow:0 0 0 3px #ff2d7828}
.avsf-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.avsf-password-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.avsf-tool-btn{padding:8px 11px;border:1px solid #ffffff35;border-radius:9px;background:#ffffff12;color:#f5efff;font-size:12px;font-weight:650;cursor:pointer}.avsf-tool-btn:hover{background:#ffffff22}.avsf-password-note{font-size:11px;color:#b9bfd2;line-height:1.55;margin-top:8px}.avsf-strength{font-size:11px;color:#9ae6b4;margin-top:6px}
.avsf-error{background:#7f1d1d55;border:1px solid #f8717180;color:#fecaca;padding:10px 13px;border-radius:10px;font-size:13px;margin-bottom:14px}.avsf-success{background:#14532d55;border:1px solid #4ade8080;color:#dcfce7;padding:17px;border-radius:12px;font-size:14px;line-height:1.7;text-align:center}.avsf-success a{display:inline-block;margin-top:15px;padding:11px 23px;background:linear-gradient(135deg,#ff2d78,#a855f7);border-radius:10px;color:#fff;text-decoration:none;font-weight:750}
.avsf-submit{width:100%;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,#ff2d78,#a855f7);color:#fff;font-weight:800;font-size:15px;cursor:pointer;margin-top:22px}.avsf-submit:hover{filter:brightness(1.08)}.avsf-hint{font-size:11px;color:#aeb4ca;margin-top:10px;line-height:1.6}.avsf-footer{text-align:center;color:#7f86a3;font-size:11px;margin-top:15px}
@media(max-width:520px){.avsf-grid{grid-template-columns:1fr}.avsf-steps{gap:5px}.avsf-step{font-size:10px}.avsf-card{padding:21px}}
</style>
</head>
<body>
<main class="avsf-wrap">
  <section class="avsf-card">
    <div class="avsf-logo">AVSF</div>
    <h1><?php _e('ติดตั้ง AVSF ครั้งแรก'); ?></h1>
    <p class="avsf-sub"><?php _e('เชื่อมต่อฐานข้อมูลและสร้างตารางเรียบร้อยแล้ว ขั้นตอนนี้สร้างบัญชี Owner คนแรกของเว็บไซต์'); ?></p>
    <div class="avsf-steps" aria-label="<?php echo esc(__('ขั้นตอนติดตั้ง')); ?>">
      <span class="avsf-step"><?php _e('✓ เชื่อมต่อฐานข้อมูล'); ?></span><span aria-hidden="true">→</span>
      <span class="avsf-step"><?php _e('✓ สร้างตาราง'); ?></span><span aria-hidden="true">→</span>
      <span class="avsf-step avsf-active"><?php _e('3 สร้าง Owner'); ?></span>
    </div>
    <?php if ($success): ?>
      <div class="avsf-success" role="status">
        <strong><?php _e('ติดตั้งสำเร็จแล้ว!'); ?></strong><br>
        <?php printf(__('สร้างบัญชี Owner %s เรียบร้อยแล้ว'), '<strong>'.esc($createdUsername).'</strong>'); ?><br>
        <?php _e('เมื่อเข้าสู่ระบบครั้งแรก จะต้องตั้งค่าและยืนยัน 2FA ก่อนเข้าใช้งานหลังบ้าน'); ?><br>
        <?php _e('รหัสผ่านถูกบันทึกเป็น Hash อย่างปลอดภัย ระบบไม่สามารถแสดงรหัสผ่านเดิมซ้ำได้'); ?><br>
        <strong><?php _e('โปรดใช้รหัสผ่านที่คัดลอก/บันทึกไว้ก่อนกดยืนยันติดตั้ง'); ?></strong><br>
        <a href="admin-login/index.php"><?php _e('เข้าสู่ระบบหลังบ้าน →'); ?></a>
      </div>
    <?php else: ?>
      <?php if ($error !== ''): ?><div class="avsf-error" role="alert">⚠️ <?= esc($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off" id="avsf-owner-setup-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="avsf-field">
          <label for="username"><?php _e('ชื่อผู้ใช้ Owner'); ?></label>
          <input id="username" name="username" maxlength="50" minlength="3" required autocomplete="username" placeholder="<?php echo esc(__('เช่น siteowner')); ?>" value="<?= esc($_POST['username'] ?? '') ?>">
        </div>
        <div class="avsf-field">
          <label for="password"><?php _e('รหัสผ่าน Owner (อย่างน้อย 12 ตัวอักษร)'); ?></label>
          <input id="password" name="password" type="password" minlength="12" required autocomplete="new-password" spellcheck="false" value="<?= esc($initialPassword) ?>">
          <div class="avsf-password-tools">
            <button class="avsf-tool-btn" type="button" id="avsf-generate-password"><?php _e('สุ่มรหัสผ่านใหม่'); ?></button>
            <button class="avsf-tool-btn" type="button" id="avsf-toggle-password" aria-pressed="false"><?php _e('แสดงรหัสผ่าน'); ?></button>
            <button class="avsf-tool-btn" type="button" id="avsf-copy-password"><?php _e('คัดลอกรหัสผ่าน'); ?></button>
          </div>
          <div class="avsf-strength" id="avsf-password-strength"><?php _e('ระบบสุ่มรหัสผ่านให้แล้ว 22 ตัวอักษร มีตัวพิมพ์เล็ก/ใหญ่ ตัวเลข และสัญลักษณ์'); ?></div>
          <p class="avsf-password-note"><?php _e('คัดลอกและเก็บรหัสผ่านไว้ในที่ปลอดภัยก่อนกดยืนยัน หากลืมรหัสผ่าน ระบบจะไม่สามารถกู้รหัสผ่านเดิมกลับมาแสดงได้'); ?></p>
        </div>
        <div class="avsf-field">
          <label for="password2"><?php _e('ยืนยันรหัสผ่าน'); ?></label>
          <input id="password2" name="password2" type="password" minlength="12" required autocomplete="new-password" spellcheck="false" value="<?= esc($initialPassword) ?>">
        </div>
        <div class="avsf-grid">
          <div class="avsf-field"><label for="email"><?php _e('อีเมลสำหรับกู้คืน (แนะนำ)'); ?></label><input id="email" name="email" type="email" maxlength="150" autocomplete="email" placeholder="you@example.com" value="<?= esc($_POST['email'] ?? '') ?>"></div>
          <div class="avsf-field"><label for="phone"><?php _e('เบอร์โทร (ไม่บังคับ)'); ?></label><input id="phone" name="phone" maxlength="30" autocomplete="tel" placeholder="08x-xxx-xxxx" value="<?= esc($_POST['phone'] ?? '') ?>"></div>
        </div>
        <p class="avsf-hint"><?php _e('บัญชีนี้จะได้รับสิทธิ์ Owner เต็มระบบ ห้ามส่งรหัสผ่านให้บุคคลอื่น และควรเปิดใช้งาน HTTPS ก่อนเข้าสู่ระบบ'); ?></p>
        <button class="avsf-submit" type="submit"><?php _e('สร้างบัญชี Owner และเริ่มใช้งาน'); ?></button>
      </form>
    <?php endif; ?>
  </section>
  <p class="avsf-footer">AVSF · <?php _e('หน้าติดตั้งจะถูกล็อกอัตโนมัติเมื่อมีบัญชีผู้ใช้แล้ว'); ?></p>
</main>
<script>
(() => {
  'use strict';
  const password = document.getElementById('password');
  const confirmPassword = document.getElementById('password2');
  const generateButton = document.getElementById('avsf-generate-password');
  const toggleButton = document.getElementById('avsf-toggle-password');
  const copyButton = document.getElementById('avsf-copy-password');
  const strength = document.getElementById('avsf-password-strength');
  if (!password || !confirmPassword || !generateButton) return;
  const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*()-_=+[]{}:?,.'];
  const pool = sets.join('');
  function secureIndex(max) {
    if (!window.crypto || !window.crypto.getRandomValues) throw new Error('Secure random is unavailable');
    const limit = Math.floor(0x100000000 / max) * max;
    const value = new Uint32Array(1);
    do { window.crypto.getRandomValues(value); } while (value[0] >= limit);
    return value[0] % max;
  }
  function randomPassword(length = 22) {
    const chars = sets.map(set => set[secureIndex(set.length)]);
    while (chars.length < length) chars.push(pool[secureIndex(pool.length)]);
    for (let i = chars.length - 1; i > 0; i--) {
      const j = secureIndex(i + 1);
      [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
  }
  function applyPassword(value) {
    password.value = value;
    confirmPassword.value = value;
    strength.textContent = <?php echo avsf_js(__('รหัสผ่าน 22 ตัวอักษร · ตัวพิมพ์เล็ก/ใหญ่ · ตัวเลข · สัญลักษณ์')); ?>;
  }
  generateButton.addEventListener('click', () => {
    try { applyPassword(randomPassword(22)); }
    catch (error) { strength.textContent = <?php echo avsf_js(__('ไม่สามารถสุ่มรหัสผ่านในเบราว์เซอร์นี้ได้ โปรดใช้ HTTPS หรือรหัสผ่านที่สร้างจากเครื่องมือที่ปลอดภัย')); ?>; }
  });
  if (toggleButton) toggleButton.addEventListener('click', () => {
    const reveal = password.type === 'password';
    password.type = reveal ? 'text' : 'password';
    confirmPassword.type = reveal ? 'text' : 'password';
    toggleButton.textContent = reveal ? <?php echo avsf_js(__('ซ่อนรหัสผ่าน')); ?> : <?php echo avsf_js(__('แสดงรหัสผ่าน')); ?>;
    toggleButton.setAttribute('aria-pressed', reveal ? 'true' : 'false');
  });
  if (copyButton) copyButton.addEventListener('click', async () => {
    if (!password.value) { strength.textContent = <?php echo avsf_js(__('ไม่มีรหัสผ่านให้คัดลอก')); ?>; return; }
    let copied = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(password.value);
        copied = true;
      }
    } catch (error) { /* Try the local selection fallback below. */ }
    if (!copied) {
      const previousType = password.type;
      try {
        password.type = 'text'; password.focus(); password.select();
        copied = document.execCommand('copy');
        password.setSelectionRange(password.value.length, password.value.length);
      } catch (error) { copied = false; }
      password.type = previousType;
    }
    strength.textContent = copied
      ? <?php echo avsf_js(__('คัดลอกรหัสผ่านแล้ว โปรดเก็บไว้ในที่ปลอดภัย')); ?>
      : <?php echo avsf_js(__('คัดลอกอัตโนมัติไม่ได้ กรุณากดแสดงรหัสผ่านแล้วคัดลอกด้วยตนเอง')); ?>;
  });
  const form = document.getElementById('avsf-owner-setup-form');
  if (form) form.addEventListener('submit', (event) => {
    if (password.value !== confirmPassword.value) {
      event.preventDefault(); strength.textContent = <?php echo avsf_js(__('รหัสผ่านทั้งสองช่องไม่ตรงกัน')); ?>; confirmPassword.focus();
    }
  });
})();
</script>
</body>
