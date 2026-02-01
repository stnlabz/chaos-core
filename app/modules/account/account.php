<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Account Module: Profile
 *
 * Route:
 * /profile -> /app/modules/account/account.php
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

    // 1. FETCH CURRENT USER DATA
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.username,
            u.name,
            u.email,
            u.role_id,
            COALESCE(r.slug, '')  AS role_slug,
            COALESCE(r.label, '') AS role_label
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");

    if ($stmt === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">User lookup failed.</div></div>';
        return;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $userRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($userRow) || empty($userRow['id'])) {
        header('Location: /login');
        exit;
    }

    $username  = (string) ($userRow['username'] ?? '');
    $name      = (string) ($userRow['name'] ?? '');
    $email     = (string) ($userRow['email'] ?? '');
    $roleId    = (int) ($userRow['role_id'] ?? 1);
    $roleLabel = (string) ($userRow['role_label'] ?? 'User');

    $errorProfile  = '';
    $noticeProfile = '';
    $dangerError   = '';

    // 2. HANDLE UPDATES
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $mode = (string) ($_POST['mode'] ?? 'profile');

        if ($mode === 'delete') {
            $now = date('Y-m-d H:i:s');
            $stmtD = $conn->prepare("UPDATE users SET is_active = 0, updated_at = ? WHERE id = ? LIMIT 1");
            if ($stmtD) {
                $stmtD->bind_param('si', $now, $userId);
                $stmtD->execute();
                if ($stmtD->affected_rows >= 0) {
                    $stmtD->close();
                    $auth->logout();
                    header('Location: /');
                    exit;
                }
                $stmtD->close();
            }
            $dangerError = 'Failed to delete account.';
        } else {
            // PROFILE UPDATE LOGIC
            $usernameNew = trim((string) ($_POST['username'] ?? $username));
            $nameNew     = trim((string) ($_POST['name'] ?? $name));
            $emailNew    = trim((string) ($_POST['email'] ?? $email));
            $pass        = (string) ($_POST['password'] ?? '');
            $pass2       = (string) ($_POST['password_confirm'] ?? '');

            if ($usernameNew === '' || $nameNew === '' || $emailNew === '') {
                $errorProfile = 'Username, Name, and Email are required.';
            } elseif (!filter_var($emailNew, FILTER_VALIDATE_EMAIL)) {
                $errorProfile = 'Email is not valid.';
            } elseif ($pass !== '' && $pass !== $pass2) {
                $errorProfile = 'Passwords do not match.';
            } elseif ($pass !== '' && strlen($pass) < 8) {
                $errorProfile = 'Password must be at least 8 characters.';
            } else {
                $now = date('Y-m-d H:i:s');

                if ($pass !== '') {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $stmtU = $conn->prepare("UPDATE users SET username = ?, name = ?, email = ?, password_hash = ?, updated_at = ? WHERE id = ? LIMIT 1");
                    $stmtU->bind_param('sssssi', $usernameNew, $nameNew, $emailNew, $hash, $now, $userId);
                } else {
                    $stmtU = $conn->prepare("UPDATE users SET username = ?, name = ?, email = ?, updated_at = ? WHERE id = ? LIMIT 1");
                    $stmtU->bind_param('ssssi', $usernameNew, $nameNew, $emailNew, $now, $userId);
                }

                if ($stmtU && $stmtU->execute()) {
                    $noticeProfile = 'Profile updated.';
                    $username = $usernameNew;
                    $name     = $nameNew;
                    $email    = $emailNew;
                    $stmtU->close();
                } else {
                    $errorProfile = 'Save failed (Username/Email may be taken).';
                }
            }
        }
    }

    // 3. RECENT ACTIVITY
    $activitySql = "
        SELECT r.body, r.created_at, p.slug, p.title
        FROM post_replies AS r
        LEFT JOIN posts AS p ON p.id = r.post_id
        WHERE r.author_id = {$userId} AND r.status = 1
        ORDER BY r.created_at DESC LIMIT 5";
    $activity = $db->fetch_all($activitySql);
    ?>

    <div class="container my-4 account-profile">
        <div class="row">
            <div class="col-12 col-md-8">
                <h1 class="home-title">Your Profile</h1>

                <?php if ($errorProfile !== ''): ?>
                    <div class="alert alert-danger small mb-2"><?= htmlspecialchars($errorProfile); ?></div>
                <?php elseif ($noticeProfile !== ''): ?>
                    <div class="alert alert-success small mb-2"><?= htmlspecialchars($noticeProfile); ?></div>
                <?php endif; ?>

                <form method="post" action="/profile">
                    <input type="hidden" name="mode" value="profile">

                    <div class="mb-2">
                        <label class="small fw-semibold" for="username">Username (Login)</label>
                        <input type="text" name="username" id="username" class="form-control" value="<?= htmlspecialchars($username); ?>" required>
                    </div>
                    
                    <div class="mb-2">
                        <label class="small fw-semibold" for="name">Real Name</label>
                        <input type="text" name="name" id="name" class="form-control" value="<?= htmlspecialchars($name); ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="small fw-semibold" for="email">Email Address</label>
                        <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($email); ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="small fw-semibold">Access Level</label>
                        <div class="form-control-plaintext small text-muted">
                            <span class="badge bg-secondary"><?= htmlspecialchars($roleLabel); ?></span>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-2">
                        <label class="small fw-semibold" for="password">Change Password (optional)</label>
                        <input type="password" name="password" id="password" class="form-control" autocomplete="new-password">
                    </div>

                    <div class="mb-3">
                        <label class="small fw-semibold" for="password_confirm">Confirm New Password</label>
                        <input type="password" name="password_confirm" id="password_confirm" class="form-control" autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                    <a href="/" class="btn btn-outline-secondary btn-sm ms-2">Cancel</a>
                </form>

                <?php if (!empty($activity)): ?>
                    <hr class="my-4">
                    <section class="account-activity">
                        <h2 class="h5 mb-2">Recent Activity</h2>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($activity as $item): ?>
                                <li class="list-group-item px-0">
                                    <div class="small text-muted">
                                        <?= htmlspecialchars((string)$item['created_at']); ?> · 
                                        <a href="/posts/<?= htmlspecialchars((string)$item['slug']); ?>"><?= htmlspecialchars((string)$item['title']); ?></a>
                                    </div>
                                    <div class="small"><?= nl2br(htmlspecialchars((string)$item['body'])); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <hr class="my-4">

                <section class="account-danger-zone mt-3">
                    <h2 class="h5 text-danger">Danger Zone</h2>
                    <?php if ($dangerError !== ''): ?>
                        <div class="alert alert-danger small mb-2"><?= htmlspecialchars($dangerError); ?></div>
                    <?php endif; ?>
                    <p class="small text-muted mb-2">Deactivating your account will disable your login. This is a soft-delete.</p>
                    <form method="post" action="/profile" onsubmit="return confirm('Deactivate your account?');">
                        <input type="hidden" name="mode" value="delete">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate Account</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
    <?php
})();
