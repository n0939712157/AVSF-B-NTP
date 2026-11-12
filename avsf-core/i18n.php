<?php
/**
 * AVSF i18n layer (v3.2.0).
 * Loaded from avsf-core/bootstrap.php AFTER session_start().
 * Default language: Thai (th). Keys are the original Thai strings (gettext-style);
 * a missing key falls back to the Thai key itself, so nothing ever breaks.
 *
 * Dictionary layout (merged at runtime, no single shared file to conflict on):
 *   avsf-core/lang/th/*.php   each returns array  (optional; usually empty)
 *   avsf-core/lang/en/*.php   each returns array  'Thai key' => 'English text'
 *
 * Public helpers:
 *   __($key, $args=null)   translate + optional vsprintf/named {placeholders}
 *   _e($key, $args=null)   echo __()
 *   avsf_locale()          current locale code ('th'|'en')
 *   avsf_lang_attr()       <html lang=""> value ('th'|'en')
 *   avsf_js($s)            json_encode() a translated string for JS literals
 *   avsf_lang_switcher_html()  compact TH/EN toggle preserving current query
 */

if (!defined('AVSF_ROOT')) exit;

function avsf_i18n_allowed_locales() {
    return ['th', 'en'];
}

function avsf_detect_locale() {
    $allowed = avsf_i18n_allowed_locales();
    $locale = 'th';
    $source = null;
    if (PHP_SAPI !== 'cli' && isset($_GET['lang']) && is_string($_GET['lang'])) {
        $candidate = strtolower(substr(trim($_GET['lang']), 0, 2));
        if (in_array($candidate, $allowed, true)) { $locale = $candidate; $source = 'get'; }
    }
    if ($source === null && PHP_SAPI !== 'cli' && !empty($_COOKIE['avsf_lang']) && is_string($_COOKIE['avsf_lang'])) {
        $candidate = strtolower(substr(trim($_COOKIE['avsf_lang']), 0, 2));
        if (in_array($candidate, $allowed, true)) { $locale = $candidate; $source = 'cookie'; }
    }
    if ($source === null && isset($_SESSION['avsf_lang']) && is_string($_SESSION['avsf_lang'])) {
        $candidate = strtolower(substr(trim($_SESSION['avsf_lang']), 0, 2));
        if (in_array($candidate, $allowed, true)) { $locale = $candidate; $source = 'session'; }
    }
    if (PHP_SAPI !== 'cli') {
        $_SESSION['avsf_lang'] = $locale;
        if ($source === 'get' || empty($_COOKIE['avsf_lang']) || $_COOKIE['avsf_lang'] !== $locale) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            @setcookie('avsf_lang', $locale, time() + 86400 * 365, '/', '', $secure, true);
        }
    }
    return $locale;
}

$GLOBALS['AVSF_LOCALE'] = avsf_detect_locale();
$GLOBALS['AVSF_DICT'] = [];

function avsf_load_dictionary($locale) {
    $dict = [];
    $dir = AVSF_CORE_DIR . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $locale;
    if (is_dir($dir)) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            $part = require $file;
            if (is_array($part)) {
                foreach ($part as $k => $v) {
                    if (is_string($k) && is_string($v) && $v !== '') $dict[$k] = $v;
                }
            }
        }
    }
    return $dict;
}

$GLOBALS['AVSF_DICT'] = avsf_load_dictionary($GLOBALS['AVSF_LOCALE']);

function avsf_locale() { return $GLOBALS['AVSF_LOCALE']; }
function avsf_lang_attr() { return $GLOBALS['AVSF_LOCALE'] === 'en' ? 'en' : 'th'; }

function __($key, $args = null) {
    $dict = $GLOBALS['AVSF_DICT'];
    $text = isset($dict[$key]) ? $dict[$key] : (string)$key;
    if ($args === null || $args === []) return $text;
    if (!is_array($args)) $args = [$args];
    // Named placeholders {name} first, then printf-style %s
    if (isset($args[0]) && is_string($args[0]) === false) {
        foreach ($args as $k => $v) {
            if (is_string($k)) $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
    }
    if (strpos($text, '%') !== false) {
        $numArgs = array_values(array_filter($args, function ($a, $k) { return is_int($k); }, ARRAY_FILTER_USE_BOTH));
        if ($numArgs) {
            $formatted = @vsprintf($text, $numArgs);
            if ($formatted !== false) $text = $formatted;
        }
    }
    return $text;
}

function _e($key, $args = null) { echo __($key, $args); }

/** Quote a (translated) string for safe use as a JS string literal. */
function avsf_js($s) {
    return json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Build a self-referencing URL that switches ?lang= while keeping other query params. */
function avsf_lang_url($locale) {
    if (PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_URI'])) return '?lang=' . $locale;
    $uri = (string)$_SERVER['REQUEST_URI'];
    $parts = parse_url($uri);
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $query['lang'] = $locale;
    $path = isset($parts['path']) ? $parts['path'] : '';
    return $path . '?' . http_build_query($query);
}

/** Compact inline TH/EN toggle. Inline styles so it needs no extra CSS file. */
function avsf_lang_switcher_html() {
    $cur = avsf_locale();
    $thActive = $cur === 'th';
    $enActive = $cur === 'en';
    $base = 'display:inline-flex;gap:4px;align-items:center;font:12px/1 system-ui,sans-serif;';
    $btn = 'padding:5px 9px;border-radius:8px;border:1px solid rgba(127,127,160,.35);text-decoration:none;color:inherit;background:transparent;cursor:pointer;';
    $on = 'background:linear-gradient(110deg,#f52b7a,#a64de5);color:#fff;border-color:transparent;font-weight:700;';
    return '<div class="avsf-lang-switcher" style="' . $base . '" aria-label="Language">'
        . '<a href="' . htmlspecialchars(avsf_lang_url('th'), ENT_QUOTES, 'UTF-8') . '" style="' . $btn . ($thActive ? $on : '') . '">ไทย</a>'
        . '<a href="' . htmlspecialchars(avsf_lang_url('en'), ENT_QUOTES, 'UTF-8') . '" style="' . $btn . ($enActive ? $on : '') . '">EN</a>'
        . '</div>';
}
