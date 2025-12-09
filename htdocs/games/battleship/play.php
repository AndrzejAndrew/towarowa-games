<?php
// games/battleship/play.php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

// kim jestem?
$sessionKey = 'battleship_player_' . $game_id;
$myPlayer   = $_SESSION[$sessionKey] ?? "spectator";

// pobranie gry (tylko po to, by wiedzieć czy istnieje)
$stmt = mysqli_prepare($conn, "SELECT id FROM battleship_games WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $game_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) {
    die("Nie znaleziono gry.");
}

require_once __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="style.css">

<div 
    id="battleship-root"
    data-game-id="<?= (int)$game_id ?>"
    data-my-player="<?= htmlspecialchars($myPlayer) ?>"
>
    <!-- zostanie uzupełnione przez JS -->
</div>

<script src="script.js"></script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
