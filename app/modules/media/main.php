<?php
declare(strict_types=1);
global $db, $auth;

/**
 * 1. IDENTITY & ADMIN CHECK
 */
$userName = "Guest";
$isAdmin = false;

if (isset($auth) && $auth->check()) {
    $userName = $auth->username() ?: "User #" . $auth->id();
    $isAdmin = $auth->is_admin();
}

/**
 * 2. AJAX HANDLER
 */
if (isset($_REQUEST['chaos_action'])) {
    if (ob_get_length()) ob_clean();
    $id = (int)($_REQUEST['id'] ?? 0);
    $action = $_REQUEST['chaos_action'];

    if ($id > 0) {
        if ($action === 'delete_comment' && $isAdmin) {
            $commentId = (int)($_POST['comment_id'] ?? 0);
            $db->exec("DELETE FROM media_social_comments WHERE id = $commentId AND media_id = $id");
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            die();
        }

        if ($action === 'sync') {
            $h = $db->fetch("SELECT COUNT(*) as total FROM media_social_reactions WHERE media_id = $id AND type = 'heart'");
            $f = $db->fetch("SELECT COUNT(*) as total FROM media_social_reactions WHERE media_id = $id AND type = 'fire'");
            $comments = $db->fetch_all("SELECT id, user_name, comment_body FROM media_social_comments WHERE media_id = $id ORDER BY id DESC");
            $html = "";
            if (is_array($comments)) {
                foreach ($comments as $c) {
                    $cId = (int)$c['id'];
                    $html .= "<div style='margin-bottom:12px; border-bottom:1px solid #222; padding-bottom:6px; position:relative;'>";
                    if ($isAdmin) {
                        $html .= "<span onclick='deleteComment($cId)' style='position:absolute; right:0; top:0; color:#ff4444; cursor:pointer; font-size:11px; background:#222; padding:2px 6px; border-radius:4px; border:1px solid #444;'>Delete</span>";
                    }
                    $html .= "<strong style='color:#a855f7;'>" . htmlspecialchars((string)($c['user_name'] ?? 'Guest')) . "</strong>";
                    $html .= "<div style='color:#ddd; font-size:0.85rem; margin-top:4px;'>" . nl2br(htmlspecialchars((string)($c['comment_body'] ?? ''))) . "</div></div>";
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['hearts' => (int)($h['total'] ?? 0), 'fires' => (int)($f['total'] ?? 0), 'comments_html' => $html ?: "No comments yet."]);
            die();
        }
        
        if ($action === 'comment') {
            $body = $db->escape($_POST['body'] ?? '');
            if(!empty($body)) $db->exec("INSERT INTO media_social_comments (media_id, user_name, comment_body) VALUES ($id, '".$db->escape($userName)."', '$body')");
            die();
        }

        if ($action === 'pulse') {
            $type = ($_POST['type'] ?? 'heart') === 'heart' ? 'heart' : 'fire';
            $db->exec("INSERT INTO media_social_reactions (media_id, type, ip_address) VALUES ($id, '$type', '".$_SERVER['REMOTE_ADDR']."')");
            die();
        }
    }
}

/**
 * 3. DATA & SORTING
 */
$sort = $_GET['sort'] ?? 'newest';
$orderBy = "g.id DESC";
if ($sort === 'hype') $orderBy = "(heart_count + fire_count) DESC";
elseif ($sort === 'chatty') $orderBy = "comment_count DESC";

$query = "SELECT g.id, f.rel_path, f.mime, g.title,
    (SELECT COUNT(*) FROM media_social_reactions WHERE media_id = g.id AND type = 'heart') as heart_count,
    (SELECT COUNT(*) FROM media_social_reactions WHERE media_id = g.id AND type = 'fire') as fire_count,
    (SELECT COUNT(*) FROM media_social_comments WHERE media_id = g.id) as comment_count
    FROM media_gallery g 
    INNER JOIN media_files f ON f.id = g.file_id 
    ORDER BY $orderBy";

$items = $db->fetch_all($query);
$initId = (int)($_GET['id'] ?? 0);
$initData = null;
if ($initId > 0) {
    $initData = $db->fetch("SELECT g.id, f.rel_path, f.mime, g.title FROM media_gallery g INNER JOIN media_files f ON f.id = g.file_id WHERE g.id = $initId LIMIT 1");
}
?>

<div style="padding: 20px; display: flex; justify-content: flex-end; gap: 10px; background: #000; border-bottom: 1px solid #222;">
    <select onchange="location.href='?sort='+this.value" style="background: #111; color: #fff; border: 1px solid #333; padding: 6px 12px; border-radius: 6px; outline: none;">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
        <option value="hype" <?= $sort === 'hype' ? 'selected' : '' ?>>Most Hype</option>
        <option value="chatty" <?= $sort === 'chatty' ? 'selected' : '' ?>>Most Discussed</option>
    </select>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:25px; padding:25px; background: #000;">
<?php if (is_array($items)): foreach ($items as $it): 
    $id = (int)$it['id'];
    $url = (strpos((string)$it['rel_path'], '/') === false) ? "/media/" . $it['rel_path'] : $it['rel_path'];
    $isVid = (strpos((string)$it['mime'], 'video') !== false);
    $cleanTitle = ucwords(str_replace(['_', '-'], ' ', !empty($it['title']) ? $it['title'] : pathinfo((string)$it['rel_path'], PATHINFO_FILENAME)));
?>
    <div style="background:#111; border-radius:12px; overflow:hidden; border:1px solid #333; cursor:pointer;" onclick="openChaosModal('<?= $isVid?'video':'image' ?>','<?= $url ?>','<?= addslashes($cleanTitle) ?>',<?= $id ?>);">
        <div style="aspect-ratio:1/1; background:#000; display:flex; align-items:center; justify-content:center; color:#fff; overflow:hidden;">
            <?php if ($isVid): ?>
                <div style="font-size:50px; color:#a855f7;">▶</div>
            <?php else: ?>
                <img src="<?= $url ?>" style="width:100%; height:100%; object-fit:cover;">
            <?php endif; ?>
        </div>
        <div style="padding:15px; border-top:1px solid #222;">
            <div style="color:#fff; font-weight:bold; font-size:14px; margin-bottom:10px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= $cleanTitle ?></div>
            <div style="display:flex; justify-content:space-between; font-size:0.85rem; color:#aaa;">
                <span>❤️ <b><?= $it['heart_count'] ?></b></span> <span>🔥 <b><?= $it['fire_count'] ?></b></span> <span>💬 <b><?= $it['comment_count'] ?></b></span>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>
</div>

<div id="chaosModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.97); z-index:9999; align-items:center; justify-content:center; padding:20px; flex-direction:column; overflow-y:auto;">
    <button onclick="closeChaosModal()" style="position:fixed; top:20px; right:20px; background:none; border:none; color:#fff; font-size:40px; cursor:pointer; z-index:10000;">&times;</button>
    <div style="width:100%; max-width:800px; margin:auto;">
        <div style="text-align:center; margin-bottom:20px;">
            <img id="modalImg" src="" style="max-width:100%; max-height:65vh; display:none; border-radius:12px;">
            <video id="modalVid" controls style="max-width:100%; max-height:65vh; display:none; border-radius:12px;"></video>
        </div>
        <div style="background:#111; padding:25px; border-radius:16px; border:1px solid #333;">
            <h2 id="modalTitle" style="color:#fff; margin:0 0 15px 0;"></h2>
            <div style="display:flex; gap:15px; margin-bottom:25px;">
                <button onclick="sendPulse('heart')" style="background:#9333ea; border:none; color:#fff; padding:10px 20px; border-radius:10px; cursor:pointer; font-weight:bold;">❤️ Like</button>
                <button onclick="sendPulse('fire')" style="background:#ea580c; border:none; color:#fff; padding:10px 20px; border-radius:10px; cursor:pointer; font-weight:bold;">🔥 Fire</button>
            </div>
            <div style="border-top:1px solid #222; padding-top:20px;">
                <div id="commentBox" style="max-height:250px; overflow-y:auto; margin-bottom:20px; background:#080808; padding:15px; border-radius:8px; border:1px solid #1a1a1a; color:#eee;"></div>
                <div style="display:flex; gap:12px;">
                    <input type="text" id="commentInput" placeholder="Comment as <?= htmlspecialchars($userName) ?>..." style="flex:1; background:#000; border:1px solid #333; color:#fff; padding:12px; border-radius:10px; outline:none;">
                    <button onclick="submitComment()" style="background:#fff; color:#000; border:none; padding:12px 25px; border-radius:10px; font-weight:bold; cursor:pointer;">Post</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let curId = 0;
function openChaosModal(kind, src, title, id) {
    curId = id;
    const newurl = window.location.origin + window.location.pathname + '?id=' + id;
    window.history.pushState({path:newurl},'',newurl);
    document.getElementById('modalTitle').innerText = title;
    const img = document.getElementById('modalImg'); const vid = document.getElementById('modalVid');
    img.style.display = 'none'; vid.style.display = 'none';
    if(kind === 'video') { vid.style.display = 'block'; vid.src = src; vid.play(); } else { img.style.display = 'block'; img.src = src; }
    document.getElementById('chaosModal').style.display = 'flex';
    syncSocial();
}
function closeChaosModal() {
    document.getElementById('chaosModal').style.display = 'none';
    document.getElementById('modalVid').pause();
    const cleanUrl = window.location.origin + window.location.pathname;
    window.history.pushState({path:cleanUrl},'',cleanUrl);
}
function syncSocial() {
    fetch(`?chaos_action=sync&id=${curId}&t=${Date.now()}`).then(r => r.json()).then(data => {
        document.getElementById('commentBox').innerHTML = data.comments_html;
    });
}
function deleteComment(cId) {
    if(!confirm('Delete this comment permanently?')) return;
    const fd = new FormData(); fd.append('comment_id', cId);
    fetch(`?chaos_action=delete_comment&id=${curId}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => { if(data.status === 'ok') syncSocial(); });
}
function sendPulse(type) {
    const fd = new FormData(); fd.append('type', type);
    fetch(`?chaos_action=pulse&id=${curId}`, { method: 'POST', body: fd }).then(() => syncSocial());
}
function submitComment() {
    const input = document.getElementById('commentInput'); if(!input.value.trim()) return;
    const fd = new FormData(); fd.append('body', input.value);
    fetch(`?chaos_action=comment&id=${curId}`, { method: 'POST', body: fd }).then(() => { input.value = ''; syncSocial(); });
}
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($initData): 
        $isVid = (strpos((string)$initData['mime'], 'video') !== false);
        $url = (strpos((string)$initData['rel_path'], '/') === false) ? "/media/" . $initData['rel_path'] : $initData['rel_path'];
        $cleanTitle = ucwords(str_replace(['_', '-'], ' ', !empty($initData['title']) ? $initData['title'] : pathinfo((string)$initData['rel_path'], PATHINFO_FILENAME)));
    ?>
        openChaosModal('<?= $isVid ? "video" : "image" ?>', '<?= $url ?>', '<?= addslashes($cleanTitle) ?>', <?= $initId ?>);
    <?php endif; ?>
});
</script>
