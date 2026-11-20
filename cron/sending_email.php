<?php

define("IN_SITE", true);
require_once __DIR__ . "/../libs/db.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../libs/helper.php";
require_once __DIR__ . "/../libs/lang.php";
require_once __DIR__ . "/../libs/sendEmail.php";
$CMSNT = new DB();
if (!orbit_cron_allowed()) {
    http_response_code(403);
    exit("cron_forbidden");
}
if($CMSNT->site("check_time_cron_sending_email") < time() && time() - $CMSNT->site("check_time_cron_sending_email") < 3) {
    exit("Thao tác quá nhanh, vui lòng đợi");
}
$CMSNT->update("settings", ["value" => time()], " `name` = 'check_time_cron_sending_email' ");
if($CMSNT->site("smtp_status") != 1) {
    exit("Vui lòng cấu hình SMTP");
}
if($CMSNT->site("smtp_email") == "" || $CMSNT->site("smtp_password") == "") {
    exit("Vui lòng cấu hình SMTP");
}
$checkAddon = true;
/*
 * The template is a local file. It used to be fetched over HTTP from this same server with TLS
 * verification disabled — for every recipient — so an unreachable public URL (Docker, a proxy,
 * maintenance mode) rendered an empty e-mail, and N messages paid N round trips to ourselves.
 * Rendering it once, in-process, keeps the same output and cannot fail for network reasons.
 */
$emailTemplate = "";
if (is_file(__DIR__ . "/../libs/mails/notification.php")) {
    $emailTemplate = (function () {
        ob_start();
        include __DIR__ . "/../libs/mails/notification.php";
        return (string) ob_get_clean();
    })();
}
foreach ($CMSNT->get_list(" SELECT * FROM `email_campaigns` WHERE `status` = 0 ") as $camp) {
    /* One join instead of a users SELECT per queued message. */
        foreach ($CMSNT->get_list(" SELECT s.*, u.`email` AS `recipient_email` FROM `email_sending` s LEFT JOIN `users` u ON u.`id` = s.`user_id` WHERE s.`camp_id` = '" . $camp["id"] . "' AND s.`status` = 0 ORDER BY s.`id` ASC LIMIT 20 ") as $row) {
        $content = $camp["content"];
        $title = $camp["subject"];
        $content_email = str_replace("{title}", $title, $emailTemplate);
        $content_email = str_replace("{content}", $content, $content_email);
        $email = isset($row["recipient_email"]) ? (string) $row["recipient_email"] : "";
        $response = "Vui lòng kích hoạt Addon này";
        $status = 2;
        if($email == "") {
            $response = "Không tìm thấy Email người nhận";
            $status = 2;
        } else {
            $response = sendCSM($email, $camp["cc"], $title, $content_email, $camp["bcc"]);
            $status = 1;
        }
        $CMSNT->update("email_sending", ["status" => $status, "update_gettime" => gettime(), "response" => $response], " `id` = '" . $row["id"] . "' ");
    }
    if(!$CMSNT->get_row(" SELECT * FROM `email_sending` WHERE `camp_id` = '" . $camp["id"] . "' AND `status` = 0  ")) {
        $CMSNT->update("email_campaigns", ["status" => 1, "update_gettime" => gettime()], " `id` = '" . $camp["id"] . "' ");
    }
}

?>