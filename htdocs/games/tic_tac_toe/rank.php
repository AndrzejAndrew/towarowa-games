<?php
require_once __DIR__ . '/ttt_boot.php';
require_once __DIR__ . '/../../includes/header.php';

$res = mysqli_query($conn, "SELECT u.username, s.games_played, s.games_won,
                                   (CASE WHEN s.games_played>0 THEN s.games_won/s.games_played ELSE 0 END) AS ratio
                            FROM ttt_stats s
                            JOIN users u ON u.id = s.user_id
                            ORDER BY s.games_won DESC, ratio DESC, s.games_played DESC
                            LIMIT 100");
?>
<div class="container" style="max-width:840px;margin:20px auto;">
  <h1>🏆 Ranking kółka i krzyżyka</h1>
  <table border="1" cellpadding="6" cellspacing="0">
    <tr><th>#</th><th>Gracz</th><th>Rozegrane</th><th>Wygrane</th><th>Skuteczność</th></tr>
    <?php $i=1; while ($r = mysqli_fetch_assoc($res)): ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><?= htmlspecialchars($r['username']) ?></td>
        <td><?= (int)$r['games_played'] ?></td>
        <td><?= (int)$r['games_won'] ?></td>
        <td><?= round(((float)$r['ratio'])*100) ?>%</td>
      </tr>
    <?php endwhile; ?>
  </table>
  <p style="margin-top:16px;"><a href="index.php">← Wróć do gry</a></p>
</div>
