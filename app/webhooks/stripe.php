<?php
declare(strict_types=1);

/**
 * Chaos CMS — Stripe Webhook (NO SDK)
 *
 * Path: /app/webhooks/stripe.php
 *
 * - Verifies Stripe signature manually
 * - Parses checkout.session.completed
 * - Inserts finance_ledger row
 *
 * ZERO external dependencies.
 */

header('Content-Type: application/json; charset=utf-8');

// -----------------------------------------------------
// Bootstrap DB
// -----------------------------------------------------
require_once __DIR__ . '/../core/bootstrap.php'; // use YOUR real bootstrap file

global $db;

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

// -----------------------------------------------------
// Load webhook secret from settings table
// -----------------------------------------------------
$stmt = $conn->prepare("SELECT value FROM settings WHERE name='stripe_webhook_secret' LIMIT 1");
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

$webhookSecret = (string)($row['value'] ?? '');

if ($webhookSecret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'webhook_secret_missing']);
    exit;
}

// -----------------------------------------------------
// Read raw payload
// -----------------------------------------------------
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === '' || $sigHeader === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_signature']);
    exit;
}

// -----------------------------------------------------
// Verify Stripe signature manually
// -----------------------------------------------------
$parts = explode(',', $sigHeader);
$sig = '';
$timestamp = '';

foreach ($parts as $part) {
    if (strpos($part, 't=') === 0) {
        $timestamp = substr($part, 2);
    }
    if (strpos($part, 'v1=') === 0) {
        $sig = substr($part, 3);
    }
}

if ($timestamp === '' || $sig === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature_header']);
    exit;
}

$signedPayload = $timestamp . '.' . $payload;
$expectedSig = hash_hmac('sha256', $signedPayload, $webhookSecret);

if (!hash_equals($expectedSig, $sig)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'signature_mismatch']);
    exit;
}

// -----------------------------------------------------
// Parse event
// -----------------------------------------------------
$event = json_decode($payload, true);

if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

if (($event['type'] ?? '') !== 'checkout.session.completed') {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$session = $event['data']['object'] ?? null;
if (!is_array($session)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_session']);
    exit;
}

// -----------------------------------------------------
// Extract metadata
// -----------------------------------------------------
$meta = $session['metadata'] ?? [];

$userId  = (int)($meta['user_id'] ?? 0);
$refType = (string)($meta['ref_type'] ?? '');
$refId   = (int)($meta['ref_id'] ?? 0);

if ($userId <= 0 || $refId <= 0 || !in_array($refType, ['media','post'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_metadata']);
    exit;
}

// -----------------------------------------------------
// Prevent duplicate ledger entries
// -----------------------------------------------------
$stmt = $conn->prepare("
    SELECT id FROM finance_ledger
    WHERE user_id=? AND ref_type=? AND ref_id=? AND status='paid'
    LIMIT 1
");
$stmt->bind_param('isi', $userId, $refType, $refId);
$stmt->execute();
$res = $stmt->get_result();
$exists = $res ? $res->fetch_row() : null;
$stmt->close();

if ($exists) {
    echo json_encode(['ok' => true, 'duplicate' => true]);
    exit;
}

// -----------------------------------------------------
// Insert ledger
// -----------------------------------------------------
$amountCents = (int)($session['amount_total'] ?? 0);
$amount = number_format($amountCents / 100, 2, '.', '');
$currency = strtoupper((string)($session['currency'] ?? 'usd'));
$stripeId = (string)($session['payment_intent'] ?? '');

$stmt = $conn->prepare("
    INSERT INTO finance_ledger
        (user_id, ref_type, ref_id, amount, currency, stripe_id, status, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, 'paid', NOW())
");

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

echo json_encode(['ok' => true, 'recorded' => true]);
exit;

