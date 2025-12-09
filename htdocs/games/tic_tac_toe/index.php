<?php
require_once __DIR__ . '/ttt_boot.php';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container" style="max-width:720px;margin:20px auto;">
  <h1>Kółko i krzyżyk (online)</h1>

  <h2>Nowa gra</h2>

  <!-- Gra z botem -->
  <form method="post" action="create.php" style="margin:12px 0;">
    <input type="hidden" name="mode" value="bot">
    <button type="submit">🤖 Zagraj z botem</button>
  </form>

  <!-- Gra z drugim graczem -->
  <form method="post" action="create.php" style="margin:12px 0;">
    <input type="hidden" name="mode" value="pvp">
    <button type="submit">👥 Utwórz pokój dla 2 graczy</button>
  </form>

  <h2>Dołącz do istniejącej gry</h2>
  <form method="get" action="room.php" style="margin:12px 0;">
    <label>Kod gry:</label>
    <input type="text" name="code" maxlength="10" required placeholder="np. 4FJ9K">
    <button type="submit">Dołącz</button>
  </form>

  <p style="margin-top:20px;">
    <a href="rank.php">🏆 Ranking kółka i krzyżyka</a>
  </p>
</div>
