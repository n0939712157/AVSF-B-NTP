# AVSF v3.2.4 — FULL TEST / PRIVATE PACKAGE

**Product:** AVSF  
**Publisher:** B-NTP  
**Version:** 3.2.4  
**Package:** Full application + private configuration + database snapshot

> **PRIVATE PACKAGE — contains live database credentials, an install nonce, and user/account records (password hashes). Do not publish this ZIP, place it in a public download directory, or send it to anyone else.**

## Included contents

- Application files from `AVSF-B-NTP-v3.2.4-FULL-REPLACE-CLEAN` (the CLEAN build is authoritative for overlapping files).
- `config.local.php` copied unchanged from the supplied READY package.
- `database/avsf-database-snapshot.sql` — SQL snapshot containing 19 tables. The snapshot contains records in `settings`, `users`, `activity`, `login_attempts`, and `404_log`; content tables currently have no inserted content rows.
- `avsf-installer/database-schema.sql` — clean schema for a fresh empty installation.
- `AVSF-FULL-TEST-PRIVATE-MANIFEST.json` and `SHA256SUMS` for integrity checking.

## IMPORTANT — before uploading to a test server

The included `config.local.php` is the original private configuration, not an automatically generated configuration for the destination host. Its database settings may still point to the source/live database.

For an isolated test clone:

1. Create a **new, empty test database** on the test server.
2. Import `database/avsf-database-snapshot.sql` into that test database using phpMyAdmin or the host's database tool.
3. Before opening the site, edit `config.local.php` at the document root so `host`, `port`, `name`, `user`, and `password` point to the **test database**. Keep the `install_nonce` value unchanged for this clone unless you intentionally restart the first-run setup flow.
4. Upload/extract the package contents directly into the website document root (`public_html/`, `htdocs/`, or the host's equivalent). Do not add an extra enclosing folder level.
5. Confirm Apache `mod_rewrite` and PHP `mysqli` are enabled. The `avsf-storage/` directories must be writable by PHP.
6. Open the test site and verify the public pages, `/admin-login/`, Backup Center, File Manager, and Data Transfer.

**Do not run the test clone with the included database credentials unchanged unless you deliberately intend it to use the source database.** If those credentials still point to the live database, actions from the test site may alter live settings, accounts, logs, or other records. The SQL snapshot is for the isolated test database; it does not automatically change the connection values in `config.local.php`.

## Testing the built-in server migration flow

The included `config.local.php` is for bringing up this test clone. The Server Reconnect endpoint is designed for a **fresh destination document root that does not yet contain `config.local.php`**.

Recommended migration test:

1. On the working test clone, open the AVSF Admin and create a verified **Full Migration Backup** that includes both files and database.
2. Prepare a separate destination test document root and a new, empty database. Upload the AVSF application files there, but do **not** copy `config.local.php` into that destination root.
3. Open `/server-reconnect.php` on the destination and follow its steps using the new destination database credentials and the Full Migration Backup created in step 1.
4. Verify login, settings, uploads, SEO configuration, File Manager, and Backup Center on the destination before switching any production DNS or traffic.

If a destination already has `config.local.php`, Server Reconnect intentionally refuses to run until that file is removed from the destination using the hosting file manager. Do not delete the config from the source/test clone as part of ordinary testing.

## Safety and operational notes

- Back up the destination files and database before restoring anything.
- Never import this SQL snapshot over a database that contains data you need to keep. Use a new empty test database.
- Keep `config.local.php` private and retain the root `.htaccess` rules that deny direct HTTP access to configuration and SQL/JSON files.
- Do not set `config.local.php` or `avsf-storage/` to `777`. Use the least permissions that allow PHP to write where required.
- After testing, rotate database credentials if this private ZIP has been shared or stored in an untrusted location.
- The package version remains AVSF 3.2.4; this is a private test/recovery bundle, not a new application release.

## Verification scope

This package is checked for ZIP integrity, file inventory, SHA-256 consistency, SQL table count, and PHP syntax where the PHP CLI is available. Those checks do **not** replace a live installation test on the target host; host-specific PHP extensions, permissions, rewrite rules, and database access still need to be verified there.

## Site Center dashboard separation patch

This build updates `avsf-admin/modules/site-center.php` so the Dashboard is an overview page only. Detailed 404/5xx reports, activity rows, redirect listings, the long PHP runtime panel, and duplicate tool shortcut cards are no longer appended to the Dashboard. The corresponding functions remain available from their dedicated sidebar modules or the existing "เครื่องมือเพิ่มเติม" modal actions.

When a sidebar module is selected, the Site Center shell remains in place and the selected module is loaded in the main content frame. The Dashboard and the selected module are mutually exclusive; returning to "ภาพรวมระบบ" restores the overview. The selected panel is preserved in the `panel` query parameter for reload and browser back/forward navigation.

**Patch scope:** Site Center presentation and navigation only. Database schema, private configuration, credentials, snapshot rows, user accounts, and other application modules are retained from the source private test package.


## Site Center panel visibility fix (follow-up)

This follow-up corrects a CSS specificity conflict in the responsive Site Center layout. A later `.avsf-site-main > .avsf-container { display:block }` rule was overriding the Dashboard panel's `hidden` state, so the Dashboard remained visible above the selected module even though the navigation script had hidden it. The updated build makes `[hidden]` authoritative for direct Site Center content panels and also renders the Dashboard hidden on the server whenever a non-Dashboard panel is requested.

After replacing files on the test server, clear any page cache / reverse-proxy cache and reload the Site Center page. Selecting File Manager, Backup Center, SEO, Users, Security, Redirects, or another module should show the selected module without the Dashboard cards above it. The shared Site Center top bar and navigation controls remain intentionally visible.


## Site Center panel route isolation (panel-routes follow-up)

Site Center uses one stable route contract:

- `/admin-login/site-center.php` — full Dashboard overview.
- `/admin-login/site-center.php?panel=backup-center` — Backup Center only (inside the shared Site Center shell).
- Other panels use the same `panel` query parameter, such as `members`, `seo`, `redirects`, `file-manager`, `security`, and `errorlog`.

The `panel` value is validated against an explicit allowlist. Unknown values fall back to the Dashboard. The pretty PATH_INFO form (`site-center.php/panel/...`) is not required and is intentionally not used, avoiding server-specific PATH_INFO/rewrite behavior.

The visibility rule is now loaded **after** `admin-ui.css`, so that the stylesheet cannot override the HTML `hidden` state of the Dashboard or the selected-panel frame. Dashboard and module panels remain mutually exclusive during clicks, direct query-string loads, and browser Back/Forward navigation. The shared Site Center top bar and sidebar remain visible by design.

This is a navigation/UI isolation patch only. It does not change module permissions, database tables, private configuration, credentials, or database snapshot contents.
