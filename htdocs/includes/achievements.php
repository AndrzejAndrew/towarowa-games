<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stats.php'; // do XP
require_once __DIR__ . '/discord.php'; // jeśli masz webhooki

/**
 * Przyznaje odznakę użytkownikowi (jeśli jej jeszcze nie posiada).
 * 
 * @param int $user_id - id użytkownika
 * @param string $code - kod odznaki (np. 'quiz_master')
 * @return bool - true jeśli przyznano, false jeśli już miał
 */
function award_achievement(int $user_id, string $code): bool
{
    global $conn;

    if ($user_id <= 0) return false;

    // Czy już zdobył?
    $check = mysqli_query(
        $conn,
        "SELECT 1 FROM user_achievements 
         WHERE user_id = $user_id AND achievement_code = '$code'
         LIMIT 1"
    );

    if (mysqli_fetch_assoc($check)) {
        return false; // już ma
    }

    // Pobieramy info o odznace
    $res = mysqli_query(
        $conn,
        "SELECT name, description, xp_reward 
         FROM achievements
         WHERE code = '$code'
         LIMIT 1"
    );

    $ach = mysqli_fetch_assoc($res);
    if (!$ach) {
        return false; // brak takiej odznaki
    }

    // Zapisujemy zdobycie
    mysqli_query(
        $conn,
        "INSERT INTO user_achievements (user_id, achievement_code)
         VALUES ($user_id, '$code')"
    );

    // Opcjonalna nagroda XP
    if ((int)$ach['xp_reward'] > 0) {
        stats_add_xp($user_id, (int)$ach['xp_reward'], "achievement:$code");
    }

    // Powiadomienie Discord (opcjonalnie)
    if (function_exists("discord_send")) {
        $msg = "🎖 **Nowa odznaka!**\n"
             . "Użytkownik: <@$user_id>\n"
             . "Odznaka: **{$ach['name']}**\n"
             . "{$ach['description']}";
        discord_send('system', $msg);
    }

    return true;
}
