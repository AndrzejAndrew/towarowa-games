<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Pozwalamy na POST (formularz) i GET (link do dołączenia)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    header("Location: index.php");
    exit;
}

// Prosty logger (pomaga przy HTTP 500 na hostingu)
$logFile = __DIR__ . '/quiz_join_debug.log';
function qjoin_log($msg) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s ') . $msg . "\n", FILE_APPEND);
}

$code = strtoupper(trim((string)($_REQUEST['code'] ?? '')));
if ($code === '') {
    http_response_code(400);
    die("Brak kodu gry.");
}

// 1) Znajdź grę po kodzie
$stmt = mysqli_prepare($conn, "SELECT id, status FROM games WHERE code = ? LIMIT 1");
if (!$stmt) {
    qjoin_log("DB prepare failed (games by code): " . mysqli_error($conn));
    http_response_code(500);
    die("Błąd bazy (games).");
}
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) {
    http_response_code(404);
    die("Nie znaleziono gry o podanym kodzie.");
}
if (($game['status'] ?? '') !== 'lobby') {
    http_response_code(409);
    die("Ta gra już się rozpoczęła lub zakończyła.");
}

$game_id = (int)$game['id'];

// 2) Ustal dane gracza
$is_guest = is_logged_in() ? 0 : 1;
$user_id  = is_logged_in() ? (int)($_SESSION['user_id'] ?? 0) : null;

if (is_logged_in()) {
    $nickname = (string)($_SESSION['username'] ?? 'Użytkownik');
} else {
    // auth.php powinien zawsze ustawić guest_name
    $nickname = (string)($_SESSION['guest_name'] ?? 'Gość');
    // Opcjonalnie pozwól podać nick (np. gdy kiedyś dodasz formularz)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nickname'])) {
        $n = trim((string)$_POST['nickname']);
        if ($n !== '') {
            $n = preg_replace('/\s+/', ' ', $n);
            $n = mb_substr($n, 0, 50);
            $nickname = $n;
            $_SESSION['guest_name'] = $nickname;
        }
    }
}

// 3) Sprawdź czy już jest w tej grze
if ($user_id !== null) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM players WHERE game_id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        qjoin_log("DB prepare failed (players by user): " . mysqli_error($conn));
        http_response_code(500);
        die("Błąd bazy (players).");
    }
    mysqli_stmt_bind_param($stmt, "ii", $game_id, $user_id);
} else {
    $stmt = mysqli_prepare($conn, "SELECT id FROM players WHERE game_id = ? AND is_guest = 1 AND nickname = ? LIMIT 1");
    if (!$stmt) {
        qjoin_log("DB prepare failed (players by guest nick): " . mysqli_error($conn));
        http_response_code(500);
        die("Błąd bazy (players).");
    }
    mysqli_stmt_bind_param($stmt, "is", $game_id, $nickname);
}
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$exists = (mysqli_stmt_num_rows($stmt) > 0);
mysqli_stmt_close($stmt);

// 4) Dodaj gracza jeśli nie istnieje
if (!$exists) {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO players (game_id, user_id, nickname, is_guest) VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) {
        qjoin_log("DB prepare failed (players insert): " . mysqli_error($conn));
        http_response_code(500);
        die("Błąd bazy (insert).");
    }

    if ($user_id === null) {
        $null = null;
        mysqli_stmt_bind_param($stmt, "issi", $game_id, $null, $nickname, $is_guest);
    } else {
        mysqli_stmt_bind_param($stmt, "issi", $game_id, $user_id, $nickname, $is_guest);
    }

    if (!mysqli_stmt_execute($stmt)) {
        qjoin_log("DB execute failed (players insert): " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        http_response_code(500);
        die("Nie udało się dołączyć do gry.");
    }
    mysqli_stmt_close($stmt);
}

// 5) Przejdź do lobby
header("Location: lobby.php?game=" . $game_id);
exit;
