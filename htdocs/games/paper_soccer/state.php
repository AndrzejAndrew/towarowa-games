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
// POBRANIE GRY
// -----------------------------
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id=?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) {
    echo json_encode(["error" => "Nie znaleziono gry."]);
    exit;
}

// dla zalogowanych — nazwa z users
function username_from_id($id) {
    global $conn;
    if ($id === null || $id == 0) return null;

    $stmt = $conn->prepare("SELECT username FROM users WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? $row['username'] : null;
}

// docelowo bierzemy nazwę z DB — bo zapisujesz player*_name
$player1_name = $game['player1_name'] ?? null;
$player2_name = $game['player2_name'] ?? null;

if (!$player1_name) {
    $player1_name = username_from_id($game['player1_id']);
}
if (!$player2_name) {
    $player2_name = username_from_id($game['player2_id']);
}

if (!$player1_name) $player1_name = "Gość";
if (!$player2_name) $player2_name = "Gość";

// -----------------------------
// POBRANIE RUCHÓW GRY
// -----------------------------
$moves = [];
$stmt = $conn->prepare("SELECT * FROM paper_soccer_moves WHERE game_id=? ORDER BY move_no ASC, id ASC");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    // DB często zwraca liczby jako stringi — ujednolicamy typy
    foreach (["id","game_id","move_no","player","from_x","from_y","to_x","to_y"] as $k) {
        if (isset($row[$k])) $row[$k] = (int)$row[$k];
    }
    $moves[] = $row;
}
$stmt->close();

// -----------------------------
// ZWRACANY JSON
// -----------------------------
echo json_encode([
    "game" => [
        "status"         => $game["status"],
        "winner"         => (int)$game["winner"],
        "current_player" => (int)$game["current_player"],
        "player1_name"   => $player1_name,
        "player2_name"   => $player2_name
    ],
    "moves" => $moves
]);

exit;
