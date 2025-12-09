<?php
require_once __DIR__ . '/includes/discord.php';
require_once __DIR__ . '/includes/discord_config.php';

$type = $_GET['type'] ?? 'log_sys';

$msg = "✅ Test przez relay CBA\n"
     . "Typ: $type\n"
     . "Czas: " . date("Y-m-d H:i:s");

$result = discord_send(
    $type,
    $msg,
    $DISCORD_META[$type]['username'] ?? 'Test z InfinityFree',
    $DISCORD_META[$type]['color'] ?? 0x5865F2
);

echo "Wysłano. Odpowiedź:\n";
var_dump($result);
