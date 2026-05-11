<?php
// backend/mailer_cron.php
// Run via cron: php /path/to/backend/mailer_cron.php
// Recommended: once daily — 0 8 * * * php /var/www/backend/mailer_cron.php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/subscription_helper.php';
require_once __DIR__ . '/config/EmailConfig.php';

// ── 1. Expire old subscriptions ────────────────────────────────────────────
$expired = expireOldSubscriptions();
echo "[" . date('Y-m-d H:i:s') . "] Marked {$expired} subscriptions as expired.\n";

// ── 2. Fetch newly expired (status flipped today) — send expired email ─────
$justExpired = fetchAll(
    "SELECT s.*, u.full_name, u.email
     FROM subscriptions s
     JOIN users u ON u.id = s.user_id
     WHERE s.status='expired'
       AND DATE(s.updated_at) = CURDATE()
       AND NOT EXISTS (
           SELECT 1 FROM email_notifications en
           WHERE en.user_id=s.user_id AND en.type='subscription_expired'
             AND DATE(en.sent_at)=CURDATE()
       )"
);

foreach ($justExpired as $row) {
    $subject = 'Your Gurkha Marga Subscription Has Ended';
    $body    = buildExpiredEmail($row['full_name']);
    $ok      = sendMail($row['email'], $subject, $body);
    insert(
        "INSERT INTO email_notifications (user_id, type, success) VALUES (?,?,?)",
        [$row['user_id'], 'subscription_expired', (int)$ok]
    );
    echo "  Expired email → {$row['email']} " . ($ok ? 'OK' : 'FAILED') . "\n";
}

// ── 3. Send 5-day reminder to those expiring soon ──────────────────────────
$expiringSoon = getExpiringSoon(5);
foreach ($expiringSoon as $row) {
    $days    = (int) ceil((strtotime($row['expires_at']) - time()) / 86400);
    $subject = "Your Gurkha Marga subscription expires in {$days} day(s)";
    $body    = buildReminderEmail($row['full_name'], $days, $row['expires_at']);
    $ok      = sendMail($row['email'], $subject, $body);
    markReminderSent($row['id']);
    insert(
        "INSERT INTO email_notifications (user_id, type, success) VALUES (?,?,?)",
        [$row['user_id'], 'subscription_expiry_5d', (int)$ok]
    );
    echo "  Reminder email → {$row['email']} ({$days}d left) " . ($ok ? 'OK' : 'FAILED') . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cron complete.\n";

// ── Email templates ────────────────────────────────────────────────────────

function buildReminderEmail(string $name, int $days, string $expiry): string {
    $expStr = date('d M Y', strtotime($expiry));
    return <<<HTML
    <div style="font-family:Inter,sans-serif;max-width:560px;margin:0 auto;background:#070c16;color:#eef2f9;border-radius:16px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#2e7cf6,#7c3aed);padding:2rem;text-align:center">
        <div style="font-size:2rem">⚔️</div>
        <h1 style="margin:.5rem 0 0;font-size:1.3rem;font-weight:700;color:#fff">Gurkha Marga</h1>
      </div>
      <div style="padding:2rem">
        <p style="font-size:1rem;margin-bottom:1rem">Hi <strong>{$name}</strong>,</p>
        <p>Your premium subscription expires in <strong style="color:#f5c842">{$days} day(s)</strong> on <strong>{$expStr}</strong>.</p>
        <p>Renew now to keep access to your consultant, diet plans, and premium Q&A.</p>
        <div style="text-align:center;margin:1.5rem 0">
          <a href="https://gurkhamarga.com/frontend/users/subscription.php"
             style="background:linear-gradient(135deg,#f5c842,#c99a10);color:#0a0800;padding:.85rem 2rem;border-radius:10px;font-weight:700;text-decoration:none;font-size:.95rem">
            Renew Subscription →
          </a>
        </div>
        <p style="font-size:.8rem;color:#8a9bbf">If you have questions, reply to this email or message your consultant directly.</p>
      </div>
      <div style="background:#0d1526;padding:1rem;text-align:center;font-size:.75rem;color:#4a5b7a">
        © Gurkha Marga · Elite Fitness Platform
      </div>
    </div>
    HTML;
}

function buildExpiredEmail(string $name): string {
    return <<<HTML
    <div style="font-family:Inter,sans-serif;max-width:560px;margin:0 auto;background:#070c16;color:#eef2f9;border-radius:16px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#e84040,#7c3aed);padding:2rem;text-align:center">
        <div style="font-size:2rem">🔒</div>
        <h1 style="margin:.5rem 0 0;font-size:1.3rem;font-weight:700;color:#fff">Subscription Ended</h1>
      </div>
      <div style="padding:2rem">
        <p>Hi <strong>{$name}</strong>,</p>
        <p>Your Gurkha Marga premium subscription has <strong style="color:#e84040">expired</strong>.</p>
        <p>You've lost access to premium Q&A, consultant chat, and personalised diet plans. Renew to get back on track for your army selection.</p>
        <div style="text-align:center;margin:1.5rem 0">
          <a href="https://gurkhamarga.com/frontend/users/subscription.php"
             style="background:linear-gradient(135deg,#f5c842,#c99a10);color:#0a0800;padding:.85rem 2rem;border-radius:10px;font-weight:700;text-decoration:none;font-size:.95rem">
            Renew Now →
          </a>
        </div>
      </div>
      <div style="background:#0d1526;padding:1rem;text-align:center;font-size:.75rem;color:#4a5b7a">
        © Gurkha Marga · Elite Fitness Platform
      </div>
    </div>
    HTML;
}