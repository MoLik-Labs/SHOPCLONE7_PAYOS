<?php
/*
 * Orbit shell — optional, content-free page chrome for views that adopt the Orbit design system.
 *
 * Existing client views keep their current header/nav/footer; nothing here is wired into them. A new or
 * rebuilt page opts in like this:
 *
 *     $orbit = array(
 *         "title"   => "…",                 // page <title>; defaults to the CMS site title
 *         "desc"    => "…",                 // meta description; defaults to the CMS value
 *         "keyword" => "…", "image" => "…",
 *         "nav"     => array(               // optional; omit for a brand-only bar
 *             array("label" => "…", "href" => BASE_URL("client/home")),
 *             array("label" => "…", "href" => BASE_URL("client/recharge"), "current" => true),
 *         ),
 *         "footer_links" => array(array("label" => "…", "href" => "…")),
 *         "head"    => '<link rel="stylesheet" href="…">',   // raw, page-owned <head> additions
 *         "scripts" => '<script src="…"></script>',          // raw, printed before </body>
 *         "noindex" => true,                // for private pages
 *         "body_class" => "…",
 *     );
 *     require_once __DIR__ . "/../common/orbit-shell.php";
 *     orbit_shell_open($orbit);
 *     ?> … page markup … <?php
 *     orbit_shell_close($orbit);
 *
 * Rules: no copy is invented here (labels come from the caller or the CMS), no inline style, no
 * dependency on a JavaScript framework, and the CMS-provided header/footer blocks are preserved so
 * existing settings keep working.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!function_exists("orbit_shell_site")) {
    /**
     * CMS value if the shop object is available, otherwise null — the shell never invents content.
     */
    function orbit_shell_site($key)
    {
        global $CMSNT;
        if (isset($CMSNT) && is_object($CMSNT) && method_exists($CMSNT, "site")) {
            $value = $CMSNT->site($key);
            return is_string($value) ? $value : null;
        }
        return null;
    }
}

if (!function_exists("orbit_shell_asset_version")) {
    function orbit_shell_asset_version($relativePath)
    {
        $absolute = __DIR__ . "/../../" . ltrim($relativePath, "/");
        return is_file($absolute) ? (string) filemtime($absolute) : "1";
    }
}

if (!function_exists("orbit_shell_text")) {
    function orbit_shell_text($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("orbit_shell_config")) {
    function orbit_shell_config($overrides = array())
    {
        $defaults = array(
            "title" => orbit_shell_site("title"),
            "desc" => orbit_shell_site("description"),
            "keyword" => orbit_shell_site("keywords"),
            "image" => orbit_shell_site("image"),
            "lang" => "vi",
            "theme_color" => orbit_shell_site("theme_color"),
            "favicon" => orbit_shell_site("favicon"),
            "nav" => array(),
            "footer_links" => array(),
            "head" => "",
            "scripts" => "",
            "noindex" => false,
            "body_class" => "",
            "main_class" => "container section",
            "brand_html" => "",
            "actions_html" => "",
        );
        foreach ((array) $overrides as $key => $value) {
            if ($value === null || $value === "") {
                continue; /* an empty override keeps the CMS default */
            }
            $defaults[$key] = $value;
        }
        return $defaults;
    }
}

if (!function_exists("orbit_shell_open")) {
    /**
     * Prints everything up to (and including) the opening <main> element.
     */
    function orbit_shell_open($overrides = array())
    {
        $o = orbit_shell_config($overrides);
        $base = BASE_URL("public/client/orbit/");
        $styles = array("tokens.css", "base.css", "components.css", "shell.css");
        $title = trim((string) $o["title"]);
        $image = (string) $o["image"];
        if ($image !== "" && !preg_match("#^https?://#i", $image)) {
            $image = BASE_URL($image);
        }
        $favicon = (string) $o["favicon"];
        if ($favicon !== "" && !preg_match("#^https?://#i", $favicon)) {
            $favicon = BASE_URL($favicon);
        }
        ?><!doctype html>
<html lang="<?php echo orbit_shell_text($o["lang"]); ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($o["noindex"]) { ?><meta name="robots" content="noindex, nofollow">
<?php } ?>
<title><?php echo orbit_shell_text($title); ?></title>
<?php if ((string) $o["desc"] !== "") { ?><meta name="description" content="<?php echo orbit_shell_text($o["desc"]); ?>">
<?php } ?>
<?php if ((string) $o["keyword"] !== "") { ?><meta name="keywords" content="<?php echo orbit_shell_text($o["keyword"]); ?>">
<?php } ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo orbit_shell_text($title); ?>">
<?php if ($image !== "") { ?><meta property="og:image" content="<?php echo orbit_shell_text($image); ?>">
<?php } ?>
<meta name="twitter:card" content="summary_large_image">
<?php if ((string) $o["theme_color"] !== "") { ?><meta name="theme-color" content="<?php echo orbit_shell_text($o["theme_color"]); ?>">
<?php } ?>
<?php if ($favicon !== "") { ?><link rel="icon" href="<?php echo orbit_shell_text($favicon); ?>">
<?php } ?>
<?php foreach ($styles as $file) { ?>
<link rel="stylesheet" href="<?php echo $base . $file; ?>?v=<?php echo orbit_shell_asset_version("public/client/orbit/" . $file); ?>">
<?php } ?>
<?php
        /* The CMS header/footer blocks are raw HTML owned by the shop: print them unchanged. */
        if (isset($o["head_html"])) {
            echo $o["head_html"];
        }
        if (isset($o["head"]) && $o["head"] !== "") {
            echo $o["head"];
        }
        ?>
</head>
<body class="<?php echo orbit_shell_text($o["body_class"]); ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
  <div class="container topbar__inner">
    <a class="topbar__brand" href="<?php echo BASE_URL("client/home"); ?>"><?php
        echo $o["brand_html"] !== "" ? $o["brand_html"] : orbit_shell_text($title);
    ?></a>
<?php if (!empty($o["nav"])) { ?>
    <nav aria-label="<?php echo orbit_shell_text($title); ?>">
      <ul class="topbar__nav">
<?php foreach ((array) $o["nav"] as $item) {
    if (!isset($item["label"]) || !isset($item["href"])) {
        continue;
    }
    $current = !empty($item["current"]);
    ?>
        <li><a href="<?php echo orbit_shell_text($item["href"]); ?>"<?php echo $current ? ' aria-current="page"' : ""; ?>><?php echo orbit_shell_text($item["label"]); ?></a></li>
<?php } ?>
      </ul>
    </nav>
<?php } ?>
<?php if ($o["actions_html"] !== "") { ?>
    <div class="topbar__actions"><?php echo $o["actions_html"]; ?></div>
<?php } ?>
  </div>
</header>
<main id="main" class="<?php echo orbit_shell_text($o["main_class"]); ?>">
<?php
    }

    if (!function_exists("orbit_shell_close")) {
        /**
         * Prints the footer, the page-owned scripts, the music player (if any licensed tracks exist)
         * and closes the document.
         */
        function orbit_shell_close($overrides = array())
        {
            $o = orbit_shell_config($overrides);
            ?>
</main>
<footer class="footer">
  <div class="container footer__inner">
    <p style="margin:0"><?php echo orbit_shell_text($o["title"]); ?></p>
<?php if (!empty($o["footer_links"])) { ?>
    <ul class="footer__links">
<?php foreach ((array) $o["footer_links"] as $item) {
    if (!isset($item["label"]) || !isset($item["href"])) {
        continue;
    }
    ?>
      <li><a href="<?php echo orbit_shell_text($item["href"]); ?>"><?php echo orbit_shell_text($item["label"]); ?></a></li>
<?php } ?>
    </ul>
<?php } ?>
  </div>
</footer>
<?php
            if (isset($o["footer_html"]) && $o["footer_html"] !== "") {
                echo $o["footer_html"];
            }
            if (isset($o["footer"]) && $o["footer"] !== "") {
                echo $o["footer"];
            }
            if (isset($o["scripts"]) && $o["scripts"] !== "") {
                echo $o["scripts"];
            }
            /* Renders nothing at all while public/music/playlist.json has no tracks. */
            require_once __DIR__ . "/music_player.php";
            ?></body>
</html>
<?php
        }
    }
}
