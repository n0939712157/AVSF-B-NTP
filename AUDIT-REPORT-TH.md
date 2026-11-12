# AVSF v3.2.4 — Frontend & Asset Audit

Source: `avsf-backup-20261110-115449-a89fa28c.zip`  
Publisher: B-NTP

## Database and media link audit
- Posts: 35 (published).
- Posts with at least one local media reference: 14/35.
- Posts with no local media reference: 21/35.
- Unique local media paths referenced by posts: 150.
- Missing files among those 150 referenced paths: 0.
- Physical upload files in the backup: 193.
- Unreferenced files: 43 (2 branding assets and 41 uncoded import-media files).
- The 41 uncoded import-media files use codes that do not exist in this 35-post database snapshot; they were intentionally not attached by guesswork.
- Actor records: 34; actor photo fields populated: 0.
- Studio records: 20; studio logo fields populated: 0.
- Taxonomy foreign references in post actorIds/studioId/tagIds: all IDs resolve to existing taxonomy records in the backup.

## Navigation audit (static)
- The stored menu type set includes home, studio_list, actor_list, all, categories, tag_list and supported taxonomy/page/custom routes.
- `siteMenuUrl` maps built-in menu types to `siteUrl`; `sitePath` defines the routes for home, all, categories, studios, actors, tags, categories and pages.
- The frontend route map includes the corresponding page templates for home, all, categories, taxlist, studio, actor, tag, category, static page, post and search.
- Header/mobile menu event handlers exist, including toggle, outside click, Escape and link-close behavior.
- These are source-level checks only; live click-through testing against hosting was not performed.

## Changes in this release
- Taxonomy directory cards now use filled, dimensional actor/studio/tag SVG art instead of primitive outline placeholders and wire-grid floors.
- Scoped card styling uses glass surfaces, multi-layer ambient light, gradient edges and responsive 2/3/4-column breakpoints.
- Single-post information panel and category/studio/actor/tag links use matching premium surfaces.
- Home and related/recommended post cards receive an explicit no-tags class; the # tag row is suppressed.
- Footer supplement script is moved before the closing body/html tags.

## Validation status
- All PHP files in the delivered package passed `php -l`.
- ZIP archive integrity test passed.
- SHA-256 file manifest matches every shipped file.
- Static menu route/template presence checks passed.
- Live MySQL, real browser interactions, player playback, upload workflows and all hosting-specific features have not been end-to-end tested from this offline backup.


## Media fallback & cover overlay refinement
- Removed `.avsf-play-icon` markup from post-list cards. The play icon remains only inside the no-image placeholder layer; a successful cover/poster image fully covers that layer, so no play symbol overlays a real image.
- Post-card missing-image fallback palettes are selected from the post category name (subThai, HD, new, popular, or general).
- Actor/studio/tag directory cards now render their correct type-specific fallback art underneath the image slot. Successful image loads hide the fallback; failed image loads remove the broken image and reveal the matching fallback.
- Category directory cards receive a category-family palette, with optional variations for subThai, HD, new and popular categories.
- Single-post player markup and playback behavior are unchanged.
- Responsive two-column mobile taxonomy grid and existing tablet/desktop breakpoints are preserved.

## Additional validation
- PHP syntax lint: all shipped PHP files pass.
- ZIP integrity: pass.
- UPDATE-MANIFEST SHA-256: all listed package files match.
- Source assertions: post-card play overlay markup absent; category fallback classes present; taxonomy fallback artwork always rendered and image error handler present.
- Hosting/browser end-to-end checks are still required for real image fetches and player playback.
