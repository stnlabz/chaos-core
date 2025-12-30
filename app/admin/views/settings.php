<?php
declare(strict_types=1);

global $db;

if (!$db instanceof db) {
    echo '<div class="alert alert-danger">Database not available.</div>';
    return;
}

$conn = $db->connect();
if ($conn === false) {
    echo '<div class="alert alert-danger">DB connection failed.</div>';
    return;
}

/**
 * Friendly settings map
 * key => label
 */
$fields = [
    'site_name'    => 'Site Name',
    'site_slogan' => 'Site Tagline',
    'from_email'   => 'Site Email',
    'smtp-host'    => 'SMTP Host',
    'smtp_port'    => 'SMTP Port',
    'smtp_user'    => 'SMTP Username',
    'smtp-pass'    => 'SMTP Password',
    'smtp_secure'  => 'SMTP Security (ssl / tls / none)',
];

$values = [];

// Load existing values
$res = $conn->query("SELECT name, value FROM settings");
while ($row = $res->fetch_assoc()) {
    $values[$row['name']] = $row['value'];
}

// Save on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $key => $label) {
        if (!isset($_POST[$key])) {
            continue;
        }

        $val = trim((string) $_POST[$key]);

        $stmt = $conn->prepare(
            "INSERT INTO settings (name, value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        $stmt->bind_param('ss', $key, $val);
        $stmt->execute();
    }

    echo '<div class="alert alert-success">Settings saved.</div>';

    // Refresh values
    $res = $conn->query("SELECT name, value FROM settings");
    $values = [];
    while ($row = $res->fetch_assoc()) {
        $values[$row['name']] = $row['value'];
    }
}
?>

<div class="container my-4">
    <small><a href="/admin">Admin</a> &raquo; Settings</small>
    <h1 class="mt-2">Settings</h1>

    <form method="post" class="mt-3">

        <?php foreach ($fields as $key => $label): ?>
            <div class="mb-3">
                <label class="small fw-semibold"><?= htmlspecialchars($label) ?></label>
                <input
                    type="<?= str_contains($key, 'pass') ? 'password' : 'text' ?>"
                    name="<?= htmlspecialchars($key) ?>"
                    value="<?= htmlspecialchars($values[$key] ?? '', ENT_QUOTES) ?>"
                    class="form-control"
                >
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary btn-sm">
            Save Settings
        </button>
    </form>
</div>

