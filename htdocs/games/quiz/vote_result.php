<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$game_id = (int)($_POST['game_id'] ?? 0);
if ($game_id <= 0) {
    die("Brak ID gry.");
}

$res = mysqli_query($conn,
    "SELECT code, total_rounds, current_round, mode, owner_player_id
     FROM games WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);
if (!$game) {
    die("Nie ma takiej gry.");
}
if ($game['mode'] !== 'dynamic') {
    header("Location: game.php?game=" . $game_id);
    exit;
}

// sprawdź, czy to gospodarz
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

if (!$player || (int)$player['id'] !== (int)$game['owner_player_id']) {
    die("Tylko gospodarz może zakończyć głosowanie.");
}

$current_round = (int)$game['current_round'];
$total_rounds  = (int)$game['total_rounds'];

// wyliczamy numer tury głosowania
$vote_round = (int)floor(($current_round) / 5) + 1;
if ($current_round === 0) $vote_round = 1;

// policz głosy
$res = mysqli_query($conn,
    "SELECT category, COUNT(*) AS c
     FROM votes
     WHERE game_id = $game_id AND round_number = $vote_round
     GROUP BY category
     ORDER BY c DESC"
);
$winner_cat = null;
$max_c = 0;
$winners = [];
while ($row = mysqli_fetch_assoc($res)) {
    if ($row['c'] > $max_c) {
        $max_c = $row['c'];
        $winners = [$row['category']];
    } elseif ($row['c'] === $max_c) {
        $winners[] = $row['category'];
    }
}

if (empty($winners)) {
    // nikt nie zagłosował – awaryjnie losujemy kategorię z bazy
    $res2 = mysqli_query($conn,
        "SELECT DISTINCT category
         FROM questions
         WHERE category <> ''
         ORDER BY RAND()
         LIMIT 1"
    );
    $row2 = mysqli_fetch_assoc($res2);
    if (!$row2) {
        die("Brak kategorii i pytań w bazie.");
    }
    $winner_cat = $row2['category'];
} else {
    // jeśli kilka z takim samym wynikiem – losujemy jedną z nich
    $winner_cat = $winners[array_rand($winners)];
}

// obliczamy ile pytań mamy w tej turze (maks 5, ale może być mniej na końcu gry)
$questions_per_round = 5;
$remaining = $total_rounds - $current_round + 1;
$to_assign = min($questions_per_round, $remaining);

// zbierz już użyte pytania w tej grze, żeby ich nie powtarzać
$used = [];
$resu = mysqli_query($conn,
    "SELECT question_id FROM game_questions WHERE game_id = $game_id"
);
while ($r = mysqli_fetch_assoc($resu)) {
    $used[] = (int)$r['question_id'];
}
$used_list = '';
if (!empty($used)) {
    $used_list = implode(',', $used);
}

// losujemy pytania z kategorii zwycięskiej
$winner_cat_esc = mysqli_real_escape_string($conn, $winner_cat);

$sql = "
    SELECT id FROM questions
    WHERE category = '$winner_cat_esc'
";
if ($used_list !== '') {
    $sql .= " AND id NOT IN ($used_list)";
}
$sql .= " ORDER BY RAND() LIMIT $to_assign";

$resq = mysqli_query($conn, $sql);

$round_num = $current_round;
while ($q = mysqli_fetch_assoc($resq)) {
    $qid = (int)$q['id'];
    mysqli_query($conn,
        "INSERT INTO game_questions (game_id, question_id, round_number)
         VALUES ($game_id, $qid, $round_num)"
    );
    $round_num++;
}

// ustaw current_round na aktualny (nie zmieniamy tutaj – pierwszy z tej tury)
if ($current_round <= 0) {
    $current_round = 1;
}
mysqli_query($conn,
    "UPDATE games
     SET current_round = $current_round
     WHERE id = $game_id"
);

// czyścimy głosy z tej tury (żeby nie mieszały się w kolejnych)
mysqli_query($conn,
    "DELETE FROM votes WHERE game_id = $game_id AND round_number = $vote_round"
);

header("Location: game.php?game=" . $game_id);
exit;
