<?php
/* Test-only stand-in for Composer's autoloader.
 *
 * The checkout has no vendor/ (it is gitignored). The legacy payment-gate probe loads entry points
 * that include libs/db.php, which needs Dotenv before it reaches the 410 guard. This stub supplies
 * exactly that class so the guard itself can be exercised; it is written to vendor/autoload.php by
 * tools/dev/legacy-gate.sh and must never be deployed or used by the application. */
namespace Dotenv {
    class Dotenv
    {
        public static function createImmutable($path = null) { return new self(); }
        public function load() { return array(); }
        public function safeLoad() { return array(); }
    }
}
