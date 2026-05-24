<?php
// backend/cron/send_daily_motivation.php
//
// Sends one motivational quote to every user with email_daily = 1.
// Run this once per day at 05:30.
//
// ── WINDOWS (XAMPP Task Scheduler) ───────────────────────────────────────────
//   Program : C:\xampp\php\php.exe
//   Argument: C:\xampp\htdocs\gurkha-marga\backend\cron\send_daily_motivation.php
//   Schedule: Daily at 05:30
//
// ── LINUX / VPS (crontab -e) ──────────────────────────────────────────────────
//   30 5 * * * /usr/bin/php /var/www/html/gurkha-marga/backend/cron/send_daily_motivation.php >> /var/log/gurkha_motivation.log 2>&1
// ─────────────────────────────────────────────────────────────────────────────

define('BASE_PATH', dirname(__DIR__));   // points to backend/

require_once BASE_PATH . '/database.php';
require_once BASE_PATH . '/services/EmailService.php';   // ← uses EmailConfig internally

// ── Force name map ────────────────────────────────────────────────────────────
$forceNames = [
    'british'   => 'British Army',
    'nepal'     => 'Nepal Army',
    'indian'    => 'Indian Army',
    'singapore' => 'Singapore Police Force',
    'french'    => 'French Foreign Legion',
];

// ── Fetch all users who want daily email ─────────────────────────────────────
$recipients = fetchAll(
    "SELECT u.id, u.full_name, u.email, u.target_force,
            m.streak_days, m.total_visits
     FROM users u
     INNER JOIN user_motivation m ON m.user_id = u.id
     WHERE m.email_daily = 1
       AND u.is_active   = 1
       AND u.email IS NOT NULL
       AND u.email <> ''",
    []
);

if (empty($recipients)) {
    echo '[' . date('Y-m-d H:i:s') . "] No recipients with daily email enabled.\n";
    exit(0);
}

$total = count($recipients);
echo '[' . date('Y-m-d H:i:s') . "] Sending to {$total} recipient(s)...\n";

$sent   = 0;
$failed = 0;

foreach ($recipients as $user) {
    $forceName = $forceNames[strtolower($user['target_force'] ?? 'british')] ?? 'British Army';
    $streak    = (int) $user['streak_days'];

    $ok = Email::sendDailyMotivation(
        $user['full_name'],
        $user['email'],
        $forceName,
        $streak
    );

    $firstName = explode(' ', trim($user['full_name']))[0];

    if ($ok) {
        $sent++;
        echo '[' . date('H:i:s') . "] ✓  {$user['email']} ({$firstName})\n";
    } else {
        $failed++;
        echo '[' . date('H:i:s') . "] ✗  {$user['email']} ({$firstName}) — check error_log\n";
    }

    usleep(350_000);   // 0.35 s between sends — avoids Gmail rate limits
}

echo "\n[" . date('Y-m-d H:i:s') . "] Done.  Sent: {$sent}  |  Failed: {$failed}\n";