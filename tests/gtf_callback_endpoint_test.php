<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
if (PHP_OS_FAMILY === 'Unknown' || !function_exists('proc_open') || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) {
    echo "SKIP: native PHP with proc_open is required for callback endpoint fixtures.\n"; exit(0);
}
// Copies the real endpoint to an isolated fixture. All dependencies are fake; no DB or network.
$root = sys_get_temp_dir() . '/orbit-gtf-callback-' . bin2hex(random_bytes(10));
mkdir($root . '/api', 0700, true);
mkdir($root . '/libs', 0700, true);
try {
    copy(__DIR__ . '/../api/callback-gtf-card.php', $root . '/api/callback-gtf-card.php');
    file_put_contents($root . '/libs/gtf_card.php', "<?php\n");
    file_put_contents($root . '/libs/gtf_bootstrap.php', <<<'PHP'
<?php
function gtf_config() { global $argv; return ['partner_key' => $argv[1] === 'unconfigured' ? '' : 'fixture-key']; }
function gtf_card_service() { return new FixtureCard(); }
class FixtureCard {
    public function callback($input) {
        global $argv;
        if ($argv[1] === 'invalid') { throw new InvalidArgumentException('fixture'); }
        if ($argv[1] === 'failure') { throw new RuntimeException('fixture'); }
        return $argv[1];
    }
}
PHP
    );
    file_put_contents($root . '/probe.php', <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = $argv[1] === 'method' ? 'PUT' : 'POST';
register_shutdown_function(function () { echo "\nHTTP_STATUS=" . http_response_code(); });
require __DIR__ . '/api/callback-gtf-card.php';
PHP
    );
    $cases = [
        'busy'=>[503, 'retry_later'], 'credited'=>[200, 'payment.success'], 'pending'=>[200, 'pending'],
        'invalid'=>[400, 'callback_rejected'], 'failure'=>[503, 'retry_later'],
        'method'=>[405, 'method_not_allowed'], 'unconfigured'=>[503, 'not_configured'],
    ];
    $count = 0;
    foreach ($cases as $case => $expected) {
        $proc = proc_open([PHP_BINARY, $root . '/probe.php', $case], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('Could not start fixture'); }
        fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $rc=proc_close($proc);
        $want = $expected[1] . "\nHTTP_STATUS=" . $expected[0];
        if ($rc !== 0 || $out !== $want || ($err !== '' && $case !== 'failure')) {
            throw new RuntimeException("Unexpected $case: exit=$rc stdout=$out stderr=$err");
        }
        ++$count; echo "PASS  $case\n";
    }
    echo "gtf_callback_endpoint_test: $count passed, 0 failed (isolated fake service)\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
