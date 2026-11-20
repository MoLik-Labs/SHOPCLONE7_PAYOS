<?php
/*
 * Pagination totals count in the database.
 *
 * DB::num_rows() used to run the page query and read every row through mysqli just to count it.
 * orbit_count_sql() rewrites only the statements whose row count is a plain table count; everything
 * else falls back to the original query. These checks pin both halves: the rewrites that must
 * happen, and the queries that must never be rewritten.
 */

define('IN_SITE', true);
require __DIR__ . '/../libs/sql_count.php';

$checks = 0;
$failures = 0;
function check_count($name, $actual, $expected)
{
    global $checks, $failures;
    ++$checks;
    if ($actual === $expected) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . "\n      expected " . var_export($expected, true) . "\n      got      " . var_export($actual, true) . "\n";
}

$countHead = 'SELECT COUNT(*) AS `orbit_count` ';

/* ---- rewrites that must happen ------------------------------------------- */
check_count('a plain table scan becomes COUNT(*)',
    orbit_count_sql(' SELECT * FROM `logs` WHERE `id` > 5 ORDER BY id DESC'),
    $countHead . 'FROM `logs` WHERE `id` > 5');
check_count('a backticked column list becomes COUNT(*)',
    orbit_count_sql('SELECT `id` FROM `users` WHERE `banned` = 0 ORDER BY `id` DESC'),
    $countHead . 'FROM `users` WHERE `banned` = 0');
check_count('multiple backticked columns are accepted',
    orbit_count_sql('SELECT `id`, `user_id` FROM `logs`'),
    $countHead . 'FROM `logs`');
check_count('a query without WHERE is accepted',
    orbit_count_sql('SELECT * FROM `promotions` '),
    $countHead . 'FROM `promotions` ');
check_count('a table alias is kept',
    orbit_count_sql('SELECT * FROM `logs` l WHERE l.id = 1 ORDER BY l.id'),
    $countHead . 'FROM `logs` l WHERE l.id = 1');
check_count('a parenthesis in WHERE does not stop the rewrite',
    orbit_count_sql("SELECT * FROM `product_order` WHERE DATE(create_gettime) = '2026-01-01' ORDER BY id DESC"),
    $countHead . "FROM `product_order` WHERE DATE(create_gettime) = '2026-01-01'");
check_count('a literal containing the words ORDER BY is not truncated mid-string',
    orbit_count_sql("SELECT * FROM `logs` WHERE `action` = 'x ORDER BY id' ORDER BY id DESC"),
    $countHead . "FROM `logs` WHERE `action` = 'x ORDER BY id'");
check_count('a backticked identifier containing ORDER BY is handled',
    orbit_count_sql('SELECT * FROM `logs` WHERE `action` = \'ORDER BY\' ORDER BY `id` ASC'),
    $countHead . 'FROM `logs` WHERE `action` = \'ORDER BY\'');
check_count('leading whitespace is tolerated',
    orbit_count_sql('   SELECT * FROM `logs`  '),
    $countHead . 'FROM `logs`  ');

/* ---- queries that must keep their original form --------------------------- */
check_count('DISTINCT is not rewritten', orbit_count_sql('SELECT DISTINCT `id` FROM `logs`'), null);
check_count('GROUP BY is not rewritten', orbit_count_sql('SELECT * FROM `logs` GROUP BY `user_id`'), null);
check_count('HAVING is not rewritten', orbit_count_sql('SELECT * FROM `logs` HAVING COUNT(*) > 1'), null);
check_count('LIMIT is not rewritten', orbit_count_sql('SELECT * FROM `logs` LIMIT 10'), null);
check_count('UNION is not rewritten', orbit_count_sql('SELECT * FROM `logs` UNION SELECT * FROM `logs`'), null);
check_count('an expression in the select list is not rewritten', orbit_count_sql('SELECT COUNT(id) FROM `logs`'), null);
check_count('a FROM subquery is not rewritten', orbit_count_sql('SELECT * FROM (SELECT 1) x'), null);
check_count('an IN (SELECT …) is not rewritten',
    orbit_count_sql('SELECT * FROM `logs` WHERE `user_id` IN (SELECT `id` FROM `users`)'), null);
check_count('a JOIN is not rewritten',
    orbit_count_sql('SELECT * FROM `logs` l JOIN `users` u ON u.id = l.user_id'), null);
check_count('a statement without FROM is not rewritten', orbit_count_sql('SELECT 1'), null);
check_count('a non-SELECT statement is not rewritten', orbit_count_sql('DELETE FROM `logs`'), null);
check_count('a non-string is refused', orbit_count_sql(null), null);
check_count('an empty string is refused', orbit_count_sql(''), null);

/* ---- wiring --------------------------------------------------------------- */
$db = (string) file_get_contents(__DIR__ . '/../libs/db.php');
check_count('num_rows asks for the count rewrite', strpos($db, 'orbit_count_sql($sql)') !== false, true);
check_count('num_rows reads the counted integer, not every row', strpos($db, 'mysqli_fetch_assoc($result)') !== false, true);
check_count('the rewrite lives in its own include', is_file(__DIR__ . '/../libs/sql_count.php'), true);
check_count('the include is loaded before the DB class is used', strpos($db, 'sql_count.php') < strpos($db, 'class DB'), true);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
