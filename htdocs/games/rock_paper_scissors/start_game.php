<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_username() {
    if (!empty($_SESSION['user']['username'])) return $_SESSION['user']['username'];
    if (!empty($_SESSION['username'])) return $_SESSION['username'];
    if (!empty($_SESSION['guest_name'])) return $_SESSION['guest_name'];
    return 'Gosc';
}

$game_id = (int)($_POST['game_id'] ?? 0);
if ($game_id <= 0) { header('Location: index.php'); exit; }

$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g = mysqli_fetch_assoc($res);
if (!$g) { die('Gra nie istnieje.'); }

$me = current_username();

// ta sama logika co w lobby
function current_user_id_local() {
    return $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
}
$uid = current_user_id_local();

$is_owner = false;
if (!empty($g['player1_id']) && $g['player1_id'] > 0 && $uid > 0) {
    $is_owner = ((int)$g['player1_id'] === (int)$uid);
} else {
    $is_owner = ($g['player1_name'] === $me);
}

if (!$is_owner) {
    die('Tylko założyciel może wystartować grę.');
}

$q = "
    UPDATE pkn_games 
    SET status = 'playing',
        current_round = 1,
        player1_move = NULL,
        player2_move = NULL
    WHERE id = $game_id
";

if (!mysqli_query($conn, $q)) {
    die('Błąd przy starcie gry: ' . mysqli_error($conn));
}

header('Location: game.php?game=' . $game_id);
exit;
