<?php
declare(strict_types=1);

/**
 * Maintenance
 * For site maintenance and updating
 * requires /app/data/maintenance.flag
 * if present, loads the maintenance module
*/
$lock  = $docroot . '/app/data/update.lock';
$flag  = $docroot . '/app/data/maintenance.flag';
$maint = $docroot . '/app/modules/maintenance/main.php';

if (is_file($lock) || is_file($flag)) {
    // allow admin to function during update/maintenance
    if ($first !== 'admin') {
        http_response_code(503);
        $maint_mode = is_file($lock) ? 'update' : 'maintenance';

        if (is_file($maint)) {
            require $maint;
            return;
        }

        echo '<h1>Maintenance</h1><p>Site temporarily unavailable.</p>';
        return;
    }
}

/**
 * Plugins (ENABLED ONLY)
 * NOTE: plugins must NOT execute just because they exist on disk.
 */
$plugins_dir = $docroot . '/public/plugins';

if (isset($db) && $db instanceof db) {
    $conn = $db->connect();
    if ($conn instanceof mysqli && is_dir($plugins_dir)) {
        $slugs = [];

        // pull enabled plugins from DB (no fatal if table isn't ready)
        $res = @$conn->query("SELECT slug FROM plugins WHERE enabled=1");
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['slug'])) {
                    $slugs[] = (string) $row['slug'];
                }
            }
            $res->free();
        }

        foreach ($slugs as $slug) {
            $slug = (string) preg_replace('~[^a-z0-9_\-]~i', '', $slug);
            if ($slug === '') {
                continue;
            }

            $plugin = $plugins_dir . '/' . $slug . '/plugin.php';
            if (is_file($plugin)) {
                require_once $plugin;
            }
        }
    }
}

// ------------------------------------------------------------
// Core account routes
// ------------------------------------------------------------
if (
    $first === 'login' ||
    $first === 'signup' ||
    $first === 'profile' ||
    $first === 'logout' ||
    $first === 'account'
) {
    $account = $docroot . '/app/modules/account/main.php';
    if (is_file($account)) {
        require $account;
        return;
    }

    http_response_code(500);
    echo '<div><h2>Account module missing</h2><p>/app/modules/account/main.php not found.</p></div>';
    return;
}

// ------------------------------------------------------------
// Admin route (core)
// ------------------------------------------------------------
if ($first === 'admin') {
    $admin = $docroot . '/app/admin/admin.php';
    if (is_file($admin)) {
        require $admin;
        return;
    }

    http_response_code(500);
    echo '<div><h2>Admin missing</h2><p>/app/admin/admin.php not found.</p></div>';
    return;
}

// ------------------------------------------------------------
// Core modules (always available)
// ------------------------------------------------------------

// / or /home (home lives in /public/modules/home OR /app/modules/home)
if ($first === '' || $first === 'home') {
    $pub = $docroot . '/public/modules/home/main.php';
    $app = $docroot . '/app/modules/home/main.php';

    if (is_file($pub) || is_file($app)) {
        require is_file($pub) ? $pub : $app;
        return;
    }
}

// /posts (core, lives in /app/modules/posts)
if ($first === 'posts') {
    $posts = $docroot . '/app/modules/posts/main.php';
    if (is_file($posts)) {
        require $posts;
        return;
    }
}

// /pages (core, lives in /app/modules/pages)
if ($first === 'pages') {
    $page = $docroot . '/app/modules/pages/main.php';
    if (is_file($page)) {
        require $page;
        return;
    }
}

// /media (core, lives in /app/modules/media)
if ($first === 'media') {
    $media = $docroot . '/app/modules/media/main.php';
    if (is_file($media)) {
        require $media;
        return;
    }
}

// ------------------------------------------------------------
// Modules (public/modules/{slug} OR app/modules/{slug}) — PHP/MD/JSON, ALL GATED
// ------------------------------------------------------------
$pubBase = $docroot . '/public/modules/' . $first;
$appBase = $docroot . '/app/modules/' . $first;

$pubPhp  = $pubBase . '/main.php';
$pubMD   = $pubBase . '/main.md';
$pubJson = $pubBase . '/main.json';

$appPhp  = $appBase . '/main.php';
$appMD   = $appBase . '/main.md';
$appJson = $appBase . '/main.json';

// prefer public, fall back to app
$modulePhp  = is_file($pubPhp) ? $pubPhp : (is_file($appPhp) ? $appPhp : '');
$moduleMD   = is_file($pubMD) ? $pubMD : (is_file($appMD) ? $appMD : '');
$moduleJson = is_file($pubJson) ? $pubJson : (is_file($appJson) ? $appJson : '');

$moduleEnabled = false;

if (
    isset($db) &&
    $db instanceof db &&
    $first !== '' &&
    $first !== 'home' &&
    $first !== 'posts' &&
    $first !== 'media' &&
    $first !== 'pages' &&
    $first !== 'admin' &&
    $first !== 'account' &&
    $first !== 'login' &&
    $first !== 'signup' &&
    $first !== 'profile' &&
    $first !== 'logout'
) {
    $conn = $db->connect();
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT enabled FROM modules WHERE slug=? LIMIT 1");
        if ($stmt instanceof mysqli_stmt) {
            $slug = (string) $first;
            $stmt->bind_param('s', $slug);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res instanceof mysqli_result) {
                    $row = $res->fetch_assoc();
                    $moduleEnabled = is_array($row) && (int) ($row['enabled'] ?? 0) === 1;
                }
            }
            $stmt->close();
        }
    }
}

if ($modulePhp !== '') {
    if ($moduleEnabled) {
        require $modulePhp;
        return;
    }

    http_response_code(404);
    echo '<div><h2>Module Disabled</h2><p>This module is not enabled.</p></div>';
    return;
}

if ($moduleMD !== '') {
    if ($moduleEnabled) {
        render_markdown($moduleMD);
        return;
    }

    http_response_code(404);
    echo '<div><h2>Module Disabled</h2><p>This module is not enabled.</p></div>';
    return;
}

if ($moduleJson !== '') {
    if ($moduleEnabled) {
        render_json($moduleJson);
        return;
    }

    http_response_code(404);
    echo '<div><h2>Module Disabled</h2><p>This module is not enabled.</p></div>';
    return;
}

// Default
http_response_code(404);
echo '<div><h2>Ooops! That is a big fat 404</h2><p>The Page you are hunting for, cannot be found.</p></div>';

