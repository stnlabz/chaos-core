<?php
declare(strict_types=1);

/**
 * Admin: Maintenance
 * Actions only. (Health reporting lives in /admin?action=health)
 */

(function (): void {
    global $db;

    if (!isset($db) || !$db instanceof db) {
        echo '<div class="admin-wrap"><div class="container my-4"><div class="admin-card"><h3>Error</h3><div class="admin-note">DB not available.</div></div></div></div>';
        return;
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    /**
     * Read a setting value from settings table.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    $get_setting = static function (string $name, string $default = '') use ($db): string {
        $row = $db->fetch("SELECT value FROM settings WHERE name='" . addslashes($name) . "' LIMIT 1");
        if (is_array($row) && isset($row['value'])) {
            return (string) $row['value'];
        }
        return $default;
    };

    $site_theme = $get_setting('site_theme', 'default');

    $flash_ok = '';
    $flash_err = '';

    // ---------------------------------------------------------
    // POST handlers (actions)
    // ---------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        // Rebuild SEO
        if ($action === 'seo_rebuild') {
            try {
                if (function_exists('seo_build')) {
                    seo_build($site_theme);
                    $flash_ok = 'SEO rebuild triggered.';
                } elseif (function_exists('seo_generate')) {
                    seo_generate($site_theme);
                    $flash_ok = 'SEO rebuild triggered.';
                } else {
                    $flash_err = 'No callable SEO entrypoint found (expected seo_build() or seo_generate()).';
                }
            } catch (Throwable $e) {
                $flash_err = 'SEO rebuild failed: ' . $e->getMessage();
            }
        }

        // Clear cache (safe + optional)
        if ($action === 'cache_clear') {
            $cacheDir = $docroot . '/app/data/cache';
            $removed = 0;

            if (is_dir($cacheDir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($it as $f) {
                    $p = (string) $f->getPathname();
                    if ($f->isFile()) {
                        if (@unlink($p)) { $removed++; }
                    } elseif ($f->isDir()) {
                        @rmdir($p);
                    }
                }
            }

            $flash_ok = 'Cache clear attempted. Files removed: ' . (string) $removed . '.';
        }
    }

    ?>
    <div class="admin-wrap">
        <div class="container my-4">
            <div class="admin-row">
            <div>
                <div class="admin-note">Admin » Maintenance</div>
                <h1 class="admin-title">Maintenance</h1>
                <div class="admin-subtitle">Actions & tools.</div>
            </div>
            </div>

        <?php if ($flash_ok !== ''): ?>
            <div class="alert alert-success">
                <strong><?= htmlspecialchars($flash_ok, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($flash_err !== ''): ?>
            <div class="alert alert-danger">
                <strong><?= htmlspecialchars($flash_err, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        <?php endif; ?>

        <div class="admin-grid cols-2">
            <section class="admin-card">
                <h3>SEO</h3>
                <div class="admin-note">Rebuild sitemap.xml and ror.xml using the active theme topology.</div>
                <div style="height:10px;"></div>
                <form method="post">
                    <input type="hidden" name="action" value="seo_rebuild">
                    <button class="admin-btn primary" type="submit">Rebuild SEO</button>
                </form>
                <div style="height:8px;"></div>
                <div class="admin-note">Theme: <span class="admin-mono"><?= htmlspecialchars($site_theme, ENT_QUOTES, 'UTF-8'); ?></span></div>
            </section>

            <section class="admin-card">
                <h3>Cache</h3>
                <div class="admin-note">Clears <span class="admin-mono">/app/data/cache</span> (if present).</div>
                <div style="height:10px;"></div>
                <form method="post">
                    <input type="hidden" name="action" value="cache_clear">
                    <button class="admin-btn" type="submit">Clear Cache</button>
                </form>
            </section>
        </div>

        <hr class="admin-hr">

        <div class="admin-card">
            <h3>Shortcuts</h3>
            <div class="admin-note">
                <a class="admin-btn" href="/admin?action=health">View Health</a>
            </div>
        </div>
        </div>
    </div>
    <?php
})();

