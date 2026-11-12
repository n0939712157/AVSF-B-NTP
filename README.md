# AVSF — B-NTP

**Product:** AVSF  
**Developer / Publisher:** B-NTP  
**Version:** 3.2.8  
**Package type:** Full Document Root Overlay Update

## Install on an existing AVSF site

1. Create a complete backup of the current website files and database.
2. Test this ZIP on a staging copy first.
3. Extract this ZIP into the existing AVSF Document Root and allow matching application files to be overwritten.
4. Do not delete the existing website files before extraction.
5. Keep the existing `config.local.php`, database and all existing user-uploaded files under `avsf-storage/uploads/`.
6. Clear AVSF HTML cache after the update. Purge hosting/CDN cache separately if enabled.
7. Test public URLs, Admin, File Manager, import/export, SEO, Sitemap, Robots, uploads and backup/restore.

## What's included

- Responsive Taxonomy and Page workspace: compact rows show names, slugs and associated post counts; expandable panels contain details, SEO metadata and indexing controls.
- Clicking a taxonomy count opens the associated post list, including draft/private status for administrators.
- Scoped SEO templates for posts, pages, categories, studios, actors, tags and archives, with context-specific variable buttons and rendered previews.
- Live snippet preview with multilingual grapheme counts and approximate pixel-width guidance. Search engines may rewrite snippets; no preview can guarantee that Google will never truncate text.
- URL Inventory labels identify the resource type and title alongside encoded URLs.
- Persistent permission-aware Admin Quick Dock on Admin pages and public pages while an administrator is signed in, with home, dashboard, new-post, contextual edit, theme, language, logout and AVSF HTML-cache controls.
- Owner identity uses a crowned-lion gold/orange treatment. Visual rank styling does not change actual RBAC permissions.
- Duplicate in-page language controls are removed from authenticated Admin tools; the global dock remains the shared language control.
- Upload storage receives an additional Apache rule denying direct execution of script-like files.

## Package scope and cautions

- This is an overlay update, not a clean installation.
- It does not contain the live database dump, production credentials or user-uploaded media.
- It preserves the existing database, configuration and uploaded media when installed using the steps above.
- Existing user roles and permissions are preserved; visual rank badges do not grant permissions.
- The floating cache action clears AVSF's own cached HTML files only. Browser, hosting, CDN and OPcache caches require their respective controls.
- Search result previews are estimates based on grapheme count and text width. Google can rewrite titles/descriptions and display different lengths by query/device.
- Production PageSpeed/Lighthouse, real Google indexing and live shared-host integration have not been verified in this build.


## v3.2.6 — Single Post Layout Refinement
- Desktop video frame inset by 7px on each side (14px total) for clearer edge spacing.
- Breadcrumb, H1/meta header and synopsis content align to the same responsive width as the poster/details block.
- Compact landscape-poster layout keeps the header and synopsis aligned to its full-bleed width.
- Updated frontend CSS cache-busting version to `3.2.6-postlayout1`.
- No database changes; preserve existing `config.local.php`, database and uploaded media.


## v3.2.7 — Fixed Overlay Admin Dock
- Removed layout-reserving right padding from the public document body and Admin main content.
- Kept the dock as a high-z-index `position: fixed` overlay on all authenticated pages.
- Preserved dock actions, role display, permission checks and responsive sizing.
- No database, routes, RBAC permissions, content or uploaded media changes.


## v3.2.8 — Post Heading Exact Player Width Alignment
- Breadcrumb, post title and published/view metadata are synchronized to the actual rendered video frame width.
- Accounts for optional beside-player advertisement columns rather than assuming the player always fills the outer container.
- Recalculates on responsive resize, orientation changes, player/container size changes and web-font readiness.
- The synopsis/details block and other post layout areas are not changed by this refinement.
- No database, routes, RBAC, user content or uploaded media changes.
# AVSF-B-NTP

AVSF-B-NTP is the full AVSF project bundle assembled as a document-root overlay package for a clean website build, with responsive frontend refinements, taxonomy/admin polish, import tools, SEO controls, and patch-level release documentation.

## Project overview

- Product: AVSF
- Publisher / build owner: B-NTP
- Version line: 3.2.4 final package with 3.2.8 overlay refinements
- Package type: Full source + update bundle + patch documentation
- Target use: Install on an existing AVSF site or use as a complete package reference

## Included system areas

- `avsf-core/` — core logic, security, backup, optimization, deployment, i18n, import helpers
- `avsf-frontend/` — frontend templates, Page templates, taxonomy templates, shared partials
- `avsf-admin/` — administrative modules and dashboard utilities
- `avsf-installer/` — installer and schema setup scripts
- `avsf-storage/` — upload storage area and safe storage structure
- Root PHP entry files — site entry points, register, view, RSS, sitemap, robots, forgot-password
- `config.php` and `config.local.example.php` — base site configuration and example settings

## Highlights from the build

- Responsive taxonomy and page workspace with compact detail rows and expanded panels
- SEO and index controls for posts, pages, categories, actors, tags, studios and archives
- URL inventory and resource labeling for admin review
- Persistent admin dock with permission-aware actions for authenticated editors/admins
- Clean, polished interface variations for categories, flickr-style cards, gallery layouts, and related content cards
- Multi-language and i18n conversion support with translation notes and frontend tag fixes
- Improved upload handling, Apache protection, and script-safe storage rules
- Backup, security, import and deployment modules for maintenance and update workflows

## Release and patch history included

This package contains multiple changelog and patch files covering the release timeline:

- `CHANGELOG-V3-TH.md`
- `CHANGELOG-V3.1-TH.md`
- `CHANGELOG-V3.1.2-TH.md`
- `CHANGELOG-V3.2.0-TH.md`
- `CHANGELOG-V3.2.1-TH.md`
- `CHANGELOG-V3.2.2-TH.md`
- `CHANGELOG-V3.2.3-TH.md`
- `CHANGELOG-V3.2.4-TH.md`
- `CHANGELOG-COMPACT-UNIFORM-CARD-PANELS-TH.md`
- `CHANGELOG-COVER-CATEGORY-RESTORE-TH.md`
- `CHANGELOG-CYBER-NEON-TAXONOMY-TH.md`
- `CHANGELOG-DETAIL-INFO-ICONS-COMPACT-ROWS-TH.md`
- `CHANGELOG-GALLERY-HOVER-TAP-PREVIEW-TH.md`
- `CHANGELOG-INSTANT-TOUCH-SELECTION-TH.md`
- `CHANGELOG-MEDIA-FALLBACK-TH.md`
- `CHANGELOG-NO-COVER-ART-ZOOM-TH.md`
- `CHANGELOG-ORIENTATION-AWARE-POSTER-AND-COMPACT-DETAIL-TH.md`
- `CHANGELOG-ORIGINAL-CARD-SIZE-POSTER-REFINE-TH.md`
- `CHANGELOG-PORTRAIT-FILL-LONGPRESS-TH.md`
- `CHANGELOG-POST-FALLBACK-CATEGORY-ART-TH.md`
- `CHANGELOG-POSTER-NATURAL-FIT-BORDERLESS-TH.md`
- `CHANGELOG-RECOMMENDED-NO-COVER-TH.md`
- `CHANGELOG-REFERENCE-ARTWORK-TH.md`
- `CHANGELOG-RESPONSIVE-ALL-DEVICES-TH.md`
- `CHANGELOG-RESPONSIVE-ART-NATURAL-COVERS-TH.md`
- `CHANGELOG-RESPONSIVE-DETAIL-ROW-SPACING-TH.md`
- `CHANGELOG-SELECTION-OUTLINE-INSTANT-PREVIEW-TH.md`
- `CHANGELOG-STANDARD-UNIFORM-POST-CARDS-TH.md`
- `CHANGELOG-UNIFORM-COVER-FRAMES-TH.md`

## Audit and validation reports

This bundle includes testing, review, and audit notes such as:

- `AUDIT-REPORT-TH.md`
- `shard-A-report.md`
- `shard-B-report.md`
- `shard-C-report.md`
- `shard-D-report.md`
- `shard-E-i18n-report.md`
- `README-TEST-PRIVATE-TH.md`
- `README-FULL-SOURCE-TH.md`
- `README-UPLOAD-FIX-TH.md`
- `README-UPLOAD-HTACCESS-HOTFIX-TH.md`
- `README-FRONTEND-TAGS-FIX-TH.txt`
- `CLEAN-SITE-FEATURE-TH.md`
- `I18N-CONVERSION-SPEC.md`
- `LIGHT-DARK-THEME-FIX-TH.txt`
- `RESTORE-CONFIG-ONLY-TH.txt`
- `UPDATE-GUIDE-TH.txt`
- `DEVELOPER.md`

## Manifests and metadata

- `AVSF-METADATA.json`
- `AVSF-FULL-TEST-PRIVATE-MANIFEST.json`
- `AVSF-V3.2.1-UPDATE-MANIFEST.json`
- `AVSF-V3.2.4-FULL-SOURCE-MANIFEST.json`
- `UPDATE-MANIFEST.json`
- `PATCH-MANIFEST.json`
- `SHA256SUMS`

## Installation notes

1. Back up the live website and database before applying this package.
2. Test the update or overlay in a staging copy first.
3. Upload the contents into the existing AVSF document root and overwrite matching files only.
4. Keep the current `config.local.php`, existing database, and uploaded media under `avsf-storage/uploads/`.
5. Clear AVSF HTML cache and any hosting/CDN cache after deployment.
6. Verify public pages, admin modules, uploads, SEO output, and content import flows.

## Important cautions

- This is an overlay update and not a clean reinstall.
- It does not include live production credentials or user-uploaded media.
- Preserve the current site configuration and database.
- Do not delete the upload folder or existing config files unless directed by an explicit migration plan.

## Repository status

This repository bundle was prepared for GitHub upload and contains the full project files, update manifests, audit reports, and changelog history from the start of the package through the latest release notes.

## Sources and references

Files in this bundle collectively document the site update flow, feature additions, admin tools, storage safety, upload fixes, i18n conversion, and release-level patch notes.
