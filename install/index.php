<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0755, true);
}

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

function redirect(string $to): void
{
    header('Location: ' . $to, true, 302);
    exit;
}

function exec_stmt(mysqli $db, string $sql): bool
{
    $ok = $db->query($sql);

    if ($ok === true) {
        return true;
    }

    $errno = (int) $db->errno;

    // Re-run safe:
    // 1050 table exists
    // 1060 dup column
    // 1061 dup key
    // 1068 multiple primary key (should not happen now, but keep safe)
    // 1091 drop missing
    // 121/1022 duplicates
    $safe = [1050, 1060, 1061, 1068, 1091, 121, 1022];

    if (in_array($errno, $safe, true)) {
        return true;
    }

    return false;
}

/**
 * @param array<string,mixed> $data
 */
function write_config_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return @file_put_contents($path, $json . PHP_EOL) !== false;
}

$siteRoot = realpath(__DIR__ . '/..');
if (!is_string($siteRoot) || $siteRoot === '') {
    $siteRoot = dirname(__DIR__);
}

$docroot = rtrim($siteRoot, "/\\");
$appRoot = $docroot . '/app';

$cfgDir  = $appRoot . '/config';
$cfgPath = $cfgDir . '/config.json';

$force     = (isset($_GET['force']) && (string) $_GET['force'] === '1');
$installed = is_file($cfgPath);

$errors = [];

$values = [
    'db_host'     => 'localhost',
    'db_user'     => '',
    'db_pass'     => '',
    'db_name'     => '',
    'admin_user'  => '',
    'admin_email' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $values['db_host']     = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $values['db_user']     = trim((string) ($_POST['db_user'] ?? ''));
    $values['db_pass']     = (string) ($_POST['db_pass'] ?? '');
    $values['db_name']     = trim((string) ($_POST['db_name'] ?? ''));

    $values['admin_user']  = trim((string) ($_POST['admin_user'] ?? ''));
    $values['admin_email'] = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPass             = (string) ($_POST['admin_pass'] ?? '');

    if ($installed && !$force) {
        $errors[] = 'Installer locked: /app/config/config.json exists. Use /install/?force=1 to override.';
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
            $errors[] = 'DB connection failed: ' . (string) $db->connect_error;
        } else {
            $db->set_charset('utf8mb4');

            try {
                $db->begin_transaction();

                $creates = [
                    "CREATE TABLE IF NOT EXISTS `settings` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `name` varchar(255) NOT NULL,
                        `value` text NOT NULL,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `name` (`name`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `roles` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `slug` text NOT NULL,
                        `label` text NOT NULL,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci",

                    "CREATE TABLE IF NOT EXISTS `users` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `username` varchar(255) NOT NULL,
                        `email` varchar(255) NOT NULL DEFAULT '',
                        `role_id` int(11) NOT NULL DEFAULT 1,
                        `password_hash` varchar(255) NOT NULL,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        `role` int(11) NOT NULL DEFAULT 1,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `username` (`username`),
                        KEY `fk_users_role` (`role`),
                        KEY `idx_role_id` (`role_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `topics` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `slug` varchar(190) NOT NULL,
                        `label` varchar(255) NOT NULL,
                        `is_public` tinyint(1) NOT NULL DEFAULT 1,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uniq_slug` (`slug`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `pages` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `slug` varchar(190) NOT NULL,
                        `title` varchar(255) NOT NULL,
                        `format` varchar(16) NOT NULL DEFAULT 'html',
                        `body` mediumtext NOT NULL,
                        `status` tinyint(1) NOT NULL DEFAULT 0,
                        `visibility` tinyint(1) NOT NULL DEFAULT 0,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uniq_slug` (`slug`),
                        KEY `idx_status_vis` (`status`,`visibility`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `posts` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `slug` varchar(190) NOT NULL,
                        `title` varchar(255) NOT NULL,
                        `body` mediumtext NOT NULL,
                        `excerpt` text DEFAULT NULL,
                        `status` tinyint(1) NOT NULL DEFAULT 0,
                        `visibility` tinyint(1) NOT NULL DEFAULT 0,
                        `topic` varchar(64) DEFAULT NULL,
                        `author_id` int(10) UNSIGNED DEFAULT NULL,
                        `topic_id` int(10) UNSIGNED DEFAULT NULL,
                        `published_at` datetime DEFAULT NULL,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `idx_topic_id` (`topic_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `post_replies` (
                        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `post_id` int(10) UNSIGNED NOT NULL,
                        `parent_id` int(10) UNSIGNED DEFAULT NULL,
                        `author_id` int(10) UNSIGNED NOT NULL,
                        `body` text NOT NULL,
                        `status` tinyint(1) NOT NULL DEFAULT 1,
                        `visibility` tinyint(1) NOT NULL DEFAULT 0,
                        `created_at` datetime NOT NULL,
                        `updated_at` datetime NOT NULL,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `media_files` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `filename` varchar(255) NOT NULL,
                        `rel_path` varchar(255) NOT NULL,
                        `mime` varchar(128) NOT NULL,
                        `size_bytes` int(11) NOT NULL DEFAULT 0,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci",

                    "CREATE TABLE IF NOT EXISTS `media_gallery` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `file_id` int(11) NOT NULL,
                        `title` varchar(255) NOT NULL DEFAULT '',
                        `caption` text DEFAULT NULL,
                        `visibility` tinyint(1) NOT NULL DEFAULT 0,
                        `status` tinyint(1) NOT NULL DEFAULT 1,
                        `sort_order` int(11) NOT NULL DEFAULT 0,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uq_media_gallery_file` (`file_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci",

                    "CREATE TABLE IF NOT EXISTS `modules` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `name` text NOT NULL,
                        `slug` varchar(255) NOT NULL,
                        `installed` tinyint(1) NOT NULL DEFAULT 1,
                        `enabled` tinyint(1) NOT NULL DEFAULT 0,
                        `version` varchar(50) NOT NULL DEFAULT 'v0.0.0',
                        `creator` varchar(255) NOT NULL DEFAULT 'unknown',
                        `has_admin` tinyint(1) NOT NULL DEFAULT 0,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `slug` (`slug`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `plugins` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `name` text NOT NULL,
                        `slug` varchar(255) NOT NULL,
                        `installed` tinyint(1) NOT NULL DEFAULT 1,
                        `enabled` tinyint(1) NOT NULL DEFAULT 0,
                        `version` varchar(50) NOT NULL DEFAULT 'v0.0.0',
                        `creator` varchar(255) NOT NULL DEFAULT 'unknown',
                        `has_admin` tinyint(1) NOT NULL DEFAULT 0,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `slug` (`slug`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

                    "CREATE TABLE IF NOT EXISTS `themes` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `slug` varchar(255) NOT NULL,
                        `installed` tinyint(1) NOT NULL DEFAULT 1,
                        `enabled` tinyint(1) NOT NULL DEFAULT 0,
                        `version` varchar(50) NOT NULL DEFAULT 'v0.0.0',
                        `creator` varchar(255) NOT NULL DEFAULT 'unknown',
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `slug` (`slug`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
                ];

                foreach ($creates as $q) {
                    if (!exec_stmt($db, $q)) {
                        throw new RuntimeException('SQL failed: ' . (string) $db->error);
                    }
                }

                $seedRoles = [
                    [1, 'user', 'User'],
                    [2, 'editor', 'Editor'],
                    [3, 'moderator', 'Moderator'],
                    [4, 'admin', 'Admin'],
                    [5, 'creator', 'Creator'],
                    [6, 'comptroller', 'Comptroller'],
                ];

                $stmtRole = $db->prepare(
                    'INSERT INTO roles (id, slug, label)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE slug=VALUES(slug), label=VALUES(label)'
                );

                if ($stmtRole === false) {
                    throw new RuntimeException('Role seed prepare failed.');
                }

                foreach ($seedRoles as $r) {
                    $id    = (int) $r[0];
                    $slug  = (string) $r[1];
                    $label = (string) $r[2];

                    $stmtRole->bind_param('iss', $id, $slug, $label);

                    if (!$stmtRole->execute()) {
                        throw new RuntimeException('Role seed failed: ' . (string) $stmtRole->error);
                    }
                }

                $stmtRole->close();

                $chk = $db->query("SELECT id FROM roles WHERE id=4 LIMIT 1");
                $hasAdminRole = ($chk instanceof mysqli_result) ? ($chk->num_rows === 1) : false;

                if ($chk instanceof mysqli_result) {
                    $chk->free();
                }

                if (!$hasAdminRole) {
                    throw new RuntimeException('roles.id=4 missing after seed.');
                }

                $db->query(
                    "INSERT INTO settings (name, value)
                     VALUES ('site_theme', 'default')
                     ON DUPLICATE KEY UPDATE value=VALUES(value)"
                );
		/**
		 * Removed in 2.0.8
                $db->query(
                    "INSERT INTO themes (slug, installed, enabled, version, creator)
                     VALUES ('default', 1, 1, 'v0.0.0', 'stn-labz')
                     ON DUPLICATE KEY UPDATE installed=1, enabled=1"
                );
                */

                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                if (!is_string($hash) || $hash === '') {
                    throw new RuntimeException('Password hashing failed.');
                }

                $roleId = 4;
                $role   = 4;

                $stmtUser = $db->prepare(
                    "INSERT INTO users (username, email, role_id, password_hash, role)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        email=VALUES(email),
                        role_id=VALUES(role_id),
                        password_hash=VALUES(password_hash),
                        role=VALUES(role)"
                );

                if ($stmtUser === false) {
                    throw new RuntimeException('Admin seed prepare failed.');
                }

                $stmtUser->bind_param('ssisi', $values['admin_user'], $values['admin_email'], $roleId, $hash, $role);

                if (!$stmtUser->execute()) {
                    throw new RuntimeException('Admin create failed: ' . (string) $stmtUser->error);
                }

                $stmtUser->close();

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

                if (!write_config_json($cfgPath, $config)) {
                    throw new RuntimeException('Failed to write /app/config/config.json (check permissions).');
                }

                $db->commit();

                $installDir = __DIR__;

                register_shutdown_function(static function () use ($installDir): void {
                    rrmdir($installDir);
                });

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
        button:disabled { opacity: 0.6; cursor: not-allowed; }
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

    <?php if ($installed && !$force): ?>
        <div class="alert alert-danger">
            Installer locked because <code>/app/config/config.json</code> exists.<br>
            Override: <code>/install/?force=1</code>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Installer failed</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= h((string) $e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <form method="post" action="">
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

        <?php $btnDisabled = ($installed && !$force) ? 'disabled' : ''; ?>
        <button type="submit" <?php echo $btnDisabled; ?>>Install</button>
    </form>
</div>
</body>
</html>

