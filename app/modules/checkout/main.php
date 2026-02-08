<?php
declare(strict_types=1);

/**
 * Chaos CMS — Core Module: Checkout
 *
 * Purpose:
 * - Handle paid access for MEDIA and POSTS only
 * - Stripe webhook writes to finance_ledger
 *
 * Routes:
 *   /checkout?type=media&id={id}
 *   /checkout?type=post&id={id}
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

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path !== '/checkout' && strpos($path, '/checkout') !== 0) {
        return;
    }

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    // Auth context
    $loggedIn = false;
    $userId = 0;
    if (isset($auth) && $auth instanceof auth) {
        $loggedIn = (bool)$auth->check();
        if ($loggedIn) {
            $uid = $auth->id();
            if (is_int($uid) && $uid > 0) {
                $userId = (int)$uid;
            }
        }
    }

    $type = (string)($_GET['type'] ?? '');
    $id = (int)($_GET['id'] ?? 0);

    if (!in_array($type, ['media', 'post'], true) || $id <= 0) {
        http_response_code(400);
        echo '<div class="container my-4"><div class="alert alert-danger">Invalid checkout parameters.</div></div>';
        return;
    }

    // Require login to purchase (matches how your entitlement/ledger works)
    if (!$loggedIn || $userId <= 0) {
        echo '<div class="container my-4">';
        echo '<div class="alert alert-warning">You must be logged in to purchase premium content.</div>';
        echo '<a class="btn btn-primary" href="/login">Log in</a>';
        echo '</div>';
        return;
    }

    // Pull item info + price from DB
    $title = '';
    $amountCents = 0;

    if ($type === 'media') {
        $stmt = $conn->prepare("
            SELECT g.title, g.price
            FROM media_gallery g
            WHERE g.id=?
            LIMIT 1
        ");
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
            echo '<div class="container my-4"><div class="alert alert-danger">Media item not found.</div></div>';
            return;
        }

        $title = (string)($row['title'] ?? '');
        $price = (string)($row['price'] ?? '');
        $amountCents = (int)round(((float)$price) * 100);
    } else {
        // Posts table name may differ in your build; this matches your checkout module intent.
        $stmt = $conn->prepare("
            SELECT p.title, p.price
            FROM posts p
            WHERE p.id=?
            LIMIT 1
        ");
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
            echo '<div class="container my-4"><div class="alert alert-danger">Post not found.</div></div>';
            return;
        }

        $title = (string)($row['title'] ?? '');
        $price = (string)($row['price'] ?? '');
        $amountCents = (int)round(((float)$price) * 100);
    }

    if ($amountCents <= 0) {
        http_response_code(400);
        echo '<div class="container my-4"><div class="alert alert-danger">This item is not priced for checkout.</div></div>';
        return;
    }

    $displayTitle = $title !== '' ? $title : (($type === 'media') ? ('Media #' . $id) : ('Post #' . $id));
    $displayAmount = number_format($amountCents / 100, 2);

    ?>
    <div class="container my-4" style="max-width: 820px;">
        <h1 class="h3">Checkout</h1>
        <div class="text-muted mb-3">Secure purchase via Stripe</div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-column gap-2">
                    <div><strong>Item:</strong> <?= $h($displayTitle); ?></div>
                    <div><strong>Type:</strong> <?= $h($type); ?></div>
                    <div><strong>Price:</strong> $<?= $h($displayAmount); ?></div>
                </div>

                <hr>

                <button class="btn btn-primary" id="btnPay">Pay with Stripe</button>
                <a class="btn btn-light" href="<?= $type === 'media' ? '/media' : '/posts'; ?>">Cancel</a>

                <div class="mt-3 small text-muted" id="payStatus"></div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var btn = document.getElementById('btnPay');
            var status = document.getElementById('payStatus');

            function setStatus(msg) {
                if (!status) return;
                status.textContent = msg || '';
            }

            btn.addEventListener('click', function () {
                btn.disabled = true;
                setStatus('Creating Stripe session...');

                fetch('/checkout/create-session', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        contentType: <?= json_encode($type); ?>,
                        contentId: <?= (int)$id; ?>,
                        amount: <?= (int)$amountCents; ?>
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || data.error) {
                        btn.disabled = false;
                        setStatus(data && data.error ? data.error : 'Request failed.');
                        return;
                    }

                    if (!data.url) {
                        btn.disabled = false;
                        setStatus('Stripe session missing URL.');
                        return;
                    }

                    // Redirect to Stripe Checkout URL
                    window.location.href = data.url;
                })
                .catch(function () {
                    btn.disabled = false;
                    setStatus('Request failed.');
                });
            });
        })();
    </script>
    <?php
})();

