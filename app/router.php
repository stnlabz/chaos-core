<?php
declare(strict_types=1);

/**
 * Chaos CMS Router
 * Skinny, predictable, explicit routing.
 * 
 * Route order:
 * 1. Maintenance/Update mode
 * 2. Account routes (login, signup, profile, logout, account)
 * 3. Core modules (home, posts, pages, media)
 * 4. Dynamic modules (DB-gated)
 * 5. 404 (errors module)
 */

// ------------------------------------------------------------
// URL parsing
// ------------------------------------------------------------
$path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$trimmed  = trim($path, '/');
$segments = $trimmed === '' ? ['home'] : explode('/', $trimmed);
$first    = $segments[0] ?? 'home';
$docroot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');

// ------------------------------------------------------------
// Maintenance / Update Mode
// ------------------------------------------------------------
$lock  = $docroot . '/app/data/update.lock';
$flag  = $docroot . '/app/data/maintenance.flag';
$maint = $docroot . '/app/modules/maintenance/main.php';

if (is_file($lock) || is_file($flag)) {
    // Allow admin to function during maintenance
    if ($first !== 'admin') {
        http_response_code(503);
        
        if (is_file($maint)) {
            require $maint;
            return;
        }
        
        echo '<h1>Maintenance</h1><p>Site temporarily unavailable.</p>';
        return;
    }
}

//------------------------------------------------------------
// Checkout & Webhooks (before account routes)
//------------------------------------------------------------
// /checkout/create-session - API endpoint (no theme)
if ($first === 'checkout' && isset($segments[1]) && $segments[1] === 'create-session') {
    $api = $docroot . '/app/modules/checkout/api.php';
    if (is_file($api)) {
        require $api;
        return;
    }
}

// /checkout - Main module (with theme)
if ($first === 'checkout') {
    $checkout = $docroot . '/app/modules/checkout/main.php';
    if (is_file($checkout)) {
        require $checkout;
        return;
    }
}

// /webhooks/stripe
if ($first === 'webhooks' && isset($segments[1]) && $segments[1] === 'stripe') {
    $stripeWebhook = $docroot . '/app/webhooks/stripe.php';
    if (is_file($stripeWebhook)) {
        require $stripeWebhook;
        return;
    }
}


// ------------------------------------------------------------
// Account Routes (EXPLICIT - Security Critical)
// ------------------------------------------------------------
if (in_array($first, ['login', 'signup', 'profile', 'logout', 'account'], true)) {
    $account = $docroot . '/app/modules/account/main.php';
    
    if (is_file($account)) {
        require $account;
        return;
    }
    
    http_response_code(500);
    echo '<div class="container my-4"><div class="alert alert-danger">Account module missing.</div></div>';
    return;
}

// ------------------------------------------------------------
// Core Modules (Always Available, No DB Check)
// ------------------------------------------------------------
$coreModules = [
    'home'  => ['/public/modules/home/main.php', '/app/modules/home/main.php'],
    'posts' => ['/app/modules/posts/main.php'],
    'pages' => ['/app/modules/pages/main.php'],
    'media' => ['/app/modules/media/main.php'],
];

// Handle empty path as 'home'
$coreSlug = ($first === '' || $first === 'home') ? 'home' : $first;

if (isset($coreModules[$coreSlug])) {
    foreach ($coreModules[$coreSlug] as $modPath) {
        $fullPath = $docroot . $modPath;
        
        if (is_file($fullPath)) {
            require $fullPath;
            return;
        }
    }
}

// ------------------------------------------------------------
// Dynamic Modules (DB-Gated, Optional Modules)
// ------------------------------------------------------------
if ($first !== '' && $first !== 'admin') {
    $pubBase = $docroot . '/public/modules/' . $first;
    $appBase = $docroot . '/app/modules/' . $first;
    
    $pubPhp  = $pubBase . '/main.php';
    $pubMD   = $pubBase . '/main.md';
    $pubJson = $pubBase . '/main.json';
    
    $appPhp  = $appBase . '/main.php';
    $appMD   = $appBase . '/main.md';
    $appJson = $appBase . '/main.json';
    
    // Prefer public, fallback to app
    $modulePhp  = is_file($pubPhp) ? $pubPhp : (is_file($appPhp) ? $appPhp : '');
    $moduleMD   = is_file($pubMD) ? $pubMD : (is_file($appMD) ? $appMD : '');
    $moduleJson = is_file($pubJson) ? $pubJson : (is_file($appJson) ? $appJson : '');
    
    // Check if module is enabled (skip for core modules already handled above)
    $moduleEnabled = false;
    
    if (isset($db) && $db instanceof db) {
        $conn = $db->connect();
        
        if ($conn instanceof mysqli) {
            $stmt = $conn->prepare("SELECT enabled FROM modules WHERE slug=? LIMIT 1");
            
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('s', $first);
                
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    
                    if ($res instanceof mysqli_result) {
                        $row = $res->fetch_assoc();
                        $moduleEnabled = is_array($row) && (int)($row['enabled'] ?? 0) === 1;
                    }
                }
                
                $stmt->close();
            }
        }
    }
    
    // Load module file if enabled
    if ($modulePhp !== '') {
        if ($moduleEnabled) {
            require $modulePhp;
            return;
        }
        
        http_response_code(403);
        $errorsModule = $docroot . '/app/modules/errors/main.php';
        
        if (is_file($errorsModule)) {
            $_SERVER['ERROR_CODE'] = 403;
            $_SERVER['ERROR_MESSAGE'] = 'Module disabled';
            require $errorsModule;
            return;
        }
        
        echo '<div class="container my-4"><div class="alert alert-warning">Module disabled.</div></div>';
        return;
    }
    
    if ($moduleMD !== '') {
        if ($moduleEnabled) {
            render_markdown($moduleMD);
            return;
        }
        
        http_response_code(403);
        echo '<div class="container my-4"><div class="alert alert-warning">Module disabled.</div></div>';
        return;
    }
    
    if ($moduleJson !== '') {
        if ($moduleEnabled) {
            render_json($moduleJson);
            return;
        }
        
        http_response_code(403);
        echo '<div class="container my-4"><div class="alert alert-warning">Module disabled.</div></div>';
        return;
    }
}

// ------------------------------------------------------------
// 404 - Not Found
// ------------------------------------------------------------
http_response_code(404);

$errorsModule = $docroot . '/app/modules/errors/main.php';

if (is_file($errorsModule)) {
    $_SERVER['ERROR_CODE'] = 404;
    $_SERVER['ERROR_MESSAGE'] = 'Page not found';
    require $errorsModule;
    return;
}

// Fallback 404 (if errors module missing)
echo '<div class="container my-4">';
echo '<div class="alert alert-secondary">';
echo '<h2>404 - Not Found</h2>';
echo '<p>The page you requested could not be found.</p>';
echo '<p><a href="/">Return to home</a></p>';
echo '</div>';
echo '</div>';
