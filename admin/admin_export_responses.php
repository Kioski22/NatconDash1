<?php
// Export quiz + evaluation responses for a given speaker into a single CSV
// Columns:
// Timestamp, Score, TAKE THE QUIZ AND EVALUATION, Full Name, <Quiz Questions...>, <Evaluation Questions...>, Comments
// Timestamp: earliest of quiz submission or evaluation submission for that speaker & user
// Score: quiz score (blank if no quiz)
// TAKE THE QUIZ AND EVALUATION: Speaker Title + Name (same for all rows)
// Full Name: User name (from users table) or synthesized
// Quiz question columns contain the user's submitted answer text (option text or paragraph answer)
// Evaluation question columns contain numeric value (scale), option text (choice), or blank for non-applicable; Comments column holds free-text comment

session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo 'DB unavailable'; exit; }

$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) {
  // Render selection interface
  $speakers = [];
  $res = $db->query("SELECT s.id,s.name,s.title, fr.name AS room_name, fd.label AS day_label FROM speakers s LEFT JOIN function_rooms fr ON fr.id=s.room_id LEFT JOIN function_room_days fd ON fd.id=fr.day_id ORDER BY fd.day_date ASC, fr.id ASC, s.name ASC");
  if ($res) { while($row=$res->fetch_assoc()) $speakers[]=$row; }
  ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Export Quiz & Evaluation Responses</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{padding:2rem;background:#f5f7fa;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;} .card{border-radius:14px;box-shadow:0 4px 18px rgba(0,0,0,.08);} .table-hover tbody tr:hover{background:#eef2ff;} .badge-day{background:#111e6c;} .small-muted{font-size:.75rem;opacity:.65;} .search-box{max-width:340px;} </style></head><body><div class="container"><div class="card p-4"> <h3 class="mb-3">Export Quiz & Evaluation Responses</h3><p class="text-muted mb-4">Select a speaker to download a consolidated CSV containing quiz answers, scores, and evaluation feedback.</p><div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><input type="text" id="search" class="form-control search-box" placeholder="Search speaker..."><span class="small-muted">Total Speakers: <?= count($speakers) ?></span></div><?php if(empty($speakers)): ?><div class="alert alert-info">No speakers available.</div><?php else: ?><div class="table-responsive" style="max-height:60vh;overflow:auto;"><table class="table table-sm table-hover align-middle" id="speakersTbl"><thead class="table-light"><tr><th>ID</th><th>Name / Title</th><th>Day</th><th>Room</th><th>Action</th></tr></thead><tbody><?php foreach($speakers as $sp): $label = trim(($sp['title']? $sp['title'].' ': '').$sp['name']); ?><tr><td class="text-muted"><?= (int)$sp['id'] ?></td><td><strong><?= htmlspecialchars($sp['name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($sp['title'] ?: '—') ?></small></td><td><?= htmlspecialchars($sp['day_label'] ?: '-') ?></td><td><?= htmlspecialchars($sp['room_name'] ?: '-') ?></td><td><a class="btn btn-sm btn-primary" href="admin_export_responses.php?speaker_id=<?= (int)$sp['id'] ?>">Export</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?><a href="Admin_dashboard.php" class="btn btn-link mt-3">&larr; Back to Dashboard</a></div></div><script>const q=document.getElementById('search'); q&&q.addEventListener('input',()=>{ const term=q.value.toLowerCase(); document.querySelectorAll('#speakersTbl tbody tr').forEach(tr=>{ const txt=tr.textContent.toLowerCase(); tr.style.display= txt.includes(term)?'':'none'; }); });</script></body></html><?php exit; }

// Fetch speaker info
$speaker = null; $speakerTitleName = '';
if ($st = $db->prepare('SELECT id,name,title FROM speakers WHERE id=? LIMIT 1')) {
  $st->bind_param('i', $speaker_id); $st->execute(); $speaker = $st->get_result()->fetch_assoc(); $st->close();
}
if (!$speaker) { http_response_code(404); echo 'Speaker not found'; exit; }
$speakerTitleName = trim(($speaker['title'] ? $speaker['title'].' ' : '').$speaker['name']);

// Fetch quiz (single) for speaker
$quiz = null; $quizQuestions = []; $quizOptions = []; // arrays parallel
if ($st = $db->prepare('SELECT id,questions,options FROM quizzes WHERE speaker_id=? LIMIT 1')) {
  $st->bind_param('i', $speaker_id); $st->execute(); $st->bind_result($quiz_id,$qjson,$ojson);
  if ($st->fetch()) {
    $quiz = ['id'=>$quiz_id];
    $quizQuestions = json_decode((string)$qjson,true) ?: [];
    $quizOptions   = json_decode((string)$ojson,true) ?: [];
  }
  $st->close();
}

// Active evaluation questions (dynamic system) – fallback to empty if tables absent
$evalQuestions = []; // list of ['id'=>, 'text'=>, 'type'=>]
$evalQuestionMap = [];
$commentsQuestionIds = [];
function table_exists($db,$name){ $r=$db->query("SHOW TABLES LIKE '".$db->real_escape_string($name)."'"); $ok=$r&&$r->num_rows>0; if($r) $r->close(); return $ok; }
if (table_exists($db,'speaker_eval_question_sets') && table_exists($db,'speaker_eval_questions') && table_exists($db,'speaker_eval_responses')) {
  $setId = 0; if ($r=$db->query('SELECT id FROM speaker_eval_question_sets WHERE is_active=1 ORDER BY id DESC LIMIT 1')) { if($row=$r->fetch_assoc()) $setId=(int)$row['id']; $r->close(); }
  if ($setId>0) {
    if ($r=$db->query('SELECT id,question_text,question_type FROM speaker_eval_questions WHERE question_set_id='.(int)$setId.' ORDER BY sort_order ASC,id ASC')) {
      while($row=$r->fetch_assoc()) {
        $qid=(int)$row['id']; $txt=$row['question_text']; $type=$row['question_type'];
        $evalQuestions[]=['id'=>$qid,'text'=>$txt,'type'=>$type];
        $evalQuestionMap[$qid]=['text'=>$txt,'type'=>$type];
        if (stripos($txt,'comment')!==false || stripos($txt,'remarks')!==false) $commentsQuestionIds[]=$qid;
      }
      $r->close();
    }
  }
}
// Decide which evaluation question will map to the exported Comments column: choose first comment-like; else first text/textarea; else none
$commentsQid = null;
if ($commentsQuestionIds) { $commentsQid = $commentsQuestionIds[0]; }
else {
  foreach($evalQuestions as $q){ if (in_array($q['type'],['text','textarea','open_text','long_text'])) { $commentsQid=$q['id']; break; } }
}

// Build CSV header
$csvHeader = ['Timestamp','Score','TAKE THE QUIZ AND EVALUATION','Full Name'];
// Quiz question headers
foreach ($quizQuestions as $idx=>$qt) {
  $h = 'Question '.($idx+1).'. '.preg_replace('/\s+/',' ',trim((string)$qt));
  $csvHeader[] = $h;
}
// Evaluation headers (excluding comment question which goes to Comments column)
foreach ($evalQuestions as $q) {
  if ($q['id'] === $commentsQid) continue;
  $csvHeader[] = preg_replace('/\s+/',' ',trim($q['text']));
}
$csvHeader[] = 'Comments';

// Collect user submissions: quiz responses
$userRows = []; // user_id => base row data
if ($quiz) {
  $sql = 'SELECT qr.user_id, qr.answers, qr.score, qr.created_at, u.name AS user_name FROM quiz_responses qr LEFT JOIN users u ON u.id=qr.user_id WHERE qr.speaker_id='.(int)$speaker_id;
  if ($res=$db->query($sql)) {
    while($row=$res->fetch_assoc()) {
      $uid=(int)$row['user_id'];
      $answers = json_decode((string)$row['answers'],true);
      if(!is_array($answers)) $answers=[];
      $userRows[$uid] = $userRows[$uid] ?? [
        'quiz_timestamp'=>$row['created_at'],
        'eval_timestamp'=>null,
        'score'=>$row['score'],
        'user_name'=>$row['user_name'] ?? '' ,
        'quiz_answers'=>$answers,
        'eval_answers'=>[],
        'comment'=>''
      ];
      // Keep earliest timestamp for combined
      if (!empty($userRows[$uid]['quiz_timestamp']) && $row['created_at'] < $userRows[$uid]['quiz_timestamp']) {
        $userRows[$uid]['quiz_timestamp'] = $row['created_at'];
      }
    }
    $res->close();
  }
}

// Evaluation responses (dynamic)
if ($evalQuestions) {
  // Map response -> user
  $evalSql = 'SELECT r.id AS rid, r.user_id, r.created_at, ri.question_id, ri.numeric_value, ri.text_value, u.name AS user_name
              FROM speaker_eval_responses r
              JOIN speaker_eval_response_items ri ON ri.response_id=r.id
              LEFT JOIN users u ON u.id=r.user_id
              WHERE r.speaker_id='.(int)$speaker_id;
  if ($res=$db->query($evalSql)) {
    while($row=$res->fetch_assoc()) {
      $uid=(int)$row['user_id'];
      if(!isset($userRows[$uid])) {
        $userRows[$uid]=[
          'quiz_timestamp'=>null,
          'eval_timestamp'=>$row['created_at'],
          'score'=>'',
          'user_name'=>$row['user_name'] ?? '',
          'quiz_answers'=>[],
          'eval_answers'=>[],
          'comment'=>''
        ];
      }
      // earliest eval timestamp
      if (!$userRows[$uid]['eval_timestamp'] || $row['created_at'] < $userRows[$uid]['eval_timestamp']) {
        $userRows[$uid]['eval_timestamp']=$row['created_at'];
      }
      $qid=(int)$row['question_id'];
      if ($qid === $commentsQid) {
        $val = trim((string)($row['text_value'] ?? ''));
        if ($val !== '') { // concatenate if multiple
          if ($userRows[$uid]['comment']!=='') $userRows[$uid]['comment'].=' | ';
          $userRows[$uid]['comment'].=$val;
        }
      } else {
        $qmeta = $evalQuestionMap[$qid] ?? null;
        if ($qmeta) {
          $etype = $qmeta['type'];
            $val = '';
            if (in_array($etype,['scale','rating','number','numeric'])) {
              $val = ($row['numeric_value'] !== null) ? (string)$row['numeric_value'] : '';
            } elseif (in_array($etype,['multiple_choice_single','dropdown'])) {
              $val = trim((string)$row['text_value']);
            } else { // text type that isn't comments
              $val = trim((string)$row['text_value']);
            }
            $userRows[$uid]['eval_answers'][$qid] = $val;
        }
      }
    }
    $res->close();
  }
}

// For quiz option text mapping
function quiz_answer_text($idx,$optionsArr) {
  if (!is_array($optionsArr)) return '';
  return isset($optionsArr[$idx]) ? (string)$optionsArr[$idx] : (is_int($idx)?(string)$idx:'');
}

// Prepare download
$filename = 'speaker_'.$speaker_id.'_responses_'.date('Ymd_His').'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache'); header('Expires: 0');
$out = fopen('php://output','w');
// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out,$csvHeader);

// Sort users by timestamp ascending
usort($userRows, function($a,$b){
  $ta = $a['quiz_timestamp'] ?? $a['eval_timestamp'] ?? '';
  $tb = $b['quiz_timestamp'] ?? $b['eval_timestamp'] ?? '';
  return strcmp($ta,$tb);
});

foreach ($userRows as $uid => $data) {
  // Build row
  $timestamp = $data['quiz_timestamp'] ?: ($data['eval_timestamp'] ?: '');
  $score = $data['score'];
  $userName = $data['user_name'];
  // Quiz answers mapped to text
  $quizAnswerTexts = [];
  foreach ($quizQuestions as $i=>$qText) {
    $ans = $data['quiz_answers'][$i] ?? '';
    if (is_int($ans) || (is_numeric($ans) && (string)(int)$ans === (string)$ans)) {
      $opts = $quizOptions[$i] ?? [];
      $quizAnswerTexts[] = quiz_answer_text((int)$ans,$opts);
    } else {
      $quizAnswerTexts[] = is_string($ans)?$ans:'';
    }
  }
  // Evaluation answers in order (excluding comments)
  $evalVals = [];
  foreach ($evalQuestions as $q) {
    if ($q['id'] === $commentsQid) continue;
    $evalVals[] = $data['eval_answers'][$q['id']] ?? '';
  }
  $comment = $data['comment'];
  $row = array_merge([$timestamp,$score,$speakerTitleName,$userName], $quizAnswerTexts, $evalVals, [$comment]);
  fputcsv($out,$row);
}

fclose($out);
exit;