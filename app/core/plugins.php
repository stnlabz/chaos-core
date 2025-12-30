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

