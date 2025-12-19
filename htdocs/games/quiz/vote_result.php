<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$game_id = (int)($_POST['game_id'] ?? 0);
if ($game_id <= 0) {
    die("Brak ID gry.");
}

function quiz_get_lock(string $key, int $timeoutSeconds = 0): bool
{
    global $conn;
    $k = mysqli_real_escape_string($conn, $key);
    $r = mysqli_query($conn, "SELECT GET_LOCK('$k', " . (int)$timeoutSeconds . ") AS l");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return isset($row['l']) && (int)$row['l'] === 1;
}
function quiz_release_lock(string $key): void
{
    global $conn;
    $k = mysqli_real_escape_string($conn, $key);
    @mysqli_query($conn, "SELECT RELEASE_LOCK('$k')");
}
function quiz_try_game_update(string $sql_with_timers, string $sql_fallback): void
{
    global $conn;
    if (mysqli_query($conn, $sql_with_timers)) return;
    if (mysqli_errno($conn) === 1054) {
        mysqli_query($conn, $sql_fallback);
    }
}

// pobierz grę
$gRes = mysqli_query($conn,
    "SELECT owner_player_id, current_round, total_rounds, time_per_question, mode, status
     FROM games WHERE id=$game_id LIMIT 1"
);
$game = $gRes ? mysqli_fetch_assoc($gRes) : null;
if (!$game) {
    die("Gra nie istnieje.");
}

if (($game['mode'] ?? 'classic') !== 'dynamic') {
    header("Location: game.php?game=" . $game_id);
    exit;
}

if (($game['status'] ?? '') !== 'running') {
    header("Location: lobby.php?game=" . $game_id);
    exit;
}

// tylko host
$owner_player_id = (int)$game['owner_player_id'];
// ustal playera
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, "SELECT id FROM players WHERE game_id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $guest_id = (int)($_SESSION['guest_id'] ?? 0);
    if ($guest_id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM players WHERE game_id=? AND is_guest=1 AND guest_id=?");
        mysqli_stmt_bind_param($stmt, "ii", $game_id, $guest_id);
    } else {
        $nickname = $_SESSION['guest_name'] ?? 'Gość';
        $stmt = mysqli_prepare($conn, "SELECT id FROM players WHERE game_id=? AND is_guest=1 AND nickname=?");
        mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
    }
}
mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$player || (int)$player['id'] !== $owner_player_id) {
    die("Tylko twórca gry może zakończyć głosowanie ręcznie.");
}

$current_round = (int)$game['current_round'];
$vote_round = (int)floor(($current_round - 1) / 5) + 1;
$lockKey = "quiz_vote_{$game_id}_{$vote_round}";

if (!quiz_get_lock($lockKey, 2)) {
    die("Trwa finalizacja głosowania, spróbuj ponownie.");
}

try {
    // czy już przypisane?
    $assignedRes = mysqli_query($conn,
        "SELECT COUNT(*) AS c FROM game_questions WHERE game_id=$game_id AND round_number=$current_round"
    );
    $assigned = (int)(mysqli_fetch_assoc($assignedRes)['c'] ?? 0);
    if ($assigned > 0) {
        header("Location: game.php?game=" . $game_id);
        exit;
    }

    // zwycięska kategoria
    $topRes = mysqli_query($conn,
        "SELECT category, COUNT(*) AS cnt
         FROM votes
         WHERE game_id=$game_id AND round_number=$vote_round
         GROUP BY category
         ORDER BY cnt DESC
         LIMIT 1"
    );
    $topRow = $topRes ? mysqli_fetch_assoc($topRes) : null;
    $winner_cat = $topRow['category'] ?? null;

    if ($winner_cat === null) {
        $seed = (int)($game_id * 1000 + $vote_round * 17);
        $randCatRes = mysqli_query($conn, "SELECT DISTINCT category FROM questions ORDER BY RAND($seed) LIMIT 1");
        $rc = $randCatRes ? mysqli_fetch_assoc($randCatRes) : null;
        $winner_cat = $rc['category'] ?? null;
    }

    if ($winner_cat === null) {
        die("Brak kategorii.");
    }

    // użyte pytania
    $usedIds = [];
    $usedRes = mysqli_query($conn,
        "SELECT DISTINCT question_id FROM game_questions WHERE game_id=$game_id"
    );
    if ($usedRes) {
        while ($u = mysqli_fetch_assoc($usedRes)) {
            $usedIds[] = (int)$u['question_id'];
        }
    }

    $total_rounds = (int)$game['total_rounds'];
    $to_assign = 5;
    $remaining = $total_rounds - $current_round + 1;
    if ($remaining < $to_assign) {
        $to_assign = max(0, $remaining);
    }

    if ($to_assign <= 0) {
        quiz_try_game_update(
            "UPDATE games SET status='finished', vote_ends_at=NULL, round_ends_at=NULL WHERE id=$game_id",
            "UPDATE games SET status='finished' WHERE id=$game_id"
        );
        header("Location: finish.php?game=" . $game_id);
        exit;
    }

    $notIn = '';
    if (count($usedIds) > 0) {
        $notIn = "AND id NOT IN (" . implode(',', $usedIds) . ")";
    }

    $seedQ = (int)($game_id * 100000 + $vote_round * 313);
    $qSql = "SELECT id FROM questions WHERE category='" . mysqli_real_escape_string($conn, $winner_cat) . "' $notIn ORDER BY RAND($seedQ) LIMIT $to_assign";
    $qRes = mysqli_query($conn, $qSql);
    $qIds = [];
    if ($qRes) {
        while ($qr = mysqli_fetch_assoc($qRes)) {
            $qIds[] = (int)$qr['id'];
        }
    }

    if (count($qIds) < $to_assign) {
        $need = $to_assign - count($qIds);
        $seedQ2 = (int)($seedQ + 7);
        $qSql2 = "SELECT id FROM questions WHERE category='" . mysqli_real_escape_string($conn, $winner_cat) . "' ORDER BY RAND($seedQ2) LIMIT $need";
        $qRes2 = mysqli_query($conn, $qSql2);
        if ($qRes2) {
            while ($qr = mysqli_fetch_assoc($qRes2)) {
                $qid = (int)$qr['id'];
                if (!in_array($qid, $qIds, true)) {
                    $qIds[] = $qid;
                }
            }
        }
    }

    if (count($qIds) === 0) {
        die("Brak pytań w tej kategorii.");
    }

    $ins = mysqli_prepare($conn, "INSERT INTO game_questions (game_id, question_id, round_number) VALUES (?, ?, ?)");
    $rnum = $current_round;
    foreach ($qIds as $qid) {
        $gid = $game_id;
        $qid2 = (int)$qid;
        $rn = (int)$rnum;
        mysqli_stmt_bind_param($ins, "iii", $gid, $qid2, $rn);
        mysqli_stmt_execute($ins);
        $rnum++;
    }
    mysqli_stmt_close($ins);

    // czyść głosy
    mysqli_query($conn, "DELETE FROM votes WHERE game_id=$game_id AND round_number=$vote_round");

    // ustaw deadline na pytanie + wyczyść deadline głosowania
    quiz_try_game_update(
        "UPDATE games SET vote_ends_at=NULL, round_ends_at=DATE_ADD(NOW(), INTERVAL time_per_question SECOND) WHERE id=$game_id",
        "UPDATE games SET status='running' WHERE id=$game_id"
    );

    header("Location: game.php?game=" . $game_id);
    exit;

} finally {
    quiz_release_lock($lockKey);
}
