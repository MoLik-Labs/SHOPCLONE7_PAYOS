<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * check_string() — the escape hatch every handler uses on request values.
 *
 * A form can post `field[]=x`, which arrives as an array. check_string() used to pass it straight to
 * addslashes(): a TypeError on PHP 8, so any endpoint that called it answered a crafted request with
 * a 500 (and, worse, stopped before its own validation). Every non-string now becomes "".
 *
 *   php tests/check_string_test.php
 */

define('IN_SITE', true);
class DB
{
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_row($query) { return false; }
    public function get_list($query) { return array(); }
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
require __DIR__ . '/../libs/helper.php';

$checks = 0;
$failures = array();
function cs_is($expected, $actual, $name)
{
    global $checks, $failures;
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = $name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
}

/* Strings keep their exact legacy escaping: quotes escaped for SQL, tags encoded for HTML, trimmed. */
cs_is('abc', check_string('abc'), 'a plain string is unchanged');
cs_is('a b', check_string('  a b  '), 'surrounding whitespace is trimmed');
/* htmlspecialchars() runs last, so the backslash from addslashes() stays visible before the entity. */
cs_is('a\&#039;b', check_string("a'b"), 'a single quote is escaped for SQL and encoded for HTML');
cs_is('&lt;b&gt;', check_string('<b>'), 'markup is encoded for HTML');
cs_is('a\&quot;b', check_string('a"b'), 'a double quote is escaped for SQL and encoded for HTML');

/* Scalars that used to be coerced still coerce. */
cs_is('42', check_string(42), 'an integer becomes its decimal string');
cs_is('4.5', check_string(4.5), 'a float becomes its decimal string');

/* Everything that is not a scalar becomes the empty string instead of a TypeError. */
cs_is('', check_string(array('x')), 'an array becomes an empty string');
cs_is('', check_string(array()), 'an empty array becomes an empty string');
cs_is('', check_string(null), 'null becomes an empty string');
cs_is('', check_string(true), 'true becomes an empty string, not "1"');
cs_is('', check_string(false), 'false becomes an empty string');
cs_is('', check_string(new stdClass()), 'an object becomes an empty string');

/* The whole point: no warning, no exception, on any of the shapes a request can carry. */
$threw = false;
try {
    foreach (array(array('a' => array('b' => 'c')), null, true, 1.5) as $probe) {
        check_string($probe);
    }
} catch (Throwable $error) {
    $threw = true;
}
cs_is(false, $threw, 'no request shape raises a TypeError');

/* A nested array from `field[a][b]=1` is refused the same way. */
cs_is('', check_string(array('a' => array('b' => 'c'))), 'a nested array becomes an empty string');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDOUT, 'FAIL  ' . $failure . "\n");
    }
}
echo "\n" . $checks . " checks, " . count($failures) . " failure(s)\n";
exit($failures ? 1 : 0);
