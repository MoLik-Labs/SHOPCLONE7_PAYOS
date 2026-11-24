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
$CMSNT->values = ['title' => 'Shop <One>', 'logo_dark' => 'assets/storage/images/logo_dark_C0I.png', 'favicon' => 'assets/storage/images/favicon_JEI.png'];
$options = store_shell_config([], false);
$brand = store_brand_html($options, $CMSNT);
$checks = [
    'known bundled old logo is not displayed' => strpos($brand, 'logo_dark_C0I.png') === false,
    'fallback mark and escaped shop name are shown' => strpos($brand, 'assets/brand/orbit-icon.png') !== false && strpos($brand, 'Shop &lt;One&gt;') !== false,
    'known bundled old favicon is replaced' => $options['favicon'] === 'assets/brand/orbit-icon.png',
];
$CMSNT->values['logo_dark'] = 'assets/storage/images/my-own-logo.png';
$CMSNT->values['favicon'] = 'assets/storage/images/my-own-favicon.png';
$options = store_shell_config([], false);
$brand = store_brand_html($options, $CMSNT);
$checks['owner logo remains configured'] = strpos($brand, 'my-own-logo.png') !== false && strpos($brand, 'orbit-icon.png') === false;
$checks['owner favicon remains configured'] = $options['favicon'] === 'assets/storage/images/my-own-favicon.png';
$CMSNT->values['logo_dark'] = 'assets/storage/images/logo_dark_C0I.png';
$CMSNT->values['logo_light'] = 'assets/storage/images/my-light.png';
$checks['custom light logo takes precedence over old dark logo'] = strpos(store_brand_html($options, $CMSNT), 'my-light.png') !== false;
$CMSNT->values['logo_light'] = '';
$CMSNT->values['logo_dark'] = '';
$checks['missing logo uses Orbit mark'] = strpos(store_brand_html($options, $CMSNT), 'assets/brand/orbit-icon.png') !== false;
$checks['legacy path works in a subdirectory and with query'] = orbit_brand_is_legacy('https://shop.example/sub/assets/img/cmsnt_dark.png?v=3');
$checks['an unrelated path containing a similar name stays custom'] = !orbit_brand_is_legacy('assets/storage/images/my-cmsnt-logo.png');
$checks['admin mark exists'] = is_file(__DIR__ . '/../assets/brand/orbit-icon.png') && is_file(__DIR__ . '/../assets/brand/orbit-wordmark-dark.png') && is_file(__DIR__ . '/../assets/brand/orbit-wordmark-light.png');
$footer = file_get_contents(__DIR__ . '/../views/admin/footer.php');
$home = file_get_contents(__DIR__ . '/../views/admin/home.php');
$checks['admin footer no longer advertises vendor'] = strpos($footer, 'Software by') === false && strpos($footer, 'www.cmsnt.co') === false;
$checks['admin dashboard no longer advertises community'] = strpos($home, '[CMSNT]') === false && strpos($home, 'zalo.me/g/idapcx933') === false;
$suppliers = file_get_contents(__DIR__ . '/../views/admin/product-api.php');
$checks['supplier management does not load vendor promotions'] = strpos($suppliers, 'api.cmsnt.co/suppliers.php') === false
    && strpos($suppliers, 'product-api-manager') !== false && strpos($suppliers, 'removeSupplier') !== false;
$security = file_get_contents(__DIR__ . '/../ajaxs/admin/update.php');
$checks['security alert no longer refers shop owner to vendor'] = strpos($security, 'https://www.cmsnt.co/') === false;
$fail = 0; foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) ++$fail; }
echo count($checks) . " checks, $fail failures\n"; exit($fail ? 1 : 0);
