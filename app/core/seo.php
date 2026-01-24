<?php
declare(strict_types=1);

class seo
{
    protected static string $rootPath = '';
    protected static string $baseUrl  = '';

    public static function run(string $theme): void
    {
        self::$rootPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        self::$baseUrl  = self::get_BaseUrl();

        $links = self::discover_modules();
        if (!empty($links)) {
            self::generate_ai_manifests($links);
            self::writeSitemap($links);
        }
    }

    protected static function discover_modules(): array
    {
        global $db;
        if (!isset($db) || !($db instanceof db)) return [];
        try {
            $mods = $db->fetch_all("SELECT title, slug FROM modules");
            $links = [];
            if ($mods) {
                foreach ($mods as $m) {
                    $links[] = [
                        'title' => $m['title'],
                        'href'  => self::get_BaseUrl() . "/products/view/" . $m['slug']
                    ];
                }
            }
            return $links;
        } catch (\Throwable $e) { return []; }
    }

    protected static function generate_ai_manifests(array $links): void
    {
        $aiData = [];
        foreach ($links as $l) {
            $aiData[] = ['title' => $l['title'], 'url' => $l['href'], 'brief' => "Poe Mei content for " . $l['title']];
        }
        @file_put_contents(self::$rootPath . '/llm.json', json_encode(['index' => $aiData], JSON_PRETTY_PRINT));
    }

    public static function auto_generate_meta(): array { return ['description' => 'Poe Mei']; }
    public static function auto_generate_opengraph(): string { return ''; }
    public static function auto_generate_twitter_card(): string { return ''; }
    public static function auto_generate_canonical(): string { 
        return '<link rel="canonical" href="'.htmlspecialchars(self::get_BaseUrl().($_SERVER['REQUEST_URI'] ?? '')).'">'; 
    }
    public static function auto_generate_schema($t): string { return ''; }

    public static function get_BaseUrl(): string {
        return ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    protected static function writeSitemap($links) {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($links as $l) { $xml .= " <url><loc>".htmlspecialchars($l['href'])."</loc></url>\n"; }
        $xml .= "</urlset>";
        @file_put_contents(self::$rootPath . '/sitemap.xml', $xml);
    }
}
