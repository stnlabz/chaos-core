<?php
declare(strict_types=1);

/**
 * Chaos CMS — Public Posts (Light Theme / Black Text)
 */

(function (): void {
    global $db, $auth;
    $conn = $db->connect();

    // --- 1. SELF-HEALING DB CHECK ---
    $checkCols = mysqli_query($conn, "SHOW COLUMNS FROM post_replies");
    $existing = [];
    while($c = mysqli_fetch_assoc($checkCols)) { $existing[] = $c['Field']; }
    if (!in_array('status', $existing)) {
        mysqli_query($conn, "ALTER TABLE post_replies ADD COLUMN status INT DEFAULT 1");
    }

    $isLoggedIn = (isset($auth) && $auth->check());
    $uId = $isLoggedIn ? (int)$auth->id() : 0;

    // --- 2. INTERNAL POST HANDLER ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chaos_reply_action'])) {
        if (!$isLoggedIn) { die("Auth required."); }
        $pId  = (int)$_POST['post_id'];
        $body = mysqli_real_escape_string($conn, (string)$_POST['body']);
        if ($pId > 0 && !empty($body)) {
            $sql = "INSERT INTO post_replies (post_id, author_id, body, status, created_at) 
                    VALUES ($pId, $uId, '$body', 1, UTC_TIMESTAMP())";
            mysqli_query($conn, $sql);
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // --- 3. LIGHT THEME CSS ---
    ?>
    <style>
        .chaos-posts-container { color: #000000 !important; background: transparent; }
        .chaos-posts-container h1, 
        .chaos-posts-container h2, 
        .chaos-posts-container h3 { color: #000000 !important; font-weight: 800; margin-bottom: 1rem; }
        
        .chaos-post-content { color: #222222 !important; line-height: 1.7; font-size: 1.15rem; }
        .chaos-post-content p { color: #222222 !important; margin-bottom: 1.5rem; }
        
        .chaos-feed-item { 
            border-bottom: 1px solid #eeeeee; 
            padding: 2rem 0; 
            margin-bottom: 1rem;
        }
        .chaos-feed-item a { color: #000000; text-decoration: none; }
        .chaos-feed-item a:hover { color: #0056b3; }
        
        .chaos-reply { 
            background: #f9f9f9; 
            border: 1px solid #eaeaea; 
            padding: 1.25rem; 
            margin-bottom: 1rem; 
            border-radius: 4px;
            color: #333 !important;
        }
        .chaos-reply strong { color: #000; }

        .chaos-input {
            width: 100%;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 4px;
            color: #000 !important;
            background: #fff !important;
        }
        .text-muted { color: #666666 !important; font-size: 0.9rem; }
    </style>
    <?php

    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $segments = explode('/', trim($uri, '/'));
    $slug = (isset($segments[0]) && $segments[0] === 'posts' && isset($segments[1])) ? $segments[1] : '';

    // --- 4. SINGLE POST VIEW ---
    if ($slug !== '') {
        $escSlug = mysqli_real_escape_string($conn, $slug);
        $res = mysqli_query($conn, "SELECT * FROM posts WHERE slug='$escSlug' AND (status=1 OR status='published') LIMIT 1");
        $post = mysqli_fetch_assoc($res);

        if (!$post) { echo "Post not found."; return; }
        $postId = (int)$post['id'];
        ?>
        <div class="container my-5 chaos-posts-container">
            <h1 style="font-size: 3rem;"><?= htmlspecialchars($post['title']) ?></h1>
            <p class="text-muted">Published on <?= $post['created_at'] ?></p>
            <hr>
            <div class="chaos-post-content mt-4">
                <?= nl2br($post['body']) ?>
            </div>

            <div class="mt-5 pt-4">
                <h3>Responses</h3>
                <?php
                $rRes = mysqli_query($conn, "SELECT r.*, u.username FROM post_replies r INNER JOIN users u ON u.id = r.author_id WHERE r.post_id = $postId AND r.status = 1 ORDER BY r.id ASC");
                while($r = mysqli_fetch_assoc($rRes)): ?>
                    <div class="chaos-reply">
                        <strong><?= htmlspecialchars($r['username']) ?></strong>
                        <div class="mt-1"><?= nl2br(htmlspecialchars($r['body'])) ?></div>
                    </div>
                <?php endwhile; ?>

                <?php if($isLoggedIn): ?>
                    <form action="" method="POST" class="mt-4">
                        <input type="hidden" name="chaos_reply_action" value="1">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <textarea name="body" class="chaos-input" rows="4" placeholder="Write a response..."></textarea>
                        <button type="submit" class="btn btn-dark mt-2">Post Response</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    } 
    // --- 5. LIST VIEW ---
    else {
        $res = mysqli_query($conn, "SELECT * FROM posts WHERE (status=1 OR status='published') ORDER BY id DESC LIMIT 50");
        ?>
        <div class="container my-5 chaos-posts-container">
            <h1 class="border-bottom pb-3">Latest Posts</h1>
            <?php while($p = mysqli_fetch_assoc($res)): ?>
                <div class="chaos-feed-item">
                    <h2><a href="/posts/<?= htmlspecialchars($p['slug']) ?>"><?= htmlspecialchars($p['title']) ?></a></h2>
                    <p class="text-muted"><?= htmlspecialchars($p['excerpt'] ?? '') ?></p>
                    <a href="/posts/<?= htmlspecialchars($p['slug']) ?>" class="btn btn-sm btn-outline-dark">Read More</a>
                </div>
            <?php endwhile; ?>
        </div>
        <?php
    }
})();
