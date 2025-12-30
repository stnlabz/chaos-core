<?php

declare(strict_types=1);

/**
 * Signup (username-based)
 * Route: /signup
 */

(function (): void {
    global $auth, $db;

    if (!$auth instanceof auth || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Auth/DB not available.</div></div>';
        return;
    }

    if ($auth->check()) {
        header('Location: /profile');
        exit;
    }

    $error = '';
    $username = '';
    $email = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $pass     = (string) ($_POST['password'] ?? '');

        if ($username === '' || $pass === '') {
            $error = 'Username and password are required.';
        } else {
            $conn = $db->connect();
            if ($conn === false) {
                $error = 'DB connection failed.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                if (!is_string($hash) || $hash === '') {
                    $error = 'Password hashing failed.';
                } else {
                    $role = 1;

                    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
                    if ($stmt === false) {
                        $error = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('ssss', $username, $email, $hash, $role);
                        if (!$stmt->execute()) {
                            $error = 'Could not create account (username may already exist).';
                        }
                        $stmt->close();
                    }
                }
            }

            if ($error === '') {
                header('Location: /login');
                exit;
            }
        }
    }
    ?>

    <div class="container my-4 account-signup">
        <div class="row">
            <div class="col-12 col-md-6">
                <h1 class="home-title">Sign Up</h1>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger small mb-2">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="/signup">
                    <div class="mb-2">
                        <label class="small fw-semibold" for="username">Username</label>
                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="form-control"
                            value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                            required
                        >
                    </div>

                    <div class="mb-2">
                        <label class="small fw-semibold" for="email">Email (optional)</label>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-control"
                            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="small fw-semibold" for="password">Password</label>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">Create Account</button>
                    <a href="/login" class="btn btn-outline-secondary btn-sm ms-2">Back to Login</a>
                </form>
            </div>
        </div>
    </div>

    <?php
})();

