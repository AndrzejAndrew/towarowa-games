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
    echo json_encode(["error" => "Gra nie istnieje."]);
    exit;
}

// -----------------------------
// NAZWY GRACZY
// -----------------------------

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

// docelowo bierzemy nazwę z DB — bo zapisaliśmy ją przy tworzeniu gry
$player1_name = $game["player1_name"];
$player2_name = $game["player2_name"];

// jeśli gracz jest zalogowany, ale w DB nazwy nie ma (fallback)
if (!$player1_name) $player1_name = username_from_id($game["player1_id"]);
if (!$player2_name) $player2_name = username_from_id($game["player2_id"]);

// ostateczny fallback
if (!$player1_name) $player1_name = "Gość";
if (!$player2_name) $player2_name = "Gość";


// -----------------------------
// POBRANIE RUCHÓW GRY
// -----------------------------
$moves = [];
$stmt = $conn->prepare("SELECT * FROM paper_soccer_moves WHERE game_id=? ORDER BY id ASC");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $moves[] = $row;
}
$stmt->close();

// -----------------------------
// ZWRACANY JSON
// -----------------------------
echo json_encode([
    "game" => [
        "status"         => $game["status"],
        "winner"         => $game["winner"],
        "current_player" => $game["current_player"],
        "player1_name"   => $player1_name,
        "player2_name"   => $player2_name
    ],
    "moves" => $moves
]);

exit;
