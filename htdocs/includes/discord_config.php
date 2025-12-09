<?php
// Adres relaya na CBA – USTAW SWOJĄ DOMENĘ
define('DISCORD_RELAY_URL', 'http://pracaniezajac.cba.pl/discord_relay/relay.php');

// Ten sam tajny token co w relay_config.php na CBA
define('DISCORD_RELAY_SECRET', 'h4slo_Discord_2025_XyZ');

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
