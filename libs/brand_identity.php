<?php
if (!defined('IN_SITE')) { exit('The Request Not Found'); }

/* Product identity is code-owned. Shop titles, contact channels and operational settings are separate. */
function orbit_brand_setting_locked($key)
{
    return in_array($key, ['author', 'copyright_footer', 'logo_light', 'logo_dark', 'favicon'], true);
}

/* Legacy views use this helper; ignore DB logo values without deleting any uploaded files. */
function orbit_brand_logo($dark, $light)
{
    return 'assets/brand/molik-wordmark-dark.svg';
}

function orbit_brand_favicon($favicon)
{
    return 'assets/brand/molik-icon.svg';
}
