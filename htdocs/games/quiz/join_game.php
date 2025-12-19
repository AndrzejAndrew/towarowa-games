<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// pozwalamy na POST (formularz) i GET (link do dołączenia)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    header("Location: index.php");
    exit;
}

$code = strtoupper(trim((string)($_REQUEST['code'] ?? '')));
if ($code === '') {
    die("Brak kodu gry.");
}

// znajdź grę po code
$stmt = mysqli_prepare($conn, "SELECT id, status, mode FROM games WHERE code=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) {
    die("Nie znaleziono gry o tym kodzie.");
}

$game_id = (int)$game['id'];
$status = $game['status'] ?? 'lobby';

if ($status === 'finished') {
    header("Location: finish.php?game=" . $game_id);
    exit;
}

// ustal dane gracza
$nickname = '';
$user_id = null;
$is_guest = 0;
$guest_id = null;

if (is_logged_in()) {
    $nickname = $_SESSION['username'] ?? 'Użytkownik';
    $user_id = (int)$_SESSION['user_id'];
    $is_guest = 0;
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $guest_id = (int)($_SESSION['guest_id'] ?? 0);
    $is_guest = 1;
}

// sprawdź czy już jest w players
if ($is_guest) {
    if ($guest_id > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM players WHERE game_id=? AND is_guest=1 AND guest_id=? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "ii", $game_id, $guest_id);
    } else {
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM players WHERE game_id=? AND is_guest=1 AND nickname=? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
    }
} else {
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id=? AND user_id=? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $user_id);
}

mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$existing = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$existing) {
    // insert
    $stmt = mysqli_prepare($conn,
        "INSERT INTO players (game_id, nickname, user_id, is_guest, guest_id) VALUES (?, ?, ?, ?, ?)"
    );
    $uidParam = $user_id;
    $gidParam = $guest_id;
    mysqli_stmt_bind_param($stmt, "isiii", $game_id, $nickname, $uidParam, $is_guest, $gidParam);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// redirect do lobby
header("Location: lobby.php?game=" . $game_id);
exit;
