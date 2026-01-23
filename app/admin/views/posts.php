<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

(function (): void {
    global $db, $auth;
    $conn = $db->connect();

    // --- 1. SELF-HEALING MIGRATION ---
    // This ensures ChaosCMS gets the columns you built on Poemei
    $checkCols = mysqli_query($conn, "SHOW COLUMNS FROM posts");
    $existing = [];
    while($c = mysqli_fetch_assoc($checkCols)) { $existing[] = $c['Field']; }

    if (!in_array('isPremium', $existing)) {
        mysqli_query($conn, "ALTER TABLE posts ADD COLUMN visibility INT(11) DEFAULT 0 AFTER status");
        mysqli_query($conn, "ALTER TABLE posts ADD COLUMN isPremium INT(11) DEFAULT 0 AFTER visibility");
        mysqli_query($conn, "ALTER TABLE posts ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00 AFTER isPremium");
        mysqli_query($conn, "ALTER TABLE posts ADD COLUMN tier_required VARCHAR(50) DEFAULT 'free' AFTER price");
    }
    if (!in_array('excerpt', $existing)) {
        mysqli_query($conn, "ALTER TABLE posts ADD COLUMN excerpt TEXT AFTER title");
    }

    // --- 2. DELETE HANDLER ---
    if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
        $delId = (int)$_GET['delete'];
        mysqli_query($conn, "DELETE FROM posts WHERE id = $delId LIMIT 1");
        header('Location: /admin?action=posts');
        exit;
    }

    $do = (string)($_GET['do'] ?? '');
    $id = (int)($_GET['id'] ?? 0);

    // --- 3. SAVE LOGIC ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postId     = (int)($_POST['id'] ?? 0);
        $title      = trim((string)($_POST['title'] ?? ''));
        $slug       = trim((string)($_POST['slug'] ?? ''));
        $body       = (string)($_POST['body'] ?? '');
        $excerpt    = trim((string)($_POST['excerpt'] ?? ''));
        $status     = (int)($_POST['status'] ?? 0);
        $visibility = (int)($_POST['visibility'] ?? 0);
        $isPremium  = (int)($_POST['is_premium'] ?? 0); 
        $price      = (float)($_POST['price'] ?? 0.00);
        $tier       = trim((string)($_POST['tier_required'] ?? 'free'));

        $eSlug    = mysqli_real_escape_string($conn, $slug ?: strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
        $eTitle   = mysqli_real_escape_string($conn, $title);
        $eBody    = mysqli_real_escape_string($conn, $body);
        $eExcerpt = mysqli_real_escape_string($conn, $excerpt);
        $eTier    = mysqli_real_escape_string($conn, $tier);

        if ($postId > 0) {
            $sql = "UPDATE posts SET 
                slug='$eSlug', title='$eTitle', body='$eBody', excerpt='$eExcerpt', 
                status=$status, visibility=$visibility, isPremium=$isPremium, 
                price=$price, tier_required='$eTier', updated_at=UTC_TIMESTAMP() 
                WHERE id=$postId";
        } else {
            $sql = "INSERT INTO posts (
                slug, title, body, excerpt, status, visibility, 
                isPremium, price, tier_required, created_at
            ) VALUES (
                '$eSlug', '$eTitle', '$eBody', '$eExcerpt', $status, $visibility, 
                $isPremium, $price, '$eTier', UTC_TIMESTAMP()
            )";
        }

        if (mysqli_query($conn, $sql)) {
            header('Location: /admin?action=posts');
            exit;
        } else {
            die("DATABASE ERROR: " . mysqli_error($conn));
        }
    }

    // Load Data
    $post = ['id'=>0,'title'=>'','slug'=>'','body'=>'','excerpt'=>'','status'=>0,'visibility'=>0,'isPremium'=>0,'price'=>'0.00','tier_required'=>'free'];
    if ($do === 'edit' && $id > 0) {
        $res = mysqli_query($conn, "SELECT * FROM posts WHERE id = $id LIMIT 1");
        if ($row = mysqli_fetch_assoc($res)) { $post = $row; }
    }
?>

<style>
    .admin-wrap { max-width: 1000px; margin: 0 auto; padding: 20px; font-family: sans-serif; }
    .admin-card { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .admin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .admin-input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
    .btn { padding: 10px 20px; border-radius: 5px; cursor: pointer; border: none; font-weight: bold; text-decoration: none; display: inline-block; }
    .btn-save { background: #000; color: #fff; }
    .btn-cancel { background: #eee; color: #333; }
</style>

<div class="admin-wrap">
    <?php if ($do === 'edit' || $do === 'new'): ?>
        <form method="post" class="admin-card">
            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
            
            <div class="admin-grid">
                <div>
                    <label style="display:block; font-size:12px; font-weight:bold;">TITLE</label>
                    <input type="text" name="title" class="admin-input" value="<?= htmlspecialchars((string)($post['title'] ?? '')) ?>" required>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:bold;">SLUG</label>
                    <input type="text" name="slug" class="admin-input" value="<?= htmlspecialchars((string)($post['slug'] ?? '')) ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:bold;">STATUS</label>
                    <select name="status" class="admin-input">
                        <option value="0" <?= ($post['status'] ?? 0) == 0 ? 'selected' : '' ?>>Draft</option>
                        <option value="1" <?= ($post['status'] ?? 0) == 1 ? 'selected' : '' ?>>Published</option>
                    </select>
                </div>
            </div>

            <div style="background:#f4f4f4; padding:15px; border-radius:8px; margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;">Monetization & Visibility</h4>
                <div class="admin-grid">
                    <div>
                        <label style="display:block; font-size:11px;">VISIBILITY</label>
                        <select name="visibility" class="admin-input">
                            <option value="0" <?= ($post['visibility'] ?? 0) == 0 ? 'selected' : '' ?>>Public</option>
                            <option value="1" <?= ($post['visibility'] ?? 0) == 1 ? 'selected' : '' ?>>Members Only</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:11px;">PREMIUM?</label>
                        <select name="is_premium" class="admin-input">
                            <option value="0" <?= ($post['isPremium'] ?? 0) == 0 ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= ($post['isPremium'] ?? 0) == 1 ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:11px;">PRICE</label>
                        <input type="number" step="0.01" name="price" class="admin-input" value="<?= htmlspecialchars((string)($post['price'] ?? '0.00')) ?>">
                    </div>
                    <div>
                        <label style="display:block; font-size:11px;">TIER</label>
                        <select name="tier_required" class="admin-input">
                            <?php $t = $post['tier_required'] ?? 'free'; ?>
                            <option value="free" <?= $t === 'free' ? 'selected' : '' ?>>Free</option>
                            <option value="premium" <?= $t === 'premium' ? 'selected' : '' ?>>Premium</option>
                            <option value="pro" <?= $t === 'pro' ? 'selected' : '' ?>>Pro</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:12px; font-weight:bold;">EXCERPT</label>
                <textarea name="excerpt" class="admin-input" rows="2"><?= htmlspecialchars((string)($post['excerpt'] ?? '')) ?></textarea>
            </div>

            <div>
                <label style="display:block; font-size:12px; font-weight:bold;">CONTENT</label>
                <textarea name="body" class="admin-input" rows="15" style="font-family:monospace;"><?= htmlspecialchars((string)($post['body'] ?? '')) ?></textarea>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn btn-save">SAVE POST</button>
                <a href="/admin?action=posts" class="btn btn-cancel">CANCEL</a>
            </div>
        </form>
    <?php else: ?>
        <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
            <h2>Posts</h2>
            <a href="/admin?action=posts&do=new" class="btn btn-save">NEW POST</a>
        </div>
        <div class="admin-card">
            <table style="width:100%; border-collapse: collapse;">
                <tr style="text-align:left; border-bottom:1px solid #eee;">
                    <th style="padding:10px;">ID</th>
                    <th>Title</th>
                    <th style="text-align:right; padding:10px;">Actions</th>
                </tr>
                <?php 
                $res = mysqli_query($conn, "SELECT id, title FROM posts ORDER BY id DESC");
                while($p = mysqli_fetch_assoc($res)): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><?= $p['id'] ?></td>
                    <td><?= htmlspecialchars((string)($p['title'] ?? '')) ?></td>
                    <td style="text-align:right; padding:10px;">
                        <a href="/admin?action=posts&do=edit&id=<?= $p['id'] ?>">Edit</a> | 
                        <a href="/admin?action=posts&delete=<?= $p['id'] ?>" style="color:red;" onclick="return confirm('Delete?')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php })(); ?>
