<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
// Exercise the real endpoint in isolated subprocesses with fake dependencies. Never opens a DB/network.
if (PHP_OS_FAMILY === 'Unknown' || !function_exists('proc_open') || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) { echo "SKIP: native PHP with proc_open is required for checkout endpoint fixtures.\n"; exit(0); }
$root = sys_get_temp_dir() . '/orbit-preflight-' . bin2hex(random_bytes(10));
mkdir($root . '/ajaxs/client', 0700, true);
mkdir($root . '/libs/database', 0700, true);
try {
    copy(__DIR__ . '/../ajaxs/client/product.php', $root . '/ajaxs/client/product.php');
    foreach (['config.php', 'libs/lang.php', 'libs/helper.php', 'libs/sendEmail.php', 'libs/suppliers.php', 'libs/database/users.php'] as $file) {
        file_put_contents($root . '/' . $file, "<?php\n");
    }
    file_put_contents($root . '/libs/db.php', <<<'PHP'
<?php
$scenario = $argv[1];
$_REQUEST = ['action'=>'buyProduct', 'api_key'=>'fake-key', 'client_order_id'=>'abcdef01-2345', 'id'=>'1', 'amount'=>'2'];
if ($scenario === 'no-key') { unset($_REQUEST['client_order_id']); }
if ($scenario === 'no-product-id') { unset($_REQUEST['id']); }
if ($scenario === 'no-amount') { unset($_REQUEST['amount']); }
if ($scenario === 'invalid-amount') { $_REQUEST['amount'] = '1e3'; }
if ($scenario === 'minimum') { $_REQUEST['amount'] = '1'; }
if ($scenario === 'maximum') { $_REQUEST['amount'] = '6'; }
if ($scenario === 'coupon-balance') { $_REQUEST['coupon'] = 'FAKE'; }
class Mobile_Detect {}
class FixtureDB {
    public function site($name) { return $name === 'status' ? 1 : ($name === 'thoi_gian_mua_cach_nhau' ? 5 : 0); }
    public function get_row($query) {
        global $scenario;
        if (strpos($query, 'FROM `users`') !== false) { return ['id'=>7, 'banned'=>0, 'time_request'=>$scenario === 'throttle' ? time() : 0, 'discount'=>0]; }
        if (strpos($query, 'FROM `product_order`') !== false) {
            return $scenario === 'prior-order' ? ['product_id'=>1, 'trash'=>1] : false;
        }
        if (strpos($query, 'FROM `products`') !== false) {
            if ($scenario === 'missing-product') { return false; }
            return ['id'=>1, 'name'=>'Fixture', 'min'=>2, 'max'=>5, 'supplier_id'=>$scenario === 'supplier' ? 3 : 0, 'code'=>'fixture'];
        }
        if (strpos($query, 'FROM `product_stock`') !== false) { return ['COUNT(id)'=>$scenario === 'stock' ? 0 : 100]; }
        if (strpos($query, 'FROM `suppliers`') !== false) { return false; }
        throw new Exception('Unexpected fixture query');
    }
}
$CMSNT = new FixtureDB();
function __($s) { return $s; }
function check_string($s) { return (string) $s; }
function orbit_order_id($buyer, $key) { return 'W' . substr(hash('sha256', $buyer . '|' . $key), 0, 30); }
function order_quantity($v) { return is_string($v) && preg_match('/^\d+$/D', $v) ? (int) $v : false; }
function format_cash($x) { return (string) $x; }
function orbit_unit_price($product) { return 100; }
function random($alphabet, $length) { return str_repeat('A', $length); }
function getDiscount($a, $id) { return 0; }
function getRowRealtime($table, $id, $field) { global $scenario; return $scenario === 'debit' ? 1000 : 0; }
function orbit_coupon_claim($id, $coupon, $buyer, $money, $trans) { file_put_contents(getenv('FIXTURE_TRACE'), 'coupon\n', FILE_APPEND); return 0; }
class users {
    public function DebitCreditsChecked($buyer, $pay, $msg, $key) { file_put_contents(getenv('FIXTURE_TRACE'), 'debit\n', FILE_APPEND); return 'insufficient'; }
}
PHP
    );
    $cases = [
        'throttle'=>[true, ''], 'no-product-id'=>[true, ''], 'missing-product'=>[true, ''],
        'no-amount'=>[true, ''], 'invalid-amount'=>[true, ''], 'minimum'=>[true, ''],
        'maximum'=>[true, ''], 'stock'=>[true, ''], 'supplier'=>[true, ''], 'balance'=>[true, ''],
        'no-key'=>[false, ''], 'prior-order'=>[false, ''], 'coupon-balance'=>[false, 'coupon'], 'debit'=>[false, 'debit'],
    ];
    $checks = 0;
    foreach ($cases as $scenario => $expected) {
        $trace = $root . '/trace';
        @unlink($trace);
        $env = array_merge($_ENV, ['FIXTURE_TRACE'=>$trace]);
        // Inherit process-only variables such as PHP_INI_SCAN_DIR and library paths.
        foreach (['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'HOME'] as $name) {
            $value = getenv($name); if ($value !== false) { $env[$name] = $value; }
        }
        $proc = proc_open([PHP_BINARY, $root . '/ajaxs/client/product.php', $scenario], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root, $env);
        if (!is_resource($proc)) { throw new RuntimeException('Could not start fixture'); }
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($proc);
        $response = json_decode($stdout, true);
        $events = is_file($trace) ? file_get_contents($trace) : '';
        if ($exit !== 0 || $stderr !== '' || !is_array($response) || ($response['status'] ?? '') !== 'error') {
            throw new RuntimeException("Unexpected response for $scenario: exit=$exit stdout=$stdout stderr=$stderr");
        }
        $isTagged = ($response['outcome'] ?? '') === 'not_started' && ($response['request_id'] ?? '') === 'abcdef01-2345';
        $unexpectedFields = !$expected[0] && (array_key_exists('outcome', $response) || array_key_exists('request_id', $response));
        $expectedEvents = $expected[1] === '' ? '' : $expected[1] . '\\n';
        if ($isTagged !== $expected[0] || $unexpectedFields || $events !== $expectedEvents) {
            throw new RuntimeException("Wrong preflight or side effects for $scenario: $stdout events=$events");
        }
        ++$checks;
        echo "PASS  $scenario\n";
    }
    echo "checkout_preflight_test: $checks passed, 0 failed (isolated fake endpoint)\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
