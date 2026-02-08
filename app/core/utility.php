<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Utilities
 *
 * Core-only:
 *  - Provide functions for the entirety of the Chaos CMS.
 */
class utility
{
    public static function redirect_to(string $url): void
    {
        header('Location: ' . $url, true);
        exit;
    }

    /*---------------------------------------------------------
     * NAV HELPERS
     * ------------------------------------------------------- */

    public static function current_path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    public static function nav_active(string $href): string
    {
        $current = self::current_path();

        if ($href === '/') {
            return ($current === '/') ? ' active' : '';
        }

        return (strpos($current, $href) === 0) ? ' active' : '';
    }

    /**
     * Navigation state helper
     *
     * @return array{logged_in:bool,username:string,role_id:int,can_admin:bool}
     */
    public static function nav_state(auth $auth): array
    {
        if (!$auth->check()) {
            return [
                'logged_in' => false,
                'username'  => '',
                'role_id'   => 1,
                'can_admin' => false,
            ];
        }

        $user   = $auth->user();
        $roleId = (int) ($user['role_id'] ?? 1);

        return [
            'logged_in' => true,
            'username'  => ucfirst((string) ($user['username'] ?? '')),
            'role_id'   => $roleId,
            // editor(2), moderator(3), admin(4)
            'can_admin' => in_array($roleId, [2, 3, 4], true),
        ];
    }
    
    public static function pretty_error($message) {
            echo "<div style='
                background: #1e1e1e;
                color: #f88;
                padding: 1.5em;
                border: 2px solid #f00;
                font-family: monospace;
                margin: 2em;
                border-radius: 10px;
            '><strong>Error:</strong><br>$message</div>";
        
            $log_file = APP_ROOT . '/logs/site_errors.log'; // ?? This was missing!
        
            $log_line = "[" . date('Y-m-d H:i:s') . "] $message\n";
        
            if (file_exists($log_file) && filesize($log_file) > 1024 * 1024) { // 1MB
                rename($log_file, $log_file . '.' . time());
            }
        
            file_put_contents($log_file, $log_line, FILE_APPEND); // ?? Was missing semicolon
        }
        
    	public static function throw_error($code = 500, $message = 'Unknown Error') {
            http_response_code($code);
        
            $friendly = [
                400 => 'Bad Request',
                403 => 'Forbidden',
                404 => 'Not Found',
                500 => 'Internal Server Error',
                503 => 'Service Unavailable'
            ];
        
            $title = $friendly[$code] ?? 'Error';
            pretty_error("[$code] $title: $message");
        
            // Optional: Log it
            $log_line = "[" . date('Y-m-d H:i:s') . "] [$code] $title  $message\n";
            @file_put_contents(APP_ROOT . '/logs/site_errors.log', $log_line, FILE_APPEND);
        
            exit;
        }
}
