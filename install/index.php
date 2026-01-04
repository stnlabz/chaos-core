<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Installer
 *
 * Goals:
 * - Dead simple setup (KISS)
 * - Safe schema creation (IF NOT EXISTS)
 * - Seed roles + default theme + settings
 * - Create first admin (role_id=4)
 * - Write /app/config/config.json LAST
 * - Redirect to /login on success
 * - Attempt to remove /install after success
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * HTML escape.
 */
function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Ensure a directory exists.
 */
function ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0755, true);
}

/**
 * Recursive delete directory.
 */
function rrmdir(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }

        @unlink($path);
    }

    return @rmdir($dir);
}

/**
 * Redirect and exit.
 */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

$errors = [];
$notice = '';

$defaults = [
    'db_host'     => 'localhost',
    'db_user'     => '',
    'db_pass'     => '',
    'db_name'     => '',
    'admin_user'  => '',
    'admin_email' => '',
];

$values = $defaults;

$docroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), "/\\");
$appRoot = $docroot . '/app';

$cfgDir  = $appRoot . '/config';
$cfgPath = $cfgDir . '/config.json';

$installed = is_file($cfgPath);

/**
 * CSRF (kept minimal; can be removed if you want).
 */
if (!isset($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}

$csrf = (string)($_SESSION['install_csrf'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $values['db_host']     = trim((string)($_POST['db_host'] ?? 'localhost'));
    $values['db_user']     = trim((string)($_POST['db_user'] ?? ''));
    $values['db_pass']     = (string)($_POST['db_pass'] ?? '');
    $values['db_name']     = trim((string)($_POST['db_name'] ?? ''));

    $values['admin_user']  = trim((string)($_POST['admin_user'] ?? ''));
    $values['admin_email'] = trim((string)($_POST['admin_email'] ?? ''));
    $adminPass             = (string)($_POST['admin_pass'] ?? '');

    $postedCsrf = (string)($_POST['csrf'] ?? '');

    if ($installed) {
        $errors[] = 'Installer locked: /app/config/config.json exists. Remove it to re-run.';
    }

    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $errors[] = 'Invalid request token.';
    }

    if ($values['db_host'] === '' || $values['db_user'] === '' || $values['db_name'] === '') {
        $errors[] = 'DB host, user, and database name are required.';
    }

    if ($values['admin_user'] === '' || $adminPass === '') {
        $errors[] = 'Admin username and password are required.';
    }

    if (!$errors) {
        $db = @new mysqli($values['db_host'], $values['db_user'], $values['db_pass'], $values['db_name']);

        if ($db->connect_errno) {
            $errors[] = 'DB connection failed: ' . (string)$db->connect_error;
        } else {
            $db->set_charset('utf8mb4');

            try {
                $db->begin_transaction();

                // ---------------------------------------------------------
                // 1) schema
                // ---------------------------------------------------------
                $schema = [];

                $schema[] = "
                    CREATE TABLE IF NOT EXISTS settings (
                      id INT(11) NOT NULL AUTO_INCREMENT,
                      name VARCHAR(255) NOT NULL,
                      value TEXT NOT NULL,
                      PRIMARY KEY (id),
                      UNIQUE KEY name (name)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ";

                $schema[] = "
                    CREATE TABLE IF NOT EXISTS roles (
                      id INT(11) NOT NULL AUTO_INCREMENT,
                      slug TEXT NOT NULL,
                      label TEXT NOT NULL,
                      PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ";

                $schema[] = "
                    CREATE TABLE IF NOT EXISTS users (
                      id INT(11) NOT NULL AUTO_INCREMENT,
                      username VARCHAR(255) NOT NULL,
                      email VARCHAR(255) NOT NULL DEFAULT '',
                      role_id INT(11) NOT NULL DEFAULT 1,
                      password_hash VARCHAR(255) NOT NULL,
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                      role INT(11) NOT NULL DEFAULT 1,
                      PRIMARY KEY (id),
                      UNIQUE KEY username (username),
                      KEY idx_role_id (role_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ";

                $schema[] = "
                    CREATE TABLE IF NOT EXISTS themes (
                      id INT(11) NOT NULL AUTO_INCREMENT,
                      slug VARCHAR(255) NOT NULL,
                      installed TINYINT(1) NOT NULL DEFAULT 1,
                      enabled TINYINT(1) NOT NULL DEFAULT 0,
                      version VARCHAR(50) NOT NULL DEFAULT 'v0.0.0',
                      creator VARCHAR(255) NOT NULL DEFAULT 'unknown',
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                      PRIMARY KEY (id),
                      UNIQUE KEY slug (slug)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
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
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                      PRIMARY KEY (id),
                      UNIQUE KEY slug (slug)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
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
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                      PRIMARY KEY (id),
                      UNIQUE KEY slug (slug)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ";

                foreach ($schema as $sql) {
                    if ($db->query($sql) !== true) {
                        throw new RuntimeException('Schema error: ' . (string)$db->error);
                    }
                }

                // ---------------------------------------------------------
                // 2) FK (safe attempt)
                // ---------------------------------------------------------
                @$db->query("
                    ALTER TABLE users
                    ADD CONSTRAINT fk_users_roles
                    FOREIGN KEY (role_id) REFERENCES roles(id)
                    ON UPDATE CASCADE
                ");

                // ---------------------------------------------------------
                // 3) seed roles (admin = 4)
                // ---------------------------------------------------------
                $seedRoles = [
                    [1, 'user', 'User'],
                    [2, 'editor', 'Editor'],
                    [3, 'moderator', 'Moderator'],
                    [4, 'admin', 'Admin'],
                ];

                $stmtRole = $db->prepare("
                    INSERT INTO roles (id, slug, label)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE slug=VALUES(slug), label=VALUES(label)
                ");

                if ($stmtRole === false) {
                    throw new RuntimeException('Role seed prepare failed.');
                }

                foreach ($seedRoles as $r) {
                    $id    = (int)$r[0];
                    $slug  = (string)$r[1];
                    $label = (string)$r[2];

                    $stmtRole->bind_param('iss', $id, $slug, $label);

                    if (!$stmtRole->execute()) {
                        throw new RuntimeException('Role seed failed: ' . (string)$stmtRole->error);
                    }
                }

                $stmtRole->close();

                // ---------------------------------------------------------
                // 4) seed settings + themes
                // ---------------------------------------------------------
                $db->query("
                    INSERT INTO settings (name, value)
                    VALUES ('site_theme', 'default')
                    ON DUPLICATE KEY UPDATE value=VALUES(value)
                ");

                $db->query("
                    INSERT INTO themes (slug, installed, enabled, version, creator)
                    VALUES ('default', 1, 1, 'v0.0.0', 'default')
                    ON DUPLICATE KEY UPDATE enabled=1, installed=1
                ");

                // ---------------------------------------------------------
                // 5) create admin user (role_id=4)
                // ---------------------------------------------------------
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                if (!is_string($hash) || $hash === '') {
                    throw new RuntimeException('Password hashing failed.');
                }

                $roleId = 4;
                $role   = 4;

                $stmtUser = $db->prepare("
                    INSERT INTO users (username, email, role_id, password_hash, role)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        email=VALUES(email),
                        role_id=VALUES(role_id),
                        password_hash=VALUES(password_hash),
                        role=VALUES(role)
                ");

                if ($stmtUser === false) {
                    throw new RuntimeException('Admin seed prepare failed.');
                }

                $stmtUser->bind_param('ssisi', $values['admin_user'], $values['admin_email'], $roleId, $hash, $role);

                if (!$stmtUser->execute()) {
                    throw new RuntimeException('Admin create failed: ' . (string)$stmtUser->error);
                }

                $stmtUser->close();

                // ---------------------------------------------------------
                // 6) write config LAST
                // ---------------------------------------------------------
                if (!ensure_dir($cfgDir)) {
                    throw new RuntimeException('Cannot create /app/config directory.');
                }

                $config = [
                    'db' => [
                        'host' => $values['db_host'],
                        'user' => $values['db_user'],
                        'pass' => $values['db_pass'],
                        'name' => $values['db_name'],
                    ],
                ];

                $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || @file_put_contents($cfgPath, $json . PHP_EOL) === false) {
                    throw new RuntimeException('Failed to write /app/config/config.json (permissions?).');
                }

                $db->commit();

                // cleanup session token
                unset($_SESSION['install_csrf']);

                // 6.5) attempt to delete /install after this request ends
                $installDir = __DIR__;
                if (basename($installDir) === 'install') {
                    register_shutdown_function(static function () use ($installDir): void {
                        rrmdir($installDir);
                    });
                }

                // 7) redirect
                redirect('/login');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = $e->getMessage();
            }

            $db->close();
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chaos CMS Installer</title>
    <style>
        body { font-family: system-ui, Arial, sans-serif; background: #0c0f14; color: #e6eaf2; margin: 0; }
        .wrap { max-width: 860px; margin: 28px auto; padding: 18px; background: #121826; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; }
        .row { display: flex; gap: 14px; flex-wrap: wrap; }
        .col { flex: 1 1 260px; }
        label { display: block; font-size: 12px; font-weight: 700; margin: 10px 0 4px; color: #b7c0d6; }
        input { width: 100%; padding: 10px; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px; background: #0f1420; color: #e6eaf2; }
        button { margin-top: 14px; padding: 10px 14px; border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px; cursor: pointer; background: #1f2a44; color: #e6eaf2; font-weight: 700; }
        .alert { padding: 10px 12px; border-radius: 10px; margin: 12px 0; font-size: 13px; border: 1px solid rgba(255, 255, 255, 0.12); }
        .alert-danger { background: rgba(255, 80, 80, 0.12); }
        .small { font-size: 12px; color: #b7c0d6; }
        code { background: rgba(255, 255, 255, 0.08); padding: 2px 6px; border-radius: 8px; }
        ul { margin: 6px 0 0 18px; }
    </style>
</head>
<body>
<div class="wrap">
    <h2>Chaos CMS Installer</h2>

    <?php if ($installed): ?>
        <div class="alert alert-danger">
            Installer locked: <code>/app/config/config.json</code> exists.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Installer failed</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= h((string)$e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf" value="<?= h($csrf); ?>">

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

        <button type="submit" <?= $installed ? 'disabled' : ''; ?>>Install</button>
    </form>

    <p class="small" style="margin-top:16px;">
        After install you will be redirected to <code>/login</code>. The installer will attempt to delete <code>/install</code>.
    </p>
</div>
</body>
</html>

