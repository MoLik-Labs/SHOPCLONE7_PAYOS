<?php
/*
 * Orbit chart — the markup half of the chart component.
 *
 *   require_once __DIR__ . "/chart.php";
 *   orbit_chart(array(
 *       "type"    => "area",              // area | line | bar | donut | spark
 *       "title"   => "Doanh thu 30 ngày",
 *       "caption" => "Đã trừ đơn hoàn tiền",
 *       "labels"  => array("2026-09-06", …),
 *       "series"  => array(
 *           array("name" => "Doanh thu", "values" => array(120000, …), "color" => "accent"),
 *       ),
 *       // donut instead of labels/series:
 *       "slices"  => array("payOS" => 1200000, "Ngân hàng" => 400000),
 *       "height"  => 240,
 *       "suffix"  => "đ",                  // money/unit shown after every number
 *       "prefix"  => "",
 *       "format"  => "currency",           // currency = no decimals, number = smart decimals
 *       "formatter" => function ($value) { return format_currency($value); },  // optional: table + legend
 *       "table"   => true,                 // render the no-JavaScript / screen-reader table
 *       "empty"   => "Chưa có dữ liệu trong khoảng này",
 *   ));
 *
 * The component is content-free: it never invents a title, a colour or an empty-state sentence — the
 * caller passes them. Without JavaScript the table *is* the chart; with JavaScript the drawing replaces
 * it visually and the table stays available to screen readers (see chart.css).
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!function_exists("orbit_chart_assets")) {
    /**
     * Prints the chart stylesheet/script once per request (the dashboard may draw a dozen charts).
     */
    function orbit_chart_assets()
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        $base = BASE_URL("public/client/orbit/");
        $version = function ($relative) {
            $absolute = __DIR__ . "/../../" . ltrim($relative, "/");
            return is_file($absolute) ? (string) filemtime($absolute) : "1";
        };
        echo '<link rel="stylesheet" href="' . $base . 'chart.css?v=' . $version("public/client/orbit/chart.css") . '">' . "\n";
        echo '<script src="' . $base . 'chart.js?v=' . $version("public/client/orbit/chart.js") . '" defer></script>' . "\n";
    }
}

if (!function_exists("orbit_chart_format")) {
    /**
     * Default number formatting for labels/tables; a caller-provided formatter wins.
     */
    function orbit_chart_format($value, $config)
    {
        if (isset($config["formatter"]) && is_callable($config["formatter"])) {
            return (string) call_user_func($config["formatter"], $value);
        }
        $prefix = isset($config["prefix"]) ? (string) $config["prefix"] : "";
        $suffix = isset($config["suffix"]) ? (string) $config["suffix"] : "";
        $decimals = (isset($config["format"]) && $config["format"] === "currency") ? 0 : 2;
        return $prefix . number_format((float) $value, $decimals, ",", ".") . $suffix;
    }
}

if (!function_exists("orbit_chart")) {
    /**
     * Prints one chart (or its empty state, when there is nothing honest to draw).
     */
    function orbit_chart($config)
    {
        if (!function_exists("chart_payload")) {
            require_once __DIR__ . "/../../libs/chart.php";
        }
        $type = isset($config["type"]) ? (string) $config["type"] : "bar";
        $title = isset($config["title"]) ? (string) $config["title"] : "";
        $caption = isset($config["caption"]) ? (string) $config["caption"] : "";
        $withTable = !array_key_exists("table", $config) || $config["table"];
        $payload = chart_payload($config);
        $hasData = !empty($payload["hasData"]);
        $emptyText = isset($config["empty"]) ? (string) $config["empty"] : "";
        $tableId = "chart-table-" . substr(md5((string) ($title . $type . mt_rand())), 0, 8);

        echo '<figure class="chart" data-chart';
        if (!empty($config["summary"])) {
            echo ' data-summary="' . htmlspecialchars((string) $config["summary"], ENT_QUOTES, "UTF-8") . '"';
        }
        echo '>' . "\n";

        if ($title !== "" || $caption !== "") {
            echo '<figcaption class="chart__head">';
            if ($title !== "") {
                echo '<h3 class="chart__title">' . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . '</h3>';
            }
            if ($caption !== "") {
                echo '<p class="chart__caption">' . htmlspecialchars($caption, ENT_QUOTES, "UTF-8") . '</p>';
            }
            echo '</figcaption>' . "\n";
        }

        if (!$hasData) {
            /* Yours is the honest sentence; this component only frames it. */
            echo '<div class="chart__empty">' . htmlspecialchars($emptyText !== "" ? $emptyText : "—", ENT_QUOTES, "UTF-8") . '</div>' . "\n";
            echo '</figure>' . "\n";
            return;
        }

        echo '<div class="chart__canvas" aria-hidden="true"></div>' . "\n";

        /* Legend: server-rendered so it is correct without JavaScript (and for donuts it carries the shares). */
        if ($type === "donut") {
            echo '<ul class="chart__legend">' . "\n";
            foreach ($payload["series"] as $slice) {
                echo '<li><span class="chart__swatch c-' . htmlspecialchars($slice["color"], ENT_QUOTES, "UTF-8") . '"></span>'
                    . htmlspecialchars($slice["label"], ENT_QUOTES, "UTF-8")
                    . ' <span class="chart__legend-value">' . htmlspecialchars(orbit_chart_format($slice["value"], $config), ENT_QUOTES, "UTF-8") . '</span>'
                    . ' <span class="faint">' . htmlspecialchars((string) $slice["share"], ENT_QUOTES, "UTF-8") . '%</span></li>' . "\n";
            }
            echo '</ul>' . "\n";
        } elseif (count($payload["series"]) > 1) {
            echo '<ul class="chart__legend">' . "\n";
            foreach ($payload["series"] as $series) {
                echo '<li><span class="chart__swatch c-' . htmlspecialchars($series["color"], ENT_QUOTES, "UTF-8") . '"></span>'
                    . htmlspecialchars($series["name"], ENT_QUOTES, "UTF-8")
                    . ' <span class="chart__legend-value">' . htmlspecialchars(orbit_chart_format($series["stats"]["sum"], $config), ENT_QUOTES, "UTF-8") . '</span></li>' . "\n";
            }
            echo '</ul>' . "\n";
        }

        /* The payload the renderer consumes. JSON_HEX_TAG keeps it inside the script element. */
        echo '<script type="application/json">' . str_replace(
            array("</"),
            array("<\\/"),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        ) . '</script>' . "\n";

        if ($withTable) {
            echo '<table class="chart__table" id="' . $tableId . '">' . "\n";
            if ($type === "donut") {
                echo '<caption>' . htmlspecialchars($title !== "" ? $title : "Data", ENT_QUOTES, "UTF-8") . '</caption>' . "\n";
                echo '<thead><tr><th>Mục</th><th class="num">Giá trị</th><th class="num">Tỷ lệ</th></tr></thead>' . "\n<tbody>\n";
                foreach ($payload["series"] as $slice) {
                    echo '<tr><td>' . htmlspecialchars($slice["label"], ENT_QUOTES, "UTF-8") . '</td>'
                        . '<td class="num">' . htmlspecialchars(orbit_chart_format($slice["value"], $config), ENT_QUOTES, "UTF-8") . '</td>'
                        . '<td class="num">' . htmlspecialchars((string) $slice["share"], ENT_QUOTES, "UTF-8") . '%</td></tr>' . "\n";
                }
                echo '</tbody>' . "\n";
            } else {
                $columns = array();
                foreach ($payload["series"] as $series) {
                    $columns[] = $series["name"] !== "" ? $series["name"] : "Value";
                }
                echo '<caption>' . htmlspecialchars($title !== "" ? $title : "Data", ENT_QUOTES, "UTF-8") . '</caption>' . "\n";
                echo '<thead><tr><th>' . (isset($config["label_head"]) ? htmlspecialchars((string) $config["label_head"], ENT_QUOTES, "UTF-8") : "Mốc") . '</th>';
                foreach ($columns as $column) {
                    echo '<th class="num">' . htmlspecialchars($column, ENT_QUOTES, "UTF-8") . '</th>';
                }
                echo '</tr></thead>' . "\n<tbody>\n";
                foreach ($payload["labels"] as $index => $label) {
                    echo '<tr><td>' . htmlspecialchars((string) $label, ENT_QUOTES, "UTF-8") . '</td>';
                    foreach ($payload["series"] as $series) {
                        $value = isset($series["values"][$index]) ? $series["values"][$index] : 0;
                        echo '<td class="num">' . htmlspecialchars(orbit_chart_format($value, $config), ENT_QUOTES, "UTF-8") . '</td>';
                    }
                    echo '</tr>' . "\n";
                }
                echo '</tbody>' . "\n";
            }
            echo '</table>' . "\n";
        }

        echo '</figure>' . "\n";
    }
}
