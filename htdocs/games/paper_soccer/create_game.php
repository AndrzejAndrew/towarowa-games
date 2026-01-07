<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$mode = $_POST['mode'] ?? $_GET['mode'] ?? null;
if (!in_array($mode, ['bot', 'pvp'], true)) {
    die("Nieprawidłowy tryb gry.");
}

function random_code($len = 6) {
    $c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $o = '';
    for ($i = 0; $i < $len; $i++) {
        $o .= $c[random_int(0, strlen($c) - 1)];
    }
    return $o;
}

$code = random_code();

// Gracz 1
$player1_id   = is_logged_in() ? (int)($_SESSION['user_id'] ?? 0) : (int)($_SESSION['guest_id'] ?? 0);
$player1_name = is_logged_in() ? ($_SESSION['username'] ?? 'Gracz') : ($_SESSION['guest_name'] ?? 'Gość');

if ($player1_id <= 0) {
    die("Brak identyfikacji gracza (sesja).");
}

// Start boiska (jak w move.php/state.php)
$ball_x = 4;
$ball_y = 6;
$used_lines_json = '[]';
$winner = 0;
$current_player = 1;

if ($mode === 'bot') {
    $difficulty_raw = (int)($_POST['bot_difficulty'] ?? $_GET['bot_difficulty'] ?? 1);
    $difficulty = max(1, min(4, $difficulty_raw));

    $stmt = $conn->prepare("
        INSERT INTO paper_soccer_games (
            code, mode, bot_difficulty,
            player1_id, player1_name,
            player2_id, player2_name,
            status, current_player,
            ball_x, ball_y, used_lines, winner
        ) VALUES (
            ?, 'bot', ?,
            ?, ?,
            0, 'BOT',
            'playing', ?,
            ?, ?, ?, ?
        )
    ");
    if (!$stmt) die("DB error: ".$conn->error);

    $stmt->bind_param("siisiiii", $code, $difficulty, $player1_id, $player1_name, $current_player, $ball_x, $ball_y, $used_lines_json, $winner);
} else {
    $stmt = $conn->prepare("
        INSERT INTO paper_soccer_games (
            code, mode, bot_difficulty,
            player1_id, player1_name,
            player2_id, player2_name,
            status, current_player,
            ball_x, ball_y, used_lines, winner
        ) VALUES (
            ?, 'pvp', NULL,
            ?, ?,
            0, NULL,
            'waiting', ?,
            ?, ?, ?, ?
        )
    ");
    if (!$stmt) die("DB error: ".$conn->error);

    $stmt->bind_param("isiiiiii", $code, $player1_id, $player1_name, $current_player, $ball_x, $ball_y, $used_lines_json, $winner);
}

$stmt->execute();
$game_id = (int)$stmt->insert_id;
$stmt->close();

// WŁAŚCIWE przekierowanie do gry (to obsługuje lobby PvP i bot)
header("Location: play.php?game_id=" . $game_id);
exit;
