<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header("Content-Type: application/json");

$game_id = (int)($_GET['game'] ?? 0);
$client_round = (int)($_GET['round'] ?? 0);
if ($game_id <= 0) {
    echo json_encode(["action" => "error", "msg" => "Brak ID gry"]);
    exit;
}

function quiz_get_lock(string $key, int $timeoutSeconds = 0): bool
{
    global $conn;
    $k = mysqli_real_escape_string($conn, $key);
    $res = mysqli_query($conn, "SELECT GET_LOCK('$k', $timeoutSeconds) AS l");
    if (!$res) {
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    return (int)($row['l'] ?? 0) === 1;
}

function quiz_release_lock(string $key): void
{
    global $conn;
    $k = mysqli_real_escape_string($conn, $key);
    @mysqli_query($conn, "SELECT RELEASE_LOCK('$k')");
}

function quiz_safe_update(string $sql_with_timers, string $sql_fallback): bool
{
    global $conn;
    if (mysqli_query($conn, $sql_with_timers)) {
        return true;
    }
    if (mysqli_errno($conn) === 1054) {
        return (bool)mysqli_query($conn, $sql_fallback);
    }
    return false;
}

// Blokujemy operacje przejścia na danej grze – minimalizuje wyścigi pomiędzy klientami.
$lockKey = "quiz_next_" . $game_id;
if (!quiz_get_lock($lockKey, 0)) {
    echo json_encode(["action" => "wait", "busy" => true]);
    exit;
}

try {
    // pobierz stan gry
    $res = mysqli_query($conn,
        "SELECT id, status, mode, current_round, total_rounds, time_per_question, round_ends_at,
                IF(round_ends_at IS NULL, NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), round_ends_at))) AS round_time_left
         FROM games WHERE id = $game_id LIMIT 1"
    );
    if (!$res && mysqli_errno($conn) === 1054) {
        // fallback dla starszej bazy bez round_ends_at
        $res = mysqli_query($conn,
            "SELECT id, status, mode, current_round, total_rounds, time_per_question, round_ends_at
             FROM games WHERE id = $game_id LIMIT 1"
        );
    }
    $game = $res ? mysqli_fetch_assoc($res) : null;

    if (!$game) {
        echo json_encode(["action" => "error", "msg" => "Gra nie istnieje"]);
        exit;
    }

    // Statusy inne niż "running" muszą być obsłużone jawnie.
    // Jeśli ktoś zakończył grę, a inny gracz jeszcze polluje next.php,
    // nie możemy zwrócić "wait" – inaczej zostanie na ekranie pytania bez końca.
    $status = (string)($game['status'] ?? '');
    if ($status === 'finished') {
        echo json_encode(["action" => "finish"]);
        exit;
    }
    if ($status === 'lobby') {
        echo json_encode(["action" => "lobby"]);
        exit;
    }
    if ($status !== 'running') {
        echo json_encode(["action" => "wait", "status" => $status]);
        exit;
    }

    $current_round   = (int)$game['current_round'];
    $total_rounds    = (int)$game['total_rounds'];
    $timePerQuestion = (int)$game['time_per_question'];
    $mode            = $game['mode'] ?? 'classic';

    // Jeśli klient jest na innej rundzie niż serwer, nie czekamy.
    // To zabezpiecza przed sytuacją, w której jeden klient "przegapi" odpowiedź "next"
    // (np. bo ktoś inny już zdążył przesunąć current_round) i zostaje na starym pytaniu.
    if ($client_round > 0 && $client_round !== $current_round) {
        // jeśli gra jest już zakończona lub przekroczono total_rounds
        if (($game['status'] ?? '') === 'finished' || ($total_rounds > 0 && $current_round > $total_rounds)) {
            echo json_encode(["action" => "finish", "server_round" => $current_round]);
            exit;
        }

        if ($mode === 'dynamic') {
            $qCurRes = mysqli_query($conn,
                "SELECT 1 FROM game_questions WHERE game_id=$game_id AND round_number=$current_round LIMIT 1"
            );
            $hasCurQuestion = ($qCurRes && mysqli_fetch_assoc($qCurRes)) ? true : false;
            if (!$hasCurQuestion) {
                echo json_encode(["action" => "next", "vote" => true, "server_round" => $current_round]);
                exit;
            }
        }

        echo json_encode(["action" => "next", "server_round" => $current_round]);
        exit;
    }

    // Jeśli gra już przekroczyła total_rounds – zakończ
    if ($total_rounds > 0 && $current_round > $total_rounds) {
        quiz_safe_update(
            "UPDATE games SET status='finished', round_ends_at=NULL, vote_ends_at=NULL WHERE id=$game_id",
            "UPDATE games SET status='finished' WHERE id=$game_id"
        );
        echo json_encode(["action" => "finish"]);
        exit;
    }

    // pobierz question_id z aktualnej rundy
    $qres = mysqli_query($conn,
        "SELECT question_id FROM game_questions WHERE game_id=$game_id AND round_number=$current_round LIMIT 1"
    );
    $qrow = $qres ? mysqli_fetch_assoc($qres) : null;
    $question_id = (int)($qrow['question_id'] ?? 0);

    // W trybie dynamicznym w pewnych momentach runda może nie mieć przypisanego pytania (głosowanie).
    if ($question_id <= 0) {
        // jeśli to nie koniec gry, pozwól klientowi przejść (game.php i tak przerzuci na vote.php)
        if ($current_round <= $total_rounds && $mode === 'dynamic') {
            echo json_encode(["action" => "next", "vote" => true]);
            exit;
        }
        quiz_safe_update(
            "UPDATE games SET status='finished', round_ends_at=NULL, vote_ends_at=NULL WHERE id=$game_id",
            "UPDATE games SET status='finished' WHERE id=$game_id"
        );
        echo json_encode(["action" => "finish"]);
        exit;
    }

    // policz graczy
    $resPlayers = mysqli_query($conn,
        "SELECT COUNT(*) AS c FROM players WHERE game_id=$game_id"
    );
    $prow = $resPlayers ? mysqli_fetch_assoc($resPlayers) : null;
    $total_players = (int)($prow['c'] ?? 0);

    // policz odpowiedzi na to pytanie
    $resAns = mysqli_query($conn,
        "SELECT COUNT(*) AS c FROM answers WHERE game_id=$game_id AND question_id=$question_id"
    );
    $arow = $resAns ? mysqli_fetch_assoc($resAns) : null;
    $answers_count = (int)($arow['c'] ?? 0);

    // Deadline rundy – liczymy po stronie MySQL (unika błędów stref czasowych DATETIME)
    $time_left_server = null;
    $expired = false;
    if (is_array($game) && array_key_exists('round_time_left', $game) && $game['round_time_left'] !== null) {
        $time_left_server = (int)$game['round_time_left'];
        $expired = ($time_left_server <= 0);
    }

    // Jeżeli czas minął, a nie wszyscy odpowiedzieli – dopisz brakujące odpowiedzi jako timeout
    if ($expired && $total_players > 0 && $answers_count < $total_players) {

        $missing = [];
        $mres = mysqli_query($conn,
            "SELECT p.id AS player_id
             FROM players p
             LEFT JOIN answers a
               ON a.game_id=p.game_id AND a.question_id=$question_id AND a.player_id=p.id
             WHERE p.game_id=$game_id AND a.id IS NULL"
        );
        if ($mres) {
            while ($mr = mysqli_fetch_assoc($mres)) {
                $missing[] = (int)$mr['player_id'];
            }
        }

        if (!empty($missing)) {
            $stmtIns = mysqli_prepare($conn,
                "INSERT INTO answers (game_id, player_id, question_id, answer, is_correct, time_left)
                 VALUES (?, ?, ?, NULL, 0, 0)"
            );
            foreach ($missing as $pid) {
                mysqli_stmt_bind_param($stmtIns, "iii", $game_id, $pid, $question_id);
                @mysqli_stmt_execute($stmtIns);
            }
            mysqli_stmt_close($stmtIns);
        }

        // przelicz po dopisaniu
        $resAns2 = mysqli_query($conn,
            "SELECT COUNT(*) AS c FROM answers WHERE game_id=$game_id AND question_id=$question_id"
        );
        $arow2 = $resAns2 ? mysqli_fetch_assoc($resAns2) : null;
        $answers_count = (int)($arow2['c'] ?? 0);
    }

    // Jeszcze czekamy
    if ($total_players > 0 && $answers_count < $total_players && !$expired) {
        echo json_encode([
            "action" => "wait",
            "answered" => $answers_count,
            "total" => $total_players,
            "time_left" => $time_left_server
        ]);
        exit;
    }

    // Wszyscy odpowiedzieli lub czas minął → przechodzimy dalej
    if ($current_round >= $total_rounds) {
        quiz_safe_update(
            "UPDATE games SET status='finished', round_ends_at=NULL, vote_ends_at=NULL WHERE id=$game_id",
            "UPDATE games SET status='finished' WHERE id=$game_id"
        );
        echo json_encode(["action" => "finish"]);
        exit;
    }

    $next_round = $current_round + 1;

    // W trybie dynamicznym przejście na rundę, która nie ma jeszcze pytania, oznacza wejście w fazę głosowania.
    if ($mode === 'dynamic') {
        $qNextRes = mysqli_query($conn,
            "SELECT 1 FROM game_questions WHERE game_id=$game_id AND round_number=$next_round LIMIT 1"
        );
        $hasNextQuestion = ($qNextRes && mysqli_fetch_assoc($qNextRes)) ? true : false;

        if (!$hasNextQuestion) {
            $sqlWith = "UPDATE games
                        SET current_round=$next_round,
                            vote_ends_at = DATE_ADD(NOW(), INTERVAL time_per_question SECOND),
                            round_ends_at = NULL
                        WHERE id=$game_id";
            $sqlFallback = "UPDATE games SET current_round=$next_round WHERE id=$game_id";
            quiz_safe_update($sqlWith, $sqlFallback);

            echo json_encode(["action" => "next", "vote" => true]);
            exit;
        }
    }

    $sqlWith = "UPDATE games
                SET current_round=$next_round,
                    round_ends_at = DATE_ADD(NOW(), INTERVAL time_per_question SECOND),
                    vote_ends_at = NULL
                WHERE id=$game_id";

    $sqlFallback = "UPDATE games SET current_round=$next_round WHERE id=$game_id";

    quiz_safe_update($sqlWith, $sqlFallback);

    echo json_encode(["action" => "next"]);
    exit;

} finally {
    quiz_release_lock($lockKey);
}
