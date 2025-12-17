<?php
// ================================================
//  STATS / XP / RANKING SYSTEM FOR GAME PORTAL
// ================================================
require_once __DIR__ . '/db.php';


// ------------------------------------------------
//  XP VALUES (możesz zmieniać)
// ------------------------------------------------
const XP_WIN   = 15;
const XP_LOSS  = 5;
const XP_DRAW  = 8;
const XP_PLAYED = 3;


// ------------------------------------------------
//  Pobranie aktywnego sezonu (jeśli istnieje)
// ------------------------------------------------
function get_active_season($conn) {
    $sql = "SELECT id FROM seasons WHERE is_active = 1 LIMIT 1";
    $res = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($res);
    return $row ? intval($row['id']) : null;
}

// ------------------------------------------------
//  Czy user_id istnieje w tabeli users?
//  (chroni przed nabijaniem statystyk przez gości,
//   którzy mają losowy guest_id w sesji)
// ------------------------------------------------
function stats_user_exists(mysqli $conn, int $user_id): bool {
    if ($user_id <= 0) return false;
    $st = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    if (!$st) return false;
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->store_result();
    $ok = ($st->num_rows > 0);
    $st->close();
    return $ok;
}


// ------------------------------------------------
//  REJESTRACJA WYNIKU GRY
// ------------------------------------------------
function stats_register_result($user_id, $game_code, $result, $points = 0) {
    global $conn;

    if (!$user_id || !$game_code || !$result) return false;
    // Zapisujemy tylko dla realnych kont (users). Gość ma losowe guest_id, którego nie ma w users.
    if (!stats_user_exists($conn, (int)$user_id)) return false;

    $season_id = get_active_season($conn);

    // 1) Zapisz wynik
    $stmt = $conn->prepare(
        "INSERT INTO game_results (user_id, game_code, result, points, season_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issii", $user_id, $game_code, $result, $points, $season_id);
    $stmt->execute();
    $result_id = $stmt->insert_id;
    $stmt->close();


    // 2) Wylicz XP
    switch ($result) {
        case 'win':   $xp = XP_WIN; break;
        case 'loss':  $xp = XP_LOSS; break;
        case 'draw':  $xp = XP_DRAW; break;
        default:      $xp = XP_PLAYED; break;
    }

    // 3) Dodaj XP
    $stmt = $conn->prepare(
        "INSERT INTO xp_log (user_id, game_code, event_type, xp_delta, related_result_id, season_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $event = 'game_' . $result;
    $stmt->bind_param("issiii", $user_id, $game_code, $event, $xp, $result_id, $season_id);
    $stmt->execute();
    $stmt->close();

    return true;
}


// ------------------------------------------------
//  ŁĄCZNE XP + poziom
// ------------------------------------------------
function get_user_xp($user_id) {
    global $conn;

    $sql = "SELECT SUM(xp_delta) AS total_xp
            FROM xp_log WHERE user_id = $user_id";
    $res = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($res);

    $xp = intval($row['total_xp'] ?? 0);

    // Poziom na podstawie user_levels
    $level_sql = "SELECT MAX(level) AS lvl 
                  FROM user_levels 
                  WHERE xp_required <= $xp";
    $res2 = mysqli_query($conn, $level_sql);
    $row2 = mysqli_fetch_assoc($res2);

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
    $row = mysqli_fetch_assoc($res);

    $games = intval($row['games_total']);
    $wins  = intval($row['wins']);
    $loss  = intval($row['losses']);
    $draws = intval($row['draws']);

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

    while ($r = mysqli_fetch_assoc($res)) {
        $games = intval($r['games_total']);
        $wins  = intval($r['wins']);
        $win_rate = $games > 0 ? round(($wins / $games)*100, 1) : 0;

        $r['win_rate'] = $win_rate;
        $rows[] = $r;
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
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
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
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
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
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}


// ------------------------------------------------
//  Ranking sezonowy
// ------------------------------------------------
function get_ranking_season($season_id) {
    global $conn;

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
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

?>
