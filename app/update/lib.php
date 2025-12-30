<?php

declare(strict_types=1);

/**
 * Chaos CMS Core Updater Library
 *
 * Update scope: /app/**
 * Preserved during apply: /app/data, /app/update
 *
 * NOTE:
 * - No package structure requirements beyond locating an app/ directory.
 * - If the package contains an app/ directory, we apply it.
 */

function chaos_update_run(string $cmd, array $args): void
{
    $root = chaos_update_paths();

    if (!is_dir($root['logs'])) {
        @mkdir($root['logs'], 0755, true);
    }

    if (!is_dir($root['packages'])) {
        @mkdir($root['packages'], 0755, true);
    }

    if (!is_dir($root['stage'])) {
        @mkdir($root['stage'], 0755, true);
    }

    if (!is_dir($root['backup'])) {
        @mkdir($root['backup'], 0755, true);
    }

    switch ($cmd) {
        case 'status':
            chaos_update_status();
            return;

        case 'check':
            chaos_update_check();
            return;

        case 'upgrade':
            chaos_update_upgrade();
            return;

        case 'lock':
            chaos_update_lock();
            return;

        case 'unlock':
            chaos_update_unlock();
            return;

        case 'maintenance:on':
            chaos_update_maintenance(true);
            return;

        case 'maintenance:off':
            chaos_update_maintenance(false);
            return;

        case 'apply':
            chaos_update_apply($args);
            return;

        case 'rollback':
            chaos_update_rollback($args);
            return;

        default:
            chaos_update_out("Unknown command: {$cmd}");
            chaos_update_out('Try: status | check | upgrade | lock | unlock | maintenance:on | maintenance:off | apply | rollback');
            return;
    }
}

/**
 * Parse CLI args like --key=value into an associative array.
 *
 * @param array<int,string> $argv
 *
 * @return array<string,string>
 */
function chaos_update_parse_args(array $argv): array
{
    $out = [];

    foreach ($argv as $a) {
        if (strpos($a, '--') !== 0) {
            continue;
        }

        $a = substr($a, 2);
        $parts = explode('=', $a, 2);

        $k = trim((string) ($parts[0] ?? ''));
        $v = (string) ($parts[1] ?? '');

        if ($k !== '') {
            $out[$k] = $v;
        }
    }

    return $out;
}

/**
 * Get canonical paths used by updater.
 *
 * @return array<string,string>
 */
function chaos_update_paths(): array
{
    if (!defined('APP_ROOT')) {
        define('APP_ROOT', dirname(__DIR__));
    }

    return [
        'app'      => APP_ROOT,
        'data'     => APP_ROOT . '/data',
        'lock'     => APP_ROOT . '/data/update.lock',
        'flag'     => APP_ROOT . '/data/maintenance.flag',
        'update'   => APP_ROOT . '/update',
        'logs'     => APP_ROOT . '/update/logs',
        'packages' => APP_ROOT . '/update/packages',
        'stage'    => APP_ROOT . '/update/stage',
        'backup'   => APP_ROOT . '/update/backup',
        'cfg'      => APP_ROOT . '/update/update.json',
        'version'  => APP_ROOT . '/data/version.json',
    ];
}

/**
 * Print to stdout.
 */
function chaos_update_out(string $msg): void
{
    echo $msg . PHP_EOL;
}

/**
 * Append to updater log.
 */
function chaos_update_log(string $msg): void
{
    $p = chaos_update_paths();
    $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . PHP_EOL;
    @file_put_contents($p['logs'] . '/update.log', $line, FILE_APPEND);
}

/**
 * Show current update/maintenance state.
 */
function chaos_update_status(): void
{
    $p = chaos_update_paths();

    $lock = is_file($p['lock']) ? 'yes' : 'no';
    $flag = is_file($p['flag']) ? 'yes' : 'no';
    $local = chaos_update_local_version();

    chaos_update_out('local_version: ' . ($local !== '' ? $local : 'unknown'));
    chaos_update_out('update_lock: ' . $lock);
    chaos_update_out('maintenance_flag: ' . $flag);
}

/**
 * Read local version string from /app/data/version.json.
 */
function chaos_update_local_version(): string
{
    $p = chaos_update_paths();

    if (!is_file($p['version'])) {
        return '';
    }

    $raw = (string) @file_get_contents($p['version']);
    $json = json_decode($raw, true);

    if (!is_array($json)) {
        return '';
    }

    $v = (string) ($json['version'] ?? '');

    return trim($v);
}

/**
 * Write local version info to /app/data/version.json
 */
function chaos_update_write_local_version(string $version): bool
{
    $p = chaos_update_paths();

    $payload = [
        'version'    => $version,
        'updated_at' => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return @file_put_contents($p['version'], $json . PHP_EOL) !== false;
}

/**
 * Read update config from /app/update/update.json
 *
 * @return array<string,mixed>
 */
function chaos_update_cfg(): array
{
    $p = chaos_update_paths();

    if (!is_file($p['cfg'])) {
        return [
            'manifest_url'    => '',
            'timeout_seconds' => 10,
            'channel'         => 'stable',
        ];
    }

    $raw = (string) @file_get_contents($p['cfg']);
    $json = json_decode($raw, true);

    if (!is_array($json)) {
        return [
            'manifest_url'    => '',
            'timeout_seconds' => 10,
            'channel'         => 'stable',
        ];
    }

    return $json;
}

/**
 * HTTP GET (simple, KISS).
 */
function chaos_update_http_get(string $url, int $timeoutSeconds = 10): string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'    => $timeoutSeconds,
            'user_agent' => 'ChaosCMS-Updater/1.0',
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);

    return is_string($raw) ? $raw : '';
}

/**
 * Fetch remote manifest JSON.
 *
 * Expected fields for upgrade:
 * - version
 * - package_url
 * - sha256
 *
 * @return array<string,string>
 */
function chaos_update_remote_manifest(): array
{
    $cfg = chaos_update_cfg();
    $url = (string) ($cfg['manifest_url'] ?? '');
    $timeout = (int) ($cfg['timeout_seconds'] ?? 10);

    if ($url === '') {
        return [];
    }

    $raw = chaos_update_http_get($url, $timeout);
    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [];
    }

    return [
        'version'     => trim((string) ($json['version'] ?? '')),
        'package_url' => trim((string) ($json['package_url'] ?? '')),
        'sha256'      => strtolower(trim((string) ($json['sha256'] ?? ''))),
    ];
}

/**
 * Compare local and remote versions and print status.
 */
function chaos_update_check(): void
{
    $local = chaos_update_local_version();
    $m = chaos_update_remote_manifest();
    $remote = (string) ($m['version'] ?? '');

    chaos_update_out('local_version:  ' . ($local !== '' ? $local : 'unknown'));
    chaos_update_out('remote_version: ' . ($remote !== '' ? $remote : 'unknown'));

    if ($remote === '') {
        chaos_update_out('status: remote manifest unavailable or invalid');
        return;
    }

    if ($local === '') {
        chaos_update_out('status: update available (local unknown)');
        return;
    }

    if (version_compare($remote, $local, '>')) {
        chaos_update_out('status: update available');
        return;
    }

    chaos_update_out('status: up to date');
}

/**
 * Upgrade: check remote manifest; if newer, download + verify + apply.
 */
function chaos_update_upgrade(): void
{
    $p = chaos_update_paths();

    $local = chaos_update_local_version();
    $m = chaos_update_remote_manifest();

    $remote = (string) ($m['version'] ?? '');
    $pkgUrl = (string) ($m['package_url'] ?? '');
    $sha    = (string) ($m['sha256'] ?? '');

    if ($remote === '') {
        chaos_update_out('upgrade: remote manifest missing version');
        return;
    }

    if ($pkgUrl === '' || $sha === '') {
        chaos_update_out('upgrade: remote manifest missing package_url or sha256');
        return;
    }

    if ($local !== '' && !version_compare($remote, $local, '>')) {
        chaos_update_out('upgrade: already up to date');
        return;
    }

    $fileName = basename(parse_url($pkgUrl, PHP_URL_PATH) ?: ('chaos-core-' . $remote . '.tar.gz'));
    if ($fileName === '') {
        $fileName = 'chaos-core-' . $remote . '.tar.gz';
    }

    $dst = $p['packages'] . '/' . $fileName;

    chaos_update_out('upgrade: downloading ' . $pkgUrl);
    chaos_update_log('DOWNLOAD start: ' . $pkgUrl);

    $cfg = chaos_update_cfg();
    $timeout = (int) ($cfg['timeout_seconds'] ?? 10);

    $raw = chaos_update_http_get($pkgUrl, $timeout);
    if ($raw === '') {
        chaos_update_out('upgrade: download failed');
        chaos_update_log('DOWNLOAD failed');
        return;
    }

    if (@file_put_contents($dst, $raw) === false) {
        chaos_update_out('upgrade: cannot write package to ' . $dst);
        chaos_update_log('DOWNLOAD failed: cannot write');
        return;
    }

    $calc = hash_file('sha256', $dst);
    if (!is_string($calc) || strtolower($calc) !== strtolower($sha)) {
        chaos_update_out('upgrade: sha256 mismatch (download corrupted or wrong hash)');
        chaos_update_log('DOWNLOAD blocked: sha256 mismatch');
        @unlink($dst);
        return;
    }

    chaos_update_out('upgrade: sha256 ok');
    chaos_update_log('DOWNLOAD ok: ' . $dst);

    chaos_update_apply([
        'file'   => $dst,
        'sha256' => $sha,
        'remote' => $remote,
    ]);

    chaos_update_write_local_version($remote);
}

/**
 * Create update.lock.
 */
function chaos_update_lock(): void
{
    $p = chaos_update_paths();

    if (is_file($p['lock'])) {
        chaos_update_out('update.lock already exists');
        return;
    }

    @file_put_contents($p['lock'], gmdate('c') . PHP_EOL);
    chaos_update_log('LOCK created');
    chaos_update_out('LOCK: on');
}

/**
 * Remove update.lock.
 */
function chaos_update_unlock(): void
{
    $p = chaos_update_paths();

    if (!is_file($p['lock'])) {
        chaos_update_out('update.lock not present');
        return;
    }

    @unlink($p['lock']);
    chaos_update_log('LOCK removed');
    chaos_update_out('LOCK: off');
}

/**
 * Toggle maintenance.flag.
 */
function chaos_update_maintenance(bool $on): void
{
    $p = chaos_update_paths();

    if ($on) {
        if (!is_file($p['flag'])) {
            @file_put_contents($p['flag'], gmdate('c') . PHP_EOL);
            chaos_update_log('MAINTENANCE enabled');
        }

        chaos_update_out('MAINTENANCE: on');
        return;
    }

    if (is_file($p['flag'])) {
        @unlink($p['flag']);
        chaos_update_log('MAINTENANCE disabled');
    }

    chaos_update_out('MAINTENANCE: off');
}

/**
 * Apply a core package.
 *
 * Rules:
 * - If the package contains an app/ directory (directly OR under one wrapper dir), we apply it.
 * - We preserve /app/data and /app/update.
 *
 * Args:
 *  --file=/path/to/package.tar.gz
 *  --sha256=<hex> (optional)
 */
function chaos_update_apply(array $args): void
{
    $p = chaos_update_paths();

    $file = (string) ($args['file'] ?? '');
    $sha  = strtolower((string) ($args['sha256'] ?? ''));

    if ($file === '' || !is_file($file)) {
        chaos_update_out('apply: missing --file=... or file not found');
        return;
    }

    if ($sha !== '') {
        $calc = hash_file('sha256', $file);
        if (!is_string($calc) || strtolower($calc) !== $sha) {
            chaos_update_out('apply: sha256 mismatch');
            chaos_update_log('APPLY blocked: sha256 mismatch');
            return;
        }
    }

    chaos_update_lock();
    chaos_update_maintenance(true);

    $stamp = gmdate('Ymd_His');
    $stageDir  = $p['stage'] . '/' . $stamp;
    $backupDir = $p['backup'] . '/' . $stamp;

    @mkdir($stageDir, 0755, true);
    @mkdir($backupDir, 0755, true);

    chaos_update_log('APPLY start: ' . $file);

    if (!chaos_update_extract($file, $stageDir)) {
        chaos_update_out('apply: extract failed');
        chaos_update_log('APPLY failed: extract failed');
        chaos_update_maintenance(false);
        chaos_update_unlock();
        return;
    }

    // Locate app/ either at stage root or under first wrapper directory.
    $stagedApp = '';

    if (is_dir($stageDir . '/app')) {
        $stagedApp = $stageDir . '/app';
    } else {
        $scan = @scandir($stageDir);
        if (is_array($scan)) {
            foreach ($scan as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $candidate = $stageDir . '/' . $item . '/app';
                if (is_dir($candidate)) {
                    $stagedApp = $candidate;
                    break;
                }
            }
        }
    }

    if ($stagedApp === '') {
        chaos_update_out('apply: package invalid (missing app/ directory)');
        chaos_update_log('APPLY failed: missing app/ directory');
        chaos_update_maintenance(false);
        chaos_update_unlock();
        return;
    }

    // Backup is best-effort (warn + continue).
    if (!chaos_update_backup_app($backupDir)) {
        chaos_update_out('apply: backup failed (continuing)');
        chaos_update_log('APPLY notice: backup failed (continuing)');
    }

    // Copy staged app into live /app; preserve config + updater itself.
    if (!chaos_update_copy_dir($stagedApp, $p['app'], ['data', 'update'])) {
        chaos_update_out('apply: copy to /app failed');
        chaos_update_log('APPLY failed: copy failed');

        // Rollback only if backup exists.
        if (is_dir($backupDir . '/app')) {
            chaos_update_out('Attempting rollback from: ' . $backupDir);
            chaos_update_restore_app($backupDir);
        }

        chaos_update_maintenance(false);
        chaos_update_unlock();
        return;
    }

    chaos_update_log('APPLY files copied');
    chaos_update_opcache_reset();

    chaos_update_log('APPLY complete');
    chaos_update_out('apply: complete');

    chaos_update_maintenance(false);
    chaos_update_unlock();
}

/**
 * Rollback from a backup directory.
 *
 * Args:
 *  --from=/app/update/backup/<stamp>
 */
function chaos_update_rollback(array $args): void
{
    $p = chaos_update_paths();

    $from = (string) ($args['from'] ?? '');
    if ($from === '' || !is_dir($from)) {
        chaos_update_out('rollback: missing --from=... or dir not found');
        return;
    }

    chaos_update_lock();
    chaos_update_maintenance(true);

    $ok = chaos_update_restore_app($from);

    chaos_update_opcache_reset();

    chaos_update_out($ok ? 'rollback: complete' : 'rollback: failed');
    chaos_update_log($ok ? 'ROLLBACK complete: ' . $from : 'ROLLBACK failed: ' . $from);

    chaos_update_maintenance(false);
    chaos_update_unlock();
}

/**
 * Extract tar.gz / tgz / tar / zip to a directory.
 */
function chaos_update_extract(string $file, string $toDir): bool
{
    $fileLower = strtolower($file);

    if (str_ends_with($fileLower, '.zip')) {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }

        $ok = $zip->extractTo($toDir);
        $zip->close();

        return $ok;
    }

    if (
        str_ends_with($fileLower, '.tar.gz') ||
        str_ends_with($fileLower, '.tgz') ||
        str_ends_with($fileLower, '.tar')
    ) {
        $cmd = 'tar -xf ' . escapeshellarg($file) . ' -C ' . escapeshellarg($toDir) . ' 2>/dev/null';
        $out = [];
        $rc = 0;

        @exec($cmd, $out, $rc);

        return $rc === 0;
    }

    return false;
}

/**
 * Backup /app into backupDir/app
 */
function chaos_update_backup_app(string $backupDir): bool
{
    $p = chaos_update_paths();

    $dst = rtrim($backupDir, '/') . '/app';
    @mkdir($dst, 0755, true);

    if (!is_dir($dst)) {
        return false;
    }

    chaos_update_log('BACKUP start: ' . $dst);

    // No reason to back up what we never overwrite during apply.
    $ok = chaos_update_copy_dir($p['app'], $dst, ['data', 'update']);

    chaos_update_log($ok ? 'BACKUP ok' : 'BACKUP failed');

    return $ok;
}


/**
 * Restore /app from backupDir/app
 */
function chaos_update_restore_app(string $backupDir): bool
{
    $p = chaos_update_paths();

    $src = rtrim($backupDir, '/') . '/app';
    if (!is_dir($src)) {
        return false;
    }

    chaos_update_log('RESTORE start: ' . $src);

    $ok = chaos_update_copy_dir($src, $p['app']);

    chaos_update_log($ok ? 'RESTORE ok' : 'RESTORE failed');

    return $ok;
}

/**
 * Recursive copy (overwrites files). KISS.
 *
 * @param array<int,string> $excludeTop Top-level directory names to skip.
 */
function chaos_update_copy_dir(string $src, string $dst, array $excludeTop = []): bool
{
    if (!is_dir($src)) {
        return false;
    }

    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        return false;
    }

    $items = @scandir($src);
    if (!is_array($items)) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        if (in_array($item, $excludeTop, true)) {
            continue;
        }

        $from = $src . '/' . $item;
        $to   = $dst . '/' . $item;

        if (is_dir($from)) {
            if (!chaos_update_copy_dir($from, $to)) {
                return false;
            }
            continue;
        }

        if (is_file($from)) {
            if (!@copy($from, $to)) {
                return false;
            }
        }
    }

    return true;
}


/**
 * Clear opcache if available.
 */
function chaos_update_opcache_reset(): void
{
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

