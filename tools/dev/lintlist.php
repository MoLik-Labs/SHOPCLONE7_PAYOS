<?php
/* Parse-check every file listed in a JSON array (one `token_get_all(TOKEN_PARSE)` per file). */
$files = json_decode(file_get_contents($argv[1]), true);
$bad = 0;
$n = 0;
foreach ($files as $file) {
    ++$n;
    try {
        token_get_all(file_get_contents($file), TOKEN_PARSE);
    } catch (ParseError $error) {
        ++$bad;
        echo 'PARSE FAIL ' . $file . ': ' . $error->getMessage() . ' (line ' . $error->getLine() . ")\n";
    }
}
echo 'LINT ' . $n . ' files, ' . $bad . " parse errors\n";
exit($bad === 0 ? 0 : 1);
