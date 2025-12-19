<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$game_id = (int)($_POST['game_id'] ?? 0);
$category = trim($_POST['category'] ?? '');
if ($game_id <= 0 || $category === '') {
    die("Brak danych.");
}

// pobierz grę
$gRes = mysqli_query($conn,
    "SELECT current_round, mode, status FROM games WHERE id = $game_id LIMIT 1"
);
$game = mysqli_fetch_assoc($gRes);
if (!$game) {
    die("Gra nie istnieje.");
}

if (($game['mode'] ?? 'classic') !== 'dynamic') {
    header("Location: game.php?game=" . $game_id);
    exit;
}

if (($game['status'] ?? '') !== 'running') {
    header("Location: lobby.php?game=" . $game_id);
    exit;
}

$current_round = (int)$game['current_round'];
$vote_round = (int)floor(($current_round - 1) / 5) + 1;

// ustal playera
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id=? AND user_id=?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $guest_id = (int)($_SESSION['guest_id'] ?? 0);
    if ($guest_id > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM players WHERE game_id=? AND is_guest=1 AND guest_id=?"
        );
        mysqli_stmt_bind_param($stmt, "ii", $game_id, $guest_id);
    } else {
        $nickname = $_SESSION['guest_name'] ?? 'Gość';
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM players WHERE game_id=? AND is_guest=1 AND nickname=?"
        );
        mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
    }
}

mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$player) {
    die("Nie jesteś w tej grze.");
}
$player_id = (int)$player['id'];

// jeśli już głosował, nie nadpisuj
$check = mysqli_prepare($conn, "SELECT id FROM votes WHERE game_id=? AND player_id=? AND round_number=? LIMIT 1");
mysqli_stmt_bind_param($check, "iii", $game_id, $player_id, $vote_round);
mysqli_stmt_execute($check);
$chkRes = mysqli_stmt_get_result($check);
$exists = mysqli_fetch_assoc($chkRes);
mysqli_stmt_close($check);

if (!$exists) {
    $ins = mysqli_prepare($conn,
        "INSERT INTO votes (game_id, player_id, round_number, category) VALUES (?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($ins, "iiis", $game_id, $player_id, $vote_round, $category);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
}

header("Location: vote.php?game=" . $game_id);
exit;
