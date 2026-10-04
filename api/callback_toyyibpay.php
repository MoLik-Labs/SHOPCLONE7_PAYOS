<?php

// The archived handler credited an invoice from unsigned POST fields.
// This integration is retired; receive new payments through payOS.
http_response_code(410);
exit('Toyyibpay callback retired. Use the payOS webhook.');