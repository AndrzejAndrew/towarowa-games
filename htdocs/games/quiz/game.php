<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

// gra
$res = mysqli_query($conn,
    "SELECT code, total_rounds, time_per_question, current_round, status, mode
 FROM games WHERE id = $game_id"
);
$game = mysqli_fetch_assoc($res);
if (!$game) {
    die("Nie znaleziono gry.");
}
if ($game['status'] === 'finished') {
    header("Location: finish.php?game=" . $game_id);
    exit;
}

$current_round = (int)$game['current_round'];

// znajdź aktualnego gracza
$is_guest = is_logged_in() ? 0 : 1;
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn,
        "SELECT id, nickname, score FROM players WHERE game_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $uid);
} else {
    $nickname = $_SESSION['guest_name'] ?? 'Gość';
    $stmt = mysqli_prepare($conn,
        "SELECT id, nickname, score FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ?"
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

// pobieranie pytania wraz z kategorią
$stmt = mysqli_prepare($conn,
    "SELECT q.id, q.question, q.category, q.a, q.b, q.c, q.d
     FROM game_questions gq
     JOIN questions q ON q.id = gq.question_id
     WHERE gq.game_id = ? AND gq.round_number = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $game_id, $current_round);
mysqli_stmt_execute($stmt);
$resq = mysqli_stmt_get_result($stmt);
$qrow = mysqli_fetch_assoc($resq);
mysqli_stmt_close($stmt);

if (!$qrow) {
    // Jeśli tryb dynamiczny i nie ma pytania na tę rundę,
    // to znaczy, że trzeba przejść do głosowania.
    if (($game['mode'] ?? 'classic') === 'dynamic') {
        header("Location: vote.php?game=" . $game_id);
        exit;
    } else {
        header("Location: finish.php?game=" . $game_id);
        exit;
    }
}

$question_id = (int)$qrow['id'];

// czy gracz już odpowiedział?
$res = mysqli_query($conn,
    "SELECT answer FROM answers
     WHERE game_id = $game_id AND player_id = $player_id AND question_id = $question_id"
);
$arow = mysqli_fetch_assoc($res);
$already_answered = $arow ? true : false;
$selected_answer = $arow['answer'] ?? '';
?>
<div class="container">
    <h1>Quiz – pytanie <?php echo $current_round; ?>/<?php echo (int)$game['total_rounds']; ?></h1>
    <p>Gracz: <?php echo htmlspecialchars($player['nickname']); ?>,
       wynik: <?php echo (int)$player['score']; ?> pkt</p>
    <p>Czas: <span id="timer"></span></p>

    <div class="card">

        <!-- KATEGORIA PYTANIA -->
        <?php if (!empty($qrow['category'])): ?>
            <p class="question-category">
                <strong>Kategoria:</strong> <?php echo htmlspecialchars($qrow['category']); ?>
            </p>
        <?php endif; ?>

        <p><?php echo nl2br(htmlspecialchars($qrow['question'])); ?></p>

        <div class="answers-grid">
            <?php
                $keys = ['a', 'b', 'c', 'd'];
                $shuffled = $keys;
                shuffle($shuffled);

                $displayLetters = ['A', 'B', 'C', 'D'];

                foreach ($shuffled as $index => $opt):
                    if (!empty($qrow[$opt])):
            ?>
                <button class="btn answer-btn"
                        data-answer="<?php echo $opt; ?>"
                        onclick="sendAnswer('<?php echo $opt; ?>')">
                    <?php echo $displayLetters[$index]; ?>.
                    <?php echo htmlspecialchars($qrow[$opt]); ?>
                </button>
            <?php
                    endif;
                endforeach;
            ?>
        </div>
    </div>

    <p id="status"></p>
    <p><a href="/index.php">Przerwij i wróć do strony głównej</a></p>
</div>

<script>
let timeLeft = <?php echo (int)$game['time_per_question']; ?>;
let answered = <?php echo $already_answered ? 'true' : 'false'; ?>;
let selected = "<?php echo htmlspecialchars($selected_answer); ?>";
let timerId = null;

function init() {
    const btns = document.querySelectorAll(".answer-btn");
    btns.forEach(b => {
        if (answered && b.dataset.answer === selected) {
            b.classList.add("btn-selected");
        }
        if (answered) {
            b.disabled = true;
        }
    });
    if (!answered) {
        startTimer();
    } else {
        document.getElementById("status").textContent =
            "Już odpowiedziałeś. Czekamy na innych graczy...";
        pollNext();
    }
}

function startTimer() {
    const el = document.getElementById("timer");
    el.textContent = timeLeft + " s";

    timerId = setInterval(() => {
        timeLeft--;
        if (timeLeft <= 0) {
            clearInterval(timerId);
            if (!answered) {
                answered = true;
                document.querySelectorAll(".answer-btn").forEach(b => b.disabled = true);
                document.getElementById("status").textContent =
                    "Czas minął. Czekamy na innych graczy...";
                pollNext();
            }
            el.textContent = "0 s";
        } else {
            el.textContent = timeLeft + " s";
        }
    }, 1000);
}

function sendAnswer(ans) {
    if (answered) return;
    answered = true;
    clearInterval(timerId);

    document.querySelectorAll(".answer-btn").forEach(b => {
        b.disabled = true;
        if (b.dataset.answer === ans) {
            b.classList.add("btn-selected");
        }
    });

    document.getElementById("status").textContent =
        "Odpowiedź wysłana. Czekamy na innych graczy...";

    const fd = new FormData();
    fd.append("game_id", "<?php echo $game_id; ?>");
    fd.append("question_id", "<?php echo $question_id; ?>");
    fd.append("answer", ans);
    fd.append("time_left", timeLeft);

    fetch("answer.php", { method: "POST", body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                document.getElementById("status").textContent = "Błąd przy wysyłaniu odpowiedzi.";
            } else {
                pollNext();
            }
        })
        .catch(() => {
            document.getElementById("status").textContent = "Błąd sieci.";
        });
}

function pollNext() {
    const code = "<?php echo $game['code']; ?>";
    const round = <?php echo $current_round; ?>;

    const check = () => {
        fetch("next.php?code=" + encodeURIComponent(code) + "&round=" + round)
            .then(r => r.json())
            .then(data => {
                if (data.action === "wait") {
                    setTimeout(check, 2000);
                } else if (data.action === "next") {
                    window.location.href = "game.php?game=<?php echo $game_id; ?>";
                } else if (data.action === "finish") {
                    window.location.href = "finish.php?game=<?php echo $game_id; ?>";
                }
            })
            .catch(() => setTimeout(check, 3000));
    };
    check();
}

document.addEventListener("DOMContentLoaded", init);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
