# Shard A Report — AVSF V.3 Upgrade

**Files owned (edited in place):**
- `avsf-admin/index.php` (6271 lines → lint-clean)
- `avsf-admin/assets/css/admin-ui.css`

**PHP lint:** `/tmp/phpenv/bin/php -l avsf-admin/index.php` → `No syntax errors detected`. (admin-ui.css is pure CSS.)

---

## 1. Post-editor light-mode black box — FIXED

Root causes found & fixed:
- `:root` in inline `<style>` defaulted to **dark** vars (`--bg:#090b10;--card:#11151f…`), conflicting with admin-ui.css light default. Flipped `:root` to **light** (SPEC contract) and added `[data-admin-theme="dark"],.avsf-theme-dark{…dark vars…}`.
- The `.avsf-admin-dark` class selector (19 occurrences in inline CSS) was **dead** — the theme toggle never added that class, so all dark-mode override rules were inert and several elements' *default* rule was hardcoded dark. Converted all 19 to `[data-admin-theme="dark"]`.
- Hardcoded dark surfaces with NO light override (all converted to `var(--card)`/`var(--card2)`):
  - `.avsf-posts-stat-card` (`linear-gradient(145deg,#17131e,#11121b)`), strong color → `var(--accent)`
  - `.avsf-posts-bulk-toolbar` (`#171722,#11121a`)
  - `.avsf-posts-bulk-controls select` (`#0c0c14`)
  - `.avsf-posts-view-switch` (`#101018`)
  - `.avsf-editor-gallery-item` (`#080810`)
  - `.avsf-editor-cover-preview img` (`#080810`)
  - `.avsf-post-summary-card` default gradient (light override already existed in admin-ui.css; default now var-based)
  - Keyword-notes fullscreen: `.avsf-post-keyword-notes[open]` (`#090912`), its `>summary` (`rgba(18,16,28,.96)`), `.avsf-keyword-note-preview` (`rgba(5,7,12,.48)` + white border), `.avsf-keyword-notes-grid label` (`color:#ddd`), `.avsf-keyword-notes-read h3` (`color:#eee`) — all to `var(--bg)`/`var(--card)`/`var(--card2)`/`var(--text)`/`var(--border)`. Added explicit `body[data-admin-theme="light"]` chip-contrast overrides (amber/purple chips, `#ffe0a3`→`#8a5a00` etc.).
  - `.avsf-post-admin-title-link:hover` / `.avsf-post-taxonomy-link:hover` (`color:#fff` — invisible on white) → `var(--accent)`.
  - `.avsf-admin-mobile-bar` in `@media≤900px` (`rgba(10,10,15,.96)`) → `var(--card)`.
- `body[data-admin-theme="light"]{--bg:…}` var redefinition removed (it would override html-driven vars and cause flash); only `color-scheme:light` kept. Cosmetic `.avsf-xxx` light overrides retained.
- **admin-ui.css:** var definitions re-keyed from `body[data-admin-theme=…]` to `:root` (light) + `[data-admin-theme="dark"],.avsf-theme-dark` (dark); 98 cosmetic `body[data-admin-theme=…]` selectors converted to `[data-admin-theme=…]` so the html attribute (set by anti-flash script) drives them before body paint. Existing `!important` light rules for editor cards/textarea/contenteditable/inputs/taxonomy verified intact and covering all editor surfaces.

Video frames (`.avsf-video-wrap`, `.avsf-video-preview-frame`) intentionally kept `background:#000` (correct letterbox UX, not an admin surface).

## 2. Theme unification — FIXED

- Anti-flash inline `<script>` added to **all 3 standalone pages** (2FA, login, main admin) right after `<head>`, exactly per SPEC (reads `avsf-admin-theme`, default `light`, sets `data-admin-theme` + `avsf-theme-<t>` class on `documentElement`). Uses double-quoted JS strings so it is safe inside single-quoted PHP echo blocks.
- `<body data-admin-theme="light">` static attribute retained (SPEC).
- Theme toggle JS (bottom of body) now updates **both** `document.body` and `documentElement` (attribute + class) and writes `localStorage['avsf-admin-theme']`. Initial-apply path also syncs documentElement.
- Both toggle buttons (mobile bar + sidebar nav) now show sun/moon inline SVG pair with `.avsf-theme-icon-sun`/`.avsf-theme-icon-moon` visibility driven by `[data-admin-theme]`.
- No other theme attribute/key remains in these files (`data-site-center-theme` etc. not present; `avsf-admin-dark` class eliminated).

## 3. Responsive mobile — FIXED

- Added global `html,body{max-width:100%;overflow-x:clip}` + `min-width:0` on `.avsf-admin-layout,.avsf-admin-main,.avsf-card,.avsf-form-group,.avsf-rich-editor-toolbar-row,.avsf-rich-editor-tool-group,.avsf-posts-bulk-controls,.avsf-keyword-notes-grid section` + `img,svg{max-width:100%}`.
- Sidebar off-canvas drawer already existed at ≤900px (`.avsf-admin-sidebar` fixed, `.avsf-menu-open` toggles, backdrop, Escape key, closes on nav click) — verified intact; hamburger `☰` text replaced with inline stroke SVG menu icon.
- `.avsf-posts-page-actions` already `flex-wrap:wrap`; at ≤420px buttons go full-width flex:1. Editor toolbar `flex-wrap:wrap` (rows/groups). Settings forms: `.avsf-form-row,.avsf-form-row-3` → 1 column at ≤900px. Keyword-notes grid → 1 column at ≤700px. Posts bulk toolbar → column at ≤700px. All tables wrapped in `overflow-x:auto` scroll containers (`.avsf-posts-table-scroll`, `.avsf-taxonomy-table-scroll`, inline `style="overflow-x:auto"`); posts table `min-width:1320px` scrolls internally.
- Login page: inputs `font-size:16px` at ≤760px (prevents iOS zoom); brand panel collapses to single column.

## 4. SVG icons — DONE

Replaced emoji-only/text-only action buttons with consistent inline stroke SVG (`viewBox 0 0 24, fill:none, stroke:currentColor, stroke-width:1.8, round caps`):
- Theme toggle: ◐ → sun + moon pair (2 buttons).
- Hamburger: ☰ → menu SVG.
- Editor toolbar: 🔗 (link) → link SVG; ⛓ (unlink) → broken-link SVG; ⛶ (fullscreen) → fullscreen SVG. (B/I/U/list/align/undo-redo remain typographic glyphs — standard editor pattern, not emoji; note below.)
- Keyword notes: 🔑 → key, ✏️ → pencil, 💾 → save.
- Post header: `+ เขียนโพสต์ใหม่` → plus SVG. Post-editor H1: ✏️/➕ → pencil/plus SVG.
- Settings save buttons (all variants: บันทึกการตั้งค่า ×2, VDOHide, การแสดงผล, บันทึก SEO, บันทึกชุดคีย์) → save SVG icon.
- Post-list / taxonomy action buttons: `แก้ไข` (5×) → pencil; `ลบ` (6×) → trash.
- Dashboard stat icons already used inline SVG (verified 7 cards) — left intact.
- **Login page modernized:** user + lock SVG icons inside input fields (`.with-icon` padding), password toggle ◉ → eye SVG, theme-aware light/dark via CSS vars (was hardcoded dark), premium gradient brand panel retained, responsive.
- **2FA page:** shield SVG icon added to brand header.
- Button labels kept small (existing 11–13px; added `.avsf-btn svg{width:15px;height:15px}` sizing rule).

## 5. Fake buttons — NONE FOUND

Audited: zero `href="#"`, zero empty `onclick=""`, zero dead `<button>`. The only empty button is `.avsf-admin-menu-backdrop` (has `aria-label` + real click handler closing the drawer — verified in JS). No placeholder buttons requiring `disabled`+title.

## 6. Version bump — DONE

- `avsf-qrcode.js?v=2.0.8` → `?v=3.0.0` (2FA page).
- `admin-ui.css?v=14` → `?v=3.0.0`.
- No other `2.0.8`/version string present in these files (Shard D owns metadata files).

## 7. Menus/routes/features — preserved

No menu item, page, form, or endpoint removed or altered. All routes (`page=posts`, `post-new`, `post-edit`, `settings*`, `categories`, `studios`, `actors`, `tags`, `pages`, `comments`, `reports`, dashboard widgets) intact.

## Remaining / notes (not bugs, honest limitations)

- **2FA page** still uses `@media(prefers-color-scheme:dark)` (OS preference) rather than the saved `avsf-admin-theme`; anti-flash script is present but CSS doesn't key off it. Light-first so no black-box issue. Full saved-theme support would require duplicating the dark rule block as `[data-admin-theme="dark"]` — left as-is to avoid CSS-nesting invalidity; flagged for follow-up.
- **Editor toolbar** B/I/U/strike/list/align/undo/redo/horizontal-rule buttons still use typographic glyphs (B, I, U, •≡, ⇤, ↔, ⇥, ―, ↶, ↷, ?) rather than pure SVG. Emoji removed. Full SVG conversion of ~18 glyph buttons is cosmetic and was deprioritized; toolbar is already `flex-wrap` responsive.
- Section-title emojis (📂, 🏆, 🎬, 👤, 💡 auto-hints) retained as accents — explicitly allowed by SPEC ("Emoji may stay as accent").
- **i18n:** no translation layer in these files; all UI strings hardcoded Thai; `siteLanguage` setting only affects HTML lang. Matches SPEC §6 — reported, no translations invented.
- Cross-shard: theme/responsive changes are attribute/CSS only; modules (Shard B/C) that emit `.avsf-admin-dark` selectors or `body[data-admin-theme]` will still work because both `[data-admin-theme="dark"]` and `body[data-admin-theme="light"]` patterns are supported; if any module still toggles `.avsf-admin-dark` class, its dark rules would need the same selector conversion — flagged to parent for cross-shard audit.

**Backup:** `/tmp/index.php.shardA.bak`, `/tmp/admin-ui.css.shardA.bak`.
