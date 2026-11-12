<?php
/**
 * AVSF — ระบบสิทธิ์ผู้ใช้ (RBAC)
 * ใช้: require_once AVSF_CORE_DIR . '/auth/permissions.php';
 * 
 * Owner is the immutable top-level role. Other role presets are configurable.
 */

if (!defined('PERMISSIONS_DEFINED')) {
define('PERMISSIONS_DEFINED', true);

// ====== รายการสิทธิ์ทั้งหมด (key => ชื่อไทย + หมวดหมู่) ======
$GLOBALS['ALL_PERMISSIONS'] = [
    'posts' => [
        'label' => '📝 โพสต์',
        'items' => [
            'posts.view'     => 'ดูรายการโพสต์',
            'posts.create'   => 'สร้างโพสต์ใหม่',
            'posts.edit'     => 'แก้ไขโพสต์ (ของตัวเอง)',
            'posts.edit_all' => 'แก้ไขโพสต์ (ของคนอื่นด้วย)',
            'posts.delete'   => 'ลบโพสต์',
            'posts.publish'  => 'เผยแพร่/เปลี่ยนสถานะโพสต์',
            'posts.import'   => 'นำเข้าโพสต์จำนวนมาก',
            'posts.export'   => 'ส่งออกโพสต์ทั้งหมดเป็น WordPress XML',
        ],
    ],
    'comments' => [
        'label' => '💬 คอมเมนต์',
        'items' => [
            'comments.view'     => 'ดูคอมเมนต์',
            'comments.moderate' => 'อนุมัติ/ลบ/ตอบคอมเมนต์',
        ],
    ],
    'taxonomy' => [
        'label' => '🏷️ หมวดหมู่/นักแสดง/แท็ก',
        'items' => [
            'taxonomy.manage' => 'จัดการค่าย/นักแสดง/แท็ก (เพิ่ม/แก้/ลบ)',
        ],
    ],
    'appearance' => [
        'label' => '🎨 ตั้งค่าเว็บ & ธีม',
        'items' => [
            'settings.view'   => 'ดูตั้งค่าเว็บ',
            'settings.edit'   => 'แก้ไขตั้งค่าเว็บ (ชื่อ, สี, เมนู, โฆษณา)',
            'theme.edit'      => 'แก้ไขธีม/หน้าแสดงผล',
            'banners.manage'  => 'จัดการ Banner/สไลด์',
            'pages.manage'    => 'จัดการหน้าคงที่ (About/Contact ฯลฯ)',
        ],
    ],
    'system' => [
        'label' => '⚙️ ระบบ & ความปลอดภัย',
        'items' => [
            'cache.manage'     => 'จัดการแคช (เปิด/ปิด/ล้าง)',
            'security.view'    => 'ดู Security Center',
            'security.scan'    => 'สแกน/ล้างมัลแวร์, quarantine',
            'backup.download'  => 'ดาวน์โหลดสำรองข้อมูล',
            'sitecenter.view'  => 'เข้าศูนย์เว็บไซต์',
            'seo.manage'       => 'จัดการ SEO, Index และ Sitemap',
            'redirects.manage' => 'จัดการ Redirect และรายงาน URL',
            'sitecenter.tools' => 'ใช้เครื่องมือระบบในศูนย์เว็บไซต์',
            'errorlog.view'    => 'ดู Error Log',
        ],
    ],
    'users' => [
        'label' => '👥 จัดการผู้ใช้ (เฉพาะ Super Admin)',
        'items' => [
            'users.manage' => 'เพิ่ม/แก้ไข/ลบผู้ใช้ + กำหนดสิทธิ์',
        ],
    ],
];

// ====== ดึงสิทธิ์ของ user ปัจจุบัน ======
if (!function_exists('currentUserPermissions')) {
    function permissionRoleDefinitions() {
        return [
            'owner' => 'Owner — เจ้าของเว็บไซต์',
            'super_admin' => 'Super Admin — ผู้ดูแลระบบ',
            'deputy_admin' => 'รองแอดมิน',
            'editor' => 'บรรณาธิการ',
            'moderator' => 'ผู้ตรวจ',
            'author' => 'ผู้เขียน',
            'custom' => 'แอดมินทั่วไป — โพสต์และเผยแพร่',
            'user' => 'ผู้ใช้ทั่วไป',
        ];
    }

    function permissionKeys() {
        $keys = [];
        foreach ($GLOBALS['ALL_PERMISSIONS'] as $group) {
            foreach ($group['items'] as $key => $label) $keys[] = $key;
        }
        return $keys;
    }

    function permissionRoleRank($role) {
        $ranks = ['owner'=>100,'super_admin'=>90,'deputy_admin'=>80,'editor'=>60,'moderator'=>50,'author'=>40,'custom'=>20,'user'=>10];
        return $ranks[$role] ?? 0;
    }

    function defaultPermissionsForRole($role) {
        $all = permissionKeys();
        $presets = [
            'owner' => $all,
            'super_admin' => $all,
            'deputy_admin' => [
                'posts.view','posts.create','posts.edit','posts.edit_all','posts.delete','posts.publish','posts.import','posts.export',
                'comments.view','comments.moderate','taxonomy.manage','settings.view','settings.edit','theme.edit',
                'banners.manage','pages.manage','cache.manage','security.view','backup.download','sitecenter.view',
                'seo.manage','redirects.manage','sitecenter.tools','errorlog.view','users.manage'
            ],
            'editor' => [
                'posts.view','posts.create','posts.edit','posts.edit_all','posts.delete','posts.publish','posts.import','posts.export',
                'comments.view','comments.moderate','taxonomy.manage'
            ],
            'moderator' => ['comments.view','comments.moderate','posts.view'],
            'author' => ['posts.view','posts.create','posts.edit'],
            'custom' => ['posts.view','posts.create','posts.edit','posts.publish'],
            'user' => [],
        ];
        return $presets[$role] ?? [];
    }

    function readyRolePermissionPresets() {
        return [
            'super_admin' => ['label' => 'Super Admin — สิทธิ์เต็มระบบ', 'permissions' => permissionKeys()],
            'deputy_admin' => ['label' => 'รองแอดมิน — จัดการเนื้อหาและระบบเว็บไซต์', 'permissions' => defaultPermissionsForRole('deputy_admin')],
            'editor' => ['label' => 'บรรณาธิการ — โพสต์ทั้งหมดและคอมเมนต์', 'permissions' => defaultPermissionsForRole('editor')],
            'moderator' => ['label' => 'ผู้ตรวจ — คอมเมนต์และดูโพสต์', 'permissions' => defaultPermissionsForRole('moderator')],
            'author' => ['label' => 'ผู้เขียน — เขียนและแก้โพสต์ของตนเอง', 'permissions' => defaultPermissionsForRole('author')],
            'custom' => ['label' => 'แอดมินทั่วไป — เขียน แก้ไข และเผยแพร่โพสต์', 'permissions' => defaultPermissionsForRole('custom')],
            'user' => ['label' => 'ผู้ใช้ทั่วไป — ไม่เข้าหลังบ้าน', 'permissions' => []],
        ];
    }

    function normalizePermissionList($permissions) {
        if (!is_array($permissions)) return [];
        if (in_array('*', $permissions, true)) return ['*'];
        return array_values(array_unique(array_filter($permissions, function($permission) {
            return is_string($permission) && in_array($permission, permissionKeys(), true);
        })));
    }

    function currentUserPermissions() {
        if (empty($_SESSION['avsf_admin'])) return [];
        $perms = $_SESSION['user_permissions'] ?? [];
        if (is_string($perms)) $perms = json_decode($perms, true) ?: [];
        return normalizePermissionList($perms);
    }

    function currentUserRole() {
        return $_SESSION['user_role'] ?? '';
    }

    function isSuperAdmin() {
        return in_array(currentUserRole(), ['owner','super_admin'], true);
    }

    // ====== เช็คสิทธิ์ — คืน true/false ======
    function can($permission) {
        if (currentUserRole() === 'owner') return true;
        $perms = currentUserPermissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    // ====== เช็คสิทธิ์ ถ้าไม่มีให้ตาย/redirect ======
    function requirePermission($permission) {
        if (!can($permission)) {
            http_response_code(403);
            die('<div style="font-family:sans-serif;text-align:center;padding:60px;"><h1>⛔ ไม่มีสิทธิ์เข้าถึง</h1><p>คุณไม่มีสิทธิ์ใช้งานส่วนนี้ กรุณาติดต่อ Super Admin</p><a href="index.php" style="color:#2563eb">← กลับแดชบอร์ด</a></div>');
        }
    }

    function requireSuperAdmin() {
        if (!function_exists('isAdmin') || !isAdmin()) {
            header('Location: index.php');
            exit;
        }
        if (!in_array(currentUserRole(), ['owner','super_admin'], true)) {
            http_response_code(403);
            die('<div style="font-family:sans-serif;text-align:center;padding:60px;"><h1>⛔ ไม่มีสิทธิ์เข้าถึง</h1><p>ส่วนนี้เปิดให้ใช้งานเฉพาะ Owner หรือ Super Admin</p><a href="index.php" style="color:#2563eb">← กลับแดชบอร์ด</a></div>');
        }
    }

    function requireSiteCenterAccess() {
        if (!function_exists('isAdmin') || !isAdmin()) {
            header('Location: index.php');
            exit;
        }
        if (!can('sitecenter.view')) {
            http_response_code(403);
            die('<div style="font-family:sans-serif;text-align:center;padding:60px;"><h1>⛔ ไม่มีสิทธิ์เข้าถึง</h1><p>บัญชีนี้ยังไม่ได้รับสิทธิ์เข้าศูนย์เว็บไซต์</p><a href="index.php" style="color:#2563eb">← กลับแดชบอร์ด</a></div>');
        }
    }

    // ====== โหลดสิทธิ์จาก DB ใส่ session (เรียกตอน login สำเร็จ) ======
    function loadUserPermissions($userId, $conn) {
        $userId = (int)$userId;
        $result = mysqli_query($conn, "SELECT role, permissions FROM users WHERE id = $userId LIMIT 1");
        if (!$result) {
            error_log('AVSF permission lookup failed: '.mysqli_error($conn));
            $_SESSION['user_role'] = 'custom';
            $_SESSION['user_permissions'] = [];
            return;
        }
        if ($result && $row = mysqli_fetch_assoc($result)) {
            $role = $row['role'] ?: 'custom';
            if (!isset(permissionRoleDefinitions()[$role])) $role = 'custom';
            $_SESSION['user_role'] = $role;
            $userPerms = json_decode($row['permissions'] ?: '[]', true);
            if (!is_array($userPerms)) $userPerms = [];
            $configured = function_exists('setting') ? setting('role_permissions_' . $role, '') : '';
            $rolePerms = $configured !== '' ? json_decode($configured, true) : defaultPermissionsForRole($role);
            if (!is_array($rolePerms)) $rolePerms = defaultPermissionsForRole($role);
            $_SESSION['user_permissions'] = normalizePermissionList(array_merge($rolePerms, $userPerms));
        } else {
            $_SESSION['user_role'] = 'custom';
            $_SESSION['user_permissions'] = [];
        }
    }

    // ====== แสดงเมนูเฉพาะที่มีสิทธิ์ ======
    function menuIf($permission, $html) {
        return can($permission) ? $html : '';
    }
}

} // PERMISSIONS_DEFINED

// ห้ามเรียกโดยตรง
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'permissions.php') {
    http_response_code(403);
    exit;
}
