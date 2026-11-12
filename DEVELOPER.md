# AVSF B-NTP — Developer Notes (v3.2.4 Full Source)

- Product/package: AVSF
- Developer/publisher: B-NTP
- Release: 3.2.4 Full Source
- Source basis: Hosting snapshot `AVSUBFIN.zip` plus v3.2.4 Host Source Navigation Repair.
- Site-specific `config.local.php`, `.env` secrets, live database dumps and uploaded media are excluded by design.
- Keep the canonical active trees at Document Root: `avsf-admin/`, `avsf-core/`, `avsf-frontend/`, `avsf-installer/`, `avsf-storage/`, `avsf-updates/`.
- Do not package a recursive second copy of Admin/Core/Installer inside `avsf-frontend/`.

## v3.2.4 fixes

- `avsf-admin/modules/site-center.php`: direct per-link click handlers restored; same-origin panel validation, iframe timeout/error handling, direct-page fallback, dashboard cards, and `about:blank` guard retained.
- `avsf-admin/modules/security.php`: remove leading output before config/bootstrap/session startup.
- `avsf-core/cache/cache_lib.php`: required shared cache dependency; must be present in every full-source release.
- `config.php`: AVSF_VERSION is 3.2.4 and paths derive from `__DIR__`.

## Data protection

- `config.local.php` must never be committed/distributed. Use `/db-setup.php` for fresh installs.
- Never include real `.env`, SQL data dumps, uploaded media, private backups, logs, cache or quarantine contents in a public source ZIP.
- Do not import `database-schema.sql` into an existing site as an update step.
