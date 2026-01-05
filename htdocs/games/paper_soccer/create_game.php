<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// tryb gry może pochodzić z POST albo GET
$mode = $_POST['mode'] ?? $_GET['mode'] ?? null;

if (!in_array($mode, ['bot', 'pvp'])) {
    die("Nieprawidłowy tryb gry.");
}

function random_code($len = 6) {
    $c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $o = '';
    for ($i = 0; $i < $len; $i++) {
        $o .= $c[rand(0, strlen($c) - 1)];
    }
    return $o;
}

// ID + nazwa gracza 1
$player1_id = is_logged_in() ? (int)$_SESSION['user_id'] : (int)$_SESSION['guest_id'];
$player1_name = is_logged_in() ? $_SESSION['username'] : $_SESSION['guest_name'];

$code = random_code(6);

if ($mode === 'bot') {

    $difficulty = (int)($_POST['bot_difficulty'] ?? $_GET['bot_difficulty'] ?? 1);
    $difficulty = max(1, min(4, $difficulty)); // NOWE: 1..4

    $stmt = $conn->prepare("
        INSERT INTO paper_soccer_games (
            code, mode, bot_difficulty,
            player1_id, player1_name,
            player2_id, player2_name,
            status, current_player
        ) VALUES (
            ?, 'bot', ?, ?, ?, 0, NULL, 'playing', 1
        )
    ");

    if (!$stmt) {
        die("SQL prepare error: " . $conn->error);
    }

    $stmt->bind_param("siis", $code, $difficulty, $player1_id, $player1_name);

} else {

    $stmt = $conn->prepare("
        INSERT INTO paper_soccer_games (
            code, mode, bot_difficulty,
            player1_id, player1_name,
            player2_id, player2_name,
            status, current_player
        ) VALUES (
            ?, 'pvp', NULL, ?, ?, 0, NULL, 'waiting', 1
        )
    ");

    if (!$stmt) {
        die("SQL prepare error: " . $conn->error);
    }

    $stmt->bind_param("sis", $code, $player1_id, $player1_name);
}

$stmt->execute();
$game_id = $stmt->insert_id;
$stmt->close();

header("Location: play.php?game_id=" . $game_id);
exit;
