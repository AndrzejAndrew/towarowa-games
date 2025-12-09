<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}
$code = strtoupper(trim($_POST['code'] ?? ''));
if ($code === '') {
    header("Location: index.php");
    exit;
}

// znajdź grę w lobby
$stmt = mysqli_prepare($conn,
    "SELECT id, status FROM games WHERE code = ?"
);
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) {
    die("Nie znaleziono gry o podanym kodzie.");
}
if ($game['status'] !== 'lobby') {
    die("Ta gra już się rozpoczęła lub zakończyła.");
}
$game_id = (int)$game['id'];

// dane gracza
$is_guest = is_logged_in() ? 0 : 1;
$user_id = is_logged_in() ? (int)$_SESSION['user_id'] : null;
$nickname = is_logged_in() ? $_SESSION['username'] : ($_SESSION['guest_name'] ?? 'Gość');

// sprawdź czy już jest w tej grze
if ($user_id !== null) {
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $user_id);
} else {
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$exists = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);

if (!$exists) {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO players (game_id, user_id, nickname, is_guest)
         VALUES (?, ?, ?, ?)"
    );
    if ($user_id === null) {
        $null = null;
        mysqli_stmt_bind_param($stmt, "issi", $game_id, $null, $nickname, $is_guest);
    } else {
        mysqli_stmt_bind_param($stmt, "issi", $game_id, $user_id, $nickname, $is_guest);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

header("Location: lobby.php?game=" . $game_id);
exit;
