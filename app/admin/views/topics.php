<?php

declare(strict_types=1);

/**
 * Chaos CMS — Admin: Topics
 * Route: /admin?action=topics
 *
 * Table: topics
 * - id, slug, label, is_public, created_at
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
    $csrfKey = 'chaos_admin_topics_csrf';

    if (!isset($_SESSION[$csrfKey]) || !is_string($_SESSION[$csrfKey]) || $_SESSION[$csrfKey] === '') {
        $_SESSION[$csrfKey] = bin2hex(random_bytes(16));
    }

    $pageCsrf = (string) $_SESSION[$csrfKey];

    $csrfOk = static function (string $token) use ($pageCsrf): bool {
        return $token !== '' && hash_equals($pageCsrf, $token);
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
                $slug     = trim((string) ($_POST['slug'] ?? ''));
                $label    = trim((string) ($_POST['label'] ?? ''));
                $isPublic = ((int) ($_POST['is_public'] ?? 1) === 1) ? 1 : 0;

                if ($slug === '' || $label === '') {
                    $flash['err'] = 'Slug and label are required.';
                } else {
                    $stmt = $conn->prepare('INSERT INTO topics (slug, label, is_public, created_at) VALUES (?, ?, ?, NOW())');

                    if ($stmt === false) {
                        $flash['err'] = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('ssi', $slug, $label, $isPublic);

                        if (!$stmt->execute()) {
                            $flash['err'] = 'Create failed: ' . (string) $stmt->error;
                        } else {
                            $flash['ok'] = 'Topic created.';
                        }

                        $stmt->close();
                    }
                }
            }

            if ($action === 'update') {
                $id       = (int) ($_POST['id'] ?? 0);
                $slug     = trim((string) ($_POST['slug'] ?? ''));
                $label    = trim((string) ($_POST['label'] ?? ''));
                $isPublic = ((int) ($_POST['is_public'] ?? 1) === 1) ? 1 : 0;

                if ($id < 1 || $slug === '' || $label === '') {
                    $flash['err'] = 'Invalid update payload.';
                } else {
                    $stmt = $conn->prepare('UPDATE topics SET slug=?, label=?, is_public=? WHERE id=? LIMIT 1');

                    if ($stmt === false) {
                        $flash['err'] = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('ssii', $slug, $label, $isPublic, $id);

                        if (!$stmt->execute()) {
                            $flash['err'] = 'Update failed: ' . (string) $stmt->error;
                        } else {
                            $flash['ok'] = 'Topic updated.';
                        }

                        $stmt->close();
                    }
                }
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);

                if ($id < 1) {
                    $flash['err'] = 'Invalid delete payload.';
                } else {
                    $stmt = $conn->prepare('DELETE FROM topics WHERE id=? LIMIT 1');

                    if ($stmt === false) {
                        $flash['err'] = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('i', $id);

                        if (!$stmt->execute()) {
                            $flash['err'] = 'Delete failed: ' . (string) $stmt->error;
                        } else {
                            $flash['ok'] = 'Topic deleted.';
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }

    $rows = [];
    $res  = $conn->query('SELECT id, slug, label, is_public, created_at FROM topics ORDER BY label ASC');

    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
        $res->free();
    }

    echo '<div class="container my-4">';
    echo '<div class="text-muted small">Admin &raquo; Topics</div>';
    echo '<h1 class="h3 mt-2 mb-3">Topics</h1>';

    if ($flash['ok'] !== '') {
        echo '<div class="alert alert-success">' . e($flash['ok']) . '</div>';
    }

    if ($flash['err'] !== '') {
        echo '<div class="alert alert-danger">' . e($flash['err']) . '</div>';
    }

    echo '<div class="card mb-3"><div class="card-body">';
    echo '<div class="fw-semibold mb-2">Add Topic</div>';
    echo '<form method="post" action="/admin?action=topics">';
    echo '<input type="hidden" name="csrf" value="' . e($pageCsrf) . '">';
    echo '<input type="hidden" name="do" value="create">';

    echo '<div class="row g-2">';
    echo '<div class="col-12 col-md-4"><input class="form-control" name="slug" placeholder="slug (e.g. updates)" required></div>';
    echo '<div class="col-12 col-md-5"><input class="form-control" name="label" placeholder="Label (e.g. Updates)" required></div>';
    echo '<div class="col-12 col-md-3">';
    echo '<select class="form-select" name="is_public">';
    echo '<option value="1">Public</option>';
    echo '<option value="0">Members Only</option>';
    echo '</select>';
    echo '</div>';
    echo '</div>';

    echo '<button class="btn btn-sm mt-3" type="submit">Create</button>';
    echo '</form>';
    echo '</div></div>';

    echo '<div class="card"><div class="card-body">';
    echo '<div class="fw-semibold mb-2">Existing Topics</div>';

    if (!$rows) {
        echo '<div class="text-muted">No topics found.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm align-middle">';
        echo '<thead><tr><th>ID</th><th>Slug</th><th>Label</th><th>Visibility</th><th>Actions</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $id       = (int) ($r['id'] ?? 0);
            $slug     = (string) ($r['slug'] ?? '');
            $label    = (string) ($r['label'] ?? '');
            $isPublic = (int) ($r['is_public'] ?? 1);

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td><code>' . e($slug) . '</code></td>';
            echo '<td>' . e($label) . '</td>';
            echo '<td>' . ($isPublic === 1 ? 'Public' : 'Members Only') . '</td>';
            echo '<td style="min-width:260px;">';

            echo '<form class="d-inline" method="post" action="/admin?action=topics">';
            echo '<input type="hidden" name="csrf" value="' . e($pageCsrf) . '">';
            echo '<input type="hidden" name="do" value="delete">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<button class="btn btn-sm btn-danger" type="submit" onclick="return confirm(\'Delete this topic?\')">Delete</button>';
            echo '</form>';

            echo ' <button class="btn btn-sm" type="button" data-topic-edit="' . $id . '">Edit</button>';

            echo '</td>';
            echo '</tr>';

            echo '<tr data-topic-row="' . $id . '" style="display:none;">';
            echo '<td colspan="5">';
            echo '<form method="post" action="/admin?action=topics">';
            echo '<input type="hidden" name="csrf" value="' . e($pageCsrf) . '">';
            echo '<input type="hidden" name="do" value="update">';
            echo '<input type="hidden" name="id" value="' . $id . '">';

            echo '<div class="row g-2">';
            echo '<div class="col-12 col-md-4"><input class="form-control" name="slug" value="' . e($slug) . '" required></div>';
            echo '<div class="col-12 col-md-5"><input class="form-control" name="label" value="' . e($label) . '" required></div>';
            echo '<div class="col-12 col-md-3">';
            echo '<select class="form-select" name="is_public">';
            echo '<option value="1"' . ($isPublic === 1 ? ' selected' : '') . '>Public</option>';
            echo '<option value="0"' . ($isPublic === 0 ? ' selected' : '') . '>Members Only</option>';
            echo '</select>';
            echo '</div>';
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
    echo 'document.querySelectorAll("[data-topic-edit]").forEach(function(btn){';
    echo '  btn.addEventListener("click", function(){';
    echo '    var id = btn.getAttribute("data-topic-edit");';
    echo '    var row = document.querySelector("[data-topic-row=\'" + id + "\']");';
    echo '    if (!row) { return; }';
    echo '    row.style.display = (row.style.display === "none" ? "" : "none");';
    echo '  });';
    echo '});';
    echo '</script>';
})();

