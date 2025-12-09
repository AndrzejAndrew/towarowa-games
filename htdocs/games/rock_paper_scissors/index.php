<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function current_user_id() {
    return $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
}
function current_username() {
    if (!empty($_SESSION['user']['username'])) return $_SESSION['user']['username'];
    if (!empty($_SESSION['username'])) return $_SESSION['username'];
    if (!empty($_SESSION['guest_name'])) return $_SESSION['guest_name'];
    return 'Gosc';
}
?>

<div class="container" style="max-width:900px;margin:2rem auto;">
    <h1>Papier • Kamień • Nożyce</h1>
    <p>Wybierz tryb gry:</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
        <div class="card" style="padding:16px;border:1px solid #333;border-radius:12px;">
            <h2>Gra z botem</h2>
            <p>Zagraj przeciwko prostemu botowi. Wybierz liczbę rund.</p>
            <form method="POST" action="play_bot.php">
                <label>Liczba rund: 
                    <select name="rounds">
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                    </select>
                </label>
                <button type="submit">Start z botem</button>
            </form>
        </div>
        <div class="card" style="padding:16px;border:1px solid #333;border-radius:12px;">
            <h2>Gra online</h2>
            <p>Utwórz pokój i zaproś przeciwnika kodem – lub dołącz do istniejącej gry.</p>
            <form method="POST" action="create_game.php" style="margin-bottom:12px;">
                <label>Liczba rund: 
                    <select name="rounds">
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                    </select>
                </label>
                <button type="submit">Utwórz grę</button>
            </form>
            <form method="GET" action="join.php">
                <label>Kod pokoju: <input type="text" name="code" maxlength="10" required></label>
                <button type="submit">Dołącz</button>
            </form>
        </div>
        <div class="card" style="padding:16px;border:1px solid #333;border-radius:12px;">
            <h2>Ranking</h2>
            <p>Zobacz, kto jest najlepszy w PKN.</p>
            <a href="ranking.php">Przejdź do rankingu</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
