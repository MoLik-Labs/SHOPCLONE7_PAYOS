<?php
/*
 * Orbit music player — include this where the player should live (normally the client footer):
 *
 *     require_once __DIR__ . '/../common/music_player.php';
 *
 * With an empty playlist it renders NOTHING: no markup, no CSS, no JS, no requests. Add tracks to
 * public/music/playlist.json (or import a direct audio URL with tools/music-import.php) and it turns
 * itself on. Rights are the owner's responsibility: only self-host tracks you own or that are CC0/CC-BY.
 *
 * This file never echoes unescaped user data: titles/artists go out through json_encode with
 * JSON_HEX_TAG, so a title containing markup cannot close the script tag or inject HTML.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

/* A caller may point at another playlist (a landing-page-only list, or a test fixture). */
if (!isset($orbitMusicPlaylistFile)) {
    $orbitMusicPlaylistFile = __DIR__ . "/../../public/music/playlist.json";
}
$orbitMusicTracks = array();

/* mbstring is common but not guaranteed: fall back to a byte-safe cut rather than fatalling. */
$orbitMusicCut = function ($value, $length) {
    return function_exists("mb_substr") ? mb_substr($value, 0, $length) : substr($value, 0, $length);
};

if (is_file($orbitMusicPlaylistFile)) {
    $orbitMusicRaw = @file_get_contents($orbitMusicPlaylistFile);
    $orbitMusicJson = is_string($orbitMusicRaw) ? json_decode($orbitMusicRaw, true) : null;
    if (is_array($orbitMusicJson) && isset($orbitMusicJson["tracks"]) && is_array($orbitMusicJson["tracks"])) {
        foreach ($orbitMusicJson["tracks"] as $orbitMusicTrack) {
            if (!is_array($orbitMusicTrack)) {
                continue;
            }
            $orbitMusicEntry = array();
            $orbitMusicTitle = isset($orbitMusicTrack["title"]) ? trim((string) $orbitMusicTrack["title"]) : "";
            $orbitMusicArtist = isset($orbitMusicTrack["artist"]) ? trim((string) $orbitMusicTrack["artist"]) : "";
            $orbitMusicLicense = isset($orbitMusicTrack["license"]) ? trim((string) $orbitMusicTrack["license"]) : "";
            $orbitMusicFile = isset($orbitMusicTrack["file"]) ? trim((string) $orbitMusicTrack["file"]) : "";
            $orbitMusicUrl = isset($orbitMusicTrack["url"]) ? trim((string) $orbitMusicTrack["url"]) : "";

            /* Local files win: they are stable, private and never hotlink a third party. */
            if ($orbitMusicFile !== "" && !preg_match("#^https?://#i", $orbitMusicFile)) {
                /* only a filename inside public/music/ — no traversal, no absolute paths */
                $orbitMusicFile = basename($orbitMusicFile);
                if (!is_file(__DIR__ . "/../../public/music/" . $orbitMusicFile)) {
                    continue; /* a playlist entry for a file that is not there renders nothing */
                }
                $orbitMusicEntry["file"] = rawurlencode($orbitMusicFile);
            } elseif ($orbitMusicUrl !== "" && preg_match("#^https://#i", $orbitMusicUrl)) {
                /* direct https audio URL (the owner's exported link). Never http, never javascript:. */
                $orbitMusicEntry["url"] = $orbitMusicUrl;
            } else {
                continue;
            }

            $orbitMusicEntry["title"] = $orbitMusicCut($orbitMusicTitle !== "" ? $orbitMusicTitle : basename($orbitMusicFile), 120);
            if ($orbitMusicArtist !== "") {
                $orbitMusicEntry["artist"] = $orbitMusicCut($orbitMusicArtist, 120);
            }
            if ($orbitMusicLicense !== "") {
                $orbitMusicEntry["license"] = $orbitMusicCut($orbitMusicLicense, 60);
            }
            $orbitMusicTracks[] = $orbitMusicEntry;
            if (count($orbitMusicTracks) >= 30) {
                break; /* a page is not a jukebox */
            }
        }
    }
}

if (!$orbitMusicTracks) {
    return; /* switched off / nothing licensed yet */
}

/*
 * The shop object is normally available, but this partial must also work from a plain script or a test
 * fixture — so a missing $CMSNT degrades to an empty site name instead of a fatal error.
 */
$orbitMusicSiteName = "";
if (isset($CMSNT) && is_object($CMSNT) && method_exists($CMSNT, "site")) {
    $orbitMusicSiteName = (string) $CMSNT->site("title");
}

/* Cache-busting by file mtime: stable rendering without a build step. */
$orbitAssetVersion = function ($relativePath) {
    $absolute = __DIR__ . "/../../" . $relativePath;
    return is_file($absolute) ? (string) filemtime($absolute) : "1";
};

/* A playlist title with invalid UTF-8 (possible after a restore) would make json_encode() return
   false; the player would then print an empty box. Substitute, and if even that fails, render
   nothing at all. */
$orbitMusicConfig = json_encode(
    array(
        "site" => $orbitMusicSiteName,
        "base" => BASE_URL("public/music/"),
        "tracks" => $orbitMusicTracks,
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
);
if (!is_string($orbitMusicConfig)) {
    return;
}
?>
<link rel="stylesheet" href="<?php echo BASE_URL("public/client/orbit/music-player.css"); ?>?v=<?php echo $orbitAssetVersion("public/client/orbit/music-player.css"); ?>">
<div id="orbit-music" class="om"></div>
<script type="application/json" id="om-config"><?php echo $orbitMusicConfig; ?></script>
<script src="<?php echo BASE_URL("public/client/orbit/music-player.js"); ?>?v=<?php echo $orbitAssetVersion("public/client/orbit/music-player.js"); ?>" defer></script>
<?php
unset($orbitMusicPlaylistFile, $orbitMusicRaw, $orbitMusicJson, $orbitMusicTracks, $orbitMusicTrack, $orbitMusicEntry, $orbitMusicCut, $orbitMusicSiteName, $orbitMusicConfig);
?>
