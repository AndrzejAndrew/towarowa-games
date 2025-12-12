<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

// ------------------------------------------
// Ranking A – wg liczby zwycięstw (TOP 20)
// ------------------------------------------
//
// Definicja zwycięzcy:
//  - gracz z najwyższym wynikiem w danej grze
//  - tylko gry ze statusem "finished"
//  - tylko zalogowani użytkownicy (user_id > 0)

$winsSql = "
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
    LIMIT 20
";

$winsResult = mysqli_query($conn, $winsSql);
$winsRows   = [];

if ($winsResult) {
    while ($row = mysqli_fetch_assoc($winsResult)) {
        $row['wins']         = (int)$row['wins'];
        $row['games_played'] = (int)$row['games_played'];
        $row['total_points'] = (int)$row['total_points'];
        $winsRows[] = $row;
    }
}

// -----------------------------------------------
// Ranking B – wg sumy punktów / średniego wyniku
// -----------------------------------------------

$pointsSql = "
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
    LIMIT 20
";

$pointsResult = mysqli_query($conn, $pointsSql);
$pointsRows   = [];

if ($pointsResult) {
    while ($row = mysqli_fetch_assoc($pointsResult)) {
        $row['games_played'] = (int)$row['games_played'];
        $row['total_points'] = (int)$row['total_points'];
        $row['best_score']   = (int)$row['best_score'];
        $row['avg_score']    = round((float)$row['avg_score'], 1);
        $pointsRows[] = $row;
    }
}
?>

<div class="container">
    <h1>Ranking Quizu</h1>
    <p>
        Statystyki dotyczą tylko zalogowanych użytkowników i zakończonych gier.
        Goście oraz gry przerwane w trakcie nie są uwzględniane.
    </p>

    <!-- Ranking A: wg liczby zwycięstw -->
    <h2>Ranking wg liczby zwycięstw</h2>

    <?php if (empty($winsRows)): ?>
        <p>Na razie brak danych do wyświetlenia. Zagraj w kilka gier, aby zbudować ranking.</p>
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
            <?php $pos = 1; foreach ($winsRows as $r): ?>
                <tr>
                    <td><?php echo $pos++; ?></td>
                    <td><?php echo htmlspecialchars($r['username']); ?></td>
                    <td><?php echo $r['wins']; ?></td>
                    <td><?php echo $r['games_played']; ?></td>
                    <td><?php echo $r['total_points']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Ranking B: wg sumy punktów -->
    <h2>Ranking wg sumy punktów</h2>

    <?php if (empty($pointsRows)): ?>
        <p>Na razie brak danych do wyświetlenia. Zagraj w kilka gier, aby zobaczyć tę tabelę.</p>
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
            <?php $pos = 1; foreach ($pointsRows as $r): ?>
                <tr>
                    <td><?php echo $pos++; ?></td>
                    <td><?php echo htmlspecialchars($r['username']); ?></td>
                    <td><?php echo $r['games_played']; ?></td>
                    <td><?php echo $r['total_points']; ?></td>
                    <td><?php echo $r['best_score']; ?></td>
                    <td><?php echo $r['avg_score']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><a href="index.php">&larr; Wróć do quizu</a></p>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
