<?php

declare(strict_types=1);

/**
 * Chaos CMS — DB Core
 *
 * Loads DB credentials from /app/config/config.json and provides a small MySQLi wrapper.
 *
 * Supported config.json shapes:
 *  1) Preferred:
 *     { "db": { "host": "...", "user": "...", "pass": "...", "name": "..." } }
 *  2) Legacy:
 *     { "host": "...", "user": "...", "pass": "...", "name": "..." }
 */
final class db
{
    /**
     * Active MySQLi connection.
     *
     * @var mysqli|null
     */
    protected ?mysqli $link = null;

    /**
     * Normalized configuration.
     *
     * @var array<string,string>
     */
    protected array $config = [
        'host' => '',
        'user' => '',
        'pass' => '',
        'name' => '',
    ];

    /**
     * Load DB config from /app/config/config.json.
     */
    public function __construct()
    {
        $cfgPath = dirname(__DIR__) . '/config/config.json';

        if (!is_file($cfgPath)) {
            return;
        }

        $raw = (string) @file_get_contents($cfgPath);
        if ($raw === '') {
            return;
        }

        // Strip UTF-8 BOM if present (breaks json_decode).
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return;
        }

        // Preferred: {"db":{...}}
        if (isset($j['db']) && is_array($j['db'])) {
            $d = $j['db'];

            $this->config = [
                'host' => (string) ($d['host'] ?? ''),
                'user' => (string) ($d['user'] ?? ''),
                'pass' => (string) ($d['pass'] ?? ''),
                'name' => (string) ($d['name'] ?? ''),
            ];

            return;
        }

        // Legacy: {"host":"...","user":"...","pass":"...","name":"..."}
        $this->config = [
            'host' => (string) ($j['host'] ?? ''),
            'user' => (string) ($j['user'] ?? ''),
            'pass' => (string) ($j['pass'] ?? ''),
            'name' => (string) ($j['name'] ?? ''),
        ];
    }

    /**
     * Internal connect tunnel.
     *
     * @return mysqli|false
     */
    private function db_connect()
    {
        if ($this->link instanceof mysqli) {
            return $this->link;
        }

        $host = (string) ($this->config['host'] ?? '');
        $user = (string) ($this->config['user'] ?? '');
        $pass = (string) ($this->config['pass'] ?? '');
        $name = (string) ($this->config['name'] ?? '');

        if ($host === '' || $user === '' || $name === '') {
            return false;
        }

        mysqli_report(MYSQLI_REPORT_OFF);

        $link = @new mysqli($host, $user, $pass, $name);
        if ($link->connect_errno) {
            return false;
        }

        $link->set_charset('utf8mb4');
        $this->link = $link;

        return $this->link;
    }

    /**
     * Public connect tunnel.
     *
     * @return mysqli|false
     */
    public function connect()
    {
        return $this->db_connect();
    }

    /**
     * Back-compat alias (some code expects this).
     *
     * @return mysqli|false
     */
    public function get_connection()
    {
        return $this->connect();
    }

    /**
     * Close connection.
     */
    public function close(): void
    {
        if ($this->link instanceof mysqli) {
            @$this->link->close();
        }

        $this->link = null;
    }

    /**
     * Escape a string for safe SQL usage (last resort; prefer prepared statements).
     */
    public function escape(string $value): string
    {
        $conn = $this->connect();
        if ($conn instanceof mysqli) {
            return $conn->real_escape_string($value);
        }

        return addslashes($value);
    }

    /**
     * Run a raw query.
     *
     * @return mysqli_result|bool
     */
    public function query(string $sql)
    {
        $conn = $this->connect();
        if (!$conn instanceof mysqli) {
            return false;
        }

        return $conn->query($sql);
    }

    /**
     * Execute a non-select statement (INSERT/UPDATE/DELETE/DDL).
     */
    public function exec(string $sql): bool
    {
        $res = $this->query($sql);

        return $res === true;
    }

    /**
     * Fetch one row.
     *
     * @return array<string,mixed>|null
     */
    public function fetch(string $sql): ?array
    {
        $res = $this->query($sql);
        if (!$res instanceof mysqli_result) {
            return null;
        }

        $row = $res->fetch_assoc();
        $res->close();

        return is_array($row) ? $row : null;
    }

    /**
     * Fetch all rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetch_all(string $sql): array
    {
        $res = $this->query($sql);
        if (!$res instanceof mysqli_result) {
            return [];
        }

        $rows = [];

        while (true) {
            $row = $res->fetch_assoc();
            if (!is_array($row)) {
                break;
            }

            $rows[] = $row;
        }

        $res->close();

        return $rows;
    }
}

