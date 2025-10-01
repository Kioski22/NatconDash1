<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$all = isset($_GET['all']) && $_GET['all'] == '1';

// Verify required columns exist to avoid fatal errors if schema not migrated
$requiredCols = ['question_text','question_type','is_required','is_active','sort_order','min_scale','max_scale','scale_label_left','scale_label_right'];
$existingCols = [];
if ($resCols = $db->query("SHOW COLUMNS FROM natcon_eval_questions")) {
  while ($cRow = $resCols->fetch_assoc()) { $existingCols[] = $cRow['Field']; }
} else {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Table natcon_eval_questions missing','hint'=>'Create table then run migration helper (admin/natcon_eval_migrate.php).']);
  exit;
}
$missing = array_diff($requiredCols, $existingCols);
if ($missing) {
  $didAuto = false; $autoErrors = [];
  // If admin logged in, attempt automatic migration of missing columns.
  if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    $ddlMap = [
      'question_text'     => 'ADD COLUMN question_text TEXT NOT NULL',
      'question_type'     => 'ADD COLUMN question_type VARCHAR(64) NOT NULL DEFAULT "scale"',
      'is_required'       => 'ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1',
      'is_active'         => 'ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
      'sort_order'        => 'ADD COLUMN sort_order INT NOT NULL DEFAULT 10',
      'min_scale'         => 'ADD COLUMN min_scale INT NULL',
      'max_scale'         => 'ADD COLUMN max_scale INT NULL',
      'scale_label_left'  => 'ADD COLUMN scale_label_left VARCHAR(100) NULL',
      'scale_label_right' => 'ADD COLUMN scale_label_right VARCHAR(100) NULL'
    ];
    foreach ($missing as $col) {
      if (!isset($ddlMap[$col])) continue;
      $sql = "ALTER TABLE natcon_eval_questions {$ddlMap[$col]}";
      if ($db->query($sql)) { $didAuto = true; }
      else { $autoErrors[] = $col . ': ' . $db->error; }
    }
    // Refresh columns if auto migration attempted
    if ($didAuto) {
      $existingCols = [];
      if ($resCols2 = $db->query("SHOW COLUMNS FROM natcon_eval_questions")) {
        while ($cRow2 = $resCols2->fetch_assoc()) $existingCols[] = $cRow2['Field'];
      }
      $missing = array_diff($requiredCols, $existingCols);
    }
  }
  if ($missing) {
    http_response_code(500);
    echo json_encode([
      'ok'=>false,
      'error'=>'Schema incomplete: missing columns: '.implode(', ', $missing),
      'auto_migration_attempted'=>$didAuto,
      'auto_migration_errors'=>$autoErrors,
      'hint'=>'Visit /admin/natcon_eval_migrate.php as admin to repair schema.'
    ]);
    exit;
  }
}

$qsql = "SELECT id, question_text, question_type, is_required, is_active, sort_order, min_scale, max_scale, scale_label_left, scale_label_right FROM natcon_eval_questions";
$qsql .= $all ? '' : ' WHERE is_active = 1';
$qsql .= ' ORDER BY sort_order ASC, id ASC';

$questions = [];
if ($res = $db->query($qsql)) {
  while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['is_required'] = (int)$row['is_required'];
    $row['is_active'] = (int)$row['is_active'];
    $row['sort_order'] = (int)$row['sort_order'];
    $row['min_scale'] = $row['min_scale'] !== null ? (int)$row['min_scale'] : null;
    $row['max_scale'] = $row['max_scale'] !== null ? (int)$row['max_scale'] : null;
    $questions[] = $row;
  }
}

// load options for all relevant questions in one query
$withOptions = array_filter($questions, function($q){ return in_array($q['question_type'], ['multiple_choice_single','multiple_choice_multi','dropdown'], true); });
if ($withOptions) {
  $ids = implode(',', array_map('intval', array_column($withOptions,'id')));
  if ($res = $db->query("SELECT id, question_id, option_text FROM natcon_eval_options WHERE question_id IN ($ids) ORDER BY id ASC")) {
    $optMap = [];
    while ($row = $res->fetch_assoc()) {
      $qid = (int)$row['question_id'];
      if (!isset($optMap[$qid])) $optMap[$qid] = [];
      $optMap[$qid][] = ['id'=>(int)$row['id'],'option_text'=>$row['option_text']];
    }
    foreach ($questions as &$q) { if (isset($optMap[$q['id']])) $q['options'] = $optMap[$q['id']]; }
  }
}

echo json_encode(['ok'=>true,'questions'=>$questions]);
