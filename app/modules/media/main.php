<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Media
 *
 * Routes:
 *   /media
 *
 * Notes:
 * - Displays media gallery (images + video)
 * - Social interactions handled by chaos_action endpoints in this same file
 * - Visibility controls listing visibility
 * - Premium controls viewing access (paid entitlement)
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

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isMediaRoute = ($path === '/media' || strpos($path, '/media') === 0);
    if (!$isMediaRoute) {
        return;
    }

    // -------------------------------------------------------------
    // Auth context
    // -------------------------------------------------------------
    $loggedIn = false;
    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = $auth->check();
    }

    $userId = 0;
    $roleId = 0;
    $urow = null;

    if ($loggedIn && isset($auth) && $auth instanceof auth) {
        $uid = $auth->id();
        if (is_int($uid) && $uid > 0) {
            $userId = (int)$uid;
            $urow = $db->fetch("SELECT role_id, username FROM users WHERE id=" . (int)$userId . " LIMIT 1");
            if (is_array($urow)) {
                $roleId = (int)($urow['role_id'] ?? 0);
            }
        }
    }

    $isAdminOrEditor = ($roleId === 2 || $roleId === 4 || $roleId === 5);
    $canPreviewUnpublished = ($roleId === 2 || $roleId === 4 || $roleId === 5);

    $userName = 'Guest';
    if ($loggedIn && is_array($urow) && isset($urow['username'])) {
        $userName = (string)$urow['username'];
        if ($userName === '') {
            $userName = 'User';
        }
    }

    // -------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------
    $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');

    $isImageMime = static function (string $mime): bool {
        return (strpos($mime, 'image/') === 0);
    };

    $isVideoMime = static function (string $mime): bool {
        return ($mime === 'video/mp4' || strpos($mime, 'video/') === 0);
    };

    // Paid entitlement check (Stripe webhook inserts "paid" rows)
    $hasPaid = static function (mysqli $conn, int $uid, string $refType, int $refId): bool {
        if ($uid <= 0 || $refId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM finance_ledger
            WHERE user_id=? AND ref_type=? AND ref_id=? AND status='paid'
            LIMIT 1
        ");
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('isi', $uid, $refType, $refId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res instanceof mysqli_result) ? (bool)$res->fetch_row() : false;
        $stmt->close();

        return $ok;
    };

    // Premium gating:
    // - Admin/editor: always allowed
    // - Not logged in: locked
    // - Logged in: locked unless paid
    $isPremiumLocked = static function (
        mysqli $conn,
        bool $loggedIn,
        bool $isAdminOrEditor,
        int $uid,
        int $mediaId,
        int $isPremium
    ) use ($hasPaid): bool {
        if ($isPremium !== 1) {
            return false;
        }
        if ($isAdminOrEditor) {
            return false;
        }
        if (!$loggedIn || $uid <= 0) {
            return true;
        }
        return !$hasPaid($conn, $uid, 'media', $mediaId);
    };

    // -------------------------------------------------------------
    // Social actions (JSON)
    // -------------------------------------------------------------
    $chaosAction = (string)($_GET['chaos_action'] ?? '');
    if ($chaosAction !== '') {
        header('Content-Type: application/json; charset=utf-8');

        $mediaId = (int)($_GET['media_id'] ?? 0);
        if ($mediaId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'bad_media_id']);
            return;
        }

        // Visibility enforcement (must match listing rules)
        $visSql = "g.visibility=0";
        if ($loggedIn) {
            $visSql = "g.visibility IN (0,2)";
            if ($isAdminOrEditor) {
                $visSql = "g.visibility IN (0,1,2)";
            }
        }

        $statusSql = "g.status=1";
        if ($canPreviewUnpublished) {
            $statusSql = "g.status IN (0,1)";
        }

        $sql = "
            SELECT
                g.id, g.file_id, g.title, g.caption, g.visibility, g.status, g.is_premium, g.price, g.tier_required,
                f.rel_path, f.mime
            FROM media_gallery g
            LEFT JOIN media_files f ON f.id = g.file_id
            WHERE g.id=? AND ($visSql) AND ($statusSql)
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
            return;
        }

        $stmt->bind_param('i', $mediaId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            return;
        }

        $isPremium = ((int)($row['is_premium'] ?? 0) === 1) ? 1 : 0;
        $locked = $isPremiumLocked($conn, $loggedIn, $isAdminOrEditor, (int)$userId, (int)$mediaId, (int)$isPremium);

        // For premium locked items: block social actions (keeps premium truly gated)
        if ($locked) {
            echo json_encode(['ok' => false, 'error' => 'premium_required']);
            return;
        }

        if ($chaosAction === 'react') {
            if (!$loggedIn || $userId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'login_required']);
                return;
            }

            $kind = (string)($_POST['kind'] ?? '');
            if (!in_array($kind, ['like', 'love', 'laugh'], true)) {
                $kind = 'like';
            }

            $stmt2 = $conn->prepare("
                INSERT INTO media_social_reactions (media_id, user_id, kind, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            if ($stmt2 === false) {
                echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
                return;
            }

            $uid = (int)$userId;
            $stmt2->bind_param('iis', $mediaId, $uid, $kind);
            $stmt2->execute();
            $stmt2->close();

            echo json_encode(['ok' => true]);
            return;
        }

        if ($chaosAction === 'comment') {
            if (!$loggedIn || $userId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'login_required']);
                return;
            }

            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($comment === '') {
                echo json_encode(['ok' => false, 'error' => 'empty']);
                return;
            }

            if (strlen($comment) > 500) {
                $comment = substr($comment, 0, 500);
            }

            $stmt2 = $conn->prepare("
                INSERT INTO media_social_comments (media_id, user_id, comment, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            if ($stmt2 === false) {
                echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
                return;
            }

            $uid = (int)$userId;
            $stmt2->bind_param('iis', $mediaId, $uid, $comment);
            $stmt2->execute();
            $stmt2->close();

            echo json_encode(['ok' => true]);
            return;
        }

        if ($chaosAction === 'delete_comment') {
            if (!$loggedIn || !$isAdminOrEditor) {
                echo json_encode(['ok' => false, 'error' => 'forbidden']);
                return;
            }

            $cid = (int)($_POST['comment_id'] ?? 0);
            if ($cid <= 0) {
                echo json_encode(['ok' => false, 'error' => 'bad_comment_id']);
                return;
            }

            $stmt2 = $conn->prepare("DELETE FROM media_social_comments WHERE id=? AND media_id=? LIMIT 1");
            if ($stmt2 === false) {
                echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
                return;
            }

            $stmt2->bind_param('ii', $cid, $mediaId);
            $stmt2->execute();
            $stmt2->close();

            echo json_encode(['ok' => true]);
            return;
        }

        if ($chaosAction === 'social') {
            $out = [
                'ok' => true,
                'logged_in' => $loggedIn ? 1 : 0,
                'user' => $userName,
                'counts' => ['like' => 0, 'love' => 0, 'laugh' => 0],
                'comments' => [],
            ];

            $resR = $conn->query("
                SELECT kind, COUNT(*) AS c
                FROM media_social_reactions
                WHERE media_id=" . (int)$mediaId . "
                GROUP BY kind
            ");
            if ($resR instanceof mysqli_result) {
                while ($r = $resR->fetch_assoc()) {
                    if (is_array($r)) {
                        $k = (string)($r['kind'] ?? '');
                        $c = (int)($r['c'] ?? 0);
                        if (isset($out['counts'][$k])) {
                            $out['counts'][$k] = $c;
                        }
                    }
                }
                $resR->close();
            }

            $resC = $conn->query("
                SELECT c.id, c.user_id, u.username, c.comment, c.created_at
                FROM media_social_comments c
                LEFT JOIN users u ON u.id = c.user_id
                WHERE c.media_id=" . (int)$mediaId . "
                ORDER BY c.id DESC
                LIMIT 60
            ");
            if ($resC instanceof mysqli_result) {
                while ($r = $resC->fetch_assoc()) {
                    if (is_array($r)) {
                        $out['comments'][] = [
                            'id' => (int)($r['id'] ?? 0),
                            'user_id' => (int)($r['user_id'] ?? 0),
                            'username' => (string)($r['username'] ?? ''),
                            'comment' => (string)($r['comment'] ?? ''),
                            'created_at' => (string)($r['created_at'] ?? ''),
                        ];
                    }
                }
                $resC->close();
            }

            echo json_encode($out);
            return;
        }

        echo json_encode(['ok' => false, 'error' => 'unknown_action']);
        return;
    }

    // -------------------------------------------------------------
    // Listing rules + sorting
    // -------------------------------------------------------------
    $sort = (string)($_GET['sort'] ?? 'newest');
    if (!in_array($sort, ['newest', 'oldest'], true)) {
        $sort = 'newest';
    }
    $orderBy = ($sort === 'oldest') ? 'g.created_at ASC, g.id ASC' : 'g.created_at DESC, g.id DESC';

    $visSql = "g.visibility=0";
    if ($loggedIn) {
        $visSql = "g.visibility IN (0,2)";
        if ($isAdminOrEditor) {
            $visSql = "g.visibility IN (0,1,2)";
        }
    }

    $statusSql = "g.status=1";
    if ($canPreviewUnpublished) {
        $statusSql = "g.status IN (0,1)";
    }

    $sql = "
        SELECT
            g.id,
            g.file_id,
            g.title,
            g.caption,
            g.visibility,
            g.status,
            g.is_premium,
            g.price,
            g.tier_required,
            g.created_at,
            f.filename,
            f.rel_path,
            f.mime
        FROM media_gallery g
        LEFT JOIN media_files f ON f.id = g.file_id
        WHERE ($visSql) AND ($statusSql)
        ORDER BY $orderBy
        LIMIT 200
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

    ?>
    <div class="container my-4 media-index">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h1 class="h3 m-0">Media</h1>
                <div class="small text-muted">Images and video</div>
            </div>

            <form method="get" action="/media" class="d-flex align-items-center gap-2">
                <input type="hidden" name="sort" value="<?= $h($sort === 'newest' ? 'oldest' : 'newest'); ?>">
                <button class="btn btn-sm"><?= $sort === 'newest' ? 'Oldest' : 'Newest'; ?></button>
            </form>
        </div>

        <?php if (empty($items)): ?>
            <div class="alert small">No media files yet.</div>
            <?php return; ?>
        <?php endif; ?>

        <div class="media-grid">
            <?php foreach ($items as $it): ?>
                <?php
                if (!is_array($it)) {
                    continue;
                }

                $id       = (int)($it['id'] ?? 0);
                $title    = trim((string)($it['title'] ?? ''));
                $caption  = trim((string)($it['caption'] ?? ''));
                $rel      = (string)($it['rel_path'] ?? '');
                $mime     = (string)($it['mime'] ?? '');
                $filename = (string)($it['filename'] ?? '');
                $st       = (int)($it['status'] ?? 1);

                $isPremium = ((int)($it['is_premium'] ?? 0) === 1) ? 1 : 0;
                $tier      = (string)($it['tier_required'] ?? 'free');
                $price     = (string)($it['price'] ?? '');

                $url = $rel !== '' ? $rel : '';
                if ($url !== '' && $url[0] !== '/') {
                    $url = '/' . $url;
                }

                $isImg = $isImageMime($mime) && $url !== '' && is_file($docroot . $url);
                $isVid = $isVideoMime($mime) && $url !== '' && is_file($docroot . $url);

                if (!$isImg && !$isVid) {
                    continue;
                }

                $meta = $title !== '' ? $title : ($filename !== '' ? $filename : ('Media #' . $id));
                if ($st === 0) {
                    $meta = 'Unpublished · ' . $meta;
                }

                $locked = $isPremiumLocked($conn, $loggedIn, $isAdminOrEditor, (int)$userId, (int)$id, (int)$isPremium);
                ?>
                <a
                    class="media-tile<?= $locked ? ' is-locked' : ''; ?><?= $isVid ? ' media-video-tile' : ''; ?>"
                    href="<?= $h($url); ?>"
                    data-media-id="<?= (int)$id; ?>"
                    data-media-kind="<?= $locked ? 'locked' : ($isVid ? 'video' : 'image'); ?>"
                    data-media-full="<?= $locked ? '' : $h($url); ?>"
                    data-media-locked="<?= $locked ? '1' : '0'; ?>"
                    data-media-alt="<?= $h($meta); ?>"
                    data-media-title="<?= $h($title); ?>"
                    data-media-caption="<?= $h($caption); ?>"
                    data-media-premium="<?= (int)$isPremium; ?>"
                    data-media-tier="<?= $h($tier); ?>"
                    data-media-price="<?= $h($price); ?>"
                >
                    <?php if ($isImg): ?>
                        <img src="<?= $h($url); ?>" alt="<?= $h($meta); ?>">
                    <?php else: ?>
                        <div class="media-video-thumb">
                            <div class="media-video-icon">▶</div>
                            <div class="media-video-name"><?= $h($meta); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($st === 0): ?>
                        <div class="media-badge">Unpublished</div>
                    <?php endif; ?>

                    <?php if ($isPremium === 1): ?>
                        <div class="media-premium">Premium</div>
                    <?php endif; ?>

                    <?php if ($price !== '' && $isPremium === 1): ?>
                        <div class="media-price">$<?= $h($price); ?></div>
                    <?php endif; ?>

                    <div class="media-overlay">
                        <div class="media-overlay-title"><?= $h($meta); ?></div>
                        <?php if ($caption !== ''): ?>
                            <div class="media-overlay-cap"><?= $h($caption); ?></div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="media-modal" id="mediaModal" aria-hidden="true">
        <div class="media-modal-backdrop" data-close="1"></div>
        <div class="media-modal-card">
            <button class="media-modal-close" type="button" data-close="1">×</button>
            <div class="media-modal-body">
                <img id="mediaModalImg" src="" alt="" style="display:none;">
                <video id="mediaModalVid" controls playsinline style="display:none;"></video>

                <div class="media-modal-lock" id="mediaModalLock" style="display:none;">
                    <div class="media-modal-lock-title">Premium content</div>
                    <div class="media-modal-lock-text" id="mediaModalLockText"></div>
                    <div class="media-modal-lock-actions" id="mediaModalLockActions"></div>
                </div>

                <div class="media-modal-meta" id="mediaModalMeta" style="display:none;">
                    <div class="media-modal-title" id="mediaModalTitle"></div>
                    <div class="media-modal-cap" id="mediaModalCap"></div>
                </div>

                <div class="media-social" id="mediaSocial" style="display:none;">
                    <div class="media-social-row">
                        <button class="btn btn-sm btn-light" type="button" data-react="like">👍 Like</button>
                        <button class="btn btn-sm btn-light" type="button" data-react="love">❤️ Love</button>
                        <button class="btn btn-sm btn-light" type="button" data-react="laugh">😂 Laugh</button>
                        <div class="media-social-counts" id="mediaSocialCounts"></div>
                    </div>

                    <div class="media-social-comments">
                        <div class="media-social-comments-title">Comments</div>
                        <div class="media-social-comments-list" id="mediaSocialComments"></div>

                        <div class="media-social-add" id="mediaSocialAdd">
                            <input type="text" id="mediaSocialInput" placeholder="Add a comment..." style="flex:1;">
                            <button class="btn btn-sm btn-primary" type="button" id="mediaSocialSend">Send</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <style>
        /* keep width similar to 2.0.9 (not full-bleed) */
        .media-index { max-width: 1180px; }

        .media-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        @media (min-width: 768px) { .media-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (min-width: 1200px) { .media-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }

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

        /* prevent distortion: aspect-ratio + cover */
        .media-tile img {
            display: block;
            width: 100%;
            aspect-ratio: 4 / 3;
            height: auto;
            object-fit: cover;
        }

        .media-overlay {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 10px 10px 28px;
            background: linear-gradient(to top, rgba(0,0,0,0.68), rgba(0,0,0,0));
            color: #fff;
        }

        .media-overlay-title { font-weight: 700; font-size: 13px; line-height: 1.2; }
        .media-overlay-cap { font-size: 12px; opacity: 0.92; margin-top: 4px; line-height: 1.2; }

        .media-badge {
            position: absolute;
            left: 10px;
            top: 10px;
            background: rgba(255,193,7,0.92);
            color: #000;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 999px;
            z-index: 3;
        }

        .media-premium {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.75);
            color: #fff;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            line-height: 1;
            z-index: 3;
        }

        .media-price {
            position: absolute;
            right: 10px;
            top: 38px;
            background: rgba(0,0,0,0.75);
            color: #fff;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 999px;
            z-index: 3;
        }

        .media-video-thumb {
            aspect-ratio: 4 / 3;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #111;
            color: #fff;
        }

        .media-video-icon {
            width: 52px;
            height: 52px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.22);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .media-video-name {
            font-size: 12px;
            opacity: 0.9;
            padding: 0 12px;
            text-align: center;
            line-height: 1.2;
        }

        .media-tile.is-locked img { filter: blur(3px); }
        .media-tile.is-locked::after {
            content: "🔒";
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            background: rgba(0,0,0,0.18);
            z-index: 2;
        }

        .media-modal { position: fixed; inset: 0; display: none; z-index: 9999; }
        .media-modal[aria-hidden="false"] { display: block; }

        .media-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.85); }

        .media-modal-card {
            position: relative;
            max-width: 92vw;
            max-height: 92vh;
            margin: 4vh auto;
            background: #111;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .media-modal-close {
            position: absolute;
            top: 8px;
            right: 10px;
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            z-index: 2;
        }

        .media-modal-body { padding: 16px; }

        .media-modal-body img,
        .media-modal-body video {
            max-width: 92vw;
            max-height: 78vh;
            width: 100%;
            height: auto;
            border-radius: 10px;
            display: block;
            background: #000;
        }

        .media-modal-meta { margin-top: 12px; color: #fff; }
        .media-modal-title { font-weight: 700; font-size: 16px; }
        .media-modal-cap { opacity: 0.85; margin-top: 4px; font-size: 13px; }

        .media-modal-lock {
            width: 100%;
            padding: 20px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            background: rgba(0,0,0,0.35);
            color: #fff;
            text-align: center;
        }

        .media-modal-lock-title { font-weight: 700; margin-bottom: 6px; }
        .media-modal-lock-text { opacity: 0.85; margin-bottom: 12px; font-size: 0.95rem; }
        .media-modal-lock-actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }

        .media-social { margin-top: 14px; color: #fff; }
        .media-social-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .media-social-counts { margin-left: auto; font-size: 12px; opacity: 0.85; }

        .media-social-comments { margin-top: 12px; }
        .media-social-comments-title { font-weight: 700; font-size: 13px; margin-bottom: 8px; }
        .media-social-comments-list {
            max-height: 260px;
            overflow: auto;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 12px;
            padding: 10px;
            background: rgba(0,0,0,0.25);
        }

        .media-social-comment { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .media-social-comment:last-child { border-bottom: 0; }

        .media-social-comment-meta {
            font-size: 12px;
            opacity: 0.85;
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
        .media-social-comment-body { margin-top: 4px; font-size: 13px; line-height: 1.35; }
        .media-social-add { margin-top: 10px; display: flex; gap: 8px; align-items: center; }
    </style>

    <script>
        (function () {
            var modal = document.getElementById('mediaModal');
            var img = document.getElementById('mediaModalImg');
            var vid = document.getElementById('mediaModalVid');
            var meta = document.getElementById('mediaModalMeta');
            var title = document.getElementById('mediaModalTitle');
            var cap = document.getElementById('mediaModalCap');

            var social = document.getElementById('mediaSocial');
            var socialCounts = document.getElementById('mediaSocialCounts');
            var socialComments = document.getElementById('mediaSocialComments');
            var socialAdd = document.getElementById('mediaSocialAdd');
            var socialInput = document.getElementById('mediaSocialInput');
            var socialSend = document.getElementById('mediaSocialSend');

            var lockText = document.getElementById('mediaModalLockText');
            var lockActions = document.getElementById('mediaModalLockActions');

            var currentMediaId = 0;
            var loggedIn = <?= $loggedIn ? 'true' : 'false'; ?>;

            function escapeHtml(s) {
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function closeModal() {
                modal.setAttribute('aria-hidden', 'true');
                if (vid) {
                    vid.pause();
                    vid.removeAttribute('src');
                    vid.load();
                }
                if (img) {
                    img.removeAttribute('src');
                }
                if (social) {
                    social.style.display = 'none';
                }
            }

            function syncSocial() {
                if (!currentMediaId || !social || !socialCounts || !socialComments) return;

                fetch('/media?chaos_action=social&media_id=' + encodeURIComponent(currentMediaId), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) return;

                        var c = data.counts || {};
                        socialCounts.textContent =
                            '👍 ' + (c.like || 0) + '  ' +
                            '❤️ ' + (c.love || 0) + '  ' +
                            '😂 ' + (c.laugh || 0);

                        var html = '';
                        var list = data.comments || [];
                        for (var i = 0; i < list.length; i++) {
                            var it = list[i] || {};
                            var del = '';
                            <?php if ($isAdminOrEditor): ?>
                            if (it.id) {
                                del = '<a href="#" data-social-del="' + it.id + '" style="color:#f66;">delete</a>';
                            }
                            <?php endif; ?>

                            html +=
                                '<div class="media-social-comment">' +
                                    '<div class="media-social-comment-meta">' +
                                        '<div><strong>' + escapeHtml(it.username || '') + '</strong> · ' + escapeHtml(it.created_at || '') + '</div>' +
                                        '<div>' + del + '</div>' +
                                    '</div>' +
                                    '<div class="media-social-comment-body">' + escapeHtml(it.comment || '') + '</div>' +
                                '</div>';
                        }
                        socialComments.innerHTML = html;

                        social.style.display = '';
                        if (data.logged_in) {
                            if (socialAdd) socialAdd.style.display = '';
                        } else {
                            if (socialAdd) socialAdd.style.display = 'none';
                        }
                    })
                    .catch(function () {});
            }

            function react(kind) {
                if (!loggedIn || !currentMediaId) return;
                var fd = new FormData();
                fd.append('kind', kind);
                fetch('/media?chaos_action=react&media_id=' + encodeURIComponent(currentMediaId), {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function () { syncSocial(); }).catch(function () {});
            }

            function sendComment() {
                if (!currentMediaId || !socialInput) return;
                var val = (socialInput.value || '').trim();
                if (!val) return;
                var fd = new FormData();
                fd.append('comment', val);
                fetch('/media?chaos_action=comment&media_id=' + encodeURIComponent(currentMediaId), {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function () {
                    socialInput.value = '';
                    syncSocial();
                }).catch(function () {});
            }

            function delComment(id) {
                if (!currentMediaId) return;
                var fd = new FormData();
                fd.append('comment_id', id);
                fetch('/media?chaos_action=delete_comment&media_id=' + encodeURIComponent(currentMediaId), {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function () { syncSocial(); }).catch(function () {});
            }

            function openModal(kind, full, alt, t, c, mediaId, tier, price) {
                var lock = document.getElementById('mediaModalLock');
                currentMediaId = mediaId || 0;

                if (lock) { lock.style.display = 'none'; }
                if (lockText) { lockText.textContent = ''; }
                if (lockActions) { lockActions.innerHTML = ''; }

                if (img) { img.style.display = 'none'; }
                if (vid) { vid.style.display = 'none'; }
                if (meta) { meta.style.display = 'none'; }
                if (social) { social.style.display = 'none'; }

                if (kind === 'locked') {
                    if (lock) { lock.style.display = ''; }
                    if (meta) { meta.style.display = ''; }

                    if (title) { title.textContent = t || ''; }
                    if (cap) { cap.textContent = c || ''; }

                    if (!loggedIn) {
                        if (lockText) lockText.textContent = 'This item is premium content. Log in to purchase and view.';
                        if (lockActions) lockActions.innerHTML = '<a class="btn btn-sm btn-primary" href="/login">Log in</a>';
                    } else {
                        var p = (price && String(price).trim() !== '') ? (' $' + String(price).trim()) : '';
                        if (lockText) lockText.textContent = 'This item is premium content' + p + '. Purchase required to view.';
                        if (lockActions) {
                            lockActions.innerHTML =
                                '<a class="btn btn-sm btn-primary" href="/checkout?ref_type=media&ref_id=' + encodeURIComponent(String(currentMediaId)) + '">Purchase</a>';
                        }
                    }

                    modal.setAttribute('aria-hidden', 'false');
                    return;
                }

                if (kind === 'video') {
                    if (vid) {
                        vid.src = full || '';
                        vid.style.display = '';
                    }
                } else {
                    if (img) {
                        img.src = full || '';
                        img.alt = alt || '';
                        img.style.display = '';
                    }
                }

                if (title) title.textContent = t || '';
                if (cap) cap.textContent = c || '';

                if ((t && t.trim() !== '') || (c && c.trim() !== '')) {
                    if (meta) meta.style.display = '';
                }

                modal.setAttribute('aria-hidden', 'false');
                syncSocial();
            }

            // Click handler MUST be on document so tiles work
            document.addEventListener('click', function (e) {
                var close = e.target && e.target.getAttribute ? e.target.getAttribute('data-close') : null;
                if (close === '1') {
                    e.preventDefault();
                    closeModal();
                    return;
                }

                var btn = e.target.closest && e.target.closest('[data-react]');
                if (btn) {
                    e.preventDefault();
                    react(btn.getAttribute('data-react'));
                    return;
                }

                var del = e.target.closest && e.target.closest('[data-social-del]');
                if (del) {
                    e.preventDefault();
                    delComment(parseInt(del.getAttribute('data-social-del') || '0', 10) || 0);
                    return;
                }

                var a = e.target.closest && e.target.closest('a[data-media-id]');
                if (!a) return;

                e.preventDefault();

                var locked = (a.getAttribute('data-media-locked') === '1');
                if (locked) {
                    openModal(
                        'locked',
                        '',
                        a.getAttribute('data-media-alt') || '',
                        a.getAttribute('data-media-title') || '',
                        a.getAttribute('data-media-caption') || '',
                        parseInt(a.getAttribute('data-media-id') || '0', 10) || 0,
                        a.getAttribute('data-media-tier') || '',
                        a.getAttribute('data-media-price') || ''
                    );
                    return;
                }

                openModal(
                    a.getAttribute('data-media-kind') || 'image',
                    a.getAttribute('data-media-full') || '',
                    a.getAttribute('data-media-alt') || '',
                    a.getAttribute('data-media-title') || '',
                    a.getAttribute('data-media-caption') || '',
                    parseInt(a.getAttribute('data-media-id') || '0', 10) || 0,
                    a.getAttribute('data-media-tier') || '',
                    a.getAttribute('data-media-price') || ''
                );
            });

            if (socialSend) {
                socialSend.addEventListener('click', function (e) {
                    e.preventDefault();
                    sendComment();
                });
            }

            if (socialInput) {
                socialInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        sendComment();
                    }
                });
            }

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    if (modal.getAttribute('aria-hidden') === 'false') {
                        closeModal();
                    }
                }
            });
        })();
    </script>
    <?php
})();

