<?php

declare(strict_types=1);

/**
 * Chaos CMS - Core SEO (FULLY AUTOMATIC)
 * Now includes AI Bridge integration for unified discovery.
 */

class seo
{
    protected static string $rootPath = '';
    protected static string $baseUrl  = '';
    protected static string $hashPath = '';
    protected static string $siteName = 'Website';
    protected static string $theme = '';
    
    /** @var array<string,mixed> */
    protected static array $siteData = [];

    /**
     * Surfaced Method: Returns raw site URL string for graph injection.
     */
    public static function get_BaseUrl(): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    /**
     * Entry point - auto-generates all SEO and AI manifests.
     */
    public static function run(string $theme): void
    {
        try {
            self::$rootPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
            self::$baseUrl  = self::detectBaseUrl();
            self::$theme    = $theme;

            self::$hashPath = defined('SEO_HASH_PATH') 
                ? (string) SEO_HASH_PATH 
                : dirname(__DIR__) . '/data/seo_hash.json';

            self::discoverSiteData();

            $themeDir = self::$rootPath . '/public/themes/' . $theme;
            if (!is_dir($themeDir)) {
                self::log('Theme directory not found: ' . $theme);
                return;
            }

            $navFile = self::findNavFile($themeDir);
            if ($navFile === null) {
                self::log('No nav file found in theme: ' . $theme);
                return;
            }

            $html = @file_get_contents($navFile);
            if ($html === false || $html === '') {
                self::log('Could not read nav file: ' . $navFile);
                return;
            }

            // Unified Discovery: Extraction + Database Modules
            $navLinks = self::extractLinks($html, self::$baseUrl);
            $moduleLinks = self::discover_modules();
            $allLinks = array_merge($navLinks, $moduleLinks);
            
            if (empty($allLinks)) {
                self::log('No internal links found for indexing');
                return;
            }

            // Change Detection for entire manifest set
            if (!self::needsRegeneration($allLinks)) {
                return; 
            }

            // Generate Human SEO
            $sitemapOk = self::writeSitemap(self::$rootPath, $allLinks);
            $rorOk     = self::writeRor(self::$rootPath, $allLinks);
            $robotsOk  = self::writeRobots(self::$rootPath);

            // Generate AI Bridge Manifests
            self::generate_ai_manifests($allLinks);

            if ($sitemapOk && $rorOk && $robotsOk) {
                self::writeHash($allLinks);
                self::log('SEO and AI files generated successfully');
            } else {
                self::log('SEO generation completed with errors');
            }

        } catch (\Throwable $e) {
            self::log('SEO generation failed: ' . $e->getMessage());
        }
    }

    /**
     * AI Bridge: Discovers unknown module slugs from DB.
     */
    protected static function discover_modules(): array
    {
        global $db;
        $links = [];
        if (isset($db) && $db instanceof db) {
            $mods = $db->fetch_all("SELECT title, slug FROM modules");
            if (is_array($mods)) {
                foreach ($mods as $m) {
                    $url = rtrim(self::$baseUrl, '/') . "/products/view/" . ($m['slug'] ?? '');
                    $links[] = [
                        'title'      => (string)($m['title'] ?? 'Module'),
                        'href'       => $url,
                        'updated'    => gmdate('c'),
                        'priority'   => 0.7,
                        'changefreq' => 'weekly'
                    ];
                }
            }
        }
        return $links;
    }

    /**
     * AI Bridge: Generates llm.json and llms.txt.
     */
    protected static function generate_ai_manifests(array $links): void
    {
        $aiData = [];
        foreach ($links as $l) {
            $aiData[] = [
                'title' => $l['title'],
                'url'   => $l['href'],
                'brief' => self::crawl_brief($l['href'])
            ];
        }

        file_put_contents(self::$rootPath . '/llm.json', json_encode(['index' => $aiData], JSON_PRETTY_PRINT));
        
        $txt = "# AI Index" . PHP_EOL . PHP_EOL;
        foreach ($aiData as $d) {
            $txt .= "## {$d['title']}" . PHP_EOL . "- URL: {$d['url']}" . PHP_EOL . "- Brief: {$d['brief']}" . PHP_EOL . PHP_EOL;
        }
        file_put_contents(self::$rootPath . '/llms.txt', $txt);
    }

    /**
     * AI Bridge: Crawls page content for brief description.
     */
    protected static function crawl_brief(string $url): string
    {
        $ctx = stream_context_create(["http" => ["timeout" => 2]]);
        $html = @file_get_contents($url, false, $ctx);
        if ($html && preg_match('/<p>(.*?)<\/p>/s', $html, $matches)) {
            return substr(strip_tags($matches[1]), 0, 180) . '...';
        }
        return "Chaos CMS Module Component.";
    }

    protected static function discoverSiteData(): void
    {
        global $db;
        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='site_name' LIMIT 1");
            if (is_array($row) && isset($row['value']) && trim((string)$row['value']) !== '') {
                self::$siteName = trim((string)$row['value']);
            }
        }

        $siteDesc = '';
        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='site_description' LIMIT 1");
            if (is_array($row) && isset($row['value'])) {
                $siteDesc = trim((string)$row['value']);
            }
        }

        self::$siteData = [
            'site_name' => self::$siteName,
            'site_description' => $siteDesc !== '' ? $siteDesc : 'Powered by Chaos CMS',
            'default_image' => self::discoverDefaultImage(),
            'logo' => self::discoverLogo(),
            'social_profiles' => self::discoverSocialProfiles(),
        ];
    }

    protected static function discoverLogo(): string
    {
        $candidates = ['/public/themes/'.self::$theme.'/assets/images/logo.png', '/public/assets/images/logo.png'];
        foreach ($candidates as $path) {
            if (is_file(self::$rootPath . $path)) return self::$baseUrl . $path;
        }
        return '';
    }

    protected static function discoverDefaultImage(): string
    {
        $candidates = ['/public/themes/'.self::$theme.'/assets/images/og-default.jpg', '/public/assets/images/default.jpg'];
        foreach ($candidates as $path) {
            if (is_file(self::$rootPath . $path)) return self::$baseUrl . $path;
        }
        return self::discoverLogo();
    }

    protected static function discoverSocialProfiles(): array
    {
        global $db;
        $profiles = [];
        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='social_links' LIMIT 1");
            if (is_array($row) && isset($row['value'])) {
                $decoded = json_decode((string)$row['value'], true);
                if (is_array($decoded)) $profiles = array_values($decoded);
            }
        }
        return $profiles;
    }

    protected static function detectBaseUrl(): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    protected static function findNavFile(string $themeDir): ?string
    {
        $priorityFiles = [$themeDir . '/nav.php', $themeDir . '/header.php'];
        foreach ($priorityFiles as $file) {
            if (is_file($file)) return $file;
        }
        return null;
    }

    protected static function extractLinks(string $html, string $base): array
    {
        $links = [];
        if (preg_match_all('/<a\s+[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = trim((string)$m[2]);
                if ($href === '' || $href[0] !== '/' || strpos($href, '#') !== false) continue;
                $text = trim(strip_tags((string)$m[3])) ?: $href;
                $full = rtrim($base, '/') . $href;
                $links[$full] = [
                    'title'      => $text,
                    'href'       => $full,
                    'updated'    => gmdate('c'),
                    'priority'   => ($href === '/' ? 1.0 : 0.5),
                    'changefreq' => 'weekly',
                ];
            }
        }
        return array_values($links);
    }

    protected static function needsRegeneration(array $links): bool
    {
        if (!is_file(self::$hashPath)) return true;
        $current = sha1((string)json_encode($links));
        $existing = @file_get_contents(self::$hashPath);
        return trim((string)$existing) !== $current;
    }

    protected static function writeHash(array $links): void
    {
        $hash = sha1((string)json_encode($links));
        @file_put_contents(self::$hashPath, $hash);
    }

    protected static function writeSitemap(string $root, array $links): bool
    {
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($links as $l) {
            $xml .= "  <url><loc>".self::xmlSafe($l['href'])."</loc><lastmod>{$l['updated']}</lastmod><changefreq>{$l['changefreq']}</changefreq><priority>".number_format($l['priority'], 1)."</priority></url>\n";
        }
        $xml .= "</urlset>";
        return @file_put_contents($root . '/sitemap.xml', $xml) !== false;
    }

    protected static function writeRor(string $root, array $links): bool
    {
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss xmlns:ror=\"http://rorweb.com/0.1/\" version=\"2.0\">\n<channel><title>SiteMap</title>\n";
        foreach ($links as $l) {
            $xml .= "  <item><title>".self::xmlSafe($l['title'])."</title><link>".self::xmlSafe($l['href'])."</link><ror:updated>{$l['updated']}</ror:updated><ror:updatePeriod>{$l['changefreq']}</ror:updatePeriod><ror:resourceOf>sitemap</ror:resourceOf></item>\n";
        }
        $xml .= "</channel>\n</rss>";
        return @file_put_contents($root . '/ror.xml', $xml) !== false;
    }

    protected static function writeRobots(string $root): bool
    {
        $robots = "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: ".self::$baseUrl."/sitemap.xml\n";
        return @file_put_contents($root . '/robots.txt', $robots) !== false;
    }

    protected static function xmlSafe(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function auto_generate_meta(array $page = []): array
    {
        if (empty(self::$siteData)) self::discoverSiteData();
        return [
            'description' => (string)($page['description'] ?? self::$siteData['site_description']),
            'keywords'    => is_array($page['keywords'] ?? null) ? implode(', ', $page['keywords']) : (string)($page['keywords'] ?? ''),
            'robots'      => (string)($page['robots'] ?? 'index, follow')
        ];
    }

    public static function auto_generate_opengraph(array $page = []): string
    {
        if (empty(self::$siteData)) self::discoverSiteData();
        $title = htmlspecialchars((string)($page['title'] ?? self::$siteData['site_name']));
        $img   = htmlspecialchars((string)($page['image'] ?? self::$siteData['default_image']));
        return "<meta property=\"og:title\" content=\"$title\"><meta property=\"og:image\" content=\"$img\">";
    }

    public static function auto_generate_twitter_card(array $page = []): string
    {
        if (empty(self::$siteData)) self::discoverSiteData();
        $title = htmlspecialchars((string)($page['title'] ?? self::$siteData['site_name']));
        return "<meta name=\"twitter:card\" content=\"summary_large_image\"><meta name=\"twitter:title\" content=\"$title\">";
    }

    public static function auto_generate_canonical(?string $url = null): string
    {
        $url = htmlspecialchars($url ?? self::currentUrl());
        return "<link rel=\"canonical\" href=\"$url\">";
    }

    public static function auto_generate_schema(string $type, array $data = []): string
    {
        $json = ['@context' => 'https://schema.org', '@type' => ucfirst($type), 'url' => self::$baseUrl];
        return '<script type="application/ld+json">'.json_encode($json, JSON_UNESCAPED_SLASHES).'</script>';
    }

    protected static function currentUrl(): string
    {
        return (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    protected static function log(string $msg): void
    {
        error_log('[' . gmdate('Y-m-d H:i:s') . 'Z] [SEO-AI] ' . $msg . PHP_EOL);
    }
}
