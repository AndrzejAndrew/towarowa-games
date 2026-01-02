<?php
require_once __DIR__ . '/../config.php';

// Połączenie z bazą danych przy użyciu danych z pliku konfiguracyjnego
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    // W środowisku produkcyjnym warto logować błędy do pliku, zamiast wyświetlać je użytkownikowi
    error_log("Database connection error: " . mysqli_connect_error());
    // Wyświetlenie generycznej wiadomości o błędzie
    die("Wystąpił błąd serwera. Prosimy spróbować później.");
}

mysqli_set_charset($conn, "utf8mb4");

// Ustawienie strefy czasowej sesji MySQL na UTC dla spójności danych
@mysqli_query($conn, "SET time_zone = '+00:00'");
?>