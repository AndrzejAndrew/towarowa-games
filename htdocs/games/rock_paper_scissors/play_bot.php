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
$rounds = max(1, min(15, (int)($_POST['rounds'] ?? 1)));
$me = current_username();
$uid = current_user_id();
$round = 1;
$p_me = 0; $p_bot = 0;
$map_pl = ['rock'=>'Kamień','paper'=>'Papier','scissors'=>'Nożyce'];
$last = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['my_move'])) {
    $round = (int)$_POST['round'];
    $p_me = (int)$_POST['p_me'];
    $p_bot = (int)$_POST['p_bot'];
    $my_move = $_POST['my_move'];
    $allowed = ['rock','paper','scissors'];
    if (in_array($my_move,$allowed)) {
        $bot_move = $allowed[array_rand($allowed)];
        function outcome_pkn_local($a,$b){
            if ($a===$b) return 0;
            if ($a==='rock' && $b==='scissors') return 1;
            if ($a==='paper' && $b==='rock') return 1;
            if ($a==='scissors' && $b==='paper') return 1;
            return 2;
        }
        $res_r = outcome_pkn_local($my_move,$bot_move);
        if ($res_r===1) $p_me++; elseif ($res_r===2) $p_bot++;
        $round++;
        $last = ['my'=>$my_move,'bot'=>$bot_move,'res'=>$res_r];
    }
    $rounds = (int)$_POST['rounds'];
}

$finished = ($round > $rounds);
if ($finished && $uid > 0) {
    $chk = mysqli_query($conn, "SELECT user_id FROM pkn_stats WHERE user_id = $uid");
    if (!mysqli_fetch_assoc($chk)) {
        mysqli_query($conn, "INSERT INTO pkn_stats (user_id) VALUES ($uid)");
    }
    mysqli_query($conn, "UPDATE pkn_stats SET games_total = games_total + 1, games_bot_total = games_bot_total + 1 WHERE user_id = $uid");
    if ($p_me > $p_bot) {
        mysqli_query($conn, "UPDATE pkn_stats SET games_won = games_won + 1, games_bot_won = games_bot_won + 1 WHERE user_id = $uid");
    } elseif ($p_me < $p_bot) {
        mysqli_query($conn, "UPDATE pkn_stats SET games_lost = games_lost + 1 WHERE user_id = $uid");
    }
}
?>
<div class="container" style="max-width:760px;margin:2rem auto;">
    <h1>PKN – Gra z botem</h1>
    <p><strong>Runda:</strong> <?php echo min($round,$rounds); ?> / <?php echo $rounds; ?></p>
    <p><strong>Wynik:</strong> <?php echo $p_me.' : '.$p_bot; ?></p>
    <?php if (!empty($last)) : ?>
        <p>Ostatnia runda: ty – <strong><?php echo $map_pl[$last['my']]; ?></strong>, bot – <strong><?php echo $map_pl[$last['bot']]; ?></strong>.
        <?php
            if ($last['res']===0) echo ' Remis.';
            elseif ($last['res']===1) echo ' Punkt dla Ciebie.';
            else echo ' Punkt dla bota.';
        ?>
        </p>
    <?php endif; ?>

    <?php if (!$finished): ?>
    <form method="POST">
        <input type="hidden" name="rounds" value="<?php echo $rounds; ?>">
        <input type="hidden" name="round" value="<?php echo $round; ?>">
        <input type="hidden" name="p_me" value="<?php echo $p_me; ?>">
        <input type="hidden" name="p_bot" value="<?php echo $p_bot; ?>">
        <button name="my_move" value="rock">Kamień</button>
        <button name="my_move" value="paper">Papier</button>
        <button name="my_move" value="scissors">Nożyce</button>
    </form>
    <?php else: ?>
        <h2>Koniec gry</h2>
        <p><strong>Wynik końcowy:</strong> <?php echo $p_me.' : '.$p_bot; ?></p>
        <p><a href="index.php">Zagraj ponownie</a> | <a href="ranking.php">Ranking</a></p>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
