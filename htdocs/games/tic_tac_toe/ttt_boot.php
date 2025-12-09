<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!is_logged_in()) {
    if (!isset($_SESSION['guest_id'])) {
        try { $_SESSION['guest_id'] = random_int(100000, 999999); }
        catch (Exception $e) { $_SESSION['guest_id'] = mt_rand(100000, 999999); }
    }
    if (!isset($_SESSION['guest_name'])) {
        $_SESSION['guest_name'] = 'Guest_' . $_SESSION['guest_id'];
    }
}

function ttt_current_player_id(): int {
    if (is_logged_in()) return (int)$_SESSION['user_id'];
    return (int)$_SESSION['guest_id'];
}

function ttt_is_logged_user(int $user_id, mysqli $conn): bool {
    $user_id = (int)$user_id;
    $res = mysqli_query($conn, "SELECT id FROM users WHERE id = {$user_id} LIMIT 1");
    return ($res && mysqli_fetch_assoc($res)) ? true : false;
}

function ttt_display_name(int $user_id, mysqli $conn): string {
    $user_id = (int)$user_id;

    // 1) jeśli to zalogowany użytkownik z tabeli users
    $res = mysqli_query($conn, "SELECT username FROM users WHERE id = {$user_id} LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return $row['username'];
    }

    // 2) jeśli to MY jesteśmy tym gościem – użyj ładnej nazwy z sesji (np. Boromir96)
    if (isset($_SESSION['guest_id'], $_SESSION['guest_name'])
        && (int)$_SESSION['guest_id'] === $user_id
        && $_SESSION['guest_name'] !== '') {
        return $_SESSION['guest_name'];
    }

    // 3) rezerwowy wariant – gdyby nie dało się dopasować
    return 'Gość #' . $user_id;
}


function ttt_fetch_game_by_code(string $code, mysqli $conn): ?array {
    $code = mysqli_real_escape_string($conn, $code);
    $res = mysqli_query($conn, "SELECT * FROM ttt_games WHERE code = '{$code}' LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) return $row;
    return null;
}
