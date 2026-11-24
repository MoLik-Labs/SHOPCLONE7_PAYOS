<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
// Execute the actual stock row loop with hostile fixture data; no database or browser.
$source = file_get_contents(__DIR__ . '/../views/admin/product-stock.php');
$start = strpos($source, '    $orbitUserNames = orbit_user_names($listDatatable, "seller");');
$end = strpos($source, '    echo "                                </tbody>', $start);
if ($start === false || $end === false) { throw new RuntimeException('Stock row loop not found'); }
function orbit_user_names($rows, $key) { return [5 => '<img src=x onerror=alert(1)>']; }
function orbit_user_name($names, $id) { return $names[(int) $id] ?? ''; }
function base_url_admin($path) { return '/?module=admin&action=' . $path; }
function timeAgo($time) { return 'fixture'; }
function __($s) { return $s; }
$attack = 'x" autofocus onfocus="alert(1)</textarea><script>alert(2)</script>&';
$listDatatable = [['id'=>'1', 'account'=>$attack, 'uid'=>'<svg onload=alert(3)>', 'seller'=>5,
    'create_gettime'=>'2024-01-01', 'time_check_live'=>0, 'type'=>'<script>alert(4)</script>']];
ob_start(); eval(substr($source, $start, $end - $start)); $html = ob_get_clean();
$escaped = htmlspecialchars($attack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
preg_match('/data-checkbox="([^"]*)"/', $html, $match);
$attribute = isset($match[1]) ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
$exportStart = strpos($source, 'function exportDataTXT()');
$exportEnd = strpos($source, 'function exportDataClipboard()', $exportStart);
$exportSource = substr($source, $exportStart, $exportEnd - $exportStart);
$checks = [
    'attribute keeps exact escaped account' => strpos($html, 'data-checkbox="' . $escaped . '"') !== false,
    'attribute decodes back to the original account for exports' => $attribute === $attack,
    'textarea escapes close tags and preserves account' => strpos($html, $escaped . '</textarea>') !== false,
    'UID is escaped' => strpos($html, '&lt;svg onload=alert(3)&gt;') !== false,
    'seller name is escaped' => strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false,
    'type is escaped' => strpos($html, '&lt;script&gt;alert(4)&lt;/script&gt;') !== false,
    'no injected live HTML' => strpos($html, '<script>') === false && strpos($html, '<svg ') === false && strpos($html, '<img src=x') === false,
    'TXT export separates accounts by newline' => strpos($exportSource, "var dataString = selectedData.join('\\\\n');") !== false,
    'stock endpoint is key-free' => strpos($source, 'base_url("api/importAccount.php")') !== false
        && strpos($source, 'api/importAccount.php?code=') === false,
    'key rotation does not rebuild a credential URL' => strpos($source, 'document.getElementById(\\"api_key\\").textContent = result.api_key') !== false
        && strpos($source, 'document.getElementById(\\"url_api\\").value =') === false,
    'live key is escaped and page marked no-store' => strpos($source, 'htmlspecialchars((string) $getUser["api_key"]') !== false
        && strpos($source, 'Cache-Control: no-store, private') !== false,
    'import directions require header and POST body' => strpos($source, 'X-API-Key') !== false
        && strpos($source, 'Gửi POST form qua HTTPS') !== false,
];
$failed=0; foreach ($checks as $name=>$ok) { echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; if (!$ok) { ++$failed; } }
echo count($checks) . " checks, $failed failures\n"; exit($failed ? 1 : 0);
