<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_user_id() {
    return $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
}
function current_username() {
    if (!empty($_SESSION['user']['username'])) return $_SESSION['user']['username'];
    if (!empty($_SESSION['username'])) return $_SESSION['username'];
    if (!empty($_SESSION['guest_name'])) return $_SESSION['guest_name'];
    return 'Gosc';
}
?>

<?php
$res = mysqli_query($conn, "SELECT u.id, u.username, s.games_total, s.games_won, s.games_lost, s.games_bot_total, s.games_bot_won,
    CASE WHEN s.games_total>0 THEN ROUND(100.0*s.games_won/s.games_total,2) ELSE 0 END AS winrate
    FROM users u
    JOIN pkn_stats s ON s.user_id = u.id
    ORDER BY winrate DESC, s.games_total DESC, s.games_won DESC
    LIMIT 200");
?>
<div class="container" style="max-width:900px;margin:2rem auto;">
    <h1>PKN – Ranking</h1>
    <table border="1" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;">
        <thead>
            <tr>
                <th>#</th>
                <th>Użytkownik</th>
                <th>Rozegrane</th>
                <th>Wygrane</th>
                <th>Przegrane</th>
                <th>Wygrane %</th>
                <th>vs Bot (W/T)</th>
            </tr>
        </thead>
        <tbody>
        <?php $i=1; while($r = mysqli_fetch_assoc($res)): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($r['username'] ?? ('ID:'.$r['id'])); ?></td>
                <td><?php echo (int)$r['games_total']; ?></td>
                <td><?php echo (int)$r['games_won']; ?></td>
                <td><?php echo (int)$r['games_lost']; ?></td>
                <td><?php echo number_format((float)$r['winrate'], 2); ?>%</td>
                <td><?php echo (int)$r['games_bot_won'].' / '.(int)$r['games_bot_total']; ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <p style="margin-top:12px;"><a href="index.php">Wróć do PKN</a></p>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
