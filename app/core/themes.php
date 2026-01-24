<?php
declare(strict_types=1);

final class themes
{
    private static array $state = [
        'site_name'   => 'Poe Mei',
        'theme'       => 'shadow_witch',
        'base_href'   => '',
        'meta'        => [],
    ];

    public static function init(array $state): void
    {
        global $db;

        foreach ($state as $k => $v) {
            self::$state[(string)$k] = $v;
        }

        if (isset($db) && $db instanceof db) {
            $nameRow = $db->fetch("SELECT value FROM settings WHERE name='site_name' LIMIT 1");
            if ($nameRow) {
                self::$state['site_name'] = $nameRow['value'];
            }

            $themeRow = $db->fetch("SELECT value FROM settings WHERE name='site_theme' LIMIT 1");
            if ($themeRow && !empty($themeRow['value'])) {
                self::$state['theme'] = (string)$themeRow['value'];
            }
        }

        if (empty(self::$state['theme'])) {
            self::$state['theme'] = 'shadow_witch';
        }

        if (class_exists('seo')) {
            self::auto_generate_seo();
        }
    }

    public static function get(string $key): mixed 
    { 
        return self::$state[$key] ?? null; 
    }

    public static function render_header(): void { self::load_view('header'); }
    public static function render_nav(): void    { self::load_view('nav'); }
    public static function render_footer(): void { self::load_view('footer'); }

    private static function load_view(string $file): void
    {
        $theme = (string)self::$state['theme'];
        
        // Absolute path discovery
        $root = dirname(__DIR__, 2);
        
        $themePath = $root . "/public/themes/" . $theme . "/" . $file . ".php";
        $corePath  = $root . "/app/views/core/" . $file . ".php";

        // This WILL output in your HTML source code
        echo "\n";

        if (file_exists($themePath)) {
            require $themePath;
        } else {
            echo "\n";
            require $corePath;
        }
    }

    public static function css_links(): string 
    { 
        $theme = (string)self::$state['theme'];
        $base = self::href('');
        $out = '<link rel="stylesheet" href="' . $base . '/assets/css/core.css">' . "\n";
        if ($theme !== '') {
            $out .= '<link rel="stylesheet" href="' . $base . '/themes/' . $theme . '/assets/css/theme.css">' . "\n";
        }
        return $out;
    }

    public static function favicon_links(): string 
    { 
        return '<link rel="icon" href="' . self::href('/assets/icons/favicon.ico') . '">'; 
    }

    public static function href(string $href): string 
    {
        $base = trim((string)(self::$state['base_href'] ?? ''));
        return ($base !== '') ? rtrim($base, '/') . $href : '/public' . $href;
    }

    public static function get_canonical(): string { return class_exists('seo') ? seo::auto_generate_canonical() : ''; }
    public static function get_opengraph(): string { return class_exists('seo') ? seo::auto_generate_opengraph() : ''; }
    public static function get_twitter_card(): string { return class_exists('seo') ? seo::auto_generate_twitter_card() : ''; }
    public static function get_schema(): string { return class_exists('seo') ? seo::auto_generate_schema('org') : ''; }

    protected static function auto_generate_seo(): void 
    {
        $meta = seo::auto_generate_meta();
        if (is_array($meta)) {
            self::$state['meta'] = array_merge((array)self::$state['meta'], $meta);
        }
    }
}
