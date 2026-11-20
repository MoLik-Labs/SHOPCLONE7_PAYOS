<?php
//thanhvucoder
if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}
$CMSNT = new DB();
orbit_logout();
redirect(base_url("client/"));
echo "\n";

?>