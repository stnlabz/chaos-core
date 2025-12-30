<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Core Module: Pages
 *
 * Route:
 *   /pages/{slug}
 *
 * DB Table:
 *   pages
 *
 * Formats supported:
 *   - html (raw HTML stored in body)
 *   - md   (Markdown stored in body, rendered via render_md if available; fallback safe HTML)
 *   - json (JSON stored in body; rendered via render_json if available; fallback pretty)
 */

(function (): void {
    global $db, $auth;

    if (!isset($db) || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB unavailable.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">DB connection failed.</div></div>';
        return;
    }

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uriPath = rtrim($uriPath, '/');

    // Expect /pages/{slug}
    $slug = '';
    if (strpos($uriPath, '/pages') === 0) {
        $slug = trim(substr($uriPath, strlen('/pages')), '/');
    }

    if ($slug === '') {
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-secondary">Page not found.</div></div>';
        return;
    }

    // basic slug sanitize (keep simple, match DB slugs)
    $slug = (string)preg_replace('~[^a-z0-9\-_\/]~i', '', $slug);

    $stmt = $conn->prepare('SELECT id, slug, title, format, body, status, visibility, created_at, updated_at FROM pages WHERE slug=? LIMIT 1');
    if ($stmt === false) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Query failed.</div></div>';
        return;
    }

    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $res  = $stmt->get_result();
    $page = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($page)) {
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-secondary">Page not found.</div></div>';
        return;
    }

    $status     = (int)($page['status'] ?? 0);
    $visibility = (int)($page['visibility'] ?? 0);

    $isLoggedIn = (isset($auth) && $auth instanceof auth) ? (bool)$auth->check() : false;

    // Draft gating: drafts only visible to logged-in
    if ($status !== 1 && !$isLoggedIn) {
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-secondary">Page not found.</div></div>';
        return;
    }

    // Visibility: 0 public, 1 unlisted, 2 members
    if ($visibility === 2 && !$isLoggedIn) {
        http_response_code(403);
        echo '<div class="container my-4"><div class="alert alert-warning">Members only.</div></div>';
        return;
    }

    if ($visibility === 1 && !$isLoggedIn) {
        http_response_code(404);
        echo '<div class="container my-4"><div class="alert alert-secondary">Page not found.</div></div>';
        return;
    }

    $title  = (string)($page['title'] ?? '');
    $format = strtolower((string)($page['format'] ?? 'html'));
    $body   = (string)($page['body'] ?? '');

    // Simple HTML escape helper
    $e = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    // Render JSON pages as JSON output (not HTML)
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');

        if (function_exists('render_json')) {
            // render_json expects a file path in your stack, so we fall back to direct emit
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) || is_object($decoded)) {
            echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        echo json_encode(['error' => 'Invalid JSON body', 'slug' => $slug], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }

    // HTML wrapper output for md/html
    echo '<div class="container my-4 page-view">';
    echo '<article class="page-card">';
    //echo '<h1 class="page-title">' . $e($title) . '</h1>';

    if ($format === 'md') {
        // Prefer your md renderer if present; otherwise safe fallback
        if (class_exists('render_md')) {
            $md = new render_md();

            // Try common method names without being clever
            if (method_exists($md, 'text')) {
                echo '<div class="page-body">' . (string)$md->text($body) . '</div>';
                echo '</article></div>';
                return;
            }

            if (method_exists($md, 'markdown')) {
                echo '<div class="page-body">' . (string)$md->markdown($body) . '</div>';
                echo '</article></div>';
                return;
            }

            if (method_exists($md, 'render')) {
                echo '<div class="page-body">' . (string)$md->render($body) . '</div>';
                echo '</article></div>';
                return;
            }
        }

        // fallback: show readable plain text with minimal paragraph breaks
        $safe = $e($body);
        $safe = preg_replace("/\r\n|\r|\n/", "\n", $safe);
        $paras = array_values(array_filter(array_map('trim', explode("\n\n", (string)$safe))));
        echo '<div class="page-body">';
        foreach ($paras as $p) {
            echo '<p>' . str_replace("\n", '<br>', $p) . '</p>';
        }
        echo '</div>';
        echo '</article></div>';
        return;
    }

    // html (raw HTML stored)
    echo '<div class="page-body">' . $body . '</div>';
    echo '</article></div>';
})();

