<?php
// frontend/users/documents.php
session_start();

define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$user   = fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1", [$userId]);
if (!$user) { session_destroy(); header('Location: ../auth/login.php'); exit(); }

$forceMap = [
    'british'   => ['name' => 'British Army',           'unit' => 'Brigade of Gurkhas'],
    'nepal'     => ['name' => 'Nepal Army',              'unit' => 'Nepal Army Recruitment'],
    'indian'    => ['name' => 'Indian Army',             'unit' => 'Indian Gorkha Rifles'],
    'singapore' => ['name' => 'Singapore Police Force',  'unit' => 'SPF Gurkha Contingent'],
    'french'    => ['name' => 'French Foreign Legion',   'unit' => 'Legion Etrangere'],
];

$forceKey  = $user['target_force'] ?? 'british';
$forceData = $forceMap[$forceKey] ?? $forceMap['british'];
$forceName = $forceData['name'];
$forceUnit = $forceData['unit'];

$expLabel  = ucfirst($user['experience_level'] ?? 'beginner');
$firstName = explode(' ', $user['full_name'])[0];
$initials  = strtoupper(substr($user['full_name'], 0, 1));

$avatarType   = $user['avatar_type']   ?? 'initial';
$profilePhoto = $user['profile_photo'] ?? null;
$avatarConfig = !empty($user['avatar_config']) ? json_decode($user['avatar_config'], true) : null;
$photoUrl     = $profilePhoto ? '/gurkha-marga/frontend/uploads/avatars/' . htmlspecialchars($profilePhoto) : null;
$avatarConfigJson = $avatarConfig ? json_encode($avatarConfig) : 'null';

$allDocs = [
    'british' => [
        ['id'=>'bp1',  'name'=>'Valid Passport',               'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Min 6 months', 'notes'=>'Must be machine-readable. All pages photocopied.'],
        ['id'=>'bp2',  'name'=>'Birth Certificate',            'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Certified translation if not in English.'],
        ['id'=>'bp3',  'name'=>'Citizenship Certificate',      'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Nepali citizenship required.'],
        ['id'=>'bp4',  'name'=>'School Leaving Certificate',   'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'SLC or equivalent minimum.'],
        ['id'=>'bp5',  'name'=>'Academic Transcripts',         'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'All educational qualifications.'],
        ['id'=>'bp6',  'name'=>'Medical Certificate',          'category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'3 months',     'notes'=>'From registered government hospital.'],
        ['id'=>'bp7',  'name'=>'Police Clearance Certificate', 'category'=>'Legal',     'required'=>true,  'copies'=>1, 'validity'=>'6 months',     'notes'=>'District Police Office.'],
        ['id'=>'bp8',  'name'=>'Character Certificate',        'category'=>'Character', 'required'=>true,  'copies'=>2, 'validity'=>'3 months',     'notes'=>'From Ward or Municipality.'],
        ['id'=>'bp9',  'name'=>'Passport-size Photos',         'category'=>'Identity',  'required'=>true,  'copies'=>8, 'validity'=>'Recent',       'notes'=>'White background, formal attire.'],
        ['id'=>'bp10', 'name'=>'Height / Weight Certificate',  'category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'1 month',      'notes'=>'Issued by health post or hospital.'],
        ['id'=>'bp11', 'name'=>'Chest X-Ray Report',           'category'=>'Medical',   'required'=>false, 'copies'=>1, 'validity'=>'3 months',     'notes'=>'If requested at medical stage.'],
        ['id'=>'bp12', 'name'=>'Eye Test Certificate',         'category'=>'Medical',   'required'=>false, 'copies'=>1, 'validity'=>'3 months',     'notes'=>'Vision must meet army standards.'],
    ],
    'nepal' => [
        ['id'=>'np1',  'name'=>'Citizenship Certificate',      'category'=>'Identity',  'required'=>true,  'copies'=>3, 'validity'=>'Original',     'notes'=>'Must be Nepali citizen.'],
        ['id'=>'np2',  'name'=>'Birth Certificate',            'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Ward-level birth registration.'],
        ['id'=>'np3',  'name'=>'SLC / SEE Certificate',        'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Minimum Second Division.'],
        ['id'=>'np4',  'name'=>'Academic Marksheets',          'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Grade 10 and above.'],
        ['id'=>'np5',  'name'=>'Medical Certificate',          'category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'1 month',      'notes'=>'Government hospital only.'],
        ['id'=>'np6',  'name'=>'Character Certificate',        'category'=>'Character', 'required'=>true,  'copies'=>2, 'validity'=>'3 months',     'notes'=>'Ward or Municipality office.'],
        ['id'=>'np7',  'name'=>'Police Clearance',             'category'=>'Legal',     'required'=>true,  'copies'=>1, 'validity'=>'3 months',     'notes'=>'District Police Office.'],
        ['id'=>'np8',  'name'=>'Passport-size Photos',         'category'=>'Identity',  'required'=>true,  'copies'=>6, 'validity'=>'Recent',       'notes'=>'White background.'],
        ['id'=>'np9',  'name'=>'Ethnicity Certificate',        'category'=>'Identity',  'required'=>false, 'copies'=>1, 'validity'=>'Original',     'notes'=>'For reserved quota applicants.'],
        ['id'=>'np10', 'name'=>'Temporary Residence Proof',    'category'=>'Address',   'required'=>false, 'copies'=>1, 'validity'=>'3 months',     'notes'=>'If residing outside home district.'],
    ],
    'indian' => [
        ['id'=>'ip1',  'name'=>'Indian Citizenship Proof',     'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Aadhaar or Voter ID.'],
        ['id'=>'ip2',  'name'=>'Birth Certificate',            'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Municipal birth record.'],
        ['id'=>'ip3',  'name'=>'10th Board Certificate',       'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'CBSE / State board accepted.'],
        ['id'=>'ip4',  'name'=>'School Marksheets',            'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Class 8 onwards.'],
        ['id'=>'ip5',  'name'=>'Domicile Certificate',         'category'=>'Address',   'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'From State Revenue Office.'],
        ['id'=>'ip6',  'name'=>'Medical Fitness Certificate',  'category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'6 months',     'notes'=>'Govt. registered MBBS doctor.'],
        ['id'=>'ip7',  'name'=>'Caste Certificate',            'category'=>'Identity',  'required'=>false, 'copies'=>2, 'validity'=>'Original',     'notes'=>'Required for SC/ST/OBC quota.'],
        ['id'=>'ip8',  'name'=>'Passport-size Photos',         'category'=>'Identity',  'required'=>true,  'copies'=>10,'validity'=>'Recent',       'notes'=>'White background, 4.5x3.5cm.'],
        ['id'=>'ip9',  'name'=>'Police Verification Report',   'category'=>'Legal',     'required'=>true,  'copies'=>1, 'validity'=>'6 months',     'notes'=>'Local Police Station.'],
        ['id'=>'ip10', 'name'=>'Character Certificate',        'category'=>'Character', 'required'=>true,  'copies'=>2, 'validity'=>'3 months',     'notes'=>'From headmaster or gazetted officer.'],
    ],
    'singapore' => [
        ['id'=>'sp1',  'name'=>'Valid Passport',               'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Min 2 years',  'notes'=>'Nepali passport required.'],
        ['id'=>'sp2',  'name'=>'Birth Certificate',            'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Certified',    'notes'=>'With certified English translation.'],
        ['id'=>'sp3',  'name'=>'Citizenship Certificate',      'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Nepali citizenship.'],
        ['id'=>'sp4',  'name'=>'SLC or Equivalent',            'category'=>'Education', 'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'Minimum educational requirement.'],
        ['id'=>'sp5',  'name'=>'Medical Certificate',          'category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'3 months',     'notes'=>'Comprehensive medical from hospital.'],
        ['id'=>'sp6',  'name'=>'Police Clearance',             'category'=>'Legal',     'required'=>true,  'copies'=>1, 'validity'=>'3 months',     'notes'=>'Clean criminal record required.'],
        ['id'=>'sp7',  'name'=>'Character Certificate',        'category'=>'Character', 'required'=>true,  'copies'=>2, 'validity'=>'3 months',     'notes'=>'Local government authority.'],
        ['id'=>'sp8',  'name'=>'Passport Photos',              'category'=>'Identity',  'required'=>true,  'copies'=>6, 'validity'=>'Recent',       'notes'=>'White background, recent.'],
        ['id'=>'sp9',  'name'=>'Height / Weight / Chest Cert.','category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'1 month',      'notes'=>'Must meet SPF physical standards.'],
        ['id'=>'sp10', 'name'=>'No Objection Certificate',     'category'=>'Legal',     'required'=>false, 'copies'=>1, 'validity'=>'6 months',     'notes'=>'From Nepal government if applicable.'],
    ],
    'french' => [
        ['id'=>'fp1',  'name'=>'Valid Passport',               'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Valid',        'notes'=>'Any nationality accepted.'],
        ['id'=>'fp2',  'name'=>'Birth Certificate',            'category'=>'Identity',  'required'=>true,  'copies'=>2, 'validity'=>'Original',     'notes'=>'With French translation if needed.'],
        ['id'=>'fp3',  'name'=>'Criminal Record',              'category'=>'Legal',     'required'=>true,  'copies'=>1, 'validity'=>'3 months',     'notes'=>'Clean record mandatory.'],
        ['id'=>'fp4',  'name'=>'Medical Certificate',          'category'=>'Medical',   'required'=>true,  'copies'=>1, 'validity'=>'1 month',      'notes'=>'Full physical examination.'],
        ['id'=>'fp5',  'name'=>'Academic Certificates',        'category'=>'Education', 'required'=>false, 'copies'=>2, 'validity'=>'Original',     'notes'=>'Higher education is advantageous.'],
        ['id'=>'fp6',  'name'=>'Passport Photos',              'category'=>'Identity',  'required'=>true,  'copies'=>6, 'validity'=>'Recent',       'notes'=>'ICAO standard format.'],
        ['id'=>'fp7',  'name'=>'Previous Military Records',    'category'=>'Military',  'required'=>false, 'copies'=>1, 'validity'=>'Original',     'notes'=>'If previously served in any force.'],
        ['id'=>'fp8',  'name'=>'Proof of Address',             'category'=>'Address',   'required'=>false, 'copies'=>1, 'validity'=>'3 months',     'notes'=>'Utility bill or bank statement.'],
        ['id'=>'fp9',  'name'=>'Language Proficiency Proof',   'category'=>'Education', 'required'=>false, 'copies'=>1, 'validity'=>'2 years',      'notes'=>'French language certificate if any.'],
    ],
];

$docs     = $allDocs[$forceKey] ?? $allDocs['british'];
$required = array_filter($docs, fn($d) => $d['required']);
$optional = array_filter($docs, fn($d) => !$d['required']);
$reqCount = count($required);
$optCount = count($optional);
$totalDocs = count($docs);

$timelines = [
    'british' => [
        ['Registration',         'Submit online application via British Army portal. Confirm all documents are in order.'],
        ['Preliminary Round',    'Height, weight, chest measurement check at Dhulikhel. First document submission.'],
        ['Written Test',         'English and mathematics examination. Document folder must be available.'],
        ['Physical Fitness Test','5 km run, pull-ups, sit-ups. Keep all documents in your bag.'],
        ['Medical Examination',  'Full body check, X-ray, eye test. Present all medical documents.'],
        ['Final Selection Board','Interview, character assessment, final document review.'],
        ['Offer and Enlistment', 'Successful candidates receive offer and proceed to Catterick training.'],
    ],
    'nepal' => [
        ['Online Registration',   'Register on Nepal Army portal with scanned documents.'],
        ['Document Verification', 'Physical document check at district army barracks.'],
        ['Physical Test',         '1600 m run, pull-ups, sit-ups, push-ups at recruitment ground.'],
        ['Written Examination',   'Nepali, Maths, General Knowledge — multiple choice and written.'],
        ['Medical Board',         'Hospital medical check — all medical documents required.'],
        ['Final Merit List',      'Selected based on aggregate marks and physical scores.'],
    ],
    'indian' => [
        ['Rally Registration',  'Register at nearest Army Recruitment Rally.'],
        ['Document Screening',  'Original document verification at rally venue.'],
        ['Physical Fitness Test','1.6 km run, beam, 9 feet ditch jump, zigzag balance.'],
        ['Medical Examination', 'Conducted at Military Hospital — bring all medical documents.'],
        ['Written Test (CEE)',  'Common Entrance Exam at designated exam centres.'],
        ['Merit and Enrolment', 'Final merit list published. Report for training.'],
    ],
    'singapore' => [
        ['Online Application',   'Apply via SPF Gurkha Contingent official portal.'],
        ['Shortlisting',         'Based on age, height, weight, education criteria.'],
        ['Physical Assessment',  'Run, pull-ups, sit-ups to Singapore police standards.'],
        ['Document Verification','All original documents checked at Gurkha Camp.'],
        ['Medical Examination',  'Comprehensive medical at SAF / SPF medical centre.'],
        ['Offer and Work Permit','Successful candidates receive work permit and contract.'],
    ],
    'french' => [
        ['Walk-In Recruitment',  'Arrive at nearest Legion recruitment post with documents.'],
        ['Initial Assessment',   'Identity check, medical screening, psychological test.'],
        ['Security Clearance',   'Background checks and criminal record verification.'],
        ['Physical Tests',       'CCPM fitness test, sports aptitude evaluation.'],
        ['Selection Board',      'Final interview with Legion selection committee.'],
        ['Enlistment',           'Sign 5-year contract. Begin basic training at Castelnaudary.'],
    ],
];
$tl = $timelines[$forceKey] ?? $timelines['british'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documents — Gurkha Marga</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --accent:  #3b82f6;
    --gold:    #fbbf24;
    --success: #10b981;
    --error:   #ef4444;
    --warning: #f59e0b;
    --t1:      #f8fafc;
    --t2:      #cbd5e1;
    --t3:      #64748b;
    --bg:      #0f172a;
    --surface: rgba(30, 41, 59, 0.8);
    --surface-solid: #1e293b;
    --hover:   rgba(59, 130, 246, 0.08);
    --border:  rgba(255, 255, 255, 0.07);
    --border-hi: rgba(255, 255, 255, 0.12);
}

html { scroll-behavior: smooth; }
body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: var(--t1);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── SIDEBAR ── */
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
    color: rgba(203,213,225,.35); text-transform: uppercase;
    padding: .4rem 1.25rem; margin-bottom: .2rem;
}
.nav-item {
    padding: .6rem 1.25rem; display: flex; align-items: center; gap: 11px;
    color: var(--t2); text-decoration: none;
    transition: all .2s; border-left: 2.5px solid transparent; font-size: .85rem;
}
.nav-item:hover, .nav-item.active {
    background: var(--hover); color: var(--t1); border-left-color: var(--accent);
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item.logout { color: #f87171; }
.nav-item.logout:hover { background: rgba(239,68,68,.08); border-left-color: var(--error); }

/* ── LAYOUT ── */
.main { margin-left: 260px; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar {
    background: var(--surface); backdrop-filter: blur(20px);
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
.page { padding: 1.75rem; max-width: 900px; }

/* ── SECTION HEADERS ── */
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
.sec-badge.red {
    background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.2); color: #fca5a5;
}

/* ── FORCE BANNER ── */
.force-banner {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem;
    background: rgba(59, 130, 246, 0.07); border: 1px solid rgba(59, 130, 246, 0.2);
}
.force-banner-left { display: flex; align-items: center; gap: .85rem; }
.force-banner-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    background: rgba(59, 130, 246, 0.12); display: flex; align-items: center; justify-content: center;
}
.force-banner-icon svg { width: 18px; height: 18px; stroke: #93c5fd; fill: none; }
.force-banner-title { font-size: .85rem; font-weight: 600; color: #93c5fd; }
.force-banner-sub   { font-size: .7rem; color: var(--t3); margin-top: 2px; }
.force-stats { display: flex; align-items: center; gap: 1.5rem; flex-shrink: 0; }
.force-stat { text-align: center; }
.force-stat-num   { font-size: 1.25rem; font-weight: 700; line-height: 1; }
.force-stat-label { font-size: .62rem; color: var(--t3); margin-top: 2px; text-transform: uppercase; letter-spacing: .06em; }
.stat-divider { width: 1px; height: 28px; background: var(--border); }

/* ── PROGRESS ── */
.progress-row {
    display: flex; align-items: center; gap: 1rem;
    padding: .75rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem;
    background: var(--surface); border: 1px solid var(--border);
}
.prog-label { font-size: .72rem; font-weight: 600; color: var(--t3); white-space: nowrap; width: 80px; }
.prog-bar { flex: 1; height: 4px; background: rgba(255,255,255,.06); border-radius: 2px; overflow: hidden; }
.prog-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--success), #34d399); transition: width .8s ease; width: 0%; }
.prog-count { font-size: .72rem; color: var(--t3); white-space: nowrap; font-weight: 600; }

/* ── FILTER BAR ── */
.filter-bar { display: flex; gap: .4rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.filter-btn {
    padding: .42rem .9rem; border-radius: 8px; font-size: .75rem; font-weight: 600;
    border: 1px solid var(--border); background: transparent; color: var(--t2);
    cursor: pointer; transition: all .18s; font-family: 'Poppins', sans-serif;
}
.filter-btn:hover { background: var(--hover); color: var(--t1); }
.filter-btn.active { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.4); color: #93c5fd; }
.search-wrap { margin-left: auto; position: relative; }
.search-icon {
    position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
    pointer-events: none;
}
.search-icon svg { width: 13px; height: 13px; stroke: var(--t3); fill: none; }
.search-input {
    padding: .42rem .9rem .42rem 2.1rem; border-radius: 8px;
    background: var(--surface-solid); border: 1px solid var(--border);
    color: var(--t1); font-size: .75rem; font-family: 'Poppins', sans-serif;
    width: 185px; transition: border-color .18s, width .25s;
}
.search-input:focus { outline: none; border-color: rgba(59,130,246,.4); width: 215px; }
.search-input::placeholder { color: var(--t3); }

/* ── DOCUMENT LIST ── */
.doc-list { display: flex; flex-direction: column; gap: .45rem; margin-bottom: .5rem; }

/* ── DOCUMENT ROW ── */
.doc-row {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden; transition: border-color .18s;
    border-left: 2.5px solid transparent;
}
.doc-row:hover { border-color: var(--border-hi); }
.doc-row.req  { border-left-color: var(--error); }
.doc-row.opt  { border-left-color: var(--warning); }
.doc-row.done { border-left-color: var(--success); }

.doc-header {
    display: flex; align-items: center; gap: .85rem;
    padding: .82rem 1.1rem; cursor: pointer; user-select: none;
}
.doc-header:hover { background: rgba(255,255,255,.02); }

.doc-num {
    width: 26px; height: 26px; border-radius: 6px; flex-shrink: 0;
    background: rgba(59,130,246,.1); display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700; color: #93c5fd; font-variant-numeric: tabular-nums;
}
.doc-num.req-num  { background: rgba(239,68,68,.1);  color: #fca5a5; }
.doc-num.done-num { background: rgba(16,185,129,.1); color: #6ee7b7; }
.doc-num.opt-num  { background: rgba(245,158,11,.1); color: #fcd34d; }

.doc-name { flex: 1; font-size: .88rem; font-weight: 500; color: var(--t1); line-height: 1.3; }
.doc-cat-label {
    font-size: .65rem; font-weight: 600; padding: .18rem .5rem; border-radius: 5px; white-space: nowrap;
}
.cat-identity  { background: rgba(59,130,246,.12);  color: #93c5fd; }
.cat-education { background: rgba(139,92,246,.12); color: #c4b5fd; }
.cat-medical   { background: rgba(16,185,129,.12); color: #6ee7b7; }
.cat-legal     { background: rgba(251,191,36,.1);  color: #fcd34d; }
.cat-character { background: rgba(251,191,36,.1);  color: #fcd34d; }
.cat-address   { background: rgba(20,184,166,.12); color: #5eead4; }
.cat-military  { background: rgba(239,68,68,.1);   color: #fca5a5; }

.doc-status-pill {
    font-size: .65rem; font-weight: 600; padding: .18rem .55rem; border-radius: 5px; white-space: nowrap; flex-shrink: 0;
}
.pill-req  { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.2);  color: #fca5a5; }
.pill-opt  { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.2); color: #fcd34d; }
.pill-done { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: #6ee7b7; }

.doc-chevron {
    width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); display: flex; align-items: center; justify-content: center;
    color: var(--t3); transition: transform .22s, color .18s; flex-shrink: 0;
}
.doc-chevron svg { width: 10px; height: 10px; stroke: currentColor; fill: none; }
.doc-row.open .doc-chevron { transform: rotate(180deg); color: var(--accent); }

/* ── DOC EXPANDED ── */
.doc-body {
    display: none; border-top: 1px solid var(--border);
    background: rgba(15,23,42,.4);
    padding: .85rem 1.1rem 1rem;
}
.doc-row.open .doc-body { display: block; }

.doc-meta-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; margin-bottom: .85rem;
}
.meta-item {
    background: rgba(15,23,42,.55); border: 1px solid var(--border);
    border-radius: 8px; padding: .6rem .75rem;
}
.meta-label { font-size: .6rem; font-weight: 600; color: var(--t3); text-transform: uppercase; letter-spacing: .08em; margin-bottom: .2rem; }
.meta-val   { font-size: .8rem; font-weight: 600; color: var(--t1); }

.doc-notes {
    font-size: .78rem; color: var(--t2); line-height: 1.65;
    background: rgba(59,130,246,.05); border: 1px solid rgba(59,130,246,.12);
    border-radius: 7px; padding: .6rem .85rem; margin-bottom: .75rem;
}

.doc-actions { display: flex; align-items: center; gap: .5rem; }
.mark-btn {
    padding: .4rem .85rem; border-radius: 7px; font-family: 'Poppins',sans-serif;
    font-size: .75rem; font-weight: 600; border: 1px solid var(--border);
    background: transparent; color: var(--t3); cursor: pointer; transition: all .18s;
    display: inline-flex; align-items: center; gap: 5px;
}
.mark-btn:hover  { border-color: rgba(16,185,129,.4); color: var(--success); }
.mark-btn.marked { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.35); color: #6ee7b7; }

/* ── DIVIDER ── */
.section-divider {
    height: 1px; background: var(--border);
    margin: 1.75rem 0 1.25rem; position: relative;
}
.section-divider span {
    position: absolute; top: -9px; left: 0;
    background: #0f172a; padding-right: 10px;
    font-size: .6rem; font-weight: 700; letter-spacing: .12em;
    text-transform: uppercase; color: var(--t3);
}

/* ── TIMELINE ── */
.timeline-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 1.35rem 1.5rem; margin-top: 2rem;
}
.tl-list { display: flex; flex-direction: column; gap: .65rem; margin-top: 1.1rem; }
.tl-item {
    display: flex; gap: .85rem; align-items: flex-start;
}
.tl-step-num {
    width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; margin-top: 2px;
    background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.28);
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700; color: #93c5fd; font-variant-numeric: tabular-nums;
}
.tl-step-title { font-size: .86rem; font-weight: 600; color: var(--t1); margin-bottom: .2rem; line-height: 1.3; }
.tl-step-desc  { font-size: .76rem; color: var(--t2); line-height: 1.55; }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.fade { animation: fadeUp .35s ease both; }
.d1 { animation-delay: .04s; }
.d2 { animation-delay: .09s; }
.d3 { animation-delay: .14s; }
.d4 { animation-delay: .19s; }

/* ── MOBILE ── */
.mobile-fab {
    display: none; position: fixed; bottom: 1.25rem; right: 1.25rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    border: none; color: #fff; font-size: 1.2rem; cursor: pointer;
    box-shadow: 0 4px 15px rgba(59,130,246,.4); z-index: 999;
    align-items: center; justify-content: center;
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main { margin-left: 0; }
    .page { padding: 1rem; }
    .topbar { padding: .8rem 1rem; }
    .mobile-fab { display: flex; }
    .doc-meta-grid { grid-template-columns: 1fr 1fr; }
    .force-stats { display: none; }
}
@media (max-width: 500px) {
    .doc-meta-grid { grid-template-columns: 1fr; }
    .filter-bar { gap: .3rem; }
    .search-wrap { margin-left: 0; width: 100%; }
    .search-input { width: 100%; }
}
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
                <p><?= htmlspecialchars($forceName) ?></p>
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
        <a href="nutrition.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Nutrition
        </a>
        <a href="questions.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Questions
        </a>
        <a href="documents.php" class="nav-item active">
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
    <header class="topbar">
        <nav class="bc">
            <a href="dashboard.php">Dashboard</a>
            <span>/</span>
            <span>Documents</span>
        </nav>
        <div class="tb-right">
            <span class="force-badge"><?= htmlspecialchars($forceName) ?></span>
            <button class="btn-ghost" onclick="window.print()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print
            </button>
        </div>
    </header>

    <div class="page">

        <!-- Force banner -->
        <div class="force-banner fade d1">
            <div class="force-banner-left">
                <div class="force-banner-icon">
                    <svg viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div>
                    <div class="force-banner-title"><?= htmlspecialchars($forceName) ?></div>
                    <div class="force-banner-sub"><?= htmlspecialchars($forceUnit) ?> · Document checklist</div>
                </div>
            </div>
            <div class="force-stats">
                <div class="force-stat">
                    <div class="force-stat-num"><?= $totalDocs ?></div>
                    <div class="force-stat-label">Total</div>
                </div>
                <div class="stat-divider"></div>
                <div class="force-stat">
                    <div class="force-stat-num" style="color:#fca5a5"><?= $reqCount ?></div>
                    <div class="force-stat-label">Required</div>
                </div>
                <div class="stat-divider"></div>
                <div class="force-stat">
                    <div class="force-stat-num" style="color:#fcd34d"><?= $optCount ?></div>
                    <div class="force-stat-label">Optional</div>
                </div>
                <div class="stat-divider"></div>
                <div class="force-stat">
                    <div class="force-stat-num" id="kpi-ready" style="color:#6ee7b7">0</div>
                    <div class="force-stat-label">Ready</div>
                </div>
            </div>
        </div>

        <!-- Progress -->
        <div class="progress-row fade d2">
            <span class="prog-label">Readiness</span>
            <div class="prog-bar"><div class="prog-fill" id="prog-fill"></div></div>
            <span class="prog-count" id="prog-count">0 / <?= $totalDocs ?></span>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar fade d3">
            <button class="filter-btn active" onclick="filterDocs('all', this)">All <span style="opacity:.55">(<?= $totalDocs ?>)</span></button>
            <button class="filter-btn" onclick="filterDocs('req', this)">Required <span style="opacity:.55">(<?= $reqCount ?>)</span></button>
            <button class="filter-btn" onclick="filterDocs('opt', this)">Optional <span style="opacity:.55">(<?= $optCount ?>)</span></button>
            <?php
            $cats = array_unique(array_column($docs, 'category'));
            foreach ($cats as $cat):
            ?>
            <button class="filter-btn" onclick="filterDocs('<?= strtolower($cat) ?>', this)"><?= htmlspecialchars($cat) ?></button>
            <?php endforeach; ?>
            <div class="search-wrap">
                <span class="search-icon">
                    <svg viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="m21 21-4.35-4.35"/></svg>
                </span>
                <input class="search-input" type="text" placeholder="Search documents..." oninput="searchDocs(this.value)">
            </div>
        </div>

        <!-- Required documents -->
        <div class="sec-header fade d3">
            <div class="sec-title">Required Documents</div>
            <span class="sec-badge red"><?= $reqCount ?></span>
        </div>

        <div class="doc-list fade d4" id="doc-list">
        <?php
        $catClass = [
            'identity'  => 'cat-identity',
            'education' => 'cat-education',
            'medical'   => 'cat-medical',
            'legal'     => 'cat-legal',
            'character' => 'cat-character',
            'address'   => 'cat-address',
            'military'  => 'cat-military',
        ];
        $idx = 0;
        foreach ($docs as $doc):
            $idx++;
            $catKey   = strtolower($doc['category']);
            $catCls   = $catClass[$catKey] ?? 'cat-identity';
            $typeClass = $doc['required'] ? 'req' : 'opt';
        ?>
        <div class="doc-row <?= $typeClass ?>"
             data-type="<?= $typeClass ?>"
             data-cat="<?= $catKey ?>"
             data-name="<?= strtolower(htmlspecialchars($doc['name'])) ?>"
             id="doc-<?= htmlspecialchars($doc['id']) ?>">

            <div class="doc-header" onclick="toggleDoc('<?= htmlspecialchars($doc['id']) ?>')">
                <div class="doc-num <?= $doc['required'] ? 'req-num' : 'opt-num' ?>" id="dn-<?= htmlspecialchars($doc['id']) ?>"><?= $idx ?></div>
                <div class="doc-name"><?= htmlspecialchars($doc['name']) ?></div>
                <span class="doc-cat-label <?= $catCls ?>"><?= htmlspecialchars($doc['category']) ?></span>
                <span class="doc-status-pill <?= $doc['required'] ? 'pill-req' : 'pill-opt' ?>" id="sp-<?= htmlspecialchars($doc['id']) ?>">
                    <?= $doc['required'] ? 'Required' : 'Optional' ?>
                </span>
                <div class="doc-chevron">
                    <svg viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </div>
            </div>

            <div class="doc-body">
                <div class="doc-meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Copies needed</div>
                        <div class="meta-val"><?= $doc['copies'] ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Validity</div>
                        <div class="meta-val"><?= htmlspecialchars($doc['validity']) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Category</div>
                        <div class="meta-val"><?= htmlspecialchars($doc['category']) ?></div>
                    </div>
                </div>
                <?php if (!empty($doc['notes'])): ?>
                <div class="doc-notes"><?= htmlspecialchars($doc['notes']) ?></div>
                <?php endif; ?>
                <div class="doc-actions">
                    <button class="mark-btn" id="mb-<?= htmlspecialchars($doc['id']) ?>" onclick="toggleMark('<?= htmlspecialchars($doc['id']) ?>')">
                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="mi-<?= htmlspecialchars($doc['id']) ?>"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <span id="mt-<?= htmlspecialchars($doc['id']) ?>">Mark as ready</span>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        
        <!-- Timeline -->
        <div class="timeline-card fade d4">
            <div class="sec-header" style="margin-bottom:0">
                <div class="sec-title">Selection Timeline</div>
                <span class="sec-badge"><?= count($tl) ?> stages</span>
            </div>
            <div class="tl-list">
                <?php foreach ($tl as $i => [$phase, $desc]): ?>
                <div class="tl-item">
                    <div class="tl-step-num"><?= $i + 1 ?></div>
                    <div>
                        <div class="tl-step-title"><?= htmlspecialchars($phase) ?></div>
                        <div class="tl-step-desc"><?= htmlspecialchars($desc) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<button class="mobile-fab" onclick="document.getElementById('sidebar').classList.toggle('active')">
    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>

<script>
// ── PHP data ──────────────────────────────────────────────────────────────
const SAVED_AVATAR_TYPE = <?= json_encode($avatarType) ?>;
const SAVED_PHOTO_URL   = <?= json_encode($photoUrl) ?>;
const SAVED_AVATAR_CFG  = <?= $avatarConfigJson ?>;
const USER_INITIAL      = <?= json_encode($initials) ?>;
const TOTAL_DOCS        = <?= $totalDocs ?>;

// ── Avatar (matches questions.php) ────────────────────────────────────────
const AV_OPTIONS = {
    bg:[
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
    const bg   = AV_OPTIONS.bg.find(o=>o.id===state.bg)              || AV_OPTIONS.bg[0];
    const skin = AV_OPTIONS.skin.find(o=>o.id===state.skin)          || AV_OPTIONS.skin[1];
    const hCol = AV_OPTIONS.hairColor.find(o=>o.id===state.hairColor) || AV_OPTIONS.hairColor[1];
    const eyeC = AV_OPTIONS.eyes.find(o=>o.id===state.eyes)          || AV_OPTIONS.eyes[0];
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
        for(let a=0;a<Math.PI*2;a+=0.35){const rx=cx+Math.cos(a)*42,ry=(cy-30)+Math.sin(a)*24;if(ry<cy-10){ctx.beginPath();ctx.arc(rx,ry,9,0,Math.PI*2);ctx.fill();}}
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
    const c = document.getElementById('sbAvContainer'); if(!c) return; c.innerHTML='';
    if (SAVED_AVATAR_TYPE==='photo' && SAVED_PHOTO_URL) {
        const img=document.createElement('img');
        img.src=SAVED_PHOTO_URL;
        img.style.cssText='width:38px;height:38px;object-fit:cover;border-radius:50%;display:block';
        img.onerror=()=>renderInitialSb(c);
        c.appendChild(img);
    } else if (SAVED_AVATAR_TYPE==='ai' && SAVED_AVATAR_CFG) {
        const canvas=document.createElement('canvas');
        canvas.style.cssText='width:38px;height:38px;border-radius:50%;display:block';
        c.appendChild(canvas);
        drawAvatarOnCanvas(canvas, SAVED_AVATAR_CFG, 38);
    } else { renderInitialSb(c); }
}
function renderInitialSb(c) {
    const d=document.createElement('div');
    d.style.cssText='width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff';
    d.textContent=USER_INITIAL;
    c.appendChild(d);
}

// ── Mark ready ────────────────────────────────────────────────────────────
const checked = new Set();

function toggleMark(id) {
    const row  = document.getElementById('doc-' + id);
    const btn  = document.getElementById('mb-'  + id);
    const txt  = document.getElementById('mt-'  + id);
    const num  = document.getElementById('dn-'  + id);
    const pill = document.getElementById('sp-'  + id);

    if (checked.has(id)) {
        checked.delete(id);
        btn.classList.remove('marked');
        txt.textContent = 'Mark as ready';
        const isReq = row.dataset.type === 'req';
        row.classList.remove('done');
        row.classList.add(isReq ? 'req' : 'opt');
        num.classList.remove('done-num');
        num.classList.add(isReq ? 'req-num' : 'opt-num');
        pill.className = 'doc-status-pill ' + (isReq ? 'pill-req' : 'pill-opt');
        pill.textContent = isReq ? 'Required' : 'Optional';
    } else {
        checked.add(id);
        btn.classList.add('marked');
        txt.textContent = 'Ready';
        row.classList.remove('req', 'opt');
        row.classList.add('done');
        num.classList.remove('req-num', 'opt-num');
        num.classList.add('done-num');
        pill.className = 'doc-status-pill pill-done';
        pill.textContent = 'Ready';
    }
    updateProgress();
    try { localStorage.setItem('gm-docs-v2', JSON.stringify([...checked])); } catch(e) {}
}

function updateProgress() {
    const n = checked.size;
    document.getElementById('kpi-ready').textContent  = n;
    document.getElementById('prog-count').textContent = n + ' / ' + TOTAL_DOCS;
    document.getElementById('prog-fill').style.width  = (n / TOTAL_DOCS * 100) + '%';
}

// ── Accordion toggle ──────────────────────────────────────────────────────
function toggleDoc(id) {
    const row = document.getElementById('doc-' + id);
    if (!row) return;
    const wasOpen = row.classList.contains('open');
    document.querySelectorAll('.doc-row.open').forEach(r => r.classList.remove('open'));
    if (!wasOpen) row.classList.add('open');
}

// ── Filter ────────────────────────────────────────────────────────────────
function filterDocs(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.doc-row').forEach(row => {
        const show = type === 'all'
            || row.dataset.type === type
            || row.dataset.cat  === type;
        row.style.display = show ? '' : 'none';
    });
}

// ── Search ────────────────────────────────────────────────────────────────
function searchDocs(val) {
    const q = val.trim().toLowerCase();
    document.querySelectorAll('.doc-row').forEach(row => {
        row.style.display = (!q || row.dataset.name.includes(q)) ? '' : 'none';
    });
}

// ── Restore saved state ───────────────────────────────────────────────────
window.addEventListener('load', () => {
    renderSidebarAvatar();
    try {
        const saved = JSON.parse(localStorage.getItem('gm-docs-v2') || '[]');
        saved.forEach(id => {
            if (document.getElementById('doc-' + id)) {
                checked.add(id);
                // apply state directly without triggering toggle logic loop
                const row  = document.getElementById('doc-' + id);
                const btn  = document.getElementById('mb-'  + id);
                const txt  = document.getElementById('mt-'  + id);
                const num  = document.getElementById('dn-'  + id);
                const pill = document.getElementById('sp-'  + id);
                if (!row || !btn) return;
                btn.classList.add('marked');
                txt.textContent = 'Ready';
                row.classList.remove('req', 'opt'); row.classList.add('done');
                num.classList.remove('req-num', 'opt-num'); num.classList.add('done-num');
                if (pill) { pill.className = 'doc-status-pill pill-done'; pill.textContent = 'Ready'; }
            }
        });
    } catch(e) {}
    updateProgress();

    // Close sidebar on outside click (mobile)
    document.addEventListener('click', e => {
        const sb  = document.getElementById('sidebar');
        const fab = document.querySelector('.mobile-fab');
        if (window.innerWidth <= 768 && sb && fab && !sb.contains(e.target) && !fab.contains(e.target))
            sb.classList.remove('active');
    });
});
</script>
</body>
</html>