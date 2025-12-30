<?php

declare(strict_types=1);

/**
 * Chaos CMS Core Updater (CLI)
 *
 * Usage:
 *   php /app/update/run.php status
 *   php /app/update/run.php check
 *   php /app/update/run.php upgrade
 *   php /app/update/run.php lock
 *   php /app/update/run.php unlock
 *   php /app/update/run.php maintenance:on
 *   php /app/update/run.php maintenance:off
 *   php /app/update/run.php apply --file=/app/update/packages/core.tar.gz --sha256=...
 *   php /app/update/run.php rollback --from=/app/update/backup/20251229_101500
 */

require __DIR__ . '/lib.php';

$cmd = (string) ($argv[1] ?? 'status');
$args = chaos_update_parse_args($argv);

chaos_update_run($cmd, $args);

