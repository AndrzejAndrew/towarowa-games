<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header("Content-Type: application/json");

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    echo json_encode(["action" => "error", "msg" => "Brak ID gry"]);
    exit;
}

function quiz_get_lock(string $key, int $timeoutSeconds = 0): bool
{
    global $conn;
    $k = mysqli_real_escape_string($conn, $key);
    $sql = "SELECT GET_LOCK('$k', " . (int)$timeoutSeconds . ") AS l";
    $r = mysqli_query($conn, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return isset($row['l']) && (int)$row['l'] === 1;
}

function quiz_release_lock(string $key): void
{
    global $conn;
    $k = mysqli_real_escape_string($conn, $key);
    @mysqli_query($conn, "SELECT RELEASE_LOCK('$k')");
}

function quiz_try_game_update(string $sql_with_timers, string $sql_fallback): bool
{
    global $conn;
    if (mysqli_query($conn, $sql_with_timers)) return true;
    $errno = mysqli_errno($conn);
    if ($errno === 1054) {
        return (bool)mysqli_query($conn, $sql_fallback);
    }
    return false;
}

// pobierz grę
$gRes = mysqli_query($conn,
    "SELECT id, status, mode, current_round, total_rounds, time_per_question, vote_ends_at,
            IF(vote_ends_at IS NULL, NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), vote_ends_at))) AS vote_time_left
     FROM games WHERE id=$game_id LIMIT 1"
);
if (!$gRes && mysqli_errno($conn) === 1054) {
    // fallback dla starszej bazy bez vote_ends_at
    $gRes = mysqli_query($conn,
        "SELECT id, status, mode, current_round, total_rounds, time_per_question, vote_ends_at
         FROM games WHERE id=$game_id LIMIT 1"
    );
}
$game = $gRes ? mysqli_fetch_assoc($gRes) : null;
if (!$game) {
    echo json_encode(["action" => "error", "msg" => "Gra nie istnieje"]);
    exit;
}

if (($game['mode'] ?? 'classic') !== 'dynamic') {
    echo json_encode(["action" => "start"]);
    exit;
}

if (($game['status'] ?? '') === 'finished') {
    echo json_encode(["action" => "finish"]);
    exit;
}

$current_round = (int)$game['current_round'];
$vote_round = (int)floor(($current_round - 1) / 5) + 1;
$tpq = (int)$game['time_per_question'];

// jeśli deadline nieustawiony – ustaw go przy pierwszym wejściu w fazę głosowania (wymaga migracji DB)
if (empty($game['vote_ends_at'])) {
    @mysqli_query($conn, "UPDATE games SET vote_ends_at=DATE_ADD(NOW(), INTERVAL time_per_question SECOND) WHERE id=$game_id");
    $tmpRes = mysqli_query($conn, "SELECT vote_ends_at FROM games WHERE id=$game_id LIMIT 1");
    $tmpRow = $tmpRes ? mysqli_fetch_assoc($tmpRes) : null;
    if ($tmpRow && !empty($tmpRow['vote_ends_at'])) {
        $game['vote_ends_at'] = $tmpRow['vote_ends_at'];
    }
}

// policz graczy i głosy
$tpRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM players WHERE game_id=$game_id");
$total_players = (int)(mysqli_fetch_assoc($tpRes)['c'] ?? 0);

$vcRes = mysqli_query($conn,
    "SELECT COUNT(DISTINCT player_id) AS c
     FROM votes
     WHERE game_id=$game_id AND round_number=$vote_round"
);
$votes_count = (int)(mysqli_fetch_assoc($vcRes)['c'] ?? 0);

// czy pytania na aktualną rundę są już przypisane?
$assignedRes = mysqli_query($conn,
    "SELECT COUNT(*) AS c
     FROM game_questions
     WHERE game_id=$game_id AND round_number=$current_round"
);
$assigned = (int)(mysqli_fetch_assoc($assignedRes)['c'] ?? 0);
if ($assigned > 0) {
    // gra już wystartowała z pytaniem
    echo json_encode(["action" => "start", "votes" => $votes_count, "total" => $total_players, "time_left" => null]);
    exit;
}

// czas do końca głosowania wg serwera (liczone w MySQL, aby uniknąć różnic stref czasowych)
$time_left = null;
$expired = false;

if (!empty($game['vote_ends_at'])) {
    if (is_array($game) && array_key_exists('vote_time_left', $game) && $game['vote_time_left'] !== null) {
        $time_left = (int)$game['vote_time_left'];
    } else {
        // fallback: wylicz po stronie MySQL na podstawie vote_ends_at
        $tlRes = mysqli_query($conn,
            "SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), vote_ends_at)) AS t
             FROM games WHERE id=$game_id LIMIT 1"
        );
        $tlRow = $tlRes ? mysqli_fetch_assoc($tlRes) : null;
        if ($tlRow && $tlRow['t'] !== null) {
            $time_left = (int)$tlRow['t'];
        }
    }
    $expired = ($time_left !== null && $time_left <= 0);
}

$should_finalize = ($total_players > 0 && $votes_count >= $total_players) || $expired;

if (!$should_finalize) {
    echo json_encode([
        "action" => "wait",
        "votes" => $votes_count,
        "total" => $total_players,
        "time_left" => $time_left
    ]);
    exit;
}

// finalizacja – zabezpieczenie przed wyścigiem
$lockKey = "quiz_vote_{$game_id}_{$vote_round}";
if (!quiz_get_lock($lockKey, 0)) {
    echo json_encode([
        "action" => "wait",
        "votes" => $votes_count,
        "total" => $total_players,
        "time_left" => $time_left,
        "busy" => 1
    ]);
    exit;
}

try {
    // ponownie sprawdź czy już przypisane
    $assignedRes = mysqli_query($conn,
        "SELECT COUNT(*) AS c
         FROM game_questions
         WHERE game_id=$game_id AND round_number=$current_round"
    );
    $assigned = (int)(mysqli_fetch_assoc($assignedRes)['c'] ?? 0);
    if ($assigned > 0) {
        echo json_encode(["action" => "start"]);
        exit;
    }

    // wybierz zwycięską kategorię
    $winner_cat = null;
    $topRes = mysqli_query($conn,
        "SELECT category, COUNT(*) AS cnt
         FROM votes
         WHERE game_id=$game_id AND round_number=$vote_round
         GROUP BY category
         ORDER BY cnt DESC
         LIMIT 1"
    );
    $topRow = $topRes ? mysqli_fetch_assoc($topRes) : null;
    if ($topRow && !empty($topRow['category'])) {
        $winner_cat = $topRow['category'];
    }

    if ($winner_cat === null) {
        // brak głosów – losowa, deterministyczna kategoria z puli
        $seed = (int)($game_id * 1000 + $vote_round * 17);
        $randCatRes = mysqli_query($conn, "SELECT DISTINCT category FROM questions ORDER BY RAND($seed) LIMIT 1");
        $rc = $randCatRes ? mysqli_fetch_assoc($randCatRes) : null;
        $winner_cat = $rc['category'] ?? null;
    }

    if ($winner_cat === null) {
        echo json_encode(["action" => "error", "msg" => "Brak kategorii do wyboru"]);
        exit;
    }

    // użyte pytania w tej grze
    $usedIds = [];
    $usedRes = mysqli_query($conn,
        "SELECT DISTINCT question_id
         FROM game_questions
         WHERE game_id=$game_id"
    );
    if ($usedRes) {
        while ($u = mysqli_fetch_assoc($usedRes)) {
            $usedIds[] = (int)$u['question_id'];
        }
    }

    $to_assign = 5;
    $total_rounds = (int)$game['total_rounds'];
    $remaining = $total_rounds - $current_round + 1;
    if ($remaining < $to_assign) {
        $to_assign = max(0, $remaining);
    }

    if ($to_assign <= 0) {
        // nic do przypisania -> kończymy
        quiz_try_game_update(
            "UPDATE games SET status='finished', vote_ends_at=NULL, round_ends_at=NULL WHERE id=$game_id",
            "UPDATE games SET status='finished' WHERE id=$game_id"
        );
        echo json_encode(["action" => "finish"]);
        exit;
    }

    $notIn = '';
    if (count($usedIds) > 0) {
        $notIn = "AND id NOT IN (" . implode(',', $usedIds) . ")";
    }

    // losuj pytania z kategorii
    $seedQ = (int)($game_id * 100000 + $vote_round * 313);
    $qSql = "SELECT id FROM questions WHERE category='" . mysqli_real_escape_string($conn, $winner_cat) . "' $notIn ORDER BY RAND($seedQ) LIMIT $to_assign";
    $qRes = mysqli_query($conn, $qSql);

    $qIds = [];
    if ($qRes) {
        while ($qr = mysqli_fetch_assoc($qRes)) {
            $qIds[] = (int)$qr['id'];
        }
    }

    // jeśli brakuje pytań, dograj bez notIn (ostatnia deska ratunku)
    if (count($qIds) < $to_assign) {
        $need = $to_assign - count($qIds);
        $seedQ2 = (int)($seedQ + 7);
        $qSql2 = "SELECT id FROM questions WHERE category='" . mysqli_real_escape_string($conn, $winner_cat) . "' ORDER BY RAND($seedQ2) LIMIT $need";
        $qRes2 = mysqli_query($conn, $qSql2);
        if ($qRes2) {
            while ($qr = mysqli_fetch_assoc($qRes2)) {
                $qid = (int)$qr['id'];
                if (!in_array($qid, $qIds, true)) {
                    $qIds[] = $qid;
                }
            }
        }
    }

    if (count($qIds) === 0) {
        echo json_encode(["action" => "error", "msg" => "Brak pytań w tej kategorii"]);
        exit;
    }

    // przypisz do game_questions
    $ins = mysqli_prepare($conn, "INSERT INTO game_questions (game_id, question_id, round_number) VALUES (?, ?, ?)");
    $rnum = $current_round;
    foreach ($qIds as $qid) {
        $gid = $game_id;
        $qid2 = (int)$qid;
        $rn = (int)$rnum;
        mysqli_stmt_bind_param($ins, "iii", $gid, $qid2, $rn);
        mysqli_stmt_execute($ins);
        $rnum++;
    }
    mysqli_stmt_close($ins);

    // wyczyść głosy tej rundy
    mysqli_query($conn, "DELETE FROM votes WHERE game_id=$game_id AND round_number=$vote_round");

    // ustaw deadline na pytanie
    quiz_try_game_update(
        "UPDATE games SET vote_ends_at=NULL, round_ends_at=DATE_ADD(NOW(), INTERVAL time_per_question SECOND) WHERE id=$game_id",
        "UPDATE games SET status='running' WHERE id=$game_id"
    );

    echo json_encode(["action" => "start"]);
    exit;

} finally {
    quiz_release_lock($lockKey);
}
