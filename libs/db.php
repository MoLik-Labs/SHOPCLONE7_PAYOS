<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
include_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/settings_cache.php";
require_once __DIR__ . "/sql_count.php";
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/../");
$dotenv->load();
/* TLS may terminate at a proxy (Render, Cloudflare) while PHP still sees http. */
$orbitHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
    || (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && strtolower((string) $_SERVER["HTTP_X_FORWARDED_PROTO"]) === "https")
    || (isset($_SERVER["SERVER_PORT"]) && (int) $_SERVER["SERVER_PORT"] === 443);
if (PHP_SAPI !== "cli" && !headers_sent()) {
    /* Refuse a session id the server never issued (session fixation), and keep the cookie away
       from scripts and from cross-site form posts. */
    ini_set("session.use_strict_mode", "1");
    session_set_cookie_params(array("lifetime" => 0, "path" => "/", "secure" => $orbitHttps, "httponly" => true, "samesite" => "Lax"));
}
session_start();
if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"]) || strlen($_SESSION["csrf_token"]) < 32) {
    try {
        $orbitCsrfBytes = random_bytes(32);
    } catch (Throwable $error) {
        /* No CSPRNG is a broken host, but it must not take the whole site down with it. */
        $orbitCsrfBytes = openssl_random_pseudo_bytes(32);
    }
    $_SESSION["csrf_token"] = base64_encode($orbitCsrfBytes);
}
/* The CSRF token (not the session id) is published as a readable cookie so public/common/csrf.js
   can attach it to XHR and fetch calls without a meta tag on every template. */
if (PHP_SAPI !== "cli" && !headers_sent() && (!isset($_COOKIE["orbit_csrf"]) || $_COOKIE["orbit_csrf"] !== $_SESSION["csrf_token"])) {
    setcookie("orbit_csrf", $_SESSION["csrf_token"], array("expires" => 0, "path" => "/", "secure" => $orbitHttps, "httponly" => false, "samesite" => "Lax"));
}
class DB
{
    private $ketnoi;

    /** How many SQL statements this process has sent — for tools/perf-probe.php and staging. */
    public static $queries = 0;
    /** How many statements failed. A page render must never report 0 errors while showing blanks. */
    public static $errors = 0;
    /** Last driver message, already stripped of credentials by MySQL. */
    public static $last_error = "";

    public static function reset_stats()
    {
        self::$queries = 0;
        self::$errors = 0;
        self::$last_error = "";
    }

    /**
     * Log a failed statement without ever echoing SQL to the browser. The SQL text itself is only
     * written when DB_DEBUG=1, because INSERT/UPDATE statements can carry tokens and passwords.
     */
    private function note_error($sql)
    {
        ++self::$errors;
        self::$last_error = (string) mysqli_error($this->ketnoi);
        $where = "unknown";
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
            if (isset($frame["file"], $frame["line"]) && $frame["file"] !== __FILE__) {
                $where = basename($frame["file"]) . ":" . $frame["line"];
                break;
            }
        }
        $detail = "[orbit-db] " . self::$last_error . " at " . $where;
        if (getenv("DB_DEBUG") === "1") {
            $detail .= " | SQL: " . substr(preg_replace('/\s+/', ' ', (string) $sql), 0, 300);
        }
        error_log($detail);
    }

    /** Every statement goes through here so the count, the log and the error counter are honest. */
    private function run_sql($sql)
    {
        ++self::$queries;
        $result = mysqli_query($this->ketnoi, $sql);
        if ($result === false) {
            $this->note_error($sql);
        }
        return $result;
    }
    public function connect()
    {
        if(!$this->ketnoi) {
            /* PHP 8.1+ throws mysqli exceptions by default; the rest of this class is written for the
               "false on error" contract, so pin the mode instead of depending on the php.ini value. */
            mysqli_report(MYSQLI_REPORT_OFF);
            $host = $_ENV["DB_HOST"] ?? getenv("DB_HOST") ?: "localhost";
            $user = $_ENV["DB_USERNAME"] ?? getenv("DB_USERNAME") ?: "";
            $pass = $_ENV["DB_PASSWORD"] ?? getenv("DB_PASSWORD") ?: "";
            $name = $_ENV["DB_DATABASE"] ?? getenv("DB_DATABASE") ?: "";
            $port = (int) ($_ENV["DB_PORT"] ?? getenv("DB_PORT") ?: 3306);
            /* A dead database must fail in seconds, not hang the render for the default 60 s. */
            $link = mysqli_init();
            if ($link) {
                mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
                $this->ketnoi = @mysqli_real_connect($link, $host, $user, $pass, $name, $port);
            } else {
                $this->ketnoi = false;
            }
            if (!$this->ketnoi) {
                error_log("[orbit-db] connect failed: " . mysqli_connect_error());
                if (PHP_SAPI === "cli") {
                    fwrite(STDERR, "Error => DATABASE\n");
                    exit(1);
                }
                if (!headers_sent()) {
                    http_response_code(503);
                }
                exit("Error => DATABASE");
            }
            /* utf8 (3-byte) silently mangles emoji and any 4-byte character; utf8mb4 does not. */
            if (!mysqli_set_charset($this->ketnoi, "utf8mb4")) {
                error_log("[orbit-db] cannot set utf8mb4: " . mysqli_error($this->ketnoi));
            }
        }
    }
    public function dis_connect()
    {
        if($this->ketnoi) {
            mysqli_close($this->ketnoi);
        }
    }
    public function site($data)
    {
        $key = (string) $data;
        /* ONE query per request for every small setting. A page reads 20–60 keys; asking MySQL for
           each one separately was the largest avoidable cost in a render. Large blobs (the FAQ blob,
           e-mail templates) are left out of the bulk map by the LENGTH filter and fetched on demand
           by remember(), so a page that never prints them never pays for them.
           Writes to `settings` flush both layers (see update()/insert()/remove()). */
        $map = $this->site_cache()->remember_all(function () {
            $this->connect();
            $map = array();
            $result = $this->run_sql("SELECT `name`, `value` FROM `settings` WHERE LENGTH(`value`) <= 1024 ");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $map[(string) $row["name"]] = $row["value"];
                }
                $result->free();
            }
            return $map;
        });
        if (array_key_exists($key, $map)) {
            return $map[$key];
        }
        /* Either a large value or a setting that does not exist: ask once, then cache the answer. */
        return $this->site_cache()->remember($key, function () use ($key) {
            $this->connect();
            $result = $this->run_sql("SELECT * FROM `settings` WHERE `name` = '" . mysqli_real_escape_string($this->ketnoi, $key) . "' ");
            $row = $result ? $result->fetch_array() : null;
            if ($result) {
                $result->free();
            }
            return ($row && isset($row["value"])) ? $row["value"] : "";
        });
    }

    private static $site_cache_shared = null;

    private function site_cache()
    {
        if (self::$site_cache_shared === null) {
            self::$site_cache_shared = new SettingsCache();
        }
        return self::$site_cache_shared;
    }

    /** How many times the bulk settings query ran in this process (should be 0 or 1). */
    public function settings_loads()
    {
        return $this->site_cache()->map_loads();
    }

    /** Public hook for long-running workers and tests; a normal request never needs it. */
    public function flush_site_cache()
    {
        $this->site_cache()->flush();
    }
    /**
     * Run $callback inside a transaction; commit only when it returns truthy.
     * The ledger row and the balance update in users::AddCredits() must move together — before this,
     * a failed UPDATE left a `dongtien` row that claimed money had moved. On a MyISAM table the
     * engine ignores the transaction (same behaviour as before, no worse).
     */
    public function transaction($callback)
    {
        $this->connect();
        $started = @mysqli_begin_transaction($this->ketnoi);
        try {
            $result = $callback();
        } catch (Throwable $error) {
            if ($started) {
                @mysqli_rollback($this->ketnoi);
            }
            error_log("[orbit-db] transaction rolled back: " . $error->getMessage());
            return false;
        }
        if ($started) {
            if ($result) {
                @mysqli_commit($this->ketnoi);
            } else {
                @mysqli_rollback($this->ketnoi);
            }
        }
        return $result;
    }
    public function query($sql)
    {
        $this->connect();
        $row = $this->run_sql($sql);
        return $row;
    }
    /**
     * Rows changed by the last INSERT/UPDATE/DELETE on this connection.
     *
     * Needed to make a status change a claim: `UPDATE … SET status='completed' WHERE id=? AND
     * status='pending'` credits only the request that actually moved the row, so a payment
     * provider that retries its callback cannot credit twice.
     */
    public function affectedRows()
    {
        return $this->ketnoi ? (int) mysqli_affected_rows($this->ketnoi) : 0;
    }
    /**
     * Normalise an amount before it is put into arithmetic SQL. Not a security boundary on its own
     * (callers already escape), but "1abc" or "" used to reach MySQL, which casts them to 1 and 0 —
     * a credit log that disagrees with the balance. A malformed amount is refused and logged.
     */
    private function amount($sotien)
    {
        if (!is_numeric($sotien)) {
            error_log("[orbit-db] refused a non-numeric amount");
            return false;
        }
        return (float) $sotien;
    }
    public function cong($table, $data, $sotien, $where)
    {
        $this->connect();
        if (($sotien = $this->amount($sotien)) === false) {
            return false;
        }
        $row = $this->run_sql("UPDATE `" . $table . "` SET `" . $data . "` = `" . $data . "` + '" . $sotien . "' WHERE " . $where . " ");
        return $row;
    }
    public function tru($table, $data, $sotien, $where)
    {
        $this->connect();
        if (($sotien = $this->amount($sotien)) === false) {
            return false;
        }
        $row = $this->run_sql("UPDATE `" . $table . "` SET `" . $data . "` = `" . $data . "` - '" . $sotien . "' WHERE " . $where . " ");
        return $row;
    }
    /**
     * Render one PHP value as a SQL literal. NULL must stay NULL: `'NULL'` is a five-character string,
     * which is how a cleared 2FA token used to become a token that could literally be submitted, and
     * how a nullable product_code became an empty string instead of "no code".
     */
    private function sql_value($value)
    {
        if ($value === NULL) {
            return "NULL";
        }
        return "'" . mysqli_real_escape_string($this->ketnoi, (string) $value) . "'";
    }
    public function insert($table, $data)
    {
        $this->connect();
        $field_list = "";
        $value_list = "";
        foreach ($data as $key => $value) {
            $field_list .= "," . $key;
            /* INSERT keeps the legacy empty-string form: the schema is not shipped with the code, and
               a NOT NULL product_code is written as NULL by every supplier purchase. UPDATE is where
               "clear this token" is meant literally, so only it renders SQL NULL. */
            $value_list .= ",'" . mysqli_real_escape_string($this->ketnoi, (string) $value) . "'";
        }
        $sql = "INSERT INTO " . $table . "(" . trim($field_list, ",") . ") VALUES (" . trim($value_list, ",") . ")";
        $result = $this->run_sql($sql);
        if ($table === "settings") {
            $this->site_cache()->flush();
        }
        return $result;
    }
    /**
     * The AUTO_INCREMENT value produced by the last INSERT on this connection (0 when there was none).
     * Campaigns need their own id to attach recipients: reading "the newest row" instead races another
     * admin's insert and can attach one campaign's recipients to another.
     */
    public function insertId()
    {
        return $this->ketnoi ? (int) mysqli_insert_id($this->ketnoi) : 0;
    }
    public function update($table, $data, $where)
    {
        $this->connect();
        $sql = "";
        foreach ($data as $key => $value) {
            $sql .= $key . " = " . $this->sql_value($value) . ",";
        }
        $sql = "UPDATE " . $table . " SET " . trim($sql, ",") . " WHERE " . $where;
        $result = $this->run_sql($sql);
        if ($table === "settings") {
            $this->site_cache()->flush();
        }
        return $result;
    }
    public function update_value($table, $data, $where, $value1)
    {
        $this->connect();
        $sql = "";
        foreach ($data as $key => $value) {
            $sql .= $key . " = " . $this->sql_value($value) . ",";
        }
        $sql = "UPDATE " . $table . " SET " . trim($sql, ",") . " WHERE " . $where . " LIMIT " . $value1;
        return $this->run_sql($sql);
    }
    public function remove($table, $where)
    {
        $this->connect();
        $sql = "DELETE FROM " . $table . " WHERE " . $where;
        $result = $this->run_sql($sql);
        if ($table === "settings") {
            $this->site_cache()->flush();
        }
        return $result;
    }
    public function get_list($sql)
    {
        $this->connect();
        $result = $this->run_sql($sql);
        if(!$result) {
            return [];
        }
        $return = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $return[] = $row;
        }
        mysqli_free_result($result);
        return $return;
    }
    public function get_row($sql)
    {
        $this->connect();
        $result = $this->run_sql($sql);
        if(!$result) {
            return false;
        }
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        if($row) {
            return $row;
        }
        return false;
    }
    public function num_rows($sql)
    {
        $this->connect();
        /*
         * Pagination totals only need the number of rows. Counting in the database avoids sending
         * every matching row to PHP; queries whose row count is not a plain COUNT fall back to the
         * original statement (see libs/sql_count.php).
         */
        $countSql = orbit_count_sql($sql);
        if($countSql !== null) {
            $result = $this->run_sql($countSql);
            if(!$result) {
                return false;
            }
            $row = mysqli_fetch_assoc($result);
            mysqli_free_result($result);
            $count = $row && isset($row["orbit_count"]) ? (int) $row["orbit_count"] : 0;
            return $count > 0 ? $count : false;
        }
        $result = $this->run_sql($sql);
        if(!$result) {
            return false;
        }
        $row = mysqli_num_rows($result);
        mysqli_free_result($result);
        if($row) {
            return $row;
        }
        return false;
    }
}

?>
