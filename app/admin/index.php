<?php

declare(strict_types=1);

/**
 * Chaos CMS Admin Router
 * /admin?action=...
 */

global $db, $auth;

if (!isset($auth) || !$auth instanceof auth || !$auth->check()) {
    redirect_to('/login');
    exit;
}

$action = (string) ($_GET['action'] ?? 'dashboard');

$allowed = [
    'dashboard',
    'settings',
    'pages',
    'posts',
    'media',
    'users',
    'themes',
    'modules',
    'plugins',
    'maintenance',
    'health',
    'topics',
    'roles',
    'update',

    // dynamic admin hooks
    'module_admin',
    'plugin_admin',
];

if (!in_array($action, $allowed, true)) {
    $action = 'dashboard';
}

$docroot  = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$adminDir = $docroot . '/app/admin';

$h = static function (string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
};

$slug_clean = static function ($slug): string {
    $slug = (string) $slug;
    $slug = trim($slug);
    $slug = strtolower($slug);
    $slug = (string) preg_replace('~\s+~', '-', $slug);
    $slug = (string) preg_replace('~[^a-z0-9_\-]~i', '', $slug);
    return $slug;
};

$admin_hook_include = static function (string $kind) use ($docroot, $h, $slug_clean): void {
    $slug = $slug_clean($_GET['slug'] ?? '');

    if ($slug === '') {
        echo '<div class="admin-wrap"><div class="container my-4">';
        echo '<div class="alert alert-danger">Missing slug.</div>';
        echo '</div></div>';
        return;
    }

    $candidates = [];

    if ($kind === 'module') {
        $candidates[] = $docroot . '/public/modules/' . $slug . '/admin/main.php';
        $candidates[] = $docroot . '/app/modules/' . $slug . '/admin/main.php';
        $candidates[] = $docroot . '/modules/' . $slug . '/admin/main.php';
        $back  = '/admin?action=modules';
        $label = 'Modules';
    } else {
        $candidates[] = $docroot . '/public/plugins/' . $slug . '/admin/main.php';
        $candidates[] = $docroot . '/app/plugins/' . $slug . '/admin/main.php';
        $candidates[] = $docroot . '/plugins/' . $slug . '/admin/main.php';
        $back  = '/admin?action=plugins';
        $label = 'Plugins';
    }

    $entry = '';
    foreach ($candidates as $cand) {
        if (is_string($cand) && $cand !== '' && is_file($cand)) {
            $entry = $cand;
            break;
        }
    }

    if ($entry === '') {
        echo '<div class="admin-wrap"><div class="container my-4">';
        echo '<small><a href="' . $h($back) . '">' . $h($label) . '</a> &raquo; ' . $h($slug) . '</small>';
        echo '<div class="alert alert-danger mt-2">Admin entry not found for <strong>' . $h($slug) . '</strong>.</div>';
        echo '</div></div>';
        return;
    }

    require $entry;
};

// ------------------------------------------------------------
// Route
// ------------------------------------------------------------

switch ($action) {
    case 'dashboard':
        require $adminDir . '/views/dashboard.php';
        break;

    case 'settings':
        require $adminDir . '/views/settings.php';
        break;

    case 'pages':
        require $adminDir . '/views/pages.php';
        break;

    case 'posts':
        require $adminDir . '/views/posts.php';
        break;

    case 'media':
        require $adminDir . '/views/media.php';
        break;

    case 'users':
        require $adminDir . '/views/users.php';
        break;

    case 'themes':
        require $adminDir . '/views/themes.php';
        break;

    case 'modules':
        require $adminDir . '/views/modules.php';
        break;

    case 'plugins':
        require $adminDir . '/views/plugins.php';
        break;

    case 'maintenance':
        require $adminDir . '/views/maintenance.php';
        break;

    case 'health':
        require $adminDir . '/views/health.php';
        break;

    case 'topics':
        require $adminDir . '/views/topics.php';
        break;

    case 'roles':
        require $adminDir . '/views/roles.php';
        break;

    case 'update':
        require $adminDir . '/views/update.php';
        break;

    case 'module_admin':
        $admin_hook_include('module');
        break;

    case 'plugin_admin':
        $admin_hook_include('plugin');
        break;

    default:
        require $adminDir . '/views/dashboard.php';
        break;
}

