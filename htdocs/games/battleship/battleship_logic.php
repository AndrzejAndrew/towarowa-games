<?php
// games/battleship/battleship_logic.php

if (!defined('BATTLESHIP_INCLUDED')) {
    define('BATTLESHIP_INCLUDED', true);
}

/**
 * Tworzy pustą planszę 10x10 wypełnioną zerami.
 */
function battleship_empty_board() {
    $board = [];
    for ($y = 0; $y < 10; $y++) {
        $row = [];
        for ($x = 0; $x < 10; $x++) {
            $row[] = 0;
        }
        $board[] = $row;
    }
    return $board;
}

/**
 * Flota: tablica długości okrętów.
 */
function battleship_fleet() {
    // 1x4, 2x3, 3x2, 4x1
    return [4, 3, 3, 2, 2, 2, 1, 1, 1, 1];
}

/**
 * Sprawdza, czy można postawić okręt o długości $size
 * na planszy $board w pozycji ($x, $y) w orientacji $dir ('H' lub 'V'),
 * zachowując przerwy (bez dotykania się statków).
 */
function battleship_can_place(&$board, $x, $y, $size, $dir) {
    for ($i = 0; $i < $size; $i++) {
        $cx = $x + ($dir === 'H' ? $i : 0);
        $cy = $y + ($dir === 'V' ? $i : 0);

        if ($cx < 0 || $cx > 9 || $cy < 0 || $cy > 9) {
            return false;
        }
        if ($board[$cy][$cx] != 0) {
            return false;
        }

        // sprawdzamy sąsiedztwo (8 kierunków + pole)
        for ($ny = $cy - 1; $ny <= $cy + 1; $ny++) {
            for ($nx = $cx - 1; $nx <= $cx + 1; $nx++) {
                if ($nx < 0 || $nx > 9 || $ny < 0 || $ny > 9) continue;
                if ($board[$ny][$nx] != 0) {
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * Losowe rozmieszczenie całej floty.
 * Zwraca stan:
 * [
 *   'board' => [10x10],
 *   'ships' => [ shipId => ['size' => X, 'hits' => 0] ],
 *   'remaining' => liczba statków
 * ]
 */
function battleship_generate_state() {
    $board = battleship_empty_board();
    $ships = [];
    $shipId = 1;

    foreach (battleship_fleet() as $size) {
        $placed = false;
        $try = 0;
        while (!$placed && $try < 1000) {
            $try++;
            $dir = (rand(0, 1) == 0) ? 'H' : 'V';
            $x = rand(0, 9);
            $y = rand(0, 9);

            if (!battleship_can_place($board, $x, $y, $size, $dir)) {
                continue;
            }

            for ($i = 0; $i < $size; $i++) {
                $cx = $x + ($dir === 'H' ? $i : 0);
                $cy = $y + ($dir === 'V' ? $i : 0);
                $board[$cy][$cx] = $shipId;
            }

            $ships[$shipId] = [
                'size' => $size,
                'hits' => 0
            ];
            $shipId++;
            $placed = true;
        }

        if (!$placed) {
            // awaryjnie – próbujemy od nowa
            return battleship_generate_state();
        }
    }

    return [
        'board' => $board,
        'ships' => $ships,
        'remaining' => count($ships)
    ];
}

/**
 * Strzał w planszę.
 * Zwraca:
 * [
 *   'result'   => 'miss'|'hit'|'sunk'|'already',
 *   'state'    => zaktualizowany stan,
 *   'finished' => bool (czy wszystkie statki zatopione)
 * ]
 *
 * UWAGA: jeśli statek zostanie zatopiony, wszystkie jego pola są oznaczane jako 'S'
 * (czarne na planszy).
 */
function battleship_shot($state, $x, $y) {
    $board     = $state['board'];
    $ships     = $state['ships'];
    $remaining = $state['remaining'];

    $cell = $board[$y][$x];

    // pola już ostrzelane: M (pudło), H (trafiony), S (zatopiony statek)
    if ($cell === 'M' || $cell === 'H' || $cell === 'S') {
        return [
            'result'   => 'already',
            'state'    => $state,
            'finished' => ($remaining == 0)
        ];
    }

    if ($cell === 0) {
        // pudło
        $board[$y][$x]   = 'M';
        $state['board']  = $board;
        return [
            'result'   => 'miss',
            'state'    => $state,
            'finished' => ($remaining == 0)
        ];
    }

    // trafiliśmy statek shipId
    $shipId        = $cell;
    $board[$y][$x] = 'H';
    $ships[$shipId]['hits']++;

    $state['board'] = $board;
    $state['ships'] = $ships;

    // czy zatopiony?
    if ($ships[$shipId]['hits'] >= $ships[$shipId]['size']) {
        // zmniejszamy licznik pozostałych statków
        $remaining--;
        $state['remaining'] = $remaining;

        // flood-fill: zaznaczamy wszystkie segmenty tego statku na 'S'
        $queue = [[$x, $y]];
        while (!empty($queue)) {
            [$cx, $cy] = array_pop($queue);
            if ($cx < 0 || $cx > 9 || $cy < 0 || $cy > 9) {
                continue;
            }
            $val = $board[$cy][$cx];

            // jeśli już S, albo to nie część tego statku, pomijamy
            if ($val === 'S' || ($val !== 'H' && $val !== $shipId)) {
                continue;
            }

            // ustawiamy pole jako zatopione
            $board[$cy][$cx] = 'S';

            // sąsiedzi w 4 kierunkach (bez przekątnych)
            $queue[] = [$cx + 1, $cy];
            $queue[] = [$cx - 1, $cy];
            $queue[] = [$cx, $cy + 1];
            $queue[] = [$cx, $cy - 1];
        }

        $state['board'] = $board;

        return [
            'result'   => 'sunk',
            'state'    => $state,
            'finished' => ($remaining == 0)
        ];
    }

    // tylko trafiony, jeszcze nie zatopiony
    return [
        'result'   => 'hit',
        'state'    => $state,
        'finished' => ($remaining == 0)
    ];
}


/**
 * Prosty ruch bota – zwraca [x, y].
 * easy   - losowe (może powtarzać),
 * normal - losowe bez powtórek,
 * hard   - na razie to samo co normal (później możemy rozbudować).
 */
function battleship_bot_move($difficulty, $enemyState) {
    $board = $enemyState['board'];

    if ($difficulty === 'easy') {
        return [rand(0, 9), rand(0, 9)];
    }

    // normal / hard – bez powtórek i bez pól zatopionych ('S')
    $candidates = [];
    for ($y = 0; $y < 10; $y++) {
        for ($x = 0; $x < 10; $x++) {
            if ($board[$y][$x] !== 'M' && $board[$y][$x] !== 'H' && $board[$y][$x] !== 'S') {
                $candidates[] = [$x, $y];
            }
        }
    }

    if (empty($candidates)) {
        return [rand(0, 9), rand(0, 9)];
    }

    return $candidates[rand(0, count($candidates) - 1)];
}

/**
 * Tworzy prostą listę statków (tylko size/hits) na potrzeby frontu.
 */
function battleship_ships_summary($state) {
    if (!$state || !isset($state['ships']) || !is_array($state['ships'])) {
        return [];
    }
    $out = [];
    foreach ($state['ships'] as $ship) {
        $out[] = [
            'size' => (int)$ship['size'],
            'hits' => (int)$ship['hits'],
        ];
    }
    return $out;
}
