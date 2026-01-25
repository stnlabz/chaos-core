<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Account Module: Profile
 *
 * Route:
 * /profile -> /app/modules/account/account.php
 *
 * Depends on:
 * - global $auth (instance of auth)
 * - global $db   (instance of db)
 */

(function (): void {
    global $auth, $db;

    if (!$auth instanceof auth) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Authentication core is not available.</div></div>';
        return;
    }

    if (!$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Database core is not available.</div></div>';
        return;
    }

    // Require login
    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }

    $sessionUser = $auth->user();
    $userId = (int) (($sessionUser['id'] ?? 0));
    if ($userId <= 0) {
        header('Location: /login');
        exit;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Database connection failed.</div></div>';
        return;
    }

    // ---------------------------------------------------------------------
    // Fetch User Data
    // ---------------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT u.username, u.name, u.email, u.role_id, r.label as role_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $userData = $res->fetch_assoc();
    $stmt->close();

    if (!$userData) {
        $auth->logout();
        header('Location: /login');
        exit;
    }

    $username  = (string) ($userData['username'] ?? '');
    $name      = (string) ($userData['name'] ?? '');
    $email     = (string) ($userData['email'] ?? '');
    $roleName  = (string) ($userData['role_name'] ?? 'User');

    $errorProfile = '';
    $noticeProfile = '';
    $dangerError = '';
    $dangerNotice = '';

    // ---------------------------------------------------------------------
    // POST Actions
    // ---------------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $mode = (string) ($_POST['mode'] ?? '');

        if ($mode === 'profile') {
            $usernameNew = trim((string) ($_POST['username'] ?? $username));
            $nameNew     = trim((string) ($_POST['name'] ?? $name));
            $emailNew    = trim((string) ($_POST['email'] ?? $email));
            $pass        = (string) ($_POST['password'] ?? '');
            $pass2       = (string) ($_POST['password_confirm'] ?? '');

            if ($usernameNew === '' || $nameNew === '' || $emailNew === '') {
                $errorProfile = 'Username, Name, and Email are required.';
            } elseif (!filter_var($emailNew, FILTER_VALIDATE_EMAIL)) {
                $errorProfile = 'A valid email address is required.';
            } elseif ($pass !== '' && $pass !== $pass2) {
                $errorProfile = 'Passwords do not match.';
            } else {
                $now = date('Y-m-d H:i:s');
                if ($pass !== '') {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $stmtU = $conn->prepare("UPDATE users SET username=?, name=?, email=?, password_hash=?, updated_at=? WHERE id=? LIMIT 1");
                    $stmtU->bind_param('sssssi', $usernameNew, $nameNew, $emailNew, $hash, $now, $userId);
                } else {
                    $stmtU = $conn->prepare("UPDATE users SET username=?, name=?, email=?, updated_at=? WHERE id=? LIMIT 1");
                    $stmtU->bind_param('ssssi', $usernameNew, $nameNew, $emailNew, $now, $userId);
                }

                if ($stmtU && $stmtU->execute()) {
                    $noticeProfile = 'Profile updated.';
                    $username = $usernameNew;
                    $name = $nameNew;
                    $email = $emailNew;
                    $stmtU->close();
                } else {
                    $errorProfile = 'Save failed (possible duplicate username or email).';
                }
            }
        }

        if ($mode === 'delete') {
            $stmtD = $conn->prepare("UPDATE users SET role_id = 0 WHERE id = ? LIMIT 1");
            $stmtD->bind_param('i', $userId);
            if ($stmtD->execute()) {
                $auth->logout();
                header('Location: /login?deleted=1');
                exit;
            } else {
                $dangerError = 'Failed to deactivate account.';
            }
            $stmtD->close();
        }
    }
    ?>

    <div class="container my-4 account-profile">
        <div class="row">
            <div class="col-12 col-md-8 col-lg-6">
                <header class="mb-4">
                    <h1 class="h3 mb-0">My Account</h1>
                    <p class="text-muted small">Role: <span class="badge bg-secondary"><?= htmlspecialchars($roleName); ?></span></p>
                </header>

                <section class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Profile Details</h2>

                        <?php if ($errorProfile !== ''): ?>
                            <div class="alert alert-danger small mb-2"><?= htmlspecialchars($errorProfile, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php elseif ($noticeProfile !== ''): ?>
                            <div class="alert alert-success small mb-2"><?= htmlspecialchars($noticeProfile, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>

                        <form method="post" action="/profile">
                            <input type="hidden" name="mode" value="profile">

                            <div class="mb-2">
                                <label class="small fw-semibold" for="username">Username</label>
                                <input type="text" name="username" id="username" class="form-control" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="mb-2">
                                <label class="small fw-semibold" for="name">Full Name</label>
                                <input type="text" name="name" id="name" class="form-control" value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="mb-2">
                                <label class="small fw-semibold" for="email">Email Address</label>
                                <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="row mt-3">
                                <div class="col-6">
                                    <label class="small fw-semibold" for="password">New Password</label>
                                    <input type="password" name="password" id="password" class="form-control form-control-sm" placeholder="Leave blank to keep">
                                </div>
                                <div class="col-6">
                                    <label class="small fw-semibold" for="password_confirm">Confirm New</label>
                                    <input type="password" name="password_confirm" id="password_confirm" class="form-control form-control-sm">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm mt-3">Update Profile</button>
                        </form>
                    </div>
                </section>

                <hr class="my-4">

                <section class="account-danger-zone mt-3">
                    <h2 class="h5 text-danger">Danger Zone</h2>

                    <?php if ($dangerError !== ''): ?>
                        <div class="alert alert-danger small mb-2">
                            <?= htmlspecialchars($dangerError, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php elseif ($dangerNotice !== ''): ?>
                        <div class="alert alert-success small mb-2">
                            <?= htmlspecialchars($dangerNotice, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <p class="small text-muted mb-2">
                        Deleting your account will deactivate your login.
                    </p>

                    <form
                        method="post"
                        action="/profile"
                        onsubmit="return confirm('Are you sure you want to delete your account? This cannot be undone.');"
                    >
                        <input type="hidden" name="mode" value="delete">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete Account</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
    <?php
})();
