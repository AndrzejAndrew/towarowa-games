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
function ps_user_exists(mysqli $conn, int $user_id): bool {
    if ($user_id <= 0) return false;
    $st = $conn->prepare("SELECT 1 FROM users WHERE id=? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

function ps_add_result(mysqli $conn, int $user_id, string $result): void {
    // Nie zapisujemy wyników dla guest_id
    if (!ps_user_exists($conn, $user_id)) return;

    // 1) per-game wynik (paper_soccer_stats)
    $st = $conn->prepare("INSERT INTO paper_soccer_stats (user_id, games_played, wins, draws, losses)
                          VALUES (?,1, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE
                            games_played = games_played + 1,
                            wins  = wins  + VALUES(wins),
                            draws = draws + VALUES(draws),
                            losses= losses+ VALUES(losses)");
    if ($st) {
        $win  = ($result === 'win') ? 1 : 0;
        $draw = ($result === 'draw') ? 1 : 0;
        $loss = ($result === 'loss') ? 1 : 0;
        $st->bind_param("iiii", $user_id, $win, $draw, $loss);
        $st->execute();
        $st->close();
    }

    // 2) globalny system XP/odznak
    $ok_global = stats_register_result($user_id, 'paper_soccer', $result);
    if ($ok_global === false) {
        ps_log("WARN stats_register_result failed user_id={$user_id}, result={$result}");
    }
}

function ps_update_ranking_for_finished_game(mysqli $conn, array $game, int $winner): void {
    // winner: 1 lub 2
    $p1 = (int)$game['player1_id'];
    $p2 = (int)$game['player2_id'];
    $mode = $game['mode'];

    if ($mode === 'bot') {
        // w trybie bot zapisujemy wynik tylko dla gracza 1 (user)
        if ($winner === 1) ps_add_result($conn, $p1, 'win');
        else ps_add_result($conn, $p1, 'loss');
        return;
    }

    // pvp: zapisujemy dla obu
    if ($winner === 1) {
        ps_add_result($conn, $p1, 'win');
        ps_add_result($conn, $p2, 'loss');
    } elseif ($winner === 2) {
        ps_add_result($conn, $p1, 'loss');
        ps_add_result($conn, $p2, 'win');
    }
}

// ----------------------------------------------------
// POBRANIE GRY
// ----------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id = ?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) {
    echo json_encode(["error" => "Gra nie istnieje"]);
    exit;
}

// ----------------------------------------------------
// WALIDACJA TUR
// ----------------------------------------------------
$current_player = (int)$game['current_player'];
$mode = $game['mode'];

$me_id = is_logged_in() ? (int)$_SESSION['user_id'] : (int)($_SESSION['guest_id'] ?? 0);

// w bot mode user zawsze gra jako 1
if ($mode === 'bot') {
    if ($current_player !== 1) {
        echo json_encode(["error" => "Nie twoja tura"]);
        exit;
    }
} else {
    // pvp
    if ($current_player === 1 && $me_id !== (int)$game['player1_id']) {
        echo json_encode(["error" => "Nie twoja tura"]);
        exit;
    }
    if ($current_player === 2 && $me_id !== (int)$game['player2_id']) {
        echo json_encode(["error" => "Nie twoja tura"]);
        exit;
    }
}

// ----------------------------------------------------
// ODCZYT STANU Z DB
// ----------------------------------------------------
$ball_x = (int)$game['ball_x'];
$ball_y = (int)$game['ball_y'];
$usedLines = json_decode($game['used_lines'] ?? '[]', true);
if (!is_array($usedLines)) $usedLines = [];

ps_log("DB ball=($ball_x,$ball_y) current_player=$current_player lines=" . count($usedLines));

// ----------------------------------------------------
// WALIDACJA RUCHU
// ----------------------------------------------------
if ($from_x !== $ball_x || $from_y !== $ball_y) {
    echo json_encode(["error" => "Zły punkt startowy"]);
    exit;
}

// backendowa walidacja – używamy funkcji z bot.php
if (!ps_is_valid_move_backend($from_x, $from_y, $to_x, $to_y, $usedLines)) {
    echo json_encode(["error" => "Niepoprawny ruch (backend)"]);
    exit;
}

// ----------------------------------------------------
// DOPISZ LINIĘ + AKTUALIZUJ PIŁKĘ
// ----------------------------------------------------
$usedLines[] = ['x1'=>$from_x,'y1'=>$from_y,'x2'=>$to_x,'y2'=>$to_y];

$ball_x = $to_x;
$ball_y = $to_y;

// ----------------------------------------------------
// ZMIANA GRACZA (jeśli nie ma extra)
// ----------------------------------------------------
$winner = 0;

if ($goal === 1) {
    $winner = ($ball_y === 0) ? 2 : 1; // jeśli piłka na górze: wygrał gracz 2, na dole: gracz 1
    $status = 'finished';
} elseif ($draw === 1) {
    $status = 'finished';
} else {
    $status = 'playing';
}

if ($status === 'playing') {
    if ($extra === 0) {
        $current_player = ($current_player === 1) ? 2 : 1;
    }
} else {
    // finished
    if ($winner !== 0) {
        ps_update_ranking_for_finished_game($conn, $game, $winner);
    }
}

// ----------------------------------------------------
// ZAPIS DO DB
// ----------------------------------------------------
$usedLinesJson = json_encode($usedLines);

$stmt = $conn->prepare("UPDATE paper_soccer_games
                        SET used_lines=?, ball_x=?, ball_y=?, current_player=?, status=?, winner=?
                        WHERE id=?");
$stmt->bind_param("siiiisi", $usedLinesJson, $ball_x, $ball_y, $current_player, $status, $winner, $game_id);
$stmt->execute();
$stmt->close();

// ----------------------------------------------------
// RUCH BOTA (jeśli bot i tura bota)
// ----------------------------------------------------
if ($mode === 'bot' && $status === 'playing') {
    // jeśli teraz jest tura bota
    if ($current_player === 2) {
        $difficulty = (int)($game['bot_difficulty'] ?? 1);
        $ball = ['x'=>$ball_x,'y'=>$ball_y];

        $botMove = bot_choose_move($ball, $usedLines, $difficulty);

        if ($botMove) {
            // bot robi ruch w DB (symulujemy jak powyżej)
            $from_x = $ball_x;
            $from_y = $ball_y;
            $to_x = (int)$botMove['x'];
            $to_y = (int)$botMove['y'];

            $usedLines[] = ['x1'=>$from_x,'y1'=>$from_y,'x2'=>$to_x,'y2'=>$to_y];
            $ball_x = $to_x;
            $ball_y = $to_y;

            // czy bot ma extra?
            $tmpLines = $usedLines;
            $hasExtra = ps_backend_has_bounce($ball_x, $ball_y, $tmpLines) ? 1 : 0;

            // gol?
            $botGoal = ($ball_y === 0 && $ball_x >= 3 && $ball_x <= 5);
            if ($botGoal) {
                $status = 'finished';
                $winner = 2;
                ps_update_ranking_for_finished_game($conn, $game, $winner);
            } else {
                if ($hasExtra === 0) {
                    $current_player = 1;
                } else {
                    $current_player = 2;
                }
            }

            $usedLinesJson = json_encode($usedLines);

            $stmt = $conn->prepare("UPDATE paper_soccer_games
                                    SET used_lines=?, ball_x=?, ball_y=?, current_player=?, status=?, winner=?
                                    WHERE id=?");
            $stmt->bind_param("siiiisi", $usedLinesJson, $ball_x, $ball_y, $current_player, $status, $winner, $game_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo json_encode([
    "ok" => true,
    "ball_x" => $ball_x,
    "ball_y" => $ball_y,
    "current_player" => $current_player,
    "status" => $status,
    "winner" => $winner
]);