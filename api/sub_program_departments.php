<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; } elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; } else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

$dayId = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0;
if ($dayId <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid day']); exit; }

// Confirm day exists
$day=null; if($st=$db->prepare('SELECT id,label,day_date,is_enabled FROM function_room_days WHERE id=? LIMIT 1')){ $st->bind_param('i',$dayId); $st->execute(); $r=$st->get_result(); $day=$r->fetch_assoc(); $st->close(); }
if(!$day){ http_response_code(404); echo json_encode(['error'=>'Day not found']); exit; }

// Fetch speakers that are sub program and have a technical department
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$sql = "SELECT s.id,s.name,s.title,s.start_time,s.end_time,s.photo_url,s.technical_department_id,td.name AS dept_name,fr.name AS room_name,fr.id AS room_id
                                FROM speakers s
                                LEFT JOIN technical_departments td ON td.id = s.technical_department_id
                                LEFT JOIN function_rooms fr ON fr.id = s.room_id
                                WHERE fr.day_id=? AND s.program_type='sub' AND s.technical_department_id IS NOT NULL
                                ORDER BY td.name ASC, fr.name ASC, s.start_time ASC, s.name ASC";
$speakers=[]; if($st=$db->prepare($sql)){ $st->bind_param('i',$dayId); $st->execute(); $res=$st->get_result(); while($row=$res->fetch_assoc()) $speakers[]=$row; $st->close(); }

// Pre-fetch quiz + evaluation status for current user to minimize per-item queries
if ($user_id > 0 && !empty($speakers)) {
        $ids = array_map(fn($r)=>(int)$r['id'],$speakers);
        $idList = implode(',', array_map('intval',$ids));
        // evaluations
        $evalMap = [];
        if ($q=$db->query("SELECT speaker_id FROM speaker_evaluations WHERE user_id={$user_id} AND speaker_id IN ($idList)")) { while($r=$q->fetch_assoc()) $evalMap[(int)$r['speaker_id']]=true; }
        if ($q=$db->query("SELECT speaker_id FROM speaker_eval_responses WHERE user_id={$user_id} AND speaker_id IN ($idList)")) { while($r=$q->fetch_assoc()) $evalMap[(int)$r['speaker_id']]=true; }
        // quizzes existence
        $quizExists = [];
        if ($q=$db->query("SELECT speaker_id FROM quizzes WHERE speaker_id IN ($idList)")) { while($r=$q->fetch_assoc()) $quizExists[(int)$r['speaker_id']]=true; }
        // fallback quiz_questions
        $hasQQ = false; if ($r=$db->query("SHOW TABLES LIKE 'quiz_questions'")) { $hasQQ = $r->num_rows>0; $r->close(); }
        if ($hasQQ) { if ($q=$db->query("SELECT speaker_id FROM quiz_questions WHERE speaker_id IN ($idList) GROUP BY speaker_id")) { while($r=$q->fetch_assoc()) $quizExists[(int)$r['speaker_id']]=true; } }
        // quiz submissions
        $quizSub = [];
        if ($q=$db->query("SELECT speaker_id FROM quiz_responses WHERE user_id={$user_id} AND speaker_id IN ($idList) GROUP BY speaker_id")) { while($r=$q->fetch_assoc()) $quizSub[(int)$r['speaker_id']]=true; }
        // Build flags into speakers array
        foreach ($speakers as &$sp) {
                $sid = (int)$sp['id'];
                $sp['evaluated'] = !empty($evalMap[$sid]);
                $sp['has_quiz'] = !empty($quizExists[$sid]);
                $sp['quiz_submitted'] = !empty($quizSub[$sid]);
                $sp['requires_evaluation'] = true; // for clarity in UI
        }
        unset($sp);
}

$departments=[]; // dept_id => ['name'=>..., 'speakers'=>[]]
foreach($speakers as $sp){ $did=(int)$sp['technical_department_id']; if(!isset($departments[$did])){ $departments[$did]=['department_id'=>$did,'department_name'=>$sp['dept_name']??'Department','speakers'=>[]]; } $departments[$did]['speakers'][]=$sp; }

// Include departments that have an explicit attendance template even if no speakers on this day
// (Helps users generate attendance certificates early.)
if ($resTpl = $db->query("SELECT DISTINCT scope_identifier FROM certificates WHERE type='sub_program_attendance' AND scope_binding='sub_program' AND scope_identifier<>''")) {
        $templateDeptIds = [];
        while($r=$resTpl->fetch_assoc()){ $sid = (int)$r['scope_identifier']; if($sid>0) $templateDeptIds[$sid]=true; }
        $resTpl->close();
        if ($templateDeptIds) {
                // Fetch names for those not already present
                $missing = array_diff(array_keys($templateDeptIds), array_keys($departments));
                if ($missing) {
                        $idList = implode(',', array_map('intval',$missing));
                        if ($q = $db->query("SELECT id,name FROM technical_departments WHERE id IN ($idList)")) {
                                while($row=$q->fetch_assoc()) {
                                        $did=(int)$row['id'];
                                        if(!isset($departments[$did])) {
                                                $departments[$did] = [
                                                        'department_id'=>$did,
                                                        'department_name'=>$row['name'] ?: 'Department',
                                                        'speakers'=>[]
                                                ];
                                        }
                                }
                                $q->close();
                        }
                }
        }
}

echo json_encode(['day'=>$day,'departments'=>$departments]);
?>