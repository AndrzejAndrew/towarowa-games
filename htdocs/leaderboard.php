<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

// ============================================================
//  GLOBALNY LEADERBOARD – wszystkie gry (portal)
//  Źródła danych:
//   - game_results / xp_log / user_levels (global)
//   - quiz: players + games (historyczne wyniki)
//   - paper_soccer: paper_soccer_stats
//   - pkn: pkn_stats
//   - ttt: ttt_stats
//   - hangman / battleship: game_results
// ============================================================

function lb_fetch_all(mysqli $conn, string $sql): array {
    $rows = [];
    $res = mysqli_query($conn, $sql);
    if (!$res) return $rows;
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    return $rows;
}

// ---------------------------
// 0) Ustawienia
// ---------------------------
// Na leaderboardzie pokazujemy skrót (TOP N), a pełne zestawienia są w rankingach poszczególnych gier.
$LIMIT_GLOBAL = 10;
$LIMIT_GAME   = 10;

// ---------------------------
// 1) Ranking poziomu (XP)
// ---------------------------

$xpRows = lb_fetch_all($conn, "
    SELECT
        t.user_id,
        t.username,
        t.total_xp,
        COALESCE((
            SELECT MAX(l.level)
            FROM user_levels l
            WHERE l.xp_required <= t.total_xp
        ), 1) AS level
    FROM (
        SELECT
            u.id AS user_id,
            u.username,
            COALESCE(SUM(xl.xp_delta), 0) AS total_xp
        FROM users u
        LEFT JOIN xp_log xl ON xl.user_id = u.id
        GROUP BY u.id, u.username
    ) AS t
    WHERE t.total_xp > 0
    ORDER BY level DESC, total_xp DESC
    LIMIT {$LIMIT_GLOBAL}
");

foreach ($xpRows as &$r) {
    $r['total_xp'] = (int)($r['total_xp'] ?? 0);
    $r['level']    = (int)($r['level'] ?? 1);
}
unset($r);

// --------------------------------------
// 2) Globalnie: aktywność / zwycięstwa
// --------------------------------------

$activityRows = lb_fetch_all($conn, "
    SELECT
        u.id AS user_id,
        u.username,
        COUNT(*) AS games_played,
        SUM(gr.result='win')  AS wins,
        SUM(gr.result='loss') AS losses,
        SUM(gr.result='draw') AS draws,
        ROUND(100.0 * SUM(gr.result='win') / COUNT(*), 1) AS winrate,
        MAX(gr.created_at) AS last_game
    FROM game_results gr
    JOIN users u ON u.id = gr.user_id
    GROUP BY u.id, u.username
    HAVING games_played > 0
    ORDER BY wins DESC, winrate DESC, games_played DESC
    LIMIT {$LIMIT_GLOBAL}
");

foreach ($activityRows as &$r) {
    $r['games_played'] = (int)($r['games_played'] ?? 0);
    $r['wins']         = (int)($r['wins'] ?? 0);
    $r['losses']       = (int)($r['losses'] ?? 0);
    $r['draws']        = (int)($r['draws'] ?? 0);
    $r['winrate']      = (float)($r['winrate'] ?? 0);
}
unset($r);

// ---------------------------
// 3) QUIZ – ranking (jak w /games/quiz/ranking.php)
// ---------------------------

$quizWinsRows = lb_fetch_all($conn, "
    SELECT
        u.id AS user_id,
        u.username,
        COALESCE(wins.win_count, 0) AS wins,
        COUNT(DISTINCT p.game_id) AS games_played,
        SUM(p.score) AS total_points
    FROM players p
    JOIN games g   ON g.id = p.game_id
    JOIN users u   ON u.id = p.user_id
    LEFT JOIN (
        SELECT
            p2.user_id,
            COUNT(*) AS win_count
        FROM players p2
        JOIN games g2 ON g2.id = p2.game_id
        JOIN (
            SELECT game_id, MAX(score) AS max_score
            FROM players
            GROUP BY game_id
        ) m ON m.game_id = p2.game_id AND p2.score = m.max_score
        WHERE
            g2.status = 'finished'
            AND p2.user_id IS NOT NULL
            AND p2.user_id > 0
        GROUP BY p2.user_id
    ) wins ON wins.user_id = p.user_id
    WHERE
        p.user_id IS NOT NULL
        AND p.user_id > 0
        AND g.status = 'finished'
    GROUP BY u.id, u.username, wins.win_count
    HAVING games_played > 0
    ORDER BY wins DESC, total_points DESC
    LIMIT {$LIMIT_GAME}
");

foreach ($quizWinsRows as &$r) {
    $r['wins']         = (int)($r['wins'] ?? 0);
    $r['games_played'] = (int)($r['games_played'] ?? 0);
    $r['total_points'] = (int)($r['total_points'] ?? 0);
}
unset($r);

$quizPointsRows = lb_fetch_all($conn, "
    SELECT
        u.id AS user_id,
        u.username,
        COUNT(*)         AS games_played,
        SUM(p.score)     AS total_points,
        MAX(p.score)     AS best_score,
        AVG(p.score)     AS avg_score
    FROM players p
    JOIN games g ON g.id = p.game_id
    JOIN users u ON u.id = p.user_id
    WHERE
        p.user_id IS NOT NULL
        AND p.user_id > 0
        AND g.status = 'finished'
    GROUP BY u.id, u.username
    HAVING games_played > 0
    ORDER BY total_points DESC
    LIMIT {$LIMIT_GAME}
");

foreach ($quizPointsRows as &$r) {
    $r['games_played'] = (int)($r['games_played'] ?? 0);
    $r['total_points'] = (int)($r['total_points'] ?? 0);
    $r['best_score']   = (int)($r['best_score'] ?? 0);
    $r['avg_score']    = round((float)($r['avg_score'] ?? 0), 1);
}
unset($r);

// ---------------------------
// 4) PAPER SOCCER – ranking (paper_soccer_stats)
// ---------------------------

$paperSoccerRows = lb_fetch_all($conn, "
    SELECT
        u.username AS username,
        s.games_played,
        s.games_won,
        s.games_lost,
        s.games_drawn,
        s.last_played,
        CASE WHEN s.games_played > 0 THEN ROUND(100.0 * s.games_won / s.games_played, 1) ELSE 0 END AS winrate
    FROM paper_soccer_stats s
    JOIN users u ON u.id = s.user_id
    ORDER BY s.games_won DESC, s.games_played DESC
    LIMIT {$LIMIT_GAME}
");

foreach ($paperSoccerRows as &$r) {
    $r['games_played'] = (int)($r['games_played'] ?? 0);
    $r['games_won']    = (int)($r['games_won'] ?? 0);
    $r['games_lost']   = (int)($r['games_lost'] ?? 0);
    $r['games_drawn']  = (int)($r['games_drawn'] ?? 0);
    $r['winrate']      = (float)($r['winrate'] ?? 0);
}
unset($r);

// ---------------------------
// 5) PKN – ranking (pkn_stats)
// ---------------------------

$pknRows = lb_fetch_all($conn, "
    SELECT
        u.id,
        u.username,
        s.games_total,
        s.games_won,
        s.games_lost,
        s.games_bot_total,
        s.games_bot_won,
        CASE WHEN s.games_total > 0 THEN ROUND(100.0 * s.games_won / s.games_total, 2) ELSE 0 END AS winrate
    FROM users u
    JOIN pkn_stats s ON s.user_id = u.id
    ORDER BY winrate DESC, s.games_total DESC, s.games_won DESC
    LIMIT {$LIMIT_GAME}
");

foreach ($pknRows as &$r) {
    $r['games_total']     = (int)($r['games_total'] ?? 0);
    $r['games_won']       = (int)($r['games_won'] ?? 0);
    $r['games_lost']      = (int)($r['games_lost'] ?? 0);
    $r['games_bot_total'] = (int)($r['games_bot_total'] ?? 0);
    $r['games_bot_won']   = (int)($r['games_bot_won'] ?? 0);
    $r['winrate']         = (float)($r['winrate'] ?? 0);
}
unset($r);

// ---------------------------
// 6) TTT – ranking (ttt_stats)
// ---------------------------

$tttRows = lb_fetch_all($conn, "
    SELECT
        u.username,
        s.games_played,
        s.games_won,
        (CASE WHEN s.games_played > 0 THEN ROUND(100.0 * s.games_won / s.games_played, 1) ELSE 0 END) AS winrate
    FROM ttt_stats s
    JOIN users u ON u.id = s.user_id
    ORDER BY s.games_won DESC, winrate DESC, s.games_played DESC
    LIMIT {$LIMIT_GAME}
");

foreach ($tttRows as &$r) {
    $r['games_played'] = (int)($r['games_played'] ?? 0);
    $r['games_won']    = (int)($r['games_won'] ?? 0);
    $r['winrate']      = (float)($r['winrate'] ?? 0);
}
unset($r);

// ---------------------------
// 7) HANGMAN + BATTLESHIP – ranking (game_results)
// ---------------------------

function lb_game_results_ranking(mysqli $conn, string $gameCode, int $limit): array {
    $gameCodeEsc = mysqli_real_escape_string($conn, $gameCode);
    $rows = lb_fetch_all($conn, "
        SELECT
            u.username,
            COUNT(*) AS games_played,
            SUM(gr.result='win')  AS wins,
            SUM(gr.result='loss') AS losses,
            SUM(gr.result='draw') AS draws,
            ROUND(100.0 * SUM(gr.result='win') / COUNT(*), 1) AS winrate,
            MAX(gr.created_at) AS last_game
        FROM game_results gr
        JOIN users u ON u.id = gr.user_id
        WHERE gr.game_code = '{$gameCodeEsc}'
        GROUP BY u.id, u.username
        HAVING games_played > 0
        ORDER BY wins DESC, winrate DESC, games_played DESC
        LIMIT {$limit}
    ");

    foreach ($rows as &$r) {
        $r['games_played'] = (int)($r['games_played'] ?? 0);
        $r['wins']         = (int)($r['wins'] ?? 0);
        $r['losses']       = (int)($r['losses'] ?? 0);
        $r['draws']        = (int)($r['draws'] ?? 0);
        $r['winrate']      = (float)($r['winrate'] ?? 0);
    }
    unset($r);

    return $rows;
}

$hangmanRows    = lb_game_results_ranking($conn, 'hangman', $LIMIT_GAME);
$battleshipRows = lb_game_results_ranking($conn, 'battleship', $LIMIT_GAME);

?>

<div class="container">
    <h1>Rankingi – wszystkie gry</h1>
    <p class="subtitle">
        Zbiorcze zestawienie rankingów z całego portalu. Większość statystyk dotyczy zalogowanych użytkowników
        oraz gier zakończonych (tam gdzie to ma zastosowanie).
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 18px;">
        <a class="btn-secondary" href="#global">Globalnie</a>
        <a class="btn-secondary" href="#quiz">Quiz</a>
        <a class="btn-secondary" href="#paper_soccer">Papierowa Piłka</a>
        <a class="btn-secondary" href="#pkn">PKN</a>
        <a class="btn-secondary" href="#ttt">Kółko i krzyżyk</a>
        <a class="btn-secondary" href="#hangman">Wisielec</a>
        <a class="btn-secondary" href="#battleship">Statki</a>
    </div>

    <!-- ==================== GLOBAL ==================== -->
    <div class="card" id="global" style="margin-top:16px;">
        <h2>Globalnie</h2>
        <p class="subtitle" style="margin-top:6px;">
            Poziom i XP liczone są z <code>xp_log</code>, a aktywność z <code>game_results</code>.
        </p>

        <h3 style="margin-top:14px;">Najwyższy poziom (XP)</h3>
        <?php if (empty($xpRows)): ?>
            <p>Brak danych o XP. Zagraj w kilka gier, aby pojawić się w rankingu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Poziom</th>
                    <th>Łączne XP</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($xpRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['level']; ?></td>
                        <td><?php echo (int)$r['total_xp']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="margin-top:18px;">Najbardziej aktywni gracze (wszystkie gry)</h3>
        <?php if (empty($activityRows)): ?>
            <p>Brak danych o rozegranych grach.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Gry</th>
                    <th>Wygrane</th>
                    <th>Przegrane</th>
                    <th>Remisy</th>
                    <th>Win%</th>
                    <th>Ostatnia gra</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($activityRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['wins']; ?></td>
                        <td><?php echo (int)$r['losses']; ?></td>
                        <td><?php echo (int)$r['draws']; ?></td>
                        <td><?php echo number_format((float)$r['winrate'], 1); ?>%</td>
                        <td><?php echo htmlspecialchars($r['last_game'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ==================== QUIZ ==================== -->
    <div class="card" id="quiz" style="margin-top:16px;">
        <h2>Quiz</h2>
        <p class="subtitle" style="margin-top:6px;">
            To jest skrót z <a href="/games/quiz/ranking.php">/games/quiz/ranking.php</a> (zalogowani + gry zakończone).
        </p>

        <h3 style="margin-top:14px;">Ranking wg liczby zwycięstw</h3>
        <?php if (empty($quizWinsRows)): ?>
            <p>Brak danych z quizu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Zwycięstwa</th>
                    <th>Rozegrane gry</th>
                    <th>Suma punktów</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($quizWinsRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['wins']; ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['total_points']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="margin-top:18px;">Ranking wg sumy punktów</h3>
        <?php if (empty($quizPointsRows)): ?>
            <p>Brak danych z quizu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Liczba gier</th>
                    <th>Suma punktów</th>
                    <th>Najlepszy wynik</th>
                    <th>Średni wynik</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($quizPointsRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['total_points']; ?></td>
                        <td><?php echo (int)$r['best_score']; ?></td>
                        <td><?php echo htmlspecialchars((string)$r['avg_score']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:12px;"><a href="/games/quiz/ranking.php">Zobacz pełny ranking quizu →</a></p>
    </div>

    <!-- ==================== PAPER SOCCER ==================== -->
    <div class="card" id="paper_soccer" style="margin-top:16px;">
        <h2>Papierowa Piłka Nożna</h2>
        <p class="subtitle" style="margin-top:6px;">
            Statystyki z <code>paper_soccer_stats</code> (rozegrane / wygrane / przegrane / remisy).
        </p>

        <?php if (empty($paperSoccerRows)): ?>
            <p>Brak danych w rankingu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Gry</th>
                    <th>Wygrane</th>
                    <th>Przegrane</th>
                    <th>Remisy</th>
                    <th>Win%</th>
                    <th>Ostatnia gra</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($paperSoccerRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['games_won']; ?></td>
                        <td><?php echo (int)$r['games_lost']; ?></td>
                        <td><?php echo (int)$r['games_drawn']; ?></td>
                        <td><?php echo number_format((float)$r['winrate'], 1); ?>%</td>
                        <td><?php echo htmlspecialchars($r['last_played'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:12px;"><a href="/games/paper_soccer/ranking.php">Zobacz pełny ranking papierowej piłki →</a></p>
    </div>

    <!-- ==================== PKN ==================== -->
    <div class="card" id="pkn" style="margin-top:16px;">
        <h2>PKN (Papier–Kamień–Nożyce)</h2>
        <p class="subtitle" style="margin-top:6px;">
            Statystyki z <code>pkn_stats</code> (również vs bot).
        </p>

        <?php if (empty($pknRows)): ?>
            <p>Brak danych w rankingu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Rozegrane</th>
                    <th>Wygrane</th>
                    <th>Przegrane</th>
                    <th>Win%</th>
                    <th>vs Bot (W / T)</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($pknRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_total']; ?></td>
                        <td><?php echo (int)$r['games_won']; ?></td>
                        <td><?php echo (int)$r['games_lost']; ?></td>
                        <td><?php echo number_format((float)$r['winrate'], 2); ?>%</td>
                        <td><?php echo (int)$r['games_bot_won'] . ' / ' . (int)$r['games_bot_total']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:12px;"><a href="/games/rock_paper_scissors/ranking.php">Zobacz pełny ranking PKN →</a></p>
    </div>

    <!-- ==================== TTT ==================== -->
    <div class="card" id="ttt" style="margin-top:16px;">
        <h2>Kółko i krzyżyk</h2>
        <p class="subtitle" style="margin-top:6px;">
            Statystyki z <code>ttt_stats</code>.
        </p>

        <?php if (empty($tttRows)): ?>
            <p>Brak danych w rankingu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Rozegrane</th>
                    <th>Wygrane</th>
                    <th>Win%</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($tttRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['games_won']; ?></td>
                        <td><?php echo number_format((float)$r['winrate'], 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:12px;"><a href="/games/tic_tac_toe/rank.php">Zobacz pełny ranking kółka i krzyżyka →</a></p>
    </div>

    <!-- ==================== HANGMAN ==================== -->
    <div class="card" id="hangman" style="margin-top:16px;">
        <h2>Wisielec</h2>
        <p class="subtitle" style="margin-top:6px;">
            Na ten moment ranking opiera się o <code>game_results</code> (wygrane / przegrane / remisy).
        </p>

        <?php if (empty($hangmanRows)): ?>
            <p>Brak danych w rankingu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Gry</th>
                    <th>Wygrane</th>
                    <th>Przegrane</th>
                    <th>Remisy</th>
                    <th>Win%</th>
                    <th>Ostatnia gra</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($hangmanRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['wins']; ?></td>
                        <td><?php echo (int)$r['losses']; ?></td>
                        <td><?php echo (int)$r['draws']; ?></td>
                        <td><?php echo number_format((float)$r['winrate'], 1); ?>%</td>
                        <td><?php echo htmlspecialchars($r['last_game'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:12px;"><a href="/games/hangman/index.php">Przejdź do wisielca →</a></p>
    </div>

    <!-- ==================== BATTLESHIP ==================== -->
    <div class="card" id="battleship" style="margin-top:16px;">
        <h2>Statki</h2>
        <p class="subtitle" style="margin-top:6px;">
            Na ten moment ranking opiera się o <code>game_results</code> (wygrane / przegrane / remisy).
        </p>

        <?php if (empty($battleshipRows)): ?>
            <p>Brak danych w rankingu.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Gry</th>
                    <th>Wygrane</th>
                    <th>Przegrane</th>
                    <th>Remisy</th>
                    <th>Win%</th>
                    <th>Ostatnia gra</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($battleshipRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo (int)$r['games_played']; ?></td>
                        <td><?php echo (int)$r['wins']; ?></td>
                        <td><?php echo (int)$r['losses']; ?></td>
                        <td><?php echo (int)$r['draws']; ?></td>
                        <td><?php echo number_format((float)$r['winrate'], 1); ?>%</td>
                        <td><?php echo htmlspecialchars($r['last_game'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:12px;"><a href="/games/battleship/index.php">Przejdź do statków →</a></p>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
