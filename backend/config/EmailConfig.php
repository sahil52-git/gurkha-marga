<?php
// backend/config/EmailConfig.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendMail(string $to, string $subject, string $body, string $altBody = ''): bool {

    // ── Guard: refuse to send if config is missing ────────────────────────
    $username = Config::get('MAIL_USERNAME');
    $password = Config::get('MAIL_PASSWORD');

    if (empty($username) || empty($password)) {
        error_log('EmailConfig: MAIL_USERNAME or MAIL_PASSWORD is not set in config.');
        return false;
    }

    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('EmailConfig: Invalid recipient address: ' . $to);
        return false;
    }

    $mail = new PHPMailer(true); // true = throw exceptions

    try {
        // ── SMTP settings ─────────────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host        = 'smtp.gmail.com';
        $mail->SMTPAuth    = true;
        $mail->Username    = $username;
        $mail->Password    = $password;
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;

        // ── Timeout (seconds) — prevents silent hangs on cron ─────────────
        $mail->Timeout     = 15;

        // ── Encoding — critical for HTML emails via Gmail SMTP ────────────
        $mail->CharSet     = PHPMailer::CHARSET_UTF8;   // 'UTF-8'
        $mail->Encoding    = 'base64';

        // ── Sender / recipient ────────────────────────────────────────────
        $mail->setFrom($username, 'Gurkha Marga');
        $mail->addAddress($to);

        // ── Content ───────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Plain-text fallback — required by many mail servers; without this
        // some providers (Outlook, corporate filters) reject the message.
        $mail->AltBody = !empty($altBody)
            ? $altBody
            : strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>', '</div>'], "\n", $body));

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log both the PHPMailer internal error and the exception message
        error_log('EmailConfig sendMail() failed to [' . $to . ']: ' . $mail->ErrorInfo);
        error_log('EmailConfig Exception: ' . $e->getMessage());
        return false;
    }
}