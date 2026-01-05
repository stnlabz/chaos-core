<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$isLoggedIn = !empty($_SESSION['auth']) && is_array($_SESSION['auth']) && !empty($_SESSION['auth']['id']);
$role       = $isLoggedIn ? (string)($_SESSION['auth']['role'] ?? '') : '';
$isAdmin    = ($role === 'admin');

$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function nav_active(string $href, string $current): string
{
    return ($href === '/' && $current === '/') || ($href !== '/' && str_starts_with($current, $href))
        ? ' active'
        : '';
}

global $auth;

$nav = utility::nav_state($auth);
?>

<!-- Handle the html demand for an H1 -->
  <div class="row">
    <nav class="navbar">
    <a href="/" class="navbar-brand">
        <span class="navbar-brand-icon">
            <!-- Inline SVG or <img> -->
            <img src="/public/themes/default/assets/icons/icon.svg" alt="Chaos >
        </span>
        <span class="navbar-brand-text"><?= $site_name ?></span>
    </a>
    
<ul class="navbar-nav">
    <li><a href="/" class="<?php echo ($current === '/') ? 'active' : ''; ?>">Home</a></li>
    <li><a href="/posts" class="<?php echo ($current === '/posts') ? 'active' : ''; ?>">Posts</a></li>
    <li><a href="/media" class="<?php echo ($current === '/media') ? 'active' : ''; ?>">Media</a></li>
    <li><a href="/pages/changelog" class="<?php echo ($current === '/pages/changelog') ? 'active' : ''; ?>">Changelog</a></li>
    <li><a href="/codex" class="<?php echo ($current === '/codex') ? 'active' : ''; ?>">Codex</a></li>

     <?php if ($nav['logged_in']): ?>
        <li>
            <a href="/profile" class="<?= utility::nav_active('/profile'); ?>">
                Account<?= $nav['username'] ? ' (' . e($nav['username']) . ')' : ''; ?>
            </a>
        </li>
        <?php if ($nav['can_admin']): ?>
            <li>
                <a href="/admin" class="<?= utility::nav_active('/admin'); ?>">Admin</a>
            </li>
            <li>
                <a href="/logout" class="<?= utility::nav_active('/logout'); ?>">Logout</a>
            </li>
        <?php endif; ?>
        <?php else: ?>
        <li>
            <a href="/login" class="<?= utility::nav_active('/login'); ?>">Login</a>
        </li>
        <li>
            <a href="/signup" class="<?= utility::nav_active('/signup'); ?>">Sign Up</a>
        </li>
    <?php endif; ?>
</ul>
  </div>


