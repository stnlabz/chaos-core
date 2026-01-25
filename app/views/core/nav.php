<?php
declare(strict_types=1);

/**
 * Enhanced Navigation:
 * - Dynamic Module injection from 'modules' table
 * - Strict Role-Based Access Control (RBAC) preserved
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

global $db;

// --- Auth & Roles ---
$isLoggedIn = !empty($_SESSION['auth']) && is_array($_SESSION['auth']) && !empty($_SESSION['auth']['id']);
$roleId     = $isLoggedIn ? (int) ($_SESSION['auth']['role_id'] ?? 0) : 0;

$isAdmin   = ($roleId === 4);
$isEditor  = ($roleId === 2);
$isCreator = ($roleId === 5);

$canAdminNav = ($isAdmin || $isEditor || $isCreator);

// --- Pathing & Helpers ---
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

// --- Dynamic Module Fetching ---
$dynamicModules = [];
if (isset($db) && $db instanceof db) {
    try {
        // Only pull modules marked active to show in nav
        $dynamicModules = $db->fetchAll("SELECT name, slug FROM modules WHERE status = 'active' ORDER BY sort_order ASC");
    } catch (\Throwable $e) {
        // Silence errors during "chaos mornings" - fall back to hardcoded if table fails
    }
}

// Site name from themes class
$siteName = (class_exists('themes') && method_exists('themes', 'get')) 
    ? trim((string) themes::get('site_name')) 
    : 'Poe Mei';

if ($siteName === '') $siteName = 'Poe Mei';

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
            
            <?php if (!empty($dynamicModules)): ?>
                <?php foreach ($dynamicModules as $mod): ?>
                    <a href="/<?= $h($mod['slug']); ?>" class="<?= $h($active('/' . $mod['slug'])); ?>">
                        <?= $h($mod['name']); ?>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <a href="/media" class="<?= $h($active('/media')); ?>">Media</a>
                <a href="/posts" class="<?= $h($active('/posts')); ?>">Posts</a>
            <?php endif; ?>
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
