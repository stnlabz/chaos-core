<?php

declare(strict_types=1);

/**
 * Account Router
 * Routes:
 *  /login   -> login.php
 *  /signup  -> signup.php
 *  /profile -> account.php
 *  /logout  -> logout.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/login') {
    require __DIR__ . '/login.php';
    return;
}

if ($path === '/signup') {
    require __DIR__ . '/register.php';
    return;
}

if ($path === '/profile' || $path === '/account') {
    require __DIR__ . '/account.php';
    return;
}

if ($path === '/logout') {
    require __DIR__ . '/logout.php';
    return;
}

// fallback
http_response_code(404);
echo '<div class="container my-4"><div class="alert alert-secondary">Not found.</div></div>';

