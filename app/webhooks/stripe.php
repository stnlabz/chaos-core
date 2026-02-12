<?php
declare(strict_types=1);

/**
 * Chaos CMS — Stripe Webhook
 *
 * Path: /app/webhooks/stripe.php
 *
 * Handles completed Stripe checkout sessions and records
 * purchases into finance_ledger.
 */

use Stripe\Webhook;

require_once __DIR__ . '/../core/init.php';

global $db;

header('Content-Type: application/json');

if (!isset($db) || !$db instanceof db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
    exit;
}

$conn = $db->connect();
if (!$conn instanceof mysqli) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect_failed']);
    exit;
}

/* ---------------------------------------------------------
 * Load Stripe secrets from DB-backed site settings
 * --------------------------------------------------------- */
 /**
$settings = $db->fetch("
    SELECT stripe_secret_key, stripe_webhook_secret
    FROM site_settings
    LIMIT 1
");

if (!is_array($settings)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_settings_missing']);
    exit;
}

*/
$getSetting = static function (mysqli $conn, string $name): string {
    $stmt = $conn->prepare("SELECT value FROM settings WHERE name=? LIMIT 1");
    if ($stmt === false) {
        return '';
    }

    $stmt->bind_param('s', $name);
    $stmt->execute();
    $res = $stmt->get_result();
    $val = '';

    if ($res instanceof mysqli_result) {
        $row = $res->fetch_assoc();
        if (is_array($row) && isset($row['value'])) {
            $val = (string)$row['value'];
        }
    }

    $stmt->close();
    return $val;
};

$stripeSecret  = trim($getSetting($conn, 'stripe_secret_key'));
$webhookSecret = trim($getSetting($conn, 'stripe_webhook_secret'));
/**
$stripeSecret  = (string)($settings['stripe_secret_key'] ?? '');
$webhookSecret = (string)($settings['stripe_webhook_secret'] ?? '');
*/
if ($stripeSecret === '' || $webhookSecret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'stripe_not_configured']);
    exit;
}

\Stripe\Stripe::setApiKey($stripeSecret);

/* ---------------------------------------------------------
 * Read raw payload
 * --------------------------------------------------------- */
$payload    = file_get_contents('php://input');
$sigHeader  = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

/* ---------------------------------------------------------
 * Handle checkout completion
 * --------------------------------------------------------- */
if ($event->type !== 'checkout.session.completed') {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$session = $event->data->object;

/*
 * REQUIRED metadata (already defined in your checkout module):
 *  user_id
 *  ref_type   (media | post)
 *  ref_id
 */
$meta = $session->metadata ?? null;

if (!$meta) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_metadata']);
    exit;
}

$userId  = (int)($meta->user_id ?? 0);
$refType = (string)($meta->ref_type ?? '');
$refId   = (int)($meta->ref_id ?? 0);

if ($userId <= 0 || $refId <= 0 || !in_array($refType, ['media', 'post'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_metadata']);
    exit;
}

/* ---------------------------------------------------------
 * Amounts
 * --------------------------------------------------------- */
$amountCents = (int)($session->amount_total ?? 0);
$amount      = number_format($amountCents / 100, 2, '.', '');
$currency    = strtoupper((string)($session->currency ?? 'usd'));
$stripeId    = (string)($session->payment_intent ?? '');

/* ---------------------------------------------------------
 * Insert ledger record
 * --------------------------------------------------------- */
$stmt = $conn->prepare("
    INSERT INTO finance_ledger
        (user_id, ref_type, ref_id, amount, currency, stripe_id, status, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, 'paid', NOW())
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
    exit;
}

$stmt->bind_param(
    'isidss',
    $userId,
    $refType,
    $refId,
    $amount,
    $currency,
    $stripeId
);

$stmt->execute();
$stmt->close();

/* ---------------------------------------------------------
 * Done
 * --------------------------------------------------------- */
echo json_encode([
    'ok'       => true,
    'recorded'=> true,
    'ref'      => $refType,
    'ref_id'   => $refId,
    'user_id'  => $userId,
    'amount'   => $amount
]);
exit;

