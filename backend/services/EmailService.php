<?php
// backend/services/EmailService.php
//
// Provides:
//   Email::sendWelcomeEmail()          — called by register.php right after INSERT
//   Email::sendSignupMotivationEmail() — called by register.php immediately after welcome
//   Email::sendDailyMotivation()       — called by cron/send_daily_motivation.php
//
// Requires: backend/config/EmailConfig.php  (provides sendMail())

require_once __DIR__ . '/../config/EmailConfig.php';

class Email
{
    // ── All 55 quotes (shared by signup + cron) ───────────────────────────
    private static array $QUOTES = [
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
    ];

    // ── Site URL — change to your live domain when deploying ─────────────
    private static string $SITE_URL = 'http://localhost/gurkha-marga';


    // ═════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Step 5 in register.php — warm welcome the moment account is created.
     */
    public static function sendWelcomeEmail(string $fullName, string $toEmail): bool
    {
        $firstName = explode(' ', trim($fullName))[0];
        $subject   = '🎖️ Welcome to Gurkha Marga — Your Journey Begins';
        $html      = self::buildWelcomeHtml($firstName);
        $plain     = self::buildWelcomePlain($firstName);

        return sendMail($toEmail, $subject, $html, $plain);
    }

    /**
     * Step 6 in register.php — first motivational quote sent immediately after welcome.
     */
    public static function sendSignupMotivationEmail(
        string $fullName,
        string $toEmail,
        string $forceName
    ): bool {
        $firstName  = explode(' ', trim($fullName))[0];
        $dayOfYear  = (int) date('z');
        $quote      = self::$QUOTES[$dayOfYear % count(self::$QUOTES)];
        $dayLabel   = 'Day 1 — Your First Warrior Briefing';
        $dateLabel  = date('l, d F Y');
        $streakMsg  = '🔥 Day 1 — the hardest step is the one you just took.';
        $progressPct = 0; // brand new user

        $subject = '⚔️ Day 1 — Your Gurkha Marga Warrior Quote';
        $html    = self::buildMotivationHtml(
            $firstName, $forceName, $quote[0], $quote[1],
            $dayLabel, $dateLabel, $streakMsg, $progressPct
        );
        $plain = self::buildMotivationPlain(
            $firstName, $forceName, $quote[0], $streakMsg, $dayLabel
        );

        return sendMail($toEmail, $subject, $html, $plain);
    }

    /**
     * Called by cron/send_daily_motivation.php for every user with email_daily = 1.
     */
    public static function sendDailyMotivation(
        string $fullName,
        string $toEmail,
        string $forceName,
        int    $streak
    ): bool {
        $firstName   = explode(' ', trim($fullName))[0];
        $dayOfYear   = (int) date('z');
        $quote       = self::$QUOTES[$dayOfYear % count(self::$QUOTES)];
        $dayLabel    = 'Day ' . ($dayOfYear + 1) . ' of 365';
        $dateLabel   = date('l, d F Y');
        $progressPct = min(100, (int) round(($dayOfYear + 1) / 365 * 100));
        $streakMsg   = self::streakMessage($streak);

        $subject = "⚔️ {$dayLabel} — Your Gurkha Marga Warrior Quote";
        $html    = self::buildMotivationHtml(
            $firstName, $forceName, $quote[0], $quote[1],
            $dayLabel, $dateLabel, $streakMsg, $progressPct
        );
        $plain = self::buildMotivationPlain(
            $firstName, $forceName, $quote[0], $streakMsg, $dayLabel
        );

        return sendMail($toEmail, $subject, $html, $plain);
    }


    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    private static function streakMessage(int $streak): string
    {
        if ($streak >= 365) return "🏆 {$streak}-day streak — a full warrior year.";
        if ($streak >= 200) return "🏆 {$streak}-day streak — iron mind achieved.";
        if ($streak >= 100) return "🏆 {$streak}-day streak — century soldier.";
        if ($streak >= 66)  return "🔥 {$streak}-day streak — habit fully automatic.";
        if ($streak >= 30)  return "🔥 {$streak}-day streak — habit fully formed.";
        if ($streak >= 7)   return "🔥 {$streak}-day streak — one week strong.";
        return "🔥 {$streak}-day streak — keep going.";
    }

    // ── WELCOME HTML ──────────────────────────────────────────────────────
    private static function buildWelcomeHtml(string $firstName): string
    {
        $safe    = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $siteUrl = self::$SITE_URL;
        $year    = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#05080f;padding:30px 10px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- HEADER -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a0008,#0a0015);border-radius:16px 16px 0 0;
                     padding:36px 36px 28px;text-align:center;
                     border:1px solid rgba(200,16,46,.3);border-bottom:none;">
            <div style="font-size:2.8rem;margin-bottom:10px;">⚔️</div>
            <div style="font-size:1.9rem;font-weight:800;letter-spacing:.1em;color:#f0c040;">GURKHA MARGA</div>
            <div style="font-size:.72rem;color:#3d4f6e;letter-spacing:.18em;text-transform:uppercase;margin-top:4px;">
              ELITE FITNESS PLATFORM
            </div>
          </td>
        </tr>

        <!-- WELCOME BODY -->
        <tr>
          <td style="background:#0a1020;padding:40px 36px 32px;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="text-align:center;margin-bottom:28px;">
              <div style="display:inline-block;padding:6px 22px;border-radius:99px;
                          background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);
                          font-size:.78rem;color:#6ee7b7;letter-spacing:.1em;font-family:monospace;">
                ACCOUNT ACTIVATED
              </div>
            </div>
            <p style="font-size:1.1rem;color:#edf2fc;line-height:1.8;margin-bottom:18px;">
              Namaste <strong style="color:#f0c040;">{$safe}</strong>,
            </p>
            <p style="font-size:.92rem;color:#8a9bbf;line-height:1.8;margin-bottom:18px;">
              Welcome to <strong style="color:#edf2fc;">Gurkha Marga</strong> — the training platform
              built for those who pursue the most demanding military selections in the world.
            </p>
            <p style="font-size:.92rem;color:#8a9bbf;line-height:1.8;margin-bottom:28px;">
              Your account is live. Every day at 05:30 you will receive a warrior briefing —
              one quote, your streak, and your year progress. Read it. Carry it into training.
            </p>

            <!-- WHAT'S INSIDE -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
              <tr>
                <td style="width:50%;padding-right:8px;vertical-align:top;">
                  <div style="background:#0f1a2e;border-radius:10px;padding:14px 16px;
                              border:1px solid rgba(59,130,246,.15);">
                    <div style="font-size:1.3rem;margin-bottom:6px;">📊</div>
                    <div style="font-size:.78rem;font-weight:700;color:#93c5fd;margin-bottom:4px;">Progress Tracker</div>
                    <div style="font-size:.72rem;color:#475569;line-height:1.5;">
                      Log every workout, track your PBs, see your improvement over time.
                    </div>
                  </div>
                </td>
                <td style="width:50%;padding-left:8px;vertical-align:top;">
                  <div style="background:#0f1a2e;border-radius:10px;padding:14px 16px;
                              border:1px solid rgba(251,191,36,.15);">
                    <div style="font-size:1.3rem;margin-bottom:6px;">🔥</div>
                    <div style="font-size:.78rem;font-weight:700;color:#f0c040;margin-bottom:4px;">Daily Streak</div>
                    <div style="font-size:.72rem;color:#475569;line-height:1.5;">
                      Visit every day to maintain your streak. 365 days — a full warrior year.
                    </div>
                  </div>
                </td>
              </tr>
            </table>

            <div style="text-align:center;">
              <a href="{$siteUrl}/frontend/users/dashboard.php"
                 style="display:inline-block;padding:14px 38px;border-radius:10px;
                        background:linear-gradient(135deg,#c8102e,#8b0000);color:#fff;
                        text-decoration:none;font-weight:700;font-size:.92rem;letter-spacing:.08em;">
                🔱 ENTER WARRIOR ZONE
              </a>
            </div>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#060b14;border-radius:0 0 16px 16px;padding:18px 36px;
                     text-align:center;border:1px solid rgba(255,255,255,.05);border-top:none;">
            <div style="font-size:.72rem;color:#3d4f6e;line-height:1.9;">
              You are receiving this because you created a Gurkha Marga account.<br>
              <a href="{$siteUrl}/frontend/users/motivation.php"
                 style="color:#8a9bbf;text-decoration:underline;">Manage email preferences</a>
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

    private static function buildWelcomePlain(string $firstName): string
    {
        $siteUrl = self::$SITE_URL;
        return
            "GURKHA MARGA — ELITE FITNESS PLATFORM\n"
          . str_repeat('-', 40) . "\n\n"
          . "Namaste {$firstName},\n\n"
          . "Welcome to Gurkha Marga. Your account is now active.\n\n"
          . "Every morning at 05:30 you will receive a daily warrior briefing.\n\n"
          . "Enter your warrior zone: {$siteUrl}/frontend/users/dashboard.php\n\n"
          . "Gurkha Marga © " . date('Y');
    }

    // ── MOTIVATION HTML (shared by signup Day 1 + cron) ───────────────────
    private static function buildMotivationHtml(
        string $firstName,
        string $forceName,
        string $quoteText,
        string $quoteSource,
        string $dayLabel,
        string $dateLabel,
        string $streakMsg,
        int    $progressPct
    ): string {
        $safe        = htmlspecialchars($firstName,   ENT_QUOTES, 'UTF-8');
        $safeForce   = htmlspecialchars($forceName,   ENT_QUOTES, 'UTF-8');
        $safeQuote   = htmlspecialchars($quoteText,   ENT_QUOTES, 'UTF-8');
        $safeSource  = htmlspecialchars($quoteSource, ENT_QUOTES, 'UTF-8');
        $safeDay     = htmlspecialchars($dayLabel,    ENT_QUOTES, 'UTF-8');
        $safeDate    = htmlspecialchars($dateLabel,   ENT_QUOTES, 'UTF-8');
        $safeStreak  = htmlspecialchars($streakMsg,   ENT_QUOTES, 'UTF-8');
        $progressBar = str_repeat('█', (int)($progressPct / 5))
                     . str_repeat('░', 20 - (int)($progressPct / 5));
        $siteUrl     = self::$SITE_URL;
        $year        = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#05080f;padding:30px 10px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- HEADER -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a0008,#0a0015);border-radius:16px 16px 0 0;
                     padding:32px 36px;text-align:center;
                     border:1px solid rgba(200,16,46,.3);border-bottom:none;">
            <div style="font-size:2.2rem;margin-bottom:8px;">⚔️</div>
            <div style="font-size:1.8rem;font-weight:800;letter-spacing:.1em;color:#f0c040;">GURKHA MARGA</div>
            <div style="font-size:.72rem;color:#3d4f6e;letter-spacing:.18em;text-transform:uppercase;margin-top:4px;">
              ELITE FITNESS PLATFORM
            </div>
            <div style="margin-top:16px;display:inline-block;padding:5px 18px;border-radius:99px;
                        background:rgba(200,16,46,.15);border:1px solid rgba(200,16,46,.3);
                        font-size:.75rem;color:#ff7090;letter-spacing:.1em;font-family:monospace;">
              {$safeDay} &nbsp;·&nbsp; {$safeDate}
            </div>
          </td>
        </tr>

        <!-- QUOTE -->
        <tr>
          <td style="background:#0a1020;padding:36px 36px 28px;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                        font-family:Georgia,serif;margin-bottom:8px;">"</div>
            <div style="font-family:Georgia,serif;font-size:1.25rem;font-style:italic;
                        font-weight:600;color:#edf2fc;line-height:1.7;
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

        <!-- STREAK + PROGRESS -->
        <tr>
          <td style="background:#0c1525;padding:20px 36px;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="width:50%;padding-right:10px;vertical-align:top;">
                  <div style="background:#0a1020;border-radius:10px;padding:14px 16px;
                              border:1px solid rgba(240,192,64,.15);text-align:center;">
                    <div style="font-size:1.4rem;margin-bottom:4px;">🔥</div>
                    <div style="font-size:.82rem;font-weight:700;color:#f0c040;
                                line-height:1.4;">{$safeStreak}</div>
                  </div>
                </td>
                <td style="width:50%;padding-left:10px;vertical-align:top;">
                  <div style="background:#0a1020;border-radius:10px;padding:14px 16px;
                              border:1px solid rgba(46,124,246,.15);text-align:center;">
                    <div style="font-size:.65rem;color:#3d4f6e;letter-spacing:.1em;
                                text-transform:uppercase;font-family:monospace;
                                margin-bottom:6px;">YEAR PROGRESS</div>
                    <div style="font-family:monospace;font-size:.65rem;color:#2e7cf6;
                                word-break:break-all;">{$progressBar}</div>
                    <div style="font-family:monospace;font-size:.75rem;color:#6ab4ff;
                                margin-top:4px;">{$progressPct}% of 365 days</div>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- CTA -->
        <tr>
          <td style="background:#0a1020;padding:28px 36px;text-align:center;
                     border-left:1px solid rgba(200,16,46,.3);
                     border-right:1px solid rgba(200,16,46,.3);">
            <div style="font-size:.88rem;color:#8a9bbf;margin-bottom:18px;line-height:1.7;">
              Namaste <strong style="color:#edf2fc;">{$safe}</strong> —
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

        <!-- FOOTER -->
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

    private static function buildMotivationPlain(
        string $firstName,
        string $forceName,
        string $quoteText,
        string $streakMsg,
        string $dayLabel
    ): string {
        $siteUrl = self::$SITE_URL;
        return
            "GURKHA MARGA — ELITE FITNESS PLATFORM\n"
          . str_repeat('-', 40) . "\n"
          . "{$dayLabel}\n\n"
          . "Namaste {$firstName},\n\n"
          . "\"{$quoteText}\"\n\n"
          . "— {$forceName}\n\n"
          . str_repeat('-', 40) . "\n"
          . "{$streakMsg}\n\n"
          . "Open warrior zone: {$siteUrl}/frontend/users/motivation.php\n\n"
          . "Gurkha Marga © " . date('Y');
    }
}