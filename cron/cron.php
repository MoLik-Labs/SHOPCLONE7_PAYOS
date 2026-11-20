<?php

/* Another cron may include this file in-process; defining the guard twice prints a warning. */
if (!defined("IN_SITE")) {
    define("IN_SITE", true);
}
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/helper.php";
$CMSNT = new DB();
if (!orbit_cron_allowed()) {
    http_response_code(403);
    exit("cron_forbidden");
}
if($CMSNT->site("check_time_cron_cron") < time() && time() - $CMSNT->site("check_time_cron_cron") < 3) {
    exit("Thao tác quá nhanh, vui lòng thử lại sau!");
}
$CMSNT->update("settings", ["value" => time()], " `name` = 'check_time_cron_cron' ");
if($CMSNT->site("status_tao_gd_ao") == 1) {
    /* One bounded probe set, reused by both generators below: two or three PK lookups each. */
    $fakeBuyers = orbit_random_user_ids(20);
    $int_rand = rand(0, $CMSNT->site("toc_do_gd_nap_ao"));
    if($int_rand == $CMSNT->site("toc_do_gd_nap_ao")) {
        $array_amount = explode(PHP_EOL, $CMSNT->site("menh_gia_nap_ao_ngau_nhien"));
        $array_method = explode(PHP_EOL, $CMSNT->site("method_nap_ao"));
        $amount = $array_amount[rand(0, count($array_amount) - 1)];
        $amount = $amount != 0 ? $amount : 10000;
        $method = $array_method[rand(0, count($array_method) - 1)];
        $CMSNT->insert("deposit_log", ["user_id" => $fakeBuyers ? $fakeBuyers[array_rand($fakeBuyers)] : 0, "method" => $method, "amount" => $amount, "received" => $amount, "create_time" => time(), "is_virtual" => 1]);
    }
    $int_rand = rand(0, $CMSNT->site("toc_do_gd_mua_ao"));
    if($int_rand == $CMSNT->site("toc_do_gd_mua_ao")) {
        $amount = rand($CMSNT->site("sl_mua_toi_thieu_gd_ao"), $CMSNT->site("sl_mua_toi_da_gd_ao"));
        $trans_id = random("QWERTYUPASDFGHJKZXCVBNM123456789", 4);
        /* The order of the products is irrelevant to the inserts; the RAND() sort was pure cost. */
        foreach ($CMSNT->get_list("SELECT * FROM `products` WHERE `status` = 1 ") as $fakeIndex => $product) {
            /*
             * The `tao_gd_ao_sp_het_hang` option used to guard an empty block: it ran a stock COUNT per
             * product and then did nothing with the answer. The generator has always written one fake
             * order per active product, in stock or not; that behaviour is unchanged, and the per-product
             * query is gone.
             */
            /* Reuse the buyers picked above instead of running ORDER BY RAND() on the users table per product. */
            $fakeBuyer = $fakeBuyers ? $fakeBuyers[$fakeIndex % count($fakeBuyers)] : 0;
            $CMSNT->insert("order_log", ["buyer" => $fakeBuyer, "product_name" => $product["name"], "pay" => $amount * $product["price"], "amount" => $amount, "create_time" => time(), "is_virtual" => 1]);
        }
    }
}
$CMSNT->remove("deposit_log", " " . time() . " - `create_time` >= 604800 ");
$CMSNT->remove("order_log", " " . time() . " - `create_time` >= 604800 ");
$urls = [];
$urls[] = base_url("tool/check-live-facebook");
$urls[] = base_url("tool/get-2fa");
$urls[] = base_url("tool/icon-facebook");
$urls[] = base_url("tool/random-face");
foreach ($CMSNT->get_list(" SELECT * FROM categories WHERE `status` = 1 ") as $category) {
    $urls[] = base_url("category/" . $category["slug"]);
}
foreach ($CMSNT->get_list(" SELECT * FROM products WHERE `status` = 1 ") as $product) {
    $urls[] = base_url("product/" . $product["slug"]);
}
foreach ($CMSNT->get_list(" SELECT * FROM posts WHERE `status` = 1 ") as $blog) {
    $urls[] = base_url("blog/" . $blog["slug"]);
}
$xml = new DOMDocument("1.0", "UTF-8");
$xml->formatOutput = true;
$urlset = $xml->createElement("urlset");
$urlset->setAttribute("xmlns", "http://www.sitemaps.org/schemas/sitemap/0.9");
foreach ($urls as $url) {
    $urlElement = $xml->createElement("url");
    $locElement = $xml->createElement("loc", htmlspecialchars($url));
    $urlElement->appendChild($locElement);
    $urlset->appendChild($urlElement);
}
$xml->appendChild($urlset);
/*
 * Relative paths resolve against the caller's working directory: run from the webroot,
 * "../sitemap.xml" landed *outside* the site and the public sitemap was never updated. Anchor it to
 * this file instead, and leave a log line when the file cannot be written.
 */
$sitemapPath = __DIR__ . "/../sitemap.xml";
if($xml->save($sitemapPath) === false) {
    error_log("[orbit] sitemap could not be written to " . $sitemapPath);
}

?>