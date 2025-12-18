<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/discord.php';

/**
 * Przyznaje odznakę użytkownikowi (jeśli jej jeszcze nie posiada).
 * Zapis do user_achievements + opcjonalny XP z achievements.xp_reward.
 *
 * @param int $user_id
 * @param string $code
 * @return bool true jeśli przyznano, false jeśli już miał / brak odznaki
 */
function award_achievement(int $user_id, string $code): bool
{
    global $conn;

    if ($user_id <= 0 || $code === '') {
        return false;
    }

    // stats_award_achievement robi sprawdzenie duplikatu i ewentualne XP.
    $awarded = stats_award_achievement($user_id, $code);
    if (!$awarded) {
        return false;
    }

    // Powiadomienie Discord (opcjonalnie)
    // (używamy nazwy/opisu z DB, żeby wiadomość była czytelna)
    $stmt = $conn->prepare("SELECT name, description FROM achievements WHERE code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && function_exists('discord_send')) {
        $name = $row['name'] ?? $code;
        $desc = $row['description'] ?? '';

        $msg = "🎖 **Nowa odznaka!**\n"
             . "UserID: {$user_id}\n"
             . "Odznaka: **{$name}**\n"
             . $desc;
        discord_send('system', $msg);
    }

    return true;
}
