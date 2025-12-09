<?php
// games/battleship/index.php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="style.css">

<h1>Gra w statki</h1>

<div class="battleship-menu">

    <section>
        <h2>1. Gra przeciwko BOTowi</h2>
        <form action="create_bot.php" method="post">
    <label>Poziom trudności:</label>
    <select name="difficulty" required>
        <option value="easy">Łatwy</option>
        <option value="normal">Średni</option>
        <option value="hard">Trudny</option>
    </select>

    <label style="display:block; margin-top:7px;">
        <input type="checkbox" name="manual_setup" value="1">
        Rozstaw statki ręcznie
    </label>

    <button type="submit" style="margin-top:10px;">Zagraj z BOT-em</button>
</form>

    </section>

    <section>
        <h2>2. Gra gracz vs gracz (online)</h2>

        <div class="pvp-block">
            <h3>Utwórz nową grę</h3>
            <form action="create_pvp.php" method="post">

    <label style="display:block; margin-bottom:5px;">
        <input type="checkbox" name="manual_setup" value="1">
        Rozstaw statki ręcznie
    </label>

    <button type="submit">Stwórz grę i odbierz kod</button>
</form>

        </div>

        <div class="pvp-block">
            <h3>Dołącz do istniejącej gry</h3>
            <form action="join_pvp.php" method="post">
                <label for="join_code">Kod gry:</label>
                <input type="text" name="join_code" id="join_code" maxlength="12" required>
                <button type="submit">Dołącz</button>
            </form>
        </div>
    </section>

</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
