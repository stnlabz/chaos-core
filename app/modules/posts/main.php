<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Posts Module
 *
 * Routes:
 *   /posts        -> List view
 *   /posts/{slug} -> Single post view
 *
 * Added: Premium/tier monetization support
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB is not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $isLoggedIn = false;
    $userId = null;

    if (isset($auth) && $auth instanceof auth) {
        $isLoggedIn = $auth->check();
        if ($isLoggedIn && method_exists($auth, 'id')) {
            try {
                $userId = $auth->id();
            } catch (Throwable $e) {
                $userId = null;
            }
        }
    }

    /**
     * Get user context
     */
    $current_user = static function () use ($isLoggedIn, $userId, $db): array {
        if (!$isLoggedIn || $userId === null) {
            return ['id' => 0, 'username' => '', 'role_id' => 0, 'role_label' => ''];
        }

        $row = $db->fetch(
            "SELECT u.id, u.username, u.role_id, r.label AS role_label
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id=" . (int)$userId . " LIMIT 1"
        );

        if (!is_array($row)) {
            return [
                'id' => $userId,
                'username' => '',
                'role_id' => 0,
                'role_label' => '',
            ];
        }

        $username = (string)($row['username'] ?? '');
        $roleId   = (int)($row['role_id'] ?? 0);
        $roleLbl  = (string)($row['role_label'] ?? '');

        return [
            'id' => (int)($row['id'] ?? $userId),
            'username' => $username,
            'role_id' => $roleId,
            'role_label' => $roleLbl,
        ];
    };

    $can_moderate = static function (array $u): bool {
        $rid = (int) ($u['role_id'] ?? 0);
        return ($rid === 3 || $rid === 4);
    };

    $is_admin = static function (array $u): bool {
        return ((int) ($u['role_id'] ?? 0) === 4);
    };

    /**
     * Check if user has purchased this specific post
     */
    $hasPurchased = static function (int $postId, ?int $userId) use ($conn): bool {
        if ($userId === null) {
            return false;
        }
        
        $stmt = $conn->prepare("
            SELECT id FROM content_purchases 
            WHERE user_id=? AND content_type='post' AND content_id=? AND status='completed'
            LIMIT 1
        ");
        if ($stmt === false) {
            return false;
        }
        
        $stmt->bind_param('ii', $userId, $postId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = ($result && $result->num_rows > 0);
        $stmt->close();
        
        return $exists;
    };

    /**
     * Get user's current subscription tier
     */
    $getUserTier = static function (?int $userId) use ($conn): string {
        if ($userId === null) {
            return 'free';
        }
        
        $stmt = $conn->prepare("
            SELECT st.slug 
            FROM user_subscriptions us
            INNER JOIN subscription_tiers st ON st.id = us.tier_id
            WHERE us.user_id=? AND us.status='active' 
            AND (us.expires_at IS NULL OR us.expires_at > NOW())
            ORDER BY 
                CASE st.slug 
                    WHEN 'pro' THEN 3 
                    WHEN 'premium' THEN 2 
                    ELSE 1 
                END DESC
            LIMIT 1
        ");
        
        if ($stmt === false) {
            return 'free';
        }
        
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $tier = (string)($row['slug'] ?? 'free');
            $stmt->close();
            return $tier;
        }
        
        $stmt->close();
        return 'free';
    };

    /**
     * Check if user's tier meets requirement
     */
    $tierMeetsRequirement = static function (string $userTier, string $requiredTier): bool {
        $levels = ['free' => 0, 'premium' => 1, 'pro' => 2];
        $userLevel = $levels[$userTier] ?? 0;
        $requiredLevel = $levels[$requiredTier] ?? 0;
        return $userLevel >= $requiredLevel;
    };

    $userTier = $getUserTier($userId);

    $e = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $vis_label = static function (int $v): string {
        if ($v === 2) return 'Members';
        if ($v === 1) return 'Unlisted';
        return 'Public';
    };

    $js_redirect = static function (string $url): void {
        echo '<script>window.location.href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
    };

    $csrf_ok = static function (): bool {
        if (!function_exists('csrf_ok')) {
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

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/posts', PHP_URL_PATH) ?: '/posts';
    $path = rtrim($path, '/');
    $slug = '';

    if (strpos($path, '/posts/') === 0) {
        $slug = trim(substr($path, strlen('/posts/')));
        $slug = preg_replace('~[^a-z0-9\-_]~i', '', (string)$slug) ?: '';
    }

    $u = $current_user();
    $userId = $u['id'];
    $isLoggedIn = $auth->check();

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

    // Single post view
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
        $isPremium = (int)($post['is_premium'] ?? 0);
        $price = $post['price'] ?? null;
        $tierReq = (string)($post['tier_required'] ?? 'free');

        // Draft/hidden gating (status=0)
        if ($status !== 1 && (!$isLoggedIn || (int) ($u['role_id'] ?? 0) < 2)) {
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

        // MONETIZATION ACCESS CHECK
        $hasAccess = false;
        $accessReason = '';
        
        // Admins always have access
        if ($is_admin($u)) {
            $hasAccess = true;
        }
        // Check premium status
        elseif ($isPremium === 1) {
            if ($hasPurchased($postId, $userId)) {
                $hasAccess = true;
            } elseif ($tierMeetsRequirement($userTier, $tierReq)) {
                $hasAccess = true;
            }
        }
        // Check tier requirement even if not premium
        elseif ($tierReq !== 'free') {
            if ($tierMeetsRequirement($userTier, $tierReq)) {
                $hasAccess = true;
            }
        }
        // Free content
        else {
            $hasAccess = true;
        }

        // Build access meta
        if ($isPremium === 1 && $price !== null) {
            $accessReason = 'Premium - $' . number_format((float)$price, 2);
        } elseif ($tierReq !== 'free') {
            $accessReason = ucfirst($tierReq) . ' Required';
        }

        $topicId = (int)($post['topic_id'] ?? 0);
        $topicName = $topics[$topicId] ?? 'General';

        $title = (string)($post['title'] ?? '');
        $excerpt = (string)($post['excerpt'] ?? '');
        $publishedAt = (string)($post['published_at'] ?? $post['created_at'] ?? '');

        // Load replies (admins can see hidden + restore/purge)
        $replyStatusSql = $is_admin($u) ? 'AND r.status IN (0,1)' : 'AND r.status = 1';

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
              {$replyStatusSql}
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
                    <?= $e($topicName); ?> · <?= $e($vis_label($visibility)); ?>
                    <?php if ($accessReason !== ''): ?>
                        · <span class="post-premium-badge"><?= $e($accessReason); ?></span>
                    <?php endif; ?>
                    · <?= $e($publishedAt); ?>
                </div>

                <?php if ($hasAccess): ?>
                    <div class="post-body">
                        <?= (string)($post['body'] ?? ''); ?>
                    </div>
                <?php else: ?>
                    <div class="post-locked">
                        <div class="post-locked-icon">🔒</div>
                        <div class="post-locked-title">Premium Content</div>
                        <div class="post-locked-desc">
                            <?php if ($price !== null): ?>
                                Purchase for $<?= number_format((float)$price, 2); ?> or upgrade to <?= $e(ucfirst($tierReq)); ?> tier
                            <?php else: ?>
                                <?= $e(ucfirst($tierReq)); ?> subscription required
                            <?php endif; ?>
                        </div>
                        <?php if ($isLoggedIn): ?>
                            <button 
                                type="button"
                                class="post-locked-btn" 
                                data-purchase-post-id="<?= $postId; ?>" 
                                data-purchase-price="<?= $price !== null ? number_format((float)$price, 2) : '0.00'; ?>" 
                                data-purchase-tier="<?= $e(ucfirst($tierReq)); ?>" 
                                data-purchase-title="<?= $e($title); ?>"
                            >
                                View Purchase Options
                            </button>
                        <?php else: ?>
                            <a href="/login" class="post-locked-btn">Login to View</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="share">
                <?php
                    if (function_exists('share_buttons')) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
                        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');

                        $absUrl = $scheme . '://' . $host . $uri;
                        share_buttons($absUrl, $title);
                    }
                ?>
                </div>
            </article>

            <?php if ($hasAccess): ?>
                <section class="post-replies">
                    <?php if (empty($replies)): ?>
                        <div class="alert small">No replies yet.</div>
                    <?php else: ?>
                        <div class="reply-list">
                            <?php foreach ($replies as $r): ?>
                                <?php
                                $rid = (int)($r['id'] ?? 0);
                                $rauth = (string)($r['username'] ?? '');
                                $rbody = (string)($r['body'] ?? '');
                                $rtime = (string)($r['created_at'] ?? '');
                                $rst = (int)($r['status'] ?? 1);
                                ?>
                                <div class="reply <?= $rst === 0 ? 'reply--hidden' : ''; ?>">
                                    <div class="reply-head">
                                        <span class="reply-who"><?= $e($rauth); ?><span class="reply-time"><?= $e($rtime); ?></span>
                                        <?php if ($rst === 0): ?>
                                            <span class="reply-hidden-tag">Hidden</span>
                                        <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="reply-body"><?= $e($rbody); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <style>
            .posts-view .post-card { max-width: 920px; margin: 0 auto; }
            .post-title { margin: 0; }
            .post-excerpt { margin-top: 10px; opacity: .9; }
            .post-meta { margin-top: 10px; font-size: .9rem; opacity: .75; }
            .post-premium-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                background: rgba(168, 85, 247, 0.2);
                color: #a855f7;
                font-weight: 600;
                font-size: .85rem;
            }
            .post-body { margin-top: 18px; }
            .post-body p { margin: 0 0 12px; }

            .post-locked {
                margin-top: 30px;
                padding: 40px 20px;
                text-align: center;
                border: 2px dashed rgba(168, 85, 247, 0.3);
                border-radius: 12px;
                background: rgba(168, 85, 247, 0.05);
            }
            .post-locked-icon {
                font-size: 3rem;
                margin-bottom: 12px;
            }
            .post-locked-title {
                font-size: 1.5rem;
                font-weight: 700;
                margin-bottom: 8px;
            }
            .post-locked-desc {
                margin-bottom: 20px;
                opacity: .8;
            }
            .post-locked-btn {
                display: inline-block;
                padding: 10px 24px;
                background: #a855f7;
                color: #fff;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                transition: background 0.2s;
            }
            .post-locked-btn:hover {
                background: #9333ea;
            }

            .post-replies { max-width: 920px; margin: 0 auto; }
            .reply-list { margin-top: 12px; display: flex; flex-direction: column; gap: 12px; }
            .reply { border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 12px 14px; }
            .reply-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
            .reply-who { font-size: .95rem; opacity: .95; }
            .reply-time { display: inline-block; margin-left: 10px; font-size: .8rem; opacity: .7; }
            .reply-body { margin-top: 8px; font-size: .95rem; opacity: .92; line-height: 1.4; }
            .reply-actions { margin: 0; }
            .post-mod { display:flex; gap:8px; align-items:center; }

            .reply--hidden { opacity: 0.65; }
            .reply-hidden-tag {
                display: inline-block;
                margin-left: 8px;
                font-size: .75rem;
                padding: 2px 8px;
                border-radius: 999px;
                border: 1px solid rgba(255,255,255,0.18);
                opacity: .9;
            }
        </style>
        <?php
        return;
    }

    // Index view (list)
    $where = "WHERE status=1";
    if (!$isLoggedIn) {
        $where .= " AND visibility=0";
    }

    $sql = "
        SELECT id, slug, title, excerpt, created_at, published_at, visibility, topic_id, 
               is_premium, price, tier_required
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
                $pPremium = (int)($p['is_premium'] ?? 0);
                $pPrice = $p['price'] ?? null;
                $pTier = (string)($p['tier_required'] ?? 'free');
                
                $pMeta = $e($pTopic) . ' · ' . $e($vis_label($pvis));
                if ($pPremium === 1 && $pPrice !== null) {
                    $pMeta .= ' · <span class="post-list-premium">Premium - $' . number_format((float)$pPrice, 2) . '</span>';
                } elseif ($pTier !== 'free') {
                    $pMeta .= ' · <span class="post-list-premium">' . $e(ucfirst($pTier)) . '</span>';
                }
                $pMeta .= ' · ' . $e($pDate);
                ?>
                <a class="post-row" href="/posts/<?= $e($pslug); ?>">
                    <div class="post-row-title"><?= $e($ptitle); ?></div>
                    <?php if ($pex !== ''): ?>
                        <div class="post-row-ex"><?= $e($pex); ?></div>
                    <?php endif; ?>
                    <div class="post-row-meta"><?= $pMeta; ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Purchase Modal -->
    <div class="purchase-modal" id="purchaseModal" aria-hidden="true" style="position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;">
        <div class="purchase-modal-backdrop" data-purchase-close="1" style="position:absolute;inset:0;background:rgba(0,0,0,0.8);"></div>
        <div class="purchase-modal-card" role="dialog" aria-modal="true" style="position:relative;z-index:1;background:#1f2937;border-radius:16px;max-width:480px;width:90%;padding:0;box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);">
            <button class="purchase-modal-close" type="button" data-purchase-close="1" aria-label="Close" style="position:absolute;top:12px;right:12px;background:none;border:none;color:#fff;font-size:28px;cursor:pointer;padding:4px;width:32px;height:32px;opacity:0.7;">×</button>
            
            <div class="purchase-modal-body" style="padding:32px 24px;text-align:center;">
                <div class="purchase-modal-icon" style="font-size:4rem;margin-bottom:16px;">🔒</div>
                <h2 class="purchase-modal-title" id="purchaseTitle" style="font-size:1.5rem;font-weight:700;margin:0 0 12px;color:#fff;">Premium Content</h2>
                <p class="purchase-modal-desc" id="purchaseDesc" style="color:rgba(255,255,255,0.8);margin:0 0 24px;">Choose how you'd like to access this content:</p>
                
                <div class="purchase-options" id="purchaseOptions" style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;"></div>
                
                <div class="purchase-modal-footer" style="display:flex;justify-content:center;gap:12px;">
                    <button type="button" class="btn-purchase-cancel" data-purchase-close="1" style="padding:10px 24px;background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
                </div>
            </div>
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
        .post-list-premium {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            background: rgba(168, 85, 247, 0.2);
            color: #a855f7;
            font-weight: 600;
            font-size: .8rem;
        }

        .purchase-modal[aria-hidden="false"] { display: flex !important; }
        .purchase-option {
            background: rgba(168, 85, 247, 0.1);
            border: 2px solid rgba(168, 85, 247, 0.3);
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: left;
        }
        .purchase-option:hover {
            background: rgba(168, 85, 247, 0.2);
            border-color: rgba(168, 85, 247, 0.5);
        }
        .purchase-option-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 4px;
            color: #a855f7;
        }
        .purchase-option-desc {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.7);
            margin-bottom: 8px;
        }
        .purchase-option-price {
            font-weight: 600;
            color: #fff;
            font-size: 1.2rem;
        }
        .post-locked-btn {
            display: inline-block;
            padding: 10px 24px;
            background: #a855f7;
            color: #fff;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .post-locked-btn:hover {
            background: #9333ea;
            color: #fff;
        }
    </style>

    <script>
    (function() {
        var purchaseModal = document.getElementById('purchaseModal');
        var purchaseTitle = document.getElementById('purchaseTitle');
        var purchaseOptions = document.getElementById('purchaseOptions');

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-purchase-post-id]');
            if (btn) {
                e.preventDefault();
                var id = btn.getAttribute('data-purchase-post-id');
                var price = btn.getAttribute('data-purchase-price');
                var tier = btn.getAttribute('data-purchase-tier');
                var title = btn.getAttribute('data-purchase-title');
                
                purchaseTitle.textContent = title;
                
                var html = '';
                if (price && parseFloat(price) > 0) {
                    html += '<div class="purchase-option" data-purchase-action="one-time" data-id="' + id + '" data-price="' + price + '">';
                    html += '<div class="purchase-option-title">One-Time Purchase</div>';
                    html += '<div class="purchase-option-desc">Buy this post once and keep it forever</div>';
                    html += '<div class="purchase-option-price">$' + price + '</div>';
                    html += '</div>';
                }
                
                if (tier && tier !== 'Free') {
                    html += '<div class="purchase-option" data-purchase-action="subscribe" data-tier="' + tier.toLowerCase() + '">';
                    html += '<div class="purchase-option-title">' + tier + ' Subscription</div>';
                    html += '<div class="purchase-option-desc">Access this and all ' + tier + ' content</div>';
                    html += '<div class="purchase-option-price">Subscribe Now</div>';
                    html += '</div>';
                }
                
                purchaseOptions.innerHTML = html;
                purchaseModal.setAttribute('aria-hidden', 'false');
            }

            var closeBtn = e.target.closest('[data-purchase-close]');
            if (closeBtn) {
                e.preventDefault();
                purchaseModal.setAttribute('aria-hidden', 'true');
            }

            var option = e.target.closest('[data-purchase-action]');
            if (option) {
                e.preventDefault();
                var action = option.getAttribute('data-purchase-action');
                
                if (action === 'subscribe') {
                    var tier = option.getAttribute('data-tier');
                    window.location.href = '/account?upgrade=' + tier;
                } else if (action === 'one-time') {
                    var id = option.getAttribute('data-id');
                    var price = option.getAttribute('data-price');
                    window.location.href = '/checkout?type=post&id=' + id + '&price=' + price;
                }
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && purchaseModal.getAttribute('aria-hidden') === 'false') {
                purchaseModal.setAttribute('aria-hidden', 'true');
            }
        });
    })();
    </script>
    <?php
})();
