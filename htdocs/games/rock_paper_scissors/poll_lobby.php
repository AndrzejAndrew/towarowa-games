<?php
require_once __DIR__ . '/../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');
$game_id = (int)($_GET['game'] ?? 0);
$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g = mysqli_fetch_assoc($res);
if (!$g) { echo json_encode(['error'=>'not_found']); exit; }

$redirect = null;
if ($g['status'] === 'playing') {
    $redirect = 'game.php?game=' . $game_id;
} elseif ($g['status'] === 'finished') {
    $redirect = 'result.php?game=' . $game_id;
}

echo json_encode([
    'status' => $g['status'],
    'player2_name' => $g['player2_name'] ?? null,
    'redirect' => $redirect
]);
