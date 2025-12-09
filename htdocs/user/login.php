<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Podaj login i hasło.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, password FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $username;
            // po zalogowaniu gość nie jest potrzebny
            unset($_SESSION['guest_name'], $_SESSION['guest_id']);
            header("Location: /index.php");
            exit;
        } else {
            $error = "Nieprawidłowy login lub hasło.";
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <h1>Logowanie</h1>
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
        <button type="submit" class="btn-primary">Zaloguj</button>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
