<?php
require_once __DIR__ . '/ttt_boot.php';

function ttt_random_code(int $len = 5): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i=0; $i<$len; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return $code;
}

$playerId = ttt_current_player_id();

// tryb gry: bot / drugi gracz
$mode = $_POST['mode'] ?? 'pvp';
$vsBot = ($mode === 'bot') ? 1 : 0;

// dla bota gra od razu jest "playing", dla 2 graczy – "waiting"
$status = $vsBot ? 'playing' : 'waiting';

$code = ttt_random_code();

$tries = 0;
do {
    $tries++;
    $esc = mysqli_real_escape_string($conn, $code);
    $exists = mysqli_query($conn, "SELECT id FROM ttt_games WHERE code='{$esc}' LIMIT 1");
    if ($exists && mysqli_fetch_assoc($exists)) {
        $code = ttt_random_code();
    } else {
        break;
    }
} while ($tries < 5);

$esc = mysqli_real_escape_string($conn, $code);
mysqli_query($conn, "INSERT INTO ttt_games (code, player_x, board, turn, status, vs_bot)
                     VALUES ('{$esc}', {$playerId}, '_________', 'X', '{$status}', {$vsBot})");

header("Location: room.php?code={$code}");
exit;
