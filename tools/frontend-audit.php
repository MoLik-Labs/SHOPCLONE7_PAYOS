<?php
/* CLI only: this is a reporting tool, not a route. .htaccess refuses /tools, but a deployment that
   ignores it would run the audit for any visitor (and print every path it examined). */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Orbit frontend audit — proves the rendering-stability contract on the files it applies to.
 *
 *   php tools/frontend-audit.php                 # the Orbit design-system files (the contract's scope)
 *   php tools/frontend-audit.php --scope=views   # the legacy shop views (baseline, expected to be noisy)
 *   php tools/frontend-audit.php --scope=sales   # sales tracking: admin + buyer pages, charts, tables
 *   php tools/frontend-audit.php --scope=all
 *   php tools/frontend-audit.php --write=docs/frontend/frontend-audit-report.md
 *   php tools/frontend-audit.php --json
 *
 * Rules (from docs/frontend/08 §3):
 *   external-request     no third-party hosts: no CDN, no Google Fonts, no analytics, no hotlinking
 *   insecure-http        https:// only (http:// is refused)
 *   img-dimensions       every <img>/<video> carries width+height or an aspect-ratio
 *   inline-handler       no onclick="…" / onload="…" attributes
 *   unversioned-asset    public/… .css/.js references carry ?v=<version>
 *   inline-style-block   no <style> blocks that duplicate a stylesheet
 *   blocking-script      <script> in a page is defer/async/module (on the same source line, so the
 *                        rule can read concatenated PHP one line at a time)
 *   reduced-motion       a file that animates must respect prefers-reduced-motion
 *   remote-font          fonts are system/self-hosted (no fonts.googleapis/gstatic, no remote @font-face)
 *   player-preload       the music player never preloads audio (preload="none") and reserves its box
 *
 * Exit code: 0 when the scanned scope has no findings, 1 when it does.
 * The tool reads files only; it never writes unless --write is given.
 */

error_reporting(E_ALL);
ini_set("display_errors", "1");
if (!defined("STDERR")) {
    define("STDERR", fopen("php://stderr", "wb"));
}

$root = dirname(__DIR__);
$scope = "orbit";
$writeTo = "";
$asJson = false;
$summaryOnly = false;
$explicitPath = "";

$arguments = array_slice(isset($argv) ? $argv : array(), 1);
if (getenv("AUDIT_ARGS") !== false) {
    /* Arguments may also arrive through AUDIT_ARGS — the bundled test drives the tool that way in a
       runtime that does not provide argv. Same parser, same rules. */
    $arguments = array_merge($arguments, preg_split("/\s+/", trim((string) getenv("AUDIT_ARGS"))));
}
foreach ($arguments as $argument) {
    if ($argument === "") {
        continue;
    }
    if (strpos($argument, "--scope=") === 0) {
        $scope = substr($argument, 8);
        continue;
    }
    if (strpos($argument, "--write=") === 0) {
        $writeTo = substr($argument, 8);
        continue;
    }
    if (strpos($argument, "--path=") === 0) {
        $explicitPath = substr($argument, 7);
        continue;
    }
    if ($argument === "--json") {
        $asJson = true;
        continue;
    }
    if ($argument === "--summary") {
        $summaryOnly = true;
        continue;
    }
    if ($argument === "--help" || $argument === "-h") {
        echo "Usage: php tools/frontend-audit.php [--scope=orbit|views|sales|all] [--path=file-or-dir] [--write=path] [--json|--summary]\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown option: " . $argument . "\n");
    exit(2);
}
if (!in_array($scope, array("orbit", "views", "sales", "all"), true)) {
    fwrite(STDERR, "--scope must be orbit, views, sales or all\n");
    exit(2);
}

/* ------------------------------------------------------------------ scope */

$scopes = array(
    "orbit" => array(
        "public/client/orbit/tokens.css",
        "public/client/orbit/base.css",
        "public/client/orbit/components.css",
        "public/client/orbit/shell.css",
        "public/client/orbit/music-player.css",
        "public/client/orbit/music-player.js",
        "views/common/orbit-shell.php",
        "views/common/music_player.php",
        "views/common/orbit-styleguide.php",
        "public/music/playlist.json",
    ),
    "views" => array("views"),
    "sales" => array(
        "views/admin/sales.php",
        "views/client/purchase-history.php",
        "views/common/chart.php",
        "views/common/pagination.php",
        "libs/chart.php",
        "libs/sales_stats.php",
        "public/client/orbit/chart.css",
        "public/client/orbit/chart.js",
        "public/client/orbit/table.js",
    ),
);

$targets = array();
if ($explicitPath !== "") {
    /* Scan exactly what was pointed at (used by the audit's own test, and handy for a single file). */
    $absolute = strpos($explicitPath, "/") === 0 ? $explicitPath : $root . "/" . $explicitPath;
    if (is_dir($absolute)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match("/\.(php|css|js|json)$/", $file->getFilename())) {
                $targets[] = $file->getPathname();
            }
        }
    } elseif (is_file($absolute)) {
        $targets[] = $absolute;
    } else {
        fwrite(STDERR, "--path does not exist: " . $explicitPath . "\n");
        exit(2);
    }
}
$wanted = $explicitPath !== "" ? array() : ($scope === "all" ? array("orbit", "views", "sales") : array($scope));
foreach ($wanted as $key) {
    foreach ($scopes[$key] as $entry) {
        $absolute = $root . "/" . $entry;
        if (is_dir($absolute)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match("/\\.(php|css|js|json)$/", $file->getFilename())) {
                    $targets[] = $file->getPathname();
                }
            }
            continue;
        }
        if (is_file($absolute)) {
            $targets[] = $absolute;
        }
    }
}
$targets = array_values(array_unique($targets));
sort($targets);

/* ---------------------------------------------------------------- scanning */

$findings = array(); /* rule => list of [file, line, detail] */
$counts = array();
$filesScanned = 0;

function add_finding($rule, $file, $line, $detail)
{
    global $findings, $counts;
    $findings[$rule][] = array("file" => $file, "line" => $line, "detail" => $detail);
    $counts[$rule] = isset($counts[$rule]) ? $counts[$rule] + 1 : 1;
}

/* Hosts that are acceptable in an Orbit file: our own placeholders and documentation domains. */
$allowedHosts = array(
    "example.com" => true, "example.org" => true, "shop.example" => true, "media.example" => true,
);
$analyticsPattern = "/(google-analytics|googletagmanager|gtag|hotjar|facebook\\.net|doubleclick|clarity\\.ms|sentry|segment\\.io|mixpanel|cdn\\.jsdelivr|cdnjs|unpkg|bootstrapcdn|fonts\\.googleapis|fonts\\.gstatic)/i";

/**
 * Comments are not code: a rule quoted in a docblock is not a violation. Blank comment bodies out while
 * keeping the line count identical, so reported line numbers stay true.
 */
function audit_strip_comments($contents, $kind)
{
    if ($kind === "json") {
        return $contents;
    }
    /* block comments, preserving newlines */
    $contents = (string) preg_replace_callback("#/\*.*?\*/#s", function ($m) {
        return preg_replace("/[^\n]/", " ", $m[0]);
    }, $contents);
    if ($kind === "css") {
        return $contents;
    }
    /* line comments in php/js: // anywhere (but not the // in "https://"), and # starting a php line */
    $contents = (string) preg_replace("~^([ \t]*)#[^\n]*~m", "$1", $contents);
    $contents = (string) preg_replace("~(?<!:)\/\/[^\n]*~", "", $contents);
    return $contents;
}

foreach ($targets as $file) {
    $relative = ltrim(str_replace($root, "", $file), "/");
    $contents = (string) file_get_contents($file);
    if ($contents === "") {
        continue;
    }
    $filesScanned++;
    $kind = substr($file, -4) === ".css" ? "css" : (substr($file, -4) === ".php" ? "php"
        : (substr($file, -5) === ".json" ? "json" : "js"));
    $code = (string) audit_strip_comments($contents, $kind);
    $lines = explode("\n", $code);
    $isCss = substr($file, -4) === ".css";
    $isJs = substr($file, -3) === ".js";
    $isPhp = substr($file, -4) === ".php";

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        /* external hosts */
        if (preg_match_all("#https?://([A-Za-z0-9_.\\-]+)#", $line, $matches)) {
            foreach ($matches[1] as $position => $host) {
                $host = strtolower($host);
                $scheme = substr($matches[0][$position], 0, 5) === "https" ? "https" : "http";
                if (isset($allowedHosts[$host])) {
                    continue;
                }
                /* XML namespaces are identifiers, not requests: document.createElementNS needs the exact
                   http://www.w3.org/2000/svg string. Nothing is fetched from that host. */
                if ($host === "www.w3.org" && preg_match('#www\.w3\.org/\d{4}/#i', $line)) {
                    continue;
                }
                if ($scheme === "http") {
                    add_finding("insecure-http", $relative, $lineNumber, "http://" . $host);
                    continue;
                }
                if (preg_match($analyticsPattern, $host)) {
                    add_finding("external-request", $relative, $lineNumber, "third-party service: " . $host);
                    continue;
                }
                add_finding("external-request", $relative, $lineNumber, "external host: " . $host);
            }
        }

        /* media without reserved geometry */
        if (!$isJs && preg_match_all("#<(img|video)\\b(?:(?!\\?>)[^>])*>#i", $line, $media)) {
            foreach ($media[0] as $tag) {
                $hasSize = preg_match('#\\bwidth\\s*=#i', $tag) && preg_match('#\\bheight\\s*=#i', $tag);
                $hasRatio = stripos($tag, "aspect-ratio") !== false;
                if (!$hasSize && !$hasRatio) {
                    add_finding("img-dimensions", $relative, $lineNumber, trim(substr($tag, 0, 60)));
                }
            }
        }

        /* inline event handlers */
        if (preg_match_all('#\\bon(click|load|error|mouseover|mouseout|submit|focus|blur|change|input)\\s*=\\s*["\\\']#i', $line, $handlers)) {
            foreach ($handlers[1] as $handler) {
                add_finding("inline-handler", $relative, $lineNumber, "on" . strtolower($handler) . "=\"…\"");
            }
        }

        /* asset versions: every public/ .css/.js reference needs ?v= (or the version helper) on its
           line. Matching the quoted attribute would break on href="<?php echo BASE_URL("…"); ?>" — the
           most common pattern in this shop — so the reference itself is matched instead. */
        if (preg_match_all("#public/[A-Za-z0-9_/\-.$]+\.(?:css|js)\b#i", $line, $assets)) {
            foreach ($assets[0] as $asset) {
                $hasVersion = strpos($line, "?v=") !== false
                    || strpos($line, "asset_version") !== false
                    || strpos($line, "orbitVersion") !== false;
                if (!$hasVersion) {
                    add_finding("unversioned-asset", $relative, $lineNumber, $asset);
                }
            }
        }

        /* <style> blocks */
        if (!$isCss && preg_match("#<style\\b#i", $line)) {
            add_finding("inline-style-block", $relative, $lineNumber, "<style> block");
        }

        /* blocking scripts */
        if (preg_match("#<script\\b[^>]*\\bsrc\\s*=#i", $line)
            && !preg_match('#\\b(defer|async)\\b|type\\s*=\\s*["\\\']module["\\\']#i', $line)) {
            add_finding("blocking-script", $relative, $lineNumber, "external <script> without defer/async");
        }

        /* remote fonts */
        if (preg_match($analyticsPattern, $line) && preg_match("#font#i", $line)) {
            add_finding("remote-font", $relative, $lineNumber, "remote font reference");
        }
    }

    /* reduced motion: a file that animates must include the guard */
    if (($isCss || $isJs) && preg_match("/(transition|animation|scroll-behavior)\\s*:/", $code)) {
        if ($isCss && stripos($code, "prefers-reduced-motion") === false) {
            add_finding("reduced-motion", $relative, 0, "declares motion but has no prefers-reduced-motion guard");
        }
    }

    /* the player's two hard invariants */
    if (basename($file) === "music-player.js") {
        if (strpos($code, "preload = 'none'") === false && strpos($code, 'preload = "none"') === false) {
            add_finding("player-preload", $relative, 0, "the audio element must not preload (preload=\"none\")");
        }
        if (strpos($code, "new Audio(") !== false) {
            add_finding("player-preload", $relative, 0, "use createElement(\"audio\") so no request happens before a click");
        }
    }
    if (basename($file) === "music-player.css") {
        if (!preg_match("#\\.om__toggle\\s*\\{[^}]*width:\\s*var\\(--control-h\\)#s", $code)) {
            add_finding("player-preload", $relative, 0, "the collapsed control must reserve its box (width: var(--control-h))");
        }
    }

    /* the playlist: only https, and prefer a self-hosted file over a remote url */
    if (basename($file) === "playlist.json") {
        $data = json_decode($contents, true);
        if (is_array($data) && isset($data["tracks"]) && is_array($data["tracks"])) {
            foreach ($data["tracks"] as $i => $track) {
                if (isset($track["url"]) && stripos((string) $track["url"], "https://") !== 0) {
                    add_finding("insecure-http", $relative, 0, "track " . ($i + 1) . " is not an https url");
                }
                if (isset($track["url"]) && !isset($track["file"])) {
                    add_finding("external-request", $relative, 0, "track " . ($i + 1) . " hotlinks a remote url — prefer tools/music-import.php");
                }
                if (!isset($track["license"]) || trim((string) $track["license"]) === "") {
                    add_finding("external-request", $relative, 0, "track " . ($i + 1) . " records no licence");
                }
            }
        }
    }
}

/* ----------------------------------------------------------------- report */

$ruleLabels = array(
    "external-request" => "third-party request",
    "insecure-http" => "insecure http://",
    "img-dimensions" => "media without reserved geometry",
    "inline-handler" => "inline event handler",
    "unversioned-asset" => "asset without ?v= version",
    "inline-style-block" => "inline <style> block",
    "blocking-script" => "render-blocking script",
    "reduced-motion" => "motion without a reduced-motion guard",
    "remote-font" => "remote font",
    "player-preload" => "music player invariant",
);
$ruleOrder = array_keys($ruleLabels);

$summary = array(
    "scope" => $explicitPath !== "" ? $explicitPath : $scope,
    "files" => $filesScanned,
    "findings" => array_sum($counts),
    "by_rule" => array(),
    "details" => array(),
);
foreach ($ruleOrder as $rule) {
    if (!isset($counts[$rule])) {
        continue;
    }
    $summary["by_rule"][$rule] = $counts[$rule];
    foreach ($findings[$rule] as $finding) {
        $summary["details"][] = array(
            "rule" => $rule,
            "file" => $finding["file"],
            "line" => $finding["line"],
            "detail" => $finding["detail"],
        );
    }
}

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($summaryOnly) {
    /* Counts only: enough for a baseline in CI or a release note. */
    echo "scope=" . $summary["scope"] . " files=" . $filesScanned . " findings=" . $summary["findings"] . "\n";
    foreach ($ruleOrder as $rule) {
        if (isset($summary["by_rule"][$rule])) {
            echo sprintf("  %-28s %d\n", $rule, $summary["by_rule"][$rule]);
        }
    }
} else {
    echo "Orbit frontend audit — scope: " . ($summary["scope"]) . " (" . $filesScanned . " file(s))\n";
    echo str_repeat("-", 72) . "\n";
    if (!$summary["findings"]) {
        echo "No findings. The rendering-stability contract holds for this scope.\n";
    } else {
        foreach ($summary["details"] as $detail) {
            printf(
                "  %-24s %s%s  %s\n",
                $ruleLabels[$detail["rule"]],
                $detail["file"],
                $detail["line"] ? ":" . $detail["line"] : "",
                $detail["detail"]
            );
        }
        echo "\n" . $summary["findings"] . " finding(s): ";
        $parts = array();
        foreach ($summary["by_rule"] as $rule => $count) {
            $parts[] = $ruleLabels[$rule] . "=" . $count;
        }
        echo implode(", ", $parts) . "\n";
    }
}

if ($writeTo !== "") {
    $path = (strpos($writeTo, "/") === 0) ? $writeTo : $root . "/" . $writeTo;
    $markdown = "# Frontend audit report\n\n"
        . "Generated by `php tools/frontend-audit.php" . ($explicitPath !== "" ? " --path=" . $explicitPath : " --scope=" . $scope)
        . "` on " . gmdate("Y-m-d H:i") . " UTC.\n\n"
        . "- Scope: `" . $summary["scope"] . "` — " . $filesScanned . " file(s)\n"
        . "- Findings: **" . $summary["findings"] . "**\n\n";
    if (!$summary["findings"]) {
        $markdown .= "No findings: no third-party requests, no insecure http, media reserves its geometry, no inline handlers "
            . "or style blocks, every asset carries a version, motion respects reduced-motion, and the music player does not "
            . "preload audio.\n";
    } else {
        $markdown .= "| rule | file | line | detail |\n| --- | --- | --- | --- |\n";
        foreach ($summary["details"] as $detail) {
            $markdown .= "| " . $detail["rule"] . " | `" . $detail["file"] . "` | " . ($detail["line"] ? $detail["line"] : "—")
                . " | " . str_replace("|", "\\|", $detail["detail"]) . " |\n";
        }
        $markdown .= "\nTotals: ";
        $parts = array();
        foreach ($summary["by_rule"] as $rule => $count) {
            $parts[] = $rule . "=" . $count;
        }
        $markdown .= implode(", ", $parts) . "\n";
    }
    if (@file_put_contents($path, $markdown) === false) {
        fwrite(STDERR, "Could not write " . $path . "\n");
        exit(3);
    }
    if (!$asJson) {
        echo "Report written to " . $writeTo . "\n";
    }
}

exit($summary["findings"] ? 1 : 0);
