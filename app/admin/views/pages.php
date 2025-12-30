<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin: Pages
 * Route: /admin?action=pages
 *
 * Requires table:
 *  - pages (id, slug, title, format, body, status, visibility, created_at, updated_at)
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

    if (!function_exists('e')) {
        function e(string $v): string
        {
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }
    }

    $csrf_token = static function (): string {
        if (function_exists('csrf_token')) {
            return (string) csrf_token();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['_csrf'];
    };

    $csrf_ok = static function (string $token) use ($csrf_token): bool {
        if (function_exists('csrf_ok')) {
            return (bool) csrf_ok($token);
        }

        return hash_equals($csrf_token(), $token);
    };

    $flash = static function (string $type, string $msg): void {
        if (function_exists('flash')) {
            flash($type, $msg);
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
            $_SESSION['_flash'] = [];
        }

        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
    };

    $q = trim((string)($_GET['q'] ?? ''));
    $filter_status = (string)($_GET['status'] ?? '');
    $filter_vis = (string)($_GET['vis'] ?? '');

    $view_mode = 'list';
    $edit_id = 0;

    if (isset($_GET['new']) && (string)($_GET['new']) === '1') {
        $view_mode = 'edit';
        $edit_id = 0;
    } elseif (isset($_GET['edit'])) {
        $view_mode = 'edit';
        $edit_id = (int)($_GET['edit'] ?? 0);
        if ($edit_id < 0) {
            $edit_id = 0;
        }
    }

    $vis_label = static function (int $v): string {
        return match ($v) {
            0 => 'Public',
            1 => 'Members',
            2 => 'Unlisted',
            3 => 'Private',
            default => 'Public',
        };
    };

    $format_label = static function (string $f): string {
        $f = strtolower(trim($f));
        return match ($f) {
            'html' => 'HTML',
            'json' => 'JSON',
            default => 'Markdown',
        };
    };

    $safe_format = static function (string $f): string {
        $f = strtolower(trim($f));
        return in_array($f, ['md', 'html', 'json'], true) ? $f : 'md';
    };

    // ---------------------------------------------------------
    // POST handlers
    // ---------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string)($_POST['csrf'] ?? '');
        if ($token === '' || !$csrf_ok($token)) {
            $flash('err', 'Security token invalid. Please try again.');
            header('Location: /admin?action=pages');
            exit;
        }

        $op = (string)($_POST['op'] ?? '');

        if ($op === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $slug = trim((string)($_POST['slug'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $format = $safe_format((string)($_POST['format'] ?? 'md'));
            $body = (string)($_POST['body'] ?? '');
            $status = (int)($_POST['status'] ?? 0);
            $visibility = (int)($_POST['visibility'] ?? 0);

            $status = $status === 1 ? 1 : 0;
            $visibility = in_array($visibility, [0, 1, 2, 3], true) ? $visibility : 0;

            if ($slug === '') {
                $flash('err', 'Slug is required.');
                header('Location: /admin?action=pages' . ($id > 0 ? '&edit=' . $id : '&new=1'));
                exit;
            }

            if ($title === '') {
                $title = $slug;
            }

            if ($id > 0) {
                $sql = "UPDATE pages SET slug=?, title=?, format=?, body=?, status=?, visibility=? WHERE id=? LIMIT 1";
                $stmt = $conn->prepare($sql);

                if ($stmt === false) {
                    $flash('err', 'Save failed (prepare).');
                    header('Location: /admin?action=pages&edit=' . $id);
                    exit;
                }

                $stmt->bind_param('ssssiii', $slug, $title, $format, $body, $status, $visibility, $id);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    $flash('ok', 'Page updated.');
                    header('Location: /admin?action=pages&edit=' . $id);
                    exit;
                }

                $flash('err', 'Update failed.');
                header('Location: /admin?action=pages&edit=' . $id);
                exit;
            }

            $sql = "INSERT INTO pages (slug, title, format, body, status, visibility) VALUES (?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);

            if ($stmt === false) {
                $flash('err', 'Create failed (prepare).');
                header('Location: /admin?action=pages&new=1');
                exit;
            }

            $stmt->bind_param('ssssii', $slug, $title, $format, $body, $status, $visibility);
            $ok = $stmt->execute();
            $new_id = $ok ? (int)$stmt->insert_id : 0;
            $stmt->close();

            if ($ok && $new_id > 0) {
                $flash('ok', 'Page created.');
                header('Location: /admin?action=pages&edit=' . $new_id);
                exit;
            }

            $flash('err', 'Create failed.');
            header('Location: /admin?action=pages&new=1');
            exit;
        }

        if ($op === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare("DELETE FROM pages WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                    $flash('ok', 'Page deleted.');
                } else {
                    $flash('err', 'Delete failed.');
                }
            }
            header('Location: /admin?action=pages');
            exit;
        }

        if ($op === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            $to = $to === 1 ? 1 : 0;

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE pages SET status=? WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $to, $id);
                    $stmt->execute();
                    $stmt->close();
                    $flash('ok', $to === 1 ? 'Published.' : 'Unpublished.');
                } else {
                    $flash('err', 'Status change failed.');
                }
            }

            $back = (string)($_POST['back'] ?? '/admin?action=pages');
            header('Location: ' . $back);
            exit;
        }
    }

    // ---------------------------------------------------------
    // Edit load
    // ---------------------------------------------------------
    $page = [
        'id' => 0,
        'slug' => '',
        'title' => '',
        'format' => 'md',
        'body' => '',
        'status' => 0,
        'visibility' => 0,
        'created_at' => '',
        'updated_at' => '',
    ];

    if ($view_mode === 'edit' && $edit_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM pages WHERE id=? LIMIT 1");
        if ($stmt !== false) {
            $stmt->bind_param('i', $edit_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (is_array($row)) {
                $page = array_merge($page, $row);
                $page['id'] = (int)($row['id'] ?? 0);
                $page['status'] = (int)($row['status'] ?? 0);
                $page['visibility'] = (int)($row['visibility'] ?? 0);
            } else {
                $view_mode = 'list';
            }
        } else {
            $view_mode = 'list';
        }
    }

    // ---------------------------------------------------------
    // List query
    // ---------------------------------------------------------
    $rows = [];

    if ($view_mode === 'list') {
        $where = [];
        $types = '';
        $bind = [];

        if ($q !== '') {
            $where[] = "(slug LIKE CONCAT('%', ?, '%') OR title LIKE CONCAT('%', ?, '%'))";
            $types .= 'ss';
            $bind[] = $q;
            $bind[] = $q;
        }

        if ($filter_status !== '' && ($filter_status === '0' || $filter_status === '1')) {
            $where[] = "status=?";
            $types .= 'i';
            $bind[] = (int)$filter_status;
        }

        if ($filter_vis !== '' && preg_match('~^[0-3]$~', $filter_vis) === 1) {
            $where[] = "visibility=?";
            $types .= 'i';
            $bind[] = (int)$filter_vis;
        }

        $sql = "SELECT id, slug, title, format, status, visibility, created_at, updated_at FROM pages";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY updated_at DESC, id DESC LIMIT 500";

        $stmt = $conn->prepare($sql);
        if ($stmt !== false) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$bind);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    if (is_array($r)) {
                        $rows[] = $r;
                    }
                }
                $res->close();
            }
            $stmt->close();
        }
    }

    $csrf_field = e($csrf_token());
    $back_qs = '/admin?action=pages'
        . ($q !== '' ? '&q=' . rawurlencode($q) : '')
        . ($filter_status !== '' ? '&status=' . rawurlencode($filter_status) : '')
        . ($filter_vis !== '' ? '&vis=' . rawurlencode($filter_vis) : '');

    ?>
    <div class="container my-3 admin-pages">
        <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
            <div>
                <h2 class="h5 m-0">Pages</h2>
                <div class="small text-muted mt-1">
                    Data-driven pages at <code>/pages/{slug}</code>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-sm btn-primary" href="/admin?action=pages&new=1">New Page</a>
                <?php if ($view_mode === 'edit'): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/admin?action=pages">Back to Pages</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($view_mode === 'list'): ?>
            <form method="get" class="admin-card mt-3">
                <input type="hidden" name="action" value="pages">

                <div class="admin-grid-4">
                    <div>
                        <label class="small text-muted">Search</label>
                        <input class="form-control form-control-sm" name="q" value="<?= e($q); ?>" placeholder="slug or title">
                    </div>

                    <div>
                        <label class="small text-muted">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="" <?= $filter_status === '' ? 'selected' : ''; ?>>All</option>
                            <option value="1" <?= $filter_status === '1' ? 'selected' : ''; ?>>Published</option>
                            <option value="0" <?= $filter_status === '0' ? 'selected' : ''; ?>>Draft</option>
                        </select>
                    </div>

                    <div>
                        <label class="small text-muted">Visibility</label>
                        <select class="form-select form-select-sm" name="vis">
                            <option value="" <?= $filter_vis === '' ? 'selected' : ''; ?>>All</option>
                            <option value="0" <?= $filter_vis === '0' ? 'selected' : ''; ?>>Public</option>
                            <option value="1" <?= $filter_vis === '1' ? 'selected' : ''; ?>>Members</option>
                            <option value="2" <?= $filter_vis === '2' ? 'selected' : ''; ?>>Unlisted</option>
                            <option value="3" <?= $filter_vis === '3' ? 'selected' : ''; ?>>Private</option>
                        </select>
                    </div>

                    <div class="d-flex align-items-end gap-2">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
                        <a class="btn btn-sm btn-outline-secondary" href="/admin?action=pages">Reset</a>
                    </div>
                </div>
            </form>

            <div class="admin-card mt-3">
                <?php if (empty($rows)): ?>
                    <div class="small text-muted">No pages found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:44%">Page</th>
                                    <th style="width:14%">Format</th>
                                    <th style="width:14%">Status</th>
                                    <th style="width:14%">Visibility</th>
                                    <th style="width:14%" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $id = (int)($r['id'] ?? 0);
                                    $slug = (string)($r['slug'] ?? '');
                                    $title = (string)($r['title'] ?? '');
                                    $format = (string)($r['format'] ?? 'md');
                                    $status = (int)($r['status'] ?? 0);
                                    $vis = (int)($r['visibility'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= e($title !== '' ? $title : $slug); ?></div>
                                            <div class="small text-muted">
                                                <code>/pages/<?= e($slug); ?></code>
                                            </div>
                                        </td>
                                        <td><?= e($format_label($format)); ?></td>
                                        <td>
                                            <?php if ($status === 1): ?>
                                                <span class="badge text-bg-success">Published</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Draft</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-light"><?= e($vis_label($vis)); ?></span>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="/admin?action=pages&edit=<?= (string)$id; ?>">Edit</a>

                                            <a class="btn btn-sm btn-outline-secondary" href="/pages/<?= e($slug); ?>" target="_blank" rel="noopener">View</a>

                                            <form method="post" action="/admin?action=pages" class="d-inline">
                                                <input type="hidden" name="csrf" value="<?= $csrf_field; ?>">
                                                <input type="hidden" name="op" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= (string)$id; ?>">
                                                <input type="hidden" name="to" value="<?= $status === 1 ? '0' : '1'; ?>">
                                                <input type="hidden" name="back" value="<?= e($back_qs); ?>">
                                                <?php if ($status === 1): ?>
                                                    <button class="btn btn-sm btn-outline-warning" type="submit">Unpublish</button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-success" type="submit">Publish</button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <?php
            $id = (int)($page['id'] ?? 0);
            $slug = (string)($page['slug'] ?? '');
            $title = (string)($page['title'] ?? '');
            $format = $safe_format((string)($page['format'] ?? 'md'));
            $body = (string)($page['body'] ?? '');
            $status = (int)($page['status'] ?? 0);
            $vis = (int)($page['visibility'] ?? 0);
            ?>

            <form method="post" action="/admin?action=pages" class="admin-card mt-3">
                <input type="hidden" name="csrf" value="<?= $csrf_field; ?>">
                <input type="hidden" name="op" value="save">
                <input type="hidden" name="id" value="<?= (string)$id; ?>">

                <div class="admin-grid-2">
                    <div>
                        <label class="small text-muted" for="page_slug">Slug</label>
                        <input id="page_slug" class="form-control form-control-sm" name="slug" value="<?= e($slug); ?>" placeholder="example: about" required>
                        <div class="small text-muted mt-1">Route: <code>/pages/<?= e($slug !== '' ? $slug : '{slug}'); ?></code></div>
                    </div>

                    <div>
                        <label class="small text-muted" for="page_title">Title</label>
                        <input id="page_title" class="form-control form-control-sm" name="title" value="<?= e($title); ?>" placeholder="Admin label for this page">
                    </div>
                </div>

                <div class="admin-grid-3 mt-3">
                    <div>
                        <label class="small text-muted" for="page_format">Format</label>
                        <select id="page_format" class="form-select form-select-sm" name="format">
                            <option value="md" <?= $format === 'md' ? 'selected' : ''; ?>>Markdown</option>
                            <option value="html" <?= $format === 'html' ? 'selected' : ''; ?>>HTML</option>
                            <option value="json" <?= $format === 'json' ? 'selected' : ''; ?>>JSON</option>
                        </select>
                    </div>

                    <div>
                        <label class="small text-muted" for="page_status">Status</label>
                        <select id="page_status" class="form-select form-select-sm" name="status">
                            <option value="0" <?= $status === 0 ? 'selected' : ''; ?>>Draft</option>
                            <option value="1" <?= $status === 1 ? 'selected' : ''; ?>>Published</option>
                        </select>
                    </div>

                    <div>
                        <label class="small text-muted" for="page_visibility">Visibility</label>
                        <select id="page_visibility" class="form-select form-select-sm" name="visibility">
                            <option value="0" <?= $vis === 0 ? 'selected' : ''; ?>>Public</option>
                            <option value="1" <?= $vis === 1 ? 'selected' : ''; ?>>Members</option>
                            <option value="2" <?= $vis === 2 ? 'selected' : ''; ?>>Unlisted</option>
                            <option value="3" <?= $vis === 3 ? 'selected' : ''; ?>>Private</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="small text-muted" for="page_body">Body</label>
                    <textarea id="page_body" class="form-control" name="body" rows="18" spellcheck="false"><?= e($body); ?></textarea>
                </div>

                <div class="d-flex gap-2 flex-wrap mt-3">
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>

                    <?php if ($id > 0): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="/pages/<?= e($slug); ?>" target="_blank" rel="noopener">View</a>
                    <?php endif; ?>

                    <a class="btn btn-sm btn-outline-secondary ms-auto" href="/admin?action=pages">Back</a>
                </div>
            </form>

            <?php if ($id > 0): ?>
                <div class="admin-card mt-3">
                    <div class="d-flex gap-2 flex-wrap">
                        <form method="post" action="/admin?action=pages" class="d-inline">
                            <input type="hidden" name="csrf" value="<?= $csrf_field; ?>">
                            <input type="hidden" name="op" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (string)$id; ?>">
                            <input type="hidden" name="to" value="<?= $status === 1 ? '0' : '1'; ?>">
                            <input type="hidden" name="back" value="<?= e('/admin?action=pages&edit=' . (string)$id); ?>">
                            <?php if ($status === 1): ?>
                                <button class="btn btn-sm btn-outline-warning" type="submit">Unpublish</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-success" type="submit">Publish</button>
                            <?php endif; ?>
                        </form>

                        <form method="post" action="/admin?action=pages" class="d-inline" onsubmit="return confirm('Delete this page?');">
                            <input type="hidden" name="csrf" value="<?= $csrf_field; ?>">
                            <input type="hidden" name="op" value="delete">
                            <input type="hidden" name="id" value="<?= (string)$id; ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
})();

