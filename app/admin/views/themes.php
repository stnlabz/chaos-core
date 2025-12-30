<?php

declare(strict_types=1);

(function (): void {
    global $db;

    if (!$db instanceof db) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB not available.</div></div>';
        return;
    }

    $docroot   = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3), '/\\');
    $themesDir = $docroot . '/public/themes';

    $do   = (string)($_GET['do'] ?? '');
    $slug = (string)($_GET['slug'] ?? '');
    $slug = (string)preg_replace('~[^a-z0-9_\-]~i', '', $slug);

    $notice = '';
    $error  = '';

    $conn = $db->connect();
    if ($conn === false) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $active = 'default';
    $row = $db->fetch("SELECT value FROM settings WHERE name='site_theme' LIMIT 1");
    if (is_array($row) && isset($row['value']) && trim((string)$row['value']) !== '') {
        $active = trim((string)$row['value']);
    }

    $set_site_theme = static function (string $newTheme) use ($conn): bool {
        $sql = "UPDATE settings SET value=? WHERE name='site_theme' LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('s', $newTheme);
        $stmt->execute();

        if ($stmt->affected_rows < 1) {
            $stmt->close();

            $sql2 = "INSERT INTO settings (name, value) VALUES ('site_theme', ?)";
            $stmt2 = $conn->prepare($sql2);
            if ($stmt2 === false) {
                return false;
            }

            $stmt2->bind_param('s', $newTheme);
            $ok = $stmt2->execute();
            $stmt2->close();
            return $ok;
        }

        $stmt->close();
        return true;
    };

    $upsert_theme_row = static function (string $tSlug, string $version, string $creator) use ($conn): bool {
        $sql = "
            INSERT INTO themes (slug, installed, enabled, version, creator, created_at, updated_at)
            VALUES (?, 1, 0, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                installed=1,
                version=VALUES(version),
                creator=VALUES(creator),
                updated_at=NOW()
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('sss', $tSlug, $version, $creator);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    };

    if ($do !== '' && $slug !== '') {
        $themePath = $themesDir . '/' . $slug;

        if (!is_dir($themePath)) {
            $error = 'Theme not found on disk.';
        } else {
            $valid = is_file($themePath . '/header.php') && is_file($themePath . '/footer.php');

            if ($do === 'enable' && !$valid) {
                $error = 'Theme missing header.php/footer.php (invalid theme).';
            } else {
                $meta = [
                    'version' => 'v0.0.0',
                    'creator' => 'unknown',
                ];

                $metaPath = $themePath . '/meta.json';
                if (is_file($metaPath)) {
                    $raw = (string)@file_get_contents($metaPath);
                    $j = json_decode($raw, true);
                    if (is_array($j)) {
                        $meta['version'] = (string)($j['version'] ?? $meta['version']);
                        $meta['creator'] = (string)($j['creator'] ?? ($j['author'] ?? $meta['creator']));
                    }
                }

                if (!$upsert_theme_row($slug, (string)$meta['version'], (string)$meta['creator'])) {
                    $error = 'Failed to register theme in DB.';
                }

                if ($error === '') {
                    if ($do === 'install') {
                        $stmt = $conn->prepare("UPDATE themes SET installed=1, updated_at=NOW() WHERE slug=? LIMIT 1");
                        if ($stmt !== false) {
                            $stmt->bind_param('s', $slug);
                            $stmt->execute();
                            $stmt->close();
                        }
                        $notice = 'Theme installed: ' . $slug;
                    }

                    if ($do === 'uninstall') {
                        if (strtolower($slug) === 'default') {
                            $error = 'Default theme cannot be uninstalled.';
                        } else {
                            $stmt = $conn->prepare("UPDATE themes SET installed=0, enabled=0, updated_at=NOW() WHERE slug=? LIMIT 1");
                            if ($stmt !== false) {
                                $stmt->bind_param('s', $slug);
                                $stmt->execute();
                                $stmt->close();
                            }

                            if (strtolower($active) === strtolower($slug)) {
                                $set_site_theme('default');
                                $active = 'default';
                            }

                            $notice = 'Theme uninstalled: ' . $slug;
                        }
                    }

                    if ($do === 'enable') {
                        $conn->query("UPDATE themes SET enabled=0");

                        $stmt = $conn->prepare("UPDATE themes SET enabled=1, installed=1, updated_at=NOW() WHERE slug=? LIMIT 1");
                        if ($stmt === false) {
                            $error = 'DB prepare failed (enable).';
                        } else {
                            $stmt->bind_param('s', $slug);
                            $stmt->execute();
                            $stmt->close();
                        }

                        if ($error === '') {
                            if (!$set_site_theme($slug)) {
                                $error = 'Failed to write settings.site_theme.';
                            } else {
                                $active = $slug;
                                $notice = 'Theme enabled: ' . $slug;
                            }
                        }
                    }

                    if ($do === 'disable') {
                        if (strtolower($slug) === 'default') {
                            $error = 'Default theme cannot be disabled.';
                        } else {
                            $stmt = $conn->prepare("UPDATE themes SET enabled=0, updated_at=NOW() WHERE slug=? LIMIT 1");
                            if ($stmt !== false) {
                                $stmt->bind_param('s', $slug);
                                $stmt->execute();
                                $stmt->close();
                            }

                            if (strtolower($active) === strtolower($slug)) {
                                $set_site_theme('default');
                                $active = 'default';
                            }

                            $notice = 'Theme disabled: ' . $slug;
                        }
                    }
                }
            }
        }
    }

    $dbThemes = [];
    $rows = $db->fetch_all("SELECT slug, installed, enabled, version, creator FROM themes");
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $s = (string)($r['slug'] ?? '');
            if ($s === '') {
                continue;
            }
            $dbThemes[$s] = [
                'installed' => (int)($r['installed'] ?? 0),
                'enabled'   => (int)($r['enabled'] ?? 0),
                'version'   => (string)($r['version'] ?? ''),
                'creator'   => (string)($r['creator'] ?? ''),
            ];
        }
    }

    $themes = [];

    if (is_dir($themesDir)) {
        $items = scandir($themesDir);
        if (is_array($items)) {
            foreach ($items as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $themesDir . '/' . $name;
                if (!is_dir($path)) {
                    continue;
                }

                $tSlug = (string)$name;

                $meta = [
                    'name'    => $tSlug,
                    'version' => 'v0.0.0',
                    'creator' => 'unknown',
                ];

                $metaPath = $path . '/meta.json';
                if (is_file($metaPath)) {
                    $raw = (string)@file_get_contents($metaPath);
                    $j = json_decode($raw, true);
                    if (is_array($j)) {
                        $meta['name']    = (string)($j['name'] ?? $meta['name']);
                        $meta['version'] = (string)($j['version'] ?? $meta['version']);
                        $meta['creator'] = (string)($j['creator'] ?? ($j['author'] ?? $meta['creator']));
                    }
                }

                $valid = is_file($path . '/header.php') && is_file($path . '/footer.php');

                $state = $dbThemes[$tSlug] ?? [
                    'installed' => 0,
                    'enabled'   => 0,
                    'version'   => '',
                    'creator'   => '',
                ];

                $themes[] = [
                    'slug'      => $tSlug,
                    'name'      => (string)$meta['name'],
                    'version'   => (string)($state['version'] !== '' ? $state['version'] : $meta['version']),
                    'creator'   => (string)($state['creator'] !== '' ? $state['creator'] : $meta['creator']),
                    'valid'     => $valid,
                    'installed' => (int)$state['installed'] === 1,
                    'enabled'   => (int)$state['enabled'] === 1,
                    'active'    => strtolower($tSlug) === strtolower($active),
                ];
            }
        }
    }

    usort($themes, static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    echo '<div class="container my-4">';
    echo '<small><a href="/admin">Admin</a> &raquo; Themes</small>';
    echo '<h1 class="h3 mt-2 mb-3">Themes</h1>';

    if ($error !== '') {
        echo '<div class="alert alert-danger small mb-2">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    } elseif ($notice !== '') {
        echo '<div class="alert alert-success small mb-2">' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    echo '<div class="small text-muted mb-3">Active theme: <strong>' . htmlspecialchars($active, ENT_QUOTES, 'UTF-8') . '</strong></div>';

    if (!$themes) {
        echo '<div class="alert alert-secondary">No themes found in <code>/public/themes</code>.</div>';
        echo '</div>';
        return;
    }

    echo '<div class="row">';

    foreach ($themes as $t) {
        $tSlug = (string)$t['slug'];

        echo '<div class="col-12 col-md-6 col-lg-3 mb-3">';
        echo '  <div class="card admin-card" style="height:100%;">';
        echo '    <div class="card-body">';
        echo '      <div class="fw-semibold">' . htmlspecialchars((string)$t['name'], ENT_QUOTES, 'UTF-8') . '</div>';
        echo '      <div class="small text-muted">' . htmlspecialchars($tSlug, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '      <div class="small text-muted mt-1">' . htmlspecialchars((string)$t['version'], ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars((string)$t['creator'], ENT_QUOTES, 'UTF-8') . '</div>';

        echo '      <div class="small mt-2">';
        echo $t['valid'] ? '<span class="badge bg-success">Valid</span>' : '<span class="badge bg-danger">Invalid</span>';
        echo ' ';
        echo $t['installed'] ? '<span class="badge bg-primary">Installed</span>' : '<span class="badge bg-secondary">Not Installed</span>';
        echo ' ';
        echo $t['enabled'] ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Disabled</span>';
        echo '      </div>';

        echo '      <div class="mt-3 d-flex flex-wrap gap-2">';

        if ($t['installed']) {
            if (strtolower($tSlug) !== 'default') {
                echo '<a class="btn btn-sm btn-outline-danger admin-btn admin-btn-danger" href="/admin?action=themes&amp;do=uninstall&amp;slug='
                    . htmlspecialchars($tSlug, ENT_QUOTES, 'UTF-8')
                    . '">Uninstall</a>';
            }
        } else {
            echo '<a class="btn btn-sm btn-outline-primary admin-btn admin-btn-primary" href="/admin?action=themes&amp;do=install&amp;slug='
                . htmlspecialchars($tSlug, ENT_QUOTES, 'UTF-8')
                . '">Install</a>';
        }

        if ($t['active']) {
            echo '<span class="btn btn-sm btn-success admin-btn admin-btn-ok" style="cursor:default;">Active</span>';
            if (strtolower($tSlug) !== 'default') {
                echo '<a class="btn btn-sm btn-outline-warning admin-btn admin-btn-warn" href="/admin?action=themes&amp;do=disable&amp;slug='
                    . htmlspecialchars($tSlug, ENT_QUOTES, 'UTF-8')
                    . '">Disable</a>';
            }
        } else {
            if ($t['installed'] && $t['valid']) {
                echo '<a class="btn btn-sm btn-primary admin-btn admin-btn-primary" href="/admin?action=themes&amp;do=enable&amp;slug='
                    . htmlspecialchars($tSlug, ENT_QUOTES, 'UTF-8')
                    . '">Enable</a>';
            }
            if ($t['enabled'] && strtolower($tSlug) !== 'default') {
                echo '<a class="btn btn-sm btn-outline-warning admin-btn admin-btn-warn" href="/admin?action=themes&amp;do=disable&amp;slug='
                    . htmlspecialchars($tSlug, ENT_QUOTES, 'UTF-8')
                    . '">Disable</a>';
            }
        }

        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';
})();

