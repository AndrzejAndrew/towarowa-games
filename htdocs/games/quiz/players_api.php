<?php
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    echo json_encode(['error' => 'bad_game']);
    exit;
}

$res = mysqli_query($conn,
    "SELECT status FROM games WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);
if (!$game) {
    echo json_encode(['error' => 'no_game']);
    exit;
}
$status = $game['status'];

$res = mysqli_query($conn,
    "SELECT nickname, score FROM players WHERE game_id = $game_id ORDER BY id ASC"
);
$players = [];
while ($row = mysqli_fetch_assoc($res)) {
    $players[] = [
        'nickname' => $row['nickname'],
        'score' => (int)$row['score']
    ];
}

echo json_encode([
    'status' => $status,
    'players' => $players
]);
