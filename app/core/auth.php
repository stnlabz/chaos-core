<?php

declare(strict_types=1);

/**
 * Chaos CMS DB - Core Auth (ENHANCED)
 *
 * Features:
 * - Login/Logout with username
 * - Role hierarchy enforcement (user=1, editor=2, moderator=3, admin=4)
 * - Session timeout tracking (30 min default)
 * - Session security (IP/User Agent validation - configurable)
 * - Password reset tokens
 * - Brute force protection (5 attempts, 15 min lockout)
 * - Role validation against roles table
 *
 * Schema Requirements:
 * - users.role_id (INT, FK to roles.id)
 * - users.reset_token (VARCHAR 64, nullable)
 * - users.reset_expires (DATETIME, nullable)
 * - users.login_attempts (INT, default 0)
 * - users.locked_until (DATETIME, nullable)
 * - users.last_ip (VARCHAR 45, nullable)
 * - users.last_user_agent (VARCHAR 255, nullable)
 */

final class auth
{
    protected db $db;
    
    /**
     * Security settings (configurable via settings table or constants)
     */
    private const SESSION_TIMEOUT_MINUTES = 30;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const RESET_TOKEN_HOURS = 24;

    public function __construct(db $db)
    {
        $this->db = $db;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Check if user is authenticated.
     *
     * @return bool
     */
    public function check(): bool
    {
        if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth']) || !isset($_SESSION['auth']['id'])) {
            return false;
        }

        // Check session timeout
        if (!$this->check_timeout()) {
            $this->logout();
            return false;
        }

        // Check session security (if enabled)
        if ($this->is_session_security_enabled() && !$this->validate_session()) {
            $this->logout();
            return false;
        }

        // Update last activity
        $_SESSION['auth']['last_activity'] = time();

        return true;
    }

    /**
     * Check session timeout.
     *
     * @param int|null $maxInactiveMinutes
     * @return bool
     */
    public function check_timeout(?int $maxInactiveMinutes = null): bool
    {
        if ($maxInactiveMinutes === null) {
            $maxInactiveMinutes = self::SESSION_TIMEOUT_MINUTES;
        }

        $lastActivity = (int) ($_SESSION['auth']['last_activity'] ?? 0);

        if ($lastActivity === 0) {
            // First time, set it
            $_SESSION['auth']['last_activity'] = time();
            return true;
        }

        $inactiveSeconds = time() - $lastActivity;
        $maxInactiveSeconds = $maxInactiveMinutes * 60;

        return $inactiveSeconds < $maxInactiveSeconds;
    }

    /**
     * Validate session security (IP and User Agent).
     * Only called if session security is enabled.
     *
     * @return bool
     */
    public function validate_session(): bool
    {
        $sessionIp = (string) ($_SESSION['auth']['ip'] ?? '');
        $sessionUa = (string) ($_SESSION['auth']['user_agent'] ?? '');

        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentUa = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // First login - store values
        if ($sessionIp === '' || $sessionUa === '') {
            $_SESSION['auth']['ip'] = $currentIp;
            $_SESSION['auth']['user_agent'] = $currentUa;
            return true;
        }

        // Validate
        return $sessionIp === $currentIp && $sessionUa === $currentUa;
    }

    /**
     * Check if session security is enabled.
     * Reads from settings table: session_security (1=enabled, 0=disabled)
     * Default: disabled (to avoid mobile network issues)
     *
     * @return bool
     */
    private function is_session_security_enabled(): bool
    {
        // Check for constant override
        if (defined('SESSION_SECURITY_ENABLED')) {
            return (bool) SESSION_SECURITY_ENABLED;
        }

        // Check settings table
        $conn = $this->db->connect();
        if ($conn === false) {
            return false;
        }

        $row = $this->db->fetch("SELECT value FROM settings WHERE name='session_security' LIMIT 1");
        
        if (is_array($row) && isset($row['value'])) {
            return (int) $row['value'] === 1;
        }

        return false; // Default: disabled
    }

    /**
     * Login by username.
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

        // Check if account is locked
        if (!$this->check_login_attempts($username)) {
            return false;
        }

        $sql = "SELECT id, username, email, password_hash, role_id, locked_until
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
            $this->record_login_attempt($username, false);
            return false;
        }

        // Verify password
        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->record_login_attempt($username, false);
            return false;
        }

        // Validate role exists
        $roleId = (int) ($user['role_id'] ?? 1);
        if (!$this->role_exists($roleId)) {
            $this->record_login_attempt($username, false);
            return false;
        }

        // Success - record it
        $this->record_login_attempt($username, true);

        // Regenerate session
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }

        // Set session
        $_SESSION['auth'] = [
            'id'            => (int) ($user['id'] ?? 0),
            'username'      => (string) ($user['username'] ?? ''),
            'email'         => (string) ($user['email'] ?? ''),
            'role_id'       => $roleId,
            'last_activity' => time(),
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        // Update last login timestamp and IP
        $this->update_last_login((int) ($user['id'] ?? 0));

        return true;
    }

    /**
     * Logout current user.
     *
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
     * Check if account is locked due to too many failed attempts.
     *
     * @param string $username
     * @return bool True if account is NOT locked (can proceed), False if locked
     */
    public function check_login_attempts(string $username): bool
    {
        $conn = $this->db->connect();
        if ($conn === false) {
            return true; // Fail open (allow login attempt if DB down)
        }

        $sql = "SELECT login_attempts, locked_until FROM users WHERE username=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return true;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            return true; // User doesn't exist yet, allow attempt
        }

        $attempts = (int) ($row['login_attempts'] ?? 0);
        $lockedUntil = (string) ($row['locked_until'] ?? '');

        // Check if locked
        if ($lockedUntil !== '' && $lockedUntil !== '0000-00-00 00:00:00') {
            $lockTime = strtotime($lockedUntil);
            
            if ($lockTime > time()) {
                // Still locked
                return false;
            }
            
            // Lock expired, reset attempts
            $this->reset_login_attempts($username);
            return true;
        }

        // Check attempt count
        return $attempts < self::MAX_LOGIN_ATTEMPTS;
    }

    /**
     * Record login attempt (success or failure).
     *
     * @param string $username
     * @param bool $success
     * @return void
     */
    public function record_login_attempt(string $username, bool $success): void
    {
        $conn = $this->db->connect();
        if ($conn === false) {
            return;
        }

        if ($success) {
            // Reset attempts on successful login
            $this->reset_login_attempts($username);
            return;
        }

        // Increment failed attempts
        $sql = "UPDATE users SET login_attempts = login_attempts + 1 WHERE username=?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->close();

        // Check if we need to lock the account
        $sql = "SELECT login_attempts FROM users WHERE username=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (is_array($row)) {
            $attempts = (int) ($row['login_attempts'] ?? 0);
            
            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                // Lock account
                $lockedUntil = date('Y-m-d H:i:s', time() + (self::LOCKOUT_MINUTES * 60));
                
                $sql = "UPDATE users SET locked_until=? WHERE username=?";
                $stmt = $conn->prepare($sql);
                
                if ($stmt !== false) {
                    $stmt->bind_param('ss', $lockedUntil, $username);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    /**
     * Reset login attempts for user.
     *
     * @param string $username
     * @return void
     */
    private function reset_login_attempts(string $username): void
    {
        $conn = $this->db->connect();
        if ($conn === false) {
            return;
        }

        $sql = "UPDATE users SET login_attempts=0, locked_until=NULL WHERE username=?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update last login timestamp and IP for user.
     *
     * @param int $userId
     * @return void
     */
    private function update_last_login(int $userId): void
    {
        $conn = $this->db->connect();
        if ($conn === false) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = date('Y-m-d H:i:s');

        $sql = "UPDATE users SET last_ip=?, last_user_agent=?, updated_at=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('sssi', $ip, $ua, $now, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Validate that a role_id exists in roles table.
     *
     * @param int $roleId
     * @return bool
     */
    public function role_exists(int $roleId): bool
    {
        $conn = $this->db->connect();
        if ($conn === false) {
            return false;
        }

        $sql = "SELECT id FROM roles WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Generate password reset token.
     *
     * @param string $email
     * @return string|false Token string on success, false on failure
     */
    public function generate_reset_token(string $email): string|false
    {
        $email = trim($email);
        
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $conn = $this->db->connect();
        if ($conn === false) {
            return false;
        }

        // Check if user exists
        $sql = "SELECT id FROM users WHERE email=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($user)) {
            return false;
        }

        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (self::RESET_TOKEN_HOURS * 3600));

        // Store token
        $sql = "UPDATE users SET reset_token=?, reset_expires=? WHERE email=?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('sss', $token, $expires, $email);
        $success = $stmt->execute();
        $stmt->close();

        return $success ? $token : false;
    }

    /**
     * Validate reset token and return user ID if valid.
     *
     * @param string $token
     * @return int|false User ID on success, false if invalid/expired
     */
    public function validate_reset_token(string $token): int|false
    {
        $token = trim($token);
        
        if ($token === '') {
            return false;
        }

        $conn = $this->db->connect();
        if ($conn === false) {
            return false;
        }

        $sql = "SELECT id, reset_expires FROM users WHERE reset_token=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($user)) {
            return false;
        }

        $expires = (string) ($user['reset_expires'] ?? '');
        
        if ($expires === '' || strtotime($expires) < time()) {
            return false; // Expired
        }

        return (int) ($user['id'] ?? 0);
    }

    /**
     * Reset password using valid token.
     *
     * @param string $token
     * @param string $newPassword
     * @return bool
     */
    public function reset_password(string $token, string $newPassword): bool
    {
        $userId = $this->validate_reset_token($token);
        
        if ($userId === false) {
            return false;
        }

        $conn = $this->db->connect();
        if ($conn === false) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        if (!is_string($hash)) {
            return false;
        }

        // Update password and clear token
        $sql = "UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('si', $hash, $userId);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Return current user session data.
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth']) || !isset($_SESSION['auth']['id'])) {
            return null;
        }

        return $_SESSION['auth'];
    }

    /**
     * Current user ID.
     *
     * @return int|null
     */
    public function id(): ?int
    {
        $u = $this->user();
        return is_array($u) ? (int) ($u['id'] ?? 0) : null;
    }

    /**
     * Current username.
     *
     * @return string
     */
    public function username(): string
    {
        $u = $this->user();
        return is_array($u) ? (string) ($u['username'] ?? '') : '';
    }

    /**
     * Current role_id.
     *
     * @return int|null
     */
    public function role_id(): ?int
    {
        $u = $this->user();
        return is_array($u) ? (int) ($u['role_id'] ?? 0) : null;
    }

    /**
     * Check if current user has minimum role level.
     * Role hierarchy: user=1, editor=2, moderator=3, admin=4
     *
     * @param int $minRoleId
     * @return bool
     */
    public function can(int $minRoleId): bool
    {
        $currentRoleId = $this->role_id();
        return $currentRoleId !== null && $currentRoleId >= $minRoleId;
    }

    /**
     * Get current user's role slug from roles table.
     *
     * @return string
     */
    public function role_slug(): string
    {
        $roleId = $this->role_id();
        
        if ($roleId === null) {
            return '';
        }

        $conn = $this->db->connect();
        if ($conn === false) {
            return '';
        }

        $sql = "SELECT slug FROM roles WHERE id=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            return '';
        }

        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? (string) ($row['slug'] ?? '') : '';
    }

    /**
     * Check if current user is admin.
     *
     * @return bool
     */
    public function is_admin(): bool
    {
        return $this->role_slug() === 'admin';
    }

    /**
     * Check if current user is moderator or higher.
     *
     * @return bool
     */
    public function is_moderator(): bool
    {
        $slug = $this->role_slug();
        return in_array($slug, ['moderator', 'admin'], true);
    }

    /**
     * Check if current user is editor or higher.
     *
     * @return bool
     */
    public function is_editor(): bool
    {
        $slug = $this->role_slug();
        return in_array($slug, ['editor', 'moderator', 'admin'], true);
    }

    /**
     * Ensure users table exists with all required columns.
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

        // Create table if missing
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(190) NOT NULL,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role_id INT(11) NOT NULL DEFAULT 1,
                reset_token VARCHAR(64) NULL DEFAULT NULL,
                reset_expires DATETIME NULL DEFAULT NULL,
                login_attempts INT(11) NOT NULL DEFAULT 0,
                locked_until DATETIME NULL DEFAULT NULL,
                last_ip VARCHAR(45) NULL DEFAULT NULL,
                last_user_agent VARCHAR(255) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_username (username),
                UNIQUE KEY uniq_email (email),
                KEY idx_role_id (role_id),
                KEY idx_reset_token (reset_token),
                KEY idx_locked_until (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $conn->query($sql);

        // Add missing columns for existing installations
        $columns = [
            'role_id' => "ALTER TABLE users ADD COLUMN role_id INT(11) NOT NULL DEFAULT 1 AFTER password_hash",
            'reset_token' => "ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL DEFAULT NULL AFTER role_id",
            'reset_expires' => "ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL DEFAULT NULL AFTER reset_token",
            'login_attempts' => "ALTER TABLE users ADD COLUMN login_attempts INT(11) NOT NULL DEFAULT 0 AFTER reset_expires",
            'locked_until' => "ALTER TABLE users ADD COLUMN locked_until DATETIME NULL DEFAULT NULL AFTER login_attempts",
            'last_ip' => "ALTER TABLE users ADD COLUMN last_ip VARCHAR(45) NULL DEFAULT NULL AFTER locked_until",
            'last_user_agent' => "ALTER TABLE users ADD COLUMN last_user_agent VARCHAR(255) NULL DEFAULT NULL AFTER last_ip",
        ];

        foreach ($columns as $colName => $alterSql) {
            $chk = $conn->query("SHOW COLUMNS FROM users LIKE '$colName'");
            
            if ($chk instanceof mysqli_result) {
                $exists = $chk->num_rows > 0;
                $chk->close();
                
                if (!$exists) {
                    $conn->query($alterSql);
                }
            }
        }

        // Create indexes if missing
        $indexes = [
            'idx_role_id' => "CREATE INDEX idx_role_id ON users(role_id)",
            'idx_reset_token' => "CREATE INDEX idx_reset_token ON users(reset_token)",
            'idx_locked_until' => "CREATE INDEX idx_locked_until ON users(locked_until)",
        ];

        foreach ($indexes as $idxName => $createSql) {
            $chk = $conn->query("SHOW INDEX FROM users WHERE Key_name='$idxName'");
            
            if ($chk instanceof mysqli_result) {
                $exists = $chk->num_rows > 0;
                $chk->close();
                
                if (!$exists) {
                    @$conn->query($createSql);
                }
            }
        }
    }
}
