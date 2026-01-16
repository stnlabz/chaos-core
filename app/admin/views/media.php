<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin: Media
 * Route:
 *   /admin?action=media
 *
 * Purpose:
 * - List/Upload/Edit media
 * - ADDED: Monetization fields (is_premium, price, tier_required, uploader_id)
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

    $userId = null;
    if (isset($auth) && $auth instanceof auth && method_exists($auth, 'id')) {
        try {
            $userId = $auth->id();
        } catch (Throwable $e) {
            $userId = null;
        }
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $safe_rel = static function (string $rel): string {
        $rel = str_replace(['..', '~'], '', $rel);
        return ltrim($rel, '/');
    };

    /**
     * Ensure media_gallery row exists for a file_id.
     */
    $ensure_gallery = static function (mysqli $conn, int $fileId, ?int $uploaderId = null): void {
        $row = $conn->query("SELECT id FROM media_gallery WHERE file_id={$fileId} LIMIT 1");
        if ($row instanceof mysqli_result && $row->num_rows > 0) {
            $row->close();
            
            // Update uploader_id if provided and not already set
            if ($uploaderId !== null) {
                $check = $conn->query("SELECT uploader_id FROM media_gallery WHERE file_id={$fileId} LIMIT 1");
                if ($check instanceof mysqli_result) {
                    $data = $check->fetch_assoc();
                    $check->close();
                    if (is_array($data) && ($data['uploader_id'] === null || (int)$data['uploader_id'] === 0)) {
                        $conn->query("UPDATE media_gallery SET uploader_id={$uploaderId} WHERE file_id={$fileId} LIMIT 1");
                    }
                }
            }
            return;
        }
        if ($row instanceof mysqli_result) {
            $row->close();
        }

        $uploaderSql = $uploaderId !== null ? $uploaderId : 'NULL';
        $conn->query("INSERT INTO media_gallery (file_id, uploader_id) VALUES ({$fileId}, {$uploaderSql})");
    };

    $visibility_text = static function (int $v): string {
        return match ($v) {
            2 => 'Members',
            0 => 'Public',
            default => 'Unknown',
        };
    };

    $status_text = static function (int $s): string {
        return ($s === 1) ? 'Published' : 'Draft';
    };

    $tier_text = static function (string $t): string {
        return match ($t) {
            'pro' => 'Pro',
            'premium' => 'Premium',
            'free' => 'Free',
            default => 'Free',
        };
    };

    $notice = '';
    $error = '';

    // -------------------------------------------------------------
    // POST handlers
    // -------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $do = (string)($_POST['do'] ?? '');

        // Upload new file
        if ($do === 'upload') {
            if (!isset($_FILES['file'])) {
                $error = 'No file received.';
            } else {
                $f = $_FILES['file'];
                $tmpPath = (string)($f['tmp_name'] ?? '');
                $origName = (string)($f['name'] ?? '');
                $fsize = (int)($f['size'] ?? 0);
                $ferr = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);

                if ($ferr !== UPLOAD_ERR_OK) {
                    $error = 'Upload error: ' . (string)$ferr;
                } elseif ($tmpPath === '' || !is_file($tmpPath)) {
                    $error = 'Upload path invalid.';
                } else {
                    $base = preg_replace('~[^a-z0-9_\-\.]~i', '_', strtolower($origName)) ?? 'file';
                    if ($base === '') {
                        $base = 'file_' . time();
                    }

                    $destDir = $docroot . '/public/media';
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0755, true);
                    }

                    $dest = $destDir . '/' . $base;
                    $rel = '/public/media/' . $base;

                    $moved = @move_uploaded_file($tmpPath, $dest);
                    if (!$moved) {
                        $error = 'Move failed.';
                    } else {
                        $mime = mime_content_type($dest) ?: 'application/octet-stream';
                        $size = filesize($dest) ?: 0;

                        $stmt = $conn->prepare("INSERT INTO media_files (filename, rel_path, mime, size_bytes) VALUES (?,?,?,?)");
                        if ($stmt === false) {
                            $error = 'DB insert failed.';
                        } else {
                            $stmt->bind_param('sssi', $base, $rel, $mime, $size);
                            $stmt->execute();
                            $newId = (int)$stmt->insert_id;
                            $stmt->close();

                            if ($newId > 0) {
                                $ensure_gallery($conn, $newId, $userId);
                            }

                            $notice = 'Upload complete.';
                        }
                    }
                }
            }

            header('Location: /admin?action=media');
            exit;
        }

        // Save metadata / visibility / status / sort_order / MONETIZATION
        if ($do === 'save') {
            $fileId = (int)($_POST['id'] ?? 0);

            if ($fileId > 0) {
                $ensure_gallery($conn, $fileId, $userId);

                $title = trim((string)($_POST['title'] ?? ''));
                $caption = trim((string)($_POST['caption'] ?? ''));
                $sort = (int)($_POST['sort_order'] ?? 0);

                $vis = (int)($_POST['visibility'] ?? 0);
                if ($vis !== 2) {
                    $vis = 0;
                }

                $status = (int)($_POST['status'] ?? 0);
                $status = ($status === 1) ? 1 : 0;

                // MONETIZATION FIELDS
                $isPremium = (int)($_POST['is_premium'] ?? 0);
                $isPremium = ($isPremium === 1) ? 1 : 0;

                $price = trim((string)($_POST['price'] ?? ''));
                $priceDecimal = null;
                if ($price !== '' && is_numeric($price)) {
                    $priceDecimal = (float)$price;
                    if ($priceDecimal < 0) {
                        $priceDecimal = null;
                    }
                }

                $tierRequired = trim((string)($_POST['tier_required'] ?? 'free'));
                if (!in_array($tierRequired, ['free', 'premium', 'pro'], true)) {
                    $tierRequired = 'free';
                }

                $sql = "UPDATE media_gallery
                        SET title=?, caption=?, visibility=?, status=?, sort_order=?,
                            is_premium=?, price=?, tier_required=?
                        WHERE file_id=? LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt !== false) {
                    $stmt->bind_param(
                        'ssiiiidsi',
                        $title,
                        $caption,
                        $vis,
                        $status,
                        $sort,
                        $isPremium,
                        $priceDecimal,
                        $tierRequired,
                        $fileId
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = 'Updated.';
                }
            }

            header('Location: /admin?action=media');
            exit;
        }

        // Delete
        if ($do === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT id, rel_path FROM media_files WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $stmt->close();

                    if (is_array($row)) {
                        $rel = (string)($row['rel_path'] ?? '');
                        $fs = $rel !== '' ? ($docroot . '/' . $safe_rel($rel)) : '';

                        $conn->query("DELETE FROM media_gallery WHERE file_id=" . (int)$id . " LIMIT 1");
                        $del = $conn->prepare("DELETE FROM media_files WHERE id=? LIMIT 1");
                        if ($del !== false) {
                            $del->bind_param('i', $id);
                            $del->execute();
                            $del->close();
                        }

                        if ($fs !== '' && is_file($fs)) {
                            @unlink($fs);
                        }

                        $notice = 'Deleted.';
                    }
                }
            }

            header('Location: /admin?action=media');
            exit;
        }
    }

    // -------------------------------------------------------------
    // Fetch media list
    // -------------------------------------------------------------
    $sql = "
        SELECT
            f.id,
            f.filename,
            f.rel_path,
            f.mime,
            f.size_bytes,
            f.created_at,
            COALESCE(g.title, '') AS title,
            COALESCE(g.caption, '') AS caption,
            COALESCE(g.visibility, 0) AS visibility,
            COALESCE(g.status, 1) AS status,
            COALESCE(g.sort_order, 0) AS sort_order,
            COALESCE(g.is_premium, 0) AS is_premium,
            g.price,
            COALESCE(g.tier_required, 'free') AS tier_required,
            COALESCE(g.uploader_id, 0) AS uploader_id
        FROM media_files f
        LEFT JOIN media_gallery g ON g.file_id = f.id
        ORDER BY f.id DESC
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

    ?>
    <div class="container my-4 admin-media">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h1 class="h3 m-0">Media Gallery</h1>
            <button class="btn btn-sm btn-primary" onclick="document.getElementById('uploadForm').style.display='block'">Upload</button>
        </div>

        <?php if ($notice !== ''): ?>
            <div class="alert alert-success"><?= $h($notice); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= $h($error); ?></div>
        <?php endif; ?>

        <!-- Upload Form (hidden by default) -->
        <div id="uploadForm" style="display:none; margin-bottom: 20px; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
            <h3 style="margin: 0 0 12px 0; font-size: 1rem;">Upload New Media</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="do" value="upload">
                <div style="margin-bottom: 10px;">
                    <input type="file" name="file" required style="display: block; padding: 8px;">
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-sm btn-primary">Upload</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('uploadForm').style.display='none'">Cancel</button>
                </div>
            </form>
        </div>

        <?php if (empty($items)): ?>
            <div class="alert alert-secondary">No media files yet. Upload your first file above.</div>
        <?php else: ?>
            <div class="admin-media-grid">
                <?php foreach ($items as $it): ?>
                    <?php
                    $id = (int)($it['id'] ?? 0);
                    $fn = (string)($it['filename'] ?? '');
                    $rel = (string)($it['rel_path'] ?? '');
                    $mime = (string)($it['mime'] ?? '');
                    $title = (string)($it['title'] ?? '');
                    $caption = (string)($it['caption'] ?? '');
                    $vis = (int)($it['visibility'] ?? 0);
                    $st = (int)($it['status'] ?? 1);
                    $sort = (int)($it['sort_order'] ?? 0);
                    $isPremium = (int)($it['is_premium'] ?? 0);
                    $price = $it['price'] ?? null;
                    $tier = (string)($it['tier_required'] ?? 'free');
                    $uploaderId = (int)($it['uploader_id'] ?? 0);

                    $isImage = (strpos($mime, 'image/') === 0);
                    $isVideo = (strpos($mime, 'video/') === 0);
                    
                    $priceDisplay = '';
                    if ($price !== null && $isPremium === 1) {
                        $priceDisplay = '$' . number_format((float)$price, 2);
                    }
                    ?>
                    <div class="admin-media-tile">
                        <?php if ($isImage): ?>
                            <div class="admin-media-preview">
                                <img src="<?= $h($rel); ?>" alt="<?= $h($fn); ?>" onclick="showLightbox('<?= $h($rel); ?>')">
                            </div>
                        <?php elseif ($isVideo): ?>
                            <div class="admin-media-preview admin-media-video">
                                <div class="video-icon">▶</div>
                                <div class="video-label">Video</div>
                            </div>
                        <?php else: ?>
                            <div class="admin-media-preview admin-media-file">
                                <div class="file-icon">📄</div>
                                <div class="file-label"><?= $h($mime); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="admin-media-info">
                            <div class="small text-muted"><?= $h($fn); ?></div>
                            
                            <?php if ($isPremium === 1 || $tier !== 'free'): ?>
                                <div class="media-badges">
                                    <?php if ($isPremium === 1): ?>
                                        <span class="badge badge-premium">Premium</span>
                                    <?php endif; ?>
                                    <?php if ($tier !== 'free'): ?>
                                        <span class="badge badge-tier"><?= $h(ucfirst($tier)); ?></span>
                                    <?php endif; ?>
                                    <?php if ($priceDisplay !== ''): ?>
                                        <span class="badge badge-price"><?= $h($priceDisplay); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form method="post" class="admin-media-form">
                            <input type="hidden" name="do" value="save">
                            <input type="hidden" name="id" value="<?= (int)$id; ?>">

                            <div class="form-grid">
                                <div>
                                    <label class="small fw-semibold">Title</label>
                                    <input name="title" type="text" value="<?= $h($title); ?>" placeholder="Optional">
                                </div>
                                <div>
                                    <label class="small fw-semibold">Caption</label>
                                    <textarea name="caption" rows="2" placeholder="Optional"><?= $h($caption); ?></textarea>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div>
                                    <label class="small fw-semibold">Visibility</label>
                                    <select name="visibility">
                                        <option value="0"<?= $vis === 0 ? ' selected' : ''; ?>>Public</option>
                                        <option value="2"<?= $vis === 2 ? ' selected' : ''; ?>>Members</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="small fw-semibold">Status</label>
                                    <select name="status">
                                        <option value="0"<?= $st === 0 ? ' selected' : ''; ?>>Draft</option>
                                        <option value="1"<?= $st === 1 ? ' selected' : ''; ?>>Published</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="small fw-semibold">Sort</label>
                                    <input name="sort_order" type="number" value="<?= (int)$sort; ?>" style="width:90px;">
                                </div>
                            </div>

                            <!-- MONETIZATION FIELDS -->
                            <div class="monetization-section">
                                <div class="section-title">Monetization</div>
                                <div class="form-grid">
                                    <div>
                                        <label class="small fw-semibold">Premium</label>
                                        <select name="is_premium">
                                            <option value="0"<?= $isPremium === 0 ? ' selected' : ''; ?>>No</option>
                                            <option value="1"<?= $isPremium === 1 ? ' selected' : ''; ?>>Yes</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="small fw-semibold">Price (USD)</label>
                                        <input name="price" type="number" step="0.01" min="0" value="<?= $price !== null ? $h((string)$price) : ''; ?>" placeholder="0.00">
                                    </div>
                                    <div>
                                        <label class="small fw-semibold">Tier</label>
                                        <select name="tier_required">
                                            <option value="free"<?= $tier === 'free' ? ' selected' : ''; ?>>Free</option>
                                            <option value="premium"<?= $tier === 'premium' ? ' selected' : ''; ?>>Premium</option>
                                            <option value="pro"<?= $tier === 'pro' ? ' selected' : ''; ?>>Pro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; gap: 8px; margin-top: 10px;">
                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="if(confirm('Delete this file?')) { let f=document.createElement('form'); f.method='post'; f.innerHTML='<input name=do value=delete><input name=id value=<?= (int)$id; ?>>'; document.body.appendChild(f); f.submit(); }">Delete</button>
                            </div>

                            <div class="admin-media-meta small text-muted mt-2">
                                <?= $h($visibility_text($vis)); ?> · <?= $h($status_text($st)); ?>
                                <?php if ($uploaderId > 0): ?>
                                    · Uploader ID: <?= (int)$uploaderId; ?>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Admin Lightbox -->
    <div class="media-lightbox" id="adminMediaLightbox" aria-hidden="true">
        <button type="button" class="media-lightbox-close" id="adminMediaLightboxClose" aria-label="Close">×</button>
        <img class="media-lightbox-img" id="adminMediaLightboxImg" alt="">
    </div>

    <script>
        function showLightbox(src) {
            const lightbox = document.getElementById('adminMediaLightbox');
            const img = document.getElementById('adminMediaLightboxImg');
            img.src = src;
            lightbox.setAttribute('aria-hidden', 'false');
            lightbox.style.display = 'flex';
        }

        document.getElementById('adminMediaLightboxClose').addEventListener('click', function() {
            const lightbox = document.getElementById('adminMediaLightbox');
            lightbox.setAttribute('aria-hidden', 'true');
            lightbox.style.display = 'none';
        });

        document.getElementById('adminMediaLightbox').addEventListener('click', function(e) {
            if (e.target === this) {
                this.setAttribute('aria-hidden', 'true');
                this.style.display = 'none';
            }
        });
    </script>

    <style>
        .admin-media-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        @media (min-width: 768px) {
            .admin-media-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 1100px) {
            .admin-media-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }

        .admin-media-tile {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            background: #fff;
        }

        .admin-media-preview {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }

        .admin-media-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .admin-media-preview img:hover {
            transform: scale(1.05);
        }

        .admin-media-video, .admin-media-file {
            flex-direction: column;
            gap: 8px;
        }

        .video-icon, .file-icon {
            font-size: 2.5rem;
        }

        .video-label, .file-label {
            font-size: 0.85rem;
            color: #6b7280;
        }

        .admin-media-info {
            margin-bottom: 10px;
        }

        .media-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-premium {
            background: #ede9fe;
            color: #6b21a8;
        }

        .badge-tier {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-price {
            background: #d1fae5;
            color: #065f46;
        }

        .admin-media-form {
            font-size: 0.9rem;
        }

        .form-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-grid > div {
            display: flex;
            flex-direction: column;
        }

        .form-grid label {
            margin-bottom: 4px;
            font-size: 0.8rem;
        }

        .form-grid input,
        .form-grid select,
        .form-grid textarea {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .monetization-section {
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            margin-top: 10px;
        }

        .section-title {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }

        .admin-media-meta {
            padding-top: 8px;
            border-top: 1px solid #f3f4f6;
        }

        .media-lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .media-lightbox-img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }

        .media-lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 40px;
            cursor: pointer;
            padding: 0;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.85rem;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: #111827;
            color: #fff;
        }

        .btn-outline {
            border-color: #d1d5db;
            color: #111827;
            background: #fff;
        }

        .btn-danger {
            background: #dc2626;
            color: #fff;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }

        .alert-danger {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .alert-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .small {
            font-size: 0.85rem;
        }

        .text-muted {
            color: #6b7280;
        }

        .fw-semibold {
            font-weight: 600;
        }

        .h3 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .m-0 {
            margin: 0;
        }

        .mb-3 {
            margin-bottom: 1rem;
        }

        .mt-2 {
            margin-top: 0.5rem;
        }

        .d-flex {
            display: flex;
        }

        .align-items-center {
            align-items: center;
        }

        .justify-content-between {
            justify-content: space-between;
        }
    </style>
    <?php
})();
