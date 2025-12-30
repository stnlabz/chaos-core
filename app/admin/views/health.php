<?php
declare(strict_types=1);

/**
 * Admin: Health
 * Reporting only. (Actions live in Maintenance.)
 */

(function (): void {
    global $db;

    if (!isset($db) || !$db instanceof db) {
        echo '<div class="admin-wrap"><div class="container my-4"><div class="admin-card"><h3>Error</h3><div class="admin-note">DB not available.</div></div></div></div>';
        return;
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    /**
     * @param string $sql
     * @return int
     */
    $count_sql = static function (string $sql) use ($db): int {
        $row = $db->fetch($sql);
        if (is_array($row)) {
            $v = array_values($row)[0] ?? 0;
            return (int) $v;
        }
        return 0;
    };

    /**
     * @param string $absPath
     * @return array{ok:bool,path:string,size:int,age:int}
     */
    $file_stat = static function (string $absPath): array {
        if (!is_file($absPath)) {
            return ['ok' => false, 'path' => $absPath, 'size' => 0, 'age' => 0];
        }
        $sz = (int) @filesize($absPath);
        $mt = (int) @filemtime($absPath);
        $age = $mt > 0 ? max(0, time() - $mt) : 0;
        return ['ok' => true, 'path' => $absPath, 'size' => $sz, 'age' => $age];
    };

    /**
     * @param string $absPath
     * @return bool
     */
    $dir_writable = static function (string $absPath): bool {
        return is_dir($absPath) && is_writable($absPath);
    };

    // counts
    $posts   = $count_sql("SELECT COUNT(*) FROM posts");
    $media   = $count_sql("SELECT COUNT(*) FROM media_files");
    $users   = $count_sql("SELECT COUNT(*) FROM users");
    $modules = $count_sql("SELECT COUNT(*) FROM modules WHERE installed=1 AND enabled=1");
    $plugins = $count_sql("SELECT COUNT(*) FROM plugins WHERE installed=1 AND enabled=1");
    $themes  = $count_sql("SELECT COUNT(*) FROM themes");

    // core checks
    $db_ok = ($db->connect() !== false);

    $logsDir      = $docroot . '/logs';
    $uploadsDir   = $docroot . '/public/uploads';
    $downloadsDir = $docroot . '/public/downloads';

    $logs_ok      = $dir_writable($logsDir);
    $uploads_ok   = $dir_writable($uploadsDir);
    $downloads_ok = $dir_writable($downloadsDir);

    // SEO reporting
    $sitemap = $file_stat($docroot . '/sitemap.xml');
    $ror     = $file_stat($docroot . '/ror.xml');
    $hash    = $file_stat($docroot . '/app/data/seo_hash.json');

    // runtime
    $phpver = PHP_VERSION;
    $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');

    // theme name (if available)
    $site_theme = 'default';
    $rowTheme = $db->fetch("SELECT value FROM settings WHERE name='site_theme' LIMIT 1");
    if (is_array($rowTheme) && isset($rowTheme['value'])) {
        $site_theme = (string) $rowTheme['value'];
    }

    $checked = gmdate('Y-m-d H:i:s') . 'Z';

    /**
     * @param bool $ok
     * @return string
     */
    $badge = static function (bool $ok): string {
        return $ok
            ? '<span class="admin-badge ok">OK</span>'
            : '<span class="admin-badge fail">FAIL</span>';
    };

    /**
     * @param int $ageSec
     * @return string
     */
    $age_label = static function (int $ageSec): string {
        if ($ageSec <= 0) return '0m';
        $m = (int) floor($ageSec / 60);
        if ($m < 60) return (string) $m . 'm';
        $h = (int) floor($m / 60);
        if ($h < 24) return (string) $h . 'h';
        $d = (int) floor($h / 24);
        return (string) $d . 'd';
    };

    ?>
    <div class="admin-wrap">
        <div class="container my-4">
        <div class="admin-row">
            <div>
                <div class="admin-note">Admin » Health</div>
                <h1 class="admin-title">Health</h1>
                <div class="admin-subtitle">Last checked: <?= htmlspecialchars($checked, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <a class="admin-btn" href="/admin?action=maintenance">Maintenance</a>
            </div>
        </div>

        <div class="admin-grid cols-4">
            <section class="admin-card">
                <h3>Posts</h3>
                <div class="admin-kpi"><?= (int) $posts; ?></div>
                <div class="admin-kpi-label">total</div>
            </section>
            <section class="admin-card">
                <h3>Media</h3>
                <div class="admin-kpi"><?= (int) $media; ?></div>
                <div class="admin-kpi-label">files</div>
            </section>
            <section class="admin-card">
                <h3>Users</h3>
                <div class="admin-kpi"><?= (int) $users; ?></div>
                <div class="admin-kpi-label">accounts</div>
            </section>
            <section class="admin-card">
                <h3>Registry</h3>
                <div class="admin-kv">
                    <div class="k">Modules</div><div class="v"><?= (int) $modules; ?></div>
                    <div class="k">Plugins</div><div class="v"><?= (int) $plugins; ?></div>
                    <div class="k">Themes</div><div class="v"><?= (int) $themes; ?></div>
                </div>
            </section>
        </div>

        <div style="height:14px;"></div>

        <section class="admin-card">
            <h3>Core Checks</h3>
            <div class="admin-grid cols-4">
                <div class="admin-card">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">Database</div>
                            <div class="admin-mono">Connection</div>
                        </div>
                        <?= $badge($db_ok); ?>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">/logs</div>
                            <div class="admin-mono">Writable</div>
                        </div>
                        <?= $badge($logs_ok); ?>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">/public/uploads</div>
                            <div class="admin-mono">Writable</div>
                        </div>
                        <?= $badge($uploads_ok); ?>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">/public/downloads</div>
                            <div class="admin-mono">Writable</div>
                        </div>
                        <?= $badge($downloads_ok); ?>
                    </div>
                </div>
            </div>
        </section>

        <div style="height:14px;"></div>

        <section class="admin-card">
            <h3>SEO</h3>

            <div class="admin-grid cols-3">
                <div class="admin-card">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">sitemap.xml</div>
                            <div class="admin-mono">/sitemap.xml</div>
                        </div>
                        <?= $badge($sitemap['ok']); ?>
                    </div>
                    <div style="height:8px;"></div>
                    <div class="admin-kv">
                        <div class="k">Size</div><div class="v"><?= (int) $sitemap['size']; ?> bytes</div>
                        <div class="k">Age</div><div class="v"><?= htmlspecialchars($age_label((int)$sitemap['age']), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">ror.xml</div>
                            <div class="admin-mono">/ror.xml</div>
                        </div>
                        <?= $badge($ror['ok']); ?>
                    </div>
                    <div style="height:8px;"></div>
                    <div class="admin-kv">
                        <div class="k">Size</div><div class="v"><?= (int) $ror['size']; ?> bytes</div>
                        <div class="k">Age</div><div class="v"><?= htmlspecialchars($age_label((int)$ror['age']), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <div class="admin-card<?= $hash['ok'] ? '' : ' fail'; ?>">
                    <div class="admin-row">
                        <div>
                            <div class="admin-note">seo_hash.json</div>
                            <div class="admin-mono">/app/data/seo_hash.json</div>
                        </div>
                        <?= $badge($hash['ok']); ?>
                    </div>
                    <div style="height:8px;"></div>
                    <div class="admin-kv">
                        <div class="k">Size</div><div class="v"><?= (int) $hash['size']; ?> bytes</div>
                        <div class="k">Age</div><div class="v"><?= htmlspecialchars($age_label((int)$hash['age']), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>

            <div style="height:10px;"></div>
            <div class="admin-note">Topology source theme: <span class="admin-mono"><?= htmlspecialchars($site_theme, ENT_QUOTES, 'UTF-8'); ?></span></div>
        </section>

        <div style="height:14px;"></div>

        <section class="admin-grid cols-3">
            <div class="admin-card">
                <h3>Runtime</h3>
                <div class="admin-kv">
                    <div class="k">PHP</div><div class="v"><?= htmlspecialchars($phpver, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="k">Server</div><div class="v"><?= htmlspecialchars($server, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

            <div class="admin-card">
                <h3>Theme</h3>
                <div class="admin-mono"><?= htmlspecialchars($site_theme, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="admin-card">
                <h3>Paths</h3>
                <div class="admin-note admin-mono"><?= htmlspecialchars($docroot, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </section>
    </div>
    <?php
})();

