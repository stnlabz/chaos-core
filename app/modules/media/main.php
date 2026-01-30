<?php
declare(strict_types=1);

// 1. THE RECOVERY GATE: Wipes CMS headers and forces JSON
if (isset($_REQUEST['chaos_action'])) {
    global $db, $md;
    
    // Clear any HTML the router might have already started (the <head> tag)
    if (ob_get_length()) ob_clean(); 

    $id = (int)($_REQUEST['id'] ?? 0);
    $action = $_REQUEST['chaos_action'];

    if ($id > 0) {
        if ($action === 'sync') {
            $h = $db->fetch("SELECT COUNT(*) as total FROM media_social_reactions WHERE media_id = $id AND type = 'heart'");
            $f = $db->fetch("SELECT COUNT(*) as total FROM media_social_reactions WHERE media_id = $id AND type = 'fire'");
            $comments = $db->fetch_all("SELECT user_name, comment_body FROM media_social_comments WHERE media_id = $id ORDER BY id DESC");
            
            $html = "";
            foreach ($comments as $c) {
                $body = (isset($md)) ? $md->markdown($c['comment_body']) : nl2br(htmlspecialchars($c['comment_body']));
                $html .= "<div style='margin-bottom:12px; border-bottom:1px solid #222; padding-bottom:8px;'>";
                $html .= "<strong style='color:#007bff;'>".htmlspecialchars((string)$c['user_name'])."</strong>";
                $html .= "<div style='color:#ccc; font-size:0.85rem; margin-top:3px;'>$body</div></div>";
            }

            header('Content-Type: application/json');
            echo json_encode([
                'hearts' => (int)($h['total'] ?? 0),
                'fires' => (int)($f['total'] ?? 0),
                'comments_html' => $html ?: "<div style='color:#444;'>No comments yet...</div>"
            ]);
            exit; // Stop everything here so NO HTML is sent
        }

        if ($action === 'pulse') {
            $type = $_POST['type'] === 'heart' ? 'heart' : 'fire';
            $ip = $db->escape($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $db->exec("INSERT INTO media_social_reactions (media_id, type, ip_address) VALUES ($id, '$type', '$ip')");
            exit;
        }

        if ($action === 'comment') {
            $name = $db->escape($_POST['name'] ?: 'Guest');
            $body = $db->escape($_POST['body'] ?? '');
            if (!empty($body)) {
                $db->exec("INSERT INTO media_social_comments (media_id, user_name, comment_body) VALUES ($id, '$name', '$body')");
            }
            exit;
        }
    }
}

// 2. NORMAL PAGE RENDER (Everything below here only runs on a standard page load)
(function (): void {
    global $db;

    $sql = "SELECT g.id, f.kind, f.rel_path, g.title, g.caption,
                (SELECT COUNT(*) FROM media_social_reactions WHERE media_id = g.id AND type = 'heart') as h_count,
                (SELECT COUNT(*) FROM media_social_reactions WHERE media_id = g.id AND type = 'fire') as f_count,
                (SELECT COUNT(*) FROM media_social_comments WHERE media_id = g.id) as c_count
            FROM media_gallery g
            INNER JOIN media_files f ON g.file_id = f.id 
            WHERE g.status = 1 ORDER BY g.sort_order ASC, g.id DESC";

    $media = $db->fetch_all($sql);

    echo '<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:20px; padding:20px;">';
    foreach ($media as $item) {
        $id = (int)$item['id'];
        echo '<div style="background:#111; border-radius:10px; overflow:hidden; border:1px solid #222;">';
            echo '<a href="javascript:void(0)" onclick="openChaosModal(\''.addslashes((string)$item['kind']).'\',\''.addslashes((string)$item['rel_path']).'\',\''.addslashes((string)$item['title']).'\',\''.addslashes((string)$item['caption']).'\','.$id.');">';
                echo '<img src="'.$item['rel_path'].'" style="width:100%; aspect-ratio:1/1; object-fit:cover; display:block;">';
            echo '</a>';
            echo '<div style="padding:10px; display:flex; justify-content:space-around; font-size:0.8rem; color:#888; background:#050505;">';
                echo '<span>❤️ <b id="grid-h-'.$id.'">'.$item['h_count'].'</b></span>';
                echo '<span>🔥 <b id="grid-f-'.$id.'">'.$item['f_count'].'</b></span>';
                echo '<span>💬 <b id="grid-c-'.$id.'">'.$item['c_count'].'</b></span>';
            echo '</div>';
        echo '</div>';
    }
    echo '</div>';
?>

<div id="mediaModal" style="display:none; position:fixed; z-index:100000; inset:0; background:rgba(0,0,0,0.95); overflow-y:auto; backdrop-filter:blur(8px);">
    <div onclick="closeChaosModal()" style="position:fixed; top:20px; right:40px; color:#fff; font-size:50px; cursor:pointer; z-index:200001;">&times;</div>
    <div style="max-width:1100px; margin: 50px auto; background:#000; display:flex; flex-wrap:wrap; border:1px solid #333; border-radius:15px; overflow:hidden;">
        <div id="mediaModalBox" style="flex:2; min-width:320px; min-height:500px; display:flex; align-items:center; justify-content:center; background:#000;"></div>
        <div style="flex:1; min-width:320px; padding:25px; color:#fff; border-left:1px solid #222; display:flex; flex-direction:column; background:#050505;">
            <h3 id="mTitle" style="margin-top:0;"></h3>
            <p id="mCap" style="color:#777; font-size:0.9rem;"></p>
            <div style="padding:15px 0; border-top:1px solid #222; display:flex; gap:10px;">
                <button onclick="chaosPulse('heart')" style="background:#111; border:1px solid #333; color:#fff; padding:10px; cursor:pointer; flex:1; border-radius:5px;">❤️ <span id="hCount">0</span></button>
                <button onclick="chaosPulse('fire')" style="background:#111; border:1px solid #333; color:#fff; padding:10px; cursor:pointer; flex:1; border-radius:5px;">🔥 <span id="fCount">0</span></button>
            </div>
            <div id="chaosFeed" style="flex-grow:1; overflow-y:auto; max-height:300px; margin:15px 0;"></div>
            <div style="border-top:1px solid #222; padding-top:15px;">
                <input type="text" id="cUser" placeholder="Name" style="width:100%; background:#111; border:1px solid #333; color:#fff; padding:10px; margin-bottom:8px;">
                <textarea id="cMsg" placeholder="Comment..." style="width:100%; background:#111; border:1px solid #333; color:#fff; padding:10px; height:60px; resize:none;"></textarea>
                <button onclick="chaosComment()" style="width:100%; background:#007bff; border:none; color:#fff; padding:12px; cursor:pointer; font-weight:bold; border-radius:5px;">Post</button>
            </div>
        </div>
    </div>
</div>

<script>
    let activeID = null;
    const endpoint = window.location.origin + window.location.pathname;

    function openChaosModal(kind, src, title, cap, id) {
        activeID = id;
        document.getElementById('mediaModal').style.display = "block";
        document.getElementById('mTitle').innerText = title;
        document.getElementById('mCap').innerText = cap;
        const box = document.getElementById('mediaModalBox');
        box.innerHTML = (kind === 'video') ? `<video src="${src}" controls autoplay style="max-width:100%; max-height:85vh;"></video>` : `<img src="${src}" style="max-width:100%; max-height:85vh; object-fit:contain;">`;

        syncModalData(id);
    }

    function syncModalData(id) {
        fetch(`${endpoint}?chaos_action=sync&id=${id}`)
            .then(r => r.json())
            .then(data => {
                document.getElementById('hCount').innerText = data.hearts;
                document.getElementById('fCount').innerText = data.fires;
                document.getElementById('chaosFeed').innerHTML = data.comments_html;
            })
            .catch(err => console.error("JSON Error. The server returned HTML instead of data. This usually means the PHP 'exit' was ignored. Check the 'Network' tab in F12.", err));
    }

    function closeChaosModal() { document.getElementById('mediaModal').style.display = "none"; }

    function chaosPulse(type) {
        fetch(`${endpoint}?chaos_action=pulse&id=${activeID}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `type=${type}`
        }).then(() => { syncModalData(activeID); });
    }

    function chaosComment() {
        const name = document.getElementById('cUser').value;
        const msg = document.getElementById('cMsg').value;
        if(!msg) return;
        fetch(`${endpoint}?chaos_action=comment&id=${activeID}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `name=${encodeURIComponent(name)}&body=${encodeURIComponent(msg)}`
        }).then(() => {
            syncModalData(activeID);
            document.getElementById('cMsg').value = '';
        });
    }
</script>
<?php })(); ?>
