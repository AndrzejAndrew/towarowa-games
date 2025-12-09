<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

// tylko zalogowani użytkownicy mogą dodawać pytania
if (!is_logged_in()) {
    header("Location: /user/login.php");
    exit;
}

$success = "";
$error = "";

// pobierz listę istniejących kategorii (do podpowiedzi)
$categories = [];
$res = mysqli_query(
    $conn,
    "SELECT DISTINCT category 
     FROM questions 
     WHERE category IS NOT NULL AND category <> '' 
     ORDER BY category"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $categories[] = $row['category'];
    }
}

// obsługa dodania pytania
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_question') {

    $category = trim($_POST['category'] ?? '');
    $question = trim($_POST['question'] ?? '');
    $a = trim($_POST['answer_a'] ?? '');
    $b = trim($_POST['answer_b'] ?? '');
    $c = trim($_POST['answer_c'] ?? '');
    $d = trim($_POST['answer_d'] ?? '');
    $correct = strtoupper(trim($_POST['correct_answer'] ?? ''));

    if ($category === '' || $question === '' || $a === '' || $b === '' || $c === '' || $d === '') {
        $error = "Uzupełnij wszystkie pola.";
    } elseif (!in_array($correct, ['A','B','C','D'], true)) {
        $error = "Wybierz poprawną odpowiedź (A, B, C lub D).";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO questions (category, question, a, b, c, d, correct)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssssss", $category, $question, $a, $b, $c, $d, $correct);
            if (mysqli_stmt_execute($stmt)) {
                $success = "Pytanie zostało dodane.";
                // wyczyść pola formularza
                $category = $question = $a = $b = $c = $d = "";
                $correct = "A";
            } else {
                $error = "Błąd podczas zapisu pytania.";
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = "Błąd przygotowania zapytania.";
        }
    }
}

// wartości domyślne, gdy pierwszy raz wchodzimy na stronę
if (!isset($correct) || $correct === "") {
    $correct = "A";
}
?>
<div class="container">
    <h1>Dodaj pytanie do quizu</h1>
    <p>Formularz dostępny jest tylko dla zalogowanych użytkowników. Kategoria jest zwykłym tekstem – możesz wybrać jedną z istniejących lub wpisać nową.</p>

    <?php if ($success): ?>
        <p style="color:#4ade80; font-size:0.9rem; margin-bottom:8px;"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="color:#f97373; font-size:0.9rem; margin-bottom:8px;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" class="form-vertical">
        <input type="hidden" name="action" value="add_question">

        <label>Kategoria:
            <input list="category-list" name="category" value="<?php echo htmlspecialchars($category ?? ''); ?>" required>
            <datalist id="category-list">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>

        <label>Pytanie:
            <input type="text" name="question" value="<?php echo htmlspecialchars($question ?? ''); ?>" required>
        </label>

        <div class="answers-grid">
            <div>
                <label>Odpowiedź A:
                    <input type="text" name="answer_a" value="<?php echo htmlspecialchars($a ?? ''); ?>" required>
                </label>
            </div>
            <div>
                <label>Odpowiedź B:
                    <input type="text" name="answer_b" value="<?php echo htmlspecialchars($b ?? ''); ?>" required>
                </label>
            </div>
            <div>
                <label>Odpowiedź C:
                    <input type="text" name="answer_c" value="<?php echo htmlspecialchars($c ?? ''); ?>" required>
                </label>
            </div>
            <div>
                <label>Odpowiedź D:
                    <input type="text" name="answer_d" value="<?php echo htmlspecialchars($d ?? ''); ?>" required>
                </label>
            </div>
        </div>

        <label>Poprawna odpowiedź:
            <select name="correct_answer" required>
                <option value="A" <?php if ($correct === 'A') echo 'selected'; ?>>A</option>
                <option value="B" <?php if ($correct === 'B') echo 'selected'; ?>>B</option>
                <option value="C" <?php if ($correct === 'C') echo 'selected'; ?>>C</option>
                <option value="D" <?php if ($correct === 'D') echo 'selected'; ?>>D</option>
            </select>
        </label>

        <button type="submit" class="btn-primary">Dodaj pytanie</button>
    </form>

    <p style="margin-top:16px;">
        <a href="index.php">&larr; Wróć do quizu</a>
    </p>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
