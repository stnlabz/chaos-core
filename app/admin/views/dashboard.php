<?php

declare(strict_types=1);

/**
 * Chaos CMS Admin: Dashboard
 *
 * Notes:
 * - Must be wrapped in .admin-wrap so admin.css scoped layout applies.
 * - No inline CSS. No CSS variables belong in this file.
 */
global $db;

if (!isset($db) || !$db instanceof db) {
    echo '<div class="admin-wrap"><div class="container my-4">';
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

// Counts (adjust table names here only if your schema differs)
$pagesTotal     = $countSql('SELECT COUNT(*) FROM pages');
$pagesPublished = $countSql('SELECT COUNT(*) FROM pages WHERE status=1');
$pagesDrafts    = $countSql('SELECT COUNT(*) FROM pages WHERE status=0');

$posts   = $countSql('SELECT COUNT(*) FROM posts');
$media   = $countSql('SELECT COUNT(*) FROM media_files');
$users   = $countSql('SELECT COUNT(*) FROM users');
$modules = $countSql('SELECT COUNT(*) FROM modules WHERE installed=1 AND enabled=1');
$plugins = $countSql('SELECT COUNT(*) FROM plugins WHERE installed=1 AND enabled=1');
$themes  = $countSql('SELECT COUNT(*) FROM themes WHERE installed=1 AND enabled=1');

// Version check
$localVersion  = 'unknown';
$remoteVersion = 'unknown';
$statusText    = 'Unknown';

$localPath = $docroot . '/app/data/version.json';

if (is_file($localPath)) {
    $json = json_decode((string) @file_get_contents($localPath), true);

    if (is_array($json) && isset($json['version'])) {
        $localVersion = (string) $json['version'];
    }
}

$remoteUrl = 'https://version.chaoscms.org/db/version.json';
$ctx       = stream_context_create([
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
    $statusText = version_compare($localVersion, $remoteVersion, '>=') ? 'Up to Date' : 'Update Available';
}

// -----------------------------------------------------------------------------
// Render
// -----------------------------------------------------------------------------

echo '<div class="admin-wrap admin-dash">';
echo '  <div class="container my-4">';
echo '    <div class="text-muted small">Admin &raquo; Dashboard</div>';
echo '    <h1 class="h3 mt-2 mb-1">Dashboard</h1>';
echo '    <div class="text-muted mb-3">Quick view.</div>';

$tiles = [
    ['Settings', 'Manage your sites Settings', '/admin?action=settings'],
    ['Pages', 'Manage public pages', '/admin?action=pages'],
    ['Posts', 'Write &amp; publish', '/admin?action=posts'],
    ['Media', 'Uploads &amp; gallery', '/admin?action=media'],
    ['Users', 'Accounts', '/admin?action=users'],

    // Tools
    ['Topics', 'Tools: topics taxonomy', '/admin?action=topics'],
    ['Roles', 'Tools: roles registry', '/admin?action=roles'],

    ['Modules', 'Installed modules', '/admin?action=modules'],
    ['Plugins', 'Extensions', '/admin?action=plugins'],
    ['Themes', 'Site Ascetic', '/admin?action=themes'],
    ['Maintenance', 'Actions &amp; tools', '/admin?action=maintenance'],
    ['Health', 'System reporting', '/admin?action=health'],
];

echo '    <div class="row">';

foreach ($tiles as $t) {
    $label = (string) $t[0];
    $desc  = (string) $t[1];
    $href  = (string) $t[2];

    echo '      <div class="col-12 col-md-6 col-lg-3">';
    echo '        <div class="card"><div class="card-body">';
    echo '          <div class="fw-semibold">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '          <div class="text-muted small mb-2">' . $desc . '</div>';
    echo '          <a class="btn btn-sm" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Open</a>';
    echo '        </div></div>';
    echo '      </div>';
}

echo '    </div>';
echo '    <hr>';

// KPIs
echo '    <div class="row">';

echo '      <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '        <div class="text-muted small mb-1">Pages</div>';
echo '        <div class="admin-kpi"><span class="num">' . $pagesTotal . '</span><span class="lbl">total</span></div>';
echo '        <div class="text-muted small">Published: ' . $pagesPublished . ' &nbsp;|&nbsp; Drafts: ' . $pagesDrafts . '</div>';
echo '      </div></div></div>';

echo '      <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '        <div class="text-muted small mb-1">Posts</div>';
echo '        <div class="admin-kpi"><span class="num">' . $posts . '</span><span class="lbl">total</span></div>';
echo '      </div></div></div>';

echo '      <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '        <div class="text-muted small mb-1">Media</div>';
echo '        <div class="admin-kpi"><span class="num">' . $media . '</span><span class="lbl">files</span></div>';
echo '      </div></div></div>';

echo '      <div class="col-12 col-md-6 col-lg-3"><div class="card"><div class="card-body">';
echo '        <div class="text-muted small mb-1">Users</div>';
echo '        <div class="admin-kpi"><span class="num">' . $users . '</span><span class="lbl">accounts</span></div>';
echo '      </div></div></div>';

echo '    </div>';
echo '    <div class="divider"></div>';

// Registry + Version
echo '    <div class="row">';

echo '      <div class="col-12 col-md-6"><div class="card"><div class="card-body">';
echo '        <div class="fw-semibold mb-1">Registry</div>';
echo '        <div class="text-muted small mb-2">Enabled components.</div>';
echo '        <div class="text-muted small">Modules: <strong>' . $modules . '</strong> &nbsp;|&nbsp; Plugins: <strong>' . $plugins . '</strong></div>';
echo '      </div></div></div>';

echo '      <div class="col-12 col-md-6"><div class="card"><div class="card-body">';
echo '        <div class="fw-semibold mb-1">Core Version</div>';
echo '        <div class="text-muted small mb-2">Local vs remote version check.</div>';
echo '        <div class="text-muted small">Status: <strong>' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</strong></div>';
echo '        <div class="text-muted small">Local: <span class="fw-semibold">' . htmlspecialchars($localVersion, ENT_QUOTES, 'UTF-8') . '</span></div>';
echo '        <div class="text-muted small">Remote: <span class="fw-semibold">' . htmlspecialchars($remoteVersion, ENT_QUOTES, 'UTF-8') . '</span></div>';

if ($statusText === 'Update Available') {
    echo '        <div class="mt-3">';
    echo '          <a class="btn btn-sm btn-primary" href="/admin?action=update">Run Update</a>';
    echo '        </div>';
}

echo '      </div></div></div>';

echo '    </div>';
echo '  </div>';
echo '</div>';

