<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Checkout (UI)
 *
 * Routes:
 *   /checkout?type=media&id=91
 *   /checkout?type=post&id=123
 *
 * Notes:
 * - UI renders inside theme.
 * - The Pay button calls /checkout/create-session (API) and redirects to Stripe.
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
    if (!$conn instanceof mysqli) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $type = strtolower(trim((string)($_GET['type'] ?? '')));
    $id = (int)($_GET['id'] ?? 0);

    if (!in_array($type, ['media', 'post'], true) || $id <= 0) {
        echo '<div class="container my-4"><div class="alert alert-warning">Invalid checkout parameters.</div></div>';
        return;
    }

    $loggedIn = false;
    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = (bool)$auth->check();
    }

    if (!$loggedIn) {
        echo '<div class="container my-4"><div class="alert alert-warning">Please log in to purchase.</div></div>';
        return;
    }

    $title = '';
    $priceStr = '';

    if ($type === 'media') {
        $stmt = $conn->prepare("SELECT title, price FROM media_gallery WHERE id=? LIMIT 1");
        if ($stmt === false) {
            echo '<div class="container my-4"><div class="alert alert-danger">DB error.</div></div>';
            return;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            echo '<div class="container my-4"><div class="alert alert-warning">Item not found.</div></div>';
            return;
        }

        $title = trim((string)($row['title'] ?? ''));
        $priceStr = (string)($row['price'] ?? '');
    } else {
        $stmt = $conn->prepare("SELECT title, price FROM posts WHERE id=? LIMIT 1");
        if ($stmt === false) {
            echo '<div class="container my-4"><div class="alert alert-danger">Post checkout is not supported on this install.</div></div>';
            return;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            echo '<div class="container my-4"><div class="alert alert-warning">Item not found.</div></div>';
            return;
        }

        $title = trim((string)($row['title'] ?? ''));
        $priceStr = (string)($row['price'] ?? '');
    }

    if ($title === '') {
        $title = ($type === 'media') ? ('Media #' . $id) : ('Post #' . $id);
    }

    $price = (float)$priceStr;
    if ($price <= 0) {
        echo '<div class="container my-4"><div class="alert alert-warning">This item is not purchasable (price is not set).</div></div>';
        return;
    }

    $priceDisplay = number_format($price, 2, '.', '');

    ?>
    <div class="container my-4" style="max-width: 720px;">
        <h1 class="h3 mb-2">Checkout</h1>
        <div class="text-muted mb-3">Secure purchase via Stripe</div>

        <div class="card">
            <div class="card-body">
                <div class="mb-2"><strong>Item:</strong> <?= $h($title); ?></div>
                <div class="mb-2"><strong>Type:</strong> <?= $h($type); ?></div>
                <div class="mb-3"><strong>Price:</strong> $<?= $h($priceDisplay); ?></div>

                <div id="checkoutError" class="alert alert-danger" style="display:none;"></div>

                <button class="btn btn-primary" id="payBtn" type="button">
                    Pay with Stripe
                </button>
                <a class="btn btn-light ms-2" href="/<?= $h($type === 'media' ? 'media' : 'posts'); ?>">Cancel</a>
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
                if (err) { err.style.display = 'none'; err.textContent = ''; }

                fetch('/checkout/create-session', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        type: '<?= $h($type); ?>',
                        id: <?= (int)$id; ?>
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok || !data.url) {
                        showErr((data && (data.message || data.error)) ? (data.message || data.error) : 'Request failed.');
                        btn.disabled = false;
                        return;
                    }
                    window.location.href = data.url;
                })
                .catch(function () {
                    showErr('Request failed.');
                    btn.disabled = false;
                });
            });
        })();
    </script>
    <?php
})();

