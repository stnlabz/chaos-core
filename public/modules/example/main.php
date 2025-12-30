<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Teaching Module: example
 *
 * URL patterns:
 *   /example
 *   /example/view/{topic}
 *
 * This module demonstrates:
 *   - How module routers work
 *   - How pages/views are loaded
 *   - How JSON or DB could be used (teaching examples)
 */

// -----------------------------------------------------------------------------
//  Local helpers
// -----------------------------------------------------------------------------

/**
 * Safely parse the request URL into segments.
 *
 * @return array<int,string>
 */
function example_get_segments(): array
{
    $path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return array_values(array_filter(explode('/', trim($path, '/'))));
}

/**
 * Render a simple 404 for this teaching module.
 *
 * @return void
 */
function example_not_found(): void
{
    http_response_code(404);
    echo '<div class="container my-4">';
    echo '<div class="alert alert-secondary">Not found.</div>';
    echo '</div>';
}

// -----------------------------------------------------------------------------
//  Handlers
// -----------------------------------------------------------------------------

/**
 * /example
 *
 * @return void
 */
function example_home(): void
{
    echo '<div class="container my-4">';
    require __DIR__ . '/views/index.php';
    echo '</div>';
}

/**
 * /example/view/{topic}
 *
 * @param string $topic
 * @return void
 */
function example_view(string $topic): void
{
    if ($topic === '') {
        example_not_found();
        return;
    }

    // Link to teaching pages
    if ($topic === 'database') {
        require __DIR__ . '/views/using_database.php';
        return;
    }

    if ($topic === 'json') {
        require __DIR__ . '/views/using_json.php';
        return;
    }

    // Fallback
    example_not_found();
}

// -----------------------------------------------------------------------------
//  Module-level router
// -----------------------------------------------------------------------------

$segments   = example_get_segments();
$moduleSlug = $segments[0] ?? '';   // expected: example
$action     = $segments[1] ?? '';   // e.g. view
$arg1       = $segments[2] ?? '';   // e.g. topic

// Make sure we're under /example
if ($moduleSlug !== 'example') {
    example_not_found();
    return;
}

switch ($action) {
    case '':
        example_home();
        break;

    case 'view':
        example_view($arg1);
        break;

    default:
        example_not_found();
        break;
}

