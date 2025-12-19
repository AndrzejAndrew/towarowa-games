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
    "SELECT id, code, owner_player_id, status, total_rounds, time_per_question, mode
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
if (is_logged_in()) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
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

// Jeśli ktoś wszedł w lobby bez dołączenia (np. z linku lobby), przerzuć go na join link.
if (!$player) {
    header("Location: join_game.php?code=" . urlencode($code));
    exit;
}
$player_id = (int)$player['id'];
$is_owner = ($player_id === (int)$game['owner_player_id']);
$code = $game['code'];

// linki do udostępnienia
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$base = $scheme . '://' . $host;
$joinLink = $base . '/games/quiz/join_game.php?code=' . urlencode($code);
$lobbyLink = $base . '/games/quiz/lobby.php?game=' . $game_id;

$modeLabel = (($game['mode'] ?? 'classic') === 'dynamic') ? 'Dynamiczny (głosowanie kategorii)' : 'Klasyczny';
?>
<div class="container">
    <h1>Lobby quizu</h1>

    <div class="card" style="margin-bottom: 16px;">
        <p style="margin-top:0">Kod gry: <strong><?php echo htmlspecialchars($code); ?></strong></p>
        <p style="margin-bottom: 8px;">
            Tryb: <strong><?php echo htmlspecialchars($modeLabel); ?></strong><br>
            Rund / pytań: <?php echo (int)$game['total_rounds']; ?>,
            czas: <?php echo (int)$game['time_per_question']; ?> s
        </p>

        <div style="display:grid; gap:10px; grid-template-columns: 1fr;">
            <div>
                <label style="display:block; font-weight:600; margin-bottom:6px;">Link do dołączenia (polecane)</label>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input id="joinLink" type="text" value="<?php echo htmlspecialchars($joinLink); ?>" readonly style="flex:1; min-width: 260px; padding:6px 8px; border-radius:8px; border:1px solid #374151; background:#020617; color:#e5e7eb;" />
                    <button type="button" class="btn-secondary" onclick="copyText('joinLink')">Kopiuj</button>
                </div>
            </div>
            <div>
                <label style="display:block; font-weight:600; margin-bottom:6px;">Link do lobby (dla osób już dołączonych)</label>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input id="lobbyLink" type="text" value="<?php echo htmlspecialchars($lobbyLink); ?>" readonly style="flex:1; min-width: 260px; padding:6px 8px; border-radius:8px; border:1px solid #374151; background:#020617; color:#e5e7eb;" />
                    <button type="button" class="btn-secondary" onclick="copyText('lobbyLink')">Kopiuj</button>
                </div>
            </div>
        </div>

        <p id="copyInfo" style="margin-top:10px; opacity:.8"></p>
    </div>

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
function copyText(inputId) {
    const el = document.getElementById(inputId);
    el.focus();
    el.select();
    try {
        document.execCommand('copy');
        document.getElementById('copyInfo').textContent = 'Skopiowano do schowka.';
        setTimeout(() => { document.getElementById('copyInfo').textContent = ''; }, 1500);
    } catch (e) {
        document.getElementById('copyInfo').textContent = 'Nie udało się skopiować. Zaznacz i skopiuj ręcznie.';
    }
}

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
