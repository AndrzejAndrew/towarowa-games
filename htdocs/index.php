<?php
// Strona główna portalu gier – nowy układ z paskiem statystyk
require_once __DIR__ . '/includes/header.php';

?>

    <!-- Sekcja HERO: tytuł + opis + CTA + panel logowania/profilu -->
    <section class="home-hero">
        <div class="hero-main">
            <h1>Centrum rozrywki dla zespołu</h1>
            <p class="subtitle hero-subtitle">
                Zróbmy małą przerwę dla głowy. Wybierz grę, zaproś ekipę i zagrajcie on-line –
                wszystko działa w przeglądarce, nawet z naszym proxy.
                Przed rozpoczęciem gry polecam użyć ctrl + F5 w celu pełnego odświeżenia strony (ignorując cache strony)
            </p>
            <a href="games/quiz/index.php" class="btn btn-primary hero-cta">
                🔀 Szybki start – zagraj w quiz
            </a>
            <p class="hero-note">
                Możesz grać jako gość, ale po zalogowaniu zapisujemy Twoje wyniki, historię gier
                i rankingi.
                Tutaj oficjalny <a href="https://discord.gg/ynsTvsYm">Discord</a> naszego portalu.
            </p>
        </div>

        <div class="hero-side">
            <?php if (!is_logged_in()): ?>
                <div class="login-panel">
                    <h2>Zaloguj się, żeby mieć pełne statystyki</h2>
                    <p>
                        Po zalogowaniu zobaczysz swój profil, historię gier i rankingi.
                        Możesz też grać jako gość, ale wyniki nie zapiszą się na Twoje konto.
                        Możesz zalogować się przez Discord (za pierwszym razem jest to równoznaczne z rejestracją)!
                        A tutaj oficjalny <a href="https://discord.gg/ynsTvsYm">Discord</a> naszego portalu.
                    </p>
                    <div class="login-actions">
                        <a href="/user/login.php" class="btn btn-primary">Zaloguj</a>
                        <a href="/user/register.php" class="btn btn-secondary">Rejestracja</a>
                        <a href="/auth/discord_login.php" class="btn btn-discord">Zaloguj przez Discord</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="login-panel login-panel-auth">
                    <h2>Cześć, <?php echo htmlspecialchars(current_display_name()); ?> 👋</h2>
                    <p>
                        Możesz od razu wybrać grę, sprawdzić swój profil albo zajrzeć do rankingów.
                    </p>
                    <div class="login-actions">
                        <a href="/user/profile.php" class="btn btn-primary">Mój profil</a>
                        <a href="/games/quiz/ranking.php" class="btn btn-secondary">Ranking quizu</a>
                        <a href="/user/logout.php" class="btn btn-outline">Wyloguj</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Sekcja: Gry zespołowe -->
    <section class="games-section">
        <div class="games-section-header">
            <h2>Gry zespołowe</h2>
            <p class="section-subtitle">
                Idealne na wspólną przerwę z kilkoma osobami – lobby, rundy, trochę rywalizacji.
            </p>
        </div>

        <div class="games-grid">
            <a class="game-tile" href="games/quiz/index.php">
                <div>
                    <div class="game-title">Quiz drużynowy</div>
                    <div class="game-desc">
                        Twórz gry, dołączaj do pokojów i rywalizuj na żywo.
                        Pytania losowane z bazy danych.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">multiplayer</span>
                    lobby, rundy, wyniki
                </div>
            </a>

            <a class="game-tile" href="games/hangman/index.php">
                <div>
                    <div class="game-title">Wisielec online</div>
                    <div class="game-desc">
                        Gra słowna dla kilku osób – jeden wymyśla hasło, reszta próbuje zgadnąć,
                        zanim „gość” skończy na linie.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">multiplayer</span>
                    hasła, drużyny, napięcie do końca
                </div>
            </a>

            <a class="game-tile" href="games/paper_soccer/index.php">
                <div>
                    <div class="game-title">Papierowa piłka nożna</div>
                    <div class="game-desc">
                        Logiczna gra na planszy – przesuwaj piłkę po liniach i spróbuj zdobyć bramkę.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">2 osoby</span>
                    ruch po liniach siatki
                </div>
            </a>
        </div>
    </section>

    <!-- Sekcja: Gry 1 vs 1 -->
    <section class="games-section">
        <div class="games-section-header">
            <h2>Gry 1 vs 1</h2>
            <p class="section-subtitle">
                Pojedynki jeden na jeden – z kolegą albo przeciwko prostemu botowi.
            </p>
        </div>

        <div class="games-grid">
            <a class="game-tile" href="games/tic_tac_toe/index.php">
                <div>
                    <div class="game-title">Kółko i krzyżyk</div>
                    <div class="game-desc">
                        Klasyka 3×3. Graj z kolegą przy jednym komputerze albo przeciwko prostemu botowi.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">2 osoby / bot</span>
                    plansza 3×3, szybkie rundy
                </div>
            </a>

            <a class="game-tile" href="games/rock_paper_scissors/index.php">
                <div>
                    <div class="game-title">Papier, kamień, nożyce</div>
                    <div class="game-desc">
                        Szybka gra na refleks – papier owija kamień, kamień tępi nożyce,
                        nożyce tną papier.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">2 osoby / bot</span>
                    rundy na punkty
                </div>
            </a>

            <a class="game-tile" href="games/battleship/index.php">
                <div>
                    <div class="game-title">Statki</div>
                    <div class="game-desc">
                        Rozstaw flotę i zatop przeciwnika. Prosta wersja 10×10 z losowym botem.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">1–2 osoby</span>
                    klasyczne okręty
                </div>
            </a>
        </div>
    </section>

    <!-- Sekcja: Statystyki i rankingi -->
    <section class="games-section">
        <div class="games-section-header">
            <h2>Statystyki i rankingi</h2>
            <p class="section-subtitle">
                Zobacz kto dominuje w quizie i sprawdź swoje własne statystyki.
            </p>
        </div>

        <div class="games-grid">
            <a class="game-tile" href="/games/quiz/ranking.php">
                <div>
                    <div class="game-title">Ranking quizu</div>
                    <div class="game-desc">
                        Tabela najlepszych zawodników quizu. Zobacz, na którym miejscu jesteś
                        po ostatnich rozgrywkach.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">ranking</span>
                    najlepsze wyniki, historia punktów
                </div>
            </a>

            <a class="game-tile" href="/user/profile.php">
                <div>
                    <div class="game-title">Mój profil i historia gier</div>
                    <div class="game-desc">
                        Podsumowanie Twoich gier, zwycięstw i porażek. Idealne, żeby śledzić postępy
                        i udowodnić, że to nie był przypadek.
                    </div>
                </div>
                <div class="game-meta">
                    <span class="badge">twoje statystyki</span>
                    profil gracza, historia rozgrywek
                </div>
            </a>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
