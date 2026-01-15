<?php
declare(strict_types=1);

/**
 * Admin: Maintenance (ENHANCED)
 * 
 * Features:
 * - SEO rebuild (sitemap, ror, robots)
 * - Cache clearing
 * - Database optimization
 * - Database backup
 * - System health integration
 * - Update status checking
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        echo '<div class="admin-wrap"><div class="container my-4"><div class="admin-card"><h3>Error</h3><div class="admin-note">DB not available.</div></div></div></div>';
        return;
    }

    // Auth check
    if (!isset($auth) || !$auth instanceof auth || !$auth->check()) {
        header('Location: /login');
        exit;
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    /**
     * Read setting from DB.
     */
    $get_setting = static function (string $name, string $default = '') use ($db): string {
        $row = $db->fetch("SELECT value FROM settings WHERE name=? LIMIT 1", [$name]);
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

        // ---------------- SEO Rebuild ----------------
        if ($action === 'seo_rebuild') {
            try {
                if (function_exists('seo_build')) {
                    seo_build($site_theme);
                    $flash_ok = 'SEO files generated (sitemap.xml, ror.xml, robots.txt).';
                } elseif (function_exists('seo_generate')) {
                    seo_generate($site_theme);
                    $flash_ok = 'SEO files generated.';
                } else {
                    $flash_err = 'SEO function not found.';
                }
            } catch (Throwable $e) {
                $flash_err = 'SEO rebuild failed: ' . $e->getMessage();
            }
        }

        // ---------------- Cache Clear ----------------
        if ($action === 'cache_clear') {
            $cacheDir = $docroot . '/app/data/cache';
            $removed = 0;

            try {
                if (is_dir($cacheDir)) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );

                    foreach ($it as $f) {
                        $p = (string) $f->getPathname();
                        if ($f->isFile()) {
                            if (@unlink($p)) {
                                $removed++;
                            }
                        } elseif ($f->isDir()) {
                            @rmdir($p);
                        }
                    }
                }

                $flash_ok = 'Cache cleared. Files removed: ' . $removed . '.';
            } catch (Throwable $e) {
                $flash_err = 'Cache clear failed: ' . $e->getMessage();
            }
        }

        // ---------------- Database Optimization ----------------
        if ($action === 'db_optimize') {
            try {
                $conn = $db->connect();
                if ($conn === false) {
                    $flash_err = 'Database connection failed.';
                } else {
                    $tables = [];
                    $res = $conn->query("SHOW TABLES");
                    
                    if ($res instanceof mysqli_result) {
                        while ($row = $res->fetch_array()) {
                            if (isset($row[0])) {
                                $tables[] = $row[0];
                            }
                        }
                        $res->close();
                    }

                    $optimized = 0;
                    foreach ($tables as $table) {
                        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
                        if ($safeTable !== '') {
                            $conn->query("OPTIMIZE TABLE `{$safeTable}`");
                            $optimized++;
                        }
                    }

                    $flash_ok = 'Database optimized. Tables processed: ' . $optimized . '.';
                }
            } catch (Throwable $e) {
                $flash_err = 'Database optimization failed: ' . $e->getMessage();
            }
        }

        // ---------------- Database Backup ----------------
        if ($action === 'db_backup') {
            try {
                $conn = $db->connect();
                if ($conn === false) {
                    $flash_err = 'Database connection failed.';
                } else {
                    $backupDir = $docroot . '/app/data/backups';
                    
                    if (!is_dir($backupDir)) {
                        @mkdir($backupDir, 0755, true);
                    }

                    if (!is_dir($backupDir) || !is_writable($backupDir)) {
                        $flash_err = 'Backup directory not writable: ' . $backupDir;
                    } else {
                        $timestamp = gmdate('Y-m-d_His');
                        $backupFile = $backupDir . '/backup_' . $timestamp . '.sql';

                        // Get all tables
                        $tables = [];
                        $res = $conn->query("SHOW TABLES");
                        
                        if ($res instanceof mysqli_result) {
                            while ($row = $res->fetch_array()) {
                                if (isset($row[0])) {
                                    $tables[] = $row[0];
                                }
                            }
                            $res->close();
                        }

                        // Build SQL dump
                        $sql = "-- Chaos CMS Database Backup\n";
                        $sql .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
                        $sql .= "-- Database: " . $conn->get_server_info() . "\n\n";
                        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

                        foreach ($tables as $table) {
                            $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
                            if ($safeTable === '') {
                                continue;
                            }

                            // Table structure
                            $res = $conn->query("SHOW CREATE TABLE `{$safeTable}`");
                            if ($res instanceof mysqli_result) {
                                $row = $res->fetch_array();
                                if (isset($row[1])) {
                                    $sql .= "-- Table: {$safeTable}\n";
                                    $sql .= "DROP TABLE IF EXISTS `{$safeTable}`;\n";
                                    $sql .= $row[1] . ";\n\n";
                                }
                                $res->close();
                            }

                            // Table data
                            $res = $conn->query("SELECT * FROM `{$safeTable}`");
                            if ($res instanceof mysqli_result) {
                                while ($row = $res->fetch_assoc()) {
                                    $cols = array_keys($row);
                                    $vals = array_map(function($v) use ($conn) {
                                        if ($v === null) {
                                            return 'NULL';
                                        }
                                        return "'" . $conn->real_escape_string((string)$v) . "'";
                                    }, array_values($row));

                                    $sql .= "INSERT INTO `{$safeTable}` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $vals) . ");\n";
                                }
                                $res->close();
                                $sql .= "\n";
                            }
                        }

                        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

                        // Write backup file
                        $result = @file_put_contents($backupFile, $sql);
                        
                        if ($result === false) {
                            $flash_err = 'Failed to write backup file.';
                        } else {
                            $size = @filesize($backupFile);
                            $sizeKB = $size ? round($size / 1024, 2) : 0;
                            $flash_ok = 'Database backup created: backup_' . $timestamp . '.sql (' . $sizeKB . ' KB)';
                        }
                    }
                }
            } catch (Throwable $e) {
                $flash_err = 'Database backup failed: ' . $e->getMessage();
            }
        }
    }

    // ---------------------------------------------------------
    // Check for update/maintenance flags
    // ---------------------------------------------------------
    $updateLock = is_file($docroot . '/app/data/update.lock');
    $maintFlag = is_file($docroot . '/app/data/maintenance.flag');

    ?>
    <div class="admin-wrap">
        <div class="container my-4">
            <div class="admin-row">
                <div>
                    <div class="admin-note">Admin » Maintenance</div>
                    <h1 class="admin-title">Maintenance</h1>
                    <div class="admin-subtitle">System maintenance actions & tools.</div>
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

            <?php if ($updateLock || $maintFlag): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ System Status:</strong>
                    <?php if ($updateLock): ?>
                        Update in progress (<code>update.lock</code> present).
                    <?php endif; ?>
                    <?php if ($maintFlag): ?>
                        Maintenance mode active (<code>maintenance.flag</code> present).
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="admin-grid cols-2">
                <!-- SEO -->
                <section class="admin-card">
                    <h3>SEO</h3>
                    <div class="admin-note">
                        Rebuild <code>sitemap.xml</code>, <code>ror.xml</code>, and <code>robots.txt</code> 
                        using the active theme navigation.
                    </div>
                    <div style="height:10px;"></div>
                    <form method="post">
                        <input type="hidden" name="action" value="seo_rebuild">
                        <button class="admin-btn primary" type="submit">Rebuild SEO</button>
                    </form>
                    <div style="height:8px;"></div>
                    <div class="admin-note">Theme: <span class="admin-mono"><?= htmlspecialchars($site_theme, ENT_QUOTES, 'UTF-8'); ?></span></div>
                </section>

                <!-- Cache -->
                <section class="admin-card">
                    <h3>Cache</h3>
                    <div class="admin-note">
                        Clears all cache files in <code>/app/data/cache</code> directory.
                    </div>
                    <div style="height:10px;"></div>
                    <form method="post">
                        <input type="hidden" name="action" value="cache_clear">
                        <button class="admin-btn" type="submit">Clear Cache</button>
                    </form>
                </section>

                <!-- Database Optimization -->
                <section class="admin-card">
                    <h3>Database</h3>
                    <div class="admin-note">
                        Optimize all database tables to improve performance and reclaim space.
                    </div>
                    <div style="height:10px;"></div>
                    <form method="post">
                        <input type="hidden" name="action" value="db_optimize">
                        <button class="admin-btn" type="submit">Optimize Database</button>
                    </form>
                </section>

                <!-- Database Backup -->
                <section class="admin-card">
                    <h3>Backup</h3>
                    <div class="admin-note">
                        Create a complete SQL backup of all database tables.
                        Stored in <code>/app/data/backups/</code>.
                    </div>
                    <div style="height:10px;"></div>
                    <form method="post">
                        <input type="hidden" name="action" value="db_backup">
                        <button class="admin-btn" type="submit">Create Backup</button>
                    </form>
                </section>
            </div>

            <hr class="admin-hr">

            <div class="admin-card">
                <h3>Shortcuts</h3>
                <div class="admin-note">
                    <a class="admin-btn" href="/admin?action=health">View System Health</a>
                    <a class="admin-btn" href="/admin">Back to Dashboard</a>
                </div>
            </div>

            <?php
            // Display recent backups
            $backupDir = $docroot . '/app/data/backups';
            if (is_dir($backupDir)) {
                $backups = glob($backupDir . '/backup_*.sql');
                if (!empty($backups)) {
                    rsort($backups); // Newest first
                    $backups = array_slice($backups, 0, 5); // Show last 5
                    
                    echo '<div class="admin-card">';
                    echo '<h3>Recent Backups</h3>';
                    echo '<div class="admin-note">Last 5 database backups:</div>';
                    echo '<div style="height:10px;"></div>';
                    echo '<ul style="list-style:none;padding:0;margin:0;">';
                    
                    foreach ($backups as $backup) {
                        $name = basename($backup);
                        $size = @filesize($backup);
                        $sizeKB = $size ? round($size / 1024, 2) : 0;
                        $time = @filemtime($backup);
                        $age = $time ? gmdate('Y-m-d H:i:s', $time) . ' UTC' : 'Unknown';
                        
                        echo '<li style="padding:8px 0;border-bottom:1px solid rgba(0,0,0,0.1);">';
                        echo '<span class="admin-mono">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
                        echo ' <span style="color:#666;font-size:0.9em;">(' . $sizeKB . ' KB, ' . $age . ')</span>';
                        echo '</li>';
                    }
                    
                    echo '</ul>';
                    echo '</div>';
                }
            }
            ?>

        </div>
    </div>
    <?php
})();
