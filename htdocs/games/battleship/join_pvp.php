<?php
// games/battleship/join_pvp.php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
define('BATTLESHIP_INCLUDED', true);
require_once __DIR__ . '/battleship_logic.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$code = $_POST['join_code'] ?? ($_GET['code'] ?? '');
$code = trim($code);
if ($code === '') die("Brak kodu.");

$stmt = mysqli_prepare($conn,
    "SELECT * FROM battleship_games WHERE join_code=? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$res  = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) die("Nie znaleziono gry.");
if ($game['player2_id']) die("W tej grze jest już dwóch graczy.");
if ($game['mode'] !== 'pvp') die("To nie jest gra PVP.");

// Dane gracza 2
$p2_id   = null;
$p2_name = "Gość_" . rand(1000,9999);

if (function_exists('is_logged_in') && is_logged_in()) {
    if (!empty($_SESSION['user_id']))   $p2_id   = (int)$_SESSION['user_id'];
    if (!empty($_SESSION['username']))  $p2_name = $_SESSION['username'];
}

// Czy gra jest manualna (obaj ustawiają statki)
$manual_mode = (int)$game['manual_setup1'];  // 1 = manualna, 0 = auto

$player2_state_json = null;
$new_status         = $game['status'];

// LOGIKA:
//  - Gra manualna: obaj ustawiają → status 'prepare_both'
//  - Gra automatyczna: P2 dostaje flotę → jeśli P1 ma state, start gry

if ($manual_mode === 1) {
    // Manualna: P2 ustawi statki ręcznie → player2_state = NULL
    $player2_state_json = null;

    // Niezależnie od tego, co było, po dołączeniu mamy tryb wspólnego
    // rozstawiania statków.
    $new_status = 'prepare_both';
} else {
    // Automatyczna: P2 dostaje losową flotę
    $p2_state            = battleship_generate_state();
    $player2_state_json  = json_encode($p2_state);

    // Jeśli P1 również ma już flotę → gra startuje
    if (!empty($game['player1_state'])) {
        $new_status             = 'in_progress';
        $game['current_turn']   = 1;
    } else {
        // P1 jeszcze nie ma floty (teoretycznie nie powinno się zdarzyć)
        $new_status = 'lobby';
    }
}

$stmt = mysqli_prepare($conn,
    "UPDATE battleship_games
     SET player2_id=?, player2_name=?, player2_state=?, status=?
     WHERE id=?"
);
mysqli_stmt_bind_param(
    $stmt,
    "isssi",
    $p2_id,
    $p2_name,
    $player2_state_json,
    $new_status,
    $game['id']
);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// Zapisz w sesji, że to gracz 2
$_SESSION['battleship_player_'.$game['id']] = 2;

header("Location: play.php?game=".$game['id']);
exit;
