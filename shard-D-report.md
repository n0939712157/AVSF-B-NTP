# Shard D Report — AVSF V.3 Upgrade
Scope: everything NOT owned by A/B/C (avsf-core/**, avsf-frontend/**, avsf-installer/**, avsf-storage/**, avsf-updates/**, root *.php, AVSF-METADATA.json, FULL-REPLACE-CLEAN-MANIFEST.json, README.md, DEVELOPER.md, README-FULL-REPLACE-CLEAN-TH.txt, LIGHT-DARK-THEME-FIX-TH.txt). avsf-admin/ audited read-only, NOT edited.

## 1. Files changed (8 files)
| File | Change |
|---|---|
| `config.php` | `AVSF_VERSION` 2.0.8 → 3.0.0 |
| `AVSF-METADATA.json` | version → 3.0.0; `release` field updated to "V.3 Premium UX Refresh — unified theme, responsive admin, SVG icons, SEO toggle fix, stability audit" (build_date already 2026-11-07) |
| `FULL-REPLACE-CLEAN-MANIFEST.json` | version → 3.0.0; source_release → `AVSF-Server-Reconnect-B-NTP-v3.0.0.zip`; added `_hash_note` documenting that per-file SHA-256 hashes were NOT recomputed. **Hashes left intact — must regenerate before publishing.** |
| `DEVELOPER.md` | Version 2.0.2 (stale) → 3.0.0 |
| `README.md` | header Version → 3.0.0, Build date → 2026-11-07; added `### 3.0.0 — 2026-11-07 (V.3 Premium UX Refresh)` section at top of version history |
| `README-FULL-REPLACE-CLEAN-TH.txt` | all v2.0.8 refs → v3.0.0; added V.3 note + hash-regen warning |
| `avsf-frontend/controllers/register.php` | added `@media (prefers-color-scheme: light)` block — white cards (#fff), light bg #f3f6fb, dark text #19304f, muted #667790, border #dfe6f0, accent #267cf4, soft shadow. Matches premium light look. Dark default preserved. |
| `avsf-frontend/controllers/forgot-password.php` | same light-variant media query added. |

### Version bump locations (comprehensive)
- All current product-version `2.0.8` strings eliminated from owned files (verified by grep — zero remaining).
- Remaining `2.0.x` strings are HISTORICAL and intentionally left: README.md version-history entries (2.0.2, 2.0.3 sections), `LIGHT-DARK-THEME-FIX-TH.txt` title (historical fix doc for v2.0.3), README.md update-manifest example block (`"version":"2.0.2"` — illustrative example).
- No version strings found in avsf-core, avsf-installer, avsf-frontend (other than the two auth pages edited).

## 2. Cross-cutting audit findings (read-only)

### (a) Duplicate / conflicting code
- **`openLightbox()` defined twice**: `avsf-admin/index.php:4918` (1 arg) and `avsf-frontend/partials/footer.php:168` (3 args). Different runtime contexts (admin page vs frontend page — never co-loaded). No actual conflict. Note only.
- **`.avsf-admin-sidebar` CSS selector twice** in `avsf-admin/assets/css/admin-ui.css` (line 24 `scrollbar-width:thin`; line 97 `min-width:0`). Additive, different properties — no conflict.
- **No duplicate static HTML `id=` attributes** found (only dynamic PHP-generated ids `id="'.$formId.'"` / `id="'.esc($key).'"` in avsf-admin/index.php — unique at runtime).
- **No conflicting JS handlers** on same element/ID found (repeated `getElementById` are normal multi-references).
- **localStorage keys** (no conflicts): `avsf-admin-theme` (3×, correct unified key), `avsf-posts-view` (2×, posts-list view pref — separate feature), `AVSF_save_*` / `AVSF_like_*` (frontend like/save buttons), plus one leftover `avsf-site-center-theme-v2` (see (d)).

### (b) i18n audit — confirmed exactly as spec predicted
- **No translation layer exists**: zero occurrences of `__()`, `_e()`, `esc_html__`, `gettext`, `load_*_textdomain`, `TextDomain`, or any i18n helper. Confirmed by grep.
- **All UI strings hardcoded** (mostly Thai; some English admin labels e.g. "System Error Log", "ACCOUNT SECURITY").
- **`siteLanguage` setting (th/en)** exists at `avsf-admin/index.php:2037,2061`, explicitly documented at `avsf-admin/index.php:3773`: *"กำหนด lang attribute ใน HTML เพื่อช่วยเบราว์เซอร์และเสิร์ชเอนจิน ไม่ได้แปลข้อความบนหน้าเว็บ"* (sets HTML lang only, does NOT translate page text).
- Frontend respects it: `avsf-frontend/partials/head.php:2` sets `<html lang="<?php echo setting('siteLanguage','th')==='en'?'en':'th'; ?>">`.
- **Minor inconsistency (not a bug)**: all 12+ admin pages hardcode `<html lang="th">` — the `siteLanguage` setting does NOT affect admin pages' lang attribute. Only frontend. Not broken, just limited scope.
- No broken language toggle found.

### (c) Fake buttons / href="#" / dead onclick (scanned ALL files incl. avsf-admin)
- **`avsf-admin/modules/file-manager.php:522`**: `<a id="fmDownload" href="#">` — **NOT dead**: `openSheet()` at line 546 dynamically sets `.href` (`?download=` / `?download_dir=`) before the sheet is shown. Wired at runtime. (Shard B file — not edited.)
- **`avsf-admin/modules/site-center.php:682`**: `<button type="submit" onclick="return true">` — **no-op/dead onclick** (submit button inside a form; `return true` does nothing). Harmless but pointless. (Shard B file — not edited.)
- All other `onclick=` handlers are real: `confirm()` dialogs, `openModal`/`closeModal`, `fmBulk`, select-all/none checkboxes, `location.reload()`, password-visibility toggle. No empty `onclick=""`, no `onclick="void"`, no `onclick="#"`.
- No other `href="#"` found (only the fmDownload one above). No `href="javascript:void(0)"` without a handler found.

### (d) Leftover theme attribute/key inconsistencies — A/B/C did NOT fully unify (all in avsf-admin, read-only, reported for them to fix)
- **`avsf-site-center-theme-v2` localStorage key** still used: `avsf-admin/modules/site-center.php:1317` (Shard B), `avsf-admin/modules/users.php:672` (Shard C). Should read `avsf-admin-theme`.
- **`data-users-theme` attribute** still used throughout `avsf-admin/modules/users.php` (~20 occurrences, lines 538–676, Shard C). Should be `data-admin-theme`.
- **`data-site-theme` attribute** in `avsf-admin/modules/site-center.php` (lines 649, 719, 1320, Shard B).
- **`data-site-center-theme` attribute** still in: `avsf-admin/modules/site-center.php` (lines 1174, 1175, 1209, 1314, Shard B), `avsf-admin/modules/security.php:107` (Shard C), `avsf-admin/modules/error-log-center.php:76` (Shard C), `avsf-admin/modules/backup-center.php:121` (Shard C).
- Correct unified contract (`data-admin-theme` + `avsf-admin-theme`) IS present in: `avsf-admin/index.php`, `avsf-admin/assets/css/admin-ui.css`, `avsf-admin/assets/css/file-manager-center.css`, `avsf-admin/modules/data-transfer.php`.
- **Impact**: users.php / site-center.php / security.php / error-log-center.php / backup-center.php may not follow the global light/dark toggle and may flash the wrong theme on load. A/B/C should complete the replacement.

## 3. Stability (avsf-core) — bugs found & fixed
- **None fixed** — no obvious bugs found; conservative approach taken (report, don't rewrite).
- Baseline `php -l` on ALL owned PHP files (avsf-core, avsf-frontend, avsf-installer, avsf-storage, avsf-updates, root) → **zero syntax errors**.
- Helper functions used by the two auth pages verified defined: `e()` (security_lib.php:22), `esc()` (functions.php:73), `avsfClass()` (bootstrap.php:256), `setting()` (bootstrap.php:75), `avsfCanonicalBaseUrl()` (bootstrap.php:137), `logActivity()` (bootstrap.php:217), `sec_csrf_verify`/`sec_rate_limit` (security_lib).
- Core DB helper functions (`getStudio`, `getActor`, `getTag`, `publishedPosts`, etc.) properly declare `global $conn`.
- Heuristic scan for missing `global $conn` in core functions flagged `advancedDraftParseText()` in `avsf-core/import/post_import_parser.php` — **verified false positive**: `$conn` is only passed as a parameter to helper functions `advancedDraftFindOrCreateTerm($conn,...)` / `advancedDraftSaveRecord($conn,...)`; the parse function itself never touches `$conn`.
- No `extract()`, no undefined-variable patterns, no missing `isset` on superglobals found in core.
- Did NOT touch DB schema, config.local.php, .htaccess rules, avsf-storage/uploads, or any route.

## 4. php -l result (edited files)
```
No syntax errors detected in config.php
No syntax errors detected in avsf-frontend/controllers/register.php
No syntax errors detected in avsf-frontend/controllers/forgot-password.php
```
JSON validation: `AVSF-METADATA.json` and `FULL-REPLACE-CLEAN-MANIFEST.json` both parse valid.

## 5. Notes / follow-ups for other shards
- **A/B/C must fix leftover theme attrs** listed in §2(d) (users.php, site-center.php, security.php, error-log-center.php, backup-center.php + `avsf-site-center-theme-v2` key).
- **Regenerate SHA-256 hashes** in `FULL-REPLACE-CLEAN-MANIFEST.json` before publishing (hashes are stale after the in-place version bump).
- `site-center.php:682` no-op `onclick="return true"` can be removed (cosmetic).
