<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// -------------------------------------------
// Pomocnicze
// -------------------------------------------
function hm_json_error(string $msg) {
    echo json_encode([
        'success' => false,
        'error'   => $msg,
    ]);
    exit;
}

function hm_get_current_user_id(): ?int {
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

function hm_resolve_nickname(): string {
    // Preferuj aktualny username / guest_name
    if (is_logged_in() && !empty($_SESSION['username'])) {
        $nick = $_SESSION['username'];
    } elseif (!is_logged_in() && !empty($_SESSION['guest_name'])) {
        $nick = $_SESSION['guest_name'];
    } elseif (!empty($_SESSION['hangman_nickname'])) {
        $nick = $_SESSION['hangman_nickname'];
    } else {
        $nick = 'Gracz_' . rand(1000, 9999);
    }
    $_SESSION['hangman_nickname'] = $nick;
    return $nick;
}

/**
 * Zapewnia, że aktualny użytkownik jest w hangman_players.
 * Zwraca wiersz gracza lub null.
 */
function hm_ensure_player(mysqli $conn, int $game_id): ?array {
    $user_id = hm_get_current_user_id();

    // 1) zalogowany -> po user_id
    if ($user_id !== null && $user_id > 0) {
        $res = mysqli_query($conn,
            "SELECT * FROM hangman_players
             WHERE game_id = $game_id AND user_id = $user_id
             LIMIT 1"
        );
        if ($res && mysqli_num_rows($res) > 0) {
            return mysqli_fetch_assoc($res);
        }
    }

    // 2) gość -> po nicku
    $nickname     = hm_resolve_nickname();
    $nickname_esc = mysqli_real_escape_string($conn, $nickname);

    $res = mysqli_query($conn,
        "SELECT * FROM hangman_players
         WHERE game_id = $game_id
           AND user_id IS NULL
           AND nickname = '$nickname_esc'
         LIMIT 1"
    );
    if ($res && mysqli_num_rows($res) > 0) {
        return mysqli_fetch_assoc($res);
    }

    // 3) jeśli nie istnieje -> dodaj
    $is_guest = ($user_id === null || $user_id <= 0) ? 1 : 0;
    $user_sql = ($user_id !== null && $user_id > 0) ? (string)$user_id : "NULL";

    $sql_ins = "
        INSERT INTO hangman_players
            (game_id, user_id, is_guest, nickname, score, is_creator)
        VALUES
            ($game_id, $user_sql, $is_guest, '$nickname_esc', 0, 0)
    ";
    $ok = mysqli_query($conn, $sql_ins);
    if (!$ok) {
        return null;
    }

    $pid = (int)mysqli_insert_id($conn);
    $res2 = mysqli_query($conn,
        "SELECT * FROM hangman_players WHERE id = $pid"
    );
    if ($res2 && mysqli_num_rows($res2) > 0) {
        return mysqli_fetch_assoc($res2);
    }

    return null;
}

// -------------------------------------------
// Dane z POST
// -------------------------------------------
$game_id = (int)($_POST['game_id'] ?? 0);
$type    = $_POST['type'] ?? '';

if ($game_id <= 0) {
    hm_json_error("Brak ID gry.");
}
if (!in_array($type, ['letter', 'phrase'], true)) {
    hm_json_error("Nieprawidłowy typ ruchu.");
}

// Pobierz grę
$res_game = mysqli_query($conn,
    "SELECT *
     FROM hangman_games
     WHERE id = $game_id"
);
$game = $res_game ? mysqli_fetch_assoc($res_game) : null;

if (!$game) {
    hm_json_error("Nie znaleziono gry.");
}
if ($game['status'] !== 'playing') {
    hm_json_error("Gra nie jest w trakcie rozgrywki.");
}

$game_mode   = $game['mode'];
$max_errors  = (int)$game['max_errors'];
$errors_now  = (int)$game['errors_count'];
$phrase      = $game['phrase']; // oryginalne hasło

// Rozkoduj użyte litery
$used_letters = [];
if (!empty($game['used_letters'])) {
    $tmp = json_decode($game['used_letters'], true);
    if (is_array($tmp)) {
        $used_letters = $tmp;
    }
}

// Upewnij się, że mamy gracza
$player = hm_ensure_player($conn, $game_id);
if (!$player) {
    hm_json_error("Nie udało się zidentyfikować gracza.");
}
$player_id = (int)$player['id'];

// -------------------------------------------
// Kluczowa rzecz: prowadzący w trybie HOST nie może grać
// -------------------------------------------
if ($game_mode === 'host' && (int)$player['is_creator'] === 1) {
    hm_json_error("Prowadzący nie bierze udziału w zgadywaniu – może tylko obserwować.");
}

// -------------------------------------------
// W POJEDYNKU pilnujemy tury
// -------------------------------------------
$current_turn_pid = $game['current_turn_player_id'] ? (int)$game['current_turn_player_id'] : 0;
if ($game_mode === 'duel') {
    if ($current_turn_pid > 0 && $current_turn_pid !== $player_id) {
        hm_json_error("Teraz jest tura przeciwnika.");
    }
}

// -------------------------------------------
// Przygotowanie do oceny ruchu
// -------------------------------------------
$phrase_upper = mb_strtoupper($phrase, 'UTF-8');
$is_correct   = false;
$guess_value  = '';
$guess_type   = $type;

// Punkty
$score_delta = 0; // +1 za poprawną literę, +5 za poprawne hasło

// -------------------------------------------
// Obsługa litery
// -------------------------------------------
if ($type === 'letter') {
    $letter = $_POST['letter'] ?? '';
    $letter = trim($letter);
    if ($letter === '') {
        hm_json_error("Brak litery.");
    }

    $letter = mb_strtoupper(mb_substr($letter, 0, 1, 'UTF-8'), 'UTF-8');
    $guess_value = $letter;

    // czy już była
    if (in_array($letter, $used_letters, true)) {
        hm_json_error("Ta litera była już użyta.");
    }

    // Sprawdź, czy występuje w haśle
    $is_correct = (mb_strpos($phrase_upper, $letter, 0, 'UTF-8') !== false);

    if ($is_correct) {
        $used_letters[] = $letter;
        $score_delta = 1; // poprawna litera = 1 pkt
    } else {
        $errors_now++;
    }

// -------------------------------------------
// Obsługa całego hasła
// -------------------------------------------
} else {
    $phrase_guess = $_POST['phrase_guess'] ?? '';
    $phrase_guess = trim($phrase_guess);
    if ($phrase_guess === '') {
        hm_json_error("Brak hasła do sprawdzenia.");
    }

    $guess_value = $phrase_guess;
    $guess_upper = mb_strtoupper($phrase_guess, 'UTF-8');

    if ($guess_upper === $phrase_upper) {
        // Trafione hasło
        $is_correct   = true;
        $score_delta  = 5; // poprawne hasło = 5 pkt
    } else {
        // Pudło – za hasło można mocniej karać
        $errors_now += 2;
    }
}

// Zabezpieczenie przed przekroczeniem błędów
if ($errors_now < 0) $errors_now = 0;
if ($errors_now > $max_errors) $errors_now = $max_errors;

// -------------------------------------------
// Sprawdź, czy całość hasła jest już odgadnięta (po literach)
// -------------------------------------------
$all_revealed = false;
if ($is_correct && $type === 'letter') {
    $set = array_flip($used_letters);

    $all_revealed = true;
    $len = mb_strlen($phrase_upper, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($phrase_upper, $i, 1, 'UTF-8');
        // ignorujemy spacje i znaki nie-alfanumeryczne
        if (!preg_match('/[A-ZĄĆĘŁŃÓŚŻŹ0-9]/u', $ch)) {
            continue;
        }
        if (!isset($set[$ch])) {
            $all_revealed = false;
            break;
        }
    }
}

// -------------------------------------------
// Ustal, czy gra się kończy
// -------------------------------------------
$winner_player_id = $game['winner_player_id'] ? (int)$game['winner_player_id'] : 0;
$new_status       = $game['status'];

// 1. Przegrana – wisielec wygrywa (za dużo błędów)
if ($errors_now >= $max_errors && !$is_correct) {
    $new_status       = 'finished';
    $winner_player_id = 0; // nikt – wisielec

// 2. Zgadnięcie całego hasła (po literach)
} elseif ($all_revealed) {
    $new_status       = 'finished';
    $winner_player_id = $player_id;

// 3. Zgadnięcie całego hasła (wprost)
} elseif ($type === 'phrase' && $is_correct) {
    $new_status       = 'finished';
    $winner_player_id = $player_id;
}

// -------------------------------------------
// Aktualizacja punktów gracza
// -------------------------------------------
if ($score_delta !== 0) {
    $sql_score = "
        UPDATE hangman_players
        SET score = score + " . (int)$score_delta . "
        WHERE id = $player_id
    ";
    mysqli_query($conn, $sql_score);
}

// -------------------------------------------
// Zapis ruchu do hangman_guesses
// -------------------------------------------
$guess_type_esc = mysqli_real_escape_string($conn, $guess_type);
$guess_value_esc = mysqli_real_escape_string($conn, $guess_value);
$is_correct_int  = $is_correct ? 1 : 0;

$sql_guess = "
    INSERT INTO hangman_guesses
        (game_id, player_id, guess_type, guess, is_correct, created_at)
    VALUES
        ($game_id, $player_id, '$guess_type_esc', '$guess_value_esc', $is_correct_int, NOW())
";
mysqli_query($conn, $sql_guess);

// -------------------------------------------
// Ustal, kto ma turę w pojedynku
// -------------------------------------------
$new_turn_pid = $game['current_turn_player_id'] ? (int)$game['current_turn_player_id'] : 0;

if ($game_mode === 'duel' && $new_status === 'playing') {
    // Znajdź przeciwnika
    $opp_id = 0;
    $res_pl = mysqli_query($conn,
        "SELECT id FROM hangman_players
         WHERE game_id = $game_id"
    );
    if ($res_pl) {
        while ($row = mysqli_fetch_assoc($res_pl)) {
            $pid = (int)$row['id'];
            if ($pid !== $player_id) {
                $opp_id = $pid;
                break;
            }
        }
    }
    if ($opp_id > 0) {
        $new_turn_pid = $opp_id;
    }
} elseif ($game_mode !== 'duel') {
    // w innych trybach tury nie używamy
    $new_turn_pid = 0;
}

// -------------------------------------------
// Zapis zmian w hangman_games
// -------------------------------------------
$used_json = json_encode(array_values(array_unique($used_letters)));

$sql_update = "
    UPDATE hangman_games
    SET errors_count = $errors_now,
        used_letters = '" . mysqli_real_escape_string($conn, $used_json) . "',
        status       = '" . mysqli_real_escape_string($conn, $new_status) . "',
        winner_player_id = " . ($winner_player_id > 0 ? $winner_player_id : "NULL") . ",
        current_turn_player_id = " . ($new_turn_pid > 0 ? $new_turn_pid : "NULL") . "
    WHERE id = $game_id
";
mysqli_query($conn, $sql_update);

// -------------------------------------------
// Odpowiedź
// -------------------------------------------
echo json_encode([
    'success'          => true,
    'is_correct'       => $is_correct,
    'type'             => $type,
    'guess'            => $guess_value,
    'errors_now'       => $errors_now,
    'max_errors'       => $max_errors,
    'status'           => $new_status,
    'winner_player_id' => $winner_player_id,
]);
