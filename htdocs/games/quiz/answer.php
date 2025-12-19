<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => false, "error" => "bad_method"]);
    exit;
}

$game_id = (int)($_POST['game_id'] ?? 0);
$question_id = (int)($_POST['question_id'] ?? 0);
$answer_raw = $_POST['answer'] ?? '';
$time_left_raw = $_POST['time_left'] ?? 0;

if ($game_id <= 0 || $question_id <= 0) {
    echo json_encode(["ok" => false, "error" => "bad_params"]);
    exit;
}

// Ujednolicamy format odpowiedzi: A/B/C/D albo NULL (brak odpowiedzi)
$answer = strtoupper(trim((string)$answer_raw));
if ($answer === '') {
    $answer_db = null; // zapisujemy NULL = brak odpowiedzi
} else {
    // tylko A-D – wszystko inne traktujemy jako brak odpowiedzi
    if (!in_array($answer, ['A','B','C','D'], true)) {
        $answer = '';
        $answer_db = null;
    } else {
        $answer_db = $answer;
    }
}

// pobierz time_per_question (dla clamp)
$tpq = 0;
$gr = mysqli_query($conn, "SELECT time_per_question, status FROM games WHERE id=$game_id LIMIT 1");
$g = $gr ? mysqli_fetch_assoc($gr) : null;
if (!$g) {
    echo json_encode(["ok" => false, "error" => "game_not_found"]);
    exit;
}
if (($g['status'] ?? '') !== 'running') {
    echo json_encode(["ok" => false, "error" => "game_not_running"]);
    exit;
}
$tpq = (int)($g['time_per_question'] ?? 0);

$time_left = (int)$time_left_raw;
if ($time_left < 0) $time_left = 0;
if ($tpq > 0 && $time_left > $tpq) $time_left = $tpq;

// znajdź player_id
$player_id = null;

if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND user_id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if ($row) {
        $player_id = (int)$row['id'];
    }
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if ($row) {
        $player_id = (int)$row['id'];
    }
}


if (!$player_id) {
    echo json_encode(["ok" => false, "error" => "player_not_found"]);
    exit;
}

// sprawdź czy już odpowiedział
$check = mysqli_prepare($conn,
    "SELECT id FROM answers WHERE game_id = ? AND player_id = ? AND question_id = ? LIMIT 1"
);
mysqli_stmt_bind_param($check, "iii", $game_id, $player_id, $question_id);
mysqli_stmt_execute($check);
$checkRes = mysqli_stmt_get_result($check);
$exist = mysqli_fetch_assoc($checkRes);
mysqli_stmt_close($check);

if ($exist) {
    echo json_encode(["ok" => false, "error" => "already_answered"]);
    exit;
}

// pobierz poprawną odpowiedź
$q = mysqli_query($conn, "SELECT correct FROM questions WHERE id=$question_id LIMIT 1");
$qrow = $q ? mysqli_fetch_assoc($q) : null;
$correct = strtoupper((string)($qrow['correct'] ?? ''));

$is_correct = 0;
if ($answer_db !== null && $correct !== '' && $answer_db === $correct) {
    $is_correct = 1;
}

$stmtIns = mysqli_prepare($conn,
    "INSERT INTO answers (game_id, player_id, question_id, answer, is_correct, time_left)
     VALUES (?, ?, ?, ?, ?, ?)"
);
mysqli_stmt_bind_param($stmtIns, "iiisii", $game_id, $player_id, $question_id, $answer_db, $is_correct, $time_left);
$ok = mysqli_stmt_execute($stmtIns);
$err = mysqli_stmt_error($stmtIns);
mysqli_stmt_close($stmtIns);

if (!$ok) {
    echo json_encode(["ok" => false, "error" => "db_error", "details" => $err]);
    exit;
}

echo json_encode(["ok" => true, "is_correct" => $is_correct]);
exit;
