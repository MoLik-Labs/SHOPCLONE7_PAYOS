<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}

/*
 * Translation lookups used to cost two queries per string: the language row and the phrase row.
 * An admin page calls __() well over a hundred times, so the header alone produced hundreds of
 * round trips. OrbitLang resolves the language once per request and loads that language's phrases
 * in a single SELECT, then answers every __() from memory.
 *
 * Behaviour is unchanged: the cookie language wins when it is an enabled language, otherwise the
 * default language is used, a missing phrase falls back to the default language, and finally the
 * key itself is returned. setLanguage() resets the cache so a language switch inside one request
 * (the AJAX endpoint does that) is not served stale text.
 */
final class OrbitLang
{
    /** @var array|false|null false = resolved and absent, null = not resolved yet */
    private static $chosen = null;
    private static $default = null;
    /** @var array<int, array<string, string>> */
    private static $maps = array();
    /** @var array<string, string> */
    private static $phrases = array();

    public static function reset()
    {
        self::$chosen = null;
        self::$default = null;
        self::$maps = array();
        self::$phrases = array();
    }

    /** Only sane language codes reach the query; anything else behaves like "no cookie". */
    private static function cookieLanguage()
    {
        if (!isset($_COOKIE["language"]) || !is_string($_COOKIE["language"])) {
            return "";
        }
        $language = trim($_COOKIE["language"]);
        return preg_match('/^[A-Za-z0-9._-]{1,16}$/', $language) ? $language : "";
    }

    private static function fetchLanguage($where)
    {
        global $CMSNT;
        $row = $CMSNT->get_row("SELECT `id`, `lang` FROM `languages` WHERE " . $where . " LIMIT 1");
        return $row ? $row : false;
    }

    /** The language the visitor asked for, or the default one when the cookie names nothing usable. */
    public static function chosen()
    {
        if (self::$chosen !== null) {
            return self::$chosen;
        }
        self::$chosen = false;
        $language = self::cookieLanguage();
        if ($language !== "") {
            self::$chosen = self::fetchLanguage("`lang` = '" . $language . "' AND `status` = 1");
        }
        if (!self::$chosen) {
            self::$chosen = self::defaultRow();
        }
        return self::$chosen;
    }

    public static function defaultRow()
    {
        if (self::$default === null) {
            self::$default = self::fetchLanguage("`lang_default` = 1");
        }
        return self::$default;
    }

    /** @return array<string, string> */
    public static function map($langId)
    {
        $langId = (int) $langId;
        if (isset(self::$maps[$langId])) {
            return self::$maps[$langId];
        }
        global $CMSNT;
        $map = array();
        $rows = $CMSNT->get_list("SELECT `name`, `value` FROM `translate` WHERE `lang_id` = " . $langId . " ");
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(string) $row["name"]] = (string) $row["value"];
            }
        }
        self::$maps[$langId] = $map;
        return $map;
    }

    public static function text($name)
    {
        $name = (string) $name;
        if (isset(self::$phrases[$name])) {
            return self::$phrases[$name];
        }
        $value = $name;
        $chosen = self::chosen();
        if ($chosen) {
            $map = self::map($chosen["id"]);
            if (isset($map[$name])) {
                $value = $map[$name];
            } else {
                $default = self::defaultRow();
                if ($default && (int) $default["id"] !== (int) $chosen["id"]) {
                    $fallback = self::map($default["id"]);
                    if (isset($fallback[$name])) {
                        $value = $fallback[$name];
                    }
                }
            }
        }
        self::$phrases[$name] = $value;
        return $value;
    }
}

function setLanguage($id)
{
    global $CMSNT;
    /* Accept a real id only: "2abc" must not silently mean language 2. */
    if (is_int($id)) {
        $id = (string) $id;
    }
    if (!is_string($id) || !preg_match('/^[0-9]{1,10}$/', $id)) {
        return false;
    }
    if($row = $CMSNT->get_row("SELECT * FROM `languages` WHERE `id` = '" . (int) $id . "' AND `status` = 1 ")) {
        $isSet = setcookie("language", $row["lang"], time() + 946080000, "/", "", !empty($_SERVER["HTTPS"]), true);
        /* Make the switch visible to __() in the same request, not just on the next page load. */
        $_COOKIE["language"] = $row["lang"];
        OrbitLang::reset();
        if($isSet) {
            return true;
        }
        return false;
    }
    return false;
}
function getLanguage()
{
    $row = OrbitLang::chosen();
    return $row ? $row["lang"] : false;
}
function __($name)
{
    return OrbitLang::text($name);
}
