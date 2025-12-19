<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    die("Brak ID gry.");
}

// pobierz grę
$gameRes = mysqli_query($conn,
    "SELECT id, status, mode, current_round, total_rounds, time_per_question, vote_ends_at, owner_player_id
     FROM games WHERE id = $game_id LIMIT 1"
);
$game = mysqli_fetch_assoc($gameRes);
if (!$game) {
    die("Nie ma takiej gry.");
}

if (($game['mode'] ?? 'classic') !== 'dynamic') {
    header("Location: game.php?game=" . $game_id);
    exit;
}

if ($game['status'] === 'lobby') {
    header("Location: lobby.php?game=" . $game_id);
    exit;
}
if ($game['status'] === 'finished') {
    header("Location: finish.php?game=" . $game_id);
    exit;
}

$current_round = (int)$game['current_round'];
$time_per_q = (int)$game['time_per_question'];
$owner_player_id = (int)$game['owner_player_id'];

// numer głosowania: rundy 1-5 => 1, 6-10 => 2, itd.
$vote_round = (int)floor(($current_round - 1) / 5) + 1;

// ustal playera
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $guest_id = (int)($_SESSION['guest_id'] ?? 0);
    if ($guest_id > 0) {
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND guest_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "ii", $game_id, $guest_id);
    } else {
        $nickname = $_SESSION['guest_name'] ?? 'Gość';
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
        );
        mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
    }
}

mysqli_stmt_execute($stmt);
$res2 = mysqli_stmt_get_result($stmt);
$player = mysqli_fetch_assoc($res2);
mysqli_stmt_close($stmt);

if (!$player) {
    die("Nie jesteś uczestnikiem tej gry.");
}
$player_id = (int)$player['id'];
$is_host = ($player_id === $owner_player_id);

// sprawdź czy już głosował
$already_vote = null;
$stmtV = mysqli_prepare($conn,
    "SELECT category FROM votes WHERE game_id=? AND player_id=? AND round_number=? LIMIT 1"
);
mysqli_stmt_bind_param($stmtV, "iii", $game_id, $player_id, $vote_round);
mysqli_stmt_execute($stmtV);
$vRes = mysqli_stmt_get_result($stmtV);
$vRow = mysqli_fetch_assoc($vRes);
mysqli_stmt_close($stmtV);
if ($vRow) {
    $already_vote = $vRow['category'] ?? null;
}

// Ujednolicone kategorie dla wszystkich: deterministyczny seed zależny od game_id i vote_round
$seed = (int)($game_id * 1000 + $vote_round * 17);
$resCats = mysqli_query($conn,
    "SELECT DISTINCT category FROM questions ORDER BY RAND($seed) LIMIT 5"
);
$cats = [];
if ($resCats) {
    while ($r = mysqli_fetch_assoc($resCats)) {
        if (!empty($r['category'])) {
            $cats[] = $r['category'];
        }
    }
}

// czas do końca głosowania wg serwera (fallback: pełny time_per_question)
$voteEndsAt = $game['vote_ends_at'] ?? null;

// jeśli deadline nieustawiony – ustaw go przy pierwszym wejściu w fazę głosowania (wymaga migracji DB)
if (empty($voteEndsAt)) {
    @mysqli_query($conn, "UPDATE games SET vote_ends_at=DATE_ADD(NOW(), INTERVAL time_per_question SECOND) WHERE id = $game_id");
    $tmpRes = mysqli_query($conn, "SELECT vote_ends_at FROM games WHERE id = $game_id LIMIT 1");
    $tmpRow = $tmpRes ? mysqli_fetch_assoc($tmpRes) : null;
    if ($tmpRow && !empty($tmpRow['vote_ends_at'])) {
        $voteEndsAt = $tmpRow['vote_ends_at'];
    }
}

$voteTimeLeftInitial = $time_per_q;
if (!empty($voteEndsAt)) {
    $ts = strtotime($voteEndsAt);
    if ($ts !== false) {
        $voteTimeLeftInitial = max(0, $ts - time());
        if ($voteTimeLeftInitial > $time_per_q) {
            $voteTimeLeftInitial = $time_per_q;
        }
    }
}

?>

<div class="container">
    <h1>Wybór kategorii</h1>
    <p>Głosowanie: <?php echo $vote_round; ?> (pytanie <?php echo $current_round; ?> / <?php echo (int)$game['total_rounds']; ?>)</p>

    <div class="game-tile" style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
            <div>
                <div style="font-weight:600;">Czas na wybór: <span id="vote-time">...</span> s</div>
                <div id="vote-progress" style="font-size:0.85rem; color:#9ca3af;"></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.85rem; color:#9ca3af;">Tryb</div>
                <div style="font-weight:600;">Dynamiczny</div>
            </div>
        </div>
        <div id="vote-meta" style="margin-top:10px; font-size:0.95rem; color:#e5e7eb;"></div>
    </div>

    <?php if ($already_vote !== null): ?>
        <p><strong>Oddałeś głos:</strong> <?php echo htmlspecialchars($already_vote); ?>. Czekamy na pozostałych...</p>
    <?php else: ?>
        <form method="POST" action="vote_submit.php" id="vote-form">
            <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">

            <div class="category-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                <?php foreach ($cats as $cat): ?>
                    <button type="submit" name="category" value="<?php echo htmlspecialchars($cat); ?>"
                            class="btn-secondary" style="text-align:left; padding:14px; border-radius:14px;">
                        <?php echo htmlspecialchars($cat); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    <?php endif; ?>

    <p id="info" style="margin-top:14px;"></p>

    <p><a href="/index.php">&larr; Wróć do strony głównej</a></p>
</div>

<script>
const gameId = <?php echo (int)$game_id; ?>;
let timeLeft = <?php echo (int)$voteTimeLeftInitial; ?>;
let alreadyVoted = <?php echo $already_vote !== null ? 'true' : 'false'; ?>;

const timeEl = document.getElementById('vote-time');
const progressEl = document.getElementById('vote-progress');
const metaEl = document.getElementById('vote-meta');
const infoEl = document.getElementById('info');

function updateTimer() {
    timeEl.textContent = String(Math.max(0, timeLeft));
}

function disableVoteForm() {
    const form = document.getElementById('vote-form');
    if (!form) return;
    form.querySelectorAll('button').forEach(b => {
        b.disabled = true;
        b.style.opacity = 0.65;
        b.style.cursor = 'not-allowed';
    });
}

function pollVote() {
    fetch('vote_poll.php?game=' + gameId)
        .then(r => r.json())
        .then(data => {
            if (data.action === 'wait') {
                if (typeof data.votes !== 'undefined' && typeof data.total !== 'undefined') {
                    progressEl.textContent = `Oddane głosy: ${data.votes}/${data.total}`;
                } else {
                    progressEl.textContent = 'Czekamy na pozostałych...';
                }

                if (typeof data.time_left !== 'undefined' && data.time_left !== null) {
                    timeLeft = Math.max(0, parseInt(data.time_left, 10) || 0);
                    updateTimer();
                }

                setTimeout(pollVote, 2000);
            } else if (data.action === 'start') {
                window.location = 'game.php?game=' + gameId;
            } else {
                setTimeout(pollVote, 2000);
            }
        })
        .catch(_ => setTimeout(pollVote, 2000));
}

updateTimer();

// jeśli już zagłosował – tylko polling
if (alreadyVoted) {
    metaEl.textContent = 'Oddałeś głos. Gra rozpocznie się automatycznie po zakończeniu głosowania.';
    disableVoteForm();
    pollVote();
} else {
    // po wysłaniu formularza blokujemy przyciski szybciej (UX)
    const form = document.getElementById('vote-form');
    if (form) {
        form.addEventListener('submit', () => {
            alreadyVoted = true;
            metaEl.textContent = 'Głos zapisany. Czekamy na pozostałych...';
            disableVoteForm();
            setTimeout(pollVote, 500);
        });
    }
    pollVote();
}

setInterval(() => {
    if (timeLeft > 0) {
        timeLeft--;
        updateTimer();
        if (timeLeft <= 0) {
            infoEl.textContent = 'Czas na głosowanie minął. Uruchamiam grę...';
        }
    } else {
        updateTimer();
    }
}, 1000);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
