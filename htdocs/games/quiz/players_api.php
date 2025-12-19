<?php
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    echo json_encode(['error' => 'bad_game']);
    exit;
}

// Pobierz status + tryb + aktualną rundę i sprawdź czy runda ma przypisane pytanie.
// (W trybie dynamicznym, gdy status=running i has_question=0, klient powinien trafić na vote.php zamiast game.php.)
$res = mysqli_query($conn,
    "SELECT g.status, g.mode, g.current_round, g.total_rounds,\n            (SELECT 1 FROM game_questions gq WHERE gq.game_id=g.id AND gq.round_number=g.current_round LIMIT 1) AS has_question\n     FROM games g\n     WHERE g.id = $game_id\n     LIMIT 1"
);
$game = $res ? mysqli_fetch_assoc($res) : null;
if (!$game) {
    echo json_encode(['error' => 'no_game']);
    exit;
}

$status = $game['status'] ?? 'lobby';
$mode = $game['mode'] ?? 'classic';
$current_round = (int)($game['current_round'] ?? 1);
$total_rounds = (int)($game['total_rounds'] ?? 0);
$has_question = !empty($game['has_question']) ? 1 : 0;

$res = mysqli_query($conn,
    "SELECT nickname, score\n     FROM players\n     WHERE game_id = $game_id\n     ORDER BY id ASC"
);

$players = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $players[] = [
            'nickname' => $row['nickname'],
            'score' => (int)$row['score']
        ];
    }
}

echo json_encode([
    'status' => $status,
    'mode' => $mode,
    'current_round' => $current_round,
    'total_rounds' => $total_rounds,
    'has_question' => $has_question,
    'players' => $players
]);
