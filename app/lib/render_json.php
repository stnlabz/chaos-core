<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — JSON Page Renderer
 * Renders structured JSON as HTML pages.
 */
 if (!class_exists('render_json')) {
class render_json
{
    /**
     * Render a JSON file as HTML.
     *
     * Supported shapes:
     * 1. {
     *      "title": "...",
     *      "subtitle": "...",
     *      "content": { "intro": "...", "items": "...|[]", "footer": "..." }
     *    }
     *
     * 2. {
     *      "title": "...",
     *      "subtitle": "...",
     *      "sections": [
     *        { "heading": "...", "body": "...", "list": "...|[]" }
     *      ]
     *    }
     *
     * KISS. No Markdown engine integration.
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

        $title    = $data['title']    ?? '';
        $subtitle = $data['subtitle'] ?? '';

        echo '<div class="json-page">';

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

            if (isset($section['list'])) {
                $this->render_list($section['list']);
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
            $this->render_list($content['items']);
        }

        if (!empty($content['footer'])) {
            echo '<p><small>' . nl2br(htmlspecialchars((string) $content['footer'], ENT_QUOTES, 'UTF-8')) . '</small></p>';
        }
    }
    
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
     * Smart list renderer.
     *
     * Accepts:
     * - array: ["one", "two", "three"]
     * - string with dash/asterisk bullets:
     *     "- one\n- two\n- three"
     *     "* one\n* two"
     *
     * @param mixed $value
     * @return void
     */
    protected function render_list($value): void
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

        echo '<ul>';
        foreach ($items as $item) {
            echo '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
}
}
