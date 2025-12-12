<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

/**
 * GLOBALNY LEADERBOARD
 *
 * Sekcje:
 *  1. Ranking level / XP (na podstawie xp_log + user_levels)
 *  2. Najbardziej aktywni gracze (game_results)
 *  3. Skrócony ranking quizu (players + games)
 */

// ---------------------------
// 1. Ranking level / XP
// ---------------------------

// Najpierw zbieramy całkowite XP per użytkownik z xp_log
$xpRows = [];
$xpSql = "
    SELECT 
        t.user_id,
        t.username,
        t.total_xp,
        (
            SELECT MAX(l.level)
            FROM user_levels l
            WHERE l.xp_required <= t.total_xp
        ) AS level
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
    LIMIT 10
";

if ($xpResult = mysqli_query($conn, $xpSql)) {
    while ($row = mysqli_fetch_assoc($xpResult)) {
        $row['total_xp'] = (int)$row['total_xp'];
        $row['level']    = (int)($row['level'] ?? 0);
        $xpRows[]        = $row;
    }
}

// --------------------------------------
// 2. Najbardziej aktywni gracze (ALL)
// --------------------------------------

$activityRows = [];
$activitySql = "
    SELECT
        u.id AS user_id,
        u.username,
        COUNT(*) AS games_played,
        SUM(CASE WHEN gr.result = 'win' THEN 1 ELSE 0 END) AS wins
    FROM game_results gr
    JOIN users u ON u.id = gr.user_id
    GROUP BY u.id, u.username
    HAVING games_played > 0
    ORDER BY wins DESC, games_played DESC
    LIMIT 10
";

if ($activityResult = mysqli_query($conn, $activitySql)) {
    while ($row = mysqli_fetch_assoc($activityResult)) {
        $row['games_played'] = (int)$row['games_played'];
        $row['wins']         = (int)$row['wins'];
        $activityRows[]      = $row;
    }
}

// ----------------------------------------
// 3. Skrócony ranking quizu (TOP 5)
// ----------------------------------------

$quizRows = [];
$quizSql = "
    SELECT 
        u.id AS user_id,
        u.username,
        COUNT(*)     AS games_played,
        SUM(p.score) AS total_points,
        MAX(p.score) AS best_score
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
    LIMIT 5
";

if ($quizResult = mysqli_query($conn, $quizSql)) {
    while ($row = mysqli_fetch_assoc($quizResult)) {
        $row['games_played'] = (int)$row['games_played'];
        $row['total_points'] = (int)$row['total_points'];
        $row['best_score']   = (int)$row['best_score'];
        $quizRows[]          = $row;
    }
}
?>

<div class="container">
    <h1>Leaderboard – wszystkie gry</h1>
    <p>
        Zbiorczy ranking graczy z całego portalu. Szczegółowe rankingi
        poszczególnych gier znajdziesz na ich stronach, np.
        <a href="/games/quiz/ranking.php">Ranking quizu</a>.
    </p>

    <!-- Sekcja 1: Globalny ranking level / XP -->
    <div class="card">
        <h2>Najwyższy poziom (XP)</h2>
        <?php if (empty($xpRows)): ?>
            <p>Brak danych o poziomach. Zagraj kilka gier, aby zdobyć XP.</p>
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
                        <td><?php echo $r['level']; ?></td>
                        <td><?php echo $r['total_xp']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Sekcja 2: Najbardziej aktywni gracze -->
    <div class="card">
        <h2>Najbardziej aktywni gracze (wszystkie gry)</h2>
        <?php if (empty($activityRows)): ?>
            <p>Brak danych o rozegranych grach.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Rozegrane gry</th>
                    <th>Zwycięstwa</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($activityRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo $r['games_played']; ?></td>
                        <td><?php echo $r['wins']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Sekcja 3: Skrócony ranking quizu -->
    <div class="card">
        <h2>Top 5 w quizie</h2>
        <?php if (empty($quizRows)): ?>
            <p>Brak danych z quizu. Rozegraj kilka gier, aby zobaczyć tabelę.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Miejsce</th>
                    <th>Gracz</th>
                    <th>Liczba gier</th>
                    <th>Suma punktów</th>
                    <th>Najlepszy wynik</th>
                </tr>
                </thead>
                <tbody>
                <?php $pos = 1; foreach ($quizRows as $r): ?>
                    <tr>
                        <td><?php echo $pos++; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo $r['games_played']; ?></td>
                        <td><?php echo $r['total_points']; ?></td>
                        <td><?php echo $r['best_score']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <a href="/games/quiz/ranking.php">Zobacz pełny ranking quizu &rarr;</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
