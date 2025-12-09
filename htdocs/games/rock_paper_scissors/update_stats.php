<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/stats.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Upewnia się, że w tabeli pkn_stats istnieje wiersz dla danego usera.
 */
function ensure_pkn_stats_row(int $uid): void
{
    global $conn;
    if ($uid <= 0) {
        return;
    }

    $uid = (int)$uid;

    $res = mysqli_query(
        $conn,
        "SELECT user_id FROM pkn_stats WHERE user_id = {$uid} LIMIT 1"
    );
    if (!mysqli_fetch_assoc($res)) {
        mysqli_query(
            $conn,
            "INSERT INTO pkn_stats (user_id) VALUES ({$uid})"
        );
    }
}

/**
 * Aktualizacja statystyk po zakończonej grze PVP (pkn_games).
 *
 * Parametry:
 *  - $g           – pełny rekord z pkn_games (SELECT * ...)
 *  - $p1s, $p2s   – końcowe wyniki (punkty) graczy 1 i 2 (nie są tu konieczne,
 *                   ale zostawiamy w sygnaturze, bo submit_move.php je przekazuje)
 *  - $winner_game – 1 => wygrał player1, 2 => player2, null => remis
 */
function update_stats_after_game(array $g, int $p1s, int $p2s, $winner_game): void
{
    global $conn;

    // user_id graczy z tabeli pkn_games
    $p1 = isset($g['player1_id']) ? (int)$g['player1_id'] : 0;
    $p2 = isset($g['player2_id']) ? (int)$g['player2_id'] : 0;

    // w PVP obaj mogą być 0 (goście) – wtedy nic nie zapisujemy
    if ($p1 <= 0 && $p2 <= 0) {
        return;
    }

    // upewniamy się, że w pkn_stats istnieją wiersze
    if ($p1 > 0) {
        ensure_pkn_stats_row($p1);
    }
    if ($p2 > 0) {
        ensure_pkn_stats_row($p2);
    }

    // każdemu zaliczamy jedną rozegraną grę
    if ($p1 > 0) {
        mysqli_query(
            $conn,
            "UPDATE pkn_stats
             SET games_total = games_total + 1
             WHERE user_id = {$p1}"
        );
    }
    if ($p2 > 0) {
        mysqli_query(
            $conn,
            "UPDATE pkn_stats
             SET games_total = games_total + 1
             WHERE user_id = {$p2}"
        );
    }

    // wygrane / przegrane w tabeli pkn_stats
    if ($winner_game === 1) {
        // wygrał player1
        if ($p1 > 0) {
            mysqli_query(
                $conn,
                "UPDATE pkn_stats
                 SET games_won = games_won + 1
                 WHERE user_id = {$p1}"
            );
        }
        if ($p2 > 0) {
            mysqli_query(
                $conn,
                "UPDATE pkn_stats
                 SET games_lost = games_lost + 1
                 WHERE user_id = {$p2}"
            );
        }
    } elseif ($winner_game === 2) {
        // wygrał player2
        if ($p2 > 0) {
            mysqli_query(
                $conn,
                "UPDATE pkn_stats
                 SET games_won = games_won + 1
                 WHERE user_id = {$p2}"
            );
        }
        if ($p1 > 0) {
            mysqli_query(
                $conn,
                "UPDATE pkn_stats
                 SET games_lost = games_lost + 1
                 WHERE user_id = {$p1}"
            );
        }
    }
    // remis: tylko games_total, bez won/lost – już zrobione wyżej

    // ======================
    // GLOBALNE STATYSTYKI / XP
    // ======================
    //
    // game_key: 'pkn'
    // result:  'win' | 'loss' | 'draw'
    //
    if ($winner_game === 1) {
        if ($p1 > 0) {
            stats_register_result($p1, 'pkn', 'win');
        }
        if ($p2 > 0) {
            stats_register_result($p2, 'pkn', 'loss');
        }
    } elseif ($winner_game === 2) {
        if ($p2 > 0) {
            stats_register_result($p2, 'pkn', 'win');
        }
        if ($p1 > 0) {
            stats_register_result($p1, 'pkn', 'loss');
        }
    } else {
        // remis
        if ($p1 > 0) {
            stats_register_result($p1, 'pkn', 'draw');
        }
        if ($p2 > 0) {
            stats_register_result($p2, 'pkn', 'draw');
        }
    }
}
