<?php
// Spójna strefa czasowa w całym portalu (wyświetlanie / logi)
// Dane w bazie najlepiej trzymać w UTC, a wyświetlać w Europe/Warsaw.
date_default_timezone_set('Europe/Warsaw');

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

// Wymuś UTC na poziomie sesji MySQL (stabilne NOW()/CURRENT_TIMESTAMP)
// Nie wymaga uprawnień GLOBAL.
@mysqli_query($conn, "SET time_zone = '+00:00'");
?>
