<?php

// ================================================
// BOT — 4 poziomy trudności (1..4)
// ================================================

// ------------------------------------------------
// 1) Sprawdza, czy dana linia już istnieje
//    Uwaga: wartości z DB często wracają jako stringi,
//    więc rzutujemy na int, żeby porównania były poprawne.
// ------------------------------------------------
function ps_is_line_used(array $usedLines, int $x1, int $y1, int $x2, int $y2): bool
{
    foreach ($usedLines as $L) {
        $lx1 = (int)($L['x1'] ?? 0);
        $ly1 = (int)($L['y1'] ?? 0);
        $lx2 = (int)($L['x2'] ?? 0);
        $ly2 = (int)($L['y2'] ?? 0);

        if (
            ($lx1 === $x1 && $ly1 === $y1 && $lx2 === $x2 && $ly2 === $y2) ||
            ($lx1 === $x2 && $ly1 === $y2 && $lx2 === $x1 && $ly2 === $y1)
        ) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------
// 2) BACKENDOWY odpowiednik isValidMove() z JS
// ------------------------------------------------
function ps_is_valid_move_backend(int $fromX, int $fromY, int $toX, int $toY, array $usedLines): bool
{
    $cols = 9;
    $rows = 13;

    // 1) poza planszą
    if ($toX < 0 || $toX >= $cols || $toY < 0 || $toY >= $rows) return false;

    // 2) musi być sąsiad
    $dx = abs($toX - $fromX);
    $dy = abs($toY - $fromY);
    if ($dx > 1 || $dy > 1 || ($dx === 0 && $dy === 0)) return false;

    // 3) linia już była
    if (ps_is_line_used($usedLines, $fromX, $fromY, $toX, $toY)) return false;

    // 4) ZAKAZ jazdy wzdłuż ściany — identycznie jak w JS
    $leftSlide   = ($fromX == 0       && $toX == 0       && $fromY != $toY);
    $rightSlide  = ($fromX == $cols-1 && $toX == $cols-1 && $fromY != $toY);
    $topSlide    = ($fromY == 0       && $toY == 0       && $fromX != $toX);
    $bottomSlide = ($fromY == $rows-1 && $toY == $rows-1 && $fromX != $toX);

    if ($leftSlide || $rightSlide || $topSlide || $bottomSlide) return false;

    return true;
}

// ------------------------------------------------
// 3) Czy punkt daje odbicie? (backend)
//    Musi odzwierciedlać hasBounce() z JS
// ------------------------------------------------
function ps_backend_has_bounce(int $x, int $y, array $usedLines): bool
{
    $cols = 9;
    $rows = 13;

    // 1) odbicie od ściany (legalne)
    if ($x === 0 || $x === $cols - 1) return true;
    if ($y === 0 || $y === $rows - 1) return true;

    // 2) skrzyżowania (degree >= 2)
    $deg = 0;
    foreach ($usedLines as $L) {
        $lx1 = (int)($L['x1'] ?? 0);
        $ly1 = (int)($L['y1'] ?? 0);
        $lx2 = (int)($L['x2'] ?? 0);
        $ly2 = (int)($L['y2'] ?? 0);

        if (($lx1 == $x && $ly1 == $y) || ($lx2 == $x && $ly2 == $y)) {
            $deg++;
            if ($deg >= 2) return true;
        }
    }

    return false;
}

// ------------------------------------------------
// 3.1) Lista dozwolonych ruchów z (x,y)
// ------------------------------------------------
function ps_list_valid_moves(int $x, int $y, array $usedLines): array
{
    $moves = [];

    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            if ($dx === 0 && $dy === 0) continue;

            $nx = $x + $dx;
            $ny = $y + $dy;

            if (!ps_is_valid_move_backend($x, $y, $nx, $ny, $usedLines)) {
                continue;
            }

            $moves[] = ['x' => $nx, 'y' => $ny];
        }
    }

    return $moves;
}

// ------------------------------------------------
// 3.2) Bramka dla konkretnego gracza
//    P1 atakuje dół (y=12), P2 (bot) atakuje górę (y=0)
// ------------------------------------------------
function ps_is_goal_for_player(int $x, int $y, int $player): bool
{
    if ($player === 2) {
        return ($y === 0 && $x >= 3 && $x <= 5);
    }
    return ($y === 12 && $x >= 3 && $x <= 5);
}

// ------------------------------------------------
// 3.3) Heurystyka (dla minimax)
// ------------------------------------------------
function ps_eval_position(int $x, int $y, array $usedLines, int $playerToMove): int
{
    // baza: im bliżej bramki bota (y=0), tym lepiej
    // zakres: y=0 -> +240, y=12 -> -240
    $score = (12 - 2 * $y) * 20;

    // centrum lepsze niż boki
    $score -= abs($x - 4) * 4;

    // preferencja „do przodu” zależna od tego, kto jest na ruchu
    if ($playerToMove === 2) {
        $score += (12 - $y) * 2;
    } else {
        $score -= $y * 2;
    }

    // lekki bonus/karą za potencjalne odbicia w kolejnym ruchu
    $moves = ps_list_valid_moves($x, $y, $usedLines);
    $bounceNext = 0;
    foreach ($moves as $m) {
        $tmp = $usedLines;
        $tmp[] = ['x1' => $x, 'y1' => $y, 'x2' => $m['x'], 'y2' => $m['y']];
        if (ps_backend_has_bounce($m['x'], $m['y'], $tmp)) {
            $bounceNext++;
        }
    }

    if ($playerToMove === 2) $score += $bounceNext * 6;
    else $score -= $bounceNext * 6;

    return $score;
}

// ------------------------------------------------
// 3.4) Minimax (alpha-beta) — do poziomu „Ekspert”
// ------------------------------------------------
function ps_minimax(array $ball, array $usedLines, int $playerToMove, int $depth, int $alpha, int $beta): int
{
    $x = (int)$ball['x'];
    $y = (int)$ball['y'];

    // terminale
    if (ps_is_goal_for_player($x, $y, 2)) return 100000;
    if (ps_is_goal_for_player($x, $y, 1)) return -100000;

    $moves = ps_list_valid_moves($x, $y, $usedLines);
    if (empty($moves)) {
        // brak ruchu => przegrywa gracz, który ma turę
        return ($playerToMove === 2) ? -90000 : 90000;
    }

    if ($depth <= 0) {
        return ps_eval_position($x, $y, $usedLines, $playerToMove);
    }

    if ($playerToMove === 2) {
        // bot maksymalizuje
        $best = -1000000;
        foreach ($moves as $m) {
            $newLines = $usedLines;
            $newLines[] = ['x1' => $x, 'y1' => $y, 'x2' => $m['x'], 'y2' => $m['y']];
            $newBall = ['x' => $m['x'], 'y' => $m['y']];

            if (ps_is_goal_for_player($newBall['x'], $newBall['y'], 2)) {
                return 100000;
            }

            $extra = ps_backend_has_bounce($newBall['x'], $newBall['y'], $newLines);
            $nextPlayer = $extra ? 2 : 1;

            $val = ps_minimax($newBall, $newLines, $nextPlayer, $depth - 1, $alpha, $beta);
            if ($extra) $val += 40; // preferuj utrzymanie tury

            if ($val > $best) $best = $val;
            if ($best > $alpha) $alpha = $best;
            if ($beta <= $alpha) break;
        }
        return $best;
    }

    // gracz 1 minimalizuje
    $best = 1000000;
    foreach ($moves as $m) {
        $newLines = $usedLines;
        $newLines[] = ['x1' => $x, 'y1' => $y, 'x2' => $m['x'], 'y2' => $m['y']];
        $newBall = ['x' => $m['x'], 'y' => $m['y']];

        if (ps_is_goal_for_player($newBall['x'], $newBall['y'], 1)) {
            return -100000;
        }

        $extra = ps_backend_has_bounce($newBall['x'], $newBall['y'], $newLines);
        $nextPlayer = $extra ? 1 : 2;

        $val = ps_minimax($newBall, $newLines, $nextPlayer, $depth - 1, $alpha, $beta);
        if ($extra) $val -= 40; // dla bota to gorsze, bo gracz trzyma turę

        if ($val < $best) $best = $val;
        if ($best < $beta) $beta = $best;
        if ($beta <= $alpha) break;
    }

    return $best;
}

// ------------------------------------------------
// 4) Bot wybiera ruch — z filtrowaniem nielegalnych ruchów
// ------------------------------------------------
function bot_choose_move(array $ball, array $usedLines, int $difficulty = 1): ?array
{
    $x = (int)$ball['x'];
    $y = (int)$ball['y'];

    $moves = ps_list_valid_moves($x, $y, $usedLines);
    if (empty($moves)) return null;

    // Poziom 1 — losowy ruch
    if ($difficulty <= 1) {
        return $moves[array_rand($moves)];
    }

    // Funkcja oceny ruchu (heurystyka, bez lookahead)
    $scoreFn = function ($m) use ($x, $y) {
        $score = 0;

        // preferencja w górę (atak bota)
        if ($m['y'] < $y) $score += 15;
        elseif ($m['y'] > $y) $score -= 8;

        // im bliżej y=0, tym lepiej
        $score += (12 - $m['y']);

        // centrum lepsze niż rogi
        $score -= abs($m['x'] - 4);

        // unikanie skrajnych słupków
        if ($m['x'] == 0 || $m['x'] == 8) $score -= 3;

        return $score;
    };

    // POZIOM 2 (normalny)
    if ($difficulty == 2) {
        usort($moves, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
        return $moves[0];
    }

    // POZIOM 3 (trudny)
    // A) gol
    foreach ($moves as $m) {
        if (ps_is_goal_for_player($m['x'], $m['y'], 2)) {
            return $m;
        }
    }

    // B) strefa ataku
    $attacks = [];
    foreach ($moves as $m) {
        if ($m['y'] <= 2 && $m['x'] >= 2 && $m['x'] <= 6) $attacks[] = $m;
    }
    if ($attacks) {
        usort($attacks, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
        return $attacks[0];
    }

    // C) ruchy, które dają odbicie
    $bounceMoves = [];
    foreach ($moves as $m) {
        $tmp = $usedLines;
        $tmp[] = ['x1' => $x, 'y1' => $y, 'x2' => $m['x'], 'y2' => $m['y']];
        if (ps_backend_has_bounce($m['x'], $m['y'], $tmp)) {
            $bounceMoves[] = $m;
        }
    }
    if ($bounceMoves) {
        usort($bounceMoves, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
        return $bounceMoves[0];
    }

    // D) normalna heurystyka
    if ($difficulty == 3) {
        usort($moves, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
        return $moves[0];
    }

    // POZIOM 4 (Ekspert)
    // Minimax (alpha-beta) z uwzględnieniem odbić (utrzymania tury)
    $depth = 6; // kompromis: zauważalnie mocniejszy, nadal szybki

    $bestMove = $moves[0];
    $bestVal  = -1000000;

    foreach ($moves as $m) {
        $newLines = $usedLines;
        $newLines[] = ['x1' => $x, 'y1' => $y, 'x2' => $m['x'], 'y2' => $m['y']];
        $newBall = ['x' => $m['x'], 'y' => $m['y']];

        // natychmiastowy gol
        if (ps_is_goal_for_player($newBall['x'], $newBall['y'], 2)) {
            return $m;
        }

        $extra = ps_backend_has_bounce($newBall['x'], $newBall['y'], $newLines);
        $nextPlayer = $extra ? 2 : 1;

        $val = ps_minimax($newBall, $newLines, $nextPlayer, $depth - 1, -1000000, 1000000);
        if ($extra) $val += 60;

        // tie-break: stara heurystyka
        $val += $scoreFn($m);

        if ($val > $bestVal) {
            $bestVal  = $val;
            $bestMove = $m;
        }
    }

    return $bestMove;
}
