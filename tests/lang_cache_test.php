<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * translation cache — behaviour + query count (no database).
 *
 *   php tests/lang_cache_test.php
 *
 * __() used to run two SELECTs per string (the language row, then the phrase). An admin screen
 * calls it hundreds of times, so the header alone cost hundreds of round trips. OrbitLang now
 * resolves the language once and loads its phrases in one query. This test proves the translation
 * is still correct — cookie language, default-language fallback, missing key returns the key —
 * and that the query count really dropped.
 */

define("IN_SITE", true);
ob_start(); // keep headers "unsent" so setLanguage()'s setcookie() works under the CLI

require_once __DIR__ . "/../libs/lang.php";

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

class FakeLangDB
{
    public $languages = array();
    public $translate = array();
    public $sql = array();

    public function get_row($sql)
    {
        $this->sql[] = $sql;
        if (strpos($sql, "`lang_default` = 1") !== false) {
            foreach ($this->languages as $row) {
                if ((int) $row["lang_default"] === 1) {
                    return $row;
                }
            }
            return false;
        }
        if (preg_match("/`id` = '([0-9]+)' AND `status` = 1/", $sql, $match)) {
            foreach ($this->languages as $row) {
                if ((int) $row["id"] === (int) $match[1] && (int) $row["status"] === 1) {
                    return $row;
                }
            }
            return false;
        }
        if (preg_match("/`lang` = '([^']*)' AND `status` = 1/", $sql, $match)) {
            foreach ($this->languages as $row) {
                if ($row["lang"] === $match[1] && (int) $row["status"] === 1) {
                    return $row;
                }
            }
        }
        return false;
    }

    public function get_list($sql)
    {
        $this->sql[] = $sql;
        if (preg_match("/`lang_id` = ([0-9]+)/", $sql, $match)) {
            $rows = array();
            foreach (isset($this->translate[(int) $match[1]]) ? $this->translate[(int) $match[1]] : array() as $name => $value) {
                $rows[] = array("name" => $name, "value" => $value);
            }
            return $rows;
        }
        return array();
    }

    public function count($needle)
    {
        $total = 0;
        foreach ($this->sql as $statement) {
            if (strpos($statement, $needle) !== false) {
                $total++;
            }
        }
        return $total;
    }
}

$db = new FakeLangDB();
$db->languages = array(
    array("id" => 1, "lang" => "vi", "status" => 1, "lang_default" => 1),
    array("id" => 2, "lang" => "en", "status" => 1, "lang_default" => 0),
    array("id" => 3, "lang" => "fr", "status" => 0, "lang_default" => 0),
);
$db->translate = array(
    1 => array("Xin chào" => "Xin chào", "Chỉ có VI" => "Chỉ có VI"),
    2 => array("Xin chào" => "Hello", "Only EN" => "Only EN"),
);
$GLOBALS["CMSNT"] = $db;

/* ------------------------------------------------- chosen language + one bulk query */

$_COOKIE["language"] = "en";
OrbitLang::reset();
check("__() returns the cookie language's phrase", __("Xin chào") === "Hello");
check("the language row was read exactly once", $db->count("FROM `languages`") === 1);
check("the phrases were loaded in one bulk query", $db->count("FROM `translate`") === 1);
check("the bulk query is per language, not per phrase",
    strpos($db->sql[count($db->sql) - 1], "`lang_id` = 2") !== false);

for ($i = 0; $i < 50; $i++) {
    __("Xin chào");
    __("Only EN");
}
check("50 more lookups add no queries", $db->count("FROM `translate`") === 1 && $db->count("FROM `languages`") === 1);

/* ------------------------------------------------- fallback to the default language */

check("a key missing in the chosen language falls back to the default", __("Chỉ có VI") === "Chỉ có VI");
check("…and loads the default row + map once",
    $db->count("`lang_default` = 1") === 1 && $db->count("FROM `translate`") === 2);
__("Chỉ có VI");
check("…the fallback is cached too", $db->count("FROM `translate`") === 2);

check("an unknown key returns itself", __("Không có ở đâu") === "Không có ở đâu");
check("…without another query", $db->count("FROM `translate`") === 2);
$before = $db->count("FROM `languages`");
check("getLanguage() reports the chosen language", getLanguage() === "en");
check("…using the already loaded row", $db->count("FROM `languages`") === $before);

/* ------------------------------------------------- no cookie, disabled and hostile cookies */

OrbitLang::reset();
$_COOKIE = array();
check("without a cookie the default language is used", __("Xin chào") === "Xin chào");

OrbitLang::reset();
$db->sql = array();
$_COOKIE["language"] = "fr"; // exists but is disabled
check("a disabled language falls back to the default", __("Xin chào") === "Xin chào");
check("…and it is only ever compared against status = 1",
    $db->count("`lang` = 'fr' AND `status` = 1") === 1);

OrbitLang::reset();
$db->sql = array();
$_COOKIE["language"] = "' OR 1=1 -- ";
check("an injection-shaped cookie is rejected before SQL", __("Xin chào") === "Xin chào");
check("…and no hostile text reaches a statement", strpos(implode("\n", $db->sql), "OR 1=1") === false);

/* ------------------------------------------------- switching language inside a request */

OrbitLang::reset();
$_COOKIE["language"] = "vi";
__("Xin chào");
check("switching language validates the id", setLanguage("2 OR 1=1") === false);
check("a disabled language cannot be selected", setLanguage(3) === false);
check("an enabled language is accepted", setLanguage(2) === true);
check("…and the next __() speaks it", __("Xin chào") === "Hello");

/* ------------------------------------------------- wiring: views still call __() and the file kept its guard */

$langFile = (string) file_get_contents(__DIR__ . "/../libs/lang.php");
check("the library refuses direct access", strpos($langFile, 'if(!defined("IN_SITE"))') !== false);
check("__() delegates to the cache", strpos($langFile, 'return OrbitLang::text($name);') !== false);
check("a language switch resets the cache", strpos($langFile, "OrbitLang::reset();") !== false);

ob_end_flush();
echo "\n" . $pass . " passed, " . $fail . " failed.\n";
exit($fail === 0 ? 0 : 1);
