<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe żądanie']);
    exit;
}

$game_id = (int)($_POST['game_id'] ?? 0);
$round_number = (int)($_POST['round_number'] ?? 0);
$category = trim($_POST['category'] ?? '');

if ($game_id <= 0 || $round_number <= 0 || $category === '') {
    echo json_encode(['ok' => false, 'error' => 'Brak danych']);
    exit;
}

// znajdź gracza
$is_guest = is_logged_in() ? 0 : 1;
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}
mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$player) {
    echo json_encode(['ok' => false, 'error' => 'Nie jesteś uczestnikiem tej gry.']);
    exit;
}
$player_id = (int)$player['id'];

$category_esc = mysqli_real_escape_string($conn, $category);

// nadpisujemy ewentualny wcześniejszy głos tego gracza w tej rundzie
mysqli_query($conn,
    "DELETE FROM votes
     WHERE game_id = $game_id AND player_id = $player_id AND round_number = $round_number"
);

mysqli_query($conn,
    "INSERT INTO votes (game_id, player_id, round_number, category)
     VALUES ($game_id, $player_id, $round_number, '$category_esc')"
);

echo json_encode(['ok' => true]);
