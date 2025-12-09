<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/update_stats.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

$game_id = (int)($_POST['game_id'] ?? 0);
$move    = $_POST['move'] ?? '';
$slot    = (int)($_POST['slot'] ?? 0);

$allowed = ['rock','paper','scissors'];
if ($game_id <= 0 || !in_array($move, $allowed) || ($slot !== 1 && $slot !== 2)) {
    echo json_encode(['error' => 'invalid']); exit;
}

$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g   = mysqli_fetch_assoc($res);
if (!$g)                 { echo json_encode(['error'=>'no_game']); exit; }
if ($g['status'] !== 'playing') { echo json_encode(['error'=>'not_playing']); exit; }

$field = ($slot === 1) ? 'player1_move' : 'player2_move';

// zapamiętujemy ruch gracza
$q = sprintf(
    "UPDATE pkn_games SET %s = '%s' WHERE id = %d",
    $field,
    mysqli_real_escape_string($conn, $move),
    $game_id
);
mysqli_query($conn, $q);

// pobieramy stan po ruchu
$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g   = mysqli_fetch_assoc($res);

$m1 = $g['player1_move'];
$m2 = $g['player2_move'];

function outcome_pkn($a, $b) {
    if ($a === $b) return 0;
    if ($a === 'rock'     && $b === 'scissors') return 1;
    if ($a === 'paper'    && $b === 'rock')     return 1;
    if ($a === 'scissors' && $b === 'paper')    return 1;
    return 2;
}

// jeśli obaj zagrali – rozliczamy rundę
if ($m1 && $m2) {
    $winner_round = outcome_pkn($m1, $m2);

    $p1s = (int)$g['p1_score'];
    $p2s = (int)$g['p2_score'];
    if     ($winner_round === 1) $p1s++;
    elseif ($winner_round === 2) $p2s++;

    $round_no   = (int)$g['current_round'];
    $next_round = $round_no + 1;
    $finished   = ($next_round > (int)$g['rounds_total']);

    $status = $finished ? 'finished' : 'playing';

    // kto wygrał całą grę (1,2,0) – przydaje się do statystyk
    $winner_game = null;
    if ($finished) {
        if     ($p1s > $p2s) $winner_game = 1;
        elseif ($p2s > $p1s) $winner_game = 2;
        else                 $winner_game = 0;
    }

    // zapisujemy rundę do pkn_rounds
    $iq = sprintf(
        "INSERT INTO pkn_rounds (game_id, round_no, p1_move, p2_move, winner)
         VALUES (%d, %d, '%s', '%s', %d)",
        $game_id,
        $round_no,
        mysqli_real_escape_string($conn, $m1),
        mysqli_real_escape_string($conn, $m2),
        $winner_round
    );
    mysqli_query($conn, $iq);

    // aktualizujemy stan gry
    $uq = sprintf(
        "UPDATE pkn_games
         SET p1_score=%d, p2_score=%d,
             current_round=%d,
             player1_move=NULL, player2_move=NULL,
             status='%s',
             winner=%s
         WHERE id=%d",
        $p1s, $p2s,
        $next_round,
        $status,
        is_null($winner_game) ? 'NULL' : $winner_game,
        $game_id
    );
    mysqli_query($conn, $uq);

    if ($finished) {
        update_stats_after_game($g, $p1s, $p2s, $winner_game);
    }
}

echo json_encode(['ok' => true]);
