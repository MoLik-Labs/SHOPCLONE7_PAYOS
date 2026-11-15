<?php
/*
 * Orbit charts — the maths, in PHP, so it can be tested without a browser.
 *
 * The renderer in public/client/orbit/chart.js is deliberately thin: it receives coordinates that are
 * already normalised to a 0..100 × 0..100 space (plus axis ticks and donut angles) and only turns them
 * into SVG path strings. Everything that decides *what* the chart shows happens here, where a unit test
 * can check it: nice axis maxima, ticks, normalised points, bar layout, donut angles.
 *
 * No dependencies, no database, no globals. All functions are pure.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!function_exists("chart_nice_max")) {
    /**
     * The smallest "nice" maximum (1/2/5 × 10^n steps) that still contains $max.
     * Used so an axis reads 0 / 500k / 1M rather than 0 / 437,123 / 874,246.
     */
    function chart_nice_max($max)
    {
        $max = (float) $max;
        if ($max <= 0) {
            return 1.0; /* always give the axis a real height */
        }
        $exponent = floor(log10($max));
        $magnitude = pow(10, $exponent);
        foreach (array(1, 2, 2.5, 5, 10) as $step) {
            $candidate = $step * $magnitude;
            if ($candidate >= $max - 1e-9) {
                return (float) $candidate;
            }
        }
        return (float) (10 * $magnitude);
    }
}

if (!function_exists("chart_ticks")) {
    /**
     * Evenly spaced tick values from 0 to $max (inclusive), $count segments.
     */
    function chart_ticks($max, $count = 4)
    {
        $count = max(1, (int) $count);
        $ticks = array();
        for ($i = 0; $i <= $count; $i++) {
            $ticks[] = $max * ($i / $count);
        }
        return $ticks;
    }
}

if (!function_exists("chart_stack_max")) {
    /**
     * Highest stacked total across the series (used when two series share one axis).
     */
    function chart_stack_max($series)
    {
        $max = 0.0;
        $length = 0;
        foreach ((array) $series as $entry) {
            $length = max($length, isset($entry["values"]) ? count($entry["values"]) : 0);
        }
        for ($i = 0; $i < $length; $i++) {
            $total = 0.0;
            foreach ((array) $series as $entry) {
                if (isset($entry["values"][$i])) {
                    $total += max(0.0, (float) $entry["values"][$i]);
                }
            }
            $max = max($max, $total);
        }
        return $max;
    }
}

if (!function_exists("chart_normalise")) {
    /**
     * Normalised 0..1 position of a value on an axis whose height is $max.
     */
    function chart_normalise($value, $max)
    {
        $max = (float) $max;
        if ($max <= 0) {
            return 0.0;
        }
        $ratio = (float) $value / $max;
        return max(0.0, min(1.0, $ratio));
    }
}

if (!function_exists("chart_points")) {
    /**
     * Points for a line/area chart, in a 0..100 × 0..100 space (y grows downwards, like SVG).
     * Returns array(array("x" => float, "y" => float, "value" => float), …).
     */
    function chart_points($values, $max, $round = 2)
    {
        $values = array_values((array) $values);
        $count = count($values);
        if ($count === 0) {
            return array();
        }
        $points = array();
        foreach ($values as $index => $value) {
            $x = $count === 1 ? 50.0 : ($index / ($count - 1)) * 100.0;
            $y = 100.0 - (chart_normalise($value, $max) * 100.0);
            $points[] = array(
                "x" => round($x, $round),
                "y" => round($y, $round),
                "value" => (float) $value,
            );
        }
        return $points;
    }
}

if (!function_exists("chart_line_path")) {
    /**
     * SVG path through normalized points. Straight segments by default; $smooth adds a gentle
     * Catmull-Rom-style curve, which is what makes a revenue chart look calm rather than jagged.
     */
    function chart_line_path($points, $smooth = false)
    {
        $count = count($points);
        if ($count === 0) {
            return "";
        }
        if ($count === 1) {
            return "M " . $points[0]["x"] . " " . $points[0]["y"];
        }
        if (!$smooth) {
            $path = "M " . $points[0]["x"] . " " . $points[0]["y"];
            for ($i = 1; $i < $count; $i++) {
                $path .= " L " . $points[$i]["x"] . " " . $points[$i]["y"];
            }
            return $path;
        }
        /* Horizontal-tangent cubic through each point: no overshoot, no loops. */
        $path = "M " . $points[0]["x"] . " " . $points[0]["y"];
        for ($i = 1; $i < $count; $i++) {
            $previous = $points[$i - 1];
            $current = $points[$i];
            $middle = ($previous["x"] + $current["x"]) / 2;
            $path .= " C " . $middle . " " . $previous["y"] . ", " . $middle . " " . $current["y"]
                . ", " . $current["x"] . " " . $current["y"];
        }
        return $path;
    }
}

if (!function_exists("chart_area_path")) {
    /**
     * Closed path for the filled area under a line, plus the points (for the markers).
     */
    function chart_area_path($points, $baseline = 100)
    {
        $count = count($points);
        if ($count === 0) {
            return "";
        }
        if ($count === 1) {
            return "";
        }
        $path = chart_line_path($points, true);
        $last = $points[$count - 1];
        $first = $points[0];
        return $path . " L " . $last["x"] . " " . $baseline . " L " . $first["x"] . " " . $baseline . " Z";
    }
}

if (!function_exists("chart_bars")) {
    /**
     * Bar layout in the same 0..100 space: each bar gets x/width/height (height 0..100, drawn upwards)
     * and its value. $gap is the share of the slot left empty between bars.
     */
    function chart_bars($values, $max, $gap = 0.35, $round = 2)
    {
        $values = array_values((array) $values);
        $count = count($values);
        if ($count === 0) {
            return array();
        }
        $slot = 100.0 / $count;
        $width = $slot * (1 - max(0.0, min(0.9, (float) $gap)));
        $bars = array();
        foreach ($values as $index => $value) {
            $height = chart_normalise($value, $max) * 100.0;
            $bars[] = array(
                "x" => round(($index * $slot) + (($slot - $width) / 2), $round),
                "y" => round(100.0 - $height, $round),
                "width" => round($width, $round),
                "height" => round($height, $round),
                "value" => (float) $value,
            );
        }
        return $bars;
    }
}

if (!function_exists("chart_donut")) {
    /**
     * Donut slices: share, start/end angle in degrees (0° = 12 o'clock, clockwise) and the running total.
     * Zero-value slices are dropped; a single 100% slice is returned as one full ring.
     */
    function chart_donut($values, $round = 2)
    {
        $clean = array();
        $total = 0.0;
        foreach ((array) $values as $label => $value) {
            $value = max(0.0, (float) $value);
            $clean[$label] = $value;
            $total += $value;
        }
        if ($total <= 0) {
            return array("total" => 0.0, "slices" => array());
        }
        $slices = array();
        $angle = 0.0;
        foreach ($clean as $label => $value) {
            if ($value <= 0) {
                continue;
            }
            $share = $value / $total;
            $sweep = $share * 360.0;
            $slices[] = array(
                "label" => (string) $label,
                "value" => $value,
                "share" => round($share * 100, 2),
                "start" => round($angle, $round),
                "end" => round($angle + $sweep, $round),
            );
            $angle += $sweep;
        }
        /* Guard against rounding drift making the last slice land at 359.98°. */
        if ($slices) {
            $last = count($slices) - 1;
            $slices[$last]["end"] = 360.0;
            /*
             * A very small last slice (99.9999% + 0.0001%) rounds its start to exactly 360. Turning
             * that start into 0 — which is what this used to do — gave the slice a 360° sweep and
             * painted it over the whole ring. Keep a hairline sliver instead: start < end, and the
             * renderer draws 0.01° rather than a second full circle.
             */
            if ($slices[$last]["start"] >= 360.0) {
                $slices[$last]["start"] = 359.99;
            }
            if ($slices[$last]["start"] >= $slices[$last]["end"]) {
                $slices[$last]["start"] = $slices[$last]["end"] - 0.01;
            }
        }
        return array("total" => $total, "slices" => $slices);
    }
}

if (!function_exists("chart_series_stats")) {
    /**
     * Small numbers a chart header can show: total, average, peak, trough and the last value.
     */
    function chart_series_stats($values)
    {
        $values = array_values((array) $values);
        if (!$values) {
            return array("count" => 0, "sum" => 0.0, "average" => 0.0, "max" => 0.0, "min" => 0.0, "last" => 0.0, "first" => 0.0);
        }
        $floats = array_map("floatval", $values);
        return array(
            "count" => count($floats),
            "sum" => array_sum($floats),
            "average" => array_sum($floats) / count($floats),
            "max" => max($floats),
            "min" => min($floats),
            "last" => (float) end($floats),
            "first" => (float) reset($floats),
        );
    }
}

if (!function_exists("chart_number")) {
    /**
     * Human number formatting for axis labels and tooltips: 1234567 → "1,2M" / "1.2M".
     * Kept dependency-free and locale-neutral (the site formats money elsewhere).
     */
    function chart_number($value, $decimals = 1, $decimalPoint = ".", $thousandPoint = ",")
    {
        $value = (float) $value;
        $absolute = abs($value);
        if ($absolute >= 1000000000) {
            return number_format($value / 1000000000, $decimals, $decimalPoint, "") . "B";
        }
        if ($absolute >= 1000000) {
            return number_format($value / 1000000, $decimals, $decimalPoint, "") . "M";
        }
        if ($absolute >= 1000) {
            return number_format($value / 1000, $decimals, $decimalPoint, "") . "k";
        }
        return number_format($value, 0, $decimalPoint, $thousandPoint);
    }
}

if (!function_exists("chart_payload")) {
    /**
     * The whole payload the renderer needs. Keeps the view and the test on one code path.
     *
     * $config:
     *   type      area|line|bar|donut|spark
     *   labels    x labels (line/area) or slice labels (won't be used for donut: see series labels)
     *   series    array of array("name" => string, "values" => array, "color" => accent|ok|warn|danger|info|muted)
     *   slices    array(label => value) for donut
     *   height    pixel height of the drawing area
     *   suffix/prefix  text around numbers in tooltips/labels
     *   format    "number" (default) or "currency" (thousands separator, no decimals)
     */
    function chart_payload($config)
    {
        $type = isset($config["type"]) ? (string) $config["type"] : "bar";
        /* Defaults match orbit_chart_format()/number_format($v, $n, ",", ".") used by the server
           table, so the JavaScript tooltip and the no-JavaScript table print the same number. */
        $decimalsSeparator = isset($config["decimal"]) ? (string) $config["decimal"] : ",";
        $thousandsSeparator = isset($config["thousands"]) ? (string) $config["thousands"] : ".";
        $payload = array(
            "type" => $type,
            "suffix" => isset($config["suffix"]) ? (string) $config["suffix"] : "",
            "prefix" => isset($config["prefix"]) ? (string) $config["prefix"] : "",
            "format" => isset($config["format"]) ? (string) $config["format"] : "number",
            "decimal" => $decimalsSeparator,
            "thousands" => $thousandsSeparator,
            "height" => isset($config["height"]) ? (int) $config["height"] : 220,
            "labels" => array(),
            "series" => array(),
            "hasData" => false,
            "stats" => array(),
        );

        if ($type === "donut") {
            $slices = isset($config["slices"]) ? (array) $config["slices"] : array();
            $donut = chart_donut($slices);
            $colours = array("accent", "ok", "warn", "info", "danger", "muted");
            foreach ($donut["slices"] as $index => $slice) {
                $slice["color"] = $colours[$index % count($colours)];
                $payload["labels"][] = $slice["label"];
                $payload["series"][] = $slice;
            }
            $payload["total"] = $donut["total"];
            $payload["hasData"] = count($donut["slices"]) > 0;
            return $payload;
        }

        $series = array();
        foreach ((array) (isset($config["series"]) ? $config["series"] : array()) as $entry) {
            if (!isset($entry["values"]) || !is_array($entry["values"])) {
                continue;
            }
            $series[] = array(
                "name" => isset($entry["name"]) ? (string) $entry["name"] : "",
                "values" => array_values(array_map("floatval", $entry["values"])),
                "color" => isset($entry["color"]) ? (string) $entry["color"] : "accent",
            );
        }
        if (!$series) {
            return $payload;
        }
        $payload["labels"] = array_values((array) (isset($config["labels"]) ? $config["labels"] : array()));

        $rawMax = chart_stack_max($series);
        /* If the caller asks for two different scales (money + orders), normalise each series separately. */
        $perSeries = !empty($config["independent"]);
        $max = $perSeries ? 1.0 : chart_nice_max($rawMax);
        $payload["max"] = $max;
        $payload["ticks"] = chart_ticks($max, isset($config["ticks"]) ? (int) $config["ticks"] : 4);
        $payload["hasData"] = $rawMax > 0;

        foreach ($series as $index => $entry) {
            $seriesMax = $perSeries && count($entry["values"]) ? chart_nice_max(max($entry["values"])) : $max;
            $out = array(
                "name" => $entry["name"],
                "color" => $entry["color"],
                "stats" => chart_series_stats($entry["values"]),
                "max" => $seriesMax,
            );
            if ($type === "bar") {
                $out["bars"] = chart_bars($entry["values"], $seriesMax, isset($config["gap"]) ? (float) $config["gap"] : 0.35);
            } else {
                $points = chart_points($entry["values"], $seriesMax);
                $out["points"] = $points;
                $out["path"] = chart_line_path($points, !empty($config["smooth"]));
                $out["area"] = chart_area_path($points);
                $out["showMarkers"] = count($points) <= 20;
            }
            $payload["series"][] = $out;
        }
        if (isset($config["sparkline"])) {
            $payload["sparkline"] = (bool) $config["sparkline"];
        }
        return $payload;
    }
}
