<?php
// KONFIGURACJA BAZY DANYCH – UZUPEŁNIJ DANYMI Z InfinityFree
// host, nazwa bazy, użytkownik i hasło znajdziesz w panelu MySQL na koncie hostingowym.

$DB_HOST = "sqlXXX.epizy.com";      // <-- zmień
$DB_NAME = "if0_40535478_games";    // <-- zmień (lub własna nazwa)
$DB_USER = "if0_40535478";          // <-- zmień
$DB_PASS = "TUTAJ_HASLO";           // <-- zmień

$charset = "utf8mb4";
$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // Jeśli baza nie jest skonfigurowana – nie zabijaj strony głównej.
    // Po prostu zapisz błąd do loga i idź dalej.
    error_log("DB connection failed: " . $e->getMessage());
    $pdo = null;
}
