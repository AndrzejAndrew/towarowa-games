<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

$res = mysqli_query($conn,
    "SELECT code, total_rounds, current_round, status, mode, owner_player_id
     FROM games WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);
if (!$game) {
    die("Nie ma takiej gry.");
}
if ($game['mode'] !== 'dynamic') {
    header("Location: game.php?game=" . $game_id);
    exit;
}

$current_round = (int)$game['current_round'];
$code = $game['code'];

// wyliczamy numer tury głosowania (1..N)
$vote_round = (int)floor(($current_round) / 5) + 1;
if ($current_round === 0) $vote_round = 1;

// znajdź aktualnego gracza
$is_guest = is_logged_in() ? 0 : 1;
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id, nickname FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare($conn,
        "SELECT id, nickname FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}
mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$player) {
    die("Nie jesteś uczestnikiem tej gry.");
}
$player_id = (int)$player['id'];

// losujemy 5 kategorii ze wszystkich pytań
$res = mysqli_query($conn,
    "SELECT DISTINCT category
     FROM questions
     WHERE category <> ''
     ORDER BY RAND()
     LIMIT 5"
);
$cats = [];
while ($row = mysqli_fetch_assoc($res)) {
    $cats[] = $row['category'];
}
?>
<div class="container">
    <h1>Głosowanie nad kategorią</h1>
    <p>Kod gry: <strong><?php echo htmlspecialchars($code); ?></strong></p>
    <p>Gracz: <?php echo htmlspecialchars($player['nickname']); ?></p>
    <p>Ta tura: <strong><?php echo $vote_round; ?></strong></p>

    <?php if (empty($cats)): ?>
        <p>Brak dostępnych kategorii w bazie pytań.</p>
    <?php else: ?>
        <p>Wybierz kategorię, w której chciałbyś zagrać. Z tej kategorii zostanie wylosowanych 5 pytań.</p>

        <form id="vote-form">
            <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
            <input type="hidden" name="round_number" value="<?php echo $vote_round; ?>">

            <?php foreach ($cats as $c): ?>
                <label style="display:block; margin-bottom:6px;">
                    <input type="radio" name="category" value="<?php echo htmlspecialchars($c); ?>">
                    <?php echo htmlspecialchars($c); ?>
                </label>
            <?php endforeach; ?>

            <button type="button" class="btn-primary" onclick="sendVote()">Oddaj głos</button>
        </form>

        <p id="vote-status" style="margin-top:10px;"></p>

        <?php if ((int)$game['owner_player_id'] === $player_id): ?>
            <hr>
            <p>Jako gospodarz możesz zakończyć głosowanie, gdy wszyscy gracze oddadzą głosy.</p>
            <form method="post" action="vote_result.php" id="finish-form">
                <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
                <button type="submit" class="btn-secondary">Zakończ głosowanie i rozpocznij rundę</button>
            </form>
        <?php else: ?>
            <p>Czekaj na decyzję gospodarza, gdy głosy zostaną zliczone.</p>
        <?php endif; ?>
    <?php endif; ?>

    <p style="margin-top:20px;"><a href="/index.php">&larr; Wyjdź do strony głównej</a></p>
</div>

<script>
function sendVote() {
    const form = document.getElementById('vote-form');
    const statusEl = document.getElementById('vote-status');
    const fd = new FormData(form);
    if (!fd.get('category')) {
        statusEl.textContent = "Musisz wybrać kategorię.";
        return;
    }
    fetch('vote_submit.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            statusEl.textContent = "Głos zapisany. Czekamy na innych graczy i gospodarza.";
        } else {
            statusEl.textContent = data.error || "Błąd przy zapisie głosu.";
        }
    })
    .catch(err => {
        console.error(err);
        statusEl.textContent = "Błąd sieci.";
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
