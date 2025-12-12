<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

/*
 * Ranking graczy w quizie
 * – tylko zalogowani użytkownicy
 * – tylko zakończone gry (games.status = 'finished')
 */

$sql = "
    SELECT 
        u.id,
        u.username,
        COUNT(*) AS games_played,
        SUM(p.score) AS total_points,
        MAX(p.score) AS best_score,
        AVG(p.score) AS avg_score
    FROM players p
    JOIN games g   ON g.id = p.game_id
    JOIN users u   ON u.id = p.user_id
    WHERE p.user_id IS NOT NULL
      AND p.user_id > 0
      AND g.status = 'finished'
    GROUP BY u.id, u.username
    ORDER BY total_points DESC
    LIMIT 20
";

$result = mysqli_query($conn, $sql);
$rows = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['games_played']  = (int)$row['games_played'];
        $row['total_points']  = (int)$row['total_points'];
        $row['best_score']    = (int)$row['best_score'];
        $row['avg_score']     = round((float)$row['avg_score'], 1);
        $rows[] = $row;
    }
}
?>
<div class="container">
    <h1>Ranking graczy – Quiz</h1>
    <p>
        Poniżej lista najlepszych graczy quizu.
        Liczą się tylko zakończone gry oraz wyniki zalogowanych użytkowników.
    </p>

    <?php if (empty($rows)): ?>
        <p>Na razie brak danych do wyświetlenia. Zagraj w kilka gier, aby zbudować ranking!</p>
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
            <?php $pos = 1; foreach ($rows as $r): ?>
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
