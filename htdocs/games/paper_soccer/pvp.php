<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/auth.php';
?>

<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Paper Soccer – PvP</title>

<style>
    body {
        background: #222;
        color: #eee;
        font-family: Arial, sans-serif;
        text-align: center;
        padding: 20px;
    }

    h1 {
        font-size: 32px;
        margin-bottom: 20px;
    }

    .container {
        max-width: 700px;
        margin: 0 auto;
    }

    .card {
        background: #333;
        border: 2px solid #444;
        border-radius: 10px;
        padding: 20px;
        margin: 20px 0;
    }

    input[type=text] {
        padding: 10px;
        width: 80%;
        font-size: 20px;
        border-radius: 6px;
        border: 1px solid #666;
        background: #222;
        color: #fff;
        margin-top: 10px;
    }

    button, a.button {
        background: #4CAF50;
        border: none;
        padding: 12px 22px;
        color: white;
        font-size: 18px;
        border-radius: 6px;
        cursor: pointer;
        display: inline-block;
        text-decoration: none;
        margin-top: 10px;
    }

    button:hover, a.button:hover {
        background: #45a049;
    }
</style>

</head>
<body>

<h1>👥 Tryb PvP</h1>

<div class="container">

    <!-- ⭐ OPCJA 1: NOWA GRA -->
    <div class="card">
        <h2>🎮 Stwórz nową grę</h2>
        <p>Otrzymasz kod, który możesz przesłać przeciwnikowi.</p>

        <a href="create_game.php?mode=pvp" class="button">Stwórz nową grę</a>
    </div>


    <!-- ⭐ OPCJA 2: DOŁĄCZ DO GRY -->
    <div class="card">
        <h2>➡️ Dołącz do istniejącej gry</h2>
        <p>Wpisz kod otrzymany od przeciwnika:</p>

        <form method="GET" action="join_game.php">
            <input type="text" name="code" maxlength="6" placeholder="Np. ABC123" required>
            <br>
            <button type="submit">Dołącz</button>
        </form>
    </div>

</div>

</body>
</html>
