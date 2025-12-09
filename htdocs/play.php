<?php
require_once "includes/db.php";

$game_id = $_GET['game_id'];
$nick = $_GET['nick'] ?? "gracz";

$q = mysqli_query($conn, "SELECT * FROM questions ORDER BY id LIMIT 1");
$question = mysqli_fetch_assoc($q);
?>
<!DOCTYPE html>
<html>
<body>

<h2><?php echo $question['question']; ?></h2>

<form action="next.php" method="POST">
    <input type="hidden" name="q_id" value="<?php echo $question['id']; ?>">
    <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
    <input type="hidden" name="player" value="<?php echo $nick; ?>">

    <label><input type="radio" name="answer" value="A" required> <?php echo $question['a']; ?></label><br>
    <label><input type="radio" name="answer" value="B"> <?php echo $question['b']; ?></label><br>
    <label><input type="radio" name="answer" value="C"> <?php echo $question['c']; ?></label><br>
    <label><input type="radio" name="answer" value="D"> <?php echo $question['d']; ?></label><br><br>

    <button type="submit">Zatwierdź</button>
</form>

</body>
</html>
