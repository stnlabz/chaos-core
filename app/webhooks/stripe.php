<?php

declare(strict_types=1);

/**
 * Chaos CMS - Stripe Webhook Handler
 * 
 * Route: /webhooks/stripe
 * 
 * Handles Stripe webhook events for:
 * - payment_intent.succeeded (content purchases)
 * - payment_intent.payment_failed
 * - customer.subscription.created
 * - customer.subscription.updated
 * - customer.subscription.deleted
 */

(function (): void {
    global $db;

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo json_encode(['error' => 'Database not available']);
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        return;
    }

    // Get the raw POST body
    $payload = @file_get_contents('php://input');
    if ($payload === false) {
        http_response_code(400);
        echo json_encode(['error' => 'No payload']);
        return;
    }

    // Get Stripe signature header
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    // Get webhook secret from settings table
    $webhookSecret = '';
    $platformFeePercent = 10.0; // Default 10%
    
    $settingsRows = $db->fetch_all("SELECT name, value FROM settings WHERE name IN ('stripe_webhook_secret', 'platform_fee_percent')");
    if (is_array($settingsRows)) {
        foreach ($settingsRows as $row) {
            $name = (string)($row['name'] ?? '');
            $value = (string)($row['value'] ?? '');
            
            if ($name === 'stripe_webhook_secret') {
                $webhookSecret = $value;
            } elseif ($name === 'platform_fee_percent') {
                $platformFeePercent = (float)$value;
            }
        }
    }
    
    // Parse the JSON payload
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    // Verify the webhook signature (IMPORTANT: Enable this in production)
    if ($webhookSecret !== '' && $sigHeader !== '') {
        // Stripe signature verification would go here
        // For now, we'll skip it for testing but MUST add in production
        // TODO: Implement Stripe signature verification
        // See: https://stripe.com/docs/webhooks/signatures
    }

    $eventType = (string)($event['type'] ?? '');
    $eventId = (string)($event['id'] ?? '');
    $data = $event['data']['object'] ?? [];

    // Log the webhook for debugging
    error_log("Stripe Webhook: {$eventType} - {$eventId}");

    /**
     * Handle checkout.session.completed - This fires when payment succeeds
     */
    if ($eventType === 'checkout.session.completed') {
        $sessionId = (string)($data['id'] ?? '');
        $paymentIntentId = (string)($data['payment_intent'] ?? '');
        $amountCents = (int)($data['amount_total'] ?? 0);
        $currency = (string)($data['currency'] ?? 'usd');
        $metadata = $data['metadata'] ?? [];
        
        // For checkout sessions, metadata is on the session itself
        if (empty($metadata)) {
            // Try to get from payment_intent_data if it exists
            $metadata = $data['payment_intent_data']['metadata'] ?? [];
        }
        
        error_log("Checkout Session Metadata: " . json_encode($metadata));
        
        $userId = (int)($metadata['user_id'] ?? 0);
        $contentType = (string)($metadata['content_type'] ?? '');
        $contentId = (int)($metadata['content_id'] ?? 0);
        
        error_log("Parsed: userId=$userId, contentType=$contentType, contentId=$contentId");
        
        if ($userId > 0 && $contentType !== '' && $contentId > 0) {
            $amount = $amountCents / 100;
            $platformFee = $amount * ($platformFeePercent / 100);
            $creatorPayout = $amount - $platformFee;
            
            // Create content_purchases record
            $stmt = $conn->prepare("
                INSERT INTO content_purchases 
                (user_id, content_type, content_id, amount, platform_fee, creator_payout, 
                 stripe_payment_intent_id, status, purchased_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'completed',
                    purchased_at = NOW()
            ");
            
            if ($stmt !== false) {
                $stmt->bind_param(
                    'isiddds',
                    $userId,
                    $contentType,
                    $contentId,
                    $amount,
                    $platformFee,
                    $creatorPayout,
                    $paymentIntentId
                );
                $stmt->execute();
                $stmt->close();
            }
            
            // Get creator info
            $creatorId = 0;
            $creatorUsername = '';
            
            if ($contentType === 'post') {
                $row = $db->fetch("
                    SELECT p.author_id, u.username 
                    FROM posts p 
                    LEFT JOIN users u ON u.id = p.author_id
                    WHERE p.id = {$contentId} 
                    LIMIT 1
                ");
                if (is_array($row)) {
                    $creatorId = (int)($row['author_id'] ?? 0);
                    $creatorUsername = (string)($row['username'] ?? '');
                }
            } elseif ($contentType === 'media') {
                $row = $db->fetch("
                    SELECT g.uploader_id, u.username 
                    FROM media_gallery g 
                    LEFT JOIN users u ON u.id = g.uploader_id
                    WHERE g.id = {$contentId} 
                    LIMIT 1
                ");
                if (is_array($row)) {
                    $creatorId = (int)($row['uploader_id'] ?? 0);
                    $creatorUsername = (string)($row['username'] ?? '');
                }
            }
            
            // Record in finance_ledger
            if ($creatorId > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO finance_ledger 
                    (user_id, creator_id, creator_username, amount_cents, currency, 
                     kind, status, ref_type, ref_id, note, created_at)
                    VALUES (?, ?, ?, ?, ?, 'purchase', 'paid', ?, ?, ?, NOW())
                ");
                
                if ($stmt !== false) {
                    $note = "Content purchase via Stripe";
                    $stmt->bind_param(
                        'iisissis',
                        $userId,
                        $creatorId,
                        $creatorUsername,
                        $amountCents,
                        $currency,
                        $contentType,
                        $contentId,
                        $note
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            error_log("Payment processed: User {$userId} purchased {$contentType} {$contentId}");
        } else {
            error_log("Webhook error: Missing metadata - userId=$userId, contentType=$contentType, contentId=$contentId");
        }
        
        http_response_code(200);
        echo json_encode(['received' => true, 'event' => $eventType]);
        return;
    }

    /**
     * Handle payment_intent.succeeded - One-time content purchase
     */
    if ($eventType === 'payment_intent.succeeded') {
        $paymentIntentId = (string)($data['id'] ?? '');
        $amountCents = (int)($data['amount'] ?? 0);
        $currency = (string)($data['currency'] ?? 'usd');
        $metadata = $data['metadata'] ?? [];
        
        // Log metadata for debugging
        error_log("Payment Intent Metadata: " . json_encode($metadata));
        
        $userId = (int)($metadata['user_id'] ?? 0);
        $contentType = (string)($metadata['content_type'] ?? ''); // 'post' or 'media'
        $contentId = (int)($metadata['content_id'] ?? 0);
        
        error_log("Parsed: userId=$userId, contentType=$contentType, contentId=$contentId");
        
        if ($userId > 0 && $contentType !== '' && $contentId > 0) {
            // Get content info to calculate platform fee
            $amount = $amountCents / 100;
            $platformFee = $amount * ($platformFeePercent / 100);
            $creatorPayout = $amount - $platformFee;
            
            // Update or create content_purchases record
            $stmt = $conn->prepare("
                INSERT INTO content_purchases 
                (user_id, content_type, content_id, amount, platform_fee, creator_payout, 
                 stripe_payment_intent_id, status, purchased_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'completed',
                    purchased_at = NOW()
            ");
            
            if ($stmt !== false) {
                $stmt->bind_param(
                    'isiddds',
                    $userId,
                    $contentType,
                    $contentId,
                    $amount,
                    $platformFee,
                    $creatorPayout,
                    $paymentIntentId
                );
                $stmt->execute();
                $stmt->close();
            }
            
            // Get creator_id from content
            $creatorId = 0;
            $creatorUsername = '';
            
            if ($contentType === 'post') {
                $row = $db->fetch("
                    SELECT p.author_id, u.username 
                    FROM posts p 
                    LEFT JOIN users u ON u.id = p.author_id
                    WHERE p.id = {$contentId} 
                    LIMIT 1
                ");
                if (is_array($row)) {
                    $creatorId = (int)($row['author_id'] ?? 0);
                    $creatorUsername = (string)($row['username'] ?? '');
                }
            } elseif ($contentType === 'media') {
                $row = $db->fetch("
                    SELECT g.uploader_id, u.username 
                    FROM media_gallery g 
                    LEFT JOIN users u ON u.id = g.uploader_id
                    WHERE g.id = {$contentId} 
                    LIMIT 1
                ");
                if (is_array($row)) {
                    $creatorId = (int)($row['uploader_id'] ?? 0);
                    $creatorUsername = (string)($row['username'] ?? '');
                }
            }
            
            // Record in finance_ledger
            if ($creatorId > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO finance_ledger 
                    (user_id, creator_id, creator_username, amount_cents, currency, 
                     kind, status, ref_type, ref_id, note, created_at)
                    VALUES (?, ?, ?, ?, ?, 'purchase', 'paid', ?, ?, ?, NOW())
                ");
                
                if ($stmt !== false) {
                    $note = "Content purchase via Stripe";
                    $stmt->bind_param(
                        'iisissis',
                        $userId,
                        $creatorId,
                        $creatorUsername,
                        $amountCents,
                        $currency,
                        $contentType,
                        $contentId,
                        $note
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            error_log("Payment processed: User {$userId} purchased {$contentType} {$contentId}");
        } else {
            error_log("Webhook error: Missing metadata - userId=$userId, contentType=$contentType, contentId=$contentId");
        }
    }

    /**
     * Handle payment_intent.payment_failed
     */
    elseif ($eventType === 'payment_intent.payment_failed') {
        $paymentIntentId = (string)($data['id'] ?? '');
        
        // Mark payment as failed
        $stmt = $conn->prepare("
            UPDATE content_purchases 
            SET status = 'failed' 
            WHERE stripe_payment_intent_id = ? 
            LIMIT 1
        ");
        
        if ($stmt !== false) {
            $stmt->bind_param('s', $paymentIntentId);
            $stmt->execute();
            $stmt->close();
        }
        
        error_log("Payment failed: {$paymentIntentId}");
    }

    /**
     * Handle customer.subscription.created
     */
    elseif ($eventType === 'customer.subscription.created') {
        $subscriptionId = (string)($data['id'] ?? '');
        $customerId = (string)($data['customer'] ?? '');
        $status = (string)($data['status'] ?? 'incomplete');
        $currentPeriodEnd = (int)($data['current_period_end'] ?? 0);
        $metadata = $data['metadata'] ?? [];
        
        $userId = (int)($metadata['user_id'] ?? 0);
        $tierId = (int)($metadata['tier_id'] ?? 0);
        
        if ($userId > 0 && $tierId > 0) {
            $expiresAt = $currentPeriodEnd > 0 ? date('Y-m-d H:i:s', $currentPeriodEnd) : null;
            
            $stmt = $conn->prepare("
                INSERT INTO user_subscriptions 
                (user_id, tier_id, stripe_subscription_id, stripe_customer_id, 
                 status, expires_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    expires_at = VALUES(expires_at),
                    updated_at = NOW()
            ");
            
            if ($stmt !== false) {
                $stmt->bind_param(
                    'iissss',
                    $userId,
                    $tierId,
                    $subscriptionId,
                    $customerId,
                    $status,
                    $expiresAt
                );
                $stmt->execute();
                $stmt->close();
            }
            
            error_log("Subscription created: User {$userId} - Tier {$tierId}");
        }
    }

    /**
     * Handle customer.subscription.updated
     */
    elseif ($eventType === 'customer.subscription.updated') {
        $subscriptionId = (string)($data['id'] ?? '');
        $status = (string)($data['status'] ?? 'active');
        $currentPeriodEnd = (int)($data['current_period_end'] ?? 0);
        
        $expiresAt = $currentPeriodEnd > 0 ? date('Y-m-d H:i:s', $currentPeriodEnd) : null;
        
        $stmt = $conn->prepare("
            UPDATE user_subscriptions 
            SET status = ?, expires_at = ?, updated_at = NOW()
            WHERE stripe_subscription_id = ?
            LIMIT 1
        ");
        
        if ($stmt !== false) {
            $stmt->bind_param('sss', $status, $expiresAt, $subscriptionId);
            $stmt->execute();
            $stmt->close();
        }
        
        error_log("Subscription updated: {$subscriptionId} - Status: {$status}");
    }

    /**
     * Handle customer.subscription.deleted
     */
    elseif ($eventType === 'customer.subscription.deleted') {
        $subscriptionId = (string)($data['id'] ?? '');
        
        $stmt = $conn->prepare("
            UPDATE user_subscriptions 
            SET status = 'canceled', updated_at = NOW()
            WHERE stripe_subscription_id = ?
            LIMIT 1
        ");
        
        if ($stmt !== false) {
            $stmt->bind_param('s', $subscriptionId);
            $stmt->execute();
            $stmt->close();
        }
        
        error_log("Subscription canceled: {$subscriptionId}");
    }

    // Return 200 OK to acknowledge receipt
    http_response_code(200);
    echo json_encode(['received' => true, 'event' => $eventType]);
})();
