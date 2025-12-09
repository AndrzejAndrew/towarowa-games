<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Pomocniczo – określenie aktualnego user_id (jeśli jest)
function hm_get_current_user_id(): ?int {
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

// Pomocniczo – ustalenie nicka (z auth / sesji)
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

// Upewnia się, że bieżąca osoba jest dodana do hangman_players. Zwraca wiersz gracza.
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

    // 2) Gość / brak user_id – próbujemy po nicku
    $nickname = hm_resolve_nickname();
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

    // 3) Jeśli dalej nie ma – tworzymy nowego gracza
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
// Pobranie gry
// -------------------------------------------
$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    header("Location: index.php");
    exit;
}

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

// Najpierw upewnij się, że bieżący użytkownik jest na liście graczy
$current_player = hm_ensure_player($conn, $game_id);

// Czy ta osoba jest prowadzącym-obserwatorem (tylko w trybie 'host')?
$is_host_observer = ($game['mode'] === 'host'
    && $current_player
    && (int)$current_player['is_creator'] === 1
);

// -------------------------------------------
// AJAX: zwróć JSON ze stanem lobby (lista graczy + status)
// -------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header("Content-Type: application/json; charset=utf-8");

    $players = [];
    $res_pl = mysqli_query($conn,
        "SELECT nickname, score, is_creator
         FROM hangman_players
         WHERE game_id = $game_id
         ORDER BY is_creator DESC, id ASC"
    );
    if ($res_pl) {
        while ($row = mysqli_fetch_assoc($res_pl)) {
            $players[] = $row;
        }
    }

    echo json_encode([
        'status'  => $game['status'],
        'players' => $players,
    ]);
    exit;
}

// -------------------------------------------
// Obsługa POST – start gry
// -------------------------------------------
$start_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'start'
) {
    if ($game['mode'] === 'host' && $is_host_observer) {
        // TRYB HOST – prowadzący (is_creator=1) może zacząć grę,
        // ale sam będzie tylko obserwatorem
        if ($game['status'] !== 'lobby') {
            $start_error = "Gra nie jest już w lobby.";
        } else {
            $res_upd = mysqli_query($conn,
                "UPDATE hangman_games
                 SET status = 'playing'
                 WHERE id = $game_id"
            );

            if ($res_upd) {
                header("Location: game.php?game=" . $game_id);
                exit;
            } else {
                $start_error = "Nie udało się rozpocząć gry: " . mysqli_error($conn);
            }
        }
    } else {
        // POZOSTAŁE TRYBY – klasyczna logika (twórca jest graczem)
        if (!$current_player) {
            $start_error = "Nie udało się zidentyfikować gracza.";
        } elseif ((int)$current_player['is_creator'] !== 1) {
            $start_error = "Tylko twórca gry może ją rozpocząć.";
        } elseif ($game['status'] !== 'lobby') {
            $start_error = "Gra nie jest już w lobby.";
        } else {
            // Ustaw status na 'playing'
            // W trybie 'duel' dodatkowo ustawiamy, kto zaczyna (twórca gry)
            $set_turn = '';
            if ($game['mode'] === 'duel' && $current_player) {
                $set_turn = ", current_turn_player_id = " . (int)$current_player['id'];
            }

            $res_upd = mysqli_query($conn,
                "UPDATE hangman_games
                 SET status = 'playing' $set_turn
                 WHERE id = $game_id"
            );

            if ($res_upd) {
                header("Location: game.php?game=" . $game_id);
                exit;
            } else {
                $start_error = "Nie udało się rozpocząć gry: " . mysqli_error($conn);
            }
        }
    }
}

// -------------------------------------------
// Jeśli gra już wystartowała / zakończona – przekieruj
// -------------------------------------------
if ($game['status'] === 'playing') {
    header("Location: game.php?game=" . $game_id);
    exit;
}
if ($game['status'] === 'finished') {
    header("Location: finish.php?game=" . $game_id);
    exit;
}

// Ponownie pobierz listę graczy do wyświetlenia
$players = [];
$res_pl = mysqli_query($conn,
    "SELECT nickname, score, is_creator
     FROM hangman_players
     WHERE game_id = $game_id
     ORDER BY is_creator DESC, id ASC"
);
if ($res_pl) {
    while ($row = mysqli_fetch_assoc($res_pl)) {
        $players[] = $row;
    }
}

// Przygotuj link do gry (do skopiowania innym)
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path      = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
$game_link = $scheme . '://' . $host . $path . '/lobby.php?game=' . $game_id;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container mt-4">
    <h1>Lobby – Wisielec</h1>

    <div class="row mt-3">
        <div class="col-md-6 mb-3">
            <div class="card">
                <div class="card-header">
                    Informacje o grze
                </div>
                <div class="card-body">
                    <p><strong>Kod gry:</strong> <?php echo htmlspecialchars($game['code']); ?></p>
                    <div class="mb-3">
                        <label class="form-label">Link do gry (udostępnij znajomym):</label>
                        <input type="text" class="form-control" readonly
                               value="<?php echo htmlspecialchars($game_link); ?>"
                               onclick="this.select();">
                    </div>
                    <p><strong>Tryb:</strong>
                        <?php
                        $mode_desc = [
                            'coop'   => 'Wszyscy razem przeciwko wisielcowi (kooperacja)',
                            'battle' => 'Bitwa o hasło (kto pierwszy odgadnie)',
                            'duel'   => 'Pojedynek 1 na 1',
                            'host'   => 'Prowadzący wpisuje hasło (prowadzący nie gra, tylko obserwuje)',
                        ];
                        $mode = $game['mode'];
                        echo htmlspecialchars($mode_desc[$mode] ?? $mode);
                        ?>
                    </p>
                    <p><strong>Maksymalna liczba błędów:</strong>
                        <?php echo (int)$game['max_errors']; ?>
                    </p>
                    <?php
                    $difficulty_labels = [
                        'manual' => 'Ręcznie wpisane hasło',
                        'easy'   => 'Łatwy',
                        'medium' => 'Średni',
                        'hard'   => 'Trudny',
                    ];
                    if (!empty($game['difficulty'])): ?>
                        <p><strong>Poziom trudności:</strong>
                            <?php echo htmlspecialchars($difficulty_labels[$game['difficulty']] ?? $game['difficulty']); ?>
                        </p>
                    <?php endif; ?>

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

                    <?php if ($is_host_observer && $game['mode'] === 'host'): ?>
                        <p class="mt-2 text-muted small">
                            Jesteś prowadzącym tej gry – hasło, które wpisałeś, jest tajne.
                            Nie bierzesz udziału w zgadywaniu, tylko obserwujesz grę.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <div class="card">
                <div class="card-header">
                    Gracze w lobby
                </div>
                <div class="card-body">
                    <ul id="players-list" class="list-group mb-3">
                        <?php if (empty($players)): ?>
                            <li class="list-group-item">Brak graczy (to nie powinno się zdarzyć 😉)</li>
                        <?php else: ?>
                            <?php foreach ($players as $p): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        <?php echo htmlspecialchars($p['nickname']); ?>
                                        <?php if ((int)$p['is_creator'] === 1): ?>
                                            <span class="badge bg-primary ms-2">Twórca gry</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-muted small">
                                        <?php echo (int)$p['score']; ?> pkt
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>

                    <?php if ($start_error): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($start_error); ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $can_start = false;
                    if ($game['mode'] === 'host' && $is_host_observer) {
                        $can_start = true;
                    } elseif ($current_player && (int)$current_player['is_creator'] === 1) {
                        $can_start = true;
                    }
                    ?>

                    <?php if ($can_start): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="start">
                            <button type="submit" class="btn btn-success">
                                Rozpocznij grę
                            </button>
                        </form>
                        <p class="mt-2 text-muted small">
                            Rozpocznij grę, gdy wszyscy gracze dołączą do lobby.
                        </p>
                    <?php else: ?>
                        <p class="text-muted">
                            Czekaj, aż twórca gry rozpocznie rozgrywkę...
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <p class="mt-3 text-muted small">
        Lobby odświeża się automatycznie co kilka sekund. Gdy gra się rozpocznie, zostaniesz przeniesiony do planszy.
    </p>
</div>

<script>
function refreshLobby() {
    fetch('lobby.php?game=<?php echo $game_id; ?>&ajax=1')
        .then(r => r.json())
        .then(data => {
            if (!data || !data.status) return;

            // Jeśli gra wystartowała albo się skończyła – przekieruj
            if (data.status === 'playing') {
                window.location.href = 'game.php?game=<?php echo $game_id; ?>';
                return;
            }
            if (data.status === 'finished') {
                window.location.href = 'finish.php?game=<?php echo $game_id; ?>';
                return;
            }

            const ul = document.getElementById('players-list');
            if (!ul) return;
            ul.innerHTML = '';

            if (!data.players || data.players.length === 0) {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.textContent = 'Brak graczy...';
                ul.appendChild(li);
                return;
            }

            data.players.forEach(p => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';

                const leftSpan = document.createElement('span');
                leftSpan.textContent = p.nickname;

                if (parseInt(p.is_creator) === 1) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-primary ms-2';
                    badge.textContent = 'Twórca gry';
                    leftSpan.appendChild(badge);
                }

                const rightSpan = document.createElement('span');
                rightSpan.className = 'text-muted small';
                rightSpan.textContent = (p.score || 0) + ' pkt';

                li.appendChild(leftSpan);
                li.appendChild(rightSpan);
                ul.appendChild(li);
            });
        })
        .catch(err => {
            console.error('Błąd podczas odświeżania lobby:', err);
        });
}

// Odświeżaj co 2 sekundy
setInterval(refreshLobby, 2000);
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
