<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

// Pobieramy ranking
$q = "
    SELECT 
        u.username,
        s.games_played,
        s.games_won,
        s.games_lost,
        s.games_drawn,
        s.last_played
    FROM paper_soccer_stats s
    LEFT JOIN users u ON u.id = s.user_id
    ORDER BY s.games_won DESC, s.games_played DESC
";
$res = mysqli_query($conn, $q);

?>
<style>
    body {
        background: #222;
        color: #eee;
        font-family: Arial, sans-serif;
        padding: 20px;
    }

    h1 {
        text-align: center;
        margin-bottom: 30px;
    }

    table {
        width: 90%;
        max-width: 900px;
        margin: 0 auto;
        border-collapse: collapse;
        background: #333;
        border: 2px solid #444;
        border-radius: 10px;
        overflow: hidden;
    }

    th, td {
        padding: 12px 8px;
        text-align: center;
    }

    th {
        background: #444;
        font-size: 16px;
    }

    tr:nth-child(even) {
        background: #2a2a2a;
    }

    tr:hover {
        background: #505050;
    }

    .no-data {
        text-align: center;
        margin-top: 40px;
        font-size: 20px;
        color: #bbb;
    }
</style>

<h1>🏆 Ranking – Papierowa Piłka Nożna</h1>

<?php if (mysqli_num_rows($res) == 0): ?>

    <div class="no-data">Brak danych w rankingu.</div>

<?php else: ?>

<table>
    <tr>
        <th>Miejsce</th>
        <th>Gracz</th>
        <th>Gry</th>
        <th>Wygrane</th>
        <th>Przegrane</th>
        <th>Remisy</th>
        <th>Ostatnia gra</th>
    </tr>

    <?php 
    $place = 1;
    while ($row = mysqli_fetch_assoc($res)):
        $username = $row['username'] ?: "Gość";
    ?>
    <tr>
        <td><?= $place++ ?></td>
        <td><?= htmlspecialchars($username) ?></td>
        <td><?= $row['games_played'] ?></td>
        <td style="color:#4CAF50;font-weight:bold;"><?= $row['games_won'] ?></td>
        <td style="color:#e74c3c;"><?= $row['games_lost'] ?></td>
        <td style="color:#f1c40f;"><?= $row['games_drawn'] ?></td>
        <td><?= $row['last_played'] ?></td>
    </tr>
    <?php endwhile; ?>
</table>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
