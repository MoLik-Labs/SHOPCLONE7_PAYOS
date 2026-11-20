<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Admin image uploads.
 *
 * Every form hard-coded a `.png` filename whatever the bytes were, called unlink() on the old path
 * *before* the new upload had been moved, and relied on a relative path that depends on the caller's
 * working directory. `unlink()` also ran on whatever the database held, including "" for an unset
 * setting and anything a crafted value could point at.
 *
 * This checks the helpers' refusals (no temporary files are created) and the source wiring of every
 * admin form. A real move_uploaded_file() cannot run outside an HTTP request, so the happy path is
 * covered by the helper's own contract, not by this file.
 */

define('IN_SITE', true);

class DB
{
    public function __construct() {}
    public function connect() { return true; }
    public function dis_connect() { return true; }
    public function site($name) { return $name === 'timezone' ? 'Asia/Ho_Chi_Minh' : ''; }
    public function get_list($sql) { return array(); }
    public function get_row($sql) { return false; }
    public function query($sql) { return true; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (!function_exists('__')) {
    function __($text) { return $text; }
}
require __DIR__ . '/../libs/helper.php';

$root = dirname(__DIR__);
$checks = 0;
$failures = 0;
function check_upload($name, $condition, $detail = '')
{
    global $checks, $failures;
    ++$checks;
    if ($condition) {
        echo "PASS  " . $name . "\n";
        return;
    }
    ++$failures;
    echo "FAIL  " . $name . ($detail !== '' ? "  (" . $detail . ")" : '') . "\n";
}

/* ---- delete guard --------------------------------------------------------- */
$outside = tempnam(sys_get_temp_dir(), 'orbit_upload_');
check_upload('an empty path is not deleted', orbit_delete_upload('') === false);
check_upload('a path outside the storage directory is refused',
    orbit_delete_upload('/etc/passwd') === false);
check_upload('traversal out of the storage directory is refused',
    orbit_delete_upload('assets/storage/../../config.php') === false);
check_upload('a missing upload is not an error',
    orbit_delete_upload('assets/storage/images/does-not-exist.png') === false);
check_upload('a file outside the storage root still exists after the attempt',
    file_exists($outside) && orbit_delete_upload($outside) === false);
@unlink($outside);

/* ---- store guard ---------------------------------------------------------- */
$fake = array('name' => 'x.png', 'tmp_name' => '/tmp/not-an-upload', 'error' => UPLOAD_ERR_OK, 'size' => 10);
check_upload('an upload that did not come through HTTP is refused',
    orbit_image_extension($fake) === false && orbit_store_image($fake, 'test_') === false);
check_upload('a traversal prefix is refused before the filesystem is touched',
    orbit_store_image($fake, '../../evil') === false);
check_upload('a traversal subdirectory is refused',
    orbit_store_image($fake, 'test_', '../../public') === false);
check_upload('an empty prefix is refused',
    orbit_store_image($fake, '') === false);
check_upload('a missing file entry is refused',
    orbit_image_extension(array()) === false && orbit_store_image(array(), 'test_') === false);

/* ---- wiring --------------------------------------------------------------- */
$views = array();
foreach (glob($root . '/views/admin/*.php') as $file) {
    $views[basename($file)] = (string) file_get_contents($file);
}
$withUploads = array();
foreach ($views as $name => $source) {
    if (strpos($source, 'check_img(') !== false || strpos($source, 'valid_image_upload(') !== false) {
        $withUploads[$name] = $source;
    }
}
check_upload('the admin has upload forms to check', count($withUploads) >= 14, (string) count($withUploads));

$badMove = array();
$badUnlink = array();
$missingPrefix = array();
foreach ($withUploads as $name => $source) {
    if (strpos($source, 'move_uploaded_file(') !== false) {
        $badMove[] = $name;
    }
    if (preg_match('/\bunlink\s*\(/', $source)) {
        $badUnlink[] = $name;
    }
    if (strpos($source, 'orbit_store_image(') === false) {
        $missingPrefix[] = $name;
    }
}
check_upload('no view moves an upload itself (the helper owns the path and type)',
    $badMove === array(), implode(', ', $badMove));
check_upload('no view calls unlink() on a database value',
    $badUnlink === array(), implode(', ', $badUnlink));
check_upload('every upload form stores through orbit_store_image()',
    $missingPrefix === array(), implode(', ', $missingPrefix));

/* The admin AJAX remove handler deleted "../../<db value>" directly, outside the storage fence. */
$removeHandler = (string) file_get_contents($root . '/ajaxs/admin/remove.php');
check_upload('the admin remove handler never unlinks a raw database path',
    preg_match('/\bunlink\s*\(/', $removeHandler) !== 0 ? false : true);
check_upload('the admin remove handler deletes through orbit_delete_upload()',
    substr_count($removeHandler, 'orbit_delete_upload(') >= 8);

/* The old file is removed only after the new one is stored and saved. */
foreach (array('blog-edit.php', 'category-edit.php', 'blog-category-edit.php', 'recharge-bank-edit.php', 'recharge-manual-edit.php') as $name) {
    $source = $views[$name];
    check_upload($name . ' deletes the previous upload only after the update',
        strpos($source, 'orbit_delete_upload(') > strpos($source, 'orbit_store_image(')
        && strpos($source, 'orbit_delete_upload(') > strpos($source, '$CMSNT->update('));
}
foreach (array('theme.php', 'language-edit.php') as $name) {
    $views[$name] = (string) file_get_contents($root . '/views/admin/' . $name);
    check_upload($name . ' keeps the setting until the new file is stored',
        strpos($views[$name], 'orbit_delete_upload(') > strpos($views[$name], '$stored = orbit_store_image('));
}

/* Product images are stored by their real type and only recorded when the move succeeded. */
foreach (array('product-add.php', 'product-edit.php') as $name) {
    $source = $views[$name];
    check_upload($name . ' uses the upload helper',
        strpos($source, 'orbit_store_image([') !== false && strpos($source, '.png";') === false);
    check_upload($name . ' records the stored basename, not an invented name',
        strpos($source, 'basename($stored)') !== false);
}

/* The account-list import reads PHP's own temp file and caps its size. */
$stock = $views['product-stock.php'];
check_upload('the stock import validates that the file really was uploaded',
    strpos($stock, 'is_uploaded_file($file["tmp_name"])') !== false);
check_upload('the stock import caps the file size',
    strpos($stock, '10 * 1024 * 1024') !== false);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures === 0 ? 0 : 1);
