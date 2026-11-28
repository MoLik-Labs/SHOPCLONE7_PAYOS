<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
if (PHP_OS_FAMILY === 'Unknown' || !function_exists('proc_open') || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) { echo "SKIP: native PHP with proc_open is required for auth endpoint fixtures.\n"; exit(0); }
// Run the real handler and origin resolver against fake DB and mail only, never real credentials.
$root = sys_get_temp_dir() . '/orbit-auth-' . bin2hex(random_bytes(10));
mkdir($root . '/ajaxs/client', 0700, true); mkdir($root . '/libs', 0700, true);
try {
    copy(__DIR__ . '/../ajaxs/client/auth.php', $root . '/ajaxs/client/auth.php');
    copy(__DIR__ . '/../libs/helper.php', $root . '/libs/helper.php');
    foreach (['request_security.php', 'money.php', 'clock.php'] as $file) { copy(__DIR__ . '/../libs/' . $file, $root . '/libs/' . $file); }
    file_put_contents($root . '/config.php', "<?php\n"); file_put_contents($root . '/libs/lang.php', "<?php function __(\$s) { return \$s; }\n");
    file_put_contents($root . '/libs/sendEmail.php', <<<'PHP'
<?php
function sendCSM($email, $name, $subject, $content, $sender) {
    file_put_contents(getenv('TRACE'), "mail:" . $content . "\n", FILE_APPEND); return true;
}
PHP
    );
    file_put_contents($root . '/libs/db.php', <<<'PHP'
<?php
$scenario = $argv[1];
$_SERVER['HTTP_HOST'] = 'attacker.example'; $_SERVER['SERVER_NAME'] = 'attacker.example'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_ENV['APP_URL'] = $scenario === 'missing-config' ? '' : 'https://shop.example';
$_POST = ['action'=>'ForgotPassword', 'email'=>'fixture@example.test'];
if (strpos($scenario, 'reset-') === 0) { $_POST = ['action'=>'ChangePassword', 'token'=>'ab12', 'newpassword'=>'fixture123', 'renewpassword'=>'fixture123']; }
class Mobile_Detect { function getUserAgent() { return 'fixture'; } }
class DB {
    private $affected = 1;
    public function site($key) {
        $values = ['status'=>1, 'status_demo'=>0, 'smtp_password'=>'fake', 'email_temp_subject_forgot_password'=>'reset', 'email_temp_content_forgot_password'=>'{domain} {link}', 'title'=>'fixture', 'type_password'=>'bcrypt', 'timezone'=>'UTC'];
        return $values[$key] ?? '';
    }
    public function get_row($query) {
        global $scenario;
        if (strpos($query, 'block_ip') !== false || $scenario === 'unknown') { return false; }
        return ['id'=>7, 'username'=>'fixture', 'email'=>'fixture@example.test', 'time_forgot_password'=>$scenario === 'throttled' ? time() : time()-70, 'token_forgot_password'=>'ab12', 'banned'=>0];
    }
    public function update($table, $data, $where) {
        global $scenario;
        file_put_contents(getenv('TRACE'), 'update:' . $where . "\n", FILE_APPEND);
        $this->affected = $scenario === 'reset-stale' ? 0 : 1;
        return $scenario !== 'write-failure';
    }
    public function affectedRows() { return $this->affected; }
    public function insert($table, $data) { file_put_contents(getenv('TRACE'), "insert\n", FILE_APPEND); return true; }
}
PHP
    );
    $cases = ['known', 'unknown', 'throttled', 'missing-config', 'write-failure', 'reset-stale'];
    $responses = [];
    foreach ($cases as $case) {
        $trace = $root . '/trace'; @unlink($trace);
        $env = array_merge($_ENV, ['TRACE'=>$trace]);
        foreach (['PATH','PHPRC','PHP_INI_SCAN_DIR','LD_LIBRARY_PATH','HOME'] as $name) { $value=getenv($name); if ($value !== false) { $env[$name]=$value; } }
        $proc=proc_open([PHP_BINARY, $root . '/ajaxs/client/auth.php', $case], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, $env);
        if (!is_resource($proc)) { throw new RuntimeException('Cannot start fixture'); }
        fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($proc);
        $json=json_decode($out, true); $events=is_file($trace) ? file_get_contents($trace) : '';
        if ($exit !== 0 || !is_array($json) || ($err !== '' && !in_array($case, ['missing-config', 'write-failure'], true))) { throw new RuntimeException("$case exit=$exit out=$out err=$err"); }
        if ($case === 'missing-config' && strpos($err, 'password reset disabled') === false) { throw new RuntimeException('Missing configuration was not logged'); }
        if ($case === 'write-failure' && strpos($err, 'could not persist') === false) { throw new RuntimeException('Failed write was not logged'); }
        $responses[$case]=$json;
        if ($case === 'known' && (!preg_match('~^update:.*\nmail:shop\.example <a[^\n]+https://shop\.example/\?action=reset-password&token=[a-f0-9]{64}~', $events) || strpos($events, 'attacker.example') !== false)) { throw new RuntimeException("bad mail or order: $events"); }
        if (in_array($case, ['unknown','throttled','missing-config'], true) && $events !== '') { throw new RuntimeException("$case had side effects: $events"); }
        if ($case === 'write-failure' && strpos($events, 'mail:') !== false) { throw new RuntimeException('Mail sent despite failed write'); }
        if ($case === 'missing-config' && ($json['status'] ?? '') !== 'error') { throw new RuntimeException('Missing config must fail closed'); }
        if ($case === 'reset-stale' && (($json['status'] ?? '') !== 'error' || strpos($events, 'insert') !== false || strpos($events, "`token_forgot_password` = 'ab12'") === false || strpos($events, '`banned` = 0') === false || strpos($events, '`time_forgot_password` >=') === false)) { throw new RuntimeException("stale token not rejected: $out $events"); }
        echo "PASS  $case\n";
    }
    foreach (['unknown','throttled','write-failure'] as $case) { if ($responses[$case] !== $responses['known']) { throw new RuntimeException("Non-neutral response: $case"); } }
    echo "PASS  neutral responses\nauth_reset_endpoint_test: 7 passed, 0 failed (isolated fake dependencies)\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($root);
}
