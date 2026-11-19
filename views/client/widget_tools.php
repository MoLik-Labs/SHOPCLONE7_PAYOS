<?php
/*
 * /client/widget_tools — retired.
 *
 * This file used to print four legacy tool cards: inline styles, background images from `mod/img/`
 * and links to `/tool/<name>`, a route table that no longer exists. Nothing included it, and
 * /client/tools is the real page (views/client/tools.php).
 *
 * The route is kept as a redirect so a bookmark or an old e-mail still lands somewhere useful
 * instead of a half-styled fragment.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

redirect(base_url("client/tools"));
