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

        $user = $auth->user();

        $roleId = (int)($user['role_id'] ?? 1);

        return [
            'logged_in' => true,
            'username'  => ucfirst((string)($user['username'] ?? '')),
            'role_id'   => $roleId,
            // editor(2), moderator(3), admin(4)
            'can_admin' => in_array($roleId, [2, 3, 4], true),
        ];
    }
}
