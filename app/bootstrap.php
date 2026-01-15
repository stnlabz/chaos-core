<?php
/**
 * Bootstrap
 * Pre loads Core and Lib
 */

declare(strict_types=1);

// ------------------------------------------------------------
// Security Headers (MUST - prevents common attacks)
// ------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Definitions
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__) . '/app');
}
$ROOT = dirname(__DIR__);
define('LOG_PATH', $ROOT . '/logs');

/**
 * Debug
 * Used for development
 * set $debug = false to shut it off
 * or just delete the if()
 */
$debug = true;
if ($debug) {
    // Error Logging
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', LOG_PATH . '/site_errors.log');

    // Optional: set error reporting level
    error_reporting(E_ALL);

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// -------------------------------------------------------------
// Load LIB function files (like render_json, render_markdown, etc.)
// -------------------------------------------------------------

$libPath = __DIR__ . '/lib';

foreach (glob($libPath . '/*.php') as $libFile) {
    require_once $libFile;
}

// -------------------------------------------------------------
// Autoload classes from /app/core and /app/lib
// -------------------------------------------------------------
spl_autoload_register(function ($class) {
    $core = __DIR__ . '/core/' . $class . '.php';
    if (is_file($core)) {
        require_once $core;
        return;
    }

    $lib = __DIR__ . '/lib/' . $class . '.php';
    if (is_file($lib)) {
        require_once $lib;
        return;
    }

    // absolutely nothing else
});

$db = new db();
$auth = new auth($db);
modules::ensure_registry_tables($db);
plugins::load_enabled($db);

// -------------------------------------------------------------
// Renderer callables (router uses these via render_markdown/render_json)
// -------------------------------------------------------------

$render_md = null;

if (class_exists('render_md')) {
    $md = new render_md();

    if (method_exists($md, 'markdown_file')) {
        $render_md = static function (string $path) use ($md): void {
            $md->markdown_file($path);
        };
    } elseif (method_exists($md, 'file')) {
        $render_md = static function (string $path) use ($md): void {
            $md->file($path);
        };
    } elseif (method_exists($md, 'render_file')) {
        $render_md = static function (string $path) use ($md): void {
            $md->render_file($path);
        };
    }
}

$render_json = null;

if (class_exists('render_json')) {
    $jr = new render_json();

    if (method_exists($jr, 'json_file')) {
        $render_json = static function (string $path) use ($jr): void {
            $jr->json_file($path);
        };
    } elseif (method_exists($jr, 'file')) {
        $render_json = static function (string $path) use ($jr): void {
            $jr->file($path);
        };
    } elseif (method_exists($jr, 'render_file')) {
        $render_json = static function (string $path) use ($jr): void {
            $jr->render_file($path);
        };
    }
}

if (!function_exists('render_markdown')) {
    function render_markdown(string $path): void
    {
        global $render_md;

        if (is_callable($render_md)) {
            $render_md($path);
            return;
        }

        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">'
            . 'Markdown renderer missing ($render_md not callable).'
            . '</div></div>';
    }
}

if (!function_exists('render_json')) {
    function render_json(string $path): void
    {
        global $render_json;

        if (is_callable($render_json)) {
            $render_json($path);
            return;
        }

        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">'
            . 'JSON renderer missing ($render_json not callable).'
            . '</div></div>';
    }
}


// bootstrap (after $db is ready)
$settings = [];

$rows = $db->fetch_all('SELECT name, value FROM settings');
foreach ($rows as $row) {
    $settings[$row['name']] = $row['value'];
}

// expose specific things
$site_name = $settings['site_name'] ?? 'Website';

/**
 * Themes
 * From the DB (fallback to filesystem default)
 */
// -------------------------------------------------------------
// Active Theme (DB-driven, KISS + filesystem fallback)
// -------------------------------------------------------------
$site_theme = 'default';

if ($db instanceof db) {
    // 1) settings override (settings.name = site_theme)
    $row = $db->fetch("SELECT value FROM settings WHERE name='site_theme' LIMIT 1");

    $raw = '';
    if (is_array($row) && isset($row['value'])) {
        $raw = trim((string) $row['value']);
    }

    /**
     * IMPORTANT:
     * "default" is NOT a real theme override.
     * It means: "use enabled theme if any, otherwise core fallback".
     */
    if ($raw !== '' && strtolower($raw) !== 'default') {
        $site_theme = $raw;
    } else {
        // 2) enabled theme from themes table (schema: themes.slug, themes.enabled)
        $t = $db->fetch('SELECT slug FROM themes WHERE enabled=1 LIMIT 1');

        if (is_array($t) && isset($t['slug']) && trim((string) $t['slug']) !== '') {
            $site_theme = trim((string) $t['slug']);
        }
    }
}

// sanitize slug
$site_theme = (string) preg_replace('~[^a-z0-9_\-]~i', '', $site_theme);

require_once __DIR__ . '/core/themes.php';
themes::init([
    'site_name' => $site_name ?? 'Chaos CMS',
    'theme'     => $site_theme ?? '',
    'base_href' => $base_href ?? '',
]);

/**
 * SEO
 * Automates the development and maintenance of SEO
 * sitemap.xml
 * ror.xml
 */
seo::run($site_theme);

/**
 * Social Media Sharing
 */
require_once __DIR__ . '/lib/share.php';
