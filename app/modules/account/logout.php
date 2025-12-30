<?php

declare(strict_types=1);

/**
 * Logout
 */

(function (): void {
    global $auth;

    if ($auth instanceof auth) {
        $auth->logout();
    }

    header('Location: /login');
    exit;
})();

