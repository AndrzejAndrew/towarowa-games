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
$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) { header('Location: index.php'); exit; }
$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$g = mysqli_fetch_assoc($res);
if (!$g) { die('Gra nie istnieje.'); }
$me = current_username();
$mySlot = ($g['player1_name'] === $me) ? 1 : (($g['player2_name'] === $me) ? 2 : 0);
if ($mySlot === 0) { die('Nie jesteś uczestnikiem tej gry.'); }
?>
<div class="container" style="max-width:760px;margin:2rem auto;">
    <h1>PKN – Gra online</h1>
    <div class="card" style="padding:16px;border:1px solid #333;border-radius:12px;">
        <p><strong>Runda:</strong> <span id="round"><?php echo (int)$g['current_round']; ?></span> / <?php echo (int)$g['rounds_total']; ?></p>
        <p><strong>Wynik:</strong> <span id="score"><?php echo (int)$g['p1_score'].' : '.(int)$g['p2_score']; ?></span></p>
        <div id="choices" style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0;">
            <button data-m="rock">Kamień</button>
            <button data-m="paper">Papier</button>
            <button data-m="scissors">Nożyce</button>
        </div>
        <p id="state">Wybierz ruch…</p>
    </div>
</div>
<script>
const gameId = <?php echo $game_id; ?>;
const mySlot = <?php echo $mySlot; ?>;

document.querySelectorAll('#choices button').forEach(b => {
    b.addEventListener('click', async () => {
        const move = b.dataset.m;
        const r = await fetch('submit_move.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({game_id: gameId, move, slot: mySlot})
        });
        const data = await r.json();
        if (data.error) {
            document.getElementById('state').textContent = 'Błąd: ' + data.error;
            return;
        }
        document.getElementById('state').textContent = 'Zagrano: ' + move + '. Czekaj na przeciwnika…';
    });
});

async function poll() {
    try {
        const r = await fetch('poll_game.php?game=' + gameId + '&slot=' + mySlot);
        const data = await r.json();
        if (data.redirect) { window.location.href = data.redirect; return; }
        document.getElementById('round').textContent = data.current_round;
        document.getElementById('score').textContent = data.p1_score + ' : ' + data.p2_score;
        if (data.round_result) {
            document.getElementById('state').textContent = data.round_result_text;
        }
    } catch (e) {}
    setTimeout(poll, 1100);
}
poll();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
