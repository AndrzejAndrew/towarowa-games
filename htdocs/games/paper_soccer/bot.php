<?php

// ================================================
// BOT — 4 poziomy trudności
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
// 2) Walidacja ruchu (backend) – musi odzwierciedlać JS
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

    // 4) zakaz przekątnych w bramkach (jeśli masz takie zasady w JS)
    // (tu zostawiamy jak było – jeśli w JS nie masz zakazu, usuń)
    // ...

    // 5) zakaz wyjścia poza boisko / w narożnikach etc. (jak w JS)
    // (tu też zostawiamy jak było – w repo już masz logikę ścian i bramek)

    // Dodatkowa logika ścian/rogów (jak w JS)
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
// 4) Zbierz wszystkie poprawne ruchy (backend) dla danej pozycji piłki
// ------------------------------------------------
function ps_backend_get_moves(array $ball, array $usedLines): array
{
    $cols = 9;
    $rows = 13;

    $x = (int)($ball['x'] ?? 0);
    $y = (int)($ball['y'] ?? 0);

    $moves = [];

    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            if ($dx === 0 && $dy === 0) continue;

            $nx = $x + $dx;
            $ny = $y + $dy;

            if (!ps_is_valid_move_backend($x, $y, $nx, $ny, $usedLines)) continue;

            $moves[] = [
                'from_x' => $x, 'from_y' => $y,
                'x' => $nx, 'y' => $ny
            ];
        }
    }

    return $moves;
}

// ------------------------------------------------
// 5) Symulacja ruchu: zwraca [newBall, newLines, bounce, goalForBot, goalForOpp]
// Bot atakuje do góry (y=0), przeciwnik do dołu (y=12)
// ------------------------------------------------
function ps_backend_apply_move(array $ball, array $usedLines, array $move): array
{
    $fromX = (int)$move['from_x'];
    $fromY = (int)$move['from_y'];
    $toX   = (int)$move['x'];
    $toY   = (int)$move['y'];

    $newLines = $usedLines;
    $newLines[] = ['x1' => $fromX, 'y1' => $fromY, 'x2' => $toX, 'y2' => $toY];

    $newBall = ['x' => $toX, 'y' => $toY];

    // UWAGA: odbicie liczymy na podstawie linii PO dodaniu aktualnej linii
    $bounce = ps_backend_has_bounce($toX, $toY, $newLines);

    $goalForBot = ($toY === 0  && $toX >= 3 && $toX <= 5);
    $goalForOpp = ($toY === 12 && $toX >= 3 && $toX <= 5);

    return [$newBall, $newLines, $bounce, $goalForBot, $goalForOpp];
}

// ------------------------------------------------
// 6) Funkcja oceny pozycji dla bota (im większa tym lepiej)
// ------------------------------------------------
function ps_backend_eval(array $ball): int
{
    $x = (int)$ball['x'];
    $y = (int)$ball['y'];

    // Bot atakuje w górę -> niższe y = lepiej
    $score = 0;
    $score += (12 - $y) * 20;      // progres do bramki
    $score -= abs($x - 4) * 6;     // preferuj środek

    // delikatna kara za bycie tuż przy ścianie (większe ryzyko zablokowania)
    if ($x === 0 || $x === 8) $score -= 10;

    return $score;
}

// ------------------------------------------------
// 7) Minimax z ograniczeniem gałęzi (expert)
// ------------------------------------------------
function ps_backend_minimax(array $ball, array $usedLines, int $depth, bool $botTurn): int
{
    // terminal: ktoś strzelił gola
    $x = (int)$ball['x'];
    $y = (int)$ball['y'];

    if ($y === 0  && $x >= 3 && $x <= 5)  return  100000; // gol bota
    if ($y === 12 && $x >= 3 && $x <= 5)  return -100000; // gol przeciwnika

    if ($depth <= 0) {
        return ps_backend_eval($ball);
    }

    $moves = ps_backend_get_moves($ball, $usedLines);

    // brak ruchów => przegrywa gracz, który ma turę
    if (!$moves) {
        return $botTurn ? -90000 : 90000;
    }

    // sortowanie heurystyczne + ograniczenie liczby ruchów (wydajność na hostingu)
    usort($moves, function($a, $b) use ($botTurn) {
        $sa = ps_backend_eval(['x' => $a['x'], 'y' => $a['y']]);
        $sb = ps_backend_eval(['x' => $b['x'], 'y' => $b['y']]);
        // bot maksymalizuje, przeciwnik minimalizuje
        return $botTurn ? ($sb <=> $sa) : ($sa <=> $sb);
    });

    $MAX_BRANCH = 10;
    if (count($moves) > $MAX_BRANCH) $moves = array_slice($moves, 0, $MAX_BRANCH);

    if ($botTurn) {
        $best = -999999;
        foreach ($moves as $m) {
            [$nb, $nl, $bounce, $goalBot, $goalOpp] = ps_backend_apply_move($ball, $usedLines, $m);

            if ($goalBot) return 100000; // natychmiast
            if ($goalOpp) continue;      // bot nie wybierze samobója (teoretycznie)

            $val = ps_backend_minimax($nb, $nl, $depth - 1, $bounce ? true : false);

            // premia za odbicie (daje dodatkowy ruch)
            if ($bounce) $val += 250;

            if ($val > $best) $best = $val;
        }
        return $best;
    } else {
        $best = 999999;
        foreach ($moves as $m) {
            [$nb, $nl, $bounce, $goalBot, $goalOpp] = ps_backend_apply_move($ball, $usedLines, $m);

            if ($goalOpp) return -100000; // przeciwnik strzela bota
            if ($goalBot) continue;

            $val = ps_backend_minimax($nb, $nl, $depth - 1, $bounce ? true : false);

            // premia za odbicie dla przeciwnika (z punktu bota to minus)
            if ($bounce) $val -= 250;

            if ($val < $best) $best = $val;
        }
        return $best;
    }
}

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

            if (!ps_is_valid_move_backend($x, $y, $nx, $ny, $usedLines)) continue;

            $moves[] = [
                'from_x' => $x, 'from_y' => $y,
                'x' => $nx, 'y' => $ny
            ];
        }
    }

    if (empty($moves)) return null;

    // Poziom 1 — losowy ruch
    if ($difficulty <= 1) {
        return $moves[array_rand($moves)];
    }

    // Poziom 4 — ekspert (minimax + odbicia)
    // UWAGA: bot atakuje bramkę u góry (y=0)
    if ($difficulty >= 4) {

        // A) gol natychmiast
        foreach ($moves as $m) {
            if ($m['y'] == 0 && $m['x'] >= 3 && $m['x'] <= 5) {
                return $m;
            }
        }

        // B) minimax (ograniczony) — wybierz ruch z najlepszą oceną
        $depth = 4; // kompromis: siła vs wydajność na hostingu
        $bestMove = null;
        $bestScore = -999999;

        // porządkuj wstępnie heurystyką (żeby szybciej znaleźć dobry ruch)
        $tmp = $moves;
        usort($tmp, function($a, $b) use ($x, $y) {
            $sa = ps_backend_eval(['x' => $a['x'], 'y' => $a['y']]);
            $sb = ps_backend_eval(['x' => $b['x'], 'y' => $b['y']]);
            return $sb <=> $sa;
        });

        $MAX_ROOT = 12;
        if (count($tmp) > $MAX_ROOT) $tmp = array_slice($tmp, 0, $MAX_ROOT);

        foreach ($tmp as $m) {
            [$nb, $nl, $bounce, $goalBot, $goalOpp] = ps_backend_apply_move($ball, $usedLines, $m);

            if ($goalBot) return $m;
            if ($goalOpp) continue;

            $val = ps_backend_minimax($nb, $nl, $depth - 1, $bounce ? true : false);
            if ($bounce) $val += 250;

            if ($val > $bestScore) {
                $bestScore = $val;
                $bestMove = $m;
            }
        }

        if ($bestMove) return $bestMove;

        // fallback
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

    // B) blokuj szybkie zagrożenie (jeżeli piłka jest tuż nad Twoją bramką, nie oddawaj środka)
    $attacks = [];
    foreach ($moves as $m) {
        if ($m['y'] <= 3 && $m['x'] >= 2 && $m['x'] <= 6) $attacks[] = $m;
    }
    if ($attacks) {
        usort($attacks, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
        return $attacks[0];
    }

    // C) odbicia (żeby dostać extra ruch) – liczone po dodaniu linii
    $bounceMoves = [];
    foreach ($moves as $m) {
        // odbicie liczymy po dodaniu aktualnej linii (inaczej nie wykryje punktów z 1 istniejącą linią)
        $tmpLines = $usedLines;
        $tmpLines[] = ['x1' => $x, 'y1' => $y, 'x2' => $m['x'], 'y2' => $m['y']];
        if (ps_backend_has_bounce($m['x'], $m['y'], $tmpLines)) {
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
