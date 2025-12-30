<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('CHAOS_ADMIN_WRAPPED', true);

$admin_css = '/public/assets/css/admin.css';
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
$admin_css_v = '1';
if ($docroot !== '' && is_file($docroot . $admin_css)) {
    $admin_css_v = (string) filemtime($docroot . $admin_css);
}

function admin_header(string $title, string $admin_css, string $admin_css_v): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
        <link rel="stylesheet" href="<?= htmlspecialchars($admin_css, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($admin_css_v, ENT_QUOTES, 'UTF-8'); ?>">
    </head>
    <body>
    <div class="admin-wrap">
    <?php
}

function admin_footer(): void
{
    $year = date('Y');
    ?>
        <div class="container my-4">
            <footer class="text-muted small">&copy; <?= (int) $year; ?>, ChAoS CMS</footer>
        </div>
    </div>
    </body>
    </html>
    <?php
}

admin_header('Admin', $admin_css, $admin_css_v);
?>
<nav class="admin-nav">
    <div class="admin-nav-inner">
        <div class="admin-nav-left">
            <a href="/" class="admin-nav-brand">Home</a>
            <a href="/admin" class="admin-nav-link">Admin</a>
        </div>

        <div class="admin-nav-right">
            <a href="/profile" class="admin-nav-link">Account</a>
            <a href="/admin?action=logout" class="admin-nav-link admin-nav-logout">Logout</a>
        </div>
    </div>
</nav>

<?php
require __DIR__ . '/index.php';
admin_footer();

