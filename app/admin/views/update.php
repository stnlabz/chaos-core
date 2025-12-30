<?php

declare(strict_types=1);

$docroot = dirname(__DIR__, 3);          // .../public_html/poemei.com
$approot = dirname(__DIR__, 2);          // .../public_html/poemei.com/app

$paths = [
    $approot . '/update/lib.php',        // /app/update/lib.php (preferred)
    $docroot . '/update/lib.php',        // /update/lib.php (fallback)
];

$up_lib = '';
foreach ($paths as $p) {
    if (is_file($p)) {
        $up_lib = $p;
        break;
    }
}

echo '<div class="container my-3">';
echo '<h2 class="h5 m-0">Core Update</h2>';
echo '<div class="small text-muted mt-1">Runs the updater from admin.</div>';

if ($up_lib === '') {
    echo '<div class="alert alert-danger mt-3">';
    echo 'Missing updater lib.php. Checked:<br>';
    echo '<code>' . htmlspecialchars($paths[0], ENT_QUOTES, 'UTF-8') . '</code><br>';
    echo '<code>' . htmlspecialchars($paths[1], ENT_QUOTES, 'UTF-8') . '</code>';
    echo '</div>';
    echo '</div>';
    return;
}

require_once $up_lib;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo '<form method="post" class="admin-card mt-3">';
    echo '<p class="mb-3">This will fetch the remote version manifest, download the package, verify SHA256, apply it to <code>/app</code>, and preserve <code>/app/data</code> and <code>/app/update</code>.</p>';
    echo '<button class="btn btn-sm btn-primary" type="submit">Run Update</button> ';
    echo '<a class="btn btn-sm btn-outline-secondary" href="/admin">Back</a>';
    echo '</form>';
    echo '</div>';
    return;
}

echo '<div class="admin-card mt-3">';
echo '<div class="small text-muted mb-2">Output</div>';

ob_start();

try {
    chaos_update_upgrade();
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}

$out = (string) ob_get_clean();

echo '<pre style="white-space:pre-wrap;margin:0;">' . htmlspecialchars($out, ENT_QUOTES, 'UTF-8') . '</pre>';
echo '</div>';

echo '<div class="d-flex gap-2 flex-wrap mt-3">';
echo '<a class="btn btn-sm btn-outline-secondary" href="/admin">Back to Dashboard</a>';
echo '</div>';

echo '</div>';

