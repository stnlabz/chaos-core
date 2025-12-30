<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin: Posts
 *
 * Route:
 *   /admin?action=posts
 *
 * Supports:
 * - List posts
 * - Create / Edit
 * - Publish / Unpublish
 * - Topic (single)
 * - Visibility (0=Public, 1=Members, 2=Unlisted)
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">DB is not available.</div></div>';
        return;
    }

    if (!isset($auth) || !$auth instanceof auth) {
        echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Auth is not available.</div></div>';
        return;
    }

    if (!$auth->check()) {
        header('Location: /login');
        exit;
    }

    $conn = $db->connect();
    if ($conn === false) {
        echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">DB connection failed.</div></div>';
        return;
    }

    $userId = $auth->id();

    /**
     * Topics loader (tolerant):
     * - Tries post_topics (id, label)
     * - Falls back to topics (id, name)
     * - If neither exists, uses General only
     *
     * @return array<int,string>
     */
    $load_topics = static function (mysqli $conn): array {
        $topics = [0 => 'General'];

        // Try post_topics
        $sql = "SELECT id, label FROM topics ORDER BY label ASC";
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            $topics = [];
            while ($r = $res->fetch_assoc()) {
                if (!is_array($r)) {
                    continue;
                }
                $id = (int)($r['id'] ?? 0);
                $lb = trim((string)($r['label'] ?? ''));
                if ($id > 0 && $lb !== '') {
                    $topics[$id] = $lb;
                }
            }
            $res->close();

            if (!empty($topics)) {
                return $topics;
            }

            return [0 => 'General'];
        }

        // Try topics
        $sql = "SELECT id, name FROM topics ORDER BY name ASC";
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            $topics = [];
            while ($r = $res->fetch_assoc()) {
                if (!is_array($r)) {
                    continue;
                }
                $id = (int)($r['id'] ?? 0);
                $lb = trim((string)($r['name'] ?? ''));
                if ($id > 0 && $lb !== '') {
                    $topics[$id] = $lb;
                }
            }
            $res->close();

            if (!empty($topics)) {
                return $topics;
            }
        }

        return [0 => 'General'];
    };

    $topics = $load_topics($conn);

    /**
     * Normalize visibility label/badge.
     *
     * @param int $v
     * @return string
     */
    $vis_badge = static function (int $v): string {
        return match ($v) {
            0 => '<span class="admin-badge admin-badge-ok">Public</span>',
            1 => '<span class="admin-badge admin-badge-warn">Members</span>',
            2 => '<span class="admin-badge admin-badge-danger">Unlisted</span>',
            default => '<span class="admin-badge">—</span>',
        };
    };

    /**
     * Status badge.
     *
     * @param int $s
     * @return string
     */
    $status_badge = static function (int $s): string {
        return ($s === 1)
            ? '<span class="admin-badge admin-badge-ok">Published</span>'
            : '<span class="admin-badge">Draft</span>';
    };

    $do = (string)($_GET['do'] ?? '');
    $id = (int)($_GET['id'] ?? 0);

    // -------------------------------------------------------------
    // Handle form submit (create/update)
    // -------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $postId     = (int)($_POST['id'] ?? 0);
        $title      = trim((string)($_POST['title'] ?? ''));
        $slug       = trim((string)($_POST['slug'] ?? ''));
        $excerpt    = trim((string)($_POST['excerpt'] ?? ''));
        $body       = (string)($_POST['body'] ?? '');
        $status     = (int)($_POST['status'] ?? 0);
        $visibility = (int)($_POST['visibility'] ?? 0);
        $topicId    = (int)($_POST['topic_id'] ?? 0);

        if ($title === '') {
            echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Title is required.</div></div>';
            return;
        }

        // Basic slug fallback
        if ($slug === '') {
            $slug = strtolower(trim(preg_replace('~[^a-z0-9\-]+~i', '-', $title) ?? '', '-'));
        } else {
            $slug = strtolower(trim(preg_replace('~[^a-z0-9\-]+~i', '-', $slug) ?? '', '-'));
        }

        if ($slug === '') {
            echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Slug is required.</div></div>';
            return;
        }

        // Keep within allowed set
        if (!in_array($visibility, [0, 1, 2], true)) {
            $visibility = 0;
        }
        $status = ($status === 1) ? 1 : 0;

        // Publish time handling
        $publishedAt = null;
        if ($status === 1) {
            $publishedAt = gmdate('Y-m-d H:i:s');
        }

        // Topic: if posted topic id not known, fallback to 0
        if ($topicId > 0 && !isset($topics[$topicId])) {
            $topicId = 0;
        }

        if ($postId > 0) {
            // Update
            $sql = "
                UPDATE posts
                SET
                    slug=?,
                    title=?,
                    excerpt=?,
                    body=?,
                    status=?,
                    visibility=?,
                    topic_id=?,
                    updated_at=NOW(),
                    published_at=IF(?, ?, published_at)
                WHERE id=?
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Failed to prepare update.</div></div>';
                return;
            }

            $setPublished = ($status === 1 && $publishedAt !== null) ? 1 : 0;
            $pubVal = $publishedAt ?? '';

            // types: s s s s i i i i s i
            $stmt->bind_param(
                'ssssiiissi',
                $slug,
                $title,
                $excerpt,
                $body,
                $status,
                $visibility,
                $topicId,
                $setPublished,
                $pubVal,
                $postId
            );

            $ok = $stmt->execute();
            $stmt->close();

            if (!$ok) {
                echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Update failed.</div></div>';
                return;
            }

            header('Location: /admin?action=posts');
            exit;
        }

        // Create
        $sql = "
            INSERT INTO posts
                (slug, title, body, excerpt, status, visibility, topic_id, author_id, published_at, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Failed to prepare insert.</div></div>';
            return;
        }

        $pubVal = ($status === 1 && $publishedAt !== null) ? $publishedAt : null;
        $authorId = ($userId !== null) ? (int)$userId : 0;

        // bind_param cannot bind NULL directly as null with strict typing easily; use empty string if null
        $pubStr = ($pubVal === null) ? '' : $pubVal;
        $stmt->bind_param(
            'ssssiiiis',
            $slug,
            $title,
            $body,
            $excerpt,
            $status,
            $visibility,
            $topicId,
            $authorId,
            $pubStr
        );

        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Insert failed.</div></div>';
            return;
        }

        header('Location: /admin?action=posts');
        exit;
    }

    // -------------------------------------------------------------
    // Edit/New view
    // -------------------------------------------------------------
    if ($do === 'new' || ($do === 'edit' && $id > 0)) {
        $post = [
            'id'         => 0,
            'title'      => '',
            'slug'       => '',
            'excerpt'    => '',
            'body'       => '',
            'status'     => 0,
            'visibility' => 0,
            'topic_id'   => 0,
        ];

        if ($do === 'edit' && $id > 0) {
            $stmt = $conn->prepare("SELECT * FROM posts WHERE id=? LIMIT 1");
            if ($stmt !== false) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if (is_array($row)) {
                    $post['id']         = (int)($row['id'] ?? 0);
                    $post['title']      = (string)($row['title'] ?? '');
                    $post['slug']       = (string)($row['slug'] ?? '');
                    $post['excerpt']    = (string)($row['excerpt'] ?? '');
                    $post['body']       = (string)($row['body'] ?? '');
                    $post['status']     = (int)($row['status'] ?? 0);
                    $post['visibility'] = (int)($row['visibility'] ?? 0);
                    $post['topic_id']   = (int)($row['topic_id'] ?? 0);
                }
            }
        }

        ?>
        <div class="admin-wrap">

            <div class="admin-toolbar">
                <h1><?= ($do === 'new') ? 'New Post' : 'Edit Post'; ?></h1>
                <a href="/admin?action=posts" class="btn btn-sm btn-outline">Back</a>
            </div>

            <form method="post" class="admin-card">
                <input type="hidden" name="id" value="<?= (int)$post['id']; ?>">

                <div class="admin-grid">
                    <div>
                        <label class="admin-label">Title</label>
                        <input
                            type="text"
                            name="title"
                            class="admin-input"
                            value="<?= e($post['title']); ?>"
                            required
                        >
                    </div>

                    <div>
                        <label class="admin-label">Slug</label>
                        <input
                            type="text"
                            name="slug"
                            class="admin-input"
                            value="<?= e($post['slug']); ?>"
                            placeholder="auto if blank"
                        >
                    </div>

                    <div>
                        <label class="admin-label">Topic</label>
                        <select name="topic_id" class="admin-input">
                            <option value="0" <?= ((int)$post['topic_id'] === 0) ? 'selected' : ''; ?>>General</option>
                            <?php foreach ($topics as $tid => $lbl): ?>
                                <?php if ((int)$tid === 0) continue; ?>
                                <option value="<?= (int)$tid; ?>" <?= ((int)$post['topic_id'] === (int)$tid) ? 'selected' : ''; ?>>
                                    <?= e($lbl); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="admin-label">Status</label>
                        <select name="status" class="admin-input">
                            <option value="0" <?= ((int)$post['status'] === 0) ? 'selected' : ''; ?>>Draft</option>
                            <option value="1" <?= ((int)$post['status'] === 1) ? 'selected' : ''; ?>>Published</option>
                        </select>
                    </div>

                    <div>
                        <label class="admin-label">Visibility</label>
                        <select name="visibility" class="admin-input">
                            <option value="0" <?= ((int)$post['visibility'] === 0) ? 'selected' : ''; ?>>Public</option>
                            <option value="1" <?= ((int)$post['visibility'] === 1) ? 'selected' : ''; ?>>Members</option>
                            <option value="2" <?= ((int)$post['visibility'] === 2) ? 'selected' : ''; ?>>Unlisted</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="admin-label">Excerpt</label>
                    <textarea name="excerpt" class="admin-textarea" rows="3"><?= e($post['excerpt']); ?></textarea>
                </div>

                <div class="mt-3">
                    <label class="admin-label">Body (HTML allowed)</label>
                    <textarea name="body" class="admin-textarea" rows="14"><?= e($post['body']); ?></textarea>
                    <div class="admin-help">Tip: You can use basic HTML like &lt;p&gt;, &lt;em&gt;, &lt;strong&gt; and it will render on the public side.</div>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a class="btn btn-sm btn-outline" href="/admin?action=posts">Cancel</a>
                </div>
            </form>
        </div>
<!--
        <style>
            .admin-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
            .admin-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
            .admin-toolbar h1 { margin:0; font-size: 1.25rem; }
            .admin-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fff; }
            .admin-grid { display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
            @media (max-width: 980px){ .admin-grid { grid-template-columns: 1fr 1fr; } }
            @media (max-width: 520px){ .admin-grid { grid-template-columns: 1fr; } }
            .admin-label { display:block; font-size: .85rem; color:#444; margin-bottom:6px; }
            .admin-input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:10px; }
            .admin-textarea { width:100%; padding:10px; border:1px solid #d1d5db; border-radius:10px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
            .admin-help { font-size:.8rem; color:#666; margin-top:6px; }
            .admin-actions { display:flex; gap:10px; margin-top:14px; }
            .btn { display:inline-block; text-decoration:none; border-radius:10px; padding:7px 10px; border:1px solid transparent; }
            .btn-sm { padding:6px 9px; font-size:.85rem; }
            .btn-primary { background:#111827; color:#fff; }
            .btn-outline { border-color:#d1d5db; color:#111827; background:#fff; }
            .mt-3 { margin-top: 12px; }
        </style>
        -->
        <?php
        return;
    }
    // -------------------------------------------------------------
    // List view
    // -------------------------------------------------------------
    $posts = [];
    $sql = "
        SELECT
            id,
            slug,
            title,
            excerpt,
            status,
            visibility,
            topic_id,
            updated_at
        FROM posts
        ORDER BY updated_at DESC, id DESC
        LIMIT 250
    ";

    $res = $conn->query($sql);
    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            if (is_array($r)) {
                $posts[] = $r;
            }
        }
        $res->close();
    }

    ?>
    <div class="admin-wrap">

        <div class="admin-toolbar">
            <h1>Posts</h1>
            <a href="/admin?action=posts&do=new" class="btn btn-primary btn-sm">New Post</a>
        </div>

        <?php if (empty($posts)): ?>
            <div class="admin-alert">No posts yet.</div>
        <?php else: ?>
            <div class="admin-card">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>Title</th>
                        <th>Topic</th>
                        <th>Visibility</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($posts as $p): ?>
                        <?php
                        $pid = (int)($p['id'] ?? 0);
                        $topicId = (int)($p['topic_id'] ?? 0);
                        $vis = (int)($p['visibility'] ?? 0);
                        $st  = (int)($p['status'] ?? 0);
                        ?>
                        <tr>
                            <td class="post-titlecell">
                                <strong><?= e((string)($p['title'] ?? '')); ?></strong>
                                <?php if (!empty($p['excerpt'])): ?>
                                    <div class="meta"><?= e((string)$p['excerpt']); ?></div>
                                <?php endif; ?>
                                <div class="slug">/posts/<?= e((string)($p['slug'] ?? '')); ?></div>
                            </td>

                            <td><?= e($topics[$topicId] ?? 'General'); ?></td>
                            <td><?= $vis_badge($vis); ?></td>
                            <td><?= $status_badge($st); ?></td>
                            <td class="small"><?= e((string)($p['updated_at'] ?? '')); ?></td>

                            <td class="right">
                                <a class="btn btn-sm btn-outline" href="/admin?action=posts&do=edit&id=<?= $pid; ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
<!--
    <style>
        .admin-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
        .admin-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
        .admin-toolbar h1 { margin:0; font-size: 1.25rem; }
        .admin-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 0; background: #fff; overflow:hidden; }
        .admin-table { width:100%; border-collapse:collapse; }
        .admin-table th, .admin-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; text-align:left; }
        .admin-table th { font-size:.85rem; color:#374151; background:#fafafa; }
        .post-titlecell .meta { font-size:.85rem; color:#666; margin-top:4px; }
        .post-titlecell .slug { font-size:.75rem; color:#9ca3af; margin-top:4px; }
        .small { font-size:.85rem; color:#6b7280; }
        .right { text-align:right; }
        .admin-badge { display:inline-block; padding: 4px 8px; border-radius: 999px; font-size:.78rem; border:1px solid #e5e7eb; background:#f9fafb; color:#111827; }
        .admin-badge-ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
        .admin-badge-warn { background:#fffbeb; border-color:#fcd34d; color:#92400e; }
        .admin-badge-danger { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
        .btn { display:inline-block; text-decoration:none; border-radius:10px; padding:7px 10px; border:1px solid transparent; }
        .btn-sm { padding:6px 9px; font-size:.85rem; }
        .btn-primary { background:#111827; color:#fff; }
        .btn-outline { border-color:#d1d5db; color:#111827; background:#fff; }
        .admin-alert { padding: 12px; border: 1px solid #e5e7eb; border-radius: 12px; background:#fff; }
        .admin-alert-danger { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    </style>
    -->
    <?php
})();

