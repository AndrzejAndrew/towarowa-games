<?php
require_once __DIR__ . '/ttt_boot.php';
require_once __DIR__ . '/../../includes/header.php';

$code = $_GET['code'] ?? '';
$game = ttt_fetch_game_by_code($code, $conn);
if (!$game) {
    echo "<div class='container'><p>Nie znaleziono gry.</p></div>";
    exit;
}

$me    = ttt_current_player_id();
$vsBot = !empty($game['vs_bot']);

// dołączanie drugiego gracza tylko w trybie PVP
if (!$vsBot && $game['status'] === 'waiting' && $game['player_x'] != $me && empty($game['player_o'])) {
    $esc = mysqli_real_escape_string($conn, $game['code']);
    mysqli_query($conn, "UPDATE ttt_games SET player_o = {$me}, status = 'playing' WHERE code = '{$esc}'");
    $game = ttt_fetch_game_by_code($code, $conn);
}

// symbol gracza
$mySymbol = null;
if ((int)$game['player_x'] === $me) {
    $mySymbol = 'X';
} elseif (!$vsBot && (int)$game['player_o'] === $me) {
    $mySymbol = 'O';
}

$playerXName = $game['player_x'] ? ttt_display_name((int)$game['player_x'], $conn) : '—';
if ($vsBot) {
    $playerOName = 'Bot';
} else {
    $playerOName = $game['player_o'] ? ttt_display_name((int)$game['player_o'], $conn) : '—';
}

$modeLabel = $vsBot ? 'Grasz z botem' : 'Gra dwóch graczy';
?>
<link rel="stylesheet" href="style.css">
<div class="container ttt-wrapper">
  <div class="ttt-head">
    <h1>Kółko i krzyżyk</h1>
    <div class="ttt-meta">
      <div>Kod gry: <strong><?php echo htmlspecialchars($game['code']); ?></strong></div>
      <div>Tryb: <strong><?php echo htmlspecialchars($modeLabel); ?></strong></div>
      <div>Status: <span id="status"><?php echo htmlspecialchars($game['status']); ?></span></div>
      <div>Gracze:
        X = <strong id="pX"><?php echo htmlspecialchars($playerXName); ?></strong>,
        O = <strong id="pO"><?php echo htmlspecialchars($playerOName); ?></strong>
      </div>
      <div>Ja gram: <strong><?php echo $mySymbol ? $mySymbol : 'obserwator'; ?></strong></div>
    </div>
  </div>

  <div id="board" class="ttt-board" data-code="<?php echo htmlspecialchars($game['code']); ?>"></div>

  <div class="ttt-actions">
    <a class="btn" href="index.php">← Wróć</a>
    <a class="btn" href="rank.php">🏆 Ranking</a>
  </div>
</div>

<script>
  const MY_SYMBOL = <?php echo json_encode($mySymbol); ?>;
</script>
<script src="script.js?v=3"></script>

