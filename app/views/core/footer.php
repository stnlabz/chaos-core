<?php
declare(strict_types=1);

/**
 * Core Footer
 * Closes structure opened by header.php
 */
?>
</main>

<footer class="site-footer">
    <div class="container">
        <div class="small text-muted">
            &copy; <?= date('Y'); ?> <?= htmlspecialchars((string)(class_exists('themes') ? themes::get('site_name') : 'Chaos CMS'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
</footer>

</div><!-- /.site-wrap -->
</body>
</html>

