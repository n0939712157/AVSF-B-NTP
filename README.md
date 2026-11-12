# AVSF-B-NTP

Full AVSF source package for a **new, empty website installation**. This document is the consolidated installation guide, feature overview, release history, and validation summary. It contains no agent execution logs, private site configuration, database snapshot, user records, or uploaded media.

## Release

- Product: AVSF
- Publisher: B-NTP
- Application version: 3.2.8
- Package: Complete source with first-run database and Owner setup
- Source includes the full application; a new site starts with a new database and no existing posts or media.

## Install a new website

1. Create a new, empty MySQL database and a dedicated database user in your hosting control panel. Do not reuse a production database.
2. Enable HTTPS and Apache `mod_rewrite`. Use PHP with `mysqli`; enable `zip` for archive/backup features.
3. Upload the repository source contents directly into the new domain's document root so that `index.php`, `config.php`, and `.htaccess` are at the root. Do not upload the `.git` folder. Do not add an extra enclosing directory level.
4. Ensure PHP can write to the required `avsf-storage/` directories using the least permissions your host supports. Do not set permissions to `777`.
5. In one browser session, open `https://your-domain/db-setup.php`, enter the new database connection details, and submit. AVSF tests the connection and creates the private `config.local.php`.
6. Continue in the same browser session at `https://your-domain/setup.php`. Create the first Owner account, save the generated password securely, and complete the required 2FA enrollment at first login.
7. Sign in at `/admin-login/`. Configure the site name, canonical URL, language, mail and external integrations, SEO, and permalink settings.
8. Test the home page, post pages, search, taxonomy pages, Admin, uploads, Sitemap, Robots, import/export, and backup/restore before opening the site to visitors.

The installer initializes an empty site. It does not import posts, user accounts, existing configuration, database records, or media from another website.

## Move an existing AVSF site

This source can also be used as an overlay update, but follow a separate update procedure:

1. Back up the existing website files and database, then test on a staging copy.
2. Upload the source contents into the existing document root and overwrite matching application files only.
3. Preserve the existing `config.local.php`, database, user-uploaded files in `avsf-storage/uploads/`, and private backups. Do not run first-install setup or import the clean schema over an existing database.
4. Clear AVSF and hosting/CDN caches as applicable, then verify the site and Admin.

## What's included

- `avsf-core/` — application bootstrap, database setup helpers, permissions, 2FA, security, cache, backup, import, i18n, analytics, and update deployment.
- `avsf-frontend/` and `avsf-frontend.css` — public routes, responsive page and taxonomy templates, shared layout, post cards, galleries, video player, and SEO/feed controllers.
- `avsf-admin/` — Admin dashboard and Site Center, users, security, SEO, redirects, file management, import/export, cache, backup, and error-log tools.
- `avsf-installer/` — first-run database and Owner setup, storage migration, server reconnect, schema, and clean-package support.
- `avsf-storage/` — protected upload and runtime-storage locations. No live uploads, backups, logs, or database snapshots are included.
- Root `index.php`, endpoint shims, `.htaccess`, `config.php`, and `config.local.example.php`.

## Feature overview

### Public site

- Responsive home, search, archive, category, studio, actor, tag, page, and single-post views.
- Post galleries, related/recommended cards, video player, comments, and metadata display.
- Responsive cover/poster sizing, image fallbacks, and touch-friendly gallery preview.
- RSS, Robots, XML Sitemap and sitemap index endpoints.
- Thai/English translation infrastructure and language selection, with translated frontend strings and dictionaries.

### Administration

- Permission-aware dashboard, Site Center navigation, and quick-access controls.
- User and role administration, login security, and time-based one-time-password (TOTP) 2FA.
- SEO templates, indexing controls, sitemap settings, URL inventory, redirects, and error-log tools.
- File Manager with search, editing, upload, download, and file operations.
- Import/export, backup/restore, cache management, security scanning, and site-health tools.
- Responsive Admin theme and controls; visual rank treatments do not grant permissions.

### Storage and installation safety

- First-run setup writes site-specific credentials to `config.local.php`, which must remain private.
- Apache rules protect configuration, internal source directories, runtime storage, and script-like files under uploads.
- A new installation uses an empty database. Existing users, permissions, posts, settings, and media are not bundled.

## Consolidated release history

This section combines the project's release notes and UI/feature patch history into this README. It records intended changes, not a guarantee of production-host verification.

- **v3.0:** Admin theme unification, responsive Admin layouts, consistent icons, SEO indexing controls, and stability refinements.
- **v3.1 / v3.1.1:** Thai/English translation infrastructure, language dictionaries and switcher, installer/frontend string conversion, and responsive Admin refinements.
- **v3.1.2:** File Manager and Admin usability improvements.
- **v3.2.0:** Clean-site package and fresh-install/safe-reset workflow.
- **v3.2.1:** Desktop floating controls and Admin navigation refinements.
- **v3.2.2:** Full-viewport Site Center layout.
- **v3.2.3:** Site Center navigation repair and restoration of the shared cache dependency.
- **v3.2.4:** Full-source assembly, Site Center panel/navigation fixes, responsive stability, upload handling, and protected storage updates.
- **v3.2.6:** Single-post layout spacing and alignment refinements.
- **v3.2.7:** Admin Quick Dock changed to a fixed overlay without reserving page-layout space.
- **v3.2.8:** Post breadcrumb/title/metadata alignment to the rendered player width, including responsive and optional-ad-column layouts.
- **Frontend card and gallery refinements:** consistent responsive card frames; natural poster sizing; category/taxonomy artwork; no-cover fallbacks; removal of unwanted tag rows; compact metadata; touch selection and gallery preview; and responsive detail spacing.
- **Admin refinements:** theme consistency, mobile navigation, SVG action icons, search/filter controls, SEO toggle feedback, compact taxonomy/page rows, scoped SEO previews, and permission-aware quick actions.

The source declares version 3.2.8 in release metadata and uses the 3.2.8 frontend asset version. Earlier source notes and manifests may refer to 3.2.4 because they describe the full-source assembly point.

## Consolidated validation and limitations

- Historical package reports record PHP syntax checks, source-level route/template checks, archive integrity checks, and manifest/hash checks for their respective builds.
- Those historical checks are not a current hosting certification and are not a substitute for testing this exact source on the destination server.
- A live MySQL installation, hosting-specific permissions/rewrite behavior, real mail delivery, browser/device interactions, media uploads, player playback, and production SEO/indexing must be tested on staging.
- Search snippet previews are estimates; search engines can rewrite titles and descriptions.

## Data excluded from this source package

- `config.local.php`, database passwords, API keys, and other site-specific secrets.
- Live SQL/database dumps, user/account records, password hashes, 2FA secrets, and activity history.
- User-uploaded media, cache, logs, backups, quarantine contents, and hosting data.
- Agent work logs, per-agent/shard reports, private test-package notes, and temporary machine paths.

Never publish a real `config.local.php` or a database dump. Keep a separate encrypted backup of site data and credentials. See [README-TH.md](README-TH.md) for the Thai guide.
