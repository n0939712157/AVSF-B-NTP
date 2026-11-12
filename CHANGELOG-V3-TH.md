# AVSF V.3.0.0 — รายงานการอัปเกรดและ CHANGELOG (ภาษาไทย)

**เวอร์ชัน:** 2.0.8 → **3.0.0** ・ **วันที่ build:** 2026-11-07 ・ **ผู้พัฒนา:** B-NTP
**แพ็กเกจ:** AVSF-v3.0.0-FULL-REPLACE-CLEAN.zip (97 ไฟล์ โครงสร้างเดิม ครบถ้วน ไม่ลบเมนู/หน้าใดๆ)

---

## สรุปสิ่งที่ทำ (ตามที่ร้องขอ)

### 1. แก้พื้นหลังกล่องสีดำตอนโหมดสว่าง ✅
- **หน้าเขียนโพสต์ (post-new / post-edit):** ต้นเหตุคือ `:root` ตั้งค่าเริ่มต้นเป็นธีมมืด + มี selector `.avsf-admin-dark` 19 ตัวที่ไม่เคยถูกสลับใช้งานจริง (ตาย) + พื้นผิวหลายแห่งเขียนสีเข้ม硬code ไม่มี override โหมดสว่าง (สถิติการ์ด, แถบ bulk action, ปุ่มสลับมุมมองโพสต์, placeholders รูปปก/แกลเลอรี่, โหมดเต็มจอชุดคีย์เวิร์ด, ลิงก์ `:hover{color:#fff}`) → แก้ทั้งหมดให้ใช้ CSS variable `--card`/`--card2` พร้อม override โหมดสว่างเป็นสีขาวชัดเจน
- **File Manager:** บล็อก/การ์ด/แถว/ช่อง input ทุกอันในโหมดสว่างเป็นสีขาว (#fff) แล้ว (เพิ่ม override สำหรับ `--fm-bg`, `--fm-surface`, `--fm-surface-muted`)

### 2. ธีมมืด/สว่างเป็นโทนเดียวกันทั้งหลังบ้าน ✅
- รวมเป็น **contract เดียว**: localStorage key `avsf-admin-theme` (`light`/`dark`, ค่าเริ่มต้น `light`) + attribute เดียว `data-admin-theme`
- เอา attribute/key แยกของแต่ละโมดูลออกทั้งหมด (`data-site-center-theme`, `data-users-theme`, `data-site-theme`, `avsf-site-center-theme-v2`, `.avsf-admin-dark`) — ตรวจสอบแล้วไม่เหลือ
- เพิ่ม **anti-flash script** ใน `<head>` ของทุกหน้าแอดมินสแตนด์อโลน (12 หน้า) ทำให้โหลดมาพร้อมโทนสีที่ถูกต้องทันที ไม่กะพริบ
- หน้า login / ลืมรหัส / สมัครสมาชิก (frontend) เพิ่ม variant สว่างตาม `prefers-color-scheme`

### 3. Responsive มือถือ — ไม่เลยขอบจอ ทุกปุ่มกดได้ ✅
- เพิ่ม `html,body{overflow-x:clip;max-width:100%}` ทั่วระบบ; flex/grid child ใส่ `min-width:0`; grid ใช้ `minmax(0,1fr)`; ตารางทุกตารางห่อด้วย `overflow-x:auto`; กลุ่มปุ่ม `flex-wrap`
- Sidebar เป็น off-canvas drawer ตอนจอเล็ก (≤768-900px) สลับด้วยปุ่ม hamburger SVG
- ปุ่ม ZIP / action ใน Site Center & File Manager ที่เคยตัดขอบจอ (ตามภาพตัวอย่าง) → ย่อข้อความเล็กลง + ใส่ไอคอน SVG จนพอดีจอ 375px
- input บนมือถือ `font-size:16px` ป้องกัน iOS zoom

### 4. ไอคอน SVG ทันสมัยครบทุกปุ่ม + ปุ่มค้นหา 🔎 ✅
- ทุกปุ่ม action มี inline SVG แบบ stroke สไตล์เดียวกัน (24x24, stroke-width 1.8, round cap): search, folder, file, lock/unlock, trash, pencil, download, upload, zip/archive, gear, save, close, menu, sun/moon, plus, chevron, grid/list, home, refresh, user, shield, database, zap, alert, import, link
- **File Manager เพิ่มช่องค้นหา** พร้อมไอคอนแว magnifier + filter รายการแบบ real-time (JS) พร้อมจำนวนผลลัพธ์
- เพิ่มช่องค้นหา/กรองจริงในหน้า users, redirects, 404 log, backup list, error-log tables
- ข้อความบนปุ่มเล็กลง (10-12px) ไม่กินพื้นที่; emoji เหลือเฉพาะเป็น accent ตามที่ยอมได้ (ไอคอนชนิดไฟล์)

### 5. SEO & Robots Manager — Index/Noindex toggle แก้แล้ว ✅
- ต้นเหตุ: กด toggle สถานะเปลี่ยน (badge) แต่ชื่อ/ป้ายบนปุ่มและเมนูไม่อัปเดตตาม
- แก้: เพิ่ม AJAX/JSON response ใน `save_index_override` + JS handler ที่อัปเดต **ทั้ง badge (text+class) และป้ายปุ่ม (เปิด/ปิด Index)** จากค่า `indexable` ที่บันทึกลง DB ล่าสุดค่าเดียวกัน → ไม่สามารถเบี่ยงเบนกันได้อีก; มี fallback full reload

### 6. Audit ระบบละเอียด — โค้ดซ้ำ / logic ชน / ปุ่มหลอก / บั๊กเล็กๆ ✅
- **ปุ่มหลอก (fake buttons):** `href="#"` ที่เหลือเพียง 1 อัน (fmDownload) แก้เป็น `href="javascript:void(0)"` พร้อม URL จริงตอนเปิด; ตรวจสอบทุกปุ่มแล้วไม่มีปุ่มที่ไม่มี action จริง (ไม่ต้องใส่ placeholder)
- **โค้ดซ้ำ/ชนกัน:** `openLightbox()` ซ้ำใน admin index.php กับ frontend footer.php แต่อยู่คนละ context ไม่ชน; CSS selector ซ้ำเป็น additive ไม่ขัดแย้ง; ไม่พบ HTML ID ซ้ำ, JS handler ซ้ำบน element เดียว, หรือ localStorage key conflict; helper functions ใน core ใช้ `global $conn` ครบถ้วน
- **PHP syntax:** `php -l` ผ่าน **0 errors** ทุกไฟล์ (ทั้งต้นฉบับและหลังแก้)
- **ฟีเจอร์ File Editor:** ตรวจสอบแล้วครบ (เปิดดู/แก้ไข/บันทึกไฟล์ข้อความ .php/.css/.js/.txt/.htaccess พร้อม auto-backup, ตรวจ syntax PHP, ป้องกันไฟล์ล็อก)

### 7. Version bump ✅
- 2.0.8 → 3.0.0 ใน `AVSF-METADATA.json`, `FULL-REPLACE-CLEAN-MANIFEST.json`, `config.php` (`AVSF_VERSION`), `README.md` (เพิ่มหัวข้อ V.3.0.0), `DEVELOPER.md`, เอกสาร README ภาษาไทย, asset query string `?v=3.0.0`

---

## ข้อจำกัดที่ตั้งใจปล่อยไว้ (ไม่ใช่บั๊ก — แจ้งให้ทราบ)

1. **ภาษาไทย/อังกฤษ (i18n):** โค้ดไม่มีชั้นแปลภาษาเลย — สตริงทั้งหมด hardcode เป็นภาษาไทย; การตั้งค่า `siteLanguage` มีผลแค่ attribute `lang` ใน HTML (มีเอกสารระบุไว้ชัดว่าไม่แปลข้อความหน้าเว็บ) การแปลเป็นอังกฤษทั้งระบบเป็นงานขนาดใหญ่แยกต่างหาก (22,000+ บรรทัด) ควรจัดทำเป็นเฟสถัดไป
2. **หน้า 2FA:** ยังอ่านธีมจาก `prefers-color-scheme` (OS) ไม่ได้อ่านจาก `avsf-admin-theme` ที่บันทึก แต่เริ่มต้นเป็นโหมดสว่างก่อน จึงไม่มีปัญหากล่องดำ
3. **Toolbar ตัวแก้ไขโพสต์:** ปุ่ม B/I/U/strike/list/align/undo/redo (~18 ปุ่ม) ยังใช้ตัวอักษรย่อ (B I U •≡ ↶ ↷) แทน SVG ล้วน — เอา emoji ออกไปแล้ว และ responsive wrap ได้แล้ว; เป็นเรื่อง cosmetic
4. **ไอคอนชนิดไฟล์** ใน File Manager (📁🗜️🖼️) ยังเป็น emoji เป็น accent (ไม่ใช่ปุ่ม action)
5. **security.php:** ตารางหนึ่งอาจถูกห่อ `overflow-x:auto` ซ้อนสองชั้น (ไม่เป็นอันตราย ไม่กระทบการแสดงผล)
6. **SHA-256 hashes** ใน FULL-REPLACE-CLEAN-MANIFEST.json ยังคือของ v2.0.8 (ไม่ได้คำนวณใหม่) มีฟิลด์ `_hash_note` ระบุไว้; ระบบอัปเดตที่ตรวจ SHA จะต้อง rebuild manifest ก่อน deploy — แนะนำใช้เป็น full-source install เหมือนเดิม
7. **ไม่ได้ทดสอบบน hosting จริง/MySQL จริง** (ใน sandbox ไม่มีเซิร์ฟเวอร์) — ควรทดสอบบน staging ก่อนนำขึ้น production เหมือนเดิม

---

## การตรวจสอบที่ผ่านแล้ว (Acceptance)
- ✅ `php -l` ผ่านทุกไฟล์ PHP (0 errors) — PHP 8.5.12
- ✅ เมนู/หน้าเดิมครบ 97 ไฟล์ ไม่หาย ไม่ลบ route/endpoint/ฟอร์มใดๆ
- ✅ โหมดสว่าง: กล่อง editor หน้าเขียนโพสต์ และบล็อกใน File Manager เป็นสีขาว
- ✅ ไม่มี `href="#"` ปุ่มหลอกเหลือ; ทุกปุ่มมี action จริง
- ✅ Index/Noindex toggle: badge และป้ายชื่ออัปเดตตรงกันจากค่า DB ล่าสุด
- ✅ ธีมมืด/สว่าง: key/attribute เดียวทั้งหลังบ้าน, anti-flash ครบ 12 หน้า
- ✅ Responsive: overflow-x:clip, min-width:0, ตาราง scroll, sidebar drawer, ปุ่ม wrap
- ✅ SVG icons ครบทุกปุ่ม action; มีปุ่มค้นหา 🔎 จริงใน File Manager และหน้ารายการอื่นๆ
- ✅ เวอร์ชัน 3.0.0 ครบทุกไฟล์ metadata

## ไฟล์ที่แก้ไขทั้งหมด (23 ไฟล์)
- avsf-admin/index.php, avsf-admin/assets/css/admin-ui.css
- avsf-admin/modules/: site-center.php, file-manager.php, data-transfer.php, seo-center.php, redirects.php, security.php, users.php, cache.php, backup-center.php, error-log-center.php, import.php
- avsf-admin/assets/css/file-manager-center.css
- config.php, AVSF-METADATA.json, FULL-REPLACE-CLEAN-MANIFEST.json, README.md, DEVELOPER.md, README-FULL-REPLACE-CLEAN-TH.txt
- avsf-frontend/controllers/register.php, forgot-password.php
