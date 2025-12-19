<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stats.php'; // XP helper
if (file_exists(__DIR__ . '/discord.php')) { require_once __DIR__ . '/discord.php'; } // opcjonalnie

/**
 * Przyznaje odznakę użytkownikowi (jeśli jej jeszcze nie posiada).
 *
 * @param int $user_id
 * @param string $code
 * @param bool $silent Jeśli true, nie wysyłaj powiadomień (np. przy backfillu / naprawie danych)
 * @return bool true jeśli przyznano, false jeśli już miał lub brak definicji.
 */
function award_achievement(int $user_id, string $code, bool $silent = false): bool
{
    global $conn;

    if ($user_id <= 0 || $code === '') {
        return false;
    }

    $codeEsc = mysqli_real_escape_string($conn, $code);

    // Czy już zdobył?
    $check = mysqli_query(
        $conn,
        "SELECT 1 FROM user_achievements WHERE user_id = $user_id AND achievement_code = '$codeEsc' LIMIT 1"
    );
    if ($check && mysqli_fetch_assoc($check)) {
        return false;
    }

    // Pobierz definicję
    $res = mysqli_query(
        $conn,
        "SELECT name, description, xp_reward FROM achievements WHERE code = '$codeEsc' LIMIT 1"
    );
    $ach = $res ? mysqli_fetch_assoc($res) : null;
    if (!$ach) {
        return false;
    }

    // Zapisz zdobycie (idempotentnie)
    $ins = mysqli_query(
        $conn,
        "INSERT INTO user_achievements (user_id, achievement_code) VALUES ($user_id, '$codeEsc')"
    );

    if (!$ins) {
        // jeśli insert się nie udał (np. constraint) – traktuj jak brak przyznania
        return false;
    }

    // Opcjonalna nagroda XP
    $xpReward = (int)($ach['xp_reward'] ?? 0);
    if ($xpReward > 0 && function_exists('stats_add_xp')) {
        stats_add_xp($user_id, $xpReward, "achievement:$code");
    }

    // Discord (opcjonalnie)
    if (!$silent && function_exists('discord_send')) {
        $name = $ach['name'] ?? $code;
        $desc = $ach['description'] ?? '';
        $msg = "🎖 **Nowa odznaka!**\n" .
               "Użytkownik ID: **$user_id**\n" .
               "Odznaka: **$name**\n" .
               $desc;
        discord_send('system', $msg);
    }

    return true;
}
