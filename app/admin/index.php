<?php

declare(strict_types=1);

/**
 * Chaos CMS Admin Router
 *
 * Routes:
 *   /admin
 *   /admin?action=dashboard|posts|media|pages|users|settings|themes|modules|plugins|maintenance|health|update|audit
 *
 * Role model (role_id):
 *  - 4: Admin (full)
 *  - 5: Creator (posts + media only)
 *  - 2: Editor  (posts + media only)
 *  - 3: Moderator (NO /admin)
 *  - 1: User (NO /admin)
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/\\');

function admin_role_id(): int
{
    $rid = 0;

    if (!empty($_SESSION['auth']) && is_array($_SESSION['auth'])) {
        $rid = (int) ($_SESSION['auth']['role_id'] ?? 0);
    }

    return $rid;
}

function admin_require_auth(): void
{
    if (empty($_SESSION['auth']) || !is_array($_SESSION['auth']) || empty($_SESSION['auth']['id'])) {
        header('Location: /login');
        exit;
    }
}

function admin_view(string $view): void
{
    $viewsDir = __DIR__ . '/views';
    $path     = $viewsDir . '/' . $view . '.php';

    if (!is_file($path)) {
        http_response_code(404);
        echo '<div class="admin-wrap"><div class="container my-4">';
        echo '<div class="alert alert-danger">Admin view not found.</div>';
        echo '</div></div>';
        return;
    }

    require $path;
}

$action = (string) ($_GET['action'] ?? 'dashboard');
$action = $action !== '' ? $action : 'dashboard';

// everything else requires login
admin_require_auth();

// -----------------------------------------------------------------------------
// Role gate (see role model above)
// -----------------------------------------------------------------------------
$roleId = admin_role_id();

// Moderators (3) do NOT use /admin. Users (1) do not either.
// If someone has no role_id for any reason, treat as blocked.
if ($roleId !== 4 && $roleId !== 2 && $roleId !== 5) {
    http_response_code(403);
    echo '<div class="admin-wrap"><div class="container my-4">';
    echo '<div class="alert alert-warning">Forbidden</div>';
    echo '</div></div>';
    return;
}

// Editors (2) + Creators (5) only get: dashboard, posts, media
if ($roleId !== 4) {
    $allowed = [
        'dashboard' => true,
        'posts'     => true,
        'media'     => true,
    ];

    if (!isset($allowed[$action])) {
        http_response_code(403);
        echo '<div class="admin-wrap"><div class="container my-4">';
        echo '<div class="alert alert-warning">Forbidden</div>';
        echo '</div></div>';
        return;
    }
}

// -----------------------------------------------------------------------------
// Router
// -----------------------------------------------------------------------------
switch ($action) {
    case 'dashboard':
        admin_view('dashboard');
        break;

    case 'posts':
        admin_view('posts');
        break;

    case 'media':
        admin_view('media');
        break;

    case 'pages':
        admin_view('pages');
        break;

    case 'users':
        admin_view('users');
        break;

    case 'settings':
        admin_view('settings');
        break;

    case 'themes':
        admin_view('themes');
        break;

    case 'modules':
        admin_view('modules');
        break;

    case 'plugins':
        admin_view('plugins');
        break;

    case 'maintenance':
        admin_view('maintenance');
        break;

    case 'health':
        admin_view('health');
        break;

    case 'update':
        admin_view('update');
        break;

    case 'audit':
        admin_view('audit');
        break;

    default:
        admin_view('dashboard');
        break;
}

