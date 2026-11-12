# AVSF B-NTP v3.2.4 — FULL SOURCE

## Source assembly
- Full active source assembled from the Hosting download `AVSUBFIN.zip`.
- Merged the v3.2.4 Host Source Navigation Repair files: `config.php`, `AVSF-METADATA.json`, `avsf-admin/modules/site-center.php`, and `avsf-admin/modules/security.php`.
- Restored all active Admin, Core, Frontend, Installer, Storage and Updates trees from the Hosting snapshot.
- Removed the recursive stale snapshot nested inside `avsf-frontend/` to avoid shipping duplicate older systems.
- Excluded site-specific `config.local.php`, live credentials, runtime data and uploaded media.

## Navigation / security repairs
- Restored direct per-link navigation handlers in Site Center.
- Kept same-origin validation and dashboard-card mapping.
- Added iframe timeout/error fallback to direct same-origin module URL.
- Ignored `about:blank` iframe load when returning to dashboard.
- Fixed Security Center output before bootstrap/session initialization.

## Deployment notes
- Fresh install: use `/db-setup.php`, then `/setup.php` in the same browser session.
- Existing site: merge/overwrite source files only; keep `config.local.php`, `.env`, uploads and database. Do not import SQL.
- Static QA does not replace a real Hosting/staging test.
