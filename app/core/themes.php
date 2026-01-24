<?php
declare(strict_types=1);

final class themes
{
    private static array $state = [
        'site_name'  => 'Chaos CMS',
        'title'      => '',
        'theme'      => '',
        'base_href'  => '',
        'body_class' => '',
        'meta'       => [],
        'seo_meta'   => '',
        'seo_og'     => '',
        'seo_twitter' => '',
        'canonical'  => '',
        'schema'     => '',
    ];

    public static function init(array $state): void
    {
        foreach ($state as $k => $v) {
            self::$state[(string) $k] = $v;
        }

        $theme = (string) (self::$state['theme'] ?? '');
        self::$state['theme'] = self::resolve_theme($theme);

        if (class_exists('seo')) {
            self::auto_generate_seo();
        }
    }

    protected static function auto_generate_seo(): void
    {
        $meta = seo::auto_generate_meta();
        if (!empty($meta)) {
            self::$state['meta'] = array_merge((array)(self::$state['meta'] ?? []), $meta);
        }
        
        self::$state['seo_og'] = seo::auto_generate_opengraph();
        self::$state['seo_twitter'] = self::generate_twitter_card_with_url();
        self::$state['canonical'] = seo::auto_generate_canonical();
        
        if (($_SERVER['REQUEST_URI'] ?? '/') === '/') {
            self::$state['schema'] = self::generate_site_schema();
        }
    }

    protected static function generate_twitter_card_with_url(): string
    {
        $siteUrl = class_exists('seo') ? seo::get_BaseUrl() : '';
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        $fullUrl = htmlspecialchars($siteUrl . $currentUri, ENT_QUOTES, 'UTF-8');

        $html = seo::auto_generate_twitter_card();
        $html .= '<meta name="twitter:url" content="' . $fullUrl . '">' . PHP_EOL;
        return $html;
    }

    protected static function generate_site_schema(): string
    {
        $siteUrl = class_exists('seo') ? seo::get_BaseUrl() : '';
        return seo::auto_generate_schema('organization', ['url' => $siteUrl]);
    }

    public static function set_title(string $title): void { self::$state['title'] = $title; }

    public static function get(string $key): mixed { return self::$state[$key] ?? null; }
    public static function get_opengraph(): string { return (string)(self::$state['seo_og'] ?? ''); }
    public static function get_twitter_card(): string { return (string)(self::$state['seo_twitter'] ?? ''); }
    public static function get_canonical(): string { return (string)(self::$state['canonical'] ?? ''); }
    public static function get_schema(): string { return (string)(self::$state['schema'] ?? ''); }

    public static function render_header(): void { require self::project_root() . '/app/views/core/header.php'; }
    public static function render_nav(): void { require self::project_root() . '/app/views/core/nav.php'; }
    public static function render_footer(): void { require self::project_root() . '/app/views/core/footer.php'; }

    public static function css_links(): string { return '<link rel="stylesheet" href="'.self::href('/assets/css/core.css').'">'; }
    public static function js_links(): string { return '<script src="'.self::href('/assets/js/core.js').'"></script>'; }

    public static function favicon_links(): string
    {
        $ico = self::href('/assets/icons/favicon.ico');
        return '<link rel="icon" href="' . htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function href(string $href): string
    {
        $base = trim((string) (self::$state['base_href'] ?? ''));
        return ($base !== '') ? rtrim($base, '/') . $href : '/public' . $href;
    }

    private static function project_root(): string { return dirname(__DIR__, 2); }

    private static function resolve_theme(string $theme): string
    {
        $theme = (string) preg_replace('~[^a-z0-9_\-]~i', '', trim($theme));
        return (is_dir(self::project_root() . '/public/themes/' . $theme)) ? $theme : '';
    }
}
