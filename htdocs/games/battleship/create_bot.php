<?php
// games/battleship/create_bot.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
define('BATTLESHIP_INCLUDED', true);
require_once __DIR__ . '/battleship_logic.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$difficulty = $_POST['difficulty'] ?? 'easy';
$manual_setup = isset($_POST['manual_setup']) ? 1 : 0;

// Ustalenie gracza
$player1_id = null;
$player1_name = "Gość_" . rand(1000,9999);

if (function_exists('is_logged_in') && is_logged_in()) {

    if (!empty($_SESSION['user_id'])) {
        $player1_id = (int)$_SESSION['user_id'];
    }

    if (!empty($_SESSION['username'])) {
        $player1_name = $_SESSION['username'];
    }
}


// BOT MA ZAWSZE LOSOWĄ PLANSZĘ
$bot_state = battleship_generate_state();
$bot_json  = json_encode($bot_state);

// Jeżeli gracz wybiera automatyczne ustawienie:
if ($manual_setup == 0) {

    // Player1 też dostaje losową
    $p1_state = battleship_generate_state();
    $p1_json  = json_encode($p1_state);

    $status = "in_progress";

} else {
    // Player1 będzie rozstawiać ręcznie
    $p1_json = null;
    $status = "prepare_p1";   // nowy status
}

$stmt = mysqli_prepare($conn,
    "INSERT INTO battleship_games 
    (mode, difficulty, player1_id, player1_name, player1_state, player2_state,
     manual_setup1, manual_setup2, status)
     VALUES ('bot', ?, ?, ?, ?, ?, ?, 0, ?)"
);

mysqli_stmt_bind_param(
    $stmt,
    "sisssis",
    $difficulty,
    $player1_id,
    $player1_name,
    $p1_json,
    $bot_json,
    $manual_setup,
    $status
);

mysqli_stmt_execute($stmt);
$game_id = mysqli_insert_id($conn);

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['battleship_player_'.$game_id] = 1;

header("Location: play.php?game=$game_id");
exit;
