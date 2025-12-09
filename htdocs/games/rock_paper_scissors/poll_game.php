<?php
require_once __DIR__ . '/../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

$game_id = (int)($_GET['game'] ?? 0);
$slot    = (int)($_GET['slot'] ?? 0); // 1 lub 2

if ($game_id <= 0) { echo json_encode(['error'=>'invalid']); exit; }

$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g   = mysqli_fetch_assoc($res);
if (!$g) { echo json_encode(['error'=>'no_game']); exit; }

// podstawowe informacje
$current_round = (int)$g['current_round'];
$p1_score      = (int)$g['p1_score'];
$p2_score      = (int)$g['p2_score'];

$round_text = '';
$round_no   = null;

// ostatnia zakończona runda
$rres = mysqli_query($conn, "SELECT * FROM pkn_rounds WHERE game_id = $game_id ORDER BY round_no DESC LIMIT 1");
if ($row = mysqli_fetch_assoc($rres)) {
    $round_no = (int)$row['round_no'];
    $m1 = $row['p1_move'];
    $m2 = $row['p2_move'];
    $winner = (int)$row['winner']; // 0 remis, 1 p1, 2 p2

    $map = ['rock' => 'kamień', 'paper' => 'papier', 'scissors' => 'nożyce'];

    // perspektywa gracza
    if ($slot === 1) {
        $my  = $m1;
        $opp = $m2;
        $relWinner = $winner;           // 1 = ja, 2 = przeciwnik
    } elseif ($slot === 2) {
        $my  = $m2;
        $opp = $m1;
        if     ($winner === 1) $relWinner = 2;
        elseif ($winner === 2) $relWinner = 1;
        else                   $relWinner = 0;
    } else {
        $my = $m1; $opp = $m2; $relWinner = $winner;
    }

    if (isset($map[$my]) && isset($map[$opp])) {
        if ($relWinner === 0) {
            $round_text = "Runda {$round_no}: zagrałeś {$map[$my]} – przeciwnik {$map[$opp]}: remis.";
        } elseif ($relWinner === 1) {
            $round_text = "Runda {$round_no}: zagrałeś {$map[$my]} – przeciwnik {$map[$opp]}: wygrywasz tę rundę!";
        } else {
            $round_text = "Runda {$round_no}: zagrałeś {$map[$my]} – przeciwnik {$map[$opp]}: przegrywasz tę rundę.";
        }
    }
}

$redirect = null;
if ($g['status'] === 'finished') {
    $redirect = 'result.php?game=' . $game_id;
}

echo json_encode([
    'current_round'      => $current_round,
    'p1_score'           => $p1_score,
    'p2_score'           => $p2_score,
    'round_result'       => $round_text !== '',
    'round_result_text'  => $round_text,
    'round_no'           => $round_no,
    'redirect'           => $redirect
]);
