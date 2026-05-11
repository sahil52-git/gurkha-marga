<?php
// backend/config/EmailConfig.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendMail(string $to, string $subject, string $body): bool {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = Config::get('MAIL_USERNAME');
        $mail->Password   = Config::get('MAIL_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(Config::get('MAIL_USERNAME'), 'Gurkha Marga');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Email Error: ' . $mail->ErrorInfo);
        return false;
    }
}