<?php
// frontend/admin/admin_questions.php
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../auth/login.php'); exit();
}

define('DB_PATH', dirname(dirname(dirname(__FILE__))) . '/backend/database.php');
require_once DB_PATH;

define('UPLOAD_DIR', dirname(dirname(__DIR__)) . '/frontend/uploads/question_files/');
define('UPLOAD_URL', '/gurkha-marga/frontend/uploads/question_files/');
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$CATEGORIES = ['math', 'english', 'general', 'past', 'physical'];
$FORCES     = ['all', 'british', 'nepal', 'indian', 'singapore', 'french'];
$QTYPES     = ['mcq', 'text'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create', 'update'], true)) {
        $id            = (int)($_POST['id'] ?? 0);
        $qtype         = in_array($_POST['qtype'] ?? '', $QTYPES, true)        ? $_POST['qtype']        : 'mcq';
        $category      = in_array($_POST['category'] ?? '', $CATEGORIES, true) ? $_POST['category']     : 'general';
        $target_force  = in_array($_POST['target_force'] ?? '', $FORCES, true) ? $_POST['target_force'] : 'all';
        $question_text = trim($_POST['question_text'] ?? '');
        $answer_text   = trim($_POST['answer_text']   ?? '');
        $sort_order    = (int)($_POST['sort_order']   ?? 0);
        $is_active     = isset($_POST['is_active']) ? 1 : 0;

        $opt_a   = trim($_POST['opt_a'] ?? '');
        $opt_b   = trim($_POST['opt_b'] ?? '');
        $opt_c   = trim($_POST['opt_c'] ?? '');
        $opt_d   = trim($_POST['opt_d'] ?? '');
        $correct = (int)($_POST['correct_index'] ?? 0);
        $options_json = ($qtype === 'mcq') ? json_encode([$opt_a, $opt_b, $opt_c, $opt_d]) : null;

        $file_path = null;
        if (!empty($_FILES['question_file']['name'])) {
            $orig    = (string)$_FILES['question_file']['name'];
            $ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','png','jpg','jpeg','mp4','webm'];
            if (!in_array($ext, $allowed, true)) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'File type not allowed.'];
                header('Location: admin_questions.php'); exit();
            }
            if ((int)$_FILES['question_file']['size'] > 50*1024*1024) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'File exceeds 50 MB.'];
                header('Location: admin_questions.php'); exit();
            }
            $fname = uniqid('qfile_', true) . '.' . $ext;
            if (move_uploaded_file((string)$_FILES['question_file']['tmp_name'], UPLOAD_DIR.$fname)) {
                $file_path = $fname;
            }
        }

        if ($question_text === '') {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Question text is required.'];
            header('Location: admin_questions.php'); exit();
        }

        try {
            if ($action === 'create') {
                execute(
                    "INSERT INTO questions
                     (question_type, category, target_force, question_text, answer_text,
                      options_json, correct_index, file_path, sort_order, is_active, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                    [$qtype, $category, $target_force, $question_text, $answer_text,
                     $options_json, $correct, $file_path, $sort_order, $is_active]
                );
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Question created successfully.'];
            } else {
                $fileClause = $file_path ? ', file_path=?' : '';
                $params = [$qtype, $category, $target_force, $question_text, $answer_text,
                           $options_json, $correct, $sort_order, $is_active];
                if ($file_path) $params[] = $file_path;
                $params[] = $id;
                execute(
                    "UPDATE questions SET
                     question_type=?, category=?, target_force=?, question_text=?, answer_text=?,
                     options_json=?, correct_index=?, sort_order=?, is_active=?
                     {$fileClause}
                     WHERE id=?",
                    $params
                );
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Question updated.'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'DB error: '.$e->getMessage()];
        }
        header('Location: admin_questions.php'); exit();
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $row = fetchOne("SELECT file_path FROM questions WHERE id=?", [$id]);
            if ($row && !empty($row['file_path']) && file_exists(UPLOAD_DIR.$row['file_path'])) {
                unlink(UPLOAD_DIR.$row['file_path']);
            }
            execute("DELETE FROM questions WHERE id=?", [$id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Question deleted.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Delete failed: '.$e->getMessage()];
        }
        header('Location: admin_questions.php'); exit();
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try { execute("UPDATE questions SET is_active=1-is_active WHERE id=?", [$id]); } catch(Exception $e){}
        header('Location: admin_questions.php'); exit();
    }
}

require_once __DIR__ . '/admin_layout.php';

$filterCat   = $_GET['cat']   ?? '';
$filterForce = $_GET['force'] ?? '';
$filterType  = $_GET['type']  ?? '';
$search      = trim($_GET['q'] ?? '');
$editId      = (int)($_GET['edit'] ?? 0);
$page        = max(1, (int)($_GET['p'] ?? 1));
$perPage     = 20;

$where  = ['1=1'];
$params = [];
if ($filterCat   && in_array($filterCat,   $CATEGORIES, true)) { $where[]='category=?';      $params[]=$filterCat; }
if ($filterForce && in_array($filterForce, $FORCES,     true)) { $where[]='target_force=?';  $params[]=$filterForce; }
if ($filterType  && in_array($filterType,  $QTYPES,     true)) { $where[]='question_type=?'; $params[]=$filterType; }
if ($search) { $where[]='question_text LIKE ?'; $params[]='%'.$search.'%'; }

$whereSQL = implode(' AND ', $where);
$offset   = ($page-1)*$perPage;

try {
    $total     = (int)(fetchOne("SELECT COUNT(*) as c FROM questions WHERE {$whereSQL}", $params)['c'] ?? 0);
    $questions = fetchAll("SELECT * FROM questions WHERE {$whereSQL} ORDER BY category, sort_order, id DESC LIMIT {$perPage} OFFSET {$offset}", $params) ?: [];
    $editRow   = $editId ? fetchOne("SELECT * FROM questions WHERE id=?", [$editId]) : null;
    $totalQ    = (int)(fetchOne("SELECT COUNT(*) as c FROM questions", [])['c'] ?? 0);
    $activeQ   = (int)(fetchOne("SELECT COUNT(*) as c FROM questions WHERE is_active=1", [])['c'] ?? 0);
    $mcqCount  = (int)(fetchOne("SELECT COUNT(*) as c FROM questions WHERE question_type='mcq'", [])['c'] ?? 0);
    $textCount = (int)(fetchOne("SELECT COUNT(*) as c FROM questions WHERE question_type='text'", [])['c'] ?? 0);
    $filesCount= (int)(fetchOne("SELECT COUNT(*) as c FROM questions WHERE file_path IS NOT NULL AND file_path!=''", [])['c'] ?? 0);
} catch (Exception $e) {
    $total=$totalQ=$activeQ=$mcqCount=$textCount=$filesCount=0;
    $questions=[]; $editRow=null;
}

$totalPages = max(1,(int)ceil($total/$perPage));
$flash = getFlash();

$catLabel   = ['math'=>'Math','english'=>'English','general'=>'General','past'=>'Past Papers','physical'=>'Physical'];
$forceLabel = ['all'=>'All Forces','british'=>'British Army','nepal'=>'Nepal Army','indian'=>'Indian Army','singapore'=>'Singapore Police','french'=>'French Legion'];
$typeLabel  = ['mcq'=>'MCQ','text'=>'Past Paper'];

function catBadge(string $c): string {
    $map=['math'=>'badge-blue','english'=>'badge-green','general'=>'badge-purple','past'=>'badge-amber','physical'=>'badge-red'];
    $lbl=['math'=>'Math','english'=>'English','general'=>'General','past'=>'Past Papers','physical'=>'Physical'];
    return '<span class="badge '.($map[$c]??'badge-blue').'" style="font-size:.6rem">'.htmlspecialchars($lbl[$c]??$c).'</span>';
}
function forceBadge(string $f): string {
    $codes=['all'=>'ALL','british'=>'GB','nepal'=>'NP','indian'=>'IN','singapore'=>'SG','french'=>'FR'];
    return '<span class="badge badge-blue" style="font-size:.6rem">'.($codes[$f]??strtoupper($f)).' '.ucfirst($f).'</span>';
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Questions</h1>
        <p class="page-sub">Manage MCQs and Past Papers for user quiz practice</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="admin_questions.php" class="btn btn-secondary">All Questions</a>
        <button class="btn btn-primary" onclick="openQModal()">+ Add Question</button>
    </div>
</div>

<?php if ($flash && !empty($flash['msg'])): ?>
<div class="flash flash-<?= htmlspecialchars($flash['type']) ?>" id="flashMsg">
    <?= $flash['type']==='success'?'&#10003;':'&#9888;' ?>
    <?= htmlspecialchars($flash['msg']) ?>
    <button onclick="this.parentNode.remove()" style="background:none;border:none;color:inherit;cursor:pointer;margin-left:auto">&times;</button>
</div>
<?php endif; ?>

<style>
/* ─── Modal ─── */
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(6px);
    z-index:1000;display:none;align-items:flex-start;justify-content:center;
    padding:2rem 1rem;overflow-y:auto;
}
.modal-overlay.open{display:flex}
.modal{
    background:#0f172a;border:1px solid rgba(255,255,255,.09);
    border-radius:14px;width:100%;max-width:640px;margin:auto;
    box-shadow:0 24px 60px rgba(0,0,0,.6);
}
.modal-head{
    display:flex;align-items:center;justify-content:space-between;
    padding:1.1rem 1.4rem;border-bottom:1px solid rgba(255,255,255,.07);
}
.modal-title{font-size:.92rem;font-weight:700;color:#f8fafc}
.modal-close{
    width:28px;height:28px;border-radius:7px;
    background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);
    color:#94a3b8;cursor:pointer;display:flex;align-items:center;justify-content:center;
    font-size:.85rem;line-height:1;transition:all .15s;
}
.modal-close:hover{background:rgba(239,68,68,.12);color:#fca5a5;border-color:rgba(239,68,68,.25)}

/* Type selector tabs (replaces dropdown) */
.type-tabs{display:flex;gap:.35rem;margin-bottom:1rem}
.type-tab{
    flex:1;padding:.6rem .5rem;border-radius:8px;font-family:inherit;
    font-size:.78rem;font-weight:600;border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.03);color:#94a3b8;cursor:pointer;
    transition:all .18s;text-align:center;
}
.type-tab:hover{color:#cbd5e1;border-color:rgba(255,255,255,.12)}
.type-tab.active.mcq-tab{
    background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.35);color:#93c5fd;
}
.type-tab.active.pp-tab{
    background:rgba(251,191,36,.08);border-color:rgba(251,191,36,.3);color:#fbbf24;
}
.type-tab-icon{font-size:.95rem;display:block;margin-bottom:.2rem}
.type-tab-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}

/* Form layout */
.modal-body{padding:1.25rem 1.4rem}
.modal-foot{
    padding:.9rem 1.4rem;border-top:1px solid rgba(255,255,255,.07);
    display:flex;gap:.6rem;justify-content:flex-end;
}
.frow{display:grid;gap:.75rem;margin-bottom:.75rem}
.frow.cols-2{grid-template-columns:1fr 1fr}
.frow.cols-3{grid-template-columns:1fr 1fr 1fr}
.frow.full{grid-template-columns:1fr}
.fgroup{display:flex;flex-direction:column;gap:.3rem}
.flabel{
    font-size:.64rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;
    color:#64748b;
}
.flabel-req{color:#fca5a5;margin-left:2px}
.fcontrol{
    width:100%;padding:.55rem .8rem;border-radius:8px;
    background:rgba(15,23,42,.9);border:1px solid rgba(255,255,255,.08);
    color:#f8fafc;font-family:inherit;font-size:.82rem;
    transition:border-color .15s;box-sizing:border-box;
}
.fcontrol:focus{outline:none;border-color:rgba(59,130,246,.45);background:rgba(15,23,42,.95)}
.fcontrol::placeholder{color:#475569}
textarea.fcontrol{resize:vertical;min-height:72px;line-height:1.55}
select.fcontrol option{background:#1e293b}

/* Option inputs */
.opts-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.75rem}
.opt-input-row{display:flex;align-items:center;gap:.5rem}
.opt-badge{
    width:24px;height:24px;border-radius:6px;flex-shrink:0;
    background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);
    display:flex;align-items:center;justify-content:center;
    font-size:.64rem;font-weight:800;color:#93c5fd;
}

/* Correct answer selector */
.correct-picker{display:flex;gap:.45rem;flex-wrap:wrap}
.correct-opt{
    display:flex;align-items:center;gap:.4rem;
    padding:.4rem .7rem;border-radius:7px;border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.03);cursor:pointer;
    font-size:.76rem;color:#94a3b8;transition:all .16s;
}
.correct-opt:has(input:checked){
    border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.08);color:#6ee7b7;
}
.correct-opt input{display:none}

/* Upload zone */
.upload-zone{
    border:1.5px dashed rgba(255,255,255,.1);border-radius:9px;
    padding:1.1rem;text-align:center;cursor:pointer;
    font-size:.78rem;color:#64748b;display:block;
    transition:all .18s;background:rgba(15,23,42,.5);
}
.upload-zone:hover{border-color:rgba(59,130,246,.3);color:#94a3b8;background:rgba(59,130,246,.04)}
.upload-zone input{display:none}
.upload-icon{font-size:1.3rem;display:block;margin-bottom:.35rem}
.upload-hint{font-size:.68rem;color:#475569;margin-top:.2rem}

/* Active toggle */
.toggle-row{display:flex;align-items:center;gap:.75rem}
.toggle{position:relative;width:36px;height:20px;cursor:pointer}
.toggle input{opacity:0;width:0;height:0}
.toggle-track{
    position:absolute;inset:0;background:rgba(255,255,255,.08);
    border-radius:20px;border:1px solid rgba(255,255,255,.1);transition:all .2s;
}
.toggle input:checked+.toggle-track{background:rgba(16,185,129,.4);border-color:rgba(16,185,129,.4)}
.toggle-track::after{
    content:'';position:absolute;width:14px;height:14px;
    background:#94a3b8;border-radius:50%;top:2px;left:2px;transition:transform .2s;
}
.toggle input:checked+.toggle-track::after{transform:translateX(16px);background:#fff}
.toggle-label{font-size:.78rem;color:#94a3b8}

/* Section divider */
.form-section{
    margin-bottom:.9rem;padding-bottom:.9rem;
    border-bottom:1px solid rgba(255,255,255,.06);
}
.form-section:last-of-type{border-bottom:none;margin-bottom:0;padding-bottom:0}
.form-section-title{
    font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
    color:#475569;margin-bottom:.7rem;
}

/* File chip in table */
.file-chip{
    display:inline-flex;align-items:center;gap:3px;padding:.16rem .5rem;
    border-radius:5px;font-size:.62rem;font-weight:600;
    background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.25);color:#c4b5fd;
    text-decoration:none;
}
.file-chip:hover{background:rgba(139,92,246,.18)}

/* Filters */
.q-filter-bar{display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}
.filter-select{
    padding:.4rem .7rem;border-radius:7px;background:var(--surface);
    border:1px solid var(--border);color:var(--text-2);
    font-family:inherit;font-size:.78rem;cursor:pointer;
}
.filter-select:focus{outline:none;border-color:rgba(59,130,246,.4)}
.search-wrap{position:relative;flex:1;min-width:160px}
.search-wrap svg{position:absolute;left:.6rem;top:50%;transform:translateY(-50%);width:13px;height:13px;stroke:var(--text-3);pointer-events:none}
.search-input{
    width:100%;padding:.4rem .7rem .4rem 2rem;border-radius:7px;
    background:var(--surface);border:1px solid var(--border);
    color:var(--text);font-family:inherit;font-size:.78rem;box-sizing:border-box;
}
.search-input:focus{outline:none;border-color:rgba(59,130,246,.4)}
.search-input::placeholder{color:var(--text-3)}

/* Table */
.mcq-opts{display:flex;flex-direction:column;gap:2px;margin-top:3px}
.mcq-opt{font-size:.67rem;color:var(--text-3)}
.mcq-opt.correct{color:#6ee7b7;font-weight:600}
.type-tag{
    display:inline-flex;align-items:center;padding:.16rem .5rem;
    border-radius:4px;font-size:.6rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
}
.type-mcq{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:#93c5fd}
.type-text{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);color:#fbbf24}

/* Pagination */
.q-pagination{display:flex;align-items:center;gap:.35rem;justify-content:flex-end;margin-top:1rem}
.pg-btn{
    padding:.32rem .65rem;border-radius:6px;font-size:.74rem;font-weight:600;
    border:1px solid var(--border);background:transparent;color:var(--text-2);
    cursor:pointer;text-decoration:none;transition:all .15s;
}
.pg-btn:hover{background:var(--surface);color:var(--text)}
.pg-btn.current{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.3);color:#93c5fd;cursor:default}

@media(max-width:700px){
    .frow.cols-2,.frow.cols-3,.opts-grid{grid-template-columns:1fr}
    .type-tabs{gap:.25rem}
}
</style>

<!-- ─── STATS ─── -->
<div class="section-label">Overview</div>
<div class="stats-grid cols-5 mb">
    <div class="stat-card"><div class="stat-label">Total</div><div class="stat-val"><?= number_format($totalQ) ?></div><div class="stat-sub">All questions</div></div>
    <div class="stat-card"><div class="stat-label">Active</div><div class="stat-val" style="color:#6ee7b7"><?= number_format($activeQ) ?></div><div class="stat-sub">Visible to users</div></div>
    <div class="stat-card"><div class="stat-label">MCQ</div><div class="stat-val" style="color:#93c5fd"><?= number_format($mcqCount) ?></div><div class="stat-sub">Multiple choice</div></div>
    <div class="stat-card"><div class="stat-label">Past Papers</div><div class="stat-val" style="color:#fbbf24"><?= number_format($textCount) ?></div><div class="stat-sub">Documents & text</div></div>
    <div class="stat-card"><div class="stat-label">With Files</div><div class="stat-val" style="color:#c4b5fd"><?= number_format($filesCount) ?></div><div class="stat-sub">Attachments</div></div>
</div>

<!-- ─── FILTERS ─── -->
<form method="GET" class="q-filter-bar">
    <div class="search-wrap">
        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/></svg>
        <input class="search-input" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search questions...">
    </div>
    <select class="filter-select" name="type" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="mcq"  <?=$filterType==='mcq' ?'selected':''?>>MCQ</option>
        <option value="text" <?=$filterType==='text'?'selected':''?>>Past Paper</option>
    </select>
    <select class="filter-select" name="cat" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($CATEGORIES as $c): ?><option value="<?=$c?>" <?=$filterCat===$c?'selected':''?>><?= htmlspecialchars($catLabel[$c]) ?></option><?php endforeach; ?>
    </select>
    <select class="filter-select" name="force" onchange="this.form.submit()">
        <option value="">All Forces</option>
        <?php foreach ($FORCES as $f): ?><option value="<?=$f?>" <?=$filterForce===$f?'selected':''?>><?= htmlspecialchars($forceLabel[$f]) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary" style="padding:.4rem .8rem;font-size:.78rem">Search</button>
    <?php if ($search||$filterCat||$filterForce||$filterType): ?>
    <a href="admin_questions.php" class="btn btn-secondary" style="padding:.4rem .8rem;font-size:.78rem">Clear</a>
    <?php endif; ?>
</form>

<!-- ─── TABLE ─── -->
<div class="section-label">
    Questions
    <span style="color:var(--text-3);font-size:.7rem;font-weight:400;margin-left:.5rem"><?=$total?> result<?=$total!==1?'s':''?></span>
</div>
<div class="card mb">
    <div class="tbl-wrap">
        <table class="data-table">
            <thead><tr>
                <th style="width:32px">#</th>
                <th>Question</th>
                <th>Type</th>
                <th>Category</th>
                <th>Force</th>
                <th>File</th>
                <th>Status</th>
                <th style="width:110px">Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($questions)): ?>
            <tr><td colspan="8" class="empty-row">No questions found. Click "+ Add Question" to create one.</td></tr>
            <?php endif; ?>
            <?php foreach ($questions as $i=>$q):
                $opts=($q['question_type']==='mcq'&&!empty($q['options_json']))?json_decode($q['options_json'],true):[];
                if(!is_array($opts))$opts=[];
                $ci=(int)($q['correct_index']??0);
                $letters=['A','B','C','D'];
            ?>
            <tr>
                <td class="mono dim" style="font-size:.7rem"><?=($page-1)*$perPage+$i+1?></td>
                <td style="max-width:340px">
                    <div style="font-size:.82rem;font-weight:500;color:var(--text);line-height:1.4;margin-bottom:2px">
                        <?= htmlspecialchars(mb_strimwidth($q['question_text'],0,100,'...')) ?>
                    </div>
                    <?php if($q['question_type']==='mcq'&&!empty($opts)): ?>
                    <div class="mcq-opts">
                        <?php foreach($opts as $oi=>$opt): ?>
                        <div class="mcq-opt <?=$oi===$ci?'correct':''?>"><?=$letters[$oi]?>. <?= htmlspecialchars(mb_strimwidth((string)$opt,0,55,'...')) ?><?=$oi===$ci?' ✓':''?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif(!empty($q['answer_text'])): ?>
                    <div style="font-size:.7rem;color:var(--text-3);margin-top:2px"><?= htmlspecialchars(mb_strimwidth($q['answer_text'],0,80,'...')) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="type-tag <?=$q['question_type']==='mcq'?'type-mcq':'type-text'?>"><?=$q['question_type']==='mcq'?'MCQ':'Past Paper'?></span></td>
                <td><?= catBadge($q['category']) ?></td>
                <td><?= forceBadge($q['target_force']) ?></td>
                <td>
                    <?php if(!empty($q['file_path'])): ?>
                    <a href="<?=UPLOAD_URL.htmlspecialchars($q['file_path'])?>" target="_blank" class="file-chip">
                        <?=strtoupper(pathinfo($q['file_path'],PATHINFO_EXTENSION))?>
                    </a>
                    <?php else: ?><span style="color:var(--text-3);font-size:.7rem">—</span><?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?=(int)$q['id']?>">
                        <button type="submit" class="badge <?=$q['is_active']?'badge-green':'badge-red'?>" style="cursor:pointer;border:none;font-family:inherit;font-size:.62rem">
                            <?=$q['is_active']?'Active':'Inactive'?>
                        </button>
                    </form>
                </td>
                <td>
                    <div style="display:flex;gap:.3rem">
                        <button class="btn btn-secondary" onclick="openEdit(<?= htmlspecialchars(json_encode($q),ENT_QUOTES) ?>)" style="padding:.28rem .55rem;font-size:.7rem">Edit</button>
                        <form method="POST" onsubmit="return confirm('Delete this question?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?=(int)$q['id']?>">
                            <button type="submit" class="btn btn-secondary" style="padding:.28rem .55rem;font-size:.7rem;color:#fca5a5;border-color:rgba(239,68,68,.25)">Del</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages>1): ?>
    <div style="padding:.7rem 1.1rem;border-top:1px solid var(--border)">
        <div class="q-pagination">
            <?php $qs=http_build_query(array_filter(['q'=>$search,'cat'=>$filterCat,'force'=>$filterForce,'type'=>$filterType]));
            if($page>1): ?><a class="pg-btn" href="?p=<?=$page-1?>&<?=$qs?>">&laquo; Prev</a><?php endif;
            for($pi=max(1,$page-2);$pi<=min($totalPages,$page+2);$pi++): ?>
            <a class="pg-btn <?=$pi===$page?'current':''?>" href="?p=<?=$pi?>&<?=$qs?>"><?=$pi?></a>
            <?php endfor;
            if($page<$totalPages): ?><a class="pg-btn" href="?p=<?=$page+1?>&<?=$qs?>">Next &raquo;</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ─── MODAL ─── -->
<div class="modal-overlay" id="qModalOverlay" onclick="overlayClose(event)">
<div class="modal">
    <div class="modal-head">
        <div class="modal-title" id="qModalTitle">Add Question</div>
        <button class="modal-close" onclick="closeQModal()" title="Close">&times;</button>
    </div>

    <form method="POST" enctype="multipart/form-data" id="qForm">
        <input type="hidden" name="action"  id="fAction" value="create">
        <input type="hidden" name="id"      id="fId"     value="0">
        <!-- hidden real qtype -->
        <input type="hidden" name="qtype"   id="fQtypeHidden" value="mcq">

        <div class="modal-body">

            <!-- ── Type selector ── -->
            <div class="form-section">
                <div class="form-section-title">Question Type</div>
                <div class="type-tabs">
                    <button type="button" class="type-tab mcq-tab active" id="tabMCQ" onclick="onTypeChange('mcq')">
                        <span class="type-tab-label">MCQ</span>
                    </button>
                    <button type="button" class="type-tab pp-tab" id="tabPP" onclick="onTypeChange('text')">
                        <span class="type-tab-label">Past Paper</span>
                    </button>
                </div>
            </div>

            <!-- ── Meta row ── -->
            <div class="form-section">
                <div class="form-section-title">Details</div>
                <div class="frow cols-2">
                    <div class="fgroup">
                        <label class="flabel">Category</label>
                        <select class="fcontrol" name="category" id="fCategory" required>
                            <?php foreach($CATEGORIES as $c): ?><option value="<?=$c?>"><?= htmlspecialchars($catLabel[$c]) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fgroup">
                        <label class="flabel">Target Force</label>
                        <select class="fcontrol" name="target_force" id="fForce">
                            <?php foreach($FORCES as $f): ?><option value="<?=$f?>"><?= htmlspecialchars($forceLabel[$f]) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="frow full">
                    <div class="fgroup">
                        <label class="flabel">Question / Title <span class="flabel-req">*</span></label>
                        <textarea class="fcontrol" name="question_text" id="fQText" rows="3" required placeholder="Enter the question text…"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── MCQ section ── -->
            <div id="mcqSection" class="form-section">
                <div class="form-section-title">Answer Options</div>
                <div class="opts-grid">
                    <div class="opt-input-row"><div class="opt-badge">A</div><input class="fcontrol" name="opt_a" id="fOpt_a" placeholder="Option A…"></div>
                    <div class="opt-input-row"><div class="opt-badge">B</div><input class="fcontrol" name="opt_b" id="fOpt_b" placeholder="Option B…"></div>
                    <div class="opt-input-row"><div class="opt-badge">C</div><input class="fcontrol" name="opt_c" id="fOpt_c" placeholder="Option C…"></div>
                    <div class="opt-input-row"><div class="opt-badge">D</div><input class="fcontrol" name="opt_d" id="fOpt_d" placeholder="Option D…"></div>
                </div>

                <div class="fgroup" style="margin-bottom:.75rem">
                    <label class="flabel">Correct Answer</label>
                    <div class="correct-picker">
                        <?php foreach(['A','B','C','D'] as $oi=>$letter): ?>
                        <label class="correct-opt">
                            <input type="radio" name="correct_index" value="<?=$oi?>" <?=$oi===0?'checked':''?>>
                            Option <?=$letter?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="fgroup">
                    <label class="flabel">Explanation <span style="color:#475569;font-weight:400">(optional)</span></label>
                    <textarea class="fcontrol" id="fAnswerMcq" name="answer_text" rows="2" placeholder="Brief explanation shown after answering…"></textarea>
                </div>
            </div>

            <!-- ── Past Paper section ── -->
            <div id="ppSection" class="form-section" style="display:none">
                <div class="form-section-title">Upload File</div>
                <label class="upload-zone" id="uploadZone">
                    <input type="file" name="question_file" id="fFile"
                           accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.mp4,.webm"
                           onchange="onFileChange(this)">
                    <span id="uploadLabel">Click to select a file</span>
                    <span class="upload-hint">Max 50 MB</span>
                </label>
                <div id="currentFile" style="display:none;font-size:.72rem;color:#c4b5fd;margin-top:.4rem;padding:.4rem .65rem;border-radius:6px;background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.2)"></div>

                <div class="fgroup" style="margin-top:.75rem">
                    <label class="flabel">Answer / Notes <span style="color:#475569;font-weight:400">(optional)</span></label>
                    <textarea class="fcontrol" id="fAnswer" name="_answer_text_disabled" rows="3" placeholder="Add notes, answer key, or any explanation…"></textarea>
                </div>
            </div>

            <!-- ── Common footer ── -->
            <div class="frow cols-2" style="margin-bottom:0">
                <div class="fgroup">
                    <label class="flabel">Sort Order</label>
                    <input class="fcontrol" type="number" name="sort_order" id="fSort" value="0" min="0">
                </div>
                <div class="fgroup" style="justify-content:flex-end;padding-top:.2rem">
                    <label class="flabel">Visibility</label>
                    <div class="toggle-row">
                        <label class="toggle">
                            <input type="checkbox" name="is_active" id="fActive" checked>
                            <span class="toggle-track"></span>
                        </label>
                        <span class="toggle-label" id="activeLabel">Visible to users</span>
                    </div>
                </div>
            </div>

        </div><!-- /modal-body -->

        <div class="modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeQModal()">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btnSave">Save Question</button>
        </div>
    </form>
</div>
</div>

<script>
function openQModal() {
    document.getElementById('qModalTitle').textContent = 'Add Question';
    document.getElementById('fAction').value  = 'create';
    document.getElementById('fId').value      = '0';
    document.getElementById('qForm').reset();
    document.getElementById('currentFile').style.display = 'none';
    document.getElementById('uploadLabel').textContent   = 'Click to select a file from your device';
    onTypeChange('mcq');
    document.getElementById('qModalOverlay').classList.add('open');
}

function openEdit(q) {
    document.getElementById('qModalTitle').textContent = 'Edit Question';
    document.getElementById('fAction').value  = 'update';
    document.getElementById('fId').value      = q.id;
    document.getElementById('fCategory').value = q.category || 'general';
    document.getElementById('fForce').value    = q.target_force || 'all';
    document.getElementById('fQText').value    = q.question_text || '';
    document.getElementById('fSort').value     = q.sort_order || 0;
    document.getElementById('fActive').checked = (q.is_active == 1);
    document.getElementById('activeLabel').textContent = (q.is_active == 1) ? 'Visible to users' : 'Hidden from users';

    var type = q.question_type || 'mcq';
    onTypeChange(type);

    if (type === 'mcq') {
        var opts = ['','','',''];
        try { opts = q.options_json ? JSON.parse(q.options_json) : opts; } catch(e) {}
        ['a','b','c','d'].forEach(function(l, i) {
            var el = document.getElementById('fOpt_' + l);
            if (el) el.value = opts[i] || '';
        });
        var ci = parseInt(q.correct_index, 10) || 0;
        document.querySelectorAll('[name="correct_index"]').forEach(function(r, i) { r.checked = (i === ci); });
        document.getElementById('fAnswerMcq').value = q.answer_text || '';
    } else {
        document.getElementById('fAnswer').value = q.answer_text || '';
    }

    if (q.file_path) {
        var cf = document.getElementById('currentFile');
        var ext = q.file_path.split('.').pop().toUpperCase();
        cf.style.display = 'block';
        cf.textContent   = '📎 Current: ' + ext + ' file attached — upload a new file to replace it';
    } else {
        document.getElementById('currentFile').style.display = 'none';
    }

    document.getElementById('qModalOverlay').classList.add('open');
}

function closeQModal() {
    document.getElementById('qModalOverlay').classList.remove('open');
}

function overlayClose(e) {
    if (e.target === document.getElementById('qModalOverlay')) closeQModal();
}

function onTypeChange(type) {
    var isMCQ = (type === 'mcq');

    // Update tab visuals
    document.getElementById('tabMCQ').classList.toggle('active', isMCQ);
    document.getElementById('tabPP').classList.toggle('active', !isMCQ);

    // Show/hide sections
    document.getElementById('mcqSection').style.display = isMCQ ? '' : 'none';
    document.getElementById('ppSection').style.display  = isMCQ ? 'none' : '';

    // Wire answer_text to the right textarea
    document.getElementById('fAnswerMcq').name = isMCQ ? 'answer_text' : '_answer_text_disabled';
    document.getElementById('fAnswer').name    = isMCQ ? '_answer_text_disabled' : 'answer_text';

    // Update hidden input
    document.getElementById('fQtypeHidden').value = type;

    // Update save button label
    document.getElementById('btnSave').textContent = isMCQ ? 'Save MCQ' : 'Save Past Paper';
}

function onFileChange(input) {
    var lbl = document.getElementById('uploadLabel');
    if (input.files && input.files.length) {
        var f = input.files[0];
        var size = (f.size / 1024 / 1024).toFixed(1);
        lbl.textContent = '✓ ' + f.name + ' (' + size + ' MB)';
        document.getElementById('uploadZone').style.borderColor = 'rgba(16,185,129,.4)';
        document.getElementById('uploadZone').style.background  = 'rgba(16,185,129,.04)';
    } else {
        lbl.textContent = 'Click to select a file from your device';
        document.getElementById('uploadZone').style.borderColor = '';
        document.getElementById('uploadZone').style.background  = '';
    }
}

document.getElementById('fActive').addEventListener('change', function() {
    document.getElementById('activeLabel').textContent = this.checked ? 'Visible to users' : 'Hidden from users';
});

// Auto-dismiss flash
var fm = document.getElementById('flashMsg');
if (fm) setTimeout(function() {
    fm.style.transition = 'opacity .4s';
    fm.style.opacity    = '0';
    setTimeout(function() { fm.remove(); }, 400);
}, 4000);

<?php if ($editRow): ?>
openEdit(<?= json_encode($editRow, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>