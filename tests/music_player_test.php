<?php
/*
 * Orbit music player — partial rendering contract (no database, no framework).
 *
 *   php tests/music_player_test.php
 *
 * What is checked: an empty playlist renders nothing at all; a valid playlist produces one JSON config
 * block with the expected entries; hostile input (markup in a title, http:// URLs, path traversal in a
 * file name, junk entries) never reaches the page; the local-file branch only accepts real files inside
 * public/music/.
 */

define("IN_SITE", true);

if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}

class OrbitMusicTestCmsnt
{
    public function site($key)
    {
        return $key === "title" ? "Orbit Office" : "";
    }
}

$CMSNT = new OrbitMusicTestCmsnt();

$pass = 0;
$fail = 0;

function check($label, $condition)
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . "\n";
    }
}

function render_player($playlistPath)
{
    $orbitMusicPlaylistFile = $playlistPath;
    ob_start();
    include __DIR__ . "/../views/common/music_player.php";
    return ob_get_clean();
}

$tmp = sys_get_temp_dir() . "/orbit-music-" . getmypid();
@mkdir($tmp, 0777, true);

$musicDir = __DIR__ . "/../public/music";
$fixture = $musicDir . "/orbit-test-tone.mp3";
$fixtureCreated = false;
if (is_dir($musicDir) && is_writable($musicDir)) {
    file_put_contents($fixture, "ID3");   /* existence is all the partial checks */
    $fixtureCreated = true;
}

try {
    /* 1. nothing licensed -> nothing rendered */
    $empty = $tmp . "/empty.json";
    file_put_contents($empty, json_encode(array("tracks" => array())));
    check("an empty playlist renders nothing", render_player($empty) === "");

    /* 2. a missing playlist file renders nothing */
    check("a missing playlist renders nothing", render_player($tmp . "/nope.json") === "");

    /* 3. a valid playlist renders the control and one JSON block */
    $good = $tmp . "/good.json";
    file_put_contents($good, json_encode(array("tracks" => array(
        array("title" => "Calm 01", "artist" => "Orbit Studio", "license" => "CC0", "url" => "https://media.example/calm-01.mp3"),
        array("title" => "Local track", "file" => $fixtureCreated ? "orbit-test-tone.mp3" : "missing.mp3"),
        array("title" => "Traversal", "file" => "../../config.php"),
        array("title" => "Junk", "file" => ""),
        array("title" => "Insecure", "url" => "http://media.example/insecure.mp3"),
        array("title" => "Javascript", "url" => "javascript:alert(1)"),
    ))));
    $html = render_player($good);
    check("a valid playlist renders the player root", strpos($html, 'id="orbit-music"') !== false);
    check("the stylesheet and script are referenced once", substr_count($html, "music-player.css") === 1 && substr_count($html, "music-player.js") === 1);
    check("assets carry a cache-busting version", strpos($html, "music-player.js?v=") !== false);

    $json = null;
    if (preg_match('#<script type="application/json" id="om-config">(.*?)</script>#s', $html, $m)) {
        $json = json_decode($m[1], true);
    }
    check("the config block is valid JSON", is_array($json));
    check("the https track is kept", is_array($json) && isset($json["tracks"][0]["url"]) && $json["tracks"][0]["url"] === "https://media.example/calm-01.mp3");
    check("the base URL for local files is passed", is_array($json) && isset($json["base"]) && strpos($json["base"], "public/music/") !== false);
    check($fixtureCreated ? "a real local file is kept" : "a missing local file is skipped",
        is_array($json) && ($fixtureCreated
            ? (isset($json["tracks"][1]["file"]) && $json["tracks"][1]["file"] === "orbit-test-tone.mp3")
            : !isset($json["tracks"][1])));
    $titles = is_array($json) ? array_column($json["tracks"], "title") : array();
    check("path traversal is refused", !in_array("Traversal", $titles, true));
    check("empty entries are refused", !in_array("Junk", $titles, true));
    check("http:// is refused (https only)", !in_array("Insecure", $titles, true));
    check("javascript: is refused", !in_array("Javascript", $titles, true));

    /* 4. markup in a title cannot escape the JSON block */
    $xss = $tmp . "/xss.json";
    file_put_contents($xss, json_encode(array("tracks" => array(
        array("title" => "</script><script>alert(1)</script>", "url" => "https://media.example/x.mp3"),
    ))));
    $htmlXss = render_player($xss);
    check("a title containing markup cannot close the script tag", strpos($htmlXss, "</script><script>alert(1)") === false);
    check("the escaped title survives as data", strpos($htmlXss, "\\u003C/script\\u003E") !== false || strpos($htmlXss, "u003C") !== false);

    /* 5. the playlist is capped */
    $many = array();
    for ($i = 0; $i < 60; $i++) {
        $many[] = array("title" => "Track " . $i, "url" => "https://media.example/t" . $i . ".mp3");
    }
    $capped = $tmp . "/many.json";
    file_put_contents($capped, json_encode(array("tracks" => $many)));
    $htmlMany = render_player($capped);
    $count = preg_match_all('#"url"\s*:#', $htmlMany);
    check("the playlist is capped at 30 tracks", $count === 30);
} finally {
    if ($fixtureCreated && is_file($fixture)) {
        unlink($fixture);
    }
    foreach (glob($tmp . "/*.json") as $file) {
        unlink($file);
    }
    @rmdir($tmp);
}

echo "\nmusic_player_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
