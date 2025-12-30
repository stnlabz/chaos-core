<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core SEO
 *
 * Core-only:
 *  - Scan active theme nav for internal links (href starting with "/").
 *  - Generate:
 *      /sitemap.xml
 *      /ror.xml
 *  - Track changes via:
 *      /app/data/seo.hash
 *
 * No JSON nav, no plugins, no admin dependency.
 */
class seo
{
    protected static string $rootPath = '';
    protected static string $baseUrl  = '';
    protected static string $hashPath = '';

    /**
     * Entry point.
     *
     * Call from bootstrap AFTER $theme is set:
     *
     *   require_once __DIR__ . '/core/seo.php';
     *   if (php_sapi_name() !== 'cli') {
     *       seo::run($theme);
     *   }
     *
     * @param string $theme
     * @return void
     */
    public static function run(string $theme): void
    {
        self::$rootPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        self::$baseUrl  = self::detectBaseUrl();

        // Hash lives in /app/data/seo.hash
        self::$hashPath = dirname(__DIR__) . '/data/seo_hash.json';

        $themeDir = self::$rootPath . '/public/themes/' . $theme;
        if (!is_dir($themeDir)) {
            return;
        }

        $navFile = self::findNavFile($themeDir);
        if ($navFile === null) {
            return;
        }

        $html = file_get_contents($navFile);
        if ($html === false || $html === '') {
            return;
        }

        $links = self::extractLinks($html, self::$baseUrl);
        if (empty($links)) {
            return;
        }

        if (!self::needsRegeneration($links)) {
            return;
        }

        self::writeSitemap(self::$rootPath, $links);
        self::writeRor(self::$rootPath, $links);
        self::writeHash($links);
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
     * Find a theme file that clearly contains nav markup.
     *
     * @param string $themeDir
     * @return string|null
     */
    protected static function findNavFile(string $themeDir): ?string
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $themeDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $html = file_get_contents($path);
            if ($html === false || $html === '') {
                continue;
            }

            if (
                strpos($html, '<nav') !== false ||
                strpos($html, 'navbar-nav') !== false ||
                preg_match('/<a[^>]+href=("|\')\//i', $html)
            ) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Extract internal links from nav HTML.
     *
     * Only hrefs that:
     *  - start with "/"
     * are included.
     *
     * @param string $html
     * @param string $base
     * @return array<int,array{title:string,href:string,updated:string}>
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
            if ($href === '' || $href[0] !== '/') {
                continue;
            }

            $text = trim(strip_tags((string) $m[3]));
            if ($text === '') {
                $text = $href;
            }

            $full = rtrim($base, '/') . $href;

            $links[] = [
                'title'   => $text,
                'href'    => $full,
                'updated' => gmdate('c'),
            ];
        }

        return $links;
    }

    /**
     * Decide if we need to regenerate based on hash of links.
     *
     * @param array<int,array{title:string,href:string,updated:string}> $links
     * @return bool
     */
    protected static function needsRegeneration(array $links): bool
    {
        if (!is_file(self::$hashPath)) {
            return true;
        }

        $current  = sha1((string) json_encode($links));
        $existing = trim((string) file_get_contents(self::$hashPath));

        return $existing !== $current;
    }

    /**
     * Write hash to /app/data/seo.hash
     *
     * @param array<int,array{title:string,href:string,updated:string}> $links
     * @return void
     */
    protected static function writeHash(array $links): void
    {
        $dir = dirname(self::$hashPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $hash = sha1((string) json_encode($links));
        file_put_contents(self::$hashPath, $hash);
    }

    /**
     * Generate /sitemap.xml in site root.
     *
     * @param string $root
     * @param array<int,array{title:string,href:string,updated:string}> $links
     * @return void
     */
    protected static function writeSitemap(string $root, array $links): void
    {
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($links as $l) {
            $loc     = self::xmlSafe($l['href']);
            $lastmod = $l['updated'];

            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.5</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>";

        file_put_contents($root . '/sitemap.xml', $xml);
    }

    /**
     * Generate /ror.xml in site root.
     *
     * @param string $root
     * @param array<int,array{title:string,href:string,updated:string}> $links
     * @return void
     */
    protected static function writeRor(string $root, array $links): void
    {
        $xml  = "<rss xmlns:ror=\"http://rorweb.com/0.1/\" version=\"2.0\">\n";
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
            $xml .= "    <ror:updatePeriod>weekly</ror:updatePeriod>\n";
            $xml .= "    <ror:resourceOf>sitemap</ror:resourceOf>\n";
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>";

        file_put_contents($root . '/ror.xml', $xml);
    }

    /**
     * Minimal XML-safe escape:
     *  - Escape & and <
     *  - DO NOT escape ">" so ">Docs" stays ">Docs"
     *
     * @param string $text
     * @return string
     */
    protected static function xmlSafe(string $text): string
    {
        return str_replace(
            ['&', '<'],
            ['&amp;', '&lt;'],
            $text
        );
    }
}

/**
 * Callable SEO entrypoints (KISS).
 * Some bootstrap code expects one of these.
 */

/**
 * @param string $theme
 * @return void
 */
function seo_build(?string $theme = null): void
{
    // If theme not provided, try to fetch it from settings (DB), else fallback.
    if ($theme === null || $theme === '') {
        $theme = 'default';

        /** @var mixed $db */
        global $db;

        if (isset($db) && $db instanceof db) {
            $row = $db->fetch("SELECT value FROM settings WHERE name='site_theme' LIMIT 1");
            if (is_array($row) && isset($row['value']) && (string)$row['value'] !== '') {
                $theme = (string) $row['value'];
            }
        }
    }

    // Run the SEO builder (class-based)
    if (class_exists('seo')) {
        seo::run((string) $theme);
        return;
    }

    // If class missing, fail loudly but safely.
    http_response_code(500);
    echo '<div class="container my-4"><div class="alert alert-danger">SEO core missing: class seo not found.</div></div>';
}

/**
 * @param string $theme
 * @return void
 */
function seo_generate(?string $theme = null): void
{
    if ($theme === null || $theme === '') {
        return;
    }
    seo::run($theme);
}

