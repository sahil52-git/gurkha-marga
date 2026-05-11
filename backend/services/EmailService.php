<?php
// backend/services/EmailService.php

require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../config/EmailConfig.php';

class Email {

    public static function sendWelcomeEmail(string $name, string $email): bool {
        $subject = "Welcome to Gurkha Marga!";
        $body = "
        <html><body style='font-family:Arial;color:#333;padding:20px;'>
            <h2 style='color:#3b82f6;'>Welcome, {$name} 👋</h2>
            <p>Thank you for joining <strong>Gurkha Marga</strong>.</p>
            <p>Your fitness journey starts now 💪</p>
            <br>
            <p>Best Regards,<br><strong>Gurkha Marga Team</strong></p>
        </body></html>";

        return sendMail($email, $subject, $body);
    }

    /**
     * Called immediately on signup — sends Day 1 quote + sets expectations
     */
    public static function sendSignupMotivationEmail(string $name, string $email, string $forceName): bool {
        $subject = "⚔️ Day 1 — Your Gurkha Marga Warrior Journey Begins";

        $body = <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#05080f;padding:30px 10px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <tr><td style="background:linear-gradient(135deg,#1a0008,#0a0015);border-radius:16px 16px 0 0;
                       padding:32px 36px;text-align:center;border:1px solid rgba(200,16,46,.3);border-bottom:none;">
          <div style="font-size:2.2rem;margin-bottom:8px;">⚔️</div>
          <div style="font-family:'Arial Black',sans-serif;font-size:1.8rem;letter-spacing:.1em;color:#f0c040;">GURKHA MARGA</div>
          <div style="font-size:.72rem;color:#3d4f6e;letter-spacing:.18em;text-transform:uppercase;margin-top:4px;">ELITE FITNESS PLATFORM</div>
          <div style="margin-top:16px;display:inline-block;padding:5px 18px;border-radius:99px;
                      background:rgba(200,16,46,.15);border:1px solid rgba(200,16,46,.3);
                      font-size:.75rem;color:#ff7090;letter-spacing:.1em;font-family:monospace;">
            DAY 1 OF 365 &nbsp;·&nbsp; YOUR JOURNEY STARTS NOW
          </div>
        </td></tr>

        <tr><td style="background:#0a1020;padding:36px 36px 28px;
                       border-left:1px solid rgba(200,16,46,.3);border-right:1px solid rgba(200,16,46,.3);">
          <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;font-family:Georgia,serif;margin-bottom:8px;">"</div>
          <div style="font-family:Georgia,serif;font-size:1.35rem;font-style:italic;font-weight:600;
                      color:#edf2fc;line-height:1.6;border-left:3px solid #c8102e;padding-left:18px;">
            The mountain does not care if you are tired. Neither does the finish line.
          </div>
          <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;font-family:Georgia,serif;text-align:right;margin-top:4px;">"</div>
          <div style="font-family:monospace;font-size:.75rem;color:#8a9bbf;margin-top:8px;letter-spacing:.08em;">
            — Day 1 Gurkha Wisdom · {$forceName}
          </div>
        </td></tr>

        <tr><td style="background:#0a1020;padding:24px 36px;text-align:center;
                       border-left:1px solid rgba(200,16,46,.3);border-right:1px solid rgba(200,16,46,.3);">
          <div style="font-size:.88rem;color:#8a9bbf;margin-bottom:16px;line-height:1.6;">
            Namaste <strong style="color:#edf2fc;">{$name}</strong> — you have joined the path to <strong style="color:#f0c040;">{$forceName}</strong>.<br>
            Every morning at <strong style="color:#c8102e;">5:30 AM</strong>, one quote will arrive in this inbox.<br>
            365 days. One warrior. No excuses.
          </div>
          <a href="http://localhost/gurkha-marga/frontend/users/motivation.php"
             style="display:inline-block;padding:12px 32px;border-radius:8px;
                    background:linear-gradient(135deg,#c8102e,#8b0000);color:#fff;
                    text-decoration:none;font-weight:700;font-size:.9rem;letter-spacing:.08em;">
            🔱 OPEN WARRIOR ZONE
          </a>
        </td></tr>

        <tr><td style="background:#060b14;border-radius:0 0 16px 16px;padding:20px 36px;
                       text-align:center;border:1px solid rgba(255,255,255,.05);border-top:none;">
          <div style="font-size:.72rem;color:#3d4f6e;line-height:1.8;">
            You will receive one motivational quote every morning automatically.<br>
            <a href="http://localhost/gurkha-marga/frontend/users/motivation.php"
               style="color:#8a9bbf;text-decoration:underline;">Manage email preferences</a>
            &nbsp;·&nbsp; Gurkha Marga
          </div>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        return sendMail($email, $subject, $body);
    }
}