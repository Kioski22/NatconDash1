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

// Fetch speaker info (avoid get_result for cPanel compatibility)
$speaker = null; $speakerTitleName = '';
if ($st = $db->prepare('SELECT id,name,title FROM speakers WHERE id=? LIMIT 1')) {
  $st->bind_param('i', $speaker_id);
  if ($st->execute()) {
    $sid = 0; $sname = ''; $stitle = '';
    $st->bind_result($sid,$sname,$stitle);
    if ($st->fetch()) {
      $speaker = ['id'=>$sid,'name'=>$sname,'title'=>$stitle];
    }
  }
  $st->close();
}
if (!$speaker) { http_response_code(404); echo 'Speaker not found'; exit; }
$speakerTitleName = trim(($speaker['title'] ? $speaker['title'].' ' : '').$speaker['name']);

// Fetch quiz (single) for speaker - use bind_result strictly (no get_result)
$quiz = null; $quizQuestions = []; $quizOptions = []; $quizAnswers = []; $quizPassingScore = null; // arrays parallel
if ($st = $db->prepare('SELECT id,questions,options,answers,passing_score FROM quizzes WHERE speaker_id=? LIMIT 1')) {
  $st->bind_param('i', $speaker_id);
  if ($st->execute()) {
    $quiz_id = 0; $qjson = null; $ojson = null; $ajson = null; $ps = null;
    $st->bind_result($quiz_id,$qjson,$ojson,$ajson,$ps);
    if ($st->fetch()) {
      $quiz = ['id'=>$quiz_id];
      $quizQuestions = json_decode((string)$qjson,true) ?: [];
      $quizOptions   = json_decode((string)$ojson,true) ?: [];
      $quizAnswers   = json_decode((string)$ajson,true) ?: [];
      if ($ps !== null && $ps !== '') $quizPassingScore = (int)$ps;
    }
  }
  $st->close();
}

// Fallback: legacy quiz_questions table -> question headers only (no options mapping)
function column_exists($db,$table,$col){ $res=$db->query("SHOW COLUMNS FROM `".$db->real_escape_string($table)."` LIKE '".$db->real_escape_string($col)."'"); $ok=$res && $res->num_rows>0; if($res) $res->close(); return $ok; }
if ((!$quiz || empty($quizQuestions)) && table_exists($db,'quiz_questions')) {
  $col = null; foreach(['question_text','question','text'] as $try){ if(column_exists($db,'quiz_questions',$try)){ $col=$try; break; } }
  if ($col) {
    if ($st=$db->prepare("SELECT id, `".$col."` FROM quiz_questions WHERE speaker_id=? ORDER BY id ASC")){
      $st->bind_param('i',$speaker_id); if($st->execute()){ $qid=0; $qtxt=''; $st->bind_result($qid,$qtxt); $tmp=[]; while($st->fetch()){ $tmp[] = (string)$qtxt; } if($tmp) { $quizQuestions = $tmp; } } $st->close();
    }
  }
}

// Determine passing score threshold (percent) from admin settings if available
// For pass/fail we prefer the per-quiz required-correct count (passing_score). If none, no pass/fail shown.
$passingRequiredCorrect = $quizPassingScore; // integer or null

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

// Legacy evaluation fallback (speaker_evaluations table) when no dynamic eval questions are configured
$useLegacyEval = false; $legacyEvalHeaders = [];
if (empty($evalQuestions) && table_exists($db,'speaker_evaluations')) {
  $useLegacyEval = true;
  // Standard legacy fields
  $legacyEvalHeaders = ['Content Quality','Speaker Clarity','Engagement','Overall'];
}

// Build CSV header
$csvHeader = ['Timestamp','Score','Pass or Fail','TAKE THE QUIZ AND EVALUATION','Full Name'];
// Quiz question headers
foreach ($quizQuestions as $idx=>$qt) {
  $h = 'Question '.($idx+1).'. '.preg_replace('/\s+/',' ',trim((string)$qt));
  $csvHeader[] = $h;
}
// Evaluation headers (excluding comment question which goes to Comments column)
if (!$useLegacyEval) {
  foreach ($evalQuestions as $q) {
    if ($q['id'] === $commentsQid) continue;
    $csvHeader[] = preg_replace('/\s+/',' ',trim($q['text']));
  }
} else {
  foreach ($legacyEvalHeaders as $lh) { $csvHeader[] = $lh; }
}
$csvHeader[] = 'Comments';

// Collect user submissions: quiz responses
$userRows = []; // key => base row data (key: id:<id> or email:<email>)
function make_user_key($user_id, $email=''){
  $uid = (int)$user_id;
  if ($uid > 0) return 'id:'.$uid;
  $em = trim(strtolower((string)$email));
  return $em !== '' ? 'email:'.$em : 'id:0';
}
if ($quiz) {
  $sql = 'SELECT qr.user_id, qr.answers, qr.score, qr.created_at, u.name AS user_name, u.email AS user_email FROM quiz_responses qr LEFT JOIN users u ON u.id=qr.user_id WHERE qr.speaker_id='.(int)$speaker_id;
  if ($res=$db->query($sql)) {
    while($row=$res->fetch_assoc()) {
      $uid=(int)$row['user_id']; $uemail = (string)($row['user_email'] ?? '');
      $key = make_user_key($uid,$uemail);
      $answers = json_decode((string)$row['answers'],true);
      if(!is_array($answers)) $answers=[];
      $userRows[$key] = $userRows[$key] ?? [
        'quiz_timestamp'=>$row['created_at'],
        'eval_timestamp'=>null,
        'score'=>$row['score'],
        'user_name'=>$row['user_name'] ?? '' ,
        'user_email'=>$uemail,
        'quiz_answers'=>$answers,
        'eval_answers'=>[],
        'eval_legacy'=>null,
        'comment'=>''
      ];
      // Keep earliest timestamp for combined
      if (!empty($userRows[$key]['quiz_timestamp']) && $row['created_at'] < $userRows[$key]['quiz_timestamp']) {
        $userRows[$key]['quiz_timestamp'] = $row['created_at'];
      }
    }
    $res->close();
  }
}

// Evaluation responses (dynamic)
if ($evalQuestions && !$useLegacyEval) {
  // Map response -> user
  // Detect optional columns for email and submitted timestamp
  $hasUserEmail = column_exists($db,'speaker_eval_responses','user_email') || column_exists($db,'speaker_eval_responses','email');
  $submittedCol = column_exists($db,'speaker_eval_responses','submitted_at') ? 'submitted_at' : (column_exists($db,'speaker_eval_responses','created_at') ? 'created_at' : 'submitted_at');
  $emailSel = $hasUserEmail ? ", COALESCE(r.user_email, r.email) AS user_email" : ", NULL AS user_email";
  // Preload option text map for choice questions
  $qidList = array_column($evalQuestions,'id'); $optTextByQ = [];
  if (!empty($qidList) && table_exists($db,'speaker_eval_options')) {
    $ids = implode(',', array_map('intval',$qidList));
    if ($ro=$db->query("SELECT id,question_id,option_text FROM speaker_eval_options WHERE question_id IN ($ids)")){
      while($o=$ro->fetch_assoc()){ $qid=(int)$o['question_id']; $oid=(int)$o['id']; if(!isset($optTextByQ[$qid])) $optTextByQ[$qid]=[]; $optTextByQ[$qid][$oid]=(string)$o['option_text']; }
      $ro->close();
    }
  }
  $evalSql = "SELECT r.id AS rid, r.user_id, r.$submittedCol AS submitted_at, ri.question_id, ri.numeric_value, ri.option_id, ri.text_answer, ri.checkbox_values, u.name AS user_name$emailSel
              FROM speaker_eval_responses r
              JOIN speaker_eval_response_items ri ON ri.response_id=r.id
              LEFT JOIN users u ON u.id=r.user_id
              WHERE r.speaker_id=".(int)$speaker_id;
  if ($res=$db->query($evalSql)) {
    while($row=$res->fetch_assoc()) {
      $uid=(int)$row['user_id']; $uem = isset($row['user_email']) ? trim(strtolower((string)$row['user_email'])) : '';
      $key = make_user_key($uid,$uem);
      if(!isset($userRows[$key])) {
        $userRows[$key]=[
          'quiz_timestamp'=>null,
          'eval_timestamp'=>$row['submitted_at'],
          'score'=>'',
          'user_name'=>$row['user_name'] ?? '',
          'user_email'=>$uem,
          'quiz_answers'=>[],
          'eval_answers'=>[],
          'eval_legacy'=>null,
          'comment'=>''
        ];
      }
      // earliest eval timestamp
      if (!$userRows[$key]['eval_timestamp'] || $row['submitted_at'] < $userRows[$key]['eval_timestamp']) {
        $userRows[$key]['eval_timestamp']=$row['submitted_at'];
      }
      $qid=(int)$row['question_id'];
      if ($qid === $commentsQid) {
        $val = trim((string)($row['text_answer'] ?? ''));
        if ($val !== '') { // concatenate if multiple
          if ($userRows[$key]['comment']!=='') $userRows[$key]['comment'].=' | ';
          $userRows[$key]['comment'].=$val;
        }
      } else {
        $qmeta = $evalQuestionMap[$qid] ?? null;
        if ($qmeta) {
          $etype = $qmeta['type'];
            $val = '';
            if (in_array($etype,['scale','rating','number','numeric'])) {
              $val = ($row['numeric_value'] !== null) ? (string)$row['numeric_value'] : '';
            } elseif (in_array($etype,['multiple_choice_single','dropdown'])) {
              $oid = isset($row['option_id']) ? (int)$row['option_id'] : 0; $val = '';
              if ($oid && isset($optTextByQ[$qid][$oid])) $val = $optTextByQ[$qid][$oid];
              else $val = trim((string)($row['text_answer'] ?? ''));
            } elseif (in_array($etype,['multiple_choice_multi','checkbox'])) {
              $val = '';
              $raw = (string)($row['checkbox_values'] ?? '');
              $arr = null; $tmp = trim($raw);
              if ($tmp !== '') { $arr = json_decode($tmp,true); if(!is_array($arr)) $arr = null; }
              if ($arr) {
                $parts=[]; foreach($arr as $oid){ $oid=(int)$oid; $parts[] = isset($optTextByQ[$qid][$oid]) ? $optTextByQ[$qid][$oid] : (string)$oid; }
                $val = implode('; ', array_filter($parts, fn($s)=>trim((string)$s) !== ''));
              } else {
                $val = trim((string)($row['text_answer'] ?? ''));
              }
            } else { // text type that isn't comments
              $val = trim((string)($row['text_answer'] ?? ''));
            }
            $userRows[$key]['eval_answers'][$qid] = $val;
        }
      }
    }
    $res->close();
  }
}

// Legacy evaluation data fill if applicable
if ($useLegacyEval) {
  // Preload user names by email if possible (batch later if needed)
  $sql = "SELECT user_id, COALESCE(user_email,email) AS em, content_quality, speaker_clarity, engagement, overall, comments, created_at FROM speaker_evaluations WHERE speaker_id=".(int)$speaker_id;
  if ($res=$db->query($sql)) {
    $emailToName = [];
    $emails = [];
    $rows = [];
    while($row=$res->fetch_assoc()) { $rows[]=$row; $em = trim(strtolower((string)($row['em'] ?? ''))); if($em!=='') $emails[$em]=true; }
    $res->close();
    if (!empty($emails) && table_exists($db,'users')) {
      $in = implode(',', array_fill(0, count($emails), '?'));
      // Build a dynamic prepared statement for email lookup
      $types = str_repeat('s', count($emails));
      $stmt = $db->prepare("SELECT email,name FROM users WHERE LOWER(email) IN (".$in.")");
      if ($stmt) {
        $vals = array_keys($emails);
        // bind_param with dynamic args
        $bind = [$types]; foreach($vals as $v){ $bind[]=$v; }
        // Use call_user_func_array hack for dynamic binding
        $tmp = [];
        foreach($bind as $k=>$v){ $tmp[$k] = $v; }
        $refs = [];
        foreach($tmp as $k=>&$v){ $refs[$k] = &$v; }
        call_user_func_array([$stmt,'bind_param'],$refs);
        if ($stmt->execute()) {
          $stmt->bind_result($e,$n);
          while($stmt->fetch()) { $emailToName[trim(strtolower((string)$e))] = (string)$n; }
        }
        $stmt->close();
      }
    }
    foreach($rows as $row){
      $uid=(int)$row['user_id']; $em = trim(strtolower((string)($row['em'] ?? '')));
      $key = make_user_key($uid,$em);
      if(!isset($userRows[$key])){
        $userRows[$key]=[
          'quiz_timestamp'=>null,
          'eval_timestamp'=>$row['created_at'],
          'score'=>'',
          'user_name'=>'',
          'user_email'=>$em,
          'quiz_answers'=>[],
          'eval_answers'=>[],
          'eval_legacy'=>[ (string)$row['content_quality'], (string)$row['speaker_clarity'], (string)$row['engagement'], (string)$row['overall'] ],
          'comment'=>trim((string)$row['comments'])
        ];
        // Fill name if available via email map
        if ($userRows[$key]['user_name']==='' && $em!=='' && isset($emailToName[$em])) $userRows[$key]['user_name']=$emailToName[$em];
      } else {
        // Merge earliest timestamp and values (if multiple legacy rows exist)
        if (!$userRows[$key]['eval_timestamp'] || $row['created_at'] < $userRows[$key]['eval_timestamp']) $userRows[$key]['eval_timestamp']=$row['created_at'];
        $userRows[$key]['eval_legacy'] = $userRows[$key]['eval_legacy'] ?: [ (string)$row['content_quality'], (string)$row['speaker_clarity'], (string)$row['engagement'], (string)$row['overall'] ];
        $c = trim((string)$row['comments']); if ($c!==''){ if($userRows[$key]['comment']!=='') $userRows[$key]['comment'].=' | '; $userRows[$key]['comment'].=$c; }
      }
    }
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
// Convert associative to indexed for sorting
$rowsList = array_values($userRows);
// Fill missing names from users table using email if possible (one batch)
$emails = [];
foreach ($rowsList as $r) { if (($r['user_name']??'')==='' && ($r['user_email']??'')!==''){ $emails[strtolower($r['user_email'])] = true; } }
if (!empty($emails) && table_exists($db,'users')) {
  $in = implode(',', array_fill(0,count($emails),'?')); $types = str_repeat('s', count($emails));
  if ($st = $db->prepare("SELECT LOWER(email) AS e, name FROM users WHERE LOWER(email) IN ($in)")){
    $vals = array_keys($emails); $bind = [$types]; foreach($vals as $v){ $bind[]=$v; }
    $tmp = []; foreach($bind as $k=>$v){ $tmp[$k]=$v; } $refs=[]; foreach($tmp as $k=>&$v){ $refs[$k]=&$v; }
    call_user_func_array([$st,'bind_param'],$refs);
    if ($st->execute()){
      $st->bind_result($e,$n); $map=[]; while($st->fetch()){ $map[$e]=$n; }
      foreach ($rowsList as &$r) { $em=strtolower($r['user_email']??''); if($r['user_name']==='' && $em!=='' && isset($map[$em])) $r['user_name']=$map[$em]; }
      unset($r);
    }
    $st->close();
  }
}
usort($rowsList, function($a,$b){
  $ta = $a['quiz_timestamp'] ?? $a['eval_timestamp'] ?? '';
  $tb = $b['quiz_timestamp'] ?? $b['eval_timestamp'] ?? '';
  return strcmp($ta,$tb);
});

foreach ($rowsList as $data) {
  // Build row
  $timestamp = $data['quiz_timestamp'] ?: ($data['eval_timestamp'] ?: '');
  $score = $data['score'];
  // Compute pass/fail based on per-quiz required-correct count if available
  $passFail = '';
  $totalQuestions = count($quizAnswers) > 0 ? count($quizAnswers) : (count($quizQuestions) > 0 ? count($quizQuestions) : 0);
  if ($score !== '' && $score !== null && $totalQuestions > 0 && $passingRequiredCorrect !== null) {
    $passFail = ((int)$score >= (int)$passingRequiredCorrect) ? 'PASS' : 'FAIL';
  }
  $userName = $data['user_name'];
  // Quiz answers mapped to text
  $quizAnswerTexts = [];
  foreach ($quizQuestions as $i=>$qText) {
    $ans = $data['quiz_answers'][$i] ?? '';
    $opts = $quizOptions[$i] ?? [];
    if (is_array($ans)) {
      // Map each selected index to option text and join
      $parts = [];
      foreach ($ans as $one) { if (is_numeric($one)) { $parts[] = quiz_answer_text((int)$one,$opts); } }
      $quizAnswerTexts[] = implode('; ', array_filter($parts, function($s){ return trim((string)$s) !== ''; }));
    } elseif (is_int($ans) || (is_numeric($ans) && (string)(int)$ans === (string)$ans)) {
      $quizAnswerTexts[] = quiz_answer_text((int)$ans,$opts);
    } else {
      $quizAnswerTexts[] = is_string($ans)?$ans:'';
    }
  }
  // Evaluation answers in order (excluding comments)
  $evalVals = [];
  if (!$useLegacyEval) {
    foreach ($evalQuestions as $q) {
      if ($q['id'] === $commentsQid) continue;
      $evalVals[] = $data['eval_answers'][$q['id']] ?? '';
    }
  } else {
    $evl = $data['eval_legacy'] ?? null; if (!is_array($evl)) $evl = ['', '', '', ''];
    // Ensure 4 columns
    for ($i=0;$i<4;$i++){ $evalVals[] = isset($evl[$i]) ? $evl[$i] : ''; }
  }
  $comment = $data['comment'];
  $row = array_merge([$timestamp,$score,$passFail,$speakerTitleName,$userName], $quizAnswerTexts, $evalVals, [$comment]);
  fputcsv($out,$row);
}

fclose($out);
exit;