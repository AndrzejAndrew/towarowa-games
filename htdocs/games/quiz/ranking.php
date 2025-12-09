<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

// Ranking tylko dla zalogowanych
if (!is_logged_in()) {
    header("Location: /user/login.php");
    exit;
}

// główne statystyki: rozegrane / wygrane gry
$sql = "
SELECT 
    u.id,
    u.username,
    COUNT(DISTINCT p.game_id) AS games_played,
    SUM(
        CASE 
            WHEN p.score = (
                SELECT MAX(p2.score)
                FROM players p2
                WHERE p2.game_id = p.game_id
            ) AND p.score > 0
            THEN 1 ELSE 0
        END
    ) AS games_won
FROM users u
LEFT JOIN players p ON u.id = p.user_id
GROUP BY u.id, u.username
ORDER BY games_won DESC, games_played DESC, u.username ASC
";

$res = mysqli_query($conn, $sql);
$ranking = [];

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $user_id = (int)$row['id'];

        // Pomijamy użytkowników, którzy nie zagrali żadnej gry
        if ((int)$row['games_played'] === 0) {
            continue;
        }

        // Najlepsza kategoria - najwięcej poprawnych odpowiedzi
        $stmt = mysqli_prepare(
            $conn,
            "SELECT q.category, COUNT(*) AS correct_count
             FROM answers a
             JOIN players p ON a.player_id = p.id
             JOIN questions q ON a.question_id = q.id
             WHERE p.user_id = ? 
               AND a.is_correct = 1
               AND q.category IS NOT NULL
               AND q.category <> ''
             GROUP BY q.category
             ORDER BY correct_count DESC, q.category ASC
             LIMIT 1"
        );
        $best_category = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $cres = mysqli_stmt_get_result($stmt);
            if ($crow = mysqli_fetch_assoc($cres)) {
                $best_category = $crow['category'];
            }
            mysqli_stmt_close($stmt);
        }

        $row['best_category'] = $best_category;
        $ranking[] = $row;
    }
}
?>
<div class="container">
    <h1>Ranking graczy – quiz</h1>
    <p>Tu widać, ilu zalogowani użytkownicy rozegrali i wygrali gier w quiz oraz w jakiej kategorii odpowiadają najlepiej.</p>

    <?php if (empty($ranking)): ?>
        <p>Brak jeszcze danych do rankingu. Zagraj kilka gier w quiz, żeby pojawić się na liście.</p>
    <?php else: ?>
        <table class="table-ranking">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Użytkownik</th>
                    <th>Rozegrane gry</th>
                    <th>Wygrane gry</th>
                    <th>Najlepsza kategoria</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 1; foreach ($ranking as $row): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo (int)$row['games_played']; ?></td>
                    <td><?php echo (int)$row['games_won']; ?></td>
                    <td>
                        <?php echo $row['best_category'] ? htmlspecialchars($row['best_category']) : 'brak danych'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p style="margin-top:16px;">
        <a href="index.php">&larr; Wróć do quizu</a><br>
        <a href="/index.php">&larr; Wróć do strony głównej</a>
    </p>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
