<?php

declare(strict_types=1);

/**
 * Chaos CMS Installer
 *
 * - Writes /app/config/config.json
 * - Creates DB schema (tables)
 * - Creates first admin user (role_id=4, role=4)
 * - Preps updater dirs + update.json (best-effort)
 * - Creates /app/data/version.json if missing (best-effort)
 * - Attempts to delete /install (best-effort)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['chaos_install_csrf']) || !is_string($_SESSION['chaos_install_csrf'])) {
        $_SESSION['chaos_install_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['chaos_install_csrf'];
}

function csrf_check(string $token): bool
{
    return isset($_SESSION['chaos_install_csrf'])
        && is_string($_SESSION['chaos_install_csrf'])
        && hash_equals($_SESSION['chaos_install_csrf'], $token);
}

function ensure_dir(string $dir, int $mode = 0755): bool
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, $mode, true);
}

/**
 * @param array<string,mixed> $data
 */
function write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return @file_put_contents($path, $json . PHP_EOL) !== false;
}

function rrmdir(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $items = @scandir($dir);
    if (!is_array($items)) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            if (!rrmdir($path)) {
                return false;
            }
            continue;
        }

        if (!@unlink($path)) {
            return false;
        }
    }

    return @rmdir($dir);
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * @param array<int,string> $sqlStatements
 *
 * @return array{ok:bool,error:string}
 */
function run_schema(mysqli $db, array $sqlStatements): array
{
    foreach ($sqlStatements as $sql) {
        $ok = $db->query($sql);
        if ($ok !== true) {
            return [
                'ok' => false,
                'error' => (string) $db->error,
            ];
        }
    }

    return [
        'ok' => true,
        'error' => '',
    ];
}

$docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/\\');
$appRoot = $docroot . '/app';

$cfgDir  = $appRoot . '/config';
$cfgPath = $cfgDir . '/config.json';

$dataDir = $appRoot . '/data';

$updateRoot = $appRoot . '/update';
$updateCfg  = $updateRoot . '/update.json';

$errors  = [];
$notices = [];

$values = [
    'db_host' => 'localhost',
    'db_user' => '',
    'db_pass' => '',
    'db_name' => '',
    'admin_user' => '',
    'admin_email' => '',
];

if (is_file($cfgPath)) {
    echo '<div style="max-width:860px;margin:28px auto;font-family:system-ui,Arial;">';
    echo '<h2>Installer Locked</h2>';
    echo '<p>Config already exists at <code>' . h($cfgPath) . '</code>.</p>';
    echo '</div>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!csrf_check($token)) {
        $errors[] = 'Invalid CSRF token.';
    }

    $values['db_host'] = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $values['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
    $values['db_pass'] = (string) ($_POST['db_pass'] ?? '');
    $values['db_name'] = trim((string) ($_POST['db_name'] ?? ''));

    $values['admin_user']  = trim((string) ($_POST['admin_user'] ?? ''));
    $values['admin_email'] = trim((string) ($_POST['admin_email'] ?? ''));
    $admin_pass            = (string) ($_POST['admin_pass'] ?? '');

    if ($values['db_host'] === '' || $values['db_user'] === '' || $values['db_name'] === '') {
        $errors[] = 'DB host, user, and database name are required.';
    }

    if ($values['admin_user'] === '' || $admin_pass === '') {
        $errors[] = 'Admin username and password are required.';
    }

    if (!$errors) {
        if (!ensure_dir($cfgDir)) {
            $errors[] = 'Failed to create /app/config directory. Check permissions.';
        } else {
            $cfg = [
                'host' => $values['db_host'],
                'user' => $values['db_user'],
                'pass' => $values['db_pass'],
                'name' => $values['db_name'],
            ];

            if (!write_json($cfgPath, $cfg)) {
                $errors[] = 'Failed to write /app/config/config.json. Check permissions.';
            } else {
                $db = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
                if ($db->connect_errno) {
                    $errors[] = 'DB connection failed: ' . (string) $db->connect_error;
                } else {
                    $schema = [];

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS settings (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          name VARCHAR(255) NOT NULL,
                          value TEXT NOT NULL,
                          PRIMARY KEY (id),
                          UNIQUE KEY name (name)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS media_files (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          filename VARCHAR(255) NOT NULL,
                          rel_path VARCHAR(255) NOT NULL,
                          mime VARCHAR(128) NOT NULL,
                          size_bytes INT(11) NOT NULL DEFAULT 0,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (id)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=latin1
                          COLLATE=latin1_swedish_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS media_gallery (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          file_id INT(11) NOT NULL,
                          title VARCHAR(255) NOT NULL DEFAULT '',
                          caption TEXT DEFAULT NULL,
                          visibility TINYINT(1) NOT NULL DEFAULT 0,
                          status TINYINT(1) NOT NULL DEFAULT 1,
                          sort_order INT(11) NOT NULL DEFAULT 0,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          UNIQUE KEY uq_media_gallery_file (file_id)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=latin1
                          COLLATE=latin1_swedish_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS modules (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          name TEXT NOT NULL,
                          slug VARCHAR(255) NOT NULL,
                          installed TINYINT(1) NOT NULL DEFAULT 1,
                          enabled TINYINT(1) NOT NULL DEFAULT 0,
                          version VARCHAR(50) NOT NULL DEFAULT 'v0.0.0',
                          creator VARCHAR(255) NOT NULL DEFAULT 'unknown',
                          has_admin TINYINT(1) NOT NULL DEFAULT 0,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          UNIQUE KEY slug (slug)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS plugins (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          name TEXT NOT NULL,
                          slug VARCHAR(255) NOT NULL,
                          installed TINYINT(1) NOT NULL DEFAULT 1,
                          enabled TINYINT(1) NOT NULL DEFAULT 0,
                          version VARCHAR(50) NOT NULL DEFAULT 'v0.0.0',
                          creator VARCHAR(255) NOT NULL DEFAULT 'unknown',
                          has_admin TINYINT(1) NOT NULL DEFAULT 0,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          UNIQUE KEY slug (slug)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS pages (
                          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                          slug VARCHAR(190) NOT NULL,
                          title VARCHAR(255) NOT NULL,
                          format VARCHAR(16) NOT NULL DEFAULT 'html',
                          body MEDIUMTEXT NOT NULL,
                          status TINYINT(1) NOT NULL DEFAULT 0,
                          visibility TINYINT(1) NOT NULL DEFAULT 0,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          UNIQUE KEY uniq_slug (slug),
                          KEY idx_status_vis (status, visibility)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS posts (
                          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                          slug VARCHAR(190) NOT NULL,
                          title VARCHAR(255) NOT NULL,
                          body MEDIUMTEXT NOT NULL,
                          excerpt TEXT DEFAULT NULL,
                          status TINYINT(1) NOT NULL DEFAULT 0,
                          visibility TINYINT(1) NOT NULL DEFAULT 0,
                          topic VARCHAR(64) DEFAULT NULL,
                          author_id INT(10) UNSIGNED DEFAULT NULL,
                          topic_id INT(10) UNSIGNED DEFAULT NULL,
                          published_at DATETIME DEFAULT NULL,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          KEY idx_topic_id (topic_id)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS post_replies (
                          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                          post_id INT(10) UNSIGNED NOT NULL,
                          parent_id INT(10) UNSIGNED DEFAULT NULL,
                          author_id INT(10) UNSIGNED NOT NULL,
                          body TEXT NOT NULL,
                          status TINYINT(1) NOT NULL DEFAULT 1,
                          visibility TINYINT(1) NOT NULL DEFAULT 0,
                          created_at DATETIME NOT NULL,
                          updated_at DATETIME NOT NULL,
                          PRIMARY KEY (id)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS roles (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          slug TEXT NOT NULL,
                          label TEXT NOT NULL,
                          PRIMARY KEY (id)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=latin1
                          COLLATE=latin1_swedish_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS themes (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          slug VARCHAR(255) NOT NULL,
                          installed TINYINT(1) NOT NULL DEFAULT 1,
                          enabled TINYINT(1) NOT NULL DEFAULT 0,
                          version VARCHAR(50) NOT NULL DEFAULT 'v0.0.0',
                          creator VARCHAR(255) NOT NULL DEFAULT 'unknown',
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          UNIQUE KEY slug (slug)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS topics (
                          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                          slug VARCHAR(190) NOT NULL,
                          label VARCHAR(255) NOT NULL,
                          is_public TINYINT(1) NOT NULL DEFAULT 1,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (id),
                          UNIQUE KEY uniq_slug (slug)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $schema[] = "
                        CREATE TABLE IF NOT EXISTS users (
                          id INT(11) NOT NULL AUTO_INCREMENT,
                          username VARCHAR(255) NOT NULL,
                          email VARCHAR(255) NOT NULL DEFAULT '',
                          role_id INT(11) NOT NULL DEFAULT 1,
                          password_hash VARCHAR(255) NOT NULL,
                          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          role INT(11) NOT NULL DEFAULT 1,
                          PRIMARY KEY (id),
                          UNIQUE KEY username (username),
                          KEY fk_users_role (role),
                          KEY idx_role_id (role_id)
                        ) ENGINE=InnoDB
                          DEFAULT CHARSET=utf8mb4
                          COLLATE=utf8mb4_general_ci
                    ";

                    $result = run_schema($db, $schema);
                    if (!$result['ok']) {
                        $errors[] = 'Schema error: ' . $result['error'];
                    } else {
                        $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                        if (!is_string($hash) || $hash === '') {
                            $errors[] = 'Password hashing failed.';
                        } else {
                            $roleId = 4;
                            $role   = 4;

                            $stmt = $db->prepare("
                                INSERT INTO users (username, email, role_id, password_hash, role)
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            if ($stmt === false) {
                                $errors[] = 'Failed to prepare admin user insert.';
                            } else {
                                $stmt->bind_param('ssisi', $values['admin_user'], $values['admin_email'], $roleId, $hash, $role);

                                if (!$stmt->execute()) {
                                    $errors[] = 'Failed to create admin user: ' . (string) $stmt->error;
                                }

                                $stmt->close();
                            }
                        }
                    }

                    $db->close();
                }
            }
        }
    }

    if (!$errors) {
        if (!ensure_dir($dataDir)) {
            $notices[] = 'Notice: could not create /app/data.';
        } else {
            $verPath = $dataDir . '/version.json';
            if (!is_file($verPath)) {
                if (!write_json($verPath, ['version' => '0.0.0', 'updated_at' => gmdate('c')])) {
                    $notices[] = 'Notice: could not write /app/data/version.json.';
                }
            }
        }

        $updDirs = [
            $updateRoot,
            $updateRoot . '/logs',
            $updateRoot . '/packages',
            $updateRoot . '/stage',
            $updateRoot . '/backup',
        ];

        foreach ($updDirs as $dir) {
            if (!ensure_dir($dir)) {
                $notices[] = 'Notice: could not create ' . $dir . '.';
                continue;
            }

            @chmod($dir, 0775);
        }

        if (!is_file($updateCfg)) {
            if (!write_json($updateCfg, [
                'manifest_url' => 'https://version.chaoscms.org/db/version.json',
                'timeout_seconds' => 10,
                'channel' => 'stable',
            ])) {
                $notices[] = 'Notice: could not write /app/update/update.json.';
            }
        }

        $installDir = __DIR__;
        register_shutdown_function(static function () use ($installDir): void {
            rrmdir($installDir);
        });

        $_SESSION['chaos_install_success'] = true;
        redirect_to('/install/?done=1');
    }
}

$done = ((string) ($_GET['done'] ?? '') === '1') && !empty($_SESSION['chaos_install_success']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chaos CMS Installer</title>
    <style>
        body {
            font-family: system-ui, Arial;
            background: #0b0f14;
            color: #e8eef7;
            margin: 0;
        }
        .wrap {
            max-width: 900px;
            margin: 28px auto;
            padding: 18px;
            background: #111826;
            border: 1px solid #1f2a3a;
            border-radius: 12px;
        }
        .row { display: flex; gap: 14px; flex-wrap: wrap; }
        .col { flex: 1 1 260px; }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin: 10px 0 4px;
            color: #b7c3d6;
        }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #2a3a52;
            border-radius: 10px;
            background: #0b1220;
            color: #e8eef7;
        }
        button {
            margin-top: 14px;
            padding: 10px 14px;
            border: 1px solid #2a3a52;
            border-radius: 10px;
            cursor: pointer;
            background: #16243a;
            color: #e8eef7;
        }
        .alert {
            padding: 10px 12px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 13px;
        }
        .danger { background: #2a1212; border: 1px solid #5a2222; }
        .warn { background: #2a2412; border: 1px solid #5a4b22; }
        .ok { background: #132a18; border: 1px solid #235a31; }
        code {
            background: #0b1220;
            padding: 2px 6px;
            border-radius: 8px;
            border: 1px solid #22314a;
        }
        .small { font-size: 12px; color: #b7c3d6; }
    </style>
</head>
<body>
<div class="wrap">
    <h2>Chaos CMS Installer</h2>
    <p class="small">Creates DB schema and creates the first admin user (admin=4).</p>

    <?php if ($done): ?>
        <div class="alert ok">
            <strong>Install complete.</strong><br>
            Config written, tables created, admin user created.
        </div>

        <?php if ($notices): ?>
            <div class="alert warn">
                <strong>Notices</strong>
                <ul>
                    <?php foreach ($notices as $n): ?>
                        <li><?= h((string) $n); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p><a href="/login" style="color:#8ab4ff;">Go to Login</a></p>
    <?php else: ?>

        <?php if ($errors): ?>
            <div class="alert danger">
                <strong>Installer failed</strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= h((string) $e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/install/">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()); ?>">

            <h3>Database</h3>
            <div class="row">
                <div class="col">
                    <label for="db_host">Host</label>
                    <input id="db_host" name="db_host" value="<?= h($values['db_host']); ?>" required>
                </div>
                <div class="col">
                    <label for="db_user">User</label>
                    <input id="db_user" name="db_user" value="<?= h($values['db_user']); ?>" required>
                </div>
                <div class="col">
                    <label for="db_pass">Pass</label>
                    <input id="db_pass" name="db_pass" value="<?= h($values['db_pass']); ?>">
                </div>
                <div class="col">
                    <label for="db_name">Database</label>
                    <input id="db_name" name="db_name" value="<?= h($values['db_name']); ?>" required>
                </div>
            </div>

            <h3 style="margin-top:18px;">First Admin User</h3>
            <div class="row">
                <div class="col">
                    <label for="admin_user">Username</label>
                    <input id="admin_user" name="admin_user" value="<?= h($values['admin_user']); ?>" required>
                </div>
                <div class="col">
                    <label for="admin_email">Email (optional)</label>
                    <input id="admin_email" name="admin_email" value="<?= h($values['admin_email']); ?>">
                </div>
                <div class="col">
                    <label for="admin_pass">Password</label>
                    <input id="admin_pass" name="admin_pass" type="password" required>
                </div>
            </div>

            <button type="submit">Install</button>
        </form>

        <p class="small" style="margin-top:16px;">
            After success, installer attempts to delete <code>/install</code>.
        </p>
    <?php endif; ?>
</div>
</body>
</html>

