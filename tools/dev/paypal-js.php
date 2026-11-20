<?php
/*
 * Render the inline PayPal button script from views/client/recharge-paypal.php and print it.
 *
 *   node tools/dev/phpcl.mjs tools/dev/paypal-js.php > /tmp/paypal.js && node --check /tmp/paypal.js
 *
 * The block is PHP echo statements that assemble JavaScript. `php -l` only proves the PHP parses; a
 * stray quote or an unclosed string produces a page whose Buy button silently never appears. This
 * evaluates the exact echo sequence with stub values so the gate can run `node --check` on it.
 *
 * The anchors are the first echo launching the SDK URL and the echo carrying `order: orderData`.
 * If either disappears the tool exits 3 rather than printing an empty file — an empty `node --check`
 * passes vacuously, which is worse than no check at all.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$view = dirname(__DIR__, 2) . "/views/client/recharge-paypal.php";
$lines = is_file($view) ? file($view, FILE_IGNORE_NEW_LINES) : false;
if (!$lines) {
    fwrite(STDERR, "cannot read views/client/recharge-paypal.php\n");
    exit(2);
}

$start = null;
$end = null;
foreach ($lines as $index => $line) {
    if ($start === null && strpos($line, 'echo "&currency=USD') !== false) {
        $start = $index;
    }
    if ($start !== null && strpos($line, "order: orderData") !== false) {
        $end = $index;
        break;
    }
}
if ($start === null || $end === null || $end <= $start) {
    fwrite(STDERR, "cannot locate the PayPal inline script block (anchors changed)\n");
    exit(3);
}

$block = implode("\n", array_slice($lines, $start, $end - $start + 1));

/* Evaluate the echo sequence in this process: the PHP-WASM harness has no usable PHP_BINARY for a
   child process. The stubs mirror what the view has when the shop renders it. */
class PaypalStub
{
    public function site($key)
    {
        return "stub-client-id";
    }
}
$getUser = array("id" => 7, "token" => "stub-token");
$paypalNonce = "0123456789abcdef0123456789abcdef";
if (!function_exists("BASE_URL")) {
    function BASE_URL($path)
    {
        return "https://shop.example/" . $path;
    }
}
$CMSNT = new PaypalStub();

ob_start();
try {
    eval($block);
} catch (Throwable $error) {
    ob_end_clean();
    fwrite(STDERR, "the PayPal block failed to render: " . $error->getMessage() . "\n");
    exit(3);
}
$output = ob_get_clean();

if (!is_string($output) || $output === "") {
    fwrite(STDERR, "the PayPal block produced no output\n");
    exit(3);
}

/* Keep only what a browser would execute: the inline <script> body. */
if (!preg_match('~<script>\s*(.*?)\s*</script>~s', $output, $match)) {
    fwrite(STDERR, "the rendered block has no inline <script>\n");
    exit(3);
}
if (strpos($match[1], "custom_id:") === false) {
    fwrite(STDERR, "the rendered script has no custom_id binding\n");
    exit(3);
}

echo $match[1] . "\n";
