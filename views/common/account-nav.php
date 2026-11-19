<?php
/*
 * Shared account navigation + small, strict input helpers for the signed-in pages.
 *
 * Every account page (profile, orders, transactions, favourites, security, …) renders the same list
 * from here, so a new page cannot quietly drop a link or point at a stale route. The helpers clamp
 * the two values a visitor controls on every list page — the page size and the page number — so a
 * URL cannot ask the database for 10 000 rows.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

/* store_e() and the money helpers live with the shell; require it so this file also works when a
   page includes it first (it only defines functions). */
require_once __DIR__ . "/store-shell.php";

if (!function_exists("store_account_links")) {
    /** The account area, in the order a customer needs it. */
    function store_account_links()
    {
        $links = array(
            array("key" => "profile", "label" => "Tổng quan", "href" => BASE_URL("client/profile")),
            array("key" => "purchase-history", "label" => "Lịch sử mua hàng", "href" => BASE_URL("client/purchase-history")),
            array("key" => "orders", "label" => "Đơn hàng", "href" => BASE_URL("product-orders")),
            array("key" => "transactions", "label" => "Biến động số dư", "href" => BASE_URL("client/transactions")),
            array("key" => "favorites", "label" => "Yêu thích", "href" => BASE_URL("client/favorites")),
            array("key" => "security", "label" => "Bảo mật", "href" => BASE_URL("client/security")),
            array("key" => "change-password", "label" => "Đổi mật khẩu", "href" => BASE_URL("client/change-password")),
            array("key" => "logs", "label" => "Nhật ký", "href" => BASE_URL("client/logs")),
        );
        return $links;
    }
}

if (!function_exists("store_account_nav")) {
    function store_account_nav($current = "")
    {
        echo '<nav class="account-nav" aria-label="Khu vực tài khoản">';
        foreach (store_account_links() as $link) {
            $isCurrent = $link["key"] === $current;
            echo '<a href="' . store_e($link["href"]) . '"'
                . ($isCurrent ? ' aria-current="page"' : "") . ">" . store_e($link["label"]) . "</a>";
        }
        echo "</nav>";
    }
}

if (!function_exists("store_affiliate_nav")) {
    /**
     * The affiliate area has three routes of its own. Kept out of store_account_nav() so the main
     * account map stays eight items long instead of eleven.
     */
    function store_affiliate_nav($current = "")
    {
        $links = array(
            array("key" => "affiliates", "label" => "Chương trình", "href" => BASE_URL("client/affiliates")),
            array("key" => "affiliate-history", "label" => "Lịch sử hoa hồng", "href" => BASE_URL("client/affiliate-history")),
            array("key" => "affiliate-withdraw", "label" => "Rút hoa hồng", "href" => BASE_URL("client/affiliate-withdraw")),
        );
        echo '<nav class="account-nav account-nav--sub" aria-label="Khu vực cộng tác viên">';
        foreach ($links as $link) {
            $isCurrent = $link["key"] === $current;
            echo '<a href="' . store_e($link["href"]) . '"'
                . ($isCurrent ? ' aria-current="page"' : "") . ">" . store_e($link["label"]) . "</a>";
        }
        echo "</nav>";
    }
}

if (!function_exists("store_account_limit")) {
    /** Page size: only the sizes the UI offers, never an arbitrary number from the query string. */
    function store_account_limit($value, $default = 20)
    {
        $allowed = array(5, 10, 20, 50, 100);
        $value = (int) $value;
        return in_array($value, $allowed, true) ? $value : $default;
    }
}

if (!function_exists("store_account_page")) {
    function store_account_page($value)
    {
        $page = (int) $value;
        return $page < 1 ? 1 : ($page > 10000 ? 10000 : $page);
    }
}

if (!function_exists("store_account_search")) {
    /** A short, quote-safe search term for the LIKE filters on the history pages. */
    function store_account_search($value, $max = 64)
    {
        $value = trim(strip_tags((string) $value));
        if (function_exists("mb_substr")) {
            $value = mb_substr($value, 0, $max, "UTF-8");
        } else {
            $value = substr($value, 0, $max);
        }
        return addslashes($value);
    }
}

if (!function_exists("store_account_date_range")) {
    /**
     * "2026-01-01 to 2026-01-31" → array("2026-01-01 00:00:00", "2026-01-31 23:59:59").
     * Returns null when the input is not two real dates, so the filter is simply not applied.
     */
    function store_account_date_range($value)
    {
        $parts = explode(" to ", (string) $value);
        if (count($parts) !== 2) {
            return null;
        }
        $from = DateTime::createFromFormat("Y-m-d", trim($parts[0]));
        $to = DateTime::createFromFormat("Y-m-d", trim($parts[1]));
        if (!$from || !$to || $from->format("Y-m-d") !== trim($parts[0]) || $to->format("Y-m-d") !== trim($parts[1])) {
            return null;
        }
        if ($from > $to) {
            return null;
        }
        return array($from->format("Y-m-d") . " 00:00:00", $to->format("Y-m-d") . " 23:59:59");
    }
}

if (!function_exists("store_account_badge")) {
    /** Positive / negative amount, announced as text and not only by colour. */
    function store_account_badge($amount)
    {
        $amount = (float) $amount;
        if ($amount > 0) {
            return '<span class="pill pill--ok">+' . format_currency($amount) . "</span>";
        }
        if ($amount < 0) {
            return '<span class="pill pill--danger">' . format_currency($amount) . "</span>";
        }
        return '<span class="pill">0</span>';
    }
}
