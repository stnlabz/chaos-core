<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Admin Router (ROUTER ONLY)
 *
 * IMPORTANT:
 * - This file does NOT output header/footer.
 * - It is wrapped by /app/admin/admin.php for styling + layout.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/\\');

function admin_slug(string $slug): string
{
    $slug = trim($slug);
    $slug = preg_replace('~[^a-z0-9_\-]~i', '', $slug);
    return (string)$slug;
}

function admin_require_auth(): void
{
    global $auth;

    if (!$auth instanceof auth) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Auth core not available.</div></div>';
        exit;
    }

    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }
}

function admin_view(string $view): void
{
    $path = __DIR__ . '/views/' . $view . '.php';

    if (!is_file($path)) {
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-secondary">Admin view not found.</div></div>';
        return;
    }

    require $path;
}

function admin_hook_include(string $kind, string $slug): void
{
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/\\');

    $slug = admin_slug($slug);
    if ($slug === '') {
        http_response_code(400);
        echo '<div class="container my-4"><div class="alert alert-danger">Missing slug.</div></div>';
        return;
    }

    if ($kind === 'module') {
        $entry = $docroot . '/public/modules/' . $slug . '/admin/main.php';
        $back  = '/admin?action=modules';
        $label = 'Modules';
    } else {
        $entry = $docroot . '/public/plugins/' . $slug . '/admin/main.php';
        $back  = '/admin?action=plugins';
        $label = 'Plugins';
    }

    echo '<div class="container my-4">';
    echo '<small><a href="/admin">Admin</a> &raquo; <a href="' . htmlspecialchars($back, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a> &raquo; ' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</small>';

    if (is_file($entry)) {
        echo '<h1 class="h3 mt-2 mb-3">' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . ' Admin</h1>';
        require $entry;
    } else {
        echo '<div class="alert alert-secondary mt-3">No admin UI found at: <code>' . htmlspecialchars($entry, ENT_QUOTES, 'UTF-8') . '</code></div>';
    }

    echo '</div>';
}

/* ------------------------------------------------------------
 * ROUTING
 * ---------------------------------------------------------- */

$action = (string)($_GET['action'] ?? 'dashboard');

if ($action === 'logout') {
    global $auth;
    if ($auth instanceof auth) {
        $auth->logout();
    }
    header('Location: /login');
    exit;
}

// everything else requires login
admin_require_auth();

switch ($action) {
    case 'dashboard':
        admin_view('dashboard');
        break;

    case 'settings':
        admin_view('settings');
        break;

    case 'modules':
        admin_view('modules');
        break;

    case 'plugins':
        admin_view('plugins');
        break;

    case 'themes':
        admin_view('themes');
        break;

    case 'pages':
        admin_view('pages');
        break;

    case 'media':
        admin_view('media');
        break;

    case 'posts':
        admin_view('posts');
        break;

    case 'users':
        admin_view('users');
        break;

    case 'maintenance':
        admin_view('maintenance');
        break;

    case 'module_admin':
        admin_hook_include('module', (string)($_GET['slug'] ?? ''));
        break;

    case 'plugin_admin':
        admin_hook_include('plugin', (string)($_GET['slug'] ?? ''));
        break;
    case 'health':
        admin_view('health');
        break;
    case 'update':
        admin_view('update');
        break;
    case 'roles':
        admin_view('roles');
        break;
    case 'topics':
        admin_view('topics');
        break;

    default:
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-secondary">Unknown admin action.</div></div>';
        break;
}

