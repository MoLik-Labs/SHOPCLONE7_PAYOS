<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(404); exit; }
define('IN_SITE', true);
function BASE_URL($path = '') { return 'https://shop.example/' . ltrim($path, '/'); }
class BrandFixture {
    public $values = [];
    public function site($key) { return $this->values[$key] ?? ''; }
}
$CMSNT = new BrandFixture();
require_once __DIR__ . '/../views/common/store-shell.php';
$CMSNT->values = ['title'=>'Shop <One>', 'logo_dark'=>'assets/storage/images/my-own-logo.png', 'favicon'=>'assets/storage/images/my-own-favicon.png', 'copyright_footer'=>'UNTRUSTED BRAND', 'author'=>'Not Studio'];
$options = store_shell_config([], false);
$brand = store_brand_html(array_merge($options, ['brand_html'=>'<b>FAKE</b>']), $CMSNT);
ob_start(); store_shell_open(); $head = ob_get_clean();
ob_start(); store_shell_close(); $footer = ob_get_clean();
$settings = file_get_contents(__DIR__ . '/../views/admin/settings.php');
$theme = file_get_contents(__DIR__ . '/../views/admin/theme.php');
$adminFooter = file_get_contents(__DIR__ . '/../views/admin/footer.php');
$checks = [
    'studio mark and name are fixed even with logo settings and override' => strpos($brand, 'assets/brand/molik-icon.svg') !== false && strpos($brand, 'MoLik Studio') !== false && strpos($brand, 'FAKE') === false && strpos($brand,'my-own-logo') === false,
    'favicon cannot be overwritten through CMS settings' => $options['favicon'] === 'assets/brand/molik-icon.svg',
    'credit is fixed independently of legacy copyright' => strpos($footer,'MoLik Studio') !== false && strpos($footer,'UNTRUSTED BRAND') === false,
    'head author is fixed' => strpos($head, '<meta name="author" content="MoLik Studio">') !== false && strpos($head, 'Not Studio') === false,
    'admin footer uses fixed studio name' => strpos($adminFooter,'MoLik Studio') !== false && strpos($adminFooter,'NggKhaiz') === false,
    'metadata author cannot be set through legacy settings form' => strpos($settings, 'name=\\"author\\"') === false && strpos($settings, 'orbit_brand_setting_locked($key)') !== false,
    'brand fields explicitly locked' => orbit_brand_setting_locked('author') && orbit_brand_setting_locked('copyright_footer') && orbit_brand_setting_locked('logo_dark') && !orbit_brand_setting_locked('smtp_status'),
    'branding uploads are not accepted by theme form' => strpos($theme,'check_img("logo_light")') === false && strpos($theme,'check_img("logo_dark")') === false && strpos($theme,'check_img("favicon")') === false,
    'brand assets exist' => is_file(__DIR__.'/../assets/brand/molik-icon.svg') && is_file(__DIR__.'/../assets/brand/molik-wordmark-dark.svg') && is_file(__DIR__.'/../assets/brand/molik-wordmark-light.svg'),
    'footer credit links to the fixed official studio GitHub' => strpos($footer,'href="https://github.com/MoLik-Labs"') !== false,
    'studio URL is not read from settings' => function_exists('orbit_brand_url') && orbit_brand_url() === 'https://github.com/MoLik-Labs',
    'admin and legacy client credit use the fixed link' => strpos($adminFooter, 'orbit_brand_url()') !== false && strpos(file_get_contents(__DIR__.'/../views/client/footer.php'), 'orbit_brand_url()') !== false,
];
$fail = 0; foreach ($checks as $name=>$ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) ++$fail; }
echo count($checks) . " checks, $fail failures\n"; exit($fail ? 1 : 0);
