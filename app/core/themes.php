<?php
declare(strict_types=1);

/**
 * /app/core/themes.php
 *
 * Core theme state + helpers:
 * - Core CSS always loads.
 * - Active theme CSS loads if present.
 * - Core header/footer/nav views render by default.
 * - A theme nav.php may override core nav.
 *
 * IMPORTANT:
 * - This class expects themes::init($configArray) (NOT a db object).
 * - No env var magic. No bootstrap/router edits required.
 */

final class themes
{
    /**
     * @var array<string, mixed>
     */
    private static array $state = [];

    /**
     * Init with config array (typically $config from bootstrap config include).
     *
     * Expected keys (optional):
     * - site_name (string)
     * - title (string)
     * - body_class (string)
     * - meta (array<string,string>)
     * - site_theme (string)  e.g. "classic"
     */
    public static function init(array $state): void
    {
        self::$state = $state;
    }

    /**
     * Read a value from theme state.
     *
     * @return mixed
     */
    public static function get(string $key)
    {
        return self::$state[$key] ?? null;
    }

    /**
     * Active theme slug, derived from config.
     */
    public static function theme_slug(): string
    {
        $slug = (string) (self::$state['site_theme'] ?? '');
        $slug = trim($slug);

        if ($slug === '') {
            return 'default';
        }

        // keep it safe
        $slug = (string) preg_replace('~[^a-z0-9_\-]+~i', '', $slug);

        return $slug !== '' ? $slug : 'default';
    }

    /**
     * Backward-safe alias used by older header builds.
     */
    public static function active_slug(): string
    {
        return self::theme_slug();
    }

    /**
     * Absolute filesystem path to theme directory if exists.
     */
    public static function theme_dir(): string
    {
        $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docroot === '') {
            return '';
        }

        $slug = self::theme_slug();
        $dir  = $docroot . '/public/themes/' . $slug;

        return is_dir($dir) ? $dir : '';
    }

    /**
     * Returns a web href for a theme asset if it exists.
     * Example: themes::href('assets/css/theme.css')
     */
    public static function href(string $path): string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return '';
        }

        $dir = self::theme_dir();
        if ($dir === '') {
            return '';
        }

        $full = $dir . '/' . $path;
        if (!is_file($full)) {
            return '';
        }

        return '/public/themes/' . self::theme_slug() . '/' . $path;
    }

    /**
     * Render <link> tags for core + active theme CSS.
     * Core always loads. Theme loads if file exists.
     */
    public static function css_links(): string
    {
        $out = '';

        // core css (always)
        $out .= '<link rel="stylesheet" href="/public/assets/css/core.css">' . PHP_EOL;

        // theme css (if exists)
        $themeCss = self::href('assets/css/theme.css');
        if ($themeCss !== '') {
            $out .= '<link rel="stylesheet" href="' . htmlspecialchars($themeCss, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        return $out;
    }

    /**
     * Render header view (core fallback).
     */
    public static function render_header(): void
    {
        $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docroot === '') {
            return;
        }

        $file = $docroot . '/app/views/core/header.php';
        if (is_file($file)) {
            require $file;
        }
    }

    /**
     * Render footer view (core fallback).
     */
    public static function render_footer(): void
    {
        $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docroot === '') {
            return;
        }

        $file = $docroot . '/app/views/core/footer.php';
        if (is_file($file)) {
            require $file;
        }
    }

    /**
     * Render nav:
     * - If theme provides /public/themes/{slug}/nav.php, that overrides.
     * - Else core nav view renders.
     */
    public static function render_nav(): void
    {
        $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docroot === '') {
            return;
        }

        $themeDir = self::theme_dir();
        if ($themeDir !== '') {
            $themeNav = $themeDir . '/nav.php';
            if (is_file($themeNav)) {
                require $themeNav;
                return;
            }
        }

        $coreNav = $docroot . '/app/views/core/nav.php';
        if (is_file($coreNav)) {
            require $coreNav;
        }
    }
}

