<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$game_id      = (int)($_POST['game_id'] ?? 0);
$question_id  = (int)($_POST['question_id'] ?? 0);
$answer_raw   = $_POST['answer'] ?? '';
$answer       = strtoupper(trim($answer_raw));

$time_left    = (int)($_POST['time_left'] ?? 0);   // czas, który pozostał na zegarze

if ($game_id <= 0 || $question_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'bad_params']);
    exit;
}

// Pobierz gracza (zalogowany lub gość)
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id, score FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? '';
    $stmt = mysqli_prepare($conn,
        "SELECT id, score FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}

mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$player) {
    echo json_encode(['ok' => false, 'error' => 'player_not_found']);
    exit;
}

$player_id = (int)$player['id'];

// Pobierz poprawną odpowiedź
$stmt = mysqli_prepare($conn,
    "SELECT correct FROM questions WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $question_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$q = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$q) {
    echo json_encode(['ok' => false, 'error' => 'question_not_found']);
    exit;
}

$correct = strtoupper($q['correct']);
$is_correct = ($answer !== '' && $answer === $correct) ? 1 : 0;

// Sprawdź czy już odpowiedział
$stmt = mysqli_prepare($conn,
    "SELECT id FROM answers WHERE game_id = ? AND player_id = ? AND question_id = ?"
);
mysqli_stmt_bind_param($stmt, "iii", $game_id, $player_id, $question_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$exists = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);

if ($exists) {
    echo json_encode(['ok' => true, 'info' => 'already_answered']);
    exit;
}

// Zapisz odpowiedź + czas
$stmt = mysqli_prepare($conn,
    "INSERT INTO answers (game_id, player_id, question_id, answer, is_correct, time_left)
     VALUES (?, ?, ?, ?, ?, ?)"
);
mysqli_stmt_bind_param($stmt, "iiisii",
    $game_id, $player_id, $question_id, $answer, $is_correct, $time_left
);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// ---------------------------------------
//  NALICZ PUNKTY WG SYSTEMU A:
//  100 pkt za poprawną odpowiedź
//  + 10 pkt za każdą pozostałą sekundę
// ---------------------------------------

if ($is_correct) {
    $base_points  = 100;
    $bonus_points = max(0, $time_left * 10);

    $total_add = $base_points + $bonus_points;

    mysqli_query($conn,
        "UPDATE players SET score = score + $total_add WHERE id = $player_id"
    );
}

echo json_encode(['ok' => true]);
