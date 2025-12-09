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
function current_user_id() {
    return $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
}

$code = strtoupper(trim($_GET['code'] ?? ''));
if (!$code) { header('Location: index.php'); exit; }

$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE code = '" . mysqli_real_escape_string($conn,$code) . "' LIMIT 1");
$game = mysqli_fetch_assoc($res);
if (!$game) { die("Nie znaleziono gry o kodzie $code."); }

$game_id = (int)$game['id'];
$me_id = current_user_id();
$me_name = mysqli_real_escape_string($conn, current_username());

if ($game['status'] !== 'waiting' && (int)$game['player2_id'] === 0) {
    die('Gra jest już w toku.');
}

if ((int)$game['player2_id'] === 0 && $game['player1_name'] !== $me_name) {
    $q = sprintf("UPDATE pkn_games SET player2_id=%d, player2_name='%s' WHERE id=%d AND (player2_id = 0 OR player2_id IS NULL)",
        $me_id, $me_name, $game_id);
    mysqli_query($conn, $q);
}

header('Location: lobby.php?game=' . $game_id);
exit;
