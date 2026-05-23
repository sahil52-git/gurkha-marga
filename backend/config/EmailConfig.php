<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ─── Autoload ────────────────────────────────────────────────────────────────
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    require_once __DIR__ . '/../../vendor/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/../../vendor/phpmailer/SMTP.php';
    require_once __DIR__ . '/../../vendor/phpmailer/Exception.php';
}

// ─── SMTP Credentials (edit once, used everywhere) ───────────────────────────
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_USER',      'your_email@gmail.com');   // ← your Gmail address
define('SMTP_PASS',      'your_app_password');       // ← your Gmail App Password
define('SMTP_PORT',      587);
define('SMTP_FROM_NAME', 'Gurkha Marga');

// ─── Generic mailer (used by motivation.php, cron jobs, etc.) ────────────────
/**
 * Send any HTML email.
 *
 * @param string $toEmail  Recipient address
 * @param string $subject  Subject line
 * @param string $htmlBody Full HTML body
 * @param string $altBody  Plain-text fallback (auto-generated if empty)
 * @return bool
 */
function sendMail(string $toEmail, string $subject, string $htmlBody, string $altBody = ''): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('sendMail Error: ' . $mail->ErrorInfo);
        return false;
    }
}

// ─── Password reset mailer ────────────────────────────────────────────────────
/**
 * Send password reset email with a secure link.
 *
 * @param string $toEmail   Recipient email
 * @param string $toName    Recipient name
 * @param string $resetLink The full reset URL
 * @return bool
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request – Gurkha Marga';
        $mail->Body    = getResetEmailTemplate($toName, $resetLink);
        $mail->AltBody = "Hello $toName,\n\nReset your password using this link (valid 1 hour):\n$resetLink\n\nIf you didn't request this, ignore this email.\n\nGurkha Marga Team";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

// ─── Password reset HTML template ────────────────────────────────────────────
/**
 * Returns the HTML email template for password reset.
 */
function getResetEmailTemplate(string $name, string $resetLink): string
{
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
    <body style="margin:0;padding:0;background-color:#f0f4f8;font-family:'Segoe UI',Arial,sans-serif;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 0;">
        <tr><td align="center">
          <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

            <!-- Header -->
            <tr>
              <td style="background:linear-gradient(135deg,#1a1a2e,#0f3460);padding:36px 40px;text-align:center;">
                <h1 style="color:#e94560;margin:0;font-size:26px;letter-spacing:2px;">GURKHA MARGA</h1>
                <p style="color:rgba(255,255,255,0.6);margin:6px 0 0;font-size:13px;">Your Path Forward</p>
              </td>
            </tr>

            <!-- Body -->
            <tr>
              <td style="padding:40px;">
                <div style="text-align:center;margin-bottom:28px;">
                  <div style="width:70px;height:70px;background:#fff0f3;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <span style="font-size:32px;">🔐</span>
                  </div>
                  <h2 style="color:#1a1a2e;margin:0;font-size:22px;">Password Reset Request</h2>
                </div>

                <p style="color:#4a5568;font-size:15px;line-height:1.7;margin-bottom:12px;">Hi <strong>$name</strong>,</p>
                <p style="color:#4a5568;font-size:15px;line-height:1.7;margin-bottom:28px;">
                  We received a request to reset your Gurkha Marga account password. Click the button below to create a new password. This link is valid for <strong>1 hour</strong>.
                </p>

                <!-- CTA Button -->
                <div style="text-align:center;margin:32px 0;">
                  <a href="$resetLink" style="display:inline-block;background:linear-gradient(135deg,#e94560,#c73652);color:#ffffff;text-decoration:none;padding:16px 36px;border-radius:10px;font-size:16px;font-weight:600;letter-spacing:0.5px;">
                    Reset My Password
                  </a>
                </div>

                <p style="color:#718096;font-size:13px;line-height:1.6;margin-bottom:8px;">
                  If the button doesn't work, copy and paste this link into your browser:
                </p>
                <p style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:12px;color:#4a5568;word-break:break-all;margin-bottom:24px;">$resetLink</p>

                <div style="background:#fff8f0;border-left:4px solid #e94560;border-radius:4px;padding:14px 16px;margin-bottom:20px;">
                  <p style="color:#744210;font-size:13px;margin:0;">
                    ⚠️ If you didn't request a password reset, please ignore this email. Your password won't change until you click the link above.
                  </p>
                </div>
              </td>
            </tr>

            <!-- Footer -->
            <tr>
              <td style="background:#f7fafc;padding:24px 40px;text-align:center;border-top:1px solid #e2e8f0;">
                <p style="color:#a0aec0;font-size:12px;margin:0;">© 2024 Gurkha Marga. All rights reserved.</p>
                <p style="color:#a0aec0;font-size:12px;margin:6px 0 0;">This is an automated email — please do not reply.</p>
              </td>
            </tr>

          </table>
        </td></tr>
      </table>
    </body>
    </html>
    HTML;
}