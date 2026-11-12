<?php

/**

 * AVSF — จัดการผู้ใช้ & สิทธิ์ (RBAC + 2FA)

 * เข้าถึงได้เฉพาะบัญชีที่มีสิทธิ์จัดการผู้ใช้เท่านั้น

 */

require_once dirname(__DIR__, 2) . '/config.php';

require_once AVSF_CORE_DIR . '/functions.php';

require_once AVSF_CORE_DIR . '/security/security_lib.php';

require_once AVSF_CORE_DIR . '/auth/permissions.php';
require_once AVSF_CORE_DIR . '/admin-quick-dock.php';
avsf_admin_quick_dock_start_buffer();

require_once AVSF_CORE_DIR . '/auth/totp.php';



if (!isAdmin()) {

    header('Location: index.php');

    exit;

}

if (empty($_SESSION['avsf_admin_id']) && !empty($_SESSION['avsf_admin_username'])) {

    $currentUserStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE username=? LIMIT 1');

    if (!$currentUserStmt) { error_log('AVSF current user lookup prepare failed: '.mysqli_error($conn)); http_response_code(500); exit(__('ตรวจสอบบัญชีผู้ใช้ไม่สำเร็จ')); }

    $currentUsername = (string)$_SESSION['avsf_admin_username'];

    mysqli_stmt_bind_param($currentUserStmt, 's', $currentUsername);

    if (!mysqli_stmt_execute($currentUserStmt)) { error_log('AVSF current user lookup failed: '.mysqli_stmt_error($currentUserStmt)); mysqli_stmt_close($currentUserStmt); http_response_code(500); exit(__('ตรวจสอบบัญชีผู้ใช้ไม่สำเร็จ')); }

    mysqli_stmt_bind_result($currentUserStmt, $currentUserId);

    if (mysqli_stmt_fetch($currentUserStmt)) $_SESSION['avsf_admin_id'] = (int)$currentUserId;

    mysqli_stmt_close($currentUserStmt);

}

if (!empty($_SESSION['avsf_admin_id'])) loadUserPermissions((int)$_SESSION['avsf_admin_id'], $conn);

requireSiteCenterAccess();

requirePermission('users.manage');



$msg = '';

$msgType = 'success';

$action = $_GET['action'] ?? 'list';

$roleDefinitions = permissionRoleDefinitions();

$ownerResult = mysqli_query($conn, "SELECT id FROM users WHERE role='owner' ORDER BY id ASC LIMIT 1");

if (!$ownerResult) {

    error_log('AVSF owner role lookup failed: '.mysqli_error($conn));

    http_response_code(500);

    exit(__('ตรวจสอบข้อมูล Owner ไม่สำเร็จ'));

}

$ownerRow = mysqli_fetch_assoc($ownerResult);

$ownerExists = (bool)$ownerRow;

$firstUserResult = mysqli_query($conn, 'SELECT id FROM users ORDER BY id ASC LIMIT 1');

if (!$firstUserResult) {

    error_log('AVSF first user lookup failed: '.mysqli_error($conn));

    http_response_code(500);

    exit(__('ตรวจสอบข้อมูลผู้ใช้ไม่สำเร็จ'));

}

$firstUserRow = mysqli_fetch_assoc($firstUserResult);

$firstUserId = $firstUserRow ? (int)$firstUserRow['id'] : 0;

if (!$ownerExists && $firstUserId > 0) {

    $firstRoleResult = mysqli_query($conn, 'SELECT role FROM users WHERE id='.(int)$firstUserId.' LIMIT 1');

    $firstRoleRow = $firstRoleResult ? mysqli_fetch_assoc($firstRoleResult) : null;

    if ($firstRoleResult) mysqli_free_result($firstRoleResult);

    if ($firstRoleRow && in_array((string)$firstRoleRow['role'], ['super_admin','owner'], true)) {

        mysqli_query($conn, "UPDATE users SET role='owner' WHERE id=".(int)$firstUserId." LIMIT 1");

        $ownerExists = true;

    }

}



$regSecret = setting('registration_secret_key', '');

if ($regSecret === '') {

    $regSecret = bin2hex(random_bytes(16));

    saveSetting('registration_secret_key', $regSecret);

}

$siteUrl = rtrim(avsfCanonicalBaseUrl(), '/');

$regLink = $siteUrl . '/register.php?key=' . rawurlencode($regSecret);



$editId = (int)($_GET['id'] ?? 0);



// ====== POST actions ======

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    sec_csrf_verify();

    $targetRoleForPost = '';

    if ($editId > 0) {

        $targetRoleResult = mysqli_query($conn, 'SELECT role FROM users WHERE id='.(int)$editId.' LIMIT 1');

        if (!$targetRoleResult) { error_log('AVSF target user lookup failed: '.mysqli_error($conn)); http_response_code(500); exit(__('ตรวจสอบบัญชีเป้าหมายไม่สำเร็จ')); }

        $targetRoleRow = mysqli_fetch_assoc($targetRoleResult);

        $targetRoleForPost = $targetRoleRow['role'] ?? '';

    }

    $mayManageTargetCredentials = !($targetRoleForPost === 'owner' && ((int)($_SESSION['avsf_admin_id']??0) !== $editId || currentUserRole() !== 'owner'))

        && !($targetRoleForPost === 'super_admin' && (int)($_SESSION['avsf_admin_id']??0) !== $editId && currentUserRole() !== 'owner');



    // ====== ลิงก์สมัครลับ (Private Invite Link) ======

    if (isset($_POST['regenerate_key'])) {

        $newKey = bin2hex(random_bytes(16));

        saveSetting('registration_secret_key', $newKey);

        $msg = __('✅ สร้างลิงก์สมัครใหม่เรียบร้อย (ลิงก์เก่าใช้ไม่ได้แล้ว)');

    }



    // --- บันทึกสิทธิ์ตั้งต้นแยกตามบทบาท ---

    if (isset($_POST['save_role_permissions'])) {

        $role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';

        if (!isset($roleDefinitions[$role]) || $role === 'owner' || (currentUserRole()!=='owner' && permissionRoleRank($role)>=permissionRoleRank(currentUserRole()))) {

            $msg = __('บทบาทที่เลือกไม่สามารถตั้งค่าสิทธิ์ได้'); $msgType = 'error';

        } else {

            $permissions = normalizePermissionList($_POST['permissions'] ?? []);

            $encoded = json_encode($permissions, JSON_UNESCAPED_UNICODE);

            if ($encoded === false || !saveSetting('role_permissions_'.$role, $encoded)) {

                error_log('AVSF role permission save failed for role '.$role);

                $msg = __('บันทึกชุดสิทธิ์ไม่สำเร็จ'); $msgType = 'error';

            } else {

                logActivity('ปรับชุดสิทธิ์บทบาท '.$role.__(' โดย ').($_SESSION['avsf_admin_username']??'admin'));

                $msg = __('บันทึกชุดสิทธิ์บทบาทแล้ว ผู้ใช้จะได้รับค่าใหม่เมื่อเข้าสู่ระบบครั้งถัดไป');

            }

        }

        $action = 'roles';

    }



    // --- ใช้ชุดสิทธิ์สำเร็จรูปตามบทบาท ---

    if (isset($_POST['apply_role_preset'])) {
        $presetRole = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';
        $presets = readyRolePermissionPresets();
        if (!isset($presets[$presetRole]) || $presetRole === 'owner' || (currentUserRole() !== 'owner' && $presetRole === 'super_admin')) {
            $msg = __('ไม่สามารถใช้ชุดสิทธิ์สำเร็จรูปนี้ได้'); $msgType = 'error';
        } elseif (currentUserRole() !== 'owner' && permissionRoleRank($presetRole) >= permissionRoleRank(currentUserRole())) {
            $msg = __('คุณกำหนดชุดสิทธิ์ได้เฉพาะบทบาทที่ต่ำกว่าตัวเอง'); $msgType = 'error';
        } else {
            $encoded = json_encode(normalizePermissionList($presets[$presetRole]['permissions']), JSON_UNESCAPED_UNICODE);
            if ($encoded === false || !saveSetting('role_permissions_'.$presetRole, $encoded)) {
                $msg = __('ใช้ชุดสิทธิ์สำเร็จรูปไม่สำเร็จ'); $msgType = 'error';
            } else {
                logActivity('ใช้ชุดสิทธิ์สำเร็จรูป '.$presetRole.__(' โดย ').($_SESSION['avsf_admin_username'] ?? 'admin'));
                $msg = __('ใช้ชุดสิทธิ์สำเร็จรูปสำหรับบทบาทนี้แล้ว');
            }
        }
        $action = 'roles';
    }

    // --- เพิ่มผู้ใช้ใหม่ ---

    if (isset($_POST['add_user'])) {

        $username = trim($_POST['username'] ?? '');

        $password = $_POST['password'] ?? '';

        $role = $_POST['role'] ?? 'custom';

        if ($username === '' || strlen($password) < 6 || !isset($roleDefinitions[$role]) || $role === 'owner' || ($role === 'super_admin' && currentUserRole() !== 'owner') || (currentUserRole()!=='owner' && permissionRoleRank($role)>permissionRoleRank(currentUserRole()))) {

            $msg = __('กรุณากรอกชื่อผู้ใช้ และรหัสผ่านอย่างน้อย 6 ตัว'); $msgType = 'error';

        } else {

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $permissions = normalizePermissionList($_POST['permissions'] ?? []);

            $perms = json_encode($permissions, JSON_UNESCAPED_UNICODE);

            $stmt = mysqli_prepare($conn, 'INSERT INTO users (username,password,role,permissions) VALUES (?,?,?,?)');

            if (!$stmt) {

                error_log('AVSF user creation prepare failed: '.mysqli_error($conn));

                $ok = false;

            } else {

                mysqli_stmt_bind_param($stmt, 'ssss', $username, $hash, $role, $perms);

                $ok = mysqli_stmt_execute($stmt);

                if (!$ok) error_log('AVSF user creation failed: '.mysqli_stmt_error($stmt));

                mysqli_stmt_close($stmt);

            }

            if ($ok) { $msg = "เพิ่มผู้ใช้ '$username' เรียบร้อย"; logActivity("เพิ่มผู้ใช้: $username"); }

            else { $msg = __('เพิ่มผู้ใช้ไม่สำเร็จ อาจมีชื่อซ้ำหรือฐานข้อมูลขัดข้อง'); $msgType = 'error'; }

        }

    }



    // --- แก้ไขผู้ใช้ (role + permissions) ---

    if (isset($_POST['edit_user']) && $editId > 0) {

        $role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';

        $targetResult = mysqli_query($conn, "SELECT id,role FROM users WHERE id=".(int)$editId." LIMIT 1");

        $target = $targetResult ? mysqli_fetch_assoc($targetResult) : null;

        $ownerPromotionAllowed = !$ownerExists && $target && $editId === $firstUserId && currentUserRole() === 'super_admin';

        $roleEscalationDenied = currentUserRole()!=='owner' && (

            permissionRoleRank($role)>permissionRoleRank(currentUserRole())

            || ($target && permissionRoleRank($target['role'])>permissionRoleRank(currentUserRole()) && !$ownerPromotionAllowed)

            || ($target && $target['role']==='super_admin' && $role!=='super_admin' && !$ownerPromotionAllowed)

        );

        if (!$target || !isset($roleDefinitions[$role]) || ($role === 'owner' && !$ownerPromotionAllowed && $target['role'] !== 'owner') || ($target['role'] === 'owner' && $role !== 'owner') || $roleEscalationDenied) {

            $msg = __('ไม่สามารถเปลี่ยนบทบาทนี้ได้'); $msgType = 'error';

        } else {

            $permissions = normalizePermissionList($_POST['permissions'] ?? []);

            $perms = json_encode($permissions, JSON_UNESCAPED_UNICODE);

            $stmt = mysqli_prepare($conn, 'UPDATE users SET role=?,permissions=? WHERE id=?');

            if (!$stmt) {

                error_log('AVSF user update prepare failed: '.mysqli_error($conn));

                $msg = __('บันทึกสิทธิ์ไม่สำเร็จ'); $msgType = 'error';

            } else {

                mysqli_stmt_bind_param($stmt, 'ssi', $role, $perms, $editId);

                $saved = mysqli_stmt_execute($stmt);

                if (!$saved) error_log('AVSF user update failed: '.mysqli_stmt_error($stmt));

                mysqli_stmt_close($stmt);

                if ($saved) {

                    $msg = __('บันทึกสิทธิ์เรียบร้อย');

                    if ($role === 'owner' && !$ownerExists) $msg .= __(' — บัญชีแรกเป็น Owner แล้ว กรุณาเข้าสู่ระบบใหม่เพื่อโหลดสิทธิ์');

                } else { $msg = __('บันทึกสิทธิ์ไม่สำเร็จ'); $msgType = 'error'; }

            }

        }

    }



    // --- เปลี่ยนรหัสผ่านผู้อื่น (Super Admin ทำได้) ---

    if (isset($_POST['reset_password']) && $editId > 0) {

        $newPass = $_POST['new_password'] ?? '';

        if (!$mayManageTargetCredentials) { $msg = __('มีเพียง Owner เท่านั้นที่จัดการบัญชี Owner หรือ Super Admin ได้'); $msgType = 'error'; }

        elseif (strlen($newPass) < 6) { $msg = __('รหัสผ่านต้องอย่างน้อย 6 ตัว'); $msgType = 'error'; }

        else {

            $hash = password_hash($newPass, PASSWORD_DEFAULT);

            $passwordStmt = mysqli_prepare($conn, 'UPDATE users SET password=? WHERE id=?');

            if (!$passwordStmt) { error_log('AVSF password reset prepare failed: '.mysqli_error($conn)); $msg = __('เปลี่ยนรหัสผ่านไม่สำเร็จ'); $msgType = 'error'; }

            else {

                mysqli_stmt_bind_param($passwordStmt, 'si', $hash, $editId);

                $passwordSaved = mysqli_stmt_execute($passwordStmt);

                if (!$passwordSaved) error_log('AVSF password reset failed: '.mysqli_stmt_error($passwordStmt));

                mysqli_stmt_close($passwordStmt);

                $msg = $passwordSaved ? __('เปลี่ยนรหัสผ่านเรียบร้อย') : __('เปลี่ยนรหัสผ่านไม่สำเร็จ');

                $msgType = $passwordSaved ? 'success' : 'error';

                if ($passwordSaved) logActivity('ผู้ดูแลเปลี่ยนรหัสผ่าน user #'.$editId);

            }

        }

    }



    // --- เปิดใช้งาน 2FA ---

    if (isset($_POST['enable_2fa']) && $editId > 0) {

        $secret = $_POST['twofa_secret'] ?? '';

        $code = preg_replace('/[^0-9]/', '', $_POST['twofa_code'] ?? '');

        if (!$mayManageTargetCredentials) {

            $msg = __('มีเพียง Owner เท่านั้นที่จัดการบัญชี Owner หรือ Super Admin ได้'); $msgType = 'error';

        } elseif (AVSFTOTP::verifyCode($secret, $code)) {

            $sE = mysqli_real_escape_string($conn, $secret);

            mysqli_query($conn, "UPDATE users SET twofa_secret='$sE', twofa_enabled=1 WHERE id=$editId");

            $msg = __('✅ เปิดใช้งาน 2FA เรียบร้อย');

        } else {

            $msg = __('โค้ด 6 หลักไม่ถูกต้อง กรุณาลองใหม่'); $msgType = 'error';

        }

    }



    // --- ปิด 2FA ---

    if (isset($_POST['disable_2fa']) && $editId > 0 && $mayManageTargetCredentials) {

        mysqli_query($conn, "UPDATE users SET twofa_enabled=0, twofa_secret='' WHERE id=$editId");

        $msg = __('ปิดใช้งาน 2FA เรียบร้อย');

    } elseif (isset($_POST['disable_2fa']) && $editId > 0) {

        $msg = __('มีเพียง Owner เท่านั้นที่จัดการบัญชี Owner หรือ Super Admin ได้'); $msgType = 'error';

    }



    // --- ลบผู้ใช้ (ห้ามลบ Super Admin) ---

    if (isset($_POST['delete_user']) && $editId > 0) {

        $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role,username FROM users WHERE id=$editId"));

        if (!$u) {

            $msg = __('ไม่พบผู้ใช้ที่ต้องการลบ'); $msgType = 'error';

        } elseif (in_array($u['role'], ['owner','super_admin'], true)) {

            $msg = __('⛔ ไม่สามารถลบ Owner หรือ Super Admin ได้'); $msgType = 'error';

        } else {

            $deleted = mysqli_query($conn, "DELETE FROM users WHERE id=".(int)$editId);

            if ($deleted) {

                $msg = "ลบผู้ใช้ '{$u['username']}' เรียบร้อย";

                logActivity("ลบผู้ใช้: {$u['username']}");

                $action = 'list';

            } else {

                error_log('AVSF user delete failed: '.mysqli_error($conn));

                $msg = __('ลบผู้ใช้ไม่สำเร็จ'); $msgType = 'error';

            }

        }

    }

}



// ====== ดึงข้อมูลผู้ใช้ที่กำลังแก้ไข ======

$editUser = null;

$new2faSecret = '';

if ($editId > 0) {

    $editUser = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$editId"));

    if ($editUser) {

        $editUser['perms'] = normalizePermissionList(json_decode($editUser['permissions'] ?? '[]', true) ?: []);

        if (empty($editUser['twofa_secret'])) {

            $new2faSecret = AVSFTOTP::generateSecret();

        }

    }

}



// ====== ดึงผู้ใช้ทั้งหมด ======

$allUsers = [];

$res = mysqli_query($conn, "SELECT id,username,role,permissions,lastLogin,twofa_enabled FROM users ORDER BY id ASC");

if (!$res) { error_log('AVSF user list failed: '.mysqli_error($conn)); http_response_code(500); exit(__('โหลดรายชื่อผู้ใช้ไม่สำเร็จ')); }

while ($r = mysqli_fetch_assoc($res)) $allUsers[] = $r;



$csrf = esc($_SESSION['csrf_token']);

$accent = setting('theme_accent', '#ff2d78');

?>

<!DOCTYPE html>

<html lang="<?php echo avsf_lang_attr(); ?>">

<head>

<script>try{var t=localStorage.getItem('avsf-admin-theme')||'light';document.documentElement.setAttribute('data-admin-theme',t);document.documentElement.className='avsf-theme-'+t;}catch(e){document.documentElement.setAttribute('data-admin-theme','light');}</script>
<meta charset="UTF-8">

<meta name="viewport" content="width=device-width,initial-scale=1">

<meta name="robots" content="noindex,nofollow">

<title>👥 จัดการผู้ใช้ & สิทธิ์ — AVSF</title>

<style>

*{box-sizing:border-box;margin:0;padding:0}

html,body{max-width:100%;overflow-x:clip}body{font-family:Kanit,'Segoe UI',sans-serif;background:var(--bg,#f4f6f9);color:var(--text,#1e293b);min-height:100vh;width:100%;max-width:none;margin:0;padding:clamp(12px,2vw,24px);color-scheme:light}

[data-admin-theme="dark"]{background:#090b10;color:#e5e7eb;color-scheme:dark}

h1{font-size:20px;margin-bottom:4px}

.avsf-sub{color:#9ca3af;font-size:12px;margin-bottom:20px}

.avsf-card{background:#13131f;border:1px solid #27273a;border-radius:14px;padding:18px;margin-bottom:16px}

.avsf-msg{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px}

.avsf-msg.avsf-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}

.avsf-msg.avsf-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}

.avsf-btn{display:inline-block;padding:9px 18px;border-radius:10px;border:none;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;font-family:inherit}

.avsf-btn-primary{background:linear-gradient(135deg,#ff2d78,#a855f7);color:#fff}

.avsf-btn-ghost{background:#1e1e2e;color:#e5e7eb;border:1px solid #373750}

.avsf-btn-danger{background:#dc2626;color:#fff}

.avsf-btn-sm{padding:6px 12px;font-size:12px}

table{width:100%;border-collapse:collapse;font-size:13px}

th,td{padding:10px 8px;text-align:left;border-bottom:1px solid #27273a}

th{color:#9ca3af;font-weight:600;font-size:11px;text-transform:uppercase}

.avsf-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}

.avsf-badge.avsf-super{background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff}

.avsf-badge.avsf-editor{background:rgba(59,130,246,.2);color:#60a5fa}

.avsf-badge.avsf-author{background:rgba(34,197,94,.2);color:#4ade80}

.avsf-badge.avsf-moderator{background:rgba(168,85,247,.2);color:#c084fc}

.avsf-badge.avsf-custom{background:#374151;color:#d1d5db}

.avsf-badge.avsf-on{background:rgba(34,197,94,.2);color:#4ade80}

.avsf-badge.avsf-off{background:#374151;color:#9ca3af}

label{display:block;font-size:12px;font-weight:600;color:#9ca3af;margin:12px 0 5px}

input,select,textarea{width:100%;padding:10px 12px;background:#0c0c15;border:1px solid #27273a;border-radius:9px;color:#fff;font-size:13px;font-family:inherit}

input:focus,select:focus{outline:none;border-color:#ff2d78}

.avsf-perm-group{margin-bottom:14px;padding:12px;background:#0c0c15;border-radius:10px;border:1px solid #27273a}

.avsf-perm-group h4{font-size:13px;margin-bottom:8px;color:#fbbf24}

.avsf-perm-item{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px}

.avsf-perm-item input{width:auto;margin:0}

.avsf-twofa-box{text-align:center;padding:16px;background:#0c0c15;border-radius:12px;border:1px solid #27273a}

.avsf-twofa-box img{border-radius:10px;background:#fff;padding:8px}

.avsf-twofa-secret{font-family:monospace;font-size:14px;letter-spacing:2px;background:#1e1e2e;padding:8px;border-radius:8px;margin:10px 0;user-select:all}

.avsf-back{color:#60a5fa;text-decoration:none;font-size:13px}

.avsf-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

.avsf-card{background:#fff;border-color:#dfe6ef;color:#1e293b;box-shadow:0 1px 2px rgba(15,23,42,.04)}

.avsf-sub,.avsf-back,.avsf-twofa-secret{color:#64748b}

label{color:#475569}

th{color:#64748b;border-color:#e2e8f0}td{border-color:#e2e8f0}

input,select,textarea{background:#fff;border-color:#cbd5e1;color:#1e293b}

.avsf-btn-ghost{background:#fff;color:#334155;border-color:#cbd5e1}

.avsf-perm-group,.avsf-twofa-box{background:#f8fafc;border-color:#e2e8f0;color:#334155}

.avsf-badge.avsf-custom,.avsf-badge.avsf-off{background:#e2e8f0;color:#475569}

.avsf-badge.avsf-author{background:#dcfce7;color:#15803d}.avsf-badge.avsf-editor{background:#dbeafe;color:#1d4ed8}.avsf-badge.avsf-moderator{background:#ede9fe;color:#7e22ce}.avsf-badge.avsf-on{background:#dcfce7;color:#15803d}.avsf-badge.avsf-off{background:#e2e8f0;color:#475569}

[data-admin-theme="dark"] .avsf-card{background:#11151f;border-color:#283244;color:#e5e7eb;box-shadow:none}

[data-admin-theme="dark"] .avsf-sub,[data-admin-theme="dark"] .avsf-back,[data-admin-theme="dark"] .avsf-twofa-secret{color:#9ca3af}

[data-admin-theme="dark"] label{color:#9ca3af}

[data-admin-theme="dark"] th,[data-admin-theme="dark"] td{border-color:#27273a}

[data-admin-theme="dark"] th{color:#9ca3af}

[data-admin-theme="dark"] input,[data-admin-theme="dark"] select,[data-admin-theme="dark"] textarea{background:#0c0c15;border-color:#27273a;color:#fff}

[data-admin-theme="dark"] .avsf-btn-ghost{background:#1e1e2e;color:#e5e7eb;border-color:#373750}

[data-admin-theme="dark"] .avsf-perm-group,[data-admin-theme="dark"] .avsf-twofa-box{background:#0c0c15;border-color:#27273a;color:#e5e7eb}

[data-admin-theme="dark"] .avsf-badge.avsf-custom,[data-admin-theme="dark"] .avsf-badge.avsf-off{background:#374151;color:#d1d5db}

[data-admin-theme="dark"] .avsf-badge.avsf-author{background:rgba(34,197,94,.2);color:#4ade80}[data-admin-theme="dark"] .avsf-badge.avsf-editor{background:rgba(59,130,246,.2);color:#60a5fa}[data-admin-theme="dark"] .avsf-badge.avsf-moderator{background:rgba(168,85,247,.2);color:#c084fc}[data-admin-theme="dark"] .avsf-badge.avsf-on{background:rgba(34,197,94,.2);color:#4ade80}

@media(max-width:700px){body{padding:12px}table{min-width:680px}.avsf-card{padding:14px}}

</style>
<link rel="stylesheet" href="admin-ui.css">
</head>

<body data-admin-theme="light">



<a href="site-center.php" class="avsf-back"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> กลับศูนย์เว็บไซต์</a>

<h1 style="margin-top:12px">👥 จัดการผู้ใช้ & สิทธิ์</h1>

<p class="avsf-sub">จัดการสมาชิก บทบาท และขอบเขตการเข้าถึง · รองรับ 2FA (Google Authenticator)</p>



<?php if ($msg): ?><div class="avsf-msg <?php echo e(avsfClass($msgType)); ?>"><?php echo esc($msg); ?></div><?php endif; ?>



<?php if ($action === 'list'): ?>

<div class="avsf-row" style="justify-content:flex-end;margin-bottom:12px">

    <a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="?action=roles"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.4 1z"/></svg> <?php _e('ตั้งค่าสิทธิ์แต่ละบทบาท'); ?></a>

</div>

<!-- ====== ลิงก์สมัครลับ (เห็นเฉพาะ Super Admin) ====== -->

<div class="avsf-card" style="border-left:4px solid #f59e0b">

    <h4 style="margin-bottom:8px"><?php _e('🔗 ลิงก์สมัครสมาชิก (ส่งให้คนในองค์กรเท่านั้น)'); ?></h4>

    <p style="font-size:12px;color:#9ca3af;margin-bottom:10px"><?php _e('ลิงก์นี้เป็นความลับ — คนนอกจะไม่รู้ว่ามีหน้าสมัคร (เข้าโดยตรงจะเห็น 404) ส่งให้คนที่ต้องการสมัครเท่านั้น'); ?></p>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">

        <input id="regLink" readonly value="<?php echo esc($regLink); ?>" style="flex:1;min-width:0;font-size:12px;font-family:monospace" onclick="this.select()">

        <button class="avsf-btn avsf-btn-ghost avsf-btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('regLink').value);this.textContent='✅ คัดลอกแล้ว'"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> <?php _e('คัดลอก'); ?></button>

        <form method="post" onsubmit="return confirm(<?php echo avsf_js(__('สร้างลิงก์ใหม่? ลิงก์เก่าจะใช้ไม่ได้ทันที')); ?>)">

            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <input type="hidden" name="regenerate_key" value="1">

            <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-2.1-9.4L23 10"/></svg> <?php _e('สร้างใหม่'); ?></button>

        </form>

    </div>

    <p style="font-size:11px;color:#6b7280;margin-top:8px"><?php _e('💡 กด "สร้างใหม่" เมื่อต้องการยกเลิกลิงก์เก่า (เช่น เมื่อคนในองค์กรครบแล้ว หรือส่งผิดคน)'); ?></p>

</div>

<!-- ====== รายการผู้ใช้ ====== -->

<div class="avsf-card">

    <div class="avsf-row" style="justify-content:space-between;margin-bottom:14px">

        <strong><?php _e('ผู้ใช้ทั้งหมด ('); ?><?php echo count($allUsers); ?>)</strong>

        <a class="avsf-btn avsf-btn-primary avsf-btn-sm" href="?action=add"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> <?php _e('เพิ่มผู้ใช้'); ?></a>

    </div>
<div class="avsf-row" style="margin-bottom:12px;gap:8px">
        <div style="position:relative;flex:1;min-width:180px"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5;pointer-events:none"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg><input id="userFilter" type="search" placeholder="<?php echo __('ค้นหาชื่อผู้ใช้, บทบาท...'); ?>" style="padding-left:34px" oninput="var q=this.value.toLowerCase();document.querySelectorAll('tr[data-user-row]').forEach(function(r){r.style.display=(r.textContent.toLowerCase().indexOf(q)>=0)?'':'none';});"></div>
    </div>


    <div style="overflow-x:auto">

    <table>

        <tr><th><?php _e('ชื่อผู้ใช้'); ?></th><th><?php _e('บทบาท'); ?></th><th><?php _e('ขอบเขตสิทธิ์'); ?></th><th>Site Center</th><th>2FA</th><th><?php _e('เข้าล่าสุด'); ?></th><th><?php _e('จัดการ'); ?></th></tr>

        <?php foreach ($allUsers as $u): ?>

        <?php

            $rolePresetValue = setting('role_permissions_'.$u['role'], '');

            $rolePreset = $rolePresetValue !== '' ? json_decode($rolePresetValue, true) : defaultPermissionsForRole($u['role']);

            if (!is_array($rolePreset)) $rolePreset = defaultPermissionsForRole($u['role']);

            $storedUserPerms = json_decode($u['permissions'] ?: '[]', true);

            if (!is_array($storedUserPerms)) $storedUserPerms = [];

            $effectiveUserPerms = normalizePermissionList(array_merge($rolePreset, $storedUserPerms));

            $centerAccess = $u['role']==='owner' || in_array('*',$effectiveUserPerms,true) || in_array('sitecenter.view',$effectiveUserPerms,true);

        ?>

        <tr>

            <td><strong><?php echo esc($u['username']); ?></strong></td>

            <td><span class="avsf-badge <?php echo e(avsfClass($u['role']==='owner'?'avsf-super':$u['role'])); ?>"><?php echo esc($roleDefinitions[$u['role']] ?? $u['role']); ?></span></td>

            <td><?php echo in_array('*',$effectiveUserPerms,true)?__('สิทธิ์ทั้งหมด'):number_format(count($effectiveUserPerms)).__(' รายการ'); ?></td>

            <td><span class="avsf-badge <?php echo e(avsfClass($centerAccess?'avsf-on':'avsf-off')); ?>"><?php echo $centerAccess?__('อนุญาต'):__('ไม่อนุญาต'); ?></span></td>

            <td><span class="avsf-badge <?php echo e(avsfClass($u['twofa_enabled'] ? 'avsf-on' : 'avsf-off')); ?>"><?php echo $u['twofa_enabled'] ? __('✅ เปิด') : __('ปิด'); ?></span></td>

            <td style="font-size:11px;color:#9ca3af"><?php echo $u['lastLogin'] ? esc($u['lastLogin']) : '-'; ?></td>

            <td>

                <a class="avsf-btn avsf-btn-ghost avsf-btn-sm" href="?action=edit&id=<?php echo $u['id']; ?>">แก้ไข</a>

                <?php if (!in_array($u['role'], ['owner','super_admin'], true)): ?>

                <form method="post" style="display:inline" onsubmit="return confirm(<?php echo avsf_js(__('ลบผู้ใช้นี้?')); ?>)">

                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                    <input type="hidden" name="delete_user" value="1">

                    <input type="hidden" name="id" value="<?php echo $u['id']; ?>">

                    <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit"><?php _e('ลบ'); ?></button>

                </form>

                <?php endif; ?>

            </td>

        </tr>

        <?php endforeach; ?>

    </table>

    </div>

</div>



<?php elseif ($action === 'add'): ?>

<!-- ====== เพิ่มผู้ใช้ ====== -->

<div class="avsf-card">

    <h3 style="margin-bottom:14px"><?php _e('➕ เพิ่มผู้ใช้ใหม่'); ?></h3>

    <form method="post">

        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

        <input type="hidden" name="add_user" value="1">

        <label><?php _e('ชื่อผู้ใช้ (Username)'); ?></label>

        <input name="username" required placeholder="<?php echo __('เช่น editor1'); ?>">

        <label><?php _e('รหัสผ่าน (อย่างน้อย 6 ตัว)'); ?></label>

        <input name="password" type="password" required placeholder="••••••••">

        <label><?php _e('ประเภทผู้ใช้'); ?></label>

        <select name="role">

            <?php foreach ($roleDefinitions as $value=>$label):

                if ($value==='owner' || ($value==='super_admin' && currentUserRole()!=='owner')) continue;

            ?>

            <option value="<?php echo esc($value); ?>"><?php echo esc($label); ?></option>

            <?php endforeach; ?>

        </select>

        <small style="display:block;color:#9ca3af;margin-top:6px"><?php _e('สิทธิ์ตั้งต้นจะมาจากหน้า “ตั้งค่าสิทธิ์แต่ละบทบาท” และสามารถเพิ่มขอบเขตรายบุคคลได้จาก checkbox ด้านล่าง'); ?></small>

        <div style="margin-top:16px">

            <strong style="font-size:13px"><?php _e('📋 กำหนดสิทธิ์ละเอียด (ถ้าเลือก custom หรือต้องการเพิ่มเติม):'); ?></strong>

            <?php foreach ($GLOBALS['ALL_PERMISSIONS'] as $group): ?>

            <div class="avsf-perm-group">

                <h4><?php echo $group['label']; ?></h4>

                <?php foreach ($group['items'] as $key => $label): ?>

                <label class="avsf-perm-item"><input type="checkbox" name="permissions[]" value="<?php echo $key; ?>"> <?php echo esc($label); ?></label>

                <?php endforeach; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <div class="avsf-row" style="margin-top:16px">

            <button class="avsf-btn avsf-btn-primary" type="submit"><?php _e('💾 เพิ่มผู้ใช้'); ?></button>

            <a class="avsf-btn avsf-btn-ghost" href="?action=list"><?php _e('ยกเลิก'); ?></a>

        </div>

    </form>

</div>



<?php elseif ($action === 'edit' && $editUser): ?>

<!-- ====== แก้ไขผู้ใช้ ====== -->

<div class="avsf-card">

    <h3 style="margin-bottom:4px"><?php _e('✏️ แก้ไข:'); ?> <?php echo esc($editUser['username']); ?></h3>

    <p class="avsf-sub" style="margin-bottom:14px"><?php _e('บทบาทปัจจุบัน:'); ?> <strong><?php echo esc($editUser['role']); ?></strong></p>



    <!-- เปลี่ยนรหัสผ่าน (Super Admin ทำได้) -->

    <div class="avsf-perm-group">

        <h4><?php _e('🔑 เปลี่ยนรหัสผ่านให้ผู้ใช้นี้'); ?></h4>

        <form method="post" class="avsf-row">

            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <input type="hidden" name="reset_password" value="1">

            <input name="new_password" type="password" placeholder="<?php echo __('รหัสผ่านใหม่ (อย่างน้อย 6 ตัว)'); ?>" style="flex:1;min-width:0" required>

            <button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit"><?php _e('บันทึกรหัสผ่าน'); ?></button>

        </form>

    </div>



    <!-- 2FA -->

    <div class="avsf-perm-group">

        <h4>🔐 2FA (Google Authenticator / Authy)</h4>

        <?php if ($editUser['twofa_enabled']): ?>

            <p style="font-size:13px;color:#4ade80;margin-bottom:10px"><?php _e('✅ เปิดใช้งานแล้ว'); ?></p>

            <form method="post" onsubmit="return confirm(<?php echo avsf_js(__('ปิด 2FA?')); ?>)">

                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <input type="hidden" name="disable_2fa" value="1">

                <button class="avsf-btn avsf-btn-danger avsf-btn-sm" type="submit"><?php _e('ปิด 2FA'); ?></button>

            </form>

        <?php else: ?>

            <div class="avsf-twofa-box">

                <p style="font-size:12px;color:#9ca3af;margin-bottom:10px"><?php _e('1. เปิดแอป Authenticator → Add account → Enter setup key'); ?></p>

                <p style="font-size:12px;color:#9ca3af;margin-top:8px"><?php _e('พิมพ์ Secret Key นี้ลงในแอปโดยตรง (ไม่ส่ง Secret ไปยังบริการ QR ภายนอก):'); ?></p>

                <div class="avsf-twofa-secret"><?php echo $new2faSecret; ?></div>

                <form method="post" style="margin-top:12px">

                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                    <input type="hidden" name="enable_2fa" value="1">

                    <input type="hidden" name="twofa_secret" value="<?php echo $new2faSecret; ?>">

                    <label style="text-align:left"><?php _e('2. ใส่โค้ด 6 หลักจากแอปเพื่อยืนยัน:'); ?></label>

                    <input name="twofa_code" placeholder="123456" maxlength="6" style="max-width:150px;margin:0 auto;text-align:center;letter-spacing:4px;font-size:18px" required>

                    <button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit" style="margin-top:10px"><?php _e('ยืนยัน & เปิดใช้งาน'); ?></button>

                </form>

            </div>

        <?php endif; ?>

    </div>



    <!-- Role + Permissions -->

    <form method="post">

        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

        <input type="hidden" name="edit_user" value="1">

        <label><?php _e('บทบาท'); ?></label>

        <select name="role" <?php echo $editUser['role']==='owner'?'disabled':''; ?>>

            <?php foreach ($roleDefinitions as $v=>$l):

                if ($v==='owner' && $editUser['role']!=='owner' && !(!$ownerExists && (int)$editUser['id']===$firstUserId && currentUserRole()==='super_admin')) continue;

                if ($v==='super_admin' && currentUserRole()!=='owner' && $editUser['role']!=='super_admin') continue;

                if ($editUser['role']==='owner' && $v!=='owner') continue;

            ?>

            <option value="<?php echo esc($v); ?>" <?php echo $editUser['role']===$v?'selected':''; ?>><?php echo esc($l); ?></option>

            <?php endforeach; ?>

        </select>

        <?php if ($editUser['role']==='owner'): ?>

            <input type="hidden" name="role" value="owner">

            <small style="display:block;color:#fbbf24;margin-top:6px"><?php _e('Owner เป็นบัญชีเจ้าของสูงสุด ไม่สามารถลดสิทธิ์หรือเปลี่ยนบทบาทจากหน้านี้ได้'); ?></small>

        <?php elseif (!$ownerExists && (int)$editUser['id']===$firstUserId && currentUserRole()==='super_admin'): ?>

            <small style="display:block;color:#fbbf24;margin-top:6px"><?php _e('ยังไม่มี Owner ในระบบ บัญชีแรกนี้สามารถเปลี่ยนเป็น Owner ได้'); ?></small>

        <?php endif; ?>

        <div style="margin-top:16px">

            <strong style="font-size:13px"><?php _e('📋 สิทธิ์ละเอียด:'); ?></strong>

            <?php foreach ($GLOBALS['ALL_PERMISSIONS'] as $group): ?>

            <div class="avsf-perm-group">

                <h4><?php echo $group['label']; ?></h4>

                <?php foreach ($group['items'] as $key => $label): ?>

                <label class="avsf-perm-item"><input type="checkbox" name="permissions[]" value="<?php echo $key; ?>" <?php echo in_array($key,$editUser['perms'],true)?'checked':''; ?>> <?php echo esc($label); ?></label>

                <?php endforeach; ?>

            </div>

            <?php endforeach; ?>

        </div>

        <div class="avsf-row" style="margin-top:16px">

            <button class="avsf-btn avsf-btn-primary" type="submit"><?php _e('💾 บันทึกสิทธิ์'); ?></button>

            <a class="avsf-btn avsf-btn-ghost" href="?action=list"><?php _e('กลับรายการ'); ?></a>

        </div>

    </form>

</div>

<?php elseif ($action === 'roles'): ?>

<div class="avsf-card">

    <h3 style="margin-bottom:6px"><?php _e('⚙️ ตั้งค่าขอบเขตสิทธิ์ตามบทบาท'); ?></h3>

    <p class="avsf-sub"><?php _e('กำหนดชุดสิทธิ์เริ่มต้นแยกตามประเภทบัญชี การเปลี่ยนแปลงมีผลเมื่อผู้ใช้เข้าสู่ระบบครั้งถัดไป ส่วน Owner มีสิทธิ์สูงสุดคงที่และไม่สามารถลดทอนได้ ผู้ดูแลกำหนดสิทธิ์ได้เฉพาะบทบาทที่ต่ำกว่าระดับของตน'); ?></p>

    <div style="margin:14px 0 18px;padding:14px;border:1px solid #27273a;border-radius:12px;background:#111827">
        <strong><?php _e('ชุดสิทธิ์สำเร็จรูป'); ?></strong>
        <p style="font-size:12px;color:#9ca3af;margin:5px 0 12px"><?php _e('เลือกชุดจากรายการนี้เพื่อกำหนดสิทธิ์เริ่มต้นครบชุด จากนั้นบันทึกหรือปรับเพิ่มเติมได้ด้านล่าง'); ?></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
        <?php foreach (readyRolePermissionPresets() as $presetRole=>$preset):
            if ($presetRole === 'owner' || (currentUserRole() !== 'owner' && (permissionRoleRank($presetRole) >= permissionRoleRank(currentUserRole()) || $presetRole === 'super_admin'))) continue;
        ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="apply_role_preset" value="1">
                <input type="hidden" name="role" value="<?php echo esc($presetRole); ?>">
                <button class="avsf-btn avsf-btn-secondary" style="width:100%;min-height:42px;text-align:left" type="submit" title="ใช้ชุดสิทธิ์ <?php echo esc($preset['label']); ?>">
                    <strong><?php echo esc($roleDefinitions[$presetRole]); ?></strong><br>
                    <span style="font-size:11px;font-weight:400;opacity:.78"><?php echo esc($preset['label']); ?></span>
                </button>
            </form>
        <?php endforeach; ?>
        </div>
    </div>

    <?php foreach ($roleDefinitions as $role=>$label):

        if ($role==='owner' || (currentUserRole()!=='owner' && permissionRoleRank($role)>=permissionRoleRank(currentUserRole()))) continue;

        $configured = setting('role_permissions_'.$role, '');

        $checkedPermissions = $configured !== '' ? json_decode($configured, true) : defaultPermissionsForRole($role);

        if (!is_array($checkedPermissions)) $checkedPermissions = defaultPermissionsForRole($role);

        $checkedPermissions = normalizePermissionList($checkedPermissions);

    ?>

    <form method="post" class="avsf-perm-group">

        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

        <input type="hidden" name="save_role_permissions" value="1">

        <input type="hidden" name="role" value="<?php echo esc($role); ?>">

        <h4><?php echo esc($label); ?></h4>

        <p style="font-size:11px;color:#9ca3af;margin-bottom:10px"><?php _e('เลือกสิทธิ์ที่บัญชีบทบาทนี้ได้รับเป็นค่าเริ่มต้น'); ?></p>

        <?php foreach ($GLOBALS['ALL_PERMISSIONS'] as $group): ?>

        <div style="padding:8px 0;border-top:1px solid #27273a">

            <strong style="font-size:12px"><?php echo esc($group['label']); ?></strong>

            <?php foreach ($group['items'] as $key=>$permissionLabel): ?>

            <label class="avsf-perm-item"><input type="checkbox" name="permissions[]" value="<?php echo esc($key); ?>" <?php echo in_array($key,$checkedPermissions,true)?'checked':''; ?>> <?php echo esc($permissionLabel); ?></label>

            <?php endforeach; ?>

        </div>

        <?php endforeach; ?>

        <button class="avsf-btn avsf-btn-primary avsf-btn-sm" type="submit"><?php _e('บันทึกสิทธิ์บทบาทนี้'); ?></button>

    </form>

    <?php endforeach; ?>

</div>

<?php endif; ?>



</body>

</html>

