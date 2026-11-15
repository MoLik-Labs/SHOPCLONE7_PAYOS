<?php
/*
 * Sales statistics — pure aggregation + SQL builders for the admin sales dashboard.
 *
 * Two rules this file follows, because money is involved:
 *
 *  1. **Validated input only.** Every date that reaches SQL has been through sales_date(), which
 *     accepts nothing but a real Y-m-d. Every limit is clamped to an integer range. No string from a
 *     request is ever concatenated raw (see sales_sql_* — they only accept what the clean_* helpers
 *     return).
 *  2. **Aggregation is pure.** The functions that turn rows into numbers take arrays and return arrays;
 *     they never touch the database. That is what makes them testable without MySQL, and it means the
 *     dashboard cannot disagree with the exported CSV: both use the same code.
 *
 * Column names come from the queries this shop already runs (see tests/sales_stats_test.php, which
 * checks every identifier used here against the schema strings found in the repository).
 *
 * Meaning of an order for these numbers:
 *   - `refund = 0`  … not refunded
 *   - `trash  = 0`  … not deleted by an admin
 *   - revenue = SUM(pay), cost = SUM(cost), profit = revenue - cost
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

/* ---------------------------------------------------------------- cleaning */

if (!function_exists("sales_date")) {
    /**
     * A real calendar date in Y-m-d, or $default. Anything else (including "2026-02-31") is refused.
     */
    function sales_date($value, $default = null)
    {
        $value = trim((string) $value);
        if (!preg_match("/^(\d{4})-(\d{2})-(\d{2})$/", $value, $match)) {
            return $default;
        }
        if (!checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists("sales_limit")) {
    /**
     * A positive integer limit inside a sane range (never 0, never 10000).
     */
    function sales_limit($value, $default = 10, $min = 1, $max = 200)
    {
        $value = (int) $value;
        if ($value < $min) {
            $value = (int) $default;
        }
        return max($min, min($max, $value));
    }
}

/* -------------------------------------------------------------- aggregation */

if (!function_exists("sales_row")) {
    /**
     * Normalise one summary row from SQL into numbers this dashboard can trust.
     * NULL (no rows at all) becomes 0, which is what an empty shop should show.
     */
    function sales_row($row)
    {
        $orders = isset($row["orders"]) ? (int) $row["orders"] : 0;
        $revenue = isset($row["revenue"]) ? (float) $row["revenue"] : 0.0;
        $cost = isset($row["cost"]) ? (float) $row["cost"] : 0.0;
        return array(
            "orders" => $orders,
            "revenue" => $revenue,
            "cost" => $cost,
            "profit" => $revenue - $cost,
            "average" => $orders > 0 ? $revenue / $orders : 0.0,
            "margin" => $revenue > 0 ? (($revenue - $cost) / $revenue) * 100 : 0.0,
        );
    }
}

if (!function_exists("sales_growth")) {
    /**
     * Percentage change from $previous to $current.
     * Returns pct = null when there is no baseline to compare against — the UI shows "—" rather than
     * a fake +100% (a shop's first sale is not a growth statistic).
     */
    function sales_growth($current, $previous)
    {
        $current = (float) $current;
        $previous = (float) $previous;
        $difference = $current - $previous;
        if ($previous == 0.0) {
            $pct = null;
        } else {
            $pct = ($difference / abs($previous)) * 100;
        }
        $direction = "flat";
        if ($pct !== null && $pct > 0.05) {
            $direction = "up";
        } elseif ($pct !== null && $pct < -0.05) {
            $direction = "down";
        }
        return array(
            "pct" => $pct === null ? null : round($pct, 1),
            "direction" => $direction,
            "difference" => $difference,
        );
    }
}

if (!function_exists("sales_dates")) {
    /**
     * The list of Y-m-d days in a period (inclusive), oldest first.
     */
    function sales_dates($from, $to)
    {
        $from = sales_date($from);
        $to = sales_date($to);
        if ($from === null || $to === null) {
            return array();
        }
        $cursor = strtotime($from . " 00:00:00");
        $end = strtotime($to . " 00:00:00");
        if ($cursor === false || $end === false || $cursor > $end) {
            return array();
        }
        $days = array();
        while ($cursor <= $end && count($days) < 400) {
            $days[] = date("Y-m-d", $cursor);
            $cursor = strtotime("+1 day", $cursor);
        }
        return $days;
    }
}

if (!function_exists("sales_period")) {
    /**
     * A reporting window: the last $days days ending on $end (today by default), with the same-length
     * window immediately before it so growth can be computed.
     */
    function sales_period($days, $end = null)
    {
        $days = sales_limit($days, 30, 1, 400);
        $end = sales_date($end, date("Y-m-d"));
        $to = strtotime($end . " 00:00:00");
        $from = strtotime("-" . ($days - 1) . " day", $to);
        $previousTo = strtotime("-1 day", $from);
        $previousFrom = strtotime("-" . ($days - 1) . " day", $previousTo);
        return array(
            "days" => $days,
            "from" => date("Y-m-d", $from),
            "to" => date("Y-m-d", $to),
            "previous_from" => date("Y-m-d", $previousFrom),
            "previous_to" => date("Y-m-d", $previousTo),
            "label" => $days . "d",
        );
    }
}

if (!function_exists("sales_series")) {
    /**
     * Fill a per-day aggregate into a continuous series, so a day with no sales shows as 0 rather than
     * a missing point that would make a line chart lie about the trend.
     *
     * $rows: rows with a "day" key (Y-m-d) plus numeric fields.
     */
    function sales_series($rows, $dates, $fields = array("orders", "revenue", "cost"))
    {
        $index = array();
        foreach ((array) $rows as $row) {
            $day = isset($row["day"]) ? substr((string) $row["day"], 0, 10) : "";
            if ($day === "") {
                continue;
            }
            if (!isset($index[$day])) {
                $index[$day] = array();
                foreach ($fields as $field) {
                    $index[$day][$field] = 0;
                }
            }
            foreach ($fields as $field) {
                $index[$day][$field] += isset($row[$field]) ? (float) $row[$field] : 0;
            }
        }
        $series = array();
        foreach ((array) $dates as $date) {
            $row = isset($index[$date]) ? $index[$date] : array();
            $entry = array("day" => $date);
            foreach ($fields as $field) {
                $entry[$field] = isset($row[$field]) ? $row[$field] : 0;
            }
            $entry["profit"] = (isset($entry["revenue"]) ? $entry["revenue"] : 0) - (isset($entry["cost"]) ? $entry["cost"] : 0);
            $series[] = $entry;
        }
        return $series;
    }
}

if (!function_exists("sales_top_products")) {
    /**
     * Group already-fetched order rows by product and rank them. The share of each listed product
     * is measured against every row passed in (not just the limited slice), so it stays meaningful
     * when the caller shows only the top few.
     * $rows: array of array("product_name" => …, "product_id" => …, "pay" => …, "cost" => …)
     */
    function sales_top_products($rows, $limit = 10)
    {
        $limit = sales_limit($limit, 10, 1, 100);
        $grouped = array();
        foreach ((array) $rows as $row) {
            $name = isset($row["product_name"]) ? trim((string) $row["product_name"]) : "";
            if ($name === "") {
                $name = "(không có tên)";
            }
            $key = $name . "|" . (isset($row["product_id"]) ? (string) $row["product_id"] : "");
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    "product_id" => isset($row["product_id"]) ? $row["product_id"] : null,
                    "name" => $name,
                    "orders" => 0,
                    "quantity" => 0,
                    "revenue" => 0.0,
                    "cost" => 0.0,
                );
            }
            $grouped[$key]["orders"]++;
            $grouped[$key]["quantity"] += isset($row["amount"]) ? (int) $row["amount"] : 0;
            $grouped[$key]["revenue"] += isset($row["pay"]) ? (float) $row["pay"] : 0.0;
            $grouped[$key]["cost"] += isset($row["cost"]) ? (float) $row["cost"] : 0.0;
        }
        $products = array_values($grouped);
        usort($products, function ($a, $b) {
            if ($a["revenue"] == $b["revenue"]) {
                return $b["orders"] - $a["orders"];
            }
            return $a["revenue"] < $b["revenue"] ? 1 : -1;
        });
        $total = 0.0;
        foreach ($products as $product) {
            $total += $product["revenue"];
        }
        $products = array_slice($products, 0, $limit);
        /*
         * Share uses every product in $rows, not just the ones left after the limit. Dividing by
         * the top N made the column add up to 100% even when the N shown were a small part of sales.
         */
        return sales_product_rows($products, $total);
    }
}

if (!function_exists("sales_product_rows")) {
    /**
     * Normalise product rows that were aggregated in SQL (see sales_sql_top_products()).
     *
     * The share is measured against the period's revenue, not just the rows on screen: the previous
     * version divided by the sum of the top N, so the displayed percentages always added up to 100%
     * even when those eight products were half of what the shop sold. A period total of 0 falls back
     * to the sum of the rows, so a caller that has no summary never divides by zero.
     */
    function sales_product_rows($rows, $totalRevenue = null)
    {
        $products = array();
        $sum = 0.0;
        foreach ((array) $rows as $row) {
            $name = "";
            if (isset($row["name"])) {
                $name = trim((string) $row["name"]);
            } elseif (isset($row["product_name"])) {
                $name = trim((string) $row["product_name"]);
            }
            if ($name === "") {
                $name = "(không có tên)";
            }
            $revenue = isset($row["revenue"]) ? (float) $row["revenue"]
                : (isset($row["pay"]) ? (float) $row["pay"] : 0.0);
            $cost = isset($row["cost"]) ? (float) $row["cost"] : 0.0;
            $products[] = array(
                "product_id" => isset($row["product_id"]) ? $row["product_id"] : null,
                "name" => $name,
                "orders" => isset($row["orders"]) ? (int) $row["orders"] : 0,
                "quantity" => isset($row["quantity"]) ? (int) $row["quantity"]
                    : (isset($row["amount"]) ? (int) $row["amount"] : 0),
                "revenue" => $revenue,
                "cost" => $cost,
                "profit" => $revenue - $cost,
            );
            $sum += $revenue;
        }
        $total = $totalRevenue === null ? $sum : (float) $totalRevenue;
        if ($total <= 0.0) {
            $total = $sum;
        }
        foreach ($products as $index => $product) {
            $products[$index]["share"] = $total > 0.0 ? ($product["revenue"] / $total) * 100 : 0.0;
        }
        return $products;
    }
}

if (!function_exists("sales_totals")) {
    /**
     * Totals over arbitrary order rows (used for the exported CSV and the period table).
     */
    function sales_totals($rows)
    {
        $orders = 0;
        $revenue = 0.0;
        $cost = 0.0;
        foreach ((array) $rows as $row) {
            $orders++;
            $revenue += isset($row["pay"]) ? (float) $row["pay"] : 0.0;
            $cost += isset($row["cost"]) ? (float) $row["cost"] : 0.0;
        }
        return sales_row(array("orders" => $orders, "revenue" => $revenue, "cost" => $cost));
    }
}

if (!function_exists("sales_slices")) {
    /**
     * label => amount, ranked, with tiny entries merged into one "Khác" slice so a donut stays legible.
     */
    function sales_slices($rows, $labelKey, $valueKey, $limit = 6, $otherLabel = "Khác")
    {
        $limit = sales_limit($limit, 6, 1, 20);
        $grouped = array();
        foreach ((array) $rows as $row) {
            $label = isset($row[$labelKey]) && trim((string) $row[$labelKey]) !== ""
                ? trim((string) $row[$labelKey]) : $otherLabel;
            if (!isset($grouped[$label])) {
                $grouped[$label] = 0.0;
            }
            $grouped[$label] += isset($row[$valueKey]) ? (float) $row[$valueKey] : 0.0;
        }
        arsort($grouped);
        $slices = array();
        $position = 0;
        $rest = 0.0;
        foreach ($grouped as $label => $value) {
            $position++;
            if ($position <= $limit) {
                $slices[$label] = $value;
            } else {
                $rest += $value;
            }
        }
        if ($rest != 0.0) {
            /*
             * Add to an existing slice instead of overwriting it: a deposit method (or a product)
             * could legitimately be called "Khác", and the old assignment silently replaced its
             * amount with the remainder, so the donut showed less money than the ledger.
             */
            $slices[$otherLabel] = isset($slices[$otherLabel]) ? $slices[$otherLabel] + $rest : $rest;
        }
        return $slices;
    }
}

if (!function_exists("sales_percent")) {
    /**
     * A share, rounded, never dividing by zero.
     */
    function sales_percent($value, $total, $decimals = 0)
    {
        if ((float) $total == 0.0) {
            return 0.0;
        }
        return round(((float) $value / (float) $total) * 100, $decimals);
    }
}

/* --------------------------------------------------------------- CSV cells */

if (!defined("SALES_CSV_RISKY")) {
    define("SALES_CSV_RISKY", "=+-@|");
}

if (!function_exists("sales_csv_cell")) {
    /**
     * One CSV cell *value*, safe to open in Excel or Google Sheets.
     *
     * A cell that starts with = + - @ | is a formula to a spreadsheet: a customer named
     * "=HYPERLINK(...)" would execute on the accountant's machine. Prefixing with an apostrophe makes
     * the spreadsheet treat the value as text — the classic CSV-injection fix.
     *
     * Quoting is deliberately NOT done here. The writer owns it: fputcsv() quotes exactly once, and
     * pre-quoting a value here made fputcsv() escape the quotes again, so "a;b" reached the file as
     * """a;b""" and Excel showed the quote characters. sales_csv_row() is the writer for callers that
     * do not use fputcsv().
     */
    function sales_csv_cell($value)
    {
        $value = (string) $value;
        /*
         * A plain number is not a formula: -500.00 must stay a number so the accountant can still sum and
         * sort the column. What has to be neutralised is a formula *shape* — "=SUM(A1:A9)", "-2+3",
         * "@cmd|' /C calc'", or anything with leading whitespace before those.
         */
        $leading = ltrim($value);
        $isPlainNumber = is_numeric($leading);
        if (!$isPlainNumber && $leading !== "" && strpos(SALES_CSV_RISKY, $leading[0]) !== false) {
            $value = "'" . $value;
        }
        /* Also neutralise tab/CR injection, which some parsers treat as a new row. */
        return str_replace(array("\t", "\r", "\n"), array(" ", " ", " "), $value);
    }
}

if (!function_exists("sales_csv_row")) {
    /**
     * A complete CSV line for callers that write directly instead of using fputcsv(). Quotes each cell
     * exactly once (RFC 4180), after sales_csv_cell() has neutralised formula shapes.
     */
    function sales_csv_row($cells, $delimiter = ",")
    {
        $encoded = array();
        foreach ((array) $cells as $cell) {
            $value = sales_csv_cell($cell);
            $needsQuotes = $delimiter === ""
                || strpos($value, '"') !== false
                || strpos($value, $delimiter) !== false
                || strpos($value, "\r") !== false
                || strpos($value, "\n") !== false;
            if ($needsQuotes) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $encoded[] = $value;
        }
        return implode($delimiter, $encoded);
    }
}

/* ------------------------------------------------------------ SQL builders */

if (!function_exists("sales_sql_range")) {
    /**
     * DATETIME range used by product_order.create_gettime, disjunctive-safe (no BETWEEN/LIKE traps).
     */
    function sales_sql_range($from, $to, $column = "create_gettime")
    {
        /* Both operands are validated before they are allowed to reach SQL. */
        $from = sales_date($from, "1970-01-01");
        $to = sales_date($to, date("Y-m-d"));
        return "`" . $column . "` >= '" . $from . " 00:00:00' AND `" . $column . "` <= '" . $to . " 23:59:59'";
    }
}

if (!function_exists("sales_sql_valid")) {
    /**
     * The WHERE clause every sales number shares: real orders only.
     * refund = 0 excludes refunded orders; trash = 0 excludes orders deleted by an admin.
     */
    function sales_sql_valid()
    {
        return "`refund` = 0 AND `trash` = 0";
    }
}

if (!function_exists("sales_sql_summary")) {
    function sales_sql_summary($from, $to)
    {
        return "SELECT COUNT(`id`) AS orders, COALESCE(SUM(`pay`), 0) AS revenue, COALESCE(SUM(`cost`), 0) AS cost"
            . " FROM `product_order` WHERE " . sales_sql_valid() . " AND " . sales_sql_range($from, $to);
    }
}

if (!function_exists("sales_sql_by_day")) {
    /**
     * Revenue per day. DATE() on a DATETIME column is index-unfriendly, but the WHERE range still uses
     * the index, so only the rows in the window are grouped — which is what matters here.
     */
    function sales_sql_by_day($from, $to)
    {
        return "SELECT DATE(`create_gettime`) AS day, COUNT(`id`) AS orders,"
            . " COALESCE(SUM(`pay`), 0) AS revenue, COALESCE(SUM(`cost`), 0) AS cost"
            . " FROM `product_order` WHERE " . sales_sql_valid() . " AND " . sales_sql_range($from, $to)
            . " GROUP BY DATE(`create_gettime`) ORDER BY day ASC";
    }
}

if (!function_exists("sales_sql_orders")) {
    /**
     * The newest order rows for the on-screen "recent orders" table. Not used for ranking or the
     * export any more: a bounded newest-first list is not the period (see sales_sql_orders_page()).
     * $limit is clamped, so this can never dump an unbounded table.
     */
    function sales_sql_orders($from, $to, $limit = 200)
    {
        $limit = sales_limit($limit, 200, 1, 5000);
        return "SELECT `id`, `trans_id`, `buyer`, `product_id`, `product_name`, `amount`, `pay`, `cost`,"
            . " `create_gettime` FROM `product_order` WHERE " . sales_sql_valid() . " AND " . sales_sql_range($from, $to)
            . " ORDER BY `id` DESC LIMIT " . $limit;
    }
}

if (!function_exists("sales_sql_orders_page")) {
    /**
     * One page of order rows for a whole-period export, ordered by id and filtered with a keyset
     * (`id` > last seen) rather than OFFSET. The CSV used to reuse the screen's 500-row query, so a
     * period with more orders silently exported a fraction of them under a "orders in the period"
     * heading. Keyset paging keeps every row reachable without loading the table at once.
     */
    function sales_sql_orders_page($from, $to, $afterId = 0, $limit = 500)
    {
        $afterId = max(0, (int) $afterId);
        $limit = sales_limit($limit, 500, 1, 2000);
        return "SELECT `id`, `trans_id`, `buyer`, `product_id`, `product_name`, `amount`, `pay`, `cost`,"
            . " `create_gettime` FROM `product_order` WHERE " . sales_sql_valid() . " AND " . sales_sql_range($from, $to)
            . " AND `id` > " . $afterId . " ORDER BY `id` ASC LIMIT " . $limit;
    }
}

if (!function_exists("sales_each_order")) {
    /**
     * Walk every order in a period, one keyset page at a time, calling $callback for each row.
     *
     * $fetch receives a SQL string and returns an array of rows (the shop's get_list). The result says
     * how many rows were handed over and whether the shop was read to the end. $max is a safety stop:
     * if a period contains more rows than that, the caller must say so rather than pretend the export
     * is complete. A page of rows without usable ids also stops instead of looping forever.
     *
     * Returns array("rows" => int, "complete" => bool).
     */
    function sales_each_order($fetch, $from, $to, $callback, $chunk = 500, $max = 100000)
    {
        $chunk = sales_limit($chunk, 500, 1, 2000);
        $max = sales_limit($max, 100000, 1, 1000000);
        $afterId = 0;
        $rows = 0;
        while ($rows < $max) {
            $page = call_user_func($fetch, sales_sql_orders_page($from, $to, $afterId, $chunk));
            if (!is_array($page) || empty($page)) {
                return array("rows" => $rows, "complete" => true);
            }
            foreach ($page as $row) {
                if ($rows >= $max) {
                    /* the safety stop is a hard cap, even if a page would push past it */
                    return array("rows" => $rows, "complete" => false);
                }
                call_user_func($callback, $row);
                $rows++;
                if (isset($row["id"])) {
                    $afterId = (int) $row["id"];
                }
            }
            if (count($page) < $chunk) {
                return array("rows" => $rows, "complete" => true);
            }
            if ($afterId <= 0) {
                /* no ids to page on: stop rather than re-reading the same page forever */
                return array("rows" => $rows, "complete" => false);
            }
        }
        return array("rows" => $rows, "complete" => false);
    }
}

if (!function_exists("sales_sql_top_products")) {
    /**
     * Best-selling products over the whole reporting window, ranked by revenue in SQL.
     *
     * The dashboard used to rank the 500 newest order rows it had fetched for the "recent orders"
     * table. On a period with more than 500 orders that ranking was not the period's ranking: a
     * product that sold heavily earlier in the window could be missing even though its money was in
     * the revenue KPI. Aggregating in SQL makes the table and the KPI describe the same rows.
     *
     * Grouping by product_id merges a product's rows; MAX(product_name) supplies a display name when
     * the product was renamed (the most recent name is not tracked, so the alphabetically last is used).
     */
    function sales_sql_top_products($from, $to, $limit = 8)
    {
        $limit = sales_limit($limit, 8, 1, 100);
        return "SELECT `product_id`, MAX(`product_name`) AS name, COUNT(`id`) AS orders,"
            . " COALESCE(SUM(`amount`), 0) AS quantity, COALESCE(SUM(`pay`), 0) AS revenue,"
            . " COALESCE(SUM(`cost`), 0) AS cost"
            . " FROM `product_order` WHERE " . sales_sql_valid() . " AND " . sales_sql_range($from, $to)
            . " GROUP BY `product_id` ORDER BY revenue DESC LIMIT " . $limit;
    }
}

if (!function_exists("sales_sql_top_customers")) {
    /**
     * Best customers by revenue. LEFT JOIN so an order whose buyer row was deleted still counts.
     */
    function sales_sql_top_customers($from, $to, $limit = 10)
    {
        $limit = sales_limit($limit, 10, 1, 100);
        return "SELECT o.`buyer` AS buyer_id, u.`username` AS username, u.`email` AS email,"
            . " COUNT(o.`id`) AS orders, COALESCE(SUM(o.`pay`), 0) AS revenue"
            . " FROM `product_order` o LEFT JOIN `users` u ON u.`id` = o.`buyer`"
            . " WHERE " . str_replace(array("`refund`", "`trash`", "`create_gettime`"), array("o.`refund`", "o.`trash`", "o.`create_gettime`"), sales_sql_valid() . " AND " . sales_sql_range($from, $to))
            . " GROUP BY o.`buyer`, u.`username`, u.`email` ORDER BY revenue DESC LIMIT " . $limit;
    }
}

if (!function_exists("sales_sql_deposits_by_method")) {
    /**
     * Money added to customer wallets, grouped by payment method.
     * deposit_log.create_time is a UNIX timestamp, so it is compared as one (index friendly).
     */
    function sales_sql_deposits_by_method($from, $to)
    {
        $from = sales_date($from, "1970-01-01");
        $to = sales_date($to, date("Y-m-d"));
        $start = strtotime($from . " 00:00:00");
        $end = strtotime($to . " 23:59:59");
        return "SELECT `method`, COUNT(`id`) AS deposits, COALESCE(SUM(`amount`), 0) AS amount,"
            . " COALESCE(SUM(`received`), 0) AS received FROM `deposit_log`"
            . " WHERE `create_time` >= " . (int) $start . " AND `create_time` <= " . (int) $end
            . " AND `is_virtual` = 0 GROUP BY `method` ORDER BY amount DESC";
    }
}

if (!function_exists("sales_sql_counts")) {
    /**
     * Headline counters for the shop (users, orders ever). Same tables the existing dashboard uses.
     */
    function sales_sql_counts()
    {
        return array(
            "users" => "SELECT COUNT(`id`) AS total FROM `users` WHERE `admin` = 0",
            "orders_all" => "SELECT COUNT(`id`) AS total FROM `product_order` WHERE " . sales_sql_valid(),
        );
    }
}

if (!function_exists("sales_sql_new_users")) {
    /**
     * Customers who registered inside the window.
     *
     * `users` has no `create_gettime`: the shop writes registration time to `create_date` through
     * gettime(), which formats it as "Y/m/d H:i:s". The bounds therefore use slashes too, so the
     * comparison is correct whether the column is DATETIME (MySQL parses the literal) or a string
     * (byte comparison against the format the shop itself writes).
     */
    function sales_sql_new_users($from, $to)
    {
        $from = sales_date($from, "1970-01-01");
        $to = sales_date($to, date("Y-m-d"));
        $fromStamp = str_replace("-", "/", $from) . " 00:00:00";
        $toStamp = str_replace("-", "/", $to) . " 23:59:59";
        return "SELECT COUNT(`id`) AS total FROM `users` WHERE `admin` = 0"
            . " AND `create_date` >= '" . $fromStamp . "'"
            . " AND `create_date` <= '" . $toStamp . "'";
    }
}

if (!function_exists("sales_method_label")) {
    /**
     * A readable name for a payment-method slug without inventing brands: unknown slugs are title-cased.
     */
    function sales_method_label($method)
    {
        $method = trim((string) $method);
        if ($method === "") {
            return "Khác";
        }
        $known = array(
            "payos" => "payOS", "bank" => "Ngân hàng", "momo" => "MoMo", "card" => "Thẻ cào",
            "crypto" => "Crypto", "paypal" => "PayPal", "manual" => "Thủ công",
            "perfectmoney" => "Perfect Money", "flutterwave" => "Flutterwave", "squadco" => "SquadCo",
            "toyyibpay" => "ToyyibPay", "gtf" => "GachtheFast", "gtf-card" => "GachtheFast (thẻ)",
            "gtf-bank" => "GachtheFast (bank)",
        );
        $key = strtolower($method);
        if (isset($known[$key])) {
            return $known[$key];
        }
        return ucwords(str_replace(array("-", "_"), " ", $key));
    }
}
