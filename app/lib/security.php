<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * /app/lib/security.php
 *
 * KISS security + helpers used across core/modules/plugins.
 * - HTML escaping
 * - CSRF token helpers
 * - Visibility helpers (0=Public, 1=Unlisted, 2=Members)
 */

/**
 * Escape HTML safely.
 *
 * @param string $v
 * @return string
 */
function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Ensure session is started.
 *
 * @return void
 */
function security_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Get (or create) CSRF token.
 *
 * @return string
 */
function csrf_token(): string
{
    security_session();

    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || $_SESSION['_csrf'] === '') {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['_csrf'];
}

/**
 * Validate CSRF token.
 *
 * @param string $token
 * @return bool
 */
function csrf_ok(string $token): bool
{
    security_session();

    $sess = $_SESSION['_csrf'] ?? '';
    if (!is_string($sess) || $sess === '') {
        return false;
    }

    return hash_equals($sess, $token);
}

/**
 * Simple flash messaging (optional).
 *
 * @param string $type
 * @param string $msg
 * @return void
 */
function flash(string $type, string $msg): void
{
    security_session();

    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }

    $_SESSION['_flash'][] = [
        'type' => $type,
        'msg'  => $msg,
        'ts'   => gmdate('c'),
    ];
}

/**
 * Pop and clear flash messages.
 *
 * @return array<int,array{type:string,msg:string,ts:string}>
 */
function flash_pop(): array
{
    security_session();

    $out = [];
    if (isset($_SESSION['_flash']) && is_array($_SESSION['_flash'])) {
        $out = $_SESSION['_flash'];
    }

    $_SESSION['_flash'] = [];
    return $out;
}

/**
 * Visibility constants:
 * 0 = Public      (visible + listable for everyone)
 * 1 = Unlisted    (viewable by direct link, NOT listable)
 * 2 = Members     (viewable + listable only when logged in)
 */
function visibility_label(int $v): string
{
    if ($v === 2) {
        return 'Members';
    }

    if ($v === 1) {
        return 'Unlisted';
    }

    return 'Public';
}

/**
 * Can a user VIEW an item with visibility level?
 *
 * @param int  $visibility
 * @param bool $loggedIn
 * @return bool
 */
function can_view_visibility(int $visibility, bool $loggedIn): bool
{
    if ($visibility === 2) {
        return $loggedIn;
    }

    // Public + Unlisted are viewable (Unlisted is just not listable)
    return true;
}

/**
 * Can a user SEE an item in LISTS (index pages, grids, feeds)?
 *
 * @param int  $visibility
 * @param bool $loggedIn
 * @return bool
 */
function can_list_visibility(int $visibility, bool $loggedIn): bool
{
    // Unlisted never appears in lists
    if ($visibility === 1) {
        return false;
    }

    // Members list items only to logged-in users
    if ($visibility === 2) {
        return $loggedIn;
    }

    return true;
}

/**
 * Helper for building SQL fragments when filtering list views.
 * Use this for grids/index pages, NOT for single-item views.
 *
 * @param bool $loggedIn
 * @return string
 */
function visibility_list_sql(bool $loggedIn): string
{
    // List views exclude Unlisted (1) always.
    // Public (0) always included.
    // Members (2) included only when logged in.
    if ($loggedIn) {
        return 'visibility IN (0,2)';
    }

    return 'visibility = 0';
}

/**
 * Helper for building SQL fragments when filtering single-item views.
 * Single-item views allow Unlisted (1) by direct link.
 *
 * @param bool $loggedIn
 * @return string
 */
function visibility_view_sql(bool $loggedIn): string
{
    // View pages allow Unlisted (1).
    // Members (2) requires login.
    if ($loggedIn) {
        return 'visibility IN (0,1,2)';
    }

    return 'visibility IN (0,1)';
}

