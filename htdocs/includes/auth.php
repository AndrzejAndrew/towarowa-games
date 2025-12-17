<?php
// ------------------------------------------------------
// STREFA CZASOWA (UI/LOGI)
// ------------------------------------------------------
// Portal działa w Polsce – ustawiamy domyślną strefę czasu dla PHP,
// aby logi/komunikaty i formatowanie dat było spójne.
// (Baza może przechowywać DATETIME w UTC; konwersję do PL robimy w widoku.)
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Europe/Warsaw');
}

// ------------------------------------------------------
// BOOTSTRAP – sekrety + konfiguracja Discord
// ------------------------------------------------------
$secretsFile = __DIR__ . '/secrets_runtime.php';
if (!file_exists($secretsFile)) {
    http_response_code(500);
    die('Missing secrets_runtime.php (deploy error)');
}
require_once $secretsFile;

require_once __DIR__ . '/discord_config.php';

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
