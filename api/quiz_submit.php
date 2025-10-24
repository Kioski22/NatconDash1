<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../db.php';

// DB
if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

// Auth (adjust to your app)
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['error'=>'Login required']); exit; }

// Inputs
$quiz_id    = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
$speaker_id = isset($_POST['speaker_id']) ? (int)$_POST['speaker_id'] : 0;
if ($quiz_id <= 0 || $speaker_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid payload']); exit; }

// Parse answers from multiple formats: answers_json (preferred), answers[] (form array), answers (JSON string), or qN/qN_text fields
function parse_answers_from_post(array $post) {
  if (isset($post['answers_json'])) {
    $a = json_decode((string)$post['answers_json'], true);
    if (is_array($a)) return $a;
  }
  if (isset($post['answers'])) {
    if (is_array($post['answers'])) return array_values($post['answers']);
    $as = json_decode((string)$post['answers'], true);
    if (is_array($as)) return $as;
  }
  // Fallback: q0, q1 (MCQ index), q0_text, q1_text (paragraph)
  $answers = [];
  $i = 0;
  while (true) {
    $keyIdx = 'q'.$i;
    $keyTxt = 'q'.$i.'_text';
    if (!array_key_exists($keyIdx, $post) && !array_key_exists($keyTxt, $post)) break;
    if (array_key_exists($keyIdx, $post) && $post[$keyIdx] !== '') {
      $answers[] = (int)$post[$keyIdx];
    } else {
      $answers[] = trim((string)($post[$keyTxt] ?? ''));
    }
    $i++;
  }
  return $answers;
}

$answersArr = parse_answers_from_post($_POST);
if (!is_array($answersArr)) { http_response_code(400); echo json_encode(['error'=>'Answers must be an array']); exit; }

// Load quiz (correct answers)
$stmt = $db->prepare("SELECT q.answers, q.options, q.day_id, q.room_id, IFNULL(q.passing_score, NULL) AS passing_score FROM quizzes q WHERE q.id = ? AND q.speaker_id = ? LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$stmt->bind_param('ii', $quiz_id, $speaker_id);
$stmt->execute();
$stmt->bind_result($answersJson, $optionsJson, $day_id, $room_id, $passing_score);
if (!$stmt->fetch()) { $stmt->close(); http_response_code(404); echo json_encode(['error'=>'Quiz not found']); exit; }
$stmt->close();

$correct = json_decode((string)$answersJson, true);
if (!is_array($correct)) { http_response_code(500); echo json_encode(['error'=>'Quiz data corrupted']); exit; }
if (count($answersArr) !== count($correct)) { http_response_code(400); echo json_encode(['error'=>'Answer count mismatch']); exit; }

$options = json_decode((string)$optionsJson, true);
if (!is_array($options)) { $options = []; }

// Paragraph grading thresholds
$PARA_MIN_CHARS = 20;
$PARA_MIN_WORDS = 3;

function _mb_strlen_safe($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
function _mb_substr_safe($s, $start, $len) { return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len); }
function _word_count_unicode($text) {
  $text = trim((string)$text);
  if ($text === '') return 0;
  if (preg_match_all('/\p{L}[\p{L}\p{Mn}\p{Pd}\']*/u', $text, $m)) return count($m[0]);
  $text = preg_replace('/[^\p{L}\s]/u', ' ', $text);
  $parts = preg_split('/\s+/', trim($text));
  return ($parts && $parts[0] !== '') ? count($parts) : 0;
}
function _paragraph_is_correct($text, $minChars, $minWords) {
  $t = trim((string)$text);
  if (_mb_strlen_safe($t) < $minChars) return false;
  if (_word_count_unicode($t) < $minWords) return false;
  return true;
}

// Score
$score = 0;
$totalGradable = count($correct);
$normalizedAnswers = [];
$MAX_TEXT_LEN = 4000;

for ($i = 0; $i < count($correct); $i++) {
  $corr = $correct[$i] ?? null;
  $user = $answersArr[$i] ?? null;
  $optSet = $options[$i] ?? null;
  $isParagraph = (is_array($optSet) && count($optSet) === 0) || $corr === null;

  if (!$isParagraph) {
    // Multiple choice (legacy support: $corr can be int or array)
    if (is_array($corr)) {
      $corrSet = array_values(array_unique(array_map('intval', $corr)));
    } elseif ($corr === '' || $corr === false) {
      $corrSet = [];
    } else {
      $corrSet = [(int)$corr];
    }
    sort($corrSet);

    if (is_array($user)) {
      $userSet = array_values(array_unique(array_map('intval', $user)));
    } elseif ($user === '' || $user === null) {
      $userSet = [];
    } else {
      $userSet = [(int)$user];
    }
    sort($userSet);

    if (!empty($corrSet) && $userSet === $corrSet) $score++;
    $normalizedAnswers[] = $userSet;
  } else {
    // Paragraph: trim and cap length
    $txt = is_string($user) ? $user : (is_null($user) ? '' : strval($user));
    $txt = trim($txt);
    if (_mb_strlen_safe($txt) > $MAX_TEXT_LEN) $txt = _mb_substr_safe($txt, 0, $MAX_TEXT_LEN);
    if (_paragraph_is_correct($txt, $PARA_MIN_CHARS, $PARA_MIN_WORDS)) $score++;
    $normalizedAnswers[] = $txt;
  }
}

$ansJson = json_encode($normalizedAnswers, JSON_UNESCAPED_UNICODE);

// Compute pass/fail based on required correct count if configured
// Determine passing rule
$passed = true; $required = null; $rule = 'none';

// Helper to fetch admin settings safely (no mysqlnd)
$get_setting = function(mysqli $db, string $key, $default=null){
  if(!$st=$db->prepare('SELECT setting_value FROM admin_settings WHERE setting_key=? LIMIT 1')) return $default;
  $st->bind_param('s',$key); $st->execute(); $st->bind_result($val); $out=$default; if($st->fetch()){ $out=$val; } $st->close(); return $out;
};

// Try quiz-specific absolute passing_score first (number of correct answers)
if ($passing_score !== null && $passing_score !== '' && is_numeric($passing_score)) {
  $required = max(0, (int)$passing_score);
  if ($totalGradable > 0 && $required > $totalGradable) { $required = $totalGradable; }
  $passed = ($score >= $required);
  $rule = 'quiz_absolute';
} else {
  // Fallback to admin-configured percentage threshold
  // Detect program type of the speaker (sub vs main) to choose the threshold key
  $program_type = '';
  if ($stp = $db->prepare('SELECT program_type FROM speakers WHERE id=? LIMIT 1')) {
    $stp->bind_param('i',$speaker_id); $stp->execute(); $stp->bind_result($program_type); $stp->fetch(); $stp->close();
  }
  $program_type = strtolower((string)$program_type);
  // Choose keys: sub -> sub_cert_min_score; main -> main_cert_min_score; fallback -> cert_min_score
  $pct = null;
  if ($program_type === 'sub') {
    $v = $get_setting($db,'sub_cert_min_score', null);
    if ($v === null || $v === '') $v = $get_setting($db,'cert_min_score', null);
    if ($v !== null && $v !== '') $pct = (int)$v;
  } else {
    $v = $get_setting($db,'main_cert_min_score', null);
    if ($v === null || $v === '') $v = $get_setting($db,'cert_min_score', null);
    if ($v !== null && $v !== '') $pct = (int)$v;
  }
  if ($pct !== null && $pct > 0 && $totalGradable > 0) {
    // Evaluate by percentage directly to avoid rounding disputes
    $percentScore = ($score * 100.0) / $totalGradable;
    $passed = ((int)round($percentScore) >= (int)$pct);
    // Provide a derived required count for UI clarity (ceiling of required correct answers)
    $required = (int)ceil(($pct/100.0) * $totalGradable);
    if ($required > $totalGradable) $required = $totalGradable;
    $rule = 'admin_percent';
  } else {
    $passed = true; // no threshold configured
    $required = null;
    $rule = 'none';
  }
}

// If not passed, do not save; allow retry
if ($required !== null && !$passed) {
  echo json_encode([
    'ok'=>true,
    'passed'=>false,
    'score'=>$score,
    'total'=>$totalGradable,
    'required'=>$required,
    'rule'=>$rule,
    'message'=>'You did not meet the passing score. You can try again.'
  ]);
  exit;
}

// Ensure quiz_responses has needed columns (best-effort migration for cPanel without CI/CD)
$ensureSql = "
  CREATE TABLE IF NOT EXISTS quiz_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    speaker_id INT NOT NULL,
    room_id INT NULL,
    day_id INT NULL,
    user_id INT NOT NULL,
    answers JSON NULL,
    score INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_user (quiz_id, user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$db->query($ensureSql);

// Single insert with unique key handling (race-safe)
// Upsert to allow retries to overwrite prior attempt (e.g., if older behavior saved a failing first try)
$ins = $db->prepare("INSERT INTO quiz_responses (quiz_id, speaker_id, room_id, day_id, user_id, answers, score) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE answers=VALUES(answers), score=VALUES(score), created_at=NOW()");
if (!$ins) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$ins->bind_param('iiiiisi', $quiz_id, $speaker_id, $room_id, $day_id, $user_id, $ansJson, $score);
if ($ins->execute()) {
  $resp = ['ok'=>true, 'passed'=>true, 'score'=>$score, 'total'=>$totalGradable, 'rule'=>$rule];
  if ($required !== null) { $resp['required'] = $required; }
  echo json_encode($resp);
} else {
  http_response_code(500); echo json_encode(['error'=>'Save failed']);
}
$ins->close();