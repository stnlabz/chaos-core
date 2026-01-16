<?php

declare(strict_types=1);

/**
 * Chaos CMS - Checkout Module
 * 
 * Route: /checkout
 * 
 * Handles checkout pages only.
 * API endpoint (/checkout/create-session) is handled in router.php
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        ob_end_clean();
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Database not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        ob_end_clean();
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Database connection failed.</div></div>';
        return;
    }

    // Parse URL segments
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
    
    $moduleSlug = $segments[0] ?? ''; // 'checkout'
    $action = $segments[1] ?? '';     // 'create-session', 'success', 'cancel', or empty

    // Safety check
    if ($moduleSlug !== 'checkout') {
        ob_end_clean();
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-danger">Invalid route.</div></div>';
        return;
    }

    // Helper function for escaping
    $h = static function(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    // Check if user is logged in
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

    // Route to appropriate handler
    switch ($action) {
        case '':
            // Main checkout page
            checkout_main($db, $conn, $loggedIn, $userId, $h);
            break;

        case 'create-session':
            // This should never be reached - handled at top of file
            http_response_code(500);
            echo '<div class="container my-4"><div class="alert alert-danger">API endpoint error</div></div>';
            break;

        case 'success':
            // Success page
            checkout_success($h);
            break;

        case 'cancel':
            // Cancel page
            checkout_cancel($h);
            break;

        default:
            http_response_code(404);
            echo '<div class="container my-4"><div class="alert alert-danger">Page not found.</div></div>';
            break;
    }
})();

/**
 * Main checkout page
 */
function checkout_main(db $db, mysqli $conn, bool $loggedIn, ?int $userId, callable $h): void
{
    if (!$loggedIn || $userId === null) {
        header('Location: /login');
        exit;
    }

    // Get checkout parameters
    $type = (string)($_GET['type'] ?? ''); // 'post' or 'media'
    $id = (int)($_GET['id'] ?? 0);
    $price = (float)($_GET['price'] ?? 0);

    if ($type === '' || $id <= 0 || $price <= 0) {
        http_response_code(400);
        echo '<div class="container my-4"><div class="alert alert-danger">Invalid checkout parameters.</div></div>';
        return;
    }

    // Validate content exists and get details
    $contentTitle = '';
    $contentExists = false;

    if ($type === 'post') {
        $stmt = $conn->prepare("SELECT title, is_premium, price FROM posts WHERE id=? LIMIT 1");
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $contentTitle = (string)($row['title'] ?? 'Post #' . $id);
                
                $isPremium = (int)($row['is_premium'] ?? 0);
                $actualPrice = (float)($row['price'] ?? 0);
                
                // Debug output (remove after testing)
                error_log("Checkout Debug - Post ID: $id, isPremium: $isPremium, actualPrice: $actualPrice, urlPrice: $price");
                
                // Validate it's premium and price matches (allow 1 cent tolerance for float precision)
                if ($isPremium === 1 && abs($actualPrice - $price) <= 0.01) {
                    $contentExists = true;
                }
            }
            $stmt->close();
        }
    } elseif ($type === 'media') {
        $stmt = $conn->prepare("SELECT title, is_premium, price, tier_required FROM media_gallery WHERE id=? LIMIT 1");
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $contentTitle = (string)($row['title'] ?? 'Media #' . $id);
                
                $isPremium = (int)($row['is_premium'] ?? 0);
                $actualPrice = (float)($row['price'] ?? 0);
                $tierRequired = (string)($row['tier_required'] ?? 'free');
                
                // Debug output
                error_log("Checkout Debug - Media ID: $id, isPremium: $isPremium, actualPrice: $actualPrice, urlPrice: $price, tierRequired: $tierRequired");
                
                // Validate it's premium and price matches (allow 1 cent tolerance for float precision)
                if ($isPremium === 1 && abs($actualPrice - $price) <= 0.01) {
                    $contentExists = true;
                } else {
                    // Show detailed error for debugging
                    echo '<div class="container my-4"><div class="alert alert-warning">';
                    echo '<h4>Debug Info:</h4>';
                    echo 'Media ID: ' . $id . '<br>';
                    echo 'Title: ' . $h($contentTitle) . '<br>';
                    echo 'is_premium in DB: ' . $isPremium . ' (expected: 1)<br>';
                    echo 'price in DB: $' . number_format($actualPrice, 2) . ' (expected: $' . number_format($price, 2) . ')<br>';
                    echo 'tier_required: ' . $h($tierRequired) . '<br>';
                    echo 'Price difference: ' . abs($actualPrice - $price) . '<br>';
                    echo '</div></div>';
                }
            } else {
                echo '<div class="container my-4"><div class="alert alert-danger">No media found with ID: ' . $id . '</div></div>';
            }
            $stmt->close();
        } else {
            echo '<div class="container my-4"><div class="alert alert-danger">Database query failed</div></div>';
        }
    }

    if (!$contentExists) {
        if ($type !== '') {
            // Already showed debug info above, just return
            return;
        }
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-danger">Content not found or not available for purchase. (ID: ' . $id . ', Type: ' . $h($type) . ', Price: $' . number_format($price, 2) . ')</div></div>';
        return;
    }

    // Check if user already purchased this
    $stmt = $conn->prepare("
        SELECT id FROM content_purchases 
        WHERE user_id=? AND content_type=? AND content_id=? AND status='completed'
        LIMIT 1
    ");
    
    if ($stmt !== false) {
        $stmt->bind_param('isi', $userId, $type, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $stmt->close();
            $redirectUrl = $type === 'post' ? '/posts' : '/media';
            header('Location: ' . $redirectUrl . '?already_purchased=1');
            exit;
        }
        $stmt->close();
    }

    // Get Stripe settings
    $stripeEnabled = false;
    $stripePublishableKey = '';

    $settingsRows = $db->fetch_all("
        SELECT name, value FROM settings 
        WHERE name IN ('stripe_enabled', 'stripe_publishable_key')
    ");
    
    if (is_array($settingsRows)) {
        foreach ($settingsRows as $row) {
            $name = (string)($row['name'] ?? '');
            $value = (string)($row['value'] ?? '');
            
            if ($name === 'stripe_enabled') {
                $stripeEnabled = ($value === '1');
            } elseif ($name === 'stripe_publishable_key') {
                $stripePublishableKey = $value;
            }
        }
    }

    if (!$stripeEnabled || $stripePublishableKey === '') {
        echo '<div class="container my-4"><div class="alert alert-warning">Payment system is not configured. Please contact support.</div></div>';
        return;
    }

    $amountCents = (int)($price * 100);
    
    ?>
    <div class="container my-4" style="max-width: 600px;">
        <div class="card">
            <div class="card-body">
                <h1 class="h3 mb-3">Complete Your Purchase</h1>
                
                <?php if (isset($_GET['canceled'])): ?>
                    <div class="alert alert-warning mb-3">
                        Payment was canceled. Click the button below to try again.
                    </div>
                <?php endif; ?>

                <div class="mb-4">
                    <h2 class="h5">Order Summary</h2>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span><?= $h($contentTitle); ?></span>
                        <strong>$<?= number_format($price, 2); ?></strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <strong>Total</strong>
                        <strong>$<?= number_format($price, 2); ?></strong>
                    </div>
                </div>

                <div id="stripe-checkout-container">
                    <p class="text-muted mb-3">Click the button below to proceed to secure payment:</p>
                    
                    <button 
                        type="button" 
                        id="checkout-button" 
                        class="btn btn-primary btn-lg w-100"
                        data-user-id="<?= $userId; ?>"
                        data-content-type="<?= $h($type); ?>"
                        data-content-id="<?= $id; ?>"
                        data-amount="<?= $amountCents; ?>"
                        data-title="<?= $h($contentTitle); ?>"
                    >
                        Pay $<?= number_format($price, 2); ?> with Stripe
                    </button>
                    
                    <div class="mt-3 text-center">
                        <a href="/<?= $h($type); ?>" class="text-muted small">Cancel and go back</a>
                    </div>

                    <div id="error-message" class="alert alert-danger mt-3" style="display: none;"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe('<?= $h($stripePublishableKey); ?>');
        const checkoutButton = document.getElementById('checkout-button');
        const errorMessage = document.getElementById('error-message');

        checkoutButton.addEventListener('click', async function() {
            checkoutButton.disabled = true;
            checkoutButton.textContent = 'Processing...';
            errorMessage.style.display = 'none';

            try {
                const response = await fetch('/checkout/create-session', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        userId: checkoutButton.dataset.userId,
                        contentType: checkoutButton.dataset.contentType,
                        contentId: checkoutButton.dataset.contentId,
                        amount: checkoutButton.dataset.amount,
                        title: checkoutButton.dataset.title
                    })
                });

                const session = await response.json();

                if (session.error) {
                    throw new Error(session.error);
                }

                const result = await stripe.redirectToCheckout({
                    sessionId: session.id
                });

                if (result.error) {
                    throw new Error(result.error.message);
                }
            } catch (error) {
                errorMessage.textContent = error.message || 'An error occurred. Please try again.';
                errorMessage.style.display = 'block';
                checkoutButton.disabled = false;
                checkoutButton.textContent = 'Pay $<?= number_format($price, 2); ?> with Stripe';
            }
        });
    </script>
    <?php
}

/**
 * Create Stripe Checkout Session (API endpoint)
 */
function checkout_create_session(db $db, mysqli $conn, bool $loggedIn, ?int $userId): void
{
    header('Content-Type: application/json');

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

    // Build success and cancel URLs
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    
    $successUrl = $scheme . '://' . $host . '/checkout/success?type=' . $contentType;
    $cancelUrl = $scheme . '://' . $host . '/checkout?type=' . $contentType . '&id=' . $contentId . '&canceled=1';

    // Create Stripe Checkout Session using cURL
    $data = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => $title,
                ],
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
    exit; // Stop execution immediately
}

/**
 * Success page after payment
 */
function checkout_success(callable $h): void
{
    $type = (string)($_GET['type'] ?? 'media');
    $redirectUrl = $type === 'post' ? '/posts' : '/media';
    
    ?>
    <div class="container my-4" style="max-width: 600px;">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size: 4rem; color: #22c55e; margin-bottom: 1rem;">✓</div>
                <h1 class="h3 mb-3">Payment Successful!</h1>
                <p class="mb-4">Your purchase has been completed. You now have access to this content.</p>
                <a href="<?= $h($redirectUrl); ?>" class="btn btn-primary">View Content</a>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Cancel page
 */
function checkout_cancel(callable $h): void
{
    $type = (string)($_GET['type'] ?? 'media');
    $id = (int)($_GET['id'] ?? 0);
    $price = (float)($_GET['price'] ?? 0);
    
    $retryUrl = '/checkout?type=' . $type . '&id=' . $id . '&price=' . $price;
    $backUrl = $type === 'post' ? '/posts' : '/media';
    
    ?>
    <div class="container my-4" style="max-width: 600px;">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size: 4rem; color: #f59e0b; margin-bottom: 1rem;">⚠</div>
                <h1 class="h3 mb-3">Payment Canceled</h1>
                <p class="mb-4">Your payment was canceled. No charges were made.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <a href="<?= $h($retryUrl); ?>" class="btn btn-primary">Try Again</a>
                    <a href="<?= $h($backUrl); ?>" class="btn btn-outline-secondary">Go Back</a>
                </div>
            </div>
        </div>
    </div>
    <?php
}
