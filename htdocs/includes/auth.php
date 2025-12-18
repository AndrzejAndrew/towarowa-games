<?php
// ------------------------------------------------------
// BOOTSTRAP – sekrety + konfiguracja Discord
// ------------------------------------------------------
// Strefa czasowa portalu (wyświetlanie / logi)
date_default_timezone_set('Europe/Warsaw');

$secretsFile = __DIR__ . '/secrets_runtime.php';
if (!file_exists($secretsFile)) {
    http_response_code(500);
    die('Missing secrets_runtime.php (deploy error)');
}
require_once $secretsFile;

require_once __DIR__ . '/discord_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/achievements.php';

// ------------------------------------------------------
// SESJA
// ------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------
// LOGICZNE UJEDNOLICENIE SESJI
// ------------------------------------------------------

// Jeśli istnieje user_id ale brak tablicy user → utwórz ją
if (isset($_SESSION['user_id']) && !isset($_SESSION['user'])) {
    $_SESSION['user'] = [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? null
    ];
}

// Jeśli istnieje tablica user ale brak user_id → skopiuj
if (isset($_SESSION['user']['id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $_SESSION['user']['id'];
}
if (isset($_SESSION['user']['username']) && !isset($_SESSION['username'])) {
    $_SESSION['username'] = $_SESSION['user']['username'];
}

// ------------------------------------------------------
// GOŚĆ
// ------------------------------------------------------
if (!isset($_SESSION['user_id']) && !isset($_SESSION['guest_name'])) {
    $names = [
        "Aragorn","Legolas","Frodo","Gimli","Boromir","Gandalf",
        "Samwise","Merry","Pippin","Elrond","Balin","Thranduil",
        "Eomer","Theoden","Arwen","Galadriela"
    ];
    $nick = $names[array_rand($names)] . rand(10,99);
    $_SESSION['guest_name'] = $nick;
    $_SESSION['guest_id']   = rand(100000,999999);
}

// ------------------------------------------------------
// FUNKCJE POMOCNICZE
// ------------------------------------------------------
function current_display_name() {
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        return $_SESSION['username'];
    }
    if (isset($_SESSION['guest_name'])) {
        return $_SESSION['guest_name'] . " (gość)";
    }
    return "Gość";
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// ------------------------------------------------------
// DAILY LOGIN + STREAK (Podejście B: kolumny w users)
// ------------------------------------------------------
function portal_apply_daily_login_rewards(int $user_id): void
{
    global $conn;

    if ($user_id <= 0) {
        return;
    }

    // Jeśli migracja DB nie została wykonana, nie wywalaj całego portalu.
    try {
        $stmt = $conn->prepare(
            "SELECT last_login_date, login_streak, max_login_streak FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        return;
    }

    if (!$row) {
        return;
    }

    $warsawTz = new DateTimeZone('Europe/Warsaw');
    $today = (new DateTimeImmutable('now', $warsawTz))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable('yesterday', $warsawTz))->format('Y-m-d');

    $last = $row['last_login_date'] ?? null;
    $streak = (int)($row['login_streak'] ?? 0);
    $best = (int)($row['max_login_streak'] ?? 0);

    // Już zalogował się dzisiaj (wg Europe/Warsaw)
    if ($last === $today) {
        return;
    }

    // Aktualizacja streak
    if ($last === $yesterday) {
        $streak = $streak + 1;
    } else {
        $streak = 1;
    }
    if ($streak > $best) {
        $best = $streak;
    }

    // update user
    $stmt = $conn->prepare(
        "UPDATE users SET last_login_date = ?, login_streak = ?, max_login_streak = ? WHERE id = ?"
    );
    $stmt->bind_param('siii', $today, $streak, $best, $user_id);
    $stmt->execute();
    $stmt->close();

    // XP za dzienny login
    $season_id = get_active_season($conn);
    stats_add_xp($user_id, XP_DAILY_LOGIN, 'bonus', 'daily_login', null, $season_id);

    // Odznaki za streak
    if ($streak >= 7) {
        award_achievement($user_id, 'login_streak_7');
    }
    if ($streak >= 30) {
        award_achievement($user_id, 'login_streak_30');
    }
}

// Auto: apply daily login rewards dla zalogowanego usera
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    // tylko realne konto (bez gości)
    if (stats_user_exists($uid)) {
        portal_apply_daily_login_rewards($uid);
    }
}
