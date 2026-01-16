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
    'site_name'              => 'Site Name',
    'site_slogan'            => 'Site Tagline',
    'from_email'             => 'Site Email',
    'smtp-host'              => 'SMTP Host',
    'smtp_port'              => 'SMTP Port',
    'smtp_user'              => 'SMTP Username',
    'smtp-pass'              => 'SMTP Password',
    'smtp_secure'            => 'SMTP Security (ssl / tls / none)',
    'stripe_publishable_key' => 'Stripe Publishable Key',
    'stripe_secret_key'      => 'Stripe Secret Key',
    'stripe_webhook_secret'  => 'Stripe Webhook Secret',
    'stripe_enabled'         => 'Enable Stripe (1 or 0)',
    'platform_fee_percent'   => 'Platform Fee Percentage (e.g., 10 for 10%)',
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

        <!-- Site Settings -->
        <h3 class="h5 mb-3 mt-4">Site Settings</h3>
        
        <?php 
        $siteFields = ['site_name', 'site_slogan', 'from_email'];
        foreach ($siteFields as $key): 
            if (!isset($fields[$key])) continue;
            $label = $fields[$key];
        ?>
            <div class="mb-3">
                <label class="small fw-semibold"><?= htmlspecialchars($label) ?></label>
                <input
                    type="text"
                    name="<?= htmlspecialchars($key) ?>"
                    value="<?= htmlspecialchars($values[$key] ?? '', ENT_QUOTES) ?>"
                    class="form-control"
                >
            </div>
        <?php endforeach; ?>

        <!-- SMTP Settings -->
        <h3 class="h5 mb-3 mt-4">SMTP Settings</h3>
        
        <?php 
        $smtpFields = ['smtp-host', 'smtp_port', 'smtp_user', 'smtp-pass', 'smtp_secure'];
        foreach ($smtpFields as $key): 
            if (!isset($fields[$key])) continue;
            $label = $fields[$key];
        ?>
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

        <!-- Stripe Settings -->
        <h3 class="h5 mb-3 mt-4">Stripe Payment Settings</h3>
        <div class="alert alert-info small mb-3">
            <strong>Setup Instructions:</strong><br>
            1. Get your API keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard → Developers → API Keys</a><br>
            2. Create a webhook endpoint at <code>https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yoursite.com') ?>/webhooks/stripe</code><br>
            3. Copy the webhook signing secret from <a href="https://dashboard.stripe.com/webhooks" target="_blank">Stripe Dashboard → Developers → Webhooks</a><br>
            4. Select these events: <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, <code>customer.subscription.*</code>
        </div>

        <div class="mb-3">
            <label class="small fw-semibold">Enable Stripe (1 or 0)</label>
            <select name="stripe_enabled" class="form-control">
                <option value="0" <?= ($values['stripe_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>0 - Disabled</option>
                <option value="1" <?= ($values['stripe_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>1 - Enabled</option>
            </select>
            <div class="form-text small">Enable or disable Stripe payments site-wide</div>
        </div>

        <div class="mb-3">
            <label class="small fw-semibold">Stripe Publishable Key</label>
            <input
                type="text"
                name="stripe_publishable_key"
                value="<?= htmlspecialchars($values['stripe_publishable_key'] ?? '', ENT_QUOTES) ?>"
                class="form-control"
                placeholder="pk_test_... or pk_live_..."
            >
            <div class="form-text small">Public key for frontend (safe to expose)</div>
        </div>

        <div class="mb-3">
            <label class="small fw-semibold">Stripe Secret Key</label>
            <input
                type="password"
                name="stripe_secret_key"
                value="<?= htmlspecialchars($values['stripe_secret_key'] ?? '', ENT_QUOTES) ?>"
                class="form-control"
                placeholder="sk_test_... or sk_live_..."
            >
            <div class="form-text small">Secret key for backend operations (never expose to frontend)</div>
        </div>

        <div class="mb-3">
            <label class="small fw-semibold">Stripe Webhook Secret</label>
            <input
                type="password"
                name="stripe_webhook_secret"
                value="<?= htmlspecialchars($values['stripe_webhook_secret'] ?? '', ENT_QUOTES) ?>"
                class="form-control"
                placeholder="whsec_..."
            >
            <div class="form-text small">Signing secret for webhook verification</div>
        </div>

        <div class="mb-3">
            <label class="small fw-semibold">Platform Fee Percentage</label>
            <input
                type="number"
                name="platform_fee_percent"
                value="<?= htmlspecialchars($values['platform_fee_percent'] ?? '10', ENT_QUOTES) ?>"
                class="form-control"
                min="0"
                max="100"
                step="0.1"
            >
            <div class="form-text small">Platform fee taken from creator earnings (e.g., 10 = 10%)</div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm mt-3">
            Save Settings
        </button>
    </form>
</div>
