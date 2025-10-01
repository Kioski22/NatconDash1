<?php
session_start();
require_once __DIR__ . '/../db.php';

// Auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  header('Location: admin_login.php'); exit;
}

// Normalize DB
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo 'DB unavailable'; exit; }

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

// Basic existence checks
function table_exists(mysqli $db,string $t): bool { $t=$db->real_escape_string($t); $r=$db->query("SHOW TABLES LIKE '{$t}'"); return $r && $r->num_rows>0; }
$hasQuestions = table_exists($db,'natcon_eval_questions');
$hasResponses = table_exists($db,'natcon_eval_responses');
$hasItems     = table_exists($db,'natcon_eval_response_items');
$hasOptions   = table_exists($db,'natcon_eval_options');

if (!$hasQuestions || !$hasResponses || !$hasItems) {
  // Minimal HTML page
  echo '<!doctype html><html><head><meta charset="utf-8"><title>NatCon Eval Reports</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head><body class="p-4">';
  echo '<h4>NatCon Evaluation Reports</h4>';
  echo '<div class="alert alert-warning">Schema incomplete. Required tables: natcon_eval_questions, natcon_eval_responses, natcon_eval_response_items'.(!$hasOptions?' (options optional if no choice questions)':'').'.</div>';
  echo '<a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a> ';
  echo '<a href="admin_eval_natcon.php" class="btn btn-primary btn-sm">Manage Questions</a>';
  echo '</body></html>';
  exit;
}

// Handle exports first (streaming)
$export = $_GET['export'] ?? '';
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  $filename = 'natcon_eval_'.preg_replace('/[^a-z0-9_\-]/i','', $export).'.csv';
  header('Content-Disposition: attachment; filename='.$filename);
  $out = fopen('php://output','w');

  // Load questions (ordered)
  $questions = [];
  if ($r = $db->query("SELECT id, question_text, question_type FROM natcon_eval_questions ORDER BY sort_order ASC, id ASC")) {
    while ($row = $r->fetch_assoc()) $questions[(int)$row['id']] = $row;
  }
  if ($export === 'wide') {
    // Build headers
    $cols = ['response_id','user_id','submitted_at'];
    foreach ($questions as $qid=>$q) { $cols[] = 'Q'.$qid; }
    fputcsv($out, $cols);
    // Load responses
    $responses = [];
    if ($r = $db->query("SELECT id,user_id,submitted_at FROM natcon_eval_responses ORDER BY id ASC")) {
      while ($row = $r->fetch_assoc()) $responses[] = $row;
    }
    if ($responses) {
      // Load items
      $respIds = array_column($responses,'id');
      $idList = implode(',', array_map('intval',$respIds));
      $itemsMap = [];
      if ($resI = $db->query("SELECT response_id, question_id, numeric_value, option_id, text_answer, checkbox_values FROM natcon_eval_response_items WHERE response_id IN ($idList)")) {
        while ($it = $resI->fetch_assoc()) {
          $rid = (int)$it['response_id'];
          $qid = (int)$it['question_id'];
          if (!isset($itemsMap[$rid])) $itemsMap[$rid] = [];
          $itemsMap[$rid][$qid] = $it;
        }
      }
      // Option text map for single choice (optional/performance) collect all option ids
      $optionText = [];
      if ($hasOptions) {
        if ($ro = $db->query("SELECT id, option_text FROM natcon_eval_options")) {
          while ($o = $ro->fetch_assoc()) $optionText[(int)$o['id']] = $o['option_text'];
        }
      }
      foreach ($responses as $resp) {
        $row = [$resp['id'],$resp['user_id'],$resp['submitted_at']];
        foreach ($questions as $qid=>$q) {
          $val = '';
            if (isset($itemsMap[$resp['id']][$qid])) {
              $item = $itemsMap[$resp['id']][$qid];
              switch ($q['question_type']) {
                case 'scale':
                case 'checkbox':
                  $val = $item['numeric_value'];
                  break;
                case 'text':
                case 'textarea':
                  $val = $item['text_answer'];
                  break;
                case 'multiple_choice_single':
                case 'dropdown':
                  $oid = (int)$item['option_id'];
                  $val = ($optionText[$oid] ?? ('option#'.$oid));
                  break;
                case 'multiple_choice_multi':
                  // checkbox_values JSON array
                  if (!empty($item['checkbox_values'])) {
                    $arr = json_decode($item['checkbox_values'], true);
                    if (is_array($arr)) {
                      $texts = [];
                      foreach ($arr as $oid) { $texts[] = $optionText[$oid] ?? ('option#'.$oid); }
                      $val = implode(' | ', $texts);
                    }
                  }
                  break;
              }
            }
            $row[] = $val;
        }
        fputcsv($out,$row);
      }
    }
  } elseif ($export === 'long') {
    fputcsv($out, ['response_id','user_id','submitted_at','question_id','question_type','answer_value','numeric_value','option_id','option_text','multi_option_ids','multi_option_texts','text_answer']);
    // Preload option text
    $optionText=[]; if ($hasOptions && ($ro=$db->query("SELECT id, option_text FROM natcon_eval_options"))) while ($o=$ro->fetch_assoc()) $optionText[(int)$o['id']]=$o['option_text'];
    // Stream rows via join
    $sql = "SELECT r.id AS response_id, r.user_id, r.submitted_at, i.question_id, q.question_type, i.numeric_value, i.option_id, i.text_answer, i.checkbox_values FROM natcon_eval_responses r JOIN natcon_eval_response_items i ON i.response_id=r.id JOIN natcon_eval_questions q ON q.id = i.question_id ORDER BY r.id ASC, i.question_id ASC";
    if ($res = $db->query($sql)) {
      while ($row = $res->fetch_assoc()) {
        $answerValue = '';
        $multiIds=''; $multiTexts=''; $optText='';
        switch ($row['question_type']) {
          case 'scale':
          case 'checkbox':
            $answerValue = $row['numeric_value'];
            break;
          case 'text':
          case 'textarea':
            $answerValue = $row['text_answer'];
            break;
          case 'multiple_choice_single':
          case 'dropdown':
            $oid = (int)$row['option_id'];
            $optText = $optionText[$oid] ?? '';
            $answerValue = $optText;
            break;
          case 'multiple_choice_multi':
            if (!empty($row['checkbox_values'])) {
              $arr = json_decode($row['checkbox_values'], true);
              if (is_array($arr)) {
                $multiIds = implode('|', $arr);
                $texts=[]; foreach ($arr as $oid) { $texts[] = $optionText[$oid] ?? ('option#'.$oid); }
                $multiTexts = implode('|',$texts);
                $answerValue = $multiTexts;
              }
            }
            break;
        }
        fputcsv($out, [
          $row['response_id'],
          $row['user_id'],
          $row['submitted_at'],
          $row['question_id'],
          $row['question_type'],
          $answerValue,
          $row['numeric_value'],
          $row['option_id'],
          $optText,
          $multiIds,
          $multiTexts,
          $row['text_answer']
        ]);
      }
    }
  } else {
    fputcsv($out,['error'=>'Unknown export type']);
  }
  fclose($out);
  exit;
}

// Load high-level stats
$totalResponses = 0;
if ($r = $db->query("SELECT COUNT(*) c FROM natcon_eval_responses")) { $totalResponses = (int)$r->fetch_assoc()['c']; }

$questions = [];
if ($r = $db->query("SELECT id, question_text, question_type, min_scale, max_scale FROM natcon_eval_questions ORDER BY sort_order ASC, id ASC")) {
  while ($row = $r->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['min_scale'] = $row['min_scale'] !== null ? (int)$row['min_scale'] : null;
    $row['max_scale'] = $row['max_scale'] !== null ? (int)$row['max_scale'] : null;
    $questions[$row['id']] = $row;
  }
}
$qIds = array_keys($questions);

// Preload option text (for choice types & multi) & map by question
$optionByQuestion = [];
if ($hasOptions && $qIds) {
  $idList = implode(',', array_map('intval',$qIds));
  if ($ro = $db->query("SELECT id, question_id, option_text FROM natcon_eval_options WHERE question_id IN ($idList) ORDER BY id ASC")) {
    while ($o = $ro->fetch_assoc()) {
      $qid = (int)$o['question_id'];
      if (!isset($optionByQuestion[$qid])) $optionByQuestion[$qid] = [];
      $optionByQuestion[$qid][] = ['id'=>(int)$o['id'],'option_text'=>$o['option_text']];
    }
  }
}

// Aggregate data for scale & checkbox quickly via SQL
$scaleStats = []; // qid => ['avg'=>, 'count'=>, 'dist'=>[value=>count]]
$checkboxStats = []; // qid => ['true'=>, 'false'=>]
if ($qIds) {
  // Scale numeric values
  $scaleIds = array_filter($qIds, fn($id) => $questions[$id]['question_type']==='scale');
  if ($scaleIds) {
    $idList = implode(',', $scaleIds);
    if ($rs = $db->query("SELECT question_id, AVG(numeric_value) a, COUNT(*) c FROM natcon_eval_response_items WHERE question_id IN ($idList) GROUP BY question_id")) {
      while ($row = $rs->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        $scaleStats[$qid] = ['avg'=>(float)$row['a'],'count'=>(int)$row['c'],'dist'=>[]];
      }
    }
    // Distribution
    if ($rs = $db->query("SELECT question_id, numeric_value val, COUNT(*) c FROM natcon_eval_response_items WHERE question_id IN ($idList) GROUP BY question_id, numeric_value")) {
      while ($row = $rs->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        if (!isset($scaleStats[$qid])) $scaleStats[$qid] = ['avg'=>0,'count'=>0,'dist'=>[]];
        $scaleStats[$qid]['dist'][(int)$row['val']] = (int)$row['c'];
      }
    }
  }
  // Checkbox (single boolean stored as numeric_value 0/1)
  $checkIds = array_filter($qIds, fn($id) => $questions[$id]['question_type']==='checkbox');
  if ($checkIds) {
    $idList = implode(',', $checkIds);
    if ($rs = $db->query("SELECT question_id, numeric_value, COUNT(*) c FROM natcon_eval_response_items WHERE question_id IN ($idList) GROUP BY question_id, numeric_value")) {
      while ($row = $rs->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        if (!isset($checkboxStats[$qid])) $checkboxStats[$qid] = ['true'=>0,'false'=>0];
        if ((int)$row['numeric_value'] === 1) $checkboxStats[$qid]['true'] += (int)$row['c']; else $checkboxStats[$qid]['false'] += (int)$row['c'];
      }
    }
  }
}

// Option & multi-choice stats (single & dropdown can be aggregated by option_id; multi requires JSON parse)
$choiceStats = []; // qid => [ option_id => count ]
$multiStats = [];  // qid => [ option_id => count ]
$choiceIds = array_filter($qIds, fn($id) => in_array($questions[$id]['question_type'],['multiple_choice_single','dropdown'],true));
if ($choiceIds) {
  $idList = implode(',', $choiceIds);
  if ($rs = $db->query("SELECT question_id, option_id, COUNT(*) c FROM natcon_eval_response_items WHERE question_id IN ($idList) AND option_id IS NOT NULL GROUP BY question_id, option_id")) {
    while ($row = $rs->fetch_assoc()) {
      $qid = (int)$row['question_id'];
      if (!isset($choiceStats[$qid])) $choiceStats[$qid] = [];
      $choiceStats[$qid][(int)$row['option_id']] = (int)$row['c'];
    }
  }
}
$multiIds = array_filter($qIds, fn($id) => $questions[$id]['question_type']==='multiple_choice_multi');
if ($multiIds) {
  $idList = implode(',', $multiIds);
  if ($rs = $db->query("SELECT question_id, checkbox_values FROM natcon_eval_response_items WHERE question_id IN ($idList) AND checkbox_values IS NOT NULL AND checkbox_values <> ''")) {
    while ($row = $rs->fetch_assoc()) {
      $qid = (int)$row['question_id'];
      $arr = json_decode($row['checkbox_values'], true);
      if (is_array($arr)) {
        if (!isset($multiStats[$qid])) $multiStats[$qid] = [];
        foreach ($arr as $oid) {
          $oid = (int)$oid; if (!isset($multiStats[$qid][$oid])) $multiStats[$qid][$oid]=0; $multiStats[$qid][$oid]++; }
      }
    }
  }
}

// Text answers counts
$textCounts = []; // qid => count non-empty
$textIds = array_filter($qIds, fn($id) => in_array($questions[$id]['question_type'],['text','textarea'], true));
if ($textIds) {
  $idList = implode(',', $textIds);
  if ($rs = $db->query("SELECT question_id, COUNT(*) c FROM natcon_eval_response_items WHERE question_id IN ($idList) AND text_answer IS NOT NULL AND text_answer <> '' GROUP BY question_id")) {
    while ($row = $rs->fetch_assoc()) $textCounts[(int)$row['question_id']] = (int)$row['c'];
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>NatCon Evaluation Analytics</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    body { background:#f4f6f9; }
    .q-card { border:1px solid #ddd; border-radius:8px; padding:1rem 1rem 0.75rem; background:#fff; margin-bottom:1rem; }
    .dist-bar { height:8px; background:#0d6efd; }
    .small-muted { font-size:.75rem; color:#6c757d; }
    .option-row { display:flex; justify-content:space-between; gap:.75rem; }
    .code { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.75rem; background:#f1f3f5; padding:2px 4px; border-radius:4px; }
  </style>
</head>
<body class="p-3">
  <div class="container-lg">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h4 class="mb-0">NatCon Evaluation Analytics</h4>
      <div class="btn-group">
        <a href="Admin_dashboard.php" class="btn btn-sm btn-secondary">Dashboard</a>
        <a href="admin_eval_natcon.php" class="btn btn-sm btn-outline-primary">Manage Questions</a>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body d-flex flex-wrap gap-4 align-items-center">
        <div>
          <div class="text-muted small">Total Responses</div>
          <div class="h4 mb-0"><?= (int)$totalResponses ?></div>
        </div>
        <div>
          <div class="text-muted small">Questions</div>
          <div class="h5 mb-0"><?= count($questions) ?></div>
        </div>
        <div class="ms-auto d-flex gap-2 flex-wrap">
          <a href="?export=wide" class="btn btn-sm btn-primary">Export Wide CSV</a>
          <a href="?export=long" class="btn btn-sm btn-outline-primary">Export Long CSV</a>
        </div>
      </div>
    </div>

    <?php if (!$questions): ?>
      <div class="alert alert-info">No questions found. <a href="admin_eval_natcon.php">Create questions</a> first.</div>
    <?php endif; ?>

    <?php foreach ($questions as $qid=>$q): ?>
      <?php
        $type = $q['question_type'];
        $title = $q['question_text'];
      ?>
      <div class="q-card">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div class="fw-semibold">Q<?= (int)$qid ?>: <?= e($title) ?></div>
          <span class="badge text-bg-secondary"><?= e($type) ?></span>
        </div>
        <?php if ($type === 'scale'): ?>
          <?php $stat = $scaleStats[$qid] ?? ['avg'=>0,'count'=>0,'dist'=>[]]; ?>
          <div class="small mb-1">Average: <strong><?= number_format($stat['avg'],2) ?></strong> (n=<?= (int)$stat['count'] ?>)</div>
          <?php
            $min = $q['min_scale'] ?? 1; $max = $q['max_scale'] ?? 5;
            $total = max(1,(int)$stat['count']);
          ?>
          <div class="mb-2">
            <?php for ($v=$min; $v<=$max; $v++): $c = $stat['dist'][$v] ?? 0; $pct = $stat['count'] ? ($c/$total*100) : 0; ?>
              <div class="d-flex align-items-center gap-2 mb-1">
                <div style="width:40px;" class="small text-muted text-end"><?= $v ?></div>
                <div class="flex-grow-1 bg-light rounded" style="position:relative; height:8px;">
                  <div class="dist-bar" style="width:<?= round($pct,1) ?>%;"></div>
                </div>
                <div style="width:70px;" class="small text-muted text-end"><?= $c ?> (<?= round($pct,1) ?>%)</div>
              </div>
            <?php endfor; ?>
          </div>
        <?php elseif (in_array($type,['multiple_choice_single','dropdown'],true)): ?>
          <?php $opts = $optionByQuestion[$qid] ?? []; $counts = $choiceStats[$qid] ?? []; $sum = array_sum($counts); $sum = $sum ?: 1; ?>
          <div class="mb-2">
            <?php foreach ($opts as $o): $c = $counts[$o['id']] ?? 0; $pct = $c? ($c/$sum*100):0; ?>
              <div class="option-row small mb-1">
                <div class="text-truncate" style="max-width:55%;" title="<?= e($o['option_text']) ?>">• <?= e($o['option_text']) ?></div>
                <div class="flex-grow-1">
                  <div class="bg-light rounded" style="position:relative; height:8px;">
                    <div class="dist-bar" style="width:<?= round($pct,1) ?>%;"></div>
                  </div>
                </div>
                <div style="width:85px;" class="text-end small text-muted"><?= $c ?> (<?= round($pct,1) ?>%)</div>
              </div>
            <?php endforeach; ?>
            <?php if (!$opts): ?><div class="small-muted">(No options)</div><?php endif; ?>
          </div>
        <?php elseif ($type === 'multiple_choice_multi'): ?>
          <?php $opts = $optionByQuestion[$qid] ?? []; $counts = $multiStats[$qid] ?? []; $sum = array_sum($counts); $sum = $sum ?: 1; ?>
          <div class="mb-2">
            <?php foreach ($opts as $o): $c = $counts[$o['id']] ?? 0; $pct = $c? ($c/$sum*100):0; ?>
              <div class="option-row small mb-1">
                <div class="text-truncate" style="max-width:55%;" title="<?= e($o['option_text']) ?>">• <?= e($o['option_text']) ?></div>
                <div class="flex-grow-1">
                  <div class="bg-light rounded" style="position:relative; height:8px;">
                    <div class="dist-bar" style="width:<?= round($pct,1) ?>%;"></div>
                  </div>
                </div>
                <div style="width:85px;" class="text-end small text-muted"><?= $c ?> (<?= round($pct,1) ?>%)</div>
              </div>
            <?php endforeach; ?>
            <?php if (!$opts): ?><div class="small-muted">(No options)</div><?php endif; ?>
          </div>
          <div class="small-muted">Counts reflect total individual selections (multi-select may exceed number of responses).</div>
        <?php elseif ($type === 'checkbox'): ?>
          <?php $stat = $checkboxStats[$qid] ?? ['true'=>0,'false'=>0]; $sum = max(1, $stat['true'] + $stat['false']); $pctTrue = $stat['true']? ($stat['true']/$sum*100):0; ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <div class="small">Checked: <strong><?= (int)$stat['true'] ?></strong> (<?= round($pctTrue,1) ?>%)</div>
            <div class="small">Unchecked: <strong><?= (int)$stat['false'] ?></strong></div>
          </div>
          <div class="bg-light rounded mb-2" style="height:10px; position:relative;">
            <div class="dist-bar" style="width:<?= round($pctTrue,1) ?>%;"></div>
          </div>
        <?php elseif (in_array($type,['text','textarea'],true)): ?>
          <?php $cnt = $textCounts[$qid] ?? 0; ?>
          <div class="small mb-2">Non-empty responses: <strong><?= (int)$cnt ?></strong></div>
          <details class="mb-2">
            <summary class="small text-muted">Preview first 25 answers</summary>
            <div class="mt-2" style="max-height:250px; overflow:auto;">
              <ol class="small" style="padding-left:1.1rem;">
                <?php
                  $limit = 25;
                  if ($cnt > 0) {
                    $resTxt = $db->query("SELECT text_answer FROM natcon_eval_response_items WHERE question_id=".$qid." AND text_answer IS NOT NULL AND text_answer<>'' ORDER BY response_id ASC LIMIT $limit");
                    if ($resTxt) {
                      while ($trow = $resTxt->fetch_assoc()) {
                        echo '<li>'.e(mb_strimwidth($trow['text_answer'],0,160,'…')).'</li>';
                      }
                    }
                  }
                ?>
              </ol>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
