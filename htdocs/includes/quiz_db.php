<?php
$host='localhost';$user='user';$pass='pass';$db='quiz_db';
$conn=new mysqli($host,$user,$pass,$db);
if($conn->connect_error){die('DB error');}
mysqli_set_charset($conn,'utf8mb4');
?>