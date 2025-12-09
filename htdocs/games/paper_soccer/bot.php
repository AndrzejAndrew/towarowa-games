<?php

// ================================================
// BOT — 3 poziomy trudności
// ================================================

// ------------------------------------------------
// 1) Sprawdza, czy dana linia już istnieje
// ------------------------------------------------
function ps_is_line_used(array $usedLines, int $x1, int $y1, int $x2, int $y2): bool
{
    foreach ($usedLines as $L) {
        if (
            ($L['x1'] === $x1 && $L['y1'] === $y1 && $L['x2'] === $x2 && $L['y2'] === $y2) ||
            ($L['x1'] === $x2 && $L['y1'] === $y2 && $L['x2'] === $x1 && $L['y2'] === $y1)
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
// Musi odzwierciedlać hasBounce() z JS
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
        if (($L['x1'] == $x && $L['y1'] == $y) || ($L['x2'] == $x && $L['y2'] == $y)) {
            $deg++;
            if ($deg >= 2) return true;
        }
    }

    return false;
}

// ------------------------------------------------
// 4) Bot wybiera ruch — z filtrowaniem nielegalnych ruchów
// ------------------------------------------------
function bot_choose_move(array $ball, array $usedLines, int $difficulty = 1): ?array
{
    $cols = 9;
    $rows = 13;

    $x = $ball['x'];
    $y = $ball['y'];

    $moves = [];

    // ZBIERAMY WSZYSTKIE POPRAWNE RUCHY (backendowo)
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

    if (empty($moves)) return null;

    // Poziom 1 — losowy ruch
    if ($difficulty <= 1) {
        return $moves[array_rand($moves)];
    }

    // Funkcja oceny ruchu
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
        if ($m['y'] == 0 && $m['x'] >= 3 && $m['x'] <= 5) {
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
        if (ps_backend_has_bounce($m['x'], $m['y'], $usedLines)) {
            $bounceMoves[] = $m;
        }
    }
    if ($bounceMoves) {
        usort($bounceMoves, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
        return $bounceMoves[0];
    }

    // D) normalna heurystyka
    usort($moves, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
    return $moves[0];
}

