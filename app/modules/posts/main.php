<?php

/**
 * Chaos CMS DB — Admin: Posts
 *
 * Route:
 *   /admin?action=posts
 *
 * Monetization-aware version
 */

require_once __DIR__ . '/../../core/init.php';

if (!isset($db) || !$db instanceof db) {
    echo '<div class="alert alert-danger">Database unavailable.</div>';
    return;
}

$conn = $db->connect();
if (!$conn) {
    echo '<div class="alert alert-danger">Database connection failed.</div>';
    return;
}

$h = static fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$rows = [];
$res = $conn->query(
    "SELECT p.id, p.slug, p.title, p.status, p.visibility, p.author_id, u.username AS author_name,
            p.created_at, p.published_at
     FROM posts p
     LEFT JOIN users u ON u.id = p.author_id
     ORDER BY p.created_at DESC
     LIMIT 100"
);
if ($res instanceof mysqli_result) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $res->close();
}

?>
<div class="container my-4 admin-posts">
    <h1 class="h3">Posts</h1>
    <p class="text-muted">Manage all posts</p>

    <?php if (empty($rows)): ?>
        <div class="alert">No posts found.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Slug</th>
                        <th>Status</th>
                        <th>Visibility</th>
                        <th>Creator</th>
                        <th>Created</th>
                        <th>Published</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= $h($r['title']) ?></td>
                            <td><?= $h($r['slug']) ?></td>
                            <td><?= ((int)$r['status'] === 1 ? 'Published' : 'Draft') ?></td>
                            <td>
                                <?php
                                    $v = (int)$r['visibility'];
                                    echo ['Public', 'Unlisted', 'Members', 'Premium'][$v] ?? 'Unknown';
                                ?>
                            </td>
                            <td><?= $h($r['author_name'] ?? 'Unknown') ?></td>
                            <td><?= $h($r['created_at']) ?></td>
                            <td><?= $h($r['published_at']) ?></td>
                            <td>
                                <a href="/admin?action=edit_post&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="/admin?action=delete_post&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this post?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

