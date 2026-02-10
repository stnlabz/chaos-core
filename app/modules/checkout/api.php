<?php

declare(strict_types=1);

ob_clean();
header_remove();
header('Content-Type: application/json; charset=utf-8');

/**
 * Chaos CMS — Checkout API
 * Route: /checkout/create-session
 *
 * Accepts:
 *  - JSON: { "type":"media","id":91 }
 *  - FORM: type=media&id=91
 *
 * MySQLi ONLY
 */

(function (): void {
    global $db, $auth;

    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        //return;
        exit;
    }

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_unavailable']);
        //return;
        exit;
    }

    $conn = $db->connect();
    if (!$conn instanceof mysqli) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_connect_failed']);
        //return;
        exit;
    }

    // ------------------------------------------------------------
    // Auth (NO redirects, NO HTML)
    // ------------------------------------------------------------
    if (!isset($auth) || !$auth instanceof auth || !$auth->check()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'login_required']);
        //return;
        exit;
    }

    $userId = (int)$auth->id();
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'login_required']);
        //return;
        exit;
    }

    // ------------------------------------------------------------
    // INPUT: JSON OR FORM
    // ------------------------------------------------------------
    $input = [];

    $raw = trim((string)file_get_contents('php://input'));
    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = $json;
        }
    }

    // Fallback to POST (THIS WAS MISSING)
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }

    $type = strtolower(trim((string)($input['type'] ?? $input['contentType'] ?? '')));
    $id   = (int)($input['id'] ?? $input['contentId'] ?? 0);

    if (!in_array($type, ['media', 'post'], true) || $id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_checkout_parameters']);
        //return;
        exit;
    }

    // ------------------------------------------------------------
    // SETTINGS (DB-backed, Chaos-style)
    // ------------------------------------------------------------
    $getSetting = static function (mysqli $conn, string $name): string {
        $stmt = $conn->prepare("SELECT value FROM settings WHERE name=? LIMIT 1");
        if (!$stmt) return '';
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $res = $stmt->get_result();
        $val = ($res && ($row = $res->fetch_assoc())) ? (string)$row['value'] : '';
        $stmt->close();
        return $val;
        exit;
    };

    if ($getSetting($conn, 'stripe_enabled') !== '1') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'stripe_disabled']);
        return;
        exit;
    }

    $stripeSecret = trim($getSetting($conn, 'stripe_secret_key'));
    if ($stripeSecret === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'stripe_not_configured']);
        return;
        exit;
    }

    // ------------------------------------------------------------
    // LOOKUP ITEM (SERVER-SIDE TRUST ONLY)
    // ------------------------------------------------------------
    if ($type === 'media') {
        $stmt = $conn->prepare("SELECT title, price FROM media_gallery WHERE id=? LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT title, price FROM posts WHERE id=? LIMIT 1");
    }

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
        return;
        exit;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'item_not_found']);
        return;
        exit;
    }

    $title = trim((string)($row['title'] ?? 'Item #' . $id));
    $price = (float)($row['price'] ?? 0);

    $amount = (int)round($price * 100);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_price']);
        return;
        exit;
    }

    // ------------------------------------------------------------
    // STRIPE SESSION
    // ------------------------------------------------------------
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');

    $fields = [
        'mode' => 'payment',
        'success_url' => $base . "/checkout?type={$type}&id={$id}&status=success&session_id={CHECKOUT_SESSION_ID}",
        'cancel_url'  => $base . "/checkout?type={$type}&id={$id}&status=cancel",

        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string)$amount,
        'line_items[0][price_data][product_data][name]' => $title,
        'line_items[0][quantity]' => '1',

        'metadata[ref_type]' => $type,
        'metadata[ref_id]'   => (string)$id,
        'metadata[user_id]'  => (string)$userId,
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $stripeSecret],
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($http < 200 || $http >= 300 || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'stripe_error', 'http' => $http]);
        return;
    }

    echo json_encode(['ok' => true, 'url' => (string)$data['url']]);
})();

