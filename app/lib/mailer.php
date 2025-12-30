<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Mailer (DB-backed)
 *
 * Uses PHPMailer dropped into:
 *   /app/lib/phpmailer/{PHPMailer.php,SMTP.php,Exception.php}
 *
 * Reads SMTP settings from DB table: settings (name/value).
 *
 * Expected keys (supports dash or underscore):
 *   smtp-host / smtp_host
 *   smtp_port
 *   smtp_user
 *   smtp-pass / smtp_pass
 *   smtp_secure   (auto|tls|ssl|none)
 *   smtp_timeout
 *   from_email (optional)
 *   from_name  (optional)
 *   reply_to   (optional)
 */

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

class mailer
{
    /**
     * @var db
     */
    protected db $db;

    /**
     * @var array<string,string>|null
     */
    protected ?array $cache = null;

    /**
     * @param db $db
     */
    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * Create and configure a PHPMailer instance.
     *
     * @return \PHPMailer\PHPMailer\PHPMailer
     */
    public function create(): \PHPMailer\PHPMailer\PHPMailer
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $host    = $this->get('smtp-host', '');
        if ($host === '') {
            // No SMTP configured -> return inert mailer (dev-safe)
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
            return $mail;
        }

        $port    = (int) $this->get('smtp_port', '587');
        $user    = $this->get('smtp_user', '');
        $pass    = $this->get('smtp-pass', '');
        $secure  = strtolower($this->get('smtp_secure', 'auto'));
        $timeout = (int) $this->get('smtp_timeout', '12');

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';
        $mail->isHTML(true);
        $mail->Timeout    = $timeout;

        $mail->SMTPAuth = ($user !== '' && $pass !== '');
        if ($mail->SMTPAuth) {
            $mail->Username = $user;
            $mail->Password = $pass;
        }

        // smtp_secure: auto|tls|ssl|none
        if ($secure === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'none') {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        } else {
            // auto: let PHPMailer negotiate; do not force
            // leave SMTPSecure unset
        }

        $fromEmail = $this->get('from_email', '');
        $fromName  = $this->get('from_name', '');
        if ($fromEmail !== '') {
            $mail->setFrom($fromEmail, $fromName);
        }

        $replyTo = $this->get('reply_to', '');
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        return $mail;
    }

    /**
     * Get a setting by name (supports dash/underscore variants).
     *
     * Example:
     *   get('smtp-host') will also match 'smtp_host'
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    protected function get(string $name, string $default = ''): string
    {
        $all = $this->all();

        $name = trim($name);
        if ($name === '') {
            return $default;
        }

        $a = strtolower($name);
        $b = strtolower(str_replace('-', '_', $name));
        $c = strtolower(str_replace('_', '-', $name));

        if (isset($all[$a])) {
            return (string) $all[$a];
        }
        if (isset($all[$b])) {
            return (string) $all[$b];
        }
        if (isset($all[$c])) {
            return (string) $all[$c];
        }

        return $default;
    }

    /**
     * Load all settings into a request-local cache.
     *
     * @return array<string,string>
     */
    protected function all(): array
    {
        if (is_array($this->cache)) {
            return $this->cache;
        }

        $this->cache = [];

        $conn = $this->db->connect();
        if ($conn === false) {
            return $this->cache;
        }

        $res = $conn->query('SELECT name, value FROM settings');
        if (!$res instanceof \mysqli_result) {
            return $this->cache;
        }

        while ($row = $res->fetch_assoc()) {
            $k = strtolower(trim((string) ($row['name'] ?? '')));
            $v = (string) ($row['value'] ?? '');
            if ($k !== '') {
                $this->cache[$k] = $v;
            }
        }

        $res->close();

        return $this->cache;
    }
}

