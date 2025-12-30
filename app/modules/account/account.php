<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Account Module: Profile
 *
 * Route:
 *   /profile -> /app/modules/account/account.php
 *
 * Depends on:
 *   - global $auth (instance of auth)
 *   - global $db   (instance of db)
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
    // Always resolve role via role_id -> roles table (never trust stale fields)
    // ---------------------------------------------------------------------
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.username,
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

    $username = (string) ($userRow['username'] ?? '');
    $email    = (string) ($userRow['email'] ?? '');
    $roleId   = (int) ($userRow['role_id'] ?? 1);

    $roleLabel = (string) ($userRow['role_label'] ?? '');
    $roleSlug  = (string) ($userRow['role_slug'] ?? '');

    if ($roleLabel === '') {
        // fallback: humanize slug or role id
        $roleLabel = $roleSlug !== '' ? ucfirst($roleSlug) : ('Role #' . (string)$roleId);
    }

    $errorProfile  = '';
    $noticeProfile = '';
    $dangerError   = '';
    $dangerNotice  = '';

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $mode = (string) ($_POST['mode'] ?? 'profile');

        // ---- Delete account (soft deactivate) ---------------------------
        if ($mode === 'delete') {
            $now = date('Y-m-d H:i:s');

            // NOTE: requires users.is_active to exist; you’ve been using it.
            $stmtD = $conn->prepare("
                UPDATE users
                SET is_active = 0,
                    updated_at = ?
                WHERE id = ?
                LIMIT 1
            ");

            if ($stmtD === false) {
                $dangerError = 'Failed to delete account.';
            } else {
                $stmtD->bind_param('si', $now, $userId);
                $stmtD->execute();
                $ok = ($stmtD->affected_rows >= 0);
                $stmtD->close();

                if ($ok) {
                    $auth->logout();
                    header('Location: /');
                    exit;
                }

                $dangerError = 'Failed to delete account. Please try again.';
            }
        } else {
            // ---- Profile update -----------------------------------------
            $usernameNew = trim((string) ($_POST['username'] ?? $username));
            $emailNew    = trim((string) ($_POST['email'] ?? $email)); // optional
            $pass        = (string) ($_POST['password'] ?? '');
            $pass2       = (string) ($_POST['password_confirm'] ?? '');

            if ($usernameNew === '') {
                $errorProfile = 'Username is required.';
            } elseif ($emailNew !== '' && !filter_var($emailNew, FILTER_VALIDATE_EMAIL)) {
                $errorProfile = 'Email is not valid.';
            } elseif ($pass !== '' && $pass !== $pass2) {
                $errorProfile = 'Passwords do not match.';
            } elseif ($pass !== '' && strlen($pass) < 8) {
                $errorProfile = 'Password must be at least 8 characters.';
            } else {
                $now = date('Y-m-d H:i:s');

                if ($pass !== '') {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);

                    $stmtU = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            email = ?,
                            password_hash = ?,
                            updated_at = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if ($stmtU === false) {
                        $errorProfile = 'Save failed.';
                    } else {
                        $stmtU->bind_param('ssssi', $usernameNew, $emailNew, $hash, $now, $userId);
                        $stmtU->execute();
                        $stmtU->close();

                        $noticeProfile = 'Profile updated.';
                        $username = $usernameNew;
                        $email = $emailNew;
                    }
                } else {
                    $stmtU = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            email = ?,
                            updated_at = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if ($stmtU === false) {
                        $errorProfile = 'Save failed.';
                    } else {
                        $stmtU->bind_param('sssi', $usernameNew, $emailNew, $now, $userId);
                        $stmtU->execute();
                        $stmtU->close();

                        $noticeProfile = 'Profile updated.';
                        $username = $usernameNew;
                        $email = $emailNew;
                    }
                }
            }
        }
    }

    // ---- Recent activity (last 5 replies) ------------------------------
    $activity = [];
    $activitySql = "
        SELECT
            r.body,
            r.created_at,
            r.post_id,
            p.slug,
            p.title
        FROM post_replies AS r
        LEFT JOIN posts AS p ON p.id = r.post_id
        WHERE r.author_id = {$userId}
          AND r.status = 1
        ORDER BY r.created_at DESC
        LIMIT 5
    ";
    $activity = $db->fetch_all($activitySql);

    ?>
    <div class="container my-4 account-profile">
        <div class="row">
            <div class="col-12 col-md-8">
                <h1 class="home-title">Your Profile</h1>

                <?php if ($errorProfile !== ''): ?>
                    <div class="alert alert-danger small mb-2">
                        <?= htmlspecialchars($errorProfile, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php elseif ($noticeProfile !== ''): ?>
                    <div class="alert alert-success small mb-2">
                        <?= htmlspecialchars($noticeProfile, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="/profile">
                    <input type="hidden" name="mode" value="profile">

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

                    <div class="mb-2">
                        <label class="small fw-semibold">Role</label>
                        <div class="form-control-plaintext small text-muted">
                            <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-2">
                        <label class="small fw-semibold" for="password">New Password (optional)</label>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            autocomplete="new-password"
                        >
                        <div class="form-text small">
                            Leave blank to keep your current password.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small fw-semibold" for="password_confirm">Confirm New Password</label>
                        <input
                            type="password"
                            name="password_confirm"
                            id="password_confirm"
                            class="form-control"
                            autocomplete="new-password"
                        >
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        Save Changes
                    </button>
                    <a href="/" class="btn btn-outline-secondary btn-sm">
                        Cancel
                    </a>
                </form>

                <?php if (is_array($activity) && !empty($activity)): ?>
                    <hr class="my-4">
                    <section class="account-activity">
                        <h2 class="h5 mb-2">Recent Activity</h2>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($activity as $item): ?>
                                <li class="list-group-item px-0">
                                    <div class="small text-muted">
                                        <?= htmlspecialchars((string)($item['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($item['title']) && !empty($item['slug'])): ?>
                                            · In
                                            <a href="/posts/<?= htmlspecialchars((string)$item['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small">
                                        <?= nl2br(htmlspecialchars((string) ($item['body'] ?? ''), ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

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
                        Deleting your account will deactivate your login and mark your user as inactive.
                        Existing posts or replies may remain visible.
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

