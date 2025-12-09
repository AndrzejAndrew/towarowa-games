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

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) { header('Location: index.php'); exit; }

$res = mysqli_query($conn, "SELECT * FROM pkn_games WHERE id = $game_id");
$game = mysqli_fetch_assoc($res);
if (!$game) { die("Nie znaleziono gry."); }

$me_name = current_username();
$uid      = current_user_id();

// rozpoznanie właściciela – najpierw po ID, potem po nazwie (dla gościa)
$is_owner = false;
if (!empty($game['player1_id']) && $game['player1_id'] > 0 && $uid > 0) {
    $is_owner = ((int)$game['player1_id'] === (int)$uid);
} else {
    $is_owner = ($game['player1_name'] === $me_name);
}
?>
<div class="container" style="max-width:760px;margin:2rem auto;">
    <h1>PKN – Lobby</h1>
    <p>Kod pokoju: <strong style="font-family:monospace;"><?php echo htmlspecialchars($game['code']); ?></strong></p>
    <p>Link do gry: <input type="text" value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']).'/join.php?code='.$game['code']); ?>" style="width:100%;" onclick="this.select();" readonly></p>

    <div class="card" style="padding:16px;border:1px solid #333;border-radius:12px;">
        <p><strong>Gracz 1:</strong> <?php echo htmlspecialchars($game['player1_name']); ?></p>
        <p><strong>Gracz 2:</strong> <span id="player2"><?php echo $game['player2_name'] ? htmlspecialchars($game['player2_name']) : '— oczekiwanie —'; ?></span></p>
        <p><strong>Liczba rund:</strong> <?php echo (int)$game['rounds_total']; ?></p>
        <p><strong>Status:</strong> <span id="status"><?php echo htmlspecialchars($game['status']); ?></span></p>

        <?php if ($is_owner): ?>
            <form method="POST" action="start_game.php"
                  id="startForm"
                  style="<?php echo ($game['status']==='waiting' && $game['player2_name']) ? '' : 'display:none;'; ?>">
                <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
                <button type="submit">Start gry</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
const gameId   = <?php echo $game_id; ?>;
const isOwner  = <?php echo $is_owner ? 'true' : 'false'; ?>;
const startForm = document.getElementById('startForm');

setInterval(async () => {
    try {
        const r = await fetch('poll_lobby.php?game=' + gameId);
        const data = await r.json();

        document.getElementById('status').textContent = data.status;

        if (data.player2_name) {
            document.getElementById('player2').textContent = data.player2_name;
        }

        // automatyczne pokazanie START gdy dołączy drugi gracz
        if (isOwner && startForm) {
            if (data.status === 'waiting' && data.player2_name) {
                startForm.style.display = '';
            } else {
                startForm.style.display = 'none';
            }
        }

        if (data.redirect) {
            window.location.href = data.redirect;
        }
    } catch (e) {}
}, 1200);
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
