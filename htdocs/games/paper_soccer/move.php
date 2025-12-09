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
// INPUT
// ----------------------------------------------------
$game_id = (int)($_POST['game_id'] ?? 0);
$from_x  = (int)($_POST['from_x'] ?? -1);
$from_y  = (int)($_POST['from_y'] ?? -1);
$to_x    = (int)($_POST['to_x'] ?? -1);
$to_y    = (int)($_POST['to_y'] ?? -1);
$extra   = (int)($_POST['extra'] ?? 0);
$goal    = (int)($_POST['goal'] ?? 0);
$draw    = (int)($_POST['draw'] ?? 0);

ps_log("START POST=" . json_encode($_POST));

if ($game_id <= 0) {
    echo json_encode(["error" => "Brak ID gry"]);
    exit;
}

// ----------------------------------------------------
// Ranking utils (bez zmian)
// ----------------------------------------------------
function ps_add_result($conn, $user_id, $result) {
    if ($user_id <= 0) return;

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

    $st = $conn->prepare($sql);
    if (!$st) return;
    $st->bind_param("iiii", $user_id, $won, $lost, $drawn);
    $st->execute();
    $st->close();

    // 🔥 GLOBALNE STATYSTYKI PORTALU (XP + game_results)
    // game_code = 'paper_soccer', punkty = 0 (tu nie liczymy punktów)
    stats_register_result($user_id, 'paper_soccer', $result);
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
            ps_add_result($conn, $p2, "win");
            ps_add_result($conn, $p1, "loss");
        } else {
            ps_add_result($conn, $p1, "draw");
            ps_add_result($conn, $p2, "draw");
        }
    }
    else {
        if ($winner == 1) ps_add_result($conn, $p1, "win");
        elseif ($winner == 2) ps_add_result($conn, $p1, "loss");
        else ps_add_result($conn, $p1, "draw");
    }
}

// ----------------------------------------------------
// Load game
// ----------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id=?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) {
    echo json_encode(["error" => "Gra nie istnieje"]);
    exit;
}
if ($game['status'] === 'finished') {
    echo json_encode(["error" => "Gra zakończona"]);
    exit;
}

ps_log("GAME id={$game['id']}, current_player={$game['current_player']}");

$game_before = $game;

// ----------------------------------------------------
// Determine player number
// ----------------------------------------------------
$my_id = is_logged_in() ? ($_SESSION['user_id'] ?? 0) : ($_SESSION['guest_id'] ?? 0);

if ($my_id == $game['player1_id']) {
    $player = 1;
} elseif ($my_id == $game['player2_id']) {
    $player = 2;
} else {
    $player = (int)$game['current_player']; // fallback
}

ps_log("PLAYER=$player (my_id=$my_id)");

// ----------------------------------------------------
// BEFORE ACCEPTING MOVE: backend validation (!!)
// ----------------------------------------------------

$stmt = $conn->prepare("
    SELECT from_x, from_y, to_x, to_y
    FROM paper_soccer_moves 
    WHERE game_id=?
    ORDER BY move_no ASC
");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$usedLines = [];
$ball = ["x" => 4, "y" => 6];
while ($row = $res->fetch_assoc()) {
    $usedLines[] = [
        "x1" => $row["from_x"],
        "y1" => $row["from_y"],
        "x2" => $row["to_x"],
        "y2" => $row["to_y"]
    ];
    $ball = ["x" => $row["to_x"], "y" => $row["to_y"]];
}

// BACKEND WALIDACJA ruchu gracza
if (!ps_is_valid_move_backend($from_x, $from_y, $to_x, $to_y, $usedLines)) {
    ps_log("INVALID_PLAYER_MOVE BLOCKED from=($from_x,$from_y) to=($to_x,$to_y)");
    echo json_encode(["error" => "Nielegalny ruch"]);
    exit;
}

// ----------------------------------------------------
// Save player move
// ----------------------------------------------------
ps_log("PLAYER_MOVE ($from_x,$from_y)→($to_x,$to_y) extra=$extra");

$stmt = $conn->prepare("
    INSERT INTO paper_soccer_moves (game_id, move_no, player, from_x, from_y, to_x, to_y)
    SELECT ?, IFNULL(MAX(move_no),0)+1, ?, ?, ?, ?, ?
    FROM paper_soccer_moves WHERE game_id=?
");
$stmt->bind_param("iiiiiii",
    $game_id, $player,
    $from_x, $from_y, $to_x, $to_y,
    $game_id
);
$stmt->execute();
$stmt->close();


// ----------------------------------------------------
// Goal?
// ----------------------------------------------------
if ($goal > 0) {
    $winner = 0;

    if ($to_y == 12 && $to_x >= 3 && $to_x <= 5) $winner = 1;
    if ($to_y == 0  && $to_x >= 3 && $to_x <= 5) $winner = 2;

    $conn->query("UPDATE paper_soccer_games SET status='finished', winner=$winner, current_player=0 WHERE id=$game_id");
    ps_update_ranking_for_finished_game($conn, $game_before, $winner);

    ps_log("FINISHED BY GOAL winner=$winner");
    echo json_encode(["ok" => true, "finished" => "goal", "winner" => $winner]);
    exit;
}


// ----------------------------------------------------
// DRAW?
// ----------------------------------------------------
if ($draw > 0) {
    $conn->query("UPDATE paper_soccer_games SET status='finished', winner=0, current_player=0 WHERE id=$game_id");
    ps_update_ranking_for_finished_game($conn, $game_before, 0);

    echo json_encode(["ok" => true, "finished" => "draw"]);
    exit;
}


// ----------------------------------------------------
// TURN SWITCH
// ----------------------------------------------------
$next_player = ($extra == 1 ? $player : ($player == 1 ? 2 : 1));
$conn->query("UPDATE paper_soccer_games SET current_player=$next_player WHERE id=$game_id");


// ----------------------------------------------------
// BOT TURN?
// ----------------------------------------------------
if ($game['mode'] === 'bot' && $next_player == 2) {

    ps_log("BOT_TURN_START");

    // Reload used lines + ball (bot begins)
    $stmt = $conn->prepare("
        SELECT from_x, from_y, to_x, to_y
        FROM paper_soccer_moves 
        WHERE game_id=?
        ORDER BY move_no ASC
    ");
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $usedLines = [];
    $ball = ["x" => 4, "y" => 6];

    while ($row = $res->fetch_assoc()) {
        $usedLines[] = [
            "x1" => $row["from_x"],
            "y1" => $row["from_y"],
            "x2" => $row["to_x"],
            "y2" => $row["to_y"]
        ];
        $ball = ["x" => $row["to_x"], "y" => $row["to_y"]];
    }

    $difficulty = (int)$game['bot_difficulty'];
    $safety = 0;

    while (true) {
        $safety++;
        if ($safety > 50) {
            ps_log("BOT_LOOP_SAFETY_BREAK");
            break;
        }

        $botMove = bot_choose_move($ball, $usedLines, $difficulty);

        if ($botMove === null) {
            // bot nie ma ruchu → wygrał gracz 1
            $conn->query("UPDATE paper_soccer_games SET status='finished', winner=1, current_player=0 WHERE id=$game_id");
            ps_update_ranking_for_finished_game($conn, $game_before, 1);
            echo json_encode(["ok" => true, "finished" => "nomove", "winner" => 1]);
            exit;
        }

        // BACKENDOWA WALIDACJA RUCHU BOTA  
        if (!ps_is_valid_move_backend($ball['x'], $ball['y'], $botMove['x'], $botMove['y'], $usedLines)) {
            ps_log("BOT_ILLEGAL_MOVE BLOCKED");
            break;
        }

        // zapis ruchu
        $stmt = $conn->prepare("
            INSERT INTO paper_soccer_moves (game_id, move_no, player, from_x, from_y, to_x, to_y)
            SELECT ?, IFNULL(MAX(move_no),0)+1, 2, ?, ?, ?, ?
            FROM paper_soccer_moves WHERE game_id=?
        ");
        $stmt->bind_param("iiiiii",
            $game_id,
            $ball['x'], $ball['y'], 
            $botMove['x'], $botMove['y'],
            $game_id
        );
        $stmt->execute();
        $stmt->close();

        $usedLines[] = [
            "x1" => $ball['x'],
            "y1" => $ball['y'],
            "x2" => $botMove['x'],
            "y2" => $botMove['y']
        ];
        $ball = ["x" => $botMove['x'], "y" => $botMove['y']];

        // gol?
        if ($ball['y'] == 0 && $ball['x'] >= 3 && $ball['x'] <= 5) {
            $conn->query("UPDATE paper_soccer_games SET status='finished', winner=2, current_player=0 WHERE id=$game_id");
            ps_update_ranking_for_finished_game($conn, $game_before, 2);
            echo json_encode(["ok" => true, "bot" => "goal", "winner" => 2]);
            exit;
        }

        // bounce / extra
        $botExtra = ps_backend_has_bounce($ball['x'], $ball['y'], $usedLines);

        if (!$botExtra) {
            $conn->query("UPDATE paper_soccer_games SET current_player=1 WHERE id=$game_id");
            echo json_encode(["ok" => true, "bot" => "moved", "next" => 1]);
            exit;
        }
    }

    echo json_encode(["ok" => true, "bot" => "moved"]);
    exit;
}

// ----------------------------------------------------
// END (no bot)
// ----------------------------------------------------
echo json_encode(["ok" => true, "next" => $next_player]);
exit;

