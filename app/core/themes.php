<?php
declare(strict_types=1);

final class themes
{
    private static bool $booted = false;
    private static array $state = [
        'site_name' => 'Chaos CMS',
        'theme'     => '',
        'base_href' => '',
    ];

    /**
     * Self-booting logic to ensure DB is checked 
     * without needing a manual call in index.php
     */
    private static function boot(): void
    {
        if (self::$booted) return;
        
        global $db;

        if (isset($db) && $db instanceof db) {
            $nameRow = $db->fetch("SELECT value FROM settings WHERE 
name='site_name' LIMIT 1");
            if ($nameRow) self::$state['site_name'] = 
(string)$nameRow['value'];

            $themeRow = $db->fetch("SELECT value FROM settings WHERE 
name='site_theme' LIMIT 1");
            if ($themeRow) self::$state['theme'] = 
(string)$themeRow['value'];
        }

        // Validate theme path
        $slug = preg_replace('~[^a-z0-9_\-]~i', '', 
trim((string)self::$state['theme']));
        $path = dirname(__DIR__, 2) . '/public/themes/' . $slug;
        
        if ($slug !== '' && is_dir($path)) {
            self::$state['theme'] = $slug;
        } else {
            self::$state['theme'] = '';
        }

        self::$booted = true;
    }

    public static function get(string $key): mixed 
    { 
        self::boot();
        return self::$state[$key] ?? null; 
    }

    public static function render_header(): void { 
self::load_view('header'); }
    public static function render_nav(): void    { 
self::load_view('nav'); }
    public static function render_footer(): void { 
self::load_view('footer'); }

    // SEO stubs to satisfy core views
    public static function get_canonical(): string { return ''; }
    public static function get_opengraph(): string { return ''; }
    public static function get_twitter_card(): string { return ''; }
    public static function get_schema(): string { return ''; }

    private static function load_view(string $file): void
    {
        self::boot();
        $theme = (string)self::$state['theme'];
        $root  = dirname(__DIR__, 2);
        
        $themePath = $root . "/public/themes/" . $theme . "/" . $file . 
".php";
        $corePath  = $root . "/app/views/core/" . $file . ".php";

        if ($theme !== '' && file_exists($themePath)) {
            require $themePath;
        } else {
            require $corePath;
        }
    }

    public static function css_links(): string 
    { 
        self::boot();
        $theme = (string)self::$state['theme'];
        $base = self::href('');
        $out = '<link rel="stylesheet" href="' . $base . 
'/assets/css/core.css">' . "\n";
        if ($theme !== '') {
            $out .= '<link rel="stylesheet" href="' . $base . '/themes/' 
. $theme . '/assets/css/theme.css">' . "\n";
        }
        return $out;
    }

    public static function favicon_links(): string { return '<link 
rel="icon" href="' . self::href('/assets/icons/favicon.ico') . '">'; }
    
    public static function href(string $href): string 
    {
        $base = trim((string)(self::$state['base_href'] ?? ''));
        return ($base !== '') ? rtrim($base, '/') . $href : '/public' . 
$href;
    }
}
