<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

// gra – pobieramy też flagę discord_finish_sent
$res = mysqli_query($conn,
    "SELECT code, discord_finish_sent 
     FROM games 
     WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);
if (!$game) {
    die("Nie ma takiej gry.");
}
$already_notified = (int)($game['discord_finish_sent'] ?? 0);

// gracze
$res = mysqli_query($conn,
    "SELECT id, user_id, nickname, score, is_guest
     FROM players
     WHERE game_id = $game_id
     ORDER BY score DESC, id ASC"
);
$players = [];
while ($row = mysqli_fetch_assoc($res)) {
    $players[] = $row;
}

// zapisz historię tylko dla zalogowanych użytkowników
foreach ($players as $p) {
    if ((int)$p['is_guest'] === 1 || (int)$p['user_id'] <= 0) continue;

    $uid = (int)$p['user_id'];
    $result = 'Quiz: ' . (int)$p['score'] . ' pkt (kod: ' . $game['code'] . ')';

    mysqli_query($conn,
        "INSERT INTO game_history (user_id, game_type, result)
         VALUES ($uid, 'quiz', '" . mysqli_real_escape_string($conn, $result) . "')"
    );
}

/*
    ============================
    POWIADOMIENIE DISCORD – WYNIKI QUIZU
    (tylko raz na grę)
    ============================
*/
if (!$already_notified && !empty($players)) {
    try {
        $winnerNick  = $players[0]['nickname'] ?? 'brak';
        $winnerScore = (int)($players[0]['score'] ?? 0);

        // zbuduj top 5 wyników
        $lines = [];
        $place = 1;
        foreach ($players as $p) {
            $lines[] = $place . ". " . $p['nickname'] . " – " . (int)$p['score'] . " pkt";
            $place++;
            if ($place > 5) break;
        }

        $discordMessage = "🏆 **Zakończono grę QUIZ**\n"
            . "Kod gry: **{$game['code']}** (ID: {$game_id})\n"
            . "Zwycięzca: **{$winnerNick}** ({$winnerScore} pkt)\n"
            . "Liczba graczy: " . count($players) . "\n\n"
            . "Top wyniki:\n" . implode("\n", $lines);

        discord_send(
            'winner',
            $discordMessage,
            $DISCORD_META['winner']['username'] ?? 'Wyniki',
            $DISCORD_META['winner']['color'] ?? 0x2ECC71
        );

        // ustawiamy flagę, żeby kolejne wejścia na finish.php NIC już nie wysyłały
        mysqli_query($conn,
            "UPDATE games 
             SET discord_finish_sent = 1 
             WHERE id = $game_id"
        );
    } catch (Throwable $e) {
        // ewentualne błędy przechwyci globalny handler, grze nie przeszkadzamy
    }
}
?>
<div class="container">
    <h1>Wyniki quizu</h1>
    <p>Kod gry: <strong><?php echo htmlspecialchars($game['code']); ?></strong></p>

    <!-- Ranking -->
    <table class="table">
        <thead>
        <tr>
            <th>Miejsce</th>
            <th>Gracz</th>
            <th>Punkty</th>
        </tr>
        </thead>
        <tbody>
        <?php $place = 1; ?>
        <?php
        $current_uid = is_logged_in() ? (int)$_SESSION['user_id'] : 0;
        foreach ($players as $p):
            $highlight = ($current_uid > 0 && $p['user_id'] == $current_uid);
        ?>
            <tr<?php if ($highlight) echo ' class="highlight"'; ?>>
                <td><?php echo $place++; ?></td>
                <td><?php echo htmlspecialchars($p['nickname']); ?></td>
                <td><?php echo (int)$p['score']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <hr>
    <h2>Szczegółowe odpowiedzi</h2>
    <p>Pełna tabela odpowiedzi graczy dla każdego pytania.</p>

<?php
// Pobieramy wszystkie pytania w kolejności rund
$sql_q = "
    SELECT gq.round_number, q.id, q.question, q.correct, q.a, q.b, q.c, q.d
    FROM game_questions gq
    JOIN questions q ON q.id = gq.question_id
    WHERE gq.game_id = $game_id
    ORDER BY gq.round_number
";
$res_q = mysqli_query($conn, $sql_q);

while ($qrow = mysqli_fetch_assoc($res_q)):
    $qid          = (int)$qrow['id'];
    $round        = (int)$qrow['round_number'];
    $question     = $qrow['question'];
    $correct_key  = strtolower($qrow['correct']);
    $correct_text = $qrow[$correct_key];
?>

    <div class="question-block">
        <h3><?php echo $round . ". " . htmlspecialchars($question); ?></h3>
        <p><strong>Poprawna odpowiedź:</strong> <?php echo htmlspecialchars($correct_text); ?></p>

        <table class="table">
            <tr>
                <th>Gracz</th>
                <th>Odpowiedź</th>
                <th>Punkty zdobyte</th>
                <th>Poprawna?</th>
            </tr>

<?php
    // Pobieramy odpowiedzi graczy do tego pytania
    $sql_a = "
        SELECT p.nickname, a.answer, a.is_correct, a.time_left
        FROM answers a
        JOIN players p ON p.id = a.player_id
        WHERE a.game_id = $game_id AND a.question_id = $qid
        ORDER BY p.nickname
    ";
    $res_a = mysqli_query($conn, $sql_a);

    while ($arow = mysqli_fetch_assoc($res_a)):
        $nickname  = htmlspecialchars($arow['nickname']);
        $ans_key   = strtolower($arow['answer']);
        $ans_text  = $ans_key ? $qrow[$ans_key] : "-";
        $correct   = (int)$arow['is_correct'];
        $time_left = (int)$arow['time_left'];

        // Liczymy punkty jak w answer.php
        $earned_points = $correct ? (100 + $time_left * 10) : 0;

        $ok    = $correct ? "✔" : "✖";
        $color = $correct ? "#22c55e" : "#ef4444";
?>

            <tr>
                <td><?php echo $nickname; ?></td>
                <td><?php echo htmlspecialchars($ans_text); ?></td>
                <td><strong><?php echo $earned_points; ?></strong></td>
                <td style="color: <?php echo $color; ?>; font-weight:bold;"><?php echo $ok; ?></td>
            </tr>

<?php endwhile; ?>

        </table>
    </div>
    <br>

<?php endwhile; ?>

    <p><a href="index.php">Zagraj ponownie w quiz</a></p>
    <p><a href="/index.php">&larr; Wróć do strony głównej</a></p>
</div>

<?php
require_once __DIR__ . '/../../includes/stats.php';
// STATYSTYKI QUIZU
if (!empty($players)) {

    // Zwycięzca — to pierwszy gracz z posortowanej listy
    $winner_user_id = (int)$players[0]['user_id'];

    foreach ($players as $p) {
        $uid = (int)$p['user_id'];
        if ($uid <= 0) continue; // pomiń gości

        $score = (int)$p['score'];

        if ($uid === $winner_user_id) {
            stats_register_result($uid, 'quiz', 'win', $score);
        } else {
            stats_register_result($uid, 'quiz', 'loss', $score);
        }
    }
}

require_once __DIR__ . '/../../includes/footer.php';

