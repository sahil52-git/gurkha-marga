<?php

$_autoload = dirname(__DIR__) . '/vendor/autoload.php';   // gurkha-marga/backend/vendor/

if (!file_exists($_autoload)) {
    // Fallback: project root vendor (one level above backend/)
    $_autoload = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
}

if (!file_exists($_autoload)) {
    error_log('[Gurkha Mailer] autoload.php not found. Run: composer require phpmailer/phpmailer');
    // Define a no-op sendMail so the rest of the app doesn't crash
    if (!function_exists('sendMail')) {
        function sendMail(string $to, string $subject, string $html, string $plain = ''): bool {
            error_log("[Gurkha Mailer] sendMail() called but PHPMailer is not installed.");
            return false;
        }
    }
    return;
}

require_once $_autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

// ── SMTP credentials — edit these two lines only ──────────────────────────────
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_USER',      'sahilshrestha741@gmail.com');   // ← YOUR Gmail address
define('SMTP_PASS',      'smzgqflzqdipbgow');    // ← 16-char Gmail App Password
define('SMTP_PORT',      587);
define('SMTP_FROM_NAME', 'Gurkha Marga');
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Send an HTML email via Gmail SMTP / PHPMailer.
 *
 * @param string $toEmail   Recipient address
 * @param string $subject   Subject line
 * @param string $htmlBody  Full HTML body
 * @param string $altBody   Plain-text fallback (auto-stripped if empty)
 * @return bool             true = sent, false = failed (check error_log)
 */
function sendMail(string $toEmail, string $subject, string $htmlBody, string $altBody = ''): bool
{
    // Guard: refuse to attempt if credentials are still placeholder
    if (
        str_contains(SMTP_USER, 'your_gmail') ||
        str_contains(SMTP_PASS, 'xxxx')
    ) {
        error_log('[Gurkha Mailer] sendMail() skipped — SMTP credentials are still placeholder. '
                . 'Edit backend/config/EmailConfig.php with your Gmail + App Password.');
        return false;
    }

    $mail = new PHPMailer(true);  // true = throw exceptions

    try {
        // ── Server ────────────────────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Uncomment to debug SMTP handshake in development:
        // $mail->SMTPDebug  = SMTP::DEBUG_SERVER;

        // ── Sender / recipient ────────────────────────────────────────────
        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->CharSet = 'UTF-8';

        // ── Content ───────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        $mail->send();
        return true;

    } catch (MailerException $e) {
        error_log('[Gurkha Mailer] PHPMailer exception for <' . $toEmail . '>: ' . $mail->ErrorInfo);
        return false;
    } catch (\Throwable $e) {
        error_log('[Gurkha Mailer] Unexpected error for <' . $toEmail . '>: ' . $e->getMessage());
        return false;
    }
}