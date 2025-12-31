<?php

declare(strict_types=1);

/**
 * Chaos CMS — Admin: Modules
 *
 * Rules:
 * - Modules live in: /public/modules/{slug}
 * - Optional install hook:
 *     /app/modules/{slug}/install.php OR /public/modules/{slug}/install.php
 *     function {slug}_install(mysqli $conn): array{ok:bool,error?:string}
 * - Optional uninstall hook:
 *     /app/modules/{slug}/uninstall.php OR /public/modules/{slug}/uninstall.php
 *     function {slug}_uninstall(mysqli $conn): array{ok:bool,error?:string}
 * - If hook missing, move along.
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

    $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3)), '/\\');
    $baseDir = $docroot . '/public/modules';
    $nowUtc  = gmdate('Y-m-d H:i:s');

    $flashOk  = '';
    $flashErr = '';

    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $slugClean = static function (string $slug): string {
        $slug = (string) preg_replace('~[^a-z0-9_\-]~i', '', $slug);
        return strtolower($slug);
    };

    $hasAdmin = static function (string $slug) use ($docroot): int {
        return is_file($docroot . '/public/modules/' . $slug . '/admin/main.php') ? 1 : 0;
    };

    $metaRead = static function (string $dir, string $slug): array {
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

    $resolveHookPath = static function (string $docroot, string $slug, string $file): string {
        $p = $docroot . '/app/modules/' . $slug . '/' . $file;
        if (is_file($p)) {
            return $p;
        }

        $p = $docroot . '/public/modules/' . $slug . '/' . $file;
        if (is_file($p)) {
            return $p;
        }

        return '';
    };

    $redirect = static function (): void {
        header('Location: /admin?action=modules');
        exit;
    };

    $csrf = function_exists('csrf_token') ? (string) csrf_token() : '';

    // ---------------------------------------------------------------------
    // POST actions
    // ---------------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string) ($_POST['csrf'] ?? '');

        if (function_exists('csrf_ok') && !csrf_ok($token)) {
            $flashErr = 'Invalid CSRF token.';
        } else {
            $do   = (string) ($_POST['do'] ?? '');
            $slug = $slugClean((string) ($_POST['slug'] ?? ''));

            if ($do === '' || $slug === '' || $slug === 'home') {
                $redirect();
            }

            $dir = $baseDir . '/' . $slug;
            if (!is_dir($dir)) {
                $redirect();
            }

            $meta = $metaRead($dir, $slug);

            $name = (string) $meta['name'];
            $ver  = (string) $meta['version'];
            $cre  = (string) $meta['creator'];
            $adm  = (int) $hasAdmin($slug);

            if ($do === 'install') {
                $stmt = $conn->prepare(
                    "INSERT INTO modules (slug, name, version, creator, has_admin, enabled, installed, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        name=VALUES(name),
                        version=VALUES(version),
                        creator=VALUES(creator),
                        has_admin=VALUES(has_admin),
                        installed=1,
                        enabled=0,
                        updated_at=VALUES(updated_at)"
                );

                if ($stmt) {
                    $stmt->bind_param('ssssiss', $slug, $name, $ver, $cre, $adm, $nowUtc, $nowUtc);
                    $stmt->execute();
                    $stmt->close();
                }

                $installPath = $resolveHookPath($docroot, $slug, 'install.php');

                if ($installPath !== '') {
                    require_once $installPath;

                    $fn = $slug . '_install';

                    if (function_exists($fn)) {
                        $result = $fn($conn);

                        if (is_array($result) && (($result['ok'] ?? true) === false)) {
                            $flashErr = (string) ($result['error'] ?? 'Module installer failed.');
                        }
                    } else {
                        $flashErr = 'Installer present but function missing: ' . $fn . '()';
                    }
                }

                if ($flashErr === '') {
                    $redirect();
                }
            }

            if ($do === 'enable') {
                $stmt = $conn->prepare(
                    "UPDATE modules
                     SET enabled=1, installed=1, name=?, version=?, creator=?, has_admin=?, updated_at=?
                     WHERE slug=? LIMIT 1"
                );

                if ($stmt) {
                    $stmt->bind_param('sssiss', $name, $ver, $cre, $adm, $nowUtc, $slug);
                    $stmt->execute();
                    $stmt->close();
                }

                $redirect();
            }

            if ($do === 'disable') {
                $stmt = $conn->prepare("UPDATE modules SET enabled=0, updated_at=? WHERE slug=? LIMIT 1");

                if ($stmt) {
                    $stmt->bind_param('ss', $nowUtc, $slug);
                    $stmt->execute();
                    $stmt->close();
                }

                $redirect();
            }

            if ($do === 'uninstall') {
                $uninstallPath = $resolveHookPath($docroot, $slug, 'uninstall.php');

                if ($uninstallPath !== '') {
                    require_once $uninstallPath;

                    $fn = $slug . '_uninstall';

                    if (function_exists($fn)) {
                        $result = $fn($conn);

                        if (is_array($result) && (($result['ok'] ?? true) === false)) {
                            $flashErr = (string) ($result['error'] ?? 'Module uninstall failed.');
                        }
                    } else {
                        $flashErr = 'Uninstall hook present but function missing: ' . $fn . '()';
                    }
                }

                if ($flashErr === '') {
                    $stmt = $conn->prepare(
                        "UPDATE modules
                         SET enabled=0, installed=0, updated_at=?
                         WHERE slug=? LIMIT 1"
                    );

                    if ($stmt) {
                        $stmt->bind_param('ss', $nowUtc, $slug);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $redirect();
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // DB state
    // ---------------------------------------------------------------------
    $state = [];

    $dbRows = $db->fetch_all('SELECT slug, installed, enabled, version, creator, has_admin FROM modules');
    if (is_array($dbRows)) {
        foreach ($dbRows as $r) {
            $s = (string) ($r['slug'] ?? '');
            if ($s !== '') {
                $state[$s] = $r;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Filesystem scan
    // ---------------------------------------------------------------------
    $items = [];

    if (is_dir($baseDir)) {
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = $slugClean(basename($dir));
            if ($slug === '' || $slug === 'home') {
                continue;
            }

            $meta = $metaRead($dir, $slug);
            $row  = $state[$slug] ?? null;

            $items[] = [
                'slug'      => $slug,
                'name'      => (string) $meta['name'],
                'version'   => (string) ($row['version'] ?? $meta['version']),
                'creator'   => (string) ($row['creator'] ?? $meta['creator']),
                'installed' => (int) ($row['installed'] ?? 0),
                'enabled'   => (int) ($row['enabled'] ?? 0),
                'has_admin' => (bool) $hasAdmin($slug),
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        $an = (string) ($a['name'] ?? '');
        $bn = (string) ($b['name'] ?? '');
        $c  = strcasecmp($an, $bn);
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? ''));
    });

    ?>
    <div class="container my-3 admin-modules">
        <div class="d-flex justify-content-between align-items-end mb-3">
            <div>
                <div class="small text-muted">Admin</div>
                <h1 class="h3 m-0">Modules</h1>
            </div>
        </div>

        <?php if ($flashOk !== ''): ?>
            <div class="alert alert-success"><?= $e($flashOk); ?></div>
        <?php endif; ?>

        <?php if ($flashErr !== ''): ?>
            <div class="alert alert-danger"><?= $e($flashErr); ?></div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div class="alert alert-secondary">No modules found in <code>/public/modules</code>.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle admin-table">
                    <thead>
                    <tr>
                        <th style="width: 34%;">Name</th>
                        <th style="width: 18%;">Slug</th>
                        <th style="width: 16%;">Status</th>
                        <th>Version · Creator</th>
                        <th class="text-end" style="width: 22%;">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $m): ?>
                        <?php
                        $slugH = $e((string) ($m['slug'] ?? ''));
                        $nameH = $e((string) ($m['name'] ?? ''));

                        $statusText = 'Not installed';
                        $badgeClass = 'badge text-bg-secondary';

                        if ((int) ($m['installed'] ?? 0) === 1 && (int) ($m['enabled'] ?? 0) === 1) {
                            $statusText = 'Enabled';
                            $badgeClass = 'badge text-bg-success';
                        } elseif ((int) ($m['installed'] ?? 0) === 1) {
                            $statusText = 'Installed';
                            $badgeClass = 'badge text-bg-warning';
                        }

                        $verH = $e((string) ($m['version'] ?? ''));
                        $creH = $e((string) ($m['creator'] ?? ''));
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= $nameH; ?></div>
                                <div class="small text-muted"><?= $e('/public/modules/' . (string) ($m['slug'] ?? '')); ?></div>
                            </td>
                            <td><code><?= $slugH; ?></code></td>
                            <td><span class="<?= $badgeClass; ?>"><?= $e($statusText); ?></span></td>
                            <td class="text-muted small"><?= $verH; ?> · <?= $creH; ?></td>
                            <td class="text-end">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf" value="<?= $e($csrf); ?>">
                                    <input type="hidden" name="slug" value="<?= $slugH; ?>">

                                    <?php if ((int) ($m['installed'] ?? 0) === 0): ?>
                                        <button class="btn btn-sm btn-outline-primary" name="do" value="install">Install</button>
                                    <?php else: ?>
                                        <?php if ((int) ($m['enabled'] ?? 0) === 1): ?>
                                            <button class="btn btn-sm btn-outline-secondary" name="do" value="disable">Disable</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-primary" name="do" value="enable">Enable</button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" name="do" value="uninstall">Uninstall</button>
                                    <?php endif; ?>
                                </form>

                                <?php if ((int) ($m['installed'] ?? 0) === 1 && (int) ($m['enabled'] ?? 0) === 1 && !empty($m['has_admin'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary ms-1"
                                       href="/admin?action=module_admin&amp;slug=<?= $slugH; ?>">Admin</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
})();

