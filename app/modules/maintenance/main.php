<?php
declare(strict_types=1);

http_response_code(503);
$mode = isset($maint_mode) ? (string) $maint_mode : 'maintenance';

$title = 'Maintenance';
$h1    = 'Maintenance in progress';
$p     = 'The site is temporarily unavailable. Please check back shortly.';

if ($mode === 'update') {
    $title = 'Updating';
    $h1    = 'Update in progress';
    $p     = 'Core is updating right now. Please try again in a few minutes.';
}
?>

<div class="container">
   <h1><?= htmlspecialchars($h1, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
