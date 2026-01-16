<?php

declare(strict_types=1);

/**
 * Checkout API Handler
 * Called from router for /checkout/create-session
 * Bypasses theme system for pure JSON response
 */

header('Content-Type: application/json');

global $db, $auth;

if (!isset($db) || !$db instanceof db) {
    echo json_encode(['error' => 'Database not available']);
    exit;
}

$conn = $db->connect();
if ($conn === false) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Check auth
$loggedIn = false;
$userId = null;
if (isset($auth) && $auth instanceof auth) {
    $loggedIn = $auth->check();
    if ($loggedIn && method_exists($auth, 'id')) {
        try {
            $userId = $auth->id();
        } catch (Throwable $e) {
            $userId = null;
        }
    }
}

if (!$loggedIn || $userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$contentType = (string)($input['contentType'] ?? '');
$contentId = (int)($input['contentId'] ?? 0);
$amount = (int)($input['amount'] ?? 0);
$title = (string)($input['title'] ?? 'Content Purchase');

if ($contentType === '' || $contentId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// Get Stripe secret key
$stripeSecretKey = '';
$row = $db->fetch("SELECT value FROM settings WHERE name='stripe_secret_key' LIMIT 1");
if (is_array($row)) {
    $stripeSecretKey = (string)($row['value'] ?? '');
}

if ($stripeSecretKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Payment system not configured']);
    exit;
}

// Build URLs
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

$successUrl = $scheme . '://' . $host . '/checkout/success?type=' . $contentType;
$cancelUrl = $scheme . '://' . $host . '/checkout?type=' . $contentType . '&id=' . $contentId . '&canceled=1';

// Create Stripe session
$data = [
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price_data' => [
            'currency' => 'usd',
            'product_data' => ['name' => $title],
            'unit_amount' => $amount,
        ],
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'metadata' => [
        'user_id' => (string)$userId,
        'content_type' => $contentType,
        'content_id' => (string)$contentId,
    ],
    'payment_intent_data' => [
        'metadata' => [
            'user_id' => (string)$userId,
            'content_type' => $contentType,
            'content_id' => (string)$contentId,
        ],
    ],
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $stripeSecretKey,
    'Content-Type: application/x-www-form-urlencoded',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log('Stripe API error: ' . $response);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create checkout session']);
    exit;
}

$session = json_decode($response, true);
if (!is_array($session) || !isset($session['id'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid response from payment provider']);
    exit;
}

echo json_encode([
    'id' => $session['id'],
    'url' => $session['url'] ?? null,
]);
exit;
