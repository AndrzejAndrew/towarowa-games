<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/ttt_boot.php';
require_once __DIR__ . '/../../includes/stats.php';   // ← DODANE

$code = $_POST['code'] ?? $_GET['code'] ?? '';
$pos  = isset($_POST['pos']) ? (int)$_POST['pos'] : (isset($_GET['pos']) ? (int)$_GET['pos'] : -1);

if ($pos < 0 || $pos > 8) {
    echo json_encode(['ok'=>false, 'error'=>'bad_pos']);
    exit;
}

$game = ttt_fetch_game_by_code($code, $conn);
if (!$game) {
    echo json_encode(['ok'=>false, 'error'=>'not_found']);
    exit;
}

if ($game['status'] !== 'playing') {
    echo json_encode(['ok'=>false, 'error'=>'not_playing']);
    exit;
}

$me    = ttt_current_player_id();   // aktualny user_id lub bot-user
$vsBot = !empty($game['vs_bot']);

$symbol = null;
if ((int)$game['player_x'] === $me) $symbol = 'X';
if (!$vsBot && (int)$game['player_o'] === $me) $symbol = 'O';

if (!$symbol) {
    echo json_encode(['ok'=>false, 'error'=>'not_a_player']);
    exit;
}

if ($symbol !== $game['turn']) {
    echo json_encode(['ok'=>false, 'error'=>'not_your_turn']);
    exit;
}

$board = str_split($game['board']);
if ($board[$pos] !== '_') {
    echo json_encode(['ok'=>false, 'error'=>'occupied']);
    exit;
}

$board[$pos] = $symbol;

$wins = [
    [0,1,2],[3,4,5],[6,7,8],
    [0,3,6],[1,4,7],[2,5,8],
    [0,4,8],[2,4,6]
];

$winner_id = null;
foreach ($wins as $w) {
    if ($board[$w[0]] === $symbol && $board[$w[1]] === $symbol && $board[$w[2]] === $symbol) {
        $winner_id = $me;
        break;
    }
}

$filled = true;
for ($i=0; $i<9; $i++) {
    if ($board[$i] === '_') { $filled = false; break; }
}

$esc_code = mysqli_real_escape_string($conn, $game['code']);


//
// GAŁĄŹ: gra z botem
//
if ($vsBot) {
    $newBoard = implode('', $board);

    // -------------------------------------------------------
    // 1) Gracz wygrał swoim ruchem
    // -------------------------------------------------------
    if ($winner_id) {
        mysqli_query($conn,
            "UPDATE ttt_games SET board='{$newBoard}', winner={$me}, status='finished' WHERE code='{$esc_code}'"
        );

        if (ttt_is_logged_user($me, $conn)) {
            // lokalne staty
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$me}, 1, 1)
                 ON DUPLICATE KEY UPDATE
                    games_played = games_played + 1,
                    games_won    = games_won + 1"
            );

            // globalne statystyki
            stats_register_result($me, 'ttt', 'win');
        }

        echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>$me]);
        exit;
    }

    // -------------------------------------------------------
    // 2) Remis po ruchu gracza
    // -------------------------------------------------------
    if ($filled) {
        mysqli_query($conn,
            "UPDATE ttt_games SET board='{$newBoard}', status='finished' WHERE code='{$esc_code}'"
        );

        if (ttt_is_logged_user($me, $conn)) {
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$me}, 1, 0)
                 ON DUPLICATE KEY UPDATE
                    games_played = games_played + 1"
            );

            stats_register_result($me, 'ttt', 'draw');
        }

        echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>null]);
        exit;
    }

    // -------------------------------------------------------
    // 3) Bot wykonuje ruch
    // -------------------------------------------------------
    $botSymbol = ($symbol === 'X') ? 'O' : 'X';

    // najprostszy bot: pierwszy wolny
    $botPos = null;
    for ($i=0; $i<9; $i++) {
        if ($board[$i] === '_') { $botPos = $i; break; }
    }

    if ($botPos === null) {
        // nietypowy remis
        mysqli_query($conn,
            "UPDATE ttt_games SET board='{$newBoard}', status='finished' WHERE code='{$esc_code}'"
        );

        if (ttt_is_logged_user($me, $conn)) {
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$me}, 1, 0)
                 ON DUPLICATE KEY UPDATE
                    games_played = games_played + 1"
            );

            stats_register_result($me, 'ttt', 'draw');
        }

        echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>null]);
        exit;
    }

    $board[$botPos] = $botSymbol;

    // bot wygrywa?
    $botWins = false;
    foreach ($wins as $w) {
        if ($board[$w[0]] === $botSymbol && $board[$w[1]] === $botSymbol && $board[$w[2]] === $botSymbol) {
            $botWins = true;
            break;
        }
    }

    $filledAfterBot = true;
    for ($i=0; $i<9; $i++) {
        if ($board[$i] === '_') { $filledAfterBot = false; break; }
    }

    $newBoard = implode('', $board);

    // -------------------------------------------------------
    // BOT WYGRYWA (winner = 0 → bot)
    // -------------------------------------------------------
    if ($botWins) {
        mysqli_query($conn,
            "UPDATE ttt_games SET board='{$newBoard}', winner=0, status='finished' WHERE code='{$esc_code}'"
        );

        if (ttt_is_logged_user($me, $conn)) {
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$me}, 1, 0)
                 ON DUPLICATE KEY UPDATE
                    games_played = games_played + 1"
            );

            stats_register_result($me, 'ttt', 'loss');
        }

        echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>0]);
        exit;
    }

    // -------------------------------------------------------
    // Remis po ruchu bota
    // -------------------------------------------------------
    if ($filledAfterBot) {
        mysqli_query($conn,
            "UPDATE ttt_games SET board='{$newBoard}', status='finished' WHERE code='{$esc_code}'"
        );

        if (ttt_is_logged_user($me, $conn)) {
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$me}, 1, 0)
                 ON DUPLICATE KEY UPDATE
                    games_played = games_played + 1"
            );

            stats_register_result($me, 'ttt', 'draw');
        }

        echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>null]);
        exit;
    }

    // Gra trwa – kolej X
    mysqli_query($conn,
        "UPDATE ttt_games SET board='{$newBoard}', turn='X' WHERE code='{$esc_code}'"
    );
    echo json_encode(['ok'=>true, 'status'=>'playing', 'turn'=>'X']);
    exit;
}


// =========================================================
//              GAŁĄŹ PvP – 2 graczy
// =========================================================

$newBoard = implode('', $board);

$x_id = (int)$game['player_x'];
$o_id = (int)$game['player_o'];


//
// PvP: ktoś wygrał
//
if ($winner_id) {
    mysqli_query($conn,
        "UPDATE ttt_games SET board='{$newBoard}', winner={$winner_id}, status='finished' WHERE code='{$esc_code}'"
    );

    foreach ([$x_id, $o_id] as $uid) {
        if (ttt_is_logged_user($uid, $conn)) {
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$uid}, 1, " . ($uid==$winner_id?1:0) . ")
                 ON DUPLICATE KEY UPDATE
                   games_played = games_played + 1,
                   games_won    = games_won + " . ($uid==$winner_id?1:0)
            );
        }
    }

    // GLOBALNE statystyki
    if (ttt_is_logged_user($x_id, $conn)) {
        stats_register_result($x_id, 'ttt', $x_id==$winner_id ? 'win' : 'loss');
    }
    if (ttt_is_logged_user($o_id, $conn)) {
        stats_register_result($o_id, 'ttt', $o_id==$winner_id ? 'win' : 'loss');
    }

    echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>$winner_id]);
    exit;
}

//
// PvP: REMIS
//
if ($filled) {
    mysqli_query($conn,
        "UPDATE ttt_games SET board='{$newBoard}', status='finished' WHERE code='{$esc_code}'"
    );

    foreach ([$x_id, $o_id] as $uid) {
        if (ttt_is_logged_user($uid, $conn)) {
            mysqli_query($conn,
                "INSERT INTO ttt_stats (user_id, games_played, games_won)
                 VALUES ({$uid}, 1, 0)
                 ON DUPLICATE KEY UPDATE
                   games_played = games_played + 1"
            );

            stats_register_result($uid, 'ttt', 'draw');
        }
    }

    echo json_encode(['ok'=>true, 'status'=>'finished', 'winner'=>null]);
    exit;
}

//
// Normalna zmiana tury
//
$next = ($symbol === 'X') ? 'O' : 'X';
mysqli_query($conn,
    "UPDATE ttt_games SET board='{$newBoard}', turn='{$next}' WHERE code='{$esc_code}'"
);

echo json_encode(['ok'=>true, 'status'=>'playing', 'turn'=>$next]);
