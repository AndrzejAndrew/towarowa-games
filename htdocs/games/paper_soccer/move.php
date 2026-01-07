<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/bot.php';
require_once __DIR__ . '/../../includes/stats.php';

header("Content-Type: application/json");

// ----------------------------------------------------
// LOG FILE
// ----------------------------------------------------
$logFile = __DIR__ . '/ps_move_debug.log';

function ps_log($msg) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s ') . $msg . "\n", FILE_APPEND);
}

// ----------------------------------------------------
// STATS / RANKING (restored to prevent HTTP 500 on finish)
// ----------------------------------------------------
function ps_user_exists(mysqli $conn, int $user_id): bool {
    if ($user_id <= 0) return false;
    $st = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->store_result();
    $ok = ($st->num_rows > 0);
    $st->close();
    return $ok;
}

function ps_add_result($conn, $user_id, $result) {
    // Wyniki zapisujemy wyłącznie dla użytkowników zalogowanych (istniejących w tabeli users).
    // Gość ma w sesji losowe guest_id (6 cyfr), które nie jest rekordem w users.
    if ($user_id <= 0) return;
    if (!ps_user_exists($conn, (int)$user_id)) return;

    $won = $lost = $drawn = 0;
    if ($result === 'win')  $won = 1;
    if ($result === 'loss') $lost = 1;
    if ($result === 'draw') $drawn = 1;

    // Lokalny ranking Paper Soccer
    $sql = "
        INSERT INTO paper_soccer_stats (
            user_id, games_played, games_won, games_lost, games_drawn, last_played
        ) VALUES (?, 1, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            games_played = games_played + 1,
            games_won    = games_won + VALUES(games_won),
            games_lost   = games_lost + VALUES(games_lost),
            games_drawn  = games_drawn + VALUES(games_drawn),
            last_played  = NOW()
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iiii", $user_id, $won, $lost, $drawn);
        $stmt->execute();
        $stmt->close();
    }

    // Globalne wyniki + XP (wspólny mechanizm portalu)
    // Funkcja stats_register_result() jest w /includes/stats.php
    if (function_exists('stats_register_result')) {
        stats_register_result($user_id, 'paper_soccer', $result);
    }

    // Historia gier (dla profilu / ostatnich gier)
    // Jeżeli tabela nie istnieje lub brak uprawnień, to prepare() zwróci false i nic się nie stanie.
    $stmt2 = $conn->prepare("INSERT INTO game_results (user_id, game_code, result, played_at) VALUES (?, 'paper_soccer', ?, NOW())");
    if ($stmt2) {
        $stmt2->bind_param("is", $user_id, $result);
        $stmt2->execute();
        $stmt2->close();
    }
}

function ps_update_ranking_for_finished_game($conn, $game_before, $winner) {
    if ($game_before['status'] === 'finished') return;

    $p1 = (int)$game_before['player1_id'];
    $p2 = (int)$game_before['player2_id'];

    if ($game_before['mode'] === 'pvp') {
        if ($winner == 1) {
            ps_add_result($conn, $p1, "win");
            ps_add_result($conn, $p2, "loss");
        } elseif ($winner == 2) {
            ps_add_result($conn, $p1, "loss");
            ps_add_result($conn, $p2, "win");
        } else {
            ps_add_result($conn, $p1, "draw");
            ps_add_result($conn, $p2, "draw");
        }
    } else {
        // vs bot
        // Zapisujemy wynik tylko dla gracza (player1). Bot nie ma konta w users.
        if ($winner == 1) ps_add_result($conn, $p1, "win");
        elseif ($winner == 2) ps_add_result($conn, $p1, "loss");
        else ps_add_result($conn, $p1, "draw");
    }
}

// ---------------
// INPUT
// ---------------
$game_id = (int)($_POST['game_id'] ?? 0);
$from_x  = (int)($_POST['from_x'] ?? -1);
$from_y  = (int)($_POST['from_y'] ?? -1);
$to_x    = (int)($_POST['to_x'] ?? -1);
$to_y    = (int)($_POST['to_y'] ?? -1);
$extra   = (int)($_POST['extra'] ?? 0);
$goal    = (int)($_POST['goal'] ?? 0);
$draw    = (int)($_POST['draw'] ?? 0);

if ($game_id <= 0) {
    echo json_encode(["error" => "Brak game_id"]);
    exit;
}


// ----------------------------------------------------
// Get game
// ----------------------------------------------------
$stmt = 
$conn->prepare("SELECT * FROM paper_soccer_games WHERE id = ?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$res = $stmt->get_result();
$game = $res->fetch_assoc();
$stmt->close();

if (!$game) {
    echo json_encode(["error" => "Gra nie istnieje"]);
    exit;
}

// keep original state (for finish stats)
$game_before = $game;

$mode = $game['mode']; // 'bot' or 'pvp'
$status = $game['status'];

if ($status === 'finished') {
    echo json_encode([
        "ok" => true,
        "finished" => true,
        "winner" => (int)$game['winner']
    ]);
    exit;
}


// ----------------------------------------------------
// Validate player
// ----------------------------------------------------
$user_id = (int)($_SESSION['user_id'] ?? 0);
$guest_id = (int)($_SESSION['guest_id'] ?? 0);

$current_player = (int)$game['current_player']; // 1 or 2 or 0
$p1 = (int)$game['player1_id'];
$p2 = (int)$game['player2_id'];

if ($mode === 'pvp') {
    // In PVP: player1_id and player2_id
    if ($current_player === 1) {
        if ($user_id > 0) {
            if ($user_id !== $p1) {
                echo json_encode(["error" => "Nie Twoja tura"]);
                exit;
            }
        } else {
            if ($guest_id !== $p1) {
                echo json_encode(["error" => "Nie Twoja tura"]);
                exit;
            }
        }
    } elseif ($current_player === 2) {
        if ($user_id > 0) {
            if ($user_id !== $p2) {
                echo json_encode(["error" => "Nie Twoja tura"]);
                exit;
            }
        } else {
            if ($guest_id !== $p2) {
                echo json_encode(["error" => "Nie Twoja tura"]);
                exit;
            }
        }
    }
} else {
    // vs bot: only player1 is human
    if ($current_player !== 1) {
        echo json_encode(["error" => "Nie Twoja tura"]);
        exit;
    }
    if ($user_id > 0) {
        if ($user_id !== $p1) {
            echo json_encode(["error" => "Nie Twoja gra"]);
            exit;
        }
    } else {
        if ($guest_id !== $p1) {
            echo json_encode(["error" => "Nie Twoja gra"]);
            exit;
        }
    }
}


// ----------------------------------------------------
// Apply move
// ----------------------------------------------------
$board_json = $game['board_state'];
$board = json_decode($board_json, true);
if (!is_array($board)) $board = [];

$path_json = $game['path_state'];
$path = json_decode($path_json, true);
if (!is_array($path)) $path = [];

if ($from_x < 0 || $from_y < 0 || $to_x < 0 || $to_y < 0) {
    echo json_encode(["error" => "Błędne dane ruchu"]);
    exit;
}


// -------------------------------------
// Handle draw request
// -------------------------------------
if ($draw === 1) {
    $conn->query("UPDATE paper_soccer_games SET status='finished', winner=0, current_player=0 WHERE id=$game_id");
    ps_update_ranking_for_finished_game($conn, $game_before, 0);
    ps_log("FINISHED BY DRAW");
    echo json_encode(["ok" => true, "finished" => "draw", "winner" => 0]);
    exit;
}

// -------------------------------------
// Handle goal signal from client
// -------------------------------------
if ($goal === 1) {
    // Determine winner based on last move destination (to_y, to_x)
    // goal top (y==0, x=3..5) => player1 scores (winner=1)
    // goal bottom (y==12, x=3..5) => player2 scores (winner=2)
    $winner = 0;
    if ($to_y == 12 && $to_x >= 3 && $to_x <= 5) $winner = 1;
    if ($to_y == 0  && $to_x >= 3 && $to_x <= 5) $winner = 2;

    $conn->query("UPDATE paper_soccer_games SET status='finished', winner=$winner, current_player=0 WHERE id=$game_id");
    ps_update_ranking_for_finished_game($conn, $game_before, $winner);

    ps_log("FINISHED BY GOAL winner=$winner");
    echo json_encode(["ok" => true, "finished" => "goal", "winner" => $winner]);
    exit;
}


// -------------------------------------
// Validate move
// -------------------------------------
$key = $from_x . "," . $from_y;
if (!isset($path[$key])) $path[$key] = [];

$destKey = $to_x . "," . $to_y;
if (in_array($destKey, $path[$key], true)) {
    echo json_encode(["error" => "Ruch już wykonany"]);
    exit;
}

// Add edge both ways
$path[$key][] = $destKey;
if (!isset($path[$destKey])) $path[$destKey] = [];
$path[$destKey][] = $key;

// Update ball position
$board['ball'] = ["x" => $to_x, "y" => $to_y];

// Determine next player: if bounce then same, else switch
$bounce = (bool)$extra;
$next_player = $bounce ? $current_player : ($current_player === 1 ? 2 : 1);


// Save game state
$new_board_json = json_encode($board);
$new_path_json  = json_encode($path);

$stmt = $conn->prepare("UPDATE paper_soccer_games SET board_state = ?, path_state = ?, current_player = ? WHERE id = ?");
$stmt->bind_param("ssii", $new_board_json, $new_path_json, $next_player, $game_id);
$stmt->execute();
$stmt->close();

// Return move OK
ps_log("MOVE OK game=$game_id from=($from_x,$from_y) to=($to_x,$to_y) extra=$extra next=$next_player mode=$mode");

// -------------------------------------
// If mode=bot and next_player=2, do bot move
// -------------------------------------
if ($mode === 'bot' && $next_player === 2) {

    // reload current game state after player move
    $stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id = ?");
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $game2 = $res->fetch_assoc();
    $stmt->close();

    $board2 = json_decode($game2['board_state'], true);
    if (!is_array($board2)) $board2 = [];
    $path2 = json_decode($game2['path_state'], true);
    if (!is_array($path2)) $path2 = [];

    $difficulty = (int)$game2['difficulty'];

    $botMove = bot_choose_move($board2, $path2, $difficulty);

    if (!$botMove) {
        // no move => draw
        $conn->query("UPDATE paper_soccer_games SET status='finished', winner=0, current_player=0 WHERE id=$game_id");
        ps_update_ranking_for_finished_game($conn, $game_before, 0);
        ps_log("BOT NO MOVE -> DRAW");
        echo json_encode(["ok" => true, "finished" => "draw", "winner" => 0]);
        exit;
    }

    $bx1 = $botMove['from_x'];
    $by1 = $botMove['from_y'];
    $bx2 = $botMove['to_x'];
    $by2 = $botMove['to_y'];

    // apply bot move to path2
    $k1 = $bx1 . "," . $by1;
    $k2 = $bx2 . "," . $by2;
    if (!isset($path2[$k1])) $path2[$k1] = [];
    $path2[$k1][] = $k2;
    if (!isset($path2[$k2])) $path2[$k2] = [];
    $path2[$k2][] = $k1;

    // update ball
    $board2['ball'] = ["x" => $bx2, "y" => $by2];

    // did bot score?
    $botGoal = ($by2 == 12 && $bx2 >= 3 && $bx2 <= 5);

    if ($botGoal) {
        // bot scores => winner=2
        $new_board_json = json_encode($board2);
        $new_path_json  = json_encode($path2);

        $conn->query("UPDATE paper_soccer_games SET board_state='$new_board_json', path_state='$new_path_json', status='finished', winner=2, current_player=0 WHERE id=$game_id");
        ps_update_ranking_for_finished_game($conn, $game_before, 2);
        ps_log("BOT GOAL -> FINISHED winner=2");
        echo json_encode(["ok" => true, "finished" => "goal", "winner" => 2, "bot_move" => $botMove]);
        exit;
    }

    // switch to player 1 (no bounce support in bot move yet, treat as normal)
    $new_board_json = json_encode($board2);
    $new_path_json  = json_encode($path2);

    $stmt = $conn->prepare("UPDATE paper_soccer_games SET board_state = ?, path_state = ?, current_player = 1 WHERE id = ?");
    $stmt->bind_param("ssi", $new_board_json, $new_path_json, $game_id);
    $stmt->execute();
    $stmt->close();

    ps_log("BOT MOVE OK to=($bx2,$by2) -> player1 turn");

    echo json_encode(["ok" => true, "bot_move" => $botMove]);
    exit;
}

echo json_encode(["ok" => true]);
