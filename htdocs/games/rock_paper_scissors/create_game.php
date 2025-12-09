<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_user_id() {
    return $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
}
function current_username() {
    if (!empty($_SESSION['user']['username'])) return $_SESSION['user']['username'];
    if (!empty($_SESSION['username'])) return $_SESSION['username'];
    if (!empty($_SESSION['guest_name'])) return $_SESSION['guest_name'];
    return 'Gosc';
}

$rounds = max(1, min(15, (int)($_POST['rounds'] ?? 1)));
// losowy kod pokoju - bez random_bytes (zgodny z tanim hostingiem)
$code = strtoupper(substr(md5(uniqid('', true)), 0, 6));

$player1_id = current_user_id();
$player1_name = mysqli_real_escape_string($conn, current_username());

$q = sprintf(
    "INSERT INTO pkn_games (code, rounds_total, current_round, player1_id, player1_name, player2_id, player2_name, p1_score, p2_score, status)
     VALUES ('%s', %d, 1, %d, '%s', 0, NULL, 0, 0, 'waiting')",
    $code, $rounds, $player1_id, $player1_name
);
if (!mysqli_query($conn, $q)) {
    die('Błąd tworzenia gry: ' . mysqli_error($conn));
}
$game_id = mysqli_insert_id($conn);
header('Location: lobby.php?game=' . $game_id);
exit;
