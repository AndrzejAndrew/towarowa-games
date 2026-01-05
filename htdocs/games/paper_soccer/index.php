<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/auth.php';
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Paper Soccer</title>

    <style>
        body {
            background: #222;
            color: #eee;
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 20px;
        }

        h1 {
            font-size: 34px;
            margin-bottom: 20px;
            color: #fff;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        /* kafelki */
        .menu-card {
            background: #333;
            border: 2px solid #444;
            border-radius: 10px;
            padding: 20px;
            margin: 15px 0;
            color: #fff;
            text-decoration: none;
            display: block;
            transition: 0.2s;
        }

        .menu-card:hover {
            background: #444;
            border-color: #666;
            transform: scale(1.02);
        }

        .menu-card h2 {
            margin-bottom: 8px;
            font-size: 22px;
        }

        /* zasady gry */
        .rules-box {
            background: #2d2d2d;
            border: 2px solid #444;
            padding: 20px;
            margin-top: 30px;
            border-radius: 10px;
            text-align: left;
            color: #ddd;
        }

        .rules-box h3 {
            margin-bottom: 10px;
            color: #fff;
        }

        .rules-box ul {
            margin-left: 20px;
            line-height: 1.55;
        }
    </style>

</head>
<body>

<h1>⚽ Papierowa Piłka Nożna</h1>

<div class="container">
    <!-- Zasady gry -->
    <div class="rules-box">
        <h3>📘 Zasady gry Paper Soccer</h3>
        <ul>
            <li>Każdy ruch przesuwa piłkę o jedną kratkę.</li>
            <li>Po odbiciu od linii, ściany albo rogu — wykonujesz dodatkowy ruch.</li>
            <li>Nie można przechodzić przez istniejące linie.</li>
            <li>Wygrywa gracz, który trafi piłką w bramkę przeciwnika.</li>
            <li>Gra toczy się na klasycznej arkuszowej planszy.</li>
        </ul>
    </div>
    
    <!-- Gra z botem: łatwy -->
    <a href="create_game.php?mode=bot&bot_difficulty=1" class="menu-card">
        <h2>🤖 Gra z botem — Łatwy</h2>
        <p>Dla początkujących graczy.</p>
    </a>

    <!-- Gra z botem: średni -->
    <a href="create_game.php?mode=bot&bot_difficulty=2" class="menu-card">
        <h2>🤖 Gra z botem — Średni</h2>
        <p>Idealny poziom do treningu.</p>
    </a>

    <!-- Gra z botem: trudny -->
    <a href="create_game.php?mode=bot&bot_difficulty=3" class="menu-card">
        <h2>🤖 Gra z botem — Trudny</h2>
        <p>Bot gra najlepiej jak potrafi.</p>
    </a>

    <!-- Gra z botem: ekspert -->
    <a href="create_game.php?mode=bot&bot_difficulty=4" class="menu-card">
        <h2>🤖 Gra z botem — Ekspert</h2>
        <p>Prawdziwe wyzwanie.</p>
    </a>

    <a href="pvp.php" class="menu-card">
    <h2>👥 Gra PvP — Zagraj z innym graczem</h2>
    <p>Wprowadź kod gry lub czekaj na przeciwnika.</p>
</a>

    <!-- Ranking -->
    <a href="ranking.php" class="menu-card">
        <h2>🏆 Ranking</h2>
        <p>Zobacz najlepszych graczy.</p>
    </a>



</div>

</body>
</html>
