<?php
// /auth/discord_callback.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Adres relaya OAuth na CBA (HTTP, nie HTTPS!)
define('DISCORD_OAUTH_RELAY_URL', 'http://pracaniezajac.cba.pl/discord_oauth/discord_oauth_relay.php');

// Ten sam sekret, co w $DISCORD_OAUTH_RELAY_SECRET na CBA
define('DISCORD_OAUTH_RELAY_SECRET', ':gl.O*HWgd`k\|EzG%YSb;B4nHNIHO|h');

// auth.php powinien już robić session_start(), ale na wszelki wypadek:
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Obsługa błędu z Discorda
if (isset($_GET['error'])) {
    // np. user kliknął "Cancel"
    // Możesz tu dać ładną stronę zamiast echo
    echo "Logowanie przez Discord zostało przerwane. (<a href=\"/index.php\">Wróć</a>)";
    exit;
}

// Musimy dostać ?code=...
if (empty($_GET['code'])) {
    echo "Brak kodu autoryzacji Discord. (<a href=\"/index.php\">Wróć</a>)";
    exit;
}

$code = $_GET['code'];

// Sprawdzenie state (ochrona przed CSRF)
if (!empty($_SESSION['discord_oauth_state'])) {
    $state_from_session = $_SESSION['discord_oauth_state'];
    $state_from_request = $_GET['state'] ?? '';

    if (!$state_from_request || !hash_equals($state_from_session, $state_from_request)) {
        echo "Nieprawidłowy state. Spróbuj ponownie. (<a href=\"/index.php\">Wróć</a>)";
        exit;
    }
    // możemy wyczyścić state
    unset($_SESSION['discord_oauth_state']);
}

// ---------------------------------------------
// 1. Wywołanie relaya na CBA z naszym code
// ---------------------------------------------
$postData = [
    'token' => DISCORD_OAUTH_RELAY_SECRET,
    'code'  => $code,
];

$ch = curl_init(DISCORD_OAUTH_RELAY_URL);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);

curl_close($ch);

if ($http_code < 200 || $http_code >= 300 || !$response) {
    echo "Błąd połączenia z serwerem logowania (CBA).<br>";
    echo "Kod HTTP: $http_code<br>";
    echo "Błąd cURL: $curl_err<br>";
    exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data['ok'])) {
    echo "Błąd podczas logowania przez Discord.<br>";
    echo "Szczegóły: <pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

// ---------------------------------------------
// 2. Mamy dane użytkownika z Discorda
// ---------------------------------------------
$user = $data['user'] ?? null;
if (!$user || empty($user['id'])) {
    echo "Nie udało się pobrać danych użytkownika Discord.";
    exit;
}

$discord_id       = $user['id'];  // stabilny identyfikator
$discord_username = $user['global_name'] ?? $user['username'] ?? ('discord_' . $discord_id);
$discord_avatar   = null;

// Budujemy URL avatara (jeśli jest)
if (!empty($user['avatar'])) {
    $discord_avatar = "https://cdn.discordapp.com/avatars/{$discord_id}/{$user['avatar']}.png";
}

// ---------------------------------------------
// 3. Logowanie / rejestracja w naszej bazie
// ---------------------------------------------

// 3a. Szukamy użytkownika z takim discord_id
$stmt = mysqli_prepare($conn, "SELECT id, username FROM users WHERE discord_id = ?");
mysqli_stmt_bind_param($stmt, "s", $discord_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $found_id, $found_username);
$has_user = mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

if ($has_user) {
    // Istnieje użytkownik powiązany z tym Discordem – logujemy go
    $_SESSION['user_id']  = (int)$found_id;
    $_SESSION['username'] = $found_username;

    // Możemy zaktualizować discord_username/avatar (opcjonalnie)
    $stmt = mysqli_prepare($conn,
        "UPDATE users 
         SET discord_username = ?, discord_avatar = ? 
         WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $discord_username, $discord_avatar, $found_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

} else {
    // 3b. Nie ma użytkownika z tym discord_id – tworzymy nowego

    // Upewniamy się, że username jest unikalny
    $base_username = $discord_username;
    $username      = $base_username;
    $suffix        = 1;

    while (true) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$exists) {
            break;
        }
        $suffix++;
        $username = $base_username . '_' . $suffix;
    }

    // Generujemy losowe hasło (i tak nie będzie używane, ale kolumna może być NOT NULL)
    $randomPassword = bin2hex(random_bytes(16));
    $passwordHash   = password_hash($randomPassword, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO users (username, password, discord_id, discord_username, discord_avatar)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "sssss",
        $username,
        $passwordHash,
        $discord_id,
        $discord_username,
        $discord_avatar
    );
    mysqli_stmt_execute($stmt);
    $new_user_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $_SESSION['user_id']  = (int)$new_user_id;
    $_SESSION['username'] = $username;
}

// ---------------------------------------------
// 4. Sukces – przekierowanie na stronę główną
// ---------------------------------------------
header("Location: /index.php");
exit;
