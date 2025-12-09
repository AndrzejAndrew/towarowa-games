<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ttt_boot.php';

$code = $_GET['code'] ?? '';
$game = ttt_fetch_game_by_code($code, $conn);
if (!$game) {
    echo json_encode(['ok'=>false, 'error'=>'not_found']);
    exit;
}

$me    = ttt_current_player_id();
$vsBot = !empty($game['vs_bot']);

$playerXName = $game['player_x'] ? ttt_display_name((int)$game['player_x'], $conn) : '—';
if ($vsBot) {
    $playerOName = 'Bot';
} else {
    $playerOName = $game['player_o'] ? ttt_display_name((int)$game['player_o'], $conn) : '—';
}

$mySymbol = null;
if ((int)$game['player_x'] === $me) {
    $mySymbol = 'X';
} elseif (!$vsBot && (int)$game['player_o'] === $me) {
    $mySymbol = 'O';
}

// Kto jest zwycięzcą (id użytkownika albo 0 = bot)
$winnerId = ($game['winner'] !== null) ? (int)$game['winner'] : null;
if ($winnerId === null) {
    $winnerName = null;
} elseif ($vsBot && $winnerId === 0) {
    $winnerName = 'Bot';
} else {
    $winnerName = ttt_display_name($winnerId, $conn);
}

/**
 * WYLICZENIE TYPU LINII ZWYCIĘSTWA
 * row0,row1,row2,col0,col1,col2,diag0,diag1
 */
$winningType = null;
$boardArr    = str_split($game['board']);

$wins = [
    ['cells' => [0,1,2], 'type' => 'row0'],
    ['cells' => [3,4,5], 'type' => 'row1'],
    ['cells' => [6,7,8], 'type' => 'row2'],
    ['cells' => [0,3,6], 'type' => 'col0'],
    ['cells' => [1,4,7], 'type' => 'col1'],
    ['cells' => [2,5,8], 'type' => 'col2'],
    ['cells' => [0,4,8], 'type' => 'diag0'],
    ['cells' => [2,4,6], 'type' => 'diag1'],
];

foreach ($wins as $w) {
    $c = $w['cells'];
    if (
        $boardArr[$c[0]] !== '_' &&
        $boardArr[$c[0]] === $boardArr[$c[1]] &&
        $boardArr[$c[1]] === $boardArr[$c[2]]
    ) {
        $winningType = $w['type'];
        break;
    }
}

echo json_encode([
    'ok'             => true,
    'code'           => $game['code'],
    'board'          => $game['board'],
    'turn'           => $game['turn'],
    'status'         => $game['status'],
    'winner'         => $winnerId,
    'winner_name'    => $winnerName,
    'player_x'       => $game['player_x'] ? (int)$game['player_x'] : null,
    'player_o'       => $game['player_o'] ? (int)$game['player_o'] : null,
    'player_x_name'  => $playerXName,
    'player_o_name'  => $playerOName,
    'me'             => $me,
    'my_symbol'      => $mySymbol,
    'vs_bot'         => (bool)$vsBot,
    'winning_type'   => $winningType,   // <<< TU DODANE
]);
