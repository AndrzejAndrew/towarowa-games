<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$code = strtoupper(trim($_GET['code'] ?? ''));
if ($code === '') {
    echo json_encode(['error' => 'no_code']);
    exit;
}

// gra
$stmt = mysqli_prepare($conn, "SELECT id, status FROM games WHERE code = ?");
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$game) {
    echo json_encode(['error' => 'no_game']);
    exit;
}

$game_id = (int)$game['id'];

// gracze
$stmt = mysqli_prepare($conn, "SELECT nickname, score FROM players WHERE game_id = ? ORDER BY id ASC");
mysqli_stmt_bind_param($stmt, "i", $game_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$players = [];
while ($row = mysqli_fetch_assoc($result)) {
    $players[] = [
        'nickname' => $row['nickname'],
        'score' => (int)$row['score']
    ];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'status' => $game['status'],
    'players' => $players
]);
