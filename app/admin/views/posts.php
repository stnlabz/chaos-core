<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin: Posts
 * Route:
 *   /admin?action=posts
 *
 * Purpose:
 * - List/Create/Edit posts
 * - ADDED: Monetization fields (is_premium, price, tier_required)
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">DB is not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">DB connection failed.</div></div>';
        return;
    }

    $userId = null;
    if (isset($auth) && $auth instanceof auth && method_exists($auth, 'id')) {
        try {
            $userId = $auth->id();
        } catch (Throwable $e) {
            $userId = null;
        }
    }

    $e = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    /**
     * Load topics for dropdown
     */
    $load_topics = static function (mysqli $conn): array {
        $topics = [];
        $sql = "SELECT id, label AS name FROM topics ORDER BY label ASC";
        $res = $conn->query($sql);

        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
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
     */
    $status_badge = static function (int $s): string {
        return ($s === 1)
            ? '<span class="admin-badge admin-badge-ok">Published</span>'
            : '<span class="admin-badge">Draft</span>';
    };

    /**
     * Tier badge.
     */
    $tier_badge = static function (string $t): string {
        return match ($t) {
            'pro' => '<span class="admin-badge admin-badge-premium">Pro</span>',
            'premium' => '<span class="admin-badge admin-badge-premium">Premium</span>',
            'free' => '<span class="admin-badge admin-badge-ok">Free</span>',
            default => '<span class="admin-badge">—</span>',
        };
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
        
        // Monetization fields
        $isPremium     = (int)($_POST['is_premium'] ?? 0);
        $price         = trim((string)($_POST['price'] ?? ''));
        $tierRequired  = trim((string)($_POST['tier_required'] ?? 'free'));

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
        
        // Validate tier
        if (!in_array($tierRequired, ['free', 'premium', 'pro'], true)) {
            $tierRequired = 'free';
        }
        
        // Validate and convert price
        $isPremium = ($isPremium === 1) ? 1 : 0;
        $priceDecimal = null;
        if ($price !== '' && is_numeric($price)) {
            $priceDecimal = (float)$price;
            if ($priceDecimal < 0) {
                $priceDecimal = null;
            }
        }

        if ($postId > 0) {
            // UPDATE
            $sql = "UPDATE posts
                    SET slug=?, title=?, body=?, excerpt=?, status=?, visibility=?, topic_id=?,
                        is_premium=?, price=?, tier_required=?, updated_at=UTC_TIMESTAMP()
                    WHERE id=? LIMIT 1";

            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Update prepare failed.</div></div>';
                return;
            }

            $stmt->bind_param(
                'ssssiiiidsi',
                $slug,
                $title,
                $body,
                $excerpt,
                $status,
                $visibility,
                $topicId,
                $isPremium,
                $priceDecimal,
                $tierRequired,
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

        // INSERT
        $sql = "INSERT INTO posts
                (slug, title, body, excerpt, status, visibility, topic_id, author_id, 
                 is_premium, price, tier_required, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo '<div class="admin-wrap"><div class="admin-alert admin-alert-danger">Insert prepare failed.</div></div>';
            return;
        }

        $publishedAt = ($status === 1) ? date('Y-m-d H:i:s') : null;
        $pubVal = ($publishedAt !== null) ? $publishedAt : null;
        $authorId = ($userId !== null) ? (int)$userId : 0;

        // bind_param cannot bind NULL directly as null with strict typing easily; use empty string if null
        $pubStr = ($pubVal === null) ? '' : $pubVal;
        $stmt->bind_param(
            'ssssiiiidss',
            $slug,
            $title,
            $body,
            $excerpt,
            $status,
            $visibility,
            $topicId,
            $authorId,
            $isPremium,
            $priceDecimal,
            $tierRequired,
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
            'id'            => 0,
            'title'         => '',
            'slug'          => '',
            'excerpt'       => '',
            'body'          => '',
            'status'        => 0,
            'visibility'    => 0,
            'topic_id'      => 0,
            'is_premium'    => 0,
            'price'         => '',
            'tier_required' => 'free',
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
                    $post['id']            = (int)($row['id'] ?? 0);
                    $post['title']         = (string)($row['title'] ?? '');
                    $post['slug']          = (string)($row['slug'] ?? '');
                    $post['excerpt']       = (string)($row['excerpt'] ?? '');
                    $post['body']          = (string)($row['body'] ?? '');
                    $post['status']        = (int)($row['status'] ?? 0);
                    $post['visibility']    = (int)($row['visibility'] ?? 0);
                    $post['topic_id']      = (int)($row['topic_id'] ?? 0);
                    $post['is_premium']    = (int)($row['is_premium'] ?? 0);
                    $post['price']         = isset($row['price']) && $row['price'] !== null 
                                             ? (string)$row['price'] 
                                             : '';
                    $post['tier_required'] = (string)($row['tier_required'] ?? 'free');
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
                            value="<?= $e($post['title']); ?>"
                            required
                        >
                    </div>

                    <div>
                        <label class="admin-label">Slug</label>
                        <input
                            type="text"
                            name="slug"
                            class="admin-input"
                            value="<?= $e($post['slug']); ?>"
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
                                    <?= $e($lbl); ?>
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

                <!-- MONETIZATION SECTION -->
                <div class="admin-section mt-3">
                    <h3 class="admin-section-title">Monetization Settings</h3>
                    <div class="admin-grid">
                        <div>
                            <label class="admin-label">Premium Content</label>
                            <select name="is_premium" class="admin-input">
                                <option value="0" <?= ((int)$post['is_premium'] === 0) ? 'selected' : ''; ?>>No</option>
                                <option value="1" <?= ((int)$post['is_premium'] === 1) ? 'selected' : ''; ?>>Yes</option>
                            </select>
                            <div class="admin-help">Mark as premium content requiring payment</div>
                        </div>

                        <div>
                            <label class="admin-label">Price (USD)</label>
                            <input
                                type="number"
                                name="price"
                                class="admin-input"
                                value="<?= $e($post['price']); ?>"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                            >
                            <div class="admin-help">Leave empty for tier-based access only</div>
                        </div>

                        <div>
                            <label class="admin-label">Tier Required</label>
                            <select name="tier_required" class="admin-input">
                                <option value="free" <?= ($post['tier_required'] === 'free') ? 'selected' : ''; ?>>Free</option>
                                <option value="premium" <?= ($post['tier_required'] === 'premium') ? 'selected' : ''; ?>>Premium</option>
                                <option value="pro" <?= ($post['tier_required'] === 'pro') ? 'selected' : ''; ?>>Pro</option>
                            </select>
                            <div class="admin-help">Minimum subscription tier to access</div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="admin-label">Excerpt</label>
                    <textarea name="excerpt" class="admin-textarea" rows="3"><?= $e($post['excerpt']); ?></textarea>
                </div>

                <div class="mt-3">
                    <label class="admin-label">Body (HTML allowed)</label>
                    <textarea name="body" class="admin-textarea" rows="14"><?= $e($post['body']); ?></textarea>
                    <div class="admin-help">Tip: You can use basic HTML like &lt;p&gt;, &lt;em&gt;, &lt;strong&gt; and it will render on the public side.</div>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a class="btn btn-sm btn-outline" href="/admin?action=posts">Cancel</a>
                </div>
            </form>
        </div>
        <style>
            .admin-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
            .admin-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
            .admin-toolbar h1 { margin:0; font-size: 1.25rem; }
            .admin-card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fafafa; }
            .admin-section { border-top: 1px solid #e5e7eb; padding-top: 14px; }
            .admin-section-title { font-size: 1rem; font-weight: 600; margin-bottom: 12px; }
            .admin-grid { display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
            @media (max-width: 980px){ .admin-grid { grid-template-columns: 1fr 1fr; } }
            @media (max-width: 520px){ .admin-grid { grid-template-columns: 1fr; } }
            .admin-label { display:block; font-size: .85rem; color:#444; margin-bottom:6px; font-weight: 600; }
            .admin-input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:10px; }
            .admin-textarea { width:100%; padding:10px; border:1px solid #d1d5db; border-radius:10px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
            .admin-help { font-size:.75rem; color:#666; margin-top:4px; }
            .admin-actions { display:flex; gap:10px; margin-top:14px; }
            .btn { display:inline-block; text-decoration:none; border-radius:10px; padding:7px 10px; border:1px solid transparent; cursor: pointer; }
            .btn-sm { padding:6px 9px; font-size:.85rem; }
            .btn-primary { background:#111827; color:#fff; }
            .btn-outline { border-color:#d1d5db; color:#111827; background:#fff; }
            .mt-3 { margin-top: 12px; }
        </style>
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
            is_premium,
            price,
            tier_required,
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
                        <th>Tier</th>
                        <th>Price</th>
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
                        $topicLabel = $topics[$topicId] ?? 'General';
                        $title = (string)($p['title'] ?? '');
                        $slug = (string)($p['slug'] ?? '');
                        $vis = (int)($p['visibility'] ?? 0);
                        $status = (int)($p['status'] ?? 0);
                        $tier = (string)($p['tier_required'] ?? 'free');
                        $price = $p['price'] ?? null;
                        $isPremium = (int)($p['is_premium'] ?? 0);
                        $updated = (string)($p['updated_at'] ?? '');
                        
                        $priceDisplay = '—';
                        if ($price !== null && $isPremium === 1) {
                            $priceDisplay = '$' . number_format((float)$price, 2);
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= $e($title); ?></div>
                                <div class="text-muted small">/posts/<?= $e($slug); ?></div>
                            </td>
                            <td><?= $e($topicLabel); ?></td>
                            <td><?= $vis_badge($vis); ?></td>
                            <td><?= $tier_badge($tier); ?></td>
                            <td><?= $e($priceDisplay); ?></td>
                            <td><?= $status_badge($status); ?></td>
                            <td class="text-muted small"><?= $e($updated); ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline" href="/admin?action=posts&do=edit&id=<?= (int)$pid; ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .admin-wrap { max-width: 1400px; margin: 0 auto; padding: 16px; }
        .admin-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
        .admin-toolbar h1 { margin:0; font-size:1.5rem; }
        .admin-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px; overflow:auto; }
        .admin-alert { padding:12px; background:#f3f4f6; border:1px solid #d1d5db; border-radius:8px; }
        .admin-table { width:100%; border-collapse:collapse; }
        .admin-table th { text-align:left; padding:8px; border-bottom:2px solid #e5e7eb; font-weight:600; font-size:.85rem; }
        .admin-table td { padding:10px 8px; border-bottom:1px solid #f3f4f6; font-size:.9rem; }
        .admin-badge { display:inline-block; padding:3px 8px; border-radius:6px; font-size:.75rem; font-weight:600; }
        .admin-badge-ok { background:#dcfce7; color:#15803d; }
        .admin-badge-warn { background:#fef3c7; color:#a16207; }
        .admin-badge-danger { background:#fee2e2; color:#b91c1c; }
        .admin-badge-premium { background:#ede9fe; color:#6b21a8; }
        .fw-semibold { font-weight:600; }
        .text-muted { color:#6b7280; }
        .small { font-size:.85rem; }
        .btn { display:inline-block; text-decoration:none; border-radius:8px; padding:6px 12px; border:1px solid transparent; font-size:.85rem; cursor:pointer; }
        .btn-primary { background:#111827; color:#fff; }
        .btn-outline { border-color:#d1d5db; color:#111827; background:#fff; }
        .btn-sm { padding:4px 8px; font-size:.8rem; }
    </style>
    <?php
})();
