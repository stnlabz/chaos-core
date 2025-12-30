<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin: Media
 * Route:
 *   /admin?action=media
 *
 * Uses:
 * - media_files (file storage metadata)
 * - media_gallery (gallery metadata)
 *
 * media_gallery:
 *   visibility: 0=public, 2=members
 *   status: 0=draft, 1=published
 */

(function (): void {
    global $db;
    echo '<div class="admin-wrap">';

    $crumb = '<small><a href="/admin">Admin</a> &raquo; Media</small>';

    if (!isset($db) || !$db instanceof db) {
        echo '<div class="container my-4">' . $crumb . '<div class="alert alert-danger mt-2">DB is not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        echo '<div class="container my-4">' . $crumb . '<div class="alert alert-danger mt-2">DB connection failed.</div></div>';
        return;
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    $mediaDirFs = $docroot . '/public/media';

    if (!is_dir($mediaDirFs)) {
        @mkdir($mediaDirFs, 0755, true);
    }

    $notice = '';
    $error  = '';

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $is_image_mime = static function (string $mime): bool {
        return (strpos($mime, 'image/') === 0);
    };

    $slug_filename = static function (string $name): string {
        $name = trim($name);
        $name = (string) preg_replace('~\s+~', '-', $name);
        $name = (string) preg_replace('~[^a-zA-Z0-9\.\-_]+~', '', $name);
        $name = strtolower($name);
        return $name !== '' ? $name : 'file';
    };

    $safe_rel = static function (string $rel): string {
        $rel = trim($rel);
        if ($rel === '') return '';
        if ($rel[0] !== '/') $rel = '/' . $rel;
        return $rel;
    };

    $ensure_gallery = static function (mysqli $conn, int $fileId): void {
        // defaults: visibility=0 (public), status=0 (draft), sort_order=0
        $sql = "INSERT IGNORE INTO media_gallery (file_id, title, caption, visibility, status, sort_order)
                VALUES (?, '', NULL, 0, 0, 0)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $stmt->close();
    };

    $visibility_text = static function (int $v): string {
        return ($v === 2) ? 'Members' : 'Public';
    };

    $status_text = static function (int $s): string {
        return ($s === 1) ? 'Published' : 'Draft';
    };

    // -----------------------------------------------------------------
    // POST Actions
    // -----------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $do = (string) ($_POST['do'] ?? '');

        // Upload
        if ($do === 'upload') {
            if (!isset($_FILES['upload']) || !is_array($_FILES['upload'])) {
                $error = 'No file uploaded.';
            } else {
                $f = $_FILES['upload'];

                $tmp  = (string) ($f['tmp_name'] ?? '');
                $name = (string) ($f['name'] ?? '');
                $err  = (int) ($f['error'] ?? 0);
                $size = (int) ($f['size'] ?? 0);

                if ($err !== 0 || $tmp === '' || !is_uploaded_file($tmp)) {
                    $error = 'Upload failed.';
                } else {
                    $base = $slug_filename($name);
                    $dest = $mediaDirFs . '/' . $base;

                    // avoid overwrite
                    if (is_file($dest)) {
                        $pi = pathinfo($base);
                        $stem = (string) ($pi['filename'] ?? 'file');
                        $ext  = (string) ($pi['extension'] ?? '');
                        $n = 2;
                        do {
                            $try = $stem . '-' . $n . ($ext !== '' ? '.' . $ext : '');
                            $dest = $mediaDirFs . '/' . $try;
                            $n++;
                        } while (is_file($dest));
                        $base = basename($dest);
                    }

                    if (!@move_uploaded_file($tmp, $dest)) {
                        $error = 'Failed to move uploaded file.';
                    } else {
                        $mime = (string) (@mime_content_type($dest) ?: 'application/octet-stream');
                        $rel  = '/public/media/' . $base;

                        $stmt = $conn->prepare("INSERT INTO media_files (filename, rel_path, mime, size_bytes) VALUES (?, ?, ?, ?)");
                        if ($stmt === false) {
                            $error = 'DB insert failed.';
                        } else {
                            $stmt->bind_param('sssi', $base, $rel, $mime, $size);
                            $stmt->execute();
                            $newId = (int) $stmt->insert_id;
                            $stmt->close();

                            if ($newId > 0) {
                                $ensure_gallery($conn, $newId);
                            }

                            $notice = 'Upload complete.';
                        }
                    }
                }
            }

            header('Location: /admin?action=media');
            exit;
        }

        // Save metadata / visibility / status / sort_order
        if ($do === 'save') {
            $fileId = (int) ($_POST['id'] ?? 0);

            if ($fileId > 0) {
                $ensure_gallery($conn, $fileId);

                $title = trim((string) ($_POST['title'] ?? ''));
                $caption = trim((string) ($_POST['caption'] ?? ''));
                $sort = (int) ($_POST['sort_order'] ?? 0);

                $vis = (int) ($_POST['visibility'] ?? 0);
                if ($vis !== 2) $vis = 0;

                $status = (int) ($_POST['status'] ?? 0);
                $status = ($status === 1) ? 1 : 0;

                $sql = "UPDATE media_gallery
                        SET title=?, caption=?, visibility=?, status=?, sort_order=?
                        WHERE file_id=? LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt !== false) {
                    // caption can be empty string; store empty string (simple)
                    $stmt->bind_param('ssiiii', $title, $caption, $vis, $status, $sort, $fileId);
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
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare("SELECT id, rel_path FROM media_files WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $stmt->close();

                    if (is_array($row)) {
                        $rel = (string) ($row['rel_path'] ?? '');
                        $fs = $rel !== '' ? ($docroot . $safe_rel($rel)) : '';

                        $conn->query("DELETE FROM media_gallery WHERE file_id=" . (int) $id . " LIMIT 1");
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

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------
    $q = trim((string) ($_GET['q'] ?? ''));
    $fltVis = (string) ($_GET['vis'] ?? '');     // '', '0', '2'
    $fltStat = (string) ($_GET['st'] ?? '');     // '', '0', '1'

    $where = [];
    $bindTypes = '';
    $bindVals = [];

    if ($q !== '') {
        $where[] = "(f.filename LIKE ? OR f.mime LIKE ? OR g.title LIKE ?)";
        $like = '%' . $q . '%';
        $bindTypes .= 'sss';
        $bindVals[] = $like;
        $bindVals[] = $like;
        $bindVals[] = $like;
    }

    if ($fltVis === '0' || $fltVis === '2') {
        $where[] = "COALESCE(g.visibility, 0) = ?";
        $bindTypes .= 'i';
        $bindVals[] = (int) $fltVis;
    }

    if ($fltStat === '0' || $fltStat === '1') {
        $where[] = "COALESCE(g.status, 0) = ?";
        $bindTypes .= 'i';
        $bindVals[] = (int) $fltStat;
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    // -----------------------------------------------------------------
    // List media (join gallery)
    // -----------------------------------------------------------------
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
            COALESCE(g.status, 0) AS status,
            COALESCE(g.sort_order, 0) AS sort_order
        FROM media_files f
        LEFT JOIN media_gallery g ON g.file_id = f.id
        $whereSql
        ORDER BY f.id DESC
        LIMIT 300
    ";

    $items = [];

    if ($bindTypes === '') {
        $res = $conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                if (is_array($r)) $items[] = $r;
            }
            $res->close();
        }
    } else {
        $stmt = $conn->prepare($sql);
        if ($stmt !== false) {
            // bind dynamically (KISS but safe)
            $refs = [];
            $refs[] = $bindTypes;
            foreach ($bindVals as $k => $v) {
                $refs[] = &$bindVals[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);

            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    if (is_array($r)) $items[] = $r;
                }
                $res->close();
            }
            $stmt->close();
        }
    }

    ?>
    <div class="container my-4 admin-media">
        <?= $crumb; ?>

        <div class="d-flex align-items-center justify-content-between mt-2 mb-3">
            <h1 class="h3 m-0">Media</h1>
            <div class="small text-muted">Folder: <code>/public/media</code></div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger small mb-3"><?= $h($error); ?></div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <div class="alert small mb-3"><?= $h($notice); ?></div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-12 col-md-6 col-lg-3 mb-3">
                <div class="card">
                    <div class="card-body">
                        <div class="fw-semibold">Upload</div>

                        <form method="post" action="/admin?action=media" enctype="multipart/form-data" class="mt-2">
                            <input type="hidden" name="do" value="upload">
                            <div class="mb-2">
                                <label class="small fw-semibold" for="upload">File</label>
                                <input type="file" id="upload" name="upload" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                        </form>

                        <div class="small text-muted mt-2">Images + files. (Grid previews images only.)</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <div class="fw-semibold">Search / Filter</div>

                        <form method="get" action="/admin" class="mt-2">
                            <input type="hidden" name="action" value="media">

                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <label class="small fw-semibold" for="q">Query</label>
                                    <input type="text" id="q" name="q" value="<?= $h($q); ?>" placeholder="filename, mime, title">
                                </div>

                                <div class="col-6 col-md-3">
                                    <label class="small fw-semibold" for="vis">Visibility</label>
                                    <select id="vis" name="vis">
                                        <option value=""<?= $fltVis === '' ? ' selected' : ''; ?>>All</option>
                                        <option value="0"<?= $fltVis === '0' ? ' selected' : ''; ?>>Public</option>
                                        <option value="2"<?= $fltVis === '2' ? ' selected' : ''; ?>>Members</option>
                                    </select>
                                </div>

                                <div class="col-6 col-md-3">
                                    <label class="small fw-semibold" for="st">Status</label>
                                    <select id="st" name="st">
                                        <option value=""<?= $fltStat === '' ? ' selected' : ''; ?>>All</option>
                                        <option value="1"<?= $fltStat === '1' ? ' selected' : ''; ?>>Published</option>
                                        <option value="0"<?= $fltStat === '0' ? ' selected' : ''; ?>>Draft</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-2">
                                <button type="submit" class="btn btn-sm">Apply</button>
                                <?php if ($q !== '' || $fltVis !== '' || $fltStat !== ''): ?>
                                    <a class="btn btn-sm" href="/admin?action=media" style="margin-left:6px;">Clear</a>
                                <?php endif; ?>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($items)): ?>
            <div class="alert small">No media found.</div>
            <?php return; ?>
        <?php endif; ?>

        <div class="admin-media-grid">
            <?php foreach ($items as $it): ?>
                <?php
                $id   = (int) ($it['id'] ?? 0);
                $rel  = $safe_rel((string) ($it['rel_path'] ?? ''));
                $mime = (string) ($it['mime'] ?? '');
                $isImg = $is_image_mime($mime) && $rel !== '' && is_file($docroot . $rel);

                $title = (string) ($it['title'] ?? '');
                $caption = (string) ($it['caption'] ?? '');
                $vis = (int) ($it['visibility'] ?? 0);
                $st  = (int) ($it['status'] ?? 0);
                $sort = (int) ($it['sort_order'] ?? 0);

                $url = $rel !== '' ? $rel : '';
                ?>
                <div class="admin-media-tile">
                    <div class="admin-media-preview">
                        <?php if ($isImg && $url !== ''): ?>
                            <a href="<?= $h($url); ?>" class="admin-media-open" data-full="<?= $h($url); ?>" data-alt="<?= $h($title !== '' ? $title : $it['filename']); ?>">
                                <img src="<?= $h($url); ?>" alt="">
                            </a>
                        <?php else: ?>
                            <div class="admin-media-file">
                                <div class="small text-muted"><?= $h($mime); ?></div>
                                <div class="small mt-1"><?= $h((string) ($it['filename'] ?? '')); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="admin-media-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary admin-copy" data-url="<?= $h($url); ?>">Copy link</button>

                        <form method="post" action="/admin?action=media" onsubmit="return confirm('Delete this file?');" style="display:inline;">
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $id; ?>">
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </div>

                    <form method="post" action="/admin?action=media" class="admin-media-form">
                        <input type="hidden" name="do" value="save">
                        <input type="hidden" name="id" value="<?= (int) $id; ?>">

                        <label class="small fw-semibold">Title</label>
                        <input name="title" value="<?= $h($title); ?>">

                        <label class="small fw-semibold mt-2">Caption</label>
                        <textarea name="caption" rows="2"><?= $h($caption); ?></textarea>

                        <div class="admin-media-row mt-2">
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
                                <input name="sort_order" type="number" value="<?= (int) $sort; ?>" style="width:90px;">
                            </div>
                        </div>

                        <button class="btn btn-sm btn-primary mt-2">Save</button>

                        <div class="admin-media-meta small text-muted mt-2">
                            <?= $h($visibility_text($vis)); ?> · <?= $h($status_text($st)); ?>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Admin Lightbox -->
    <div class="media-lightbox" id="adminMediaLightbox" aria-hidden="true">
        <button type="button" class="media-lightbox-close" id="adminMediaLightboxClose" aria-label="Close">×</button>
        <img class="media-lightbox-img" id="adminMediaLightboxImg" alt="">
    </div>
<!--
    <style>
        .admin-media-grid{
            display:grid;
            gap:14px;
            grid-template-columns:repeat(1, minmax(0, 1fr));
        }
        @media (min-width: 768px){
            .admin-media-grid{grid-template-columns:repeat(3, minmax(0, 1fr));}
        }
        @media (min-width: 1100px){
            .admin-media-grid{grid-template-columns:repeat(4, minmax(0, 1fr));}
        }

        .admin-media-tile{
            border:1px solid #e5e7eb;
            border-radius:12px;
            overflow:hidden;
            background:#fff;
        }
        .admin-media-preview{
            border-bottom:1px solid #e5e7eb;
            background:#f9fafb;
        }
        .admin-media-preview img{
            width:100%;
            height:170px;
            object-fit:cover;
            display:block;
        }
        .admin-media-file{
            height:170px;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            padding:12px;
            text-align:center;
        }

        .admin-media-actions{
            display:flex;
            gap:8px;
            padding:10px 12px;
            border-bottom:1px solid #e5e7eb;
            background:#fff;
        }

        .admin-media-form{
            padding:12px;
        }
        .admin-media-form input,
        .admin-media-form select,
        .admin-media-form textarea{
            width:100%;
            border:1px solid #d1d5db;
            border-radius:8px;
            padding:8px 10px;
            font-size:14px;
        }
        .admin-media-form textarea{resize:vertical}
        .admin-media-row{
            display:grid;
            grid-template-columns:1fr 1fr 100px;
            gap:10px;
            align-items:end;
        }
        .admin-media-meta{opacity:0.9}

        .media-lightbox{
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.88);
            display:none;
            align-items:center;
            justify-content:center;
            padding:20px;
            z-index:9999;
        }
        .media-lightbox.is-open{display:flex}
        .media-lightbox-img{
            max-width:96vw;
            max-height:92vh;
            border-radius:10px;
            border:1px solid rgba(255,255,255,0.15);
        }
        .media-lightbox-close{
            position:absolute;
            top:14px;
            right:16px;
            font-size:28px;
            line-height:1;
            border:0;
            background:transparent;
            color:#fff;
            cursor:pointer;
        }
        body.media-lightbox-open{overflow:hidden}
    </style>
-->
</div>
    <script>
        (function(){
            // copy link
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.admin-copy');
                if(!btn) return;

                var url = btn.getAttribute('data-url') || '';
                if(!url) return;

                if(navigator.clipboard){
                    navigator.clipboard.writeText(url).then(function(){
                        btn.textContent = 'Copied';
                        setTimeout(function(){ btn.textContent = 'Copy link'; }, 1200);
                    });
                    return;
                }

                var ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (x) {}
                document.body.removeChild(ta);
                btn.textContent = 'Copied';
                setTimeout(function(){ btn.textContent = 'Copy link'; }, 1200);
            });

            // lightbox
            var box = document.getElementById('adminMediaLightbox');
            var img = document.getElementById('adminMediaLightboxImg');
            var closeBtn = document.getElementById('adminMediaLightboxClose');
            if(!box || !img || !closeBtn) return;

            function openBox(src, alt){
                img.src = src;
                img.alt = alt || '';
                box.classList.add('is-open');
                box.setAttribute('aria-hidden', 'false');
                document.body.classList.add('media-lightbox-open');
            }

            function closeBox(){
                box.classList.remove('is-open');
                box.setAttribute('aria-hidden', 'true');
                img.src = '';
                document.body.classList.remove('media-lightbox-open');
            }

            document.addEventListener('click', function(e){
                var a = e.target.closest('.admin-media-open');
                if(a){
                    e.preventDefault();
                    openBox(a.getAttribute('data-full') || a.getAttribute('href') || '', a.getAttribute('data-alt') || '');
                    return;
                }
                if(e.target === box || e.target === closeBtn){
                    closeBox();
                }
            });

            document.addEventListener('keydown', function(e){
                if(e.key === 'Escape') closeBox();
            });
        })();
    </script>
    <?php
})();

