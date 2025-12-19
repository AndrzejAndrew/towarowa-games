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
    "Harry Potter" => "🪄",
    "Historia" => "🏺",
    "Informatyka" => "💻",
    "Internet" => "🌐",
    "Język polski" => "📚",
    "Matematyka" => "➗",
    "Miasta i stolice" => "🏙️",
    "Muzyka filmowa" => "🎼",
    "Piłka nożna" => "⚽",
    "Pokemon" => "🧢",
    "Poznań" => "🏙️",
    "Seriale" => "📺",
    "Sport" => "🏅",
    "Wiedza ogólna" => "🧠",
    "Władca Pierścieni" => "💍",
    "XX wiek" => "🕰️",
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

    /* HERO / JOIN */
    .join-highlight {
        border-color: #38bdf8 !important;
        box-shadow: 0 0 0 1px rgba(56,189,248,0.35);
        background: radial-gradient(circle at top left, rgba(56,189,248,0.18), #020617 55%);
    }
    .quiz-hero-grid {
        display: grid;
        grid-template-columns: 1.2fr 0.8fr;
        gap: 18px;
        align-items: center;
    }
    .quiz-hero-title {
        font-size: 1.4rem;
        font-weight: 700;
        margin: 0 0 6px 0;
    }
    .quiz-hero-sub {
        color: #9ca3af;
        margin: 0 0 10px 0;
        line-height: 1.35;
    }
    .quiz-hero-points {
        margin: 0;
        padding-left: 18px;
        color: #cbd5e1;
        font-size: 0.95rem;
    }
    .quiz-hero-form {
        border: 1px solid #1f2937;
        border-radius: 16px;
        padding: 14px;
        background: rgba(2,6,23,0.55);
    }
    .quiz-join-form {
        max-width: 100% !important;
        margin-top: 0 !important;
    }
    .quiz-join-code {
        font-size: 1.25rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        padding: 12px 12px !important;
    }
    .quiz-hero-note {
        margin-top: 8px;
        font-size: 0.85rem;
        color: #9ca3af;
        line-height: 1.35;
    }

    /* Kategorie */
    #category-section.disabled {
        opacity: 0.45;
        pointer-events: none;
    }
    .btn-small {
        font-size: 0.85em;
        padding: 4px 10px;
    }
    .cat-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 6px;
        margin-bottom: 8px;
    }
    .cat-search {
        flex: 1 1 240px;
        max-width: 360px;
    }

    @media (max-width: 860px) {
        .quiz-hero-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container">
    <h1>Quiz</h1>
    <p>Witaj, <?php echo htmlspecialchars($display); ?>! Dołącz do gry lub utwórz nowy pokój.</p>

    <!-- ====================== HERO: DOŁĄCZ DO GRY (wariant 2) ====================== -->
    <div class="game-tile join-highlight" style="margin-bottom: 24px;">
        <div class="quiz-hero-grid">
            <div>
                <div class="quiz-hero-title">Dołącz do gry</div>
                <p class="quiz-hero-sub">
                    Najszybsza opcja: wpisz kod pokoju od organizatora. Jeśli dostałeś link z lobby – po prostu go otwórz.
                </p>
                <ul class="quiz-hero-points">
                    <li>Kod ma zwykle 5–6 znaków, np. <strong>LXGRDM</strong></li>
                    <li>Po dołączeniu zobaczysz lobby i listę graczy na żywo</li>
                </ul>
            </div>

            <div class="quiz-hero-form">
                <form method="post" action="join_game.php" class="form-vertical quiz-join-form">
                    <label>Kod gry:
                        <input class="quiz-join-code" type="text" name="code" maxlength="8" required placeholder="LXGRDM" autocomplete="off" autofocus>
                    </label>
                    <button type="submit" class="btn-primary" style="width:100%;">Dołącz</button>
                </form>
                <div class="quiz-hero-note">
                    Wskazówka: w lobby pojawi się też przycisk „Kopiuj link do dołączenia”, dzięki czemu możesz wysłać link zamiast kodu.
                </div>
            </div>
        </div>
    </div>

    <!-- ====================== UTWÓRZ GRĘ ====================== -->
    <div class="game-tile" style="margin-bottom: 24px;">
        <div class="game-title">Utwórz nową grę</div>
        <div class="game-desc">
            Wybierz tryb, ustaw liczbę rund i czas. W trybie klasycznym zaznacz kategorie, z których mają być losowane pytania.
        </div>

        <form method="post" action="create_game.php" class="form-vertical quiz-create-form">

            <label style="margin-bottom:4px;">Tryb gry:</label>
            <div class="mode-switch">
                <label class="mode-option active" data-mode="classic">
                    <div class="mode-option-header">
                        <input type="radio" name="mode" value="classic" checked>
                        <span>Klasyczny</span>
                    </div>
                    <div class="mode-option-desc">
                        Pytania tylko z wybranych kategorii.
                    </div>
                </label>

                <label class="mode-option" data-mode="dynamic">
                    <div class="mode-option-header">
                        <input type="radio" name="mode" value="dynamic">
                        <span>Dynamiczny</span>
                    </div>
                    <div class="mode-option-desc">
                        Co 5 pytań głosowanie nad kategorią i losowanie pakietu.
                    </div>
                </label>
            </div>

            <div class="form-row" style="margin-top: 10px;">
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

            <p id="category-info" style="font-size:0.9em; opacity:0.85; margin: 10px 0 6px 0;">
                W trybie klasycznym pytania losowane są tylko z zaznaczonych kategorii.
            </p>

            <div class="cat-toolbar">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <button type="button" id="select-all-cats" class="btn-secondary btn-small">
                        Zaznacz wszystkie
                    </button>
                    <span style="font-size:0.85rem; color:#9ca3af;">
                        (kliknij ponownie, aby odznaczyć)
                    </span>
                </div>

                <input id="cat-search" class="cat-search" type="text" placeholder="Szukaj kategorii..." autocomplete="off">
            </div>

            <div id="category-section">
                <div class="category-grid" id="category-grid">
                    <?php foreach ($categories as $catRaw): ?>
                        <?php
                            $cat = htmlspecialchars($catRaw);
                            $icon = $icons[$catRaw] ?? "🏷️";
                        ?>
                        <label class="category-pill" data-cat="<?php echo strtolower($catRaw); ?>">
                            <input type="checkbox" name="categories[]" value="<?php echo $cat; ?>">
                            <span class="category-icon"><?php echo $icon; ?></span>
                            <span class="category-label"><?php echo $cat; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="font-size:0.9em; color:#aaa; margin-top:10px;">
                • Tryb klasyczny: liczba rund = liczba pytań<br>
                • Tryb dynamiczny: liczba rund × 5 pytań (głosowanie nad kategorią co 5 pytań)
            </p>

            <button type="submit" class="btn-primary" style="margin-top:12px;">Utwórz pokój</button>
        </form>
    </div>

    <p>
        <a href="ranking.php">Zobacz ranking graczy quizu</a>
    </p>
    <p><a href="/index.php">&larr; Wróć do strony głównej</a></p>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const modeOptions  = document.querySelectorAll(".mode-option");
    const modeRadios   = document.querySelectorAll('input[name="mode"]');
    const catSection   = document.getElementById("category-section");
    const catInfo      = document.getElementById("category-info");
    const selectAllBtn = document.getElementById("select-all-cats");
    const searchInput  = document.getElementById("cat-search");
    const joinCode     = document.querySelector('input[name="code"]');

    function updateModeUI() {
        const mode = document.querySelector('input[name="mode"]:checked').value;
        modeOptions.forEach(opt => opt.classList.toggle("active", opt.dataset.mode === mode));

        const disabled = (mode === "dynamic");
        catSection.classList.toggle("disabled", disabled);

        document.querySelectorAll('#category-section input[type="checkbox"]').forEach(ch => {
            ch.disabled = disabled;
        });

        searchInput.disabled = disabled;
        searchInput.style.opacity = disabled ? 0.6 : 1;

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

    // JOIN: automatyczny uppercase
    if (joinCode) {
        joinCode.addEventListener("input", () => {
            joinCode.value = joinCode.value.toUpperCase().replace(/\s+/g, '');
        });
    }

    // Kategorie: select all / toggle
    selectAllBtn.addEventListener("click", () => {
        const mode = document.querySelector('input[name="mode"]:checked').value;
        if (mode === "dynamic") return;

        const checkboxes = Array.from(document.querySelectorAll('#category-section input[type="checkbox"]'));
        const allChecked = checkboxes.length > 0 && checkboxes.every(ch => ch.checked);
        checkboxes.forEach(ch => ch.checked = !allChecked);
        selectAllBtn.textContent = allChecked ? "Zaznacz wszystkie" : "Odznacz wszystkie";
    });

    // Kategorie: filtrowanie
    function filterCategories() {
        const q = (searchInput.value || "").toLowerCase().trim();
        document.querySelectorAll("#category-grid .category-pill").forEach(pill => {
            const name = pill.getAttribute("data-cat") || "";
            pill.style.display = (q === "" || name.includes(q)) ? "" : "none";
        });
    }
    searchInput.addEventListener("input", filterCategories);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
