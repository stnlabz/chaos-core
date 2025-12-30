<?php

declare(strict_types=1);

/**
 * Chaos CMS — Admin: Roles
 * Route: /admin?action=roles
 *
 * Table: roles
 * Hard rule: role id 4 cannot be deleted.
 */

(function (): void {
    global $db;

    if (!$db instanceof db) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!function_exists('e')) {
        function e(string $v): string
        {
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }
    }

    // Local CSRF (isolated from core to avoid token/key mismatches)
    $csrfKey = 'chaos_admin_roles_csrf';

    if (!isset($_SESSION[$csrfKey]) || !is_string($_SESSION[$csrfKey]) || $_SESSION[$csrfKey] === '') {
        $_SESSION[$csrfKey] = bin2hex(random_bytes(16));
    }

    $pageCsrf = (string) $_SESSION[$csrfKey];

    $csrfOk = static function (string $token) use ($pageCsrf): bool {
        return $token !== '' && hash_equals($pageCsrf, $token);
    };

    $countUsersForRole = static function (mysqli $conn, int $roleId): int {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM users WHERE role=? OR role_id=?');
        if ($stmt === false) {
            return 0;
        }

        $stmt->bind_param('ii', $roleId, $roleId);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;

        $stmt->close();

        return (int) ($row['c'] ?? 0);
    };

    $flash = [
        'ok'  => '',
        'err' => '',
    ];

    $action = (string) ($_POST['do'] ?? '');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string) ($_POST['csrf'] ?? '');

        if (!$csrfOk($token)) {
            $flash['err'] = 'Invalid CSRF token.';
        } else {
            if ($action === 'create') {
                $slug  = trim((string) ($_POST['slug'] ?? ''));
                $label = trim((string) ($_POST['label'] ?? ''));

                if ($slug === '' || $label === '') {
                    $flash['err'] = 'Slug and label are required.';
                } else {
                    $stmt = $conn->prepare('INSERT INTO roles (slug, label) VALUES (?, ?)');
                    if ($stmt === false) {
                        $flash['err'] = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('ss', $slug, $label);

                        if (!$stmt->execute()) {
                            $flash['err'] = 'Create failed: ' . (string) $stmt->error;
                        } else {
                            $flash['ok'] = 'Role created.';
                        }

                        $stmt->close();
                    }
                }
            }

            if ($action === 'update') {
                $id    = (int) ($_POST['id'] ?? 0);
                $slug  = trim((string) ($_POST['slug'] ?? ''));
                $label = trim((string) ($_POST['label'] ?? ''));

                if ($id < 1 || $slug === '' || $label === '') {
                    $flash['err'] = 'Invalid update payload.';
                } else {
                    $stmt = $conn->prepare('UPDATE roles SET slug=?, label=? WHERE id=? LIMIT 1');
                    if ($stmt === false) {
                        $flash['err'] = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('ssi', $slug, $label, $id);

                        if (!$stmt->execute()) {
                            $flash['err'] = 'Update failed: ' . (string) $stmt->error;
                        } else {
                            $flash['ok'] = 'Role updated.';
                        }

                        $stmt->close();
                    }
                }
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);

                if ($id < 1) {
                    $flash['err'] = 'Invalid delete payload.';
                } elseif ($id === 4) {
                    $flash['err'] = 'Admin role (4) cannot be deleted.';
                } else {
                    $inUse = $countUsersForRole($conn, $id);
                    if ($inUse > 0) {
                        $flash['err'] = 'Role is in use by users. Remove assignments first.';
                    } else {
                        $stmt = $conn->prepare('DELETE FROM roles WHERE id=? LIMIT 1');
                        if ($stmt === false) {
                            $flash['err'] = 'DB prepare failed.';
                        } else {
                            $stmt->bind_param('i', $id);

                            if (!$stmt->execute()) {
                                $flash['err'] = 'Delete failed: ' . (string) $stmt->error;
                            } else {
                                $flash['ok'] = 'Role deleted.';
                            }

                            $stmt->close();
                        }
                    }
                }
            }
        }
    }

    $rows = [];
    $res  = $conn->query('SELECT id, slug, label FROM roles ORDER BY id ASC');

    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
        $res->free();
    }

    echo '<div class="container my-4">';
    echo '<div class="text-muted small">Admin &raquo; Roles</div>';
    echo '<h1 class="h3 mt-2 mb-3">Roles</h1>';

    if ($flash['ok'] !== '') {
        echo '<div class="alert alert-success">' . e($flash['ok']) . '</div>';
    }

    if ($flash['err'] !== '') {
        echo '<div class="alert alert-danger">' . e($flash['err']) . '</div>';
    }

    echo '<div class="card mb-3"><div class="card-body">';
    echo '<div class="fw-semibold mb-2">Add Role</div>';
    echo '<form method="post" action="/admin?action=roles">';
    echo '<input type="hidden" name="csrf" value="' . e($pageCsrf) . '">';
    echo '<input type="hidden" name="do" value="create">';

    echo '<div class="row g-2">';
    echo '<div class="col-12 col-md-5"><input class="form-control" name="slug" placeholder="slug (e.g. editor)" required></div>';
    echo '<div class="col-12 col-md-7"><input class="form-control" name="label" placeholder="Label (e.g. Editor)" required></div>';
    echo '</div>';

    echo '<button class="btn btn-sm mt-3" type="submit">Create</button>';
    echo '</form>';
    echo '</div></div>';

    echo '<div class="card"><div class="card-body">';
    echo '<div class="fw-semibold mb-2">Existing Roles</div>';

    if (!$rows) {
        echo '<div class="text-muted">No roles found.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm align-middle">';
        echo '<thead><tr><th>ID</th><th>Slug</th><th>Label</th><th>In Use</th><th>Actions</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $id    = (int) ($r['id'] ?? 0);
            $slug  = (string) ($r['slug'] ?? '');
            $label = (string) ($r['label'] ?? '');

            $inUse = $id > 0 ? $countUsersForRole($conn, $id) : 0;

            echo '<tr>';
            echo '<td>' . $id . ($id === 4 ? ' <span class="badge text-bg-warning">admin</span>' : '') . '</td>';
            echo '<td><code>' . e($slug) . '</code></td>';
            echo '<td>' . e($label) . '</td>';
            echo '<td>' . $inUse . '</td>';
            echo '<td style="min-width:280px;">';

            if ($id !== 4) {
                echo '<form class="d-inline" method="post" action="/admin?action=roles">';
                echo '<input type="hidden" name="csrf" value="' . e($pageCsrf) . '">';
                echo '<input type="hidden" name="do" value="delete">';
                echo '<input type="hidden" name="id" value="' . $id . '">';
                echo '<button class="btn btn-sm btn-danger" type="submit" onclick="return confirm(\'Delete this role?\')">Delete</button>';
                echo '</form> ';
            } else {
                echo '<span class="text-muted small">Protected</span> ';
            }

            echo '<button class="btn btn-sm" type="button" data-role-edit="' . $id . '">Edit</button>';
            echo '</td>';
            echo '</tr>';

            echo '<tr data-role-row="' . $id . '" style="display:none;">';
            echo '<td colspan="5">';
            echo '<form method="post" action="/admin?action=roles">';
            echo '<input type="hidden" name="csrf" value="' . e($pageCsrf) . '">';
            echo '<input type="hidden" name="do" value="update">';
            echo '<input type="hidden" name="id" value="' . $id . '">';

            echo '<div class="row g-2">';
            echo '<div class="col-12 col-md-5"><input class="form-control" name="slug" value="' . e($slug) . '" required></div>';
            echo '<div class="col-12 col-md-7"><input class="form-control" name="label" value="' . e($label) . '" required></div>';
            echo '</div>';

            echo '<button class="btn btn-sm mt-3" type="submit">Save</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div></div>';
    echo '</div>';

    echo '<script>';
    echo 'document.querySelectorAll("[data-role-edit]").forEach(function(btn){';
    echo '  btn.addEventListener("click", function(){';
    echo '    var id = btn.getAttribute("data-role-edit");';
    echo '    var row = document.querySelector("[data-role-row=\'" + id + "\']");';
    echo '    if (!row) { return; }';
    echo '    row.style.display = (row.style.display === "none" ? "" : "none");';
    echo '  });';
    echo '});';
    echo '</script>';
})();

