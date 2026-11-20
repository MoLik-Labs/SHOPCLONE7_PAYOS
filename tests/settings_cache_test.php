<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
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

/* ------------------------------------------------------- bulk settings map */

$bulk = new SettingsCache();
$bulkLoads = 0;
$rows = array("title" => "Orbit Shop", "currency" => "VND", "hotline" => "0909 123 456");
$loader = function () use (&$bulkLoads, $rows) {
    ++$bulkLoads;
    return $rows;
};
$map = $bulk->remember_all($loader);
check("the bulk loader returns every small setting", $map === $rows);
check("…and runs exactly once for many reads", $bulk->remember_all($loader) === $rows && $bulkLoads === 1
    && $bulk->map_loads() === 1);
check("the map is visible to size()", $bulk->size() === 3);

/* A key that the bulk query deliberately skipped (a large blob) is still fetchable and cached. */
$largeLoads = 0;
$faq = $bulk->remember("page_faq", function () use (&$largeLoads) {
    ++$largeLoads;
    return "<h2>FAQ</h2>";
});
check("a large value outside the map loads on demand", $faq === "<h2>FAQ</h2>" && $largeLoads === 1);
check("…and is cached like any other key", $bulk->remember("page_faq", function () use (&$largeLoads) {
    return "unexpected";
}) === "<h2>FAQ</h2>" && $largeLoads === 1);

$bulk->flush();
check("flush drops the bulk map too", $bulk->has_all() === false);
$bulk->remember_all($loader);
check("a read after a write reloads the map", $bulkLoads === 2 && $bulk->map_loads() === 2);

/* ------------------------------------------------------------- wiring */

$db = (string) file_get_contents(__DIR__ . "/../libs/db.php");
check("DB loads the cache helper", strpos($db, 'require_once __DIR__ . "/settings_cache.php"') !== false);
check("DB::site bulk-loads small settings once", strpos($db, "site_cache()->remember_all") !== false
    && strpos($db, "LENGTH(`value`) <= 1024") !== false);
check("DB::site still has a per-key fallback for large values", strpos($db, "site_cache()->remember(") !== false);
check("settings writes flush the cache (update)", preg_match('/function update\(.*?settings.*?flush/s', $db) === 1);
check("settings writes flush the cache (insert)", preg_match('/function insert\(.*?settings.*?flush/s', $db) === 1);
check("settings writes flush the cache (remove)", preg_match('/function remove\(.*?settings.*?flush/s', $db) === 1);
check("the cache is shared by all DB instances in a request",
    strpos($db, "private static \$site_cache_shared") !== false);

echo "\nsettings_cache_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
