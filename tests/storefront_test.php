<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Storefront home — rendering and data-path contract.
 *
 *   php tests/storefront_test.php
 *
 * No database, no browser: a stub answers the same queries the page sends. The test proves that the
 * page prints real rows, hides sold-out products when the shop asks it to, batches stock into one
 * query, keeps search/sort/filter in SQL and in the markup, never invents a rating, and ships no
 * third-party request.
 */

define("IN_SITE", true);
require_once __DIR__ . "/../libs/money.php";

if (!function_exists("base_url")) {
    function base_url($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}
if (!function_exists("check_string")) {
    function check_string($data)
    {
        return trim(htmlspecialchars(addslashes((string) $data)));
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("format_currency")) {
    function format_currency($amount)
    {
        return number_format((float) $amount, 0, ",", ".") . "đ";
    }
}
if (!function_exists("format_cash")) {
    function format_cash($number, $suffix = "")
    {
        return number_format((float) $number, 0, ",", ".") . (string) $suffix;
    }
}
if (!function_exists("myip")) {
    function myip()
    {
        return "127.0.0.1";
    }
}
if (!function_exists("gettime")) {
    function gettime()
    {
        return date("Y/m/d H:i:s");
    }
}
if (!function_exists("redirect")) {
    function redirect($url)
    {
        echo "REDIRECTED";
    }
}
if (!function_exists("dirImageProduct")) {
    function dirImageProduct($image)
    {
        return "assets/storage/images/products/" . $image;
    }
}

class StorefrontTestCmsnt
{
    public $values = array();
    public $queries = array();
    public $products = array();
    public $categories = array();
    public $stock = array();
    public $counts = array();
    public $total = 0;

    public function site($key)
    {
        return isset($this->values[$key]) ? $this->values[$key] : "";
    }

    public function get_row($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, "FROM `users` WHERE `token`") !== false) {
            return null;
        }
        if (strpos($sql, "FROM `categories`") !== false && strpos($sql, "`slug`") !== false) {
            foreach ($this->categories as $category) {
                if (strpos($sql, "'" . $category["slug"] . "'") !== false) {
                    return $category;
                }
            }
        }
        return null;
    }

    public function get_list($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, "FROM `categories`") !== false) {
            return $this->categories;
        }
        if (strpos($sql, "GROUP BY `category_id`") !== false) {
            return $this->counts;
        }
        if (strpos($sql, "FROM `products`") !== false) {
            return $this->products;
        }
        if (strpos($sql, "FROM `product_stock`") !== false) {
            $rows = array();
            foreach ($this->stock as $code => $count) {
                $rows[] = array("product_code" => $code, "c" => $count);
            }
            return $rows;
        }
        return array();
    }

    public function num_rows($sql)
    {
        $this->queries[] = $sql;
        return $this->total;
    }
}

global $CMSNT;
$CMSNT = new StorefrontTestCmsnt();
$CMSNT->values = array(
    "title" => 'Orbit "Shop" <script>alert(1)</script>',
    "description" => "Shop bán tài khoản & licence",
    "keywords" => "shop, licence",
    "max_show_product_home" => "6",
    "type_show_product" => "BOX",
    "product_hide_outstock" => "1",
    "product_sold_display" => "1",
    "order_by_product_home" => "1",
    "notice_home" => "",
    "popup_status" => "0",
    "popup_noti" => "",
    "hotline" => "0909 123 456",
    "email" => "support@orbit.example",
    "address" => "Quận 1, TP.HCM",
    "bank_status" => "1",
    "momo_status" => "0",
    "card_status" => "0",
    "crypto_status" => "0",
    "paypal_status" => "0",
    "perfectmoney_status" => "0",
    "toyyibpay_status" => "0",
    "squadco_status" => "0",
    "flutterwave_status" => "0",
    "status_demo" => "0",
);

$CMSNT->categories = array(
    array("id" => 1, "name" => "Tài khoản", "slug" => "tai-khoan", "icon" => "", "parent_id" => 1,
        "description" => "Tài khoản dùng ngay"),
    array("id" => 2, "name" => "Licence", "slug" => "licence", "icon" => "", "parent_id" => 1,
        "description" => ""),
);
$CMSNT->counts = array(
    array("category_id" => 1, "c" => 4),
    array("category_id" => 2, "c" => 2),
);
$CMSNT->products = array(
    array("id" => 1, "name" => "Netflix 1 tháng", "slug" => "netflix-1-thang", "price" => 120000,
        "discount" => 0, "short_desc" => "Tài khoản riêng", "images" => "", "sold" => 12,
        "supplier_id" => 0, "api_stock" => 0, "code" => "P1", "category_id" => 1, "status" => 1, "stt" => 9),
    array("id" => 2, "name" => "Hết hàng", "slug" => "het-hang", "price" => 50000,
        "discount" => 0, "short_desc" => "Không còn", "images" => "", "sold" => 0,
        "supplier_id" => 0, "api_stock" => 0, "code" => "P2", "category_id" => 1, "status" => 1, "stt" => 8),
    array("id" => 3, "name" => "Office 365", "slug" => "office-365", "price" => 300000,
        "discount" => 20, "short_desc" => "Bản quyền năm", "images" => "", "sold" => 3,
        "supplier_id" => 0, "api_stock" => 0, "code" => "P3", "category_id" => 2, "status" => 1, "stt" => 7),
    array("id" => 4, "name" => "YouTube Premium", "slug" => "youtube-premium", "price" => 90000,
        "discount" => 0, "short_desc" => "6 tháng", "images" => "", "sold" => 0,
        "supplier_id" => 0, "api_stock" => 0, "code" => "P4", "category_id" => 1, "status" => 1, "stt" => 6),
    array("id" => 5, "name" => "Spotify", "slug" => "spotify", "price" => 70000,
        "discount" => 0, "short_desc" => "3 tháng", "images" => "", "sold" => 1,
        "supplier_id" => 0, "api_stock" => 0, "code" => "P5", "category_id" => 2, "status" => 1, "stt" => 5),
    array("id" => 6, "name" => "Canva Pro", "slug" => "canva-pro", "price" => 150000,
        "discount" => 0, "short_desc" => "1 năm", "images" => "", "sold" => 7,
        "supplier_id" => 0, "api_stock" => 0, "code" => "P6", "category_id" => 1, "status" => 1, "stt" => 4),
);
$CMSNT->stock = array("P1" => 10, "P2" => 0, "P3" => 3, "P4" => 7, "P5" => 2, "P6" => 40);
$CMSNT->total = 40;

$_COOKIE = array();
$_SESSION = array();
$_GET = array(
    "category" => "2",
    "keyword" => "<script>alert(1)</script>",
    "sort" => "price-asc",
    "page" => "2",
);
$_SERVER["SERVER_NAME"] = "shop.example";
$_SERVER["REQUEST_URI"] = "/?module=client&action=home";
$_SERVER["HTTPS"] = "on";

$errors = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$errors) {
    $errors[] = $message . " at " . basename($file) . ":" . $line;
    return true;
});

ob_start();
require __DIR__ . "/../views/client/home.php";
$html = ob_get_clean();
restore_error_handler();

$pass = 0;
$fail = 0;
function check($label, $condition, $detail = "")
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($detail !== "" ? " — " . $detail : "") . "\n";
    }
}

/* --- document ------------------------------------------------------------- */
check("one complete document", substr_count($html, "<!doctype html>") === 1
    && substr_count($html, "</html>") === 1);
check("one main landmark and one footer", substr_count($html, "<main") === 1
    && substr_count($html, "</main>") === 1 && substr_count($html, "<footer") === 1);
check("the light storefront grade is the default", strpos($html, 'data-theme="light"') !== false);
check("the title is escaped, not injected", strpos($html, "<script>alert(1)</script>") === false
    && strpos($html, "&lt;script&gt;alert(1)&lt;/script&gt;") !== false);

/* --- real data ------------------------------------------------------------ */
check("every visible product renders a card", substr_count($html, 'class="product-card"') === 5);
check("the sold-out product is hidden when the shop asks", strpos($html, "Hết hàng</a>") === false
    && strpos($html, "Không còn") === false);
check("a real price is printed", strpos($html, "120.000đ") !== false);
check("a discount prints the old and the new price", strpos($html, "300.000đ") !== false
    && strpos($html, "240.000đ") !== false);
check("category names come from the rows", strpos($html, "Tài khoản") !== false
    && strpos($html, "Licence") !== false);
check("the enabled payment method is offered", strpos($html, "Ngân hàng") !== false);
check("a disabled method is not offered", stripos($html, "MOMO") === false);
check("contact details come from settings", strpos($html, "0909 123 456") !== false
    && strpos($html, "support@orbit.example") !== false);
check("the owner credit is present", strpos($html, "Orbit Office") !== false);

/* --- no invented trust signals ------------------------------------------- */
check("no hardcoded star rating", strpos($html, "icofont-star") === false
    && strpos($html, "fa-star") === false);
check("no invented review count", stripos($html, "reviews") === false && strpos($html, "4.5") === false);
check("no invented testimonial block", stripos($html, "testimonial") === false);

/* --- queries -------------------------------------------------------------- */
$stockQueries = 0;
$listQuery = "";
$countQuery = "";
foreach ($CMSNT->queries as $query) {
    if (strpos($query, "FROM `product_stock`") !== false) {
        $stockQueries++;
        check("the stock query covers the whole grid at once", strpos($query, " IN (") !== false
            && substr_count($query, "SELECT") === 1);
    }
    if (strpos($query, "FROM `products`") !== false
        && strpos($query, "GROUP BY `category_id`") === false) {
        if (strpos($query, "LIMIT") !== false) {
            $listQuery = $query;
        } else {
            $countQuery = $query;
        }
    }
}
check("the list query filters by the real category column", strpos($listQuery, "`category_id` = 2") !== false);
check("the list query sorts in SQL", strpos($listQuery, "ORDER BY `price` ASC") !== false);
check("the list query escapes the keyword", strpos($listQuery, "<script>") === false
    && strpos($listQuery, "%&lt;script&gt;") !== false);
check("the count query counts the same filter without a sort", $countQuery !== ""
    && strpos($countQuery, "`category_id` = 2") !== false && strpos($countQuery, "ORDER BY") === false);
check("exactly one stock query for the page", $stockQueries === 1);

/* Payment methods are read once per request: the nav, the top-bar button and the payment grid all
   ask the same question, and answering it three times would be three round trips. */
$methodReads = 0;
foreach ($CMSNT->queries as $query) {
    if (strpos($query, "FROM `payment_manual`") !== false) {
        $methodReads++;
    }
}
check("payment methods are read once per request", $methodReads === 1);

/* --- controls ------------------------------------------------------------- */
check("the search field is present", strpos($html, 'id="site-search"') !== false
    && strpos($html, 'name="keyword"') !== false);
check("the top-bar recharge button uses an enabled method", strpos($html, 'class="btn topbar__cta" href="https://shop.example/?action=recharge-bank"') !== false);
check("the category filter is a link", strpos($html, 'href="https://shop.example/category/tai-khoan"') !== false);
check("the sort control keeps the current filters", strpos($html, 'name="sort"') !== false
    && strpos($html, 'value="price-asc" selected') !== false);
check("pagination is present and marks the current page", strpos($html, 'aria-label="Phân trang"') !== false
    && strpos($html, 'aria-current="page"') !== false);

/* --- assets and performance ---------------------------------------------- */
check("no third-party script, stylesheet or font", stripos($html, "cdn.jsdelivr") === false
    && stripos($html, "fonts.googleapis") === false && stripos($html, "googleapis.com") === false);
check("the local font is preloaded", strpos($html, "be-vietnam-pro") !== false);
check("no inline style attribute remains", strpos($html, 'style="') === false);
/* Layout contract: sections own their containers, and a view carries exactly one filled primary
   inside the main landmark — two competing calls to action read as a template, not a shop. */
check("the main landmark is full-bleed so sections own their container", strpos($html, '<main id="main" class="">') !== false
    || strpos($html, '<main id="main">') !== false);
$mainHtml = "";
if (preg_match('~<main\b[^>]*>(.*)</main>~s', $html, $mainMatch)) {
    $mainHtml = $mainMatch[1];
}
check("the hero carries one filled primary action", substr_count($mainHtml, "btn btn--primary btn--lg") === 1);
check("the second hero action is an outline, not a second primary", strpos($mainHtml, 'class="btn btn--lg"') !== false
    && substr_count($mainHtml, "btn btn--primary") === 1);
check("no PHP warning or notice reached the page", empty($errors), implode(" | ", $errors));
check("the render emitted no stray PHP tags", strpos($html, "<?php") === false);

/* --- navigation menu ------------------------------------------------------ */
$nav = (string) file_get_contents(__DIR__ . "/../views/client/nav.php");
check("the category tree is read once and grouped in PHP", strpos($nav, '$navCategoryGroups') !== false);
check("no menu queries categories per parent any more", strpos($nav, "parent_id` = 0 ORDER BY") === false);
check("both the megamenu and the mobile menu render the grouped tree",
    substr_count($nav, 'foreach ($navParents as $category)') === 2);
check("the flat footer menu still lists the categories", strpos($nav, "parent_id` != 0") !== false);

echo "\nstorefront_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
