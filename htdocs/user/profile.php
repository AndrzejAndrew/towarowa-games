<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    header("Location: /user/login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Statystyki kółko i krzyżyk
$ttt_stats = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT games_played, games_won FROM ttt_stats WHERE user_id = $user_id"
));
if (!$ttt_stats) {
    $ttt_stats = [
        'games_played' => 0,
        'games_won'    => 0,
    ];
}

$stmt = mysqli_prepare($conn,
    "SELECT game_type, result, created_at
     FROM game_history
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 10"
);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
    $rows[] = $r;
}
mysqli_stmt_close($stmt);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <h1>Mój profil</h1>
      <h2>Kółko i krzyżyk</h2>
    <p>
        Rozegrane gry: <?php echo (int)$ttt_stats['games_played']; ?><br>
        Wygrane gry: <?php echo (int)$ttt_stats['games_won']; ?>
    </p>

    <p>Ostatnie gry zapisane na koncie:</p>
    <?php if (!$rows): ?>
        <p>Brak zapisanych gier. Zagraj w quiz jako zalogowany, aby zapisać historię.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>Gra</th>
                <th>Wynik</th>
                <th>Data</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['game_type']); ?></td>
                    <td><?php echo htmlspecialchars($r['result']); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
