<?php
/*
 * Orbit shell — compatibility entry point used by the sales dashboard, purchase history and the
 * styleguide. The document itself is produced by views/common/store-shell.php, so every page in the
 * shop now shares one top bar, one footer and one design system.
 *
 *   $orbit = array(
 *       "title"   => "…",
 *       "desc"    => "…",
 *       "nav"     => array(array("label" => "…", "href" => "…", "current" => true)),
 *       "footer_links" => array(array("label" => "…", "href" => "…")),
 *       "actions_html" => "…",          // kept for the styleguide's theme switch
 *       "head"    => "…", "scripts" => "…",
 *       "noindex" => true,
 *       "body_class" => "…",
 *       "main_class" => "container section",
 *   );
 *   orbit_shell_open($orbit);
 *   … page markup …
 *   orbit_shell_close($orbit);
 *
 * Nothing here invents copy, and the CMS-provided blocks keep working.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/store-shell.php";

if (!function_exists("orbit_shell_text")) {
    function orbit_shell_text($value)
    {
        return store_e($value);
    }
}

if (!function_exists("orbit_shell_asset_version")) {
    function orbit_shell_asset_version($relative)
    {
        return store_asset_version($relative);
    }
}

if (!function_exists("orbit_shell_site")) {
    function orbit_shell_site($key)
    {
        $value = store_site($key);
        return $value === "" ? null : $value;
    }
}

if (!function_exists("orbit_shell_config")) {
    function orbit_shell_config($overrides = array())
    {
        $o = store_shell_config(array());
        if (!empty($overrides["actions_html"]) && empty($o["actions"])) {
            $o["actions"] = $overrides["actions_html"];
        }
        if (!empty($overrides["footer_links"]) && empty($overrides["footer_columns"])) {
            $o["footer_columns"] = array(
                array("title" => "Liên kết", "links" => (array) $overrides["footer_links"]),
            );
        }
        foreach ((array) $overrides as $key => $value) {
            if ($value === null || $value === "") {
                continue;
            }
            $o[$key] = $value;
        }
        /* Older callers read this key back (tests and the styleguide); never let it disappear. */
        if (!array_key_exists("footer_links", $o)) {
            $o["footer_links"] = isset($overrides["footer_links"]) ? (array) $overrides["footer_links"] : array();
        }
        return $o;
    }
}

if (!function_exists("orbit_shell_open")) {
    function orbit_shell_open($overrides = array())
    {
        store_shell_open(orbit_shell_config($overrides));
    }
}

if (!function_exists("orbit_shell_close")) {
    function orbit_shell_close($overrides = array())
    {
        store_shell_close(orbit_shell_config($overrides));
    }
}
