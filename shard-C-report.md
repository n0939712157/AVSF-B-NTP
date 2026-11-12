# Shard C Report — AVSF V.3 Upgrade (avsf-admin/modules/*)

**Files owned & edited (all 8, saved in place):**
`seo-center.php`, `redirects.php`, `security.php`, `users.php`, `cache.php`, `backup-center.php`, `error-log-center.php`, `import.php`

Working copy: `/home/user/Dola/chats/38418554846669841/work/avsf-v2/avsf-admin/modules/`

---

## 1. SEO Index/Noindex Toggle Bug — FIXED (highest priority)

**Symptom reported:** "กดแล้วเปลี่ยนแต่ชื่อเมนูในหน้าทำงานไม่เปลี่ยน" — clicking toggles the status badge but the button label / row label does not flip to match.

**Root cause found:** The per-row toggle was a plain POST form with no progressive enhancement; after save it forced `$view='indexing'` and did a full reload, but there was no guarantee both the `<span class="avsf-pill">` badge AND the submit-button label reflected the freshly stored state on the same render path, and there was no AJAX path. (Save itself did persist via `saveSeoIndexOverride()`.)

**Fix applied (`seo-center.php`):**
1. Added an AJAX/JSON branch in the `save_index_override` POST handler: when `$_POST['ajax']==='1'`, returns `{ok, indexable, notice}` JSON and exits (CSRF still verified, DB still written).
2. Toggle form now carries hidden `ajax=1` and `view` fields, class `avsf-seo-toggle`; badge span has `data-seo-badge`; button has `data-seo-toggle-btn`.
3. Added JS handler: on submit, `preventDefault()` → `fetch()` POST → on `ok:true` updates **BOTH** the status badge (text `Index`/`Noindex` + `avsf-index`/`avsf-noindex` class) **AND** the button label (flips between `เปิด Index` / `ปิด Index` with matching SVG icon), and flips the hidden `indexable` value for the next toggle. On any failure, falls back to a normal full-page form submit (so state is always correct).
4. Full-reload path also preserved (no-JS works) and now preserves the current view.

**Verified:** toggle writes to `seo_index_overrides` via existing `saveSeoIndexOverride()`; rendered label is derived from the same freshly-queried `indexable` value as the badge (both in `$indexRows` loop), so they can never diverge.

---

## 2. Theme Unification — DONE on all 8

- Anti-flash `<script>` (spec-exact) inserted as **first child of `<head>`, before any CSS**, in every standalone module page.
- `<body data-admin-theme="light">` set statically; script corrects on `documentElement` + `body`.
- Per-module attributes renamed: `data-users-theme` (users.php), `data-site-center-theme` (security/backup/error-log), `body[data-theme]` (seo-center) → all now `[data-admin-theme="dark"]`. Reads same localStorage key `avsf-admin-theme` (default `light`). No new keys introduced.
- Dark-only hardcoded pages (`redirects.php`, `cache.php`, `import.php`) converted to CSS-variable theme: light mode = white cards (`--card:#fff`), `--bg:#f3f6fb`, dark override per spec. `security.php` msg/check-list/code colors lightened for light mode.
- `grep` confirms **zero** leftover `data-users-theme` / `data-site-center-theme` / `data-seo-theme` / `body[data-theme` in any of the 8 files.

---

## 3. Responsive — DONE

- `html,body{max-width:100%;overflow-x:clip}` added to all 8 (seo-center already had it).
- Every `<table>` wrapped in `<div style="overflow-x:auto">` (security.php all 4 tables wrapped; redirects/cache/backup/error-log/import already wrapped or now wrapped; users list already wrapped).
- Grids use `minmax(0,1fr)` / `auto-fit`; `.avsf-row` flex containers `flex-wrap:wrap`; inputs `min-width:0;max-width:100%`; buttons wrap. Verified no fixed width exceeding viewport in these modules.

---

## 4. SVG Icons + Search Filters — DONE

- Consistent inline stroke SVG icons (`viewBox 0 0 24 24`, `stroke="currentColor"`, `stroke-width 1.8–2`) added to all action buttons across all 8 files: search/magnifier, save, trash, download, upload, gear, plus, link/redirect, alert, folder, clock, box/archive, restore, back-arrow, zap/cache, database/backup, list, help, home, refresh, check, power. Button labels kept small (11–13px). Emoji retained as accent only where it was inside non-button text.
- **Real search/filter boxes added (with magnifier SVG, JS `oninput` filtering table rows) to list pages that lacked one:**
  - `users.php` — users list filter (`#userFilter`, rows `data-user-row`).
  - `redirects.php` — redirects list filter + 404-log list filter.
  - `backup-center.php` — backup list filter (`#backupFilter`).
  - `error-log-center.php` — 5xx table filter + 404 table filter.
  - `seo-center.php` already had a working server-side filter (kept, added SVG to its button).

---

## 5. Fake Buttons Audit — CLEAN

- `grep` for `href="#"` across all 8: **none**. All `type="button"` buttons have real `onclick` handlers (clipboard copy, refresh, reveal-restore-form, menu toggle, theme toggle). No placeholders left. No disabled-placeholder list needed.

---

## 6. Kept intact

All routes, menu items, forms, endpoints, and features preserved. No DB/config/.htaccess changes. No other-shard files touched. Version bump left to Shard D (no version string appeared inside these module files that I own; `import.php`/others reference core version via functions).

---

## 7. PHP Lint — ALL PASS

`/tmp/phpenv/bin/php -l` on every edited file → **"No syntax errors detected"** for:
seo-center.php, redirects.php, security.php, users.php, cache.php, backup-center.php, error-log-center.php, import.php.

---

## 8. Notes / remaining (honest)

- **i18n:** No translation layer exists; all UI strings are hardcoded Thai (spec §6 — audit only, did not invent translations). `siteLanguage` setting only affects `lang` attribute. Noted for cross-shard.
- `security.php`: one table may be double-wrapped in `overflow-x:auto` (harmless, no visual/syntax issue).
- SEO toggle AJAX requires JS; no-JS falls back to full reload (state always correct). If another shard changes the `save_index_override` handler signature, re-check the JSON branch.
- Cross-shard note: `admin-ui.css` (Shard A) and other modules use the same `avsf-admin-theme` key — unified now; theme toggle button in seo-center.php writes that key.
