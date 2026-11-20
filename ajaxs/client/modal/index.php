<?php
/*
 * Directory index for the modal partials. Partials are included by ajaxs/client/*.php and must never
 * be requested directly; this file used to be an empty IonCube placeholder that answered 200.
 */
http_response_code(404);
exit('Not found');
