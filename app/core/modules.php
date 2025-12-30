<?php

declare(strict_types=1);

/**
 * Chaos DB CMS - Modules / Plugins / Themes Registry Helper
 *
 * Core responsibilities:
 *  - Ensure registry tables exist:
 *      - modules
 *      - plugins
 *      - themes
 *  - Allow modules/plugins/themes to register themselves with metadata:
 *      - slug
 *      - name
 *      - version
 *      - author
 *      - description
 *      - has_admin (modules/plugins)
 *      - enabled (for later admin control)
 *      - installed
 *  - Provide a simple hook for modules to create their own data tables
 *    when they first run (e.g. internal_log).
 */
class modules
{
    /**
     * Ensure registry tables exist.
     *
     * @param db $db
     * @return void
     */
    public static function ensure_registry_tables(db $db): void
    {
        $conn = $db->connect();
        if ($conn === false) {
            return;
        }

        $sqlModules = <<<SQL
CREATE TABLE IF NOT EXISTS modules (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  name        VARCHAR(190) NOT NULL,
  version     VARCHAR(32)  NOT NULL DEFAULT '',
  author      VARCHAR(190) NOT NULL DEFAULT '',
  description TEXT         NULL,
  has_admin   TINYINT(1)   NOT NULL DEFAULT 0,
  enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  installed   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_modules_enabled (enabled),
  KEY idx_modules_installed (installed),
  KEY idx_modules_has_admin (has_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $sqlPlugins = <<<SQL
CREATE TABLE IF NOT EXISTS plugins (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  name        VARCHAR(190) NOT NULL,
  version     VARCHAR(32)  NOT NULL DEFAULT '',
  author      VARCHAR(190) NOT NULL DEFAULT '',
  description TEXT         NULL,
  has_admin   TINYINT(1)   NOT NULL DEFAULT 0,
  enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  installed   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_plugins_enabled (enabled),
  KEY idx_plugins_installed (installed),
  KEY idx_plugins_has_admin (has_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $sqlThemes = <<<SQL
CREATE TABLE IF NOT EXISTS themes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  name        VARCHAR(190) NOT NULL,
  version     VARCHAR(32)  NOT NULL DEFAULT '',
  author      VARCHAR(190) NOT NULL DEFAULT '',
  description TEXT         NULL,
  enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  is_default  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_themes_enabled (enabled),
  KEY idx_themes_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $db->query($sqlModules);
        $db->query($sqlPlugins);
        $db->query($sqlThemes);
    }

    /**
     * Register or update a module in the modules table.
     *
     * @param db $db
     * @param string $slug
     * @param string $name
     * @param string $version
     * @param string $author
     * @param string $description
     * @param bool $hasAdmin
     * @return void
     */
    public static function register_module(
        db $db,
        string $slug,
        string $name,
        string $version = '',
        string $author = '',
        string $description = '',
        bool $hasAdmin = false
    ): void {
        $conn = $db->connect();
        if ($conn === false) {
            return;
        }

        $has = $hasAdmin ? 1 : 0;

        $stmt = $conn->prepare(
            'INSERT INTO modules (slug, name, version, author, description, has_admin, enabled, installed)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0)
             ON DUPLICATE KEY UPDATE
               name        = VALUES(name),
               version     = VALUES(version),
               author      = VALUES(author),
               description = VALUES(description),
               has_admin   = VALUES(has_admin)'
        );

        if (!$stmt instanceof \mysqli_stmt) {
            return;
        }

        // FIX: description is a string, so we need 5x "s" then "i"
        $stmt->bind_param('sssssi', $slug, $name, $version, $author, $description, $has);

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Register or update a plugin in the plugins table.
     *
     * @param db $db
     * @param string $slug
     * @param string $name
     * @param string $version
     * @param string $author
     * @param string $description
     * @param bool $hasAdmin
     * @return void
     */
    public static function register_plugin(
        db $db,
        string $slug,
        string $name,
        string $version = '',
        string $author = '',
        string $description = '',
        bool $hasAdmin = false
    ): void {
        $conn = $db->connect();
        if ($conn === false) {
            return;
        }

        $has = $hasAdmin ? 1 : 0;

        $stmt = $conn->prepare(
            'INSERT INTO plugins (slug, name, version, author, description, has_admin, enabled, installed)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0)
             ON DUPLICATE KEY UPDATE
               name        = VALUES(name),
               version     = VALUES(version),
               author      = VALUES(author),
               description = VALUES(description),
               has_admin   = VALUES(has_admin)'
        );

        if (!$stmt instanceof \mysqli_stmt) {
            return;
        }

        // FIX: description is a string, so we need 5x "s" then "i"
        $stmt->bind_param('sssssi', $slug, $name, $version, $author, $description, $has);

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Register or update a theme in the themes table.
     *
     * @param db $db
     * @param string $slug
     * @param string $name
     * @param string $version
     * @param string $author
     * @param string $description
     * @return void
     */
    public static function register_theme(
        db $db,
        string $slug,
        string $name,
        string $version = '',
        string $author = '',
        string $description = ''
    ): void {
        $conn = $db->connect();
        if ($conn === false) {
            return;
        }

        $stmt = $conn->prepare(
            'INSERT INTO themes (slug, name, version, author, description, enabled, is_default)
             VALUES (?, ?, ?, ?, ?, 0, 0)
             ON DUPLICATE KEY UPDATE
               name        = VALUES(name),
               version     = VALUES(version),
               author      = VALUES(author),
               description = VALUES(description)'
        );

        if (!$stmt instanceof \mysqli_stmt) {
            return;
        }

        $stmt->bind_param('sssss', $slug, $name, $version, $author, $description);

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Fetch all modules.
     *
     * @param db $db
     * @param bool $enabledOnly
     * @return array<int,array<string,mixed>>
     */
    public static function all_modules(db $db, bool $enabledOnly = false): array
    {
        $conn = $db->connect();
        if ($conn === false) {
            return [];
        }

        $sql = 'SELECT * FROM modules';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY slug ASC';

        $rows = [];
        $res = $conn->query($sql);
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->close();
        }

        return $rows;
    }

    /**
     * Convenience alias.
     *
     * @param db $db
     * @return array<int,array<string,mixed>>
     */
    public static function enabled_modules(db $db): array
    {
        return self::all_modules($db, true);
    }

    /**
     * KISS Hook:
     * Ensure a module's OWN data table exists.
     *
     * @param db $db
     * @param string $tableName
     * @param string $createSql
     * @return array<string,mixed>
     */
    public static function ensure_data_table(db $db, string $tableName, string $createSql): array
    {
        $result = [
            'ok'      => false,
            'created' => false,
            'exists'  => false,
            'rows'    => 0,
        ];

        $conn = $db->connect();
        if ($conn === false) {
            return $result;
        }

        $safeName = trim($tableName);
        if ($safeName === '') {
            return $result;
        }

        $safeLike = $conn->real_escape_string($safeName);

        $checkSql = "SHOW TABLES LIKE '{$safeLike}'";
        $checkRes = $conn->query($checkSql);

        if ($checkRes instanceof \mysqli_result && $checkRes->num_rows > 0) {
            $result['exists'] = true;
            $result['ok']     = true;

            $countSql = "SELECT COUNT(*) AS c FROM `{$safeName}`";
            $countRes = $conn->query($countSql);
            if ($countRes instanceof \mysqli_result) {
                $row = $countRes->fetch_assoc();
                $result['rows'] = (int) ($row['c'] ?? 0);
                $countRes->close();
            }

            $checkRes->close();
            return $result;
        }

        if ($checkRes instanceof \mysqli_result) {
            $checkRes->close();
        }

        // Create table, then re-check.
        $conn->query($createSql);

        $checkRes2 = $conn->query($checkSql);
        if ($checkRes2 instanceof \mysqli_result && $checkRes2->num_rows > 0) {
            $result['exists']  = true;
            $result['created'] = true;
            $result['ok']      = true;

            $countSql = "SELECT COUNT(*) AS c FROM `{$safeName}`";
            $countRes = $conn->query($countSql);
            if ($countRes instanceof \mysqli_result) {
                $row = $countRes->fetch_assoc();
                $result['rows'] = (int) ($row['c'] ?? 0);
                $countRes->close();
            }

            $checkRes2->close();
            return $result;
        }

        if ($checkRes2 instanceof \mysqli_result) {
            $checkRes2->close();
        }

        return $result;
    }
}

