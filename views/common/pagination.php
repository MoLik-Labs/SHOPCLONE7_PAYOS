<?php
/*
 * Orbit pagination — server-side, link-based, no JavaScript.
 *
 *   require_once __DIR__ . "/pagination.php";
 *   orbit_pagination(array(
 *       "page"     => $page,               // current page, 1-based
 *       "per_page" => 20,
 *       "total"    => $totalRows,
 *       "href"     => function ($page) { return base_url_admin("product-orders&page=" . $page); },
 *       "labels"   => array("summary" => "Hiển thị %from%–%to% trong %total%"),
 *   ));
 *
 * The caller owns the URL (this shop has several URL builders), so the component never builds one —
 * it only prunes and clamps the window. Pages beyond the end are clamped, so a stale bookmark shows
 * the last page instead of an empty table.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!function_exists("orbit_pagination_window")) {
    /**
     * Which page numbers to show: always the first, the last, the current and its neighbours, with
     * "…" gaps. Pure function — tested in tests/pagination_test.php.
     */
    function orbit_pagination_window($page, $pages, $edge = 1, $around = 1)
    {
        $pages = max(1, (int) $pages);
        /* Clamp here too: this is a public helper, and a window that does not contain the current page
           would render a pagination bar with no "you are here". */
        $page = min($pages, max(1, (int) $page));
        $shown = array();
        $add = function ($number) use (&$shown, $pages) {
            if ($number >= 1 && $number <= $pages && !in_array($number, $shown, true)) {
                $shown[] = $number;
            }
        };
        for ($i = 1; $i <= min($edge, $pages); $i++) {
            $add($i);
        }
        for ($i = $page - $around; $i <= $page + $around; $i++) {
            $add($i);
        }
        for ($i = max(1, $pages - $edge + 1); $i <= $pages; $i++) {
            $add($i);
        }
        sort($shown);
        return $shown;
    }
}

if (!function_exists("orbit_pagination_state")) {
    /**
     * Normalise the inputs: current page, page count, and the row range this page covers.
     */
    function orbit_pagination_state($total, $perPage, $page)
    {
        $total = max(0, (int) $total);
        $perPage = max(1, (int) $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = (int) $page;
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $pages) {
            $page = $pages;
        }
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = min($total, $page * $perPage);
        return array(
            "total" => $total,
            "per_page" => $perPage,
            "pages" => $pages,
            "page" => $page,
            "from" => $from,
            "to" => $to,
            "offset" => ($page - 1) * $perPage,
            "has_previous" => $page > 1,
            "has_next" => $page < $pages,
        );
    }
}

if (!function_exists("orbit_pagination_summary")) {
    function orbit_pagination_summary($state, $template = "Hiển thị %from%–%to% trong %total%")
    {
        if ($state["total"] === 0) {
            return "Không có dòng nào";
        }
        return str_replace(
            array("%from%", "%to%", "%total%", "%page%", "%pages%"),
            array(
                number_format($state["from"]),
                number_format($state["to"]),
                number_format($state["total"]),
                number_format($state["page"]),
                number_format($state["pages"]),
            ),
            (string) $template
        );
    }
}

if (!function_exists("orbit_pagination")) {
    /**
     * $options: page, per_page, total, href (callable), labels, aria (label for the <nav>).
     */
    function orbit_pagination($options)
    {
        $state = orbit_pagination_state(
            isset($options["total"]) ? $options["total"] : 0,
            isset($options["per_page"]) ? $options["per_page"] : 20,
            isset($options["page"]) ? $options["page"] : 1
        );
        if ($state["pages"] <= 1) {
            if ($state["total"] > 0) {
                echo '<div class="pagination-bar"><span class="pagination-bar__info">'
                    . htmlspecialchars(orbit_pagination_summary($state, isset($options["labels"]["summary"])
                        ? $options["labels"]["summary"] : "Hiển thị %from%–%to% trong %total%"), ENT_QUOTES, "UTF-8")
                    . '</span></div>' . "\n";
            }
            return;
        }
        $href = isset($options["href"]) && is_callable($options["href"]) ? $options["href"] : null;
        if ($href === null) {
            return;
        }
        $window = orbit_pagination_window($state["page"], $state["pages"]);
        $aria = isset($options["aria"]) ? (string) $options["aria"] : "Phân trang";
        $summary = orbit_pagination_summary($state, isset($options["labels"]["summary"])
            ? $options["labels"]["summary"] : "Hiển thị %from%–%to% trong %total%");

        echo '<div class="pagination-bar">' . "\n";
        echo '<span class="pagination-bar__info">' . htmlspecialchars($summary, ENT_QUOTES, "UTF-8") . '</span>' . "\n";
        echo '<nav aria-label="' . htmlspecialchars($aria, ENT_QUOTES, "UTF-8") . '">' . "\n";
        echo '<ul class="pagination">' . "\n";

        if ($state["has_previous"]) {
            echo '<li><a href="' . htmlspecialchars(call_user_func($href, $state["page"] - 1), ENT_QUOTES, "UTF-8")
                . '" rel="prev" aria-label="Trang trước">‹</a></li>' . "\n";
        } else {
            echo '<li><span class="is-disabled" aria-hidden="true">‹</span></li>' . "\n";
        }

        $previous = 0;
        foreach ($window as $number) {
            if ($previous && $number > $previous + 1) {
                echo '<li><span class="pagination__ellipsis" aria-hidden="true">…</span></li>' . "\n";
            }
            if ($number === $state["page"]) {
                echo '<li><span aria-current="page">' . number_format($number) . '</span></li>' . "\n";
            } else {
                echo '<li><a href="' . htmlspecialchars(call_user_func($href, $number), ENT_QUOTES, "UTF-8")
                    . '" aria-label="Trang ' . number_format($number) . '">' . number_format($number) . '</a></li>' . "\n";
            }
            $previous = $number;
        }

        if ($state["has_next"]) {
            echo '<li><a href="' . htmlspecialchars(call_user_func($href, $state["page"] + 1), ENT_QUOTES, "UTF-8")
                . '" rel="next" aria-label="Trang sau">›</a></li>' . "\n";
        } else {
            echo '<li><span class="is-disabled" aria-hidden="true">›</span></li>' . "\n";
        }

        echo '</ul>' . "\n</nav>\n</div>\n";
    }
}
