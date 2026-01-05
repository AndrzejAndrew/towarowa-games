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
// UTIL: czy user istnieje
// ----------------------------------------------------
function ps_user_exists($conn, int $user_id): bool {
    $st = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
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
            user_id, games_played, games_won, games_lost, games_drawn
        ) VALUES (?, 1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            games_played = games_played + 1,
            games_won    = games_won + VALUES(games_won),
            games_lost   = games_lost + VALUES(games_lost),
            games_drawn  = games_drawn + VALUES(games_drawn)
    ";
    $st = $conn->prepare($sql);
    $st->bind_param("iiii", $user_id, $won, $lost, $drawn);
    $st->execute();
    $st->close();
}

function ps_update_ranking_for_finished_game($conn, array $game) {
    // aktualizujemy wyniki tylko gdy gra faktycznie finished
    if (($game['status'] ?? '') !== 'finished') return;

    $p1_id = (int)($game['player1_id'] ?? 0);
    $p2_id = (int)($game['player2_id'] ?? 0);
    $winner = (int)($game['winner'] ?? 0);

    if ($winner === 0) {
        ps_add_result($conn, $p1_id, 'draw');
        ps_add_result($conn, $p2_id, 'draw');
        return;
    }

    if ($winner === 1) {
        ps_add_result($conn, $p1_id, 'win');
        ps_add_result($conn, $p2_id, 'loss');
        return;
    }

    if ($winner === 2) {
        ps_add_result($conn, $p1_id, 'loss');
        ps_add_result($conn, $p2_id, 'win');
        return;
    }
}

// ----------------------------------------------------
// POBRANIE GRY
// ----------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id=? LIMIT 1");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) {
    echo json_encode(["error" => "Nie ma takiej gry"]);
    exit;
}

// ----------------------------------------------------
// AUTORYZACJA / WYLICZENIE MOJEGO PLAYERA (1/2)
// ----------------------------------------------------
$my_id = is_logged_in() ? (int)$_SESSION['user_id'] : (int)$_SESSION['guest_id'];

$myPlayer = 0;
if ((int)$game['player1_id'] === $my_id) $myPlayer = 1;
if ((int)$game['player2_id'] === $my_id) $myPlayer = 2;

// w bot-mode gracz 2 to BOT
if ($game['mode'] === 'bot') {
    $myPlayer = 1;
}

if ($myPlayer === 0) {
    echo json_encode(["error" => "Nie jesteś uczestnikiem tej gry"]);
    exit;
}

// ----------------------------------------------------
// BLOKADA: gra zakończona
// ----------------------------------------------------
if ($game['status'] === 'finished') {
    echo json_encode(["error" => "Gra jest już zakończona"]);
    exit;
}

// ----------------------------------------------------
// BLOKADA: nie Twoja tura (tylko pvp)
// ----------------------------------------------------
$current_player = (int)$game['current_player'];

if ($game['mode'] === 'pvp' && $current_player !== $myPlayer) {
    echo json_encode(["error" => "Nie Twoja tura"]);
    exit;
}

// ----------------------------------------------------
// ACCEPTING MOVE: backend validation (!!)
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
        "x1" => (int)$row["from_x"],
        "y1" => (int)$row["from_y"],
        "x2" => (int)$row["to_x"],
        "y2" => (int)$row["to_y"]
    ];
    $ball = ["x" => (int)$row["to_x"], "y" => (int)$row["to_y"]];
}

// BACKEND WALIDACJA ruchu gracza
if (!ps_is_valid_move_backend($from_x, $from_y, $to_x, $to_y, $usedLines)) {
    ps_log("INVALID_PLAYER_MOVE BLOCKED from=($from_x,$from_y) to=($to_x,$to_y)");
    echo json_encode(["error" => "Nielegalny ruch"]);
    exit;
}

// -------------------------------------------------
// ZAPIS RUCHU GRACZA
// -------------------------------------------------
$move_no = (int)$game['move_no'];

$stmt = $conn->prepare("
    INSERT INTO paper_soccer_moves (game_id, move_no, player, from_x, from_y, to_x, to_y, extra, goal, draw)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("iiiiiiiiii", $game_id, $move_no, $myPlayer, $from_x, $from_y, $to_x, $to_y, $extra, $goal, $draw);
$stmt->execute();
$stmt->close();

// aktualizacja piłki w backend (lokalnie)
$ball = ["x" => $to_x, "y" => $to_y];

// -------------------------------------------------
// SPRAWDZENIE GOLA / REMISU / ZMIANA TURY
// -------------------------------------------------
$winner = 0;
$status = $game['status'];

if ($goal === 1) {
    // gola wykrywa frontend, ale backend i tak potwierdza po pozycji
    // jeśli piłka w bramce górnej — wygrywa gracz 2, dolnej — gracz 1
    if ($ball['y'] == 0 && $ball['x'] >= 3 && $ball['x'] <= 5) $winner = 2;
    if ($ball['y'] == 12 && $ball['x'] >= 3 && $ball['x'] <= 5) $winner = 1;

    if ($winner > 0) {
        $status = "finished";
    }
}

// jeśli draw (z frontu) – oznacz zakończenie (fallback)
if ($draw === 1 && $status !== "finished") {
    $winner = 0;
    $status = "finished";
}

// tura: jeśli extra=1 to ten sam gracz, w przeciwnym razie drugi
if ($status !== "finished") {
    if ($extra === 0) {
        $current_player = ($current_player === 1) ? 2 : 1;
    }
}

// move_no +1
$move_no_next = $move_no + 1;

// zapis do games
$stmt = $conn->prepare("
    UPDATE paper_soccer_games
    SET current_player=?, winner=?, status=?, move_no=?
    WHERE id=?
");
$stmt->bind_param("iisis", $current_player, $winner, $status, $move_no_next, $game_id);
$stmt->execute();
$stmt->close();

// -------------------------------------------------
// BOT MOVE (jeśli tryb bot i tura bota)
// -------------------------------------------------
if ($status !== "finished" && $game['mode'] === 'bot' && $current_player === 2) {

    // odtwórz usedLines + ball jeszcze raz (pewnie, zgodnie z DB)
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
            "x1" => (int)$row["from_x"],
            "y1" => (int)$row["from_y"],
            "x2" => (int)$row["to_x"],
            "y2" => (int)$row["to_y"]
        ];
        $ball = ["x" => (int)$row["to_x"], "y" => (int)$row["to_y"]];
    }

    $difficulty = (int)$game['bot_difficulty'];
    $safety = 0;

    while ($current_player === 2 && $status !== "finished" && $safety < 12) {
        $safety++;

        $move = bot_choose_move($ball, $usedLines, $difficulty);
        if (!$move) {
            // bot nie ma ruchu => wygrywa gracz 1
            $winner = 1;
            $status = "finished";
            break;
        }

        $bx = (int)$ball['x'];
        $by = (int)$ball['y'];
        $nx = (int)$move['x'];
        $ny = (int)$move['y'];

        // walidacja bezpieczeństwa
        if (!ps_is_valid_move_backend($bx, $by, $nx, $ny, $usedLines)) {
            ps_log("BOT INVALID MOVE BLOCKED from=($bx,$by) to=($nx,$ny)");
            $winner = 1;
            $status = "finished";
            break;
        }

        // dopisz linię
        $usedLines[] = ["x1" => $bx, "y1" => $by, "x2" => $nx, "y2" => $ny];
        $ball = ["x" => $nx, "y" => $ny];

        $extraBot = ps_backend_has_bounce($nx, $ny, $usedLines) ? 1 : 0;

        // gol?
        if (($ny === 0 && $nx >= 3 && $nx <= 5) || ($ny === 12 && $nx >= 3 && $nx <= 5)) {
            $winner = ($ny === 0) ? 2 : 1;
            $status = "finished";
        }

        // zapisz ruch bota
        $stmt = $conn->prepare("
            INSERT INTO paper_soccer_moves (game_id, move_no, player, from_x, from_y, to_x, to_y, extra, goal, draw)
            VALUES (?, ?, 2, ?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->bind_param("iiiiiii", $game_id, $move_no_next, $bx, $by, $nx, $ny, $extraBot);
        $stmt->execute();
        $stmt->close();

        $move_no_next++;

        if ($status === "finished") {
            break;
        }

        // zmiana tury: jeśli extraBot=1, bot gra dalej, inaczej gracz 1
        if ($extraBot === 0) {
            $current_player = 1;
        }
    }

    // update games po ruchach bota
    $stmt = $conn->prepare("
        UPDATE paper_soccer_games
        SET current_player=?, winner=?, status=?, move_no=?
        WHERE id=?
    ");
    $stmt->bind_param("iisis", $current_player, $winner, $status, $move_no_next, $game_id);
    $stmt->execute();
    $stmt->close();
}

// -------------------------------------------------
// po zakończeniu gry – ranking
// -------------------------------------------------
if ($status === 'finished') {
    // dociągnij aktualny rekord gry (po bot-move itp.)
    $stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $finalGame = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($finalGame) {
        ps_update_ranking_for_finished_game($conn, $finalGame);
    }
}

// -------------------------------------------------
echo json_encode(["ok" => 1]);
exit;
