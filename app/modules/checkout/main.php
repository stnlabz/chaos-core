<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Checkout
 *
 * Routes:
 *   /checkout
 *
 * Notes:
 * - Handles payment checkout for premium media/posts via Stripe Checkout Sessions.
 * - Uses /checkout/create-session (same module) as the API endpoint.
 * - No PDO. MySQLi only.
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB is not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $loggedIn = false;
    $userId = 0;

    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = $auth->check();
        $uid = $auth->id();
        if (is_int($uid) && $uid > 0) {
            $userId = (int)$uid;
        }
    }

    $type = (string)($_GET['type'] ?? ($_GET['ref_type'] ?? ''));
    $id = (int)($_GET['id'] ?? ($_GET['ref_id'] ?? 0));
    $type = strtolower(trim($type));

    if (!in_array($type, ['media', 'post'], true) || $id <= 0) {
        http_response_code(400);
        echo '<div class="container my-4"><div class="alert alert-danger">Invalid checkout parameters.</div></div>';
        return;
    }

    if (!$loggedIn || $userId <= 0) {
        echo '<div class="container my-4"><div class="alert alert-warning">Please <a href="/login">log in</a> to purchase.</div></div>';
        return;
    }

    // Resolve item details for display only (API resolves again server-side)
    $title = '';
    $priceStr = '0.00';

    if ($type === 'media') {
        $stmt = $conn->prepare("SELECT title, price FROM media_gallery WHERE id=? LIMIT 1");
        if ($stmt === false) {
            http_response_code(500);
            echo '<div class="container my-4"><div class="alert alert-danger">DB error.</div></div>';
            return;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-danger">Not found.</div></div>';
            return;
        }

        $title = trim((string)($row['title'] ?? ''));
        $priceStr = (string)($row['price'] ?? '0.00');

        if ($title === '') {
            $title = 'Media #' . (string)$id;
        }
    } else {
        $stmt = $conn->prepare("SELECT title, price FROM posts WHERE id=? LIMIT 1");
        if ($stmt === false) {
            http_response_code(500);
            echo '<div class="container my-4"><div class="alert alert-danger">DB error.</div></div>';
            return;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-danger">Not found.</div></div>';
            return;
        }

        $title = trim((string)($row['title'] ?? ''));
        $priceStr = (string)($row['price'] ?? '0.00');

        if ($title === '') {
            $title = 'Post #' . (string)$id;
        }
    }

    $fmtMoney = static function (string $v): string {
        $f = (float)$v;
        if (abs($f) < 0.005) {
            return number_format(0.0, 1, '.', '');
        }
        return number_format($f, 2, '.', '');
    };

    $priceDisplay = $fmtMoney($priceStr);
    ?>
    <div class="container my-4" style="max-width:780px;">
        <h1 class="h3 mb-2">Checkout</h1>
        <div class="text-muted small mb-3">Secure payment for premium content.</div>

        <div class="card">
            <div class="card-body">
                <div class="mb-2"><strong>Item:</strong> <?= $h($title); ?></div>
                <div class="mb-3"><strong>Price:</strong> $<?= $h($priceDisplay); ?></div>

                <div id="checkoutError" class="alert alert-danger" style="display:none;"></div>

                <button id="payBtn" class="btn btn-primary" type="button">
                    Continue
                </button>

                <a class="btn btn-link" href="<?= $type === 'media' ? '/media' : '/posts'; ?>">Cancel</a>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var btn = document.getElementById('payBtn');
            var err = document.getElementById('checkoutError');

            function showErr(msg) {
                if (!err) return;
                err.textContent = msg || 'Request failed.';
                err.style.display = '';
            }

            if (!btn) return;

            btn.addEventListener('click', function () {
                btn.disabled = true;
                if (err) err.style.display = 'none';

                fetch('/checkout/create-session', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        contentType: <?= json_encode($type); ?>,
                        contentId: <?= (int)$id; ?>
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        btn.disabled = false;
                        showErr((data && data.error) ? data.error : 'Request failed.');
                        return;
                    }

                    if (data.url) {
                        window.location.href = data.url;
                        return;
                    }

                    btn.disabled = false;
                    showErr('Request failed.');
                })
                .catch(function () {
                    btn.disabled = false;
                    showErr('Request failed.');
                });
            });
        })();
    </script>
    <?php
})();

