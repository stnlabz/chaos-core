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
    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = $auth->check();
    }

    // Determine role for privileged preview of unpublished items (editors/creators/admins)
    $roleId = 0;
    if ($loggedIn && isset($auth) && $auth instanceof auth && method_exists($auth, 'id')) {
        try {
            $uid = $auth->id();
            if (is_int($uid) && $uid > 0) {
                $urow = $db->fetch('SELECT role_id FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
                if (is_array($urow) && isset($urow['role_id'])) {
                    $roleId = (int) $urow['role_id'];
                }
            }
        } catch (Throwable $e) {
            $roleId = 0;
        }
    }

    $canPreviewUnpublished = ($roleId === 2 || $roleId === 4 || $roleId === 5);

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
            f.id,
            f.filename,
            f.rel_path,
            f.mime,
            f.created_at,
            g.title,
            g.caption,
            g.visibility,
            g.status,
            g.sort_order
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
                $rel      = (string) ($it['rel_path'] ?? '');
                $mime     = (string) ($it['mime'] ?? '');
                $title    = trim((string) ($it['title'] ?? ''));
                $caption  = trim((string) ($it['caption'] ?? ''));
                $filename = (string) ($it['filename'] ?? '');
                $vis      = (int) ($it['visibility'] ?? 0);
                $st       = (int) ($it['status'] ?? 1);

                $url = $rel !== '' ? $rel : '';
                if ($url !== '' && $url[0] !== '/') {
                    $url = '/' . $url;
                }

                $isImg = $isImageMime($mime) && $url !== '' && is_file($docroot . $url);
                $isVid = $isVideoMime($mime) && $url !== '' && is_file($docroot . $url);

                $label = $title !== '' ? $title : ($filename !== '' ? $filename : 'Media');
                $meta  = ($vis === 2) ? 'Members' : 'Public';
                if ($st === 0) {
                    $meta = 'Unpublished · ' . $meta;
                }
                ?>

                <?php if ($isImg): ?>
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
                <?php elseif ($isVid): ?>
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
                    <a class="media-tile media-file-tile" href="<?= $h($url); ?>" target="_blank" rel="noopener">
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
            background: rgba(0,0,0,0.7);
            color: #fff;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 999px;
        }

        .media-modal {
            position: fixed;
            inset: 0;
            display: none;
            z-index: 9999;
        }

        .media-modal[aria-hidden="false"] {
            display: block;
        }

        .media-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.85);
        }

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

        .media-modal-body {
            padding: 16px;
        }

        .media-modal-body img {
            max-width: 92vw;
            max-height: 78vh;
            width: 100%;
            height: auto;
            border-radius: 10px;
            display: block;
            background: #000;
        }

        .media-modal-meta {
            margin-top: 10px;
            color: #fff;
        }

        .media-modal-title {
            font-weight: 800;
            margin-bottom: 4px;
        }

        .media-modal-cap {
            opacity: 0.85;
            line-height: 1.35;
        }

        .media-badge{
            position:absolute;
            top:10px;
            left:10px;
            background:rgba(0,0,0,0.75);
            color:#fff;
            font-size:12px;
            padding:4px 8px;
            border-radius:999px;
            letter-spacing:0.2px;
            z-index:3;
        }

        .media-video-thumb{
            height:160px;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:10px;
            background:rgba(0,0,0,0.04);
            padding:12px;
            text-align:center;
        }

        .media-video-play{
            width:44px;
            height:44px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            border:1px solid rgba(0,0,0,0.25);
            font-size:18px;
            line-height:1;
            opacity:0.85;
        }

        .media-video-name{
            font-size:13px;
            opacity:0.9;
            max-width:100%;
            word-break:break-word;
        }

        .media-modal-body video{
            max-width:92vw;
            max-height:78vh;
            width:100%;
            border-radius:10px;
            background:#000;
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

            function openModal(kind, full, alt, t, c) {
                if (!kind) { kind = 'image'; }

                if (kind === 'video') {
                    if (vid) {
                        vid.src = full || '';
                        vid.style.display = '';
                    }
                    img.src = '';
                    img.style.display = 'none';
                } else {
                    img.src = full || '';
                    img.alt = alt || '';
                    img.style.display = '';
                    if (vid) {
                        vid.pause();
                        vid.removeAttribute('src');
                        vid.load();
                        vid.style.display = 'none';
                    }
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

            document.addEventListener('click', function (e) {
                var close = e.target.closest('[data-media-close="1"]');
                if (close) {
                    e.preventDefault();
                    closeModal();
                    return;
                }

                var a = e.target.closest('a[data-media-full]');
                if (!a) return;

                e.preventDefault();
                openModal(
                    a.getAttribute('data-media-kind') || 'image',
                    a.getAttribute('data-media-full'),
                    a.getAttribute('data-media-alt'),
                    a.getAttribute('data-media-title'),
                    a.getAttribute('data-media-caption')
                );
            });

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

