<?php
declare(strict_types=1);

/**
 * Theme Nav (Classic)
 *
 * Safe to load as theme override.
 * Does NOT redeclare helpers if core already loaded them.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Nav active helper
 * Guarded to prevent redeclare fatals.
 */
if (!function_exists('nav_active')) {
    /**
     * @param string $href
     * @param string $current
     * @return string
     */
    function nav_active(string $href, string $current): string
    {
        if ($href === '/' && $current === '/') {
            return 'active';
        }

        if ($href !== '/' && str_starts_with($current, $href)) {
            return 'active';
        }

        return '';
    }
}

// ------------------------------------------------------------
// Auth / Role State
// ------------------------------------------------------------
$authData   = $_SESSION['auth'] ?? [];
$isLoggedIn = is_array($authData) && !empty($authData['id']);

$roleId  = (int) ($authData['role_id'] ?? 0);
$isAdmin = ($roleId === 4);
$isCreator = ($roleId === 5);
$isEditor = ($roleId === 2);

// ------------------------------------------------------------
// Routing
// ------------------------------------------------------------
$current = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$siteName = (string) (themes::get('site_name') ?? 'Website');
?>

<nav class="core-nav">
    <div class="core-nav__inner">

        <a href="/" class="core-nav__brand <?php echo nav_active('/', $current); ?>">
            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
        </a>

        <div class="core-nav__links">
            <a href="/" class="<?php echo nav_active('/', $current); ?>">Home</a>
            <a href="/media" class="<?php echo nav_active('/media', $current); ?>">Media</a>
            <a href="/posts" class="<?php echo nav_active('/posts', $current); ?>">Posts</a>
        </div>

        <div class="core-nav__auth">
            <?php if ($isLoggedIn): ?>

                <a href="/account" class="<?php echo nav_active('/account', $current); ?>">
                    Account
                </a>

                <?php if ($isAdmin || $isCreator || $isEditor): ?>
                    <a href="/admin" class="<?php echo nav_active('/admin', $current); ?>">
                        Admin
                    </a>
                <?php endif; ?>

                <a href="/logout">Logout</a>

            <?php else: ?>

                <a href="/login" class="<?php echo nav_active('/login', $current); ?>">Login</a>
                <a href="/signup" class="<?php echo nav_active('/signup', $current); ?>">Sign Up</a>

            <?php endif; ?>
        </div>

    </div>
</nav>

