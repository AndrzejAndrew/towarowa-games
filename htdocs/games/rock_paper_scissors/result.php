<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_username_local() {
    if (!empty($_SESSION['user']['username'])) return $_SESSION['user']['username'];
    if (!empty($_SESSION['username'])) return $_SESSION['username'];
    if (!empty($_SESSION['guest_name'])) return $_SESSION['guest_name'];
    return 'Gosc';
}

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) { header('Location:index.php'); exit; }

$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g   = mysqli_fetch_assoc($res);
if (!$g) { die('Gra nie istnieje.'); }

$me = current_username_local();

// rundy
$rres = mysqli_query($conn, "SELECT * FROM pkn_rounds WHERE game_id = $game_id ORDER BY round_no ASC");
$rounds = [];
while ($row = mysqli_fetch_assoc($rres)) {
    $rounds[] = $row;
}

$p1 = $g['player1_name'];
$p2 = $g['player2_name'];
$p1s = (int)$g['p1_score'];
$p2s = (int)$g['p2_score'];
$total_rounds = (int)$g['rounds_total'];

$winner_text = '';
$me_message  = '';

if ($p1s > $p2s) {
    $winner_text = 'Wygrał gracz 1: '.htmlspecialchars($p1);
} elseif ($p2s > $p1s) {
    $winner_text = 'Wygrał gracz 2: '.htmlspecialchars($p2);
} else {
    $winner_text = 'Remis – obaj gracze są równie dobrzy!';
}

// komunikat z perspektywy zalogowanego
if ($me === $p1 || $me === $p2) {
    $my_rounds = ($me === $p1) ? $p1s : $p2s;
    if ($p1s === $p2s) {
        $me_message = "Rozegrano {$total_rounds} rund. Masz remis: {$p1s} : {$p2s}.";
    } elseif (
        ($me === $p1 && $p1s > $p2s) ||
        ($me === $p2 && $p2s > $p1s)
    ) {
        $me_message = "Gratulacje! Wygrałeś {$my_rounds}/{$total_rounds} rund. Wygrałeś tę bitwę!";
    } else {
        $me_message = "Tym razem przegrałeś – zdobyłeś {$my_rounds}/{$total_rounds} rund. Następnym razem się uda!";
    }
}
?>
<div class="container" style="max-width:900px;margin:2rem auto;">
    <h1>PKN – Wynik</h1>
    <p><strong><?php echo htmlspecialchars($p1); ?></strong> vs <strong><?php echo htmlspecialchars($p2); ?></strong></p>
    <p><strong>Wynik końcowy:</strong> <?php echo $p1s.' : '.$p2s; ?></p>
    <p><?php echo $winner_text; ?></p>
    <?php if ($me_message): ?>
        <p><?php echo htmlspecialchars($me_message); ?></p>
    <?php endif; ?>

    <?php if ($rounds): ?>
        <h2>Podsumowanie rund</h2>
        <table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th>Runda</th>
                    <th><?php echo htmlspecialchars($p1); ?></th>
                    <th><?php echo htmlspecialchars($p2); ?></th>
                    <th>Wynik rundy</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $map = ['rock'=>'kamień','paper'=>'papier','scissors'=>'nożyce'];
            foreach ($rounds as $r):
                $rn = (int)$r['round_no'];
                $m1 = $map[$r['p1_move']] ?? $r['p1_move'];
                $m2 = $map[$r['p2_move']] ?? $r['p2_move'];
                $w  = (int)$r['winner'];
                if     ($w === 0) $wtext = 'Remis';
                elseif ($w === 1) $wtext = 'Wygrywa: '.$p1;
                else              $wtext = 'Wygrywa: '.$p2;
            ?>
                <tr>
                    <td><?php echo $rn; ?></td>
                    <td><?php echo htmlspecialchars($m1); ?></td>
                    <td><?php echo htmlspecialchars($m2); ?></td>
                    <td><?php echo htmlspecialchars($wtext); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p style="margin-top:12px;">
        <a href="index.php">Zagraj ponownie</a> |
        <a href="ranking.php">Ranking</a>
    </p>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
