# AVSF B-NTP v3.2.4 — FULL SOURCE

**Product / Package:** AVSF  
**Developer / Publisher:** B-NTP  
**Version:** 3.2.4  
**Source base:** `AVSUBFIN.zip` downloaded from Hosting, merged with the v3.2.4 Host Source Navigation Repair.

## What this package contains

This is the complete active AVSF source tree, laid out directly for extraction into the Hosting Document Root. It includes:

- `avsf-admin/` — Admin login, dashboard, modules, File Manager, Backup/Restore, SEO, users and assets.
- `avsf-core/` — bootstrap, database auto-install, permissions, TOTP/2FA, cache, backup, security, import, i18n and update manager.
- `avsf-frontend/` — public front controller, controllers, templates, partials, pages, taxonomy templates and sitemap assets.
- `avsf-installer/` — database setup, Owner setup, Server Reconnect, schema and storage migration.
- `avsf-storage/` — guarded storage folders and uploads protection files; no user media or private backups are included.
- `avsf-updates/` — guarded update staging/history/rollback folders.
- Root `.htaccess`, `config.php`, public endpoint shims and Thai/English documentation.

The recursive stale source snapshot found under the active `avsf-frontend/` directory in the Hosting archive has been excluded. The active frontend code remains in `avsf-frontend/` at the Document Root level. This avoids shipping duplicate older Admin/Core/Installer systems inside the frontend directory.

## Security and site-specific data excluded

- `config.local.php` — contains Hosting-specific database credentials and install nonce. It is deliberately not included.
- `.env` / live environment secrets.
- Live database dumps and user records.
- Real uploaded media, cache, logs, private backups and quarantine contents.

A safe `config.local.example.php` template is included. It is not read automatically. For a fresh installation, use `/db-setup.php`, which creates the real `config.local.php` securely. The bootstrap also supports Hosting environment variables `AVSF_DB_HOST`, `AVSF_DB_USER`, `AVSF_DB_PASSWORD`, `AVSF_DB_NAME` and `AVSF_DB_PORT`; this source package does not include a dotenv loader.

## Fresh installation

1. Create an empty MySQL database and database user in Hosting.
2. Upload the **contents of this ZIP** directly into the intended Document Root (do not create an extra `htdocs/` subfolder).
3. Ensure PHP extensions `mysqli` and `zip` are enabled and the storage/update directories are writable by PHP.
4. Open `/db-setup.php` and enter the new database connection details.
5. In the same browser session, open `/setup.php` to create the first Owner account and complete required 2FA enrollment.
6. Sign in at `/admin-login/` and set the site URL, language, SEO and permalink options.
7. Test the homepage, admin modules, uploads, robots and sitemap endpoints.

## Update an existing AVSF site

1. Create and download a full Files + Database backup first.
2. Extract as a merge/overwrite into the existing Document Root.
3. Keep the site's current `config.local.php`, `.env` (if used), `avsf-storage/uploads`, private backups and database. Do not import the installer schema or any SQL dump as part of a file update.
4. Clear AVSF/Hosting/browser cache, then test Admin login, 2FA, Site Center navigation, each module, SEO, File Manager and the frontend.
5. Use a staging copy before production where possible.

This package has no live `config.local.php`; extracting it as a merge will not remove an existing config file. It does include the v3.2.4 `config.php` and root `.htaccess` from the merged source.

## v3.2.4 repair included

- Restored direct per-link Site Center navigation handlers (the model used in v3.2.1), including dashboard-card mapping.
- Added same-origin validation and iframe timeout/error/direct-open fallback.
- Ignored the `about:blank` load event when returning to the Site Center dashboard.
- Fixed Security Center output order so PHP config/bootstrap/session setup runs before HTML is emitted.
- Restored and retained `avsf-core/cache/cache_lib.php` in the complete Core source.

## Validation limits

The release was checked for ZIP integrity, duplicate archive paths, PHP syntax, the external QR JavaScript syntax, and SHA-256 manifest consistency. This environment does not have the PHP `mysqli` or `ZipArchive` extensions enabled, and it cannot run the Hosting's authenticated browser session. Therefore database setup, ZIP export/restore, real permissions, all menu clicks and mobile/desktop rendering still require staging/Hosting verification.
