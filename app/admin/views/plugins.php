<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin View: Plugins
 * File: /app/admin/views/plugins.php
 */

(function (): void {
    global $db;

    if (!$db instanceof db) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if (!$conn instanceof mysqli) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    $baseDir = $docroot . '/public/plugins';

    $slug_clean = static function (string $slug): string {
        $slug = strtolower($slug);
        $slug = (string) preg_replace('~[^a-z0-9_\-]~', '', $slug);
        return $slug;
    };

    $meta_read = static function (string $dir, string $slug): array {
        $fallback = [
            'slug'    => $slug,
            'name'    => $slug,
            'version' => 'v0.0.0',
            'creator' => 'unknown',
        ];

        $file = $dir . '/meta.json';
        if (!is_file($file)) {
            return $fallback;
        }

        $raw = (string) @file_get_contents($file);
        $j   = json_decode($raw, true);

        if (!is_array($j)) {
            return $fallback;
        }

        return [
            'slug'    => $slug,
            'name'    => (string) ($j['name'] ?? $fallback['name']),
            'version' => (string) ($j['version'] ?? $fallback['version']),
            'creator' => (string) ($j['creator'] ?? ($j['author'] ?? $fallback['creator'])),
        ];
    };

    $has_admin = static function (string $slug) use ($docroot): bool {
        return is_file($docroot . '/public/plugins/' . $slug . '/admin/main.php');
    };

    $redir = static function (): void {
        header('Location: /admin?action=plugins');
        exit;
    };

    $csrf_val = function_exists('csrf_token') ? (string) csrf_token() : '';

    // ------------------------------------------------------------
    // POST actions (install/enable/disable/uninstall)
    // ------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string) ($_POST['csrf'] ?? '');
        if (!function_exists('csrf_ok') || !csrf_ok($token)) {
            $redir();
        }

        $do   = (string) ($_POST['do'] ?? '');
        $slug = $slug_clean((string) ($_POST['slug'] ?? ''));

        if ($do === '' || $slug === '') {
            $redir();
        }

        $dir = $baseDir . '/' . $slug;
        if (!is_dir($dir)) {
            $redir();
        }

        $meta = $meta_read($dir, $slug);

        $name    = (string) $meta['name'];
        $version = (string) $meta['version'];
        $creator = (string) $meta['creator'];
        $admin   = $has_admin($slug) ? 1 : 0;

        // current row?
        $installed = 0;
        $enabled   = 0;

        $stmt = $conn->prepare("SELECT installed, enabled FROM plugins WHERE slug=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (is_array($row)) {
                $installed = (int) ($row['installed'] ?? 0);
                $enabled   = (int) ($row['enabled'] ?? 0);
            }
        }

        if ($do === 'install') {
            if ($installed === 1) {
                $redir();
            }

            $stmt = $conn->prepare(
                "INSERT INTO plugins (slug, name, version, creator, has_admin, installed, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('ssssi', $slug, $name, $version, $creator, $admin);
                $stmt->execute();
                $stmt->close();
            }

            $redir();
        }

        if ($do === 'uninstall') {
            $stmt = $conn->prepare("DELETE FROM plugins WHERE slug=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $stmt->close();
            }

            $redir();
        }

        if ($do === 'enable') {
            if ($installed !== 1) {
                $redir();
            }

            $stmt = $conn->prepare("UPDATE plugins SET enabled=1, updated_at=NOW() WHERE slug=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $stmt->close();
            }

            $redir();
        }

        if ($do === 'disable') {
            if ($installed !== 1) {
                $redir();
            }

            $stmt = $conn->prepare("UPDATE plugins SET enabled=0, updated_at=NOW() WHERE slug=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $stmt->close();
            }

            $redir();
        }

        $redir();
    }

    // ------------------------------------------------------------
    // Build filesystem plugin list
    // ------------------------------------------------------------
    $fs = [];
    if (is_dir($baseDir)) {
        $dirs = glob($baseDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $slug = basename($dir);
            $slug = $slug_clean($slug);
            if ($slug === '') {
                continue;
            }

            if (!is_file($dir . '/plugin.php')) {
                continue;
            }

            $meta = $meta_read($dir, $slug);

            $fs[$slug] = [
                'slug'      => $slug,
                'name'      => (string) $meta['name'],
                'version'   => (string) $meta['version'],
                'creator'   => (string) $meta['creator'],
                'has_admin' => $has_admin($slug) ? 1 : 0,
            ];
        }
    }

    // ------------------------------------------------------------
    // Load DB plugin state
    // ------------------------------------------------------------
    $dbRows = [];
    $res = $conn->query("SELECT slug, installed, enabled, name, version, creator, has_admin FROM plugins ORDER BY slug ASC");
    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            if (is_array($r) && isset($r['slug'])) {
                $dbRows[(string) $r['slug']] = $r;
            }
        }
        $res->close();
    }

    // ------------------------------------------------------------
    // Merge view rows
    // ------------------------------------------------------------
    $rows = [];
    foreach ($fs as $slug => $p) {
        $dbp = $dbRows[$slug] ?? null;

        $installed = is_array($dbp) ? (int) ($dbp['installed'] ?? 0) : 0;
        $enabled   = is_array($dbp) ? (int) ($dbp['enabled'] ?? 0) : 0;

        $rows[] = [
            'slug'      => $slug,
            'name'      => (string) ($p['name'] ?? $slug),
            'version'   => (string) ($p['version'] ?? 'v0.0.0'),
            'creator'   => (string) ($p['creator'] ?? 'unknown'),
            'has_admin' => (int) ($p['has_admin'] ?? 0),
            'installed' => $installed,
            'enabled'   => $enabled,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) $a['slug'], (string) $b['slug']);
    });

    $pill = static function (string $text, string $kind): string {
        $cls = 'admin-pill';
        if ($kind === 'ok') {
            $cls .= ' admin-pill-ok';
        } elseif ($kind === 'warn') {
            $cls .= ' admin-pill-warn';
        } elseif ($kind === 'bad') {
            $cls .= ' admin-pill-bad';
        } else {
            $cls .= ' admin-pill-neutral';
        }

        return '<span class="' . $cls . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    };

    ?>
    <div class="container my-4">
        <div class="admin-breadcrumb">
            <small><a href="/admin">Admin</a> &raquo; Plugins</small>
        </div>

        <div class="admin-page-head">
            <h1 class="admin-title">Plugins</h1>
            <div class="admin-subtitle">Install and enable plugins from <code>/public/plugins</code></div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="alert alert-secondary mt-3">No plugins found.</div>
            <?php return; ?>
        <?php endif; ?>

        <div class="admin-grid admin-grid-2 mt-3">
            <?php foreach ($rows as $p): ?>
                <?php
                $slug      = (string) ($p['slug'] ?? '');
                $name      = (string) ($p['name'] ?? $slug);
                $version   = (string) ($p['version'] ?? 'v0.0.0');
                $creator   = (string) ($p['creator'] ?? 'unknown');
                $installed = (int) ($p['installed'] ?? 0);
                $enabled   = (int) ($p['enabled'] ?? 0);
                $hasAdmin  = (int) ($p['has_admin'] ?? 0);

                $state = $installed !== 1 ? $pill('Not installed', 'neutral') : ($enabled === 1 ? $pill('Enabled', 'ok') : $pill('Disabled', 'warn'));
                ?>
                <section class="admin-card">
                    <div class="admin-card-top">
                        <div>
                            <div class="admin-card-title"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="admin-card-sub">
                                <?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="admin-dot">·</span>
                                <?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="admin-dot">·</span>
                                <?= htmlspecialchars($creator, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <div class="admin-card-state"><?= $state; ?></div>
                    </div>

                    <div class="admin-actions">
                        <form method="post" class="admin-actions-left">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_val, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">

                            <?php if ($installed !== 1): ?>
                                <button class="admin-btn admin-btn-primary" type="submit" name="do" value="install">Install</button>
                            <?php else: ?>
                                <?php if ($enabled === 1): ?>
                                    <button class="admin-btn admin-btn-warn" type="submit" name="do" value="disable">Disable</button>
                                <?php else: ?>
                                    <button class="admin-btn admin-btn-ok" type="submit" name="do" value="enable">Enable</button>
                                <?php endif; ?>
                                <button class="admin-btn admin-btn-danger" type="submit" name="do" value="uninstall" onclick="return confirm('Uninstall plugin <?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>?');">Uninstall</button>
                            <?php endif; ?>
                        </form>

                        <div class="admin-actions-right">
                            <?php if ($installed === 1 && $enabled === 1 && $hasAdmin === 1): ?>
                                <a class="admin-btn admin-btn-link" href="/admin?action=plugin_admin&amp;slug=<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">Admin</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
})();

