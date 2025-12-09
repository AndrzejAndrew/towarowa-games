<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$game_id = (int)($_POST['game_id'] ?? 0);
if ($game_id <= 0) {
    die("Brak ID gry.");
}

// pobierz grę wraz z trybem
$res = mysqli_query($conn,
    "SELECT owner_player_id, total_rounds, mode FROM games WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);

if (!$game) {
    die("Nie ma takiej gry.");
}

$owner_player_id = (int)$game['owner_player_id'];
$mode = $game['mode'] ?? 'classic';

// ustal aktualnego playera
$is_guest = is_logged_in() ? 0 : 1;

if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}

mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$player || (int)$player['id'] !== $owner_player_id) {
    die("Nie jesteś gospodarzem tej gry.");
}

/*
    ======================================================
    TRYB DYNAMICZNY — przejście bez losowania pytań
    ======================================================
*/
if ($mode === 'dynamic') {

    // ustaw start gry
    mysqli_query($conn,
        "UPDATE games 
         SET status='running', current_round=1 
         WHERE id = $game_id"
    );

    // przekierowanie do głosowania
    header("Location: vote.php?game=" . $game_id);
    exit;
}

/*
    ======================================================
    TRYB KLASYCZNY — STARA LOGIKA
    ======================================================
*/

// policz pytania przypisane do tej gry
$res = mysqli_query($conn,
    "SELECT COUNT(*) AS c FROM game_questions WHERE game_id = $game_id"
);
$row = mysqli_fetch_assoc($res);
$qcount = (int)($row['c'] ?? 0);

if ($qcount <= 0) {
    die("Brak pytań przypisanych do tej gry. Utwórz grę ponownie.");
}

// dopasowanie rund
$total_rounds = min((int)$game['total_rounds'], $qcount);

// uruchomienie gry
mysqli_query($conn,
    "UPDATE games 
     SET status='running', total_rounds=$total_rounds, current_round=1 
     WHERE id = $game_id"
);

header("Location: game.php?game=" . $game_id);
exit;
