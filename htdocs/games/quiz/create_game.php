<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/discord.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// TRYB GRY
$mode = $_POST['mode'] ?? 'classic';
if ($mode !== 'classic' && $mode !== 'dynamic') {
    $mode = 'classic';
}

$total_rounds = max(1, (int)($_POST['total_rounds'] ?? 5));
$time_per_question = max(5, (int)($_POST['time_per_question'] ?? 20));

// W trybie dynamicznym: total_rounds = liczba TUR (każda po 5 pytań)
// W klasycznym: total_rounds = liczba pytań
if ($mode === 'dynamic') {
    $questions_per_round = 5;
    $total_questions = $total_rounds * $questions_per_round;
} else {
    $questions_per_round = 1;
    $total_questions = $total_rounds;
}

/*
    1. KATEGORIE (tylko CLASSIC)
*/
$categories = $_POST['categories'] ?? [];

if ($mode === 'classic') {
    if (empty($categories)) {
        die("W trybie klasycznym musisz wybrać co najmniej jedną kategorię.");
    }

    $cat_list = "'" . implode("','", array_map(function($c) use ($conn) {
        return mysqli_real_escape_string($conn, $c);
    }, $categories)) . "'";

    // sprawdzenie liczby pytań
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM questions WHERE category IN ($cat_list)");
    $row = mysqli_fetch_assoc($res);
    $questions_count = (int)$row['c'];

    if ($questions_count < $total_questions) {
        die("Za mało pytań w wybranych kategoriach: tylko $questions_count, potrzebujesz $total_questions.");
    }
}

/*
    2. UŻYTKOWNIK
*/
$is_guest = is_logged_in() ? 0 : 1;
$user_id = is_logged_in() ? (int)$_SESSION['user_id'] : null;
$nickname = is_logged_in() ? $_SESSION['username'] : ($_SESSION['guest_name'] ?? 'Gość');

/*
    3. GENEROWANIE KODU GRY
*/
function gen_code($len = 6) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < $len; $i++) {
        $c .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $c;
}

do {
    $code = gen_code();
    $stmt = mysqli_prepare($conn, "SELECT id FROM games WHERE code = ?");
    mysqli_stmt_bind_param($stmt, "s", $code);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
} while ($exists);

/*
    4. TWORZENIE GRY
    W polu total_rounds zapisujemy LICZBĘ PYTAŃ (total_questions),
    żeby nagłówek "pytanie X / Y" był poprawny.
*/
$stmt = mysqli_prepare($conn,
    "INSERT INTO games (code, total_rounds, time_per_question, status, current_round, mode)
     VALUES (?, ?, ?, 'lobby', 1, ?)"
);
mysqli_stmt_bind_param($stmt, "siis", $code, $total_questions, $time_per_question, $mode);
mysqli_stmt_execute($stmt);
$game_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

/*
    5. DODANIE GRACZA-HOSTA
*/
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
$player_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

mysqli_query($conn,
    "UPDATE games SET owner_player_id = $player_id WHERE id = $game_id"
);

/*
    6. LOSOWANIE PYTAŃ (tylko CLASSIC)
*/
mysqli_query($conn, "DELETE FROM game_questions WHERE game_id = $game_id");

if ($mode === 'classic') {
    $res = mysqli_query($conn,
        "SELECT id FROM questions
         WHERE category IN ($cat_list)
         ORDER BY RAND()
         LIMIT $total_questions"
    );

    $round = 1;
    while ($q = mysqli_fetch_assoc($res)) {
        $qid = (int)$q['id'];
        mysqli_query($conn,
            "INSERT INTO game_questions (game_id, question_id, round_number)
             VALUES ($game_id, $qid, $round)"
        );
        $round++;
    }
}
/*
$res = discord_send('quiz', 'TEST WEBHOOK ' . date('H:i:s'));
file_put_contents(
    __DIR__ . '/_discord_test.log',
    date('c') . ' => ' . var_export($res, true) . PHP_EOL,
    FILE_APPEND
);
*/
/*
    7. DISCORD – info
*/
try {
    if ($mode === 'classic') {
        $category_list = implode(', ', $categories);
    } else {
        $category_list = 'Dynamiczny – kategorie wybierane w trakcie gry';
    }

    $discordMessage = "🎮 **Utworzono nowe lobby QUIZ**\n"
        . "Kod: **{$code}** (ID: {$game_id})\n"
        . "Tryb: {$mode}\n"
        . "Info: {$category_list}\n"
        . "Rundy (formularz): {$total_rounds}, pytań łącznie: {$total_questions}\n"
        . "Czas na pytanie: {$time_per_question} s\n"
        . "Twórca: {$nickname}";

    discord_send(
        'quiz',
        $discordMessage,
        $DISCORD_META['quiz']['username'] ?? 'Quiz Lobby',
        $DISCORD_META['quiz']['color'] ?? 0x3498DB
    );
} catch (Throwable $e) {
    // ignorujemy
}

/*
    8. PRZEJŚCIE DO LOBBY
*/
header("Location: lobby.php?game=" . $game_id);
exit;
