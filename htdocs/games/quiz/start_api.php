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

function quiz_try_update_game(string $sql_with_timers, string $sql_fallback): void
{
    global $conn;

    if (mysqli_query($conn, $sql_with_timers)) {
        return;
    }

    $errno = mysqli_errno($conn);
    $err   = mysqli_error($conn);

    // Migracja DB mogła jeszcze nie zostać wykonana (unknown column)
    if ($errno === 1054) {
        if (!mysqli_query($conn, $sql_fallback)) {
            die("Błąd bazy danych (fallback): " . mysqli_error($conn));
        }
        return;
    }

    die("Błąd bazy danych: " . $err);
}

// pobierz grę wraz z trybem
$res = mysqli_query(
    $conn,
    "SELECT owner_player_id, total_rounds, mode FROM games WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);

if (!$game) {
    die("Nie ma takiej gry.");
}

$owner_player_id = (int)$game['owner_player_id'];
$mode = $game['mode'] ?? 'classic';

// ustal aktualnego playera
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare(
        $conn,
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
    TRYB DYNAMICZNY — start gry i przejście do głosowania
    ======================================================
*/
if ($mode === 'dynamic') {

    // status=running + deadline na głosowanie (używamy time_per_question jako czasu na wybór kategorii)
    $sqlWith = "UPDATE games
                SET status='running', current_round=1,
                    vote_ends_at = DATE_ADD(NOW(), INTERVAL time_per_question SECOND),
                    round_ends_at = NULL
                WHERE id = $game_id";
    $sqlFallback = "UPDATE games
                    SET status='running', current_round=1
                    WHERE id = $game_id";

    quiz_try_update_game($sqlWith, $sqlFallback);

    header("Location: vote.php?game=" . $game_id);
    exit;
}

/*
    ======================================================
    TRYB KLASYCZNY — losowanie już wykonane w create_game.php
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

$sqlWith = "UPDATE games
            SET status='running', total_rounds=$total_rounds, current_round=1,
                round_ends_at = DATE_ADD(NOW(), INTERVAL time_per_question SECOND),
                vote_ends_at = NULL
            WHERE id = $game_id";

$sqlFallback = "UPDATE games
                SET status='running', total_rounds=$total_rounds, current_round=1
                WHERE id = $game_id";

quiz_try_update_game($sqlWith, $sqlFallback);

header("Location: game.php?game=" . $game_id);
exit;
