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
$stmt = $db->prepare("SELECT q.answers, q.day_id, q.room_id FROM quizzes q WHERE q.id = ? AND q.speaker_id = ? LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$stmt->bind_param('ii', $quiz_id, $speaker_id);
$stmt->execute();
$stmt->bind_result($answersJson, $day_id, $room_id);
if (!$stmt->fetch()) { $stmt->close(); http_response_code(404); echo json_encode(['error'=>'Quiz not found']); exit; }
$stmt->close();

$correct = json_decode((string)$answersJson, true);
if (!is_array($correct)) { http_response_code(500); echo json_encode(['error'=>'Quiz data corrupted']); exit; }
if (count($answersArr) !== count($correct)) { http_response_code(400); echo json_encode(['error'=>'Answer count mismatch']); exit; }

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
  $corr = $correct[$i]; // array of ints for MCQ, null for paragraph
  $user = $answersArr[$i];

  if (is_array($corr)) {
    // Multiple-correct: treat correct if user's set equals the correct set
    $corrSet = array_values(array_unique(array_map('intval', $corr)));
    sort($corrSet);
    $userSet = [];
    if (is_array($user)) {
      $userSet = array_values(array_unique(array_map('intval', $user)));
    } elseif (is_numeric($user)) {
      $userSet = [(int)$user];
    }
    sort($userSet);
    if ($userSet === $corrSet) $score++;
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

// Single insert with unique key handling (race-safe)
$ins = $db->prepare("INSERT INTO quiz_responses (quiz_id, speaker_id, room_id, day_id, user_id, answers, score) VALUES (?, ?, ?, ?, ?, ?, ?)");
if (!$ins) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$ins->bind_param('iiiiisi', $quiz_id, $speaker_id, $room_id, $day_id, $user_id, $ansJson, $score);
if ($ins->execute()) {
  echo json_encode(['ok'=>true, 'score'=>$score, 'total'=>$totalGradable]);
} else {
  // 1062 if unique (quiz_id,user_id) constraint blocks duplicates
  if ($db->errno == 1062) { http_response_code(409); echo json_encode(['error'=>'You already submitted this quiz']); }
  else { http_response_code(500); echo json_encode(['error'=>'Save failed']); }
}
$ins->close();