<?php

declare(strict_types=1);

/**
 * /app/views/core/nav.php
 *
 * Core navigation view.
 * - No layout wrappers here (header/footer own structure)
 * - Admin link shown for: Admin (4), Editor (2), Creator (5)
 * - Moderators (3) do NOT get /admin link
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$isLoggedIn = !empty($_SESSION['auth']) && is_array($_SESSION['auth']) && !empty($_SESSION['auth']['id']);
$roleId     = $isLoggedIn ? (int) ($_SESSION['auth']['role_id'] ?? 0) : 0;

$isAdmin   = ($roleId === 4);
$isEditor  = ($roleId === 2);
$isCreator = ($roleId === 5);

$canAdminNav = ($isAdmin || $isEditor || $isCreator);

$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$current = rtrim($current, '/') ?: '/';

$h = static function (string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
};

$active = static function (string $href) use ($current): string {
    $href = rtrim($href, '/') ?: '/';

    $isActive = ($href === '/' && $current === '/')
        || ($href !== '/' && str_starts_with($current, $href));

    return $isActive ? 'active' : '';
};

// Site name
$siteName = 'Website';
if (class_exists('themes') && method_exists('themes', 'get')) {
    $tmp = trim((string) themes::get('site_name'));
    if ($tmp !== '') {
        $siteName = $tmp;
    }
}

// Account label
$username     = $isLoggedIn ? (string) ($_SESSION['auth']['username'] ?? '') : '';
$accountLabel = ($username !== '') ? 'Account (' . $username . ')' : 'Account';

?>
<nav class="core-nav">
    <div class="container core-nav__inner">
        <a href="/" class="core-nav__brand <?= $h($active('/')); ?>">
            <?= $h($siteName); ?>
        </a>

        <div class="core-nav__links">
            <a href="/" class="<?= $h($active('/')); ?>">Home</a>
            <a href="/media" class="<?= $h($active('/media')); ?>">Media</a>
            <a href="/posts" class="<?= $h($active('/posts')); ?>">Posts</a>
        </div>

        <div class="core-nav__auth">
            <?php if ($isLoggedIn): ?>
                <a href="/account" class="<?= $h($active('/account')); ?>">
                    <?= $h($accountLabel); ?>
                </a>

                <?php if ($canAdminNav): ?>
                    <a href="/admin" class="<?= $h($active('/admin')); ?>">Admin</a>
                <?php endif; ?>

                <a href="/logout" class="<?= $h($active('/logout')); ?>">Logout</a>
            <?php else: ?>
                <a href="/login" class="<?= $h($active('/login')); ?>">Login</a>
                <a href="/signup" class="<?= $h($active('/signup')); ?>">Sign Up</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

