<?php
/**
 * Normalize the public admin alias to a trailing-slash URL.
 * Relative admin links (index.php?page=..., admin-ui.css, site-center.php)
 * otherwise resolve from the website root when the browser URL is
 * /admin-login without its trailing slash. That causes 404s and missing CSS.
 * Preserve the query string and POST method during canonicalization.
 */
$avsfPublicRequestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$avsfPublicRequestPath = (string)(parse_url($avsfPublicRequestUri, PHP_URL_PATH) ?? '/');
if (preg_match('~^(.*)/admin-login$~i', $avsfPublicRequestPath, $avsfAdminAliasMatch)) {
    $avsfAdminPrefix = rtrim((string)($avsfAdminAliasMatch[1] ?? ''), '/');
    $avsfAdminCanonicalPath = $avsfAdminPrefix . '/admin-login/';
    $avsfAdminQuery = parse_url($avsfPublicRequestUri, PHP_URL_QUERY);
    if (is_string($avsfAdminQuery) && $avsfAdminQuery !== '') {
        $avsfAdminCanonicalPath .= '?' . $avsfAdminQuery;
    }
    header('Location: ' . $avsfAdminCanonicalPath, true, 308);
    exit;
}

require_once dirname(__DIR__) . '/config.php';

require_once AVSF_CORE_DIR . '/functions.php';

require_once AVSF_CORE_DIR . '/security/security_lib.php';

require_once AVSF_CORE_DIR . '/auth/permissions.php';

require_once AVSF_CORE_DIR . '/auth/totp.php';

require_once AVSF_CORE_DIR . '/import/post_import_parser.php';
require_once AVSF_CORE_DIR . '/import/avsf-image-matcher.php';
require_once AVSF_CORE_DIR . '/analytics/recent_page_views.php';
require_once AVSF_CORE_DIR . '/cache/cache_lib.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';

ensureCategoriesTable($conn);

ensurePrimarySlugs($conn);

ensureSplitTaxonomyNames($conn);
ensurePostsUpdatedAtColumn($conn);
ensurePagesSeoColumns($conn);

foreach(['coverAlt'=>'VARCHAR(255) DEFAULT NULL','posterAlt'=>'VARCHAR(255) DEFAULT NULL','galleryAlt'=>'TEXT DEFAULT NULL'] as $column=>$definition){
    $columnExists=mysqli_query($conn,"SHOW COLUMNS FROM posts LIKE '$column'");
    if($columnExists && mysqli_num_rows($columnExists)==0) mysqli_query($conn,"ALTER TABLE posts ADD COLUMN `$column` $definition");
}

header('X-Robots-Tag: noindex, nofollow, noarchive', true);

header('Cache-Control: no-store, private', true);

header('X-Content-Type-Options: nosniff', true);

header('Referrer-Policy: same-origin', true);

$accent=setting('admin_accent',setting('theme_accent','#ff2d78'));

if(!preg_match('/^#[0-9a-f]{6}$/i',$accent))$accent='#ff2d78';

$msg = isset($_SESSION['avsf_admin_notice']) ? $_SESSION['avsf_admin_notice'] : '';
if (isset($_GET['2fa_locked'])) $msg = __('ใส่รหัส 2FA ผิดเกินกำหนด กรุณาเข้าสู่ระบบใหม่');
if (isset($_GET['2fa_required'])) $msg = __('เซสชันเดิมหมดสิทธิ์ กรุณาเข้าสู่ระบบและยืนยัน 2FA อีกครั้ง');
$msgType = 'success';

unset($_SESSION['avsf_admin_notice']);



// ---- LOGIN / LOGOUT ----

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

// ====== Mandatory 2FA: verify existing authenticator or enroll on first login ======
function avsfFinishAdminLogin($user) {
    global $conn;
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) { session_destroy(); header('Location: index.php'); exit; }
    session_regenerate_id(true);
    loadUserPermissions($uid, $conn);
    // Ordinary site members must never be promoted into the admin area by a valid password/2FA.
    if (!in_array((string)($_SESSION['user_role'] ?? ''), ['owner','super_admin','deputy_admin','editor','moderator','author','custom'], true)) {
        $_SESSION = [];
        session_destroy();
        http_response_code(403);
        exit(__('บัญชีนี้ไม่มีสิทธิ์เข้าสู่ระบบหลังบ้าน กรุณาใช้หน้าสมาชิกของเว็บไซต์'));
    }
    // The admin session is created only after a successful TOTP verification.
    // The marker is bound to this account and is required by isAdmin() on every
    // subsequent backend request. Old sessions without this marker are rejected.
    $_SESSION['avsf_admin'] = 1;
    $_SESSION['avsf_admin_id'] = $uid;
    $_SESSION['avsf_admin_username'] = (string)$user['username'];
    $_SESSION['avsf_2fa_verified_user_id'] = $uid;
    $_SESSION['avsf_2fa_verified_at'] = time();
    $_SESSION['avsf_2fa_auth_method'] = 'totp';
    mysqli_query($conn, 'UPDATE users SET lastLogin=NOW() WHERE id='.$uid);
    if (function_exists('sec_log_login_attempt')) sec_log_login_attempt((string)$user['username'], true, $conn);
    unset($_SESSION['2fa_pending_id'], $_SESSION['2fa_enroll_id'], $_SESSION['2fa_enroll_secret'], $_SESSION['2fa_enroll_username'], $_SESSION['2fa_failures'], $_SESSION['2fa_error']);
    if (function_exists('logActivity')) logActivity('เข้าสู่ระบบหลังยืนยัน 2FA');
    header('Location: index.php?page=dashboard', true, 303);
    exit;
}

function avsfRenderTwoFactorScreen($mode, $username, $secret = '', $error = '') {
    $brand = function_exists('avsfBrandName') ? avsfBrandName() : 'AVSF';
    $isEnroll = $mode === 'enroll';
    $safeBrand = esc($brand);
    $safeUser = esc($username);
    $safeError = $error !== '' ? '<div class="avsf-error">'.esc($error).'</div>' : '';
    $otpauth = $isEnroll ? AVSFTOTP::getOTPAuthUri($secret, $username, $brand) : '';
    $qrBasePath = function_exists('avsfCanonicalBasePath') ? (string)avsfCanonicalBasePath() : '';
    $qrBasePath = '/' . trim($qrBasePath, '/');
    if ($qrBasePath === '/') $qrBasePath = '';
    $qrAssetUrl = $qrBasePath . '/admin-login/assets/avsf-qrcode.js?v=3.0.0';
    $secretBlock = $isEnroll ? '<div class="avsf-qr-panel"><div class="avsf-secret-label">'.__('1. สแกน QR Code ด้วย Google Authenticator / Authy').'</div><div id="avsf-admin-2fa-qr" class="avsf-qr-code" data-otpauth="'.esc($otpauth).'" role="img" aria-label="'.__('QR Code สำหรับตั้งค่า 2FA').'"><span>'.__('กำลังสร้าง QR Code…').'</span></div><p class="avsf-note">'.__('QR Code สร้างภายในเบราว์เซอร์ของเว็บไซต์นี้ Secret จะไม่ถูกส่งไปยังบริการ QR ภายนอก').'</p><div class="avsf-secret-label">'.__('หรือใช้ Secret Key หากสแกนไม่ได้').'</div><code class="avsf-secret">'.esc($secret).'</code></div>' : __('<p class="avsf-note">'.__('เปิดแอป Authenticator ของบัญชีนี้ แล้วกรอกรหัสปัจจุบัน 6 หลัก').'</p>');
    $field = $isEnroll ? '<input type="hidden" name="twofa_enroll" value="1">' : '<input type="hidden" name="twofa_verify" value="1">';
    $title = $isEnroll ? __('ตั้งค่า 2FA ก่อนเข้าใช้งานครั้งแรก') : __('ยืนยันตัวตน 2 ขั้นตอน');
    $button = $isEnroll ? __('ยืนยันและเปิดใช้งาน 2FA') : __('ยืนยันและเข้าสู่ระบบ');
    echo '<!doctype html><html lang="'.avsf_lang_attr().'"><head><script>try{var t=localStorage.getItem("avsf-admin-theme")||"light";document.documentElement.setAttribute("data-admin-theme",t);document.documentElement.className="avsf-theme-"+t;}catch(e){document.documentElement.setAttribute("data-admin-theme","light");}</script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>'.esc($title).' — '.$safeBrand.'</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;background:#f4f6fb;color:#172033;font:15px/1.65 system-ui,-apple-system,"Noto Sans Thai",sans-serif}.card{width:min(100%,500px);background:#fff;border:1px solid #dce3ee;border-radius:20px;padding:clamp(20px,5vw,32px);box-shadow:0 16px 48px #17203312}.brand{font-size:12px;font-weight:900;letter-spacing:.08em;color:#e83e83}.title{font-size:22px;font-weight:850;margin:8px 0}.sub,.note{color:#64748b;font-size:13px}.secret-label{font-size:13px;font-weight:750;margin:20px 0 8px}.secret{display:block;overflow-wrap:anywhere;background:#f1f4f9;border:1px solid #dce3ee;border-radius:12px;padding:16px;text-align:center;letter-spacing:.13em;font-size:17px;font-weight:800;color:#18243a}.avsf-qr-panel{margin:18px 0 8px;text-align:center}.avsf-qr-code{display:flex;align-items:center;justify-content:center;min-height:244px;margin:12px auto;padding:12px;width:min(100%,280px);background:#fff;border:1px solid #dce3ee;border-radius:16px}.avsf-qr-code svg{display:block;width:100%;height:auto;max-width:256px;shape-rendering:crispEdges}.avsf-qr-code .qr-error{color:#b91c1c;font-size:13px;line-height:1.5}.field{margin:18px 0}.field label{display:block;font-size:13px;font-weight:750;margin-bottom:7px}.field input{width:100%;padding:15px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#172033;font-size:24px;letter-spacing:.35em;text-align:center}.btn{width:100%;border:0;border-radius:12px;padding:14px;background:linear-gradient(110deg,#f52b7a,#a64de5);color:#fff;font-size:15px;font-weight:800}.error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c;border-radius:10px;padding:10px 12px;margin:14px 0}.logout{display:block;text-align:center;margin-top:18px;color:#64748b;font-size:13px;text-decoration:none}.note{margin:12px 0 0}[data-admin-theme="dark"],.avsf-theme-dark{body{background:#090b10;color:#e8edf7}.card{background:#121722;border-color:#2a3547}.sub,.note,.logout{color:#9ba8bb}.secret{background:#192131;border-color:#344157;color:#f3f6fb}.field input{background:#0d1320;border-color:#344157;color:#f3f6fb}}</style></head><body><main class="card"><div class="brand" style="display:flex;align-items:center;gap:8px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 11 4.6-2.5 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg><span>'.$safeBrand.' · ACCOUNT SECURITY</span></div><h1 class="title">'.esc($title).'</h1><p class="sub">'.__('บัญชี:').' <strong>'.$safeUser.'</strong></p>'.$safeError.$secretBlock.'<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="'.esc($_SESSION['csrf_token'] ?? '').'">'.$field.'<div class="field"><label for="twofa_code">'.__('รหัสยืนยัน 6 หลัก').'</label><input id="twofa_code" name="twofa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required autofocus></div><button class="btn" type="submit">'.esc($button).'</button></form><a class="logout" href="?logout=1">'.__('ยกเลิกและออกจากระบบ').'</a></main><script src="'.esc($qrAssetUrl).'"></script></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['twofa_verify']) || isset($_POST['twofa_enroll']))) {
    if (empty($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        session_destroy(); header('Location: index.php'); exit;
    }
    $enrolling = isset($_POST['twofa_enroll']);
    $uid = $enrolling ? (int)($_SESSION['2fa_enroll_id'] ?? 0) : (int)($_SESSION['2fa_pending_id'] ?? 0);
    $secret = $enrolling ? (string)($_SESSION['2fa_enroll_secret'] ?? '') : '';
    if ($uid <= 0 || ($enrolling && $secret === '')) { session_destroy(); header('Location: index.php'); exit; }
    $pendingResult = mysqli_query($conn, 'SELECT * FROM users WHERE id='.(int)$uid.' LIMIT 1');
    $pendingUser = $pendingResult ? mysqli_fetch_assoc($pendingResult) : null;
    if ($pendingResult) mysqli_free_result($pendingResult);
    $code = preg_replace('/[^0-9]/', '', (string)($_POST['twofa_code'] ?? ''));
    $verifySecret = $enrolling ? $secret : (string)($pendingUser['twofa_secret'] ?? '');
    $valid = $pendingUser && strlen($code) === 6 && AVSFTOTP::verifyCode($verifySecret, $code);
    if (!$valid) {
        $_SESSION['2fa_failures'] = (int)($_SESSION['2fa_failures'] ?? 0) + 1;
        if ($pendingUser && function_exists('sec_log_login_attempt')) sec_log_login_attempt((string)$pendingUser['username'], false, $conn);
        if ($_SESSION['2fa_failures'] >= 5) { unset($_SESSION['2fa_pending_id'], $_SESSION['2fa_enroll_id'], $_SESSION['2fa_enroll_secret'], $_SESSION['2fa_enroll_username'], $_SESSION['2fa_failures']); header('Location: index.php?2fa_locked=1'); exit; }
        $_SESSION['2fa_error'] = __('รหัสไม่ถูกต้อง กรุณารอรหัสใหม่จากแอป Authenticator แล้วลองอีกครั้ง');
        header('Location: index.php?2fa_step='.($enrolling ? 'enroll' : 'verify'), true, 303); exit;
    }
    if ($enrolling) {
        $update = mysqli_prepare($conn, 'UPDATE users SET twofa_secret=?, twofa_enabled=1 WHERE id=?');
        if (!$update) { http_response_code(500); exit(__('บันทึกการตั้งค่า 2FA ไม่สำเร็จ')); }
        mysqli_stmt_bind_param($update, 'si', $secret, $uid);
        $saved = mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        if (!$saved) { http_response_code(500); exit(__('บันทึกการตั้งค่า 2FA ไม่สำเร็จ')); }
        $pendingUser['twofa_secret'] = $secret; $pendingUser['twofa_enabled'] = 1;
        if (function_exists('logActivity')) logActivity('ตั้งค่า 2FA ครั้งแรก: '.(string)$pendingUser['username']);
    }
    avsfFinishAdminLogin($pendingUser);
}

// Render pending 2FA flow on refresh without asking the user to enter the password again.
if (!empty($_SESSION['2fa_enroll_id']) && !empty($_SESSION['2fa_enroll_secret'])) {
    $e = (string)($_SESSION['2fa_error'] ?? ''); unset($_SESSION['2fa_error']);
    avsfRenderTwoFactorScreen('enroll', (string)($_SESSION['2fa_enroll_username'] ?? ''), (string)$_SESSION['2fa_enroll_secret'], $e);
}
if (!empty($_SESSION['2fa_pending_id'])) {
    $e = (string)($_SESSION['2fa_error'] ?? ''); unset($_SESSION['2fa_error']);
    avsfRenderTwoFactorScreen('verify', (string)($_SESSION['2fa_username'] ?? ''), '', $e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    if (sec_is_ip_locked($conn)) {
        $msg = __('ล็อกชั่วคราว 15 นาที — ทายรหัสผ่านผิดมากเกินไป');
    } elseif (empty($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $msg = __('แบบฟอร์มหมดอายุ กรุณาลองใหม่');
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $stmt = mysqli_prepare($conn, 'SELECT id, password FROM users WHERE username=? LIMIT 1');
        if (!$stmt) {
            $msg = __('ระบบตรวจสอบบัญชีไม่พร้อม กรุณาลองใหม่');
        } else {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $userId, $storedPassword);
            $foundUser = mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
            $passwordValid = $foundUser && (password_verify((string)$_POST['login_pass'], (string)$storedPassword) || hash_equals((string)$storedPassword, md5((string)$_POST['login_pass'])));
            if ($passwordValid) {
                // Never keep a previous backend authorization alive while a new
                // login is awaiting its own TOTP challenge.
                unset($_SESSION['avsf_admin'], $_SESSION['avsf_admin_id'], $_SESSION['avsf_admin_username'],
                      $_SESSION['avsf_2fa_verified_user_id'], $_SESSION['avsf_2fa_verified_at'],
                      $_SESSION['avsf_2fa_auth_method'], $_SESSION['user_role'], $_SESSION['user_permissions']);
                if (password_needs_rehash((string)$storedPassword, PASSWORD_DEFAULT) || hash_equals((string)$storedPassword, md5((string)$_POST['login_pass']))) {
                    $newHash = password_hash((string)$_POST['login_pass'], PASSWORD_DEFAULT);
                    $update = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE id=?');
                    if ($update) { mysqli_stmt_bind_param($update, 'si', $newHash, $userId); mysqli_stmt_execute($update); mysqli_stmt_close($update); }
                }
                session_regenerate_id(true);
                // Login success is recorded only after TOTP is verified/enrolled.
                $uResult = mysqli_query($conn, 'SELECT * FROM users WHERE id='.(int)$userId.' LIMIT 1');
                $user = $uResult ? mysqli_fetch_assoc($uResult) : null;
                if ($uResult) mysqli_free_result($uResult);
                if (!$user) { $msg = __('ไม่พบบัญชีผู้ใช้'); }
                elseif (!in_array((string)($user['role'] ?? 'user'), ['owner','super_admin','deputy_admin','editor','moderator','author','custom'], true)) {
                    $msg = __('บัญชีนี้ไม่มีสิทธิ์เข้าสู่ระบบหลังบ้าน กรุณาใช้หน้าสมาชิกของเว็บไซต์');
                }
                elseif (!empty($user['twofa_enabled']) && trim((string)$user['twofa_secret']) !== '') {
                        $_SESSION['2fa_pending_id'] = (int)$userId;
                        $_SESSION['2fa_username'] = (string)$user['username'];
                        $_SESSION['2fa_failures'] = 0;
                        avsfRenderTwoFactorScreen('verify', (string)$user['username']);
                    } else {
                        // Mandatory first-login 2FA enrollment for all accounts entering the admin area.
                        $_SESSION['2fa_enroll_id'] = (int)$userId;
                        $_SESSION['2fa_enroll_username'] = (string)$user['username'];
                        $_SESSION['2fa_enroll_secret'] = AVSFTOTP::generateSecret();
                        $_SESSION['2fa_failures'] = 0;
                        avsfRenderTwoFactorScreen('enroll', (string)$user['username'], (string)$_SESSION['2fa_enroll_secret']);
                }
            } else {
                sec_log_login_attempt($username, false, $conn);
                $msg = __('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
            }
        }
    }
}

if (!isAdmin()) {

    $loginBrandName = avsfBrandName();
    $loginBrandLogo = trim((string)setting('siteLogo',''));
    $loginBrandLogoValid = $loginBrandLogo !== '' && validImageReference($loginBrandLogo);

    echo '<!DOCTYPE html><html lang="'.avsf_lang_attr().'"><head><script>try{var t=localStorage.getItem("avsf-admin-theme")||"light";document.documentElement.setAttribute("data-admin-theme",t);document.documentElement.className="avsf-theme-"+t;}catch(e){document.documentElement.setAttribute("data-admin-theme","light");}</script><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.__('เข้าสู่ระบบหลังบ้าน - %s', [esc($loginBrandName)]).'</title><link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"><style>

*{box-sizing:border-box}html,body{margin:0;min-height:100%;scrollbar-width:none;-ms-overflow-style:none}*::-webkit-scrollbar{width:0;height:0}:root{--bg:#f3f6fb;--panel:#fff;--text:#19304f;--muted:#667790;--border:#dfe6f0}[data-admin-theme="dark"],.avsf-theme-dark{--bg:#090b10;--panel:#11151f;--text:#e9edf6;--muted:#a5afc1;--border:#293142}body{font-family:Kanit,sans-serif;background:var(--bg);color:var(--text)}.avsf-login-shell{min-height:100vh;display:grid;grid-template-columns:minmax(320px,.92fr) 1.08fr}.avsf-brand-panel{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;padding:38px 7%;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,.18),transparent 30%),linear-gradient(145deg,#ff2d78 0%,#a855f7 56%,#5726b8 100%)}.avsf-brand-panel:after{content:"";position:absolute;width:340px;height:340px;border:1px solid rgba(255,255,255,.15);border-radius:50%;right:-120px;bottom:-170px;box-shadow:0 0 0 38px rgba(255,255,255,.035),0 0 0 78px rgba(255,255,255,.025)}.avsf-brand-top,.avsf-brand-copy,.avsf-brand-foot{position:relative;z-index:1}.avsf-brand-top{display:flex;align-items:center;gap:13px}.avsf-brand-mark{width:auto;max-width:190px;min-width:46px;height:46px;padding:5px 9px;border-radius:12px;background:rgba(255,255,255,.18);display:grid;place-items:center;font-size:12px;font-weight:900;backdrop-filter:blur(10px);overflow:hidden}.avsf-brand-mark img{display:block;max-width:170px;max-height:36px;object-fit:contain}.avsf-brand-name{font-size:15px;font-weight:800}.avsf-brand-caption{font-size:11px;opacity:.8;margin-top:2px}.avsf-brand-copy{max-width:520px}.avsf-brand-copy h1{font-size:clamp(28px,3.3vw,44px);line-height:1.25;margin:0 0 14px;font-weight:900}.avsf-brand-copy p{font-size:14px;line-height:1.8;opacity:.86;margin:0;max-width:420px}.avsf-brand-foot{font-size:11px;opacity:.68}.avsf-login-panel{display:flex;align-items:center;justify-content:center;padding:36px}.avsf-login-content{width:min(100%,430px)}.avsf-login-heading{padding-bottom:20px;border-bottom:1px solid var(--border);margin-bottom:25px}.avsf-login-heading strong{font-size:clamp(27px,3vw,38px);font-weight:900;letter-spacing:-.03em;background:linear-gradient(90deg,#ff70a3,#be8cff);-webkit-background-clip:text;background-clip:text;color:transparent}.avsf-login-heading p{font-size:13px;color:var(--muted);margin:5px 0 0}.avsf-login-title{font-size:21px;font-weight:800;margin:0 0 5px}.avsf-login-subtitle{font-size:12px;color:var(--muted);margin:0 0 22px}.avsf-login-field{margin-bottom:16px}.avsf-login-field label{display:block;font-size:12px;font-weight:600;color:var(--text);margin-bottom:7px}.avsf-login-input-wrap{position:relative}.avsf-login-input{width:100%;height:48px;padding:0 14px;border:1px solid var(--border);border-radius:11px;background:var(--panel);color:var(--text);font:14px Kanit,sans-serif;outline:none;transition:border .2s,box-shadow .2s}.avsf-login-input:focus{border-color:#ff4f91;box-shadow:0 0 0 3px rgba(255,45,120,.13)}.avsf-password-input{padding-right:48px}.avsf-login-input.with-icon{padding-left:42px}.avsf-login-input-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:18px;height:18px;opacity:.6;pointer-events:none;z-index:1}.avsf-password-toggle{position:absolute;right:8px;top:7px;width:34px;height:34px;border:0;border-radius:8px;color:var(--muted);background:transparent;display:grid;place-items:center}.avsf-password-toggle:hover{background:rgba(120,120,160,.12);color:var(--text)}.avsf-login-error{padding:10px 12px;border:1px solid rgba(239,68,68,.28);border-radius:10px;background:rgba(239,68,68,.09);color:#ff9a9a;font-size:12px;margin-bottom:15px}.avsf-login-submit{width:100%;height:48px;border:0;border-radius:11px;background:linear-gradient(100deg,#ff2d78,#a855f7);color:#fff;font:700 14px Kanit,sans-serif;box-shadow:0 8px 24px rgba(255,45,120,.2);transition:transform .2s,filter .2s}.avsf-login-submit:hover{transform:translateY(-1px);filter:brightness(1.08)}.avsf-login-bottom{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:17px;font-size:11px;color:var(--muted)}.avsf-login-bottom a{color:#c4a4ff;text-decoration:none}.avsf-login-bottom a:hover{color:var(--text)}.avsf-security-note{margin-top:23px;padding-top:16px;border-top:1px solid var(--border);color:var(--muted);font-size:10px;line-height:1.7}@media(max-width:760px){.avsf-login-input{font-size:16px}.avsf-login-shell{grid-template-columns:1fr}.avsf-brand-panel{min-height:190px;padding:22px 24px}.avsf-brand-copy{margin:28px 0 18px}.avsf-brand-copy h1{font-size:25px}.avsf-brand-copy p,.avsf-brand-foot{display:none}.avsf-login-panel{padding:28px 22px 42px}.avsf-login-content{max-width:460px}.avsf-login-heading{margin-bottom:22px}}</style></head><body><div class="avsf-login-shell"><section class="avsf-brand-panel"><div class="avsf-brand-top"><div class="avsf-brand-mark">'.($loginBrandLogoValid?'<img src="'.esc($loginBrandLogo).'" alt="'.esc($loginBrandName).'">':esc($loginBrandName)).'</div><div><div class="avsf-brand-name">'.esc($loginBrandName).' หลังบ้าน</div><div class="avsf-brand-caption">'.__('ระบบจัดการเว็บไซต์').'</div></div></div><div class="avsf-brand-copy"><h1>'.__('ทุกโพสต์และทุกหน้า').'<br>'.__('จัดการได้ในที่เดียว').'</h1><p>'.__('พื้นที่ดูแลเว็บไซต์สำหรับผู้ดูแลระบบ จัดการเนื้อหา สถานะการเผยแพร่ และข้อมูล SEO ได้อย่างเป็นระเบียบ').'</p></div><div class="avsf-brand-foot">'.__('สำหรับผู้ดูแลที่ได้รับอนุญาตเท่านั้น · ระบบบันทึกกิจกรรมการใช้งาน').'</div></section><main class="avsf-login-panel"><div class="avsf-login-content"><div class="avsf-login-heading"><strong>'.esc($loginBrandName).'</strong><p>'.__('ระบบจัดการเว็บไซต์').'</p></div><h2 class="avsf-login-title">'.__('เข้าสู่ระบบหลังบ้าน').'</h2><p class="avsf-login-subtitle">'.__('กรอกชื่อผู้ใช้และรหัสผ่านเพื่อดำเนินการต่อ').'</p>'.($msg?'<div class="avsf-login-error" role="alert">'.esc($msg).'</div>':'').'<form method="post" autocomplete="on"><input type="hidden" name="csrf_token" value="'.esc($_SESSION['csrf_token']).'"><div class="avsf-login-field"><label for="loginUsername">'.__('ชื่อผู้ใช้').'</label><div class="avsf-login-input-wrap"><svg class="avsf-login-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><input class="avsf-login-input with-icon" id="loginUsername" name="username" type="text" autocomplete="username" required autofocus></div></div><div class="avsf-login-field"><label for="loginPassword">'.__('รหัสผ่าน').'</label><div class="avsf-login-input-wrap"><svg class="avsf-login-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input class="avsf-login-input avsf-password-input with-icon" id="loginPassword" name="login_pass" type="password" autocomplete="current-password" required><button class="avsf-password-toggle" type="button" aria-label="'.__('แสดงรหัสผ่าน').'" onclick="var p=document.getElementById(\'loginPassword\');var show=p.type===\'password\';p.type=show?\'text\':\'password\';this.setAttribute(\'aria-label\',show?\'ซ่อนรหัสผ่าน\':\'แสดงรหัสผ่าน\');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div><button class="avsf-login-submit" type="submit">'.__('เข้าสู่ระบบ').'</button></form><div class="avsf-login-bottom"><span>'.__('เข้าใช้งานเฉพาะผู้ดูแล').'</span><a href="../">'.__('กลับไปหน้าบ้าน').'</a></div><div class="avsf-security-note">'.__('อย่าแชร์ข้อมูลเข้าสู่ระบบ และควรเปลี่ยนรหัสผ่านเริ่มต้นก่อนเปิดให้เข้าถึงจากเครือข่ายภายนอก').'</div><div style="text-align:center;margin-top:12px;font-size:11px;opacity:.7">'.avsf_lang_switcher_html().'</div></div></main></div></body></html>';

    exit;

}

if (empty($_SESSION['avsf_admin_id']) && !empty($_SESSION['avsf_admin_username'])) {

    $sessionUserStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE username=? LIMIT 1');

    if ($sessionUserStmt) {

        $sessionUsername = (string)$_SESSION['avsf_admin_username'];

        mysqli_stmt_bind_param($sessionUserStmt, 's', $sessionUsername);

        if (mysqli_stmt_execute($sessionUserStmt)) {

            mysqli_stmt_bind_result($sessionUserStmt, $sessionUserId);

            if (mysqli_stmt_fetch($sessionUserStmt)) $_SESSION['avsf_admin_id'] = (int)$sessionUserId;

        } else error_log('AVSF session user lookup failed: '.mysqli_stmt_error($sessionUserStmt));

        mysqli_stmt_close($sessionUserStmt);

    } else error_log('AVSF session user lookup prepare failed: '.mysqli_error($conn));

}

if (!empty($_SESSION['avsf_admin_id'])) loadUserPermissions((int)$_SESSION['avsf_admin_id'], $conn);

if (isset($_GET['live_page_views']) && $_GET['live_page_views'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    if (!isAdmin()) {
        http_response_code(401);
        echo json_encode(['error'=> __('ต้องเข้าสู่ระบบก่อน')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!avsfRecentPageViewsEnsureTable($conn)) {
        error_log('AVSF recent page-view table setup failed: '.mysqli_error($conn));
        http_response_code(500);
        echo json_encode(['error'=> __('โหลดข้อมูลผู้เข้าชมไม่สำเร็จ')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $liveViewsResult=mysqli_query($conn,'SELECT page_path,page_title,viewed_at FROM recent_page_views ORDER BY id DESC LIMIT 15');
    if (!$liveViewsResult) {
        error_log('AVSF recent page-view query failed: '.mysqli_error($conn));
        http_response_code(500);
        echo json_encode(['error'=> __('โหลดข้อมูลผู้เข้าชมไม่สำเร็จ')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $liveViews=[];
    while($liveView=mysqli_fetch_assoc($liveViewsResult)) {
        $liveViews[]=[
            'path'=>$liveView['page_path'],
            'title'=>$liveView['page_title'],
            'viewedAt'=>$liveView['viewed_at']
        ];
    }
    echo json_encode(['views'=>$liveViews], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))) {

    http_response_code(403);

    exit(__('คำขอไม่ถูกต้องหรือหมดอายุ กรุณาโหลดหน้าใหม่'));

}



// ---- ACTION HANDLING (POST) ----

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

if ($page === 'backup') {
    requirePermission('backup.download');
    header('Location: site-center.php?panel=backup-center', true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    $actionPermissions = [

        'save_category'=>'taxonomy.manage','save_tax'=>'taxonomy.manage','add_tax'=>'taxonomy.manage','delete_category'=>'taxonomy.manage','delete_tax'=>'taxonomy.manage','bulk_delete_taxonomies'=>'taxonomy.manage','toggle_cmt'=>'comments.moderate','delete_cmt'=>'comments.moderate',

        'save_site_content'=>'theme.edit','save_theme'=>'theme.edit','save_seo'=>'seo.manage','save_seo_public'=>'seo.manage',

        'save_settings'=>'settings.edit','save_settings_section'=>'settings.edit','save_card_layout'=>'settings.edit','save_system'=>'settings.edit','save_banner_ads'=>'banners.manage','save_page'=>'pages.manage','delete_page'=>'pages.manage',

        'save_banner'=>'banners.manage','delete_banner'=>'banners.manage','quick_clear_cache'=>'cache.manage','delete_media'=>'settings.edit','update_media_metadata'=>'settings.edit','import_json'=>'posts.import'

    ];

    if(isset($actionPermissions[$action])) requirePermission($actionPermissions[$action]);

    if ($action === 'save_post') {

        requirePermission(((int)($_POST['id'] ?? 0) > 0) ? 'posts.edit' : 'posts.create');

    }

    if (in_array($action, ['delete_post','delete_posts'], true)) requirePermission('posts.delete');

    if ($action === 'bulk_post_action') requirePermission('posts.edit');

    if ($action === 'save_quick_draft') requirePermission('posts.create');

    if ($action === 'save_advanced_draft') requirePermission('posts.create');

    if ($action === 'quick_clear_cache') {

        requirePermission('cache.manage');
        $clearedFiles=AVSFCache::clearAll(AVSF_CACHE_DIR);
        logActivity('ล้างแคชด่วน '.$clearedFiles.' ไฟล์ โดย '.($_SESSION['avsf_admin_username']??'admin'));
        if(isset($_POST['ajax'])&&(string)$_POST['ajax']==='1') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo json_encode(['ok'=>true,'cleared'=>$clearedFiles,'message'=>'ล้างแคชสำเร็จ '.$clearedFiles.' ไฟล์'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['avsf_admin_notice']='ล้างแคชสำเร็จ '.$clearedFiles.' ไฟล์';
        header('Location: index.php?page=dashboard');exit;

    }

    if ($action === 'save_admin_accent_quick') {

        requirePermission('settings.edit');

        $quickAccent = isset($_POST['admin_accent']) && is_string($_POST['admin_accent']) ? $_POST['admin_accent'] : '';

        if (!preg_match('/^#[0-9a-f]{6}$/i', $quickAccent)) {

            http_response_code(400);

            exit(__('รูปแบบสีหลังบ้านไม่ถูกต้อง'));

        }

        if (!saveSetting('admin_accent', strtolower($quickAccent))) {

            error_log('AVSF quick admin accent save failed');

            http_response_code(500);

            exit(__('บันทึกสีหลังบ้านไม่สำเร็จ'));

        }

        logActivity('เปลี่ยนสี Accent หลังบ้าน');

        header('Location: index.php?page=dashboard');

        exit;

    }

    $passwordChangeError = '';

    if ($action === 'save_system') {

        $newPassword = trim((string)($_POST['newPass'] ?? ''));

        $_POST['newPass'] = '';

        if ($newPassword !== '') {

            $currentUsername = (string)($_SESSION['avsf_admin_username'] ?? '');

            if (strlen($newPassword) < 8) {

                $passwordChangeError = __('รหัสผ่านใหม่ต้องอย่างน้อย 8 ตัวอักษร');

            } elseif ($currentUsername === '') {

                $passwordChangeError = __('ไม่พบผู้ใช้ที่เข้าสู่ระบบ จึงเปลี่ยนรหัสผ่านไม่ได้');

            } else {

                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $passwordStmt = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE username=?');

                if (!$passwordStmt) {

                    error_log('AVSF admin password update could not be prepared: ' . mysqli_error($conn));

                    $passwordChangeError = __('เปลี่ยนรหัสผ่านไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');

                } else {

                    mysqli_stmt_bind_param($passwordStmt, 'ss', $passwordHash, $currentUsername);

                    $passwordSaved = mysqli_stmt_execute($passwordStmt);

                    if (!$passwordSaved) {

                        error_log('AVSF admin password update failed: ' . mysqli_stmt_error($passwordStmt));

                        $passwordChangeError = __('เปลี่ยนรหัสผ่านไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');

                    } elseif (mysqli_stmt_affected_rows($passwordStmt) !== 1) {

                        $passwordChangeError = __('ไม่พบผู้ใช้ที่เข้าสู่ระบบ จึงเปลี่ยนรหัสผ่านไม่ได้');

                    }

                    mysqli_stmt_close($passwordStmt);

                }

            }

        }

    }

    if ($action==='save_post_keyword_notes') {

        $primaryNotes=trim(isset($_POST['primary_keyword_notes'])?$_POST['primary_keyword_notes']:'');

        $secondaryNotes=trim(isset($_POST['secondary_keyword_notes'])?$_POST['secondary_keyword_notes']:'');

        if (mb_strlen($primaryNotes,'UTF-8')>20000 || mb_strlen($secondaryNotes,'UTF-8')>30000) {

            $msg= __('ชุดคีย์ยาวเกินกำหนด กรุณาลดจำนวนข้อความแล้วบันทึกอีกครั้ง');

        } else {

            saveSetting('post_primary_keyword_notes',$primaryNotes);

            saveSetting('post_secondary_keyword_notes',$secondaryNotes);

            logActivity('แก้ไขชุดคีย์เวิร์ดโน้ต');

            $_SESSION['avsf_admin_notice']= __('บันทึกชุดคีย์เวิร์ดแล้ว');

            header('Location: index.php?page=posts#post-keyword-notes');

            exit;

        }

    }

    if ($action==='save_quick_draft') {

        requirePermission('posts.create');

        $draftTitle=trim(isset($_POST['draft_title'])&&is_string($_POST['draft_title'])?$_POST['draft_title']:'');

        $draftContent=trim(isset($_POST['draft_content'])&&is_string($_POST['draft_content'])?$_POST['draft_content']:'');

        if ($draftTitle==='') {

            $msg= __('กรุณากรอกชื่อเรื่องก่อนบันทึกฉบับร่าง');

            $msgType='error';

        } elseif (mb_strlen($draftTitle,'UTF-8')>200) {

            $msg= __('ชื่อเรื่องยาวเกิน 200 ตัวอักษร');

            $msgType='error';

        } else {

            $draftSlug=uniqueSlugForName($conn,'posts',$draftTitle);

            $draftDate=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');

            $draftCreatedAt=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');

            $draftAuthor=trim(isset($_SESSION['avsf_admin_username'])?$_SESSION['avsf_admin_username']:'');

            if($draftAuthor==='')$draftAuthor='admin';

            $draftValues=array_map(function($value)use($conn){return mysqli_real_escape_string($conn,(string)$value);},[

                $draftTitle,$draftSlug,$draftContent,'[]','[]',$draftAuthor,$draftDate,$draftCreatedAt

            ]);

            $draftSql="INSERT INTO posts (title,slug,content,actorIds,tagIds,status,author,date,createdAt,updatedAt,views) VALUES ('{$draftValues[0]}','{$draftValues[1]}','{$draftValues[2]}','{$draftValues[3]}','{$draftValues[4]}','draft','{$draftValues[5]}','{$draftValues[6]}','{$draftValues[7]}','{$draftValues[7]}',0)";

            if(!mysqli_query($conn,$draftSql)) {

                error_log('AVSF quick draft save failed: '.mysqli_error($conn));

                $msg= __('บันทึกฉบับร่างไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูลแล้วลองอีกครั้ง');

                $msgType='error';

            } else {

                logActivity('สร้างฉบับร่าง: '.$draftTitle);

                $_SESSION['avsf_admin_notice']= __('บันทึกฉบับร่างแล้ว');

                header('Location: index.php?page=dashboard');

                exit;

            }

        }

    }

    if ($action==='save_advanced_draft') {

        requirePermission('posts.create');

        try {

            $advancedRows=advancedDraftParseText(isset($_POST['advanced_draft_data'])&&is_string($_POST['advanced_draft_data'])?$_POST['advanced_draft_data']:'');

            if(count($advancedRows)>100)throw new RuntimeException('บันทึกได้ไม่เกิน 100 เรื่องต่อครั้ง');
            $advancedUpload=avsfCollectUploadedImageFiles(['advancedDraftImages','advancedDraftFolder'],3000,25*1024*1024);
            if(!empty($advancedUpload['errors']))throw new RuntimeException(implode(' / ',array_slice($advancedUpload['errors'],0,3)));
            $advancedImageAssets=$advancedUpload['images'];

            $author=trim((string)($_SESSION['avsf_admin_username']??''))?:'admin';

            $created=0;$updated=0;$failures=[];$remoteWarnings=[];$firstId=0;$savedCoverImages=0;$savedPosterImages=0;$savedGalleryImages=0;$savedTagTerms=0;

            foreach($advancedRows as $rowIndex=>$advancedRow) {

                if(!mysqli_begin_transaction($conn))throw new RuntimeException('เริ่มรายการบันทึกฉบับร่างไม่สำเร็จ: '.mysqli_error($conn));

                try {

                    $saved=advancedDraftSaveRecord($conn,$advancedRow,$author,$advancedImageAssets);

                    if(!mysqli_commit($conn))throw new RuntimeException('ยืนยันการบันทึกฉบับร่างไม่สำเร็จ: '.mysqli_error($conn));

                    if(!$firstId)$firstId=(int)$saved['id'];
                    $savedCoverImages+=(int)($saved['coverCount']??0);
                    $savedPosterImages+=(int)($saved['posterCount']??0);
                    $savedGalleryImages+=(int)($saved['galleryCount']??0);
                    $savedTagTerms+=(int)($saved['tagCount']??0);

                    if($saved['status']==='created')$created++;else $updated++;

                    logActivity(($saved['status']==='created'?'สร้าง':'อัปเดต').'ฉบับร่าง: '.trim((string)($advancedRow['title']??'')));

                    if($saved['remoteError']!==''){
                        error_log('AVSF Remote API warning for advanced draft '.$saved['id'].': '.$saved['remoteError']);
                        $remoteWarnings[]=$saved['remoteError'];
                    }

                } catch(Throwable $rowError) {

                    mysqli_rollback($conn);

                    $failures[]='รายการ '.($rowIndex+1).': '.$rowError->getMessage();

                }

            }

            $summary=__('บันทึกฉบับร่างแล้ว %s', [$created.' เรื่อง, อัปเดต '.$updated.' เรื่อง · รูปปก '.$savedCoverImages.' รูป, โปสเตอร์ '.$savedPosterImages.' รูป, Gallery '.$savedGalleryImages.' รูป · แท็ก '.$savedTagTerms.' รายการ']);

            if($failures)$summary.=' · ไม่สำเร็จ '.count($failures).' เรื่อง ('.implode(' / ',array_slice($failures,0,3)).')';

            if($remoteWarnings)$summary.=' · คำเตือนวิดีโอ: '.implode(' / ',array_unique($remoteWarnings));

            $_SESSION['avsf_admin_notice']=$summary;

            header('Location: index.php?page=dashboard#dashboard-widget-quick-draft-content');

            exit;

        } catch(Throwable $advancedError) {

            error_log('AVSF advanced quick draft parsing failed: '.$advancedError->getMessage());

            $_SESSION['avsf_admin_notice']='บันทึกฉบับร่างไม่สำเร็จ: '.$advancedError->getMessage();

            header('Location: index.php?page=dashboard');

            exit;

        }

    }

    if ($action==='save_post') {

        $id = intval($_POST['id']);

        $title = trim($_POST['title']);

        $videoInput=normalizeVideoInput(isset($_POST['videoUrl'])?$_POST['videoUrl']:'');

        $coverUpload=storeImageUpload('coverUpload');

        $coverValue=$coverUpload['path']!==''?$coverUpload['path']:trim(isset($_POST['cover'])?$_POST['cover']:'');

        $posterUpload=storeImageUpload('posterUpload');

        $posterValue=$posterUpload['path']!==''?$posterUpload['path']:trim(isset($_POST['poster'])?$_POST['poster']:'');

        $galleryUploads=storeMultipleImageUploads('galleryUploads');

        $duration = trim(isset($_POST['videoDuration']) ? $_POST['videoDuration'] : '');

        $durationParts=$duration!==''?videoDurationParts($duration):null;

        if ($coverUpload['error']!=='') { $msg=$coverUpload['error']; }

        elseif ($posterUpload['error']!=='') { $msg=$posterUpload['error']; }

        elseif ($galleryUploads['error']!=='') { $msg=$galleryUploads['error']; }

        elseif (!validImageReference($coverValue)) { $msg= __('ลิงก์รูปภาพต้องเป็น URL http(s) หรือพาธรูปภายในเว็บไซต์'); }

        elseif (!validImageReference($posterValue)) { $msg= __('ลิงก์โปสเตอร์ต้องเป็น URL http(s) หรือพาธรูปภายในเว็บไซต์'); }

        elseif ($videoInput['error']!=='') { $msg=$videoInput['error']; }

        elseif (!$title) { $msg= __('กรุณากรอกชื่อเรื่อง'); }

        elseif ($duration!=='' && $durationParts===null) { $msg= __('รูปแบบระยะเวลาไม่ถูกต้อง ใช้วินาที, นาที:วินาที, ชั่วโมง:นาที:วินาที หรือรูปแบบ ISO 8601'); }

        else {

            $postedSlug=trim($_POST['slug']??'');

            if($id>0) {

                $currentPost=mysqli_fetch_assoc(mysqli_query($conn,"SELECT title,slug FROM posts WHERE id=".$id." LIMIT 1"));

                $slug=($currentPost&&$postedSlug===$currentPost['slug']&&$title!==$currentPost['title'])

                    ?uniqueSlugForName($conn,'posts',$title,$id)

                    :($postedSlug?:uniqueSlugForName($conn,'posts',$title,$id));

            } else {

                $currentPost=null;

                $slugSource=trim(isset($_POST['code'])?$_POST['code']:'')!==''?trim($_POST['code']):$title;
                $slug=uniqueSlugForName($conn,'posts',$slugSource);

                }

                $slugE = mysqli_real_escape_string($conn, $slug);

            $titleE = mysqli_real_escape_string($conn, $title);

            $contentE = mysqli_real_escape_string($conn, $_POST['content']);

            $coverE = mysqli_real_escape_string($conn, $coverValue);

            $posterE = mysqli_real_escape_string($conn, $posterValue);

            $videoSource=$videoInput['url'];

            $videoE = mysqli_real_escape_string($conn, $videoSource);

            $remoteResult=$videoSource!==''?videoApiRemote($videoSource):['url'=>'','error'=>''];

            $embedE=mysqli_real_escape_string($conn,$remoteResult['url']);

            $duration=$durationParts===null?'':(string)($durationParts[0]*3600+$durationParts[1]*60+$durationParts[2]);

            $durE = mysqli_real_escape_string($conn, $duration);

            $galleryInput=isset($_POST['gallery'])?(string)$_POST['gallery']:'';

            $gallery=normalizeGalleryImages($galleryInput);

            $gallery=array_values(array_unique(array_merge($gallery,$galleryUploads['paths'])));

            $galleryE = mysqli_real_escape_string($conn, json_encode($gallery));

            $coverAltE=mysqli_real_escape_string($conn, trim(isset($_POST['coverAlt'])?(string)$_POST['coverAlt']:''));
            $posterAltE=mysqli_real_escape_string($conn, trim(isset($_POST['posterAlt'])?(string)$_POST['posterAlt']:''));
            $galleryAltInput=isset($_POST['galleryAlt'])?(string)$_POST['galleryAlt']:'';
            $galleryAlt=array_map('trim',preg_split('/\r\n|\r|\n/',$galleryAltInput));
            $galleryAltE=mysqli_real_escape_string($conn,json_encode(array_values($galleryAlt)));

            $taxonomyNames=function($field) {

                if (!isset($_POST[$field])) return [];

                $names=json_decode((string)$_POST[$field],true);

                if (!is_array($names)) return [];

                $result=[];$seen=[];

                foreach($names as $name) {

                    foreach(preg_split('/[,،;|，；\r\n]+/u',(string)$name)?:[] as $part) {

                        $part=trim($part);

                        if ($part==='' || mb_strlen($part,'UTF-8')>100) continue;

                        $key=mb_strtolower($part,'UTF-8');

                        if (isset($seen[$key])) continue;

                        $seen[$key]=true;$result[]=$part;

                    }

                }

                return $result;

            };

            $findTaxonomyId=function($table,$name) use ($conn) {

                $stmt=mysqli_prepare($conn,"SELECT id FROM `$table` WHERE name=? LIMIT 1");

                if (!$stmt) return 0;

                mysqli_stmt_bind_param($stmt,'s',$name);

                if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt);return 0; }

                mysqli_stmt_bind_result($stmt,$foundId);

                $found=mysqli_stmt_fetch($stmt);

                mysqli_stmt_close($stmt);

                return $found?(int)$foundId:0;

            };

            $createTaxonomyId=function($table,$name) use ($conn) {

                $slug=uniqueSlugForName($conn,$table,$name);

                $stmt=mysqli_prepare($conn,"INSERT INTO `$table` (name,slug) VALUES (?,?)");

                if (!$stmt) return 0;

                mysqli_stmt_bind_param($stmt,'ss',$name,$slug);

                $ok=mysqli_stmt_execute($stmt);$id=$ok?(int)mysqli_insert_id($conn):0;

                mysqli_stmt_close($stmt);

                return $id;

            };

            $studioNames=array_values(array_filter(array_map('trim',splitTaxonomyNames(trim(isset($_POST['studioName'])?(string)$_POST['studioName']:'')))));
            $studioId=0;
            foreach($studioNames as $studioIndex=>$studioName) {
                $studioTaxonomyId=$findTaxonomyId('studios',$studioName);
                if(!$studioTaxonomyId) $studioTaxonomyId=$createTaxonomyId('studios',$studioName);
                if(!$studioTaxonomyId) {$msg='บันทึกชื่อค่าย '.esc($studioName).' ไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูลแล้วลองใหม่';break;}
                if($studioIndex===0)$studioId=$studioTaxonomyId;
            }

            $actorIds=array_map('intval',isset($_POST['actorIds'])&&is_array($_POST['actorIds'])?$_POST['actorIds']:[]);

            foreach($taxonomyNames('newActorNames') as $actorName) {

                $actorId=$findTaxonomyId('actors',$actorName);

                if (!$actorId) $actorId=$createTaxonomyId('actors',$actorName);

                if (!$actorId) {$msg='บันทึกชื่อนักแสดง "'.esc($actorName).'" ไม่สำเร็จ';break;}

                $actorIds[]=$actorId;

            }

            $tagIds=array_map('intval',isset($_POST['tagIds'])&&is_array($_POST['tagIds'])?$_POST['tagIds']:[]);

            if ($msg==='') foreach($taxonomyNames('newTagNames') as $tagName) {

                $tagId=$findTaxonomyId('tags',$tagName);

                if (!$tagId) $tagId=$createTaxonomyId('tags',$tagName);

                if (!$tagId) {$msg='บันทึกแท็ก "'.esc($tagName).'" ไม่สำเร็จ';break;}

                $tagIds[]=$tagId;

            }

            $actorIds=json_encode(array_values(array_unique($actorIds)));

            $tagIds=json_encode(array_values(array_unique($tagIds)));

            $postedMetaTitle=trim($_POST['metaTitle']);
            if($postedMetaTitle==='') {
                $metaTitleTemplate=trim((string)setting('seo_post_meta_title_template',''));
                $postedMetaTitle=$metaTitleTemplate!==''
                    ?str_replace(['{code}','{title}','{site_name}'],[trim($_POST['code']??''),$title,setting('siteName','')],$metaTitleTemplate)
                    :$title;
            }
            $mtE = mysqli_real_escape_string($conn, $postedMetaTitle);

            $postedMetaDesc=trim($_POST['metaDesc']);
            if($postedMetaDesc==='') {
                $metaDescTemplate=trim((string)setting('seo_post_meta_desc_template',''));
                if($metaDescTemplate!=='' ) {
                    $postedMetaDesc=str_replace(['{code}','{title}','{site_name}'],[trim($_POST['code']??''),$title,setting('siteName','')],$metaDescTemplate);
                }
            }
            $mdE = mysqli_real_escape_string($conn, $postedMetaDesc);

            $kwE = mysqli_real_escape_string($conn, trim($_POST['keywords']));

            $status = isset($_POST['post_intent']) && $_POST['post_intent']==='publish'?'publish':(isset($_POST['post_intent']) && $_POST['post_intent']==='draft'?'draft':($_POST['status']==='publish'?'publish':'draft'));

            $dateValue = isset($_POST['date']) ? $_POST['date'] : '';

            $dateObject = DateTime::createFromFormat('!Y-m-d', $dateValue);

            $thaiNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));

            $date = $status==='publish' && isset($_POST['post_intent']) && $_POST['post_intent']==='publish' ? $thaiNow->format('Y-m-d') : ($dateObject && $dateObject->format('Y-m-d')===$dateValue ? $dateValue : $thaiNow->format('Y-m-d'));

            $dateE = mysqli_real_escape_string($conn, $date);

            $authorName=trim(isset($_SESSION['avsf_admin_username'])?$_SESSION['avsf_admin_username']:'');

            if($authorName==='')$authorName='admin';

            $authorE=mysqli_real_escape_string($conn,mb_substr($authorName,0,100,'UTF-8'));

            $createdAtE=mysqli_real_escape_string($conn,$thaiNow->format('Y-m-d H:i:s'));
            $updatedAtE=$createdAtE;

            $codeE = mysqli_real_escape_string($conn, trim(isset($_POST['code'])?$_POST['code']:''));

            $actorENE = mysqli_real_escape_string($conn, trim(isset($_POST['actorEN'])?$_POST['actorEN']:''));

            $categoryValue=trim(isset($_POST['category'])?$_POST['category']:'');

            try {

                foreach(splitTaxonomyNames($categoryValue) as $categoryName)ensureCategoryName($conn,$categoryName);

            } catch(Throwable $categoryError) {

                $msg='บันทึกหมวดหมู่ไม่สำเร็จ: '.$categoryError->getMessage();

            }

            $categoryE = mysqli_real_escape_string($conn, $categoryValue);

            $focusE = mysqli_real_escape_string($conn, trim(isset($_POST['focusKeyphrase'])?$_POST['focusKeyphrase']:''));

            if ($msg!=='') {

                error_log('AVSF taxonomy save failed: '.$msg);

            } elseif ($id) {

                mysqli_query($conn, "UPDATE posts SET code='$codeE',title='$titleE',slug='$slugE',content='$contentE',cover='$coverE',coverAlt='$coverAltE',posterUrl='$posterE',posterAlt='$posterAltE',videoUrl='$videoE',embedUrl='$embedE',videoDuration='$durE',gallery='$galleryE',galleryAlt='$galleryAltE',studioId=$studioId,actorIds='$actorIds',actorEN='$actorENE',tagIds='$tagIds',category='$categoryE',focusKeyphrase='$focusE',metaTitle='$mtE',metaDesc='$mdE',keywords='$kwE',status='$status',date='$dateE',updatedAt='$updatedAtE' WHERE id=$id");

                if($currentPost)saveSlugAlias($conn,'post',$currentPost['slug'],$slug);

                logActivity('แก้ไขโพสต์: '.$title);

            } else {

                mysqli_query($conn, "INSERT INTO posts (code,title,slug,content,cover,coverAlt,posterUrl,posterAlt,videoUrl,embedUrl,videoDuration,gallery,galleryAlt,studioId,actorIds,actorEN,tagIds,category,focusKeyphrase,metaTitle,metaDesc,keywords,status,date,author,createdAt,updatedAt,views) VALUES ('$codeE','$titleE','$slugE','$contentE','$coverE','$coverAltE','$posterE','$posterAltE','$videoE','$embedE','$durE','$galleryE','$galleryAltE',$studioId,'$actorIds','$actorENE','$tagIds','$categoryE','$focusE','$mtE','$mdE','$kwE','$status','$dateE','$authorE','$createdAtE','$updatedAtE',0)");

                logActivity('สร้างโพสต์: '.$title);

            }

            if ($remoteResult['error']!=='') {

                error_log('AVSF Remote API warning for post '.$id.': '.$remoteResult['error']);

                $_SESSION['avsf_admin_notice']=$remoteResult['error'];

            }

            header('Location: index.php?page=posts'); exit;

        }

    }

    if ($action==='delete_post') { mysqli_query($conn, "DELETE FROM posts WHERE id=".intval($_POST['id'])); logActivity('ลบโพสต์'); header('Location: index.php?page=posts'); exit; }

    if ($action==='bulk_post_action') {

        $operation=isset($_POST['bulk_operation'])?(string)$_POST['bulk_operation']:'';

        $selected=isset($_POST['post_ids'])&&is_array($_POST['post_ids'])?$_POST['post_ids']:[];

        $postIds=[];

        foreach($selected as $selectedId) {

            if (!is_scalar($selectedId) || !ctype_digit((string)$selectedId) || (int)$selectedId<1) continue;

            $postIds[(int)$selectedId]=(int)$selectedId;

        }

        if (!in_array($operation,['publish','draft','delete'],true)) {

            $msg= __('กรุณาเลือกคำสั่งที่ต้องการใช้กับโพสต์');

        } elseif (!$postIds) {

            $msg= __('กรุณาเลือกโพสต์อย่างน้อย 1 รายการ');

        } else {

            requirePermission($operation==='delete'?'posts.delete':'posts.publish');

            $sql=$operation==='delete'?'DELETE FROM posts WHERE id=?':'UPDATE posts SET status=?,updatedAt=NOW() WHERE id=?';

            $stmt=mysqli_prepare($conn,$sql);

            if (!$stmt) {

                error_log('AVSF bulk post action prepare failed: '.mysqli_error($conn));

                $msg= __('เตรียมคำสั่งจัดการโพสต์ไม่สำเร็จ กรุณาลองใหม่');

            } else {

                if (!mysqli_begin_transaction($conn)) {

                    mysqli_stmt_close($stmt);

                    error_log('AVSF bulk post action transaction could not start: '.mysqli_error($conn));

                    $msg= __('เริ่มรายการจัดการโพสต์ไม่สำเร็จ กรุณาลองใหม่');

                } else {

                    $processed=0;

                    foreach($postIds as $postId) {

                        if ($operation==='delete') {

                            mysqli_stmt_bind_param($stmt,'i',$postId);

                        } else {

                            $newStatus=$operation==='publish'?'publish':'draft';

                            mysqli_stmt_bind_param($stmt,'si',$newStatus,$postId);

                        }

                        if (!mysqli_stmt_execute($stmt)) {

                            $error=mysqli_stmt_error($stmt);

                            mysqli_rollback($conn);

                            error_log('AVSF bulk post action failed: '.$error);

                            $msg= __('ดำเนินการกับโพสต์ไม่สำเร็จ ไม่มีรายการใดถูกเปลี่ยนแปลง');

                            break;

                        }

                        $processed++;

                    }

                    mysqli_stmt_close($stmt);

                    if ($msg==='') {

                        if (!mysqli_commit($conn)) {

                            $error=mysqli_error($conn);

                            mysqli_rollback($conn);

                            error_log('AVSF bulk post action commit failed: '.$error);

                            $msg= __('บันทึกการจัดการโพสต์ไม่สำเร็จ กรุณาลองใหม่');

                        } else {

                            $operationLabel=$operation==='publish'? __('เผยแพร่'):($operation==='draft'? __('เปลี่ยนเป็นฉบับร่าง'): __('ลบ'));

                            logActivity('จัดการโพสต์แบบกลุ่ม: '.$operationLabel.' '.$processed.' รายการ');

                            $_SESSION['avsf_admin_notice']='ดำเนินการ'.$operationLabel.'แล้ว '.$processed.' รายการ';

                            header('Location: index.php?page=posts');

                            exit;

                        }

                    }

                }

            }

        }

    }

    if ($action==='add_tax') {

        $type=isset($_POST['tax_type'])&&is_string($_POST['tax_type'])?$_POST['tax_type']:'';

        if(!in_array($type,['studio','actor','tag'],true))$msg= __('ชนิดข้อมูลไม่ถูกต้อง');

        $table=$type==='studio'?'studios':($type==='actor'?'actors':'tags');

        $imageField=$type==='studio'?'logoUpload':($type==='actor'?'photoUpload':'');

        $imageUpload=$imageField!==''?storeImageUpload($imageField):['path'=>'','error'=>''];

        $imageInput=$type==='studio'?($_POST['logo']??''):($type==='actor'?($_POST['photo']??''):'');

        if(!is_string($imageInput))$imageInput='';

        $imageValue=$imageUpload['path']!==''?$imageUpload['path']:trim($imageInput);

        $imageError=$imageUpload['error']!==''?$imageUpload['error']:(($imageValue!==''&&!validImageReference($imageValue))? __('ลิงก์รูปภาพต้องเป็น URL http(s) หรือพาธรูปภายในเว็บไซต์'):'');

        $nameInput=$_POST['name']??'';$slugInput=$_POST['slug']??'';$titleInput=$_POST['metaTitle']??'';$descInput=$_POST['metaDesc']??'';$indexChoice=isset($_POST['indexable'])&&is_string($_POST['indexable'])?$_POST['indexable']:'inherit';

        $name=is_string($nameInput)?trim($nameInput):'';$metaTitle=is_string($titleInput)?trim($titleInput):'';$metaDesc=is_string($descInput)?trim($descInput):'';

        $descriptionInput=$type==='studio'?($_POST['desc']??''):($type==='actor'?($_POST['bio']??''):'');

        if($msg===''&&(!is_string($nameInput)||!is_string($slugInput)||!is_string($titleInput)||!is_string($descInput)||!is_string($descriptionInput)))$msg= __('ข้อมูลรายการไม่ถูกต้อง');

        elseif($msg===''&&$imageError!=='')$msg=$imageError;

        elseif($msg===''&&(mb_strlen($metaTitle,'UTF-8')>200||mb_strlen($metaDesc,'UTF-8')>300))$msg= __('SEO Title ต้องไม่เกิน 200 ตัวอักษร และ Meta Description ต้องไม่เกิน 300 ตัวอักษร');
        elseif($msg===''&&!in_array($indexChoice,['inherit','index','noindex'],true))$msg='สถานะ Index ไม่ถูกต้อง';

        elseif($msg===''&&$name==='')$msg= __('กรุณาระบุชื่อรายการ');

        elseif($msg===''&&$name!=='') {

            $nameE = mysqli_real_escape_string($conn, $name);

            try {$slugE = mysqli_real_escape_string($conn, uniqueSlugForName($conn,$table,trim($slugInput)!==''?trim($slugInput):$name));}

            catch(Throwable $error) {$msg='สร้าง Slug ไม่สำเร็จ: '.$error->getMessage();$slugE='';}

            if ($msg==='') {

                $metaTitleE=mysqli_real_escape_string($conn,$metaTitle);$metaDescE=mysqli_real_escape_string($conn,$metaDesc);

                if ($type==='studio') { $logoE=mysqli_real_escape_string($conn,$imageValue); $descE=mysqli_real_escape_string($conn,trim($descriptionInput)); $ok=mysqli_query($conn, "INSERT INTO studios (name,slug,logo,`desc`,metaTitle,metaDesc) VALUES ('$nameE','$slugE','$logoE','$descE','$metaTitleE','$metaDescE')"); }

                elseif ($type==='actor') { $photoE=mysqli_real_escape_string($conn,$imageValue); $bioE=mysqli_real_escape_string($conn,trim($descriptionInput)); $ok=mysqli_query($conn, "INSERT INTO actors (name,slug,photo,bio,metaTitle,metaDesc) VALUES ('$nameE','$slugE','$photoE','$bioE','$metaTitleE','$metaDescE')"); }

                else $ok=mysqli_query($conn, "INSERT INTO tags (name,slug,metaTitle,metaDesc) VALUES ('$nameE','$slugE','$metaTitleE','$metaDescE')");

                if(!$ok)$msg='เพิ่มรายการไม่สำเร็จ: '.mysqli_error($conn);

                else { $newTaxId=(int)mysqli_insert_id($conn); try { if($newTaxId>0&&$indexChoice!=='inherit')saveSeoIndexOverride($type,$newTaxId,$indexChoice==='index',$conn); } catch(Throwable $indexError) { $msg='เพิ่มรายการแล้ว แต่บันทึกสถานะ Index ไม่สำเร็จ: '.$indexError->getMessage(); } logActivity('เพิ่ม'.($type==='studio'?'ค่าย':($type==='actor'?'นักแสดง':'แท็ก')).': '.$name); }

            }

        }

        if ($msg==='') { header('Location: index.php?page='.($type==='studio'?'studios':($type==='actor'?'actors':'tags'))); exit; }

    }

        if ($action==='save_tax') {

            $type=isset($_POST['tax_type'])?$_POST['tax_type']:'';

            $table=$type==='studio'?'studios':($type==='actor'?'actors':($type==='tag'?'tags':''));

            if($table==='') { $msg= __('ชนิดข้อมูลไม่ถูกต้อง'); }

            else {

                $id=(int)($_POST['id']??0);$name=trim($_POST['name']??'');$slug=trim($_POST['slug']??'');

                $currentTax=$id>0?mysqli_fetch_assoc(mysqli_query($conn,"SELECT name,slug FROM $table WHERE id=$id LIMIT 1")):null;

                if($slug===''||($currentTax&&$slug===$currentTax['slug']&&$name!==$currentTax['name']))$slug=uniqueSlugForName($conn,$table,$name,$id);

                if($id<1||$name==='')$msg= __('กรุณาระบุชื่อรายการ');

                else {

                    $metaTitle=trim(is_string($_POST['metaTitle']??'')?$_POST['metaTitle']:'');
                    $metaDesc=trim(is_string($_POST['metaDesc']??'')?$_POST['metaDesc']:'');
                    $indexChoice=isset($_POST['indexable'])&&is_string($_POST['indexable'])?$_POST['indexable']:'inherit';
                    if(mb_strlen($metaTitle,'UTF-8')>200||mb_strlen($metaDesc,'UTF-8')>300)$msg='SEO Title ต้องไม่เกิน 200 ตัวอักษร และ Meta Description ต้องไม่เกิน 300 ตัวอักษร';
                    elseif(!in_array($indexChoice,['inherit','index','noindex'],true))$msg='สถานะ Index ไม่ถูกต้อง';
                    else {
                        if($type==='studio') {
                            $upload=storeImageUpload('logoUpload');$logo=$upload['path']!==''?$upload['path']:(is_string($_POST['logo']??'')?trim($_POST['logo']):'');
                            $description=is_string($_POST['desc']??'')?trim($_POST['desc']):'';
                            if($upload['error']!=='')$msg=$upload['error'];elseif($logo!==''&&!validImageReference($logo))$msg='ลิงก์โลโก้ไม่ถูกต้อง';
                            if($msg==='')$stmt=mysqli_prepare($conn,'UPDATE studios SET name=?,slug=?,logo=?,`desc`=?,metaTitle=?,metaDesc=? WHERE id=?');
                            if($msg===''&&!$stmt)$msg='เตรียมบันทึกข้อมูลค่ายไม่สำเร็จ: '.mysqli_error($conn);
                            if($msg===''){mysqli_stmt_bind_param($stmt,'ssssssi',$name,$slug,$logo,$description,$metaTitle,$metaDesc,$id);if(!mysqli_stmt_execute($stmt))$msg='บันทึกค่ายไม่สำเร็จ: '.mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);}
                        } elseif($type==='actor') {
                            $upload=storeImageUpload('photoUpload');$photo=$upload['path']!==''?$upload['path']:(is_string($_POST['photo']??'')?trim($_POST['photo']):'');
                            $bio=is_string($_POST['bio']??'')?trim($_POST['bio']):'';
                            if($upload['error']!=='')$msg=$upload['error'];elseif($photo!==''&&!validImageReference($photo))$msg='ลิงก์รูปนักแสดงไม่ถูกต้อง';
                            if($msg==='')$stmt=mysqli_prepare($conn,'UPDATE actors SET name=?,slug=?,photo=?,bio=?,metaTitle=?,metaDesc=? WHERE id=?');
                            if($msg===''&&!$stmt)$msg='เตรียมบันทึกข้อมูลนักแสดงไม่สำเร็จ: '.mysqli_error($conn);
                            if($msg===''){mysqli_stmt_bind_param($stmt,'ssssssi',$name,$slug,$photo,$bio,$metaTitle,$metaDesc,$id);if(!mysqli_stmt_execute($stmt))$msg='บันทึกนักแสดงไม่สำเร็จ: '.mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);}
                        } else {
                            $stmt=mysqli_prepare($conn,'UPDATE tags SET name=?,slug=?,metaTitle=?,metaDesc=? WHERE id=?');
                            if(!$stmt)$msg='เตรียมบันทึกแท็กไม่สำเร็จ: '.mysqli_error($conn);
                            else {mysqli_stmt_bind_param($stmt,'ssssi',$name,$slug,$metaTitle,$metaDesc,$id);if(!mysqli_stmt_execute($stmt))$msg='บันทึกแท็กไม่สำเร็จ: '.mysqli_stmt_error($stmt);mysqli_stmt_close($stmt);}
                        }
                        if($msg==='') {
                            try {if($indexChoice==='inherit')resetSeoIndexOverride($type,$id,$conn);else saveSeoIndexOverride($type,$id,$indexChoice==='index',$conn);}
                            catch(Throwable $error){$msg='บันทึกสถานะ Index ไม่สำเร็จ: '.$error->getMessage();}
                        }
                        if($msg===''){if($currentTax)saveSlugAlias($conn,$type,$currentTax['slug'],$slug);logActivity('แก้ไขข้อมูล/SEO '.($type==='studio'?'ค่าย':($type==='actor'?'นักแสดง':'แท็ก')).': '.$name);$msg=__('บันทึกรายการแล้ว');}
                    }
                }

                if($msg==='') {header('Location: index.php?page='.($type==='studio'?'studios':($type==='actor'?'actors':'tags')));exit;}

            }

        }

        if ($action==='save_category') {

            $id=(int)($_POST['id']??0);$name=trim($_POST['name']??'');$slug=trim($_POST['slug']??'');

            $oldCategory=$id>0?mysqli_fetch_assoc(mysqli_query($conn,"SELECT name,slug FROM categories WHERE id=$id LIMIT 1")):null;

            if($oldCategory&&$slug===$oldCategory['slug']&&$name===$oldCategory['name'])$slug=(string)$oldCategory['slug'];
            else $slug=uniqueSlugForName($conn,'categories',$slug!==''?$slug:$name,$id);

            if($name==='')$msg= __('กรุณาระบุชื่อหมวดหมู่');

            elseif($id>0&&!$oldCategory)$msg= __('ไม่พบหมวดหมู่ที่ต้องการแก้ไข');

            else {

                $metaTitle=trim(is_string($_POST['metaTitle']??'')?$_POST['metaTitle']:'');$metaDesc=trim(is_string($_POST['metaDesc']??'')?$_POST['metaDesc']:'');
                $indexChoice=isset($_POST['indexable'])&&is_string($_POST['indexable'])?$_POST['indexable']:'inherit';
                if(mb_strlen($metaTitle,'UTF-8')>200||mb_strlen($metaDesc,'UTF-8')>300)$msg='SEO Title ต้องไม่เกิน 200 ตัวอักษร และ Meta Description ต้องไม่เกิน 300 ตัวอักษร';
                elseif(!in_array($indexChoice,['inherit','index','noindex'],true))$msg='สถานะ Index ไม่ถูกต้อง';

                if($msg!== '') { /* validation error: leave records unchanged */ }
                elseif(!mysqli_begin_transaction($conn))$msg='เริ่มบันทึกหมวดหมู่ไม่สำเร็จ: '.mysqli_error($conn);

                else {

                    if($id>0)$stmt=mysqli_prepare($conn,"UPDATE categories SET name=?,slug=?,metaTitle=?,metaDesc=? WHERE id=?");

                    else $stmt=mysqli_prepare($conn,"INSERT INTO categories (name,slug,metaTitle,metaDesc) VALUES (?,?,?,?)");

                    if(!$stmt)$msg='เตรียมบันทึกหมวดหมู่ไม่สำเร็จ: '.mysqli_error($conn);

                    else {

                        if($id>0)mysqli_stmt_bind_param($stmt,'ssssi',$name,$slug,$metaTitle,$metaDesc,$id);

                        else mysqli_stmt_bind_param($stmt,'ssss',$name,$slug,$metaTitle,$metaDesc);

                        if(!mysqli_stmt_execute($stmt))$msg='บันทึกหมวดหมู่ไม่สำเร็จ: '.mysqli_stmt_error($stmt);

                        mysqli_stmt_close($stmt);

                    }

                    if($msg===''&&$oldCategory&&$oldCategory['name']!==$name) {

                        $postResult=mysqli_query($conn,"SELECT id,category FROM posts");

                        if(!$postResult)$msg='อ่านโพสต์เพื่อแก้ชื่อหมวดหมู่ไม่สำเร็จ: '.mysqli_error($conn);

                        else {

                            $postUpdate=mysqli_prepare($conn,"UPDATE posts SET category=?,updatedAt=NOW() WHERE id=?");

                            if(!$postUpdate)$msg='เตรียมแก้หมวดหมู่ของโพสต์ไม่สำเร็จ: '.mysqli_error($conn);

                            else {

                                while($post=mysqli_fetch_assoc($postResult)) {

                                    $names=splitTaxonomyNames($post['category']);

                                    if(!in_array($oldCategory['name'],$names,true))continue;

                                    $names=array_map(function($value)use($oldCategory,$name){return $value===$oldCategory['name']?$name:$value;},$names);

                                    $updated=implode(', ',$names);$postId=(int)$post['id'];

                                    mysqli_stmt_bind_param($postUpdate,'si',$updated,$postId);

                                    if(!mysqli_stmt_execute($postUpdate)){$msg='อัปเดตหมวดหมู่ของโพสต์ '.$postId.' ไม่สำเร็จ: '.mysqli_stmt_error($postUpdate);break;}

                                }

                                mysqli_stmt_close($postUpdate);

                            }

                        }

                    }

                    if($msg===''&&$oldCategory) {

                        try {saveSlugAlias($conn,'category',$oldCategory['slug'],$slug);}

                        catch(Throwable $error) {$msg='บันทึกเส้นทาง Slug เดิมไม่สำเร็จ: '.$error->getMessage();}

                    }

                    if($msg==='') {
                        try {
                            if($id>0) {if($indexChoice==='inherit')resetSeoIndexOverride('category',$id,$conn);else saveSeoIndexOverride('category',$id,$indexChoice==='index',$conn);}
                            else { $newCategoryId=(int)mysqli_insert_id($conn); if($newCategoryId>0&&$indexChoice!=='inherit')saveSeoIndexOverride('category',$newCategoryId,$indexChoice==='index',$conn); }
                        } catch(Throwable $indexError) {$msg='บันทึกสถานะ Index ไม่สำเร็จ: '.$indexError->getMessage();}
                    }
                    if($msg==='') {

                        if(!mysqli_commit($conn))$msg='ยืนยันการบันทึกหมวดหมู่ไม่สำเร็จ: '.mysqli_error($conn);

                    }

                    if($msg!=='')mysqli_rollback($conn);

                    else {

                        logActivity(($id?'แก้ไข':'เพิ่ม').'หมวดหมู่: '.$name);

                        $_SESSION['avsf_admin_notice']= __('บันทึกหมวดหมู่แล้ว');

                        header('Location: index.php?page=categories');exit;

                    }

                }

            }

        }

        if ($action==='delete_category') {

            $id=(int)($_POST['id']??0);

            $category=$id>0?mysqli_fetch_assoc(mysqli_query($conn,"SELECT name FROM categories WHERE id=$id LIMIT 1")):null;

            $used=false;

            $postResult=$category?mysqli_query($conn,"SELECT category FROM posts WHERE category IS NOT NULL AND category<>''"):false;

            if($category&&!$postResult)$msg='ตรวจสอบโพสต์ของหมวดหมู่ไม่สำเร็จ: '.mysqli_error($conn);

            if($category&&$postResult)while($post=mysqli_fetch_assoc($postResult))if(in_array($category['name'],splitTaxonomyNames($post['category']),true)){$used=true;break;}

            if($msg===''&&$used)$msg= __('ลบไม่ได้: ยังมีโพสต์ใช้หมวดหมู่นี้ กรุณาแก้หมวดในโพสต์ก่อน');

            elseif($msg==='') {

                if($id>0&&!mysqli_query($conn,"DELETE FROM categories WHERE id=$id"))$msg='ลบหมวดหมู่ไม่สำเร็จ: '.mysqli_error($conn);

                else {logActivity('ลบหมวดหมู่');header('Location: index.php?page=categories');exit;}

            }

        }

    if ($action==='delete_tax') { $type=$_POST['tax_type']; $table=$type==='studio'?'studios':($type==='actor'?'actors':'tags'); mysqli_query($conn, "DELETE FROM $table WHERE id=".intval($_POST['id'])); logActivity('ลบ'.($type==='studio'?'ค่าย':($type==='actor'?'นักแสดง':'แท็ก'))); header('Location: index.php?page='.($type==='studio'?'studios':($type==='actor'?'actors':'tags'))); exit; }

    if ($action==='bulk_delete_taxonomies') {
        $type=isset($_POST['tax_type'])&&is_string($_POST['tax_type'])?$_POST['tax_type']:'';
        $tableMap=['category'=>'categories','studio'=>'studios','actor'=>'actors','tag'=>'tags'];
        $pageMap=['category'=>'categories','studio'=>'studios','actor'=>'actors','tag'=>'tags'];
        $rawIds=isset($_POST['taxonomy_ids'])&&is_array($_POST['taxonomy_ids'])?$_POST['taxonomy_ids']:[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$rawIds),function($id){return $id>0;})));
        if(!isset($tableMap[$type])) {
            $msg= __('ชนิด Taxonomy ไม่ถูกต้อง');$msgType='error';
        } elseif(!$ids) {
            $msg= __('กรุณาเลือกอย่างน้อย 1 รายการก่อนลบ');$msgType='error';
        } else {
            $table=$tableMap[$type];$idsSql=implode(',',array_map('intval',$ids));$deleteError='';$deletedCount=0;
            ensureSeoIndexOverridesTable($conn);
            if(!mysqli_begin_transaction($conn))$deleteError='เริ่มรายการลบไม่สำเร็จ: '.mysqli_error($conn);
            else {
                if($type==='category') {
                    $terms=[];$termResult=mysqli_query($conn,"SELECT id,name FROM categories WHERE id IN ($idsSql)");
                    if(!$termResult)$deleteError='อ่านหมวดหมู่ที่เลือกไม่สำเร็จ: '.mysqli_error($conn);
                    else while($term=mysqli_fetch_assoc($termResult))$terms[(int)$term['id']]=$term['name'];
                    if($deleteError===''&&$terms) {
                        $nameSet=array_fill_keys(array_values($terms),true);
                        $postsResult=mysqli_query($conn,"SELECT id,category FROM posts WHERE category IS NOT NULL AND category<>''");
                        if(!$postsResult)$deleteError='อ่านความสัมพันธ์หมวดหมู่ในโพสต์ไม่สำเร็จ: '.mysqli_error($conn);
                        else {
                            $updatePost=mysqli_prepare($conn,'UPDATE posts SET category=?,updatedAt=NOW() WHERE id=?');
                            if(!$updatePost)$deleteError='เตรียมอัปเดตหมวดหมู่ในโพสต์ไม่สำเร็จ: '.mysqli_error($conn);
                            else {
                                while($post=mysqli_fetch_assoc($postsResult)) {
                                    $names=splitTaxonomyNames($post['category']);
                                    $filtered=array_values(array_filter($names,function($name)use($nameSet){return !isset($nameSet[$name]);}));
                                    if(count($filtered)===count($names))continue;
                                    $categoryValue=implode(', ',$filtered);$postId=(int)$post['id'];
                                    mysqli_stmt_bind_param($updatePost,'si',$categoryValue,$postId);
                                    if(!mysqli_stmt_execute($updatePost)){$deleteError='ลบความสัมพันธ์หมวดหมู่ในโพสต์ไม่สำเร็จ: '.mysqli_stmt_error($updatePost);break;}
                                }
                                mysqli_stmt_close($updatePost);
                            }
                            mysqli_free_result($postsResult);
                        }
                    }
                } elseif($type==='studio') {
                    if(!mysqli_query($conn,"UPDATE posts SET studioId=0,updatedAt=NOW() WHERE studioId IN ($idsSql)"))$deleteError='นำค่ายออกจากโพสต์ไม่สำเร็จ: '.mysqli_error($conn);
                } else {
                    $column=$type==='actor'?'actorIds':'tagIds';
                    $postsResult=mysqli_query($conn,"SELECT id,`$column` FROM posts WHERE `$column` IS NOT NULL AND `$column`<>''");
                    if(!$postsResult)$deleteError='อ่านความสัมพันธ์ Taxonomy ในโพสต์ไม่สำเร็จ: '.mysqli_error($conn);
                    else {
                        $updatePost=mysqli_prepare($conn,"UPDATE posts SET `$column`=?,updatedAt=NOW() WHERE id=?");
                        if(!$updatePost)$deleteError='เตรียมอัปเดตโพสต์ไม่สำเร็จ: '.mysqli_error($conn);
                        else {
                            $removeSet=array_fill_keys($ids,true);
                            while($post=mysqli_fetch_assoc($postsResult)) {
                                $currentIds=parseTaxonomyIds($post[$column]);
                                $filtered=array_values(array_filter($currentIds,function($id)use($removeSet){return !isset($removeSet[(int)$id]);}));
                                if(count($filtered)===count($currentIds))continue;
                                $encoded=json_encode($filtered);$postId=(int)$post['id'];
                                mysqli_stmt_bind_param($updatePost,'si',$encoded,$postId);
                                if(!mysqli_stmt_execute($updatePost)){$deleteError='อัปเดตความสัมพันธ์ในโพสต์ไม่สำเร็จ: '.mysqli_stmt_error($updatePost);break;}
                            }
                            mysqli_stmt_close($updatePost);
                        }
                        mysqli_free_result($postsResult);
                    }
                }
                if($deleteError==='') {
                    if(!mysqli_query($conn,"DELETE FROM `$table` WHERE id IN ($idsSql)"))$deleteError='ลบรายการ Taxonomy ไม่สำเร็จ: '.mysqli_error($conn);
                    else $deletedCount=mysqli_affected_rows($conn);
                }
                if($deleteError==='') {
                    $typeE=mysqli_real_escape_string($conn,$type);
                    if(!mysqli_query($conn,"DELETE FROM seo_index_overrides WHERE entity_type='$typeE' AND entity_id IN ($idsSql)"))$deleteError='ลบค่า SEO Index Override ไม่สำเร็จ: '.mysqli_error($conn);
                }
                if($deleteError==='') {
                    if(!mysqli_commit($conn))$deleteError='ยืนยันการลบไม่สำเร็จ: '.mysqli_error($conn);
                }
                if($deleteError!=='')mysqli_rollback($conn);
            }
            if($deleteError!=='') {
                $msg=$deleteError;$msgType='error';
            } else {
                $labelMap=['category'=> __('หมวดหมู่'),'studio'=> __('ค่าย'),'actor'=> __('นักแสดง'),'tag'=> __('แท็ก')];
                logActivity('ลบ '.$labelMap[$type].' หลายรายการ ('.$deletedCount.' รายการ)');
                $clearedCount=AVSFCache::clearAll(AVSF_CACHE_DIR);
                $_SESSION['avsf_admin_notice']='ลบรายการที่เลือกแล้ว '.$deletedCount.' รายการ · ล้างแคช '.$clearedCount.' ไฟล์';
                header('Location: index.php?page='.$pageMap[$type]);exit;
            }
        }
    }

    if ($action==='save_page') {

        $id=intval($_POST['id']); $title=trim($_POST['title']); $postedSlug=trim($_POST['slug']);

        $oldPage=$id?mysqli_fetch_assoc(mysqli_query($conn,"SELECT title,slug FROM pages WHERE id=$id LIMIT 1")):null;

        $slug=($oldPage&&$postedSlug===$oldPage['slug']&&$title!==$oldPage['title'])

            ?uniqueSlugForName($conn,'pages',$title,$id)

            :($postedSlug?:uniqueSlugForName($conn,'pages',$title,$id));

        $titleE=mysqli_real_escape_string($conn,$title); $slugE=mysqli_real_escape_string($conn,$slug); $contentE=mysqli_real_escape_string($conn,is_string($_POST['content']??'')?$_POST['content']:'');
        $metaTitle=trim(is_string($_POST['metaTitle']??'')?$_POST['metaTitle']:'');$metaDesc=trim(is_string($_POST['metaDesc']??'')?$_POST['metaDesc']:'');
        $metaTitleE=mysqli_real_escape_string($conn,$metaTitle);$metaDescE=mysqli_real_escape_string($conn,$metaDesc);
        $indexChoice=isset($_POST['indexable'])&&is_string($_POST['indexable'])?$_POST['indexable']:'inherit';
        $status=($_POST['status']??'')==='publish'?'publish':'draft';
        if(mb_strlen($metaTitle,'UTF-8')>200||mb_strlen($metaDesc,'UTF-8')>300||!in_array($indexChoice,['inherit','index','noindex'],true))$msg='ข้อมูล SEO ไม่ถูกต้องหรือยาวเกินกำหนด';
        else {
            if ($id) {$ok=mysqli_query($conn,"UPDATE pages SET title='$titleE',slug='$slugE',content='$contentE',status='$status',metaTitle='$metaTitleE',metaDesc='$metaDescE' WHERE id=$id");if($ok&&$oldPage)saveSlugAlias($conn,'page',$oldPage['slug'],$slug);}
            else {$ok=mysqli_query($conn,"INSERT INTO pages (title,slug,content,status,date,metaTitle,metaDesc) VALUES ('$titleE','$slugE','$contentE','$status',CURDATE(),'$metaTitleE','$metaDescE')");$id=(int)mysqli_insert_id($conn);}
            if(!$ok)$msg='บันทึกหน้าเว็บไม่สำเร็จ: '.mysqli_error($conn);
            else {try{if($indexChoice==='inherit')resetSeoIndexOverride('page',$id,$conn);else saveSeoIndexOverride('page',$id,$indexChoice==='index',$conn);}catch(Throwable $indexError){$msg='บันทึกสถานะ Index ไม่สำเร็จ: '.$indexError->getMessage();}}
            if($msg===''){logActivity('บันทึกหน้าเว็บ: '.$title);header('Location: index.php?page=pages');exit;}
        }

    }

    if ($action==='delete_page') { mysqli_query($conn,"DELETE FROM pages WHERE id=".intval($_POST['id'])); header('Location: index.php?page=pages'); exit; }

    if ($action==='save_banner') {

        $upload=storeImageUpload('bannerImageUpload');

        $imageValue=$upload['path']!==''?$upload['path']:trim(isset($_POST['image'])?$_POST['image']:'');

        if ($upload['error']!=='') $msg=$upload['error'];

        elseif (!validImageReference($imageValue)) $msg= __('ลิงก์รูปภาพต้องเป็น URL http(s) หรือพาธรูปภายในเว็บไซต์');

        else {

            $id=intval($_POST['id']); $titleE=mysqli_real_escape_string($conn,trim($_POST['title'])); $linkE=mysqli_real_escape_string($conn,trim($_POST['link'])); $imgE=mysqli_real_escape_string($conn,$imageValue); $textE=mysqli_real_escape_string($conn,trim($_POST['text'])); $active=isset($_POST['active'])?1:0;

            if ($id) mysqli_query($conn,"UPDATE banners SET title='$titleE',link='$linkE',image='$imgE',text='$textE',active=$active WHERE id=$id");

            else mysqli_query($conn,"INSERT INTO banners (title,link,image,text,active) VALUES ('$titleE','$linkE','$imgE','$textE',$active)");

            logActivity('บันทึกแบนเนอร์'); header('Location: index.php?page=banners'); exit;

        }

    }

    if ($action==='upload_media') {

        $upload=storeImageUpload('mediaUpload');

        if ($upload['error']!=='') $msg=$upload['error'];

        else { logActivity('อัปโหลดรูปภาพ'); header('Location: index.php?page=media'); exit; }

    }

    if (in_array($action,['save_media_details','delete_media','bulk_delete_media'],true)) {

        $mediaMetadata=json_decode(setting('media_metadata_v1','{}'),true);

        if(!is_array($mediaMetadata))$mediaMetadata=[];

        $uploadDirectory=realpath(AVSF_UPLOAD_DIR);

        $resolveUpload=function($source)use($uploadDirectory) {

            if(!$uploadDirectory||!is_string($source))return false;

            $sourcePath=parse_url($source,PHP_URL_PATH);
            if(!is_string($sourcePath)||$sourcePath==='')return false;
            $decodedPath=rawurldecode($sourcePath);
            if(!preg_match('~(?:^|/)uploads/([^/]+)$~u',$decodedPath,$match))return false;
            $filename=$match[1];
            $extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
            if($filename===''||$filename==='.'||$filename==='..'||strpos($filename,"\0")!==false
                ||!in_array($extension,['jpg','jpeg','png','gif','webp'],true))return false;

            $target=$uploadDirectory.DIRECTORY_SEPARATOR.$filename;
            $file=realpath($target);

            return $file&&is_file($file)&&!is_link($target)
                &&strpos($file,$uploadDirectory.DIRECTORY_SEPARATOR)===0?$file:false;

        };

        $isReferenced=function($source)use($conn) {

            static $referencedFiles=null;
            $sourcePath=parse_url((string)$source,PHP_URL_PATH);
            $filename=is_string($sourcePath)?rawurldecode(basename($sourcePath)):'';
            $extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
            if($filename===''||!in_array($extension,['jpg','jpeg','png','gif','webp'],true))return true;

            if($referencedFiles!==null)return isset($referencedFiles[$filename]);

            $referencedFiles=[];

            $queries=[

                "SELECT cover,gallery,content FROM posts",

                "SELECT image FROM banners",

                "SELECT content FROM pages",

                "SELECT svalue FROM settings WHERE skey<>'media_metadata_v1'"

            ];

            foreach($queries as $sql) {

                $result=mysqli_query($conn,$sql);

                if(!$result){$referencedFiles=null;return true;}

                while($row=mysqli_fetch_assoc($result))foreach($row as $value) {

                    if(is_string($value)&&preg_match_all("~uploads/([^/?#\"'<>\\s]+)~u",$value,$matches)) {

                        foreach($matches[1] as $match) {
                            $referencedName=rawurldecode(basename(str_replace('\\','/',$match)));
                            if($referencedName!=='')$referencedFiles[$referencedName]=true;
                        }

                    }

                }

            }

            return isset($referencedFiles[$filename]);

        };

        if($action==='save_media_details') {

            $source=$_POST['media_source']??'';

            $title=$_POST['media_title']??'';$alt=$_POST['media_alt']??'';$description=$_POST['media_description']??'';

            if(!is_string($source)||!validImageReference($source)||!is_string($title)||!is_string($alt)||!is_string($description)

                ||mb_strlen($source,'UTF-8')>2000||mb_strlen($title,'UTF-8')>200||mb_strlen($alt,'UTF-8')>300||mb_strlen($description,'UTF-8')>2000) {

                $msg= __('ข้อมูลรายละเอียดรูปภาพไม่ถูกต้อง');$msgType='error';

            } else {

                $mediaMetadata[$source]=['title'=>trim($title),'alt'=>trim($alt),'description'=>trim($description)];

                if(saveSetting('media_metadata_v1',json_encode($mediaMetadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))) {

                    logActivity('แก้ไขรายละเอียดรูปภาพ');$msg= __('บันทึกรายละเอียดรูปภาพแล้ว');

                } else {$msg= __('บันทึกรายละเอียดรูปภาพไม่สำเร็จ');$msgType='error';}

            }

        } else {

            $sources=$action==='delete_media'?[$_POST['media_source']??'']:(isset($_POST['media_sources'])&&is_array($_POST['media_sources'])?$_POST['media_sources']:[]);

            $sources=array_values(array_unique(array_filter($sources,'is_string')));

            if(!$sources||count($sources)>200) {

                $msg= __('กรุณาเลือกรูปภาพอย่างน้อย 1 รูป (สูงสุด 200 รูปต่อครั้ง)');$msgType='error';

            } else {

                $deleted=0;$blocked=0;

                foreach($sources as $source) {

                    $file=$resolveUpload($source);

                    if(!$file||$isReferenced($source)){$blocked++;continue;}

                    if(!unlink($file)){$blocked++;continue;}

                    unset($mediaMetadata[$source]);$deleted++;

                }

                if($deleted&&!saveSetting('media_metadata_v1',json_encode($mediaMetadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))) {

                    $msg= __('ลบรูปภาพแล้ว แต่บันทึกข้อมูลกำกับรูปที่เหลือไม่สำเร็จ');$msgType='error';

                } else {

                    logActivity('ลบรูปภาพจากคลังสื่อ '.$deleted.' รูป');

                    $msg=__('ลบรูปภาพแล้ว %s', [$deleted.' รูป'.($blocked?' · ข้าม '.$blocked.' รูปที่ยังถูกใช้งานหรือไม่สามารถลบได้':'')]);

                    if($blocked)$msgType='error';

                }

            }

        }

    }

    if ($action==='delete_banner') { mysqli_query($conn,"DELETE FROM banners WHERE id=".intval($_POST['id'])); header('Location: index.php?page=banners'); exit; }

    if ($action==='toggle_cmt') { $id=intval($_POST['id']); $r=mysqli_fetch_assoc(mysqli_query($conn,"SELECT approved FROM comments WHERE id=$id")); $new=$r['approved']?0:1; mysqli_query($conn,"UPDATE comments SET approved=$new WHERE id=$id"); header('Location: index.php?page=comments'); exit; }

    if ($action==='delete_cmt') { mysqli_query($conn,"DELETE FROM comments WHERE id=".intval($_POST['id'])); header('Location: index.php?page=comments'); exit; }

    if ($action==='save_site_content') {

        $menuConfigs=[];$menuError='';

        $featuredLimitValue=isset($_POST['homeFeaturedLimit'])&&is_string($_POST['homeFeaturedLimit'])?$_POST['homeFeaturedLimit']:'12';

        $bannerIntervalValue=isset($_POST['bannerInterval'])&&is_string($_POST['bannerInterval'])?$_POST['bannerInterval']:'3';

        $featuredIntervalValue=isset($_POST['featuredInterval'])&&is_string($_POST['featuredInterval'])?$_POST['featuredInterval']:'6';

        $featuredLimit=ctype_digit($featuredLimitValue)?(int)$featuredLimitValue:0;

        $bannerInterval=ctype_digit($bannerIntervalValue)?(int)$bannerIntervalValue:0;

        $featuredInterval=ctype_digit($featuredIntervalValue)?(int)$featuredIntervalValue:0;

        if(!in_array($featuredLimit,[4,6,8,10,12,16,20,24],true)||$bannerInterval<3||$bannerInterval>30||$featuredInterval<3||$featuredInterval>30) {

            $menuError= __('ค่าจำนวนการ์ดหรือความเร็วสไลด์อยู่นอกช่วงที่กำหนด');

        }

        foreach(['header'=>6,'explore'=>6,'bottom'=>4] as $menuName=>$slotCount) {

            $labels=isset($_POST['menu_'.$menuName.'_label'])&&is_array($_POST['menu_'.$menuName.'_label'])?$_POST['menu_'.$menuName.'_label']:[];

            $destinations=isset($_POST['menu_'.$menuName.'_destination'])&&is_array($_POST['menu_'.$menuName.'_destination'])?$_POST['menu_'.$menuName.'_destination']:[];

            $customUrls=isset($_POST['menu_'.$menuName.'_custom'])&&is_array($_POST['menu_'.$menuName.'_custom'])?$_POST['menu_'.$menuName.'_custom']:[];

            $menuConfigs[$menuName]=[];

            for($slot=0;$slot<$slotCount;$slot++) {

                $labelValue=isset($labels[$slot])?$labels[$slot]:'';

                $destinationValue=isset($destinations[$slot])?$destinations[$slot]:'';

                $customValue=isset($customUrls[$slot])?$customUrls[$slot]:'';

                if(!is_string($labelValue)||!is_string($destinationValue)||!is_string($customValue)) {

                    $menuError= __('ข้อมูลเมนูไม่ถูกต้อง กรุณาตรวจสอบรายการเมนู');

                    break 2;

                }

                $label=trim($labelValue);$destination=trim($destinationValue);$customUrl=trim($customValue);

                if($label===''&&$destination===''&&$customUrl==='')continue;

                if($label===''||$destination==='') {

                    $menuError= __('กรุณาระบุชื่อและปลายทางให้ครบในแต่ละเมนู');

                    break 2;

                }

                $type=$destination;$value='';

                if(preg_match('/^(category|tag|studio|actor|page):([a-z0-9ก-๙-]+)$/iu',$destination,$match)) {

                    $type=$match[1];$value=$match[2];

                } elseif($destination==='custom') {

                    if(!(validAdDestinationUrl($customUrl)||preg_match('/^(?:index\.php\?|\/(?!\/))[^\s<>"]*$/u',$customUrl))) {

                        $menuError= __('ลิงก์ Custom ต้องเป็น URL http(s) ที่ถูกต้อง หรือพาธภายในเว็บไซต์ เช่น index.php?page=all');

                        break 2;

                    }

                    $value=$customUrl;

                } elseif(!in_array($destination,['home','all','categories','studio_list','actor_list','tag_list'],true)) {

                    $menuError= __('พบประเภทเมนูที่ไม่รองรับ กรุณาเลือกปลายทางจากรายการ');

                    break 2;

                }

                $menuConfigs[$menuName][]=['label'=>mb_substr($label,0,60,'UTF-8'),'type'=>$type,'value'=>$value];

            }

        }

        if($menuError==='') {

            $siteFields=[

                'site_home_featured_title'=>['homeFeaturedTitle','แนะนำ'],

                'site_home_featured_all_label'=>['homeFeaturedAllLabel','ดูทั้งหมด'],

                'site_home_latest_title'=>['homeLatestTitle','โพสต์ล่าสุด'],

                'site_banner_accessibility'=>['bannerAccessibility','บทความรหัสหนังใหม่'],

                'site_ad_label'=>['siteAdLabel','โฆษณา'],

                'site_ad_close_label'=>['siteAdCloseLabel','ปิดโฆษณา'],

                'site_search_placeholder'=>['siteSearchPlaceholder','ค้นหา...'],

                'site_search_button'=>['siteSearchButton','ค้นหา'],

                'site_home_featured_limit'=>['homeFeaturedLimit',(string)$featuredLimit],

                'site_banner_interval_seconds'=>['bannerInterval',(string)$bannerInterval],

                'site_home_featured_interval_seconds'=>['featuredInterval',(string)$featuredInterval]

            ];

            foreach($siteFields as $settingKey=>$field) {

                $value=isset($_POST[$field[0]])&&is_string($_POST[$field[0]])?trim($_POST[$field[0]]):'';

                saveSetting($settingKey,mb_substr($value,0,120,'UTF-8'));

            }

            foreach($menuConfigs as $menuName=>$menuItems)saveSetting('site_menu_'.$menuName,json_encode($menuItems,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

            logActivity('แก้ไขเนื้อหาและเมนูหน้าเว็บ');$msg= __('บันทึกเนื้อหาและเมนูหน้าเว็บแล้ว');

        } else {

            $msg=$menuError;

        }

    }

    if ($action==='save_theme') {
        $adminAccent = isset($_POST['adminAccent']) && is_string($_POST['adminAccent']) ? trim($_POST['adminAccent']) : '';
        $siteAccent = isset($_POST['accent']) && is_string($_POST['accent']) ? trim($_POST['accent']) : '';
        $colorFields = [
            'themeBgColor'=>'theme_bg_color',
            'themeTextColor'=>'theme_text_color',
            'themeMutedColor'=>'theme_muted_color',
            'themeAccent2Color'=>'theme_accent2_color',
            'themeLinkColor'=>'theme_link_color',
            'themeButtonBg'=>'theme_button_bg',
            'themeButtonText'=>'theme_button_text',
            'themeCardBg'=>'theme_card_bg',
            'themeCard2Bg'=>'theme_card2_bg',
            'themeBorderColor'=>'theme_border_color',
            'themeMenuBg'=>'theme_menu_bg',
            'themeMenuText'=>'theme_menu_text',
            'themeMenuActiveBg'=>'theme_menu_active_bg',
            'themeMenuActiveText'=>'theme_menu_active_text'
        ];
        $themeColors = [];
        $saveError = '';
        if (!preg_match('/^#[0-9a-f]{6}$/i', $adminAccent) || !preg_match('/^#[0-9a-f]{6}$/i', $siteAccent)) {
            $saveError = __('สีเว็บและสีหลังบ้านต้องเป็นรหัสสี Hex 6 หลัก เช่น #267cf4');
        }
        foreach ($colorFields as $postKey=>$settingKey) {
            $value = isset($_POST[$postKey]) && is_string($_POST[$postKey]) ? trim($_POST[$postKey]) : '';
            if (!preg_match('/^#[0-9a-f]{6}$/i', $value)) { $saveError = 'กรุณาเลือกสีให้ถูกต้องในช่อง ' . $postKey; break; }
            $themeColors[$settingKey] = strtolower($value);
        }
        $cardRadiusRaw = isset($_POST['themeCardRadius']) && is_string($_POST['themeCardRadius']) ? $_POST['themeCardRadius'] : '14';
        $cardRadius = ctype_digit($cardRadiusRaw) ? (int)$cardRadiusRaw : 14;
        $cardShadow = isset($_POST['themeCardShadow']) && is_string($_POST['themeCardShadow']) ? $_POST['themeCardShadow'] : 'soft';
        if ($cardRadius < 8 || $cardRadius > 24) $saveError = __('ความโค้งการ์ดต้องอยู่ระหว่าง 8–24 px');
        if (!in_array($cardShadow, ['none','soft','deep'], true)) $saveError = __('รูปแบบเงาการ์ดไม่ถูกต้อง');
        $logoText = isset($_POST['logoText']) && is_string($_POST['logoText']) ? trim($_POST['logoText']) : '';
        $logoValue = isset($_POST['siteLogo']) && is_string($_POST['siteLogo']) ? trim($_POST['siteLogo']) : trim((string)setting('siteLogo',''));
        if (mb_strlen($logoText, 'UTF-8') > 120) $saveError = __('ข้อความแบรนด์สำรองต้องไม่เกิน 120 ตัวอักษร');
        if (!validImageReference($logoValue)) $saveError = __('URL โลโก้ต้องเป็น HTTPS หรือพาธรูปภายในเว็บไซต์');

        if ($saveError !== '') {
            $msg = $saveError; $msgType = 'error';
        } else {
            $logoUpload = storeImageUpload('themeSiteLogoUpload');
            if ($logoUpload['error'] !== '') { $msg = $logoUpload['error']; $msgType = 'error'; }
            elseif ($logoUpload['path'] !== '') $logoValue = $logoUpload['path'];
            if ($msgType === 'error') { /* upload validation failed; do not save partial theme settings */ }
            elseif (!saveSetting('logoText', $logoText) || !saveSetting('siteLogo', $logoValue) || !saveSetting('theme_accent', $siteAccent) || !saveSetting('admin_accent', $adminAccent)) {
                $msg = __('บันทึกสีหลักไม่สำเร็จ'); $msgType = 'error';
            } else {
                foreach ($themeColors as $key=>$value) {
                    if (!saveSetting($key, $value)) { $saveError = 'บันทึกค่ารูปแบบเว็บไม่สำเร็จ (' . $key . ')'; break; }
                }
                if ($saveError === '') {
                    saveSetting('theme_card_radius', (string)$cardRadius);
                    saveSetting('theme_card_shadow', $cardShadow);
                    foreach (['showViews','showStudio','showTags','showActors','showDuration','showBottomNav','showSearch','showRelated','showBanners','showAnnouncement'] as $key) {
                        saveSetting('theme_' . $key, isset($_POST[$key]) ? '1' : '0');
                    }
                    logActivity('แก้ไขสไตล์ สีลิงก์ ปุ่ม การ์ด และเมนูหน้าเว็บ');
                    $msg = __('บันทึกสไตล์ สีลิงก์ ปุ่ม การ์ด และเมนูเรียบร้อย');
                } else { $msg = $saveError; $msgType = 'error'; }
            }
        }
    }

    if ($action==='save_seo') {

        $robotsValue=isset($_POST['robots'])&&is_string($_POST['robots'])?$_POST['robots']:'index, follow';

        if(!in_array($robotsValue,['index, follow','index, nofollow','noindex, follow','noindex, nofollow'],true))$robotsValue='index, follow';

        $seoSettings=[];$saveError='';

        $socialImageUpload=storeImageUpload('seoSocialImageUpload');

        if($socialImageUpload['error']!=='')$saveError=$socialImageUpload['error'];

        $seoFormFields=['seo_site_name'=>'seoSiteName','seo_meta_home_title'=>'metaHomeTitle','seo_meta_home_desc'=>'metaHomeDescription','seo_primary_keyword'=>'primaryKeyword','seo_secondary_keywords'=>'secondaryKeywords','seo_keywords'=>'keywords','seo_gaId'=>'gaId','seo_robots_txt'=>'robotsTxt','seo_social_title'=>'socialTitle','seo_social_desc'=>'socialDescription','seo_social_image'=>'socialImage'];

        foreach($seoFormFields as $key=>$field) {

            if($saveError!=='')break;

            $value=$_POST[$field]??'';

            if(!is_string($value)){$saveError= __('ข้อมูล SEO ไม่ถูกต้อง');break;}

            $seoSettings[$key]=trim($value);

        }

        if($saveError===''&&$socialImageUpload['path']!=='')$seoSettings['seo_social_image']=$socialImageUpload['path'];

        if($saveError===''&&mb_strlen($seoSettings['seo_site_name'],'UTF-8')>120)$saveError= __('ชื่อเว็บไซต์สำหรับ SEO ต้องไม่เกิน 120 ตัวอักษร');

        if($saveError===''&&(mb_strlen($seoSettings['seo_social_title'],'UTF-8')>200||mb_strlen($seoSettings['seo_social_desc'],'UTF-8')>300||mb_strlen($seoSettings['seo_social_image'],'UTF-8')>2000||($seoSettings['seo_social_image']!==''&&!validImageReference($seoSettings['seo_social_image']))))$saveError= __('ข้อมูล Social Meta ไม่ถูกต้อง หรือ URL รูปภาพไม่รองรับ');

        $twitterCard=isset($_POST['twitterCard'])&&is_string($_POST['twitterCard'])?$_POST['twitterCard']:'summary_large_image';

        if(!in_array($twitterCard,['summary','summary_large_image'],true))$saveError= __('รูปแบบ Twitter Card ไม่ถูกต้อง');

        $seoSettings['seo_social_twitter_card']=$twitterCard;

        $seoSettings['seo_robots']=$robotsValue;

        $separator=isset($_POST['seoTitleSeparator'])&&is_string($_POST['seoTitleSeparator'])?$_POST['seoTitleSeparator']:'|';

        if(!in_array($separator,['|','-','–','»','·','•','/'],true))$saveError= __('ตัวคั่น Title ไม่ถูกต้อง');

        else $seoSettings['seo_title_separator']=$separator;

        if($saveError==='')foreach(['all','categories','studio_list','actor_list','tag_list','page'] as $archiveType) {

            foreach(['title','desc'] as $field) {

                $key='seo_meta_'.$archiveType.'_'.$field;$value=$_POST[$key]??'';

                if(!is_string($value)){$saveError= __('ข้อมูล SEO ไม่ถูกต้อง');break 2;}

                $seoSettings[$key]=trim($value);

            }

        }

        if($saveError==='')foreach(['category','studio','actor','tag'] as $taxonomyType) {

            foreach(['title','desc'] as $field) {

                $key='seo_taxonomy_'.$taxonomyType.'_'.$field;$value=$_POST[$key]??'';

                if(!is_string($value)){$saveError= __('ข้อมูล SEO ไม่ถูกต้อง');break 2;}

                $seoSettings[$key]=trim($value);

            }

        }

        if($saveError==='')foreach($seoSettings as $key=>$value) {

            if(mb_strlen($value,'UTF-8')>5000){$saveError='ค่าตั้งค่า '.$key.' ยาวเกินกำหนด';break;}

            if(!saveSetting($key,$value)){$saveError='บันทึก '.$key.' ไม่สำเร็จ';break;}

        }

        if($saveError===''){logActivity('แก้ไขตั้งค่า SEO META');$msg= __('บันทึก SEO META แล้ว');}

        else{$msg=$saveError;$msgType='error';}

    }

    if ($action==='save_seo_public') {

        $publicKeys=['home_title','home_description','all_title','all_description','categories_title','categories_description','category_title','category_description','studio_list_title','studio_list_description','actor_list_title','actor_list_description','tag_list_title','tag_list_description','studio_title','studio_description','actor_title','actor_description','tag_title','tag_description'];

        $saveError='';$publicSettings=[];

        foreach($publicKeys as $key) {

            $field='seo_public_'.$key;

            $value=$_POST[$field]??'';

            if(!is_string($value)){$saveError= __('ข้อมูลเทมเพลตไม่ถูกต้อง');break;}

            $max=strpos($key,'_title')!==false?200:5000;

            if(mb_strlen($value,'UTF-8')>$max){$saveError= __('ความยาวของข้อมูลเกินกำหนด');break;}

            $publicSettings[$field]=trim($value);

        }

        $position=isset($_POST['seo_public_home_position'])?$_POST['seo_public_home_position']:'top';

        if($saveError===''&&!in_array($position,['top','bottom'],true))$saveError= __('ตำแหน่งเนื้อหาหน้าแรกไม่ถูกต้อง');

        if($saveError==='') {

            $publicSettings['seo_public_home_position']=$position;

            foreach($publicSettings as $key=>$value)if(!saveSetting($key,$value)){$saveError='บันทึก '.$key.' ไม่สำเร็จ';break;}

            if($saveError===''){logActivity('แก้ไข SEO ในเว็บสำหรับผู้ชม');$msg= __('บันทึก SEO ในเว็บแล้ว');header('Location: index.php?page=seo-public');exit;}

        }

        $msg=$saveError;$msgType='error';

    }

    if ($action==='save_system') {

        $videoAdSlots=[];$videoAdError='';

        foreach(['top'=>4,'inside'=>1,'bottom'=>3] as $position=>$slotCount) {

            $destinationKey='videoAd'.ucfirst($position).'Destination';

            $imageKey='videoAd'.ucfirst($position).'Image';

            $destinations=isset($_POST[$destinationKey])&&is_array($_POST[$destinationKey])?$_POST[$destinationKey]:[];

            $images=isset($_POST[$imageKey])&&is_array($_POST[$imageKey])?$_POST[$imageKey]:[];

            for($slot=0;$slot<$slotCount;$slot++) {

                $destinationValue=isset($destinations[$slot])?$destinations[$slot]:'';

                $imageValue=isset($images[$slot])?$images[$slot]:'';

                if(!is_string($destinationValue) || !is_string($imageValue)) {

                    $videoAdError= __('ข้อมูลโฆษณาไม่ถูกต้อง กรุณาตรวจ URL ปลายทางและ URL รูปภาพอีกครั้ง');

                    break 2;

                }

                $destination=trim($destinationValue);

                $image=trim($imageValue);

                if(substr($image,0,2)==='//')$image='https:'.$image;

                if($destination==='' && $image==='')continue;

                if(!validAdDestinationUrl($destination) || $image==='' || !validImageReference($image)) {

                    $videoAdError= __('กรุณากรอก URL ปลายทางแบบ http(s) และ URL รูปภาพที่ถูกต้องให้ครบในแต่ละป้ายโฆษณา');

                    break 2;

                }

                $videoAdSlots[$position][]=['destination'=>$destination,'image'=>$image];

            }

        }

        if($videoAdError==='') {

        saveSetting('siteName', trim($_POST['siteName']));

        saveSetting('siteDesc', trim($_POST['siteDesc']));

        // Footer attribution is generated centrally from Site URL and B-NTP.

        saveSetting('announcement', trim($_POST['announcement']));

        if (trim($_POST['newPass'])) { $p=password_hash(trim($_POST['newPass']), PASSWORD_DEFAULT); $stmt=mysqli_prepare($conn,"UPDATE users SET password=? WHERE username='admin'"); mysqli_stmt_bind_param($stmt,'s',$p); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }

        if(isset($_POST['videoAdTopDestination'])||isset($_POST['videoAdInsideDestination'])||isset($_POST['videoAdBottomDestination']))saveSetting('video_ad_slots',json_encode($videoAdSlots,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        logActivity('แก้ไขตั้งค่าระบบ');

        if ($passwordChangeError !== '') {

            $msg = __('บันทึกการตั้งค่าเว็บไซต์แล้ว แต่%s', [$passwordChangeError]);

            $msgType = 'error';

        } else {

            $msg = __('บันทึกตั้งค่าแล้ว');

        }

        } else {

            $msg=$videoAdError;

        }

    }

    if ($action==='save_card_layout') {
        requirePermission('settings.edit');
        $families = ['mobile','tablet_portrait','tablet_landscape','desktop'];
        $groups = ['posts','studios','actors','categories','tags'];
        $defaults = [
            'columns' => ['mobile'=>2,'tablet_portrait'=>3,'tablet_landscape'=>4,'desktop'=>4],
            'rows' => [
                'posts'=>['mobile'=>6,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'studios'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'actors'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'categories'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'tags'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3]
            ]
        ];
        $values = [];
        $saveError = '';
        foreach ($families as $family) {
            $key = 'card_columns_'.$family;
            $raw = $_POST[$key] ?? '';
            $value = is_string($raw) ? trim($raw) : '';
            $max = $family === 'mobile' ? 3 : 6;
            if (!ctype_digit($value) || (int)$value < 1 || (int)$value > $max) {
                $saveError = __('จำนวนคอลัมน์ของ %s', [($family==='mobile'? __('มือถือ'):($family==='tablet_portrait'? __('แท็บเล็ตแนวตั้ง'):($family==='tablet_landscape'? __('แท็บเล็ตแนวนอน'): __('คอมพิวเตอร์')))).' ต้องอยู่ระหว่าง 1-'.$max]);
                break;
            }
            $values[$key] = (string)(int)$value;
        }
        if ($saveError === '') {
            foreach ($groups as $group) {
                foreach ($families as $family) {
                    $key = 'card_rows_'.$group.'_'.$family;
                    $raw = $_POST[$key] ?? '';
                    $value = is_string($raw) ? trim($raw) : '';
                    if (!ctype_digit($value) || (int)$value < 1 || (int)$value > 20) {
                        $saveError = __('จำนวนแถวของ %s ต้องอยู่ระหว่าง 1-20 แถว', [strtoupper($group)]);
                        break 2;
                    }
                    $values[$key] = (string)(int)$value;
                }
            }
        }
        if ($saveError === '') {
            foreach ($values as $key=>$value) {
                if (!saveSetting($key,$value)) { $saveError= __('บันทึกการตั้งค่าการ์ดไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล'); break; }
            }
        }
        if ($saveError === '') {
            logActivity('แก้ไขการแสดงผลการ์ดและจำนวนรายการต่อหน้า');
            header('Location: index.php?page=settings-card-layout&saved=1');
            exit;
        }
        $msg=$saveError;
        $msgType='error';
    }

    if ($action==='save_settings_section') {

        $section=isset($_POST['settings_section'])?(string)$_POST['settings_section']:'';

        $allowed=[

            'general'=>['siteName','siteUrl','siteDesc','announcement','adminEmail','logoText','siteLanguage','siteTimezone','dateFormat','timeFormat'],

            'connectors'=>['socialFacebook','socialX','socialInstagram','socialTelegram'],

            'writing'=>['defaultCategory','defaultPostFormat','mailServer','mailPort','mailLogin','mailCategory','updateServices'],

            'reading'=>['homeDisplay','homePageId','postsPerPage','feedItems','feedContent','discourageIndexing'],

            'discussion'=>['commentsEnabled','commentsRequireName','commentsRequireApproval','commentsNotifyAdmin'],

            'media'=>['mediaMaxWidth','mediaMaxHeight','mediaQuality','mediaLazyLoad'],

            'permalinks'=>['permalinkStructure','categoryBase','tagBase','actorBase','studioBase'],

            'privacy'=>['privacyPageId','privacyContact'],

            'redis'=>['redisEnabled','redisHost','redisPort','redisDatabase'],

            'vdohide'=>['vdohideApiToken','vdohideSupportedDomains','vdohideIframeDomain']

        ];

        $generalOptionValues=[

            'siteLanguage'=>['th','en'],

            'siteTimezone'=>['Asia/Bangkok','UTC','Asia/Tokyo','Asia/Singapore','Asia/Kuala_Lumpur','Australia/Sydney','Europe/London','America/New_York','America/Los_Angeles'],

            'dateFormat'=>['d M Y','d/m/Y','Y-m-d','F j, Y'],

            'timeFormat'=>['H:i','g:i A'],

        ];

        if(!isset($allowed[$section])){$msg= __('ประเภทการตั้งค่าไม่ถูกต้อง');$msgType='error';}

        else {

            $saveError='';

            $submittedSiteName=$_POST['siteName']??'';

            if($section==='general'&&(!is_string($submittedSiteName)||trim($submittedSiteName)===''))$saveError= __('กรุณาระบุชื่อเว็บไซต์');

            $permalinkValues=[];
            if($section==='permalinks') {
                $structure=isset($_POST['permalinkStructure'])&&is_string($_POST['permalinkStructure'])?trim($_POST['permalinkStructure']):'postname';
                if(!in_array($structure,['plain','dayname','monthname','numeric','postname'],true))$saveError= __('รูปแบบลิงก์ถาวรไม่ถูกต้อง');
                else $permalinkValues['permalinkStructure']=$structure;
                $baseDefaults=['categoryBase'=>'category','tagBase'=>'tag','actorBase'=>'actor','studioBase'=>'studio'];
                $reservedBases=['all','categories','page','post','search','sitemap','sitemap-index','sitemap_index','rss','robots','archives','admin','admin-login','api','uploads','cloud','taxonomy'];
                $legacyBaseOwners=['categoryBase'=>'category','tagBase'=>'tag','actorBase'=>'actor','studioBase'=>'studio'];
                foreach($baseDefaults as $baseKey=>$baseDefault) {
                    $rawBase=$_POST[$baseKey]??$baseDefault;
                    $baseValue=is_string($rawBase)?strtolower(trim($rawBase," /\\\t\n\r\0\x0B")):$baseDefault;
                    if($baseValue==='')$baseValue=$baseDefault;
                    $isOtherLegacyBase=in_array($baseValue,array_values($legacyBaseOwners),true)&&$baseValue!==$legacyBaseOwners[$baseKey];
                    if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$baseValue) || in_array($baseValue,$reservedBases,true) || $isOtherLegacyBase) {
                        $saveError= __('ฐาน URL ต้องใช้ a-z, 0-9 หรือขีดกลาง และห้ามใช้คำสงวนของระบบ');break;
                    }
                    $permalinkValues[$baseKey]=$baseValue;
                }
                if($saveError==='') {
                    $uniqueBases=array_unique(array_values($permalinkValues));
                    if(count($uniqueBases)!==count($permalinkValues))$saveError= __('ฐาน URL ของหมวดหมู่ แท็ก นักแสดง และค่าย ต้องไม่ซ้ำกัน');
                }
            }

            foreach($allowed[$section] as $key) {

                if($saveError!=='')break;
                $raw=$_POST[$key]??'';
                if($section==='permalinks'&&isset($permalinkValues[$key]))$raw=$permalinkValues[$key];

                if(is_array($raw))$raw='';

                $value=is_string($raw)?trim($raw):'';

                if($key==='adminEmail'&&$value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL)){$saveError= __('รูปแบบอีเมลไม่ถูกต้อง');break;}

                if($key==='vdohideIframeDomain'&&$value!==''&&(!filter_var($value,FILTER_VALIDATE_URL)||!preg_match('#^https?://[^/\\s]+/?$#i',$value))){ $saveError= __('โดเมน iframe ต้องใส่เป็น URL เช่น https://player.example.com');break; }

                if($key==='siteUrl'&&$value!=='') {
                    $urlParts=parse_url($value);
                    $urlHost=is_array($urlParts)?(string)($urlParts['host']??''):'';
                    $hostValid=$urlHost!==''&&(filter_var($urlHost,FILTER_VALIDATE_IP)!==false||(bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\.?$/i',$urlHost));
                    if(!is_array($urlParts)||!isset($urlParts['scheme'])||!in_array(strtolower((string)$urlParts['scheme']),['http','https'],true)||!$hostValid||isset($urlParts['user'])||isset($urlParts['pass'])||isset($urlParts['query'])||isset($urlParts['fragment'])||preg_match('/[\x00-\x20<>\\\\]/',$value)||preg_match('~(?:^|/)\.\.(?:/|$)~',(string)($urlParts['path']??''))||(isset($urlParts['port'])&&((int)$urlParts['port']<1||(int)$urlParts['port']>65535))) {
                        $saveError= __('URL หลักต้องเป็น http(s)://โดเมน หรือ URL พร้อมโฟลเดอร์ติดตั้ง โดยห้ามมี query, fragment หรือข้อมูลผู้ใช้');break;
                    }
                    $value=rtrim($value,'/');
                }


                if($key==='vdohideApiToken'&&$value===''){ continue; }

                if($key==='siteName'&&mb_strlen($value,'UTF-8')>120){$saveError= __('ชื่อเว็บไซต์ต้องไม่เกิน 120 ตัวอักษร');break;}

                if($key==='siteDesc'&&mb_strlen($value,'UTF-8')>500){$saveError= __('คำโปรยเว็บไซต์ต้องไม่เกิน 500 ตัวอักษร');break;}

                if($key==='logoText'&&mb_strlen($value,'UTF-8')>120){$saveError= __('ข้อความโลโก้ต้องไม่เกิน 120 ตัวอักษร');break;}

                if($section==='general'&&isset($generalOptionValues[$key])&&!in_array($value,$generalOptionValues[$key],true)){$saveError='ค่าตัวเลือก '.$key.' ไม่ถูกต้อง';break;}

                if(in_array($key,['postsPerPage','feedItems','mediaMaxWidth','mediaMaxHeight','mediaQuality','mailPort','redisPort','redisDatabase'],true)&&$value!==''&&!ctype_digit($value)){$saveError= __('กรุณากรอกตัวเลขในช่องที่กำหนด');break;}

                if($saveError==='') {

                    if(!saveSetting($key,$value)){$saveError= __('บันทึกการตั้งค่าไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');break;}
                    if($key==='siteUrl'&&!saveSetting('site_url',$value)){$saveError= __('บันทึก URL หลักสำรองไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');break;}

                }

            }

            if($section==='general'&&$saveError==='') {

                foreach(['siteLogo'=>'siteLogoUpload','siteIcon'=>'siteIconUpload'] as $settingKey=>$fileField) {

                    if(isset($_POST[$settingKey])&&!is_string($_POST[$settingKey])){$saveError= __('ข้อมูล URL รูปโลโก้หรือไอคอนไม่ถูกต้อง');break;}

                    $value=isset($_POST[$settingKey])?trim($_POST[$settingKey]):'';

                    $upload=storeImageUpload($fileField);

                    if($upload['error']!==''){$saveError=$upload['error'];break;}

                    if($upload['path']!=='')$value=$upload['path'];

                    if(!validImageReference($value)){$saveError= __('กรุณาระบุ URL รูปภาพแบบ http(s) หรือพาธรูปภายในเว็บไซต์');break;}

                    if(!saveSetting($settingKey,$value)){$saveError= __('บันทึกรูปโลโก้หรือไอคอนไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูล');break;}

                }

            }

            if($saveError===''){logActivity('แก้ไขตั้งค่า: '.$section);header('Location: index.php?page='.($section==='general'?'settings':'settings-'.$section));exit;}

            $msg=$saveError;$msgType='error';

        }

    }

    if ($action==='save_banner_ads') {

        $videoAdSlots=[];$videoAdError='';

        foreach(['top'=>4,'inside'=>1,'bottom'=>3] as $position=>$slotCount) {

            $destinationKey='videoAd'.ucfirst($position).'Destination';

            $imageKey='videoAd'.ucfirst($position).'Image';

            $destinations=isset($_POST[$destinationKey])&&is_array($_POST[$destinationKey])?$_POST[$destinationKey]:[];

            $images=isset($_POST[$imageKey])&&is_array($_POST[$imageKey])?$_POST[$imageKey]:[];

            for($slot=0;$slot<$slotCount;$slot++) {

                $destinationValue=$destinations[$slot]??'';

                $imageValue=$images[$slot]??'';

                if(!is_string($destinationValue)||!is_string($imageValue)){$videoAdError= __('ข้อมูลโฆษณาไม่ถูกต้อง กรุณาตรวจ URL ปลายทางและ URL รูปภาพอีกครั้ง');break 2;}

                $destination=trim($destinationValue);$image=trim($imageValue);

                if(substr($image,0,2)==='//')$image='https:'.$image;

                if($destination===''&&$image==='')continue;

                if(!validAdDestinationUrl($destination)||$image===''||!validImageReference($image)){$videoAdError= __('กรุณากรอก URL ปลายทางแบบ http(s) และ URL รูปภาพที่ถูกต้องให้ครบในแต่ละป้ายโฆษณา');break 2;}

                $videoAdSlots[$position][]=['destination'=>$destination,'image'=>$image];

            }

        }

        if($videoAdError==='') {

            $encodedSlots=json_encode($videoAdSlots,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

            if($encodedSlots===false)$videoAdError= __('แปลงข้อมูลป้ายโฆษณาไม่สำเร็จ กรุณาตรวจสอบข้อความใน URL');

            elseif(!saveSetting('video_ad_slots',$encodedSlots))$videoAdError= __('บันทึกป้ายโฆษณารอบวิดีโอไม่สำเร็จ');

            else {logActivity('แก้ไขป้ายโฆษณารอบวิดีโอ');header('Location: index.php?page=banners#video-ad-settings');exit;}

        }

        $msg=$videoAdError;$msgType='error';

    }

    if ($action==='full_backup') {
        requirePermission('backup.download');
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            exit(__('เซิร์ฟเวอร์ยังไม่มีส่วนขยาย ZipArchive สำหรับสร้างไฟล์สำรอง ZIP'));
        }
        $dbDump = "-- AVSF full database backup\n-- Generated " . gmdate('Y-m-d H:i:s') . " UTC\nSET FOREIGN_KEY_CHECKS=0;\n";
        $tablesResult = mysqli_query($conn, 'SHOW TABLES');
        if ($tablesResult) {
            while ($tableRow = mysqli_fetch_row($tablesResult)) {
                $table = str_replace('`', '', $tableRow[0]);
                $tableSafe = '`' . str_replace('`', '``', $table) . '`';
                $create = mysqli_query($conn, 'SHOW CREATE TABLE ' . $tableSafe);
                if (!$create || !($createRow = mysqli_fetch_row($create))) continue;
                $dbDump .= "\nDROP TABLE IF EXISTS $tableSafe;\n" . $createRow[1] . ";\n";
                $rows = mysqli_query($conn, 'SELECT * FROM ' . $tableSafe);
                if (!$rows) continue;
                while ($row = mysqli_fetch_assoc($rows)) {
                    $columns = array_map(function($column) { return '`' . str_replace('`', '``', $column) . '`'; }, array_keys($row));
                    $values = array_map(function($value) use ($conn) {
                        return $value === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
                    }, array_values($row));
                    $dbDump .= 'INSERT INTO ' . $tableSafe . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
                }
            }
        }
        $dbDump .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $zipPath = tempnam(sys_get_temp_dir(), 'avsf-full-');
        $zip = new ZipArchive();
        if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit(__('ไม่สามารถเริ่มสร้างไฟล์สำรอง ZIP ได้'));
        }
        $zip->addFromString('database/avsf-database.sql', $dbDump);
        $root = realpath(AVSF_ROOT);
        $skip = function($path) use ($root) {
            $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));
            return preg_match('#(^|/)(\.git|node_modules|cache)(/|$)#i', $relative);
        };
        if ($root && is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                if ($skip($item->getPathname())) continue;
                $absolute = $item->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
                if ($item->isDir()) {
                    $zip->addEmptyDir('website/' . $relative);
                } elseif ($item->isFile() && !is_link($absolute)) {
                    if ($item->getSize() <= 64 * 1024 * 1024) $zip->addFile($absolute, 'website/' . $relative);
                }
            }
        }
        $zip->close();
        $filename = 'avsf-full-backup-' . date('Y-m-d-His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store, private');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    if ($action==='export') {

        requirePermission('backup.download');

        $data = ['settings'=>$SETTINGS];

        foreach (['studios','actors','tags','categories','slug_aliases','posts','banners','pages','comments','users','activity'] as $t) {

            $res=mysqli_query($conn,"SELECT * FROM $t"); $data[$t]=[];

            while($r=mysqli_fetch_assoc($res)) $data[$t][]=$r;

        }

        header('Content-Type: application/json');

        header('Content-Disposition: attachment; filename="avsf-backup.json"');

        echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); exit;

    }

    if ($action==='reset') {

        requirePermission('backup.download');

        foreach (['posts','studios','actors','tags','categories','slug_aliases','banners','pages','comments','activity'] as $t) mysqli_query($conn,"TRUNCATE TABLE $t");

        logActivity('รีเซ็ตข้อมูล'); header('Location: index.php?page=settings'); exit;

    }

}

if (isset($_GET['export'])) { $_POST['action']='export'; /* handled above on next load */ }



// ---- DATA LOADING ----

$studios=[];$res=mysqli_query($conn,"SELECT * FROM studios");while($r=mysqli_fetch_assoc($res))$studios[]=$r;

$actors=[];$res=mysqli_query($conn,"SELECT * FROM actors");while($r=mysqli_fetch_assoc($res))$actors[]=$r;

$tags=[];$res=mysqli_query($conn,"SELECT * FROM tags");while($r=mysqli_fetch_assoc($res))$tags[]=$r;

$posts=[];$res=mysqli_query($conn,"SELECT * FROM posts ORDER BY date DESC");while($r=mysqli_fetch_assoc($res))$posts[]=$r;

$pubCount=count(array_filter($posts,function($p){return $p['status']==='publish';}));

$draftCount=count($posts)-$pubCount;

$totalViews=array_sum(array_column($posts,'views'));

$pendingCmts=mysqli_num_rows(mysqli_query($conn,"SELECT * FROM comments WHERE approved=0"));



// ---- MENU ----

$menus = [

    'dashboard'=> __('📊 ภาพรวม'),'media'=> __('🖼️ สื่อ/แกลเลอรี่'),

    'pages'=> __('📄 หน้าเว็บ'),'banners'=> __('📢 แบนเนอร์/ประกาศ'),'comments'=>'💬 คอมเมนต์'.($pendingCmts?' <span class="avsf-badge avsf-badge-red">'.$pendingCmts.'</span>':''),

    'reports'=> __('📈 รายงาน/สถิติ'),'theme'=> __('🎨 สไตล์ & เมนู'),'site-content'=> __('🧩 เนื้อหาเว็บ & เมนู'),'seo'=>'🔍 ตั้งค่า SEO'

];

if (!can('users.manage')) unset($menus['users']);



function videoAdSettingsHtml() {

    $slots=json_decode(setting('video_ad_slots','{}'),true);

    if(!is_array($slots))$slots=[];

    $html= __('<div class="avsf-card avsf-mb" id="video-ad-settings"><div class="avsf-section-title">โฆษณารอบวิดีโอ</div><p style="font-size:12px;color:var(--muted);line-height:1.7;margin-bottom:14px;">จัดการแบนเนอร์ด้านบน ภายใน และด้านล่างเครื่องเล่น ระบบจะคงค่าปัจจุบันไว้จนกว่าจะบันทึกแบบฟอร์มนี้</p><form method="post"><input type="hidden" name="action" value="save_banner_ads">');

    foreach(['top'=>['label'=> __('ด้านบนวิดีโอ'),'count'=>4],'inside'=>['label'=> __('ภายในเครื่องเล่น'),'count'=>1],'bottom'=>['label'=> __('ด้านล่างวิดีโอ'),'count'=>3]] as $position=>$group) {

        $html.='<h3 style="font-size:14px;font-weight:700;margin:14px 0 8px;">'.$group['label'].'</h3>';

        $entries=isset($slots[$position])&&is_array($slots[$position])?$slots[$position]:[];

        for($slot=0;$slot<$group['count'];$slot++) {

            $entry=isset($entries[$slot])&&is_array($entries[$slot])?$entries[$slot]:[];

            $destination=isset($entry['destination'])?$entry['destination']:'';

            $image=isset($entry['image'])?$entry['image']:'';

            $destinationKey='videoAd'.ucfirst($position).'Destination';

            $imageKey='videoAd'.ucfirst($position).'Image';

            $html.='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:10px;padding:12px 0;border-top:1px solid var(--border);"><div class="avsf-form-group" style="margin:0;"><label>ป้าย '.($slot+1).' — URL ปลายทาง</label><input type="url" name="'.$destinationKey.'[]" value="'.esc($destination).'" placeholder="https://example.com/" inputmode="url"></div><div class="avsf-form-group" style="margin:0;"><label>URL รูปภาพ</label><input type="text" name="'.$imageKey.'[]" value="'.esc($image).'" placeholder="https://example.com/banner.gif" inputmode="url"></div></div>';

        }

    }

    return $html.'<button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit">บันทึกโฆษณารอบวิดีโอ</button></form></div>';

}



function dashboardWidgetHtml($id,$title,$content,$class='') {

    $contentId='dashboard-widget-'.$id.'-content';

    return '<section class="avsf-card avsf-dashboard-widget'.($class!==''?' '.e(avsfClass($class)):'').'"><div class="avsf-dashboard-widget-heading"><h2>'.esc($title).'</h2><button class="avsf-dashboard-collapse-toggle" type="button" data-dashboard-toggle aria-expanded="true" aria-controls="'.esc($contentId).'" aria-label="พับเก็บ '.esc($title).'"><span aria-hidden="true">⌃</span></button></div><div class="avsf-dashboard-widget-content" id="'.esc($contentId).'">'.$content.'</div></section>';

}



function adminContent($page) {

    global $conn,$posts,$studios,$actors,$tags,$pubCount,$draftCount,$totalViews,$pendingCmts,$SETTINGS;

    if ($page==='dashboard') {

        $recentAct=[];$res=mysqli_query($conn,"SELECT * FROM activity ORDER BY time DESC LIMIT 8");while($r=mysqli_fetch_assoc($res))$recentAct[]=$r;

        $chartPosts=array_values(array_filter($posts,function($post){return (int)$post['views']>0;}));

        usort($chartPosts,function($a,$b){return (int)$b['views']<=>(int)$a['views'];});

        $chartPosts=array_slice($chartPosts,0,7);

        $maxViews=$chartPosts?(int)$chartPosts[0]['views']:1;

        $chartHtml=$chartPosts?implode('',array_map(function($post)use($maxViews){

            $views=(int)$post['views'];

            $label=trim((string)($post['code']??''))?:$post['title'];

            return '<div class="avsf-bar-item" title="'.esc($post['title']).': '.number_format($views).'"><div class="avsf-bar" style="height:'.max(4,$views/$maxViews*100).'%;"><span>'.number_format($views).'</span></div><span class="avsf-bar-label">'.esc($label).'</span></div>';

        },$chartPosts)): __('<div style="width:100%;align-self:center;text-align:center;color:var(--muted);font-size:12px;">ยังไม่มีข้อมูลยอดวิวจากโพสต์</div>');

        $quickDraftHtml='';

        if(can('posts.create')) {

            $draftAuthor=trim(isset($_SESSION['avsf_admin_username'])?$_SESSION['avsf_admin_username']:'');

            $recentDrafts=array_values(array_filter($posts,function($post)use($draftAuthor){

                return $post['status']==='draft' && trim(isset($post['author'])?$post['author']:'')===$draftAuthor;

            }));

            usort($recentDrafts,function($a,$b){
                $aTime=trim((string)($a['createdAt']??''));if($aTime==='')$aTime=trim((string)($a['date']??''));
                $bTime=trim((string)($b['createdAt']??''));if($bTime==='')$bTime=trim((string)($b['date']??''));
                return strcmp($bTime,$aTime);
            });

            $recentDrafts=array_slice($recentDrafts,0,5);

            $recentDraftHtml=$recentDrafts?implode('',array_map(function($draft){

                return '<a class="avsf-dashboard-draft-link" href="index.php?page=post-edit&id='.(int)$draft['id'].'" title="'.esc($draft['title']).'">'.esc($draft['title']).'</a>';

            },$recentDrafts)): __('<p class="avsf-dashboard-empty-drafts">ยังไม่มีฉบับร่าง</p>');

            $quickDraftHtml=dashboardWidgetHtml('quick-draft','เขียนเรื่องใหม่ฉบับร่าง',

                '<div class="avsf-dashboard-draft-mode-switch" role="group" aria-label="เลือกรูปแบบการเขียน"><button type="button" class="avsf-is-active" data-draft-mode="quick" aria-pressed="true">รวดเร็ว</button><button type="button" data-draft-mode="advanced" aria-pressed="false">แอดวานซ์ · วางข้อมูลเต็ม</button></div>'.

                '<div class="avsf-dashboard-draft-mode-panel" data-draft-panel="quick"><form method="post" class="avsf-dashboard-quick-draft"><input type="hidden" name="action" value="save_quick_draft"><div class="avsf-form-group"><label for="quickDraftTitle">ชื่อเรื่อง</label><input id="quickDraftTitle" name="draft_title" maxlength="200" placeholder="ใส่ชื่อเรื่องที่ต้องการบันทึกฉบับร่าง" required></div><div class="avsf-form-group"><label for="quickDraftContent">เนื้อหา</label><textarea id="quickDraftContent" name="draft_content" rows="4" placeholder="คุณกำลังคิดถึงอะไรอยู่?"></textarea></div><button class="avsf-btn avsf-btn-primary" type="submit">บันทึกฉบับร่าง</button></form></div>'.

                '<div class="avsf-dashboard-draft-mode-panel" data-draft-panel="advanced" hidden><form method="post" enctype="multipart/form-data" class="avsf-dashboard-quick-draft avsf-dashboard-advanced-draft"><input type="hidden" name="action" value="save_advanced_draft"><div class="avsf-form-group"><label for="advancedDraftFile">เปิดไฟล์ข้อมูล (ไม่บังคับ)</label><input id="advancedDraftFile" type="file" accept=".txt,.csv,.json,text/plain,text/csv,application/json"><small class="avsf-field-help">TXT, CSV หรือ JSON · ใช้ Code, Title และหัวข้ออื่นเป็นชื่อฟิลด์</small></div><div class="avsf-form-group"><label for="advancedDraftImages">เลือกรูปปกและรูป Gallery ของทุกเรื่อง</label><input id="advancedDraftImages" name="advancedDraftImages[]" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple><small class="avsf-field-help">จับคู่ตาม Code: CODE-123.jpg หรือ CODE-123-cover.jpg = รูปปก · CODE-123-gallery-01.jpg, CODE-123-gallery-02.jpg = Gallery (เรียงตามเลข) · ไฟล์ที่ไม่มีคำว่า gallery จะไม่ถูกนำไปเป็น Gallery อัตโนมัติ</small></div><div class="avsf-form-group"><label for="advancedDraftFolder">หรือเลือกโฟลเดอร์รูปภาพ (ถ้าอุปกรณ์รองรับ)</label><input id="advancedDraftFolder" name="advancedDraftFolder[]" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple webkitdirectory directory><small class="avsf-field-help">เลือกได้ทั้งหลายไฟล์หรือทั้งโฟลเดอร์ · แต่ละรูปจะถูกจับคู่กับเรื่องที่มี Code ตรงกันเท่านั้น</small></div><div class="avsf-form-group"><label for="advancedDraftData">วางข้อมูลเต็ม</label><textarea id="advancedDraftData" name="advanced_draft_data" rows="8" required placeholder="Code, START-656&#10;Focus Keyphrase, ...&#10;Actor, ...&#10;Tags, ...&#10;Studio, ...&#10;Categories, ...&#10;Title, ...&#10;Content, ...&#10;Meta Description, ...&#10;Actor EN, ...&#10;Duration, ...&#10;Video URL, ..."></textarea><small class="avsf-field-help">Code ใช้สร้าง Slug และจับคู่รูป · Actor EN ใช้สร้าง ALT เป็น Code + Actor EN; ถ้าไม่มีใช้ Code · Gallery ต้องมีคำว่า gallery ในชื่อไฟล์</small><span class="avsf-dashboard-advanced-status" aria-live="polite"></span></div><button class="avsf-btn avsf-btn-primary" type="submit">แยกข้อมูลและบันทึกฉบับร่าง</button></form></div>'.

                '<div class="avsf-dashboard-recent-drafts"><div class="avsf-dashboard-recent-drafts-heading"><strong>ฉบับร่างล่าสุดของคุณ</strong><a href="index.php?page=posts">ดูโพสต์ทั้งหมด</a></div>'.$recentDraftHtml.'</div>',

                'dashboard-widget-quick-draft');

        }

        $statisticsHtml=dashboardWidgetHtml('statistics','สถิติระบบ','<div class="avsf-grid avsf-dashboard-stat-grid">'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-posts" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h11l3 3v15H5z"/><path d="M16 3v4h4M8 11h8M8 15h6"/></svg></div><div><div class="avsf-stat-num">'.count($posts).'</div><div class="avsf-stat-label">โพสต์ทั้งหมด</div></div></div>'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-published" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></div><div><div class="avsf-stat-num" style="color:var(--success);">'.$pubCount.'</div><div class="avsf-stat-label">เผยแพร่</div></div></div>'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-drafts" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20h16M6 17l8-8 3 3-8 8H6zM13 10l3-3 3 3"/></svg></div><div><div class="avsf-stat-num" style="color:var(--gold);">'.$draftCount.'</div><div class="avsf-stat-label">แบบร่าง</div></div></div>'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-studios" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V8l8-5 8 5v13M8 21v-5h8v5M8 10h2M14 10h2"/></svg></div><div><div class="avsf-stat-num" style="color:var(--accent2);">'.count($studios).'</div><div class="avsf-stat-label">ค่าย</div></div></div>'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-actors" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 11a3 3 0 1 0 0-6M16 14c3 0 5 2 5 5"/></svg></div><div><div class="avsf-stat-num" style="color:var(--accent3);">'.count($actors).'</div><div class="avsf-stat-label">นักแสดง</div></div></div>'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-views" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></div><div><div class="avsf-stat-num" style="color:var(--gold);">'.number_format($totalViews).'</div><div class="avsf-stat-label">ยอดวิวรวม</div></div></div>'.

        '<div class="avsf-stat-card"><div class="avsf-stat-icon avsf-stat-icon-comments" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H8l-4 4zM8 9h8M8 12h5"/></svg></div><div><div class="avsf-stat-num" style="color:var(--danger);">'.$pendingCmts.'</div><div class="avsf-stat-label">คอมเมนต์รออนุมัติ</div></div></div></div>','dashboard-widget-statistics');

        $h='<h1 style="font-size:24px;font-weight:900;margin-bottom:20px;">📊 ภาพรวมระบบ</h1>'.

        '<div class="avsf-dashboard-overview-grid">'.

        $statisticsHtml.

        dashboardWidgetHtml('views','ยอดวิวสะสมตามโพสต์ยอดนิยม','<div class="avsf-bar-chart">'.$chartHtml.'</div>','dashboard-widget-views dashboard-widget-full').

        '</div>'.

        '<div class="avsf-grid avsf-dashboard-chart-grid avsf-dashboard-chart-grid-top">'.

        $quickDraftHtml.

        dashboardWidgetHtml('activity','กิจกรรมล่าสุด','<div class="avsf-dashboard-activity-list">'.implode('',array_map(function($a){return '<div class="avsf-dashboard-activity-item"><span aria-hidden="true">●</span><div>'.esc($a['action']).'<small>'.fmtTime($a['time']).'</small></div></div>';},$recentAct)).'</div>','dashboard-widget-activity').

        dashboardWidgetHtml('recent-posts','โพสต์ล่าสุด','<div class="avsf-dashboard-recent-posts-heading"><a class="avsf-btn avsf-btn-primary avsf-btn-sm" href="index.php?page=post-new">+ เขียนใหม่</a></div>'.

        '<div style="overflow-x:auto;"><table><thead><tr><th>ชื่อเรื่อง</th><th>ค่าย</th><th>ความยาว</th><th>สถานะ</th><th>วิว</th><th>วันที่</th><th></th></tr></thead><tbody>'.

        implode('',array_map(function($p){return '<tr><td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">'.esc($p['title']).($p['code']?'<div style="font-size:10px;color:var(--muted);font-weight:400;">'.esc($p['code']).'</div>':'').'</td><td>'.esc(getStudio($p['studioId'])['name']).'</td><td>'.esc(formatVideoDuration($p['videoDuration'])?:'-').'</td><td><span class="avsf-post-status '.($p['status']==='publish'?'avsf-is-published':'avsf-is-draft').'">'.($p['status']==='publish'? __('เผยแพร่'): __('ฉบับร่าง')).'</span></td><td>'.$p['views'].'</td><td>'.fmtDate($p['date']).'</td><td><a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="index.php?page=post-edit&id='.$p['id'].'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg> แก้ไข</a></td></tr>';},array_slice($posts,0,5))).

        '</tbody></table></div>','dashboard-widget-recent-posts').

        '</div>'.

        dashboardWidgetHtml('live-visitors','ผู้เข้าชมล่าสุด · เรียลไทม์','<p class="avsf-dashboard-live-status" data-live-status aria-live="polite">กำลังโหลดรายการล่าสุด...</p><ol class="avsf-dashboard-live-list" data-live-list aria-live="polite"></ol>','dashboard-widget-live-visitors');

        $h.= __('<script>(function(){var list=document.querySelector("[data-live-list]"),status=document.querySelector("[data-live-status]"),busy=false;if(!list||!status)return;function load(){if(busy)return;busy=true;fetch("index.php?page=dashboard&live_page_views=1",{credentials:"same-origin",headers:{"Accept":"application/json"},cache:"no-store"}).then(function(response){if(!response.ok)throw new Error("โหลดข้อมูลไม่สำเร็จ");return response.json();}).then(function(data){if(!Array.isArray(data.views))throw new Error("รูปแบบข้อมูลไม่ถูกต้อง");list.replaceChildren();data.views.forEach(function(entry){if(!entry||typeof entry.path!=="string"||entry.path.charAt(0)!=="/")return;var target;try{target=new URL(entry.path,location.origin);}catch(error){return;}if(target.origin!==location.origin||target.search||target.hash)return;var item=document.createElement("li"),link=document.createElement("a"),details=document.createElement("div"),path=document.createElement("small"),time=document.createElement("time");link.href=target.href;link.textContent=typeof entry.title==="string"&&entry.title.trim()?entry.title:entry.path;link.title=entry.path;path.textContent=entry.path;details.appendChild(link);details.appendChild(path);item.appendChild(details);if(typeof entry.viewedAt==="string"){var date=new Date(entry.viewedAt.replace(" ","T"));if(!Number.isNaN(date.getTime())){time.dateTime=date.toISOString();time.textContent=new Intl.DateTimeFormat("th-TH",{hour:"2-digit",minute:"2-digit",second:"2-digit"}).format(date);item.appendChild(time);}}list.appendChild(item);});status.textContent=data.views.length?"อัปเดตอัตโนมัติทุก 10 วินาที · ล่าสุด "+data.views.length+" รายการ":"ยังไม่มีผู้เข้าชมหน้าเว็บ";}).catch(function(){status.textContent="ไม่สามารถโหลดข้อมูลผู้เข้าชมได้";}).finally(function(){busy=false;});}load();window.setInterval(load,10000);})();</script>');

        return $h;

    }

    if ($page==='posts') {

        $categorySlugs=[];

        $categoryResult=mysqli_query($conn,"SELECT name,slug FROM categories ORDER BY name");

        if(!$categoryResult)throw new RuntimeException('โหลดหมวดหมู่สำหรับรายการโพสต์ไม่สำเร็จ: '.mysqli_error($conn));

        while($categoryRow=mysqli_fetch_assoc($categoryResult))$categorySlugs[mb_strtolower($categoryRow['name'],'UTF-8')]=$categoryRow['slug'];

        $statusCounts=[];

        foreach($posts as $post)$statusCounts[$post['status']]=isset($statusCounts[$post['status']])?$statusCounts[$post['status']]+1:1;

        $statusLabels=['publish'=> __('เผยแพร่'),'draft'=> __('ฉบับร่าง'),'pending'=> __('รอตรวจ'),'private'=> __('ส่วนตัว')];

        $statusCards='<div class="avsf-posts-stat-card"><strong>'.count($posts).'</strong><span>โพสต์ทั้งหมด</span></div>';

        foreach($statusCounts as $status=>$count) {

            $label=isset($statusLabels[$status])?$statusLabels[$status]:$status;

            $class=$status==='publish'?'avsf-is-published':($status==='draft'?'avsf-is-draft':'avsf-is-pending');

            $statusCards.='<div class="avsf-posts-stat-card"><strong class="'.$class.'">'.$count.'</strong><span>'.esc($label).'</span></div>';

        }

        $actorsById=[];foreach($actors as $actor)$actorsById[(int)$actor['id']]=$actor;

        $tagsById=[];foreach($tags as $tag)$tagsById[(int)$tag['id']]=$tag;

        $tableRows='';$postCards='';

        foreach($posts as $post) {

            $studio=getStudio($post['studioId']);

            $duration=formatVideoDuration($post['videoDuration']);

            $status=$post['status']==='publish'? __('เผยแพร่'):($post['status']==='draft'? __('ฉบับร่าง'):(isset($statusLabels[$post['status']])?$statusLabels[$post['status']]:$post['status']));

            $statusClass=$post['status']==='publish'?'avsf-is-published':($post['status']==='draft'?'avsf-is-draft':'avsf-is-pending');

            $actorRows=[];foreach(parseTaxonomyIds($post['actorIds']) as $actorId)if(isset($actorsById[$actorId]))$actorRows[]=$actorsById[$actorId];

            $tagRows=[];foreach(parseTaxonomyIds($post['tagIds']) as $tagId)if(isset($tagsById[$tagId]))$tagRows[]=$tagsById[$tagId];

            $actorsForPost=array_column($actorRows,'name');

            $tagNamesForPost=array_column($tagRows,'name');

            $categoriesForPost=preg_split('/[,،;|，；\r\n]+/u',(string)(isset($post['category'])?$post['category']:''))?:[];

            $categoriesForPost=array_values(array_unique(array_filter(array_map('trim',$categoriesForPost),function($category){return $category!=='';})));

            $postAuthor=trim(isset($post['author'])?$post['author']:'');

            $postDateTime=isset($post['createdAt'])?$post['createdAt']:'';

            $postBylineDate=$postDateTime!==''?fmtThaiDateTime($postDateTime):(isset($post['date'])&&$post['date']!==''?fmtThaiDate($post['date']).' · เวลาไม่ระบุ': __('วันเวลาไม่ระบุ'));

            $cover=!empty($post['cover'])?$post['cover']:'';

            $postId=(int)$post['id'];

            $selectPost='<input class="avsf-post-select-checkbox" type="checkbox" value="'.$postId.'" aria-label="เลือกโพสต์ '.esc($post['title']).'">';

            $actions='<a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="index.php?page=post-edit&amp;id='.$postId.'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg> แก้ไข</a><form method="post" class="avsf-post-delete-form"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="'.$postId.'"><button class="avsf-btn avsf-btn-danger avsf-btn-sm" onclick="return confirm(\'ลบโพสต์นี้?\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg> ลบ</button></form>';

            $categoryLinks=[];foreach($categoriesForPost as $categoryName){$categoryKey=mb_strtolower($categoryName,'UTF-8');$categorySlug=isset($categorySlugs[$categoryKey])?$categorySlugs[$categoryKey]:'';$categoryLinks[]=$categorySlug!==''?'<a class="avsf-post-taxonomy-link" href="../index.php?page=category&amp;slug='.rawurlencode($categorySlug).'" target="_blank" rel="noopener">'.esc($categoryName).'</a>':'<span>'.esc($categoryName).'</span>';}

            $actorLinks=[];foreach($actorRows as $actor)$actorLinks[]='<a class="avsf-post-taxonomy-link" href="../index.php?page=actor&amp;slug='.rawurlencode($actor['slug']).'" target="_blank" rel="noopener">'.esc($actor['name']).'</a>';

            $tagLinks=[];foreach($tagRows as $tag)$tagLinks[]='<a class="avsf-post-taxonomy-link" href="../index.php?page=tag&amp;slug='.rawurlencode($tag['slug']).'" target="_blank" rel="noopener">'.esc($tag['name']).'</a>';

            $studioLink=!empty($studio['id'])?'<a class="avsf-post-taxonomy-link" href="../index.php?page=studio&amp;slug='.rawurlencode($studio['slug']).'" target="_blank" rel="noopener">'.esc($studio['name']).'</a>': __('<span>ไม่ระบุ</span>');

            $excerpt=trim(stripHtml($post['content']??''));

            if(mb_strlen($excerpt,'UTF-8')>150)$excerpt=mb_substr($excerpt,0,150,'UTF-8').'…';

            $titleUrl=$post['status']==='publish'?'../index.php?page=post&amp;slug='.rawurlencode($post['slug']):'index.php?page=post-edit&amp;id='.$postId;

            $tableRows.='<tr><td class="avsf-post-table-title">'.$selectPost.($cover?'<img src="'.esc($cover).'" alt="" loading="lazy">': __('<span class="avsf-post-table-placeholder">ไม่มีภาพ</span>')).'<div class="avsf-post-table-description"><strong><a class="avsf-post-admin-title-link" href="'.$titleUrl.'"'.($post['status']==='publish'?' target="_blank" rel="noopener"':'').'>'.esc($post['title']).'</a></strong>'.(!empty($post['code'])?'<small>'.esc($post['code']).'</small>':'').($excerpt!==''?'<span class="avsf-post-table-excerpt">'.esc($excerpt).'</span>':'').'<div class="avsf-post-table-inline-actions">'.$actions.'</div></div></td><td class="avsf-post-taxonomy-cell">'.($categoryLinks?implode('、 ',$categoryLinks): __('<span class="avsf-post-taxonomy-empty">ไม่ระบุ</span>')).'</td><td class="avsf-post-taxonomy-cell">'.$studioLink.'</td><td class="avsf-post-taxonomy-cell">'.($actorLinks?implode('、 ',$actorLinks): __('<span class="avsf-post-taxonomy-empty">ไม่ระบุ</span>')).'</td><td class="avsf-post-taxonomy-cell">'.($tagLinks?implode('、 ',$tagLinks): __('<span class="avsf-post-taxonomy-empty">ไม่ระบุ</span>')).'</td><td><span class="avsf-post-status '.$statusClass.'">'.esc($status).'</span></td><td>'.number_format((int)$post['views']).'</td><td>'.fmtDate($post['date']).'</td><td>'.esc($duration?:'-').'</td></tr>';

            $taxonomyPills='';

            foreach(array_slice($categoriesForPost,0,3) as $categoryName)$taxonomyPills.='<span class="avsf-post-summary-pill avsf-is-category">'.esc($categoryName).'</span>';

            if(!empty($studio['id']))$taxonomyPills.='<span class="avsf-post-summary-pill avsf-is-studio">'.esc($studio['name']).'</span>';

            foreach(array_slice($actorsForPost,0,3) as $actorName)$taxonomyPills.='<span class="avsf-post-summary-pill avsf-is-actor">'.esc($actorName).'</span>';

            foreach(array_slice($tagNamesForPost,0,5) as $tagName)$taxonomyPills.='<span class="avsf-post-summary-pill avsf-is-tag">#'.esc($tagName).'</span>';

            if($taxonomyPills==='')$taxonomyPills= __('<span class="avsf-post-summary-pill avsf-is-empty">ไม่ระบุ</span>');

            $postCards.='<article class="avsf-post-summary-card"><div class="avsf-post-summary-cover">'.($cover?'<img src="'.esc($cover).'" alt="" loading="lazy">': __('<span>ไม่มีภาพปก</span>')).'<span class="avsf-post-status '.$statusClass.'">'.esc($status).'</span></div><div class="avsf-post-summary-body"><div class="avsf-post-summary-heading"><div>'.(!empty($post['code'])?'<span class="avsf-post-summary-code">'.esc($post['code']).'</span>':'').'<h2>'.esc($post['title']).'</h2></div><div class="avsf-post-summary-views">👁 '.number_format((int)$post['views']).'</div></div><div class="avsf-post-summary-byline"><span>👤 '.esc($postAuthor!==''?$postAuthor: __('ไม่ระบุ')).'</span><span>◷ '.esc($postBylineDate).'</span></div><div class="avsf-post-summary-meta">'.($duration?'<span>🎞 '.esc($duration).'</span>':'').'</div><div class="avsf-post-summary-taxonomy">'.$taxonomyPills.'</div><div class="avsf-post-summary-actions">'.$selectPost.$actions.'</div></div></article>';

        }

        $savedPrimary=trim(setting('post_primary_keyword_notes',''));

        $savedSecondary=trim(setting('post_secondary_keyword_notes',''));

        $defaultPrimary="jav|EN|1\nหนังav|TH|2\nหนังโป๊ญี่ปุ่น|TH|3\nav japan|EN|4\nxxxav|EN\nหนังเอวี|TH|6\nav xxx|EN\nหนัง av|TH\navjapan|EN|9\navxxx|EN\navญี่ปุ่น|TH\nหนังโป๊|TH\nxxx av|EN\nav ญี่ปุ่น|TH\nxxxญี่ปุ่น|TH\nหนัง x ญี่ปุ่น|TH\nดูหนังโป๊ญี่ปุ่น|TH\nxxxjapan|EN\nav18+|EN\nหนังโป๊av|TH\nav hd|EN\njapan av|EN|22\nporn av|EN\nคลิปโป๊ญี่ปุ่น|TH\njapanxxx|EN\nเอวี|TH\nav porn|EN\nหนังxญี่ปุ่น|TH";

        $defaultSecondary="หนังโป๊ญี่ปุ่นนมใหญ่\nav นมใหญ่\nเย็ดญี่ปุ่น\nหนังเอวีนมใหญ่\njav นมใหญ่\nนางเอกเอวีนมใหญ่\nหนังโป๊ญี่ปุ่นสวย\nav สวย\nหนังเอวีหน้าใส\njav สวย\nนางเอกavสวย\nหนังโป๊ญี่ปุ่นใหม่\nav ใหม่ล่าสุด\nหนังเอวีใหม่\njav ใหม่\nดูหนังเอวีฟรี\nดูjavฟรี\nหนังโป๊ญี่ปุ่นhd\nav hd\njav hd\nหนังเอวีชัด\nดูavออนไลน์\nดูjavออนไลน์\nหนังโป๊ญี่ปุ่นเต็มเรื่อง\nav เต็มเรื่อง\njav เต็มเรื่อง\nหนังเอวีแนวโรแมนติก\nav คู่รัก\njav ความรัก\nหนังโป๊ญี่ปุ่นน่ารัก\nav น่ารัก\njav น่ารัก\nนางเอกเอวีน่ารัก\nหนังโป๊ญี่ปุ่นยอดนิยม\nav ยอดนิยม\njav ยอดนิยม\nหนังเอวีเรื่องดัง\nดูหนังav\nหนังavญี่ปุ่น\nav ญี่ปุ่น\njav ญี่ปุ่น\nนักแสดงavญี่ปุ่น\nนักแสดงjav\nav สตรีมมิ่ง\nดูหนังเอวี\nหนังโป๊ญี่ปุ่นxxx\nav xxx\nหนังโป๊ญี่ปุ่น24ชม\nav ครบทุกเรื่อง\njav ครบทุกเรื่อง\nหนังเอวีสด\nav สด\nถ่ายทอดสดav\nหนังเอวีคุณภาพสูง\nav 4k\njav 1080p\nหนังเอวีฟูลเอชดี\nหนังโป๊ญี่ปุ่นชัดไม่เบลอ\nav คมชัด\njav คมชัด";

        if(!array_key_exists('post_primary_keyword_notes',$SETTINGS))$savedPrimary=$defaultPrimary;

        if(!array_key_exists('post_secondary_keyword_notes',$SETTINGS))$savedSecondary=$defaultSecondary;

        $primaryChipHtml='';$primaryRankMap=[1=>1,2=>2,3=>3,4=>4,6=>6,9=>9,22=>22];

        foreach(preg_split('/\r\n|\r|\n/',$savedPrimary) as $index=>$line) {

            $parts=array_map('trim',explode('|',$line));$keyword=isset($parts[0])?$parts[0]:'';

            if($keyword==='')continue;

            $language=isset($parts[1])&&in_array(strtoupper($parts[1]),['EN','TH'],true)?strtoupper($parts[1]):(preg_match('/[ก-๙]/u',$keyword)?'TH':'EN');

            $rank=isset($parts[2])&&ctype_digit($parts[2])?(int)$parts[2]:(isset($primaryRankMap[$index+1])?$primaryRankMap[$index+1]:0);

            $primaryChipHtml.='<button type="button" class="avsf-keyword-note-chip" data-copy-keyword="'.esc($keyword).'" title="คลิกเพื่อคัดลอก: '.esc($keyword).'"><span class="avsf-keyword-note-index">#'.($index+1).'</span><span>'.esc($keyword).'</span>'.($rank?'<span class="avsf-keyword-note-rank">rank '.$rank.'</span>':'').'<span class="avsf-keyword-note-language avsf-is-'.strtolower($language).'">'.$language.'</span></button>';

        }

        $secondaryChipHtml='';foreach(preg_split('/\r\n|\r|\n/',$savedSecondary) as $index=>$keyword){$keyword=trim($keyword);if($keyword!=='')$secondaryChipHtml.='<button type="button" class="avsf-keyword-note-chip avsf-is-secondary" data-copy-keyword="'.esc($keyword).'" title="คลิกเพื่อคัดลอก: '.esc($keyword).'"><span class="avsf-keyword-note-index">#'.($index+1).'</span><span>'.esc($keyword).'</span></button>';}

        $postHeaderActions='<div class="avsf-posts-page-actions">'.(can('posts.import')? __('<a class="avsf-btn avsf-btn-ghost" href="import.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5"/><path d="M5 17v3h14v-3"/></svg>นำเข้าข้อมูล</a>'):'').'<a class="avsf-btn avsf-btn-primary" href="index.php?page=post-new"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> เขียนโพสต์ใหม่</a></div>';

        return '<div class="avsf-posts-page" data-posts-page><div class="avsf-posts-page-heading"><div><h1>📝 จัดการโพสต์ทั้งหมด</h1><p>ค้นหาและเลือกมุมมองให้เหมาะกับงานและขนาดหน้าจอ</p></div>'.$postHeaderActions.'</div>'.

        '<div class="avsf-posts-stats">'.$statusCards.'</div>'.

        '<details class="avsf-post-keyword-notes" id="post-keyword-notes"><summary class="avsf-keyword-notes-summary"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M14 9l2.5 2.5"/></svg> ชุดคีย์เวิร์ดส่วนตัว</span><small>คลิกเพื่อเปิดเต็มจอ · แตะคีย์เพื่อคัดลอกเฉพาะคำ</small></summary><div class="avsf-keyword-notes-content"><form method="post" class="avsf-keyword-notes-form" data-keyword-form><input type="hidden" name="action" value="save_post_keyword_notes"><div class="avsf-keyword-notes-toolbar"><div><strong>ชุดคีย์ยอดนิยม · Top Focus Keys</strong><small>'.count(array_filter(preg_split('/\r\n|\r|\n/',$savedPrimary),'trim')).' คีย์</small></div><button class="avsf-btn avsf-btn-ghost avsf-btn-sm" type="button" data-keyword-edit><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg> แก้ไขชุดคีย์</button></div><div class="avsf-keyword-notes-read" data-keyword-read><section><h3>Primary head keys <span>คลิกคีย์เพื่อคัดลอก</span></h3><div class="avsf-keyword-note-preview">'.$primaryChipHtml.'</div></section><section><h3>Long-tail Keywords <span>'.count(array_filter(preg_split('/\r\n|\r|\n/',$savedSecondary),'trim')).' คีย์ · คลิกคีย์เพื่อคัดลอก</span></h3><div class="avsf-keyword-note-preview">'.$secondaryChipHtml.'</div></section></div><div class="avsf-keyword-notes-edit" data-keyword-editor hidden><div class="avsf-keyword-notes-grid"><section><label for="primaryKeywordNotes">Primary head keys <span>หนึ่งคีย์ต่อบรรทัด · คีย์|ภาษา|อันดับ (เช่น jav|EN|1)</span></label><textarea id="primaryKeywordNotes" name="primary_keyword_notes" rows="12">'.esc($savedPrimary).'</textarea></section><section><label for="secondaryKeywordNotes">Long-tail / Secondary keys <span>หนึ่งคีย์ต่อบรรทัด</span></label><textarea id="secondaryKeywordNotes" name="secondary_keyword_notes" rows="12">'.esc($savedSecondary).'</textarea></section></div><div class="avsf-keyword-notes-footer"><small>ชุดคีย์นี้เก็บเป็นโน้ตส่วนตัวของหลังบ้าน ไม่ถูกนำไปใส่ในโพสต์อัตโนมัติ</small><div><button class="avsf-btn avsf-btn-ghost avsf-btn-sm" type="button" data-keyword-cancel>ยกเลิก</button> <button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/></svg> บันทึกชุดคีย์</button></div></div></div></form></div></details>'.

        '<div class="avsf-posts-toolbar"><strong>รายการโพสต์ <span>'.count($posts).' เรื่อง</span></strong><div class="avsf-posts-view-switch" role="group" aria-label="เปลี่ยนมุมมองโพสต์"><button type="button" data-post-view="normal" aria-pressed="true">☷ ปกติ</button><button type="button" data-post-view="rows" aria-pressed="false">▤ แถวรายละเอียด</button><button type="button" data-post-view="columns" aria-pressed="false">▦ คอลัมน์</button></div></div>'.

        '<form method="post" class="avsf-posts-bulk-toolbar" data-bulk-post-form><input type="hidden" name="action" value="bulk_post_action"><div class="avsf-posts-bulk-selection"><label class="avsf-posts-select-all"><input type="checkbox" data-select-all> เลือกทั้งหมด</label><span data-selected-count>เลือกแล้ว 0 รายการ</span></div><div class="avsf-posts-bulk-controls"><label class="avsf-visually-hidden" for="bulkPostOperation">คำสั่งสำหรับโพสต์ที่เลือก</label><select id="bulkPostOperation" name="bulk_operation" required><option value="">-- เลือกคำสั่ง --</option><option value="publish">เผยแพร่</option><option value="draft">เปลี่ยนเป็นฉบับร่าง</option><option value="delete">ลบโพสต์</option></select><button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit" data-bulk-submit>ทำรายการที่เลือก</button></div></form>'.

        '<section class="avsf-posts-view avsf-posts-view-normal avsf-is-active" data-post-view-panel="normal"><div class="avsf-card avsf-posts-table-card"><div class="avsf-posts-table-scroll"><table class="avsf-posts-table"><thead><tr><th>เรื่อง</th><th>หมวดหมู่</th><th>ค่าย</th><th>นักแสดง</th><th>แท็ก</th><th>สถานะ</th><th>วิว</th><th>วันที่</th><th>ความยาว</th></tr></thead><tbody>'.($tableRows!==''?$tableRows: __('<tr><td colspan="9" class="avsf-posts-empty">ยังไม่มีโพสต์</td></tr>')).'</tbody></table></div></div></section>'.

        '<section class="avsf-posts-view avsf-posts-view-rows" data-post-view-panel="rows">'.$postCards.'</section><section class="avsf-posts-view avsf-posts-view-columns" data-post-view-panel="columns">'.$postCards.'</section></div>';

    }

    if ($page==='post-new' || $page==='post-edit') {

        $p = ['id'=>0,'code'=>'','title'=>'','slug'=>'','content'=>'','cover'=>'','posterUrl'=>'','videoUrl'=>'','embedUrl'=>'','videoDuration'=>'','gallery'=>'[]','studioId'=>$studios?$studios[0]['id']:0,'actorIds'=>'[]','actorEN'=>'','tagIds'=>'[]','category'=>'','focusKeyphrase'=>'','metaTitle'=>'','metaDesc'=>'','keywords'=>'','status'=>'draft','date'=>date('Y-m-d')];

        if ($page==='post-edit' && isset($_GET['id'])) {

            $id=intval($_GET['id']);

            $r=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM posts WHERE id=$id"));

            if ($r) $p=$r;

        }

        $actorIds=json_decode($p['actorIds'],true)?:[];

        $tagIds=json_decode($p['tagIds'],true)?:[];

        $gallery=normalizeGalleryImages($p['gallery']??'');
        $galleryAlt=json_decode(isset($p['galleryAlt'])?(string)$p['galleryAlt']:'[]',true)?:[];

        $galleryPreviewHtml='';

        foreach($gallery as $galleryImage)$galleryPreviewHtml.='<div class="avsf-editor-gallery-item"><img src="'.esc($galleryImage).'" alt="" loading="lazy"></div>';

        $playerInput=!empty($p['embedUrl'])?$p['embedUrl']:$p['videoUrl'];

        $v=parseVideo($playerInput);

        $studioUsage=[];$actorUsage=[];$tagUsage=[];

        foreach($studios as $studio)$studioUsage[(int)$studio['id']]=0;

        foreach($actors as $actor)$actorUsage[(int)$actor['id']]=0;

        foreach($tags as $tag)$tagUsage[(int)$tag['id']]=0;

        foreach($posts as $usagePost) {

            $studioUsage[(int)$usagePost['studioId']]=isset($studioUsage[(int)$usagePost['studioId']])?$studioUsage[(int)$usagePost['studioId']]+1:1;

            foreach(array_unique(json_decode($usagePost['actorIds'],true)?:[]) as $usageActorId)if(isset($actorUsage[(int)$usageActorId]))$actorUsage[(int)$usageActorId]++;

            foreach(array_unique(json_decode($usagePost['tagIds'],true)?:[]) as $usageTagId)if(isset($tagUsage[(int)$usageTagId]))$tagUsage[(int)$usageTagId]++;

        }

        $popularTerms=function($items,$usage) {

            usort($items,function($a,$b)use($usage){$countA=isset($usage[(int)$a['id']])?$usage[(int)$a['id']]:0;$countB=isset($usage[(int)$b['id']])?$usage[(int)$b['id']]:0;return $countB===$countA?strcasecmp($a['name'],$b['name']):$countB-$countA;});

            return array_slice(array_values(array_filter($items,function($item)use($usage){return !empty($usage[(int)$item['id']]);})),0,10);

        };

        $sortTaxonomyByUsage=function(&$items,$usage) {
            usort($items,function($a,$b)use($usage){
                $countA=(int)($usage[(int)$a['id']]??0);$countB=(int)($usage[(int)$b['id']]??0);
                if($countA!==$countB)return $countB<=>$countA;
                return strnatcasecmp((string)$a['name'],(string)$b['name']);
            });
        };
        $sortTaxonomyByUsage($studios,$studioUsage);
        $sortTaxonomyByUsage($actors,$actorUsage);
        $sortTaxonomyByUsage($tags,$tagUsage);

        $categoryUsage=[];

        foreach($posts as $categoryPost) {

            foreach(array_unique(preg_split('/[,،;|，；\r\n]+/u',(string)(isset($categoryPost['category'])?$categoryPost['category']:''))?:[]) as $categoryName) {

                $categoryName=trim($categoryName);

                if($categoryName!=='')$categoryUsage[$categoryName]=isset($categoryUsage[$categoryName])?$categoryUsage[$categoryName]+1:1;

            }

        }

        $categoryOptions='';$categoryRecommendations='';

        $categoryResult=mysqli_query($conn,"SELECT name FROM categories ORDER BY name");

        $categoryRows=[];

        while($categoryResult&&($categoryRow=mysqli_fetch_assoc($categoryResult)))$categoryRows[]=$categoryRow['name'];

        usort($categoryRows,function($a,$b)use($categoryUsage){$usage=(int)($categoryUsage[$b]??0)<=>(int)($categoryUsage[$a]??0);return $usage!==0?$usage:strnatcasecmp($a,$b);});

        foreach($categoryRows as $categoryName) {

            $categoryCount=(int)($categoryUsage[$categoryName]??0);

            $categoryOptions.='<option value="'.esc($categoryName).'" data-count="'.(int)$categoryCount.'">';

            $categoryRecommendations.='<button class="avsf-taxonomy-recommendation" type="button" data-category-name="'.esc($categoryName).'">'.esc($categoryName).' <span>('.(int)$categoryCount.')</span></button>';

        }

        $popularStudios=$popularTerms($studios,$studioUsage);

        $popularActors=$popularTerms($actors,$actorUsage);

        $popularTags=$popularTerms($tags,$tagUsage);

        $actorOptions=implode('',array_map(function($actor)use($actorIds,$actorUsage){$count=isset($actorUsage[(int)$actor['id']])?$actorUsage[(int)$actor['id']]:0;return '<option value="'.$actor['id'].'" data-count="'.$count.'"'.(in_array($actor['id'],$actorIds)?' selected':'').'>'.esc($actor['name']).'</option>';},$actors));

        $tagOptions=implode('',array_map(function($tag)use($tagIds,$tagUsage){$count=isset($tagUsage[(int)$tag['id']])?$tagUsage[(int)$tag['id']]:0;return '<option value="'.$tag['id'].'" data-count="'.$count.'"'.(in_array($tag['id'],$tagIds)?' selected':'').'>'.esc($tag['name']).'</option>';},$tags));

        $studioOptions=implode('',array_map(function($studio)use($studioUsage){$count=isset($studioUsage[(int)$studio['id']])?$studioUsage[(int)$studio['id']]:0;return '<option value="'.esc($studio['name']).'" data-count="'.$count.'">';},$studios));

        $popularStudioHtml=implode('',array_map(function($studio)use($studioUsage){return '<button class="avsf-taxonomy-recommendation" type="button" data-studio-name="'.esc($studio['name']).'">'.esc($studio['name']).' <span>('.(int)$studioUsage[(int)$studio['id']].')</span></button>';},$popularStudios));

        $popularActorHtml=implode('',array_map(function($actor)use($actorUsage){return '<button class="avsf-taxonomy-recommendation" type="button" data-term-id="'.$actor['id'].'" data-term-name="'.esc($actor['name']).'">'.esc($actor['name']).' <span>('.(int)$actorUsage[(int)$actor['id']].')</span></button>';},$popularActors));

        $popularTagHtml=implode('',array_map(function($tag)use($tagUsage){return '<button class="avsf-taxonomy-recommendation" type="button" data-term-id="'.$tag['id'].'" data-term-name="'.esc($tag['name']).'">'.esc($tag['name']).' <span>('.(int)$tagUsage[(int)$tag['id']].')</span></button>';},$popularTags));

        $newActorsJson=json_encode([],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

        $newTagsJson=json_encode([],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

        $postSeoTitleTemplate=(string)setting('seo_post_meta_title_template','{title} | {site_name}');
        $postSeoDescTemplate=(string)setting('seo_post_meta_desc_template','{code} - {title}');
        $postSeoVariables=['code'=>(string)($p['code']??''),'title'=>(string)($p['title']??''),'site_name'=>(string)setting('siteName','')];
        $postSeoPreviewTitle=trim((string)($p['metaTitle']??''))!==''?(string)$p['metaTitle']:str_replace(['{code}','{title}','{site_name}'],[$postSeoVariables['code'],$postSeoVariables['title'],$postSeoVariables['site_name']],$postSeoTitleTemplate);
        $postSeoPreviewDesc=trim((string)($p['metaDesc']??''))!==''?(string)$p['metaDesc']:str_replace(['{code}','{title}','{site_name}'],[$postSeoVariables['code'],$postSeoVariables['title'],$postSeoVariables['site_name']],$postSeoDescTemplate);
        if(trim($postSeoPreviewDesc)==='')$postSeoPreviewDesc=mb_substr(stripHtml((string)($p['content']??'')),0,160,'UTF-8');
        $autoTitle=$p['metaTitle']?'':'<div class="avsf-auto-hint">💡 เว้นว่าง = ใช้แม่แบบ Title: <strong>'.esc($postSeoPreviewTitle).'</strong></div>';
        $autoDesc=$p['metaDesc']?'':'<div class="avsf-auto-hint">💡 เว้นว่าง = ใช้แม่แบบ Description หรือดึงจากเนื้อหา: <strong>'.esc(mb_substr(stripHtml($p['content']),0,80,'UTF-8')).'...</strong></div>';

        $editorToolbar='<div class="avsf-rich-editor-toolbar" role="toolbar" aria-label="'.esc(__('เครื่องมือจัดรูปแบบเนื้อหา')).'">'.
            '<div class="avsf-rich-editor-toolbar-row"><div class="avsf-rich-editor-tool-group"><label class="avsf-sr-only" for="contentBlockFormat">'.esc(__('รูปแบบย่อหน้า')).'</label><select id="contentBlockFormat" data-rich-block title="'.esc(__('รูปแบบย่อหน้า')).'"><option value="p">'.esc(__('ย่อหน้า')).'</option><option value="h1">'.esc(__('หัวข้อ 1')).'</option><option value="h2">'.esc(__('หัวข้อ 2')).'</option><option value="h3">'.esc(__('หัวข้อ 3')).'</option><option value="h4">'.esc(__('หัวข้อ 4')).'</option><option value="h5">'.esc(__('หัวข้อ 5')).'</option><option value="h6">'.esc(__('หัวข้อ 6')).'</option><option value="pre">'.esc(__('จัดรูปแบบไว้ล่วงหน้า')).'</option></select>'.
            '<button type="button" data-rich-action="bold" title="'.esc(__('ตัวหนา (Ctrl+B)')).'" aria-label="'.esc(__('ตัวหนา')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/></svg></button><button type="button" data-rich-action="italic" title="'.esc(__('ตัวเอียง (Ctrl+I)')).'" aria-label="'.esc(__('ตัวเอียง')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/></svg></button><button type="button" data-rich-action="underline" title="'.esc(__('ขีดเส้นใต้')).'" aria-label="'.esc(__('ขีดเส้นใต้')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M6 3v7a6 6 0 0 0 6 6 6 6 0 0 0 6-6V3"/><line x1="4" y1="21" x2="20" y2="21"/></svg></button><button type="button" data-rich-action="strikeThrough" title="'.esc(__('ขีดฆ่า')).'" aria-label="'.esc(__('ขีดฆ่า')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><line x1="4" y1="12" x2="20" y2="12"/></svg></button>'.
            '<span class="avsf-rich-editor-separator" aria-hidden="true"></span><button type="button" data-rich-action="insertUnorderedList" title="'.esc(__('รายการหัวข้อย่อย')).'" aria-label="'.esc(__('รายการหัวข้อย่อย')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></button><button type="button" data-rich-action="insertOrderedList" title="'.esc(__('รายการตัวเลข')).'" aria-label="'.esc(__('รายการตัวเลข')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg></button><button type="button" data-rich-action="blockquote" title="'.esc(__('ข้อความอ้างอิง')).'" aria-label="'.esc(__('ข้อความอ้างอิง')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M10 11H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v6c0 2.5-1.5 4-4 5"/><path d="M19 11h-4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v6c0 2.5-1.5 4-4 5"/></svg></button>'.
            '<span class="avsf-rich-editor-separator" aria-hidden="true"></span><button type="button" data-rich-action="justifyLeft" title="'.esc(__('ชิดซ้าย')).'" aria-label="'.esc(__('ชิดซ้าย')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg></button><button type="button" data-rich-action="justifyCenter" title="'.esc(__('กึ่งกลาง')).'" aria-label="'.esc(__('กึ่งกลาง')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="18" y1="10" x2="6" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="18" y1="18" x2="6" y2="18"/></svg></button><button type="button" data-rich-action="justifyRight" title="'.esc(__('ชิดขวา')).'" aria-label="'.esc(__('ชิดขวา')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="21" y1="10" x2="7" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="7" y2="18"/></svg></button>'.
            '<span class="avsf-rich-editor-separator" aria-hidden="true"></span><button type="button" data-rich-action="createLink" title="'.esc(__('แทรกหรือแก้ไขลิงก์ (Ctrl+K)')).'" aria-label="'.esc(__('แทรกหรือแก้ไขลิงก์')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/></svg></button><button type="button" data-rich-action="unlink" title="'.esc(__('เอาลิงก์ออก')).'" aria-label="'.esc(__('เอาลิงก์ออก')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/><path d="m3 3 18 18"/></svg></button><button type="button" data-rich-action="insertMore" title="'.esc(__('เพิ่มป้ายอ่านต่อ')).'" aria-label="'.esc(__('เพิ่มป้ายอ่านต่อ')).'">'.esc(__('อ่านต่อ')).'</button><button type="button" data-rich-action="fullscreen" title="'.esc(__('เต็มจอ')).'" aria-label="'.esc(__('เต็มจอ')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button></div>'.
            '<div class="avsf-rich-editor-toolbar-row"><div class="avsf-rich-editor-tool-group"><label class="avsf-sr-only" for="contentInlineFormat">'.esc(__('รูปแบบตัวอักษร')).'</label><select id="contentInlineFormat" data-rich-inline title="'.esc(__('รูปแบบตัวอักษร')).'"><option value="">'.esc(__('รูปแบบ')).'</option><option value="code">'.esc(__('โค้ด')).'</option><option value="kbd">'.esc(__('แป้นพิมพ์')).'</option><option value="small">'.esc(__('ตัวเล็ก')).'</option><option value="sub">'.esc(__('ตัวห้อย')).'</option><option value="sup">'.esc(__('ตัวยก')).'</option></select>'.
            '<button type="button" data-rich-action="insertHorizontalRule" title="'.esc(__('เส้นแนวนอน')).'" aria-label="'.esc(__('เส้นแนวนอน')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg></button><label class="avsf-rich-color-tool avsf-rich-color-foreground" title="'.esc(__('สีตัวอักษร')).'"><span aria-hidden="true">A</span><input type="color" data-rich-color="foreColor" value="#d92d55" aria-label="'.esc(__('เลือกสีตัวอักษร')).'"></label><label class="avsf-rich-color-tool avsf-rich-color-highlight" title="'.esc(__('สีเน้นข้อความ')).'"><span aria-hidden="true">&#9632;</span><input type="color" data-rich-color="hiliteColor" value="#fff176" aria-label="'.esc(__('เลือกสีเน้นข้อความ')).'"></label>'.
            '<button type="button" data-rich-action="pasteText" title="'.esc(__('วางเป็นข้อความล้วน')).'" aria-label="'.esc(__('วางเป็นข้อความล้วน')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></button><button type="button" data-rich-action="removeFormat" title="'.esc(__('ล้างรูปแบบของข้อความที่เลือก')).'" aria-label="'.esc(__('ล้างรูปแบบ')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M20 20H7L3 16a2 2 0 0 1 0-3l9-9a2 2 0 0 1 3 0l5 5a2 2 0 0 1 0 3l-7 7"/></svg></button><button type="button" data-rich-action="specialCharacter" title="'.esc(__('แทรกอักขระพิเศษ')).'" aria-label="'.esc(__('แทรกอักขระพิเศษ')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg></button>'.
            '<span class="avsf-rich-editor-separator" aria-hidden="true"></span><button type="button" data-rich-action="outdent" title="'.esc(__('ลดย่อหน้า')).'" aria-label="'.esc(__('ลดย่อหน้า')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><polyline points="7 8 3 12 7 16"/><line x1="21" y1="12" x2="11" y2="12"/><line x1="21" y1="6" x2="11" y2="6"/><line x1="21" y1="18" x2="11" y2="18"/></svg></button><button type="button" data-rich-action="indent" title="'.esc(__('เพิ่มย่อหน้า')).'" aria-label="'.esc(__('เพิ่มย่อหน้า')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><polyline points="3 8 7 12 3 16"/><line x1="21" y1="12" x2="11" y2="12"/><line x1="21" y1="6" x2="11" y2="6"/><line x1="21" y1="18" x2="11" y2="18"/></svg></button><button type="button" data-rich-action="undo" title="'.esc(__('ย้อนกลับ (Ctrl+Z)')).'" aria-label="'.esc(__('ย้อนกลับ')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></button><button type="button" data-rich-action="redo" title="'.esc(__('ทำซ้ำ (Ctrl+Y)')).'" aria-label="'.esc(__('ทำซ้ำ')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><polyline points="15 14 20 9 15 4"/><path d="M4 20v-7a4 4 0 0 1 4-4h12"/></svg></button><button type="button" data-rich-action="keyboardHelp" title="'.esc(__('ปุ่มลัดคีย์บอร์ด')).'" aria-label="'.esc(__('ปุ่มลัดคีย์บอร์ด')).'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></button></div></div>'.
            '<div class="avsf-rich-editor-specials" data-rich-specials hidden aria-label="'.esc(__('อักขระพิเศษ')).'">'.implode('',array_map(function($character){return '<button type="button" data-special-character="'.esc($character).'">'.esc($character).'</button>';},['©','®','™','…','—','–','“','”','‘','’','•','×','÷','฿','¥','€'])).'</div>'.
            '<span class="avsf-rich-editor-status" data-rich-status aria-live="polite"></span></div>'.
            '<div id="postContentVisual" class="avsf-post-content-visual" contenteditable="true" role="textbox" aria-multiline="true" aria-label="'.esc(__('เนื้อหาโพสต์แบบ Rich Text')).'" spellcheck="true" hidden></div>';

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:16px;">'.($p['id']? __('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg> แก้ไขโพสต์'): __('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> เขียนโพสต์ใหม่')).'</h1>'.

        '<div class="avsf-editor-preview-layout"><form id="postEditorForm" class="avsf-editor-form" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_post"><input type="hidden" name="id" value="'.$p['id'].'">'.

        '<div class="avsf-editor-columns"><div class="avsf-editor-side-column"><details class="avsf-seo-audit avsf-seo-audit-sticky" id="seoAudit" data-site-name="'.esc(setting('siteName')).'" aria-live="polite"><summary class="avsf-seo-audit-summary"><span class="avsf-seo-audit-light" data-seo-light></span><strong data-seo-overall>กำลังตรวจ SEO</strong><span class="avsf-seo-audit-score" data-seo-score>0/100</span></summary><ul class="avsf-seo-audit-list" data-seo-checks></ul><small class="avsf-seo-audit-note">รายการตรวจช่วยเตือนเบื้องต้น ไม่ใช่การรับรองอันดับจาก Google</small></details><aside class="avsf-card avsf-editor-preview-panel"><div class="avsf-section-title">👁️ ตัวอย่างโพสต์ (อัปเดตขณะพิมพ์)</div><span id="postPreviewStatus" class="avsf-post-status '.($p['status']==='publish'?'avsf-is-published':'avsf-is-draft').'">'.($p['status']==='publish'? __('เผยแพร่'): __('ฉบับร่าง')).'</span><h2 id="postPreviewTitle" style="font-size:19px;line-height:1.5;margin:12px 0 10px;">'.esc($p['title']?: __('ชื่อเรื่องจะแสดงที่นี่')).'</h2><div id="postPreviewMeta" style="font-size:12px;color:var(--muted);margin-bottom:14px;">'.esc(implode(' · ',array_filter([isset($p['code'])?$p['code']:'',getStudio($p['studioId'])['name'],isset($p['actorEN'])?$p['actorEN']:'',isset($p['category'])?$p['category']:'' ]))).'</div><div id="postPreviewContent" class="avsf-post-preview-content">'.esc(stripHtml($p['content'])?: __('เนื้อหาตัวอย่างจะแสดงที่นี่')).'</div><div id="postPreviewSEO" style="border-top:1px solid var(--border);margin-top:16px;padding-top:12px;font-size:12px;color:var(--muted);">Focus Keyphrase: '.esc(isset($p['focusKeyphrase'])?$p['focusKeyphrase']:'-').' | Meta Description: '.esc($p['metaDesc']?: __('ระบบจะสร้างจากเนื้อหาอัตโนมัติ')).'</div></aside>'.

        '<div class="avsf-editor-cover-preview" id="editorCoverPreview"'.((!empty($p['cover'])||!empty($p['posterUrl']))?'':' hidden').'><img id="editorCoverPreviewImage" src="'.esc(!empty($p['cover'])?$p['cover']:(isset($p['posterUrl'])?$p['posterUrl']:'')).'" alt="ตัวอย่างรูปปกโพสต์"><span>ตัวอย่างรูปปกโพสต์</span></div>'.

        '<div class="avsf-card avsf-mb avsf-editor-image-card"><div class="avsf-section-title" style="color:var(--accent2);">🖼️ รูปปกและโปสเตอร์</div>'.

        '<div class="avsf-form-group"><label>รูปปก: ใส่ลิงก์หรือเลือกไฟล์</label><input name="cover" value="'.esc($p['cover']).'" placeholder="https://... (หากเลือกไฟล์ ไฟล์จะถูกใช้แทนลิงก์)"><input type="file" name="coverUpload" accept="image/jpeg,image/png,image/gif,image/webp" style="margin-top:8px;"><label style="margin-top:8px">ALT รูปปก</label><input name="coverAlt" value="'.esc(isset($p['coverAlt'])?$p['coverAlt']:'').'" placeholder="คำอธิบายรูปปกสำหรับ SEO และผู้ใช้ Screen Reader"><small class="avsf-field-help">ใช้เป็นภาพปกการ์ดและภาพสำรอง · JPG, PNG, GIF, WebP ไม่เกิน 5 MB</small></div>'.

        '<div class="avsf-form-group"><label>โปสเตอร์หน้าโพสต์: ใส่ลิงก์หรือเลือกไฟล์</label><input name="poster" value="'.esc(isset($p['posterUrl'])?$p['posterUrl']:'').'" placeholder="ไม่ระบุ = ใช้รูปปกเป็นโปสเตอร์"><input type="file" name="posterUpload" accept="image/jpeg,image/png,image/gif,image/webp" style="margin-top:8px;"><label style="margin-top:8px">ALT โปสเตอร์</label><input name="posterAlt" value="'.esc(isset($p['posterAlt'])?$p['posterAlt']:'').'" placeholder="คำอธิบายโปสเตอร์สำหรับ SEO และผู้ใช้ Screen Reader"><small class="avsf-field-help">โปสเตอร์มีลำดับเหนือรูปปกในหน้าโพสต์ · JPG, PNG, GIF, WebP ไม่เกิน 5 MB</small></div></div>'.

        '<div class="avsf-card avsf-mb avsf-editor-video-card"><div class="avsf-section-title" style="color:var(--accent);">🎬 วิดีโอและ Remote Player</div>'.

        '<div class="avsf-form-group"><label for="videoUrlInput">ลิงก์วิดีโอ หรือวาง iframe เต็ม</label><textarea id="videoUrlInput" name="videoUrl" rows="3" placeholder="https://player.example.com/embed/ID&#10;หรือวางโค้ด &lt;iframe src=&quot;https://...&quot;&gt;&lt;/iframe&gt;">'.esc($p['videoUrl']).'</textarea><small class="avsf-video-input-help">รองรับลิงก์ http(s), URL วิดีโอ MP4/WebM/OGG, YouTube, Google Drive, Fembed, โดเมน player ที่ตั้งค่าไว้ และโค้ด iframe เต็ม ระบบจะดึงเฉพาะ src แล้วสร้าง iframe ที่ปลอดภัยให้</small></div>'.

        '<div class="avsf-remote-api-status '.(getenv('AVSF_VDOHIDE_TOKEN')?'avsf-is-ready':'avsf-is-off').'"><span class="avsf-remote-api-dot"></span><span><strong>'.(getenv('AVSF_VDOHIDE_TOKEN')? __('Remote Player API พร้อมใช้งาน'): __('Remote Player API ยังไม่ได้ตั้งค่า')).'</strong><small>'.(getenv('AVSF_VDOHIDE_TOKEN')? __('ระบบจะสร้าง Player URL ตอนบันทึกโพสต์'): __('ลิงก์วิดีโอและ iframe ที่รองรับยังใช้งานได้โดยตรง ตั้งค่า token ที่ฝั่งเซิร์ฟเวอร์เพื่อเปิด API')).'</small></span></div>'.

        '<div id="videoInputPreview" class="avsf-video-input-preview">'.($v['embed']?'<div class="avsf-video-wrap">'.$v['embed'].'</div>': __('<span>ตัวอย่างเครื่องเล่นจะแสดงที่นี่</span>')).'</div></div>'.

        '<div class="avsf-card avsf-mb avsf-editor-seo-card"><div class="avsf-section-title" style="color:var(--gold);">🔍 SEO ต่อโพสต์</div>'.

        '<div class="avsf-form-group"><label>Meta Title <small>เว้นว่างเพื่อใช้แม่แบบ</small></label><input name="metaTitle" value="'.esc($p['metaTitle']).'" data-preview="metaTitle" maxlength="200">'.$autoTitle.'<small class="avsf-char-count" data-post-title-count></small></div>'.

        '<div class="avsf-form-group"><label>Meta Description <small>เว้นว่างเพื่อใช้แม่แบบ/คำอธิบายจากเนื้อหา</small></label><textarea name="metaDesc" rows="3" data-preview="metaDesc" maxlength="300">'.esc($p['metaDesc']).'</textarea>'.$autoDesc.'<small class="avsf-char-count" data-post-desc-count></small></div>'.

        '<div class="avsf-token-picker"><span>ตัวแปรที่ใช้ได้ในโพสต์นี้:</span><button type="button" class="avsf-token-chip" data-post-token="{code}">{code}</button><button type="button" class="avsf-token-chip" data-post-token="{title}">{title}</button><button type="button" class="avsf-token-chip" data-post-token="{site_name}">{site_name}</button></div>'.
        '<div class="avsf-google-preview" data-post-seo-preview data-title-template="'.esc($postSeoTitleTemplate).'" data-desc-template="'.esc($postSeoDescTemplate).'" data-site-name="'.esc(setting('siteName','')).'"><div class="avsf-google-preview-label">ตัวอย่างผลการค้นหา · อัปเดตตามข้อมูลจริงขณะพิมพ์</div><div class="avsf-google-preview-title" data-post-serp-title>'.esc($postSeoPreviewTitle).'</div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/').'<span data-post-serp-slug>'.esc((string)$p['slug']).'</span></div><div class="avsf-google-preview-desc" data-post-serp-desc>'.esc($postSeoPreviewDesc).'</div><div class="avsf-google-preview-status" data-post-serp-status>ตัวอย่างประมาณการเท่านั้น · Google อาจเลือกข้อความใหม่หรือตัดตามอุปกรณ์และคำค้นหา</div></div>'.

        '<div class="avsf-form-group"><label>Keywords (คั่นด้วย ,)</label><input name="keywords" value="'.esc($p['keywords']).'"></div></div>'.

        '</div><div class="avsf-editor-primary-column">'.

        '<div class="avsf-card avsf-mb avsf-editor-basic-card"><div class="avsf-section-title">📌 ข้อมูลพื้นฐาน</div>'.

        '<div class="avsf-form-row avsf-code-status-row"><div class="avsf-form-group"><label for="postCodeInput">รหัสเรื่อง (Code)</label><input id="postCodeInput" class="avsf-code-input" name="code" value="'.esc(isset($p['code'])?$p['code']:'').'" data-preview="code" placeholder="เช่น ABC-123"></div>'.

        '<div class="avsf-form-group"><label for="postStatusInput">สถานะ</label><select id="postStatusInput" name="status" data-preview="status"><option value="publish"'.($p['status']==='publish'?' selected':'').'>เผยแพร่</option><option value="draft"'.($p['status']==='draft'?' selected':'').'>แบบร่าง</option></select></div></div>'.

        '<div class="avsf-form-row avsf-focus-slug-row"><div class="avsf-form-group"><label for="postFocusInput">Focus Keyphrase</label><input id="postFocusInput" name="focusKeyphrase" value="'.esc(isset($p['focusKeyphrase'])?$p['focusKeyphrase']:'').'" data-preview="focusKeyphrase"></div>'.

        '<div class="avsf-form-group"><label for="postSlugInput">Slug (URL)</label><input id="postSlugInput" name="slug" value="'.esc($p['slug']).'" data-preview="slug"></div></div>'.

        '<div class="avsf-form-group avsf-post-title-field"><label for="postTitleInput">ชื่อเรื่อง *</label><textarea id="postTitleInput" class="avsf-post-title-autogrow" name="title" rows="1" data-preview="title" data-title-autogrow maxlength="70" required>'.esc($p['title']).'</textarea></div>'.

        '<div class="avsf-form-row-3"><div class="avsf-form-group"><label>วันที่เผยแพร่</label><input type="date" name="date" value="'.$p['date'].'"></div>'.

        '<div class="avsf-form-group avsf-video-duration-group"><label for="videoDuration">ความยาววิดีโอ</label><div class="avsf-video-duration-fields"><input id="videoDuration" name="videoDuration" value="'.esc(isset($p['videoDuration'])?$p['videoDuration']:'').'" placeholder="เช่น 6300, 105:00, 01:45:00 หรือ PT1H45M"><div class="avsf-form-group"><label for="videoDurationFormatted">รูปแบบชั่วโมง</label><input id="videoDurationFormatted" value="'.esc(formatVideoDuration(isset($p['videoDuration'])?$p['videoDuration']:'')).'" readonly tabindex="-1" aria-label="ความยาววิดีโอในรูปแบบ ชั่วโมง:นาที:วินาที"></div></div><small class="avsf-field-help">ใส่ได้ทั้งวินาที, นาที:วินาที, ชั่วโมง:นาที:วินาที, รูปแบบ PT... หรือหน่วยชั่วโมง/นาที/วินาที · ระบบเก็บเป็นวินาที</small></div></div></div>'.

        '<div class="avsf-card avsf-mb avsf-editor-content-card"><div class="avsf-section-title">✍️ เนื้อหาโพสต์</div><div class="avsf-form-group"><label for="postContentVisual">เนื้อหาโพสต์ · เลือกข้อความแล้วใช้เครื่องมือจัดรูปแบบ</label>'.$editorToolbar.'<textarea id="postContentEditor" name="content" rows="12" data-preview="content">'.esc($p['content']).'</textarea><small class="avsf-field-help">ลากครอบข้อความแล้วเลือกตัวหนา สีตัวอักษร สีเน้น หัวข้อ รายการ ลิงก์ และเครื่องมืออื่น ๆ ได้ทันที · HTML จะบันทึกพร้อมโพสต์</small></div></div>'.

        '<div class="avsf-card avsf-mb avsf-editor-gallery-card"><div class="avsf-section-title" style="color:var(--accent2);">🖼️ แกลเลอรี่</div><div class="avsf-form-group"><label for="galleryUpload">อัปโหลดภาพหลายรูป หรือวาง URL หนึ่งรูปต่อบรรทัด</label><input id="galleryUpload" type="file" name="galleryUploads[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple><small class="avsf-field-help">เลือกภาพได้หลายรูป · JPG, PNG, GIF, WebP ไม่เกิน 5 MB ต่อรูป · ภาพใหม่จะเพิ่มต่อจากภาพเดิม</small></div><div class="avsf-form-group"><label for="galleryUrls">URL รูปภาพ (หนึ่ง URL ต่อบรรทัด)</label><textarea id="galleryUrls" class="avsf-gallery-autogrow" name="gallery" rows="2" data-autogrow aria-label="URL ภาพแกลเลอรี่ หนึ่งรูปต่อบรรทัด" placeholder="https://example.com/image-1.jpg&#10;https://example.com/image-2.jpg">'.esc(implode("\n",$gallery)).'</textarea></div><div class="avsf-form-group"><label for="galleryAlt">ALT แกลเลอรี่ (หนึ่งรายการต่อบรรทัด)</label><textarea id="galleryAlt" class="avsf-gallery-autogrow" name="galleryAlt" rows="2" data-autogrow placeholder="ALT รูปที่ 1&#10;ALT รูปที่ 2">'.esc(implode("\n",$galleryAlt)).'</textarea><small class="avsf-field-help">เริ่มต้นแสดง 2 บรรทัด และขยายอัตโนมัติตามจำนวนรูป/ข้อความที่ใส่</small></div><div id="editorGalleryPreview" class="avsf-editor-gallery-preview" aria-live="polite">'.$galleryPreviewHtml.'</div></div>'.

        '<div class="avsf-card avsf-mb avsf-editor-taxonomy-card avsf-editor-tags-card"><div class="avsf-section-title" style="color:var(--accent3);">🏷️ หมวดหมู่ / ค่าย / นักแสดง / แท็ก</div>'.

        '<div class="avsf-form-group"><label for="postCategoryInput">หมวดหมู่ <span class="avsf-usage-caption">ค้นหาหรือเพิ่มชื่อหมวดหมู่</span></label><div class="avsf-taxonomy-autocomplete"><input id="postCategoryInput" name="category" value="'.esc(isset($p['category'])?$p['category']:'').'" data-preview="category" autocomplete="off" aria-autocomplete="list" aria-controls="postCategorySuggestions" placeholder="พิมพ์ชื่อหมวดหมู่"><datalist id="postCategoryOptions">'.$categoryOptions.'</datalist><div id="postCategorySuggestions" class="avsf-taxonomy-suggestions" hidden></div></div><div class="avsf-taxonomy-recommendations" aria-label="หมวดหมู่ที่มีอยู่">'.$categoryRecommendations.'</div><small class="avsf-field-help">กดชื่อเพื่อเลือก หรือพิมพ์ชื่อใหม่แล้วกด Enter</small></div>'.

        '<div class="avsf-form-group"><label for="studioName">ค่าย <span class="avsf-usage-caption">พิมพ์ค้นหาหรือเลือก 10 ค่ายที่ใช้บ่อย</span></label><div class="avsf-taxonomy-autocomplete"><input id="studioName" name="studioName" value="'.esc(getStudio($p['studioId'])['name']).'" autocomplete="off" aria-autocomplete="list" aria-controls="studioSuggestions" placeholder="พิมพ์ค้นหาหรือเพิ่มชื่อค่าย"><datalist id="studioOptions">'.$studioOptions.'</datalist><div id="studioSuggestions" class="avsf-taxonomy-suggestions" hidden></div></div><div class="avsf-taxonomy-recommendations" aria-label="ค่ายที่ใช้บ่อยที่สุด">'.$popularStudioHtml.'</div><small class="avsf-field-help">กดชื่อเพื่อเลือก หรือพิมพ์ชื่อใหม่แล้วกด Enter</small></div>'.

        '<div class="avsf-form-group"><label>นักแสดง <span class="avsf-usage-caption">ค้นหา เลือกหลายคน หรือเพิ่มชื่อใหม่</span></label><div class="avsf-taxonomy-picker" data-taxonomy-picker="actors"><div class="avsf-taxonomy-selected" aria-live="polite"></div><input id="actorPickerInput" class="avsf-taxonomy-input" type="text" autocomplete="off" aria-autocomplete="list" aria-expanded="false" placeholder="พิมพ์ชื่อนักแสดงแล้วกด Enter"><div class="avsf-taxonomy-suggestions" hidden></div></div><input type="hidden" name="actorEN" value="'.esc(isset($p['actorEN'])?$p['actorEN']:'').'"><input type="hidden" name="newActorNames" class="avsf-taxonomy-custom-values" value="'.esc($newActorsJson).'"><select class="avsf-taxonomy-source" name="actorIds[]" multiple data-preview="actors">'.$actorOptions.'</select>'.

        '<div class="avsf-taxonomy-recommendations" aria-label="นักแสดงที่ใช้บ่อยที่สุด">'.$popularActorHtml.'</div><small class="avsf-field-help">กดชื่อเพื่อเลือกนักแสดงที่แนะนำ</small></div>'.

        '<div class="avsf-editor-tags-section"><div class="avsf-section-title" style="color:var(--accent3);">🏷️ แท็ก</div><div class="avsf-form-group"><label>แท็ก <span class="avsf-usage-caption">ค้นหา เลือกหลายแท็ก หรือพิมพ์เพิ่มเอง</span></label><div class="avsf-taxonomy-picker" data-taxonomy-picker="tags"><div class="avsf-taxonomy-selected" aria-live="polite"></div><input id="tagPickerInput" class="avsf-taxonomy-input" type="text" autocomplete="off" aria-autocomplete="list" aria-expanded="false" placeholder="พิมพ์แท็กแล้วกด Enter"><div class="avsf-taxonomy-suggestions" hidden></div></div><input type="hidden" name="newTagNames" class="avsf-taxonomy-custom-values" value="'.esc($newTagsJson).'"><select class="avsf-taxonomy-source" name="tagIds[]" multiple data-preview="tags">'.$tagOptions.'</select><div class="avsf-taxonomy-recommendations" aria-label="แท็กที่ใช้บ่อยที่สุด">'.$popularTagHtml.'</div><small class="avsf-field-help">กดชื่อเพื่อเลือกแท็กที่แนะนำ</small></div></div></div>'.

        '</div></div></div>'.

        '<div class="avsf-editor-actions"><button class="avsf-btn avsf-btn-primary" type="submit">💾 บันทึกโพสต์</button><button class="avsf-btn avsf-btn-primary" type="submit" name="post_intent" value="publish" style="background:var(--success);box-shadow:none;">🚀 เผยแพร่ทันที</button><button class="avsf-btn avsf-btn-ghost" type="submit" name="post_intent" value="draft">📝 บันทึกเป็นฉบับร่าง</button><a class="avsf-btn avsf-btn-ghost" href="index.php?page=posts">ยกเลิก</a></div></form></div>';

    }

    if (in_array($page,['categories','studios','actors','tags'],true)) {

        $type = $page==='categories'?'category':($page==='studios'?'studio':($page==='actors'?'actor':'tag'));
        $table = ['category'=>'categories','studio'=>'studios','actor'=>'actors','tag'=>'tags'][$type];
        $labels = ['category'=>__('หมวดหมู่'),'studio'=>__('ค่าย'),'actor'=>__('นักแสดง'),'tag'=>__('แท็ก')];
        $label=$labels[$type];
        $routeType=$type;
        $token=$type==='category'?'cat':$type;
        $taxonomyToken='%%'.$token.'%%';
        $defaultTitleTemplates=[
            'category'=>'%%cat%% %%sep%% %%sitename%%',
            'studio'=>'%%studio%% %%sep%% ผลงานและเรื่องใหม่ %%sep%% %%sitename%%',
            'actor'=>'%%actor%% %%sep%% ผลงานทั้งหมด %%sep%% %%sitename%%',
            'tag'=>'%%tag%% %%sep%% %%sitename%%'
        ];
        $defaultDescTemplates=[
            'category'=>'รวมเรื่องในหมวด %%cat%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%',
            'studio'=>'รวมผลงานจากค่าย %%studio%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%',
            'actor'=>'รวมผลงานของ %%actor%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%',
            'tag'=>'รวมเรื่องที่ติดแท็ก %%tag%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%'
        ];
        $titleTemplate=trim((string)setting('seo_taxonomy_'.$type.'_title',$defaultTitleTemplates[$type]));
        $descTemplate=trim((string)setting('seo_taxonomy_'.$type.'_desc',$defaultDescTemplates[$type]));
        if($titleTemplate==='')$titleTemplate=$defaultTitleTemplates[$type];
        if($descTemplate==='')$descTemplate=$defaultDescTemplates[$type];
        $siteName=avsfSeoSiteName(setting('seo_site_name',setting('siteName','')));
        $items=[];$result=mysqli_query($conn,"SELECT * FROM `$table` ORDER BY name ASC");
        if($result)while($row=mysqli_fetch_assoc($result))$items[]=$row;
        // Admin counts and drill-down lists include published, draft and private posts.
        $taxonomyPosts=[];$taxonomyPostResult=mysqli_query($conn,"SELECT id,title,slug,category,studioId,actorIds,tagIds,status FROM posts ORDER BY date DESC,id DESC");
        if(!$taxonomyPostResult)throw new RuntimeException('Could not load taxonomy post usage: '.mysqli_error($conn));
        while($taxonomyPostRow=mysqli_fetch_assoc($taxonomyPostResult))$taxonomyPosts[]=$taxonomyPostRow;
        mysqli_free_result($taxonomyPostResult);
        $usage=[];$postsByTerm=[];
        foreach($taxonomyPosts as $taxonomyPost) {
            $matchedIds=[];
            if($type==='category') {
                foreach(splitTaxonomyNames($taxonomyPost['category']??'') as $termName) {
                    foreach($items as $candidate)if((string)$candidate['name']===(string)$termName){$matchedIds[]=(int)$candidate['id'];break;}
                }
            } elseif($type==='studio') {
                $sid=(int)($taxonomyPost['studioId']??0);if($sid>0)$matchedIds[]=$sid;
            } else {
                $column=$type==='actor'?'actorIds':'tagIds';
                $matchedIds=parseTaxonomyIds($taxonomyPost[$column]??'[]');
            }
            foreach(array_unique(array_map('intval',$matchedIds)) as $termId)if($termId>0){
                $usage[$termId]=($usage[$termId]??0)+1;
                $postsByTerm[$termId][]=$taxonomyPost;
            }
        }
        usort($items,function($a,$b)use($usage){
            $countCompare=(int)($usage[(int)$b['id']]??0)<=>(int)($usage[(int)$a['id']]??0);
            return $countCompare!==0?$countCompare:strnatcasecmp((string)$a['name'],(string)$b['name']);
        });
        $overrides=[];
        try {
            ensureSeoIndexOverridesTable($conn);
            $overrideResult=mysqli_query($conn,"SELECT entity_id,indexable FROM seo_index_overrides WHERE entity_type='".mysqli_real_escape_string($conn,$type)."'");
            if($overrideResult)while($overrideRow=mysqli_fetch_assoc($overrideResult))$overrides[(int)$overrideRow['entity_id']]=(int)$overrideRow['indexable'];
        } catch(Throwable $ignore) { error_log('AVSF taxonomy index overrides unavailable: '.$ignore->getMessage()); }
        $allowedTokens=[$taxonomyToken,'%%title%%','%%term_title%%','%%name%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%post_count%%','%%item_count%%','%%sep%%','%%page%%','%%currentyear%%'];
        $tokenButtons='';
        foreach($allowedTokens as $allowedToken)$tokenButtons.='<button type="button" class="avsf-token-chip" data-insert-token="'.esc($allowedToken).'">'.esc($allowedToken).'</button>';

        $rows='';
        foreach($items as $item) {
            $id=(int)$item['id'];$name=(string)$item['name'];$slug=(string)$item['slug'];$count=(int)($usage[$id]??0);
            $customTitle=trim((string)($item['metaTitle']??''));$customDesc=trim((string)($item['metaDesc']??''));
            $hasCustomSeo=($customTitle!==''||$customDesc!=='');
            $resolvedTitle=taxonomySeoValue($type,'title',$name,$siteName,$customTitle,$count);
            $resolvedDesc=taxonomySeoValue($type,'desc',$name,$siteName,$customDesc,$count);
            $indexState=array_key_exists($id,$overrides)?($overrides[$id]?'index':'noindex'):'inherit';
            $effectiveIndex=$indexState==='inherit'?seoIndexDefault($type):($indexState==='index');
            $formId='avsf-term-form-'.$type.'-'.$id;
            $archiveUrl='../index.php?page='.$routeType.'&amp;slug='.rawurlencode($slug);
            $postList='';
            foreach(($postsByTerm[$id]??[]) as $termPost) {
                $postUrl='../index.php?page=post&amp;slug='.rawurlencode((string)$termPost['slug']);
                $editUrl='index.php?page=post-edit&amp;id='.(int)$termPost['id'];
                $postStatus=(string)($termPost['status']??'')==='publish'?'เผยแพร่':((string)($termPost['status']??'')==='draft'?'ฉบับร่าง':'ส่วนตัว');
                $postList.='<li><span class="avsf-term-post-info"><a class="avsf-term-post-link" href="'.$postUrl.'" target="_blank" rel="noopener">'.esc($termPost['title']??('โพสต์ #'.(int)$termPost['id'])).'</a><small>'.esc($postStatus).'</small></span><a class="avsf-term-post-edit" href="'.$editUrl.'">แก้ไข</a></li>';
            }
            if($postList==='')$postList='<li class="avsf-term-post-empty">ยังไม่มีโพสต์ที่เผยแพร่ในรายการนี้</li>';
            $detailFields='';
            if($type==='studio') {
                $detailFields.='<div class="avsf-form-group"><label>โลโก้ค่าย · URL หรือไฟล์ใหม่</label><input form="'.$formId.'" name="logo" value="'.esc($item['logo']??'').'" placeholder="https://example.com/logo.webp"><input form="'.$formId.'" type="file" name="logoUpload" accept="image/jpeg,image/png,image/gif,image/webp,image/avif"><small class="avsf-field-help">ปล่อยว่างเพื่อคงรูปเดิม · รับไฟล์ภาพที่ระบบอนุญาต</small></div><div class="avsf-form-group"><label>คำอธิบายค่าย</label><textarea form="'.$formId.'" name="desc" rows="3">'.esc($item['desc']??'').'</textarea></div>';
            } elseif($type==='actor') {
                $detailFields.='<div class="avsf-form-group"><label>รูปนักแสดง · URL หรือไฟล์ใหม่</label><input form="'.$formId.'" name="photo" value="'.esc($item['photo']??'').'" placeholder="https://example.com/actor.webp"><input form="'.$formId.'" type="file" name="photoUpload" accept="image/jpeg,image/png,image/gif,image/webp,image/avif"><small class="avsf-field-help">ปล่อยว่างเพื่อคงรูปเดิม</small></div><div class="avsf-form-group"><label>ประวัติ / คำอธิบาย</label><textarea form="'.$formId.'" name="bio" rows="3">'.esc($item['bio']??'').'</textarea></div>';
            }
            $checked=$hasCustomSeo?' checked':'';$disabled=$hasCustomSeo?'':' disabled';
            $titleValue=$hasCustomSeo?($customTitle!==''?$customTitle:$titleTemplate):$titleTemplate;
            $descValue=$hasCustomSeo?($customDesc!==''?$customDesc:$descTemplate):$descTemplate;
            $detailFields.='<details class="avsf-term-settings"><summary>SEO Title, Meta Description และการจัดทำดัชนี</summary><div class="avsf-term-settings-body">'
                .'<label class="avsf-seo-custom-toggle"><input form="'.$formId.'" type="checkbox" name="use_custom_seo" value="1" data-seo-custom-toggle'.$checked.'> กำหนด SEO เฉพาะรายการนี้ (ไม่ใช้แม่แบบส่วนกลาง)</label>'
                .'<div class="avsf-form-group"><label>SEO Title</label><input form="'.$formId.'" name="metaTitle" maxlength="200" value="'.esc($titleValue).'" data-seo-title'.$disabled.'><small class="avsf-char-count" data-title-count></small></div>'
                .'<div class="avsf-form-group"><label>Meta Description</label><textarea form="'.$formId.'" name="metaDesc" maxlength="300" rows="3" data-seo-description'.$disabled.'>'.esc($descValue).'</textarea><small class="avsf-char-count" data-desc-count></small></div>'
                .'<div class="avsf-token-picker"><span>ตัวแปรที่ใช้ได้เฉพาะ '.$label.' นี้:</span>'.$tokenButtons.'</div>'
                .'<div class="avsf-google-preview"><div class="avsf-google-preview-label">ตัวอย่างผลการค้นหา · แสดงค่าตัวแปรจริง</div><div class="avsf-google-preview-title" data-seo-preview-title>'.esc($resolvedTitle).'</div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/'.$type.'/'.rawurlencode($slug)).'</div><div class="avsf-google-preview-desc" data-seo-preview-desc>'.esc($resolvedDesc).'</div><div class="avsf-google-preview-status" data-seo-preview-status>ตัวอย่างประมาณการ · Google อาจเปลี่ยนข้อความหรือความยาวที่แสดงตามอุปกรณ์และคำค้นหา</div></div>'
                .'<div class="avsf-form-group"><label>การจัดทำดัชนี (Indexing)</label><select form="'.$formId.'" name="indexable"><option value="inherit"'.($indexState==='inherit'?' selected':'').'>ตามค่า SEO เริ่มต้น ('.($effectiveIndex?'Index':'Noindex').')</option><option value="index"'.($indexState==='index'?' selected':'').'>Index · อนุญาตให้จัดทำดัชนี</option><option value="noindex"'.($indexState==='noindex'?' selected':'').'>Noindex · ไม่ให้นำเข้าดัชนี</option></select><small class="avsf-field-help">Noindex จะมีผลต่อ Robots Meta และ Sitemap ผ่านระบบ SEO ของ AVSF</small></div>'
                .'</div></details>';
            $rows.='<article class="avsf-tax-item" data-seo-editor data-term-type="'.esc($type).'" data-term-name="'.esc($name).'" data-term-count="'.$count.'" data-sitename="'.esc($siteName).'" data-sitedesc="'.esc(setting('siteDesc','')).'" data-primary-keyword="'.esc(setting('seo_primary_keyword','')).'" data-separator="'.esc(setting('seo_title_separator','|')).'" data-title-template="'.esc($titleTemplate).'" data-desc-template="'.esc($descTemplate).'" data-token="'.esc($taxonomyToken).'">'
                .'<form id="'.$formId.'" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="'.($type==='category'?'save_category':'save_tax').'">'.($type!=='category'?'<input type="hidden" name="tax_type" value="'.esc($type).'">':'').'<input type="hidden" name="id" value="'.$id.'"></form>'
                .'<div class="avsf-tax-main-row"><div class="avsf-tax-name"><a href="'.$archiveUrl.'" target="_blank" rel="noopener" class="avsf-tax-name-link">'.esc($name).'</a><span class="avsf-tax-type-label">'.$label.'</span></div><div class="avsf-tax-slug"><span>Slug:</span> <a href="'.$archiveUrl.'" target="_blank" rel="noopener">'.esc($slug).'</a></div><button class="avsf-tax-count" type="button" data-toggle-posts="term-posts-'.$type.'-'.$id.'"><strong>'.number_format($count).'</strong><span>เรื่อง</span><small>กดดูรายการ</small></button><details class="avsf-tax-editor"><summary><span class="avsf-tax-edit-icon">✎</span> จัดการ / แก้ไข</summary><div class="avsf-tax-edit-body">'
                .'<div class="avsf-form-row"><div class="avsf-form-group"><label>ชื่อ'.$label.'</label><input form="'.$formId.'" name="name" data-term-name-input maxlength="'.($type==='category'?150:100).'" value="'.esc($name).'" required></div><div class="avsf-form-group"><label>Slug</label><input form="'.$formId.'" name="slug" maxlength="150" value="'.esc($slug).'" required><small class="avsf-field-help">แก้ Slug จะเก็บเส้นทางเดิมเป็น Alias เพื่อช่วยรักษา URL</small></div></div>'
                .$detailFields
                .'<div class="avsf-term-save-row"><button form="'.$formId.'" class="avsf-btn avsf-btn-primary" type="submit">บันทึกการเปลี่ยนแปลง</button><a class="avsf-btn avsf-btn-ghost" href="'.$archiveUrl.'" target="_blank" rel="noopener">ดูหน้าเว็บไซต์</a><form method="post"><input type="hidden" name="action" value="'.($type==='category'?'delete_category':'delete_tax').'">'.($type!=='category'?'<input type="hidden" name="tax_type" value="'.esc($type).'">':'').'<input type="hidden" name="id" value="'.$id.'"><button class="avsf-btn avsf-btn-danger" type="submit" onclick="return confirm(\'ยืนยันลบรายการนี้?\')">ลบรายการ</button></form></div>'
                .'</div></details></div>'
                .'<details class="avsf-tax-posts" id="term-posts-'.$type.'-'.$id.'"><summary>รายการโพสต์ทั้งหมดใน'.$label.'นี้ ('.number_format($count).')</summary><ul>'.$postList.'</ul></details>'
                .'</article>';
        }
        $bulkId='bulk-delete-'.$type;
        $newExtra='';
        if($type==='studio')$newExtra='<div class="avsf-form-group"><label>โลโก้ค่าย</label><input name="logo" placeholder="URL โลโก้"><input type="file" name="logoUpload" accept="image/jpeg,image/png,image/gif,image/webp,image/avif"></div><div class="avsf-form-group"><label>คำอธิบายค่าย</label><textarea name="desc" rows="2"></textarea></div>';
        elseif($type==='actor')$newExtra='<div class="avsf-form-group"><label>รูปนักแสดง</label><input name="photo" placeholder="URL รูปนักแสดง"><input type="file" name="photoUpload" accept="image/jpeg,image/png,image/gif,image/webp,image/avif"></div><div class="avsf-form-group"><label>ประวัติ / คำอธิบาย</label><textarea name="bio" rows="2"></textarea></div>';
        $addForm='<section class="avsf-card avsf-tax-add-card"><h2>＋ เพิ่ม'.$label.'ใหม่</h2><form method="post" enctype="multipart/form-data" data-seo-editor data-term-type="'.esc($type).'" data-term-name="ตัวอย่าง'.$label.'" data-term-count="0" data-sitename="'.esc($siteName).'" data-sitedesc="'.esc(setting('siteDesc','')).'" data-primary-keyword="'.esc(setting('seo_primary_keyword','')).'" data-separator="'.esc(setting('seo_title_separator','|')).'" data-title-template="'.esc($titleTemplate).'" data-desc-template="'.esc($descTemplate).'" data-token="'.esc($token).'"><input type="hidden" name="action" value="'.($type==='category'?'save_category':'add_tax').'">'.($type!=='category'?'<input type="hidden" name="tax_type" value="'.esc($type).'">':'').'<div class="avsf-form-row"><div class="avsf-form-group"><label>ชื่อ *</label><input name="name" required placeholder="ชื่อ'.$label.'"></div><div class="avsf-form-group"><label>Slug (เว้นว่างเพื่อสร้างอัตโนมัติ)</label><input name="slug" placeholder="ระบบสร้างจากชื่ออัตโนมัติ"></div></div>'.$newExtra
            .'<details class="avsf-term-settings"><summary>SEO และสถานะ Index (ตั้งค่าหากต้องการ)</summary><div class="avsf-term-settings-body"><div class="avsf-form-group"><label>SEO Title</label><input name="metaTitle" data-seo-title maxlength="200" value="'.esc($titleTemplate).'"><small class="avsf-char-count" data-title-count></small></div><div class="avsf-form-group"><label>Meta Description</label><textarea name="metaDesc" data-seo-description maxlength="300" rows="3">'.esc($descTemplate).'</textarea><small class="avsf-char-count" data-desc-count></small></div><div class="avsf-token-picker"><span>ตัวแปรของ'.$label.':</span>'.$tokenButtons.'</div><div class="avsf-google-preview"><div class="avsf-google-preview-label">ตัวอย่าง Google · ใช้ชื่อ/จำนวนที่กำลังกรอก</div><div class="avsf-google-preview-title" data-seo-preview-title>ตัวอย่างชื่อหน้าเว็บไซต์</div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/'.$type.'/').'</div><div class="avsf-google-preview-desc" data-seo-preview-desc>ตัวอย่างคำอธิบายหน้าเว็บไซต์</div><div class="avsf-google-preview-status" data-seo-preview-status>Google อาจเปลี่ยนข้อความและความยาวตามอุปกรณ์และคำค้นหา</div></div><div class="avsf-form-group"><label>Indexing</label><select name="indexable"><option value="inherit">ตามค่าเริ่มต้น</option><option value="index">Index</option><option value="noindex">Noindex</option></select></div></div></details><button class="avsf-btn avsf-btn-primary" type="submit">เพิ่ม'.$label.'</button></form></section>';
        return '<header class="avsf-tax-page-heading"><div><h1>'.($type==='category'?'📂':'🎬').' จัดการ'.$label.'</h1><p>แต่ละรายการแสดงชื่อ Slug และจำนวนโพสต์ในแถวเดียว กดจำนวนเพื่อดูโพสต์ กดจัดการเพื่อแก้รายละเอียด SEO และ Indexing</p></div><span class="avsf-tax-total">'.number_format(count($items)).' รายการ</span></header>'
            .$addForm
            .'<div class="avsf-tax-list-toolbar"><form id="'.$bulkId.'" method="post" onsubmit="return confirm(\'ยืนยันลบรายการที่เลือก? โพสต์จะยังคงอยู่\')"><input type="hidden" name="action" value="bulk_delete_taxonomies"><input type="hidden" name="tax_type" value="'.esc($type).'"> <button type="button" class="avsf-btn avsf-btn-ghost avsf-btn-sm" data-taxonomy-select-all="'.$bulkId.'">เลือกทั้งหมด</button> <button type="submit" class="avsf-btn avsf-btn-danger avsf-btn-sm">ลบรายการที่เลือก</button></form></div>'
            .'<section class="avsf-tax-list">'.($rows!==''?$rows:'<div class="avsf-card avsf-empty-state">ยังไม่มี'.$label.'ในระบบ</div>').'</section>';
    }

    if ($page==='media') {

        $imgs=[];$mediaMetadata=json_decode(setting('media_metadata_v1','{}'),true);

        if(!is_array($mediaMetadata))$mediaMetadata=[];

        $addMedia=function($src,$from,$type)use(&$imgs,$mediaMetadata) {

            if(!is_string($src)||trim($src)==='')return;

            if(!isset($imgs[$src]))$imgs[$src]=['src'=>$src,'from'=>[],'type'=>$type,'title'=>'','alt'=>'','description'=>'','uploaded'=>'','deletable'=>false];

            if(!in_array($from,$imgs[$src]['from'],true))$imgs[$src]['from'][]=$from;

            if($type=== __('อัปโหลด'))$imgs[$src]['deletable']=true;

            if(isset($mediaMetadata[$src])&&is_array($mediaMetadata[$src])) {

                foreach(['title','alt','description'] as $field)if(isset($mediaMetadata[$src][$field])&&is_string($mediaMetadata[$src][$field]))$imgs[$src][$field]=$mediaMetadata[$src][$field];

            }

        };

        $uploadDirectory=AVSF_UPLOAD_DIR;

        if (is_dir($uploadDirectory)) {

            $mediaScan=[];
            try {
                $mediaIterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDirectory, FilesystemIterator::SKIP_DOTS));
                foreach($mediaIterator as $mediaFile){ if($mediaFile->isFile() && !$mediaFile->isLink()) $mediaScan[]=$mediaFile->getPathname(); }
            } catch (Throwable $e) { $mediaScan=glob($uploadDirectory.'/*.{jpg,jpeg,png,gif,webp}',GLOB_BRACE)?:[]; }
            $uploadRoot=rtrim(str_replace('\\','/',$uploadDirectory),'/');
            foreach ($mediaScan as $file) {
                $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
                if(!in_array($ext,['jpg','jpeg','png','gif','webp','avif'],true)) continue;
                $full=str_replace('\\','/',$file);
                $relative=ltrim(substr($full,strlen($uploadRoot)),'/');
                if($relative==='' || strpos($relative,'..')!==false) continue;
                $encoded=implode('/', array_map('rawurlencode', explode('/',$relative)));
                $basePath=str_replace('\\','/',dirname(dirname(isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/avsf-admin/index.php')));
                $src=rtrim($basePath,'/').'/uploads/'.$encoded;
                $addMedia($src,'คลังรูปภาพ','อัปโหลด');
                if(strpos($relative,'/')!==false && isset($imgs[$src])) $imgs[$src]['deletable']=false;
                $modified=@filemtime($file);
                $imgs[$src]['uploaded']=$modified?fmtDate(date('Y-m-d H:i:s',$modified)):'';
            }
        }

        foreach($posts as $p){if($p['cover'])$addMedia($p['cover'],$p['title'],'ปก');foreach(json_decode($p['gallery'],true)?:[] as $g)$addMedia($g,$p['title'],'แกลเลอรี่');}

        $banners=[];$res=mysqli_query($conn,"SELECT * FROM banners");while($r=mysqli_fetch_assoc($res))$banners[]=$r;

        foreach($banners as $b)if($b['image'])$addMedia($b['image'],$b['title'],'แบนเนอร์');

        $imgs=array_values($imgs);

        $cards=implode('',array_map(function($im){

            $data=esc(json_encode($im,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

            $fileLabel=$im['title']!==''?$im['title']:basename((string)parse_url($im['src'],PHP_URL_PATH));

            return '<div class="avsf-gallery-item avsf-media-card"><button type="button" class="avsf-media-card-open" data-media="'.$data.'" onclick="openMediaDetails(this)" aria-label="ดูรายละเอียดรูปภาพ '.esc($fileLabel).'"><img src="'.esc($im['src']).'" alt="'.esc($im['alt']).'" loading="lazy" onerror="this.parentElement.parentElement.style.display=\'none\'"></button><span class="avsf-media-card-label">'.esc($im['type']).'</span>'.($im['deletable']?'<span class="avsf-media-card-check"><input class="avsf-media-select-checkbox" type="checkbox" value="'.esc($im['src']).'" aria-label="เลือกรูปนี้"></span>':'').'</div>';

        },$imgs));

        $mediaForm= __('<form id="mediaBulkForm" method="post" class="avsf-card avsf-mb"><input type="hidden" name="action" value="bulk_delete_media"><div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;"><label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="mediaSelectAll" style="width:auto;"> เลือกรูปที่อัปโหลดทั้งหมด</label><span id="mediaSelectedCount" aria-live="polite" style="font-size:12px;color:var(--muted);">เลือกแล้ว 0 รูป</span><button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit">ลบที่เลือก</button></div><small class="avsf-field-help">ลบได้เฉพาะไฟล์ที่อัปโหลดเข้าคลัง และระบบจะข้ามรูปที่ยังถูกอ้างอิงในโพสต์ แบนเนอร์ หน้าเว็บ หรือการตั้งค่า</small></form>');

        $mediaDialog= __('<div class="avsf-media-modal" id="mediaModal" hidden role="dialog" aria-modal="true" aria-labelledby="mediaModalTitle"><div class="avsf-media-modal-panel"><button type="button" class="avsf-media-modal-close" onclick="closeMediaDetails()" aria-label="ปิดรายละเอียด">×</button><h2 id="mediaModalTitle" style="font-size:18px;margin-bottom:12px;">รายละเอียดรูปภาพ</h2><img id="mediaModalImage" class="avsf-media-modal-image" alt=""><p><strong>URL รูปภาพ</strong><code id="mediaModalUrl" class="avsf-media-url"></code></p><p><strong>วันที่อัปโหลด</strong> <span id="mediaModalDate">—</span></p><form method="post"><input type="hidden" name="action" value="save_media_details"><input type="hidden" name="media_source" id="mediaSource"><div class="avsf-form-group"><label for="mediaTitle">ชื่อรูปภาพ</label><input id="mediaTitle" name="media_title" maxlength="200" placeholder="ชื่อที่ใช้เรียกรูปนี้"></div><div class="avsf-form-group"><label for="mediaAlt">Alt Text</label><input id="mediaAlt" name="media_alt" maxlength="300" placeholder="คำอธิบายภาพสำหรับการเข้าถึงและ SEO"></div><div class="avsf-form-group"><label for="mediaDescription">คำอธิบาย</label><textarea id="mediaDescription" name="media_description" maxlength="2000" rows="3" placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับรูปภาพ"></textarea></div><button class="avsf-btn avsf-btn-primary" type="submit">บันทึกรายละเอียด</button></form><form method="post" id="mediaDeleteForm" style="margin-top:10px;"><input type="hidden" name="action" value="delete_media"><input type="hidden" name="media_source" id="mediaDeleteSource"><button class="avsf-btn avsf-btn-danger" type="submit">ลบรูปนี้</button><small class="avsf-field-help">รูปที่ถูกใช้งานอยู่จะไม่ถูกลบ</small></form></div></div>');

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:6px;">🖼️ สื่อ/แกลเลอรี่ทั้งหมด</h1><p style="color:var(--muted);font-size:12px;margin-bottom:16px;">รวมรูปภาพที่ใช้ในระบบ ('.count($imgs).' รูป) · กดรูปเพื่อดู/แก้รายละเอียด</p><div class="avsf-card avsf-mb"><div class="avsf-section-title">เพิ่มรูปเข้า Media Library</div><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_media"><div class="avsf-form-row"><div class="avsf-form-group"><label>เลือกรูปหลายเรื่องพร้อมกัน (JPG, PNG, GIF, WebP) ระบบแบ่งอัปโหลดและผูกเข้าโพสต์ตาม Code</label><input id="avsfMediaFiles" type="file" name="mediaUpload" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple></div><div class="avsf-form-group" style="display:flex;align-items:flex-end;"><button class="avsf-btn avsf-btn-primary" type="submit">⬆️ อัปโหลดรูป</button></div></div></form></div><script>(function(){var form=document.getElementById("avsfMediaFiles");if(!form)return;form=form.form;if(!form)return;form.addEventListener("submit",function(ev){if(!window.FormData||!window.XMLHttpRequest)return;var input=document.getElementById("avsfMediaFiles");var files=input&&input.files?Array.prototype.slice.call(input.files):[];if(!files.length)return;ev.preventDefault();var csrf=form.querySelector("[name=csrf_token]");var button=form.querySelector("button[type=submit]");var old=button?button.textContent:"";if(button){button.disabled=true;}var chunkSize=8,chunks=[],cur=[];files.forEach(function(file){if(cur.length>=chunkSize){chunks.push(cur);cur=[];}cur.push(file);});if(cur.length)chunks.push(cur);var saved=0,linked=0,posts=0,errors=[];function base(file){return String(file.name||"image").split(/[\\/]/).pop();}function send(i){if(i>=chunks.length){alert("อัปโหลดเสร็จ บันทึก "+saved+" รูป, ผูกเข้า "+posts+" เรื่อง ("+linked+" ไฟล์)"+(errors.length?"\n"+errors.slice(0,4).join("\n"):""));window.location.href="index.php?page=media";return;}var data=new FormData();if(csrf)data.append("csrf_token",csrf.value);chunks[i].forEach(function(file){data.append("library_images[]",file,base(file));});if(button)button.textContent="กำลังอัปโหลด "+(i+1)+"/"+chunks.length;var xhr=new XMLHttpRequest();xhr.open("POST","import.php?library_chunk=1",true);xhr.onload=function(){var body=null;try{body=JSON.parse(xhr.responseText||"{}");}catch(e){body=null;}if(xhr.status<200||xhr.status>=300||!body||body.error){errors.push((body&&body.message)||"ชุดที่ "+(i+1)+" ไม่สำเร็จ");send(i+1);return;}saved+=Number(body.saved)||0;linked+=Number(body.linked_images)||0;posts+=Number(body.updated_posts)||0;if(body.errors&&body.errors.length)errors=errors.concat(body.errors);send(i+1);};xhr.onerror=function(){errors.push("การเชื่อมต่อขัดข้อง");send(i+1);};xhr.send(data);};send(0);});})();</script>'.

        (count($imgs)?$mediaForm.'<div class="avsf-gallery-grid">'.$cards.'</div>'.$mediaDialog: __('<div class="avsf-card" style="text-align:center;color:var(--muted);padding:40px;">ยังไม่มีรูปภาพ</div>'));

    }

    if ($page==='pages') {

        $pages=[];$res=mysqli_query($conn,"SELECT * FROM pages");while($r=mysqli_fetch_assoc($res))$pages[]=$r;

        $editId=isset($_GET['edit'])?intval($_GET['edit']):0;

        $editPg=$editId?mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM pages WHERE id=$editId")):null;

        $h= __('<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;"><h1 style="font-size:22px;font-weight:900;">📄 จัดการหน้าเว็บ</h1><a class="avsf-btn avsf-btn-primary" href="index.php?page=pages&edit=0">+ เพิ่มหน้าใหม่</a></div>');

        if ($editId!==0 || isset($_GET['edit'])) {

            $pg=$editPg?:['id'=>0,'title'=>'','slug'=>'','content'=> __('<p>เนื้อหา</p>'),'status'=>'publish','metaTitle'=>'','metaDesc'=>''];
            $pageIndexState='inherit';
            if(!empty($pg['id'])) { try { ensureSeoIndexOverridesTable($conn); $pageOverride=mysqli_query($conn,"SELECT indexable FROM seo_index_overrides WHERE entity_type='page' AND entity_id=".(int)$pg['id']." LIMIT 1"); if($pageOverride&&($pageOverrideRow=mysqli_fetch_assoc($pageOverride)))$pageIndexState=((int)$pageOverrideRow['indexable']?'index':'noindex'); } catch(Throwable $pageIndexError) { error_log('AVSF page index override unavailable: '.$pageIndexError->getMessage()); } }
            $pageTitleTemplate=trim((string)setting('seo_meta_page_title','%%title%% %%sep%% %%sitename%%'))?:'%%title%% %%sep%% %%sitename%%';
            $pageDescTemplate=trim((string)setting('seo_meta_page_desc','%%excerpt%%'))?:'%%excerpt%%';
            $pageMetaTitle=trim((string)($pg['metaTitle']??''));$pageMetaDesc=trim((string)($pg['metaDesc']??''));
            $pageVars=['title'=>$pg['title'],'name'=>$pg['title'],'term_title'=>$pg['title'],'site_name'=>$siteName??avsfSeoSiteName(setting('seo_site_name',setting('siteName',''))),'site_desc'=>setting('siteDesc',''),'excerpt'=>mb_substr(stripHtml($pg['content']),0,180,'UTF-8'),'primary_keyword'=>setting('seo_primary_keyword','')];
            $pagePreviewTitle=renderSeoTemplate($pageMetaTitle!==''?$pageMetaTitle:$pageTitleTemplate,$pageVars);$pagePreviewDesc=renderSeoTemplate($pageMetaDesc!==''?$pageMetaDesc:$pageDescTemplate,$pageVars);
            $pageTokens=['%%title%%','%%name%%','%%term_title%%','%%sitename%%','%%sitedesc%%','%%excerpt%%','%%primary_keyword%%','%%sep%%','%%page%%','%%currentyear%%'];$pageTokenHtml='';foreach($pageTokens as $pageToken)$pageTokenHtml.='<button type="button" class="avsf-token-chip" data-insert-token="'.esc($pageToken).'">'.esc($pageToken).'</button>';
            $h.='<div class="avsf-card avsf-mb"><h3 style="font-size:15px;font-weight:700;margin-bottom:14px;">'.($pg['id']?'แก้ไขหน้า: '.esc($pg['title']): __('เพิ่มหน้าใหม่')).'</h3><form method="post" data-seo-editor data-term-type="page" data-term-name="'.esc($pg['title']).'" data-sitename="'.esc(avsfSeoSiteName(setting('seo_site_name',setting('siteName','')))).'" data-sitedesc="'.esc(setting('siteDesc','')).'" data-primary-keyword="'.esc(setting('seo_primary_keyword','')).'" data-separator="'.esc(setting('seo_title_separator','|')).'" data-title-template="'.esc($pageTitleTemplate).'" data-desc-template="'.esc($pageDescTemplate).'"><input type="hidden" name="action" value="save_page"><input type="hidden" name="id" value="'.$pg['id'].'">'.
            '<div class="avsf-form-row"><div class="avsf-form-group"><label>ชื่อหน้า</label><input name="title" value="'.esc($pg['title']).'" data-term-name-input required></div>'.
            '<div class="avsf-form-group"><label>Slug</label><input name="slug" value="'.esc($pg['slug']).'"></div></div>'.
            '<div class="avsf-form-group"><label>เนื้อหา (HTML)</label><textarea name="content" rows="6" data-page-excerpt-source>'.esc($pg['content']).'</textarea></div>'.
            '<details class="avsf-term-settings" open><summary>SEO Title, Meta Description และการจัดทำดัชนี</summary><div class="avsf-term-settings-body"><label class="avsf-seo-custom-toggle"><input type="checkbox" name="use_custom_seo" value="1" data-seo-custom-toggle'.(($pageMetaTitle!==''||$pageMetaDesc!=='')?' checked':'').'> กำหนด SEO เฉพาะหน้านี้ (ไม่ใช้แม่แบบส่วนกลาง)</label><div class="avsf-form-group"><label>SEO Title</label><input name="metaTitle" maxlength="200" value="'.esc($pageMetaTitle!==''?$pageMetaTitle:$pageTitleTemplate).'" data-seo-title'.(($pageMetaTitle!==''||$pageMetaDesc!=='')?'':' disabled').'><small class="avsf-char-count" data-title-count></small></div><div class="avsf-form-group"><label>Meta Description</label><textarea name="metaDesc" maxlength="300" rows="3" data-seo-description'.(($pageMetaTitle!==''||$pageMetaDesc!=='')?'':' disabled').'>'.esc($pageMetaDesc!==''?$pageMetaDesc:$pageDescTemplate).'</textarea><small class="avsf-char-count" data-desc-count></small></div><div class="avsf-token-picker"><span>ตัวแปรที่ใช้ได้กับหน้าเว็บเท่านั้น:</span>'.$pageTokenHtml.'</div><div class="avsf-google-preview"><div class="avsf-google-preview-label">ตัวอย่างผลการค้นหา</div><div class="avsf-google-preview-title" data-seo-preview-title>'.esc($pagePreviewTitle).'</div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/page/'.rawurlencode((string)$pg['slug'])).'</div><div class="avsf-google-preview-desc" data-seo-preview-desc>'.esc($pagePreviewDesc).'</div><div class="avsf-google-preview-status" data-seo-preview-status>เป็นการประมาณการ Google อาจเลือกข้อความและตัดความยาวต่างกันตามอุปกรณ์และคำค้นหา</div></div><div class="avsf-form-group"><label>การจัดทำดัชนี</label><select name="indexable"><option value="inherit"'.($pageIndexState==='inherit'?' selected':'').'>ตามค่าเริ่มต้น</option><option value="index"'.($pageIndexState==='index'?' selected':'').'>Index</option><option value="noindex"'.($pageIndexState==='noindex'?' selected':'').'>Noindex</option></select></div></div></details>'.
            '<div class="avsf-form-group"><label>สถานะ</label><select name="status"><option value="publish"'.($pg['status']==='publish'?' selected':'').'>เผยแพร่</option><option value="draft"'.($pg['status']==='draft'?' selected':'').'>ซ่อน</option></select></div>'.
            '<button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit">💾 บันทึก</button> <a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="index.php?page=pages">ยกเลิก</a></form></div>';

        }

        $h.='<div class="avsf-page-list">'.
        implode('',array_map(function($pg){$publicUrl='../index.php?page=page&amp;slug='.rawurlencode((string)$pg['slug']);return '<article class="avsf-page-list-item"><div class="avsf-page-list-title">'.esc($pg['title']).'</div><div class="avsf-page-list-slug"><span>Slug:</span> <a href="'.$publicUrl.'" target="_blank" rel="noopener">'.esc($pg['slug']).'</a><div class="avsf-page-list-count">'.fmtDate($pg['date']).'</div></div><span class="avsf-post-status '.($pg['status']==='publish'?'avsf-is-published':'avsf-is-draft').'">'.($pg['status']==='publish'? __('เผยแพร่'): __('ซ่อน')).'</span><div class="avsf-page-list-actions"><a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="index.php?page=pages&amp;edit='.(int)$pg['id'].'">✎ แก้ไข / SEO</a><form method="post"><input type="hidden" name="action" value="delete_page"><input type="hidden" name="id" value="'.(int)$pg['id'].'"><button class="avsf-btn avsf-btn-danger avsf-btn-sm" onclick="return confirm(\'ลบหน้านี้?\')">ลบ</button></form></div></article>';},$pages)).
        '</div><p style="font-size:11px;color:var(--muted);margin-top:10px;">หน้าที่เผยแพร่จะแสดงลิงก์ใน Footer ของหน้าบ้านอัตโนมัติ</p>';

        return $h;

    }

    if ($page==='banners') {

        $banners=[];$res=mysqli_query($conn,"SELECT * FROM banners");while($r=mysqli_fetch_assoc($res))$banners[]=$r;

        $editId=isset($_GET['edit'])?intval($_GET['edit']):0;

        $editB=$editId?mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM banners WHERE id=$editId")):null;

        $h= __('<h1 style="font-size:22px;font-weight:900;margin-bottom:6px;">📢 แบนเนอร์ &amp; ประกาศ</h1><p style="color:var(--muted);font-size:12px;margin-bottom:16px;">จัดการแบนเนอร์หน้าแรก ประกาศ และโฆษณารอบเครื่องเล่นวิดีโอ</p>');

        if ($editId!==0 || isset($_GET['edit'])) {

            $b=$editB?:['id'=>0,'title'=>'','link'=>'index.php','image'=>'','text'=>'','active'=>1];

            $h.='<div class="avsf-card avsf-mb"><h3 style="font-size:15px;font-weight:700;margin-bottom:14px;">'.($b['id']? __('แก้ไขแบนเนอร์'): __('เพิ่มแบนเนอร์ใหม่')).'</h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_banner"><input type="hidden" name="id" value="'.$b['id'].'">'.

            '<div class="avsf-form-row"><div class="avsf-form-group"><label>ชื่อแบนเนอร์</label><input name="title" value="'.esc($b['title']).'"></div>'.

            '<div class="avsf-form-group"><label>ลิงก์ (คลิกแล้วไปไหน)</label><input name="link" value="'.esc($b['link']).'"></div></div>'.

            '<div class="avsf-form-row"><div class="avsf-form-group"><label>รูปภาพ: ใส่ลิงก์หรือเลือกไฟล์</label><input name="image" value="'.esc($b['image']).'" placeholder="https://..."><input type="file" name="bannerImageUpload" accept="image/jpeg,image/png,image/gif,image/webp" style="margin-top:8px;"><small style="color:var(--muted);font-size:10px;">JPG, PNG, GIF, WebP · ไม่เกิน 5 MB</small></div>'.

            '<div class="avsf-form-group"><label>ข้อความรอง</label><input name="text" value="'.esc($b['text']).'"></div></div>'.

            '<div class="avsf-toggle-row"><span>แสดงแบนเนอร์นี้</span><label class="avsf-switch"><input type="checkbox" name="active" '.($b['active']?'checked':'').'><span class="avsf-slider"></span></label></div>'.

            '<button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit" style="margin-top:12px;">💾 บันทึก</button> <a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="index.php?page=banners">ยกเลิก</a></form></div>';

        }

        $h.='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;"><h2 style="font-size:16px;font-weight:700;">รายการแบนเนอร์</h2><a class="avsf-btn avsf-btn-primary avsf-btn-sm" href="index.php?page=banners&edit=0">+ เพิ่มแบนเนอร์</a></div>'.

        '<div class="avsf-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;">'.

        implode('',array_map(function($b){return '<div class="avsf-card" style="padding:14px;"><div style="font-weight:700;font-size:13px;margin-bottom:4px;">'.esc($b['title']).'</div><div style="font-size:11px;color:var(--muted);margin-bottom:8px;">'.esc($b['text']).'</div><span class="avsf-post-status '.($b['active']?'avsf-is-published':'avsf-is-draft').'">'.($b['active']? __('แสดง'): __('ซ่อน')).'</span><div style="display:flex;gap:6px;margin-top:10px;"><a class="avsf-btn avsf-btn-ghost avsf-btn-sm" style="flex:1;" href="index.php?page=banners&edit='.$b['id'].'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg> แก้ไข</a><form method="post" style="display:inline;"><input type="hidden" name="action" value="delete_banner"><input type="hidden" name="id" value="'.$b['id'].'"><button class="avsf-btn avsf-btn-danger avsf-btn-sm" onclick="return confirm(\'ลบแบนเนอร์นี้?\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg> ลบ</button></form></div></div>';},$banners)).'</div>';

        $h.=videoAdSettingsHtml();

        return $h;

    }

    if ($page==='comments') {

        $cmts=[];$res=mysqli_query($conn,"SELECT * FROM comments ORDER BY id DESC");while($r=mysqli_fetch_assoc($res))$cmts[]=$r;

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:6px;">💬 จัดการคอมเมนต์</h1><p style="color:var(--muted);font-size:12px;margin-bottom:16px;">คอมเมนต์ที่ผู้ใช้ส่งจะต้องอนุมัติก่อนแสดงบนหน้าบ้าน (รออนุมัติ '.$pendingCmts.' รายการ)</p>'.

        '<div class="avsf-card"><div style="overflow-x:auto;"><table><thead><tr><th>ผู้ส่ง</th><th>คอมเมนต์</th><th>โพสต์</th><th>วันที่</th><th>สถานะ</th><th style="width:160px;">จัดการ</th></tr></thead><tbody>'.

        implode('',array_map(function($c){$p=mysqli_fetch_assoc(mysqli_query($GLOBALS['conn'],"SELECT title FROM posts WHERE id=".$c['postId']));return '<tr><td style="font-weight:600;font-size:12px;">'.esc($c['name']).'</td><td style="max-width:200px;font-size:12px;">'.esc($c['text']).'</td><td style="font-size:11px;color:var(--muted);">'.esc($p?$p['title']:'-').'</td><td>'.fmtDate($c['date']).'</td><td><span class="avsf-post-status '.($c['approved']?'avsf-is-published':'avsf-is-pending').'">'.($c['approved']? __('อนุมัติแล้ว'): __('รออนุมัติ')).'</span></td><td><form method="post" style="display:inline;"><input type="hidden" name="action" value="toggle_cmt"><input type="hidden" name="id" value="'.$c['id'].'"><button class="avsf-btn '.($c['approved']?'avsf-btn-ghost':'avsf-btn-primary').' avsf-btn-sm" style="margin-right:4px;">'.($c['approved']? __('ซ่อน'): __('อนุมัติ')).'</button></form><form method="post" style="display:inline;"><input type="hidden" name="action" value="delete_cmt"><input type="hidden" name="id" value="'.$c['id'].'"><button class="avsf-btn avsf-btn-danger avsf-btn-sm" onclick="return confirm(\'ลบคอมเมนต์นี้?\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg> ลบ</button></form></td></tr>';},$cmts)).

        '</tbody></table></div></div>';

    }

    if ($page==='users') {

        if (!can('users.manage')) {

            http_response_code(403);

            return '<h1>⛔ ไม่มีสิทธิ์เข้าถึง</h1><p>การจัดการผู้ใช้เปิดให้เฉพาะ Super Admin</p>';

        }

        header('Location: users.php');

        exit;

    }

    if ($page==='reports') {

        $topPosts=array_slice(array_values(array_sort($posts,'views')),0,10);

        $studioViews=[];foreach($studios as $s)$studioViews[$s['id']]=['name'=>$s['name'],'views'=>0,'count'=>0];

        foreach(publishedPosts() as $p)if(isset($studioViews[$p['studioId']])){$studioViews[$p['studioId']]['views']+=$p['views'];$studioViews[$p['studioId']]['count']++;}

        $actorViews=[];foreach($actors as $a)$actorViews[$a['id']]=['name'=>$a['name'],'views'=>0];

        foreach(publishedPosts() as $p)foreach(json_decode($p['actorIds'],true)?:[] as $aid)if(isset($actorViews[$aid]))$actorViews[$aid]['views']+=$p['views'];

        uasort($actorViews,function($a,$b){return $b['views']-$a['views'];});

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:20px;">📈 รายงาน &amp; สถิติ</h1>'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">🏆 โพสต์ยอดนิยม 10 อันดับ</div><div style="overflow-x:auto;"><table><thead><tr><th>อันดับ</th><th>ชื่อโพสต์</th><th>ค่าย</th><th>ยอดวิว</th></tr></thead><tbody>'.

        implode('',array_map(function($p,$i){$rc=$i===0?'var(--gold)':($i===1?'var(--muted)':($i===2?'#cd7f32':'var(--muted)'));return '<tr><td><span style="font-weight:900;color:'.$rc.';font-size:16px;">'.($i+1).'</span></td><td style="font-weight:600;">'.esc($p['title']).'</td><td>'.esc(getStudio($p['studioId'])['name']).'</td><td style="color:var(--accent);font-weight:700;">'.number_format($p['views']).'</td></tr>';},$topPosts,array_keys($topPosts))).

        '</tbody></table></div></div>'.

        '<div class="avsf-grid avsf-reports-taxonomy-grid" style="grid-template-columns:1fr 1fr;gap:12px;">'.

        '<div class="avsf-card"><div class="avsf-section-title">🎬 ยอดวิวแยกตามค่าย</div><table><thead><tr><th>ค่าย</th><th>จำนวนโพสต์</th><th>ยอดวิว</th></tr></thead><tbody>'.

        implode('',array_map(function($s){return '<tr><td>'.esc($s['name']).'</td><td>'.$s['count'].'</td><td style="color:var(--accent);font-weight:700;">'.number_format($s['views']).'</td></tr>';},$studioViews)).'</tbody></table></div>'.

        '<div class="avsf-card"><div class="avsf-section-title">👤 ยอดวิวแยกตามนักแสดง</div><table><thead><tr><th>นักแสดง</th><th>ยอดวิวรวม</th></tr></thead><tbody>'.

        implode('',array_map(function($a){return '<tr><td>'.esc($a['name']).'</td><td style="color:var(--accent2);font-weight:700;">'.number_format($a['views']).'</td></tr>';},$actorViews)).'</tbody></table></div></div>';

    }

    if ($page==='site-content') {

        $categoryOptions=[];$categoryResult=mysqli_query($conn,"SELECT slug,name FROM categories ORDER BY name");

        while($categoryResult&&($categoryRow=mysqli_fetch_assoc($categoryResult)))$categoryOptions[$categoryRow['slug']]=$categoryRow['name'];

        $pageOptions=[];$pageResult=mysqli_query($conn,"SELECT slug,title FROM pages WHERE status='publish' ORDER BY title");

        while($pageResult&&($pageRow=mysqli_fetch_assoc($pageResult)))$pageOptions[$pageRow['slug']]=$pageRow['title'];

        $menuTypes=[

            'home'=> __('หน้าหลัก'),'all'=> __('เรื่องทั้งหมด'),'categories'=> __('หน้ารวมหมวดหมู่'),

            'studio_list'=> __('รายชื่อค่าย'),'actor_list'=> __('รายชื่อนักแสดง'),'tag_list'=>'รายการแท็ก'

        ];

        $menuGroups=[

            'header'=>['label'=> __('เมนูหลักบน Header'),'count'=>6,'defaults'=>[

                ['label'=> __('นักแสดง'),'type'=>'actor_list','value'=>''],['label'=> __('ค่าย'),'type'=>'studio_list','value'=>''],

                ['label'=> __('รหัสเรื่อง'),'type'=>'all','value'=>''],['label'=> __('บทความ'),'type'=>'categories','value'=>'']

            ]],

            'explore'=>['label'=> __('แถบสำรวจเนื้อหา'),'count'=>6,'defaults'=>[

                ['label'=> __('เรื่องทั้งหมด'),'type'=>'all','value'=>''],['label'=> __('หมวดหมู่'),'type'=>'categories','value'=>'']

            ]],

            'bottom'=>['label'=> __('เมนูล่าง'),'count'=>4,'defaults'=>array_map(function($index,$label){$types=['home','studio_list','actor_list','tag_list'];return ['label'=>$label,'type'=>$types[$index],'value'=>''];},[0,1,2,3],json_decode(setting('theme_navLabels','["หน้าหลัก","ค่าย","นักแสดง","แท็ก"]'),true)?:['หน้าหลัก','ค่าย','นักแสดง','แท็ก'])]

        ];

        $menuHtml='';

        foreach($menuGroups as $menuName=>$group) {

            $stored=json_decode(setting('site_menu_'.$menuName,''),true);

            if(!is_array($stored))$stored=$group['defaults'];

            $menuHtml.='<div class="avsf-card avsf-mb"><div class="avsf-section-title">'.esc($group['label']).'</div><p style="font-size:11px;color:var(--muted);margin-bottom:10px;">เว้นรายการว่างเพื่อไม่แสดงเมนู · เลือกหมวดหมู่ ค่าย นักแสดง แท็ก หรือหน้าที่เผยแพร่เพื่อเชื่อมไปยังรายการนั้น</p>';

            for($slot=0;$slot<$group['count'];$slot++) {

                $item=isset($stored[$slot])&&is_array($stored[$slot])?$stored[$slot]:[];

                $label=isset($item['label'])?$item['label']:'';

                $type=isset($item['type'])?$item['type']:'';

                $value=isset($item['value'])?$item['value']:'';

                $selection=$type;

                if(in_array($type,['category','tag','studio','actor','page'],true))$selection=$type.':'.$value;

                $destinationName='menu_'.$menuName.'_destination[]';

                $labelName='menu_'.$menuName.'_label[]';

                $customName='menu_'.$menuName.'_custom[]';

                $options= __('<option value="">— ไม่ใช้เมนูนี้ —</option>');

                foreach($menuTypes as $menuType=>$menuLabel)$options.='<option value="'.esc($menuType).'"'.($selection===$menuType?' selected':'').'>'.esc($menuLabel).'</option>';

                foreach($categoryOptions as $categorySlug=>$categoryName)$options.='<option value="category:'.esc($categorySlug).'"'.($selection==='category:'.$categorySlug?' selected':'').'>หมวดหมู่: '.esc($categoryName).'</option>';

                foreach($tags as $tag)$options.='<option value="tag:'.esc($tag['slug']).'"'.($selection==='tag:'.$tag['slug']?' selected':'').'>แท็ก: '.esc($tag['name']).'</option>';

                foreach($studios as $studio)$options.='<option value="studio:'.esc($studio['slug']).'"'.($selection==='studio:'.$studio['slug']?' selected':'').'>ค่าย: '.esc($studio['name']).'</option>';

                foreach($actors as $actor)$options.='<option value="actor:'.esc($actor['slug']).'"'.($selection==='actor:'.$actor['slug']?' selected':'').'>นักแสดง: '.esc($actor['name']).'</option>';

                foreach($pageOptions as $pageSlug=>$pageTitle)$options.='<option value="page:'.esc($pageSlug).'"'.($selection==='page:'.$pageSlug?' selected':'').'>หน้าเว็บ: '.esc($pageTitle).'</option>';

                $options.='<option value="custom"'.($selection==='custom'?' selected':'').'>Custom URL</option>';

                $customUrl=$type==='custom'?$value:'';

                $menuHtml.='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:8px;padding:10px 0;border-top:1px solid var(--border);align-items:end;"><div class="avsf-form-group" style="margin:0;"><label>ชื่อเมนู '.($slot+1).'</label><input name="'.esc($labelName).'" value="'.esc($label).'" maxlength="60" placeholder="เช่น หนังใหม่"></div><div class="avsf-form-group" style="margin:0;"><label>ปลายทาง</label><select name="'.esc($destinationName).'">'.$options.'</select></div><div class="avsf-form-group" style="margin:0;"><label>URL (ใช้เมื่อเลือก Custom)</label><input name="'.esc($customName).'" value="'.esc($customUrl).'" placeholder="https://example.com หรือ index.php?page=all"></div></div>';

            }

            $menuHtml.='</div>';

        }

        $contentFields=[

            ['homeFeaturedTitle','site_home_featured_title','หัวข้อการ์ดสไลด์แนะนำ','แนะนำ'],

            ['homeFeaturedAllLabel','site_home_featured_all_label','ข้อความปุ่มดูทั้งหมด','ดูทั้งหมด'],

            ['homeLatestTitle','site_home_latest_title','หัวข้อรายการล่าสุดหน้าแรก','โพสต์ล่าสุด'],

            ['bannerAccessibility','site_banner_accessibility','ชื่อส่วนสไลด์แบนเนอร์','บทความรหัสหนังใหม่'],

            ['siteAdLabel','site_ad_label','ป้ายกำกับโฆษณา','โฆษณา'],

            ['siteAdCloseLabel','site_ad_close_label','ข้อความปุ่มปิดโฆษณาในวิดีโอ','ปิดโฆษณา'],

            ['siteSearchPlaceholder','site_search_placeholder','ข้อความตัวอย่างช่องค้นหา','ค้นหา...'],

            ['siteSearchButton','site_search_button','ข้อความปุ่มค้นหา','ค้นหา']

        ];

        $contentInputs='';

        foreach($contentFields as $field)$contentInputs.='<div class="avsf-form-group"><label>'.esc($field[2]).'</label><input name="'.esc($field[0]).'" maxlength="120" value="'.esc(setting($field[1],$field[3])).'"></div>';

        $featuredLimit=(int)setting('site_home_featured_limit','12');

        if(!in_array($featuredLimit,[4,6,8,10,12,16,20,24],true))$featuredLimit=12;

        $bannerInterval=(int)setting('site_banner_interval_seconds','3');

        if($bannerInterval<3||$bannerInterval>30)$bannerInterval=3;

        $featuredInterval=(int)setting('site_home_featured_interval_seconds','6');

        if($featuredInterval<3||$featuredInterval>30)$featuredInterval=6;

        $selectField=function($name,$label,$value,$options) {

            $html='<div class="avsf-form-group"><label>'.esc($label).'</label><select name="'.esc($name).'">';

            foreach($options as $optionValue=>$optionLabel)$html.='<option value="'.esc((string)$optionValue).'"'.((string)$optionValue===(string)$value?' selected':'').'>'.esc($optionLabel).'</option>';

            return $html.'</select></div>';

        };

        $contentInputs.='<div class="avsf-form-row">'.$selectField('homeFeaturedLimit','จำนวนการ์ดในสไลด์แนะนำ',$featuredLimit,[4=>'4',6=>'6',8=>'8',10=>'10',12=>'12',16=>'16',20=>'20',24=>'24']).$selectField('bannerInterval','เวลาสลับแบนเนอร์ (วินาที)',$bannerInterval,array_combine(range(3,15),range(3,15))).'</div>';

        $contentInputs.='<div class="avsf-form-row">'.$selectField('featuredInterval','เวลาสไลด์การ์ดแนะนำ (วินาที)',$featuredInterval,array_combine(range(3,30),range(3,30))).'</div>';

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:8px;">🧩 เนื้อหาเว็บ &amp; เมนู</h1><p style="color:var(--muted);font-size:12px;margin-bottom:18px;">ปรับข้อความหน้าแรก เมนู และป้ายโฆษณาได้ที่นี่ แยกจากการตั้งค่า SEO หลักของเว็บไซต์ · ตั้งค่าลิงก์และภาพโฆษณาที่ <a href="index.php?page=settings#video-ad-settings" style="color:var(--accent);">ตั้งค่าระบบ → โฆษณารอบวิดีโอ</a></p>'.

        '<form method="post"><input type="hidden" name="action" value="save_site_content">'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">หัวข้อและป้ายบนหน้าเว็บ</div>'.$contentInputs.'</div>'.

        $menuHtml.

        '<button class="avsf-btn avsf-btn-primary" type="submit">💾 บันทึกเนื้อหาและเมนู</button> <a class="avsf-btn avsf-btn-ghost" href="../index.php" target="_blank" rel="noopener">👁 ดูหน้าบ้าน</a></form>';

    }

    if ($page==='theme') {
        $toggles = [
            'showViews'=> __('แสดงยอดวิว (Views)'), 'showStudio'=> __('แสดงชื่อค่ายบนการ์ด'),
            'showTags'=> __('แสดงแท็กบนการ์ด'), 'showActors'=> __('แสดงนักแสดงบนการ์ด'),
            'showDuration'=> __('แสดงความยาววิดีโอบนการ์ด'), 'showBottomNav'=> __('แสดงเมนูล่าง (Bottom Nav)'),
            'showSearch'=> __('แสดงแถบค้นหาบน Header'), 'showRelated'=> __('แสดงโพสต์ที่เกี่ยวข้องใต้โพสต์'),
            'showBanners'=> __('แสดงแบนเนอร์ Slideshow บนหน้าแรก'), 'showAnnouncement'=>'แสดงแถบประกาศเลื่อนบนสุด'
        ];
        $colorFields = [
            ['themeBgColor','สีพื้นหลังเว็บไซต์','theme_bg_color','#0a0a0f'],
            ['themeTextColor','สีข้อความหลัก','theme_text_color','#ffffff'],
            ['themeMutedColor','สีข้อความรอง','theme_muted_color','#8b8b9a'],
            ['themeAccent2Color','สี Accent รอง','theme_accent2_color','#a855f7'],
            ['themeLinkColor','สีลิงก์เว็บไซต์','theme_link_color','#ff79a8'],
            ['themeButtonBg','สีพื้นปุ่มหลัก','theme_button_bg',setting('theme_accent','#ff2d78')],
            ['themeButtonText','สีข้อความบนปุ่ม','theme_button_text','#ffffff'],
            ['themeCardBg','สีพื้นการ์ดหลัก','theme_card_bg','#14141c'],
            ['themeCard2Bg','สีพื้นการ์ดรอง','theme_card2_bg','#1a1a24'],
            ['themeBorderColor','สีเส้นขอบและเส้นแบ่ง','theme_border_color','#343443'],
            ['themeMenuBg','สีพื้นหลังเมนู','theme_menu_bg','#101018'],
            ['themeMenuText','สีข้อความเมนู','theme_menu_text','#b5b5c5'],
            ['themeMenuActiveBg','สีพื้นเมนูที่เลือก','theme_menu_active_bg','#321426'],
            ['themeMenuActiveText','สีข้อความเมนูที่เลือก','theme_menu_active_text','#ff70a4']
        ];
        $colorHtml = '';
        foreach ($colorFields as $field) {
            $value = setting($field[2], $field[3]);
            if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$value)) $value = $field[3];
            $colorHtml .= '<label class="avsf-theme-color-field"><span>' . esc($field[1]) . '</span><input type="color" name="' . esc($field[0]) . '" value="' . esc($value) . '"><code>' . esc(strtoupper($value)) . '</code></label>';
        }
        $radius = (int)setting('theme_card_radius','14'); if ($radius < 8 || $radius > 24) $radius = 14;
        $shadow = setting('theme_card_shadow','soft'); if (!in_array($shadow,['none','soft','deep'],true)) $shadow = 'soft';
        $toggleHtml = implode('', array_map(function($key,$label) {
            $on = setting('theme_'.$key,'1') === '1';
            return '<div class="avsf-toggle-row"><span>' . esc($label) . '</span><label class="avsf-switch"><input type="checkbox" name="' . esc($key) . '" ' . ($on?'checked':'') . '><span class="avsf-slider"></span></label></div>';
        }, array_keys($toggles), $toggles));
        return '<style>.avsf-theme-color-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:10px}.avsf-theme-color-field{display:flex;align-items:center;gap:10px;min-width:0;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--card2)}.avsf-theme-color-field span{flex:1;min-width:0;font-size:12px}.avsf-theme-color-field input[type=color]{width:48px;height:40px;flex:none;padding:3px}.avsf-theme-color-field code{font-size:10px;color:var(--muted)}.avsf-theme-style-layout{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:12px}.avsf-theme-style-layout .avsf-form-group{margin:0}</style>' .
            '<h1 style="font-size:22px;font-weight:900;margin-bottom:8px;">🎨 จัดการสไตล์ &amp; เมนูหน้าเว็บ</h1><p style="color:var(--muted);font-size:12px;margin-bottom:18px;line-height:1.8;">ปรับโลโก้ สีลิงก์ สีปุ่ม สีการ์ด เงา มุมโค้ง และเมนูให้ตรงกับหน้าบ้าน โดยค่าที่บันทึกจะถูกนำไปใช้กับ CSS ของเว็บไซต์จริง</p>' .
            '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_theme">' .
            '<div class="avsf-card avsf-mb"><div class="avsf-section-title">ข้อมูลแบรนด์และโลโก้จริง</div>' .
            (trim((string)setting('siteLogo',''))!=='' && validImageReference(setting('siteLogo','')) ? '<div style="margin:8px 0 12px;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--card2)"><img src="' . esc(setting('siteLogo','')) . '" alt="ตัวอย่างโลโก้" style="display:block;max-width:220px;max-height:70px;object-fit:contain"></div>' : '') .
            '<div class="avsf-form-group"><label>URL โลโก้เว็บไซต์ (ถ้ามี)</label><input name="siteLogo" value="' . esc(setting('siteLogo','')) . '" placeholder="https://example.com/logo.png หรือ /uploads/logo.png"><small class="avsf-field-help">ช่องนี้ใช้ร่วมกับโลโก้หน้าบ้าน ท้ายเว็บ และหลังบ้าน ไม่สร้างโลโก้ซ้ำคนละชุด</small></div>' .
            '<div class="avsf-form-group"><label>หรืออัปโหลดไฟล์โลโก้จริง</label><input type="file" name="themeSiteLogoUpload" accept="image/jpeg,image/png,image/gif,image/webp"><small class="avsf-field-help">รองรับ JPG, PNG, GIF และ WebP ขนาดไม่เกิน 5 MB · เลือกไฟล์ใหม่เพื่อแทนที่โลโก้ปัจจุบัน</small></div>' .
            '<div class="avsf-form-group"><label>ข้อความชื่อเว็บสำรอง (แสดงเมื่อไม่มีโลโก้และไม่มีชื่อเว็บตั้งไว้)</label><input name="logoText" value="' . esc(setting('logoText','')) . '" maxlength="120"><small class="avsf-field-help">หากมีชื่อเว็บไซต์ใน ตั้งค่าระบบ → ทั่วไป ระบบจะใช้ชื่อเว็บไซต์นั้นเป็นข้อความสำรอง</small></div>' .
            '<div class="avsf-theme-style-layout"><div class="avsf-form-group"><label>สี Accent หน้าเว็บ</label><input type="color" name="accent" value="' . esc(setting('theme_accent','#ff2d78')) . '"></div><div class="avsf-form-group"><label>สี Accent หลังบ้าน</label><input type="color" name="adminAccent" value="' . esc(setting('admin_accent','#267cf4')) . '"><small class="avsf-field-help">เปลี่ยนสีปุ่มและเมนูหลังบ้านเท่านั้น</small></div></div></div>' .
            '<div class="avsf-card avsf-mb"><div class="avsf-section-title">สีของหน้าเว็บไซต์</div><div class="avsf-theme-color-grid">' . $colorHtml . '</div></div>' .
            '<div class="avsf-card avsf-mb"><div class="avsf-section-title">รูปแบบการ์ดและเงา</div><div class="avsf-theme-style-layout"><div class="avsf-form-group"><label>ความโค้งมุมการ์ด: ' . $radius . ' px</label><input type="range" name="themeCardRadius" min="8" max="24" step="1" value="' . $radius . '" oninput="this.previousElementSibling.textContent=\'ความโค้งมุมการ์ด: \'+this.value+\' px\'"><small class="avsf-field-help">ใช้กับการ์ดโพสต์ การ์ดหมวดหมู่ การ์ดแนะนำ และกล่องเนื้อหาหลัก</small></div><div class="avsf-form-group"><label>ระดับเงาการ์ด</label><select name="themeCardShadow"><option value="none" ' . ($shadow==='none'?'selected':'') . '>ไม่มีเงา</option><option value="soft" ' . ($shadow==='soft'?'selected':'') . '>เงานุ่ม</option><option value="deep" ' . ($shadow==='deep'?'selected':'') . '>เงาชัด</option></select></div></div></div>' .
            '<div class="avsf-card avsf-mb"><div class="avsf-section-title">เปิด / ปิดไอเทมบนหน้าเว็บ</div>' . $toggleHtml . '</div>' .
            '<div class="avsf-card avsf-mb"><div class="avsf-section-title">ตั้งค่าเมนูและรายการเมนู</div><p style="color:var(--muted);font-size:12px;line-height:1.8;">ตั้งค่าชื่อและปลายทางของเมนูที่ <a href="index.php?page=site-content" style="color:var(--accent);">เนื้อหาเว็บ &amp; เมนู</a> · ปรับสีเมนูได้ในส่วนสีของหน้าเว็บไซต์ด้านบน</p></div>' .
            '<button class="avsf-btn avsf-btn-primary" type="submit">💾 บันทึกสไตล์ทั้งหมด</button> <a class="avsf-btn avsf-btn-ghost" href="../index.php" target="_blank" rel="noopener">👁 ดูหน้าบ้าน</a></form>';
    }

    if ($page==='seo-public') {

        $publicTemplates=[

            ['home','หน้าแรก','%%sitename%%','%%sitedesc%%','%%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%post_count%%, %%sep%%, %%currentyear%%'],

            ['all','เรื่องทั้งหมด','เรื่องทั้งหมด %%sep%% %%sitename%%','รวมเรื่องที่เผยแพร่ทั้งหมด %%post_count%% เรื่องบน %%sitename%%','%%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%post_count%%, %%page%%, %%sep%%'],

            ['categories','หน้ารวมหมวดหมู่','หมวดหมู่ทั้งหมด %%sep%% %%sitename%%','เลือกดูเรื่องที่เผยแพร่ตามหมวดหมู่บน %%sitename%%','%%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%item_count%%, %%sep%%'],

            ['category','หน้า Archive หมวดหมู่','%%cat%% %%sep%% %%sitename%%','รวมเรื่องในหมวด %%cat%% ทั้งหมด %%post_count%% เรื่อง','%%cat%%, %%category%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%'],

            ['studio_list','หน้ารวมค่าย','ค่ายหนังทั้งหมด %%sep%% %%sitename%%','เลือกดูผลงานจากค่ายต่าง ๆ บน %%sitename%%','%%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%item_count%%, %%sep%%'],

            ['actor_list','หน้ารวมนักแสดง','นักแสดงทั้งหมด %%sep%% %%sitename%%','รวมรายชื่อนักแสดงและผลงานบน %%sitename%%','%%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%item_count%%, %%sep%%'],

            ['tag_list','หน้ารวมแท็ก','แท็กทั้งหมด %%sep%% %%sitename%%','เลือกดูเรื่องตามแท็กที่เกี่ยวข้องบน %%sitename%%','%%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%item_count%%, %%sep%%'],

            ['studio','หน้า Archive ค่าย','ผลงานจาก %%studio%% %%sep%% %%sitename%%','รวมผลงานจากค่าย %%studio%% ทั้งหมด %%post_count%% เรื่อง','%%studio%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%'],

            ['actor','หน้า Archive นักแสดง','ผลงานของ %%actor%% %%sep%% %%sitename%%','รวมผลงานของ %%actor%% ทั้งหมด %%post_count%% เรื่อง','%%actor%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%'],

            ['tag','หน้า Archive แท็ก','เรื่องที่ติดแท็ก %%tag%% %%sep%% %%sitename%%','รวมเรื่องที่ติดแท็ก %%tag%% ทั้งหมด %%post_count%% เรื่อง','%%tag%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%']

        ];

        $publicTemplateHtml='';

        foreach($publicTemplates as $template) {

            $key=$template[0];$fieldPrefix='seo_public_'.$key;

            $publicTemplateHtml.='<section class="avsf-card avsf-mb"><div class="avsf-section-title">'.esc($template[1]).'</div><div class="avsf-form-group"><label for="'.$fieldPrefix.'_title">หัวข้อหน้า (H1) ที่ผู้ชมเห็น</label><input id="'.$fieldPrefix.'_title" name="'.$fieldPrefix.'_title" maxlength="200" value="'.esc(setting($fieldPrefix.'_title','')).'" placeholder="'.esc($template[2]).'"><small class="avsf-field-help">แสดงเป็นหัวข้อหลักในหน้าเว็บ ไม่ใช่ Meta Title สำหรับผลการค้นหา</small></div><div class="avsf-form-group"><label for="'.$fieldPrefix.'_description">คำอธิบาย/เนื้อหาที่ผู้ชมเห็น</label><textarea id="'.$fieldPrefix.'_description" name="'.$fieldPrefix.'_description" rows="3" maxlength="5000" placeholder="'.esc($template[3]).'">'.esc(setting($fieldPrefix.'_description','')).'</textarea><small class="avsf-field-help">ข้อความธรรมดา; เว้นบรรทัดเพื่อแยกย่อหน้า ระบบจะแทนตัวแปรก่อนแสดงผล</small></div><small class="avsf-field-help">ตัวแปรของเทมเพลตนี้: '.esc($template[4]).'</small></section>';

        }

        $position=setting('seo_public_home_position','top');

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:8px;">🔍 ตั้งค่า SEO · SEO ในเว็บให้คนดูเห็น</h1><p style="font-size:12px;color:var(--muted);line-height:1.7;margin-bottom:16px;">กำหนดหัวข้อ H1 และคำอธิบายที่แสดงในเนื้อหาหน้าเว็บ แยกจาก Meta สำหรับเสิร์ชเอนจินอย่างชัดเจน ค่าเทมเพลตจะใช้เมื่อไม่มีข้อความเฉพาะรายการ และแทนตัวแปรให้เป็นชื่อ/จำนวนจริงตามหน้า</p><div class="avsf-card avsf-mb"><div class="avsf-section-title">คู่มือตัวแปรเทมเพลต</div><p style="font-size:11px;color:var(--muted);line-height:1.8;">%%sitename%% / %%site%% ชื่อเว็บไซต์ · %%sitedesc%% คำอธิบายเว็บ · %%primary_keyword%% / %%focus_keyphrase%% คีย์เวิร์ดหลัก · %%cat%% / %%category%% / %%primary_category%% / %%studio%% / %%actor%% / %%tag%% ชื่อหมวดหมู่หรือรายการ · %%title%% / %%term_title%% / %%name%% ชื่อหน้าหรือชื่อรายการ · %%excerpt%% ข้อความสรุป · %%post_count%% จำนวนโพสต์ที่ตรงกับหน้านั้น · %%item_count%% จำนวนรายการในหน้ารวม · %%sep%% ตัวคั่น · %%page%% เลขหน้า · %%currentyear%% / %%year%% ปีปัจจุบัน<br>รองรับชื่อเดิม %%site_name%% และ %%site_desc%% เพื่อไม่ให้เทมเพลตที่บันทึกไว้ก่อนหน้าสูญหาย</p></div><form method="post"><input type="hidden" name="action" value="save_seo_public"><div class="avsf-card avsf-mb"><div class="avsf-section-title">ตำแหน่งข้อความหน้าแรก</div><div class="avsf-form-group"><label>แสดงข้อความนำด้านบนหรือด้านล่างเนื้อหา</label><select name="seo_public_home_position"><option value="top"'.($position==='top'?' selected':'').'>ด้านบน</option><option value="bottom"'.($position==='bottom'?' selected':'').'>ด้านล่าง</option></select></div><small class="avsf-field-help">หากหัวข้อและคำอธิบายหน้าแรกว่าง ระบบจะไม่เพิ่มกล่องข้อความใหม่</small></div>'.$publicTemplateHtml.'<button class="avsf-btn avsf-btn-primary" type="submit">บันทึก SEO ในเว็บ</button></form>';

    }

    if ($page==='seo') {

        $siteBase = rtrim(avsfCanonicalBaseUrl(), '/');

        $allPages=[];$res=mysqli_query($conn,"SELECT * FROM pages ORDER BY title ASC");while($r=mysqli_fetch_assoc($res))$allPages[]=$r;

        $publishedPages=array_values(array_filter($allPages,function($pg){return $pg['status']==='publish';}));

        $publishedPosts=array_values(array_filter($posts,function($post){return $post['status']==='publish';}));

        $publishedCount=count($publishedPosts)+count($publishedPages);

        $publishedPostCount=count($publishedPosts);

        $metadataReady=count(array_filter($publishedPosts,function($post){return !empty($post['metaTitle']) && !empty($post['metaDesc']);}));

        $metadataCoverage=$publishedPostCount?round($metadataReady/$publishedPostCount*100):0;

        $videoPosts=array_values(array_filter($publishedPosts,function($post){return !empty($post['videoUrl']);}));

        $videoSchemaReady=count(array_filter($videoPosts,function($post){

            $source=parseVideo($post['videoUrl']);

            $thumbnail=!empty($post['cover'])?$post['cover']:$source['thumb'];

            return $source['error']==='' && ($source['embedUrl']!=='' || $source['contentUrl']!=='') && $thumbnail!=='' && validImageReference($thumbnail);

        }));

        $videoSchemaCoverage=count($videoPosts)?round($videoSchemaReady/count($videoPosts)*100):100;

        $siteTitleValue=trim(setting('seo_meta_home_title',''));

        $siteDescriptionValue=trim(setting('seo_meta_home_desc',''));

        $seoSiteNameValue=trim(setting('seo_site_name',setting('siteName','')));

        $siteTitle=$siteTitleValue!==''?$siteTitleValue:$seoSiteNameValue;

        $siteDescription=$siteDescriptionValue!==''?$siteDescriptionValue:trim(setting('siteDesc',''));

        $robotsValue=trim(setting('seo_robots','index, follow'));

        if(!in_array($robotsValue,['index, follow','index, nofollow','noindex, follow','noindex, nofollow'],true))$robotsValue='index, follow';

        $titleSeparator=trim(setting('seo_title_separator','|'));

        $titleSeparators=['|'=> __('เส้นตั้ง (|)'),'-'=> __('ขีดสั้น (-)'),'–'=> __('ขีดยาว (–)'),'»'=> __('ลูกศร (»)'),'·'=> __('จุดกลาง (·)'),'•'=> __('จุดทึบ (•)'),'/'=> __('เครื่องหมายทับ (/)')];

        if(!isset($titleSeparators[$titleSeparator]))$titleSeparator='|';

        $primaryKeyword=trim(setting('seo_primary_keyword',''));

        $secondaryKeywords=trim(setting('seo_secondary_keywords',''));

        $seoScore=0;

        $seoScore+=($siteTitle!=='' && mb_strlen($siteTitle,'UTF-8')<=60)?15:0;

        $seoScore+=($siteDescription!=='' && mb_strlen($siteDescription,'UTF-8')>=50 && mb_strlen($siteDescription,'UTF-8')<=160)?15:0;

        $seoScore+=$primaryKeyword!==''?15:0;

        $seoScore+=$secondaryKeywords!==''?10:0;

        $seoScore+=$metadataCoverage>=80?15:($metadataCoverage>=50?10:($metadataCoverage>0?5:0));

        $seoScore+=$videoSchemaCoverage>=80?15:($videoSchemaCoverage>=50?10:($videoSchemaCoverage>0?5:0));

        $seoScore+=$publishedCount>0?15:0;

        $seoLabel=$seoScore>=80? __('พร้อมดี'):($seoScore>=50? __('กำลังปรับปรุง'): __('ควรตั้งค่าเพิ่มเติม'));

        $seoChecks=[

            [$siteTitle!=='' && mb_strlen($siteTitle,'UTF-8')<=60,'ไตเติลเว็บไซต์ (ไม่เกิน 60 ตัวอักษร)'],

            [$siteDescription!=='' && mb_strlen($siteDescription,'UTF-8')>=50 && mb_strlen($siteDescription,'UTF-8')<=160,'Meta Description (50–160 ตัวอักษร)'],

            [$primaryKeyword!=='','คีย์เวิร์ดหลัก'],

            [$secondaryKeywords!=='','คีย์เวิร์ดรอง'],

            [$metadataCoverage>=80,'Meta ครบ '.$metadataCoverage.'% ของโพสต์ที่เผยแพร่'],

            [$videoSchemaCoverage>=80,'VideoObject พร้อมรูปปก '.$videoSchemaReady.'/'.count($videoPosts).' โพสต์'],

            [$publishedCount>0,'มี '.$publishedCount.' หน้าที่เผยแพร่และอยู่ใน Sitemap']

        ];

        $taxonomyTemplates=[

            'category'=>['label'=> __('หมวดหมู่'),'title'=>'%%cat%% %%sep%% %%sitename%%','desc'=> __('รวมเรื่องในหมวด %%cat%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%'),'variable'=>'%%cat%%, %%category%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%'],

            'studio'=>['label'=> __('ค่าย'),'title'=> __('%%studio%% %%sep%% ผลงานและเรื่องใหม่ %%sep%% %%sitename%%'),'desc'=> __('รวมผลงานจากค่าย %%studio%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%'),'variable'=>'%%studio%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%'],

            'actor'=>['label'=> __('นักแสดง'),'title'=> __('%%actor%% %%sep%% ผลงานทั้งหมด %%sep%% %%sitename%%'),'desc'=> __('รวมผลงานของ %%actor%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%'),'variable'=>'%%actor%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%'],

            'tag'=>['label'=> __('แท็ก'),'title'=>'%%tag%% %%sep%% %%sitename%%','desc'=> __('รวมเรื่องที่ติดแท็ก %%tag%% จำนวน %%post_count%% เรื่อง อัปเดตล่าสุดบน %%sitename%%'),'variable'=>'%%tag%%, %%title%%, %%term_title%%, %%sitename%%, %%post_count%%, %%primary_keyword%%, %%sep%%, %%page%%']

        ];

        $taxonomyTemplateHtml='';

        foreach($taxonomyTemplates as $taxonomyType=>$template) {

            $taxonomySample=['category'=>'AV ซับไทย','studio'=>'ตัวอย่างค่าย','actor'=>'ตัวอย่างนักแสดง','tag'=>'ตัวอย่างแท็ก'][$taxonomyType];
            $taxonomyToken=$taxonomyType==='category'?'cat':$taxonomyType;
            $taxonomyTemplateTitle=trim((string)setting('seo_taxonomy_'.$taxonomyType.'_title',''))?:$template['title'];
            $taxonomyTemplateDesc=trim((string)setting('seo_taxonomy_'.$taxonomyType.'_desc',''))?:$template['desc'];
            $taxonomyButtons='';$taxonomyAllowed=[$taxonomyType==='category'?'%%cat%%':'%%'.$taxonomyType.'%%','%%title%%','%%term_title%%','%%name%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%post_count%%','%%item_count%%','%%sep%%','%%page%%','%%currentyear%%'];
            foreach(array_unique($taxonomyAllowed) as $tokenValue)$taxonomyButtons.='<button type="button" class="avsf-token-chip" data-insert-token="'.esc($tokenValue).'">'.esc($tokenValue).'</button>';
            $taxonomyTemplateHtml.='<div class="avsf-seo-template-block" data-seo-editor data-term-type="'.esc($taxonomyType).'" data-term-name="'.esc($taxonomySample).'" data-term-count="12" data-sitename="'.esc($siteName).'" data-sitedesc="'.esc(setting('siteDesc','')).'" data-primary-keyword="'.esc(setting('seo_primary_keyword','')).'" data-separator="'.esc(setting('seo_title_separator','|')).'" data-title-template="'.esc($template['title']).'" data-desc-template="'.esc($template['desc']).'" data-token="'.esc($taxonomyToken).'"><strong>'.esc($template['label']).'</strong><div class="avsf-form-row" style="margin-top:8px;"><div class="avsf-form-group"><label>รูปแบบ Meta Title</label><input data-seo-title name="seo_taxonomy_'.$taxonomyType.'_title" maxlength="200" value="'.esc($taxonomyTemplateTitle).'"><small class="avsf-char-count" data-title-count></small></div><div class="avsf-form-group"><label>รูปแบบ Meta Description</label><textarea data-seo-description name="seo_taxonomy_'.$taxonomyType.'_desc" maxlength="300" rows="2">'.esc($taxonomyTemplateDesc).'</textarea><small class="avsf-char-count" data-desc-count></small></div></div><div class="avsf-token-picker"><span>ตัวแปรที่ใช้ได้กับ'.esc($template['label']).':</span>'.$taxonomyButtons.'</div><div class="avsf-google-preview"><div class="avsf-google-preview-label">ตัวอย่างผลการค้นหา · '.esc($template['label']).'</div><div class="avsf-google-preview-title" data-seo-preview-title>'.esc(renderSeoTemplate($taxonomyTemplateTitle,['title'=>$taxonomySample,'term_title'=>$taxonomySample,'name'=>$taxonomySample,'site_name'=>$siteName,'post_count'=>12,'item_count'=>12,$taxonomyToken=>$taxonomySample])).'</div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/'.$taxonomyType.'/'.rawurlencode(strtolower($taxonomySample))).'</div><div class="avsf-google-preview-desc" data-seo-preview-desc>'.esc(renderSeoTemplate($taxonomyTemplateDesc,['title'=>$taxonomySample,'term_title'=>$taxonomySample,'name'=>$taxonomySample,'site_name'=>$siteName,'post_count'=>12,'item_count'=>12,$taxonomyToken=>$taxonomySample])).'</div><div class="avsf-google-preview-status" data-seo-preview-status>ตัวอย่างจากข้อมูลสมมติ 12 เรื่อง · Google อาจปรับข้อความตามอุปกรณ์และคำค้นหา</div></div><small class="avsf-field-help">'.esc($template['variable']).'</small></div>';

        }

        $archiveMetaTemplates=[

            'all'=>['label'=> __('เรื่องทั้งหมด'),'title'=> __('เรื่องทั้งหมด %%sep%% %%sitename%%'),'desc'=> __('รวมเรื่องที่เผยแพร่ทั้งหมด %%post_count%% เรื่องบน %%sitename%%')],

            'categories'=>['label'=> __('หน้ารวมหมวดหมู่'),'title'=> __('หมวดหมู่ทั้งหมด %%sep%% %%sitename%%'),'desc'=> __('เลือกดูเรื่องที่เผยแพร่ตามหมวดหมู่ %%item_count%% หมวดบน %%sitename%%')],

            'studio_list'=>['label'=> __('หน้ารวมค่าย'),'title'=> __('ค่ายหนังทั้งหมด %%sep%% %%sitename%%'),'desc'=> __('เลือกดูผลงานจากค่ายต่าง ๆ %%item_count%% ค่ายบน %%sitename%%')],

            'actor_list'=>['label'=> __('หน้ารวมนักแสดง'),'title'=> __('นักแสดงทั้งหมด %%sep%% %%sitename%%'),'desc'=> __('รวมรายชื่อนักแสดง %%item_count%% คนและผลงานบน %%sitename%%')],

            'tag_list'=>['label'=> __('หน้ารวมแท็ก'),'title'=> __('แท็กทั้งหมด %%sep%% %%sitename%%'),'desc'=> __('เลือกดูเรื่องตามแท็กที่เกี่ยวข้อง %%item_count%% แท็กบน %%sitename%%')],

            'page'=>['label'=> __('หน้าเว็บคงที่'),'title'=> '%%title%% %%sep%% %%sitename%%','desc'=> '%%excerpt%%']

        ];

        $archiveMetaHtml='';

        foreach($archiveMetaTemplates as $archiveType=>$template) {
            $archiveSamples=[
                'all'=>['name'=>'เรื่องทั้งหมด','count'=>35,'tokens'=>['%%post_count%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%sep%%','%%page%%','%%currentyear%%']],
                'categories'=>['name'=>'หมวดหมู่ทั้งหมด','count'=>8,'tokens'=>['%%item_count%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%sep%%','%%page%%']],
                'studio_list'=>['name'=>'ค่ายทั้งหมด','count'=>6,'tokens'=>['%%item_count%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%sep%%','%%page%%']],
                'actor_list'=>['name'=>'นักแสดงทั้งหมด','count'=>24,'tokens'=>['%%item_count%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%sep%%','%%page%%']],
                'tag_list'=>['name'=>'แท็กทั้งหมด','count'=>48,'tokens'=>['%%item_count%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%sep%%','%%page%%']],
                'page'=>['name'=>'ตัวอย่างหน้าเว็บ','count'=>0,'tokens'=>['%%title%%','%%name%%','%%term_title%%','%%excerpt%%','%%sitename%%','%%sitedesc%%','%%primary_keyword%%','%%sep%%','%%page%%','%%currentyear%%']]
            ][$archiveType];
            $archiveTitleValue=trim((string)setting('seo_meta_'.$archiveType.'_title',''))?:$template['title'];
            $archiveDescValue=trim((string)setting('seo_meta_'.$archiveType.'_desc',''))?:$template['desc'];
            $archiveTokenButtons='';foreach($archiveSamples['tokens'] as $archiveToken)$archiveTokenButtons.='<button type="button" class="avsf-token-chip" data-insert-token="'.esc($archiveToken).'">'.esc($archiveToken).'</button>';
            $archiveVars=['title'=>$archiveSamples['name'],'name'=>$archiveSamples['name'],'term_title'=>$archiveSamples['name'],'site_name'=>$siteName,'site_desc'=>setting('siteDesc',''),'excerpt'=>'ตัวอย่างคำอธิบายจากเนื้อหาหน้าเว็บ','post_count'=>$archiveType==='all'?$archiveSamples['count']:'','item_count'=>$archiveType==='all'?'':$archiveSamples['count']];
            $archiveMetaHtml.='<div class="avsf-seo-template-block" data-seo-editor data-term-type="'.esc($archiveType==='page'?'page':'archive').'" data-term-name="'.esc($archiveSamples['name']).'" data-term-count="'.(int)$archiveSamples['count'].'" data-sitename="'.esc($siteName).'" data-excerpt="ตัวอย่างคำอธิบายจากเนื้อหาหน้าเว็บ" data-sitedesc="'.esc(setting('siteDesc','')).'" data-primary-keyword="'.esc($primaryKeyword).'" data-separator="'.esc(setting('seo_title_separator','|')).'" data-title-template="'.esc($template['title']).'" data-desc-template="'.esc($template['desc']).'"><strong>'.esc($template['label']).'</strong><div class="avsf-form-row" style="margin-top:8px;"><div class="avsf-form-group"><label>Meta Title</label><input data-seo-title name="seo_meta_'.$archiveType.'_title" maxlength="200" value="'.esc($archiveTitleValue).'"><small class="avsf-char-count" data-title-count></small></div><div class="avsf-form-group"><label>Meta Description</label><textarea data-seo-description name="seo_meta_'.$archiveType.'_desc" maxlength="300" rows="2">'.esc($archiveDescValue).'</textarea><small class="avsf-char-count" data-desc-count></small></div></div><div class="avsf-token-picker"><span>ตัวแปรที่ใช้ได้ใน'.esc($template['label']).':</span>'.$archiveTokenButtons.'</div><div class="avsf-google-preview"><div class="avsf-google-preview-label">ตัวอย่าง Google · '.esc($template['label']).'</div><div class="avsf-google-preview-title" data-seo-preview-title>'.esc(renderSeoTemplate($archiveTitleValue,$archiveVars)).'</div><div class="avsf-google-preview-url">'.esc(avsfCanonicalBaseUrl().'/'.($archiveType==='page'?'page/':'')).'</div><div class="avsf-google-preview-desc" data-seo-preview-desc>'.esc(renderSeoTemplate($archiveDescValue,$archiveVars)).'</div><div class="avsf-google-preview-status" data-seo-preview-status>ข้อมูลตัวอย่างสำหรับดูการแทนตัวแปรเท่านั้น · Google อาจปรับข้อความและความยาวตามอุปกรณ์และคำค้นหา</div></div></div>';
        }


        $sitemapUrls=[];

        $sitemapUrls[]=$siteBase.'/';

        $sitemapUrls[]=$siteBase.'/?page=all';

        $sitemapUrls[]=$siteBase.'/?page=categories';

        $categoryResult=mysqli_query($conn,"SELECT slug FROM categories ORDER BY name");

        while($categoryResult&&($categoryRow=mysqli_fetch_assoc($categoryResult)))$sitemapUrls[]=$siteBase.'/?page=category&slug='.rawurlencode($categoryRow['slug']);

        foreach(['studio'=>'studios','actor'=>'actors','tag'=>'tags'] as $taxonomyRoute=>$taxonomyTable) {

            $sitemapUrls[]=$siteBase.'/?page=taxlist&slug='.$taxonomyRoute;

            $taxonomyResult=mysqli_query($conn,"SELECT slug FROM $taxonomyTable ORDER BY name");

            while($taxonomyResult&&($taxonomyRow=mysqli_fetch_assoc($taxonomyResult)))$sitemapUrls[]=$siteBase.'/?page='.$taxonomyRoute.'&slug='.rawurlencode($taxonomyRow['slug']);

        }

        foreach($publishedPosts as $post)$sitemapUrls[]=$siteBase.'/?page=post&slug='.rawurlencode($post['slug']);

        foreach($publishedPages as $pg)$sitemapUrls[]=$siteBase.'/?page=page&slug='.rawurlencode($pg['slug']);

        $statusRows='';

        foreach($posts as $post)$statusRows.='<tr><td>โพสต์</td><td>'.esc($post['title']).'</td><td><span class="avsf-post-status '.($post['status']==='publish'?'avsf-is-published':'avsf-is-draft').'">'.($post['status']==='publish'? __('เผยแพร่'): __('ฉบับร่าง')).'</span></td><td>'.(!empty($post['metaTitle']) && !empty($post['metaDesc'])? __('ครบ'): __('ต้องตรวจ')).'</td></tr>';

        foreach($allPages as $pg)$statusRows.='<tr><td>หน้า</td><td>'.esc($pg['title']).'</td><td><span class="avsf-post-status '.($pg['status']==='publish'?'avsf-is-published':'avsf-is-draft').'">'.($pg['status']==='publish'? __('เผยแพร่'): __('ฉบับร่าง')).'</span></td><td>'.($pg['status']==='publish'? __('รวมในสถานะเผยแพร่'): __('ยังไม่เผยแพร่')).'</td></tr>';

        return '<h1 style="font-size:22px;font-weight:900;margin-bottom:8px;">🔍 ตั้งค่า SEO · SEO META KEY</h1><p style="font-size:12px;color:var(--muted);line-height:1.7;margin-bottom:16px;">ข้อมูล Meta, Keywords, Robots, Canonical และ Structured Data สำหรับเสิร์ชเอนจิน · Google ไม่ใช้ Meta Keywords เป็นปัจจัยจัดอันดับโดยตรง แต่ระบบเก็บไว้ให้เครื่องมือและ workflow ของเว็บไซต์</p>'.

        '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_seo">'.

        '<div class="avsf-seo-status"><div class="avsf-seo-status-head"><div><div style="font-size:16px;font-weight:800;">สถานะ SEO META</div><div style="font-size:12px;color:var(--muted);">ประเมินจาก Meta และข้อมูลเสิร์ชเอนจินที่ตั้งค่าไว้</div></div><div class="avsf-seo-score">'.(int)$seoScore.'<span style="font-size:14px;color:var(--muted);"> / 100</span></div></div><div class="avsf-seo-meter"><span style="width:'.(int)$seoScore.'%;"></span></div><div style="margin-top:8px;font-size:13px;font-weight:700;color:'.($seoScore>=80?'var(--success)':($seoScore>=50?'var(--gold)':'var(--danger)')).';">'.esc($seoLabel).'</div><div class="avsf-seo-checks">'.implode('',array_map(function($check){return '<div class="avsf-seo-check" style="color:'.($check[0]?'var(--success)':'var(--muted)').';">'.($check[0]?'✓':'○').' '.esc($check[1]).'</div>';},$seoChecks)).'</div></div>'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">ชื่อเว็บไซต์และ Meta หน้าแรก</div>'.

        '<div class="avsf-form-group"><label>ชื่อเว็บไซต์สำหรับ SEO / Google / Open Graph</label><input name="seoSiteName" maxlength="120" value="'.esc($seoSiteNameValue).'" placeholder="ชื่อที่ต้องการให้ Google และตัวอย่างลิงก์ใช้"><small class="avsf-field-help">ใช้กับ WebSite Schema, VideoObject Publisher, Open Graph og:site_name และตัวแปร %%sitename%% / %%site_name%% ในแม่แบบ SEO โดยไม่เปลี่ยนข้อความโลโก้หรือชื่อที่แสดงบนหัวเว็บ · เว้นว่างเพื่อใช้ชื่อเว็บไซต์จากเมนูตั้งค่าทั่วไป</small></div>'.

        '<div class="avsf-form-row"><div class="avsf-form-group"><label>Meta Title หน้าแรก</label><input name="metaHomeTitle" maxlength="200" value="'.esc($siteTitleValue).'" placeholder="%%sitename%% %%sep%% %%sitedesc%%"></div>'.

        '<div class="avsf-form-group"><label>Meta Description หน้าแรก</label><input name="metaHomeDescription" maxlength="300" value="'.esc($siteDescriptionValue).'" placeholder="%%sitedesc%% · %%primary_keyword%%"></div></div>'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">Social SEO · ตัวอย่างเมื่อแชร์ลิงก์</div><p class="avsf-field-help">Open Graph และ Twitter Card ใช้กับตัวอย่างลิงก์บนโซเชียล ไม่ใช่ H1 หรือเนื้อหา SEO ที่คนอ่านเห็น และแยกจาก SEO ในเว็บให้คนดูเห็น</p><div class="avsf-form-row"><div class="avsf-form-group"><label>Social Title (ไม่บังคับ)</label><input name="socialTitle" maxlength="200" value="'.esc(setting('seo_social_title','')).'" placeholder="เว้นว่างเพื่อใช้ Meta Title ของหน้านั้น"></div><div class="avsf-form-group"><label>Social Description (ไม่บังคับ)</label><input name="socialDescription" maxlength="300" value="'.esc(setting('seo_social_desc','')).'" placeholder="เว้นว่างเพื่อใช้ Meta Description ของหน้านั้น"></div></div><div class="avsf-form-row"><div class="avsf-form-group"><label>URL รูปภาพแชร์</label><input name="socialImage" maxlength="2000" value="'.esc(setting('seo_social_image','')).'" placeholder="https://example.com/social-image.jpg หรือ /uploads/image.jpg"><small class="avsf-field-help">ใช้ภาพนี้เมื่อหน้าไม่มีภาพของตัวเอง แนะนำภาพ 1200 × 630 px</small></div><div class="avsf-form-group"><label>อัปโหลดภาพแชร์</label><input type="file" name="seoSocialImageUpload" accept="image/jpeg,image/png,image/gif,image/webp"><small class="avsf-field-help">รองรับ JPG, PNG, GIF, WebP สูงสุด 5 MB หากเลือกไฟล์ใหม่จะใช้แทน URL ด้านบน</small></div></div><div class="avsf-form-group"><label>Twitter Card</label><select name="twitterCard"><option value="summary_large_image"'.(setting('seo_social_twitter_card','summary_large_image')==='summary_large_image'?' selected':'').'>ภาพใหญ่ (summary_large_image)</option><option value="summary"'.(setting('seo_social_twitter_card','summary_large_image')==='summary'?' selected':'').'>ภาพย่อ (summary)</option></select></div></div>'.

        '<div class="avsf-form-group"><label>ตัวคั่นใน Title</label><select name="seoTitleSeparator">'.implode('',array_map(function($value,$label)use($titleSeparator){return '<option value="'.esc($value).'"'.($titleSeparator===$value?' selected':'').'>'.esc($label).'</option>';},array_keys($titleSeparators),array_values($titleSeparators))).'</select><small class="avsf-field-help">ใช้แทนตัวแปร %%sep%% ใน Title ของทุกเทมเพลต</small></div><small class="avsf-field-help">ตัวแปรหน้าแรก: %%sitename%%, %%sitedesc%%, %%primary_keyword%%, %%sep%%, %%currentyear%% · ชื่อเดิม %%site_name%% และ %%site_desc%% ยังรองรับ</small>'.

        '<div class="avsf-form-row"><div class="avsf-form-group"><label>คีย์เวิร์ดหลัก</label><input name="primaryKeyword" value="'.esc($primaryKeyword).'" placeholder="คำค้นหลัก"></div>'.

        '<div class="avsf-form-group"><label>คีย์เวิร์ดรอง (คั่นด้วย ,)</label><input name="secondaryKeywords" value="'.esc($secondaryKeywords).'" placeholder="คำค้นรอง, คำค้นเพิ่มเติม"></div></div>'.

        '<div class="avsf-form-group"><label>Keywords เริ่มต้น (คั่นด้วย ,)</label><input name="keywords" value="'.esc(setting('seo_keywords','')).'"></div>'.

        '<small style="display:block;margin:-6px 0 12px;color:var(--muted);font-size:10px;">Google ไม่ใช้ meta keywords เป็นสัญญาณจัดอันดับโดยตรง ให้ใช้ Focus Keyphrase ในโพสต์และเขียนเนื้อหาที่ตรงกับคำค้นเป็นหลัก</small>'.

        '<div class="avsf-form-group"><label>Google Analytics / Tag ID (G-XXXXXXXXXX)</label><input name="gaId" value="'.esc(setting('seo_gaId','')).'" placeholder="G-XXXXXXXXXX"></div></div>'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">แม่แบบ Meta SEO สำหรับ Taxonomy</div><p style="font-size:11px;color:var(--muted);margin-bottom:8px;">ใช้เมื่อรายการยังไม่ได้กำหนด Meta SEO เฉพาะตัว รองรับตัวแปรแยกตามประเภท</p>'.$taxonomyTemplateHtml.'</div>'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">Meta สำหรับหน้ารวม</div>'.$archiveMetaHtml.'</div>'.

        '<div class="avsf-card avsf-mb"><div class="avsf-section-title">Robots Meta &amp; robots.txt</div><div class="avsf-form-group"><label>ค่าเริ่มต้นสำหรับหน้าที่เผยแพร่</label><select name="robots">'.implode('',array_map(function($value)use($robotsValue){return '<option value="'.esc($value).'"'.($robotsValue===$value?' selected':'').'>'.esc($value).'</option>';},['index, follow','index, nofollow','noindex, follow','noindex, nofollow'])).'</select></div><div class="avsf-form-group"><label>กฎเพิ่มเติมใน robots.txt</label><textarea name="robotsTxt" rows="4" style="font-family:monospace;font-size:12px;">'.esc(setting('seo_robots_txt','')).'</textarea><small style="font-size:10px;color:var(--muted);">กฎนี้จะต่อท้ายกฎพื้นฐานที่ /robots.txt ก่อนบรรทัด Sitemap เช่น User-agent: * และ Disallow: /private/</small></div></div>'.

        '<button class="avsf-btn avsf-btn-primary" type="submit">💾 บันทึกตั้งค่า SEO</button></form>'.

        '<div class="avsf-card avsf-mt"><div class="avsf-section-title">สถานะหน้าเว็บทั้งหมด</div><div class="avsf-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));margin-bottom:14px;"><div class="avsf-stat-card"><div class="avsf-stat-num">'.(count($posts)+count($allPages)).'</div><div class="avsf-stat-label">หน้าทั้งหมด</div></div><div class="avsf-stat-card"><div class="avsf-stat-num" style="color:var(--success);">'.$publishedCount.'</div><div class="avsf-stat-label">เผยแพร่</div></div><div class="avsf-stat-card"><div class="avsf-stat-num" style="color:var(--gold);">'.(count($posts)+count($allPages)-$publishedCount).'</div><div class="avsf-stat-label">ฉบับร่าง</div></div><div class="avsf-stat-card"><div class="avsf-stat-num" style="color:var(--accent2);">'.$metadataReady.'</div><div class="avsf-stat-label">Meta ครบ (โพสต์)</div></div></div><div style="overflow-x:auto;"><table><thead><tr><th>ชนิด</th><th>ชื่อ</th><th>สถานะ</th><th>SEO Meta</th></tr></thead><tbody>'.$statusRows.'</tbody></table></div></div>'.

        '<div class="avsf-card avsf-mt"><div class="avsf-section-title">Sitemap URL ('.count($sitemapUrls).' URL)</div><div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;font-family:monospace;font-size:11px;max-height:180px;overflow:auto;color:var(--muted);">'.implode('<br>',array_map('esc',$sitemapUrls)).'</div></div>';

    }

    if (in_array($page,['settings','settings-connectors','settings-writing','settings-reading','settings-card-layout','settings-discussion','settings-media','settings-permalinks','settings-privacy','settings-redis','settings-vdohide','backup'],true)) {

        $sectionDefinitions=[

            'settings-connectors'=>['section'=>'connectors','title'=>'Connectors','fields'=>[['socialFacebook','Facebook URL'],['socialX','X URL'],['socialInstagram','Instagram URL'],['socialTelegram','Telegram URL']]],

            'settings-writing'=>['section'=>'writing','title'=> __('การเขียน'),'fields'=>[['defaultCategory','หมวดหมู่เริ่มต้น'],['defaultPostFormat','รูปแบบโพสต์เริ่มต้น'],['mailServer','เซิร์ฟเวอร์อีเมล'],['mailPort','พอร์ตอีเมล'],['mailLogin','ชื่อผู้ใช้อีเมล'],['mailCategory','หมวดหมู่อีเมล'],['updateServices','บริการแจ้งอัปเดต']]],

            'settings-reading'=>['section'=>'reading','title'=> __('การอ่าน'),'fields'=>[['homeDisplay','หน้าแรกแสดงผล','select',['posts'=> __('โพสต์ล่าสุด'),'static'=> __('หน้าคงที่')]],['homePageId','รหัสหน้าแรก'],['feedItems','จำนวนรายการ Feed'],['feedContent','เนื้อหา Feed','select',['summary'=> __('สรุป'),'full'=> __('เต็ม')]],['discourageIndexing','ปิดการจัดทำดัชนี','select',['0'=> __('ไม่ปิด'),'1'=> __('ปิด')]]]],

            'settings-discussion'=>['section'=>'discussion','title'=> __('การสนทนา'),'fields'=>[['commentsEnabled','เปิดคอมเมนต์','select',['1'=> __('เปิด'),'0'=> __('ปิด')]],['commentsRequireName','บังคับกรอกชื่อ','select',['1'=> __('ใช่'),'0'=> __('ไม่')]],['commentsRequireApproval','ต้องอนุมัติก่อนแสดง','select',['1'=> __('ใช่'),'0'=> __('ไม่')]],['commentsNotifyAdmin','แจ้งผู้ดูแลเมื่อมีคอมเมนต์','select',['1'=> __('ใช่'),'0'=> __('ไม่')]]]],

            'settings-media'=>['section'=>'media','title'=> __('สื่อ'),'fields'=>[['mediaMaxWidth','ความกว้างสูงสุด'],['mediaMaxHeight','ความสูงสูงสุด'],['mediaQuality','คุณภาพรูปภาพ'],['mediaLazyLoad','Lazy loading','select',['1'=> __('เปิด'),'0'=> __('ปิด')]]]],

            'settings-permalinks'=>['section'=>'permalinks','title'=> __('ลิงก์ถาวร'),'fields'=>[
                ['permalinkStructure','รูปแบบลิงก์โพสต์','select',[
                    'plain'=> __('Plain — พารามิเตอร์ดั้งเดิม (?p=123)'),
                    'dayname'=>'Day and name — /2026/11/04/post-name/',
                    'monthname'=>'Month and name — /2026/11/post-name/',
                    'numeric'=>'Numeric — /archives/123/',
                    'postname'=>'Post name — /post-name/ (แนะนำ)'
                ],'ใช้กำหนด URL ของโพสต์ที่เผยแพร่ทุกเรื่อง ส่วนหมวดหมู่ นักแสดง แท็ก และค่ายใช้ฐาน URL แยกด้านล่าง'],
                ['categoryBase','ฐาน URL หมวดหมู่','text',null,'ตัวอย่าง category → /category/ชื่อหมวด/'],
                ['tagBase','ฐาน URL แท็ก','text',null,'ตัวอย่าง tag → /tag/ชื่อแท็ก/'],
                ['actorBase','ฐาน URL นักแสดง','text',null,'ตัวอย่าง actor → /actor/ชื่อนักแสดง/'],
                ['studioBase','ฐาน URL ค่าย/สตูดิโอ','text',null,'ตัวอย่าง studio → /studio/ชื่อค่าย/']
            ]],

            'settings-privacy'=>['section'=>'privacy','title'=> __('ความเป็นส่วนตัว'),'fields'=>[['privacyPageId','รหัสหน้าความเป็นส่วนตัว'],['privacyContact','อีเมลติดต่อเรื่องข้อมูลส่วนบุคคล']]],

            'settings-redis'=>['section'=>'redis','title'=>'Redis','fields'=>[['redisEnabled','เปิดใช้งาน Redis','select',['0'=> __('ปิด'),'1'=> __('เปิด')]],['redisHost','Redis Host'],['redisPort','Redis Port'],['redisDatabase','Redis Database']]],

            'settings-vdohide'=>['section'=>'vdohide','title'=>'VDOHide API','fields'=>[]]

        ];

        if($page==='backup') {

            $recentAct=[];$res=mysqli_query($conn,"SELECT * FROM activity ORDER BY time DESC LIMIT 50");while($r=mysqli_fetch_assoc($res))$recentAct[]=$r;

            $activityHtml='<div class="avsf-card avsf-mt"><div class="avsf-section-title">🕐 บันทึกกิจกรรมล่าสุด</div><div style="font-size:12px;line-height:2;max-height:200px;overflow:auto;">'.implode('',array_map(function($a){return '<div style="border-bottom:1px solid var(--border);padding:4px 0;">'.esc($a['action']).' <span style="color:var(--muted);font-size:10px;margin-left:8px;">'.fmtTime($a['time']).'</span></div>';},$recentAct)).'</div></div>';

            return '<h1 style="font-size:22px;font-weight:900;margin-bottom:20px;">สำรองข้อมูล</h1><div class="avsf-card"><div class="avsf-section-title">สำรองระบบแบบเต็ม</div><p style="font-size:12px;color:var(--muted);margin:8px 0 14px;line-height:1.8;">ดาวน์โหลดไฟล์เว็บไซต์และฐานข้อมูลรวมไว้ในไฟล์ ZIP เดียว เหมาะสำหรับย้ายระบบ หรือเก็บสำรองก่อนอัปเดต ระบบจะไม่รวมโฟลเดอร์ cache และข้อมูลภายในโปรแกรมที่ไม่จำเป็นต่อการกู้คืน</p><form method="post"><input type="hidden" name="action" value="full_backup"><button class="avsf-btn avsf-btn-primary" type="submit" onclick="return confirm(\'สร้างไฟล์สำรองเต็มระบบและฐานข้อมูลตอนนี้?\')">สร้างและดาวน์โหลดสำรองเต็มระบบ (.zip)</button></form><div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);"><div class="avsf-section-title" style="margin-bottom:10px">สำรองข้อมูลแบบรวดเร็ว</div><div style="display:flex;gap:10px;flex-wrap:wrap;"><form method="post"><input type="hidden" name="action" value="export"><button class="avsf-btn avsf-btn-ghost" type="submit">ส่งออกข้อมูล (JSON)</button></form><form method="post" onsubmit="return confirm(\'รีเซ็ตข้อมูลทั้งหมด? การดำเนินการนี้ย้อนกลับไม่ได้\')"><input type="hidden" name="action" value="reset"><button class="avsf-btn avsf-btn-danger" type="submit">รีเซ็ตทั้งหมด</button></form></div></div></div>'.$activityHtml;

        }

        if($page==='settings-card-layout') {
            $families = [
                'mobile'=>['มือถือแนวตั้ง','2 คอลัมน์เริ่มต้น'],
                'tablet_portrait'=>['แท็บเล็ต / iPad แนวตั้ง','3 คอลัมน์เริ่มต้น'],
                'tablet_landscape'=>['แท็บเล็ต / iPad แนวนอน','4 คอลัมน์เริ่มต้น'],
                'desktop'=>['คอมพิวเตอร์','4 คอลัมน์เริ่มต้น']
            ];
            $groups = [
                'posts'=> __('โพสต์และวิดีโอ (หน้าแรก / เรื่องทั้งหมด / ค้นหา / หน้ารายการในหมวด ค่าย นักแสดง และแท็ก)'),
                'studios'=> __('การ์ดค่ายทั้งหมด'),
                'actors'=> __('การ์ดนักแสดงทั้งหมด'),
                'categories'=> __('การ์ดหมวดหมู่ทั้งหมด'),
                'tags'=>'การ์ดแท็กทั้งหมด'
            ];
            $legacyPostCount=(int)setting('postsPerPage','12');
            $legacyPostCount=max(1,min(120,$legacyPostCount>0?$legacyPostCount:12));
            $rowDefaults = [
                'posts'=>[],
                'studios'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'actors'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'categories'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3],
                'tags'=>['mobile'=>5,'tablet_portrait'=>4,'tablet_landscape'=>3,'desktop'=>3]
            ];
            $columnDefaults = ['mobile'=>2,'tablet_portrait'=>3,'tablet_landscape'=>4,'desktop'=>4];
            foreach($families as $family=>$familyInfo) {
                $currentColumns=(int)setting('card_columns_'.$family,(string)$columnDefaults[$family]);
                $rowDefaults['posts'][$family]=max(1,min(20,(int)ceil($legacyPostCount/max(1,$currentColumns))));
            }
            $columnHtml = '';
            foreach ($families as $family=>$familyInfo) {
                $key='card_columns_'.$family;
                $value=(int)setting($key,(string)$columnDefaults[$family]);
                $max=$family==='mobile'?3:6;
                $columnHtml .= '<div class="avsf-card" style="min-width:0"><div class="avsf-section-title">'.esc($familyInfo[0]).'</div><div class="avsf-form-group"><label for="'.esc($key).'">จำนวนคอลัมน์</label><input type="number" id="'.esc($key).'" name="'.esc($key).'" min="1" max="'.$max.'" step="1" value="'.$value.'" required data-layout-columns="'.esc($family).'" data-max="'.$max.'"><small class="avsf-field-help">'.$familyInfo[1].' · ปรับได้ 1-'.$max.' คอลัมน์</small></div></div>';
            }
            $rowsHtml = '';
            foreach ($groups as $group=>$groupLabel) {
                $rowsHtml .= '<div class="avsf-card avsf-mb"><div class="avsf-section-title">'.esc($groupLabel).'</div><div class="avsf-grid" style="grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px">';
                foreach ($families as $family=>$familyInfo) {
                    $key='card_rows_'.$group.'_'.$family;
                    $value=(int)setting($key,(string)$rowDefaults[$group][$family]);
                    $columns=(int)setting('card_columns_'.$family,(string)$columnDefaults[$family]);
                    $rowsHtml .= '<div class="avsf-form-group" style="margin:0"><label for="'.esc($key).'">'.esc($familyInfo[0]).' · จำนวนแถว</label><input type="number" id="'.esc($key).'" name="'.esc($key).'" min="1" max="20" step="1" value="'.$value.'" required data-layout-rows="'.esc($group).'" data-family="'.esc($family).'" data-default-columns="'.$columns.'"><small class="avsf-field-help"><span data-layout-total>'.($value*$columns).'</span> การ์ดต่อหน้า (แถว × คอลัมน์)</small></div>';
                }
                $rowsHtml .= '</div></div>';
            }
            $savedNotice = !empty($_GET['saved']) ? __('<div class="avsf-card" style="border-color:var(--success);color:var(--success);margin-bottom:14px">บันทึกการตั้งค่าการแสดงผลการ์ดเรียบร้อยแล้ว</div>') : '';
            return '<h1 style="font-size:22px;font-weight:900;margin-bottom:6px">การแสดงผลการ์ดและจำนวนรายการต่อหน้า</h1>'
                .'<p style="font-size:12px;color:var(--muted);line-height:1.8;margin-bottom:16px">ตั้งค่ากลางสำหรับการ์ดทุกประเภทบนเว็บไซต์ ทั้งโพสต์/วิดีโอ ค่าย นักแสดง หมวดหมู่ และแท็ก ระบบคำนวณจำนวนรายการต่อหน้าจาก <strong>จำนวนแถว × จำนวนคอลัมน์</strong> และปรับตามความกว้างหน้าจอที่ใช้งานจริง เมื่อหมุน iPad หรือแท็บเล็ต ระบบจะตรวจขนาดหน้าจอใหม่ให้อัตโนมัติ</p>'
                .$savedNotice
                .'<form method="post"><input type="hidden" name="action" value="save_card_layout">'
                .'<div class="avsf-card avsf-mb"><div class="avsf-section-title">1. จำนวนคอลัมน์ของแต่ละอุปกรณ์</div><div class="avsf-grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px">'.$columnHtml.'</div><p style="font-size:11px;color:var(--muted);margin-top:12px">ค่าตั้งต้นตามที่กำหนด: มือถือ 2 คอลัมน์ · iPad แนวตั้ง 3 คอลัมน์ · iPad แนวนอน 4 คอลัมน์ · คอมพิวเตอร์ 4 คอลัมน์ คุณสามารถเปลี่ยนได้เอง</p></div>'
                .'<div class="avsf-card avsf-mb"><div class="avsf-section-title">2. จำนวนแถวและจำนวนการ์ดต่อหน้า</div><p style="font-size:11px;color:var(--muted);margin-bottom:12px">ปรับแยกตามประเภทเนื้อหาและอุปกรณ์ได้ จำนวนที่แสดงต่อหน้าจะคำนวณให้อัตโนมัติ ไม่ต้องแก้โค้ดเมื่ออยากเพิ่มหรือลดแถว</p></div>'
                .$rowsHtml
                .'<button class="avsf-btn avsf-btn-primary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/></svg> บันทึกการตั้งค่าการแสดงผล</button></form>'
                .'<script>(function(){var form=document.currentScript&&document.currentScript.previousElementSibling;if(!form||form.tagName!=="FORM")form=document.querySelector("form input[name=action][value=save_card_layout]")?.closest("form");if(!form)return;function update(){var cols={};form.querySelectorAll("[data-layout-columns]").forEach(function(el){cols[el.dataset.layoutColumns]=Math.max(1,parseInt(el.value||1,10)||1)});form.querySelectorAll("[data-layout-rows]").forEach(function(el){var rows=Math.max(1,parseInt(el.value||1,10)||1);var total=rows*(cols[el.dataset.family]||parseInt(el.dataset.defaultColumns||1,10));var out=el.parentNode.querySelector("[data-layout-total]");if(out)out.textContent=total})}form.addEventListener("input",update);update()})();</script>';
        }

        if($page==='settings') {

            $generalFields=[

                ['siteName','ชื่อเว็บไซต์','text','ชื่อเว็บไซต์ของคุณ','ชื่อที่ใช้ระบุตัวตนเว็บไซต์ แสดงในหัวเว็บและเทมเพลต SEO'],

                ['siteUrl','URL หลักของเว็บไซต์','url','https://example.com','กำหนด URL หลักและ path ติดตั้งของเว็บไซต์ เช่น https://example.com หรือ https://example.com/site ใช้กับ Canonical, Sitemap, robots.txt, RSS และเป็น path กลางของลิงก์ภายใน (ลิงก์ภายในยังใช้ host ที่กำลังเปิดอยู่) ส่วนรูปแบบ URL ของโพสต์ตั้งแยกได้ที่เมนู ลิงก์ถาวร'],

                ['siteDesc','คำโปรยเว็บไซต์','textarea','เช่น รวมเนื้อหาและรายการล่าสุดจากเว็บไซต์','คำอธิบายสั้น ๆ ว่าเว็บไซต์นำเสนออะไร ใช้เป็นข้อมูลตั้งต้นของ Meta และตัวแปร %%sitedesc%%'],

                ['adminEmail','อีเมลผู้ดูแล','email','admin@example.com','อีเมลสำหรับการติดต่อและการแจ้งเตือน'],

                ['logoText','ข้อความโลโก้','text','AVSF','ใช้เมื่อยังไม่ได้อัปโหลดรูปโลโก้ แยกคำด้วยช่องว่างเพื่อให้คำหลังใช้สี Accent'],

                ['siteLanguage','ภาษาของเอกสารเว็บ','select',['th'=> __('ไทย'),'en'=>'English'],'กำหนด lang attribute ใน HTML เพื่อช่วยเบราว์เซอร์และเสิร์ชเอนจิน ไม่ได้แปลข้อความบนหน้าเว็บ'],

                ['siteTimezone','เขตเวลา','select',['Asia/Bangkok'=> __('กรุงเทพฯ (UTC+7)'),'UTC'=>'UTC','Asia/Tokyo'=> __('โตเกียว (UTC+9)'),'Asia/Singapore'=> __('สิงคโปร์ (UTC+8)'),'Asia/Kuala_Lumpur'=> __('กัวลาลัมเปอร์ (UTC+8)'),'Australia/Sydney'=> __('ซิดนีย์'),'Europe/London'=> __('ลอนดอน'),'America/New_York'=> __('นิวยอร์ก'),'America/Los_Angeles'=> __('ลอสแอนเจลิส')],'ใช้แสดงวันและเวลาตามตำแหน่งของเว็บไซต์'],

                ['dateFormat','รูปแบบวันที่','select',['d M Y'=>'20 Oct 2025','d/m/Y'=>'20/10/2025','Y-m-d'=>'2025-10-20','F j, Y'=>'October 20, 2025'],'เลือกรูปแบบวันที่ที่ใช้แสดงในหน้าเว็บ'],

                ['timeFormat','รูปแบบเวลา','select',['H:i'=> __('24 ชั่วโมง (14:30)'),'g:i A'=> __('12 ชั่วโมง (2:30 PM)')],'เลือกรูปแบบเวลาแสดงผล'],


                ['announcement','ข้อความประกาศ','textarea','ประกาศข่าวหรือข้อมูลสำคัญ','แถบประกาศด้านบนเว็บ เว้นว่างหากไม่ต้องการแสดง']

            ];

            $fieldsHtml='';foreach($generalFields as $field){

                $key=$field[0];$value=setting($key,'');$type=$field[2];$help=$field[4];

                if($key==='siteLanguage'&&$value==='')$value='th';

                if($key==='siteTimezone'&&$value==='')$value='Asia/Bangkok';

                if($key==='dateFormat'&&$value==='')$value='d M Y';

                if($key==='timeFormat'&&$value==='')$value='H:i';

                if($type==='select'){

                    $options='';foreach($field[3] as $optionValue=>$optionLabel)$options.='<option value="'.esc($optionValue).'"'.((string)$value===(string)$optionValue?' selected':'').'>'.esc($optionLabel).'</option>';

                    $control='<select name="'.esc($key).'">'.$options.'</select>';

                } elseif($type==='textarea') {

                    $control='<textarea name="'.esc($key).'" rows="3" maxlength="'.($key==='siteDesc'?'500':'1000').'" placeholder="'.esc($field[3]).'">'.esc($value).'</textarea>';

                } else {

                    $control='<input type="'.esc($type).'" name="'.esc($key).'" value="'.esc($value).'" placeholder="'.esc($field[3]).'"'.($key==='siteName'?' required maxlength="120"':'').'>';

                }

                $fieldsHtml.='<div class="avsf-form-group"><label for="setting-'.esc($key).'">'.esc($field[1]).'</label>'.str_replace('name="'.esc($key).'"','id="setting-'.esc($key).'" name="'.esc($key).'"',$control).'<small class="avsf-field-help">'.esc($help).'</small></div>';

            }

            $logoUrl=setting('siteLogo','');$iconUrl=setting('siteIcon','');

            $imageFields='<div class="avsf-card avsf-mb"><div class="avsf-section-title">โลโก้เว็บไซต์</div>'.($logoUrl!==''&&validImageReference($logoUrl)?'<img src="'.esc($logoUrl).'" alt="ตัวอย่างโลโก้เว็บไซต์" style="max-width:220px;max-height:70px;object-fit:contain;margin-bottom:12px;">':'').'<div class="avsf-form-group"><label for="setting-siteLogo">URL รูปโลโก้</label><input id="setting-siteLogo" name="siteLogo" value="'.esc($logoUrl).'" placeholder="https://example.com/logo.png หรือ /uploads/logo.png"><small class="avsf-field-help">ใส่ URL รูปภาพที่เข้าถึงได้ หรือเลือกไฟล์ด้านล่าง รูปนี้จะแสดงแทนข้อความโลโก้บนหัวเว็บและท้ายเว็บ</small></div><div class="avsf-form-group"><label for="setting-siteLogoUpload">อัปโหลดรูปโลโก้</label><input id="setting-siteLogoUpload" type="file" name="siteLogoUpload" accept="image/jpeg,image/png,image/gif,image/webp"><small class="avsf-field-help">รองรับ JPG, PNG, GIF, WebP ขนาดไม่เกิน 5 MB เมื่อเลือกรูปใหม่ ระบบจะใช้รูปที่อัปโหลดแทน URL</small></div></div>'.

                '<div class="avsf-card avsf-mb"><div class="avsf-section-title">ไอคอนเว็บไซต์ (Site Icon)</div>'.($iconUrl!==''&&validImageReference($iconUrl)?'<img src="'.esc($iconUrl).'" alt="ตัวอย่างไอคอนเว็บไซต์" style="width:64px;height:64px;object-fit:contain;margin-bottom:12px;">':'').'<div class="avsf-form-group"><label for="setting-siteIcon">URL ไอคอนเว็บไซต์</label><input id="setting-siteIcon" name="siteIcon" value="'.esc($iconUrl).'" placeholder="https://example.com/icon.png หรือ /uploads/icon.png"><small class="avsf-field-help">แสดงเป็น favicon บนแท็บเบราว์เซอร์และบุ๊กมาร์ก ใช้รูปสี่เหลี่ยมจัตุรัส ขนาดแนะนำอย่างน้อย 512 × 512 px</small></div><div class="avsf-form-group"><label for="setting-siteIconUpload">อัปโหลดไอคอนเว็บไซต์</label><input id="setting-siteIconUpload" type="file" name="siteIconUpload" accept="image/jpeg,image/png,image/gif,image/webp"><small class="avsf-field-help">รองรับ JPG, PNG, GIF, WebP ขนาดไม่เกิน 5 MB เมื่อเลือกรูปใหม่ ระบบจะใช้รูปที่อัปโหลดแทน URL</small></div></div>';

            return '<h1 style="font-size:22px;font-weight:900;margin-bottom:6px;">ตั้งค่าระบบพื้นฐาน · ทั่วไป</h1><p style="color:var(--muted);font-size:12px;margin-bottom:16px;">กำหนดชื่อเว็บ คำโปรย ภาษา เวลา และ URL หลักที่ทุกส่วนของเว็บไซต์ใช้อ้างอิง ช่องตัวอย่างสีจางเป็นคำแนะนำและไม่ถูกบันทึกเป็นค่า</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_settings_section"><input type="hidden" name="settings_section" value="general"><div class="avsf-card"><div class="avsf-section-title">ข้อมูลเว็บไซต์</div>'.$fieldsHtml.'<div class="avsf-form-group"><a class="avsf-btn avsf-btn-ghost" href="index.php?page=settings-permalinks">ตั้งค่ารูปแบบลิงก์ถาวรของโพสต์ →</a><small class="avsf-field-help">URL หลักกำหนดโดเมนและโฟลเดอร์ติดตั้ง ส่วนลิงก์ถาวรกำหนดรูปแบบ URL ของแต่ละโพสต์</small></div></div>'.$imageFields.'<button class="avsf-btn avsf-btn-primary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/></svg> บันทึกการตั้งค่า</button></form>';

        }

        if($page==='settings-vdohide') {

            $environmentToken=(string)getenv('AVSF_VDOHIDE_TOKEN');
            $savedToken=(string)setting('vdohideApiToken','');
            $configuredToken=$environmentToken!==''||$savedToken!=='';
            $iframeDomain=(string)setting('vdohideIframeDomain','');
            $supportedDomains=(string)setting('vdohideSupportedDomains','');
            $domainHost=$iframeDomain!==''&&parse_url($iframeDomain,PHP_URL_HOST)?parse_url($iframeDomain,PHP_URL_HOST):'';
            $tokenStatus=$configuredToken? __('<span style="color:var(--success);font-weight:700">ตั้งค่าแล้ว</span>'): __('<span style="color:var(--gold);font-weight:700">ยังไม่ได้ตั้งค่า</span>');
            $iframePreview=$iframeDomain!==''?' sandbox="allow-scripts allow-same-origin allow-forms allow-presentation allow-pictures-in-picture" referrerpolicy="strict-origin-when-cross-origin"':'';
            return '<h1 style="font-size:22px;font-weight:900;margin-bottom:16px;">ตั้งค่าระบบพื้นฐาน · VDOHide API</h1><div class="avsf-card"><div class="avsf-section-title">เชื่อมต่อ Remote Player</div><p style="font-size:12px;line-height:1.8;color:var(--muted);">API endpoint: <code>https://vdohide.com/api/remote</code><br>สถานะโทเคน: '.$tokenStatus.'<br>โดเมน iframe ปัจจุบัน: <code>'.esc($domainHost!==''?$domainHost:$iframeDomain).'</code></p><form method="post" style="margin-top:16px"><input type="hidden" name="action" value="save_settings_section"><input type="hidden" name="settings_section" value="vdohide"><div class="avsf-form-group"><label for="vdohideApiToken">API Token</label><input type="password" id="vdohideApiToken" name="vdohideApiToken" value="" autocomplete="new-password" placeholder="ใส่โทเคน API จากผู้ให้บริการ"><small class="avsf-field-help">คั่นว่างไว้เพื่อคงโทเคนเดิมที่บันทึกไว้ หากคีย์ซอฟต์แวร์แนะนำสร้างคีย์ใหม่และนำใส่ตรงนี้ ใช้ environment variable <code>AVSF_VDOHIDE_TOKEN</code> จะมีลำดับก่อนค่าที่บันทึกในฐานข้อมูล</small></div><div class="avsf-form-group"><label for="vdohideSupportedDomains">โดเมนที่รองรับ (Supported Player Domains)</label><textarea id="vdohideSupportedDomains" name="vdohideSupportedDomains" rows="2" placeholder="player1.example.com, player2.example.com&#10;หรือแต่ละโดเมนขึ้นบรรทัดใหม่">'.esc($supportedDomains).'</textarea><small class="avsf-field-help">ใส่โดเมน player ที่เว็บไซต์ควรรู้จักและแปลงเป็น embed อัตโนมัติ คั่นด้วยจุลภาค (,) หรือขึ้นบรรทัดใหม่ ไม่ต้องใส่ https:// ระบบจะจับคู่โดเมนเหล่านี้เมื่อวางลิงก์วิดีโอ เช่น fembed.co, ggcdn.xyz, javzero.xyz</small></div><div class="avsf-form-group"><label for="vdohideIframeDomain">โดเมนสำหรับแสดงผลใน iframe</label><input type="url" id="vdohideIframeDomain" name="vdohideIframeDomain" value="'.esc($iframeDomain).'" placeholder="https://player.example.com"><small class="avsf-field-help">ใส่ URL หลักที่อนุญาตให้ตัวเล่นฝังอยู่ เช่น https://player.example.com หรือโดเมน player ของคุณ ระบบตรวจ URL แบบ http(s) เท่านั้น</small></div><button class="avsf-btn avsf-btn-primary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/></svg> บันทึกการตั้งค่า VDOHide</button></form></div>'.($iframeDomain!==''?'': __('<div class="avsf-card avsf-mt"><div class="avsf-section-title">ตัวอย่าง iframe</div><p style="font-size:12px;color:var(--muted)">จำเป็นต้องบันทึกโดเมนก่อนทดสอบการเล่นวิดีโอในหน้า iframe</p></div>'));

        }

        $definition=isset($sectionDefinitions[$page])?$sectionDefinitions[$page]:null;

        if($definition) {

            $fieldsHtml='';

            foreach($definition['fields'] as $field) {

                $key=$field[0];$label=$field[1];$permalinkDefaults=['categoryBase'=>'category','tagBase'=>'tag','actorBase'=>'actor','studioBase'=>'studio'];$value=$key==='permalinkStructure'?setting($key,'postname'):setting($key,$permalinkDefaults[$key]??'');if(isset($permalinkDefaults[$key])&&trim((string)$value)==='')$value=$permalinkDefaults[$key];if($key==='permalinkStructure'&&!in_array((string)$value,['plain','dayname','monthname','numeric','postname'],true))$value='postname';

                if(isset($field[2])&&$field[2]==='select') {

                    $options='';foreach($field[3] as $optionValue=>$optionLabel)$options.='<option value="'.esc($optionValue).'"'.((string)$value===(string)$optionValue?' selected':'').'>'.esc($optionLabel).'</option>';

                    $fieldsHtml.='<div class="avsf-form-group"><label>'.esc($label).'</label><select name="'.esc($key).'">'.$options.'</select>'.(!empty($field[4])?'<small class="avsf-field-help">'.esc($field[4]).'</small>':'').'</div>';

                } else $fieldsHtml.='<div class="avsf-form-group"><label>'.esc($label).'</label><input name="'.esc($key).'" value="'.esc($value).'">'.(!empty($field[4])?'<small class="avsf-field-help">'.esc($field[4]).'</small>':'').'</div>';

            }

            $notice=$page==='settings-permalinks'? __('<div class="avsf-card avsf-mb"><strong>ค่าเริ่มต้น: Post name</strong><p style="font-size:12px;color:var(--muted);margin-top:6px;line-height:1.8;">ตัวอย่าง URL: <code>/start-628/</code><br>ส่วนประกอบของรูปแบบมาตรฐาน: <code>%year%</code> ปี · <code>%monthnum%</code> เดือน · <code>%day%</code> วัน · <code>%postname%</code> slug · <code>%post_id%</code> รหัสโพสต์<br>แนะนำให้ใช้ Post name เพราะ URL สั้น อ่านง่าย และไม่ผูกกับวันที่หรือรหัสโพสต์ หากเปลี่ยนรูปแบบหลังเว็บเริ่มมีอันดับแล้ว ควรตรวจ Redirect และลิงก์เดิมก่อนใช้งานจริง</p></div>'):'';

            $notice.=$page==='settings-redis'? __('<p style="font-size:11px;color:var(--gold);margin-bottom:12px;">Redis เป็นตัวเลือกเสริมและจะเริ่มทำงานเมื่อเปิดใช้งาน ext/เซิร์ฟเวอร์ Redis ในสภาพแวดล้อมแล้ว</p>'):'';
            $notice.=$page==='settings-reading'? __('<div class="avsf-card avsf-mb"><strong>การจัดจำนวนโพสต์/การ์ดตามอุปกรณ์</strong><p style="font-size:11px;color:var(--muted);margin:6px 0 10px">การตั้งค่าจำนวนแถวและจำนวนรายการต่อหน้าอยู่ในหน้าการแสดงผลการ์ด เพื่อควบคุมมือถือ แท็บเล็ต และคอมพิวเตอร์แยกกัน</p><a class="avsf-btn avsf-btn-ghost" href="index.php?page=settings-card-layout">เปิดการตั้งค่าการแสดงผลการ์ด →</a></div>'):'';

            return '<h1 style="font-size:22px;font-weight:900;margin-bottom:16px;">ตั้งค่าระบบพื้นฐาน · '.esc($definition['title']).'</h1>'.$notice.'<form method="post"><input type="hidden" name="action" value="save_settings_section"><input type="hidden" name="settings_section" value="'.esc($definition['section']).'"><div class="avsf-card">'.$fieldsHtml.'<button class="avsf-btn avsf-btn-primary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/></svg> บันทึกการตั้งค่า</button></div></form>';

        }

    }

    return '<p>ไม่พบหน้านี้</p>';

}

function array_sort($arr,$key){usort($arr,function($a,$b)use($key){return $b[$key]-$a[$key];});return $arr;}

$content = adminContent($page);
$adminBrandName = avsfBrandName();
$adminBrandLogo = trim((string)setting('siteLogo',''));
$adminBrandLogoValid = $adminBrandLogo !== '' && validImageReference($adminBrandLogo);

ob_start();

?>

<!DOCTYPE html>

<html lang="<?php echo avsf_lang_attr(); ?>">

<head><script>try{var t=localStorage.getItem("avsf-admin-theme")||"light";document.documentElement.setAttribute("data-admin-theme",t);document.documentElement.className="avsf-theme-"+t;}catch(e){document.documentElement.setAttribute("data-admin-theme","light");}</script>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php _e('หลังบ้าน -'); ?> <?php echo esc($adminBrandName); ?></title>

<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>

:root{--bg:#f3f6fb;--card:#fff;--card2:#edf2f8;--text:#19304f;--muted:#667790;--accent:<?php echo $accent; ?>;--accent-soft:<?php echo $accent; ?>26;--accent2:#a855f7;--accent3:#22d3ee;--gold:#f5c542;--border:#dfe6f0;--danger:#ef4444;--success:#22c55e;color-scheme:light}[data-admin-theme="dark"],.avsf-theme-dark{--bg:#090b10;--card:#11151f;--card2:#161b26;--text:#e9edf6;--muted:#a5afc1;--border:#293142;--danger:#ef4444;--success:#22c55e;color-scheme:dark}

body[data-admin-theme="light"]{color-scheme:light}

body[data-admin-theme="light"] .avsf-admin-mobile-bar{background:rgba(255,255,255,.96)}

body[data-admin-theme="light"] .avsf-sidebar-status strong{color:#17643e}

body[data-admin-theme="light"] .avsf-post-summary-card{background:linear-gradient(120deg,#fff,#f8faff)}

body[data-admin-theme="light"] .avsf-post-summary-cover{background:#edf2f8}

body[data-admin-theme="light"] .avsf-post-summary-pill{background:rgba(25,48,91,.05);color:#384c69}

body[data-admin-theme="light"] .avsf-admin-nav-subitem.avsf-active{color:var(--accent)}

*{box-sizing:border-box;margin:0;padding:0}html,body{max-width:100%;overflow-x:clip}.avsf-admin-layout,.avsf-admin-main,.avsf-card,.avsf-form-group,.avsf-rich-editor-toolbar-row,.avsf-rich-editor-tool-group,.avsf-posts-bulk-controls,.avsf-keyword-notes-grid section{min-width:0}img,svg{max-width:100%}

body{font-family:'Kanit',sans-serif;background:var(--bg);color:var(--text);line-height:1.5;font-size:14px}

a{color:inherit;text-decoration:none;cursor:pointer}

button{cursor:pointer;font-family:inherit}

input,textarea,select{font-family:inherit;font-size:13px;background:var(--bg);color:var(--text);border:1px solid #414858;border-radius:8px;padding:9px 12px;width:100%}

select[name="status"]{width:auto;min-width:180px;max-width:100%}
input::placeholder,textarea::placeholder{color:#858595;opacity:.78}

input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(255,45,120,.2)}

.avsf-clearable-control{position:relative;display:flex;align-items:stretch;width:100%;min-width:0}

.avsf-clearable-control>input,.avsf-clearable-control>textarea{min-width:0;flex:1}

.avsf-clear-field-button{display:grid;width:34px;flex:0 0 34px;place-items:center;margin-left:-42px;align-self:center;padding:0;border:0;border-radius:7px;background:transparent;color:var(--muted);font-size:20px;line-height:1;cursor:pointer}

.avsf-clear-field-button:hover,.avsf-clear-field-button:focus-visible{background:rgba(255,45,120,.14);color:#ff8bb4;outline:0}

.avsf-clear-field-button[hidden]{display:none}

textarea{resize:vertical;min-height:80px}

.avsf-btn{padding:9px 18px;border-radius:8px;border:none;font-weight:600;font-size:13px;transition:all .2s;display:inline-block;text-align:center}

.avsf-btn-primary{background:var(--accent);color:#fff;box-shadow:0 0 12px rgba(255,45,120,.4)}

.avsf-btn-ghost{background:var(--card);color:var(--text);border:1px solid var(--border)}

.avsf-btn-danger{background:var(--danger);color:#fff}

.avsf-btn-sm{padding:5px 10px;font-size:11px}

.avsf-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}

.avsf-badge-AVSF{background:var(--accent-soft);color:var(--accent);border:1px solid rgba(255,45,120,.3)}

.avsf-badge-purple{background:rgba(168,85,247,.15);color:#c084fc;border:1px solid rgba(168,85,247,.3)}

.avsf-badge-gold{background:rgba(245,197,66,.15);color:var(--gold);border:1px solid rgba(245,197,66,.3)}

.avsf-badge-gray{background:rgba(255,255,255,.06);color:var(--muted);border:1px solid var(--border)}

.avsf-badge-green{background:rgba(34,197,94,.15);color:var(--success);border:1px solid rgba(34,197,94,.3)}

.avsf-badge-red{background:rgba(239,68,68,.15);color:var(--danger);border:1px solid rgba(239,68,68,.3)}

.avsf-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px}

.avsf-grid{display:grid;gap:12px}

table{width:100%;border-collapse:collapse;font-size:13px}

th,td{padding:10px 8px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}

th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em}

tr:hover{background:rgba(255,255,255,.02)}

.avsf-stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;text-align:center}
.avsf-stat-icon{display:grid;place-items:center}.avsf-stat-icon svg{display:block;width:22px;height:22px;max-width:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}

.avsf-stat-num{font-size:28px;font-weight:900;color:var(--accent)}

.avsf-stat-label{font-size:11px;color:var(--muted);margin-top:4px}

.avsf-dashboard-widget{min-width:0}

.avsf-dashboard-widget-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}

.avsf-dashboard-widget-heading h2{font-size:15px;font-weight:700;line-height:1.4}

.avsf-dashboard-collapse-toggle{display:grid;place-items:center;flex:0 0 32px;width:32px;height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card2);color:var(--muted);font-size:19px;line-height:1;cursor:pointer}

.avsf-dashboard-collapse-toggle:hover{border-color:var(--accent);color:var(--accent)}

.avsf-dashboard-collapse-toggle:focus-visible{outline:2px solid var(--accent);outline-offset:2px}

.avsf-dashboard-widget-content[hidden]{display:none}

.avsf-dashboard-stat-grid{grid-template-columns:repeat(auto-fit,minmax(115px,1fr))}

.avsf-dashboard-chart-grid{grid-template-columns:minmax(0,1.55fr) minmax(290px,1fr);grid-template-areas:"recent draft" "recent activity" "views views";grid-template-rows:auto auto auto;align-items:start;column-gap:12px;row-gap:10px;margin:14px 0}
.avsf-dashboard-chart-grid>.avsf-dashboard-widget{position:relative;width:100%;max-width:100%;min-width:0;overflow:hidden}
.avsf-dashboard-widget-recent-posts table{min-width:620px}
.avsf-dashboard-widget-views{grid-area:views;align-self:start}

.avsf-dashboard-widget-quick-draft{grid-area:draft}

.avsf-dashboard-widget-activity{grid-area:activity}
.avsf-dashboard-widget-recent-posts{grid-area:recent}

.avsf-dashboard-quick-draft .avsf-form-group{margin-bottom:10px}

.avsf-dashboard-quick-draft label{display:block;margin-bottom:5px;font-size:12px}

.avsf-dashboard-quick-draft input,.avsf-dashboard-quick-draft textarea{font-size:13px}

.avsf-dashboard-quick-draft textarea{min-height:84px}
.avsf-video-duration-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(112px,.7fr);gap:8px;align-items:end}
.avsf-video-duration-fields .avsf-form-group{margin-bottom:0}
.avsf-video-duration-fields .avsf-form-group label{font-size:11px;margin-bottom:5px}
.avsf-video-duration-fields input[readonly]{background:var(--card2);font-variant-numeric:tabular-nums;text-align:center;font-weight:700}

.avsf-dashboard-quick-draft .avsf-btn{min-height:38px}

.avsf-dashboard-draft-mode-switch{display:flex;gap:6px;margin:0 0 12px;padding:4px;border:1px solid var(--border);border-radius:11px;background:var(--card2)}
.avsf-dashboard-draft-mode-switch button{flex:1;min-height:34px;padding:6px 10px;border:0;border-radius:8px;background:transparent;color:var(--muted);font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.avsf-dashboard-draft-mode-switch button.avsf-is-active{background:var(--card);color:var(--accent);box-shadow:0 2px 8px rgba(31,60,106,.1)}
.avsf-dashboard-draft-mode-switch button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.avsf-dashboard-advanced-draft textarea{min-height:190px;font-family:inherit;font-size:12px!important;line-height:1.55;white-space:pre-wrap}
.avsf-dashboard-advanced-status{display:block;min-height:16px;margin-top:4px;color:var(--muted);font-size:11px}
.avsf-dashboard-advanced-draft [type=file]{width:100%;font-size:12px}
[data-draft-panel][hidden]{display:none!important}

.avsf-dashboard-recent-drafts{margin-top:18px;padding-top:12px;border-top:1px solid var(--border)}

.avsf-dashboard-recent-drafts-heading,.avsf-dashboard-recent-posts-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}

.avsf-dashboard-recent-drafts-heading strong{font-size:12px}

.avsf-dashboard-recent-drafts-heading a{color:var(--accent);font-size:11px;text-decoration:underline}

.avsf-dashboard-draft-link{display:block;padding:7px 0;border-bottom:1px solid var(--border);font-size:11px;line-height:1.45;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.avsf-dashboard-draft-link:last-child{border-bottom:0}

.avsf-dashboard-draft-link small{display:block;color:var(--muted);font-size:10px}

.avsf-dashboard-empty-drafts{color:var(--muted);font-size:11px}

.avsf-dashboard-activity-list{max-height:190px;overflow:auto}

.avsf-dashboard-activity-item{display:flex;gap:8px;padding:7px 0;border-bottom:1px solid var(--border);font-size:11px}

.avsf-dashboard-activity-item>span{color:var(--accent)}

.avsf-dashboard-activity-item small{display:block;color:var(--muted);font-size:10px}

.avsf-dashboard-recent-posts-heading{justify-content:flex-end}

.avsf-admin-layout{display:flex;min-height:100vh}

.avsf-admin-mobile-bar{display:none}

.avsf-admin-sidebar{width:240px;background:var(--card);border-right:1px solid var(--border);padding:16px 10px;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none}

.avsf-admin-sidebar::-webkit-scrollbar,.avsf-editor-preview-panel::-webkit-scrollbar{width:0;height:0}

.avsf-sidebar-status-row{display:flex;align-items:stretch;gap:6px;margin:0 2px 14px}

.avsf-sidebar-status{display:flex;align-items:center;gap:10px;flex:1;min-width:0;padding:11px 12px;margin:0;border:1px solid rgba(34,197,94,.2);border-radius:12px;background:linear-gradient(120deg,rgba(34,197,94,.12),rgba(34,211,238,.05))}

.avsf-sidebar-status-dot{width:9px;height:9px;flex:0 0 9px;border-radius:50%;background:var(--success);box-shadow:0 0 12px rgba(34,197,94,.8)}

.avsf-sidebar-status strong,.avsf-sidebar-status small{display:block}

.avsf-sidebar-status strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#d8ffe5}

.avsf-sidebar-status small{font-size:10px;color:var(--muted);margin-top:2px}

.avsf-sidebar-home-link{display:grid;place-items:center;flex:0 0 38px;border:1px solid var(--border);border-radius:12px;background:var(--card2);color:var(--muted);font-size:17px}

.avsf-sidebar-home-link:hover{border-color:var(--accent);background:var(--accent-soft);color:var(--accent)}

.avsf-admin-main{flex:1;padding:24px;min-width:0;max-width:100%}

.avsf-admin-nav-item{display:block;padding:9px 12px;border-radius:8px;color:var(--muted);font-size:12.5px;font-weight:500;margin-bottom:2px;transition:all .2s}

.avsf-admin-nav-item:hover{background:var(--accent-soft);color:var(--accent)}

.avsf-admin-nav-item.avsf-active{background:var(--accent);color:#fff;box-shadow:0 0 12px rgba(255,45,120,.4)}

.avsf-admin-nav-group{margin:2px 0 8px;padding:4px 0 6px;border-bottom:1px solid var(--border)}

.avsf-admin-nav-group-label{padding:8px 12px 5px;color:var(--text);font-size:12.5px;font-weight:700}

.avsf-admin-nav-accordion{margin:2px 0 8px;padding:2px 0 5px;border-bottom:1px solid var(--border)}

.avsf-admin-nav-accordion>summary{display:flex;align-items:center;gap:8px;min-height:38px;padding:8px 12px;color:var(--text);font-size:12.5px;font-weight:700;cursor:pointer;list-style:none;border-radius:8px}

.avsf-admin-nav-accordion>summary::-webkit-details-marker{display:none}

.avsf-admin-nav-accordion>summary:hover{background:var(--accent-soft)}

.avsf-admin-nav-accordion>summary:after{content:"";width:6px;height:6px;margin-left:auto;border-top:2px solid var(--muted);border-right:2px solid var(--muted);transform:rotate(45deg);transition:transform .15s}

.avsf-admin-nav-accordion[open]>summary:after{transform:rotate(135deg)}

.avsf-admin-nav-accordion>summary .avsf-admin-nav-dot{width:5px;height:5px;margin-left:auto;border-radius:50%;background:var(--muted)}

.avsf-admin-nav-accordion[open]>summary .avsf-admin-nav-dot{display:none}

.avsf-admin-nav-accordion>.avsf-admin-nav-submenu{padding:0 0 3px}

.avsf-admin-sidebar-head{position:sticky;top:-16px;z-index:6;margin:-16px -10px 8px;padding:16px 10px 8px;background:var(--card);border-bottom:1px solid var(--border)}
.avsf-admin-sidebar-brand{display:flex;align-items:center;min-height:42px;margin:0 8px 12px;font-size:17px;font-weight:900;line-height:1.2;overflow:hidden}.avsf-admin-sidebar-brand img{display:block;max-width:220px;max-height:52px;width:auto;height:auto;object-fit:contain}.avsf-admin-sidebar-brand span{overflow-wrap:anywhere}.avsf-admin-mobile-brand{display:flex;align-items:center;min-width:0;max-width:160px;overflow:hidden;font-weight:900;line-height:1.1}.avsf-admin-mobile-brand img{display:block;max-width:110px;max-height:34px;width:auto;height:auto;object-fit:contain}.avsf-admin-mobile-brand:not(:has(img)){overflow-wrap:anywhere}

.avsf-admin-sidebar-quick-controls{display:flex;align-items:center;gap:8px;margin:8px 2px}

.avsf-admin-sidebar-quick-controls .avsf-admin-nav-item{flex:1;margin:0;text-align:center;padding:7px 8px}

.avsf-admin-accent-quick{display:flex;align-items:center;gap:7px;min-height:33px;padding:5px 8px;border:1px solid var(--border);border-radius:8px;background:var(--card2);color:var(--muted);font-size:10px}

.avsf-admin-accent-quick input{width:24px;height:24px;padding:0;border:0;background:transparent;cursor:pointer}

.avsf-admin-site-center-entry{position:relative}

.avsf-admin-nav-accordion>summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}

.avsf-admin-mobile-account,.avsf-admin-mobile-site-link,.avsf-admin-mobile-actions{display:none}

.avsf-admin-menu-backdrop{display:none}

@media(max-width:900px){.avsf-admin-mobile-account{display:block;min-width:0;max-width:80px;line-height:1.2}.avsf-admin-mobile-account strong,.avsf-admin-mobile-account small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.avsf-admin-mobile-account strong{font-size:10px}.avsf-admin-mobile-account small{font-size:8px;color:var(--muted)}.avsf-admin-mobile-site-link{display:block;padding:6px 7px;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:9px;white-space:nowrap}.avsf-admin-mobile-actions{display:flex;align-items:center;gap:5px}.avsf-admin-mobile-actions .avsf-btn{min-height:34px;padding:5px 8px}.avsf-admin-mobile-brand{font-size:12px;max-width:105px}.avsf-admin-mobile-brand img{max-width:92px;max-height:30px}.avsf-admin-mobile-bar{gap:6px}.avsf-admin-mobile-bar .avsf-admin-menu-toggle{min-height:34px;padding:5px 8px;font-size:11px}}

.avsf-admin-nav-subitem{display:block;margin:1px 0 1px 10px;padding:7px 10px;border-left:2px solid var(--border);border-radius:0 7px 7px 0;color:var(--muted);font-size:11.5px;transition:all .2s}

.avsf-admin-nav-subitem:hover{border-left-color:var(--accent);background:var(--accent-soft);color:var(--accent)}

.avsf-admin-nav-subitem.avsf-active{border-left-color:var(--accent);background:var(--accent-soft);color:#fff;font-weight:700}

.avsf-section-title{font-size:15px;font-weight:700;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--border)}

.avsf-form-group{margin-bottom:14px}

.avsf-form-group label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:6px}

.avsf-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}

.avsf-form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:start}
.avsf-form-row-3>.avsf-form-group{align-self:start;margin-bottom:14px;min-width:0}
.avsf-editor-basic-card .avsf-video-duration-group{grid-column:1/-1}
.avsf-editor-basic-card .avsf-video-duration-fields{grid-template-columns:minmax(0,1fr) minmax(180px,.5fr)}

.avsf-video-input-help{display:block;color:var(--muted);font-size:11px;line-height:1.65;margin-top:7px}

.avsf-remote-api-status{display:flex;align-items:flex-start;gap:10px;padding:11px 13px;border-radius:10px;margin:10px 0 14px}

.avsf-remote-api-status.avsf-is-ready{border:1px solid rgba(34,197,94,.24);background:rgba(34,197,94,.07)}

.avsf-remote-api-status.avsf-is-off{border:1px solid rgba(245,197,66,.22);background:rgba(245,197,66,.06)}

.avsf-remote-api-dot{width:8px;height:8px;border-radius:50%;margin-top:5px;flex:0 0 8px;background:var(--gold)}

.avsf-is-ready .avsf-remote-api-dot{background:var(--success);box-shadow:0 0 10px rgba(34,197,94,.6)}

.avsf-remote-api-status strong,.avsf-remote-api-status small{display:block}

.avsf-remote-api-status strong{font-size:11px}

.avsf-remote-api-status small{font-size:10px;color:var(--muted);margin-top:2px;line-height:1.5}

.avsf-video-input-preview{min-height:42px;color:var(--muted);font-size:11px}

.avsf-video-input-preview .avsf-video-wrap{max-width:480px}

.avsf-video-preview-frame,.avsf-video-preview-media{display:block;width:min(100%,480px);aspect-ratio:16/9;border:1px solid var(--border);border-radius:12px;background:#000}

.avsf-video-preview-media{height:auto}

input[type=file]{padding:7px 9px;background:linear-gradient(145deg,rgba(255,45,120,.08),rgba(168,85,247,.07));border:1px dashed rgba(255,255,255,.2);border-radius:10px;cursor:pointer;color:var(--muted)}

input[type=file]::file-selector-button{margin-right:10px;padding:7px 12px;border:0;border-radius:7px;background:linear-gradient(100deg,var(--accent),var(--accent2));color:#fff;font:600 11px Kanit,sans-serif;cursor:pointer}

input[type=file]::-webkit-file-upload-button{margin-right:10px;padding:7px 12px;border:0;border-radius:7px;background:linear-gradient(100deg,var(--accent),var(--accent2));color:#fff;font:600 11px Kanit,sans-serif;cursor:pointer}

.avsf-editor-preview-layout{display:block}

.avsf-editor-form{display:flex;flex-direction:column;gap:16px}

.avsf-editor-form>.avsf-card,.avsf-editor-form>.avsf-editor-columns{min-width:0;margin:0}

.avsf-editor-basic-card{width:100%}
.avsf-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.avsf-rich-editor-toolbar{position:relative;display:grid;gap:7px;margin-bottom:8px;padding:9px;border:1px solid #bac8d9;border-radius:10px;background:linear-gradient(180deg,#f8fbff,#edf3fa);color:#18324f}
[data-admin-theme="dark"] .avsf-rich-editor-toolbar{border-color:#3e526a;background:linear-gradient(180deg,#182638,#111a28);color:#eaf2ff}
.avsf-rich-editor-toolbar-row,.avsf-rich-editor-tool-group{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.avsf-rich-editor-toolbar-row+.avsf-rich-editor-toolbar-row{padding-top:7px;border-top:1px solid #d5deea}
[data-admin-theme="dark"] .avsf-rich-editor-toolbar-row+.avsf-rich-editor-toolbar-row{border-color:#34455b}
.avsf-rich-editor-toolbar button,.avsf-rich-editor-toolbar select,.avsf-rich-color-tool{display:inline-flex;min-width:34px;min-height:34px;align-items:center;justify-content:center;gap:4px;padding:5px 8px;border:1px solid #aebdd0;border-radius:7px;background:#fff;color:#203b5a;font:600 13px/1.2 Kanit,sans-serif;cursor:pointer;box-shadow:0 1px 2px rgba(21,48,79,.07)}
[data-admin-theme="dark"] .avsf-rich-editor-toolbar button,[data-admin-theme="dark"] .avsf-rich-editor-toolbar select,[data-admin-theme="dark"] .avsf-rich-color-tool{border-color:#4b6079;background:#202f42;color:#f1f6ff}
.avsf-rich-editor-toolbar button:hover,.avsf-rich-editor-toolbar button:focus-visible,.avsf-rich-editor-toolbar select:hover,.avsf-rich-editor-toolbar select:focus-visible,.avsf-rich-color-tool:focus-within{border-color:#1476d4;background:#e8f3ff;color:#075bb1;outline:2px solid rgba(20,118,212,.2);outline-offset:1px}
[data-admin-theme="dark"] .avsf-rich-editor-toolbar button:hover,[data-admin-theme="dark"] .avsf-rich-editor-toolbar button:focus-visible,[data-admin-theme="dark"] .avsf-rich-editor-toolbar select:hover,[data-admin-theme="dark"] .avsf-rich-editor-toolbar select:focus-visible,[data-admin-theme="dark"] .avsf-rich-color-tool:focus-within{border-color:#58aaff;background:#203d5c;color:#fff}
.avsf-rich-editor-toolbar select{max-width:180px;padding:5px 25px 5px 9px}
.avsf-rich-editor-separator{width:1px;height:24px;margin:0 2px;background:#b9c7d8}
[data-admin-theme="dark"] .avsf-rich-editor-separator{background:#52637a}
.avsf-rich-color-tool{position:relative;min-width:39px;padding:3px 6px;font-weight:800}
.avsf-rich-color-foreground span{font-size:17px;text-decoration:underline;text-decoration-color:var(--selected-color,#d92d55);text-decoration-thickness:3px}
.avsf-rich-color-highlight span{color:#19304f;text-shadow:1px 1px #fff;background:linear-gradient(transparent 35%,var(--selected-color,#fff176) 35%,var(--selected-color,#fff176) 90%,transparent 90%)}
.avsf-rich-color-tool input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.avsf-rich-editor-specials{position:absolute;z-index:20;right:8px;bottom:8px;display:grid;grid-template-columns:repeat(8,34px);gap:4px;padding:8px;border:1px solid #8fa4bf;border-radius:9px;background:#fff;box-shadow:0 10px 28px rgba(14,32,55,.24)}
[data-admin-theme="dark"] .avsf-rich-editor-specials{border-color:#52637a;background:#172334}
.avsf-rich-editor-specials[hidden]{display:none}
.avsf-rich-editor-specials button{min-width:34px;min-height:32px;padding:4px}
.avsf-rich-editor-status:empty{display:none}
.avsf-rich-editor-status:not(:empty){grid-column:1/-1;display:block;padding:4px 7px;border-radius:6px;background:#fff0f1;color:#a32137;font-size:11px;font-weight:600}
[data-admin-theme="dark"] .avsf-rich-editor-status:not(:empty){background:#3a1c28;color:#ffb5c2}
#postContentEditor.avsf-rich-editor-source{position:absolute!important;width:1px!important;height:1px!important;min-height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.avsf-post-content-visual{width:100%;min-height:320px;max-height:70vh;overflow:auto;resize:vertical;padding:14px 16px;border:1px solid #b8c5d6;border-radius:9px;background:#fff;color:#19304f;font-size:15px;line-height:1.75;outline:0;overflow-wrap:anywhere}
.avsf-post-content-visual:focus{border-color:#1476d4;box-shadow:0 0 0 3px rgba(20,118,212,.16)}
[data-admin-theme="dark"] .avsf-post-content-visual{border-color:#465a73;background:#0d1520;color:#e8edf5}
.avsf-post-content-visual h1,.avsf-post-content-visual h2,.avsf-post-content-visual h3,.avsf-post-content-visual h4,.avsf-post-content-visual h5,.avsf-post-content-visual h6{margin:.8em 0 .45em;line-height:1.35}
.avsf-post-content-visual blockquote{margin:12px 0;padding:8px 14px;border-left:4px solid #1684e8;background:#edf6ff;color:#264866}
[data-admin-theme="dark"] .avsf-post-content-visual blockquote{background:#182b3d;color:#dcecff}
.avsf-post-content-visual ul,.avsf-post-content-visual ol{padding-left:28px}
.avsf-post-content-visual a{color:#096ac3;text-decoration:underline;text-underline-offset:2px}
[data-admin-theme="dark"] .avsf-post-content-visual a{color:#73bdff}
.avsf-post-content-visual hr{margin:16px 0;border:0;border-top:2px solid #b8c5d6}
.avsf-post-content-visual img{max-width:100%;height:auto}
.avsf-editor-content-card.avsf-is-rich-fullscreen{position:fixed!important;z-index:10000;inset:0;display:flex;flex-direction:column;overflow:auto;margin:0!important;padding:14px;background:var(--card,#fff)}
.avsf-editor-content-card.avsf-is-rich-fullscreen .avsf-form-group{display:flex;flex:1;flex-direction:column}
.avsf-editor-content-card.avsf-is-rich-fullscreen .avsf-post-content-visual{flex:1;max-height:none;resize:none}
@media(max-width:700px){.avsf-rich-editor-toolbar{padding:7px;gap:6px}.avsf-rich-editor-toolbar-row,.avsf-rich-editor-tool-group{gap:4px}.avsf-rich-editor-toolbar button,.avsf-rich-editor-toolbar select,.avsf-rich-color-tool{min-width:36px;min-height:38px;padding:6px}.avsf-rich-editor-toolbar select{max-width:142px}.avsf-post-content-visual{min-height:300px;padding:12px;font-size:16px}.avsf-rich-editor-specials{grid-template-columns:repeat(4,36px)}}
.avsf-taxonomy-chip:has(.avsf-taxonomy-selected-count){padding-right:8px}
.avsf-taxonomy-selected-count{display:none!important}

.avsf-editor-columns{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;align-items:start}

.avsf-editor-primary-column,.avsf-editor-side-column{display:flex;min-width:0;flex-direction:column;gap:16px}

.avsf-editor-primary-column{grid-column:1;grid-row:1}

.avsf-editor-side-column{grid-column:2;grid-row:1}

.avsf-editor-primary-column>.avsf-card,.avsf-editor-side-column>.avsf-card{min-width:0;margin:0}

.avsf-editor-preview-panel{position:static}

.avsf-seo-audit-sticky{position:sticky;top:16px;z-index:5}

.avsf-editor-cover-preview{position:relative;overflow:hidden;min-height:110px;border:1px solid var(--border);border-radius:14px;background:var(--card)}

.avsf-editor-cover-preview[hidden]{display:none}

.avsf-editor-cover-preview img{display:block;width:100%;max-height:320px;object-fit:contain;background:var(--card2)}

.avsf-editor-cover-preview span{display:block;padding:7px 12px;color:var(--muted);font-size:10px}

.avsf-editor-tags-section{margin-top:18px;padding-top:18px;border-top:1px solid var(--border)}

.avsf-editor-actions{display:flex;gap:10px;flex-wrap:wrap;padding:2px 0 10px}

.avsf-code-keyphrase-row{grid-template-columns:minmax(150px,.55fr) minmax(0,1.45fr)}

.avsf-code-input{max-width:230px}

.avsf-seo-audit{margin-bottom:14px;padding:0;border:1px solid var(--border);border-radius:12px;background:linear-gradient(145deg,rgba(255,255,255,.035),rgba(255,255,255,.01))}

.avsf-seo-audit-summary{display:flex;align-items:center;gap:9px;padding:11px 13px;cursor:pointer;list-style:none}

.avsf-seo-audit-summary::-webkit-details-marker{display:none}

.avsf-seo-audit-summary::after{content:"⌄";margin-left:2px;color:var(--muted);transition:transform .2s}

.avsf-seo-audit[open]>.avsf-seo-audit-summary::after{transform:rotate(180deg)}

.avsf-seo-audit-summary strong{flex:1;font-size:13px}

.avsf-seo-audit-list{padding:0 13px}

.avsf-seo-audit-note{padding:0 13px 12px}

.avsf-seo-audit-light{width:10px;height:10px;flex:0 0 10px;border-radius:50%;background:var(--muted)}

.avsf-seo-audit-score{padding:3px 8px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:10px;font-weight:700}

.avsf-seo-audit[data-state="good"]{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.055)}

.avsf-seo-audit[data-state="good"] .avsf-seo-audit-light{background:var(--success);box-shadow:0 0 10px rgba(34,197,94,.5);animation:seoPulse 3.2s ease-in-out infinite}

.avsf-seo-audit[data-state="warning"]{border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.055)}

.avsf-seo-audit[data-state="warning"] .avsf-seo-audit-light{background:#f59e0b}

.avsf-seo-audit[data-state="error"]{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.055)}

.avsf-seo-audit[data-state="error"] .avsf-seo-audit-light{background:var(--danger)}

.avsf-seo-audit[data-state="good"] .avsf-seo-audit-score{color:#86efac;border-color:rgba(34,197,94,.3)}

.avsf-seo-audit[data-state="warning"] .avsf-seo-audit-score{color:#fcd34d;border-color:rgba(245,158,11,.3)}

.avsf-seo-audit[data-state="error"] .avsf-seo-audit-score{color:#fca5a5;border-color:rgba(239,68,68,.3)}

.avsf-seo-audit-list{display:grid;gap:7px;margin:0;padding-bottom:11px;list-style:none}

.avsf-seo-audit-check{display:flex;align-items:flex-start;gap:7px;color:var(--muted);font-size:11px;line-height:1.5}

.avsf-seo-audit-check::before{content:"•";font-size:16px;font-weight:900;line-height:1}

.avsf-seo-audit-check.avsf-is-good::before{content:"✓";color:var(--success)}

.avsf-seo-audit-check.avsf-is-warning::before{content:"!";color:#f59e0b}

.avsf-seo-audit-check.avsf-is-error::before{content:"×";color:var(--danger)}

.avsf-seo-audit-note{display:block;margin-top:10px;color:var(--muted);font-size:10px;line-height:1.5}

@keyframes seoPulse{0%,100%{opacity:.65;box-shadow:0 0 4px rgba(34,197,94,.35)}50%{opacity:1;box-shadow:0 0 12px rgba(34,197,94,.8)}}

@media(prefers-reduced-motion:reduce){.avsf-seo-audit[data-state="good"] .avsf-seo-audit-light{animation:none}}

.avsf-post-preview-content{max-height:260px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;color:var(--muted);line-height:1.8}

.avsf-mb{margin-bottom:16px}.avsf-mt{margin-top:16px}

.avsf-toggle-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:13px}

.avsf-toggle-row:last-child{border-bottom:none}

.avsf-switch{position:relative;width:44px;height:24px;flex-shrink:0}

.avsf-switch input{opacity:0;width:0;height:0}

.avsf-slider{position:absolute;cursor:pointer;inset:0;background:#333;border-radius:24px;transition:.3s}

.avsf-slider:before{content:"";position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}

.avsf-switch input:checked + .avsf-slider{background:var(--accent)}

.avsf-switch input:checked + .avsf-slider:before{transform:translateX(20px)}

.avsf-auto-hint{font-size:11px;color:var(--accent3);background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.25);border-radius:8px;padding:8px 10px;margin-top:6px;line-height:1.5}

.avsf-duration-input{display:flex;align-items:center;gap:6px;max-width:260px}

.avsf-duration-input input{width:62px;text-align:center;border-color:#596273}

.avsf-duration-separator{font-weight:800;color:var(--muted)}

.avsf-field-help{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;color:var(--muted);font-size:10px;line-height:1.5}

.avsf-usage-caption{color:var(--muted);font-size:10px;font-weight:400}

.avsf-taxonomy-source{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}

.avsf-taxonomy-autocomplete{position:relative}

.avsf-taxonomy-picker{position:relative;display:flex;min-height:44px;align-items:center;gap:6px;flex-wrap:wrap;padding:6px 8px;border:1px solid #b8d9d4;border-radius:9px;background:#fff;color:#172033;transition:border-color .2s,box-shadow .2s}
[data-admin-theme="dark"] .avsf-taxonomy-picker{background:#0c1518;color:#e8ecf4;border-color:#32998e}

.avsf-taxonomy-picker:focus-within{border-color:#54dbc1;box-shadow:0 0 0 2px rgba(52,211,183,.15)}

.avsf-taxonomy-selected{display:contents}

.avsf-taxonomy-chip{display:inline-flex;max-width:100%;align-items:center;gap:7px;padding:5px 8px;border:1px solid #318f83;border-radius:999px;background:rgba(20,184,166,.16);color:#a7f3e5;font-size:11px;line-height:1.2}

.avsf-taxonomy-chip span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.avsf-taxonomy-chip small{color:#72d8cb;font-size:9px;white-space:nowrap}

.avsf-taxonomy-chip button{display:grid;width:18px;height:18px;flex:0 0 18px;place-items:center;padding:0;border:0;border-radius:50%;background:rgba(45,212,191,.2);color:#bffcf0;font-size:13px;cursor:pointer}

.avsf-taxonomy-chip.avsf-is-custom{border-color:#398e8c;background:rgba(34,211,238,.12);color:#a5f3fc}

.avsf-taxonomy-input{width:auto;min-width:150px;max-width:100%;flex:1;border:0!important;outline:0!important;box-shadow:none!important;background:transparent!important;padding:5px 4px!important;color:var(--text)}

.avsf-taxonomy-suggestions{position:absolute;z-index:30;top:calc(100% + 4px);left:0;right:0;max-height:220px;overflow:auto;padding:5px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text);box-shadow:0 12px 28px rgba(0,0,0,.22)}

.avsf-taxonomy-suggestions:not([hidden]){display:grid;gap:4px}

.avsf-taxonomy-suggestion{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:0;border-radius:7px;background:var(--card-soft,#f7f9fc);color:#172033;text-align:left;cursor:pointer}[data-admin-theme="dark"] .avsf-taxonomy-suggestion{background:#11151d;color:#e8ecf4}

.avsf-taxonomy-suggestion:hover,.avsf-taxonomy-suggestion:focus{outline:0;background:rgba(45,212,191,.13)}

.avsf-taxonomy-suggestion small{color:#80d7cb;font-size:10px;white-space:nowrap}

.avsf-taxonomy-suggestion.avsf-is-create{justify-content:flex-start;border-top:1px solid var(--border);border-radius:0 0 7px 7px;color:#a5f3fc}

.avsf-taxonomy-suggestion.avsf-is-active{outline:0;background:rgba(45,212,191,.2)}

.avsf-taxonomy-recommendations{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}

.avsf-taxonomy-recommendation{display:inline-flex;align-items:baseline;gap:4px;padding:3px 2px;border:0;border-radius:0;background:transparent;color:var(--text);font:400 11px Kanit,sans-serif;cursor:pointer;transition:color .16s}

.avsf-taxonomy-recommendation:hover{color:var(--accent);text-decoration:underline;text-underline-offset:2px}

.avsf-taxonomy-recommendation span{padding:0;border-radius:0;background:transparent;color:#738197;font-size:10px;font-weight:400;white-space:nowrap}
[data-admin-theme="dark"] .avsf-taxonomy-recommendation span{color:#9aa8bb}

.avsf-legend-recommended{color:var(--muted)}

.avsf-legend-selected{color:var(--muted)}

.avsf-editor-gallery-preview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}

.avsf-editor-gallery-item{position:relative;aspect-ratio:1;overflow:hidden;border:1px solid var(--border);border-radius:9px;background:var(--card2)}

.avsf-editor-gallery-remove{position:absolute;top:5px;right:5px;width:28px;height:28px;border:1px solid rgba(255,255,255,.55);border-radius:50%;background:#e11d48;color:#fff;font-size:18px;font-weight:700;line-height:1;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.45)}

.avsf-editor-gallery-remove:hover{background:#be123c}

.avsf-editor-gallery-remove:focus-visible{outline:2px solid #fff;outline-offset:2px}

.avsf-editor-gallery-item img{display:block;width:100%;height:100%;object-fit:cover}

.avsf-editor-gallery-empty{grid-column:1/-1;padding:14px;border:1px dashed var(--border);border-radius:9px;color:var(--muted);font-size:11px;text-align:center}

.avsf-post-status{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;white-space:nowrap}

.avsf-post-status:before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor;box-shadow:0 0 10px currentColor}

.avsf-post-status.avsf-is-published{color:var(--success)}

.avsf-post-status.avsf-is-draft{color:var(--muted)}

.avsf-post-status.avsf-is-pending{color:var(--gold)}

.avsf-seo-status{border:1px solid var(--border);border-radius:14px;padding:18px;background:linear-gradient(135deg,rgba(255,45,120,.14),rgba(168,85,247,.12));margin-bottom:16px}

.avsf-seo-status-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}

.avsf-seo-score{font-size:28px;font-weight:900;color:var(--accent)}

.avsf-seo-meter{height:8px;background:var(--bg);border-radius:10px;overflow:hidden;margin-top:12px}

.avsf-seo-meter span{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:inherit}

.avsf-seo-checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:14px}

.avsf-seo-check{padding:9px 11px;border-radius:9px;background:rgba(0,0,0,.16);font-size:12px}

html,body{scrollbar-width:none;-ms-overflow-style:none}

*::-webkit-scrollbar{width:0;height:0}

.avsf-video-wrap{position:relative;width:100%;aspect-ratio:16/9;border-radius:14px;overflow:hidden;background:#000}

.avsf-video-wrap iframe,.avsf-video-wrap video{width:100%;height:100%;border:none}

.avsf-bar-chart{display:flex;align-items:stretch;gap:8px;height:120px;padding:10px 0}

.avsf-bar-item{box-sizing:border-box;display:flex;flex:1 1 0;min-width:0;height:100%;padding-bottom:15px;flex-direction:column;align-items:stretch;justify-content:flex-end;text-align:center}
.avsf-bar-chart .avsf-bar{flex:0 0 auto;width:100%;max-height:calc(100% - 15px);background:linear-gradient(to top,var(--accent),var(--accent2));border-radius:6px 6px 0 0;position:relative;min-height:4px}

.avsf-bar-chart .avsf-bar span{position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-size:10px;color:var(--muted)}
.avsf-bar-label{display:block;width:100%;margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:9px;line-height:1.2}

.avsf-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px}

.avsf-gallery-item{aspect-ratio:1;border-radius:8px;overflow:hidden;cursor:pointer;position:relative}

.avsf-media-card{display:block;border:1px solid var(--border);background:var(--card);color:var(--text)}

.avsf-media-card-open{display:block;width:100%;height:100%;padding:0;border:0;background:transparent;color:var(--text);text-align:left}

.avsf-media-card-open:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}

.avsf-media-card img{display:block;width:100%;height:100%;object-fit:cover}

.avsf-media-card-label{position:absolute;right:0;bottom:0;left:0;padding:4px 7px;background:rgba(0,0,0,.76);font-size:10px}

.avsf-media-card-check{position:absolute;top:6px;left:6px;display:grid;place-items:center;width:26px;height:26px;border-radius:6px;background:rgba(0,0,0,.75)}

.avsf-media-card-check input{width:16px;height:16px;margin:0}

.avsf-media-modal{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;padding:18px;background:rgba(0,0,0,.8)}

.avsf-media-modal[hidden]{display:none}

.avsf-media-modal-panel{position:relative;width:min(100%,560px);max-height:92vh;overflow:auto;padding:20px;border:1px solid var(--border);border-radius:14px;background:var(--card)}

.avsf-media-modal-close{position:absolute;top:10px;right:12px;width:34px;height:34px;border:0;border-radius:8px;background:var(--card2);color:var(--text);font-size:22px}

.avsf-media-modal-image{display:block;max-width:100%;max-height:260px;margin:0 auto 12px;object-fit:contain}

.avsf-media-modal-panel p{margin:8px 0;font-size:12px;overflow-wrap:anywhere}

.avsf-media-url{display:block;margin-top:4px;color:#b9c4ff;font-size:11px;overflow-wrap:anywhere}

.avsf-media-modal-panel .avsf-field-help{display:block;margin-top:7px}

.avsf-gallery-item img{width:100%;height:100%;object-fit:cover}

.avsf-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}

.avsf-lightbox.avsf-active{display:flex}

.avsf-lightbox img{max-width:90%;max-height:85vh;border-radius:12px}

.avsf-lightbox-close{position:absolute;top:20px;right:24px;font-size:32px;color:#fff;cursor:pointer;background:none;border:none}

.avsf-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--accent);color:#fff;padding:10px 22px;border-radius:999px;font-size:13px;font-weight:600;box-shadow:0 0 20px rgba(255,45,120,.5);z-index:999;animation:toastIn .3s ease}

@keyframes toastIn{from{opacity:0;transform:translate(-50%,20px)}to{opacity:1;transform:translate(-50%,0)}}

@media(max-width:900px){select[name="status"]{width:100%;min-width:0}.avsf-video-duration-fields{grid-template-columns:1fr}.avsf-dashboard-chart-grid{grid-template-areas:"recent" "draft" "activity" "views"}.avsf-form-row,.avsf-form-row-3{grid-template-columns:1fr}.avsf-admin-mobile-bar{position:sticky;top:0;z-index:120;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:56px;padding:8px 14px;border-bottom:1px solid var(--border);background:var(--card);backdrop-filter:blur(12px)}.avsf-admin-mobile-brand{font-size:14px;font-weight:900}.avsf-admin-menu-toggle{display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:7px 12px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--text);font-family:inherit;font-size:13px;font-weight:600}.avsf-admin-layout{display:block;min-height:calc(100vh - 56px)}.avsf-admin-sidebar{display:none;position:fixed;top:56px;bottom:0;left:0;z-index:110;width:min(300px,86vw);height:auto;padding:16px 12px;overflow-y:auto;border-right:1px solid var(--border);box-shadow:14px 0 36px rgba(0,0,0,.4)}.avsf-admin-layout.avsf-menu-open .avsf-admin-sidebar{display:block}.avsf-admin-menu-backdrop{display:none;position:fixed;inset:56px 0 0;z-index:105;width:100%;height:calc(100dvh - 56px);border:0;background:rgba(0,0,0,.62)}.avsf-admin-layout.avsf-menu-open .avsf-admin-menu-backdrop{display:block}.avsf-admin-main{padding:14px;min-width:0;width:100%;overflow-x:clip}.avsf-admin-main>*{min-width:0;max-width:100%}.avsf-admin-main .avsf-card{min-width:0}.avsf-dashboard-chart-grid,.avsf-reports-taxonomy-grid{grid-template-columns:minmax(0,1fr)!important}.avsf-admin-menu-toggle:focus-visible,.avsf-admin-nav-item:focus-visible{outline:2px solid var(--accent);outline-offset:2px}}

@media(max-width:1000px){.avsf-dashboard-chart-grid{grid-template-columns:minmax(0,1fr)!important;grid-template-areas:"recent" "draft" "activity" "views"}.avsf-dashboard-chart-grid>.avsf-dashboard-widget{grid-column:1!important;grid-row:auto!important}.avsf-dashboard-widget-recent-posts table{min-width:620px}.avsf-editor-columns{display:grid;grid-template-columns:minmax(0,1fr)}.avsf-editor-primary-column,.avsf-editor-side-column{display:contents}.avsf-editor-basic-card{grid-column:1;grid-row:1}.avsf-seo-audit-sticky{grid-column:1;grid-row:2;position:static}.avsf-editor-preview-panel{grid-column:1;grid-row:3;position:static}.avsf-editor-cover-preview{grid-column:1;grid-row:4}.avsf-editor-image-card{grid-column:1;grid-row:5}.avsf-editor-video-card{grid-column:1;grid-row:6}.avsf-editor-seo-card{grid-column:1;grid-row:7}.avsf-editor-content-card{grid-column:1;grid-row:8}.avsf-editor-gallery-card{grid-column:1;grid-row:9}.avsf-editor-taxonomy-card{grid-column:1;grid-row:10}}

.avsf-posts-page{max-width:1500px;margin:0 auto}

.avsf-posts-page-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px}

.avsf-posts-page-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}

.avsf-posts-page-actions .avsf-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}

.avsf-posts-page-actions svg{width:16px;height:16px}

.avsf-posts-page-heading h1{font-size:22px;font-weight:900}

.avsf-posts-page-heading p{margin-top:3px;color:var(--muted);font-size:12px}

.avsf-posts-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px}

.avsf-posts-stat-card{display:flex;min-height:82px;flex-direction:column;justify-content:center;align-items:center;gap:3px;padding:12px;border:1px solid rgba(255,45,120,.17);border-radius:13px;background:linear-gradient(145deg,var(--card),var(--card2));text-align:center}

.avsf-posts-stat-card strong{color:var(--accent);font-size:25px;font-weight:900;line-height:1.1}

.avsf-posts-stat-card strong.avsf-is-published{color:var(--success)}

.avsf-posts-stat-card strong.avsf-is-draft{color:var(--gold)}

.avsf-posts-stat-card strong.avsf-is-pending{color:var(--accent2)}

.avsf-posts-stat-card span{color:#b8b4c8;font-size:11px}

.avsf-post-keyword-notes{margin-bottom:16px;overflow:hidden;border:1px solid rgba(168,85,247,.25);border-radius:13px;background:linear-gradient(145deg,rgba(168,85,247,.07),rgba(20,20,28,.96))}

.avsf-post-keyword-notes>summary{display:flex;min-height:50px;align-items:center;gap:12px;padding:11px 15px;cursor:pointer;list-style:none}

.avsf-post-keyword-notes>summary::-webkit-details-marker{display:none}

.avsf-post-keyword-notes>summary:after{content:"⌄";margin-left:auto;color:#c4a4ff;font-size:19px;transition:transform .2s}

.avsf-post-keyword-notes[open]>summary:after{transform:rotate(180deg)}

.avsf-post-keyword-notes>summary span{font-size:13px;font-weight:700}

.avsf-post-keyword-notes>summary small{color:var(--muted);font-size:10px}

.avsf-keyword-notes-form{padding:0 15px 14px;border-top:1px solid var(--border)}

.avsf-keyword-notes-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding-top:14px}

.avsf-keyword-notes-grid section{min-width:0}

.avsf-keyword-notes-grid label{display:block;margin-bottom:7px;color:var(--text);font-size:12px;font-weight:700}

.avsf-keyword-notes-grid label span{display:block;color:var(--muted);font-size:10px;font-weight:400}

.avsf-keyword-note-preview{display:flex;max-height:174px;gap:6px;flex-wrap:wrap;overflow:auto;margin-bottom:8px;padding:9px;border:1px solid var(--border);border-radius:10px;background:var(--card2)}

.avsf-keyword-note-chip{display:inline-flex;max-width:100%;align-items:center;gap:6px;padding:4px 7px;border:1px solid rgba(245,158,11,.35);border-radius:999px;background:rgba(245,158,11,.09);color:#ffe0a3;font-size:10px}

.avsf-keyword-note-chip>span:nth-child(2){overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.avsf-keyword-note-chip.avsf-is-secondary{border-color:rgba(168,85,247,.35);background:rgba(168,85,247,.09);color:#e0ccff}

.avsf-keyword-note-index{color:#9ca3af;font-size:9px}

.avsf-keyword-note-rank{padding:1px 5px;border-radius:999px;background:#fb923c;color:#261405;font-size:8px;font-weight:800;white-space:nowrap}

.avsf-keyword-note-language{font-size:8px;font-weight:800}

.avsf-keyword-note-language.avsf-is-en{color:#67e8f9}

.avsf-keyword-note-language.avsf-is-th{color:#f9a8d4}

.avsf-keyword-notes-grid textarea{min-height:126px;font-size:11px;line-height:1.65}

.avsf-keyword-notes-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px}

.avsf-post-keyword-notes[open]{position:fixed;inset:0;z-index:1000;width:100%;height:100vh;height:100dvh;max-height:100vh;max-height:100dvh;overflow-y:auto;padding:clamp(12px,2.4vw,30px);border:0;border-radius:0;background:var(--bg);box-shadow:none}

.avsf-post-keyword-notes[open]>summary{position:sticky;top:0;z-index:3;max-width:1440px;min-height:58px;margin:0 auto;padding:12px 16px;border:1px solid var(--border);border-radius:12px;background:var(--card);backdrop-filter:blur(12px)}

.avsf-post-keyword-notes[open]>.avsf-keyword-notes-content{width:min(100%,1440px);min-height:calc(100dvh - 100px);margin:12px auto 0;padding:clamp(13px,2vw,24px);border:1px solid rgba(168,85,247,.2);border-radius:15px;background:linear-gradient(145deg,rgba(20,20,30,.96),rgba(12,13,21,.98))}

.avsf-keyword-notes-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}

.avsf-keyword-notes-toolbar strong,.avsf-keyword-notes-toolbar small{display:block}

.avsf-keyword-notes-toolbar strong{font-size:16px}

.avsf-keyword-notes-toolbar small{margin-top:2px;color:var(--muted);font-size:10px}

.avsf-keyword-notes-read{display:grid;gap:16px}

.avsf-keyword-notes-read section{min-width:0}

.avsf-keyword-notes-read h3{display:flex;align-items:center;gap:8px;margin-bottom:9px;color:var(--text);font-size:13px}

.avsf-keyword-notes-read h3 span{color:var(--muted);font-size:10px;font-weight:400}

.avsf-keyword-notes-read .avsf-keyword-note-preview{max-height:none;overflow:visible;gap:8px;padding:12px}

.avsf-keyword-note-chip{appearance:none;-webkit-appearance:none;cursor:pointer;font:inherit;transition:transform .14s,border-color .14s,background .14s}

.avsf-keyword-note-chip:hover{transform:translateY(-1px);border-color:#f59e0b;background:rgba(245,158,11,.2)}

.avsf-keyword-note-chip.avsf-is-secondary:hover{border-color:#c084fc;background:rgba(168,85,247,.2)}

.avsf-keyword-note-chip:focus-visible{outline:2px solid #fff;outline-offset:2px}

.avsf-keyword-notes-form.avsf-is-editing .avsf-keyword-notes-read{display:none}

.avsf-keyword-notes-form:not(.avsf-is-editing) .avsf-keyword-notes-edit{display:none!important}

.avsf-keyword-notes-edit[hidden]{display:none!important}

.avsf-keyword-notes-edit .avsf-keyword-notes-grid{padding-top:0}

.avsf-keyword-notes-edit textarea{min-height:300px}

.avsf-keyword-notes-footer>div{display:flex;gap:7px}

.avsf-keyword-notes-footer small{color:var(--muted);font-size:10px}

.avsf-posts-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 10px}

.avsf-posts-toolbar>strong{font-size:14px}

.avsf-posts-toolbar>strong span{margin-left:5px;color:var(--muted);font-size:11px;font-weight:500}

.avsf-posts-bulk-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 0;padding:11px 13px;border:1px solid var(--border);border-radius:11px;background:var(--card2)}

.avsf-posts-bulk-selection,.avsf-posts-bulk-controls{display:flex;align-items:center;gap:10px}

.avsf-posts-bulk-selection{color:var(--muted);font-size:11px}

.avsf-posts-select-all{display:flex;align-items:center;gap:7px;color:var(--text);font-weight:600;cursor:pointer}

.avsf-posts-bulk-toolbar input[type=checkbox],.avsf-post-select-checkbox{width:16px;height:16px;accent-color:#ff2d78;cursor:pointer}

.avsf-posts-bulk-controls select{min-width:190px;height:34px;padding:0 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font:inherit}

.avsf-posts-bulk-controls select:focus{outline:2px solid rgba(255,45,120,.45);outline-offset:1px}

.avsf-visually-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

.avsf-posts-view-switch{display:flex;gap:5px;padding:4px;border:1px solid var(--border);border-radius:10px;background:var(--card2)}

.avsf-posts-view-switch button{padding:6px 10px;border:0;border-radius:7px;background:transparent;color:var(--muted);font-size:11px;white-space:nowrap}

.avsf-posts-view-switch button[aria-pressed="true"]{background:var(--accent);color:#fff;box-shadow:0 3px 12px rgba(255,45,120,.2)}

.avsf-posts-view{display:none}

.avsf-posts-view.avsf-is-active{display:block}

.avsf-posts-table-card{padding:0;overflow:hidden}

.avsf-posts-table-scroll{overflow:auto}

.avsf-posts-table{min-width:1320px}

.avsf-posts-table th,.avsf-posts-table td{padding:10px 9px}

.avsf-post-table-title{display:flex;min-width:320px;max-width:380px;align-items:flex-start;gap:10px}

.avsf-post-table-title>.avsf-post-select-checkbox{flex:0 0 16px}

.avsf-post-table-title img{display:block;width:auto;height:auto;max-width:80px;max-height:64px;flex:0 0 auto;border-radius:7px;object-fit:contain;background:#22212c}

.avsf-post-table-placeholder{display:grid;width:46px;height:58px;flex:0 0 46px;place-items:center;padding:4px;border-radius:7px;background:#22212c;color:var(--muted);font-size:8px;text-align:center}

.avsf-post-table-description{display:block;min-width:0;flex:1}

.avsf-post-table-title strong,.avsf-post-table-title small{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.avsf-post-table-title strong{font-weight:600}

.avsf-post-admin-title-link{color:#aebeff}

.avsf-post-admin-title-link:hover{color:var(--accent);text-decoration:underline}

.avsf-post-table-title small{margin-top:3px;color:var(--muted);font-size:10px}

.avsf-post-table-excerpt{display:-webkit-box;overflow:hidden;margin-top:4px;color:var(--muted);font-size:10px;line-height:1.55;-webkit-box-orient:vertical;-webkit-line-clamp:2}

.avsf-post-table-inline-actions{display:flex;align-items:center;gap:5px;margin-top:5px}

.avsf-post-taxonomy-cell{min-width:125px;max-width:205px;color:#d9d7e1;font-size:11px;line-height:1.75;overflow-wrap:anywhere}

.avsf-post-taxonomy-link{color:#aebeff}

.avsf-post-taxonomy-link:hover{color:var(--accent);text-decoration:underline}

.avsf-post-taxonomy-empty{color:var(--muted)}

.avsf-post-table-actions,.avsf-post-summary-actions{display:flex;align-items:center;gap:5px}

.avsf-post-summary-actions>.avsf-post-select-checkbox{flex:0 0 16px;margin-right:3px}

.avsf-post-delete-form{display:inline-flex}

.avsf-posts-empty{text-align:center;color:var(--muted);padding:32px!important}

.avsf-posts-view-rows,.avsf-posts-view-columns{gap:10px}

.avsf-posts-view-rows.avsf-is-active{display:grid}

.avsf-post-summary-card{display:grid;grid-template-columns:88px minmax(0,1fr);overflow:hidden;border:1px solid var(--border);border-radius:13px;background:linear-gradient(120deg,var(--card),var(--card2));transition:border-color .18s,transform .18s}

.avsf-post-summary-card:hover{border-color:rgba(255,45,120,.4);transform:translateY(-1px)}

.avsf-post-summary-cover{position:relative;display:flex;min-height:126px;align-items:center;justify-content:center;overflow:hidden;background:#20202b}

.avsf-post-summary-cover img{display:block;width:auto;height:auto;max-width:100%;max-height:126px;object-fit:contain}

.avsf-post-summary-cover>span:not(.avsf-post-status){display:grid;position:absolute;inset:0;place-items:center;padding:6px;color:var(--muted);font-size:9px;text-align:center}

.avsf-post-summary-cover .avsf-post-status{position:absolute;left:5px;bottom:5px;padding:3px 6px;border-radius:999px;background:rgba(0,0,0,.78);font-size:9px}

.avsf-post-summary-cover .avsf-post-status:before{width:6px;height:6px}

.avsf-post-summary-body{min-width:0;padding:11px 13px}

.avsf-post-summary-heading{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}

.avsf-post-summary-heading h2{display:-webkit-box;overflow:hidden;margin:2px 0 0;font-size:14px;font-weight:700;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:2}

.avsf-post-summary-code{color:var(--accent);font-size:10px;font-weight:800;letter-spacing:.04em}

.avsf-post-summary-views{flex:0 0 auto;color:var(--muted);font-size:10px;white-space:nowrap}

.avsf-post-summary-byline{display:flex;gap:5px 12px;flex-wrap:wrap;margin-top:7px;color:#b8b8c4;font-size:10px}

.avsf-post-summary-meta{display:flex;gap:5px 12px;flex-wrap:wrap;margin-top:5px;color:#b8b8c4;font-size:10px}

.avsf-post-summary-taxonomy{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}

.avsf-post-summary-pill{display:inline-flex;align-items:center;max-width:100%;padding:2px 8px;border:1px solid rgba(255,255,255,.1);border-radius:999px;background:rgba(255,255,255,.045);color:#d5d1df;font-size:9px;line-height:1.45;overflow-wrap:anywhere}

.avsf-post-summary-pill.avsf-is-category{border-color:rgba(255,45,120,.28);background:var(--accent-soft);color:#ff8ab1}

.avsf-post-summary-pill.avsf-is-studio{border-color:rgba(245,197,66,.25);background:rgba(245,197,66,.08);color:#f4d77e}

.avsf-post-summary-pill.avsf-is-tag{color:#c6bfd6}

.avsf-post-summary-pill.avsf-is-empty{color:var(--muted)}

.avsf-post-summary-actions{justify-content:flex-end;margin-top:8px}

.avsf-posts-view-columns.avsf-is-active{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:stretch}

.avsf-posts-view-columns .avsf-post-summary-card{display:flex;min-width:0;flex-direction:column}

.avsf-posts-view-columns .avsf-post-summary-cover{min-height:126px;max-height:220px}

.avsf-posts-view-columns .avsf-post-summary-cover img{max-height:220px}

.avsf-posts-view-columns .avsf-post-summary-cover .avsf-post-status{left:auto;right:7px}

.avsf-posts-view-columns .avsf-post-summary-body{display:flex;flex:1;flex-direction:column}

.avsf-posts-view-columns .avsf-post-summary-heading h2{-webkit-line-clamp:3}

.avsf-posts-view-columns .avsf-post-summary-actions{margin-top:auto;padding-top:10px}

@media(max-width:1100px){.avsf-posts-view-columns.avsf-is-active{grid-template-columns:repeat(2,minmax(0,1fr))}}

@media(max-width:700px){.avsf-posts-page-heading{align-items:flex-start}.avsf-posts-page-heading h1{font-size:19px}.avsf-posts-page-heading p{max-width:250px}.avsf-posts-page-actions{justify-content:flex-start}.avsf-posts-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.avsf-posts-toolbar{align-items:flex-start;flex-direction:column}.avsf-posts-view-switch{width:100%}.avsf-posts-view-switch button{flex:1;padding:8px 5px}.avsf-posts-bulk-toolbar{align-items:stretch;flex-direction:column}.avsf-posts-bulk-controls{width:100%}.avsf-posts-bulk-controls select{flex:1;min-width:0}.avsf-keyword-notes-grid{grid-template-columns:1fr}.avsf-post-summary-card{grid-template-columns:72px minmax(0,1fr)}.avsf-post-summary-cover{min-height:112px}.avsf-post-summary-cover img{max-height:112px}.avsf-posts-view-columns.avsf-is-active{grid-template-columns:1fr}.avsf-posts-view-columns .avsf-post-summary-card{display:grid;grid-template-columns:78px minmax(0,1fr);flex-direction:initial}.avsf-posts-view-columns .avsf-post-summary-cover{min-height:112px;max-height:none}.avsf-posts-view-columns .avsf-post-summary-cover img{max-height:112px}.avsf-posts-view-columns .avsf-post-summary-cover .avsf-post-status{left:4px;right:auto}}

@media(max-width:420px){.avsf-posts-page-heading{align-items:stretch;flex-direction:column}.avsf-posts-page-actions{width:100%}.avsf-posts-page-actions .avsf-btn{flex:1}.avsf-posts-stat-card{min-height:72px}.avsf-posts-stat-card strong{font-size:22px}.avsf-post-summary-body{padding:9px}.avsf-post-summary-heading h2{font-size:12px}.avsf-post-summary-meta{gap:4px 8px;font-size:9px}}

@media(max-width:700px){.avsf-post-keyword-notes[open]{padding:8px}.avsf-post-keyword-notes[open]>summary{min-height:54px;padding:9px 10px}.avsf-post-keyword-notes>summary small{max-width:48%;font-size:9px;line-height:1.35}.avsf-post-keyword-notes[open]>.avsf-keyword-notes-content{min-height:calc(100dvh - 78px);margin-top:8px;padding:12px}.avsf-keyword-notes-toolbar strong{font-size:13px}.avsf-keyword-notes-read .avsf-keyword-note-preview{gap:6px;padding:8px}.avsf-keyword-note-chip{gap:4px;padding:4px 6px;font-size:9px}.avsf-keyword-note-index,.avsf-keyword-note-rank,.avsf-keyword-note-language{font-size:8px}.avsf-keyword-notes-edit textarea{min-height:220px}.avsf-keyword-notes-footer{align-items:flex-start;flex-direction:column}.avsf-keyword-notes-footer>div{align-self:flex-end}}

@media(prefers-reduced-motion:reduce){.avsf-keyword-note-chip{transition:none}}


.avsf-taxonomy-management-layout{display:grid;grid-template-columns:minmax(260px,1fr) minmax(0,1.6fr);gap:16px;align-items:start;min-width:0}
.avsf-taxonomy-bulk-toolbar{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin:0 0 10px;flex-wrap:wrap}
.avsf-taxonomy-bulk-toolbar form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.avsf-taxonomy-table-scroll{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-inline:contain}
.avsf-taxonomy-admin-table{width:100%;min-width:980px;border-collapse:collapse}
.avsf-taxonomy-admin-table th,.avsf-taxonomy-admin-table td{vertical-align:top}
.avsf-taxonomy-admin-table input:not([type=checkbox]),.avsf-taxonomy-admin-table textarea{max-width:100%;min-width:105px}
.avsf-taxonomy-bulk-checkbox{width:18px;height:18px;accent-color:var(--accent,#ff2d78);cursor:pointer}
@media(max-width:1000px){.avsf-taxonomy-management-layout{grid-template-columns:minmax(0,1fr)}.avsf-taxonomy-management-layout>.avsf-card{min-width:0}}
@media(max-width:700px){.avsf-admin-main,.avsf-admin-content,.avsf-admin-page{min-width:0;max-width:100%;overflow-x:hidden}.avsf-taxonomy-bulk-toolbar{justify-content:flex-start}.avsf-taxonomy-table-scroll{margin:0;width:100%;overflow:visible;padding:0}.avsf-taxonomy-admin-table{display:block;min-width:0;width:100%}.avsf-taxonomy-admin-table thead{display:none}.avsf-taxonomy-admin-table tbody{display:block;width:100%}.avsf-taxonomy-admin-table tbody tr{display:block;width:100%;margin:0 0 12px;padding:7px 10px;border:1px solid var(--border);border-radius:12px;background:var(--card)}.avsf-taxonomy-admin-table tbody td{display:grid;grid-template-columns:minmax(90px,.38fr) minmax(0,1fr);align-items:start;gap:10px;width:100%;min-width:0;padding:9px 2px;border:0;border-bottom:1px solid var(--border);overflow-wrap:anywhere}.avsf-taxonomy-admin-table tbody td:last-child{border-bottom:0}.avsf-taxonomy-admin-table tbody td:before{content:attr(data-label);display:block;color:var(--muted);font-size:11px;font-weight:700}.avsf-taxonomy-admin-table tbody td>input,.avsf-taxonomy-admin-table tbody td>textarea,.avsf-taxonomy-admin-table tbody td>strong,.avsf-taxonomy-admin-table tbody td>div,.avsf-taxonomy-admin-table tbody td>a,.avsf-taxonomy-admin-table tbody td>button,.avsf-taxonomy-admin-table tbody td>form{grid-column:2}.avsf-taxonomy-admin-table form[id^="category-form-"],.avsf-taxonomy-admin-table form[id^="taxonomy-form-"]{display:none}.avsf-taxonomy-admin-table tbody td[colspan]:before{content:none}.avsf-taxonomy-admin-table input:not([type=checkbox]),.avsf-taxonomy-admin-table textarea{width:100%;max-width:100%;min-width:0;box-sizing:border-box}.avsf-taxonomy-admin-table td[data-label="<?php echo __('เลือก'); ?>"]{grid-template-columns:1fr auto;align-items:center}.avsf-taxonomy-admin-table td[data-label="<?php echo __('เลือก'); ?>"]:before{content:'เลือก';}.avsf-form-row,.avsf-form-row-3{grid-template-columns:minmax(0,1fr)!important}.avsf-form-group,.avsf-card{min-width:0;max-width:100%}.avsf-form-group input,.avsf-form-group select,.avsf-form-group textarea,.avsf-taxonomy-management-layout input,.avsf-taxonomy-management-layout textarea{width:100%;max-width:100%;min-width:0}}

[data-admin-theme="light"] .avsf-theme-icon-moon{display:none}
[data-admin-theme="dark"] .avsf-theme-icon-sun{display:none}
body[data-admin-theme="light"] .avsf-keyword-note-chip{border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.12);color:#8a5a00}
body[data-admin-theme="light"] .avsf-keyword-note-chip.avsf-is-secondary{border-color:rgba(168,85,247,.4);background:rgba(168,85,247,.1);color:#6b3fc0}
body[data-admin-theme="light"] .avsf-keyword-note-index{color:var(--muted)}
body[data-admin-theme="light"] .avsf-keyword-note-language.avsf-is-th{color:#c2186b}
body[data-admin-theme="light"] .avsf-keyword-notes-summary>span{color:var(--text)}
.avsf-btn svg,.avsf-admin-menu-toggle svg{width:15px;height:15px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.avsf-rich-editor-toolbar button svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.avsf-rich-editor-toolbar button{display:inline-flex;align-items:center;justify-content:center}
.avsf-login-field .avsf-login-input-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:18px;height:18px;opacity:.7;pointer-events:none}
.avsf-login-input.with-icon{padding-left:42px}

/* AVSF v3.2.5 unified responsive admin dock; one persistent vertical control rail. */
.avsf-admin-desktop-dock,.avsf-admin-mobile-dock{display:none!important}
.avsf-admin-main{padding-bottom:28px!important;min-width:0}
.avsf-admin-layout,.avsf-admin-main,.avsf-card{min-width:0}
@media(max-width:900px){.avsf-admin-main{padding-bottom:32px!important}.avsf-admin-mobile-actions{display:none!important}}

/* AVSF v3.2.5 taxonomy & SEO editor system */
.avsf-tax-page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 16px}.avsf-tax-page-heading h1{margin:0 0 6px;font-size:clamp(20px,2.5vw,27px);font-weight:900}.avsf-tax-page-heading p{margin:0;color:var(--muted);font-size:13px;line-height:1.6}.avsf-tax-total{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid var(--border);border-radius:999px;background:var(--panel);font-weight:800;color:var(--text)}.avsf-tax-toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin:0 0 13px}.avsf-tax-list{display:grid;gap:10px;min-width:0}.avsf-tax-item{min-width:0;border:1px solid var(--border);border-radius:15px;background:var(--panel);padding:12px;box-shadow:0 4px 16px rgba(19,48,79,.045)}.avsf-tax-main-row{display:grid;grid-template-columns:minmax(130px,1.1fr) minmax(120px,1.2fr) auto auto;gap:9px;align-items:center;min-width:0}.avsf-tax-name{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap}.avsf-tax-name-link{font-weight:850;color:var(--text);text-decoration:none;overflow-wrap:anywhere}.avsf-tax-name-link:hover{color:#267cf4;text-decoration:underline}.avsf-tax-type-label{font-size:10px;padding:3px 7px;border-radius:999px;background:rgba(38,124,244,.1);color:#2865b2;font-weight:700}.avsf-tax-slug{font-size:12px;color:var(--muted);min-width:0;overflow-wrap:anywhere}.avsf-tax-slug a{font-weight:700;color:#267cf4;text-decoration:underline;text-underline-offset:2px}.avsf-tax-count{display:inline-flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;min-height:40px;padding:6px 11px;border:1px solid rgba(38,124,244,.4);border-radius:11px;background:rgba(38,124,244,.09);color:#1d63bb;font:inherit;cursor:pointer;transition:.18s ease}.avsf-tax-count strong{font-size:15px}.avsf-tax-count small{font-size:10px;width:100%;line-height:1}.avsf-tax-count:hover,.avsf-tax-count:focus-visible{background:#267cf4;color:#fff;box-shadow:0 5px 16px rgba(38,124,244,.2);outline:0}.avsf-tax-editor{min-width:0}.avsf-tax-editor>summary,.avsf-term-settings>summary{list-style:none;display:flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:7px 11px;border:1px solid rgba(236,112,35,.38);border-radius:11px;background:rgba(236,112,35,.09);color:#c65b18;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}.avsf-tax-editor>summary::-webkit-details-marker,.avsf-term-settings>summary::-webkit-details-marker{display:none}.avsf-tax-editor>summary:after,.avsf-term-settings>summary:after{content:'⌄';font-size:17px;line-height:1;transition:transform .15s}.avsf-tax-editor[open]>summary:after,.avsf-term-settings[open]>summary:after{transform:rotate(180deg)}.avsf-tax-edit-body{padding:14px 2px 2px;display:grid;gap:12px}.avsf-tax-posts{margin-top:10px;border-top:1px solid var(--border);padding-top:9px}.avsf-tax-posts>summary{list-style:none;cursor:pointer;color:#1768bd;font-size:12px;font-weight:800;display:flex;align-items:center;gap:7px}.avsf-tax-posts>summary::-webkit-details-marker{display:none}.avsf-tax-posts>summary:before{content:'＋';display:inline-grid;place-items:center;width:21px;height:21px;border-radius:7px;background:rgba(38,124,244,.1)}.avsf-tax-posts[open]>summary:before{content:'−'}.avsf-tax-posts ul{list-style:none;margin:9px 0 0;padding:0;display:grid;gap:6px}.avsf-tax-posts li{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;min-width:0}.avsf-term-post-info{display:grid;gap:2px;min-width:0}.avsf-term-post-info small{font-size:10px;color:var(--muted)}.avsf-term-post-link{color:#267cf4;font-weight:700;text-decoration:underline;text-underline-offset:2px;overflow-wrap:anywhere}.avsf-term-post-edit{flex:none;padding:5px 9px;border-radius:7px;background:rgba(236,112,35,.1);color:#c65b18;text-decoration:none;font-size:11px;font-weight:800}.avsf-term-post-empty{color:var(--muted);font-size:12px}.avsf-term-settings{border:1px solid var(--border);border-radius:12px;padding:9px;background:color-mix(in srgb,var(--panel) 88%,var(--bg))}.avsf-term-settings>summary{justify-content:space-between;background:rgba(38,124,244,.08);color:#2269bd;border-color:rgba(38,124,244,.28);white-space:normal}.avsf-term-settings-body{padding:12px 3px 3px;display:grid;gap:12px}.avsf-seo-custom-toggle{display:flex;align-items:center;gap:8px;color:var(--text);font-size:12px;font-weight:700}.avsf-seo-custom-toggle input{width:17px;height:17px;accent-color:#267cf4}.avsf-field-help{display:block;margin-top:5px;color:var(--muted);font-size:11px;line-height:1.55}.avsf-token-picker{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:11px;color:var(--muted)}.avsf-token-chip{border:1px solid rgba(38,124,244,.3);border-radius:999px;background:rgba(38,124,244,.07);color:#2468b8;padding:5px 8px;font:600 10px/1.3 ui-monospace,monospace;cursor:pointer}.avsf-token-chip:hover,.avsf-token-chip:focus-visible{background:#267cf4;color:#fff;outline:0}.avsf-char-count{display:block;margin-top:5px;font-size:11px;color:var(--muted);font-variant-numeric:tabular-nums}.avsf-char-count.is-warning{color:#b45309;font-weight:800}.avsf-char-count.is-danger{color:#dc2626;font-weight:800}.avsf-google-preview{border:1px solid var(--border);border-radius:12px;background:#fff;color:#202124;padding:13px;max-width:100%;overflow:hidden}.avsf-google-preview-label{font-size:10px;letter-spacing:.04em;text-transform:uppercase;color:#687384;font-weight:800;margin-bottom:8px}.avsf-google-preview-title{font-family:Arial,sans-serif;font-size:20px;line-height:1.3;font-weight:400;color:#1a0dab;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.avsf-google-preview-url{font:12px/1.5 Arial,sans-serif;color:#188038;overflow-wrap:anywhere;margin:3px 0 5px}.avsf-google-preview-desc{font:14px/1.48 Arial,sans-serif;color:#4d5156;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.avsf-google-preview-status{font:11px/1.5 system-ui,sans-serif;color:#697586;margin-top:8px;padding-top:7px;border-top:1px solid #e5e7eb}.avsf-google-preview-status.is-warning{color:#ad6500}.avsf-google-preview-status.is-danger{color:#c12b2b}.avsf-term-save-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.avsf-term-save-row form{margin:0}.avsf-tax-add-card{margin-top:15px}.avsf-tax-add-card h2{font-size:16px;margin:0 0 12px}.avsf-page-list{display:grid;gap:9px}.avsf-page-list-item{display:grid;grid-template-columns:minmax(130px,1fr) minmax(130px,1.25fr) auto auto;align-items:center;gap:10px;padding:12px;border:1px solid var(--border);border-radius:13px;background:var(--panel);min-width:0}.avsf-page-list-title{font-weight:800;color:var(--text);overflow-wrap:anywhere}.avsf-page-list-slug{font-size:11px;overflow-wrap:anywhere}.avsf-page-list-slug a{color:#267cf4;text-decoration:underline}.avsf-page-list-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.avsf-page-list-actions form{margin:0}.avsf-page-list-count{font-size:11px;color:var(--muted)}
@media(max-width:760px){.avsf-tax-main-row{grid-template-columns:minmax(0,1fr) auto;gap:8px}.avsf-tax-name{grid-column:1}.avsf-tax-slug{grid-column:1;grid-row:2}.avsf-tax-count{grid-column:2;grid-row:1/3;min-width:70px}.avsf-tax-editor{grid-column:1/-1}.avsf-tax-editor>summary{justify-content:center}.avsf-page-list-item{grid-template-columns:minmax(0,1fr) auto;gap:7px}.avsf-page-list-title{grid-column:1}.avsf-page-list-slug{grid-column:1;grid-row:2}.avsf-page-list-item>.avsf-post-status{grid-column:2;grid-row:1}.avsf-page-list-actions{grid-column:1/-1}.avsf-page-list-actions .avsf-btn{flex:1;justify-content:center}.avsf-term-save-row>*{flex:1;min-width:0}.avsf-tax-item{padding:10px}.avsf-term-settings-body .avsf-form-row{grid-template-columns:minmax(0,1fr)!important}}
@media(max-width:390px){.avsf-tax-count{min-width:63px;padding:5px}.avsf-tax-editor>summary{font-size:11px;padding:6px}.avsf-tax-slug{font-size:10px}.avsf-global-dock{width:44px;max-width:calc(100vw - 8px);box-sizing:border-box}}

.avsf-seo-template-block{padding:14px 0;border-top:1px solid var(--border);min-width:0}.avsf-seo-template-block:first-child{border-top:0}.avsf-seo-template-block>.avsf-google-preview{margin-top:10px}

.avsf-sidebar-role-owner{position:relative;border:1px solid rgba(236,159,39,.78)!important;background:linear-gradient(120deg,rgba(255,247,208,.18),rgba(232,137,34,.13))!important;box-shadow:0 0 0 1px rgba(236,159,39,.14),0 0 18px rgba(232,137,34,.2)!important}.avsf-sidebar-role-owner:after{content:"";position:absolute;inset:-3px;border:1px solid rgba(236,159,39,.55);border-radius:14px;pointer-events:none;animation:avsfSidebarOwnerGlow 3s linear infinite}@keyframes avsfSidebarOwnerGlow{0%,100%{box-shadow:0 0 5px rgba(247,185,72,.35)}50%{box-shadow:0 0 15px rgba(239,134,29,.55)}}
.avsf-sidebar-role-super_admin{border:1px solid rgba(157,108,237,.45)!important}.avsf-sidebar-role-deputy_admin{border:1px solid rgba(67,151,242,.45)!important}.avsf-sidebar-role-editor{border:1px solid rgba(34,190,202,.45)!important}.avsf-sidebar-role-author{border:1px solid rgba(59,185,106,.45)!important}.avsf-sidebar-role-moderator{border:1px solid rgba(29,180,157,.45)!important}.avsf-sidebar-role-custom{border:1px solid rgba(140,110,220,.45)!important}.avsf-sidebar-role-contributor{border:1px solid rgba(225,116,171,.45)!important}.avsf-sidebar-role-subscriber{border:1px solid rgba(134,157,200,.45)!important}.avsf-sidebar-role-user{border:1px solid rgba(155,166,182,.45)!important}
@media(prefers-reduced-motion:reduce){.avsf-sidebar-role-owner:after{animation:none}}
</style>
<link rel="stylesheet" href="admin-ui.css?v=3.2.5-adminseo1">
</head>

<body<?php echo $page==='dashboard'?' class="avsf-admin-dashboard-page"':''; ?> data-admin-theme="light">
<?php $avsfDockPostId = ($page==='post-edit' ? (int)($_GET['id']??0) : 0); $avsfDockPageId = ($page==='pages' && isset($_GET['edit']) ? (int)$_GET['edit'] : 0); echo avsf_admin_quick_dock_assets(); echo avsf_admin_quick_dock_html('index.php',$avsfDockPostId,true,$avsfDockPageId); ?>

<?php if ($msg) echo '<script>window.addEventListener("load",function(){var t=document.createElement("div");t.className="avsf-toast";t.textContent="'.esc($msg).'";document.body.appendChild(t);setTimeout(function(){t.remove();},2500);});</script>'; ?>

<?php $roleLabels=['owner'=>'Owner','super_admin'=>'Super Admin','deputy_admin'=>__('รองแอดมิน'),'editor'=>__('บรรณาธิการ'),'author'=>__('ผู้เขียน'),'moderator'=>__('ผู้ตรวจ'),'custom'=>__('ทั่วไป (กำหนดสิทธิ์เอง)'),'user'=>__('ผู้ใช้ทั่วไป')]; $sidebarUsername=isset($_SESSION['avsf_admin_username'])?$_SESSION['avsf_admin_username']:'ผู้ดูแล'; $sidebarRole=currentUserRole(); ?>
<div class="avsf-admin-mobile-bar"><div class="avsf-admin-mobile-brand"><?php if($adminBrandLogoValid): ?><img src="<?php echo esc($adminBrandLogo); ?>" alt="<?php echo esc($adminBrandName); ?>"><?php else: ?><?php echo esc($adminBrandName); ?><?php endif; ?></div><div class="avsf-admin-mobile-account"><strong><?php echo esc($sidebarUsername); ?></strong><small><?php echo esc($roleLabels[$sidebarRole]??'ผู้ดูแลระบบ'); ?></small></div><?php if(can('sitecenter.view')): ?><a class="avsf-admin-mobile-site-link" href="site-center.php"><?php _e('ศูนย์เว็บไซต์'); ?></a><?php endif; ?><div class="avsf-admin-mobile-actions"><button class="avsf-btn avsf-btn-ghost avsf-btn-sm" type="button" data-admin-theme-toggle aria-label="<?php echo __('สลับโหมดมืดและสว่าง'); ?>"><svg class="avsf-theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg class="avsf-theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg></button><button class="avsf-admin-menu-toggle" type="button" aria-expanded="false" aria-controls="adminSidebar"><span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span><span><?php _e('เมนู'); ?></span></button></div></div>

<div class="avsf-admin-layout">

<aside class="avsf-admin-sidebar" id="adminSidebar" aria-label="<?php echo __('เมนูจัดการระบบ'); ?>">

<div class="avsf-admin-sidebar-head">

<div class="avsf-admin-sidebar-brand"><?php if($adminBrandLogoValid): ?><img src="<?php echo esc($adminBrandLogo); ?>" alt="<?php echo esc($adminBrandName); ?>"><?php else: ?><span><?php echo esc($adminBrandName); ?></span><?php endif; ?></div>

<?php $roleLabels=['owner'=>'Owner','super_admin'=>'Super Admin','deputy_admin'=>__('รองแอดมิน'),'editor'=>__('บรรณาธิการ'),'author'=>__('ผู้เขียน'),'moderator'=>__('ผู้ตรวจ'),'custom'=>__('ทั่วไป (กำหนดสิทธิ์เอง)'),'user'=>__('ผู้ใช้ทั่วไป')]; $sidebarUsername=isset($_SESSION['avsf_admin_username'])?$_SESSION['avsf_admin_username']:'ผู้ดูแล'; $sidebarRole=currentUserRole(); ?>

<?php
$roleSvg='';
if($sidebarRole==='owner'){$roleSvg='<svg viewBox="0 0 32 32" aria-hidden="true"><defs><linearGradient id="pcLionOwner" x1="4" y1="2" x2="28" y2="30"><stop stop-color="#fff7c7"/><stop offset=".42" stop-color="#f6b23b"/><stop offset="1" stop-color="#a84412"/></linearGradient></defs><path d="M16 3 11 5 6 4 4 10l2 5-1 6 6 7 5-2 5 2 6-7-1-6 2-5-2-6-5 1-5-2z" fill="url(#pcLionOwner)" stroke="#8b4214"/><path d="M7 8 11 10 16 7l5 3 4-2-2 5 1 6-5 5-3-2-3 2-5-5 1-6z" fill="#f8d57a" stroke="#9b4a18"/><path d="M10 14 13 15m6-1 3-1M13 20h6m-3-3v3" fill="none" stroke="#5d2a12" stroke-width="1.6" stroke-linecap="round"/><path d="m10 5 1-3 5 3 5-3 1 3" fill="none" stroke="#8b4214" stroke-width="1.7"/><circle cx="11" cy="15" r="1" fill="#2b2019"/><circle cx="21" cy="15" r="1" fill="#2b2019"/></svg>'; } elseif($sidebarRole==='super_admin'){$roleSvg='<svg viewBox="0 0 32 32" aria-hidden="true"><defs><linearGradient id="pcGold" x1="4" y1="3" x2="27" y2="29" gradientUnits="userSpaceOnUse"><stop stop-color="#fff8bd"/><stop offset=".16" stop-color="#ffc94f"/><stop offset=".38" stop-color="#f58a2a"/><stop offset=".68" stop-color="#e94a1e"/><stop offset="1" stop-color="#943014"/></linearGradient><linearGradient id="pcGoldHi" x1="7" y1="6" x2="22" y2="22" gradientUnits="userSpaceOnUse"><stop stop-color="#fffbd8"/><stop offset=".45" stop-color="#ffd15a"/><stop offset="1" stop-color="#ec6825"/></linearGradient></defs><path d="M5.2 11 11.4 16.3 16 5.5l4.8 10.8L26.8 11 24.4 24H7.6z" fill="#7d2814" opacity=".42" transform="translate(.8 1.8)"/><path d="M4 10.2 10.7 16.4 16 5.1l5.3 11.3L28 10.2l-2.4 15H6.4z" fill="url(#pcGold)" stroke="#8f3215" stroke-width=".65"/><path d="M4 10.2 10.7 16.4 16 5.1l-1.2 12.7zM16 17.8l5.3-1.4 6.7-6.2-5.3 13.9z" fill="url(#pcGoldHi)" opacity=".82"/><path d="M7.2 21.9h17.6l-.7 4.5H7.9z" fill="#b83c18"/><path d="M7.6 22.5h16.8" stroke="#ffdf72" stroke-width="1.1"/><circle cx="4.7" cy="9.8" r="2" fill="#fff4a7" stroke="#cc7622"/><circle cx="16" cy="4.8" r="2.15" fill="#fff7ba" stroke="#cc7622"/><circle cx="27.3" cy="9.8" r="2" fill="#fff4a7" stroke="#cc7622"/><path d="M9 23.9h14" stroke="#ffe98e" opacity=".7"/></svg>'; } elseif($sidebarRole==='deputy_admin'||$sidebarRole==='moderator'){$roleSvg='<svg viewBox="0 0 32 32" aria-hidden="true"><defs><linearGradient id="pcBlue" x1="7" y1="4" x2="25" y2="28"><stop stop-color="#dff8ff"/><stop offset=".3" stop-color="#5dc6ff"/><stop offset=".65" stop-color="#3478ee"/><stop offset="1" stop-color="#3035a3"/></linearGradient></defs><path d="M16 3.5 27 7.8v8.1c0 6.9-4.1 10.7-11 13.1-6.9-2.4-11-6.2-11-13.1V7.8z" fill="url(#pcBlue)" stroke="#6aa7ff"/><path d="M10.7 16.2 14.3 20l7.2-8" fill="none" stroke="#fff" stroke-width="2.3"/></svg>'; } else {$roleSvg='<svg viewBox="0 0 32 32" aria-hidden="true"><defs><linearGradient id="pcUser" x1="6" y1="3" x2="26" y2="29"><stop stop-color="#fff"/><stop offset=".4" stop-color="#68d8ff"/><stop offset="1" stop-color="#7857d8"/></linearGradient></defs><circle cx="16" cy="10.5" r="6" fill="url(#pcUser)"/><path d="M5.2 27c.8-7 5-10.5 10.8-10.5S26 20 26.8 27" fill="url(#pcUser)"/></svg>'; }
?>
<div class="avsf-sidebar-status-row"><div class="avsf-sidebar-status avsf-sidebar-role-<?php echo esc($sidebarRole); ?>"><span class="avsf-sidebar-status-icon"><?php echo str_replace('<svg ','<svg style="width:27px;height:27px;overflow:visible;stroke-linejoin:round;stroke-linecap:round" ', $roleSvg); ?></span><span><strong><?php echo esc($sidebarUsername); ?></strong><small><?php echo esc(isset($roleLabels[$sidebarRole])?$roleLabels[$sidebarRole]:'ผู้ดูแลระบบ'); ?></small></span><span class="avsf-sidebar-status-dot" title="<?php echo __('ออนไลน์'); ?>"></span></div><a class="avsf-sidebar-home-link" href="../index.php" target="_blank" rel="noopener" aria-label="<?php echo __('เปิดหน้าแรกของเว็บไซต์ในแท็บใหม่'); ?>" title="<?php echo __('เปิดหน้าแรกของเว็บไซต์'); ?>">⌂</a></div>

<div class="avsf-admin-sidebar-quick-controls">

<button class="avsf-admin-nav-item" type="button" data-admin-theme-toggle aria-label="<?php echo __('สลับโหมดมืดและสว่าง'); ?>"><svg class="avsf-theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg class="avsf-theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg> <?php _e('ธีม'); ?></button>

<?php if (can('settings.edit')): ?><form class="avsf-admin-accent-quick" method="post" title="<?php echo __('เปลี่ยนสี Accent หลังบ้าน'); ?>"><input type="hidden" name="csrf_token" value="<?php echo esc($_SESSION['csrf_token']??''); ?>"><input type="hidden" name="action" value="save_admin_accent_quick"><label for="quickAdminAccent" style="margin:0;color:inherit"><?php _e('สีหลังบ้าน'); ?></label><input id="quickAdminAccent" type="color" name="admin_accent" value="<?php echo esc($accent); ?>" aria-label="<?php echo __('เลือกสี Accent หลังบ้าน'); ?>" onchange="this.form.submit()"></form><?php endif; ?>

</div>

<?php if(can('sitecenter.view')): ?>

<a class="avsf-admin-nav-item" href="site-center.php"><?php _e('🏠 ศูนย์เว็บไซต์'); ?></a>

<?php endif; ?>

</div>

<?php foreach ($menus as $key=>$label): $active = ($page===$key || ($key==='seo'&&$page==='seo-public') || ($page==='post-edit' && $key==='posts')) ? 'active':''; ?>

<a class="avsf-admin-nav-item <?php echo e(avsfClass($active)); ?>" href="index.php?page=<?php echo $key; ?>"><?php echo $label; ?></a>

<?php if($key==='dashboard'): ?>

<details class="avsf-admin-nav-accordion" <?php echo in_array($page,['posts','post-edit','post-new','categories','studios','actors','tags'],true)?'open':''; ?>>

<summary><?php _e('📝 โพสต์ทั้งหมด'); ?> <span class="avsf-admin-nav-dot"></span></summary>

<div class="avsf-admin-nav-submenu">

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass(in_array($page,['posts','post-edit'],true)?'avsf-active':'')); ?>" href="index.php?page=posts"><?php _e('รายการโพสต์'); ?></a>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='post-new'?'avsf-active':'')); ?>" href="index.php?page=post-new"><?php _e('เขียนโพสต์ใหม่'); ?></a>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='categories'?'avsf-active':'')); ?>" href="index.php?page=categories"><?php _e('หมวดหมู่'); ?></a>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='studios'?'avsf-active':'')); ?>" href="index.php?page=studios"><?php _e('ค่าย'); ?></a>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='actors'?'avsf-active':'')); ?>" href="index.php?page=actors"><?php _e('นักแสดง'); ?></a>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='tags'?'avsf-active':'')); ?>" href="index.php?page=tags"><?php _e('แท็ก'); ?></a>

</div></details>

<?php endif; ?>

<?php if($key==='seo'): ?>

<details class="avsf-admin-nav-accordion" <?php echo in_array($page,['seo','seo-public'],true)?'open':''; ?>>

<summary>🔍 SEO <span class="avsf-admin-nav-dot"></span></summary>

<div class="avsf-admin-nav-submenu">

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='seo'?'avsf-active':'')); ?>" href="index.php?page=seo">SEO META KEY</a>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page==='seo-public'?'avsf-active':'')); ?>" href="index.php?page=seo-public"><?php _e('SEO ในเว็บให้คนดูเห็น'); ?></a>

<?php if (can('seo.manage') || can('posts.export') || (isSuperAdmin() && can('backup.download'))): ?><a class="avsf-admin-nav-subitem" href="data-transfer.php"><?php _e('⇄ ศูนย์ส่งออก / นำเข้าข้อมูล'); ?></a><?php endif; ?>

</div></details>

<?php endif; ?>

<?php endforeach; ?>

<details class="avsf-admin-nav-accordion" <?php echo strpos($page,'settings')===0||$page==='backup'?'open':''; ?>>

<summary><?php _e('⚙️ ตั้งค่าระบบพื้นฐาน'); ?> <span class="avsf-admin-nav-dot"></span></summary>

<div class="avsf-admin-nav-submenu">

<?php $settingMenu=[['settings','ทั่วไป'],['settings-connectors','Connectors'],['settings-writing','การเขียน'],['settings-reading','การอ่าน'],['settings-card-layout','การแสดงผลการ์ด'],['settings-discussion','การสนทนา'],['settings-media','สื่อ'],['settings-permalinks','ลิงก์ถาวร'],['settings-privacy','ความเป็นส่วนตัว'],['settings-redis','Redis'],['settings-vdohide','VDOHide API']]; foreach($settingMenu as $entry): ?>

<a class="avsf-admin-nav-subitem <?php echo e(avsfClass($page===$entry[0]?'avsf-active':'')); ?>" href="index.php?page=<?php echo esc($entry[0]); ?>"><?php echo esc($entry[1]); ?></a>

<?php endforeach; ?>

</div></details>

<div style="height:1px;background:var(--border);margin:12px 8px;"></div>

<a class="avsf-admin-nav-item" href="index.php?logout=1" style="color:var(--danger);"><?php _e('🚪 ออกจากระบบ'); ?></a>

</aside>

<button class="avsf-admin-menu-backdrop" type="button" aria-label="<?php echo __('ปิดเมนู'); ?>"></button>

<main class="avsf-admin-main"><?php echo $content; ?></main>

</div>

<div id="lightbox" class="avsf-lightbox" onclick="this.classList.remove('avsf-active')"><button class="avsf-lightbox-close">×</button><img id="lightboxImg" src="" alt=""></div>

<script>

function openLightbox(src){document.getElementById('lightboxImg').src=src;document.getElementById('lightbox').classList.add('avsf-active');}

(function(){

  document.querySelectorAll('[data-dashboard-toggle]').forEach(function(button){

    button.addEventListener('click',function(){

      var content=document.getElementById(button.getAttribute('aria-controls'));

      if(!content)return;

      var expanded=button.getAttribute('aria-expanded')==='true';

      content.hidden=expanded;

      button.setAttribute('aria-expanded',expanded?'false':'true');

      var title=button.closest('.avsf-dashboard-widget-heading').querySelector('h2').textContent;

      button.setAttribute('aria-label',(expanded?'ขยาย ':'พับเก็บ ')+title);

      button.querySelector('span').textContent=expanded?'⌄':'⌃';

    });

  });

})();

(function(){

  var modal=document.getElementById('mediaModal');

  if(modal){

    window.openMediaDetails=function(button){

      var media=JSON.parse(button.getAttribute('data-media')||'{}');

      modal.hidden=false;

      document.getElementById('mediaModalImage').src=media.src||'';

      document.getElementById('mediaModalImage').alt=media.alt||'';

      document.getElementById('mediaModalUrl').textContent=media.src||'';

      document.getElementById('mediaModalDate').textContent=media.uploaded||'ไม่มีข้อมูลวันที่ในระบบ';

      document.getElementById('mediaSource').value=media.src||'';

      document.getElementById('mediaDeleteSource').value=media.src||'';

      document.getElementById('mediaTitle').value=media.title||'';

      document.getElementById('mediaAlt').value=media.alt||'';

      document.getElementById('mediaDescription').value=media.description||'';

      document.getElementById('mediaDeleteForm').hidden=!media.deletable;

      document.getElementById('mediaTitle').focus();

    };

    window.closeMediaDetails=function(){modal.hidden=true;};

    modal.addEventListener('click',function(event){if(event.target===modal)window.closeMediaDetails();});

    document.addEventListener('keydown',function(event){if(event.key==='Escape')window.closeMediaDetails();});

  }

})();

(function(){

  var form=document.getElementById('mediaBulkForm');

  if(!form)return;

  var boxes=Array.prototype.slice.call(document.querySelectorAll('.avsf-media-select-checkbox'));

  var selectAll=document.getElementById('mediaSelectAll'),count=document.getElementById('mediaSelectedCount');

  var update=function(){

    var selected=boxes.filter(function(box){return box.checked;}).length;

    count.textContent=<?php echo avsf_js(__('เลือกแล้ว ')); ?>+selected+' รูป';

    selectAll.checked=boxes.length>0&&selected===boxes.length;

    selectAll.indeterminate=selected>0&&!selectAll.checked;

  };

  boxes.forEach(function(box){box.addEventListener('change',update);});

  selectAll.addEventListener('change',function(){boxes.forEach(function(box){box.checked=selectAll.checked;});update();});

  form.addEventListener('submit',function(event){

    var selected=boxes.filter(function(box){return box.checked;});

    if(!selected.length||!window.confirm('ยืนยันลบรูปที่เลือก '+selected.length+' รูป? รูปที่ยังถูกใช้งานจะไม่ถูกลบ.')){event.preventDefault();return;}

    selected.forEach(function(box){var input=document.createElement('input');input.type='hidden';input.name='media_sources[]';input.value=box.value;form.appendChild(input);});

  });

  update();

})();

(function(){

  var layout=document.querySelector('.avsf-admin-layout');

  var toggle=document.querySelector('.avsf-admin-menu-toggle');

  var backdrop=document.querySelector('.avsf-admin-menu-backdrop');

  if(!layout||!toggle||!backdrop)return;

  function setMenuOpen(open){

    layout.classList.toggle('avsf-menu-open',open);

    toggle.setAttribute('aria-expanded',open?'true':'false');

    toggle.querySelector('[aria-hidden="true"]').textContent=open?'×':'☰';

    toggle.querySelectorAll('span')[1].textContent=open?'ปิดเมนู':'เมนู';

  }

  toggle.addEventListener('click',function(){setMenuOpen(!layout.classList.contains('avsf-menu-open'));});

  backdrop.addEventListener('click',function(){setMenuOpen(false);});

  layout.querySelectorAll('.avsf-admin-nav-item,.avsf-admin-nav-subitem').forEach(function(link){link.addEventListener('click',function(){setMenuOpen(false);});});

  document.addEventListener('keydown',function(event){if(event.key==='Escape')setMenuOpen(false);});

})();

(function(){

  var themeKey='avsf-admin-theme';

  var savedTheme=localStorage.getItem(themeKey);

  if(savedTheme==='light'||savedTheme==='dark'){document.body.setAttribute('data-admin-theme',savedTheme);try{document.documentElement.setAttribute('data-admin-theme',savedTheme);document.documentElement.className='avsf-theme-'+savedTheme;}catch(e){}}

  document.querySelectorAll('[data-admin-theme-toggle]').forEach(function(button){

    button.addEventListener('click',function(){

      var next=document.body.getAttribute('data-admin-theme')==='light'?'dark':'light';

      document.body.setAttribute('data-admin-theme',next);

      try{document.documentElement.setAttribute('data-admin-theme',next);document.documentElement.className='avsf-theme-'+next;}catch(e){}
      localStorage.setItem(themeKey,next);

    });

  });

})();

(function(){

  var details=document.getElementById('post-keyword-notes');

  if(!details)return;

  var form=details.querySelector('[data-keyword-form]');

  var read=details.querySelector('[data-keyword-read]');

  var editor=details.querySelector('[data-keyword-editor]');

  var editButton=details.querySelector('[data-keyword-edit]');

  var cancelButton=details.querySelector('[data-keyword-cancel]');

  var notify=function(message,isError){

    var old=document.querySelector('.avsf-toast');

    if(old)old.remove();

    var toast=document.createElement('div');

    toast.className='avsf-toast';

    toast.textContent=message;

    if(isError)toast.style.background='#b4233a';

    document.body.appendChild(toast);

    window.setTimeout(function(){toast.remove();},2600);

  };

  var setEditing=function(enabled){

    form.classList.toggle('avsf-is-editing',enabled);

    editor.hidden=!enabled;

    editButton.hidden=enabled;

    read.hidden=enabled;

    if(enabled)details.querySelector('#primaryKeywordNotes').focus();

  };

  details.addEventListener('toggle',function(){document.body.style.overflow=details.open?'hidden':'';});

  editButton.addEventListener('click',function(){setEditing(true);});

  cancelButton.addEventListener('click',function(){form.reset();setEditing(false);});

  details.addEventListener('click',function(event){

    var chip=event.target.closest('[data-copy-keyword]');

    if(!chip)return;

    var keyword=chip.getAttribute('data-copy-keyword')||'';

    var fallbackCopy=function(){

      var helper=document.createElement('textarea');

      helper.value=keyword;

      helper.setAttribute('readonly','');

      helper.style.position='fixed';

      helper.style.opacity='0';

      document.body.appendChild(helper);

      helper.select();

      var copied=false;

      try{copied=document.execCommand('copy');}catch(error){copied=false;}

      helper.remove();

      if(copied)notify('คัดลอก “'+keyword+'” แล้ว');

      else notify(<?php echo avsf_js(__('คัดลอกไม่สำเร็จ กรุณาเลือกคีย์แล้วคัดลอกด้วยตนเอง')); ?>,true);

    };

    if(navigator.clipboard&&navigator.clipboard.writeText){

      navigator.clipboard.writeText(keyword).then(function(){notify('คัดลอก “'+keyword+'” แล้ว');}).catch(fallbackCopy);

    } else fallbackCopy();

  });

  document.addEventListener('keydown',function(event){

    if(event.key==='Escape'&&details.open){

      if(form.classList.contains('avsf-is-editing')){form.reset();setEditing(false);}

      else details.open=false;

      details.querySelector('summary').focus();

    }

  });

})();

(function(){

  var page=document.querySelector('[data-posts-page]');

  if(!page)return;

  var buttons=page.querySelectorAll('[data-post-view]');

  var panels=page.querySelectorAll('[data-post-view-panel]');

  var setView=function(view){

    if(!Array.from(page.querySelectorAll('[data-post-view-panel]')).some(function(panel){return panel.getAttribute('data-post-view-panel')===view;}))view='normal';

    buttons.forEach(function(button){button.setAttribute('aria-pressed',button.getAttribute('data-post-view')===view?'true':'false');});

    panels.forEach(function(panel){panel.classList.toggle('avsf-is-active',panel.getAttribute('data-post-view-panel')===view);});

    try{localStorage.setItem('avsf-posts-view',view);}catch(error){}

  };

  buttons.forEach(function(button){button.addEventListener('click',function(){setView(button.getAttribute('data-post-view'));});});

  var savedView='normal';

  try{savedView=localStorage.getItem('avsf-posts-view')||'normal';}catch(error){}

  setView(savedView);

})();

(function(){

  var page=document.querySelector('[data-posts-page]'),form=page&&page.querySelector('[data-bulk-post-form]');

  if(!form)return;

  var getCheckboxes=function(){return Array.from(page.querySelectorAll('.avsf-post-select-checkbox'));};

  var getSelected=function(){return Array.from(new Set(getCheckboxes().filter(function(box){return box.checked;}).map(function(box){return box.value;})));};

  var update=function(){

    var checkboxes=getCheckboxes(),selected=getSelected(),selectAll=form.querySelector('[data-select-all]');

    form.querySelector('[data-selected-count]').textContent=<?php echo avsf_js(__('เลือกแล้ว ')); ?>+selected.length+' รายการ';

    selectAll.checked=checkboxes.length>0&&selected.length===new Set(checkboxes.map(function(box){return box.value;})).size;

    selectAll.indeterminate=selected.length>0&&!selectAll.checked;

  };

  getCheckboxes().forEach(function(box){

    box.addEventListener('change',function(){

      getCheckboxes().filter(function(other){return other.value===box.value;}).forEach(function(other){other.checked=box.checked;});

      update();

    });

  });

  form.querySelector('[data-select-all]').addEventListener('change',function(){

    getCheckboxes().forEach(function(box){box.checked=this.checked;},this);

    update();

  });

  form.addEventListener('submit',function(event){

    var selected=getSelected(),operation=form.querySelector('[name="bulk_operation"]').value;

    if(!selected.length||!operation){event.preventDefault();window.alert(!selected.length?'กรุณาเลือกโพสต์อย่างน้อย 1 รายการ':'กรุณาเลือกคำสั่งที่ต้องการ');return;}

    var labels={publish:'เผยแพร่',draft:'เปลี่ยนเป็นฉบับร่าง',delete:'ลบ'};

    if(!window.confirm('ยืนยัน'+labels[operation]+' '+selected.length+' โพสต์ที่เลือก?')){event.preventDefault();return;}

    form.querySelectorAll('[data-bulk-post-id]').forEach(function(input){input.remove();});

    selected.forEach(function(id){

      var input=document.createElement('input');input.type='hidden';input.name='post_ids[]';input.value=id;input.setAttribute('data-bulk-post-id','');

      form.appendChild(input);

    });

  });

  update();

})();

(function(){

  var form=document.querySelector('.avsf-editor-form');if(!form)return;

  var uploadNotice=form.querySelector('[data-upload-validation]');
  if(!uploadNotice){
    uploadNotice=document.createElement('p');
    uploadNotice.className='avsf-msg avsf-error';
    uploadNotice.setAttribute('data-upload-validation','');
    uploadNotice.setAttribute('role','alert');
    uploadNotice.setAttribute('aria-live','assertive');
    uploadNotice.tabIndex=-1;
    uploadNotice.hidden=true;
    form.insertBefore(uploadNotice,form.firstChild);
  }
  var validateEditorUploads=function(){
    var oversized=[];
    form.querySelectorAll('input[type="file"]').forEach(function(input){
      Array.prototype.forEach.call(input.files||[],function(file){
        if(file.size>5*1024*1024)oversized.push(file.name+' ('+(file.size/1024/1024).toFixed(1)+' MB)');
      });
    });
    if(!oversized.length){uploadNotice.hidden=true;return true;}
    uploadNotice.textContent=<?php echo avsf_js(__('บันทึกไม่ได้: รูปต่อไปนี้เกิน 5 MB ต่อรูป: ')); ?>+oversized.join(', ')+'. กรุณานำไฟล์เหล่านี้ออกจากช่องเลือก แล้วเลือกไฟล์ที่เล็กกว่า 5 MB ก่อนบันทึกอีกครั้ง';
    uploadNotice.hidden=false;
    uploadNotice.focus({preventScroll:true});
    uploadNotice.scrollIntoView({behavior:'smooth',block:'center'});
    return false;
  };
  form.querySelectorAll('input[type="file"]').forEach(function(input){input.addEventListener('change',validateEditorUploads);});
  form.addEventListener('submit',function(event){if(!validateEditorUploads())event.preventDefault();});

  

  form.querySelectorAll('.avsf-form-group input:not([type]):not([readonly]),.avsf-form-group input[type="text"]:not([readonly]),.avsf-form-group input[type="search"]:not([readonly]),.avsf-form-group input[type="url"]:not([readonly]),.avsf-form-group input[type="email"]:not([readonly]),.avsf-form-group input[type="tel"]:not([readonly]),.avsf-form-group input[type="number"]:not([readonly]),.avsf-form-group input[type="file"]:not([readonly]),.avsf-form-group textarea:not([readonly])').forEach(function(field){

    if(field.classList.contains('avsf-taxonomy-input')||field.closest('.avsf-clearable-control')||field.readOnly||field.disabled)return;

    var wrapper=document.createElement('div');wrapper.className='avsf-clearable-control';

    field.parentNode.insertBefore(wrapper,field);wrapper.appendChild(field);

    var clear=document.createElement('button');clear.type='button';clear.className='avsf-clear-field-button';clear.textContent='×';

    var group=field.closest('.avsf-form-group'),label=group?group.querySelector('label'):null;

    clear.setAttribute('aria-label','ล้าง'+(label?label.textContent.trim():'ข้อมูลในช่องนี้'));clear.title=<?php echo avsf_js(__('ล้างข้อมูลในช่องนี้')); ?>;

    field.style.paddingRight='42px';

    var updateClearState=function(){clear.hidden=field.type==='file'?!field.files||field.files.length===0:field.value==='';};

    field.addEventListener('input',updateClearState);field.addEventListener('change',updateClearState);

    clear.addEventListener('click',function(){

      if(field.type==='file')field.value='';

      else field.value='';

      field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));

      updateClearState();field.focus();

    });

    wrapper.appendChild(clear);updateClearState();

  });

  var galleryUrls=form.querySelector('#galleryUrls'),galleryUpload=form.querySelector('#galleryUpload'),galleryPreview=form.querySelector('#editorGalleryPreview'),galleryObjectUrls=[];

  form.querySelectorAll('textarea[data-autogrow]').forEach(function(textarea){
    var resizeTextarea=function(){
      textarea.style.height='auto';
      var maxHeight=420;
      textarea.style.height=Math.min(textarea.scrollHeight,maxHeight)+'px';
      textarea.style.overflowY=textarea.scrollHeight>maxHeight?'auto':'hidden';
    };
    textarea.addEventListener('input',resizeTextarea);
    resizeTextarea();
  });

  var titleInput=form.querySelector('textarea[data-title-autogrow]');
  if(titleInput){
    var resizeTitle=function(){
      titleInput.style.height='auto';
      titleInput.style.height=Math.max(39,titleInput.scrollHeight)+'px';
    };
    titleInput.addEventListener('input',resizeTitle);
    titleInput.addEventListener('keydown',function(event){if(event.key==='Enter')event.preventDefault();});
    resizeTitle();
  }

  var updateGalleryPreview=function(){

    galleryObjectUrls.forEach(function(url){URL.revokeObjectURL(url);});

    galleryObjectUrls=[];

    galleryPreview.replaceChildren();

    var lines=galleryUrls.value.split(/\r\n|\r|\n/),sources=[];

    lines.forEach(function(line,index){
      var url=line.trim();
      if(url)sources.push({url:url,type:'url',index:index});
    });

    Array.prototype.forEach.call(galleryUpload.files||[],function(file,index){
      var objectUrl=URL.createObjectURL(file);galleryObjectUrls.push(objectUrl);sources.push({url:objectUrl,type:'file',index:index});
    });

    if(!sources.length){
      var empty=document.createElement('div');empty.className='avsf-editor-gallery-empty';empty.textContent=<?php echo avsf_js(__('ภาพแกลเลอรี่จะแสดงตัวอย่างที่นี่')); ?>;galleryPreview.appendChild(empty);return;
    }

    sources.forEach(function(source){
      var item=document.createElement('div');item.className='avsf-editor-gallery-item';
      var image=document.createElement('img');image.src=source.url;image.alt='';image.loading='lazy';
      image.addEventListener('error',function(){item.remove();});
      var remove=document.createElement('button');remove.type='button';remove.className='avsf-editor-gallery-remove';remove.textContent='×';remove.setAttribute('aria-label',<?php echo avsf_js(__('ลบภาพนี้ออกจากแกลเลอรี่')); ?>);remove.title=<?php echo avsf_js(__('ลบภาพนี้ออกจากรายการ')); ?>;
      remove.addEventListener('click',function(){
        if(source.type==='url'){
          var currentLines=galleryUrls.value.split(/\r\n|\r|\n/);
          currentLines.splice(source.index,1);
          galleryUrls.value=currentLines.join('\n');
          galleryUrls.dispatchEvent(new Event('input',{bubbles:true}));
          return;
        }
        var transfer=new DataTransfer();
        Array.prototype.forEach.call(galleryUpload.files||[],function(file,index){if(index!==source.index)transfer.items.add(file);});
        galleryUpload.files=transfer.files;
        galleryUpload.dispatchEvent(new Event('change',{bubbles:true}));
      });
      item.appendChild(image);item.appendChild(remove);galleryPreview.appendChild(item);
    });
  };;

  if(galleryUrls&&galleryUpload&&galleryPreview){

    galleryUrls.addEventListener('input',updateGalleryPreview);

    galleryUpload.addEventListener('change',updateGalleryPreview);

    updateGalleryPreview();

  }

  var videoField=form.querySelector('#videoUrlInput'),videoPreview=document.getElementById('videoInputPreview');

  if(videoField&&videoPreview){

    var updateVideoPreview=function(){

      var source=videoField.value.trim(),frameSource=source,match;

      if(source.indexOf('<iframe')!==-1){

        match=source.match(/^\s*<iframe\b[^>]*\bsrc\s*=\s*(["'])(.*?)\1[^>]*>\s*(?:<\/iframe\s*>)?\s*$/i);

        frameSource=match?match[2]:'';

      } else if(/^[A-Za-z0-9_-]{5,120}$/.test(source)){frameSource=(window.AVSF_PLAYER_DOMAIN||'')+'/embed/'+source+'/';}

      var target;

      try{target=new URL(frameSource.indexOf('//')===0?'https:'+frameSource:frameSource,location.href);}catch(e){target=null;}

      videoPreview.replaceChildren();

      if(!source){videoPreview.textContent=<?php echo avsf_js(__('ตัวอย่างเครื่องเล่นจะแสดงที่นี่')); ?>;return;}

      if(!target||(target.protocol!=='http:'&&target.protocol!=='https:')){videoPreview.textContent=<?php echo avsf_js(__('ลิงก์หรือ iframe ยังไม่ถูกต้อง')); ?>;return;}

      var youtube=target.href.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i);

      if(youtube)target=new URL('https://www.youtube.com/embed/'+youtube[1]);

      else if(target.hostname.toLowerCase()==='drive.google.com')target=new URL(target.href.replace(/\/view(?:\?.*)?$/,'/preview').replace(/([?&])usp=[^&]+/,''));

      else if(/(^|\.)fembed\.(co|com|net)$/i.test(target.hostname)){

        var fembed=target.pathname.match(/\/(?:(?:embed|v|f)\/)?([A-Za-z0-9_-]{5,160})\/?$/i);

        if(fembed)target=new URL((window.AVSF_PLAYER_DOMAIN||'https://player.example.com')+'/embed/'+fembed[1]+'/');

      } else if(window.AVSF_PLAYER_DOMAIN){try{var ph=new URL(window.AVSF_PLAYER_DOMAIN).hostname;if(target.hostname.toLowerCase()===ph){var pm=target.pathname.match(/\/(?:embed\/)?([A-Za-z0-9_-]{5,160})(?:\/|$)/i);if(pm)target=new URL(window.AVSF_PLAYER_DOMAIN+'/embed/'+pm[1]);}}catch(e){}

      }

      var media=document.createElement(/\.(mp4|webm|ogg)(?:$|\?)/i.test(target.href)?'video':'iframe');

      media.className=media.tagName==='VIDEO'?'avsf-video-preview-media':'avsf-video-preview-frame';

      if(media.tagName==='VIDEO'){media.controls=true;media.playsInline=true;media.preload='metadata';media.src=target.href;}

      else{media.src=target.href;media.title=<?php echo avsf_js(__('ตัวอย่างเครื่องเล่นวิดีโอ')); ?>;media.loading='lazy';media.referrerPolicy='strict-origin-when-cross-origin';media.allow='autoplay; fullscreen; encrypted-media; picture-in-picture';}

      videoPreview.appendChild(media);

    };

    videoField.addEventListener('input',updateVideoPreview);

    updateVideoPreview();

  }

  var durationField=document.getElementById('videoDuration'),durationFormatted=document.getElementById('videoDurationFormatted');

  if(durationField&&durationFormatted){

    var updateDurationFormat=function(){

      var value=durationField.value.trim(),total=null,parts=value.match(/^([0-9]+):([0-5]?[0-9])(?::([0-5]?[0-9]))?$/); 

      if(/^\d+$/.test(value)){total=parseInt(value,10);}
      else if(parts){total=parts[3]!==undefined?parseInt(parts[1],10)*3600+parseInt(parts[2],10)*60+parseInt(parts[3],10):parseInt(parts[1],10)*60+parseInt(parts[2],10);}
      else{var iso=value.match(/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i);if(iso){total=(parseInt(iso[1]||'0',10)*3600)+(parseInt(iso[2]||'0',10)*60)+parseInt(iso[3]||'0',10);}}

      if(total===null||!Number.isFinite(total)||total<0){durationFormatted.value='';return;}

      durationFormatted.value=String(Math.floor(total/3600)).padStart(2,'0')+':'+String(Math.floor((total%3600)/60)).padStart(2,'0')+':'+String(total%60).padStart(2,'0');

    };

    durationField.addEventListener('input',updateDurationFormat);updateDurationFormat();

  }

  var coverPreview=document.getElementById('editorCoverPreview'),coverPreviewImage=document.getElementById('editorCoverPreviewImage');

  var coverField=form.querySelector('[name="cover"]'),posterField=form.querySelector('[name="poster"]');

  var coverUpload=form.querySelector('[name="coverUpload"]'),posterUpload=form.querySelector('[name="posterUpload"]');

  var coverObjectUrl='';

  if(coverPreview&&coverPreviewImage){

    var updateCoverPreview=function(){

      if(coverObjectUrl){URL.revokeObjectURL(coverObjectUrl);coverObjectUrl='';}

      var selectedFile=(coverUpload&&coverUpload.files&&coverUpload.files[0])||(posterUpload&&posterUpload.files&&posterUpload.files[0]);

      var source='';

      if(selectedFile){coverObjectUrl=URL.createObjectURL(selectedFile);source=coverObjectUrl;}

      else source=(coverField&&coverField.value.trim())||(posterField&&posterField.value.trim())||'';

      if(!source){coverPreview.hidden=true;coverPreviewImage.removeAttribute('src');return;}

      if(!selectedFile){

        try{

          var imageUrl=new URL(source,location.href);

          if(imageUrl.protocol!=='http:'&&imageUrl.protocol!=='https:'){coverPreview.hidden=true;return;}

          source=imageUrl.href;

        }catch(error){coverPreview.hidden=true;return;}

      }

      coverPreviewImage.onload=function(){coverPreview.hidden=false;};

      coverPreviewImage.onerror=function(){coverPreview.hidden=true;};

      coverPreviewImage.src=source;

    };

    [coverField,posterField,coverUpload,posterUpload].forEach(function(input){

      if(input){input.addEventListener(input.type==='file'?'change':'input',updateCoverPreview);}

    });

    updateCoverPreview();

  }

  form.querySelectorAll('[data-taxonomy-picker]').forEach(function(picker){

    var type=picker.getAttribute('data-taxonomy-picker');

    var source=form.querySelector('[data-preview="'+type+'"]');

    var selected=picker.querySelector('.avsf-taxonomy-selected');

    var input=picker.querySelector('.avsf-taxonomy-input');

    var suggestions=picker.querySelector('.avsf-taxonomy-suggestions');

    var customField=form.querySelector('[name="new'+(type==='actors'?'Actor':'Tag')+'Names"]');

    var customNames=[];

    var pickerInitialized=false;

    try{customNames=JSON.parse(customField.value||'[]');}catch(error){customNames=[];}

    if(!Array.isArray(customNames))customNames=[];

    var normalize=function(text){return text.trim().toLocaleLowerCase();};

    var syncPicker=function(){

      customField.value=JSON.stringify(customNames);

      selected.replaceChildren();

      Array.prototype.forEach.call(source.options,function(option){

        if(!option.selected)return;

        var chip=document.createElement('span');chip.className='avsf-taxonomy-chip';

        var name=document.createElement('span');name.textContent=option.textContent;

        var remove=document.createElement('button');remove.type='button';remove.setAttribute('aria-label','นำ '+option.textContent+' ออก');remove.textContent='×';

        remove.addEventListener('click',function(){option.selected=false;syncPicker();form.dispatchEvent(new Event('change',{bubbles:true}));});

        chip.appendChild(name);chip.appendChild(remove);selected.appendChild(chip);

      });

      customNames.forEach(function(term,index){

        var chip=document.createElement('span');chip.className='avsf-taxonomy-chip avsf-is-custom';

        var name=document.createElement('span');name.textContent=term;

        var label=document.createElement('small');label.textContent=<?php echo avsf_js(__('เพิ่มเอง')); ?>;

        var remove=document.createElement('button');remove.type='button';remove.setAttribute('aria-label','นำ '+term+' ออก');remove.textContent='×';

        remove.addEventListener('click',function(){customNames.splice(index,1);syncPicker();form.dispatchEvent(new Event('change',{bubbles:true}));});

        chip.appendChild(name);chip.appendChild(label);chip.appendChild(remove);selected.appendChild(chip);

      });

      if(type==='actors'){

        var actorNameField=form.querySelector('[name="actorEN"]');

        var actorNames=Array.prototype.filter.call(source.options,function(option){return option.selected;}).map(function(option){return option.textContent;}).concat(customNames);

        if(actorNameField){

          if(actorNames.length)actorNameField.value=actorNames.join(', ');

          else if(pickerInitialized)actorNameField.value='';

        }

      }

      pickerInitialized=true;

    };

    var hideSuggestions=function(){if(suggestions)suggestions.hidden=true;input.setAttribute('aria-expanded','false');};

    var addSingleTerm=function(term){

      term=term.trim();if(!term)return;

      var match=null;

      Array.prototype.some.call(source.options,function(option){if(normalize(option.textContent)===normalize(term)){match=option;return true;}return false;});

      if(match)match.selected=true;

      else if(!customNames.some(function(name){return normalize(name)===normalize(term);}))customNames.push(term);

    };

    var addTerm=function(term){

      (String(term).split(/[,،;|，；\r\n]+/).map(function(part){return part.trim();}).filter(Boolean)).forEach(addSingleTerm);

      input.value='';hideSuggestions();syncPicker();form.dispatchEvent(new Event('change',{bubbles:true}));

    };

    var showSuggestions=function(){

      if(!suggestions)return;

      var query=normalize(input.value);

      suggestions.replaceChildren();

      var matches=Array.prototype.filter.call(source.options,function(option){return !option.selected&&(!query||normalize(option.textContent).indexOf(query)!==-1);});

      matches.sort(function(a,b){return parseInt(b.getAttribute('data-count')||'0',10)-parseInt(a.getAttribute('data-count')||'0',10)||a.textContent.localeCompare(b.textContent);});

      matches.slice(0,12).forEach(function(option){

        var button=document.createElement('button');button.type='button';button.className='avsf-taxonomy-suggestion';

        var name=document.createElement('span');name.textContent=option.textContent;

        var count=document.createElement('small');count.textContent=(option.getAttribute('data-count')||'0')+' เรื่อง';

        button.appendChild(name);button.appendChild(count);

        button.addEventListener('click',function(){addTerm(option.textContent);});

        suggestions.appendChild(button);

      });

      suggestions.hidden=suggestions.childElementCount===0;

      input.setAttribute('aria-expanded',suggestions.hidden?'false':'true');

    };

    input.addEventListener('input',showSuggestions);

    input.addEventListener('focus',showSuggestions);

    input.addEventListener('keydown',function(event){

      if(event.key==='Enter'){event.preventDefault();addTerm(input.value);}

      else if(event.key==='Escape')hideSuggestions();

    });

    input.addEventListener('blur',function(){window.setTimeout(hideSuggestions,120);});
    document.addEventListener('click',function(event){if(!picker.contains(event.target))hideSuggestions();});

    picker.parentElement.querySelectorAll('.avsf-taxonomy-recommendation[data-term-id]').forEach(function(button){

      button.addEventListener('click',function(){addTerm(button.getAttribute('data-term-name')||'');});

    });

    syncPicker();

    source.addEventListener('change',syncPicker);

  });

  var setupTaxonomyAutocomplete=function(inputId,optionsId,suggestionsId){

    var input=form.querySelector('#'+inputId),source=form.querySelector('#'+optionsId),suggestions=form.querySelector('#'+suggestionsId);

    if(!input||!source||!suggestions)return;

    var activeIndex=-1;

    var normalize=function(text){return text.trim().toLocaleLowerCase();};

    var close=function(){suggestions.hidden=true;input.setAttribute('aria-expanded','false');activeIndex=-1;};

    var appendValue=function(value){
      value=(value||'').trim();
      if(!value)return;
    var raw=input.value.trim();
    var hasSeparator=/[,،;|，；\r\n]/u.test(raw);
    var parts=raw.split(/[,،;|，；\r\n]+/u).map(function(part){return part.trim();}).filter(Boolean);
    var currentPart=parts.length?parts[parts.length-1]:'';
    var canKeepCurrent=hasSeparator || normalize(raw)===normalize(value);
    if(!canKeepCurrent && parts.length===1 && normalize(raw).indexOf(normalize(value))!==-1)parts=[];
    if(!canKeepCurrent && parts.length===1 && normalize(value).indexOf(normalize(raw))!==-1 && raw)parts=[];
    var exists=parts.some(function(part){return normalize(part)===normalize(value);});
    if(!exists)parts.push(value);
    input.value=parts.join(', ');
    };
    var choose=function(value){appendValue(value);close();input.dispatchEvent(new Event('input',{bubbles:true}));};

    var render=function(){

      var query=normalize(input.value);

      var options=Array.prototype.filter.call(source.options,function(option){

        return !query||normalize(option.value).indexOf(query)!==-1;

      });

      options.sort(function(a,b){

        return parseInt(b.getAttribute('data-count')||'0',10)-parseInt(a.getAttribute('data-count')||'0',10)||a.value.localeCompare(b.value);

      });

      suggestions.replaceChildren();

      options.slice(0,12).forEach(function(option){

        var button=document.createElement('button');button.type='button';button.className='avsf-taxonomy-suggestion';

        var name=document.createElement('span');name.textContent=option.value;

        var count=document.createElement('small');count.textContent=(option.getAttribute('data-count')||'0')+' เรื่อง';

        button.appendChild(name);button.appendChild(count);

        button.addEventListener('mousedown',function(event){event.preventDefault();});

        button.addEventListener('click',function(){choose(option.value);});

        suggestions.appendChild(button);

      });

      suggestions.hidden=suggestions.childElementCount===0;

      input.setAttribute('aria-expanded',suggestions.hidden?'false':'true');

      activeIndex=-1;

    };

    var activate=function(index){

      var options=suggestions.querySelectorAll('.avsf-taxonomy-suggestion:not(.avsf-is-create)');

      if(!options.length)return;

      activeIndex=(index+options.length)%options.length;

      Array.prototype.forEach.call(options,function(option,itemIndex){

        option.classList.toggle('avsf-is-active',itemIndex===activeIndex);

      });

    };

    input.addEventListener('input',render);

    input.addEventListener('focus',render);

    input.addEventListener('keydown',function(event){

      if(event.key==='ArrowDown'&&!suggestions.hidden){event.preventDefault();activate(activeIndex+1);}

      else if(event.key==='ArrowUp'&&!suggestions.hidden){event.preventDefault();activate(activeIndex<0?0:activeIndex-1);}

      else if(event.key==='Enter'){

        event.preventDefault();

        var options=suggestions.querySelectorAll('.avsf-taxonomy-suggestion:not(.avsf-is-create)');

        var query=normalize(input.value);

        var exact=Array.prototype.find.call(source.options,function(option){return normalize(option.value)===query;});

        if(activeIndex>=0&&options[activeIndex])choose(options[activeIndex].querySelector('span').textContent);

        else if(exact)choose(exact.value);

        else if(query){choose(input.value.trim());}

      }else if(event.key==='Escape')close();

    });

    input.addEventListener('blur',function(){window.setTimeout(close,120);});
    document.addEventListener('click',function(event){if(!input.contains(event.target)&&!suggestions.contains(event.target))close();});

  };

  var contentSource=form.querySelector('#postContentEditor'),contentEditor=form.querySelector('#postContentVisual'),editorToolbar=form.querySelector('.avsf-rich-editor-toolbar'),savedEditorRange=null;
  if(contentSource&&contentEditor&&editorToolbar){
    var allowedEditorTags=new Set('A ABBR B BLOCKQUOTE BR CAPTION CODE DEL DIV EM FONT H1 H2 H3 H4 H5 H6 HR I IMG KBD LI OL P PRE S SMALL SPAN STRONG SUB SUP TABLE TBODY TD TH THEAD TR U UL'.split(' '));
    var blockedEditorTags=new Set('SCRIPT STYLE OBJECT EMBED FORM INPUT BUTTON META LINK BASE'.split(' '));
    var safeEditorUrl=function(value,image){try{var parsed=new URL(value,location.href);if(!['http:','https:'].includes(parsed.protocol))return '';if(!image&&parsed.username)return '';return value;}catch(error){return '';}};
    var cleanEditorNode=function(node,target){
      if(node.nodeType===Node.TEXT_NODE){target.appendChild(document.createTextNode(node.nodeValue||''));return;}
      if(node.nodeType===Node.COMMENT_NODE){if((node.nodeValue||'').trim()==='more')target.appendChild(document.createComment('more'));return;}
      if(node.nodeType!==Node.ELEMENT_NODE)return;
      var tag=node.tagName;
      if(blockedEditorTags.has(tag))return;
      if(tag==='IFRAME'){
        var src=safeEditorUrl(node.getAttribute('src')||'',false),host='';
        try{host=new URL(src,location.href).hostname.toLowerCase();}catch(error){}
        if(src&&(/(^|\.)youtube(-nocookie)?\.com$/.test(host)||host==='youtu.be'||host==='player.vimeo.com')){
          var frame=document.createElement('iframe');frame.src=src;frame.title=(node.getAttribute('title')||'Embedded video').slice(0,200);frame.setAttribute('loading','lazy');frame.setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation');frame.setAttribute('allowfullscreen','');target.appendChild(frame);
        }
        return;
      }
      if(!allowedEditorTags.has(tag)){Array.from(node.childNodes).forEach(function(child){cleanEditorNode(child,target);});return;}
      var clean=document.createElement(tag==='FONT'?'span':tag.toLowerCase());
      Array.from(node.attributes).forEach(function(attribute){
        var name=attribute.name.toLowerCase(),value=attribute.value;
        if(name.indexOf('on')===0||name==='srcdoc'||name==='contenteditable')return;
        if(name==='style'){
          var styles=node.style;
          ['color','background-color','font-size','font-weight','font-style','text-decoration','text-align'].forEach(function(property){
            var styleValue=styles.getPropertyValue(property).trim();
            if(!styleValue||/url\s*\(|expression|[<>]/i.test(styleValue))return;
            if((property==='color'||property==='background-color')&&window.CSS&&CSS.supports&&!CSS.supports('color',styleValue))return;
            if(property==='text-align'&&!/^(left|right|center|justify|start|end)$/.test(styleValue))return;
            clean.style.setProperty(property,styleValue);
          });
          return;
        }
        if(tag==='FONT'&&name==='color'&&window.CSS&&CSS.supports&&CSS.supports('color',value))clean.style.color=value;
        if(tag==='A'&&name==='href'){var href=safeEditorUrl(value,false);if(href)clean.setAttribute('href',href);return;}
        if(tag==='IMG'&&name==='src'){var imageSrc=safeEditorUrl(value,true);if(imageSrc)clean.setAttribute('src',imageSrc);return;}
        if(tag==='IMG'&&name==='alt'){clean.setAttribute('alt',value.slice(0,500));return;}
        if((tag==='A'&&name==='target'&&value==='_blank')||(tag==='IMG'&&['title','width','height'].includes(name))||(['TD','TH'].includes(tag)&&['colspan','rowspan'].includes(name))||(tag==='OL'&&name==='start'&&/^\d{1,4}$/.test(value)))clean.setAttribute(name,value.slice(0,100));
      });
      if(tag==='A'&&clean.getAttribute('target')==='_blank')clean.setAttribute('rel','noopener noreferrer');
      Array.from(node.childNodes).forEach(function(child){cleanEditorNode(child,clean);});
      target.appendChild(clean);
    };
    var sanitizeEditorHtml=function(html){var parsed=new DOMParser().parseFromString('<div>'+html+'</div>','text/html'),container=document.createElement('div');Array.from(parsed.body.firstChild.childNodes).forEach(function(node){cleanEditorNode(node,container);});return container.innerHTML;};
    var saveEditorRange=function(){
      var selection=window.getSelection();
      if(selection&&selection.rangeCount&&contentEditor.contains(selection.anchorNode))savedEditorRange=selection.getRangeAt(0).cloneRange();
    };
    var restoreEditorRange=function(){
      contentEditor.focus();
      var selection=window.getSelection();
      if(savedEditorRange&&contentEditor.contains(savedEditorRange.commonAncestorContainer)){selection.removeAllRanges();selection.addRange(savedEditorRange);}
      else if(selection){selection.selectAllChildren(contentEditor);selection.collapseToEnd();}
    };
    var syncEditorContent=function(){
      contentSource.value=contentEditor.innerHTML;
      contentSource.dispatchEvent(new Event('input',{bubbles:true}));
      saveEditorRange();
    };
    var applyEditorStyle=function(property,value){
      restoreEditorRange();
      var selection=window.getSelection();
      if(!selection||!selection.rangeCount||selection.isCollapsed){editorMessage('เลือกข้อความก่อนใช้สี');return;}
      document.execCommand(property==='color'?'foreColor':'hiliteColor',false,value);
      syncEditorContent();
    };
    var editorMessage=function(message){var status=editorToolbar.querySelector('[data-rich-status]');if(status)status.textContent=message;};
    var selectedEditorText=function(){var selection=window.getSelection();return selection&&selection.rangeCount&&contentEditor.contains(selection.anchorNode)?selection.toString():'';};
    var insertEditorHtml=function(html){restoreEditorRange();document.execCommand('insertHTML',false,html);syncEditorContent();};
    contentEditor.innerHTML=sanitizeEditorHtml(contentSource.value);
    contentSource.value=contentEditor.innerHTML;
    contentSource.classList.add('avsf-rich-editor-source');
    contentSource.setAttribute('aria-hidden','true');
    contentEditor.hidden=false;
    document.addEventListener('selectionchange',saveEditorRange);
    contentEditor.addEventListener('input',syncEditorContent);
    contentEditor.addEventListener('paste',function(event){
      var clipboard=event.clipboardData;if(!clipboard)return;
      event.preventDefault();
      var html=clipboard.getData('text/html'),text=clipboard.getData('text/plain');
      if(html)insertEditorHtml(sanitizeEditorHtml(html));else document.execCommand('insertText',false,text);
      syncEditorContent();
    });
    editorToolbar.querySelectorAll('button[data-rich-action]').forEach(function(button){
      button.addEventListener('mousedown',function(event){event.preventDefault();saveEditorRange();});
      button.addEventListener('click',function(){
        var action=button.getAttribute('data-rich-action'),command=action;
        if(action==='keyboardHelp'){window.alert('ปุ่มลัดที่ใช้ได้:\\nCtrl+B ตัวหนา\\nCtrl+I ตัวเอียง\\nCtrl+K แทรกลิงก์\\nCtrl+Z ย้อนกลับ\\nCtrl+Y ทำซ้ำ');return;}
        if(action==='specialCharacter'){var panel=editorToolbar.querySelector('[data-rich-specials]');panel.hidden=!panel.hidden;return;}
        if(action==='fullscreen'){var card=form.querySelector('.avsf-editor-content-card');card.classList.toggle('avsf-is-rich-fullscreen');button.setAttribute('aria-pressed',card.classList.contains('avsf-is-rich-fullscreen')?'true':'false');return;}
        restoreEditorRange();
        if(action==='blockquote'){document.execCommand('formatBlock',false,'blockquote');}
        else if(action==='insertMore'){
          var selection=window.getSelection(),range=selection&&selection.rangeCount?selection.getRangeAt(0):null;
          if(range){range.collapse(false);range.insertNode(document.createComment('more'));range.collapse(false);selection.removeAllRanges();selection.addRange(range);}
        }else if(action==='createLink'){
          var url=window.prompt('ใส่ URL ของลิงก์','https://');
          if(url){
            var safeUrl=safeEditorUrl(url.trim(),false);
            if(!safeUrl)editorMessage('URL ไม่ถูกต้องหรือไม่ปลอดภัย');
            else if(selectedEditorText())document.execCommand('createLink',false,safeUrl);
            else{
              var linkText=window.prompt('ข้อความที่ต้องการแสดงเป็นลิงก์',safeUrl);
              if(linkText){var selection=window.getSelection(),range=selection.getRangeAt(0),anchor=document.createElement('a');anchor.href=safeUrl;anchor.textContent=linkText;range.insertNode(anchor);range.selectNodeContents(anchor);selection.removeAllRanges();selection.addRange(range);}
            }
          }
        }else if(action==='unlink'){document.execCommand('unlink',false,null);}
        else if(action==='pasteText'){
          var insertText=function(text){restoreEditorRange();document.execCommand('insertText',false,text);syncEditorContent();};
          if(navigator.clipboard&&navigator.clipboard.readText)navigator.clipboard.readText().then(insertText).catch(function(){var text=window.prompt('วางหรือพิมพ์ข้อความล้วน');if(text!==null)insertText(text);});
          else{var text=window.prompt('วางหรือพิมพ์ข้อความล้วน');if(text!==null)insertText(text);}
          return;
        }else document.execCommand(command,false,null);
        syncEditorContent();
      });
    });
    editorToolbar.querySelectorAll('[data-rich-color]').forEach(function(input){
      input.closest('.avsf-rich-color-tool').style.setProperty('--selected-color',input.value);
      input.addEventListener('pointerdown',saveEditorRange);
      input.addEventListener('focus',saveEditorRange);
      input.addEventListener('input',function(){input.closest('.avsf-rich-color-tool').style.setProperty('--selected-color',input.value);applyEditorStyle(input.getAttribute('data-rich-color')==='foreColor'?'color':'background-color',input.value);});
    });
    var blockFormat=editorToolbar.querySelector('[data-rich-block]');
    if(blockFormat)blockFormat.addEventListener('change',function(){restoreEditorRange();document.execCommand('formatBlock',false,blockFormat.value);syncEditorContent();});
    var inlineFormat=editorToolbar.querySelector('[data-rich-inline]');
    if(inlineFormat)inlineFormat.addEventListener('change',function(){
      var tag=inlineFormat.value,selected=selectedEditorText();
      if(!tag){inlineFormat.value='';return;}
      if(!selected){editorMessage('เลือกข้อความก่อนใช้รูปแบบตัวอักษร');inlineFormat.value='';return;}
      var range=savedEditorRange;if(!range){editorMessage('เลือกข้อความก่อนใช้รูปแบบตัวอักษร');inlineFormat.value='';return;}var wrapper=document.createElement(tag);wrapper.appendChild(range.extractContents());range.insertNode(wrapper);range.selectNodeContents(wrapper);var selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);savedEditorRange=range.cloneRange();syncEditorContent();inlineFormat.value='';
    });
    editorToolbar.querySelectorAll('[data-special-character]').forEach(function(button){
      button.addEventListener('mousedown',function(event){event.preventDefault();});
      button.addEventListener('click',function(){restoreEditorRange();var selection=window.getSelection(),range=selection.getRangeAt(0),text=document.createTextNode(button.getAttribute('data-special-character'));range.deleteContents();range.insertNode(text);range.setStartAfter(text);range.collapse(true);selection.removeAllRanges();selection.addRange(range);editorToolbar.querySelector('[data-rich-specials]').hidden=true;syncEditorContent();});
    });
    contentEditor.addEventListener('keydown',function(event){
      if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();var linkButton=editorToolbar.querySelector('[data-rich-action="createLink"]');if(linkButton)linkButton.click();}
    });
    var editorCard=form.querySelector('.avsf-editor-content-card');
    if(editorCard){
      editorCard.addEventListener('submit',syncEditorContent);
      document.addEventListener('keydown',function(event){if(event.key==='Escape'){editorCard.classList.remove('avsf-is-rich-fullscreen');editorToolbar.querySelector('[data-rich-specials]').hidden=true;}});
    }
  }

  var codeInput=form.querySelector('[data-preview="code"]'),slugInput=form.querySelector('[data-preview="slug"]'),titleInput=form.querySelector('[data-preview="title"]'),metaTitleInput=form.querySelector('[name="metaTitle"]');
  var slugTouched=slugInput?slugInput.value.trim()!=='' : false;
  if(slugInput)slugInput.addEventListener('input',function(){slugTouched=true;});
  var slugify=function(value){return value.toLowerCase().normalize('NFKD').replace(/[^a-z0-9ก-๙]+/gi,'-').replace(/^-+|-+$/g,'');};
  var applyDefaults=function(){if(codeInput&&slugInput&&!slugTouched){slugInput.value=slugify(codeInput.value)||slugify(titleInput?titleInput.value:'');}if(metaTitleInput&&!metaTitleInput.value.trim()&&titleInput){metaTitleInput.placeholder=titleInput.value;}};
  if(codeInput)codeInput.addEventListener('input',applyDefaults);
  if(titleInput)titleInput.addEventListener('input',applyDefaults);
  applyDefaults();

  setupTaxonomyAutocomplete('postCategoryInput','postCategoryOptions','postCategorySuggestions');

  setupTaxonomyAutocomplete('studioName','studioOptions','studioSuggestions');

  var studioInput=form.querySelector('#studioName');

  if(studioInput)form.querySelectorAll('.avsf-taxonomy-recommendation[data-studio-name]').forEach(function(button){

    button.addEventListener('click',function(){var name=button.getAttribute('data-studio-name')||'';var parts=studioInput.value.split(/[,،;|，；\r\n]+/u).map(function(part){return part.trim();}).filter(Boolean);var exists=parts.some(function(part){return part.toLocaleLowerCase()==name.toLocaleLowerCase();});if(!exists)parts.push(name);studioInput.value=parts.join(', ');studioInput.dispatchEvent(new Event('input',{bubbles:true}));var box=studioInput.parentElement.querySelector('.avsf-taxonomy-suggestions');if(box){box.hidden=true;studioInput.setAttribute('aria-expanded','false');}});

  });

  var categoryInput=form.querySelector('#postCategoryInput');

  if(categoryInput)form.querySelectorAll('.avsf-taxonomy-recommendation[data-category-name]').forEach(function(button){

    button.addEventListener('click',function(){var name=button.getAttribute('data-category-name')||'';var parts=categoryInput.value.split(/[,،;|，；\r\n]+/u).map(function(part){return part.trim();}).filter(Boolean);var exists=parts.some(function(part){return part.toLocaleLowerCase()==name.toLocaleLowerCase();});if(!exists)parts.push(name);categoryInput.value=parts.join(', ');categoryInput.dispatchEvent(new Event('input',{bubbles:true}));var box=categoryInput.parentElement.querySelector('.avsf-taxonomy-suggestions');if(box){box.hidden=true;categoryInput.setAttribute('aria-expanded','false');}});

  });

  var value=function(key){var el=form.querySelector('[data-preview="'+key+'"]');return el?el.value.trim():'';};

  var audit=function(){

    var panel=form.querySelector('#seoAudit'),list=panel.querySelector('[data-seo-checks]'),overall=panel.querySelector('[data-seo-overall]'),scoreEl=panel.querySelector('[data-seo-score]');

    var title=value('title'),rawMetaTitle=value('metaTitle')||title,metaDesc=value('metaDesc');
    var countChars=function(text){try{if(typeof Intl.Segmenter==='function')return Array.from(new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(text||'')).length;}catch(e){}return Array.from(text||'').length;};

    var contentHtml=value('content'),contentNode=document.createElement('div');

    contentNode.innerHTML=contentHtml;

    var contentText=(contentNode.textContent||'').replace(/\s+/g,' ').trim();
    var postSerp=form.querySelector('[data-post-seo-preview]');
    var metaTitle=(postSerp&&postSerp.querySelector('[data-post-serp-title]')?postSerp.querySelector('[data-post-serp-title]').textContent:'')||rawMetaTitle;
    var effectiveDesc=(postSerp&&postSerp.querySelector('[data-post-serp-desc]')?postSerp.querySelector('[data-post-serp-desc]').textContent:'')||metaDesc||Array.from(contentText).slice(0,160).join('');

    var keyphrase=value('focusKeyphrase'),slug=value('slug');

    var contentWords=0;

    if(contentText){

      if(typeof Intl.Segmenter==='function')contentWords=Array.from(new Intl.Segmenter('th',{granularity:'word'}).segment(contentText)).filter(function(part){return part.isWordLike;}).length;

      else contentWords=contentText.split(/\s+/).length;

    }

    var paragraphText=Array.from(contentNode.querySelectorAll('p,h2,h3,h4,div')).map(function(node){return (node.textContent||'').trim();});

    var longestParagraph=paragraphText.reduce(function(longest,text){return Math.max(longest,Array.from(text).length);},0);

    var missingAlt=Array.from(contentNode.querySelectorAll('img')).filter(function(image){return !image.getAttribute('alt')||!image.getAttribute('alt').trim();}).length;

    var internalLinks=0,externalLinks=0;

    Array.from(contentNode.querySelectorAll('a[href]')).forEach(function(link){

      var href=link.getAttribute('href')||'';

      if(!href||/^(mailto:|tel:|#|javascript:)/i.test(href))return;

      try{

        var url=new URL(href,location.href);

        if(url.protocol!=='http:'&&url.protocol!=='https:')return;

        if(url.hostname===location.hostname)internalLinks++;else externalLinks++;

      }catch(error){}

    });

    var checks=[

      {state:!title?'error':countChars(title)>70?'avsf-error':countChars(title)<15?'warning':'good',text:!title?'ใส่ชื่อเรื่อง':countChars(title)>70?'ชื่อเรื่องยาวเกิน 70 ตัวอักษร ('+countChars(title)+')':countChars(title)<15?'ชื่อเรื่องสั้น ('+countChars(title)+' ตัวอักษร)':'ชื่อเรื่องเหมาะสม ('+countChars(title)+'/70 ตัวอักษร)'},

      {state:!rawMetaTitle?'avsf-error':countChars(metaTitle)>70?'avsf-error':'good',text:!rawMetaTitle?'ใส่ Meta Title':countChars(metaTitle)>70?'Meta Title อาจยาวเกินพื้นที่แสดงผล ('+countChars(metaTitle)+')':'Meta Title '+countChars(metaTitle)+'/70 ตัวอักษร'},

      {state:!effectiveDesc?'avsf-error':countChars(effectiveDesc)>160?'avsf-error':countChars(effectiveDesc)<70?'warning':'good',text:!effectiveDesc?'ใส่ Meta Description':countChars(effectiveDesc)>160?'Meta Description อาจยาวเกินช่วงแนะนำ 160 ตัวอักษร':(metaDesc?'Meta Description':'คำอธิบายอัตโนมัติ')+' '+countChars(effectiveDesc)+'/ประมาณ 120–160 ตัวอักษร'+(countChars(effectiveDesc)<70?' (ควรเพิ่มรายละเอียด)':'')},

      {state:!keyphrase?'avsf-error':(title.toLocaleLowerCase().indexOf(keyphrase.toLocaleLowerCase())===-1&&contentText.toLocaleLowerCase().indexOf(keyphrase.toLocaleLowerCase())===-1)?'warning':'good',text:!keyphrase?'ระบุ Focus Keyphrase':(title.toLocaleLowerCase().indexOf(keyphrase.toLocaleLowerCase())===-1&&contentText.toLocaleLowerCase().indexOf(keyphrase.toLocaleLowerCase())===-1)?'ไม่พบ Focus Keyphrase ในชื่อเรื่องหรือเนื้อหา':'พบ Focus Keyphrase ในชื่อเรื่องหรือเนื้อหา'},

      {state:!slug?'avsf-error':'good',text:!slug?'ระบุ Slug สำหรับ URL':'มี Slug สำหรับ URL'},

      {state:!contentText?'avsf-error':contentWords<100?'warning':(paragraphText.length===0&&contentText.length>350)||longestParagraph>600?'warning':'good',text:!contentText?'เพิ่มเนื้อหาโพสต์':contentWords<100?'เนื้อหาสั้น ('+contentWords+' คำ แนะนำเพิ่มเนื้อหา)':'เนื้อหา '+contentWords+' คำ · '+paragraphText.length+' ย่อหน้า'+(((paragraphText.length===0&&contentText.length>350)||longestParagraph>600)?' · แบ่งย่อหน้าให้สั้นลง':'')},

      {state:missingAlt?'avsf-error':'good',text:missingAlt?'รูปในเนื้อหา '+missingAlt+' รูปไม่มี alt':'รูปในเนื้อหามี alt ครบ (ปก/โปสเตอร์ใช้ชื่อเรื่องเป็น alt)'},

      {state:internalLinks?'good':'warning',text:internalLinks?'มีลิงก์ภายในในเนื้อหา '+internalLinks+' ลิงก์':'ยังไม่มีลิงก์ภายในในเนื้อหา (ไม่รวมค่าย/นักแสดง/หมวดหมู่ที่ระบบเชื่อมให้อัตโนมัติ)'},

      {state:externalLinks?'good':'warning',text:externalLinks?'มีลิงก์ภายนอก '+externalLinks+' ลิงก์':'ยังไม่มีลิงก์ภายนอก (ใส่เมื่อมีแหล่งอ้างอิงที่น่าเชื่อถือ)'}

    ];

    list.replaceChildren();

    var penalty=0,hasError=false,hasWarning=false;

    checks.forEach(function(check){

      var item=document.createElement('li');

      item.className='avsf-seo-audit-check avsf-is-'+check.state;

      item.textContent=check.text;

      list.appendChild(item);

      if(check.state==='error'){penalty+=15;hasError=true;}

      else if(check.state==='warning'){penalty+=6;hasWarning=true;}

    });

    var state=hasError?'avsf-error':hasWarning?'warning':'good';

    panel.setAttribute('data-state',state);

    overall.textContent=state==='error'?'ควรแก้รายการสำคัญ':state==='warning'?'มีข้อแนะนำให้ปรับ':'ข้อมูลพื้นฐานพร้อม';

    scoreEl.textContent=Math.max(0,100-penalty)+'/100';

  };

  var update=function(){

    var serp=form.querySelector('[data-post-seo-preview]');
    if(serp){
      var code=value('code'),postTitle=value('title'),siteName=serp.getAttribute('data-site-name')||'';
      var vars={'{code}':code,'{title}':postTitle,'{site_name}':siteName};
      function applyPostTemplate(template){return String(template||'').replace(/\{(code|title|site_name)\}/g,function(token){return Object.prototype.hasOwnProperty.call(vars,token)?vars[token]:token;}).replace(/\s+/g,' ').trim();}
      var metaTitle=value('metaTitle')||applyPostTemplate(serp.getAttribute('data-title-template'))||postTitle;
      var metaDesc=value('metaDesc')||applyPostTemplate(serp.getAttribute('data-desc-template'))||Array.from((function(){var n=document.createElement('div');n.innerHTML=value('content');return (n.textContent||'').replace(/\s+/g,' ').trim();})()).slice(0,160).join('');
      var titleNode=serp.querySelector('[data-post-serp-title]'),descNode=serp.querySelector('[data-post-serp-desc]'),slugNode=serp.querySelector('[data-post-serp-slug]'),statusNode=serp.querySelector('[data-post-serp-status]');
      if(titleNode)titleNode.textContent=metaTitle||'SEO Title จะแสดงที่นี่';
      if(descNode)descNode.textContent=metaDesc||'Meta Description จะแสดงที่นี่';
      if(slugNode)slugNode.textContent=slugify(value('slug')||code||postTitle);
      function graphemes(text){try{return Array.from(new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(text)).length;}catch(e){return Array.from(text||'').length;}}
      function width(text,font){try{var c=document.createElement('canvas'),ctx=c.getContext('2d');ctx.font=font;return ctx.measureText(text||'').width;}catch(e){return graphemes(text)*8;}}
      var tc=graphemes(metaTitle),dc=graphemes(metaDesc),tw=width(metaTitle,'600 20px Arial'),dw=width(metaDesc,'14px Arial'),warnings=[];
      if(tc>60||tw>580)warnings.push('Title อาจถูกตัดบนบางหน้าจอ');
      if(dc>160||dw>920)warnings.push('Description อาจถูกตัดหรือย่อ');
      if(statusNode){statusNode.textContent='Title '+tc+' ตัวอักษร · '+Math.round(tw)+' px | Description '+dc+' ตัวอักษร · '+Math.round(dw)+' px'+(warnings.length?' · '+warnings.join(' · '):' · อยู่ในช่วงประมาณการ')+'. Google อาจเปลี่ยนข้อความและความยาวที่แสดงได้';statusNode.classList.toggle('is-warning',warnings.length>0);}
      var tcNode=serp.parentElement.querySelector('[data-post-title-count]'),dcNode=serp.parentElement.querySelector('[data-post-desc-count]');
      if(tcNode){tcNode.textContent='จำนวน '+tc+' ตัวอักษร · ความกว้างประมาณ '+Math.round(tw)+' px (ควรคุมประมาณไม่เกิน 580 px)';tcNode.classList.toggle('is-warning',tc>60||tw>580);}
      if(dcNode){dcNode.textContent='จำนวน '+dc+' ตัวอักษร · ความกว้างประมาณ '+Math.round(dw)+' px (ควรคุมประมาณไม่เกิน 920 px)';dcNode.classList.toggle('is-warning',dc>160||dw>920);}
    }

    document.getElementById('postPreviewTitle').textContent=value('title')||'ชื่อเรื่องจะแสดงที่นี่';

    var status=value('status'),statusEl=document.getElementById('postPreviewStatus');

    statusEl.textContent=status==='publish'?'เผยแพร่':'ฉบับร่าง';statusEl.className='avsf-post-status '+(status==='publish'?'avsf-is-published':'avsf-is-draft');

    var studio=form.querySelector('#studioName'),studioText=studio?studio.value.trim():'';

    var actorPicker=form.querySelector('[data-taxonomy-picker="actors"]');

    var actors=actorPicker?Array.from(actorPicker.querySelectorAll('.avsf-taxonomy-chip span:first-child')).map(function(name){return name.textContent;}):[];

    var tags=Array.from(form.querySelector('[data-preview="tags"]').selectedOptions).map(function(option){return '#'+option.textContent;});

    document.getElementById('postPreviewMeta').textContent=[value('code'),studioText,actors.join(', '),value('category'),tags.join(' ')].filter(Boolean).join(' · ');

    var content=value('content').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();

    document.getElementById('postPreviewContent').textContent=content||'เนื้อหาตัวอย่างจะแสดงที่นี่';

    document.getElementById('postPreviewSEO').textContent='Focus Keyphrase: '+(value('focusKeyphrase')||'-')+' | Meta Description: '+(value('metaDesc')||'ระบบจะสร้างจากเนื้อหาอัตโนมัติ');

    audit();

  };

  form.querySelectorAll('[data-post-token]').forEach(function(button){button.addEventListener('click',function(){var token=button.getAttribute('data-post-token')||'';var active=document.activeElement;var target=(active&&form.contains(active)&&(['metaTitle','metaDesc'].indexOf(active.name)!==-1))?active:form.querySelector('[name="metaTitle"]');if(!target)return;var start=typeof target.selectionStart==='number'?target.selectionStart:target.value.length;var end=typeof target.selectionEnd==='number'?target.selectionEnd:start;target.value=target.value.slice(0,start)+token+target.value.slice(end);target.focus();if(target.setSelectionRange)target.setSelectionRange(start+token.length,start+token.length);target.dispatchEvent(new Event('input',{bubbles:true}));});});
  form.addEventListener('input',update);form.addEventListener('change',update);

  update();

})();

</script>

<script>
(function(){
  var widget=document.querySelector('.avsf-dashboard-widget-quick-draft');
  if(!widget)return;
  var toggles=widget.querySelectorAll('[data-draft-mode]');
  var panels=widget.querySelectorAll('[data-draft-panel]');
  toggles.forEach(function(button){
    button.addEventListener('click',function(){
      var selected=button.getAttribute('data-draft-mode');
      toggles.forEach(function(item){
        var active=item===button;
        item.classList.toggle('avsf-is-active',active);
        item.setAttribute('aria-pressed',active?'true':'false');
      });
      panels.forEach(function(panel){panel.hidden=panel.getAttribute('data-draft-panel')!==selected;});
    });
  });
  var fileInput=widget.querySelector('#advancedDraftFile');
  var dataInput=widget.querySelector('#advancedDraftData');
  var status=widget.querySelector('.avsf-dashboard-advanced-status');
  if(fileInput&&dataInput)fileInput.addEventListener('change',function(){
    var file=fileInput.files&&fileInput.files[0];
    if(!file)return;
    if(file.size>5*1024*1024){fileInput.value='';status.textContent=<?php echo avsf_js(__('ไฟล์มีขนาดเกิน 5 MB')); ?>;return;}
    file.arrayBuffer().then(function(buffer){
      var text=new TextDecoder('utf-8').decode(buffer);
      if(text.indexOf('\uFFFD')!==-1)text=new TextDecoder('windows-874').decode(buffer);
      dataInput.value=text;
      dataInput.dispatchEvent(new Event('input',{bubbles:true}));
      status.textContent=<?php echo avsf_js(__('โหลด ')); ?>+file.name+' แล้ว · '+Math.max(1,text.split(/\r\n|\r|\n/).length)+' บรรทัด';
    }).catch(function(){
      status.textContent=<?php echo avsf_js(__('อ่านไฟล์ไม่สำเร็จ กรุณาลองใหม่หรือวางข้อมูลในช่อง')); ?>;
    });
  });
})();
</script>


<script>
(function(){
  document.addEventListener('click',function(event){
    var button=event.target.closest('[data-taxonomy-select-all]');
    if(!button)return;
    var form=document.getElementById(button.getAttribute('data-taxonomy-select-all'));
    if(!form)return;
    var boxes=Array.prototype.filter.call(form.elements,function(element){return element.type==='checkbox'&&element.name==='taxonomy_ids[]';});
    var shouldSelect=boxes.some(function(box){return !box.checked;});
    boxes.forEach(function(box){box.checked=shouldSelect;});
    button.textContent=shouldSelect?'ยกเลิกเลือกทั้งหมด':'เลือกทั้งหมด';
  });
})();
</script>

<script>
(function(){
  'use strict';
  function graphemeCount(value){
    try{if(window.Intl&&Intl.Segmenter){return Array.from(new Intl.Segmenter(undefined,{granularity:'grapheme'}).segment(value)).length;}}catch(e){}
    return Array.from(value||'').length;
  }
  function escapeHTML(value){return String(value==null?'':value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];});}
  function stripHTML(value){var el=document.createElement('div');el.innerHTML=String(value||'');return (el.textContent||el.innerText||'').replace(/\s+/g,' ').trim().slice(0,180);}
  function tokensFor(root){
    var type=root.getAttribute('data-term-type')||'page';
    var nameField=root.querySelector('[data-term-name-input],input[name="name"],input[name="title"]');
    var name=nameField?nameField.value:(root.getAttribute('data-term-name')||'');
    var count=root.getAttribute('data-term-count')||'0';
    var excerptField=root.querySelector('[data-page-excerpt-source]');
    var vars={title:name,name:name,term_title:name,sitename:root.getAttribute('data-sitename')||document.querySelector('meta[property="og:site_name"]')?.content||document.title,sitedesc:root.getAttribute('data-sitedesc')||'',primary_keyword:root.getAttribute('data-primary-keyword')||'',post_count:count,item_count:count,sep:root.getAttribute('data-separator')||'|',page:'1',currentyear:String(new Date().getFullYear()),excerpt:root.getAttribute('data-excerpt')||(excerptField?stripHTML(excerptField.value):'')};
    var key=root.getAttribute('data-token')||({category:'cat',studio:'studio',actor:'actor',tag:'tag'}[type]||'');
    if(key)vars[key]=name;
    if(type==='page'){vars.cat='';vars.studio='';vars.actor='';vars.tag='';}
    return vars;
  }
  function renderTemplate(template,vars){return String(template||'').replace(/%%([a-z0-9_]+)%%/gi,function(all,key){return Object.prototype.hasOwnProperty.call(vars,key.toLowerCase())?vars[key.toLowerCase()]:all;}).replace(/\s+/g,' ').trim();}
  function pixelWidth(text,font){try{var canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');ctx.font=font;return ctx.measureText(text||'').width;}catch(e){return graphemeCount(text)*8;}}
  function setup(root){
    if(root.dataset.seoLiveReady==='1')return;root.dataset.seoLiveReady='1';
    var title=root.querySelector('[data-seo-title]'),desc=root.querySelector('[data-seo-description]');
    var titleOut=root.querySelector('[data-seo-preview-title]'),descOut=root.querySelector('[data-seo-preview-desc]'),status=root.querySelector('[data-seo-preview-status]');
    var titleCount=root.querySelector('[data-title-count]'),descCount=root.querySelector('[data-desc-count]');
    var toggle=root.querySelector('[data-seo-custom-toggle]');
    function applyToggle(){if(!toggle)return;var enabled=toggle.checked;if(!enabled){var v=tokensFor(root);if(title)title.value=renderTemplate(root.getAttribute('data-title-template')||'',v);if(desc)desc.value=renderTemplate(root.getAttribute('data-desc-template')||'',v);}[title,desc].forEach(function(el){if(el)el.disabled=!enabled;});}
    function update(){
      var vars=tokensFor(root),titleTemplate=root.getAttribute('data-title-template')||'',descTemplate=root.getAttribute('data-desc-template')||'';
      var useCustom=!toggle||toggle.checked;
      var titleValue=title?(useCustom?title.value:renderTemplate(titleTemplate,vars)):'';
      var descValue=desc?(useCustom?desc.value:renderTemplate(descTemplate,vars)):'';
      if(titleValue.indexOf('%%')!==-1)titleValue=renderTemplate(titleValue,vars);
      if(descValue.indexOf('%%')!==-1)descValue=renderTemplate(descValue,vars);
      if(titleOut)titleOut.textContent=titleValue||'ตัวอย่างชื่อหน้าเว็บไซต์';
      if(descOut)descOut.textContent=descValue||'ตัวอย่างคำอธิบายหน้าเว็บไซต์';
      var titleChars=graphemeCount(titleValue),descChars=graphemeCount(descValue);
      if(titleCount){titleCount.textContent='จำนวน '+titleChars+' ตัวอักษร · เกณฑ์แนะนำโดยประมาณ 30–60 ตัวอักษร';titleCount.classList.toggle('is-warning',titleChars>60);titleCount.classList.toggle('is-danger',titleChars>200);}
      if(descCount){descCount.textContent='จำนวน '+descChars+' ตัวอักษร · เกณฑ์แนะนำโดยประมาณ 120–160 ตัวอักษร';descCount.classList.toggle('is-warning',descChars>160||descChars<70);descCount.classList.toggle('is-danger',descChars>300);}
      var tw=pixelWidth(titleValue,'600 20px Arial'),dw=pixelWidth(descValue,'14px Arial'),messages=[];
      if(titleValue&&tw>580)messages.push('Title อาจถูกตัดบนบางหน้าจอ');
      if(descValue&&dw>920)messages.push('Description อาจถูกตัดหรือย่อ');
      if(!titleValue)messages.push('ยังไม่มี SEO Title');
      if(!descValue)messages.push('ยังไม่มี Meta Description');
      if(status){status.textContent=(messages.length?messages.join(' · '):'ความยาวอยู่ในช่วงประมาณการที่เหมาะสม')+'. Google อาจเลือกข้อความใหม่และแสดงความยาวต่างกันตามอุปกรณ์ คำค้นหา และพื้นที่แสดงผล จึงไม่มีการรับประกันว่าจะไม่ถูกตัด';status.classList.toggle('is-warning',messages.length>0);status.classList.toggle('is-danger',titleChars>200||descChars>300);}
    }
    if(toggle){toggle.addEventListener('change',function(){applyToggle();update();});applyToggle();}
    [title,desc].forEach(function(el){if(el){el.addEventListener('input',update);el.addEventListener('change',update);}});
    var name=root.querySelector('[data-term-name-input],input[name="name"],input[name="title"]');if(name)name.addEventListener('input',update);
    var excerpt=root.querySelector('[data-page-excerpt-source]');if(excerpt)excerpt.addEventListener('input',update);
    root.querySelectorAll('[data-insert-token]').forEach(function(button){button.addEventListener('click',function(){var target=button.closest('.avsf-term-settings-body')?.querySelector('[data-seo-active]')||((document.activeElement===desc)?desc:title)||title;if(!target)return;if(target.disabled&&toggle){toggle.checked=true;applyToggle();}var token=button.getAttribute('data-insert-token')||'';var start=target.selectionStart==null?target.value.length:target.selectionStart;var end=target.selectionEnd==null?start:target.selectionEnd;target.value=target.value.slice(0,start)+token+target.value.slice(end);target.focus();target.setSelectionRange(start+token.length,start+token.length);target.dispatchEvent(new Event('input',{bubbles:true}));});});
    [title,desc].forEach(function(el){if(el)el.addEventListener('focus',function(){[title,desc].forEach(function(other){if(other)other.removeAttribute('data-seo-active');});el.setAttribute('data-seo-active','1');});});
    update();
  }
  document.querySelectorAll('[data-seo-editor]').forEach(setup);
  document.addEventListener('click',function(event){
    var countButton=event.target.closest('[data-toggle-posts]');
    if(!countButton)return;
    var targetId=countButton.getAttribute('data-toggle-posts')||'';
    var target=targetId?document.getElementById(targetId):null;
    if(!target)return;
    target.open=!target.open;
    if(target.open){target.scrollIntoView({behavior:window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'nearest'});}
  });
})();
</script>
</body>

</html>

<?php

$pageOutput = ob_get_clean();

$csrfField = '<input type="hidden" name="csrf_token" value="'.esc($_SESSION['csrf_token']).'">';

$pageOutput = preg_replace_callback('/<form\b[^>]*method=["\']post["\'][^>]*>/i', function($match) use ($csrfField) {

    return $match[0].$csrfField;

}, $pageOutput);

echo $pageOutput;

?>
