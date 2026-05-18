<?php
// backend/cron/send_daily_motivation.php
//
// PURPOSE : Send one motivational quote email to every user
//           who has email_daily = 1 in user_motivation.
//
// WINDOWS TASK SCHEDULER (XAMPP):
//   Program : C:\xampp\php\php.exe
//   Argument: C:\xampp\htdocs\gurkha-marga\backend\cron\send_daily_motivation.php
//   Time    : 05:30 daily
//
// LINUX CRON (VPS/production):
//   30 5 * * * php /var/www/html/gurkha-marga/backend/cron/send_daily_motivation.php >> /var/log/gurkha_motivation.log 2>&1

// ── Bootstrap ─────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(dirname(__FILE__))); // points to /backend

require_once BASE_PATH . '/database.php';
require_once BASE_PATH . '/config/Config.php';
require_once BASE_PATH . '/config/EmailConfig.php';

// ── Site base URL (used in email links) ───────────────────────────────────
// Change this to your live domain when you deploy.
// For local XAMPP leave as-is.
define('SITE_URL', 'http://localhost/gurkha-marga');

// ── 365 motivational quotes (index 0 = Jan 1, matches PHP date('z')) ──────
$QUOTES = [
    ["The mountain does not care if you are tired. Neither does the finish line.",                       "Gurkha Proverb"],
    ["A Gurkha does not ask if the task is possible. He asks only when it must be done.",               "Brigade of Gurkhas"],
    ["Discipline today is the freedom you earn tomorrow.",                                               "Military Maxim"],
    ["Your competition is not another man. Your competition is who you were yesterday.",                 "Warrior Philosophy"],
    ["Pain is temporary. Passing selection is permanent.",                                               "Selection Camp Wisdom"],
    ["Every sunrise is a battle order. Will you obey it?",                                              "Morning Creed"],
    ["Iron sharpens iron. Train harder than the standard demands.",                                      "Proverbs 27:17"],
    ["A warrior is not defined by how many times he stands. He is defined by how many times he rises.", "Ancient Martial Code"],
    ["The uniform is earned on the training ground, not on selection day.",                              "Gurkha Instructor"],
    ["Sweat now. Bleed never.",                                                                          "Military Training Doctrine"],
    ["The man who rises at 5 AM has already won half the battle.",                                       "Sergeant Major's Wisdom"],
    ["Fear is a liar. Your body can do far more than your mind believes.",                               "Special Forces Manual"],
    ["You do not get what you wish for. You get what you work for.",                                     "Training Ground Truth"],
    ["The standards do not lower for anyone. You must rise to meet them.",                               "British Army Tradition"],
    ["Ayo Gorkhali — and with that cry, mountains have trembled.",                                       "Gurkha Battle Cry"],
    ["Champions are made in the quiet sessions no one sees.",                                            "Strength Coach Maxim"],
    ["Run when you are tired. That is when training begins.",                                            "Endurance Coach"],
    ["You are not tired. You are uncomfortable. There is a difference.",                                 "SAS Selection Instructor"],
    ["The enemy you will face has also been training. Train harder.",                                    "Intelligence Briefing"],
    ["A Gurkha's word is his contract. Your commitment today is your contract.",                         "Gurkha Officers Mess"],
    ["Do not pray for easy battles. Pray to be a stronger fighter.",                                     "West Point Cadet Prayer"],
    ["Your ancestors ran barefoot through mountains. You have no excuse.",                               "Nepali Hill Training Ethos"],
    ["The mind breaks first. Train it before you train the body.",                                       "Combat Psychology"],
    ["Run the route when it is raining. You will never fear rain again.",                                "All-Weather Training"],
    ["Hard training, easy battle. Easy training, hard battle.",                                          "Suvorov's Maxim"],
    ["What you do in the darkness will be revealed in the light of selection day.",                      "Training Principle"],
    ["Character is who you are when no one is watching.",                                                "John Wooden"],
    ["You are not behind. You are exactly where your effort has placed you.",                            "Honest Reckoning"],
    ["A soldier's greatest enemy is not the enemy. It is complacency.",                                  "Military Philosophy"],
    ["The standard you walk past is the standard you accept.",                                           "Australian Army Chief"],
    ["Be the soldier who does the extra mile when no one assigns it.",                                   "Initiative Doctrine"],
    ["The only way to get fitter is to show up, every day.",                                             "Training Axiom"],
    ["A bad day of training is infinitely better than no day of training.",                              "Minimum Effective Dose"],
    ["Your future self is watching your choices today. Make them proud.",                                "Time Perspective"],
    ["Silence your doubts the only way that works — with action.",                                       "Action Over Anxiety"],
    ["The difference between a soldier and a civilian is the willingness to suffer on purpose.",         "Endurance Philosophy"],
    ["When your legs say stop, your history says continue.",                                             "Legacy Motivation"],
    ["You have survived 100% of your hardest days so far.",                                              "Resilience Reminder"],
    ["A man who masters himself can master any terrain.",                                                "Sun Tzu"],
    ["Your pace in training sets the ceiling for your pace in selection.",                               "Specificity Principle"],
    ["Rise before the city wakes. Own the morning. Own the day.",                                        "Early Rise Creed"],
    ["Strength does not shout. It endures quietly and arrives on time.",                                 "The Silent Warrior"],
    ["Do not count the miles you have run. Count the days you did not stop.",                            "Consistency Over Distance"],
    ["Commitment is doing what you said long after the mood that inspired it has left.",                 "Darren Hardy"],
    ["Your selection day is a single day. Your preparation is every other day.",                         "Preparation Arithmetic"],
    ["The best preparation for tomorrow is the complete execution of today.",                            "Daily Excellence"],
    ["A warrior eats to fuel, not to comfort.",                                                          "Nutritional Purpose"],
    ["Do not let a good day soften a great week. Maintain.",                                             "Complacency Warning"],
    ["Discipline is remembering what you want most, over what you want now.",                            "Discipline Definition"],
    ["Train until you cannot get it wrong.",                                                             "Mastery Standard"],
    ["Your body will give what your mind insists upon.",                                                 "Mind Command"],
    ["The last month of preparation is the most important month. Treat it accordingly.",                 "Final Month Gravity"],
    ["What you believe about yourself under pressure is what you will perform.",                         "Belief Performance Link"],
    ["The only question selection asks is: are you ready? Answer with your training record.",            "Training as Answer"],
    ["365 days. A warrior's year. Now begin again — stronger, wiser, and already ahead.",               "Year Complete"],
    // Indices 55-364 cycle through the above — the modulo below handles this.
];

// ── Helpers ────────────────────────────────────────────────────────────────
$forceNames = [
    'british'   => 'British Army',
    'nepal'     => 'Nepal Army',
    'indian'    => 'Indian Army',
    'singapore' => 'Singapore Police Force',
    'french'    => 'French Foreign Legion',
];

$dayOfYear  = (int) date('z');                          // 0–364
$totalQuotes = count($QUOTES);
$quote      = $QUOTES[$dayOfYear % $totalQuotes];       // cycles if < 365 entries
$quoteText  = $quote[0];
$quoteSource = $quote[1];
$dateLabel  = date('l, d F Y');
$dayLabel   = 'Day ' . ($dayOfYear + 1) . ' of 365';
$progressPct = min(100, (int) round(($dayOfYear + 1) / 365 * 100));

// ── Fetch recipients ───────────────────────────────────────────────────────
$recipients = fetchAll(
    "SELECT u.id, u.full_name, u.email, u.target_force,
            m.streak_days, m.total_visits
     FROM users u
     INNER JOIN user_motivation m ON m.user_id = u.id
     WHERE m.email_daily = 1
       AND u.is_active   = 1
       AND u.email IS NOT NULL
       AND u.email != ''",
    []
);

if (empty($recipients)) {
    echo "[" . date('Y-m-d H:i:s') . "] No recipients with daily email enabled.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Sending to " . count($recipients) . " recipient(s)...\n";

$sent   = 0;
$failed = 0;

foreach ($recipients as $user) {
    $firstName = explode(' ', trim($user['full_name']))[0];
    $forceName = $forceNames[strtolower($user['target_force'] ?? 'british')] ?? 'British Army';
    $streak    = (int) $user['streak_days'];

    // Streak message based on milestone
    if ($streak >= 365) {
        $streakMsg = "🏆 {$streak}-day streak — a full warrior year.";
    } elseif ($streak >= 200) {
        $streakMsg = "🏆 {$streak}-day streak — iron mind achieved.";
    } elseif ($streak >= 100) {
        $streakMsg = "🏆 {$streak}-day streak — you are a century soldier.";
    } elseif ($streak >= 66) {
        $streakMsg = "🔥 {$streak}-day streak — habit fully automatic.";
    } elseif ($streak >= 30) {
        $streakMsg = "🔥 {$streak}-day streak — habit fully formed.";
    } elseif ($streak >= 7) {
        $streakMsg = "🔥 {$streak}-day streak — one week strong.";
    } else {
        $streakMsg = "🔥 {$streak}-day streak — keep going.";
    }

    $subject = "⚔️ {$dayLabel} — Your Gurkha Marga Warrior Quote";
    $body    = buildMotivationEmail(
        $firstName, $forceName, $quoteText, $quoteSource,
        $dayLabel, $dateLabel, $streakMsg, $progressPct
    );
    $altBody = buildMotivationAltBody(
        $firstName, $forceName, $quoteText, $streakMsg, $dayLabel
    );

    $ok = sendMail($user['email'], $subject, $body, $altBody);

    if ($ok) {
        $sent++;
        echo "[" . date('H:i:s') . "] ✓ Sent   → {$user['email']} ({$firstName})\n";
    } else {
        $failed++;
        echo "[" . date('H:i:s') . "] ✗ Failed → {$user['email']} ({$firstName})\n";
    }

    usleep(350000); // 0.35 s between sends — avoids Gmail rate limits
}

echo "\n[" . date('Y-m-d H:i:s') . "] Done. Sent: {$sent} | Failed: {$failed}\n";

// ═══════════════════════════════════════════════════════════════════════════
// EMAIL BUILDERS
// ═══════════════════════════════════════════════════════════════════════════

function buildMotivationEmail(
    string $name,
    string $force,
    string $quoteText,
    string $quoteSource,
    string $dayLabel,
    string $dateLabel,
    string $streakMsg,
    int    $progressPct
): string {

    $safeName      = htmlspecialchars($name,        ENT_QUOTES, 'UTF-8');
    $safeForce     = htmlspecialchars($force,       ENT_QUOTES, 'UTF-8');
    $safeQuote     = htmlspecialchars($quoteText,   ENT_QUOTES, 'UTF-8');
    $safeSource    = htmlspecialchars($quoteSource, ENT_QUOTES, 'UTF-8');
    $safeDayLabel  = htmlspecialchars($dayLabel,    ENT_QUOTES, 'UTF-8');
    $safeDateLabel = htmlspecialchars($dateLabel,   ENT_QUOTES, 'UTF-8');
    $safeStreak    = htmlspecialchars($streakMsg,   ENT_QUOTES, 'UTF-8');

    $progressBar  = str_repeat('█', (int)($progressPct / 5))
                  . str_repeat('░', 20 - (int)($progressPct / 5));
    $siteUrl      = SITE_URL;
    $year         = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Gurkha Marga Daily Motivation</title>
</head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0"
         style="background:#05080f;padding:30px 10px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="max-width:600px;width:100%;">

        <!-- ── HEADER ── -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a0008,#0a0015);
                     border-radius:16px 16px 0 0;padding:32px 36px;text-align:center;
                     border:1px solid rgba(200,16,46,.3);border-bottom:none;">
            <div style="font-size:2.2rem;margin-bottom:8px;">⚔️</div>
            <div style="font-size:1.8rem;font-weight:800;letter-spacing:.1em;color:#f0c040;">
              GURKHA MARGA
            </div>
            <div style="font-size:.72rem;color:#3d4f6e;letter-spacing:.18em;
                        text-transform:uppercase;margin-top:4px;">
              ELITE FITNESS PLATFORM
            </div>
            <div style="margin-top:16px;display:inline-block;padding:5px 18px;
                        border-radius:99px;background:rgba(200,16,46,.15);
                        border:1px solid rgba(200,16,46,.3);
                        font-size:.75rem;color:#ff7090;letter-spacing:.1em;
                        font-family:monospace;">
              {$safeDayLabel} &nbsp;·&nbsp; {$safeDateLabel}
            </div>
          </td>
        </tr>

        <!-- ── QUOTE ── -->
        <tr>
          <td style="background:#0a1020;padding:36px 36px 28px;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                        font-family:Georgia,serif;margin-bottom:8px;">"</div>
            <div style="font-family:Georgia,'Times New Roman',serif;font-size:1.25rem;
                        font-style:italic;font-weight:600;color:#edf2fc;line-height:1.7;
                        border-left:3px solid #c8102e;padding-left:18px;">
              {$safeQuote}
            </div>
            <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                        font-family:Georgia,serif;text-align:right;margin-top:4px;">"</div>
            <div style="font-family:monospace;font-size:.75rem;color:#8a9bbf;
                        margin-top:10px;letter-spacing:.08em;">
              — {$safeSource} &nbsp;·&nbsp; {$safeForce}
            </div>
          </td>
        </tr>

        <!-- ── STREAK + PROGRESS ── -->
        <tr>
          <td style="background:#0c1525;padding:20px 36px;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <!-- Streak -->
                <td style="width:50%;padding-right:10px;vertical-align:top;">
                  <div style="background:#0a1020;border-radius:10px;padding:14px 16px;
                              border:1px solid rgba(240,192,64,.15);text-align:center;">
                    <div style="font-size:1.4rem;margin-bottom:4px;">🔥</div>
                    <div style="font-size:.82rem;font-weight:700;color:#f0c040;
                                line-height:1.4;">{$safeStreak}</div>
                  </div>
                </td>
                <!-- Year progress -->
                <td style="width:50%;padding-left:10px;vertical-align:top;">
                  <div style="background:#0a1020;border-radius:10px;padding:14px 16px;
                              border:1px solid rgba(46,124,246,.15);text-align:center;">
                    <div style="font-size:.65rem;color:#3d4f6e;letter-spacing:.1em;
                                text-transform:uppercase;font-family:monospace;
                                margin-bottom:6px;">YEAR PROGRESS</div>
                    <div style="font-family:monospace;font-size:.65rem;color:#2e7cf6;
                                letter-spacing:.03em;word-break:break-all;">
                      {$progressBar}
                    </div>
                    <div style="font-family:monospace;font-size:.75rem;color:#6ab4ff;
                                margin-top:4px;">{$progressPct}% of 365 days</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- ── CTA ── -->
        <tr>
          <td style="background:#0a1020;padding:28px 36px;text-align:center;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="font-size:.88rem;color:#8a9bbf;margin-bottom:18px;line-height:1.7;">
              Namaste <strong style="color:#edf2fc;">{$safeName}</strong> —
              your daily warrior briefing has arrived.<br>
              Read it. Carry it. Train accordingly.
            </div>
            <a href="{$siteUrl}/frontend/users/motivation.php"
               style="display:inline-block;padding:13px 34px;border-radius:8px;
                      background:linear-gradient(135deg,#c8102e,#8b0000);color:#fff;
                      text-decoration:none;font-weight:700;font-size:.9rem;
                      letter-spacing:.08em;">
              🔱 OPEN WARRIOR ZONE
            </a>
          </td>
        </tr>

        <!-- ── FOOTER ── -->
        <tr>
          <td style="background:#060b14;border-radius:0 0 16px 16px;
                     padding:18px 36px;text-align:center;
                     border:1px solid rgba(255,255,255,.05);border-top:none;">
            <div style="font-size:.72rem;color:#3d4f6e;line-height:1.9;">
              You receive this because daily emails are enabled on your account.<br>
              <a href="{$siteUrl}/frontend/users/motivation.php"
                 style="color:#8a9bbf;text-decoration:underline;">
                Manage email preferences
              </a>
              &nbsp;·&nbsp; Gurkha Marga &copy; {$year}
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>
HTML;
}

// Plain-text fallback (required by RFC 2822 / spam filters)
function buildMotivationAltBody(
    string $name,
    string $force,
    string $quoteText,
    string $streakMsg,
    string $dayLabel
): string {
    $siteUrl = SITE_URL;
    return
        "GURKHA MARGA — ELITE FITNESS PLATFORM\n"
      . str_repeat('-', 40) . "\n"
      . "{$dayLabel}\n\n"
      . "Namaste {$name},\n\n"
      . "\"{$quoteText}\"\n\n"
      . "— {$force}\n\n"
      . str_repeat('-', 40) . "\n"
      . "{$streakMsg}\n\n"
      . "Open your warrior zone: {$siteUrl}/frontend/users/motivation.php\n\n"
      . "To manage email preferences visit the link above.\n"
      . "Gurkha Marga © " . date('Y');
}