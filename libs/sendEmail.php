<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
/**
 * Send one HTML email through the configured SMTP account.
 *
 * Returns true only when PHPMailer accepted the message; false for "not configured", a bad address,
 * a missing attachment or an SMTP failure. It used to return the string "Chưa cấu hình SMTP" when
 * SMTP was off, which is truthy, so callers that checked the result logged a delivery that never
 * happened.
 *
 * The fifth argument is the sender's display name (every caller passes the site title). It used to be
 * called $bcc and was passed to setFrom()/addReplyTo() as a *name*, so a campaign's real BCC address
 * was printed as the sender name and the BCC was never sent. BCC is now its own argument at the end.
 *
 * @param string $mail_nhan   recipient address
 * @param string $ten_nhan    recipient display name
 * @param string $chu_de      subject
 * @param string $noi_dung    HTML body
 * @param string $sender_name display name for From/Reply-To; defaults to the site title
 * @param string $path        optional attachment; ignored unless it is a non-empty real file
 * @param string $bcc         optional BCC address
 * @return bool
 */
function sendCSM($mail_nhan, $ten_nhan, $chu_de, $noi_dung, $sender_name = "", $path = "", $bcc = "")
{
    $CMSNT = new DB();
    if($noi_dung == "") {
        return false;
    }
    $mail_nhan = trim((string) $mail_nhan);
    if($mail_nhan === "" || filter_var($mail_nhan, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    if($CMSNT->site("smtp_status") != 1) {
        return false;
    }
    if($sender_name === "") {
        $sender_name = (string) $CMSNT->site("title");
    }
    $from = trim((string) $CMSNT->site("smtp_email"));
    if($from === "" || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer();
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = "html";
        $mail->isSMTP();
        $mail->Host = $CMSNT->site("smtp_host");
        $mail->SMTPAuth = trim((string) $CMSNT->site("smtp_email")) !== "";
        $mail->Username = $CMSNT->site("smtp_email");
        $mail->Password = $CMSNT->site("smtp_password");
        $mail->SMTPSecure = $CMSNT->site("smtp_encryption");
        $mail->Port = (int) $CMSNT->site("smtp_port");
        /* A default PHPMailer timeout is 300 s, which outlives most PHP request limits and leaves the
           customer staring at a spinner long after the page has given up. */
        $mail->Timeout = 20;
        $mail->setFrom($from, $sender_name);
        $mail->addAddress($mail_nhan, $ten_nhan);
        /* addAttachment("") throws inside PHPMailer and takes the whole send down with it. Only a real,
           non-empty file is an attachment. */
        if($path !== "" && is_file($path) && filesize($path) > 0) {
            $mail->addAttachment($path);
        }
        $mail->addReplyTo($from, $sender_name);
        if(trim((string) $bcc) !== "" && filter_var(trim((string) $bcc), FILTER_VALIDATE_EMAIL) !== false) {
            $mail->addBCC(trim((string) $bcc));
        }
        $mail->isHTML(true);
        $mail->Subject = $chu_de;
        $mail->Body = $noi_dung;
        $mail->CharSet = "UTF-8";
        return (bool) $mail->send();
    } catch (\Throwable $e) {
        /* Never let a broken SMTP server turn a checkout or a password reset into an uncaught 500.
           The message is not logged: it can contain the recipient address and the SMTP password in
           debug output. */
        return false;
    }
}
