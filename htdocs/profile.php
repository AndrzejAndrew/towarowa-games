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
$sqlUser = "SELECT username, created_at, login_streak, max_login_streak FROM users WHERE id = $user_id";
$resUser = mysqli_query($conn, $sqlUser);
$userRow = $resUser ? mysqli_fetch_assoc($resUser) : null;

$username   = $userRow['username'] ?? 'Nieznany';
$created_at = $userRow['created_at'] ?? null;
$login_streak = (int)($userRow['login_streak'] ?? 0);
$max_login_streak = (int)($userRow['max_login_streak'] ?? 0);

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
// 7. Odznaki (wszystkie + postęp)
// --------------------------------------
$sqlAch = "
    SELECT a.code, a.name, a.description, a.icon, a.xp_reward, ua.earned_at
    FROM achievements a
    LEFT JOIN user_achievements ua
      ON ua.achievement_code = a.code
     AND ua.user_id = $user_id
    ORDER BY a.id ASC
";
$resAch = mysqli_query($conn, $sqlAch);

$allAchievements = [];
if ($resAch) {
    while ($r = mysqli_fetch_assoc($resAch)) {
        $allAchievements[] = $r;
    }
}

// pomocniczo: ile różnych gier
$distinct_games = count($perGame);

// funkcja postępu dla wybranych odznak (te, które już mamy w bazie)
function achievement_progress($code, $total_games, $wins, $best_streak, $distinct_games, $login_streak, $current_level) {
    $targets = [
        'first_game'     => 1,
        'first_win'      => 1,
        'veteran_25'     => 25,
        'veteran_100'    => 100,
        'all_rounder_3'  => 3,
        'all_rounder_6'  => 6,
        'win_streak_3'   => 3,
        'win_streak_5'   => 5,
        'win_streak_10'  => 10,
        'login_streak_7' => 7,
        'login_streak_30'=> 30,
        'level_10'       => 10,
        'level_25'       => 25,
        'level_50'       => 50,
        'level_100'      => 100,
    ];

    if (!isset($targets[$code])) {
        return [null, null, null]; // brak mierzalnego postępu
    }

    $target = (int)$targets[$code];
    $cur = 0;

    if ($code === 'first_game' || $code === 'veteran_25' || $code === 'veteran_100') {
        $cur = $total_games;
    } elseif ($code === 'first_win') {
        $cur = $wins;
    } elseif (strpos($code, 'all_rounder_') === 0) {
        $cur = $distinct_games;
    } elseif (strpos($code, 'win_streak_') === 0) {
        $cur = $best_streak;
    } elseif (strpos($code, 'login_streak_') === 0) {
        $cur = $login_streak;
    } elseif (strpos($code, 'level_') === 0) {
        $cur = $current_level;
    }

    $cur = max(0, (int)$cur);
    $pct = ($target > 0) ? max(0, min(100, (int)round(($cur / $target) * 100))) : 0;

    return [$cur, $target, $pct];
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
                <div class="badges-header">
                    <h2 class="h4 mb-1">Odznaki</h2>
                    <?php
                    $earnedCount = 0;
                    foreach ($allAchievements as $a) {
                        if (!empty($a['earned_at'])) $earnedCount++;
                    }
                    ?>
                    <div class="badges-subtitle">
                        Zdobyte: <strong><?php echo $earnedCount; ?></strong> / <?php echo count($allAchievements); ?>
                        <span class="badges-subnote">– odznaki są naliczane tylko dla zalogowanych.</span>
                    </div>
                </div>

                <?php if (empty($allAchievements)): ?>
                    <p class="text-muted">Brak zdefiniowanych odznak w tabeli <code>achievements</code>.</p>
                <?php else: ?>
                    <div class="badge-grid">
                        <?php foreach ($allAchievements as $a): ?>
                            <?php
                            $code = $a['code'];
                            $earnedAt = $a['earned_at'] ?? null;
                            $isEarned = !empty($earnedAt);

                            // ikonka: a.icon (jeśli ustawiona), w przeciwnym razie code.svg
                            $iconFile = $a['icon'] ?? '';
                            if (empty($iconFile)) {
                                $iconFile = $code . '.svg';
                            }
                            $iconPath = "/assets/badges/" . $iconFile;

                            // progress (jeśli umiemy policzyć)
                            [$cur, $target, $pct] = achievement_progress(
                                $code,
                                $total_games,
                                $wins,
                                $best_streak,
                                $distinct_games,
                                $login_streak,
                                $current_level
                            );
                            ?>
                            <div class="badge-card <?php echo $isEarned ? '' : 'badge-locked'; ?>">
                                <div class="badge-top">
                                    <div class="badge-icon-wrap">
                                        <img class="badge-icon" src="<?php echo htmlspecialchars($iconPath); ?>" alt="">
                                        <?php if (!$isEarned): ?>
                                            <div class="badge-lock" title="Nieodblokowane">🔒</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="badge-title">
                                        <div class="badge-name"><?php echo htmlspecialchars($a['name']); ?></div>
                                        <div class="badge-desc"><?php echo htmlspecialchars($a['description']); ?></div>
                                    </div>
                                </div>

                                <div class="badge-meta">
                                    <?php if ($isEarned): ?>
                                        <div class="badge-earned">Zdobyto: <?php echo htmlspecialchars($earnedAt); ?></div>
                                    <?php elseif ($target !== null): ?>
                                        <div class="badge-progress">
                                            <div class="badge-progress-row">
                                                <span>Postęp</span>
                                                <span><?php echo (int)$cur; ?> / <?php echo (int)$target; ?></span>
                                            </div>
                                            <div class="badge-progress-bar">
                                                <div class="badge-progress-fill" style="width: <?php echo (int)$pct; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="badge-earned badge-muted">Odznaka specjalna – brak licznika postępu.</div>
                                    <?php endif; ?>
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
