<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ---------------------------
// Pomocnicze
// ---------------------------
function hm_json_error(string $msg) {
    echo json_encode([
        'success' => false,
        'error'   => $msg,
    ]);
    exit;
}

// ---------------------------
// Wejście
// ---------------------------
$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    hm_json_error("Brak ID gry.");
}

// ---------------------------
// Pobierz grę
// ---------------------------
$res_game = mysqli_query($conn,
    "SELECT id, code, phrase, category, hint,
            mode, difficulty,
            max_errors, errors_count,
            used_letters, status,
            winner_player_id,
            current_turn_player_id
     FROM hangman_games
     WHERE id = $game_id"
);
$game = $res_game ? mysqli_fetch_assoc($res_game) : null;

if (!$game) {
    hm_json_error("Nie znaleziono gry.");
}

// ---------------------------
// Użyte litery (JSON -> tablica)
// ---------------------------
$used_letters = [];
if (!empty($game['used_letters'])) {
    $tmp = json_decode($game['used_letters'], true);
    if (is_array($tmp)) {
        // normalizujemy do wielkich liter (na wszelki wypadek)
        foreach ($tmp as $ch) {
            $ch = trim((string)$ch);
            if ($ch !== '') {
                $used_letters[] = mb_strtoupper($ch, 'UTF-8');
            }
        }
        $used_letters = array_values(array_unique($used_letters));
    }
}

// ---------------------------
// Zmaskowane hasło
// ---------------------------
$phrase        = $game['phrase'] ?? '';
$phrase_upper  = mb_strtoupper($phrase, 'UTF-8');
$masked        = '';
$letters_set   = array_flip($used_letters);   // do szybkiego sprawdzania

$len = mb_strlen($phrase_upper, 'UTF-8');
for ($i = 0; $i < $len; $i++) {
    $ch  = mb_substr($phrase_upper, $i, 1, 'UTF-8');
    $raw = mb_substr($phrase,        $i, 1, 'UTF-8'); // oryginalny znak dla wyświetlenia

    // litery/cyfry muszą być odgadnięte
    if (preg_match('/[A-ZĄĆĘŁŃÓŚŻŹ0-9]/u', $ch)) {
        if (isset($letters_set[$ch])) {
            $masked .= $raw;        // pokazujemy oryginalny znak (z ogonkami)
        } else {
            $masked .= ' _ ';       // niewidoczna litera
        }
    } else {
        // spacje, przecinki itd. pokazujemy normalnie
        $masked .= $raw;
    }
}

// ---------------------------
// Lista graczy
// ---------------------------
$players = [];
$res_pl = mysqli_query($conn,
    "SELECT id, nickname, score, is_creator
     FROM hangman_players
     WHERE game_id = $game_id
     ORDER BY is_creator DESC, id ASC"
);
if ($res_pl) {
    while ($row = mysqli_fetch_assoc($res_pl)) {
        $row['id']         = (int)$row['id'];
        $row['score']      = (int)$row['score'];
        $row['is_creator'] = (int)$row['is_creator'];
        $players[] = $row;
    }
}

// ---------------------------
// Ostatni ruch (historyjka)
// ---------------------------
$last_guess = null;
$res_last = mysqli_query($conn,
    "SELECT g.id,
            g.guess_type,
            g.guess,
            g.is_correct,
            g.created_at,
            p.nickname
     FROM hangman_guesses g
     LEFT JOIN hangman_players p
            ON p.id = g.player_id
     WHERE g.game_id = $game_id
     ORDER BY g.id DESC
     LIMIT 1"
);
if ($res_last && mysqli_num_rows($res_last) > 0) {
    $lg = mysqli_fetch_assoc($res_last);
    $last_guess = [
        'type'       => $lg['guess_type'],
        'guess'      => $lg['guess'],
        'is_correct' => (int)$lg['is_correct'] === 1,
        'nickname'   => $lg['nickname'] ?? null,
        'created_at' => $lg['created_at'],
    ];
}

// ---------------------------
// Obecny gracz na turze (tylko duel)
// ---------------------------
$current_turn_player = null;
if ($game['mode'] === 'duel' && !empty($game['current_turn_player_id'])) {
    $ctp_id = (int)$game['current_turn_player_id'];
    $res_ctp = mysqli_query($conn,
        "SELECT id, nickname
         FROM hangman_players
         WHERE id = $ctp_id AND game_id = $game_id
         LIMIT 1"
    );
    if ($res_ctp && mysqli_num_rows($res_ctp) > 0) {
        $row = mysqli_fetch_assoc($res_ctp);
        $current_turn_player = [
            'id'       => (int)$row['id'],
            'nickname' => $row['nickname'],
        ];
    }
}

// ---------------------------
// Odpowiedź JSON
// ---------------------------
echo json_encode([
    'success'       => true,

    // surowe dane gry – przydatne w JS
    'game' => [
        'id'                 => (int)$game['id'],
        'mode'               => $game['mode'],
        'difficulty'         => $game['difficulty'],
        'status'             => $game['status'],
        'max_errors'         => (int)$game['max_errors'],
        'errors_count'       => (int)$game['errors_count'],
        'category'           => $game['category'],
        'hint'               => $game['hint'],
        'winner_player_id'   => $game['winner_player_id'] ? (int)$game['winner_player_id'] : null,
        'current_turn_player_id' => $game['current_turn_player_id']
            ? (int)$game['current_turn_player_id']
            : null,
    ],

    // do wyświetlenia
    'phrase_masked'      => $masked,
    'used_letters'       => $used_letters,
    'players'            => $players,
    'last_guess'         => $last_guess,
    'current_turn_player'=> $current_turn_player,

    // ewentualna wiadomość
    'message'      => null,
    'message_type' => 'info',
]);
