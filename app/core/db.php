<?php

declare(strict_types=1);

final class db
{
    /**
     * @var mysqli|null
     */
    protected ?mysqli $link = null;

    /**
     * @var array<string,string>
     */
    protected array $config = [];

    /**
     * Load DB config from /app/config/config.json.
     */
    public function __construct()
    {
        $cfgPath = dirname(__DIR__) . '/config/config.json';

        if (is_file($cfgPath)) {
            $raw = (string) file_get_contents($cfgPath);
            $j   = json_decode($raw, true);

            if (is_array($j)) {
                $this->config = [
                    'host' => (string)($j['host'] ?? ''),
                    'user' => (string)($j['user'] ?? ''),
                    'pass' => (string)($j['pass'] ?? ''),
                    'name' => (string)($j['name'] ?? ''),
                ];
            }
        }
    }

    /**
     * Private connect tunnel.
     *
     * @return mysqli|false
     */
    private function db_connect()
    {
        if ($this->link instanceof mysqli) {
            return $this->link;
        }

        $host = (string)($this->config['host'] ?? '');
        $user = (string)($this->config['user'] ?? '');
        $pass = (string)($this->config['pass'] ?? '');
        $name = (string)($this->config['name'] ?? '');

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
     * Close connection.
     *
     * @return void
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
     *
     * @param string $value
     * @return string
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
     * @param string $sql
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
     * Fetch one row.
     *
     * @param string $sql
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
     * @param string $sql
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

