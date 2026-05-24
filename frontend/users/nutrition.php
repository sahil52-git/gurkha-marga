<?php

session_start();
define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php'); exit();
}
$userId = (int)$_SESSION['user_id'];
$user   = fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$weight   = (float)($user['weight']            ?? 70);
$height   = (float)($user['height']            ?? 170);
$age      = (int)  ($user['age']               ?? 20);
$gender   = strtolower($user['gender']         ?? 'male');
$activity = strtolower($user['activity_level'] ?? 'moderate');
$forceKey = strtolower($user['target_force']   ?? 'british');
$expKey   = strtolower($user['experience_level'] ?? 'beginner');
$firstName   = explode(' ', trim($user['full_name']))[0];
$initials    = strtoupper(substr($user['full_name'], 0, 1));

// Avatar (mirrors dashboard.php)
$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$forceMap = [
    'british'   => ['🇬🇧', 'British Army'],
    'nepal'     => ['🇳🇵', 'Nepal Army'],
    'indian'    => ['🇮🇳', 'Indian Army'],
    'singapore' => ['🇸🇬', 'Singapore Police Force'],
    'french'    => ['🇫🇷', 'French Foreign Legion'],
];
$forceFlag = $forceMap[$forceKey][0] ?? '🎖️';
$forceName = $forceMap[$forceKey][1] ?? ucfirst($forceKey);

// BMR & TDEE
$bmr = $gender === 'female'
    ? (10*$weight)+(6.25*$height)-(5*$age)-161
    : (10*$weight)+(6.25*$height)-(5*$age)+5;
$actMult = match($activity) {
    'sedentary'=>1.2,'light'=>1.375,'moderate'=>1.55,'active'=>1.725,'very_active'=>1.9,default=>1.55
};
$tdee        = (int)round($bmr * $actMult);
$targetCals  = $tdee + 350;
$waterL      = round($weight * 0.035 + 0.5, 1);

$macroSplits = [
    'british'  =>['p'=>0.30,'c'=>0.45,'f'=>0.25],
    'nepal'    =>['p'=>0.28,'c'=>0.50,'f'=>0.22],
    'indian'   =>['p'=>0.28,'c'=>0.48,'f'=>0.24],
    'singapore'=>['p'=>0.30,'c'=>0.45,'f'=>0.25],
    'french'   =>['p'=>0.32,'c'=>0.43,'f'=>0.25],
];
$split    = $macroSplits[$forceKey] ?? $macroSplits['british'];
$proteinG = (int)round(($targetCals * $split['p']) / 4);
$carbG    = (int)round(($targetCals * $split['c']) / 4);
$fatG     = (int)round(($targetCals * $split['f']) / 9);

// Meal schedule
$mealDist = [
    ['id'=>'breakfast',   'label'=>'Breakfast',     'icon'=>'🌅','time'=>'6:00 – 7:00 AM',  'tag'=>'Foundation'],
    ['id'=>'pre_workout', 'label'=>'Pre-Workout',   'icon'=>'⚡','time'=>'9:00 – 9:30 AM',  'tag'=>'Performance'],
    ['id'=>'lunch',       'label'=>'Lunch',         'icon'=>'☀️','time'=>'12:30 – 1:30 PM', 'tag'=>'Main Refuel'],
    ['id'=>'snack',       'label'=>'Snack',         'icon'=>'🍎','time'=>'4:00 – 4:30 PM',  'tag'=>'Top-up'],
    ['id'=>'dinner',      'label'=>'Dinner',        'icon'=>'🌙','time'=>'7:00 – 8:00 PM',  'tag'=>'Recovery'],
    ['id'=>'post_workout','label'=>'Post-Workout',  'icon'=>'💪','time'=>'Within 30 min',    'tag'=>'Repair'],
];

// [Food name, Quantity, kcal, protein g, carbs g, fat g]
$mealFoods = [
    'british' => [
        'breakfast'   => [['Rolled Oats','80g',297,8,54,6],['Whole Eggs','3 pcs',210,18,2,14],['Banana','1 medium',89,1,23,0],['Full-fat Milk','250ml',150,8,12,8],['Peanut Butter','1 tbsp',94,4,3,8]],
        'pre_workout' => [['White Rice / Roti','1 cup / 2 pcs',200,4,44,1],['Banana','1 pc',89,1,23,0],['Black Coffee','1 cup',5,0,1,0]],
        'lunch'       => [['Chicken Breast (grilled)','180g',297,56,0,6],['Brown Rice','1.5 cups',325,7,67,3],['Mixed Vegetables','200g',80,4,16,1],['Olive Oil','1 tsp',40,0,0,5],['Dal','1 bowl',180,12,30,2]],
        'snack'       => [['Greek Yoghurt','150g',130,15,8,4],['Mixed Nuts','25g',145,4,5,13],['Apple','1 medium',72,0,19,0]],
        'dinner'      => [['Salmon / Tuna','150g',280,38,0,14],['Sweet Potato','200g',180,4,41,0],['Spinach','100g',23,3,4,0],['Scrambled Eggs','2 pcs',140,12,2,10],['Whole Wheat Bread','2 slices',160,8,28,2]],
        'post_workout'=> [['Whey Protein','1 scoop 30g',120,24,3,2],['Banana','1 pc',89,1,23,0]],
    ],
    'nepal' => [
        'breakfast'   => [['Chiura','100g',346,7,77,1],['Boiled Eggs','3 pcs',210,18,2,14],['Dahi','200ml',120,10,9,5],['Sel Roti','1 pc',180,3,35,4],['Milk','200ml',120,7,10,6]],
        'pre_workout' => [['White Rice','1 cup',200,4,44,0],['Banana','1 pc',89,1,23,0],['Chiya','1 cup',60,2,8,2]],
        'lunch'       => [['Dal-Bhat','1 plate',500,18,90,6],['Tarkari','1 bowl',150,5,25,5],['Chicken Curry','150g',260,30,8,12],['Achar','2 tbsp',20,1,4,0]],
        'snack'       => [['Momo (steamed)','6 pcs',240,14,32,6],['Hard-boiled Eggs','2 pcs',140,12,2,10],['Seasonal Fruit','1 piece',80,1,20,0]],
        'dinner'      => [['White Rice','2 cups',400,8,88,1],['Mutton / Chicken','150g',300,28,5,18],['Mixed Dal','1 bowl',180,12,30,2],['Saag','150g',50,4,8,1]],
        'post_workout'=> [['Milk + Egg Shake','300ml + 2 eggs',310,26,18,14],['Banana','1 pc',89,1,23,0]],
    ],
    'indian' => [
        'breakfast'   => [['Paratha','2 pcs',300,8,50,9],['Boiled Eggs','3 pcs',210,18,2,14],['Dahi','150ml',90,8,7,4],['Lassi','250ml',170,10,22,5],['Mixed Fruit','1 bowl',100,1,25,0]],
        'pre_workout' => [['Banana','2 pcs',178,2,46,0],['Peanut Chikki','30g',140,4,18,6]],
        'lunch'       => [['Chapati','3 pcs',270,9,54,4],['Rajma / Dal','1 bowl',200,14,33,3],['Chicken / Paneer','150g',270,28,5,14],['Sabzi','1 bowl',120,4,18,5],['Rice','1 cup',200,4,44,0]],
        'snack'       => [['Boiled Chana','100g',164,9,27,3],['Eggs','2 pcs',140,12,2,10],['Buttermilk','250ml',50,3,5,2]],
        'dinner'      => [['Rice / Chapati','2 cups / 2 pcs',350,8,72,2],['Daal Tadka','1 bowl',210,13,33,4],['Egg Curry','150g',270,25,8,14],['Salad','1 bowl',60,2,12,1]],
        'post_workout'=> [['Milk + Banana','300ml + 1 pc',260,10,42,8],['Egg Whites','3 pcs',51,11,0,0]],
    ],
    'singapore' => [
        'breakfast'   => [['Rolled Oats','80g',297,10,54,6],['Eggs','3 pcs',210,18,2,14],['Banana','1 pc',89,1,23,0],['Low-fat Milk','250ml',110,9,12,3],['Mixed Nuts','20g',116,3,4,10]],
        'pre_workout' => [['White Rice','1 cup',200,4,44,0],['Banana','1 pc',89,1,23,0],['Isotonic Drink','300ml',75,0,19,0]],
        'lunch'       => [['Hainanese Chicken Rice','1 plate',450,28,58,12],['Steamed Veg','150g',60,3,12,1],['Fish / Tofu','150g',200,24,4,10],['Clear Soup','1 bowl',50,3,7,1]],
        'snack'       => [['Greek Yoghurt','150g',130,15,8,4],['Papaya / Mango','150g',80,1,20,0]],
        'dinner'      => [['Brown Rice','1.5 cups',325,7,67,3],['Grilled Fish','180g',240,36,0,10],['Mixed Veg','200g',80,4,16,1],['Poached Egg','2 pcs',140,12,2,10]],
        'post_workout'=> [['Protein Shake','1 scoop',120,24,3,2],['Banana','1 pc',89,1,23,0]],
    ],
    'french' => [
        'breakfast'   => [['Baguette','2 slices',160,6,32,1],['Omelette','3 eggs',230,18,2,16],['Emmental Cheese','30g',114,8,0,9],['Orange Juice','200ml',88,1,21,0],['Milk','200ml',120,7,10,6]],
        'pre_workout' => [['Rice / Pasta','1 cup',200,5,42,1],['Banana','1 pc',89,1,23,0],['Espresso','1 shot',5,0,1,0]],
        'lunch'       => [['Grilled Chicken','180g',297,56,0,6],['Pasta / Rice','1.5 cups',310,10,62,2],['Ratatouille','200g',120,3,20,4],['Olive Oil','1 tbsp',120,0,0,14],['Baguette','1 slice',80,3,16,0]],
        'snack'       => [['Natural Yoghurt','150g',100,8,10,3],['Mixed Nuts','25g',145,4,5,13],['Apple','1 pc',72,0,19,0]],
        'dinner'      => [['Fish / Beef','150g',280,34,0,14],['Potatoes / Rice','200g',170,4,38,0],['Green Beans','150g',50,3,10,0],['Cheese','30g',114,8,0,9]],
        'post_workout'=> [['Protein Shake / Milk','300ml',150,20,12,5],['Banana','1 pc',89,1,23,0]],
    ],
];

$foods = $mealFoods[$forceKey] ?? $mealFoods['british'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nutrition — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:   #3b82f6;
    --gold:     #fbbf24;
    --success:  #10b981;
    --error:    #ef4444;
    --t1:       #f8fafc;
    --t2:       #cbd5e1;
    --t3:       #64748b;
    --bg:       #0f172a;
    --surface:  rgba(30, 41, 59, 0.8);
    --surface-solid: #1e293b;
    --hover:    rgba(59, 130, 246, 0.08);
    --border:   rgba(255, 255, 255, 0.07);
    --border-hi:rgba(255, 255, 255, 0.12);
}

html { scroll-behavior: smooth; }
body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--t1);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── SIDEBAR (identical to dashboard.php) ── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: 260px; height: 100vh;
    background: rgba(15, 23, 42, 0.97);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    padding: 1.75rem 0; overflow-y: auto; z-index: 1000;
    transition: transform .3s ease;
}
.sidebar-header { padding: 0 1.25rem 1.25rem; border-bottom: 1px solid var(--border); }
.logo { display: flex; align-items: center; gap: 10px; margin-bottom: 1.1rem; }
.logo img { width: 34px; height: 34px; object-fit: contain; }
.brand-name {
    font-size: 1.25rem; font-weight: 800;
    background: linear-gradient(135deg, var(--gold), #f59e0b);
    background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.user-card {
    background: rgba(59, 130, 246, 0.07);
    border: 1px solid rgba(59, 130, 246, 0.18);
    border-radius: 12px; padding: .85rem;
    display: flex; align-items: center; gap: 10px;
}
.sidebar-av {
    width: 38px; height: 38px; border-radius: 50%; overflow: hidden;
    flex-shrink: 0; border: 1.5px solid rgba(59, 130, 246, 0.35);
    display: flex; align-items: center; justify-content: center;
}
.sidebar-av img { width: 100%; height: 100%; object-fit: cover; }
.user-details h3 { font-size: .85rem; font-weight: 600; }
.user-details p  { font-size: .7rem; color: var(--gold); margin-top: 1px; }
.nav-menu { padding: 1.25rem 0; }
.nav-section-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em;
    color: rgba(203, 213, 225, 0.35); text-transform: uppercase;
    padding: .4rem 1.25rem; margin-bottom: .2rem;
}
.nav-item {
    padding: .6rem 1.25rem; display: flex; align-items: center; gap: 11px;
    color: var(--t2); text-decoration: none;
    transition: all .2s; border-left: 2.5px solid transparent; font-size: .85rem;
}
.nav-item:hover, .nav-item.active {
    background: var(--hover); color: var(--t1);
    border-left-color: var(--accent);
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item.logout { color: #f87171; }
.nav-item.logout:hover { background: rgba(239,68,68,.08); border-left-color: var(--error); }

/* ── LAYOUT ── */
.main { margin-left: 260px; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar {
    background: var(--surface);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    padding: .9rem 1.75rem;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 50;
}
.bc { font-size: .75rem; color: var(--t3); display: flex; align-items: center; gap: 5px; }
.bc a { color: var(--t2); text-decoration: none; }
.bc a:hover { color: var(--accent); }
.tb-right { display: flex; align-items: center; gap: .65rem; }
.force-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: .3rem .75rem; border-radius: 20px; font-size: .73rem; font-weight: 600;
    background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.2); color: var(--gold);
}
.btn-ghost {
    padding: .45rem .9rem; border-radius: 8px; font-family: 'Poppins', sans-serif;
    font-size: .78rem; font-weight: 600; border: 1px solid var(--border);
    background: transparent; color: var(--t2); cursor: pointer; transition: all .18s;
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
}
.btn-ghost:hover { background: var(--surface-solid); color: var(--t1); }

/* ── PAGE ── */
.page { padding: 1.75rem; }

/* ── SUMMARY ROW ── */
.summary-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: .75rem; margin-bottom: 1.5rem;
}
.sum-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
    padding: 1rem 1.1rem; display: flex; align-items: center; gap: .85rem;
    transition: border-color .18s;
}
.sum-card:hover { border-color: var(--border-hi); }
.sum-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.sum-num { font-size: 1.1rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.sum-lbl { font-size: .63rem; color: var(--t3); margin-top: 2px; }

/* ── SECTION HEADER ── */
.sec-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: .9rem; }
.sec-title {
    font-size: .65rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--t3);
    display: flex; align-items: center; gap: .5rem;
}
.sec-title::after { content: ''; flex: 0 0 24px; height: 1px; background: var(--border); }
.sec-badge {
    padding: .2rem .6rem; border-radius: 6px; font-size: .67rem; font-weight: 600;
    background: rgba(251,191,36,.1); border: 1px solid rgba(251,191,36,.18); color: var(--gold);
}

/* ── MEAL CARDS ── */
.meal-list { display: flex; flex-direction: column; gap: .6rem; }

.meal-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
    overflow: hidden; transition: border-color .18s;
}
.meal-card:hover { border-color: var(--border-hi); }
.meal-card.open { border-color: rgba(59,130,246,.25); }

.meal-header {
    display: flex; align-items: center; gap: .85rem;
    padding: .85rem 1.1rem; cursor: pointer; user-select: none;
}
.meal-header:hover { background: rgba(255,255,255,.02); }

.meal-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.mi-breakfast    { background: rgba(249,115,22,.1); }
.mi-pre_workout  { background: rgba(245,200,66,.1); }
.mi-lunch        { background: rgba(59,130,246,.1); }
.mi-snack        { background: rgba(16,185,129,.1); }
.mi-dinner       { background: rgba(139,92,246,.1); }
.mi-post_workout { background: rgba(13,148,136,.1); }

.meal-info { flex: 1; min-width: 0; }
.meal-name { font-size: .88rem; font-weight: 600; }
.meal-meta { display: flex; align-items: center; gap: .5rem; margin-top: .15rem; }
.meal-time { font-size: .68rem; color: var(--t3); }
.meal-tag  {
    padding: .1rem .42rem; border-radius: 4px; font-size: .62rem; font-weight: 600;
    background: rgba(251,191,36,.08); color: var(--gold);
}
.meal-kcal      { text-align: right; flex-shrink: 0; }
.meal-kcal-num  { font-size: .95rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.meal-kcal-lbl  { font-size: .6rem; color: var(--t3); }
.meal-chevron   {
    width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); display: flex; align-items: center;
    justify-content: center; font-size: .6rem; color: var(--t3);
    transition: transform .22s, color .18s; flex-shrink: 0; margin-left: .35rem;
}
.meal-card.open .meal-chevron { transform: rotate(180deg); color: var(--accent); }

/* ── MEAL BODY ── */
.meal-body { display: none; padding: 0 1.1rem .95rem; }
.meal-card.open .meal-body { display: block; }

.macro-pills { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: .7rem; }
.mpill {
    padding: .15rem .52rem; border-radius: 4px; font-size: .67rem; font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.mp-kcal { background: rgba(249,115,22,.1);  color: #fb923c; }
.mp-p    { background: rgba(59,130,246,.1);  color: #93c5fd; }
.mp-c    { background: rgba(251,191,36,.1);  color: #fcd34d; }
.mp-f    { background: rgba(13,148,136,.1);  color: #5eead4; }

.food-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
.food-table th {
    font-size: .59rem; font-weight: 600; letter-spacing: .09em; text-transform: uppercase;
    color: var(--t3); padding: .3rem .45rem; text-align: left; border-bottom: 1px solid var(--border);
}
.food-table th:not(:first-child) { text-align: center; }
.food-table td {
    padding: .45rem .45rem; border-bottom: 1px solid rgba(255,255,255,.03); vertical-align: middle;
}
.food-table td:not(:first-child) { text-align: center; font-variant-numeric: tabular-nums; color: var(--t2); font-size: .73rem; }
.food-table tr:last-child td { border-bottom: none; }
.food-table tbody tr:hover td { background: rgba(255,255,255,.02); }
.f-name { font-weight: 600; color: var(--t1); font-size: .8rem; }
.f-qty  { font-size: .67rem; color: var(--t3); margin-top: 1px; }

/* ── NOTE ── */
.note {
    display: flex; gap: .6rem; padding: .65rem .9rem; border-radius: 8px;
    margin-bottom: .9rem; font-size: .77rem; line-height: 1.55;
    background: rgba(59,130,246,.07); border: 1px solid rgba(59,130,246,.15); color: #7ab8ff;
}

/* ── MOBILE ── */
.mobile-fab {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main { margin-left: 0; }
    .page { padding: 1rem; }
    .topbar { padding: .8rem 1rem; }
    .summary-row { grid-template-columns: 1fr 1fr; }
    .mobile-fab { display: flex; align-items: center; justify-content: center; }
}
@media (max-width: 480px) {
    .summary-row { grid-template-columns: 1fr 1fr; }
}

/* ── ANIMATIONS ── */
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.fade { animation: fadeIn .35s ease both; }
.d1 { animation-delay: .04s; } .d2 { animation-delay: .09s; }
.d3 { animation-delay: .14s; } .d4 { animation-delay: .18s; }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="/gurkha-marga/frontend/image/gurkhalogo.png" alt="Logo" onerror="this.style.display='none'">
            <span class="brand-name">Gurkha Marga</span>
        </div>
        <div class="user-card">
            <div class="sidebar-av" id="sbAvContainer"></div>
            <div class="user-details">
                <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                <p><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></p>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section-title">Main</div>
        <a href="dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Dashboard
        </a>
        <a href="workouts.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Workouts
        </a>
        <a href="progress.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Progress
        </a>
        <a href="nutrition.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Nutrition
        </a>
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
        </a>
        <a href="documents.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Documents
        </a>
        <a href="motivation.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
            Motivation
        </a>
       <div class="nav-section-title" style="margin-top: .75rem">Account</div>
        <a href="profile.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Profile
        </a>
        <a href="settings.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Settings
        </a>
        <!-- ★ NEW — Subscription link added below Settings -->
        <a href="subscription_fixed.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            Subscription
        </a>
        <!-- ★ END NEW -->
        <a href="../auth/logout.php" class="nav-item logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>
</aside>

<!-- ── MAIN ── -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <nav class="bc">
            <a href="dashboard.php">Dashboard</a>
            <span>/</span>
            <span>Nutrition</span>
        </nav>
        <div class="tb-right">
            <span class="force-badge"><?= $forceFlag ?> <?= htmlspecialchars($forceName) ?></span>
            <button class="btn-ghost" onclick="window.print()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print
            </button>
        </div>
    </header>

    <div class="page">

        <!-- SUMMARY ROW -->
        <div class="summary-row fade d1">
            <div class="sum-card">
                <div class="sum-icon" style="background:rgba(249,115,22,.1)">🔥</div>
                <div>
                    <div class="sum-num" style="color:#fb923c"><?= number_format($targetCals) ?></div>
                    <div class="sum-lbl">Target kcal / day</div>
                </div>
            </div>
            <div class="sum-card">
                <div class="sum-icon" style="background:rgba(59,130,246,.1)">💪</div>
                <div>
                    <div class="sum-num" style="color:#93c5fd"><?= $proteinG ?>g</div>
                    <div class="sum-lbl">Protein · <?= round($split['p']*100) ?>%</div>
                </div>
            </div>
            <div class="sum-card">
                <div class="sum-icon" style="background:rgba(251,191,36,.1)">🌾</div>
                <div>
                    <div class="sum-num" style="color:#fcd34d"><?= $carbG ?>g</div>
                    <div class="sum-lbl">Carbs · <?= round($split['c']*100) ?>%</div>
                </div>
            </div>
            <div class="sum-card">
                <div class="sum-icon" style="background:rgba(6,182,212,.1)">💧</div>
                <div>
                    <div class="sum-num" style="color:#67e8f9"><?= $waterL ?>L</div>
                    <div class="sum-lbl">Water / day</div>
                </div>
            </div>
        </div>

        <!-- MEAL PLAN -->
        <div class="fade d2">
            <div class="sec-header">
                <div class="sec-title">Daily Meal Plan</div>
                <span class="sec-badge"><?= htmlspecialchars($firstName) ?>'s Plan</span>
            </div>

            <div class="meal-list">
            <?php foreach ($mealDist as $meal):
                $mealId = $meal['id'];
                if (!isset($foods[$mealId])) continue;
                $items  = $foods[$mealId];
                $mCal = $mP = $mC = $mF = 0;
                foreach ($items as $i) { $mCal += $i[2]; $mP += $i[3]; $mC += $i[4]; $mF += $i[5]; }
            ?>
            <div class="meal-card" id="mc-<?= $mealId ?>">
                <div class="meal-header" onclick="toggleMeal('<?= $mealId ?>')">
                    <div class="meal-icon mi-<?= $mealId ?>"><?= $meal['icon'] ?></div>
                    <div class="meal-info">
                        <div class="meal-name"><?= $meal['label'] ?></div>
                        <div class="meal-meta">
                            <span class="meal-time"><?= $meal['time'] ?></span>
                            <span class="meal-tag"><?= $meal['tag'] ?></span>
                        </div>
                    </div>
                    <div class="meal-kcal">
                        <div class="meal-kcal-num"><?= $mCal ?></div>
                        <div class="meal-kcal-lbl">kcal</div>
                    </div>
                    <div class="meal-chevron">▼</div>
                </div>
                <div class="meal-body">
                    <div class="macro-pills">
                        <span class="mpill mp-kcal"><?= $mCal ?> kcal</span>
                        <span class="mpill mp-p"><?= $mP ?>g protein</span>
                        <span class="mpill mp-c"><?= $mC ?>g carbs</span>
                        <span class="mpill mp-f"><?= $mF ?>g fat</span>
                    </div>
                    <table class="food-table">
                        <thead>
                            <tr>
                                <th>Food</th>
                                <th>kcal</th>
                                <th>Protein</th>
                                <th>Carbs</th>
                                <th>Fat</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div class="f-name"><?= htmlspecialchars($item[0]) ?></div>
                                    <div class="f-qty"><?= htmlspecialchars($item[1]) ?></div>
                                </td>
                                <td><?= $item[2] ?></td>
                                <td><?= $item[3] ?>g</td>
                                <td><?= $item[4] ?>g</td>
                                <td><?= $item[5] ?>g</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /page -->
</div><!-- /main -->

<button class="mobile-fab" onclick="document.getElementById('sidebar').classList.toggle('active')">☰</button>

<script>
/* ── Avatar (identical to dashboard.php) ── */
const SAVED_AVATAR_TYPE   = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL     = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CONFIG = <?= $avatarConfigJson ?>;
const USER_INITIAL        = <?= json_encode($initials) ?>;

const AV_OPTIONS = {
    bg: [
        {id:'grad1',grad:['#1e3a5f','#2563eb']},{id:'grad2',grad:['#14532d','#16a34a']},
        {id:'grad3',grad:['#7f1d1d','#dc2626']},{id:'grad4',grad:['#312e81','#7c3aed']},
        {id:'grad5',grad:['#78350f','#d97706']},{id:'grad6',grad:['#134e4a','#0d9488']},
        {id:'grad7',grad:['#0f172a','#1e293b']},{id:'grad8',grad:['#4c0519','#e11d48']},
    ],
    skin:[
        {id:'s1',color:'#FDDBB4'},{id:'s2',color:'#F5C89A'},{id:'s3',color:'#D4956A'},
        {id:'s4',color:'#C47C45'},{id:'s5',color:'#A05C2C'},{id:'s6',color:'#7D3F17'},
        {id:'s7',color:'#5C2B0D'},{id:'s8',color:'#3B1A08'},
    ],
    hairColor:[
        {id:'black',color:'#1a1a1a'},{id:'brown',color:'#5C3A1E'},{id:'auburn',color:'#922B21'},
        {id:'blonde',color:'#D4A843'},{id:'gray',color:'#9CA3AF'},{id:'white',color:'#F5F5F5'},
        {id:'red',color:'#B91C1C'},{id:'blue',color:'#1D4ED8'},{id:'purple',color:'#7C3AED'},{id:'green',color:'#15803D'},
    ],
    eyes:[
        {id:'brown',color:'#6B3F1A'},{id:'hazel',color:'#8B6914'},{id:'green',color:'#15803D'},
        {id:'blue',color:'#1D4ED8'},{id:'gray',color:'#6B7280'},{id:'black',color:'#111827'},
        {id:'amber',color:'#D97706'},{id:'violet',color:'#7C3AED'},
    ],
};

function drawAvatarOnCanvas(canvas, state, size) {
    const ctx = canvas.getContext('2d');
    canvas.width = size; canvas.height = size;
    const cx = size/2, cy = size/2;
    const bg   = AV_OPTIONS.bg.find(o=>o.id===state.bg)            || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o=>o.id===state.skin)         || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o=>o.id===state.hairColor)|| AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o=>o.id===state.eyes)         || AV_OPTIONS.eyes[0];
    const acc  = state.accessories || [];
    const hStyle = state.hairStyle || 'short';
    const grad = ctx.createLinearGradient(0,0,size,size);
    grad.addColorStop(0,bg.grad[0]); grad.addColorStop(1,bg.grad[1]);
    ctx.fillStyle=grad; ctx.beginPath(); ctx.arc(cx,cy,cx,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath(); ctx.roundRect(cx-14,cy+32,28,22,[4,4,0,0]); ctx.fill();
    const shirt=ctx.createLinearGradient(cx-55,cy+50,cx+55,size);
    shirt.addColorStop(0,'#1e3a5f'); shirt.addColorStop(1,'#0f172a');
    ctx.fillStyle=shirt; ctx.beginPath(); ctx.ellipse(cx,cy+62,58,28,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=skin.color;
    ctx.beginPath(); ctx.ellipse(cx,cy+2,44,52,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx-44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+44,cy+5,8,11,0,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle=hCol.color==='#F5F5F5'?'#C0A080':hCol.color;
    ctx.lineWidth=3.5; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-28,cy-16); ctx.quadraticCurveTo(cx-16,cy-20,cx-7,cy-16); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx+7,cy-16);  ctx.quadraticCurveTo(cx+16,cy-20,cx+28,cy-16); ctx.stroke();
    ctx.fillStyle='#fff';
    ctx.beginPath(); ctx.ellipse(cx-16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(cx+16,cy-4,11,9,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle=eyeC.color;
    ctx.beginPath(); ctx.arc(cx-16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,6.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='#000';
    ctx.beginPath(); ctx.arc(cx-16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+16,cy-4,3.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle='rgba(255,255,255,0.6)';
    ctx.beginPath(); ctx.arc(cx-13,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(cx+19,cy-7,2.2,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle='rgba(0,0,0,0.18)'; ctx.lineWidth=2; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(cx-3,cy+2); ctx.lineTo(cx,cy+12); ctx.lineTo(cx+3,cy+2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(cx-9,cy+14); ctx.quadraticCurveTo(cx,cy+17,cx+9,cy+14); ctx.stroke();
    ctx.strokeStyle='rgba(0,0,0,0.3)'; ctx.lineWidth=2.5;
    ctx.beginPath(); ctx.moveTo(cx-14,cy+24); ctx.quadraticCurveTo(cx,cy+30,cx+14,cy+24); ctx.stroke();
    ctx.fillStyle=hCol.color;
    if(hStyle==='bald'){}
    else if(hStyle==='medium'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.ellipse(cx-44,cy+8,9,32,-0.2,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+44,cy+8,9,32,0.2,-Math.PI/2,Math.PI/2,true); ctx.fill();
    } else if(hStyle==='long'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,46,24,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-46,cy-44,92,22);
        ctx.beginPath(); ctx.roundRect(cx-50,cy-10,12,75,6); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx+38,cy-10,12,75,6); ctx.fill();
    } else if(hStyle==='curly'){
        for(let a=0;a<Math.PI*2;a+=0.35){
            const rx=cx+Math.cos(a)*42,ry=(cy-30)+Math.sin(a)*24;
            if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}
        }
        ctx.beginPath(); ctx.ellipse(cx,cy-42,40,18,0,Math.PI,0); ctx.fill();
    } else if(hStyle==='bun'){
        ctx.beginPath(); ctx.ellipse(cx,cy-40,44,20,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-43,88,18);
        ctx.beginPath(); ctx.arc(cx,cy-56,14,0,Math.PI*2); ctx.fill();
    } else if(hStyle==='mohawk'){
        ctx.beginPath(); ctx.moveTo(cx-10,cy-40); ctx.lineTo(cx,cy-80); ctx.lineTo(cx+10,cy-40); ctx.closePath(); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-10,cy-50,20,14,2); ctx.fill();
    } else {
        ctx.beginPath(); ctx.ellipse(cx,cy-38,44,22,0,Math.PI,0); ctx.fill();
        ctx.fillRect(cx-44,cy-40,88,20);
        ctx.beginPath(); ctx.ellipse(cx-42,cy-6,8,22,-0.15,-Math.PI/2,Math.PI/2); ctx.fill();
        ctx.beginPath(); ctx.ellipse(cx+42,cy-6,8,22,0.15,-Math.PI/2,Math.PI/2,true); ctx.fill();
    }
    if(acc.includes('beard')){
        ctx.fillStyle=hCol.color;
        ctx.beginPath(); ctx.ellipse(cx,cy+36,30,18,0,0,Math.PI); ctx.fill();
        ctx.beginPath(); ctx.roundRect(cx-30,cy+18,60,20,4); ctx.fill();
    }
    if(acc.includes('glasses')){
        ctx.strokeStyle='#64748b'; ctx.lineWidth=2.5; ctx.fillStyle='rgba(147,197,253,0.2)';
        ctx.beginPath(); ctx.roundRect(cx-32,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-14,24,18,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-5); ctx.lineTo(cx+8,cy-5); ctx.stroke();
    }
    if(acc.includes('sunglasses')){
        ctx.fillStyle='#111827'; ctx.strokeStyle='#374151'; ctx.lineWidth=2;
        ctx.beginPath(); ctx.roundRect(cx-34,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.roundRect(cx+8,cy-15,26,16,5); ctx.fill(); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(cx-8,cy-7); ctx.lineTo(cx+8,cy-7); ctx.stroke();
    }
    const ring=ctx.createLinearGradient(0,0,size,size);
    ring.addColorStop(0,bg.grad[0]+'88'); ring.addColorStop(1,bg.grad[1]+'88');
    ctx.strokeStyle=ring; ctx.lineWidth=size<60?2:4;
    ctx.beginPath(); ctx.arc(cx,cy,cx-2,0,Math.PI*2); ctx.stroke();
}

function renderSidebarAvatar() {
    const container = document.getElementById('sbAvContainer');
    if (!container) return;
    container.innerHTML = '';
    if (SAVED_AVATAR_TYPE === 'photo' && SAVED_PHOTO_URL) {
        const img = document.createElement('img');
        img.src = SAVED_PHOTO_URL;
        img.style.cssText = 'width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror = () => renderInitialSb(container);
        container.appendChild(img);
    } else if (SAVED_AVATAR_TYPE === 'ai' && SAVED_AVATAR_CONFIG) {
        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'width:38px;height:38px;border-radius:50%;display:block';
        container.appendChild(canvas);
        drawAvatarOnCanvas(canvas, SAVED_AVATAR_CONFIG, 38);
    } else {
        renderInitialSb(container);
    }
}
function renderInitialSb(container) {
    const d = document.createElement('div');
    d.style.cssText = 'width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent = USER_INITIAL;
    container.appendChild(d);
}

/* ── Meal toggle ── */
function toggleMeal(id) {
    const card = document.getElementById('mc-' + id);
    const wasOpen = card.classList.contains('open');
    document.querySelectorAll('.meal-card.open').forEach(c => c.classList.remove('open'));
    if (!wasOpen) card.classList.add('open');
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
    renderSidebarAvatar();
    // Open first meal by default
    const first = document.querySelector('.meal-card');
    if (first) first.classList.add('open');
    // Close sidebar on outside click (mobile)
    document.addEventListener('click', e => {
        const sb = document.getElementById('sidebar');
        const fab = document.querySelector('.mobile-fab');
        if (window.innerWidth <= 768 && sb && fab && !sb.contains(e.target) && !fab.contains(e.target)) {
            sb.classList.remove('active');
        }
    });
});
</script>
</body>
</html>