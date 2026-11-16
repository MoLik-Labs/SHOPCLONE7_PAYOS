<?php
/*
 * Storefront shell — the one chrome every customer page adopts.
 *
 * It replaces the legacy `header.php` + `nav.php` + `footer.php` trio for rebuilt pages:
 * one fixed top bar, one footer directory, no third-party asset, no invented copy.
 *
 *     $store = array(
 *         "title"    => "…",                  // defaults to the CMS site title
 *         "desc"     => "…",                  // meta description
 *         "nav"      => array(array("label" => "Sản phẩm", "href" => …, "current" => true)),
 *         "search"   => true,                  // print the catalogue search field
 *         "actions"  => '<a class="btn …">…</a>', // extra header actions (raw, caller-owned)
 *         "footer_columns" => array(array("title" => "Mua hàng", "links" => array(…))),
 *         "footer_note"    => "…",            // one line under the brand
 *         "scripts"  => "…",                  // raw, printed before </body>
 *         "head"     => "…",                  // raw, printed before </head>
 *         "noindex"  => true,
 *         "main_class" => "container section",
 *     );
 *     require_once __DIR__ . "/../common/store-shell.php";
 *     store_shell_open($store);
 *     ?> … page markup … <?php
 *     store_shell_close($store);
 *
 * Rules: all visible words come from the caller or the CMS; no colour or size is written inline;
 * the page works without JavaScript (the menu is a native details/summary fallback).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!function_exists("store_e")) {
    function store_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("store_site")) {
    function store_site($key, $cmsnt = null)
    {
        $cmsnt = $cmsnt !== null ? $cmsnt : (isset($GLOBALS["CMSNT"]) ? $GLOBALS["CMSNT"] : null);
        if (is_object($cmsnt) && method_exists($cmsnt, "site")) {
            $value = $cmsnt->site($key);
            return is_string($value) ? $value : "";
        }
        return "";
    }
}

if (!function_exists("store_asset_version")) {
    function store_asset_version($relative)
    {
        $absolute = __DIR__ . "/../../" . ltrim($relative, "/");
        return is_file($absolute) ? (string) filemtime($absolute) : "1";
    }
}

if (!function_exists("store_asset")) {
    function store_asset($relative)
    {
        return BASE_URL($relative) . "?v=" . store_asset_version($relative);
    }
}

if (!function_exists("store_first_char")) {
    function store_first_char($text)
    {
        $text = trim((string) $text);
        if ($text === "") {
            return "?";
        }
        return function_exists("mb_substr") ? mb_substr($text, 0, 1, "UTF-8") : substr($text, 0, 1);
    }
}

if (!function_exists("store_current_user")) {
    function store_current_user()
    {
        if (isset($GLOBALS["getUser"]) && is_array($GLOBALS["getUser"])) {
            return $GLOBALS["getUser"];
        }
        return null;
    }
}

if (!function_exists("store_money")) {
    function store_money($amount)
    {
        if (function_exists("format_currency")) {
            return format_currency($amount);
        }
        return number_format((float) $amount, 0, ",", ".") . "đ";
    }
}

if (!function_exists("store_nav_default")) {
    /** The five words a customer needs; each resolves to an existing route. */
    function store_nav_default($cmsnt = null, $methods = null)
    {
        $action = isset($_GET["action"]) ? (string) $_GET["action"] : "";
        $recharge = is_array($methods) ? $methods : store_payment_methods($cmsnt);
        $rechargeHref = !empty($recharge) ? $recharge[0]["href"] : BASE_URL("client/login");
        return array(
            array("label" => "Sản phẩm", "href" => BASE_URL("client/home") . "#san-pham",
                "current" => $action === "home"),
            array("label" => "Danh mục", "href" => BASE_URL("client/home") . "#danh-muc"),
            array("label" => "Nạp tiền", "href" => $rechargeHref, "current" => strpos($action, "recharge") === 0),
            array("label" => "Lịch sử", "href" => BASE_URL("product-orders"),
                "current" => in_array($action, array("product-orders", "purchase-history"), true)),
            array("label" => "Hỗ trợ", "href" => BASE_URL("client/contact"), "current" => $action === "contact"),
        );
    }
}

if (!function_exists("store_payment_methods")) {
    /**
     * Only methods this shop has actually switched on. One `site()` read per key is served by the
     * request cache, so this is cheap. No method is invented and no icon is hotlinked.
     */
    function store_payment_methods($cmsnt = null)
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $cmsnt = $cmsnt !== null ? $cmsnt : (isset($GLOBALS["CMSNT"]) ? $GLOBALS["CMSNT"] : null);
        if (!is_object($cmsnt)) {
            return array();
        }
        $payos = false;
        if (is_file(__DIR__ . "/../../libs/payos.php")) {
            require_once __DIR__ . "/../../libs/payos.php";
            if (function_exists("payos_config")) {
                $config = payos_config();
                $payos = !empty($config["enabled"]);
            }
        }
        $methods = array();
        if (is_file(__DIR__ . "/../../libs/gtf.php")) {
            require_once __DIR__ . "/../../libs/gtf.php";
            if (function_exists("gtf_config")) {
                $gtf = gtf_config();
                if (!empty($gtf["card_enabled"]) && $gtf["partner_key"] !== ""
                    && $gtf["api_base"] !== "" && $gtf["card_request_format"] !== "") {
                    $methods[] = array("key" => "gtf-card", "label" => "Thẻ cào (GachtheFast)",
                        "href" => BASE_URL("?action=recharge-gtf-card"),
                        "icon" => "assets/img/icon-cards.png");
                }
            }
        }
        if ($payos || store_site("bank_status", $cmsnt) === "1") {
            $methods[] = array("key" => "bank", "label" => $payos ? "Ngân hàng (payOS)" : "Ngân hàng",
                "href" => BASE_URL("?action=recharge-bank"), "icon" => "assets/img/icon-bank.svg");
        }
        if (!$payos && store_site("momo_status", $cmsnt) === "1") {
            $methods[] = array("key" => "momo", "label" => "Ví MOMO",
                "href" => BASE_URL("?action=recharge-momo"), "icon" => "assets/img/icon-momo.png");
        }
        if (!$payos && store_site("card_status", $cmsnt) === "1") {
            $methods[] = array("key" => "card", "label" => "Thẻ cào",
                "href" => BASE_URL("?action=recharge-card"), "icon" => "assets/img/icon-cards.png");
        }
        if (!$payos && store_site("crypto_status", $cmsnt) === "1") {
            $methods[] = array("key" => "crypto", "label" => "Crypto",
                "href" => BASE_URL("?action=recharge-crypto"), "icon" => "assets/img/icon-usdt.svg");
        }
        if (!$payos && store_site("paypal_status", $cmsnt) === "1") {
            $methods[] = array("key" => "paypal", "label" => "PayPal",
                "href" => BASE_URL("?action=recharge-paypal"), "icon" => "assets/img/icon-paypal.svg");
        }
        if (!$payos && store_site("perfectmoney_status", $cmsnt) === "1") {
            $methods[] = array("key" => "perfectmoney", "label" => "Perfect Money",
                "href" => BASE_URL("?action=recharge-perfectmoney"), "icon" => "assets/img/icon-perfectmoney.svg");
        }
        if (!$payos && store_site("toyyibpay_status", $cmsnt) === "1") {
            $methods[] = array("key" => "toyyibpay", "label" => "ToyyibPay",
                "href" => BASE_URL("?action=recharge-toyyibpay"), "icon" => "assets/img/icon-toyyibpay.jpeg");
        }
        if (!$payos && store_site("squadco_status", $cmsnt) === "1") {
            $methods[] = array("key" => "squadco", "label" => "Squadco",
                "href" => BASE_URL("?action=recharge-squadco"), "icon" => "assets/img/icon-squadco.png");
        }
        if (!$payos && store_site("flutterwave_status", $cmsnt) === "1") {
            $methods[] = array("key" => "flutterwave", "label" => "Flutterwave",
                "href" => BASE_URL("?action=recharge-flutterwave"), "icon" => "mod/img/icon-flutterwave.png");
        }
        if (!$payos && method_exists($cmsnt, "get_list")) {
            foreach ((array) $cmsnt->get_list(" SELECT * FROM `payment_manual` WHERE `display` = 1 ") as $manual) {
                if (!isset($manual["slug"]) || !isset($manual["title"])) {
                    continue;
                }
                $methods[] = array("key" => "manual-" . $manual["slug"], "label" => $manual["title"],
                    "href" => BASE_URL("recharge-manual/" . $manual["slug"]),
                    "icon" => isset($manual["icon"]) ? $manual["icon"] : "");
            }
        }
        $memo = $methods;
        return $methods;
    }
}

if (!function_exists("store_shell_config")) {
    function store_shell_config($overrides = array(), $withNav = true)
    {
        $cmsnt = isset($GLOBALS["CMSNT"]) ? $GLOBALS["CMSNT"] : null;
        $title = store_site("title", $cmsnt);
        $defaults = array(
            "title" => $title !== "" ? $title : "Shop",
            "desc" => store_site("description", $cmsnt),
            "keyword" => store_site("keywords", $cmsnt),
            "image" => store_site("image", $cmsnt),
            "favicon" => store_site("favicon", $cmsnt),
            "theme_color" => store_site("theme_color", $cmsnt),
            "lang" => "vi",
            "nav" => array(),
            "search" => false,
            "search_value" => "",
            "actions" => "",
            "footer_columns" => array(),
            "footer_note" => "",
            "footer_html" => "",
            "head" => "",
            "scripts" => "",
            "noindex" => false,
            "body_class" => "",
            "main_class" => "container section",
            "brand_html" => "",
            "recharge_href" => "",
            "theme" => "light",
        );
        foreach ((array) $overrides as $key => $value) {
            /* An explicit "" is meaningful for main_class: a full-bleed page owns its own
               containers, while the admin pages keep the default wrapper. */
            if ($value === null || ($value === "" && $key !== "main_class")) {
                continue;
            }
            $defaults[$key] = $value;
        }
        if ($withNav && empty($defaults["nav"])) {
            $defaults["nav"] = store_nav_default($cmsnt);
        }
        if (empty($defaults["footer_columns"])) {
            $defaults["footer_columns"] = store_footer_default($cmsnt);
        }
        if ($defaults["theme_color"] === "") {
            $defaults["theme_color"] = "#fbf9f4";
        }
        if ($defaults["recharge_href"] === "") {
            $methods = store_payment_methods($cmsnt);
            $defaults["recharge_href"] = !empty($methods) ? $methods[0]["href"] : BASE_URL("client/login");
        }
        return $defaults;
    }
}

if (!function_exists("store_footer_default")) {
    function store_footer_default($cmsnt = null)
    {
        return array(
            array("title" => "Mua hàng", "links" => array(
                array("label" => "Tất cả sản phẩm", "href" => BASE_URL("client/home") . "#san-pham"),
                array("label" => "Danh mục", "href" => BASE_URL("client/home") . "#danh-muc"),
                array("label" => "Lịch sử đơn hàng", "href" => BASE_URL("product-orders")),
                array("label" => "Sản phẩm yêu thích", "href" => BASE_URL("client/favorites")),
            )),
            array("title" => "Hỗ trợ", "links" => array(
                array("label" => "Câu hỏi thường gặp", "href" => BASE_URL("client/faq")),
                array("label" => "Liên hệ", "href" => BASE_URL("client/contact")),
                array("label" => "Chính sách", "href" => BASE_URL("client/policy")),
                array("label" => "Tài liệu API", "href" => BASE_URL("client/document-api")),
            )),
            array("title" => "Tài khoản", "links" => array(
                array("label" => "Đăng nhập", "href" => BASE_URL("client/login")),
                array("label" => "Đăng ký", "href" => BASE_URL("client/register")),
                array("label" => "Nạp tiền", "href" => BASE_URL("client/login")),
                array("label" => "Trang cá nhân", "href" => BASE_URL("client/profile")),
            )),
        );
    }
}

if (!function_exists("store_brand_html")) {
    function store_brand_html($o, $cmsnt = null)
    {
        if ((string) $o["brand_html"] !== "") {
            return $o["brand_html"];
        }
        /* The bar belongs to the shop, not to the page: a product called "Netflix…" must not rename the
           header. Fall back to the page title only when the owner has not set a shop name. */
        $title = trim((string) store_site("title", $cmsnt));
        if ($title === "") {
            $title = (string) $o["title"];
        }
        $logo = store_site("logo_dark", $cmsnt);
        if ($logo === "") {
            $logo = store_site("logo_light", $cmsnt);
        }
        if ($logo !== "") {
            return '<img class="topbar__logo" src="' . store_e(BASE_URL($logo)) . '" alt="'
                . store_e($title) . '" width="132" height="32">';
        }
        return '<span class="topbar__mark" aria-hidden="true">' . store_e(store_first_char($title)) . '</span>'
            . '<span class="topbar__name">' . store_e($title) . '</span>';
    }
}

if (!function_exists("store_pager")) {
    /**
     * Accessible, plain-link pagination. $query keeps the current filters; only known scalars are
     * carried, and every value is urlencoded.
     */
    function store_pager($page, $total, $limit, $query = array())
    {
        $pages = max(1, (int) ceil(((int) $total) / max(1, (int) $limit)));
        $page = max(1, min($pages, (int) $page));
        if ($pages <= 1) {
            return "";
        }
        $href = function ($number) use ($query) {
            $params = array();
            foreach ($query as $key => $value) {
                if (is_scalar($value) && (string) $value !== "") {
                    $params[$key] = (string) $value;
                }
            }
            $params["page"] = (int) $number;
            return BASE_URL("client/home") . "?" . http_build_query($params);
        };
        $out = '<nav class="pager" aria-label="Phân trang">';
        if ($page > 1) {
            $out .= '<a rel="prev" href="' . store_e($href($page - 1)) . '">Trước</a>';
        }
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        if ($start > 1) {
            $out .= '<a href="' . store_e($href(1)) . '">1</a>';
            if ($start > 2) {
                $out .= '<span class="is-disabled" aria-hidden="true">…</span>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $out .= $i === $page
                ? '<span aria-current="page">' . (int) $i . '</span>'
                : '<a href="' . store_e($href($i)) . '">' . (int) $i . '</a>';
        }
        if ($end < $pages) {
            if ($end < $pages - 1) {
                $out .= '<span class="is-disabled" aria-hidden="true">…</span>';
            }
            $out .= '<a href="' . store_e($href($pages)) . '">' . (int) $pages . '</a>';
        }
        if ($page < $pages) {
            $out .= '<a rel="next" href="' . store_e($href($page + 1)) . '">Sau</a>';
        }
        return $out . '</nav>';
    }
}

if (!function_exists("store_shell_open")) {
    function store_shell_open($overrides = array())
    {
        $o = store_shell_config($overrides);
        $cmsnt = isset($GLOBALS["CMSNT"]) ? $GLOBALS["CMSNT"] : null;
        $title = trim((string) $o["title"]);
        $image = (string) $o["image"];
        if ($image !== "" && !preg_match("#^https?://#i", $image)) {
            $image = BASE_URL($image);
        }
        $favicon = (string) $o["favicon"];
        if ($favicon !== "" && !preg_match("#^https?://#i", $favicon)) {
            $favicon = BASE_URL($favicon);
        }
        $user = store_current_user();
        $searchAction = BASE_URL("client/home");
        $styles = array(
            "public/client/orbit/tokens.css",
            "public/client/orbit/base.css",
            "public/client/orbit/components.css",
            "public/client/orbit/shell.css",
            "public/client/orbit/store.css",
        );
        ?><!doctype html>
<html lang="<?php echo store_e($o["lang"]); ?>" data-theme="<?php echo store_e($o["theme"]); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php if (!empty($o["noindex"])) { ?><meta name="robots" content="noindex, nofollow">
<?php } ?>
<title><?php echo store_e($title); ?></title>
<?php if ((string) $o["desc"] !== "") { ?><meta name="description" content="<?php echo store_e($o["desc"]); ?>">
<?php } ?>
<?php if ((string) $o["keyword"] !== "") { ?><meta name="keywords" content="<?php echo store_e($o["keyword"]); ?>">
<?php } ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo store_e($title); ?>">
<?php if ((string) $o["desc"] !== "") { ?><meta property="og:description" content="<?php echo store_e($o["desc"]); ?>">
<?php } ?>
<?php if ($image !== "") { ?><meta property="og:image" content="<?php echo store_e($image); ?>">
<?php } ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="<?php echo store_e($o["theme_color"]); ?>">
<?php if ($favicon !== "") { ?><link rel="icon" href="<?php echo store_e($favicon); ?>">
<?php } ?>
<link rel="preload" href="<?php echo store_e(BASE_URL("public/client/fonts/be-vietnam-pro/latin-400.woff2")); ?>" as="font" type="font/woff2" crossorigin>
<?php foreach ($styles as $file) { ?>
<link rel="stylesheet" href="<?php echo store_e(store_asset($file)); ?>">
<?php } ?>
<?php if (isset($o["head"]) && $o["head"] !== "") { echo $o["head"]; } ?>
</head>
<body class="<?php echo store_e($o["body_class"]); ?>">
<a class="skip-link" href="#main">Bỏ qua điều hướng</a>
<header class="topbar" data-topbar>
  <div class="container topbar__inner">
    <a class="topbar__brand" href="<?php echo BASE_URL("client/home"); ?>"><?php echo store_brand_html($o, $cmsnt); ?></a>
    <nav class="topbar__nav" aria-label="Điều hướng chính">
      <ul>
<?php foreach ((array) $o["nav"] as $item) {
    if (!isset($item["label"]) || !isset($item["href"])) {
        continue;
    }
    $current = !empty($item["current"]);
    ?>
        <li><a href="<?php echo store_e($item["href"]); ?>"<?php echo $current ? ' aria-current="page"' : ""; ?>><?php echo store_e($item["label"]); ?></a></li>
<?php } ?>
      </ul>
    </nav>
<?php if (!empty($o["search"])) { ?>
    <form class="topbar__search" role="search" action="<?php echo store_e($searchAction); ?>" method="get">
      <label class="sr-only" for="site-search">Tìm sản phẩm</label>
      <input class="input" id="site-search" type="search" name="keyword" value="<?php echo store_e($o["search_value"]); ?>" placeholder="Tìm sản phẩm…" autocomplete="off">
      <button class="btn btn--ghost btn--icon" type="submit" aria-label="Tìm">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
      </button>
    </form>
<?php } ?>
    <div class="topbar__actions">
<?php if (isset($o["actions"]) && $o["actions"] !== "") { echo $o["actions"]; } ?>
<?php if ($user && isset($user["token"]) && $user["token"] !== "") { ?>
      <a class="topbar__balance" href="<?php echo BASE_URL("client/profile"); ?>">
        <span>Số dư</span>
        <strong><?php echo store_e(store_money(isset($user["money"]) ? $user["money"] : 0)); ?></strong>
      </a>
      <a class="btn btn--ghost topbar__account" href="<?php echo BASE_URL("client/profile"); ?>">Tài khoản</a>
<?php } else { ?>
      <a class="btn btn--ghost topbar__account" href="<?php echo BASE_URL("client/login"); ?>">Đăng nhập</a>
<?php } ?>
      <a class="btn topbar__cta" href="<?php echo store_e($o["recharge_href"]); ?>">Nạp tiền</a>
      <button class="topbar__toggle" type="button" aria-expanded="false" aria-controls="site-menu" data-menu-toggle>
        <span class="sr-only">Mở menu</span>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
      </button>
    </div>
  </div>
  <div class="topbar__sheet" id="site-menu" data-menu-sheet hidden>
    <nav aria-label="Điều hướng trên điện thoại">
      <ul>
<?php foreach ((array) $o["nav"] as $item) {
    if (!isset($item["label"]) || !isset($item["href"])) {
        continue;
    }
    ?>
        <li><a href="<?php echo store_e($item["href"]); ?>"><?php echo store_e($item["label"]); ?></a></li>
<?php } ?>
      </ul>
    </nav>
<?php if (!empty($o["search"])) { ?>
    <form class="topbar__search" role="search" action="<?php echo store_e($searchAction); ?>" method="get">
      <label class="sr-only" for="site-search-mobile">Tìm sản phẩm</label>
      <input class="input" id="site-search-mobile" type="search" name="keyword" placeholder="Tìm sản phẩm…" autocomplete="off">
      <button class="btn btn--ghost" type="submit">Tìm</button>
    </form>
<?php } ?>
    <div class="topbar__sheet-actions">
      <a class="btn btn--ghost btn--block" href="<?php echo BASE_URL($user ? "client/logout" : "client/login"); ?>"><?php echo $user ? "Đăng xuất" : "Đăng nhập"; ?></a>
      <a class="btn btn--primary btn--block" href="<?php echo store_e($o["recharge_href"]); ?>">Nạp tiền</a>
    </div>
  </div>
</header>
<main id="main"<?php echo $o["main_class"] !== "" ? ' class="' . store_e($o["main_class"]) . '"' : ""; ?>>
<?php
    }
}

if (!function_exists("store_footer_contact")) {
    function store_footer_contact($cmsnt = null)
    {
        $items = array();
        $hotline = store_site("hotline", $cmsnt);
        $email = store_site("email", $cmsnt);
        $address = store_site("address", $cmsnt);
        if ($hotline !== "") {
            $items[] = '<li><span class="footer__label">Hotline</span> <a href="tel:' . store_e(preg_replace('/[^0-9+]/', "", $hotline))
                . '">' . store_e($hotline) . '</a></li>';
        }
        if ($email !== "") {
            $items[] = '<li><span class="footer__label">Email</span> <a href="mailto:' . store_e($email) . '">'
                . store_e($email) . '</a></li>';
        }
        if ($address !== "") {
            $items[] = '<li><span class="footer__label">Địa chỉ</span> ' . store_e($address) . '</li>';
        }
        return $items;
    }
}

if (!function_exists("store_shell_close")) {
    function store_shell_close($overrides = array())
    {
        $o = store_shell_config($overrides, false);
        $cmsnt = isset($GLOBALS["CMSNT"]) ? $GLOBALS["CMSNT"] : null;
        $year = date("Y");
        $copyright = store_site("copyright_footer", $cmsnt);
        ?>
</main>
<footer class="footer">
  <div class="container">
    <div class="footer__top">
      <div class="footer__brand">
        <a class="topbar__brand" href="<?php echo BASE_URL("client/home"); ?>"><?php echo store_brand_html($o, $cmsnt); ?></a>
<?php if ((string) $o["footer_note"] !== "") { ?>
        <p class="footer__note"><?php echo store_e($o["footer_note"]); ?></p>
<?php } elseif (store_site("description", $cmsnt) !== "") { ?>
        <p class="footer__note"><?php echo store_e(store_site("description", $cmsnt)); ?></p>
<?php } ?>
        <ul class="footer__contact">
<?php echo implode("\n", store_footer_contact($cmsnt)); ?>
        </ul>
      </div>
      <div class="footer__cols">
<?php foreach ((array) $o["footer_columns"] as $column) {
    if (!isset($column["title"]) || empty($column["links"])) {
        continue;
    }
    ?>
        <nav class="footer__col" aria-label="<?php echo store_e($column["title"]); ?>">
          <h2 class="footer__heading"><?php echo store_e($column["title"]); ?></h2>
          <ul>
<?php foreach ((array) $column["links"] as $link) {
    if (!isset($link["label"]) || !isset($link["href"])) {
        continue;
    }
    ?>
            <li><a href="<?php echo store_e($link["href"]); ?>"><?php echo store_e($link["label"]); ?></a></li>
<?php } ?>
          </ul>
        </nav>
<?php } ?>
      </div>
    </div>
    <div class="footer__legal">
      <p>© <?php echo store_e($year); ?> <?php echo store_e($o["title"]); ?><?php
        echo $copyright !== "" ? " · " . store_e($copyright) : "";
      ?></p>
      <p class="footer__credit">Thiết kế &amp; vận hành: <strong>Orbit Office</strong></p>
      <ul class="footer__legal-links">
        <li><a href="<?php echo BASE_URL("client/policy"); ?>">Chính sách</a></li>
        <li><a href="<?php echo BASE_URL("client/faq"); ?>">FAQ</a></li>
        <li><a href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ</a></li>
      </ul>
    </div>
<?php if (isset($o["footer_html"]) && $o["footer_html"] !== "") { echo $o["footer_html"]; } ?>
  </div>
</footer>
<?php
        if (isset($o["scripts"]) && $o["scripts"] !== "") {
            echo $o["scripts"];
        }
        ?><script defer src="<?php echo store_e(store_asset("public/client/orbit/store.js")); ?>"></script>
<?php
        /* Renders nothing at all while public/music/playlist.json has no tracks. */
        require_once __DIR__ . "/music_player.php";
        ?></body>
</html>
<?php
    }
}
