<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Media
 *
 * Route:
 *   /media
 *
 * Shows published media from media_gallery/media_files in a grid.
 * - Public items (visibility=0) are visible to everyone
 * - Member items (visibility=2) are visible only when logged in
 * - Premium items require payment or subscription tier
 * - Editors/Admins/Creators can preview unpublished (status=0) on the public surface
 * - Click any tile to open a simple lightbox (no deps) for images/videos
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

    $loggedIn = false;
    $userId = null;
    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = $auth->check();
        if ($loggedIn && method_exists($auth, 'id')) {
            try {
                $userId = $auth->id();
            } catch (Throwable $e) {
                $userId = null;
            }
        }
    }

    // Determine role for privileged preview of unpublished items (editors/creators/admins)
    $roleId = 0;
    if ($loggedIn && $userId !== null) {
        $urow = $db->fetch('SELECT role_id FROM users WHERE id=' . (int)$userId . ' LIMIT 1');
        if (is_array($urow) && isset($urow['role_id'])) {
            $roleId = (int)$urow['role_id'];
        }
    }

    $canPreviewUnpublished = ($roleId === 2 || $roleId === 4 || $roleId === 5);
    $isAdmin = ($roleId === 4);

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    // Visibility rules: Public always. Members only when logged in.
    $visSql = $loggedIn
        ? "g.visibility IN (0,2)"
        : "g.visibility = 0";

    // Status rules:
    // - Public sees only published (status=1)
    // - Logged-in members see only published
    // - Editors (2), Admins (4), Creators (5) can preview unpublished (status=0)
    $statusSql = ($loggedIn && $canPreviewUnpublished)
        ? "g.status IN (0,1)"
        : "g.status = 1";

    $sql = "
        SELECT
            g.id,
            f.filename,
            f.rel_path,
            f.mime,
            f.created_at,
            g.title,
            g.caption,
            g.visibility,
            g.status,
            g.sort_order,
            g.is_premium,
            g.price,
            g.tier_required
        FROM media_gallery g
        INNER JOIN media_files f ON f.id = g.file_id
        WHERE {$statusSql}
          AND {$visSql}
        ORDER BY g.sort_order ASC, f.id DESC
        LIMIT 150
    ";

    $items = [];
    $res = $conn->query($sql);
    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            if (is_array($r)) {
                $items[] = $r;
            }
        }
        $res->close();
    }

    /**
     * Check if user has purchased this specific media item
     */
    $hasPurchased = static function (int $mediaId, ?int $userId) use ($conn): bool {
        if ($userId === null) {
            return false;
        }
        
        $stmt = $conn->prepare("
            SELECT id FROM content_purchases 
            WHERE user_id=? AND content_type='media' AND content_id=? AND status='completed'
            LIMIT 1
        ");
        if ($stmt === false) {
            return false;
        }
        
        $stmt->bind_param('ii', $userId, $mediaId);
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

    $isImageMime = static function (string $mime): bool {
        return (strpos($mime, 'image/') === 0);
    };

    $isVideoMime = static function (string $mime): bool {
        return (strpos($mime, 'video/') === 0);
    };

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    ?>
    <div class="container my-4 media-gallery">
        <h1 class="h3 m-0">Media</h1>
        <div class="small text-muted mt-1">
            <?php if ($loggedIn && $canPreviewUnpublished): ?>
                Preview: Published + Unpublished
            <?php else: ?>
                <?= $loggedIn ? 'Public + Members gallery' : 'Public gallery'; ?>
            <?php endif; ?>
        </div>

        <?php if (empty($items)): ?>
            <div class="alert small mt-3">No media found.</div>
            <?php return; ?>
        <?php endif; ?>

        <div class="media-grid mt-3">
            <?php foreach ($items as $it): ?>
                <?php
                $mediaId  = (int)($it['id'] ?? 0);
                $rel      = (string)($it['rel_path'] ?? '');
                $mime     = (string)($it['mime'] ?? '');
                $title    = trim((string)($it['title'] ?? ''));
                $caption  = trim((string)($it['caption'] ?? ''));
                $filename = (string)($it['filename'] ?? '');
                $vis      = (int)($it['visibility'] ?? 0);
                $st       = (int)($it['status'] ?? 1);
                $isPremium = (int)($it['is_premium'] ?? 0);
                $price     = $it['price'] ?? null;
                $tierReq   = (string)($it['tier_required'] ?? 'free');

                $url = $rel !== '' ? $rel : '';
                if ($url !== '' && $url[0] !== '/') {
                    $url = '/' . $url;
                }

                $isImg = $isImageMime($mime) && $url !== '' && is_file($docroot . $url);
                $isVid = $isVideoMime($mime) && $url !== '' && is_file($docroot . $url);

                $label = $title !== '' ? $title : ($filename !== '' ? $filename : 'Media');
                
                // Determine access status
                $hasAccess = false;
                $accessReason = '';
                
                // Admins always have access
                if ($isAdmin) {
                    $hasAccess = true;
                }
                // Check premium status
                elseif ($isPremium === 1) {
                    if ($hasPurchased($mediaId, $userId)) {
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
                
                // Build meta label
                if ($isPremium === 1 && $price !== null) {
                    $meta = 'Premium - $' . number_format((float)$price, 2);
                } elseif ($tierReq !== 'free') {
                    $meta = ucfirst($tierReq);
                } else {
                    $meta = ($vis === 2) ? 'Members' : 'Public';
                }
                
                if ($st === 0) {
                    $meta = 'Unpublished · ' . $meta;
                }
                ?>

                <?php if ($isImg): ?>
                    <?php if ($hasAccess): ?>
                        <a
                            class="media-tile"
                            data-media-kind="image"
                            href="<?= $h($url); ?>"
                            data-media-full="<?= $h($url); ?>"
                            data-media-alt="<?= $h($label); ?>"
                            data-media-title="<?= $h($title); ?>"
                            data-media-caption="<?= $h($caption); ?>"
                        >
                            <img src="<?= $h($url); ?>" alt="<?= $h($label); ?>">

                            <?php if ($st === 0): ?>
                                <div class="media-badge">Unpublished</div>
                            <?php endif; ?>

                            <?php if ($title !== '' || $caption !== ''): ?>
                                <div class="media-overlay">
                                    <?php if ($title !== ''): ?>
                                        <div class="media-overlay-title"><?= $h($title); ?></div>
                                    <?php endif; ?>
                                    <?php if ($caption !== ''): ?>
                                        <div class="media-overlay-cap"><?= $h($caption); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="media-pill"><?= $h($meta); ?></div>
                        </a>
                    <?php else: ?>
                        <div 
                            class="media-tile media-locked media-locked-clickable" 
                            data-purchase-type="media"
                            data-purchase-id="<?= $mediaId; ?>"
                            data-purchase-price="<?= $price !== null ? number_format((float)$price, 2) : '0.00'; ?>"
                            data-purchase-tier="<?= $h(ucfirst($tierReq)); ?>"
                            data-purchase-title="<?= $h($label); ?>"
                            onclick="console.log('Div clicked!'); event.preventDefault();"
                        >
                            <div class="media-locked-overlay">🔒</div>
                            <img src="<?= $h($url); ?>" alt="<?= $h($label); ?>">
                            <div class="media-pill media-pill-locked"><?= $h($meta); ?></div>
                            <div class="media-locked-action">Click to Purchase</div>
                        </div>
                    <?php endif; ?>
                    
                <?php elseif ($isVid): ?>
                    <?php if ($hasAccess): ?>
                        <a
                            class="media-tile media-video-tile"
                            href="<?= $h($url); ?>"
                            data-media-kind="video"
                            data-media-full="<?= $h($url); ?>"
                            data-media-alt="<?= $h($label); ?>"
                            data-media-title="<?= $h($title); ?>"
                            data-media-caption="<?= $h($caption); ?>"
                        >
                            <div class="media-video-thumb">
                                <div class="media-video-play">▶</div>
                                <div class="media-video-name"><?= $h($label); ?></div>
                            </div>

                            <?php if ($st === 0): ?>
                                <div class="media-badge">Unpublished</div>
                            <?php endif; ?>

                            <?php if ($title !== '' || $caption !== ''): ?>
                                <div class="media-overlay">
                                    <?php if ($title !== ''): ?>
                                        <div class="media-overlay-title"><?= $h($title); ?></div>
                                    <?php endif; ?>
                                    <?php if ($caption !== ''): ?>
                                        <div class="media-overlay-cap"><?= $h($caption); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="media-pill"><?= $h($meta); ?></div>
                        </a>
                    <?php else: ?>
                        <div 
                            class="media-tile media-video-tile media-locked media-locked-clickable"
                            data-purchase-type="media"
                            data-purchase-id="<?= $mediaId; ?>"
                            data-purchase-price="<?= $price !== null ? number_format((float)$price, 2) : '0.00'; ?>"
                            data-purchase-tier="<?= $h(ucfirst($tierReq)); ?>"
                            data-purchase-title="<?= $h($label); ?>"
                        >
                            <div class="media-locked-overlay">🔒</div>
                            <div class="media-video-thumb">
                                <div class="media-video-play">▶</div>
                                <div class="media-video-name"><?= $h($label); ?></div>
                            </div>
                            <div class="media-pill media-pill-locked"><?= $h($meta); ?></div>
                            <div class="media-locked-action">Click to Purchase</div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <a class="media-tile media-file-tile" href="<?= $hasAccess ? $h($url) : '#'; ?>" 
                       <?= $hasAccess ? 'target="_blank" rel="noopener"' : ''; ?>>
                        <div class="media-file">
                            <div class="fw-semibold"><?= $h($label); ?></div>
                            <div class="small text-muted mt-1"><?= $h($mime); ?></div>
                            <div class="small text-muted mt-1"><?= $h($meta); ?></div>
                        </div>
                    </a>
                <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>

    <!-- Purchase Modal -->
    <div class="purchase-modal" id="purchaseModal" aria-hidden="true">
        <div class="purchase-modal-backdrop" data-purchase-close="1"></div>
        <div class="purchase-modal-card" role="dialog" aria-modal="true">
            <button class="purchase-modal-close" type="button" data-purchase-close="1" aria-label="Close">×</button>
            
            <div class="purchase-modal-body">
                <div class="purchase-modal-icon">🔒</div>
                <h2 class="purchase-modal-title" id="purchaseTitle">Premium Content</h2>
                <p class="purchase-modal-desc" id="purchaseDesc">This content requires a purchase or subscription.</p>
                
                <div class="purchase-options" id="purchaseOptions"></div>
                
                <div class="purchase-modal-footer">
                    <button type="button" class="btn-purchase-cancel" data-purchase-close="1">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="media-modal" id="mediaModal" aria-hidden="true">
        <div class="media-modal-backdrop" data-media-close="1"></div>
        <div class="media-modal-card" role="dialog" aria-modal="true">
            <button class="media-modal-close" type="button" data-media-close="1" aria-label="Close">×</button>

            <div class="media-modal-body">
                <img id="mediaModalImg" alt="">
                <video id="mediaModalVid" controls playsinline style="display:none;"></video>
                <div class="media-modal-meta" id="mediaModalMeta" style="display:none;">
                    <div class="media-modal-title" id="mediaModalTitle"></div>
                    <div class="media-modal-cap" id="mediaModalCap"></div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .media-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, 1fr);
        }

        @media (min-width: 768px) {
            .media-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 1200px) {
            .media-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        .media-tile {
            display: block;
            position: relative;
            border: 1px solid rgba(0,0,0,0.10);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            text-decoration: none;
            color: inherit;
        }

        .media-tile img {
            display: block;
            width: 100%;
            height: 160px;
            object-fit: cover;
        }

        .media-locked {
            cursor: not-allowed;
        }

        .media-locked-clickable {
            cursor: pointer !important;
        }

        .media-locked-clickable:hover {
            border-color: rgba(168, 85, 247, 0.5);
        }

        .media-locked img {
            filter: blur(8px);
            opacity: 0.4;
        }

        .media-locked-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 3rem;
            z-index: 10;
        }

        .media-locked-action {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(168, 85, 247, 0.9);
            color: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            z-index: 11;
        }

        .media-file-tile .media-file {
            height: 160px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
            background: rgba(0,0,0,0.03);
        }

        .media-overlay {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 10px 10px 28px;
            background: linear-gradient(to top, rgba(0,0,0,0.70), rgba(0,0,0,0));
            color: #fff;
        }

        .media-overlay-title {
            font-weight: 700;
            font-size: 13px;
            line-height: 1.2;
        }

        .media-overlay-cap {
            font-size: 12px;
            opacity: 0.9;
            margin-top: 4px;
            line-height: 1.2;
        }

        .media-pill {
            position: absolute;
            right: 10px;
            top: 10px;
            background: rgba(0,0,0,0.70);
            color: #fff;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .media-pill-locked {
            background: rgba(220, 38, 38, 0.9);
        }

        .media-badge {
            position: absolute;
            left: 10px;
            top: 10px;
            background: rgba(245,158,11,0.90);
            color: #fff;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .media-video-tile .media-video-thumb {
            position: relative;
            height: 160px;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .media-video-tile .media-video-play {
            position: absolute;
            font-size: 42px;
            color: rgba(255,255,255,0.85);
        }

        .media-video-tile .media-video-name {
            position: absolute;
            bottom: 10px;
            left: 10px;
            right: 10px;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
        }

        .media-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
        }

        .media-modal[aria-hidden="false"] {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .media-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.92);
        }

        .media-modal-card {
            position: relative;
            z-index: 1;
            max-width: 90vw;
            max-height: 90vh;
        }

        .media-modal-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: none;
            border: none;
            color: #fff;
            font-size: 32px;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
        }

        .media-modal-body img {
            max-width: 90vw;
            max-height: 90vh;
            display: block;
            border-radius: 8px;
        }

        .media-modal-body video {
            max-width: 90vw;
            max-height: 90vh;
            display: block;
            border-radius: 8px;
        }

        .media-modal-meta {
            background: rgba(0,0,0,0.80);
            color: #fff;
            padding: 12px;
            margin-top: 10px;
            border-radius: 8px;
        }

        .media-modal-title {
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 4px;
        }

        .media-modal-cap {
            font-size: 14px;
            opacity: 0.9;
        }

        /* Purchase Modal */
        .purchase-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .purchase-modal[aria-hidden="false"] {
            display: flex;
        }

        .purchase-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.8);
        }

        .purchase-modal-card {
            position: relative;
            z-index: 1;
            background: #1f2937;
            border-radius: 16px;
            max-width: 480px;
            width: 90%;
            padding: 0;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
        }

        .purchase-modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: none;
            border: none;
            color: #fff;
            font-size: 28px;
            cursor: pointer;
            padding: 4px;
            width: 32px;
            height: 32px;
            opacity: 0.7;
        }

        .purchase-modal-close:hover {
            opacity: 1;
        }

        .purchase-modal-body {
            padding: 32px 24px;
            text-align: center;
        }

        .purchase-modal-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }

        .purchase-modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 12px;
            color: #fff;
        }

        .purchase-modal-desc {
            color: rgba(255,255,255,0.8);
            margin: 0 0 24px;
        }

        .purchase-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 24px;
        }

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

        .purchase-modal-footer {
            display: flex;
            justify-content: center;
            gap: 12px;
        }

        .btn-purchase-cancel {
            padding: 10px 24px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }

        .btn-purchase-cancel:hover {
            background: rgba(255,255,255,0.15);
        }
    </style>

    <script>
        (function () {
            var modal = document.getElementById('mediaModal');
            var img = document.getElementById('mediaModalImg');
            var vid = document.getElementById('mediaModalVid');
            var meta = document.getElementById('mediaModalMeta');
            var title = document.getElementById('mediaModalTitle');
            var cap = document.getElementById('mediaModalCap');

            var purchaseModal = document.getElementById('purchaseModal');
            var purchaseTitle = document.getElementById('purchaseTitle');
            var purchaseDesc = document.getElementById('purchaseDesc');
            var purchaseOptions = document.getElementById('purchaseOptions');

            function openModal(kind, src, alt, t, c) {
                if (kind === 'video') {
                    img.style.display = 'none';
                    vid.style.display = '';
                    vid.src = src;
                    vid.load();
                    try { vid.play(); } catch (e) {}
                } else {
                    vid.style.display = 'none';
                    if (vid) {
                        try {
                            vid.pause();
                        } catch (e) {}
                    }
                    img.style.display = '';
                    img.src = src;
                    img.alt = alt || '';
                }

                title.textContent = t || '';
                cap.textContent = c || '';

                if ((t && t.trim() !== '') || (c && c.trim() !== '')) {
                    meta.style.display = '';
                } else {
                    meta.style.display = 'none';
                }

                modal.setAttribute('aria-hidden', 'false');
            }

            function closeModal() {
                modal.setAttribute('aria-hidden', 'true');
                img.src = '';
                img.alt = '';
                img.style.display = '';

                if (vid) {
                    try { vid.pause(); } catch (e) {}
                    vid.removeAttribute('src');
                    vid.load();
                    vid.style.display = 'none';
                }
            }

            function openPurchaseModal(type, id, price, tier, itemTitle) {
                purchaseTitle.textContent = itemTitle;
                purchaseDesc.textContent = 'Choose how you\'d like to access this content:';
                
                var html = '';
                
                // One-time purchase option
                if (price && parseFloat(price) > 0) {
                    html += '<div class="purchase-option" data-purchase-action="one-time" data-id="' + id + '" data-price="' + price + '">';
                    html += '<div class="purchase-option-title">One-Time Purchase</div>';
                    html += '<div class="purchase-option-desc">Buy this content once and keep it forever</div>';
                    html += '<div class="purchase-option-price">$' + price + '</div>';
                    html += '</div>';
                }
                
                // Subscription option
                if (tier && tier !== 'Free') {
                    html += '<div class="purchase-option" data-purchase-action="subscribe" data-tier="' + tier.toLowerCase() + '">';
                    html += '<div class="purchase-option-title">' + tier + ' Subscription</div>';
                    html += '<div class="purchase-option-desc">Access this and all ' + tier + ' content</div>';
                    html += '<div class="purchase-option-price">Subscribe Now</div>';
                    html += '</div>';
                }
                
                // Login option (only show if var isUserLoggedIn is false)
                var isUserLoggedIn = <?= $loggedIn ? 'true' : 'false'; ?>;
                if (!isUserLoggedIn) {
                    html += '<div class="purchase-option" data-purchase-action="login">';
                    html += '<div class="purchase-option-title">Already have access?</div>';
                    html += '<div class="purchase-option-desc">Login to view your purchased content</div>';
                    html += '<div class="purchase-option-price">Login</div>';
                    html += '</div>';
                }
                
                purchaseOptions.innerHTML = html;
                purchaseModal.setAttribute('aria-hidden', 'false');
            }

            function closePurchaseModal() {
                purchaseModal.setAttribute('aria-hidden', 'true');
            }

            // Click event handlers - order matters!
            document.addEventListener('click', function (e) {
                console.log('Click detected on:', e.target);
                
                // 1. Check for close buttons first
                var close = e.target.closest('[data-media-close="1"]');
                if (close) {
                    console.log('Close button clicked');
                    e.preventDefault();
                    closeModal();
                    return;
                }

                var purchaseClose = e.target.closest('[data-purchase-close="1"]');
                if (purchaseClose) {
                    console.log('Purchase close button clicked');
                    e.preventDefault();
                    closePurchaseModal();
                    return;
                }

                // 2. Check for locked/purchase items BEFORE checking for media items
                var lockedItem = e.target.closest('[data-purchase-type]');
                console.log('Locked item found:', lockedItem);
                if (lockedItem) {
                    console.log('Opening purchase modal for:', lockedItem.getAttribute('data-purchase-title'));
                    e.preventDefault();
                    openPurchaseModal(
                        lockedItem.getAttribute('data-purchase-type'),
                        lockedItem.getAttribute('data-purchase-id'),
                        lockedItem.getAttribute('data-purchase-price'),
                        lockedItem.getAttribute('data-purchase-tier'),
                        lockedItem.getAttribute('data-purchase-title')
                    );
                    return;
                }

                // 3. Check for purchase option clicks
                var purchaseOption = e.target.closest('[data-purchase-action]');
                if (purchaseOption) {
                    e.preventDefault();
                    var action = purchaseOption.getAttribute('data-purchase-action');
                    
                    if (action === 'login') {
                        window.location.href = '/login';
                    } else if (action === 'subscribe') {
                        var tier = purchaseOption.getAttribute('data-tier');
                        window.location.href = '/account?upgrade=' + tier;
                    } else if (action === 'one-time') {
                        var id = purchaseOption.getAttribute('data-id');
                        var price = purchaseOption.getAttribute('data-price');
                        window.location.href = '/checkout?type=media&id=' + id + '&price=' + price;
                    }
                    return;
                }

                // 4. Finally check for regular media items (unlocked)
                var a = e.target.closest('a[data-media-full]');
                if (a) {
                    e.preventDefault();
                    openModal(
                        a.getAttribute('data-media-kind') || 'image',
                        a.getAttribute('data-media-full'),
                        a.getAttribute('data-media-alt'),
                        a.getAttribute('data-media-title'),
                        a.getAttribute('data-media-caption')
                    );
                    return;
                }
            });

            // Keyboard events
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    if (modal.getAttribute('aria-hidden') === 'false') {
                        closeModal();
                    }
                    if (purchaseModal.getAttribute('aria-hidden') === 'false') {
                        closePurchaseModal();
                    }
                }
            });
        })();
    </script>
    <?php
})();
