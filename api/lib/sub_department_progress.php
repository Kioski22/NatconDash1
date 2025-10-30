<?php
/**
 * Shared helpers for computing sub-department certificate progress.
 * These functions can be reused by multiple endpoints that need
 * normalized program type handling and granular progress calculation.
 */

if (!function_exists('get_setting')) {
    function get_setting(mysqli $db, string $key, $default = '') {
        $chk = $db->query("SHOW TABLES LIKE 'admin_settings'");
        if (!$chk) return $default;
        $has = $chk->num_rows > 0;
        $chk->close();
        if (!$has) return $default;
        if ($st = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key=? LIMIT 1")) {
            $st->bind_param('s', $key);
            $st->execute();
            $st->bind_result($val);
            if ($st->fetch()) {
                $st->close();
                return $val;
            }
            $st->close();
        }
        return $default;
    }
}

if (!function_exists('normalize_program_type')) {
    function normalize_program_type($raw): string {
        $clean = strtolower(trim((string)$raw));
        if ($clean === '') return '';
        $clean = str_replace(['_', '-'], ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        if ($clean === 'sub') return 'sub';
        if (strpos($clean, 'sub ') === 0) return 'sub';
        return $clean;
    }
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(mysqli $db, string $table, string $column): bool {
        static $cache = [];
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') return false;
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $tableEsc = str_replace('`', '``', $table);
        $columnEsc = $db->real_escape_string($column);
        $result = false;
        if ($res = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'")) {
            $result = $res->num_rows > 0;
            $res->close();
        }
        $cache[$key] = $result;
        return $result;
    }
}

if (!function_exists('compute_progress')) {
    function compute_progress(mysqli $db, int $dept_id, int $user_id, string $user_email = '', ?int $filter_day_id = null): array {
        $totalSpeakers = 0;
        $evalCompleted = 0; $evalRemaining = [];
        $quizTotal = 0; $quizCompleted = 0; $quizRemaining = [];
        $skippedDetailed = [];
        $skippedSummary = [];
        $pendingDetails = [];
        $trackedSpeakers = [];
        $debugRowsFetched = 0;

        $dayFilterApplied = ($filter_day_id !== null && $filter_day_id > 0);

        // Load sub program rules
    $requireQuiz    = (get_setting($db,'sub_cert_require_quiz', get_setting($db,'cert_require_quiz','1')) === '1');
    $requireEval    = (get_setting($db,'sub_cert_require_eval', get_setting($db,'cert_require_eval','0')) === '1');

        // Detect existence of quiz_questions table (legacy fallback)
        $hasQuizQuestions = false;
        if ($res = $db->query("SHOW TABLES LIKE 'quiz_questions'")) { $hasQuizQuestions = $res->num_rows > 0; $res->close(); }

        $sqlError = null;
        $sql = <<<SQL
SELECT
    s.id,
    s.name,
    s.title,
    s.program_type,
    fr.id AS room_id,
    fr.is_active,
    fr.day_id,
    fd.day_date,
    fd.is_enabled
FROM speakers s
LEFT JOIN function_rooms fr ON fr.id = s.room_id
LEFT JOIN function_room_days fd ON fd.id = fr.day_id
WHERE s.technical_department_id=?
SQL;

        $speakerRows = [];

        if ($st = $db->prepare($sql)) {
            $st->bind_param('i', $dept_id);
            if (!$st->execute()) {
                $sqlError = $st->error;
            } else {
                $st->bind_result($spId, $spName, $spTitle, $spProgramType, $roomId, $roomIsActive, $dayId, $dayDate, $dayIsEnabled);
                while ($st->fetch()) {
                    $debugRowsFetched++;

                    $spLabel = trim((string)($spName ?? ''));
                    if ($spLabel === '') {
                        $spLabel = 'Speaker #' . $spId;
                    }

                    $normalizedType = normalize_program_type($spProgramType);
                    $dayDateStr = $dayDate !== null ? trim((string)$dayDate) : null;

                    if ($normalizedType !== 'sub') {
                        $skippedDetailed[] = [
                            'speaker_id'   => $spId,
                            'speaker_label'=> $spLabel,
                            'reason'       => 'program_type_other',
                            'program_type' => $spProgramType,
                            'normalized_program_type' => $normalizedType,
                            'room_id'      => $roomId,
                            'day_id'       => $dayId,
                            'day_date'     => $dayDateStr
                        ];
                        $skippedSummary['program_type_other'] = ($skippedSummary['program_type_other'] ?? 0) + 1;
                        continue;
                    }

                    // Only skip speakers when an explicit day filter is applied and the session falls outside that filter
                    $skipReason = null;
                    $skipHard = false;
                    if ($dayFilterApplied) {
                        if ($dayId === null) {
                            $skipReason = 'day_unassigned';
                            $skipHard = true;
                        } elseif ((int)$dayId !== (int)$filter_day_id) {
                            $skipReason = 'day_other';
                            $skipHard = true;
                        }
                    }

                    if ($skipReason !== null) {
                        $skippedDetailed[] = [
                            'speaker_id'   => $spId,
                            'speaker_label'=> $spLabel,
                            'reason'       => $skipReason,
                            'program_type' => $spProgramType,
                            'normalized_program_type' => $normalizedType,
                            'room_id'      => $roomId,
                            'day_id'       => $dayId,
                            'day_date'     => $dayDateStr
                        ];
                        $skippedSummary[$skipReason] = ($skippedSummary[$skipReason] ?? 0) + 1;
                        if ($skipHard) {
                            continue;
                        }
                    }

                    $totalSpeakers++;
                    $trackedSpeakers[] = [
                        'speaker_id'    => $spId,
                        'speaker_label' => $spLabel,
                        'program_type'  => $normalizedType,
                        'room_id'       => $roomId,
                        'day_id'        => $dayId,
                        'day_date'      => $dayDateStr
                    ];

                    $speakerRows[] = [
                        'speaker_id' => (int)$spId,
                        'label'      => $spLabel,
                        'room_id'    => $roomId,
                        'day_id'     => $dayId,
                        'day_date'   => $dayDateStr
                    ];
                }
            }
            $st->close();
        } else {
            $sqlError = $db->error;
        }

        $evalMap = [];
        $quizFlags = [];
        $quizIdsBySpeaker = [];
        $quizResponsesByQuiz = [];
        $quizResponsesBySpeaker = [];

        if (!empty($speakerRows)) {
            $speakerIds = array_map(function ($row) { return (int)$row['speaker_id']; }, $speakerRows);
            $idList = implode(',', $speakerIds);

            if ($idList !== '') {
                if ($user_id > 0) {
                    if ($res = $db->query("SELECT speaker_id FROM speaker_evaluations WHERE user_id=" . $user_id . " AND speaker_id IN (" . $idList . ")")) {
                        while ($row = $res->fetch_assoc()) {
                            $evalMap[(int)$row['speaker_id']] = true;
                        }
                        $res->close();
                    }
                    if ($res = $db->query("SELECT speaker_id FROM speaker_eval_responses WHERE user_id=" . $user_id . " AND speaker_id IN (" . $idList . ")")) {
                        while ($row = $res->fetch_assoc()) {
                            $evalMap[(int)$row['speaker_id']] = true;
                        }
                        $res->close();
                    }
                }

                if ($user_email !== '') {
                    $email = $db->real_escape_string($user_email);
                    $emailClauses = [];
                    if (db_column_exists($db, 'speaker_eval_responses', 'user_email')) {
                        $emailClauses[] = "user_email = '" . $email . "'";
                    }
                    if (db_column_exists($db, 'speaker_eval_responses', 'email')) {
                        $emailClauses[] = "email = '" . $email . "'";
                    }
                    if ($emailClauses) {
                        $where = implode(' OR ', $emailClauses);
                        if ($res = $db->query("SELECT speaker_id FROM speaker_eval_responses WHERE (" . $where . ") AND speaker_id IN (" . $idList . ")")) {
                            while ($row = $res->fetch_assoc()) {
                                $evalMap[(int)$row['speaker_id']] = true;
                            }
                            $res->close();
                        }
                    }
                }

                $quizEmailClauses = [];
                if ($user_email !== '') {
                    $email = $db->real_escape_string($user_email);
                    if (db_column_exists($db, 'quiz_responses', 'user_email')) {
                        $quizEmailClauses[] = "user_email = '" . $email . "'";
                    }
                    if (db_column_exists($db, 'quiz_responses', 'email')) {
                        $quizEmailClauses[] = "email = '" . $email . "'";
                    }
                }

                if (!empty($quizIdsBySpeaker)) {
                    $quizIdList = implode(',', array_map('intval', array_values($quizIdsBySpeaker)));
                    if ($quizIdList !== '') {
                        if ($user_id > 0) {
                            if ($res = $db->query("SELECT DISTINCT quiz_id FROM quiz_responses WHERE user_id=" . $user_id . " AND quiz_id IN (" . $quizIdList . ")")) {
                                while ($row = $res->fetch_assoc()) {
                                    $quizResponsesByQuiz[(int)$row['quiz_id']] = true;
                                }
                                $res->close();
                            }
                        }
                        if ($quizEmailClauses) {
                            $where = implode(' OR ', $quizEmailClauses);
                            if ($res = $db->query("SELECT DISTINCT quiz_id FROM quiz_responses WHERE (" . $where . ") AND quiz_id IN (" . $quizIdList . ")")) {
                                while ($row = $res->fetch_assoc()) {
                                    $quizResponsesByQuiz[(int)$row['quiz_id']] = true;
                                }
                                $res->close();
                            }
                        }
                    }
                }

                if ($user_id > 0) {
                    if ($res = $db->query("SELECT DISTINCT speaker_id FROM quiz_responses WHERE user_id=" . $user_id . " AND speaker_id IN (" . $idList . ")")) {
                        while ($row = $res->fetch_assoc()) {
                            $quizResponsesBySpeaker[(int)$row['speaker_id']] = true;
                        }
                        $res->close();
                    }
                }
                if ($quizEmailClauses) {
                    $where = implode(' OR ', $quizEmailClauses);
                    if ($res = $db->query("SELECT DISTINCT speaker_id FROM quiz_responses WHERE (" . $where . ") AND speaker_id IN (" . $idList . ")")) {
                        while ($row = $res->fetch_assoc()) {
                            $quizResponsesBySpeaker[(int)$row['speaker_id']] = true;
                        }
                        $res->close();
                    }
                }

                if ($res = $db->query("SELECT id,speaker_id FROM quizzes WHERE speaker_id IN (" . $idList . ")")) {
                    while ($row = $res->fetch_assoc()) {
                        $speakerId = (int)$row['speaker_id'];
                        $quizFlags[$speakerId] = true;
                        $quizIdsBySpeaker[$speakerId] = (int)$row['id'];
                    }
                    $res->close();
                }

                if ($hasQuizQuestions) {
                    if ($res = $db->query("SELECT speaker_id FROM quiz_questions WHERE speaker_id IN (" . $idList . ") GROUP BY speaker_id")) {
                        while ($row = $res->fetch_assoc()) {
                            $quizFlags[(int)$row['speaker_id']] = true;
                        }
                        $res->close();
                    }
                }
            }
        }

        foreach ($speakerRows as $row) {
            $spId = (int)$row['speaker_id'];
            $spLabel = $row['label'];
            $roomId = $row['room_id'];
            $dayDateStr = $row['day_date'];

            $evalDone = !empty($evalMap[$spId]);
            if ($evalDone) {
                $evalCompleted++;
            } else {
                $evalRemaining[] = $spId;
            }

            $hasQuiz = !empty($quizFlags[$spId]);
            $quizUserDone = !empty($quizResponsesBySpeaker[$spId]);
            if (!$quizUserDone && isset($quizIdsBySpeaker[$spId])) {
                $quizId = $quizIdsBySpeaker[$spId];
                if (!empty($quizResponsesByQuiz[$quizId])) {
                    $quizUserDone = true;
                }
            }

            if ($hasQuiz) {
                $quizTotal++;
                if ($quizUserDone) {
                    $quizCompleted++;
                } else {
                    $quizRemaining[] = $spId;
                }
            }

            $needsEval = $requireEval && !$evalDone;
            $needsQuiz = $requireQuiz && $hasQuiz && !$quizUserDone;
            if ($needsEval || $needsQuiz) {
                $pendingDetails[] = [
                    'speaker_id'    => $spId,
                    'speaker_label' => $spLabel,
                    'needs_eval'    => $needsEval,
                    'needs_quiz'    => $needsQuiz,
                    'day_date'      => $dayDateStr,
                    'room_id'       => $roomId
                ];
            }
        }

        $debugFallback = [];
        if ($debugRowsFetched === 0) {
            if ($dbg = $db->prepare("SELECT id, program_type, technical_department_id, room_id FROM speakers WHERE technical_department_id=? LIMIT 10")) {
                $dbg->bind_param('i',$dept_id);
                if ($dbg->execute()) {
                    $dbg->bind_result($dId,$dProg,$dDept,$dRoom);
                    while ($dbg->fetch()) {
                        $debugFallback[] = [
                            'speaker_id'=>$dId,
                            'program_type'=>$dProg,
                            'room_id'=>$dRoom,
                            'technical_department_id'=>$dDept
                        ];
                    }
                }
                $dbg->close();
            }
            if ($dayFilterApplied && empty($debugFallback)) {
                if ($dbg2 = $db->prepare("SELECT s.id, s.program_type, s.technical_department_id, fr.day_id FROM speakers s LEFT JOIN function_rooms fr ON fr.id = s.room_id WHERE fr.day_id=? LIMIT 10")) {
                    $dbg2->bind_param('i',$filter_day_id);
                    if ($dbg2->execute()) {
                        $dbg2->bind_result($dId,$dProg,$dDept,$dDay);
                        while ($dbg2->fetch()) {
                            $debugFallback[] = [
                                'speaker_id'=>$dId,
                                'program_type'=>$dProg,
                                'technical_department_id'=>$dDept,
                                'day_id'=>$dDay
                            ];
                        }
                    }
                    $dbg2->close();
                }
            }
        }

        $evalOk = !$requireEval ? true : ($evalCompleted === $totalSpeakers && $totalSpeakers >= 0);
        $quizOk = !$requireQuiz ? true : ($quizCompleted === $quizTotal);
        $complete = ($totalSpeakers>0 && $evalOk && $quizOk);

        return [
            'total'=>$totalSpeakers,
            'completed'=>$evalCompleted,
            'remaining'=>$evalRemaining,
            'complete'=>$complete,
            'eval_total'=>$totalSpeakers,
            'eval_completed'=>$evalCompleted,
            'eval_remaining'=>$evalRemaining,
            'quiz_total'=>$quizTotal,
            'quiz_completed'=>$quizCompleted,
            'quiz_remaining'=>$quizRemaining,
            'skipped'=>$skippedDetailed,
            'skipped_summary'=>$skippedSummary,
            'pending_detail'=>$pendingDetails,
            'tracked_speakers'=>$trackedSpeakers,
            'require_eval'=>$requireEval,
            'require_quiz'=>$requireQuiz,
            'debug'=>[
                'rows_fetched'=>$debugRowsFetched,
                'day_filter_applied'=>$dayFilterApplied,
                'filter_day_id'=>$dayFilterApplied ? $filter_day_id : null,
                'fallback_sample'=>$debugFallback,
                'sql_error'=>$sqlError
            ]
        ];
    }
}
