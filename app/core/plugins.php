<?php

declare(strict_types=1);

class plugins
{
    /**
     * @var array<string,bool>
     */
    protected static array $loaded = [];

    /**
     * Load enabled plugins.
     *
     * Plugin contract:
     *   /public/plugins/{slug}/plugin.php
     * must RETURN an array:
     *   [
     *     'init' => callable|null,
     *     'routes' => callable|null,
     *     'shutdown' => callable|null
     *   ]
     *
     * Nothing in plugin.php should run at file scope.
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
                        $cb();
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

            $file = $docroot . '/public/plugins/' . $slug . '/plugin.php';
            if (!is_file($file)) {
                continue;
            }

            $hooks = require $file;

            if (!is_array($hooks)) {
                self::log('Plugin did not return hooks array: ' . $slug);
                continue;
            }

            self::$loaded[$slug] = true;

            if (isset($hooks['init']) && is_callable($hooks['init'])) {
                $hooks['init']($db);
            }
        }
    }

    /**
     * @param string $slug
     * @return string
     */
    protected static function sanitize_slug(string $slug): string
    {
        return (string) preg_replace('~[^a-z0-9_\-]~i', '', $slug);
    }

    /**
     * @param string $msg
     * @return void
     */
    protected static function log(string $msg): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . PHP_EOL;
        error_log($line);
    }
}

