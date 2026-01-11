<?php

declare(strict_types=1);

/**
 * Chaos CMS Admin: Audit
 * Route:
 *   /admin?action=audit
 *
 * Purpose:
 * - Accountability surface for soft-deletes and visibility hides.
 * - Admin-only (gated by /app/admin/index.php).
 *
 * Conventions:
 * - posts.status: 1=active, 0=hidden/removed
 * - posts.visibility: 0=public, 1=unlisted, 2=members
 * - media_gallery.status: 1=published, 0=unpublished
 * - media_gallery.visibility: 0=public, 2=members
 * - post_replies.status: 1=active, 0=removed
 */

global $db;

echo '<div class="admin-wrap">';

if (!isset($db) || !$db instanceof db) {
    echo '<div class="container my-4">';
    echo '<small><a href="/admin">Admin</a> &raquo; Audit</small>';
    echo '<div class="alert alert-danger mt-2">DB not available.</div>';
    echo '</div></div>';
    return;
}

$conn = $db->connect();
if ($conn === false) {
    echo '<div class="container my-4">';
    echo '<small><a href="/admin">Admin</a> &raquo; Audit</small>';
    echo '<div class="alert alert-danger mt-2">DB connection failed.</div>';
    echo '</div></div>';
    return;
}

$e = static function (string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
};

$crumb = '<small><a href="/admin">Admin</a> &raquo; Audit</small>';

$csrf_ok = static function (): bool {
    if (!function_exists('csrf_ok')) {
        return true;
    }
    $token = (string) ($_POST['csrf'] ?? '');
    return csrf_ok($token);
};

$csrf_field = static function () use ($e): string {
    if (!function_exists('csrf_token')) {
        return '';
    }
    return '<input type="hidden" name="csrf" value="' . $e((string) csrf_token()) . '">';
};

// -----------------------------------------------------------------------------
// POST actions (Replies only): restore / purge
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$csrf_ok()) {
        echo '<div class="container my-4">';
        echo $crumb;
        echo '<div class="alert alert-danger mt-2">Bad CSRF.</div>';
        echo '</div></div>';
        return;
    }

    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        // Restore reply (status=1)
        if ($do === 'reply_restore') {
            $stmt = $conn->prepare("UPDATE post_replies SET status=1, updated_at=UTC_TIMESTAMP() WHERE id=? LIMIT 1");
            if ($stmt !== false) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            header('Location: /admin?action=audit#replies');
            exit;
        }

        // Purge reply (permanent delete)
        if ($do === 'reply_purge') {
            $stmt = $conn->prepare("DELETE FROM post_replies WHERE id=? LIMIT 1");
            if ($stmt !== false) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            header('Location: /admin?action=audit#replies');
            exit;
        }
    }

    header('Location: /admin?action=audit#replies');
    exit;
}

// ---------------------------
// Fetch flagged posts
// ---------------------------
$posts = [];

$sqlPosts = "
    SELECT
        id,
        slug,
        title,
        status,
        visibility,
        created_at,
        updated_at
    FROM posts
    WHERE status=0 OR visibility IN (1,2)
    ORDER BY COALESCE(updated_at, created_at) DESC
    LIMIT 200
";

$res = $conn->query($sqlPosts);
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        if (is_array($row)) {
            $posts[] = $row;
        }
    }
    $res->close();
}

// ---------------------------
// Fetch flagged media
// ---------------------------
$media = [];

$sqlMedia = "
    SELECT
        f.id,
        f.filename,
        f.rel_path,
        f.mime,
        f.size_bytes,
        f.created_at,
        COALESCE(g.title,'') AS title,
        COALESCE(g.visibility,0) AS visibility,
        COALESCE(g.status,1) AS status,
        COALESCE(g.created_at, f.created_at) AS gallery_created
    FROM media_files f
    LEFT JOIN media_gallery g ON g.file_id=f.id
    WHERE COALESCE(g.status,1)=0 OR COALESCE(g.visibility,0) IN (2)
    ORDER BY f.id DESC
    LIMIT 200
";

$res = $conn->query($sqlMedia);
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        if (is_array($row)) {
            $media[] = $row;
        }
    }
    $res->close();
}

// ---------------------------
// Fetch removed replies
// ---------------------------
$replies = [];

$sqlReplies = "
    SELECT
        r.id,
        r.post_id,
        r.author_id,
        r.status,
        r.visibility,
        r.created_at,
        r.updated_at,
        LEFT(r.body, 140) AS snippet,
        p.slug AS post_slug,
        p.title AS post_title,
        u.username AS author_username
    FROM post_replies r
    LEFT JOIN posts p ON p.id=r.post_id
    LEFT JOIN users u ON u.id=r.author_id
    WHERE r.status=0
    ORDER BY COALESCE(r.updated_at, r.created_at) DESC
    LIMIT 200
";

$res = $conn->query($sqlReplies);
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        if (is_array($row)) {
            $replies[] = $row;
        }
    }
    $res->close();
}

$postsCount   = count($posts);
$mediaCount   = count($media);
$repliesCount = count($replies);

$visText = static function (int $v): string {
    if ($v === 2) {
        return 'Members';
    }
    if ($v === 1) {
        return 'Unlisted';
    }
    return 'Public';
};

echo '<div class="container my-4 admin-audit">';
echo $crumb;

echo '<div class="d-flex align-items-center justify-content-between mt-2 mb-2">';
echo '<h1 class="h3 m-0">Audit</h1>';
echo '<div class="small text-muted">Soft deletes & visibility flags</div>';
echo '</div>';

echo '<div class="row g-2 mb-3">';
echo '  <div class="col-12 col-md-4"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small">Flagged Posts</div>';
echo '    <div class="admin-kpi"><span class="num">' . $postsCount . '</span><span class="lbl">items</span></div>';
echo '  </div></div></div>';

echo '  <div class="col-12 col-md-4"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small">Flagged Media</div>';
echo '    <div class="admin-kpi"><span class="num">' . $mediaCount . '</span><span class="lbl">items</span></div>';
echo '  </div></div></div>';

echo '  <div class="col-12 col-md-4"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small">Removed Replies</div>';
echo '    <div class="admin-kpi"><span class="num">' . $repliesCount . '</span><span class="lbl">items</span></div>';
echo '  </div></div></div>';
echo '</div>';

// POSTS
echo '<h2 class="h5 mt-4">Posts</h2>';
echo '<div class="text-muted small mb-2">Shows <code>status=0</code> or <code>visibility</code> in <code>(unlisted,members)</code>.</div>';

if (empty($posts)) {
    echo '<div class="alert small">No flagged posts.</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Title</th><th>Status</th><th>Visibility</th><th>Updated</th><th></th>';
    echo '</tr></thead><tbody>';

    foreach ($posts as $p) {
        $id   = (int) ($p['id'] ?? 0);
        $slug = (string) ($p['slug'] ?? '');
        $ttl  = (string) ($p['title'] ?? '');
        $st   = (int) ($p['status'] ?? 0);
        $vis  = (int) ($p['visibility'] ?? 0);
        $upd  = (string) ($p['updated_at'] ?? $p['created_at'] ?? '');

        $stTxt = ($st === 1) ? 'Active' : 'Hidden';
        $vTxt  = $visText($vis);

        echo '<tr>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . $e($ttl) . '</td>';
        echo '<td>' . $e($stTxt) . '</td>';
        echo '<td>' . $e($vTxt) . '</td>';
        echo '<td>' . $e($upd) . '</td>';
        echo '<td>';
        if ($slug !== '') {
            echo '<a class="btn btn-sm" href="/posts/' . $e($slug) . '" target="_blank">Open</a>';
            echo ' <a class="btn btn-sm" href="/admin?action=posts&edit=' . $id . '">Admin</a>';
        } else {
            echo '<span class="text-muted small">n/a</span>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

// MEDIA
echo '<h2 class="h5 mt-4">Media</h2>';
echo '<div class="text-muted small mb-2">Shows <code>media_gallery.status=0</code> (unpublished) or <code>visibility=members</code>.</div>';

if (empty($media)) {
    echo '<div class="alert small">No flagged media.</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>File</th><th>Type</th><th>Status</th><th>Visibility</th><th>When</th><th></th>';
    echo '</tr></thead><tbody>';

    foreach ($media as $m) {
        $id   = (int) ($m['id'] ?? 0);
        $fn   = (string) ($m['filename'] ?? '');
        $rel  = (string) ($m['rel_path'] ?? '');
        $mime = (string) ($m['mime'] ?? '');
        $st   = (int) ($m['status'] ?? 1);
        $vis  = (int) ($m['visibility'] ?? 0);
        $when = (string) ($m['gallery_created'] ?? $m['created_at'] ?? '');

        $stTxt = ($st === 1) ? 'Published' : 'Unpublished';
        $vTxt  = ($vis === 2) ? 'Members' : 'Public';

        echo '<tr>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . $e($fn) . '</td>';
        echo '<td>' . $e($mime) . '</td>';
        echo '<td>' . $e($stTxt) . '</td>';
        echo '<td>' . $e($vTxt) . '</td>';
        echo '<td>' . $e($when) . '</td>';
        echo '<td>';

        if ($rel !== '') {
            echo '<a class="btn btn-sm" href="' . $e($rel) . '" target="_blank">Open</a>';
        } else {
            echo '<span class="text-muted small">n/a</span>';
        }

        echo ' <a class="btn btn-sm" href="/admin?action=media">Admin</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

// REPLIES
echo '<h2 class="h5 mt-4" id="replies">Replies</h2>';
echo '<div class="text-muted small mb-2">Removed replies (status=0). Admin can restore or purge.</div>';

if (empty($replies)) {
    echo '<div class="alert small">No removed replies.</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Post</th><th>Author</th><th>When</th><th>Snippet</th><th></th>';
    echo '</tr></thead><tbody>';

    foreach ($replies as $r) {
        $id = (int)($r['id'] ?? 0);
        $slug = (string)($r['post_slug'] ?? '');
        $pt = (string)($r['post_title'] ?? '');
        $au = (string)($r['author_username'] ?? '');
        $when = (string)($r['updated_at'] ?? $r['created_at'] ?? '');
        $snip = (string)($r['snippet'] ?? '');

        echo '<tr>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . ($pt !== '' ? $e($pt) : '<span class="text-muted small">(missing)</span>') . '</td>';
        echo '<td>' . $e($au !== '' ? $au : 'n/a') . '</td>';
        echo '<td>' . $e($when) . '</td>';
        echo '<td>' . $e($snip) . '</td>';
        echo '<td>';

        if ($slug !== '') {
            echo '<a class="btn btn-sm" href="/posts/' . $e($slug) . '" target="_blank">Open</a> ';
        }

        // Restore
        echo '<form method="post" action="/admin?action=audit" style="display:inline;">';
        echo $csrf_field();
        echo '<input type="hidden" name="do" value="reply_restore">';
        echo '<input type="hidden" name="id" value="' . (int)$id . '">';
        echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Restore</button>';
        echo '</form> ';

        // Purge
        echo '<form method="post" action="/admin?action=audit" style="display:inline;" onsubmit="return confirm(\'Permanently delete this reply? This cannot be undone.\');">';
        echo $csrf_field();
        echo '<input type="hidden" name="do" value="reply_purge">';
        echo '<input type="hidden" name="id" value="' . (int)$id . '">';
        echo '<button type="submit" class="btn btn-sm btn-outline-danger">Purge</button>';
        echo '</form>';

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

echo '</div>';

echo '</div>';

