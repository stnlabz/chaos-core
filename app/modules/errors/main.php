<?php
declare(strict_types=1);

/**
 * Chaos CMS - Errors Module
 * Handles: 404, 403, 500, 503
 * 
 * Usage:
 *   Set $_SERVER['ERROR_CODE'] and $_SERVER['ERROR_MESSAGE'] before requiring this file.
 *   Or let it detect from http_response_code().
 */

// Detect error code
$errorCode = (int) ($_SERVER['ERROR_CODE'] ?? http_response_code());
$errorMessage = (string) ($_SERVER['ERROR_MESSAGE'] ?? '');

// Default messages if not provided
$messages = [
    403 => 'Access Forbidden',
    404 => 'Page Not Found',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable',
];

if ($errorMessage === '' && isset($messages[$errorCode])) {
    $errorMessage = $messages[$errorCode];
}

// Check maintenance/update status for 503
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3), '/\\');
$updateLock = $docroot . '/app/data/update.lock';
$maintFlag = $docroot . '/app/data/maintenance.flag';

$isUpdate = is_file($updateLock);
$isMaintenance = is_file($maintFlag);

// Escape helper
$e = static function (string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
};

?>
<div class="container my-4">
    <div class="error-page">
        <div class="error-card">
            <?php if ($errorCode === 503 && ($isUpdate || $isMaintenance)): ?>
                <!-- Maintenance/Update Mode -->
                <div class="error-icon">🔧</div>
                <h1 class="error-title">
                    <?= $isUpdate ? 'System Update in Progress' : 'Scheduled Maintenance' ?>
                </h1>
                <p class="error-description">
                    <?php if ($isUpdate): ?>
                        The system is currently being updated. This should only take a few minutes.
                    <?php else: ?>
                        The site is temporarily unavailable for scheduled maintenance.
                    <?php endif; ?>
                </p>
                <p class="error-note">
                    Please check back shortly. We apologize for any inconvenience.
                </p>
                
            <?php elseif ($errorCode === 404): ?>
                <!-- 404 Not Found -->
                <div class="error-icon">🔍</div>
                <h1 class="error-title">404 - <?= $e($errorMessage) ?></h1>
                <p class="error-description">
                    The page you're looking for doesn't exist or has been moved.
                </p>
                <div class="error-actions">
                    <a href="/" class="btn btn-primary">Return Home</a>
                    <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
                </div>
                
            <?php elseif ($errorCode === 403): ?>
                <!-- 403 Forbidden -->
                <div class="error-icon">🚫</div>
                <h1 class="error-title">403 - <?= $e($errorMessage) ?></h1>
                <p class="error-description">
                    You don't have permission to access this resource.
                </p>
                <?php if (isset($auth) && $auth instanceof auth && !$auth->check()): ?>
                    <div class="error-actions">
                        <a href="/login" class="btn btn-primary">Login</a>
                        <a href="/" class="btn btn-secondary">Return Home</a>
                    </div>
                <?php else: ?>
                    <div class="error-actions">
                        <a href="/" class="btn btn-primary">Return Home</a>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($errorCode === 500): ?>
                <!-- 500 Server Error -->
                <div class="error-icon">⚠️</div>
                <h1 class="error-title">500 - <?= $e($errorMessage) ?></h1>
                <p class="error-description">
                    Something went wrong on our end. We're working to fix it.
                </p>
                <p class="error-note">
                    If this problem persists, please contact the site administrator.
                </p>
                <div class="error-actions">
                    <a href="/" class="btn btn-primary">Return Home</a>
                </div>
                
            <?php else: ?>
                <!-- Generic Error -->
                <div class="error-icon">❌</div>
                <h1 class="error-title">
                    <?= $errorCode > 0 ? $errorCode . ' - ' : '' ?><?= $e($errorMessage ?: 'An error occurred') ?>
                </h1>
                <p class="error-description">
                    An unexpected error has occurred.
                </p>
                <div class="error-actions">
                    <a href="/" class="btn btn-primary">Return Home</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.error-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 60vh;
    padding: 2rem 0;
}

.error-card {
    max-width: 600px;
    text-align: center;
    padding: 3rem 2rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.error-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.8;
}

.error-title {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 1rem;
    color: inherit;
}

.error-description {
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
    opacity: 0.8;
}

.error-note {
    font-size: 0.9rem;
    margin-bottom: 2rem;
    opacity: 0.6;
}

.error-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
}

.error-actions .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.error-actions .btn-primary {
    background: #2563eb;
    color: white;
    border: none;
}

.error-actions .btn-primary:hover {
    background: #1d4ed8;
}

.error-actions .btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: inherit;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.error-actions .btn-secondary:hover {
    background: rgba(255, 255, 255, 0.15);
}
</style>
