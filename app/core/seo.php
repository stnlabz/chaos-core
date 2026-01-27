<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core SEO
 * Centralized SEO & AI Manifest Management
 */
class seo
{
    protected static string $rootPath = '';
    protected static string $baseUrl  = '';
    protected static string $hashPath = '';

    public static function run(string $theme): void
    {
        self::$rootPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        self::$baseUrl  = self::detectBaseUrl();
        self::$hashPath = dirname(__DIR__) . '/data/seo_hash.json';

        $themeDir = self::$rootPath . '/public/themes/' . $theme;
        if (!is_dir($themeDir)) return;

        $navFile = self::findNavFile($themeDir);
        if (!$navFile) return;

        $html = file_get_contents($navFile);
        if (!$html) return;

        $links = self::extractLinks($html, self::$baseUrl);
        if (empty($links)) return;

        $currentHash = sha1((string)json_encode($links));
        $oldHash = is_file(self::$hashPath) ? trim((string)file_get_contents(self::$hashPath)) : '';

        if ($currentHash !== $oldHash) {
            self::writeSitemap($links);
            self::writeRor($links);
            self::writeLlmTxt($links);
            self::writeLlmJson($links);
            self::updateRobots(); // New: Keeps robots.txt synced
            
            if (!is_dir(dirname(self::$hashPath))) mkdir(dirname(self::$hashPath), 0775, true);
            file_put_contents(self::$hashPath, $currentHash);
        }
    }

    /**
     * Updates robots.txt to include AI manifests and sitemaps.
     */
    protected static function updateRobots(): void
    {
        $robotsPath = self::$rootPath . '/robots.txt';
        $content = "User-agent: *\nAllow: /\n\n";
        
        // Standard SEO
        $content .= "Sitemap: " . self::$baseUrl . "/sitemap.xml\n";
        
        // AI Manifests
        $content .= "LLMSxt: " . self::$baseUrl . "/llms.txt\n";
        $content .= "LLMJson: " . self::$baseUrl . "/llm.json\n";

        @file_put_contents($robotsPath, $content);
    }

    /* --- AI MANIFESTS --- */

    protected static function writeLlmTxt(array $links): void
    {
        $txt = "# " . ($_SERVER['HTTP_HOST'] ?? 'Chaos CMS') . " Directory\n\n";
        foreach ($links as $l) {
            $txt .= "- " . $l['title'] . ": " . $l['href'] . "\n";
        }
        @file_put_contents(self::$rootPath . '/llms.txt', $txt);
    }

    protected static function writeLlmJson(array $links): void
    {
        $data = ['version' => '1.0', 'links' => $links];
        @file_put_contents(self::$rootPath . '/llm.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    /* --- ORIGINAL LOGIC: NAV SCANNING & XML GENERATION --- */

    protected static function detectBaseUrl(): string {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    protected static function findNavFile(string $themeDir): ?string {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($themeDir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $c = file_get_contents($file->getPathname());
                if (strpos($c, '<nav') !== false || strpos($c, 'navbar') !== false) return $file->getPathname();
            }
        }
        return null;
    }

    protected static function extractLinks(string $html, string $base): array {
        $links = [];
        if (preg_match_all('/<a\s+[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = trim((string)$m[2]);
                if ($href !== '' && $href[0] === '/') {
                    $links[] = [
                        'title' => trim(strip_tags((string)$m[3])),
                        'href'  => rtrim($base, '/') . $href,
                        'date'  => gmdate('Y-m-d')
                    ];
                }
            }
        }
        return $links;
    }

    protected static function writeSitemap(array $links): void {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($links as $l) {
            $xml .= "  <url><loc>".htmlspecialchars($l['href'])."</loc></url>\n";
        }
        file_put_contents(self::$rootPath . '/sitemap.xml', $xml . "</urlset>");
    }

    protected static function writeRor(array $links): void {
        $xml = "<rss xmlns:ror=\"http://rorweb.com/0.1/\" version=\"2.0\">\n<channel>\n";
        foreach ($links as $l) {
            $xml .= "  <item><title>".htmlspecialchars($l['title'])."</title><link>".htmlspecialchars($l['href'])."</link></item>\n";
        }
        file_put_contents(self::$rootPath . '/ror.xml', $xml . "</channel>\n</rss>");
    }

    /* --- THEME HELPER STUBS (REQUIRED BY THEMES.PHP) --- */
    public static function auto_generate_meta(): array { 
        return [
            'description' => 'Chaos CMS Overhaul',
            'ai_txt'  => '<link rel="help" type="text/plain" href="/llms.txt">',
            'ai_json' => '<link rel="alternate" type="application/json" href="/llm.json">'
        ]; 
    }
    public static function auto_generate_opengraph(): string { return ''; }
    public static function auto_generate_twitter_card(): string { return ''; }
    public static function auto_generate_canonical(): string { 
        return '<link rel="canonical" href="'.htmlspecialchars(self::detectBaseUrl().($_SERVER['REQUEST_URI'] ?? '')).'">'; 
    }
    public static function auto_generate_schema($t = null): string { return ''; }
}

/* --- PROCEDURAL HELPERS --- */
function seo_build(?string $theme = null): void {
    seo::run($theme ?? 'default');
}
function seo_generate(?string $theme = null): void {
    if ($theme) seo::run($theme);
}
