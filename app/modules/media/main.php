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
 * - Click any tile to open a simple lightbox (no deps)
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

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    // Published only. Public always. Members only when logged in.
    $visSql = $loggedIn
        ? "g.visibility IN (0,2)"
        : "g.visibility = 0";

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
            g.sort_order
        FROM media_gallery g
        INNER JOIN media_files f ON f.id = g.file_id
        WHERE g.status = 1
          AND {$visSql}
        ORDER BY g.sort_order ASC, f.id DESC
        LIMIT 240
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

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    ?>
    <div class="container my-4 media-gallery">
        <h1 class="h3 m-0">Media</h1>
        <div class="small text-muted mt-1">
            <?= $loggedIn ? 'Public + Members gallery' : 'Public gallery'; ?>
        </div>

        <?php if (empty($items)): ?>
            <div class="alert small mt-3">No published media yet.</div>
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

                $url = $rel !== '' ? $rel : '';
                if ($url !== '' && $url[0] !== '/') {
                    $url = '/' . $url;
                }

                $isImg = $isImageMime($mime) && $url !== '' && is_file($docroot . $url);

                $label = $title !== '' ? $title : ($filename !== '' ? $filename : 'Media');
                $meta  = ($vis === 2) ? 'Members' : 'Public';
                ?>

                <?php if ($isImg): ?>
                    <a
                        class="media-tile"
                        href="<?= $h($url); ?>"
                        data-media-full="<?= $h($url); ?>"
                        data-media-alt="<?= $h($label); ?>"
                        data-media-title="<?= $h($title); ?>"
                        data-media-caption="<?= $h($caption); ?>"
                    >
                        <img src="<?= $h($url); ?>" alt="<?= $h($label); ?>">

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
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
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
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            background: rgba(255,255,255,0.03);
        }

        .media-tile img {
            width: 100%;
            height: 170px;
            object-fit: cover;
            display: block;
        }

        .media-tile:hover {
            border-color: rgba(255,255,255,0.22);
        }

        .media-overlay {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 10px 10px 8px;
            background: linear-gradient(to top, rgba(0,0,0,0.78), rgba(0,0,0,0.0));
            color: #fff;
        }

        .media-overlay-title {
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .media-overlay-cap {
            margin-top: 4px;
            font-size: 12px;
            line-height: 1.2;
            opacity: 0.9;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .media-pill {
            position: absolute;
            top: 8px;
            left: 8px;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.18);
            background: rgba(0,0,0,0.35);
            color: rgba(255,255,255,0.92);
            pointer-events: none;
        }

        .media-file {
            height: 170px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 14px;
        }

        /* Lightbox */
        .media-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
        }

        .media-modal[aria-hidden="false"] {
            display: block;
        }

        .media-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.78);
        }

        .media-modal-card {
            position: relative;
            width: min(1100px, calc(100vw - 24px));
            margin: 60px auto;
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 14px;
            background: rgba(10,10,10,0.88);
            overflow: hidden;
        }

        .media-modal-close {
            position: absolute;
            top: 8px;
            right: 10px;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.16);
            background: rgba(0,0,0,0.35);
            color: #fff;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
        }

        .media-modal-body {
            padding: 14px;
        }

        .media-modal-body img {
            width: 100%;
            height: auto;
            max-height: 72vh;
            object-fit: contain;
            display: block;
            border-radius: 10px;
            background: rgba(255,255,255,0.02);
        }

        .media-modal-meta {
            margin-top: 10px;
            color: rgba(255,255,255,0.92);
        }

        .media-modal-title {
            font-weight: 700;
        }

        .media-modal-cap {
            margin-top: 4px;
            opacity: 0.9;
        }
    </style>

    <script>
        (function () {
            var modal = document.getElementById('mediaModal');
            var img = document.getElementById('mediaModalImg');
            var meta = document.getElementById('mediaModalMeta');
            var title = document.getElementById('mediaModalTitle');
            var cap = document.getElementById('mediaModalCap');

            function openModal(full, alt, t, c) {
                img.src = full;
                img.alt = alt || '';
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

