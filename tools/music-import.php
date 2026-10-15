<?php
/*
 * Orbit music importer — download ONE direct audio URL into public/music/ and add it to the playlist.
 *
 *   php tools/music-import.php "https://media.example/calm-01.mp3" --title="Calm 01" \
 *       --artist="Orbit Studio" --license="CC0"
 *   php tools/music-import.php --list
 *
 * Why import instead of hotlinking: a remote URL can expire, rate-limit, block hotlinks or change
 * content; a self-hosted file keeps the player stable and private. The player still accepts direct
 * https URLs (public/music/playlist.json "url" entries) — importing is simply the better option.
 *
 * CLI only. It makes an outbound request, so it is deliberately NOT reachable from the web, and it
 * refuses private/loopback targets so it cannot be turned into an SSRF probe, follows no redirects,
 * and verifies TLS (never set --allow-local-http on a live host).
 *
 * Rights: only import audio you are allowed to distribute. --license is recorded, not verified.
 */

error_reporting(E_ALL);
ini_set("display_errors", "1");

/* The STDERR constant only exists in the CLI SAPI; php://stderr exists everywhere. */
if (!defined("STDERR")) {
    define("STDERR", fopen("php://stderr", "wb"));
}

/* Refuse every non-CLI SAPI before touching the filesystem: an outbound fetcher must never be
   reachable from a web request. Non-zero exit so a wrapper/CI sees the refusal as a failure. */
if (PHP_SAPI !== "cli") {
    if (!headers_sent()) {
        http_response_code(403);
    }
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$maxBytes = 20 * 1024 * 1024;      /* 20 MB per track is plenty for a shop background loop */
$maxTracks = 30;                    /* same cap the player enforces */
$musicDir = __DIR__ . "/../public/music";
$playlistFile = $musicDir . "/playlist.json";
$allowedLocal = false;
$force = false;
$title = "";
$artist = "";
$license = "";
$url = "";
$listOnly = false;

/* ------------------------------------------------------------------ args */

function importer_usage()
{
    return "Usage:\n"
        . "  php tools/music-import.php <direct-audio-url> [--title=\"…\"] [--artist=\"…\"] [--license=\"CC0\"] [--force]\n"
        . "  php tools/music-import.php --list\n"
        . "\n"
        . "Options:\n"
        . "  --title, --artist, --license   metadata shown in the player (title defaults to the file name)\n"
        . "  --force                        replace an existing imported file with the same name\n"
        . "  --list                         show the current playlist and exit\n"
        . "  --allow-local-http             TEST ONLY: also permit http:// and private hosts (never on a live host;\n"
        . "                                 the audio content-type check is NOT relaxed)\n"
        . "  --help                         this text\n";
}

$argvCopy = $argv;
array_shift($argvCopy);
foreach ($argvCopy as $argument) {
    if ($argument === "--help" || $argument === "-h") {
        echo importer_usage();
        exit(0);
    }
    if ($argument === "--list") {
        $listOnly = true;
        continue;
    }
    if ($argument === "--force") {
        $force = true;
        continue;
    }
    if ($argument === "--allow-local-http") {
        $allowedLocal = true;
        continue;
    }
    if (strpos($argument, "--title=") === 0) {
        $title = substr($argument, 8);
        continue;
    }
    if (strpos($argument, "--artist=") === 0) {
        $artist = substr($argument, 9);
        continue;
    }
    if (strpos($argument, "--license=") === 0) {
        $license = substr($argument, 10);
        continue;
    }
    if (strpos($argument, "--") === 0) {
        fwrite(STDERR, "Unknown option: " . $argument . "\n\n" . importer_usage());
        exit(2);
    }
    if ($url !== "") {
        fwrite(STDERR, "Only one URL at a time.\n");
        exit(2);
    }
    $url = $argument;
}

/* ------------------------------------------------------------- playlist */

function playlist_read($file)
{
    if (!is_file($file)) {
        return array("_comment" => "Orbit music player playlist.", "tracks" => array());
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return array("_comment" => "Orbit music player playlist.", "tracks" => array());
    }
    if (!isset($data["tracks"]) || !is_array($data["tracks"])) {
        $data["tracks"] = array();
    }
    return $data;
}

function playlist_write($file, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents($file, $json . "\n", LOCK_EX) !== false;
}

function slugify($value, $fallback = "track")
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace("/[^a-z0-9]+/", "-", $value);
    $value = trim((string) $value, "-");
    if ($value === "") {
        $value = $fallback;
    }
    return substr($value, 0, 60);
}

if ($listOnly) {
    $data = playlist_read($playlistFile);
    if (!$data["tracks"]) {
        echo "The playlist is empty — the player renders nothing while it stays that way.\n";
        exit(0);
    }
    printf("%d track(s):\n", count($data["tracks"]));
    foreach ($data["tracks"] as $i => $track) {
        printf(
            "  %2d. %s%s%s%s\n",
            $i + 1,
            isset($track["title"]) ? $track["title"] : "(untitled)",
            isset($track["artist"]) ? " — " . $track["artist"] : "",
            isset($track["file"]) ? "  [file: " . $track["file"] . "]" : "",
            isset($track["url"]) ? "  [url: " . $track["url"] . "]" : ""
        );
    }
    exit(0);
}

if ($url === "") {
    fwrite(STDERR, importer_usage());
    exit(2);
}

/* ----------------------------------------------------------------- SSRF */

$parts = parse_url($url);
if (!is_array($parts) || empty($parts["host"]) || empty($parts["scheme"])) {
    fwrite(STDERR, "That is not a complete URL.\n");
    exit(2);
}
$scheme = strtolower($parts["scheme"]);
$host = strtolower($parts["host"]);

if ($scheme !== "https" && !($allowedLocal && $scheme === "http")) {
    fwrite(STDERR, "Only https:// URLs are accepted (found: " . $scheme . "://).\n");
    exit(2);
}
if (preg_match("/(^|\\.)(localhost|local|internal|lan|home|corp)$/", $host)) {
    fwrite(STDERR, "Refusing an internal host name: " . $host . "\n");
    exit(2);
}

$resolved = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
if (!filter_var($resolved, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "Could not resolve " . $host . "\n");
    exit(3);
}
$publicIp = filter_var(
    $resolved,
    FILTER_VALIDATE_IP,
    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
);
if ($publicIp === false && !$allowedLocal) {
    fwrite(STDERR, "Refusing a private/loopback address (" . $resolved . ").\n");
    exit(2);
}

/* -------------------------------------------------------------- download */

if (!is_dir($musicDir) && !@mkdir($musicDir, 0755, true)) {
    fwrite(STDERR, "Cannot create " . $musicDir . "\n");
    exit(3);
}

$tmpFile = $musicDir . "/.import-" . getmypid() . ".part";
$received = 0;
$contentType = "";
$httpCode = 0;
$effectiveUrl = $url;
$handle = null;
$ok = false;

if (function_exists("curl_init")) {
    $handle = curl_init();
    curl_setopt_array($handle, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => false,         /* streamed to the write callback below */
        CURLOPT_FOLLOWLOCATION => false,         /* no redirects: they are how SSRF filters get bypassed */
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => "OrbitMusicImporter/1.0",
        CURLOPT_HTTPHEADER => array("Accept: audio/*"),
        CURLOPT_RESOLVE => array($host . ":" . (isset($parts["port"]) ? (int) $parts["port"] : ($scheme === "https" ? 443 : 80)) . ":" . $resolved),
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$received, $maxBytes, $tmpFile) {
            $received += strlen($chunk);
            if ($received > $maxBytes) {
                return 0; /* aborts the transfer */
            }
            return file_put_contents($tmpFile, $chunk, FILE_APPEND) === false ? 0 : strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$contentType, &$httpCode) {
            if (preg_match("#^HTTP/\\S+\\s+(\\d{3})#i", $header, $m)) {
                $httpCode = (int) $m[1];
            }
            if (stripos($header, "content-type:") === 0) {
                $contentType = strtolower(trim(substr($header, 13)));
            }
            return strlen($header);
        },
    ));
    $ok = curl_exec($handle);
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    $httpCode = $httpCode > 0 ? $httpCode : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    curl_close($handle);
    if (!$ok && $received > $maxBytes) {
        @unlink($tmpFile);
        fwrite(STDERR, "Refused: the file is larger than " . round($maxBytes / 1048576) . " MB.\n");
        exit(3);
    }
    if (!$ok) {
        @unlink($tmpFile);
        fwrite(STDERR, "Download failed (" . $errno . "): " . $error . "\n");
        exit(3);
    }
} else {
    /* No curl: streams, still verifies TLS (PHP 7.4 defaults) and caps the size. */
    $context = stream_context_create(array("http" => array(
        "method" => "GET",
        "follow_location" => 0,
        "timeout" => 60,
        "user_agent" => "OrbitMusicImporter/1.0",
        "header" => "Accept: audio/*\r\n",
        "max_redirects" => 1,
    ), "ssl" => array("verify_peer" => true, "verify_peer_name" => true)));
    $source = @fopen($url, "rb", false, $context);
    if (!$source) {
        fwrite(STDERR, "Download failed: cannot open " . $host . "\n");
        exit(3);
    }
    $meta = stream_get_meta_data($source);
    foreach ((array) $meta["wrapper_data"] as $line) {
        if (preg_match("#^HTTP/\\S+\\s+(\\d{3})#i", $line, $m)) {
            $httpCode = (int) $m[1];
        }
        if (stripos($line, "content-type:") === 0) {
            $contentType = strtolower(trim(substr($line, 13)));
        }
    }
    $sink = fopen($tmpFile, "wb");
    while (!feof($source)) {
        $chunk = fread($source, 65536);
        if ($chunk === false) {
            break;
        }
        $received += strlen($chunk);
        if ($received > $maxBytes) {
            fwrite($sink, "");
            fclose($sink);
            fclose($source);
            @unlink($tmpFile);
            fwrite(STDERR, "Refused: the file is larger than " . round($maxBytes / 1048576) . " MB.\n");
            exit(3);
        }
        fwrite($sink, $chunk);
    }
    fclose($sink);
    fclose($source);
}

if ($httpCode !== 0 && ($httpCode < 200 || $httpCode > 299)) {
    @unlink($tmpFile);
    fwrite(STDERR, "Refused: the server answered HTTP " . $httpCode . ".\n");
    exit(3);
}
if ($received === 0) {
    @unlink($tmpFile);
    fwrite(STDERR, "Refused: the download was empty.\n");
    exit(3);
}
/* The content-type check is never relaxed — not even with --allow-local-http. */
if ($contentType !== "" && strpos($contentType, "audio/") !== 0) {
    @unlink($tmpFile);
    fwrite(STDERR, "Refused: that URL serves \"" . $contentType . "\", not audio.\n");
    exit(3);
}

/* ----------------------------------------------------------- file naming */

$extensionMap = array(
    "audio/mpeg" => "mp3", "audio/mp3" => "mp3", "audio/x-mpeg" => "mp3",
    "audio/mp4" => "m4a", "audio/x-m4a" => "m4a", "audio/aac" => "aac",
    "audio/ogg" => "ogg", "application/ogg" => "ogg", "audio/opus" => "opus",
    "audio/wav" => "wav", "audio/x-wav" => "wav", "audio/webm" => "webm",
    "audio/flac" => "flac", "audio/x-flac" => "flac",
);
$contentTypeBase = trim(explode(";", $contentType . ";")[0]);
$extension = isset($extensionMap[$contentTypeBase]) ? $extensionMap[$contentTypeBase] : "";
if ($extension === "") {
    $pathExtension = strtolower((string) pathinfo(isset($parts["path"]) ? $parts["path"] : "", PATHINFO_EXTENSION));
    if (preg_match("/^(mp3|m4a|aac|ogg|opus|wav|webm|flac)$/", $pathExtension)) {
        $extension = $pathExtension;
    }
}
if ($extension === "") {
    $extension = "mp3";
}
$baseName = slugify($title !== "" ? $title : pathinfo(isset($parts["path"]) ? $parts["path"] : "", PATHINFO_FILENAME), "track");
$fileName = $baseName . "-" . substr(hash("sha256", $url), 0, 8) . "." . $extension;
$target = $musicDir . "/" . $fileName;

/* Decide BEFORE touching the music directory: a refused import must not leave an orphan file. */
$data = playlist_read($playlistFile);
$replaced = false;
$replaceIndex = -1;
foreach ($data["tracks"] as $index => $track) {
    if (isset($track["file"]) && $track["file"] === $fileName) {
        $replaced = true;
        $replaceIndex = $index;
        break;
    }
}

if (is_file($target) && !$force) {
    @unlink($tmpFile);
    fwrite(STDERR, "Already imported: public/music/" . $fileName . " (use --force to replace).\n");
    exit(4);
}
if (!$replaced && count($data["tracks"]) >= $maxTracks) {
    @unlink($tmpFile);
    fwrite(STDERR, "The playlist is full (" . $maxTracks . " tracks) — remove one first.\n");
    exit(5);
}

if (!@rename($tmpFile, $target)) {
    @unlink($tmpFile);
    fwrite(STDERR, "Cannot write " . $target . "\n");
    exit(3);
}
@chmod($target, 0644);

$entry = array_filter(array(
    "title" => $title !== "" ? $title : $baseName,
    "artist" => $artist,
    "license" => $license,
    "file" => $fileName,
    "source" => $effectiveUrl,
    "imported_at" => gmdate("c"),
), function ($value) {
    return $value !== "" && $value !== null;
});

if ($replaced) {
    $data["tracks"][$replaceIndex] = $entry;
} else {
    $data["tracks"][] = $entry;
}
if (!playlist_write($playlistFile, $data)) {
    fwrite(STDERR, "The file was saved but the playlist could not be updated.\n");
    exit(3);
}

printf(
    "%s public/music/%s (%s KB, %s)%s\n",
    $replaced ? "Replaced" : "Imported",
    $fileName,
    number_format($received / 1024, 0),
    $contentType !== "" ? $contentType : "unknown type",
    $license !== "" ? " — licence recorded as " . $license : " — no licence recorded (add --license)"
);
if ($license === "") {
    echo "Reminder: only publish audio you have the right to distribute.\n";
}
echo "The playlist now has " . count($data["tracks"]) . " track(s).\n";
