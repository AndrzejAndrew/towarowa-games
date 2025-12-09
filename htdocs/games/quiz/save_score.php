<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$code = strtoupper(trim($_POST['code'] ?? ''));
$question_id = (int)($_POST['question_id'] ?? 0);
$answer = trim($_POST['answer'] ?? '');

if ($code === '' || $question_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'bad_params']);
    exit;
}

// gra
$stmt = mysqli_prepare($conn, "SELECT id, status FROM games WHERE code = ?");
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$game || $game['status'] !== 'running') {
    echo json_encode(['ok' => false, 'error' => 'game_not_running']);
    exit;
}
$game_id = (int)$game['id'];

// gracz
$stmt = mysqli_prepare($conn, "SELECT id FROM players WHERE game_id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, "ii", $game_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$player) {
    echo json_encode(['ok' => false, 'error' => 'not_in_game']);
    exit;
}
$player_id = (int)$player['id'];

// pytanie
$stmt = mysqli_prepare($conn, "SELECT correct FROM questions WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $question_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$q = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$q) {
    echo json_encode(['ok' => false, 'error' => 'no_question']);
    exit;
}

$correct = $q['correct'];
$is_correct = ($answer !== '' && $answer === $correct) ? 1 : 0;

// sprawdź, czy już jest odpowiedź
$stmt = mysqli_prepare($conn, "SELECT id FROM answers WHERE game_id = ? AND player_id = ? AND question_id = ?");
mysqli_stmt_bind_param($stmt, "iii", $game_id, $player_id, $question_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$exists = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);

if ($exists) {
    echo json_encode(['ok' => true, 'info' => 'already_answered']);
    exit;
}

// wstaw odpowiedź
$stmt = mysqli_prepare($conn,
    "INSERT INTO answers (game_id, player_id, question_id, answer, is_correct)
     VALUES (?, ?, ?, ?, ?)"
);
mysqli_stmt_bind_param($stmt, "iii si", $game_id, $player_id, $question_id, $answer, $is_correct);
