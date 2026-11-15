<?php
/*
 * settings read cache — semantics + wiring (no database).
 *
 *   php tests/settings_cache_test.php
 *
 * The cache exists to remove repeated `SELECT * FROM settings WHERE name = …` round trips from a
 * page render. It is only safe if it is flushed by every write, so this test pins both halves:
 * the class behaviour, and the fact that DB::site() uses it and DB::update()/insert()/remove()
 * flush it. (The DB class itself needs MySQL, which this sandbox does not have; the source checks
 * below are clearly labelled as wiring checks, not a live query-count measurement.)
 */

define("IN_SITE", true);
require_once __DIR__ . "/../libs/settings_cache.php";

$pass = 0;
$fail = 0;
function check($label, $condition, $detail = "")
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($detail !== "" ? "   >> " . $detail : "") . "\n";
    }
}

$cache = new SettingsCache();
$loads = 0;
$loader = function () use (&$loads) {
    $loads++;
    return "Orbit Shop";
};

check("a missing value runs the loader", $cache->remember("title", $loader) === "Orbit Shop");
check("the second read is served from memory", $cache->remember("title", $loader) === "Orbit Shop");
check("…and the loader ran exactly once", $loads === 1 && $cache->loads() === 1);
check("the key is now known", $cache->has("title") === true && $cache->has("description") === false);

$cache->remember("currency", function () {
    return "VND";
});
check("different keys load independently", $loads === 1 && $cache->size() === 2);

$cache->flush();
check("flush empties the cache", $cache->size() === 0 && $cache->has("title") === false);
check("a read after a write hits the loader again", $cache->remember("title", $loader) === "Orbit Shop"
    && $loads === 2);

$nullLoads = 0;
$missing = $cache->remember("does_not_exist", function () use (&$nullLoads) {
    $nullLoads++;
    return null;
});
check("a missing row is cached as null",
    $missing === null && $cache->remember("does_not_exist", function () use (&$nullLoads) {
        $nullLoads++;
        return "unexpected";
    }) === null && $nullLoads === 1);

$cache->remember(123, function () {
    return "numeric key";
});
check("numeric keys are usable", $cache->remember("123", function () {
    return "not this";
}) === "numeric key");

/* ------------------------------------------------------------- wiring */

$db = (string) file_get_contents(__DIR__ . "/../libs/db.php");
check("DB loads the cache helper", strpos($db, 'require_once __DIR__ . "/settings_cache.php"') !== false);
check("DB::site reads through the cache", strpos($db, "site_cache()->remember") !== false);
check("settings writes flush the cache (update)", preg_match('/function update\(.*?settings.*?flush/s', $db) === 1);
check("settings writes flush the cache (insert)", preg_match('/function insert\(.*?settings.*?flush/s', $db) === 1);
check("settings writes flush the cache (remove)", preg_match('/function remove\(.*?settings.*?flush/s', $db) === 1);
check("the cache is shared by all DB instances in a request",
    strpos($db, "private static \$site_cache_shared") !== false);

echo "\nsettings_cache_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
