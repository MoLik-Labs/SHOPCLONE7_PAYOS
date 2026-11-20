<?php
/*
 * One seam for "now".
 *
 * Pages normally read time() directly. The static preview needs a frozen clock so a rebuild is
 * byte-identical, and a countdown ("779 seconds left") is the one value that changes every second.
 * The preview defines orbit_now() before this file is loaded; production gets the real clock.
 */

if (!function_exists("orbit_now")) {
    function orbit_now()
    {
        return time();
    }
}
