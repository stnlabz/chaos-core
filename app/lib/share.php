<?php

declare(strict_types=1);

/**
 * Chaos CMS — Share Buttons
 *
 * Theme-neutral share bar:
 * - No Bootstrap dependency
 * - No icon-font dependency
 * - Styling handled by /public/assets/css/core.css
 *
 * Usage:
 *   echo share_buttons($absoluteUrl, $title);
 */

if (!function_exists('share_buttons')) {
    /**
     * Render share buttons for a given absolute URL.
     *
     * @param string $absUrl Absolute URL to share.
     * @param string $title  Title text (optional).
     *
     * @return string
     */
    function share_buttons(string $absUrl, string $title = ''): string
    {
        $absUrl = trim($absUrl);
        $title  = trim($title);

        if ($absUrl === '') {
            return '';
        }

        $u = rawurlencode($absUrl);
        $t = rawurlencode($title);

        $links = [
            ['label' => 'X',        'href' => "https://twitter.com/intent/tweet?url={$u}&text={$t}"],
            ['label' => 'Facebook', 'href' => "https://www.facebook.com/sharer/sharer.php?u={$u}"],
            ['label' => 'LinkedIn', 'href' => "https://www.linkedin.com/sharing/share-offsite/?url={$u}"],
            ['label' => 'Reddit',   'href' => "https://www.reddit.com/submit?url={$u}&title={$t}"],
            ['label' => 'Email',    'href' => "mailto:?subject={$t}&body={$u}"],
        ];

        $h = static function (string $v): string {
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        };

        $out  = '<div class="share-bar" aria-label="Share">' . PHP_EOL;
        $out .= '  <span class="share-label">Share</span>' . PHP_EOL;

        foreach ($links as $a) {
            $label = (string) ($a['label'] ?? '');
            $href  = (string) ($a['href'] ?? '');

            if ($label === '' || $href === '') {
                continue;
            }

            $out .= '  <a class="share-pill share-link" href="' . $h($href) . '" target="_blank" rel="noopener noreferrer">'
                . $h($label)
                . '</a>' . PHP_EOL;
        }

        $out .= '  <button type="button" class="share-pill share-copy" data-url="' . $h($absUrl) . '">Copy link</button>' . PHP_EOL;
        $out .= '</div>' . PHP_EOL;

        $out .= '<script>
(function () {
    "use strict";

    document.addEventListener("click", function (e) {
        var btn = e.target && e.target.closest ? e.target.closest(".share-copy") : null;
        if (!btn) {
            return;
        }

        var url = btn.getAttribute("data-url") || window.location.href;
        var original = btn.getAttribute("data-label") || btn.textContent || "Copy link";

        function flash(text) {
            btn.textContent = text;
            window.setTimeout(function () {
                btn.textContent = original;
            }, 1200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                flash("Copied");
            }).catch(function () {
                flash("Copy failed");
            });

            return;
        }

        var ta = document.createElement("textarea");
        ta.value = url;
        ta.setAttribute("readonly", "readonly");
        ta.style.position = "absolute";
        ta.style.left = "-9999px";
        document.body.appendChild(ta);
        ta.select();

        try {
            document.execCommand("copy");
            flash("Copied");
        } catch (err) {
            flash("Copy failed");
        }

        document.body.removeChild(ta);
    });
})();
</script>';

        return $out;
    }
}

