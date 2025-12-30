<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Posts
 *
 * Routes:
 *   /posts
 *   /posts/{slug}
 *
 * Replies:
 *   - post_replies.status: 1 = active, 0 = removed
 *   - post_replies.visibility: 0 public, 2 members (matches your stated convention)
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB is not available.</div></div>';
        return;
    }

    if (!isset($auth) || !$auth instanceof auth) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Auth is not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    // -------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------
    $e = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $vis_label = static function (int $v): string {
        // per your note: 0 public, 2 members-only (1 can be unlisted if you use it)
        if ($v === 2) return 'Members';
        if ($v === 1) return 'Unlisted';
        return 'Public';
    };

    $js_redirect = static function (string $to): void {
        $toSafe = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
        echo '<script>window.location.href="' . $toSafe . '";</script>';
    };

    $current_user = static function () use ($db, $auth): array {
        // returns ['id'=>int|null, 'username'=>string, 'role_id'=>int, 'role_label'=>string]
        $uid = null;

        // Prefer auth->id() if present
        if (method_exists($auth, 'id')) {
            try {
                $tmp = $auth->id();
                if (is_int($tmp) && $tmp > 0) {
                    $uid = $tmp;
                }
            } catch (Throwable $e) {
                $uid = null;
            }
        }

        if ($uid === null) {
            return [
                'id' => null,
                'username' => '',
                'role_id' => 0,
                'role_label' => '',
            ];
        }

        $row = $db->fetch(
            "SELECT u.id, u.username, u.role_id, r.label AS role_label
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id=" . (int)$uid . " LIMIT 1"
        );

        if (!is_array($row)) {
            return [
                'id' => $uid,
                'username' => '',
                'role_id' => 0,
                'role_label' => '',
            ];
        }

        $username = (string)($row['username'] ?? '');
        $roleId   = (int)($row['role_id'] ?? 0);
        $roleLbl  = (string)($row['role_label'] ?? '');

        return [
            'id' => (int)($row['id'] ?? $uid),
            'username' => $username,
            'role_id' => $roleId,
            'role_label' => $roleLbl,
        ];
    };

    $can_moderate = static function (array $u): bool {
        $rid = (int)($u['role_id'] ?? 0);
        return ($rid >= 3); // 3 moderator, 4 admin
    };

    $csrf_ok = static function (): bool {
        if (!function_exists('csrf_ok')) {
            // If core CSRF helpers aren't available yet, don't block.
            return true;
        }

        $token = (string)($_POST['csrf'] ?? '');
        return csrf_ok($token);
    };

    $csrf_field = static function () use ($e): string {
        if (!function_exists('csrf_token')) {
            return '';
        }
        return '<input type="hidden" name="csrf" value="' . $e((string)csrf_token()) . '">';
    };

    // -------------------------------------------------------------
    // Parse slug
    // -------------------------------------------------------------
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/posts', PHP_URL_PATH) ?: '/posts';
    $path = rtrim($path, '/');
    $slug = '';

    // /posts/{slug}
    if (strpos($path, '/posts/') === 0) {
        $slug = trim(substr($path, strlen('/posts/')));
        $slug = preg_replace('~[^a-z0-9\-_]~i', '', (string)$slug) ?: '';
    }

    $u = $current_user();
    $userId = $u['id'];
    $isLoggedIn = $auth->check();

    // -------------------------------------------------------------
    // Topics map (optional; safe if missing)
    // -------------------------------------------------------------
    $topics = [];
    $topicRows = $db->fetch_all("SELECT id, label FROM topics ORDER BY label ASC");
    if (is_array($topicRows)) {
        foreach ($topicRows as $tr) {
            $tid = (int)($tr['id'] ?? 0);
            if ($tid > 0) {
                $topics[$tid] = (string)($tr['label'] ?? 'General');
            }
        }
    }

    // -------------------------------------------------------------
    // Reply actions: add / delete
    // -------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $slug !== '') {
        $action = (string)($_POST['action'] ?? '');

        // CSRF (if available)
        if (!$csrf_ok()) {
            echo '<div class="container my-4"><div class="alert alert-danger">Bad CSRF.</div></div>';
            return;
        }

        // Load post by slug first (needed for both add/delete)
        $stmtP = $conn->prepare("SELECT id, status, visibility FROM posts WHERE slug=? LIMIT 1");
        if ($stmtP === false) {
            http_response_code(500);
            echo '<div class="container my-4"><div class="alert alert-danger">Query failed.</div></div>';
            return;
        }
        $stmtP->bind_param('s', $slug);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        $postRow = $resP ? $resP->fetch_assoc() : null;
        $stmtP->close();

        if (!is_array($postRow)) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-secondary">Post not found.</div></div>';
            return;
        }

        $postId = (int)($postRow['id'] ?? 0);
        $postVis = (int)($postRow['visibility'] ?? 0);
        $postStatus = (int)($postRow['status'] ?? 0);

        // Basic gating: drafts + unlisted/members rules
        if ($postStatus !== 1 && !$isLoggedIn) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-secondary">Post not found.</div></div>';
            return;
        }
        if ($postVis === 2 && !$isLoggedIn) {
            http_response_code(403);
            echo '<div class="container my-4"><div class="alert alert-warning">Members only.</div></div>';
            return;
        }
        if ($postVis === 1 && !$isLoggedIn) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-secondary">Post not found.</div></div>';
            return;
        }

        // Add reply
        if ($action === 'reply_add') {
            if (!$isLoggedIn || $userId === null) {
                echo '<div class="container my-4"><div class="alert alert-warning">Please log in to reply.</div></div>';
                return;
            }

            $body = trim((string)($_POST['reply_body'] ?? ''));
            if ($body === '') {
                $js_redirect('/posts/' . $slug);
                return;
            }

            $parentId = null;
            $pidRaw = (string)($_POST['parent_id'] ?? '');
            if ($pidRaw !== '' && ctype_digit($pidRaw)) {
                $parentId = (int)$pidRaw;
                if ($parentId <= 0) {
                    $parentId = null;
                }
            }

            // Replies inherit post visibility unless explicitly provided
            $replyVis = $postVis;

            $sql = "INSERT INTO post_replies (post_id, parent_id, author_id, body, status, visibility, created_at)
                    VALUES (?, ?, ?, ?, 1, ?, UTC_TIMESTAMP())";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                http_response_code(500);
                echo '<div class="container my-4"><div class="alert alert-danger">Reply insert failed.</div></div>';
                return;
            }

            if ($parentId === null) {
                // parent_id is nullable
                $null = null;
                $stmt->bind_param('iiisi', $postId, $null, $userId, $body, $replyVis);
            } else {
                $stmt->bind_param('iiisi', $postId, $parentId, $userId, $body, $replyVis);
            }

            $ok = $stmt->execute();
            $stmt->close();

            $js_redirect('/posts/' . $slug);
            return;
        }

        // Delete reply (moderator/admin only)
        if ($action === 'reply_delete') {
            if (!$isLoggedIn || !$can_moderate($u)) {
                http_response_code(403);
                echo '<div class="container my-4"><div class="alert alert-warning">Not allowed.</div></div>';
                return;
            }

            $rid = (string)($_POST['reply_id'] ?? '');
            if (!ctype_digit($rid)) {
                $js_redirect('/posts/' . $slug);
                return;
            }

            $replyId = (int)$rid;

            $stmt = $conn->prepare("UPDATE post_replies SET status=0, updated_at=UTC_TIMESTAMP() WHERE id=? AND post_id=? LIMIT 1");
            if ($stmt !== false) {
                $stmt->bind_param('ii', $replyId, $postId);
                $stmt->execute();
                $stmt->close();
            }

            $js_redirect('/posts/' . $slug);
            return;
        }
    }

    // -------------------------------------------------------------
    // Single post view
    // -------------------------------------------------------------
    if ($slug !== '') {
        $stmt = $conn->prepare("SELECT * FROM posts WHERE slug=? LIMIT 1");
        if ($stmt === false) {
            http_response_code(500);
            echo '<div class="container my-4"><div class="alert alert-danger">Query failed.</div></div>';
            return;
        }

        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $post = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($post)) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-secondary">Post not found.</div></div>';
            return;
        }

        $postId = (int)($post['id'] ?? 0);
        $visibility = (int)($post['visibility'] ?? 0);
        $status = (int)($post['status'] ?? 0);

        // Draft gating
        if ($status !== 1 && !$isLoggedIn) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-secondary">Post not found.</div></div>';
            return;
        }

        // Members-only gating
        if ($visibility === 2 && !$isLoggedIn) {
            http_response_code(403);
            echo '<div class="container my-4"><div class="alert alert-warning">Members only.</div></div>';
            return;
        }

        // Unlisted
        if ($visibility === 1 && !$isLoggedIn) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-secondary">Post not found.</div></div>';
            return;
        }

        $topicId = (int)($post['topic_id'] ?? 0);
        $topicName = $topics[$topicId] ?? 'General';

        $title = (string)($post['title'] ?? '');
        $excerpt = (string)($post['excerpt'] ?? '');
        $publishedAt = (string)($post['published_at'] ?? $post['created_at'] ?? '');

        // Load replies (only active; apply visibility)
        $replySql = "
            SELECT
                r.id,
                r.post_id,
                r.parent_id,
                r.author_id,
                r.body,
                r.status,
                r.visibility,
                r.created_at,
                u.username,
                u.role_id,
                rl.label AS role_label
            FROM post_replies r
            INNER JOIN users u ON u.id = r.author_id
            LEFT JOIN roles rl ON rl.id = u.role_id
            WHERE r.post_id = ?
              AND r.status = 1
            ORDER BY r.id ASC
        ";

        $replies = [];
        $stmtR = $conn->prepare($replySql);
        if ($stmtR !== false) {
            $stmtR->bind_param('i', $postId);
            $stmtR->execute();
            $resR = $stmtR->get_result();
            if ($resR instanceof mysqli_result) {
                while ($row = $resR->fetch_assoc()) {
                    if (is_array($row)) {
                        // Visibility gating for replies:
                        $rv = (int)($row['visibility'] ?? 0);
                        if ($rv === 2 && !$isLoggedIn) {
                            continue;
                        }
                        if ($rv === 1 && !$isLoggedIn) {
                            continue;
                        }
                        $replies[] = $row;
                    }
                }
                $resR->close();
            }
            $stmtR->close();
        }

        ?>
        <div class="container my-4 posts-view">
            <article class="post-card">
                <h2 class="post-title"><?= $e($title); ?></h2>

                <?php if ($excerpt !== ''): ?>
                    <p class="post-excerpt"><?= $e($excerpt); ?></p>
                <?php endif; ?>

                <div class="post-meta">
                    <?= $e($topicName); ?> · <?= $e($vis_label($visibility)); ?> · <?= $e($publishedAt); ?>
                </div>

                <div class="post-body">
                    <?= (string)($post['body'] ?? ''); ?>
                </div>
            </article>

            <section class="post-replies">
                <h3 class="h5 mt-4 mb-2">Replies</h3>

                <?php if (empty($replies)): ?>
                    <div class="small text-muted">No replies yet.</div>
                <?php else: ?>
                    <div class="reply-list">
                        <?php foreach ($replies as $r): ?>
                            <?php
                            $rId = (int)($r['id'] ?? 0);
                            $rUser = ucfirst((string)($r['username'] ?? ''));
                            $rRole = (string)($r['role_label'] ?? '');
                            $rCreated = (string)($r['created_at'] ?? '');
                            $rBody = (string)($r['body'] ?? '');

                            $roleTxt = $rRole !== '' ? ' ' . $rRole : '';
                            ?>
                            <div class="reply">
                                <div class="reply-head">
                                    <div class="reply-who">
                                        <strong><?= $e($rUser); ?></strong><?= $e($roleTxt); ?>
                                        <span class="reply-time"><?= $e($rCreated); ?></span>
                                    </div>

                                    <?php if ($isLoggedIn && $can_moderate($u)): ?>
                                        <form method="post" class="reply-actions" action="/posts/<?= $e($slug); ?>">
                                            <?= $csrf_field(); ?>
                                            <input type="hidden" name="action" value="reply_delete">
                                            <input type="hidden" name="reply_id" value="<?= (int)$rId; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <div class="reply-body">
                                    <?= nl2br($e($rBody)); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isLoggedIn && $userId !== null): ?>
                    <form method="post" class="post-reply-form mt-3" action="/posts/<?= $e($slug); ?>">
                        <?= $csrf_field(); ?>
                        <input type="hidden" name="action" value="reply_add">

                        <div class="mb-2">
                            <label for="reply_body" class="small fw-semibold">Leave a reply</label>
                            <textarea
                                id="reply_body"
                                name="reply_body"
                                rows="4"
                                class="form-control"
                                required
                            ></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm">Post Reply</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-secondary small mt-3">
                        Please <a href="/login">login</a> to leave a reply.
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <style>
            .posts-view .post-card { max-width: 920px; margin: 0 auto; }
            .post-title { margin: 0; }
            .post-excerpt { margin-top: 10px; opacity: .9; }
            .post-meta { margin-top: 10px; font-size: .9rem; opacity: .75; }
            .post-body { margin-top: 18px; }
            .post-body p { margin: 0 0 12px; }

            .post-replies { max-width: 920px; margin: 0 auto; }
            .reply-list { margin-top: 12px; display: flex; flex-direction: column; gap: 12px; }
            .reply { border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 12px 14px; }
            .reply-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
            .reply-who { font-size: .95rem; opacity: .95; }
            .reply-time { display: inline-block; margin-left: 10px; font-size: .8rem; opacity: .7; }
            .reply-body { margin-top: 8px; font-size: .95rem; opacity: .92; line-height: 1.4; }
            .reply-actions { margin: 0; }
        </style>
        <?php
        return;
    }

    // -------------------------------------------------------------
    // Index view (list)
    // -------------------------------------------------------------
    // Basic rule: show published posts, and hide members/unlisted unless logged in.
    $where = "WHERE status=1";
    if (!$isLoggedIn) {
        $where .= " AND visibility=0";
    } else {
        // logged-in can see all published regardless of visibility
    }

    $sql = "
        SELECT id, slug, title, excerpt, created_at, published_at, visibility, topic_id
        FROM posts
        $where
        ORDER BY COALESCE(published_at, created_at) DESC
        LIMIT 50
    ";

    $rows = $db->fetch_all($sql);
    if (!is_array($rows)) {
        $rows = [];
    }

    ?>
    <div class="container my-4 posts-index">
        <h1 class="h3 m-0">Posts</h1>
        <div class="small text-muted mt-1">Latest posts</div>

        <?php if (empty($rows)): ?>
            <div class="alert small mt-3">No posts yet.</div>
            <?php return; ?>
        <?php endif; ?>

        <div class="posts-list mt-3">
            <?php foreach ($rows as $p): ?>
                <?php
                $pslug = (string)($p['slug'] ?? '');
                $ptitle = (string)($p['title'] ?? '');
                $pex = (string)($p['excerpt'] ?? '');
                $pvis = (int)($p['visibility'] ?? 0);
                $ptid = (int)($p['topic_id'] ?? 0);
                $pTopic = $topics[$ptid] ?? 'General';
                $pDate = (string)($p['published_at'] ?? $p['created_at'] ?? '');
                ?>
                <a class="post-row" href="/posts/<?= $e($pslug); ?>">
                    <div class="post-row-title"><?= $e($ptitle); ?></div>
                    <?php if ($pex !== ''): ?>
                        <div class="post-row-ex"><?= $e($pex); ?></div>
                    <?php endif; ?>
                    <div class="post-row-meta">
                        <?= $e($pTopic); ?> · <?= $e($vis_label($pvis)); ?> · <?= $e($pDate); ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <style>
        .posts-list { display: grid; grid-template-columns: 1fr; gap: 14px; }
        .post-row {
            display:block;
            text-decoration:none;
            color:inherit;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            padding: 12px 14px;
        }
        .post-row:hover { border-color: rgba(255,255,255,0.22); }
        .post-row-title { font-weight: 700; }
        .post-row-ex { margin-top: 6px; opacity: .9; }
        .post-row-meta { margin-top: 8px; font-size:.85rem; opacity:.7; }
    </style>
    <?php
})(); 

