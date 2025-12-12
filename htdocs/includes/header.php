<?php
// ------------------------------------------------------
// Debug info – możesz zostawić lub wyłączyć na produkcji
// ------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ------------------------------------------------------
// Autoryzacja + Discord
// ------------------------------------------------------
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/discord_config.php';
require_once __DIR__ . '/discord_error_handler.php';

// ------------------------------------------------------
// Połączenie z bazą
// ------------------------------------------------------
require_once __DIR__ . '/db.php';

// ------------------------------------------------------
// STATYSTYKI PORTALU
// ------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Domyślne wartości (gdyby coś poszło nie tak z DB)
$stats_online_now   = 0;
$stats_logged_in    = 0;
$stats_visits_today = 0;
$stats_visits_month = 0;
$stats_visits_total = 0;

if (isset($conn) && $conn instanceof mysqli) {

    // --------- 1) portal_sessions – ONLINE / ZALOGOWANI ---------
    $sessionId      = session_id();
    $isLoggedInFlag = is_logged_in() ? 1 : 0;
    $ip             = $_SERVER['REMOTE_ADDR'] ?? '';

    $sessionIdEsc = mysqli_real_escape_string($conn, $sessionId);
    $ipEsc        = mysqli_real_escape_string($conn, $ip);

    mysqli_query(
        $conn,
        "INSERT INTO portal_sessions (session_id, is_logged_in, last_active, ip_address)
         VALUES ('$sessionIdEsc', $isLoggedInFlag, NOW(), '$ipEsc')
         ON DUPLICATE KEY UPDATE
            is_logged_in = VALUES(is_logged_in),
            last_active  = VALUES(last_active),
            ip_address   = VALUES(ip_address)"
    );

    // Sesja nieaktywna ponad 5 minut = już nie „online”
    mysqli_query(
        $conn,
        "DELETE FROM portal_sessions
         WHERE last_active < (NOW() - INTERVAL 5 MINUTE)"
    );

    // Online wszyscy
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM portal_sessions");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $stats_online_now = (int)$row['c'];
    }

    // Online zalogowani
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM portal_sessions WHERE is_logged_in = 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $stats_logged_in = (int)$row['c'];
    }

    // --------- 2) portal_counters – WIZYTY (NIE odsłony!) ---------
    $today = date('Y-m-d');
    $month = date('Y-m');

    // Czy dla TEJ sesji mamy już policzoną wizytę dzisiaj?
    $shouldCountVisit = false;
    if (empty($_SESSION['portal_last_visit_date']) || $_SESSION['portal_last_visit_date'] !== $today) {
        $shouldCountVisit = true;
        $_SESSION['portal_last_visit_date'] = $today;
    }

    $res = mysqli_query($conn, "SELECT * FROM portal_counters WHERE id = 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $total   = (int)$row['total_visits'];
        $tDate   = $row['today_date'];
        $tVisits = (int)$row['today_visits'];
        $mMonth  = $row['month_key'];
        $mVisits = (int)$row['month_visits'];
    } else {
        // Awaryjna inicjalizacja, gdyby wiersza nie było
        $total   = 0;
        $tDate   = $today;
        $tVisits = 0;
        $mMonth  = $month;
        $mVisits = 0;

        mysqli_query(
            $conn,
            "INSERT IGNORE INTO portal_counters
                (id, total_visits, today_date, today_visits, month_key, month_visits)
             VALUES (1, 0, '$today', 0, '$month', 0)"
        );
    }

    // Reset dnia
    if ($tDate !== $today) {
        $tDate   = $today;
        $tVisits = 0;
    }

    // Reset miesiąca
    if ($mMonth !== $month) {
        $mMonth  = $month;
        $mVisits = 0;
    }

    // 🔴 KLUCZOWA ZMIANA:
    // Zliczamy wizytę TYLKO raz dziennie na sesję (shouldCountVisit = true),
    // a nie przy każdym przeładowaniu strony / wejściu w grę.
    if ($shouldCountVisit) {
        $total++;
        $tVisits++;
        $mVisits++;
    }

    mysqli_query(
        $conn,
        "UPDATE portal_counters
         SET total_visits = $total,
             today_date   = '$tDate',
             today_visits = $tVisits,
             month_key    = '$mMonth',
             month_visits = $mVisits
         WHERE id = 1"
    );

    $stats_visits_total = $total;
    $stats_visits_today = $tVisits;
    $stats_visits_month = $mVisits;
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Centrum rozrywki – gry</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-dark">
<div class="wrapper">
    <div class="card">

        <!-- Górny pasek nawigacji -->
        <div class="nav-bar">
            <div>🎮 Centrum rozrywki</div>
            <div class="nav-links">
                <span><?php echo htmlspecialchars(current_display_name()); ?></span>
                |
                <a href="/index.php">Strona główna</a>
                <a href="/games/quiz/index.php">Quiz</a>
                <?php if (is_logged_in()): ?>
                    <a href="/games/quiz/add_question.php">Dodaj pytanie</a>
                    <a href="/games/quiz/ranking.php">Ranking quizu</a>
					<a href="/leaderboard.php">Rankingi</a>
                    <a href="/profile.php">Mój profil</a>
                    <a href="/user/logout.php">Wyloguj</a>
                <?php else: ?>
                    <a href="/user/login.php">Zaloguj</a>
                    <a href="/user/register.php">Rejestracja</a>
                    <a href="/auth/discord_login.php">Discord</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pasek statystyk widoczny wszędzie -->
        <div class="home-stats-bar">
            <div class="stat-pill">
                <span class="stat-label">Online teraz</span>
                <span class="stat-value"><?php echo $stats_online_now; ?></span>
            </div>
            <div class="stat-pill">
                <span class="stat-label">Zalogowani</span>
                <span class="stat-value"><?php echo $stats_logged_in; ?></span>
            </div>
            <div class="stat-pill">
                <span class="stat-label">Dziś</span>
                <span class="stat-value"><?php echo $stats_visits_today; ?></span>
            </div>
            <div class="stat-pill">
                <span class="stat-label">Ten miesiąc</span>
                <span class="stat-value"><?php echo $stats_visits_month; ?></span>
            </div>
            <div class="stat-pill stat-pill-total">
                <span class="stat-label">Łącznie</span>
                <span class="stat-value"><?php echo $stats_visits_total; ?></span>
            </div>
        </div>
