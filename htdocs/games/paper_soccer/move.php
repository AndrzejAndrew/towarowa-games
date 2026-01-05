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

if ($game_id <= 0) {
    echo json_encode(["error" => "Brak game_id"]);
    exit;
}


// ----------------------------------------------------
// Get game
// ----------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id=?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game_before = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game_before) {
    echo json_encode(["error" => "Gra nie istnieje."]);
    exit;
}

if ($game_before['status'] === 'finished') {
    echo json_encode(["error" => "Gra jest zakończona."]);
    exit;
}


// ----------------------------------------------------
// Identify current player
// ----------------------------------------------------
$my_id = is_logged_in() ? (int)$_SESSION['user_id'] : (int)$_SESSION['guest_id'];

$player = 0;
if ((int)$game_before['player1_id'] === $my_id) $player = 1;
if ((int)$game_before['player2_id'] === $my_id) $player = 2;

// bot mode: tylko gracz 1 wykonuje ruchy
if ($game_before['mode'] === 'bot') {
    $player = 1;
}

if ($player === 0) {
    echo json_encode(["error" => "Nie jesteś uczestnikiem tej gry."]);
    exit;
}

$current_player = (int)$game_before['current_player'];
if ($game_before['mode'] === 'pvp' && $current_player !== $player) {
    echo json_encode(["error" => "Nie Twoja tura."]);
    exit;
}


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
    // NOWE: wymuszenie intów
    $fx = (int)$row["from_x"];
    $fy = (int)$row["from_y"];
    $tx = (int)$row["to_x"];
    $ty = (int)$row["to_y"];

    $usedLines[] = ["x1" => $fx, "y1" => $fy, "x2" => $tx, "y2" => $ty];
    $ball = ["x" => $tx, "y" => $ty];
}

// BACKEND waliduje dokładnie ten ruch
if (!ps_is_valid_move_backend($from_x, $from_y, $to_x, $to_y, $usedLines)) {
    ps_log("ILLEGAL_MOVE from=($from_x,$from_y) to=($to_x,$to_y)");
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
$stmt->bind_param(
    "iiiiiii",
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
if ($game_before['mode'] === 'bot' && $next_player == 2) {

    // odtwórz stan na świeżo
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
        $fx = (int)$row["from_x"];
        $fy = (int)$row["from_y"];
        $tx = (int)$row["to_x"];
        $ty = (int)$row["to_y"];

        $usedLines[] = ["x1" => $fx, "y1" => $fy, "x2" => $tx, "y2" => $ty];
        $ball = ["x" => $tx, "y" => $ty];
    }

    $difficulty = (int)$game_before['bot_difficulty']; // 1..4 (w tym ekspert)
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

        $fromX = (int)$ball['x'];
        $fromY = (int)$ball['y'];
        $toX   = (int)$botMove['x'];
        $toY   = (int)$botMove['y'];

        // dopisz linię do lokalnego stanu
        $usedLines[] = ["x1" => $fromX, "y1" => $fromY, "x2" => $toX, "y2" => $toY];
        $ball = ["x" => $toX, "y" => $toY];

        // zapis ruchu bota (bez dodatkowych kolumn!)
        $stmt = $conn->prepare("
            INSERT INTO paper_soccer_moves (game_id, move_no, player, from_x, from_y, to_x, to_y)
            SELECT ?, IFNULL(MAX(move_no),0)+1, 2, ?, ?, ?, ?
            FROM paper_soccer_moves WHERE game_id=?
        ");
        $stmt->bind_param("iiiiii", $game_id, $fromX, $fromY, $toX, $toY, $game_id);
        $stmt->execute();
        $stmt->close();

        // gol?
        if ($toY == 12 && $toX >= 3 && $toX <= 5) {
            $conn->query("UPDATE paper_soccer_games SET status='finished', winner=1, current_player=0 WHERE id=$game_id");
            ps_update_ranking_for_finished_game($conn, $game_before, 1);
            echo json_encode(["ok" => true, "finished" => "goal", "winner" => 1]);
            exit;
        }
        if ($toY == 0 && $toX >= 3 && $toX <= 5) {
            $conn->query("UPDATE paper_soccer_games SET status='finished', winner=2, current_player=0 WHERE id=$game_id");
            ps_update_ranking_for_finished_game($conn, $game_before, 2);
            echo json_encode(["ok" => true, "finished" => "goal", "winner" => 2]);
            exit;
        }

        // czy bot ma extra (odbicie)? jeśli tak, gra dalej
        $extraBot = ps_backend_has_bounce($toX, $toY, $usedLines) ? 1 : 0;

        if ($extraBot == 1) {
            $conn->query("UPDATE paper_soccer_games SET current_player=2 WHERE id=$game_id");
            continue;
        }

        // koniec tury bota
        $conn->query("UPDATE paper_soccer_games SET current_player=1 WHERE id=$game_id");
        break;
    }
}

echo json_encode(["ok" => true]);
exit;
