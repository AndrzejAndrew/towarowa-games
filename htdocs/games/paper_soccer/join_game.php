<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$code = $_GET['code'] ?? null;

if (!$code) {
    ?>
    <!-- Twój HTML formularza – bez zmian -->
    <?php
    exit;
}

$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE code=? AND mode='pvp'");
$stmt->bind_param("s", $code);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) die("Nie ma takiej gry.");

if ($game['player2_id'] != 0 && $game['player2_id'] !== null) {
    die("Gra jest już pełna.");
}

$player2_id   = is_logged_in() ? (int)$_SESSION['user_id'] : (int)$_SESSION['guest_id'];
$player2_name = is_logged_in() ? $_SESSION['username'] : $_SESSION['guest_name'];

$stmt = $conn->prepare("
    UPDATE paper_soccer_games 
    SET player2_id=?, player2_name=?, status='playing'
    WHERE id=?
");
$stmt->bind_param("isi", $player2_id, $player2_name, $game['id']);
$stmt->execute();
$stmt->close();

header("Location: play.php?game_id=".$game['id']);
exit;
