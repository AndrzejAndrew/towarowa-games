<?php
// Ustawienie strefy czasu dla PHP (formatowanie dat, logi)
// W większości skryptów i tak ładuje się includes/auth.php, ale db.php
// bywa używane samodzielnie, więc ustawiamy defensywnie tutaj.
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Europe/Warsaw');
}

// Połączenie z bazą danych - uzupełnij danymi z InfinityFree
$host = "sql112.infinityfree.com";
$user = "if0_40535478";
$pass = "HKndHI2VOBuud30";
$db   = "if0_40535478_towarowa";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Database connection error: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// MySQL: ustawiamy strefę czasu sesji na UTC, żeby NOW()/CURRENT_TIMESTAMP
// zawsze dawały stabilny zapis czasu niezależnie od hostingu.
// (Wyświetlanie dla użytkownika robimy w Europe/Warsaw.)
@mysqli_query($conn, "SET time_zone = '+00:00'");
?>
