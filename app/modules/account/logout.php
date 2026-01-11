<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Account Module: Logout
 *
 * Mapped route:
 *   /logout -> /app/modules/account/logout.php
 *
 * Depends on:
 *   - global $auth (instance of auth)
 */

(function (): void {
    global $auth;

    if ($auth instanceof auth) {
        $auth->logout();
    }

    header('Location: /');
    exit;
})();

