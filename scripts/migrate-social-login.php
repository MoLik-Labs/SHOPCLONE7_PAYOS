<?php
/**
 * Social sign-in schema (Google / GitHub through Supabase).
 *
 * Creates exactly one new table and seeds seven settings rows that are missing. It does not touch
 * `users`, any order, any balance or any existing setting value: running it twice is a no-op.
 *
 * Usage:
 *   php scripts/migrate-social-login.php            # create table + missing settings rows
 *   php scripts/migrate-social-login.php --check    # report only, change nothing
 *   php scripts/migrate-social-login.php --rollback # drop oauth_identities (settings are kept)
 *
 * Why a separate table: a (provider, subject) pair is the stable identity Google/GitHub return.
 * Storing it beside the user lets one local account survive an email change at the provider, and
 * lets the admin audit which accounts were entered through which provider. Passwords are untouched.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from the command line.');
}
if (!defined('IN_SITE')) {
    define('IN_SITE', true);
}

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();

function social_env($name, $default = '')
{
    $value = isset($_ENV[$name]) ? (string) $_ENV[$name] : '';
    if ($value === '' && getenv($name) !== false) {
        $value = (string) getenv($name);
    }
    return $value === '' ? $default : $value;
}

function social_db()
{
    $host = social_env('DB_HOST', 'localhost');
    $database = social_env('DB_DATABASE');
    $port = (int) social_env('DB_PORT', '3306');
    if ($database === '' || preg_match('/[;\x00-\x1f]/', $host . $database) || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid database configuration.');
    }
    return new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
        social_env('DB_USERNAME'),
        social_env('DB_PASSWORD'),
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        )
    );
}

$table = 'oauth_identities';
$ddl = "CREATE TABLE IF NOT EXISTS `oauth_identities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(20) NOT NULL,
    `subject` VARCHAR(64) NOT NULL,
    `user_id` INT NOT NULL,
    `email` VARCHAR(190) NOT NULL DEFAULT '',
    `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `last_login_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_provider_subject` (`provider`, `subject`),
    UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
    KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$settings = array(
    'oauth_enabled' => '0',
    'oauth_google' => '0',
    'oauth_github' => '0',
    'oauth_supabase_url' => '',
    'oauth_supabase_anon_key' => '',
    'oauth_auto_create' => '1',
    'oauth_link_by_email' => '0',
);

/* Columns the login code writes. Checked up front so a missing column is a clear message here,
   not a half-created account during a customer's login. */
$requiredUserColumns = array(
    'id', 'username', 'email', 'password', 'token', 'admin', 'banned',
    'status_2fa', 'status_otp_mail', 'ip', 'device', 'create_date', 'update_date',
    'time_session', 'api_key', 'SecretKey_2fa', 'ref_id', 'utm_source', 'time_request',
);

try {
    $db = social_db();
    $action = isset($argv[1]) ? (string) $argv[1] : '';
    if (!in_array($action, array('', '--check', '--rollback'), true)) {
        fwrite(STDERR, "Unknown option: {$action}\n");
        exit(1);
    }

    $tableExists = (bool) $db->query("SHOW TABLES LIKE 'oauth_identities'")->fetchColumn();

    if ($action === '--rollback') {
        if ($tableExists) {
            $db->exec('DROP TABLE IF EXISTS `oauth_identities`');
            echo "Dropped oauth_identities. Social logins stop working immediately.\n";
        } else {
            echo "oauth_identities was not installed; nothing to drop.\n";
        }
        echo "The seven oauth_* settings rows were kept (they contain no secret).\n";
        echo "Users, orders, balances and passwords were not touched.\n";
        exit(0);
    }

    $missingColumns = array();
    foreach ($requiredUserColumns as $column) {
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = :column"
        );
        $statement->execute(array(':column' => $column));
        if ((int) $statement->fetchColumn() === 0) {
            $missingColumns[] = $column;
        }
    }
    if ($missingColumns) {
        fwrite(STDERR, "The `users` table is missing: " . implode(', ', $missingColumns) . "\n");
        fwrite(STDERR, "Social login would create incomplete accounts. Restore the shop schema first.\n");
        exit(1);
    }

    if ($action === '--check') {
        echo 'oauth_identities: ' . ($tableExists ? 'present' : 'missing') . "\n";
        $engine = $tableExists
            ? (string) $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oauth_identities'")->fetchColumn()
            : '';
        echo 'engine: ' . ($engine !== '' ? $engine : '-') . "\n";
        $count = $tableExists ? (int) $db->query('SELECT COUNT(*) FROM `oauth_identities`')->fetchColumn() : 0;
        echo 'linked identities: ' . $count . "\n";
        $present = 0;
        foreach ($settings as $name => $default) {
            $statement = $db->prepare('SELECT COUNT(*) FROM `settings` WHERE `name` = :name');
            $statement->execute(array(':name' => $name));
            $present += (int) $statement->fetchColumn() > 0 ? 1 : 0;
        }
        echo 'oauth settings rows: ' . $present . '/' . count($settings) . "\n";
        echo "No change was made (--check).\n";
        exit(0);
    }

    $db->exec($ddl);
    $seeded = 0;
    $insert = $db->prepare('INSERT INTO `settings` (`name`, `value`) VALUES (:name, :value)');
    foreach ($settings as $name => $default) {
        $check = $db->prepare('SELECT COUNT(*) FROM `settings` WHERE `name` = :name');
        $check->execute(array(':name' => $name));
        if ((int) $check->fetchColumn() === 0) {
            $insert->execute(array(':name' => $name, ':value' => $default));
            $seeded++;
        }
    }
    echo "Social login migration complete.\n";
    echo '  table oauth_identities: ' . ($tableExists ? 'already present' : 'created') . "\n";
    echo '  settings rows inserted: ' . $seeded . " (existing values untouched)\n";
    echo "Next: admin Settings -> Đăng nhập mạng xã hội, then Test connection before saving.\n";
    echo "Rollback: php scripts/migrate-social-login.php --rollback\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
