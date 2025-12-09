<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// -------------------------------------------
// Pomocnicze funkcje – takie jak w lobby.php
// -------------------------------------------
function hm_get_current_user_id(): ?int {
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

function hm_resolve_nickname(): string {
    // ZAWSZE preferujemy aktualny username / guest_name
    if (is_logged_in() && !empty($_SESSION['username'])) {
        $nick = $_SESSION['username'];
    } elseif (!is_logged_in() && !empty($_SESSION['guest_name'])) {
        $nick = $_SESSION['guest_name'];
    } elseif (!empty($_SESSION['hangman_nickname'])) {
        // fallback – stary nick z sesji
        $nick = $_SESSION['hangman_nickname'];
    } else {
        $nick = 'Gracz_' . rand(1000, 9999);
    }

    $_SESSION['hangman_nickname'] = $nick;
    return $nick;
}

/**
 * Zapewnia, że aktualnie odwiedzający jest wpisany jako gracz w tej grze.
 * Zwraca wiersz z hangman_players lub null w razie błędu.
 */
function hm_ensure_player(mysqli $conn, int $game_id): ?array {
    $user_id = hm_get_current_user_id();

    // 1) Jeśli zalogowany – szukamy po user_id
    if ($user_id !== null && $user_id > 0) {
        $res = mysqli_query($conn,
            "SELECT * FROM hangman_players
             WHERE game_id = $game_id AND user_id = $user_id
             LIMIT 1"
        );
        if ($res && mysqli_num_rows($res) > 0) {
            return mysqli_fetch_assoc($res);
        }
    }

    // 2) Gość – sprawdź po nicku
    $nickname     = hm_resolve_nickname();
    $nickname_esc = mysqli_real_escape_string($conn, $nickname);

    $res = mysqli_query($conn,
        "SELECT * FROM hangman_players
         WHERE game_id = $game_id
           AND user_id IS NULL
           AND nickname = '$nickname_esc'
         LIMIT 1"
    );
    if ($res && mysqli_num_rows($res) > 0) {
        return mysqli_fetch_assoc($res);
    }

    // 3) Jeśli dalej nic – dodaj nowego gracza
    $is_guest = ($user_id === null || $user_id <= 0) ? 1 : 0;
    $user_sql = ($user_id !== null && $user_id > 0) ? (string)$user_id : "NULL";

    $sql_insert = "
        INSERT INTO hangman_players
            (game_id, user_id, is_guest, nickname, score, is_creator)
        VALUES
            ($game_id, $user_sql, $is_guest, '$nickname_esc', 0, 0)
    ";
    $res_ins = mysqli_query($conn, $sql_insert);
    if (!$res_ins) {
        return null;
    }

    $player_id = (int)mysqli_insert_id($conn);

    $res2 = mysqli_query($conn,
        "SELECT * FROM hangman_players WHERE id = $player_id"
    );
    if ($res2 && mysqli_num_rows($res2) > 0) {
        return mysqli_fetch_assoc($res2);
    }

    return null;
}

// -------------------------------------------
// Pobierz ID gry
// -------------------------------------------
$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

// Pobranie gry
$res_game = mysqli_query($conn,
    "SELECT *
     FROM hangman_games
     WHERE id = $game_id"
);
$game = $res_game ? mysqli_fetch_assoc($res_game) : null;

if (!$game) {
    require_once __DIR__ . '/../../includes/header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>Nie znaleziono takiej gry.</div></div>";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Jeśli gra jeszcze w lobby – cofnij do lobby
if ($game['status'] === 'lobby') {
    header("Location: lobby.php?game=" . $game_id);
    exit;
}

// Jeśli już zakończona – idź od razu do podsumowania
if ($game['status'] === 'finished') {
    header("Location: finish.php?game=" . $game_id);
    exit;
}

// Upewnij się, że jesteśmy zapisani jako gracz
$current_player = hm_ensure_player($conn, $game_id);
if (!$current_player) {
    require_once __DIR__ . '/../../includes/header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>Nie udało się dołączyć do gry jako gracz.</div></div>";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// Czy ten gracz jest prowadzącym-obserwatorem w trybie host?
$is_host_observer = ($game['mode'] === 'host' && (int)$current_player['is_creator'] === 1);

// Opisy trybów
$mode_desc = [
    'coop'   => 'Wszyscy razem przeciwko wisielcowi (kooperacja)',
    'battle' => 'Bitwa o hasło (kto pierwszy odgadnie)',
    'duel'   => 'Pojedynek 1 na 1',
    'host'   => 'Prowadzący wpisuje hasło (prowadzący nie gra, tylko obserwuje)',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mt-4">
    <h1>Wisielec – rozgrywka</h1>

    <div class="row mt-3">
        <!-- Lewa kolumna – rysunek wisielca + info o błędach -->
        <div class="col-md-4 mb-3">
            <div class="card text-center">
                <div class="card-header">
                    Wisielec
                </div>
                <div class="card-body">
                    <div id="hangman-figure" style="min-height: 200px; font-family: monospace; white-space: pre; font-size: 14px;">
                        Ładowanie...
                    </div>
                    <p class="mt-3">
                        Błędów: <span id="errors-counter">0</span> /
                        <span id="errors-max"><?php echo (int)$game['max_errors']; ?></span>
                    </p>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    Informacje o grze
                </div>
                <div class="card-body">
                    <p><strong>Tryb:</strong>
                        <?php
                        $mode = $game['mode'];
                        echo htmlspecialchars($mode_desc[$mode] ?? $mode);
                        ?>
                    </p>
                    <?php if (!empty($game['category'])): ?>
                        <p><strong>Kategoria:</strong>
                            <?php echo htmlspecialchars($game['category']); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($game['hint'])): ?>
                        <p><strong>Podpowiedź:</strong>
                            <?php echo htmlspecialchars($game['hint']); ?>
                        </p>
                    <?php endif; ?>
                    <p class="text-muted small mb-0">
                        Hasło jest ukryte – odgaduj litery lub całe zdanie.
                    </p>
                    <?php if ($is_host_observer && $game['mode'] === 'host'): ?>
                        <p class="text-muted small mt-1">
                            Jesteś prowadzącym – sam wymyśliłeś hasło, dlatego tylko obserwujesz grę (nie możesz zgadywać).
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Środek – hasło i zgadywanie -->
        <div class="col-md-5 mb-3">
            <div class="card mb-3">
                <div class="card-header">
                    Hasło
                </div>
                <div class="card-body">
                    <div id="phrase-display"
                         style="font-size: 24px; letter-spacing: 4px; word-wrap: break-word;">
                        Ładowanie...
                    </div>
                    <p class="mt-3 mb-1"><strong>Użyte litery:</strong></p>
                    <div id="used-letters" class="mb-2">
                        -
                    </div>
                    <div id="last-guess" class="text-muted small">
                        &nbsp;
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    Zgadnij literę
                </div>
                <div class="card-body">
                    <form id="letter-form">
                        <div class="input-group">
                            <input type="text"
                                   name="letter"
                                   id="letter-input"
                                   class="form-control"
                                   maxlength="1"
                                   autocomplete="off"
                                   placeholder="Wpisz jedną literę">
                            <button type="submit" class="btn btn-primary">
                                Zgadnij literę
                            </button>
                        </div>
                        <div class="form-text">
                            Litery będą automatycznie zamienione na wielkie.
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    Zgadnij całe hasło
                </div>
                <div class="card-body">
                    <form id="phrase-form">
                        <div class="input-group">
                            <input type="text"
                                   name="phrase_guess"
                                   id="phrase-input"
                                   class="form-control"
                                   autocomplete="off"
                                   placeholder="Spróbuj odgadnąć całe hasło">
                            <button type="submit" class="btn btn-success">
                                Zgadnij hasło
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="game-message" class="mt-3"></div>
        </div>

        <!-- Prawa kolumna – gracze i punkty -->
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-header">
                    Gracze
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Gracz</th>
                            <th class="text-end">Punkty</th>
                        </tr>
                        </thead>
                        <tbody id="players-table-body">
                        <tr><td colspan="2" class="text-center">Ładowanie...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Info o turze / roli -->
            <div id="turn-info" class="mt-2 text-muted small"></div>

            <div class="mt-3 text-muted small">
                <p class="mb-1">
                    Jesteś zalogowany jako:
                    <strong><?php echo htmlspecialchars($current_player['nickname']); ?></strong>
                </p>
                <?php if ((int)$current_player['is_creator'] === 1): ?>
                    <p class="mb-0">
                        To Ty utworzyłeś tę grę.
                        <?php if ($is_host_observer && $game['mode'] === 'host'): ?>
                            W tym trybie nie zgadujesz hasła – tylko obserwujesz.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p class="mb-0">Dołączyłeś do gry utworzonej przez innego gracza.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const gameId = <?php echo $game_id; ?>;
const myPlayerId = <?php echo (int)$current_player['id']; ?>;
const isObserver = <?php echo $is_host_observer ? 'true' : 'false'; ?>;

function showMessage(text, type='info') {
    const box = document.getElementById('game-message');
    if (!box) return;
    if (!text) {
        box.innerHTML = '';
        return;
    }
    let cls = 'alert alert-info';
    if (type === 'error') cls = 'alert alert-danger';
    if (type === 'success') cls = 'alert alert-success';
    if (type === 'warning') cls = 'alert alert-warning';
    box.className = cls;
    box.textContent = text;
}

/**
 * Proste rysowanie wisielca w ASCII na podstawie liczby błędów.
 */
function renderHangman(errors, maxErrors) {
    const div = document.getElementById('hangman-figure');
    if (!div) return;

    const steps = [
        `
        
        
        
        
        
        
        
=========
        `,
        `
     |
     |
     |
     |
     |
     |
=========
        `,
        `
 +---+
     |
     |
     |
     |
     |
=========
        `,
        `
 +---+
 |   |
     |
     |
     |
     |
=========
        `,
        `
 +---+
 |   |
 O   |
     |
     |
     |
=========
        `,
        `
 +---+
 |   |
 O   |
 |   |
     |
     |
=========
        `,
        `
 +---+
 |   |
 O   |
/|   |
     |
     |
=========
        `,
        `
 +---+
 |   |
 O   |
/|\\  |
     |
     |
=========
        `,
        `
 +---+
 |   |
 O   |
/|\\  |
/    |
     |
=========
        `,
        `
 +---+
 |   |
 O   |
/|\\  |
/ \\  |
     |
=========
        `
    ];

    const maxStep = steps.length - 1;
    let stepIndex;
    if (maxErrors <= 0) {
        stepIndex = 0;
    } else {
        stepIndex = Math.round((errors / maxErrors) * maxStep);
        if (stepIndex < 0) stepIndex = 0;
        if (stepIndex > maxStep) stepIndex = maxStep;
    }

    div.textContent = steps[stepIndex];
}

/**
 * Pobieranie stanu gry (state.php) i aktualizacja UI
 */
function loadState() {
    fetch('state.php?game=' + gameId)
        .then(r => r.json())
        .then(data => {
            if (!data || data.success === false) {
                if (data && data.error) {
                    showMessage(data.error, 'error');
                }
                return;
            }

            // zapamiętujemy ostatni stan (do sprawdzania tury w submitach)
            window.lastGameState = data;

            const game = data.game;
            if (!game) return;

            // Przekierowania na wypadek zmiany statusu
            if (game.status === 'lobby') {
                window.location.href = 'lobby.php?game=' + gameId;
                return;
            }
            if (game.status === 'finished') {
                window.location.href = 'finish.php?game=' + gameId;
                return;
            }

            // Hasło zamaskowane
            const phraseDiv = document.getElementById('phrase-display');
            if (phraseDiv && data.phrase_masked) {
                phraseDiv.textContent = data.phrase_masked;
            }

            // Użyte litery
            const lettersDiv = document.getElementById('used-letters');
            if (lettersDiv) {
                if (data.used_letters && data.used_letters.length > 0) {
                    lettersDiv.textContent = data.used_letters.join(' ');
                } else {
                    lettersDiv.textContent = '-';
                }
            }

            // Ostatni ruch
            const lastDiv = document.getElementById('last-guess');
            if (lastDiv) {
                if (data.last_guess) {
                    const lg = data.last_guess;
                    let txt = 'Ostatni ruch: ' + (lg.nickname || 'ktoś') + ' zgadł ';
                    if (lg.type === 'letter') {
                        txt += 'literę "' + lg.guess + '" ';
                    } else {
                        txt += 'hasło "' + lg.guess + '" ';
                    }
                    txt += lg.is_correct ? '– trafione!' : '– pudło.';
                    lastDiv.textContent = txt;
                } else {
                    lastDiv.textContent = '';
                }
            }

            // Błędy
            const errorsCounter = document.getElementById('errors-counter');
            if (errorsCounter) {
                errorsCounter.textContent = game.errors_count ?? 0;
            }

            const errorsMax = document.getElementById('errors-max');
            let maxErrors = parseInt(errorsMax ? errorsMax.textContent : (game.max_errors ?? 8));
            if (isNaN(maxErrors)) {
                maxErrors = game.max_errors ?? 8;
            }

            renderHangman(game.errors_count ?? 0, maxErrors);

            // Tabela graczy
            const tbody = document.getElementById('players-table-body');
            if (tbody && data.players) {
                tbody.innerHTML = '';
                if (data.players.length === 0) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 2;
                    td.className = 'text-center';
                    td.textContent = 'Brak graczy.';
                    tr.appendChild(td);
                    tbody.appendChild(tr);
                } else {
                    data.players.forEach(p => {
                        const tr = document.createElement('tr');

                        const tdName = document.createElement('td');
                        tdName.textContent = p.nickname || 'Gracz';
                        if (parseInt(p.is_creator) === 1) {
                            const span = document.createElement('span');
                            span.className = 'badge bg-primary ms-1';
                            span.textContent = 'T';
                            tdName.appendChild(span);
                        }

                        const tdScore = document.createElement('td');
                        tdScore.className = 'text-end';
                        tdScore.textContent = (p.score || 0) + ' pkt';

                        tr.appendChild(tdName);
                        tr.appendChild(tdScore);
                        tbody.appendChild(tr);
                    });
                }
            }

            // Informacja o turze / roli
            const turnInfo = document.getElementById('turn-info');
            const letterInput = document.getElementById('letter-input');
            const phraseInput = document.getElementById('phrase-input');
            const letterBtn = document.querySelector('#letter-form button[type="submit"]');
            const phraseBtn = document.querySelector('#phrase-form button[type="submit"]');

            if (turnInfo) {
                if (game.mode === 'duel') {
                    const ct = data.current_turn_player;
                    if (ct && ct.id) {
                        const myTurn = (parseInt(ct.id) === myPlayerId);

                        if (myTurn) {
                            turnInfo.textContent = "Twoja tura – możesz zgadywać.";
                        } else {
                            turnInfo.textContent = "Tura gracza: " + ct.nickname + ". Poczekaj na swoją kolej.";
                        }

                        [letterInput, phraseInput, letterBtn, phraseBtn].forEach(el => {
                            if (el) el.disabled = !myTurn || isObserver;
                        });

                        if (isObserver) {
                            turnInfo.textContent = "Jesteś prowadzącym – obserwujesz grę, ale nie zgadujesz.";
                        }
                    } else {
                        turnInfo.textContent = "Pojedynek 1 na 1 – oczekiwanie na graczy...";
                        [letterInput, phraseInput, letterBtn, phraseBtn].forEach(el => {
                            if (el) el.disabled = true;
                        });
                    }
                } else {
                    // Tryby coop / battle / host – brak tur,
                    // ale w trybie HOST prowadzący (observer) nie zgaduje
                    if (game.mode === 'host' && isObserver) {
                        turnInfo.textContent = "Jesteś prowadzącym – obserwujesz grę, ale nie zgadujesz.";
                    } else {
                        turnInfo.textContent = "";
                    }

                    const enable = !(game.mode === 'host' && isObserver);
                    [letterInput, phraseInput, letterBtn, phraseBtn].forEach(el => {
                        if (el) el.disabled = !enable;
                    });
                }
            }

            // Jeśli serwer zwrócił jakąś wiadomość informacyjną
            if (data.message) {
                showMessage(data.message, data.message_type || 'info');
            }
        })
        .catch(err => {
            console.error('Błąd podczas pobierania stanu gry:', err);
        });
}

// Obsługa formularza litery
document.getElementById('letter-form')?.addEventListener('submit', function (e) {
    e.preventDefault();

    if (isObserver) {
        showMessage('Jako prowadzący nie możesz zgadywać.', 'warning');
        return;
    }

    // W pojedynku pilnujemy, czy to moja tura
    if (window.lastGameState &&
        window.lastGameState.game &&
        window.lastGameState.game.mode === 'duel') {

        const ct = window.lastGameState.current_turn_player;
        if (!ct || parseInt(ct.id) !== myPlayerId) {
            showMessage('Teraz jest tura przeciwnika.', 'warning');
            return;
        }
    }

    const input = document.getElementById('letter-input');
    if (!input) return;
    let val = input.value.trim();
    if (!val) {
        showMessage('Wpisz literę.', 'warning');
        return;
    }
    val = val.toUpperCase().charAt(0); // tylko pierwszy znak, wielka litera

    const fd = new FormData();
    fd.append('game_id', gameId);
    fd.append('type', 'letter');
    fd.append('letter', val);

    fetch('move.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.json())
        .then(data => {
            if (!data || data.success === false) {
                showMessage(data && data.error ? data.error : 'Nie udało się wysłać ruchu.', 'error');
                return;
            }
            input.value = '';
            showMessage('', 'info');
            loadState();
        })
        .catch(err => {
            console.error('Błąd przy wysyłaniu litery:', err);
            showMessage('Wystąpił błąd podczas wysyłania litery.', 'error');
        });
});

// Obsługa formularza hasła
document.getElementById('phrase-form')?.addEventListener('submit', function (e) {
    e.preventDefault();

    if (isObserver) {
        showMessage('Jako prowadzący nie możesz zgadywać.', 'warning');
        return;
    }

    // W pojedynku pilnujemy, czy to moja tura
    if (window.lastGameState &&
        window.lastGameState.game &&
        window.lastGameState.game.mode === 'duel') {

        const ct = window.lastGameState.current_turn_player;
        if (!ct || parseInt(ct.id) !== myPlayerId) {
            showMessage('Teraz jest tura przeciwnika.', 'warning');
            return;
        }
    }

    const input = document.getElementById('phrase-input');
    if (!input) return;
    const val = input.value.trim();
    if (!val) {
        showMessage('Wpisz propozycję hasła.', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('game_id', gameId);
    fd.append('type', 'phrase');
    fd.append('phrase_guess', val);

    fetch('move.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.json())
        .then(data => {
            if (!data || data.success === false) {
                showMessage(data && data.error ? data.error : 'Nie udało się wysłać propozycji hasła.', 'error');
                return;
            }
            input.value = '';
            showMessage('', 'info');
            loadState();
        })
        .catch(err => {
            console.error('Błąd przy wysyłaniu hasła:', err);
            showMessage('Wystąpił błąd podczas wysyłania hasła.', 'error');
        });
});

// Startowe pobranie stanu + odświeżanie co 2 sekundy
loadState();
setInterval(loadState, 2000);
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
