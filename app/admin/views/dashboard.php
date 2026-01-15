<?php

declare(strict_types=1);

/**
 * Chaos CMS Admin: Dashboard
 *
 * Notes:
 * - Must be wrapped in .admin-wrap so admin.css scoped layout applies.
 * - No inline CSS. No CSS variables belong in this file.
 * - Update block is ADMIN-only and stays on the bottom row next to Registry.
 */

global $db, $auth;

echo '<div class="admin-wrap admin-dash">';

if (!isset($db) || !$db instanceof db) {
    echo '<div class="container my-4">';
    echo '<div class="alert alert-danger">DB not available.</div>';
    echo '</div></div>';
    return;
}

$docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

/**
 * Return the first column of a COUNT(*) query as an int.
 */
$countSql = static function (string $sql) use ($db): int {
    $row = $db->fetch($sql);

    if (!is_array($row)) {
        return 0;
    }

    $val = array_values($row)[0] ?? 0;

    return (int) $val;
};

/**
 * Resolve current role_id.
 * Prefers session auth payload; falls back to users table via $auth->id().
 */
$getRoleId = static function () use ($db, $auth): int {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (!empty($_SESSION['auth']) && is_array($_SESSION['auth'])) {
        $rid = (int) ($_SESSION['auth']['role_id'] ?? 0);
        if ($rid > 0) {
            return $rid;
        }
    }

    if (isset($auth) && $auth instanceof auth && method_exists($auth, 'id')) {
        try {
            $uid = $auth->id();
            if (is_int($uid) && $uid > 0) {
                $row = $db->fetch('SELECT role_id FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
                if (is_array($row)) {
                    return (int) ($row['role_id'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            return 0;
        }
    }

    return 0;
};

$roleId  = $getRoleId();
$isAdmin = ($roleId === 4);
$isEdit  = ($roleId === 2);
$isCreate = ($roleId === 5);

// Counts
$pagesTotal     = $countSql('SELECT COUNT(*) FROM pages');
$pagesPublished = $countSql('SELECT COUNT(*) FROM pages WHERE status=1');
$pagesDrafts    = $countSql('SELECT COUNT(*) FROM pages WHERE status=0');

$posts   = $countSql('SELECT COUNT(*) FROM posts');
$media   = $countSql('SELECT COUNT(*) FROM media_files');
$users   = $countSql('SELECT COUNT(*) FROM users');
$modules = $countSql('SELECT COUNT(*) FROM modules WHERE installed=1 AND enabled=1');
$plugins = $countSql('SELECT COUNT(*) FROM plugins WHERE installed=1 AND enabled=1');
$themes  = $countSql('SELECT COUNT(*) FROM themes WHERE installed=1 AND enabled=1');

// Version check (ADMIN-only display)
$localVersion = 'unknown';
$statusText   = 'Unknown';
$updateLabel  = 'Open Update';

$localPath = $docroot . '/app/data/version.json';

if (is_file($localPath)) {
    $json = json_decode((string) @file_get_contents($localPath), true);

    if (is_array($json) && isset($json['version'])) {
        $localVersion = (string) $json['version'];
    }
}

$remoteVersion = 'unknown';
$remoteUrl = 'https://version.chaoscms.org/db/version.json';
$ctx = stream_context_create([
    'http' => [
        'timeout'    => 2,
        'user_agent' => 'ChaosCMS/2.x',
    ],
]);

$remoteRaw = @file_get_contents($remoteUrl, false, $ctx);

if (is_string($remoteRaw) && $remoteRaw !== '') {
    $rj = json_decode($remoteRaw, true);

    if (is_array($rj) && isset($rj['version'])) {
        $remoteVersion = (string) $rj['version'];
    }
}

if ($localVersion !== 'unknown' && $remoteVersion !== 'unknown') {
    $needsUpdate = version_compare($localVersion, $remoteVersion, '<');
    $statusText  = $needsUpdate ? 'Update Available' : 'Up to date';
    $updateLabel = $needsUpdate ? 'Run Update' : 'Open Update';
} elseif ($localVersion !== 'unknown') {
    // We at least know local; still show a sane status
    $statusText  = 'Up to date';
    $updateLabel = 'Open Update';
}

// -----------------------------------------------------------------------------
// Render
// -----------------------------------------------------------------------------

echo '<div class="container my-4">';
echo '  <div class="text-muted small">Admin &raquo; Dashboard</div>';
echo '  <h1 class="admin-title mt-2">Dashboard</h1>';
echo '  <div class="admin-subtitle">Administrator tools.</div>';

// Tiles / links (role-gated)
$tiles = [];

// Editors + Creators: Posts + Media only
if ($isEdit || $isCreate) {
    $tiles[] = ['Posts', 'Write &amp; publish', '/admin?action=posts'];
    $tiles[] = ['Media', 'Uploads &amp; gallery', '/admin?action=media'];
}

// Admin: everything
if ($isAdmin) {
    $tiles = [
        ['Posts', 'Write &amp; publish', '/admin?action=posts'],
        ['Media', 'Uploads &amp; gallery', '/admin?action=media'],
        ['Pages', 'Data-driven pages', '/admin?action=pages'],
        ['Users', 'Accounts &amp; roles', '/admin?action=users'],
        ['Roles', 'User roles', '/admin?action=roles'],
        ['Settings', 'Site configuration', '/admin?action=settings'],
        ['Themes', 'Enable &amp; switch', '/admin?action=themes'],
        ['Modules', 'Install &amp; manage', '/admin?action=modules'],
        ['Plugins', 'Install &amp; manage', '/admin?action=plugins'],
        ['Maintenance', 'System &amp; SEO', '/admin?action=maintenance'],
        ['Health', 'System reporting', '/admin?action=health'],
        ['Audit', 'Admin review queue', '/admin?action=audit'],
        ['Finance', 'Site Financial Ledger', '/admin?action=finance'],
        ['Update', 'Version &amp; update', '/admin?action=update'],
    ];
}

if (!empty($tiles)) {
    echo '<div class="row">';
    foreach ($tiles as $t) {
        $label = (string) ($t[0] ?? '');
        $desc  = (string) ($t[1] ?? '');
        $href  = (string) ($t[2] ?? '#');

        echo '<div class="col-12 col-md-6 col-lg-3">';
        echo '  <div class="card"><div class="card-body">';
        echo '    <div class="fw-semibold"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong></div>';
        echo '    <div class="text-muted small mb-2">' . $desc . '</div>';
        echo '    <a class="btn btn-sm" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Open</a>';
        echo '  </div></div>';
        echo '</div>';
    }
    echo '</div>';
}

echo '<hr>';

// KPIs (everyone who can access /admin)
echo '<div class="row">';
echo '  <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small mb-1">Pages</div>';
echo '    <div class="admin-kpi"><span class="num">' . $pagesTotal . '</span><span class="lbl">total</span></div>';
echo '    <div class="text-muted small">Published: ' . $pagesPublished . ' &nbsp;|&nbsp; Drafts: ' . $pagesDrafts . '</div>';
echo '  </div></div></div>';

echo '  <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small mb-1">Posts</div>';
echo '    <div class="admin-kpi"><span class="num">' . $posts . '</span><span class="lbl">total</span></div>';
echo '    <div class="text-muted small">Total posts in database.</div>';
echo '  </div></div></div>';

echo '  <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small mb-1">Media</div>';
echo '    <div class="admin-kpi"><span class="num">' . $media . '</span><span class="lbl">files</span></div>';
echo '    <div class="text-muted small">Total uploaded files.</div>';
echo '  </div></div></div>';

echo '  <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '    <div class="text-muted small mb-1">Users</div>';
echo '    <div class="admin-kpi"><span class="num">' . $users . '</span><span class="lbl">accounts</span></div>';
echo '    <div class="text-muted small">Accounts.</div>';
echo '  </div></div></div>';
echo '</div>';

echo '<div class="divider"></div>';

// Registry + Update (Update is ADMIN-only, sits next to Registry)
echo '<div class="row">';

echo '  <div class="col-12 col-md-6"><div class="card"><div class="card-body">';
echo '    <div class="fw-semibold mb-1">Registry</div>';
echo '    <div class="text-muted small mb-2">Enabled components.</div>';
echo '    <div class="text-muted small">Modules: <strong>' . $modules . '</strong> &nbsp;|&nbsp; Plugins: <strong>' . $plugins . '</strong> &nbsp;|&nbsp; Themes: <strong>' . $themes . '</strong></div>';
echo '  </div></div></div>';

if ($isAdmin) {
    echo '  <div class="col-12 col-md-6"><div class="card"><div class="card-body">';
    echo '    <div class="fw-semibold mb-1">Update</div>';
    echo '    <div class="text-muted small mb-2">Core update status.</div>';

    echo '    <div class="text-muted small"><strong>Version</strong> ' . htmlspecialchars($localVersion, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '    <div class="text-muted small"><strong>Status:</strong> ' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</div>';

    echo '    <div class="mt-3">';
    echo '      <a class="btn btn-sm btn-primary" href="/admin?action=update">' . htmlspecialchars($updateLabel, ENT_QUOTES, 'UTF-8') . '</a>';
    echo '    </div>';
    echo '  </div></div></div>';
}

echo '</div>'; // row

echo '</div>'; // container
echo '</div>'; // admin-wrap

