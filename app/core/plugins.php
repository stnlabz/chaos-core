<?php

declare(strict_types=1);

/**
 * Chaos CMS - Core Plugins (ENHANCED)
 *
 * Features:
 * - Plugin slot system (register/execute slots)
 * - Plugin structure validation (Codex 17.3)
 * - Metadata validation (meta.json)
 * - Dependency resolution
 * - Conflict detection
 * - Asset coordination
 * - Enhanced error handling
 * - Safe failure modes
 *
 * Plugin Contract:
 *   /public/plugins/{slug}/plugin.php must RETURN array:
 *   [
 *     'init' => callable|null,
 *     'routes' => callable|null,
 *     'shutdown' => callable|null
 *   ]
 *
 * Plugin Structure (Codex 17.3):
 *   /public/plugins/{slug}/
 *   ├── plugin.php       (entry point, returns hooks)
 *   ├── meta.json        (metadata: name, version, author, dependencies)
 *   ├── actions/         (optional, action files)
 *   ├── templates/       (optional, render templates)
 *   └── assets/          (optional, CSS/JS/images)
 */

class plugins
{
    /**
     * @var array<string,bool>
     */
    protected static array $loaded = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected static array $metadata = [];

    /**
     * Load enabled plugins.
     *
     * @param db $db
     * @return void
     */
    public static function load_enabled(db $db): void
    {
        // -----------------------------------------------------------------
        // Core slot registry (MUST exist before any plugin init runs)
        // -----------------------------------------------------------------
        if (!function_exists('plugin_register_slot')) {
            /**
             * Register a callback to a named slot.
             *
             * @param string   $slot
             * @param callable $cb
             * @param int      $priority Lower runs first.
             *
             * @return void
             */
            function plugin_register_slot(string $slot, callable $cb, int $priority = 10): void
            {
                if (!isset($GLOBALS['CHAOS_PLUGIN_SLOTS']) || !is_array($GLOBALS['CHAOS_PLUGIN_SLOTS'])) {
                    $GLOBALS['CHAOS_PLUGIN_SLOTS'] = [];
                }

                if (!isset($GLOBALS['CHAOS_PLUGIN_SLOTS'][$slot]) || !is_array($GLOBALS['CHAOS_PLUGIN_SLOTS'][$slot])) {
                    $GLOBALS['CHAOS_PLUGIN_SLOTS'][$slot] = [];
                }

                $GLOBALS['CHAOS_PLUGIN_SLOTS'][$slot][] = [
                    'priority' => $priority,
                    'cb'       => $cb,
                ];
            }
        }

        if (!function_exists('plugin_slot')) {
            /**
             * Render all callbacks registered to a named slot.
             *
             * @param string $slot
             *
             * @return void
             */
            function plugin_slot(string $slot): void
            {
                $slots = $GLOBALS['CHAOS_PLUGIN_SLOTS'] ?? null;

                if (!is_array($slots) || !isset($slots[$slot]) || !is_array($slots[$slot])) {
                    return;
                }

                $items = $slots[$slot];

                usort($items, static function (array $a, array $b): int {
                    return (int) ($a['priority'] ?? 10) <=> (int) ($b['priority'] ?? 10);
                });

                foreach ($items as $it) {
                    $cb = $it['cb'] ?? null;

                    if (is_callable($cb)) {
                        try {
                            $cb();
                        } catch (\Throwable $e) {
                            error_log('[PLUGIN_SLOT_ERROR] ' . $slot . ': ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        // -----------------------------------------------------------------
        // Normal plugin loading
        // -----------------------------------------------------------------
        $conn = $db->connect();
        if ($conn === false) {
            return;
        }

        $rows = $db->fetch_all(
            "SELECT slug FROM plugins WHERE installed=1 AND enabled=1 ORDER BY slug ASC"
        );

        if (!$rows) {
            return;
        }

        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

        foreach ($rows as $r) {
            $slug = (string) ($r['slug'] ?? '');
            $slug = self::sanitize_slug($slug);

            if ($slug === '') {
                continue;
            }

            if (isset(self::$loaded[$slug])) {
                continue;
            }

            // Validate plugin structure
            $validation = self::validate_structure($slug);
            
            if (!$validation['valid']) {
                self::log('Plugin structure validation failed: ' . $slug . ' - ' . implode(', ', $validation['errors']));
                continue;
            }

            // Validate metadata
            $metadata = self::validate_metadata($slug);
            
            if (!$metadata['valid']) {
                self::log('Plugin metadata validation failed: ' . $slug . ' - ' . implode(', ', $metadata['errors']));
                continue;
            }

            self::$metadata[$slug] = $metadata['data'];

            // Check dependencies
            if (!self::check_dependencies($slug, $metadata['data'])) {
                self::log('Plugin dependency check failed: ' . $slug);
                continue;
            }

            // Check conflicts
            $conflicts = self::check_conflicts($slug, $metadata['data']);
            
            if (!empty($conflicts)) {
                self::log('Plugin conflicts detected: ' . $slug . ' - ' . implode(', ', $conflicts));
                continue;
            }

            // Load plugin
            $file = $docroot . '/public/plugins/' . $slug . '/plugin.php';
            
            try {
                $hooks = require $file;

                if (!is_array($hooks)) {
                    self::log('Plugin did not return hooks array: ' . $slug);
                    continue;
                }

                self::$loaded[$slug] = true;

                // Execute init hook
                if (isset($hooks['init']) && is_callable($hooks['init'])) {
                    try {
                        $hooks['init']($db);
                    } catch (\Throwable $e) {
                        self::log('Plugin init failed: ' . $slug . ' - ' . $e->getMessage());
                        self::$loaded[$slug] = false;
                        continue;
                    }
                }

                // Register shutdown hook if provided
                if (isset($hooks['shutdown']) && is_callable($hooks['shutdown'])) {
                    register_shutdown_function($hooks['shutdown']);
                }

            } catch (\Throwable $e) {
                self::log('Plugin load failed: ' . $slug . ' - ' . $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Validate plugin structure per Codex 17.3.
     *
     * Required:
     * - /public/plugins/{slug}/plugin.php
     * - /public/plugins/{slug}/meta.json
     *
     * @param string $slug
     * @return array{valid:bool,errors:array<int,string>}
     */
    public static function validate_structure(string $slug): array
    {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $base = $docroot . '/public/plugins/' . $slug;

        $errors = [];

        // Check plugin directory exists
        if (!is_dir($base)) {
            $errors[] = 'Plugin directory does not exist';
            return ['valid' => false, 'errors' => $errors];
        }

        // Check plugin.php exists
        if (!is_file($base . '/plugin.php')) {
            $errors[] = 'plugin.php missing';
        }

        // Check meta.json exists (meta.json or plugin.json acceptable)
        if (!is_file($base . '/meta.json') && !is_file($base . '/plugin.json')) {
            $errors[] = 'meta.json or plugin.json missing';
        }

        // Optional: Check actions/ templates/ assets/ are directories if they exist
        foreach (['actions', 'templates', 'assets'] as $dir) {
            $path = $base . '/' . $dir;
            if (file_exists($path) && !is_dir($path)) {
                $errors[] = $dir . ' exists but is not a directory';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate and load plugin metadata.
     *
     * @param string $slug
     * @return array{valid:bool,errors:array<int,string>,data:array<string,mixed>}
     */
    public static function validate_metadata(string $slug): array
    {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $base = $docroot . '/public/plugins/' . $slug;

        $errors = [];
        $data = [];

        // Try meta.json first, then plugin.json
        $metaFile = is_file($base . '/meta.json') ? $base . '/meta.json' : $base . '/plugin.json';

        if (!is_file($metaFile)) {
            return [
                'valid' => false,
                'errors' => ['Metadata file not found'],
                'data' => [],
            ];
        }

        $raw = @file_get_contents($metaFile);
        
        if ($raw === false) {
            return [
                'valid' => false,
                'errors' => ['Could not read metadata file'],
                'data' => [],
            ];
        }

        $json = json_decode($raw, true);

        if (!is_array($json)) {
            return [
                'valid' => false,
                'errors' => ['Invalid JSON in metadata file'],
                'data' => [],
            ];
        }

        // Required fields
        $required = ['name', 'version', 'author'];

        foreach ($required as $field) {
            if (!isset($json[$field]) || trim((string) $json[$field]) === '') {
                $errors[] = 'Missing required field: ' . $field;
            }
        }

        // Validate version format (semver-ish: v1.0.0 or 1.0.0)
        if (isset($json['version'])) {
            $version = (string) $json['version'];
            if (!preg_match('/^v?\d+\.\d+(\.\d+)?/', $version)) {
                $errors[] = 'Invalid version format (expected: 1.0.0 or v1.0.0)';
            }
        }

        // Build data array
        $data = [
            'slug' => $slug,
            'name' => (string) ($json['name'] ?? $slug),
            'version' => (string) ($json['version'] ?? '0.0.0'),
            'author' => (string) ($json['author'] ?? 'unknown'),
            'description' => (string) ($json['description'] ?? ''),
            'dependencies' => (array) ($json['dependencies'] ?? []),
            'conflicts' => (array) ($json['conflicts'] ?? []),
            'requires_version' => (string) ($json['requires_version'] ?? ''),
        ];

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data,
        ];
    }

    /**
     * Check if plugin dependencies are met.
     *
     * @param string $slug
     * @param array<string,mixed> $metadata
     * @return bool
     */
    public static function check_dependencies(string $slug, array $metadata): bool
    {
        $dependencies = (array) ($metadata['dependencies'] ?? []);

        if (empty($dependencies)) {
            return true; // No dependencies
        }

        foreach ($dependencies as $depSlug => $depVersion) {
            // Check if dependency is loaded
            if (!isset(self::$loaded[$depSlug]) || !self::$loaded[$depSlug]) {
                self::log('Plugin ' . $slug . ' requires ' . $depSlug . ' but it is not loaded');
                return false;
            }

            // Check version if specified
            if ($depVersion !== '' && isset(self::$metadata[$depSlug]['version'])) {
                $loadedVersion = self::$metadata[$depSlug]['version'];
                
                if (!self::version_compatible($loadedVersion, (string) $depVersion)) {
                    self::log('Plugin ' . $slug . ' requires ' . $depSlug . ' ' . $depVersion . ' but ' . $loadedVersion . ' is loaded');
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check for plugin conflicts.
     *
     * @param string $slug
     * @param array<string,mixed> $metadata
     * @return array<int,string>
     */
    public static function check_conflicts(string $slug, array $metadata): array
    {
        $conflicts = (array) ($metadata['conflicts'] ?? []);
        $detected = [];

        if (empty($conflicts)) {
            return []; // No conflicts declared
        }

        foreach ($conflicts as $conflictSlug) {
            if (isset(self::$loaded[$conflictSlug]) && self::$loaded[$conflictSlug]) {
                $detected[] = $conflictSlug;
            }
        }

        return $detected;
    }

    /**
     * Get plugin assets (CSS/JS paths).
     *
     * @param string $slug
     * @return array{css:array<int,string>,js:array<int,string>}
     */
    public static function get_plugin_assets(string $slug): array
    {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $base = $docroot . '/public/plugins/' . $slug . '/assets';

        $assets = [
            'css' => [],
            'js' => [],
        ];

        if (!is_dir($base)) {
            return $assets;
        }

        // Look for common asset files
        $cssFiles = ['style.css', 'plugin.css', $slug . '.css'];
        $jsFiles = ['script.js', 'plugin.js', $slug . '.js'];

        foreach ($cssFiles as $file) {
            if (is_file($base . '/css/' . $file)) {
                $assets['css'][] = '/public/plugins/' . $slug . '/assets/css/' . $file;
            }
        }

        foreach ($jsFiles as $file) {
            if (is_file($base . '/js/' . $file)) {
                $assets['js'][] = '/public/plugins/' . $slug . '/assets/js/' . $file;
            }
        }

        return $assets;
    }

    /**
     * Check if loaded version is compatible with required version.
     * Simple comparison: loaded >= required
     *
     * @param string $loaded  (e.g. "1.2.3" or "v1.2.3")
     * @param string $required (e.g. ">=1.0.0" or "1.0.0")
     * @return bool
     */
    protected static function version_compatible(string $loaded, string $required): bool
    {
        // Strip 'v' prefix
        $loaded = ltrim($loaded, 'v');
        $required = ltrim($required, 'v');

        // Extract operator if present (>=, >, ==, <, <=)
        $operator = '>=';
        if (preg_match('/^(>=|>|==|<|<=)(.+)$/', $required, $matches)) {
            $operator = $matches[1];
            $required = trim($matches[2]);
        }

        // Simple version_compare
        $cmp = version_compare($loaded, $required);

        switch ($operator) {
            case '>=':
                return $cmp >= 0;
            case '>':
                return $cmp > 0;
            case '==':
                return $cmp === 0;
            case '<':
                return $cmp < 0;
            case '<=':
                return $cmp <= 0;
            default:
                return $cmp >= 0;
        }
    }

    /**
     * Get loaded plugin metadata.
     *
     * @param string $slug
     * @return array<string,mixed>|null
     */
    public static function get_metadata(string $slug): ?array
    {
        return self::$metadata[$slug] ?? null;
    }

    /**
     * Check if plugin is loaded.
     *
     * @param string $slug
     * @return bool
     */
    public static function is_loaded(string $slug): bool
    {
        return self::$loaded[$slug] ?? false;
    }

    /**
     * Get all loaded plugin slugs.
     *
     * @return array<int,string>
     */
    public static function get_loaded(): array
    {
        return array_keys(array_filter(self::$loaded));
    }

    /**
     * Sanitize plugin slug.
     *
     * @param string $slug
     * @return string
     */
    protected static function sanitize_slug(string $slug): string
    {
        return (string) preg_replace('~[^a-z0-9_\-]~i', '', $slug);
    }

    /**
     * Log plugin message.
     *
     * @param string $msg
     * @return void
     */
    protected static function log(string $msg): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] [PLUGINS] ' . $msg . PHP_EOL;
        error_log($line);
    }
}
