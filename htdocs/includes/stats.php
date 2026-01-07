<?php
// ================================================
//  STATS / XP / RANKING SYSTEM FOR GAME PORTAL
// ================================================
require_once __DIR__ . '/db.php';

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

    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
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

    $stmt = $conn->prepare(
        "INSERT INTO xp_log (user_id, game_code, event_type, xp_delta, related_result_id, season_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

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

    return (bool)$ok;
}

// ------------------------------------------------
//  Przyznawanie odznaki (jeśli nie ma) + opcjonalnie XP z achievements.xp_reward
// ------------------------------------------------
function stats_award_achievement(int $user_id, string $code): bool {
    global $conn;

    if ($user_id <= 0 || $code === '') return false;

    // już posiada?
    $stmt = $conn->prepare(
        "SELECT 1 FROM user_achievements WHERE user_id = ? AND achievement_code = ? LIMIT 1"
    );
    $stmt->bind_param('is', $user_id, $code);
    $stmt->execute();
    $stmt->store_result();
    $already = $stmt->num_rows > 0;
    $stmt->close();

    if ($already) return false;

    // czy istnieje taka odznaka
    $stmt = $conn->prepare(
        "SELECT xp_reward FROM achievements WHERE code = ? LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) return false;

    // zapisz zdobycie
    $stmt = $conn->prepare(
        "INSERT INTO user_achievements (user_id, achievement_code) VALUES (?, ?)"
    );
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

    $stmt = $conn->prepare(
        "SELECT 1
         FROM xp_log
         WHERE user_id = ?
           AND event_type = 'bonus'
           AND game_code = 'daily_first_game'
           AND created_at >= ? AND created_at < ?
         LIMIT 1"
    );
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
    $stmt = $conn->prepare(
        "SELECT result
         FROM game_results
         WHERE user_id = ?
           AND result IN ('win','loss','draw')
         ORDER BY created_at DESC, id DESC
         LIMIT 20"
    );
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
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM game_results WHERE user_id = ?");
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
    $stmt = $conn->prepare("SELECT 1 FROM game_results WHERE user_id = ? AND result = 'win' LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->store_result();
    $hasWin = $stmt->num_rows > 0;
    $stmt->close();

    if ($hasWin) {
        stats_award_achievement($user_id, 'first_win');
    }

    // 3) All-rounder
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT game_code) AS c FROM game_results WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $distinctGames = (int)($row['c'] ?? 0);
    if ($distinctGames >= 3) {
        stats_award_achievement($user_id, 'all_rounder_3');
    }
    if ($distinctGames >= 6) {
        stats_award_achievement($user_id, 'all_rounder_6');
    }
}

// ------------------------------------------------
//  Odznaki za poziomy (bez dodatkowego XP - xp_reward=0)
// ------------------------------------------------
function stats_check_level_achievements(int $user_id): void {
    global $conn;

    if ($user_id <= 0) return;

    // Total XP
    $stmt = $conn->prepare("SELECT COALESCE(SUM(xp_delta), 0) AS total_xp FROM xp_log WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $xp = (int)($row['total_xp'] ?? 0);

    // Current level from user_levels
    $stmt = $conn->prepare("SELECT COALESCE(MAX(level), 1) AS lvl FROM user_levels WHERE xp_required <= ?");
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
function stats_register_result($user_id, $game_code, $result, $points = 0) {
    global $conn;

    $user_id = (int)$user_id;
    $game_code = (string)$game_code;
    $result = (string)$result;

    if ($user_id <= 0 || $game_code === '' || $result === '') return false;

    // tylko realne konta (bez guest_id)
    if (!stats_user_exists($user_id)) return false;

    $season_id = get_active_season($conn);

    // 1) Zapisz wynik
    $stmt = $conn->prepare(
        "INSERT INTO game_results (user_id, game_code, result, points, season_id)
         VALUES (?, ?, ?, ?, ?)"
    );
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
    $level_sql = "SELECT MAX(level) AS lvl FROM user_levels WHERE xp_required <= $xp";
    $res2 = mysqli_query($conn, $level_sql);
    $row2 = $res2 ? mysqli_fetch_assoc($res2) : null;

    $level = intval($row2['lvl'] ?? 1);

    return [
        'xp' => $xp,
        'level' => $level
    ];
}

// ------------------------------------------------
//  Globalne statystyki użytkownika
// ------------------------------------------------
function get_user_stats_global($user_id) {
    global $conn;

    $user_id = (int)$user_id;

    $sql = "
        SELECT 
            COUNT(*) AS games_total,
            SUM(result='win')  AS wins,
            SUM(result='loss') AS losses,
            SUM(result='draw') AS draws,
            MAX(created_at)    AS last_game
        FROM game_results
        WHERE user_id = $user_id
    ";

    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;

    $games = intval($row['games_total'] ?? 0);
    $wins  = intval($row['wins'] ?? 0);
    $loss  = intval($row['losses'] ?? 0);
    $draws = intval($row['draws'] ?? 0);

    $win_rate = $games > 0 ? round(($wins / $games) * 100, 1) : 0;

    return [
        'games_total' => $games,
        'wins' => $wins,
        'losses' => $loss,
        'draws' => $draws,
        'win_rate' => $win_rate,
        'last_game' => $row['last_game'] ?? null
    ];
}

// ------------------------------------------------
//  Statystyki per gra
// ------------------------------------------------
function get_user_stats_per_game($user_id) {
    global $conn;

    $user_id = (int)$user_id;

    $sql = "
        SELECT 
            game_code,
            COUNT(*) AS games_total,
            SUM(result='win') AS wins,
            SUM(result='loss') AS losses,
            SUM(result='draw') AS draws,
            SUM(points) AS total_points
        FROM game_results
        WHERE user_id = $user_id
        GROUP BY game_code
        ORDER BY games_total DESC
    ";

    $res = mysqli_query($conn, $sql);
    $rows = [];

    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $games = intval($r['games_total']);
            $wins  = intval($r['wins']);
            $win_rate = $games > 0 ? round(($wins / $games) * 100, 1) : 0;

            $r['win_rate'] = $win_rate;
            $rows[] = $r;
        }
    }

    return $rows;
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
//  Ranking miesięczny
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
//  Ranking dzienny
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
//  Ranking sezonowy
// ------------------------------------------------
function get_ranking_season($season_id) {
    global $conn;

    $season_id = (int)$season_id;
    if ($season_id <= 0) return [];

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

?>
