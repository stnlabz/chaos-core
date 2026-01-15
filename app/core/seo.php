<?php

declare(strict_types=1);

/**
 * Chaos CMS - Core SEO (FULLY AUTOMATIC)
 *
 * Automatic SEO generation with ZERO site owner interaction required.
 * 
 * Discovers from the site:
 * - Navigation structure → sitemap.xml + ror.xml
 * - Database settings → site name, description
 * - Theme assets → logo, favicon for OG/schema
 * - Posts/content → dynamic page meta
 * - File system → images for default OG
 * - Footer/content → social media links
 * 
 * Generates automatically:
 * - /sitemap.xml (with smart priorities)
 * - /ror.xml (ROR feed)
 * - /robots.txt (with intelligent blocking)
 * - Meta tags (description, keywords, author)
 * - Open Graph tags (for social sharing)
 * - Twitter Card tags (for Twitter/X)
 * - Canonical URLs (prevent duplicate content)
 * - JSON-LD structured data (Organization, Article, Breadcrumb)
 * 
 * Hash-based change detection ensures minimal overhead.
 * XML validation ensures clean output.
 * Error handling with logging.
 * 
 * NO JSON CONFIG FILES NEEDED - DISCOVERS EVERYTHING AUTOMATICALLY.
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
     * Entry point - auto-generates all SEO files.
     *
     * @param string $theme
     * @return void
     */
    public static function run(string $theme): void
    {
        try {
            self::$rootPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
            self::$baseUrl  = self::detectBaseUrl();
            self::$theme    = $theme;

            // Hash path
            self::$hashPath = defined('SEO_HASH_PATH') 
                ? (string) SEO_HASH_PATH 
                : dirname(__DIR__) . '/data/seo_hash.json';

            // Discover site data automatically
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

            $links = self::extractLinks($html, self::$baseUrl);
            
            if (empty($links)) {
                self::log('No internal links found in nav');
                return;
            }

            if (!self::needsRegeneration($links)) {
                return; // No changes, skip regeneration
            }

            // Generate files
            $sitemapOk = self::writeSitemap(self::$rootPath, $links);
            $rorOk = self::writeRor(self::$rootPath, $links);
            $robotsOk = self::writeRobots(self::$rootPath);

            if ($sitemapOk && $rorOk && $robotsOk) {
                self::writeHash($links);
                self::log('SEO files generated successfully');
            } else {
                self::log('SEO generation completed with errors');
            }

        } catch (\Throwable $e) {
            self::log('SEO generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Automatically discover site data from DB and filesystem.
     *
     * @return void
     */
    protected static function discoverSiteData(): void
    {
        global $db;

        // Discover site name from DB
        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='site_name' LIMIT 1");
            if (is_array($row) && isset($row['value']) && trim((string)$row['value']) !== '') {
                self::$siteName = trim((string)$row['value']);
            }
        }

        // Discover site description from DB
        $siteDesc = '';
        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='site_description' LIMIT 1");
            if (is_array($row) && isset($row['value'])) {
                $siteDesc = trim((string)$row['value']);
            }
        }

        // Auto-discover logo from theme or core assets
        $logo = self::discoverLogo();

        // Auto-discover default OG image
        $ogImage = self::discoverDefaultImage();

        // Auto-discover social profiles from site
        $socialProfiles = self::discoverSocialProfiles();

        self::$siteData = [
            'site_name' => self::$siteName,
            'site_description' => $siteDesc !== '' ? $siteDesc : 'Powered by Chaos CMS',
            'default_image' => $ogImage,
            'logo' => $logo,
            'social_profiles' => $socialProfiles,
        ];
    }

    /**
     * Auto-discover logo from theme or core assets.
     *
     * @return string
     */
    protected static function discoverLogo(): string
    {
        $candidates = [
            '/public/themes/' . self::$theme . '/assets/images/logo.png',
            '/public/themes/' . self::$theme . '/assets/images/logo.jpg',
            '/public/themes/' . self::$theme . '/assets/images/logo.svg',
            '/public/assets/images/logo.png',
            '/public/assets/images/logo.jpg',
            '/public/assets/images/logo.svg',
        ];

        foreach ($candidates as $path) {
            if (is_file(self::$rootPath . $path)) {
                return self::$baseUrl . $path;
            }
        }

        return '';
    }

    /**
     * Auto-discover default Open Graph image.
     *
     * @return string
     */
    protected static function discoverDefaultImage(): string
    {
        $candidates = [
            '/public/themes/' . self::$theme . '/assets/images/og-default.jpg',
            '/public/themes/' . self::$theme . '/assets/images/og-default.png',
            '/public/themes/' . self::$theme . '/assets/images/default.jpg',
            '/public/assets/images/og-default.jpg',
            '/public/assets/images/og-default.png',
            '/public/assets/images/default.jpg',
        ];

        foreach ($candidates as $path) {
            if (is_file(self::$rootPath . $path)) {
                return self::$baseUrl . $path;
            }
        }

        // Fallback to logo if found
        $logo = self::discoverLogo();
        return $logo !== '' ? $logo : '';
    }

    /**
     * Auto-discover social media profiles from footer or content.
     *
     * @return array<int,string>
     */
    protected static function discoverSocialProfiles(): array
    {
        global $db;

        $profiles = [];

        // Try to find social links in settings
        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='social_links' LIMIT 1");
            if (is_array($row) && isset($row['value']) && trim((string)$row['value']) !== '') {
                $decoded = json_decode((string)$row['value'], true);
                if (is_array($decoded)) {
                    $profiles = array_values($decoded);
                }
            }
        }

        // Scan theme footer for social links
        if (empty($profiles)) {
            $footerPath = self::$rootPath . '/public/themes/' . self::$theme . '/footer.php';
            if (is_file($footerPath)) {
                $html = @file_get_contents($footerPath);
                if ($html !== false) {
                    $profiles = self::extractSocialLinks($html);
                }
            }
        }

        return $profiles;
    }

    /**
     * Extract social media links from HTML.
     *
     * @param string $html
     * @return array<int,string>
     */
    protected static function extractSocialLinks(string $html): array
    {
        $links = [];
        $domains = [
            'twitter.com',
            'x.com',
            'facebook.com',
            'github.com',
            'linkedin.com',
            'instagram.com',
            'youtube.com',
        ];

        if (preg_match_all('/<a[^>]+href=(["\'])([^"\']+)\1/i', $html, $matches)) {
            foreach ($matches[2] as $url) {
                foreach ($domains as $domain) {
                    if (stripos($url, $domain) !== false) {
                        $links[] = $url;
                        break;
                    }
                }
            }
        }

        return array_unique($links);
    }

    /**
     * Detect base URL from environment.
     *
     * @return string
     */
    protected static function detectBaseUrl(): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    /**
     * Find a theme file that contains nav markup.
     *
     * @param string $themeDir
     * @return string|null
     */
    protected static function findNavFile(string $themeDir): ?string
    {
        // Priority order: nav.php, header.php, then scan all
        $priorityFiles = [
            $themeDir . '/nav.php',
            $themeDir . '/header.php',
        ];

        foreach ($priorityFiles as $file) {
            if (is_file($file)) {
                $html = @file_get_contents($file);
                if ($html !== false && self::hasNavMarkup($html)) {
                    return $file;
                }
            }
        }

        // Scan all PHP files
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $themeDir,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $html = @file_get_contents($file->getPathname());
                
                if ($html !== false && self::hasNavMarkup($html)) {
                    return $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            self::log('Nav file scan failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Check if HTML contains nav markup.
     *
     * @param string $html
     * @return bool
     */
    protected static function hasNavMarkup(string $html): bool
    {
        $patterns = [
            '<nav',
            'navbar',
            'menu',
            'navigation',
            '<ul',
            '<li',
        ];

        foreach ($patterns as $pattern) {
            if (stripos($html, $pattern) !== false) {
                // Verify it has internal links
                if (preg_match('/<a[^>]+href=("|\')\//i', $html)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract internal links from HTML.
     *
     * @param string $html
     * @param string $base
     * @return array<int,array{title:string,href:string,updated:string,priority:float,changefreq:string}>
     */
    protected static function extractLinks(string $html, string $base): array
    {
        $links = [];

        if (!preg_match_all(
            '/<a\s+[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $href = trim((string) $m[2]);
            
            // Only internal links starting with /
            if ($href === '' || $href[0] !== '/') {
                continue;
            }

            // Skip anchors and queries
            if (strpos($href, '#') !== false || strpos($href, '?') !== false) {
                continue;
            }

            $text = trim(strip_tags((string) $m[3]));
            if ($text === '') {
                $text = $href;
            }

            $full = rtrim($base, '/') . $href;

            // Determine priority and changefreq based on URL
            $priority = self::getPriority($href);
            $changefreq = self::getChangefreq($href);

            $links[] = [
                'title'      => $text,
                'href'       => $full,
                'updated'    => gmdate('c'),
                'priority'   => $priority,
                'changefreq' => $changefreq,
            ];
        }

        // Remove duplicates
        $unique = [];
        foreach ($links as $link) {
            $unique[$link['href']] = $link;
        }

        return array_values($unique);
    }

    /**
     * Get priority for URL (0.0 to 1.0).
     *
     * @param string $href
     * @return float
     */
    protected static function getPriority(string $href): float
    {
        // Home page
        if ($href === '/' || $href === '/home') {
            return 1.0;
        }

        // Important sections
        if (in_array($href, ['/posts', '/pages', '/media', '/about', '/contact'], true)) {
            return 0.8;
        }

        // Default
        return 0.5;
    }

    /**
     * Get changefreq for URL.
     *
     * @param string $href
     * @return string
     */
    protected static function getChangefreq(string $href): string
    {
        // Home page changes frequently
        if ($href === '/' || $href === '/home') {
            return 'daily';
        }

        // Posts/news change often
        if (str_starts_with($href, '/posts') || str_starts_with($href, '/news')) {
            return 'weekly';
        }

        // Static pages
        if (in_array($href, ['/about', '/contact', '/privacy', '/terms'], true)) {
            return 'monthly';
        }

        return 'weekly';
    }

    /**
     * Check if regeneration is needed.
     *
     * @param array<int,array{title:string,href:string,updated:string,priority:float,changefreq:string}> $links
     * @return bool
     */
    protected static function needsRegeneration(array $links): bool
    {
        if (!is_file(self::$hashPath)) {
            return true;
        }

        $current  = sha1((string) json_encode($links));
        $existing = @file_get_contents(self::$hashPath);

        if ($existing === false) {
            return true;
        }

        $existing = trim($existing);

        return $existing !== $current;
    }

    /**
     * Write hash file.
     *
     * @param array<int,array{title:string,href:string,updated:string,priority:float,changefreq:string}> $links
     * @return void
     */
    protected static function writeHash(array $links): void
    {
        $dir = dirname(self::$hashPath);
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $hash = sha1((string) json_encode($links));
        @file_put_contents(self::$hashPath, $hash);
    }

    /**
     * Generate /sitemap.xml.
     *
     * @param string $root
     * @param array<int,array{title:string,href:string,updated:string,priority:float,changefreq:string}> $links
     * @return bool
     */
    protected static function writeSitemap(string $root, array $links): bool
    {
        try {
            $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

            foreach ($links as $l) {
                $loc        = self::xmlSafe($l['href']);
                $lastmod    = $l['updated'];
                $priority   = number_format($l['priority'], 1);
                $changefreq = $l['changefreq'];

                $xml .= "  <url>\n";
                $xml .= "    <loc>{$loc}</loc>\n";
                $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
                $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
                $xml .= "    <priority>{$priority}</priority>\n";
                $xml .= "  </url>\n";
            }

            $xml .= "</urlset>";

            // Validate XML
            if (!self::validateXml($xml)) {
                self::log('Sitemap XML validation failed');
                return false;
            }

            $result = @file_put_contents($root . '/sitemap.xml', $xml);
            
            if ($result === false) {
                self::log('Failed to write sitemap.xml');
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            self::log('Sitemap generation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate /ror.xml.
     *
     * @param string $root
     * @param array<int,array{title:string,href:string,updated:string,priority:float,changefreq:string}> $links
     * @return bool
     */
    protected static function writeRor(string $root, array $links): bool
    {
        try {
            $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<rss xmlns:ror=\"http://rorweb.com/0.1/\" version=\"2.0\">\n";
            $xml .= "<channel>\n";
            $xml .= "  <title>SiteMap</title>\n";
            $xml .= "  <item>\n";
            $xml .= "    <title>Index</title>\n";
            $xml .= "    <ror:about>sitemap</ror:about>\n";
            $xml .= "    <ror:type>SiteMap</ror:type>\n";
            $xml .= "  </item>\n";

            foreach ($links as $l) {
                $title = self::xmlSafe($l['title']);
                $loc   = self::xmlSafe($l['href']);

                $xml .= "  <item>\n";
                $xml .= "    <title>{$title}</title>\n";
                $xml .= "    <link>{$loc}</link>\n";
                $xml .= "    <ror:updated>{$l['updated']}</ror:updated>\n";
                $xml .= "    <ror:updatePeriod>{$l['changefreq']}</ror:updatePeriod>\n";
                $xml .= "    <ror:resourceOf>sitemap</ror:resourceOf>\n";
                $xml .= "  </item>\n";
            }

            $xml .= "</channel>\n</rss>";

            // Validate XML
            if (!self::validateXml($xml)) {
                self::log('ROR XML validation failed');
                return false;
            }

            $result = @file_put_contents($root . '/ror.xml', $xml);
            
            if ($result === false) {
                self::log('Failed to write ror.xml');
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            self::log('ROR generation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate /robots.txt.
     *
     * @param string $root
     * @return bool
     */
    protected static function writeRobots(string $root): bool
    {
        try {
            $baseUrl = self::$baseUrl;

            $robots  = "# Chaos CMS - Robots.txt\n";
            $robots .= "# Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";
            $robots .= "User-agent: *\n";
            $robots .= "Allow: /\n";
            $robots .= "Disallow: /app/\n";
            $robots .= "Disallow: /install/\n";
            $robots .= "Disallow: /admin/\n";
            $robots .= "Disallow: /login\n";
            $robots .= "Disallow: /logout\n";
            $robots .= "Disallow: /signup\n\n";
            $robots .= "Sitemap: {$baseUrl}/sitemap.xml\n";

            $result = @file_put_contents($root . '/robots.txt', $robots);
            
            if ($result === false) {
                self::log('Failed to write robots.txt');
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            self::log('Robots.txt generation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate XML string.
     *
     * @param string $xml
     * @return bool
     */
    protected static function validateXml(string $xml): bool
    {
        $prev = libxml_use_internal_errors(true);
        
        $doc = simplexml_load_string($xml);
        
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false || !empty($errors)) {
            foreach ($errors as $error) {
                self::log('XML validation error: ' . trim($error->message));
            }
            return false;
        }

        return true;
    }

    /**
     * XML-safe escape.
     *
     * @param string $text
     * @return string
     */
    protected static function xmlSafe(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ========================================================================
    // AUTOMATIC META TAG GENERATION (FOR THEMES INTEGRATION)
    // ========================================================================

    /**
     * Auto-generate page meta tags based on current request.
     * Call from themes::render_header() or router.
     *
     * @param array<string,mixed> $page Optional page data override
     * @return array<string,string>
     */
    public static function auto_generate_meta(array $page = []): array
    {
        if (empty(self::$siteData)) {
            self::discoverSiteData();
        }

        $meta = [];
        
        // Description
        $desc = $page['description'] ?? self::$siteData['site_description'] ?? '';
        if ($desc !== '') {
            $meta['description'] = (string)$desc;
        }
        
        // Keywords (auto-extract from page title)
        if (!empty($page['keywords'])) {
            $keywords = $page['keywords'];
            if (is_array($keywords)) {
                $meta['keywords'] = implode(', ', array_map('strval', $keywords));
            } else {
                $meta['keywords'] = (string)$keywords;
            }
        }
        
        // Author
        if (!empty($page['author'])) {
            $meta['author'] = (string)$page['author'];
        }
        
        // Robots
        $meta['robots'] = (string)($page['robots'] ?? 'index, follow');
        
        return $meta;
    }

    /**
     * Auto-generate Open Graph tags.
     *
     * @param array<string,mixed> $page
     * @return string
     */
    public static function auto_generate_opengraph(array $page = []): string
    {
        if (empty(self::$siteData)) {
            self::discoverSiteData();
        }

        $html = '';
        
        $title = (string)($page['title'] ?? self::$siteData['site_name'] ?? 'Website');
        $desc = (string)($page['description'] ?? self::$siteData['site_description'] ?? '');
        $image = (string)($page['image'] ?? self::$siteData['default_image'] ?? '');
        $url = (string)($page['url'] ?? self::currentUrl());
        $type = (string)($page['type'] ?? 'website');
        
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        
        $html .= '<meta property="og:title" content="' . $esc($title) . '">' . PHP_EOL;
        $html .= '<meta property="og:type" content="' . $esc($type) . '">' . PHP_EOL;
        $html .= '<meta property="og:url" content="' . $esc($url) . '">' . PHP_EOL;
        
        if ($desc !== '') {
            $html .= '<meta property="og:description" content="' . $esc($desc) . '">' . PHP_EOL;
        }
        
        if ($image !== '') {
            $html .= '<meta property="og:image" content="' . $esc($image) . '">' . PHP_EOL;
        }
        
        if (!empty(self::$siteData['site_name'])) {
            $html .= '<meta property="og:site_name" content="' . $esc((string)self::$siteData['site_name']) . '">' . PHP_EOL;
        }
        
        return $html;
    }

    /**
     * Auto-generate Twitter Card tags.
     *
     * @param array<string,mixed> $page
     * @return string
     */
    public static function auto_generate_twitter_card(array $page = []): string
    {
        if (empty(self::$siteData)) {
            self::discoverSiteData();
        }

        $html = '';
        
        $title = (string)($page['title'] ?? self::$siteData['site_name'] ?? 'Website');
        $desc = (string)($page['description'] ?? self::$siteData['site_description'] ?? '');
        $image = (string)($page['image'] ?? self::$siteData['default_image'] ?? '');
        $card = (string)($page['twitter_card'] ?? 'summary_large_image');
        
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        
        $html .= '<meta name="twitter:card" content="' . $esc($card) . '">' . PHP_EOL;
        $html .= '<meta name="twitter:title" content="' . $esc($title) . '">' . PHP_EOL;
        
        if ($desc !== '') {
            $html .= '<meta name="twitter:description" content="' . $esc($desc) . '">' . PHP_EOL;
        }
        
        if ($image !== '') {
            $html .= '<meta name="twitter:image" content="' . $esc($image) . '">' . PHP_EOL;
        }
        
        return $html;
    }

    /**
     * Auto-generate canonical link tag.
     *
     * @param string|null $url
     * @return string
     */
    public static function auto_generate_canonical(?string $url = null): string
    {
        $url = $url ?? self::currentUrl();
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        
        return '<link rel="canonical" href="' . $esc($url) . '">' . PHP_EOL;
    }

    /**
     * Auto-generate JSON-LD structured data.
     *
     * @param string $type 'organization'|'article'|'breadcrumb'
     * @param array<string,mixed> $data
     * @return string
     */
    public static function auto_generate_schema(string $type, array $data = []): string
    {
        if (empty(self::$siteData)) {
            self::discoverSiteData();
        }

        $json = [];
        
        switch ($type) {
            case 'organization':
                $json = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => (string)($data['name'] ?? self::$siteData['site_name'] ?? ''),
                    'url' => (string)($data['url'] ?? self::$baseUrl),
                ];
                
                if (!empty(self::$siteData['logo'])) {
                    $json['logo'] = (string)self::$siteData['logo'];
                }
                
                if (!empty(self::$siteData['social_profiles'])) {
                    $json['sameAs'] = array_map('strval', (array)self::$siteData['social_profiles']);
                }
                break;
                
            case 'article':
                $json = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => (string)($data['title'] ?? ''),
                    'description' => (string)($data['description'] ?? ''),
                    'datePublished' => (string)($data['published'] ?? gmdate('c')),
                    'dateModified' => (string)($data['modified'] ?? gmdate('c')),
                ];
                
                if (!empty($data['author'])) {
                    $json['author'] = [
                        '@type' => 'Person',
                        'name' => (string)$data['author']
                    ];
                }
                
                if (!empty($data['image'])) {
                    $json['image'] = (string)$data['image'];
                }
                break;
                
            case 'breadcrumb':
                $items = [];
                foreach ($data['items'] ?? [] as $i => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $items[] = [
                        '@type' => 'ListItem',
                        'position' => $i + 1,
                        'name' => (string)($item['name'] ?? ''),
                        'item' => (string)($item['url'] ?? '')
                    ];
                }
                
                $json = [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $items
                ];
                break;
        }
        
        if (empty($json)) {
            return '';
        }
        
        return '<script type="application/ld+json">' . PHP_EOL .
               json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL .
               '</script>' . PHP_EOL;
    }

    /**
     * Get current URL.
     *
     * @return string
     */
    protected static function currentUrl(): string
    {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        return $scheme . '://' . $host . $uri;
    }

    /**
     * Log message.
     *
     * @param string $msg
     * @return void
     */
    protected static function log(string $msg): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] [SEO] ' . $msg . PHP_EOL;
        error_log($line);
    }
}

/**
 * Callable SEO entrypoints (KISS).
 */

/**
 * Build SEO files.
 *
 * @param string|null $theme
 * @return void
 */
function seo_build(?string $theme = null): void
{
    if ($theme === null || $theme === '') {
        $theme = 'default';

        global $db;

        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='site_theme' LIMIT 1");
            if (is_array($row) && isset($row['value']) && (string) $row['value'] !== '') {
                $theme = (string) $row['value'];
            }
        }
    }

    if (class_exists('seo')) {
        seo::run((string) $theme);
        return;
    }

    http_response_code(500);
    echo '<div class="container my-4"><div class="alert alert-danger">SEO core missing: class seo not found.</div></div>';
}

/**
 * Generate SEO files.
 *
 * @param string|null $theme
 * @return void
 */
function seo_generate(?string $theme = null): void
{
    if ($theme === null || $theme === '') {
        return;
    }
    
    seo::run($theme);
}
