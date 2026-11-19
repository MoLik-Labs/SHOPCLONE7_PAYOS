<?php
/*
 * Legacy Hotmail live-check endpoint.
 *
 * It opened an IMAP session with a username/password pair supplied in the query string, from an
 * unauthenticated URL: anyone could make the shop probe Microsoft accounts, and every credential
 * landed in the web server's access log. Nothing in the shop calls it (the cron job
 * cron/checklive/hotmail.php does its own check server-side), so it is retired rather than patched.
 */
http_response_code(410);
exit('This endpoint has been retired. Use cron/checklive/hotmail.php.');
