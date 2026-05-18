<?php
// backend/services/EmailService.php

require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../config/EmailConfig.php';

class Email {

    // ─────────────────────────────────────────────────────────────────────
    // Simple welcome email sent on account creation
    // ─────────────────────────────────────────────────────────────────────
    public static function sendWelcomeEmail(string $name, string $email): bool {

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $subject  = "Welcome to Gurkha Marga!";

        $body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#05080f;padding:30px 10px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="max-width:600px;width:100%;background:#0a1020;border-radius:16px;
                    border:1px solid rgba(59,130,246,.2);overflow:hidden;">
        <tr>
          <td style="background:linear-gradient(135deg,#1a0008,#0a0015);padding:32px 36px;
                     text-align:center;border-bottom:1px solid rgba(200,16,46,.3);">
            <div style="font-size:2rem;margin-bottom:8px;">⚔️</div>
            <div style="font-size:1.6rem;font-weight:800;letter-spacing:.08em;color:#f0c040;">
              GURKHA MARGA
            </div>
            <div style="font-size:.7rem;color:#3d4f6e;letter-spacing:.18em;
                        text-transform:uppercase;margin-top:4px;">ELITE FITNESS PLATFORM</div>
          </td>
        </tr>
        <tr>
          <td style="padding:36px;">
            <h2 style="color:#3b82f6;margin:0 0 16px;">Welcome, {$safeName} 👋</h2>
            <p style="color:#cbd5e1;line-height:1.7;margin:0 0 12px;">
              Thank you for joining <strong style="color:#f0c040;">Gurkha Marga</strong>.
            </p>
            <p style="color:#cbd5e1;line-height:1.7;margin:0 0 24px;">
              Your fitness journey starts now 💪<br>
              Check your inbox — your Day 1 warrior quote is on its way.
            </p>
            <a href="http://localhost/gurkha-marga/frontend/auth/login.php"
               style="display:inline-block;padding:12px 28px;border-radius:8px;
                      background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;
                      text-decoration:none;font-weight:700;font-size:.9rem;">
              Login to Your Account →
            </a>
          </td>
        </tr>
        <tr>
          <td style="background:#060b14;padding:20px 36px;text-align:center;
                     border-top:1px solid rgba(255,255,255,.05);">
            <p style="font-size:.72rem;color:#3d4f6e;margin:0;">
              Gurkha Marga &copy; <?= date('Y') ?>
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $altBody = "Welcome, {$name}!\n\nThank you for joining Gurkha Marga. Your fitness journey starts now.\n\n— Gurkha Marga Team";

        return sendMail($email, $subject, $body, $altBody);
    }


    // ─────────────────────────────────────────────────────────────────────
    // Day 1 motivation email — sent immediately on signup
    // ─────────────────────────────────────────────────────────────────────
    public static function sendSignupMotivationEmail(
        string $name,
        string $email,
        string $forceName
    ): bool {

        $safeName  = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
        $safeForce = htmlspecialchars($forceName, ENT_QUOTES, 'UTF-8');
        $subject   = "⚔️ Day 1 — Your Gurkha Marga Warrior Journey Begins";

        $body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#05080f;padding:30px 10px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="max-width:600px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a0008,#0a0015);
                     border-radius:16px 16px 0 0;padding:32px 36px;text-align:center;
                     border:1px solid rgba(200,16,46,.3);border-bottom:none;">
            <div style="font-size:2.2rem;margin-bottom:8px;">⚔️</div>
            <div style="font-size:1.8rem;font-weight:800;letter-spacing:.1em;color:#f0c040;">
              GURKHA MARGA
            </div>
            <div style="font-size:.72rem;color:#3d4f6e;letter-spacing:.18em;
                        text-transform:uppercase;margin-top:4px;">ELITE FITNESS PLATFORM</div>
            <div style="margin-top:16px;display:inline-block;padding:5px 18px;
                        border-radius:99px;background:rgba(200,16,46,.15);
                        border:1px solid rgba(200,16,46,.3);
                        font-size:.75rem;color:#ff7090;letter-spacing:.1em;font-family:monospace;">
              DAY 1 OF 365 &nbsp;·&nbsp; YOUR JOURNEY STARTS NOW
            </div>
          </td>
        </tr>

        <!-- Quote block -->
        <tr>
          <td style="background:#0a1020;padding:36px 36px 28px;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                        font-family:Georgia,serif;margin-bottom:8px;">"</div>
            <div style="font-family:Georgia,serif;font-size:1.35rem;font-style:italic;
                        font-weight:600;color:#edf2fc;line-height:1.6;
                        border-left:3px solid #c8102e;padding-left:18px;">
              The mountain does not care if you are tired. Neither does the finish line.
            </div>
            <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                        font-family:Georgia,serif;text-align:right;margin-top:4px;">"</div>
            <div style="font-family:monospace;font-size:.75rem;color:#8a9bbf;
                        margin-top:8px;letter-spacing:.08em;">
              — Day 1 Gurkha Wisdom &nbsp;·&nbsp; {$safeForce}
            </div>
          </td>
        </tr>

        <!-- Body / CTA -->
        <tr>
          <td style="background:#0a1020;padding:24px 36px;text-align:center;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="font-size:.88rem;color:#8a9bbf;margin-bottom:16px;line-height:1.7;">
              Namaste <strong style="color:#edf2fc;">{$safeName}</strong> — you have joined
              the path to <strong style="color:#f0c040;">{$safeForce}</strong>.<br>
              Every morning, one quote will arrive in this inbox.<br>
              <strong style="color:#c8102e;">365 days. One warrior. No excuses.</strong>
            </div>
            <a href="http://localhost/gurkha-marga/frontend/users/motivation.php"
               style="display:inline-block;padding:12px 32px;border-radius:8px;
                      background:linear-gradient(135deg,#c8102e,#8b0000);color:#fff;
                      text-decoration:none;font-weight:700;font-size:.9rem;
                      letter-spacing:.08em;">
              🔱 OPEN WARRIOR ZONE
            </a>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#060b14;border-radius:0 0 16px 16px;padding:20px 36px;
                     text-align:center;border:1px solid rgba(255,255,255,.05);border-top:none;">
            <div style="font-size:.72rem;color:#3d4f6e;line-height:1.8;">
              You will receive one motivational quote every morning automatically.<br>
              <a href="http://localhost/gurkha-marga/frontend/users/motivation.php"
                 style="color:#8a9bbf;text-decoration:underline;">Manage email preferences</a>
              &nbsp;·&nbsp; Gurkha Marga &copy; <?= date('Y') ?>
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $altBody = "Namaste {$name},\n\n"
            . "\"The mountain does not care if you are tired. Neither does the finish line.\"\n\n"
            . "You have joined the path to {$forceName}.\n"
            . "365 days. One warrior. No excuses.\n\n"
            . "— Gurkha Marga Team";

        return sendMail($email, $subject, $body, $altBody);
    }
}