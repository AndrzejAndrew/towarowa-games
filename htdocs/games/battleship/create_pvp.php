<?php
// games/battleship/create_pvp.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
define('BATTLESHIP_INCLUDED', true);
require_once __DIR__ . '/battleship_logic.php';

// ------------------------------------
// Dane gracza 1 (twórcy gry)
// ------------------------------------
$p1_id   = null;
$p1_name = "Gość_" . rand(1000, 9999);

if (function_exists('is_logged_in') && is_logged_in()) {
    if (!empty($_SESSION['user_id'])) {
        $p1_id = (int)$_SESSION['user_id'];
    }
    if (!empty($_SESSION['username'])) {
        $p1_name = $_SESSION['username'];
    }
}

// Czy gra ma być manualna (obaj ustawiają statki ręcznie)
$manual_mode = isset($_POST['manual_setup']) ? 1 : 0;

// Ustalamy stan P1
$p1_json  = null;
$status   = 'lobby';       // domyślnie czekamy na P2
$ms1      = $manual_mode;  // 1 = gra manualna, 0 = auto
$ms2      = $manual_mode;  // dla PVP manualnej – obaj manualni

if ($manual_mode == 0) {
    // Gra automatyczna: P1 od razu dostaje losową flotę
    $p1_state = battleship_generate_state();
    $p1_json  = json_encode($p1_state);
    // status zostaje 'lobby' – gra wystartuje po dołączeniu P2
} else {
    // Gra manualna: obaj będą ustawiać statki
    $p1_json = null;
    $status  = 'prepare_both';
}

// Kod do dołączenia do gry
$join_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// ------------------------------------
// INSERT do battleship_games
// ------------------------------------
$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO battleship_games
     (mode, player1_id, player1_name, player1_state,
      manual_setup1, manual_setup2,
      join_code, status)
     VALUES ('pvp', ?, ?, ?, ?, ?, ?, ?)"
);

mysqli_stmt_bind_param(
    $stmt,
    "issiiss",
    $p1_id,
    $p1_name,
    $p1_json,
    $ms1,
    $ms2,
    $join_code,
    $status
);

mysqli_stmt_execute($stmt);
$game_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// zapamiętujemy, że w tej grze jestem graczem 1
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['battleship_player_' . $game_id] = 1;

require_once __DIR__ . '/../../includes/header.php';
?>

<h1>Nowa gra PVP – Statki</h1>

<p>Kod gry:</p>
<div class="join-code"><?= htmlspecialchars($join_code) ?></div>

<p>Udostępnij ten kod drugiemu graczowi.</p>

<p><a href="play.php?game=<?= (int)$game_id ?>">Przejdź do poczekalni</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
