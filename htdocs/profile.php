<?php
// profile.php – profil użytkownika / statystyki

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

if (!is_logged_in()) {
    ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-body">
                <h1>Mój profil</h1>
                <p>Musisz być zalogowany, aby zobaczyć swój profil i statystyki.</p>
                <p>Zaloguj się w formularzu u góry strony, a następnie wróć tutaj.</p>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// --------------------------------------
// 1. Dane użytkownika
// --------------------------------------
$sqlUser = "SELECT username, created_at FROM users WHERE id = $user_id";
$resUser = mysqli_query($conn, $sqlUser);
$userRow = $resUser ? mysqli_fetch_assoc($resUser) : null;

$username   = $userRow['username'] ?? 'Nieznany';
$created_at = $userRow['created_at'] ?? null;

// --------------------------------------
// 2. XP i poziom
// --------------------------------------

// całkowity XP
$sqlXp = "
    SELECT COALESCE(SUM(xp_delta), 0) AS total_xp
    FROM xp_log
    WHERE user_id = $user_id
";
$resXp = mysqli_query($conn, $sqlXp);
$rowXp = $resXp ? mysqli_fetch_assoc($resXp) : ['total_xp' => 0];
$total_xp = (int)$rowXp['total_xp'];

// poziomy z user_levels
$levels = [];
$resLvl = mysqli_query($conn,
    "SELECT level, xp_required
     FROM user_levels
     ORDER BY xp_required ASC"
);
if ($resLvl) {
    while ($r = mysqli_fetch_assoc($resLvl)) {
        $levels[] = [
            'level'       => (int)$r['level'],
            'xp_required' => (int)$r['xp_required'],
        ];
    }
}

// wyliczenie aktualnego poziomu
$current_level     = 1;
$current_level_xp  = 0;
$next_level        = null;
$next_level_xp_req = null;

if (!empty($levels)) {
    foreach ($levels as $idx => $lvl) {
        if ($total_xp >= $lvl['xp_required']) {
            $current_level    = $lvl['level'];
            $current_level_xp = $lvl['xp_required'];
            $next_level       = null;
            $next_level_xp_req = null;
            if (isset($levels[$idx+1])) {
                $next_level        = $levels[$idx+1]['level'];
                $next_level_xp_req = $levels[$idx+1]['xp_required'];
            }
        }
    }
}

// progress paska poziomu
$xp_in_level   = $total_xp - $current_level_xp;
$xp_to_next    = ($next_level_xp_req !== null) ? max(0, $next_level_xp_req - $total_xp) : 0;
$level_percent = 100;
if ($next_level_xp_req !== null && $next_level_xp_req > $current_level_xp) {
    $range         = $next_level_xp_req - $current_level_xp;
    $level_percent = max(0, min(100, round(($xp_in_level / $range) * 100)));
}

// --------------------------------------
// 3. Statystyki globalne (game_results)
// --------------------------------------

// ogółem
$sqlGlobal = "
    SELECT
        COUNT(*) AS total_games,
        SUM(result = 'win')   AS wins,
        SUM(result = 'loss')  AS losses,
        SUM(result = 'draw')  AS draws
    FROM game_results
    WHERE user_id = $user_id
";
$resGlobal = mysqli_query($conn, $sqlGlobal);
$rowGlobal = $resGlobal ? mysqli_fetch_assoc($resGlobal) : null;

$total_games = (int)($rowGlobal['total_games'] ?? 0);
$wins        = (int)($rowGlobal['wins'] ?? 0);
$losses      = (int)($rowGlobal['losses'] ?? 0);
$draws       = (int)($rowGlobal['draws'] ?? 0);

$games_count_for_ratio = $wins + $losses + $draws;
$winrate = ($games_count_for_ratio > 0)
    ? round(($wins / $games_count_for_ratio) * 100, 1)
    : 0.0;

// --------------------------------------
// 4. Statystyki per gra (game_results)
// --------------------------------------
$sqlPerGame = "
    SELECT
        game_code,
        COUNT(*)                      AS games,
        SUM(result = 'win')           AS wins,
        SUM(result = 'loss')          AS losses,
        SUM(result = 'draw')          AS draws,
        COALESCE(SUM(points), 0)      AS points
    FROM game_results
    WHERE user_id = $user_id
    GROUP BY game_code
    ORDER BY games DESC
";
$resPerGame = mysqli_query($conn, $sqlPerGame);

$perGame = [];
if ($resPerGame) {
    while ($r = mysqli_fetch_assoc($resPerGame)) {
        $perGame[] = [
            'game_code' => $r['game_code'],
            'games'     => (int)$r['games'],
            'wins'      => (int)$r['wins'],
            'losses'    => (int)$r['losses'],
            'draws'     => (int)$r['draws'],
            'points'    => (int)$r['points'],
        ];
    }
}

// ulubiona gra = ta z największą liczbą gier
$favourite_game = null;
if (!empty($perGame)) {
    $maxG = -1;
    foreach ($perGame as $pg) {
        if ($pg['games'] > $maxG) {
            $maxG = $pg['games'];
            $favourite_game = $pg['game_code'];
        }
    }
}

// ładniejsze nazwy gier
function game_label($code) {
    $map = [
        'quiz'        => 'Quiz',
        'ttt'         => 'Kółko i krzyżyk',
        'pkn'         => 'Papier / Kamień / Nożyce',
        'papersoccer' => 'Papierowa piłka nożna',
        'battleship'  => 'Statki',
        'hangman'     => 'Wisielec',
    ];
    return $map[$code] ?? $code;
}

// --------------------------------------
// 5. Seria zwycięstw (game_results)
// --------------------------------------
$sqlSeq = "
    SELECT result, game_code, created_at
    FROM game_results
    WHERE user_id = $user_id
      AND result IN ('win','loss','draw')
    ORDER BY created_at ASC, id ASC
";
$resSeq = mysqli_query($conn, $sqlSeq);

$current_streak = 0;
$best_streak    = 0;

if ($resSeq) {
    while ($r = mysqli_fetch_assoc($resSeq)) {
        if ($r['result'] === 'win') {
            $current_streak++;
            if ($current_streak > $best_streak) {
                $best_streak = $current_streak;
            }
        } else {
            $current_streak = 0;
        }
    }
}

// --------------------------------------
// 6. Ostatnie gry
// --------------------------------------
$sqlLast = "
    SELECT game_code, result, points, created_at
    FROM game_results
    WHERE user_id = $user_id
    ORDER BY created_at DESC, id DESC
    LIMIT 10
";
$resLast = mysqli_query($conn, $sqlLast);

$lastGames = [];
if ($resLast) {
    while ($r = mysqli_fetch_assoc($resLast)) {
        $lastGames[] = $r;
    }
}
// --------------------------------------
// 7. Odznaki użytkownika
// --------------------------------------
$sqlAch = "
    SELECT a.name, a.description, a.icon, ua.earned_at
    FROM user_achievements ua
    JOIN achievements a ON a.code = ua.achievement_code
    WHERE ua.user_id = $user_id
    ORDER BY ua.earned_at DESC
";
$resAch = mysqli_query($conn, $sqlAch);

$myAchievements = [];
if ($resAch) {
    while ($r = mysqli_fetch_assoc($resAch)) {
        $myAchievements[] = $r;
    }
}

?>
<div class="container mt-4 mb-4">
    <div class="row">
        <!-- Panel główny: użytkownik + XP -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h1 class="h3 mb-3">Profil gracza</h1>

                    <p class="mb-1">
                        <strong>Użytkownik:</strong>
                        <?php echo htmlspecialchars($username); ?>
                    </p>
                    <?php if ($created_at): ?>
                        <p class="mb-3">
                            <strong>Na portalu od:</strong>
                            <?php echo htmlspecialchars($created_at); ?>
                        </p>
                    <?php endif; ?>

                    <h2 class="h5 mt-3 mb-2">Poziom i doświadczenie</h2>
                    <p class="mb-1">
                        <strong>Poziom:</strong> <?php echo (int)$current_level; ?>
                        <?php if ($next_level !== null): ?>
                            (następny: <?php echo (int)$next_level; ?>)
                        <?php else: ?>
                            – maksymalny poziom w tabeli
                        <?php endif; ?>
                    </p>
                    <p class="mb-1">
                        <strong>Całkowity XP:</strong> <?php echo $total_xp; ?>
                    </p>

                    <?php if ($next_level !== null): ?>
                        <p class="mb-2">
                            <strong>Do kolejnego poziomu:</strong>
                            <?php echo $xp_to_next; ?> XP
                        </p>
                        <div class="progress" style="height: 18px;">
                            <div class="progress-bar" role="progressbar"
                                 style="width: <?php echo $level_percent; ?>%;"
                                 aria-valuenow="<?php echo $level_percent; ?>"
                                 aria-valuemin="0" aria-valuemax="100">
                                <?php echo $level_percent; ?>%
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 text-muted">
                            Osiągnąłeś maksymalny poziom.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Panel globalnych statystyk -->
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h4 mb-3">Statystyki ogólne</h2>

                    <p class="mb-1">
                        <strong>Rozegrane gry:</strong> <?php echo $total_games; ?>
                    </p>
                    <p class="mb-1">
                        <strong>Wygrane:</strong> <?php echo $wins; ?>
                    </p>
                    <p class="mb-1">
                        <strong>Przegrane:</strong> <?php echo $losses; ?>
                    </p>
                    <p class="mb-1">
                        <strong>Remisy:</strong> <?php echo $draws; ?>
                    </p>
                    <p class="mb-1">
                        <strong>Skuteczność (wygrane %):</strong>
                        <?php echo number_format($winrate, 1, ',', ' '); ?>%
                    </p>
                    <p class="mb-0">
                        <strong>Najdłuższa seria zwycięstw:</strong>
                        <?php echo $best_streak; ?> gra<?php echo ($best_streak == 1 ? '' : 'ch'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Statystyki per gra -->
    <div class="row mt-3">
        <div class="col-12 mb-3">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4 mb-3">Statystyki według gry</h2>

                    <?php if (empty($perGame)): ?>
                        <p class="text-muted mb-0">
                            Brak danych – zagraj w dowolną grę, aby zobaczyć statystyki.
                        </p>
                    <?php else: ?>
                        <?php if ($favourite_game): ?>
                            <p class="mb-2">
                                <strong>Ulubiona gra:</strong>
                                <?php echo htmlspecialchars(game_label($favourite_game)); ?>
                            </p>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                <tr>
                                    <th>Gra</th>
                                    <th class="text-end">Gry</th>
                                    <th class="text-end">Wygrane</th>
                                    <th class="text-end">Przegrane</th>
                                    <th class="text-end">Remisy</th>
                                    <th class="text-end">Punkty (jeśli dotyczy)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($perGame as $pg): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(game_label($pg['game_code'])); ?></td>
                                        <td class="text-end"><?php echo $pg['games']; ?></td>
                                        <td class="text-end"><?php echo $pg['wins']; ?></td>
                                        <td class="text-end"><?php echo $pg['losses']; ?></td>
                                        <td class="text-end"><?php echo $pg['draws']; ?></td>
                                        <td class="text-end"><?php echo $pg['points']; ?></td>
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
<div class="row mt-3">
    <div class="col-12 mb-3">
        <div class="card">
            <div class="card-body">
                <h2 class="h4 mb-3">🎖 Odznaki</h2>

                <?php if (empty($myAchievements)): ?>
                    <p class="text-muted">Brak zdobytych odznak. Graj więcej, aby je odblokować!</p>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($myAchievements as $ach): ?>
                            <div class="col-md-4 col-sm-6 mb-3">
                                <div class="p-2 border rounded">
                                    <?php if (!empty($ach['icon'])): ?>
                                        <img src="/assets/badges/<?php echo htmlspecialchars($ach['icon']); ?>" 
                                             alt="" style="width:40px; height:40px;">
                                    <?php endif; ?>

                                    <h5 class="mt-2 mb-1"><?php echo htmlspecialchars($ach['name']); ?></h5>
                                    <p class="small mb-1 text-muted">
                                        <?php echo htmlspecialchars($ach['description']); ?>
                                    </p>
                                    <p class="small text-end mb-0 text-secondary">
                                        Zdobyto: <?php echo htmlspecialchars($ach['earned_at']); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

    <!-- Ostatnie gry -->
    <div class="row mt-3">
        <div class="col-12 mb-3">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4 mb-3">Ostatnie gry</h2>

                    <?php if (empty($lastGames)): ?>
                        <p class="text-muted mb-0">
                            Brak zarejestrowanych gier w historii.
                        </p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Gra</th>
                                    <th>Wynik</th>
                                    <th class="text-end">Punkty</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($lastGames as $g): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($g['created_at']); ?></td>
                                        <td><?php echo htmlspecialchars(game_label($g['game_code'])); ?></td>
                                        <td>
                                            <?php
                                            $r = $g['result'];
                                            if ($r === 'win')   echo '<span class="text-success">Wygrana</span>';
                                            elseif ($r === 'loss') echo '<span class="text-danger">Przegrana</span>';
                                            elseif ($r === 'draw') echo 'Remis';
                                            else echo htmlspecialchars($r);
                                            ?>
                                        </td>
                                        <td class="text-end"><?php echo (int)$g['points']; ?></td>
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
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
