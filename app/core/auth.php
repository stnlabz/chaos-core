<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Core Auth
 *
 * - users.role_id ties to roles.id
 * - roles table: id, slug, label
 */

final class auth
{
    protected db $db;

    public function __construct(db $db)
    {
        $this->db = $db;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * @return bool
     */
    public function check(): bool
    {
        return isset($_SESSION['auth']) && is_array($_SESSION['auth']) && isset($_SESSION['auth']['id']);
    }

    /**
     * Convenience: current role_id or null.
     *
     * @return int|null
     */
    public function role_id(): ?int
    {
        $u = $this->user();
        if (!is_array($u)) {
            return null;
        }

        return isset($u['role_id']) ? (int)$u['role_id'] : null;
    }

    /**
     * True if current user has role_id >= required.
     *
     * user=1, editor=2, moderator=3, admin=4
     *
     * @param int $minRoleId
     * @return bool
     */
    public function can(int $minRoleId): bool
    {
        return $this->role_id() >= $minRoleId;
    }

    /**
     * Login by username (per your directive).
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function login(string $username, string $password): bool
    {
        $conn = $this->db->connect();
        if ($conn === false) {
            return false;
        }

        $sql = "SELECT id, username, email, password_hash, role_id
                FROM users
                WHERE username=?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();

        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($user)) {
            return false;
        }

        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            return false;
        }

        // Regenerate session id only if headers still possible
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }

        $_SESSION['auth'] = [
            'id'       => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'email'    => (string) ($user['email'] ?? ''),
            'role_id'  => (int) ($user['role_id'] ?? 1),
        ];

        return true;
    }

    /**
     * @return void
     */
    public function logout(): void
    {
        unset($_SESSION['auth']);

        if (!headers_sent()) {
            @session_regenerate_id(true);
        }
    }
    
        /**
     * Ensure users table exists (and role_id column exists).
     * Called from bootstrap.
     *
     * @param db $db
     * @return void
     */
    public static function ensure_users_table(db $db): void
    {
        $conn = $db->connect();
        if ($conn === false) {
            return;
        }

        // 1) Create table if missing (minimal, matches your current direction)
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(190) NOT NULL,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role_id INT(11) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_username (username),
                UNIQUE KEY uniq_email (email),
                KEY idx_role_id (role_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $conn->query($sql);

        // 2) Make sure role_id exists (for older installs)
        $chk = $conn->query("SHOW COLUMNS FROM users LIKE 'role_id'");
        if ($chk instanceof mysqli_result) {
            $has = ($chk->num_rows > 0);
            $chk->close();

            if (!$has) {
                $conn->query("ALTER TABLE users ADD COLUMN role_id INT(11) NOT NULL DEFAULT 1 AFTER email");
                $conn->query("CREATE INDEX idx_role_id ON users(role_id)");
            }
        }
    }
    
     /**
     * Return the current logged-in user session payload (or null).
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $u = $_SESSION['auth'] ?? null;
        if (!is_array($u) || !isset($u['id'])) {
            return null;
        }

        return $u;
    }

    /**
     * Convenience: current user id or null.
     *
     * @return int|null
     */
    public function id(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return $_SESSION['auth']['id'] ?? null;
}


    /**
     * Convenience: current username or empty string.
     *
     * @return string
     */
    public function username(): string
    {
        $u = $this->user();
        if (!is_array($u)) {
            return '';
        }

        return (string)($u['username'] ?? '');
    }
    
    public function role_slug(): string
{
    $u = $this->user();
    return is_array($u) ? (string)($u['role_slug'] ?? '') : '';
}

public function is_admin(): bool
{
    return $this->role_slug() === 'admin';
}

public function is_moderator(): bool
{
    $r = $this->role_slug();
    return ($r === 'moderator' || $r === 'admin');
}

public function is_editor(): bool
{
    $r = $this->role_slug();
    return ($r === 'editor' || $r === 'moderator' || $r === 'admin');
}

}

