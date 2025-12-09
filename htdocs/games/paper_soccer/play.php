<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$game_id = (int)($_GET["game_id"] ?? 0);
if ($game_id <= 0) {
    echo "Brak ID gry.";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Pobranie gry
$stmt = $conn->prepare("SELECT * FROM paper_soccer_games WHERE id=?");
$stmt->bind_param("i", $game_id);
$stmt->execute();
$game = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$game) {
    echo "Nie znaleziono gry.";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// -----------------------------------------------------
// USTALENIE, KTÓRY GRACZ TO MY — POPRAWNA WERSJA
// -----------------------------------------------------

$my_id =
    is_logged_in()
        ? (int)($_SESSION['user_id'] ?? 0)
        : (int)($_SESSION['guest_id'] ?? 0);

$my_player = 0;

if ($my_id === (int)$game['player1_id']) {
    $my_player = 1;
} elseif ($my_id === (int)$game['player2_id']) {
    $my_player = 2;
}

if ($my_player === 0) {
    die("Nie jesteś uczestnikiem tej gry.");
}

// -----------------------------------------------------
// LOBBY PVP – jeśli status = waiting i brak gracza 2
// -----------------------------------------------------
if ($game['mode'] === 'pvp' && $game['status'] === 'waiting' && $game['player2_id'] == 0) {
    ?>
    <style>
        body {
            background: #222;
            color: white;
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 30px;
        }
        .box {
            background: #333;
            padding: 20px;
            border: 2px solid #555;
            border-radius: 10px;
            display: inline-block;
            margin-top: 30px;
        }
        .code {
            font-size: 32px;
            color: #4CAF50;
            font-weight: bold;
            margin: 10px 0;
        }
    </style>

    <meta http-equiv="refresh" content="2">

    <h1>👥 Oczekiwanie na przeciwnika</h1>

    <div class="box">
        <p>Przekaż ten kod znajomemu:</p>
        <div class="code"><?= htmlspecialchars($game["code"]) ?></div>

        <button onclick="
            navigator.clipboard.writeText('<?= htmlspecialchars($game["code"]) ?>')
            .then(() => alert('Skopiowano kod!'));
        ">Kopiuj kod</button>

        <p style='margin-top:20px;color:#bbb;'>Oczekiwanie...</p>
    </div>

    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}
?>

<link rel="stylesheet" href="style.css">

<style>
    .game-container {
        max-width: 900px;
        margin: 20px auto;
        text-align: center;
    }
    .player-panel {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        margin-bottom: 10px;
        color: #fff;
    }
    .ps-player {
        flex: 1;
    }
    .ps-player-label {
        font-size: 14px;
        opacity: 0.8;
    }
    .ps-player-name {
        font-size: 18px;
        font-weight: bold;
    }
    .ps-player-goal {
        font-size: 13px;
        opacity: 0.8;
        margin-top: 4px;
    }
    .ps-score {
        font-size: 28px;
        font-weight: bold;
        min-width: 80px;
    }
    #ps-turn-info {
        margin-top: 8px;
        margin-bottom: 8px;
        font-size: 18px;
        color: #fff;
    }
    #ps-rematch {
        margin-top: 12px;
        padding: 8px 16px;
        font-size: 16px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        display: none;
    }
</style>

<div class="game-container">

    <h1>Papierowa piłka nożna</h1>

    <!-- Panel graczy + wynik -->
    <div id="ps-player-info" class="player-panel">
        <div class="ps-player">
            <div class="ps-player-label">Gracz 1</div>
            <div class="ps-player-name" id="ps-p1-name">Ładowanie...</div>

            <!-- POPRAWIONE STRONY BRAMEK -->
            <div class="ps-player-goal" id="ps-p1-goal">
                <?= ($my_player == 1)
                    ? "Atakujesz bramkę na dole"
                    : "Przeciwnik atakuje bramkę na dole" ?>
            </div>
        </div>

        <div class="ps-score" id="ps-score">0 : 0</div>

        <div class="ps-player">
            <div class="ps-player-label">Gracz 2</div>
            <div class="ps-player-name" id="ps-p2-name">Ładowanie...</div>

            <!-- POPRAWIONE STRONY BRAMEK -->
            <div class="ps-player-goal" id="ps-p2-goal">
                <?= ($my_player == 2)
                    ? "Atakujesz bramkę u góry"
                    : "Przeciwnik atakuje bramkę u góry" ?>
            </div>
        </div>
    </div>

    <div id="ps-turn-info">Trwa ładowanie gry...</div>

    <canvas
        id="ps-board"
        width="600"
        height="800"
        data-game-id="<?= $game_id ?>"
        data-player="<?= $my_player ?>"
        data-mode="<?= htmlspecialchars($game['mode']) ?>"
        data-bot-diff="<?= (int)($game['bot_difficulty'] ?? 1) ?>"
    ></canvas>

    <button id="ps-rematch">Rewanż</button>

</div>

<script src="script.js"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
