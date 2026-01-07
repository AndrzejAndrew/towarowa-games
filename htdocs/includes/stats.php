<?php
// ================================================
//  STATS / XP / RANKING SYSTEM FOR GAME PORTAL
// ================================================
require_once __DIR__ . '/db.php';

// ------------------------------------------------
//  Bezpieczne prepare + log (żeby nie robić HTTP 500 po udanym ruchu)
//  Log: htdocs/includes/stats_errors.log
// ------------------------------------------------
function stats_log(string $msg): void {
    $line = date('Y-m-d H:i:s ') . $msg . "\n";
    @file_put_contents(__DIR__ . '/stats_errors.log', $line, FILE_APPEND);
}

/**
 * Zwraca mysqli_stmt albo null, jeśli prepare() się nie uda.
 * Dzięki temu bind_param() nie robi FATAL i nie rozwala odpowiedzi JSON w grach.
 */
function stats_prepare(string $sql): ?mysqli_stmt {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $compact = preg_replace('/\s+/', ' ', trim($sql));
        stats_log('PREPARE FAILED: ' . ($conn->error ?? 'unknown') . ' | SQL: ' . $compact);
        return null;
    }
    return $stmt;
}

// ------------------------------------------------
//  XP VALUES (tuning)
// ------------------------------------------------
const XP_PLAYED = 5;           // zawsze za rozegranie (po zakończeniu gry)
const XP_WIN_BONUS = 20;       // bonus za wygraną
const XP_DRAW_BONUS = 12;      // bonus za remis
const XP_LOSS_BONUS = 6;       // bonus za przegraną
const XP_FIRST_GAME_DAY = 10;  // bonus: pierwsza gra danego dnia (Europe/Warsaw)
const XP_DAILY_LOGIN = 10;     // bonus: pierwsze zalogowanie w danym dniu (Europe/Warsaw)

const XP_STREAK_3 = 15;
const XP_STREAK_5 = 30;
const XP_STREAK_10 = 80;

// ------------------------------------------------
//  Pobranie aktywnego sezonu (jeśli istnieje)
// ------------------------------------------------
function get_active_season($conn) {
    $sql = "SELECT id FROM seasons WHERE is_active = 1 LIMIT 1";
    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ? intval($row['id']) : null;
}

// ------------------------------------------------
//  Daty/dzień w Europe/Warsaw, ale trzymamy w DB UTC
// ------------------------------------------------
function warsaw_day_bounds_utc(?DateTimeImmutable $warsawNow = null): array {
    $warsawTz = new DateTimeZone('Europe/Warsaw');
    $utcTz = new DateTimeZone('UTC');

    $nowW = $warsawNow ?: new DateTimeImmutable('now', $warsawTz);
    $startW = $nowW->setTime(0, 0, 0);
    $endW = $startW->modify('+1 day');

    $startUtc = $startW->setTimezone($utcTz)->format('Y-m-d H:i:s');
    $endUtc   = $endW->setTimezone($utcTz)->format('Y-m-d H:i:s');

    return [$startUtc, $endUtc, $startW->format('Y-m-d')]; // trzeci element: data w Warszawie
}

// ------------------------------------------------
//  Sprawdzenie, czy user istnieje (żeby nie wpadały guest_id)
// ------------------------------------------------
function stats_user_exists(int $user_id): bool {
    global $conn;
    if ($user_id <= 0) return false;

    $stmt = stats_prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

// ------------------------------------------------
//  Uniwersalne dopisywanie XP (zgodne z ENUM xp_log.event_type)
// ------------------------------------------------
function stats_add_xp(
    int $user_id,
    int $xp_delta,
    string $event_type,
    string $game_code = 'portal',
    ?int $related_result_id = null,
    ?int $season_id = null
): bool {
    global $conn;

    if ($user_id <= 0 || $xp_delta === 0) return false;

    $allowed = ['game_win','game_loss','game_draw','game_played','bonus','badge'];
    if (!in_array($event_type, $allowed, true)) {
        return false;
    }

    $stmt = stats_prepare(
        "INSERT INTO xp_log (user_id, game_code, event_type, xp_delta, related_result_id, season_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) return false;

    // bind: i s s i i i
    $rid = $related_result_id ?? null;
    $sid = $season_id ?? null;

    $stmt->bind_param('issiii', $user_id, $game_code, $event_type, $xp_delta, $rid, $sid);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        // sprawdź odznaki poziomowe po każdej zmianie XP
        stats_check_level_achievements($user_id);
    }

    return $ok;
}

// ------------------------------------------------
//  Przyznawanie odznaki (jeśli nie ma) + opcjonalnie XP z achievements.xp_reward
// ------------------------------------------------
function stats_award_achievement(int $user_id, string $code): bool {
    global $conn;

    if ($user_id <= 0 || $code === '') return false;

    // już posiada?
    $stmt = stats_prepare(
        "SELECT 1 FROM user_achievements WHERE user_id = ? AND achievement_code = ? LIMIT 1"
    );

    if (!$stmt) return false;

    $stmt->bind_param('is', $user_id, $code);
    $stmt->execute();
    $stmt->store_result();
    $already = $stmt->num_rows > 0;
    $stmt->close();

    if ($already) return false;

    // czy istnieje taka odznaka
    $stmt = stats_prepare(
        "SELECT xp_reward FROM achievements WHERE code = ? LIMIT 1"
    );

    if (!$stmt) return false;

    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) return false;

    // zapisz zdobycie
    $stmt = stats_prepare(
        "INSERT INTO user_achievements (user_id, achievement_code) VALUES (?, ?)"
    );

    if (!$stmt) return false;

    $stmt->bind_param('is', $user_id, $code);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) return false;

    $xp_reward = (int)($row['xp_reward'] ?? 0);
    if ($xp_reward > 0) {
        // XP za odznakę – event_type = badge
        stats_add_xp($user_id, $xp_reward, 'badge', 'badge');
    }

    return true;
}

// ------------------------------------------------
//  Bonus: pierwsza gra danego dnia (Europe/Warsaw)
// ------------------------------------------------
function stats_apply_first_game_of_day_bonus(int $user_id, ?int $season_id = null): void {
    global $conn;

    [$startUtc, $endUtc] = warsaw_day_bounds_utc();

    $stmt = stats_prepare(
        "SELECT 1
         FROM xp_log
         WHERE user_id = ?
           AND event_type = 'bonus'
           AND game_code = 'daily_first_game'
           AND created_at >= ? AND created_at < ?
         LIMIT 1"
    );

    if (!$stmt) return;

    $stmt->bind_param('iss', $user_id, $startUtc, $endUtc);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if (!$exists) {
        stats_add_xp($user_id, XP_FIRST_GAME_DAY, 'bonus', 'daily_first_game', null, $season_id);
    }
}

// ------------------------------------------------
//  Bonus: seria zwycięstw (progi 3/5/10)
// ------------------------------------------------
function stats_apply_win_streak_bonus(int $user_id, ?int $season_id = null): void {
    global $conn;

    // pobierz ostatnie wyniki (wystarczy 20)
    $stmt = stats_prepare(
        "SELECT result
         FROM game_results
         WHERE user_id = ?
           AND result IN ('win','loss','draw')
         ORDER BY created_at DESC, id DESC
         LIMIT 20"
    );

    if (!$stmt) return;

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $streak = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (($r['result'] ?? '') === 'win') {
                $streak++;
            } else {
                break;
            }
        }
    }
    $stmt->close();

    if ($streak === 3) {
        stats_add_xp($user_id, XP_STREAK_3, 'bonus', 'win_streak_3', null, $season_id);
        stats_award_achievement($user_id, 'win_streak_3');
    } elseif ($streak === 5) {
        stats_add_xp($user_id, XP_STREAK_5, 'bonus', 'win_streak_5', null, $season_id);
        stats_award_achievement($user_id, 'win_streak_5');
    } elseif ($streak === 10) {
        stats_add_xp($user_id, XP_STREAK_10, 'bonus', 'win_streak_10', null, $season_id);
        stats_award_achievement($user_id, 'win_streak_10');
    }
}

// ------------------------------------------------
//  Reguły odznak „globalnych” po zakończeniu gry
// ------------------------------------------------
function stats_check_global_achievements(int $user_id): void {
    global $conn;

    // 1) First game
    $stmt = stats_prepare("SELECT COUNT(*) AS c FROM game_results WHERE user_id = ?");
    if (!$stmt) return;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $gamesTotal = (int)($row['c'] ?? 0);
    if ($gamesTotal >= 1) {
        stats_award_achievement($user_id, 'first_game');
    }
    if ($gamesTotal >= 25) {
        stats_award_achievement($user_id, 'veteran_25');
    }
    if ($gamesTotal >= 100) {
        stats_award_achievement($user_id, 'veteran_100');
    }

    // 2) First win
    $stmt = stats_prepare("SELECT 1 FROM game_results WHERE user_id = ? AND result = 'win' LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->store_result();
    $hasWin = $stmt->num_rows > 0;
    $stmt->close();

    if ($hasWin) {
        stats_award_achievement($user_id, 'first_win');
    }

    // 3) All-rounder (różne gry)
    $stmt = stats_prepare("SELECT COUNT(DISTINCT game_code) AS c FROM game_results WHERE user_id = ?");
    if (!$stmt) return;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $gamesDistinct = (int)($row['c'] ?? 0);
    if ($gamesDistinct >= 3) {
        stats_award_achievement($user_id, 'all_rounder_3');
    }
    if ($gamesDistinct >= 6) {
        stats_award_achievement($user_id, 'all_rounder_6');
    }

    // 4) Odznaki poziomowe – tu tylko kontrolnie
    stats_check_level_achievements($user_id);
}

// ------------------------------------------------
//  Odznaki poziomowe (na bazie sumy XP)
// ------------------------------------------------
function stats_check_level_achievements(int $user_id): void {
    global $conn;

    if ($user_id <= 0) return;

    // Total XP
    $stmt = stats_prepare("SELECT COALESCE(SUM(xp_delta), 0) AS total_xp FROM xp_log WHERE user_id = ?");
    if (!$stmt) return;
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $xp = (int)($row['total_xp'] ?? 0);

    // Current level from user_levels
    $stmt = stats_prepare("SELECT COALESCE(MAX(level), 1) AS lvl FROM user_levels WHERE xp_required <= ?");
    if (!$stmt) return;
    $stmt->bind_param('i', $xp);
    $stmt->execute();
    $row2 = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $lvl = (int)($row2['lvl'] ?? 1);

    if ($lvl >= 10)  stats_award_achievement($user_id, 'level_10');
    if ($lvl >= 25)  stats_award_achievement($user_id, 'level_25');
    if ($lvl >= 50)  stats_award_achievement($user_id, 'level_50');
    if ($lvl >= 100) stats_award_achievement($user_id, 'level_100');
}

// ------------------------------------------------
//  REJESTRACJA WYNIKU GRY
// ------------------------------------------------
function stats_register_result(int $user_id, string $game_code, string $result, int $points = 0): bool {
    global $conn;

    if ($user_id <= 0) return false;
    if (!stats_user_exists($user_id)) return false;

    $allowed = ['win','loss','draw'];
    if (!in_array($result, $allowed, true)) return false;

    $season_id = get_active_season($conn);

    // 1) zapis do game_results
    $stmt = stats_prepare(
        "INSERT INTO game_results (user_id, game_code, result, points, season_id)
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) return false;

    $stmt->bind_param('issii', $user_id, $game_code, $result, $points, $season_id);
    $ok = $stmt->execute();
    $result_id = $stmt->insert_id;
    $stmt->close();

    if (!$ok) return false;

    // 2) XP za rozegranie
    stats_add_xp($user_id, XP_PLAYED, 'game_played', $game_code, $result_id, $season_id);

    // 3) XP za wynik
    if ($result === 'win') {
        stats_add_xp($user_id, XP_WIN_BONUS, 'game_win', $game_code, $result_id, $season_id);
    } elseif ($result === 'loss') {
        stats_add_xp($user_id, XP_LOSS_BONUS, 'game_loss', $game_code, $result_id, $season_id);
    } elseif ($result === 'draw') {
        stats_add_xp($user_id, XP_DRAW_BONUS, 'game_draw', $game_code, $result_id, $season_id);
    }

    // 4) Bonus dzienny: pierwsza gra
    stats_apply_first_game_of_day_bonus($user_id, $season_id);

    // 5) Bonus za serię wygranych (tylko jeśli ostatnie to wygrane)
    if ($result === 'win') {
        stats_apply_win_streak_bonus($user_id, $season_id);
    }

    // 6) Odznaki globalne
    stats_check_global_achievements($user_id);

    return true;
}

// ------------------------------------------------
//  ŁĄCZNE XP + poziom
// ------------------------------------------------
function get_user_xp($user_id) {
    global $conn;

    $user_id = (int)$user_id;

    $sql = "SELECT SUM(xp_delta) AS total_xp FROM xp_log WHERE user_id = $user_id";
    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;

    $xp = intval($row['total_xp'] ?? 0);

    // Poziom na podstawie user_levels
    $sql2 = "SELECT COALESCE(MAX(level), 1) AS lvl FROM user_levels WHERE xp_required <= $xp";
    $res2 = mysqli_query($conn, $sql2);
    $row2 = $res2 ? mysqli_fetch_assoc($res2) : null;
    $lvl = intval($row2['lvl'] ?? 1);

    return [$xp, $lvl];
}

// ------------------------------------------------
//  STATYSTYKI per user (global)
// ------------------------------------------------
function get_user_stats_global($user_id) {
    global $conn;
    $user_id = (int)$user_id;

    $sql = "
        SELECT
            COUNT(*) AS games_total,
            SUM(result='win') AS wins,
            SUM(result='loss') AS losses,
            SUM(result='draw') AS draws
        FROM game_results
        WHERE user_id = $user_id
    ";

    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;

    return [
        'games_total' => (int)($row['games_total'] ?? 0),
        'wins'        => (int)($row['wins'] ?? 0),
        'losses'      => (int)($row['losses'] ?? 0),
        'draws'       => (int)($row['draws'] ?? 0),
    ];
}

// ------------------------------------------------
//  STATYSTYKI per user (per game_code)
// ------------------------------------------------
function get_user_stats_per_game($user_id, $game_code) {
    global $conn;
    $user_id = (int)$user_id;
    $game_code_safe = mysqli_real_escape_string($conn, $game_code);

    $sql = "
        SELECT
            COUNT(*) AS games_total,
            SUM(result='win') AS wins,
            SUM(result='loss') AS losses,
            SUM(result='draw') AS draws
        FROM game_results
        WHERE user_id = $user_id AND game_code = '$game_code_safe'
    ";

    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;

    return [
        'games_total' => (int)($row['games_total'] ?? 0),
        'wins'        => (int)($row['wins'] ?? 0),
        'losses'      => (int)($row['losses'] ?? 0),
        'draws'       => (int)($row['draws'] ?? 0),
    ];
}

// ------------------------------------------------
//  Ranking ALL-TIME (po XP)
// ------------------------------------------------
function get_ranking_alltime() {
    global $conn;

    $sql = "
        SELECT 
            u.id,
            u.username,
            COALESCE(SUM(x.xp_delta),0) AS xp
        FROM users u
        LEFT JOIN xp_log x ON x.user_id = u.id
        GROUP BY u.id, u.username
        ORDER BY xp DESC
    ";

    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

// ------------------------------------------------
//  Ranking miesięczny (po XP)
// ------------------------------------------------
function get_ranking_monthly() {
    global $conn;

    $sql = "
        SELECT 
            u.id,
            u.username,
            COALESCE(SUM(x.xp_delta),0) AS xp
        FROM users u
        LEFT JOIN xp_log x 
            ON x.user_id = u.id
            AND YEAR(x.created_at)=YEAR(CURRENT_DATE())
            AND MONTH(x.created_at)=MONTH(CURRENT_DATE())
        GROUP BY u.id, u.username
        ORDER BY xp DESC
    ";

    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

// ------------------------------------------------
//  Ranking dzienny (po XP)
// ------------------------------------------------
function get_ranking_daily() {
    global $conn;

    $sql = "
        SELECT 
            u.id,
            u.username,
            COALESCE(SUM(x.xp_delta),0) AS xp
        FROM users u
        LEFT JOIN xp_log x 
            ON x.user_id = u.id
            AND DATE(x.created_at)=CURRENT_DATE()
        GROUP BY u.id, u.username
        ORDER BY xp DESC
    ";

    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

// ------------------------------------------------
//  Ranking aktywnego sezonu (po XP)
// ------------------------------------------------
function get_ranking_season() {
    global $conn;

    $season_id = get_active_season($conn);
    if (!$season_id) return [];

    $sql = "
        SELECT 
            u.id,
            u.username,
            COALESCE(SUM(x.xp_delta),0) AS xp
        FROM users u
        LEFT JOIN xp_log x 
            ON x.user_id = u.id
            AND x.season_id = $season_id
        GROUP BY u.id, u.username
        ORDER BY xp DESC
    ";

    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}
