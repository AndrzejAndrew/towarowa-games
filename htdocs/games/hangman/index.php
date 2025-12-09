<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/header.php';

// Obsługa dołączania po kodzie gry
$join_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_code'])) {
    $code = trim($_POST['join_code'] ?? '');
    $code = strtoupper($code);

    if ($code === '') {
        $join_error = "Podaj kod gry.";
    } else {
        $code_esc = mysqli_real_escape_string($conn, $code);

        $res = mysqli_query($conn,
            "SELECT id FROM hangman_games
             WHERE code = '$code_esc'"
        );

        if ($res && mysqli_num_rows($res) > 0) {
            $game = mysqli_fetch_assoc($res);
            $game_id = (int)$game['id'];

            // Przekieruj do lobby, tam dodamy gracza do gry
            header("Location: lobby.php?game=" . $game_id);
            exit;
        } else {
            $join_error = "Nie znaleziono gry o podanym kodzie.";
        }
    }
}
?>

<div class="container mt-4">
    <h1>Wisielec</h1>
    <p>Klasyczna gra w odgadywanie hasła – solo, w drużynie lub na bitwy o hasło.</p>

    <div class="row mt-4">
        <!-- Utwórz grę -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    Utwórz nową grę
                </div>
                <div class="card-body">
                    <p>Wymyśl hasło, wybierz tryb gry i zaproś innych za pomocą kodu.</p>
                    <a href="create.php" class="btn btn-primary">Utwórz grę</a>
                </div>
            </div>
        </div>

        <!-- Dołącz do gry -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    Dołącz do istniejącej gry
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label for="join_code" class="form-label">Kod gry</label>
                            <input type="text" name="join_code" id="join_code"
                                   class="form-control"
                                   maxlength="10"
                                   placeholder="NP. ABC123"
                                   required>
                        </div>
                        <?php if ($join_error): ?>
                            <div class="alert alert-danger">
                                <?php echo htmlspecialchars($join_error); ?>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success">Dołącz</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
