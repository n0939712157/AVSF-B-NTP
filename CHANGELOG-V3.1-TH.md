# AVSF CHANGELOG — v3.1.1 (2026-11-07)

**เวอร์ชันก่อนหน้า:** 3.1.0 · **ผู้เผยแพร่:** B-NTP · **ประเภท:** Full Replace Clean Update / Hotfix
**ไฟล์ส่งมอบ:** `AVSF-B-NTP-v3.1.1-FULL-REPLACE-CLEAN.zip`

## การแก้ไขใน v3.1.1
- นำ `config.local.php` ออกจากแพ็กเกจ เพราะเป็นไฟล์เฉพาะเว็บไซต์ที่มีข้อมูลการเชื่อมต่อฐานข้อมูลจาก snapshot เก่า ห้ามนำไปทับค่าของเว็บไซต์ปัจจุบัน
- แก้ `avsf-core/lang/en/core-frontend.php` ให้ initialize และ `return $avsf_lang` ตามรูปแบบที่ `avsf-core/i18n.php` โหลดจริง
- อัปเดต `config.php`, `AVSF-METADATA.json`, `DEVELOPER.md`, README และ Manifest เป็น v3.1.1
- สร้าง Manifest SHA-256 ใหม่จากไฟล์ที่ส่งมอบจริง โดยไม่นับ Manifest เองใน `file_count`
- ไม่เปลี่ยน database schema; ไม่รวม SQL dump, `.env` หรือสื่อ uploads จริง

## ขอบเขตภาษา
ระบบมีตัวสลับ TH/EN และพจนานุกรมประมาณ 1,544 entries ตามไฟล์ต้นทาง แต่ยังมี composite HTML fragments และข้อความเฉพาะบางจุดที่ตั้งใจ fallback เป็นภาษาไทยตามรายงาน v3.1.0 จึงไม่กล่าวอ้างว่าคำแปลครบทุกข้อความ 100%

## การตรวจ v3.1.1
- PHP syntax และ dictionary return types ผ่าน
- ทดสอบโหลด i18n ด้วย PHP CLI และตรวจตัวอย่างคำแปลจาก core/admin/frontend
- ZIP CRC, Path uniqueness และ SHA-256 Manifest ผ่าน
- ยังไม่ได้ทดสอบบน Hosting/MySQL จริง หรือทดสอบ UI End-to-End ทุกปุ่ม

---


**เวอร์ชันก่อนหน้า:** 3.0.0 · **ผู้เผยแพร่:** B-NTP · **ประเภท:** Full Replace Clean Update
**ไฟล์ส่งมอบ:** `AVSF-v3.1.0-FULL-REPLACE-CLEAN.zip` (108 ไฟล์, SHA-256 คำนวณใหม่ทุกไฟล์)

---

## สรุป 4 จุดที่แก้ไข

### 1. ระบบ i18n รองรับภาษาอังกฤษ/ไทยทั้งระบบ (ฟีเจอร์ใหญ่)
- **เพิ่มชั้นแปลภาษา** `avsf-core/i18n.php` (โหลดอัตโนมัติผ่าน `bootstrap.php` หลัง `session_start`)
  - Helper: `__($key, $args)`, `_e()`, `avsf_js()` (สำหรับ string ใน JS), `avsf_lang_attr()` (สำหรับ `<html lang>`), `avsf_lang_switcher_html()` (ปุ่มสลับภาษา)
  - ตรวจจับภาษาตามลำดับ: `?lang=` → cookie `avsf_lang` → session → ค่าเริ่มต้น **ไทย (th)**
  - พจนานุกรมแบบแยกส่วน (merged partials): `avsf-core/lang/<locale>/*.php` แต่ละไฟล์ `return ['ข้อความไทย'=>'English', ...]` — ขาดคีย์จะ fallback เป็นภาษาไทยอัตโนมัติ ไม่เคยแสดงผลผิด
- **เพิ่มไฟล์พจนานุกรมอังกฤษ 4 ไฟล์** (รวม ~1,544 คีย์):
  - `avsf-core/lang/en/_core.php` (คำทั่วไป)
  - `avsf-core/lang/en/admin-index.php` (332 คีย์ — หน้าแอดมินหลัก/เข้าสู่ระบบ/2FA/ตัวแก้ไขโพสต์)
  - `avsf-core/lang/en/admin-modules.php` (892 คีย์ — 11 โมดูลแอดมิน)
  - `avsf-core/lang/en/core-frontend.php` (320 คีย์ — core/frontend/installer)
- **แปลงสตริงไทยทั้งหมดที่ผู้ใช้เห็น** ใน admin (12 หน้า) + frontend public pages + installer + root pages ให้ผ่าน `__()` — `<title>`, heading, label, ปุ่ม, `<option>`, table header, tooltip `title`/`aria-label`, `placeholder`, ข้อความ success/error, `confirm()`/`alert()` (ผ่าน `avsf_js()`), JSON API message
- **เพิ่ม UI สลับภาษา (TH/EN)** ในทุกหน้า: แอดมิน (แถบบน/หลัง back-link), หน้า login, frontend (ที่ `partials/header.php` แชร์ทั้งไซต์), หน้า register/forgot-password แบบสแตนด์อโลน
- `<html lang="th">` ทุกหน้าเปลี่ยนเป็น dynamic ตามภาษาที่เลือก

### 2. หน้า 2FA เข้าระบบธีม (Theme Fix)
- **ปัญหา:** หน้ายืนยัน 2FA (`avsf-admin/index.php` บล็อก ~line 101) มี anti-flash script อ่าน `localStorage avsf-admin-theme` อยู่แล้ว แต่ CSS dark mode ถูกจำกัดด้วย `@media(prefers-color-scheme:dark)` ทำให้ตามธีม OS ไม่ใช่ธีมที่ผู้ใช้บันทึก
- **แก้:** เปลี่ยน selector จาก `@media(prefers-color-scheme:dark){...}` เป็น `[data-admin-theme="dark"],.avsf-theme-dark{...}` (ตรงกับ pattern หน้า login และ 11 โมดูล) — คงกฎ CSS ทุกตัวไว้เหมือนเดิม
- **ตรวจสอบ:** `prefers-color-scheme` เหลือ 0 จุดใน index.php

### 3. Toolbar ตัวแก้ไขโพสต์เป็น SVG icon ทั้งหมด
- **ปัญหา:** ปุ่มบน rich-editor toolbar (`$editorToolbar` ~line 2810) ใช้ตัวอักษรย่อ/ยูนิโคด (B, I, U, S, •≡, 1., ❝, ⇤, ↔, ⇥, ―, T↓, Tx, Ω, ↶, ↷, ?)
- **แก้:** แปลง **19 ปุ่ม** เป็น inline SVG สไตล์ Feather/Lucide (`stroke="currentColor" stroke-width="1.8"`, ขนาด 15×15) ตรงกับปุ่ม link/unlink/fullscreen ที่เป็น SVG อยู่แล้ว — **คงทุก attribute** (`type`, `data-rich-action`, `title`, `aria-label`) JS handler ยังทำงานปกติ
- **ตรวจสอบ:** `data-rich-action` ครบ 26 จุด, ไม่มี glyph ตัวอักษรเหลือ; ปุ่มสี (A, ▰) และ "อ่านต่อ" (ข้อความ label — แปลผ่าน i18n แล้ว) คงไว้ตามเดิม

### 4. คำนวณ SHA-256 ใหม่ + bump version
- `FULL-REPLACE-CLEAN-MANIFEST.json`: recompute SHA-256 ทุกไฟล์ (108 ไฟล์), ลบ `_hash_note` ที่ระบุว่ายังไม่ได้คำนวณ, เปลี่ยน `version` → **3.1.0**, `build_date` → 2026-11-07, `file_count` → 108, เพิ่มไฟล์ใหม่ทั้งหมดลงใน manifest
- `AVSF-METADATA.json`: version → 3.1.0, อัปเดต release notes + เพิ่มข้อมูล i18n
- `config.php`: `AVSF_VERSION` → 3.1.0
- **เลือก bump เป็น 3.1.0** (ไม่ใช่ 3.0.1) เนื่องจาก i18n เป็น feature ใหญ่ที่ขยายความสามารถของระบบ

---

## ไฟล์ที่เพิ่มใหม่ (7 ไฟล์)
| ไฟล์ | จุดประสงค์ |
|---|---|
| `avsf-core/i18n.php` | ชั้นแปลภาษา + helper + ตรวจจับ locale + switcher |
| `avsf-core/lang/en/_core.php` | พจนานุกรมคำทั่วไป |
| `avsf-core/lang/en/admin-index.php` | พจนานุกรมหน้าแอดมินหลัก (332 คีย์) |
| `avsf-core/lang/en/admin-modules.php` | พจนานุกรม 11 โมดูลแอดมิน (892 คีย์) |
| `avsf-core/lang/en/core-frontend.php` | พจนานุกรม core/frontend/installer (320 คีย์) |
| `I18N-CONVERSION-SPEC.md` | สเปคการแปลง i18n (สำหรับผู้พัฒนาต่อ) |
| `shard-E-i18n-report.md` | รายงานการแปลง shard core/frontend |

## ไฟล์ที่แก้ไข
- **ทุกหน้าแอดมิน:** `avsf-admin/index.php` (รวม fix 2FA + toolbar SVG) + 11 ไฟล์ใน `avsf-admin/modules/`
- **Frontend:** `avsf-frontend/` — partials, pages, loop-templates, taxonomy-templates, controllers, runtime, index.php
- **Core:** `avsf-core/functions.php`, `security/security_lib.php`, `database/auto-install.php`, `api/vdohide-api.php`, `import/avsf-image-matcher.php`, `bootstrap.php` (hook โหลด i18n)
- **Installer:** `avsf-installer/setup.php`, `server-reconnect.php`, `db-setup.php`, `migrate-storage.php` (db-setup/server-reconnect โหลด i18n โดยตรงก่อน config.local.php มีอยู่)
- **Root:** `forgot-password.php`, `register.php`, `rss.php`, `config.php`, `AVSF-METADATA.json`, `FULL-REPLACE-CLEAN-MANIFEST.json`
- **ไม่ได้แตะ:** เส้นทางเมนู/route, ชื่อไฟล์, CSS class/ID, data-* attribute, ชื่อฟอร์มฟิลด์, SQL, โครงสร้าง HTML/JS, ทุกไฟล์เดิม 97+ คงครบ

---

## การตรวจสอบ (Verification)
- **PHP syntax check:** ใช้ `php-parser` (npm) แทน `php -l` (เครื่องไม่มี PHP binary) — ตรวจสอบ **83 ไฟล์ .php ทั้งหมด** ผ่าน 0 error (`checked=83 failed=0`)
- **2FA theme:** `prefers-color-scheme` = 0 จุด; dark selector ใช้ `[data-admin-theme="dark"]` ครบ
- **Toolbar SVG:** `data-rich-action` ครบ 26 จุด; ไม่มี `<strong>B</strong>`/`<em>I</strong>`/`<u>U</u>`/`<s>S</s>`/↶/↷ เหลือ
- **JS handler:** ผูกตาม `data-rich-action` attribute ซึ่งคงไว้ครบ — ไม่เสียหายจากการเปลี่ยน inner content เป็น SVG
- **i18n fallback:** คีย์ใดขาดจะแสดงภาษาไทย (default) — ไม่มีทางแสดงผลว่าง/ผิด

## สตริงที่เจตนาไม่แปล (Skipped — ระบุตาม acceptance)
- `logActivity()` (บันทึก audit log ภาษาไทย — consistency กับ log เก่า), `error_log()`, ข้อความ `throw new Exception/RuntimeException` (internal technical errors)
- `fmtThaiDate()` Thai date formatter, regex ช่วง `[ก-๙]`, CSV header, seed-data default (แปลตอนอ่านผ่าน `setting()` ที่ wrap แล้ว), JSON-LD `inLanguage` metadata
- **~33 composite HTML-fragment keys** ในหน้า settings ขั้นสูงของแอดมิน (เป็นก้อนการ์ด HTML ทั้งก้อน) — ยังแสดงไทยในโหมด EN (fallback ปลอดภัย) สามารถขยายแปลทีละส่วนได้ใน patch ถัดไป
- `confirm()` ใน raw `<script>` บางจุดของ file-manager.php (อยู่ใน JS code ไม่สามารถ wrap ผ่าน regex ได้อย่างปลอดภัย)
- หน้า installer wizard ไม่มีปุ่มสลับภาษา (เป็น one-time POST flow; `?lang=` จะขัดขั้นตอน) แต่ `<html lang>` เป็น dynamic และแปลครบ

## การติดตั้ง
ใช้เป็น Full Replace Clean Update เหมือน v3.0.0 — ทับไฟล์ทั้งหมด, manifest ตรวจสอบ SHA-256 ได้ทุกไฟล์, ฐานข้อมูลไม่ต้อง migrate (ไม่มีการเปลี่ยน schema)
