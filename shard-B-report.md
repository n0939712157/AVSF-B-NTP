# Shard B Report — AVSF V.3 Upgrade

**Files owned (all edited in place):**
- `avsf-admin/modules/site-center.php`
- `avsf-admin/modules/file-manager.php`
- `avsf-admin/modules/data-transfer.php`
- `avsf-admin/assets/css/file-manager-center.css`

## 1. Theme Unification (highest priority)

**file-manager-center.css**
- Renamed `:root[data-site-center-theme="dark"]` → `:root[data-admin-theme="dark"]`.
- Added explicit `:root[data-admin-theme="light"]` block with WHITE surfaces (`--fm-surface:#fff`, `--fm-surface-muted:#f8fafc`, `--fm-bg:#f3f6fb`).
- Default `:root` now light-mode values per SPEC palette; `color-scheme` set on both themes.
- Added `html{max-width:100%;overflow-x:clip}` and `body{overflow-x:clip !important}`.
- Added `.avsf-fm-search` styles (magnifier positioned inside input) and `.avsf-btn svg` sizing.

**file-manager.php** (standalone page, own inline `<style>`)
- Added anti-flash `<script>` in `<head>` BEFORE `<style>`, reading `localStorage['avsf-admin-theme']` (default `light`), setting `data-admin-theme` on `<html>`.
- `<body>` now static `data-admin-theme="light"`.
- Replaced hardcoded-dark `:root` vars with light defaults + explicit `:root[data-admin-theme="dark"]` override block (18 new theme vars: `--fm-topbar-bg`, `--fm-editor-bg/text`, `--fm-msg-ok/err/warn-*`, `--fm-update-*`, `--fm-upload-border`, `--fm-row-hover`, `--fm-link`, `--fm-folder`).
- Replaced every hardcoded dark surface in inline CSS with theme vars: topbar `rgba(14,18,27,.96)`→`var(--fm-topbar-bg)`, editor textareas `#090d15`/`#080b12`→`var(--fm-editor-bg)`, messages `#103828/#421b27/#3a2c15`→`var(--fm-msg-*-bg)`, update panel `linear-gradient(#151d2b,#101620)`+`#34445e` border→vars, upload dashed border `#53617a`→`var(--fm-upload-border)`, row hover `rgba(255,255,255,.025)`→`var(--fm-row-hover)`, file-list code color `#d7e3f6`→`var(--fm-text)`, divider `rgba(255,255,255,.06)`→`var(--fm-border)`, editor notices→vars.
- `meta theme-color` changed from `#11151f` (dark) to `#f3f6fb` (light).

**site-center.php**
- Added same anti-flash script in `<head>`; `<body data-admin-theme="light">`.
- Renamed ALL `data-site-center-theme` → `data-admin-theme` (4 occurrences: embedded iframe style selectors, frame theme sync, `syncSiteCenterFrameTheme`).
- Renamed localStorage key `avsf-site-center-theme-v2` → `avsf-admin-theme`.
- Theme toggle now also sets `document.documentElement.setAttribute('data-admin-theme', next)` on init and on click (previously only `shell.dataset.theme`), so the whole page + admin-ui.css theme selectors respond.
- Fixed hardcoded-dark inline style on the "สีหลังบ้าน" fallback link (`background:#1a2030;color:#e5e7eb;border:#303748`) → `var(--card/#fff)`, `var(--text/#19304f)`, `var(--border/#dfe6f0)`.

**data-transfer.php**
- Added anti-flash script in `<head>`.
- Added explicit `:root` (light) + `:root[data-admin-theme="dark"]` blocks defining `--bg/--card/--card2/--text/--muted/--border/--accent`; removed dark fallbacks (`#090b10`, `#11151f`, `#161b26`, `#293142`) from body/card/button rules. Added `html,body{max-width:100%;overflow-x:clip}`.

## 2. File Manager features

**(a) Search box — NEW, wired for real**
- Added `<input type="search" id="fmSearchInput">` with inline magnifier SVG (circle+handle, positioned absolute left) + live count `<span id="fmSearchCount">`, placed between breadcrumb and bulk-actions bar.
- JS filter (added to existing IIFE): on `input`, reads `row.dataset.name`, hides non-matching `.avsf-fm-row` via `style.display`, shows `N / total รายการ` count. No page reload — pure client-side filter on current folder listing.

**(b) File edit/view/save — verified complete**
- Full-screen editor already existed and is complete: opens on `?file=`, loads content into `<textarea name="content">`, POSTs `action=save` with CSRF; server does sensitive-file check, lock check, PHP `token_get_all` syntax validation, auto-backup to temp dir, atomic rename, opcache invalidate, cache clear. Save button disabled when locked. Added confirm-free safe flow (already non-destructive with auto-backup). Editor textarea/notice colors now theme-aware.

**(c) Button overflow fix (the dark ZIP buttons cut off in ref images)**
- Topbar: "ดาวน์โหลด ZIP โฟลเดอร์นี้" (28 chars) → short label "ZIP" + archive SVG; "← ศูนย์รวมเว็บไซต์" → "ศูนย์เว็บ" + back-arrow SVG. Both now fit in `.avsf-fm-tools` with `flex-wrap:wrap` (already present) and `flex:1` on mobile.
- Row actions: "ดาวน์โหลด" → "ดาวน์" + download SVG; "⋯ จัดการ" → "จัดการ" + vertical-dots SVG; "ZIP" keeps short label + archive SVG. All row buttons now 11px labels with 14px SVG, flex-wrap in `.avsf-fm-row-actions`. On ≤620px they get `flex:1` and wrap.
- Toolbar: "＋ โฟลเดอร์"→folder+plus SVG+"โฟลเดอร์"; "＋ ไฟล์"→file+plus SVG+"ไฟล์"; "↑ อัปโหลดไฟล์"→upload SVG+"อัปโหลด".
- Bulk actions: lock/unlock/delete all got matching SVG (lock, unlock, trash).
- Update panel header: "⬆ อัปเดตAVSF" → upload SVG + label.

## 3. Responsive audit
- file-manager.php: `html,body{overflow-x:clip}` present; grid uses `minmax(0,1fr)`; `.avsf-fm-table-wrap` wraps table; ≤900px sidebar collapses to horizontal scroll strip, ≤620px table becomes card-grid (already existed, verified); buttons `flex-wrap:wrap` everywhere; inputs `min-width:0`. Search bar `flex:1 1 200px;min-width:0`.
- site-center.php: grids use `minmax(0,1fr)` (verified in media queries); sidebar is off-canvas drawer (`#siteSidebar` toggled by `#siteMenuToggle` hamburger, closes on Escape/outside-click/link-click — existed, verified). Menu-toggle icon `☰` replaced with SVG hamburger.
- data-transfer.php: `grid-template-columns:repeat(2,minmax(0,1fr))` → 1fr at ≤700px; `.avsf-transfer-actions{flex-wrap:wrap}`; added `overflow-x:clip`.
- No fixed widths exceeding viewport found.

## 4. SVG icons (replaced emoji-only action buttons)
All action buttons now have inline stroke SVG `viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"`:
- file-manager.php: folder (topbar h1, sidebar, mkdir), back-arrow, archive/zip (2×), upload (2×), plus (mkdir/newfile), lock (bulk+sheet), unlock (bulk), trash (bulk+sheet), vertical-dots (จัดการ), download (row+sheet+editor+binary), pencil/edit (sheet×2), copy (sheets), move (arrows), gear/chmod, save (editor), x/close (editor), magnifier (search). Sidebar Root 🏠→home SVG; folder tree 📁→folder SVG.
- site-center.php: theme toggle ◐→moon SVG (both sidebar and topbar instances); hamburger ☰→SVG; settings ⚙→gear SVG.
- data-transfer.php: ⇄→move-arrows (h1), ←→back-arrow, ↓→download (3 export buttons).
- File-type item icons (📁🗜️🖼️🧩📄) in the list `$icon` variable and 🔒 lock-accent in filenames/title kept as emoji accent per SPEC ("Emoji may stay as accent").

## 5. Fake buttons wired / removed
- `file-manager.php` line 522: `<a id="fmDownload" href="#">` — the ONLY `href="#"` in admin. Fixed: initial `href="javascript:void(0)"`; real download URL is set by `openSheet()` JS before the modal becomes visible (`?download=` or `?download_dir=`). No other `href="#"` remains in any of my 3 PHP files (grep verified).
- All buttons audited: every `<button>` has either `type=submit` in a real form, `onclick`/`addEventListener` handler, or `type=button` with JS listener. No disabled placeholders introduced.

## 6. Features/routes preserved
- No menu items, forms, endpoints, or routes removed. All existing actions (mkdir, newfile, upload, rename, copy, move, delete, chmod, lock, unlock, extract, save, update_preview/apply/cancel/rollback, bulk) intact.
- Version string not present in my files (Shard D owns metadata).

## 7. PHP lint result
```
/tmp/phpenv/bin/php -l file-manager.php  → No syntax errors detected
/tmp/phpenv/bin/php -l site-center.php    → No syntax errors detected
/tmp/phpenv/bin/php -l data-transfer.php  → No syntax errors detected
```

## 8. Remaining notes / cross-shard
- **i18n**: All UI strings hardcoded Thai; no translation layer. `siteLanguage` setting only affects HTML lang. Noted per SPEC §6 — no translation attempted.
- **Cross-shard dependency**: `admin-ui.css` (Shard A) is linked by site-center.php and data-transfer.php. Shard B added `data-admin-theme` on `<html>`/`<body>`; if Shard A's admin-ui.css uses `[data-admin-theme]` selectors, dark mode will apply. If Shard A still uses an older attribute name, light mode (default) will still render correctly because Shard B's inline overrides provide light `:root` vars.
- **File type item icons** (📁🗜️🖼️🧩📄) remain emoji as accent (not action buttons) — SPEC allows emoji as accent.
- CRLF→LF line-ending conversion applied to all 3 PHP files (was mixed CRLF; PHP unaffected, simplifies diff).
