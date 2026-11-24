<?php
if (!defined('IN_SITE')) { exit('The Request Not Found'); }

/** Only replace the identifiable bundled legacy assets, never an arbitrary owner upload. */
function orbit_brand_is_legacy($value)
{
    if (!is_string($value) || trim($value) === '') { return true; }
    $path = parse_url(trim($value), PHP_URL_PATH);
    if (!is_string($path)) { return false; }
    $path = strtolower(ltrim(rawurldecode($path), '/'));
    $known = [
        'assets/img/cmsnt_dark.png', 'assets/img/cmsnt_light.png',
        'assets/img/cmsnt.vn_dark.png', 'assets/img/cmsnt.vn_light.png', 'assets/img/icon cmsnt.jpeg',
        'assets/storage/images/logo_dark_c0i.png', 'assets/storage/images/logo_dark_vbl.png',
        'assets/storage/images/logo_light_e4k.png', 'assets/storage/images/logo_light_ju1.png',
        'assets/storage/images/favicon_40d.png', 'assets/storage/images/favicon_jei.png',
    ];
    foreach ($known as $asset) {
        if ($path === $asset || substr($path, -strlen('/' . $asset)) === '/' . $asset) { return true; }
    }
    return false;
}

function orbit_brand_logo($dark, $light)
{
    foreach ([$dark, $light] as $logo) {
        if (is_string($logo) && !orbit_brand_is_legacy($logo)) { return $logo; }
    }
    return '';
}

function orbit_brand_favicon($favicon)
{
    return orbit_brand_is_legacy($favicon) ? 'assets/brand/orbit-icon.png' : $favicon;
}
