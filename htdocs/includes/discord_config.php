<?php
/**
 * UWAGA:
 * Ten plik NIE zawiera sekretów.
 * Sekrety są wstrzykiwane przez GitHub Actions
 * do secrets_runtime.php
 */

if (!defined('DISCORD_RELAY_SECRET')) {
    http_response_code(500);
    die('DISCORD_RELAY_SECRET missing (deploy error)');
}

if (!defined('DISCORD_OAUTH_RELAY_SECRET')) {
    http_response_code(500);
    die('DISCORD_OAUTH_RELAY_SECRET missing (deploy error)');
}


// Mapowanie typów – to są stringi, które trafiają do relay.php
$DISCORD = [
    "bug"      => "bug",
    "quiz"     => "quiz",
    "statki"   => "statki",
    "winner"   => "winner",
    "security" => "security",
    "log_gier" => "log_gier",
    "log_sys"  => "log_sys",
    "pilka"    => "pilka"
];

// Ładne nazwy i kolory – opcjonalne
$DISCORD_META = [
    "bug"      => ["username" => "Bug Reporter",   "color" => 0xFF0000],
    "quiz"     => ["username" => "Quiz Lobby",     "color" => 0x3498DB],
    "statki"   => ["username" => "Statki",         "color" => 0x1ABC9C],
    "winner"   => ["username" => "Wyniki",         "color" => 0x2ECC71],
    "security" => ["username" => "Bezpieczeństwo", "color" => 0xE67E22],
    "log_gier" => ["username" => "Logi Gier",      "color" => 0x9B59B6],
    "log_sys"  => ["username" => "System",         "color" => 0x95A5A6],
    "pilka"    => ["username" => "Piłka",          "color" => 0xF1C40F],
];
