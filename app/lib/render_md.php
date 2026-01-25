<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Markdown Renderer
 * KISS engine for docs, changelogs and internal pages.
 */

if (!class_exists('render_md')) {
    class render_md
    {
        /**
         * Render a Markdown file from disk.
         *
         * @param string $path
         * @return void
         */
        public function markdown_file(string $path): void
        {
            if (!is_file($path)) {
                echo '<p>Markdown file not found.</p>';
                return;
            }

            $raw = (string) file_get_contents($path);
            echo $this->markdown($raw);
        }

        /**
         * Render Markdown text into HTML.
         *
         * @param string $text
         * @return string
         */
        public function markdown(string $text): string
        {
            // 1) Escape HTML
            $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

            // 2) Fenced code blocks
            $html = preg_replace_callback(
                '/```(\w+)?\R([\s\S]*?)```/m',
                static function (array $matches): string {
                    $lang = trim((string) ($matches[1] ?? ''));
                    $code = (string) $matches[2];
                    $class = '';
                    if ($lang !== '') {
                        $class = ' class="code-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"';
                    }
                    return '<pre><code' . $class . '>' . $code . '</code></pre>';
                },
                $html
            );

            // 3) Horizontal rules
            $html = preg_replace('/^(?:[-*_]){3,}$/m', '<hr>', $html);

            // 4) Blockquotes
            $html = preg_replace_callback(
                '/^(?:&gt;\s?.+\R?)+/m',
                static function (array $matches): string {
                    $block  = $matches[0];
                    $lines  = preg_split('/\R/', trim($block));
                    $output = '<blockquote>';
                    if (is_array($lines)) {
                        foreach ($lines as $line) {
                            $clean = preg_replace('/^\s*&gt;\s?/', '', $line);
                            if ($clean !== '') {
                                $output .= $clean . '<br>';
                            }
                        }
                        $output = rtrim($output, '<br>');
                    }
                    $output .= '</blockquote>';
                    return $output;
                },
                $html
            );

            // 5) Unordered lists (with checklist support)
            $html = preg_replace_callback(
                '/^(?:\s*[-*+]\s+.+\R?)+/m',
                static function (array $matches): string {
                    $block = $matches[0];
                    $lines = preg_split('/\R/', trim($block));
                    $out   = '<ul>';
                    if (is_array($lines)) {
                        foreach ($lines as $line) {
                            $clean = preg_replace('/^\s*[-*+]\s+/', '', $line);
                            if ($clean !== '') {
                                $clean = preg_replace('/^\[x\]\s?/i', '<input type="checkbox" checked disabled> ', $clean);
                                $clean = preg_replace('/^\[ \]\s?/', '<input type="checkbox" disabled> ', $clean);
                                $out .= '<li>' . $clean . '</li>';
                            }
                        }
                    }
                    $out .= '</ul>';
                    return $out;
                },
                $html
            );

            // 6) Ordered lists
            $html = preg_replace_callback(
                '/^(?:\s*\d+\.\s+.+\R?)+/m',
                static function (array $matches): string {
                    $block = $matches[0];
                    $lines = preg_split('/\R/', trim($block));
                    $out   = '<ol>';
                    if (is_array($lines)) {
                        foreach ($lines as $line) {
                            $clean = preg_replace('/^\s*\d+\.\s+/', '', $line);
                            if ($clean !== '') {
                                $out .= '<li>' . $clean . '</li>';
                            }
                        }
                    }
                    $out .= '</ol>';
                    return $out;
                },
                $html
            );

            // 7) Headings
            $html = preg_replace_callback(
                '/(^|<li>)(#{1,6})\s*(.+?)(?:$|<\/li>)/m',
                static function (array $m): string {
                    $prefix = $m[1];
                    $level  = strlen(trim($m[2]));
                    $text   = $m[3];
                    $tag    = "h" . $level;
                    if (strpos($prefix, '<li>') !== false) {
                        return "<li><$tag>$text</$tag></li>";
                    }
                    return "<$tag>$text</$tag>";
                },
                $html
            );

            // 8) Images
            $html = preg_replace_callback(
                '/!\[([^\]]*)\]\(([^\)]+)\)/',
                static function (array $matches): string {
                    $alt = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                    $url = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                    return '<img src="' . $url . '" alt="' . $alt . '">';
                },
                $html
            );

            // 9) Links
            $html = preg_replace_callback(
                '/\[([^\]]+)\]\(([^\)]+)\)/',
                static function (array $matches): string {
                    $text = $matches[1];
                    $url  = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                    return '<a href="' . $url . '">' . $text . '</a>';
                },
                $html
            );

            // 10) Auto-link
            $html = preg_replace('~(?<![">])\b(https?://[^\s<]+|www\.[^\s<]+)(?<![.,:;])~i', '<a href="$1" target="_blank">$1</a>', $html);

            // 11) Inline code
            $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

            // 12) Bold
            $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html);

            // 13) Italic
            $html = preg_replace('/(?<!\*)\*(?!\s)([^*\n]+?)(?<!\s)\*(?!\*)/m', '<em>$1</em>', $html);
            $html = preg_replace('/(?<!_)_(?!\s)([^_\n]+?)(?<!\s)_(?!_)/m', '<em>$1</em>', $html);

            // 14) Strikethrough
            $html = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $html);

            // 15) Highlight
            $html = preg_replace('/==(.+?)==/s', '<mark>$1</mark>', $html);

            // 16) Superscript
            $html = preg_replace('/\^(.+?)\^/s', '<sup>$1</sup>', $html);

            // 17) Curly Attributes
            $html = preg_replace_callback(
                '/<(h[1-6]|li|p|mark|del)>(.*?)\s*\{([^}]+)\}/is',
                static function (array $m): string {
                    $tag = $m[1];
                    $content = $m[2];
                    $attr_str = trim($m[3]);
                    $classes = []; $id = '';
                    foreach (explode(' ', $attr_str) as $bit) {
                        if (strpos($bit, '.') === 0) $classes[] = substr($bit, 1);
                        if (strpos($bit, '#') === 0) $id = substr($bit, 1);
                    }
                    $attr_html = (!empty($classes) ? ' class="' . implode(' ', $classes) . '"' : '') . ($id ? ' id="' . $id . '"' : '');
                    return "<$tag$attr_html>$content</$tag>";
                },
                $html
            );

            // 18) Newlines outside <pre>
            $parts = preg_split('/(<pre><code.*?<\/code><\/pre>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) return nl2br($html);
            $out = '';
            foreach ($parts as $part) {
                if ($part === '') continue;
                if (strpos($part, '<pre><code') === 0) {
                    $out .= $part;
                } else {
                    $out .= nl2br($part);
                }
            }
            return $out;
        }
    }
}
