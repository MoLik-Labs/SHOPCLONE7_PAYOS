<?php
namespace PHPMailer\PHPMailer {
    /* CLI only: these files are test code, not a route. Without this, a deployment that does not
       honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
    if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
        http_response_code(404);
        exit;
    }

    /** Records what sendCSM() asks PHPMailer to do, without an SMTP server. */
    class PHPMailer
    {
        public static $calls = array();
        public static $sendResult = true;
        public static $throwOnSend = false;

        public $SMTPDebug;
        public $Debugoutput;
        public $Host;
        public $SMTPAuth;
        public $Username;
        public $Password;
        public $SMTPSecure;
        public $Port;
        public $Timeout;
        public $Subject;
        public $Body;
        public $CharSet;
        public $From;
        public $FromName;

        public function __construct() {}
        public function isSMTP() { self::$calls[] = array("isSMTP"); }
        public function setFrom($address, $name = "") { self::$calls[] = array("setFrom", $address, $name); }
        public function addAddress($address, $name = "") { self::$calls[] = array("addAddress", $address, $name); }
        public function addAttachment($path) { self::$calls[] = array("addAttachment", $path); }
        public function addReplyTo($address, $name = "") { self::$calls[] = array("addReplyTo", $address, $name); }
        public function addBCC($address) { self::$calls[] = array("addBCC", $address); }
        public function isHTML($flag) { self::$calls[] = array("isHTML", (bool) $flag); }
        public function send()
        {
            self::$calls[] = array("send");
            if (self::$throwOnSend) {
                throw new \RuntimeException("Could not instantiate mail function.");
            }
            return self::$sendResult;
        }
        public static function reset()
        {
            self::$calls = array();
            self::$sendResult = true;
            self::$throwOnSend = false;
        }
        public static function names()
        {
            return array_map(function ($call) { return $call[0]; }, self::$calls);
        }
        public static function first($name)
        {
            foreach (self::$calls as $call) {
                if ($call[0] === $name) {
                    return $call;
                }
            }
            return null;
        }
    }
}

namespace {
    define("IN_SITE", true);

    class DB
    {
        public static $settings = array();
        public function __construct() {}
        public function site($name)
        {
            return isset(self::$settings[$name]) ? self::$settings[$name] : "";
        }
    }

    require_once __DIR__ . "/../libs/sendEmail.php";

    $pass = 0;
    $fail = 0;
    function check($label, $ok)
    {
        global $pass, $fail;
        $ok ? $pass++ : $fail++;
        fwrite(STDOUT, ($ok ? "PASS  " : "FAIL  ") . $label . "\n");
    }

    DB::$settings = array(
        "smtp_status" => 1,
        "smtp_host" => "smtp.example.test",
        "smtp_email" => "shop@example.test",
        "smtp_password" => "secret",
        "smtp_encryption" => "tls",
        "smtp_port" => "587",
        "title" => "Orbit Office",
    );

    /* 1. A message with no body is never sent. */
    PHPMailer\PHPMailer\PHPMailer::reset();
    check("an empty body returns false", sendCSM("buyer@example.test", "Buyer", "Hi", "") === false);
    check("an empty body never reaches PHPMailer", PHPMailer\PHPMailer\PHPMailer::$calls === array());

    /* 2. No SMTP configuration means "not sent", not a truthy string. */
    DB::$settings["smtp_status"] = 0;
    PHPMailer\PHPMailer\PHPMailer::reset();
    $off = sendCSM("buyer@example.test", "Buyer", "Hi", "<p>hello</p>");
    check("SMTP off returns boolean false, not a truthy message", $off === false);
    check("SMTP off never reaches PHPMailer", PHPMailer\PHPMailer\PHPMailer::$calls === array());
    DB::$settings["smtp_status"] = 1;

    /* 3. A malformed address is refused before the library throws. */
    PHPMailer\PHPMailer\PHPMailer::reset();
    check("a malformed recipient is refused", sendCSM("not-an-address", "Buyer", "Hi", "<p>x</p>") === false);
    check("a malformed recipient never reaches PHPMailer", PHPMailer\PHPMailer\PHPMailer::$calls === array());

    /* 4. No attachment: addAttachment("") used to throw inside PHPMailer and kill the send. */
    PHPMailer\PHPMailer\PHPMailer::reset();
    $ok = sendCSM("buyer@example.test", "Buyer", "Order", "<p>accounts</p>");
    check("a normal send returns true", $ok === true);
    check("no attachment is added when the path is empty",
        !in_array("addAttachment", PHPMailer\PHPMailer\PHPMailer::names(), true));

    /* 5. A real file is attached; a path that no longer exists is not. */
    $tmp = tempnam(sys_get_temp_dir(), "orbit-mail-");
    file_put_contents($tmp, "account:user:pass\n");
    PHPMailer\PHPMailer\PHPMailer::reset();
    sendCSM("buyer@example.test", "Buyer", "Order", "<p>accounts</p>", "", $tmp);
    $attachment = PHPMailer\PHPMailer\PHPMailer::first("addAttachment");
    check("an existing non-empty file is attached", $attachment !== null && $attachment[1] === $tmp);
    unlink($tmp);

    PHPMailer\PHPMailer\PHPMailer::reset();
    sendCSM("buyer@example.test", "Buyer", "Order", "<p>accounts</p>", "", "/nonexistent/accounts.txt");
    check("a missing attachment does not abort the send",
        PHPMailer\PHPMailer\PHPMailer::first("send") !== null
        && PHPMailer\PHPMailer\PHPMailer::first("addAttachment") === null);

    /* 6. The BCC address is a blind copy, never the sender's display name. */
    PHPMailer\PHPMailer\PHPMailer::reset();
    sendCSM("buyer@example.test", "Buyer", "Campaign", "<p>news</p>", "", "", "archive@example.test");
    $from = PHPMailer\PHPMailer\PHPMailer::first("setFrom");
    $bcc = PHPMailer\PHPMailer\PHPMailer::first("addBCC");
    check("the sender name is the site title, not the BCC address",
        $from !== null && $from[1] === "shop@example.test" && $from[2] === "Orbit Office");
    check("the BCC address is added as a blind copy",
        $bcc !== null && $bcc[1] === "archive@example.test");

    /* 7. An invalid BCC (callers used to pass the site title there) is ignored, not a header. */
    PHPMailer\PHPMailer\PHPMailer::reset();
    sendCSM("buyer@example.test", "Buyer", "Campaign", "<p>news</p>", "", "", "Orbit Office");
    check("a non-address BCC is dropped", PHPMailer\PHPMailer\PHPMailer::first("addBCC") === null);

    /* 8. An SMTP failure is a false return, not an uncaught exception on the checkout page. */
    PHPMailer\PHPMailer\PHPMailer::reset();
    PHPMailer\PHPMailer\PHPMailer::$throwOnSend = true;
    check("an SMTP exception returns false", sendCSM("buyer@example.test", "Buyer", "Hi", "<p>x</p>") === false);

    PHPMailer\PHPMailer\PHPMailer::reset();
    PHPMailer\PHPMailer\PHPMailer::$sendResult = false;
    check("a rejected message returns false", sendCSM("buyer@example.test", "Buyer", "Hi", "<p>x</p>") === false);

    /* 9. An unconfigured sender would throw inside setFrom(). */
    DB::$settings["smtp_email"] = "";
    PHPMailer\PHPMailer\PHPMailer::reset();
    check("a missing sender address is refused", sendCSM("buyer@example.test", "Buyer", "Hi", "<p>x</p>") === false);
    check("a missing sender never reaches PHPMailer", PHPMailer\PHPMailer\PHPMailer::$calls === array());
    DB::$settings["smtp_email"] = "shop@example.test";

    /* 10. The campaign worker records a failure as a failure. */
    $cron = (string) file_get_contents(__DIR__ . "/../cron/sending_email.php");
    check("the campaign worker marks only true as sent",
        strpos($cron, '$status = $response === true ? 1 : 2;') !== false);
    check("the campaign worker passes the campaign BCC in the BCC argument",
        strpos($cron, 'sendCSM($email, $camp["cc"], $title, $content_email, "", "", (string) $camp["bcc"])') !== false);

    echo "\n" . $pass . " passed, " . $fail . " failed.\n";
    exit($fail === 0 ? 0 : 1);
}
