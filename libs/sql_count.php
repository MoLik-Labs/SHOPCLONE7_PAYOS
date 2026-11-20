<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
/**
 * Position of the first top-level occurrence of any keyword in `$keywords`.
 *
 * "Top level" means outside string/identifier quotes and outside parentheses, so a literal such as
 * `WHERE note = 'ORDER BY id'` or a function call `DATE(create_gettime)` never confuses the scan.
 * Each keyword must be surrounded by whitespace, and keywords are compared case-insensitively.
 *
 * @param string   $sql
 * @param string[] $keywords
 * @return array{0:int|false,1:string|null} [position,matched keyword]
 */
function orbit_sql_top_level_keyword($sql, $keywords)
{
    $length = strlen($sql);
    $quote = null;
    $depth = 0;
    for ($i = 0; $i < $length; ++$i) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === "\\") {
                ++$i;
                continue;
            }
            if ($char === $quote) {
                /* '' and `` inside a quoted run are one escaped quote, not a close. */
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    ++$i;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === "`") {
            $quote = $char;
            continue;
        }
        if ($char === "(") {
            ++$depth;
            continue;
        }
        if ($char === ")") {
            if (0 < $depth) {
                --$depth;
            }
            continue;
        }
        if ($depth !== 0) {
            continue;
        }
        foreach ($keywords as $keyword) {
            $keywordLength = strlen($keyword);
            if (strcasecmp(substr($sql, $i, $keywordLength), $keyword) !== 0) {
                continue;
            }
            $before = $i === 0 ? " " : $sql[$i - 1];
            $afterPos = $i + $keywordLength;
            $after = $length <= $afterPos ? " " : $sql[$afterPos];
            if (preg_match('/\s/', $before) && preg_match('/\s/', $after)) {
                return array($i, $keyword);
            }
        }
    }
    return array(false, null);
}
/**
 * Rewrite a plain `SELECT … FROM … [WHERE …] [ORDER BY …]` into the matching COUNT query.
 *
 * `DB::num_rows()` used to run the pagination query and then read every row through mysqli just to
 * count them: on `logs` or `product_sold` a page of 20 records still transferred the whole matching
 * result set. Counting in MySQL sends one integer back.
 *
 * The rewrite is deliberately conservative. It returns null — meaning "run the original query" —
 * for anything whose row count is not the count of a simple table scan:
 *
 *   - the select list must be `*` or a backticked column list (no expressions, no DISTINCT);
 *   - the FROM clause must be one backticked table with an optional alias (no subquery);
 *   - GROUP BY, HAVING, LIMIT and UNION change what the result set means;
 *   - a subquery in WHERE needs the original execution plan.
 *
 * ORDER BY is dropped because it cannot change COUNT(*), and it is located with a quote- and
 * parenthesis-aware scan so a literal containing the words "ORDER BY" cannot truncate the query.
 *
 * @param mixed $sql
 * @return string|null the count query, or null when the original must be used
 */
function orbit_count_sql($sql)
{
    if (!is_string($sql) || !preg_match('/^\s*SELECT\s+/i', $sql)) {
        return null;
    }
    if (!preg_match('/^\s*SELECT\s+(?:\*|`[A-Za-z0-9_]+`(?:\s*,\s*`[A-Za-z0-9_]+`)*)\s+(FROM)\s+/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $tail = substr($sql, $match[1][1]);
    if (!preg_match('/^FROM\s+`[A-Za-z0-9_]+`(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?(?=\s|$)/i', $tail)) {
        return null;
    }
    list($forbiddenPos) = orbit_sql_top_level_keyword($tail, array("DISTINCT", "GROUP BY", "HAVING", "LIMIT", "UNION", "JOIN"));
    if ($forbiddenPos !== false) {
        return null;
    }
    if (preg_match('/\(\s*SELECT\b/i', $tail)) {
        return null;
    }
    list($orderPos) = orbit_sql_top_level_keyword($tail, array("ORDER BY"));
    if ($orderPos !== false) {
        $tail = rtrim(substr($tail, 0, $orderPos));
    }
    return 'SELECT COUNT(*) AS `orbit_count` ' . $tail;
}
