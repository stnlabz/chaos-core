<?php
/**
 * Chaos CMS DB
 *  The Database driven version
*/

/**
 * Bootstrap
 * Pre loads needed files and functions
*/
require_once ( __DIR__ . '/app/bootstrap.php');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$trimmed  = trim($path, '/');
[$first]  = $trimmed === '' ? ['home'] : explode('/', $trimmed, 2);

$docroot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

// Setup /admin
$admin = $docroot . '/app/admin';
if ($first === 'admin') {
    require $admin . '/admin.php';
    return;
}

// The Themes Header
include __DIR__ . "/public/themes/{$site_theme}/header.php";

// The Router
// All routing gets dispatched from here
include __DIR__ . '/app/router.php';

// The Themes Footer
include __DIR__ . "/public/themes/{$site_theme}/footer.php";
