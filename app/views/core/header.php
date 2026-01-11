<?php
declare(strict_types=1);

/**
 * Core Header
 * Owns ALL page structure opening.
 */

$siteName = trim((string) (class_exists('themes') ? themes::get('site_name') : ''));
if ($siteName === '') {
    $siteName = 'Chaos CMS';
}

$title = trim((string) (class_exists('themes') ? themes::get('title') : ''));
if ($title === '') {
    $title = $siteName;
}

$bodyClass = trim((string) (class_exists('themes') ? themes::get('body_class') : ''));

$meta = class_exists('themes') ? themes::get('meta') : [];
if (!is_array($meta)) {
    $meta = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>

<?php foreach ($meta as $name => $content): ?>
    <?php if ($name !== '' && $content !== ''): ?>
        <meta name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
              content="<?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
<?php endforeach; ?>

<?= class_exists('themes') ? themes::css_links() : ''; ?>
</head>

<body<?= $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
<div class="site-wrap">

<header class="site-header">
<?php
// Nav is CONTENT ONLY
if (class_exists('themes')) {
    themes::render_nav();
}
?>
</header>

<main class="site-main">

