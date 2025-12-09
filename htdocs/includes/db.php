<?php
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
?>
