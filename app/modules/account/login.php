<?php
declare(strict_types=1);

/**
 * Chaos CMS DB — Account: Login
 * Path: /app/modules/account/login.php
 *
 * Rules:
 * - Do NOT call auth statically.
 * - Redirect before output.
 * - Use $auth->login() if available; otherwise fallback to DB login.
 */

(function (): void {
    global $db, $auth;

    // Basic guards
    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB not available.</div></div>';
        return;
    }

    // If auth exists and user is already logged in, bounce them.
    $isLoggedIn = (isset($auth) && $auth instanceof auth) ? (bool) $auth->check() : false;
    if ($isLoggedIn) {
        header('Location: /profile');
        exit;
    }

    $error = '';
    $username = '';

    /**
     * Fallback login path if $auth->login() does not exist.
     * Sets a simple session user id. (Your auth core can later standardize this.)
     */
    $fallback_login = static function (string $user, string $pass) use ($db): bool {
        $user = trim($user);
        if ($user === '' || $pass === '') {
            return false;
        }

        $conn = $db->connect();
        if ($conn === false) {
            return false;
        }

        // Prefer username login, but allow email as convenience.
        $sql = 'SELECT id, username, email, password_hash, role_id
                FROM users
                WHERE (username = ? OR email = ?)
                LIMIT 1';

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('ss', $user, $user);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            return false;
        }

        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($pass, $hash)) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Minimal session contract (keep it simple).
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['role_id'] = (int) ($row['role_id'] ?? 1);

        // Regenerate if possible (ignore if headers already sent by some rogue plugin).
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }

        return true;
    };

    // Handle POST
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Username and password are required.';
        } else {
            $ok = false;

            // Preferred: use auth object if it supports login()
            if (isset($auth) && $auth instanceof auth && method_exists($auth, 'login')) {
                try {
                    /** @var mixed $res */
                    $res = $auth->login($username, $password);
                    $ok = ($res === true);
                } catch (Throwable $e) {
                    $ok = false;
                }
            } else {
                // Fallback: direct DB login
                $ok = $fallback_login($username, $password);
            }

            if ($ok) {
                header('Location: /profile');
                exit;
            }

            $error = 'Invalid username/email or password.';
        }
    }

    // Render
    ?>
    <div class="container my-4 account-login" style="max-width: 520px;">
        <h1 class="h3 mb-3">Login</h1>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger small"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" class="card">
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="username">Username (or Email)</label>
                    <input
                        id="username"
                        name="username"
                        class="form-control"
                        value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Login</button>
                    <a class="btn btn-outline-secondary" href="/signup">Sign up</a>
                </div>
            </div>
        </form>
    </div>
    <?php
})();

