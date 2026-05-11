<?php
// backend/cron/send_daily_motivation.php
// Run via cron: 0 17 * * * php /path/to/gurkha-marga/backend/cron/send_daily_motivation.php

define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/config/Config.php';
require_once BASE_PATH . '/backend/config/EmailConfig.php';
require_once BASE_PATH . '/backend/database.php';

// ── 365 daily quotes (index 0–364 matches PHP date('z')) ─────────────
$quotes = [
    "The mountain does not care if you are tired. Neither does the finish line.",
    "A Gurkha does not ask if the task is possible. He asks only when it must be done.",
    "Discipline today is the freedom you earn tomorrow.",
    "Your competition is not another man. Your competition is who you were yesterday.",
    "Pain is temporary. Passing selection is permanent.",
    "Every sunrise is a battle order. Will you obey it?",
    "Iron sharpens iron. Train harder than the standard demands.",
    "A warrior is not defined by how many times he stands. He is defined by how many times he rises.",
    "The uniform is earned on the training ground, not on selection day.",
    "Sweat now. Bleed never.",
    "The man who rises at 5 AM has already won half the battle.",
    "Fear is a liar. Your body can do far more than your mind believes.",
    "You do not get what you wish for. You get what you work for.",
    "The standards do not lower for anyone. You must rise to meet them.",
    "Ayō Gorkhali — and with that cry, mountains have trembled.",
    "One more pull-up today means one less doubt tomorrow.",
    "Champions are made in the quiet sessions no one sees.",
    "The khukuri is sharp because it is used. Your body must be the same.",
    "Sleep like a warrior. Eat like a warrior. Train like a warrior. Think like a warrior.",
    "Run when you are tired. That is when training begins.",
    "21 days to build a habit. You are already building the soldier.",
    "You are not tired. You are uncomfortable. There is a difference.",
    "The enemy you will face has also been training. Train harder.",
    "A Gurkha's word is his contract. Your commitment today is your contract.",
    "Brick by brick. Rep by rep. Day by day. The fortress is built.",
    "Do not pray for easy battles. Pray to be a stronger fighter.",
    "Your ancestors ran barefoot through mountains. You have no excuse.",
    "The mind breaks first. Train it before you train the body.",
    "Run the route when it is raining. You will never fear rain again.",
    "Thirty days of consistency has changed more soldiers than talent ever has.",
    "You have not reached your limit. You have reached your comfort zone.",
    "Every morning you wake and train is a vote for the soldier you want to become.",
    "The Gurkha does not ask for glory. The glory finds the Gurkha.",
    "Hard training, easy battle. Easy training, hard battle.",
    "Let your legs carry you to where your dreams live.",
    "What you do in the darkness will be revealed in the light of selection day.",
    "Character is who you are when no one is watching.",
    "Breathe. Step. Repeat. Mountains are climbed one step at a time.",
    "You are not behind. You are exactly where your effort has placed you.",
    "A soldier's greatest enemy is not the enemy. It is complacency.",
    "The standard you walk past is the standard you accept.",
    "Be the soldier who does the extra mile when no one assigns it.",
    "Push-ups do not build strength. Consistency builds strength.",
    "The body achieves what the mind believes.",
    "The only way to get fitter is to show up, every day.",
    "A bad day of training is infinitely better than no day of training.",
    "Your future self is watching your choices today. Make them proud.",
    "In the mountains of Nepal, the path is steep and the air is thin. Train accordingly.",
    "Silence your doubts the only way that works — with action.",
    "Fifty days in. You are no longer a beginner. You are a builder.",
    "The difference between a soldier and a civilian is the willingness to suffer on purpose.",
    "When your legs say stop, your history says continue.",
    "Hunger sharpens the blade. Let your desire for the uniform sharpen you.",
    "You have survived 100% of your hardest days so far.",
    "A man who masters himself can master any terrain.",
    "Your pace in training sets the ceiling for your pace in selection.",
    "Rise before the city wakes. Own the morning. Own the day.",
    "Every kilogram of fat lost is a kilogram not carried over the finish line.",
    "Strength does not shout. It endures quietly and arrives on time.",
    "Sixty days. The habit is now yours. Protect it.",
    "The weight of the world feels lighter when you are physically strong.",
    "Do not count the miles you have run. Count the days you did not stop.",
    "One press-up more than yesterday is progress. Do not underestimate it.",
    "Your recruiter will not remember your excuses. Only your scores.",
    "The Gurkha hill runs are not training for the test. The test IS the training.",
    "66 days — science says your habits are now automatic. The soldier is taking shape.",
    "Fuel is not a reward. Fuel is a weapon. Eat accordingly.",
    "Walk the path in the dark and you will know it by heart when it matters.",
    "You are building something that cannot be taken from you.",
    "Seventy days of work buys credibility no speech can give.",
    "The best preparation for tomorrow is the complete execution of today.",
    "Your uniform will weigh nothing compared to the regret of never trying.",
    "Run with the ache in your lungs. That ache is your lungs growing stronger.",
    "The training ground is a classroom. What lesson will you take today?",
    "Do not let the weekend break what the week built.",
    "A soldier in the making is a force in training. Be a force today.",
    "Your effort in the dark is an investment that pays on selection day.",
    "One cold shower. One hill run. One extra rep. Small acts, massive character.",
    "The man who does not know himself cannot command others.",
    "Eighty days strong. Most people quit before now. You are not most people.",
    "Study the selection tests. Train for what is required. Be efficient.",
    "Nepal produced warriors when others did not know war existed.",
    "The harder the training, the softer the selection feels.",
    "Your nutrition is your invisible training session. Do not skip it.",
    "Strength training is not vanity. It is armor for the mission ahead.",
    "The valley looks different from the summit. Climb first, celebrate after.",
    "Breathe deep. The air that fills your lungs filled a Gurkha's in the Falklands.",
    "A warrior's posture is trained, not born.",
    "Do not wait for motivation to arrive. Begin, and motivation will follow.",
    "90 days — you have outlasted almost everyone who started with you.",
    "The mission is simple: be better tomorrow than you were today.",
    "A Gurkha's smile in the face of hardship is not weakness. It is superiority.",
    "The forest does not grow faster when you stare at it. Show up, water it, leave.",
    "You cannot control selection day. You can control every day before it.",
    "Run hills until flat roads feel like rest.",
    "Your doubts are loudest at 5am and weakest by 6am, after you have already run.",
    "There is no shortcut from Pokhara to Sandhurst. There is only the path.",
    "Your body speaks in the language of effort. Are you fluent yet?",
    "One more day away from regret. One more day closer to identity.",
    "100 days. You have crossed the threshold. Very few do. Keep going.",
    "Now it gets serious. 100 days of foundation. Time to build the walls.",
    "The Gurkha oath is not taken lightly. Neither should your training be.",
    "Good enough is the enemy of selected.",
    "Rest is not surrender. It is strategy.",
    "Your body has never lied to you. Your mind has. Trust the body's earned response.",
    "What you repeatedly do, you will become. Choose your repetitions wisely.",
    "The British Army has been selecting Gurkhas since 1815. Every one of them trained.",
    "Speed is trained. Endurance is built. Character is revealed under pressure.",
    "Your weakness today is the target you train against tomorrow.",
    "When the recruiter watches you run, what story does your stride tell?",
    "Three ones: one goal, one path, one commitment.",
    "Training sore means training honest.",
    "You are not just preparing your body. You are preparing the soldier who will lead.",
    "The Gurkha knife has two edges: combat and ceremony. Be sharp in both.",
    "A soldier who cannot run cannot fight. Run.",
    "Every missed session is a transaction — your future self pays for it.",
    "The recruiter will shake the hand you have built. Make it a strong hand.",
    "Sunsets come after every exhausting day. Both are worth experiencing.",
    "Iron the mind before you iron the uniform.",
    "120 days. You are building a cathedral. Brick by brick.",
    "The dawn patrol belongs to you. Take it every day.",
    "Your competition woke up this morning. Did you?",
    "Repetition is the mother of mastery.",
    "The only bad workout is the one that never happened.",
    "Pressure makes diamonds. Let the training apply pressure.",
    "You were not born a soldier. You are becoming one. Daily.",
    "A Gurkha serves with his heart, not just his hands.",
    "Eat clean. Sleep hard. Train focused. Repeat without exception.",
    "Your training log is a war diary. Write it with intent.",
    "The person who trained yesterday is faster than the person who trains tomorrow.",
    "Halfway through the year approaches. Assess your standard. Adjust.",
    "The best time to plant a tree was 20 years ago. The second best time is now.",
    "Your uniform will smell of the training that earned it. Let it.",
    "Chase the standard, not the spotlight.",
    "Rain, mud, and cold are not obstacles. They are the course.",
    "A Gurkha never complains about the hill. He studies the best line to climb it.",
    "You are the architect of your physical life. Design it deliberately.",
    "When you want to stop — breathe, count to ten, continue.",
    "Your ancestors' blood runs in your veins. Run with that knowledge.",
    "140 days in. You have more foundation than most soldiers had before selection.",
    "The legs you want are built in the sessions you do not want to do.",
    "Selection does not care about your feelings. Train them to align with your goals.",
    "Eat rice. Train hard. Sleep well. Simple formula. Consistent execution.",
    "Your mind is the battalion commander. Your body is the platoon. Lead it well.",
    "A sharp knife is not loud. Sharpen silently. Cut decisively.",
    "The only one stopping you is the untrained version of you. Train past him.",
    "Hills are paid for in training, not in fear.",
    "5am belongs to you before it belongs to anyone else. Claim it.",
    "The war against weakness is fought in the training ground. Win it there first.",
    "150 days — you are no longer starting. You are sustaining. That is harder.",
    "Commitment is doing what you said long after the mood that inspired it has left.",
    "Your selection day is a single day. Your preparation is every other day.",
    "Do not flinch from the hard run. It builds the soldier who will not flinch under fire.",
    "The flag you will carry has been carried by better men. Earn the right.",
    "Every dawn is a blank page. What will you write with your body today?",
    "Sleep is free performance enhancement. Use it fully.",
    "Preparation is an act of respect — to the regiment, to yourself.",
    "A body made uncomfortable every day adapts to discomfort.",
    "You already know what to do. The question is whether you will do it.",
    "160 days — you have trained through seasons. Now train through what breaks others.",
    "The test of a soldier is not the test day. It is every day before it.",
    "Hills made Nepal's warriors. Seek hills.",
    "Weakness has a cure. It is called consistent effort.",
    "Train the way you want to perform. There is no gap between them.",
    "The body is a record of every decision you made. What does yours say today?",
    "Not faster, not stronger — just more consistent than anyone else.",
    "Your sacrifice today is invisible. Your result on selection day will not be.",
    "Do not tell people you are training. Show them on selection day.",
    "The soldier you want to be is on the other side of the discipline you resist.",
    "170 days. You are more than halfway to a year. The year that changes everything.",
    "Every kilogram pressed is a statement. Every kilometer run is a declaration.",
    "The body you train is the body that will carry the weight of the mission.",
    "Silence your phone. Silence your excuses. Start.",
    "Do not be the soldier who wished he had trained harder. Be the one who did.",
    "The Gurkha does not retreat. Advance or hold. Never retreat from your goals.",
    "Pain fades. The memory of effort does not. Build memories worth having.",
    "Training is rehearsal. Selection is the performance. Rehearse perfectly.",
    "Your sweat on the training ground is the rain that grows the soldier.",
    "Breathe through the hard parts. The hard parts are the whole point.",
    "180 days — half a year of commitment. The world has no idea what you are building.",
    "The second half is harder because you are tired. More valuable because of it.",
    "You are six months from where you were. Can you see the difference?",
    "Fatigue is honest. Listen to it. Respond intelligently. Never obey it blindly.",
    "The army will give you weapons. You must provide the warrior who carries them.",
    "Do not miss training because of comfort. Comfort does not pass selection.",
    "Your goal is a point on a map. Training is navigation. Do not get lost.",
    "A thousand small victories is a war won.",
    "The lungs burn because they are becoming capable of more.",
    "Train today so that you can protect tomorrow.",
    "190 days — the body you wear now is not the body you started with.",
    "Every man who crossed the selection hill was once on the other side of it.",
    "The goal is not to be good enough. The goal is to be unquestionable.",
    "Hard work is invisible from the outside. The results are not.",
    "Hunger is a sign of commitment. Let it remind you what you are reaching for.",
    "You can plan the training or you can plan the regret. Choose.",
    "The enemy's strength is irrelevant if your preparation is absolute.",
    "A drill done a thousand times needs no thought. Drill your fundamentals.",
    "Winter does not care about your goals. Train anyway. Especially in winter.",
    "You are 199 days of proof that you do not quit. Do not start now.",
    "200 days. Two hundred sunrises you chose your future over your comfort.",
    "You are not a visitor at the training ground. You are a resident.",
    "The wall you hit is not the end. It is the beginning of the real training.",
    "Soldiers are made in the discipline between sessions.",
    "Your eating is part of your training. You cannot out-train a bad diet.",
    "Prove it to yourself first. The examiner will notice.",
    "The path to selection is paved with refusals to stop.",
    "There is no medal for trying. There is a medal for completing. Complete.",
    "Hard bodies carry soft minds further. Hard minds carry broken bodies further still.",
    "Tomorrow is built in today's training session.",
    "210 days — more prepared than 90% of applicants who face the same selection.",
    "Run like the selection board is watching. Because one day, they will be.",
    "The Gurkha who earns the khukuri keeps it sharp for life.",
    "You have no days left to waste. Every day is counted from here.",
    "A soldier's fitness is not the goal. It is the vehicle.",
    "Pull up. Push up. Stand up. Show up. The four commandments of selection prep.",
    "Your training is a letter to your future self. Make it worth reading.",
    "Patience is not waiting. Patience is training while waiting.",
    "The weight of your ambition must be matched by the weight you lift.",
    "Be the first to the training ground and the last to leave.",
    "220 days. You are in the territory where transformation becomes visible.",
    "Train like your life depends on it. For some, it will.",
    "The mind trained in discomfort finds combat merely another challenge.",
    "Three digits of training days. That is who you are now.",
    "Your khukuri will be presented to you. Make sure you deserve the ceremony.",
    "The only question selection asks is: are you ready? Answer with your training record.",
    "A Gurkha in the field is worth ten men in terms of morale alone.",
    "Mountains are not climbed with talent. They are climbed with persistence.",
    "Train for the worst day you might face. Live the best day that follows.",
    "You are the sum of your training sessions. Add wisely.",
    "230 days — winter, heat, rain, or shine. You have trained through them all.",
    "Every second you rest is the second a competitor is running.",
    "The finish line does not move. You must move toward it.",
    "Strength and honor. The two currencies that matter on selection day.",
    "You are preparing for the hardest interview of your life. Do not underprepare.",
    "Consistency is the rarest form of talent.",
    "When the sergeant shouts your name, what has your training made you?",
    "Do not fear the examination. Fear the unprepared version of yourself walking into it.",
    "The soldiers who went before you are watching. Do not embarrass the standard.",
    "Every 5km in training is one less fear you carry to selection.",
    "240 days. The question is no longer whether you can. It is when.",
    "Train with absolute focus for one hour. Distraction-free. Watch what happens.",
    "Your fitness is a message to the world about your relationship with your goals.",
    "A warrior does not need motivation. He has a standard.",
    "Drink the water. Sleep the hours. Run the distance. Eat the food. Basics win.",
    "The hardest kilometer is the one you almost did not start.",
    "Selection is not searching for perfect athletes. It is searching for perfect character.",
    "You were built for more than comfort. You were built for the mission.",
    "Every honest rep is a deposit into the account that pays on selection day.",
    "The mountains of Nepal have been watching soldiers train for centuries. Now they watch you.",
    "250 days — the compound interest of discipline is starting to show.",
    "Do not compare your progress to someone else who started later.",
    "Scars in training mean strength in selection.",
    "Your next personal best is waiting behind your next maximum effort.",
    "Do not let tiredness make a coward of you.",
    "Consistency over intensity. The marathon defeats the sprinter.",
    "The training that broke you is the training that built you.",
    "Run with your eyes forward, not on the ground where you have already been.",
    "The hardest part is lacing up. After that, it is just running.",
    "Breathe. Balance. Push. Pull. The four elements of physical readiness.",
    "260 days — you have trained on days when others called it impossible.",
    "The candidate who shows up is already ahead of the one who stayed home.",
    "Your body is not a machine to be broken. It is a capability to be expanded.",
    "A Gurkha is feared not because he is angry, but because he is prepared.",
    "Give 100% today. Tomorrow's version of you will be stronger because of it.",
    "Do not negotiate with your alarm clock. You always lose.",
    "The Gurkha contingent has never been defeated in combat. Continue the tradition.",
    "Your speed is built over months. Your strength is built over months. Begin.",
    "Silence the noise. Hear only the sound of your breathing and your footsteps.",
    "Walk when you must. Run when you can. But never stop.",
    "270 days — nine months of effort. A new soldier is being born.",
    "The cold does not care about your schedule. Train in it.",
    "Your recruiter has seen thousands. Be the one remembered.",
    "Prepare as though your family is watching.",
    "The standards were set by men who proved they were achievable.",
    "Your next milestone: 100 days remaining. Close the gap.",
    "A warrior eats to fuel, not to comfort.",
    "Do not let a good day soften a great week. Maintain.",
    "The khukuri has no hilt guard because the Gurkha commits fully to every strike.",
    "Your training is a debt you owe to the version of yourself that wants to pass.",
    "280 days — you are a completely different person than who started.",
    "What you believe about yourself under pressure is what you will perform.",
    "The selection cadre wants to see who you are when it hurts. Show them.",
    "A soldier serves something larger than himself. Keep that in mind on the hard days.",
    "Discipline is remembering what you want most, over what you want now.",
    "You have trained through every obstacle this year has thrown. What is one more?",
    "Lift as though the uniform depends on it. Because it does.",
    "The Himalayan wind is cold. The Gurkha's resolve is colder.",
    "288 days — you are in the final quarter. No days off from excellence now.",
    "Train like a professional and you will perform like one.",
    "Your training timeline is not flexible. Your technique is.",
    "Carry the ruck with gratitude. Some would give anything for the chance.",
    "The last 5 meters of a pull-up set is where soldiers are selected.",
    "Do not train to pass. Train to dominate.",
    "Pain is the price of admission. Excellence is the reward.",
    "You are 70 days from a year. Every single one matters now.",
    "Run the extra loop when no one assigned it. That is the soldier who gets selected.",
    "The proudest moment will be when you look back at the training that made it possible.",
    "Prepare so thoroughly that on selection day you are just executing your plan.",
    "Challenge your limits every single day or they will not move.",
    "300 days — three hundred reasons why you will not quit on day 301.",
    "The final hundred days are not for building. They are for sharpening.",
    "Your name in the selection results will be the product of these days.",
    "A soldier is never truly at rest. He is either training or recovering.",
    "You have carried this goal for 304 days. It has weight. Honor it.",
    "The selection year ends. The soldier's career begins.",
    "What others see as dedication, you know as keeping your word to yourself.",
    "Fewer days remain than have passed. The end is nearer than the beginning.",
    "Fear of failure is a compass. It points to what matters.",
    "You have done the hard things. Selection day will be the easiest of them all.",
    "310 days of trust in the process. The process has been trustworthy.",
    "A Gurkha who has trained well has no need of luck.",
    "You are writing a story with your body. Make it one worth telling.",
    "The candidate who is calm on selection day trained calmly under pressure.",
    "Your fitness speaks before you open your mouth.",
    "315 days — fifty remain. Every one is gold.",
    "Train until you cannot get it wrong.",
    "The khukuri has never been used in surrender.",
    "Tomorrow's selection is built in today's session. Build carefully.",
    "Respect the process enough to complete it fully.",
    "320 days of evidence that you are not the person who quits.",
    "You are not preparing to survive selection. You are preparing to excel.",
    "The uniform fits those who have earned it. Have you earned it?",
    "On the final stretch, do not think about the distance left. Think about the next step.",
    "The mountain is behind you more than it is in front of you.",
    "40 days. Each one a soldier's increment.",
    "Your body will give what your mind insists upon.",
    "Train with urgency. The selection date is fixed.",
    "The last month of preparation is the most important month. Treat it accordingly.",
    "Every morning you rose and trained, you made a promise. Keep the last ones too.",
    "330 days — thirty-five remain. Do not coast. Accelerate.",
    "You have already won the hardest battle — the one against giving up.",
    "Thirty days more. The person on the other side of them will be selected.",
    "Sharpen every edge. Run every session. Leave nothing in reserve until selection day.",
    "Your preparation has been seen by no audience except yourself. That is the purest discipline.",
    "The finish line is close enough to smell. Do not stop now.",
    "A year of sweat for a lifetime of pride. The maths are in your favor.",
    "You have been training for 337 days. On day 338, you train again.",
    "The soldiers in the portrait on the regimental wall were once exactly where you are.",
    "26 days. Every kilometer is an argument for your selection.",
    "340 days of loyalty to yourself. Do not betray the streak now.",
    "The examiner sees your fitness. Your character was built in private.",
    "Twenty days. Twenty final opportunities to be better.",
    "You are who you have been training to be.",
    "Rest well. The final sessions require your full strength.",
    "15 days. Taper your body. Sharpen your mind. Trust your work.",
    "The Gurkha selection is not the end. It is the beginning. Prepare to begin.",
    "10 days. You have earned your place at the start line.",
    "7 days. Trust everything you have built. Do not add — just maintain.",
    "6 days. Review your documents. Know your numbers. Believe your training.",
    "5 days. Sleep. Eat. Hydrate. Trust the work.",
    "4 days. You are ready. Your body knows it even if your mind is nervous.",
    "3 days. Visualize the route. Visualize the pull-up bar. Visualize your name on the list.",
    "2 days. Rest. Pack. Breathe. You have prepared for this moment for a year.",
    "Tomorrow is the day. Sleep tonight as if you have already passed.",
    "This is selection day. You are ready. You have been ready for months.",
    "After selection: rest, reflect, then rebuild immediately.",
    "Whether you passed or are building for next time — today you continue.",
    "The year is nearly done. The soldier has been built.",
    "Reflection: what did this year of discipline teach you about yourself?",
    "360 days of commitment deserve 360 days of pride.",
    "A year of training is a year of becoming.",
    "The final days are not endings. They are the beginning of the next preparation.",
    "Thank the process. It made you.",
    "Tomorrow is the last day of this year's journey. Begin planning the next.",
    "365 days. A warrior's year. Now begin again — stronger, wiser, and already ahead.",
];

// ── Force display names ───────────────────────────────────────────────
$forceNames = [
    'british'   => 'British Army',
    'nepal'     => 'Nepal Army',
    'indian'    => 'Indian Army',
    'singapore' => 'Singapore Police Force',
    'french'    => 'French Foreign Legion',
];

// ── Get today's quote ─────────────────────────────────────────────────
$dayOfYear = (int)date('z'); // 0–364
$todayQuote = $quotes[$dayOfYear % count($quotes)];
$dateLabel  = date('l, d F Y');
$dayLabel   = 'Day ' . ($dayOfYear + 1) . ' of 365';

// ── Fetch all users with email_daily = 1 ─────────────────────────────
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

$sent   = 0;
$failed = 0;

foreach ($recipients as $user) {
    $firstName = explode(' ', $user['full_name'])[0];
    $forceName = $forceNames[strtolower($user['target_force'] ?? 'british')] ?? 'British Army';
    $streak    = (int)$user['streak_days'];
    $streakMsg = $streak >= 100
        ? "🏆 {$streak}-day streak — you are a century soldier."
        : ($streak >= 30
            ? "🔥 {$streak}-day streak — habit fully formed."
            : "🔥 {$streak}-day streak — keep going.");

    $subject = "⚔️ Day " . ($dayOfYear + 1) . " — Your Gurkha Marga Warrior Quote";

    $body = buildEmailBody($firstName, $forceName, $todayQuote, $dayLabel, $dateLabel, $streakMsg, $dayOfYear + 1);

    $ok = sendMail($user['email'], $subject, $body);

    if ($ok) {
        $sent++;
        echo "[" . date('H:i:s') . "] ✓ Sent to {$user['email']} ({$firstName})\n";
    } else {
        $failed++;
        echo "[" . date('H:i:s') . "] ✗ Failed: {$user['email']} ({$firstName})\n";
    }

    // Small delay to avoid SMTP rate limits
    usleep(300000); // 0.3 seconds
}

echo "\n[" . date('Y-m-d H:i:s') . "] Done. Sent: {$sent} | Failed: {$failed}\n";

// ── HTML email builder ────────────────────────────────────────────────
function buildEmailBody(
    string $name,
    string $force,
    string $quote,
    string $dayLabel,
    string $dateLabel,
    string $streakMsg,
    int    $dayNum
): string {
    $progressPct = min(100, round(($dayNum / 365) * 100));
    $progressBar = str_repeat('█', (int)($progressPct / 5)) . str_repeat('░', 20 - (int)($progressPct / 5));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gurkha Marga Daily Motivation</title>
</head>
<body style="margin:0;padding:0;background:#05080f;font-family:'Segoe UI',Arial,sans-serif;">

  <!-- Wrapper -->
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#05080f;padding:30px 10px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#1a0008,#0a0015);border-radius:16px 16px 0 0;
                       padding:32px 36px;text-align:center;border:1px solid rgba(200,16,46,.3);border-bottom:none;">
              <div style="font-size:2.2rem;margin-bottom:8px;">⚔️</div>
              <div style="font-family:'Bebas Neue','Arial Black',sans-serif;font-size:1.8rem;
                          letter-spacing:.1em;color:#f0c040;">GURKHA MARGA</div>
              <div style="font-size:.72rem;color:#3d4f6e;letter-spacing:.18em;text-transform:uppercase;
                          margin-top:4px;">ELITE FITNESS PLATFORM</div>
              <div style="margin-top:16px;display:inline-block;padding:5px 18px;border-radius:99px;
                          background:rgba(200,16,46,.15);border:1px solid rgba(200,16,46,.3);
                          font-size:.75rem;color:#ff7090;letter-spacing:.1em;font-family:monospace;">
                {$dayLabel} &nbsp;·&nbsp; {$dateLabel}
              </div>
            </td>
          </tr>

          <!-- Quote block -->
          <tr>
            <td style="background:#0a1020;padding:36px 36px 28px;
                       border-left:1px solid rgba(200,16,46,.3);border-right:1px solid rgba(200,16,46,.3);">
              <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                          font-family:Georgia,serif;margin-bottom:8px;">"</div>
              <div style="font-family:Georgia,'Crimson Pro',serif;font-size:1.35rem;font-style:italic;
                          font-weight:600;color:#edf2fc;line-height:1.6;
                          border-left:3px solid #c8102e;padding-left:18px;margin:0 0 8px 0;">
                {$quote}
              </div>
              <div style="font-size:3rem;color:rgba(240,192,64,.15);line-height:.8;
                          font-family:Georgia,serif;text-align:right;margin-top:4px;">"</div>
              <div style="font-family:monospace;font-size:.75rem;color:#8a9bbf;margin-top:8px;
                          letter-spacing:.08em;">— Daily Gurkha Wisdom · {$force}</div>
            </td>
          </tr>

          <!-- Streak -->
          <tr>
            <td style="background:#0c1525;padding:20px 36px;
                       border-left:1px solid rgba(200,16,46,.3);border-right:1px solid rgba(200,16,46,.3);">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="width:50%;padding-right:12px;">
                    <div style="background:#0a1020;border-radius:10px;padding:14px 16px;
                                border:1px solid rgba(240,192,64,.15);text-align:center;">
                      <div style="font-size:1.5rem;">🔥</div>
                      <div style="font-family:'Arial Black',sans-serif;font-size:1.1rem;
                                  color:#f0c040;margin-top:4px;">{$streakMsg}</div>
                    </div>
                  </td>
                  <td style="width:50%;padding-left:12px;">
                    <div style="background:#0a1020;border-radius:10px;padding:14px 16px;
                                border:1px solid rgba(46,124,246,.15);text-align:center;">
                      <div style="font-size:.7rem;color:#3d4f6e;letter-spacing:.1em;
                                  text-transform:uppercase;font-family:monospace;margin-bottom:6px;">
                        YEAR PROGRESS
                      </div>
                      <div style="font-family:monospace;font-size:.7rem;color:#2e7cf6;
                                  letter-spacing:.03em;">{$progressBar}</div>
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
            <td style="background:#0a1020;padding:24px 36px;text-align:center;
                       border-left:1px solid rgba(200,16,46,.3);border-right:1px solid rgba(200,16,46,.3);">
              <div style="font-size:.88rem;color:#8a9bbf;margin-bottom:16px;line-height:1.6;">
                Namaste <strong style="color:#edf2fc;">{$name}</strong> — your daily warrior briefing has arrived.<br>
                Read it. Carry it. Train accordingly.
              </div>
              <a href="http://localhost/gurkha-marga/frontend/users/motivation.php"
                 style="display:inline-block;padding:12px 32px;border-radius:8px;
                        background:linear-gradient(135deg,#c8102e,#8b0000);color:#fff;
                        text-decoration:none;font-weight:700;font-size:.9rem;
                        letter-spacing:.08em;font-family:'Arial Black',sans-serif;">
                🔱 OPEN WARRIOR ZONE
              </a>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#060b14;border-radius:0 0 16px 16px;padding:20px 36px;
                       text-align:center;border:1px solid rgba(255,255,255,.05);border-top:none;">
              <div style="font-size:.72rem;color:#3d4f6e;line-height:1.8;">
                You are receiving this because daily motivation emails are enabled on your account.<br>
                <a href="http://localhost/gurkha-marga/frontend/users/motivation.php"
                   style="color:#8a9bbf;text-decoration:underline;">Manage email preferences</a>
                &nbsp;·&nbsp; Gurkha Marga &copy; <?= date('Y') ?>
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
HTML;
}