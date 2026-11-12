# Shard E — i18n TH→EN Conversion Report

Scope: everything EXCEPT `avsf-admin/index.php` and `avsf-admin/modules/*.php`.
Helpers used: `__()`, `_e()`, `avsf_js()`, `avsf_lang_attr()`, `avsf_lang_switcher_html()` (loaded via bootstrap).

## Changed files (37 + 1 dictionary)

### Installer (4)
- `avsf-installer/db-setup.php` — added direct i18n bootstrap (runs before config.local.php exists; cannot load config.php), all UI strings converted, `<html lang>` dynamic.
- `avsf-installer/setup.php` — full page converted (PHP $error/die + HTML + JS via `avsf_js(__())`), `<html lang>` dynamic.
- `avsf-installer/server-reconnect.php` — added direct i18n bootstrap, all user-visible strings converted (including caught RuntimeException messages shown as $error), `<html lang>` dynamic.
- `avsf-installer/migrate-storage.php` — converted (loads config.php).

### Frontend (28)
- `avsf-frontend/partials/head.php` — `<html lang>` → `avsf_lang_attr()` (replaced static setting-based expression).
- `avsf-frontend/partials/header.php` — search placeholders/buttons, menu labels wrapped `__($menuItem['label'])`, aria-labels; **language switcher inserted here** (shared partial, appears site-wide) inside `.avsf-site-header-actions` after `<nav>`.
- `avsf-frontend/partials/footer.php` — lightbox aria-labels, recommendation JS strings (via `avsf_js`), bottom-nav menu labels wrapped.
- `avsf-frontend/partials/pagination.php` — converted.
- `avsf-frontend/pages/` — page-not-found, page-search, page-static, page-all, page-categories, page-taxlist, page-home (SEO setting defaults wrapped; menu/nav label defaults left as-is — translated at echo via `__($menuItem['label'])`).
- `avsf-frontend/loop-templates/` — content-related, content-gallery, content-comments, content-action-card, content-share-buttons, single-video-player, single-video. (content-post-card.php: Thai only in code comment — no change.)
- `avsf-frontend/taxonomy-templates/` — taxonomy-category, taxonomy-studio, taxonomy-actor, taxonomy-tag (SEO defaults + UI strings; breadcrumb JSON-LD names wrapped).
- `avsf-frontend/controllers/register.php`, `controllers/forgot-password.php` — standalone full pages converted, `<html lang>` dynamic, language switcher added (these pages do not include header.php, so per spec fallback inserted per-page). Email subject + body in forgot-password converted with %s placeholders.
- `avsf-frontend/controllers/rss.php` — siteDesc default + `<language>` made dynamic. (view.php, robots.php: Thai only in comments — no change. sitemap.php/sitemap_index.php/page-view.php: 0 Thai.)
- `avsf-frontend/runtime/template-loader.php` — user-visible fallback error string converted.
- `avsf-frontend/index.php` — 410/maintenance/die messages, inline pagination, error fallback, comment default name, CSRF die, JS alert (via `avsf_js`). Default menu/nav label arrays left as Thai seed (translated at output).

### Core (5 — user-visible non-exception strings only)
- `avsf-core/functions.php` — upload/video-parse/Remote-API error returns, SEO default templates, `'ไม่ระบุ'` default names.
- `avsf-core/security/security_lib.php` — CSRF/rate-limit `die()` messages + upload error returns.
- `avsf-core/database/auto-install.php` — `die()` messages converted; **seed-data setting defaults left as Thai** (admin-configurable content, not UI chrome; translated at read time via wrapped `setting()` defaults).
- `avsf-core/api/vdohide-api.php` — API error returns.
- `avsf-core/import/avsf-image-matcher.php` — upload error returns (with %s placeholders).

### Dictionary
- `avsf-core/lang/en/core-frontend.php` — **320 keys**, all used keys with English translations. (Duplicates of `_core.php` keys like `บันทึก`/`ค้นหา` included — harmless, later value wins.)

## Lint result
`node phplint.js <37 changed files> avsf-core/lang/en/core-frontend.php` → **checked=37 failed=0**.

## Language switcher placement
- Site-wide: `avsf-frontend/partials/header.php` (shared partial, included on all templated pages) — inside `.avsf-site-header-actions`, floated after primary nav.
- Standalone auth pages (no shared header): `controllers/register.php` + `controllers/forgot-password.php` — top of card.
- Installer pages: switcher intentionally NOT added (one-time wizard; `?lang=` GET would break POST form flow). `<html lang>` is dynamic on all installer pages.

## Skipped strings (with reasons)
1. **`throw new RuntimeException(...)` messages** in core libraries (zip_safety, malware_scanner, deployment_manager, backup_manager, cache_lib, permissions, totp) — internal exception messages, explicitly skipped per spec. (~300 occurrences)
2. **`error_log()` / `logActivity()` messages** — left Thai (e.g. setup.php `logActivity('ติดตั้งครั้งแรก — สร้าง Owner: ...')`, index.php comment log).
3. **Code comments / docblocks** — all Thai comments left as-is (security_lib, malware_scanner, cache_lib, totp, vdohide, content-post-card, view.php, robots.php, post_import_parser).
4. **Technical regex / mapping arrays** — `[ก-๙]` slug regex (index.php, post_import_parser), duration regex, CSV column-name mapping arrays in post_import_parser.php (data mapping, not UI).
5. **Seed-data setting defaults** in auto-install.php (`siteName='เว็บไซต์ใหม่'`, navLabels JSON, `แนะนำ`, `โฆษณา`, `ค้นหา...`, etc.) — inserted into DB on first install; admin-configurable content. Translated at read time via wrapped `setting('key', __('thai default'))` in frontend templates.
6. **Thai date formatter** `fmtThaiDate()` month-abbrev array + `' เวลา ... น.'` in functions.php — locale-specific date logic; translating would require behavior change beyond string replacement.
7. **`'inLanguage'=>'th'`** in JSON-LD schema (VideoObject/CollectionPage) — technical metadata attribute, not user-visible text.
8. **Root thin wrappers** (forgot-password.php, register.php, page-view.php, view.php, rss.php, sitemap.php, sitemap_index.php, robots.php, migrate-storage.php at root) — 0 user-visible strings; just `require` the real controller. Unchanged.
9. **`avsf-admin/**`** — out of scope (owned by another worker).
10. **`config.php`, root `index.php`, `i18n.php`, `bootstrap.php`, `avsf-core/lang/th/`** — out of scope / do-not-touch.
