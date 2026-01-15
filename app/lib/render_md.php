<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Markdown Renderer
 * KISS engine for docs, changelogs and internal pages.
 *
 * Supported features:
 * - Headings: #..######
 * - Bold: **text**
 * - Italic/Emphasis: *text* or _text_
 * - Small: ~~text~~
 * - Inline code: `code`
 * - Fenced code blocks: ```lang
 * - Blockquotes: > text
 * - Unordered lists: -, *, +
 * - Ordered lists: 1. 2. 3.
 * - Links: [text](url)
 * - Images: ![alt](url)
 * - Horizontal rules: ---, ***, ___
 * - Newlines preserved outside code blocks
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
         * Supported:
         * - Headings: #..###### (space optional)
         * - Bold: **text**
         * - Italic/Emphasis: *text* or _text_
         * - Small: ~~text~~
         * - Inline code: `code`
         * - Fenced code: ```php / ```json / ```go / etc.
         * - Blockquotes: > text (multi-line)
         * - Unordered lists: -, *, +
         * - Ordered lists: 1. 2. 3.
         * - Links: [text](url)
         * - Images: ![alt](url)
         * - Horizontal rules: ---, ***, ___
         * - Newlines preserved outside <pre>.
         *
         * @param string $text
         * @return string
         */
        public function markdown(string $text): string
        {
            // 1) Escape HTML so we don't execute anything
            $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

            // 2) Fenced code blocks FIRST: ```lang\n...\n```
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

            // 3) Horizontal rules: ---, ***, ___
            $html = preg_replace('/^(?:[-*_]){3,}$/m', '<hr>', $html);

            // 4) Blockquotes: lines starting with &gt;
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

            // 5) Unordered lists: - item / * item / + item
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
                                $out .= '<li>' . $clean . '</li>';
                            }
                        }
                    }

                    $out .= '</ul>';
                    return $out;
                },
                $html
            );

            // 6) Ordered lists: 1. item / 2. item ...
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

            // 7) Headings (space optional after #) - BEFORE inline formatting
            $html = preg_replace('/^######\s*(.+)$/m', '<h6>$1</h6>', $html);
            $html = preg_replace('/^#####\s*(.+)$/m', '<h5>$1</h5>', $html);
            $html = preg_replace('/^####\s*(.+)$/m',  '<h4>$1</h4>', $html);
            $html = preg_replace('/^###\s*(.+)$/m',   '<h3>$1</h3>', $html);
            $html = preg_replace('/^##\s*(.+)$/m',    '<h2>$1</h2>', $html);
            $html = preg_replace('/^#\s*(.+)$/m',     '<h1>$1</h1>', $html);

            // 8) Images: ![alt](url) - BEFORE links
            $html = preg_replace_callback(
                '/!\[([^\]]*)\]\(([^\)]+)\)/',
                static function (array $matches): string {
                    $alt = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                    $url = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                    return '<img src="' . $url . '" alt="' . $alt . '">';
                },
                $html
            );

            // 9) Links: [text](url)
            $html = preg_replace_callback(
                '/\[([^\]]+)\]\(([^\)]+)\)/',
                static function (array $matches): string {
                    $text = $matches[1];
                    $url  = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                    return '<a href="' . $url . '">' . $text . '</a>';
                },
                $html
            );

            // 10) Inline code: `code`
            $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

            // 11) Bold: **text** - BEFORE italic to avoid conflicts
            $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

            // 12) Italic and Emphasis: *text* or _text_
            $html = preg_replace('/(?<!\*)\*(?!\s)([^*\n]+?)(?<!\s)\*(?!\*)/m', '<em>$1</em>', $html);
            $html = preg_replace('/(?<!_)_(?!\s)([^_\n]+?)(?<!\s)_(?!_)/m', '<em>$1</em>', $html);

            // 13) Small: ~~text~~
            $html = preg_replace('/~~(.+?)~~/s', '<small>$1</small>', $html);

            // 14) Newlines outside <pre> → <br>
            $parts = preg_split(
                '/(<pre><code.*?<\/code><\/pre>)/s',
                $html,
                -1,
                PREG_SPLIT_DELIM_CAPTURE
            );

            if ($parts === false) {
                return nl2br($html);
            }

            $out = '';

            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

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
