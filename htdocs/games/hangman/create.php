<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

// Pomocniczo – spróbujmy odczytać ID użytkownika z sesji
$creator_user_id = null;
if (!empty($_SESSION['user_id'])) {
    $creator_user_id = (int)$_SESSION['user_id'];
}

// Prosta funkcja do generowania kodu gry
function generate_hangman_code($length = 6) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // bez podobnych: I, O, 1, 0
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

$errors = [];

// opisy poziomów (do wyświetlenia)
$difficulty_labels = [
    'easy'   => 'Łatwy – pojedyncze słowa',
    'medium' => 'Średni – krótkie wyrażenia / tytuły',
    'hard'   => 'Trudny – przysłowia i dłuższe zdania',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phrase     = trim($_POST['phrase'] ?? '');
    $category   = trim($_POST['category'] ?? '');
    $hint       = trim($_POST['hint'] ?? '');
    $mode       = $_POST['mode'] ?? 'coop';
    $max_errors = (int)($_POST['max_errors'] ?? 8);
    $word_difficulty = $_POST['word_difficulty'] ?? 'easy';

    $allowed_modes = ['coop', 'battle', 'duel', 'host'];
    if (!in_array($mode, $allowed_modes, true)) {
        $mode = 'coop';
    }

    $allowed_difficulties = ['easy','medium','hard'];
    if (!in_array($word_difficulty, $allowed_difficulties, true)) {
        $word_difficulty = 'easy';
    }

    // Walidacja max_errors
    if ($max_errors < 3 || $max_errors > 12) {
        $errors[] = "Maksymalna liczba błędów musi być między 3 a 12.";
    }

    // Przygotowanie danych do zapisania w hangman_games
    $game_phrase     = '';
    $game_category   = '';
    $game_hint       = '';
    $game_difficulty = 'manual'; // dla host – ręczne hasło, dla losowanych nadpiszemy

    // -----------------------------------
    // TRYB HOST – prowadzący wpisuje hasło, ale nie gra
    // -----------------------------------
    if ($mode === 'host') {
        if ($phrase === '') {
            $errors[] = "Podaj hasło / zdanie do odgadnięcia.";
        } elseif (mb_strlen($phrase) > 255) {
            $errors[] = "Hasło jest za długie (maks. 255 znaków).";
        }

        if (empty($errors)) {
            $game_phrase   = $phrase;
            $game_category = $category;
            $game_hint     = $hint;
            $game_difficulty = 'manual';
        }
    } else {
        // -----------------------------------
        // TRYBY COOP / BATTLE / DUEL – hasło losowane z bazy
        // -----------------------------------
        if (empty($errors)) {
            $diff_esc = mysqli_real_escape_string($conn, $word_difficulty);

            // najpierw spróbuj z wybranego poziomu
            $res_word = mysqli_query($conn,
                "SELECT phrase, category, hint, difficulty
                 FROM hangman_words
                 WHERE difficulty = '$diff_esc'
                 ORDER BY RAND()
                 LIMIT 1"
            );

            if (!$res_word || mysqli_num_rows($res_word) === 0) {
                // jeśli brak – weź cokolwiek
                $res_word = mysqli_query($conn,
                    "SELECT phrase, category, hint, difficulty
                     FROM hangman_words
                     ORDER BY RAND()
                     LIMIT 1"
                );
            }

            if (!$res_word || mysqli_num_rows($res_word) === 0) {
                $errors[] = "Brak przygotowanych haseł w bazie dla wisielca. Dodaj hasła w trybie 'Prowadzący wpisuje hasło' jako zalogowany użytkownik.";
            } else {
                $w = mysqli_fetch_assoc($res_word);
                $game_phrase   = $w['phrase'];
                $game_category = $w['category'] ?? '';
                $game_hint     = $w['hint'] ?? '';
                $game_difficulty = $w['difficulty'] ?: $word_difficulty;
            }
        }
    }

    if (empty($errors)) {
        // Escapowanie pól tekstowych (już po ewentualnym nadpisaniu przez słowo z bazy)
        $phrase_esc = mysqli_real_escape_string($conn, $game_phrase);

        if ($game_category !== '') {
            $category_esc = "'" . mysqli_real_escape_string($conn, $game_category) . "'";
        } else {
            $category_esc = "NULL";
        }

        if ($game_hint !== '') {
            $hint_esc = "'" . mysqli_real_escape_string($conn, $game_hint) . "'";
        } else {
            $hint_esc = "NULL";
        }

        // creator_user_id może być NULL
        if ($creator_user_id !== null && $creator_user_id > 0) {
            $creator_sql = (string)$creator_user_id;
        } else {
            $creator_sql = "NULL";
        }

        $difficulty_esc = mysqli_real_escape_string($conn, $game_difficulty);

        // Wygeneruj unikalny kod gry
        $code = '';
        do {
            $code = generate_hangman_code(6);
            $code_esc = mysqli_real_escape_string($conn, $code);
            $res_check = mysqli_query($conn,
                "SELECT id FROM hangman_games WHERE code = '$code_esc'"
            );
        } while ($res_check && mysqli_num_rows($res_check) > 0);

        // Wstaw grę
        $sql_game = "
            INSERT INTO hangman_games
                (code, creator_user_id, phrase, hint, category, mode, difficulty, max_errors)
            VALUES
                ('$code_esc', $creator_sql, '$phrase_esc', $hint_esc, $category_esc,
                 '$mode', '$difficulty_esc', $max_errors)
        ";

        $res_game = mysqli_query($conn, $sql_game);

        if (!$res_game) {
            $errors[] = "Błąd podczas zapisywania gry: " . mysqli_error($conn);
        } else {
            $game_id = (int)mysqli_insert_id($conn);

            // Jeśli tryb HOST i prowadzący jest zalogowany -> zapisz hasło do hangman_words
            if ($mode === 'host' && $creator_user_id !== null && $creator_user_id > 0) {
                $phrase_w_esc   = mysqli_real_escape_string($conn, $game_phrase);
                $category_w_esc = $game_category !== '' ? "'" . mysqli_real_escape_string($conn, $game_category) . "'" : "NULL";
                $hint_w_esc     = $game_hint !== ''     ? "'" . mysqli_real_escape_string($conn, $game_hint) . "'"     : "NULL";
                $diff_w_esc     = mysqli_real_escape_string($conn, $word_difficulty);

                mysqli_query($conn,
                    "INSERT INTO hangman_words (phrase, category, hint, difficulty)
                     VALUES ('$phrase_w_esc', $category_w_esc, $hint_w_esc, '$diff_w_esc')"
                );
                // Nawet jeśli ten INSERT się nie uda – gra już istnieje, więc nie blokujemy niczego.
            }

           // Dodaj twórcę do hangman_players (również w trybie HOST – tam będzie obserwatorem)
if (is_logged_in() && !empty($_SESSION['username'])) {
    $nickname = $_SESSION['username'];
} elseif (!is_logged_in() && !empty($_SESSION['guest_name'])) {
    $nickname = $_SESSION['guest_name'];
} else {
    $nickname = 'Gracz_' . rand(1000, 9999);
}

// Zapamiętaj dla wisielca, żeby lobby/game używały tego samego
$_SESSION['hangman_nickname'] = $nickname;

$nickname_esc = mysqli_real_escape_string($conn, $nickname);
$is_guest = ($creator_user_id === null || $creator_user_id <= 0) ? 1 : 0;

$sql_player = "
    INSERT INTO hangman_players
        (game_id, user_id, is_guest, nickname, score, is_creator)
    VALUES
        ($game_id, " . ($creator_user_id !== null ? $creator_user_id : "NULL") . ",
         $is_guest, '$nickname_esc', 0, 1)
";

mysqli_query($conn, $sql_player);
// jeśli się nie uda – trudno, ale gra istnieje


            // Sukces – przekierowanie do lobby
            header("Location: lobby.php?game=" . $game_id);
            exit;
        }
    }
}
?>

<div class="container mt-4">
    <h1>Utwórz nową grę – Wisielec</h1>
    <p>
        Wybierz tryb gry. W trybach <strong>Kooperacja / Bitwa / Pojedynek</strong> hasło jest losowane z bazy haseł
        na podstawie wybranego poziomu trudności. W trybie
        <strong>Prowadzący wpisuje hasło</strong> podane przez Ciebie hasło zostanie zapisane w bazie (jeśli jesteś
        zalogowany), ale Ty sam nie bierzesz udziału w zgadywaniu – tylko obserwujesz.
    </p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="mt-3">
        <div class="mb-3">
            <label for="mode" class="form-label">Tryb gry</label>
            <select name="mode" id="mode" class="form-select">
                <option value="coop" <?php echo (isset($mode) && $mode === 'coop') ? 'selected' : ''; ?>>
                    Kooperacja – wszyscy razem przeciwko wisielcowi (hasło losowe z bazy)
                </option>
                <option value="battle" <?php echo (isset($mode) && $mode === 'battle') ? 'selected' : ''; ?>>
                    Bitwa o hasło – kto pierwszy, ten lepszy (hasło losowe z bazy)
                </option>
                <option value="duel" <?php echo (isset($mode) && $mode === 'duel') ? 'selected' : ''; ?>>
                    Pojedynek 1 na 1 (hasło losowe z bazy)
                </option>
                <option value="host" <?php echo (isset($mode) && $mode === 'host') ? 'selected' : ''; ?>>
                    Prowadzący wpisuje hasło (prowadzący nie gra, tylko obserwuje)
                </option>
            </select>
        </div>

        <div class="mb-3">
            <label for="word_difficulty" class="form-label">
                Poziom trudności hasła (dla gier z losowym hasłem i przy zapisie własnych haseł)
            </label>
            <select name="word_difficulty" id="word_difficulty" class="form-select">
                <?php
                $current_diff = $word_difficulty ?? 'easy';
                foreach ($difficulty_labels as $key => $label) {
                    $sel = ($current_diff === $key) ? 'selected' : '';
                    echo "<option value=\"$key\" $sel>" . htmlspecialchars($label) . "</option>";
                }
                ?>
            </select>
            <div class="form-text">
                Dla trybów Kooperacja/Bitwa/Pojedynek – z tego poziomu trudności zostanie wylosowane hasło.
                W trybie Prowadzący – ten poziom zostanie zapisany przy Twoim haśle w bazie, jeśli jesteś zalogowany.
            </div>
        </div>

        <hr>

        <div class="mb-3">
            <label for="phrase" class="form-label">Hasło / zdanie (tylko dla trybu Prowadzący)</label>
            <textarea name="phrase" id="phrase" class="form-control" rows="2"
                      placeholder="Np. 'Władca Pierścieni' albo 'Nie chwal dnia przed zachodem słońca'"><?php
                echo isset($phrase) ? htmlspecialchars($phrase) : '';
            ?></textarea>
            <div class="form-text">
                To pole jest <strong>wymagane tylko w trybie Prowadzący</strong>. W innych trybach hasło jest
                losowane z bazy.
            </div>
        </div>

        <div class="mb-3">
            <label for="category" class="form-label">Kategoria (opcjonalnie, dla Prowadzącego)</label>
            <input type="text" name="category" id="category" class="form-control"
                   placeholder="Np. Film, Przysłowie, Miasto"
                   value="<?php echo isset($category) ? htmlspecialchars($category) : ''; ?>">
        </div>

        <div class="mb-3">
            <label for="hint" class="form-label">Podpowiedź (opcjonalnie, dla Prowadzącego)</label>
            <input type="text" name="hint" id="hint" class="form-control"
                   placeholder="Krótka podpowiedź wyświetlana w trakcie gry"
                   value="<?php echo isset($hint) ? htmlspecialchars($hint) : ''; ?>">
        </div>

        <div class="mb-3">
            <label for="max_errors" class="form-label">Maksymalna liczba błędów</label>
            <input type="number" name="max_errors" id="max_errors" class="form-control"
                   min="3" max="12"
                   value="<?php echo isset($max_errors) ? (int)$max_errors : 8; ?>">
            <div class="form-text">
                Im mniej błędów, tym trudniej (standardowo 6–8).
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Utwórz grę</button>
        <a href="index.php" class="btn btn-secondary ms-2">Anuluj</a>
    </form>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
