<?php

declare(strict_types=1);

/**
 * Chaos CMS DB
 * Announcements helper (front-end)
 *
 * Usage:
 *   announcements_latest();
 */

if (!function_exists('announcements_latest')) {

    function announcements_latest(): void
    {
        global $db;

        if (!$db instanceof db) {
            return;
        }

        $conn = $db->connect();
        if (!$conn instanceof mysqli) {
            return;
        }

        $sql = "
            SELECT title, body, created_at
            FROM announcements
            WHERE published = 1
            ORDER BY created_at DESC
            LIMIT 1
        ";

        $res = $conn->query($sql);
        if (!$res instanceof mysqli_result || $res->num_rows === 0) {
            return;
        }

        $row = $res->fetch_assoc();
        $res->free();

        $title = e((string) ($row['title'] ?? ''));
        $body  = nl2br(e((string) ($row['body'] ?? '')));
        $date  = e((string) ($row['created_at'] ?? ''));

        echo '<section class="container my-4 announcements-latest">';
        echo '  <div class="card">';
        echo '    <div class="card-body">';
        echo '      <div class="d-flex justify-content-between align-items-start mb-2">';
        echo '        <strong>' . $title . '</strong>';
        echo '        <small class="text-muted">' . $date . '</small>';
        echo '      </div>';
        echo '      <div class="small">' . $body . '</div>';
        echo '    </div>';
        echo '  </div>';
        echo '</section>';
    }
}

