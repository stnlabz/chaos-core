<?php
declare(strict_types=1);

class version
{
    public static function current(): string
    {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $path    = $docroot . '/app/data/version.json';

        if (!is_file($path)) {
            return 'unknown';
        }

        $raw = (string) @file_get_contents($path);
        $j   = json_decode($raw, true);

        if (!is_array($j)) {
            return 'unknown';
        }

        $v = (string) ($j['version'] ?? '');
        return $v !== '' ? $v : 'unknown';
    }

    protected static function latest_url(): string
    {
        return 'https://version.chaoscms.org/db/version.json';
    }

    /**
     * @return array<string,string>
     */
    public static function get_status(): array
    {
        $current = self::current();
        $url     = self::latest_url();

        // Cache file in /app/data
        $docroot    = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $cachePath  = $docroot . '/app/data/version_cache.json';
        $ttl        = 3600; // 1 hour

        // 1) Cache
        if (is_file($cachePath)) {
            $raw = (string) @file_get_contents($cachePath);
            $j   = json_decode($raw, true);

            if (is_array($j)) {
                $cachedAt = (int) ($j['cached_at'] ?? 0);
                if ($cachedAt > 0 && (time() - $cachedAt) < $ttl) {
                    $latest   = (string) ($j['latest'] ?? 'unknown');
                    $released = (string) ($j['released'] ?? '');
                    $notes    = (string) ($j['notes'] ?? '');

                    return [
                        'current'  => $current,
                        'latest'   => $latest,
                        'released' => $released,
                        'notes'    => $notes,
                        'status'   => self::compare($current, $latest),
                    ];
                }
            }
        }

        // 2) Remote fetch (short timeout)
        $ctx = stream_context_create([
            'http'  => ['timeout' => 3],
            'https' => ['timeout' => 3],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return [
                'current'  => $current,
                'latest'   => 'unknown',
                'released' => '',
                'notes'    => '',
                'status'   => 'unknown',
            ];
        }

        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return [
                'current'  => $current,
                'latest'   => 'unknown',
                'released' => '',
                'notes'    => '',
                'status'   => 'unknown',
            ];
        }

        $latest   = (string) ($j['version'] ?? '');
        $released = (string) ($j['released'] ?? '');
        $notes    = (string) ($j['notes'] ?? '');

        if ($latest === '') {
            $latest = 'unknown';
        }

        // 3) Write cache (best effort)
        @file_put_contents($cachePath, json_encode([
            'cached_at' => time(),
            'latest'    => $latest,
            'released'  => $released,
            'notes'     => $notes,
        ], JSON_PRETTY_PRINT));

        return [
            'current'  => $current,
            'latest'   => $latest,
            'released' => $released,
            'notes'    => $notes,
            'status'   => self::compare($current, $latest),
        ];
    }

    protected static function compare(string $current, string $latest): string
    {
        if ($current === 'unknown' || $latest === 'unknown') {
            return 'unknown';
        }

        $cmp = version_compare($current, $latest);
        if ($cmp === 0) return 'up_to_date';
        if ($cmp < 0)  return 'update_available';
        return 'ahead';
    }
}

