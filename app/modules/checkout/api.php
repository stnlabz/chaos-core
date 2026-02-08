<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Checkout (API)
 *
 * Route:
 *   POST /checkout/create-session
 *
 * Notes:
 * - Same-origin JSON endpoint (no theme).
 * - Creates a Stripe Checkout Session for media/post premium purchases.
 * - Amount/description are resolved server-side from the database (never trusted from the browser).
 * - No PDO. MySQLi only.
 */

(function (): void {
    global $db, $auth;

    header('Content-Type: application/json; charset=utf-8');

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_missing']);
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_connect_failed']);
        return;
    }

    $loggedIn = false;
    $userId = 0;

    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = $auth->check();
        $uid = $auth->id();
        if (is_int($uid) && $uid > 0) {
            $userId = (int)$uid;
        }
    }

    if (!$loggedIn || $userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'login_required']);
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        return;
    }

    // -------------------------------------------------------------
    // Input (JSON preferred, form-data tolerated)
    // -------------------------------------------------------------
    $raw = (string)file_get_contents('php://input');
    $data = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $type = (string)($data['contentType'] ?? $data['type'] ?? $_POST['contentType'] ?? $_POST['type'] ?? $_POST['ref_type'] ?? '');
    $id = (int)($data['contentId'] ?? $data['id'] ?? $_POST['contentId'] ?? $_POST['id'] ?? $_POST['ref_id'] ?? 0);

    $type = strtolower(trim($type));

    if (!in_array($type, ['media', 'post'], true) || $id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_parameters']);
        return;
    }

    // -------------------------------------------------------------
    // Settings lookup (DB-driven)
    // -------------------------------------------------------------
    $get_setting = static function (mysqli $conn, string $key): string {
        // Primary: shop_settings(name,value)
        $stmt = $conn->prepare("SELECT value FROM shop_settings WHERE name=? LIMIT 1");
        if ($stmt !== false) {
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
            $stmt->close();
            if (is_array($row) && isset($row['value'])) {
                return (string)$row['value'];
            }
        }

        // Fallback: site_settings(name,value)
        $stmt2 = $conn->prepare("SELECT value FROM site_settings WHERE name=? LIMIT 1");
        if ($stmt2 !== false) {
            $stmt2->bind_param('s', $key);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $row2 = ($res2 instanceof mysqli_result) ? $res2->fetch_assoc() : null;
            $stmt2->close();
            if (is_array($row2) && isset($row2['value'])) {
                return (string)$row2['value'];
            }
        }

        // Fallback: settings(setting_key, setting_value)
        $stmt3 = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        if ($stmt3 !== false) {
            $stmt3->bind_param('s', $key);
            $stmt3->execute();
            $res3 = $stmt3->get_result();
            $row3 = ($res3 instanceof mysqli_result) ? $res3->fetch_assoc() : null;
            $stmt3->close();
            if (is_array($row3) && isset($row3['setting_value'])) {
                return (string)$row3['setting_value'];
            }
        }

        return '';
    };

    $stripeSecretKey = $get_setting($conn, 'stripe_sk');
    if ($stripeSecretKey === '') {
        $stripeSecretKey = $get_setting($conn, 'stripe_secret_key');
    }

    if ($stripeSecretKey === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'stripe_secret_missing']);
        return;
    }

    // -------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------
    $money_to_cents = static function (string $price): int {
        $p = trim($price);
        if ($p === '') {
            return 0;
        }

        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $p)) {
            $p = (string)((float)$p);
        }

        $f = (float)$p;
        $cents = (int)round($f * 100);
        if ($cents < 0) {
            $cents = 0;
        }
        return $cents;
    };

    $already_paid = static function (mysqli $conn, int $userId, string $refType, int $refId): bool {
        $stmt = $conn->prepare("SELECT 1 FROM finance_ledger WHERE user_id=? AND ref_type=? AND ref_id=? AND status='paid' LIMIT 1");
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('isi', $userId, $refType, $refId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res instanceof mysqli_result) ? (bool)$res->fetch_row() : false;
        $stmt->close();

        return $ok;
    };

    $insert_free_paid = static function (mysqli $conn, int $userId, string $refType, int $refId): bool {
        if ($already_paid($conn, $userId, $refType, $refId)) {
            return true;
        }

        $stmt = $conn->prepare("
            INSERT INTO finance_ledger (user_id, ref_type, ref_id, amount, currency, status, created_at)
            VALUES (?, ?, ?, 0, 'usd', 'paid', NOW())
        ");
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('isi', $userId, $refType, $refId);
        $ok = $stmt->execute();
        $stmt->close();

        return (bool)$ok;
    };

    // -------------------------------------------------------------
    // Resolve item (server-side)
    // -------------------------------------------------------------
    $title = '';
    $priceStr = '0.00';
    $creatorId = 0;

    if ($type === 'media') {
        $stmt = $conn->prepare("SELECT id, title, price, user_id FROM media_gallery WHERE id=? LIMIT 1");
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
            return;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            return;
        }

        $title = trim((string)($row['title'] ?? ''));
        $priceStr = (string)($row['price'] ?? '0.00');
        $creatorId = (int)($row['user_id'] ?? 0);

        if ($title === '') {
            $title = 'Media #' . (string)$id;
        }
    } else {
        $stmt = $conn->prepare("SELECT id, title, price, user_id FROM posts WHERE id=? LIMIT 1");
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'db_prepare_failed']);
            return;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            return;
        }

        $title = trim((string)($row['title'] ?? ''));
        $priceStr = (string)($row['price'] ?? '0.00');
        $creatorId = (int)($row['user_id'] ?? 0);

        if ($title === '') {
            $title = 'Post #' . (string)$id;
        }
    }

    if ($already_paid($conn, $userId, $type, $id)) {
        echo json_encode(['ok' => true, 'already_paid' => 1, 'url' => ($type === 'media') ? '/media' : '/posts']);
        return;
    }

    $amountCents = $money_to_cents($priceStr);

    if ($amountCents <= 0) {
        $ok = $insert_free_paid($conn, $userId, $type, $id);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'ledger_insert_failed']);
            return;
        }

        echo json_encode(['ok' => true, 'free' => 1, 'url' => ($type === 'media') ? '/media' : '/posts']);
        return;
    }

    // -------------------------------------------------------------
    // Create Stripe Checkout Session
    // -------------------------------------------------------------
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $baseUrl = $scheme . '://' . $host;

    $successUrl = $baseUrl . '/checkout?type=' . rawurlencode($type) . '&id=' . rawurlencode((string)$id) . '&success=1';
    $cancelUrl  = $baseUrl . '/checkout?type=' . rawurlencode($type) . '&id=' . rawurlencode((string)$id) . '&cancel=1';

    $fields = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string)$userId,
        'metadata[ref_type]' => $type,
        'metadata[ref_id]' => (string)$id,
        'metadata[creator_id]' => (string)$creatorId,
        'metadata[user_id]' => (string)$userId,
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string)$amountCents,
        'line_items[0][price_data][product_data][name]' => $title,
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    if ($ch === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'curl_init_failed']);
        return;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripeSecretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($resp) || $resp === '') {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'stripe_no_response']);
        return;
    }

    $out = json_decode($resp, true);
    if (!is_array($out)) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'stripe_bad_json', 'http' => $http]);
        return;
    }

    if ($http >= 400 || isset($out['error'])) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'stripe_error', 'http' => $http, 'stripe' => $out['error'] ?? $out]);
        return;
    }

    $url = (string)($out['url'] ?? '');
    if ($url === '') {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'stripe_missing_url', 'http' => $http]);
        return;
    }

    echo json_encode(['ok' => true, 'url' => $url]);
})();

