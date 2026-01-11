<?php

declare(strict_types=1);

/**
 * Chaos CMS Codex
 *
 * Core-only class used to generate API reference content into:
 *  - codex_topics
 *  - codex
 *
 * Public Codex pages should never expose internal-only methods.
 */
final class codex
{
    /**
     * Generate Codex topic intro pages and API reference entries.
     *
     * @param db $db
     * @param array<int,string>|string $scanDirs
     * @return array{topics:int,intro_created:int,intro_updated:int,entries:int,created:int,updated:int,skipped:int}
     */
    public static function generate_api_reference(db $db, $scanDirs): array
    {
        $dirs = self::normalize_scan_dirs($scanDirs);

        if ($dirs === []) {
            return self::zero_stats();
        }

        $topicMap = self::topic_map_default();

        $files = self::gather_php_files($dirs);
        if ($files === []) {
            return self::zero_stats();
        }

        $topics  = [];
        $entries = [];

        foreach ($files as $abs) {
            $topicLabel = self::classify_topic($abs, $topicMap);
            $topics[$topicLabel] = true;

            $parsed = self::parse_file_methods($abs);
            foreach ($parsed as $entry) {
                $entry['topic_label'] = $topicLabel;
                $entries[] = $entry;
            }
        }

        $topicLabels = array_keys($topics);
        sort($topicLabels);

        $topicIds = self::ensure_topics($db, $topicLabels);
        $topicsCreated = (int) ($topicIds['_created'] ?? 0);
        unset($topicIds['_created']);

        $introCreated = 0;
        $introUpdated = 0;

        foreach ($topicLabels as $label) {
            $topicId = (int) ($topicIds[$label] ?? 0);
            if ($topicId <= 0) {
                continue;
            }

            $res = self::upsert_topic_intro($db, $topicId, $label);

            if ($res === 'created') {
                $introCreated++;
            }

            if ($res === 'updated') {
                $introUpdated++;
            }
        }

        $entriesTotal = 0;
        $created      = 0;
        $updated      = 0;
        $skipped      = 0;

        foreach ($entries as $e) {
            $topicLabel = (string) ($e['topic_label'] ?? 'Core');
            $topicId    = (int) ($topicIds[$topicLabel] ?? 0);

            if ($topicId <= 0) {
                $skipped++;
                continue;
            }

            $res = self::upsert_api_entry($db, $topicId, $e);
            $entriesTotal++;

            if ($res === 'created') {
                $created++;
                continue;
            }

            if ($res === 'updated') {
                $updated++;
                continue;
            }

            $skipped++;
        }

        return [
            'topics'        => count($topicLabels),
            'intro_created' => $introCreated,
            'intro_updated' => $introUpdated,
            'entries'       => $entriesTotal,
            'created'       => $created,
            'updated'       => $updated,
            'skipped'       => $skipped,
        ];
    }

    /**
     * @param array<int,string>|string $scanDirs
     * @return array<int,string>
     */
    private static function normalize_scan_dirs($scanDirs): array
    {
        if (is_string($scanDirs) && $scanDirs !== '') {
            $scanDirs = [$scanDirs];
        }

        if (!is_array($scanDirs) || $scanDirs === []) {
            return [];
        }

        $out = [];
        foreach ($scanDirs as $d) {
            $d = trim((string) $d);
            if ($d !== '') {
                $out[] = $d;
            }
        }

        $out = array_values(array_unique($out));

        return $out;
    }

    /**
     * @return array{topics:int,intro_created:int,intro_updated:int,entries:int,created:int,updated:int,skipped:int}
     */
    private static function zero_stats(): array
    {
        return [
            'topics'        => 0,
            'intro_created' => 0,
            'intro_updated' => 0,
            'entries'       => 0,
            'created'       => 0,
            'updated'       => 0,
            'skipped'       => 0,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function topic_map_default(): array
    {
        return [
            '/app/core/db.php'       => 'Database',
            '/app/core/auth.php'     => 'Auth',
            '/app/core/mailer.php'   => 'Mailer',
            '/app/core/render.php'   => 'Rendering',
            '/app/core/seo.php'      => 'SEO',
            '/app/core/plugins.php'  => 'Plugins',
            '/app/core/utility.php'  => 'Utilities',
            '/app/core/codex.php'    => 'Codex',
            '/app/core/'             => 'Core',
            '/app/lib/'              => 'Libraries',
        ];
    }

    /**
     * @param array<int,string> $dirs
     * @return array<int,string>
     */
    private static function gather_php_files(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/');

            if (!is_dir($dir)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $f) {
                if (!$f instanceof SplFileInfo) {
                    continue;
                }

                if (!$f->isFile()) {
                    continue;
                }

                $path = (string) $f->getPathname();
                if (substr($path, -4) !== '.php') {
                    continue;
                }

                $files[] = $path;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param string $absFile
     * @param array<string,string> $topicMap
     * @return string
     */
    private static function classify_topic(string $absFile, array $topicMap): string
    {
        $absFile = str_replace('\\', '/', $absFile);

        $bestKey   = '';
        $bestTopic = 'Core';

        foreach ($topicMap as $key => $topic) {
            if (strpos($absFile, $key) === false) {
                continue;
            }

            if (strlen($key) > strlen($bestKey)) {
                $bestKey   = $key;
                $bestTopic = $topic;
            }
        }

        return $bestTopic;
    }

    /**
     * Ensure codex_topics exist. Returns label => id plus _created.
     *
     * @param db $db
     * @param array<int,string> $topics
     * @return array<string,int>
     */
    private static function ensure_topics(db $db, array $topics): array
    {
        $link = $db->connect();

        $existing = $db->fetch_all('SELECT id, label FROM codex_topics') ?: [];
        $map      = [];
        $created  = 0;

        foreach ($existing as $row) {
            $label = (string) ($row['label'] ?? '');
            $id    = (int) ($row['id'] ?? 0);

            if ($label !== '' && $id > 0) {
                $map[$label] = $id;
            }
        }

        foreach ($topics as $label) {
            $label = trim((string) $label);

            if ($label === '' || isset($map[$label])) {
                continue;
            }

            $slug = self::slugify($label);

            $stmt = $link->prepare(
                'INSERT INTO codex_topics (slug, label, sort_order, is_public, created_at) VALUES (?, ?, 0, 1, NOW())'
            );

            if ($stmt === false) {
                continue;
            }

            $stmt->bind_param('ss', $slug, $label);
            $stmt->execute();
            $stmt->close();

            $safeSlug = $link->real_escape_string($slug);
            $row = $db->fetch("SELECT id FROM codex_topics WHERE slug='{$safeSlug}' LIMIT 1");

            if (is_array($row) && isset($row['id'])) {
                $map[$label] = (int) $row['id'];
                $created++;
            }
        }

        $map['_created'] = $created;

        return $map;
    }

    /**
     * @param db $db
     * @param int $topicId
     * @param string $topicLabel
     * @return 'created'|'updated'|'skipped'
     */
    private static function upsert_topic_intro(db $db, int $topicId, string $topicLabel): string
    {
        $link = $db->connect();

        $slug  = 'topic-' . self::slugify($topicLabel);
        $title = $topicLabel . ' — Overview';

        $body =
            '# ' . $topicLabel . "\n\n" .
            "This topic contains API-level documentation extracted from Chaos CMS core.\n\n" .
            "Entries below are generated from source methods.\n";

        $safeSlug = $link->real_escape_string($slug);

        $row = $db->fetch("SELECT id, title, body, format FROM codex WHERE slug='{$safeSlug}' LIMIT 1");

        if (is_array($row) && isset($row['id'])) {
            $id       = (int) $row['id'];
            $oldTitle = (string) ($row['title'] ?? '');
            $oldBody  = (string) ($row['body'] ?? '');
            $oldFmt   = (string) ($row['format'] ?? 'md');

            if ($oldTitle === $title && $oldBody === $body && $oldFmt === 'md') {
                return 'skipped';
            }

            $stmt = $link->prepare(
                "UPDATE codex
                 SET title=?, body=?, format='md', topic_id=?, status=1, visibility=0, updated_at=NOW()
                 WHERE id=?
                 LIMIT 1"
            );

            if ($stmt === false) {
                return 'skipped';
            }

            $stmt->bind_param('ssii', $title, $body, $topicId, $id);
            $stmt->execute();
            $stmt->close();

            return 'updated';
        }

        $stmt = $link->prepare(
            "INSERT INTO codex (slug, title, body, format, topic_id, status, visibility, created_at)
             VALUES (?, ?, ?, 'md', ?, 1, 0, NOW())"
        );

        if ($stmt === false) {
            return 'skipped';
        }

        $stmt->bind_param('sssi', $slug, $title, $body, $topicId);
        $stmt->execute();
        $stmt->close();

        return 'created';
    }

    /**
     * Parse PHP file and extract method entries (best-effort).
     *
     * @param string $abs
     * @return array<int,array<string,string>>
     */
    private static function parse_file_methods(string $abs): array
    {
        $src = @file_get_contents($abs);

        if (!is_string($src) || $src === '') {
            return [];
        }

        $tokens = @token_get_all($src);

        if (!is_array($tokens) || $tokens === []) {
            return [];
        }

        $namespace = '';
        $class     = '';
        $entries   = [];

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && $t[0] === T_NAMESPACE) {
                $namespace = self::read_qualified($tokens, $i + 1);
                continue;
            }

            if (is_array($t) && $t[0] === T_CLASS) {
                $class = self::read_ident($tokens, $i + 1);
                continue;
            }

            if (is_array($t) && $t[0] === T_FUNCTION) {
                $name = self::read_ident($tokens, $i + 1);

                if ($name === '') {
                    continue;
                }

                $kind = ($class !== '') ? 'method' : 'function';
                $fq   = ($class !== '') ? ($class . '::' . $name) : $name;

                if ($namespace !== '' && $class === '') {
                    $fq = $namespace . '\\' . $fq;
                }

                if (self::is_internal_symbol($fq) === true) {
                    continue;
                }

                $sig = self::read_signature_clean($tokens, $i);

                $entries[] = [
                    'kind'      => $kind,
                    'name'      => $fq,
                    'signature' => $sig,
                    'file'      => self::relpath($abs),
                ];
            }
        }

        return $entries;
    }

    /**
     * Internal-only symbols must not be exposed in public Codex.
     *
     * @param string $fq
     * @return bool
     */
    private static function is_internal_symbol(string $fq): bool
    {
        $fq = strtolower($fq);

        if (strpos($fq, '::') !== false) {
            $parts = explode('::', $fq);
            $method = (string) ($parts[1] ?? '');
            if (strpos($method, 'codex_') === 0) {
                return true;
            }
            if (strpos($method, 'utility_codex_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read an identifier after an index.
     *
     * @param array<int,mixed> $tokens
     * @param int $start
     * @return string
     */
    private static function read_ident(array $tokens, int $start): string
    {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && $t[0] === T_STRING) {
                return (string) $t[1];
            }

            if ($t === '(' || $t === '{' || $t === ';') {
                break;
            }
        }

        return '';
    }

    /**
     * Read a qualified name after T_NAMESPACE.
     *
     * @param array<int,mixed> $tokens
     * @param int $start
     * @return string
     */
    private static function read_qualified(array $tokens, int $start): string
    {
        $out = '';
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_NS_SEPARATOR)) {
                $out .= (string) $t[1];
                continue;
            }

            if ($t === ';' || $t === '{') {
                break;
            }
        }

        return trim($out);
    }

    /**
     * Extract a clean function declaration signature (no garbage before it),
     * then format as a stub with braces.
     *
     * @param array<int,mixed> $tokens
     * @param int $fnIndex
     * @return string
     */
    private static function read_signature_clean(array $tokens, int $fnIndex): string
    {
        $start = self::find_signature_start($tokens, $fnIndex);
        $header = self::read_until_block_open($tokens, $start);

        $header = preg_replace('~\s+~', ' ', (string) $header);
        $header = trim((string) $header);

        $header = rtrim($header, '{');
        $header = trim($header);

        if ($header === '') {
            $header = 'function';
        }

        return $header . "\n{\n}\n";
    }

    /**
     * Find the earliest token index that belongs to the function declaration
     * (visibility/static/final/abstract) without capturing prior braces/semicolons.
     *
     * @param array<int,mixed> $tokens
     * @param int $fnIndex
     * @return int
     */
    private static function find_signature_start(array $tokens, int $fnIndex): int
    {
        $i = $fnIndex - 1;

        while ($i >= 0) {
            $t = $tokens[$i];

            if (is_array($t)) {
                $id = (int) $t[0];

                if ($id === T_WHITESPACE) {
                    $i--;
                    continue;
                }

                if (
                    $id === T_PUBLIC ||
                    $id === T_PROTECTED ||
                    $id === T_PRIVATE ||
                    $id === T_STATIC ||
                    $id === T_FINAL ||
                    $id === T_ABSTRACT
                ) {
                    $i--;
                    continue;
                }

                break;
            }

            if ($t === "\n" || $t === "\r" || $t === "\t" || $t === ' ') {
                $i--;
                continue;
            }

            break;
        }

        return max(0, $i + 1);
    }

    /**
     * Read tokens from $start until the opening "{" or ";" that ends the declaration.
     *
     * @param array<int,mixed> $tokens
     * @param int $start
     * @return string
     */
    private static function read_until_block_open(array $tokens, int $start): string
    {
        $out = '';
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t)) {
                $out .= (string) $t[1];
            } else {
                $out .= (string) $t;
            }

            if ($t === '{' || $t === ';') {
                break;
            }
        }

        return $out;
    }

    /**
     * Upsert API entry into codex.
     *
     * @param db $db
     * @param int $topicId
     * @param array<string,string> $entry
     * @return 'created'|'updated'|'skipped'
     */
    private static function upsert_api_entry(db $db, int $topicId, array $entry): string
    {
        $link = $db->connect();

        $name = trim((string) ($entry['name'] ?? ''));

        if ($name === '') {
            return 'skipped';
        }

        $slug  = 'api-' . self::slugify($name);
        $title = $name;

        $sig  = (string) ($entry['signature'] ?? '');
        $file = (string) ($entry['file'] ?? '');

        $body = '# ' . $title . "\n\n";

        if ($sig !== '') {
            $body .= "## Signature\n\n";
            $body .= "```php\n" . $sig . "\n```\n\n";
        }

        if ($file !== '') {
            $body .= "## Source\n\n";
            $body .= '- `' . $file . "`\n\n";
        }

        $body .= "## Notes\n\n";
        $body .= "- Describe what this method does.\n";
        $body .= "- Mention params/return behavior.\n";

        $safeSlug = $link->real_escape_string($slug);

        $row = $db->fetch("SELECT id, title, body, format, topic_id FROM codex WHERE slug='{$safeSlug}' LIMIT 1");

        if (is_array($row) && isset($row['id'])) {
            $id = (int) $row['id'];

            $oldTitle = (string) ($row['title'] ?? '');
            $oldBody  = (string) ($row['body'] ?? '');
            $oldFmt   = (string) ($row['format'] ?? 'md');
            $oldTopic = (int) ($row['topic_id'] ?? 0);

            if ($oldTitle === $title && $oldBody === $body && $oldFmt === 'md' && $oldTopic === $topicId) {
                return 'skipped';
            }

            $stmt = $link->prepare(
                "UPDATE codex
                 SET title=?, body=?, format='md', topic_id=?, status=1, visibility=0, updated_at=NOW()
                 WHERE id=?
                 LIMIT 1"
            );

            if ($stmt === false) {
                return 'skipped';
            }

            $stmt->bind_param('ssii', $title, $body, $topicId, $id);
            $stmt->execute();
            $stmt->close();

            return 'updated';
        }

        $stmt = $link->prepare(
            "INSERT INTO codex (slug, title, body, format, topic_id, status, visibility, created_at)
             VALUES (?, ?, ?, 'md', ?, 1, 0, NOW())"
        );

        if ($stmt === false) {
            return 'skipped';
        }

        $stmt->bind_param('sssi', $slug, $title, $body, $topicId);
        $stmt->execute();
        $stmt->close();

        return 'created';
    }

    /**
     * Slugify.
     *
     * @param string $s
     * @return string
     */
    private static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9]+~', '-', $s);
        $s = trim((string) $s, '-');

        return $s !== '' ? $s : 'item';
    }

    /**
     * Convert absolute file path to /app/... when possible.
     *
     * @param string $abs
     * @return string
     */
    private static function relpath(string $abs): string
    {
        $abs = str_replace('\\', '/', $abs);

        $pos = strpos($abs, '/app/');
        if ($pos !== false) {
            return substr($abs, $pos);
        }

        return $abs;
    }
}

