<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header("Content-Type: application/json");

// -----------------------------
// PARAMETR
// -----------------------------
$game_id = (int)($_GET['game_id'] ?? 0);
if ($game_id <= 0) {
    echo json_encode(["error" => "Brak ID gry."]);
    exit;
}

// -----------------------------
// POBIERZ GRĘ
// -----------------------------
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id = ?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) {
    echo json_encode(["error" => "Gra nie istnieje."]);
    exit;
}

// -----------------------------
// NAZWY GRACZY
// -----------------------------
function username_from_id($id) {
    global $conn;
    $id = (int)$id;
    if ($id <= 0) return null;
    $st = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param("i", $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row['username'] ?? null;
}

$player1_name = $game["player1_name"] ?? null;
$player2_name = $game["player2_name"] ?? null;

if (!$player1_name) $player1_name = username_from_id($game["player1_id"]);
if (!$player2_name) $player2_name = username_from_id($game["player2_id"]);

if (!$player1_name) $player1_name = "Gość";
if (!$player2_name) $player2_name = "Gość";

// w trybie bot zawsze pokazujemy BOT jako gracza 2
if (($game['mode'] ?? '') === 'bot') {
    $player2_name = 'BOT';
}

// -----------------------------
// POBRANIE RUCHÓW GRY
// -----------------------------
$moves = [];
$stmt = $conn->prepare("SELECT * FROM paper_soccer_moves WHERE game_id=? ORDER BY move_no ASC, id ASC");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    foreach (["id","game_id","move_no","player","from_x","from_y","to_x","to_y"] as $k) {
        if (isset($row[$k])) $row[$k] = (int)$row[$k];
    }
    $moves[] = $row;
}

// -----------------------------
// used_lines
// -----------------------------
$usedLines = json_decode($game["used_lines"] ?? "[]", true);
if (!is_array($usedLines)) $usedLines = [];

// -----------------------------
// Odpowiedź
// -----------------------------
echo json_encode([
    "ok" => true,
    "game" => [
        "id" => (int)$game["id"],
        "code" => $game["code"],
        "mode" => $game["mode"],
        "status" => $game["status"],
        "current_player" => (int)$game["current_player"],
        "winner" => (int)($game["winner"] ?? 0),
        "ball_x" => (int)$game["ball_x"],
        "ball_y" => (int)$game["ball_y"],
        "used_lines" => $usedLines,
        "moves" => $moves,
        "player1_name" => $player1_name,
        "player2_name" => $player2_name,
        "bot_difficulty" => isset($game["bot_difficulty"]) ? (int)$game["bot_difficulty"] : null
    ]
]);
