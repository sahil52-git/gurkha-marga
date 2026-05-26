<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised', 'questions' => []]);
    exit();
}

define('BASE_PATH', dirname(dirname(dirname(__FILE__))));
require_once BASE_PATH . '/backend/database.php';
require_once BASE_PATH . '/backend/subscription_helper.php';

$userId    = (int)$_SESSION['user_id'];
$sub       = getActiveSubscription($userId);
$isPremium = (bool)$sub;

if (!$isPremium) {
    http_response_code(403);
    echo json_encode(['error' => 'Premium required', 'questions' => []]);
    exit();
}

$user     = fetchOne(
    "SELECT target_force FROM users WHERE id = ? AND is_active = 1 LIMIT 1",
    [$userId]
);
$forceKey = strtolower($user['target_force'] ?? 'all');

// ── Validate category param ───────────────────────────────
$VALID_CATS = ['math', 'english', 'general', 'past', 'physical'];
$cat        = trim($_GET['cat'] ?? '');

if (!in_array($cat, $VALID_CATS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid category', 'questions' => []]);
    exit();
}

try {
    $rows = fetchAll(
        "SELECT id, question_type, question_text,
                options_json, correct_index,
                answer_text,  file_path
         FROM   questions
         WHERE  is_active    = 1
           AND  category     = ?
           AND  (target_force = ? OR target_force = 'all')
         ORDER  BY sort_order ASC, id ASC",
        [$cat, $forceKey]
    ) ?: [];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage(), 'questions' => []]);
    exit();
}

// ── Shuffle so every session feels fresh ─────────────────
shuffle($rows);

// ── Shape payload ─────────────────────────────────────────
$out = [];
foreach ($rows as $r) {
    $opts = null;
    if ($r['question_type'] === 'mcq' && !empty($r['options_json'])) {
        $decoded = json_decode($r['options_json'], true);
        $opts    = is_array($decoded) ? array_values($decoded) : null;
    }

    $out[] = [
        'id'   => (int)$r['id'],
        'type' => $r['question_type'],          // 'mcq' | 'text'
        'q'    => $r['question_text'],
        'opts' => $opts,                         // null for text Q&As
        'ans'  => (int)($r['correct_index'] ?? 0),
        'exp'  => $r['answer_text'] ?? '',
        'file' => $r['file_path']   ?? null,
    ];
}

echo json_encode(['questions' => $out], JSON_UNESCAPED_UNICODE);