# AVSF v3.1.1 — I18N Conversion Spec (FROZEN — all shards must follow exactly)

## Infrastructure (v3.1.1 hotfix: all dictionary partials must return arrays; core-frontend.php was normalized to this contract)
- `avsf-core/i18n.php` — loaded automatically from `bootstrap.php`. Defines helpers below.
- `avsf-core/lang/en/_core.php` — shared common strings.
- Dictionary layout: `avsf-core/lang/<locale>/*.php`, each file `return ['Thai key'=>'translation', ...];`. All partials are merged at runtime.
- Default language = Thai. Keys are the **original Thai strings** (gettext-style). Missing key falls back to the Thai key itself — nothing ever breaks.

## Helper API (use exactly these)
- `__($key, $args=null)` — returns translated string. `$args` array for `%s` placeholders: `__('พบ %s เรื่อง', [$count])`.
- `_e($key, $args=null)` — echo shortcut.
- `avsf_js($s)` — quotes a translated string for JS literals: `confirm(<?php echo avsf_js(__('ยืนยันลบ?')); ?>)`.
- `avsf_lang_attr()` — for `<html lang="...">`.
- `avsf_lang_switcher_html()` — compact TH/EN toggle (inline-styled, no CSS dependency). Insert **once per page**.

## Conversion rules
1. **Translate**: every user-visible Thai string — `<title>`, headings, labels, buttons, `<option>` text, table headers, `title=`/`aria-label=` tooltips, input `placeholder=`, success/error `$msg` shown to user, `confirm()`/`alert()` text, JSON API messages shown in UI.
2. **Do NOT translate or touch**: `error_log()` args, thrown exception messages, SQL, CSS classes/IDs, `data-*` attributes, form field `name=`, URLs/hrefs/route paths, menu targets, `logActivity()` strings (leave Thai), CSV column headers, technical slugs. (Leaving logActivity Thai is intentional — note in your report.)
3. **Variables in strings**: convert concatenation to `%s` placeholder.
   - Before: `$msg = 'บันทึกแล้ว ' . $n . ' เรื่อง';`
   - After:  `$msg = __('บันทึกแล้ว %s เรื่อง', [$n]);`
4. **HTML output**: `<?php echo __('ข้อความ'); ?>` (prefer over `<?= ... ?>`). For plain text nodes: `<h1><?php _e('หัวข้อ'); ?></h1>`.
5. **JS literals**: always `avsf_js(__('...'))` — never put a raw translated string inside `onclick="..."` or `<script>`.
6. **JSON responses**: `echo json_encode(['error' => __('ข้อความ')], JSON_UNESCAPED_UNICODE);`.
7. **`<html lang="th">`** → `<html lang="<?php echo avsf_lang_attr(); ?>">`.
8. **Language switcher**: insert `<?php echo avsf_lang_switcher_html(); ?>` once per page — admin modules: right after the "กลับแดชบอร์ด" back-link (or top-right of header); frontend: in footer/header. Wrap in a floated container if needed, e.g. `<div style="float:right;margin-top:-2px"><?php echo avsf_lang_switcher_html(); ?></div>`.
9. **Emoji prefixes**: keep the emoji in the key. `'✅ บันทึกแล้ว'` → key `'✅ บันทึกแล้ว'` → `'✅ Saved'`.
10. **Preserve everything else exactly**: HTML structure, CSS, JS logic, event handlers, form handling. Only string literals change.

## Your dictionary partial
- Create ONE file: `avsf-core/lang/en/<YOUR-PARTIAL-NAME>.php` (e.g. `admin-modules.php`).
- It must contain **every Thai key you introduced**, mapped to a natural English translation. Duplicates across partials are harmless.
- Do NOT edit `_core.php` or other shards' partial files. Do NOT create a `th/` partial (keys are Thai).

## Self-check before returning
- No unbalanced `<?php`/`?>`, no stray text outside PHP.
- Run the syntax checker (report result):
  `node /home/user/.dola/agent_mode/workspace/.sessions/38418591691189009/agents/o_0000iUBN9qe/phplint.js <your files...>`
- Return: list of files changed, key count in your partial, lint result, any strings intentionally skipped (with reason).
