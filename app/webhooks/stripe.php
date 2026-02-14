<?php

declare(strict_types=1);

/**
 * Chaos CMS — Stripe Webhook (NO external dependencies)
 *
 * Path: /app/webhooks/stripe.php
 * Routes (via router):
 *   /webhooks/stripe
 *   /webhooks/stripe.php
 *
 * Handles Stripe event: checkout.session.completed
 * - Verifies Stripe-Signature using HMAC SHA256
 * - Extracts metadata written by Checkout:
 *     metadata[user_id]
 *     metadata[ref_type] (media|post)
 *     metadata[ref_id]
 * - Inserts a paid entitlement row into finance_ledger
 *
 * Notes:
 * - MySQLi ONLY. No PDO.
 * - Stripe secrets are stored in settings(name,value)
 * - Responds JSON only (no theme HTML)
 */

(function (): void {
    global $db;

    header('Content-Type: application/json; charset=utf-8');

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
        return;
    }

    $conn = $db->connect();
    if (!$conn instanceof mysqli) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_connect_failed']);
        return;
    }

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
            if (is_array($row) && array_key_exists('value', $row)) {
                $val = (string)$row['value'];
            }
        }

        $stmt->close();
        return $val;
    };

    $stripeEnabled = trim($getSetting($conn, 'stripe_enabled'));
    $webhookSecret = trim($getSetting($conn, 'stripe_webhook_secret'));

    if ($stripeEnabled !== '1' || $webhookSecret === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'stripe_not_configured']);
        return;
    }

    $payload = (string)file_get_contents('php://input');
    $sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

    if ($payload === '' || $sigHeader === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_signature']);
        return;
    }

    // Parse Stripe-Signature: t=timestamp,v1=signature[,v1=signature2...]
    $ts = 0;
    $v1s = [];

    foreach (explode(',', $sigHeader) as $p) {
        $kv = explode('=', trim($p), 2);
        if (count($kv) !== 2) {
            continue;
        }
        $k = trim($kv[0]);
        $v = trim($kv[1]);

        if ($k === 't') {
            $ts = (int)$v;
        }
        if ($k === 'v1') {
            $v1s[] = $v;
        }
    }

    if ($ts <= 0 || empty($v1s)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_signature_header']);
        return;
    }

    // Replay protection tolerance (seconds)
    $tolerance = 300;
    if (abs(time() - $ts) > $tolerance) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'timestamp_out_of_tolerance']);
        return;
    }

    $signedPayload = $ts . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);

    $sigOk = false;
    foreach ($v1s as $sig) {
        if (hash_equals($expected, $sig)) {
            $sigOk = true;
            break;
        }
    }

    if (!$sigOk) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
        return;
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_json']);
        return;
    }

    $type = (string)($event['type'] ?? '');
    if ($type !== 'checkout.session.completed') {
        echo json_encode(['ok' => true, 'ignored' => true, 'type' => $type]);
        return;
    }

    $session = $event['data']['object'] ?? null;
    if (!is_array($session)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_session']);
        return;
    }

    $meta = $session['metadata'] ?? null;
    if (!is_array($meta)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_metadata']);
        return;
    }

    $userId  = (int)($meta['user_id'] ?? 0);
    $refType = strtolower(trim((string)($meta['ref_type'] ?? '')));
    $refId   = (int)($meta['ref_id'] ?? 0);

    if ($userId <= 0 || $refId <= 0 || !in_array($refType, ['media', 'post'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_metadata']);
        return;
    }

    $amountCents = (int)($session['amount_total'] ?? 0);
    $currency    = strtoupper((string)($session['currency'] ?? 'USD'));

    // Prefer payment_intent; fallback to session id
    $stripeId = (string)($session['payment_intent'] ?? '');
    if ($stripeId === '') {
        $stripeId = (string)($session['id'] ?? '');
    }
    if ($stripeId === '') {
        $stripeId = 'stripe_' . bin2hex(random_bytes(8));
    }

    $amount = (float)($amountCents / 100);

    // Idempotency: prevent duplicates (Stripe retries webhooks)
    $stmt0 = $conn->prepare("SELECT 1 FROM finance_ledger WHERE stripe_id=? LIMIT 1");
    if ($stmt0 === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
        return;
    }

    $stmt0->bind_param('s', $stripeId);
    $stmt0->execute();
    $res0 = $stmt0->get_result();
    $exists = ($res0 instanceof mysqli_result) ? (bool)$res0->fetch_row() : false;
    $stmt0->close();

    if ($exists) {
        echo json_encode(['ok' => true, 'duplicate' => true, 'stripe_id' => $stripeId]);
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO finance_ledger
            (user_id, ref_type, ref_id, amount, currency, stripe_id, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 'paid', NOW())
    ");
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
        return;
    }

    $stmt->bind_param('isidss', $userId, $refType, $refId, $amount, $currency, $stripeId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok' => true,
        'recorded' => true,
        'ref_type' => $refType,
        'ref_id' => $refId,
        'user_id' => $userId,
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => $currency,
        'stripe_id' => $stripeId,
    ]);
})();

