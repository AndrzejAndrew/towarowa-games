<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

// gra
$stmt = mysqli_prepare($conn,
    "SELECT code, owner_player_id, status, total_rounds, time_per_question
     FROM games WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $game_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) {
    die("Nie znaleziono gry.");
}

// ustal aktualnego playera (zalogowany lub gość)
$is_guest = is_logged_in() ? 0 : 1;
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id, nickname, score FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare($conn,
        "SELECT id, nickname, score FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$player) {
    die("Nie jesteś uczestnikiem tej gry.");
}
$player_id = (int)$player['id'];
$is_owner = ($player_id === (int)$game['owner_player_id']);
$code = $game['code'];
?>
<div class="container">
    <h1>Lobby quizu</h1>
    <p>Kod gry: <strong><?php echo htmlspecialchars($code); ?></strong></p>
    <p>Rund: <?php echo (int)$game['total_rounds']; ?>,
       czas na pytanie: <?php echo (int)$game['time_per_question']; ?> s</p>

    <h2>Gracze w lobby</h2>
    <ul id="players">
        <li>Ładowanie...</li>
    </ul>

    <?php if ($is_owner && $game['status'] === 'lobby'): ?>
        <form method="post" action="start_api.php">
            <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
            <button type="submit" class="btn-primary">Start gry</button>
        </form>
    <?php elseif ($game['status'] === 'lobby'): ?>
        <p>Czekamy aż gospodarz rozpocznie grę...</p>
    <?php endif; ?>

    <p><a href="/index.php">&larr; Wróć do strony głównej</a></p>
</div>
<script>
function refreshPlayers() {
    fetch("players_api.php?game=<?php echo $game_id; ?>")
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            const list = document.getElementById("players");
            list.innerHTML = "";
            data.players.forEach(p => {
                const li = document.createElement("li");
                li.textContent = p.nickname + " – " + p.score + " pkt";
                list.appendChild(li);
            });
            if (data.status === "running") {
                window.location.href = "game.php?game=<?php echo $game_id; ?>";
            } else if (data.status === "finished") {
                window.location.href = "finish.php?game=<?php echo $game_id; ?>";
            }
        })
        .catch(console.error);
}
document.addEventListener("DOMContentLoaded", function() {
    refreshPlayers();
    setInterval(refreshPlayers, 2000);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
