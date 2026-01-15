<?php
declare(strict_types=1);

/**
 * Chaos CMS — Themes
 *
 * Contract:
 * - Core ALWAYS owns templates:
 *   /app/views/core/header.php
 *   /app/views/core/footer.php
 *
 * - Themes MAY override nav only:
 *   /public/themes/{theme}/nav.php
 *
 * - Themes own assets:
 *   /public/themes/{theme}/assets/css/theme.css (optional)
 *   /public/themes/{theme}/assets/js/theme.js   (optional)
 *
 * - Core assets:
 *   /public/assets/css/core.css (required)
 *   /public/assets/js/core.js   (optional)
 *
 * - Icons / Favicons:
 *   Theme may supply:
 *     /public/themes/{theme}/assets/icons/favicon.ico
 *     /public/themes/{theme}/assets/icons/favicon.png
 *     /public/themes/{theme}/assets/icons/apple-touch-icon.png
 *     /public/themes/{theme}/assets/icons/icon.svg
 *   Core fallback may supply:
 *     /public/assets/icons/favicon.ico
 *     /public/assets/icons/favicon.png
 *     /public/assets/icons/apple-touch-icon.png
 *     /public/assets/icons/icon.svg
 *
 * - Routing:
 *   Chaos routes from project root, so href() prefixes /public unless base_href is provided.
 */
final class themes
{
    /**
     * @var array<string, mixed>
     */
    private static array $state = [
        'site_name'  => 'Chaos CMS',
        'title'      => '',
        'theme'      => '',
        'base_href'  => '',
        'body_class' => '',
        'meta'       => [],
    ];

    /**
     * @param array<string, mixed> $state
     * @return void
     */
    public static function init(array $state): void
    {
        foreach ($state as $k => $v) {
            self::$state[(string) $k] = $v;
        }

        $theme = (string) (self::$state['theme'] ?? '');
        self::$state['theme'] = self::resolve_theme($theme);

        // ---------------------------------------------------------
        // Body class defaults (theme-driven)
        // ---------------------------------------------------------
        $body = trim((string) (self::$state['body_class'] ?? ''));

        if ($body === '') {
            $active = (string) (self::$state['theme'] ?? '');

            // Shadow Witch theme expects "sw-body" on <body>
            if ($active === 'shadow_witch') {
                self::$state['body_class'] = 'sw-body';
            } elseif ($active !== '') {
                // Generic theme class if someone wants to target it
                self::$state['body_class'] = $active;
            }
        }
    }

    /**
     * @param string $title
     * @return void
     */
    public static function set_title(string $title): void
    {
        self::$state['title'] = $title;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public static function get(string $key): mixed
    {
        return self::$state[$key] ?? null;
    }

    /**
     * Render header (CORE ONLY).
     *
     * @return void
     */
    public static function render_header(): void
    {
        $theme = (string) (self::$state['theme'] ?? '');

        if ($theme !== '') {
            $themeHead = self::project_root() . '/public/themes/' . $theme . '/header.php';

            if (is_file($themeHead)) {
                require $themeHead;
                return;
            }
        }
        require self::project_root() . '/app/views/core/header.php';
    }

    /**
     * Render nav:
     * - Theme override: /public/themes/{theme}/nav.php
     * - Core fallback:  /app/views/core/nav.php
     *
     * @return void
     */
    public static function render_nav(): void
    {
        $theme = (string) (self::$state['theme'] ?? '');

        if ($theme !== '') {
            $themeNav = self::project_root() . '/public/themes/' . $theme . '/nav.php';

            if (is_file($themeNav)) {
                require $themeNav;
                return;
            }
        }

        require self::project_root() . '/app/views/core/nav.php';
    }

    /**
     * Render footer (CORE ONLY).
     *
     * @return void
     */
    public static function render_footer(): void
    {
        $theme = (string) (self::$state['theme'] ?? '');

        if ($theme !== '') {
            $themeFoot = self::project_root() . '/public/themes/' . $theme . '/footer.php';

            if (is_file($themeFoot)) {
                require $themeFoot;
                return;
            }
        }
        require self::project_root() . '/app/views/core/footer.php';
    }

    /**
     * @return string
     */
    public static function css_links(): string
    {
        $out = '';

        // Always: core css
        $coreHref = htmlspecialchars(self::href('/assets/css/core.css'), ENT_QUOTES, 'UTF-8');
        $out .= '<link rel="stylesheet" href="' . $coreHref . '">' . PHP_EOL;

        // Optional: theme css (loads AFTER core, so it overrides)
        $theme = (string) (self::$state['theme'] ?? '');
        if ($theme !== '') {
            $themeFs = self::project_root() . '/public/themes/' . $theme . '/assets/css/theme.css';

            if (is_file($themeFs)) {
                $themeHref = htmlspecialchars(self::href('/themes/' . $theme . '/assets/css/theme.css'), ENT_QUOTES, 'UTF-8');
                $out .= '<link rel="stylesheet" href="' . $themeHref . '">' . PHP_EOL;
            }
        }

        return $out;
    }

    /**
     * @return string
     */
    public static function js_links(): string
    {
        $out = '';

        // Optional: core js
        $coreFs = self::project_root() . '/public/assets/js/core.js';

        if (is_file($coreFs)) {
            $coreSrc = htmlspecialchars(self::href('/assets/js/core.js'), ENT_QUOTES, 'UTF-8');
            $out .= '<script src="' . $coreSrc . '"></script>' . PHP_EOL;
        }

        // Optional: theme js
        $theme = (string) (self::$state['theme'] ?? '');
        if ($theme !== '') {
            $themeFs = self::project_root() . '/public/themes/' . $theme . '/assets/js/theme.js';

            if (is_file($themeFs)) {
                $themeSrc = htmlspecialchars(self::href('/themes/' . $theme . '/assets/js/theme.js'), ENT_QUOTES, 'UTF-8');
                $out .= '<script src="' . $themeSrc . '"></script>' . PHP_EOL;
            }
        }

        return $out;
    }

    /**
     * Favicons / icons. Theme-first, then core fallback.
     *
     * @return string
     */
    public static function favicon_links(): string
    {
        $out = '';

        $theme = (string) (self::$state['theme'] ?? '');

        $tryTheme = static function (string $rel) use ($theme): string {
            if ($theme === '') {
                return '';
            }

            $fs = self::project_root() . '/public/themes/' . $theme . '/assets/icons/' . ltrim($rel, '/');
            if (!is_file($fs)) {
                return '';
            }

            return self::href('/themes/' . $theme . '/assets/icons/' . ltrim($rel, '/'));
        };

        $tryCore = static function (string $rel): string {
            $fs = self::project_root() . '/public/assets/icons/' . ltrim($rel, '/');
            if (!is_file($fs)) {
                return '';
            }

            return self::href('/assets/icons/' . ltrim($rel, '/'));
        };

        $faviconIco = $tryTheme('favicon.ico');
        if ($faviconIco === '') {
            $faviconIco = $tryCore('favicon.ico');
        }

        $faviconPng = $tryTheme('favicon.png');
        if ($faviconPng === '') {
            $faviconPng = $tryCore('favicon.png');
        }

        $appleTouch = $tryTheme('apple-touch-icon.png');
        if ($appleTouch === '') {
            $appleTouch = $tryCore('apple-touch-icon.png');
        }

        $iconSvg = $tryTheme('icon.svg');
        if ($iconSvg === '') {
            $iconSvg = $tryCore('icon.svg');
        }
        
        $iconPng = $tryTheme('icon.png');
        if ($iconPng === '') {
            $iconPng = $tryCore('icon.png');
        }

        if ($faviconIco !== '') {
            $out .= '<link rel="icon" href="' . htmlspecialchars($faviconIco, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        if ($faviconPng !== '') {
            $out .= '<link rel="icon" type="image/png" href="' . htmlspecialchars($faviconPng, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        if ($appleTouch !== '') {
            $out .= '<link rel="apple-touch-icon" href="' . htmlspecialchars($appleTouch, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        if ($iconSvg !== '') {
            $out .= '<link rel="icon" type="image/svg+xml" href="' . htmlspecialchars($iconSvg, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        return $out;
    }

    /**
     * Build an href that ALWAYS targets /public as the web root for assets,
     * unless base_href is explicitly provided.
     * 
     * FIXED: Now handles admin routes and absolute paths correctly.
     *
     * @param string $href
     * @return string
     */
    public static function href(string $href): string
    {
        $href = trim((string) $href);

        if ($href === '') {
            return '';
        }

        // Already absolute or protocol-relative - return as-is
        if (str_starts_with($href, 'http://') || 
            str_starts_with($href, 'https://') || 
            str_starts_with($href, '//')) {
            return $href;
        }

        // Already starts with /public/ - return as-is
        if (str_starts_with($href, '/public/')) {
            return $href;
        }

        // Check for explicit base_href override
        $base = trim((string) (self::$state['base_href'] ?? ''));

        if ($base !== '') {
            return rtrim($base, '/') . '/' . ltrim($href, '/');
        }

        // Default: prefix with /public
        return '/public/' . ltrim($href, '/');
    }

    /**
     * @return string
     */
    private static function project_root(): string
    {
        if (defined('APP_ROOT')) {
            return dirname((string) APP_ROOT);
        }

        return dirname(__DIR__, 2);
    }

    /**
     * @param string $theme
     * @return string
     */
    private static function resolve_theme(string $theme): string
    {
        $theme = trim($theme);
        $theme = (string) preg_replace('~[^a-z0-9_\-]~i', '', $theme);

        if ($theme === '' || strtolower($theme) === 'default') {
            return '';
        }

        $dir = self::project_root() . '/public/themes/' . $theme;

        if (!is_dir($dir)) {
            return '';
        }

        return $theme;
    }
}
