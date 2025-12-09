<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

$display = current_display_name();

// Pobierz kategorie z bazy
$categories = [];
$res = mysqli_query($conn, "SELECT DISTINCT category FROM questions ORDER BY category");
while ($row = mysqli_fetch_assoc($res)) {
    if ($row['category'] !== '') {
        $categories[] = $row['category'];
    }
}

$icons = [
    "Biologia" => "🧬",
    "Chemia" => "⚗️",
    "E.Leclerc" => "🏬",
    "Geografia" => "🌍",
    "Gry komputerowe" => "🎮",
    "Historia" => "🏺",
    "Informatyka" => "💻",
    "Język polski" => "📚",
    "Matematyka" => "➗",
    "Miasta i stolice" => "🏙️",
    "Piłka nożna" => "⚽",
    "Sport" => "🏅"
];
?>
<style>
    .mode-switch {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin: 8px 0 14px 0;
    }
    .mode-option {
        border-radius: 999px;
        padding: 8px 14px;
        border: 1px solid #3f3f46;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        gap: 2px;
        background: #020617;
        min-width: 220px;
    }
    .mode-option-header {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
    }
    .mode-option input[type="radio"] {
        accent-color: #38bdf8;
    }
    .mode-option-desc {
        font-size: 0.85em;
        opacity: 0.8;
        padding-left: 23px;
    }
    .mode-option.active {
        border-color: #38bdf8;
        background: #020617;
        box-shadow: 0 0 0 1px rgba(56,189,248,0.4);
    }
    #category-section.disabled {
        opacity: 0.45;
    }
    .btn-small {
        font-size: 0.85em;
        padding: 4px 10px;
    }
</style>

<div class="container">
    <h1>Quiz</h1>
    <p>Witaj, <?php echo htmlspecialchars($display); ?>! Utwórz nową grę lub dołącz do istniejącej.</p>

    <!-- ====================== UTWÓRZ GRĘ ====================== -->
    <div class="game-tile" style="margin-bottom: 24px;">
        <div class="game-title">Utwórz nową grę</div>
        <div class="game-desc">
            Wybierz tryb gry, kategorie (w trybie klasycznym), ustaw liczbę rund i czas na pytanie,
            a następnie udostępnij kod innym graczom.
        </div>

        <form method="post" action="create_game.php" class="form-vertical quiz-create-form">

            <label>Tryb gry:</label>
            <div class="mode-switch">
                <label class="mode-option active" data-mode="classic">
                    <div class="mode-option-header">
                        <input type="radio" name="mode" value="classic" checked>
                        <span>Klasyczny</span>
                    </div>
                    <div class="mode-option-desc">
                        Stały zestaw kategorii, losowanie pytań tylko z wybranych poniżej.
                    </div>
                </label>

                <label class="mode-option" data-mode="dynamic">
                    <div class="mode-option-header">
                        <input type="radio" name="mode" value="dynamic">
                        <span>Dynamiczny</span>
                    </div>
                    <div class="mode-option-desc">
                        Po każdej turze głosowanie nad kategorią. Z wybranej kategorii
                        losujemy pakiet pytań.
                    </div>
                </label>
            </div>

            <p id="category-info" style="font-size:0.9em; opacity:0.85; margin-bottom:4px;">
                W trybie klasycznym pytania losowane są tylko z zaznaczonych kategorii.
            </p>

            <div style="display:flex; justify-content:space-between; align-items:center;">
                <label>Kategorie pytań (używane w trybie klasycznym):</label>
                <button type="button" id="select-all-cats" class="btn-secondary btn-small">
                    Zaznacz wszystkie
                </button>
            </div>

            <div id="category-section">
                <div class="category-grid">
                    <?php foreach ($categories as $catRaw): ?>
                        <?php
                            $cat = htmlspecialchars($catRaw);
                            $icon = $icons[$catRaw] ?? "❓";
                        ?>
                        <label class="category-pill">
                            <input type="checkbox" name="categories[]" value="<?php echo $cat; ?>">
                            <span class="category-icon"><?php echo $icon; ?></span>
                            <span class="category-label"><?php echo $cat; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-col">
                    <label>Liczba rund:
                        <input type="number" name="total_rounds" min="1" max="20" value="5">
                    </label>
                </div>
                <div class="form-col">
                    <label>Czas na pytanie (sekundy):
                        <input type="number" name="time_per_question" min="5" max="120" value="20">
                    </label>
                </div>
            </div>

            <p style="font-size:0.9em; color:#aaa;">
                • Tryb klasyczny: liczba rund = liczba pytań<br>
                • Tryb dynamiczny: liczba rund × 5 pytań (głosowanie nad kategorią co 5 pytań)
            </p>

            <button type="submit" class="btn-primary" style="margin-top:12px;">Utwórz pokój</button>
        </form>
    </div>

    <!-- ====================== DOŁĄCZ DO GRY ====================== -->
    <div class="game-tile">
        <div class="game-title">Dołącz do gry</div>
        <div class="game-desc">
            Wpisz kod pokoju otrzymany od osoby, która utworzyła grę.
        </div>

        <form method="post" action="join_game.php" class="form-vertical quiz-join-form">
            <label>Kod gry:
                <input type="text" name="code" maxlength="8" required>
            </label>
            <button type="submit" class="btn-secondary">Dołącz</button>
        </form>
    </div>

    <p style="margin-top:20px;"><a href="/index.php">&larr; Wróć do strony głównej</a></p>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const modeOptions = document.querySelectorAll(".mode-option");
    const modeRadios  = document.querySelectorAll('input[name="mode"]');
    const catSection  = document.getElementById("category-section");
    const catInfo     = document.getElementById("category-info");
    const selectAllBtn = document.getElementById("select-all-cats");

    function updateModeUI() {
        const mode = document.querySelector('input[name="mode"]:checked').value;
        modeOptions.forEach(opt => {
            opt.classList.toggle("active", opt.dataset.mode === mode);
        });

        const disabled = (mode === "dynamic");
        catSection.classList.toggle("disabled", disabled);
        document.querySelectorAll('#category-section input[type="checkbox"]').forEach(ch => {
            ch.disabled = disabled;
        });

        if (mode === "dynamic") {
            catInfo.textContent = "W trybie dynamicznym kategorie wybierane są przez głosowanie w trakcie gry. Lista poniżej jest ignorowana.";
        } else {
            catInfo.textContent = "W trybie klasycznym pytania losowane są tylko z zaznaczonych kategorii.";
        }
    }

    modeOptions.forEach(opt => {
        opt.addEventListener("click", () => {
            const radio = opt.querySelector('input[type="radio"]');
            radio.checked = true;
            updateModeUI();
        });
    });

    modeRadios.forEach(r => r.addEventListener("change", updateModeUI));
    updateModeUI();

    selectAllBtn.addEventListener("click", () => {
        const mode = document.querySelector('input[name="mode"]:checked').value;
        if (mode === "dynamic") {
            return; // w dynamicznym kategorie nie mają znaczenia
        }
        const checkboxes = Array.from(document.querySelectorAll('#category-section input[type="checkbox"]'));
        const allChecked = checkboxes.length > 0 && checkboxes.every(ch => ch.checked);
        checkboxes.forEach(ch => ch.checked = !allChecked);
        selectAllBtn.textContent = allChecked ? "Zaznacz wszystkie" : "Odznacz wszystkie";
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
