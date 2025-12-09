<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Wpisz login i hasło.";
    } elseif ($password !== $password2) {
        $error = "Hasła muszą być takie same.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $error = "Taki login już istnieje.";
        } else {
            mysqli_stmt_close($stmt);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, "ss", $username, $hash);
            if (mysqli_stmt_execute($stmt)) {
                header("Location: login.php");
                exit;
            } else {
                $error = "Błąd podczas rejestracji.";
            }
        }
        mysqli_stmt_close($stmt);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <h1>Rejestracja</h1>
    <?php if ($error): ?>
        <p style="color:#fca5a5;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form method="post" class="form-vertical">
        <label>Login:
            <input type="text" name="username" required>
        </label>
        <label>Hasło:
            <input type="password" name="password" required>
        </label>
        <label>Powtórz hasło:
            <input type="password" name="password2" required>
        </label>
        <button type="submit" class="btn-primary">Utwórz konto</button>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
