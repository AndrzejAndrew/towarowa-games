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
    "SELECT id, status, mode, current_round, total_rounds, time_per_question, round_ends_at,
            IF(round_ends_at IS NULL, NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), round_ends_at))) AS round_time_left
     FROM games WHERE id = $game_id LIMIT 1"
);
if (!$gameRes && mysqli_errno($conn) === 1054) {
    // fallback dla starszej bazy bez round_ends_at / TIMESTAMPDIFF
    $gameRes = mysqli_query($conn,
        "SELECT id, status, mode, current_round, total_rounds, time_per_question, round_ends_at
         FROM games WHERE id = $game_id LIMIT 1"
    );
}
$game = $gameRes ? mysqli_fetch_assoc($gameRes) : null;

if (!$game) {
    die("Nie ma takiej gry.");
}

if ($game['status'] === 'lobby') {
    header("Location: lobby.php?game=" . $game_id);
    exit;
}

if ($game['status'] === 'finished') {
    header("Location: finish.php?game=" . $game_id);
    exit;
}

$mode = $game['mode'] ?? 'classic';

// ustal playera
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
    die("Nie jesteś uczestnikiem tej gry.");
}
$player_id = (int)$player['id'];

$current_round   = (int)$game['current_round'];
$total_rounds    = (int)$game['total_rounds'];
$time_per_q      = (int)$game['time_per_question'];

// pobierz pytanie z game_questions
$qRes = mysqli_query($conn,
    "SELECT q.*
     FROM game_questions gq
     JOIN questions q ON gq.question_id = q.id
     WHERE gq.game_id = $game_id AND gq.round_number = $current_round
     LIMIT 1"
);
$qrow = mysqli_fetch_assoc($qRes);

if (!$qrow) {
    // w dynamicznym brak pytania oznacza fazę głosowania
    if ($mode === 'dynamic') {
        header("Location: vote.php?game=" . $game_id);
        exit;
    }
    die("Brak pytania dla tej rundy.");
}

// sprawdź czy już odpowiadał (pobieramy też time_left)
$already_answered = false;
$my_answer = null;
$my_time_left_at_answer = null;
$ansStmt = mysqli_prepare($conn,
    "SELECT answer, time_left, answered_at
     FROM answers
     WHERE game_id = ? AND player_id = ? AND question_id = ?
     LIMIT 1"
);
$qid = (int)$qrow['id'];
mysqli_stmt_bind_param($ansStmt, "iii", $game_id, $player_id, $qid);
mysqli_stmt_execute($ansStmt);
$ansRes = mysqli_stmt_get_result($ansStmt);
$arow = mysqli_fetch_assoc($ansRes);
mysqli_stmt_close($ansStmt);

if ($arow) {
    $already_answered = true;
    $my_answer = $arow['answer'];
    $my_time_left_at_answer = (int)($arow['time_left'] ?? 0);
}

// czas do końca rundy wg serwera (bez pułapki stref czasowych – liczone po stronie MySQL)
$timeLeftInitial = $time_per_q;
if (is_array($game) && array_key_exists('round_time_left', $game) && $game['round_time_left'] !== null) {
    $timeLeftInitial = (int)$game['round_time_left'];
    if ($timeLeftInitial > $time_per_q) {
        $timeLeftInitial = $time_per_q;
    }
}

// opcje odpowiedzi (losujemy kolejność, ale litery A-D są przypisane do kolumn a-d)
$options = ['a', 'b', 'c', 'd'];
shuffle($options);

$correct = strtoupper($qrow['correct']);
$category = $qrow['category'] ?? '';

// oblicz "odpowiedziałeś po X sekundach" (bazujemy na time_left zapisanym w DB)
$answeredAfter = null;
if ($already_answered && $my_time_left_at_answer !== null) {
    $answeredAfter = max(0, $time_per_q - (int)$my_time_left_at_answer);
}
?>

<div class="container">
    <h1>Quiz</h1>
    <p>Runda <?php echo $current_round; ?> / <?php echo $total_rounds; ?></p>

    <div class="game-tile" style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
            <div>
                <div style="font-weight:600;">Czas do końca rundy: <span id="time-left">...</span> s</div>
                <div id="progress" style="font-size:0.85rem; color:#9ca3af;"></div>
            </div>
            <div style="text-align:right;">
                <?php if ($category !== ''): ?>
                    <div style="font-size:0.85rem; color:#9ca3af;">Kategoria</div>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($category); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div id="answer-meta" style="margin-top:10px; font-size:0.9rem; color:#e5e7eb;"></div>
    </div>

    <div class="game-tile" style="margin-bottom:16px;">
        <div class="game-title" style="margin-bottom:10px;">Pytanie:</div>
        <div style="font-size:1.05rem; line-height:1.35;">
            <?php echo htmlspecialchars($qrow['question']); ?>
        </div>
    </div>

    <div class="category-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
        <?php foreach ($options as $opt): ?>
            <?php
                $letter = strtoupper($opt); // A/B/C/D odpowiada kolumnom a/b/c/d
                $label = $qrow[$opt];
            ?>
            <button class="btn-secondary" style="text-align:left; padding:14px; border-radius:14px;"
                onclick="sendAnswer('<?php echo $letter; ?>')"
                data-answer="<?php echo $letter; ?>"
                id="btn_<?php echo $letter; ?>">
                <strong><?php echo $letter; ?>.</strong> <?php echo htmlspecialchars($label); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <p id="info" style="margin-top:14px;"></p>

    <p><a href="/index.php">&larr; Wróć do strony głównej</a></p>
</div>

<script>
const gameId = <?php echo (int)$game_id; ?>;
const timePerQ = <?php echo (int)$time_per_q; ?>;
let timeLeft = <?php echo (int)$timeLeftInitial; ?>;
let answered = <?php echo $already_answered ? 'true' : 'false'; ?>;
let selected = <?php echo $my_answer !== null ? json_encode($my_answer) : 'null'; ?>;
let answeredAfter = <?php echo $answeredAfter !== null ? (int)$answeredAfter : 'null'; ?>;
let myTimeLeftAtAnswer = <?php echo $my_time_left_at_answer !== null ? (int)$my_time_left_at_answer : 'null'; ?>;

const timeEl = document.getElementById('time-left');
const infoEl = document.getElementById('info');
const metaEl = document.getElementById('answer-meta');
const progressEl = document.getElementById('progress');

function setButtonsDisabled(disabled) {
    document.querySelectorAll('button[data-answer]').forEach(b => {
        b.disabled = disabled;
        b.style.opacity = disabled ? 0.65 : 1;
        b.style.cursor = disabled ? 'not-allowed' : 'pointer';
    });
}

function highlightSelected() {
    if (!selected) return;
    document.querySelectorAll('button[data-answer]').forEach(b => {
        if (b.dataset.answer === String(selected).toUpperCase()) {
            b.classList.add('btn-primary');
            b.classList.remove('btn-secondary');
        }
    });
}

function renderMeta() {
    if (!answered) {
        metaEl.textContent = "";
        return;
    }

    if (!selected) {
        metaEl.textContent = `Nie udzieliłeś odpowiedzi w czasie. Czekamy na pozostałych graczy...`;
        return;
    }

    const ansLetter = String(selected).toUpperCase();
    const used = (answeredAfter !== null) ? answeredAfter : (timePerQ - Math.max(0, myTimeLeftAtAnswer || 0));
    const left = (myTimeLeftAtAnswer !== null) ? myTimeLeftAtAnswer : null;

    if (left !== null) {
        metaEl.textContent = `Twoja odpowiedź: ${ansLetter}. Odpowiedziałeś po ${used}s (zostało ${left}s). Czekamy na pozostałych...`;
    } else {
        metaEl.textContent = `Twoja odpowiedź: ${ansLetter}. Czekamy na pozostałych...`;
    }
}

function updateTimerUI() {
    timeEl.textContent = String(Math.max(0, timeLeft));
}

async function sendAnswer(letter) {
    if (answered) return;
    answered = true;

    // jeśli gracz nie odpowiedział (timeout) – wysyłamy NULL/"" aby serwer mógł przejść dalej
    const answerLetter = (letter === null || letter === undefined) ? '' : String(letter).toUpperCase();

    // natychmiast blokujemy przyciski
    setButtonsDisabled(true);

    const payload = new URLSearchParams({
        game_id: String(gameId),
        question_id: String(<?php echo (int)$qrow['id']; ?>),
        answer: answerLetter,
        time_left: String(Math.max(0, Math.min(timePerQ, timeLeft)))
    });

    try {
        const resp = await fetch('answer.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: payload.toString()
        });
        const data = await resp.json();
        if (!data.ok && data.error !== 'already_answered') {
            infoEl.textContent = "Błąd wysyłania odpowiedzi: " + (data.error || 'unknown');
        }

        // zapisz lokalnie
        selected = answerLetter !== '' ? answerLetter : null;
        myTimeLeftAtAnswer = Math.max(0, Math.min(timePerQ, timeLeft));
        answeredAfter = timePerQ - myTimeLeftAtAnswer;

        highlightSelected();
        renderMeta();
        pollNext();
    } catch (e) {
        infoEl.textContent = "Błąd połączenia przy wysyłaniu odpowiedzi.";
        // mimo wszystko próbujemy przejść dalej – next.php i tak ma fail-safe na timeout
        pollNext();
    }
}

function pollNext() {
    fetch('next.php?game=' + gameId)
        .then(r => r.json())
        .then(data => {
            if (data.action === 'wait') {
                const a = (data.answered !== undefined && data.total !== undefined)
                    ? `Odpowiedzi: ${data.answered}/${data.total}`
                    : 'Czekamy na pozostałych graczy...';
                progressEl.textContent = a;
                setTimeout(pollNext, 2000);
            } else if (data.action === 'next') {
                window.location = 'game.php?game=' + gameId;
            } else if (data.action === 'finish') {
                window.location = 'finish.php?game=' + gameId;
            } else {
                // error/busy
                setTimeout(pollNext, 2000);
            }
        })
        .catch(_ => setTimeout(pollNext, 2000));
}

// init
updateTimerUI();
if (answered) {
    setButtonsDisabled(true);
    highlightSelected();
    renderMeta();
    pollNext();
}

// tick
setInterval(() => {
    if (timeLeft > 0) {
        timeLeft--;
        updateTimerUI();

        // gdy czas dobiegnie końca, a gracz nie odpowiedział → wyślij timeout
        if (timeLeft <= 0 && !answered) {
            updateTimerUI();
            infoEl.textContent = "Czas minął. Zapisuję brak odpowiedzi...";
            sendAnswer('');
        }
    } else {
        updateTimerUI();
        if (!answered) {
            infoEl.textContent = "Czas minął. Zapisuję brak odpowiedzi...";
            sendAnswer('');
        }
    }
}, 1000);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
