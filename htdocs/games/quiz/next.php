<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$code = strtoupper(trim($_GET['code'] ?? ''));
$round = (int)($_GET['round'] ?? 0);

if ($code === '' || $round <= 0) {
    echo json_encode(['action' => 'wait', 'error' => 'bad_params']);
    exit;
}

// gra
$res = mysqli_query($conn, "SELECT id, total_rounds, current_round, status FROM games WHERE code = '$code'");
$game = mysqli_fetch_assoc($res);
if (!$game) {
    echo json_encode(['action' => 'finish', 'error' => 'no_game']);
    exit;
}
$game_id = (int)$game['id'];

if ($game['status'] === 'finished') {
    echo json_encode(['action' => 'finish']);
    exit;
}

// ilu graczy
$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM players WHERE game_id = $game_id");
$row = mysqli_fetch_assoc($res);
$total_players = (int)$row['c'];

// jakie pytanie jest w tej rundzie
$res = mysqli_query($conn,
    "SELECT question_id FROM game_questions WHERE game_id = $game_id AND round_number = $round"
);
$gq = mysqli_fetch_assoc($res);
if (!$gq) {
    // brak pytania – kończymy
    mysqli_query($conn, "UPDATE games SET status = 'finished' WHERE id = $game_id");
    echo json_encode(['action' => 'finish']);
    exit;
}
$question_id = (int)$gq['question_id'];

// ile odpowiedzi
$res = mysqli_query($conn,
    "SELECT COUNT(*) AS c FROM answers
     WHERE game_id = $game_id AND question_id = $question_id"
);
$row = mysqli_fetch_assoc($res);
$answers_count = (int)$row['c'];

if ($answers_count < $total_players) {
    // jeszcze czekamy
    echo json_encode(['action' => 'wait', 'players' => $total_players, 'answers' => $answers_count]);
    exit;
}

// wszyscy odpowiedzieli -> przechodzimy dalej
if ($round < (int)$game['total_rounds']) {
    // zwiększamy bieżącą rundę, jeśli jeszcze jej nie zwiększono
    if ((int)$game['current_round'] == $round) {
        mysqli_query($conn, "UPDATE games SET current_round = current_round + 1 WHERE id = $game_id");
    }
    echo json_encode(['action' => 'next']);
} else {
    // koniec gry
    mysqli_query($conn, "UPDATE games SET status = 'finished' WHERE id = $game_id");
    echo json_encode(['action' => 'finish']);
}
