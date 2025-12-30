<?php
declare(strict_types=1);

(function (): void {
    global $db, $auth;

    if (!$db instanceof db) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $crumb = '<small><a href="/admin">Admin</a> &raquo; Users</small>';

    $error = '';

    $roles = $db->fetch_all("SELECT id, slug, label FROM roles ORDER BY id ASC");
    $roles = is_array($roles) ? $roles : [];
    $roleMap = [];
    foreach ($roles as $r) {
        $rid = (int)($r['id'] ?? 0);
        if ($rid > 0) {
            $roleMap[$rid] = [
                'slug'  => (string)($r['slug'] ?? ''),
                'label' => (string)($r['label'] ?? ''),
            ];
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $op = (string)($_POST['op'] ?? '');

        if ($op === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $email    = trim((string)($_POST['email'] ?? ''));
            $pass     = (string)($_POST['password'] ?? '');
            $roleId   = (int)($_POST['role_id'] ?? 1);

            if ($username === '') {
                $error = 'Username is required.';
            } elseif ($pass === '' || strlen($pass) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Email is not valid.';
            } elseif (!isset($roleMap[$roleId])) {
                $error = 'Invalid role.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $now  = gmdate('Y-m-d H:i:s');

                $stmt = $conn->prepare("INSERT INTO users (username, email, role_id, password_hash, created_at, updated_at) VALUES (?,?,?,?,?,?)");
                if ($stmt === false) {
                    $error = 'Create failed.';
                } else {
                    $stmt->bind_param('ssisss', $username, $email, $roleId, $hash, $now, $now);
                    $ok = $stmt->execute();
                    $stmt->close();

                    if ($ok) {
                        header('Location: /admin?action=users');
                        exit;
                    }
                    $error = 'Create failed.';
                }
            }
        }

        if ($op === 'save_role') {
            $uid = (int)($_POST['id'] ?? 0);
            $roleId = (int)($_POST['role_id'] ?? 1);

            if ($uid > 0 && isset($roleMap[$roleId])) {
                $now = gmdate('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE users SET role_id=?, role=?, updated_at=? WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('iisi', $roleId, $roleId, $now, $uid);
                    $stmt->execute();
                    $stmt->close();
                    header('Location: /admin?action=users');
                    exit;
                }
                $error = 'Update failed.';
            } else {
                $error = 'Invalid input.';
            }
        }

        if ($op === 'delete') {
            $uid = (int)($_POST['id'] ?? 0);
            $meId = (int)($auth instanceof auth ? ($auth->id() ?? 0) : 0);

            if ($uid > 0 && $meId !== $uid) {
                $stmt = $conn->prepare("DELETE FROM users WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $stmt->close();
                    header('Location: /admin?action=users');
                    exit;
                }
                $error = 'Delete failed.';
            } else {
                $error = 'Cannot delete current user.';
            }
        }
    }

    $rows = $db->fetch_all("
        SELECT u.id, u.username, u.email, u.role_id, u.created_at, u.updated_at, r.slug AS role_slug, r.label AS role_label
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY u.id DESC
        LIMIT 250
    ");
    $rows = is_array($rows) ? $rows : [];

    ?>
    <div class="container my-4 admin-users">
        <?= $crumb; ?>

        <div class="d-flex align-items-center justify-content-between mt-2 mb-3">
            <h1 class="h3 m-0">Users</h1>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger small mb-2"><?= $esc($error); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12 col-lg-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Create User</div>

                        <form method="post" action="/admin?action=users">
                            <input type="hidden" name="op" value="create">

                            <div class="row">
                                <div class="col-12 col-md-6">
                                    <div class="small text-muted mb-1">Username</div>
                                    <input class="form-control" type="text" name="username" value="">
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="small text-muted mb-1">Email</div>
                                    <input class="form-control" type="text" name="email" value="">
                                </div>
                            </div>

                            <div class="row mt-2">
                                <div class="col-12 col-md-6">
                                    <div class="small text-muted mb-1">Password</div>
                                    <input class="form-control" type="password" name="password" value="">
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="small text-muted mb-1">Role</div>
                                    <select class="form-control" name="role_id">
                                        <?php foreach ($roleMap as $rid => $meta): ?>
                                            <option value="<?= (int)$rid; ?>"><?= $esc((string)($meta['label'] ?: $meta['slug'])); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button class="btn" type="submit">Create</button>
                            </div>
                        </form>

                        <div class="small text-muted mt-2">Default role should be <strong>User</strong>.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Roles</div>
                        <?php if (empty($roleMap)): ?>
                            <div class="small text-muted">No roles found.</div>
                        <?php else: ?>
                            <div class="small text-muted">
                                <?php foreach ($roleMap as $rid => $meta): ?>
                                    <div><strong><?= (int)$rid; ?></strong> — <?= $esc((string)($meta['slug'] ?: '')); ?> (<?= $esc((string)($meta['label'] ?: '')); ?>)</div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <?php if (empty($rows)): ?>
                    <div class="small text-muted">No users.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:80px;">ID</th>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th style="width:240px;">Role</th>
                                    <th style="width:180px;">Updated</th>
                                    <th style="width:160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $uid = (int)($r['id'] ?? 0);
                                    $uname = (string)($r['username'] ?? '');
                                    $uemail = (string)($r['email'] ?? '');
                                    $rid = (int)($r['role_id'] ?? 1);
                                    $updated = (string)($r['updated_at'] ?? '');
                                    $meId = (int)($auth instanceof auth ? ($auth->id() ?? 0) : 0);
                                    $isMe = ($meId === $uid);
                                    ?>
                                    <tr>
                                        <td><?= (int)$uid; ?></td>
                                        <td class="fw-semibold"><?= $esc(ucfirst($uname)); ?></td>
                                        <td><?= $esc($uemail); ?></td>
                                        <td>
                                            <form method="post" action="/admin?action=users" class="d-flex gap-2" style="align-items:center;">
                                                <input type="hidden" name="op" value="save_role">
                                                <input type="hidden" name="id" value="<?= (int)$uid; ?>">
                                                <select class="form-control" name="role_id" style="max-width: 180px;">
                                                    <?php foreach ($roleMap as $xid => $meta): ?>
                                                        <option value="<?= (int)$xid; ?>" <?= ((int)$xid === $rid) ? 'selected' : ''; ?>>
                                                            <?= $esc((string)($meta['label'] ?: $meta['slug'])); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn" type="submit">Save</button>
                                            </form>
                                        </td>
                                        <td class="small text-muted"><?= $esc($updated); ?></td>
                                        <td>
                                            <?php if (!$isMe): ?>
                                                <form method="post" action="/admin?action=users" onsubmit="return confirm('Delete this user?');">
                                                    <input type="hidden" name="op" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$uid; ?>">
                                                    <button class="btn btn-danger" type="submit">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="small text-muted">Current</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
<?php
})();

