<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}

define('IN_SITE', true);
class DB
{
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($query) { return false; }
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
require __DIR__ . '/../libs/helper.php';

function expect_security($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_ENV['TRUSTED_PROXY_IPS'] = '';
$_SERVER['HTTP_CLIENT_IP'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = "x' OR 1=1 --, 198.51.100.30";
expect_security(myip() === '203.0.113.10', 'Untrusted headers must not control client IP or reach SQL.');
$_ENV['TRUSTED_PROXY_IPS'] = '203.0.113.10,192.0.2.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30, 192.0.2.10';
expect_security(myip() === '198.51.100.30', 'Trusted proxy chain should yield the closest untrusted client.');
$_SERVER['HTTP_X_FORWARDED_FOR'] = "x' OR 1=1 --, 192.0.2.10";
expect_security(myip() === '203.0.113.10', 'Invalid forwarded IP must fail back to remote address.');
$_SERVER['REMOTE_ADDR'] = '';
expect_security(myip() === '0.0.0.0', 'Missing remote address must remain a safe literal.');

for ($i = 0; $i < 30; ++$i) {
    $token = random('ABC012', 64);
    expect_security(strlen($token) === 64 && preg_match('/^[ABC012]{64}$/D', $token) === 1, 'Tokens must preserve requested length and alphabet.');
    expect_security(getRandomWeightedElement(['never' => 0, 'always' => 1]) === 'always', 'Weighted selection must subtract weights.');
}
expect_security(roundMoney(126) === 100.0 && roundMoney(150) === 200.0 && roundMoney(1026) === 1000.0, 'Money rounding must perform arithmetic instead of string concatenation.');
expect_security(check_img('missing') === false, 'Missing uploads must be rejected.');
expect_security(valid_image_upload(['name' => 'evil.php', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK]) === false, 'Non-uploaded source file must be rejected.');
echo "Legacy security regression checks passed.\n";
