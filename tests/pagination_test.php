<?php
/*
 * Orbit pagination — window maths and markup contract.
 *
 *   php tests/pagination_test.php
 */

define("IN_SITE", true);
if (!defined("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim($path, "/");
    }
}
require_once __DIR__ . "/../views/common/pagination.php";

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
        echo "  FAIL " . $label . ($detail !== "" ? "   >> " . $detail : "") . "\n";
    }
}

/* ------------------------------------------------------------------- window */

check("one page shows only itself", orbit_pagination_window(1, 1) === array(1));
check("the window always contains the current page", in_array(4, orbit_pagination_window(4, 20), true));
check("the first and last page are always offered", array(1, 20) === array_values(array_intersect(
    array(1, 20), orbit_pagination_window(10, 20))));
check("the window stays sorted", (function () {
    $window = orbit_pagination_window(10, 40);
    $sorted = $window;
    sort($sorted);
    return $window === $sorted;
})());
check("there are no duplicate page numbers", (function () {
    $window = orbit_pagination_window(3, 5);
    return count($window) === count(array_unique($window));
})());
check("a short list shows every page", orbit_pagination_window(2, 3) === array(1, 2, 3));
check("a huge list is collapsed", count(orbit_pagination_window(500, 1000)) <= 9);
$window = orbit_pagination_window(1, 100);
check("the first page does not show a page 0", min($window) === 1);
$windowEnd = orbit_pagination_window(100, 100);
check("the last page does not run past the end", max($windowEnd) === 100);
check("a page number below the range is clamped", orbit_pagination_window(0, 10) === orbit_pagination_window(1, 10));
check("a page number above the range is clamped", orbit_pagination_window(99, 10) === orbit_pagination_window(10, 10));

/* -------------------------------------------------------------------- state */

$state = orbit_pagination_state(0, 20, 1);
check("no rows means one empty page", $state["pages"] === 1 && $state["total"] === 0 && $state["from"] === 0);
check("…with nothing to go to", $state["has_previous"] === false && $state["has_next"] === false);

$state = orbit_pagination_state(45, 20, 2);
check("the page count rounds up", $state["pages"] === 3);
check("the row range is inclusive and 1-based", $state["from"] === 21 && $state["to"] === 40);
check("the SQL offset matches the page", $state["offset"] === 20);
check("a middle page has both directions", $state["has_previous"] === true && $state["has_next"] === true);

$state = orbit_pagination_state(45, 20, 99);
check("a page past the end is clamped to the last page", $state["page"] === 3);
check("…so the range is the real tail", $state["from"] === 41 && $state["to"] === 45);
check("the last page has no next", $state["has_next"] === false);

$state = orbit_pagination_state(45, 20, -3);
check("a negative page becomes the first page", $state["page"] === 1 && $state["from"] === 1);
$state = orbit_pagination_state(45, 0, 1);
check("a zero page size is treated as one", $state["per_page"] === 1);

check("the summary counts rows, not pages", orbit_pagination_summary(orbit_pagination_state(45, 20, 2)) === "Hiển thị 21–40 trong 45");
check("an empty table says so", orbit_pagination_summary(orbit_pagination_state(0, 20, 1)) === "Không có dòng nào");
check("the summary template can be customised",
    orbit_pagination_summary(orbit_pagination_state(45, 20, 3), "%page%/%pages% (%total%)") === "3/3 (45)");

/* ------------------------------------------------------------------- markup */

function render_pagination($options)
{
    ob_start();
    orbit_pagination($options);
    return ob_get_clean();
}

$html = render_pagination(array(
    "page" => 2,
    "per_page" => 20,
    "total" => 95,
    "href" => function ($page) {
        return "https://shop.example/list?page=" . $page;
    },
));
check("a multi-page table gets a nav landmark", strpos($html, "<nav aria-label=") !== false);
check("the current page is marked, not linked", strpos($html, '<span aria-current="page">2</span>') !== false);
check("previous and next links are present", strpos($html, 'rel="prev"') !== false && strpos($html, 'rel="next"') !== false);
check("links point at the caller's URLs", strpos($html, "https://shop.example/list?page=3") !== false);
check("a gap is shown as an ellipsis, not a page", strpos($html, 'class="pagination__ellipsis"') !== false);
check("the row summary is printed", strpos($html, "Hiển thị 21–40 trong 95") !== false);

$single = render_pagination(array(
    "page" => 1,
    "per_page" => 20,
    "total" => 5,
    "href" => function ($page) {
        return "?page=" . $page;
    },
));
check("a single page prints no nav, only the count", strpos($single, "<nav") === false && strpos($single, "trong 5") !== false);
check("an empty table prints nothing to click", substr_count($single, "<a ") === 0);

$hostile = render_pagination(array(
    "page" => 1,
    "per_page" => 10,
    "total" => 100,
    "href" => function ($page) {
        return 'https://shop.example/?q="><script>alert(1)</script>';
    },
));
check("a hostile href is escaped", strpos($hostile, "<script>alert(1)</script>") === false);
check("…and still printed inside the attribute", strpos($hostile, "&lt;script&gt;") !== false);

$first = render_pagination(array("page" => 1, "per_page" => 10, "total" => 100,
    "href" => function ($page) { return "?page=" . $page; }));
check("the first page disables 'previous' instead of linking to page 0", strpos($first, "is-disabled") !== false && strpos($first, "?page=0") === false);

echo "\npagination_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
