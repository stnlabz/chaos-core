<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — JSON Page Renderer
 * Renders structured JSON as HTML pages.
 *
 * Supported shapes:
 * 1. Basic content:
 *    {
 *      "title": "...",
 *      "subtitle": "...",
 *      "content": { "intro": "...", "items": "...|[]", "footer": "..." }
 *    }
 *
 * 2. Sections-based:
 *    {
 *      "title": "...",
 *      "subtitle": "...",
 *      "sections": [
 *        { "heading": "...", "body": "...", "list": "...|[]", "ordered_list": [] }
 *      ]
 *    }
 *
 * 3. With metadata:
 *    {
 *      "meta": { "author": "...", "date": "...", "tags": [] },
 *      "title": "...",
 *      "sections": [...]
 *    }
 *
 * 4. With tables:
 *    {
 *      "sections": [
 *        { "table": { "headers": [], "rows": [[]] } }
 *      ]
 *    }
 *
 * 5. With images:
 *    {
 *      "sections": [
 *        { "image": "url", "caption": "..." }
 *      ]
 *    }
 *
 * KISS. No Markdown engine integration.
 */

if (!class_exists('render_json')) {
    class render_json
    {
        /**
         * Render a JSON file as HTML.
         *
         * @param string $path
         * @return void
         */
        public function json_file(string $path): void
        {
            if (!is_file($path)) {
                echo '<p>JSON page not found.</p>';
                return;
            }

            $raw  = file_get_contents($path);
            $data = json_decode((string) $raw, true);

            if (!is_array($data)) {
                echo '<pre>' . htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8') . '</pre>';
                return;
            }

            echo '<div class="json-page">';

            // Render metadata if present
            if (!empty($data['meta']) && is_array($data['meta'])) {
                $this->render_metadata($data['meta']);
            }

            $title    = $data['title']    ?? '';
            $subtitle = $data['subtitle'] ?? '';

            if ($title !== '') {
                echo '<h1>' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . '</h1>';
            }

            if ($subtitle !== '') {
                echo '<h3>' . htmlspecialchars((string) $subtitle, ENT_QUOTES, 'UTF-8') . '</h3>';
            }

            // Prefer "sections" if present
            if (!empty($data['sections']) && is_array($data['sections'])) {
                $this->render_sections($data['sections']);
            }
            // Legacy "content" format
            elseif (!empty($data['content']) && is_array($data['content'])) {
                $this->render_legacy_content($data['content']);
            } else {
                // Unknown shape: fallback to pretty JSON
                echo '<pre>' . htmlspecialchars(
                    json_encode($data, JSON_PRETTY_PRINT),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</pre>';
            }

            echo '</div>';
        }

        /**
         * Read and parse JSON file.
         *
         * @param string $path
         * @return array<string,mixed>
         */
        public function jread(string $path): array
        {
            if (!is_file($path)) {
                return [];
            }

            $raw = (string) @file_get_contents($path);
            $j = json_decode($raw, true);

            return is_array($j) ? $j : [];
        }

        /**
         * Render metadata block.
         *
         * @param array<string,mixed> $meta
         * @return void
         */
        protected function render_metadata(array $meta): void
        {
            echo '<div class="json-meta">';

            if (!empty($meta['author'])) {
                echo '<span class="meta-author">Author: ' . htmlspecialchars((string) $meta['author'], ENT_QUOTES, 'UTF-8') . '</span>';
            }

            if (!empty($meta['date'])) {
                echo '<span class="meta-date">Date: ' . htmlspecialchars((string) $meta['date'], ENT_QUOTES, 'UTF-8') . '</span>';
            }

            if (!empty($meta['tags']) && is_array($meta['tags'])) {
                echo '<span class="meta-tags">Tags: ';
                $tags = array_map(
                    static function ($tag): string {
                        return htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8');
                    },
                    $meta['tags']
                );
                echo implode(', ', $tags);
                echo '</span>';
            }

            echo '</div>';
        }

        /**
         * Render section-based format.
         *
         * @param array<int,array<string,mixed>> $sections
         * @return void
         */
        protected function render_sections(array $sections): void
        {
            foreach ($sections as $section) {
                if (!is_array($section)) {
                    continue;
                }

                echo '<section class="json-section">';

                if (!empty($section['heading'])) {
                    echo '<h2>' . htmlspecialchars((string) $section['heading'], ENT_QUOTES, 'UTF-8') . '</h2>';
                }

                if (!empty($section['body'])) {
                    echo '<p>' . nl2br(htmlspecialchars((string) $section['body'], ENT_QUOTES, 'UTF-8')) . '</p>';
                }

                // Image support
                if (!empty($section['image'])) {
                    $this->render_image($section['image'], $section['caption'] ?? '');
                }

                // Table support
                if (!empty($section['table']) && is_array($section['table'])) {
                    $this->render_table($section['table']);
                }

                // Unordered list
                if (isset($section['list'])) {
                    $this->render_list($section['list'], false);
                }

                // Ordered list
                if (isset($section['ordered_list'])) {
                    $this->render_list($section['ordered_list'], true);
                }

                echo '</section>';
            }
        }

        /**
         * Render legacy "content" format.
         *
         * @param array<string,mixed> $content
         * @return void
         */
        protected function render_legacy_content(array $content): void
        {
            if (!empty($content['intro'])) {
                echo '<p>' . nl2br(htmlspecialchars((string) $content['intro'], ENT_QUOTES, 'UTF-8')) . '</p>';
            }

            if (array_key_exists('items', $content)) {
                $this->render_list($content['items'], false);
            }

            if (!empty($content['footer'])) {
                echo '<p><small>' . nl2br(htmlspecialchars((string) $content['footer'], ENT_QUOTES, 'UTF-8')) . '</small></p>';
            }
        }

        /**
         * Render an image with optional caption.
         *
         * @param string $url
         * @param string $caption
         * @return void
         */
        protected function render_image(string $url, string $caption = ''): void
        {
            $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $alt = $caption !== '' ? htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') : 'Image';

            echo '<figure class="json-image">';
            echo '<img src="' . $url . '" alt="' . $alt . '">';

            if ($caption !== '') {
                echo '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
            }

            echo '</figure>';
        }

        /**
         * Render a table.
         *
         * Expected format:
         * {
         *   "headers": ["Column 1", "Column 2"],
         *   "rows": [
         *     ["Cell 1", "Cell 2"],
         *     ["Cell 3", "Cell 4"]
         *   ]
         * }
         *
         * @param array<string,mixed> $table
         * @return void
         */
        protected function render_table(array $table): void
        {
            $headers = $table['headers'] ?? [];
            $rows    = $table['rows']    ?? [];

            if (!is_array($headers) || !is_array($rows)) {
                return;
            }

            echo '<table class="json-table">';

            // Headers
            if (!empty($headers)) {
                echo '<thead><tr>';
                foreach ($headers as $h) {
                    echo '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
                }
                echo '</tr></thead>';
            }

            // Rows
            if (!empty($rows)) {
                echo '<tbody>';
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    echo '<tr>';
                    foreach ($row as $cell) {
                        echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody>';
            }

            echo '</table>';
        }

        /**
         * Smart list renderer.
         *
         * Accepts:
         * - array: ["one", "two", "three"]
         * - string with dash/asterisk bullets:
         *     "- one\n- two\n- three"
         *     "* one\n* two"
         *
         * @param mixed $value
         * @param bool $ordered
         * @return void
         */
        protected function render_list($value, bool $ordered = false): void
        {
            $items = [];

            // Case 1: proper array
            if (is_array($value)) {
                foreach ($value as $v) {
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $items[] = $v;
                    }
                }
            }
            // Case 2: string with possible "- item" or "* item" lines
            elseif (is_string($value)) {
                $lines = preg_split('/\R/', $value);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }

                        // Match "- item" or "* item"
                        if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
                            $text = trim((string) $m[1]);
                            if ($text !== '') {
                                $items[] = $text;
                            }
                        } else {
                            // If no leading - or *, treat as plain item
                            $items[] = $line;
                        }
                    }
                }
            }

            if (empty($items)) {
                return;
            }

            $tag = $ordered ? 'ol' : 'ul';
            echo '<' . $tag . '>';
            foreach ($items as $item) {
                echo '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            echo '</' . $tag . '>';
        }
    }
}
