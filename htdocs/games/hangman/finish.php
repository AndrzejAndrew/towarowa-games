<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/stats.php'; // ← GLOBALNE STATYSTYKI

// ------------------------
// Funkcja błędu
// ------------------------
function hm_finish_error($msg) {
    echo "<div class='container mt-4'>
            <div class='alert alert-danger'>" . htmlspecialchars($msg) . "</div>
          </div>";
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// ------------------------
// ID GRY
// ------------------------
$game_id = (int)($_GET['game'] ?? 0);
if ($game_id <= 0) {
    hm_finish_error("Brak ID gry.");
}

// Pobieramy grę
$res_game = mysqli_query($conn,
    "SELECT *
     FROM hangman_games
     WHERE id = $game_id"
);
$game = $res_game ? mysqli_fetch_assoc($res_game) : null;

if (!$game) {
    hm_finish_error("Nie znaleziono takiej gry.");
}

// Jeśli gra jeszcze nie jest skończona – przekieruj
if ($game['status'] === 'playing') {
    header("Location: game.php?game=" . $game_id);
    exit;
}
if ($game['status'] === 'lobby') {
    header("Location: lobby.php?game=" . $game_id);
    exit;
}

// ------------------------
// POBRANIE GRACZY (POPRAWIONE!!!)
// hangman_players MA user_id – wcześniej nie pobierałeś tej kolumny
// ------------------------
$players = [];
$res_pl = mysqli_query($conn,
    "SELECT id, user_id, nickname, score, is_creator
     FROM hangman_players
     WHERE game_id = $game_id
     ORDER BY score DESC, id ASC"
);

if ($res_pl) {
    while ($row = mysqli_fetch_assoc($res_pl)) {
        $row['id']        = (int)$row['id'];
        $row['user_id']   = (int)$row['user_id'];   // ← KLUCZOWA LINIJKA
        $row['score']     = (int)$row['score'];
        $row['is_creator']= (int)$row['is_creator'];
        $players[] = $row;
    }
}

// Mapa graczy
$players_by_id = [];
foreach ($players as $p) {
    $players_by_id[$p['id']] = $p;
}

// ------------------------
// USTALENIE WYNIKU / ZWYCIĘZCY
// ------------------------
$winner_player_id = $game['winner_player_id'] ? (int)$game['winner_player_id'] : null;
$winner = null;
if ($winner_player_id && isset($players_by_id[$winner_player_id])) {
    $winner = $players_by_id[$winner_player_id];
}

$max_errors  = (int)$game['max_errors'];
$errors_now  = (int)$game['errors_count'];
$phrase      = $game['phrase'];
$phrase_html = htmlspecialchars($phrase);
$mode        = $game['mode']; // battle, coop, duel

// ------------------------
// Historia prób
// ------------------------
$guesses = [];
$res_g = mysqli_query($conn,
    "SELECT g.guess_type, g.guess, g.is_correct, g.created_at,
            p.nickname AS player_nickname
     FROM hangman_guesses g
     LEFT JOIN hangman_players p ON p.id = g.player_id
     WHERE g.game_id = $game_id
     ORDER BY g.id ASC"
);
if ($res_g) {
    while ($row = mysqli_fetch_assoc($res_g)) {
        $guesses[] = [
            'type'       => $row['guess_type'],
            'guess'      => $row['guess'],
            'is_correct' => (int)$row['is_correct'] === 1,
            'nickname'   => $row['player_nickname'] ?: 'Nieznany gracz',
            'created_at' => $row['created_at'],
        ];
    }
}

$mode_desc = [
    'coop'   => 'Wszyscy razem przeciwko wisielcowi (kooperacja)',
    'battle' => 'Bitwa o hasło (kto pierwszy odgadnie / kto zdobył najwięcej punktów)',
    'duel'   => 'Pojedynek 1 na 1',
];

// ======================================================
//  GLOBALNE STATYSTYKI – XP, WYGRANE, PRZEGRANE, REMISY
// ======================================================
//
// 3 scenariusze:
//  - jest $winner -> ktoś odgadł hasło / wygrał battle / wygrał duel
//  - brak winner -> Wisielec wygrał → przegrali wszyscy (coop)
//  - battle mode może mieć zwycięzcę punktowego
//
// USER_ID always from $players[]
//
// ======================================================

if (!empty($players)) {

    if ($winner) {
        // ---------- ZWYCIĘZCA ----------
        $winner_uid = (int)$winner['user_id'];

        if ($winner_uid > 0) {
            stats_register_result($winner_uid, 'hangman', 'win');
        }

        // Reszta → przegrana
        foreach ($players as $p) {
            if ($p['id'] !== $winner['id'] && $p['user_id'] > 0) {
                stats_register_result($p['user_id'], 'hangman', 'loss');
            }
        }
    }
    else {
        // ---------- WISIELEC WYGRAŁ -> wszyscy przegrali ----------
        foreach ($players as $p) {
            if ($p['user_id'] > 0) {
                stats_register_result($p['user_id'], 'hangman', 'loss');
            }
        }
    }
}

?>
<div class="container mt-4">
    <h1>Wisielec – podsumowanie gry</h1>

    <div class="row mt-3">
        <div class="col-md-6 mb-3">
            <div class="card">
                <div class="card-header">Wynik</div>
                <div class="card-body">
                    <?php if ($winner): ?>
                        <div class="alert alert-success">
                            <?php if ($mode === 'battle'): ?>
                                <strong>Bitwa rozstrzygnięta!</strong><br>
                                Gracz <strong><?php echo htmlspecialchars($winner['nickname']); ?></strong>
                                zdobył najwięcej punktów!
                            <?php elseif ($mode === 'duel'): ?>
                                <strong>Pojedynek zakończony!</strong><br>
                                Wygrał gracz <strong><?php echo htmlspecialchars($winner['nickname']); ?></strong>.
                            <?php else: ?>
                                <strong>Brawo!</strong><br>
                                Gracz <strong><?php echo htmlspecialchars($winner['nickname']); ?></strong>
                                odgadł hasło i poprowadził drużynę do zwycięstwa.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <strong>Niestety...</strong><br>
                            Wisielec wygrał – nie udało się odgadnąć hasła.
                        </div>
                    <?php endif; ?>

                    <p><strong>Hasło:</strong><br>
                        <span style="font-size: 1.3rem;"><?php echo $phrase_html; ?></span>
                    </p>

                    <p><strong>Tryb gry:</strong> <?php echo htmlspecialchars($mode_desc[$mode] ?? $mode); ?></p>
                    <p><strong>Maks. błędów:</strong> <?php echo $max_errors; ?></p>
                    <p><strong>Popełnione błędy:</strong> <?php echo $errors_now; ?></p>
                </div>
            </div>
        </div>

        <!-- Tabela graczy -->
        <div class="col-md-6 mb-3">
            <div class="card">
                <div class="card-header">Wyniki graczy</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead><tr><th>Gracz</th><th class="text-end">Punkty</th></tr></thead>
                        <tbody>
                        <?php foreach ($players as $p): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($p['nickname']); ?>
                                    <?php if ($p['is_creator']): ?>
                                        <span class="badge bg-primary ms-1">Twórca gry</span>
                                    <?php endif; ?>
                                    <?php if ($winner && $winner['id'] === $p['id']): ?>
                                        <span class="badge bg-success ms-1">Zwycięzca</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo $p['score']; ?> pkt</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Historia prób -->
    <div class="row mt-3">
        <div class="col-12 mb-3">
            <div class="card">
                <div class="card-header">Historia prób</div>
                <div class="card-body p-0">
                    <?php if (empty($guesses)): ?>
                        <div class="p-3 text-muted">Brak prób.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table mb-0 table-sm">
                                <thead>
                                    <tr>
                                        <th style="width:130px;">Czas</th>
                                        <th>Gracz</th>
                                        <th>Rodzaj</th>
                                        <th>Co zgadywał</th>
                                        <th>Wynik</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($guesses as $g): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($g['created_at']); ?></td>
                                            <td><?php echo htmlspecialchars($g['nickname']); ?></td>
                                            <td><?php echo $g['type']==='letter'?'Litera':'Hasło'; ?></td>
                                            <td>
                                                <?php if ($g['type']==='letter'): ?>
                                                    <strong><?php echo htmlspecialchars(mb_strtoupper($g['guess'],'UTF-8')); ?></strong>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($g['guess']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($g['is_correct']): ?>
                                                    <span class="text-success">Trafione</span>
                                                <?php else: ?>
                                                    <span class="text-danger">Pudło</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Przyciski -->
    <div class="row mt-3 mb-4">
        <div class="col-12 d-flex flex-wrap gap-2">
            <a href="index.php" class="btn btn-primary">Zagraj ponownie</a>
            <a href="../../index.php" class="btn btn-secondary">Wróć do strony głównej</a>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
